<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DQ_Metabox
 * Version: 4.8.1
 * - Adds Send/Update/Refresh buttons
 * - Displays PAID / UNPAID badges
 * - Adds correct "View in QuickBooks" link (with companyId / txnId)
 * - Supports Sandbox or Production environments
 */
class DQ_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_metabox' ] );
        add_action( 'admin_post_dq_send_to_qbo', [ __CLASS__, 'send' ] );
        add_action( 'admin_post_dq_update_qbo', [ __CLASS__, 'update' ] );
        add_action( 'admin_post_dq_refresh_qbo', [ __CLASS__, 'refresh' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
        
            /**
             * Pre-populate ACF select field "_dq_purchase_order" with taxonomy "purchase_order" terms
             * Applies only to post type "workorder"
             */
            add_filter( 'acf/load_field/name=_dq_purchase_order', function( $field ) {

                // Only populate for Work Order CPT
                global $post;
                if ( empty( $post ) || $post->post_type !== 'workorder' ) {
                    return $field;
                }

                // Clear existing choices
                $field['choices'] = [];

                // Get all taxonomy terms
                $terms = get_terms([
                    'taxonomy'   => 'purchase_order',
                    'hide_empty' => false,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ]);

                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    foreach ( $terms as $term ) {
                        $field['choices'][ $term->term_id ] = $term->name;
                    }
                }

                return $field;
            });
    }

    public static function add_metabox() {
        add_meta_box(
            'dq_quickbooks_box',
            'QuickBooks Integration',
            [ __CLASS__, 'render' ],
            'workorder',
            'side',
            'high'
        );
    }

    public static function render( $post ) {
        $invoice_id  = function_exists('get_field') ? get_field( 'wo_invoice_id', $post->ID ) : get_post_meta( $post->ID, 'wo_invoice_id', true );
        $invoice_no  = function_exists('get_field') ? get_field( 'wo_invoice_no', $post->ID ) : get_post_meta( $post->ID, 'wo_invoice_no', true );              
        echo '<div style="padding:6px;font-size:13px;">';

        // --- Invoice Info ---
        echo '<div style="margin-bottom:10px;">';
        echo '<h4 style="margin:0 0 8px;border-bottom:1px solid #ddd;">Invoice Info</h4>';
        if ( $invoice_no ) {
            echo '<p style="margin:4px 0;"><span class="dashicons dashicons-media-text" style="vertical-align:middle;"></span> <strong>No:</strong> <input type="text" readonly value="' . esc_attr( $invoice_no ) . '" style="width:70px;text-align:center;background:#f9f9f9;border:1px solid #ccc;border-radius:3px;padding:2px 4px;"></p>';
        }
        if ( $invoice_id ) {
            echo '<p style="margin:4px 0;"><span class="dashicons dashicons-media-spreadsheet" style="vertical-align:middle;"></span> <strong>ID:</strong> <input type="text" readonly value="' . esc_attr( $invoice_id ) . '" style="width:80px;text-align:center;background:#f9f9f9;border:1px solid #ccc;border-radius:3px;padding:2px 4px;"></p>';
        }

        // Add working "View in QuickBooks" link
        if ( $invoice_id ) {
            $is_sandbox  = defined('DQ_QB_ENV') && DQ_QB_ENV === 'sandbox';
            $realm_id    = defined('DQ_QB_REALM_ID') ? DQ_QB_REALM_ID : get_option('dq_qb_realm_id');
            $base_url    = $is_sandbox ? 'https://sandbox.qbo.intuit.com/app/invoice' : 'https://app.qbo.intuit.com/app/invoice';

            if ( $realm_id ) {
                $invoice_url = esc_url( $base_url . '?txnId=' . $invoice_id . '&companyId=' . $realm_id );
                echo '<p style="margin:6px 0;">
                        <a href="' . $invoice_url . '" target="_blank" style="text-decoration:none;">
                            <span class="dashicons dashicons-external" style="vertical-align:middle;"></span>
                            View in QuickBooks
                        </a>
                      </p>';
            } else {
                echo '<p><em style="color:#d63638;">Realm ID not configured. Define DQ_QB_REALM_ID or save dq_qb_realm_id option.</em></p>';
            }
        }

        if ( ! $invoice_id && ! $invoice_no ) {
            echo '<em>No invoice found yet.</em>';
        }
        echo '</div>';

        // --- Invoice Totals ---
        echo '<div style="margin-bottom:10px;">';
        echo '<h4 style="margin:0 0 8px;border-bottom:1px solid #ddd;">Invoice Totals</h4>';

        $total = $paid = $balance = 0.00;
        if ( $invoice_id ) {
            $invoice_data = DQ_API::get_invoice( $invoice_id );
            if ( ! is_wp_error( $invoice_data ) && ! empty( $invoice_data['Invoice'] ) ) {
                $invoice = $invoice_data['Invoice'];
                $total   = isset( $invoice['TotalAmt'] ) ? floatval( $invoice['TotalAmt'] ) : 0;
                $balance = isset( $invoice['Balance'] ) ? floatval( $invoice['Balance'] ) : 0;
                $paid    = max( $total - $balance, 0 );

                // Save totals
                update_post_meta( $post->ID, 'wo_total_billed', $total );
                update_post_meta( $post->ID, 'wo_total_paid', $paid );
                update_post_meta( $post->ID, 'wo_balance_due', $balance );

                DQ_Logger::info( "Fetched QuickBooks totals for invoice #$invoice_id", [
                    'Total' => $total,
                    'Paid' => $paid,
                    'Balance' => $balance,
                ] );
            } else {
                echo '<p><em>Unable to retrieve totals from QuickBooks.</em></p>';
            }
        }

        echo '<p style="margin:3px 0;"><strong>Total:</strong> $'   . number_format( $total, 2 ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Paid:</strong> $'    . number_format( $paid, 2 )  . '</p>';
        echo '<p style="margin:3px 0;"><strong>Balance:</strong> $' . number_format( $balance, 2 ) . '</p>';

        // PAID / UNPAID badge
        if ( $total > 0 ) {
            if ( $balance <= 0 ) {
                echo '<div style="background:#e7f6ec;color:#22863a;font-weight:bold;padding:6px 10px;border:1px solid #c7ebd3;border-radius:3px;margin-top:8px;text-align:center;">
                        <span class="dashicons dashicons-yes"></span> PAID
                      </div>';
            } else {
                echo '<div style="background:#fbeaea;color:#d63638;font-weight:bold;padding:6px 10px;border:1px solid #f3b7b7;border-radius:3px;margin-top:8px;text-align:center;">
                        <span class="dashicons dashicons-dismiss"></span> UNPAID
                      </div>';
            }
        }

        echo '</div>';

        // --- Buttons ---
        $send_url    = wp_nonce_url( admin_url( 'admin-post.php?action=dq_send_to_qbo&post=' . $post->ID ), 'dq_send_' . $post->ID );
        $update_url  = wp_nonce_url( admin_url( 'admin-post.php?action=dq_update_qbo&post=' . $post->ID ), 'dq_update_' . $post->ID );
        $refresh_url = wp_nonce_url( admin_url( 'admin-post.php?action=dq_refresh_qbo&post=' . $post->ID ), 'dq_refresh_' . $post->ID );

        if ( empty( $invoice_id ) ) {
            echo '<p><a href="' . esc_url( $send_url ) . '" class="button button-primary" style="width:100%;">Send to QuickBooks</a></p>';
        } else {
            echo '<p><a href="' . esc_url( $update_url ) . '" class="button button-primary" style="width:100%;margin-bottom:5px;">Update QuickBooks</a></p>';
            echo '<p><a href="' . esc_url( $refresh_url ) . '" class="button" style="width:100%;" onclick="return confirm(\'Refresh invoice data from QuickBooks? This will overwrite totals stored on this Work Order.\');">Refresh from QuickBooks</a></p>';
        }

        echo '</div>'; // wrapper
    }

    /** Send to QuickBooks */
    public static function send() {
        if ( empty( $_GET['post'] ) ) wp_die( 'Invalid Work Order.' );
        $post_id = intval( $_GET['post'] );
        check_admin_referer( 'dq_send_' . $post_id );

        $payload = DQ_Invoice::build_from_work_order( $post_id );
        if ( is_wp_error( $payload ) ) wp_die( $payload->get_error_message() );

        $response = DQ_API::create_invoice( $payload );
        if ( is_wp_error( $response ) ) wp_die( 'QuickBooks error: ' . $response->get_error_message() );

        $invoice = $response['Invoice'] ?? $response;

        if ( function_exists( 'update_field' ) ) {
            if ( isset( $invoice['Id'] ) ) update_field( 'wo_invoice_id', $invoice['Id'], $post_id );
            if ( isset( $invoice['DocNumber'] ) ) update_field( 'wo_invoice_no', $invoice['DocNumber'], $post_id );
        } else {
            if ( isset( $invoice['Id'] ) ) update_post_meta( $post_id, 'wo_invoice_id', $invoice['Id'] );
            if ( isset( $invoice['DocNumber'] ) ) update_post_meta( $post_id, 'wo_invoice_no', $invoice['DocNumber'] );
        }
        update_post_meta( $post_id, 'wo_last_synced', current_time( 'mysql' ) );

        wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&dq_msg=sent' ) );
        exit;
    }

    /** Update existing QuickBooks invoice */
    public static function update() {
        if ( empty( $_GET['post'] ) ) wp_die( 'Invalid Work Order.' );
        $post_id = intval( $_GET['post'] );
        check_admin_referer( 'dq_update_' . $post_id );

        $invoice_id = function_exists('get_field') ? get_field( 'wo_invoice_id', $post_id ) : get_post_meta( $post_id, 'wo_invoice_id', true );
        if ( ! $invoice_id ) wp_die( 'No QuickBooks Invoice ID found for this Work Order.' );

        $payload = DQ_Invoice::build_from_work_order( $post_id );
        if ( is_wp_error( $payload ) ) wp_die( $payload->get_error_message() );

        $response = DQ_API::update_invoice( $invoice_id, $payload );
        if ( is_wp_error( $response ) ) wp_die( 'QuickBooks error: ' . $response->get_error_message() );

        $invoice = $response['Invoice'] ?? $response;

        if ( function_exists( 'update_field' ) ) {
            if ( isset( $invoice['Id'] ) ) update_field( 'wo_invoice_id', $invoice['Id'], $post_id );
            if ( isset( $invoice['DocNumber'] ) ) update_field( 'wo_invoice_no', $invoice['DocNumber'], $post_id );
        } else {
            if ( isset( $invoice['Id'] ) ) update_post_meta( $post_id, 'wo_invoice_id', $invoice['Id'] );
            if ( isset( $invoice['DocNumber'] ) ) update_post_meta( $post_id, 'wo_invoice_no', $invoice['DocNumber'] );
        }
        update_post_meta( $post_id, 'wo_last_synced', current_time( 'mysql' ) );

        wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&dq_msg=updated' ) );
        exit;
    }

    /** Refresh invoice data */
    public static function refresh() {
        if ( empty( $_GET['post'] ) ) wp_die( 'Invalid Work Order.' );
        $post_id = intval( $_GET['post'] );
        check_admin_referer( 'dq_refresh_' . $post_id );

        $invoice_id = function_exists('get_field') ? get_field( 'wo_invoice_id', $post_id ) : get_post_meta( $post_id, 'wo_invoice_id', true );
        if ( ! $invoice_id ) wp_die( 'No QuickBooks Invoice ID found for this Work Order.' );

        $invoice_data = DQ_API::get_invoice( $invoice_id );
        if ( is_wp_error( $invoice_data ) ) wp_die( 'QuickBooks error: ' . $invoice_data->get_error_message() );

        if ( ! empty( $invoice_data['Invoice'] ) ) {
            $invoice = $invoice_data['Invoice'];

            if ( isset( $invoice['DocNumber'] ) ) {
                if ( function_exists( 'update_field' ) )
                    update_field( 'wo_invoice_no', $invoice['DocNumber'], $post_id );
                else
                    update_post_meta( $post_id, 'wo_invoice_no', $invoice['DocNumber'] );
            }

            $total   = isset( $invoice['TotalAmt'] ) ? floatval( $invoice['TotalAmt'] ) : 0;
            $balance = isset( $invoice['Balance'] ) ? floatval( $invoice['Balance'] ) : 0;
            $paid    = max( $total - $balance, 0 );

            update_post_meta( $post_id, 'wo_total_billed', $total );
            update_post_meta( $post_id, 'wo_total_paid', $paid );
            update_post_meta( $post_id, 'wo_balance_due', $balance );
            update_post_meta( $post_id, 'wo_last_synced', current_time( 'mysql' ) );
            
             
            // --- NEW: Save Due Date and Terms ---
            $due_date = isset($invoice['DueDate']) ? (string) $invoice['DueDate'] : '';
            $terms    = '';

            if (!empty($invoice['SalesTermRef'])) {
                // Prefer the name, else fallback to the id value
                if (isset($invoice['SalesTermRef']['name']) && $invoice['SalesTermRef']['name'] !== '') {
                    $terms = (string) $invoice['SalesTermRef']['name'];
                } elseif (isset($invoice['SalesTermRef']['value'])) {
                    $terms = (string) $invoice['SalesTermRef']['value']; // e.g. "3" when "Net 30" isn't expanded
                }
            }

            // Save to ACF if available, else post meta
            if (function_exists('update_field')) {
                update_field('wo_due_date', $due_date, $post_id);
                update_field('wo_terms',    $terms,    $post_id);
            } else {
                update_post_meta($post_id, 'wo_due_date', $due_date);
                update_post_meta($post_id, 'wo_terms',    $terms);
            }
            
            // --- NEW: Save Invoice Date on refresh ---
            $invoice_date = isset($invoice['TxnDate']) ? (string) $invoice['TxnDate'] : '';
            if (function_exists('update_field')) {
                update_field('wo_invoice_date', $invoice_date, $post_id);
            } else {
                update_post_meta($post_id, 'wo_invoice_date', $invoice_date);
            }

   
            // 2) After you have both $post_id and the $invoice object/array:
            dominus_qb_update_acf_bill_ship($post_id, $invoice);

            DQ_Logger::info( "Refreshed invoice #$invoice_id from QuickBooks", [
                'DocNumber' => $invoice['DocNumber'] ?? null,
                'Total' => $total, 'Paid' => $paid, 'Balance' => $balance
            ] );

            wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&dq_msg=refreshed' ) );
            exit;
        }

        wp_die( 'QuickBooks response did not include an Invoice object.' );
    }

    /** Display admin notices */
    public static function admin_notices() {
        if ( empty( $_GET['dq_msg'] ) ) return;
        $msg = sanitize_text_field( $_GET['dq_msg'] );
        $messages = [
            'sent'      => '✅ Invoice successfully sent to QuickBooks!',
            'updated'   => '✅ QuickBooks invoice updated successfully!',
            'refreshed' => '✅ Invoice refreshed from QuickBooks successfully!',
            'error'     => '❌ QuickBooks action failed. Check logs for details.',
        ];
        if ( isset( $messages[ $msg ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $messages[ $msg ] ) . '</strong></p></div>';
        }
    }
}
