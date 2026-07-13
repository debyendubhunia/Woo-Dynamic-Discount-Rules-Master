<?php
namespace WooDynamicDiscountRulesMaster;
class Activator {
    public static function activate() {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            wp_die(
                __('Woo Dynamic Discount Rules Master requires PHP 7.4 or higher.', 'woo-dynamic-discount-rules-master'),
                '',
                ['back_link' => true]
            );
        }
        if (!class_exists('WooCommerce')) {
            wp_die(
                __('Woo Dynamic Discount Rules Master requires WooCommerce to be installed and active.', 'woo-dynamic-discount-rules-master'),
                '',
                ['back_link' => true]
            );
        }
        (new Database())->init_tables();
        add_option('wddrm_enable_cache', 'yes');
        add_option('wddrm_cache_ttl', 3600);
        flush_rewrite_rules();
    }
}
