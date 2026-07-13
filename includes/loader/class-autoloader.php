<?php
namespace WooDynamicDiscountRulesMaster\Loader;

class Autoloader {
    protected static $prefixes = [];

    public static function init() {
        spl_autoload_register([__CLASS__, 'load']);
        self::add_namespace('WooDynamicDiscountRulesMaster\\',           WDDRM_PLUGIN_DIR . 'includes/');
        self::add_namespace('WooDynamicDiscountRulesMaster\\Admin\\',    WDDRM_PLUGIN_DIR . 'admin/');
        self::add_namespace('WooDynamicDiscountRulesMaster\\API\\',      WDDRM_PLUGIN_DIR . 'api/');
        self::add_namespace('WooDynamicDiscountRulesMaster\\Frontend\\', WDDRM_PLUGIN_DIR . 'frontend/');
    }

    public static function add_namespace( string $prefix, string $base_dir ) {
        $prefix   = trim( $prefix, '\\' ) . '\\';
        $base_dir = rtrim( $base_dir, DIRECTORY_SEPARATOR ) . '/';
        self::$prefixes[ $prefix ] = $base_dir;
    }

    public static function load( string $class ) {
        foreach ( self::$prefixes as $prefix => $base_dir ) {
            if ( strpos( $class, $prefix ) !== 0 ) continue;

            $relative   = substr( $class, strlen( $prefix ) );
            $parts      = explode( '\\', $relative );
            $class_name = array_pop( $parts );

            // CamelCase / Acronyms → kebab-case: "AIController" → "ai-controller"
            $kebab = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $class_name );
            $kebab = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $kebab );
            $kebab = strtolower( $kebab );

            $subdir = empty( $parts ) ? '' : implode( '/', array_map(
                function($p) {
                    $val = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $p );
                    $val = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $val );
                    return strtolower( $val );
                },
                $parts
            ) ) . '/';

            foreach ( ['class-', 'trait-', 'interface-'] as $pfx ) {
                $file = $base_dir . $subdir . $pfx . $kebab . '.php';
                if ( file_exists( $file ) ) { require_once $file; return; }
            }
        }
    }
}
