<?php
/**
 * Dominus QuickBooks Admin Page
 * Handles plugin settings and connection to QuickBooks.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'DQ_Admin' ) ) return; // ✅ Prevent double-loading

class DQ_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_post_dq_save_settings', [ $this, 'save_settings' ] );
        add_action( 'admin_post_dq_connect', [ $this, 'connect_quickbooks' ] );
        add_action( 'admin_post_dq_clear_logs', [ $this, 'clear_logs' ] );
    }

    /**
     * Add QuickBooks menu page under Settings
     */
    public function register_menu() {
        add_menu_page(
            'Dominus QuickBooks',
            'Dominus QB',
            'manage_options',
            'dominus-quickbooks',
            [ $this, 'render_settings_page' ],
            'dashicons-forms',
            58
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        $settings = get_option( 'dq_settings', [] );
        $client_id = $settings['client_id'] ?? '';
        $client_secret = $settings['client_secret'] ?? '';
        $environment = $settings['environment'] ?? 'sandbox';
        $redirect_uri = admin_url( 'admin-post.php?action=dq_oauth_callback' );

        $realm_id     = $settings['realm_id'] ?? '';
        $access_token = $settings['access_token'] ?? '';
        $expires_at   = ! empty( $settings['expires_at'] ) ? date( 'Y-m-d H:i:s', $settings['expires_at'] ) : '(none)';

        $log_url = DQ_Logger::get_file_url();

        ?>
        <div class="wrap">
            <h1>Dominus QuickBooks Settings</h1>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="dq_save_settings">
                <?php wp_nonce_field( 'dq_save_settings' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="client_id">Client ID</label></th>
                        <td><input type="text" name="client_id" id="client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_secret">Client Secret</label></th>
                        <td><input type="text" name="client_secret" id="client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row">Environment</th>
                        <td>
                            <select name="environment">
                                <option value="sandbox" <?php selected( $environment, 'sandbox' ); ?>>Sandbox</option>
                                <option value="production" <?php selected( $environment, 'production' ); ?>>Production</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p><strong>Redirect URI:</strong><br>
                    <code><?php echo esc_html( $redirect_uri ); ?></code><br>
                    <small>Add this URI to your QuickBooks app configuration in the Developer Dashboard.</small>
                </p>

                <?php submit_button( '💾 Save Credentials' ); ?>
            </form>

            <hr>

            <h2>QuickBooks Connection</h2>
            <?php if ( ! empty( $access_token ) ) : ?>
                <p><strong>Status:</strong> ✅ Connected<br>
                    <strong>Realm ID:</strong> <?php echo esc_html( $realm_id ); ?><br>
                    <strong>Access Token Expires:</strong> <?php echo esc_html( $expires_at ); ?></p>
            <?php else : ?>
                <p><strong>Status:</strong> ❌ Not Connected</p>
            <?php endif; ?>

            <p><a href="<?php echo esc_url( $this->get_connect_url() ); ?>" class="button button-primary">🔗 Connect to QuickBooks</a></p>

            <hr>

            <h2>QuickBooks Tools</h2>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="dq_clear_logs">
                <?php wp_nonce_field( 'dq_clear_logs' ); ?>
                <?php submit_button( '🧹 Clear Log File', 'secondary' ); ?>
            </form>

            <p><a href="<?php echo esc_url( $log_url ); ?>" target="_blank" class="button">📄 View Log File</a></p>
        </div>
        <?php
    }

    /**
     * Save credentials to database
     */
    public function save_settings() {
        check_admin_referer( 'dq_save_settings' );

        $settings = get_option( 'dq_settings', [] );

        $settings['client_id']     = sanitize_text_field( $_POST['client_id'] ?? '' );
        $settings['client_secret'] = sanitize_text_field( $_POST['client_secret'] ?? '' );
        $settings['environment']   = sanitize_text_field( $_POST['environment'] ?? 'sandbox' );

        update_option( 'dq_settings', $settings );

        DQ_Logger::log( 'Admin saved QuickBooks credentials.', 'ADMIN' );

        wp_safe_redirect( admin_url( 'admin.php?page=dominus-quickbooks&dq_saved=1' ) );
        exit;
    }

    /**
     * Build connect URL for QuickBooks
     */
    private function get_connect_url() {
        $settings = get_option( 'dq_settings', [] );
        $client_id = $settings['client_id'] ?? '';
        $redirect_uri = admin_url( 'admin-post.php?action=dq_oauth_callback' );

        $base_auth_url = 'https://appcenter.intuit.com/connect/oauth2';
        $scopes = urlencode( 'com.intuit.quickbooks.accounting openid profile email phone address' );
        $state = wp_generate_password( 12, false );

        return "{$base_auth_url}?client_id={$client_id}&response_type=code&scope={$scopes}&redirect_uri=" . urlencode( $redirect_uri ) . "&state={$state}";
    }

    /**
     * Redirect to Intuit OAuth authorization page
     */
    public function connect_quickbooks() {
        $url = $this->get_connect_url();
        wp_redirect( $url );
        exit;
    }

    /**
     * Clear DQ logs
     */
    public function clear_logs() {
        check_admin_referer( 'dq_clear_logs' );
        DQ_Logger::clear();
        wp_safe_redirect( admin_url( 'admin.php?page=dominus-quickbooks&dq_cleared=1' ) );
        exit;
    }
}

new DQ_Admin();
