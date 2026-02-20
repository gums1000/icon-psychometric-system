<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Icon_PSY_Insights {

    /**
     * Get readiness data for a participant.
     *
     * Returns:
     * [
     *   'ready'          => bool,
     *   'status'         => 'ready'|'partial'|'not_ready',
     *   'completed'      => int,
     *   'total'          => int,
     *   'percentage'     => int,
     *   'min_required'   => int,
     *   'blocked_reason' => string
     * ]
     */
    public static function get_participant_readiness( $participant_id ) {
        global $wpdb;

        $participant_id      = (int) $participant_id;
        $participants_table  = $wpdb->prefix . 'icon_project_participants';
        $raters_table        = $wpdb->prefix . 'icon_project_raters';

        // Fetch the participant row
        $participant = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $participants_table WHERE id = %d",
                $participant_id
            )
        );

        if ( ! $participant ) {
            return [
                'ready'          => false,
                'status'         => 'not_ready',
                'completed'      => 0,
                'total'          => 0,
                'percentage'     => 0,
                'min_required'   => 3,
                'blocked_reason' => 'Participant not found.'
            ];
        }

        // Determine minimum raters (per participant rule)
        $min_required = 3;
        if ( isset( $participant->min_raters_required ) && (int) $participant->min_raters_required > 0 ) {
            $min_required = (int) $participant->min_raters_required;
        }

        // Count total raters
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $raters_table WHERE participant_id = %d",
                $participant_id
            )
        );

        // Count completed raters
        $completed = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $raters_table WHERE participant_id = %d AND status = %s",
                $participant_id,
                'completed'
            )
        );

        // Calculate %
        $percentage = $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0;

        // Determine readiness state
        $status         = 'not_ready';
        $ready          = false;
        $blocked_reason = '';

        if ( $completed >= $min_required ) {
            $status = 'ready';
            $ready  = true;
        }
        elseif ( $completed > 0 && $completed < $min_required ) {
            $status         = 'partial';
            $blocked_reason = sprintf(
                'Only %d out of %d required raters have completed their feedback.',
                $completed,
                $min_required
            );
        }
        elseif ( $total > 0 && $completed === 0 ) {
            $status         = 'not_ready';
            $blocked_reason = 'Raters have been invited but none have completed their feedback yet.';
        }
        else { // $total == 0
            $status         = 'not_ready';
            $blocked_reason = 'No raters have been added yet.';
        }

        return [
            'ready'          => $ready,
            'status'         => $status,
            'completed'      => $completed,
            'total'          => $total,
            'percentage'     => $percentage,
            'min_required'   => $min_required,
            'blocked_reason' => $blocked_reason,
        ];
    }



    /**
     * Render a coloured readiness pill.
     */
    public static function render_pill( $readiness ) {

        $status = isset( $readiness['status'] ) ? $readiness['status'] : 'not_ready';

        switch ( $status ) {
            case 'ready':
                $label = 'Ready';
                $class = 'icon-psy-pill-ready';
                break;

            case 'partial':
                $label = 'Partially Ready';
                $class = 'icon-psy-pill-partial';
                break;

            default:
                $label = 'Not Ready';
                $class = 'icon-psy-pill-notready';
        }

        echo sprintf(
            '<span class="icon-psy-pill %s">%s</span>',
            esc_attr( $class ),
            esc_html( $label )
        );
    }



    /**
     * Render compact progress bar.
     */
    public static function render_progress( $readiness ) {

        $percentage = isset( $readiness['percentage'] ) ? (int) $readiness['percentage'] : 0;
        $completed  = isset( $readiness['completed'] ) ? (int) $readiness['completed'] : 0;
        $total      = isset( $readiness['total'] ) ? (int) $readiness['total'] : 0;

        $percentage = max( 0, min( 100, $percentage ) );
        ?>

        <div class="icon-psy-progress-wrap">
            <div class="icon-psy-progress-bar">
                <div class="icon-psy-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%;"></div>
            </div>
            <div class="icon-psy-progress-meta">
                <?php echo esc_html( "$completed / $total raters completed" ); ?>
            </div>
        </div>

        <?php
    }
}
