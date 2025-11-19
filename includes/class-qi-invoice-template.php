<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Provides a default single template for CPT 'workorder'.
 * - Uses theme's single-workorder.php if present.
 * - Falls back to plugin template at templates/single-workorder.php
 */
class Qi_Invoice_Template {

    public static function init() {
        add_filter( 'single_template', [ __CLASS__, 'qi_single_template' ] );
    }

    /**
     * If viewing a Work Order single and the theme does not provide a template,
     * use the plugin's bundled template.
     */
    public static function qi_single_template( $single ) {
        global $post;
        if ( $post && $post instanceof WP_Post && $post->post_type === 'quickbooks_invoice' ) {

            // Theme override priority
            $theme_template = locate_template( [ 'single-quickbooks_invoice.php', 'dqqb/single-quickbooks_invoice.php' ] );
            if ( ! empty( $theme_template ) ) {
                return $theme_template;
            }

            // Plugin fallback
            $plugin_template = trailingslashit( DQQB_PATH ) . 'templates/single-quickbooks_invoice.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }
        return $single;
    }
}

add_action( 'init', [ 'Qi_Invoice_Template', 'init' ] );