jQuery(function ($) {
    if (typeof wddrm_product_data === 'undefined') return;

    var basePrice = parseFloat(wddrm_product_data.price);
    var regPrice = parseFloat(wddrm_product_data.regular_price);
    var currency = wddrm_product_data.currency_symbol;
    var rules = wddrm_product_data.rules || [];

    var $priceContainer = $('.summary .price, .single-product .price').first();
    var originalHtml = $priceContainer.html();

    function getDiscountPct(qty) {
        var maxPct = 0;
        $.each(rules, function(_, rule) {
            var tiers = rule.tiers || [];
            $.each(tiers, function(_, tier) {
                var minQty = parseInt(tier.min_qty);
                var pct = parseFloat(tier.discount_pct);
                if (qty >= minQty && pct > maxPct) {
                    maxPct = pct;
                }
            });
        });
        return maxPct;
    }

    function updateLivePrice() {
        var qty = parseInt($('input[name="quantity"]').val()) || 1;
        var pct = getDiscountPct(qty);

        if (pct > 0) {
            var newPrice = basePrice * (1 - pct / 100);
            
            // Format price
            var formattedReg = formatPriceHtml(regPrice);
            var formattedNew = formatPriceHtml(newPrice);

            // Construct crossed out HTML
            var strikeHtml = '<del>' + formattedReg + '</del> <ins>' + formattedNew + '</ins>';
            $priceContainer.html(strikeHtml);
        } else {
            // Restore original HTML
            $priceContainer.html(originalHtml);
        }
    }

    function formatPriceHtml(amount) {
        var amt = amount.toFixed(2);
        // Match standard wc-price HTML if possible, or fallback to simple prefix/suffix based on WooCommerce settings
        return '<span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol">' + currency + '</span>' + amt + '</span>';
    }

    // Bind events
    $(document).on('change input', 'input[name="quantity"], .qty', updateLivePrice);

    // Support variation selection
    $(document).on('show_variation', '.variations_form', function(event, variation) {
        if (variation && variation.display_price) {
            basePrice = parseFloat(variation.display_price);
            regPrice = parseFloat(variation.display_regular_price || variation.display_price);
            
            // Try to extract originalHtml from variation.price_html
            if (variation.price_html) {
                originalHtml = variation.price_html;
            } else {
                originalHtml = formatPriceHtml(basePrice);
            }
            
            updateLivePrice();
        }
    });

    // Run once on load
    updateLivePrice();
});