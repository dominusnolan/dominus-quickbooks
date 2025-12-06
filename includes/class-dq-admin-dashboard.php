<?php
if (!defined('ABSPATH')) exit;

/**
 * Class DQ_Admin_Dashboard
 * WordPress Admin dashboard with Work Orders Summary
 * Similar to frontend dashboard but for admin area
 */
class DQ_Admin_Dashboard
{
    const PER_PAGE = 25;

    /**
     * Status mappings for normalization
     */
    const STATUS_MAPPINGS = [
        'close' => 'closed',
    ];

    /**
     * Valid workorder statuses
     */
    const VALID_STATUSES = ['open', 'scheduled', 'close', 'closed'];

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_dq_admin_dashboard_paginate', [__CLASS__, 'ajax_paginate']);
    }

    /**
     * Add admin menu page
     */
    public static function add_menu_page()
    {
        add_menu_page(
            'Dashboard',
            'Dashboard',
            'manage_options',
            'dq-admin-dashboard',
            [__CLASS__, 'render_page'],
            'dashicons-dashboard',
            3
        );
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook)
    {
        // Only load on our dashboard page
        if ($hook !== 'toplevel_page_dq-admin-dashboard') {
            return;
        }

        wp_enqueue_style(
            'dq-admin-dashboard',
            DQQB_URL . 'assets/css/dq-admin-dashboard.css',
            [],
            DQQB_VERSION
        );

        wp_enqueue_script(
            'dq-admin-dashboard',
            DQQB_URL . 'assets/js/dq-admin-dashboard.js',
            ['jquery'],
            DQQB_VERSION,
            true
        );

        wp_localize_script('dq-admin-dashboard', 'dqAdminDashboard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dq_admin_dashboard_nonce'),
        ]);
    }

    /**
     * Render admin dashboard page
     */
    public static function render_page()
    {
        $counts = self::get_workorder_counts();
        $page = isset($_GET['dashboard_page']) ? max(1, intval($_GET['dashboard_page'])) : 1;
        $workorders_data = self::get_dashboard_workorders($page);

        // Prepare action URLs
        $manage_workorders_url = admin_url('edit.php?post_type=workorder');
        // Import from SMAX: external SMAX import manager page
        $import_smax_url = 'https://milaymechanical.com/wp-admin/admin.php?page=pmxi-admin-manage';
        $manage_invoices_url = admin_url('edit.php?post_type=quickbooks_invoice');

        echo '<div class="wrap dq-admin-dashboard-wrap">';
        echo '<h1>Dashboard</h1>';

        // Work Orders Summary Section
        echo '<div class="dq-admin-dashboard-section">';
        echo '<h2>Work Orders Summary</h2>';

        // Action buttons (Manage / Import / Invoices)
        echo '<div class="dq-admin-actions" style="margin-bottom:16px;">';
        echo '<a href="' . esc_url($manage_workorders_url) . '" class="button">Manage Work Orders</a> ';
        echo '<a href="' . esc_url($import_smax_url) . '" class="button">Import Work Orders from SMAX</a> ';
        echo '<a href="' . esc_url($manage_invoices_url) . '" class="button">Manage Invoices</a>';
        echo '</div>';

        // Summary Cards
        echo '<div class="dq-summary-cards">';
        
        // Open Work Orders Card
        echo '<div class="dq-summary-card dq-card-open">';
        echo '<div class="card-icon"><span class="dashicons dashicons-unlock"></span></div>';
        echo '<div class="card-content">';
        echo '<span class="card-count">' . intval($counts['open']) . '</span>';
        echo '<span class="card-label">Open Work Orders</span>';
        echo '</div>';
        echo '</div>';

        // Scheduled Work Orders Card
        echo '<div class="dq-summary-card dq-card-scheduled">';
        echo '<div class="card-icon"><span class="dashicons dashicons-calendar-alt"></span></div>';
        echo '<div class="card-content">';
        echo '<span class="card-count">' . intval($counts['scheduled']) . '</span>';
        echo '<span class="card-label">Scheduled Work Orders</span>';
        echo '</div>';
        echo '</div>';

        // Closed Work Orders Card
        echo '<div class="dq-summary-card dq-card-closed">';
        echo '<div class="card-icon"><span class="dashicons dashicons-lock"></span></div>';
        echo '<div class="card-content">';
        echo '<span class="card-count">' . intval($counts['closed']) . '</span>';
        echo '<span class="card-label">Closed Work Orders</span>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // End summary cards

        // Recent Work Orders Table
        echo '<div class="dq-workorders-section">';
        echo '<h3>Recent Work Orders</h3>';
        echo '<div id="dq-admin-dashboard-table-container">';
        echo self::render_dashboard_table($workorders_data['workorders']);
        echo '</div>';
        echo self::render_dashboard_pagination($workorders_data['current_page'], $workorders_data['max_pages']);
        echo '</div>';

        echo '</div>'; // End dashboard section
        echo '</div>'; // End wrap
    }

    /**
     * Get work order counts by status
     */
    private static function get_workorder_counts()
    {
        $counts = [
            'open' => 0,
            'scheduled' => 0,
            'closed' => 0,
        ];

        // Get all workorders
        $workorders = get_posts([
            'post_type' => 'workorder',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($workorders as $pid) {
            $status = self::get_workorder_status($pid);
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * Get workorder status from taxonomy or ACF field
     */
    private static function get_workorder_status($post_id)
    {
        // Try taxonomy first
        $terms = get_the_terms($post_id, 'status');
        if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
            $term = array_shift($terms);
            $slug = !empty($term->slug) ? strtolower($term->slug) : '';
            if (in_array($slug, self::VALID_STATUSES, true)) {
                return self::normalize_status($slug);
            }
        }

        // Try category taxonomy
        $cats = get_the_terms($post_id, 'category');
        if (!is_wp_error($cats) && !empty($cats) && is_array($cats)) {
            foreach ($cats as $cat) {
                $slug = strtolower($cat->slug);
                if (in_array($slug, self::VALID_STATUSES, true)) {
                    return self::normalize_status($slug);
                }
            }
        }

        // Try ACF/meta field
        $wo_status = self::get_acf_or_meta($post_id, 'wo_status');
        if ($wo_status) {
            $wo_status = strtolower(trim($wo_status));
            if (in_array($wo_status, self::VALID_STATUSES, true)) {
                return self::normalize_status($wo_status);
            }
        }

        return 'open'; // Default
    }

    /**
     * Normalize status value using mappings
     */
    private static function normalize_status($status)
    {
        return isset(self::STATUS_MAPPINGS[$status]) ? self::STATUS_MAPPINGS[$status] : $status;
    }

    /**
     * Helper to get ACF field or fall back to post meta
     */
    private static function get_acf_or_meta($post_id, $field_name)
    {
        if (function_exists('get_field')) {
            return get_field($field_name, $post_id);
        }
        return get_post_meta($post_id, $field_name, true);
    }

    /**
     * Get dashboard work orders with pagination
     */
    private static function get_dashboard_workorders($page = 1)
    {
        $engineer_ids = get_users(['role' => 'engineer', 'fields' => 'ID']);
        $args = [
            'post_type' => 'workorder',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => self::PER_PAGE,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            'author__in'     => $engineer_ids, // only authors with engineer role
        ];

        $query = new WP_Query($args);

        return [
            'workorders' => $query->posts,
            'total' => $query->found_posts,
            'max_pages' => $query->max_num_pages,
            'current_page' => $page,
        ];
    }

    /**
     * Render dashboard table
     */
    private static function render_dashboard_table($workorders)
    {
        if (empty($workorders)) {
            return '<div class="dq-empty-state">No work orders found.</div>';
        }

        $output = '<table class="wp-list-table widefat fixed striped dq-dashboard-table">';
        $output .= '<thead><tr>';
        $output .= '<th>Work Order Number</th>';
        $output .= '<th>Company</th>';
        $output .= '<th>Date Dispatched</th>';
        $output .= '<th>Field Engineer</th>';
        $output .= '<th>State</th>';
        $output .= '</tr></thead>';
        $output .= '<tbody>';

        foreach ($workorders as $workorder) {
            $post_id = $workorder->ID;
            $wo_number = get_the_title($post_id);
            
            // Get ACF fields using helper
            $wo_location = self::get_acf_or_meta($post_id, 'wo_location');
            $wo_date_received = self::get_acf_or_meta($post_id, 'wo_wo_date_received');
            if (!$wo_date_received) {
                $wo_date_received = self::get_acf_or_meta($post_id, 'wo_date_received');
            }
            $wo_state = self::get_acf_or_meta($post_id, 'wo_state');

            // Get author with profile picture
            $author_id = $workorder->post_author;
            $author_html = self::get_author_with_avatar($author_id);

            // Format date using WordPress date function for proper localization
            $date_display = 'N/A';
            if ($wo_date_received) {
                $ts = strtotime($wo_date_received);
                if ($ts) {
                    $date_display = date_i18n('m/d/Y', $ts);
                }
            }

            $output .= '<tr>';
            $output .= '<td><a href="' . esc_url(get_permalink($post_id)) . '">' . esc_html($wo_number) . '</a></td>';
            $output .= '<td>' . esc_html($wo_location ?: 'N/A') . '</td>';
            $output .= '<td>' . esc_html($date_display) . '</td>';
            $output .= '<td>' . $author_html . '</td>';
            $output .= '<td>' . esc_html($wo_state ?: 'N/A') . '</td>';
            $output .= '</tr>';
        }

        $output .= '</tbody></table>';
        return $output;
    }

    /**
     * Get author with avatar HTML
     */
    private static function get_author_with_avatar($user_id)
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return 'Unknown';
        }

        $display_name = esc_html($user->display_name);
        $avatar_url = '';

        // Try ACF profile_picture first
        if (function_exists('get_field')) {
            $profile_picture = get_field('profile_picture', 'user_' . $user_id);
            if (is_array($profile_picture) && !empty($profile_picture['url'])) {
                $avatar_url = esc_url($profile_picture['url']);
            } elseif (is_numeric($profile_picture)) {
                $avatar_url = esc_url(wp_get_attachment_url($profile_picture));
            } elseif (is_string($profile_picture) && filter_var($profile_picture, FILTER_VALIDATE_URL)) {
                $avatar_url = esc_url($profile_picture);
            }
        }

        // Fallback to Gravatar
        if (!$avatar_url) {
            $avatar_url = get_avatar_url($user_id, ['size' => 32]);
        }

        return '<div class="dq-engineer-cell">' .
               '<img src="' . $avatar_url . '" alt="" class="dq-avatar" />' .
               '<span>' . $display_name . '</span>' .
               '</div>';
    }

    /**
     * Render dashboard pagination
     */
    private static function render_dashboard_pagination($current_page, $max_pages)
    {
        if ($max_pages <= 1) {
            return '';
        }

        $output = '<div class="dq-dashboard-pagination" data-max-pages="' . esc_attr($max_pages) . '">';

        // Previous button
        $prev_class = ($current_page <= 1) ? 'disabled' : '';
        $output .= '<a href="#" class="dq-page-link ' . $prev_class . '" data-page="' . ($current_page - 1) . '">« Previous</a>';

        // Page numbers
        $range = 2;
        $start = max(1, $current_page - $range);
        $end = min($max_pages, $current_page + $range);

        if ($start > 1) {
            $output .= '<a href="#" class="dq-page-link" data-page="1">1</a>';
            if ($start > 2) {
                $output .= '<span class="ellipsis">...</span>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $current_page) {
                $output .= '<span class="current">' . $i . '</span>';
            } else {
                $output .= '<a href="#" class="dq-page-link" data-page="' . $i . '">' . $i . '</a>';
            }
        }

        if ($end < $max_pages) {
            if ($end < $max_pages - 1) {
                $output .= '<span class="ellipsis">...</span>';
            }
            $output .= '<a href="#" class="dq-page-link" data-page="' . $max_pages . '">' . $max_pages . '</a>';
        }

        // Next button
        $next_class = ($current_page >= $max_pages) ? 'disabled' : '';
        $output .= '<a href="#" class="dq-page-link ' . $next_class . '" data-page="' . ($current_page + 1) . '">Next »</a>';

        $output .= '</div>';
        return $output;
    }

    /**
     * AJAX handler for dashboard pagination
     */
    public static function ajax_paginate()
    {
        check_ajax_referer('dq_admin_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied', 403);
        }

        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $data = self::get_dashboard_workorders($page);

        wp_send_json_success([
            'table' => self::render_dashboard_table($data['workorders']),
            'pagination' => self::render_dashboard_pagination($data['current_page'], $data['max_pages']),
        ]);
    }
}