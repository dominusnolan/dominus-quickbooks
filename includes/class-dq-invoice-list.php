<?php
if (!defined('ABSPATH')) exit;

/**
 * Class DQ_Invoice_List
 * Frontend invoice list shortcode with AJAX-powered pagination + filter UI.
 * Usage: [dqqb_invoice_list]
 */
class DQ_Invoice_List
{
    const PER_PAGE = 25;
    const PER_PAGE_MIN = 1;
    const PER_PAGE_MAX = 100;
    const DEFAULT_WRAPPER_ID = 'dq-invoice-list-1';

    public static function init()
    {
        add_shortcode('dqqb_invoice_list', [__CLASS__, 'render_shortcode']);
        add_shortcode('dqqb_unpaid_invoices', [__CLASS__, 'render_unpaid_shortcode']);
        add_action('wp_ajax_dq_invoice_list_paginate', [__CLASS__, 'ajax_paginate']);
        add_action('wp_ajax_nopriv_dq_invoice_list_paginate', [__CLASS__, 'ajax_paginate']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_scripts']);
    }

    /**
     * Shortcode for unpaid invoices - same as dqqb_invoice_list with unpaid_only="true"
     */
    public static function render_unpaid_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : [];
        $atts['unpaid_only'] = 'true';
        return self::render_shortcode($atts);
    }

    public static function register_scripts()
    {
        wp_register_script(
            'dq-invoice-list',
            false,
            ['jquery'],
            DQQB_VERSION,
            true
        );
    }

    public static function render_shortcode($atts)
    {
        $atts = shortcode_atts([
            'status' => '', // paid, unpaid, or empty for all
            'date_type' => 'qi_invoice_date', // Default to invoice date
            'date_from' => '',
            'date_to' => '',
            'unpaid_only' => '', // When 'true', only show invoices with qi_balance_due > 0
            'per_page' => '', // Optional: override default per_page
            'invoice_no' => '', // Search by invoice number
            'sort_column' => '', // Column to sort by
            'sort_direction' => '', // Sort direction (ASC/DESC)
        ], $atts, 'dqqb_invoice_list');

        // Enqueue scripts
        wp_enqueue_script('dq-invoice-list');
        
        // Generate unique ID for this shortcode instance
        static $instance = 0;
        $instance++;
        $wrapper_id = 'dq-invoice-list-' . $instance;

        // Get initial page data
        $page = 1;
        $data = self::get_invoices($atts, $page);

        $output = self::render_html($data, $atts, $wrapper_id);

        // Add inline script with AJAX handler and filter handler
        self::enqueue_inline_script($wrapper_id, $atts);

        return $output;
    }

    private static function get_invoices($atts, $page = 1)
    {
        // Support per_page attribute, default to self::PER_PAGE
        $per_page = !empty($atts['per_page']) ? intval($atts['per_page']) : self::PER_PAGE;
        if ($per_page < self::PER_PAGE_MIN || $per_page > self::PER_PAGE_MAX) {
            $per_page = self::PER_PAGE;
        }

        $args = [
            'post_type' => 'quickbooks_invoice',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        // Handle sorting
        $sort_column = !empty($atts['sort_column']) ? sanitize_text_field($atts['sort_column']) : '';
        $sort_direction = !empty($atts['sort_direction']) && in_array(strtoupper($atts['sort_direction']), ['ASC', 'DESC']) ? strtoupper($atts['sort_direction']) : 'DESC';

        $sortable_columns = [
            'qi_invoice_no' => 'qi_invoice_no',
            'qi_invoice_date' => 'qi_invoice_date',
            'qi_due_date' => 'qi_due_date',
            'days_remaining' => 'qi_due_date', // Sort by due date for days remaining
        ];

        if (!empty($sort_column) && isset($sortable_columns[$sort_column])) {
            $meta_key = $sortable_columns[$sort_column];
            $args['meta_key'] = $meta_key;
            $args['order'] = $sort_direction;
            
            // Use appropriate orderby type based on field type
            if (in_array($sort_column, ['qi_invoice_date', 'qi_due_date', 'days_remaining'])) {
                $args['orderby'] = 'meta_value';
                $args['meta_type'] = 'DATE';
            } else {
                $args['orderby'] = 'meta_value';
            }
        }

        // Meta query for filters
        $meta_query = ['relation' => 'AND'];

        // Search by invoice number (partial match using LIKE)
        if (!empty($atts['invoice_no'])) {
            $meta_query[] = [
                'key' => 'qi_invoice_no',
                'value' => sanitize_text_field($atts['invoice_no']),
                'compare' => 'LIKE',
            ];
        }

        // If unpaid_only is true, remove status filter and just show invoices with positive balance
        if (!empty($atts['unpaid_only']) && $atts['unpaid_only'] === 'true') {
            $meta_query[] = [
                'key' => 'qi_balance_due',
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ];
        } else {
            // Status filter (paid/unpaid)
            if (!empty($atts['status'])) {
                $status = sanitize_text_field($atts['status']);
                if ($status === 'paid') {
                    $meta_query[] = [
                        'key' => 'qi_payment_status',
                        'value' => 'paid',
                        'compare' => '=',
                    ];
                } elseif ($status === 'unpaid') {
                    $meta_query[] = [
                        'relation' => 'OR',
                        [
                            'key' => 'qi_payment_status',
                            'value' => 'paid',
                            'compare' => '!=',
                        ],
                        [
                            'key' => 'qi_payment_status',
                            'compare' => 'NOT EXISTS',
                        ],
                    ];
                }
            }
        }

        // Date range filter (depending on selected type)
        $date_key = !empty($atts['date_type']) ? sanitize_text_field($atts['date_type']) : 'qi_invoice_date';
        if (!empty($atts['date_from']) || !empty($atts['date_to'])) {
            $date_query = [];
            if (!empty($atts['date_from'])) {
                $date_query[] = [
                    'key' => $date_key,
                    'value' => sanitize_text_field($atts['date_from']),
                    'compare' => '>=',
                    'type' => 'DATE',
                ];
            }
            if (!empty($atts['date_to'])) {
                $date_query[] = [
                    'key' => $date_key,
                    'value' => sanitize_text_field($atts['date_to']),
                    'compare' => '<=',
                    'type' => 'DATE',
                ];
            }
            if (!empty($date_query)) {
                $meta_query = array_merge($meta_query, $date_query);
            }
        }

        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }

        $query = new WP_Query($args);

        return [
            'invoices' => $query->posts,
            'total' => $query->found_posts,
            'max_pages' => $query->max_num_pages,
            'current_page' => $page,
        ];
    }

    private static function render_html($data, $atts, $wrapper_id)
    {
        $sort_column = isset($atts['sort_column']) ? $atts['sort_column'] : '';
        $sort_direction = isset($atts['sort_direction']) ? $atts['sort_direction'] : '';

        $output = '<div id="' . esc_attr($wrapper_id) . '" class="dq-invoice-list-wrapper">';
        $output .= self::get_styles();

        $output .= self::render_filter_form($atts, $wrapper_id);

        $output .= '<div class="dq-invoice-list-content">';
        $output .= self::render_table($data['invoices'], $sort_column, $sort_direction);
        $output .= '</div>';

        $output .= self::render_pagination($data['current_page'], $data['max_pages']);

        $output .= '</div>';

        return $output;
    }

    private static function render_filter_form($atts, $wrapper_id)
    {
        $status = isset($atts['status']) ? $atts['status'] : '';
        $date_type = isset($atts['date_type']) ? $atts['date_type'] : 'qi_invoice_date';
        $date_from = isset($atts['date_from']) ? $atts['date_from'] : '';
        $date_to = isset($atts['date_to']) ? $atts['date_to'] : '';
        $invoice_no = isset($atts['invoice_no']) ? $atts['invoice_no'] : '';
        $sort_column = isset($atts['sort_column']) ? $atts['sort_column'] : '';
        $sort_direction = isset($atts['sort_direction']) ? $atts['sort_direction'] : '';

        // These select options map to meta keys.
        $date_type_options = [
            'qi_invoice_date' => 'Invoice Date',
            'qi_due_date' => 'Due Date',
        ];

        $output = '<form class="dq-invoice-list-filter" data-wrapper="' . esc_attr($wrapper_id) . '" style="margin-bottom:20px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">';

        // Hidden fields for sorting
        $output .= '<input type="hidden" name="sort_column" value="' . esc_attr($sort_column) . '">';
        $output .= '<input type="hidden" name="sort_direction" value="' . esc_attr($sort_direction) . '">';

        // Invoice number search field
        $output .= '<div><label>Invoice #<br>
            <input type="text" style="margin-bottom:0" name="invoice_no" value="' . esc_attr($invoice_no) . '" placeholder="Search..." style="width:120px;"></label></div>';

        if (empty($atts['unpaid_only']) ) {    
            $output .= '<div><label>Status<br>
                <select name="status">
                    <option value="">All</option>
                    <option value="paid"' . ($status == 'paid' ? ' selected' : '') . '>Paid</option>
                    <option value="unpaid"' . ($status == 'unpaid' ? ' selected' : '') . '>Unpaid</option>
                </select>
            </label></div>';
        }
        $output .= '<div><label>Date Type<br>
            <select name="date_type">';
        foreach($date_type_options as $key => $label) {
            $output .= '<option value="' . esc_attr($key) . '"' . ($date_type == $key ? ' selected' : '') . '>' . esc_html($label) . '</option>';
        }
        $output .= '</select></label></div>';
        $output .= '<div><label>From Date<br>
            <input type="date" name="date_from" value="' . esc_attr($date_from) . '"></label></div>';
        $output .= '<div><label>To Date<br>
            <input type="date" name="date_to" value="' . esc_attr($date_to) . '"></label></div>';
        $output .= '<div><button type="submit" style="padding:8px 24px;">Filter</button></div>';
        $output .= '</form>';
        return $output;
    }

    private static function get_styles()
    {
        return '<style>
.dq-invoice-list-wrapper { margin: 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.dq-invoice-list-wrapper.loading { opacity: 0.6; pointer-events: none; }
.dq-invoice-list-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.dq-invoice-list-table th { background: #006d7b; color: #fff; padding: 12px 10px; text-align: left; font-weight: 600; font-size: 14px; }
.dq-invoice-list-table th.sortable { cursor: pointer; user-select: none; }
.dq-invoice-list-table th.sortable:hover { background: #005a66; }
.dq-invoice-list-table th.sortable .sort-arrow { margin-left: 5px; font-size: 12px; opacity: 0.5; }
.dq-invoice-list-table th.sortable.active .sort-arrow { opacity: 1; }
.dq-invoice-list-table td { padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle; font-size: 14px; }
.dq-invoice-list-table tr:hover td { background: #f8f9fa; }
.dq-invoice-list-table tr:last-child td { border-bottom: none; }
.dq-invoice-list-status { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
.dq-invoice-list-status.paid { background: #d4edda; color: #155724; }
.dq-invoice-list-status.unpaid { background: #fff3cd; color: #856404; }
.dq-invoice-list-pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
.dq-invoice-list-pagination a, .dq-invoice-list-pagination span { display: inline-block; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; background: #fff; }
.dq-invoice-list-pagination a:hover { background: #006d7b; color: #fff; border-color: #006d7b; }
.dq-invoice-list-pagination .current { background: #006d7b; color: #fff; border-color: #006d7b; font-weight: 600; }
.dq-invoice-list-pagination .disabled { opacity: 0.4; pointer-events: none; }
.dq-invoice-list-empty { padding: 40px; text-align: center; color: #666; font-style: italic; background: #f8f9fa; border-radius: 4px; }
.dq-invoice-list-qbo { font-size: 13px; line-height: 1.6; }
.dq-invoice-list-customer { font-size: 13px; line-height: 1.6; }
.dq-invoice-list-filter select, .dq-invoice-list-filter input[type="date"], .dq-invoice-list-filter input[type="text"] { font-size:13px; padding:6px; }
.dq-invoice-list-filter select{margin: 0 !important}
.dq-invoice-view-btn { display: inline-block; background: #006d7b; color: #fff !important; padding: 6px 16px; border-radius: 5px; text-decoration: none; font-size: 14px; }
.dq-invoice-view-btn:hover { background: #005a66; }
</style>';
    }

    private static function render_table($invoices, $sort_column = '', $sort_direction = '')
    {
        if (empty($invoices)) {
            return '<div class="dq-invoice-list-empty">No invoices found.</div>';
        }

        // Helper function to render sortable header
        $render_sortable_th = function($column_key, $label) use ($sort_column, $sort_direction) {
            $is_active = ($sort_column === $column_key);
            $active_class = $is_active ? ' active' : '';
            $arrow = '';
            if ($is_active) {
                $arrow = ($sort_direction === 'ASC') ? '▲' : '▼';
            } else {
                $arrow = '⇅'; // Neutral indicator for inactive sortable columns
            }
            return '<th class="sortable' . $active_class . '" data-sort="' . esc_attr($column_key) . '">' . esc_html($label) . '<span class="sort-arrow">' . $arrow . '</span></th>';
        };

        $output = '<table class="dq-invoice-list-table">';
        $output .= '<thead><tr>';
        $output .= $render_sortable_th('qi_invoice_no', 'Invoice #');
        $output .= '<th>Amount</th>';
        $output .= '<th>QBO Invoice</th>';
        $output .= '<th>Customer</th>';
        $output .= $render_sortable_th('qi_invoice_date', 'Invoice Date');
        $output .= $render_sortable_th('qi_due_date', 'Due Date');
        $output .= $render_sortable_th('days_remaining', 'Remain');
        $output .= '<th>Action</th>';
        $output .= '</tr></thead>';
        $output .= '<tbody>';

        foreach ($invoices as $invoice) {
            $invoice_no     = function_exists('get_field') ? get_field('qi_invoice_no', $invoice->ID) : get_post_meta($invoice->ID, 'qi_invoice_no', true);
            $wo_number      = function_exists('get_field') ? get_field('qi_wo_number', $invoice->ID) : get_post_meta($invoice->ID, 'qi_wo_number', true);
            $total_billed   = function_exists('get_field') ? get_field('qi_total_billed', $invoice->ID) : get_post_meta($invoice->ID, 'qi_total_billed', true);
            $balance_due    = function_exists('get_field') ? get_field('qi_balance_due', $invoice->ID) : get_post_meta($invoice->ID, 'qi_balance_due', true);
            $total_paid     = function_exists('get_field') ? get_field('qi_total_paid', $invoice->ID) : get_post_meta($invoice->ID, 'qi_total_paid', true);
            $terms          = function_exists('get_field') ? get_field('qi_terms', $invoice->ID) : get_post_meta($invoice->ID, 'qi_terms', true);
            if( $balance_due > 0 ){
                $payment_status = 'UNPAID';
            }else{
                $payment_status = 'PAID';
            }
            
            $customer       = function_exists('get_field') ? get_field('qi_customer', $invoice->ID) : get_post_meta($invoice->ID, 'qi_customer', true);
            $bill_to        = function_exists('get_field') ? get_field('qi_bill_to', $invoice->ID) : get_post_meta($invoice->ID, 'qi_bill_to', true);
            $ship_to        = function_exists('get_field') ? get_field('qi_ship_to', $invoice->ID) : get_post_meta($invoice->ID, 'qi_ship_to', true);
            $invoice_date   = function_exists('get_field') ? get_field('qi_invoice_date', $invoice->ID) : get_post_meta($invoice->ID, 'qi_invoice_date', true);
            $due_date       = function_exists('get_field') ? get_field('qi_due_date', $invoice->ID) : get_post_meta($invoice->ID, 'qi_due_date', true);

            // Calculate Days Remaining ONLY for UNPAID status
            $days_remaining = '';
            if ($payment_status !== 'PAID' && $due_date) {
                $now = new DateTime(date('Y-m-d'));
                $due = new DateTime($due_date);
                $interval = $now->diff($due);
                $days_remaining = $interval->format('%r%a');
            }

            $status_class = ($payment_status === 'PAID') ? 'paid' : 'unpaid';
            $status_label = ($payment_status === 'PAID') ? 'PAID' : 'UNPAID';

            $permalink = get_permalink($invoice->ID);

            // Combined QBO Invoice column
            $qbo_invoice_html = '<div class="dq-invoice-list-qbo">';
            $qbo_invoice_html .= '<strong>Billed:</strong> $' . number_format((float)$total_billed, 2) . '<br>';
            $qbo_invoice_html .= '<strong>Balance:</strong> $' . number_format((float)$balance_due, 2) . '<br>';
            $qbo_invoice_html .= '<strong>Paid:</strong> $' . number_format((float)$total_paid, 2) . '<br>';
            $qbo_invoice_html .= '<strong>Terms:</strong> ' . esc_html($terms ?: 'N/A') . '<br>';
            $qbo_invoice_html .= '<strong>Status:</strong> <span class="dq-invoice-list-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';
            $qbo_invoice_html .= '</div>';

            // Combined Customer column
            $customer_html = '<div class="dq-invoice-list-customer">';
            $customer_html .= '<strong>Customer:</strong> ' . esc_html($customer ?: 'N/A') . '<br>';
            $customer_html .= '<strong>Bill to:</strong> ' . esc_html($bill_to ?: 'N/A') . '<br>';
            $customer_html .= '<strong>Ship to:</strong> ' . esc_html($ship_to ?: 'N/A');
            $customer_html .= '</div>';

            $output .= '<tr>';
            $output .= '<td><a href="' . esc_url($permalink) . '">' . esc_html($invoice_no ?: 'N/A') . '</a></td>';
            $output .= '<td>$' . number_format((float)$total_billed, 2) . '</td>';
            $output .= '<td>' . $qbo_invoice_html . '</td>';
            $output .= '<td>' . $customer_html . '</td>';
            $output .= '<td>' . esc_html($invoice_date ? wp_date('m/d/Y', dqqb_parse_date_for_comparison($invoice_date)) : 'N/A') . '</td>';
            $output .= '<td>' . esc_html($due_date ? wp_date('m/d/Y', dqqb_parse_date_for_comparison($due_date)) : 'N/A') . '</td>';
            // Only display Days Remaining when unpaid; show dash otherwise
            $output .= '<td>' . ($payment_status !== 'PAID' ? esc_html($days_remaining ?: 'N/A') : '-') . '</td>';
            $output .= '<td><a href="' . esc_url($permalink) . '" class="dq-invoice-view-btn" target="_blank" rel="noopener noreferrer" aria-label="View invoice ' . esc_attr($invoice_no ?: $invoice->ID) . ' (opens in new tab)">View</a></td>';
            $output .= '</tr>';
        }

        $output .= '</tbody></table>';
        return $output;
    }

    private static function render_pagination($current_page, $max_pages)
    {
        if ($max_pages <= 1) {
            return '';
        }

        $output = '<div class="dq-invoice-list-pagination">';

        // Previous button
        $prev_class = ($current_page <= 1) ? 'disabled' : '';
        $output .= '<a href="#" class="dq-invoice-list-prev ' . $prev_class . '" data-page="' . ($current_page - 1) . '">« Previous</a>';

        // Page numbers
        $range = 2; // Show 2 pages on each side of current
        $start = max(1, $current_page - $range);
        $end = min($max_pages, $current_page + $range);

        // First page + ellipsis
        if ($start > 1) {
            $output .= '<a href="#" data-page="1">1</a>';
            if ($start > 2) {
                $output .= '<span class="ellipsis">...</span>';
            }
        }

        // Page numbers
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $current_page) {
                $output .= '<span class="current">' . $i . '</span>';
            } else {
                $output .= '<a href="#" data-page="' . $i . '">' . $i . '</a>';
            }
        }

        // Last page + ellipsis
        if ($end < $max_pages) {
            if ($end < $max_pages - 1) {
                $output .= '<span class="ellipsis">...</span>';
            }
            $output .= '<a href="#" data-page="' . $max_pages . '">' . $max_pages . '</a>';
        }

        // Next button
        $next_class = ($current_page >= $max_pages) ? 'disabled' : '';
        $output .= '<a href="#" class="dq-invoice-list-next ' . $next_class . '" data-page="' . ($current_page + 1) . '">Next »</a>';

        $output .= '</div>';
        return $output;
    }

    private static function enqueue_inline_script($wrapper_id, $atts)
    {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('dq_invoice_list_nonce');

        // Prepare shortcode-level attributes that must persist across AJAX
        $shortcode_atts = [
            'unpaid_only' => isset($atts['unpaid_only']) ? $atts['unpaid_only'] : '',
            'per_page' => isset($atts['per_page']) ? $atts['per_page'] : '',
        ];

        $script = "
        (function($) {
            var wrapper = $('#" . esc_js($wrapper_id) . "');
            var shortcodeAtts = " . wp_json_encode($shortcode_atts) . ";

            // Pagination click
            wrapper.on('click', '.dq-invoice-list-pagination a:not(.disabled)', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (!page || page < 1) return;
                loadInvoices(page, getFilters());
            });

            // Filter form submit
            wrapper.on('submit', '.dq-invoice-list-filter', function(e) {
                e.preventDefault();
                loadInvoices(1, getFilters());
            });

            // Sortable column header click
            wrapper.on('click', '.dq-invoice-list-table th.sortable', function(e) {
                e.preventDefault();
                var column = $(this).data('sort');
                var form = wrapper.find('.dq-invoice-list-filter');
                var currentColumn = form.find('input[name=\"sort_column\"]').val();
                var currentDirection = form.find('input[name=\"sort_direction\"]').val();

                // Toggle direction if same column, otherwise default to DESC
                var newDirection = 'DESC';
                if (column === currentColumn) {
                    newDirection = (currentDirection === 'DESC') ? 'ASC' : 'DESC';
                }

                // Update hidden fields
                form.find('input[name=\"sort_column\"]').val(column);
                form.find('input[name=\"sort_direction\"]').val(newDirection);

                // Reload with sort (go to page 1)
                loadInvoices(1, getFilters());
            });

            function getFilters() {
                var form = wrapper.find('.dq-invoice-list-filter');
                var filters = {};
                form.find('select, input').each(function() {
                    var name = $(this).attr('name');
                    var val = $(this).val();
                    filters[name] = val;
                });
                // Merge in shortcode-level attributes
                filters.unpaid_only = shortcodeAtts.unpaid_only;
                filters.per_page = shortcodeAtts.per_page;
                return filters;
            }

            function loadInvoices(page, filters) {
                wrapper.addClass('loading');
                $.ajax({
                    url: '" . esc_js($ajax_url) . "',
                    type: 'POST',
                    data: {
                        action: 'dq_invoice_list_paginate',
                        nonce: '" . esc_js($nonce) . "',
                        page: page,
                        wrapper_id: '" . esc_js($wrapper_id) . "',
                        filters: filters
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            if (response.data.filter_form) {
                                wrapper.find('.dq-invoice-list-filter').replaceWith(response.data.filter_form);
                            }
                            if (response.data.table) {
                                wrapper.find('.dq-invoice-list-content').html(response.data.table);
                            }
                            var existingPagination = wrapper.find('.dq-invoice-list-pagination');
                            if (response.data.pagination) {
                                if (existingPagination.length) {
                                    existingPagination.replaceWith(response.data.pagination);
                                } else {
                                    wrapper.append(response.data.pagination);
                                }
                            } else {
                                existingPagination.remove();
                            }
                            $('html, body').animate({
                                scrollTop: wrapper.offset().top - 100
                            }, 300);
                        }
                    },
                    complete: function() {
                        wrapper.removeClass('loading');
                    }
                });
            }
        })(jQuery);
        ";

        wp_add_inline_script('dq-invoice-list', $script);
    }

    public static function ajax_paginate()
    {
        check_ajax_referer('dq_invoice_list_nonce', 'nonce');

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $filters = isset($_POST['filters']) ? $_POST['filters'] : [];
        $wrapper_id = isset($_POST['wrapper_id']) ? sanitize_text_field($_POST['wrapper_id']) : self::DEFAULT_WRAPPER_ID;

        // Sanitize filters
        $atts = [
            'status'     => isset($filters['status']) ? sanitize_text_field($filters['status']) : '',
            'date_type'  => isset($filters['date_type']) ? sanitize_text_field($filters['date_type']) : 'qi_invoice_date',
            'date_from'  => isset($filters['date_from']) ? sanitize_text_field($filters['date_from']) : '',
            'date_to'    => isset($filters['date_to']) ? sanitize_text_field($filters['date_to']) : '',
            'unpaid_only'=> isset($filters['unpaid_only']) ? sanitize_text_field($filters['unpaid_only']) : '',
            'per_page'   => isset($filters['per_page']) ? sanitize_text_field($filters['per_page']) : '',
            'invoice_no' => isset($filters['invoice_no']) ? sanitize_text_field($filters['invoice_no']) : '',
            'sort_column' => isset($filters['sort_column']) ? sanitize_text_field($filters['sort_column']) : '',
            'sort_direction' => isset($filters['sort_direction']) ? sanitize_text_field($filters['sort_direction']) : '',
        ];

        $data = self::get_invoices($atts, $page);

        $sort_column = isset($atts['sort_column']) ? $atts['sort_column'] : '';
        $sort_direction = isset($atts['sort_direction']) ? $atts['sort_direction'] : '';

        wp_send_json_success([
            'filter_form' => self::render_filter_form($atts, $wrapper_id),
            'table' => self::render_table($data['invoices'], $sort_column, $sort_direction),
            'pagination' => self::render_pagination($data['current_page'], $data['max_pages']),
        ]);
    }
}