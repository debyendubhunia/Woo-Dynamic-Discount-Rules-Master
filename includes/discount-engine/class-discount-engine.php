<?php
namespace WooDynamicDiscountRulesMaster\DiscountEngine;

use WooDynamicDiscountRulesMaster\Repository\RuleRepository;
use WooDynamicDiscountRulesMaster\Conditions\ConditionEvaluator;

/**
 * Discount Engine — applies all rule types to the WooCommerce cart.
 *
 * Supported discount_type values:
 *
 * PRODUCT:   product_fixed | product_percentage | product_bundle | product_specific
 *            | product_variation
 * CATEGORY:  category_percentage | category_fixed | category_quantity | multi_category
 * CART:      cart_fixed | cart_percentage | cart_quantity | cart_total | cart_subtotal
 * BXGY:      bxgy_free | bxgy_discount | bxgy_product | bxgy_category | bxgy_quantity_reward
 * USER:      role_pricing | customer_pricing | vip_pricing | wholesale_pricing
 * ADVANCED:  first_order | repeat_customer | purchase_history | scheduled
 *            | country_rule | state_rule | shipping_method_rule | payment_method_rule
 */
class DiscountEngine {

    /**
     * @var RuleRepository
     */
    private $repo;

    /**
     * @var ConditionEvaluator
     */
    private $conditions;

    /**
     * @var bool
     */
    private $is_adding_bogo = false;

    /**
     * @var array
     */
    private $calculated_fees = [];

    public function __construct() {
        $this->repo       = new RuleRepository();
        $this->conditions = new ConditionEvaluator();
    }

    /* ------------------------------------------------------------------ */
    /*  Hook entry-point                                                    */
    /* ------------------------------------------------------------------ */

    public function apply_cart_discounts( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! $cart || $cart->is_empty() ) return;

        // Reset item prices to their original prices before applying rules to prevent dilution
        foreach ( $cart->get_cart() as $key => $item ) {
            if ( ! isset( $item['wddrm_original_price'] ) ) {
                if ( isset( $cart->cart_contents[ $key ] ) ) {
                    $cart->cart_contents[ $key ]['wddrm_original_price'] = (float) $item['data']->get_price();
                }
                if ( isset( $cart->items[ $key ] ) ) {
                    $cart->items[ $key ]['wddrm_original_price'] = (float) $item['data']->get_price();
                }
            } else {
                $item['data']->set_price( $item['wddrm_original_price'] );
            }
        }

        // Remove previously added fees so recalculation is clean
        $this->remove_previous_fees();

        $rules            = $this->repo->get_active_rules();
        $item_discounts   = []; // keyed by cart item key → discount amount
        $fee_discounts    = []; // label → amount
        $applied_rules    = [];

        // Calculate the true original subtotal before applying any discount rules
        $original_subtotal = 0;
        foreach ( $cart->get_cart() as $key => $item ) {
            $orig_price = isset( $item['wddrm_original_price'] ) ? $item['wddrm_original_price'] : (float) $item['data']->get_price();
            $original_subtotal += $orig_price * (int) $item['quantity'];
        }

        foreach ( $rules as $rule ) {
            if ( ! $this->conditions->evaluate( $rule ) ) continue;
            if ( ! $this->passes_usage_limits( $rule )   ) continue;

            $type = $rule['discount_type'];
            $applied_amount = 0;

            // --- Product-level discounts (adjust item prices) ---
            if ( in_array( $type, [
                'product_fixed','product_percentage','product_bundle',
                'product_specific','product_variation',
                'category_percentage','category_fixed','category_quantity','multi_category',
                'role_pricing','customer_pricing','vip_pricing','wholesale_pricing',
            ], true ) ) {
                $prev_sum = array_sum( $item_discounts );
                $this->apply_item_discounts( $rule, $item_discounts );
                $applied_amount = array_sum( $item_discounts ) - $prev_sum;

            // --- Cart-level fee discounts ---
            } elseif ( in_array( $type, [
                'cart_fixed','cart_percentage','cart_quantity','cart_total','cart_subtotal',
                'first_order','repeat_customer','purchase_history','scheduled',
                'country_rule','state_rule','shipping_method_rule','payment_method_rule',
            ], true ) ) {
                $amount = $this->calculate_cart_discount( $rule );
                if ( $amount > 0 ) {
                    $label = sanitize_text_field( $rule['name'] );
                    $fee_discounts[ $label ] = ( $fee_discounts[ $label ] ?? 0 ) + $amount;
                    $applied_amount = $amount;
                }

            // --- Buy X Get Y ---
            } elseif ( in_array( $type, [
                'bxgy_free','bxgy_discount','bxgy_product','bxgy_category','bxgy_quantity_reward',
            ], true ) ) {
                $prev_item_sum = array_sum( $item_discounts );
                $prev_fee_sum  = array_sum( $fee_discounts );
                $this->apply_bxgy( $rule, $item_discounts, $fee_discounts );
                $applied_amount = ( array_sum( $item_discounts ) - $prev_item_sum ) + ( array_sum( $fee_discounts ) - $prev_fee_sum );
            }

            if ( $applied_amount > 0 ) {
                $applied_rules[] = [
                    'rule_id'           => $rule['id'],
                    'discount_amount'   => $applied_amount,
                    'original_subtotal' => $original_subtotal,
                ];
            }

            if ( $rule['stop_further_rules'] ) break;
        }

        // Store calculated fees so they can be applied in the woocommerce_cart_calculate_fees hook
        $this->calculated_fees = $fee_discounts;

        // Apply item-level price reductions
        $this->apply_item_price_reductions( $cart, $item_discounts );

        // Apply cart-level fees immediately (mostly for unit test assertions, as WooCommerce clears them later)
        foreach ( $fee_discounts as $label => $amount ) {
            $cart->add_fee( $label, -round( $amount, 2 ), false );
        }

        // Save applied rules to WooCommerce session so checkout can associate them with the order
        if ( WC()->session ) {
            WC()->session->set( 'wddrm_applied_rules', $applied_rules );
        }
    }

    /**
     * Apply stored fee discounts during the WooCommerce calculate fees hook.
     */
    public function apply_fee_discounts( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( empty( $this->calculated_fees ) ) return;

        foreach ( $this->calculated_fees as $label => $amount ) {
            $cart->add_fee( $label, -round( $amount, 2 ), false );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Item-level discounts                                                */
    /* ------------------------------------------------------------------ */

    private function apply_item_discounts( array $rule, array &$item_discounts ) {
        $type        = $rule['discount_type'];
        $disc        = json_decode( $rule['discount_data'],  true ) ?? [];
        $filter      = json_decode( $rule['filter_data'],    true ) ?? [];
        $cart        = WC()->cart;

        $bundle_qty_met  = false;
        $bundle_total    = 0;
        $bundle_items    = [];

        foreach ( $cart->get_cart() as $key => $item ) {
            $product_id   = (int) $item['product_id'];
            $variation_id = (int) $item['variation_id'];
            $qty          = (int) $item['quantity'];
            $price        = (float) $item['data']->get_price();
            $cats         = wp_get_post_terms( $product_id, 'product_cat', ['fields'=>'ids'] );

            switch ( $type ) {

                case 'product_fixed':
                    if ( $this->product_matches( $product_id, $filter ) ) {
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + (float)( $disc['amount'] ?? 0 ) * $qty;
                    }
                    break;

                case 'product_percentage':
                    if ( $this->product_matches( $product_id, $filter ) ) {
                        $pct = (float)( $disc['percentage'] ?? 0 ) / 100;
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + $price * $pct * $qty;
                    }
                    break;

                case 'product_specific':
                    // discount_data: { products: { "ID": amount_or_pct }, mode: 'fixed'|'percentage' }
                    $mode     = $disc['mode'] ?? 'fixed';
                    $products = $disc['products'] ?? [];
                    if ( isset( $products[ $product_id ] ) ) {
                        $d = (float) $products[ $product_id ];
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + ( $mode === 'percentage' ? $price * $d / 100 : $d ) * $qty;
                    }
                    break;

                case 'product_variation':
                    $variations = $disc['variations'] ?? []; // { "variation_id": amount }
                    $vid = $variation_id ?: $product_id;
                    if ( isset( $variations[ $vid ] ) ) {
                        $d = (float) $variations[ $vid ];
                        $mode = $disc['mode'] ?? 'fixed';
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + ( $mode === 'percentage' ? $price * $d / 100 : $d ) * $qty;
                    }
                    break;

                case 'product_bundle':
                    // Collect items matching bundle product list; apply after loop
                    $bundle_prods = $disc['bundle_products'] ?? [];
                    if ( in_array( $product_id, array_map( 'intval', $bundle_prods ), true ) ) {
                        $bundle_items[ $key ] = $item;
                        $bundle_total += $price * $qty;
                    }
                    break;

                case 'category_percentage':
                    if ( $this->category_matches( $cats, $filter ) ) {
                        $pct = (float)( $disc['percentage'] ?? 0 ) / 100;
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + $price * $pct * $qty;
                    }
                    break;

                case 'category_fixed':
                    if ( $this->category_matches( $cats, $filter ) ) {
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + (float)( $disc['amount'] ?? 0 ) * $qty;
                    }
                    break;

                case 'category_quantity':
                    // tier: discount_data.tiers = [{min_qty, discount_pct}]
                    if ( $this->category_matches( $cats, $filter ) ) {
                        $total_cat_qty = $this->qty_in_categories( array_map( 'intval', $filter['categories'] ?? [] ) );
                        $pct = $this->tier_lookup( $disc['tiers'] ?? [], $total_cat_qty );
                        if ( $pct > 0 ) {
                            $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                                + $price * ( $pct / 100 ) * $qty;
                        }
                    }
                    break;

                case 'multi_category':
                    // Each cat group can have its own %; apply highest match
                    $rules_cats = $disc['category_rules'] ?? []; // [{cats:[],pct:}]
                    foreach ( $rules_cats as $cr ) {
                        $cr_cats = array_map( 'intval', $cr['categories'] ?? [] );
                        if ( array_intersect( $cats, $cr_cats ) ) {
                            $pct = (float)( $cr['percentage'] ?? 0 ) / 100;
                            $item_discounts[ $key ] = max(
                                $item_discounts[ $key ] ?? 0,
                                $price * $pct * $qty
                            );
                        }
                    }
                    break;

                case 'role_pricing':
                case 'vip_pricing':
                case 'wholesale_pricing':
                    $user  = wp_get_current_user();
                    $roles = array_map( 'sanitize_key', $disc['roles'] ?? [] );
                    if ( array_intersect( $roles, (array) $user->roles ) ) {
                        $mode = $disc['mode'] ?? 'percentage';
                        $d    = (float)( $disc['value'] ?? 0 );
                        if ( $this->product_or_cat_matches( $product_id, $cats, $filter ) ) {
                            $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                                + ( $mode === 'percentage' ? $price * $d / 100 : $d ) * $qty;
                        }
                    }
                    break;

                case 'customer_pricing':
                    $cust_ids = array_map( 'absint', $disc['customer_ids'] ?? [] );
                    if ( in_array( get_current_user_id(), $cust_ids, true ) ) {
                        $mode = $disc['mode'] ?? 'percentage';
                        $d    = (float)( $disc['value'] ?? 0 );
                        if ( $this->product_or_cat_matches( $product_id, $cats, $filter ) ) {
                            $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                                + ( $mode === 'percentage' ? $price * $d / 100 : $d ) * $qty;
                        }
                    }
                    break;
            }
        }

        // Bundle post-processing: if all bundle products present, apply bundle discount
        if ( $type === 'product_bundle' && ! empty( $bundle_items ) ) {
            $required = count( $disc['bundle_products'] ?? [] );
            $found    = count( $bundle_items );
            if ( $found >= $required ) {
                $pct = (float)( $disc['bundle_discount_pct'] ?? 0 );
                $fixed = (float)( $disc['bundle_discount_fixed'] ?? 0 );
                foreach ( $bundle_items as $key => $item ) {
                    $price = (float) $item['data']->get_price();
                    $qty   = (int)   $item['quantity'];
                    if ( $pct   > 0 ) $item_discounts[$key] = ($item_discounts[$key] ?? 0) + $price * $pct / 100 * $qty;
                    if ( $fixed > 0 ) $item_discounts[$key] = ($item_discounts[$key] ?? 0) + $fixed * $qty;
                }
            }
        }
    }

    private function apply_item_price_reductions( $cart, array $item_discounts ) {
        foreach ( $cart->get_cart() as $key => $item ) {
            if ( empty( $item_discounts[ $key ] ) ) continue;
            $original = (float) $item['data']->get_price();
            $qty      = (int)   $item['quantity'];
            $disc_per_unit = $item_discounts[ $key ] / max( $qty, 1 );
            $new_price     = max( 0, $original - $disc_per_unit );
            $item['data']->set_price( $new_price );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Cart-level discounts                                                */
    /* ------------------------------------------------------------------ */

    private function calculate_cart_discount( array $rule ): float {
        $type = $rule['discount_type'];
        $disc = json_decode( $rule['discount_data'], true ) ?? [];
        $cart = WC()->cart;

        switch ( $type ) {

            case 'cart_fixed':
            case 'shipping_method_rule':
            case 'payment_method_rule':
                $mode = $disc['mode'] ?? 'fixed';
                if ( $mode === 'percentage' ) {
                    return $cart->get_subtotal() * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                }
                return (float)( $disc['amount'] ?? 0 );

            case 'first_order':
                $order_count = 0;
                $user_id = get_current_user_id();
                if ( $user_id > 0 ) {
                    $order_count = wc_get_customer_order_count( $user_id );
                } else {
                    $email = '';
                    if ( $cart && $cart->get_customer() ) {
                        $email = $cart->get_customer()->get_billing_email();
                    }
                    if ( ! empty( $email ) ) {
                        $order_count = count( wc_get_orders( [
                            'billing_email' => $email,
                            'limit'         => 1,
                            'return'        => 'ids',
                        ] ) );
                    }
                }
                if ( $order_count > 0 ) return 0;

                $mode = $disc['mode'] ?? 'fixed';
                if ( $mode === 'percentage' ) {
                    return $cart->get_subtotal() * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                }
                return (float)( $disc['amount'] ?? 0 );

            case 'country_rule':
                $target_countries = array_map( 'strtoupper', $disc['countries'] ?? [] );
                if ( empty( $target_countries ) ) return 0;
                $shipping_country = '';
                if ( $cart && $cart->get_customer() ) {
                    $shipping_country = strtoupper( $cart->get_customer()->get_shipping_country() );
                    if ( empty( $shipping_country ) ) {
                        $shipping_country = strtoupper( $cart->get_customer()->get_billing_country() );
                    }
                }
                if ( ! in_array( $shipping_country, $target_countries, true ) ) return 0;

                $mode = $disc['mode'] ?? 'fixed';
                if ( $mode === 'percentage' ) {
                    return $cart->get_subtotal() * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                }
                return (float)( $disc['amount'] ?? 0 );

            case 'state_rule':
                $target_states = array_map( 'strtoupper', $disc['states'] ?? [] );
                if ( empty( $target_states ) ) return 0;
                $shipping_state = '';
                if ( $cart && $cart->get_customer() ) {
                    $shipping_state = strtoupper( $cart->get_customer()->get_shipping_state() );
                    if ( empty( $shipping_state ) ) {
                        $shipping_state = strtoupper( $cart->get_customer()->get_billing_state() );
                    }
                }
                if ( ! in_array( $shipping_state, $target_states, true ) ) return 0;

                $mode = $disc['mode'] ?? 'fixed';
                if ( $mode === 'percentage' ) {
                    return $cart->get_subtotal() * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                }
                return (float)( $disc['amount'] ?? 0 );

            case 'cart_percentage':
            case 'repeat_customer':
            case 'purchase_history':
                $pct = (float)( $disc['percentage'] ?? 0 );
                return $cart->get_subtotal() * ( $pct / 100 );

            case 'scheduled':
                $mode = $disc['mode'] ?? 'percentage';
                if ( $mode === 'percentage' ) {
                    return $cart->get_subtotal() * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
                }
                return (float)( $disc['amount'] ?? 0 );

            case 'cart_quantity':
                $qty  = $cart->get_cart_contents_count();
                $pct  = $this->tier_lookup( $disc['tiers'] ?? [], $qty );
                return $cart->get_subtotal() * ( $pct / 100 );

            case 'cart_total':
            case 'cart_subtotal':
                $sub  = $cart->get_subtotal();
                $min  = (float)( $disc['min_amount'] ?? 0 );
                if ( $sub < $min ) return 0;
                $mode = $disc['mode'] ?? 'percentage';
                if ( $mode === 'fixed'      ) return (float)( $disc['amount'] ?? 0 );
                return $sub * ( (float)( $disc['percentage'] ?? 0 ) / 100 );
        }

        return 0;
    }

    /* ------------------------------------------------------------------ */
    /*  Buy X Get Y                                                         */
    /* ------------------------------------------------------------------ */

    private function apply_bxgy( array $rule, array &$item_discounts, array &$fee_discounts ) {
        $type = $rule['discount_type'];
        $bxgy = json_decode( $rule['bxgy_data'] ?? '{}', true ) ?? [];
        $cart = WC()->cart;
        $items = $cart->get_cart();

        $buy_qty   = (int)( $bxgy['buy_quantity']  ?? 1 );
        $get_qty   = (int)( $bxgy['get_quantity']  ?? 1 );
        $buy_prods = array_map( 'intval', $bxgy['buy_products']  ?? [] );
        $get_prods = array_map( 'intval', $bxgy['get_products']  ?? [] );
        $buy_cats  = array_map( 'intval', $bxgy['buy_categories'] ?? [] );
        $get_cats  = array_map( 'intval', $bxgy['get_categories'] ?? [] );
        $disc_pct  = (float)( $bxgy['discount_percentage'] ?? 100 ); // 100 = free
        $disc_type = $bxgy['discount_type'] ?? 'percentage';
        $disc_val  = (float)( $bxgy['discount_value'] ?? $disc_pct );

        $target_mode = $bxgy['target_mode'] ?? 'specific';

        switch ( $type ) {

            case 'bxgy_free':
            case 'bxgy_discount':
            case 'bxgy_product':
                // Count qualifying "buy" items
                $bought = 0;
                foreach ( $items as $item ) {
                    if ( isset( $item['wddrm_auto_added'] ) ) continue; // Skip auto-added reward items
                    $pid = (int) $item['product_id'];
                    if ( empty( $buy_prods ) || in_array( $pid, $buy_prods, true ) ) {
                        $bought += (int) $item['quantity'];
                    }
                }
                if ( $bought < $buy_qty ) return;
                $sets = (int) floor( $bought / $buy_qty );

                // Apply discount to "get" items
                $remaining = $sets * $get_qty;
                $eligible_items = [];
                foreach ( $items as $key => $item ) {
                    $pid = (int) $item['product_id'];
                    if ( $target_mode === 'specific' ) {
                        if ( ! empty( $get_prods ) && ! in_array( $pid, $get_prods, true ) ) continue;
                    }
                    $eligible_items[ $key ] = $item;
                }

                // Sort if cheapest or expensive
                if ( $target_mode === 'cheapest' ) {
                    uasort( $eligible_items, function($a, $b) {
                        return (float)$a['data']->get_price() <=> (float)$b['data']->get_price();
                    } );
                } elseif ( $target_mode === 'expensive' ) {
                    uasort( $eligible_items, function($a, $b) {
                        return (float)$b['data']->get_price() <=> (float)$a['data']->get_price();
                    } );
                }

                foreach ( $eligible_items as $key => $item ) {
                    if ( $remaining <= 0 ) break;
                    $apply_qty = min( $remaining, (int) $item['quantity'] );
                    $price     = (float) $item['data']->get_price();
                    
                    if ( $disc_type === 'fixed' ) {
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + min( $price, $disc_val ) * $apply_qty;
                    } else {
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + $price * ( $disc_val / 100 ) * $apply_qty;
                    }
                    $remaining -= $apply_qty;
                }
                break;

            case 'bxgy_category':
                $bought = 0;
                foreach ( $items as $item ) {
                    if ( isset( $item['wddrm_auto_added'] ) ) continue; // Skip auto-added reward items
                    $pid  = (int) $item['product_id'];
                    $cats = wp_get_post_terms( $pid, 'product_cat', ['fields'=>'ids'] );
                    if ( empty( $buy_cats ) || array_intersect( $buy_cats, $cats ) ) {
                        $bought += (int) $item['quantity'];
                    }
                }
                if ( $bought < $buy_qty ) return;
                $sets = (int) floor( $bought / $buy_qty );
                $remaining = $sets * $get_qty;

                $eligible_items = [];
                foreach ( $items as $key => $item ) {
                    $pid  = (int) $item['product_id'];
                    $cats = wp_get_post_terms( $pid, 'product_cat', ['fields'=>'ids'] );
                    if ( $target_mode === 'specific' ) {
                        if ( ! empty( $get_cats ) && ! array_intersect( $get_cats, $cats ) ) continue;
                    }
                    $eligible_items[ $key ] = $item;
                }

                // Sort if cheapest or expensive
                if ( $target_mode === 'cheapest' ) {
                    uasort( $eligible_items, function($a, $b) {
                        return (float)$a['data']->get_price() <=> (float)$b['data']->get_price();
                    } );
                } elseif ( $target_mode === 'expensive' ) {
                    uasort( $eligible_items, function($a, $b) {
                        return (float)$b['data']->get_price() <=> (float)$a['data']->get_price();
                    } );
                }

                foreach ( $eligible_items as $key => $item ) {
                    if ( $remaining <= 0 ) break;
                    $apply_qty = min( $remaining, (int) $item['quantity'] );
                    $price     = (float) $item['data']->get_price();
                    
                    if ( $disc_type === 'fixed' ) {
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + min( $price, $disc_val ) * $apply_qty;
                    } else {
                        $item_discounts[ $key ] = ( $item_discounts[ $key ] ?? 0 )
                            + $price * ( $disc_val / 100 ) * $apply_qty;
                    }
                    $remaining -= $apply_qty;
                }
                break;

            case 'bxgy_quantity_reward':
                // Tiered: buy N+ total items, get flat/pct reward
                $total_qty = $cart->get_cart_contents_count();
                $tiers     = $bxgy['tiers'] ?? [];
                $reward    = null;
                foreach ( array_reverse( $tiers ) as $tier ) {
                    if ( $total_qty >= (int)$tier['min_qty'] ) { $reward = $tier; break; }
                }
                if ( ! $reward ) return;
                if ( ( $reward['type'] ?? 'pct' ) === 'fixed' ) {
                    $fee_discounts[ $rule['name'] ] = ( $fee_discounts[ $rule['name'] ] ?? 0 )
                        + (float)( $reward['amount'] ?? 0 );
                } else {
                    $fee_discounts[ $rule['name'] ] = ( $fee_discounts[ $rule['name'] ] ?? 0 )
                        + $cart->get_subtotal() * ( (float)( $reward['percentage'] ?? 0 ) / 100 );
                }
                break;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

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

    private function product_or_cat_matches( int $pid, array $cats, array $filter ): bool {
        if ( ! empty( $filter['included_products'] ) ) return $this->product_matches( $pid, $filter );
        if ( ! empty( $filter['categories'] )        ) return $this->category_matches( $cats, $filter );
        return true; // no filter = apply to all
    }

    private function qty_in_categories( array $cat_ids ): int {
        $qty = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            $cats = wp_get_post_terms( (int) $item['product_id'], 'product_cat', ['fields'=>'ids'] );
            if ( array_intersect( $cat_ids, $cats ) ) $qty += (int) $item['quantity'];
        }
        return $qty;
    }

    /** Pick highest matching tier for a given quantity */
    private function tier_lookup( array $tiers, int $qty ): float {
        $best = 0;
        foreach ( $tiers as $tier ) {
            if ( $qty >= (int)$tier['min_qty'] && (float)$tier['discount_pct'] > $best ) {
                $best = (float) $tier['discount_pct'];
            }
        }
        return $best;
    }

    private function passes_usage_limits( array $rule ): bool {
        if ( $rule['usage_limit_per_rule'] !== null
             && $rule['usage_count'] >= (int) $rule['usage_limit_per_rule'] ) return false;

        if ( $rule['usage_limit_per_user'] !== null && is_user_logged_in() ) {
            global $wpdb;
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT usage_count FROM {$wpdb->prefix}wddrm_user_rule_tracking
                 WHERE rule_id = %d AND user_id = %d",
                $rule['id'], get_current_user_id()
            ) );
            if ( $count >= (int) $rule['usage_limit_per_user'] ) return false;
        }
        return true;
    }

    private function remove_previous_fees() {
        // WooCommerce clears fees before each recalculation, nothing needed here
        // but hook is available if custom cleanup is required.
    }

    public function handle_auto_add( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! $cart || $cart->is_empty() ) return;

        // Prevent recursion when calling add_to_cart / set_quantity
        if ( $this->is_adding_bogo ) return;
        $this->is_adding_bogo = true;

        $rules = $this->repo->get_active_rules();
        $auto_added_pids = []; // rule_id => [product_id => qty]

        foreach ( $rules as $rule ) {
            if ( ! $this->conditions->evaluate( $rule ) ) continue;
            if ( ! $this->passes_usage_limits( $rule )   ) continue;

            $type = $rule['discount_type'];
            if ( ! in_array( $type, ['bxgy_free', 'bxgy_discount', 'bxgy_product', 'bxgy_category'], true ) ) {
                continue;
            }

            $bxgy = json_decode( $rule['bxgy_data'] ?? '{}', true ) ?? [];
            
            // Check if auto-add is checked for this rule
            $auto_add = ! empty( $bxgy['auto_add'] );
            if ( ! $auto_add ) continue;

            // Auto-add is only supported for specific target mode
            $target_mode = $bxgy['target_mode'] ?? 'specific';
            if ( $target_mode !== 'specific' ) continue;

            $buy_qty = (int)( $bxgy['buy_quantity'] ?? 1 );
            $get_qty = (int)( $bxgy['get_quantity'] ?? 1 );
            $buy_prods = array_map( 'intval', $bxgy['buy_products']  ?? [] );
            $get_prods = array_map( 'intval', $bxgy['get_products']  ?? [] );
            $buy_cats  = array_map( 'intval', $bxgy['buy_categories'] ?? [] );
            $get_cats  = array_map( 'intval', $bxgy['get_categories'] ?? [] );

            // Calculate qualifying "buy" items in cart
            $bought = 0;
            foreach ( $cart->get_cart() as $item ) {
                if ( isset( $item['wddrm_auto_added'] ) ) continue; // Skip auto-added items themselves

                $pid = (int) $item['product_id'];
                if ( empty( $buy_prods ) || in_array( $pid, $buy_prods, true ) ) {
                    if ( empty( $buy_cats ) ) {
                        $bought += (int) $item['quantity'];
                    } else {
                        $cats = wp_get_post_terms( $pid, 'product_cat', ['fields'=>'ids'] );
                        if ( array_intersect( $buy_cats, $cats ) ) {
                            $bought += (int) $item['quantity'];
                        }
                    }
                }
            }

            if ( $bought >= $buy_qty ) {
                $sets = (int) floor( $bought / $buy_qty );
                $reward_qty = $sets * $get_qty;

                // Find product Y to add
                $target_pid = 0;
                if ( ! empty( $get_prods ) ) {
                    $target_pid = $get_prods[0];
                } elseif ( ! empty( $get_cats ) ) {
                    $prods = get_posts([
                        'post_type' => 'product',
                        'posts_per_page' => 1,
                        'fields' => 'ids',
                        'tax_query' => [[
                            'taxonomy' => 'product_cat',
                            'field' => 'term_id',
                            'terms' => $get_cats,
                        ]]
                    ]);
                    if ( ! empty( $prods ) ) {
                        $target_pid = $prods[0];
                    }
                }

                if ( $target_pid > 0 ) {
                    $auto_added_pids[$rule['id']] = [
                        'product_id' => $target_pid,
                        'quantity' => $reward_qty
                    ];
                }
            }
        }

        // Scan cart to update or remove auto-added items
        foreach ( $cart->get_cart() as $key => $item ) {
            if ( isset( $item['wddrm_auto_added'] ) ) {
                $rule_id = (int)$item['wddrm_auto_added_rule_id'];
                if ( isset( $auto_added_pids[ $rule_id ] ) && $auto_added_pids[ $rule_id ]['product_id'] === (int)$item['product_id'] ) {
                    // Sync quantity if changed
                    $target_qty = $auto_added_pids[ $rule_id ]['quantity'];
                    if ( (int)$item['quantity'] !== $target_qty ) {
                        $cart->set_quantity( $key, $target_qty, false );
                    }
                    unset( $auto_added_pids[ $rule_id ] );
                } else {
                    // No longer eligible or target changed, remove
                    $cart->remove_cart_item( $key );
                }
            }
        }

        // Add remaining new auto-added items
        foreach ( $auto_added_pids as $rule_id => $data ) {
            $cart->add_to_cart( $data['product_id'], $data['quantity'], 0, [], [
                'wddrm_auto_added' => true,
                'wddrm_auto_added_rule_id' => $rule_id
            ] );
        }
        $this->is_adding_bogo = false;
    }
}
