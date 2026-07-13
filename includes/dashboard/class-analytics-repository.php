<?php
namespace WooDynamicDiscountRulesMaster\Dashboard;

if ( ! defined( 'ABSPATH' ) ) exit;

class AnalyticsRepository {

    private $wpdb;
    private $rules_table;
    private $apps_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->rules_table = $wpdb->prefix . 'wddrm_rules';
        $this->apps_table  = $wpdb->prefix . 'wddrm_rule_applications';
    }

    /**
     * Get rule status counters.
     */
    public function get_rule_counts(): array {
        $active   = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->rules_table} WHERE status = 1" );
        $inactive = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->rules_table} WHERE status = 0" );
        return [
            'active'   => $active,
            'inactive' => $inactive,
            'total'    => $active + $inactive
        ];
    }

    /**
     * Retrieve core metrics with date range filtering.
     */
    public function get_core_metrics( string $start_date, string $end_date ): array {
        $sql = $this->wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT order_id) as total_orders,
                SUM(discount_amount) as total_discount_given,
                SUM(original_subtotal) as total_subtotal
             FROM {$this->apps_table}
             WHERE applied_at >= %s AND applied_at <= %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        );

        $row = $this->wpdb->get_row( $sql, ARRAY_A );
        
        $total_orders = (int) ($row['total_orders'] ?? 0);
        $total_discount = (float) ($row['total_discount_given'] ?? 0);
        $total_subtotal = (float) ($row['total_subtotal'] ?? 0);
        
        // Compute revenue generated (Subtotal - Discount)
        $revenue_with_discounts = $total_subtotal - $total_discount;

        return [
            'orders_count'    => $total_orders,
            'discount_given'  => $total_discount,
            'revenue_impact'  => $revenue_with_discounts,
            'original_spend'  => $total_subtotal
        ];
    }

    /**
     * Get top performing rules.
     */
    public function get_top_performing_rules( string $start_date, string $end_date, int $limit = 5 ): array {
        $sql = $this->wpdb->prepare(
            "SELECT 
                a.rule_id,
                r.name as rule_name,
                r.discount_type,
                COUNT(DISTINCT a.order_id) as usages,
                SUM(a.discount_amount) as discount_given
             FROM {$this->apps_table} a
             INNER JOIN {$this->rules_table} r ON a.rule_id = r.id
             WHERE a.applied_at >= %s AND a.applied_at <= %s
             GROUP BY a.rule_id
             ORDER BY discount_given DESC
             LIMIT %d",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $limit
        );

        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Get product-level discount analytics.
     */
    public function get_product_analytics( string $start_date, string $end_date, int $limit = 5 ): array {
        $order_items = $this->wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta = $this->wpdb->prefix . 'woocommerce_order_itemmeta';

        // Join applications to WooCommerce order items to retrieve which products were purchased
        $sql = $this->wpdb->prepare(
            "SELECT 
                im.meta_value as product_id,
                oi.order_item_name as product_name,
                COUNT(DISTINCT a.order_id) as orders_count,
                SUM(a.discount_amount) as estimated_discount
             FROM {$this->apps_table} a
             INNER JOIN {$order_items} oi ON a.order_id = oi.order_id
             INNER JOIN {$order_itemmeta} im ON oi.order_item_id = im.order_item_id
             WHERE a.applied_at >= %s AND a.applied_at <= %s
               AND oi.order_item_type = 'line_item'
               AND im.meta_key = '_product_id'
             GROUP BY product_id
             ORDER BY estimated_discount DESC
             LIMIT %d",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $limit
        );

        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Get revenue trend data grouped by day.
     */
    public function get_revenue_trends( string $start_date, string $end_date ): array {
        $sql = $this->wpdb->prepare(
            "SELECT 
                DATE(applied_at) as day_date,
                SUM(discount_amount) as discount,
                SUM(original_subtotal) as subtotal
             FROM {$this->apps_table}
             WHERE applied_at >= %s AND applied_at <= %s
             GROUP BY DATE(applied_at)
             ORDER BY day_date ASC",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        );

        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Retrieve customer usage frequencies.
     */
    public function get_customer_usage( string $start_date, string $end_date, int $limit = 5 ): array {
        $users_table = $this->wpdb->prefix . 'users';
        $sql = $this->wpdb->prepare(
            "SELECT 
                a.user_id,
                COALESCE(u.user_login, 'Guest') as username,
                COALESCE(u.user_email, 'N/A') as email,
                COUNT(DISTINCT a.order_id) as orders_count,
                SUM(a.discount_amount) as discount_received
             FROM {$this->apps_table} a
             LEFT JOIN {$users_table} u ON a.user_id = u.ID
             WHERE a.applied_at >= %s AND a.applied_at <= %s
             GROUP BY a.user_id
             ORDER BY discount_received DESC
             LIMIT %d",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $limit
        );

        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }
}
