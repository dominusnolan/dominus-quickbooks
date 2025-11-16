<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Financial Reports (Monthly / Quarterly / Yearly) aggregated by Field Engineer.
 *
 * Columns:
 * 1. Field Engineer (author of linked Work Order via qi_wo_number) with profile_picture or avatar
 * 2. Total Invoices (count of quickbooks_invoice posts in date range, link to detail list)
 * 3. Invoice Amount (sum qi_total_billed)
 * 4. Labor Cost (sum of qi_invoice repeater rows where activity == "Labor Rate HR")
 * 5. Direct Labor Cost (sum of qi_other_expenses repeater amount)
 * 6. Travel Cost (sum of qi_invoice rows where activity in Travel Zone 1|2|3)
 * 7. Toll, Meals, Parking (sum qi_invoice rows where activity == "Toll, Meals, Parking")
 * 8. Profit (Invoice Amount - Direct Labor Cost)
 *
 * Date filtering based on ACF field qi_invoice_date (assumed Y-m-d or convertible).
 */
class DQ_Financial_Report {

    // Field name constants (adjust if they differ)
    const FIELD_DATE           = 'qi_invoice_date';
    const FIELD_TOTAL_BILLED   = 'qi_total_billed';
    const FIELD_LINES_REPEATER = 'qi_invoice';
    const FIELD_LINES_ACTIVITY = 'activity';
    const FIELD_LINES_AMOUNT   = 'amount';
    const FIELD_OTHER_EXPENSES = 'qi_other_expenses';
    const FIELD_OTHER_AMOUNT   = 'amount';
    const FIELD_WO_RELATION    = 'qi_wo_number';
    const USER_AVATAR_FIELD    = 'profile_picture';

    // Activity labels
    const ACTIVITY_LABOR       = 'Labor Rate HR';
    const ACTIVITY_TRAVEL      = ['Travel Zone 1','Travel Zone 2','Travel Zone 3'];
    const ACTIVITY_TOLLS       = 'Toll, Meals, Parking';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_post_dq_financial_report_csv', [ __CLASS__, 'handle_csv' ] );
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=quickbooks_invoice',
            'Financial Report',
            'Financial Report',
            'view_financial_reports',
            'dq-financial-report',
            [ __CLASS__, 'render_page' ]
        );
    }

    private static function user_can_view() : bool {
        return current_user_can( 'view_financial_reports' ) || current_user_can( 'manage_options' );
    }

    public static function render_page() {
        if ( ! self::user_can_view() ) {
            wp_die( 'Insufficient permissions.' );
        }

        $report  = isset($_GET['report']) ? sanitize_key($_GET['report']) : 'monthly';
        $year    = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $month   = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
        $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : 1;
        $engineer_filter = isset($_GET['engineer']) ? intval($_GET['engineer']) : 0;

        // Clamp values
        if ( $year < 2000 || $year > 2100 ) $year = intval(date('Y'));
        if ( $month < 1 || $month > 12 ) $month = intval(date('n'));
        if ( $quarter < 1 || $quarter > 4 ) $quarter = 1;
        if ( ! in_array( $report, ['monthly','quarterly','yearly'], true ) ) $report = 'monthly';

        $range = self::compute_date_range( $report, $year, $month, $quarter );
        $data  = self::aggregate( $range['start'], $range['end'] );

        // Sort engineers alphabetically by display name
        uasort( $data, function( $a, $b ) {
            return strcasecmp( $a['display_name'], $b['display_name'] );
        });

        $heading = ucfirst($report) . ' Report';
        $subheading = self::human_date_label( $report, $year, $month, $quarter );

        $csv_url = wp_nonce_url(
            admin_url('admin-post.php?action=dq_financial_report_csv&report=' . urlencode($report) . '&year=' . $year . '&month=' . $month . '&quarter=' . $quarter ),
            'dq_fr_csv'
        );

        echo '<div class="wrap"><h1>' . esc_html( $heading ) . ' <small style="font-weight:normal;">' . esc_html($subheading) . '</small></h1>';
        self::filters_form( $report, $year, $month, $quarter );
        echo '<p><a class="button" href="' . esc_url( $csv_url ) . '">Download CSV</a></p>';

        self::render_table( $data, $engineer_filter, $range['start'], $range['end'], $report, $year, $month, $quarter );
        echo '</div>';
    }

    private static function filters_form( $report, $year, $month, $quarter ) {
        $years = range( date('Y') - 5, date('Y') + 1 );
        echo '<form method="get" style="margin:15px 0;display:flex;gap:12px;align-items:flex-end;">';
        echo '<input type="hidden" name="post_type" value="quickbooks_invoice">';
        echo '<input type="hidden" name="page" value="dq-financial-report">';

        // Report type
        echo '<div><label style="font-weight:600;">Type<br><select name="report">';
        foreach ( ['monthly'=>'Monthly','quarterly'=>'Quarterly','yearly'=>'Yearly'] as $val=>$label ) {
            printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($report,$val,false), esc_html($label));
        }
        echo '</select></label></div>';

        // Month (monthly only)
        echo '<div><label style="font-weight:600;">Month<br><select name="month" ' . ( $report==='monthly' ? '' : 'disabled' ) . '>';
        for ( $m=1; $m<=12; $m++ ) {
            printf('<option value="%d"%s>%s</option>', $m, selected($month,$m,false), date('F', mktime(0,0,0,$m,1)));
        }
        echo '</select></label></div>';

        // Quarter (quarterly only)
        echo '<div><label style="font-weight:600;">Quarter<br><select name="quarter" ' . ( $report==='quarterly' ? '' : 'disabled' ) . '>';
        for ( $q=1; $q<=4; $q++ ) {
            printf('<option value="%d"%s>Q%d</option>', $q, selected($quarter,$q,false), $q);
        }
        echo '</select></label></div>';

        // Year
        echo '<div><label style="font-weight:600;">Year<br><select name="year">';
        foreach ( $years as $y ) {
            printf('<option value="%d"%s>%d</option>', $y, selected($year,$y,false), $y);
        }
        echo '</select></label></div>';

        echo '<div><br><input type="submit" class="button button-primary" value="Filter"></div>';
        echo '</form>';
    }

    private static function compute_date_range( string $report, int $year, int $month, int $quarter ) : array {
        switch ( $report ) {
            case 'yearly':
                $start = "$year-01-01";
                $end   = "$year-12-31";
                break;
            case 'quarterly':
                $first_month = ( ($quarter - 1) * 3 ) + 1;
                $start = date('Y-m-d', mktime(0,0,0,$first_month,1,$year));
                $last_month = $first_month + 2;
                $end_day = date('t', mktime(0,0,0,$last_month,1,$year));
                $end = date('Y-m-d', mktime(0,0,0,$last_month,$end_day,$year));
                break;
            case 'monthly':
            default:
                $start = date('Y-m-d', mktime(0,0,0,$month,1,$year));
                $end_day = date('t', mktime(0,0,0,$month,1,$year));
                $end   = date('Y-m-d', mktime(0,0,0,$month,$end_day,$year));
        }
        return ['start'=>$start,'end'=>$end];
    }

    private static function human_date_label( $report, $year, $month, $quarter ) {
        if ( $report === 'monthly' ) {
            return date('F', mktime(0,0,0,$month,1,$year)) . ' ' . $year;
        }
        if ( $report === 'quarterly' ) {
            return 'Q' . $quarter . ' ' . $year;
        }
        return (string)$year;
    }

    /**
     * Aggregate data between start/end inclusive.
     * @return array keyed by engineer user_id
     */
    private static function aggregate( string $start, string $end ) : array {
        $out = [];

        // Get ALL invoice posts with a date (we can refine with a meta_query if needed)
        $invoices = get_posts([
            'post_type'      => 'quickbooks_invoice',
            'post_status'    => ['publish','draft','pending','private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::FIELD_DATE,
        ]);

        foreach ( $invoices as $pid ) {
            $date_raw = function_exists('get_field') ? get_field( self::FIELD_DATE, $pid ) : get_post_meta( $pid, self::FIELD_DATE, true );
            $date_norm = self::normalize_date( $date_raw );
            if ( ! $date_norm ) continue;
            if ( $date_norm < $start || $date_norm > $end ) continue;

            // Work Order relation
            $wo_field = function_exists('get_field') ? get_field( self::FIELD_WO_RELATION, $pid ) : get_post_meta( $pid, self::FIELD_WO_RELATION, true );
            $wo_post = null;
            if ( is_array( $wo_field ) ) {
                // Relationship field may return array of posts
                $wo_post = reset( $wo_field );
            } elseif ( $wo_field instanceof WP_Post ) {
                $wo_post = $wo_field;
            } elseif ( is_numeric( $wo_field ) ) {
                $wo_post = get_post( intval( $wo_field ) );
            }
            if ( ! $wo_post || $wo_post->post_type !== 'workorder' ) {
                // skip invoices not linked to a work order for aggregation
                continue;
            }
            $engineer_id = intval( $wo_post->post_author );
            if ( $engineer_id <= 0 ) continue;

            if ( ! isset( $out[ $engineer_id ] ) ) {
                $user = get_user_by( 'id', $engineer_id );
                $out[ $engineer_id ] = [
                    'display_name' => $user ? $user->display_name : 'User #' . $engineer_id,
                    'invoices'     => [],
                    'count'        => 0,
                    'invoice_amount' => 0.0,
                    'labor_cost'     => 0.0,
                    'direct_labor'   => 0.0,
                    'travel_cost'    => 0.0,
                    'tolls_meals'    => 0.0,
                ];
            }

            $total_billed = self::num( function_exists('get_field') ? get_field( self::FIELD_TOTAL_BILLED, $pid ) : get_post_meta( $pid, self::FIELD_TOTAL_BILLED, true ) );
            $out[ $engineer_id ]['count']++;
            $out[ $engineer_id ]['invoice_amount'] += $total_billed;
            $out[ $engineer_id ]['invoices'][] = [
                'post_id'   => $pid,
                'date'      => $date_norm,
                'number'    => function_exists('get_field') ? get_field('qi_invoice_no', $pid) : get_post_meta($pid,'qi_invoice_no', true),
                'amount'    => $total_billed,
            ];

            // Lines
            $lines = function_exists('get_field') ? get_field( self::FIELD_LINES_REPEATER, $pid ) : get_post_meta( $pid, self::FIELD_LINES_REPEATER, true );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $row ) {
                    $activity = isset( $row[ self::FIELD_LINES_ACTIVITY ] ) ? trim( (string)$row[ self::FIELD_LINES_ACTIVITY ] ) : '';
                    $amount   = self::num( $row[ self::FIELD_LINES_AMOUNT ] ?? 0 );
                    if ( $activity === self::ACTIVITY_LABOR ) {
                        $out[ $engineer_id ]['labor_cost'] += $amount;
                    } elseif ( in_array( $activity, self::ACTIVITY_TRAVEL, true ) ) {
                        $out[ $engineer_id ]['travel_cost'] += $amount;
                    } elseif ( $activity === self::ACTIVITY_TOLLS ) {
                        $out[ $engineer_id ]['tolls_meals'] += $amount;
                    }
                }
            }

            // Direct Labor (other expenses)
            $other = function_exists('get_field') ? get_field( self::FIELD_OTHER_EXPENSES, $pid ) : get_post_meta( $pid, self::FIELD_OTHER_EXPENSES, true );
            if ( is_array( $other ) ) {
                foreach ( $other as $row ) {
                    $amt = self::num( $row[ self::FIELD_OTHER_AMOUNT ] ?? 0 );
                    $out[ $engineer_id ]['direct_labor'] += $amt;
                }
            }
        }

        // Compute profit
        foreach ( $out as $uid => $row ) {
            $out[ $uid ]['profit'] = $row['invoice_amount'] - $row['direct_labor'];
        }

        return $out;
    }

    private static function normalize_date( $raw ) {
        if ( ! $raw ) return '';
        // Attempt standard formats
        $raw = trim( (string)$raw );
        // Already Y-m-d
        if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw ) ) return $raw;
        // Try strtotime
        $ts = strtotime( $raw );
        if ( $ts ) return date('Y-m-d', $ts );
        return '';
    }

    private static function num( $v ) {
        if ( $v === null || $v === '' ) return 0.0;
        if ( is_numeric( $v ) ) return (float)$v;
        $clean = preg_replace('/[^0-9.\-]/','',(string)$v);
        return ( $clean === '' || ! is_numeric($clean) ) ? 0.0 : (float)$clean;
    }

    private static function render_table( array $data, int $engineer_filter, string $start, string $end, string $report, int $year, int $month, int $quarter ) {
        // Totals
        $totals = [
            'count'=>0,
            'invoice_amount'=>0.0,
            'labor_cost'=>0.0,
            'direct_labor'=>0.0,
            'travel_cost'=>0.0,
            'tolls_meals'=>0.0,
            'profit'=>0.0,
        ];

        echo '<style>
        .dq-fr-table { width:100%; border-collapse:collapse; background:#fff; }
        .dq-fr-table th { background:#006d7b; color:#fff; padding:8px 10px; text-align:left; font-weight:600; }
        .dq-fr-table td { padding:8px 10px; border-bottom:1px solid #eee; vertical-align:middle; }
        .dq-fr-table tr:last-child td { border-bottom:none; }
        .dq-fr-avatar { width:32px; height:32px; border-radius:50%; object-fit:cover; margin-right:8px; }
        .dq-fr-name { display:flex; align-items:center; }
        .dq-fr-profit-pos { color:#098400; font-weight:600; }
        .dq-fr-profit-neg { color:#c40000; font-weight:600; }
        .dq-fr-totals-row td { font-weight:600; background:#f2fbfe; }
        .dq-fr-invoice-list { margin:12px 0 24px 0; padding-left:20px; list-style:disc; }
        </style>';

        echo '<table class="dq-fr-table">';
        echo '<thead><tr>';
        $cols = ['Field Engineer','Total Invoices','Invoice Amount','Labor Cost','Direct Labor Cost','Travel Cost','Toll, Meals, Parking','Profit'];
        foreach ( $cols as $c ) echo '<th>' . esc_html($c) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $data as $uid => $row ) {
            $totals['count']          += $row['count'];
            $totals['invoice_amount'] += $row['invoice_amount'];
            $totals['labor_cost']     += $row['labor_cost'];
            $totals['direct_labor']   += $row['direct_labor'];
            $totals['travel_cost']    += $row['travel_cost'];
            $totals['tolls_meals']    += $row['tolls_meals'];
            $totals['profit']         += $row['profit'];

            $avatar_html = self::get_user_avatar_html( $uid );
            $engineer_link = add_query_arg([
                'post_type'=>'quickbooks_invoice',
                'page'=>'dq-financial-report',
                'report'=>$report,
                'year'=>$year,
                'month'=>$month,
                'quarter'=>$quarter,
                'engineer'=>$uid
            ], admin_url('edit.php'));

            echo '<tr>';
            echo '<td><span class="dq-fr-name">' . $avatar_html . esc_html( $row['display_name'] ) . '</span></td>';
            echo '<td><a href="' . esc_url( $engineer_link ) . '">' . intval($row['count']) . '</a></td>';
            echo '<td>' . self::money( $row['invoice_amount'] ) . '</td>';
            echo '<td>' . self::money( $row['labor_cost'] ) . '</td>';
            echo '<td>' . self::money( $row['direct_labor'] ) . '</td>';
            echo '<td>' . self::money( $row['travel_cost'] ) . '</td>';
            echo '<td>' . self::money( $row['tolls_meals'] ) . '</td>';
            $profit_class = $row['profit'] >= 0 ? 'dq-fr-profit-pos' : 'dq-fr-profit-neg';
            echo '<td><span class="' . $profit_class . '">' . self::money( $row['profit'] ) . '</span></td>';
            echo '</tr>';
        }

        // Totals row
        $profit_class_total = $totals['profit'] >= 0 ? 'dq-fr-profit-pos' : 'dq-fr-profit-neg';
        echo '<tr class="dq-fr-totals-row">';
        echo '<td>Totals:</td>';
        echo '<td>' . intval( $totals['count'] ) . '</td>';
        echo '<td>' . self::money( $totals['invoice_amount'] ) . '</td>';
        echo '<td>' . self::money( $totals['labor_cost'] ) . '</td>';
        echo '<td>' . self::money( $totals['direct_labor'] ) . '</td>';
        echo '<td>' . self::money( $totals['travel_cost'] ) . '</td>';
        echo '<td>' . self::money( $totals['tolls_meals'] ) . '</td>';
        echo '<td><span class="' . $profit_class_total . '">' . self::money( $totals['profit'] ) . '</span></td>';
        echo '</tr>';

        echo '</tbody></table>';

        // Invoice detail list for selected engineer
        if ( $engineer_filter && isset( $data[ $engineer_filter ] ) ) {
            echo '<h2 style="margin-top:30px;">Invoice List — ' . esc_html( $data[ $engineer_filter ]['display_name'] ) . '</h2>';
            echo '<ul class="dq-fr-invoice-list">';
            foreach ( $data[ $engineer_filter ]['invoices'] as $inv ) {
                $link = get_edit_post_link( $inv['post_id'] );
                $num  = $inv['number'] ?: ('Post #' . $inv['post_id']);
                echo '<li><a href="' . esc_url($link) . '">' . intval($inv['post_id']) . '</a> #' . esc_html($num) . ' : ' . esc_html($inv['date']) . ' : ' . self::money($inv['amount']) . '</li>';
            }
            echo '</ul>';
        }
    }

    private static function money( $v ) {
        return '$' . number_format( (float)$v, 2 );
    }

    private static function get_user_avatar_html( int $user_id ) {
        if ( function_exists('get_field') ) {
            $image = get_field( self::USER_AVATAR_FIELD, 'user_' . $user_id );
            if ( is_array($image) && ! empty( $image['url'] ) ) {
                return '<img class="dq-fr-avatar" src="' . esc_url( $image['url'] ) . '" alt="" />';
            } elseif ( is_string($image) && filter_var($image, FILTER_VALIDATE_URL) ) {
                return '<img class="dq-fr-avatar" src="' . esc_url( $image ) . '" alt="" />';
            }
        }
        $avatar = get_avatar_url( $user_id, ['size'=>64] );
        return '<img class="dq-fr-avatar" src="' . esc_url( $avatar ) . '" alt="" />';
    }

    /**
     * CSV export handler
     */
    public static function handle_csv() {
        if ( ! self::user_can_view() ) {
            wp_die('Permission denied');
        }
        check_admin_referer( 'dq_fr_csv' );

        $report  = isset($_GET['report']) ? sanitize_key($_GET['report']) : 'monthly';
        $year    = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $month   = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
        $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : 1;
        $range = self::compute_date_range( $report, $year, $month, $quarter );
        $data  = self::aggregate( $range['start'], $range['end'] );

        $filename = 'financial-report-' . $report . '-' . $year;
        if ( $report === 'monthly' ) $filename .= '-' . $month;
        if ( $report === 'quarterly' ) $filename .= '-Q' . $quarter;
        $filename .= '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=' . $filename );
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output','w');
        fputcsv( $out, ['Engineer','Total Invoices','Invoice Amount','Labor Cost','Direct Labor Cost','Travel Cost','Toll/Meals/Parking','Profit'] );

        $totals = [
            'count'=>0,'invoice_amount'=>0,'labor_cost'=>0,'direct_labor'=>0,'travel_cost'=>0,'tolls_meals'=>0,'profit'=>0
        ];

        foreach ( $data as $row ) {
            $totals['count']          += $row['count'];
            $totals['invoice_amount'] += $row['invoice_amount'];
            $totals['labor_cost']     += $row['labor_cost'];
            $totals['direct_labor']   += $row['direct_labor'];
            $totals['travel_cost']    += $row['travel_cost'];
            $totals['tolls_meals']    += $row['tolls_meals'];
            $totals['profit']         += $row['profit'];

            fputcsv( $out, [
                $row['display_name'],
                $row['count'],
                number_format($row['invoice_amount'],2,'.',''),
                number_format($row['labor_cost'],2,'.',''),
                number_format($row['direct_labor'],2,'.',''),
                number_format($row['travel_cost'],2,'.',''),
                number_format($row['tolls_meals'],2,'.',''),
                number_format($row['profit'],2,'.',''),
            ] );
        }

        // Totals row
        fputcsv( $out, [
            'Totals',
            $totals['count'],
            number_format($totals['invoice_amount'],2,'.',''),
            number_format($totals['labor_cost'],2,'.',''),
            number_format($totals['direct_labor'],2,'.',''),
            number_format($totals['travel_cost'],2,'.',''),
            number_format($totals['tolls_meals'],2,'.',''),
            number_format($totals['profit'],2,'.',''),
        ] );

        fclose($out);
        exit;
    }
}