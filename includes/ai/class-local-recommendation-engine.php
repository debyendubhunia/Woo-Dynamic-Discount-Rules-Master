<?php
namespace WooDynamicDiscountRulesMaster\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

class LocalRecommendationEngine implements AIServiceInterface {

    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function get_suggestions(): array {
        $suggestions = [];

        // 1. Slow-moving products (0 sales in last 60 days)
        $slow_products = $this->get_slow_moving_products( 60, 3 );
        foreach ( $slow_products as $p ) {
            $suggestions[] = [
                'type'           => 'slow_moving',
                'title'          => 'Promote Slow-Moving Item: ' . $p['name'],
                'description'    => 'This product has had no sales in the last 60 days. Recommend adding a 15% discount to clear inventory.',
                'score'          => 85,
                'suggested_rule' => [
                    'name'          => 'Clearance - 15% off ' . $p['name'],
                    'discount_type' => 'product_percentage',
                    'discount_data' => [ 'percentage' => 15 ],
                    'filter_data'   => [ 'included_products' => [ $p['id'] ] ]
                ]
            ];
        }

        // 2. Products with declining sales (>30% drop month-over-month)
        $declining = $this->get_declining_sales_products( 30, 2 );
        foreach ( $declining as $d ) {
            $suggestions[] = [
                'type'           => 'declining_sales',
                'title'          => 'Boost Declining Sales: ' . $d['name'],
                'description'    => 'Sales of this item dropped by ' . round($d['drop_percentage']) . '% compared to the previous month. Offer a fixed discount to recover momentum.',
                'score'          => 78,
                'suggested_rule' => [
                    'name'          => 'Recover Sales - $5 off ' . $d['name'],
                    'discount_type' => 'product_fixed',
                    'discount_data' => [ 'amount' => 5 ],
                    'filter_data'   => [ 'included_products' => [ $d['id'] ] ]
                ]
            ];
        }

        // 3. Bundle Opportunities (Apriori bought-together checking)
        $bundles = $this->get_frequently_bought_together( 2 );
        foreach ( $bundles as $b ) {
            $suggestions[] = [
                'type'           => 'bundle_offer',
                'title'          => 'Create Bundle Offer: ' . $b['prod1_name'] . ' + ' . $b['prod2_name'],
                'description'    => 'These products were purchased together in ' . $b['co_occurrences'] . ' orders. Recommend creating a bundle offer with a 10% discount.',
                'score'          => 92,
                'suggested_rule' => [
                    'name'          => 'Bundle - ' . $b['prod1_name'] . ' + ' . $b['prod2_name'],
                    'discount_type' => 'product_bundle',
                    'discount_data' => [
                        'bundle_products'     => [ $b['prod1_id'], $b['prod2_id'] ],
                        'bundle_discount_pct' => 10
                    ]
                ]
            ];
        }

        // 4. Seasonal & Customer Re-engagement campaigns (static default heuristics)
        $suggestions[] = [
            'type'        => 'reengagement',
            'title'       => 'VIP Customer Re-engagement Campaign',
            'description' => 'Create a high-value discount (e.g. 20% off cart) limited to VIP customers who have not placed an order in the last 90 days.',
            'score'       => 70,
            'suggested_rule' => [
                'name'           => 'VIP Re-engagement Discount',
                'discount_type'  => 'cart_percentage',
                'discount_data'  => [ 'percentage' => 20 ],
                'condition_data' => [
                    'operator' => 'AND',
                    'groups' => [
                        [ 'type' => 'is_logged_in', 'operator' => '=', 'value' => true ],
                        [ 'type' => 'total_spent', 'operator' => '>=', 'value' => 500 ]
                    ]
                ]
            ]
        ];

        return $suggestions;
    }

    private function get_slow_moving_products( int $days, int $limit ): array {
        $order_items = $this->wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $posts = $this->wpdb->prefix . 'posts';

        $cutoff = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // Query active products that do not exist in order items since the cutoff date
        $sql = $this->wpdb->prepare(
            "SELECT p.ID as id, p.post_title as name 
             FROM {$posts} p
             WHERE p.post_type = 'product' 
               AND p.post_status = 'publish'
               AND p.ID NOT IN (
                   SELECT DISTINCT im.meta_value 
                   FROM {$order_items} oi
                   INNER JOIN {$order_itemmeta} im ON oi.order_item_id = im.order_item_id
                   INNER JOIN {$posts} orders ON oi.order_id = orders.ID
                   WHERE oi.order_item_type = 'line_item'
                     AND im.meta_key = '_product_id'
                     AND orders.post_date >= %s
               )
             ORDER BY p.ID DESC
             LIMIT %d",
            $cutoff,
            $limit
        );

        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    private function get_declining_sales_products( int $days, int $limit ): array {
        $order_items = $this->wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $posts = $this->wpdb->prefix . 'posts';

        $midpoint = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $start = date( 'Y-m-d H:i:s', strtotime( "-" . ($days * 2) . " days" ) );

        // Query sales volumes per product for the last $days days and the $days days prior
        $sql = $this->wpdb->prepare(
            "SELECT 
                p.ID as id, 
                p.post_title as name,
                SUM(CASE WHEN o.post_date >= %s THEN 1 ELSE 0 END) as recent_sales,
                SUM(CASE WHEN o.post_date >= %s AND o.post_date < %s THEN 1 ELSE 0 END) as old_sales
             FROM {$posts} p
             INNER JOIN {$order_itemmeta} im ON p.ID = CAST(im.meta_value AS UNSIGNED) AND im.meta_key = '_product_id'
             INNER JOIN {$order_items} oi ON im.order_item_id = oi.order_item_id
             INNER JOIN {$posts} o ON oi.order_id = o.ID
             WHERE p.post_type = 'product' 
               AND p.post_status = 'publish'
               AND o.post_type = 'shop_order'
               AND o.post_status IN ('wc-completed', 'wc-processing')
               AND o.post_date >= %s
             GROUP BY p.ID
             HAVING old_sales > 5 AND recent_sales < old_sales * 0.7
             ORDER BY (old_sales - recent_sales) DESC
             LIMIT %d",
            $midpoint, $start, $midpoint, $start, $limit
        );

        $results = $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
        $formatted = [];
        foreach ( $results as $r ) {
            $drop = (($r['old_sales'] - $r['recent_sales']) / $r['old_sales']) * 100;
            $formatted[] = [
                'id'              => (int) $r['id'],
                'name'            => $r['name'],
                'drop_percentage' => $drop
            ];
        }
        return $formatted;
    }

    private function get_frequently_bought_together( int $limit ): array {
        $order_items = $this->wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $posts = $this->wpdb->prefix . 'posts';

        // Self-join order items to find pairs of products ordered together in the same order
        $sql = $this->wpdb->prepare(
            "SELECT 
                p1.meta_value as prod1_id,
                p2.meta_value as prod2_id,
                COUNT(DISTINCT oi1.order_id) as co_occurrences,
                post1.post_title as prod1_name,
                post2.post_title as prod2_name
             FROM {$order_items} oi1
             INNER JOIN {$order_itemmeta} p1 ON oi1.order_item_id = p1.order_item_id AND p1.meta_key = '_product_id'
             INNER JOIN {$order_items} oi2 ON oi1.order_id = oi2.order_id AND oi1.order_item_id < oi2.order_item_id
             INNER JOIN {$order_itemmeta} p2 ON oi2.order_item_id = p2.order_item_id AND p2.meta_key = '_product_id'
             INNER JOIN {$posts} post1 ON p1.meta_value = post1.ID
             INNER JOIN {$posts} post2 ON p2.meta_value = post2.ID
             WHERE oi1.order_item_type = 'line_item'
               AND oi2.order_item_type = 'line_item'
             GROUP BY prod1_id, prod2_id
             HAVING co_occurrences >= 2
             ORDER BY co_occurrences DESC
             LIMIT %d",
            $limit
        );

        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }
}
