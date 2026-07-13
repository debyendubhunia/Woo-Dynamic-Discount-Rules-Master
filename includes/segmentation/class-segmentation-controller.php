<?php
namespace WooDynamicDiscountRulesMaster\Segmentation;

if ( ! defined( 'ABSPATH' ) ) exit;

class SegmentationController {

    /**
     * @var SegmentRepository
     */
    private $repo;

    /**
     * @var SegmentationEngine
     */
    private $engine;

    public function __construct() {
        $this->repo = new SegmentRepository();
        $this->engine = new SegmentationEngine();
    }

    public function init() {
        add_action( 'wp_ajax_wddrm_preview_segment', [$this, 'ajax_preview_segment'] );
        add_action( 'wp_ajax_wddrm_save_segment',    [$this, 'ajax_save_segment'] );
        add_action( 'wp_ajax_wddrm_delete_segment',  [$this, 'ajax_delete_segment'] );
        add_action( 'admin_post_wddrm_export_segment', [$this, 'handle_csv_export'] );
    }

    /**
     * Parse and filter request rules from AJAX payload.
     */
    private function parse_request_rules(): array {
        return [
            'segment_type'       => sanitize_key( $_POST['rules']['segment_type'] ?? 'all' ),
            'min_spend'          => (float) ($_POST['rules']['min_spend'] ?? 500),
            'inactive_days'      => (int) ($_POST['rules']['inactive_days'] ?? 90),
            'min_orders'         => (int) ($_POST['rules']['min_orders'] ?? 5),
            'country'            => sanitize_text_field( $_POST['rules']['country'] ?? '' ),
            'order_count_min'    => $_POST['rules']['order_count_min'] !== '' ? (int)$_POST['rules']['order_count_min'] : '',
            'order_count_max'    => $_POST['rules']['order_count_max'] !== '' ? (int)$_POST['rules']['order_count_max'] : '',
            'total_spend_min'    => $_POST['rules']['total_spend_min'] !== '' ? (float)$_POST['rules']['total_spend_min'] : '',
            'total_spend_max'    => $_POST['rules']['total_spend_max'] !== '' ? (float)$_POST['rules']['total_spend_max'] : '',
            'bought_product_ids' => ! empty($_POST['rules']['bought_product_ids']) ? array_filter(array_map('absint', (array)$_POST['rules']['bought_product_ids'])) : [],
        ];
    }

    /**
     * AJAX preview generator.
     */
    public function ajax_preview_segment() {
        check_ajax_referer( 'wddrm_segmentation_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $rules = $this->parse_request_rules();
        $customers = $this->engine->query_customers( $rules );

        wp_send_json_success([
            'count'     => count($customers),
            'customers' => array_slice($customers, 0, 150) // preview first 150 only
        ]);
    }

    /**
     * AJAX save segment logic.
     */
    public function ajax_save_segment() {
        check_ajax_referer( 'wddrm_segmentation_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $name = sanitize_text_field( $_POST['name'] ?? '' );
        if ( empty($name) ) {
            wp_send_json_error( 'Segment name is required' );
        }

        $id = absint( $_POST['segment_id'] ?? 0 );
        $rules = $this->parse_request_rules();

        $saved_id = $this->repo->save([
            'id'    => $id,
            'name'  => $name,
            'rules' => $rules
        ]);

        wp_send_json_success([
            'message'    => 'Segment saved successfully.',
            'segment_id' => $saved_id
        ]);
    }

    /**
     * AJAX delete segment.
     */
    public function ajax_delete_segment() {
        check_ajax_referer( 'wddrm_segmentation_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $id = absint( $_POST['segment_id'] ?? 0 );
        if ( $id ) {
            $this->repo->delete( $id );
        }

        wp_send_json_success( 'Segment deleted successfully' );
    }

    /**
     * Export segment matching customers to CSV.
     */
    public function handle_csv_export() {
        check_admin_referer( 'wddrm_export_segment_action', 'wddrm_export_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden' );
        }

        // Get rules from standard POST request
        $rules = [
            'segment_type'       => sanitize_key( $_POST['rules']['segment_type'] ?? 'all' ),
            'min_spend'          => (float) ($_POST['rules']['min_spend'] ?? 500),
            'inactive_days'      => (int) ($_POST['rules']['inactive_days'] ?? 90),
            'min_orders'         => (int) ($_POST['rules']['min_orders'] ?? 5),
            'country'            => sanitize_text_field( $_POST['rules']['country'] ?? '' ),
            'order_count_min'    => $_POST['rules']['order_count_min'] !== '' ? (int)$_POST['rules']['order_count_min'] : '',
            'order_count_max'    => $_POST['rules']['order_count_max'] !== '' ? (int)$_POST['rules']['order_count_max'] : '',
            'total_spend_min'    => $_POST['rules']['total_spend_min'] !== '' ? (float)$_POST['rules']['total_spend_min'] : '',
            'total_spend_max'    => $_POST['rules']['total_spend_max'] !== '' ? (float)$_POST['rules']['total_spend_max'] : '',
            'bought_product_ids' => ! empty($_POST['rules']['bought_product_ids']) ? array_filter(array_map('absint', explode(',', $_POST['rules']['bought_product_ids']))) : [],
        ];

        $customers = $this->engine->query_customers( $rules );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=customer-segment-' . date('Y-m-d') . '.csv' );

        $output = fopen( 'php://output', 'w' );
        
        $clean = function($val) {
            if ( is_string($val) && in_array(substr($val, 0, 1), ['=', '+', '-', '@'], true) ) {
                return "'" . $val;
            }
            return $val;
        };

        fputcsv( $output, [ 'User ID', 'Username', 'Email', 'Order Count', 'Total Spent', 'Country' ] );
        foreach ( $customers as $c ) {
            fputcsv( $output, [
                $clean($c['ID']),
                $clean($c['user_login']),
                $clean($c['user_email']),
                $clean($c['order_count']),
                $clean($c['total_spent']),
                $clean($c['country']),
            ]);
        }

        fclose( $output );
        exit;
    }

    /**
     * Render UI Segment Builder.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'woo-dynamic-discount-rules-master' ) );
        }

        $self = new self();
        $segments = $self->repo->get_all();
        $nonce = wp_create_nonce( 'wddrm_segmentation_nonce' );
        $export_nonce = wp_create_nonce( 'wddrm_export_segment_action' );
        ?>
        <style>
            .wddrm-seg-container {
                max-width: 1200px;
                margin: 20px 20px 0 0;
                font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: #e2e8f0;
            }
            .wddrm-seg-header {
                background: linear-gradient(135deg, #1e293b, #0f172a);
                padding: 24px;
                border-radius: 16px;
                border: 1px solid #334155;
                margin-bottom: 24px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            }
            .wddrm-seg-header h1 {
                margin: 0;
                font-size: 28px;
                color: #ffffff;
            }
            .wddrm-seg-header p {
                margin: 4px 0 0 0;
                color: #94a3b8;
            }
            .wddrm-seg-cols {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
            }
            @media(max-width: 900px) {
                .wddrm-seg-cols {
                    grid-template-columns: 1fr;
                }
            }
            .wddrm-seg-card {
                background: #1e293b;
                border-radius: 16px;
                border: 1px solid #334155;
                padding: 24px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            .wddrm-seg-card h2 {
                margin: 0 0 16px 0;
                font-size: 20px;
                color: #ffffff;
                border-bottom: 1px solid #334155;
                padding-bottom: 12px;
            }
            .wddrm-seg-form-group {
                margin-bottom: 16px;
            }
            .wddrm-seg-form-group label {
                display: block;
                font-weight: 600;
                color: #cbd5e1;
                margin-bottom: 6px;
            }
            .wddrm-seg-input {
                width: 100%;
                background: #0f172a;
                border: 1px solid #475569;
                color: #ffffff;
                padding: 10px 12px;
                border-radius: 8px;
                outline: none;
            }
            .wddrm-seg-select {
                width: 100%;
                background: #0f172a;
                border: 1px solid #475569;
                color: #ffffff;
                padding: 10px 12px;
                border-radius: 8px;
                outline: none;
                height: 40px;
            }
            .wddrm-seg-btn {
                background: linear-gradient(135deg, #a855f7, #9333ea);
                color: #ffffff;
                border: none;
                padding: 12px 20px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                transition: transform 0.2s, opacity 0.2s;
            }
            .wddrm-seg-btn:hover {
                transform: translateY(-1px);
                opacity: 0.95;
            }
            .wddrm-seg-btn-outline {
                background: transparent;
                border: 1px solid #475569;
                color: #cbd5e1;
                padding: 12px 20px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
            }
            .wddrm-seg-btn-outline:hover {
                background: #334155;
                color: #ffffff;
            }
            .wddrm-seg-rule-details {
                margin-top: 16px;
                background: #0f172a;
                padding: 16px;
                border-radius: 8px;
                border: 1px solid #334155;
            }
            .wddrm-seg-details-row {
                display: none;
            }
            .wddrm-seg-details-row.active {
                display: block;
            }
            .wddrm-seg-flex {
                display: flex;
                gap: 12px;
                margin-top: 16px;
            }
            .wddrm-seg-table {
                width: 100%;
                border-collapse: collapse;
            }
            .wddrm-seg-table th {
                padding: 10px;
                border-bottom: 2px solid #334155;
                text-align: left;
                color: #94a3b8;
            }
            .wddrm-seg-table td {
                padding: 10px;
                border-bottom: 1px solid #334155;
                color: #cbd5e1;
            }
            .wddrm-seg-table tr:last-child td {
                border-bottom: none;
            }
            .wddrm-saved-list {
                margin-top: 24px;
                background: #1e293b;
                border-radius: 16px;
                padding: 24px;
                border: 1px solid #334155;
            }
        </style>

        <div class="wddrm-seg-container">
            <div class="wddrm-seg-header">
                <h1>Customer Segmentation Builder</h1>
                <p>Create targeted customer cohorts using shopping habits, spend value, and geographical data.</p>
            </div>

            <div class="wddrm-seg-cols">
                <!-- Left: Rule Config -->
                <div class="wddrm-seg-card">
                    <h2>Segment Logic Settings</h2>
                    
                    <div class="wddrm-seg-form-group">
                        <label for="seg-name">Segment Name</label>
                        <input type="text" id="seg-name" class="wddrm-seg-input" placeholder="e.g. Inactive VIP High Spenders">
                    </div>

                    <div class="wddrm-seg-form-group">
                        <label for="seg-type">Primary Filter Rule</label>
                        <select id="seg-type" class="wddrm-seg-select">
                            <option value="all">All Registered Customers</option>
                            <option value="first_time">First-Time Customers (0 or 1 Orders)</option>
                            <option value="returning">Returning Customers (More than 1 Order)</option>
                            <option value="high_spender">VIP / High Spenders (Threshold Spend)</option>
                            <option value="inactive">Inactive Customers (No purchase in X Days)</option>
                            <option value="frequent">Frequent Buyers (Orders count >= limit)</option>
                            <option value="location">Geographical Location (Billing Country)</option>
                        </select>
                    </div>

                    <!-- Dynamic Details Rows -->
                    <div class="wddrm-seg-rule-details">
                        <!-- Spender -->
                        <div class="wddrm-seg-details-row" id="row-high_spender">
                            <div class="wddrm-seg-form-group" style="margin:0">
                                <label>Min Spend Amount (<?php echo esc_html(get_woocommerce_currency_symbol()); ?>)</label>
                                <input type="number" id="seg-min-spend" class="wddrm-seg-input" value="500" min="0">
                            </div>
                        </div>

                        <!-- Inactive -->
                        <div class="wddrm-seg-details-row" id="row-inactive">
                            <div class="wddrm-seg-form-group" style="margin:0">
                                <label>No purchases in last (Days)</label>
                                <input type="number" id="seg-inactive-days" class="wddrm-seg-input" value="90" min="1">
                            </div>
                        </div>

                        <!-- Frequent -->
                        <div class="wddrm-seg-details-row" id="row-frequent">
                            <div class="wddrm-seg-form-group" style="margin:0">
                                <label>Min Orders Count</label>
                                <input type="number" id="seg-min-orders" class="wddrm-seg-input" value="5" min="1">
                            </div>
                        </div>

                        <!-- Location -->
                        <div class="wddrm-seg-details-row" id="row-location">
                            <div class="wddrm-seg-form-group" style="margin:0">
                                <label>Billing Country Code (2 letters)</label>
                                <input type="text" id="seg-country" class="wddrm-seg-input" placeholder="e.g. US" maxlength="2">
                            </div>
                        </div>

                        <div class="wddrm-seg-details-row" id="row-all" style="display:block">
                            <p style="margin:0;color:#64748b;font-size:13px">Filtering all active registered store users.</p>
                        </div>
                    </div>

                    <h3 style="color:#ffffff;margin-top:20px;font-size:15px">Optional Range Rules</h3>
                    <div style="display:flex;gap:12px;margin-bottom:12px;">
                        <div style="flex:1">
                            <label style="font-size:12px;color:#94a3b8">Min Orders</label>
                            <input type="number" id="seg-order-min" class="wddrm-seg-input" placeholder="0">
                        </div>
                        <div style="flex:1">
                            <label style="font-size:12px;color:#94a3b8">Max Orders</label>
                            <input type="number" id="seg-order-max" class="wddrm-seg-input" placeholder="unlimited">
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;margin-bottom:16px;">
                        <div style="flex:1">
                            <label style="font-size:12px;color:#94a3b8">Min Spend</label>
                            <input type="number" id="seg-spend-min" class="wddrm-seg-input" placeholder="0">
                        </div>
                        <div style="flex:1">
                            <label style="font-size:12px;color:#94a3b8">Max Spend</label>
                            <input type="number" id="seg-spend-max" class="wddrm-seg-input" placeholder="unlimited">
                        </div>
                    </div>

                    <div class="wddrm-seg-form-group">
                        <label>Bought Specific Products (Comma-separated IDs)</label>
                        <input type="text" id="seg-products" class="wddrm-seg-input" placeholder="e.g. 101, 102">
                    </div>

                    <div class="wddrm-seg-flex">
                        <button class="wddrm-seg-btn" id="wddrm-preview-btn">Preview Cohort</button>
                        <button class="wddrm-seg-btn-outline" id="wddrm-save-btn">Save Segment</button>
                    </div>
                </div>

                <!-- Right: Preview Table -->
                <div class="wddrm-seg-card" style="display:flex; flex-direction:column; max-height: 520px; overflow:hidden">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px">
                        <h2 style="border-bottom:none; margin:0; padding:0">Live Preview Output</h2>
                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin:0">
                            <?php wp_nonce_field( 'wddrm_export_segment_action', 'wddrm_export_nonce' ); ?>
                            <input type="hidden" name="action" value="wddrm_export_segment">
                            <input type="hidden" name="rules[segment_type]" id="exp-seg-type">
                            <input type="hidden" name="rules[min_spend]" id="exp-min-spend">
                            <input type="hidden" name="rules[inactive_days]" id="exp-inactive-days">
                            <input type="hidden" name="rules[min_orders]" id="exp-min-orders">
                            <input type="hidden" name="rules[country]" id="exp-country">
                            <input type="hidden" name="rules[order_count_min]" id="exp-order-min">
                            <input type="hidden" name="rules[order_count_max]" id="exp-order-max">
                            <input type="hidden" name="rules[total_spend_min]" id="exp-spend-min">
                            <input type="hidden" name="rules[total_spend_max]" id="exp-spend-max">
                            <input type="hidden" name="rules[bought_product_ids]" id="exp-products">
                            <button type="submit" class="wddrm-seg-btn-outline" style="padding: 6px 12px; font-size:12px" id="wddrm-csv-export-btn" disabled>Export CSV</button>
                        </form>
                    </div>
                    
                    <div style="flex-grow:1; overflow-y:auto; border: 1px solid #334155; border-radius:8px;">
                        <table class="wddrm-seg-table" id="seg-preview-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Orders</th>
                                    <th>Spend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5" style="text-align:center; color:#64748b; padding:24px">Click "Preview Cohort" to query target subscribers list.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:12px; font-size:13px; color:#94a3b8" id="val-cohort-size">Cohort Size: 0 Customers found</div>
                </div>
            </div>

            <!-- Saved list -->
            <div class="wddrm-saved-list">
                <h2 style="margin: 0 0 16px 0; font-size:20px; color:#ffffff">Saved Segments</h2>
                <table class="wddrm-seg-table" id="saved-segments-table">
                    <thead>
                        <tr>
                            <th>Segment Name</th>
                            <th>Rules Summary</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty($segments) ) : ?>
                            <tr>
                                <td colspan="3" style="text-align:center; color:#64748b; padding:16px">No segments built yet.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $segments as $seg ) : 
                                $r = json_decode($seg['rules'], true);
                                $summary = 'Primary: ' . ($r['segment_type'] ?? 'All');
                                if ( !empty($r['bought_product_ids']) ) $summary .= ', bought products (' . implode(',', $r['bought_product_ids']) . ')';
                            ?>
                                <tr data-id="<?php echo absint($seg['id']); ?>">
                                    <td><strong><?php echo esc_html($seg['name']); ?></strong></td>
                                    <td><code style="background:#0f172a;padding:4px 8px;border-radius:4px;color:#cbd5e1;font-size:12px"><?php echo esc_html($summary); ?></code></td>
                                    <td>
                                        <button class="button button-small wddrm-load-saved" data-rules='<?php echo esc_attr($seg['rules']); ?>' data-name="<?php echo esc_attr($seg['name']); ?>">Load</button>
                                        <button class="button button-small wddrm-delete-saved" style="color:#f43f5e" data-id="<?php echo absint($seg['id']); ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <script>
            jQuery(document).ready(function($) {
                var security = '<?php echo esc_js( $nonce ); ?>';
                var activeId = 0;

                // Sync toggle filters UI view
                $('#seg-type').on('change', function() {
                    var val = $(this).val();
                    $('.wddrm-seg-details-row').hide();
                    var $activeRow = $('#row-' + val);
                    if ($activeRow.length) {
                        $activeRow.show();
                    } else {
                        $('#row-all').show();
                    }
                });

                function getRules() {
                    var prodsRaw = $.trim($('#seg-products').val());
                    var productIds = prodsRaw ? prodsRaw.split(',').map(function(id) { return parseInt($.trim(id), 10); }) : [];

                    return {
                        segment_type: $('#seg-type').val(),
                        min_spend: parseFloat($('#seg-min-spend').val()) || 0,
                        inactive_days: parseInt($('#seg-inactive-days').val(), 10) || 0,
                        min_orders: parseInt($('#seg-min-orders').val(), 10) || 0,
                        country: $.trim($('#seg-country').val()),
                        order_count_min: $('#seg-order-min').val(),
                        order_count_max: $('#seg-order-max').val(),
                        total_spend_min: $('#seg-spend-min').val(),
                        total_spend_max: $('#seg-spend-max').val(),
                        bought_product_ids: productIds
                    };
                }

                function syncExportForm(rules) {
                    $('#exp-seg-type').val(rules.segment_type);
                    $('#exp-min-spend').val(rules.min_spend);
                    $('#exp-inactive-days').val(rules.inactive_days);
                    $('#exp-min-orders').val(rules.min_orders);
                    $('#exp-country').val(rules.country);
                    $('#exp-order-min').val(rules.order_count_min);
                    $('#exp-order-max').val(rules.order_count_max);
                    $('#exp-spend-min').val(rules.total_spend_min);
                    $('#exp-spend-max').val(rules.total_spend_max);
                    $('#exp-products').val(rules.bought_product_ids.join(','));
                }

                $('#wddrm-preview-btn').on('click', function(e) {
                    e.preventDefault();
                    var rules = getRules();
                    syncExportForm(rules);

                    $('#seg-preview-table tbody').html('<tr><td colspan="5" style="text-align:center;color:#64748b;padding:24px">Running segment query...</td></tr>');
                    $('#wddrm-csv-export-btn').attr('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wddrm_preview_segment',
                            security: security,
                            rules: rules
                        },
                        success: function(response) {
                            if (response.success) {
                                var html = '';
                                if (response.data.customers.length === 0) {
                                    html = '<tr><td colspan="5" style="text-align:center;color:#64748b;padding:24px">No customers matching this segment filter.</td></tr>';
                                    $('#wddrm-csv-export-btn').attr('disabled', true);
                                } else {
                                    $.each(response.data.customers, function(_, c) {
                                        html += '<tr>'
                                            + '<td>' + c.ID + '</td>'
                                            + '<td>' + c.user_login + '</td>'
                                            + '<td>' + c.user_email + '</td>'
                                            + '<td>' + c.order_count + '</td>'
                                            + '<td>$' + parseFloat(c.total_spent).toFixed(2) + '</td>'
                                            + '</tr>';
                                    });
                                    $('#wddrm-csv-export-btn').attr('disabled', false);
                                }
                                $('#seg-preview-table tbody').html(html);
                                $('#val-cohort-size').text('Cohort Size: ' + response.data.count + ' Customer(s) found');
                            } else {
                                alert(response.data);
                            }
                        }
                    });
                });

                $('#wddrm-save-btn').on('click', function(e) {
                    e.preventDefault();
                    var name = $.trim($('#seg-name').val());
                    if (!name) {
                        alert('Please enter a segment name');
                        return;
                    }

                    var rules = getRules();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wddrm_save_segment',
                            security: security,
                            name: name,
                            segment_id: activeId,
                            rules: rules
                        },
                        success: function(response) {
                            if (response.success) {
                                alert(response.data.message);
                                window.location.reload();
                            } else {
                                alert(response.data);
                            }
                        }
                    });
                });

                $(document).on('click', '.wddrm-delete-saved', function(e) {
                    e.preventDefault();
                    if (!confirm('Delete this segment?')) return;
                    var $row = $(this).closest('tr');
                    var id = $(this).data('id');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wddrm_delete_segment',
                            security: security,
                            segment_id: id
                        },
                        success: function(response) {
                            if (response.success) {
                                $row.remove();
                            }
                        }
                    });
                });

                $(document).on('click', '.wddrm-load-saved', function(e) {
                    e.preventDefault();
                    var rules = $(this).data('rules');
                    var name = $(this).data('name');
                    var id = $(this).closest('tr').data('id');

                    activeId = id;
                    $('#seg-name').val(name);
                    $('#seg-type').val(rules.segment_type).trigger('change');
                    $('#seg-min-spend').val(rules.min_spend);
                    $('#seg-inactive-days').val(rules.inactive_days);
                    $('#seg-min-orders').val(rules.min_orders);
                    $('#seg-country').val(rules.country);
                    $('#seg-order-min').val(rules.order_count_min);
                    $('#seg-order-max').val(rules.order_count_max);
                    $('#seg-spend-min').val(rules.total_spend_min);
                    $('#seg-spend-max').val(rules.total_spend_max);
                    $('#seg-products').val((rules.bought_product_ids || []).join(','));

                    $('#wddrm-preview-btn').trigger('click');
                });
            });
        </script>
        <?php
    }
}
