<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'icon_psy_package_credit_rules' ) ) {
    function icon_psy_package_credit_rules() {
        return array(
            'tool_only' => array(
                'mode' => 'per_participant',
                'cost' => 1,
            ),
            'leadership_assessment' => array(
                'mode' => 'per_participant',
                'cost' => 1,
            ),
            'feedback_360' => array(
                'mode' => 'per_participant',
                'cost' => 2,
            ),
            'bundle_debrief' => array(
                'mode' => 'per_participant',
                'cost' => 4,
            ),
            'full_package' => array(
                'mode' => 'per_participant',
                'cost' => 8,
            ),
            'high_performing_teams' => array(
                'mode' => 'per_project',
                'cost' => 2,
            ),
            // subscription_50 handled separately (not credits)
            // custom_* and enterprise handled by invoicing (not credits)
        );
    }
}

if ( ! function_exists( 'icon_psy_get_project_credit_rule' ) ) {
    function icon_psy_get_project_credit_rule( $project_row ) {

        if ( ! is_object( $project_row ) ) {
            return null;
        }

        $pkg = '';

        if ( isset( $project_row->icon_pkg ) && (string) $project_row->icon_pkg !== '' ) {
            $pkg = (string) $project_row->icon_pkg;
        } elseif ( isset( $project_row->reference ) && (string) $project_row->reference !== '' ) {
            if ( function_exists( 'icon_psy_extract_icon_cfg_from_reference' ) ) {
                $cfg = icon_psy_extract_icon_cfg_from_reference( (string) $project_row->reference );
                if ( is_array( $cfg ) && isset( $cfg['icon_pkg'] ) ) {
                    $pkg = (string) $cfg['icon_pkg'];
                }
            }
        }

        $rules = icon_psy_package_credit_rules();

        if ( $pkg && isset( $rules[ $pkg ] ) ) {
            return $rules[ $pkg ];
        }

        return null; // invoiced / unmanaged
    }
}

if ( ! function_exists( 'icon_psy_should_charge_for_new_participant' ) ) {
    function icon_psy_should_charge_for_new_participant( $mode, $existing_participants_count ) {

        $existing_participants_count = (int) $existing_participants_count;

        if ( $mode === 'per_participant' ) {
            return true;
        }

        if ( $mode === 'per_project' ) {
            // charge once: only when first participant is added
            return ( $existing_participants_count === 0 );
        }

        return false;
    }
}
