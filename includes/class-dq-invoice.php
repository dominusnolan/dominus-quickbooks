<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_Invoice
 * Version: 4.6
 * Builds QuickBooks invoice payloads from Work Orders.
 * - Uses raw ACF values (light cleaning only)
 * - Ensures QBO-compliant JSON (no unsupported properties)
 */
class DQ_Invoice {

    /**
     * Build (but DO NOT send) a QuickBooks invoice payload from a Work Order.
     * The caller (metabox) is responsible for calling DQ_API::create_invoice() or ::update_invoice().
     */
    public static function build_from_work_order( $post_id ) {
        DQ_Logger::info( 'Building invoice for Work Order #' . $post_id );

        // --- Determine customer email (post meta > ACF > fallback) --- //
        $customer_email = get_post_meta( $post_id, 'wo_customer_email', true );
        if ( empty( $customer_email ) && function_exists( 'get_field' ) ) {
            $customer_email = get_field( 'wo_customer_email', $post_id );
        }
        if ( empty( $customer_email ) ) {
            $customer_email = 'emdMillipore@ecn-trading.com';
        }

        // --- Fetch or create customer in QBO --- //
        $customer = DQ_API::get_customer_by_email( $customer_email );
        if ( is_wp_error( $customer ) || empty( $customer ) ) {
            DQ_Logger::warn( 'Customer not found in QuickBooks, creating new one ' . $customer_email );
            $data = [
                'DisplayName'      => preg_replace( '/@.*/', '', $customer_email ),
                'PrimaryEmailAddr' => [ 'Address' => $customer_email ],
                'GivenName'        => preg_replace( '/@.*/', '', $customer_email ),
                'FamilyName'       => 'Account',
            ];
            $customer = DQ_API::create_customer( $data );
        }
        if ( is_wp_error( $customer ) ) {
            return $customer;
        }
        $customer_id = isset( $customer['Id'] ) ? (string) $customer['Id'] : '';
        if ( ! $customer_id ) {
            return new WP_Error( 'dq_no_customer_id', 'No valid QuickBooks Customer ID found.' );
        }

        // --- Build Line items from ACF repeater wo_invoice --- //
        $lines = [];
        if ( function_exists( 'have_rows' ) && have_rows( 'wo_invoice', $post_id ) ) {
            while ( have_rows( 'wo_invoice', $post_id ) ) {
                the_row();
                $date        = get_sub_field( 'date' );
                $activity    = trim( (string) get_sub_field( 'activity' ) );
                $desc        = trim( (string) get_sub_field( 'description' ) );

                // Keep raw values but lightly clean to numeric for QBO
                $qty_raw     = get_sub_field( 'quantity' );
                $rate_raw    = get_sub_field( 'rate' );
                $amount_raw  = get_sub_field( 'amount' );

                $qty_num   = self::clean_number( $qty_raw );
                $rate_num  = self::clean_number( $rate_raw );
                // Prefer computed amount; fallback to provided amount if any
                $amount_num = is_numeric($qty_num) && is_numeric($rate_num)
                    ? (float)$qty_num * (float)$rate_num
                    : self::clean_number( $amount_raw );

                // Validate minimal requirements
                if ( empty( $desc ) || ! is_numeric( $qty_num ) || ! is_numeric( $rate_num ) || (float)$qty_num <= 0 || (float)$rate_num <= 0 ) {
                    DQ_Logger::warn( 'Skipping invalid line item', compact('date','activity','desc','qty_raw','rate_raw','amount_raw') );
                    continue;
                }

                $line_desc = $activity ? ($activity . ': ' . $desc) : $desc;

                $lines[] = [
                    'Amount'      => $amount_num + 0, // ensure numeric
                    'Description' => $line_desc,
                    'DetailType'  => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => [
                        // QBO requires ItemRef (only value is allowed in requests)
                        'ItemRef'   => [ 'value' => '1' ],
                        // Use "raw but cleaned" values
                        'Qty'       => $qty_num + 0,
                        'UnitPrice' => $rate_num + 0,
                    ],
                ];
            }
        }

        if ( empty( $lines ) ) {
            return new WP_Error(
                'dq_no_invoice_lines',
                sprintf( 'No valid invoice lines found for Work Order #%d. Please add at least one line item under “Invoice”.', $post_id )
            );
        }

        // Optional note: Installed Product ID goes into PrivateNote (fully supported)
        $installed = function_exists( 'get_field' ) ? get_field( 'installed_product_id', $post_id ) : '';
        $payload = [
            'CustomerRef' => [ 'value' => $customer_id ],
            'Line'        => $lines,
        ];
        if ( ! empty( $installed ) ) {
            $payload['PrivateNote'] = 'Installed Product ID: ' . $installed;
        }

        // --- Sanitize payload to avoid QBO 2010 errors (unsupported properties) --- //
        $invalid_keys = [
            'Id','SyncToken','sparse','domain','Invoice','Name','MetaData',
            'AllowIPNPayment','AllowOnlinePayment','AllowOnlineCreditCardPayment',
            'AllowOnlineACHPayment','TotalAmt','Balance'
        ];
        foreach ( $invalid_keys as $key ) {
            if ( isset( $payload[ $key ] ) ) unset( $payload[ $key ] );
        }

        // Log final JSON for debugging
        DQ_Logger::debug( 'Invoice JSON', $payload );
        return $payload;
    }

    /**
     * Light numeric cleaner: keeps digits and a single dot, returns float if possible otherwise null.
     */
    private static function clean_number( $val ) {
        if ( $val === null || $val === '' ) return null;
        if ( is_numeric( $val ) ) return $val + 0;
        $clean = preg_replace( '/[^0-9.]/', '', (string) $val );
        if ( $clean === '' ) return null;
        // Collapse multiple dots to the first valid numeric interpretation
        $parts = explode('.', $clean);
        if ( count($parts) > 2 ) {
            $clean = $parts[0] . '.' . implode('', array_slice($parts,1));
        }
        return is_numeric( $clean ) ? $clean + 0 : null;
    }
}
