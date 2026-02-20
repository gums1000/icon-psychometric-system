<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [icon_psy_auth_page]
 * Branded auth page: Login / Register / Forgot password
 *
 * Notes:
 * - Uses #login/#register/#forgot for UI tabs (no query vars needed)
 * - Forces form POST action to the page permalink (prevents weird redirects)
 * - Logs to wp-content/debug.log if WP_DEBUG_LOG enabled
 * - Supports Multisite (adds user to current blog so they show in Users list)
 */

if ( ! function_exists( 'icon_psy_auth_log' ) ) {
    function icon_psy_auth_log( $msg ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[ICON_PSY_AUTH] ' . $msg );
        }
    }
}

if ( ! function_exists( 'icon_psy_auth_username_from_email' ) ) {
    function icon_psy_auth_username_from_email( $email ) {
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

if ( ! function_exists( 'icon_psy_auth_user_login_from_email' ) ) {
    function icon_psy_auth_user_login_from_email( $email ) {
        $email = sanitize_email( $email );
        if ( ! $email || ! is_email( $email ) ) return '';
        $user = get_user_by( 'email', $email );
        if ( $user && ! is_wp_error( $user ) ) return $user->user_login;
        return '';
    }
}

if ( ! function_exists( 'icon_psy_auth_page_render' ) ) {

function icon_psy_auth_page_render() {

    // ✅ Set this to your router page (the page containing [icon_psy_portal])
    $portal_url = home_url( '/my-assessments/' );

	// DEBUG (only when you add ?auth_debug=1 to the URL)
	$auth_debug = isset($_GET['auth_debug']) && $_GET['auth_debug'] === '1';
	$debug_dump = array();

	if ( $auth_debug ) {
		$debug_dump['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? '';
		$debug_dump['POST_keys']       = isset($_POST) ? array_keys($_POST) : array();
		$debug_dump['action']          = isset($_POST['icon_psy_auth_action']) ? (string) $_POST['icon_psy_auth_action'] : '';
		$debug_dump['email']           = isset($_POST['email']) ? (string) $_POST['email'] : '';
	}

    $errors  = array();
    $notices = array();

    // Sticky values
    $sticky = array(
        'account_type' => 'individual',
        'name'         => '',
        'org'          => '',
        'email'        => '',
    );

    // Which view? (hash tabs are client-side, so server default = login)
    // We still support ?view=login|register|forgot for compatibility if you need it.
    $view = 'login';
    if ( isset( $_GET['view'] ) ) {
        $v = sanitize_key( wp_unslash( $_GET['view'] ) );
        if ( in_array( $v, array( 'login', 'register', 'forgot' ), true ) ) {
            $view = $v;
        }
    }

    // Logged-in banner (don’t return early)
    $logged_in_banner = '';
    $force_auth = isset($_GET['force_auth']) && $_GET['force_auth'] === '1';

    if ( is_user_logged_in() && ! $force_auth ) {
        $logged_in_banner = '<div class="icon-error" style="border-color:#bbf7d0;background:#ecfdf5;color:#065f46;">
            <strong>You are signed in.</strong><br>
            <a class="icon-login-link" style="font-weight:700;" href="' . esc_url( $portal_url ) . '">Go to portal →</a>
            <span style="opacity:.6;"> · </span>
            <a class="icon-login-link" style="font-weight:700;" href="' . esc_url( wp_logout_url( get_permalink() ) ) . '">Log out</a>
            <span style="opacity:.6;"> · </span>
            <a class="icon-login-link" style="font-weight:700;" href="' . esc_url( add_query_arg( 'force_auth', '1', get_permalink() ) ) . '">Show login/register</a>
        </div>';
    }

    // -----------------------
    // Handle POST
    // -----------------------
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['icon_psy_auth_action'] ) ) {
		if ( $auth_debug ) { $debug_dump['POST_detected'] = 'YES'; }


        $action = sanitize_key( wp_unslash( $_POST['icon_psy_auth_action'] ) );

        $nonce_ok = isset( $_POST['icon_psy_auth_nonce'] ) && wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['icon_psy_auth_nonce'] ) ),
            'icon_psy_auth'
        );

        // Fill sticky
        $sticky['account_type'] = isset( $_POST['account_type'] ) ? sanitize_key( wp_unslash( $_POST['account_type'] ) ) : 'individual';
        $sticky['name']         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $sticky['org']          = isset( $_POST['org'] ) ? sanitize_text_field( wp_unslash( $_POST['org'] ) ) : '';
        $sticky['email']        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

        icon_psy_auth_log( 'POST action=' . $action . ' email=' . $sticky['email'] );

        if ( ! $nonce_ok ) {
            $errors[] = 'Security check failed. Please refresh and try again.';
            icon_psy_auth_log( 'Nonce failed' );
        } else {

            // ---- LOGIN ----
            if ( $action === 'login' ) {

                $view     = 'login';
                $email    = $sticky['email'];
                $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

                if ( empty( $email ) || empty( $password ) ) {
                    $errors[] = 'Please enter your email and password.';
                } else {

                    $user_login = icon_psy_auth_user_login_from_email( $email );
                    if ( ! $user_login ) {
                        $errors[] = 'No account found with that email address.';
                    } else {

                        $creds = array(
                            'user_login'    => $user_login,
                            'user_password' => $password,
                            'remember'      => true,
                        );

                        $user = wp_signon( $creds, is_ssl() );

                        if ( is_wp_error( $user ) ) {
                            $errors[] = $user->get_error_message();
                            icon_psy_auth_log( 'Login failed: ' . $user->get_error_message() );
                        } else {
                            wp_set_current_user( $user->ID );
                            wp_set_auth_cookie( $user->ID, true );

                            // Optional: sync role if you have it
                            if ( function_exists( 'icon_psy_sync_user_portal_role' ) ) {
                                icon_psy_sync_user_portal_role( $user->ID );
                            }

                            icon_psy_auth_log( 'Login success user_id=' . $user->ID );
                            wp_safe_redirect( $portal_url );
                            exit;
                        }
                    }
                }
            }

            // ---- REGISTER ----
            if ( $action === 'register' ) {

                $view = 'register';

                $account_type = $sticky['account_type'];
                $name         = $sticky['name'];
                $org          = $sticky['org'];
                $email        = $sticky['email'];
                $password     = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

                if ( ! in_array( $account_type, array( 'individual', 'business' ), true ) ) {
                    $errors[] = 'Please select Individual or Business.';
                } elseif ( empty( $name ) || empty( $email ) || empty( $password ) ) {
                    $errors[] = 'Name, email and password are required.';
                } elseif ( ! is_email( $email ) ) {
                    $errors[] = 'Please enter a valid email address.';
                } elseif ( email_exists( $email ) ) {
                    $errors[] = 'An account already exists with that email. Please log in instead.';
                } elseif ( strlen( $password ) < 8 ) {
                    $errors[] = 'Password must be at least 8 characters.';
                } elseif ( $account_type === 'business' && $org === '' ) {
                    $errors[] = 'Organisation is required for Business accounts.';
                } else {

                    $username = icon_psy_auth_username_from_email( $email );

                    // Use wp_insert_user so we can see detailed WP_Error
                    $user_id = wp_insert_user( array(
                        'user_login'   => $username,
                        'user_pass'    => $password,
                        'user_email'   => $email,
                        'display_name' => ( $account_type === 'business' && $org ) ? $org : $name,
                        'first_name'   => $name,
                        'role'         => ( $account_type === 'business' ) ? 'icon_client' : 'icon_individual',
                    ) );

					if ( $auth_debug ) {
    				$debug_dump['wp_insert_user_result'] = is_wp_error($user_id) ? $user_id->get_error_message() : ('USER_ID=' . $user_id);
					}

                    if ( is_wp_error( $user_id ) ) {
                        $errors[] = $user_id->get_error_message();
                        icon_psy_auth_log( 'Register failed: ' . $user_id->get_error_message() );
                    } else {

                        // Multisite: make sure user is added to THIS site so they show in Users list
                        if ( is_multisite() ) {
                            $blog_id = get_current_blog_id();
                            $role    = ( $account_type === 'business' ) ? 'icon_client' : 'icon_individual';
                            add_user_to_blog( $blog_id, $user_id, $role );
                            icon_psy_auth_log( 'Multisite add_user_to_blog blog_id=' . $blog_id . ' role=' . $role );
                        }

                        update_user_meta( $user_id, 'icon_account_type', $account_type );

                        if ( $account_type === 'business' ) {
                            update_user_meta( $user_id, 'icon_client_org', $org );
                            update_user_meta( $user_id, 'icon_client_contact', $name );
                        }

                        // Optional: sync role if you have it
                        if ( function_exists( 'icon_psy_sync_user_portal_role' ) ) {
                            icon_psy_sync_user_portal_role( $user_id );
                        }

                        wp_set_current_user( $user_id );
                        wp_set_auth_cookie( $user_id, true );

                        icon_psy_auth_log( 'Register success user_id=' . $user_id );
                        wp_safe_redirect( $portal_url );
                        exit;
                    }
                }
            }

            // ---- FORGOT PASSWORD ----
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
                        icon_psy_auth_log( 'Forgot failed: ' . $result->get_error_message() );
                    } else {
                        $notices[] = 'If an account exists for that email, a password reset link has been sent.';
                        icon_psy_auth_log( 'Forgot OK for email=' . $email );
                    }
                }
            }
        }
    }

    // Base URLs (NO query vars; hash tabs handled by JS)
    $base_url = get_permalink();

    ob_start();
    ?>
    <style>
    :root { --icon-green:#15a06d; --icon-blue:#14a4cf; --text-dark:#0a3b34; --text-mid:#425b56; --text-light:#6a837d; }
    .icon-login-wrapper{position:relative;padding:80px 20px 90px;background:radial-gradient(circle at top left,#e6f9ff 0%,#ffffff 40%,#e9f8f1 100%);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text-dark);overflow:hidden;}
    .icon-login-orbit{position:absolute;border-radius:999px;opacity:.12;filter:blur(2px);pointer-events:none;z-index:0;background:linear-gradient(135deg,var(--icon-blue),var(--icon-green));}
    .icon-login-orbit.orbit-1{width:260px;height:80px;top:3%;left:-40px;}
    .icon-login-orbit.orbit-2{width:200px;height:200px;bottom:-70px;right:-30px;border-radius:50%;}
    .icon-login-orbit.orbit-3{width:140px;height:46px;top:56%;right:12%;}
    .icon-login-inner{max-width:960px;margin:0 auto;position:relative;z-index:1;}
    .icon-login-tag{display:inline-block;padding:4px 12px;border-radius:999px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;background:rgba(21,150,140,.09);color:var(--icon-green);margin-bottom:10px;}
    .icon-login-title{font-size:32px;line-height:1.16;font-weight:700;margin:0 0 8px;}
    .icon-login-subtitle{font-size:15px;color:#496863;margin:0 0 20px;max-width:560px;}
    .icon-login-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:30px;align-items:flex-start;}
    @media (max-width:900px){.icon-login-grid{grid-template-columns:1fr;}}
    .icon-login-card{background:#fff;border-radius:22px;padding:26px 24px 24px;box-shadow:0 16px 40px rgba(0,0,0,.06);}
    .icon-login-sidecard{background:#fff;border-radius:22px;padding:22px 20px 20px;box-shadow:0 14px 34px rgba(0,0,0,.05);}
    .icon-login-card h2{font-size:20px;margin:0 0 6px;}
    .icon-login-card p{font-size:14px;color:var(--text-mid);margin:0 0 16px;}
    .icon-login-form-group{margin-bottom:14px;}
    .icon-login-label{display:block;font-size:13px;font-weight:500;margin-bottom:4px;color:var(--text-dark);}
    .icon-login-input{width:100%;padding:10px 11px;border-radius:10px;border:1px solid rgba(10,59,52,.14);font-size:14px;font-family:inherit;color:var(--text-dark);box-sizing:border-box;}
    .icon-login-input:focus{outline:none;border-color:var(--icon-blue);box-shadow:0 0 0 1px rgba(20,164,207,.2);}
    .icon-btn-main{display:inline-block;padding:11px 24px;font-size:14px;font-weight:600;border-radius:999px;text-decoration:none;cursor:pointer;background:linear-gradient(135deg,var(--icon-blue),var(--icon-green));color:#fff;box-shadow:0 10px 25px rgba(20,164,207,.3);border:none;transition:transform .12s ease,box-shadow .12s ease;}
    .icon-btn-main:hover{transform:translateY(-1px);box-shadow:0 14px 32px rgba(20,164,207,.35);}
    .icon-login-links{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;font-size:12px;}
    .icon-login-link{color:var(--icon-blue);text-decoration:none;}
    .icon-login-link:hover{text-decoration:underline;}
    .icon-tabs{display:flex;gap:8px;margin:0 0 14px;flex-wrap:wrap;}
    .icon-tab{padding:8px 12px;border-radius:999px;border:1px solid rgba(148,163,184,.55);background:#fff;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;color:inherit;}
    .icon-tab.is-active{border-color:rgba(20,164,207,.7);box-shadow:0 0 0 1px rgba(20,164,207,.18);}
    .icon-error{margin:0 0 12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(185,28,28,.25);background:rgba(254,226,226,.45);color:#7f1d1d;font-size:13px;}
    .icon-notice{margin:0 0 12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(21,160,109,.25);background:rgba(236,253,245,.7);color:#065f46;font-size:13px;}
    </style>

    <section class="icon-login-wrapper">
      <div class="icon-login-orbit orbit-1"></div>
      <div class="icon-login-orbit orbit-2"></div>
      <div class="icon-login-orbit orbit-3"></div>

      <div class="icon-login-inner">
        <div class="icon-login-tag">ICON Catalyst</div>
        <h1 class="icon-login-title">Sign in or create your portal account.</h1>
        <p class="icon-login-subtitle">One login. Two portal types. Individuals get their self portal. Businesses get the client portal.</p>

        <div class="icon-login-grid">
          <div class="icon-login-card">

            <?php echo $logged_in_banner; ?>

            <?php if ( ! empty( $notices ) ) : ?>
              <div class="icon-notice">
                <?php foreach ( $notices as $n ) : ?>
                  <div><?php echo esc_html( $n ); ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ( ! empty( $errors ) ) : ?>
              <div class="icon-error">
                <strong>Fix this:</strong>
                <ul style="margin:6px 0 0; padding-left:18px;">
                  <?php foreach ( $errors as $e ) : ?>
                    <li><?php echo wp_kses_post( $e ); ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <div class="icon-tabs">
              <a class="icon-tab" href="#login">Log in</a>
              <a class="icon-tab" href="#register">Register</a>
              <a class="icon-tab" href="#forgot">Forgot password</a>
            </div>

            <!-- LOGIN -->
            <div id="login" class="icon-auth-panel">
              <h2>Log in</h2>
              <p>Enter your details to access your portal.</p>

              <form method="post" action="<?php echo esc_url( $base_url ); ?>">
                <input type="hidden" name="icon_psy_auth_action" value="login">
                <?php wp_nonce_field( 'icon_psy_auth', 'icon_psy_auth_nonce' ); ?>

                <div class="icon-login-form-group">
                  <label class="icon-login-label" for="icon-login-email">Email address</label>
                  <input type="email" id="icon-login-email" name="email" class="icon-login-input" autocomplete="email" required value="<?php echo esc_attr( $sticky['email'] ); ?>">
                </div>

                <div class="icon-login-form-group">
                  <label class="icon-login-label" for="icon-login-password">Password</label>
                  <input type="password" id="icon-login-password" name="password" class="icon-login-input" autocomplete="current-password" required>
                </div>

                <div class="icon-login-form-group">
                  <button type="submit" class="icon-btn-main">Sign in</button>
                </div>
              </form>
            </div>

            <!-- REGISTER -->
            <div id="register" class="icon-auth-panel">
              <h2>Register</h2>
              <p>Create your account and choose your portal type.</p>

              <form method="post" action="<?php echo esc_url( $base_url ); ?>" autocomplete="off">
                <input type="hidden" name="icon_psy_auth_action" value="register">
                <?php wp_nonce_field( 'icon_psy_auth', 'icon_psy_auth_nonce' ); ?>

                <div class="icon-login-form-group">
                  <label class="icon-login-label">Account type</label>
                  <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:6px;">
                    <input type="radio" name="account_type" value="individual" <?php checked( $sticky['account_type'], 'individual' ); ?>> Individual (self portal)
                  </label>
                  <label style="display:flex;gap:8px;align-items:center;font-size:13px;">
                    <input type="radio" name="account_type" value="business" <?php checked( $sticky['account_type'], 'business' ); ?>> Business (client portal)
                  </label>
                </div>

                <div class="icon-login-form-group">
                  <label class="icon-login-label" for="icon-reg-name">Your name</label>
                  <input id="icon-reg-name" class="icon-login-input" type="text" name="name" required value="<?php echo esc_attr( $sticky['name'] ); ?>">
                </div>

                <div class="icon-login-form-group">
                  <label class="icon-login-label" for="icon-reg-org">Organisation (business only)</label>
                  <input id="icon-reg-org" class="icon-login-input" type="text" name="org" value="<?php echo esc_attr( $sticky['org'] ); ?>">
                </div>

                <div class="icon-login-form-group">
                  <label class="icon-login-label" for="icon-reg-email">Email address</label>
                  <input id="icon-reg-email" class="icon-login-input" type="email" name="email" autocomplete="email" required value="<?php echo esc_attr( $sticky['email'] ); ?>">
                </div>

                <div class="icon-login-form-group">
                  <label class="icon-login-label" for="icon-reg-pass">Password (min 8 chars)</label>
                  <input id="icon-reg-pass" class="icon-login-input" type="password" name="password" autocomplete="new-password" required minlength="8">
                </div>

                <div class="icon-login-form-group">
                  <button type="submit" class="icon-btn-main">Create account</button>
                </div>
              </form>
            </div>

            <!-- FORGOT -->
            <div id="forgot" class="icon-auth-panel">
              <h2>Forgot password</h2>
              <p>Enter your email address. We’ll send you a reset link.</p>

              <form method="post" action="<?php echo esc_url( $base_url ); ?>">
                <input type="hidden" name="icon_psy_auth_action" value="forgot">
                <?php wp_nonce_field( 'icon_psy_auth', 'icon_psy_auth_nonce' ); ?>

                <div class="icon-login-form-group">
                  <label class="icon-login-label" for="icon-forgot-email">Email address</label>
                  <input type="email" id="icon-forgot-email" name="email" class="icon-login-input" autocomplete="email" required value="<?php echo esc_attr( $sticky['email'] ); ?>">
                </div>

                <div class="icon-login-form-group">
                  <button type="submit" class="icon-btn-main">Send reset link</button>
                </div>
              </form>
            </div>

            <script>
            (function(){
              // Make tabs look active based on hash
              function setActive(){
                var h = (window.location.hash || '#login').replace('#','');
                var tabs = document.querySelectorAll('.icon-tab');
                tabs.forEach(function(t){
                  var target = (t.getAttribute('href') || '').replace('#','');
                  if(target === h){ t.classList.add('is-active'); } else { t.classList.remove('is-active'); }
                });
              }
              window.addEventListener('hashchange', setActive);
              setActive();
            })();
            </script>

          </div>

          <aside class="icon-login-sidecard">
            <h3>Who uses the portal</h3>
            <p>The ICON Catalyst portal is for:</p>
            <ul>
              <li>Individuals completing self assessments.</li>
              <li>Client organisations sponsoring projects and 360s.</li>
              <li>Managers and authorised partners reviewing reports.</li>
            </ul>
          </aside>

        </div>
      </div>
    </section>
    <?php
	if ( $auth_debug ) {
    echo '<div style="margin:14px 0;padding:12px;border:1px solid #fde68a;background:#fffbeb;border-radius:12px;color:#92400e;font-size:12px;line-height:1.4;">';
    echo '<strong>AUTH DEBUG</strong><pre style="white-space:pre-wrap;margin:8px 0 0;">' . esc_html( print_r($debug_dump, true) ) . '</pre>';
    echo '</div>';
}

    return ob_get_clean();
}
}

add_action( 'init', function() {
    add_shortcode( 'icon_psy_auth_page', 'icon_psy_auth_page_render' );
}, 20 );
