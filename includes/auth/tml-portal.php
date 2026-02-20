require_once dirname( __DIR__ ) . '/helpers/auth-url-helpers.php';

 * - Ensure password reset (rp/resetpass) ALWAYS happens on wp-login.php
 * - Avoid memory exhaustion / recursion (NO site_url/network_site_url filters)
 *
 * Pages:
 * - /portal-login/        (login)
 * - /register-page-2/     (register)
 * - /lost-password-page/  (request reset email)
 *
 * Reset password:
 * - handled by wp-login.php?action=rp... (and styled via login_enqueue_scripts)
 */

// -----------------------------------------------------------------------------
// URL helpers
// -----------------------------------------------------------------------------
if ( ! function_exists( 'icon_psy_login_url' ) ) {
    function icon_psy_login_url() { return home_url( '/portal-login/' ); }
}
if ( ! function_exists( 'icon_psy_register_url' ) ) {
    function icon_psy_register_url() { return home_url( '/register-page-2/' ); }
}
if ( ! function_exists( 'icon_psy_lost_password_url' ) ) {
    function icon_psy_lost_password_url() { return home_url( '/lost-password-page/' ); }
}
if ( ! function_exists( 'icon_psy_portal_url' ) ) {
    function icon_psy_portal_url() { return home_url( '/catalyst-portal/' ); }
}
if ( ! function_exists( 'icon_psy_management_portal_url' ) ) {
    function icon_psy_management_portal_url() { return home_url( '/management-portal/' ); }
}
if ( ! function_exists( 'icon_psy_login_logo_url' ) ) {
    function icon_psy_login_logo_url() {
        return 'https://icon-talent.org/wp-content/uploads/2025/12/Icon-Catalyst-System.png';
    }
}

// -----------------------------------------------------------------------------
// Cookie safety helpers (COOKIEPATH/COOKIE_DOMAIN are normally defined by WP)
// -----------------------------------------------------------------------------
if ( ! defined( 'COOKIEPATH' ) ) {
    define( 'COOKIEPATH', '/' );
}
if ( ! defined( 'COOKIE_DOMAIN' ) ) {
    define( 'COOKIE_DOMAIN', '' );
}

// -----------------------------------------------------------------------------
// 0) HARD FORWARDERS (NO URL rewrites)
// If anything sends reset flows to branded pages, forward to wp-login.php.
// -----------------------------------------------------------------------------
add_action( 'template_redirect', function () {

    if ( is_admin() || wp_doing_ajax() ) { return; }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Only relevant on GET requests (email link clicks)
    if ( $method !== 'GET' ) { return; }

    $action = isset($_GET['action']) ? sanitize_key( wp_unslash($_GET['action']) ) : '';

    // If reset flow is incorrectly landing on portal-login, forward it
    if ( function_exists('is_page') && is_page('portal-login') && in_array($action, array('rp','resetpass'), true) ) {

        if ( empty($_GET['login']) || empty($_GET['key']) ) { return; }

        $login = sanitize_user( wp_unslash($_GET['login']) );
        $key   = sanitize_text_field( wp_unslash($_GET['key']) );

        $target = add_query_arg(
            array(
                'action' => 'rp',
                'login'  => $login,
                'key'    => $key,
            ),
            home_url( '/wp-login.php' )
        );

        wp_safe_redirect( $target );
        exit;
    }

    // If reset flow is incorrectly landing on lost-password-page, forward it
    if ( function_exists('is_page') && is_page('lost-password-page') && isset($_GET['login'], $_GET['key']) ) {

        $login = sanitize_user( wp_unslash($_GET['login']) );
        $key   = sanitize_text_field( wp_unslash($_GET['key']) );

        $target = add_query_arg(
            array(
                'action' => 'rp',
                'login'  => $login,
                'key'    => $key,
            ),
            home_url( '/wp-login.php' )
        );

        wp_safe_redirect( $target );
        exit;
    }

}, 1 );

// -----------------------------------------------------------------------------
// 1) Keep logged-in users off auth pages
// -----------------------------------------------------------------------------
add_action( 'template_redirect', function () {

    if ( is_admin() || wp_doing_ajax() ) { return; }
    if ( ! is_user_logged_in() ) { return; }

    if ( function_exists('is_page') && ( is_page('portal-login') || is_page('register-page-2') || is_page('lost-password-page') ) ) {
        wp_safe_redirect( icon_psy_portal_url() );
        exit;
    }
}, 20 );

// -----------------------------------------------------------------------------
// Helper: pick first non-empty POST value from a list of keys
// -----------------------------------------------------------------------------
if ( ! function_exists( 'icon_psy_pick_post' ) ) {
    function icon_psy_pick_post( array $keys, $sanitize = 'text' ) {
        foreach ( $keys as $k ) {
            if ( isset($_POST[$k]) && $_POST[$k] !== '' ) {
                $v = wp_unslash($_POST[$k]);
                if ( $sanitize === 'user' ) return sanitize_user( $v );
                if ( $sanitize === 'email' ) return sanitize_email( $v );
                return is_string($v) ? sanitize_text_field( $v ) : $v;
            }
        }
        return '';
    }
}

// -----------------------------------------------------------------------------
// 2) Handle auth form POSTs on branded pages
// NOTE: This is only needed if your forms POST back to the page URL.
// -----------------------------------------------------------------------------
add_action( 'template_redirect', function () {

    if ( is_admin() || wp_doing_ajax() ) { return; }

    $method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
    if ( $method !== 'POST' ) { return; }

    // -------------------------
    // LOGIN (portal-login page)
    // -------------------------
    if ( function_exists('is_page') && is_page( 'portal-login' ) ) {

        $login_raw = isset($_POST['log']) ? trim( (string) wp_unslash($_POST['log']) ) : '';
        if ( $login_raw === '' ) {
            $login_raw = (string) icon_psy_pick_post( array('user_login','username','email','user'), 'text' );
            $login_raw = trim($login_raw);
        }

        $pass = isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '';
        if ( $pass === '' ) {
            foreach ( array('user_pass','user_password','password','pass') as $k ) {
                if ( isset($_POST[$k]) && $_POST[$k] !== '' ) {
                    $pass = (string) wp_unslash($_POST[$k]);
                    break;
                }
            }
        }

        // Map email -> username (prevents mismatches)
        $login = $login_raw;
        if ( $login_raw && is_email( $login_raw ) ) {
            $u = get_user_by( 'email', $login_raw );
            if ( $u instanceof WP_User ) {
                $login = $u->user_login;
            }
        }

        $remember = ! empty($_POST['rememberme']) || ! empty($_POST['remember']);

        $creds = array(
            'user_login'    => $login,
            'user_password' => $pass,
            'remember'      => $remember,
        );

        $user = wp_signon( $creds, is_ssl() );

        if ( is_wp_error( $user ) ) {
            // Let the page reload; TML can display errors.
            return;
        }

        if ( user_can( $user, 'manage_options' ) ) {
            wp_safe_redirect( icon_psy_management_portal_url() );
            exit;
        }

        wp_safe_redirect( icon_psy_portal_url() );
        exit;
    }

    // -------------------------
    // LOST PASSWORD REQUEST (lost-password-page)
    // -------------------------
    if ( function_exists('is_page') && is_page( 'lost-password-page' ) ) {

        // If key+login exist, this is reset flow — do not handle here.
        if ( isset($_REQUEST['key'], $_REQUEST['login']) ) {
            return;
        }

        if ( empty($_POST['user_login']) ) {
            $maybe = icon_psy_pick_post( array('user_login','user_email','email','log','username'), 'text' );
            if ( $maybe !== '' ) {
                $_POST['user_login'] = $maybe;
            }
        }

        $result = retrieve_password();

        if ( is_wp_error( $result ) ) {
            return;
        }

        $url = add_query_arg( 'checkemail', 'confirm', icon_psy_lost_password_url() );
        wp_safe_redirect( $url );
        exit;
    }

    // -------------------------
    // REGISTER (register-page-2)
    // -------------------------
    if ( function_exists('is_page') && is_page( 'register-page-2' ) ) {

        require_once ABSPATH . 'wp-includes/registration.php';

        $user_login = icon_psy_pick_post( array('user_login','log','username','user'), 'user' );
        $user_email = icon_psy_pick_post( array('user_email','email'), 'email' );

        $result = register_new_user( $user_login, $user_email );

        if ( is_wp_error( $result ) ) {
            return;
        }

        // Assign Icon Client role
        $user_id = (int) $result;
        if ( $user_id > 0 ) {
            $u = new WP_User( $user_id );
            if ( $u && $u->exists() ) {
                $u->set_role( 'icon_client' );
            }
        }

        // Option 2: show success banner ONCE via cookie (no query string)
        setcookie(
            'icon_registered_once',
            '1',
            time() + 300, // 5 minutes
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true // httpOnly
        );

        wp_safe_redirect( icon_psy_login_url() );
        exit;
    }

}, 1 );

// -----------------------------------------------------------------------------
// 3) Brand WP-generated links (optional)
// -----------------------------------------------------------------------------
add_filter( 'register_url', function( $url ) {
    return icon_psy_register_url();
}, 999, 1 );

add_filter( 'lostpassword_url', function( $url, $redirect ) {
    $base = icon_psy_lost_password_url();
    if ( ! empty( $redirect ) ) {
        $base = add_query_arg( 'redirect_to', $redirect, $base );
    }
    return $base;
}, 999, 2 );

// -----------------------------------------------------------------------------
// 4) Redirect wp-login.php?action=register/lostpassword (GET) to branded pages.
// IMPORTANT: do NOT redirect rp/resetpass, and do NOT redirect if key+login present.
// -----------------------------------------------------------------------------
add_action( 'login_init', function() {

    if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
        return;
    }

    // Reset-password flow must stay on wp-login.php
    if ( isset($_GET['key'], $_GET['login']) ) {
        return;
    }

    $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

    if ( $action === 'register' ) {
        wp_safe_redirect( icon_psy_register_url() );
        exit;
    }

    if ( in_array( $action, array( 'lostpassword', 'retrievepassword' ), true ) ) {
        wp_safe_redirect( icon_psy_lost_password_url() );
        exit;
    }

    // DO NOT redirect rp/resetpass.

}, 1 );

// -----------------------------------------------------------------------------
// 5) Brand wp-login.php (including reset password screens) with CSS/logo
// This does NOT change URLs; it only styles the core login/reset UI.
// -----------------------------------------------------------------------------
add_action( 'login_enqueue_scripts', function() {

    $logo = icon_psy_login_logo_url();

    $primary = '#1f6f8b';
    $accent  = '#2bb673';
    $bg      = '#f5f7fb';

    echo '<style>
        body.login { background: ' . esc_html($bg) . '; }
        #login h1 a {
            background-image: url(' . esc_url($logo) . ') !important;
            background-size: contain !important;
            background-position: center !important;
            background-repeat: no-repeat !important;
            width: 260px !important;
            height: 90px !important;
        }
        .login #nav a, .login #backtoblog a { color: ' . esc_html($primary) . ' !important; }
        .wp-core-ui .button-primary {
            background: ' . esc_html($primary) . ' !important;
            border-color: ' . esc_html($primary) . ' !important;
            box-shadow: none !important;
            text-shadow: none !important;
        }
        .wp-core-ui .button-primary:hover {
            background: ' . esc_html($accent) . ' !important;
            border-color: ' . esc_html($accent) . ' !important;
        }
        .login form { border-radius: 10px; }
    </style>';
});

// Logo click-through + text on wp-login.php
add_filter( 'login_headerurl', function() {
    return home_url('/');
});
add_filter( 'login_headertext', function() {
    return 'Icon Talent';
});
