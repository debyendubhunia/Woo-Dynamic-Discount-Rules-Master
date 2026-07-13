<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) exit;

if ( ! defined( 'WDDRM_PLUGIN_DIR' ) ) {
    define( 'WDDRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

require_once WDDRM_PLUGIN_DIR . 'includes/class-database.php';

$db = new WooDynamicDiscountRulesMaster\Database();
$db->drop_tables();

delete_option('wddrm_enable_cache');
delete_option('wddrm_cache_ttl');
