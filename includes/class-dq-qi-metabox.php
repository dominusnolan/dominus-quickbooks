<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Metabox + actions for CPT quickbooks_invoice
 * Buttons:
 * - Send to QuickBooks (create new QBO invoice from CPT when none exists)
 * - Update QuickBooks (push CPT -> QBO by DocNumber)
 * - Pull from QuickBooks (pull QBO -> CPT by DocNumber)
 */
class DQ_QI_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_metabox' ] );
        add_action( 'admin_post_dq_qi_send_to_qbo',   [ __CLASS__, 'send' ] );   // NEW
        add_action( 'admin_post_dq_qi_update_qbo',   [ __CLASS__, 'update' ] );
        add_action( 'admin_post_dq_qi_pull_from_qb', [ __CLASS__, 'pull' ] );
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
    }

    public static function add_metabox() {
        add_meta_box(
            'dq_qi_quickbooks_box',
            'QuickBooks Invoice Sync',
            [ __CLASS__, 'render' ],
            'quickbooks_invoice',
            'side',
            'high'
        );
    }

    public static function render( $post ) {
        $invoice_id = function_exists('get_field') ? get_field('qi_invoice_id', $post->ID) : get_post_meta($post->ID, 'qi_invoice_id', true);
        $invoice_no = function_exists('get_field') ? get_field('qi_invoice_no', $post->ID) : get_post_meta($post->ID, 'qi_invoice_no', true);

        $total = $paid = $balance = 0.0;
        $status = '';
        $invoice_date = $due_date = $terms = '';
        $bill_to = $ship_to = '';

        echo '<div style="padding:6px;font-size:13px;">';

        echo '<div style="margin-bottom:10px;">';
        echo '<h4 style="margin:0 0 8px;border-bottom:1px solid #ddd;">Invoice Info</h4>';

        echo '<p style="margin:4px 0;"><strong>No:</strong> <input type="text" readonly value="' . esc_attr( (string)$invoice_no ) . '" style="width:100%;"></p>';
        echo '<p style="margin:4px 0;"><strong>ID:</strong> <input type="text" readonly value="' . esc_attr( (string)$invoice_id ) . '" style="width:100%;"></p>';

        if ( $invoice_id ) {
            // Pull live QBO totals for display (non-persistent aside from user viewing)
            $data = DQ_API::get_invoice( $invoice_id );
            if ( ! is_wp_error($data) && ! empty($data['Invoice']) ) {
                $inv = $data['Invoice'];
                $total   = isset($inv['TotalAmt']) ? (float)$inv['TotalAmt'] : 0;
                $balance = isset($inv['Balance']) ? (float)$inv['Balance'] : 0;
                $paid    = max($total - $balance, 0);
                $status  = ($balance <= 0 && $total > 0) ? 'PAID' : 'UNPAID';

                if (!empty($inv['TxnDate']))   $invoice_date = (string)$inv['TxnDate'];
                if (!empty($inv['DueDate']))   $due_date     = (string)$inv['DueDate'];
                if (!empty($inv['SalesTermRef'])) {
                    $terms = isset($inv['SalesTermRef']['name']) ? $inv['SalesTermRef']['name'] : $inv['SalesTermRef']['value'];
                }
                if (!empty($inv['BillAddr']))  $bill_to = dq_format_address($inv['BillAddr']);
                if (!empty($inv['ShipAddr']))  $ship_to = dq_format_address($inv['ShipAddr']);
            }
        }

        echo '</div>';

        echo '<div style="margin-bottom:10px;">';
        echo '<h4 style="margin:0 0 8px;border-bottom:1px solid #ddd;">Totals</h4>';
        echo '<p style="margin:3px 0;"><strong>Total:</strong> $'   . number_format( $total, 2 ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Paid:</strong> $'    . number_format( $paid, 2 )  . '</p>';
        echo '<p style="margin:3px 0;"><strong>Balance:</strong> $' . number_format( $balance, 2 ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Status:</strong> ' . esc_html( $status ) . '</p>';

        // Payment deposit totals (deposited vs undeposited)
        $deposited = 0.0;
        $undeposited = 0.0;
        if ( $invoice_id ) {
            $payment_totals = DQ_API::get_payment_deposit_totals( $invoice_id );
            if ( ! is_wp_error( $payment_totals ) ) {
                $deposited = $payment_totals['deposited'];
                $undeposited = $payment_totals['undeposited'];
            }
        }
        echo '<p style="margin:3px 0;"><strong>Deposited:</strong> $' . number_format( $deposited, 2 ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Not deposited:</strong> $' . number_format( $undeposited, 2 ) . '</p>';

        echo '<p style="margin:3px 0;"><strong>Invoice Date:</strong> ' . esc_html( $invoice_date ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Due Date:</strong> ' . esc_html( $due_date ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Terms:</strong> ' . esc_html( $terms ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Bill To:</strong> ' . esc_html( $bill_to ) . '</p>';
        echo '<p style="margin:3px 0;"><strong>Ship To:</strong> ' . esc_html( $ship_to ) . '</p>';
        echo '</div>';

        // Buttons
        $send = wp_nonce_url( admin_url( 'admin-post.php?action=dq_qi_send_to_qbo&post=' . $post->ID ), 'dq_qi_send_' . $post->ID );
        $upd  = wp_nonce_url( admin_url( 'admin-post.php?action=dq_qi_update_qbo&post=' . $post->ID ), 'dq_qi_update_' . $post->ID );
        $pul  = wp_nonce_url( admin_url( 'admin-post.php?action=dq_qi_pull_from_qb&post=' . $post->ID ), 'dq_qi_pull_' . $post->ID );

        if ( empty( $invoice_id ) ) {
            echo '<p><a href="' . esc_url($send) . '" class="button button-primary" style="width:100%;">Send to QuickBooks</a></p>';
        } else {
            echo '<p><a href="' . esc_url($upd) . '" class="button button-primary" style="width:100%;">Update QuickBooks</a></p>';
        }
        echo '<p><a href="' . esc_url($pul) . '" class="button button-secondary" style="width:100%;">Pull from QuickBooks</a></p>';

        echo '</div>';
    }

    /**
     * Create a new QuickBooks invoice from CPT (only if none exists yet).
     */
    public static function send() {
        if ( empty($_GET['post']) ) wp_die('Invalid invoice post.');
        $post_id = absint( $_GET['post'] );
        check_admin_referer( 'dq_qi_send_' . $post_id );

        $existing_id = function_exists('get_field') ? get_field('qi_invoice_id', $post_id) : get_post_meta($post_id, 'qi_invoice_id', true);
        if ( $existing_id ) wp_die('This invoice already has a QuickBooks Invoice ID. Use Update instead.');

        // Build payload from CPT
        $payload = DQ_QI_Sync::build_payload_from_cpt( $post_id );
        if ( is_wp_error( $payload ) ) wp_die( $payload->get_error_message() );

        // Create in QBO
        $resp = DQ_API::create_invoice( $payload );
        if ( is_wp_error( $resp ) ) wp_die( 'QuickBooks error: ' . $resp->get_error_message() );

        $inv = $resp['Invoice'] ?? $resp;

        // Map ID / DocNumber back
        if ( function_exists('update_field') ) {
            if ( ! empty( $inv['Id'] ) )        update_field( 'qi_invoice_id', (string)$inv['Id'], $post_id );
            if ( ! empty( $inv['DocNumber'] ) ) update_field( 'qi_invoice_no', (string)$inv['DocNumber'], $post_id );
        } else {
            if ( ! empty( $inv['Id'] ) )        update_post_meta( $post_id, 'qi_invoice_id', (string)$inv['Id'] );
            if ( ! empty( $inv['DocNumber'] ) ) update_post_meta( $post_id, 'qi_invoice_no', (string)$inv['DocNumber'] );
        }
        update_post_meta( $post_id, 'qi_last_synced', current_time('mysql') );

        // Optional auto-payment if filter enabled (same logic as update)
        if ( apply_filters('dqqb_qi_auto_payment_on_update', false) && !empty($inv['Id']) ) {
            $desired_paid  = (float)( function_exists('get_field') ? ( get_field('qi_total_paid', $post_id) ?: 0 ) : ( get_post_meta($post_id,'qi_total_paid',true) ?: 0 ) );
            $desired_total = (float)( function_exists('get_field') ? ( get_field('qi_total_billed', $post_id) ?: 0 ) : ( get_post_meta($post_id,'qi_total_billed',true) ?: 0 ) );

            $qbo_total   = isset($inv['TotalAmt']) ? (float)$inv['TotalAmt'] : $desired_total;
            $qbo_balance = isset($inv['Balance']) ? (float)$inv['Balance'] : $qbo_total;
            $qbo_paid    = max($qbo_total - $qbo_balance, 0);

            $delta = max(0.0, min($desired_paid, $qbo_total) - $qbo_paid);
            if ( $delta > 0 && ! empty($inv['CustomerRef']['value']) ) {
                $payment_payload = [
                    'CustomerRef' => [ 'value' => (string)$inv['CustomerRef']['value'] ],
                    'TotalAmt'    => $delta,
                    'TxnDate'     => (string)( function_exists('get_field') ? ( get_field('qi_invoice_date', $post_id) ?: date('Y-m-d') ) : date('Y-m-d') ),
                    'PrivateNote' => 'Auto-created payment (Send to QuickBooks)',
                    'Line'        => [
                        [
                            'Amount'    => $delta,
                            'LinkedTxn' => [
                                [ 'TxnId' => (string)$inv['Id'], 'TxnType' => 'Invoice' ],
                            ],
                        ],
                    ],
                ];
                $pay_resp = DQ_API::post('payment', $payment_payload, 'Create Payment (auto on send)');
                if ( is_wp_error($pay_resp) ) {
                    DQ_Logger::error('Auto payment failed (send)', ['post_id'=>$post_id,'invoice_id'=>$inv['Id'],'error'=>$pay_resp->get_error_message()]);
                } else {
                    DQ_Logger::info('Auto payment created (send)', ['post_id'=>$post_id,'invoice_id'=>$inv['Id'],'amount'=>$delta]);
                }
            }
        }

        if ( function_exists('dqqb_sync_invoice_number_to_workorders') ) {
            dqqb_sync_invoice_number_to_workorders( $post_id );
        }

        wp_safe_redirect( add_query_arg( [
            'post'   => $post_id,
            'action' => 'edit',
            'qi_msg' => 'sent'
        ], admin_url( 'post.php' ) ) );
        exit;
    }

    /**
     * Update existing QBO invoice.
     */
    public static function update() {
        if ( empty($_GET['post']) ) wp_die('Invalid post');
        $post_id = absint($_GET['post']);
        check_admin_referer( 'dq_qi_update_' . $post_id );

        $docnum = function_exists('get_field') ? get_field('qi_invoice_no', $post_id) : get_post_meta($post_id, 'qi_invoice_no', true);
        if ( ! $docnum ) wp_die('No qi_invoice_no found.');

        $raw = DQ_API::get_invoice_by_docnumber( $docnum );
        if ( is_wp_error($raw) ) wp_die( 'QuickBooks error: ' . $raw->get_error_message() );
        $invoice = (isset($raw['QueryResponse']['Invoice'][0]) && is_array($raw['QueryResponse']['Invoice'][0]))
            ? $raw['QueryResponse']['Invoice'][0]
            : [];
        if ( empty($invoice['Id']) ) wp_die('QuickBooks invoice not found by DocNumber.');

        $payload = DQ_QI_Sync::build_payload_from_cpt( $post_id );
        if ( is_wp_error($payload) ) wp_die( $payload->get_error_message() );

        $resp = DQ_API::update_invoice( $invoice['Id'], $payload );
        if ( is_wp_error($resp) ) wp_die( 'QuickBooks error: ' . $resp->get_error_message() );

        $inv = $resp['Invoice'] ?? $resp;

        if (function_exists('update_field')) {
            if (!empty($inv['Id']))        update_field('qi_invoice_id', (string)$inv['Id'], $post_id);
            if (!empty($inv['DocNumber'])) update_field('qi_invoice_no', (string)$inv['DocNumber'], $post_id);
        } else {
            if (!empty($inv['Id']))        update_post_meta($post_id, 'qi_invoice_id', (string)$inv['Id']);
            if (!empty($inv['DocNumber'])) update_post_meta($post_id, 'qi_invoice_no', (string)$inv['DocNumber']);
        }
        update_post_meta( $post_id, 'qi_last_synced', current_time('mysql') );

        // Auto-payment (optional via filter)
        if ( apply_filters('dqqb_qi_auto_payment_on_update', false) && !empty($inv['Id']) ) {
            $desired_paid  = (float) ( function_exists('get_field') ? ( get_field('qi_total_paid', $post_id) ?: 0 ) : ( get_post_meta($post_id, 'qi_total_paid', true) ?: 0 ) );
            $desired_total = (float) ( function_exists('get_field') ? ( get_field('qi_total_billed', $post_id) ?: 0 ) : ( get_post_meta($post_id, 'qi_total_billed', true) ?: 0 ) );

            $existing_total   = isset($inv['TotalAmt']) ? (float)$inv['TotalAmt'] : 0.0;
            $existing_balance = isset($inv['Balance']) ? (float)$inv['Balance'] : $existing_total;
            $existing_paid    = max(0.0, $existing_total - $existing_balance);

            $delta = max(0.0, min($desired_paid, $existing_total) - $existing_paid);

            if ( $delta > 0.0 && !empty($inv['CustomerRef']['value']) ) {
                $payment_payload = [
                    'CustomerRef' => [ 'value' => (string)$inv['CustomerRef']['value'] ],
                    'TotalAmt'    => $delta,
                    'TxnDate'     => (string) ( function_exists('get_field') ? ( get_field('qi_invoice_date', $post_id) ?: date('Y-m-d') ) : date('Y-m-d') ),
                    'PrivateNote' => 'Auto-created by Update QuickBooks from CPT',
                    'Line'        => [
                        [
                            'Amount'    => $delta,
                            'LinkedTxn' => [
                                [ 'TxnId' => (string)$inv['Id'], 'TxnType' => 'Invoice' ],
                            ],
                        ],
                    ],
                ];
                $pay_resp = DQ_API::post( 'payment', $payment_payload, 'Create Payment (auto on update)' );
                if ( is_wp_error($pay_resp) ) {
                    DQ_Logger::error('Auto payment failed', [ 'post_id'=>$post_id, 'invoice_id'=>$inv['Id'], 'error'=>$pay_resp->get_error_message() ]);
                } else {
                    DQ_Logger::info('Auto payment created', [ 'post_id'=>$post_id, 'invoice_id'=>$inv['Id'], 'amount'=>$delta ]);
                }
            }
        }

        if (function_exists('dqqb_sync_invoice_number_to_workorders')) {
            dqqb_sync_invoice_number_to_workorders($post_id);
        }
        wp_safe_redirect( add_query_arg([
            'post' => $post_id,
            'action' => 'edit',
            'qi_msg' => 'updated',
        ], admin_url('post.php') ) );
        exit;
    }

    /**
     * Pull QBO -> CPT
     */
    public static function pull() {
        if ( empty($_GET['post']) ) wp_die('Invalid post');
        $post_id = absint($_GET['post']);
        check_admin_referer( 'dq_qi_pull_' . $post_id );

        $res = DQ_QI_Sync::pull_from_qbo( $post_id );
        if ( is_wp_error($res) ) {
            wp_safe_redirect( add_query_arg([
                'post' => $post_id, 'action' => 'edit', 'qi_msg' => 'error:' . $res->get_error_message()
            ], admin_url('post.php') ) );
        } else {
            wp_safe_redirect( add_query_arg([
                'post' => $post_id, 'action' => 'edit', 'qi_msg' => 'pulled'
            ], admin_url('post.php') ) );
        }
        exit;
    }

    public static function admin_notices() {
        if ( empty($_GET['qi_msg']) ) return;
        $msg = (string) wp_unslash($_GET['qi_msg']);
        if ( strpos($msg, 'error:') === 0 ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html( substr($msg, 6) ) . '</strong></p></div>';
            return;
        }
        $labels = [
            'sent'    => '✅ QuickBooks invoice created successfully.',
            'updated' => '✅ QuickBooks invoice updated successfully.',
            'pulled'  => '✅ Pulled invoice data from QuickBooks.',
        ];
        if ( isset($labels[$msg]) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $labels[$msg] ) . '</strong></p></div>';
        }
    }
}