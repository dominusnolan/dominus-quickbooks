<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DQ_QI_Sync
 * Sync logic for CPT "quickbooks_invoice"
 * Enhancements:
 *  - Logs unmatched Work Order tokens when mapping CustomerMemo/PrivateNote
 *  - Toggle auto "WO-" prefix via filter `dqqb_auto_prefix_wo_prefix` (default true) or option `dqqb_auto_prefix_wo`
 */
class DQ_QI_Sync {

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

        // Header fields
        if ( ! empty( $invoice['Id'] ) ) update_field( 'qi_invoice_id', $invoice['Id'], $post_id );
        if ( ! empty( $invoice['DocNumber'] ) ) update_field( 'qi_invoice_no', (string)$invoice['DocNumber'], $post_id );
        if ( isset($invoice['TotalAmt']) )   update_field( 'qi_total_billed', (float)$invoice['TotalAmt'], $post_id );
        if ( isset($invoice['Balance']) )    update_field( 'qi_balance_due', (float)$invoice['Balance'], $post_id );
        if ( isset($invoice['TotalAmt']) && isset($invoice['Balance']) ) {
            $paid = max( (float)$invoice['TotalAmt'] - (float)$invoice['Balance'], 0 );
            update_field( 'qi_total_paid', $paid, $post_id );
            update_field( 'qi_payment_status', ( $invoice['Balance'] > 0 ? 'Unpaid' : 'Paid' ), $post_id );
        }
        update_field( 'qi_last_synced', current_time('mysql'), $post_id );

        if ( ! empty( $invoice['BillAddr'] ) || ! empty( $invoice['ShipAddr'] ) ) {
            dominus_qb_update_acf_bill_ship_qi( $post_id, $invoice );
        }

        if ( ! empty( $invoice['TxnDate'] ) ) update_field( 'qi_invoice_date', (string)$invoice['TxnDate'], $post_id );
        if ( ! empty( $invoice['DueDate'] ) ) update_field( 'qi_due_date', (string)$invoice['DueDate'], $post_id );
        if ( ! empty( $invoice['SalesTermRef'] ) ) {
            $terms = isset($invoice['SalesTermRef']['name']) ? $invoice['SalesTermRef']['name'] : $invoice['SalesTermRef']['value'];
            update_field( 'qi_terms', (string)$terms, $post_id );
        }

        // Memo parsing (CustomerMemo + PrivateNote)
        $memo_sources = [];
        if ( ! empty($invoice['CustomerMemo']['value']) && is_string($invoice['CustomerMemo']['value']) ) {
            $memo_sources[] = (string) $invoice['CustomerMemo']['value'];
        } elseif ( ! empty($invoice['CustomerMemo']) && is_string($invoice['CustomerMemo']) ) {
            $memo_sources[] = (string) $invoice['CustomerMemo'];
        }
        if ( ! empty($invoice['PrivateNote']) && is_string($invoice['PrivateNote']) ) {
            $memo_sources[] = (string) $invoice['PrivateNote'];
        }

        if ( ! empty( $memo_sources ) ) {
            $combined = implode(' ', $memo_sources);
            $tokens   = self::extract_memo_work_orders( $combined );
            $unmatched = [];
            if ( ! empty( $tokens ) ) {
                $field_obj = function_exists('get_field_object') ? get_field_object('qi_wo_number', $post_id) : null;
                $type      = is_array($field_obj) && ! empty($field_obj['type']) ? $field_obj['type'] : '';

                if ( in_array($type, ['relationship','post_object'], true) ) {
                    $ids = [];
                    foreach ( $tokens as $title ) {
                        $p = get_page_by_title( $title, OBJECT, 'workorder' );
                        if ( $p instanceof WP_Post ) {
                            $ids[] = (int)$p->ID;
                        } else {
                            $unmatched[] = $title;
                        }
                    }
                    if ( ! empty($ids) ) {
                        update_field('qi_wo_number', $ids, $post_id);
                    } else {
                        update_field('qi_wo_number', null, $post_id);
                    }
                } else {
                    // Non-relationship field: store raw tokens
                    update_field('qi_wo_number', $tokens, $post_id);
                }

                if ( ! empty($unmatched) ) {
                    DQ_Logger::info( 'Unmatched Work Order tokens (pull_from_qbo)', [
                        'post_id' => $post_id,
                        'docnumber' => $docnum,
                        'tokens' => $unmatched
                    ] );
                }
            } else {
                update_field('qi_wo_number', null, $post_id);
            }
        }

        $res = self::map_lines_to_acf( $post_id, $invoice );
        if ( is_wp_error($res) ) return $res;

        return 'Pulled invoice ' . $invoice['DocNumber'] . ' (ID ' . $invoice['Id'] . ') and updated fields + lines.';
    }

    public static function build_payload_from_cpt( $post_id ) {
        $payload = [];
        $lines = self::build_lines_from_acf( $post_id );
        if ( is_wp_error($lines) ) return $lines;
        if ( empty($lines) ) return new WP_Error('dq_qi_no_lines', 'No valid lines found in qi_invoice.');
        $payload['Line'] = $lines;
        
        $customer_name = function_exists('get_field') ? get_field('qi_customer', $post_id) : get_post_meta($post_id, 'qi_customer', true);
        if ($customer_name) {
            $customer_id = self::get_or_create_customer_id($customer_name);
            if ($customer_id) {
                $payload['CustomerRef'] = [ 'value' => (string)$customer_id ];
            } else {
                return new WP_Error('dq_qi_no_customer', "No QBO customer found/created for '$customer_name'");
            }
        } else {
            return new WP_Error('dq_qi_no_customer_field', "qi_customer ACF field is empty in CPT post ID $post_id");
        }
        $payload['DocNumber'] = get_the_title($post_id);
        
        $invoice_date_raw = function_exists('get_field') ? get_field('qi_invoice_date', $post_id) : get_post_meta($post_id, 'qi_invoice_date', true);
        $invoice_date = self::normalize_qb_date($invoice_date_raw);
        if (!empty($invoice_date)) {
            $payload['TxnDate'] = $invoice_date;
        }

        $due_date_raw = function_exists('get_field') ? get_field('qi_due_date', $post_id) : get_post_meta($post_id, 'qi_due_date', true);
        $due_date = self::normalize_qb_date($due_date_raw);
        if (!empty($due_date)) {
            $payload['DueDate'] = $due_date;
        }
        
        $bill_to = function_exists('get_field') ? get_field('qi_bill_to', $post_id) : get_post_meta($post_id, 'qi_bill_to', true);
        $ship_to = function_exists('get_field') ? get_field('qi_ship_to', $post_id) : get_post_meta($post_id, 'qi_ship_to', true);
        if ( is_string($bill_to) && trim($bill_to) !== '' ) $payload['BillAddr'] = dominus_qb_parse_address_string( $bill_to );
        if ( is_string($ship_to) && trim($ship_to) !== '' ) $payload['ShipAddr'] = dominus_qb_parse_address_string( $ship_to );

        $terms = function_exists('get_field') ? get_field('qi_terms', $post_id) : get_post_meta($post_id, 'qi_terms', true);
        $terms_ref = self::resolve_terms_ref( $terms );
        if ( $terms_ref ) $payload['SalesTermRef'] = $terms_ref;

        $memo_value = self::build_customer_memo_from_wo( $post_id );
        if ( $memo_value !== '' ) {
            $payload['CustomerMemo'] = [ 'value' => $memo_value ];
            $payload['PrivateNote']  = $memo_value;
        }

        return $payload;
    }
    
    private static function normalize_qb_date($val) {
        $val = trim((string)$val);
        if ($val === '') return '';
        // YYYY-MM-DD QuickBooks native format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
        // n/j/Y format from ACF import (mm/dd/yyyy)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]); // year, month, day
        }
        // Fallback: attempt strtotime
        $ts = strtotime($val);
        return $ts ? date('Y-m-d', $ts) : '';
    }
    
    private static function get_or_create_customer_id($customer_name) {
        $sql  = "SELECT Id FROM Customer WHERE DisplayName = '".addslashes($customer_name)."' STARTPOSITION 1 MAXRESULTS 1";
        $resp = DQ_API::query($sql);
        $arr = $resp['QueryResponse']['Customer'] ?? [];
        if (!empty($arr)) return $arr[0]['Id'];
        // If not found, create
        $payload = [ 'DisplayName' => $customer_name ];
        $resp = DQ_API::create('customer', $payload);
        if (is_wp_error($resp)) return null;
        return $resp['Customer']['Id'] ?? null;
    }

    private static function build_lines_from_acf( $post_id ) {
        if ( ! function_exists('have_rows') ) return new WP_Error('dq_acf_missing', 'ACF is required.');
        $item_map = self::get_item_map();
        if ( is_wp_error($item_map) ) return $item_map;

        $fallback_name = apply_filters('dq_qi_fallback_activity', 'Labor Rate HR');
        $fallback_item_id = self::get_item_id_by_name( $fallback_name, $item_map );
        if ( ! $fallback_item_id ) $fallback_item_id = get_option('dq_default_item_id') ?: '1';

        $rows = [];
        if ( have_rows('qi_invoice', $post_id) ) {
            while ( have_rows('qi_invoice', $post_id) ) {
                the_row();
                $activity = trim( (string) get_sub_field('activity') );
                $desc     = trim( (string) get_sub_field('description') );
                $qty      = self::num( get_sub_field('quantity') );
                $rate     = self::num( get_sub_field('rate') );
                $amount   = self::num( get_sub_field('amount') );

                if ( $qty === null || $rate === null || $qty <= 0 || $rate <= 0 ) continue;
                if ( $amount === null ) $amount = round($qty * $rate, 2);

                $item_id = $fallback_item_id;
                if ( $activity !== '' ) {
                    $try = self::get_item_id_by_name( $activity, $item_map );
                    if ( $try ) $item_id = $try;
                }
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
        if ( ! function_exists('update_field') ) return new WP_Error('dq_acf_missing', 'ACF is required.');
        $lines = isset($invoice['Line']) ? $invoice['Line'] : [];
        $rep = get_field_object('qi_invoice', $post_id);
        if ( empty($rep) || empty($rep['key']) || empty($rep['sub_fields']) ) return 'Lines mapping skipped (qi_invoice repeater missing).';

        $keys = [];
        foreach ( $rep['sub_fields'] as $sf ) $keys[$sf['name']] = $sf['key'];
        foreach ( ['activity','quantity','rate','amount'] as $need ) {
            if ( empty($keys[$need]) ) return new WP_Error('dq_acf_subfield_missing', 'ACF subfield missing: ' . $need);
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
        foreach ($map as $n => $id) if ( strcasecmp($n, $name) === 0 ) return $id;
        $sql = "SELECT Id FROM Item WHERE Name = '".addslashes($name)."' STARTPOSITION 1 MAXRESULTS 1";
        $resp = DQ_API::query($sql);
        if ( ! is_wp_error($resp) && ! empty($resp['QueryResponse']['Item'][0]['Id']) ) {
            return (string)$resp['QueryResponse']['Item'][0]['Id'];
        }
        return null;
    }

    private static function resolve_terms_ref( $terms ) {
        if ( ! $terms ) return null;
        $terms = trim( (string)$terms );
        if ( ctype_digit($terms) ) return [ 'value' => $terms ];
        $sql = "SELECT Id, Name FROM Term WHERE Name = '".addslashes($terms)."' STARTPOSITION 1 MAXRESULTS 1";
        $resp = DQ_API::query( $sql );
        if ( is_wp_error($resp) ) return null;
        $arr = $resp['QueryResponse']['Term'] ?? [];
        if ( ! empty( $arr ) ) return [ 'value' => (string)$arr[0]['Id'] ];
        return null;
    }

    private static function num( $v ) {
        if ( $v === null || $v === '' ) return null;
        if ( is_numeric($v) ) return (float)$v;
        $clean = preg_replace('/[^0-9.\-]/', '', (string)$v);
        if ($clean === '' || !is_numeric($clean)) return null;
        return (float)$clean;
    }

    private static function build_customer_memo_from_wo( $post_id ) {
        $raw = function_exists('get_field') ? get_field('qi_wo_number', $post_id) : get_post_meta($post_id, 'qi_wo_number', true);
        if ( $raw instanceof WP_Post ) $vals = [ $raw ];
        elseif ( is_array($raw) )      $vals = $raw;
        elseif ( is_string($raw) || is_numeric($raw) ) $vals = [ $raw ];
        else $vals = [];

        $titles = [];
        foreach ( $vals as $v ) {
            if ( $v instanceof WP_Post ) { $titles[] = $v->post_title ?: get_the_title( $v->ID ); continue; }
            if ( is_array($v) ) {
                if ( isset($v['post_title']) ) { $titles[] = $v['post_title']; continue; }
                if ( isset($v['ID']) && is_numeric($v['ID']) ) {
                    $t = get_the_title( (int)$v['ID'] );
                    if ( $t ) $titles[] = $t;
                    continue;
                }
                if ( isset($v['value']) ) {
                    $val = $v['value'];
                    if ( is_numeric($val) ) {
                        $t = get_the_title( (int)$val );
                        $titles[] = $t ?: (string)$val;
                    } else {
                        $titles[] = (string)$val;
                    }
                    continue;
                }
                $maybe = trim( (string) ( $v['label'] ?? '' ) );
                if ( $maybe !== '' ) $titles[] = $maybe;
                continue;
            }
            if ( is_numeric($v) ) {
                $t = get_the_title( (int)$v );
                $titles[] = $t ?: (string)$v;
                continue;
            }
            if ( is_string($v) && $v !== '' ) $titles[] = trim($v);
        }

        $titles = array_values(array_unique(array_filter(array_map('trim', $titles), fn($s)=>$s!=='')));
        return empty($titles) ? '' : implode(', ', $titles);
    }

    /**
     * Extract work order tokens from memo string.
     * Auto "WO-" prefix is controlled by:
     *   Option: get_option('dqqb_auto_prefix_wo', '1') == '1'
     *   Filter: apply_filters('dqqb_auto_prefix_wo_prefix', true)
     * If either returns false, prefixing is disabled.
     */
    private static function extract_memo_work_orders( string $memo ) : array {
        $memo = trim($memo);
        if ( $memo === '' ) return [];

        $normalized = preg_replace('/[;,]/', ' ', $memo);
        $raw_tokens = preg_split('/\s+/', $normalized);

        $do_prefix_opt = get_option('dqqb_auto_prefix_wo', '1') === '1';
        $do_prefix = apply_filters('dqqb_auto_prefix_wo_prefix', $do_prefix_opt);

        $tokens = [];
        foreach ( $raw_tokens as $tok ) {
            $t = trim($tok, " \t\n\r\0\x0B,;");
            if ( $t === '' ) continue;

            if ( $do_prefix ) {
                if ( preg_match('/^wo-/i', $t) ) {
                    $t = 'WO-' . substr($t, 3);
                } elseif ( preg_match('/^02\d{6}$/', $t) ) {
                    $t = 'WO-' . $t;
                } elseif ( preg_match('/^wo-02\d{6}$/i', $t) ) {
                    $t = 'WO-' . substr($t, 3);
                }
            } else {
                // Still normalize case for existing prefixes
                if ( preg_match('/^wo-/i', $t) ) {
                    $t = 'WO-' . substr($t, 3);
                }
            }

            $tokens[] = $t;
        }

        $out = [];
        $seen = [];
        foreach ( $tokens as $t ) {
            $key = strtolower($t);
            if ( isset($seen[$key]) ) continue;
            $seen[$key] = true;
            $out[] = $t;
        }
        return $out;
    }
}