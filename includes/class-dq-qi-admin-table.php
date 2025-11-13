<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds and customizes the admin columns for CPT quickbooks_invoice.
 * Reference: image1.png.
 */
class DQ_QI_Admin_Table {

    public static function init() {
        add_filter('manage_edit-quickbooks_invoice_columns', [__CLASS__, 'columns']);
        add_action('manage_quickbooks_invoice_posts_custom_column', [__CLASS__, 'column_content'], 10, 2);
        add_filter('manage_edit-quickbooks_invoice_sortable_columns', [__CLASS__, 'sortable']);
    }

    /**
     * Setup columns
     */
    public static function columns($columns) {
        // Remove the default title and replace with Invoice No.
        unset($columns['title']);
        $new = [];
        // Invoice No. goes first
        $new['invoice_no']  = __('Invoice No.', 'dqqb');
        $new['work_order']  = __('Work Order', 'dqqb');
        $new['amount']      = __('Amount', 'dqqb');
        $new['qbo_invoice'] = __('QBO Invoice', 'dqqb');
        $new['customer']    = __('Customer', 'dqqb');
        $new['invoice_date'] = __('Invoice Date', 'dqqb');
        $new['due_date']    = __('Invoice Due Date', 'dqqb');
        // Add Status and Date at the end (retain defaults)
        $new['status']      = $columns['status'] ?? 'Status';
        $new['date']        = $columns['date'];
        return $new;
    }

    /**
     * Populate columns
     */
    public static function column_content($column, $post_id) {
        switch($column) {
            case 'invoice_no':
                // Use post title as Invoice No; clickable to edit
                $title = get_the_title($post_id);
                $edit_url = get_edit_post_link($post_id);
                echo '<a href="'.esc_url($edit_url).'"><strong>'.esc_html($title).'</strong></a>';
                break;

            case 'work_order':
                // Get qi_wo_number field, link to Work Order(s)
                $wo_ids = function_exists('get_field') ? get_field('qi_wo_number', $post_id) : get_post_meta($post_id, 'qi_wo_number', true);
                if (!is_array($wo_ids)) $wo_ids = $wo_ids ? [$wo_ids] : [];
                $links = [];
                foreach ($wo_ids as $wo) {
                    if ($wo instanceof WP_Post) {
                        $wo_id = $wo->ID;
                    } else {
                        $wo_id = intval(is_array($wo) && isset($wo['ID']) ? $wo['ID'] : $wo);
                    }
                    $url = get_edit_post_link($wo_id);
                    $label = get_the_title($wo_id);
                    if ($wo_id && $label && $url) {
                        $links[] = '<a href="'.esc_url($url).'">'.esc_html($label).'</a>';
                    }
                }
                echo $links ? implode(', ', $links) : '<span style="color:#999;">—</span>';
                break;

            case 'amount':
                // qi_total_billed
                $amount = function_exists('get_field') ? get_field('qi_total_billed', $post_id) : get_post_meta($post_id, 'qi_total_billed', true);
                echo $amount !== '' ? '$' . number_format((float)$amount, 2) : '<span style="color:#999;">—</span>';
                break;

            case 'qbo_invoice':
                $billed  = function_exists('get_field') ? get_field('qi_total_billed', $post_id) : get_post_meta($post_id, 'qi_total_billed', true);
                $balance = function_exists('get_field') ? get_field('qi_balance_due', $post_id) : get_post_meta($post_id, 'qi_balance_due', true);
                $paid    = function_exists('get_field') ? get_field('qi_total_paid', $post_id) : get_post_meta($post_id, 'qi_total_paid', true);
                $terms   = function_exists('get_field') ? get_field('qi_terms', $post_id) : get_post_meta($post_id, 'qi_terms', true);
                $status  = function_exists('get_field') ? get_field('qi_payment_status', $post_id) : get_post_meta($post_id, 'qi_payment_status', true);
                $status_label = '';
                if (strtolower($status) === 'paid') {
                    $status_label = '<span style="background:#e7f6ec;color:#22863a;padding:2px 10px;border-radius:3px;font-weight:bold;">PAID</span>';
                } elseif (strtolower($status) === 'unpaid') {
                    $status_label = '<span style="background:#fbeaea;color:#d63638;padding:2px 10px;border-radius:3px;font-weight:bold;">UNPAID</span>';
                }
                echo
                    '<div style="line-height:1.5;">' .
                    '<strong>Billed:</strong> $' . number_format((float)$billed,2) . '<br>' .
                    '<strong>Balance:</strong> $' . number_format((float)$balance,2) . '<br>' .
                    '<strong>Paid:</strong> $' . number_format((float)$paid,2) . '<br>' .
                    '<strong>Terms:</strong> ' . esc_html($terms) . '<br>' .
                    '<strong>Status:</strong> ' . $status_label .
                    '</div>';
                break;

            case 'customer':
                $customer = function_exists('get_field') ? get_field('qi_customer', $post_id) : get_post_meta($post_id, 'qi_customer', true);
                $billto   = function_exists('get_field') ? get_field('qi_bill_to', $post_id) : get_post_meta($post_id, 'qi_bill_to', true);
                $shipto   = function_exists('get_field') ? get_field('qi_ship_to', $post_id) : get_post_meta($post_id, 'qi_ship_to', true);

                echo
                    '<div style="line-height:1.5;">' .
                    '<strong>Customer:</strong> ' . esc_html((string)$customer) . '<br>' .
                    '<strong>Bill to:</strong> ' . esc_html((string)$billto) . '<br>' .
                    '<strong>Ship to:</strong> ' . esc_html((string)$shipto) .
                    '</div>';
                break;

            case 'invoice_date':
                $date = function_exists('get_field') ? get_field('qi_invoice_date', $post_id) : get_post_meta($post_id, 'qi_invoice_date', true);
                echo $date ? esc_html($date) : '<span style="color:#999;">—</span>';
                break;

            case 'due_date':
                $date = function_exists('get_field') ? get_field('qi_due_date', $post_id) : get_post_meta($post_id, 'qi_due_date', true);
                echo $date ? esc_html($date) : '<span style="color:#999;">—</span>';
                break;

            default:
                // For status and date, use default rendering
                break;
        }
    }

    /**
     * Make Invoice No. sortable (sorts by title), and Amount sortable.
     */
    public static function sortable($sortable_columns) {
        $sortable_columns['invoice_no'] = 'title';
        $sortable_columns['amount']     = 'qi_total_billed';
        $sortable_columns['invoice_date'] = 'qi_invoice_date';
        $sortable_columns['due_date']   = 'qi_due_date';
        return $sortable_columns;
    }
}
// Activate
add_action('init', array('DQ_QI_Admin_Table', 'init'));