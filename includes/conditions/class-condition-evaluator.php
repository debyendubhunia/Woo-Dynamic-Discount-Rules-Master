<?php
namespace WooDynamicDiscountRulesMaster\Conditions;

/**
 * Evaluates all rule conditions against the current cart / user context.
 *
 * condition_data JSON structure:
 * {
 *   "operator": "AND"|"OR",
 *   "groups": [
 *     { "type": "<condition_type>", "value": <mixed>, "operator": "="|">"|"<"|">="| "<="| "!=" }
 *   ]
 * }
 */
class ConditionEvaluator {

    public function evaluate( array $rule ): bool {
        $cond = json_decode( $rule['condition_data'] ?? '{}', true );

        if ( ! empty( $cond['required_coupon'] ) ) {
            $coupon = strtolower( trim( $cond['required_coupon'] ) );
            if ( ! WC()->cart || ! WC()->cart->has_discount( $coupon ) ) {
                return false;
            }
        }

        if ( empty( $cond['groups'] ) ) return true;

        $op      = strtoupper( $cond['operator'] ?? 'AND' );
        $results = [];

        foreach ( $cond['groups'] as $group ) {
            $results[] = $this->check( $group );
        }

        if ( $op === 'OR' ) return in_array( true, $results, true );
        return ! in_array( false, $results, true ); // AND
    }

    private function check( array $group ): bool {
        $type  = $group['type']     ?? '';
        $val   = $group['value']    ?? null;
        $op    = $group['operator'] ?? '>=';

        switch ( $type ) {

            /* ---------- Cart conditions ---------- */
            case 'cart_subtotal':
                return $this->compare( WC()->cart->get_subtotal(), $op, (float) $val );

            case 'cart_total':
                return $this->compare( WC()->cart->get_total('edit'), $op, (float) $val );

            case 'cart_quantity':
                return $this->compare( WC()->cart->get_cart_contents_count(), $op, (int) $val );

            case 'cart_item_count': // unique line items
                return $this->compare( count( WC()->cart->get_cart() ), $op, (int) $val );

            /* ---------- Product / category ---------- */
            case 'product_in_cart':
                $ids = array_map( 'absint', (array) $val );
                foreach ( WC()->cart->get_cart() as $item ) {
                    if ( in_array( (int) $item['product_id'], $ids, true ) ) return true;
                }
                return false;

            case 'category_in_cart':
                $cats = array_map( 'absint', (array) $val );
                foreach ( WC()->cart->get_cart() as $item ) {
                    $product_cats = wp_get_post_terms( $item['product_id'], 'product_cat', ['fields'=>'ids'] );
                    if ( array_intersect( $cats, $product_cats ) ) return true;
                }
                return false;

            /* ---------- User / role ---------- */
            case 'user_role':
                $user  = wp_get_current_user();
                $roles = array_map( 'sanitize_key', (array) $val );
                return (bool) array_intersect( $roles, (array) $user->roles );

            case 'user_id':
                $ids = array_map( 'absint', (array) $val );
                return in_array( get_current_user_id(), $ids, true );

            case 'is_logged_in':
                return is_user_logged_in() === (bool) $val;

            /* ---------- Purchase history ---------- */
            case 'order_count': // total orders placed
                $count = wc_get_customer_order_count( get_current_user_id() );
                return $this->compare( $count, $op, (int) $val );

            case 'first_order':
                return wc_get_customer_order_count( get_current_user_id() ) === 0;

            case 'total_spent':
                $spent = wc_get_customer_total_spent( get_current_user_id() );
                return $this->compare( $spent, $op, (float) $val );

            case 'bought_product': // has previously purchased product IDs
                $ids = array_map( 'absint', (array) $val );
                foreach ( $ids as $pid ) {
                    if ( wc_customer_bought_product( '', get_current_user_id(), $pid ) ) return true;
                }
                return false;

            /* ---------- Location ---------- */
            case 'billing_country':
                $countries = array_map( 'strtoupper', (array) $val );
                $customer  = WC()->customer;
                return in_array( strtoupper( $customer ? $customer->get_billing_country() : '' ), $countries, true );

            case 'shipping_country':
                $countries = array_map( 'strtoupper', (array) $val );
                $customer  = WC()->customer;
                return in_array( strtoupper( $customer ? $customer->get_shipping_country() : '' ), $countries, true );

            case 'billing_state':
                $states   = array_map( 'strtoupper', (array) $val );
                $customer = WC()->customer;
                return in_array( strtoupper( $customer ? $customer->get_billing_state() : '' ), $states, true );

            case 'shipping_state':
                $states   = array_map( 'strtoupper', (array) $val );
                $customer = WC()->customer;
                return in_array( strtoupper( $customer ? $customer->get_shipping_state() : '' ), $states, true );

            /* ---------- Checkout method ---------- */
            case 'shipping_method':
                $chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', [] ) : [];
                $vals   = (array) $val;
                foreach ( $vals as $method ) {
                    foreach ( $chosen as $chosen_method ) {
                        if ( strpos( $chosen_method, $method ) === 0 ) return true;
                    }
                }
                return false;

            case 'payment_method':
                $chosen = WC()->session ? WC()->session->get( 'chosen_payment_method', '' ) : '';
                return in_array( $chosen, (array) $val, true );
        }

        return true; // unknown condition = pass
    }

    private function compare( $actual, string $op, $expected ): bool {
        switch ( $op ) {
            case '>':  return $actual >  $expected;
            case '<':  return $actual <  $expected;
            case '>=': return $actual >= $expected;
            case '<=': return $actual <= $expected;
            case '!=': return $actual != $expected;
            default:   return $actual == $expected;
        }
    }
}
