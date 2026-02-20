<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON branded auth pages (hard-coded to your 3 pages)
 * - /portal-login/
 * - /register-page-2/
 * - /lost-password-page/
 *
 * Uses Theme My Login shortcode internally.
 * No page shortcodes required, no wrapper blocks needed.
 */

// Your slugs
function icon_psy_auth_slugs() {
    return array(
        'login'        => 'portal-login',
        'register'     => 'register-page-2',
        'lostpassword' => 'lost-password-page',
    );
}

function icon_psy_auth_logo_url() {
    return 'https://icon-talent.org/wp-content/uploads/2025/12/Icon-Catalyst-System.png';
}

function icon_psy_auth_back_url() {
    return 'https://icon-talent.org/';
}

function icon_psy_is_auth_page() {
    if ( ! function_exists('is_page') ) return false;
    $slugs = icon_psy_auth_slugs();
    return is_page( $slugs['login'] ) || is_page( $slugs['register'] ) || is_page( $slugs['lostpassword'] );
}

function icon_psy_auth_mode() {
    $slugs = icon_psy_auth_slugs();
    if ( function_exists('is_page') ) {
        if ( is_page( $slugs['register'] ) ) return 'register';
        if ( is_page( $slugs['lostpassword'] ) ) return 'lostpassword';
        if ( is_page( $slugs['login'] ) ) return 'login';
    }
    return 'login';
}

/**
 * 1) Hide theme header/footer + give full-screen app feel (only on these pages)
 * Note: selectors may vary by theme, but these cover most WP themes.
 */
add_action( 'wp_head', function () {

    if ( ! icon_psy_is_auth_page() ) return;

    ?>
    <style>
        /* Hide common theme header/footer containers on ONLY these pages */
        body.page .site-header,
        body.page header.site-header,
        body.page #masthead,
        body.page .header,
        body.page header,
        body.page .site-footer,
        body.page footer,
        body.page #colophon,
        body.page .footer {
            display:none !important;
        }

        /* Remove top spacing some themes add */
        body { margin:0 !important; }
        .site, #page { margin:0 !important; padding:0 !important; }
        .content-area, #content, .site-content { margin:0 !important; padding:0 !important; }

        /* Prevent weird width constraints */
        .entry-content, .page-content, .wp-block-group__inner-container {
            max-width:none !important;
            padding:0 !important;
        }
    </style>
    <?php
}, 20 );

/**
 * 2) Override page content with ICON layout (only on these pages)
 */
add_filter( 'the_content', function( $content ) {

    if ( ! icon_psy_is_auth_page() ) {
        return $content;
    }

    $mode = icon_psy_auth_mode();

    // Copy tailored headings
    $tag      = 'Client portal';
    $title    = 'Icon Catalyst client access';
    $subtitle = 'Secure access to your leadership assessments, 360 feedback, projects and reports — all in one ICON system.';

    if ( $mode === 'register' ) {
        $tag      = 'Create access';
        $title    = 'Create your Icon Catalyst login';
        $subtitle = 'Register to access your organisation’s projects and reports. After you register, you’ll receive an email to set your password.';
    } elseif ( $mode === 'lostpassword' ) {
        $tag      = 'Password reset';
        $title    = 'Reset your password';
        $subtitle = 'Enter your email address and we’ll send you a link to set a new password.';
    }

    // Success banner after registration (show ONCE via cookie)
    $success_banner = '';
    if ( $mode === 'login' && ! empty($_COOKIE['icon_registered_once']) && $_COOKIE['icon_registered_once'] === '1' ) {

        $success_banner = '<div class="icon-auth-banner">
            Account created. Please check your inbox to <strong>set your password</strong>.
            Please check your inbox (and spam/junk), set your password, then log in here.
        </div>';

        // Clear cookie so it shows only once
        setcookie(
            'icon_registered_once',
            '',
            time() - 3600,
            defined('COOKIEPATH') ? COOKIEPATH : '/',
            defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : ''
        );
    }

    // TML shortcode per page (keeps structure intact)
    $tml = '[theme-my-login action="login"]';

    if ( $mode === 'register' ) {
        $tml = '[theme-my-login action="register"]';
    } elseif ( $mode === 'lostpassword' ) {
        $tml = '[theme-my-login action="lostpassword"]';
    }

    // Render the shortcode output
    $form_html = do_shortcode( $tml );

    ob_start();
    ?>
    <style>
    :root {
      --icon-green: #15a06d;
      --icon-blue: #14a4cf;
      --text-dark: #0a3b34;
      --text-mid: #425b56;
      --text-light: #6a837d;
    }

    .icon-auth-wrapper{
      position:relative;
      min-height:100vh;
      padding:90px 20px 90px;
      background: radial-gradient(circle at top left, #e6f9ff 0%, #ffffff 40%, #e9f8f1 100%);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text-dark);
      overflow:hidden;
      display:flex;
      align-items:flex-start;
      justify-content:center;
    }

    .icon-auth-orbit{
      position:absolute;
      border-radius:999px;
      opacity:.12;
      filter:blur(2px);
      pointer-events:none;
      background:linear-gradient(135deg, var(--icon-blue), var(--icon-green));
    }
    .icon-auth-orbit.o1{ width:260px; height:80px; top:4%; left:-40px; }
    .icon-auth-orbit.o2{ width:220px; height:220px; bottom:-80px; right:-40px; border-radius:50%; }
    .icon-auth-orbit.o3{ width:140px; height:46px; top:58%; right:10%; }

    .icon-auth-inner{
      width:100%;
      max-width:980px;
      position:relative;
      z-index:2;
    }

    .icon-auth-tag{
      display:inline-block;
      padding:4px 12px;
      border-radius:999px;
      font-size:11px;
      letter-spacing:.08em;
      text-transform:uppercase;
      background:rgba(21,150,140,.1);
      color:var(--icon-green);
      margin-bottom:12px;
    }

    .icon-auth-title{ font-size:34px; font-weight:700; margin:0 0 10px; }
    .icon-auth-sub{ font-size:15px; color:var(--text-mid); max-width:600px; margin:0 0 28px; }

    .icon-auth-grid{
      display:grid;
      grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);
      gap:30px;
    }
    @media (max-width:900px){ .icon-auth-grid{ grid-template-columns:1fr; } }

    .icon-auth-card,
    .icon-auth-side{
      background:#fff;
      border-radius:22px;
      padding:26px 24px;
      box-shadow:0 16px 40px rgba(0,0,0,.06);
    }

    .icon-auth-logo{
      max-width:180px;
      margin:0 0 18px;
      display:block;
    }

    .icon-auth-banner{
      margin:0 0 14px;
      padding:12px 14px;
      border-radius:14px;
      border:1px solid rgba(20,164,207,.25);
      background:#ecfeff;
      color:#0a3b34;
      font-weight:800;
    }

    /* Theme My Login styling */
    .icon-auth-card .tml{ margin:0; }
    .icon-auth-card .tml h2{ display:none; }

    .icon-auth-card input[type="text"],
    .icon-auth-card input[type="email"],
    .icon-auth-card input[type="password"]{
      width:100%;
      padding:10px 12px;
      border-radius:10px;
      border:1px solid rgba(10,59,52,.15);
      font-size:14px;
      box-sizing:border-box;
    }
    .icon-auth-card input:focus{
      outline:none;
      border-color:var(--icon-blue);
      box-shadow:0 0 0 1px rgba(20,164,207,.25);
    }

    .icon-auth-card button,
    .icon-auth-card input[type="submit"]{
      width:100%;
      margin-top:10px;
      padding:12px 22px;
      font-size:14px;
      font-weight:600;
      border-radius:999px;
      border:none;
      cursor:pointer;
      background:linear-gradient(135deg,var(--icon-blue),var(--icon-green));
      color:#fff;
      box-shadow:0 12px 30px rgba(20,164,207,.35);
    }

    /* Remove the “two buttons” area you mentioned */
    .icon-auth-card .tml-action-links{ display:none !important; }

    /* Keep Register + Lost password links */
    .icon-auth-card .tml-links{
      margin-top:14px;
      font-size:13px;
    }
    .icon-auth-card .tml-links a{
      color:var(--icon-blue);
      text-decoration:none;
      font-weight:600;
    }
    .icon-auth-card .tml-links a:hover{ text-decoration:underline; }

    .icon-auth-side h3{ font-size:18px; margin:0 0 10px; }
    .icon-auth-side p, .icon-auth-side li{ font-size:14px; color:var(--text-mid); }
    .icon-auth-side ul{ padding-left:18px; margin:0 0 12px; }

    .icon-auth-back{
      display:inline-block;
      margin-top:18px;
      font-size:13px;
      color:var(--icon-blue);
      text-decoration:none;
      font-weight:600;
    }
    .icon-auth-back:hover{ text-decoration:underline; }
    </style>

    <section class="icon-auth-wrapper">
      <div class="icon-auth-orbit o1"></div>
      <div class="icon-auth-orbit o2"></div>
      <div class="icon-auth-orbit o3"></div>

      <div class="icon-auth-inner">
        <div class="icon-auth-tag"><?php echo esc_html( $tag ); ?></div>
        <h1 class="icon-auth-title"><?php echo esc_html( $title ); ?></h1>
        <p class="icon-auth-sub"><?php echo esc_html( $subtitle ); ?></p>

        <div class="icon-auth-grid">
          <div class="icon-auth-card">
            <img class="icon-auth-logo"
                 src="<?php echo esc_url( icon_psy_auth_logo_url() ); ?>"
                 alt="Icon Catalyst">

            <?php echo $success_banner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <a class="icon-auth-back" href="<?php echo esc_url( icon_psy_auth_back_url() ); ?>">
              ← Back to Icon Talent
            </a>
          </div>

          <aside class="icon-auth-side">
            <h3>Who this portal is for</h3>
            <ul>
              <li>Leaders completing ICON assessments</li>
              <li>HR & Talent partners sponsoring projects</li>
              <li>Managers reviewing reports & insights</li>
            </ul>
            <p>
              If your organisation is onboarding or you’re unsure which login to use,
              your ICON Talent contact will guide you.
            </p>
          </aside>
        </div>
      </div>
    </section>
    <?php

    return ob_get_clean();

}, 50 );
