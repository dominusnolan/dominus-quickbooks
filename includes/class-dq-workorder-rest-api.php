<?php
/**
 * Workorder REST API
 *
 * Provides REST API endpoints for the Spark web app to fetch workorder data
 * for logged-in engineers.
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
 * Handles REST API endpoints for workorder custom post type data.
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
        add_action( 'rest_api_init', array( __CLASS__, 'add_cors_support' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public static function register_rest_routes() {
        // List workorders endpoint
        register_rest_route( self::REST_NAMESPACE, '/' . self::REST_ROUTE_LIST, array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_workorders' ),
            'permission_callback' => array( __CLASS__, 'permission_callback' ),
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
            'permission_callback' => array( __CLASS__, 'permission_callback' ),
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
     * Permission callback to check if user is logged in and has engineer role.
     *
     * @return bool|WP_Error True if user has permission, WP_Error otherwise.
     */
    public static function permission_callback() {
        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'rest_not_logged_in',
                __( 'You must be logged in to access this endpoint.', 'dominus-quickbooks' ),
                array( 'status' => 401 )
            );
        }

        // Check if user has engineer role
        $user = wp_get_current_user();
        if ( ! in_array( 'engineer', $user->roles, true ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to access this endpoint.', 'dominus-quickbooks' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * REST API callback to get list of workorders for logged-in engineer.
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
     * Add CORS support for the Spark app domain.
     *
     * @return void
     */
    public static function add_cors_support() {
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers' ) );
    }

    /**
     * Add CORS headers to allow requests from the Spark app domain.
     *
     * @param bool $served Whether the request has already been served.
     * @return bool
     */
    public static function add_cors_headers( $served ) {
        // Allow filtering the allowed origin for different environments
        $allowed_origin = apply_filters( 'dq_workorder_api_cors_origin', 'https://workorder-cpt-manage--dominusnolan.github.app' );
        $origin         = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( $_SERVER['HTTP_ORIGIN'] ) : '';

        // Only add CORS headers if the request is from the allowed origin
        if ( $origin === $allowed_origin ) {
            header( 'Access-Control-Allow-Origin: ' . $allowed_origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
        }

        // Handle preflight requests
        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( $_SERVER['REQUEST_METHOD'] ) : '';
        if ( 'OPTIONS' === $request_method ) {
            status_header( 200 );
            exit;
        }

        return $served;
    }
}
