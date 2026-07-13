/* Woo Dynamic Discount Rules – Admin JS v2.0 */
jQuery(function ($) {

    /* ── Type-to-visible-fields map ── */
    var TYPE_FIELDS = {
        // fixed amount
        disc_fixed:     ['cart_fixed','product_fixed','category_fixed','first_order',
                         'country_rule','state_rule','shipping_method_rule','payment_method_rule','scheduled'],
        // percentage
        disc_pct:       ['cart_percentage','product_percentage','category_percentage',
                         'repeat_customer','purchase_history','scheduled',
                         'cart_total','cart_subtotal'],
        // mode selector (pct or fixed) used by user/pricing types
        disc_mode:      ['role_pricing','customer_pricing','vip_pricing','wholesale_pricing',
                         'country_rule','state_rule','shipping_method_rule','payment_method_rule','scheduled'],
        // roles checkboxes
        disc_roles:     ['role_pricing','vip_pricing','wholesale_pricing'],
        // customer IDs
        disc_customers: ['customer_pricing'],
        // min amount
        disc_minamount: ['cart_total','cart_subtotal'],
        // tier rows
        disc_tiers:     ['cart_quantity','category_quantity'],
        // bundle
        disc_bundle:    ['product_bundle'],
        // countries
        disc_countries: ['country_rule'],
        // states
        disc_states:    ['state_rule'],
        // bxgy card
        disc_bxgy:      ['bxgy_free','bxgy_discount','bxgy_product','bxgy_category','bxgy_quantity_reward'],
    };

    function getAllFields() {
        var all = [];
        $.each(TYPE_FIELDS, function(_, types) { all = all.concat(types); });
        return all.filter(function(v,i,a){ return a.indexOf(v)===i; });
    }

    function updateVisibility() {
        var type = $('#discount_type').val();

        // Hide all conditional rows first
        $('.disc-field').removeClass('visible');

        // Show rows whose data-types contains current type
        $('.disc-field').each(function() {
            var types = ($(this).data('types') || '').split(',').map(function(t){ return t.trim(); });
            if (types.indexOf(type) !== -1) {
                $(this).addClass('visible');
            }
        });

        // Also sync rule_group select to match the type's natural group
        var groupMap = {
            product_fixed:'product', product_percentage:'product', product_bundle:'product',
            product_specific:'product', product_variation:'product',
            category_percentage:'category', category_fixed:'category',
            category_quantity:'category', multi_category:'category',
            cart_fixed:'cart', cart_percentage:'cart', cart_quantity:'cart',
            cart_total:'cart', cart_subtotal:'cart',
            bxgy_free:'bxgy', bxgy_discount:'bxgy', bxgy_product:'bxgy',
            bxgy_category:'bxgy', bxgy_quantity_reward:'bxgy',
            role_pricing:'user', customer_pricing:'user', vip_pricing:'user', wholesale_pricing:'user',
            first_order:'advanced', repeat_customer:'advanced', purchase_history:'advanced',
            scheduled:'advanced', country_rule:'advanced', state_rule:'advanced',
            shipping_method_rule:'advanced', payment_method_rule:'advanced',
        };
        if (groupMap[type]) $('#rule_group').val(groupMap[type]);

        // Pricing mode: show both amount + pct fields if mode selector visible
        if ($('.disc-mode.visible').length) {
            syncPricingMode();
        }

        // BxGY Target Mode field syncing
        syncBxgyTargetMode();
    }

    function syncPricingMode() {
        var mode = $('[name="disc_mode"]').val();
        if (mode === 'fixed') {
            $('.disc-pct').removeClass('visible');
            $('.disc-fixed').addClass('visible');
        } else {
            $('.disc-fixed').removeClass('visible');
            $('.disc-pct').addClass('visible');
        }
    }

    $('#discount_type').on('change', updateVisibility);
    $('[name="disc_mode"]').on('change', syncPricingMode);
    updateVisibility();

    /* ── Quantity tier add/remove ── */
    var tierIndex = $('#quantity-tiers .wddrm-tier-row').length;

    $(document).on('click', '#wddrm-add-tier', function(e) {
        e.preventDefault();
        console.log("Quantity tier added: Index " + tierIndex);
        var html = '<div class="wddrm-tier-row">'
            + 'Min qty: <input type="number" name="tiers[' + tierIndex + '][min_qty]" value="1" min="1" class="small-text">'
            + '&nbsp; Discount %: <input type="number" name="tiers[' + tierIndex + '][discount_pct]" value="0" min="0" max="100" step="0.01" class="small-text">'
            + ' <button type="button" class="button wddrm-remove-tier">×</button>'
            + '</div>';
        $('#quantity-tiers').append(html);
        tierIndex++;
    });

    /* ── Reward tier add/remove ── */
    var rewardIndex = $('#bxgy-reward-tiers .wddrm-tier-row').length;

    $(document).on('click', '#wddrm-add-reward-tier', function(e) {
        e.preventDefault();
        console.log("Reward tier added: Index " + rewardIndex);
        var html = '<div class="wddrm-tier-row">'
            + 'Min qty: <input type="number" name="bxgy_tiers[' + rewardIndex + '][min_qty]" value="1" min="1" class="small-text">'
            + '&nbsp; Type: <select name="bxgy_tiers[' + rewardIndex + '][type]"><option value="pct">%</option><option value="fixed">Fixed</option></select>'
            + '&nbsp; Value: <input type="number" name="bxgy_tiers[' + rewardIndex + '][value]" value="0" min="0" step="0.01" class="small-text">'
            + ' <button type="button" class="button wddrm-remove-tier">×</button>'
            + '</div>';
        $('#bxgy-reward-tiers').append(html);
        rewardIndex++;
    });

    /* ── Remove tier (delegated) ── */
    $(document).on('click', '.wddrm-remove-tier', function() {
        $(this).closest('.wddrm-tier-row').remove();
    });

    /* ── Conditions ── */
    var condIndex = $('#wddrm-conditions .wddrm-cond-row').length;

    var condTypes = {
        cart_subtotal:'Cart Subtotal', cart_total:'Cart Total',
        cart_quantity:'Cart Quantity', cart_item_count:'Cart Item Count',
        product_in_cart:'Product in Cart (IDs)', category_in_cart:'Category in Cart (IDs)',
        user_role:'User Role', user_id:'User ID', is_logged_in:'Is Logged In',
        order_count:'Total Orders', first_order:'First Order',
        total_spent:'Total Spent', bought_product:'Has Bought Product',
        billing_country:'Billing Country', shipping_country:'Shipping Country',
        billing_state:'Billing State', shipping_state:'Shipping State',
        shipping_method:'Shipping Method', payment_method:'Payment Method'
    };
    var condOps = {'>=':'≥','<=':'≤','>':'>','<':'<','=':'=','!=':'≠'};

    function buildCondRow(i) {
        var typeOpts = $.map(condTypes, function(label, val) {
            return '<option value="'+val+'">'+label+'</option>';
        }).join('');
        var opOpts = $.map(condOps, function(label, val) {
            return '<option value="'+val+'">'+label+'</option>';
        }).join('');
        return '<div class="wddrm-cond-row"><div class="wddrm-cond-inner" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">'
            + '<select name="cond['+i+'][type]" class="wddrm-cond-type" style="flex:2;min-width:180px">'+typeOpts+'</select>'
            + '<select name="cond['+i+'][operator]" style="width:60px">'+opOpts+'</select>'
            + '<input type="text" name="cond['+i+'][value]" style="flex:2;min-width:120px" placeholder="value">'
            + '<button type="button" class="button wddrm-remove-condition" style="flex-shrink:0">×</button>'
            + '</div></div>';
    }

    function wddrmSelect2($el, arg) {
        if (typeof $.fn.selectWoo === 'function') {
            return $el.selectWoo(arg);
        } else if (typeof $.fn.select2 === 'function') {
            return $el.select2(arg);
        }
        return $el;
    }

    function initSelectFields() {
        // Trigger WC product search select2 init
        $(document.body).trigger('wc-enhanced-select-init');

        // Init category search select2 fields
        $('.wddrm-category-search').each(function() {
            var $el = $(this);
            if (!$el.hasClass('select2-hidden-accessible')) {
                wddrmSelect2($el, {
                    placeholder: $el.data('placeholder') || 'Select options...',
                    allowClear: true
                });
            }
        });
    }

    $('#wddrm-add-condition').on('click', function() {
        $('#wddrm-conditions').append(buildCondRow(condIndex));
        condIndex++;
        initSelectFields();
    });

    $(document).on('click', '.wddrm-remove-condition', function() {
        $(this).closest('.wddrm-cond-row').remove();
    });

    $(document).on('change', '.wddrm-cond-type', function() {
        var $row = $(this).closest('.wddrm-cond-inner');
        var type = $(this).val();
        var nameAttr = $(this).attr('name');
        var index = nameAttr.match(/\d+/)[0];
        
        var $valueContainer = $row.find('[name^="cond[' + index + '][value]"]').first();
        if ($valueContainer.length === 0) {
            $valueContainer = $row.find('input, select').filter(function() {
                return ($(this).attr('name') || '').indexOf('cond[' + index + '][value]') !== -1;
            }).first();
        }

        if ($valueContainer.hasClass('select2-hidden-accessible')) {
            wddrmSelect2($valueContainer, 'destroy');
        }

        var isProduct = (type === 'product_in_cart' || type === 'bought_product');
        var isCategory = (type === 'category_in_cart');

        var $newField;
        if (isProduct) {
            $newField = $('<select class="wc-product-search" multiple="multiple" name="cond[' + index + '][value][]" data-placeholder="Search for products..." data-action="woocommerce_json_search_products_and_variations" style="flex:2;min-width:180px"></select>');
        } else if (isCategory) {
            $newField = $('<select class="wddrm-category-search" multiple="multiple" name="cond[' + index + '][value][]" data-placeholder="Select categories..." style="flex:2;min-width:180px"></select>');
            $.each(WDDRM.categories || [], function(_, cat) {
                $newField.append('<option value="' + cat.id + '">' + cat.name + '</option>');
            });
        } else {
            $newField = $('<input type="text" name="cond[' + index + '][value]" style="flex:2;min-width:120px" placeholder="value">');
        }

        $valueContainer.replaceWith($newField);
        initSelectFields();
    });

    /* ── Form validation ── */
    $('#wddrm-rule-form').on('submit', function(e) {
        var name = $.trim($('#rule_name').val());
        if (!name) {
            alert('Please enter a rule name.');
            $('#rule_name').focus();
            e.preventDefault();
        }
    });

    /* ── BxGY Discount Type syncing ── */
    function syncBxgyDiscountType() {
        var $typeSelect = $('#bxgy_discount_type');
        if ($typeSelect.length === 0) return;
        var type = $typeSelect.val();
        var symbol = (type === 'fixed') ? (WDDRM.currency_symbol || '$') : '%';
        var desc = (type === 'fixed') ? 'Fixed discount amount on product Y.' : '100% = free. 50% = half price.';
        
        $('#bxgy-disc-symbol').text(symbol);
        $('#bxgy-disc-desc').text(desc);
    }

    /* ── BxGY Target Mode visibility syncing ── */
    function syncBxgyTargetMode() {
        var mode = $('#bxgy_target_mode').val() || 'specific';
        var type = $('#discount_type').val();
        
        if (['bxgy_free', 'bxgy_discount', 'bxgy_product', 'bxgy_category'].indexOf(type) !== -1) {
            if (mode === 'specific') {
                if (type === 'bxgy_category') {
                    $('.bxgy-get-cats-row').addClass('visible');
                    $('.bxgy-get-prods-row').removeClass('visible');
                } else {
                    $('.bxgy-get-prods-row').addClass('visible');
                    $('.bxgy-get-cats-row').removeClass('visible');
                }
                $('.bxgy-auto-add-row').addClass('visible');
            } else {
                $('.bxgy-get-prods-row').removeClass('visible');
                $('.bxgy-get-cats-row').removeClass('visible');
                $('.bxgy-auto-add-row').removeClass('visible');
            }
        }
    }

    $(document).on('change', '#bxgy_discount_type', syncBxgyDiscountType);
    $(document).on('change', '#bxgy_target_mode', syncBxgyTargetMode);
    syncBxgyDiscountType();
    syncBxgyTargetMode();

    initSelectFields();

});
