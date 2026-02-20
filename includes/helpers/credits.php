<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Credits helpers
 * - Read balance from whichever meta key exists
 * - Write back to the same key that was found (or default)
 * - -1 => Unlimited
 *
 * ALSO INCLUDES:
 * - increment helper (for top-ups / webhooks)
 * - purchase card renderer (includes a 1 credit option)
 *
 * WOO ADDITIONS (this update):
 * - Map credit packs to Woo product SKUs
 * - Build Buy URLs as Woo add-to-cart links (SKU -> product ID)
 */

if ( ! function_exists( 'icon_psy_credit_meta_keys' ) ) {
    function icon_psy_credit_meta_keys() {
        return array(
			'icon_psy_participant_credits', // keep FIRST
			'icon_psy_credits',
			'icon_psy_credit_balance',
			'icon_psy_remaining_credits',
            'icon_psy_wc_credits',
            'icon_wc_credits',
        );
    }
}

if ( ! function_exists( 'icon_psy_get_client_credit_meta_key' ) ) {
    function icon_psy_get_client_credit_meta_key( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return 'icon_psy_participant_credits';

        foreach ( icon_psy_credit_meta_keys() as $k ) {
            $v = get_user_meta( $user_id, $k, true );
            if ( $v === '' || $v === null ) continue;
            if ( is_numeric( $v ) ) return $k;
        }

        return 'icon_psy_participant_credits';
    }
}

if ( ! function_exists( 'icon_psy_get_client_credit_balance' ) ) {
    function icon_psy_get_client_credit_balance( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return 0;

        $key = icon_psy_get_client_credit_meta_key( $user_id );
        $v   = get_user_meta( $user_id, $key, true );

        if ( $v === '' || $v === null ) return 0;
        if ( ! is_numeric( $v ) ) return 0;

        return (int) $v;
    }
}

if ( ! function_exists( 'icon_psy_set_client_credit_balance' ) ) {
    function icon_psy_set_client_credit_balance( $user_id, $new_value ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return false;

        $key = icon_psy_get_client_credit_meta_key( $user_id );
        update_user_meta( $user_id, $key, (int) $new_value );
        return true;
    }
}

if ( ! function_exists( 'icon_psy_decrement_client_credits' ) ) {
    function icon_psy_decrement_client_credits( $user_id, $qty = 1 ) {
        $user_id = (int) $user_id;
        $qty     = max( 1, (int) $qty );

        $bal = icon_psy_get_client_credit_balance( $user_id );

        if ( $bal === -1 ) return true; // Unlimited

        $bal = max( 0, $bal );
        if ( $bal < $qty ) return false;

        return icon_psy_set_client_credit_balance( $user_id, $bal - $qty );
    }
}

/**
 * Increment credits (for purchases/top-ups).
 * Safe with Unlimited (-1): leaves as unlimited.
 */
if ( ! function_exists( 'icon_psy_increment_client_credits' ) ) {
    function icon_psy_increment_client_credits( $user_id, $qty = 1 ) {
        $user_id = (int) $user_id;
        $qty     = max( 1, (int) $qty );
        if ( $user_id <= 0 ) return false;

        $bal = icon_psy_get_client_credit_balance( $user_id );
        if ( (int) $bal === -1 ) return true; // Unlimited stays unlimited

        $bal = max( 0, (int) $bal );
        return icon_psy_set_client_credit_balance( $user_id, $bal + $qty );
    }
}

//
// ------------------------------------------------------------
// Woo: credit pack -> SKU mapping
// ------------------------------------------------------------
//

/**
 * Map credits to Woo product SKUs.
 * You gave: 1 credit SKU = leadership_assessment-1
 *
 * Add more later as you create products:
 *  5  => 'credits-5'
 * 10  => 'credits-10'
 * 50  => 'subscription-50'
 */
if ( ! function_exists( 'icon_psy_credit_product_sku_map' ) ) {
    function icon_psy_credit_product_sku_map() {
        return array(
            1  => 'leadership_assessment-1', // ✅ 1 credit
            // 5  => 'credits-5',
            // 10 => 'credits-10',
            // 50 => 'subscription-50',
        );
    }
}

/**
 * Default Woo add-to-cart URL builder per pack (credits -> SKU -> product_id).
 * Falls back to the original $url if Woo isn't active or SKU not mapped/found.
 *
 * You can still override externally if you prefer:
 * add_filter('icon_psy_credit_pack_buy_url', function($url,$pack){...}, 10, 2);
 */
add_filter( 'icon_psy_credit_pack_buy_url', function( $url, $pack ) {

    if ( ! function_exists( 'wc_get_cart_url' ) || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
        return $url;
    }

    $credits = isset( $pack['credits'] ) ? (int) $pack['credits'] : 0;
    if ( $credits <= 0 ) return $url;

    $map = icon_psy_credit_product_sku_map();
    if ( empty( $map[ $credits ] ) ) return $url;

    $sku = (string) $map[ $credits ];
    $sku = trim( $sku );
    if ( $sku === '' ) return $url;

    $product_id = wc_get_product_id_by_sku( $sku );
    if ( ! $product_id ) return $url;

    return add_query_arg(
        array( 'add-to-cart' => (int) $product_id ),
        wc_get_cart_url()
    );

}, 20, 2 );

/**
 * Render Buy Credits card (includes a 1-credit option).
 *
 * How to hook your real payment links without editing this file again:
 * - Filter packs:
 *   add_filter('icon_psy_credit_packs', function($packs){ ... return $packs; });
 *
 * - Filter buy URL per pack:
 *   add_filter('icon_psy_credit_pack_buy_url', function($url, $pack){
 *       if ($pack['credits'] === 1) return 'https://...';
 *       return $url;
 *   }, 10, 2);
 */
if ( ! function_exists( 'icon_psy_render_purchase_card' ) ) {
    function icon_psy_render_purchase_card( $credit_balance = 0 ) {

        // Default packs (edit via filter)
        $packs = array(
            array(
                'key'     => 'c1',
                'credits' => 1,
                'title'   => '1 credit',
                'price'   => 'Top-up',
                'desc'    => 'Ideal for a single participant.',
            ),
        );

        // Only show packs that have an active SKU mapping
        $sku_map = icon_psy_credit_product_sku_map();
        $packs = array_values( array_filter( (array) $packs, function( $p ) use ( $sku_map ) {
            $c = isset($p['credits']) ? (int)$p['credits'] : 0;
            return $c > 0 && ! empty( $sku_map[$c] );
        }));

        $packs = apply_filters( 'icon_psy_credit_packs', $packs );

        // Basic fallback URL (used only if Woo SKU mapping isn't set / Woo missing)
        $default_buy_url = home_url( '/contact/' );

        ob_start();
        ?>
        <div style="margin-top:12px;">
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
                <?php foreach ( (array) $packs as $pack ) : ?>
                    <?php
                        $credits = isset($pack['credits']) ? (int) $pack['credits'] : 0;
                        if ( $credits <= 0 ) continue;

                        $title = isset($pack['title']) ? (string) $pack['title'] : ($credits . ' credits');
                        $price = isset($pack['price']) ? (string) $pack['price'] : '';
                        $desc  = isset($pack['desc']) ? (string) $pack['desc'] : '';

                        // Allow project to supply real purchase URLs (Woo SKU filter added above)
                        $buy_url = apply_filters( 'icon_psy_credit_pack_buy_url', $default_buy_url, $pack );
                        if ( ! $buy_url ) $buy_url = $default_buy_url;

                        // Keep this ONLY for non-Woo custom handlers.
                        // If Woo SKU mapping is active, the filter will already return a cart URL with add-to-cart.
                        if ( strpos( (string) $buy_url, 'add-to-cart=' ) === false ) {
                            $buy_url = add_query_arg( array( 'credits' => $credits ), $buy_url );
                        }
                    ?>
                    <div style="
                        border:1px solid rgba(20,164,207,.16);
                        border-radius:18px;
                        background:#fff;
                        padding:14px;
                        box-shadow:0 12px 30px rgba(0,0,0,.05);
                        overflow:hidden;
                    ">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                            <div style="font-weight:950;color:#071b1a;">
                                <?php echo esc_html( $title ); ?>
                            </div>
                            <div style="
                                display:inline-flex;
                                padding:6px 10px;
                                border-radius:999px;
                                font-size:11px;
                                font-weight:950;
                                letter-spacing:.06em;
                                text-transform:uppercase;
                                background: rgba(21,150,140,.10);
                                color: #15a06d;
                                white-space:nowrap;
                            ">
                                <?php echo esc_html( $credits ); ?>
                            </div>
                        </div>

                        <?php if ( $price !== '' ) : ?>
                            <div style="margin-top:6px;color:#425b56;font-size:12px;font-weight:800;">
                                <?php echo esc_html( $price ); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $desc !== '' ) : ?>
                            <div style="margin-top:6px;color:#6a837d;font-size:12px;line-height:1.35;">
                                <?php echo esc_html( $desc ); ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top:12px;">
                            <a class="icon-btn" href="<?php echo esc_url( $buy_url ); ?>">
                                Buy <?php echo (int) $credits; ?> credit<?php echo ( (int)$credits === 1 ? '' : 's' ); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:10px;" class="icon-mini">
                Buying credits will add to your current balance<?php echo ( (int)$credit_balance > 0 ? ' (currently: ' . esc_html((string)$credit_balance) . ')' : '' ); ?>.
                If you need invoicing or a subscription, use Support.
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
