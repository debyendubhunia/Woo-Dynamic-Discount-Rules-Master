<?php

use WooDynamicDiscountRulesMaster\Conditions\ConditionEvaluator;

class TestConditionEvaluator {

    private $evaluator;

    public function __construct() {
        $this->evaluator = new ConditionEvaluator();
    }

    public function setup() {
        // Reset mocks before each test
        global $mock_woocommerce, $mock_user_id, $mock_wp_user, $mock_post_terms, $mock_posts,
               $mock_customer_order_count, $mock_customer_total_spent, $mock_customer_bought_products;

        $mock_woocommerce->cart->items = [];
        $mock_woocommerce->cart->subtotal = 0.0;
        $mock_woocommerce->cart->total = 0.0;
        $mock_woocommerce->cart->applied_coupons = [];
        $mock_woocommerce->customer->billing_country = '';
        $mock_woocommerce->customer->shipping_country = '';
        $mock_woocommerce->customer->billing_state = '';
        $mock_woocommerce->customer->shipping_state = '';
        $mock_woocommerce->session->data = [];

        $mock_user_id = 0;
        $mock_wp_user = null;
        $mock_post_terms = [];
        $mock_posts = [];
        $mock_customer_order_count = 0;
        $mock_customer_total_spent = 0.0;
        $mock_customer_bought_products = [];
    }

    public function assert($condition, $message) {
        if (!$condition) {
            throw new Exception("Assertion failed: $message");
        }
    }

    public function testEmptyConditions() {
        $rule = [
            'condition_data' => json_encode([])
        ];
        $this->assert($this->evaluator->evaluate($rule) === true, "Empty condition data should pass");
    }

    public function testRequiredCoupon() {
        $rule = [
            'condition_data' => json_encode([
                'required_coupon' => 'SAVE50'
            ])
        ];

        // Without coupon
        $this->assert($this->evaluator->evaluate($rule) === false, "Should fail without coupon");

        // With coupon
        WC()->cart->applied_coupons = ['save50'];
        $this->assert($this->evaluator->evaluate($rule) === true, "Should pass with matching coupon");
    }

    public function testCartSubtotalCondition() {
        $rule = [
            'condition_data' => json_encode([
                'operator' => 'AND',
                'groups' => [
                    [
                        'type' => 'cart_subtotal',
                        'operator' => '>=',
                        'value' => 100
                    ]
                ]
            ])
        ];

        WC()->cart->subtotal = 99.99;
        $this->assert($this->evaluator->evaluate($rule) === false, "Subtotal < 100 should fail");

        WC()->cart->subtotal = 100.00;
        $this->assert($this->evaluator->evaluate($rule) === true, "Subtotal >= 100 should pass");
    }

    public function testProductInCartCondition() {
        $rule = [
            'condition_data' => json_encode([
                'operator' => 'AND',
                'groups' => [
                    [
                        'type' => 'product_in_cart',
                        'operator' => '=',
                        'value' => [101, 102]
                    ]
                ]
            ])
        ];

        // Empty cart
        $this->assert($this->evaluator->evaluate($rule) === false, "Should fail with empty cart");

        // Cart with non-matching product
        WC()->cart->add_to_cart(999);
        $this->assert($this->evaluator->evaluate($rule) === false, "Should fail with non-matching product");

        // Cart with matching product
        WC()->cart->add_to_cart(101);
        $this->assert($this->evaluator->evaluate($rule) === true, "Should pass with matching product 101");
    }

    public function testUserRoleCondition() {
        $rule = [
            'condition_data' => json_encode([
                'operator' => 'AND',
                'groups' => [
                    [
                        'type' => 'user_role',
                        'operator' => '=',
                        'value' => ['administrator', 'vip']
                    ]
                ]
            ])
        ];

        global $mock_wp_user;
        $mock_wp_user = new MockWPUser();
        $mock_wp_user->roles = ['customer'];

        $this->assert($this->evaluator->evaluate($rule) === false, "Customer role should fail");

        $mock_wp_user->roles = ['vip'];
        $this->assert($this->evaluator->evaluate($rule) === true, "VIP role should pass");
    }

    public function testLocationConditions() {
        $rule = [
            'condition_data' => json_encode([
                'operator' => 'AND',
                'groups' => [
                    [
                        'type' => 'billing_country',
                        'operator' => '=',
                        'value' => ['US', 'CA']
                    ]
                ]
            ])
        ];

        WC()->customer->billing_country = 'GB';
        $this->assert($this->evaluator->evaluate($rule) === false, "Billing country GB should fail");

        WC()->customer->billing_country = 'US';
        $this->assert($this->evaluator->evaluate($rule) === true, "Billing country US should pass");
    }

    public function testMultipleGroupsOrOperator() {
        $rule = [
            'condition_data' => json_encode([
                'operator' => 'OR',
                'groups' => [
                    [
                        'type' => 'cart_subtotal',
                        'operator' => '>=',
                        'value' => 200
                    ],
                    [
                        'type' => 'is_logged_in',
                        'operator' => '=',
                        'value' => true
                    ]
                ]
            ])
        ];

        // Guest with small subtotal
        WC()->cart->subtotal = 50.00;
        global $mock_user_id;
        $mock_user_id = 0;
        $this->assert($this->evaluator->evaluate($rule) === false, "Guest, subtotal 50 should fail");

        // Logged in with small subtotal
        $mock_user_id = 12;
        $this->assert($this->evaluator->evaluate($rule) === true, "Logged in, subtotal 50 should pass (OR operator)");

        // Guest with large subtotal
        $mock_user_id = 0;
        WC()->cart->subtotal = 250.00;
        $this->assert($this->evaluator->evaluate($rule) === true, "Guest, subtotal 250 should pass (OR operator)");
    }
}
