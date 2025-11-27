<?php
/**
 * Dominus QuickBooks — Plugin Core Class
 *
 * This class serves as the central hub for the Dominus QuickBooks plugin,
 * encapsulating core QuickBooks API authentication, requests, responses,
 * settings management, error handling, and data processing.
 *
 * @package    Dominus_QuickBooks
 * @subpackage Includes
 * @since      0.3.0
 * @author     Nolan Tan
 *
 * ## Usage Examples:
 *
 * ### Get the plugin singleton instance:
 * ```php
 * $plugin = DQ_Plugin::instance();
 * ```
 *
 * ### Check if authenticated with QuickBooks:
 * ```php
 * if ( DQ_Plugin::is_authenticated() ) {
 *     // User is connected to QuickBooks
 * }
 * ```
 *
 * ### Make an API request:
 * ```php
 * // Simple GET request
 * $response = DQ_Plugin::api_request( 'GET', 'customer/123' );
 *
 * // POST request with data
 * $response = DQ_Plugin::api_request( 'POST', 'invoice', [
 *     'CustomerRef' => [ 'value' => '123' ],
 *     'Line' => [ ... ],
 * ] );
 * ```
 *
 * ### Get a setting:
 * ```php
 * $client_id = DQ_Plugin::get_setting( 'client_id' );
 * ```
 *
 * ### Use data sanitization helpers:
 * ```php
 * $clean_amount = DQ_Plugin::sanitize_amount( '1,234.56' );
 * $clean_data   = DQ_Plugin::sanitize_api_payload( $data );
 * ```
 *
 * ### Handle errors:
 * ```php
 * $response = DQ_Plugin::api_request( 'GET', 'invoice/999' );
 * if ( DQ_Plugin::is_error( $response ) ) {
 *     $message = DQ_Plugin::get_error_message( $response );
 *     DQ_Plugin::log_error( 'Invoice fetch failed', [ 'message' => $message ] );
 * }
 * ```
 *
 * ### Register custom endpoint handler:
 * ```php
 * add_filter( 'dq_api_endpoints', function( $endpoints ) {
 *     $endpoints['estimate'] = [
 *         'create' => 'estimate',
 *         'get'    => 'estimate/{id}',
 *         'update' => 'estimate',
 *         'query'  => 'SELECT * FROM Estimate',
 *     ];
 *     return $endpoints;
 * } );
 * ```
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DQ_Plugin
 *
 * Core plugin class providing centralized functionality for QuickBooks API
 * integration, settings management, error handling, and data processing.
 */
class DQ_Plugin {

    /**
     * Plugin version.
     *
     * @var string
     */
    const VERSION = '0.3.0';

    /**
     * Option key for plugin settings.
     *
     * @var string
     */
    const OPTION_KEY = 'dq_settings';

    /**
     * Singleton instance.
     *
     * @var DQ_Plugin|null
     */
    private static $instance = null;

    /**
     * Cached settings array.
     *
     * @var array|null
     */
    private $settings = null;

    /**
     * Registered API endpoints.
     *
     * @var array
     */
    private $endpoints = [];

    /**
     * Get the singleton instance.
     *
     * @return DQ_Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - private for singleton pattern.
     */
    private function __construct() {
        $this->register_default_endpoints();
        $this->init_hooks();
    }

    /**
     * Prevent cloning of the instance.
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the instance.
     *
     * @throws Exception Always.
     */
    public function __wakeup() {
        throw new Exception( 'Cannot unserialize singleton' );
    }

    /**
     * Initialize WordPress hooks.
     */
    private function init_hooks() {
        // Allow extending endpoints via filter.
        add_filter( 'dq_api_endpoints', [ $this, 'get_endpoints' ] );

        // Log rotation on daily cron.
        add_action( 'dq_daily_maintenance', [ $this, 'run_maintenance' ] );

        // Schedule daily maintenance if not already scheduled.
        if ( ! wp_next_scheduled( 'dq_daily_maintenance' ) ) {
            wp_schedule_event( time(), 'daily', 'dq_daily_maintenance' );
        }

        /**
         * Fires when the DQ_Plugin class is fully initialized.
         *
         * @since 0.3.0
         * @param DQ_Plugin $plugin The plugin instance.
         */
        do_action( 'dq_plugin_initialized', $this );
    }

    /**
     * Initialize the plugin (static helper).
     *
     * @return void
     */
    public static function init() {
        self::instance();
    }

    // =========================================================================
    // Authentication Methods
    // =========================================================================

    /**
     * Check if the plugin is currently authenticated with QuickBooks.
     *
     * @return bool True if authenticated and token is valid.
     */
    public static function is_authenticated() {
        $settings = self::get_all_settings();

        if ( empty( $settings['access_token'] ) ) {
            return false;
        }

        // Check if token has expired.
        $expires_at = isset( $settings['expires_at'] ) ? (int) $settings['expires_at'] : 0;
        if ( time() >= $expires_at ) {
            // Try to refresh the token.
            if ( class_exists( 'DQ_Auth' ) && method_exists( 'DQ_Auth', 'refresh_access_token' ) ) {
                $refreshed = DQ_Auth::refresh_access_token();
                return ! is_wp_error( $refreshed );
            }
            return false;
        }

        return true;
    }

    /**
     * Get the current access token.
     *
     * @return string|WP_Error The access token or WP_Error on failure.
     */
    public static function get_access_token() {
        if ( class_exists( 'DQ_Auth' ) && method_exists( 'DQ_Auth', 'get_access_token' ) ) {
            return DQ_Auth::get_access_token();
        }

        $settings = self::get_all_settings();
        if ( empty( $settings['access_token'] ) ) {
            return new WP_Error(
                'dq_no_token',
                __( 'QuickBooks access token not found. Please connect to QuickBooks.', 'dominus-quickbooks' )
            );
        }

        return $settings['access_token'];
    }

    /**
     * Get the QuickBooks Realm ID (Company ID).
     *
     * @return string|null The realm ID or null if not set.
     */
    public static function get_realm_id() {
        // Check constant first.
        if ( defined( 'DQ_REALM_ID' ) && DQ_REALM_ID ) {
            return (string) DQ_REALM_ID;
        }

        // Check settings.
        $settings = self::get_all_settings();
        if ( ! empty( $settings['realm_id'] ) ) {
            return (string) $settings['realm_id'];
        }

        // Check DQ_Auth class.
        if ( class_exists( 'DQ_Auth' ) && method_exists( 'DQ_Auth', 'realm_id' ) ) {
            return DQ_Auth::realm_id();
        }

        /**
         * Filter the realm ID.
         *
         * @since 0.3.0
         * @param string $realm_id The realm ID.
         */
        return apply_filters( 'dq_realm_id', '' );
    }

    /**
     * Get the QuickBooks authorization URL.
     *
     * @return string|null The authorization URL or null if credentials are missing.
     */
    public static function get_auth_url() {
        if ( class_exists( 'DQ_Auth' ) && method_exists( 'DQ_Auth', 'get_connect_url' ) ) {
            return DQ_Auth::get_connect_url();
        }
        return null;
    }

    // =========================================================================
    // Settings Management
    // =========================================================================

    /**
     * Get all plugin settings.
     *
     * @param bool $refresh Force refresh from database.
     * @return array The settings array.
     */
    public static function get_all_settings( $refresh = false ) {
        $instance = self::instance();

        if ( $refresh || null === $instance->settings ) {
            $defaults = self::get_default_settings();
            $stored   = get_option( self::OPTION_KEY, [] );

            if ( ! is_array( $stored ) ) {
                $stored = [];
            }

            $instance->settings = array_merge( $defaults, $stored );
        }

        return $instance->settings;
    }

    /**
     * Get a specific plugin setting.
     *
     * @param string $key     The setting key.
     * @param mixed  $default The default value if setting is not found.
     * @return mixed The setting value.
     */
    public static function get_setting( $key, $default = '' ) {
        $settings = self::get_all_settings();
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }

    /**
     * Update a specific plugin setting.
     *
     * @param string $key   The setting key.
     * @param mixed  $value The new value.
     * @return bool True on success, false on failure.
     */
    public static function update_setting( $key, $value ) {
        $settings = self::get_all_settings( true );
        $settings[ $key ] = $value;

        $result = update_option( self::OPTION_KEY, $settings, 'no' );

        // Refresh cached settings.
        self::instance()->settings = null;

        /**
         * Fires after a plugin setting is updated.
         *
         * @since 0.3.0
         * @param string $key   The setting key.
         * @param mixed  $value The new value.
         */
        do_action( 'dq_setting_updated', $key, $value );

        return $result;
    }

    /**
     * Update multiple plugin settings at once.
     *
     * @param array $new_settings The new settings to merge.
     * @return bool True on success, false on failure.
     */
    public static function update_settings( array $new_settings ) {
        $settings = self::get_all_settings( true );
        $merged   = array_merge( $settings, $new_settings );

        $result = update_option( self::OPTION_KEY, $merged, 'no' );

        // Refresh cached settings.
        self::instance()->settings = null;

        /**
         * Fires after plugin settings are updated.
         *
         * @since 0.3.0
         * @param array $new_settings The updated settings.
         */
        do_action( 'dq_settings_updated', $new_settings );

        return $result;
    }

    /**
     * Get default plugin settings.
     *
     * @return array The default settings.
     */
    public static function get_default_settings() {
        /**
         * Filter the default plugin settings.
         *
         * @since 0.3.0
         * @param array $defaults The default settings.
         */
        return apply_filters( 'dq_default_settings', [
            'client_id'         => '',
            'client_secret'     => '',
            'environment'       => 'sandbox',
            'realm_id'          => '',
            'access_token'      => '',
            'refresh_token'     => '',
            'expires_at'        => 0,
            'default_tax_code'  => 'NON',
            'default_terms_ref' => '',
            'qi_date_format'    => 'm/d/Y',
        ] );
    }

    /**
     * Check if the plugin is configured with required credentials.
     *
     * @return bool True if client ID and secret are set.
     */
    public static function is_configured() {
        $settings = self::get_all_settings();
        return ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] );
    }

    // =========================================================================
    // API Request Methods
    // =========================================================================

    /**
     * Make a request to the QuickBooks API.
     *
     * @param string $method  The HTTP method (GET, POST, PUT, DELETE).
     * @param string $path    The API endpoint path.
     * @param array  $data    The request data (for POST/PUT).
     * @param array  $options Additional request options.
     * @return array|WP_Error The response data or WP_Error on failure.
     */
    public static function api_request( $method, $path, $data = [], $options = [] ) {
        // Use existing DQ_API class if available.
        if ( class_exists( 'DQ_API' ) ) {
            switch ( strtoupper( $method ) ) {
                case 'GET':
                    return DQ_API::get( $path, $options['context'] ?? '' );
                case 'POST':
                    return DQ_API::post( $path, $data, $options['context'] ?? '' );
                default:
                    return DQ_API::post( $path, $data, $options['context'] ?? '' );
            }
        }

        // Fallback implementation.
        $access_token = self::get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $realm_id = self::get_realm_id();
        if ( empty( $realm_id ) ) {
            return new WP_Error(
                'dq_no_realm_id',
                __( 'QuickBooks Realm ID is missing. Please connect to QuickBooks.', 'dominus-quickbooks' )
            );
        }

        $base_url = self::get_api_base_url();
        $url      = $base_url . $realm_id . '/' . ltrim( $path, '/' );

        // Add minor version if not already present.
        if ( strpos( $url, 'minorversion' ) === false ) {
            $url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'minorversion=65';
        }

        $headers = [
            'Authorization' => 'Bearer ' . $access_token,
            'Accept'        => 'application/json',
        ];

        $args = [
            'headers' => $headers,
            'timeout' => isset( $options['timeout'] ) ? $options['timeout'] : 30,
        ];

        if ( strtoupper( $method ) === 'POST' || strtoupper( $method ) === 'PUT' ) {
            $headers['Content-Type'] = 'application/json';
            $args['headers']         = $headers;
            $args['body']            = wp_json_encode( $data );
            $args['method']          = strtoupper( $method );
            $response                = wp_remote_post( $url, $args );
        } else {
            $response = wp_remote_get( $url, $args );
        }

        return self::handle_api_response( $response, $options['context'] ?? $path );
    }

    /**
     * Execute a QuickBooks query (SQL-like).
     *
     * @param string $sql The query string.
     * @return array|WP_Error The query response or WP_Error on failure.
     */
    public static function query( $sql ) {
        if ( class_exists( 'DQ_API' ) && method_exists( 'DQ_API', 'query' ) ) {
            return DQ_API::query( $sql );
        }

        return self::api_request( 'GET', 'query?query=' . rawurlencode( $sql ) );
    }

    /**
     * Get the QuickBooks API base URL based on environment.
     *
     * @return string The API base URL.
     */
    public static function get_api_base_url() {
        $env = self::get_setting( 'environment', 'sandbox' );

        if ( strtolower( $env ) === 'production' ) {
            return 'https://quickbooks.api.intuit.com/v3/company/';
        }

        return 'https://sandbox-quickbooks.api.intuit.com/v3/company/';
    }

    /**
     * Handle the API response and normalize it.
     *
     * @param array|WP_Error $response The raw response from wp_remote_*.
     * @param string         $context  Optional context for error messages.
     * @return array|WP_Error The normalized response or WP_Error.
     */
    public static function handle_api_response( $response, $context = '' ) {
        if ( is_wp_error( $response ) ) {
            self::log_error( 'API request failed', [
                'context' => $context,
                'error'   => $response->get_error_message(),
            ] );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
            return $data;
        }

        $error = new WP_Error(
            'dq_api_error',
            sprintf(
                /* translators: 1: HTTP status code 2: Response body */
                __( 'QuickBooks API error (%1$d): %2$s', 'dominus-quickbooks' ),
                $code,
                $body
            ),
            [
                'http_code' => $code,
                'raw_body'  => $body,
                'context'   => $context,
            ]
        );

        self::log_error( 'API error response', [
            'context'   => $context,
            'http_code' => $code,
            'body'      => $body,
        ] );

        return $error;
    }

    // =========================================================================
    // Endpoint Management
    // =========================================================================

    /**
     * Register default API endpoints.
     */
    private function register_default_endpoints() {
        $this->endpoints = [
            'customer' => [
                'create' => 'customer',
                'get'    => 'customer/{id}',
                'update' => 'customer',
                'query'  => 'SELECT * FROM Customer',
            ],
            'invoice' => [
                'create' => 'invoice',
                'get'    => 'invoice/{id}',
                'update' => 'invoice',
                'query'  => 'SELECT * FROM Invoice',
            ],
            'payment' => [
                'create' => 'payment',
                'get'    => 'payment/{id}',
                'query'  => 'SELECT * FROM Payment',
            ],
            'item' => [
                'get'   => 'item/{id}',
                'query' => 'SELECT * FROM Item WHERE Active = true',
            ],
            'companyinfo' => [
                'get' => 'companyinfo/{id}',
            ],
        ];
    }

    /**
     * Get all registered endpoints.
     *
     * @return array The endpoints array.
     */
    public function get_endpoints() {
        /**
         * Filter the registered API endpoints.
         *
         * @since 0.3.0
         * @param array $endpoints The registered endpoints.
         */
        return apply_filters( 'dq_api_endpoints', $this->endpoints );
    }

    /**
     * Register a new API endpoint.
     *
     * @param string $entity  The entity name (e.g., 'estimate').
     * @param array  $config  The endpoint configuration.
     */
    public function register_endpoint( $entity, array $config ) {
        $this->endpoints[ strtolower( $entity ) ] = $config;

        /**
         * Fires after a new endpoint is registered.
         *
         * @since 0.3.0
         * @param string $entity The entity name.
         * @param array  $config The endpoint configuration.
         */
        do_action( 'dq_endpoint_registered', $entity, $config );
    }

    /**
     * Get endpoint path for an entity and operation.
     *
     * @param string $entity    The entity name.
     * @param string $operation The operation (create, get, update, query).
     * @param array  $params    Optional parameters to replace in path.
     * @return string|null The endpoint path or null if not found.
     */
    public function get_endpoint_path( $entity, $operation, $params = [] ) {
        $entity    = strtolower( $entity );
        $operation = strtolower( $operation );

        if ( ! isset( $this->endpoints[ $entity ][ $operation ] ) ) {
            return null;
        }

        $path = $this->endpoints[ $entity ][ $operation ];

        // Replace parameters in path.
        foreach ( $params as $key => $value ) {
            $path = str_replace( '{' . $key . '}', $value, $path );
        }

        return $path;
    }

    // =========================================================================
    // Error Handling
    // =========================================================================

    /**
     * Check if a value is a WP_Error.
     *
     * @param mixed $value The value to check.
     * @return bool True if WP_Error.
     */
    public static function is_error( $value ) {
        return is_wp_error( $value );
    }

    /**
     * Get error message from a WP_Error or return a default message.
     *
     * @param WP_Error|mixed $error   The error object.
     * @param string         $default The default message if not an error.
     * @return string The error message.
     */
    public static function get_error_message( $error, $default = '' ) {
        if ( is_wp_error( $error ) ) {
            return $error->get_error_message();
        }
        return $default;
    }

    /**
     * Get error code from a WP_Error.
     *
     * @param WP_Error|mixed $error The error object.
     * @return string|null The error code or null.
     */
    public static function get_error_code( $error ) {
        if ( is_wp_error( $error ) ) {
            return $error->get_error_code();
        }
        return null;
    }

    /**
     * Create a standardized WP_Error for plugin errors.
     *
     * @param string $code    The error code (will be prefixed with 'dq_').
     * @param string $message The error message.
     * @param mixed  $data    Optional error data.
     * @return WP_Error
     */
    public static function create_error( $code, $message, $data = null ) {
        $code = 'dq_' . ltrim( $code, 'dq_' );
        return new WP_Error( $code, $message, $data );
    }

    // =========================================================================
    // Logging
    // =========================================================================

    /**
     * Log an info message.
     *
     * @param string $message The log message.
     * @param mixed  $context Optional context data.
     */
    public static function log( $message, $context = null ) {
        if ( class_exists( 'DQ_Logger' ) ) {
            DQ_Logger::info( $message, $context );
        }
    }

    /**
     * Log an error message.
     *
     * @param string $message The log message.
     * @param mixed  $context Optional context data.
     */
    public static function log_error( $message, $context = null ) {
        if ( class_exists( 'DQ_Logger' ) ) {
            DQ_Logger::error( $message, $context );
        }
    }

    /**
     * Log a warning message.
     *
     * @param string $message The log message.
     * @param mixed  $context Optional context data.
     */
    public static function log_warning( $message, $context = null ) {
        if ( class_exists( 'DQ_Logger' ) ) {
            DQ_Logger::warn( $message, $context );
        }
    }

    /**
     * Log a debug message (only if WP_DEBUG is enabled).
     *
     * @param string $message The log message.
     * @param mixed  $context Optional context data.
     */
    public static function log_debug( $message, $context = null ) {
        if ( class_exists( 'DQ_Logger' ) ) {
            DQ_Logger::debug( $message, $context );
        }
    }

    // =========================================================================
    // Data Processing & Sanitization
    // =========================================================================

    /**
     * Sanitize a monetary amount.
     *
     * @param mixed $value The raw value.
     * @return float|null The sanitized amount or null if invalid.
     */
    public static function sanitize_amount( $value ) {
        if ( $value === null || $value === '' ) {
            return null;
        }

        if ( is_numeric( $value ) ) {
            return (float) $value;
        }

        // Remove everything except digits, dots, and minus sign.
        $clean = preg_replace( '/[^0-9.\-]/', '', (string) $value );

        if ( $clean === '' ) {
            return null;
        }

        // Handle multiple dots.
        $parts = explode( '.', $clean );
        if ( count( $parts ) > 2 ) {
            $clean = $parts[0] . '.' . implode( '', array_slice( $parts, 1 ) );
        }

        return is_numeric( $clean ) ? (float) $clean : null;
    }

    /**
     * Sanitize an API payload by removing unsupported QuickBooks fields.
     *
     * @param array $payload The payload to sanitize.
     * @return array The sanitized payload.
     */
    public static function sanitize_api_payload( array $payload ) {
        // Fields that QuickBooks doesn't accept in create/update requests.
        $invalid_keys = [
            'Id',
            'SyncToken',
            'sparse',
            'domain',
            'MetaData',
            'AllowIPNPayment',
            'AllowOnlinePayment',
            'AllowOnlineCreditCardPayment',
            'AllowOnlineACHPayment',
            'TotalAmt',
            'Balance',
        ];

        /**
         * Filter the invalid keys to remove from API payloads.
         *
         * @since 0.3.0
         * @param array $invalid_keys The invalid keys.
         */
        $invalid_keys = apply_filters( 'dq_invalid_payload_keys', $invalid_keys );

        foreach ( $invalid_keys as $key ) {
            if ( isset( $payload[ $key ] ) ) {
                unset( $payload[ $key ] );
            }
        }

        return $payload;
    }

    /**
     * Sanitize a string for use in QuickBooks.
     *
     * @param string $value The raw string.
     * @param int    $max_length Maximum allowed length.
     * @return string The sanitized string.
     */
    public static function sanitize_string( $value, $max_length = 0 ) {
        $clean = sanitize_text_field( $value );

        if ( $max_length > 0 && strlen( $clean ) > $max_length ) {
            $clean = substr( $clean, 0, $max_length );
        }

        return $clean;
    }

    /**
     * Sanitize an email address.
     *
     * @param string $email The raw email.
     * @return string The sanitized email.
     */
    public static function sanitize_email( $email ) {
        return sanitize_email( $email );
    }

    /**
     * Format a date for QuickBooks API.
     *
     * @param string|int $date   The date (timestamp or date string).
     * @param string     $format The output format (default: Y-m-d).
     * @return string|null The formatted date or null if invalid.
     */
    public static function format_date( $date, $format = 'Y-m-d' ) {
        if ( empty( $date ) ) {
            return null;
        }

        if ( is_numeric( $date ) ) {
            $timestamp = (int) $date;
        } else {
            $timestamp = strtotime( $date );
        }

        if ( $timestamp === false ) {
            return null;
        }

        return gmdate( $format, $timestamp );
    }

    // =========================================================================
    // Maintenance
    // =========================================================================

    /**
     * Run plugin maintenance tasks.
     */
    public function run_maintenance() {
        // Rotate logs.
        if ( class_exists( 'DQ_Logger' ) && method_exists( 'DQ_Logger', 'rotate' ) ) {
            DQ_Logger::rotate();
        }

        /**
         * Fires during plugin maintenance.
         *
         * @since 0.3.0
         * @param DQ_Plugin $plugin The plugin instance.
         */
        do_action( 'dq_maintenance', $this );
    }

    /**
     * Clear all plugin transients.
     */
    public static function clear_transients() {
        delete_transient( 'dq_oauth_state' );

        /**
         * Fires after plugin transients are cleared.
         *
         * @since 0.3.0
         */
        do_action( 'dq_transients_cleared' );
    }

    // =========================================================================
    // Plugin Info
    // =========================================================================

    /**
     * Get the plugin version.
     *
     * @return string The plugin version.
     */
    public static function get_version() {
        if ( defined( 'DQQB_VERSION' ) ) {
            return DQQB_VERSION;
        }
        return self::VERSION;
    }

    /**
     * Get the plugin path.
     *
     * @return string The plugin directory path.
     */
    public static function get_path() {
        if ( defined( 'DQQB_PATH' ) ) {
            return DQQB_PATH;
        }
        return plugin_dir_path( dirname( __FILE__ ) );
    }

    /**
     * Get the plugin URL.
     *
     * @return string The plugin URL.
     */
    public static function get_url() {
        if ( defined( 'DQQB_URL' ) ) {
            return DQQB_URL;
        }
        return plugin_dir_url( dirname( __FILE__ ) );
    }

    /**
     * Check if WP_DEBUG is enabled.
     *
     * @return bool True if debug mode is enabled.
     */
    public static function is_debug() {
        return defined( 'WP_DEBUG' ) && WP_DEBUG;
    }
}
