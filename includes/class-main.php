<?php
namespace WooDynamicDiscountRulesMaster;

use WooDynamicDiscountRulesMaster\Traits\Singleton;

class Main {
    use Singleton;

    /**
     * @var Container
     */
    private $container;

    public function init() {
        if ( ! class_exists('WooCommerce') ) return;

        $this->container = Container::get_instance();
        $this->container->get('database')->init_tables();

        if ( is_admin() ) {
            $this->container->get('admin')->init();
            $this->container->get('dashboard_controller')->init();
            $this->container->get('segmentation_controller')->init();
            $this->container->get('ai_controller')->init();
            $this->container->get('import_export_controller')->init();
        }
        $this->container->get('frontend')->init();

        add_action( 'rest_api_init',                          [$this, 'init_rest_api'] );
        add_action( 'wp_loaded',                              [$this, 'handle_url_coupon'] );
        // woocommerce_before_calculate_totals fires during every cart recalculation
        add_action( 'woocommerce_before_calculate_totals',    [$this, 'handle_auto_add'], 10, 1 );
        add_action( 'woocommerce_before_calculate_totals',    [$this, 'apply_discounts'], 20, 1 );
        add_action( 'woocommerce_cart_calculate_fees',        [$this, 'apply_fee_discounts'], 20, 1 );
        // Record usage and persist applied rules to order meta at checkout
        add_action( 'woocommerce_checkout_order_created',     [$this, 'attach_discounts_to_order'], 10, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [$this, 'attach_discounts_to_order'], 10, 1 );
        add_action( 'woocommerce_thankyou',                   [$this, 'attach_discounts_to_order_by_id'], 10, 1 );

        // Persist BOGO auto-added tags in WooCommerce session
        add_filter( 'woocommerce_get_cart_item_from_session', [$this, 'get_cart_item_from_session'], 10, 2 );
        add_filter( 'woocommerce_cart_item_data_to_session',  [$this, 'cart_item_data_to_session'], 10, 2 );
    }

    public function init_rest_api() {
        $api = new \WooDynamicDiscountRulesMaster\API\API();
        $api->init();
    }

    public function handle_url_coupon() {
        if ( is_admin() && ! defined('DOING_AJAX') ) return;
        if ( ! empty( $_GET['wddrm_coupon'] ) ) {
            $coupon = sanitize_text_field( $_GET['wddrm_coupon'] );
            if ( WC()->session ) {
                if ( method_exists( WC()->session, 'has_session' ) && ! WC()->session->has_session() ) {
                    WC()->session->set_customer_session_cookie( true );
                }
                WC()->session->set( 'wddrm_url_coupon', $coupon );
            }
        }
    }

    public function handle_auto_add( $cart ) {
        if ( is_admin() && ! defined('DOING_AJAX') ) return;
        $engine = $this->container->get('discount_engine');
        if ( $engine ) $engine->handle_auto_add( $cart );
    }

    public function apply_discounts( $cart ) {
        if ( is_admin() && ! defined('DOING_AJAX') ) return;

        // Auto-apply coupon from URL if stored in session
        if ( WC()->session && $cart ) {
            $url_coupon = WC()->session->get( 'wddrm_url_coupon' );
            if ( ! empty( $url_coupon ) ) {
                if ( ! $cart->has_discount( $url_coupon ) ) {
                    $cart->add_discount( $url_coupon );
                }
            }
        }

        $engine = $this->container->get('discount_engine');
        if ( $engine ) $engine->apply_cart_discounts( $cart );
    }

    public function apply_fee_discounts( $cart ) {
        if ( is_admin() && ! defined('DOING_AJAX') ) return;
        $engine = $this->container->get('discount_engine');
        if ( $engine ) $engine->apply_fee_discounts( $cart );
    }

    public function attach_discounts_to_order( $order ) {
        if ( ! $order ) return;

        // Prevent duplicate processing on multiple hooks
        if ( $order->get_meta( '_wddrm_discounts_attached' ) === 'yes' ) return;

        $applied = WC()->session ? WC()->session->get('wddrm_applied_rules', []) : [];
        if ( empty($applied) ) return;

        $repo     = new \WooDynamicDiscountRulesMaster\Repository\RuleRepository();
        $order_id = $order->get_id();
        $user_id  = $order->get_customer_id();
        global $wpdb;

        foreach ( $applied as $entry ) {
            $rule_id = (int)( $entry['rule_id'] ?? 0 );
            if ( ! $rule_id ) continue;

            $wpdb->insert( $wpdb->prefix . 'wddrm_rule_applications', [
                'rule_id'           => $rule_id,
                'order_id'          => $order_id,
                'user_id'           => $user_id ?: 0,
                'discount_amount'   => (float)( $entry['discount_amount']   ?? 0 ),
                'original_subtotal' => (float)( $entry['original_subtotal'] ?? 0 ),
            ] );

            $repo->increment_usage( $rule_id, $user_id );
        }

        $order->update_meta_data( '_wddrm_discounts_attached', 'yes' );
        $order->save();

        WC()->session->set('wddrm_applied_rules', []);
    }

    public function attach_discounts_to_order_by_id( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $this->attach_discounts_to_order( $order );
        }
    }

    public function get_cart_item_from_session( $item, $values ) {
        if ( isset( $values['wddrm_auto_added'] ) ) {
            $item['wddrm_auto_added'] = $values['wddrm_auto_added'];
        }
        if ( isset( $values['wddrm_auto_added_rule_id'] ) ) {
            $item['wddrm_auto_added_rule_id'] = $values['wddrm_auto_added_rule_id'];
        }
        return $item;
    }

    public function cart_item_data_to_session( $session_data, $values ) {
        if ( isset( $values['wddrm_auto_added'] ) ) {
            $session_data['wddrm_auto_added'] = $values['wddrm_auto_added'];
        }
        if ( isset( $values['wddrm_auto_added_rule_id'] ) ) {
            $session_data['wddrm_auto_added_rule_id'] = $values['wddrm_auto_added_rule_id'];
        }
        return $session_data;
    }

    public function get_container(): Container {
        return $this->container;
    }
}
