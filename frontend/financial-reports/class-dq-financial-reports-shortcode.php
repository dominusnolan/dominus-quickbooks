<?php
/**
 * Financial Reports Shortcode
 *
 * Provides the [dq-financial-reports] shortcode for embedding the unpaid invoices
 * popup modal UI on frontend pages. This shortcode renders the same functionality
 * as the admin Financial Reports unpaid invoices modal.
 *
 * @package DominusQuickBooks
 * @subpackage Frontend
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DQ_Financial_Reports_Shortcode
 *
 * Handles the [dq-financial-reports] shortcode registration, rendering,
 * and the REST API endpoint for fetching unpaid invoice data.
 *
 * Usage:
 *   [dq-financial-reports] - Renders a button that opens the unpaid invoices modal
 *   [dq-financial-reports button_text="View Unpaid Invoices"] - Custom button text
 *   [dq-financial-reports inline="true"] - Renders inline summary instead of button
 *
 * @since 0.3.0
 */
class DQ_Financial_Reports_Shortcode {

    /**
     * Field name constants (same as DQ_Financial_Report).
     */
    const FIELD_DATE         = 'qi_invoice_date';
    const FIELD_DUE_DATE     = 'qi_due_date';
    const FIELD_INVOICE_NO   = 'qi_invoice_no';
    const FIELD_TOTAL_BILLED = 'qi_total_billed';
    const FIELD_BALANCE_DUE  = 'qi_balance_due';

    /**
     * REST API namespace.
     */
    const REST_NAMESPACE = 'dq-financial-reports/v1';

    /**
     * Track if assets have been enqueued.
     *
     * @var bool
     */
    private static $assets_enqueued = false;

    /**
     * Track shortcode instance count for unique IDs.
     *
     * @var int
     */
    private static $instance_count = 0;

    /**
     * Initialize the shortcode.
     *
     * @since 0.3.0
     */
    public static function init() {
        add_shortcode( 'dq-financial-reports', [ __CLASS__, 'render_shortcode' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
    }

    /**
     * Register frontend assets.
     *
     * @since 0.3.0
     */
    public static function register_assets() {
        $plugin_url = DQQB_URL;
        $version    = DQQB_VERSION;

        wp_register_style(
            'dq-financial-reports-shortcode',
            $plugin_url . 'frontend/financial-reports/dq-financial-reports.css',
            [],
            $version
        );

        wp_register_script(
            'dq-financial-reports-shortcode',
            $plugin_url . 'frontend/financial-reports/dq-financial-reports.js',
            [],
            $version,
            true
        );
    }

    /**
     * Enqueue assets only when shortcode is used.
     *
     * @since 0.3.0
     */
    private static function enqueue_assets() {
        if ( self::$assets_enqueued ) {
            return;
        }

        wp_enqueue_style( 'dq-financial-reports-shortcode' );
        wp_enqueue_script( 'dq-financial-reports-shortcode' );

        // Localize script with REST API info.
        wp_localize_script( 'dq-financial-reports-shortcode', 'dqFinancialReportsVars', [
            'restUrl'  => esc_url_raw( rest_url( self::REST_NAMESPACE . '/unpaid-invoices' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'isAdmin'  => current_user_can( 'manage_options' ),
        ] );

        self::$assets_enqueued = true;
    }

    /**
     * Check if the current user can view financial reports.
     *
     * @since 0.3.0
     * @return bool True if user has permission, false otherwise.
     */
    public static function user_can_view() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        // Allow users with view_financial_reports capability or administrators.
        return current_user_can( 'view_financial_reports' ) || current_user_can( 'manage_options' );
    }

    /**
     * Render the shortcode.
     *
     * @since 0.3.0
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts(
            [
                'button_text' => 'View Unpaid Invoices',
                'inline'      => 'false',
                'class'       => '',
            ],
            $atts,
            'dq-financial-reports'
        );

        // Check user permissions.
        if ( ! self::user_can_view() ) {
            return self::render_access_denied();
        }

        // Enqueue assets only on pages where shortcode is used.
        self::enqueue_assets();

        // Generate unique instance ID.
        self::$instance_count++;
        $instance_id = 'dq-fr-shortcode-' . self::$instance_count;

        // Render output.
        if ( $atts['inline'] === 'true' ) {
            return self::render_inline( $instance_id, $atts );
        }

        return self::render_button( $instance_id, $atts );
    }

    /**
     * Render access denied message.
     *
     * @since 0.3.0
     * @return string HTML output.
     */
    private static function render_access_denied() {
        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );
            return '<div class="dq-fr-access-denied">' .
                   '<p>' . esc_html__( 'Please log in to view financial reports.', 'dominus-quickbooks' ) . '</p>' .
                   '<a href="' . esc_url( $login_url ) . '" class="dq-fr-login-btn">' . esc_html__( 'Log In', 'dominus-quickbooks' ) . '</a>' .
                   '</div>';
        }

        return '<div class="dq-fr-access-denied">' .
               '<p>' . esc_html__( 'You do not have permission to view financial reports.', 'dominus-quickbooks' ) . '</p>' .
               '</div>';
    }

    /**
     * Render button that opens the modal.
     *
     * @since 0.3.0
     * @param string $instance_id Unique instance identifier.
     * @param array  $atts        Shortcode attributes.
     * @return string HTML output.
     */
    private static function render_button( $instance_id, $atts ) {
        $extra_class = ! empty( $atts['class'] ) ? ' ' . esc_attr( $atts['class'] ) : '';

        $output = '<div id="' . esc_attr( $instance_id ) . '" class="dq-fr-shortcode-wrapper' . $extra_class . '">';
        $output .= '<button type="button" class="dq-fr-open-modal-btn" aria-haspopup="dialog">';
        $output .= '<span class="dashicons dashicons-chart-pie"></span> ';
        $output .= esc_html( $atts['button_text'] );
        $output .= '</button>';
        $output .= self::render_modal_container( $instance_id );
        $output .= '</div>';

        return $output;
    }

    /**
     * Render inline summary with open modal capability.
     *
     * @since 0.3.0
     * @param string $instance_id Unique instance identifier.
     * @param array  $atts        Shortcode attributes.
     * @return string HTML output.
     */
    private static function render_inline( $instance_id, $atts ) {
        $extra_class = ! empty( $atts['class'] ) ? ' ' . esc_attr( $atts['class'] ) : '';

        $output = '<div id="' . esc_attr( $instance_id ) . '" class="dq-fr-shortcode-wrapper dq-fr-inline' . $extra_class . '" data-inline="true">';
        $output .= '<div class="dq-fr-inline-summary">';
        $output .= '<div class="dq-fr-inline-loading">' . esc_html__( 'Loading...', 'dominus-quickbooks' ) . '</div>';
        $output .= '</div>';
        $output .= self::render_modal_container( $instance_id );
        $output .= '</div>';

        return $output;
    }

    /**
     * Render the modal container HTML.
     *
     * @since 0.3.0
     * @param string $instance_id Unique instance identifier.
     * @return string HTML output.
     */
    private static function render_modal_container( $instance_id ) {
        $modal_id = $instance_id . '-modal';

        return '<div id="' . esc_attr( $modal_id ) . '" class="dq-fr-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $modal_id ) . '-title" tabindex="-1" style="display:none;">' .
               '<div class="dq-fr-modal-window">' .
               '<button type="button" class="dq-fr-modal-close" aria-label="' . esc_attr__( 'Close modal', 'dominus-quickbooks' ) . '">&times;</button>' .
               '<h2 id="' . esc_attr( $modal_id ) . '-title">' . esc_html__( 'Unpaid Invoices', 'dominus-quickbooks' ) . '</h2>' .
               '<div class="dq-fr-modal-content">' .
               '<div class="dq-fr-loading">' . esc_html__( 'Loading...', 'dominus-quickbooks' ) . '</div>' .
               '</div>' .
               '</div>' .
               '</div>';
    }

    /**
     * Register REST API routes.
     *
     * @since 0.3.0
     */
    public static function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/unpaid-invoices',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'rest_get_unpaid_invoices' ],
                'permission_callback' => [ __CLASS__, 'rest_permission_check' ],
                'args'                => [
                    'start_date' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ],
                    'end_date' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ],
                ],
            ]
        );
    }

    /**
     * Permission check for REST API.
     *
     * @since 0.3.0
     * @return bool|WP_Error True if allowed, WP_Error otherwise.
     */
    public static function rest_permission_check() {
        if ( ! self::user_can_view() ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to access this resource.', 'dominus-quickbooks' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    /**
     * REST API callback to get unpaid invoices.
     *
     * @since 0.3.0
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response|WP_Error Response or error.
     */
    public static function rest_get_unpaid_invoices( $request ) {
        $start_date = $request->get_param( 'start_date' );
        $end_date   = $request->get_param( 'end_date' );

        // Default to all time if no dates specified.
        if ( empty( $start_date ) ) {
            $start_date = '2000-01-01';
        }
        if ( empty( $end_date ) ) {
            $end_date = date( 'Y-m-d' );
        }

        $unpaid_invoices = self::get_unpaid_invoices( $start_date, $end_date );

        return rest_ensure_response( [
            'success'  => true,
            'invoices' => $unpaid_invoices['invoices'],
            'totals'   => $unpaid_invoices['totals'],
        ] );
    }

    /**
     * Get unpaid invoices data.
     *
     * @since 0.3.0
     * @param string $start Start date (Y-m-d format).
     * @param string $end   End date (Y-m-d format).
     * @return array Array with 'invoices' and 'totals' keys.
     */
    public static function get_unpaid_invoices( $start, $end ) {
        $unpaid  = [];
        $today   = date( 'Y-m-d' );

        $total_overdue  = 0.0;
        $total_incoming = 0.0;

        $invoices = get_posts( [
            'post_type'      => 'quickbooks_invoice',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::FIELD_DATE,
        ] );

        foreach ( $invoices as $pid ) {
            $date_raw  = function_exists( 'get_field' ) ? get_field( self::FIELD_DATE, $pid ) : get_post_meta( $pid, self::FIELD_DATE, true );
            $date_norm = self::normalize_date( $date_raw );

            if ( ! $date_norm ) {
                continue;
            }
            if ( $date_norm < $start || $date_norm > $end ) {
                continue;
            }

            $balance_due = self::num( function_exists( 'get_field' ) ? get_field( self::FIELD_BALANCE_DUE, $pid ) : get_post_meta( $pid, self::FIELD_BALANCE_DUE, true ) );

            // Only include invoices with balance due > 0.
            if ( $balance_due <= 0 ) {
                continue;
            }

            $invoice_no   = function_exists( 'get_field' ) ? get_field( self::FIELD_INVOICE_NO, $pid ) : get_post_meta( $pid, self::FIELD_INVOICE_NO, true );
            $total_billed = self::num( function_exists( 'get_field' ) ? get_field( self::FIELD_TOTAL_BILLED, $pid ) : get_post_meta( $pid, self::FIELD_TOTAL_BILLED, true ) );
            $invoice_date = function_exists( 'get_field' ) ? get_field( self::FIELD_DATE, $pid ) : get_post_meta( $pid, self::FIELD_DATE, true );
            $due_date_raw = function_exists( 'get_field' ) ? get_field( self::FIELD_DUE_DATE, $pid ) : get_post_meta( $pid, self::FIELD_DUE_DATE, true );
            $due_date     = self::normalize_date( $due_date_raw );

            // Calculate remaining days and categorize.
            $remaining_days_num  = null;
            $remaining_days_text = 'N/A';
            $remaining_class     = '';
            $is_overdue          = false;

            if ( $due_date ) {
                $due_date_obj = new DateTime( $due_date );
                $today_obj    = new DateTime( $today );
                $interval     = $today_obj->diff( $due_date_obj );
                $diff_days    = (int) $interval->days;

                if ( $interval->invert === 1 ) {
                    $diff_days = -$diff_days;
                }
                $remaining_days_num = $diff_days;

                if ( $diff_days < 0 ) {
                    $remaining_days_text = abs( $diff_days ) . ' days overdue';
                    $remaining_class     = 'dq-fr-days-overdue';
                    $is_overdue          = true;
                    $total_overdue      += (float) $balance_due;
                } elseif ( $diff_days === 0 ) {
                    $remaining_days_text = 'Due today';
                    $remaining_class     = 'dq-fr-days-overdue';
                    $is_overdue          = true;
                    $total_overdue      += (float) $balance_due;
                } else {
                    $remaining_days_text = $diff_days . ' days';
                    $remaining_class     = 'dq-fr-days-remaining';
                    $total_incoming     += (float) $balance_due;
                }
            } else {
                // No due date - consider as incoming (unknown).
                $total_incoming += (float) $balance_due;
            }

            $unpaid[] = [
                'post_id'             => $pid,
                'invoice_no'          => $invoice_no ?: ( 'Post #' . $pid ),
                'total_billed'        => $total_billed,
                'balance_due'         => $balance_due,
                'invoice_date'        => $invoice_date,
                'invoice_date_sort'   => self::normalize_date( $invoice_date ),
                'due_date'            => $due_date_raw ?: 'N/A',
                'due_date_sort'       => $due_date ?: '9999-12-31',
                'remaining_days_num'  => $remaining_days_num,
                'remaining_days_text' => $remaining_days_text,
                'remaining_class'     => $remaining_class,
                'is_overdue'          => $is_overdue,
                'permalink'           => get_permalink( $pid ),
            ];
        }

        // Sort by due date ascending (soonest first).
        usort( $unpaid, function( $a, $b ) {
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

        return [
            'invoices' => $unpaid,
            'totals'   => [
                'overdue'  => $total_overdue,
                'incoming' => $total_incoming,
                'total'    => $total_overdue + $total_incoming,
            ],
        ];
    }

    /**
     * Normalize date to Y-m-d format.
     *
     * @since 0.3.0
     * @param mixed $raw Raw date value.
     * @return string Normalized date or empty string.
     */
    private static function normalize_date( $raw ) {
        if ( ! $raw ) {
            return '';
        }
        $raw = trim( (string) $raw );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
            return $raw;
        }
        $ts = strtotime( $raw );
        return $ts ? date( 'Y-m-d', $ts ) : '';
    }

    /**
     * Convert value to float.
     *
     * @since 0.3.0
     * @param mixed $v Value to convert.
     * @return float Numeric value.
     */
    private static function num( $v ) {
        if ( $v === null || $v === '' ) {
            return 0.0;
        }
        if ( is_numeric( $v ) ) {
            return (float) $v;
        }
        $clean = preg_replace( '/[^0-9.\-]/', '', (string) $v );
        return ( $clean === '' || ! is_numeric( $clean ) ) ? 0.0 : (float) $clean;
    }
}
