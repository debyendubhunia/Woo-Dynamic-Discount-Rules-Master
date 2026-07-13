<?php
namespace WooDynamicDiscountRulesMaster\Admin;

use WooDynamicDiscountRulesMaster\Repository\RuleRepository;

class Admin {

    /**
     * @var RuleRepository
     */
    private $repo;

    /* ── discount_type → label map ── */
    const TYPES = [
        'product' => [
            'product_fixed'       => 'Fixed Product Discount',
            'product_percentage'  => 'Percentage Product Discount',
            'product_bundle'      => 'Product Bundle Discount',
            'product_specific'    => 'Product-Specific Pricing',
            'product_variation'   => 'Product Variation Discount',
        ],
        'category' => [
            'category_percentage' => 'Category Percentage Discount',
            'category_fixed'      => 'Category Fixed Discount',
            'category_quantity'   => 'Category Quantity Discount',
            'multi_category'      => 'Multi-Category Rules',
        ],
        'cart' => [
            'cart_fixed'          => 'Fixed Cart Discount',
            'cart_percentage'     => 'Percentage Cart Discount',
            'cart_quantity'       => 'Cart Quantity Rules',
            'cart_total'          => 'Cart Total Rules',
            'cart_subtotal'       => 'Cart Subtotal Rules',
        ],
        'bxgy' => [
            'bxgy_free'           => 'Buy X Get Y Free',
            'bxgy_discount'       => 'Buy X Get Y Discount',
            'bxgy_product'        => 'Buy Product X Get Product Y',
            'bxgy_category'       => 'Buy Category X Get Category Y',
            'bxgy_quantity_reward'=> 'Quantity-Based Rewards',
        ],
        'user' => [
            'role_pricing'        => 'Role-Based Pricing',
            'customer_pricing'    => 'Customer-Specific Pricing',
            'vip_pricing'         => 'VIP Pricing',
            'wholesale_pricing'   => 'Wholesale Pricing',
        ],
        'advanced' => [
            'first_order'           => 'First Order Discount',
            'repeat_customer'       => 'Repeat Customer Discount',
            'purchase_history'      => 'Purchase History Discount',
            'scheduled'             => 'Scheduled Discount',
            'country_rule'          => 'Country Rules',
            'state_rule'            => 'State Rules',
            'shipping_method_rule'  => 'Shipping Method Rules',
            'payment_method_rule'   => 'Payment Method Rules',
        ],
    ];

    const GROUP_LABELS = [
        'product'  => 'Product',
        'category' => 'Category',
        'cart'     => 'Cart',
        'bxgy'     => 'Buy X Get Y',
        'user'     => 'User Pricing',
        'advanced' => 'Advanced',
    ];

    public function init() {
        $this->repo = new RuleRepository();
        add_action( 'admin_menu',            [$this, 'add_menu'] );
        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_assets'] );
        add_action( 'admin_post_wddrm_save_rule',   [$this, 'handle_save'] );
        add_action( 'admin_post_wddrm_delete_rule', [$this, 'handle_delete'] );
        add_action( 'admin_post_wddrm_toggle_rule', [$this, 'handle_toggle'] );
    }

    public function add_menu() {
        // Register top level menu page with the Dashboard as callback so it is the default page loaded
        add_menu_page(
            'Discount Rules', 'Discount Rules', 'manage_woocommerce',
            'wddrm-rules', ['\WooDynamicDiscountRulesMaster\Dashboard\DashboardController', 'render_page'], 'dashicons-tag', 55
        );

        // First submenu page shares the parent slug and acts as Dashboard
        add_submenu_page(
            'wddrm-rules',
            'Dashboard & Analytics', 'Dashboard', 'manage_woocommerce',
            'wddrm-rules', ['\WooDynamicDiscountRulesMaster\Dashboard\DashboardController', 'render_page']
        );

        // Rules List is registered as a separate submenu page
        add_submenu_page(
            'wddrm-rules',
            'Discount Rules List', 'Rules List', 'manage_woocommerce',
            'wddrm-rules-list', [$this, 'render_page']
        );

        add_submenu_page(
            'wddrm-rules',
            'Customer Segmentation', 'Segmentation', 'manage_woocommerce',
            'wddrm-segmentation', ['\WooDynamicDiscountRulesMaster\Segmentation\SegmentationController', 'render_page']
        );

        add_submenu_page(
            'wddrm-rules',
            'AI Suggestions', 'AI Suggestions', 'manage_woocommerce',
            'wddrm-ai', ['\WooDynamicDiscountRulesMaster\AI\AIController', 'render_page']
        );

        add_submenu_page(
            'wddrm-rules',
            'Import & Export', 'Import / Export', 'manage_woocommerce',
            'wddrm-import-export', ['\WooDynamicDiscountRulesMaster\ImportExport\ImportExportController', 'render_page']
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wddrm-rules' ) === false ) return;
        
        wp_enqueue_style( 'woocommerce_admin_styles' );
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_localize_script( 'wc-enhanced-select', 'wc_enhanced_select_params', [
            'i18n_matches_1'            => _x( 'One result is available, press enter to select it.', 'enhanced select text', 'woocommerce' ),
            'i18n_matches_n'            => _x( '%qty% results are available, use up and down arrow keys to navigate.', 'enhanced select text', 'woocommerce' ),
            'i18n_no_matches'           => _x( 'No matches found', 'enhanced select text', 'woocommerce' ),
            'i18n_ajax_error'           => _x( 'Loading failed', 'enhanced select text', 'woocommerce' ),
            'i18n_input_too_short_1'    => _x( 'Please enter 1 or more characters', 'enhanced select text', 'woocommerce' ),
            'i18n_input_too_short_n'    => _x( 'Please enter %qty% or more characters', 'enhanced select text', 'woocommerce' ),
            'i18n_input_too_long_1'     => _x( 'Please delete 1 character', 'enhanced select text', 'woocommerce' ),
            'i18n_input_too_long_n'     => _x( 'Please delete %qty% characters', 'enhanced select text', 'woocommerce' ),
            'i18n_selection_too_long_1' => _x( 'You can only select 1 item', 'enhanced select text', 'woocommerce' ),
            'i18n_selection_too_long_n' => _x( 'You can only select %qty% items', 'enhanced select text', 'woocommerce' ),
            'i18n_load_more'            => _x( 'Loading more results…', 'enhanced select text', 'woocommerce' ),
            'i18n_searching'            => _x( 'Searching…', 'enhanced select text', 'woocommerce' ),
            'ajax_url'                  => admin_url( 'admin-ajax.php' ),
            'search_products_nonce'     => wp_create_nonce( 'search-products' ),
            'search_customers_nonce'    => wp_create_nonce( 'search-customers' ),
        ] );
        
        wp_enqueue_style( 'wddrm-admin', WDDRM_PLUGIN_URL . 'admin/assets/css/admin.css', [], WDDRM_VERSION );
        wp_enqueue_script( 'wddrm-admin', WDDRM_PLUGIN_URL . 'admin/assets/js/admin.js', ['jquery', 'wc-enhanced-select'], WDDRM_VERSION, true );

        $cats = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        $formatted_cats = [];
        if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
            foreach ( $cats as $cat ) {
                $formatted_cats[] = [
                    'id'   => $cat->term_id,
                    'name' => $cat->name,
                ];
            }
        }

        wp_localize_script( 'wddrm-admin', 'WDDRM', [
            'types'           => self::TYPES,
            'categories'      => $formatted_cats,
            'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
        ] );
    }

    /* ── Router ── */
    public function render_page() {
        $action = sanitize_key( $_GET['action'] ?? 'list' );
        $id     = absint( $_GET['id'] ?? 0 );

        switch ( $action ) {
            case 'new':  $this->render_form( null ); break;
            case 'edit':
                $rule = $this->repo->get( $id );
                $rule ? $this->render_form( $rule ) : $this->render_list( 'Rule not found.' );
                break;
            default: $this->render_list();
        }
    }

    /* ================================================================== */
    /*  LIST                                                                */
    /* ================================================================== */
    private function render_list( $error = '' ) {
        $rules   = $this->repo->get_all();
        $msg_map = [
            'saved'   => 'Rule saved successfully.',
            'deleted' => 'Rule deleted.',
            'toggled' => 'Rule status updated.',
        ];
        $msg = isset( $_GET['msg'] ) ? ( $msg_map[ sanitize_key( $_GET['msg'] ) ] ?? '' ) : '';
        $tab = sanitize_key( $_GET['tab'] ?? 'all' );

        // Filter by tab
        $filtered = $tab === 'all' ? $rules : array_filter( $rules, function($r) use ($tab) { return $r['rule_group'] === $tab; } );
        ?>
        <div class="wrap wddrm-wrap">
            <h1 class="wp-heading-inline">Discount Rules</h1>
            <a href="<?php echo esc_url( admin_url('admin.php?page=wddrm-rules-list&action=new') ); ?>" class="page-title-action">+ Add New Rule</a>
            <hr class="wp-header-end">

            <?php if ( $msg   ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg); ?></p></div><?php endif; ?>
            <?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

            <!-- Group tabs -->
            <nav class="nav-tab-wrapper wddrm-tabs">
                <a href="<?php echo esc_url( add_query_arg('tab','all') ); ?>"
                   class="nav-tab <?php echo $tab==='all'?'nav-tab-active':''; ?>">All (<?php echo count($rules); ?>)</a>
                <?php foreach ( self::GROUP_LABELS as $key => $label ) :
                    $cnt = count( array_filter( $rules, function($r) use ($key) { return $r['rule_group'] === $key; } ) );
                ?>
                <a href="<?php echo esc_url( add_query_arg('tab',$key) ); ?>"
                   class="nav-tab <?php echo $tab===$key?'nav-tab-active':''; ?>"><?php echo esc_html("$label ($cnt)"); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ( empty( $filtered ) ) : ?>
                <div class="wddrm-empty">
                    <span class="dashicons dashicons-tag"></span>
                    <p>No rules in this group yet.</p>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=wddrm-rules-list&action=new') ); ?>" class="button button-primary">Create your first rule</a>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped wddrm-table">
                    <thead><tr>
                        <th>Rule Name</th><th>Group</th><th>Type</th>
                        <th>Discount Summary</th><th>Priority</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $filtered as $rule ) :
                        $type_label  = $this->get_type_label( $rule['discount_type'] );
                        $group_label = self::GROUP_LABELS[ $rule['rule_group'] ] ?? $rule['rule_group'];
                        $disc_summary = $this->discount_summary( $rule );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $rule['name'] ); ?></strong></td>
                        <td><span class="wddrm-badge wddrm-badge-<?php echo esc_attr($rule['rule_group']); ?>"><?php echo esc_html($group_label); ?></span></td>
                        <td><?php echo esc_html( $type_label ); ?></td>
                        <td><?php echo esc_html( $disc_summary ); ?></td>
                        <td><?php echo esc_html( $rule['priority'] ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('wddrm_toggle_rule','wddrm_nonce'); ?>
                                <input type="hidden" name="action" value="wddrm_toggle_rule">
                                <input type="hidden" name="rule_id" value="<?php echo absint($rule['id']); ?>">
                                <button type="submit" class="wddrm-toggle <?php echo $rule['status']?'active':'inactive'; ?>">
                                    <?php echo $rule['status'] ? '● Active' : '○ Inactive'; ?>
                                </button>
                            </form>
                        </td>
                        <td class="wddrm-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wddrm-rules-list&action=edit&id='.absint($rule['id']))); ?>" class="button button-small">Edit</a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
                                  onsubmit="return confirm('Delete this rule?')">
                                <?php wp_nonce_field('wddrm_delete_rule','wddrm_nonce'); ?>
                                <input type="hidden" name="action" value="wddrm_delete_rule">
                                <input type="hidden" name="rule_id" value="<?php echo absint($rule['id']); ?>">
                                <button type="submit" class="button button-small wddrm-btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ================================================================== */
    /*  FORM                                                                */
    /* ================================================================== */
    private function render_form( $rule ) {
        $is_edit   = ! empty( $rule );
        if ( ! $is_edit ) {
            $d = [
                'name' => sanitize_text_field( $_GET['rule_name'] ?? '' ),
                'discount_type' => sanitize_key( $_GET['discount_type'] ?? 'bxgy_free' ),
                'rule_group' => 'bxgy',
                'status' => 1,
                'priority' => 10,
                'stop_further_rules' => 0,
                'start_date' => null,
                'end_date' => null,
                'usage_limit_per_rule' => null,
                'usage_limit_per_user' => null,
            ];
            
            $disc_data = [
                'mode' => 'percentage',
                'percentage' => (float)( $_GET['discount_pct'] ?? 0 ),
                'amount' => (float)( $_GET['discount_amt'] ?? 0 ),
                'bundle_products' => array_filter( array_map( 'absint', explode( ',', $_GET['bundle_products'] ?? '' ) ) ),
                'bundle_discount_pct' => (float)( $_GET['bundle_discount_pct'] ?? 0 ),
                'roles' => [],
                'customer_ids' => [],
                'min_amount' => 0,
                'tiers' => [],
                'countries' => [],
                'states' => []
            ];
            $disc_data['value'] = $disc_data['percentage'] ?: $disc_data['amount'];
            
            $filt_data = [
                'included_products' => array_filter( array_map( 'absint', explode( ',', $_GET['inc_products'] ?? '' ) ) ),
                'excluded_products' => [],
                'categories' => [],
                'excluded_categories' => []
            ];
            $cond_data = [];
            $bxgy_data = [];
        } else {
            $d         = $rule;
            $disc_data = json_decode($rule['discount_data'], true) ?? [];
            $filt_data = json_decode($rule['filter_data'],   true) ?? [];
            $cond_data = json_decode($rule['condition_data'],true) ?? [];
            $bxgy_data = json_decode($rule['bxgy_data']??'{}', true) ?? [];
        }
        $disc_type = $d['discount_type'] ?? 'bxgy_free';
        $rule_group= $d['rule_group']    ?? 'bxgy';
        
        $all_categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if ( is_wp_error( $all_categories ) ) {
            $all_categories = [];
        }
        ?>
        <div class="wrap wddrm-wrap">
            <h1><?php echo $is_edit ? 'Edit Rule' : 'Add New Rule'; ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wddrm-rules-list')); ?>">← Back to rules</a>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wddrm-form" id="wddrm-rule-form">
                <?php wp_nonce_field('wddrm_save_rule','wddrm_nonce'); ?>
                <input type="hidden" name="action" value="wddrm_save_rule">
                <?php if ($is_edit): ?><input type="hidden" name="rule_id" value="<?php echo absint($rule['id']); ?>"><?php endif; ?>

                <div class="wddrm-form-cols">
                    <!-- Main column -->
                    <div class="wddrm-main-col">

                        <!-- General -->
                        <div class="wddrm-card">
                            <h2>General Settings</h2>
                            <table class="form-table">
                                <tr>
                                    <th><label for="rule_name">Rule Name <span class="required">*</span></label></th>
                                    <td><input type="text" id="rule_name" name="rule_name" value="<?php echo esc_attr($d['name']??''); ?>" class="large-text" required></td>
                                </tr>
                                <tr>
                                    <th><label for="rule_group">Rule Group</label></th>
                                    <td>
                                        <select id="rule_group" name="rule_group">
                                            <?php foreach (self::GROUP_LABELS as $gk => $gl): ?>
                                            <option value="<?php echo esc_attr($gk); ?>" <?php selected($rule_group,$gk); ?>><?php echo esc_html($gl); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="discount_type">Discount Type</label></th>
                                    <td>
                                        <select id="discount_type" name="discount_type">
                                            <?php foreach (self::TYPES as $gk => $gtypes): ?>
                                            <optgroup label="<?php echo esc_attr(self::GROUP_LABELS[$gk]??$gk); ?>">
                                                <?php foreach ($gtypes as $tv => $tl): ?>
                                                <option value="<?php echo esc_attr($tv); ?>" <?php selected($disc_type,$tv); ?>><?php echo esc_html($tl); ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="rule_status">Status</label></th>
                                    <td>
                                        <select id="rule_status" name="rule_status">
                                            <option value="1" <?php selected($d['status']??1,1); ?>>Active</option>
                                            <option value="0" <?php selected($d['status']??1,0); ?>>Inactive</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="rule_priority">Priority</label></th>
                                    <td>
                                        <input type="number" id="rule_priority" name="rule_priority" value="<?php echo esc_attr($d['priority']??10); ?>" min="0" max="999" class="small-text">
                                        <p class="description">Higher = applied first.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Options</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="stop_further_rules" value="1" <?php checked($d['stop_further_rules']??0,1); ?>>
                                            Stop applying lower-priority rules after this one matches
                                        </label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- ====== DISCOUNT VALUE ====== -->
                        <div class="wddrm-card disc-field" data-types="product_fixed,product_percentage,product_bundle,product_specific,product_variation,category_percentage,category_fixed,category_quantity,multi_category,cart_fixed,cart_percentage,cart_quantity,cart_total,cart_subtotal,role_pricing,customer_pricing,vip_pricing,wholesale_pricing,first_order,repeat_customer,purchase_history,scheduled,country_rule,state_rule,shipping_method_rule,payment_method_rule">
                            <h2>Discount Value</h2>
                            <table class="form-table">

                                <!-- Cart / Product fixed -->
                                <tr class="disc-field disc-fixed" data-types="cart_fixed,product_fixed,category_fixed,first_order,country_rule,state_rule,shipping_method_rule,payment_method_rule,customer_pricing,role_pricing,vip_pricing,wholesale_pricing,scheduled">
                                    <th><label>Amount (<?php echo esc_html(get_woocommerce_currency_symbol()); ?>)</label></th>
                                    <td><input type="number" name="disc_amount" value="<?php echo esc_attr($disc_data['amount']??''); ?>" min="0" step="0.01" class="small-text"></td>
                                </tr>

                                <!-- Percentage -->
                                <tr class="disc-field disc-pct" data-types="cart_percentage,product_percentage,category_percentage,repeat_customer,purchase_history,scheduled,cart_total,cart_subtotal,customer_pricing,role_pricing,vip_pricing,wholesale_pricing">
                                    <th><label>Percentage (%)</label></th>
                                    <td><input type="number" name="disc_percentage" value="<?php echo esc_attr($disc_data['percentage']??''); ?>" min="0" max="100" step="0.01" class="small-text"> %</td>
                                </tr>

                                <!-- Pricing mode for user/role types -->
                                <tr class="disc-field disc-mode" data-types="role_pricing,customer_pricing,vip_pricing,wholesale_pricing,country_rule,state_rule,shipping_method_rule,payment_method_rule,scheduled">
                                    <th><label>Discount Mode</label></th>
                                    <td>
                                        <select name="disc_mode">
                                            <option value="percentage" <?php selected($disc_data['mode']??'percentage','percentage'); ?>>Percentage Off</option>
                                            <option value="fixed"      <?php selected($disc_data['mode']??'','fixed'); ?>>Fixed Amount Off</option>
                                        </select>
                                    </td>
                                </tr>

                                <!-- Role/user IDs -->
                                <tr class="disc-field disc-roles" data-types="role_pricing,vip_pricing,wholesale_pricing">
                                    <th><label>Apply to Roles</label></th>
                                    <td>
                                        <?php
                                        $all_roles = wp_roles()->get_names();
                                        $sel_roles = $disc_data['roles'] ?? [];
                                        foreach ($all_roles as $rk => $rl): ?>
                                        <label style="display:block;margin-bottom:4px">
                                            <input type="checkbox" name="disc_roles[]" value="<?php echo esc_attr($rk); ?>" <?php checked(in_array($rk,$sel_roles,true)); ?>>
                                            <?php echo esc_html($rl); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>

                                <tr class="disc-field disc-customers" data-types="customer_pricing">
                                    <th><label>Customer IDs</label></th>
                                    <td>
                                        <input type="text" name="disc_customer_ids" value="<?php echo esc_attr(implode(',',$disc_data['customer_ids']??[])); ?>" class="regular-text" placeholder="e.g. 5, 12, 99">
                                        <p class="description">Comma-separated WP user IDs.</p>
                                    </td>
                                </tr>

                                <!-- Cart min amount for cart_total / cart_subtotal -->
                                <tr class="disc-field disc-minamount" data-types="cart_total,cart_subtotal">
                                    <th><label>Minimum Cart Amount (<?php echo esc_html(get_woocommerce_currency_symbol()); ?>)</label></th>
                                    <td><input type="number" name="disc_min_amount" value="<?php echo esc_attr($disc_data['min_amount']??''); ?>" min="0" step="0.01" class="small-text"></td>
                                </tr>

                                <!-- Tiers for cart_quantity / category_quantity -->
                                <tr class="disc-field disc-tiers" data-types="cart_quantity,category_quantity">
                                    <th><label>Quantity Tiers</label></th>
                                    <td>
                                        <div id="quantity-tiers">
                                            <?php
                                            $tiers = $disc_data['tiers'] ?? [['min_qty'=>1,'discount_pct'=>10]];
                                            foreach ($tiers as $i => $tier): ?>
                                            <div class="wddrm-tier-row">
                                                Min qty: <input type="number" name="tiers[<?php echo $i; ?>][min_qty]" value="<?php echo esc_attr($tier['min_qty']); ?>" min="1" class="small-text">
                                                &nbsp; Discount %: <input type="number" name="tiers[<?php echo $i; ?>][discount_pct]" value="<?php echo esc_attr($tier['discount_pct']); ?>" min="0" max="100" step="0.01" class="small-text">
                                                <button type="button" class="button wddrm-remove-tier">×</button>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="button" id="wddrm-add-tier">+ Add Tier</button>
                                    </td>
                                </tr>

                                <!-- Bundle fields -->
                                <tr class="disc-field disc-bundle" data-types="product_bundle">
                                    <th><label>Bundle Products</label></th>
                                    <td>
                                        <select class="wc-product-search" multiple="multiple" name="bundle_products[]" data-placeholder="Search for products..." data-action="woocommerce_json_search_products_and_variations" style="width:100%; max-width: 400px;">
                                            <?php
                                            $bundle_products = $disc_data['bundle_products'] ?? [];
                                            foreach ( $bundle_products as $prod_id ) {
                                                $prod = wc_get_product( $prod_id );
                                                if ( $prod ) {
                                                    echo '<option value="' . esc_attr( $prod_id ) . '" selected="selected">' . esc_html( $prod->get_formatted_name() ) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                        <p class="description">All these products must be in cart.</p>
                                    </td>
                                </tr>
                                <tr class="disc-field disc-bundle" data-types="product_bundle">
                                    <th><label>Bundle Discount %</label></th>
                                    <td><input type="number" name="bundle_discount_pct" value="<?php echo esc_attr($disc_data['bundle_discount_pct']??''); ?>" min="0" max="100" step="0.01" class="small-text"></td>
                                </tr>
                                <tr class="disc-field disc-bundle" data-types="product_bundle">
                                    <th><label>Bundle Discount Fixed (<?php echo esc_html(get_woocommerce_currency_symbol()); ?>)</label></th>
                                    <td><input type="number" name="bundle_discount_fixed" value="<?php echo esc_attr($disc_data['bundle_discount_fixed']??''); ?>" min="0" step="0.01" class="small-text"></td>
                                </tr>

                                <!-- Countries for country_rule -->
                                <tr class="disc-field disc-countries" data-types="country_rule">
                                    <th><label>Target Countries</label></th>
                                    <td>
                                        <input type="text" name="disc_countries" value="<?php echo esc_attr( implode( ',', $disc_data['countries'] ?? [] ) ); ?>" class="regular-text" placeholder="e.g. US, CA, GB">
                                        <p class="description">Comma-separated two-letter country codes.</p>
                                    </td>
                                </tr>

                                <!-- States for state_rule -->
                                <tr class="disc-field disc-states" data-types="state_rule">
                                    <th><label>Target States</label></th>
                                    <td>
                                        <input type="text" name="disc_states" value="<?php echo esc_attr( implode( ',', $disc_data['states'] ?? [] ) ); ?>" class="regular-text" placeholder="e.g. NY, CA, TX">
                                        <p class="description">Comma-separated state codes.</p>
                                    </td>
                                </tr>

                            </table>
                        </div>

                        <!-- ====== BUY X GET Y ====== -->
                        <div class="wddrm-card disc-field disc-bxgy" data-types="bxgy_free,bxgy_discount,bxgy_product,bxgy_category,bxgy_quantity_reward">
                            <h2>Buy X Get Y Settings</h2>
                            <table class="form-table">
                                <tr class="bxgy-qty-rows disc-field" data-types="bxgy_free,bxgy_discount,bxgy_product,bxgy_category">
                                    <th><label>Buy Quantity (X)</label></th>
                                    <td><input type="number" name="bxgy_buy_qty" value="<?php echo esc_attr($bxgy_data['buy_quantity']??1); ?>" min="1" class="small-text"></td>
                                </tr>
                                <tr class="bxgy-qty-rows disc-field" data-types="bxgy_free,bxgy_discount,bxgy_product,bxgy_category">
                                    <th><label>Get Quantity (Y)</label></th>
                                    <td><input type="number" name="bxgy_get_qty" value="<?php echo esc_attr($bxgy_data['get_quantity']??1); ?>" min="1" class="small-text"></td>
                                </tr>
                                <tr class="bxgy-prod-rows disc-field" data-types="bxgy_free,bxgy_discount,bxgy_product">
                                    <th><label>Buy Products</label></th>
                                    <td>
                                        <select class="wc-product-search" multiple="multiple" name="bxgy_buy_products[]" data-placeholder="Search for products..." data-action="woocommerce_json_search_products_and_variations" style="width:100%; max-width: 400px;">
                                            <?php
                                            $buy_products = $bxgy_data['buy_products'] ?? [];
                                            foreach ( $buy_products as $prod_id ) {
                                                $prod = wc_get_product( $prod_id );
                                                if ( $prod ) {
                                                    echo '<option value="' . esc_attr( $prod_id ) . '" selected="selected">' . esc_html( $prod->get_formatted_name() ) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                        <p class="description">Leave blank = any product</p>
                                    </td>
                                </tr>
                                <tr class="bxgy-prod-rows bxgy-get-prods-row disc-field" data-types="bxgy_free,bxgy_discount,bxgy_product">
                                    <th><label>Get Products</label></th>
                                    <td>
                                        <select class="wc-product-search" multiple="multiple" name="bxgy_get_products[]" data-placeholder="Search for products..." data-action="woocommerce_json_search_products_and_variations" style="width:100%; max-width: 400px;">
                                            <?php
                                            $get_products = $bxgy_data['get_products'] ?? [];
                                            foreach ( $get_products as $prod_id ) {
                                                $prod = wc_get_product( $prod_id );
                                                if ( $prod ) {
                                                    echo '<option value="' . esc_attr( $prod_id ) . '" selected="selected">' . esc_html( $prod->get_formatted_name() ) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                        <p class="description">Leave blank = cheapest in cart</p>
                                    </td>
                                </tr>
                                <tr class="bxgy-cat-rows disc-field" data-types="bxgy_category">
                                    <th><label>Buy Categories</label></th>
                                    <td>
                                        <select class="wddrm-category-search" multiple="multiple" name="bxgy_buy_cats[]" data-placeholder="Select categories..." style="width:100%; max-width: 400px;">
                                            <?php
                                            $buy_cats = $bxgy_data['buy_categories'] ?? [];
                                            foreach ( $all_categories as $cat ) {
                                                echo '<option value="' . esc_attr( $cat->term_id ) . '" ' . (in_array( $cat->term_id, $buy_cats ) ? 'selected="selected"' : '') . '>' . esc_html( $cat->name ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr class="bxgy-cat-rows bxgy-get-cats-row disc-field" data-types="bxgy_category">
                                    <th><label>Get Categories</label></th>
                                    <td>
                                        <select class="wddrm-category-search" multiple="multiple" name="bxgy_get_cats[]" data-placeholder="Select categories..." style="width:100%; max-width: 400px;">
                                            <?php
                                            $get_cats = $bxgy_data['get_categories'] ?? [];
                                            foreach ( $all_categories as $cat ) {
                                                echo '<option value="' . esc_attr( $cat->term_id ) . '" ' . (in_array( $cat->term_id, $get_cats ) ? 'selected="selected"' : '') . '>' . esc_html( $cat->name ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr class="disc-field bxgy-target-mode-row" data-types="bxgy_free,bxgy_discount,bxgy_product,bxgy_category">
                                    <th><label>Reward Target Mode</label></th>
                                    <td>
                                        <select name="bxgy_target_mode" id="bxgy_target_mode">
                                            <option value="specific" <?php selected($bxgy_data['target_mode']??'specific', 'specific'); ?>>Specific Products / Categories</option>
                                            <option value="cheapest" <?php selected($bxgy_data['target_mode']??'', 'cheapest'); ?>>Cheapest product in cart</option>
                                            <option value="expensive" <?php selected($bxgy_data['target_mode']??'', 'expensive'); ?>>Most expensive product in cart</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr class="disc-field bxgy-auto-add-row" data-types="bxgy_free,bxgy_discount,bxgy_product,bxgy_category">
                                    <th><label>Auto-Add Y to Cart</label></th>
                                    <td>
                                        <input type="checkbox" name="bxgy_auto_add" value="1" <?php checked($bxgy_data['auto_add']??0, 1); ?>>
                                        <span class="description">Automatically add the free/discounted product Y to the customer's cart when buy requirements are met (specific target mode only).</span>
                                    </td>
                                </tr>
                                <tr class="disc-field" data-types="bxgy_discount,bxgy_product,bxgy_category">
                                    <th><label>Discount on Y</label></th>
                                    <td>
                                        <select name="bxgy_discount_type" id="bxgy_discount_type" style="vertical-align: middle;">
                                            <option value="percentage" <?php selected($bxgy_data['discount_type']??'percentage', 'percentage'); ?>>Percentage Off (%)</option>
                                            <option value="fixed" <?php selected($bxgy_data['discount_type']??'', 'fixed'); ?>>Fixed Amount Off</option>
                                        </select>
                                        <input type="number" name="bxgy_disc_value" id="bxgy_disc_value" value="<?php echo esc_attr($bxgy_data['discount_value']??$bxgy_data['discount_percentage']??100); ?>" min="0" step="0.01" class="small-text" style="vertical-align: middle;">
                                        <span id="bxgy-disc-symbol">%</span>
                                        <p class="description" id="bxgy-disc-desc">100% = free. 50% = half price.</p>
                                    </td>
                                </tr>
                                <!-- Quantity reward tiers -->
                                <tr class="disc-field" data-types="bxgy_quantity_reward">
                                    <th><label>Reward Tiers</label></th>
                                    <td>
                                        <div id="bxgy-reward-tiers">
                                            <?php
                                            $rtiers = $bxgy_data['tiers'] ?? [['min_qty'=>5,'type'=>'pct','percentage'=>10]];
                                            foreach ($rtiers as $i => $rt): ?>
                                            <div class="wddrm-tier-row">
                                                Min qty: <input type="number" name="bxgy_tiers[<?php echo $i; ?>][min_qty]" value="<?php echo esc_attr($rt['min_qty']); ?>" min="1" class="small-text">
                                                &nbsp; Type:
                                                <select name="bxgy_tiers[<?php echo $i; ?>][type]">
                                                    <option value="pct"   <?php selected($rt['type']??'pct','pct'); ?>>%</option>
                                                    <option value="fixed" <?php selected($rt['type']??'','fixed'); ?>>Fixed</option>
                                                </select>
                                                &nbsp; Value: <input type="number" name="bxgy_tiers[<?php echo $i; ?>][value]" value="<?php echo esc_attr($rt['percentage']??$rt['amount']??0); ?>" min="0" step="0.01" class="small-text">
                                                <button type="button" class="button wddrm-remove-tier">×</button>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="button" id="wddrm-add-reward-tier">+ Add Tier</button>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- ====== FILTERS ====== -->
                        <div class="wddrm-card">
                            <h2>Product / Category Filters</h2>
                            <p class="description">Leave blank to apply to all products / categories.</p>
                            <table class="form-table">
                                <tr>
                                    <th><label>Include Products</label></th>
                                    <td>
                                        <select class="wc-product-search" multiple="multiple" name="inc_products[]" data-placeholder="Search for products..." data-action="woocommerce_json_search_products_and_variations" style="width:100%; max-width: 400px;">
                                            <?php
                                            $inc_products = $filt_data['included_products'] ?? [];
                                            foreach ( $inc_products as $prod_id ) {
                                                $prod = wc_get_product( $prod_id );
                                                if ( $prod ) {
                                                    echo '<option value="' . esc_attr( $prod_id ) . '" selected="selected">' . esc_html( $prod->get_formatted_name() ) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Exclude Products</label></th>
                                    <td>
                                        <select class="wc-product-search" multiple="multiple" name="exc_products[]" data-placeholder="Search for products..." data-action="woocommerce_json_search_products_and_variations" style="width:100%; max-width: 400px;">
                                            <?php
                                            $exc_products = $filt_data['excluded_products'] ?? [];
                                            foreach ( $exc_products as $prod_id ) {
                                                $prod = wc_get_product( $prod_id );
                                                if ( $prod ) {
                                                    echo '<option value="' . esc_attr( $prod_id ) . '" selected="selected">' . esc_html( $prod->get_formatted_name() ) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Include Categories</label></th>
                                    <td>
                                        <select class="wddrm-category-search" multiple="multiple" name="inc_categories[]" data-placeholder="Select categories..." style="width:100%; max-width: 400px;">
                                            <?php
                                            $inc_cats = $filt_data['categories'] ?? [];
                                            foreach ( $all_categories as $cat ) {
                                                echo '<option value="' . esc_attr( $cat->term_id ) . '" ' . (in_array( $cat->term_id, $inc_cats ) ? 'selected="selected"' : '') . '>' . esc_html( $cat->name ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Exclude Categories</label></th>
                                    <td>
                                        <select class="wddrm-category-search" multiple="multiple" name="exc_categories[]" data-placeholder="Select categories..." style="width:100%; max-width: 400px;">
                                            <?php
                                            $exc_cats = $filt_data['excluded_categories'] ?? [];
                                            foreach ( $all_categories as $cat ) {
                                                echo '<option value="' . esc_attr( $cat->term_id ) . '" ' . (in_array( $cat->term_id, $exc_cats ) ? 'selected="selected"' : '') . '>' . esc_html( $cat->name ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </div>



                    </div><!-- /main col -->

                    <!-- Sidebar -->
                    <div class="wddrm-sidebar-col">

                        <div class="wddrm-card">
                            <h2>Publish</h2>
                            <p class="submit" style="padding:0;margin:0">
                                <button type="submit" class="button button-primary button-large" style="width:100%">
                                    <?php echo $is_edit ? 'Update Rule' : 'Save Rule'; ?>
                                </button>
                            </p>
                            <p style="margin-top:10px;text-align:center">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wddrm-rules-list')); ?>">Cancel</a>
                            </p>
                        </div>

                        <div class="wddrm-card">
                            <h2>Scheduling</h2>
                            <table class="form-table">
                                <tr>
                                    <th><label for="start_date">Start</label></th>
                                    <td><input type="datetime-local" id="start_date" name="start_date"
                                        value="<?php echo esc_attr($d['start_date'] ? date('Y-m-d\TH:i',strtotime($d['start_date'])) : ''); ?>"></td>
                                </tr>
                                <tr>
                                    <th><label for="end_date">End</label></th>
                                    <td><input type="datetime-local" id="end_date" name="end_date"
                                        value="<?php echo esc_attr($d['end_date'] ? date('Y-m-d\TH:i',strtotime($d['end_date'])) : ''); ?>"></td>
                                </tr>
                            </table>
                        </div>

                        <div class="wddrm-card">
                            <h2>Usage Limits</h2>
                            <table class="form-table">
                                <tr>
                                    <th><label>Total uses</label></th>
                                    <td><input type="number" name="usage_limit_per_rule" value="<?php echo esc_attr($d['usage_limit_per_rule']??''); ?>" min="0" class="small-text">
                                    <p class="description">Blank = unlimited</p></td>
                                </tr>
                                <tr>
                                    <th><label>Per user</label></th>
                                    <td><input type="number" name="usage_limit_per_user" value="<?php echo esc_attr($d['usage_limit_per_user']??''); ?>" min="0" class="small-text"></td>
                                </tr>
                                <tr>
                                     <th><label>Required Coupon</label></th>
                                     <td>
                                         <input type="text" name="required_coupon" value="<?php echo esc_attr($cond_data['required_coupon']??''); ?>" style="max-width:150px">
                                         <p class="description">Only apply if this coupon code is applied in cart.</p>
                                         <?php if ( ! empty($cond_data['required_coupon']) ) : 
                                             $share_url = add_query_arg('wddrm_coupon', $cond_data['required_coupon'], home_url('/'));
                                         ?>
                                             <p style="margin-top:8px;"><strong>Shareable Link:</strong><br>
                                             <code style="word-break: break-all;"><?php echo esc_url($share_url); ?></code></p>
                                         <?php endif; ?>
                                     </td>
                                 </tr>
                            </table>
                        </div>

                    </div><!-- /sidebar -->
                </div><!-- /flex -->
            </form>
        </div>
        <?php
    }

    private function render_condition_row( int $i, array $g = [] ) {
        $condition_types = [
            'cart_subtotal'    => 'Cart Subtotal',
            'cart_total'       => 'Cart Total',
            'cart_quantity'    => 'Cart Quantity',
            'cart_item_count'  => 'Cart Item Count (unique)',
            'product_in_cart'  => 'Product in Cart (IDs)',
            'category_in_cart' => 'Category in Cart (IDs)',
            'user_role'        => 'User Role',
            'user_id'          => 'User ID',
            'is_logged_in'     => 'User is Logged In',
            'order_count'      => 'Total Orders Placed',
            'first_order'      => 'First Order (no previous orders)',
            'total_spent'      => 'Total Spent (lifetime)',
            'bought_product'   => 'Has Bought Product (IDs)',
            'billing_country'  => 'Billing Country (code)',
            'shipping_country' => 'Shipping Country (code)',
            'billing_state'    => 'Billing State (code)',
            'shipping_state'   => 'Shipping State (code)',
            'shipping_method'  => 'Shipping Method',
            'payment_method'   => 'Payment Method',
        ];
        $operators = ['>='=>'≥','<='=>'≤','>'=>'>','<'=>'<','='=>'=','!='=>'≠'];
        ?>
        <div class="wddrm-cond-inner" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
            <select name="cond[<?php echo $i; ?>][type]" class="wddrm-cond-type" style="flex:2;min-width:180px">
                <?php foreach ($condition_types as $cv => $cl): ?>
                <option value="<?php echo esc_attr($cv); ?>" <?php selected($g['type']??'',$cv); ?>><?php echo esc_html($cl); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="cond[<?php echo $i; ?>][operator]" style="width:60px">
                <?php foreach ($operators as $ov => $ol): ?>
                <option value="<?php echo esc_attr($ov); ?>" <?php selected($g['operator']??'>=',$ov); ?>><?php echo esc_html($ol); ?></option>
                <?php endforeach; ?>
            </select>
            <?php
            $ctype = $g['type'] ?? '';
            $is_product = ( $ctype === 'product_in_cart' || $ctype === 'bought_product' );
            $is_category = ( $ctype === 'category_in_cart' );
            $val_ids = is_array( $g['value'] ?? null ) ? $g['value'] : array_filter( array_map( 'absint', explode( ',', $g['value'] ?? '' ) ) );

            if ( $is_product ) : ?>
                <select class="wc-product-search" multiple="multiple" name="cond[<?php echo $i; ?>][value][]" data-placeholder="Search for products..." data-action="woocommerce_json_search_products_and_variations" style="flex:2;min-width:180px">
                    <?php
                    foreach ( $val_ids as $prod_id ) {
                        $prod = wc_get_product( $prod_id );
                        if ( $prod ) {
                            echo '<option value="' . esc_attr( $prod_id ) . '" selected="selected">' . esc_html( $prod->get_formatted_name() ) . '</option>';
                        }
                    }
                    ?>
                </select>
            <?php elseif ( $is_category ) : ?>
                <select class="wddrm-category-search" multiple="multiple" name="cond[<?php echo $i; ?>][value][]" data-placeholder="Select categories..." style="flex:2;min-width:180px">
                    <?php
                    $all_categories = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => false] );
                    if ( ! is_wp_error( $all_categories ) && ! empty( $all_categories ) ) {
                        foreach ( $all_categories as $cat ) {
                            echo '<option value="' . esc_attr( $cat->term_id ) . '" ' . selected( in_array( $cat->term_id, $val_ids ), true, false ) . '>' . esc_html( $cat->name ) . '</option>';
                        }
                    }
                    ?>
                </select>
            <?php else : ?>
                <input type="text" name="cond[<?php echo $i; ?>][value]" value="<?php echo esc_attr( is_array( $g['value'] ?? null ) ? implode( ',', $g['value'] ) : ( $g['value'] ?? '' ) ); ?>" style="flex:2;min-width:120px" placeholder="value">
            <?php endif; ?>
            <button type="button" class="button wddrm-remove-condition" style="flex-shrink:0">×</button>
        </div>
        <?php
    }

    /* ================================================================== */
    /*  POST handlers                                                       */
    /* ================================================================== */

    public function handle_save() {
        check_admin_referer('wddrm_save_rule','wddrm_nonce');
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Forbidden');

        $id   = absint($_POST['rule_id'] ?? 0);
        $type = sanitize_key($_POST['discount_type'] ?? 'bxgy_free');
        $mode = sanitize_key($_POST['disc_mode'] ?? 'percentage');

        // --- Discount data ---
        $disc = [];
        $disc['mode']       = $mode;
        $disc['amount']     = (float)($_POST['disc_amount']     ?? 0);
        $disc['percentage'] = (float)($_POST['disc_percentage'] ?? 0);
        $disc['min_amount'] = (float)($_POST['disc_min_amount'] ?? 0);
        $disc['roles']      = array_map('sanitize_key', (array)($_POST['disc_roles'] ?? []));
        $disc['customer_ids'] = array_filter(array_map('absint', explode(',', $_POST['disc_customer_ids'] ?? '')));
        $bundle_raw = $_POST['bundle_products'] ?? [];
        $disc['bundle_products'] = is_array($bundle_raw) ? array_filter(array_map('absint', $bundle_raw)) : array_filter(array_map('absint', explode(',', $bundle_raw)));
        $disc['bundle_discount_pct']  = (float)($_POST['bundle_discount_pct']  ?? 0);
        $disc['bundle_discount_fixed']= (float)($_POST['bundle_discount_fixed']?? 0);
        $disc['value'] = $mode === 'percentage' ? $disc['percentage'] : $disc['amount'];

        // Location (Countries & States)
        $countries_raw = $_POST['disc_countries'] ?? '';
        $disc['countries'] = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $countries_raw ) ) ) ) );

        $states_raw = $_POST['disc_states'] ?? '';
        $disc['states'] = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $states_raw ) ) ) ) );

        // Tiers
        if ( ! empty($_POST['tiers']) ) {
            foreach ($_POST['tiers'] as $tier) {
                $disc['tiers'][] = ['min_qty'=>absint($tier['min_qty']),'discount_pct'=>(float)$tier['discount_pct']];
            }
        }

        // --- BxGY data ---
        $bxgy = [];
        $bxgy['buy_quantity']       = absint($_POST['bxgy_buy_qty'] ?? 1);
        $bxgy['get_quantity']       = absint($_POST['bxgy_get_qty'] ?? 1);
        $bxgy_buy_prod_raw = $_POST['bxgy_buy_products'] ?? [];
        $bxgy['buy_products'] = is_array($bxgy_buy_prod_raw) ? array_filter(array_map('absint', $bxgy_buy_prod_raw)) : array_filter(array_map('absint', explode(',', $bxgy_buy_prod_raw)));
        $bxgy_get_prod_raw = $_POST['bxgy_get_products'] ?? [];
        $bxgy['get_products'] = is_array($bxgy_get_prod_raw) ? array_filter(array_map('absint', $bxgy_get_prod_raw)) : array_filter(array_map('absint', explode(',', $bxgy_get_prod_raw)));
        $bxgy_buy_cats_raw = $_POST['bxgy_buy_cats'] ?? [];
        $bxgy['buy_categories'] = is_array($bxgy_buy_cats_raw) ? array_filter(array_map('absint', $bxgy_buy_cats_raw)) : array_filter(array_map('absint', explode(',', $bxgy_buy_cats_raw)));
        $bxgy_get_cats_raw = $_POST['bxgy_get_cats'] ?? [];
        $bxgy['get_categories'] = is_array($bxgy_get_cats_raw) ? array_filter(array_map('absint', $bxgy_get_cats_raw)) : array_filter(array_map('absint', explode(',', $bxgy_get_cats_raw)));
        $bxgy['discount_type']      = sanitize_key($_POST['bxgy_discount_type'] ?? 'percentage');
        $bxgy['target_mode']        = sanitize_key($_POST['bxgy_target_mode'] ?? 'specific');
        $bxgy['auto_add']           = isset($_POST['bxgy_auto_add']) ? 1 : 0;
        $bxgy_val = (float)($_POST['bxgy_disc_value'] ?? $_POST['bxgy_disc_pct'] ?? 100);
        $bxgy['discount_value']      = $bxgy_val;
        // Fallback for backwards compatibility:
        $bxgy['discount_percentage'] = $bxgy['discount_type'] === 'percentage' ? $bxgy_val : 100;
        if ( ! empty($_POST['bxgy_tiers']) ) {
            foreach ($_POST['bxgy_tiers'] as $rt) {
                $tier_type = sanitize_key($rt['type'] ?? 'pct');
                $entry = ['min_qty'=>absint($rt['min_qty']),'type'=>$tier_type];
                if ($tier_type==='fixed') $entry['amount']     = (float)$rt['value'];
                else                      $entry['percentage'] = (float)$rt['value'];
                $bxgy['tiers'][] = $entry;
            }
        }

        // --- Filters ---
        $inc_prod_raw = $_POST['inc_products'] ?? [];
        $exc_prod_raw = $_POST['exc_products'] ?? [];
        $inc_cat_raw  = $_POST['inc_categories'] ?? [];
        $exc_cat_raw  = $_POST['exc_categories'] ?? [];

        $filter = [
            'included_products'   => is_array($inc_prod_raw) ? array_values(array_filter(array_map('absint', $inc_prod_raw))) : array_values(array_filter(array_map('absint', explode(',', $inc_prod_raw)))),
            'excluded_products'   => is_array($exc_prod_raw) ? array_values(array_filter(array_map('absint', $exc_prod_raw))) : array_values(array_filter(array_map('absint', explode(',', $exc_prod_raw)))),
            'categories'          => is_array($inc_cat_raw)  ? array_values(array_filter(array_map('absint', $inc_cat_raw)))  : array_values(array_filter(array_map('absint', explode(',', $inc_cat_raw)))),
            'excluded_categories' => is_array($exc_cat_raw)  ? array_values(array_filter(array_map('absint', $exc_cat_raw)))  : array_values(array_filter(array_map('absint', explode(',', $exc_cat_raw)))),
        ];

        // --- Conditions ---
        $cond = ['operator' => sanitize_key($_POST['cond_operator'] ?? 'AND'), 'groups' => []];
        $cond['required_coupon'] = sanitize_text_field($_POST['required_coupon'] ?? '');
        foreach ( (array)($_POST['cond'] ?? []) as $g ) {
            $ctype = sanitize_key($g['type'] ?? '');
            $cop   = sanitize_key($g['operator'] ?? '>=');
            $cval  = $g['value'] ?? '';
            // Multi-value types: split comma list
            $multi = ['product_in_cart','category_in_cart','user_role','user_id','bought_product',
                      'billing_country','shipping_country','billing_state','shipping_state',
                      'shipping_method','payment_method'];
            if ( in_array($ctype,$multi,true) ) {
                if ( is_array($cval) ) {
                    $cval = array_values(array_filter(array_map('sanitize_text_field', $cval)));
                } else {
                    $cval = array_values(array_filter(array_map('sanitize_text_field', explode(',', $cval))));
                }
            } else {
                $cval = sanitize_text_field($cval);
            }
            if ($ctype) $cond['groups'][] = ['type'=>$ctype,'operator'=>$cop,'value'=>$cval];
        }

        $data = [
            'id'                   => $id ?: null,
            'name'                 => sanitize_text_field($_POST['rule_name'] ?? ''),
            'rule_group'           => sanitize_key($_POST['rule_group'] ?? 'cart'),
            'status'               => (int)($_POST['rule_status'] ?? 1),
            'priority'             => (int)($_POST['rule_priority'] ?? 10),
            'stop_further_rules'   => isset($_POST['stop_further_rules']) ? 1 : 0,
            'discount_type'        => $type,
            'discount_data'        => $disc,
            'filter_data'          => $filter,
            'condition_data'       => $cond,
            'bxgy_data'            => $bxgy,
            'start_date'           => $_POST['start_date'] ? date('Y-m-d H:i:s', strtotime($_POST['start_date'])) : null,
            'end_date'             => $_POST['end_date']   ? date('Y-m-d H:i:s', strtotime($_POST['end_date']))   : null,
            'usage_limit_per_rule' => $_POST['usage_limit_per_rule'] !== '' ? absint($_POST['usage_limit_per_rule']) : null,
            'usage_limit_per_user' => $_POST['usage_limit_per_user'] !== '' ? absint($_POST['usage_limit_per_user']) : null,
            'created_by'           => get_current_user_id(),
        ];

        $this->repo->save($data);
        wp_redirect(admin_url('admin.php?page=wddrm-rules-list&msg=saved'));
        exit;
    }

    public function handle_delete() {
        check_admin_referer('wddrm_delete_rule','wddrm_nonce');
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Forbidden');
        $id = absint($_POST['rule_id'] ?? 0);
        if ($id) $this->repo->delete($id);
        wp_redirect(admin_url('admin.php?page=wddrm-rules-list&msg=deleted'));
        exit;
    }

    public function handle_toggle() {
        check_admin_referer('wddrm_toggle_rule','wddrm_nonce');
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Forbidden');
        $id   = absint($_POST['rule_id'] ?? 0);
        $rule = $this->repo->get($id);
        if ($rule) $this->repo->set_status($id, $rule['status'] ? 0 : 1);
        wp_redirect(admin_url('admin.php?page=wddrm-rules-list&msg=toggled'));
        exit;
    }

    /* ── Helpers ── */
    private function get_type_label( string $type ): string {
        foreach (self::TYPES as $gtypes) {
            if (isset($gtypes[$type])) return $gtypes[$type];
        }
        return $type;
    }

    private function discount_summary( array $rule ): string {
        $d    = json_decode($rule['discount_data'], true) ?? [];
        $type = $rule['discount_type'];
        $cur  = get_woocommerce_currency_symbol();
        if (strpos($type,'percentage') !== false || strpos($type,'pct') !== false ) return ($d['percentage']??0).'%';
        if (strpos($type,'fixed')      !== false                                   ) return $cur.($d['amount']??0);
        if (strpos($type,'bxgy')       !== false) {
            $b = json_decode($rule['bxgy_data']??'{}',true) ?? [];
            return 'Buy '.($b['buy_quantity']??1).' Get '.($b['get_quantity']??1).' – '.($b['discount_percentage']??100).'% off';
        }
        if (strpos($type,'role')      !==false||strpos($type,'pricing')!==false||strpos($type,'wholesale')!==false) {
            return ($d['mode']==='fixed' ? $cur.($d['amount']??0) : ($d['percentage']??0).'%').' off';
        }
        if (!empty($d['tiers'])) return count($d['tiers']).' tier(s)';
        return '—';
    }
}
