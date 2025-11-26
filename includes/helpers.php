<?php
/**
 * Dominus QuickBooks — Helper functions
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retrieve plugin settings safely.
 *
 * @return array
 */
function dq_get_settings() {
    $defaults = [
        'client_id'        => '',
        'client_secret'    => '',
        'environment'      => 'sandbox',
        'realm_id'         => '',
        'access_token'     => '',
        'refresh_token'    => '',
        'expires_at'       => 0,
        'default_tax_code' => 'NON',
        'default_terms_ref'=> '',
    ];

    $settings = get_option( 'dq_settings', [] );

    if ( ! is_array( $settings ) ) {
        $settings = [];
    }

    return array_merge( $defaults, $settings );
}

/**
 * Save plugin settings.
 *
 * @param array $new_settings
 */
function dq_save_settings( $new_settings = [] ) {
    $settings = dq_get_settings();
    $merged   = array_merge( $settings, (array) $new_settings );
    update_option( 'dq_settings', $merged, 'no' );
}

/**
 * Build the QuickBooks OAuth authorization base URL.
 *
 * @return string
 */
function dq_auth_url() {
    $s   = dq_get_settings();
    $env = strtolower( $s['environment'] ?? 'sandbox' );

    if ( $env === 'production' ) {
        return 'https://appcenter.intuit.com/connect/oauth2';
    }

    // Sandbox environment by default
    return 'https://appcenter.intuit.com/connect/oauth2';
}

/**
 * Get the QuickBooks token endpoint URL.
 *
 * @return string
 */
function dq_token_url() {
    return 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
}

/**
 * Build a link to the Dominus QB admin page with query params.
 *
 * @param array $args
 * @return string
 */
function dq_admin_url( $args = [] ) {
    $url = admin_url( 'admin.php?page=dqqb' );

    if ( ! empty( $args ) ) {
        $url = add_query_arg( $args, $url );
    }

    return $url;
}

/**
 * Return a scope string for QuickBooks authorization.
 *
 * @return string
 */
function dq_scopes() {
    // You can extend this later with additional scopes if needed
    return 'com.intuit.quickbooks.accounting openid profile email';
}

/**
 * Write a quick log message (shortcut for DQ_Logger).
 *
 * @param string $msg
 * @param mixed  $ctx
 */
function dq_log( $msg, $ctx = null ) {
    if ( class_exists( 'DQ_Logger' ) ) {
        DQ_Logger::info( $msg, $ctx );
    } else {
        $upload = wp_upload_dir();
        $path   = trailingslashit( $upload['basedir'] ) . 'dq-log.txt';
        $line   = '[' . date( 'c' ) . '] ' . $msg;
        if ( $ctx ) {
            $line .= ' ' . ( is_string( $ctx ) ? $ctx : wp_json_encode( $ctx ) );
        }
        $line .= "\n";
        error_log( $line, 3, $path );
    }
}

/**
 * Retrieve a specific plugin option value.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function dqqb_option( $key, $default = '' ) {
    $opts = dq_get_settings();
    return $opts[ $key ] ?? $default;
}

function dqqb_qi_date_format() {
    $opts = dq_get_settings();
    $fmt  = isset($opts['qi_date_format']) ? trim($opts['qi_date_format']) : '';
    // Allow only known formats
    $allowed = ['m/d/Y','d/m/Y','n/j/Y','Y-m-d'];
    if (!in_array($fmt, $allowed, true)) {
        return 'm/d/Y'; // default
    }
    return $fmt;
}


/**
 * After importing or updating a QuickBooks Invoice CPT,
 * sync its Invoice Number to the linked Work Orders in their wo_invoice_no fields.
 * - $invoice_post_id: CPT post ID of the Invoice.
 */
function dqqb_sync_invoice_number_to_workorders($invoice_post_id) {
    if (!function_exists('update_field')) return;
    $invoice_no = get_the_title($invoice_post_id);
    if ($invoice_no === '') return;
    $work_orders = get_field('qi_wo_number', $invoice_post_id);

    if (!is_array($work_orders)) {
        if ($work_orders instanceof WP_Post) $work_orders = [ $work_orders ];
        elseif (is_numeric($work_orders)) $work_orders = [ intval($work_orders) ];
        elseif (is_string($work_orders) && $work_orders !== '') $work_orders = [ $work_orders ];
        else return;
    }

    foreach ($work_orders as $wo) {
        $wo_id = null;
        if ($wo instanceof WP_Post) {
            $wo_id = $wo->ID;
        } elseif (is_array($wo)) {
            $wo_id = isset($wo['ID']) ? $wo['ID'] : (isset($wo['value']) ? $wo['value'] : null);
        } else {
            $wo_id = intval($wo);
        }
        $wo_id = intval($wo_id);
        if (!$wo_id) continue;

        $existing = get_field('wo_invoice_no', $wo_id);
        $list = [];
        if (is_array($existing)) $list = array_map('trim', $existing);
        elseif (is_string($existing) && $existing !== '') $list = array_map('trim', explode(',', $existing));
        if (!in_array($invoice_no, $list)) $list[] = $invoice_no;
        $save_val = implode(', ', array_unique(array_filter($list, fn($v)=>$v!=='')));
        update_field('wo_invoice_no', $save_val, $wo_id);
    }
}


add_action('acf/save_post', function($post_id) {
    // Only run for Invoice CPT
    $post = get_post($post_id);
    if (! $post || $post->post_type !== 'quickbooks_invoice') return;
    if ( function_exists('dqqb_sync_invoice_number_to_workorders') ) {
        dqqb_sync_invoice_number_to_workorders($post_id);
    }
}, 20); // Higher priority to run after ACF saves fields

add_action('save_post_quickbooks_invoice', function($post_id) {
    // Avoid autorevision, autosave, bulk, etc
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( get_post_status($post_id) !== 'publish' ) return;
    
    // Run on Invoice CPT only
    if ( function_exists('dqqb_sync_invoice_number_to_workorders') ) {
        dqqb_sync_invoice_number_to_workorders($post_id);
    }
});

/**
 * NEW: When a workorder is saved/updated, update the post_author to match ACF field 'member_name'
 * Behavior:
 *  - Skip autosaves and revisions
 *  - Read ACF's member_name (get_field) when available, else post meta
 *  - Resolve member_name to a WP_User using: numeric ID -> login -> email -> display_name search
 *  - If resolved user differs from current post_author, update post_author (guarded to avoid recursion)
 *  - Log change via DQ_Logger::info() when available
 */
add_action('save_post_workorder', function( $post_id ) {
    // Basic guards: autosave, revision
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'workorder' ) return;

    static $dqqb_updating_author = false;
    if ( $dqqb_updating_author ) return; // prevent recursion

    // Read member_name from ACF if available, else fallback to post meta
    $member_raw = '';
    if ( function_exists('get_field') ) {
        $member_raw = get_field( 'member_name', $post_id );
    }
    if ( empty( $member_raw ) ) {
        $member_raw = get_post_meta( $post_id, 'member_name', true );
    }
    $member_name = trim( (string) $member_raw );
    if ( $member_name === '' ) return;

    $found_user_id = 0;

    // 0) Hardcoded mapping for Joseph Serafino Lee
    if ( strcasecmp( $member_name, 'Joseph Serafino Lee' ) === 0 ) {
        $found_user_id = 9;
    }

    // If not hardcoded, do standard resolution
    if ( ! $found_user_id ) {
        // 1) If numeric -> treat as user ID
        if ( ctype_digit( (string) $member_name ) ) {
            $try_id = intval( $member_name );
            if ( $try_id > 0 ) {
                $u = get_user_by( 'id', $try_id );
                if ( $u ) $found_user_id = $u->ID;
            }
        }
    }
    if ( ! $found_user_id ) {
        // 2) Try login then email
        $u = get_user_by( 'login', $member_name );
        if ( ! $u ) $u = get_user_by( 'email', $member_name );
        if ( $u ) $found_user_id = $u->ID;
    }
    if ( ! $found_user_id ) {
        // 3) Search display_name (WP_User_Query)
        $uq = new WP_User_Query([
            'search'         => $member_name,
            'search_columns' => [ 'display_name' ],
            'number'         => 1,
        ]);
        $results = $uq->get_results();
        if ( ! empty( $results ) && isset( $results[0]->ID ) ) {
            $found_user_id = intval( $results[0]->ID );
        }
    }

    if ( ! $found_user_id || $found_user_id <= 0 ) return;

    $current_author = intval( $post->post_author );
    if ( $current_author === $found_user_id ) return; // nothing to do

    // Update post_author with recursion guard
    $dqqb_updating_author = true;
    wp_update_post( [
        'ID' => $post_id,
        'post_author' => $found_user_id,
    ] );
    $dqqb_updating_author = false;

    // Log the change
    if ( class_exists( 'DQ_Logger' ) ) {
        DQ_Logger::info( 'Workorder author updated from member_name field', [
            'post_id' => $post_id,
            'from'    => $current_author,
            'to'      => $found_user_id,
            'member_name' => $member_name,
        ] );
    }
}, 20);