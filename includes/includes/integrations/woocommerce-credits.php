<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Woo → Credits (Icon Catalyst)
 * Adds Icon participant credits to the purchasing user when an order is paid.
 *
 * ✅ Writes to the SAME credit key your portal reads first (icon_psy_participant_credits)
 * ✅ Supports Unlimited by setting credits = -1 (optional)
 * ✅ Prevents double-awarding via order meta flag
 *
 * Product IDs (your site):
 * Tool Only: 9617
 * Leadership Assessment: 9618
 * 360 feedback: 9619
 * 360 feedback & coaching: 9620
 * Full package: 9621
 * Subscription: 9622
 */

if ( ! function_exists( 'icon_psy_wc_credit_meta_key' ) ) {
    function icon_psy_wc_credit_meta_key() {
        return 'icon_psy_participant_credits';
    }
}

if ( ! function_exists( 'icon_psy_wc_get_credit_map' ) ) {
    function icon_psy_wc_get_credit_map() {
        /**
         * Map PRODUCT ID => credits to add
         * (use -1 for Unlimited if you ever sell it)
         *
         * Credits model (based on your earlier mapping):
         * Tool Only = 1
         * Leadership = 1
         * 360 = 3
         * 360 + Debrief = 5
         * Full Package = 10
         * Subscription = 50
         */
        return array(
            9617 => 1,   // Tool Only
            9618 => 1,   // Leadership Assessment
            9619 => 3,   // 360 feedback
            9620 => 5,   // 360 feedback & coaching
            9621 => 10,  // Full package
            9622 => 50,  // Subscription (Yearly 50)

            // Optional future:
            // 9999 => -1, // Unlimited product (example)
        );
    }
}

if ( ! function_exists( 'icon_psy_wc_get_user_credits' ) ) {
    function icon_psy_wc_get_user_credits( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return 0;

        $key = icon_psy_wc_credit_meta_key();
        $v   = get_user_meta( $user_id, $key, true );

        if ( $v === '' || $v === null ) return 0;
        if ( ! is_numeric( $v ) ) return 0;

        return (int) $v; // can be -1 for Unlimited
    }
}

if ( ! function_exists( 'icon_psy_wc_set_user_credits' ) ) {
    function icon_psy_wc_set_user_credits( $user_id, $new_value ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return false;

        $key = icon_psy_wc_credit_meta_key();
        update_user_meta( $user_id, $key, (int) $new_value );
        return true;
    }
}

if ( ! function_exists( 'icon_psy_wc_add_user_credits' ) ) {
    function icon_psy_wc_add_user_credits( $user_id, $credits, $context = '' ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return false;

        $credits = (int) $credits;

        $current = icon_psy_wc_get_user_credits( $user_id );

        // If already Unlimited, leave it as Unlimited
        if ( $current === -1 ) {
            icon_psy_wc_log_credit_award( $user_id, $credits, $context, -1 );
            return true;
        }

        // If this award makes them Unlimited
        if ( $credits === -1 ) {
            icon_psy_wc_set_user_credits( $user_id, -1 );
            icon_psy_wc_log_credit_award( $user_id, -1, $context, -1 );
            return true;
        }

        if ( $credits <= 0 ) return false;

        $new = max( 0, (int) $current ) + $credits;
        icon_psy_wc_set_user_credits( $user_id, $new );

        icon_psy_wc_log_credit_award( $user_id, $credits, $context, $new );

        return true;
    }
}

if ( ! function_exists( 'icon_psy_wc_log_credit_award' ) ) {
    function icon_psy_wc_log_credit_award( $user_id, $credits, $context, $new_total ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return;

        $log = get_user_meta( $user_id, 'icon_psy_credit_log', true );
        if ( ! is_array( $log ) ) $log = array();

        $log[] = array(
            'ts'      => gmdate( 'c' ),
            'credits' => $credits,
            'context' => (string) $context,
            'total'   => $new_total,
        );

        update_user_meta( $user_id, 'icon_psy_credit_log', $log );
    }
}

/**
 * Award credits when order is paid.
 * Use both hooks to cover gateways that go straight to completed.
 */
add_action( 'woocommerce_order_status_processing', 'icon_psy_wc_award_credits_for_order', 20, 2 );
add_action( 'woocommerce_order_status_completed',  'icon_psy_wc_award_credits_for_order', 20, 2 );

if ( ! function_exists( 'icon_psy_wc_award_credits_for_order' ) ) {
    function icon_psy_wc_award_credits_for_order( $order_id, $order = null ) {

        if ( ! function_exists( 'wc_get_order' ) ) return;

        $order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order_id );
        if ( ! $order ) return;

        $user_id = (int) $order->get_user_id();
        if ( $user_id <= 0 ) return;

        // prevent double-awarding
        $already = (int) $order->get_meta( '_icon_psy_credits_awarded', true );
        if ( $already === 1 ) return;

        $map = icon_psy_wc_get_credit_map();

        $to_add = 0;
        $make_unlimited = false;

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;

            $product = $item->get_product();
            if ( ! $product ) continue;

            $product_id = (int) $product->get_id();
            $qty        = max( 1, (int) $item->get_quantity() );

            if ( $product_id && isset( $map[ $product_id ] ) ) {
                $award = (int) $map[ $product_id ];

                if ( $award === -1 ) {
                    $make_unlimited = true;
                } elseif ( $award > 0 ) {
                    $to_add += ( $award * $qty );
                }
            }
        }

        if ( $make_unlimited ) {
            icon_psy_wc_add_user_credits( $user_id, -1, 'Woo order #' . $order->get_id() . ' (Unlimited)' );
        } elseif ( $to_add > 0 ) {
            icon_psy_wc_add_user_credits( $user_id, $to_add, 'Woo order #' . $order->get_id() );
        }

        $order->update_meta_data( '_icon_psy_credits_awarded', 1 );
        $order->save();
    }
}
