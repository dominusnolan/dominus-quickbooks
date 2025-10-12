<?php
/**
 * Dominus QuickBooks API Core
 * Handles all communication with Intuit QuickBooks REST API.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_API {

    /**
     * Build QuickBooks base URL
     */
    private static function base_url( $path = '' ) {
        $settings = get_option( 'dq_settings', [] );
        $env = $settings['environment'] ?? 'sandbox';

        $base = $env === 'production'
            ? 'https://quickbooks.api.intuit.com/v3/company/'
            : 'https://sandbox-quickbooks.api.intuit.com/v3/company/';

        return $base . ( $settings['realm_id'] ?? '' ) . '/' . ltrim( $path, '/' );
    }

    /**
     * Get access token from saved settings
     */
    private static function get_token() {
        $settings = get_option( 'dq_settings', [] );
        return $settings['access_token'] ?? '';
    }

    /**
     * Core HTTP request to QuickBooks
     */
    private static function request( $method, $url, $body = [] ) {
        $token = self::get_token();
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        $args = [
            'method'      => strtoupper( $method ),
            'headers'     => $headers,
            'timeout'     => 30,
            'data_format' => 'body',
        ];

        if ( ! empty( $body ) && in_array( strtoupper( $method ), [ 'POST', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        // Log the request start
        DQ_Logger::log( "Request to {$url}", 'API' );
        DQ_Logger::log( [ 'method' => $method, 'body' => $body ], 'API' );

        $response = wp_remote_request( $url, $args );
        $code     = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );

        DQ_Logger::log( "HTTP {$code} Response: {$raw_body}", 'API' );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dq_http_error', 'HTTP error: ' . $response->get_error_message() );
        }

        $json = json_decode( $raw_body, true );

        // Handle expired token
        if ( $code === 401 ) {
            DQ_Logger::log( 'Access token expired. Attempting automatic refresh.', 'AUTH' );
            self::refresh_token();
            return new WP_Error( 'dq_auth_error', 'Unauthorized — token refreshed. Please retry.' );
        }

        // Generic failure (non-2xx)
        if ( $code < 200 || $code >= 300 ) {
            $detail = $json['Fault']['Error'][0]['Detail'] ?? 'Unknown QuickBooks API error.';
            $message = "QuickBooks API Error ({$code}): {$detail}";
            DQ_Logger::log( $message, 'API ERROR' );
            return new WP_Error( 'dq_api_error', $message );
        }

        return $json;
    }

    /* -------------------------------
     * Customer Endpoints
     * ------------------------------- */

    public static function create_customer( $data ) {
        $url = self::base_url( 'customer' );
        return self::request( 'POST', $url, $data );
    }

    public static function get_customer( $id ) {
        $url = self::base_url( 'customer/' . intval( $id ) );
        return self::request( 'GET', $url );
    }

    public static function update_customer( $data ) {
        $url = self::base_url( 'customer?operation=update' );
        return self::request( 'POST', $url, $data );
    }

    /* -------------------------------
     * Invoice Endpoints
     * ------------------------------- */

    public static function create_invoice( $data ) {
        $url = self::base_url( 'invoice' );
        return self::request( 'POST', $url, $data );
    }

    public static function get_invoice( $id ) {
        $url = self::base_url( 'invoice/' . intval( $id ) );
        return self::request( 'GET', $url );
    }

    public static function update_invoice( $data ) {
        $url = self::base_url( 'invoice?operation=update' );
        return self::request( 'POST', $url, $data );
    }

    /* -------------------------------
     * Token Handling
     * ------------------------------- */

    public static function refresh_token() {
        $settings = get_option( 'dq_settings', [] );

        if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) || empty( $settings['refresh_token'] ) ) {
            DQ_Logger::log( 'Cannot refresh token — missing client credentials.', 'AUTH' );
            return;
        }

        $url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

        $body = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $settings['refresh_token'],
        ];

        $headers = [
            'Authorization' => 'Basic ' . base64_encode( $settings['client_id'] . ':' . $settings['client_secret'] ),
            'Accept'        => 'application/json',
        ];

        $args = [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 30,
        ];

        DQ_Logger::log( 'Refreshing QuickBooks token...', 'AUTH' );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            DQ_Logger::log( 'Token refresh HTTP error: ' . $response->get_error_message(), 'AUTH' );
            return;
        }

        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $json['access_token'] ) ) {
            $settings['access_token']  = $json['access_token'];
            $settings['refresh_token'] = $json['refresh_token'] ?? $settings['refresh_token'];
            $settings['expires_at']    = time() + intval( $json['expires_in'] ?? 3600 );
            update_option( 'dq_settings', $settings );
            DQ_Logger::log( 'Token refresh successful.', 'AUTH' );
        } else {
            DQ_Logger::log( 'Token refresh failed: ' . print_r( $json, true ), 'AUTH' );
        }
    }
}
