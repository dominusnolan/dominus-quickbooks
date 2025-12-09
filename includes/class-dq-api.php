<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_API
 * Handles QuickBooks Online REST API requests (customers, invoices, queries).
 * Version: 4.6.2 (adds header WP_Error guards)
 */
class DQ_API {

    public static function init() {}

    public static function base_url() {
        $env = get_option('dq_env', 'sandbox');
        return ($env === 'production')
            ? 'https://quickbooks.api.intuit.com/v3/company/'
            : 'https://sandbox-quickbooks.api.intuit.com/v3/company/';
    }

    /**
     * Return request headers OR WP_Error if token missing/expired.
     */
    public static function headers( $json = true ) {
        $access_token = DQ_Auth::get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return new WP_Error(
                'dq_access_token_missing',
                'QuickBooks access token missing or expired. Please reconnect (Dominus QB → Connect to QuickBooks).'
            );
        }
        $headers = [
            'Authorization' => 'Bearer ' . $access_token,
            'Accept'        => 'application/json',
        ];
        if ( $json ) {
            $headers['Content-Type'] = 'application/json';
        }
        return $headers;
    }

    /**
     * Normalize and log QBO responses.
     */
    public static function handle( $resp, $context = '' ) {
        if ( is_wp_error( $resp ) ) return $resp;

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );

        if ( $code >= 200 && $code < 300 && is_array($data) ) {
            return $data;
        }

        return new WP_Error( 'dq_qbo_error', "QuickBooks API returned error ($code): $body", [
            'http_code' => $code,
            'raw_body'  => $body,
            'context'   => $context,
        ] );
    }

    public static function get( $path, $context = '' ) {
        $headers = self::headers(false);
        if ( is_wp_error( $headers ) ) return $headers;

        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::realm_id() ?? '' );
        if ( ! $realm ) {
            return new WP_Error('dq_no_realm_id', 'Realm ID missing: connect to QuickBooks first.');
        }
        $url = self::base_url() . $realm . '/' . ltrim( $path, '/' );
        $resp = wp_remote_get( $url, [
            'headers' => $headers,
            'timeout' => 30,
        ] );
        return self::handle( $resp, $context ?: "GET $path" );
    }

    public static function post( $path, $data, $context = '' ) {
        $headers = self::headers();
        if ( is_wp_error( $headers ) ) return $headers;

        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::realm_id() ?? '' );
        if ( ! $realm ) {
            return new WP_Error('dq_no_realm_id', 'Realm ID missing: connect to QuickBooks first.');
        }
        $url = self::base_url() . $realm . '/' . ltrim( $path, '/' ) . '?minorversion=65';

        $json = wp_json_encode( $data );
        $resp = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => $json,
            'timeout' => 30,
        ] );

        return self::handle( $resp, $context ?: "POST $path" );
    }

    // -------------------------- INVOICE METHODS ----------------------------- //

    public static function create_invoice( $payload ) {
        return self::post( 'invoice', $payload, 'Create Invoice' );
    }

    /**
     * Convenience wrapper to create a Payment.
     * @param array $payload QuickBooks Payment payload
     */
    public static function create_payment( $payload ) {
        return self::post( 'payment', $payload, 'Create Payment' );
    }

    public static function update_invoice( $invoice_id, $payload ) {
        $headers_fetch = self::headers(false);
        if ( is_wp_error( $headers_fetch ) ) return $headers_fetch;

        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::realm_id() ?? '' );
        if ( ! $realm ) {
            return new WP_Error('dq_no_realm_id', 'Realm ID missing: connect to QuickBooks first.');
        }

        // STEP 1: Fetch current invoice for SyncToken
        $get_url = self::base_url() . $realm . '/invoice/' . intval($invoice_id) . '?minorversion=65';
        $resp = wp_remote_get( $get_url, [
            'headers' => $headers_fetch,
            'timeout' => 30,
        ]);
        $data = self::handle( $resp, "Fetch Invoice #$invoice_id (for update)" );
        if ( is_wp_error( $data ) ) return $data;

        $invoice = $data['Invoice'] ?? $data;
        if ( empty( $invoice['Id'] ) || ! isset( $invoice['SyncToken'] ) ) {
            return new WP_Error( 'dq_no_sync_token', 'QuickBooks did not return SyncToken; verify invoice ID.' );
        }

        // Build update payload
        $update_payload = [
            'Id'          => (string) $invoice_id,
            'SyncToken'   => (string) $invoice['SyncToken'],
            'sparse'      => false,
            'Line'        => $payload['Line'] ?? [],
            'CustomerRef' => $payload['CustomerRef'] ?? $invoice['CustomerRef'] ?? null,
            'PrivateNote' => array_key_exists('PrivateNote', $payload)
                ? $payload['PrivateNote']
                : ($invoice['PrivateNote'] ?? ''),
        ];

        // Pass-through supported header fields from caller payload
        $header_fields = [
            'TxnDate', 'DueDate', 'BillAddr', 'ShipAddr',
            'SalesTermRef', 'DocNumber', 'ApplyTaxAfterDiscount'
        ];
        foreach ( $header_fields as $k ) {
            if ( array_key_exists( $k, $payload ) ) {
                $update_payload[ $k ] = $payload[ $k ];
            }
        }

        if ( ! empty( $payload['CustomerMemo'] ) ) {
            $update_payload['CustomerMemo'] = $payload['CustomerMemo'];
        }
        if ( ! empty( $payload['CustomField'] ) ) {
            $update_payload['CustomField'] = $payload['CustomField'];
        }
        // Preserve existing tax detail unless caller provided override above
        if ( isset( $invoice['TxnTaxDetail'] ) && ! isset( $update_payload['TxnTaxDetail'] ) ) {
            $update_payload['TxnTaxDetail'] = $invoice['TxnTaxDetail'];
        }

        $headers_post = self::headers();
        if ( is_wp_error( $headers_post ) ) return $headers_post;

        $post_url = self::base_url() . $realm . '/invoice?minorversion=65';
        DQ_Logger::debug( 'QBO UPDATE Payload', $update_payload );

        $resp2 = wp_remote_post( $post_url, [
            'headers' => $headers_post,
            'body'    => wp_json_encode( $update_payload ),
            'timeout' => 30,
        ]);

        $result = self::handle( $resp2, "Update Invoice #$invoice_id" );

        if ( ! is_wp_error( $result ) && isset( $result['Invoice'] ) ) {
            DQ_Logger::info( "QuickBooks invoice #$invoice_id updated successfully", $result['Invoice'] );
        } else {
            DQ_Logger::error( "QuickBooks update failed for invoice #$invoice_id", $result );
        }

        return $result;
    }

    public static function get_invoice( $id ) {
        return self::get( 'invoice/' . intval($id) . '?minorversion=65', 'Get Invoice' );
    }

    public static function query( $sql ) {
        $headers = self::headers(false);
        if ( is_wp_error( $headers ) ) return $headers;

        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::realm_id() ?? '' );
        if ( ! $realm ) {
            return new WP_Error('dq_no_realm_id', 'Realm ID missing: connect to QuickBooks first.');
        }

        $url = self::base_url() . $realm . '/query?query=' . rawurlencode( $sql ) . '&minorversion=65';
        $resp = wp_remote_get( $url, [
            'headers' => $headers,
            'timeout' => 30,
        ] );
        return self::handle( $resp, 'Custom Query' );
    }

    public static function create( $entity, $payload ) {
        $e = strtolower(trim($entity));
        switch ($e) {
            case 'invoice':
                return self::create_invoice($payload);
            case 'customer':
                return self::create_customer($payload);
            default:
                return self::post($e, $payload, 'Create ' . ucfirst($e));
        }
    }

    public static function get_invoice_by_id( $invoice_id ) {
        if ( ! $invoice_id ) {
            return new WP_Error('dq_no_invoice_id', 'Missing invoice id.');
        }

        $realm_id = self::realm_id();
        if ( ! $realm_id ) {
            return new WP_Error('dq_no_realm','Missing QuickBooks realm/company id.');
        }

        $headers = self::headers(true);
        if ( is_wp_error( $headers ) ) return $headers;

        $url = trailingslashit( self::base_url() . $realm_id ) . 'invoice/' . urlencode( $invoice_id ) . '?minorversion=65';
        $res = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 20 ] );
        if ( is_wp_error( $res ) ) return $res;

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code !== 200 || empty( $body['Invoice'] ) ) {
            $q = "select * from Invoice where Id = '{$invoice_id}'";
            $fallback = self::query_raw( $q );
            if ( ! is_wp_error( $fallback ) && ! empty( $fallback['QueryResponse']['Invoice'][0] ) ) {
                return $fallback['QueryResponse']['Invoice'][0];
            }
            return new WP_Error('dq_qbo_fetch_failed', 'Unable to fetch invoice from QuickBooks.', [ 'code' => $code, 'body' => $body ]);
        }

        return $body['Invoice'];
    }

    public static function query_raw( $sql ) {
        $realm_id = DQ_Auth::realm_id();
        if ( ! $realm_id ) return new WP_Error('dq_no_realm', 'Missing QuickBooks realm/company id.');
        $headers = self::headers(true);
        if ( is_wp_error( $headers ) ) return $headers;

        $url = trailingslashit( self::base_url() . $realm_id ) . 'query?minorversion=65&query=' . rawurlencode( $sql );
        $res = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 25 ] );
        if ( is_wp_error( $res ) ) return $res;

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code !== 200 ) {
            return new WP_Error('dq_qbo_query_failed', 'Query endpoint returned an error.', [ 'code' => $code, 'body' => $body ]);
        }
        return $body;
    }

    private static function realm_id() {
        if ( defined('DQ_REALM_ID') && DQ_REALM_ID ) return (string) DQ_REALM_ID;
        foreach ( ['woqb_realm_id','dq_realm_id','dq_company_id','qb_company_id','qbo_company_id'] as $opt ) {
            $rid = get_option( $opt );
            if ( ! empty( $rid ) ) return (string) $rid;
        }
        if ( class_exists('DQ_Auth') && method_exists('DQ_Auth', 'realm_id') ) {
            $rid = DQ_Auth::realm_id();
            if ( ! empty( $rid ) ) return (string) $rid;
        }
        $rid = apply_filters( 'dq_realm_id', '' );
        return (string) $rid;
    }

    public static function get_invoice_by_docnumber($docnumber) {
        $query = "SELECT * FROM Invoice WHERE DocNumber = '$docnumber'";
        return self::query($query);
    }

    /**
     * Query payments linked to a specific invoice ID.
     * Uses QuickBooks SQL: SELECT * FROM Payment WHERE Line.Any.LinkedTxn.TxnId = '{invoice_id}'
     *
     * @param string $invoice_id The QuickBooks Invoice ID
     * @return array|WP_Error Array of payments or WP_Error on failure
     */
    public static function get_payments_for_invoice( $invoice_id ) {
        if ( empty( $invoice_id ) ) {
            return new WP_Error( 'dq_no_invoice_id', 'Invoice ID is required to query payments.' );
        }

        // Sanitize invoice_id to prevent SQL injection
        $invoice_id = esc_sql( (string) $invoice_id );
        $sql = "SELECT * FROM Payment WHERE Line.Any.LinkedTxn.TxnId = '{$invoice_id}'";
        $result = self::query( $sql );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Return the payments array, or empty array if none found
        return $result['QueryResponse']['Payment'] ?? [];
    }

    /**
     * Calculate deposited and undeposited payment totals for an invoice.
     * Payments with DepositToAccountRef name "Undeposited Funds" are undeposited,
     * all others are deposited.
     *
     * @param string $invoice_id The QuickBooks Invoice ID
     * @return array ['deposited' => float, 'undeposited' => float] or WP_Error
     */
    public static function get_payment_deposit_totals( $invoice_id ) {
        $payments = self::get_payments_for_invoice( $invoice_id );

        if ( is_wp_error( $payments ) ) {
            DQ_Logger::error( 'Failed to fetch payments for invoice', [
                'invoice_id' => $invoice_id,
                'error' => $payments->get_error_message()
            ]);
            return $payments;
        }

        $deposited = 0.0;
        $undeposited = 0.0;

        foreach ( $payments as $payment ) {
            // Only process payments that are linked to this invoice
            if ( ! self::payment_linked_to_invoice( $payment, $invoice_id ) ) {
                continue;
            }

            $amount = isset( $payment['TotalAmt'] ) ? (float) $payment['TotalAmt'] : 0.0;

            // Check if payment is deposited to "Undeposited Funds"
            $account_name = '';
            if ( ! empty( $payment['DepositToAccountRef']['name'] ) ) {
                $account_name = trim( (string) $payment['DepositToAccountRef']['name'] );
            }

            // Case-insensitive comparison for "Undeposited Funds"
            if ( strcasecmp( $account_name, 'Undeposited Funds' ) === 0 ) {
                $undeposited += $amount;
            } else {
                // Only count as deposited if there's an actual account (not empty)
                if ( ! empty( $account_name ) ) {
                    $deposited += $amount;
                } else {
                    // Default to undeposited if no account specified
                    $undeposited += $amount;
                }
            }
        }

        DQ_Logger::debug( 'Payment deposit totals calculated', [
            'invoice_id' => $invoice_id,
            'payment_count' => count($payments),
            'deposited' => $deposited,
            'undeposited' => $undeposited,
        ]);

        return [
            'deposited'   => $deposited,
            'undeposited' => $undeposited,
        ];
    }

    /**
     * Check if a payment is linked to a specific invoice by examining its Line items.
     *
     * @param array $payment The payment data from QuickBooks
     * @param string $invoice_id The invoice ID to check for
     * @return bool True if the payment is linked to the invoice
     */
    private static function payment_linked_to_invoice( $payment, $invoice_id ) {
        if ( empty( $payment['Line'] ) || ! is_array( $payment['Line'] ) ) {
            return false;
        }

        $invoice_id = (string) $invoice_id;

        foreach ( $payment['Line'] as $line ) {
            // Check if this line has LinkedTxn entries
            if ( empty( $line['LinkedTxn'] ) || ! is_array( $line['LinkedTxn'] ) ) {
                continue;
            }

            // Check each linked transaction
            foreach ( $line['LinkedTxn'] as $linked ) {
                if ( isset( $linked['TxnId'] ) && (string) $linked['TxnId'] === $invoice_id ) {
                    // Also verify it's an Invoice type transaction
                    if ( empty( $linked['TxnType'] ) || $linked['TxnType'] === 'Invoice' ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}