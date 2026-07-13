<?php
namespace WooDynamicDiscountRulesMaster\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

class AIController {

    /**
     * @var AIService
     */
    private $service;

    public function __construct() {
        $this->service = new AIService();
    }

    public function init() {
        add_action( 'wp_ajax_wddrm_get_ai_suggestions', [$this, 'ajax_get_suggestions'] );
        add_action( 'admin_post_wddrm_save_ai_settings', [$this, 'handle_save_settings'] );
    }

    /**
     * AJAX fetcher for suggestions with Transient Caching.
     */
    public function ajax_get_suggestions() {
        check_ajax_referer( 'wddrm_ai_nonce', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $force = ! empty( $_POST['force_refresh'] );
        $cache = get_transient( 'wddrm_ai_suggestions' );

        if ( false === $cache || $force ) {
            // Recalculate
            $suggestions = $this->service->get_suggestions();
            set_transient( 'wddrm_ai_suggestions', $suggestions, HOUR_IN_SECONDS * 6 ); // cache for 6 hours
        } else {
            $suggestions = $cache;
        }

        wp_send_json_success( $suggestions );
    }

    /**
     * Save API keys and provider configurations.
     */
    public function handle_save_settings() {
        check_admin_referer( 'wddrm_save_ai_settings_action', 'wddrm_ai_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden' );
        }

        $provider = sanitize_key( $_POST['ai_provider'] ?? 'local' );
        update_option( 'wddrm_ai_provider', $provider );

        if ( isset($_POST['openai_key']) ) {
            update_option( 'wddrm_openai_api_key', sanitize_text_field($_POST['openai_key']) );
        }
        if ( isset($_POST['claude_key']) ) {
            update_option( 'wddrm_claude_api_key', sanitize_text_field($_POST['claude_key']) );
        }

        // Invalidate cache on settings save
        delete_transient( 'wddrm_ai_suggestions' );

        wp_redirect( admin_url( 'admin.php?page=wddrm-ai&msg=settings_saved' ) );
        exit;
    }

    /**
     * Render Recommendation Center UI.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'woo-dynamic-discount-rules-master' ) );
        }

        $nonce = wp_create_nonce( 'wddrm_ai_nonce' );
        $active_provider = get_option( 'wddrm_ai_provider', 'local' );
        $openai_key = get_option( 'wddrm_openai_api_key', '' );
        $claude_key = get_option( 'wddrm_claude_api_key', '' );
        
        $msg = sanitize_key( $_GET['msg'] ?? '' );
        ?>
        <style>
            .wddrm-ai-container {
                max-width: 1200px;
                margin: 20px 20px 0 0;
                font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: #e2e8f0;
            }
            .wddrm-ai-header {
                background: linear-gradient(135deg, #1e293b, #0f172a);
                padding: 24px;
                border-radius: 16px;
                border: 1px solid #334155;
                margin-bottom: 24px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .wddrm-ai-header h1 {
                margin: 0;
                font-size: 28px;
                color: #ffffff;
            }
            .wddrm-ai-header p {
                margin: 4px 0 0 0;
                color: #94a3b8;
            }
            .wddrm-ai-cols {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 24px;
            }
            @media (max-width: 900px) {
                .wddrm-ai-cols {
                    grid-template-columns: 1fr;
                }
            }
            .wddrm-ai-card {
                background: #1e293b;
                border-radius: 16px;
                border: 1px solid #334155;
                padding: 24px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                margin-bottom: 24px;
            }
            .wddrm-ai-card h2 {
                margin: 0 0 16px 0;
                font-size: 20px;
                color: #ffffff;
            }
            .wddrm-sug-item {
                background: #0f172a;
                border: 1px solid #334155;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 16px;
                position: relative;
                transition: transform 0.2s;
            }
            .wddrm-sug-item:hover {
                transform: scale(1.01);
            }
            .wddrm-sug-title {
                font-size: 18px;
                font-weight: 700;
                color: #ffffff;
                margin-bottom: 6px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .wddrm-sug-desc {
                color: #cbd5e1;
                font-size: 14px;
                line-height: 1.5;
                margin-bottom: 16px;
            }
            .wddrm-sug-score {
                position: absolute;
                top: 20px;
                right: 20px;
                background: rgba(16, 185, 129, 0.15);
                color: #10b981;
                padding: 4px 10px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 700;
            }
            .wddrm-sug-badge {
                padding: 3px 8px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                background: #334155;
                color: #94a3b8;
            }
            .wddrm-badge-slow { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
            .wddrm-badge-declining { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
            .wddrm-badge-bundle { background: rgba(6, 182, 212, 0.15); color: #06b6d4; }
            .wddrm-badge-reengage { background: rgba(168, 85, 247, 0.15); color: #a855f7; }

            .wddrm-ai-btn {
                background: linear-gradient(135deg, #06b6d4, #0891b2);
                color: #ffffff;
                border: none;
                padding: 10px 18px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                text-decoration: none;
                display: inline-block;
                font-size: 13px;
                transition: transform 0.2s;
            }
            .wddrm-ai-btn:hover {
                transform: translateY(-1px);
            }
            .wddrm-ai-btn-refresh {
                background: #334155;
                border: 1px solid #475569;
                color: #cbd5e1;
                padding: 10px 18px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
            }
            .wddrm-ai-btn-refresh:hover {
                background: #475569;
                color: #ffffff;
            }
            .wddrm-form-row {
                margin-bottom: 16px;
            }
            .wddrm-form-row label {
                display: block;
                font-weight: 600;
                margin-bottom: 6px;
                color: #cbd5e1;
            }
            .wddrm-form-input {
                width: 100%;
                background: #0f172a;
                border: 1px solid #475569;
                color: #ffffff;
                padding: 10px;
                border-radius: 8px;
                outline: none;
            }
            .wddrm-form-select {
                width: 100%;
                background: #0f172a;
                border: 1px solid #475569;
                color: #ffffff;
                padding: 10px;
                border-radius: 8px;
                outline: none;
                height: 40px;
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

        <div class="wddrm-ai-container">
            <?php if ( $msg === 'settings_saved' ) : ?>
                <div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0"><p>AI Service configurations saved successfully.</p></div>
            <?php endif; ?>

            <div class="wddrm-ai-header">
                <div>
                    <h1>AI Suggestions & Analytics</h1>
                    <p>Automated business suggestions for pricing, bundle packages, and re-engagement campaigns.</p>
                </div>
                <div style="display:flex; gap:12px; align-items:center">
                    <div class="wddrm-loader" id="ai-loader"></div>
                    <button class="wddrm-ai-btn-refresh" id="ai-refresh-btn">Re-run Analysis</button>
                </div>
            </div>

            <div class="wddrm-ai-cols">
                <!-- Left: Suggestions -->
                <div>
                    <div class="wddrm-ai-card" id="suggestions-wrapper">
                        <h2>Optimization Opportunities</h2>
                        <div id="suggestions-list">
                            <p style="color:#64748b; text-align:center; padding:32px">Computing local sales models and opportunities...</p>
                        </div>
                    </div>
                </div>

                <!-- Right: Integration Settings -->
                <div>
                    <div class="wddrm-ai-card">
                        <h2>AI Integration Options</h2>
                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                            <?php wp_nonce_field( 'wddrm_save_ai_settings_action', 'wddrm_ai_nonce' ); ?>
                            <input type="hidden" name="action" value="wddrm_save_ai_settings">

                            <div class="wddrm-form-row">
                                <label for="ai-provider">AI Strategy Provider</label>
                                <select name="ai_provider" id="ai-provider" class="wddrm-form-select">
                                    <option value="local" <?php selected( $active_provider, 'local' ); ?>>Local Heuristic Engine</option>
                                    <option value="openai" <?php selected( $active_provider, 'openai' ); ?>>OpenAI API Service</option>
                                    <option value="claude" <?php selected( $active_provider, 'claude' ); ?>>Claude API Service</option>
                                </select>
                            </div>

                            <div class="api-fields" id="fields-openai" style="display: <?php echo $active_provider === 'openai' ? 'block' : 'none'; ?>">
                                <div class="wddrm-form-row">
                                    <label for="openai-key">OpenAI API Key</label>
                                    <input type="password" name="openai_key" id="openai-key" class="wddrm-form-input" value="<?php echo esc_attr($openai_key); ?>" placeholder="sk-...">
                                </div>
                            </div>

                            <div class="api-fields" id="fields-claude" style="display: <?php echo $active_provider === 'claude' ? 'block' : 'none'; ?>">
                                <div class="wddrm-form-row">
                                    <label for="claude-key">Anthropic Claude API Key</label>
                                    <input type="password" name="claude_key" id="claude-key" class="wddrm-form-input" value="<?php echo esc_attr($claude_key); ?>" placeholder="sk-ant-...">
                                </div>
                            </div>

                            <div class="wddrm-form-row" style="margin: 0; margin-top:20px">
                                <button type="submit" class="wddrm-ai-btn" style="width:100%">Save API Keys</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                var security = '<?php echo esc_js( $nonce ); ?>';

                // Sync API fields visibility
                $('#ai-provider').on('change', function() {
                    var val = $(this).val();
                    $('.api-fields').hide();
                    if (val === 'openai') $('#fields-openai').show();
                    if (val === 'claude') $('#fields-claude').show();
                });

                function loadSuggestions(force) {
                    $('#ai-loader').show();
                    $('#suggestions-list').html('<p style="color:#64748b;text-align:center;padding:32px">Analyzing transaction logs & computing recommendation models...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wddrm_get_ai_suggestions',
                            security: security,
                            force_refresh: force ? 1 : 0
                        },
                        success: function(response) {
                            $('#ai-loader').hide();
                            if (response.success) {
                                renderSuggestions(response.data);
                            } else {
                                $('#suggestions-list').html('<p style="color:#ef4444;text-align:center;padding:32px">Failed to load suggestions: ' + response.data + '</p>');
                            }
                        },
                        error: function() {
                            $('#ai-loader').hide();
                            $('#suggestions-list').html('<p style="color:#ef4444;text-align:center;padding:32px">Internal Server Error occurred during calculation.</p>');
                        }
                    });
                }

                function renderSuggestions(list) {
                    if (!list || list.length === 0) {
                        $('#suggestions-list').html('<p style="color:#64748b;text-align:center;padding:32px">Excellent! No pricing issues or inventory imbalances detected at this time.</p>');
                        return;
                    }

                    var html = '';
                    $.each(list, function(_, sug) {
                        var badgeClass = 'wddrm-sug-badge';
                        var badgeText = 'Opportunity';
                        if (sug.type === 'slow_moving') { badgeClass += ' wddrm-badge-slow'; badgeText = 'Slow Moving'; }
                        if (sug.type === 'declining_sales') { badgeClass += ' wddrm-badge-declining'; badgeText = 'Declining Sales'; }
                        if (sug.type === 'bundle_offer') { badgeClass += ' wddrm-badge-bundle'; badgeText = 'Bundle Offer'; }
                        if (sug.type === 'reengagement') { badgeClass += ' wddrm-badge-reengage'; badgeText = 'Re-engagement'; }

                        // Generate rule prefill query URL
                        var query = 'page=wddrm-rules-list&action=new';
                        if (sug.suggested_rule) {
                            query += '&rule_name=' + encodeURIComponent(sug.suggested_rule.name);
                            query += '&discount_type=' + encodeURIComponent(sug.suggested_rule.discount_type);
                            if (sug.suggested_rule.discount_data) {
                                if (sug.suggested_rule.discount_data.percentage) {
                                    query += '&discount_pct=' + sug.suggested_rule.discount_data.percentage;
                                }
                                if (sug.suggested_rule.discount_data.amount) {
                                    query += '&discount_amt=' + sug.suggested_rule.discount_data.amount;
                                }
                                if (sug.suggested_rule.discount_data.bundle_products) {
                                    query += '&bundle_products=' + sug.suggested_rule.discount_data.bundle_products.join(',');
                                }
                                if (sug.suggested_rule.discount_data.bundle_discount_pct) {
                                    query += '&bundle_discount_pct=' + sug.suggested_rule.discount_data.bundle_discount_pct;
                                }
                            }
                            if (sug.suggested_rule.filter_data && sug.suggested_rule.filter_data.included_products) {
                                query += '&inc_products=' + sug.suggested_rule.filter_data.included_products.join(',');
                            }
                        }

                        html += '<div class="wddrm-sug-item">'
                            + '<div class="wddrm-sug-score">Confidence ' + sug.score + '%</div>'
                            + '<div class="wddrm-sug-title"><span class="' + badgeClass + '">' + badgeText + '</span> ' + sug.title + '</div>'
                            + '<div class="wddrm-sug-desc">' + sug.description + '</div>'
                            + '<a href="admin.php?' + query + '" class="wddrm-ai-btn">Create Rule from Suggestion</a>'
                            + '</div>';
                    });

                    $('#suggestions-list').html(html);
                }

                $('#ai-refresh-btn').on('click', function(e) {
                    e.preventDefault();
                    loadSuggestions(true);
                });

                loadSuggestions(false);
            });
        </script>
        <?php
    }
}
