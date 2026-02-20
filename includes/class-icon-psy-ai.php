<?php

if ( ! defined( 'ABSPATH' ) ) {

    exit;

}



/**

 * Icon_PSY_AI

 *

 * Central helper for participant narrative insights.

 *

 * - Used in the client portal (participant view)


 *

 * NOTE:

 * By default this uses a filter so you can plug in your own narrative generator.

 * If nothing is configured, it returns a safe, human-written placeholder.

 */

class Icon_PSY_AI {



    /**

     * Bootstrap. Kept for future hooks if needed.

     */

    public static function init() {

        // Reserved for future use.

    }



    /**

     * Return a structured insights array for a participant.

     *

     * @param int $participant_id

     * @return array

     */

    public static function get_participant_insights( $participant_id ) {

        global $wpdb;



        $participant_id = (int) $participant_id;

        if ( $participant_id <= 0 ) {

            return array(

                'ready'  => false,

                'error'  => 'Invalid participant ID.',

                'source' => 'error',

            );

        }



        $projects_table     = $wpdb->prefix . 'icon_psy_projects';

        $participants_table = $wpdb->prefix . 'icon_psy_participants';

        $raters_table       = $wpdb->prefix . 'icon_psy_raters';



        // Fetch participant

        $participant = $wpdb->get_row(

            $wpdb->prepare(

                "SELECT p.*, pr.name AS project_name, pr.client_name AS client_name

                 FROM {$participants_table} p

                 LEFT JOIN {$projects_table} pr ON p.project_id = pr.id

                 WHERE p.id = %d",

                $participant_id

            )

        );



        if ( ! $participant ) {

            return array(

                'ready'  => false,

                'error'  => 'Participant not found.',

                'source' => 'error',

            );

        }



        // Fetch raters for this participant

        $raters = $wpdb->get_results(

            $wpdb->prepare(

                "SELECT * FROM {$raters_table} WHERE participant_id = %d",

                $participant_id

            )

        );



        $total_raters     = 0;

        $completed_raters = 0;

        $status_counts    = array();



        if ( ! empty( $raters ) ) {

            foreach ( $raters as $r ) {

                $total_raters++;

                $status = ! empty( $r->status ) ? $r->status : 'invited';



                if ( ! isset( $status_counts[ $status ] ) ) {

                    $status_counts[ $status ] = 0;

                }

                $status_counts[ $status ]++;



                if ( $status === 'completed' ) {

                    $completed_raters++;

                }

            }

        }



        $progress_percent = ( $total_raters > 0 ) ? round( ( $completed_raters / $total_raters ) * 100 ) : 0;



        // Readiness rule: tweak as you like.

        // Example: need at least 3 completed raters.

        $min_completed_for_ready = 3;



        $is_ready = ( $completed_raters >= $min_completed_for_ready );



        // If you already have a central readiness engine, use it:

        if ( class_exists( 'Icon_PSY_Insights' ) && method_exists( 'Icon_PSY_Insights', 'get_participant_readiness' ) ) {

            $readiness = Icon_PSY_Insights::get_participant_readiness( $participant_id );

            if ( is_array( $readiness ) && isset( $readiness['ready'] ) ) {

                $is_ready = (bool) $readiness['ready'];

            }

        }



        $context = array(

            'participant_id'    => $participant_id,

            'participant_name'  => $participant->name,

            'participant_email' => $participant->email,

            'role'              => $participant->role,

            'project_id'        => $participant->project_id,

            'project_name'      => $participant->project_name,

            'client_name'       => $participant->client_name,

            'total_raters'      => $total_raters,

            'completed_raters'  => $completed_raters,

            'status_counts'     => $status_counts,

            'progress_percent'  => $progress_percent,

            'ready'             => $is_ready,

        );



        // Try to use cached insights first

        $cached = self::get_cached_insights( $participant_id );

        if ( $cached ) {

            $cached['ready']  = $is_ready;

            $cached['cached'] = true;

            return $cached;

        }



        // External narrative generator hook:

        // Return either:

        // - a string, OR

        // - an array with keys: summary, strengths, development, actions, manager_summary

        $ai_output = apply_filters( 'icon_psy_ai_generate_participant_insights', null, $context );



        $insights = null;

        $source   = 'placeholder';



        if ( is_array( $ai_output ) ) {

            // Normalise array output

            $insights = array(

                'summary'          => isset( $ai_output['summary'] ) ? (string) $ai_output['summary'] : '',

                'strengths'        => isset( $ai_output['strengths'] ) && is_array( $ai_output['strengths'] ) ? $ai_output['strengths'] : array(),

                'development'      => isset( $ai_output['development'] ) && is_array( $ai_output['development'] ) ? $ai_output['development'] : array(),

                'actions'          => isset( $ai_output['actions'] ) && is_array( $ai_output['actions'] ) ? $ai_output['actions'] : array(),

                'manager_summary'  => isset( $ai_output['manager_summary'] ) ? (string) $ai_output['manager_summary'] : '',

            );

            $source = 'external';

        } elseif ( is_string( $ai_output ) && $ai_output !== '' ) {

            // If a single string is returned, treat it as a block summary

            $insights = array(

                'summary'         => $ai_output,

                'strengths'       => array(),

                'development'     => array(),

                'actions'         => array(),

                'manager_summary' => '',

            );

            $source = 'external-string';

        } else {

            // Fallback: simple, human-written placeholder based on progress

            $message = '';



            if ( $is_ready ) {

                $message = sprintf(

                    '%s has received feedback from %d out of %d raters. This narrative is ready to support deeper coaching conversations focusing on their strengths and development priorities.',

                    $participant->name ? $participant->name : 'This participant',

                    $completed_raters,

                    $total_raters

                );

            } elseif ( $total_raters > 0 ) {

                $message = sprintf(

                    '%s is still in progress: %d of %d raters have completed feedback. Once more feedback is collected, this narrative will provide a clearer view of strengths and development needs.',

                    $participant->name ? $participant->name : 'This participant',

                    $completed_raters,

                    $total_raters

                );

            } else {

                $message = sprintf(

                    'No rater feedback has been completed yet for %s. The narrative will be available once raters start submitting their responses.',

                    $participant->name ? $participant->name : 'this participant'

                );

            }



            $insights = array(

                'summary'         => $message,

                'strengths'       => array(),

                'development'     => array(),

                'actions'         => array(),

                'manager_summary' => '',

            );

            $source = 'placeholder';

        }



        $result = array_merge(

            $insights,

            array(

                'ready'        => $is_ready,

                'context'      => $context,

                'source'       => $source,

                'cached'       => false,

                'generated_at' => current_time( 'mysql' ),

            )

        );



        // Cache for future use

        self::cache_insights( $participant_id, $result );



        return $result;

    }



    /**

     * Render the narrative insights card for the client portal (HTML).

     *

     * @param int $participant_id

     * @return string

     */

    public static function render_portal_box( $participant_id ) {

        $insights = self::get_participant_insights( $participant_id );



        $participant_name = isset( $insights['context']['participant_name'] ) && $insights['context']['participant_name']

            ? $insights['context']['participant_name']

            : 'This participant';



        $ready        = ! empty( $insights['ready'] );

        $summary      = isset( $insights['summary'] ) ? $insights['summary'] : '';

        $strengths    = ! empty( $insights['strengths'] ) && is_array( $insights['strengths'] ) ? $insights['strengths'] : array();

        $development  = ! empty( $insights['development'] ) && is_array( $insights['development'] ) ? $insights['development'] : array();

        $actions      = ! empty( $insights['actions'] ) && is_array( $insights['actions'] ) ? $insights['actions'] : array();

        $mgr_summary  = isset( $insights['manager_summary'] ) ? $insights['manager_summary'] : '';

        $source_label = ( $insights['source'] === 'placeholder' ) ? 'Preview' : 'Generated';



        ob_start();

        ?>

        <div class="icon-psy-card icon-psy-ai-box" style="margin-top:20px;">

            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">

                <div>

                    <h2 style="margin:0 0 4px; font-size:16px;">Narrative Insights</h2>

                    <p style="margin:0; font-size:12px; color:#6b7280;">

                        A narrative view of <?php echo esc_html( $participant_name ); ?>’s feedback profile.

                    </p>

                </div>

                <span style="

                    display:inline-flex;

                    align-items:center;

                    padding:2px 8px;

                    border-radius:999px;

                    font-size:10px;

                    text-transform:uppercase;

                    letter-spacing:0.06em;

                    background:#ecfdf5;

                    color:#047857;

                ">

                    <?php echo esc_html( $source_label ); ?>

                </span>

            </div>



            <?php if ( ! $ready ) : ?>

                <p style="margin-top:12px; font-size:13px; color:#374151;">

                    This narrative will fully unlock once enough raters have completed feedback for this participant.

                </p>

                <p style="margin-top:6px; font-size:12px; color:#6b7280;">

                    In the meantime, use the completion stats and charts above to monitor progress.

                </p>

            <?php endif; ?>



            <?php if ( $summary ) : ?>

                <div style="margin-top:14px; font-size:13px; color:#111827; line-height:1.5;">

                    <?php echo wp_kses_post( wpautop( $summary ) ); ?>

                </div>

            <?php endif; ?>



            <div style="display:flex; flex-wrap:wrap; gap:18px; margin-top:14px;">

                <?php if ( ! empty( $strengths ) ) : ?>

                    <div style="flex:1 1 220px;">

                        <h3 style="margin:0 0 6px; font-size:13px;">Top strengths</h3>

                        <ul style="margin:0; padding-left:18px; font-size:12px; color:#065f46;">

                            <?php foreach ( $strengths as $item ) : ?>

                                <li><?php echo esc_html( $item ); ?></li>

                            <?php endforeach; ?>

                        </ul>

                    </div>

                <?php endif; ?>



                <?php if ( ! empty( $development ) ) : ?>

                    <div style="flex:1 1 220px;">

                        <h3 style="margin:0 0 6px; font-size:13px;">Development priorities</h3>

                        <ul style="margin:0; padding-left:18px; font-size:12px; color:#92400e;">

                            <?php foreach ( $development as $item ) : ?>

                                <li><?php echo esc_html( $item ); ?></li>

                            <?php endforeach; ?>

                        </ul>

                    </div>

                <?php endif; ?>

            </div>



            <?php if ( ! empty( $actions ) ) : ?>

                <div style="margin-top:14px;">

                    <h3 style="margin:0 0 6px; font-size:13px;">Suggested coaching actions</h3>

                    <ol style="margin:0; padding-left:18px; font-size:12px; color:#111827;">

                        <?php foreach ( $actions as $item ) : ?>

                            <li><?php echo esc_html( $item ); ?></li>

                        <?php endforeach; ?>

                    </ol>

                </div>

            <?php endif; ?>



            <?php if ( $mgr_summary ) : ?>

                <div style="margin-top:14px; padding-top:10px; border-top:1px dashed #e5e7eb;">

                    <h3 style="margin:0 0 4px; font-size:13px;">Manager focus</h3>

                    <p style="margin:0; font-size:12px; color:#4b5563;">

                        <?php echo wp_kses_post( $mgr_summary ); ?>

                    </p>

                </div>

            <?php endif; ?>

        </div>

        <?php



        return ob_get_clean();

    }



    /**

     * Get cached insights (stored in wp_options).

     *

     * @param int $participant_id

     * @return array|null

     */

    protected static function get_cached_insights( $participant_id ) {

        $participant_id = (int) $participant_id;

        if ( $participant_id <= 0 ) {

            return null;

        }



        $key   = 'icon_psy_ai_p_' . $participant_id;

        $value = get_option( $key );



        if ( ! $value || ! is_array( $value ) ) {

            return null;

        }



        return $value;

    }



    /**

     * Cache insights in wp_options.

     *

     * @param int   $participant_id

     * @param array $data

     * @return void

     */

    protected static function cache_insights( $participant_id, $data ) {

        $participant_id = (int) $participant_id;

        if ( $participant_id <= 0 ) {

            return;

        }



        $key = 'icon_psy_ai_p_' . $participant_id;



        // Do NOT autoload to keep memory sane.

        update_option( $key, $data, false );

    }



    /**

     * Generate competencies via OpenAI (or other LLM).

     *

     * @param array $args {

     *   @type string $role

     *   @type string $seniority

     *   @type string $assessment_type

     *   @type string $industry

     *   @type string $focus_areas

     *   @type int    $framework_id

     *   @type string $mode single|framework

     * }

     *

     * @return array|WP_Error

     */

    public static function generate_competencies( $args ) {

        $api_key = get_option( 'icon_psy_openai_api_key' );



        if ( empty( $api_key ) ) {

            return new WP_Error(

                'icon_psy_no_api_key',

                'OpenAI API key is not configured. Please set it in Icon Psych settings.'

            );

        }



        $role            = isset( $args['role'] ) ? $args['role'] : '';

        $seniority       = isset( $args['seniority'] ) ? $args['seniority'] : '';

        $assessment_type = isset( $args['assessment_type'] ) ? $args['assessment_type'] : '';

        $industry        = isset( $args['industry'] ) ? $args['industry'] : '';

        $focus_areas     = isset( $args['focus_areas'] ) ? $args['focus_areas'] : '';

        $mode            = isset( $args['mode'] ) ? $args['mode'] : 'framework';

        $framework_id    = isset( $args['framework_id'] ) ? (int) $args['framework_id'] : 1;



        $num_competencies = ( 'single' === $mode ) ? 1 : 8; // 6–10 typical



        $system_message = 'You are the ICON AI Competency Designer, creating professional, real-world '

            . 'leadership and behavioural competencies for 360, 180, team and individual assessments. '

            . 'Respond ONLY with valid JSON.';



        $user_prompt = array(

            'role'            => $role,

            'seniority'       => $seniority,

            'assessment_type' => $assessment_type,

            'industry'        => $industry,

            'focus_areas'     => $focus_areas,

            'num_competencies'=> $num_competencies,

        );



        $user_content  = "Generate {$num_competencies} competencies in JSON format. The JSON must be an object with a single key \"competencies\" which is an array.\n\n";

        $user_content .= "Inputs:\n" . wp_json_encode( $user_prompt, JSON_PRETTY_PRINT ) . "\n\n";

        $user_content .= "Each competency in competencies[] MUST have the following fields:\n";

        $user_content .= "- code (short identifier, e.g. CUST01)\n";

        $user_content .= "- name\n";

        $user_content .= "- description\n";

        $user_content .= "- module_tag (one of: core, team, project, culture, custom)\n";

        $user_content .= "- behaviours (array of 4–6 short bullet-point strings)\n";

        $user_content .= "- rating_scale (object with keys 1–5 describing each level)\n";

        $user_content .= "- coaching_questions (array of 3–5 questions)\n";

        $user_content .= "- development_actions (array of 3–5 suggested actions)\n";



        $body = array(

            'model' => 'gpt-4.1-mini',

            'messages' => array(

                array(

                    'role'    => 'system',

                    'content' => $system_message,

                ),

                array(

                    'role'    => 'user',

                    'content' => $user_content,

                ),

            ),

            'response_format' => array(

                'type' => 'json_object',

            ),

        );



        $response = wp_remote_post(

            'https://api.openai.com/v1/chat/completions',

            array(

                'headers' => array(

                    'Authorization' => 'Bearer ' . $api_key,

                    'Content-Type'  => 'application/json',

                ),

                'body'    => wp_json_encode( $body ),

                'timeout' => 40,

            )

        );



        if ( is_wp_error( $response ) ) {

            return new WP_Error(

                'icon_psy_ai_http_error',

                'Error calling OpenAI: ' . $response->get_error_message()

            );

        }



        $code = wp_remote_retrieve_response_code( $response );

        $body_str = wp_remote_retrieve_body( $response );



        if ( $code < 200 || $code >= 300 ) {

            return new WP_Error(

                'icon_psy_ai_bad_status',

                'OpenAI returned HTTP ' . $code . '. Body: ' . $body_str

            );

        }



        $data = json_decode( $body_str, true );

        if ( ! $data || ! isset( $data['choices'][0]['message']['content'] ) ) {

            return new WP_Error(

                'icon_psy_ai_bad_response',

                'Unexpected response from OpenAI.'

            );

        }



        $content = $data['choices'][0]['message']['content'];



        $json = json_decode( $content, true );

        if ( ! $json || ! isset( $json['competencies'] ) || ! is_array( $json['competencies'] ) ) {

            return new WP_Error(

                'icon_psy_ai_bad_json',

                'OpenAI did not return the expected JSON format.'

            );

        }



        // Attach framework_id to each generated competency for convenience

        $competencies = array();

        foreach ( $json['competencies'] as $comp ) {

            if ( ! is_array( $comp ) ) {

                continue;

            }

            $comp['framework_id'] = $framework_id;

            $competencies[] = $comp;

        }



        return $competencies;

    }



}

                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $development ) ) : ?>
                    <div style="flex:1 1 220px;">
                        <h3 style="margin:0 0 6px; font-size:13px;">Development priorities</h3>
                        <ul style="margin:0; padding-left:18px; font-size:12px; color:#92400e;">
                            <?php foreach ( $development as $item ) : ?>
                                <li><?php echo esc_html( $item ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $actions ) ) : ?>
                <div style="margin-top:14px;">
                    <h3 style="margin:0 0 6px; font-size:13px;">Suggested coaching actions</h3>
                    <ol style="margin:0; padding-left:18px; font-size:12px; color:#111827;">
                        <?php foreach ( $actions as $item ) : ?>
                            <li><?php echo esc_html( $item ); ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endif; ?>

            <?php if ( $mgr_summary ) : ?>
                <div style="margin-top:14px; padding-top:10px; border-top:1px dashed #e5e7eb;">
                    <h3 style="margin:0 0 4px; font-size:13px;">Manager focus</h3>
                    <p style="margin:0; font-size:12px; color:#4b5563;">
                        <?php echo wp_kses_post( $mgr_summary ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Plain-text / HTML block for PDF reports.
     * You can embed this directly in your report HTML.
     *
     * @param int $participant_id
     * @return string
     */
    public static function render_pdf_block( $participant_id ) {
        $insights = self::get_participant_insights( $participant_id );

        $participant_name = isset( $insights['context']['participant_name'] ) && $insights['context']['participant_name']
            ? $insights['context']['participant_name']
            : 'This participant';

        $summary     = isset( $insights['summary'] ) ? $insights['summary'] : '';
        $strengths   = ! empty( $insights['strengths'] ) && is_array( $insights['strengths'] ) ? $insights['strengths'] : array();
        $development = ! empty( $insights['development'] ) && is_array( $insights['development'] ) ? $insights['development'] : array();
        $actions     = ! empty( $insights['actions'] ) && is_array( $insights['actions'] ) ? $insights['actions'] : array();
        $mgr_summary = isset( $insights['manager_summary'] ) ? $insights['manager_summary'] : '';

        ob_start();
        ?>
        <h2>Narrative Insights</h2>

        <p><em>A narrative interpretation of the feedback profile for <?php echo esc_html( $participant_name ); ?>.</em></p>

        <?php if ( $summary ) : ?>
            <?php echo wp_kses_post( wpautop( $summary ) ); ?>
        <?php endif; ?>

        <?php if ( ! empty( $strengths ) ) : ?>
            <h3>Top strengths</h3>
            <ul>
                <?php foreach ( $strengths as $item ) : ?>
                    <li><?php echo esc_html( $item ); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ( ! empty( $development ) ) : ?>
            <h3>Development priorities</h3>
            <ul>
                <?php foreach ( $development as $item ) : ?>
                    <li><?php echo esc_html( $item ); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ( ! empty( $actions ) ) : ?>
            <h3>Suggested coaching actions</h3>
            <ol>
                <?php foreach ( $actions as $item ) : ?>
                    <li><?php echo esc_html( $item ); ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php if ( $mgr_summary ) : ?>
            <h3>Manager focus</h3>
            <p><?php echo wp_kses_post( wpautop( $mgr_summary ) ); ?></p>
        <?php endif; ?>
        <?php

        return ob_get_clean();
    }

    /**
     * Get cached insights (stored in wp_options).
     *
     * @param int $participant_id
     * @return array|null
     */
    protected static function get_cached_insights( $participant_id ) {
        $participant_id = (int) $participant_id;
        if ( $participant_id <= 0 ) {
            return null;
        }

        $key   = 'icon_psy_ai_p_' . $participant_id;
        $value = get_option( $key );

        if ( ! $value || ! is_array( $value ) ) {
            return null;
        }

        return $value;
    }

    /**
     * Cache insights in wp_options.
     *
     * @param int   $participant_id
     * @param array $data
     * @return void
     */
    protected static function cache_insights( $participant_id, $data ) {
        $participant_id = (int) $participant_id;
        if ( $participant_id <= 0 ) {
            return;
        }

        $key = 'icon_psy_ai_p_' . $participant_id;

        // Do NOT autoload to keep memory sane.
        update_option( $key, $data, false );
    }

    /**
     * Generate competencies via OpenAI (or other LLM).
     *
     * @param array $args {
     *   @type string $role
     *   @type string $seniority
     *   @type string $assessment_type
     *   @type string $industry
     *   @type string $focus_areas
     *   @type int    $framework_id
     *   @type string $mode single|framework
     * }
     *
     * @return array|WP_Error
     */
    public static function generate_competencies( $args ) {
        $api_key = get_option( 'icon_psy_openai_api_key' );

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'icon_psy_no_api_key',
                'OpenAI API key is not configured. Please set it in Icon Psych settings.'
            );
        }

        $role            = isset( $args['role'] ) ? $args['role'] : '';
        $seniority       = isset( $args['seniority'] ) ? $args['seniority'] : '';
        $assessment_type = isset( $args['assessment_type'] ) ? $args['assessment_type'] : '';
        $industry        = isset( $args['industry'] ) ? $args['industry'] : '';
        $focus_areas     = isset( $args['focus_areas'] ) ? $args['focus_areas'] : '';
        $mode            = isset( $args['mode'] ) ? $args['mode'] : 'framework';
        $framework_id    = isset( $args['framework_id'] ) ? (int) $args['framework_id'] : 1;

        $num_competencies = ( 'single' === $mode ) ? 1 : 8; // 6–10 typical

        $system_message = 'You are the ICON AI Competency Designer, creating professional, real-world '
            . 'leadership and behavioural competencies for 360, 180, team and individual assessments. '
            . 'Respond ONLY with valid JSON.';

        $user_prompt = array(
            'role'            => $role,
            'seniority'       => $seniority,
            'assessment_type' => $assessment_type,
            'industry'        => $industry,
            'focus_areas'     => $focus_areas,
            'num_competencies'=> $num_competencies,
        );

        $user_content  = "Generate {$num_competencies} competencies in JSON format. The JSON must be an object with a single key \"competencies\" which is an array.\n\n";
        $user_content .= "Inputs:\n" . wp_json_encode( $user_prompt, JSON_PRETTY_PRINT ) . "\n\n";
        $user_content .= "Each competency in competencies[] MUST have the following fields:\n";
        $user_content .= "- code (short identifier, e.g. CUST01)\n";
        $user_content .= "- name\n";
        $user_content .= "- description\n";
        $user_content .= "- module_tag (one of: core, team, project, culture, custom)\n";
        $user_content .= "- behaviours (array of 4–6 short bullet-point strings)\n";
        $user_content .= "- rating_scale (object with keys 1–5 describing each level)\n";
        $user_content .= "- coaching_questions (array of 3–5 questions)\n";
        $user_content .= "- development_actions (array of 3–5 suggested actions)\n";

        $body = array(
            'model' => 'gpt-4.1-mini',
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => $system_message,
                ),
                array(
                    'role'    => 'user',
                    'content' => $user_content,
                ),
            ),
            'response_format' => array(
                'type' => 'json_object',
            ),
        );

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 40,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'icon_psy_ai_http_error',
                'Error calling OpenAI: ' . $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body_str = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'icon_psy_ai_bad_status',
                'OpenAI returned HTTP ' . $code . '. Body: ' . $body_str
            );
        }

        $data = json_decode( $body_str, true );
        if ( ! $data || ! isset( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error(
                'icon_psy_ai_bad_response',
                'Unexpected response from OpenAI.'
            );
        }

        $content = $data['choices'][0]['message']['content'];

        $json = json_decode( $content, true );
        if ( ! $json || ! isset( $json['competencies'] ) || ! is_array( $json['competencies'] ) ) {
            return new WP_Error(
                'icon_psy_ai_bad_json',
                'OpenAI did not return the expected JSON format.'
            );
        }

        // Attach framework_id to each generated competency for convenience
        $competencies = array();
        foreach ( $json['competencies'] as $comp ) {
            if ( ! is_array( $comp ) ) {
                continue;
            }
            $comp['framework_id'] = $framework_id;
            $competencies[] = $comp;
        }

        return $competencies;
    }

}
