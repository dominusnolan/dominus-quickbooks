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
     * Initialize the class and register hooks.
     *
     * @return void
     */
    public static function init() {
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
        $page     = $request->get_param( 'page' );
        $per_page = $request->get_param( 'per_page' );
        $status   = $request->get_param( 'status' );

        $user = wp_get_current_user();

        // Build query args - reference pattern from class-dq-dashboard.php
        $args = array(
            'post_type'      => 'workorder',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'author'         => $user->ID, // Only workorders authored by this engineer
        );

        // Add status filter if provided
        if ( ! empty( $status ) ) {
            // Try status taxonomy first (preferred)
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'status',
                    'field'    => 'slug',
                    'terms'    => $status,
                ),
            );
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
        $schedule_date = self::get_acf_or_meta( $post_id, 'schedule_date_time' );
        $closed_on     = self::get_acf_or_meta( $post_id, 'closed_on' );

        return array(
            'id'                => $post_id,
            'title'             => get_the_title( $post_id ),
            'status'            => $status,
            'date_created'      => $post->post_date,
            'date_modified'     => $post->post_modified,
            'wo_state'          => $wo_state ? $wo_state : '',
            'wo_customer_email' => $wo_customer_email ? $wo_customer_email : '',
            'schedule_date'     => $schedule_date ? $schedule_date : '',
            'closed_on'         => $closed_on ? $closed_on : '',
            'permalink'         => get_permalink( $post_id ),
        );
    }

    /**
     * Get workorder status from taxonomy or ACF field.
     *
     * @param int $post_id The post ID.
     * @return string The workorder status.
     */
    private static function get_workorder_status( $post_id ) {
        // Try taxonomy first
        $terms = get_the_terms( $post_id, 'status' );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) && is_array( $terms ) ) {
            $term = array_shift( $terms );
            return ! empty( $term->slug ) ? $term->slug : '';
        }

        // Try category taxonomy
        $cats = get_the_terms( $post_id, 'category' );
        if ( ! is_wp_error( $cats ) && ! empty( $cats ) && is_array( $cats ) ) {
            foreach ( $cats as $cat ) {
                $slug = strtolower( $cat->slug );
                if ( in_array( $slug, array( 'open', 'scheduled', 'close', 'closed' ), true ) ) {
                    return $slug;
                }
            }
        }

        // Try ACF/meta field
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
        // Get origin without using esc_url_raw() as it adds trailing slashes
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
        
        // Normalize origin by removing trailing slash to prevent CORS mismatch
        $origin = rtrim( $origin, '/' );

        // Define allowed origins (without trailing slashes)
        $allowed_origins = array(
            'https://workorder-cpt-manage--dominusnolan.github.app',
            'http://localhost:5173',
            'http://localhost:3000',
        );

        // Allow filtering of allowed origins
        $allowed_origins = apply_filters( 'dq_workorder_api_cors_origins', $allowed_origins );

        // Check if the request origin is in the allowed list
        if ( in_array( $origin, $allowed_origins, true ) ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
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
                // Get origin for preflight response
                $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
                $origin = rtrim( $origin, '/' );
                
                $allowed_origins = array(
                    'https://workorder-cpt-manage--dominusnolan.github.app',
                    'http://localhost:5173',
                    'http://localhost:3000',
                );
                
                $allowed_origins = apply_filters( 'dq_workorder_api_cors_origins', $allowed_origins );
                
                if ( in_array( $origin, $allowed_origins, true ) ) {
                    header( 'Access-Control-Allow-Origin: ' . $origin );
                    header( 'Access-Control-Allow-Credentials: true' );
                    header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
                    header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
                    header( 'Access-Control-Max-Age: 86400' );
                }
                
                $response = new WP_REST_Response();
                $response->set_status( 200 );
                return $response;
            }
            return $result;
        }, 10, 3 );
    }
}
