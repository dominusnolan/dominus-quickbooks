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

    public static function init()
    {
        add_shortcode('dqqb_invoice_list', [__CLASS__, 'render_shortcode']);
        add_action('wp_ajax_dq_invoice_list_paginate', [__CLASS__, 'ajax_paginate']);
        add_action('wp_ajax_nopriv_dq_invoice_list_paginate', [__CLASS__, 'ajax_paginate']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_scripts']);
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
            'unpaid_only' => '', // <-- NEW attribute
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
        $args = [
            'post_type' => 'quickbooks_invoice',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => self::PER_PAGE,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        // Meta query for filters
        $meta_query = ['relation' => 'AND'];

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
        $output = '<div id="' . esc_attr($wrapper_id) . '" class="dq-invoice-list-wrapper">';
        $output .= self::get_styles();

        $output .= self::render_filter_form($atts, $wrapper_id);

        $output .= '<div class="dq-invoice-list-content">';
        $output .= self::render_table($data['invoices']);
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

        // These select options map to meta keys.
        $date_type_options = [
            'qi_invoice_date' => 'Invoice Date',
            'qi_due_date' => 'Due Date',
        ];

        $output = '<form class="dq-invoice-list-filter" data-wrapper="' . esc_attr($wrapper_id) . '" style="margin-bottom:20px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">';

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
.dq-invoice-list-filter select, .dq-invoice-list-filter input[type="date"] { font-size:13px; padding:6px; }
.dq-invoice-list-filter select{margin: 0 !important}
</style>';
    }

    private static function render_table($invoices)
    {
        if (empty($invoices)) {
            return '<div class="dq-invoice-list-empty">No invoices found.</div>';
        }

        $output = '<table class="dq-invoice-list-table">';
        $output .= '<thead><tr>';
        $output .= '<th>Invoice #</th>';
        $output .= '<th>Workorder ID</th>';
        $output .= '<th>Amount</th>';
        $output .= '<th>QBO Invoice</th>';
        $output .= '<th>Customer</th>';
        $output .= '<th>Invoice Date</th>';
        $output .= '<th>Due Date</th>';
        $output .= '<th>Days Remaining</th>';
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
            // Properly display Workorder ID
            $wo_display = 'N/A';
            if (is_array($wo_number)) {
                $id_list = [];
                foreach ($wo_number as $wo_item) {
                    if (is_numeric($wo_item)) {
                        $id_list[] = $wo_item;
                    } elseif ($wo_item instanceof WP_Post) {
                        $id_list[] = $wo_item->ID;
                    }
                }
                if (!empty($id_list)) {
                    $wo_display = implode(', ', $id_list);
                }
            } elseif ($wo_number instanceof WP_Post) {
                $wo_display = $wo_number->ID;
            } elseif (!empty($wo_number)) {
                $wo_display = $wo_number;
            }
            $output .= '<td>' . esc_html($wo_display) . '</td>';
            $output .= '<td>$' . number_format((float)$total_billed, 2) . '</td>';
            $output .= '<td>' . $qbo_invoice_html . '</td>';
            $output .= '<td>' . $customer_html . '</td>';
            $output .= '<td>' . esc_html($invoice_date ? date('m/d/Y', strtotime($invoice_date)) : 'N/A') . '</td>';
            $output .= '<td>' . esc_html($due_date ? date('m/d/Y', strtotime($due_date)) : 'N/A') . '</td>';
            // Only display Days Remaining when unpaid; show dash otherwise
            $output .= '<td>' . ($payment_status !== 'PAID' ? esc_html($days_remaining ?: 'N/A') : '-') . '</td>';
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

        $script = "
        (function($) {
            var wrapper = $('#" . esc_js($wrapper_id) . "');
            var filters = " . json_encode($atts) . ";

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

            function getFilters() {
                var form = wrapper.find('.dq-invoice-list-filter');
                var filters = {};
                form.find('select, input').each(function() {
                    var name = $(this).attr('name');
                    var val = $(this).val();
                    filters[name] = val;
                });
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

        // Sanitize filters
        $atts = [
            'status'    => isset($filters['status']) ? sanitize_text_field($filters['status']) : '',
            'date_type' => isset($filters['date_type']) ? sanitize_text_field($filters['date_type']) : 'qi_invoice_date',
            'date_from' => isset($filters['date_from']) ? sanitize_text_field($filters['date_from']) : '',
            'date_to'   => isset($filters['date_to']) ? sanitize_text_field($filters['date_to']) : '',
        ];

        $data = self::get_invoices($atts, $page);

        wp_send_json_success([
            'filter_form' => self::render_filter_form($atts, $_POST['wrapper_id'] ?? 'dq-invoice-list-1'),
            'table' => self::render_table($data['invoices']),
            'pagination' => self::render_pagination($data['current_page'], $data['max_pages']),
        ]);
    }
}