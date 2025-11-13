<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds admin columns and custom filters for CPT quickbooks_invoice.
 */
class DQ_QI_Admin_Table {

    public static function init() {
        add_filter('manage_edit-quickbooks_invoice_columns', [__CLASS__, 'columns']);
        add_action('manage_quickbooks_invoice_posts_custom_column', [__CLASS__, 'column_content'], 10, 2);
        add_filter('manage_edit-quickbooks_invoice_sortable_columns', [__CLASS__, 'sortable']);

        // Remove default filters and add our own
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

    /**
     * Remove default filters and add custom ones.
     */
    public static function filters() {
        global $typenow;
        if ($typenow != 'quickbooks_invoice') return;
        // Remove default "All Categories" and "All Dates" via CSS hack (WordPress doesn't provide a hook)
        echo '<style>
            select[name="m"], select[name="cat"] {display:none !important;}
            .dqqb-filter-label {
                font-weight: 500;
                font-size: 13px;
                margin-right: 4px;
            }
        </style>';
        // Month (qi_invoice_date)
        $month_filter_name = 'qi_invoice_month';
        $selected = isset($_GET[$month_filter_name]) ? $_GET[$month_filter_name] : '';
        global $wpdb;
        $months = $wpdb->get_col("SELECT DISTINCT SUBSTRING(meta_value,1,7) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id=p.ID WHERE pm.meta_key='qi_invoice_date' AND p.post_type='quickbooks_invoice' AND pm.meta_value<>'' ORDER BY meta_value DESC");
        echo '<select name="' . esc_attr($month_filter_name) . '" style="margin-right:8px;"><option value="">Month...</option>';
        foreach ($months as $m) {
            $y_m = explode('-', $m);
            if (count($y_m) == 2) {
                $txt = date('F Y', strtotime($m . '-01'));
                echo '<option value="' . esc_attr($m) . '" ' . selected($selected, $m, false) . '>' . esc_html($txt) . '</option>';
            }
        }
        echo '</select>';
        // Payment Status
        $pstat = isset($_GET['qi_payment_status']) ? $_GET['qi_payment_status'] : '';
        echo '<select name="qi_payment_status" style="margin-right:8px;"><option value="">Payment Status...</option><option value="Paid" '.selected($pstat,'Paid',false).'>Paid</option><option value="Unpaid" '.selected($pstat,'Unpaid',false).'>Unpaid</option></select>';
        // Invoice Date LABEL + Input
        $inqd = isset($_GET['qi_invoice_date']) ? $_GET['qi_invoice_date'] : '';
        echo '<label for="dqqb-invoice-date-filter" class="dqqb-filter-label">Invoice Date:</label> ';
        echo '<input type="date" name="qi_invoice_date" id="dqqb-invoice-date-filter" value="' . esc_attr($inqd) . '" placeholder="mm/dd/yyyy" style="margin-right:8px;" />';
        // Due Date LABEL + Input
        $dud = isset($_GET['qi_due_date']) ? $_GET['qi_due_date'] : '';
        echo '<label for="dqqb-due-date-filter" class="dqqb-filter-label">Due Date:</label> ';
        echo '<input type="date" name="qi_due_date" id="dqqb-due-date-filter" value="' . esc_attr($dud) . '" placeholder="mm/dd/yyyy" style="margin-right:8px;" />';
    }

    /**
     * Applies filtering logic to the query vars.
     */
    public static function filter_query($query) {
        global $pagenow;
        if (!is_admin() || $pagenow != 'edit.php') return;
        $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : '';
        if ($post_type != 'quickbooks_invoice') return;

        // Filter by month
        if (!empty($_GET['qi_invoice_month'])) {
            $prefix = esc_sql($_GET['qi_invoice_month']);
            $meta_query = [
                'key'   => 'qi_invoice_date',
                'value' => $prefix,
                'compare'=>'LIKE'
            ];
            $query->set('meta_query', [$meta_query]);
        }

        // Filter by invoice date
        if (!empty($_GET['qi_invoice_date'])) {
            $val = esc_sql($_GET['qi_invoice_date']);
            $meta_query = [
                'key'=>'qi_invoice_date',
                'value'=>$val,
                'compare'=>'='
            ];
            $query->set('meta_query', [$meta_query]);
        }

        // Filter by due date
        if (!empty($_GET['qi_due_date'])) {
            $val = esc_sql($_GET['qi_due_date']);
            $meta_query = [
                'key'=>'qi_due_date',
                'value'=>$val,
                'compare'=>'='
            ];
            $query->set('meta_query', [$meta_query]);
        }

        // Filter by payment status
        if (!empty($_GET['qi_payment_status'])) {
            $val = esc_sql($_GET['qi_payment_status']);
            $meta_query = [
                'key'=>'qi_payment_status',
                'value'=>$val,
                'compare'=>'='
            ];
            $query->set('meta_query', [$meta_query]);
        }

        // Merge meta queries if more than one is present
        $meta_queries = [];
        // All handled via $_GET above, just merge those that are in use
        foreach (['qi_invoice_month','qi_invoice_date','qi_due_date','qi_payment_status'] as $f) {
            if (!empty($_GET[$f])) {
                if ($f=='qi_invoice_month') {
                    $meta_queries[] = ['key'=>'qi_invoice_date','value'=>esc_sql($_GET[$f]),'compare'=>'LIKE'];
                } else {
                    $meta_queries[] = ['key'=>str_replace('qi_', '', $f),'value'=>esc_sql($_GET[$f]),'compare'=>'='];
                }
            }
        }
        if (count($meta_queries)>1) {
            $query->set('meta_query', $meta_queries);
        }
    }
}

add_action('init', array('DQ_QI_Admin_Table', 'init'));