<?php
/**
 * Dominus QuickBooks — Admin Settings Page (v0.3)
 * Adds Connect + Test Connection buttons and improved success/error messages.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_Settings {
    const OPT_KEY = 'dq_settings';

    /**
     * Register menu and settings
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register' ] );
        add_action( 'admin_post_dq_test_connection', [ __CLASS__, 'test_connection' ] );
    }

    /**
     * Add the Dominus QB menu page
     */
    public static function menu() {
        add_menu_page(
            'Dominus QB',
            'Dominus QB',
            'manage_options',
            'dqqb',
            [ __CLASS__, 'render' ],
            'dashicons-portfolio',
            56
        );
    }

    /**
     * Register plugin settings fields
     */
    public static function register() {
        register_setting( self::OPT_KEY, self::OPT_KEY );

        add_settings_section(
            'dqqb_main',
            'QuickBooks Credentials',
            '__return_false',
            'dqqb'
        );

        $fields = [
            'client_id'         => 'Client ID',
            'client_secret'     => 'Client Secret',
            'environment'       => 'Environment (sandbox or production)',
            'realm_id'          => 'Company ID (Realm ID)',
            'default_tax_code'  => 'Default Tax Code',
            'default_terms_ref' => 'Default TermsRef (optional)',
        ];

        foreach ( $fields as $key => $label ) {
            add_settings_field(
                $key,
                $label,
                function() use ( $key ) {
                    $opts = dq_get_settings();
                    printf(
                        '<input type="text" name="%s[%s]" value="%s" class="regular-text"/>',
                        self::OPT_KEY,
                        esc_attr( $key ),
                        esc_attr( $opts[ $key ] ?? '' )
                    );
                },
                'dqqb',
                'dqqb_main'
            );
        }
    }

    /**
     * Render the Dominus QB admin page
     */
    public static function render() {
        $redirect = admin_url( 'admin-post.php?action=dq_oauth_callback' );
        echo '<div class="wrap"><h1>Dominus QB</h1>';

        // ✅ Success notice after OAuth connect
        if ( isset($_GET['connected']) && $_GET['connected'] == '1' ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Connected to QuickBooks successfully!</strong></p></div>';
        }

        // ✅ Test connection result
        if ( isset($_GET['test_result']) ) {
            $result = sanitize_text_field( $_GET['test_result'] );
            if ( $result === 'success' ) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>✅ QuickBooks connection is working!</strong></p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p><strong>❌ QuickBooks connection failed:</strong> ' . esc_html( $result ) . '</p></div>';
            }
        }

        echo '<form method="post" action="options.php">';
        settings_fields( self::OPT_KEY );
        do_settings_sections( 'dqqb' );
        submit_button();
        echo '</form>';

        printf( '<p><strong>Redirect URI:</strong> <code>%s</code></p>', esc_html( $redirect ) );

        // Buttons
        echo '<p>' . self::connect_button() . ' ' . self::test_button() . '</p>';
        echo '</div>';
    }

    /**
     * Render the Connect to QuickBooks button
     */
    public static function connect_button() {
        $url = admin_url( 'admin-post.php?action=dq_oauth_start' );
        $url = wp_nonce_url( $url, 'dq_oauth_start' );
        return sprintf(
            '<a class="button button-primary" href="%s">Connect to QuickBooks</a>',
            esc_url( $url )
        );
    }

    /**
     * Render the Test Connection button
     */
    public static function test_button() {
        $url = admin_url( 'admin-post.php?action=dq_test_connection' );
        $url = wp_nonce_url( $url, 'dq_test_connection' );
        return sprintf(
            '<a class="button" href="%s">Test Connection</a>',
            esc_url( $url )
        );
    }

    /**
     * Handle the Test Connection request
     */
    public static function test_connection() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dq_test_connection' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $realm_id = dqqb_option( 'realm_id' );
        if ( empty( $realm_id ) ) {
            wp_safe_redirect( dq_admin_url( [ 'test_result' => 'Missing realm ID' ] ) );
            exit;
        }

        $result = DQ_API::get( 'companyinfo/' . $realm_id );

        if ( is_wp_error( $result ) ) {
            $msg = urlencode( $result->get_error_message() );
            wp_safe_redirect( dq_admin_url( [ 'test_result' => $msg ] ) );
            exit;
        }

        if ( isset( $result['CompanyInfo']['CompanyName'] ) ) {
            wp_safe_redirect( dq_admin_url( [ 'test_result' => 'success' ] ) );
        } else {
            wp_safe_redirect( dq_admin_url( [ 'test_result' => 'Unexpected response' ] ) );
        }
        exit;
    }
}
