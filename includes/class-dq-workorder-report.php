<?php
if (!defined('ABSPATH')) exit;

/**
 * WorkOrder Reports Table: Month and State Summary PLUS Field Engineer Table
 * Date logic:
 *   If taxonomy status is open  -> date_requested_by_customer (ACF text)
 *   If taxonomy status is close -> closed_on (ACF text)
 *   If taxonomy status is scheduled -> schedule_date_time (ACF text)
 * All date fields are text, robust parsing is done.
 * Adds engineer table: Field Service Engineer (with profile_picture), Total WO, Percentage.
 * Adds AJAX dropdown to load per-engineer monthly table based on filter year. Default: Ramir Milay.
 */
class DQ_WorkOrder_Report
{
    const MODAL_PER_PAGE = 10;

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('wp_ajax_dq_fse_chart', [__CLASS__, 'ajax_fse_chart']);
        add_action('wp_ajax_dq_engineer_monthly', [__CLASS__, 'ajax_engineer_monthly']);
        add_action('wp_ajax_dq_workorder_modal', [__CLASS__, 'ajax_workorder_modal']);
        // New AJAX for average workspeed chart
        add_action('wp_ajax_dq_fse_avg_workspeed', [__CLASS__, 'ajax_fse_avg_workspeed']);
    }

    public static function menu()
    {
        add_menu_page(
            'WorkOrder Report',
            'WorkOrder Report',
            'manage_options',
            'dq-workorder-report',
            [__CLASS__, 'main_dashboard'],
            'dashicons-welcome-write-blog',
            22
        );
        add_submenu_page(
            'dq-workorder-report',
            'Work Order Monthly Summary',
            'Monthly Summary',
            'manage_options',
            'dq-workorder-report',
            [__CLASS__, 'main_dashboard']
        );
        // New submenu
        add_submenu_page(
            'dq-workorder-report',
            'Field Service Engineers Report',
            'Field Service Engineers Report',
            'manage_options',
            'dq-fse-report',
            [__CLASS__, 'fse_report_dashboard']
        );
    }

    public static function main_dashboard()
    {
        $year = self::get_selected_year();
        $workorders = self::get_workorders_in_year($year);

        $monthly_counts = self::get_monthly_workorder_counts_from_workorders($workorders, $year);
        $state_counts = self::get_state_workorder_counts($workorders);
        $engineer_data = self::get_engineer_data($workorders);
        
        // Render modal styles and container first
        self::render_modal_styles();
        self::render_modal_html();
        self::render_modal_script($year);
        
echo '<style>
.flex-container {
  display: flex;
  flex-direction: row;

}

.flex-container .flex-item {
  background-color: #f1f1f1;
  padding: 10px;
  text-align: left;
  width: 100%;
}

@media (max-width: 600px) {
  .flex-container {
    flex-direction: column;
  }
}

</style>';
        echo '<div class="wrap"><h1>WorkOrder Monthly Summary</h1>';
        self::filters_form($year);

        echo '<div class="table-set-1 flex-container" style="border: 1px solid;
    border-radius: 8px;
    padding: 23px;">';
            echo '<div class="flex-item">';
                self::render_monthly_table($monthly_counts, $year);
            echo '</div>';
            echo '<div class="flex-item">';
                self::render_state_table($state_counts);
            echo '</div>';
            echo '<div class="flex-item">';   
            self::render_engineer_table($engineer_data);
            echo '</div>';
            echo '<div class="flex-item">';  
            self::render_engineer_ajax_selector($engineer_data, $year);
            echo '</div>';
        echo '</div>'; 
        
        echo '<div class="table-set-2 flex-container">';
            echo '<div class="flex-item">';
                echo '<h4 style="font-size:20px">Lead Summary</h4>';
                self::render_leads_converted_table($workorders);
                self::render_lead_category_table($workorders);
            echo '</div>';
            echo '<div class="flex-item">';
                echo '<h4 style="font-size:20px">Re-Schedule Summary</h4>';
                self::render_reschedule_reasons_table($workorders);
                self::render_rescheduled_orders_table($workorders);
            echo '</div>';
        echo '</div>';

        self::render_kpi_table($workorders);

        echo '</div>';
    }

    private static function get_selected_year()
    {
        return isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
    }

    private static function filters_form($year)
    {
        echo '<form method="get" action="" style="margin:16px 0;display:flex;gap:12px;align-items:end;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page'] ?? 'dq-workorder-report') . '">';
        echo '<div>
            <label><strong>Year</strong><br>
                <select name="year" style="width:110px;">';
        for ($y = date('Y') - 5; $y <= date('Y') + 2; $y++) {
            echo '<option value="' . $y . '"' . ($year == $y ? ' selected' : '') . '>' . $y . '</option>';
        }
        echo '</select></label></div>';
        echo '<div><br><button type="submit" class="button button-primary" style="height:33px;">Filter</button></div>';
        echo '</form>';
    }

    private static function get_workorders_in_year($year)
    {
        $engineer_ids = get_users(['role' => 'engineer', 'fields' => 'ID']);
        $start = "{$year}-01-01";
        $end = "{$year}-12-31";
        $query = [
            'post_type'      => 'workorder',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'date_query'     => [
                ['after' => $start, 'before' => $end, 'inclusive' => true]
            ],
            'author__in'     => $engineer_ids, // only authors with engineer role
        ];
        return get_posts($query);
    }

    private static function get_monthly_workorder_counts_from_workorders($workorders, $year)
    {
        $counts = [];
        for ($m = 1; $m <= 12; $m++) $counts[$m] = 0;
        foreach ($workorders as $pid) {
            $terms = get_the_terms($pid, 'status');
            $status_slug = '';
            if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
                $term = array_shift($terms);
                $status_slug = !empty($term->slug) ? $term->slug : (is_object($term) && isset($term->name) ? sanitize_title($term->name) : '');
            }
            $raw_date = '';
            if ($status_slug === 'open') {
                $raw_date = function_exists('get_field') ? get_field('date_requested_by_customer', $pid) : get_post_meta($pid, 'date_requested_by_customer', true);
            } elseif ($status_slug === 'close') {
                $raw_date = function_exists('get_field') ? get_field('closed_on', $pid) : get_post_meta($pid, 'closed_on', true);
            } elseif ($status_slug === 'scheduled') {
                $raw_date = function_exists('get_field') ? get_field('schedule_date_time', $pid) : get_post_meta($pid, 'schedule_date_time', true);
            }
            if (!$raw_date) $raw_date = get_post_field('post_date', $pid);
            $month_num = self::parse_month_from_text($raw_date, $year);
            if ($month_num !== false && $month_num >=1 && $month_num <=12) {
                $counts[$month_num]++;
            }
        }
        return $counts;
    }

private static function render_reschedule_reasons_table($workorders)
{
    // 1. Gather reason counts
    $reasons = [];
    $year = self::get_selected_year();
    foreach ($workorders as $pid) {
        $reason = function_exists('get_field') ? get_field('rescheduled_reason', $pid) : get_post_meta($pid, 'rescheduled_reason', true);
        $reason = $reason ? $reason : '';
        if (strlen(trim($reason))) {
            if (!isset($reasons[$reason])) $reasons[$reason] = 0;
            $reasons[$reason]++;
        }
    }
    // Only non-zero
    $reasons = array_filter($reasons, function($ct){ return $ct > 0; });
    $yearly_total = array_sum($reasons);

    // Table output, styled for your screenshot
    ?>
    <style>
    .wo-reschedule-reason-table {
        width:100%; max-width:500px;
        border-collapse:collapse; background:#fff; margin-top:22px; border-radius:14px; overflow:hidden;
    }
    .wo-reschedule-reason-table th {
        background:#08b0d2; color:#fff; padding:18px 12px; font-size:18px; font-weight:600;
        text-align:left;
    }
    .wo-reschedule-reason-table td {
        padding:15px 12px; font-size:16px; border-bottom:1px solid #f2f2f2; background:#fff;
    }
    .wo-reschedule-reason-table tbody tr:last-child td {
        background:#54e6ea; font-weight:600; font-size:18px; border-bottom:none;
    }
    </style>
    <table class="wo-reschedule-reason-table">
        <thead>
            <tr>
                <th>Reasons</th>
                <th style="text-align:right;">Count of Rescheduling Reasons</th>
            </tr>
        </thead>
        <tbody>
        <?php
        foreach ($reasons as $reason => $ct) {
            echo '<tr>';
            echo '<td>'.esc_html($reason).'</td>';
            if ($ct > 0) {
                echo '<td style="text-align:right;"><a href="#" class="dq-wo-count-link" data-filter-type="reschedule_reason" data-reschedule-reason="'.esc_attr($reason).'" data-year="'.intval($year).'">'.intval($ct).'</a></td>';
            } else {
                echo '<td style="text-align:right;">0</td>';
            }
            echo '</tr>';
        }
        // Yearly total row
        echo '<tr>';
        echo '<td>Yearly Total</td>';
        if ($yearly_total > 0) {
            echo '<td style="text-align:right;"><a href="#" class="dq-wo-count-link" data-filter-type="rescheduled" data-year="'.intval($year).'">'.intval($yearly_total).'</a></td>';
        } else {
            echo '<td style="text-align:right;">0</td>';
        }
        echo '</tr>';
        ?>
        </tbody>
    </table>
    <?php
}


private static function render_kpi_table($workorders)
{
    // 1. Total Work Orders Received (wo_date_received not empty)
    $received_count = 0;
    $queue_days = [];
    $completed_count = 0;
    $year = self::get_selected_year();
    foreach ($workorders as $pid) {
        // Work order received
        $date_received = function_exists('get_field') ? get_field('wo_date_received', $pid) : get_post_meta($pid, 'wo_date_received', true);
        if ($date_received) $received_count++;
        // Average queue days
        $fsc_contact = function_exists('get_field') ? get_field('wo_fsc_contact_date', $pid) : get_post_meta($pid, 'wo_fsc_contact_date', true);
        if ($date_received && $fsc_contact) {
            $received_ts = strtotime($date_received);
            $contact_ts = strtotime($fsc_contact);
            if ($contact_ts > $received_ts && $received_ts) {
                $diff = ($contact_ts - $received_ts) / 86400;
                $queue_days[] = $diff;
            }
        }
        // Completed WO (closed_on present or status Close)
        $closed_on = function_exists('get_field') ? get_field('closed_on', $pid) : get_post_meta($pid, 'closed_on', true);
        $terms = get_the_terms($pid, 'status');
        $status_slug = '';
        if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
            $term = array_shift($terms);
            $status_slug = !empty($term->slug) ? $term->slug : (is_object($term) && isset($term->name) ? sanitize_title($term->name) : '');
        }
        if ($closed_on || $status_slug === 'close') $completed_count++;
    }
    $avg_queue_days = count($queue_days) ? round(array_sum($queue_days)/count($queue_days),2) : 0;

    ?>
    <style>
    .wo-kpi-table {
        width:100%; max-width:480px; border-collapse:collapse; margin-top:22px; background:#fff; border-radius:14px; overflow:hidden;
    }
    .wo-kpi-table th {
        background: #08b0d2;
        color: #fff; padding:18px 12px; font-size:18px; font-weight:600; text-align:left;
    }
    .wo-kpi-table td {
        padding:16px 12px; font-size:16px; border-bottom:1px solid #f4f4f4; background:#fff;
    }
    .wo-kpi-table tr:last-child td { border-bottom:none; }
    </style>
    <table class="wo-kpi-table">
        <thead>
            <tr>
                <th colspan="2">KPI Summary</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Total Work Orders Received</td>
                <td style="text-align:right;"><?php if ($received_count > 0): ?>
                    <a href="#" class="dq-wo-count-link" data-filter-type="received" data-year="<?php echo intval($year); ?>"><?php echo intval($received_count); ?></a>
                <?php else: echo '0'; endif; ?></td>
            </tr>
            <tr>
                <td>Average Days in Queue for Work Orders</td>
                <td style="text-align:right;"><?php echo number_format($avg_queue_days,2); ?></td>
            </tr>
            <tr>
                <td>Number of Work Orders Completed</td>
                <td style="text-align:right;"><?php if ($completed_count > 0): ?>
                    <a href="#" class="dq-wo-count-link" data-filter-type="completed" data-year="<?php echo intval($year); ?>"><?php echo intval($completed_count); ?></a>
                <?php else: echo '0'; endif; ?></td>
            </tr>
        </tbody>
    </table>
    <?php
}


    private static function render_rescheduled_orders_table($workorders)
{
    $total = count($workorders);
    $rescheduled_count = 0;
    $days_received_to_scheduled = [];
    $days_completed_to_closed = [];
    $year = self::get_selected_year();
    
    foreach ($workorders as $pid) {
        $scheduled_date = function_exists('get_field') ? get_field('schedule_date_time', $pid) : get_post_meta($pid, 'schedule_date_time', true);
        $rescheduled_date = function_exists('get_field') ? get_field('re-schedule', $pid) : get_post_meta($pid, 're-schedule', true);
        
        if ($rescheduled_date) $rescheduled_count++;

        // Date math
        $received_date = function_exists('get_field') ? get_field('date_requested_by_customer', $pid) : get_post_meta($pid, 'date_requested_by_customer', true);
        $completed_date = function_exists('get_field') ? get_field('schedule_date_time', $pid) : get_post_meta($pid, 'schedule_date_time', true);
        $closed_date = function_exists('get_field') ? get_field('closed_on', $pid) : get_post_meta($pid, 'closed_on', true);

        // Received to Scheduled
        $recv_ts = strtotime($received_date);
        $sched_ts = strtotime($scheduled_date);
        if ($recv_ts && $sched_ts && $sched_ts > $recv_ts) {
            $days = ($sched_ts - $recv_ts) / 86400;
            $days_received_to_scheduled[] = $days;
        }

        // Completed to Closed
        $comp_ts = strtotime($completed_date);
        $closed_ts = strtotime($closed_date);
        if ($comp_ts && $closed_ts && $closed_ts > $comp_ts) {
            $days = ($closed_ts - $comp_ts) / 86400;
            $days_completed_to_closed[] = $days;
        }
    }

    $pct_rescheduled = $total ? round($rescheduled_count * 100 / $total, 2) : 0;
    $avg_recv_sched = count($days_received_to_scheduled) ? round(array_sum($days_received_to_scheduled) / count($days_received_to_scheduled), 2) : 0;
    $avg_comp_closed = count($days_completed_to_closed) ? round(array_sum($days_completed_to_closed) / count($days_completed_to_closed), 2) : 0;
    ?>
    <style>
    .wo-reschedule-table {
        width:100%; max-width:500px;
        border-collapse:collapse; background:#fff; margin-top:22px; border-radius:14px; overflow:hidden;
    }
    .wo-reschedule-table th {
        background:#08b0d2; color:#fff; padding:18px 12px; font-size:18px; font-weight:600; text-align:left;
        border-radius:14px 14px 0 0;
    }
    .wo-reschedule-table td {
        padding:15px 12px; font-size:16px; background:#fff; border-bottom:1px solid #f2f2f2;
    }
    .wo-reschedule-table tr:last-child td { border-bottom:none; }
    </style>
    <table class="wo-reschedule-table">
        <thead>
            <tr>
                <th colspan="2">Re-Scheduled Orders</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Rescheduled work orders</td>
                <td style="text-align:right;"><?php if ($rescheduled_count > 0): ?>
                    <a href="#" class="dq-wo-count-link" data-filter-type="rescheduled" data-year="<?php echo intval($year); ?>"><?php echo intval($rescheduled_count); ?></a>
                <?php else: echo '0'; endif; ?></td>
            </tr>
            <tr>
                <td>% Rescheduled</td>
                <td style="text-align:right;"><?php echo number_format($pct_rescheduled,2); ?>%</td>
            </tr>
            <tr>
                <td>Average Days (Received to Scheduled)</td>
                <td style="text-align:right;"><?php echo number_format($avg_recv_sched,2); ?></td>
            </tr>
            <tr>
                <td>Average Days (Completed to Closed)</td>
                <td style="text-align:right;"><?php echo number_format($avg_comp_closed,2); ?></td>
            </tr>
        </tbody>
    </table>
    <?php
}

    private static function render_leads_converted_table($workorders)
    {
        $total = count($workorders);
        $leads_yes = 0;
        $year = self::get_selected_year();
        foreach ($workorders as $pid) {
            $lead_value = function_exists('get_field') ? get_field('wo_leads', $pid) : get_post_meta($pid, 'wo_leads', true);
            if (strcasecmp(trim($lead_value), 'Yes') === 0) $leads_yes++;
        }
        $percent = $total ? round($leads_yes * 100 / $total, 2) : 0;
        ?>
        <style>
        .wo-leads-table {
            width:100%; max-width:500px;
            border-collapse:collapse; background:#fff; margin-top:22px; border-radius:14px; overflow:hidden;
        }
        .wo-leads-table th {
            background:#08b0d2; color:#fff; padding:18px 12px; font-size:18px; font-weight:600; text-align:left;
            border-radius:14px 14px 0 0;
        }
        .wo-leads-table td {
            padding:15px 12px; font-size:16px; background:#fff; border-bottom:1px solid #f2f2f2;
        }
        .wo-leads-table tr:last-child td { border-bottom:none; }
        </style>
        <table class="wo-leads-table">
            <thead>
                <tr>
                    <th colspan="2">Leads</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Closed Work Orders that have been Converted to LEADS &ndash; QTY</td>
                    <td style="text-align:right;"><?php if ($leads_yes > 0): ?>
                        <a href="#" class="dq-wo-count-link" data-filter-type="leads_converted" data-year="<?php echo intval($year); ?>"><?php echo intval($leads_yes); ?></a>
                    <?php else: echo '0'; endif; ?></td>
                </tr>
                <tr>
                    <td>Closed Work Orders that have been Converted to LEADS &ndash; %</td>
                    <td style="text-align:right;"><?php echo number_format($percent,2); ?>%</td>
                </tr>
            </tbody>
        </table>
        <?php
    }



    private static function parse_month_from_text($raw_date, $year)
    {
        if (!$raw_date) return false;
        $raw_date = trim(str_replace('_x000D_', "\n", $raw_date));

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw_date, $m)) {
            if (intval($m[1]) == $year) return intval($m[2]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+\d{2}:\d{2}/', $raw_date, $m)) {
            if (intval($m[1]) == $year) return intval($m[2]);
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw_date, $m)) {
            if (intval($m[3]) == $year) return intval($m[1]);
        }
        if (preg_match('/^(\d{1,2})\-(\d{1,2})\-(\d{4})$/', $raw_date, $m)) {
            if (intval($m[3]) == $year) return intval($m[2]);
        }
        $ts = strtotime($raw_date);
        if ($ts) {
            $dtYear = intval(date('Y', $ts));
            $dtMonth = intval(date('n', $ts));
            if ($dtYear == $year) return $dtMonth;
        }
        return false;
    }

    private static function render_lead_category_table($workorders)
{
    // 1. Gather lead category counts
    $categories = [];
    $total = count($workorders);
    $year = self::get_selected_year();
    foreach ($workorders as $pid) {
        $cat = function_exists('get_field') ? get_field('wo_lead_category', $pid) : get_post_meta($pid, 'wo_lead_category', true);
        $cat = $cat ? $cat : '[No Lead Category]';
        if (!isset($categories[$cat])) $categories[$cat] = 0;
        $categories[$cat]++;
    }
    // 2. Sort by count descending
    arsort($categories);

    // 3. Table output (styling to match your image)
    ?>
    <style>
    .wo-lead-category-table {
        width:100%; max-width:640px;
        border-collapse:collapse; background:#fff; margin-top:22px; border-radius:14px; overflow:hidden;
    }
    .wo-lead-category-table th {
        background:#08b0d2;
        color:#fff; padding:18px 12px; font-size:18px; font-weight:600; text-align:left;
    }
    .wo-lead-category-table td {
        padding:15px 12px; font-size:16px; border-bottom:1px solid #f2f2f2; vertical-align:middle;
        background:#fff;
    }
    .wo-lead-category-table tr:last-child td {
        border-bottom: none;
    }
    .wo-lead-category-table tbody tr:last-child td {
        background: #c9e6c3; font-weight:600; font-size:18px;
    }
    </style>
    <table class="wo-lead-category-table">
        <thead>
            <tr>
                <th>Leads Categories</th>
                <th style="text-align:right;">Total Work Orders</th>
                <th style="text-align:right;">Percentage</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $grand_total = $total;
        foreach ($categories as $cat => $ct) {
            $pct = $grand_total ? round($ct*100/$grand_total,2) : 0;
            echo '<tr>';
            echo '<td>'.esc_html($cat).'</td>';
            if ($ct > 0) {
                echo '<td style="text-align:right;"><a href="#" class="dq-wo-count-link" data-filter-type="lead_category" data-lead-category="'.esc_attr($cat).'" data-year="'.intval($year).'">'.intval($ct).'</a></td>';
            } else {
                echo '<td style="text-align:right;">0</td>';
            }
            echo '<td style="text-align:right;">'.number_format($pct,2).'%'."</td>";
            echo '</tr>';
        }
        // Total row
        echo '<tr>';
        echo '<td>Total</td>';
        if ($grand_total > 0) {
            echo '<td style="text-align:right;"><a href="#" class="dq-wo-count-link" data-filter-type="year" data-year="'.intval($year).'">'.intval($grand_total).'</a></td>';
        } else {
            echo '<td style="text-align:right;">0</td>';
        }
        echo '<td style="text-align:right;">100%</td>';
        echo '</tr>';
        ?>
        </tbody>
    </table>
    <?php
}

    private static function get_state_workorder_counts($workorders)
    {
        $states = [];
        foreach ($workorders as $pid) {
            $wo_state = function_exists('get_field') ? get_field('wo_state', $pid) : get_post_meta($pid, 'wo_state', true);
            $state = $wo_state ? $wo_state : '[No State]';
            if (!isset($states[$state])) $states[$state] = 0;
            $states[$state]++;
        }
        ksort($states);
        return $states;
    }

    private static function get_engineer_data($workorders)
    {
        $engineers = [];
        $total_wo = count($workorders);

        foreach ($workorders as $pid) {
            $user_id = get_post_field('post_author', $pid);
            if (!isset($engineers[$user_id])) {
                $user = get_user_by('id',$user_id);
                $display_name = $user ? $user->display_name : 'Unknown';
                $img_url = '';
                if (function_exists('get_field')) {
                    $acf_img = get_field('profile_picture', 'user_' . $user_id);
                    if (is_array($acf_img) && !empty($acf_img['url'])) {
                        $img_url = esc_url($acf_img['url']);
                    } elseif (is_string($acf_img) && filter_var($acf_img, FILTER_VALIDATE_URL)) {
                        $img_url = esc_url($acf_img);
                    }
                }
                if (!$img_url) {
                    $img_url = get_avatar_url($user_id, ['size' => 64]);
                }
                $engineers[$user_id] = [
                    'user_id' => $user_id,
                    'display_name' => $display_name,
                    'profile_picture' => $img_url,
                    'wo_count' => 0,
                    'percentage' => 0,
                ];
            }
            $engineers[$user_id]['wo_count']++;
        }
        foreach ($engineers as $uid => &$data) {
            $data['percentage'] = ($total_wo > 0) ? round($data['wo_count'] * 100 / $total_wo, 2) : 0;
        }
        usort($engineers, function($a, $b) { return $b['wo_count'] - $a['wo_count']; });
        return $engineers;
    }

    private static function render_monthly_table($monthly_counts, $year)
    {
        $month_names = [
            1 => 'January', 2 => 'February', 3 => 'March',
            4 => 'April', 5 => 'May', 6 => 'June',
            7 => 'July', 8 => 'August', 9 => 'September',
            10 => 'October', 11 => 'November', 12 => 'December'
        ];
        $total = array_sum($monthly_counts);
        ?>
        <style>
        .wo-monthly-table {width:auto; border-collapse:collapse; background:#fff; margin-top:22px; display:inline-block; vertical-align:top; margin-right:18px;}
        .wo-monthly-table th {
            background:#0996a0;
            color:#fff;
            padding:14px 8px;
            font-size:16px;
            font-weight:600;
            text-align:left;
        }
        .wo-monthly-table td {
            padding:14px 8px;
            border-bottom:1px solid #ececec;
            vertical-align:middle;
            font-size:15px;
            text-align:right;
        }
        .wo-monthly-table tr:nth-child(even) td {background:#f8fafb;}
        .wo-monthly-table tr:last-child td {border-bottom:none;}
        .wo-monthly-table .totals-row td {
            background: #e5f4fa !important;
            font-weight:700;
            font-size:16px;
            border-top:2px solid #cbe6ea;
            text-align:right;
        }
        .wo-monthly-table .month-cell {text-align:left;}
        </style>
        <table class="wo-monthly-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Total Work Orders</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($month_names as $num => $label): ?>
                <tr>
                    <td class="month-cell"><?php echo esc_html($label); ?></td>
                    <td><?php if ($monthly_counts[$num] > 0): ?>
                        <a href="#" class="dq-wo-count-link" data-filter-type="month" data-month="<?php echo intval($num); ?>" data-year="<?php echo intval($year); ?>"><?php echo intval($monthly_counts[$num]); ?></a>
                    <?php else: echo '0'; endif; ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="totals-row">
                    <td>Total</td>
                    <td><?php if ($total > 0): ?>
                        <a href="#" class="dq-wo-count-link" data-filter-type="year" data-year="<?php echo intval($year); ?>"><?php echo intval($total); ?></a>
                    <?php else: echo '0'; endif; ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private static function render_state_table($state_counts)
    {
        $total = array_sum($state_counts);
        $year = self::get_selected_year();
        ?>
        <style>
        .wo-state-table {width:auto; border-collapse:collapse; background:#fff; margin-top:22px; display:inline-block; vertical-align:top;}
        .wo-state-table th {
            background:#0996a0;
            color:#fff;
            padding:14px 8px;
            font-size:16px;
            font-weight:600;
            text-align:left;
        }
        .wo-state-table td {
            padding:14px 8px;
            border-bottom:1px solid #ececec;
            vertical-align:middle;
            font-size:15px;
            text-align:right;
        }
        .wo-state-table tr:nth-child(even) td {background:#f8fafb;}
        .wo-state-table tr:last-child td {border-bottom:none;}
        .wo-state-table .totals-row td {
            background: #e5f4fa !important;
            font-weight:700;
            font-size:16px;
            border-top:2px solid #cbe6ea;
            text-align:right;
        }
        .wo-state-table .state-cell {text-align:left;}
        </style>
        <table class="wo-state-table">
            <thead>
                <tr>
                    <th>State</th>
                    <th>Total Work Orders</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($state_counts as $state => $count): ?>
                <tr>
                    <td class="state-cell"><?php echo esc_html($state); ?></td>
                    <td><?php if ($count > 0): ?>
                        <a href="#" class="dq-wo-count-link" data-filter-type="state" data-state="<?php echo esc_attr($state); ?>" data-year="<?php echo intval($year); ?>"><?php echo intval($count); ?></a>
                    <?php else: echo '0'; endif; ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="totals-row">
                    <td>Total</td>
                    <td><?php if ($total > 0): ?>
                        <a href="#" class="dq-wo-count-link" data-filter-type="year" data-year="<?php echo intval($year); ?>"><?php echo intval($total); ?></a>
                    <?php else: echo '0'; endif; ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private static function render_engineer_table($engineers)
    {
        $total_wo = 0;
        foreach($engineers as $eng) $total_wo += $eng['wo_count'];
        $year = self::get_selected_year();
        ?>
        <style>
        .wo-engineer-table {width:480px; border-collapse:collapse; background:#fff; margin-top:22px;}
        .wo-engineer-table th {
            background:#0996a0;
            color:#fff;
            padding:14px 8px;
            font-size:16px;
            font-weight:600;
            text-align:left;
        }
        .wo-engineer-table td {
            padding:14px 8px;
            border-bottom:1px solid #ececec;
            vertical-align:middle;
            font-size:15px;
        }
        .wo-engineer-table tr:nth-child(even) td {background:#f8fafb;}
        .wo-engineer-table tr:last-child td {border-bottom:none;}
        .wo-engineer-table .totals-row td {
            background: #e5f4fa !important;
            font-weight:700;
            font-size:16px;
            border-top:2px solid #cbe6ea;
        }
        .wo-engineer-table .eng-cell {
            display: flex;
            align-items: center;
        }
        .wo-engineer-table .eng-photo {
            border-radius:50%;
            width:38px;
            height:38px;
            object-fit:cover;
            margin-right:14px;
            background:#ececec;
            flex-shrink:0;
        }
        .wo-engineer-table .eng-name {
            font-weight:500;
            color:#222;
        }
        .wo-engineer-table .percent-cell {
            text-align:right;
        }
        </style>
        <table class="wo-engineer-table">
            <thead>
                <tr>
                    <th>Field Service Engineer</th>
                    <th>Total WO</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($engineers as $eng): ?>
                <tr>
                    <td class="eng-cell">
                        <img class="eng-photo" src="<?php echo esc_url($eng['profile_picture']); ?>" alt="" />
                        <span class="eng-name"><?php echo esc_html($eng['display_name']); ?></span>
                    </td>
                    <td><?php if ($eng['wo_count'] > 0): ?>
                        <a href="#" class="dq-wo-count-link" data-filter-type="engineer" data-engineer="<?php echo intval($eng['user_id']); ?>" data-year="<?php echo intval($year); ?>"><?php echo intval($eng['wo_count']); ?></a>
                    <?php else: echo '0'; endif; ?></td>
                    <td class="percent-cell"><?php echo number_format($eng['percentage'], 2); ?>%</td>
                </tr>
            <?php endforeach; ?>
                <tr class="totals-row">
                    <td>Total</td>
                    <td><?php if ($total_wo > 0): ?>
                        <a href="#" class="dq-wo-count-link" data-filter-type="year" data-year="<?php echo intval($year); ?>"><?php echo intval($total_wo); ?></a>
                    <?php else: echo '0'; endif; ?></td>
                    <td class="percent-cell">100.00%</td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render AJAX dropdown and container for per-engineer monthly table.
     * The dropdown's options are taken from $engineer_data (array returned by get_engineer_data).
     * Default value is Ramir Milay.
     */
    private static function render_engineer_ajax_selector($engineer_data, $year)
    {
        $nonce = wp_create_nonce('dq_engineer_monthly');
        $default_name = 'Ramir Milay';
        $default_id = null;
        foreach ($engineer_data as $eng) {
            if (strcasecmp($eng['display_name'], $default_name) === 0) {
                $default_id = $eng['user_id'];
                break;
            }
        }
        ?>
        <style>
        #dq-engineer-ajax-wrap { margin-top:22px; max-width:420px; }
        #dq-engineer-monthly-container { margin-top:14px; }
        #dq-engineer-select { width:100%; max-width:420px; padding:6px; }
        .dq-ajax-loading { display:inline-block; margin-left:8px; color:#666; }
        </style>

        <div id="dq-engineer-ajax-wrap">
            <select id="dq-engineer-select" aria-label="Select engineer">
                <option value="">-- Select Engineer --</option>
                <?php foreach ($engineer_data as $eng): ?>
                    <option value="<?php echo intval($eng['user_id']); ?>"
                        <?php echo ($default_id && $eng['user_id'] == $default_id) ? 'selected' : ''; ?>>
                        <?php echo esc_html($eng['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="dq-ajax-loading" id="dq-engineer-loading" style="display:none;">Loading…</span>
            <div id="dq-engineer-monthly-container" data-year="<?php echo intval($year); ?>">
                <!-- AJAX will inject per-engineer monthly table here -->
            </div>
        </div>

        <script>
        (function(){
            const select = document.getElementById('dq-engineer-select');
            const container = document.getElementById('dq-engineer-monthly-container');
            const loading = document.getElementById('dq-engineer-loading');
            const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const nonce = '<?php echo esc_js($nonce); ?>';
            const year = container ? container.dataset.year : '<?php echo intval($year); ?>';
            let defaultUid = '<?php echo esc_js($default_id); ?>';

            function renderTableHtml(name, counts) {
                const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                let total = 0;
                for (let i=0;i<12;i++) total += parseInt(counts[i+1]||0,10);
                let html = '<div style="background:#09a2a9;color:#fff;padding:12px;border-radius:6px;margin-top:12px;">' + (name ? name : '') + '</div>';
                html += '<table style="width:100%;border-collapse:collapse;margin-top:8px;">';
                html += '<thead><tr><th style="text-align:left;padding:10px;background:#f2f2f2">Month</th><th style="text-align:right;padding:10px;background:#f2f2f2">WO</th></tr></thead><tbody>';
                for (let i=0;i<12;i++) {
                    const m = i+1;
                    const c = counts[m] ? parseInt(counts[m],10) : 0;
                    // alternate row background colors similar to reference
                    let bg = '';
                    if (i<=2) bg = 'background:#f7e9c6;';
                    else if (i<=5) bg = 'background:#dff7f7;';
                    else if (i<=8) bg = 'background:#dff7dc;';
                    else bg = 'background:#cfeef7;';
                    html += '<tr style="' + bg + '"><td style="padding:10px;text-align:left;">' + months[i] + '</td><td style="padding:10px;text-align:right;">' + c + '</td></tr>';
                }
                html += '<tr style="background:#f7e9c6;font-weight:700;"><td style="padding:10px;text-align:left;">Total</td><td style="padding:10px;text-align:right;">' + total + '</td></tr>';
                html += '</tbody></table>';
                return html;
            }

            function fetchAndRender(uid) {
                container.innerHTML = '';
                if (!uid) return;
                loading.style.display = 'inline-block';
                const form = new FormData();
                form.append('action','dq_engineer_monthly');
                form.append('nonce', nonce);
                form.append('engineer', uid);
                form.append('year', year);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form })
                    .then(r => r.json())
                    .then(data => {
                        loading.style.display = 'none';
                        if (data && data.success && data.data && data.data.counts) {
                            const counts = data.data.counts;
                            const name = data.data.name || select.options[select.selectedIndex].text;
                            container.innerHTML = renderTableHtml(name, counts);
                        } else {
                            container.innerHTML = '<div style="color:#c00;padding:8px;">Unable to load data.</div>';
                        }
                    }).catch(err => {
                        loading.style.display = 'none';
                        container.innerHTML = '<div style="color:#c00;padding:8px;">AJAX error</div>';
                        console.error(err);
                    });
            }

            if (select) {
                select.addEventListener('change', function() {
                    fetchAndRender(this.value);
                });
                // Fire on default engineer
                if (defaultUid) {
                    fetchAndRender(defaultUid);
                }
            }
        })();
        </script>
        <?php
    }

    public static function ajax_engineer_monthly()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied', 403);
        }
        check_ajax_referer('dq_engineer_monthly', 'nonce');

        $engineer = isset($_POST['engineer']) ? intval($_POST['engineer']) : 0;
        $year = isset($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));
        if (!$engineer) {
            wp_send_json_error('Missing engineer id', 400);
        }

        // Query workorders for this author in the year
       
        $engineer = isset($_POST['engineer']) ? intval($_POST['engineer']) : 0;
        $year = isset($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));
        $start = "{$year}-01-01";
        $end = "{$year}-12-31";
        $query = [
            'post_type'      => 'workorder',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'author'         => $engineer,
            'date_query'     => [
                ['after' => $start, 'before' => $end, 'inclusive' => true]
            ],
            
        ];
        $workorders = get_posts($query);


        $counts = array_fill(1,12,0);
        foreach ($workorders as $pid) {
            $terms = get_the_terms($pid, 'status');
            $status_slug = '';
            if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
                $term = array_shift($terms);
                $status_slug = !empty($term->slug) ? $term->slug : (is_object($term) && isset($term->name) ? sanitize_title($term->name) : '');
            }
            $raw_date = '';
            if ($status_slug === 'open') {
                $raw_date = function_exists('get_field') ? get_field('date_requested_by_customer', $pid) : get_post_meta($pid, 'date_requested_by_customer', true);
            } elseif ($status_slug === 'close') {
                $raw_date = function_exists('get_field') ? get_field('closed_on', $pid) : get_post_meta($pid, 'closed_on', true);
            } elseif ($status_slug === 'scheduled') {
                $raw_date = function_exists('get_field') ? get_field('schedule_date_time', $pid) : get_post_meta($pid, 'schedule_date_time', true);
            }
            if (!$raw_date) $raw_date = get_post_field('post_date', $pid);
            $month_num = self::parse_month_from_text($raw_date, $year);
            if ($month_num !== false && $month_num >=1 && $month_num <=12) {
                $counts[$month_num]++;
            }
        }

        $user = get_user_by('id', $engineer);
        $name = $user ? $user->display_name : '';

        wp_send_json_success(['counts' => $counts, 'name' => $name]);
    }
    
    
    
    /**
     * Dashboard for Field Service Engineers Report (bar chart)
     */
    public static function fse_report_dashboard()
    {
        $year = isset($_GET['fse_year']) ? intval($_GET['fse_year']) : intval(date('Y'));
        $quarter = isset($_GET['fse_quarter']) ? intval($_GET['fse_quarter']) : 0;

        echo '<div class="wrap"><h1>Field Services Engineer &#8211; total work orders</h1>';
        self::fse_filters_form($year, $quarter);

        echo '<div id="dq-fse-bar-chart-container" style="margin-top:35px; min-height:320px; background:#fafafb; border-radius:8px; padding:18px 10px;">';
        // Container for chart, loaded via AJAX
        echo '<canvas id="dq-fse-chart" width="1400" height="360"></canvas>';
        echo '</div>';

        // New chart: Average WorkSpeed Quarterly Views
        echo '<div id="dq-fse-avg-workspeed-container" style="margin-top:22px; min-height:220px; background:#fafafb; border-radius:8px; padding:18px 10px;">';
        echo '<h3 style="margin:0 0 10px 0; font-weight:600;">FSE Average WorkSpeed Quarterly Views</h3>';
        echo '<canvas id="dq-fse-workspeed-chart" width="1400" height="260"></canvas>';
        echo '</div>';

        // JS for AJAX chart rendering
        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        (function(){
            let form = document.getElementById('dq-fse-filter-form');
            let chartContainer = document.getElementById('dq-fse-chart');
            let chartAvgContainer = document.getElementById('dq-fse-workspeed-chart');
            let chart;
            let chartAvg;

            function renderChart(data) {
                if (!chartContainer) return;
                if (chart) { chart.destroy(); }
                chart = new Chart(chartContainer, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Total WO',
                            data: data.counts,
                            backgroundColor: '#14b4db',
                            borderRadius:6,
                            maxBarThickness:30
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: { color:'#888' }
                            },
                            y: {
                                ticks: { color:'#222', font:{weight:600} }
                            }
                        }
                    }
                });
            }

            function renderAvgChart(data) {
                if (!chartAvgContainer) return;
                if (chartAvg) { chartAvg.destroy(); }
                chartAvg = new Chart(chartAvgContainer, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Avg Days',
                            data: data.averages,
                            backgroundColor: '#14b4db',
                            borderRadius:6,
                            maxBarThickness:30
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(ctx) {
                                        return (ctx.raw !== null ? ctx.raw.toFixed(2) : ctx.raw) + ' days';
                                    }
                                }
                            }
                        },
                        onClick: function(evt, elements) {
                            // elements is an array - if a bar was clicked, open the modal showing the workorders for that engineer
                            if (!elements || elements.length === 0) return;
                            const idx = elements[0].index;
                            const uid = (data.uids && typeof data.uids[idx] !== 'undefined') ? data.uids[idx] : null;
                            if (!uid) return;
                            const selYear = form ? form.querySelector('[name="fse_year"]').value : '<?php echo intval($year); ?>';
                            if (typeof window.dqOpenModalWithFilters === 'function') {
                                window.dqOpenModalWithFilters({ filter_type: 'engineer', engineer: uid, year: selYear }, 'Work Orders by Engineer (' + selYear + ')');
                            } else {
                                // Fallback: try to simulate click on a link if available (not likely)
                                console.warn('dqOpenModalWithFilters not available');
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    color:'#888'
                                },
                                title: {
                                    display: true,
                                    text: 'Average days (date_service_completed_by_fse - schedule_date_time)'
                                }
                            },
                            y: {
                                ticks: { color:'#222', font:{weight:600} }
                            }
                        }
                    }
                });
            }

            function ajaxLoad() {
                if (!form) return;
                const year = form.querySelector('[name="fse_year"]').value;
                const quarter = form.querySelector('[name="fse_quarter"]').value;
                // dim the containers while loading
                if (chartContainer) chartContainer.style.opacity = "0.5";
                if (chartAvgContainer) chartAvgContainer.style.opacity = "0.5";

                const body1 = 'action=dq_fse_chart&fse_year='+encodeURIComponent(year)+'&fse_quarter='+encodeURIComponent(quarter);
                const body2 = 'action=dq_fse_avg_workspeed&fse_year='+encodeURIComponent(year)+'&fse_quarter='+encodeURIComponent(quarter);

                const fetch1 = fetch(ajaxurl, {
                    method: 'POST',
                    credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: body1
                }).then(r => r.json());

                const fetch2 = fetch(ajaxurl, {
                    method: 'POST',
                    credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: body2
                }).then(r => r.json());

                Promise.all([fetch1, fetch2])
                    .then(([data1, data2]) => {
                        if (chartContainer) chartContainer.style.opacity = "1";
                        if (chartAvgContainer) chartAvgContainer.style.opacity = "1";

                        if (data1 && data1.success && data1.data) {
                            renderChart(data1.data);
                        }
                        if (data2 && data2.success && data2.data) {
                            renderAvgChart(data2.data);
                        } else if (data2 && !data2.success) {
                            // clear avg chart if error
                            if (chartAvg) { chartAvg.destroy(); chartAvg = null; }
                        }
                    }).catch(err => {
                        if (chartContainer) chartContainer.style.opacity = "1";
                        if (chartAvgContainer) chartAvgContainer.style.opacity = "1";
                        console.error('FSE charts AJAX error', err);
                    });
            }

            if (form) {
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    ajaxLoad();
                });
            }
            // Initial load
            ajaxLoad();
        })();
        </script>
        <?php
        echo '</div>';
    }

    /**
     * Filter form for FSE report (year, quarter)
     */
    private static function fse_filters_form($year, $quarter)
    {
        echo '<form id="dq-fse-filter-form" method="get" style="margin:12px 0 0 0;display:flex;gap:12px;align-items:end;">';
        echo '<input type="hidden" name="page" value="dq-fse-report">';
        echo '<div>
            <label><strong>Year</strong><br>
                <select name="fse_year" style="width:110px;">';
        for ($y = date('Y')-5; $y<=date('Y')+2; $y++) {
            echo '<option value="'.$y.'"'.($year==$y?' selected':'').'>' . $y . '</option>';
        }
        echo '</select></label></div>';
        echo '<div>
            <label><strong>Quarter</strong><br>
                <select name="fse_quarter" style="width:90px;">
                    <option value="0"' . ($quarter==0?' selected':'') . '>Whole Year</option>';
        for ($q=1; $q<=4; $q++) {
            echo '<option value="'.$q.'"'.($quarter==$q?' selected':'').'>Q'.$q.'</option>';
        }
        echo '</select></label></div>';
        echo '<div><br><button type="submit" class="button button-primary" style="height:33px;">Filter</button></div>';
        echo '</form>';
    }

    /**
     * AJAX handler for FSE bar chart
     */
public static function ajax_fse_chart()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied', 403);
    }
    $year = isset($_POST['fse_year']) ? intval($_POST['fse_year']) : intval(date('Y'));
    $quarter = isset($_POST['fse_quarter']) ? intval($_POST['fse_quarter']) : 0;

    // Compute correct TS range for the quarter
    if ($quarter >= 1 && $quarter <= 4) {
        $start_month = (($quarter - 1) * 3) + 1;
        $start_ts = strtotime("$year-$start_month-01");
        $end_month = $start_month + 2;
        $end_ts = strtotime(date('Y-m-t', mktime(0,0,0,$end_month,1,$year)));
    } else {
        $start_ts = strtotime("$year-01-01");
        $end_ts = strtotime("$year-12-31");
    }

    // Get ALL possible workorders for the year, no date filter!
   
    $query = [
        'post_type'      => 'workorder',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',

    ];
    $wos = get_posts($query);

    $wo_counts = [];
    $wo_names = [];
    foreach ($wos as $pid) {
        // Date logic by status:
        $terms = get_the_terms($pid, 'status');
        $status_slug = '';
        if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
            $term = array_shift($terms);
            $status_slug = !empty($term->slug) ? $term->slug : (is_object($term) && isset($term->name) ? sanitize_title($term->name) : '');
        }
        $raw_date = '';
        if ($status_slug === 'open') {
            $raw_date = function_exists('get_field') ? get_field('date_requested_by_customer', $pid) : get_post_meta($pid, 'date_requested_by_customer', true);
        } elseif ($status_slug === 'close') {
            $raw_date = function_exists('get_field') ? get_field('closed_on', $pid) : get_post_meta($pid, 'closed_on', true);
        } elseif ($status_slug === 'scheduled') {
            $raw_date = function_exists('get_field') ? get_field('schedule_date_time', $pid) : get_post_meta($pid, 'schedule_date_time', true);
        }
        if (!$raw_date) $raw_date = get_post_field('post_date', $pid);

        $ts = self::parse_date_for_chart($raw_date);
        if (!$ts) continue;
        if ($ts < $start_ts || $ts > $end_ts) continue;
        $author_id = get_post_field('post_author', $pid);
        if (!isset($wo_counts[$author_id])) $wo_counts[$author_id]=0;
        $wo_counts[$author_id]++;
    }
    foreach ($wo_counts as $uid => $ct) {
        $user = get_user_by('id', $uid);
        $wo_names[$uid] = $user ? $user->display_name : 'Unknown';
    }
    arsort($wo_counts);
    $labels = [];
    $counts = [];
    foreach ($wo_counts as $uid => $ct) {
        $labels[] = $wo_names[$uid];
        $counts[] = $ct;
    }
    wp_send_json_success([
        'labels'=>$labels,
        'counts'=>$counts
    ]);
}

/**
 * AJAX handler for FSE average workspeed chart
 *
 * Computes average (date_service_completed_by_fse - schedule_date_time) in days per engineer
 * Only includes workorders that have date_service_completed_by_fse value and valid schedule date.
 */
public static function ajax_fse_avg_workspeed()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied', 403);
    }
    $year = isset($_POST['fse_year']) ? intval($_POST['fse_year']) : intval(date('Y'));
    $quarter = isset($_POST['fse_quarter']) ? intval($_POST['fse_quarter']) : 0;

    // Compute correct TS range for the quarter
    if ($quarter >= 1 && $quarter <= 4) {
        $start_month = (($quarter - 1) * 3) + 1;
        $start_ts = strtotime("$year-$start_month-01");
        $end_month = $start_month + 2;
        $end_ts = strtotime(date('Y-m-t', mktime(0,0,0,$end_month,1,$year)));
    } else {
        $start_ts = strtotime("$year-01-01");
        $end_ts = strtotime("$year-12-31");
    }

    // Query all workorders
    $query = [
        'post_type'      => 'workorder',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];
    $wos = get_posts($query);

    $accumulators = []; // author_id => ['total_days' => x, 'count' => y]
    $skipped_ids = [];  // ids for which we skip calculation (invalid / negative)
    foreach ($wos as $pid) {
        // Use the same date logic as other charts to determine whether this WO falls into the selected range.
        $terms = get_the_terms($pid, 'status');
        $status_slug = '';
        if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
            $term = array_shift($terms);
            $status_slug = !empty($term->slug) ? $term->slug : (is_object($term) && isset($term->name) ? sanitize_title($term->name) : '');
        }
        $raw_date = '';
        if ($status_slug === 'open') {
            $raw_date = function_exists('get_field') ? get_field('date_requested_by_customer', $pid) : get_post_meta($pid, 'date_requested_by_customer', true);
        } elseif ($status_slug === 'close') {
            $raw_date = function_exists('get_field') ? get_field('closed_on', $pid) : get_post_meta($pid, 'closed_on', true);
        } elseif ($status_slug === 'scheduled') {
            $raw_date = function_exists('get_field') ? get_field('schedule_date_time', $pid) : get_post_meta($pid, 'schedule_date_time', true);
        }
        if (!$raw_date) $raw_date = get_post_field('post_date', $pid);

        $ts = self::parse_date_for_chart($raw_date);
        if (!$ts) continue;
        if ($ts < $start_ts || $ts > $end_ts) continue;

        // Only calculate if date_service_completed_by_fse has value (user requirement)
        $completed_raw = function_exists('get_field') ? get_field('date_service_completed_by_fse', $pid) : get_post_meta($pid, 'date_service_completed_by_fse', true);
        if (!$completed_raw) continue;

        $completed_ts = self::parse_date_for_chart($completed_raw);
        // Prefer 're-schedule' ACF field when present, otherwise fall back to schedule_date_time
        if (function_exists('get_field')) {
            $schedule_raw = get_field('re-schedule', $pid);
            if (empty($schedule_raw)) {
                $schedule_raw = get_field('schedule_date_time', $pid);
            }
        } else {
            // fallback to post meta
            $schedule_raw = get_post_meta($pid, 're-schedule', true);
            if (empty($schedule_raw)) {
                $schedule_raw = get_post_meta($pid, 'schedule_date_time', true);
            }
        }
        $schedule_ts = self::parse_date_for_chart($schedule_raw);

        // require both timestamps
        if (!$completed_ts || !$schedule_ts) {
            $skipped_ids[] = $pid;
            continue;
        }

        $days = ($completed_ts - $schedule_ts) / 86400;

        // Skip negative durations (treat as data error) to avoid a single bad row producing huge negative averages.
        if ($days < 0) {
            $skipped_ids[] = $pid;
            continue;
        }

        $author_id = get_post_field('post_author', $pid);
        if (!isset($accumulators[$author_id])) {
            $accumulators[$author_id] = ['total_days' => 0.0, 'count' => 0];
        }
        $accumulators[$author_id]['total_days'] += $days;
        $accumulators[$author_id]['count'] += 1;
    }

    // Compute averages and prepare labels
    $averages = [];
    $labels_map = [];
    foreach ($accumulators as $uid => $data) {
        if ($data['count'] <= 0) continue;
        $avg = $data['total_days'] / $data['count'];
        // round to 2 decimals
        $avg = round($avg, 2);
        $averages[$uid] = $avg;
        $user = get_user_by('id', $uid);
        $labels_map[$uid] = $user ? $user->display_name : 'Unknown';
    }

    if (empty($averages)) {
        wp_send_json_success(['labels' => [], 'averages' => [], 'uids' => [], 'skipped_ids' => array_values($skipped_ids)]);
    }

    // Sort by average descending
    arsort($averages);

    $labels = [];
    $avgs = [];
    $uids = [];
    foreach ($averages as $uid => $avg) {
        $labels[] = $labels_map[$uid] ?? 'Unknown';
        $avgs[] = $avg;
        $uids[] = $uid;
    }

    wp_send_json_success(['labels' => $labels, 'averages' => $avgs, 'uids' => $uids, 'skipped_ids' => array_values($skipped_ids)]);
}

    /**
     * Robust date parsing: try Y-m-d, d/m/Y, m/d/Y, fallback strtotime
     */
    private static function parse_date_for_chart($raw_date)
    {
        if (!$raw_date) return false;
        $raw_date = trim(str_replace('_x000D_', "\n", $raw_date));
        // Try YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw_date, $m)) {
            return strtotime($raw_date);
        }
        // Try YYYY-MM-DD HH:MM
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+\d{2}:\d{2}/', $raw_date, $m)) {
            return strtotime($raw_date);
        }
        // Try MM/DD/YYYY
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw_date, $m)) {
            return strtotime("{$m[3]}-{$m[1]}-{$m[2]}");
        }
        // Try DD-MM-YYYY
        if (preg_match('/^(\d{1,2})\-(\d{1,2})\-(\d{4})$/', $raw_date, $m)) {
            return strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
        }
        // Fallback
        $ts = strtotime($raw_date);
        return $ts ? $ts : false;
    }

    /**
     * Render modal CSS styles
     */
    private static function render_modal_styles()
    {
        ?>
        <style>
        /* Clickable count link styles */
        .dq-wo-count-link {
            color: #0996a0;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 1px dashed #0996a0;
            transition: all 0.2s ease;
        }
        .dq-wo-count-link:hover {
            color: #067a82;
            border-bottom-color: #067a82;
        }
        
        /* Modal overlay */
        .dq-wo-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 99999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .dq-wo-modal-overlay.active {
            display: block;
            opacity: 1;
        }
        
        /* Modal container */
        .dq-wo-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            z-index: 100000;
            max-width: 95vw;
            width: 1200px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* Modal header */
        .dq-wo-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            background: #0996a0;
            color: #fff;
            flex-shrink: 0;
        }
        .dq-wo-modal-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #fff;
        }
        .dq-wo-modal-close {
            background: transparent;
            border: none;
            color: #fff;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        .dq-wo-modal-close:hover {
            opacity: 1;
        }
        
        /* Modal body */
        .dq-wo-modal-body {
            padding: 20px 24px;
            overflow-y: auto;
            flex-grow: 1;
        }
        
        /* Modal loading state */
        .dq-wo-modal-loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .dq-wo-modal-loading:after {
            content: '';
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 3px solid #ddd;
            border-top-color: #0996a0;
            border-radius: 50%;
            animation: dq-spin 0.8s linear infinite;
            margin-left: 10px;
            vertical-align: middle;
        }
        @keyframes dq-spin {
            to { transform: rotate(360deg); }
        }
        
        /* Modal table */
        .dq-wo-modal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .dq-wo-modal-table th {
            background: #f5f5f5;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #ddd;
            white-space: nowrap;
        }
        .dq-wo-modal-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        .dq-wo-modal-table tbody tr:hover td {
            background: #f9f9f9;
        }
        .dq-wo-modal-table .wo-view-btn {
            display: inline-block;
            padding: 4px 12px;
            background: #0996a0;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            transition: background 0.2s;
        }
        .dq-wo-modal-table .wo-view-btn:hover {
            background: #067a82;
        }
        
        /* Modal pagination */
        .dq-wo-modal-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 16px 24px;
            background: #f9f9f9;
            border-top: 1px solid #eee;
            flex-shrink: 0;
        }
        .dq-wo-modal-pagination a,
        .dq-wo-modal-pagination span {
            display: inline-block;
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            background: #fff;
            font-size: 13px;
            transition: all 0.2s;
        }
        .dq-wo-modal-pagination a:hover {
            background: #0996a0;
            color: #fff;
            border-color: #0996a0;
        }
        .dq-wo-modal-pagination .current {
            background: #0996a0;
            color: #fff;
            border-color: #0996a0;
            font-weight: 600;
        }
        .dq-wo-modal-pagination .disabled {
            opacity: 0.4;
            pointer-events: none;
        }
        .dq-wo-modal-pagination .ellipsis {
            border: none;
            background: transparent;
        }
        
        /* Empty state */
        .dq-wo-modal-empty {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }

        /* Modal info bar */
        .dq-wo-modal-info {
            background: #e5f4fa;
            padding: 10px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
            color: #333;
        }
        .dq-wo-modal-info strong {
            color: #0996a0;
        }
        </style>
        <?php
    }

    /**
     * Render modal HTML container
     */
    private static function render_modal_html()
    {
        ?>
        <div class="dq-wo-modal-overlay" id="dq-wo-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="dq-wo-modal-title">
            <div class="dq-wo-modal">
                <div class="dq-wo-modal-header">
                    <h2 id="dq-wo-modal-title">Work Orders</h2>
                    <button type="button" class="dq-wo-modal-close" aria-label="Close modal">&times;</button>
                </div>
                <div class="dq-wo-modal-body" id="dq-wo-modal-body">
                    <div class="dq-wo-modal-loading">Loading work orders...</div>
                </div>
                <div class="dq-wo-modal-pagination" id="dq-wo-modal-pagination" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render modal JavaScript
     */
    private static function render_modal_script($year)
    {
        $nonce = wp_create_nonce('dq_workorder_modal');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <script>
        (function() {
            const overlay = document.getElementById('dq-wo-modal-overlay');
            const modalBody = document.getElementById('dq-wo-modal-body');
            const modalPagination = document.getElementById('dq-wo-modal-pagination');
            const modalTitle = document.getElementById('dq-wo-modal-title');
            const closeBtn = overlay ? overlay.querySelector('.dq-wo-modal-close') : null;
            const ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
            const nonce = '<?php echo esc_js($nonce); ?>';
            const defaultYear = '<?php echo intval($year); ?>';
            
            let currentFilters = {};
            let currentPage = 1;

            // Open modal
            function openModal(filters, title) {
                currentFilters = filters;
                currentPage = 1;
                if (modalTitle) modalTitle.textContent = title || 'Work Orders';
                if (overlay) {
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
                loadWorkorders();
            }

            // Close modal
            function closeModal() {
                if (overlay) {
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
                currentFilters = {};
                currentPage = 1;
            }

            // Load workorders via AJAX
            function loadWorkorders(page) {
                if (page) currentPage = page;
                
                if (modalBody) {
                    modalBody.innerHTML = '<div class="dq-wo-modal-loading">Loading work orders...</div>';
                }
                if (modalPagination) {
                    modalPagination.style.display = 'none';
                    modalPagination.innerHTML = '';
                }

                const formData = new FormData();
                formData.append('action', 'dq_workorder_modal');
                formData.append('nonce', nonce);
                formData.append('page', currentPage);
                
                // Add all filters
                for (const key in currentFilters) {
                    formData.append(key, currentFilters[key]);
                }

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        if (modalBody) {
                            modalBody.innerHTML = data.data.html || '<div class="dq-wo-modal-empty">No work orders found.</div>';
                        }
                        if (modalPagination && data.data.pagination) {
                            modalPagination.innerHTML = data.data.pagination;
                            modalPagination.style.display = data.data.max_pages > 1 ? 'flex' : 'none';
                        }
                    } else {
                        if (modalBody) {
                            modalBody.innerHTML = '<div class="dq-wo-modal-empty">Error loading work orders.</div>';
                        }
                    }
                })
                .catch(err => {
                    console.error('Modal AJAX error:', err);
                    if (modalBody) {
                        modalBody.innerHTML = '<div class="dq-wo-modal-empty">Error loading work orders.</div>';
                    }
                });
            }

            // Build title based on filter type
            function buildTitle(filterType, data) {
                const year = data.year || defaultYear;
                switch (filterType) {
                    case 'month':
                        const months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 
                                       'July', 'August', 'September', 'October', 'November', 'December'];
                        return 'Work Orders - ' + months[parseInt(data.month)] + ' ' + year;
                    case 'state':
                        return 'Work Orders - ' + data.state + ' (' + year + ')';
                    case 'engineer':
                        return 'Work Orders by Engineer (' + year + ')';
                    case 'lead_category':
                        return 'Work Orders - Lead Category: ' + data.leadCategory + ' (' + year + ')';
                    case 'reschedule_reason':
                        return 'Rescheduled Work Orders - ' + data.rescheduleReason + ' (' + year + ')';
                    case 'rescheduled':
                        return 'Rescheduled Work Orders (' + year + ')';
                    case 'leads_converted':
                        return 'Work Orders Converted to Leads (' + year + ')';
                    case 'received':
                        return 'Work Orders Received (' + year + ')';
                    case 'completed':
                        return 'Completed Work Orders (' + year + ')';
                    case 'year':
                        return 'All Work Orders (' + year + ')';
                    default:
                        return 'Work Orders (' + year + ')';
                }
            }

            // Event: Click on count link
            document.addEventListener('click', function(e) {
                const link = e.target.closest('.dq-wo-count-link');
                if (link) {
                    e.preventDefault();
                    
                    const filterType = link.dataset.filterType;
                    const filters = {
                        filter_type: filterType,
                        year: link.dataset.year || defaultYear
                    };

                    // Add specific filter data
                    if (link.dataset.month) filters.month = link.dataset.month;
                    if (link.dataset.state) filters.state = link.dataset.state;
                    if (link.dataset.engineer) filters.engineer = link.dataset.engineer;
                    if (link.dataset.leadCategory) filters.lead_category = link.dataset.leadCategory;
                    if (link.dataset.rescheduleReason) filters.reschedule_reason = link.dataset.rescheduleReason;

                    const title = buildTitle(filterType, {
                        year: filters.year,
                        month: filters.month,
                        state: filters.state,
                        leadCategory: link.dataset.leadCategory,
                        rescheduleReason: link.dataset.rescheduleReason
                    });

                    openModal(filters, title);
                }
            });

            // Event: Close button click
            if (closeBtn) {
                closeBtn.addEventListener('click', closeModal);
            }

            // Event: Overlay click (close when clicking outside modal)
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        closeModal();
                    }
                });
            }

            // Event: Escape key to close
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && overlay && overlay.classList.contains('active')) {
                    closeModal();
                }
            });

            // Event: Pagination clicks (delegated)
            if (modalPagination) {
                modalPagination.addEventListener('click', function(e) {
                    const pageLink = e.target.closest('a[data-page]');
                    if (pageLink && !pageLink.classList.contains('disabled')) {
                        e.preventDefault();
                        const page = parseInt(pageLink.dataset.page);
                        if (page && page > 0) {
                            loadWorkorders(page);
                        }
                    }
                });
            }

            // Expose a global function so other scripts (charts) can open the modal with filters
            window.dqOpenModalWithFilters = function(filters, title) {
                try {
                    openModal(filters, title);
                } catch (err) {
                    console.error('dqOpenModalWithFilters error:', err);
                }
            };
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler for modal workorder list
     */
    public static function ajax_workorder_modal()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied', 403);
        }
        check_ajax_referer('dq_workorder_modal', 'nonce');

        $filter_type = isset($_POST['filter_type']) ? sanitize_text_field($_POST['filter_type']) : '';
        $year = isset($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));
        $month = isset($_POST['month']) ? intval($_POST['month']) : 0;
        $state = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '';
        $engineer = isset($_POST['engineer']) ? intval($_POST['engineer']) : 0;
        $lead_category = isset($_POST['lead_category']) ? sanitize_text_field($_POST['lead_category']) : '';
        $reschedule_reason = isset($_POST['reschedule_reason']) ? sanitize_text_field($_POST['reschedule_reason']) : '';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = self::MODAL_PER_PAGE;

        // Get base workorders for the year
        $workorders = self::get_workorders_in_year($year);

        // Apply filters based on filter_type
        $filtered_ids = self::filter_workorders($workorders, $filter_type, [
            'year' => $year,
            'month' => $month,
            'state' => $state,
            'engineer' => $engineer,
            'lead_category' => $lead_category,
            'reschedule_reason' => $reschedule_reason,
        ]);

        $total = count($filtered_ids);
        $max_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        $paged_ids = array_slice($filtered_ids, $offset, $per_page);

        // Render HTML
        $html = self::render_modal_workorders_html($paged_ids, $total, $page, $per_page);
        $pagination = self::render_modal_pagination($page, $max_pages);

        wp_send_json_success([
            'html' => $html,
            'pagination' => $pagination,
            'total' => $total,
            'max_pages' => $max_pages,
            'current_page' => $page,
        ]);
    }

    /**
     * Filter workorders based on filter type
     */
    private static function filter_workorders($workorder_ids, $filter_type, $filters)
    {
        $year = $filters['year'];
        $filtered = [];

        foreach ($workorder_ids as $pid) {
            $include = false;

            switch ($filter_type) {
                case 'month':
                    // Filter by month
                    $terms = get_the_terms($pid, 'status');
                    $status_slug = '';
                    if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
                        $term = array_shift($terms);
                        $status_slug = !empty($term->slug) ? $term->slug : '';
                    }
                    $raw_date = '';
                    if ($status_slug === 'open') {
                        $raw_date = function_exists('get_field') ? get_field('date_requested_by_customer', $pid) : get_post_meta($pid, 'date_requested_by_customer', true);
                    } elseif ($status_slug === 'close') {
                        $raw_date = function_exists('get_field') ? get_field('closed_on', $pid) : get_post_meta($pid, 'closed_on', true);
                    } elseif ($status_slug === 'scheduled') {
                        $raw_date = function_exists('get_field') ? get_field('schedule_date_time', $pid) : get_post_meta($pid, 'schedule_date_time', true);
                    }
                    if (!$raw_date) $raw_date = get_post_field('post_date', $pid);
                    $month_num = self::parse_month_from_text($raw_date, $year);
                    $include = ($month_num !== false && $month_num == $filters['month']);
                    break;

                case 'state':
                    $wo_state = function_exists('get_field') ? get_field('wo_state', $pid) : get_post_meta($pid, 'wo_state', true);
                    if (!$wo_state) $wo_state = '[No State]';
                    $include = ($wo_state === $filters['state']);
                    break;

                case 'engineer':
                    $author_id = get_post_field('post_author', $pid);
                    $include = ($author_id == $filters['engineer']);
                    break;

                case 'lead_category':
                    $cat = function_exists('get_field') ? get_field('wo_lead_category', $pid) : get_post_meta($pid, 'wo_lead_category', true);
                    if (!$cat) $cat = '[No Lead Category]';
                    $include = ($cat === $filters['lead_category']);
                    break;

                case 'reschedule_reason':
                    $reason = function_exists('get_field') ? get_field('rescheduled_reason', $pid) : get_post_meta($pid, 'rescheduled_reason', true);
                    $include = ($reason === $filters['reschedule_reason']);
                    break;

                case 'rescheduled':
                    $rescheduled_date = function_exists('get_field') ? get_field('re-schedule', $pid) : get_post_meta($pid, 're-schedule', true);
                    $include = !empty($rescheduled_date);
                    break;

                case 'leads_converted':
                    $lead_value = function_exists('get_field') ? get_field('wo_leads', $pid) : get_post_meta($pid, 'wo_leads', true);
                    $include = (strcasecmp(trim($lead_value), 'Yes') === 0);
                    break;

                case 'received':
                    $date_received = function_exists('get_field') ? get_field('wo_date_received', $pid) : get_post_meta($pid, 'wo_date_received', true);
                    $include = !empty($date_received);
                    break;

                case 'completed':
                    $closed_on = function_exists('get_field') ? get_field('closed_on', $pid) : get_post_meta($pid, 'closed_on', true);
                    $terms = get_the_terms($pid, 'status');
                    $status_slug = '';
                    if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
                        $term = array_shift($terms);
                        $status_slug = !empty($term->slug) ? $term->slug : '';
                    }
                    $include = ($closed_on || $status_slug === 'close');
                    break;

                case 'year':
                default:
                    $include = true;
                    break;
            }

            if ($include) {
                $filtered[] = $pid;
            }
        }

        return $filtered;
    }

    /**
     * Render workorders HTML for modal
     */
    private static function render_modal_workorders_html($workorder_ids, $total, $page, $per_page)
    {
        if (empty($workorder_ids)) {
            return '<div class="dq-wo-modal-empty">No work orders found matching your criteria.</div>';
        }

        $start = (($page - 1) * $per_page) + 1;
        $end = min($page * $per_page, $total);

        $html = '<div class="dq-wo-modal-info">Showing <strong>' . $start . '-' . $end . '</strong> of <strong>' . $total . '</strong> work orders</div>';
        $html .= '<table class="dq-wo-modal-table">';
        $html .= '<thead><tr>';
        $html .= '<th>Work Order ID</th>';
        $html .= '<th>Product ID</th>';
        $html .= '<th>Location</th>';
        $html .= '<th>Engineer</th>';
        $html .= '<th>Invoice #</th>';
        $html .= '<th>Date</th>';
        $html .= '<th>Action</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($workorder_ids as $pid) {
            $title = get_the_title($pid);
            $installed_product_id = function_exists('get_field') ? get_field('installed_product_id', $pid) : get_post_meta($pid, 'installed_product_id', true);
            $wo_location = function_exists('get_field') ? get_field('wo_location', $pid) : get_post_meta($pid, 'wo_location', true);
            $wo_state = function_exists('get_field') ? get_field('wo_state', $pid) : get_post_meta($pid, 'wo_state', true);
            
            $author_id = get_post_field('post_author', $pid);
            $user = get_user_by('id', $author_id);
            $engineer_name = $user ? $user->display_name : 'Unknown';

            // Get invoice number
            $wo_invoice_no = function_exists('get_field') ? get_field('wo_invoice_no', $pid) : get_post_meta($pid, 'wo_invoice_no', true);

            // Get relevant date
            $date_requested = function_exists('get_field') ? get_field('date_requested_by_customer', $pid) : get_post_meta($pid, 'date_requested_by_customer', true);
            $date_display = $date_requested ? date('m/d/Y', strtotime($date_requested)) : date('m/d/Y', strtotime(get_post_field('post_date', $pid)));

            $edit_link = admin_url('post.php?post=' . $pid . '&action=edit');

            $html .= '<tr>';
            $html .= '<td><strong>' . esc_html($title) . '</strong></td>';
            $html .= '<td>' . esc_html($installed_product_id ?: '-') . '</td>';
            $html .= '<td>' . esc_html(($wo_location ?: '') . ($wo_state ? ', ' . $wo_state : '')) . '</td>';
            $html .= '<td>' . esc_html($engineer_name) . '</td>';
            $html .= '<td>' . esc_html($wo_invoice_no ?: '-') . '</td>';
            $html .= '<td>' . esc_html($date_display) . '</td>';
            $html .= '<td><a href="' . esc_url($edit_link) . '" class="wo-view-btn" target="_blank">View</a></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Render pagination for modal
     */
    private static function render_modal_pagination($current_page, $max_pages)
    {
        if ($max_pages <= 1) {
            return '';
        }

        $html = '';

        // Previous button
        $prev_class = ($current_page <= 1) ? 'disabled' : '';
        $html .= '<a href="#" class="' . $prev_class . '" data-page="' . ($current_page - 1) . '">« Previous</a>';

        // Page numbers
        $range = 2;
        $start = max(1, $current_page - $range);
        $end = min($max_pages, $current_page + $range);

        // First page + ellipsis
        if ($start > 1) {
            $html .= '<a href="#" data-page="1">1</a>';
            if ($start > 2) {
                $html .= '<span class="ellipsis">...</span>';
            }
        }

        // Page numbers
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $current_page) {
                $html .= '<span class="current">' . $i . '</span>';
            } else {
                $html .= '<a href="#" data-page="' . $i . '">' . $i . '</a>';
            }
        }

        // Last page + ellipsis
        if ($end < $max_pages) {
            if ($end < $max_pages - 1) {
                $html .= '<span class="ellipsis">...</span>';
            }
            $html .= '<a href="#" data-page="' . $max_pages . '">' . $max_pages . '</a>';
        }

        // Next button
        $next_class = ($current_page >= $max_pages) ? 'disabled' : '';
        $html .= '<a href="#" class="' . $next_class . '" data-page="' . ($current_page + 1) . '">Next »</a>';

        return $html;
    }
}

add_action('init', function () {
    if (class_exists('DQ_WorkOrder_Report')) DQ_WorkOrder_Report::init();
});