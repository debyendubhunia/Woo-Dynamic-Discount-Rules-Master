<?php

use WooDynamicDiscountRulesMaster\DiscountEngine\DiscountEngine;

class TestDiscountEngine {

    private $engine;

    public function __construct() {
        $this->engine = new DiscountEngine();
    }

    public function setup() {
        global $mock_woocommerce, $wpdb, $mock_user_id, $mock_post_terms, $mock_posts;
        
        $mock_woocommerce->cart->items = [];
        $mock_woocommerce->cart->subtotal = 0.0;
        $mock_woocommerce->cart->total = 0.0;
        $mock_woocommerce->cart->fees = [];
        $mock_woocommerce->cart->applied_coupons = [];
        
        $wpdb->results = [];
        $wpdb->queries = [];
        $wpdb->var_results = [];
        
        $mock_user_id = 0;
        $mock_post_terms = [];
        $mock_posts = [];
    }

    public function assert($condition, $message) {
        if (!$condition) {
            throw new Exception("Assertion failed: $message");
        }
    }

    public function testProductFixedDiscount() {
        global $wpdb;
        
        // Rule: $2 discount on Product 101
        $wpdb->results = [
            [
                'id' => 1,
                'name' => 'Save $2 on Product 101',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'discount_type' => 'product_fixed',
                'discount_data' => json_encode(['amount' => 2]),
                'filter_data' => json_encode(['included_products' => [101]]),
                'condition_data' => json_encode([]),
                'bxgy_data' => json_encode([]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ]
        ];

        // Add product 101 with price 10.0 and qty 2
        $cart = WC()->cart;
        $key = $cart->add_to_cart(101, 2);
        $cart->items[$key]['data']->set_price(10.0);

        $this->engine->apply_cart_discounts($cart);

        // Price should be 10.0 - 2.0 = 8.0
        $new_price = $cart->items[$key]['data']->get_price();
        $this->assert($new_price == 8.0, "Product price should be reduced by $2 (actual: $new_price)");
    }

    public function testProductPercentageDiscount() {
        global $wpdb;
        
        // Rule: 10% discount on Product 102
        $wpdb->results = [
            [
                'id' => 2,
                'name' => '10% Off Product 102',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'discount_type' => 'product_percentage',
                'discount_data' => json_encode(['percentage' => 10]),
                'filter_data' => json_encode(['included_products' => [102]]),
                'condition_data' => json_encode([]),
                'bxgy_data' => json_encode([]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ]
        ];

        $cart = WC()->cart;
        $key = $cart->add_to_cart(102, 1);
        $cart->items[$key]['data']->set_price(50.0);

        $this->engine->apply_cart_discounts($cart);

        // Price should be 50.0 - 10% = 45.0
        $new_price = $cart->items[$key]['data']->get_price();
        $this->assert($new_price == 45.0, "Product price should be reduced by 10% (actual: $new_price)");
    }

    public function testCartLevelDiscountFee() {
        global $wpdb;
        
        // Rule: $15 off entire cart when subtotal >= $100
        $wpdb->results = [
            [
                'id' => 3,
                'name' => '$15 Off Cart',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'discount_type' => 'cart_fixed',
                'discount_data' => json_encode(['amount' => 15, 'mode' => 'fixed']),
                'filter_data' => json_encode([]),
                'condition_data' => json_encode([
                    'operator' => 'AND',
                    'groups' => [
                        [
                            'type' => 'cart_subtotal',
                            'operator' => '>=',
                            'value' => 100
                        ]
                    ]
                ]),
                'bxgy_data' => json_encode([]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ]
        ];

        $cart = WC()->cart;
        $cart->subtotal = 120.00;
        
        $this->engine->apply_cart_discounts($cart);

        $this->assert(count($cart->fees) === 1, "One fee should be added to the cart");
        $this->assert($cart->fees[0]['name'] === '$15 Off Cart', "Fee name should match rule name");
        $this->assert($cart->fees[0]['amount'] == -15.00, "Fee amount should be negative 15");
    }

    public function testRulePriorityAndStopFurtherRules() {
        global $wpdb;

        // Two rules:
        // Rule 1: Priority 20, stop further rules, 10% discount
        // Rule 2: Priority 10, 20% discount (should not be executed)
        $wpdb->results = [
            [
                'id' => 4,
                'name' => 'Rule 1 (10% off)',
                'status' => 1,
                'priority' => 20,
                'stop_further_rules' => 1,
                'discount_type' => 'product_percentage',
                'discount_data' => json_encode(['percentage' => 10]),
                'filter_data' => json_encode(['included_products' => [103]]),
                'condition_data' => json_encode([]),
                'bxgy_data' => json_encode([]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ],
            [
                'id' => 5,
                'name' => 'Rule 2 (20% off)',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'discount_type' => 'product_percentage',
                'discount_data' => json_encode(['percentage' => 20]),
                'filter_data' => json_encode(['included_products' => [103]]),
                'condition_data' => json_encode([]),
                'bxgy_data' => json_encode([]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ]
        ];

        $cart = WC()->cart;
        $key = $cart->add_to_cart(103, 1);
        $cart->items[$key]['data']->set_price(100.0);

        $this->engine->apply_cart_discounts($cart);

        // Price should be 100 - 10% = 90.0 (Rule 2 was stopped)
        $new_price = $cart->items[$key]['data']->get_price();
        $this->assert($new_price == 90.0, "Rule 2 should have been stopped by stop_further_rules");
    }

    public function testBXGYDiscount() {
        global $wpdb;

        // Buy 2 of Product 104, Get 1 free (100% off) of Product 104
        $wpdb->results = [
            [
                'id' => 6,
                'name' => 'Buy 2 Get 1 Free',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'discount_type' => 'bxgy_free',
                'discount_data' => json_encode([]),
                'filter_data' => json_encode([]),
                'condition_data' => json_encode([]),
                'bxgy_data' => json_encode([
                    'buy_quantity' => 2,
                    'get_quantity' => 1,
                    'buy_products' => [104],
                    'get_products' => [104],
                    'discount_percentage' => 100,
                    'discount_type' => 'percentage',
                    'discount_value' => 100,
                    'target_mode' => 'specific'
                ]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ]
        ];

        $cart = WC()->cart;
        $key = $cart->add_to_cart(104, 3); // buy 2, get 1 free. Total 3 items.
        $cart->items[$key]['data']->set_price(10.0);

        $this->engine->apply_cart_discounts($cart);

        // The discount on 3 items: 1 item is free, so average discount per item is 10/3 = 3.3333...
        // New price per item should be 10 - 3.3333 = 6.6666
        $new_price = $cart->items[$key]['data']->get_price();
        $this->assert(round($new_price, 2) == 6.67, "Cheapest/reward product Y should be discounted (actual: $new_price)");
    }

    public function testURLCouponAutoApply() {
        // Instantiate and initialize main to boot container dependencies
        $main = \WooDynamicDiscountRulesMaster\Main::get_instance();
        $main->init();
        
        // Mock session coupon
        WC()->session->set('wddrm_url_coupon', 'SUMMER20');
        
        $cart = WC()->cart;
        $cart->applied_coupons = [];
        
        // This should trigger the apply_discounts method of Main
        $main->apply_discounts($cart);
        
        $this->assert(in_array('summer20', $cart->applied_coupons, true), "SUMMER20 coupon should be automatically added to the cart");
    }

    public function testCountryRuleShippingAddress() {
        global $wpdb;
        
        $wpdb->results = [
            [
                'id' => 7,
                'name' => 'US Only $10 Off',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'discount_type' => 'country_rule',
                'discount_data' => json_encode(['amount' => 10, 'mode' => 'fixed', 'countries' => ['US', 'CA']]),
                'filter_data' => json_encode([]),
                'condition_data' => json_encode([]),
                'bxgy_data' => json_encode([]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ]
        ];
        
        $cart = WC()->cart;
        $cart->subtotal = 50.0;
        
        // Try with UK shipping address (should not apply)
        WC()->customer->shipping_country = 'GB';
        WC()->customer->billing_country = 'GB';
        $this->engine->apply_cart_discounts($cart);
        $this->assert(count($cart->fees) === 0, "No fee should be added for country GB");
        
        // Try with CA shipping address (should apply)
        WC()->customer->shipping_country = 'CA';
        $this->engine->apply_cart_discounts($cart);
        $this->assert(count($cart->fees) === 1, "Fee should be added for country CA");
        $this->assert($cart->fees[0]['amount'] == -10.0, "Fee should be $10 off");
    }

    public function testStateRuleShippingAddress() {
        global $wpdb;
        
        $wpdb->results = [
            [
                'id' => 8,
                'name' => 'NY Only $5 Off',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'discount_type' => 'state_rule',
                'discount_data' => json_encode(['amount' => 5, 'mode' => 'fixed', 'states' => ['NY', 'CA']]),
                'filter_data' => json_encode([]),
                'condition_data' => json_encode([]),
                'bxgy_data' => json_encode([]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ]
        ];
        
        $cart = WC()->cart;
        $cart->subtotal = 30.0;
        
        // Try with TX state (should not apply)
        WC()->customer->shipping_state = 'TX';
        WC()->customer->billing_state = 'TX';
        $this->engine->apply_cart_discounts($cart);
        $this->assert(count($cart->fees) === 0, "No fee should be added for state TX");
        
        // Try with NY state (should apply)
        WC()->customer->shipping_state = 'NY';
        $this->engine->apply_cart_discounts($cart);
        $this->assert(count($cart->fees) === 1, "Fee should be added for state NY");
        $this->assert($cart->fees[0]['amount'] == -5.0, "Fee should be $5 off");
    }

    public function testFirstOrderGuestChecking() {
        global $wpdb, $mock_wc_orders;
        
        $wpdb->results = [
            [
                'id' => 9,
                'name' => 'First Order Discount',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'discount_type' => 'first_order',
                'discount_data' => json_encode(['amount' => 20, 'mode' => 'fixed']),
                'filter_data' => json_encode([]),
                'condition_data' => json_encode([]),
                'bxgy_data' => json_encode([]),
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
                'usage_count' => 0,
                'start_date' => null,
                'end_date' => null
            ]
        ];
        
        $cart = WC()->cart;
        $cart->subtotal = 100.0;
        
        // Guest user with no prior orders under email guest@example.com
        WC()->customer->billing_email = 'guest@example.com';
        $mock_wc_orders = []; // no orders
        
        $this->engine->apply_cart_discounts($cart);
        $this->assert(count($cart->fees) === 1, "First order fee should be added for new guest email");
        $this->assert($cart->fees[0]['amount'] == -20.0, "First order fee should be $20 off");
        
        // Guest user with existing orders under email guest@example.com
        $cart->fees = [];
        $mock_wc_orders = [123]; // has order ID 123
        
        $this->engine->apply_cart_discounts($cart);
        $this->assert(count($cart->fees) === 0, "No discount for guest email with existing order history");
    }
}
