<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Participant Portal
 *
 * Shortcode: [icon_psy_participant_portal]
 */
function icon_psy_render_participant_portal( $atts ) {

if ( ! is_user_logged_in() ) {
    return '<p>You must be logged in to access your assessment portal.</p>';
}

global $wpdb;

$participants_table = $wpdb->prefix . 'icon_psy_participants';
$projects_table     = $wpdb->prefix . 'icon_psy_projects';
$raters_table       = $wpdb->prefix . 'icon_psy_raters';

// Get logged in user email
$user       = wp_get_current_user();
$user_email = $user->user_email;

// Find participant record tied to email
$participant = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT 
            p.*,
            pr.name        AS project_name,
            pr.client_name AS client_name,
            pr.status      AS project_status
         FROM {$participants_table} p
         LEFT JOIN {$projects_table} pr ON p.project_id = pr.id
         WHERE p.email = %s
         LIMIT 1",
        $user_email
    )
);

if ( ! $participant ) {
    return '<p>No participant record is linked to your account.</p>';
}

$participant_id = (int) $participant->id;

// Load raters for this participant
$raters = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * 
         FROM {$raters_table}
         WHERE participant_id = %d
         ORDER BY id ASC",
        $participant_id
    )
);

// Build report URL
$report_url = add_query_arg(
    array( 'participant_id' => $participant_id ),
    home_url( '/feedback-report/' )
);

ob_start();
?>
<div class="icon-psy-wrapper" style="max-width:900px;margin:0 auto;padding:20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

    <div class="icon-psy-card" style="
        background:#ffffff;
        border-radius:14px;
        padding:22px 26px;
        box-shadow:0 10px 25px rgba(0,0,0,0.07);
        border:1px solid #e5f2ec;
    ">
        <h2 style="margin:0 0 6px;font-size:22px;font-weight:700;color:#0f4f47;">
            Your ICON Catalyst Assessment
        </h2>

        <p style="margin:0 0 14px;color:#425b56;font-size:14px;">
            Welcome <strong><?php echo esc_html( $participant->name ); ?></strong> —
            this is your personal assessment dashboard.
        </p>

        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;font-size:13px;">
            <div style="
                background:#ecfdf5;
                padding:4px 10px;
                border-radius:30px;
                border:1px solid #bbf7d0;
            ">
                Project: <?php echo esc_html( $participant->project_name ); ?>
            </div>

            <?php if ( $participant->client_name ) : ?>
                <div style="
                    background:#eff6ff;
                    padding:4px 10px;
                    border-radius:30px;
                    border:1px solid #bfdbfe;
                ">
                    Client: <?php echo esc_html( $participant->client_name ); ?>
                </div>
            <?php endif; ?>

            <div style="
                background:#f3f4f6;
                padding:4px 10px;
                border-radius:30px;
                border:1px solid #e5e7eb;
            ">
                Status: <?php echo ucfirst( esc_html( $participant->project_status ) ); ?>
            </div>
        </div>

        <h3 style="margin:20px 0 8px;font-size:16px;color:#064e3b;">Your Raters</h3>

        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="padding:8px;text-align:left;border-bottom:1px solid #e5e7eb;">Name</th>
                    <th style="padding:8px;text-align:left;border-bottom:1px solid #e5e7eb;">Relationship</th>
                    <th style="padding:8px;text-align:left;border-bottom:1px solid #e5e7eb;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $raters ) ) : ?>
                <tr><td colspan="3" style="padding:12px;color:#6b7280;">No raters assigned yet.</td></tr>
            <?php else : ?>
                <?php foreach ( $raters as $r ) : ?>
                    <tr>
                        <td style="padding:8px;border-bottom:1px solid #f1f5f9;">
                            <?php echo esc_html( $r->name ); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #f1f5f9;">
                            <?php echo esc_html( ucfirst( $r->relationship ) ); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #f1f5f9;">
                            <?php if ( $r->status === 'completed' ) : ?>
                                <span style="color:#065f46;font-weight:600;">Completed</span>
                            <?php else : ?>
                                <span style="color:#b91c1c;font-weight:600;">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top:20px;">
            <a href="<?php echo esc_url( $report_url ); ?>" class="button button-primary" style="
                background:#0f766e;
                border-color:#0d6b64;
                color:#ffffff;
                padding:10px 18px;
                border-radius:6px;
                font-weight:600;
                text-decoration:none;
            ">
                View Feedback Report
            </a>
        </div>

    </div>
</div>
<?php
return ob_get_clean();
}
