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
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('wp_ajax_dq_fse_chart', [__CLASS__, 'ajax_fse_chart']);
        add_action('wp_ajax_dq_engineer_monthly', [__CLASS__, 'ajax_engineer_monthly']);
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

        echo '<div class="table-set-1 flex-container">';
            echo '<div class="flex-item">';
                self::render_monthly_table($monthly_counts, $year);
            echo '</div>';
            echo '<div class="flex-item">';
                self::render_state_table($monthly_counts, $year);
            echo '</div>';
            echo '<div class="flex-item">';   
            self::render_engineer_table($engineer_data);
            echo '</div>';
            echo '<div class="flex-item">';  
            self::render_engineer_ajax_selector($engineer_data, $year);
            echo '</div>';
        echo '</div>'; 
        
    
        self::render_leads_converted_table($workorders);
        self::render_lead_category_table($workorders);

        self::render_reschedule_reasons_table($workorders);
        self::render_rescheduled_orders_table($workorders);

       

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
            echo '<td style="text-align:right;">'.intval($ct).'</td>';
            echo '</tr>';
        }
        // Yearly total row
        echo '<tr>';
        echo '<td>Yearly Total</td>';
        echo '<td style="text-align:right;">'.intval($yearly_total).'</td>';
        echo '</tr>';
        ?>
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
                <td style="text-align:right;"><?php echo intval($rescheduled_count); ?></td>
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
                    <td style="text-align:right;"><?php echo intval($leads_yes); ?></td>
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
            echo '<td style="text-align:right;">'.intval($ct).'</td>';
            echo '<td style="text-align:right;">'.number_format($pct,2).'%'."</td>";
            echo '</tr>';
        }
        // Total row
        echo '<tr>';
        echo '<td>Total</td>';
        echo '<td style="text-align:right;">'.intval($grand_total).'</td>';
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
                    <td><?php echo intval($monthly_counts[$num]); ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="totals-row">
                    <td>Total</td>
                    <td><?php echo intval($total); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private static function render_state_table($state_counts)
    {
        $total = array_sum($state_counts);
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
                    <td><?php echo intval($count); ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="totals-row">
                    <td>Total</td>
                    <td><?php echo intval($total); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private static function render_engineer_table($engineers)
    {
        $total_wo = 0;
        foreach($engineers as $eng) $total_wo += $eng['wo_count'];
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
                    <td><?php echo intval($eng['wo_count']); ?></td>
                    <td class="percent-cell"><?php echo number_format($eng['percentage'], 2); ?>%</td>
                </tr>
            <?php endforeach; ?>
                <tr class="totals-row">
                    <td>Total</td>
                    <td><?php echo intval($total_wo); ?></td>
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

        // JS for AJAX chart rendering
        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        (function(){
            let form = document.getElementById('dq-fse-filter-form');
            let chartContainer = document.getElementById('dq-fse-chart');
            let chart;
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
            function ajaxLoad() {
                if (!form) return;
                const year = form.querySelector('[name="fse_year"]').value;
                const quarter = form.querySelector('[name="fse_quarter"]').value;
                chartContainer.style.opacity = "0.5";
                fetch(ajaxurl, {
                    method: 'POST',
                    credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=dq_fse_chart&fse_year='+encodeURIComponent(year)+'&fse_quarter='+encodeURIComponent(quarter)
                })
                .then(resp => resp.json())
                .then(data => {
                    chartContainer.style.opacity = "1";
                    if (data && data.success && data.data) {
                        renderChart(data.data);
                    }
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
}

add_action('init', function () {
    if (class_exists('DQ_WorkOrder_Report')) DQ_WorkOrder_Report::init();
});