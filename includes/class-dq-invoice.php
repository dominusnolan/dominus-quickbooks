<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_Invoice
 * Version: 4.7
 * Builds QuickBooks invoice payloads from Work Orders.
 * - Uses raw ACF values (light cleaning only)
 * - Ensures QBO-compliant JSON (no unsupported properties)
 * - Passes selected Purchase Order (taxonomy: purchase_order) into QuickBooks CustomField
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
            //DQ_Logger::warn( 'Customer not found in QuickBooks, creating new one ' . $customer_email );
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
        // Fetch all active QuickBooks items (Products/Services) and map Name to Id
        $item_map = [];
        $query    = "SELECT Name, Id FROM Item WHERE Active = true";
        $qbo_items = DQ_API::query( $query );
        if ( ! is_wp_error( $qbo_items ) && ! empty( $qbo_items['QueryResponse']['Item'] ) ) {
            foreach ( $qbo_items['QueryResponse']['Item'] as $qb_item ) {
                $name = $qb_item['Name'];
                $id   = (string) $qb_item['Id'];
                $item_map[ $name ] = $id;
            }
        }
        
        DQ_Logger::debug( 'Products Query', $item_map );
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
                $amount_num = is_numeric($qty_num) && is_numeric($rate_num)
                    ? (float)$qty_num * (float)$rate_num
                    : self::clean_number( $amount_raw );

                // Validate minimal requirements
                if ( empty( $desc ) || ! is_numeric( $qty_num ) || ! is_numeric( $rate_num ) || (float)$qty_num <= 0 || (float)$rate_num <= 0 ) {
                    //DQ_Logger::warn( 'Skipping invalid line item', compact('date','activity','desc','qty_raw','rate_raw','amount_raw') );
                    continue;
                }
                
                // Determine QuickBooks ItemRef by exact name match. Fallback to default item if not found.
                $default_item_id = get_option( 'dq_default_item_id' ) ?: '1';  // Plugin’s configured default or ID 1
                $itemRefValue = $default_item_id;
                if ( $activity !== '' && isset( $item_map[ $activity ] ) ) {
                    $itemRefValue = $item_map[ $activity ];
                }

                $line_desc = $desc;
        
                $lines[] = [
                    'Amount'      => $amount_num + 0, // ensure numeric
                    'Description' => $line_desc,
                    'DetailType'  => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => [
                        // QBO requires ItemRef (only value is allowed in requests)
                        'ItemRef'   => [ 'value' => $itemRefValue ],
                        // Use "raw but cleaned" values
                        'Qty'       => $qty_num + 0,
                        'UnitPrice' => $rate_num + 0,
                        'TaxCodeRef'  => [ 'value' => 'NON' ],
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

        // --- Add selected Purchase Order (taxonomy: purchase_order) --- //
        $purchase_order_id = get_post_meta( $post_id, '_dq_purchase_order', true );
        
        if ( $purchase_order_id ) {
            $po_term = get_term( $purchase_order_id, 'purchase_order' );
            if ( $po_term && ! is_wp_error( $po_term ) ) {
                $po_name = $po_term->name;
                $def_id = self::get_purchase_order_defid();
                
                // Option 1: Add as QuickBooks CustomField
                $payload['CustomField'][] = [
                    'DefinitionId' => $def_id,
                    'Name'         => 'Purchase Orders',
                    'Type'         => 'StringType',
                    'StringValue'  => $po_name,
                ];
                
                DQ_Logger::debug('Added Purchase Order custom field', $payload['CustomField']);
                // Option 2 (optional): Use QuickBooks native PurchaseOrderRef
                // Uncomment below if your QBO account supports it:
                // $payload['PurchaseOrderRef'] = [
                //     'value' => $po_name,
                //     'name'  => 'Purchase Order',
                // ];
            }
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

        // --- Add dynamic Note to Customer ---
        $post_title   = get_the_title( $post_id ) ?: '(Untitled Work Order)';
        $installed_pid = function_exists( 'get_field' ) ? get_field( 'installed_product_id', $post_id ) : '';

        $note_text = "Work Order ID: {$post_title}";
        if ( ! empty( $installed_pid ) ) {
            $note_text .= "\nInstalled Product ID: {$installed_pid}";
        }
        
        $po_term_id = get_post_meta( $post_id, '_dq_purchase_order', true );
        $po_name = $po_term_id ? get_term( $po_term_id )->name : '';
        
        if ( ! empty( $po_name ) ) {
            $note_text .= "\nPurchase Order: {$po_name}";
        }

        $payload['CustomerMemo'] = [
            'value' => $note_text,
        ];
        
        // Force overall invoice to be non-taxable
        $payload['ApplyTaxAfterDiscount'] = false;
        $payload['TxnTaxDetail'] = [
            'TxnTaxCodeRef' => [ 'value' => 'NONTAX' ], // ← use the real code from step 2
            'TotalTax'      => 0
        ];

        // Log final JSON for debugging
        //DQ_Logger::debug( 'Invoice JSON', $payload );
        //DQ_Logger::debug('QBO Preferences', DQ_API::get('/company/' . DQ_QB_REALM_ID . '/preferences'));
        return $payload;
    }
    
    
    private static function get_purchase_order_defid() {
        $prefs = DQ_API::get( '/company/' . DQ_QB_REALM_ID . '/preferences' );

        if ( is_wp_error( $prefs ) ) {
            DQ_Logger::error( 'Failed to fetch QuickBooks Preferences: ' . $prefs->get_error_message() );
            return '1'; // fallback
        }

        $custom_fields = [];
        if ( ! empty( $prefs['Preferences']['SalesFormsPrefs']['CustomField'] ) ) {
            $custom_fields = $prefs['Preferences']['SalesFormsPrefs']['CustomField'];
        } elseif ( ! empty( $prefs['CustomField'] ) ) {
            $custom_fields = $prefs['CustomField'];
        }

        if ( empty( $custom_fields ) ) {
            DQ_Logger::warn( 'No custom fields found in QuickBooks Preferences.' );
            return '1';
        }

        foreach ( $custom_fields as $field ) {
            if ( isset( $field['Name'] ) && stripos( $field['Name'], 'purchase order' ) !== false ) {
                DQ_Logger::debug( 'Detected Purchase Order DefinitionId: ' . $field['DefinitionId'] );
                return (string) $field['DefinitionId'];
            }
        }

        DQ_Logger::warn( 'Purchase Order DefinitionId not found, using fallback 1.' );
        return '1';
    }

    /**
     * Light numeric cleaner: keeps digits and a single dot, returns float if possible otherwise null.
     */
    private static function clean_number( $val ) {
        if ( $val === null || $val === '' ) return null;
        if ( is_numeric( $val ) ) return $val + 0;
        $clean = preg_replace( '/[^0-9.]/', '', (string) $val );
        if ( $clean === '' ) return null;
        $parts = explode('.', $clean);
        if ( count($parts) > 2 ) {
            $clean = $parts[0] . '.' . implode('', array_slice($parts,1));
        }
        return is_numeric( $clean ) ? $clean + 0 : null;
    }
}