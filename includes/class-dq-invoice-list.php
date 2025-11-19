<?php
if (!defined('ABSPATH')) exit;

/**
 * Class DQ_Invoice_List
 * Frontend invoice list shortcode with AJAX-powered pagination.
 * Usage: [dqqb_invoice_list status="paid" date_from="2024-01-01" date_to="2024-12-31"]
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
            'date_from' => '',
            'date_to' => '',
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
        
        // Add inline script with AJAX handler
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

        // Date range filter
        if (!empty($atts['date_from']) || !empty($atts['date_to'])) {
            $date_query = [];
            if (!empty($atts['date_from'])) {
                $date_query[] = [
                    'key' => 'qi_invoice_date',
                    'value' => sanitize_text_field($atts['date_from']),
                    'compare' => '>=',
                    'type' => 'DATE',
                ];
            }
            if (!empty($atts['date_to'])) {
                $date_query[] = [
                    'key' => 'qi_invoice_date',
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
        
        $output .= '<div class="dq-invoice-list-content">';
        $output .= self::render_table($data['invoices']);
        $output .= '</div>';
        
        $output .= self::render_pagination($data['current_page'], $data['max_pages']);
        
        $output .= '</div>';

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
.dq-invoice-list-pagination a, .dq-invoice-list-pagination span { display: inline-block; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; background: #fff; min-width: 40px; text-align: center; transition: all 0.2s; }
.dq-invoice-list-pagination a:hover { background: #006d7b; color: #fff; border-color: #006d7b; }
.dq-invoice-list-pagination .current { background: #006d7b; color: #fff; border-color: #006d7b; font-weight: 600; }
.dq-invoice-list-pagination .disabled { opacity: 0.4; pointer-events: none; }
.dq-invoice-list-empty { padding: 40px; text-align: center; color: #666; font-style: italic; background: #f8f9fa; border-radius: 4px; }
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
        $output .= '<th>Date</th>';
        $output .= '<th>Customer</th>';
        $output .= '<th>Amount</th>';
        $output .= '<th>Status</th>';
        $output .= '</tr></thead>';
        $output .= '<tbody>';

        foreach ($invoices as $invoice) {
            $invoice_no = function_exists('get_field')
                ? get_field('qi_invoice_no', $invoice->ID)
                : get_post_meta($invoice->ID, 'qi_invoice_no', true);
            
            $invoice_date = function_exists('get_field')
                ? get_field('qi_invoice_date', $invoice->ID)
                : get_post_meta($invoice->ID, 'qi_invoice_date', true);
            
            $total_billed = function_exists('get_field')
                ? get_field('qi_total_billed', $invoice->ID)
                : get_post_meta($invoice->ID, 'qi_total_billed', true);
            
            $payment_status = function_exists('get_field')
                ? get_field('qi_payment_status', $invoice->ID)
                : get_post_meta($invoice->ID, 'qi_payment_status', true);

            // Get customer name from related work order
            $customer_name = '';
            $wo_relation = function_exists('get_field')
                ? get_field('qi_wo_number', $invoice->ID)
                : get_post_meta($invoice->ID, 'qi_wo_number', true);
            
            if ($wo_relation) {
                $wo_post = null;
                if (is_array($wo_relation)) {
                    $wo_post = reset($wo_relation);
                } elseif ($wo_relation instanceof WP_Post) {
                    $wo_post = $wo_relation;
                } elseif (is_numeric($wo_relation)) {
                    $wo_post = get_post(intval($wo_relation));
                }
                
                if ($wo_post && $wo_post->post_type === 'workorder') {
                    $customer_name = function_exists('get_field')
                        ? get_field('customer_name', $wo_post->ID)
                        : get_post_meta($wo_post->ID, 'customer_name', true);
                }
            }

            $status_class = ($payment_status === 'paid') ? 'paid' : 'unpaid';
            $status_label = ($payment_status === 'paid') ? 'Paid' : 'Unpaid';

            $permalink = get_permalink($invoice->ID);

            $output .= '<tr>';
            $output .= '<td><a href="' . esc_url($permalink) . '">' . esc_html($invoice_no ?: 'N/A') . '</a></td>';
            $output .= '<td>' . esc_html($invoice_date ? date('m/d/Y', strtotime($invoice_date)) : 'N/A') . '</td>';
            $output .= '<td>' . esc_html($customer_name ?: 'N/A') . '</td>';
            $output .= '<td>$' . number_format((float)$total_billed, 2) . '</td>';
            $output .= '<td><span class="dq-invoice-list-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></td>';
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

            wrapper.on('click', '.dq-invoice-list-pagination a:not(.disabled)', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (!page || page < 1) return;

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
                        if (response.success && response.data.table) {
                            wrapper.find('.dq-invoice-list-content').html(response.data.table);
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
            });
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
            'status' => isset($filters['status']) ? sanitize_text_field($filters['status']) : '',
            'date_from' => isset($filters['date_from']) ? sanitize_text_field($filters['date_from']) : '',
            'date_to' => isset($filters['date_to']) ? sanitize_text_field($filters['date_to']) : '',
        ];

        $data = self::get_invoices($atts, $page);

        wp_send_json_success([
            'table' => self::render_table($data['invoices']),
            'pagination' => self::render_pagination($data['current_page'], $data['max_pages']),
        ]);
    }
}
