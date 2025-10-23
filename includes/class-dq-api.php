<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_API
 * Handles QuickBooks Online REST API requests (customers, invoices, queries).
 * Version: 4.6.1 (Sandbox-ready)
 */
class DQ_API {

    public static function init() {}

    /**
     * Resolve base URL from environment.
     * Uses option 'dq_env' (sandbox|production). Defaults to sandbox.
     */
    public static function base_url() {
        $env = get_option('dq_env', 'sandbox');
        return ($env === 'production')
            ? 'https://quickbooks.api.intuit.com/v3/company/'
            : 'https://sandbox-quickbooks.api.intuit.com/v3/company/';
    }

    /**
     * Standard headers for QBO
     */
    public static function headers( $json = true ) {
        $access_token = DQ_Auth::get_access_token();
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
            //DQ_Logger::debug( "QBO Response OK ($context)", $data );
            return $data;
        }

       // DQ_Logger::error( "QBO API error ($context)", [ 'code' => $code, 'body' => $body ] );
        return new WP_Error( 'dq_qbo_error', "QuickBooks API returned error ($code): $body" );
    }

    /**
     * Simple GET wrapper (with minorversion if caller includes it in path).
     */
    public static function get( $path, $context = '' ) {
        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::get_tokens()['realm_id'] ?? '' );
        $url = self::base_url() . $realm . '/' . ltrim( $path, '/' );
        $resp = wp_remote_get( $url, [
            'headers' => self::headers(false),
            'timeout' => 30,
        ] );
        return self::handle( $resp, $context );
    }

    /**
     * Simple POST wrapper (adds minorversion).
     */
    public static function post( $path, $data, $context = '' ) {
        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::get_tokens()['realm_id'] ?? '' );
        $url = self::base_url() . $realm . '/' . ltrim( $path, '/' ) . '?minorversion=65';

        $json = wp_json_encode( $data );
        //DQ_Logger::debug( "QBO POST $url", $json );

        $resp = wp_remote_post( $url, [
            'headers' => self::headers(),
            'body'    => $json,
            'timeout' => 30,
        ] );

        return self::handle( $resp, "POST $path" );
    }

    // ------------------------- CUSTOMER METHODS ----------------------------- //

    /**
     * Look up a customer by email using QBO Query.
     * NOTE: Correct syntax is PrimaryEmailAddr = 'email'
     */
    public static function get_customer_by_email( $email ) {
       // DQ_Logger::debug( 'QBO QUERY Customer', $email );

        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::get_tokens()['realm_id'] ?? '' );
        $query = sprintf( "select * from Customer where PrimaryEmailAddr = '%s'", esc_sql( $email ) );
        $url = self::base_url() . $realm . '/query?query=' . rawurlencode( $query ) . '&minorversion=65';

        $resp = wp_remote_get( $url, [
            'headers' => self::headers(false),
            'timeout' => 30,
        ]);

        $data = self::handle( $resp, 'Customer Query' );
        if ( is_wp_error( $data ) ) return $data;

        if ( ! empty( $data['QueryResponse']['Customer'][0] ) ) {
            return $data['QueryResponse']['Customer'][0];
        }
        return null;
    }

    /**
     * Create a customer, gracefully handling duplicate-name (6240).
     */
    public static function create_customer( $data ) {
       // DQ_Logger::info( 'Creating QuickBooks customer', $data );

        $email = $data['PrimaryEmailAddr']['Address'] ?? '';
        $display_name = $data['DisplayName'] ?? '';

        // First, try to find by email
        if ( $email ) {
            $existing = self::get_customer_by_email( $email );
            if ( $existing ) {
               // DQ_Logger::info( 'Customer already exists (via email)', $existing );
                return $existing;
            }
        }

        // Attempt create
        $result = self::post( 'customer', $data, 'Customer Create' );
        if ( is_wp_error( $result ) ) {
            $msg = $result->get_error_message();
            if ( strpos( $msg, 'Duplicate Name Exists Error' ) !== false ) {
                // Query by DisplayName as a fallback
                $s = get_option('dq_settings', []);
                $realm = $s['realm_id'] ?? ( DQ_Auth::get_tokens()['realm_id'] ?? '' );
                $q = sprintf( "select * from Customer where DisplayName = '%s'", esc_sql( $display_name ) );
                $url = self::base_url() . $realm . '/query?query=' . rawurlencode( $q ) . '&minorversion=65';

                $resp = wp_remote_get( $url, [
                    'headers' => self::headers(false),
                    'timeout' => 30,
                ]);
                $retry = self::handle( $resp, 'Customer Duplicate Retry' );
                if ( ! is_wp_error( $retry ) && ! empty( $retry['QueryResponse']['Customer'][0] ) ) {
                    return $retry['QueryResponse']['Customer'][0];
                }
            }
        }
        return $result;
    }

    // -------------------------- INVOICE METHODS ----------------------------- //

    public static function create_invoice( $payload ) {
        return self::post( 'invoice', $payload, 'Create Invoice' );
    }

    /**
     * Robust update method with guaranteed SyncToken fetch for Sandbox/Prod.
     */
    public static function update_invoice( $invoice_id, $payload ) {
        // Log start
        DQ_Logger::info( "Updating QuickBooks invoice #$invoice_id", $payload );

        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::get_tokens()['realm_id'] ?? '' );

        // STEP 1: Fetch current invoice to get SyncToken
        $get_url = self::base_url() . $realm . '/invoice/' . intval($invoice_id) . '?minorversion=65';
        $resp = wp_remote_get( $get_url, [
            'headers' => self::headers(false),
            'timeout' => 30,
        ]);
        $data = self::handle( $resp, "Fetch Invoice #$invoice_id (for update)" );
        if ( is_wp_error( $data ) ) return $data;

        $invoice = $data['Invoice'] ?? $data;
        if ( empty( $invoice['Id'] ) || ! isset( $invoice['SyncToken'] ) ) {
            return new WP_Error( 'dq_no_sync_token', 'QuickBooks did not return SyncToken; verify invoice ID.' );
        }
        $sync_token = (string) $invoice['SyncToken'];

        // STEP 2: Build robust update payload (include CustomerMemo + CustomField)
        $update_payload = [
            'Id'          => (string) $invoice_id,
            'SyncToken'   => $sync_token,
            'sparse'      => false, // full update
            'Line'        => $payload['Line'] ?? [],
            'CustomerRef' => $payload['CustomerRef'] ?? $invoice['CustomerRef'] ?? null,
            'PrivateNote' => $payload['PrivateNote'] ?? '',
        ];

        // Add CustomerMemo if present
        if ( ! empty( $payload['CustomerMemo'] ) ) {
            $update_payload['CustomerMemo'] = $payload['CustomerMemo'];
        }

        // Add CustomField if present (for Purchase Order)
        if ( ! empty( $payload['CustomField'] ) ) {
            $update_payload['CustomField'] = $payload['CustomField'];
        }

        // Optional: preserve existing tax info to avoid "Business Validation Error"
        if ( isset( $invoice['TxnTaxDetail'] ) ) {
            $update_payload['TxnTaxDetail'] = $invoice['TxnTaxDetail'];
        }

        // STEP 3: Send the update
        $post_url = self::base_url() . $realm . '/invoice?minorversion=65';
        DQ_Logger::debug( 'QBO UPDATE Payload', $update_payload );

        $resp2 = wp_remote_post( $post_url, [
            'headers' => self::headers(),
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
        $s = get_option('dq_settings', []);
        $realm = $s['realm_id'] ?? ( DQ_Auth::get_tokens()['realm_id'] ?? '' );
        $url = self::base_url() . $realm . '/query?query=' . rawurlencode( $sql ) . '&minorversion=65';
        $resp = wp_remote_get( $url, [
            'headers' => self::headers(false),
            'timeout' => 30,
        ] );
        return self::handle( $resp, 'Custom Query' );
    }
}
