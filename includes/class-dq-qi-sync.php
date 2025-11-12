<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DQ_QI_Sync
 * Sync logic for CPT "quickbooks_invoice"
 * - Pull from QBO by DocNumber -> map to qi_* ACF fields
 * - Build update payload from qi_* ACF fields -> push to QBO (including header fields)
 */
class DQ_QI_Sync {

    /**
     * Pull a QuickBooks invoice by DocNumber (qi_invoice_no) and map header + lines to ACF.
     */
    public static function pull_from_qbo( $post_id ) {
        if ( ! function_exists('update_field') ) {
            return new WP_Error('dq_acf_missing', 'ACF is required.');
        }

        $docnum = (string) get_field('qi_invoice_no', $post_id);
        if ( $docnum === '' ) {
            return new WP_Error('dq_no_docnum', 'qi_invoice_no is empty.');
        }

        $invoice = DQ_API::get_invoice_by_docnumber( $docnum );
        if ( is_wp_error( $invoice ) ) return $invoice;

        // Map header fields
        if ( ! empty( $invoice['Id'] ) ) {
            update_field( 'qi_invoice_id', $invoice['Id'], $post_id );
        }
        if ( ! empty( $invoice['DocNumber'] ) ) {
            update_field( 'qi_invoice_no', (string) $invoice['DocNumber'], $post_id );
        }
        if ( isset($invoice['TotalAmt']) )   update_field( 'qi_total_billed', (float)$invoice['TotalAmt'], $post_id );
        if ( isset($invoice['Balance']) )    update_field( 'qi_balance_due', (float)$invoice['Balance'], $post_id );
        if ( isset($invoice['TotalAmt']) && isset($invoice['Balance']) ) {
            $paid = max( (float)$invoice['TotalAmt'] - (float)$invoice['Balance'], 0 );
            update_field( 'qi_total_paid', $paid, $post_id );
            update_field( 'qi_payment_status', ( $invoice['Balance'] > 0 ? 'UNPAID' : 'PAID' ), $post_id );
        }
        update_field( 'qi_last_synced', current_time('mysql'), $post_id );

        // Bill/Ship to
        if ( ! empty( $invoice['BillAddr'] ) || ! empty( $invoice['ShipAddr'] ) ) {
            dominus_qb_update_acf_bill_ship_qi( $post_id, $invoice );
        }

        // Dates and terms
        if ( ! empty( $invoice['TxnDate'] ) ) update_field( 'qi_invoice_date', (string)$invoice['TxnDate'], $post_id );
        if ( ! empty( $invoice['DueDate'] ) ) update_field( 'qi_due_date', (string)$invoice['DueDate'], $post_id );
        if ( ! empty( $invoice['SalesTermRef'] ) ) {
            $terms = isset($invoice['SalesTermRef']['name']) ? $invoice['SalesTermRef']['name'] : $invoice['SalesTermRef']['value'];
            update_field( 'qi_terms', (string)$terms, $post_id );
        }

        // Lines -> ACF repeater qi_invoice
        $res = self::map_lines_to_acf( $post_id, $invoice );
        if ( is_wp_error($res) ) return $res;

        return 'Pulled invoice ' . $invoice['DocNumber'] . ' (ID ' . $invoice['Id'] . ') and updated fields + lines.';
    }

    /**
     * Build an update payload from CPT fields to push to QBO.
     * Includes:
     * - Line items from ACF repeater qi_invoice
     * - Header: BillAddr, ShipAddr (parsed from qi_*), SalesTermRef (from qi_terms)
     */
    public static function build_payload_from_cpt( $post_id ) {
        $payload = [];

        // 1) Lines
        $lines = self::build_lines_from_acf( $post_id );
        if ( is_wp_error($lines) ) return $lines;
        if ( empty($lines) ) {
            return new WP_Error('dq_qi_no_lines', 'No valid lines found in qi_invoice.');
        }
        $payload['Line'] = $lines;

        // 2) Header: Bill/Ship address (parse from text)
        $bill_to = function_exists('get_field') ? get_field('qi_bill_to', $post_id) : get_post_meta($post_id, 'qi_bill_to', true);
        $ship_to = function_exists('get_field') ? get_field('qi_ship_to', $post_id) : get_post_meta($post_id, 'qi_ship_to', true);
        if ( is_string($bill_to) && trim($bill_to) !== '' ) {
            $payload['BillAddr'] = dominus_qb_parse_address_string( $bill_to );
        }
        if ( is_string($ship_to) && trim($ship_to) !== '' ) {
            $payload['ShipAddr'] = dominus_qb_parse_address_string( $ship_to );
        }

        // 3) Header: Terms (resolve by name or id)
        $terms = function_exists('get_field') ? get_field('qi_terms', $post_id) : get_post_meta($post_id, 'qi_terms', true);
        $terms_ref = self::resolve_terms_ref( $terms );
        if ( $terms_ref ) {
            $payload['SalesTermRef'] = $terms_ref;
        }

        return $payload;
    }

    private static function build_lines_from_acf( $post_id ) {
        if ( ! function_exists('have_rows') ) {
            return new WP_Error('dq_acf_missing', 'ACF is required.');
        }

        // Get QBO Item Name -> ID map
        $item_map = self::get_item_map();
        if ( is_wp_error($item_map) ) return $item_map;

        $fallback_name = apply_filters('dq_qi_fallback_activity', 'Labor Rate HR');
        $fallback_item_id = self::get_item_id_by_name( $fallback_name, $item_map );
        if ( ! $fallback_item_id ) {
            // Try a default plugin option as absolute fallback
            $fallback_item_id = get_option('dq_default_item_id') ?: '1';
        }

        $rows = [];
        if ( have_rows('qi_invoice', $post_id) ) {
            while ( have_rows('qi_invoice', $post_id) ) {
                the_row();
                $activity = trim( (string) get_sub_field('activity') );
                $desc     = trim( (string) get_sub_field('description') );
                $qty      = self::num( get_sub_field('quantity') );
                $rate     = self::num( get_sub_field('rate') );
                $amount   = self::num( get_sub_field('amount') ); // will fallback to qty*rate if missing

                if ( $qty === null || $rate === null || $qty <= 0 || $rate <= 0 ) {
                    continue;
                }
                if ( $amount === null ) $amount = round($qty * $rate, 2);

                // Resolve ItemRef by name
                $item_id = $fallback_item_id;
                if ( $activity !== '' ) {
                    $try = self::get_item_id_by_name( $activity, $item_map );
                    if ( $try ) $item_id = $try;
                }

                // Use description subfield when provided; otherwise fall back to activity or default
                $line_description = $desc !== '' ? $desc : ($activity !== '' ? $activity : $fallback_name);

                $rows[] = [
                    'Amount'      => (float)$amount,
                    'Description' => $line_description,
                    'DetailType'  => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => [
                        'ItemRef'   => [ 'value' => (string)$item_id ],
                        'Qty'       => (float)$qty,
                        'UnitPrice' => (float)$rate,
                        'TaxCodeRef'=> [ 'value' => dqqb_option('default_tax_code', 'NON') ],
                    ],
                ];
            }
        }

        return $rows;
    }

    private static function map_lines_to_acf( $post_id, $invoice ) {
        if ( ! function_exists('update_field') ) {
            return new WP_Error('dq_acf_missing', 'ACF is required.');
        }

        $lines = isset($invoice['Line']) ? $invoice['Line'] : [];
        $rep = get_field_object('qi_invoice', $post_id);
        if ( empty($rep) || empty($rep['key']) || empty($rep['sub_fields']) ) {
            // If repeater not configured, silently ignore lines mapping
            return 'Lines mapping skipped (qi_invoice repeater missing).';
        }

        $keys = [];
        foreach ( $rep['sub_fields'] as $sf ) {
            $keys[$sf['name']] = $sf['key'];
        }
        foreach ( ['activity','quantity','rate','amount'] as $need ) {
            if ( empty($keys[$need]) ) {
                return new WP_Error('dq_acf_subfield_missing', 'ACF subfield missing: ' . $need);
            }
        }

        $allowed_activities = apply_filters('dq_qi_allowed_activities', ['Labor Rate HR']);
        $rows = [];

        foreach ( $lines as $line ) {
            if ( !isset($line['DetailType']) || $line['DetailType'] !== 'SalesItemLineDetail' ) continue;

            $d       = $line['SalesItemLineDetail'];
            $name    = isset($d['ItemRef']['name']) ? trim($d['ItemRef']['name']) : '';
            $qty     = isset($d['Qty'])       ? (float)$d['Qty']       : 0.0;
            $rate    = isset($d['UnitPrice']) ? (float)$d['UnitPrice'] : 0.0;
            $amount  = isset($line['Amount']) ? (float)$line['Amount'] : ($qty * $rate);

            $activity = 'Labor Rate HR';
            foreach ( $allowed_activities as $a ) {
                if ( strcasecmp($a, $name) === 0 ) { $activity = $a; break; }
            }

            $rows[] = [
                $keys['activity'] => $activity,
                $keys['quantity'] => $qty,
                $keys['rate']     => $rate,
                $keys['amount']   => $amount,
            ];
        }

        update_field( $rep['key'], $rows, $post_id );
        return sprintf('Mapped %d line(s) from QBO to qi_invoice.', count($rows));
    }

    private static function get_item_map() {
        $query = "SELECT Name, Id FROM Item WHERE Active = true";
        $qbo_items = DQ_API::query( $query );
        if ( is_wp_error($qbo_items) ) return $qbo_items;

        $map = [];
        if ( ! empty( $qbo_items['QueryResponse']['Item'] ) ) {
            foreach ( $qbo_items['QueryResponse']['Item'] as $it ) {
                $map[ (string)$it['Name'] ] = (string)$it['Id'];
            }
        }
        return $map;
    }

    private static function get_item_id_by_name( $name, $map ) {
        if ( isset($map[$name]) ) return $map[$name];
        // Try case-insensitive match
        foreach ($map as $n => $id) {
            if ( strcasecmp($n, $name) === 0 ) return $id;
        }
        // As a last attempt, query just this item by name (exact)
        $sql = "SELECT Id FROM Item WHERE Name = '".addslashes($name)."' STARTPOSITION 1 MAXRESULTS 1";
        $resp = DQ_API::query($sql);
        if ( ! is_wp_error($resp) && ! empty($resp['QueryResponse']['Item'][0]['Id']) ) {
            return (string) $resp['QueryResponse']['Item'][0]['Id'];
        }
        return null;
    }

    private static function resolve_terms_ref( $terms ) {
        if ( ! $terms ) return null;

        $terms = trim( (string) $terms );
        // If numeric, assume it's an ID
        if ( ctype_digit($terms) ) {
            return [ 'value' => $terms ];
        }

        // Otherwise, look up by name
        $sql = "SELECT Id, Name FROM Term WHERE Name = '".addslashes($terms)."' STARTPOSITION 1 MAXRESULTS 1";
        $resp = DQ_API::query( $sql );
        if ( is_wp_error($resp) ) return null;

        $arr = $resp['QueryResponse']['Term'] ?? [];
        if ( ! empty( $arr ) ) {
            return [ 'value' => (string)$arr[0]['Id'] ];
        }
        return null;
    }

    private static function num( $v ) {
        if ( $v === null || $v === '' ) return null;
        if ( is_numeric($v) ) return (float)$v;
        $clean = preg_replace('/[^0-9.\-]/', '', (string)$v);
        if ($clean === '' || !is_numeric($clean)) return null;
        return (float)$clean;
    }
}
