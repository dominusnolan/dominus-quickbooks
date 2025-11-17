<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds admin columns and custom filters for CPT quickbooks_invoice.
 * Now supports Invoice/Due Date range filtering.
 */
class DQ_QI_Admin_Table {

    public static function init() {
        add_filter('manage_edit-quickbooks_invoice_columns', [__CLASS__, 'columns']);
        add_action('manage_quickbooks_invoice_posts_custom_column', [__CLASS__, 'column_content'], 10, 2);
        add_filter('manage_edit-quickbooks_invoice_sortable_columns', [__CLASS__, 'sortable']);
        add_action('restrict_manage_posts', [__CLASS__, 'filters']);
        add_filter('parse_query', [__CLASS__, 'filter_query']);
        add_filter('manage_quickbooks_invoice_sortable_columns', [__CLASS__, 'sortable']);
    }

    /**
     * Setup columns (removes date/status/categories)
     */
    public static function columns($columns) {
        unset($columns['title']);
        $new = [];
        $new['invoice_no']  = __('Invoice No.', 'dqqb');
        $new['work_order']  = __('Work Order', 'dqqb');
        $new['amount']      = __('Amount', 'dqqb');
        $new['qbo_invoice'] = __('QBO Invoice', 'dqqb');
        $new['customer']    = __('Customer', 'dqqb');
        $new['invoice_date'] = __('Invoice Date', 'dqqb');
        $new['due_date']    = __('Invoice Due Date', 'dqqb');
        $new['days_remaining'] = __('Days Remaining', 'dqqb');
        return $new;
    }

    /**
     * Populate columns
     */
    public static function column_content($column, $post_id) {
        switch($column) {
            case 'invoice_no':
                $title = get_the_title($post_id);
                $edit_url = get_edit_post_link($post_id);
                echo '<a href="'.esc_url($edit_url).'"><strong>'.esc_html($title).'</strong></a>';
                break;
            case 'work_order':
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
            case 'days_remaining':
                $status = function_exists('get_field') ? get_field('qi_payment_status', $post_id) : get_post_meta($post_id, 'qi_payment_status', true);
                $due_date = function_exists('get_field') ? get_field('qi_due_date', $post_id) : get_post_meta($post_id, 'qi_due_date', true);
                
                // Only show days remaining for UNPAID invoices with a due date
                if (strtoupper($status) === 'UNPAID' && !empty($due_date)) {
                    $today = new DateTime('now', new DateTimeZone('UTC'));
                    $due = DateTime::createFromFormat('Y-m-d', $due_date, new DateTimeZone('UTC'));
                    
                    if ($due !== false) {
                        $diff = $today->diff($due);
                        $days = (int)$diff->format('%r%a'); // %r adds sign, %a is days
                        
                        if ($days < 0) {
                            $abs_days = abs($days);
                            $tooltip = sprintf('Overdue by %d day%s', $abs_days, $abs_days !== 1 ? 's' : '');
                            echo '<span style="color:#d63638;" title="' . esc_attr($tooltip) . '">' . esc_html($days) . '</span>';
                        } else {
                            echo esc_html($days);
                        }
                    } else {
                        echo '<span style="color:#999;">—</span>';
                    }
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;
        }
    }

    public static function sortable($sortable_columns) {
        $sortable_columns['invoice_no'] = 'title';
        $sortable_columns['amount']     = 'qi_total_billed';
        $sortable_columns['invoice_date'] = 'qi_invoice_date';
        $sortable_columns['due_date']   = 'qi_due_date';
        $sortable_columns['days_remaining'] = 'qi_due_date'; // Sort by due date as proxy
        return $sortable_columns;
    }

    /**
     * Add custom filters to the admin table. 
     * Invoice/Due Date are now FROM/TO range fields with labels.
     */
    public static function filters() {
    global $typenow;
    if ($typenow != 'quickbooks_invoice') return;
    echo '<style>
        select[name="m"], select[name="cat"] {display:none !important;}
        .dqqb-filter-label {font-weight: 500; font-size: 13px; margin-right: 4px;}
        .dqqb-filter-sep {margin: 0 4px;}
        .dqqb-date-range {display:inline-block;}
    </style>';
    // Payment Status
    $pstat = isset($_GET['qi_payment_status']) ? $_GET['qi_payment_status'] : '';
    echo '<select name="qi_payment_status" style="margin-right:8px;"><option value="">Payment Status...</option><option value="Paid" '.selected($pstat, 'Paid', false).'>Paid</option><option value="Unpaid" '.selected($pstat, 'Unpaid', false).'>Unpaid</option></select>';

    // Date FIELD dropdown
    $date_field = isset($_GET['qi_date_field']) ? $_GET['qi_date_field'] : 'invoice_date';
    echo '<select name="qi_date_field" id="dqqb-date-field-select" style="margin-right:8px;">';
    echo '<option value="invoice_date"'.selected($date_field, 'invoice_date', false).'>Invoice Date</option>';
    echo '<option value="due_date"'.selected($date_field, 'due_date', false).'>Due Date</option>';
    echo '</select>';

    // Date ranges
    $inqd_from = isset($_GET['qi_invoice_date_from']) ? $_GET['qi_invoice_date_from'] : '';
    $inqd_to = isset($_GET['qi_invoice_date_to']) ? $_GET['qi_invoice_date_to'] : '';
    $dud_from = isset($_GET['qi_due_date_from']) ? $_GET['qi_due_date_from'] : '';
    $dud_to = isset($_GET['qi_due_date_to']) ? $_GET['qi_due_date_to'] : '';

    // Only show relevant date range input
    echo '<span id="dqqb-date-invoice" class="dqqb-date-range" style="'.($date_field=='invoice_date'?'':'display:none').'">';
    echo '<label for="dqqb-invoice-date-from" class="dqqb-filter-label">Invoice Date:</label>';
    echo '<input type="date" name="qi_invoice_date_from" id="dqqb-invoice-date-from" value="' . esc_attr($inqd_from) . '" placeholder="mm/dd/yyyy" />';
    echo '<span class="dqqb-filter-sep">–</span>';
    echo '<input type="date" name="qi_invoice_date_to" id="dqqb-invoice-date-to" value="' . esc_attr($inqd_to) . '" placeholder="mm/dd/yyyy" style="margin-right:8px;" />';
    echo '</span>';
    echo '<span id="dqqb-date-due" class="dqqb-date-range" style="'.($date_field=='due_date'?'':'display:none').'">';
    echo '<label for="dqqb-due-date-from" class="dqqb-filter-label">Due Date:</label>';
    echo '<input type="date" name="qi_due_date_from" id="dqqb-due-date-from" value="' . esc_attr($dud_from) . '" placeholder="mm/dd/yyyy" />';
    echo '<span class="dqqb-filter-sep">–</span>';
    echo '<input type="date" name="qi_due_date_to" id="dqqb-due-date-to" value="' . esc_attr($dud_to) . '" placeholder="mm/dd/yyyy" style="margin-right:8px;" />';
    echo '</span>';

    // JS: switch visible date range
    echo <<<JS
<script>
document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('dqqb-date-field-select');
    var invoice = document.getElementById('dqqb-date-invoice');
    var due = document.getElementById('dqqb-date-due');
    if(select && invoice && due) {
        select.addEventListener('change', function() {
            invoice.style.display = select.value === 'invoice_date' ? 'inline-block' : 'none';
            due.style.display = select.value === 'due_date' ? 'inline-block' : 'none';
        });
    }
});
</script>
JS;
}

    /**
     * Filtering logic to allow date range for invoice/due date.
     */
    public static function filter_query($query) {
        global $pagenow;
        if (!is_admin() || $pagenow != 'edit.php') return;
        $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : '';
        if ($post_type != 'quickbooks_invoice') return;
    
        $meta_queries = [];
        // Payment status
        if (!empty($_GET['qi_payment_status'])) {
            $val = esc_sql($_GET['qi_payment_status']);
            $meta_queries[] = [
                'key'=>'qi_payment_status',
                'value'=>$val,
                'compare'=>'='
            ];
        }
        // ONLY one date field active
        $date_field = isset($_GET['qi_date_field']) ? $_GET['qi_date_field'] : 'invoice_date';
        if ($date_field == 'invoice_date') {
            $from = !empty($_GET['qi_invoice_date_from']) ? $_GET['qi_invoice_date_from'] : '';
            $to = !empty($_GET['qi_invoice_date_to']) ? $_GET['qi_invoice_date_to'] : '';
            if ($from && $to) {
                $meta_queries[] = [
                    'key' => 'qi_invoice_date',
                    'value' => [$from, $to],
                    'type' => 'DATE',
                    'compare' => 'BETWEEN'
                ];
            } elseif ($from) {
                $meta_queries[] = [
                    'key' => 'qi_invoice_date',
                    'value' => $from,
                    'type' => 'DATE',
                    'compare' => '>='
                ];
            } elseif ($to) {
                $meta_queries[] = [
                    'key' => 'qi_invoice_date',
                    'value' => $to,
                    'type' => 'DATE',
                    'compare' => '<='
                ];
            }
        } elseif ($date_field == 'due_date') {
            $from = !empty($_GET['qi_due_date_from']) ? $_GET['qi_due_date_from'] : '';
            $to = !empty($_GET['qi_due_date_to']) ? $_GET['qi_due_date_to'] : '';
            if ($from && $to) {
                $meta_queries[] = [
                    'key' => 'qi_due_date',
                    'value' => [$from, $to],
                    'type' => 'DATE',
                    'compare' => 'BETWEEN'
                ];
            } elseif ($from) {
                $meta_queries[] = [
                    'key' => 'qi_due_date',
                    'value' => $from,
                    'type' => 'DATE',
                    'compare' => '>='
                ];
            } elseif ($to) {
                $meta_queries[] = [
                    'key' => 'qi_due_date',
                    'value' => $to,
                    'type' => 'DATE',
                    'compare' => '<='
                ];
            }
        }
        if (count($meta_queries) == 1) {
            $query->set('meta_query', $meta_queries);
        } elseif (count($meta_queries) > 1) {
            $query->set('meta_query', ['relation' => 'AND'] + $meta_queries);
        }
    }
}

add_action('init', array('DQ_QI_Admin_Table', 'init'));