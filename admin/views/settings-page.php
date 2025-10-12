<?php
/**
 * Dominus QuickBooks Settings Page
 * Auto-fetches active QuickBooks Items for default selection.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function dq_render_settings_page() {

    // --- Handle form submission ---
    if ( isset( $_POST['dq_save_settings'] ) && check_admin_referer( 'dq_save_settings_action', 'dq_save_settings_nonce' ) ) {

        $settings = [
            'client_id'     => sanitize_text_field( $_POST['dq_client_id'] ?? '' ),
            'client_secret' => sanitize_text_field( $_POST['dq_client_secret'] ?? '' ),
            'redirect_uri'  => sanitize_text_field( $_POST['dq_redirect_uri'] ?? '' ),
            'environment'   => sanitize_text_field( $_POST['dq_environment'] ?? 'sandbox' ),
        ];

        update_option( 'dq_settings', $settings );

        // Save default item ID
        if ( isset( $_POST['dq_default_item_id'] ) ) {
            $item_id = sanitize_text_field( $_POST['dq_default_item_id'] );
            update_option( 'dq_default_item_id', $item_id );
        }

        echo '<div class="updated"><p><strong>Settings saved.</strong></p></div>';
    }

    // --- Load settings ---
    $settings = get_option( 'dq_settings', [] );
    $default_item_id = get_option( 'dq_default_item_id', '' );

    // --- Try fetching live QuickBooks items ---
    $items = [];
    $item_error = '';

    if ( ! empty( $settings['access_token'] ) || class_exists('DQ_API') ) {
        try {
            $query = "SELECT Id, Name, Active, Type FROM Item WHERE Active = true";
            $result = DQ_API::query( $query );
            if ( ! is_wp_error( $result ) && ! empty( $result['QueryResponse']['Item'] ) ) {
                foreach ( $result['QueryResponse']['Item'] as $it ) {
                    $items[] = [
                        'id'   => $it['Id'],
                        'name' => $it['Name'] . ( isset($it['Type']) ? " ({$it['Type']})" : '' ),
                    ];
                }
            } else {
                $item_error = 'Could not fetch QuickBooks items — please ensure you are connected.';
            }
        } catch ( Exception $e ) {
            $item_error = 'Error fetching QuickBooks items: ' . $e->getMessage();
        }
    } else {
        $item_error = 'Please connect to QuickBooks first to load item list.';
    }

    ?>

    <div class="wrap">
        <h1>Dominus QuickBooks Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'dq_save_settings_action', 'dq_save_settings_nonce' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="dq_client_id">Client ID</label></th>
                    <td><input name="dq_client_id" type="text" id="dq_client_id"
                        value="<?php echo esc_attr( $settings['client_id'] ?? '' ); ?>" class="regular-text"></td>
                </tr>

                <tr>
                    <th scope="row"><label for="dq_client_secret">Client Secret</label></th>
                    <td><input name="dq_client_secret" type="text" id="dq_client_secret"
                        value="<?php echo esc_attr( $settings['client_secret'] ?? '' ); ?>" class="regular-text"></td>
                </tr>

                <tr>
                    <th scope="row"><label for="dq_redirect_uri">Redirect URI</label></th>
                    <td><input name="dq_redirect_uri" type="text" id="dq_redirect_uri"
                        value="<?php echo esc_attr( $settings['redirect_uri'] ?? '' ); ?>" class="regular-text"></td>
                </tr>

                <tr>
                    <th scope="row"><label for="dq_environment">Environment</label></th>
                    <td>
                        <select name="dq_environment" id="dq_environment">
                            <option value="sandbox" <?php selected( $settings['environment'] ?? '', 'sandbox' ); ?>>Sandbox</option>
                            <option value="production" <?php selected( $settings['environment'] ?? '', 'production' ); ?>>Production</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="dq_default_item_id">Default QuickBooks Item</label></th>
                    <td>
                        <?php if ( $item_error ) : ?>
                            <p style="color:red;"><?php echo esc_html( $item_error ); ?></p>
                            <input name="dq_default_item_id" type="text" id="dq_default_item_id"
                                value="<?php echo esc_attr( $default_item_id ); ?>" class="small-text">
                            <p class="description">Enter the QuickBooks Item ID manually if automatic fetching failed.</p>
                        <?php else : ?>
                            <select name="dq_default_item_id" id="dq_default_item_id">
                                <option value="">— Select an Item —</option>
                                <?php foreach ( $items as $it ) : ?>
                                    <option value="<?php echo esc_attr( $it['id'] ); ?>" <?php selected( $default_item_id, $it['id'] ); ?>>
                                        <?php echo esc_html( "{$it['name']} (ID: {$it['id']})" ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Choose your default item for invoice line items (e.g., Services).</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Settings', 'primary', 'dq_save_settings' ); ?>
        </form>
    </div>

    <?php
}
