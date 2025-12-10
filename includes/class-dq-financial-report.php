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

    // Password protection constants
    const PASSWORD_OPTION_KEY  = 'dq_financial_reports_password';
    const PASSWORD_COOKIE_NAME = 'dq_fr_access';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_post_dq_financial_report_csv', [ __CLASS__, 'handle_csv' ] );
        add_action( 'admin_post_dq_financial_report_auth', [ __CLASS__, 'handle_password_auth' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    /**
     * Register the Financial Reports Password setting on the WordPress General Settings page.
     */
    public static function register_settings() {
        register_setting(
            'general',
            self::PASSWORD_OPTION_KEY,
            [
                'type'              => 'string',
                'sanitize_callback' => [ __CLASS__, 'sanitize_password_field' ],
                'default'           => '',
            ]
        );

        add_settings_field(
            self::PASSWORD_OPTION_KEY,
            __( 'Financial Reports Password', 'dominus-quickbooks' ),
            [ __CLASS__, 'render_password_field' ],
            'general',
            'default'
        );
    }

    /**
     * Sanitize the password field value.
     * If empty, preserve the existing password (to allow saving without re-entering).
     * If the clear checkbox is checked, return empty string to remove protection.
     *
     * Note: This callback is invoked by the WordPress Settings API which handles
     * nonce verification before calling sanitize callbacks.
     *
     * @param string $value The submitted value.
     * @return string The sanitized value.
     */
    public static function sanitize_password_field( $value ) {
        $value = sanitize_text_field( $value );
        
        // Check if user wants to clear the password via the clear checkbox
        // The Settings API verifies nonces before this callback is invoked
        $clear_key = self::PASSWORD_OPTION_KEY . '_clear';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WordPress Settings API before sanitize callback
        $should_clear = isset( $_POST[ $clear_key ] ) && sanitize_text_field( wp_unslash( $_POST[ $clear_key ] ) ) === '1';
        
        if ( $should_clear ) {
            return '';
        }
        
        // If value is empty, preserve the existing password
        if ( $value === '' ) {
            return get_option( self::PASSWORD_OPTION_KEY, '' );
        }
        
        return $value;
    }

    /**
     * Render the password input field for the General Settings page.
     */
    public static function render_password_field() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $has_password = ! empty( get_option( self::PASSWORD_OPTION_KEY, '' ) );
        printf(
            '<input type="password" id="%1$s" name="%1$s" value="" class="regular-text" autocomplete="off" placeholder="%2$s" />',
            esc_attr( self::PASSWORD_OPTION_KEY ),
            $has_password ? esc_attr__( '(password is set)', 'dominus-quickbooks' ) : ''
        );
        if ( $has_password ) {
            // Add a checkbox to allow clearing the password
            printf(
                '<br /><label><input type="checkbox" name="%s_clear" value="1" /> %s</label>',
                esc_attr( self::PASSWORD_OPTION_KEY ),
                esc_html__( 'Clear password (remove protection)', 'dominus-quickbooks' )
            );
            echo '<p class="description">' . esc_html__( 'Enter a new password to change it. Leave empty to keep the current password. Check the box above to remove password protection.', 'dominus-quickbooks' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Set a password to protect access to Financial Reports. Leave empty for no password protection.', 'dominus-quickbooks' ) . '</p>';
        }
    }

    /**
     * Check if a password is set for the financial reports.
     *
     * @return string|false The password if set, false otherwise.
     */
    private static function get_password() {
        $password = get_option( self::PASSWORD_OPTION_KEY, '' );
        return ! empty( $password ) ? $password : false;
    }

    /**
     * Check if the user has valid access via cookie.
     *
     * @return bool True if access is granted, false otherwise.
     */
    private static function has_valid_access() {
        $password = self::get_password();
        if ( ! $password ) {
            // No password set, access is granted
            return true;
        }

        if ( ! isset( $_COOKIE[ self::PASSWORD_COOKIE_NAME ] ) ) {
            return false;
        }

        // Verify the cookie value matches a hash of the password
        $cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ self::PASSWORD_COOKIE_NAME ] ) );
        return hash_equals( wp_hash( $password ), $cookie_value );
    }

    /**
     * Handle password authentication form submission.
     */
    public static function handle_password_auth() {
        if ( ! self::user_can_view() ) {
            wp_die( 'Insufficient permissions.' );
        }

        check_admin_referer( 'dq_fr_auth' );

        $password = self::get_password();
        $entered_password = isset( $_POST['dq_fr_password'] ) ? sanitize_text_field( wp_unslash( $_POST['dq_fr_password'] ) ) : '';
        $redirect_page = isset( $_POST['dq_fr_redirect'] ) ? sanitize_text_field( wp_unslash( $_POST['dq_fr_redirect'] ) ) : 'dq-financial-reports';

        if ( $password && hash_equals( $password, $entered_password ) ) {
            // Set session-only cookie (expires when browser closes)
            $cookie_value = wp_hash( $password );
            setcookie( self::PASSWORD_COOKIE_NAME, $cookie_value, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

            wp_safe_redirect( admin_url( 'admin.php?page=' . $redirect_page ) );
            exit;
        }

        // Password doesn't match, redirect back with error
        wp_safe_redirect( admin_url( 'admin.php?page=' . $redirect_page . '&auth_error=1' ) );
        exit;
    }

    /**
     * Render the password form for protected access.
     *
     * @param string $page_slug The current page slug for redirect after authentication.
     */
    private static function render_password_form( $page_slug = 'dq-financial-reports' ) {
        $has_error = isset( $_GET['auth_error'] ) && sanitize_text_field( wp_unslash( $_GET['auth_error'] ) ) === '1';

        echo '<style>
.dq-fr-auth-card { max-width: 400px; padding: 20px; margin-top: 20px; }
.dq-fr-auth-card h2 { margin-top: 0; }
.dq-fr-auth-card label { display: block; margin-bottom: 5px; font-weight: 600; }
</style>';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Financial Reports', 'dominus-quickbooks' ) . '</h1>';

        if ( $has_error ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Incorrect password. Please try again.', 'dominus-quickbooks' ) . '</p></div>';
        }

        echo '<div class="card dq-fr-auth-card">';
        echo '<h2>' . esc_html__( 'Password Required', 'dominus-quickbooks' ) . '</h2>';
        echo '<p>' . esc_html__( 'Please enter the password to access Financial Reports.', 'dominus-quickbooks' ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="dq_financial_report_auth">';
        echo '<input type="hidden" name="dq_fr_redirect" value="' . esc_attr( $page_slug ) . '">';
        wp_nonce_field( 'dq_fr_auth' );

        echo '<p>';
        echo '<label for="dq_fr_password">' . esc_html__( 'Password', 'dominus-quickbooks' ) . '</label>';
        echo '<input type="password" id="dq_fr_password" name="dq_fr_password" class="regular-text" required autofocus>';
        echo '</p>';

        echo '<p class="submit">';
        echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Access Reports', 'dominus-quickbooks' ) . '">';
        echo '</p>';

        echo '</form>';
        echo '</div>';
        echo '</div>';
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

        // Extract report type once at the beginning
        $report = isset($_GET['report']) ? sanitize_key($_GET['report']) : 'yearly';
        if ( ! in_array( $report, ['monthly','quarterly','yearly'], true ) ) {
            $report = 'yearly';
        }
        $page_slug = self::page_slug_for( $report );

        // Check for password protection
        if ( ! self::has_valid_access() ) {
            self::render_password_form( $page_slug );
            return;
        }

        $year    = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $month   = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
        $quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : 1;
        $engineer_filter = isset($_GET['engineer']) ? intval($_GET['engineer']) : 0;

        // Clamp values
        if ( $year < 2000 || $year > 2100 ) $year = intval(date('Y'));
        if ( $month < 1 || $month > 12 ) $month = intval(date('n'));
        if ( $quarter < 1 || $quarter > 4 ) $quarter = 1;

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

  // Close modal on ESC key - only if modal is visible
  document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
      var modal = document.getElementById(unpaidModalId);
      if (modal && modal.style.display === "block") {
        closeUnpaidModal();
      }
    }
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
          },
          onHover: function(evt, elements) {
            // Change cursor to pointer when hovering over Unpaid Invoices slice
            if (elements.length > 0) {
              var index = elements[0].index;
              if (dataLabels[index] === "Unpaid Invoices") {
                ctx.style.cursor = "pointer";
              } else {
                ctx.style.cursor = "default";
              }
            } else {
              ctx.style.cursor = "default";
            }
          }
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
        // Invoices without due dates are sorted to the end
        usort( $unpaid, function( $a, $b ) {
            // Handle cases where due_date is missing - put them at the end
            if ( empty( $a['due_date'] ) && empty( $b['due_date'] ) ) return 0;
            if ( empty( $a['due_date'] ) ) return 1;  // $a goes after $b
            if ( empty( $b['due_date'] ) ) return -1; // $b goes after $a
            return strcmp( $a['due_date'], $b['due_date'] );
        });

        return $unpaid;
    }

    /**
     * Render the advanced unpaid invoices modal HTML with pagination, sorting, filtering, and CSV export.
     */
    private static function render_unpaid_invoices_modal( array $unpaid_invoices, string $modal_id, string $report, int $year, int $month, int $quarter ) {
        $period_label = self::human_date_label( $report, $year, $month, $quarter );
        $today = date('Y-m-d');

        // Pre-calculate remaining days and categorize invoices
        $processed_invoices = [];
        $total_overdue = 0.0;
        $total_incoming = 0.0;

        foreach ( $unpaid_invoices as $inv ) {
            $remaining_days_num = null;
            $remaining_days_text = 'N/A';
            $remaining_class = '';
            $is_overdue = false;

            if ( $inv['due_date'] ) {
                $due_date_obj = new DateTime( $inv['due_date'] );
                $today_obj = new DateTime( $today );
                $interval = $today_obj->diff( $due_date_obj );
                $diff_days = (int) $interval->days;
                if ( $interval->invert === 1 ) {
                    $diff_days = -$diff_days;
                }
                $remaining_days_num = $diff_days;

                if ( $diff_days < 0 ) {
                    $remaining_days_text = abs($diff_days) . ' days overdue';
                    $remaining_class = 'dq-fr-days-overdue';
                    $is_overdue = true;
                    $total_overdue += (float) $inv['balance_due'];
                } elseif ( $diff_days === 0 ) {
                    $remaining_days_text = 'Due today';
                    $remaining_class = 'dq-fr-days-overdue';
                    $is_overdue = true;
                    $total_overdue += (float) $inv['balance_due'];
                } else {
                    $remaining_days_text = $diff_days . ' days';
                    $remaining_class = 'dq-fr-days-remaining';
                    $total_incoming += (float) $inv['balance_due'];
                }
            } else {
                // No due date - consider as incoming (unknown)
                $total_incoming += (float) $inv['balance_due'];
            }

            $processed_invoices[] = [
                'post_id'             => $inv['post_id'],
                'invoice_no'          => $inv['invoice_no'],
                'total_billed'        => $inv['total_billed'],
                'balance_due'         => $inv['balance_due'],
                'invoice_date'        => $inv['invoice_date'],
                'invoice_date_sort'   => self::normalize_date($inv['invoice_date']),
                'due_date'            => $inv['due_date_raw'] ?: 'N/A',
                'due_date_sort'       => $inv['due_date'] ?: '9999-12-31',
                'remaining_days_num'  => $remaining_days_num,
                'remaining_days_text' => $remaining_days_text,
                'remaining_class'     => $remaining_class,
                'is_overdue'          => $is_overdue,
                'edit_link'           => get_edit_post_link( $inv['post_id'] ),
            ];
        }

        // Additional modal styles
        echo '<style>
.dq-fr-unpaid-modal-controls {display:flex;flex-wrap:wrap;gap:16px;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #eee;}
.dq-fr-unpaid-summary {display:flex;gap:24px;flex-wrap:wrap;}
.dq-fr-unpaid-summary-item {padding:10px 16px;border-radius:6px;font-size:14px;}
.dq-fr-unpaid-summary-item.overdue {background:#fee2e2;color:#991b1b;}
.dq-fr-unpaid-summary-item.incoming {background:#d1fae5;color:#065f46;}
.dq-fr-unpaid-summary-item strong {display:block;font-size:18px;margin-top:4px;}
.dq-fr-unpaid-filters {display:flex;gap:8px;align-items:center;}
.dq-fr-unpaid-filters label {font-weight:600;margin-right:8px;}
.dq-fr-unpaid-filters button {padding:6px 14px;border:1px solid #d1d5db;background:#fff;border-radius:4px;cursor:pointer;font-size:13px;transition:all 0.15s;}
.dq-fr-unpaid-filters button:hover {background:#f3f4f6;}
.dq-fr-unpaid-filters button.active {background:#dc3545;color:#fff;border-color:#dc3545;}
.dq-fr-unpaid-csv-btn {padding:8px 16px;background:#28a745;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:600;margin-left:auto;}
.dq-fr-unpaid-csv-btn:hover {background:#218838;}
.dq-fr-unpaid-table th.sortable {cursor:pointer;user-select:none;position:relative;padding-right:20px;}
.dq-fr-unpaid-table th.sortable:hover {background:#b82d3c;}
.dq-fr-unpaid-table th.sortable::after {content:"⇅";position:absolute;right:6px;top:50%;transform:translateY(-50%);opacity:0.5;font-size:12px;}
.dq-fr-unpaid-table th.sortable.asc::after {content:"↑";opacity:1;}
.dq-fr-unpaid-table th.sortable.desc::after {content:"↓";opacity:1;}
.dq-fr-unpaid-pagination {display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:12px;border-top:1px solid #eee;}
.dq-fr-unpaid-pagination-info {font-size:13px;color:#666;}
.dq-fr-unpaid-pagination-controls {display:flex;gap:8px;}
.dq-fr-unpaid-pagination-controls button {padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:4px;cursor:pointer;font-size:13px;}
.dq-fr-unpaid-pagination-controls button:hover:not(:disabled) {background:#f3f4f6;}
.dq-fr-unpaid-pagination-controls button:disabled {opacity:0.5;cursor:not-allowed;}
.dq-fr-unpaid-pagination-controls .page-numbers {display:flex;gap:4px;}
.dq-fr-unpaid-pagination-controls .page-num {padding:6px 10px;border:1px solid #d1d5db;background:#fff;border-radius:4px;cursor:pointer;font-size:13px;min-width:36px;text-align:center;}
.dq-fr-unpaid-pagination-controls .page-num:hover {background:#f3f4f6;}
.dq-fr-unpaid-pagination-controls .page-num.active {background:#dc3545;color:#fff;border-color:#dc3545;}
.dq-fr-unpaid-no-results {text-align:center;padding:40px 20px;color:#666;font-style:italic;}
</style>';

        echo '<div id="' . esc_attr($modal_id) . '" class="dq-fr-unpaid-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr($modal_id) . '-title">';
        echo '  <div class="dq-fr-unpaid-modal-window">';
        echo '    <button class="dq-fr-unpaid-modal-close" onclick="window.dqCloseUnpaidModal();event.preventDefault();" aria-label="Close modal">&times;</button>';
        echo '    <h2 id="' . esc_attr($modal_id) . '-title">Unpaid Invoices — ' . esc_html($period_label) . '</h2>';

        if ( empty( $processed_invoices ) ) {
            echo '    <p><em>No unpaid invoices found for this period.</em></p>';
        } else {
            // Controls: Summary stats, filters, CSV button
            echo '<div class="dq-fr-unpaid-modal-controls">';
            echo '  <div class="dq-fr-unpaid-summary">';
            echo '    <div class="dq-fr-unpaid-summary-item overdue">Overdue<strong id="dq-unpaid-overdue-total">' . self::money($total_overdue) . '</strong></div>';
            echo '    <div class="dq-fr-unpaid-summary-item incoming">Not due yet<strong id="dq-unpaid-incoming-total">' . self::money($total_incoming) . '</strong></div>';
            echo '    <div class="dq-fr-unpaid-summary-item overdue">Unpaid<strong id="dq-unpaid-incoming-total">' . self::money($total_incoming + $total_overdue) . '</strong></div>';
            echo '  </div>';
            echo '  <div class="dq-fr-unpaid-filters">';
            echo '    <label>Filter:</label>';
            echo '    <button type="button" class="active" data-filter="all">Show All</button>';
            echo '    <button type="button" data-filter="overdue">Overdue</button>';
            echo '    <button type="button" data-filter="incoming">Incoming</button>';
            echo '  </div>';
            echo '  <button type="button" class="dq-fr-unpaid-csv-btn" id="dq-unpaid-csv-btn">Download CSV</button>';
            echo '</div>';

            // Table with sortable headers
            echo '<table class="dq-fr-unpaid-table" id="dq-unpaid-table">';
            echo '  <thead><tr>';
            echo '    <th>Invoice #</th>';
            echo '    <th>Amount</th>';
            echo '    <th>Balance</th>';
            echo '    <th class="sortable" data-sort="invoice_date">Invoice Date</th>';
            echo '    <th class="sortable asc" data-sort="due_date">Due Date</th>';
            echo '    <th class="sortable" data-sort="remaining_days">Remaining Days</th>';
            echo '  </tr></thead>';
            echo '  <tbody id="dq-unpaid-tbody">';
            // Rows will be rendered by JavaScript for proper pagination/sorting/filtering
            echo '  </tbody>';
            echo '</table>';
            echo '<div class="dq-fr-unpaid-no-results" id="dq-unpaid-no-results" style="display:none;">No invoices match the current filter.</div>';

            // Pagination controls
            echo '<div class="dq-fr-unpaid-pagination">';
            echo '  <div class="dq-fr-unpaid-pagination-info" id="dq-unpaid-pagination-info">Showing 1-50 of ' . count($processed_invoices) . ' invoices</div>';
            echo '  <div class="dq-fr-unpaid-pagination-controls">';
            echo '    <button type="button" id="dq-unpaid-prev-btn" disabled>&laquo; Previous</button>';
            echo '    <div class="page-numbers" id="dq-unpaid-page-numbers"></div>';
            echo '    <button type="button" id="dq-unpaid-next-btn">Next &raquo;</button>';
            echo '  </div>';
            echo '</div>';

            // Embed invoice data as JSON for JavaScript
            echo '<script type="application/json" id="dq-unpaid-invoices-data">' . wp_json_encode($processed_invoices) . '</script>';
        }

        echo '  </div>';
        echo '</div>';

        // JavaScript for sorting, filtering, pagination, and CSV export
        self::render_unpaid_modal_javascript();
    }

    /**
     * Render JavaScript for the advanced unpaid invoices modal functionality.
     */
    private static function render_unpaid_modal_javascript() {
        // Define pagination constant (used in both PHP and JS)
        $items_per_page = 50;
        ?>
<script>
(function() {
    var dataEl = document.getElementById('dq-unpaid-invoices-data');
    if (!dataEl) return;

    var allInvoices = [];
    try {
        allInvoices = JSON.parse(dataEl.textContent);
    } catch(e) {
        console.error('Failed to parse unpaid invoices data:', e);
        return;
    }

    // Constants for sorting fallback values
    var FALLBACK_DATE = '9999-12-31';  // Used for invoices without due dates (sort to end)
    var FALLBACK_DAYS = 999999;        // Used for invoices without remaining days (sort to end)
    var ITEMS_PER_PAGE = <?php echo (int) $items_per_page; ?>;

    var currentPage = 1;
    var currentFilter = 'all';
    var currentSort = 'due_date';
    var currentSortDir = 'asc';
    var filteredInvoices = [];

    var tbody = document.getElementById('dq-unpaid-tbody');
    var paginationInfo = document.getElementById('dq-unpaid-pagination-info');
    var pageNumbers = document.getElementById('dq-unpaid-page-numbers');
    var prevBtn = document.getElementById('dq-unpaid-prev-btn');
    var nextBtn = document.getElementById('dq-unpaid-next-btn');
    var noResults = document.getElementById('dq-unpaid-no-results');
    var table = document.getElementById('dq-unpaid-table');
    var filterBtns = document.querySelectorAll('.dq-fr-unpaid-filters button');
    var sortableHeaders = document.querySelectorAll('.dq-fr-unpaid-table th.sortable');
    var csvBtn = document.getElementById('dq-unpaid-csv-btn');

    function formatMoney(val) {
        var num = parseFloat(val) || 0;
        return '$' + num.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function applyFilter() {
        if (currentFilter === 'all') {
            filteredInvoices = allInvoices.slice();
        } else if (currentFilter === 'overdue') {
            filteredInvoices = allInvoices.filter(function(inv) { return inv.is_overdue === true; });
        } else if (currentFilter === 'incoming') {
            filteredInvoices = allInvoices.filter(function(inv) { return inv.is_overdue === false; });
        }
    }

    function applySort() {
        filteredInvoices.sort(function(a, b) {
            var aVal, bVal;
            if (currentSort === 'invoice_date') {
                aVal = a.invoice_date_sort || '';
                bVal = b.invoice_date_sort || '';
            } else if (currentSort === 'due_date') {
                aVal = a.due_date_sort || FALLBACK_DATE;
                bVal = b.due_date_sort || FALLBACK_DATE;
            } else if (currentSort === 'remaining_days') {
                aVal = a.remaining_days_num !== null ? a.remaining_days_num : FALLBACK_DAYS;
                bVal = b.remaining_days_num !== null ? b.remaining_days_num : FALLBACK_DAYS;
            }
            var cmp = 0;
            if (typeof aVal === 'number' && typeof bVal === 'number') {
                cmp = aVal - bVal;
            } else {
                cmp = String(aVal).localeCompare(String(bVal));
            }
            return currentSortDir === 'asc' ? cmp : -cmp;
        });
    }

    function renderTable() {
        applyFilter();
        applySort();

        var totalPages = Math.ceil(filteredInvoices.length / ITEMS_PER_PAGE) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        var start = (currentPage - 1) * ITEMS_PER_PAGE;
        var end = Math.min(start + ITEMS_PER_PAGE, filteredInvoices.length);
        var pageInvoices = filteredInvoices.slice(start, end);

        // Clear tbody
        tbody.innerHTML = '';

        if (pageInvoices.length === 0) {
            noResults.style.display = 'block';
            table.style.display = 'none';
        } else {
            noResults.style.display = 'none';
            table.style.display = '';
            pageInvoices.forEach(function(inv) {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><a href="' + escapeHtml(inv.edit_link) + '" target="_blank">' + escapeHtml(inv.invoice_no) + '</a></td>' +
                    '<td>' + formatMoney(inv.total_billed) + '</td>' +
                    '<td><span class="dq-fr-metric-unpaid">' + formatMoney(inv.balance_due) + '</span></td>' +
                    '<td>' + escapeHtml(inv.invoice_date || 'N/A') + '</td>' +
                    '<td>' + escapeHtml(inv.due_date) + '</td>' +
                    '<td><span class="' + escapeHtml(inv.remaining_class) + '">' + escapeHtml(inv.remaining_days_text) + '</span></td>';
                tbody.appendChild(tr);
            });
        }

        // Update pagination info
        if (filteredInvoices.length === 0) {
            paginationInfo.textContent = 'No invoices to display';
        } else {
            paginationInfo.textContent = 'Showing ' + (start + 1) + '-' + end + ' of ' + filteredInvoices.length + ' invoices';
        }

        // Update page numbers
        renderPageNumbers(totalPages);

        // Update prev/next buttons
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
    }

    function renderPageNumbers(totalPages) {
        pageNumbers.innerHTML = '';
        var maxVisible = 5;
        var startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        var endPage = Math.min(totalPages, startPage + maxVisible - 1);
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        if (startPage > 1) {
            var firstBtn = document.createElement('button');
            firstBtn.className = 'page-num';
            firstBtn.textContent = '1';
            firstBtn.onclick = function() { currentPage = 1; renderTable(); };
            pageNumbers.appendChild(firstBtn);
            if (startPage > 2) {
                var dots = document.createElement('span');
                dots.textContent = '...';
                dots.style.padding = '6px 4px';
                pageNumbers.appendChild(dots);
            }
        }

        for (var i = startPage; i <= endPage; i++) {
            var btn = document.createElement('button');
            btn.className = 'page-num' + (i === currentPage ? ' active' : '');
            btn.textContent = i;
            btn.onclick = (function(page) {
                return function() { currentPage = page; renderTable(); };
            })(i);
            pageNumbers.appendChild(btn);
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                var dots = document.createElement('span');
                dots.textContent = '...';
                dots.style.padding = '6px 4px';
                pageNumbers.appendChild(dots);
            }
            var lastBtn = document.createElement('button');
            lastBtn.className = 'page-num';
            lastBtn.textContent = totalPages;
            lastBtn.onclick = function() { currentPage = totalPages; renderTable(); };
            pageNumbers.appendChild(lastBtn);
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Filter button handlers
    filterBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            filterBtns.forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentFilter = btn.getAttribute('data-filter');
            currentPage = 1;
            renderTable();
        });
    });

    // Sort header handlers
    sortableHeaders.forEach(function(th) {
        th.addEventListener('click', function() {
            var sortKey = th.getAttribute('data-sort');
            if (currentSort === sortKey) {
                currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort = sortKey;
                currentSortDir = 'asc';
            }
            // Update header classes
            sortableHeaders.forEach(function(h) {
                h.classList.remove('asc', 'desc');
            });
            th.classList.add(currentSortDir);
            currentPage = 1;
            renderTable();
        });
    });

    // Pagination button handlers
    prevBtn.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            renderTable();
        }
    });

    nextBtn.addEventListener('click', function() {
        var totalPages = Math.ceil(filteredInvoices.length / ITEMS_PER_PAGE) || 1;
        if (currentPage < totalPages) {
            currentPage++;
            renderTable();
        }
    });

    // CSV export handler
    csvBtn.addEventListener('click', function() {
        applyFilter();
        applySort();

        var csvRows = [];
        // Header row
        csvRows.push(['Invoice #', 'Amount', 'Balance', 'Invoice Date', 'Due Date', 'Remaining Days']);

        // Data rows (export currently filtered/sorted data)
        filteredInvoices.forEach(function(inv) {
            csvRows.push([
                inv.invoice_no || '',
                parseFloat(inv.total_billed || 0).toFixed(2),
                parseFloat(inv.balance_due || 0).toFixed(2),
                inv.invoice_date || '',
                inv.due_date || '',
                inv.remaining_days_text || ''
            ]);
        });

        // Convert to CSV string
        var csvContent = csvRows.map(function(row) {
            return row.map(function(cell) {
                var str = String(cell);
                if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1 || str.indexOf('\n') !== -1) {
                    str = '"' + str.replace(/"/g, '""') + '"';
                }
                return str;
            }).join(',');
        }).join('\n');

        // Download CSV
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', 'unpaid-invoices.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    });

    // Initial render
    renderTable();
})();
</script>
        <?php
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
        // Use the centralized timezone-aware helper function
        return dqqb_normalize_date_for_storage( $raw );
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

        // Check for password protection on CSV download
        if ( ! self::has_valid_access() ) {
            wp_die( 'Please authenticate first by visiting the Financial Reports page.' );
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
            fputcsv( $out, ['Date', 'Amount', 'Assigned To'] );

            foreach ( $payroll_records as $record ) {
                // Get assigned user display name
                $assigned_to = 'Unassigned';
                if ( isset( $record['user_id'] ) && $record['user_id'] > 0 ) {
                    $user = get_user_by( 'id', $record['user_id'] );
                    if ( $user ) {
                        $assigned_to = $user->display_name;
                    }
                }
                
                fputcsv( $out, [
                    $record['date'],
                    number_format($record['amount'],2,'.',''),
                    $assigned_to,
                ] );
            }

            fputcsv( $out, ['Total Payroll', number_format($payroll_total,2,'.',''), '' ] );
        }

        // Add net profit after payroll
        fputcsv( $out, [] );
        $net_profit = $totals['profit'] - $payroll_total;
        fputcsv( $out, ['Net Profit (After Payroll)', number_format($net_profit,2,'.','') ] );

        fclose($out);
        exit;
    }

    /**
     * Render payroll management section (form + records table + modal).
     */
    private static function render_payroll_section( $report, $year, $month, $quarter, $range ) {
        if ( ! class_exists( 'DQ_Payroll' ) ) {
            echo '<div class="dq-payroll-section" style="margin-top:30px;">';
            echo '<h2>Payroll Management</h2>';
            echo '<p><em>Payroll module not available.</em></p>';
            echo '</div>';
            return;
        }

        $is_admin = DQ_Payroll::user_can_manage();
        $period_label = self::human_date_label( $report, $year, $month, $quarter );
        $modal_id = 'dq-payroll-modal';
        $records = DQ_Payroll::get_records( $range['start'], $range['end'] );
        $total = 0.0;
        foreach ( $records as $record ) {
            $total += (float) $record['amount'];
        }

        // Modal Styles
        echo '<style>
.dq-payroll-modal-overlay { position:fixed; inset:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:100000; display:none; overflow-y:auto; }
.dq-payroll-modal-window { background:#fff; max-width:900px; margin:40px auto; padding:24px 20px 20px; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.15); position:relative; }
.dq-payroll-modal-close { position:absolute; right:16px; top:12px; font-size:32px; background:transparent; border:none; color:#333; cursor:pointer; line-height:1; }
.dq-payroll-modal-close:hover { color:#c40000; }
.dq-payroll-modal-close:focus { outline:2px solid #0073aa; outline-offset:2px; }
.dq-payroll-modal-form { background:#f9f9f9; padding:16px; border:1px solid #e1e4e8; border-radius:6px; margin-bottom:20px; }
.dq-payroll-modal-form h3 { margin-top:0; margin-bottom:12px; }
.dq-payroll-modal-form .form-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.dq-payroll-modal-form .form-field { display:flex; flex-direction:column; gap:4px; }
.dq-payroll-modal-form .form-field label { font-weight:600; font-size:13px; }
.dq-payroll-modal-form .form-field input,
.dq-payroll-modal-form .form-field select { padding:6px 10px; border:1px solid #ccc; border-radius:4px; }
.dq-payroll-modal-table { width:100%; border-collapse:collapse; background:#fff; margin-top:16px; }
.dq-payroll-modal-table th { background:#006d7b; color:#fff; padding:8px 10px; text-align:left; font-weight:600; font-size:14px; }
.dq-payroll-modal-table td { padding:8px 10px; border-bottom:1px solid #eee; vertical-align:middle; font-size:13px; }
.dq-payroll-modal-table tr:last-child td { border-bottom:none; }
.dq-payroll-modal-table .dq-payroll-totals td { font-weight:600; background:#e6f8fc; }
.dq-payroll-modal-delete { color:#c40000; text-decoration:none; }
.dq-payroll-modal-delete:hover { text-decoration:underline; }
.dq-payroll-modal-user-link { text-decoration:none; color:#0073aa; }
.dq-payroll-modal-user-link:hover { text-decoration:underline; }
.dq-payroll-manage-btn { margin-left:15px; }
</style>';

        echo '<div class="dq-payroll-section" style="margin-top:30px;">';
        echo '<h2>Payroll Management';
        if ( $is_admin ) {
            echo ' <button type="button" class="button button-primary dq-payroll-manage-btn" onclick="document.getElementById(\'' . esc_attr( $modal_id ) . '\').style.display=\'block\'; document.querySelector(\'#' . esc_attr( $modal_id ) . ' .dq-payroll-modal-close\').focus();">Manage Payroll</button>';
            echo ' <button type="button" class="button button-secondary dq-payroll-csv-import-btn" onclick="document.getElementById(\'' . esc_attr( $modal_id ) . '\').style.display=\'block\'; setTimeout(function(){ var importSection = document.querySelector(\'#' . esc_attr( $modal_id ) . ' .dq-payroll-modal-form input[type=file]\'); if(importSection) importSection.scrollIntoView({behavior:\'smooth\', block:\'center\'}); }, 100);" style="margin-left:8px;">CSV Import</button>';
        }
        echo '</h2>';

        // Render add form, records table, and modal (admin only - handled inside the methods)
        if ( class_exists( 'DQ_Payroll' ) ) {
            // Render the Manage Payroll button with modal
            DQ_Payroll::render_modal( $report, $year, $month, $quarter, $range );
            
            // Also render inline form and table for convenience
            DQ_Payroll::render_add_form( $report, $year, $month, $quarter );
            DQ_Payroll::render_records_table( $range['start'], $range['end'] );
        } else {
            echo '    <table class="dq-payroll-modal-table">';
            echo '      <thead><tr>';
            echo '        <th>Date</th>';
            echo '        <th>Amount</th>';
            echo '        <th>Assigned To</th>';
            echo '        <th>Actions</th>';
            echo '      </tr></thead><tbody>';

            $total = 0.0;
            foreach ( $records as $record ) {
                $total += (float) $record['amount'];
                // Use timezone-aware parsing for date display
                $date_ts = dqqb_parse_date_for_comparison( $record['date'] );
                $date_display = $date_ts ? wp_date( 'M j, Y', $date_ts ) : $record['date'];

                // Get assigned user display
                $user_display = 'Unassigned';
                if ( ! empty( $record['user_id'] ) && $record['user_id'] > 0 ) {
                    $user = get_user_by( 'id', $record['user_id'] );
                    if ( $user ) {
                        $edit_user_url = get_edit_user_link( $user->ID );
                        $user_display = '<a href="' . esc_url( $edit_user_url ) . '" class="dq-payroll-modal-user-link" target="_blank" rel="noopener">' . esc_html( $user->display_name ) . '</a>';
                    }
                }

                $delete_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=dq_payroll_delete&payroll_id=' . $record['post_id'] ),
                    DQ_Payroll::NONCE_DELETE_ACTION . '_' . $record['post_id'],
                    '_wpnonce_payroll_delete'
                );

                echo '      <tr>';
                echo '        <td>' . esc_html( $date_display ) . '</td>';
                echo '        <td>$' . number_format( (float) $record['amount'], 2 ) . '</td>';
                echo '        <td>' . $user_display . '</td>';
                echo '        <td><a href="' . esc_url( $delete_url ) . '" class="dq-payroll-modal-delete" onclick="return confirm(\'Are you sure you want to delete this payroll record?\');">Delete</a></td>';
                echo '      </tr>';
            }

            // Total row
            echo '      <tr class="dq-payroll-totals">';
            echo '        <td>Total Payroll</td>';
            echo '        <td>$' . number_format( $total, 2 ) . '</td>';
            echo '        <td></td>';
            echo '        <td></td>';
            echo '      </tr>';

            echo '    </tbody></table>';
        }

        echo '  </div>';
        echo '</div>';

        // JavaScript for modal close functionality
        echo '<script>
(function(){
    var modalId = "' . esc_js( $modal_id ) . '";
    var modal = document.getElementById(modalId);

    window.dqClosePayrollModal = function() {
        if (modal) modal.style.display = "none";
    };

    // Close on ESC key
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape" && modal && modal.style.display === "block") {
            dqClosePayrollModal();
        }
    });

    // Close on overlay click
    if (modal) {
        modal.addEventListener("click", function(ev) {
            if (ev.target === modal) dqClosePayrollModal();
        });
    }
})();
</script>';
    }
}
