<?php
if (!defined('ABSPATH')) exit;

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
            'status' => '',
            'state' => '',
            'engineer' => '',
            'search' => '',
        ], $atts, 'workorder_table');

        $atts['per_page'] = max(1, intval($atts['per_page']));
        wp_enqueue_script('dq-workorder-table');

        static $instance = 0;
        $instance++;
        $wrapper_id = 'dq-workorder-table-' . $instance;

        $page = 1;
        $data = self::get_workorders($atts, $page);

        $output = self::render_html($data, $atts, $wrapper_id);
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
        $meta_query = ['relation' => 'AND'];
        if (!empty($atts['state'])) {
            $meta_query[] = [
                'key' => 'wo_state',
                'value' => sanitize_text_field($atts['state']),
                'compare' => '=',
            ];
        }
        if (!empty($atts['engineer'])) {
            $args['author'] = intval($atts['engineer']);
        }
        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }
        if (!empty($atts['status'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'category',
                    'field' => 'slug',
                    'terms' => sanitize_text_field($atts['status']),
                ],
            ];
        }

        // Handle search query - search by post ID (exact) or title (partial)
        if (!empty($atts['search'])) {
            $search_term = sanitize_text_field($atts['search']);
            // Check if search term is numeric (potential post ID)
            if (is_numeric($search_term)) {
                // Search by exact post ID using post__in to maintain compatibility with other filters
                $args['post__in'] = [intval($search_term)];
            } else {
                // Search by title (partial match)
                $args['s'] = $search_term;
            }
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
        // Status dropdown
        $status_terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
        ]);
        $status_options = '<option value="">All Status</option>';
        foreach ($status_terms as $term) {
            if (strtolower($term->name) !== 'uncategorized') {
                $selected = ($atts['status'] === $term->slug) ? ' selected' : '';
                $status_options .= '<option value="' . esc_attr($term->slug) . '"' . $selected . '>' . esc_html($term->name) . '</option>';
            }
        }

        // State dropdown - get distinct states from existing work orders
        $state_options = '<option value="">All States</option>';
        $distinct_states = self::get_distinct_states();
        foreach ($distinct_states as $state_value) {
            $selected = (isset($atts['state']) && $atts['state'] === $state_value) ? ' selected' : '';
            $state_options .= '<option value="' . esc_attr($state_value) . '"' . $selected . '>' . esc_html($state_value) . '</option>';
        }

        // Engineer dropdown
        $author_posts = get_posts([
            'post_type' => 'workorder',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true
        ]);
        $engineers = [];
        foreach ($author_posts as $pid) {
            $p = get_post($pid);
            if ($p && $p->post_author) $engineers[$p->post_author] = true;
        }
        $engineer_dropdown = '<option value="">All Field Engineers</option>';
        foreach (array_keys($engineers) as $uid) {
            $user = get_userdata($uid);
            if ($user) {
                $selected = (isset($atts['engineer']) && intval($atts['engineer']) === $uid) ? ' selected' : '';
                $engineer_dropdown .= '<option value="' . esc_attr($uid) . '"' . $selected . '>' . esc_html($user->display_name) . '</option>';
            }
        }

        // Current search value
        $search_value = isset($atts['search']) ? esc_attr($atts['search']) : '';

        // Filter UI
        $output = '<div id="' . esc_attr($wrapper_id) . '" class="dq-workorder-table-wrapper">';
        $output .= self::get_styles();
        $output .= '<div class="dq-workorder-table-search-row" style="margin-bottom:12px;">';
        $output .= '<input type="text" name="dq_filter_search" class="dq-workorder-search-input" placeholder="Search by Work Order ID or Title..." value="' . $search_value . '" />';
        $output .= '</div>';
        $output .= '<form class="dq-workorder-table-filters" style="display:flex;gap:16px;align-items:center;margin-bottom:18px;">';
        $output .= '<label>Status <select name="dq_filter_status">' . $status_options . '</select></label>';
        $output .= '<label>State <select name="dq_filter_state">' . $state_options . '</select></label>';
        $output .= '<label>Field Engineer <select name="dq_filter_engineer">' . $engineer_dropdown . '</select></label>';
        $output .= '</form>';

        // Scroll hint
        $output .= '<div class="dq-scroll-hint">
        <svg width="54" height="32">
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
    width: 100%;
    margin: 0 auto;
    padding: 0;
    box-sizing: border-box;
}
.dq-workorder-table-wrapper.loading {
    opacity: 0.6;
    pointer-events: none;
}
.dq-workorder-search-input {
    width: 100%;
    max-width: 400px;
    padding: 10px 14px;
    border: 1px solid #e8e8e8;
    border-radius: 4px;
    font-size: 14px;
    box-sizing: border-box;
}
.dq-workorder-search-input:focus {
    outline: none;
    border-color: #0996a0;
    box-shadow: 0 0 0 2px rgba(9, 150, 160, 0.1);
}
.dq-workorder-table-filters {
    margin-bottom: 1em;
}
.dq-workorder-table-filters label {
    font-weight: 600;
    color: #0996a0;
    font-size: 15px;
}
.dq-workorder-table-filters select {
    margin-left:8px;
    padding: 6px 10px;
    border-radius: 4px;
    border: 1px solid #e8e8e8;
    font-size: 14px;
}
.dq-workorder-table {
    width: 100%;
    min-width: 0;
    border-collapse: collapse;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-radius: 8px;
    display: table;
    box-sizing: border-box;
}
.dq-workorder-table th {
    background: #0996a0;
    color: #fff;
    padding: 14px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
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
.dq-workorder-table tbody tr:hover td:not(.dq-expanded-row) {
    background: #e5f4f6;
}
.dq-view-btn {
    display: inline-block;
    background: #0996a0;
    color: #fff !important;
    padding: 6px 16px;
    border-radius: 5px;
    text-decoration: none;
    font-size: 14px;
}
.dq-expand-btn {
    margin-left: 10px;
    background: transparent;
    border: none;
    color: #0996a0;
    cursor:pointer;
    padding:4px;
}
.dq-expanded-row td {
    background: #f4ffff;
    border-bottom: none;
    border-top: 1px solid #0996a0;
}
.dq-expanded-content {
    padding: 18px 6px;
    font-size: 13px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap:18px;
}
.dq-details-block {
    background: #fff;
    border: 1px solid #c7e4eb;
    border-radius: 7px;
    padding: 13px;
    margin-bottom: 10px;
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
    .dq-workorder-table-wrapper, .dq-workorder-table {
        width: 100vw;
        min-width: 0;
        max-width: 100vw;
    }
    .dq-workorder-table-wrapper .dq-scroll-hint { display: block; }
}
@media (max-width: 768px) {
    .dq-workorder-table-wrapper { padding-bottom: 16px; }
    .dq-workorder-table { min-width: 430px; }
}
.dq-workorder-table-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: 24px;
    flex-wrap: wrap;
    font-size: 16px;
}
.dq-workorder-table-pagination a,
.dq-workorder-table-pagination span {
    display: inline-block;
    padding: 8px 14px;
    border: 1px solid #0996a0;
    border-radius: 4px;
    text-decoration: none;
    color: #0996a0;
    background: #fff;
    font-size: 16px;
    margin: 0 2px;
    transition: all 0.2s ease;
}
.dq-workorder-table-pagination a:hover {
    background: #0996a0;
    color: #fff;
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
.dq-workorder-table-pagination .ellipsis {
    border: none;
    background: transparent;
    color: #888;
    padding: 0 8px;
}
.dq-workorder-table-empty {
    padding: 40px;
    text-align: center;
    color: #666;
    font-style: italic;
    background: #f8f9fa;
    border-radius: 8px;
}
</style>';
    }

    private static function render_table($workorders)
    {
        if (empty($workorders)) {
            return '<div class="dq-workorder-table-empty">No work orders found.</div>';
        }

        $output = '<table class="dq-workorder-table"><thead><tr>
            <th>Work Order ID</th>
            <th>Field Engineer</th>
            <th>Status</th>
            <th>Date Received</th>
            <th>FSC Contact Date</th>
            <th>FSC Contact with client Days</th>
            <th>Scheduled date</th>
            <th>Date Service Completed by FSE</th>
            <th>Closed On</th>
            <th>Date FSR and DIA Reports sent</th>
            <th>Product ID</th>
            <th>Action</th>
        </tr></thead><tbody>';

        foreach ($workorders as $workorder) {
            $post_id = $workorder->ID;
            $workorder_id = esc_html(get_the_title($post_id));
            $author_id = $workorder->post_author;
            $engineer = '';
            if ($author_id) {
                $user = get_userdata($author_id);
                $display_name = $user ? esc_html($user->display_name) : '';
                $profile_picture_id = function_exists('get_field') ? get_field('profile_picture', "user_$author_id") : '';
                $profile_url = '';
                if ($profile_picture_id) {
                    $profile_url = wp_get_attachment_image($profile_picture_id, [24,24], false, ['style' => 'border-radius:50%;vertical-align:middle;margin-right:6px;']);
                }
                $engineer = $profile_url . $display_name;
            }
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
            // Main columns dates and product
            $date_received_raw = function_exists('get_field') ? get_field('date_requested_by_customer', $post_id) : get_post_meta($post_id, 'date_requested_by_customer', true);
            $date_received = self::format_date($date_received_raw);

            $fsc_contact_date_raw = function_exists('get_field') ? get_field('wo_fsc_contact_date', $post_id) : get_post_meta($post_id, 'wo_fsc_contact_date', true);
            $fsc_contact_date = self::format_date($fsc_contact_date_raw);

            $client_days = '';
            if ($fsc_contact_date_raw && $date_received_raw) {
                $diff_days = abs(round((strtotime($fsc_contact_date_raw) - strtotime($date_received_raw)) / (60 * 60 * 24)));
                $client_days = $diff_days . ' day' . ($diff_days === 1 ? '' : 's');
            } else {
                $client_days = 'N/A';
            }

            $schedule_date = self::format_date(function_exists('get_field') ? get_field('schedule_date_time', $post_id) : get_post_meta($post_id, 'schedule_date_time', true));
            $date_service_completed_by_fse = self::format_date(function_exists('get_field') ? get_field('date_service_completed_by_fse', $post_id) : get_post_meta($post_id, 'date_service_completed_by_fse', true));
            $closed_on = self::format_date(function_exists('get_field') ? get_field('closed_on', $post_id) : get_post_meta($post_id, 'closed_on', true));
            $report_date = self::format_date(function_exists('get_field') ? get_field('date_fsr_and_dia_reports_sent_to_customer', $post_id) : get_post_meta($post_id, 'date_fsr_and_dia_reports_sent_to_customer', true));
            $product_id = function_exists('get_field') ? get_field('installed_product_id', $post_id) : get_post_meta($post_id, 'installed_product_id', true);
            $view_btn = '<a href="' . esc_url(get_permalink($post_id)) . '" class="dq-view-btn" target="_blank">View</a>';
            $expand_btn = '<button class="dq-expand-btn" data-expand-id="dq-details-' . $post_id . '">&#x25BC; Expand</button>';
            $output .= '<tr class="dq-workorder-row"><td>' . $workorder_id . '</td><td>' . $engineer . '</td><td>' . $status_value . '</td><td>' . $date_received . '</td><td>' . $fsc_contact_date . '</td><td>' . $client_days . '</td><td>' . $schedule_date . '</td><td>' . $date_service_completed_by_fse . '</td><td>' . $closed_on . '</td><td>' . $report_date . '</td><td>' . esc_html($product_id) . '</td><td>' . $view_btn . $expand_btn . '</td></tr>';

            // Details row (expand)
            // Location
            $wo_location = function_exists('get_field') ? get_field('wo_location', $post_id) : get_post_meta($post_id, 'wo_location', true);
            $wo_city     = function_exists('get_field') ? get_field('wo_city', $post_id) : get_post_meta($post_id, 'wo_city', true);
            $wo_state    = function_exists('get_field') ? get_field('wo_state', $post_id) : get_post_meta($post_id, 'wo_state', true);
            $location_blk = '<div class="dq-details-block"><strong>Location Details</strong><br>'
                . 'Account: ' . esc_html($wo_location) . '<br>'
                . 'City: ' . esc_html($wo_state) . '<br>'
                . 'State: ' . esc_html($wo_city) . '</div>';

            // Leads Section
            $leads_blk = '<div class="dq-details-block"><strong>Leads</strong><br>Lead: ' . esc_html(function_exists('get_field') ? get_field('wo_leads', $post_id) : get_post_meta($post_id, 'wo_leads', true))
                . '<br>Category: ' . esc_html(function_exists('get_field') ? get_field('wo_lead_category', $post_id) : get_post_meta($post_id, 'wo_lead_category', true)) . '</div>';

            // Customer Information Section
            $customer_blk = '<div class="dq-details-block"><strong>Customer Info</strong><br>Name: ' . esc_html(function_exists('get_field') ? get_field('wo_contact_name', $post_id) : get_post_meta($post_id, 'wo_contact_name', true))
                . '<br>Address: ' . esc_html(function_exists('get_field') ? get_field('wo_contact_address', $post_id) : get_post_meta($post_id, 'wo_contact_address', true))
                . '<br>Email: ' . esc_html(function_exists('get_field') ? get_field('wo_contact_email', $post_id) : get_post_meta($post_id, 'wo_contact_email', true))
                . '<br>Contact: ' . esc_html(function_exists('get_field') ? get_field('wo_service_contact_number', $post_id) : get_post_meta($post_id, 'wo_service_contact_number', true)) . '</div>';

            $output .= '<tr id="dq-details-' . $post_id . '" class="dq-expanded-row" style="display:none;"><td colspan="12"><div class="dq-expanded-content">'
                . $location_blk . $leads_blk . $customer_blk
                . '</div></td></tr>';
        }
        $output .= '</tbody></table>';

        // Inline JS for expand/collapse (one open at a time)
        $output .= "<script>
        (function($){
            $(document).off('click.dqExpandBtn').on('click.dqExpandBtn','.dq-expand-btn', function(e){
                e.preventDefault();
                var targetId = $(this).data('expand-id');
                $('.dq-expanded-row').not('#'+targetId).hide();
                $('#'+targetId).toggle();
            });
        })(jQuery);</script>";

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

    private static function get_distinct_states()
    {
        global $wpdb;
        $states = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_value 
                FROM {$wpdb->postmeta} pm 
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                WHERE pm.meta_key = %s 
                AND pm.meta_value != '' 
                AND p.post_type = %s 
                AND p.post_status IN ('publish', 'draft', 'pending', 'private')
                ORDER BY pm.meta_value ASC",
                'wo_state',
                'workorder'
            )
        );
        return $states ? $states : [];
    }

    private static function render_pagination($current_page, $max_pages)
    {
        if ($max_pages <= 1) {
            return '';
        }
        $output = '<div class="dq-workorder-table-pagination">';
        $prev_class = ($current_page <= 1) ? 'disabled' : '';
        $output .= '<a href="#" class="dq-workorder-table-prev ' . $prev_class . '" data-page="' . ($current_page - 1) . '">« Previous</a>';
        $range = 2;
        $start = max(1, $current_page - $range);
        $end = min($max_pages, $current_page + $range);
        if ($start > 1) {
            $output .= '<a href="#" data-page="1">1</a>';
            if ($start > 2) {
                $output .= '<span class="ellipsis">...</span>';
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $current_page) {
                $output .= '<span class="current">' . $i . '</span>';
            } else {
                $output .= '<a href="#" data-page="' . $i . '">' . $i . '</a>';
            }
        }
        if ($end < $max_pages) {
            if ($end < $max_pages - 1) {
                $output .= '<span class="ellipsis">...</span>';
            }
            $output .= '<a href="#" data-page="' . $max_pages . '">' . $max_pages . '</a>';
        }
        $next_class = ($current_page >= $max_pages) ? 'disabled' : '';
        $output .= '<a href="#" class="dq-workorder-table-next ' . $next_class . '" data-page="' . ($current_page + 1) . '">Next »</a>';
        $output .= '</div>';
        return $output;
    }

    private static function enqueue_inline_script($wrapper_id, $atts)
    {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('dq_workorder_table_nonce');
        $engineer_val = isset($atts['engineer']) ? intval($atts['engineer']) : '';

        $script = "
        (function($) {
            var wrapper = $('#" . esc_js($wrapper_id) . "');
            var searchTimeout = null;
            var settings = " . json_encode([
                'per_page' => intval($atts['per_page']),
                'status' => sanitize_text_field(isset($atts['status']) ? $atts['status'] : ''),
                'state' => sanitize_text_field(isset($atts['state']) ? $atts['state'] : ''),
                'engineer' => $engineer_val,
                'search' => sanitize_text_field(isset($atts['search']) ? $atts['search'] : '')
            ]) . ";
            wrapper.on('change', '.dq-workorder-table-filters select', function(e){
                var status = wrapper.find('select[name=\"dq_filter_status\"]').val();
                var state = wrapper.find('select[name=\"dq_filter_state\"]').val();
                var engineer = wrapper.find('select[name=\"dq_filter_engineer\"]').val();
                settings.status = status;
                settings.state = state;
                settings.engineer = engineer;
                loadWorkorders(1);
            });
            wrapper.on('input', '.dq-workorder-search-input', function(e){
                var searchVal = $(this).val();
                settings.search = searchVal;
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }
                searchTimeout = setTimeout(function() {
                    loadWorkorders(1);
                }, 300);
            });
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
                        state: settings.state,
                        engineer: settings.engineer,
                        search: settings.search
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
        $engineer = isset($_POST['engineer']) ? intval($_POST['engineer']) : '';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $atts = [
            'per_page' => max(1, $per_page),
            'status' => $status,
            'state' => $state,
            'engineer' => $engineer,
            'search' => $search,
        ];
        $data = self::get_workorders($atts, $page);

        wp_send_json_success([
            'table' => self::render_table($data['workorders']),
            'pagination' => self::render_pagination($data['current_page'], $data['max_pages']),
        ]);
    }
}

?>