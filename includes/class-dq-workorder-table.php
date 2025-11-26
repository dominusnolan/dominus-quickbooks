<?php
if (!defined('ABSPATH')) exit;

/**
 * Class DQ_Workorder_Table
 * Frontend shortcode that displays a table listing all Work Orders.
 * Usage: [workorder_table]
 */
class DQ_Workorder_Table
{
    public static function init()
    {
        add_shortcode('workorder_table', [__CLASS__, 'render_shortcode']);
    }

    /**
     * Render the workorder_table shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render_shortcode($atts)
    {
        $atts = shortcode_atts([], $atts, 'workorder_table');

        $workorders = self::get_workorders();
        $output = self::get_styles();
        $output .= self::render_table($workorders);

        return $output;
    }

    /**
     * Get all workorders
     *
     * @return array Array of workorder posts
     */
    private static function get_workorders()
    {
        $args = [
            'post_type'      => 'workorder',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Get a field value with ACF fallback
     *
     * @param string $key     Field key
     * @param int    $post_id Post ID
     * @return string Field value
     */
    private static function get_field_value($key, $post_id)
    {
        if (function_exists('get_field')) {
            $value = get_field($key, $post_id);
            if (is_array($value)) {
                return '';
            }
            return (string) $value;
        }
        $value = get_post_meta($post_id, $key, true);
        return is_array($value) ? '' : (string) $value;
    }

    /**
     * Normalize a date string to Y-m-d format
     *
     * @param string $raw_date Raw date string
     * @return string|null Normalized date (Y-m-d) or null
     */
    private static function normalize_date($raw_date)
    {
        if (empty($raw_date) || !is_scalar($raw_date)) {
            return null;
        }
        $raw_date = trim((string) $raw_date);
        $timestamp = strtotime($raw_date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        // Fallback: try specific formats
        $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i', 'm/d/Y', 'd-m-Y', 'd/m/Y', 'n/j/Y'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $raw_date);
            if ($date && $date->format($format) === $raw_date) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    /**
     * Format a date string for display (m/d/Y)
     *
     * @param string $raw_date Raw date string
     * @return string Formatted date (m/d/Y) or empty string
     */
    private static function format_date($raw_date)
    {
        $normalized = self::normalize_date($raw_date);
        if ($normalized === null) {
            return '';
        }
        return date('m/d/Y', strtotime($normalized));
    }

    /**
     * Calculate days between two dates
     * Returns positive days when date2 is after date1
     *
     * @param string $date1 First date string (start date)
     * @param string $date2 Second date string (end date)
     * @return string Number of days formatted as "X days" or empty string
     */
    private static function calculate_days_between($date1, $date2)
    {
        $normalized1 = self::normalize_date($date1);
        $normalized2 = self::normalize_date($date2);

        if ($normalized1 === null || $normalized2 === null) {
            return '';
        }

        $datetime1 = new DateTime($normalized1);
        $datetime2 = new DateTime($normalized2);
        $interval = $datetime1->diff($datetime2);
        
        // Get days with sign: positive if date2 > date1, negative otherwise
        $days = (int) $interval->days;
        if ($interval->invert) {
            $days = -$days;
        }

        return $days . ' days';
    }

    /**
     * Get status term for a workorder (excluding Uncategorized)
     *
     * @param int $post_id Post ID
     * @return string Status term name or empty string
     */
    private static function get_status_term($post_id)
    {
        $terms = get_the_terms($post_id, 'status');
        if (is_wp_error($terms) || empty($terms) || !is_array($terms)) {
            return '';
        }

        foreach ($terms as $term) {
            // Skip Uncategorized
            if (strtolower($term->name) === 'uncategorized') {
                continue;
            }
            return $term->name;
        }

        return '';
    }

    /**
     * Get CSS styles for the table
     *
     * @return string CSS styles
     */
    private static function get_styles()
    {
        return '<style>
.workorder-table-wrapper { margin: 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; overflow-x: auto; }
.workorder-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); min-width: 1400px; }
.workorder-table th { background: #006d7b; color: #fff; padding: 12px 8px; text-align: left; font-weight: 600; font-size: 13px; white-space: nowrap; }
.workorder-table td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; font-size: 13px; }
.workorder-table tr:hover td { background: #f8f9fa; }
.workorder-table tr:last-child td { border-bottom: none; }
.workorder-table .wo-location-cell,
.workorder-table .wo-customer-cell,
.workorder-table .wo-leads-cell { font-size: 12px; line-height: 1.5; }
.workorder-table .wo-location-cell strong,
.workorder-table .wo-customer-cell strong,
.workorder-table .wo-leads-cell strong { color: #333; }
.workorder-table .wo-view-btn { display: inline-block; padding: 6px 12px; background: #006d7b; color: #fff; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 500; }
.workorder-table .wo-view-btn:hover { background: #005560; color: #fff; }
.workorder-table .wo-status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: #e9ecef; color: #495057; }
.workorder-table-empty { padding: 40px; text-align: center; color: #666; font-style: italic; background: #f8f9fa; border-radius: 4px; }
</style>';
    }

    /**
     * Render the HTML table
     *
     * @param array $workorders Array of workorder posts
     * @return string HTML table
     */
    private static function render_table($workorders)
    {
        if (empty($workorders)) {
            return '<div class="workorder-table-empty">No work orders found.</div>';
        }

        $output = '<div class="workorder-table-wrapper">';
        $output .= '<table class="workorder-table">';
        $output .= '<thead><tr>';
        $output .= '<th>Work Order ID</th>';
        $output .= '<th>Location</th>';
        $output .= '<th>Field Engineer</th>';
        $output .= '<th>Product ID</th>';
        $output .= '<th>Customer Info</th>';
        $output .= '<th>Date Received</th>';
        $output .= '<th>FSC Contact Date</th>';
        $output .= '<th>FSC Contact Days</th>';
        $output .= '<th>Scheduled Date</th>';
        $output .= '<th>Service Completed</th>';
        $output .= '<th>Closed On</th>';
        $output .= '<th>Reports Sent</th>';
        $output .= '<th>Leads</th>';
        $output .= '<th>Status</th>';
        $output .= '<th>View</th>';
        $output .= '</tr></thead>';
        $output .= '<tbody>';

        foreach ($workorders as $workorder) {
            $post_id = $workorder->ID;
            $output .= self::render_row($workorder, $post_id);
        }

        $output .= '</tbody></table>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render a single table row
     *
     * @param WP_Post $workorder Workorder post object
     * @param int     $post_id   Post ID
     * @return string HTML table row
     */
    private static function render_row($workorder, $post_id)
    {
        // Work Order ID (post_title)
        $work_order_id = $workorder->post_title;

        // Location
        $wo_location = self::get_field_value('wo_location', $post_id);
        $wo_city = self::get_field_value('wo_city', $post_id);
        $wo_state = self::get_field_value('wo_state', $post_id);

        // Field Engineer (Author)
        $author_id = $workorder->post_author;
        $author = get_user_by('id', $author_id);
        $engineer_name = $author ? $author->display_name : '';

        // Product ID
        $product_id = self::get_field_value('installed_product_id', $post_id);

        // Customer Info
        $contact_name = self::get_field_value('wo_contact_name', $post_id);
        $contact_address = self::get_field_value('wo_contact_address', $post_id);
        $contact_email = self::get_field_value('wo_contact_email', $post_id);
        $contact_number = self::get_field_value('wo_service_contact_number', $post_id);

        // Date fields
        $date_received_raw = self::get_field_value('date_requested_by_customer', $post_id);
        $fsc_contact_date_raw = self::get_field_value('wo_fsc_contact_date', $post_id);
        $schedule_date_raw = self::get_field_value('schedule_date_time', $post_id);
        $service_completed_raw = self::get_field_value('date_service_completed_by_fse', $post_id);
        $closed_on_raw = self::get_field_value('closed_on', $post_id);
        $reports_sent_raw = self::get_field_value('date_fsr_and_dia_reports_sent_to_customer', $post_id);

        // Format dates for display
        $date_received = self::format_date($date_received_raw);
        $fsc_contact_date = self::format_date($fsc_contact_date_raw);
        $schedule_date = self::format_date($schedule_date_raw);
        $service_completed = self::format_date($service_completed_raw);
        $closed_on = self::format_date($closed_on_raw);
        $reports_sent = self::format_date($reports_sent_raw);

        // FSC Contact with client Days: wo_fsc_contact_date - date_requested_by_customer
        $fsc_contact_days = self::calculate_days_between($date_received_raw, $fsc_contact_date_raw);

        // Leads
        $wo_leads = self::get_field_value('wo_leads', $post_id);
        $wo_lead_category = self::get_field_value('wo_lead_category', $post_id);

        // Status (term category, exclude Uncategorized)
        $status = self::get_status_term($post_id);

        // View button link
        $permalink = get_permalink($post_id);

        // Build location cell
        $location_html = '<div class="wo-location-cell">';
        if ($wo_location) {
            $location_html .= '<strong>Account:</strong> ' . esc_html($wo_location) . '<br>';
        }
        if ($wo_city) {
            $location_html .= '<strong>City:</strong> ' . esc_html($wo_city) . '<br>';
        }
        if ($wo_state) {
            $location_html .= '<strong>State:</strong> ' . esc_html($wo_state);
        }
        $location_html .= '</div>';

        // Build customer info cell
        $customer_html = '<div class="wo-customer-cell">';
        if ($contact_name) {
            $customer_html .= '<strong>Name:</strong> ' . esc_html($contact_name) . '<br>';
        }
        if ($contact_address) {
            $customer_html .= '<strong>Address:</strong> ' . esc_html($contact_address) . '<br>';
        }
        if ($contact_email) {
            $customer_html .= '<strong>Email:</strong> ' . esc_html($contact_email) . '<br>';
        }
        if ($contact_number) {
            $customer_html .= '<strong>Number:</strong> ' . esc_html($contact_number);
        }
        $customer_html .= '</div>';

        // Build leads cell
        $leads_html = '<div class="wo-leads-cell">';
        if ($wo_leads) {
            $leads_html .= '<strong>Lead:</strong> ' . esc_html($wo_leads) . '<br>';
        }
        if ($wo_lead_category) {
            $leads_html .= '<strong>Category:</strong> ' . esc_html($wo_lead_category);
        }
        $leads_html .= '</div>';

        // Build row
        $output = '<tr>';
        $output .= '<td>' . esc_html($work_order_id) . '</td>';
        $output .= '<td>' . $location_html . '</td>';
        $output .= '<td>' . esc_html($engineer_name) . '</td>';
        $output .= '<td>' . esc_html($product_id) . '</td>';
        $output .= '<td>' . $customer_html . '</td>';
        $output .= '<td>' . esc_html($date_received) . '</td>';
        $output .= '<td>' . esc_html($fsc_contact_date) . '</td>';
        $output .= '<td>' . esc_html($fsc_contact_days) . '</td>';
        $output .= '<td>' . esc_html($schedule_date) . '</td>';
        $output .= '<td>' . esc_html($service_completed) . '</td>';
        $output .= '<td>' . esc_html($closed_on) . '</td>';
        $output .= '<td>' . esc_html($reports_sent) . '</td>';
        $output .= '<td>' . $leads_html . '</td>';
        $output .= '<td><span class="wo-status-badge">' . esc_html($status) . '</span></td>';
        $output .= '<td><a href="' . esc_url($permalink) . '" class="wo-view-btn">View</a></td>';
        $output .= '</tr>';

        return $output;
    }
}
