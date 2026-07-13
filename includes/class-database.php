<?php
namespace WooDynamicDiscountRulesMaster;

class Database {
    const DB_VERSION_OPTION = 'wddrm_db_version';
    const DB_VERSION        = '2.1.0'; // bumped for schema expansion with segments

    public function init_tables() {
        $current = get_option( self::DB_VERSION_OPTION, '0.0.0' );
        if ( version_compare( $current, self::DB_VERSION, '<' ) ) {
            $this->create_tables();
            update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
        }

        // Safe fallback migration: check if rule_group or bxgy_data columns are missing and add them if necessary
        global $wpdb;
        $table_name = $wpdb->prefix . 'wddrm_rules';
        $columns = $wpdb->get_col( "DESCRIBE {$table_name}" );
        if ( ! empty( $columns ) ) {
            if ( ! in_array( 'rule_group', $columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN rule_group VARCHAR(50) NOT NULL DEFAULT 'cart' AFTER name" );
            }
            if ( ! in_array( 'bxgy_data', $columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN bxgy_data LONGTEXT AFTER condition_data" );
            }
        }
    }

    private function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Main rules table — dbDelta compliant
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wddrm_rules (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            rule_group varchar(50) NOT NULL DEFAULT 'cart',
            status tinyint(1) DEFAULT 1,
            priority int(11) DEFAULT 10,
            stop_further_rules tinyint(1) DEFAULT 0,
            discount_type varchar(80) NOT NULL,
            discount_data longtext NOT NULL,
            filter_data longtext,
            condition_data longtext,
            bxgy_data longtext,
            usage_limit_per_rule int(11) DEFAULT NULL,
            usage_limit_per_user int(11) DEFAULT NULL,
            usage_count bigint(20) unsigned DEFAULT 0,
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_status_priority  (status, priority),
            KEY idx_group_status  (rule_group, status),
            KEY idx_active_dates  (status, start_date, end_date)
        ) $charset;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wddrm_rule_applications (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned DEFAULT 0,
            discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            original_subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
            applied_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_rule_id  (rule_id),
            KEY idx_order_id  (order_id),
            KEY idx_user_id  (user_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wddrm_user_rule_tracking (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            usage_count int(10) unsigned DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_rule_user  (rule_id, user_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wddrm_cache (
            cache_key varchar(255) NOT NULL,
            cache_value longtext,
            cache_group varchar(100) DEFAULT 'default',
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (cache_key),
            KEY idx_expires_at  (expires_at)
        ) $charset;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wddrm_segments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            rules longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;" );
    }

    public function drop_tables() {
        global $wpdb;
        foreach ( ['wddrm_rules','wddrm_rule_applications','wddrm_user_rule_tracking','wddrm_cache','wddrm_segments'] as $t ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" );
        }
        delete_option( self::DB_VERSION_OPTION );
    }
}
