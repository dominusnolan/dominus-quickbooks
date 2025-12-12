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
 * Parse a date string to a UTC timestamp.
 * 
 * This function handles various date formats and returns a Unix timestamp in UTC.
 * For ambiguous formats, assumes the input is already in site timezone.
 *
 * @param string $date_string The date string to parse (e.g., '2025-01-15', '01/15/2025', etc.)
 * @return int|false Unix timestamp in UTC, or false if parsing fails
 */
function dqqb_parse_date_to_utc( $date_string ) {
    if ( empty( $date_string ) ) {
        return false;
    }
    
    // Clean up Excel artifacts and whitespace
    $date_string = trim( str_replace( '_x000D_', '', $date_string ) );
    
    // Try to parse with strtotime
    $timestamp = strtotime( $date_string );
    if ( $timestamp === false ) {
        return false;
    }
    
    // strtotime returns a timestamp in the server's timezone
    // We need to convert it to UTC if it was parsed as local time
    // Check if the string already has timezone info (if not, assume local)
    if ( ! preg_match( '/[+-]\d{4}|UTC|GMT|Z$/i', $date_string ) ) {
        // No timezone info, so strtotime interpreted it in server's local time
        // Convert from local to UTC by getting the GMT equivalent
        $local_date_string = date( 'Y-m-d H:i:s', $timestamp );
        $gmt_date_string = get_gmt_from_date( $local_date_string, 'Y-m-d H:i:s' );
        $timestamp = strtotime( $gmt_date_string . ' UTC' );
    }
    
    return $timestamp;
}

/**
 * Format a date for display using site timezone.
 * 
 * Always use this function for displaying dates to users in the admin or frontend.
 * It ensures dates are shown in the WordPress site timezone.
 * 
 * IMPORTANT: This function parses date strings as midnight in the SITE TIMEZONE,
 * not UTC, to prevent off-by-one errors when displaying dates.
 *
 * @param string|int $date Date string or Unix timestamp
 * @param string $format Date format (default: 'm/d/Y')
 * @param bool $plain_text If true, returns plain text instead of HTML-wrapped output (default: false)
 * @return string Formatted date in site timezone, or '—' if empty/invalid
 */
function dqqb_format_date_display( $date, $format = 'm/d/Y', $plain_text = false ) {
    if ( empty( $date ) ) {
        return $plain_text ? '—' : '<span style="color:#999;">—</span>';
    }
    
    // Convert to timestamp if it's a string
    if ( is_string( $date ) ) {
        $date = trim( str_replace( '_x000D_', '', $date ) );
        
        // Parse the date string as midnight in the site timezone
        // This prevents off-by-one errors when a date like '2025-12-31'
        // is parsed as UTC midnight and then displayed in a different timezone
        try {
            // Add explicit midnight time for date-only strings to ensure consistent parsing
            $date_with_time = ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) ? $date . ' 00:00:00' : $date;
            $date_obj = new DateTimeImmutable( $date_with_time, wp_timezone() );
            $timestamp = $date_obj->getTimestamp();
        } catch ( Exception $e ) {
            // Fallback: try parsing as-is if DateTimeImmutable fails
            $timestamp = strtotime( $date );
            if ( $timestamp === false ) {
                // Return the original value if we can't parse it
                return $plain_text ? $date : esc_html( $date );
            }
        }
    } else {
        $timestamp = $date;
    }
    
    // Use wp_date() which respects site timezone
    $formatted = wp_date( $format, $timestamp );
    return $plain_text ? $formatted : esc_html( $formatted );
}

/**
 * Normalize a date string to Y-m-d format in UTC for storage.
 * 
 * Always use this function when storing dates in post meta or ACF fields.
 * Dates are normalized to UTC to ensure consistency.
 *
 * @param string $date_string The date string to normalize
 * @param string $input_format Optional format hint (e.g., 'n/j/Y' for American dates)
 * @return string Normalized date in Y-m-d format (UTC), or empty string if invalid
 */
function dqqb_normalize_date_for_storage( $date_string, $input_format = '' ) {
    if ( empty( $date_string ) ) {
        return '';
    }
    
    // Clean up Excel artifacts
    $date_string = trim( str_replace( '_x000D_', '', $date_string ) );
    
    // Try parsing with the input format hint if provided
    if ( ! empty( $input_format ) ) {
        $date_obj = DateTime::createFromFormat( $input_format, $date_string, wp_timezone() );
        if ( $date_obj !== false ) {
            // Convert to UTC
            $date_obj->setTimezone( new DateTimeZone( 'UTC' ) );
            return $date_obj->format( 'Y-m-d' );
        }
    }
    
    // Try common date formats
    $formats = [
        'Y-m-d',
        'Y-m-d H:i:s',
        'm/d/Y',
        'n/j/Y',
        'd/m/Y',
        'j/n/Y',
        'm-d-Y',
        'd-m-Y',
    ];
    
    foreach ( $formats as $fmt ) {
        $date_obj = DateTime::createFromFormat( $fmt, $date_string, wp_timezone() );
        if ( $date_obj !== false ) {
            // Convert to UTC
            $date_obj->setTimezone( new DateTimeZone( 'UTC' ) );
            return $date_obj->format( 'Y-m-d' );
        }
    }
    
    // Fallback to strtotime
    $timestamp = strtotime( $date_string );
    if ( $timestamp !== false ) {
        // Assume input is in site timezone, convert to UTC
        $local_date = date( 'Y-m-d H:i:s', $timestamp );
        $gmt_date = get_gmt_from_date( $local_date, 'Y-m-d H:i:s' );
        $timestamp = strtotime( $gmt_date . ' UTC' );
        return date( 'Y-m-d', $timestamp );
    }
    
    return '';
}

/**
 * Helper function: Create a DateTimeImmutable object from year, month, day in site timezone.
 * 
 * @param int $year Year (e.g., 2025)
 * @param int $month Month (1-12)
 * @param int $day Day (1-31)
 * @return int|false Unix timestamp, or false if creation fails
 */
function dqqb_create_date_timestamp( $year, $month, $day ) {
    try {
        $date_obj = new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), wp_timezone() );
        return $date_obj->getTimestamp();
    } catch ( Exception $e ) {
        return false;
    }
}

/**
 * Parse a date string and return a timestamp, handling various formats.
 * This is a timezone-aware version specifically for chart/report date ranges.
 * 
 * IMPORTANT: Parses dates as midnight in the SITE TIMEZONE to prevent off-by-one errors.
 * Uses site's configured date format preference when available to resolve ambiguity.
 * 
 * @param string $date_string The date string to parse
 * @return int|false Unix timestamp, or false if parsing fails
 */
function dqqb_parse_date_for_comparison( $date_string ) {
    if ( empty( $date_string ) ) {
        return false;
    }
    
    // Clean up Excel artifacts
    $date_string = trim( str_replace( '_x000D_', '', $date_string ) );
    
    // Try YYYY-MM-DD format first (most common in storage, unambiguous)
    if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $date_string, $m ) ) {
        // Already in ISO format, validate and parse as midnight in site timezone
        $year  = (int) $m[1];
        $month = (int) $m[2];
        $day   = (int) $m[3];
        if ( checkdate( $month, $day, $year ) ) {
            return dqqb_create_date_timestamp( $year, $month, $day );
        }
        return false;
    }
    
    // For ambiguous formats, use site's configured date format to determine interpretation
    $configured_format = dqqb_qi_date_format();
    
    // Try slash-separated format (MM/DD/YYYY or DD/MM/YYYY based on config)
    if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_string, $m ) ) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        $year = (int) $m[3];
        
        // Determine which is month and which is day based on configured format
        if ( $configured_format === 'd/m/Y' || $configured_format === 'j/n/Y' ) {
            // Day/Month/Year format
            $day = $a;
            $month = $b;
        } else {
            // Default to Month/Day/Year (American format)
            $month = $a;
            $day = $b;
        }
        
        // Validate the date
        if ( checkdate( $month, $day, $year ) ) {
            return dqqb_create_date_timestamp( $year, $month, $day );
        }
        return false;
    }
    
    // Try dash-separated format (prefer DD-MM-YYYY for European style)
    if ( preg_match( '/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $date_string, $m ) ) {
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        
        // Validate the date
        if ( checkdate( $month, $day, $year ) ) {
            return dqqb_create_date_timestamp( $year, $month, $day );
        }
        return false;
    }
    
    // Fallback: try to parse with DateTimeImmutable in site timezone
    try {
        $date_obj = new DateTimeImmutable( $date_string, wp_timezone() );
        return $date_obj->getTimestamp();
    } catch ( Exception $e ) {
        return false;
    }
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

/**
 * Enable Application Passwords for authentication with external apps.
 * Required for Spark web app integration (fallback option).
 */
add_filter( 'wp_is_application_passwords_available', '__return_true' );

add_filter( 'wp_is_application_passwords_available_for_user', function( $available, $user ) {
    if ( in_array( 'engineer', (array) $user->roles, true ) ) {
        return true;
    }
    if ( in_array( 'administrator', (array) $user->roles, true ) ) {
        return true;
    }
    return $available;
}, 10, 2 );