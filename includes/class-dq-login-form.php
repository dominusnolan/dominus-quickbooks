<?php
/**
 * Dominus QuickBooks — Login Form Shortcode
 *
 * Provides a [dq_login] shortcode that renders a login form for use on the /access page.
 * Features:
 * - Username/email and password authentication
 * - Error messages for failed login attempts
 * - Redirect to /account-page/ on successful login
 * - Info message with link if user is already logged in
 * - Styling similar to dashboard components
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_Login_Form {

    /**
     * The URL path to redirect to after successful login.
     */
    const REDIRECT_PATH = '/account-page/';

    /**
     * Initialize WordPress hooks.
     */
    public static function init() {
        add_shortcode( 'dq_login', [ __CLASS__, 'render_shortcode' ] );
        add_action( 'wp_ajax_nopriv_dq_login_submit', [ __CLASS__, 'ajax_login' ] );
        add_action( 'wp_ajax_dq_login_submit', [ __CLASS__, 'ajax_login' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
    }

    /**
     * Register CSS and JS assets.
     */
    public static function register_assets() {
        wp_register_style(
            'dq-login-form',
            DQQB_URL . 'assets/css/dq-login-form.css',
            [],
            DQQB_VERSION
        );
        wp_register_script(
            'dq-login-form',
            DQQB_URL . 'assets/js/dq-login-form.js',
            [ 'jquery' ],
            DQQB_VERSION,
            true
        );
    }

    /**
     * Render the [dq_login] shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render_shortcode( $atts ) {
        // If user is already logged in, show info message
        if ( is_user_logged_in() ) {
            return self::render_logged_in_message();
        }

        // Enqueue assets
        wp_enqueue_style( 'dq-login-form' );
        wp_enqueue_script( 'dq-login-form' );

        // Localize script with AJAX URL and nonce
        wp_localize_script( 'dq-login-form', 'dqLoginVars', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'dq_login_nonce' ),
            'redirectUrl' => home_url( self::REDIRECT_PATH ),
        ] );

        // Check if user was just logged out
        $logged_out_message = '';
        if ( isset( $_GET['logged_out'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['logged_out'] ) ) ) {
            $logged_out_message = self::render_logged_out_message();
        }

        return $logged_out_message . self::render_login_form();
    }

    /**
     * Render the login form HTML.
     *
     * @return string Login form HTML.
     */
    private static function render_login_form() {
        $output = '<div class="dq-login-wrapper">';
        $output .= '<div class="dq-login-container">';
        $output .= '<div class="dq-login-header">';
        $output .= '<h2>Sign In</h2>';
        $output .= '<p>Enter your credentials to access your account</p>';
        $output .= '</div>';

        $output .= '<form id="dq-login-form" class="dq-login-form" method="post">';
        
        // Error message container (hidden by default)
        $output .= '<div id="dq-login-error" class="dq-login-error" style="display: none;"></div>';

        // Username/Email field
        $output .= '<div class="dq-form-group">';
        $output .= '<label for="dq-login-username">';
        $output .= '<span class="dashicons dashicons-admin-users"></span>';
        $output .= 'Username or Email';
        $output .= '</label>';
        $output .= '<input type="text" id="dq-login-username" name="username" required autocomplete="username" placeholder="Enter your username or email">';
        $output .= '</div>';

        // Password field
        $output .= '<div class="dq-form-group">';
        $output .= '<label for="dq-login-password">';
        $output .= '<span class="dashicons dashicons-lock"></span>';
        $output .= 'Password';
        $output .= '</label>';
        $output .= '<input type="password" id="dq-login-password" name="password" required autocomplete="current-password" placeholder="Enter your password">';
        $output .= '</div>';

        // Remember me checkbox
        $output .= '<div class="dq-form-group dq-form-row">';
        $output .= '<label class="dq-checkbox-label">';
        $output .= '<input type="checkbox" id="dq-login-remember" name="remember" value="1">';
        $output .= '<span>Remember me</span>';
        $output .= '</label>';
        $output .= '</div>';

        // Submit button
        $output .= '<div class="dq-form-group">';
        $output .= '<button type="submit" id="dq-login-submit" class="dq-login-button">';
        $output .= '<span class="button-text">Sign In</span>';
        $output .= '<span class="button-loading" style="display: none;">';
        $output .= '<span class="dashicons dashicons-update dq-spin"></span> Signing in...';
        $output .= '</span>';
        $output .= '</button>';
        $output .= '</div>';

        $output .= '</form>';

        // Lost password link
        $output .= '<div class="dq-login-footer">';
        $output .= '<a href="' . esc_url( wp_lostpassword_url() ) . '" class="dq-forgot-password">Forgot your password?</a>';
        $output .= '</div>';

        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render the message shown when user is already logged in.
     *
     * @return string HTML output.
     */
    private static function render_logged_in_message() {
        $current_user = wp_get_current_user();
        $display_name = esc_html( $current_user->display_name );
        $redirect_url = esc_url( home_url( self::REDIRECT_PATH ) );
        $logout_url   = esc_url( DQ_Login_Redirect::get_logout_url() );

        $output = '<div class="dq-login-wrapper">';
        $output .= '<div class="dq-login-container dq-logged-in">';
        $output .= '<div class="dq-login-header">';
        $output .= '<h2>Already Signed In</h2>';
        $output .= '</div>';
        $output .= '<div class="dq-login-info">';
        $output .= '<p>You are currently logged in as <strong>' . $display_name . '</strong>.</p>';
        $output .= '</div>';
        $output .= '<div class="dq-login-actions">';
        $output .= '<a style="font-weight: bold;" href="' . $redirect_url . '" class="dq-login-button">Go to Account Page</a>';
        $output .= '<a style="font-weight: bold;margin-left:50px" href="' . $logout_url . '" class="dq-logout-link">Sign out</a>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render the logout confirmation message.
     *
     * @return string HTML output.
     */
    private static function render_logged_out_message() {
        $output = '<div class="dq-login-wrapper">';
        $output .= '<div class="dq-logout-message">';
        $output .= '<span class="dashicons dashicons-yes-alt dq-success-icon"></span>';
        $output .= '<p>You have been logged out successfully.</p>';
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Handle AJAX login request.
     * Authenticates user and returns JSON response.
     */
    public static function ajax_login() {
        // Verify nonce
        if ( ! check_ajax_referer( 'dq_login_nonce', 'nonce', false ) ) {
            wp_send_json_error( [
                'message' => 'Security check failed. Please refresh the page and try again.',
            ], 403 );
        }

        // Get and sanitize input
        $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
        $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
        $remember = isset( $_POST['remember'] ) && $_POST['remember'] === '1';

        // Validate input
        if ( empty( $username ) || empty( $password ) ) {
            wp_send_json_error( [
                'message' => 'Please enter both username and password.',
            ], 400 );
        }

        // Check if username is actually an email
        if ( is_email( $username ) ) {
            $user = get_user_by( 'email', $username );
            if ( $user ) {
                $username = $user->user_login;
            }
        }

        // Attempt authentication
        $credentials = [
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => $remember,
        ];

        $user = wp_signon( $credentials, is_ssl() );

        if ( is_wp_error( $user ) ) {
            // Get error message
            $error_code = $user->get_error_code();
            $error_message = self::get_friendly_error_message( $error_code );

            wp_send_json_error( [
                'message' => $error_message,
            ], 401 );
        }

        // Login successful
        wp_send_json_success( [
            'message'     => 'Login successful! Redirecting...',
            'redirectUrl' => home_url( self::REDIRECT_PATH ),
        ] );
    }

    /**
     * Get a user-friendly error message for WordPress login errors.
     *
     * @param string $error_code The WordPress error code.
     * @return string User-friendly error message.
     */
    private static function get_friendly_error_message( $error_code ) {
        $messages = [
            'invalid_username'   => 'The username you entered does not exist.',
            'invalid_email'      => 'The email address you entered does not exist.',
            'incorrect_password' => 'The password you entered is incorrect.',
            'empty_username'     => 'Please enter your username.',
            'empty_password'     => 'Please enter your password.',
        ];

        // Return specific message if available, otherwise generic message
        if ( isset( $messages[ $error_code ] ) ) {
            return $messages[ $error_code ];
        }

        return 'Login failed. Please check your credentials and try again.';
    }
}
