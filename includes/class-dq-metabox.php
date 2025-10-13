<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_Metabox
 * Version: 4.6.5
 * - Adds Send/Update/Refresh buttons
 * - Updates ACF fields (wo_invoice_id, wo_invoice_no)
 * - Displays and saves QuickBooks invoice totals (Total, Paid, Balance)
 * - Admin notices for Send/Update/Refresh
 * - Refresh button asks for confirmation
 */
class DQ_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_metabox' ] );
        add_action( 'admin_post_dq_send_to_qbo', [ __CLASS__, 'send' ] );
        add_action( 'admin_post_dq_update_qbo', [ __CLASS__, 'update' ] );
        add_action( 'admin_post_dq_refresh_qbo', [ __CLASS__, 'refresh' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
    }

    public static function add_metabox() {
        add_meta_box(
            'dq_quickbooks_box',
            'QuickBooks Integration',
            [ __CLASS__, 'render' ],
            'workorder',
            'side',
            'high'
        );
    }

    public static function render( $post ) {
        $invoice_id  = function_exists('get_field') ? get_field( 'wo_invoice_id', $post->ID ) : get_post_meta( $post->ID, 'wo_invoice_id', true );
        $invoice_no  = function_exists('get_field') ? get_field( 'wo_invoice_no', $post->ID ) : get_post_meta( $post->ID, 'wo_invoice_no', true );

        echo '<p><strong>QuickBooks Invoice</strong><br/>';
        if ( $invoice_id ) echo 'ID: ' . esc_html( $invoice_id ) . '<br/>';
        if ( $invoice_no ) echo 'Number: ' . esc_html( $invoice_no ) . '<br/>';
        if ( ! $invoice_id && ! $invoice_no ) echo '<em>No invoice found yet.</em>';
        echo '</p>';

        // Fetch & display current totals from QuickBooks if ID exists
        if ( $invoice_id ) {
            $invoice_data = DQ_API::get_invoice( $invoice_id );
            if ( ! is_wp_error( $invoice_data ) && ! empty( $invoice_data['Invoice'] ) ) {
                $invoice = $invoice_data['Invoice'];
                $total   = isset( $invoice['TotalAmt'] ) ? floatval( $invoice['TotalAmt'] ) : 0;
                $balance = isset( $invoice['Balance'] ) ? floatval( $invoice['Balance'] ) : 0;
                $paid    = max( $total - $balance, 0 );

                // Save totals into meta/ACF
                update_post_meta( $post->ID, 'wo_total_billed', $total );
                update_post_meta( $post->ID, 'wo_total_paid', $paid );
                update_post_meta( $post->ID, 'wo_balance_due', $balance );

                echo '<hr><p><strong>Invoice Totals</strong><br/>';
                echo 'Total: $'   . number_format( $total, 2 ) . '<br/>';
                echo 'Paid: $'    . number_format( $paid, 2 )  . '<br/>';
                echo 'Balance: $' . number_format( $balance, 2 ) . '</p>';

                DQ_Logger::info( "Fetched QuickBooks totals for invoice #$invoice_id", [
                    'Total' => $total,
                    'Paid' => $paid,
                    'Balance' => $balance,
                ] );
            } else {
                echo '<p><em>Unable to retrieve totals from QuickBooks.</em></p>';
            }
        }

        $send_url    = wp_nonce_url( admin_url( 'admin-post.php?action=dq_send_to_qbo&post=' . $post->ID ), 'dq_send_' . $post->ID );
        $update_url  = wp_nonce_url( admin_url( 'admin-post.php?action=dq_update_qbo&post=' . $post->ID ), 'dq_update_' . $post->ID );
        $refresh_url = wp_nonce_url( admin_url( 'admin-post.php?action=dq_refresh_qbo&post=' . $post->ID ), 'dq_refresh_' . $post->ID );

        echo '<p><a href="' . esc_url( $send_url ) . '" class="button button-primary">Send to QuickBooks</a></p>';
        echo '<p><a href="' . esc_url( $update_url ) . '" class="button">Update QuickBooks</a></p>';

        if ( $invoice_id ) {
            // Confirmation via onclick confirm()
            echo '<p><a href="' . esc_url( $refresh_url ) . '" class="button" onclick="return confirm(`Refresh invoice data from QuickBooks? This will overwrite totals stored on this Work Order.`);">Refresh from QuickBooks</a></p>';
        }
    }

    /** Send to QuickBooks */
    public static function send() {
        if ( empty( $_GET['post'] ) ) wp_die( 'Invalid Work Order.' );
        $post_id = intval( $_GET['post'] );
        check_admin_referer( 'dq_send_' . $post_id );

        $payload = DQ_Invoice::build_from_work_order( $post_id );
        if ( is_wp_error( $payload ) ) wp_die( $payload->get_error_message() );

        $response = DQ_API::create_invoice( $payload );
        if ( is_wp_error( $response ) ) wp_die( 'QuickBooks error: ' . $response->get_error_message() );

        $invoice = isset( $response['Invoice'] ) ? $response['Invoice'] : $response;

        if ( function_exists( 'update_field' ) ) {
            if ( isset( $invoice['Id'] ) ) update_field( 'wo_invoice_id', $invoice['Id'], $post_id );
            if ( isset( $invoice['DocNumber'] ) ) update_field( 'wo_invoice_no', $invoice['DocNumber'], $post_id );
        } else {
            if ( isset( $invoice['Id'] ) ) update_post_meta( $post_id, 'wo_invoice_id', $invoice['Id'] );
            if ( isset( $invoice['DocNumber'] ) ) update_post_meta( $post_id, 'wo_invoice_no', $invoice['DocNumber'] );
        }
        update_post_meta( $post_id, 'wo_last_synced', current_time( 'mysql' ) );

        wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&dq_msg=sent' ) );
        exit;
    }

    /** Update existing QuickBooks invoice */
    public static function update() {
        if ( empty( $_GET['post'] ) ) wp_die( 'Invalid Work Order.' );
        $post_id = intval( $_GET['post'] );
        check_admin_referer( 'dq_update_' . $post_id );

        $invoice_id = function_exists('get_field') ? get_field( 'wo_invoice_id', $post_id ) : get_post_meta( $post_id, 'wo_invoice_id', true );
        if ( ! $invoice_id ) wp_die( 'No QuickBooks Invoice ID found for this Work Order.' );

        $payload = DQ_Invoice::build_from_work_order( $post_id );
        if ( is_wp_error( $payload ) ) wp_die( $payload->get_error_message() );

        $response = DQ_API::update_invoice( $invoice_id, $payload );
        if ( is_wp_error( $response ) ) wp_die( 'QuickBooks error: ' . $response->get_error_message() );

        $invoice = isset( $response['Invoice'] ) ? $response['Invoice'] : $response;

        if ( function_exists( 'update_field' ) ) {
            if ( isset( $invoice['Id'] ) ) update_field( 'wo_invoice_id', $invoice['Id'], $post_id );
            if ( isset( $invoice['DocNumber'] ) ) update_field( 'wo_invoice_no', $invoice['DocNumber'], $post_id );
        } else {
            if ( isset( $invoice['Id'] ) ) update_post_meta( $post_id, 'wo_invoice_id', $invoice['Id'] );
            if ( isset( $invoice['DocNumber'] ) ) update_post_meta( $post_id, 'wo_invoice_no', $invoice['DocNumber'] );
        }
        update_post_meta( $post_id, 'wo_last_synced', current_time( 'mysql' ) );

        wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&dq_msg=updated' ) );
        exit;
    }

    /** Refresh invoice data from QuickBooks (totals + DocNumber) */
    public static function refresh() {
        if ( empty( $_GET['post'] ) ) wp_die( 'Invalid Work Order.' );
        $post_id = intval( $_GET['post'] );
        check_admin_referer( 'dq_refresh_' . $post_id );

        $invoice_id = function_exists('get_field') ? get_field( 'wo_invoice_id', $post_id ) : get_post_meta( $post_id, 'wo_invoice_id', true );
        if ( ! $invoice_id ) wp_die( 'No QuickBooks Invoice ID found for this Work Order.' );

        $invoice_data = DQ_API::get_invoice( $invoice_id );
        if ( is_wp_error( $invoice_data ) ) {
            wp_die( 'QuickBooks error: ' . $invoice_data->get_error_message() );
        }

        if ( ! empty( $invoice_data['Invoice'] ) ) {
            $invoice = $invoice_data['Invoice'];

            // Save DocNumber if present
            if ( isset( $invoice['DocNumber'] ) ) {
                if ( function_exists( 'update_field' ) ) {
                    update_field( 'wo_invoice_no', $invoice['DocNumber'], $post_id );
                } else {
                    update_post_meta( $post_id, 'wo_invoice_no', $invoice['DocNumber'] );
                }
            }

            // Compute totals
            $total   = isset( $invoice['TotalAmt'] ) ? floatval( $invoice['TotalAmt'] ) : 0;
            $balance = isset( $invoice['Balance'] ) ? floatval( $invoice['Balance'] ) : 0;
            $paid    = max( $total - $balance, 0 );

            // Save totals
            update_post_meta( $post_id, 'wo_total_billed', $total );
            update_post_meta( $post_id, 'wo_total_paid', $paid );
            update_post_meta( $post_id, 'wo_balance_due', $balance );
            update_post_meta( $post_id, 'wo_last_synced', current_time( 'mysql' ) );

            DQ_Logger::info( "Refreshed invoice #$invoice_id from QuickBooks", [
                'DocNumber' => $invoice['DocNumber'] ?? null,
                'Total' => $total, 'Paid' => $paid, 'Balance' => $balance
            ] );

            wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&dq_msg=refreshed' ) );
            exit;
        }

        wp_die( 'QuickBooks response did not include an Invoice object.' );
    }

    /** Display admin notices after redirect */
    public static function admin_notices() {
        if ( empty( $_GET['dq_msg'] ) ) return;

        $msg = sanitize_text_field( $_GET['dq_msg'] );
        $messages = [
            'sent'      => '✅ Invoice successfully sent to QuickBooks!',
            'updated'   => '✅ QuickBooks invoice updated successfully!',
            'refreshed' => '✅ Invoice refreshed from QuickBooks successfully!',
            'error'     => '❌ QuickBooks action failed. Check logs for details.',
        ];

        if ( isset( $messages[ $msg ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $messages[ $msg ] ) . '</strong></p></div>';
        }
    }
}
