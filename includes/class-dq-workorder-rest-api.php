<?php
/**
 * Workorder REST API
 *
 * Provides REST API endpoints for the Spark web app to fetch workorder data
 * for logged-in engineers with JWT authentication support.
 *
 * @package Dominus_QuickBooks
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DQ_Workorder_REST_API
 *
 * Handles REST API endpoints for workorder custom post type data with JWT auth.
 */
class DQ_Workorder_REST_API {

    /**
     * REST API namespace.
     *
     * @var string
     */
    const REST_NAMESPACE = 'dq-quickbooks/v1';

    /**
     * REST API route for workorders list.
     *
     * @var string
     */
    const REST_ROUTE_LIST = 'workorders';

    /**
     * REST API route for single workorder.
     *
     * @var string
     */
    const REST_ROUTE_SINGLE = 'workorders/(?P<id>\d+)';

    /**
     * REST API route for closing workorder.
     *
     * @var string
     */
    const REST_ROUTE_CLOSE = 'workorders/(?P<id>\d+)/close';

    /**
     * Time component for start of day (used in date filtering).
     *
     * @var string
     */
    const TIME_START_OF_DAY = '00:00:00';

    /**
     * Time component for end of day (used in date filtering).
     *
     * @var string
     */
    const TIME_END_OF_DAY = '23:59:59';

    /**
     * Initialize the class and register hooks.
     *
     * @return void
     */
    public static function init() {
        // Handle CORS preflight very early, before REST API init
        add_action( 'init', array( __CLASS__, 'handle_early_cors_preflight' ), 1 );
        
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'add_cors_support' ), 15 );
        add_action( 'rest_api_init', array( __CLASS__, 'handle_preflight_requests' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public static function register_rest_routes() {
        // Authentication endpoints
        register_rest_route( self::REST_NAMESPACE, '/auth/login', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_auth_login' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'username' => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'password' => array(
                    'type'     => 'string',
                    'required' => true,
                ),
            ),
        ) );

        register_rest_route( self::REST_NAMESPACE, '/auth/validate', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_auth_validate' ),
            'permission_callback' => array( __CLASS__, 'jwt_permission_callback' ),
        ) );

        register_rest_route( self::REST_NAMESPACE, '/auth/refresh', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_auth_refresh' ),
            'permission_callback' => array( __CLASS__, 'jwt_permission_callback' ),
        ) );

        // List workorders endpoint
        register_rest_route( self::REST_NAMESPACE, '/' . self::REST_ROUTE_LIST, array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_workorders' ),
            'permission_callback' => array( __CLASS__, 'jwt_permission_callback' ),
            'args'                => array(
                'page' => array(
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ),
                'per_page' => array(
                    'type'              => 'integer',
                    'default'           => 10,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ),
                'status' => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'orderby' => array(
                    'type'              => 'string',
                    'default'           => 'date',
                    'enum'              => array( 'date', 'schedule_date' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'order' => array(
                    'type'              => 'string',
                    'default'           => 'DESC',
                    'enum'              => array( 'ASC', 'DESC' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'date_from' => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => array( __CLASS__, 'validate_date_param' ),
                ),
                'date_to' => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => array( __CLASS__, 'validate_date_param' ),
                ),
                'exclude_closed' => array(
                    'type'              => 'boolean',
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
            ),
        ) );

        // Single workorder endpoint
        register_rest_route( self::REST_NAMESPACE, '/' . self::REST_ROUTE_SINGLE, array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_single_workorder' ),
            'permission_callback' => array( __CLASS__, 'jwt_permission_callback' ),
            'args'                => array(
                'id' => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param );
                    },
                ),
            ),
        ) );

        // Close workorder endpoint
        register_rest_route( self::REST_NAMESPACE, '/' . self::REST_ROUTE_CLOSE, array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_close_workorder' ),
            'permission_callback' => array( __CLASS__, 'jwt_permission_callback' ),
            'args'                => array(
                'id' => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param );
                    },
                ),
            ),
        ) );
    }

    /**
     * Permission callback for JWT-protected endpoints.
     *
     * Validates JWT token and ensures user has engineer or administrator role.
     *
     * @return bool|WP_Error True if user has permission, WP_Error otherwise.
     */
    public static function jwt_permission_callback() {
        // Try JWT authentication first
        $token = DQ_JWT_Auth::get_token_from_request();
        
        if ( $token ) {
            $user = DQ_JWT_Auth::get_user_from_token( $token );
            
            if ( is_wp_error( $user ) ) {
                return $user;
            }
            
            // Check if user has engineer or administrator role
            if ( ! in_array( 'engineer', $user->roles, true ) && ! in_array( 'administrator', $user->roles, true ) ) {
                return new WP_Error(
                    'rest_forbidden',
                    __( 'You do not have permission to access this endpoint.', 'dominus-quickbooks' ),
                    array( 'status' => 403 )
                );
            }
            
            // Set the current user for the request
            wp_set_current_user( $user->ID );
            
            return true;
        }
        
        // Fallback to standard WordPress authentication (Application Passwords)
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( in_array( 'engineer', $user->roles, true ) || in_array( 'administrator', $user->roles, true ) ) {
                return true;
            }
            
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to access this endpoint.', 'dominus-quickbooks' ),
                array( 'status' => 403 )
            );
        }
        
        return new WP_Error(
            'rest_not_logged_in',
            __( 'Authentication required. Please provide a valid JWT token or log in.', 'dominus-quickbooks' ),
            array( 'status' => 401 )
        );
    }

    /**
     * REST API callback for login endpoint.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response|WP_Error The response containing JWT token.
     */
    public static function rest_auth_login( $request ) {
        $username = $request->get_param( 'username' );
        $password = $request->get_param( 'password' );

        // Authenticate user
        $user = wp_authenticate( $username, $password );

        if ( is_wp_error( $user ) ) {
            return new WP_Error(
                'rest_authentication_failed',
                __( 'Invalid username or password.', 'dominus-quickbooks' ),
                array( 'status' => 401 )
            );
        }

        // Check if user has engineer or administrator role
        if ( ! in_array( 'engineer', $user->roles, true ) && ! in_array( 'administrator', $user->roles, true ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'Only engineers and administrators can access this API.', 'dominus-quickbooks' ),
                array( 'status' => 403 )
            );
        }

        // Generate JWT token
        $token = DQ_JWT_Auth::generate_token( $user->ID );

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // Get token expiration
        $expiration = time() + DQ_JWT_Auth::get_token_expiration();

        // Get user role
        $role = 'engineer';
        if ( in_array( 'administrator', $user->roles, true ) ) {
            $role = 'administrator';
        }

        $response = array(
            'success'    => true,
            'token'      => $token,
            'user'       => array(
                'id'           => $user->ID,
                'username'     => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'role'         => $role,
            ),
            'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expiration ),
        );

        return rest_ensure_response( $response );
    }

    /**
     * REST API callback for validate endpoint.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response The response containing validation status.
     */
    public static function rest_auth_validate( $request ) {
        $user = wp_get_current_user();

        $response = array(
            'valid'  => true,
            'user'   => array(
                'id'           => $user->ID,
                'username'     => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
            ),
        );

        return rest_ensure_response( $response );
    }

    /**
     * REST API callback for refresh endpoint.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response|WP_Error The response containing new JWT token.
     */
    public static function rest_auth_refresh( $request ) {
        $user = wp_get_current_user();

        if ( ! $user || ! $user->ID ) {
            return new WP_Error(
                'rest_authentication_failed',
                __( 'User not found.', 'dominus-quickbooks' ),
                array( 'status' => 401 )
            );
        }

        // Generate new JWT token
        $token = DQ_JWT_Auth::generate_token( $user->ID );

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // Get token expiration
        $expiration = time() + DQ_JWT_Auth::get_token_expiration();

        $response = array(
            'success'    => true,
            'token'      => $token,
            'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expiration ),
        );

        return rest_ensure_response( $response );
    }

    /**
     * REST API callback to get list of workorders for authenticated engineer.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response|WP_Error The response containing workorder data.
     */
    public static function rest_get_workorders( $request ) {
        $page           = $request->get_param( 'page' );
        $per_page       = $request->get_param( 'per_page' );
        $status         = $request->get_param( 'status' );
        $orderby        = $request->get_param( 'orderby' );
        $order          = $request->get_param( 'order' );
        $date_from      = $request->get_param( 'date_from' );
        $date_to        = $request->get_param( 'date_to' );
        $exclude_closed = $request->get_param( 'exclude_closed' );

        $user = wp_get_current_user();

        // Build query args - reference pattern from class-dq-dashboard.php
        $args = array(
            'post_type'      => 'workorder',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'author'         => $user->ID, // Only workorders authored by this engineer
        );

        // Handle orderby parameter
        if ( 'schedule_date' === $orderby ) {
            // Order by schedule_date_time meta field (datetime field)
            $args['meta_key'] = 'schedule_date_time';
            $args['orderby']  = 'meta_value_datetime';
            $args['order']    = strtoupper( $order );
        } else {
            // Default: order by post date
            $args['orderby'] = 'date';
            $args['order']   = strtoupper( $order );
        }

        // Add status filter if provided
        if ( ! empty( $status ) ) {
            // Use category taxonomy for workorder status
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'category',
                    'field'    => 'slug',
                    'terms'    => $status,
                ),
            );
        }

        // Exclude closed workorders if requested (and no specific status filter)
        if ( $exclude_closed && empty( $status ) ) {
            if ( ! isset( $args['tax_query'] ) ) {
                $args['tax_query'] = array();
            }

            // Add exclusion for 'closed' category
            $args['tax_query'][] = array(
                'taxonomy' => 'category',
                'field'    => 'slug',
                'terms'    => array( 'closed', 'close' ),
                'operator' => 'NOT IN',
            );
        }

        // Add date filtering if provided
        if ( ! empty( $date_from ) || ! empty( $date_to ) ) {
            // Initialize meta_query if it doesn't exist, or preserve existing queries
            if ( ! isset( $args['meta_query'] ) ) {
                $args['meta_query'] = array();
            }

            // Set relation to AND (or preserve existing relation)
            if ( ! isset( $args['meta_query']['relation'] ) ) {
                $args['meta_query']['relation'] = 'AND';
            }

            if ( ! empty( $date_from ) ) {
                // Filter for dates >= date_from (start of day)
                $args['meta_query'][] = array(
                    'key'     => 'schedule_date_time',
                    'value'   => $date_from . ' ' . self::TIME_START_OF_DAY,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                );
            }

            if ( ! empty( $date_to ) ) {
                // Filter for dates <= date_to (end of day)
                $args['meta_query'][] = array(
                    'key'     => 'schedule_date_time',
                    'value'   => $date_to . ' ' . self::TIME_END_OF_DAY,
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                );
            }
        }

        $query = new WP_Query( $args );

        $workorders = array();
        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post ) {
                $workorders[] = self::format_workorder_data( $post );
            }
        }

        $response = array(
            'workorders'   => $workorders,
            'total'        => $query->found_posts,
            'total_pages'  => $query->max_num_pages,
            'current_page' => $page,
        );

        return rest_ensure_response( $response );
    }

    /**
     * REST API callback to get single workorder details.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response|WP_Error The response containing workorder data.
     */
    public static function rest_get_single_workorder( $request ) {
        $post_id = $request->get_param( 'id' );
        $user    = wp_get_current_user();

        // Validate post exists
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error(
                'rest_post_not_found',
                __( 'Workorder not found.', 'dominus-quickbooks' ),
                array( 'status' => 404 )
            );
        }

        // Validate post is a workorder
        if ( 'workorder' !== $post->post_type ) {
            return new WP_Error(
                'rest_invalid_post_type',
                __( 'Invalid post type. Expected workorder.', 'dominus-quickbooks' ),
                array( 'status' => 400 )
            );
        }

        // Verify the logged-in engineer is the author of the workorder
        if ( (int) $post->post_author !== (int) $user->ID ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to access this workorder.', 'dominus-quickbooks' ),
                array( 'status' => 403 )
            );
        }

        $workorder_data = self::format_workorder_data( $post );

        return rest_ensure_response( $workorder_data );
    }

    /**
     * REST API callback to close a workorder.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response|WP_Error The response containing updated workorder data.
     */
    public static function rest_close_workorder( $request ) {
        $post_id = $request->get_param( 'id' );
        $user    = wp_get_current_user();

        // Validate post exists
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error(
                'rest_post_not_found',
                __( 'Workorder not found.', 'dominus-quickbooks' ),
                array( 'status' => 404 )
            );
        }

        // Validate post is a workorder
        if ( 'workorder' !== $post->post_type ) {
            return new WP_Error(
                'rest_invalid_post_type',
                __( 'Invalid post type. Expected workorder.', 'dominus-quickbooks' ),
                array( 'status' => 400 )
            );
        }

        // Verify the logged-in engineer is the author of the workorder
        if ( (int) $post->post_author !== (int) $user->ID ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to close this workorder.', 'dominus-quickbooks' ),
                array( 'status' => 403 )
            );
        }

        // Update category taxonomy term to "closed" (workorder status)
        $result = wp_set_object_terms( $post_id, 'closed', 'category' );
        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                'rest_status_update_failed',
                __( 'Failed to update workorder status.', 'dominus-quickbooks' ),
                array( 'status' => 500 )
            );
        }

        // Update ACF field date_service_completed_by_fse
        $completed_date = current_time( 'Y-m-d H:i:s' );
        if ( function_exists( 'update_field' ) ) {
            update_field( 'date_service_completed_by_fse', $completed_date, $post_id );
        } else {
            update_post_meta( $post_id, 'date_service_completed_by_fse', $completed_date );
        }

        // Return updated workorder data
        $workorder_data = self::format_workorder_data( $post );

        return rest_ensure_response( $workorder_data );
    }

    /**
     * Format workorder data for API response.
     *
     * @param WP_Post $post The workorder post object.
     * @return array Formatted workorder data.
     */
    private static function format_workorder_data( $post ) {
        $post_id = $post->ID;

        // Get status from taxonomy (category)
        $status = self::get_workorder_status( $post_id );

        // Get post meta fields
        $wo_state          = get_post_meta( $post_id, 'wo_state', true );
        $wo_customer_email = get_post_meta( $post_id, 'wo_customer_email', true );

        // Get ACF fields
        // Fetch the raw value
        $rawScheduleDate = self::get_acf_or_meta( $post_id, 'schedule_date_time' );
        $schedule_date = '';
        if ($rawScheduleDate) {
            // Try ACF's Date Picker Display format
            $dt = DateTime::createFromFormat('m/d/Y', $rawScheduleDate);
            if ($dt !== false) {
                $schedule_date = $dt->format('Y-m-d 00:00:00');
            } else {
                $schedule_date = $rawScheduleDate; // fallback
            }
        }
        $re_schedule   = self::get_acf_or_meta( $post_id, 're-schedule' );
        $closed_on     = self::get_acf_or_meta( $post_id, 'closed_on' );
        $date_service_completed_by_fse = self::get_acf_or_meta( $post_id, 'date_service_completed_by_fse' );
        $wo_location   = self::get_acf_or_meta( $post_id, 'wo_location' );

        return array(
            'id'                              => $post_id,
            'title'                           => get_the_title( $post_id ),
            'status'                          => $status,
            'date_created'                    => $post->post_date,
            'date_modified'                   => $post->post_modified,
            'wo_state'                        => $wo_state ? $wo_state : '',
            'wo_customer_email'               => $wo_customer_email ? $wo_customer_email : '',
            'wo_location'                     => $wo_location ? $wo_location : '',
            'schedule_date'                   => $schedule_date ? $schedule_date : '',
            're_schedule'                     => $re_schedule ? $re_schedule : '',
            'closed_on'                       => $closed_on ? $closed_on : '',
            'date_service_completed_by_fse'   => $date_service_completed_by_fse ? $date_service_completed_by_fse : '',
            'permalink'                       => get_permalink( $post_id ),
        );
    }

    /**
     * Get workorder status from taxonomy or ACF field.
     *
     * @param int $post_id The post ID.
     * @return string The workorder status.
     */
    private static function get_workorder_status( $post_id ) {
        // Try category taxonomy first (primary source)
        $cats = get_the_terms( $post_id, 'category' );
        if ( ! is_wp_error( $cats ) && ! empty( $cats ) && is_array( $cats ) ) {
            foreach ( $cats as $cat ) {
                $slug = strtolower( $cat->slug );
                if ( in_array( $slug, array( 'open', 'scheduled', 'close', 'closed' ), true ) ) {
                    return $slug;
                }
            }
        }

        // Try status taxonomy (fallback)
        $terms = get_the_terms( $post_id, 'status' );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) && is_array( $terms ) ) {
            $term = array_shift( $terms );
            return ! empty( $term->slug ) ? $term->slug : '';
        }

        // Try ACF/meta field (final fallback)
        $wo_status = self::get_acf_or_meta( $post_id, 'wo_status' );
        if ( $wo_status ) {
            return strtolower( trim( $wo_status ) );
        }

        return ''; // Default empty
    }

    /**
     * Helper to get ACF field or fall back to post meta.
     *
     * @param int    $post_id    The post ID.
     * @param string $field_name The field name.
     * @return mixed The field value.
     */
    private static function get_acf_or_meta( $post_id, $field_name ) {
        if ( function_exists( 'get_field' ) ) {
            return get_field( $field_name, $post_id );
        }
        return get_post_meta( $post_id, $field_name, true );
    }

    /**
     * Validate date parameter in YYYY-MM-DD format.
     *
     * @param string $date_string The date string to validate.
     * @return bool True if valid date in YYYY-MM-DD format, false otherwise.
     */
    public static function validate_date_param( $date_string ) {
        if ( empty( $date_string ) ) {
            return true;
        }

        // Validate YYYY-MM-DD format and check if it's a valid date
        $date = DateTime::createFromFormat( 'Y-m-d', $date_string );
        return $date && $date->format( 'Y-m-d' ) === $date_string;
    }

    /**
     * Get allowed CORS origins.
     *
     * @return array List of allowed origins.
     */
    private static function get_allowed_origins() {
        // Define allowed origins (without trailing slashes)
        $allowed_origins = array(
            'https://workorder-cpt-manage--dominusnolan.github.app',
            'https://workorder-cpt-manage.vercel.app',  // ← Add this line
            'http://localhost:5173',
            'http://localhost:3000',
        );

        // Allow filtering of allowed origins
        return apply_filters( 'dq_workorder_api_cors_origins', $allowed_origins );
    }

    /**
     * Get sanitized origin from request.
     *
     * @return string The sanitized origin without trailing slash.
     */
    private static function get_request_origin() {
        if ( ! isset( $_SERVER['HTTP_ORIGIN'] ) ) {
            return '';
        }

        // Get origin without using esc_url_raw() as it adds trailing slashes
        $origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
        
        // Validate that the scheme is http or https first (prevent javascript:, data:, file:, etc.)
        $parsed_url = wp_parse_url( $origin );
        if ( ! isset( $parsed_url['scheme'] ) || ! in_array( $parsed_url['scheme'], array( 'http', 'https' ), true ) ) {
            return '';
        }
        
        // Validate URL structure
        if ( ! filter_var( $origin, FILTER_VALIDATE_URL ) ) {
            return '';
        }
        
        // Normalize origin by removing trailing slash to prevent CORS mismatch
        return rtrim( $origin, '/' );
    }

    /**
     * Handle CORS preflight requests very early in the WordPress lifecycle.
     * This catches OPTIONS requests before they reach the REST API.
     *
     * @return void
     */
    public static function handle_early_cors_preflight() {
        // Only handle OPTIONS requests
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'OPTIONS' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }
        
        // Check if this is a request to our API namespace
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( strpos( $request_uri, '/wp-json/dq-quickbooks/' ) === false ) {
            return;
        }
        
        // Get and validate origin
        $origin = self::get_request_origin();
        $allowed_origins = self::get_allowed_origins();
        
        // If origin is allowed, send CORS headers and exit
        if ( in_array( $origin, $allowed_origins, true ) ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With' );
            header( 'Access-Control-Max-Age: 86400' ); // Cache preflight for 24 hours
            header( 'Content-Length: 0' );
            header( 'Content-Type: text/plain' );
            status_header( 200 );
            exit;
        }
    }

    /**
     * Check if origin is allowed and send CORS headers if it is.
     *
     * @param bool $include_max_age Whether to include Access-Control-Max-Age header.
     * @return bool True if origin is allowed and headers were sent, false otherwise.
     */
    private static function maybe_send_cors_headers( $include_max_age = false ) {
        $origin = self::get_request_origin();
        $allowed_origins = self::get_allowed_origins();

        // Check if the request origin is in the allowed list
        if ( ! in_array( $origin, $allowed_origins, true ) ) {
            return false;
        }

        header( 'Access-Control-Allow-Origin: ' . $origin );
        header( 'Access-Control-Allow-Credentials: true' );
        header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
        
        if ( $include_max_age ) {
            header( 'Access-Control-Max-Age: ' . self::CORS_MAX_AGE );
        }

        return true;
    }

    /**
     * Add CORS support for the Spark app and local development.
     *
     * @return void
     */
    public static function add_cors_support() {
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers' ) );
    }

    /**
     * Add CORS headers to allow requests from allowed origins.
     *
     * @param bool $served Whether the request has already been served.
     * @return bool
     */
    public static function add_cors_headers( $served ) {
        $origin = self::get_request_origin();
        $allowed_origins = self::get_allowed_origins();

        // Only add CORS headers if the request is from an allowed origin
        if ( in_array( $origin, $allowed_origins, true ) ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With' );
        }

        return $served;
    }

    /**
     * Handle preflight OPTIONS requests.
     *
     * @return void
     */
    public static function handle_preflight_requests() {
        add_filter( 'rest_pre_dispatch', function( $result, $server, $request ) {
            if ( 'OPTIONS' === $request->get_method() ) {
                self::maybe_send_cors_headers( true );
                $response = new WP_REST_Response();
                $response->set_status( 200 );
                return $response;
            }
            return $result;
        }, 10, 3 );
    }
}
