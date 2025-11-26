<?php
if (!defined('ABSPATH')) exit;

/**
 * Class DQ_Workorder_Table
 * Frontend workorder table shortcode with AJAX-powered pagination.
 * Usage: [workorder_table] or [workorder_table per_page="20"]
 */
class DQ_Workorder_Table
{
    const DEFAULT_PER_PAGE = 10;

    public static function init()
    {
        add_shortcode('workorder_table', [__CLASS__, 'render_shortcode']);
        add_action('wp_ajax_dq_workorder_table_paginate', [__CLASS__, 'ajax_paginate']);
        add_action('wp_ajax_nopriv_dq_workorder_table_paginate', [__CLASS__, 'ajax_paginate']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_scripts']);
    }

    public static function register_scripts()
    {
        wp_register_script(
            'dq-workorder-table',
            false,
            ['jquery'],
            DQQB_VERSION,
            true
        );
    }

    public static function render_shortcode($atts)
    {
        $atts = shortcode_atts([
            'per_page' => self::DEFAULT_PER_PAGE,
            'status' => '', // Filter by status taxonomy (open, close, scheduled)
            'state' => '', // Filter by wo_state meta field
        ], $atts, 'workorder_table');

        // Sanitize per_page
        $atts['per_page'] = max(1, intval($atts['per_page']));

        // Enqueue scripts
        wp_enqueue_script('dq-workorder-table');

        // Generate unique ID for this shortcode instance
        static $instance = 0;
        $instance++;
        $wrapper_id = 'dq-workorder-table-' . $instance;

        // Get initial page data
        $page = 1;
        $data = self::get_workorders($atts, $page);

        $output = self::render_html($data, $atts, $wrapper_id);

        // Add inline script with AJAX handler
        self::enqueue_inline_script($wrapper_id, $atts);

        return $output;
    }

    private static function get_workorders($atts, $page = 1)
    {
        $per_page = isset($atts['per_page']) ? max(1, intval($atts['per_page'])) : self::DEFAULT_PER_PAGE;

        $args = [
            'post_type' => 'workorder',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        // Meta query for filters
        $meta_query = ['relation' => 'AND'];

        // State filter
        if (!empty($atts['state'])) {
            $meta_query[] = [
                'key' => 'wo_state',
                'value' => sanitize_text_field($atts['state']),
                'compare' => '=',
            ];
        }

        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }

        // Taxonomy filter for status
        if (!empty($atts['status'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'status',
                    'field' => 'slug',
                    'terms' => sanitize_text_field($atts['status']),
                ],
            ];
        }

        $query = new WP_Query($args);

        return [
            'workorders' => $query->posts,
            'total' => $query->found_posts,
            'max_pages' => $query->max_num_pages,
            'current_page' => $page,
            'per_page' => $per_page,
        ];
    }

    private static function render_html($data, $atts, $wrapper_id)
    {
        $output = '<div id="' . esc_attr($wrapper_id) . '" class="dq-workorder-table-wrapper">';
        $output .= self::get_styles();

        $output .= '<div class="dq-scroll-hint">
    <svg width="54" height="32">
       <!-- simple right arrow with "scroll" text -->
        <text x="4" y="24" font-size="14" fill="#0996a0">Scroll →</text>
        <line x1="35" y1="16" x2="52" y2="16" stroke="#0996a0" stroke-width="2"/>
        <polygon points="52,12 54,16 52,20" fill="#0996a0" />
    </svg>
</div>';

        $output .= '<div class="dq-workorder-table-content">';
        $output .= self::render_table($data['workorders']);
        $output .= '</div>';

        $output .= self::render_pagination($data['current_page'], $data['max_pages']);

        $output .= '</div>';

        return $output;
    }

    private static function get_styles()
    {
        return '<style>
.dq-workorder-table-wrapper { 
    margin: 20px 0; 
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
}
.dq-workorder-table-wrapper.loading { 
    opacity: 0.6; 
    pointer-events: none; 
}
.dq-workorder-table { 
    width: 100%; 
    border-collapse: collapse; 
    background: #fff; 
    box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
    border-radius: 8px;
    overflow: hidden;
}
.dq-workorder-table th { 
    background: #0996a0; 
    color: #fff; 
    padding: 14px 12px; 
    text-align: left; 
    font-weight: 600; 
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.dq-workorder-table td { 
    padding: 12px; 
    border-bottom: 1px solid #e8e8e8; 
    vertical-align: middle; 
    font-size: 14px; 
}
.dq-workorder-table tbody tr:nth-child(even) td { 
    background: #f8fafb; 
}
.dq-workorder-table tbody tr:hover td { 
    background: #e5f4f6; 
}
.dq-workorder-table tr:last-child td { 
    border-bottom: none; 
}
.dq-workorder-table .wo-id-cell {
    font-weight: 700;
    color: #0996a0;
}
.dq-workorder-table .wo-id-cell a {
    color: #0996a0;
    text-decoration: none;
}
.dq-workorder-table .wo-id-cell a:hover {
    text-decoration: underline;
    color: #067a82;
}
.dq-workorder-table .wo-customer-cell {
    font-weight: 600;
    color: #333;
}
.dq-workorder-table .wo-grouped-cell {
    font-size: 13px;
    line-height: 1.6;
    color: #444;
}
.dq-workorder-table .wo-grouped-cell strong {
    color: #333;
    font-weight: 600;
}
.dq-workorder-status { 
    display: inline-block; 
    padding: 4px 12px; 
    border-radius: 4px; 
    font-size: 11px; 
    font-weight: 700; 
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.dq-workorder-status.status-open { 
    background: #fff3cd; 
    color: #856404; 
}
.dq-workorder-status.status-scheduled { 
    background: #cce5ff; 
    color: #004085; 
}
.dq-workorder-status.status-close,
.dq-workorder-status.status-closed { 
    background: #d4edda; 
    color: #155724; 
}
.dq-workorder-table-pagination { 
    display: flex; 
    justify-content: center; 
    align-items: center; 
    gap: 8px; 
    margin-top: 20px; 
    flex-wrap: wrap; 
}
.dq-workorder-table-pagination a, 
.dq-workorder-table-pagination span { 
    display: inline-block; 
    padding: 8px 14px; 
    border: 1px solid #ddd; 
    border-radius: 4px; 
    text-decoration: none; 
    color: #333; 
    background: #fff;
    font-size: 14px;
    transition: all 0.2s ease;
}
.dq-workorder-table-pagination a:hover { 
    background: #0996a0; 
    color: #fff; 
    border-color: #0996a0; 
}
.dq-workorder-table-pagination .current { 
    background: #0996a0; 
    color: #fff; 
    border-color: #0996a0; 
    font-weight: 600; 
}
.dq-workorder-table-pagination .disabled { 
    opacity: 0.4; 
    pointer-events: none; 
}
.dq-workorder-table-empty { 
    padding: 40px; 
    text-align: center; 
    color: #666; 
    font-style: italic; 
    background: #f8f9fa; 
    border-radius: 8px; 
}

/* Responsive Styles */
@media (max-width: 768px) {
    .dq-workorder-table { 
        display: block; 
        overflow-x: auto; 
        -webkit-overflow-scrolling: touch;
    }
    .dq-workorder-table th,
    .dq-workorder-table td {
        padding: 10px 8px;
        font-size: 13px;
    }
    .dq-workorder-table .wo-grouped-cell {
        min-width: 180px;
    }
}

@media (max-width: 480px) {
    .dq-workorder-table-pagination a, 
    .dq-workorder-table-pagination span {
        padding: 6px 10px;
        font-size: 12px;
    }
}

.dq-workorder-table-wrapper {
  position: relative;
  width: 100%;
  overflow-x: auto;
}
.dq-scroll-hint {
  position: absolute;
  bottom: 8px;
  right: 18px;
  z-index: 20;
  pointer-events: none;
  opacity: 0.85;
  display: none;
}
@media (max-width: 991px) {
  .dq-workorder-table-wrapper .dq-scroll-hint {
    display: block;
  }
}


.dq-workorder-table {
    min-width: 1200px; /* or however wide your table needs */
    display: block;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

@media (max-width: 991px) {
    .dq-workorder-table {
        min-width: 900px;
    }
    .dq-workorder-table-wrapper .dq-scroll-hint {
        display: block;
    }
}

@media (max-width: 768px) {
    .dq-workorder-table-wrapper {
        padding-bottom: 16px;
    }
    .dq-workorder-table {
        min-width: 650px;
    }
}
</style>';
    }

    private static function render_table($workorders)
{
    if (empty($workorders)) {
        return '<div class="dq-workorder-table-empty">No work orders found.</div>';
    }

    $output = '<table class="dq-workorder-table">';
    $output .= '<thead><tr>
        <th>Work Order ID</th>
        <th>Location</th>
        <th>Field Engineer</th>
        <th>Product ID</th>
        <th>Customer Info</th>
        <th>Date Received</th>
        <th>FSC Contact Date</th>
        <th>FSC Contact with client Days</th>
        <th>Scheduled date</th>
        <th>Date Service Completed by FSE</th>
        <th>Closed On</th>
        <th>Date FSR and DIA Reports sent</th>
        <th>Leads</th>
        <th>Status</th>
        <th>View</th>
    </tr></thead><tbody>';

    foreach ($workorders as $workorder) {
        $post_id = $workorder->ID;

        // 1. Work Order ID: post_title
        $workorder_id = esc_html(get_the_title($post_id));

        // 2. Location (ACF fields)
        $wo_location = function_exists('get_field') ? get_field('wo_location', $post_id) : get_post_meta($post_id, 'wo_location', true);
        $wo_city     = function_exists('get_field') ? get_field('wo_city', $post_id) : get_post_meta($post_id, 'wo_city', true);
        $wo_state    = function_exists('get_field') ? get_field('wo_state', $post_id) : get_post_meta($post_id, 'wo_state', true);
        $location = '<strong>Account:</strong> ' . esc_html($wo_location) . '<br>'
            . '<strong>City:</strong> ' . esc_html($wo_state) . '<br>'
            . '<strong>State:</strong> ' . esc_html($wo_city);

        // 3. Field Engineer (author display name & ACF profile_picture)
        $author_id = $workorder->post_author;
        $engineer = '';
        if ($author_id) {
            $user        = get_userdata($author_id);
            $display_name = $user ? esc_html($user->display_name) : '';
            $profile_picture_id = function_exists('get_field') ? get_field('profile_picture', "user_$author_id") : '';
            $profile_url = '';
            if ($profile_picture_id) {
                $profile_url = wp_get_attachment_image($profile_picture_id, [32,32], false, ['class' => 'wo-profile-picture', 'style' => 'border-radius:50%;vertical-align:middle;margin-right:8px;']);
            }
            $engineer = $profile_url . $display_name;
        }

        // 4. Product ID (ACF)
        $installed_product_id = function_exists('get_field') ? get_field('installed_product_id', $post_id) : get_post_meta($post_id, 'installed_product_id', true);

        // 5. Customer Info (ACF)
        $customer_info = '<strong>Name:</strong> ' . esc_html(function_exists('get_field') ? get_field('wo_contact_name', $post_id) : get_post_meta($post_id, 'wo_contact_name', true)) . '<br>'
            . '<strong>Address:</strong> ' . esc_html(function_exists('get_field') ? get_field('wo_contact_address', $post_id) : get_post_meta($post_id, 'wo_contact_address', true)) . '<br>'
            . '<strong>Email:</strong> ' . esc_html(function_exists('get_field') ? get_field('wo_contact_email', $post_id) : get_post_meta($post_id, 'wo_contact_email', true)) . '<br>'
            . '<strong>Number:</strong> ' . esc_html(function_exists('get_field') ? get_field('wo_service_contact_number', $post_id) : get_post_meta($post_id, 'wo_service_contact_number', true));

        // 6. Date Received
        $date_received_raw = function_exists('get_field') ? get_field('date_requested_by_customer', $post_id) : get_post_meta($post_id, 'date_requested_by_customer', true);
        $date_received     = self::format_date($date_received_raw);

        // 7. FSC Contact Date
        $fsc_contact_date_raw = function_exists('get_field') ? get_field('wo_fsc_contact_date', $post_id) : get_post_meta($post_id, 'wo_fsc_contact_date', true);
        $fsc_contact_date     = self::format_date($fsc_contact_date_raw);

        // 8. FSC Contact with client Days
        $client_days = '';
        if ($fsc_contact_date_raw && $date_received_raw) {
            $diff_days = abs(round((strtotime($fsc_contact_date_raw) - strtotime($date_received_raw)) / (60 * 60 * 24)));
            $client_days = $diff_days . ' day' . ($diff_days === 1 ? '' : 's');
        } else {
            $client_days = 'N/A';
        }

        // 9. Scheduled date
        $schedule_date = self::format_date(function_exists('get_field') ? get_field('schedule_date_time', $post_id) : get_post_meta($post_id, 'schedule_date_time', true));

        // 10. Date Service Completed by FSE
        $date_service_completed_by_fse = self::format_date(function_exists('get_field') ? get_field('date_service_completed_by_fse', $post_id) : get_post_meta($post_id, 'date_service_completed_by_fse', true));

        // 11. Closed on
        $closed_on = self::format_date(function_exists('get_field') ? get_field('closed_on', $post_id) : get_post_meta($post_id, 'closed_on', true));

        // 12. Date FSR and DIA Reports sent to Customer
        $report_date = self::format_date(function_exists('get_field') ? get_field('date_fsr_and_dia_reports_sent_to_customer', $post_id) : get_post_meta($post_id, 'date_fsr_and_dia_reports_sent_to_customer', true));

        // 13. Leads (ACF)
        $leads_info = '<strong>Lead:</strong> ' . esc_html(function_exists('get_field') ? get_field('wo_leads', $post_id) : get_post_meta($post_id, 'wo_leads', true)) . '<br>'
            . '<strong>Category:</strong> ' . esc_html(function_exists('get_field') ? get_field('wo_lead_category', $post_id) : get_post_meta($post_id, 'wo_lead_category', true));

        // 14. Status: get the term category value (don't include uncategorized)
        $status_terms = get_the_terms($post_id, 'category');
        $status_value = '';
        if (!empty($status_terms) && !is_wp_error($status_terms)) {
            $filtered_terms = array_filter($status_terms, function($term) {
                return strtolower($term->name) !== 'uncategorized';
            });
            $status_names = array_map(function($term) {
                return esc_html($term->name);
            }, $filtered_terms);
            $status_value = implode(', ', $status_names);
        }

        // 15. View button
        $view_btn = '<a href="' . esc_url(get_permalink($post_id)) . '" class="button">View</a>';

        $output .= '<tr>
            <td>' . $workorder_id . '</td>
            <td>' . $location . '</td>
            <td>' . $engineer . '</td>
            <td>' . esc_html($installed_product_id) . '</td>
            <td>' . $customer_info . '</td>
            <td>' . $date_received . '</td>
            <td>' . $fsc_contact_date . '</td>
            <td>' . $client_days . '</td>
            <td>' . $schedule_date . '</td>
            <td>' . $date_service_completed_by_fse . '</td>
            <td>' . $closed_on . '</td>
            <td>' . $report_date . '</td>
            <td>' . $leads_info . '</td>
            <td>' . $status_value . '</td>
            <td>' . $view_btn . '</td>
        </tr>';
    }

    $output .= '</tbody></table>';
    return $output;
}

    private static function format_date($raw_date)
    {
        if (empty($raw_date)) {
            return 'N/A';
        }
        $timestamp = strtotime($raw_date);
        if ($timestamp !== false) {
            return date('m/d/Y', $timestamp);
        }
        return $raw_date;
    }

    private static function render_pagination($current_page, $max_pages)
    {
        if ($max_pages <= 1) {
            return '';
        }

        $output = '<div class="dq-workorder-table-pagination">';

        // Previous button
        $prev_class = ($current_page <= 1) ? 'disabled' : '';
        $output .= '<a href="#" class="dq-workorder-table-prev ' . $prev_class . '" data-page="' . ($current_page - 1) . '">« Previous</a>';

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
        $output .= '<a href="#" class="dq-workorder-table-next ' . $next_class . '" data-page="' . ($current_page + 1) . '">Next »</a>';

        $output .= '</div>';
        return $output;
    }

    private static function enqueue_inline_script($wrapper_id, $atts)
    {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('dq_workorder_table_nonce');

        $script = "
        (function($) {
            var wrapper = $('#" . esc_js($wrapper_id) . "');
            var settings = " . json_encode([
                'per_page' => intval($atts['per_page']),
                'status' => sanitize_text_field(isset($atts['status']) ? $atts['status'] : ''),
                'state' => sanitize_text_field(isset($atts['state']) ? $atts['state'] : ''),
            ]) . ";

            // Pagination click
            wrapper.on('click', '.dq-workorder-table-pagination a:not(.disabled)', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (!page || page < 1) return;
                loadWorkorders(page);
            });

            function loadWorkorders(page) {
                wrapper.addClass('loading');
                $.ajax({
                    url: '" . esc_js($ajax_url) . "',
                    type: 'POST',
                    data: {
                        action: 'dq_workorder_table_paginate',
                        nonce: '" . esc_js($nonce) . "',
                        page: page,
                        per_page: settings.per_page,
                        status: settings.status,
                        state: settings.state
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            if (response.data.table) {
                                wrapper.find('.dq-workorder-table-content').html(response.data.table);
                            }
                            var existingPagination = wrapper.find('.dq-workorder-table-pagination');
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
                    error: function(xhr, status, error) {
                        console.error('Work order table AJAX error:', error);
                        wrapper.find('.dq-workorder-table-content').html('<div class=\"dq-workorder-table-empty\">Error loading work orders. Please try again.</div>');
                    },
                    complete: function() {
                        wrapper.removeClass('loading');
                    }
                });
            }
        })(jQuery);
        ";

        wp_add_inline_script('dq-workorder-table', $script);
    }

    public static function ajax_paginate()
    {
        check_ajax_referer('dq_workorder_table_nonce', 'nonce');

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : self::DEFAULT_PER_PAGE;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $state = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '';

        $atts = [
            'per_page' => max(1, $per_page),
            'status' => $status,
            'state' => $state,
        ];

        $data = self::get_workorders($atts, $page);

        wp_send_json_success([
            'table' => self::render_table($data['workorders']),
            'pagination' => self::render_pagination($data['current_page'], $data['max_pages']),
        ]);
    }
}
