<?php
/**
* Plugin Name: Dominus QuickBooks
* Plugin URI: https://example.com/dominus-quickbooks
* Description: Connect WordPress to Intuit QuickBooks (Sandbox or Production). OAuth 2.0, token refresh, and basic API example.
* Version: 0.1.0
* Author: Dominus
* License: GPL-2.0-or-later
* Text Domain: dominus-quickbooks
*/

if ( ob_get_level() == 0 ) {
    ob_start();
}

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Constants
define( 'DQ_VERSION', '0.1.0' );
define( 'DQ_PLUGIN_FILE', __FILE__ );
define( 'DQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );


// Includes
require_once DQ_PLUGIN_DIR . 'includes/helpers.php';
require_once DQ_PLUGIN_DIR . 'includes/class-dq-auth.php';
require_once DQ_PLUGIN_DIR . 'includes/class-dq-api.php';
require_once DQ_PLUGIN_DIR . 'includes/class-dq-admin.php';
require_once DQ_PLUGIN_DIR . 'includes/class-dq-invoice.php';
require_once plugin_dir_path(__FILE__) . 'includes/metabox-quickbooks.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dq-logger.php';

// Register OAuth callback for admin-post
add_action( 'admin_post_dq_oauth_callback', ['DQ_Auth', 'handle_callback'] );
add_action( 'admin_post_nopriv_dq_oauth_callback', ['DQ_Auth', 'handle_callback'] );

// Boot admin area
add_action( 'plugins_loaded', function() {
    if ( is_admin() ) {
        new DQ_Admin();
    }
});


// Schedule hourly token refresh
register_activation_hook(__FILE__, function() {
    if (! wp_next_scheduled('dq_hourly_refresh')) {
        wp_schedule_event(time(), 'hourly', 'dq_hourly_refresh');
    }   
});


register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('dq_hourly_refresh');
});


// Hook into the scheduled event
add_action('dq_hourly_refresh', function() {
    $s = dq_get_settings();
    if (!empty($s['refresh_token'])) {
        $result = DQ_Auth::refresh_access_token();
        if (is_wp_error($result)) {
            // Optional: log or email admin on refresh failure
            dq_update_settings([
            'access_token' => '',
            'refresh_token' => '',
            'realm_id' => '',
            'expires_at' => 0,
            ]);
        }
    }   
});