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

    /**
     * Enqueue front-end scripts for single workorder page and localize AJAX data.
     */
    public static function enqueue_scripts() {
        if ( ! is_singular( 'workorder' ) ) {
            return;
        }

        // Register a handle and enqueue an inline script (no external file required).
        // Use false as the src to avoid an empty-string src causing issues.
        wp_register_script( 'dqqb-workorder', false );
        wp_enqueue_script( 'dqqb-workorder' );

        // Localize AJAX url and nonce
        wp_localize_script( 'dqqb-workorder', 'dqqb_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dqqb_send_quotation' ),
        ] );

        // Inline JavaScript to handle button click and AJAX post
        $inline_js = <<<JS
(function(){
    var btn = document.getElementById('dqqb-email-quotation');
    if (!btn) return;

    btn.addEventListener('click', function(){
        var postId = this.getAttribute('data-post-id');
        var nonce = dqqb_ajax.nonce;
        var self = this;
        self.disabled = true;
        var origText = self.innerText;
        self.innerText = 'Sending...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', dqqb_ajax.ajax_url);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

        xhr.onload = function() {
            var res;
            try {
                res = JSON.parse(xhr.responseText);
            } catch (e) {
                alert('Unexpected response from server.');
                self.disabled = false;
                self.innerText = origText;
                return;
            }
            if (res && res.success) {
                self.innerText = 'Email sent';
                // keep disabled to avoid duplicate sends
            } else {
                var msg = (res && res.data) ? res.data : 'Failed to send email.';
                alert(msg);
                self.disabled = false;
                self.innerText = origText;
            }
        };

        xhr.onerror = function() {
            alert('Request failed. Please try again.');
            self.disabled = false;
            self.innerText = origText;
        };

        var body = 'action=dqqb_send_quotation&post_id=' + encodeURIComponent(postId) + '&nonce=' + encodeURIComponent(nonce);
        xhr.send(body);
    });
})();
JS;
        wp_add_inline_script( 'dqqb-workorder', $inline_js );
    }

    /**
     * AJAX handler to send a simple quotation email to the workorder contact.
     *
     * Expects POST: post_id (int), nonce
     */
    public static function ajax_send_quotation() {
        // Verify nonce (will die with -1 on failure)
        check_ajax_referer( 'dqqb_send_quotation', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( 'Invalid post ID.' );
        }

        // Retrieve contact email and name using post meta (works with or without ACF)
        $email = get_post_meta( $post_id, 'wo_contact_email', true );
        $name  = get_post_meta( $post_id, 'wo_contact_name', true );

        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( 'No valid contact email found for this workorder.' );
        }

        $subject = 'Quotation';
        $message = 'Hello';

        // Attempt to send the email
        $sent = wp_mail( $email, $subject, $message );

        if ( $sent ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( 'Failed to send email (wp_mail returned false).' );
        }
    }
}