<?php
/**
 * Plugin Name: Woo Dynamic Discount Rules Master
 * Plugin URI:  https://your-site.com
 * Description: Enterprise dynamic discount rules engine for WooCommerce.
 * Version:     1.0.0
 * Author:      Debyendu Bhunia
 * License:     GPL v2 or later
 * Text Domain: woo-dynamic-discount-rules-master
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) exit;

// PHP version compatibility pre-check
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'Woo Dynamic Discount Rules Master requires PHP 7.4 or higher. The plugin has been deactivated.', 'woo-dynamic-discount-rules-master' )
            . '</p></div>';
        if ( isset( $_GET['activate'] ) ) {
            unset( $_GET['activate'] );
        }
    });
    add_action( 'admin_init', function() {
        deactivate_plugins( plugin_basename( __FILE__ ) );
    });
    return;
}

define('WDDRM_VERSION',    '1.0.0');
define('WDDRM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WDDRM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Boot the autoloader immediately — must happen at file-load time so that
// register_activation_hook / register_deactivation_hook callbacks (which fire
// before plugins_loaded) can resolve namespaced classes.
if (file_exists(WDDRM_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once WDDRM_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    require_once WDDRM_PLUGIN_DIR . 'includes/loader/class-autoloader.php';
    WooDynamicDiscountRulesMaster\Loader\Autoloader::init();
}

// Also require the classes used by activation/deactivation hooks directly,
// so they are available even if the autoloader is somehow not yet registered.
require_once WDDRM_PLUGIN_DIR . 'includes/class-database.php';
require_once WDDRM_PLUGIN_DIR . 'includes/class-activator.php';
require_once WDDRM_PLUGIN_DIR . 'includes/class-deactivator.php';

function wddrm_init() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Woo Dynamic Discount Rules Master requires WooCommerce to be installed and active.', 'woo-dynamic-discount-rules-master')
                . '</p></div>';
        });
        return;
    }
    load_plugin_textdomain(
        'woo-dynamic-discount-rules-master',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
    $plugin = WooDynamicDiscountRulesMaster\Main::get_instance();
    $plugin->init();
}
add_action('plugins_loaded', 'wddrm_init');

register_activation_hook(__FILE__,   ['WooDynamicDiscountRulesMaster\Activator',   'activate']);
register_deactivation_hook(__FILE__, ['WooDynamicDiscountRulesMaster\Deactivator', 'deactivate']);

// Disable activation and display error notice if WooCommerce is not active
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $actions ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        if ( isset( $actions['activate'] ) ) {
            $actions['activate'] = '<span style="color: #a7aaad; cursor: not-allowed;" title="' . esc_attr__( 'Requires WooCommerce to be installed and active', 'woo-dynamic-discount-rules-master' ) . '">' . esc_html__( 'Activate', 'woo-dynamic-discount-rules-master' ) . '</span>';
        }
    }
    return $actions;
} );

add_action( 'after_plugin_row_' . plugin_basename( __FILE__ ), function ( $plugin_file, $plugin_data, $status ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        $colspan = 3;
        if ( function_exists( '_get_list_table' ) ) {
            $wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );
            if ( $wp_list_table && method_exists( $wp_list_table, 'get_column_count' ) ) {
                $colspan = $wp_list_table->get_column_count();
            }
        }
        $active_class = is_plugin_active( $plugin_file ) ? 'active' : 'inactive';
        echo '<tr class="plugin-update-tr ' . esc_attr( $active_class ) . '" id="wddrm-dependency-notice" data-slug="' . esc_attr( $plugin_file ) . '">';
        echo '<td colspan="' . esc_attr( $colspan ) . '" class="plugin-update colspanchange">';
        echo '<div class="notice inline notice-error notice-alt" style="margin: 5px 20px 15px 20px;">';
        echo '<p>' . esc_html__( 'This plugin cannot be activated because WooCommerce is missing or inactive. Please install and activate WooCommerce first.', 'woo-dynamic-discount-rules-master' ) . '</p>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }
}, 10, 3 );
