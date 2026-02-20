<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMPORTANT:
 * Set this to the page that contains [icon_psy_auth_page]
 * Example: https://icon-talent.org/login-register/
 */
if ( ! defined( 'ICON_PSY_AUTH_SLUG' ) ) {
    define( 'ICON_PSY_AUTH_SLUG', '/login-register/' ); // <-- CHANGE THIS SLUG to your real auth page
}

/**
 * Ensure roles exist
 */
add_action( 'init', function() {
    if ( ! get_role( 'icon_individual' ) ) {
        add_role( 'icon_individual', 'ICON Individual', array( 'read' => true ) );
    }
    if ( ! get_role( 'icon_client' ) ) {
        add_role( 'icon_client', 'ICON Client', array( 'read' => true ) );
    }
}, 5 );

/**
 * Create a safe WP username from email (and ensure uniqueness).
 */
if ( ! function_exists( 'icon_psy_username_from_email' ) ) {
    function icon_psy_username_from_email( $email ) {
        $email = (string) $email;
        $base  = strtolower( trim( strtok( $email, '@' ) ) );
        $base  = preg_replace( '/[^a-z0-9_\-\.]/i', '', $base );

        if ( $base === '' ) { $base = 'iconuser'; }

        $username = $base;
        $i = 1;
        while ( username_exists( $username ) ) {
            $i++;
            $username = $base . $i;
            if ( $i > 200 ) {
                $username = $base . wp_rand( 1000, 9999 );
                break;
            }
        }
        return $username;
    }
}

/**
 * Redirect markup (safe inside shortcodes — no headers / no exit).
 */
if ( ! function_exists( 'icon_psy_redirect_markup' ) ) {
    function icon_psy_redirect_markup( $url, $label = 'Continue' ) {
        $url = esc_url( $url );
        if ( ! $url ) { $url = esc_url( home_url( '/' ) ); }

        return '
            <div class="icon-psy-card" style="max-width:720px;margin:40px auto;padding:18px;border:1px solid rgba(20,164,207,.18);border-radius:18px;background:#fff;">
                <h2 style="margin:0 0 6px;">Account created</h2>
                <p style="margin:0;color:#425b56;">Redirecting you now…</p>
                <p style="margin:12px 0 0;">
                    <a href="' . $url . '" style="display:inline-block;padding:10px 16px;border-radius:999px;font-weight:800;color:#fff;text-decoration:none;background:linear-gradient(135deg,#14a4cf,#15a06d);">
                        ' . esc_html( $label ) . '
                    </a>
                </p>
            </div>
            <script>window.location.href=' . wp_json_encode( $url ) . ';</script>
            <noscript><meta http-equiv="refresh" content="0;url=' . esc_attr( $url ) . '"></noscript>
        ';
    }
}

/**
 * Shortcode: [icon_psy_register]
 */
if ( ! function_exists( 'icon_psy_register_shortcode' ) ) {
    function icon_psy_register_shortcode( $atts ) {

        $atts = shortcode_atts(
            array(
                'redirect' => home_url( '/my-assessments/' ), // router page with [icon_psy_portal]
            ),
            $atts,
            'icon_psy_register'
        );

        // Auth page URLs (branded)
        $auth_url  = home_url( ICON_PSY_AUTH_SLUG );
        $login_url = add_query_arg( 'view', 'login', $auth_url );
        $lost_url  = add_query_arg( 'view', 'forgot', $auth_url );

        if ( is_user_logged_in() ) {
            return '<div class="icon-psy-card" style="padding:14px;border:1px solid #e5e7eb;border-radius:14px;">
                You are already logged in. <a href="' . esc_url( $atts['redirect'] ) . '">Go to your portal</a>.
            </div>';
        }

        $msg = '';
        $cls = '';

        // Preserve values on error
        $sticky = array(
            'account_type' => 'individual',
            'email'        => '',
            'first_name'   => '',
            'last_name'    => '',
            'org_name'     => '',
            'contact_name' => '',
            'phone'        => '',
        );

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['icon_psy_register_submit'] ) ) {

            error_log( 'ICON_PSY REGISTER POST hit uri=' . ( isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '' ) );

            $nonce_ok = isset( $_POST['icon_psy_register_nonce'] )
                && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['icon_psy_register_nonce'] ) ), 'icon_psy_register' );

            // Fill sticky from POST
            $sticky['account_type'] = isset( $_POST['account_type'] ) ? sanitize_key( wp_unslash( $_POST['account_type'] ) ) : 'individual';
            $sticky['email']        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
            $sticky['first_name']   = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
            $sticky['last_name']    = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
            $sticky['org_name']     = isset( $_POST['org_name'] ) ? sanitize_text_field( wp_unslash( $_POST['org_name'] ) ) : '';
            $sticky['contact_name'] = isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : '';
            $sticky['phone']        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

            $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

            if ( ! $nonce_ok ) {
                $msg = 'Security check failed. Please refresh and try again.';
                $cls = 'bad';
                error_log( 'ICON_PSY REGISTER nonce FAIL' );

            } else {

                $account_type = $sticky['account_type'];
                $email        = $sticky['email'];

                if ( ! in_array( $account_type, array( 'individual', 'business' ), true ) ) {
                    $msg = 'Please select Individual or Business.';
                    $cls = 'bad';

                } elseif ( empty( $email ) || ! is_email( $email ) ) {
                    $msg = 'Please enter a valid email address.';
                    $cls = 'bad';

                } elseif ( email_exists( $email ) ) {
                    $msg = 'That email is already registered. Please login instead.';
                    $cls = 'bad';

                } elseif ( strlen( $password ) < 8 ) {
                    $msg = 'Password must be at least 8 characters.';
                    $cls = 'bad';

                } elseif ( $account_type === 'individual' && ( $sticky['first_name'] === '' || $sticky['last_name'] === '' ) ) {
                    $msg = 'Please enter your first name and last name.';
                    $cls = 'bad';

                } elseif ( $account_type === 'business' && ( $sticky['org_name'] === '' || $sticky['contact_name'] === '' ) ) {
                    $msg = 'Please enter Organisation name and Contact name.';
                    $cls = 'bad';

                } else {

                    $username = icon_psy_username_from_email( $email );

                    error_log( 'ICON_PSY REGISTER attempt email=' . $email . ' username=' . $username . ' type=' . $account_type );

                    $user_id = wp_insert_user( array(
                        'user_login'   => $username,
                        'user_pass'    => $password,
                        'user_email'   => $email,
                        'display_name' => ( $account_type === 'business' ? $sticky['org_name'] : trim( $sticky['first_name'] . ' ' . $sticky['last_name'] ) ),
                        'role'         => ( $account_type === 'business' ? 'icon_client' : 'icon_individual' ),
                    ) );

                    if ( is_wp_error( $user_id ) ) {
                        $msg = $user_id->get_error_message();
                        $cls = 'bad';
                        error_log( 'ICON_PSY REGISTER ERROR: ' . $msg );

                    } else {

                        // Store meta
                        update_user_meta( $user_id, 'icon_account_type', $account_type );

                        if ( $account_type === 'business' ) {
                            update_user_meta( $user_id, 'icon_client_org', $sticky['org_name'] );
                            update_user_meta( $user_id, 'icon_client_contact', $sticky['contact_name'] );
                            update_user_meta( $user_id, 'icon_client_phone', $sticky['phone'] );

                            wp_update_user( array(
                                'ID'         => $user_id,
                                'first_name' => $sticky['contact_name'],
                            ) );

                        } else {

                            update_user_meta( $user_id, 'icon_first_name', $sticky['first_name'] );
                            update_user_meta( $user_id, 'icon_last_name', $sticky['last_name'] );

                            wp_update_user( array(
                                'ID'         => $user_id,
                                'first_name' => $sticky['first_name'],
                                'last_name'  => $sticky['last_name'],
                            ) );
                        }

                        // Optional sync
                        if ( function_exists( 'icon_psy_sync_user_portal_role' ) ) {
                            icon_psy_sync_user_portal_role( $user_id );
                        }

                        // Auto-login after registration
                        wp_set_current_user( $user_id );
                        wp_set_auth_cookie( $user_id, true );

                        $redirect = ! empty( $atts['redirect'] ) ? $atts['redirect'] : home_url( '/my-assessments/' );

                        error_log( 'ICON_PSY REGISTER OK user_id=' . $user_id . ' redirect=' . $redirect );

                        // IMPORTANT: return redirect markup (NO exit)
                        return icon_psy_redirect_markup( $redirect, 'Go to portal' );
                    }
                }
            }
        }

        ob_start();
        ?>
        <div class="icon-psy-card" style="max-width:720px;margin:0 auto;padding:18px;border:1px solid rgba(20,164,207,.18);border-radius:18px;background:#fff;">
            <h2 style="margin:0 0 6px;">Register</h2>
            <p style="margin:0 0 12px;color:#425b56;">Choose your account type. This controls what you see in the portal.</p>

            <p style="margin:0 0 14px;font-size:13px;color:#425b56;">
                Already have an account? <a href="<?php echo esc_url( $login_url ); ?>">Login</a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url( $lost_url ); ?>">Forgot password?</a>
            </p>

            <?php if ( $msg ) : ?>
                <div style="margin:0 0 12px;padding:10px 12px;border-radius:14px;border:1px solid <?php echo $cls === 'bad' ? '#fecaca' : '#bbf7d0'; ?>;background:<?php echo $cls === 'bad' ? '#fef2f2' : '#ecfdf5'; ?>;color:<?php echo $cls === 'bad' ? '#b91c1c' : '#166534'; ?>;">
                    <?php echo esc_html( $msg ); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( get_permalink() ); ?>" id="iconPsyRegisterForm" autocomplete="on">
                <?php wp_nonce_field( 'icon_psy_register', 'icon_psy_register_nonce' ); ?>
                <input type="hidden" name="icon_psy_register_submit" value="1" />

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                    <label style="display:flex;gap:8px;align-items:center;">
                        <input type="radio" name="account_type" value="individual" <?php checked( $sticky['account_type'], 'individual' ); ?>>
                        Individual
                    </label>
                    <label style="display:flex;gap:8px;align-items:center;">
                        <input type="radio" name="account_type" value="business" <?php checked( $sticky['account_type'], 'business' ); ?>>
                        Business
                    </label>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                    <div>
                        <label style="font-size:12px;color:#6a837d;">Email *</label>
                        <input type="email" name="email" required value="<?php echo esc_attr( $sticky['email'] ); ?>" style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid #cbd5e1;">
                    </div>
                    <div>
                        <label style="font-size:12px;color:#6a837d;">Password *</label>
                        <input type="password" name="password" required minlength="8" style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid #cbd5e1;">
                    </div>
                </div>

                <div id="iconPsyIndividualFields" style="margin-top:10px;display:block;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                        <div>
                            <label style="font-size:12px;color:#6a837d;">First name *</label>
                            <input type="text" name="first_name" value="<?php echo esc_attr( $sticky['first_name'] ); ?>" style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid #cbd5e1;">
                        </div>
                        <div>
                            <label style="font-size:12px;color:#6a837d;">Last name *</label>
                            <input type="text" name="last_name" value="<?php echo esc_attr( $sticky['last_name'] ); ?>" style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid #cbd5e1;">
                        </div>
                    </div>
                </div>

                <div id="iconPsyBusinessFields" style="margin-top:10px;display:none;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                        <div>
                            <label style="font-size:12px;color:#6a837d;">Organisation name *</label>
                            <input type="text" name="org_name" value="<?php echo esc_attr( $sticky['org_name'] ); ?>" style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid #cbd5e1;">
                        </div>
                        <div>
                            <label style="font-size:12px;color:#6a837d;">Contact name *</label>
                            <input type="text" name="contact_name" value="<?php echo esc_attr( $sticky['contact_name'] ); ?>" style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid #cbd5e1;">
                        </div>
                        <div style="grid-column:1/-1;">
                            <label style="font-size:12px;color:#6a837d;">Phone</label>
                            <input type="text" name="phone" value="<?php echo esc_attr( $sticky['phone'] ); ?>" style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid #cbd5e1;">
                        </div>
                    </div>
                </div>

                <div style="margin-top:14px;display:flex;justify-content:flex-end;">
                    <button type="submit" style="border:0;border-radius:999px;padding:10px 16px;font-weight:800;color:#fff;cursor:pointer;background:linear-gradient(135deg,#14a4cf,#15a06d);">
                        Create account
                    </button>
                </div>
            </form>
        </div>

        <script>
        (function(){
            var form = document.getElementById('iconPsyRegisterForm');
            if(!form) return;

            function refresh(){
                var typeEl = form.querySelector('input[name="account_type"]:checked');
                var type = typeEl ? typeEl.value : 'individual';

                var ind = document.getElementById('iconPsyIndividualFields');
                var biz = document.getElementById('iconPsyBusinessFields');

                if(ind) ind.style.display = (type === 'individual') ? 'block' : 'none';
                if(biz) biz.style.display = (type === 'business') ? 'block' : 'none';
            }

            form.addEventListener('change', function(e){
                if(e.target && e.target.name === 'account_type') refresh();
            });

            refresh();
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

add_shortcode( 'icon_psy_register', 'icon_psy_register_shortcode' );
