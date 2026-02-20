<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Project config / package helpers
 */

if ( ! function_exists( 'icon_psy_build_project_reference_blob' ) ) {
    function icon_psy_build_project_reference_blob( $existing_reference, $package_key, $config_mode ) {
        $existing_reference = trim( (string) $existing_reference );

        $blob = array(
            'icon_cfg'   => (string) $config_mode,
            'icon_pkg'   => (string) $package_key,
            'updated_at' => gmdate('c'),
        );

        $json = wp_json_encode( $blob );

        if ( $existing_reference !== '' ) {
            return $existing_reference . ' | ' . $json;
        }
        return $json;
    }
}

if ( ! function_exists( 'icon_psy_extract_icon_cfg_from_reference' ) ) {
    function icon_psy_extract_icon_cfg_from_reference( $reference ) {
        $reference = trim( (string) $reference );
        if ( $reference === '' ) return array();

        $try = json_decode( $reference, true );
        if ( is_array( $try ) && ( isset($try['icon_cfg']) || isset($try['icon_pkg']) ) ) {
            return $try;
        }

        if ( strpos( $reference, '|' ) !== false ) {
            $parts = array_map( 'trim', explode( '|', $reference ) );
            $last  = end( $parts );
            $try2  = json_decode( $last, true );
            if ( is_array( $try2 ) && ( isset($try2['icon_cfg']) || isset($try2['icon_pkg']) ) ) {
                return $try2;
            }
        }

        return array();
    }
}

if ( ! function_exists( 'icon_psy_project_is_leadership_project' ) ) {
    function icon_psy_project_is_leadership_project( $project_row, $has_reference = true ) {

        if ( ! is_object( $project_row ) ) {
            return false;
        }

        if ( isset( $project_row->icon_pkg ) && (string) $project_row->icon_pkg !== '' ) {
            return ( (string) $project_row->icon_pkg === 'leadership_assessment' );
        }

        if ( $has_reference && isset( $project_row->reference ) && (string) $project_row->reference !== '' ) {
            $cfg = icon_psy_extract_icon_cfg_from_reference( (string) $project_row->reference );
            $pkg = isset( $cfg['icon_pkg'] ) ? (string) $cfg['icon_pkg'] : '';
            return ( $pkg === 'leadership_assessment' );
        }

        return false;
    }
}

if ( ! function_exists( 'icon_psy_project_is_teams_project' ) ) {
    function icon_psy_project_is_teams_project( $project_row, $has_reference = true ) {

        if ( ! is_object( $project_row ) ) {
            return false;
        }

        if ( isset( $project_row->icon_pkg ) && (string) $project_row->icon_pkg !== '' ) {
            $pkg = (string) $project_row->icon_pkg;
            return in_array( $pkg, array( 'teams_cohorts', 'team_assessment', 'high_performing_teams' ), true );
        }

        if ( $has_reference && isset( $project_row->reference ) && (string) $project_row->reference !== '' ) {
            $cfg = icon_psy_extract_icon_cfg_from_reference( (string) $project_row->reference );
            $pkg = isset( $cfg['icon_pkg'] ) ? (string) $cfg['icon_pkg'] : '';
            return in_array( $pkg, array( 'teams_cohorts', 'team_assessment', 'high_performing_teams' ), true );
        }

        return false;
    }
}

if ( ! function_exists( 'icon_psy_project_is_teams_project_type' ) ) {
    function icon_psy_project_is_teams_project_type( $project_type ) {

        $type = strtolower( trim( (string) $project_type ) );

        return in_array(
            $type,
            array(
                'high_performing_teams',
                'teams',
                'team',
                'team_cohort',
                'teams_cohorts',
                'team_assessment',
            ),
            true
        );
    }
}

if ( ! function_exists( 'icon_psy_get_page_url_by_slugs' ) ) {
    function icon_psy_get_page_url_by_slugs( $slugs = array(), $fallback = '' ) {

        foreach ( (array) $slugs as $slug ) {
            $p = get_page_by_path( trim( $slug, '/' ) );
            if ( $p && isset( $p->ID ) ) {
                $u = get_permalink( $p->ID );
                if ( $u ) {
                    return $u;
                }
            }
        }

        return $fallback ? home_url( $fallback ) : home_url( '/' );
    }
}
