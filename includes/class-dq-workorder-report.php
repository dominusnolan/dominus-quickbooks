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
 */
class DQ_WorkOrder_Report
{
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
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
    }

    public static function main_dashboard()
    {
        $year = self::get_selected_year();
        $workorders = self::get_workorders_in_year($year);

        $monthly_counts = self::get_monthly_workorder_counts_from_workorders($workorders, $year);
        $state_counts = self::get_state_workorder_counts($workorders);
        $engineer_data = self::get_engineer_data($workorders);

        echo '<div class="wrap"><h1>WorkOrder Monthly Summary</h1>';
        self::filters_form($year);
        self::render_monthly_table($monthly_counts, $year);
        self::render_state_table($state_counts);
        self::render_engineer_table($engineer_data);
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

    /**
     * Returns array of workorder IDs within the selected year filter.
     */
    private static function get_workorders_in_year($year)
    {
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
        ];
        return get_posts($query);
    }

    /**
     * Returns array [1=>count, ..., 12=>count] for months, based on date field logic.
     */
    private static function get_monthly_workorder_counts_from_workorders($workorders, $year)
    {
        $counts = [];
        for ($m = 1; $m <= 12; $m++) $counts[$m] = 0;
        foreach ($workorders as $pid) {
            // Get status taxonomy term slug
            $terms = get_the_terms($pid, 'status');
            $status_slug = '';
            if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
                $term = array_shift($terms);
                $status_slug = !empty($term->slug) ? $term->slug : (is_object($term) && isset($term->name) ? sanitize_title($term->name) : '');
            }
            // Use correct date field based on status taxonomy
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

    /**
     * Attempts to robustly extract month (1-12) from a date string for the selected year.
     */
    private static function parse_month_from_text($raw_date, $year)
    {
        if (!$raw_date) return false;
        $raw_date = trim(str_replace('_x000D_', "\n", $raw_date));

        // Try YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw_date, $m)) {
            if (intval($m[1]) == $year) return intval($m[2]);
        }
        // Try YYYY-MM-DD HH:MM
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+\d{2}:\d{2}/', $raw_date, $m)) {
            if (intval($m[1]) == $year) return intval($m[2]);
        }
        // Try MM/DD/YYYY (US)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw_date, $m)) {
            if (intval($m[3]) == $year) return intval($m[1]);
        }
        // Try DD-MM-YYYY (EU)
        if (preg_match('/^(\d{1,2})\-(\d{1,2})\-(\d{4})$/', $raw_date, $m)) {
            if (intval($m[3]) == $year) return intval($m[2]);
        }
        // Try parsing with strtotime (last fallback)
        $ts = strtotime($raw_date);
        if ($ts) {
            $dtYear = intval(date('Y', $ts));
            $dtMonth = intval(date('n', $ts));
            if ($dtYear == $year) return $dtMonth;
        }
        return false;
    }

    /**
     * Returns array: [state_name=>count,...] for workorders in filter.
     */
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

    /**
     * Returns array with engineer_id as key: ['user_id', 'display_name', 'profile_picture', 'wo_count', 'percentage']
     */
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
        // Calculate percentage
        foreach ($engineers as $uid => &$data) {
            $data['percentage'] = ($total_wo > 0) ? round($data['wo_count'] * 100 / $total_wo, 2) : 0;
        }
        // Sort by WO count descending
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
        .wo-monthly-table {width:350px; border-collapse:collapse; background:#fff; margin-top:22px;}
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
        .wo-state-table {width:350px; border-collapse:collapse; background:#fff; margin-top:22px;}
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
}

add_action('init', function () {
    if (class_exists('DQ_WorkOrder_Report')) DQ_WorkOrder_Report::init();
});