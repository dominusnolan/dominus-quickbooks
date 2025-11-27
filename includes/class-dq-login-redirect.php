<?php
/**
 * Dominus QuickBooks — Login Redirect Handler
 *
 * Redirects all requests to /wp-login.php and /wp-admin (and sub-paths)
 * to /access using a 301 permanent redirect.
 *
 * This helps obscure the default WordPress login locations from public access.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_Login_Redirect {

    /**
     * The path to redirect users to when they access wp-login.php or wp-admin.
     */
    const REDIRECT_PATH = '/access';

    /**
     * Initialize WordPress hooks for redirect handling.
     * Uses 'init' action with high priority to catch requests early.
     */
    public static function init() {
        // Hook early on 'init' to intercept requests before WordPress processes them
        add_action( 'init', [ __CLASS__, 'maybe_redirect_login_admin' ], 1 );
        // Handle custom logout endpoint at /access?action=logout
        add_action( 'init', [ __CLASS__, 'maybe_handle_custom_logout' ], 1 );
    }

    /**
     * Handle custom logout requests at /access?action=logout.
     * This allows users to log out without being redirected away from /access.
     */
    public static function maybe_handle_custom_logout() {
        // Get the current request URI
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        // Parse the path from the request URI
        $path = wp_parse_url( $request_uri, PHP_URL_PATH );
        if ( ! $path ) {
            return;
        }

        // Normalize the path
        $path = rtrim( $path, '/' );

        // Check if this is a request to /access
        if ( ! preg_match( '#' . preg_quote( self::REDIRECT_PATH, '#' ) . '$#i', $path ) ) {
            return;
        }

        // Check for action=logout in query string
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
        if ( 'logout' !== $action ) {
            return;
        }

        // Verify nonce for CSRF protection
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dq_logout_action' ) ) {
            // Invalid or missing nonce - redirect to access page without logging out
            wp_safe_redirect( home_url( self::REDIRECT_PATH ), 302 );
            exit;
        }

        // Perform logout if user is logged in
        if ( is_user_logged_in() ) {
            wp_logout();
        }

        // Redirect to /access with logged_out=1 to show confirmation message
        $redirect_url = add_query_arg( 'logged_out', '1', home_url( self::REDIRECT_PATH ) );
        wp_safe_redirect( $redirect_url, 302 );
        exit;
    }

    /**
     * Get the custom logout URL with nonce.
     *
     * @return string The logout URL with nonce parameter.
     */
    public static function get_logout_url() {
        $logout_url = home_url( self::REDIRECT_PATH . '?action=logout' );
        return wp_nonce_url( $logout_url, 'dq_logout_action' );
    }

    /**
     * Check if the current request is to wp-login.php or wp-admin and redirect if so.
     * Handles both GET and POST requests with a 301 permanent redirect.
     *
     * Logged-in users with appropriate permissions are allowed through to maintain
     * admin functionality after they've logged in via /access.
     */
    public static function maybe_redirect_login_admin() {
        // Skip if this is an AJAX request (admin-ajax.php is in wp-admin but needs to work)
        if ( wp_doing_ajax() ) {
            return;
        }

        // Skip if this is a WP-CLI request
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }

        // Skip if this is a REST API request
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        // Skip if this is a cron request
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }

        // Get the current request URI
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        // Parse the path from the request URI (remove query string)
        $path = wp_parse_url( $request_uri, PHP_URL_PATH );
        if ( ! $path ) {
            return;
        }

        // Check if this is a request to wp-login.php or wp-admin
        if ( self::is_login_or_admin_path( $path ) ) {
            // Allow logged-in users to access wp-admin (they've already authenticated via /access)
            if ( self::is_admin_path( $path ) && is_user_logged_in() ) {
                return;
            }

            // Redirect unauthenticated users from wp-admin
            // Always redirect from wp-login.php (force users to use /access instead)
            self::do_redirect();
        }
    }

    /**
     * Check if the given path is wp-login.php or wp-admin (or a sub-path of wp-admin).
     *
     * @param string $path The URL path to check.
     * @return bool True if this is a login or admin path that should be redirected.
     */
    private static function is_login_or_admin_path( $path ) {
        return self::is_login_path( $path ) || self::is_admin_path( $path );
    }

    /**
     * Check if the given path is wp-login.php.
     *
     * @param string $path The URL path to check.
     * @return bool True if this is the login path.
     */
    private static function is_login_path( $path ) {
        // Normalize the path (remove trailing slashes for consistent comparison)
        $path = rtrim( $path, '/' );

        // Check for wp-login.php (exact match or with query string removed)
        return preg_match( '#/wp-login\.php$#i', $path );
    }

    /**
     * Check if the given path is wp-admin (or a sub-path of wp-admin).
     *
     * @param string $path The URL path to check.
     * @return bool True if this is an admin path.
     */
    private static function is_admin_path( $path ) {
        // Normalize the path (remove trailing slashes for consistent comparison)
        $path = rtrim( $path, '/' );

        // Check for wp-admin or wp-admin/* paths
        // This matches /wp-admin, /wp-admin/, and /wp-admin/anything
        if ( preg_match( '#/wp-admin(?:/.*)?$#i', $path ) ) {
            // Exclude admin-ajax.php and admin-post.php as they are used for AJAX and form handling
            if ( preg_match( '#/wp-admin/admin-ajax\.php$#i', $path ) ) {
                return false;
            }
            if ( preg_match( '#/wp-admin/admin-post\.php$#i', $path ) ) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Perform the 301 permanent redirect to /access.
     * This method exits after sending the redirect header.
     */
    private static function do_redirect() {
        $redirect_url = home_url( self::REDIRECT_PATH );

        // Use wp_safe_redirect with 301 status for permanent redirect
        // wp_safe_redirect validates the URL is on the same host
        wp_safe_redirect( $redirect_url, 301 );
        exit;
    }
}

