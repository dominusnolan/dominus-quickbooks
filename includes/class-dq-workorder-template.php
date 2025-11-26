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
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_dqqb_send_quotation', [ __CLASS__, 'ajax_send_quotation' ] );
        add_action( 'wp_ajax_nopriv_dqqb_send_quotation', [ __CLASS__, 'ajax_send_quotation' ] );
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
        $plugin_template = DQQB_PATH . 'templates/single-workorder.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }

        // Return original if nothing found
        return $template;
    }

    /**
     * Enqueue scripts for single workorder view
     */
    public static function enqueue_scripts() {
        if ( ! is_singular( 'workorder' ) ) {
            return;
        }

        // Register a minimal inline script
        wp_register_script( 'dqqb-quotation', '', [], false, true );
        wp_enqueue_script( 'dqqb-quotation' );

        // Localize script data
        wp_localize_script( 'dqqb-quotation', 'dqqb_quotation', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dqqb_send_quotation' ),
        ] );

        // Add inline JS
        $inline_js = "
            document.addEventListener('DOMContentLoaded', function() {
                var btn = document.getElementById('dqqb-email-quotation-btn');
                if (!btn) return;
                
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var postId = btn.getAttribute('data-post-id');
                    var originalText = btn.textContent;
                    
                    btn.disabled = true;
                    btn.textContent = 'Sending...';
                    
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', dqqb_quotation.ajax_url, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4) {
                            var response;
                            try {
                                response = JSON.parse(xhr.responseText);
                            } catch (err) {
                                alert('Error: Invalid response from server');
                                btn.disabled = false;
                                btn.textContent = originalText;
                                return;
                            }
                            
                            if (response.success) {
                                btn.textContent = 'Email sent';
                            } else {
                                alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                                btn.disabled = false;
                                btn.textContent = originalText;
                            }
                        }
                    };
                    
                    var params = 'action=dqqb_send_quotation&post_id=' + encodeURIComponent(postId) + '&nonce=' + encodeURIComponent(dqqb_quotation.nonce);
                    xhr.send(params);
                });
            });
        ";
        wp_add_inline_script( 'dqqb-quotation', $inline_js );
    }

    /**
     * AJAX handler for sending quotation email
     */
    public static function ajax_send_quotation() {
        // Validate nonce
        check_ajax_referer( 'dqqb_send_quotation', 'nonce' );

        // Get post ID
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID' ] );
        }

        // Get customer email from workorder meta
        $email = get_post_meta( $post_id, 'wo_contact_email', true );

        // Validate email
        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'No valid customer email found on this workorder' ] );
        }

        // Send email
        $subject = 'Quotation';
        $body    = 'Hello';
        $sent    = wp_mail( $email, $subject, $body );

        if ( $sent ) {
            wp_send_json_success( [ 'message' => 'Email sent successfully' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Failed to send email' ] );
        }
    }
}

add_action( 'init', [ 'DQ_Workorder_Template', 'init' ] );
