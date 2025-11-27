<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Payroll CPT and management for Financial Reports.
 * Registers CPT 'payroll', handles add/delete actions,
 * and provides helper methods for querying payroll records.
 */
class DQ_Payroll {

    const CPT_SLUG           = 'payroll';
    const FIELD_AMOUNT       = 'payroll_amount';
    const NONCE_ADD_ACTION   = 'dq_payroll_add';
    const NONCE_DELETE_ACTION = 'dq_payroll_delete';

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_cpt' ] );
        add_action( 'admin_post_dq_payroll_add', [ __CLASS__, 'handle_add' ] );
        add_action( 'admin_post_dq_payroll_delete', [ __CLASS__, 'handle_delete' ] );
    }

    /**
     * Register the payroll custom post type.
     */
    public static function register_cpt() {
        $labels = [
            'name'               => 'Payroll',
            'singular_name'      => 'Payroll',
            'menu_name'          => 'Payroll',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Payroll',
            'edit_item'          => 'Edit Payroll',
            'new_item'           => 'New Payroll',
            'view_item'          => 'View Payroll',
            'search_items'       => 'Search Payroll',
            'not_found'          => 'No payroll records found',
            'not_found_in_trash' => 'No payroll records found in Trash',
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false, // We manage display via Financial Report
            'menu_icon'           => 'dashicons-money',
            'capability_type'     => 'post',
            'capabilities'        => [
                'edit_post'          => 'manage_options',
                'read_post'          => 'manage_options',
                'delete_post'        => 'manage_options',
                'edit_posts'         => 'manage_options',
                'edit_others_posts'  => 'manage_options',
                'publish_posts'      => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_posts'       => 'manage_options',
            ],
            'map_meta_cap'        => false,
            'hierarchical'        => false,
            'supports'            => [ 'title' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
        ];

        register_post_type( self::CPT_SLUG, $args );
    }

    /**
     * Check if current user can manage payroll.
     *
     * @return bool
     */
    public static function user_can_manage() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Handle add payroll form submission.
     */
    public static function handle_add() {
        if ( ! self::user_can_manage() ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( self::NONCE_ADD_ACTION, '_wpnonce_payroll_add' );

        $date   = isset( $_POST['payroll_date'] ) ? sanitize_text_field( wp_unslash( $_POST['payroll_date'] ) ) : '';
        $amount = isset( $_POST['payroll_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['payroll_amount'] ) ) : '';

        // Validate date
        if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_die( 'Invalid date format. Please use YYYY-MM-DD.' );
        }

        // Validate amount
        $amount_clean = preg_replace( '/[^0-9.\-]/', '', $amount );
        if ( $amount_clean === '' || ! is_numeric( $amount_clean ) ) {
            wp_die( 'Invalid amount.' );
        }
        $amount_value = floatval( $amount_clean );

        // Create payroll post
        $post_id = wp_insert_post( [
            'post_type'   => self::CPT_SLUG,
            'post_status' => 'publish',
            'post_title'  => 'Payroll ' . $date,
            'post_date'   => $date . ' 12:00:00',
        ] );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            wp_die( 'Failed to create payroll record.' );
        }

        // Store amount - use ACF if available, fallback to post meta
        if ( function_exists( 'update_field' ) ) {
            update_field( self::FIELD_AMOUNT, $amount_value, $post_id );
        } else {
            update_post_meta( $post_id, self::FIELD_AMOUNT, $amount_value );
        }

        // Redirect back to the referring page
        $redirect_url = self::get_redirect_url();
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handle delete payroll action.
     */
    public static function handle_delete() {
        if ( ! self::user_can_manage() ) {
            wp_die( 'Permission denied.' );
        }

        $post_id = isset( $_GET['payroll_id'] ) ? intval( $_GET['payroll_id'] ) : 0;

        if ( ! $post_id ) {
            wp_die( 'Invalid payroll ID.' );
        }

        check_admin_referer( self::NONCE_DELETE_ACTION . '_' . $post_id, '_wpnonce_payroll_delete' );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== self::CPT_SLUG ) {
            wp_die( 'Invalid payroll record.' );
        }

        wp_delete_post( $post_id, true );

        // Redirect back to the referring page
        $redirect_url = self::get_redirect_url();
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Get redirect URL from referer or default to financial reports page.
     *
     * @return string
     */
    private static function get_redirect_url() {
        $referer = wp_get_referer();
        if ( $referer ) {
            return $referer;
        }
        return admin_url( 'admin.php?page=dq-financial-reports' );
    }

    /**
     * Get payroll records for a date range.
     *
     * @param string $start Start date (Y-m-d).
     * @param string $end   End date (Y-m-d).
     * @return array Array of payroll records with post_id, date, amount.
     */
    public static function get_records( $start, $end ) {
        $posts = get_posts( [
            'post_type'      => self::CPT_SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'date_query'     => [
                [
                    'after'     => $start,
                    'before'    => $end,
                    'inclusive' => true,
                ],
            ],
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $records = [];
        foreach ( $posts as $post ) {
            $amount = self::get_amount( $post->ID );
            $records[] = [
                'post_id' => $post->ID,
                'date'    => get_the_date( 'Y-m-d', $post ),
                'amount'  => $amount,
            ];
        }

        return $records;
    }

    /**
     * Get total payroll amount for a date range.
     *
     * @param string $start Start date (Y-m-d).
     * @param string $end   End date (Y-m-d).
     * @return float
     */
    public static function get_total( $start, $end ) {
        $records = self::get_records( $start, $end );
        $total   = 0.0;
        foreach ( $records as $record ) {
            $total += (float) $record['amount'];
        }
        return $total;
    }

    /**
     * Get amount for a payroll post.
     *
     * @param int $post_id Post ID.
     * @return float
     */
    public static function get_amount( $post_id ) {
        if ( function_exists( 'get_field' ) ) {
            $amount = get_field( self::FIELD_AMOUNT, $post_id );
        } else {
            $amount = get_post_meta( $post_id, self::FIELD_AMOUNT, true );
        }

        if ( $amount === null || $amount === '' ) {
            return 0.0;
        }

        if ( is_numeric( $amount ) ) {
            return (float) $amount;
        }

        $clean = preg_replace( '/[^0-9.\-]/', '', (string) $amount );
        return ( $clean === '' || ! is_numeric( $clean ) ) ? 0.0 : (float) $clean;
    }

    /**
     * Render the payroll quick entry form (admin only).
     *
     * @param string $report  Report type (monthly, quarterly, yearly).
     * @param int    $year    Year.
     * @param int    $month   Month.
     * @param int    $quarter Quarter.
     */
    public static function render_add_form( $report, $year, $month, $quarter ) {
        if ( ! self::user_can_manage() ) {
            return;
        }

        $nonce_field = wp_nonce_field( self::NONCE_ADD_ACTION, '_wpnonce_payroll_add', true, false );

        // Build hidden fields to preserve current filter state
        $hidden_fields  = '<input type="hidden" name="report" value="' . esc_attr( $report ) . '">';
        $hidden_fields .= '<input type="hidden" name="year" value="' . esc_attr( $year ) . '">';
        $hidden_fields .= '<input type="hidden" name="month" value="' . esc_attr( $month ) . '">';
        $hidden_fields .= '<input type="hidden" name="quarter" value="' . esc_attr( $quarter ) . '">';

        // Default date to today
        $default_date = date( 'Y-m-d' );

        echo '<div class="dq-payroll-add-form" style="background:#fff;padding:16px;border:1px solid #e1e4e8;border-radius:6px;margin:15px 0 25px;max-width:600px;">';
        echo '<h3 style="margin-top:0;">Add Payroll Record</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<input type="hidden" name="action" value="dq_payroll_add">';
        echo $nonce_field;
        echo $hidden_fields;
        echo '<div>';
        echo '<label style="font-weight:600;display:block;margin-bottom:4px;">Date</label>';
        echo '<input type="date" name="payroll_date" value="' . esc_attr( $default_date ) . '" required style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;">';
        echo '</div>';
        echo '<div>';
        echo '<label style="font-weight:600;display:block;margin-bottom:4px;">Amount ($)</label>';
        echo '<input type="number" name="payroll_amount" step="0.01" min="0" required placeholder="0.00" style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;width:120px;">';
        echo '</div>';
        echo '<div>';
        echo '<input type="submit" class="button button-primary" value="Add Payroll">';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render payroll records table for the given date range.
     *
     * @param string $start Start date (Y-m-d).
     * @param string $end   End date (Y-m-d).
     */
    public static function render_records_table( $start, $end ) {
        $records = self::get_records( $start, $end );

        if ( empty( $records ) ) {
            echo '<p><em>No payroll records for this period.</em></p>';
            return;
        }

        $is_admin = self::user_can_manage();

        echo '<style>
.dq-payroll-table { width:100%; max-width:600px; border-collapse:collapse; background:#fff; margin-bottom:20px; }
.dq-payroll-table th { background:#006d7b; color:#fff; padding:8px 10px; text-align:left; font-weight:600; }
.dq-payroll-table td { padding:8px 10px; border-bottom:1px solid #eee; vertical-align:middle; }
.dq-payroll-table tr:last-child td { border-bottom:none; }
.dq-payroll-totals td { font-weight:600; background:#e6f8fc; }
.dq-payroll-delete { color:#c40000; text-decoration:none; }
.dq-payroll-delete:hover { text-decoration:underline; }
</style>';

        echo '<table class="dq-payroll-table">';
        echo '<thead><tr>';
        echo '<th>Date</th>';
        echo '<th>Amount</th>';
        if ( $is_admin ) {
            echo '<th>Actions</th>';
        }
        echo '</tr></thead><tbody>';

        $total = 0.0;
        foreach ( $records as $record ) {
            $total += (float) $record['amount'];
            $date_display = date( 'M j, Y', strtotime( $record['date'] ) );

            echo '<tr>';
            echo '<td>' . esc_html( $date_display ) . '</td>';
            echo '<td>$' . number_format( (float) $record['amount'], 2 ) . '</td>';

            if ( $is_admin ) {
                $delete_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=dq_payroll_delete&payroll_id=' . $record['post_id'] ),
                    self::NONCE_DELETE_ACTION . '_' . $record['post_id'],
                    '_wpnonce_payroll_delete'
                );
                echo '<td><a href="' . esc_url( $delete_url ) . '" class="dq-payroll-delete" onclick="return confirm(\'Are you sure you want to delete this payroll record?\');">Delete</a></td>';
            }

            echo '</tr>';
        }

        // Total row
        echo '<tr class="dq-payroll-totals">';
        echo '<td>Total Payroll</td>';
        echo '<td>$' . number_format( $total, 2 ) . '</td>';
        if ( $is_admin ) {
            echo '<td></td>';
        }
        echo '</tr>';

        echo '</tbody></table>';
    }
}
