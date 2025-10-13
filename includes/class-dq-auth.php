<?php
/**
 * Dominus QuickBooks — OAuth2 Auth Handler
 * (Final version with init() + oauth_start() methods)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_Auth {

    /**
     * Initialize WordPress hooks
     */
    public static function init() {
        add_action( 'admin_post_dq_oauth_start', [ __CLASS__, 'oauth_start' ] );
        add_action( 'admin_post_dq_oauth_callback', [ __CLASS__, 'handle_callback' ] );
    }

    /**
     * Start OAuth2 authorization flow (redirect user to QuickBooks)
     */
    public static function oauth_start() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dq_oauth_start' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $url = self::get_connect_url();
        error_log( 'DQ OAUTH START — redirecting to: ' . $url );
        wp_redirect( $url );
        exit;
    }

    /**
     * Build the QuickBooks OAuth authorization URL
     */
    public static function get_connect_url() {
        $s = dq_get_settings();

        // Short alphanumeric state QuickBooks always echoes back
        $state = wp_generate_password(12, false, false);
        set_transient('dq_oauth_state', $state, 10 * MINUTE_IN_SECONDS);
        error_log('DQ SET STATE: ' . $state);

        $params = [
            'client_id'     => trim($s['client_id'] ?? ''),
            'response_type' => 'code',
            'scope'         => dq_scopes(),
            'redirect_uri'  => admin_url('admin-post.php?action=dq_oauth_callback'),
            'state'         => $state,
        ];

        $url = dq_auth_url() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        error_log('DQ CONNECT URL GENERATED WITH STATE: ' . $state);
        return $url;
    }

    /**
     * Handle the OAuth callback from Intuit
     */
    public static function handle_callback() {
        if ( ! current_user_can( 'manage_options' ) ) {
            status_header(403);
            wp_die('Forbidden');
        }

        error_log('DQ CALLBACK STARTED');
        error_log('DQ CALLBACK PARAMS: ' . print_r($_GET, true));

        // Validate 12-char transient state
        $state = isset($_GET['state']) ? preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_GET['state'])) : '';
        $saved = get_transient('dq_oauth_state');
        delete_transient('dq_oauth_state'); // one-time use

        if ( ! $state || ! $saved || $state !== $saved ) {
            error_log("DQ STATE CHECK FAILED expected=$saved got=$state");
            wp_die('Invalid OAuth state. Please reconnect.');
        }
        error_log("DQ STATE CHECK PASSED: $state");

        // --- Proceed with token exchange ---
        $code    = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $realmId = isset($_GET['realmId']) ? sanitize_text_field(wp_unslash($_GET['realmId'])) : '';

        if ( empty($code) ) {
            wp_die('Authorization code missing. Please reconnect.');
        }

        $s = dq_get_settings();

        $body = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => admin_url('admin-post.php?action=dq_oauth_callback'),
        ];

        $token_url = dq_token_url();
        error_log('DQ CALLBACK REQUEST TO TOKEN URL: ' . $token_url);

        $resp = wp_remote_post($token_url, [
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode(trim($s['client_id']) . ':' . trim($s['client_secret'])),
            ],
            'body' => http_build_query($body, '', '&', PHP_QUERY_RFC3986),
        ]);

        if ( is_wp_error($resp) ) {
            error_log('DQ TOKEN REQUEST ERROR: ' . $resp->get_error_message());
            wp_safe_redirect(dq_admin_url(['error' => 'token_request']));
            exit;
        }

        $code_resp = wp_remote_retrieve_response_code($resp);
        $body_json = json_decode(wp_remote_retrieve_body($resp), true);
        error_log('DQ CALLBACK TOKEN RESPONSE: ' . wp_remote_retrieve_body($resp));

        if ( $code_resp !== 200 || empty($body_json['access_token']) ) {
            error_log('DQ TOKEN RESPONSE ERROR: ' . print_r($body_json, true));
            wp_safe_redirect(dq_admin_url(['error' => 'token_invalid']));
            exit;
        }

        // Calculate expiry timestamp (with 60 s safety margin)
        $expires_at = time() + (int)$body_json['expires_in'] - 60;

        // Save tokens + realm ID
        $settings = dq_get_settings();
        $settings['realm_id']      = $realmId ?: ($body_json['realmId'] ?? 'sandbox-company');
        $settings['access_token']  = $body_json['access_token'];
        $settings['refresh_token'] = $body_json['refresh_token'] ?? '';
        $settings['expires_at']    = $expires_at;

        update_option('dq_settings', $settings, 'no');
        error_log('DQ CALLBACK SAVED SETTINGS: ' . print_r($settings, true));

        if ( ob_get_length() ) {
            @ob_end_clean();
        }

        wp_safe_redirect(dq_admin_url(['connected' => '1']));
        exit;
    }

    /**
     * Refresh the access token using the stored refresh token
     */
    public static function refresh_access_token() {
        $s = dq_get_settings();

        if ( empty($s['refresh_token']) ) {
            return new WP_Error('dq_missing_refresh_token', 'No refresh token available.');
        }

        $body = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $s['refresh_token'],
            'redirect_uri'  => admin_url('admin-post.php?action=dq_oauth_callback'),
        ];

        $resp = wp_remote_post(dq_token_url(), [
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode(trim($s['client_id']) . ':' . trim($s['client_secret'])),
            ],
            'body' => http_build_query($body, '', '&', PHP_QUERY_RFC3986),
        ]);

        if ( is_wp_error($resp) ) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body_json = json_decode(wp_remote_retrieve_body($resp), true);

        if ( $code !== 200 || empty($body_json['access_token']) ) {
            return new WP_Error('dq_refresh_failed', 'Token refresh failed: ' . print_r($body_json, true));
        }

        $s['access_token']  = $body_json['access_token'];
        $s['refresh_token'] = $body_json['refresh_token'] ?? $s['refresh_token'];
        $s['expires_at']    = time() + (int)$body_json['expires_in'] - 60;

        update_option('dq_settings', $s, 'no');
        error_log('DQ REFRESHED TOKENS: ' . print_r($s, true));

        return $s['access_token'];
    }

    /**
     * Retrieve the current access token (refresh automatically if expired)
     */
    public static function get_access_token() {
        $s = dq_get_settings();

        if ( empty($s['access_token']) ) {
            return new WP_Error('dq_no_token', 'No access token stored.');
        }

        if ( time() >= (int)($s['expires_at'] ?? 0) ) {
            $new = self::refresh_access_token();
            if ( is_wp_error($new) ) {
                return $new;
            }
            return $new;
        }

        return $s['access_token'];
    }
}
