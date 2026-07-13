<?php
namespace WooDynamicDiscountRulesMaster\ImportExport;

use WooDynamicDiscountRulesMaster\Repository\RuleRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

class ImportExportController {

    /**
     * @var ImportExportService
     */
    private $service;

    /**
     * @var RuleRepository
     */
    private $repo;

    public function __construct() {
        $this->service = new ImportExportService();
        $this->repo = new RuleRepository();
    }

    public function init() {
        add_action( 'admin_post_wddrm_export_rules',   [$this, 'handle_export'] );
        add_action( 'admin_post_wddrm_preview_import', [$this, 'handle_preview'] );
        add_action( 'admin_post_wddrm_confirm_import', [$this, 'handle_confirm'] );
        add_action( 'admin_post_wddrm_rollback_rules', [$this, 'handle_rollback'] );
    }

    /**
     * Handle CSV/JSON export actions.
     */
    public function handle_export() {
        check_admin_referer( 'wddrm_ie_action', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden' );
        }

        $ids = array_map( 'absint', $_POST['export_ids'] ?? [] );
        $format = sanitize_key( $_POST['export_format'] ?? 'json' );

        if ( $format === 'csv' ) {
            $content = $this->service->export_to_csv( $ids );
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=discount-rules-export-' . date('Y-m-d') . '.csv' );
        } else {
            $content = $this->service->export_to_json( $ids );
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=discount-rules-export-' . date('Y-m-d') . '.json' );
        }

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Handle uploaded file parsing and display import preview.
     */
    public function handle_preview() {
        check_admin_referer( 'wddrm_ie_action', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden' );
        }

        if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=wddrm-import-export&err=no_file' ) );
            exit;
        }

        $file_path = $_FILES['import_file']['tmp_name'];
        $file_name = $_FILES['import_file']['name'];
        $raw = file_get_contents( $file_path );
        $rules = [];

        if ( strpos( $file_name, '.csv' ) !== false ) {
            $rules = $this->service->parse_csv_string( $raw );
        } else {
            $rules = json_decode( $raw, true ) ?: [];
        }

        if ( empty( $rules ) ) {
            wp_redirect( admin_url( 'admin.php?page=wddrm-import-export&err=invalid_format' ) );
            exit;
        }

        // Cache the parsed rules in a transient for confirmation step
        $analysis = $this->service->analyze_import( $rules );
        set_transient( 'wddrm_import_preview', $analysis, HOUR_IN_SECONDS );

        wp_redirect( admin_url( 'admin.php?page=wddrm-import-export&action=preview' ) );
        exit;
    }

    /**
     * Commit the verified imported rules to the database.
     */
    public function handle_confirm() {
        check_admin_referer( 'wddrm_ie_action', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden' );
        }

        $analysis = get_transient( 'wddrm_import_preview' );
        if ( ! is_array( $analysis ) ) {
            wp_redirect( admin_url( 'admin.php?page=wddrm-import-export&err=expired' ) );
            exit;
        }

        // 1. Automatically generate backup of existing rules before modifying them
        $this->service->create_backup();

        // 2. Select collision strategy
        $strategy = sanitize_key( $_POST['conflict_strategy'] ?? 'skip' );
        $rules_to_import = array_column( $analysis, 'rule' );

        $imported = $this->service->execute_import( $rules_to_import, $strategy );
        delete_transient( 'wddrm_import_preview' );

        wp_redirect( admin_url( 'admin.php?page=wddrm-import-export&msg=imported&cnt=' . $imported ) );
        exit;
    }

    /**
     * Restore database rules state from previous backup.
     */
    public function handle_rollback() {
        check_admin_referer( 'wddrm_ie_action', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden' );
        }

        $restored = $this->service->rollback_from_backup();
        if ( $restored ) {
            wp_redirect( admin_url( 'admin.php?page=wddrm-import-export&msg=rolled_back' ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=wddrm-import-export&err=no_backup' ) );
        }
        exit;
    }

    /**
     * Render the Import/Export UI page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'woo-dynamic-discount-rules-master' ) );
        }

        $self = new self();
        $rules = $self->repo->get_all();
        $nonce = wp_create_nonce( 'wddrm_ie_action' );
        
        $action = sanitize_key( $_GET['action'] ?? 'list' );
        $err = sanitize_key( $_GET['err'] ?? '' );
        $msg = sanitize_key( $_GET['msg'] ?? '' );
        $cnt = absint( $_GET['cnt'] ?? 0 );

        $backup = get_option( 'wddrm_rules_backup' );
        ?>
        <style>
            .wddrm-ie-container {
                max-width: 1200px;
                margin: 20px 20px 0 0;
                font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: #e2e8f0;
            }
            .wddrm-ie-header {
                background: linear-gradient(135deg, #1e293b, #0f172a);
                padding: 24px;
                border-radius: 16px;
                border: 1px solid #334155;
                margin-bottom: 24px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            }
            .wddrm-ie-header h1 { margin: 0; font-size: 28px; color: #ffffff; }
            .wddrm-ie-header p { margin: 4px 0 0 0; color: #94a3b8; }
            .wddrm-ie-cols {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
            }
            @media (max-width: 900px) {
                .wddrm-ie-cols {
                    grid-template-columns: 1fr;
                }
            }
            .wddrm-ie-card {
                background: #1e293b;
                border-radius: 16px;
                border: 1px solid #334155;
                padding: 24px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                margin-bottom: 24px;
            }
            .wddrm-ie-card h2 { margin: 0 0 16px 0; font-size: 20px; color: #ffffff; }
            .wddrm-ie-btn {
                background: linear-gradient(135deg, #06b6d4, #0891b2);
                color: #ffffff;
                border: none;
                padding: 10px 18px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                text-decoration: none;
                display: inline-block;
                transition: transform 0.2s;
            }
            .wddrm-ie-btn:hover { transform: translateY(-1px); }
            .wddrm-ie-btn-outline {
                background: transparent;
                border: 1px solid #475569;
                color: #cbd5e1;
                padding: 10px 18px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                text-decoration: none;
                display: inline-block;
            }
            .wddrm-ie-btn-outline:hover { background: #334155; color: #ffffff; }
            .wddrm-ie-btn-danger {
                background: linear-gradient(135deg, #f43f5e, #e11d48);
                color: #ffffff;
                border: none;
                padding: 10px 18px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                text-decoration: none;
                display: inline-block;
            }
            .wddrm-ie-select {
                background: #0f172a;
                border: 1px solid #475569;
                color: #ffffff;
                padding: 10px;
                border-radius: 8px;
                height: 40px;
                outline: none;
            }
            .wddrm-ie-input {
                background: #0f172a;
                border: 1px solid #475569;
                color: #ffffff;
                padding: 10px;
                border-radius: 8px;
                outline: none;
            }
            .wddrm-table-wrapper {
                max-height: 250px;
                overflow-y: auto;
                border: 1px solid #334155;
                border-radius: 8px;
                margin-bottom: 16px;
            }
            .wddrm-ie-table {
                width: 100%;
                border-collapse: collapse;
            }
            .wddrm-ie-table th { padding:10px; border-bottom:2px solid #334155; color:#94a3b8; text-align:left; }
            .wddrm-ie-table td { padding:10px; border-bottom:1px solid #334155; color:#cbd5e1; }
        </style>

        <div class="wddrm-ie-container">
            <!-- Notices -->
            <?php if ( $err === 'no_file' ) : ?><div class="notice notice-error"><p>Please upload a valid JSON or CSV rules file.</p></div><?php endif; ?>
            <?php if ( $err === 'invalid_format' ) : ?><div class="notice notice-error"><p>The uploaded file format is invalid or has no recognizable discount rules.</p></div><?php endif; ?>
            <?php if ( $err === 'no_backup' ) : ?><div class="notice notice-error"><p>No previous rule database backup found to restore from.</p></div><?php endif; ?>
            <?php if ( $err === 'expired' ) : ?><div class="notice notice-error"><p>Import session expired. Please upload the file again.</p></div><?php endif; ?>

            <?php if ( $msg === 'imported' ) : ?><div class="notice notice-success is-dismissible"><p>Successfully imported <?php echo esc_html($cnt); ?> discount rules. A rollback backup has been automatically created.</p></div><?php endif; ?>
            <?php if ( $msg === 'rolled_back' ) : ?><div class="notice notice-success is-dismissible"><p>Successfully restored rules database from last automatic snapshot.</p></div><?php endif; ?>

            <div class="wddrm-ie-header">
                <h1>Rules Import & Export</h1>
                <p>Transfer campaigns between storefronts safely with duplicate detection and point-in-time recovery rollbacks.</p>
            </div>

            <?php if ( $action === 'preview' ) : 
                $preview = get_transient( 'wddrm_import_preview' );
            ?>
                <!-- Step 2: Verification Preview table -->
                <div class="wddrm-ie-card">
                    <h2>Import Verification Preview</h2>
                    <p style="color:#94a3b8;margin-bottom:16px">Previewing rules parsed from file. Verify overlap conflicts before confirming database commit.</p>

                    <div style="border:1px solid #334155; border-radius:12px; overflow:hidden; margin-bottom:20px">
                        <table class="wddrm-ie-table">
                            <thead>
                                <tr>
                                    <th>Rule Name</th>
                                    <th>Type</th>
                                    <th>Conflict Status</th>
                                    <th>Resolution Estimate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $preview as $item ) : 
                                    $conflict_color = '#10b981';
                                    if ($item['conflict'] === 'duplicate_name') $conflict_color = '#ef4444';
                                    if ($item['conflict'] === 'priority_overlap') $conflict_color = '#f59e0b';
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($item['rule']['name']); ?></strong></td>
                                        <td><?php echo esc_html($item['rule']['discount_type']); ?></td>
                                        <td style="color:<?php echo esc_attr($conflict_color); ?>"><?php echo esc_html($item['message']); ?></td>
                                        <td>
                                            <?php if ($item['conflict'] === 'duplicate_name') : ?>
                                                Requires Conflict Strategy choice below
                                            <?php else: ?>
                                                Insert as new
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                        <?php wp_nonce_field( 'wddrm_ie_action', 'security' ); ?>
                        <input type="hidden" name="action" value="wddrm_confirm_import">
                        
                        <div style="display:flex; gap:16px; align-items:center; background:#0f172a; padding:16px; border-radius:8px; border:1px solid #334155; margin-bottom:20px">
                            <label style="font-weight:600; color:#cbd5e1">Duplicate Resolution Strategy:</label>
                            <select name="conflict_strategy" class="wddrm-ie-select">
                                <option value="skip">Skip duplicates (Recommended)</option>
                                <option value="overwrite">Overwrite existing rule match</option>
                                <option value="rename">Rename imported rule (Add "(Imported)")</option>
                            </select>
                        </div>

                        <div style="display:flex; gap:12px">
                            <button type="submit" class="wddrm-ie-btn">Confirm Import</button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wddrm-import-export')); ?>" class="wddrm-ie-btn-outline">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Step 1: Main Import/Export options layout -->
                <div class="wddrm-ie-cols">
                    <!-- Left: Export Card -->
                    <div class="wddrm-ie-card">
                        <h2>Export Campaigns</h2>
                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                            <?php wp_nonce_field( 'wddrm_ie_action', 'security' ); ?>
                            <input type="hidden" name="action" value="wddrm_export_rules">

                            <p style="color:#94a3b8;margin-bottom:12px">Select rules to include in export file:</p>
                            <div class="wddrm-table-wrapper">
                                <table class="wddrm-ie-table">
                                    <thead>
                                        <tr>
                                            <th style="width:40px"><input type="checkbox" id="wddrm-check-all-export" checked></th>
                                            <th>Rule Name</th>
                                            <th>Group</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($rules)) : ?>
                                            <tr><td colspan="3" style="text-align:center;color:#64748b">No rules to export.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($rules as $r) : ?>
                                                <tr>
                                                    <td><input type="checkbox" name="export_ids[]" value="<?php echo absint($r['id']); ?>" class="wddrm-export-chk" checked></td>
                                                    <td><strong><?php echo esc_html($r['name']); ?></strong></td>
                                                    <td><?php echo esc_html($r['rule_group']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div style="display:flex; gap:12px; align-items:center; margin-top:20px">
                                <select name="export_format" class="wddrm-ie-select">
                                    <option value="json">JSON format</option>
                                    <option value="csv">CSV format</option>
                                </select>
                                <button type="submit" class="wddrm-ie-btn" <?php echo empty($rules) ? 'disabled' : ''; ?>>Generate Export</button>
                            </div>
                        </form>
                    </div>

                    <!-- Right: Import & Backup Cards -->
                    <div>
                        <!-- Import -->
                        <div class="wddrm-ie-card">
                            <h2>Import Campaigns</h2>
                            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" enctype="multipart/form-data">
                                <?php wp_nonce_field( 'wddrm_ie_action', 'security' ); ?>
                                <input type="hidden" name="action" value="wddrm_preview_import">

                                <p style="color:#94a3b8;margin-bottom:16px">Upload a previously generated `.json` or `.csv` discount rules backup file:</p>
                                <div style="margin-bottom:20px">
                                    <input type="file" name="import_file" accept=".json,.csv" required>
                                </div>

                                <button type="submit" class="wddrm-ie-btn">Upload & Preview</button>
                            </form>
                        </div>

                        <!-- Backup rollback -->
                        <div class="wddrm-ie-card" style="border-color: rgba(239,68,68,0.3)">
                            <h2>System Recovery Snapshot</h2>
                            <p style="color:#94a3b8;margin-bottom:16px">If an import causes issues, you can restore all rules to the state recorded prior to the last import sequence.</p>
                            
                            <?php if ( ! empty($backup) ) : ?>
                                <p style="font-size:13px; color:#10b981; margin-bottom:16px">✔ Last backup snapshot is stored and ready to recover.</p>
                                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" onsubmit="return confirm('Restore rules to prior backup snapshot? This will replace all current rules.')">
                                    <?php wp_nonce_field( 'wddrm_ie_action', 'security' ); ?>
                                    <input type="hidden" name="action" value="wddrm_rollback_rules">
                                    <button type="submit" class="wddrm-ie-btn-danger">Restore to Prior Backup</button>
                                </form>
                            <?php else : ?>
                                <p style="font-size:13px; color:#64748b">No recovery snapshot is stored yet.</p>
                                <button class="wddrm-ie-btn-outline" disabled>Restore Unavailable</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#wddrm-check-all-export').on('change', function() {
                    $('.wddrm-export-chk').prop('checked', $(this).prop('checked'));
                });
            });
        </script>
        <?php
    }
}
