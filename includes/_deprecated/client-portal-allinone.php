<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [icon_psy_client_portal_allinone]
 * - Logged out: Login / Register / Forgot (NO WordPress username field)
 * - Logged in:  Shows [icon_psy_client_portal]
 */

if ( ! function_exists( 'icon_psy_get_portal_url' ) ) {
    function icon_psy_get_portal_url() {
        return home_url( '/catalyst-portal/' );
    }
}

if ( ! function_exists( 'icon_psy_ensure_client_role' ) ) {
    function icon_psy_ensure_client_role() {
        if ( ! get_role( 'icon_client' ) ) {
            add_role( 'icon_client', 'ICON Client', array( 'read' => true ) );
        }
    }
}
add_action( 'init', 'icon_psy_ensure_client_role', 5 );

if ( ! function_exists( 'icon_psy_username_from_email' ) ) {
    function icon_psy_username_from_email( $email ) {
        $email = sanitize_email( $email );
        $base  = strtolower( trim( strtok( (string)$email, '@' ) ) );
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

if ( ! function_exists( 'icon_psy_user_login_from_email' ) ) {
    function icon_psy_user_login_from_email( $email ) {
        $email = sanitize_email( $email );
        if ( ! $email || ! is_email( $email ) ) return '';
        $user = get_user_by( 'email', $email );
        return ( $user && ! is_wp_error( $user ) ) ? $user->user_login : '';
    }
}

if ( ! function_exists( 'icon_psy_client_portal_allinone_shortcode' ) ) {

    function icon_psy_client_portal_allinone_shortcode() {

        // Logged in? show the real portal UI.
        if ( is_user_logged_in() ) {
            return do_shortcode( '[icon_psy_client_portal]' );
        }

        $errors  = array();
        $notices = array();

        $view = 'login';

        $sticky = array(
            'org'   => '',
            'name'  => '',
            'email' => '',
        );

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['icon_psy_aio_action'] ) ) {

            $action = sanitize_key( wp_unslash( $_POST['icon_psy_aio_action'] ) );

            $nonce_ok = isset( $_POST['icon_psy_aio_nonce'] ) && wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['icon_psy_aio_nonce'] ) ),
                'icon_psy_aio_auth'
            );

            $sticky['org']   = isset( $_POST['org'] )   ? sanitize_text_field( wp_unslash( $_POST['org'] ) ) : '';
            $sticky['name']  = isset( $_POST['name'] )  ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
            $sticky['email'] = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

            if ( ! $nonce_ok ) {
                $errors[] = 'Security check failed. Please refresh and try again.';
            } else {

                // --------------------
                // LOGIN (by email)
                // --------------------
                if ( $action === 'login' ) {
                    $view     = 'login';
                    $email    = $sticky['email'];
                    $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

                    if ( empty( $email ) || empty( $password ) ) {
                        $errors[] = 'Please enter your email and password.';
                    } else {
                        $user_login = icon_psy_user_login_from_email( $email );

                        if ( ! $user_login ) {
                            $errors[] = 'No account found with that email address.';
                        } else {

                            $user = wp_signon( array(
                                'user_login'    => $user_login,
                                'user_password' => $password,
                                'remember'      => true,
                            ), is_ssl() );

                            if ( is_wp_error( $user ) ) {
                                $errors[] = $user->get_error_message();
                            } else {
                                wp_set_current_user( $user->ID );
                                wp_set_auth_cookie( $user->ID, true );

                                wp_safe_redirect( icon_psy_get_portal_url() );
                                exit;
                            }
                        }
                    }
                }

                // --------------------
                // REGISTER (org + contact + email + password)
                // --------------------
                if ( $action === 'register' ) {
                    $view     = 'register';
                    $org      = $sticky['org'];
                    $name     = $sticky['name'];
                    $email    = $sticky['email'];
                    $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

                    if ( $org === '' ) {
                        $errors[] = 'Organisation name is required.';
                    } elseif ( $name === '' ) {
                        $errors[] = 'Contact name is required.';
                    } elseif ( empty( $email ) || ! is_email( $email ) ) {
                        $errors[] = 'Please enter a valid email address.';
                    } elseif ( email_exists( $email ) ) {
                        $errors[] = 'That email is already registered. Please log in instead.';
                    } elseif ( strlen( $password ) < 8 ) {
                        $errors[] = 'Password must be at least 8 characters.';
                    } else {

                        icon_psy_ensure_client_role();

                        $username = icon_psy_username_from_email( $email );

                        $user_id = wp_insert_user( array(
                            'user_login' => $username,
                            'user_pass'  => $password,
                            'user_email' => $email,
                            'role'       => 'icon_client',
                        ) );

                        if ( is_wp_error( $user_id ) ) {
                            $errors[] = $user_id->get_error_message();
                        } else {

                            wp_update_user( array(
                                'ID'           => $user_id,
                                'display_name' => $org,
                                'first_name'   => $name,
                            ) );

                            update_user_meta( $user_id, 'icon_account_type', 'business' );
                            update_user_meta( $user_id, 'icon_client_org', $org );
                            update_user_meta( $user_id, 'icon_client_contact', $name );

                            wp_set_current_user( $user_id );
                            wp_set_auth_cookie( $user_id, true );

                            wp_safe_redirect( icon_psy_get_portal_url() );
                            exit;
                        }
                    }
                }

                // --------------------
                // FORGOT PASSWORD
                // --------------------
                if ( $action === 'forgot' ) {
                    $view  = 'forgot';
                    $email = $sticky['email'];

                    if ( empty( $email ) || ! is_email( $email ) ) {
                        $errors[] = 'Please enter a valid email address.';
                    } else {
                        $_POST['user_login'] = $email;
                        $result = retrieve_password();

                        if ( is_wp_error( $result ) ) {
                            $errors[] = $result->get_error_message();
                        } else {
                            $notices[] = 'If an account exists for that email, a password reset link has been sent.';
                        }
                    }
                }
            }
        }

        ob_start();
        ?>
        <style>
        :root { --icon-green:#15a06d; --icon-blue:#14a4cf; --text-dark:#0a3b34; --text-mid:#425b56; --text-light:#6a837d; }
        .icon-aio-wrap{position:relative;padding:80px 20px 90px;background:radial-gradient(circle at top left,#e6f9ff 0%,#ffffff 40%,#e9f8f1 100%);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text-dark);overflow:hidden;}
        .icon-aio-inner{max-width:960px;margin:0 auto;position:relative;z-index:1;}
        .icon-aio-card{background:#fff;border-radius:22px;padding:26px 24px 24px;box-shadow:0 16px 40px rgba(0,0,0,.06);max-width:560px;}
        .icon-aio-title{font-size:32px;line-height:1.16;font-weight:700;margin:0 0 8px;}
        .icon-aio-sub{font-size:15px;color:#496863;margin:0 0 20px;max-width:560px;}
        .icon-tabs{display:flex;gap:8px;margin:0 0 14px;}
        .icon-tab{padding:8px 12px;border-radius:999px;border:1px solid rgba(148,163,184,.55);background:#fff;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;color:inherit;}
        .icon-tab.is-active{border-color:rgba(20,164,207,.7);box-shadow:0 0 0 1px rgba(20,164,207,.18);}
        .icon-error{margin:0 0 12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(185,28,28,.25);background:rgba(254,226,226,.45);color:#7f1d1d;font-size:13px;}
        .icon-notice{margin:0 0 12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(21,160,109,.25);background:rgba(236,253,245,.7);color:#065f46;font-size:13px;}
        .icon-group{margin-bottom:14px;}
        .icon-label{display:block;font-size:13px;font-weight:500;margin-bottom:4px;color:var(--text-dark);}
        .icon-input{width:100%;padding:10px 11px;border-radius:10px;border:1px solid rgba(10,59,52,.14);font-size:14px;font-family:inherit;color:var(--text-dark);box-sizing:border-box;}
        .icon-input:focus{outline:none;border-color:var(--icon-blue);box-shadow:0 0 0 1px rgba(20,164,207,.2);}
        .icon-btn{display:inline-block;padding:11px 24px;font-size:14px;font-weight:600;border-radius:999px;cursor:pointer;background:linear-gradient(135deg,var(--icon-blue),var(--icon-green));color:#fff;box-shadow:0 10px 25px rgba(20,164,207,.3);border:none;}
        .icon-links{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;font-size:12px;}
        .icon-link{color:var(--icon-blue);text-decoration:none;}
        .icon-link:hover{text-decoration:underline;}
        </style>

        <section class="icon-aio-wrap">
          <div class="icon-aio-inner">
            <h1 class="icon-aio-title">ICON Catalyst Portal</h1>
            <p class="icon-aio-sub">Log in, create a client account, or reset your password.</p>

            <div class="icon-aio-card">

              <?php if ( ! empty( $notices ) ) : ?>
                <div class="icon-notice">
                  <?php foreach ( $notices as $n ) : ?><div><?php echo esc_html( $n ); ?></div><?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if ( ! empty( $errors ) ) : ?>
                <div class="icon-error">
                  <strong>Fix this:</strong>
                  <ul style="margin:6px 0 0; padding-left:18px;">
                    <?php foreach ( $errors as $e ) : ?><li><?php echo wp_kses_post( $e ); ?></li><?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>

              <div class="icon-tabs">
                <a class="icon-tab <?php echo $view === 'login' ? 'is-active' : ''; ?>" href="#login" data-view="login">Log in</a>
                <a class="icon-tab <?php echo $view === 'register' ? 'is-active' : ''; ?>" href="#register" data-view="register">Register</a>
                <a class="icon-tab <?php echo $view === 'forgot' ? 'is-active' : ''; ?>" href="#forgot" data-view="forgot">Forgot</a>
              </div>

              <div id="iconViewLogin" style="<?php echo $view === 'login' ? '' : 'display:none;'; ?>">
                <h2 style="margin:0 0 6px;">Log in</h2>
                <p style="margin:0 0 16px;color:var(--text-mid);">Use your email and password.</p>

                <form method="post">
                  <input type="hidden" name="icon_psy_aio_action" value="login">
                  <?php wp_nonce_field( 'icon_psy_aio_auth', 'icon_psy_aio_nonce' ); ?>

                  <div class="icon-group">
                    <label class="icon-label">Email</label>
                    <input class="icon-input" type="email" name="email" required value="<?php echo esc_attr( $sticky['email'] ); ?>">
                  </div>

                  <div class="icon-group">
                    <label class="icon-label">Password</label>
                    <input class="icon-input" type="password" name="password" required autocomplete="current-password">
                  </div>

                  <button class="icon-btn" type="submit">Sign in</button>
                </form>
              </div>

              <div id="iconViewRegister" style="<?php echo $view === 'register' ? '' : 'display:none;'; ?>">
                <h2 style="margin:0 0 6px;">Register (Client)</h2>
                <p style="margin:0 0 16px;color:var(--text-mid);">Creates a client login and links it to your organisation.</p>

                <form method="post" autocomplete="off">
                  <input type="hidden" name="icon_psy_aio_action" value="register">
                  <?php wp_nonce_field( 'icon_psy_aio_auth', 'icon_psy_aio_nonce' ); ?>

                  <div class="icon-group">
                    <label class="icon-label">Organisation *</label>
                    <input class="icon-input" type="text" name="org" required value="<?php echo esc_attr( $sticky['org'] ); ?>">
                  </div>

                  <div class="icon-group">
                    <label class="icon-label">Contact name *</label>
                    <input class="icon-input" type="text" name="name" required value="<?php echo esc_attr( $sticky['name'] ); ?>">
                  </div>

                  <div class="icon-group">
                    <label class="icon-label">Email *</label>
                    <input class="icon-input" type="email" name="email" required value="<?php echo esc_attr( $sticky['email'] ); ?>">
                  </div>

                  <div class="icon-group">
                    <label class="icon-label">Password (min 8 chars) *</label>
                    <input class="icon-input" type="password" name="password" required minlength="8" autocomplete="new-password">
                  </div>

                  <button class="icon-btn" type="submit">Create account</button>
                </form>
              </div>

              <div id="iconViewForgot" style="<?php echo $view === 'forgot' ? '' : 'display:none;'; ?>">
                <h2 style="margin:0 0 6px;">Forgot password</h2>
                <p style="margin:0 0 16px;color:var(--text-mid);">We’ll email you a reset link.</p>

                <form method="post">
                  <input type="hidden" name="icon_psy_aio_action" value="forgot">
                  <?php wp_nonce_field( 'icon_psy_aio_auth', 'icon_psy_aio_nonce' ); ?>

                  <div class="icon-group">
                    <label class="icon-label">Email</label>
                    <input class="icon-input" type="email" name="email" required value="<?php echo esc_attr( $sticky['email'] ); ?>">
                  </div>

                  <button class="icon-btn" type="submit">Send reset link</button>
                </form>
              </div>

              <div class="icon-links">
                <a class="icon-link" href="#login" data-view="login">Log in</a>
                <span>·</span>
                <a class="icon-link" href="#register" data-view="register">Register</a>
                <span>·</span>
                <a class="icon-link" href="#forgot" data-view="forgot">Forgot</a>
              </div>

            </div>
          </div>
        </section>

        <script>
        (function(){
          function show(view){
            var a = document.getElementById('iconViewLogin');
            var b = document.getElementById('iconViewRegister');
            var c = document.getElementById('iconViewForgot');
            if(a) a.style.display = (view==='login') ? '' : 'none';
            if(b) b.style.display = (view==='register') ? '' : 'none';
            if(c) c.style.display = (view==='forgot') ? '' : 'none';

            document.querySelectorAll('.icon-tab').forEach(function(el){
              el.classList.toggle('is-active', el.getAttribute('data-view')===view);
            });
          }

          function fromHash(){
            var h = (window.location.hash || '').replace('#','');
            if(h==='register' || h==='forgot' || h==='login'){ show(h); }
          }

          document.addEventListener('click', function(e){
            var t = e.target.closest('[data-view]');
            if(!t) return;
            var v = t.getAttribute('data-view');
            if(v){ show(v); }
          });

          fromHash();
          window.addEventListener('hashchange', fromHash);
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

add_action( 'init', function() {
    add_shortcode( 'icon_psy_client_portal_allinone', 'icon_psy_client_portal_allinone_shortcode' );
}, 20 );
