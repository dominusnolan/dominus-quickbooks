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
     * First attempts to use QuickBooks query with LinkedTxn filter.
     * Falls back to fetching invoice and checking its LinkedTxn references.
     *
     * @param string $invoice_id The QuickBooks Invoice ID
     * @return array|WP_Error Array of payments or WP_Error on failure
     */
    public static function get_payments_for_invoice( $invoice_id ) {
        if ( empty( $invoice_id ) ) {
            return new WP_Error( 'dq_no_invoice_id', 'Invoice ID is required to query payments.' );
        }

        $invoice_id = esc_sql( (string) $invoice_id );
        
        // First, try the direct query approach with LinkedTxn filter
        // Filter by both TxnId and TxnType to ensure we only get invoice payments
        $sql = "SELECT * FROM Payment WHERE Line.Any.LinkedTxn.TxnId = '{$invoice_id}' AND Line.Any.LinkedTxn.TxnType = 'Invoice'";
        $result = self::query( $sql );

        if ( is_wp_error( $result ) ) {
            DQ_Logger::debug( 'Payment query with LinkedTxn filter failed, trying alternative approach', [
                'invoice_id' => $invoice_id,
                'error' => $result->get_error_message()
            ]);
            
            // Fallback: Get the invoice and check which payments reference it
            return self::get_payments_for_invoice_fallback( $invoice_id );
        }

        $payments = $result['QueryResponse']['Payment'] ?? [];
        
        // If no payments found with the query, try the fallback approach
        if ( empty( $payments ) ) {
            DQ_Logger::debug( 'No payments found with direct query, trying fallback', [
                'invoice_id' => $invoice_id
            ]);
            return self::get_payments_for_invoice_fallback( $invoice_id );
        }

        DQ_Logger::debug( 'Found payments with direct query', [
            'invoice_id' => $invoice_id,
            'payment_count' => count( $payments )
        ]);

        return $payments;
    }

    /**
     * Fallback method to get payments for an invoice.
     * Fetches the invoice to get customer ID, then queries all payments for that customer
     * and filters them to find payments linked to this invoice.
     *
     * @param string $invoice_id The QuickBooks Invoice ID
     * @return array|WP_Error Array of payments or WP_Error on failure
     */
    private static function get_payments_for_invoice_fallback( $invoice_id ) {
        // Get the invoice to find the customer
        $invoice_data = self::get_invoice( $invoice_id );
        if ( is_wp_error( $invoice_data ) ) {
            return $invoice_data;
        }

        $invoice = $invoice_data['Invoice'] ?? $invoice_data;
        if ( empty( $invoice['CustomerRef']['value'] ) ) {
            return new WP_Error( 'dq_no_customer', 'Invoice does not have a customer reference.' );
        }

        $customer_id = esc_sql( (string) $invoice['CustomerRef']['value'] );

        // Query all payments for this customer
        $sql = "SELECT * FROM Payment WHERE CustomerRef = '{$customer_id}'";
        $result = self::query( $sql );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $all_payments = $result['QueryResponse']['Payment'] ?? [];
        $total_payments = count($all_payments);
        
        // Filter payments to only include those linked to this invoice
        $linked_payments = [];
        foreach ( $all_payments as $payment ) {
            if ( self::payment_is_linked_to_invoice( $payment, $invoice_id ) ) {
                $linked_payments[] = $payment;
            }
        }

        $linked_count = count($linked_payments);
        DQ_Logger::debug( 'Fallback payment query completed', [
            'invoice_id' => $invoice_id,
            'customer_id' => $customer_id,
            'total_customer_payments' => $total_payments,
            'linked_payments' => $linked_count
        ]);

        return $linked_payments;
    }

    /**
     * Check if a payment is linked to a specific invoice by examining its Line items.
     * This is a helper method used in fallback scenarios.
     *
     * @param array $payment The payment data from QuickBooks
     * @param string $invoice_id The invoice ID to check for
     * @return bool True if the payment is linked to the invoice
     */
    private static function payment_is_linked_to_invoice( $payment, $invoice_id ) {
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
                    // Verify it's an Invoice type transaction
                    if ( ! empty( $linked['TxnType'] ) && $linked['TxnType'] === 'Invoice' ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Calculate the amount from a payment that was applied to a specific invoice.
     * Sums Line.Amount values for payment lines linked to the invoice.
     *
     * @param array $payment The payment data from QuickBooks
     * @param string $invoice_id The invoice ID to calculate for
     * @return float The amount applied to this invoice
     */
    private static function get_payment_amount_for_invoice( $payment, $invoice_id ) {
        $amount = 0.0;

        if ( empty( $payment['Line'] ) || ! is_array( $payment['Line'] ) ) {
            return $amount;
        }

        $invoice_id = (string) $invoice_id;

        foreach ( $payment['Line'] as $line ) {
            // Check if this line has LinkedTxn entries
            if ( empty( $line['LinkedTxn'] ) || ! is_array( $line['LinkedTxn'] ) ) {
                continue;
            }

            // Check each linked transaction
            foreach ( $line['LinkedTxn'] as $linked ) {
                $is_matching_invoice = isset( $linked['TxnId'] ) 
                    && (string) $linked['TxnId'] === $invoice_id 
                    && ! empty( $linked['TxnType'] ) 
                    && $linked['TxnType'] === 'Invoice';
                
                if ( $is_matching_invoice ) {
                    // This line is linked to our invoice, add its amount
                    $amount += isset( $line['Amount'] ) ? (float) $line['Amount'] : 0.0;
                }
            }
        }

        return $amount;
    }

    /**
     * Check if a payment is included in any bank deposit.
     * Queries all Deposits and checks if the payment ID appears in any Line.LinkedTxn.
     *
     * @param string $payment_id The QuickBooks Payment ID
     * @return bool True if payment is found in any deposit, false otherwise
     */
    private static function is_payment_in_deposit( $payment_id ) {
        if ( empty( $payment_id ) ) {
            return false;
        }

        $payment_id = esc_sql( (string) $payment_id );

        // Query deposits that contain this payment (only need to verify existence)
        $sql = "SELECT Id FROM Deposit WHERE Line.Any.LinkedTxn.TxnId = '{$payment_id}' AND Line.Any.LinkedTxn.TxnType = 'Payment'";
        $result = self::query( $sql );

        if ( is_wp_error( $result ) ) {
            DQ_Logger::debug( 'Deposit query failed for payment', [
                'payment_id' => $payment_id,
                'error' => $result->get_error_message()
            ]);
            return false;
        }

        $deposits = $result['QueryResponse']['Deposit'] ?? [];

        if ( ! empty( $deposits ) ) {
            DQ_Logger::debug( 'Payment found in deposit', [
                'payment_id' => $payment_id,
                'deposit_count' => count( $deposits )
            ]);
            return true;
        }

        return false;
    }

    /**
     * Calculate deposited and undeposited payment totals for an invoice.
     * 
     * For each payment linked to the invoice, uses get_payment_amount_for_invoice()
     * to calculate the amount applied to this specific invoice (from Line.Amount 
     * values), then classifies based on:
     * - Payment is deposited if DepositToAccountRef.name is NOT 'Undeposited Funds'
     * - Payment is deposited if it's in 'Undeposited Funds' but found in a Bank Deposit
     * - Otherwise counted as not deposited
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
            // Calculate the amount applied to THIS specific invoice
            $amount_for_invoice = self::get_payment_amount_for_invoice( $payment, $invoice_id );

            // Skip if no amount applied to this invoice
            if ( $amount_for_invoice <= 0.0 ) {
                continue;
            }

            // Check if payment is deposited to "Undeposited Funds"
            $account_name = '';
            if ( ! empty( $payment['DepositToAccountRef']['name'] ) ) {
                $account_name = trim( (string) $payment['DepositToAccountRef']['name'] );
            }

            $is_deposited = false;
            $deposit_reason = '';

            // Payment is deposited if account is NOT "Undeposited Funds"
            if ( ! empty( $account_name ) && strcasecmp( $account_name, 'Undeposited Funds' ) !== 0 ) {
                $is_deposited = true;
                $deposit_reason = 'direct_account';
            }
            // Payment may still be deposited if it was in Undeposited Funds but later included in a Bank Deposit
            else if ( ! empty( $payment['Id'] ) && self::is_payment_in_deposit( $payment['Id'] ) ) {
                $is_deposited = true;
                $deposit_reason = 'bank_deposit';
            } else {
                $deposit_reason = 'undeposited';
            }

            if ( $is_deposited ) {
                $deposited += $amount_for_invoice;
            } else {
                $undeposited += $amount_for_invoice;
            }

            DQ_Logger::debug( 'Payment processed for deposit calculation', [
                'invoice_id' => $invoice_id,
                'payment_id' => $payment['Id'] ?? 'unknown',
                'amount_for_invoice' => $amount_for_invoice,
                'account_name' => $account_name ?: '(none)',
                'is_deposited' => $is_deposited,
                'reason' => $deposit_reason,
            ]);
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
}