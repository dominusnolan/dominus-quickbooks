<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_Workorder_Template
 * 
 * Provides template fallback for single Workorder posts.
 * If the theme doesn't have single-workorder.php, we use our plugin template.
 */
class DQ_Workorder_Template {

    /**
     * Initialize hooks
     */
    public static function init() {
        add_filter( 'single_template', [ __CLASS__, 'single_template' ], 10, 1 );

        // ADDED: Handle non-AJAX submission from workorder form
        add_action('admin_post_dqqb_send_quotation_nonajax', [__CLASS__, 'handle_post_quotation']);
        add_action('admin_post_nopriv_dqqb_send_quotation_nonajax', [__CLASS__, 'handle_post_quotation']);

        // Force plugin template for single workorder pages (front-end only)
        add_filter( 'template_include', [ __CLASS__, 'force_plugin_template' ], 5 );

        // Enqueue inline edit assets on front-end single workorder pages
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_assets' ] );

        // AJAX handler for inline field updates (logged-in users only)
        add_action( 'wp_ajax_dqqb_inline_update', [ __CLASS__, 'handle_inline_update' ] );
    }

    /**
     * Filter single_template to provide fallback
     * 
     * Priority:
     * 1. Theme's single-workorder.php
     * 2. Theme's dqqb/single-workorder.php (optional override path)
     * 3. Plugin's templates/single-workorder.php
     * 
     * @param string $template Current template path
     * @return string Template path to use
     */
    public static function single_template( $template ) {
        global $post;

        // Only for workorder CPT
        if ( ! $post || $post->post_type !== 'workorder' ) {
            return $template;
        }

        // If theme already has a template, use it
        if ( $template && file_exists( $template ) ) {
            return $template;
        }

        // Check for theme override at dqqb/single-workorder.php
        $theme_override = locate_template( [ 'dqqb/single-workorder.php' ] );
        if ( $theme_override ) {
            return $theme_override;
        }

        // Use plugin template as fallback
        $plugin_template = ( defined( 'DQQB_PATH' ) ? DQQB_PATH : plugin_dir_path( dirname( __FILE__ ) . '/../' ) ) . 'templates/single-workorder.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }

        // Return original if nothing found
        return $template;
    }

    /**
     * Force the plugin's single-workorder.php template to be used on the front end
     * when viewing a single workorder. This bypasses theme template selection so the
     * plugin markup is always used.
     *
     * @param string $template The template chosen by WP
     * @return string The plugin template path or original template
     */
    public static function force_plugin_template( $template ) {
        // Only on front-end main query
        if ( is_admin() ) {
            return $template;
        }

        if ( ! function_exists( 'is_singular' ) ) {
            return $template;
        }

        if ( is_singular( 'workorder' ) ) {
            // Prefer defined constant; fall back to plugin_dir_path based lookup
            if ( defined( 'DQQB_PATH' ) ) {
                $plugin_template = DQQB_PATH . 'templates/single-workorder.php';
            } else {
                // dirname(__FILE__) is includes/ — go up one level to plugin root
                $plugin_root = plugin_dir_path( dirname( __FILE__ ) );
                $plugin_template = trailingslashit( $plugin_root ) . 'templates/single-workorder.php';
            }

            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        return $template;
    }



     public static function handle_post_quotation() {
        // Validate nonce and post_id
        if (
            !isset($_POST['dqqb_send_quotation_nonce'])
            || !check_admin_referer('dqqb_send_quotation', 'dqqb_send_quotation_nonce', false)
        ) {
            $referer = wp_get_referer() ?: home_url();
            wp_safe_redirect(add_query_arg('dqqb_quote_error', rawurlencode('Security check failed.'), $referer));
            exit;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $referer = wp_get_referer() ?: get_permalink($post_id);
        if ($post_id <= 0) {
            wp_safe_redirect(add_query_arg('dqqb_quote_error', rawurlencode('Invalid post ID.'), $referer));
            exit;
        }

        // Use ACF to fetch required fields
        $email = '';
        $contact_name = '';
        $attachment_id = '';
        if ( function_exists('get_field') ) {
            $email = get_field('wo_contact_email', $post_id);
            $contact_name = get_field('wo_contact_name', $post_id);
            $attachment_id = get_field('email_quotation', $post_id);
        } else {
            $email = get_post_meta($post_id, 'wo_contact_email', true);
            $contact_name = get_post_meta($post_id, 'wo_contact_name', true);
            $attachment_id = get_post_meta($post_id, 'email_quotation', true);
        }
        $email = sanitize_email($email);

        // Validate email
        if (empty($email) || !is_email($email)) {
            wp_safe_redirect(add_query_arg('dqqb_quote_error', rawurlencode('No valid contact email found for this workorder.'), $referer));
            exit;
        }

        // Prepare subject and body
        $subject = 'Milay Mechanical Quotation for ' . ($contact_name ? $contact_name : '');
        $body = 'Hello There';

        // Prepare attachment (ACF field "email_quotation" can be either attachment ID or array)
        $attachment_path = '';
        if ($attachment_id) {
            if (is_numeric($attachment_id)) {
                $attachment_path = get_attached_file($attachment_id);
            } elseif (is_array($attachment_id) && !empty($attachment_id['ID'])) {
                $attachment_path = get_attached_file($attachment_id['ID']);
            } elseif (is_string($attachment_id) && filter_var($attachment_id, FILTER_VALIDATE_URL)) {
                // Download file temporarily
                $tmp_file = download_url($attachment_id);
                if (!is_wp_error($tmp_file)) {
                    $attachment_path = $tmp_file;
                }
            }
        }
        $attachments = [];
        if ($attachment_path && file_exists($attachment_path)) {
            $attachments[] = $attachment_path;
        }

        $from_email_filter = function($old) { return 'admin@milaymechanical.com'; };
        $from_name_filter = function($old) { return 'Milay Mechanical'; };
        add_filter('wp_mail_from', $from_email_filter);
        add_filter('wp_mail_from_name', $from_name_filter);
       $sent = wp_mail($email, $subject, $body, [], $attachments);
        remove_filter('wp_mail_from', $from_email_filter);
        remove_filter('wp_mail_from_name', $from_name_filter);
        // Clean up temporary file if needed
        if (!empty($tmp_file) && file_exists($tmp_file)) {
            @unlink($tmp_file);
        }

        if ($sent) {
            wp_safe_redirect(add_query_arg('dqqb_quote_sent', '1', $referer));
            exit;
        } else {
            wp_safe_redirect(add_query_arg('dqqb_quote_error', rawurlencode('Failed to send email.'), $referer));
            exit;
        }
    }

    /**
     * Enqueue inline edit assets on front-end single workorder pages
     */
    public static function maybe_enqueue_assets() {
        // Only on front-end single workorder pages
        if ( is_admin() || ! is_singular( 'workorder' ) ) {
            return;
        }

        // Only enqueue if user is logged in and can edit the post
        if ( ! is_user_logged_in() ) {
            return;
        }

        global $post;
        if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $js_path = defined( 'DQQB_PATH' ) ? DQQB_PATH : plugin_dir_path( dirname( __FILE__ ) );
        $js_url  = defined( 'DQQB_URL' )  ? DQQB_URL  : plugin_dir_url( dirname( __FILE__ ) );

        $js_file = $js_path . 'assets/js/inline-edit-workorder.js';
        $js_file_url = $js_url . 'assets/js/inline-edit-workorder.js';

        if ( file_exists( $js_file ) ) {
            wp_enqueue_script(
                'dqqb-inline-edit-workorder',
                $js_file_url,
                [],
                filemtime( $js_file ),
                true
            );

            wp_localize_script(
                'dqqb-inline-edit-workorder',
                'DQQBInlineEdit',
                [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'dqqb-inline-edit' ),
                ]
            );
        }
    }

    /**
     * AJAX handler for inline field updates
     * Validates nonce and permissions, then updates field via ACF or post_meta
     */
    public static function handle_inline_update() {
        // Verify nonce
        if ( ! check_ajax_referer( 'dqqb-inline-edit', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid security token.' );
        }

        // Ensure user is logged in
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'You must be logged in.' );
        }

        // Get and validate post_id
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( 'Invalid post ID.' );
        }

        // Verify the post exists and is a workorder
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'workorder' ) {
            wp_send_json_error( 'Invalid workorder.' );
        }

        // Check user capability
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'You do not have permission to edit this post.' );
        }

        // Get field and value
        $field = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '';
        $value = isset( $_POST['value'] ) ? wp_strip_all_tags( wp_unslash( $_POST['value'] ) ) : '';

        // Whitelist of allowed editable fields
        $allowed_fields = [
            'installed_product_id',
            'wo_type_of_work',
            'wo_state',
            'wo_city',
            'wo_location',
            'wo_contact_name',
            'wo_contact_address',
            'wo_contact_email',
            'wo_service_contact_number',
        ];

        if ( ! in_array( $field, $allowed_fields, true ) ) {
            wp_send_json_error( 'Field not allowed.' );
        }

        // Sanitize value based on field type
        $warning = '';
        if ( $field === 'wo_contact_email' ) {
            // Email-specific sanitization and validation
            $sanitized_email = sanitize_email( $value );
            if ( $value !== '' && ! is_email( $sanitized_email ) ) {
                // Invalid email: save as sanitized text but return warning
                $value = sanitize_text_field( $value );
                $warning = 'Value saved, but it is not a valid email address.';
            } else {
                $value = $sanitized_email;
            }
        } else {
            // General text field sanitization
            $value = sanitize_text_field( $value );
        }

        // Update via ACF if available, otherwise use post_meta
        if ( function_exists( 'update_field' ) ) {
            update_field( $field, $value, $post_id );
        } else {
            update_post_meta( $post_id, $field, $value );
        }

        $response = [ 'value' => $value ];
        if ( $warning !== '' ) {
            $response['warning'] = $warning;
        }

        wp_send_json_success( $response );
    }
}