<?php
/**
 * Dominus QuickBooks Invoice Handler
 * Handles creating and updating QuickBooks invoices from Work Orders.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_Invoice {

    public function __construct() {
        add_action( 'admin_post_dq_create_invoice', [ $this, 'create_invoice_from_workorder' ], 20 );
        add_action( 'admin_post_dq_update_invoice', [ $this, 'update_invoice_from_workorder' ], 20 );
    }

    /**
     * Fixed QuickBooks Customer (emdMillipore@ecn-trading.com)
     */
    private function dq_get_fixed_customer_info() {
        $fixed_email = 'emdMillipore@ecn-trading.com';
        $user = get_user_by( 'email', $fixed_email );

        if ( ! $user ) {
            wp_die( "User with email {$fixed_email} not found in WordPress." );
        }

        $user_id = $user->ID;
        $name    = $user->display_name ?: 'Millipore Customer';
        $email   = $user->user_email;

        $fields = [ 'street_address_1', 'street_address_2', 'city', 'state', 'zip_code', 'country' ];
        $values = [];
        foreach ( $fields as $f ) {
            $values[$f] = function_exists( 'get_field' ) ? get_field( $f, 'user_' . $user_id ) : '';
        }

        $bill_addr = array_filter([
            'Line1' => $values['street_address_1'],
            'Line2' => $values['street_address_2'],
            'City'  => $values['city'],
            'CountrySubDivisionCode' => $values['state'],
            'PostalCode' => $values['zip_code'],
            'Country' => $values['country'],
        ]);

        return [
            'id'        => $user_id,
            'name'      => $name,
            'email'     => $email,
            'bill_addr' => $bill_addr,
        ];
    }

    /**
     * Build QuickBooks invoice payload
     */
    private function dq_build_invoice_payload( $post_id, $customer_ref, $bill_addr ) {
        $post_title   = get_the_title( $post_id ) ?: '(Untitled Work Order)';
        $installed_pid = function_exists( 'get_field' ) ? get_field( 'installed_product_id', $post_id ) : '';
        $invoice_rows = function_exists( 'get_field' ) ? get_field( 'wo_invoice', $post_id ) : [];

        $note_text = "Note to Customer:\n{$post_title}";
        if ( ! empty( $installed_pid ) ) {
            $note_text .= "\nInstalled Product ID: {$installed_pid}";
        }

        $invoice = [
            'CustomerRef'  => [ 'value' => $customer_ref ],
            'TxnDate'      => current_time( 'Y-m-d' ),
            'CustomerMemo' => [ 'value' => $note_text ],
            'BillAddr'     => $bill_addr,
            'Line'         => [],
            'SalesTermRef' => [ 'value' => '3' ], // Net 60
        ];

        foreach ( $invoice_rows as $row ) {
            $activity    = trim( $row['activity'] ?? '' );
            $description = trim( $row['description'] ?? '' );
            $quantity    = floatval( $row['quantity'] ?? 0 );
            $rate        = floatval( $row['rate'] ?? 0 );
            $amount      = floatval( $row['amount'] ?? ( $quantity * $rate ) );

            if ( $quantity <= 0 && $rate <= 0 && $amount <= 0 && $activity === '' && $description === '' ) {
                DQ_Logger::log( "Skipped blank line in invoice for post {$post_id}.", 'INVOICE' );
                continue;
            }

            $invoice['Line'][] = [
                'DetailType' => 'SalesItemLineDetail',
                'Amount'     => $amount,
                'Description'=> $description ?: $activity,
                'SalesItemLineDetail' => [
                    'ItemRef'   => [ 'value' => '1' ],
                    'Qty'       => $quantity,
                    'UnitPrice' => $rate,
                ],
            ];
        }

        return $invoice;
    }

    /**
     * Always create new invoice
     */
    public function create_invoice_from_workorder() {
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Permission denied.' );

        $post_id = intval( $_GET['post_id'] ?? 0 );
        if ( ! $post_id ) wp_die( 'Missing Work Order ID.' );

        DQ_Logger::log( "Starting invoice creation for Work Order {$post_id}.", 'INVOICE CREATE' );

        $customer = $this->dq_get_fixed_customer_info();
        $customer_ref = get_user_meta( $customer['id'], 'quickbooks_customer_id', true );

        // Create customer if not found
        if ( empty( $customer_ref ) ) {
            DQ_Logger::log( 'Creating fixed QuickBooks customer...', 'CUSTOMER' );
            $create = DQ_API::create_customer([
                'DisplayName' => $customer['name'],
                'PrimaryEmailAddr' => [ 'Address' => $customer['email'] ],
                'BillAddr' => $customer['bill_addr'],
            ]);

            if ( ! is_wp_error( $create ) && ! empty( $create['Customer']['Id'] ) ) {
                $customer_ref = $create['Customer']['Id'];
                update_user_meta( $customer['id'], 'quickbooks_customer_id', $customer_ref );
                DQ_Logger::log( "Customer created successfully. ID={$customer_ref}", 'CUSTOMER' );
            } else {
                $msg = is_wp_error( $create ) ? $create->get_error_message() : 'Unknown error';
                wp_die( 'QuickBooks customer creation failed: ' . esc_html( $msg ) );
            }
        }

        $invoice = $this->dq_build_invoice_payload( $post_id, $customer_ref, $customer['bill_addr'] );
        $result = DQ_API::create_invoice( $invoice );

        if ( is_wp_error( $result ) ) {
            wp_die( 'QuickBooks API error: ' . esc_html( $result->get_error_message() ) );
        }

        if ( empty( $result['Invoice']['Id'] ) ) {
            DQ_Logger::log( 'Invalid create_invoice response: ' . print_r( $result, true ), 'API' );
            wp_die( 'QuickBooks did not return a valid invoice.' );
        }

        $invoice_id = $result['Invoice']['Id'];
        $doc_number = $result['Invoice']['DocNumber'] ?? '';

        if ( function_exists( 'update_field' ) ) {
            update_field( 'wo_invoice_id', $invoice_id, $post_id );
            update_field( 'wo_invoice_no', $doc_number, $post_id );
        }

        DQ_Logger::log( "Invoice {$invoice_id} created successfully for Work Order {$post_id}.", 'INVOICE CREATE' );

        wp_safe_redirect( admin_url( "post.php?post={$post_id}&action=edit&dq_invoice_created=1" ) );
        exit;
    }

    /**
     * Update existing invoice by saved ID
     */
    public function update_invoice_from_workorder() {
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Permission denied.' );

        $post_id = intval( $_GET['post_id'] ?? 0 );
        if ( ! $post_id ) wp_die( 'Missing Work Order ID.' );

        $invoice_id = function_exists( 'get_field' ) ? get_field( 'wo_invoice_id', $post_id ) : '';
        if ( ! $invoice_id ) wp_die( 'No QuickBooks invoice ID found to update.' );

        DQ_Logger::log( "Starting invoice update for Work Order {$post_id}, Invoice {$invoice_id}.", 'INVOICE UPDATE' );

        $existing = DQ_API::get_invoice( $invoice_id );
        if ( is_wp_error( $existing ) ) {
            wp_die( 'Could not fetch invoice: ' . esc_html( $existing->get_error_message() ) );
        }

        if ( empty( $existing['Invoice'] ) ) {
            DQ_Logger::log( "Invoice {$invoice_id} not found in QuickBooks.", 'INVOICE UPDATE' );
            wp_die( 'Could not fetch existing invoice from QuickBooks. Empty response.' );
        }

        $sync_token = isset( $existing['Invoice']['SyncToken'] ) ? (string) $existing['Invoice']['SyncToken'] : null;
        if ( $sync_token === null ) {
            wp_die( 'QuickBooks invoice found but missing SyncToken (cannot update).' );
        }

        DQ_Logger::log( "Fetched invoice {$invoice_id} with SyncToken {$sync_token}.", 'INVOICE UPDATE' );

        $customer = $this->dq_get_fixed_customer_info();
        $customer_ref = get_user_meta( $customer['id'], 'quickbooks_customer_id', true );

        $invoice = $this->dq_build_invoice_payload( $post_id, $customer_ref, $customer['bill_addr'] );
        $invoice['Id'] = (string) $invoice_id;
        $invoice['SyncToken'] = $sync_token;
        $invoice['sparse'] = true;

        $result = DQ_API::update_invoice( $invoice );

        if ( is_wp_error( $result ) ) {
            DQ_Logger::log( 'Update failed: ' . $result->get_error_message(), 'API' );
            wp_die( 'QuickBooks update failed: ' . esc_html( $result->get_error_message() ) );
        }

        if ( empty( $result['Invoice']['Id'] ) ) {
            DQ_Logger::log( 'Invalid update_invoice response: ' . print_r( $result, true ), 'API' );
            wp_die( 'QuickBooks did not return a valid invoice on update. Check logs.' );
        }

        DQ_Logger::log( "Invoice {$invoice_id} updated successfully for Work Order {$post_id}.", 'INVOICE UPDATE' );

        wp_safe_redirect( admin_url( "post.php?post={$post_id}&action=edit&dq_invoice_updated=1" ) );
        exit;
    }
}

new DQ_Invoice();

// Admin Notices
add_action( 'admin_notices', function() {
    if ( isset( $_GET['dq_invoice_created'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Sent new invoice to QuickBooks successfully.</strong></p></div>';
    }
    if ( isset( $_GET['dq_invoice_updated'] ) ) {
        echo '<div class="notice notice-info is-dismissible"><p><strong>🔄 Updated existing QuickBooks invoice successfully.</strong></p></div>';
    }
});
