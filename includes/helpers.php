<?php
/**
 * Helper functions for Dominus QuickBooks plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Get plugin option settings
 */
function dq_get_settings() {
    $defaults = [
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => '',
        'environment'   => 'sandbox',
        'realm_id'      => '',
        'access_token'  => '',
        'refresh_token' => '',
        'expires_at'    => 0,
    ];

    $settings = get_option( 'dq_settings', [] );
    if ( ! is_array( $settings ) ) {
        $settings = [];
    }

    return wp_parse_args( $settings, $defaults );
}

/**
 * Save / merge new settings into dq_settings
 */
function dq_update_settings( $new_data = [] ) {
    $settings = dq_get_settings();
    $updated  = array_merge( $settings, $new_data );
    update_option( 'dq_settings', $updated, 'no' );
}

/**
 * Return QuickBooks OAuth base URL based on environment
 */
function dq_auth_url() {
    $s = dq_get_settings();
    $env = strtolower( $s['environment'] ?? 'sandbox' );

    if ( $env === 'production' ) {
        return 'https://appcenter.intuit.com/connect/oauth2';
    }

    return 'https://appcenter.intuit.com/connect/oauth2'; // same for sandbox
}

/**
 * Return QuickBooks OAuth token URL
 */
function dq_token_url() {
    $s = dq_get_settings();
    $env = strtolower( $s['environment'] ?? 'sandbox' );

    if ( $env === 'production' ) {
        return 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    }

    return 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
}

/**
 * Return required QuickBooks API scopes
 */
function dq_scopes() {
    // You can expand as needed (eg. for Payments, Payroll, etc.)
    $scopes = [
        'com.intuit.quickbooks.accounting',
        'openid',
        'profile',
        'email',
        'phone',
        'address'
    ];
    return implode( ' ', $scopes );
}

/**
 * Get admin settings page URL
 */
function dq_admin_url( $params = [] ) {
    $url = admin_url( 'options-general.php?page=dominus-quickbooks' );
    if ( ! empty( $params ) ) {
        $url = add_query_arg( $params, $url );
    }
    return $url;
}

/**
 * Check if the plugin has valid credentials saved
 */
function dq_is_configured() {
    $s = dq_get_settings();
    return ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] );
}

/**
 * Determine if plugin is connected to QuickBooks
 */
function dq_is_connected() {
    $s = dq_get_settings();
    return ! empty( $s['access_token'] ) && ( time() < (int) $s['expires_at'] );
}

/**
 * Get the currently stored access token
 */
function dq_get_access_token() {
    $s = dq_get_settings();
    return $s['access_token'] ?? '';
}

/**
 * Get plugin base directory
 */
function dq_plugin_dir() {
    return plugin_dir_path( __FILE__ );
}

/**
 * Get plugin base URL
 */
function dq_plugin_url() {
    return plugin_dir_url( __FILE__ );
}
