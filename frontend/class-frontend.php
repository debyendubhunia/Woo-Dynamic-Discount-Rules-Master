<?php
namespace WooDynamicDiscountRulesMaster\Frontend;

class Frontend {

    public function init() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_filter('woocommerce_get_price_html', [$this, 'show_strikeout_price'], 10, 2);
        add_action('woocommerce_before_add_to_cart_form', [$this, 'display_quantity_table'], 15);
    }

    public function enqueue() {
        wp_enqueue_style('wddrm-frontend', WDDRM_PLUGIN_URL . 'frontend/assets/css/frontend.css', [], WDDRM_VERSION);
        wp_enqueue_script('wddrm-frontend', WDDRM_PLUGIN_URL . 'frontend/assets/js/frontend.js', ['jquery'], WDDRM_VERSION, true);

        if ( is_product() ) {
            global $product;
            if ( $product ) {
                $pid = $product->get_id();
                $cats = wp_get_post_terms( $pid, 'product_cat', ['fields' => 'ids'] );

                $repo = new \WooDynamicDiscountRulesMaster\Repository\RuleRepository();
                $rules = $repo->get_active_rules();

                $js_rules = [];

                foreach ( $rules as $rule ) {
                    $type = $rule['discount_type'];
                    if ( $type !== 'category_quantity' && $type !== 'cart_quantity' ) continue;

                    $filter = json_decode( $rule['filter_data'] ?? '{}', true ) ?? [];
                    if ( $type === 'category_quantity' && ! $this->category_matches( $cats, $filter ) ) continue;

                    $disc = json_decode( $rule['discount_data'] ?? '{}', true ) ?? [];
                    if ( ! empty( $disc['tiers'] ) ) {
                        $js_rules[] = [
                            'type' => $type,
                            'tiers' => $disc['tiers']
                        ];
                    }
                }

                wp_localize_script('wddrm-frontend', 'wddrm_product_data', [
                    'product_id'      => $pid,
                    'regular_price'   => (float)$product->get_regular_price(),
                    'price'           => (float)$product->get_price(),
                    'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
                    'rules'           => $js_rules
                ]);
            }
        }
    }

    public function show_strikeout_price( $price_html, $product ) {
        // Skip in cart, checkout, or admin
        if ( is_admin() || is_cart() || is_checkout() ) {
            return $price_html;
        }

        if ( ! $product ) return $price_html;

        // Skip variable products themselves (we handle variations individually)
        if ( $product->is_type('variable') ) {
            return $price_html;
        }

        $discounted_price = $this->get_product_discounted_price( $product );
        $regular_price = (float)$product->get_regular_price();

        if ( $discounted_price < $regular_price && $discounted_price > 0 ) {
            return wc_format_sale_price( $regular_price, $discounted_price );
        }

        return $price_html;
    }

    public function display_quantity_table() {
        global $product;
        if ( ! $product ) return;

        $pid = $product->get_id();
        $cats = wp_get_post_terms( $pid, 'product_cat', ['fields' => 'ids'] );

        $repo = new \WooDynamicDiscountRulesMaster\Repository\RuleRepository();
        $rules = $repo->get_active_rules();

        $table_tiers = [];
        $rule_name = '';

        foreach ( $rules as $rule ) {
            $type = $rule['discount_type'];
            if ( $type !== 'category_quantity' && $type !== 'cart_quantity' ) continue;

            $filter = json_decode( $rule['filter_data'] ?? '{}', true ) ?? [];
            if ( $type === 'category_quantity' && ! $this->category_matches( $cats, $filter ) ) continue;

            $disc = json_decode( $rule['discount_data'] ?? '{}', true ) ?? [];
            if ( ! empty( $disc['tiers'] ) ) {
                $table_tiers = $disc['tiers'];
                $rule_name = $rule['name'];
                break; // Use the first matching tiered rule
            }
        }

        if ( empty( $table_tiers ) ) return;

        // Sort tiers by min_qty ascending
        usort( $table_tiers, function($a, $b) {
            return (int)$a['min_qty'] <=> (int)$b['min_qty'];
        } );

        $base_price = (float)$product->get_price();

        echo '<div class="wddrm-pricing-table-container">';
        if ( ! empty( $rule_name ) ) {
            echo '<h4 class="wddrm-pricing-table-title">' . esc_html( $rule_name ) . '</h4>';
        }
        echo '<table class="wddrm-pricing-table">';
        echo '<thead><tr><th>Quantity</th><th>Discount</th><th>Price / Unit</th></tr></thead>';
        echo '<tbody>';

        // Add row for base/regular price if first tier min_qty > 1
        if ( (int)$table_tiers[0]['min_qty'] > 1 ) {
            $max_base = (int)$table_tiers[0]['min_qty'] - 1;
            $qty_range_str = ( $max_base === 1 ) ? '1' : '1 - ' . $max_base;
            echo '<tr class="wddrm-tier-base-row">';
            echo '<td>' . esc_html( $qty_range_str ) . '</td>';
            echo '<td>-</td>';
            echo '<td>' . wc_price( $base_price ) . '</td>';
            echo '</tr>';
        }

        $count = count( $table_tiers );
        for ( $i = 0; $i < $count; $i++ ) {
            $tier = $table_tiers[$i];
            $min = (int)$tier['min_qty'];
            $pct = (float)$tier['discount_pct'];

            if ( $i < $count - 1 ) {
                $max = (int)$table_tiers[$i+1]['min_qty'] - 1;
                $qty_str = $min . ' - ' . $max;
            } else {
                $qty_str = $min . '+';
            }

            $discount_str = $pct . '% Off';
            $unit_price = $base_price * ( 1 - $pct / 100 );

            echo '<tr>';
            echo '<td>' . esc_html( $qty_str ) . '</td>';
            echo '<td class="wddrm-discount-badge-cell"><span class="wddrm-discount-badge">' . esc_html( $discount_str ) . '</span></td>';
            echo '<td>' . wc_price( $unit_price ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function get_product_discounted_price( $product ) {
        $price = (float)$product->get_price();
        $regular_price = (float)$product->get_regular_price();
        if ( ! $price ) return $price;

        $repo = new \WooDynamicDiscountRulesMaster\Repository\RuleRepository();
        $rules = $repo->get_active_rules();
        $conditions = new \WooDynamicDiscountRulesMaster\Conditions\ConditionEvaluator();

        $max_discount = 0;

        foreach ( $rules as $rule ) {
            // Check required coupon first
            $cond_data = json_decode( $rule['condition_data'] ?? '{}', true );
            if ( ! empty( $cond_data['required_coupon'] ) ) {
                if ( ! WC()->cart || ! WC()->cart->has_discount( strtolower(trim($cond_data['required_coupon'])) ) ) {
                    continue;
                }
            }

            if ( ! $conditions->evaluate( $rule ) ) continue;

            $type = $rule['discount_type'];
            $disc = json_decode( $rule['discount_data'] ?? '{}', true ) ?? [];
            $filter = json_decode( $rule['filter_data'] ?? '{}', true ) ?? [];

            $pid = $product->get_id();
            $cats = wp_get_post_terms( $pid, 'product_cat', ['fields' => 'ids'] );

            $discount = 0;

            switch ( $type ) {
                case 'product_percentage':
                    if ( $this->product_matches( $pid, $filter ) ) {
                        $discount = $price * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                    }
                    break;

                case 'product_fixed':
                    if ( $this->product_matches( $pid, $filter ) ) {
                        $discount = (float)( $disc['amount'] ?? 0 );
                    }
                    break;

                case 'category_percentage':
                    if ( $this->category_matches( $cats, $filter ) ) {
                        $discount = $price * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                    }
                    break;

                case 'category_fixed':
                    if ( $this->category_matches( $cats, $filter ) ) {
                        $discount = (float)( $disc['amount'] ?? 0 );
                    }
                    break;

                case 'product_specific':
                    if ( $this->product_matches( $pid, $filter ) ) {
                        $mode = $disc['mode'] ?? 'percentage';
                        if ( $mode === 'percentage' ) {
                            $discount = $price * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                        } else {
                            $discount = (float)( $disc['amount'] ?? 0 );
                        }
                    }
                    break;

                case 'product_variation':
                    if ( $product->is_type('variation') && $this->product_matches( $pid, $filter ) ) {
                        $mode = $disc['mode'] ?? 'percentage';
                        if ( $mode === 'percentage' ) {
                            $discount = $price * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                        } else {
                            $discount = (float)( $disc['amount'] ?? 0 );
                        }
                    }
                    break;
            }

            if ( $discount > $max_discount ) {
                $max_discount = $discount;
            }

            if ( $rule['stop_further_rules'] && $discount > 0 ) break;
        }

        return max( 0, $price - $max_discount );
    }

    private function product_matches( int $product_id, array $filter ): bool {
        $inc = array_map( 'intval', $filter['included_products'] ?? [] );
        $exc = array_map( 'intval', $filter['excluded_products'] ?? [] );
        if ( ! empty( $inc ) && ! in_array( $product_id, $inc, true ) ) return false;
        if ( ! empty( $exc ) &&   in_array( $product_id, $exc, true ) ) return false;
        return true;
    }

    private function category_matches( array $product_cats, array $filter ): bool {
        $inc = array_map( 'intval', $filter['categories'] ?? [] );
        $exc = array_map( 'intval', $filter['excluded_categories'] ?? [] );
        if ( ! empty( $inc ) && ! array_intersect( $inc, $product_cats ) ) return false;
        if ( ! empty( $exc ) &&   array_intersect( $exc, $product_cats ) ) return false;
        return true;
    }
}