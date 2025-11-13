<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CSV → CPT quickbooks_invoice importer
 *
 * Admin UI: In QuickBooks Invoice CPT menu, "Import CSV"
 *
 * Mapping (CSV Header -> ACF / Post fields)
 * - DocNumber  -> post_title
 * - TxnDate    -> post_date (parsed using provided date format; default n/j/Y)
 * - DocNumber  -> qi_invoice_no
 * - Customer   -> qi_customer
 * - CustomerMemo -> qi_wo_number (map Work Order by title; if not found, use null)
 * - TxnDate    -> qi_invoice_date
 * - DueDate    -> qi_due_date
 * - TotalAmt   -> qi_total_billed
 * - Balance    -> qi_balance_due
 * - Paid       -> compute status "Paid" or "Unpaid" for qi_payment_status; also store numeric into qi_total_paid
 * - PurchaseOrder -> qi_purchase_order
 * - Terms      -> qi_terms (if blank, "Net 60")
 *
 * qi_invoice repeater defaults if empty:
 * - activity    -> "Labor Rate HR"
 * - description -> "Import"
 * - quantity    -> 1
 * - rate        -> TotalAmt
 *
 * Enhancement:
 * - Detect multiple Work Orders in CustomerMemo via:
 *   • Comma/semicolon separated values
 *   • Tokens that start with "wo-" (case-insensitive) anywhere in the memo
 */
class DQ_QI_CSV_Import {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=quickbooks_invoice',
            'Import Invoices (CSV)',
            'Import CSV',
            'edit_posts',
            'dq-qi-import',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permission denied' );
        }

        $notice = '';

        if ( isset( $_POST['dq_qi_import_nonce'] ) && wp_verify_nonce( $_POST['dq_qi_import_nonce'], 'dq_qi_import' ) ) {
            $notice = self::handle_import();
        }

        echo '<div class="wrap">';
        echo '<h1>Import QuickBooks Invoices from CSV</h1>';

        if ( ! empty( $notice ) ) {
            echo wp_kses_post( $notice );
        }

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field( 'dq_qi_import', 'dq_qi_import_nonce' );
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="csv_file">CSV File</label></th><td>';
        echo '<input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="delimiter">Delimiter</label></th><td>';
        echo '<input type="text" id="delimiter" name="delimiter" value="," size="2" />';
        echo '<p class="description">Default: ,</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="date_format">Date Format</label></th><td>';
        echo '<input type="text" id="date_format" name="date_format" value="n/j/Y" />';
        echo '<p class="description">Default: n/j/Y (e.g. 2/5/2025 for February 5, 2025). Change this if your CSV uses a different format.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="default_terms">Default Terms</label></th><td>';
        echo '<input type="text" id="default_terms" name="default_terms" value="Net 60" />';
        echo '<p class="description">Used if Terms is blank.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Update Existing</th><td>';
        echo '<label><input type="checkbox" name="update_existing" value="1" /> Update existing posts that match DocNumber (qi_invoice_no or post title)</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Line Defaults</th><td>';
        echo '<p class="description">If the CSV does not contain line values, one qi_invoice line is created using defaults:
            activity "Labor Rate HR", description "Import", quantity 1, rate = TotalAmt.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button( 'Import CSV' );
        echo '</form>';

        echo '</div>';
    }

    private static function handle_import() {
        if ( empty( $_FILES['csv_file']['name'] ) ) {
            return '<div class="notice notice-error"><p>No CSV selected.</p></div>';
        }

        $delimiter       = isset( $_POST['delimiter'] ) && $_POST['delimiter'] !== '' ? substr( (string) $_POST['delimiter'], 0, 1 ) : ',';
        // Default date format set to n/j/Y to support inputs like 2/5/2025 (month/day/year without leading zeros)
        $date_format     = isset( $_POST['date_format'] ) && $_POST['date_format'] !== '' ? (string) $_POST['date_format'] : 'n/j/Y';
        $default_terms   = isset( $_POST['default_terms'] ) && $_POST['default_terms'] !== '' ? sanitize_text_field( $_POST['default_terms'] ) : 'Net 60';
        $update_existing = ! empty( $_POST['update_existing'] );

        // Upload
        $overrides = [ 'test_form' => false, 'mimes' => [ 'csv' => 'text/csv', 'txt' => 'text/plain' ] ];
        $file      = wp_handle_upload( $_FILES['csv_file'], $overrides );
        if ( isset( $file['error'] ) ) {
            return '<div class="notice notice-error"><p>Upload error: ' . esc_html( $file['error'] ) . '</p></div>';
        }

        $path = $file['file'];
        $fh   = @fopen( $path, 'r' );
        if ( ! $fh ) {
            return '<div class="notice notice-error"><p>Unable to open uploaded file.</p></div>';
        }

        $headers = fgetcsv( $fh, 0, $delimiter );
        if ( ! is_array( $headers ) || empty( $headers ) ) {
            fclose( $fh );
            return '<div class="notice notice-error"><p>CSV seems to have no header row.</p></div>';
        }

        $map = self::build_header_map( $headers );

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = 0;
        $msgs    = [];

        while ( ( $row = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
            if ( empty( array_filter( $row, fn( $v ) => trim( (string) $v ) !== '' ) ) ) {
                continue; // skip empty lines
            }

            $data = self::row_to_assoc( $map, $row );

            $doc     = self::val( $data, ['docnumber','invoiceno','invoice no'] );
            $txn     = self::val( $data, ['txndate','date'] );
            $cust    = self::val( $data, ['customer','displayname'] );
            $memo    = self::val( $data, ['customermemo','memo'] );
            $due     = self::val( $data, ['duedate'] );
            $total   = self::num( self::val( $data, ['totalamt','total','amount'] ) );
            $balance = self::num( self::val( $data, ['balance','balance due'] ) );
            $paid_csv= self::num( self::val( $data, ['paid'] ) ); // optional
            $po      = self::val( $data, ['purchaseorder','po','purchase order'] );
            $terms   = self::val( $data, ['terms'] );

            $activity    = self::val( $data, ['activity','item','itemname'] );
            $description = self::val( $data, ['description','desc'] );
            $qty         = self::num( self::val( $data, ['qty','quantity'] ) );
            $rate        = self::num( self::val( $data, ['rate','unitprice'] ) );
            $amount      = self::num( self::val( $data, ['lineamount','line total'] ) );

            if ( $doc === '' ) {
                $skipped++;
                $msgs[] = 'Skipped a row with empty DocNumber.';
                continue;
            }

            // find existing by qi_invoice_no or post_title
            $post_id = self::find_existing_invoice_post( $doc );
            $is_update = false;

            // Prepare post args
            $post_date     = self::parse_date( $txn, $date_format );
            $post_date_gmt = get_gmt_from_date( $post_date );

            if ( $post_id && $update_existing ) {
                $is_update = true;
                wp_update_post( [
                    'ID'            => $post_id,
                    'post_title'    => $doc,
                    'post_date'     => $post_date,
                    'post_date_gmt' => $post_date_gmt,
                ] );
            } elseif ( ! $post_id ) {
                $post_id = wp_insert_post( [
                    'post_type'     => 'quickbooks_invoice',
                    'post_status'   => 'publish',
                    'post_title'    => $doc,
                    'post_date'     => $post_date,
                    'post_date_gmt' => $post_date_gmt,
                ] );
                if ( is_wp_error( $post_id ) || ! $post_id ) {
                    $errors++;
                    $msgs[] = 'Failed creating post for DocNumber ' . esc_html( $doc );
                    continue;
                }
            } else {
                // Exists but not updating
                $skipped++;
                $msgs[] = 'Exists (no update): ' . esc_html( $doc );
                continue;
            }

            // Save mapped fields
            $set = function( $key, $val ) use ( $post_id ) {
                if ( function_exists( 'update_field' ) ) update_field( $key, $val, $post_id );
                else update_post_meta( $post_id, $key, $val );
            };

            $set( 'qi_invoice_no', $doc );

            if ( $cust !== '' ) {
                $set( 'qi_customer', $cust );
            }

            // Map CustomerMemo to Work Order relationship or text (supports comma or tokens starting with wo-)
            self::set_wo_number_from_memo( $post_id, $memo );

            // Dates
            if ( $txn !== '' ) {
                $set( 'qi_invoice_date', self::format_date_for_meta( $txn, $date_format ) );
            }
            if ( $due !== '' ) {
                $set( 'qi_due_date', self::format_date_for_meta( $due, $date_format ) );
            }

            // Amounts and payment status
            if ( $total !== null ) $set( 'qi_total_billed', $total );
            if ( $balance !== null ) $set( 'qi_balance_due', $balance );

            // Compute total paid (best-effort) and set status string "Paid"/"Unpaid"
            $status = null;
            $paid_calc = null;

            if ( $total !== null && $balance !== null ) {
                $paid_calc = max( 0, round( (float) $total - (float) $balance, 2 ) );
                $status = ( $balance > 0 ? 'Unpaid' : 'Paid' );
            } elseif ( $paid_csv !== null && $total !== null ) {
                $paid_calc = (float) $paid_csv;
                $status = ( $paid_csv >= $total && $total > 0 ) ? 'Paid' : 'Unpaid';
            } elseif ( $paid_csv !== null ) {
                $paid_calc = (float) $paid_csv;
                $status = ( $paid_csv > 0 ) ? 'Paid' : 'Unpaid';
            }

            if ( $paid_calc !== null ) {
                $set( 'qi_total_paid', $paid_calc );
            }
            if ( $status !== null ) {
                $set( 'qi_payment_status', $status );
            }

            if ( $po !== '' ) {
                $set( 'qi_purchase_order', $po );
            }

            $terms_val = $terms !== '' ? $terms : $default_terms;
            $set( 'qi_terms', $terms_val );

            // Ensure qi_invoice repeater has at least one line using defaults
            self::ensure_qi_invoice_line( $post_id, [
                'activity'    => $activity,
                'description' => $description,
                'qty'         => $qty,
                'rate'        => $rate,
                'amount'      => $amount,
                'total'       => $total,
            ] );

            if ( $is_update ) $updated++; else $created++;
        }

        fclose( $fh );

        $out  = '<div class="notice notice-success"><p>';
        $out .= 'Import complete. Created: ' . intval( $created ) . ', Updated: ' . intval( $updated ) . ', Skipped: ' . intval( $skipped ) . ', Errors: ' . intval( $errors ) . '.';
        $out .= '</p></div>';

        if ( ! empty( $msgs ) ) {
            $out .= '<div class="notice notice-info"><ul style="margin-left:20px;list-style:disc;">';
            foreach ( $msgs as $m ) $out .= '<li>' . esc_html( $m ) . '</li>';
            $out .= '</ul></div>';
        }

        return $out;
    }

    private static function build_header_map( array $headers ) : array {
        $map = [];
        foreach ( $headers as $i => $h ) {
            $key = strtolower( trim( (string) $h ) );
            $key = preg_replace( '/\s+/', ' ', $key );
            $map[$i] = $key;
        }
        return $map;
    }

    private static function row_to_assoc( array $map, array $row ) : array {
        $out = [];
        foreach ( $map as $i => $key ) {
            $out[$key] = isset( $row[$i] ) ? trim( (string) $row[$i] ) : '';
        }
        return $out;
    }

    private static function val( array $data, array $candidates ) : string {
        foreach ( $candidates as $c ) {
            if ( isset( $data[ $c ] ) && $data[ $c ] !== '' ) return trim( (string) $data[ $c ] );
        }
        return '';
    }

    private static function num( $v ) {
        if ( $v === '' || $v === null ) return null;
        if ( is_numeric( $v ) ) return (float) $v;
        $clean = preg_replace( '/[^0-9.\-]/', '', (string) $v );
        if ( $clean === '' || ! is_numeric( $clean ) ) return null;
        return (float) $clean;
    }

    private static function parse_date( string $val, string $format ) : string {
        $val = trim( $val );
        if ( $val === '' ) return current_time( 'mysql' );
        $dt = DateTime::createFromFormat( $format, $val );
        if ( $dt instanceof DateTime ) {
            return $dt->format( 'Y-m-d H:i:s' );
        }
        $ts = strtotime( $val );
        if ( $ts ) return date( 'Y-m-d H:i:s', $ts );
        return current_time( 'mysql' );
    }

    private static function format_date_for_meta( string $val, string $format ) : string {
        $dt = DateTime::createFromFormat( $format, $val );
        if ( $dt instanceof DateTime ) return $dt->format( 'Y-m-d' );
        $ts = strtotime( $val );
        return $ts ? date( 'Y-m-d', $ts ) : '';
    }

    private static function find_existing_invoice_post( string $docnumber ) {
        // Try meta qi_invoice_no first
        $q = new WP_Query( [
            'post_type'      => 'quickbooks_invoice',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => 'qi_invoice_no',
                    'value' => $docnumber,
                ]
            ]
        ] );
        if ( $q->have_posts() ) return (int) $q->posts[0];

        // Fallback by post_title
        $q2 = new WP_Query( [
            'post_type'      => 'quickbooks_invoice',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'title'          => $docnumber,
            's'              => $docnumber,
        ] );
        if ( $q2->have_posts() ) {
            // ensure exact match on title
            foreach ( $q2->posts as $pid ) {
                $p = get_post( $pid );
                if ( $p && $p->post_title === $docnumber ) return (int) $pid;
            }
        }
        return 0;
    }

    private static function set_wo_number_from_memo( int $post_id, string $memo ) {
        $vals = self::extract_work_order_titles( (string) $memo );

        if ( empty( $vals ) ) {
            // Clear or set null
            if ( function_exists( 'update_field' ) ) update_field( 'qi_wo_number', null, $post_id );
            else update_post_meta( $post_id, 'qi_wo_number', '' );
            return;
        }

        $field_obj = function_exists( 'get_field_object' ) ? get_field_object( 'qi_wo_number', $post_id ) : null;
        $type      = is_array( $field_obj ) && ! empty( $field_obj['type'] ) ? $field_obj['type'] : '';

        if ( in_array( $type, [ 'relationship', 'post_object' ], true ) ) {
            $ids = [];
            foreach ( $vals as $title ) {
                $p = get_page_by_title( $title, OBJECT, 'workorder' );
                if ( $p instanceof WP_Post ) $ids[] = (int) $p->ID;
            }
            if ( ! empty( $ids ) ) {
                update_field( 'qi_wo_number', $ids, $post_id );
            } else {
                // Nothing matched: per requirement use null
                update_field( 'qi_wo_number', null, $post_id );
            }
        } else {
            // Text/Repeater/etc: store the titles if no strict mapping
            if ( function_exists( 'update_field' ) ) update_field( 'qi_wo_number', $vals, $post_id );
            else update_post_meta( $post_id, 'qi_wo_number', $vals );
        }
    }

    /**
     * Extract candidate Work Order titles from a memo string.
     * Rules:
     * - Split by comma/semicolon into items (trim whitespace)
     * - Also detect any tokens starting with "wo-" (case-insensitive) anywhere in the string
     * - Deduplicate while preserving order
     */
    private static function extract_work_order_titles( string $memo ) : array {
        $memo = trim( $memo );
        if ( $memo === '' ) return [];

        $candidates = [];

        // 1) Comma/semicolon separated values
        $parts = preg_split( '/[;,]/', $memo );
        if ( is_array( $parts ) ) {
            foreach ( $parts as $p ) {
                $t = trim( $p );
                if ( $t !== '' ) $candidates[] = $t;
            }
        }

        // 2) Inline tokens starting with wo-
        if ( preg_match_all( '/\b(wo-[a-z0-9_\-]+)/i', $memo, $m ) ) {
            foreach ( $m[1] as $tok ) {
                $t = trim( $tok );
                if ( $t !== '' ) $candidates[] = $t;
            }
        }

        // Deduplicate preserving order (case-insensitive)
        $out = [];
        $seen = [];
        foreach ( $candidates as $t ) {
            $key = strtolower( $t );
            if ( isset( $seen[$key] ) ) continue;
            $seen[$key] = true;
            $out[] = $t;
        }

        return $out;
    }

    private static function ensure_qi_invoice_line( int $post_id, array $src ) {
        if ( ! function_exists( 'update_field' ) || ! function_exists( 'get_field_object' ) ) {
            return;
        }

        $rep = get_field_object( 'qi_invoice', $post_id );
        if ( empty( $rep ) || empty( $rep['key'] ) || empty( $rep['sub_fields'] ) ) {
            // Repeater not configured; nothing to do
            return;
        }

        $keys = [];
        foreach ( $rep['sub_fields'] as $sf ) {
            $keys[ $sf['name'] ] = $sf['key'];
        }

        // Defaults as requested
        $activity    = trim( (string) ( $src['activity'] ?? '' ) );
        $description = trim( (string) ( $src['description'] ?? '' ) );
        $qty         = self::num( $src['qty'] ?? null );
        $rate        = self::num( $src['rate'] ?? null );
        $amount      = self::num( $src['amount'] ?? null );
        $total       = self::num( $src['total'] ?? null );

        if ( $activity === '' ) $activity = apply_filters( 'dq_qi_default_activity', 'Labor Rate HR' );
        if ( $description === '' ) $description = 'Import';
        if ( $qty === null ) $qty = 1.0;
        if ( $rate === null ) {
            // Default rate = TotalAmt; if missing, rate=0
            $rate = $total !== null ? (float) $total : 0.0;
        }
        if ( $amount === null ) $amount = round( (float) $qty * (float) $rate, 2 );

        $row = [];
        if ( ! empty( $keys['activity'] ) )    $row[ $keys['activity'] ]    = $activity;
        if ( ! empty( $keys['description'] ) ) $row[ $keys['description'] ] = $description;
        if ( ! empty( $keys['quantity'] ) )    $row[ $keys['quantity'] ]    = (float) $qty;
        if ( ! empty( $keys['rate'] ) )        $row[ $keys['rate'] ]        = (float) $rate;
        if ( ! empty( $keys['amount'] ) )      $row[ $keys['amount'] ]      = (float) $amount;

        // If there are existing rows, we won't overwrite them; only ensure at least one row if empty
        $existing = get_field( 'qi_invoice', $post_id );
        if ( empty( $existing ) ) {
            update_field( $rep['key'], [ $row ], $post_id );
        }
    }
}

if ( is_admin() ) {
    DQ_QI_CSV_Import::init();
}