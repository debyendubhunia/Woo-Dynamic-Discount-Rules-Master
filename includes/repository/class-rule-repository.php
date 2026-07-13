<?php
namespace WooDynamicDiscountRulesMaster\Repository;

class RuleRepository {

    private $wpdb;
    private $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'wddrm_rules';
    }

    public function get_all(): array {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY priority DESC, id ASC",
            ARRAY_A
        ) ?: [];
    }

    public function get_active_rules(): array {
        $now = current_time( 'mysql' );
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 1
               AND (start_date IS NULL OR start_date <= %s)
               AND (end_date   IS NULL OR end_date   >= %s)
             ORDER BY priority DESC",
            $now, $now
        ), ARRAY_A ) ?: [];
    }

    public function get( int $id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function save( array $data ): int {
        $row = $this->prepare_row( $data );
        $id  = ! empty( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $id ) {
            $this->wpdb->update( $this->table, $row, ['id' => $id] );
            return $id;
        }

        $this->wpdb->insert( $this->table, $row );
        return (int) $this->wpdb->insert_id;
    }

    public function delete( int $id ): void {
        $this->wpdb->delete( $this->table, ['id' => $id] );
        // Also clean up tracking data for this rule
        $this->wpdb->delete( $this->wpdb->prefix . 'wddrm_user_rule_tracking', ['rule_id' => $id] );
        $this->wpdb->delete( $this->wpdb->prefix . 'wddrm_rule_applications',  ['rule_id' => $id] );
    }

    public function set_status( int $id, int $status ): void {
        $this->wpdb->update( $this->table, ['status' => $status], ['id' => $id] );
    }

    public function increment_usage( int $rule_id, int $user_id = 0 ): void {
        // Global usage count
        $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$this->table} SET usage_count = usage_count + 1 WHERE id = %d",
            $rule_id
        ) );

        // Per-user tracking
        if ( $user_id > 0 ) {
            $tracking = $this->wpdb->prefix . 'wddrm_user_rule_tracking';
            $exists   = $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT id FROM {$tracking} WHERE rule_id = %d AND user_id = %d",
                $rule_id, $user_id
            ) );
            if ( $exists ) {
                $this->wpdb->query( $this->wpdb->prepare(
                    "UPDATE {$tracking} SET usage_count = usage_count + 1 WHERE rule_id = %d AND user_id = %d",
                    $rule_id, $user_id
                ) );
            } else {
                $this->wpdb->insert( $tracking, ['rule_id'=>$rule_id,'user_id'=>$user_id,'usage_count'=>1] );
            }
        }
    }

    private function prepare_row( array $data ): array {
        return [
            'name'                 => sanitize_text_field( $data['name']        ?? '' ),
            'rule_group'           => sanitize_key( $data['rule_group']         ?? 'cart' ),
            'status'               => (int)( $data['status']                    ?? 1 ),
            'priority'             => (int)( $data['priority']                  ?? 10 ),
            'stop_further_rules'   => (int)( $data['stop_further_rules']        ?? 0 ),
            'discount_type'        => sanitize_key( $data['discount_type']      ?? '' ),
            'discount_data'        => wp_json_encode( $data['discount_data']    ?? [] ),
            'filter_data'          => wp_json_encode( $data['filter_data']      ?? [] ),
            'condition_data'       => wp_json_encode( $data['condition_data']   ?? [] ),
            'bxgy_data'            => wp_json_encode( $data['bxgy_data']        ?? [] ),
            'usage_limit_per_rule' => isset( $data['usage_limit_per_rule'] ) && $data['usage_limit_per_rule'] !== null
                                        ? (int) $data['usage_limit_per_rule'] : null,
            'usage_limit_per_user' => isset( $data['usage_limit_per_user'] ) && $data['usage_limit_per_user'] !== null
                                        ? (int) $data['usage_limit_per_user'] : null,
            'start_date'           => ! empty( $data['start_date'] ) ? $data['start_date'] : null,
            'end_date'             => ! empty( $data['end_date'] )   ? $data['end_date'] : null,
            'created_by'           => (int)( $data['created_by'] ?? get_current_user_id() ),
        ];
    }
}
