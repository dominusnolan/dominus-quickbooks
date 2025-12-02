<?php
/**
 * Invoices Balance Shortcode
 *
 * Provides a public-facing [invoices-balance] shortcode that displays
 * unpaid/overdue invoices in a table format matching the admin financial report popup.
 *
 * @package Dominus_QuickBooks
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DQ_Invoices_Balance
 *
 * Handles the [invoices-balance] shortcode rendering, REST API endpoint,
 * and CSV export functionality for public invoice balance display.
 */
class DQ_Invoices_Balance {

    /**
     * REST API namespace.
     *
     * @var string
     */
    const REST_NAMESPACE = 'dq-quickbooks/v1';

    /**
     * REST API route.
     *
     * @var string
     */
    const REST_ROUTE = 'invoices-balance';

    /**
     * Items per page for pagination.
     *
     * @var int
     */
    const PER_PAGE = 50;

    /**
     * ACF field name constants - reuse from DQ_Financial_Report.
     */
    const FIELD_DATE        = 'qi_invoice_date';
    const FIELD_DUE_DATE    = 'qi_due_date';
    const FIELD_INVOICE_NO  = 'qi_invoice_no';
    const FIELD_TOTAL_BILLED = 'qi_total_billed';
    const FIELD_BALANCE_DUE = 'qi_balance_due';

    /**
     * Track if assets have been enqueued.
     *
     * @var bool
     */
    private static $assets_enqueued = false;

    /**
     * Initialize the class and register hooks.
     *
     * @return void
     */
    public static function init() {
        add_shortcode( 'invoices-balance', array( __CLASS__, 'render_shortcode' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    /**
     * Register CSS and JS assets.
     *
     * Assets are only enqueued when the shortcode is present on the page.
     *
     * @return void
     */
    public static function register_assets() {
        wp_register_style(
            'dq-invoices-balance',
            DQQB_URL . 'frontend/invoices-balance/dq-invoices-balance.css',
            array(),
            DQQB_VERSION
        );

        wp_register_script(
            'dq-invoices-balance',
            DQQB_URL . 'frontend/invoices-balance/dq-invoices-balance.js',
            array(),
            DQQB_VERSION,
            true
        );
    }

    /**
     * Enqueue assets for the shortcode.
     *
     * Only enqueues once per page load.
     *
     * @return void
     */
    private static function enqueue_assets() {
        if ( self::$assets_enqueued ) {
            return;
        }

        wp_enqueue_style( 'dq-invoices-balance' );
        wp_enqueue_script( 'dq-invoices-balance' );

        // Pass REST API URL to JavaScript.
        wp_localize_script( 'dq-invoices-balance', 'dqInvoicesBalance', array(
            'restUrl' => rest_url( self::REST_NAMESPACE . '/' . self::REST_ROUTE ),
        ) );

        self::$assets_enqueued = true;
    }

    /**
     * Register REST API routes.
     *
     * Creates a public, read-only endpoint for fetching invoice data.
     *
     * @return void
     */
    public static function register_rest_routes() {
        register_rest_route( self::REST_NAMESPACE, '/' . self::REST_ROUTE, array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_invoices' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'filter' => array(
                    'type'              => 'string',
                    'enum'              => array( 'all', 'overdue', 'incoming' ),
                    'default'           => 'all',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );

        register_rest_route( self::REST_NAMESPACE, '/' . self::REST_ROUTE . '/csv', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_download_csv' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'filter' => array(
                    'type'              => 'string',
                    'enum'              => array( 'all', 'overdue', 'incoming' ),
                    'default'           => 'all',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );
    }

    /**
     * REST API callback to get invoice data.
     *
     * Returns only the fields needed for the table display.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response The response containing invoice data.
     */
    public static function rest_get_invoices( $request ) {
        $filter = $request->get_param( 'filter' );
        $data   = self::get_invoice_data( $filter );

        return rest_ensure_response( $data );
    }

    /**
     * REST API callback to download CSV.
     *
     * Generates and returns CSV file matching admin report format.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return void Outputs CSV and exits.
     */
    public static function rest_download_csv( $request ) {
        $filter = $request->get_param( 'filter' );
        $data   = self::get_invoice_data( $filter );

        $filename = 'unpaid-invoices-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // CSV header row - matches admin report format.
        fputcsv( $output, array(
            'Invoice #',
            'Amount',
            'Balance',
            'Invoice Date',
            'Due Date',
            'Remaining Days',
        ) );

        // Data rows.
        foreach ( $data['invoices'] as $invoice ) {
            fputcsv( $output, array(
                $invoice['invoice_no'],
                number_format( $invoice['total_billed'], 2, '.', '' ),
                number_format( $invoice['balance_due'], 2, '.', '' ),
                $invoice['invoice_date'],
                $invoice['due_date'],
                $invoice['remaining_days_text'],
            ) );
        }

        fclose( $output );
        exit;
    }

    /**
     * Get processed invoice data for display.
     *
     * Reuses the query logic from DQ_Financial_Report for consistency.
     *
     * @param string $filter Filter type: 'all', 'overdue', or 'incoming'.
     * @return array Invoice data with totals.
     */
    public static function get_invoice_data( $filter = 'all' ) {
        $today = gmdate( 'Y-m-d' );
        $invoices = array();
        $total_overdue = 0.0;
        $total_incoming = 0.0;

        // Query all unpaid invoices.
        $posts = get_posts( array(
            'post_type'      => 'quickbooks_invoice',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => self::FIELD_BALANCE_DUE,
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ),
            ),
        ) );

        foreach ( $posts as $post ) {
            $invoice_no   = self::get_field( self::FIELD_INVOICE_NO, $post->ID );
            $total_billed = self::get_numeric_field( self::FIELD_TOTAL_BILLED, $post->ID );
            $balance_due  = self::get_numeric_field( self::FIELD_BALANCE_DUE, $post->ID );
            $invoice_date = self::get_field( self::FIELD_DATE, $post->ID );
            $due_date_raw = self::get_field( self::FIELD_DUE_DATE, $post->ID );
            $due_date     = self::normalize_date( $due_date_raw );

            // Calculate remaining days.
            $remaining_days_num  = null;
            $remaining_days_text = 'N/A';
            $is_overdue          = false;

            if ( $due_date ) {
                $due_date_obj = new DateTime( $due_date );
                $today_obj    = new DateTime( $today );
                $interval     = $today_obj->diff( $due_date_obj );
                $diff_days    = (int) $interval->days;

                // If invert is 1, the due date is in the past (overdue).
                if ( $interval->invert === 1 ) {
                    $diff_days = -$diff_days;
                }

                $remaining_days_num = $diff_days;

                if ( $diff_days < 0 ) {
                    $remaining_days_text = abs( $diff_days ) . ' days overdue';
                    $is_overdue          = true;
                    $total_overdue      += $balance_due;
                } elseif ( 0 === $diff_days ) {
                    $remaining_days_text = 'Due today';
                    $is_overdue          = true;
                    $total_overdue      += $balance_due;
                } else {
                    $remaining_days_text = $diff_days . ' days';
                    $total_incoming     += $balance_due;
                }
            } else {
                // No due date - consider as incoming (unknown).
                $total_incoming += $balance_due;
            }

            // Apply filter.
            if ( 'overdue' === $filter && ! $is_overdue ) {
                continue;
            }
            if ( 'incoming' === $filter && $is_overdue ) {
                continue;
            }

            $invoices[] = array(
                'post_id'             => $post->ID,
                'invoice_no'          => $invoice_no ? $invoice_no : ( 'Post #' . $post->ID ),
                'total_billed'        => $total_billed,
                'balance_due'         => $balance_due,
                'invoice_date'        => $invoice_date ? $invoice_date : 'N/A',
                'invoice_date_sort'   => self::normalize_date( $invoice_date ),
                'due_date'            => $due_date_raw ? $due_date_raw : 'N/A',
                'due_date_sort'       => $due_date ? $due_date : '9999-12-31',
                'remaining_days_num'  => $remaining_days_num,
                'remaining_days_text' => $remaining_days_text,
                'is_overdue'          => $is_overdue,
                'permalink'           => get_permalink( $post->ID ),
            );
        }

        // Sort by due date ascending (soonest first).
        usort( $invoices, function ( $a, $b ) {
            if ( empty( $a['due_date_sort'] ) && empty( $b['due_date_sort'] ) ) {
                return 0;
            }
            if ( empty( $a['due_date_sort'] ) ) {
                return 1;
            }
            if ( empty( $b['due_date_sort'] ) ) {
                return -1;
            }
            return strcmp( $a['due_date_sort'], $b['due_date_sort'] );
        } );

        return array(
            'invoices'       => $invoices,
            'total_overdue'  => $total_overdue,
            'total_incoming' => $total_incoming,
            'total_count'    => count( $invoices ),
        );
    }

    /**
     * Render the shortcode output.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(), $atts, 'invoices-balance' );

        // Enqueue assets.
        self::enqueue_assets();

        // Get initial data.
        $data = self::get_invoice_data( 'all' );

        ob_start();
        ?>
        <div id="dq-invoices-balance" class="dq-invoices-balance-wrapper" role="region" aria-label="Unpaid Invoices">
            <!-- Summary Cards -->
            <div class="dq-ib-summary">
                <div class="dq-ib-summary-card dq-ib-overdue" aria-label="Total Overdue">
                    <span class="dq-ib-summary-label">Overdue</span>
                    <span class="dq-ib-summary-value" id="dq-ib-overdue-total">$<?php echo esc_html( number_format( $data['total_overdue'], 2 ) ); ?></span>
                </div>
                <div class="dq-ib-summary-card dq-ib-incoming" aria-label="Total Incoming">
                    <span class="dq-ib-summary-label">Not due yet</span>
                    <span class="dq-ib-summary-value" id="dq-ib-incoming-total">$<?php echo esc_html( number_format( $data['total_incoming'], 2 ) ); ?></span>
                </div>

                <div class="dq-ib-summary-card dq-ib-overdue" aria-label="Total Incoming">
                    <span class="dq-ib-summary-label">Unpaid</span>
                    <span class="dq-ib-summary-value" id="dq-ib-incoming-total">$<?php echo esc_html( number_format( $data['total_incoming'] +  $data['total_overdue'], 2 ) ); ?></span>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="dq-ib-controls">
                <div class="dq-ib-filters" role="group" aria-label="Filter invoices">
                    <button type="button" class="dq-ib-filter-btn active" data-filter="all" aria-pressed="true">
                        Show All <span class="dq-ib-count">(<?php echo esc_html( $data['total_count'] ); ?>)</span>
                    </button>
                    <button type="button" class="dq-ib-filter-btn" data-filter="overdue" aria-pressed="false">
                        Overdue
                    </button>
                    <button type="button" class="dq-ib-filter-btn" data-filter="incoming" aria-pressed="false">
                        Incoming
                    </button>
                </div>
                <button type="button" class="dq-ib-csv-btn" id="dq-ib-download-csv" aria-label="Download invoices as CSV">
                    Download CSV
                </button>
            </div>

            <!-- Invoices Table -->
            <div class="dq-ib-table-container">
                <table class="dq-ib-table" id="dq-ib-table" role="table" aria-label="Unpaid invoices list">
                    <thead>
                        <tr>
                            <th scope="col" class="dq-ib-sortable" data-sort="invoice_no" tabindex="0" role="columnheader" aria-sort="none">
                                Invoice # <span class="dq-ib-sort-icon">⇅</span>
                            </th>
                            <th scope="col">Amount</th>
                            <th scope="col" class="dq-ib-col-balance">Balance</th>
                            <th scope="col" class="dq-ib-sortable" data-sort="invoice_date" tabindex="0" role="columnheader" aria-sort="none">
                                Invoice Date <span class="dq-ib-sort-icon">⇅</span>
                            </th>
                            <th scope="col" class="dq-ib-sortable dq-ib-sorted-asc" data-sort="due_date" tabindex="0" role="columnheader" aria-sort="ascending">
                                Due Date <span class="dq-ib-sort-icon">↑</span>
                            </th>
                            <th scope="col" class="dq-ib-sortable" data-sort="remaining_days" tabindex="0" role="columnheader" aria-sort="none">
                                Remaining Days <span class="dq-ib-sort-icon">⇅</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="dq-ib-tbody">
                        <?php self::render_table_rows( $data['invoices'] ); ?>
                    </tbody>
                </table>
            </div>

            <!-- No Results Message -->
            <div class="dq-ib-no-results" id="dq-ib-no-results" style="display: none;">
                No invoices match the current filter.
            </div>

            <!-- Pagination -->
            <div class="dq-ib-pagination" id="dq-ib-pagination">
                <span class="dq-ib-pagination-info" id="dq-ib-pagination-info">
                    Showing <?php echo min( self::PER_PAGE, $data['total_count'] ); ?> of <?php echo esc_html( $data['total_count'] ); ?> invoices
                </span>
                <div class="dq-ib-pagination-controls">
                    <button type="button" class="dq-ib-page-btn" id="dq-ib-prev-btn" disabled aria-label="Previous page">
                        « Previous
                    </button>
                    <div class="dq-ib-page-numbers" id="dq-ib-page-numbers"></div>
                    <button type="button" class="dq-ib-page-btn" id="dq-ib-next-btn" aria-label="Next page">
                        Next »
                    </button>
                </div>
            </div>

            <!-- Initial data for JavaScript -->
            <script type="application/json" id="dq-ib-initial-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render table rows.
     *
     * @param array $invoices Array of invoice data.
     * @return void
     */
    private static function render_table_rows( $invoices ) {
        if ( empty( $invoices ) ) {
            return;
        }

        $count = 0;
        foreach ( $invoices as $invoice ) {
            if ( $count >= self::PER_PAGE ) {
                break;
            }

            $balance_class = $invoice['is_overdue'] ? 'dq-ib-overdue-cell' : '';
            $days_class    = $invoice['is_overdue'] ? 'dq-ib-days-overdue' : 'dq-ib-days-remaining';

            ?>
            <tr data-overdue="<?php echo $invoice['is_overdue'] ? 'true' : 'false'; ?>">
                <td>
                    <a href="<?php echo esc_url( $invoice['permalink'] ); ?>" aria-label="View invoice <?php echo esc_attr( $invoice['invoice_no'] ); ?>">
                        <?php echo esc_html( $invoice['invoice_no'] ); ?>
                    </a>
                </td>
                <td>$<?php echo esc_html( number_format( $invoice['total_billed'], 2 ) ); ?></td>
                <td class="<?php echo esc_attr( $balance_class ); ?>">
                    $<?php echo esc_html( number_format( $invoice['balance_due'], 2 ) ); ?>
                </td>
                <td><?php echo esc_html( $invoice['invoice_date'] ); ?></td>
                <td><?php echo esc_html( $invoice['due_date'] ); ?></td>
                <td class="<?php echo esc_attr( $days_class ); ?>">
                    <?php echo esc_html( $invoice['remaining_days_text'] ); ?>
                </td>
            </tr>
            <?php
            $count++;
        }
    }

    /**
     * Get ACF field value or fall back to post meta.
     *
     * @param string $field_name The field name.
     * @param int    $post_id    The post ID.
     * @return mixed The field value.
     */
    private static function get_field( $field_name, $post_id ) {
        if ( function_exists( 'get_field' ) ) {
            return get_field( $field_name, $post_id );
        }
        return get_post_meta( $post_id, $field_name, true );
    }

    /**
     * Get numeric field value.
     *
     * @param string $field_name The field name.
     * @param int    $post_id    The post ID.
     * @return float The numeric value.
     */
    private static function get_numeric_field( $field_name, $post_id ) {
        $value = self::get_field( $field_name, $post_id );

        if ( null === $value || '' === $value ) {
            return 0.0;
        }
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }

        // Clean currency formatting.
        $clean = preg_replace( '/[^0-9.\-]/', '', (string) $value );
        return ( '' === $clean || ! is_numeric( $clean ) ) ? 0.0 : (float) $clean;
    }

    /**
     * Normalize date to Y-m-d format.
     *
     * @param mixed $raw The raw date value.
     * @return string The normalized date or empty string.
     */
    private static function normalize_date( $raw ) {
        if ( ! $raw ) {
            return '';
        }

        $raw = trim( (string) $raw );

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
            return $raw;
        }

        $timestamp = strtotime( $raw );
        return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
    }
}
