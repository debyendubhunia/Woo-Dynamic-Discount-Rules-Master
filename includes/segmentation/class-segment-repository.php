<?php
namespace WooDynamicDiscountRulesMaster\Segmentation;

if ( ! defined( 'ABSPATH' ) ) exit;

class SegmentRepository {

    private $wpdb;
    private $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'wddrm_segments';
    }

    public function get_all(): array {
        return $this->wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY id DESC", ARRAY_A ) ?: [];
    }

    public function get( int $id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function save( array $data ): int {
        $id = ! empty( $data['id'] ) ? (int) $data['id'] : 0;
        $row = [
            'name'  => sanitize_text_field( $data['name'] ?? '' ),
            'rules' => wp_json_encode( $data['rules'] ?? [] )
        ];

        if ( $id ) {
            $this->wpdb->update( $this->table, $row, ['id' => $id] );
            return $id;
        }

        $this->wpdb->insert( $this->table, $row );
        return (int) $this->wpdb->insert_id;
    }

    public function delete( int $id ): void {
        $this->wpdb->delete( $this->table, ['id' => $id] );
    }
}
