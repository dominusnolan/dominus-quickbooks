<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Metabox + actions for CPT quickbooks_invoice
 * Buttons:
 * - Update QuickBooks (push CPT -> QBO by DocNumber)
 * - Pull from QuickBooks (pull QBO -> CPT by DocNumber)
 */
class DQ_QI_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_metabox' ] );
        add_action( 'admin_post_dq_qi_update_qbo', [ __CLASS__, 'update' ] );
        add_action( 'admin_post_dq_qi_pull_from_qb', [ __CLASS__, 'pull' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
    }

    public static function add_metabox() {
        add_meta_box(
            'dq_qi_quickbooks_box',
            'QuickBooks Invoice Sync',
            [ __CLASS__, 'render' ],
            'quickbooks_invoice',
            'side',
            'high'
        );
    }

    public static function render( $post ) {
        $invoice_id = function_exists('get_field') ? get_field('qi_invoice_id', $post->ID) : get_post_meta($post->ID, 'qi_invoice_id', true);
        $invoice_no = function_exists('get_field') ? get_field('qi_invoice_no', $post->ID) : get_post_meta($post->ID, 'qi_invoice_no', true);

        $total = $paid = $balance = 0.0;
        $status = '';
        $invoice_date = $due_date = $terms = '';
        $bill_to = $ship_to = '';

        echo '<div style="padding:6px;font-size:13px;">';

        echo '<div style="margin-bottom:10px;">';
        echo '<h4 style="margin:0 0 8px;border-bottom:1px solid #ddd;">Invoice Info</h4>';

        echo '<p style="margin:4px 0;"><strong>No:</strong> <input type="text" readonly value="' . esc_attr( (string)$invoice_no ) . '" style="width:100%;"></p>';
        echo '<p style="margin:4px 0;"><strong>ID:</strong> <input type="text" readonly value="' . esc_attr( (string)$invoice_id ) . '" style="width:100%;"></p>';

        if ( $invoice_id ) {
            // Try pull current totals quickly
            $data = DQ_API::get_invoice( $invoice_id );
            if ( ! is_wp_error($data) && ! empty($data['Invoice']) ) {
                $inv = $data['Invoice'];
                $total   = isset($inv['TotalAmt']) ? (float)$inv['TotalAmt'] : 0;
                $balance = isset($inv['Balance']) ? (float)$inv['Balance'] : 0;
                $paid    = max($total - $balance, 0);
                $status  = ($balance <= 0 && $total > 0) ? 'PAID' : 'UNPAID';

                if (!empty($inv['TxnDate'])) $invoice_date = (string)$inv['TxnDate'];
                if (!empty($inv['DueDate'])) $due_date     = (string)$inv['DueDate'];
                if (!empty($inv['SalesTermRef'])) {
                    $terms = isset($inv['SalesTermRef']['name']) ? $inv['SalesTermRef']['name'] : $inv['SalesTermRef']['value'];
                }
                if (!empty($inv['BillAddr'])) {
                    $bill_to = dq_format_address($inv['BillAddr']);
                }
                if (!empty($inv['ShipAddr'])) {
                    $ship_to = dq_format_address($inv['ShipAddr']);
                }
            }
        }

        echo '</div>';

        echo '<div style="margin-bottom:10px;">';
        echo '<h4 style="margin:0 0 8px;border-bottom:1px solid #ddd;">Totals</h4>';
        echo '<p style="margin:3px 0;"><strong>Total:</strong> $'   . number_format( $total, 2 ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Paid:</strong> $'    . number_format( $paid, 2 )  . '</p>';
        echo '<p style="margin:3px 0;"><strong>Balance:</strong> $' . number_format( $balance, 2 ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Status:</strong> ' . esc_html( $status ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Invoice Date:</strong> ' . esc_html( $invoice_date ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Due Date:</strong> ' . esc_html( $due_date ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Terms:</strong> ' . esc_html( $terms ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Bill To:</strong> ' . esc_html( $bill_to ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Ship To:</strong> ' . esc_html( $ship_to ) . '</p>';
        echo '</div>';

        // Buttons
        $upd = wp_nonce_url( admin_url( 'admin-post.php?action=dq_qi_update_qbo&post=' . $post->ID ), 'dq_qi_update_' . $post->ID );
        $pul = wp_nonce_url( admin_url( 'admin-post.php?action=dq_qi_pull_from_qb&post=' . $post->ID ), 'dq_qi_pull_' . $post->ID );

        echo '<p><a href="' . esc_url($upd) . '" class="button button-primary" style="width:100%;">Update QuickBooks</a></p>';
        echo '<p><a href="' . esc_url($pul) . '" class="button button-secondary" style="width:100%;">Pull from QuickBooks</a></p>';

        echo '</div>';
    }

    public static function update() {
        if ( empty($_GET['post']) ) wp_die('Invalid post');
        $post_id = absint($_GET['post']);
        check_admin_referer( 'dq_qi_update_' . $post_id );

        // Get DocNumber
        $docnum = function_exists('get_field') ? get_field('qi_invoice_no', $post_id) : get_post_meta($post_id, 'qi_invoice_no', true);
        if ( ! $docnum ) wp_die('No qi_invoice_no found.');

        // Find QBO invoice by DocNumber (used for QBO ID)
        $raw = DQ_API::get_invoice_by_docnumber( $docnum );
        if ( is_wp_error($raw) ) wp_die( 'QuickBooks error: ' . $raw->get_error_message() );
        $invoice = (isset($raw['QueryResponse']['Invoice'][0]) && is_array($raw['QueryResponse']['Invoice'][0]))
            ? $raw['QueryResponse']['Invoice'][0]
            : [];
        if ( empty($invoice['Id']) ) wp_die('QuickBooks invoice not found by DocNumber.');

        // Build update payload from CPT ACF fields (this is what you want to push to QBO!)
        $payload = DQ_QI_Sync::build_payload_from_cpt( $post_id );
        if ( is_wp_error($payload) ) wp_die( $payload->get_error_message() );

        // Push update to QBO: CPT -> QBO
        $resp = DQ_API::update_invoice( $invoice['Id'], $payload );
        if ( is_wp_error($resp) ) wp_die( 'QuickBooks error: ' . $resp->get_error_message() );

        // Only update CPT QBO IDs, do NOT overwrite your CPT values with QBO financials
        $inv = $resp['Invoice'] ?? $resp;
        if (function_exists('update_field')) {
            if (!empty($inv['Id']))        update_field('qi_invoice_id', (string)$inv['Id'], $post_id);
            if (!empty($inv['DocNumber'])) update_field('qi_invoice_no', (string)$inv['DocNumber'], $post_id);
            // Do NOT update qi_balance_due, qi_total_paid, qi_invoice_date, qi_due_date here!
        } else {
            if (!empty($inv['Id']))        update_post_meta($post_id, 'qi_invoice_id', (string)$inv['Id']);
            if (!empty($inv['DocNumber'])) update_post_meta($post_id, 'qi_invoice_no', (string)$inv['DocNumber']);
        }
        update_post_meta( $post_id, 'qi_last_synced', current_time('mysql') );

        if (function_exists('dqqb_sync_invoice_number_to_workorders')) {
            dqqb_sync_invoice_number_to_workorders($post_id);
        }
        wp_safe_redirect( add_query_arg([
            'post' => $post_id,
            'action' => 'edit',
            'qi_msg' => 'updated',
        ], admin_url('post.php') ) );
        exit;
    }

    public static function pull() {
        if ( empty($_GET['post']) ) wp_die('Invalid post');
        $post_id = absint($_GET['post']);
        check_admin_referer( 'dq_qi_pull_' . $post_id );

        $res = DQ_QI_Sync::pull_from_qbo( $post_id );
        if ( is_wp_error($res) ) {
            wp_safe_redirect( add_query_arg([
                'post' => $post_id, 'action' => 'edit', 'qi_msg' => 'error:' . $res->get_error_message()
            ], admin_url('post.php') ) );
        } else {
            wp_safe_redirect( add_query_arg([
                'post' => $post_id, 'action' => 'edit', 'qi_msg' => 'pulled'
            ], admin_url('post.php') ) );
        }
        exit;
    }

    public static function admin_notices() {
        if ( empty($_GET['qi_msg']) ) return;
        $msg = (string) wp_unslash($_GET['qi_msg']);
        if ( strpos($msg, 'error:') === 0 ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html( substr($msg, 6) ) . '</strong></p></div>';
            return;
        }
        $labels = [
            'updated' => '✅ QuickBooks invoice updated successfully.',
            'pulled'  => '✅ Pulled invoice data from QuickBooks.',
        ];
        if ( isset($labels[$msg]) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $labels[$msg] ) . '</strong></p></div>';
        }
    }
}