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
    const FIELD_USER         = 'payroll_user_id';
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

        $date    = isset( $_POST['payroll_date'] ) ? sanitize_text_field( wp_unslash( $_POST['payroll_date'] ) ) : '';
        $amount  = isset( $_POST['payroll_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['payroll_amount'] ) ) : '';
        $user_id = isset( $_POST['payroll_user_id'] ) ? intval( $_POST['payroll_user_id'] ) : 0;

        // Validate date
        if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_die( 'Invalid date format. Please use YYYY-MM-DD.' );
        }

        // Validate amount
        $amount_clean = preg_replace( '/[^0-9.]/', '', $amount );
        if ( $amount_clean === '' || ! is_numeric( $amount_clean ) ) {
            wp_die( 'Invalid amount.' );
        }
        $amount_value = floatval( $amount_clean );

        // Ensure amount is positive
        if ( $amount_value < 0 ) {
            wp_die( 'Amount must be a positive number.' );
        }

        // Validate user_id (if provided, must be existing WP user)
        if ( $user_id > 0 ) {
            $user = get_user_by( 'id', $user_id );
            if ( ! $user ) {
                $user_id = 0; // Invalid user, set to unassigned
            }
        } else {
            $user_id = 0; // Ensure 0 for unassigned
        }

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

        // Store user ID - use ACF if available, fallback to post meta
        if ( function_exists( 'update_field' ) ) {
            update_field( self::FIELD_USER, $user_id, $post_id );
        } else {
            update_post_meta( $post_id, self::FIELD_USER, $user_id );
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
     * @return array Array of payroll records with post_id, date, amount, user_id.
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
            $amount  = self::get_amount( $post->ID );
            $user_id = self::get_user_id( $post->ID );
            $records[] = [
                'post_id' => $post->ID,
                'date'    => get_the_date( 'Y-m-d', $post ),
                'amount'  => $amount,
                'user_id' => $user_id,
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

        $clean = preg_replace( '/[^0-9.]/', '', (string) $amount );
        return ( $clean === '' || ! is_numeric( $clean ) ) ? 0.0 : max( 0.0, (float) $clean );
    }

    /**
     * Get assigned user ID for a payroll post.
     *
     * @param int $post_id Post ID.
     * @return int User ID (0 if unassigned).
     */
    public static function get_user_id( $post_id ) {
        if ( function_exists( 'get_field' ) ) {
            $user_id = get_field( self::FIELD_USER, $post_id );
        } else {
            $user_id = get_post_meta( $post_id, self::FIELD_USER, true );
        }

        return ( $user_id !== null && $user_id !== '' ) ? intval( $user_id ) : 0;
    }

    /**
     * Get users with specific roles for payroll assignment.
     *
     * @return array Array of WP_User objects.
     */
    public static function get_assignable_users() {
        return get_users( [
            'role__in' => [ 'engineer', 'manager', 'staff' ],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ] );
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

        // Default date to today (using WordPress timezone)
        $default_date = wp_date( 'Y-m-d' );

        // Get assignable users
        $users = self::get_assignable_users();

        echo '<div class="dq-payroll-add-form" style="background:#fff;padding:16px;border:1px solid #e1e4e8;border-radius:6px;margin:15px 0 25px;max-width:700px;">';
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
        echo '<label style="font-weight:600;display:block;margin-bottom:4px;">Assigned To</label>';
        echo '<select name="payroll_user_id" style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;min-width:150px;">';
        echo '<option value="0">Unassigned</option>';
        foreach ( $users as $user ) {
            echo '<option value="' . esc_attr( $user->ID ) . '">' . esc_html( $user->display_name ) . '</option>';
        }
        echo '</select>';
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
.dq-payroll-table { width:100%; max-width:800px; border-collapse:collapse; background:#fff; margin-bottom:20px; }
.dq-payroll-table th { background:#006d7b; color:#fff; padding:8px 10px; text-align:left; font-weight:600; }
.dq-payroll-table td { padding:8px 10px; border-bottom:1px solid #eee; vertical-align:middle; }
.dq-payroll-table tr:last-child td { border-bottom:none; }
.dq-payroll-totals td { font-weight:600; background:#e6f8fc; }
.dq-payroll-delete { color:#c40000; text-decoration:none; }
.dq-payroll-delete:hover { text-decoration:underline; }
.dq-payroll-user-link { color:#0073aa; text-decoration:none; }
.dq-payroll-user-link:hover { text-decoration:underline; }
</style>';

        echo '<table class="dq-payroll-table">';
        echo '<thead><tr>';
        echo '<th>Date</th>';
        echo '<th>Amount</th>';
        echo '<th>Assigned To</th>';
        if ( $is_admin ) {
            echo '<th>Actions</th>';
        }
        echo '</tr></thead><tbody>';

        $total = 0.0;
        foreach ( $records as $record ) {
            $total += (float) $record['amount'];
            $date_display = wp_date( 'M j, Y', strtotime( $record['date'] ) );

            // Get assigned user display
            $user_display = 'Unassigned';
            if ( $record['user_id'] > 0 ) {
                $user = get_user_by( 'id', $record['user_id'] );
                if ( $user ) {
                    $edit_user_url = get_edit_user_link( $record['user_id'] );
                    $user_display = '<a href="' . esc_url( $edit_user_url ) . '" class="dq-payroll-user-link">' . esc_html( $user->display_name ) . '</a>';
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html( $date_display ) . '</td>';
            echo '<td>$' . number_format( (float) $record['amount'], 2 ) . '</td>';
            echo '<td>' . $user_display . '</td>';

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
        echo '<td></td>';
        if ( $is_admin ) {
            echo '<td></td>';
        }
        echo '</tr>';

        echo '</tbody></table>';
    }

    /**
     * Render the payroll management modal.
     *
     * @param string $report  Report type (monthly, quarterly, yearly).
     * @param int    $year    Year.
     * @param int    $month   Month.
     * @param int    $quarter Quarter.
     * @param array  $range   Date range with 'start' and 'end' keys.
     */
    public static function render_modal( $report, $year, $month, $quarter, $range ) {
        if ( ! self::user_can_manage() ) {
            return;
        }

        $modal_id = 'dq-payroll-modal';
        $records = self::get_records( $range['start'], $range['end'] );
        $users = self::get_assignable_users();
        $default_date = $range['end']; // Default to period end date
        $nonce_field = wp_nonce_field( self::NONCE_ADD_ACTION, '_wpnonce_payroll_add', true, false );

        // Build hidden fields to preserve current filter state
        $hidden_fields  = '<input type="hidden" name="report" value="' . esc_attr( $report ) . '">';
        $hidden_fields .= '<input type="hidden" name="year" value="' . esc_attr( $year ) . '">';
        $hidden_fields .= '<input type="hidden" name="month" value="' . esc_attr( $month ) . '">';
        $hidden_fields .= '<input type="hidden" name="quarter" value="' . esc_attr( $quarter ) . '">';

        // Modal styles
        echo '<style>
.dq-payroll-modal-overlay {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0,0,0,0.5);
    z-index: 100000;
    display: none;
    overflow-y: auto;
}
.dq-payroll-modal-window {
    background: #fff;
    max-width: 800px;
    margin: 40px auto;
    padding: 24px 20px 20px;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    position: relative;
}
.dq-payroll-modal-close {
    position: absolute;
    right: 16px;
    top: 12px;
    font-size: 32px;
    background: transparent;
    border: none;
    color: #333;
    cursor: pointer;
    line-height: 1;
}
.dq-payroll-modal-close:hover {
    color: #c40000;
}
.dq-payroll-modal-close:focus {
    outline: 2px solid #0073aa;
    outline-offset: 2px;
}
.dq-payroll-modal-form {
    background: #f9f9f9;
    padding: 16px;
    border-radius: 6px;
    margin-bottom: 20px;
}
.dq-payroll-modal-form h3 {
    margin-top: 0;
    margin-bottom: 12px;
}
.dq-payroll-modal-form form {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.dq-payroll-modal-form label {
    font-weight: 600;
    display: block;
    margin-bottom: 4px;
}
.dq-payroll-modal-form input[type="date"],
.dq-payroll-modal-form input[type="number"],
.dq-payroll-modal-form select {
    padding: 6px 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
}
.dq-payroll-modal-form input[type="number"] {
    width: 120px;
}
.dq-payroll-modal-form select {
    min-width: 150px;
}
.dq-payroll-modal-records h3 {
    margin-bottom: 12px;
}
.dq-payroll-modal-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
}
.dq-payroll-modal-table th {
    background: #006d7b;
    color: #fff;
    padding: 8px 10px;
    text-align: left;
    font-weight: 600;
}
.dq-payroll-modal-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
}
.dq-payroll-modal-table tr:last-child td {
    border-bottom: none;
}
.dq-payroll-modal-delete {
    color: #c40000;
    text-decoration: none;
}
.dq-payroll-modal-delete:hover {
    text-decoration: underline;
}
.dq-payroll-modal-user-link {
    color: #0073aa;
    text-decoration: none;
}
.dq-payroll-modal-user-link:hover {
    text-decoration: underline;
}
.dq-payroll-manage-btn {
    background: #006d7b;
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
}
.dq-payroll-manage-btn:hover {
    background: #005a66;
}
</style>';

        // Manage Payroll button
        echo '<button type="button" class="dq-payroll-manage-btn" onclick="document.getElementById(\'' . esc_attr( $modal_id ) . '\').style.display=\'block\'; document.getElementById(\'' . esc_attr( $modal_id ) . '\').querySelector(\'.dq-payroll-modal-close\').focus();">Manage Payroll</button>';

        // Modal markup
        echo '<div id="' . esc_attr( $modal_id ) . '" class="dq-payroll-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $modal_id ) . '-title">';
        echo '  <div class="dq-payroll-modal-window">';
        echo '    <button type="button" class="dq-payroll-modal-close" onclick="document.getElementById(\'' . esc_attr( $modal_id ) . '\').style.display=\'none\'; event.preventDefault();" aria-label="Close modal">&times;</button>';
        echo '    <h2 id="' . esc_attr( $modal_id ) . '-title">Manage Payroll</h2>';

        // Add form section
        echo '    <div class="dq-payroll-modal-form">';
        echo '      <h3>Add Payroll Record</h3>';
        echo '      <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '        <input type="hidden" name="action" value="dq_payroll_add">';
        echo $nonce_field;
        echo $hidden_fields;
        echo '        <div>';
        echo '          <label>Date</label>';
        echo '          <input type="date" name="payroll_date" value="' . esc_attr( $default_date ) . '" required>';
        echo '        </div>';
        echo '        <div>';
        echo '          <label>Amount ($)</label>';
        echo '          <input type="number" name="payroll_amount" step="0.01" min="0" required placeholder="0.00">';
        echo '        </div>';
        echo '        <div>';
        echo '          <label>Assigned To</label>';
        echo '          <select name="payroll_user_id">';
        echo '            <option value="0">Unassigned</option>';
        foreach ( $users as $user ) {
            echo '            <option value="' . esc_attr( $user->ID ) . '">' . esc_html( $user->display_name ) . '</option>';
        }
        echo '          </select>';
        echo '        </div>';
        echo '        <div>';
        echo '          <input type="submit" class="button button-primary" value="Add Payroll">';
        echo '        </div>';
        echo '      </form>';
        echo '    </div>';

        // Records list section
        echo '    <div class="dq-payroll-modal-records">';
        echo '      <h3>Recent Records</h3>';

        if ( empty( $records ) ) {
            echo '      <p><em>No payroll records for this period.</em></p>';
        } else {
            echo '      <table class="dq-payroll-modal-table">';
            echo '        <thead><tr>';
            echo '          <th>Date</th>';
            echo '          <th>Amount</th>';
            echo '          <th>Assigned To</th>';
            echo '          <th>Actions</th>';
            echo '        </tr></thead><tbody>';

            foreach ( $records as $record ) {
                $date_display = wp_date( 'M j, Y', strtotime( $record['date'] ) );

                // Get assigned user display
                $user_display = 'Unassigned';
                if ( $record['user_id'] > 0 ) {
                    $user = get_user_by( 'id', $record['user_id'] );
                    if ( $user ) {
                        $edit_user_url = get_edit_user_link( $record['user_id'] );
                        $user_display = '<a href="' . esc_url( $edit_user_url ) . '" class="dq-payroll-modal-user-link" target="_blank">' . esc_html( $user->display_name ) . '</a>';
                    }
                }

                $delete_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=dq_payroll_delete&payroll_id=' . $record['post_id'] ),
                    self::NONCE_DELETE_ACTION . '_' . $record['post_id'],
                    '_wpnonce_payroll_delete'
                );

                echo '        <tr>';
                echo '          <td>' . esc_html( $date_display ) . '</td>';
                echo '          <td>$' . number_format( (float) $record['amount'], 2 ) . '</td>';
                echo '          <td>' . $user_display . '</td>';
                echo '          <td><a href="' . esc_url( $delete_url ) . '" class="dq-payroll-modal-delete" onclick="return confirm(\'Are you sure you want to delete this payroll record?\');">Delete</a></td>';
                echo '        </tr>';
            }

            echo '        </tbody></table>';
        }

        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // JavaScript for modal behavior (ESC to close, click overlay to close)
        echo '<script>
(function(){
    var modalId = "' . esc_js( $modal_id ) . '";
    var modal = document.getElementById(modalId);
    if (!modal) return;

    // Close on ESC key
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape" && modal.style.display === "block") {
            modal.style.display = "none";
        }
    });

    // Close on overlay click
    modal.addEventListener("click", function(ev) {
        if (ev.target === modal) {
            modal.style.display = "none";
        }
    });
})();
</script>';
    }
}
