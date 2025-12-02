<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_Workorder_Admin_Table
 *
 * Adds custom admin columns, sorting, and expandable details section
 * for the 'workorder' custom post type in WordPress admin.
 *
 * Features:
 * - Custom columns with relevant ACF field data
 * - Sortable columns for dates and Work Order ID
 * - Field Engineer column with profile picture from ACF user field
 * - Field Engineer filter dropdown for filtering workorders by author
 * - Status column from taxonomy
 * - AJAX-powered expand/collapse for detailed information
 *
 * @since 0.2.0
 */
class DQ_Workorder_Admin_Table {

    /**
     * Initialize hooks for the workorder admin table
     *
     * @return void
     */
    public static function init() {
        // Column management
        add_filter( 'manage_edit-workorder_columns', [ __CLASS__, 'columns' ] );
        add_action( 'manage_workorder_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
        add_filter( 'manage_edit-workorder_sortable_columns', [ __CLASS__, 'sortable_columns' ] );

        // Sorting logic
        add_action( 'pre_get_posts', [ __CLASS__, 'handle_sorting' ] );

        // Default sort by post date (latest first)
        add_action( 'pre_get_posts', [ __CLASS__, 'set_default_sort' ] );

        // Field Engineer (author) filter dropdown
        add_action( 'restrict_manage_posts', [ __CLASS__, 'render_engineer_filter_dropdown' ] );

        // Filter query by selected engineer (author)
        add_action( 'pre_get_posts', [ __CLASS__, 'filter_by_engineer' ] );

        // Enqueue admin assets
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

        // AJAX handler for expandable details
        add_action( 'wp_ajax_dq_workorder_expand_details', [ __CLASS__, 'ajax_expand_details' ] );

        // AJAX handler for inline QA toggle
        add_action( 'wp_ajax_dq_toggle_quality_assurance', [ __CLASS__, 'ajax_toggle_quality_assurance' ] );
    }

    /**
     * Define custom columns for workorder admin list
     *
     * Removes default columns and adds custom ones per requirements:
     * 1. Work Order ID (sortable)
     * 2. Field Engineer with picture
     * 3. Status (taxonomy)
     * 4. QA inline toggle
     * 5. Product ID
     * 6. State
     * 7. City
     * 8. Date Dispatched (sortable)
     * 9. Date Scheduled (sortable)
     * 10. Date Closed by FSE (sortable)
     * 11. Date FSR Closed in SMAX (sortable)
     * 12. Expand link/button
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function columns( $columns ) {
        // Remove default columns we don't need
        unset( $columns['title'] );
        unset( $columns['date'] );
        unset( $columns['author'] );
        unset( $columns['categories'] );

        // Define new columns in required order
        $new_columns = [];
        $new_columns['cb']                 = '<input type="checkbox" />';
        $new_columns['wo_id']              = __( 'Work Order ID', 'dqqb' );
        $new_columns['wo_field_engineer']  = __( 'Field Engineer', 'dqqb' );
        $new_columns['wo_status']          = __( 'Status', 'dqqb' );
        $new_columns['wo_quality_assurance'] = __( 'QA', 'dqqb' );
        $new_columns['wo_product_id']      = __( 'Product ID', 'dqqb' );
        $new_columns['wo_state']           = __( 'State', 'dqqb' );
        $new_columns['wo_city']            = __( 'City', 'dqqb' );
        $new_columns['wo_date_dispatched'] = __( 'Date Dispatched', 'dqqb' );
        $new_columns['wo_date_scheduled']  = __( 'Date Scheduled', 'dqqb' );
        $new_columns['wo_date_fse_closed'] = __( 'Date Closed by FSE', 'dqqb' );
        $new_columns['wo_date_smax_closed'] = __( 'Date FSR Closed in SMAX', 'dqqb' );
        $new_columns['wo_expand']          = __( 'Expand', 'dqqb' );

        return $new_columns;
    }

    /**
     * Populate custom column content
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     * @return void
     */
    public static function column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'cb':
                echo '<input type="checkbox" name="post[]" value="' . esc_attr( $post_id ) . '" />';
                break;

            case 'wo_id':
                self::render_column_wo_id( $post_id );
                break;

            case 'wo_field_engineer':
                self::render_column_field_engineer( $post_id );
                break;

            case 'wo_status':
                self::render_column_status( $post_id );
                break;

            case 'wo_quality_assurance':
                self::render_column_quality_assurance( $post_id );
                break;

            case 'wo_product_id':
                self::render_column_product_id( $post_id );
                break;

            case 'wo_state':
                self::render_column_state( $post_id );
                break;

            case 'wo_city':
                self::render_column_city( $post_id );
                break;

            case 'wo_date_dispatched':
                self::render_column_date_dispatched( $post_id );
                break;

            case 'wo_date_scheduled':
                self::render_column_date_scheduled( $post_id );
                break;

            case 'wo_date_fse_closed':
                self::render_column_date_fse_closed( $post_id );
                break;

            case 'wo_date_smax_closed':
                self::render_column_date_smax_closed( $post_id );
                break;

            case 'wo_expand':
                self::render_column_expand( $post_id );
                break;
        }
    }

    /**
     * Render Work Order ID column with link to edit
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_wo_id( $post_id ) {
        $title    = get_the_title( $post_id );
        $edit_url = get_edit_post_link( $post_id );
        echo '<a href="' . esc_url( $edit_url ) . '"><strong>' . esc_html( $title ) . '</strong></a>';
    }

    /**
     * Render Field Engineer column with profile picture and username
     *
     * Uses ACF user field 'profile_picture' for the image,
     * falls back to WordPress avatar if not available.
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_field_engineer( $post_id ) {
        $author_id = get_post_field( 'post_author', $post_id );

        if ( ! $author_id ) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        $user = get_userdata( $author_id );
        if ( ! $user ) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        $display_name = esc_html( $user->display_name );

        // Get profile picture from ACF user field
        $profile_img_html = '';
        if ( function_exists( 'get_field' ) ) {
            $acf_img = get_field( 'profile_picture', 'user_' . $author_id );

            if ( is_array( $acf_img ) && ! empty( $acf_img['url'] ) ) {
                $img_url = esc_url( $acf_img['url'] );
                $profile_img_html = '<img src="' . $img_url . '" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:8px;background:#f0f0f0;" />';
            } elseif ( is_numeric( $acf_img ) ) {
                // ACF might return attachment ID
                $img_url = wp_get_attachment_image_url( $acf_img, 'thumbnail' );
                if ( $img_url ) {
                    $profile_img_html = '<img src="' . esc_url( $img_url ) . '" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:8px;background:#f0f0f0;" />';
                }
            } elseif ( is_string( $acf_img ) && filter_var( $acf_img, FILTER_VALIDATE_URL ) ) {
                $profile_img_html = '<img src="' . esc_url( $acf_img ) . '" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:8px;background:#f0f0f0;" />';
            }
        }

        // Fallback to WordPress avatar
        if ( empty( $profile_img_html ) ) {
            $avatar_url       = get_avatar_url( $author_id, [ 'size' => 64 ] );
            $profile_img_html = '<img src="' . esc_url( $avatar_url ) . '" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:8px;background:#f0f0f0;" />';
        }

        echo '<div style="display:flex;align-items:center;">' . $profile_img_html . '<span>' . $display_name . '</span></div>';
    }

    /**
     * Render Status column from taxonomy
     *
     * Uses the 'category' taxonomy and filters for term(s) whose name is 'uncategorized'.
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_status( $post_id ) {
        $status_terms = get_the_terms( $post_id, 'category' );
        $status_value = '';
        if ( ! empty( $status_terms ) && ! is_wp_error( $status_terms ) ) {
            $filtered_terms = array_filter( $status_terms, function( $term ) {
                return strtolower( $term->name ) !== 'uncategorized';
            } );
            $status_names = array_map( function( $term ) {
                return esc_html( $term->name );
            }, $filtered_terms );
            $status_value = implode( ', ', $status_names );
        }

        echo $status_value;
    }

    /**
     * Render QA column with inline toggle button (green check if done, gray if not)
     *
     * @param int $post_id
     * @return void
     */
    private static function render_column_quality_assurance( $post_id ) {
        $done  = self::is_quality_assurance_done( $post_id );
        $label = $done ? __( 'Quality assurance complete', 'dqqb' ) : __( 'Quality assurance not done', 'dqqb' );

        // Button using dashicons-yes, colored via classes
        printf(
            '<button type="button" class="dq-qa-toggle dashicons dashicons-yes %1$s" data-post-id="%2$d" data-done="%3$s" aria-label="%4$s" title="%4$s"></button>',
            $done ? 'dq-qa-checked' : 'dq-qa-unchecked',
            (int) $post_id,
            $done ? '1' : '0',
            esc_attr( $label )
        );
    }

    /**
     * Try common QA keys to determine if QA is done.
     * Adjust order or pin to your canonical key if needed.
     *
     * @param int $post_id
     * @return bool
     */
    private static function is_quality_assurance_done( $post_id ) {
        $keys = [ 'quality_assurance', 'wo_quality_assurance', 'qa_done', 'quality_assurance_done' ];

        foreach ( $keys as $key ) {
            $raw = self::get_acf_or_meta( $key, $post_id );

            // If no value stored, continue
            if ( $raw === '' ) {
                continue;
            }

            $val = strtolower( trim( (string) $raw ) );
            if ( in_array( $val, [ '1', 'yes', 'true', 'on', 'checked', 'done', 'complete', 'completed' ], true ) ) {
                return true;
            }
            if ( in_array( $val, [ '0', 'no', 'false', 'off', 'unchecked', 'not done' ], true ) ) {
                return false;
            }
        }

        return false;
    }

    /**
     * Decide which meta/ACF key to use for updating QA.
     * Prefers an existing ACF field or existing meta, else falls back to "quality_assurance".
     *
     * @param int $post_id
     * @return string
     */
    private static function determine_qa_key( $post_id ) {
        $candidates = [ 'quality_assurance', 'wo_quality_assurance', 'qa_done', 'quality_assurance_done' ];

        // Prefer an actual ACF field if present
        if ( function_exists( 'get_field_object' ) ) {
            foreach ( $candidates as $key ) {
                $obj = get_field_object( $key, $post_id );
                if ( $obj && is_array( $obj ) ) {
                    return $key;
                }
            }
        }

        // Otherwise prefer an existing meta key with any value (including "0")
        foreach ( $candidates as $key ) {
            $meta = get_post_meta( $post_id, $key, true );
            if ( $meta !== '' ) {
                return $key;
            }
        }

        // Default
        return 'quality_assurance';
    }

    /**
     * Render Product ID column
     *
     * Uses ACF field: installed_product_id
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_product_id( $post_id ) {
        $value = self::get_acf_or_meta( 'installed_product_id', $post_id );
        echo $value ? esc_html( $value ) : '<span style="color:#999;">—</span>';
    }

    /**
     * Render State column
     *
     * Uses ACF field: wo_state
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_state( $post_id ) {
        $value = self::get_acf_or_meta( 'wo_state', $post_id );
        echo $value ? esc_html( $value ) : '<span style="color:#999;">—</span>';
    }

    /**
     * Render City column
     *
     * Uses ACF field: wo_city
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_city( $post_id ) {
        $value = self::get_acf_or_meta( 'wo_city', $post_id );
        echo $value ? esc_html( $value ) : '<span style="color:#999;">—</span>';
    }

    /**
     * Render Date Dispatched column
     *
     * Uses ACF field: wo_date_received, falls back to post_date if empty
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_date_dispatched( $post_id ) {
        $value = self::get_acf_or_meta( 'wo_date_received', $post_id );

        if ( empty( $value ) ) {
            // Fall back to post date
            $value = get_post_field( 'post_date', $post_id );
        }

        echo self::format_date_display( $value );
    }

    /**
     * Render Date Scheduled column
     *
     * Uses ACF field: schedule_date_time
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_date_scheduled( $post_id ) {
        $value = self::get_acf_or_meta( 'schedule_date_time', $post_id );
        echo self::format_date_display( $value );
    }

    /**
     * Render Date Closed by FSE column
     *
     * Uses ACF field: date_service_completed_by_fse
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_date_fse_closed( $post_id ) {
        $value = self::get_acf_or_meta( 'date_service_completed_by_fse', $post_id );
        echo self::format_date_display( $value );
    }

    /**
     * Render Date FSR Closed in SMAX column
     *
     * Uses ACF field: closed_on
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_date_smax_closed( $post_id ) {
        $value = self::get_acf_or_meta( 'closed_on', $post_id );
        echo self::format_date_display( $value );
    }

    /**
     * Render Expand button column
     *
     * Creates a button that triggers AJAX to load detailed information
     *
     * @param int $post_id Post ID
     * @return void
     */
    private static function render_column_expand( $post_id ) {
        echo '<button type="button" class="dq-wo-expand-btn button button-small" data-post-id="' . esc_attr( $post_id ) . '">';
        echo '<span class="dashicons dashicons-arrow-down-alt2" style="vertical-align:middle;"></span>';
        echo ' ' . esc_html__( 'Expand', 'dqqb' );
        echo '</button>';
    }

    /**
     * Define sortable columns
     *
     * Makes Work Order ID and date columns sortable.
     *
     * @param array $columns Sortable columns
     * @return array Modified sortable columns
     */
    public static function sortable_columns( $columns ) {
        $columns['wo_id']               = 'title';
        $columns['wo_date_dispatched']  = 'wo_date_dispatched';
        $columns['wo_date_scheduled']   = 'wo_date_scheduled';
        $columns['wo_date_fse_closed']  = 'wo_date_fse_closed';
        $columns['wo_date_smax_closed'] = 'wo_date_smax_closed';

        // If you later want QA sortable, map wo_quality_assurance to a meta key in handle_sorting()
        // $columns['wo_quality_assurance'] = 'wo_quality_assurance';

        return $columns;
    }

    /**
     * Set default sort order to post date (latest first)
     *
     * @param WP_Query $query The query object
     * @return void
     */
    public static function set_default_sort( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-workorder' ) {
            return;
        }

        // Only apply default sort if no orderby is explicitly set
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check, no action taken
        if ( ! isset( $_GET['orderby'] ) ) {
            $query->set( 'orderby', 'date' );
            $query->set( 'order', 'DESC' );
        }
    }

    /**
     * Render Field Engineer filter dropdown
     *
     * Adds a dropdown to the workorder admin list table that allows filtering
     * workorders by the assigned Field Engineer (post author).
     * Only users with the 'engineer' role are shown in the dropdown.
     * Uses transient caching to avoid repeated database queries.
     *
     * @param string $post_type The current post type
     * @return void
     */
    public static function render_engineer_filter_dropdown( $post_type ) {
        // Only show on workorder admin list
        if ( $post_type !== 'workorder' ) {
            return;
        }

        // Get engineers from cache or database
        $engineers = self::get_engineers_for_filter();

        // Don't show dropdown if no engineers exist
        if ( empty( $engineers ) ) {
            return;
        }

        // Get currently selected engineer from query string
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no action taken
        $selected = isset( $_GET['dq_field_engineer'] ) ? absint( $_GET['dq_field_engineer'] ) : 0;

        ?>
        <select name="dq_field_engineer" id="dq-field-engineer-filter">
            <option value=""><?php esc_html_e( 'All Field Engineers', 'dqqb' ); ?></option>
            <?php foreach ( $engineers as $engineer ) : ?>
                <option value="<?php echo esc_attr( $engineer->ID ); ?>" <?php selected( $selected, $engineer->ID ); ?>>
                    <?php echo esc_html( $engineer->display_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Get engineers for the filter dropdown with caching
     *
     * Retrieves all users with the 'engineer' role and caches the result
     * using WordPress transients to reduce database queries on page loads.
     *
     * @return array Array of user objects with ID and display_name
     */
    private static function get_engineers_for_filter() {
        $cache_key = 'dq_engineer_filter_users';
        $engineers = get_transient( $cache_key );

        if ( false === $engineers ) {
            $engineers = get_users( [
                'role'    => 'engineer',
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'fields'  => [ 'ID', 'display_name' ],
            ] );

            // Cache for 5 minutes - short enough to pick up new engineers quickly
            set_transient( $cache_key, $engineers, 5 * MINUTE_IN_SECONDS );
        }

        return $engineers;
    }

    /**
     * Filter workorder query by selected Field Engineer
     *
     * Modifies the main query to filter workorders by post author
     * when a Field Engineer is selected in the dropdown filter.
     *
     * @param WP_Query $query The query object
     * @return void
     */
    public static function filter_by_engineer( $query ) {
        // Only apply in admin on main query
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        // Check if we're on the workorder admin list screen
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-workorder' ) {
            return;
        }

        // Check if an engineer filter is selected (empty string means "All")
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no action taken
        if ( ! isset( $_GET['dq_field_engineer'] ) || '' === $_GET['dq_field_engineer'] ) {
            return;
        }

        // Sanitize and set the author query (WordPress user IDs start at 1)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no action taken
        $engineer_id = absint( $_GET['dq_field_engineer'] );

        if ( $engineer_id >= 1 ) {
            $query->set( 'author', $engineer_id );
        }
    }

    /**
     * Handle custom column sorting
     *
     * Modifies the query to sort by meta values for date columns.
     *
     * @param WP_Query $query The query object
     * @return void
     */
    public static function handle_sorting( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-workorder' ) {
            return;
        }

        $orderby = $query->get( 'orderby' );

        // Map column names to meta keys
        $meta_key_map = [
            'wo_date_dispatched'  => 'wo_date_received',
            'wo_date_scheduled'   => 'schedule_date_time',
            'wo_date_fse_closed'  => 'date_service_completed_by_fse',
            'wo_date_smax_closed' => 'closed_on',
            // If enabling QA sorting:
            // 'wo_quality_assurance' => 'quality_assurance',
        ];

        if ( isset( $meta_key_map[ $orderby ] ) ) {
            $query->set( 'meta_key', $meta_key_map[ $orderby ] );
            // Use meta_value for date sorting - dates are stored in sortable format
            $query->set( 'orderby', 'meta_value' );
            $query->set( 'meta_type', 'DATE' );
        }
    }

    /**
     * Enqueue admin scripts and styles for workorder admin table
     *
     * @param string $hook The current admin page hook
     * @return void
     */
    public static function enqueue_admin_assets( $hook ) {
        // Only load on workorder list page
        if ( $hook !== 'edit.php' ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'workorder' ) {
            return;
        }

        // Inline styles for the admin table
        wp_add_inline_style( 'common', self::get_admin_styles() );

        // Inline script for expand/collapse functionality and QA toggle
        wp_enqueue_script( 'jquery' );
        wp_add_inline_script( 'jquery', self::get_admin_script() );
    }

    /**
     * Get CSS styles for the admin table
     *
     * @return string CSS styles
     */
    private static function get_admin_styles() {
        return '
            /* Workorder Admin Table Styles */
            .dq-wo-expand-btn {
                cursor: pointer;
                white-space: nowrap;
            }
            .dq-wo-expand-btn.expanded .dashicons {
                transform: rotate(180deg);
            }
            .dq-wo-expand-btn .dashicons {
                transition: transform 0.2s ease;
            }

            /* QA toggle button */
            .dq-qa-toggle {
                background: none;
                border: none;
                cursor: pointer;
                padding: 0;
                font-size: 30px;
                line-height: 1;
                vertical-align: middle;
            }
            .dq-qa-toggle.is-loading {
                opacity: 0.6;
                pointer-events: none;
            }
            .dq-qa-checked {
                color: #46b450; /* WP green */
            }
            .dq-qa-unchecked {
                color: #b0b0b0;
                opacity: 0.8;
            }

            /* Expanded details row */
            .dq-wo-details-row {
                background: #f9f9f9 !important;
            }
            .dq-wo-details-row td {
                padding: 0 !important;
                border-top: none !important;
            }
            .dq-wo-details-content {
                padding: 20px;
                background: #f9f9f9;
                border-top: 1px solid #e0e0e0;
            }
            .dq-wo-details-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 20px;
            }
            .dq-wo-details-section {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 6px;
                padding: 16px;
            }
            .dq-wo-details-section h4 {
                margin: 0 0 12px 0;
                padding-bottom: 8px;
                border-bottom: 2px solid #0996a0;
                color: #0996a0;
                font-size: 14px;
                font-weight: 600;
            }
            .dq-wo-details-section dl {
                margin: 0;
            }
            .dq-wo-details-section dt {
                font-weight: 600;
                color: #333;
                font-size: 12px;
                margin-top: 10px;
            }
            .dq-wo-details-section dt:first-child {
                margin-top: 0;
            }
            .dq-wo-details-section dd {
                margin: 2px 0 0 0;
                color: #666;
                font-size: 13px;
            }
            .dq-wo-details-loading {
                text-align: center;
                padding: 20px;
                color: #666;
            }
            .dq-wo-details-loading:after {
                content: "";
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid #ddd;
                border-top-color: #0996a0;
                border-radius: 50%;
                animation: dq-wo-spin 0.8s linear infinite;
                margin-left: 8px;
                vertical-align: middle;
            }
            @keyframes dq-wo-spin {
                to { transform: rotate(360deg); }
            }
        ';
    }

    /**
     * Get JavaScript for expand/collapse functionality and QA toggle
     *
     * @return string JavaScript code
     */
    private static function get_admin_script() {
        $ajax_url      = admin_url( 'admin-ajax.php' );
        $expand_nonce  = wp_create_nonce( 'dq_workorder_expand' );
        $toggle_nonce  = wp_create_nonce( 'dq_toggle_quality_assurance' );

        return '
            jQuery(document).ready(function($) {
                // Store loaded details to avoid repeated AJAX calls
                var loadedDetails = {};

                // Handle expand/collapse button click
                $(document).on("click", ".dq-wo-expand-btn", function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var postId = $btn.data("post-id");
                    var $row = $btn.closest("tr");
                    var $detailsRow = $row.next(".dq-wo-details-row");

                    // If details row exists, toggle visibility
                    if ($detailsRow.length && $detailsRow.data("post-id") == postId) {
                        $detailsRow.toggle();
                        $btn.toggleClass("expanded");
                        $btn.find("span:not(.dashicons)").text($detailsRow.is(":visible") ? "' . esc_js( __( 'Collapse', 'dqqb' ) ) . '" : "' . esc_js( __( 'Expand', 'dqqb' ) ) . '");
                        return;
                    }

                    // Remove any existing details row for this button
                    $row.next(".dq-wo-details-row").remove();

                    // Get column count for colspan
                    var colCount = $row.find("td").length;

                    // Check if we have cached data
                    if (loadedDetails[postId]) {
                        insertDetailsRow($row, postId, loadedDetails[postId], colCount);
                        $btn.addClass("expanded");
                        $btn.find("span:not(.dashicons)").text("' . esc_js( __( 'Collapse', 'dqqb' ) ) . '");
                        return;
                    }

                    // Show loading state
                    var $loadingRow = $("<tr class=\"dq-wo-details-row\" data-post-id=\"" + postId + "\"><td colspan=\"" + colCount + "\"><div class=\"dq-wo-details-loading\">' . esc_js( __( 'Loading details…', 'dqqb' ) ) . '</div></td></tr>");
                    $row.after($loadingRow);
                    $btn.addClass("expanded");
                    $btn.find("span:not(.dashicons)").text("' . esc_js( __( 'Collapse', 'dqqb' ) ) . '");

                    // AJAX request to get details
                    $.ajax({
                        url: "' . esc_js( $ajax_url ) . '",
                        type: "POST",
                        data: {
                            action: "dq_workorder_expand_details",
                            nonce: "' . esc_js( $expand_nonce ) . '",
                            post_id: postId
                        },
                        success: function(response) {
                            if (response.success && response.data && response.data.html) {
                                loadedDetails[postId] = response.data.html;
                                $loadingRow.find("td").html("<div class=\"dq-wo-details-content\">" + response.data.html + "</div>");
                            } else {
                                $loadingRow.find("td").html("<div class=\"dq-wo-details-content\"><p style=\"color:#c00;\">' . esc_js( __( 'Error loading details.', 'dqqb' ) ) . '</p></div>");
                            }
                        },
                        error: function() {
                            $loadingRow.find("td").html("<div class=\"dq-wo-details-content\"><p style=\"color:#c00;\">' . esc_js( __( 'Error loading details.', 'dqqb' ) ) . '</p></div>");
                        }
                    });
                });

                function insertDetailsRow($row, postId, html, colCount) {
                    var $newRow = $("<tr class=\"dq-wo-details-row\" data-post-id=\"" + postId + "\"><td colspan=\"" + colCount + "\"><div class=\"dq-wo-details-content\">" + html + "</div></td></tr>");
                    $row.after($newRow);
                }

                // Inline QA toggle
                $(document).on("click", ".dq-qa-toggle", function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    if ($btn.data("loading")) return;

                    var postId = $btn.data("post-id");
                    var done = parseInt($btn.data("done"), 10) === 1 ? 1 : 0;
                    var next = done ? 0 : 1;

                    $btn.data("loading", true).addClass("is-loading");

                    $.ajax({
                        url: "' . esc_js( $ajax_url ) . '",
                        type: "POST",
                        dataType: "json",
                        data: {
                            action: "dq_toggle_quality_assurance",
                            nonce: "' . esc_js( $toggle_nonce ) . '",
                            post_id: postId,
                            done: next
                        }
                    }).done(function(resp) {
                        if (resp && resp.success && resp.data) {
                            var newDone = resp.data.done ? 1 : 0;
                            $btn.data("done", newDone);
                            $btn.toggleClass("dq-qa-checked", !!newDone).toggleClass("dq-qa-unchecked", !newDone);
                            $btn.attr("aria-label", newDone ? "' . esc_js( __( 'Quality assurance complete', 'dqqb' ) ) . '" : "' . esc_js( __( 'Quality assurance not done', 'dqqb' ) ) . '");
                            $btn.attr("title", newDone ? "' . esc_js( __( 'Quality assurance complete', 'dqqb' ) ) . '" : "' . esc_js( __( 'Quality assurance not done', 'dqqb' ) ) . '");
                        } else {
                            alert("' . esc_js( __( 'Unable to update QA status.', 'dqqb' ) ) . '");
                        }
                    }).fail(function() {
                        alert("' . esc_js( __( 'Error updating QA status.', 'dqqb' ) ) . '");
                    }).always(function() {
                        $btn.data("loading", false).removeClass("is-loading");
                    });
                });
            });
        ';
    }

    /**
     * AJAX handler: toggle QA done status
     */
    public static function ajax_toggle_quality_assurance() {
        // Verify nonce
        if ( ! check_ajax_referer( 'dq_toggle_quality_assurance', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }

        // Validate post id
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ], 400 );
        }

        // Permission
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }

        // Determine desired state (default: toggle to 1)
        $done = isset( $_POST['done'] ) ? (int) wp_unslash( $_POST['done'] ) : 1;
        $done = $done === 1 ? 1 : 0;

        // Determine field key to update
        $key = self::determine_qa_key( $post_id );

        // Update via ACF if available for that field, else meta
        $updated = false;
        if ( function_exists( 'get_field_object' ) && function_exists( 'update_field' ) ) {
            $obj = get_field_object( $key, $post_id );
            if ( $obj && is_array( $obj ) ) {
                $updated = (bool) update_field( $key, (string) $done, $post_id );
            }
        }
        if ( ! $updated ) {
            $updated = (bool) update_post_meta( $post_id, $key, (string) $done );
        }

        if ( ! $updated ) {
            wp_send_json_error( [ 'message' => 'Failed to update.' ], 500 );
        }

        wp_send_json_success( [ 'done' => (bool) $done ] );
    }

    /**
     * AJAX handler for loading expanded details
     *
     * Returns HTML content for the expandable details section with:
     * - Customer Information (name, address, email, contact)
     * - Leads (lead, category)
     * - Location (account, state, city)
     * - Comments (private comments)
     *
     * @return void
     */
    public static function ajax_expand_details() {
        // Verify nonce
        if ( ! check_ajax_referer( 'dq_workorder_expand', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ] );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        // Get and validate post ID
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intval() provides sanitization
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
        }

        // Verify post exists and is a workorder
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'workorder' ) {
            wp_send_json_error( [ 'message' => 'Invalid workorder.' ] );
        }

        // Build details HTML
        $html = self::render_expand_details_html( $post_id );

        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * Render HTML for expanded details section
     *
     * Creates a grid layout with four sections:
     * 1. Customer Information
     * 2. Leads
     * 3. Location
     * 4. Comments
     *
     * @param int $post_id Post ID
     * @return string HTML content
     */
    private static function render_expand_details_html( $post_id ) {
        ob_start();
        ?>
        <div class="dq-wo-details-grid">
            <!-- Section 1: Customer Information -->
            <div class="dq-wo-details-section">
                <h4><?php esc_html_e( 'Customer Information', 'dqqb' ); ?></h4>
                <dl>
                    <dt><?php esc_html_e( 'Name', 'dqqb' ); ?></dt>
                    <dd><?php echo esc_html( self::get_acf_or_meta( 'wo_contact_name', $post_id ) ?: '—' ); ?></dd>

                    <dt><?php esc_html_e( 'Address', 'dqqb' ); ?></dt>
                    <dd><?php echo esc_html( self::get_acf_or_meta( 'wo_contact_address', $post_id ) ?: '—' ); ?></dd>

                    <dt><?php esc_html_e( 'Email', 'dqqb' ); ?></dt>
                    <dd>
                        <?php
                        $email = self::get_acf_or_meta( 'wo_contact_email', $post_id );
                        if ( $email ) {
                            echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
                        } else {
                            echo '—';
                        }
                        ?>
                    </dd>

                    <dt><?php esc_html_e( 'Contact', 'dqqb' ); ?></dt>
                    <dd><?php echo esc_html( self::get_acf_or_meta( 'wo_service_contact_number', $post_id ) ?: '—' ); ?></dd>
                </dl>
            </div>

            <!-- Section 2: Leads -->
            <div class="dq-wo-details-section">
                <h4><?php esc_html_e( 'Leads', 'dqqb' ); ?></h4>
                <dl>
                    <dt><?php esc_html_e( 'Lead', 'dqqb' ); ?></dt>
                    <dd><?php echo esc_html( self::get_acf_or_meta( 'wo_leads', $post_id ) ?: '—' ); ?></dd>

                    <dt><?php esc_html_e( 'Category', 'dqqb' ); ?></dt>
                    <dd><?php echo esc_html( self::get_acf_or_meta( 'wo_lead_category', $post_id ) ?: '—' ); ?></dd>
                </dl>
            </div>

            <!-- Section 3: Location -->
            <div class="dq-wo-details-section">
                <h4><?php esc_html_e( 'Location', 'dqqb' ); ?></h4>
                <dl>
                    <dt><?php esc_html_e( 'Account', 'dqqb' ); ?></dt>
                    <dd><?php echo esc_html( self::get_acf_or_meta( 'wo_location', $post_id ) ?: '—' ); ?></dd>

                    <dt><?php esc_html_e( 'State', 'dqqb' ); ?></dt>
                    <dd><?php echo esc_html( self::get_acf_or_meta( 'wo_state', $post_id ) ?: '—' ); ?></dd>

                    <dt><?php esc_html_e( 'City', 'dqqb' ); ?></dt>
                    <dd><?php echo esc_html( self::get_acf_or_meta( 'wo_city', $post_id ) ?: '—' ); ?></dd>
                </dl>
            </div>

            <!-- Section 4: Comments -->
            <div class="dq-wo-details-section">
                <h4><?php esc_html_e( 'Comments', 'dqqb' ); ?></h4>
                <dl>
                    <dt><?php esc_html_e( 'Private Comments', 'dqqb' ); ?></dt>
                    <dd>
                        <?php
                        $comments = self::get_acf_or_meta( 'private_comments', $post_id );
                        if ( $comments ) {
                            // Clean up any Excel artifacts and display with preserved whitespace
                            $comments = str_replace( '_x000D_', ' ', $comments );
                            echo '<div style="white-space:pre-wrap;max-height:150px;overflow-y:auto;">' . esc_html( $comments ) . '</div>';
                        } else {
                            echo '—';
                        }
                        ?>
                    </dd>
                </dl>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Helper: Get value from ACF field or post meta
     *
     * Checks if ACF is available and uses get_field(),
     * otherwise falls back to get_post_meta().
     *
     * @param string $field_name Field name
     * @param int $post_id Post ID
     * @return mixed Field value or empty string
     */
    private static function get_acf_or_meta( $field_name, $post_id ) {
        if ( function_exists( 'get_field' ) ) {
            $value = get_field( $field_name, $post_id );
            // Handle array returns (some ACF fields return arrays)
            if ( is_array( $value ) ) {
                return '';
            }
            return (string) $value;
        }

        $value = get_post_meta( $post_id, $field_name, true );
        return is_array( $value ) ? '' : (string) $value;
    }

    /**
     * Helper: Format date for display
     *
     * Handles various date formats and returns a consistent display format.
     *
     * @param string $date_value Raw date value
     * @return string Formatted date or dash for empty/invalid
     */
    private static function format_date_display( $date_value ) {
        if ( empty( $date_value ) ) {
            return '<span style="color:#999;">—</span>';
        }

        // Clean up any Excel artifacts
        $date_value = trim( str_replace( '_x000D_', '', $date_value ) );

        // Try to parse the date
        $timestamp = strtotime( $date_value );
        if ( $timestamp !== false ) {
            return esc_html( wp_date( 'm/d/Y', $timestamp ) );
        }

        // Return as-is if parsing fails
        return esc_html( $date_value );
    }
}

// Initialize the class on init hook
add_action( 'init', [ 'DQ_Workorder_Admin_Table', 'init' ] );