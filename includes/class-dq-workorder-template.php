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

        // Enqueue scripts for single workorder view and add AJAX handlers
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_dqqb_send_quotation', [ __CLASS__, 'ajax_send_quotation' ] );
        add_action( 'wp_ajax_nopriv_dqqb_send_quotation', [ __CLASS__, 'ajax_send_quotation' ] );

        // Force plugin template for single workorder pages (front-end only)
        add_filter( 'template_include', [ __CLASS__, 'force_plugin_template' ], 5 );
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

        // Find recipient
        $email_keys = ['wo_contact_email', 'wo_customer_email', 'wo_email', 'customer_email'];
        $email = '';
        foreach ($email_keys as $key) {
            $val = get_post_meta($post_id, $key, true);
            if (!empty($val)) { $email = (string)$val; break; }
        }
        $email = sanitize_email($email);
        if (empty($email) || !is_email($email)) {
            wp_safe_redirect(add_query_arg('dqqb_quote_error', rawurlencode('No valid contact email found for this workorder.'), $referer));
            exit;
        }

        $subject = 'Quotation';
        $body = 'Hello';

        $sent = wp_mail($email, $subject, $body);

        if ($sent) {
            wp_safe_redirect(add_query_arg('dqqb_quote_sent', '1', $referer));
            exit;
        } else {
            wp_safe_redirect(add_query_arg('dqqb_quote_error', rawurlencode('Failed to send email.'), $referer));
            exit;
        }
    }
}