<?php
/**
 * Dominus QuickBooks Metabox for Work Orders
 * Adds "Send to QuickBooks" and "Update QuickBooks" buttons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_Metabox {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
    }

    public function add_metabox() {
        add_meta_box(
            'dq_quickbooks_box',
            'QuickBooks Integration',
            [ $this, 'render_metabox' ],
            'workorder', // CPT slug
            'side',
            'high'
        );
    }

    public function render_metabox( $post ) {
        $post_id = $post->ID;
        $invoice_no = function_exists( 'get_field' ) ? get_field( 'wo_invoice_no', $post_id ) : '';
        $invoice_id = function_exists( 'get_field' ) ? get_field( 'wo_invoice_id', $post_id ) : '';

        $send_url   = admin_url( "admin-post.php?action=dq_create_invoice&post_id={$post_id}" );
        $update_url = admin_url( "admin-post.php?action=dq_update_invoice&post_id={$post_id}" );

        echo '<div style="padding:5px 0;">';

        // Show saved info if exists
        if ( $invoice_no || $invoice_id ) {
            echo '<p><strong>Saved QuickBooks Invoice:</strong><br>';
            if ( $invoice_no ) echo 'Invoice No: <code>' . esc_html( $invoice_no ) . '</code><br>';
            if ( $invoice_id ) echo 'Invoice ID: <code>' . esc_html( $invoice_id ) . '</code>';
            echo '</p>';
        } else {
            echo '<p><em>No QuickBooks invoice linked yet.</em></p>';
        }

        echo '<hr>';

        // Send Button
        echo '<p><a href="' . esc_url( $send_url ) . '" class="button button-primary" style="width:100%; text-align:center;">💾 Send to QuickBooks</a></p>';

        // Update Button (only show if invoice exists)
        if ( $invoice_id ) {
            echo '<p><a href="' . esc_url( $update_url ) . '" class="button button-secondary" style="width:100%; text-align:center;">🔄 Update QuickBooks</a></p>';
        }

        echo '</div>';
    }
}

new DQ_Metabox();
