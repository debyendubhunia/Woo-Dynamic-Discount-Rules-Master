<?php
namespace WooDynamicDiscountRulesMaster\Segmentation;

if ( ! defined( 'ABSPATH' ) ) exit;

class SegmentationEngine {

    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Resolve customers matching segment criteria.
     * Returns list of matching customer details: ID, user_login, email, order_count, total_spent.
     */
    public function query_customers( array $rules ): array {
        $users_table    = $this->wpdb->prefix . 'users';
        $usermeta_table = $this->wpdb->prefix . 'usermeta';

        // Base query joining users with order metrics
        $query = "SELECT 
                    u.ID, 
                    u.user_login, 
                    u.user_email,
                    COALESCE(CAST(m_count.meta_value AS UNSIGNED), 0) as order_count,
                    COALESCE(CAST(m_spent.meta_value AS DECIMAL(12,2)), 0.00) as total_spent,
                    COALESCE(m_country.meta_value, '') as country
                  FROM {$users_table} u
                  LEFT JOIN {$usermeta_table} m_count ON u.ID = m_count.user_id AND m_count.meta_key = '_order_count'
                  LEFT JOIN {$usermeta_table} m_spent ON u.ID = m_spent.user_id AND m_spent.meta_key = '_money_spent'
                  LEFT JOIN {$usermeta_table} m_country ON u.ID = m_country.user_id AND m_country.meta_key = 'billing_country'
                  WHERE 1=1";

        // Filter: First-time vs Returning vs VIP
        $type = sanitize_key( $rules['segment_type'] ?? 'all' );
        switch ( $type ) {
            case 'first_time':
                $query .= " AND COALESCE(CAST(m_count.meta_value AS UNSIGNED), 0) <= 1";
                break;
            case 'returning':
                $query .= " AND COALESCE(CAST(m_count.meta_value AS UNSIGNED), 0) > 1";
                break;
            case 'vip':
            case 'high_spender':
                $min_spend = (float) ($rules['min_spend'] ?? 500);
                $query .= $this->wpdb->prepare( " AND COALESCE(CAST(m_spent.meta_value AS DECIMAL(12,2)), 0.00) >= %f", $min_spend );
                break;
            case 'inactive':
                // Check last order date from WooCommerce orders
                $days = (int) ($rules['inactive_days'] ?? 90);
                $cutoff = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
                $query .= $this->wpdb->prepare( 
                    " AND u.ID NOT IN (
                        SELECT DISTINCT post_author FROM {$this->wpdb->prefix}posts
                        WHERE post_type = 'shop_order' 
                          AND post_status IN ('wc-completed', 'wc-processing')
                          AND post_date >= %s
                    )", 
                    $cutoff 
                );
                break;
            case 'frequent':
                $min_orders = (int) ($rules['min_orders'] ?? 5);
                $query .= $this->wpdb->prepare( " AND COALESCE(CAST(m_count.meta_value AS UNSIGNED), 0) >= %d", $min_orders );
                break;
            case 'location':
                $country_code = strtoupper( sanitize_text_field( $rules['country'] ?? '' ) );
                if ( ! empty( $country_code ) ) {
                    $query .= $this->wpdb->prepare( " AND UPPER(m_country.meta_value) = %s", $country_code );
                }
                break;
        }

        // Add filter by order count range if provided
        if ( isset( $rules['order_count_min'] ) && $rules['order_count_min'] !== '' ) {
            $query .= $this->wpdb->prepare( " AND COALESCE(CAST(m_count.meta_value AS UNSIGNED), 0) >= %d", (int) $rules['order_count_min'] );
        }
        if ( isset( $rules['order_count_max'] ) && $rules['order_count_max'] !== '' ) {
            $query .= $this->wpdb->prepare( " AND COALESCE(CAST(m_count.meta_value AS UNSIGNED), 0) <= %d", (int) $rules['order_count_max'] );
        }

        // Add filter by total spend range if provided
        if ( isset( $rules['total_spend_min'] ) && $rules['total_spend_min'] !== '' ) {
            $query .= $this->wpdb->prepare( " AND COALESCE(CAST(m_spent.meta_value AS DECIMAL(12,2)), 0.00) >= %f", (float) $rules['total_spend_min'] );
        }
        if ( isset( $rules['total_spend_max'] ) && $rules['total_spend_max'] !== '' ) {
            $query .= $this->wpdb->prepare( " AND COALESCE(CAST(m_spent.meta_value AS DECIMAL(12,2)), 0.00) <= %f", (float) $rules['total_spend_max'] );
        }

        // Product history search
        if ( ! empty( $rules['bought_product_ids'] ) ) {
            $prod_ids = array_filter( array_map( 'absint', (array) $rules['bought_product_ids'] ) );
            if ( ! empty( $prod_ids ) ) {
                $order_items = $this->wpdb->prefix . 'woocommerce_order_items';
                $order_itemmeta = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
                $postmeta = $this->wpdb->prefix . 'postmeta';

                $prod_placeholders = implode( ',', array_fill( 0, count( $prod_ids ), '%d' ) );
                
                $query .= $this->wpdb->prepare(
                    " AND u.ID IN (
                        SELECT DISTINCT customer_user.meta_value 
                        FROM {$order_items} oi
                        INNER JOIN {$order_itemmeta} im ON oi.order_item_id = im.order_item_id
                        INNER JOIN {$postmeta} customer_user ON oi.order_id = customer_user.post_id
                        WHERE oi.order_item_type = 'line_item'
                          AND im.meta_key = '_product_id'
                          AND im.meta_value IN ($prod_placeholders)
                          AND customer_user.meta_key = '_customer_user'
                          AND customer_user.meta_value > 0
                    )",
                    ...$prod_ids
                );
            }
        }

        $query .= " ORDER BY total_spent DESC LIMIT 500"; // cap segment output to avoid memory fatigue
        return $this->wpdb->get_results( $query, ARRAY_A ) ?: [];
    }
}
