<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Financial Reports (Monthly / Quarterly / Yearly) as a separate admin menu.
 * Menu icon: dashicons-chart-pie.
 * Submenus: Yearly Report, Quarterly Report, Monthly Report.
 */
class DQ_Financial_Report {

    // Field name constants (adjust if they differ)
    const FIELD_DATE           = 'qi_invoice_date';
    const FIELD_DUE_DATE       = 'qi_due_date';
    const FIELD_INVOICE_NO     = 'qi_invoice_no';
    const FIELD_TOTAL_BILLED   = 'qi_total_billed';
    const FIELD_BALANCE_DUE    = 'qi_balance_due';
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
    const ACTIVITY_TOLLS       = ['Toll', 'Meals', 'Parking'];

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_post_dq_financial_report_csv', [ __CLASS__, 'handle_csv' ] );
    }

    public static function user_can_view() {
        // Only allow admins (manage_options) for menu visibility and page access
        return current_user_can('manage_options');
    }

    public static function menu() {
        // Top-level menu
        add_menu_page(
            'Financial Reports',
            'Financial Reports',
            'manage_options',
            'dq-financial-reports',
            function() { DQ_Financial_Report::render_page_type('yearly'); },
            'dashicons-chart-pie',
            21
        );
        // Yearly Report submenu - slug matches parent
        add_submenu_page(
            'dq-financial-reports',
            'Yearly Report',
            'Yearly Report',
            'manage_options',
            'dq-financial-reports',
            function() { DQ_Financial_Report::render_page_type('yearly'); }
        );
        // Quarterly
        add_submenu_page(
            'dq-financial-reports',
            'Quarterly Report',
            'Quarterly Report',
            'manage_options',
            'dq-financial-reports-quarterly',
            function() { DQ_Financial_Report::render_page_type('quarterly'); }
        );
        // Monthly
        add_submenu_page(
            'dq-financial-reports',
            'Monthly Report',
            'Monthly Report',
            'manage_options',
            'dq-financial-reports-monthly',
            function() { DQ_Financial_Report::render_page_type('monthly'); }
        );
    }

    /**
     * Resolve the correct admin.php?page= slug for a given report type
     */
    private static function page_slug_for( string $report ): string {
        switch ($report) {
            case 'monthly':   return 'dq-financial-reports-monthly';
            case 'quarterly': return 'dq-financial-reports-quarterly';
            case 'yearly':
            default:          return 'dq-financial-reports';
        }
    }

    /**
     * Render a specific report type, routed by menu/submenu.
     */
    public static function render_page_type($type) {
        // Prevent double table by running only once
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;

        $report = in_array($type, ['yearly','quarterly','monthly']) ? $type : 'yearly';
        $_GET['report'] = $report;
        self::render_page();
    }

    /**
     * The main report page renderer.
     */
    public static function render_page() {
        if ( ! self::user_can_view() ) {
            wp_die( 'Insufficient permissions.' );
        }

        $report  = isset($_GET['report']) ? sanitize_key($_GET['report']) : 'yearly';
        $year    = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $month   = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
        $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : 1;
        $engineer_filter = isset($_GET['engineer']) ? intval($_GET['engineer']) : 0;

        // Clamp values
        if ( $year < 2000 || $year > 2100 ) $year = intval(date('Y'));
        if ( $month < 1 || $month > 12 ) $month = intval(date('n'));
        if ( $quarter < 1 || $quarter > 4 ) $quarter = 1;
        if ( ! in_array( $report, ['monthly','quarterly','yearly'], true ) ) $report = 'yearly';

        $range = self::compute_date_range( $report, $year, $month, $quarter );
        $data  = self::aggregate( $range['start'], $range['end'] );

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

        // NEW: Profitability Pie Chart (now includes payroll deduction)
        self::render_profit_chart( $data, $report, $year, $month, $quarter, $range );

        self::render_table( $data, $engineer_filter, $range['start'], $range['end'], $report, $year, $month, $quarter );

        // Payroll Management Section
        self::render_payroll_section( $report, $year, $month, $quarter, $range );

        echo '</div>';
    }

    /**
     * NEW: Renders a profitability pie chart (Direct Labor + Payroll vs Remaining Billed amount).
     * Remaining = Total Billed - Direct Labor - Payroll (or zero if negative).
     * Also shows unpaid invoices as a separate red slice.
     * Clicking the unpaid invoices slice opens a modal with all unpaid invoices for the period.
     */
    private static function render_profit_chart( array $data, string $report, int $year, int $month, int $quarter, array $range ) {
        // Aggregate totals
        $total_billed = 0.0;
        $direct_labor = 0.0;
        $unpaid_total = 0.0;
        foreach ( $data as $row ) {
            $total_billed += (float)$row['invoice_amount'];
            $direct_labor += (float)$row['direct_labor'];
            $unpaid_total += (float)$row['unpaid_amount'];
        }

        // Get payroll total for this period
        $payroll_total = 0.0;
        if ( class_exists( 'DQ_Payroll' ) ) {
            $payroll_total = DQ_Payroll::get_total( $range['start'], $range['end'] );
        }

        $total_deductions = $direct_labor + $payroll_total;
        $remaining = $total_billed - $total_deductions;
        $profitable = $remaining >= 0;

        // Guard: if both are zero, skip chart (nothing to show)
        if ( $total_billed <= 0 && $total_deductions <= 0 ) {
            echo '<p><em>No financial data available for this period.</em></p>';
            return;
        }

        // Collect unpaid invoices for modal
        $unpaid_invoices = self::get_unpaid_invoices( $range['start'], $range['end'] );

        // Unique DOM IDs to avoid collisions if multiple charts were ever rendered
        $canvas_id = 'dq-fr-profit-chart';
        $wrapper_id = 'dq-fr-profit-chart-wrapper';
        $unpaid_modal_id = 'dq-fr-unpaid-invoices-modal';

        // Basic styles + container
        echo '<style>
#' . esc_attr($wrapper_id) . ' {max-width:480px;background:#fff;padding:16px 18px;margin:15px 0 25px;border:1px solid #e1e4e8;border-radius:6px;box-shadow:0 2px 4px rgba(0,0,0,0.06);}
#' . esc_attr($wrapper_id) . ' h2 {margin:0 0 12px;font-size:18px;font-weight:600;}
#' . esc_attr($wrapper_id) . ' .dq-fr-pie-legend {margin-top:12px;font-size:13px;line-height:1.5;}
#' . esc_attr($wrapper_id) . ' .dq-fr-pie-legend span {display:inline-block;margin-right:14px;}
#' . esc_attr($wrapper_id) . ' .dq-fr-pie-metric {margin-top:10px;font-size:13px;}
.dq-fr-metric-positive {color:#098400;font-weight:600;}
.dq-fr-metric-negative {color:#c40000;font-weight:600;}
.dq-fr-metric-unpaid {color:#c40000;font-weight:600;}
/* Unpaid Invoices Modal Styles */
.dq-fr-unpaid-modal-overlay {position:fixed;inset:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:100000;display:none;overflow-y:auto;}
.dq-fr-unpaid-modal-window {background:#fff;max-width:1000px;margin:40px auto;padding:24px 20px 20px;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.15);position:relative;}
.dq-fr-unpaid-modal-close {position:absolute;right:16px;top:12px;font-size:32px;background:transparent;border:none;color:#333;cursor:pointer;line-height:1;}
.dq-fr-unpaid-modal-close:hover {color:#c40000;}
.dq-fr-unpaid-modal-close:focus {outline:2px solid #0073aa;outline-offset:2px;}
.dq-fr-unpaid-table {width:100%;border-collapse:collapse;margin-top:16px;}
.dq-fr-unpaid-table th {background:#dc3545;color:#fff;padding:10px 12px;font-weight:600;text-align:left;font-size:14px;}
.dq-fr-unpaid-table td {border-bottom:1px solid #eee;padding:8px 12px;font-size:13px;vertical-align:middle;}
.dq-fr-unpaid-table tr:last-child td {border-bottom:none;}
.dq-fr-unpaid-table tr:hover td {background:#fff8f8;}
.dq-fr-days-overdue {color:#c40000;font-weight:600;}
.dq-fr-days-remaining {color:#098400;font-weight:600;}
</style>';

        echo '<div id="' . esc_attr($wrapper_id) . '">';
        echo '<h2>Profitability Overview</h2>';
        echo '<canvas id="' . esc_attr($canvas_id) . '" width="460" height="320" aria-label="Profitability pie chart" role="img"></canvas>';

        $status_label = $profitable ? 'Profitable' : 'Loss';
        $status_class = $profitable ? 'dq-fr-metric-positive' : 'dq-fr-metric-negative';
        $remaining_label = $profitable ? 'Net Profit' : 'Net Loss';
        echo '<div class="dq-fr-pie-metric"><strong>Status:</strong> <span class="' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></div>';
        echo '<div class="dq-fr-pie-legend">
                <span><strong>Total Billed:</strong> ' . self::money($total_billed) . '</span>
                <span><strong>Direct Labor:</strong> ' . self::money($direct_labor) . '</span>
                <span><strong>Payroll:</strong> ' . self::money($payroll_total) . '</span>
                <span><strong>' . esc_html($remaining_label) . ':</strong> ' . self::money($remaining) . '</span>
                <span><strong class="dq-fr-metric-unpaid">Unpaid Invoices:</strong> <span class="dq-fr-metric-unpaid">' . self::money($unpaid_total) . '</span></span>
              </div>';
        echo '<noscript><p><em>Pie chart requires JavaScript. Total Billed ' . self::money($total_billed) . ' vs Direct Labor ' . self::money($direct_labor) . ' + Payroll ' . self::money($payroll_total) . '. Unpaid: ' . self::money($unpaid_total) . '.</em></p></noscript>';
        echo '</div>';

        // Render unpaid invoices modal
        self::render_unpaid_invoices_modal( $unpaid_invoices, $unpaid_modal_id, $report, $year, $month, $quarter );

        // Load Chart.js once (simple guard)
        echo '<script>
(function(){
  var unpaidModalId = "' . esc_js($unpaid_modal_id) . '";
  var dqProfitChart = null;

  if (!window.Chart) {
    var s = document.createElement("script");
    s.src = "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js";
    s.onload = renderDQProfitChart;
    document.head.appendChild(s);
  } else {
    renderDQProfitChart();
  }

  function openUnpaidModal() {
    var modal = document.getElementById(unpaidModalId);
    if (modal) {
      modal.style.display = "block";
      // Set focus to close button for accessibility
      var closeBtn = modal.querySelector(".dq-fr-unpaid-modal-close");
      if (closeBtn) closeBtn.focus();
    }
  }

  function closeUnpaidModal() {
    var modal = document.getElementById(unpaidModalId);
    if (modal) modal.style.display = "none";
  }

  // Close modal on ESC key
  document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") closeUnpaidModal();
  });

  // Close modal on overlay click
  var modalOverlay = document.getElementById(unpaidModalId);
  if (modalOverlay) {
    modalOverlay.addEventListener("click", function(ev) {
      if (ev.target === modalOverlay) closeUnpaidModal();
    });
  }

  // Make close function globally accessible for the close button
  window.dqCloseUnpaidModal = closeUnpaidModal;

  function renderDQProfitChart(){
    try {
      var ctx = document.getElementById("' . esc_js($canvas_id) . '");
      if(!ctx) return;
      var directLabor = ' . json_encode(round($direct_labor,2)) . ';
      var payroll = ' . json_encode(round($payroll_total,2)) . ';
      var remaining = ' . json_encode(round(max($remaining,0),2)) . ';
      var loss = ' . json_encode($remaining < 0 ? round(abs($remaining),2) : 0) . ';
      var unpaid = ' . json_encode(round($unpaid_total,2)) . ';
      var dataLabels = remaining >= 0 ? ["Direct Labor","Payroll","Net Profit","Unpaid Invoices"] : ["Direct Labor","Payroll","Net Loss","Unpaid Invoices"];
      var dataValues = remaining >= 0 ? [directLabor, payroll, remaining, unpaid] : [directLabor, payroll, loss, unpaid];

      dqProfitChart = new Chart(ctx, {
        type: "pie",
        data: {
          labels: dataLabels,
          datasets: [{
            data: dataValues,
            backgroundColor: [
              "#ff6f3c",   // Direct Labor slice
              "#3498db",   // Payroll slice
              remaining >= 0 ? "#2e8b57" : "#c40000", // Profit or Loss slice
              "#dc3545"    // Unpaid Invoices slice (red)
            ],
            borderColor: "#ffffff",
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          onClick: function(evt, elements) {
            if (elements.length > 0) {
              var index = elements[0].index;
              var label = dataLabels[index];
              if (label === "Unpaid Invoices") {
                openUnpaidModal();
              }
            }
          },
          plugins: {
            legend: {
              position: "bottom"
            },
            tooltip: {
              callbacks: {
                label: function(ctx){
                  var v = ctx.parsed;
                  var label = ctx.label + ": $" + v.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
                  if (ctx.label === "Unpaid Invoices") {
                    label += " (click to view details)";
                  }
                  return label;
                }
              }
            },
            title: {
              display: false
            }
          }
        }
      });

      // Change cursor to pointer when hovering over Unpaid Invoices slice
      ctx.addEventListener("mousemove", function(e) {
        var points = dqProfitChart.getElementsAtEventForMode(e, "nearest", {intersect: true}, false);
        if (points.length > 0) {
          var index = points[0].index;
          if (dataLabels[index] === "Unpaid Invoices") {
            ctx.style.cursor = "pointer";
          } else {
            ctx.style.cursor = "default";
          }
        } else {
          ctx.style.cursor = "default";
        }
      });

    } catch(e){
      console.warn("DQ Financial Report chart error:", e);
    }
  }
})();
</script>';
    }

    /**
     * Get all unpaid invoices for the given date range.
     * Returns array of invoice data sorted by due date ascending.
     */
    private static function get_unpaid_invoices( string $start, string $end ) : array {
        $unpaid = [];

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

            $balance_due = self::num( function_exists('get_field') ? get_field( self::FIELD_BALANCE_DUE, $pid ) : get_post_meta( $pid, self::FIELD_BALANCE_DUE, true ) );

            // Only include invoices with balance due > 0
            if ( $balance_due <= 0 ) continue;

            $invoice_no = function_exists('get_field') ? get_field( self::FIELD_INVOICE_NO, $pid ) : get_post_meta( $pid, self::FIELD_INVOICE_NO, true );
            $total_billed = self::num( function_exists('get_field') ? get_field( self::FIELD_TOTAL_BILLED, $pid ) : get_post_meta( $pid, self::FIELD_TOTAL_BILLED, true ) );
            $invoice_date = function_exists('get_field') ? get_field( self::FIELD_DATE, $pid ) : get_post_meta( $pid, self::FIELD_DATE, true );
            $due_date_raw = function_exists('get_field') ? get_field( self::FIELD_DUE_DATE, $pid ) : get_post_meta( $pid, self::FIELD_DUE_DATE, true );
            $due_date = self::normalize_date( $due_date_raw );

            $unpaid[] = [
                'post_id'      => $pid,
                'invoice_no'   => $invoice_no ?: ('Post #' . $pid),
                'total_billed' => $total_billed,
                'balance_due'  => $balance_due,
                'invoice_date' => $invoice_date,
                'due_date'     => $due_date,
                'due_date_raw' => $due_date_raw,
            ];
        }

        // Sort by due date ascending (soonest first)
        usort( $unpaid, function( $a, $b ) {
            $date_a = $a['due_date'] ?: '9999-99-99';
            $date_b = $b['due_date'] ?: '9999-99-99';
            return strcmp( $date_a, $date_b );
        });

        return $unpaid;
    }

    /**
     * Render the unpaid invoices modal HTML.
     */
    private static function render_unpaid_invoices_modal( array $unpaid_invoices, string $modal_id, string $report, int $year, int $month, int $quarter ) {
        $period_label = self::human_date_label( $report, $year, $month, $quarter );
        $today = date('Y-m-d');

        echo '<div id="' . esc_attr($modal_id) . '" class="dq-fr-unpaid-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr($modal_id) . '-title">';
        echo '  <div class="dq-fr-unpaid-modal-window">';
        echo '    <button class="dq-fr-unpaid-modal-close" onclick="window.dqCloseUnpaidModal();event.preventDefault();" aria-label="Close modal">&times;</button>';
        echo '    <h2 id="' . esc_attr($modal_id) . '-title">Unpaid Invoices — ' . esc_html($period_label) . '</h2>';

        if ( empty( $unpaid_invoices ) ) {
            echo '    <p><em>No unpaid invoices found for this period.</em></p>';
        } else {
            echo '    <table class="dq-fr-unpaid-table">';
            echo '      <thead><tr>';
            echo '        <th>Invoice #</th>';
            echo '        <th>Amount</th>';
            echo '        <th>Balance</th>';
            echo '        <th>Invoice Date</th>';
            echo '        <th>Due Date</th>';
            echo '        <th>Remaining Days</th>';
            echo '      </tr></thead><tbody>';

            foreach ( $unpaid_invoices as $inv ) {
                $inv_link = get_edit_post_link( $inv['post_id'] );

                // Calculate remaining days
                $remaining_days = '';
                $remaining_class = '';
                if ( $inv['due_date'] ) {
                    $due_ts = strtotime( $inv['due_date'] );
                    $today_ts = strtotime( $today );
                    $diff_days = (int) round( ( $due_ts - $today_ts ) / 86400 );

                    if ( $diff_days < 0 ) {
                        $remaining_days = abs($diff_days) . ' days overdue';
                        $remaining_class = 'dq-fr-days-overdue';
                    } elseif ( $diff_days === 0 ) {
                        $remaining_days = 'Due today';
                        $remaining_class = 'dq-fr-days-overdue';
                    } else {
                        $remaining_days = $diff_days . ' days';
                        $remaining_class = 'dq-fr-days-remaining';
                    }
                } else {
                    $remaining_days = 'N/A';
                }

                echo '<tr>';
                echo '<td><a href="' . esc_url($inv_link) . '" target="_blank">' . esc_html($inv['invoice_no']) . '</a></td>';
                echo '<td>' . self::money($inv['total_billed']) . '</td>';
                echo '<td><span class="dq-fr-metric-unpaid">' . self::money($inv['balance_due']) . '</span></td>';
                echo '<td>' . esc_html($inv['invoice_date']) . '</td>';
                echo '<td>' . esc_html($inv['due_date_raw'] ?: 'N/A') . '</td>';
                echo '<td><span class="' . esc_attr($remaining_class) . '">' . esc_html($remaining_days) . '</span></td>';
                echo '</tr>';
            }

            echo '      </tbody></table>';
        }

        echo '  </div>';
        echo '</div>';
    }

    private static function filters_form( $report, $year, $month, $quarter ) {
        $years = range( date('Y') - 5, date('Y') + 2 );

        // Use the page slug that matches the current report so the menu stays on the correct submenu
        $page_slug = self::page_slug_for($report);

        echo '<form method="get" action="' . esc_url( admin_url('admin.php') ) . '" style="margin:15px 0;display:flex;gap:12px;align-items:flex-end;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';

        if ($report === 'monthly') {
            // Monthly: Only month + year; keep report value consistent for downstream logic (CSV link, etc.)
            echo '<input type="hidden" name="report" value="monthly">';
            echo '<div><label style="font-weight:600;">Month<br><select name="month">';
            for ($m=1; $m<=12; $m++) {
                printf('<option value="%d"%s>%s</option>', $m, selected($month,$m,false), date('F', mktime(0,0,0,$m,1)));
            }
            echo '</select></label></div>';
            echo '<div><label style="font-weight:600;">Year<br><select name="year">';
            foreach ($years as $y) {
                printf('<option value="%d"%s>%d</option>', $y, selected($year,$y,false), $y);
            }
            echo '</select></label></div>';
        } elseif ($report === 'quarterly') {
            // Quarterly: Only quarter + year
            echo '<input type="hidden" name="report" value="quarterly">';
            echo '<div><label style="font-weight:600;">Quarter<br><select name="quarter">';
            for ($q = 1; $q <= 4; $q++) {
                printf('<option value="%d"%s>Q%d</option>', $q, selected($quarter,$q,false), $q);
            }
            echo '</select></label></div>';
            echo '<div><label style="font-weight:600;">Year<br><select name="year">';
            foreach ($years as $y) {
                printf('<option value="%d"%s>%d</option>', $y, selected($year,$y,false), $y);
            }
            echo '</select></label></div>';
        } else {
            // Yearly: Only year
            echo '<input type="hidden" name="report" value="yearly">';
            echo '<div><label style="font-weight:600;">Year<br><select name="year">';
            foreach ($years as $y) {
                printf('<option value="%d"%s>%d</option>', $y, selected($year,$y,false), $y);
            }
            echo '</select></label></div>';
        }

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

        // Get ALL invoice posts with a date (meta key filter for performance)
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
                $wo_post = reset( $wo_field );
            } elseif ( $wo_field instanceof WP_Post ) {
                $wo_post = $wo_field;
            } elseif ( is_numeric( $wo_field ) ) {
                $wo_post = get_post( intval( $wo_field ) );
            }
            if ( ! $wo_post || $wo_post->post_type !== 'workorder' ) continue;

            $engineer_id = intval( $wo_post->post_author );
            if ( $engineer_id <= 0 ) continue;

            if ( ! isset( $out[ $engineer_id ] ) ) {
                $user = get_user_by( 'id', $engineer_id );
                $out[ $engineer_id ] = [
                    'display_name'   => $user ? $user->display_name : 'User #' . $engineer_id,
                    'invoices'       => [],
                    'count'          => 0,
                    'invoice_amount' => 0.0,
                    'unpaid_amount'  => 0.0,
                    'labor_cost'     => 0.0,
                    'direct_labor'   => 0.0,
                    'travel_cost'    => 0.0,
                    'tolls_meals'    => 0.0,
                ];
            }

            $total_billed = self::num( function_exists('get_field') ? get_field( self::FIELD_TOTAL_BILLED, $pid ) : get_post_meta( $pid, self::FIELD_TOTAL_BILLED, true ) );
            $balance_due = self::num( function_exists('get_field') ? get_field( self::FIELD_BALANCE_DUE, $pid ) : get_post_meta( $pid, self::FIELD_BALANCE_DUE, true ) );
            $out[ $engineer_id ]['count']++;
            $out[ $engineer_id ]['invoice_amount'] += $total_billed;
            $out[ $engineer_id ]['unpaid_amount'] += $balance_due;
            $out[ $engineer_id ]['invoices'][] = [
                'post_id'     => $pid,
                'date'        => $date_norm,
                'number'      => function_exists('get_field') ? get_field('qi_invoice_no', $pid) : get_post_meta($pid,'qi_invoice_no', true),
                'amount'      => $total_billed,
                'balance_due' => $balance_due,
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
                    } elseif ( in_array( $activity, self::ACTIVITY_TOLLS, true ) ) {
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
        $raw = trim( (string)$raw );
        if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw ) ) return $raw;
        $ts = strtotime( $raw );
        return $ts ? date('Y-m-d', $ts ) : '';
    }

    private static function num( $v ) {
        if ( $v === null || $v === '' ) return 0.0;
        if ( is_numeric( $v ) ) return (float)$v;
        $clean = preg_replace('/[^0-9.\-]/','',(string)$v);
        return ( $clean === '' || ! is_numeric($clean) ) ? 0.0 : (float)$clean;
    }
private static function render_table( array $data, int $engineer_filter, string $start, string $end, string $report, int $year, int $month, int $quarter ) {
$totals = [
    'count'=>0,
    'invoice_amount'=>0.0,
    'unpaid_amount'=>0.0,
    'labor_cost'=>0.0,
    'direct_labor'=>0.0,
    'travel_cost'=>0.0,
    'tolls_meals'=>0.0,
    'profit'=>0.0,
];

// Collect per-engineer modal HTML here and print once after the main table
$modals_html = '';

echo '<style>
.dq-fr-table { width:100%; border-collapse:collapse; background:#fff; }
.dq-fr-table th { background:#006d7b; color:#fff; padding:8px 10px; text-align:left; font-weight:600; }
.dq-fr-table td { padding:8px 10px; border-bottom:1px solid #eee; vertical-align:middle; }
.dq-fr-table tr:last-child td { border-bottom:none; }
.dq-fr-avatar { width:32px; height:32px; border-radius:50%; object-fit:cover; margin-right:8px; }
.dq-fr-name { display:flex; align-items:center; }
.dq-fr-profit-pos { color:#098400; font-weight:600; }
.dq-fr-profit-neg { color:#c40000; font-weight:600; }
.dq-fr-unpaid { color:#c40000; font-weight:600; }
.dq-fr-totals-row td { font-weight:600; background:#e6f8fc; }

/* Modal + inner invoice table */
.dq-fr-modal-overlay { position:fixed; inset:0; width:100vw; height:100vh; background:rgba(0,0,0,0.35); z-index:9999; display:none; }
.dq-fr-modal-window { background:#fff; max-width:900px; margin:50px auto; padding:24px 20px 20px; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.09); position:relative; }
.dq-fr-modal-close { position:absolute; right:16px; top:12px; font-size:32px; background:transparent; border:none; color:#333; cursor:pointer; line-height:1; }
.dq-fr-modal-invoice-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
.dq-fr-modal-invoice-table th { background:#f2fbfe; color:#333; font-weight:600; padding:6px; font-size:14px; text-align:left; }
.dq-fr-modal-invoice-table td { border-bottom:1px solid #eee; font-size:13px; padding:4px 7px; vertical-align:middle; }
.dq-fr-modal-invoice-table th, .dq-fr-modal-invoice-table td { border-right:1px solid #eee; }
.dq-fr-modal-invoice-table th:last-child, .dq-fr-modal-invoice-table td:last-child { border-right:none; }
.dq-fr-modal-invoice-table tr:last-child td { border-bottom:none; }
.dq-fr-tooltip[title] { cursor:help; border-bottom:1px dotted #888; position:relative; }
</style>';

echo '<table class="dq-fr-table">';
echo '<thead><tr>';
$cols = ['Field Engineer','Total Invoices','Invoice Amount','Unpaid Amount','Labor Cost','Direct Labor Cost','Travel Cost','Toll, Meals, Parking','Profit'];
foreach ( $cols as $c ) echo '<th>' . esc_html($c) . '</th>';
echo '</tr></thead><tbody>';

foreach ( $data as $uid => $row ) {
    $totals['count']          += $row['count'];
    $totals['invoice_amount'] += $row['invoice_amount'];
    $totals['unpaid_amount']  += $row['unpaid_amount'];
    $totals['labor_cost']     += $row['labor_cost'];
    $totals['direct_labor']   += $row['direct_labor'];
    $totals['travel_cost']    += $row['travel_cost'];
    $totals['tolls_meals']    += $row['tolls_meals'];
    $totals['profit']         += $row['profit'];

    $avatar_html = self::get_user_avatar_html( $uid );
    $modalId = 'dq-fr-modal-' . $uid;

    echo '<tr>';
    echo '<td><span class="dq-fr-name">' . $avatar_html . esc_html( $row['display_name'] ) . '</span></td>';
    // Open modal without reloading; modal is pre-rendered per row (below)
    echo '<td><a href="#" onclick="(function(m){ if(m){ m.style.display=\'block\'; } })(document.getElementById(\'' . esc_attr($modalId) . '\')); return false;">' . intval($row['count']) . '</a></td>';
    echo '<td>' . self::money( $row['invoice_amount'] ) . '</td>';
    $unpaid_class = $row['unpaid_amount'] > 0 ? 'dq-fr-unpaid' : '';
    echo '<td><span class="' . $unpaid_class . '">' . self::money( $row['unpaid_amount'] ) . '</span></td>';
    echo '<td>' . self::money( $row['labor_cost'] ) . '</td>';
    echo '<td>' . self::money( $row['direct_labor'] ) . '</td>';
    echo '<td>' . self::money( $row['travel_cost'] ) . '</td>';
    echo '<td>' . self::money( $row['tolls_meals'] ) . '</td>';
    $profit_class = $row['profit'] >= 0 ? 'dq-fr-profit-pos' : 'dq-fr-profit-neg';
    echo '<td><span class="' . $profit_class . '">' . self::money( $row['profit'] ) . '</span></td>';
    echo '</tr>';

    // Build modal for this engineer now (hidden by default)
    ob_start();
    echo '<div id="' . esc_attr($modalId) . '" class="dq-fr-modal-overlay">';
    echo '  <div class="dq-fr-modal-window">';
    echo '    <button class="dq-fr-modal-close" onclick="document.getElementById(\'' . esc_attr($modalId) . '\').style.display=\'none\';event.preventDefault();">&times;</button>';
    echo '    <h2>Invoice List — ' . esc_html($row['display_name']) . '</h2>';
    echo '    <table class="dq-fr-modal-invoice-table">';
    echo '      <thead><tr>';
    $modal_cols = ['Invoice Number','Work Orders','Invoice Date','Invoice Amount','Labor Cost','Direct Labor Cost','Travel Cost','Other Billed Expense'];
    foreach ($modal_cols as $mc) echo '<th>' . esc_html($mc) . '</th>';
    echo '      </tr></thead><tbody>';

    // Render each invoice row with breakdown
    foreach ( $row['invoices'] as $inv ) {
        $pid      = $inv['post_id'];
        $num      = $inv['number'] ?: ('Post #' . $pid);
        $inv_link = get_edit_post_link( $pid );
        $inv_date = function_exists('get_field') ? get_field(self::FIELD_DATE, $pid) : get_post_meta($pid, self::FIELD_DATE, true);
        $inv_total= function_exists('get_field') ? get_field(self::FIELD_TOTAL_BILLED, $pid) : get_post_meta($pid, self::FIELD_TOTAL_BILLED, true);

        // Work orders list
        $wo_field = function_exists('get_field') ? get_field(self::FIELD_WO_RELATION, $pid) : get_post_meta($pid, self::FIELD_WO_RELATION, true);
        $wo_display = '';
        if (is_array($wo_field)) {
            foreach ($wo_field as $wo_item) {
                $wo_id = (is_numeric($wo_item) ? intval($wo_item) : (is_object($wo_item) ? $wo_item->ID : null));
                if ($wo_id) {
                    $wo_link = get_edit_post_link($wo_id);
                    $wo_display .= '<a href="' . esc_url($wo_link) . '" target="_blank">#' . $wo_id . '</a>, ';
                }
            }
            $wo_display = rtrim($wo_display, ', ');
        } elseif (is_numeric($wo_field)) {
            $wo_id = intval($wo_field);
            $wo_link = get_edit_post_link($wo_id);
            $wo_display = '<a href="' . esc_url($wo_link) . '" target="_blank">#' . $wo_id . '</a>';
        } elseif ($wo_field instanceof WP_Post) {
            $wo_id = $wo_field->ID;
            $wo_link = get_edit_post_link($wo_id);
            $wo_display = '<a href="' . esc_url($wo_link) . '" target="_blank">#' . $wo_id . '</a>';
        }

        // Line breakdowns
        $lines = function_exists('get_field') ? get_field(self::FIELD_LINES_REPEATER, $pid) : get_post_meta(self::FIELD_LINES_REPEATER, $pid, true);
        $labor = $travel = $otherExp = 0.0;
        $tz1=$tz2=$tz3=0.0; $toll=$meals=$parking=0.0;

        if (is_array($lines)) {
            foreach ($lines as $one) {
                $activity = isset($one[self::FIELD_LINES_ACTIVITY]) ? trim((string)$one[self::FIELD_LINES_ACTIVITY]) : '';
                $amt      = self::num($one[self::FIELD_LINES_AMOUNT] ?? 0);
                if ($activity === self::ACTIVITY_LABOR) {
                    $labor += $amt;
                } elseif ($activity === 'Travel Zone 1') {
                    $travel += $amt; $tz1 += $amt;
                } elseif ($activity === 'Travel Zone 2') {
                    $travel += $amt; $tz2 += $amt;
                } elseif ($activity === 'Travel Zone 3') {
                    $travel += $amt; $tz3 += $amt;
                } elseif ($activity === 'Toll') {
                    $otherExp += $amt; $toll += $amt;
                } elseif ($activity === 'Meals') {
                    $otherExp += $amt; $meals += $amt;
                } elseif ($activity === 'Parking') {
                    $otherExp += $amt; $parking += $amt;
                }
            }
        }

        // Direct Labor Cost from other expenses repeater WITH BREAKDOWN
        $directLabor = 0.0;
        $directLaborParts = []; // collect each row for tooltip
        $other = function_exists('get_field') ? get_field(self::FIELD_OTHER_EXPENSES, $pid) : get_post_meta(self::FIELD_OTHER_EXPENSES, $pid, true);
        if (is_array($other)) {
            $i = 1;
            foreach ($other as $o) {
                $amtRow = self::num($o[self::FIELD_OTHER_AMOUNT] ?? 0);
                if ($amtRow !== 0.0) {
                    $label = '';
                    // Try to find a human label if present in repeater row
                    foreach (['label','description','desc','name','type'] as $possible) {
                        if (isset($o[$possible]) && is_string($o[$possible]) && $o[$possible] !== '') {
                            $label = trim($o[$possible]);
                            break;
                        }
                    }
                    if ($label === '') {
                        $label = 'Item ' . $i;
                    }
                    $directLaborParts[] = $label . ': ' . self::money($amtRow);
                }
                $directLabor += $amtRow;
                $i++;
            }
        }
        $directLaborTip = empty($directLaborParts)
            ? 'No direct labor line items'
            : "Direct Labor Breakdown:\n" . implode("\n", $directLaborParts);

        // Tooltip strings
        $travelTip = "Travel Zone 1: " . self::money($tz1) . "\nTravel Zone 2: " . self::money($tz2) . "\nTravel Zone 3: " . self::money($tz3);
        $otherTip  = "Toll: " . self::money($toll) . "\nMeals: " . self::money($meals) . "\nParking: " . self::money($parking);

        echo '<tr>';
        echo '<td><a href="' . esc_url($inv_link) . '" target="_blank">' . esc_html($num) . '</a></td>';
        echo '<td>' . $wo_display . '</td>';
        echo '<td>' . esc_html($inv_date) . '</td>';
        echo '<td>' . self::money($inv_total) . '</td>';
        echo '<td>' . self::money($labor) . '</td>';
        // Direct Labor cost now has tooltip breakdown
        echo '<td><span class="dq-fr-tooltip" title="' . esc_attr($directLaborTip) . '">' . self::money($directLabor) . '</span></td>';
        echo '<td><span class="dq-fr-tooltip" title="' . esc_attr($travelTip) . '">' . self::money($travel) . '</span></td>';
        echo '<td><span class="dq-fr-tooltip" title="' . esc_attr($otherTip) . '">' . self::money($otherExp) . '</span></td>';
        echo '</tr>';
    }

    echo '      </tbody></table>';
    echo '  </div>';
    echo '</div>';
    $modals_html .= ob_get_clean();
}

// Totals row
$profit_class_total = $totals['profit'] >= 0 ? 'dq-fr-profit-pos' : 'dq-fr-profit-neg';
$unpaid_class_total = $totals['unpaid_amount'] > 0 ? 'dq-fr-unpaid' : '';
echo '<tr class="dq-fr-totals-row">';
echo '<td>Totals:</td>';
echo '<td>' . intval( $totals['count'] ) . '</td>';
echo '<td>' . self::money( $totals['invoice_amount'] ) . '</td>';
echo '<td><span class="' . $unpaid_class_total . '">' . self::money( $totals['unpaid_amount'] ) . '</span></td>';
echo '<td>' . self::money( $totals['labor_cost'] ) . '</td>';
echo '<td>' . self::money( $totals['direct_labor'] ) . '</td>';
echo '<td>' . self::money( $totals['travel_cost'] ) . '</td>';
echo '<td>' . self::money( $totals['tolls_meals'] ) . '</td>';
echo '<td><span class="' . $profit_class_total . '">' . self::money( $totals['profit'] ) . '</span></td>';
echo '</tr>';

// Payroll deduction row
$payroll_total = 0.0;
if ( class_exists( 'DQ_Payroll' ) ) {
    $payroll_total = DQ_Payroll::get_total( $start, $end );
}
if ( $payroll_total > 0 ) {
    echo '<tr class="dq-fr-totals-row" style="background:#fff3cd;">';
    echo '<td colspan="8" style="text-align:right;"><strong>Less: Payroll</strong></td>';
    echo '<td><span style="color:#856404;">-' . self::money( $payroll_total ) . '</span></td>';
    echo '</tr>';

    // Net profit row (after payroll)
    $net_profit = $totals['profit'] - $payroll_total;
    $net_profit_class = $net_profit >= 0 ? 'dq-fr-profit-pos' : 'dq-fr-profit-neg';
    echo '<tr class="dq-fr-totals-row" style="background:#d4edda;">';
    echo '<td colspan="8" style="text-align:right;"><strong>Net Profit (After Payroll)</strong></td>';
    echo '<td><span class="' . $net_profit_class . '">' . self::money( $net_profit ) . '</span></td>';
    echo '</tr>';
}

echo '</tbody></table>';

// Print all modals once; add a single ESC/overlay-click closer
echo $modals_html;
echo '<script>
(function(){
  function closeOnEsc(e){
    if(e.key === "Escape"){
      document.querySelectorAll(".dq-fr-modal-overlay").forEach(function(m){ m.style.display="none"; });
    }
  }
  document.addEventListener("keydown", closeOnEsc);
  document.querySelectorAll(".dq-fr-modal-overlay").forEach(function(m){
    m.addEventListener("click", function(ev){ if(ev.target === m){ m.style.display="none"; } });
  });
})();
</script>';
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

    public static function handle_csv() {
        if ( ! self::user_can_view() ) {
            wp_die('Permission denied');
        }
        check_admin_referer( 'dq_fr_csv' );

        $report  = isset($_GET['report']) ? sanitize_key($_GET['report']) : 'yearly';
        $year    = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $month   = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
        $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : 1;
        $range   = self::compute_date_range( $report, $year, $month, $quarter );
        $data    = self::aggregate( $range['start'], $range['end'] );

        $filename = 'financial-report-' . $report . '-' . $year;
        if ( $report === 'monthly' ) $filename .= '-' . $month;
        if ( $report === 'quarterly' ) $filename .= '-Q' . $quarter;
        $filename .= '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=' . $filename );
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output','w');
        fputcsv( $out, ['Engineer','Total Invoices','Invoice Amount','Unpaid Amount','Labor Cost','Direct Labor Cost','Travel Cost','Toll/Meals/Parking','Profit'] );

        $totals = [
            'count'=>0,'invoice_amount'=>0,'unpaid_amount'=>0,'labor_cost'=>0,'direct_labor'=>0,'travel_cost'=>0,'tolls_meals'=>0,'profit'=>0
        ];

        foreach ( $data as $row ) {
            $totals['count']          += $row['count'];
            $totals['invoice_amount'] += $row['invoice_amount'];
            $totals['unpaid_amount']  += $row['unpaid_amount'];
            $totals['labor_cost']     += $row['labor_cost'];
            $totals['direct_labor']   += $row['direct_labor'];
            $totals['travel_cost']    += $row['travel_cost'];
            $totals['tolls_meals']    += $row['tolls_meals'];
            $totals['profit']         += $row['profit'];

            fputcsv( $out, [
                $row['display_name'],
                $row['count'],
                number_format($row['invoice_amount'],2,'.',''),
                number_format($row['unpaid_amount'],2,'.',''),
                number_format($row['labor_cost'],2,'.',''),
                number_format($row['direct_labor'],2,'.',''),
                number_format($row['travel_cost'],2,'.',''),
                number_format($row['tolls_meals'],2,'.',''),
                number_format($row['profit'],2,'.',''),
            ] );
        }

        fputcsv( $out, [
            'Totals',
            $totals['count'],
            number_format($totals['invoice_amount'],2,'.',''),
            number_format($totals['unpaid_amount'],2,'.',''),
            number_format($totals['labor_cost'],2,'.',''),
            number_format($totals['direct_labor'],2,'.',''),
            number_format($totals['travel_cost'],2,'.',''),
            number_format($totals['tolls_meals'],2,'.',''),
            number_format($totals['profit'],2,'.',''),
        ] );

        // Add payroll deduction section
        $payroll_total = 0.0;
        if ( class_exists( 'DQ_Payroll' ) ) {
            $payroll_total = DQ_Payroll::get_total( $range['start'], $range['end'] );
            $payroll_records = DQ_Payroll::get_records( $range['start'], $range['end'] );

            // Empty row separator
            fputcsv( $out, [] );
            fputcsv( $out, ['--- Payroll Deductions ---'] );
            fputcsv( $out, ['Date', 'Amount'] );

            foreach ( $payroll_records as $record ) {
                fputcsv( $out, [
                    $record['date'],
                    number_format($record['amount'],2,'.',''),
                ] );
            }

            fputcsv( $out, ['Total Payroll', number_format($payroll_total,2,'.','') ] );
        }

        // Add net profit after payroll
        fputcsv( $out, [] );
        $net_profit = $totals['profit'] - $payroll_total;
        fputcsv( $out, ['Net Profit (After Payroll)', number_format($net_profit,2,'.','') ] );

        fclose($out);
        exit;
    }

    /**
     * Render payroll management section (form + records table).
     */
    private static function render_payroll_section( $report, $year, $month, $quarter, $range ) {
        echo '<div class="dq-payroll-section" style="margin-top:30px;">';
        echo '<h2>Payroll Management</h2>';

        // Render add form (admin only - handled inside the method)
        if ( class_exists( 'DQ_Payroll' ) ) {
            DQ_Payroll::render_add_form( $report, $year, $month, $quarter );
            DQ_Payroll::render_records_table( $range['start'], $range['end'] );
        } else {
            echo '<p><em>Payroll module not available.</em></p>';
        }

        echo '</div>';
    }
}
