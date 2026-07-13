<?php
namespace WooDynamicDiscountRulesMaster\ImportExport;

use WooDynamicDiscountRulesMaster\Repository\RuleRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

class ImportExportService {

    /**
     * @var RuleRepository
     */
    private $repo;

    public function __construct() {
        $this->repo = new RuleRepository();
    }

    /**
     * Create a backup option of all current rules.
     */
    public function create_backup(): bool {
        $rules = $this->repo->get_all();
        return update_option( 'wddrm_rules_backup', $rules );
    }

    /**
     * Rollback rules from the saved backup option.
     */
    public function rollback_from_backup(): bool {
        $backup = get_option( 'wddrm_rules_backup' );
        if ( ! is_array( $backup ) ) return false;

        // Clear existing rules
        $existing = $this->repo->get_all();
        foreach ( $existing as $r ) {
            $this->repo->delete( (int)$r['id'] );
        }

        // Restore backup rules
        foreach ( $backup as $data ) {
            // Unset ID so it inserts clean or maps
            unset($data['id']);
            // decode json fields so repository can prepare them correctly
            $data['discount_data'] = json_decode($data['discount_data'], true) ?: [];
            $data['filter_data']   = json_decode($data['filter_data'], true) ?: [];
            $data['condition_data'] = json_decode($data['condition_data'], true) ?: [];
            $data['bxgy_data']      = json_decode($data['bxgy_data'], true) ?: [];

            $this->repo->save( $data );
        }
        return true;
    }

    /**
     * Compile selected rules into a JSON string payload.
     */
    public function export_to_json( array $rule_ids ): string {
        $all = $this->repo->get_all();
        $filtered = [];
        foreach ( $all as $r ) {
            if ( empty( $rule_ids ) || in_array( (int)$r['id'], $rule_ids, true ) ) {
                // Decode JSON values for cleaner exported representations
                $r['discount_data']  = json_decode( $r['discount_data'], true ) ?: [];
                $r['filter_data']    = json_decode( $r['filter_data'], true ) ?: [];
                $r['condition_data'] = json_decode( $r['condition_data'], true ) ?: [];
                $r['bxgy_data']      = json_decode( $r['bxgy_data'], true ) ?: [];
                $filtered[] = $r;
            }
        }
        return wp_json_encode( $filtered );
    }

    /**
     * Compile selected rules into a CSV string.
     */
    public function export_to_csv( array $rule_ids ): string {
        $all = $this->repo->get_all();
        $headers = [
            'name', 'rule_group', 'status', 'priority', 'stop_further_rules',
            'discount_type', 'discount_data', 'filter_data', 'condition_data',
            'bxgy_data', 'usage_limit_per_rule', 'usage_limit_per_user', 'start_date', 'end_date'
        ];

        $csv = fopen( 'php://temp', 'r+' );
        fputcsv( $csv, $headers );

        $clean = function($val) {
            if ( is_string($val) && in_array(substr($val, 0, 1), ['=', '+', '-', '@'], true) ) {
                return "'" . $val;
            }
            return $val;
        };

        foreach ( $all as $r ) {
            if ( empty( $rule_ids ) || in_array( (int)$r['id'], $rule_ids, true ) ) {
                $row = [];
                foreach ( $headers as $h ) {
                    $row[] = $clean($r[$h] ?? '');
                }
                fputcsv( $csv, $row );
            }
        }

        rewind( $csv );
        $content = stream_get_contents( $csv );
        fclose( $csv );
        return $content;
    }

    /**
     * Parse raw CSV back into an array of rules.
     */
    public function parse_csv_string( string $csv_content ): array {
        $lines = str_getcsv( $csv_content, "\n" );
        if ( empty($lines) ) return [];

        $headers = str_getcsv( array_shift($lines) );
        $rules = [];

        foreach ( $lines as $line ) {
            if ( empty(trim($line)) ) continue;
            $row = str_getcsv($line);
            if ( count($row) !== count($headers) ) continue;
            
            $rule = array_combine( $headers, $row );
            
            // Decode serialized options
            $rule['status']             = (int)$rule['status'];
            $rule['priority']           = (int)$rule['priority'];
            $rule['stop_further_rules'] = (int)$rule['stop_further_rules'];
            $rule['discount_data']      = json_decode( $rule['discount_data'], true ) ?: [];
            $rule['filter_data']        = json_decode( $rule['filter_data'], true ) ?: [];
            $rule['condition_data']     = json_decode( $rule['condition_data'], true ) ?: [];
            $rule['bxgy_data']          = json_decode( $rule['bxgy_data'], true ) ?: [];

            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Validate rules schema and detect duplication/priority conflicts.
     */
    public function analyze_import( array $imported_rules ): array {
        $existing = $this->repo->get_all();
        $existing_names = array_column( $existing, 'name' );
        $existing_priorities = array_column( $existing, 'priority' );

        $analyzed = [];

        foreach ( $imported_rules as $idx => $r ) {
            $name = sanitize_text_field( $r['name'] ?? '' );
            $type = sanitize_key( $r['discount_type'] ?? '' );

            if ( empty($name) || empty($type) ) {
                $analyzed[] = [
                    'valid'     => false,
                    'rule'      => $r,
                    'conflict'  => 'missing_fields',
                    'message'   => 'Missing Name or Discount Type.'
                ];
                continue;
            }

            $conflict = 'none';
            $message  = 'Safe to import.';

            // Conflict detection
            if ( in_array( $name, $existing_names, true ) ) {
                $conflict = 'duplicate_name';
                $message  = 'A rule with this name already exists.';
            } elseif ( in_array( (int)($r['priority'] ?? 10), $existing_priorities, true ) ) {
                $conflict = 'priority_overlap';
                $message  = 'Overlaps with an existing rule priority.';
            }

            $analyzed[] = [
                'valid'    => true,
                'rule'     => $r,
                'conflict' => $conflict,
                'message'  => $message
            ];
        }

        return $analyzed;
    }

    /**
     * Execute final rule imports.
     */
    public function execute_import( array $rules, string $conflict_strategy ): int {
        $imported_count = 0;
        $existing = $this->repo->get_all();

        foreach ( $rules as $r ) {
            $name = sanitize_text_field( $r['name'] ?? '' );
            
            // Check if name already exists
            $match = null;
            foreach ( $existing as $e ) {
                if ( $e['name'] === $name ) {
                    $match = $e;
                    break;
                }
            }

            if ( $match ) {
                if ( $conflict_strategy === 'skip' ) {
                    continue; // skip importing this rule
                } elseif ( $conflict_strategy === 'overwrite' ) {
                    $r['id'] = (int)$match['id']; // map to overwrite
                } else {
                    // rename
                    $r['name'] = $name . ' (Imported ' . date('H:i') . ')';
                    unset($r['id']);
                }
            } else {
                unset($r['id']); // insert as new
            }

            $this->repo->save( $r );
            $imported_count++;
        }

        return $imported_count;
    }
}
