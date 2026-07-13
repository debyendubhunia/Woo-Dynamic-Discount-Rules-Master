<?php
/**
 * Bootstrap file for Woo Dynamic Discount Rules Master tests.
 * This file mocks WordPress and WooCommerce environments so core classes
 * can be tested in isolation.
 */

// Define WooCommerce and WordPress constants
define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

// Autoload plugin classes
require_once dirname(__DIR__) . '/includes/loader/class-autoloader.php';
WooDynamicDiscountRulesMaster\Loader\Autoloader::init();

// Mock global $wpdb object
class MockWPDB {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public $queries = [];
    public $results = [];
    public $var_results = [];

    public function prepare($query, ...$args) {
        // Simple replacement mock
        $query = str_replace('%s', "'%s'", $query);
        $query = str_replace('%d', "%d", $query);
        return vsprintf($query, $args);
    }

    public function get_results($query, $output_type = ARRAY_A) {
        $this->queries[] = $query;
        return $this->results;
    }

    public function get_row($query, $output_type = ARRAY_A) {
        $this->queries[] = $query;
        return !empty($this->results) ? reset($this->results) : null;
    }

    public function get_var($query) {
        $this->queries[] = $query;
        return $this->var_results[$query] ?? reset($this->var_results) ?? null;
    }

    public function get_col($query, $column_offset = 0) {
        $this->queries[] = $query;
        return [];
    }

    public function insert($table, $data) {
        $this->queries[] = "INSERT INTO $table";
        return true;
    }

    public function update($table, $data, $where) {
        $this->queries[] = "UPDATE $table";
        return true;
    }

    public function delete($table, $where) {
        $this->queries[] = "DELETE FROM $table";
        return true;
    }

    public function query($query) {
        $this->queries[] = $query;
        return true;
    }
}

global $wpdb;
$wpdb = new MockWPDB();

// Mock WooCommerce Core Class
if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}

// Mock WordPress functions
if (!function_exists('is_admin')) {
    function is_admin() {
        return false;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {}
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        return true;
    }
}

if (!function_exists('wp_roles')) {
    function wp_roles() {
        return new class {
            public function get_names() {
                return ['administrator' => 'Administrator', 'customer' => 'Customer'];
            }
        };
    }
}

if (!function_exists('get_woocommerce_currency_symbol')) {
    function get_woocommerce_currency_symbol($currency = '') {
        return '$';
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($str) {
        return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $str));
    }
}

if (!function_exists('absint')) {
    function absint($val) {
        return abs((int)$val);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('get_current_user_id')) {
    global $mock_user_id;
    $mock_user_id = 0;
    function get_current_user_id() {
        global $mock_user_id;
        return $mock_user_id;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return get_current_user_id() > 0;
    }
}

// Mock User Object
class MockWPUser {
    public $roles = [];
}

if (!function_exists('wp_get_current_user')) {
    global $mock_wp_user;
    $mock_wp_user = null;
    function wp_get_current_user() {
        global $mock_wp_user;
        if (!$mock_wp_user) {
            $mock_wp_user = new MockWPUser();
        }
        return $mock_wp_user;
    }
}

// Mock post/taxonomy functions
global $mock_post_terms;
$mock_post_terms = []; // [product_id => [term_ids]]

if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($post_id, $taxonomy, $args = []) {
        global $mock_post_terms;
        return $mock_post_terms[$post_id] ?? [];
    }
}

global $mock_posts;
$mock_posts = [];

if (!function_exists('get_posts')) {
    function get_posts($args = []) {
        global $mock_posts;
        return $mock_posts;
    }
}

// Mock WooCommerce Customer functions
if (!function_exists('wc_get_customer_order_count')) {
    global $mock_customer_order_count;
    $mock_customer_order_count = 0;
    function wc_get_customer_order_count($user_id) {
        global $mock_customer_order_count;
        return $mock_customer_order_count;
    }
}

if (!function_exists('wc_get_customer_total_spent')) {
    global $mock_customer_total_spent;
    $mock_customer_total_spent = 0.0;
    function wc_get_customer_total_spent($user_id) {
        global $mock_customer_total_spent;
        return $mock_customer_total_spent;
    }
}

if (!function_exists('wc_customer_bought_product')) {
    global $mock_customer_bought_products;
    $mock_customer_bought_products = []; // [user_id => [product_ids]]
    function wc_customer_bought_product($email, $user_id, $product_id) {
        global $mock_customer_bought_products;
        return in_array($product_id, $mock_customer_bought_products[$user_id] ?? [], true);
    }
}

// Mock WooCommerce product class
class MockWCProduct {
    private $price;
    public function __construct($price) {
        $this->price = (float)$price;
    }
    public function get_price() {
        return $this->price;
    }
    public function set_price($price) {
        $this->price = (float)$price;
    }
}

// Mock WooCommerce Cart
class MockWCCart {
    public $items = [];
    public $subtotal = 0.0;
    public $total = 0.0;
    public $fees = [];
    public $applied_coupons = [];

    public function is_empty() {
        return empty($this->items);
    }

    public function get_cart() {
        return $this->items;
    }

    public function get_subtotal() {
        return $this->subtotal;
    }

    public function get_total($context = '') {
        return $this->total;
    }

    public function get_cart_contents_count() {
        $count = 0;
        foreach ($this->items as $item) {
            $count += $item['quantity'];
        }
        return $count;
    }

    public function add_fee($name, $amount, $taxable = false, $tax_class = '') {
        $this->fees[] = [
            'name' => $name,
            'amount' => $amount
        ];
    }

    public function set_quantity($key, $qty, $refresh = true) {
        if (isset($this->items[$key])) {
            $this->items[$key]['quantity'] = $qty;
        }
    }

    public function remove_cart_item($key) {
        unset($this->items[$key]);
    }

    public function add_to_cart($product_id, $quantity = 1, $variation_id = 0, $variation = [], $cart_item_data = []) {
        $key = md5($product_id . serialize($cart_item_data));
        $this->items[$key] = array_merge([
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'quantity' => $quantity,
            'data' => new MockWCProduct(10.0), // default price
        ], $cart_item_data);
        return $key;
    }

    public function has_discount($coupon) {
        return in_array(strtolower($coupon), $this->applied_coupons, true);
    }

    public function add_discount($coupon) {
        $this->applied_coupons[] = strtolower($coupon);
        return true;
    }

    public function get_customer() {
        return WC()->customer;
    }
}

// Mock WooCommerce Customer
class MockWCCustomer {
    public $billing_country = '';
    public $shipping_country = '';
    public $billing_state = '';
    public $shipping_state = '';
    public $billing_email = '';

    public function get_billing_country() { return $this->billing_country; }
    public function get_shipping_country() { return $this->shipping_country; }
    public function get_billing_state() { return $this->billing_state; }
    public function get_shipping_state() { return $this->shipping_state; }
    public function get_billing_email() { return $this->billing_email; }
}

// Mock WooCommerce Session
class MockWCSession {
    public $data = [];
    public function get($key, $default = null) {
        return $this->data[$key] ?? $default;
    }
    public function set($key, $value) {
        $this->data[$key] = $value;
    }
    public function has_session() {
        return true;
    }
    public function set_customer_session_cookie($bool) {
        return true;
    }
}

// Mock WooCommerce main instance
class MockWooCommerce {
    public $cart;
    public $customer;
    public $session;

    public function __construct() {
        $this->cart = new MockWCCart();
        $this->customer = new MockWCCustomer();
        $this->session = new MockWCSession();
    }
}

if (!function_exists('WC')) {
    global $mock_woocommerce;
    $mock_woocommerce = new MockWooCommerce();
    function WC() {
        global $mock_woocommerce;
        return $mock_woocommerce;
    }
}

// Mock wc_get_orders
if (!function_exists('wc_get_orders')) {
    global $mock_wc_orders;
    $mock_wc_orders = [];
    function wc_get_orders($args) {
        global $mock_wc_orders;
        return $mock_wc_orders;
    }
}

// Mock home_url
if (!function_exists('home_url')) {
    function home_url($path = '') {
        return 'https://example.com' . $path;
    }
}

// Mock add_query_arg
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value, $url) {
        return $url . (strpos($url, '?') === false ? '?' : '&') . urlencode($key) . '=' . urlencode($value);
    }
}

