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

    /**
     * Check if the current user can edit a workorder post
     *
     * Checks multiple possible edit capabilities for workorder CPT to ensure
     * all users with valid edit permission are supported.
     *
     * @param int $post_id The workorder post ID
     * @return bool True if user can edit, false otherwise
     */
    private static function can_edit_workorder( $post_id ) {
        return current_user_can( 'edit_post', $post_id )
            || current_user_can( 'edit_workorder', $post_id )
            || current_user_can( 'edit_workorders' );
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
        if ( ! $post ) {
            return;
        }

        // Check if user can edit this workorder
        if ( ! self::can_edit_workorder( $post->ID ) ) {
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
     * Get array of US states with full names as both keys and values
     *
     * @return array
     */
    public static function get_us_states() {
        return [
            'Alabama' => 'Alabama',
            'Alaska' => 'Alaska',
            'Arizona' => 'Arizona',
            'Arkansas' => 'Arkansas',
            'California' => 'California',
            'Colorado' => 'Colorado',
            'Connecticut' => 'Connecticut',
            'Delaware' => 'Delaware',
            'District of Columbia' => 'District of Columbia',
            'Florida' => 'Florida',
            'Georgia' => 'Georgia',
            'Hawaii' => 'Hawaii',
            'Idaho' => 'Idaho',
            'Illinois' => 'Illinois',
            'Indiana' => 'Indiana',
            'Iowa' => 'Iowa',
            'Kansas' => 'Kansas',
            'Kentucky' => 'Kentucky',
            'Louisiana' => 'Louisiana',
            'Maine' => 'Maine',
            'Maryland' => 'Maryland',
            'Massachusetts' => 'Massachusetts',
            'Michigan' => 'Michigan',
            'Minnesota' => 'Minnesota',
            'Mississippi' => 'Mississippi',
            'Missouri' => 'Missouri',
            'Montana' => 'Montana',
            'Nebraska' => 'Nebraska',
            'Nevada' => 'Nevada',
            'New Hampshire' => 'New Hampshire',
            'New Jersey' => 'New Jersey',
            'New Mexico' => 'New Mexico',
            'New York' => 'New York',
            'North Carolina' => 'North Carolina',
            'North Dakota' => 'North Dakota',
            'Ohio' => 'Ohio',
            'Oklahoma' => 'Oklahoma',
            'Oregon' => 'Oregon',
            'Pennsylvania' => 'Pennsylvania',
            'Rhode Island' => 'Rhode Island',
            'South Carolina' => 'South Carolina',
            'South Dakota' => 'South Dakota',
            'Tennessee' => 'Tennessee',
            'Texas' => 'Texas',
            'Utah' => 'Utah',
            'Vermont' => 'Vermont',
            'Virginia' => 'Virginia',
            'Washington' => 'Washington',
            'West Virginia' => 'West Virginia',
            'Wisconsin' => 'Wisconsin',
            'Wyoming' => 'Wyoming',
        ];
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

        // Check if user can edit this workorder
        if ( ! self::can_edit_workorder( $post_id ) ) {
            wp_send_json_error( 'You do not have permission to edit this post.' );
        }

        // Get raw field value for debug logging
        $raw_field = isset( $_POST['field'] ) ? wp_unslash( $_POST['field'] ) : '';

        // Trim whitespace and sanitize field name (sanitize_key lowercases and removes non-alphanumeric chars except dashes/underscores)
        $field = sanitize_key( trim( $raw_field ) );

        // Debug logging for troubleshooting field matching issues
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf(
                '[DQQB Inline Edit] Raw field: "%s", Sanitized field: "%s", Post ID: %d',
                $raw_field,
                $field,
                $post_id
            ) );
        }

        // For private_comments, allow HTML; others strip tags
        $raw_value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
        $value = ( $field === 'private_comments' ) ? $raw_value : wp_strip_all_tags( $raw_value );

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
            'wo_leads',
            'wo_lead_category',
            'private_comments',
        ];

        if ( ! in_array( $field, $allowed_fields, true ) ) {
            // Provide detailed error message including raw and sanitized field values for debugging
            wp_send_json_error( sprintf(
                'Field not allowed. Raw field: "%s", Sanitized field: "%s". Allowed fields: %s',
                esc_html( $raw_field ),
                esc_html( $field ),
                implode( ', ', $allowed_fields )
            ) );
        }

        // Fields that use ACF choices (selects)
        $choice_fields = [ 'wo_leads', 'wo_lead_category' ];
        $label = null;

        // Sanitize value based on field type
        if ( $field === 'wo_contact_email' ) {
            // Email-specific sanitization and validation
            $sanitized_email = sanitize_email( $value );
            if ( $value !== '' && ! is_email( $sanitized_email ) ) {
                // Invalid email: reject and do not save
                wp_send_json_error( 'Invalid email address.' );
            }
            $value = $sanitized_email;
        } elseif ( $field === 'wo_state' ) {
            // State: validate against US states list, store uppercase code, return full name as label
            $value = strtoupper( sanitize_text_field( $value ) );
            $us_states = self::get_us_states();
            // Allow empty value to clear the field
            if ( $value !== '' && ! array_key_exists( $value, $us_states ) ) {
                wp_send_json_error( 'Invalid US state code.' );
            }
            // Set label to full state name
            if ( $value !== '' && isset( $us_states[ $value ] ) ) {
                $label = $us_states[ $value ];
            }
        } elseif ( in_array( $field, $choice_fields, true ) ) {
            // For choice fields, validate against ACF choices if available
            $value = sanitize_text_field( $value );
            if ( function_exists( 'get_field_object' ) ) {
                $field_object = get_field_object( $field, $post_id );
                if ( $field_object && ! empty( $field_object['choices'] ) ) {
                    $choices = $field_object['choices'];
                    // Allow empty value to clear the field
                    if ( $value !== '' && ! array_key_exists( $value, $choices ) ) {
                        wp_send_json_error( 'Invalid choice for this field.' );
                    }
                    // Get the human-readable label for the choice
                    if ( $value !== '' && isset( $choices[ $value ] ) ) {
                        $label = $choices[ $value ];
                    }
                }
            }
        } elseif ( $field === 'private_comments' ) {
            // Allow safe HTML formatting via wp_kses_post
            $value = wp_kses_post( $value );
            // Return sanitized HTML as label for display
            $label = $value;
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

        $response = [
            'field' => $field,
            'value' => $value,
        ];
        if ( $label !== null ) {
            $response['label'] = $label;
        }

        wp_send_json_success( $response );
    }
}