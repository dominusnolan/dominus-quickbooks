<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * DQ_Gmail_Settings
 * - Stores Gmail OAuth client id/secret
 * - Handles OAuth connect/disconnect
 * - Persists/refreshes access tokens
 * PHP 7.4 compatible
 */
class DQ_Gmail_Settings {
    const OPTION = 'dq_gmail_settings';        // client_id, client_secret, token
    const PAGE_SLUG = 'dq-gmail';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'maybe_handle_oauth']);
    }

    public static function menu() {
        add_submenu_page(
            'options-general.php',
            'Dominus Gmail',
            'Dominus Gmail',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function get_settings() {
        $defaults = [
            'client_id' => '',
            'client_secret' => '',
            'token' => [] // full token blob
        ];
        $opt = get_option(self::OPTION, []);
        return wp_parse_args($opt, $defaults);
    }

    public static function save_settings($data) {
        update_option(self::OPTION, $data);
    }

    public static function render() {
        if ( ! current_user_can('manage_options') ) return;
        $s = self::get_settings();
        $redirect = self::redirect_uri();
        ?>
        <div class="wrap">
            <h1>Dominus Gmail</h1>
            <form method="post">
                <?php wp_nonce_field('dq_gmail_save','dq_gmail_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>OAuth Client ID</label></th>
                        <td><input type="text" name="client_id" class="regular-text" value="<?php echo esc_attr($s['client_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>OAuth Client Secret</label></th>
                        <td><input type="text" name="client_secret" class="regular-text" value="<?php echo esc_attr($s['client_secret']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Redirect URI</label></th>
                        <td><code><?php echo esc_html($redirect); ?></code></td>
                    </tr>
                </table>
                <?php submit_button('Save Credentials'); ?>
            </form>

            <?php
            // Save action
            if ( isset($_POST['dq_gmail_nonce']) && wp_verify_nonce($_POST['dq_gmail_nonce'],'dq_gmail_save') ) {
                $s['client_id'] = isset($_POST['client_id']) ? sanitize_text_field($_POST['client_id']) : '';
                $s['client_secret'] = isset($_POST['client_secret']) ? sanitize_text_field($_POST['client_secret']) : '';
                self::save_settings($s);
                echo '<div class="updated notice"><p>Saved.</p></div>';
            }

            // Connect / Disconnect
            echo '<hr />';
            if ( ! empty($s['token']['access_token']) ) {
                echo '<p><strong>Status:</strong> Connected to Gmail.</p>';
                $disconnect_url = wp_nonce_url( add_query_arg(['dq_gmail_disconnect'=>1]), 'dq_gmail_disconnect');
                echo '<a class="button" href="'.esc_url($disconnect_url).'">Disconnect</a>';
            } else {
                $auth_url = esc_url(self::auth_url());
                echo '<p><strong>Status:</strong> Not connected.</p>';
                echo '<a class="button-primary" href="'.$auth_url.'">Connect to Gmail</a>';
            }
            ?>
            <p style="margin-top: 1em;"><em>Scopes:</em> <code>https://www.googleapis.com/auth/gmail.readonly</code></p>
        </div>
        <?php
    }

    public static function redirect_uri() {
        return add_query_arg(['dq_gmail_oauth'=>1], admin_url('options-general.php?page='.self::PAGE_SLUG));
    }

    public static function auth_url() {
        $s = self::get_settings();
        $params = [
            'client_id' => $s['client_id'],
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public static function maybe_handle_oauth() {
        if ( isset($_GET['dq_gmail_disconnect']) && current_user_can('manage_options') && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'dq_gmail_disconnect') ) {
            $s = self::get_settings();
            $s['token'] = [];
            self::save_settings($s);
            wp_safe_redirect( admin_url('options-general.php?page='.self::PAGE_SLUG.'&disconnected=1') );
            exit;
        }

        if ( ! isset($_GET['dq_gmail_oauth']) || ! current_user_can('manage_options') ) return;
        if ( isset($_GET['code']) ) {
            $s = self::get_settings();
            if ( empty($s['client_id']) || empty($s['client_secret']) ) return;

            $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => [
                    'code' => sanitize_text_field($_GET['code']),
                    'client_id' => $s['client_id'],
                    'client_secret' => $s['client_secret'],
                    'redirect_uri' => self::redirect_uri(),
                    'grant_type' => 'authorization_code',
                ],
            ]);
            if ( is_wp_error($resp) ) return;
            $token = json_decode( wp_remote_retrieve_body($resp), true );
            if ( isset($token['access_token']) ) {
                $s['token'] = $token;
                self::save_settings($s);
                wp_safe_redirect( admin_url('options-general.php?page='.self::PAGE_SLUG.'&connected=1') );
                exit;
            }
        }
    }

    public static function get_access_token() {
        $s = self::get_settings();
        if ( empty($s['token']) ) return '';

        // Refresh if needed
        $expires_at = (int)($s['token']['created'] ?? 0) + (int)($s['token']['expires_in'] ?? 0) - 60;
        if ( time() >= $expires_at && ! empty($s['token']['refresh_token']) ) {
            $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => [
                    'client_id' => $s['client_id'],
                    'client_secret' => $s['client_secret'],
                    'refresh_token' => $s['token']['refresh_token'],
                    'grant_type' => 'refresh_token',
                ],
            ]);
            if ( ! is_wp_error($resp) ) {
                $tok = json_decode(wp_remote_retrieve_body($resp), true);
                if ( isset($tok['access_token']) ) {
                    $tok['refresh_token'] = $s['token']['refresh_token']; // keep existing
                    $tok['created'] = time();
                    $s['token'] = $tok;
                    self::save_settings($s);
                }
            }
        }

        return (string)($s['token']['access_token'] ?? '');
    }
}
