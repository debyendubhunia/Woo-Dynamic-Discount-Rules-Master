<?php
namespace WooDynamicDiscountRulesMaster\Dashboard;

if ( ! defined( 'ABSPATH' ) ) exit;

class DashboardController {

    /**
     * @var AnalyticsRepository
     */
    private $repo;

    public function __construct() {
        $this->repo = new AnalyticsRepository();
    }

    public function init() {
        add_action( 'wp_ajax_wddrm_get_dashboard_data', [$this, 'ajax_get_dashboard_data'] );
        add_action( 'admin_post_wddrm_export_analytics', [$this, 'handle_csv_export'] );
    }

    /**
     * Fetch dates from request or fall back to last 30 days.
     */
    private function get_dates(): array {
        $start = ! empty( $_REQUEST['start_date'] ) ? sanitize_text_field( $_REQUEST['start_date'] ) : date('Y-m-d', strtotime('-30 days', current_time('timestamp')));
        $end   = ! empty( $_REQUEST['end_date'] ) ? sanitize_text_field( $_REQUEST['end_date'] ) : date('Y-m-d', current_time('timestamp'));
        return [$start, $end];
    }

    /**
     * AJAX handler with nonce and permission verification.
     */
    public function ajax_get_dashboard_data() {
        check_ajax_referer( 'wddrm_dashboard_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        list( $start, $end ) = $this->get_dates();

        $metrics   = $this->repo->get_core_metrics( $start, $end );
        $rules     = $this->repo->get_top_performing_rules( $start, $end, 5 );
        $products  = $this->repo->get_product_analytics( $start, $end, 5 );
        $customers = $this->repo->get_customer_usage( $start, $end, 5 );
        $trends    = $this->repo->get_revenue_trends( $start, $end );

        wp_send_json_success([
            'metrics'   => $metrics,
            'rules'     => $rules,
            'products'  => $products,
            'customers' => $customers,
            'trends'    => $trends,
        ]);
    }

    /**
     * Export dashboard metrics to CSV.
     */
    public function handle_csv_export() {
        check_admin_referer( 'wddrm_export_analytics_action', 'wddrm_export_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden' );
        }

        list( $start, $end ) = $this->get_dates();

        $metrics   = $this->repo->get_core_metrics( $start, $end );
        $rules     = $this->repo->get_top_performing_rules( $start, $end, 20 );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=discount-rules-analytics-' . $start . '-to-' . $end . '.csv' );

        $output = fopen( 'php://output', 'w' );
        
        // Escape helper for CSV Formula Injection
        $clean = function($val) {
            if ( is_string($val) && in_array(substr($val, 0, 1), ['=', '+', '-', '@'], true) ) {
                return "'" . $val;
            }
            return $val;
        };

        // Header and Summary
        fputcsv( $output, [ 'Woo Dynamic Discount Rules Master - Performance Report' ] );
        fputcsv( $output, [ 'Period', $start . ' to ' . $end ] );
        fputcsv( $output, [] );

        fputcsv( $output, [ 'Core Metrics' ] );
        fputcsv( $output, [ 'Metric', 'Value' ] );
        fputcsv( $output, [ 'Orders with Discounts', $clean($metrics['orders_count']) ] );
        $cur = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
        fputcsv( $output, [ 'Total Discounts Given (' . $cur . ')', $clean($metrics['discount_given']) ] );
        fputcsv( $output, [ 'Net Revenue Impact (' . $cur . ')', $clean($metrics['revenue_impact']) ] );
        fputcsv( $output, [ 'Original Value (' . $cur . ')', $clean($metrics['original_spend']) ] );
        fputcsv( $output, [] );

        // Top Rules
        fputcsv( $output, [ 'Top Performing Discount Rules' ] );
        fputcsv( $output, [ 'Rule ID', 'Rule Name', 'Discount Type', 'Usages', 'Total Discount Given' ] );
        foreach ( $rules as $r ) {
            fputcsv( $output, [
                $clean($r['rule_id']),
                $clean($r['rule_name']),
                $clean($r['discount_type']),
                $clean($r['usages']),
                $clean($r['discount_given']),
            ]);
        }

        fclose( $output );
        exit;
    }

    /**
     * Render the Dashboard Interface.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'woo-dynamic-discount-rules-master' ) );
        }

        $self = new self();
        $counts = $self->repo->get_rule_counts();
        $nonce = wp_create_nonce( 'wddrm_dashboard_nonce' );
        $export_nonce = wp_create_nonce( 'wddrm_export_analytics_action' );
        $cur = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
        ?>
        <style>
            .wddrm-db-container {
                max-width: 1200px;
                margin: 20px 20px 0 0;
                font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                color: #e2e8f0;
            }
            .wddrm-db-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 24px;
                background: linear-gradient(135deg, #1e293b, #0f172a);
                padding: 24px;
                border-radius: 16px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                border: 1px solid #334155;
            }
            .wddrm-db-header h1 {
                margin: 0;
                font-size: 28px;
                font-weight: 700;
                color: #ffffff;
                letter-spacing: -0.5px;
            }
            .wddrm-db-header p {
                margin: 4px 0 0 0;
                color: #94a3b8;
            }
            .wddrm-filters {
                display: flex;
                gap: 12px;
                align-items: center;
            }
            .wddrm-input {
                background: #1e293b;
                border: 1px solid #475569;
                color: #ffffff;
                padding: 8px 12px;
                border-radius: 8px;
                outline: none;
            }
            .wddrm-btn {
                background: linear-gradient(135deg, #06b6d4, #0891b2);
                color: #ffffff;
                border: none;
                padding: 10px 18px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                transition: transform 0.2s, opacity 0.2s;
            }
            .wddrm-btn:hover {
                transform: translateY(-1px);
                opacity: 0.95;
            }
            .wddrm-btn-secondary {
                background: #334155;
                color: #ffffff;
                border: 1px solid #475569;
                padding: 10px 18px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                text-decoration: none;
                display: inline-block;
            }
            .wddrm-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 20px;
                margin-bottom: 24px;
            }
            .wddrm-card {
                background: #1e293b;
                border-radius: 16px;
                padding: 24px;
                border: 1px solid #334155;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                transition: transform 0.3s ease;
            }
            .wddrm-card:hover {
                transform: translateY(-2px);
            }
            .wddrm-card-title {
                font-size: 14px;
                color: #94a3b8;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-bottom: 8px;
                font-weight: 600;
            }
            .wddrm-card-value {
                font-size: 32px;
                font-weight: 700;
                color: #ffffff;
            }
            .wddrm-card-sub {
                margin-top: 8px;
                font-size: 13px;
                color: #10b981;
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .wddrm-charts-row {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 20px;
                margin-bottom: 24px;
            }
            @media (max-width: 900px) {
                .wddrm-charts-row {
                    grid-template-columns: 1fr;
                }
            }
            .wddrm-chart-container {
                background: #1e293b;
                border-radius: 16px;
                padding: 24px;
                border: 1px solid #334155;
                height: 360px;
                display: flex;
                flex-direction: column;
            }
            .wddrm-chart-svg {
                flex-grow: 1;
                width: 100%;
                height: 100%;
            }
            .wddrm-table-card {
                background: #1e293b;
                border-radius: 16px;
                padding: 24px;
                border: 1px solid #334155;
            }
            .wddrm-table-card h2 {
                margin: 0 0 16px 0;
                font-size: 18px;
                color: #ffffff;
            }
            .wddrm-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
            }
            .wddrm-table th {
                padding: 12px;
                border-bottom: 2px solid #334155;
                color: #94a3b8;
                font-weight: 600;
            }
            .wddrm-table td {
                padding: 12px;
                border-bottom: 1px solid #334155;
                color: #cbd5e1;
            }
            .wddrm-table tr:last-child td {
                border-bottom: none;
            }
            .wddrm-loader {
                border: 3px solid #334155;
                border-top: 3px solid #06b6d4;
                border-radius: 50%;
                width: 24px;
                height: 24px;
                animation: spin 1s linear infinite;
                display: none;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>

        <div class="wddrm-db-container">
            <div class="wddrm-db-header">
                <div>
                    <h1>Dashboard & Analytics</h1>
                    <p>Live analysis of dynamic discounts performance.</p>
                </div>
                <div class="wddrm-filters">
                    <div class="wddrm-loader" id="wddrm-db-loader"></div>
                    <input type="date" id="wddrm-start-date" class="wddrm-input" value="<?php echo esc_attr( date('Y-m-d', strtotime('-30 days', current_time('timestamp'))) ); ?>">
                    <span style="color:#64748b">to</span>
                    <input type="date" id="wddrm-end-date" class="wddrm-input" value="<?php echo esc_attr( date('Y-m-d', current_time('timestamp')) ); ?>">
                    <button class="wddrm-btn" id="wddrm-filter-btn">Filter</button>
                    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin:0; display:inline">
                        <?php wp_nonce_field( 'wddrm_export_analytics_action', 'wddrm_export_nonce' ); ?>
                        <input type="hidden" name="action" value="wddrm_export_analytics">
                        <input type="hidden" name="start_date" id="wddrm-export-start">
                        <input type="hidden" name="end_date" id="wddrm-export-end">
                        <button type="submit" class="wddrm-btn-secondary" id="wddrm-export-btn">Export Report</button>
                    </form>
                </div>
            </div>

            <!-- Stats grid -->
            <div class="wddrm-grid">
                <div class="wddrm-card">
                    <div class="wddrm-card-title">Campaign Revenue</div>
                    <div class="wddrm-card-value" id="val-revenue"><?php echo esc_html($cur); ?>0.00</div>
                    <div class="wddrm-card-sub">Estimated net revenue</div>
                </div>
                <div class="wddrm-card">
                    <div class="wddrm-card-title">Discounts Distributed</div>
                    <div class="wddrm-card-value" id="val-discounts" style="color: #f43f5e"><?php echo esc_html($cur); ?>0.00</div>
                    <div class="wddrm-card-sub" style="color: #f43f5e">Direct coupon / rule value</div>
                </div>
                <div class="wddrm-card">
                    <div class="wddrm-card-title">Orders Impacted</div>
                    <div class="wddrm-card-value" id="val-orders">0</div>
                    <div class="wddrm-card-sub" style="color: #06b6d4">Orders utilizing active rules</div>
                </div>
                <div class="wddrm-card">
                    <div class="wddrm-card-title">Active Rules Ratio</div>
                    <div class="wddrm-card-value" style="color:#a855f7"><?php echo esc_html($counts['active'] . ' / ' . $counts['total']); ?></div>
                    <div class="wddrm-card-sub" style="color:#a855f7">Active vs total campaigns</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="wddrm-charts-row">
                <!-- Trend line chart -->
                <div class="wddrm-chart-container">
                    <div class="wddrm-card-title" style="margin-bottom: 16px;">Discount Trend History</div>
                    <div style="flex-grow:1; position:relative;" id="trend-chart-wrapper">
                        <svg class="wddrm-chart-svg" id="trend-chart-svg" viewBox="0 0 600 220" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="cyan-gradient" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#06b6d4" stop-opacity="0.4"/>
                                    <stop offset="100%" stop-color="#06b6d4" stop-opacity="0.0"/>
                                </linearGradient>
                            </defs>
                            <g id="chart-grid-lines"></g>
                            <path id="chart-line-path" fill="none" stroke="#06b6d4" stroke-width="3" d=""></path>
                            <path id="chart-area-path" fill="url(#cyan-gradient)" d=""></path>
                            <g id="chart-data-dots"></g>
                        </svg>
                    </div>
                </div>

                <!-- Rule Breakdown List -->
                <div class="wddrm-table-card">
                    <h2>Top Rules Performance</h2>
                    <table class="wddrm-table" id="top-rules-table">
                        <thead>
                            <tr>
                                <th>Rule</th>
                                <th>Usages</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="3" style="text-align:center;color:#64748b">Loading top rules...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Double tables row -->
            <div class="wddrm-charts-row">
                <div class="wddrm-table-card">
                    <h2>Estimated Product Performance</h2>
                    <table class="wddrm-table" id="top-products-table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Sales Count</th>
                                <th>Estimated Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="3" style="text-align:center;color:#64748b">Loading products...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="wddrm-table-card">
                    <h2>Customer Reward Activity</h2>
                    <table class="wddrm-table" id="top-customers-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Orders</th>
                                <th>Discount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="3" style="text-align:center;color:#64748b">Loading customer stats...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <script>
            jQuery(document).ready(function($) {
                var security = '<?php echo esc_js( $nonce ); ?>';
                var currency = '<?php echo esc_js( $cur ); ?>';

                // Initialize start and end dates using the visitor's local browser timezone
                var today = new Date();
                var thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(today.getDate() - 30);

                var formatDate = function(d) {
                    var month = '' + (d.getMonth() + 1),
                        day = '' + d.getDate(),
                        year = d.getFullYear();

                    if (month.length < 2) month = '0' + month;
                    if (day.length < 2) day = '0' + day;

                    return [year, month, day].join('-');
                };

                $('#wddrm-start-date').val(formatDate(thirtyDaysAgo));
                $('#wddrm-end-date').val(formatDate(today));

                function formatCurrency(num) {
                    return currency + parseFloat(num).toFixed(2);
                }

                function fetchDashboardData() {
                    $('#wddrm-db-loader').show();
                    var startDate = $('#wddrm-start-date').val();
                    var endDate = $('#wddrm-end-date').val();

                    // Sync export inputs
                    $('#wddrm-export-start').val(startDate);
                    $('#wddrm-export-end').val(endDate);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wddrm_get_dashboard_data',
                            security: security,
                            start_date: startDate,
                            end_date: endDate
                        },
                        success: function(response) {
                            $('#wddrm-db-loader').hide();
                            if (response.success) {
                                renderStats(response.data);
                            }
                        },
                        error: function() {
                            $('#wddrm-db-loader').hide();
                        }
                    });
                }

                function renderStats(data) {
                    // Core Stats
                    $('#val-revenue').text(formatCurrency(data.metrics.revenue_impact));
                    $('#val-discounts').text(formatCurrency(data.metrics.discount_given));
                    $('#val-orders').text(data.metrics.orders_count);

                    // Top Rules Table
                    var rulesHtml = '';
                    if (data.rules.length === 0) {
                        rulesHtml = '<tr><td colspan="3" style="text-align:center;color:#64748b">No rules recorded in this period.</td></tr>';
                    } else {
                        $.each(data.rules, function(_, item) {
                            rulesHtml += '<tr>'
                                + '<td><strong>' + item.rule_name + '</strong><br><small style="color:#64748b">' + item.discount_type + '</small></td>'
                                + '<td>' + item.usages + '</td>'
                                + '<td style="color:#f43f5e">' + formatCurrency(item.discount_given) + '</td>'
                                + '</tr>';
                        });
                    }
                    $('#top-rules-table tbody').html(rulesHtml);

                    // Top Products Table
                    var productsHtml = '';
                    if (data.products.length === 0) {
                        productsHtml = '<tr><td colspan="3" style="text-align:center;color:#64748b">No product data recorded.</td></tr>';
                    } else {
                        $.each(data.products, function(_, item) {
                            productsHtml += '<tr>'
                                + '<td>' + item.product_name + '</td>'
                                + '<td>' + item.orders_count + '</td>'
                                + '<td style="color:#06b6d4">' + formatCurrency(item.estimated_discount) + '</td>'
                                + '</tr>';
                        });
                    }
                    $('#top-products-table tbody').html(productsHtml);

                    // Top Customers Table
                    var customersHtml = '';
                    if (data.customers.length === 0) {
                        customersHtml = '<tr><td colspan="3" style="text-align:center;color:#64748b">No customer data recorded.</td></tr>';
                    } else {
                        $.each(data.customers, function(_, item) {
                            customersHtml += '<tr>'
                                + '<td><strong>' + item.username + '</strong><br><small style="color:#64748b">' + item.email + '</small></td>'
                                + '<td>' + item.orders_count + '</td>'
                                + '<td style="color:#a855f7">' + formatCurrency(item.discount_received) + '</td>'
                                + '</tr>';
                        });
                    }
                    $('#top-customers-table tbody').html(customersHtml);

                    // Draw Line Trend Chart
                    drawTrendChart(data.trends);
                }

                function drawTrendChart(trends) {
                    var $svg = $('#trend-chart-svg');
                    var $line = $('#chart-line-path');
                    var $area = $('#chart-area-path');
                    var $dots = $('#chart-data-dots');
                    var $grid = $('#chart-grid-lines');

                    $dots.empty();
                    $grid.empty();

                    if (!trends || trends.length === 0) {
                        $line.attr('d', '');
                        $area.attr('d', '');
                        return;
                    }

                    if (trends && trends.length === 1) {
                        var singlePoint = trends[0];
                        var prevDate = new Date(singlePoint.day_date);
                        prevDate.setDate(prevDate.getDate() - 1);
                        var prevDateStr = prevDate.toISOString().split('T')[0];
                        trends.unshift({
                            day_date: prevDateStr,
                            discount: 0,
                            subtotal: 0
                        });
                    }

                    var width = 600;
                    var height = 220;
                    var padding = 20;

                    var maxVal = 100;
                    $.each(trends, function(_, t) {
                        var val = parseFloat(t.discount);
                        if (val > maxVal) maxVal = val;
                    });
                    maxVal = maxVal * 1.1; // 10% breathing room

                    // Render horizontal grid lines
                    for (var i = 1; i <= 4; i++) {
                        var gridY = padding + (height - padding * 2) * (i / 5);
                        $grid.append('<line x1="' + padding + '" y1="' + gridY + '" x2="' + (width - padding) + '" y2="' + gridY + '" stroke="#334155" stroke-dasharray="4,4" />');
                    }

                    var stepX = (width - padding * 2) / Math.max(1, trends.length - 1);
                    var points = [];

                    $.each(trends, function(idx, t) {
                        var x = padding + idx * stepX;
                        var val = parseFloat(t.discount);
                        var y = height - padding - (val / maxVal) * (height - padding * 2);
                        points.push({x: x, y: y, date: t.day_date, val: val});
                    });

                    // Construct path d string
                    var lineD = 'M ' + points[0].x + ' ' + points[0].y;
                    for (var i = 1; i < points.length; i++) {
                        lineD += ' L ' + points[i].x + ' ' + points[i].y;
                    }

                    $line.attr('d', lineD);

                    // Area path closes at baseline
                    var areaD = lineD + ' L ' + points[points.length - 1].x + ' ' + (height - padding) + ' L ' + points[0].x + ' ' + (height - padding) + ' Z';
                    $area.attr('d', areaD);

                    // Append dots with simple tooltips
                    $.each(points, function(_, pt) {
                        var dot = $('<circle cx="' + pt.x + '" cy="' + pt.y + '" r="5" fill="#06b6d4" stroke="#1e293b" stroke-width="2" style="cursor:pointer;" />');
                        dot.attr('title', pt.date + ': ' + formatCurrency(pt.val));
                        $dots.append(dot);
                    });
                }

                $('#wddrm-filter-btn').on('click', fetchDashboardData);
                fetchDashboardData();
            });
        </script>
        <?php
    }
}
