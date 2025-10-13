<?php
/**
 * Dominus QuickBooks — Helper functions
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retrieve plugin settings safely.
 *
 * @return array
 */
function dq_get_settings() {
    $defaults = [
        'client_id'        => '',
        'client_secret'    => '',
        'environment'      => 'sandbox',
        'realm_id'         => '',
        'access_token'     => '',
        'refresh_token'    => '',
        'expires_at'       => 0,
        'default_tax_code' => 'NON',
        'default_terms_ref'=> '',
    ];

    $settings = get_option( 'dq_settings', [] );

    if ( ! is_array( $settings ) ) {
        $settings = [];
    }

    return array_merge( $defaults, $settings );
}

/**
 * Save plugin settings.
 *
 * @param array $new_settings
 */
function dq_save_settings( $new_settings = [] ) {
    $settings = dq_get_settings();
    $merged   = array_merge( $settings, (array) $new_settings );
    update_option( 'dq_settings', $merged, 'no' );
}

/**
 * Build the QuickBooks OAuth authorization base URL.
 *
 * @return string
 */
function dq_auth_url() {
    $s   = dq_get_settings();
    $env = strtolower( $s['environment'] ?? 'sandbox' );

    if ( $env === 'production' ) {
        return 'https://appcenter.intuit.com/connect/oauth2';
    }

    // Sandbox environment by default
    return 'https://appcenter.intuit.com/connect/oauth2';
}

/**
 * Get the QuickBooks token endpoint URL.
 *
 * @return string
 */
function dq_token_url() {
    return 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
}

/**
 * Build a link to the Dominus QB admin page with query params.
 *
 * @param array $args
 * @return string
 */
function dq_admin_url( $args = [] ) {
    $url = admin_url( 'admin.php?page=dqqb' );

    if ( ! empty( $args ) ) {
        $url = add_query_arg( $args, $url );
    }

    return $url;
}

/**
 * Return a scope string for QuickBooks authorization.
 *
 * @return string
 */
function dq_scopes() {
    // You can extend this later with additional scopes if needed
    return 'com.intuit.quickbooks.accounting openid profile email';
}

/**
 * Write a quick log message (shortcut for DQ_Logger).
 *
 * @param string $msg
 * @param mixed  $ctx
 */
function dq_log( $msg, $ctx = null ) {
    if ( class_exists( 'DQ_Logger' ) ) {
        DQ_Logger::info( $msg, $ctx );
    } else {
        $upload = wp_upload_dir();
        $path   = trailingslashit( $upload['basedir'] ) . 'dq-log.txt';
        $line   = '[' . date( 'c' ) . '] ' . $msg;
        if ( $ctx ) {
            $line .= ' ' . ( is_string( $ctx ) ? $ctx : wp_json_encode( $ctx ) );
        }
        $line .= "\n";
        error_log( $line, 3, $path );
    }
}

/**
 * Retrieve a specific plugin option value.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function dqqb_option( $key, $default = '' ) {
    $opts = dq_get_settings();
    return $opts[ $key ] ?? $default;
}
