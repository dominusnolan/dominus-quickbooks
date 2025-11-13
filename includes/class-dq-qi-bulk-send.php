<?php
if (!defined('ABSPATH')) exit;

/**
 * Bulk Send QuickBooks Invoices
 * - Adds admin page for sending all CPT quickbooks_invoice posts to QBO in bulk.
 */
class DQ_QI_Bulk_Send {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_dq_qi_bulk_send', [__CLASS__, 'process_bulk_send']);
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=quickbooks_invoice',
            'Bulk Send Invoices to QuickBooks',
            'Bulk Send to QBO',
            'manage_options',
            'dq-qi-bulk-send',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die('Permission denied');
        $msg = '';
        if (!empty($_GET['msg'])) {
            $msg = wp_kses_post($_GET['msg']);
            echo '<div class="notice notice-info"><p>'.$msg.'</p></div>';
        }
        echo '<div class="wrap"><h1>Bulk Send Invoices to QuickBooks</h1>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('dq_qi_bulk_send', 'dq_qi_bulk_send_nonce');
        echo '<p>This will send all published QuickBooks Invoice posts to QuickBooks Online. This may take a while if you have many invoices.</p>';
        submit_button('Bulk Send All Invoices');
        echo '<input type="hidden" name="action" value="dq_qi_bulk_send">';
        echo '</form></div>';
    }

    public static function process_bulk_send() {
        if (!current_user_can('manage_options')) wp_die('Permission denied');
        check_admin_referer('dq_qi_bulk_send', 'dq_qi_bulk_send_nonce');

        $args = [
            'post_type' => 'quickbooks_invoice',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];
        $query = new WP_Query($args);

        $sent = 0; $skipped = 0; $errors = [];
        foreach ($query->posts as $post_id) {
            // Build invoice payload (match mapping logic from DQ_QI_Sync)
            $payload = DQ_QI_Sync::build_payload_from_cpt($post_id);
            if (is_wp_error($payload)) {
                $skipped++;
                $errors[] = "ID $post_id: ".$payload->get_error_message();
                continue;
            }
            $response = DQ_API::create_invoice($payload);
            if (is_wp_error($response)) {
                $skipped++;
                $errors[] = "ID $post_id: ".$response->get_error_message();
                continue;
            }
            $invoice = $response['Invoice'] ?? $response;
            // Map back to ACF fields just as in DQ_QI_Metabox
            if (function_exists('update_field')) {
                if (!empty($invoice['DocNumber'])) update_field('qi_invoice_no', $invoice['DocNumber'], $post_id);
                if (!empty($invoice['Id'])) update_field('qi_invoice_id', $invoice['Id'], $post_id);
                if (!empty($invoice['TotalAmt'])) update_field('qi_total_billed', $invoice['TotalAmt'], $post_id);
                if (!empty($invoice['Balance'])) update_field('qi_balance_due', $invoice['Balance'], $post_id);
                if (isset($invoice['TotalAmt']) && isset($invoice['Balance'])) {
                    $paid = max($invoice['TotalAmt'] - $invoice['Balance'], 0);
                    update_field('qi_total_paid', $paid, $post_id);
                    update_field('qi_payment_status', ($invoice['Balance'] > 0 ? 'UNPAID' : 'PAID'), $post_id);
                }
                update_field('qi_last_synced', current_time('mysql'), $post_id);
                if (!empty($invoice['BillAddr'])) update_field('qi_bill_to', dq_format_address($invoice['BillAddr']), $post_id);
                if (!empty($invoice['ShipAddr'])) update_field('qi_ship_to', dq_format_address($invoice['ShipAddr']), $post_id);
                if (!empty($invoice['TxnDate'])) update_field('qi_invoice_date', $invoice['TxnDate'], $post_id);
                if (!empty($invoice['DueDate'])) update_field('qi_due_date', $invoice['DueDate'], $post_id);
                if (!empty($invoice['SalesTermRef'])) {
                    update_field('qi_terms', isset($invoice['SalesTermRef']['name']) ? $invoice['SalesTermRef']['name'] : $invoice['SalesTermRef']['value'], $post_id);
                }
            } else {
                update_post_meta($post_id, 'qi_invoice_no', $invoice['DocNumber'] ?? '');
                update_post_meta($post_id, 'qi_invoice_id', $invoice['Id'] ?? '');
                update_post_meta($post_id, 'qi_total_billed', $invoice['TotalAmt'] ?? '');
                update_post_meta($post_id, 'qi_balance_due', $invoice['Balance'] ?? '');
                update_post_meta($post_id, 'qi_total_paid', isset($invoice['TotalAmt']) && isset($invoice['Balance']) ? max($invoice['TotalAmt']-$invoice['Balance'], 0) : '');
                update_post_meta($post_id, 'qi_payment_status', isset($invoice['Balance']) && $invoice['Balance'] > 0 ? 'UNPAID' : 'PAID');
                update_post_meta($post_id, 'qi_last_synced', current_time('mysql'));
                update_post_meta($post_id, 'qi_bill_to', !empty($invoice['BillAddr']) ? dq_format_address($invoice['BillAddr']) : '');
                update_post_meta($post_id, 'qi_ship_to', !empty($invoice['ShipAddr']) ? dq_format_address($invoice['ShipAddr']) : '');
                update_post_meta($post_id, 'qi_invoice_date', $invoice['TxnDate'] ?? '');
                update_post_meta($post_id, 'qi_due_date', $invoice['DueDate'] ?? '');
                update_post_meta($post_id, 'qi_terms', !empty($invoice['SalesTermRef']) ? (isset($invoice['SalesTermRef']['name']) ? $invoice['SalesTermRef']['name'] : $invoice['SalesTermRef']['value']) : '');
            }
            $sent++;
        }

        // Show results
        $msg = "Sent $sent invoice(s) to QuickBooks. Skipped $skipped. ";
        if ($errors) $msg .= 'Errors:<br/>' . implode('<br/>', array_map('esc_html', $errors));
        wp_safe_redirect(add_query_arg(['page'=>'dq-qi-bulk-send','msg'=>$msg], admin_url('edit.php?post_type=quickbooks_invoice')));
        exit;
    }
}

if (is_admin()) DQ_QI_Bulk_Send::init();