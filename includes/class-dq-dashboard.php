<?php
if (!defined('ABSPATH')) exit;

/**
 * Class DQ_Dashboard
 * Front-end dashboard for logged-in admin users.
 * Usage: [dqqb_dashboard]
 */
class DQ_Dashboard
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

    /**
     * Roles to include in team view (can be filtered)
     */
    const TEAM_ROLES = ['engineer', 'staff'];

    public static function init()
    {
        add_shortcode('dqqb_dashboard', [__CLASS__, 'render_shortcode']);
        add_action('wp_ajax_dq_dashboard_paginate', [__CLASS__, 'ajax_paginate']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    public static function register_assets()
    {
        wp_register_style(
            'dq-dashboard',
            DQQB_URL . 'assets/css/dq-dashboard.css',
            [],
            DQQB_VERSION
        );
        wp_register_script(
            'dq-dashboard',
            DQQB_URL . 'assets/js/dq-dashboard.js',
            ['jquery'],
            DQQB_VERSION,
            true
        );
    }

    /**
     * Check if current user is admin
     */
    private static function user_can_view()
    {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    /**
     * Render shortcode
     */
    public static function render_shortcode($atts)
    {
        if (!self::user_can_view()) {
            return '<div class="dqqb-dashboard-notice">You must be logged in as an administrator to view this dashboard.</div>';
        }

        wp_enqueue_style('dq-dashboard');
        wp_enqueue_script('dq-dashboard');

        // Get current menu from URL param
        $current_menu = isset($_GET['dqqb_menu']) ? sanitize_key($_GET['dqqb_menu']) : 'dashboard';
        $valid_menus = ['dashboard', 'workorders', 'invoices', 'invoices_balance', 'financial_report', 'workorder_report', 'team', 'documentation'];
        if (!in_array($current_menu, $valid_menus, true)) {
            $current_menu = 'dashboard';
        }

        // Get sub-tab for workorder_report menu
        $report_tab = isset($_GET['report_tab']) ? sanitize_key($_GET['report_tab']) : 'monthly_summary';
        $valid_report_tabs = ['monthly_summary', 'fse_report'];
        if (!in_array($report_tab, $valid_report_tabs, true)) {
            $report_tab = 'monthly_summary';
        }

        // Localize script
        wp_localize_script('dq-dashboard', 'dqDashboardVars', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dq_dashboard_nonce'),
            'currentMenu' => $current_menu,
            'reportTab' => $report_tab,
        ]);

        $output = '<div class="dqqb-dashboard-wrapper">';
        $output .= self::render_sidebar($current_menu);
        $output .= '<div class="dqqb-dashboard-content">';
        $output .= self::render_content($current_menu, $report_tab);
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render sidebar menu
     */
    private static function render_sidebar($current_menu)
    {
        $base_url = remove_query_arg(['dqqb_menu', 'report_tab']);
        $menu_items = [
            'dashboard' => [
                'label' => 'Dashboard',
                'icon' => 'dashicons-dashboard',
            ],
            'workorders' => [
                'label' => 'Work Orders',
                'icon' => 'dashicons-clipboard',
            ],
            'invoices' => [
                'label' => 'Invoices',
                'icon' => 'dashicons-media-text',
            ],
            'invoices_balance' => [
                'label' => 'Invoices Balance',
                'icon' => 'dashicons-money-alt',
            ],
            'financial_report' => [
                'label' => 'Financial Report',
                'icon' => 'dashicons-chart-pie',
            ],
            'workorder_report' => [
                'label' => 'Work Order Report',
                'icon' => 'dashicons-analytics',
            ],
            'team' => [
                'label' => 'Team',
                'icon' => 'dashicons-groups',
            ],
            'documentation' => [
                'label' => 'Documentation',
                'icon' => 'dashicons-book-alt',
            ],
            'logout' => [
                'label' => 'Logout',
                'icon' => 'dashicons-exit',
            ],
        ];

        $output = '<div class="dqqb-dashboard-sidebar">';
        $output .= '<div class="dqqb-sidebar-header">';
        $output .= '<h2>Admin Dashboard</h2>';
        $output .= '</div>';
        $output .= '<nav class="dqqb-sidebar-nav">';
        $output .= '<ul>';

        foreach ($menu_items as $key => $item) {
            // Hide Financial Report menu item unless user has administrator privileges
            if ($key === 'financial_report' && !current_user_can('manage_options')) {
                continue;
            }

            $active_class = ($current_menu === $key) ? 'active' : '';
            $url = add_query_arg('dqqb_menu', $key, $base_url);
            
            // Only financial_report links to admin area now (workorder_report is handled in front-end)
            if ($key === 'financial_report') {
                $url = admin_url('admin.php?page=dq-financial-reports');
            } elseif ($key === 'logout') {
                $url = DQ_Login_Redirect::get_logout_url();
            }

            $target = '';
            if ($key === 'financial_report') {
                $target = ' target="_blank"';
            }

            $output .= '<li class="' . esc_attr($active_class) . '">';
            $output .= '<a href="' . esc_url($url) . '"' . $target . '>';
            $output .= '<span class="dashicons ' . esc_attr($item['icon']) . '"></span>';
            $output .= '<span class="menu-label">' . esc_html($item['label']) . '</span>';
            $output .= '</a>';
            $output .= '</li>';
        }

        $output .= '</ul>';
        $output .= '</nav>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render main content area based on current menu
     */
    private static function render_content($current_menu, $report_tab = 'monthly_summary')
    {
        switch ($current_menu) {
            case 'dashboard':
                return self::render_dashboard_view();
            case 'workorders':
                return self::render_workorders_view();
            case 'invoices':
                return self::render_invoices_view();
            case 'invoices_balance':
                return self::render_invoices_balance_view();
            case 'financial_report':
                return self::render_financial_report_view();
            case 'workorder_report':
                return self::render_workorder_report_view($report_tab);
            case 'team':
                return self::render_team_view();
            case 'documentation':
                return self::render_documentation_view();
            default:
                return self::render_dashboard_view();
        }
    }

    /**
     * Render Dashboard view with summary cards and work order table
     */
    private static function render_dashboard_view()
    {
        $counts = self::get_workorder_counts();
        $page = isset($_GET['dashboard_page']) ? max(1, intval($_GET['dashboard_page'])) : 1;
        $workorders_data = self::get_dashboard_workorders($page);

        $output = '<div class="dqqb-dashboard-main">';
        $output .= '<h1>Dashboard</h1>';

        // Summary Cards
        $output .= '<div class="dqqb-summary-cards">';
        $output .= '<div class="dqqb-card dqqb-card-open">';
        $output .= '<div class="card-icon"><span class="dashicons dashicons-unlock"></span></div>';
        $output .= '<div class="card-content">';
        $output .= '<span class="card-count">' . intval($counts['open']) . '</span>';
        $output .= '<span class="card-label">Open Work Orders</span>';
        $output .= '</div>';
        $output .= '</div>';

        $output .= '<div class="dqqb-card dqqb-card-scheduled">';
        $output .= '<div class="card-icon"><span class="dashicons dashicons-calendar-alt"></span></div>';
        $output .= '<div class="card-content">';
        $output .= '<span class="card-count">' . intval($counts['scheduled']) . '</span>';
        $output .= '<span class="card-label">Scheduled Work Orders</span>';
        $output .= '</div>';
        $output .= '</div>';

        $output .= '<div class="dqqb-card dqqb-card-closed">';
        $output .= '<div class="card-icon"><span class="dashicons dashicons-lock"></span></div>';
        $output .= '<div class="card-content">';
        $output .= '<span class="card-count">' . intval($counts['closed']) . '</span>';
        $output .= '<span class="card-label">Closed Work Orders</span>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';

        // Work Orders Table
        $output .= '<div class="dqqb-workorders-section">';
        $output .= '<h2>Recent Work Orders</h2>';
        $output .= '<div id="dqqb-dashboard-table-container">';
        $output .= self::render_dashboard_table($workorders_data['workorders']);
        $output .= '</div>';
        $output .= self::render_dashboard_pagination($workorders_data['current_page'], $workorders_data['max_pages']);
        $output .= '</div>';

        $output .= '</div>';

        return $output;
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
            return '<div class="dqqb-empty-state">No work orders found.</div>';
        }

        $output = '<table class="dqqb-dashboard-table">';
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

        return '<div class="dqqb-engineer-cell">' .
               '<img src="' . $avatar_url . '" alt="" class="dqqb-avatar" />' .
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

        $output = '<div class="dqqb-dashboard-pagination" data-max-pages="' . esc_attr($max_pages) . '">';

        // Previous button
        $prev_class = ($current_page <= 1) ? 'disabled' : '';
        $output .= '<a href="#" class="dqqb-page-link ' . $prev_class . '" data-page="' . ($current_page - 1) . '">« Previous</a>';

        // Page numbers
        $range = 2;
        $start = max(1, $current_page - $range);
        $end = min($max_pages, $current_page + $range);

        if ($start > 1) {
            $output .= '<a href="#" class="dqqb-page-link" data-page="1">1</a>';
            if ($start > 2) {
                $output .= '<span class="ellipsis">...</span>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $current_page) {
                $output .= '<span class="current">' . $i . '</span>';
            } else {
                $output .= '<a href="#" class="dqqb-page-link" data-page="' . $i . '">' . $i . '</a>';
            }
        }

        if ($end < $max_pages) {
            if ($end < $max_pages - 1) {
                $output .= '<span class="ellipsis">...</span>';
            }
            $output .= '<a href="#" class="dqqb-page-link" data-page="' . $max_pages . '">' . $max_pages . '</a>';
        }

        // Next button
        $next_class = ($current_page >= $max_pages) ? 'disabled' : '';
        $output .= '<a href="#" class="dqqb-page-link ' . $next_class . '" data-page="' . ($current_page + 1) . '">Next »</a>';

        $output .= '</div>';
        return $output;
    }

    /**
     * Render Work Orders view (uses existing shortcode)
     */
    private static function render_workorders_view()
    {
        $output = '<div class="dqqb-dashboard-main">';
        $output .= '<h1>Work Orders</h1>';
        $output .= do_shortcode('[workorder_table per_page="20"]');
        $output .= '</div>';
        return $output;
    }

    /**
     * Render Invoices view (uses existing shortcode)
     */
    private static function render_invoices_view()
    {
        $output = '<div class="dqqb-dashboard-main">';
        $output .= '<h1>Invoices</h1>';
        $output .= do_shortcode('[dqqb_invoice_list]');
        $output .= '</div>';
        return $output;
    }

    /**
     * Render Invoices Balance view (unpaid only)
     */
    private static function render_invoices_balance_view()
    {
        $output = '<div class="dqqb-dashboard-main">';
        $output .= '<h1>Invoices Balance</h1>';
        $output .= do_shortcode('[dqqb_invoice_list unpaid_only="true"]');
        $output .= '</div>';
        return $output;
    }

    /**
     * Render Financial Report view (link to admin)
     */
    private static function render_financial_report_view()
    {
        $output = '<div class="dqqb-dashboard-main dqqb-redirect-view">';
        $output .= '<h1>Financial Report</h1>';
        $output .= '<p>The Financial Report is available in the WordPress admin area.</p>';
        $output .= '<a href="' . esc_url(admin_url('admin.php?page=dq-financial-reports')) . '" class="dqqb-admin-link" target="_blank">';
        $output .= '<span class="dashicons dashicons-external"></span> Open Financial Reports';
        $output .= '</a>';
        $output .= '</div>';
        return $output;
    }

    /**
     * Render Work Order Report view with sub-menu tabs
     */
    private static function render_workorder_report_view($report_tab = 'monthly_summary')
    {
        $base_url = remove_query_arg('report_tab');
        $base_url = add_query_arg('dqqb_menu', 'workorder_report', $base_url);

        $output = '<div class="dqqb-dashboard-main">';
        $output .= '<h1>Work Order Report</h1>';

        // Sub-menu tabs
        $output .= '<div class="dqqb-report-tabs">';
        $output .= '<a href="' . esc_url(add_query_arg('report_tab', 'monthly_summary', $base_url)) . '" class="dqqb-report-tab' . ($report_tab === 'monthly_summary' ? ' active' : '') . '">';
        $output .= '<span class="dashicons dashicons-calendar-alt"></span> Monthly Summary';
        $output .= '</a>';
        $output .= '<a href="' . esc_url(add_query_arg('report_tab', 'fse_report', $base_url)) . '" class="dqqb-report-tab' . ($report_tab === 'fse_report' ? ' active' : '') . '">';
        $output .= '<span class="dashicons dashicons-groups"></span> Field Service Engineers Report';
        $output .= '</a>';
        $output .= '</div>';

        // Render appropriate content based on tab
        $output .= '<div class="dqqb-report-content">';
        if ($report_tab === 'fse_report') {
            $output .= self::render_fse_report_content();
        } else {
            $output .= self::render_monthly_summary_content();
        }
        $output .= '</div>';

        $output .= '</div>';
        return $output;
    }

    /**
     * Render Monthly Summary content (reuses DQ_WorkOrder_Report logic)
     */
    private static function render_monthly_summary_content()
    {
        if (!class_exists('DQ_WorkOrder_Report')) {
            return '<div class="dqqb-empty-state">Work Order Report class not available.</div>';
        }

        ob_start();
        DQ_WorkOrder_Report::main_dashboard();
        return ob_get_clean();
    }

    /**
     * Render FSE Report content (reuses DQ_WorkOrder_Report logic)
     */
    private static function render_fse_report_content()
    {
        if (!class_exists('DQ_WorkOrder_Report')) {
            return '<div class="dqqb-empty-state">Work Order Report class not available.</div>';
        }

        // Ensure jQuery is enqueued for AJAX functionality
        wp_enqueue_script('jquery');

        // Output ajaxurl for frontend AJAX calls (matches admin area behavior)
        $output = '<script>window.ajaxurl = ' . wp_json_encode(admin_url('admin-ajax.php')) . ';</script>';

        ob_start();
        DQ_WorkOrder_Report::fse_report_dashboard();
        $output .= ob_get_clean();

        return $output;
    }

    /**
     * Render Team view
     */
    private static function render_team_view()
    {
        $team_members = self::get_team_members();

        $output = '<div class="dqqb-dashboard-main">';
        $output .= '<h1>Team</h1>';

        if (empty($team_members)) {
            $output .= '<div class="dqqb-empty-state">No team members found.</div>';
        } else {
            $output .= '<table class="dqqb-team-table">';
            $output .= '<thead><tr>';
            $output .= '<th>Profile</th>';
            $output .= '<th>First Name</th>';
            $output .= '<th>Last Name</th>';
            $output .= '<th>Email</th>';
            $output .= '<th>Role</th>';
            $output .= '</tr></thead>';
            $output .= '<tbody>';

            foreach ($team_members as $member) {
                $output .= '<tr>';
                $output .= '<td class="profile-cell"><img src="' . esc_url($member['avatar']) . '" alt="" class="dqqb-team-avatar" /></td>';
                $output .= '<td>' . esc_html($member['first_name']) . '</td>';
                $output .= '<td>' . esc_html($member['last_name']) . '</td>';
                $output .= '<td><a href="mailto:' . esc_attr($member['email']) . '">' . esc_html($member['email']) . '</a></td>';
                $output .= '<td><span class="dqqb-role-badge dqqb-role-' . esc_attr($member['role_key']) . '">' . esc_html($member['role']) . '</span></td>';
                $output .= '</tr>';
            }

            $output .= '</tbody></table>';
        }

        $output .= '</div>';
        return $output;
    }

    /**
     * Render Documentation view with links to documentation pages
     */
    private static function render_documentation_view()
    {
        $documentation_links = [
            [
                'label' => 'Account Login',
                'url' => '/documentation/account-login-documentation/',
                'icon' => 'dashicons-admin-users',
            ],
            [
                'label' => 'Content Management',
                'url' => '/documentation/content-management/',
                'icon' => 'dashicons-admin-page',
            ],
            [
                'label' => 'Invoice Management',
                'url' => '/documentation/invoice-management/',
                'icon' => 'dashicons-media-text',
            ],
            [
                'label' => 'Work Order Management',
                'url' => '/documentation/work-order-management/',
                'icon' => 'dashicons-clipboard',
            ],
            [
                'label' => 'Reporting Management',
                'url' => '/documentation/reporting-documentation/',
                'icon' => 'dashicons-chart-bar',
            ],
        ];

        $output = '<div class="dqqb-dashboard-main">';
        $output .= '<h1>Documentation</h1>';
        $output .= '<div class="dqqb-documentation-list">';
        $output .= '<ul class="dqqb-doc-links">';

        foreach ($documentation_links as $link) {
            $output .= '<li>';
            $output .= '<a href="' . esc_url($link['url']) . '">';
            $output .= '<span class="dashicons ' . esc_attr($link['icon']) . '"></span>';
            $output .= '<span class="doc-label">' . esc_html($link['label']) . '</span>';
            $output .= '</a>';
            $output .= '</li>';
        }

        $output .= '</ul>';
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Get team members (engineers and staff)
     * Roles can be filtered using 'dqqb_dashboard_team_roles' filter
     */
    private static function get_team_members()
    {
        $team = [];

        // Allow filtering of team roles
        $team_roles = apply_filters('dqqb_dashboard_team_roles', self::TEAM_ROLES);

        // Get users with configured roles
        $users = get_users([
            'role__in' => $team_roles,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        // Get WordPress roles for proper display names
        global $wp_roles;

        foreach ($users as $user) {
            $avatar_url = '';

            // Try ACF profile_picture first
            if (function_exists('get_field')) {
                $profile_picture = get_field('profile_picture', 'user_' . $user->ID);
                if (is_array($profile_picture) && !empty($profile_picture['url'])) {
                    $avatar_url = $profile_picture['url'];
                } elseif (is_numeric($profile_picture)) {
                    $avatar_url = wp_get_attachment_url($profile_picture);
                } elseif (is_string($profile_picture) && filter_var($profile_picture, FILTER_VALIDATE_URL)) {
                    $avatar_url = $profile_picture;
                }
            }

            // Fallback to Gravatar
            if (!$avatar_url) {
                $avatar_url = get_avatar_url($user->ID, ['size' => 64]);
            }

            // Get role display name using WordPress translate_user_role
            $role_key = '';
            $role_display = '';
            if (!empty($user->roles)) {
                $role_key = $user->roles[0];
                // Get the proper role name from wp_roles
                if (isset($wp_roles->role_names[$role_key])) {
                    $role_display = translate_user_role($wp_roles->role_names[$role_key]);
                } else {
                    $role_display = ucfirst(str_replace('_', ' ', $role_key));
                }
            }

            // Use display_name as fallback for first/last name if empty
            $first_name = $user->first_name;
            $last_name = $user->last_name;
            if (!$first_name && !$last_name) {
                // Split display_name as fallback
                $name_parts = explode(' ', $user->display_name, 2);
                $first_name = isset($name_parts[0]) ? $name_parts[0] : $user->display_name;
                $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
            }

            $team[] = [
                'id' => $user->ID,
                'first_name' => $first_name ?: $user->display_name,
                'last_name' => $last_name ?: '',
                'email' => $user->user_email,
                'avatar' => $avatar_url,
                'role' => $role_display,
                'role_key' => $role_key,
            ];
        }

        return $team;
    }

    /**
     * AJAX handler for dashboard pagination
     */
    public static function ajax_paginate()
    {
        check_ajax_referer('dq_dashboard_nonce', 'nonce');

        if (!self::user_can_view()) {
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
