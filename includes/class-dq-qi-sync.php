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

    /**
     * Maximum length for Purchase Order number validation
     */
    const MAX_PO_LENGTH = 100;

    public static function pull_from_qbo( $post_id ) {
        if ( ! function_exists('update_field') ) {
            return new WP_Error('dq_acf_missing', 'ACF is required.');
        }

        $docnum = (string) get_field('qi_invoice_no', $post_id);
        if ( $docnum === '' ) {
            return new WP_Error('dq_no_docnum', 'qi_invoice_no is empty.');
        }

        // Always fetch and extract the invoice object
        $raw = DQ_API::get_invoice_by_docnumber( $docnum );
        if ( is_wp_error($raw) ) wp_die( 'QuickBooks error: ' . $raw->get_error_message() );

        $invoice_obj = (isset($raw['QueryResponse']['Invoice'][0]) && is_array($raw['QueryResponse']['Invoice'][0]))
            ? $raw['QueryResponse']['Invoice'][0]
            : [];
        if ( empty($invoice_obj['Id']) ) wp_die('QuickBooks invoice not found by DocNumber.');

        // Use extracted object for ALL mapping
        $lines = isset($invoice_obj['Line']) ? $invoice_obj['Line'] : [];
        DQ_Logger::info('QuickBooks invoice lines pulled', ['lines'=>$lines, 'invoice'=>$invoice_obj, 'post_id'=>$post_id]);
        
        // Customer sync: Extract CustomerRef and sync to qbo_customers taxonomy
        if ( ! empty( $invoice_obj['CustomerRef'] ) && is_array( $invoice_obj['CustomerRef'] ) ) {
            $customer_name = null;
            
            // Prefer 'name' field (DisplayName)
            if ( ! empty( $invoice_obj['CustomerRef']['name'] ) ) {
                $customer_name = trim( (string) $invoice_obj['CustomerRef']['name'] );
            } elseif ( ! empty( $invoice_obj['CustomerRef']['value'] ) ) {
                // Fallback: if only 'value' (customer ID) is present, query QBO API for DisplayName
                $customer_id = (string) $invoice_obj['CustomerRef']['value'];
                
                // Validate customer ID format (alphanumeric with optional internal hyphens)
                // Pattern ensures ID starts and ends with alphanumeric, with hyphens only between chars
                if ( preg_match( '/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/i', $customer_id ) ) {
                    $customer_data = DQ_API::get( 'customer/' . $customer_id );
                    if ( ! is_wp_error( $customer_data ) && ! empty( $customer_data['Customer']['DisplayName'] ) ) {
                        $customer_name = trim( (string) $customer_data['Customer']['DisplayName'] );
                        DQ_Logger::info( 'Resolved customer DisplayName from QBO API', [
                            'post_id' => $post_id,
                            'customer_id' => $customer_id,
                            'display_name' => $customer_name
                        ] );
                    } else {
                        DQ_Logger::warning( 'Could not resolve customer DisplayName from QBO API', [
                            'post_id' => $post_id,
                            'customer_id' => $customer_id,
                            'error' => is_wp_error( $customer_data ) ? $customer_data->get_error_message() : 'No DisplayName in response'
                        ] );
                    }
                } else {
                    DQ_Logger::error( 'Invalid customer ID format in CustomerRef', [
                        'post_id' => $post_id,
                        'customer_id' => $customer_id
                    ] );
                }
            }
            
            // If we have a customer name, sync it to taxonomy
            if ( ! empty( $customer_name ) ) {
                $term = term_exists( $customer_name, 'qbo_customers' );
                $term_id = null;
                
                if ( $term ) {
                    $term_id = self::extract_term_id( $term );
                    DQ_Logger::info( 'Found existing qbo_customers term', [
                        'post_id' => $post_id,
                        'customer_name' => $customer_name,
                        'term_id' => $term_id
                    ] );
                } else {
                    // Term doesn't exist, create it
                    $term = wp_insert_term( $customer_name, 'qbo_customers' );
                    if ( is_wp_error( $term ) ) {
                        DQ_Logger::error( 'Failed to create qbo_customers term', [
                            'post_id' => $post_id,
                            'customer_name' => $customer_name,
                            'error' => $term->get_error_message()
                        ] );
                        $term_id = null;
                    } else {
                        $term_id = self::extract_term_id( $term );
                        DQ_Logger::info( 'Created new qbo_customers term', [
                            'post_id' => $post_id,
                            'customer_name' => $customer_name,
                            'term_id' => $term_id
                        ] );
                    }
                }
                
                // If we have a valid term_id, assign it to the post
                if ( $term_id ) {
                    // Assign term to post (creates the taxonomy relationship)
                    // Note: The 'false' parameter (append=false) replaces all existing terms for this taxonomy
                    // This enforces single-customer-per-invoice constraint, matching QuickBooks Online's
                    // data model where each invoice belongs to exactly one customer (not shared/multi-customer)
                    $set_result = wp_set_object_terms( $post_id, $term_id, 'qbo_customers', false );
                    
                    if ( is_wp_error( $set_result ) ) {
                        DQ_Logger::error( 'Failed to assign qbo_customers term to post', [
                            'post_id' => $post_id,
                            'customer_name' => $customer_name,
                            'term_id' => $term_id,
                            'error' => $set_result->get_error_message()
                        ] );
                    } else {
                        // Also update the ACF taxonomy field 'qi_customer'
                        // This field is configured to link to the qbo_customers taxonomy
                        // ACF will return it as a WP_Term object based on the field's return_format setting
                        $updated = update_field( 'qi_customer', $term_id, $post_id );
                        if ( $updated ) {
                            DQ_Logger::info( 'Updated qi_customer field from QBO CustomerRef', [
                                'post_id' => $post_id,
                                'customer_name' => $customer_name,
                                'term_id' => $term_id
                            ] );
                        } else {
                            DQ_Logger::error( 'Failed to update qi_customer ACF field', [
                                'post_id' => $post_id,
                                'customer_name' => $customer_name,
                                'term_id' => $term_id
                            ] );
                        }
                    }
                } else {
                    DQ_Logger::error( 'Could not determine valid term_id for qbo_customers', [
                        'post_id' => $post_id,
                        'customer_name' => $customer_name,
                        'term_result_type' => is_array( $term ) ? 'array' : gettype( $term )
                    ] );
                }
            } else {
                DQ_Logger::debug( 'No customer name found in CustomerRef', [
                    'post_id' => $post_id,
                    'customer_ref' => $invoice_obj['CustomerRef']
                ] );
            }
        } else {
            DQ_Logger::debug( 'No CustomerRef found in QuickBooks invoice', [
                'post_id' => $post_id
            ] );
        }
        
        // Header fields
        if ( ! empty( $invoice_obj['Id'] ) ) update_field( 'qi_invoice_id', $invoice_obj['Id'], $post_id );
        if ( ! empty( $invoice_obj['DocNumber'] ) ) update_field( 'qi_invoice_no', (string)$invoice_obj['DocNumber'], $post_id );
        if ( isset($invoice_obj['TotalAmt']) )   update_field( 'qi_total_billed', (float)$invoice_obj['TotalAmt'], $post_id );
        if ( isset($invoice_obj['Balance']) )    update_field( 'qi_balance_due', (float)$invoice_obj['Balance'], $post_id );
        if ( isset($invoice_obj['TotalAmt']) && isset($invoice_obj['Balance']) ) {
            $paid = max( (float)$invoice_obj['TotalAmt'] - (float)$invoice_obj['Balance'], 0 );
            update_field( 'qi_total_paid', $paid, $post_id );
            update_field( 'qi_payment_status', ( $invoice_obj['Balance'] > 0 ? 'Unpaid' : 'Paid' ), $post_id );
        }
        update_field( 'qi_last_synced', current_time('mysql'), $post_id );

        // Bill/Ship Address
        if ( ! empty( $invoice_obj['BillAddr'] ) || ! empty( $invoice_obj['ShipAddr'] ) ) {
            dominus_qb_update_acf_bill_ship_qi( $post_id, $invoice_obj );
        }

        // Dates/terms
        if ( ! empty( $invoice_obj['TxnDate'] ) ) update_field( 'qi_invoice_date', (string)$invoice_obj['TxnDate'], $post_id );
        if ( ! empty( $invoice_obj['DueDate'] ) ) update_field( 'qi_due_date', (string)$invoice_obj['DueDate'], $post_id );
        if ( ! empty( $invoice_obj['SalesTermRef'] ) ) {
            $terms = isset($invoice_obj['SalesTermRef']['name']) ? $invoice_obj['SalesTermRef']['name'] : $invoice_obj['SalesTermRef']['value'];
            update_field( 'qi_terms', (string)$terms, $post_id );
        }

        // Memo parsing (CustomerMemo + PrivateNote)
        $memo_sources = [];
        if ( ! empty($invoice_obj['CustomerMemo']['value']) && is_string($invoice_obj['CustomerMemo']['value']) ) {
            $memo_sources[] = (string) $invoice_obj['CustomerMemo']['value'];
        } elseif ( ! empty($invoice_obj['CustomerMemo']) && is_string($invoice_obj['CustomerMemo']) ) {
            $memo_sources[] = (string) $invoice_obj['CustomerMemo'];
        }
        if ( ! empty($invoice_obj['PrivateNote']) && is_string($invoice_obj['PrivateNote']) ) {
            $memo_sources[] = (string) $invoice_obj['PrivateNote'];
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

        // Process PO# custom field from QuickBooks
        if ( ! empty( $invoice_obj['CustomField'] ) && is_array( $invoice_obj['CustomField'] ) ) {
            $po_value = null;
            foreach ( $invoice_obj['CustomField'] as $field ) {
                // Normalize PO detection to accept both 'PO#' and 'Purchase Orders' (and variants)
                $field_name = isset( $field['Name'] ) ? trim( (string) $field['Name'] ) : '';
                
                // Check if this is a PO field by Name (exact matches or case-insensitive substring)
                // Only matches fields explicitly containing purchase order terminology
                $is_po_field = ( $field_name === 'PO#' || 
                                 $field_name === 'Purchase Orders' || 
                                 $field_name === 'Purchase Order' ||
                                 ( stripos( $field_name, 'purchase order' ) !== false && strlen( $field_name ) < 50 ) );
                
                if ( $is_po_field ) {
                    // Extract the value from StringValue (the expected field type for PO#)
                    if ( ! empty( $field['StringValue'] ) ) {
                        $po_value = trim( (string) $field['StringValue'] );
                        DQ_Logger::info( 'Found PO CustomField by Name', [
                            'post_id' => $post_id,
                            'field_name' => $field_name,
                            'po_value' => $po_value
                        ] );
                        break;
                    }
                }
            }
            
            // Fallback: check StringValue even if Name doesn't match (for edge cases)
            // This handles situations where:
            // - QBO CustomField Name is missing or empty
            // - QBO CustomField Name was changed but value remains
            // Conservative: only activates if there's exactly one StringType CustomField
            // Can be disabled via filter 'dqqb_enable_po_fallback_detection'
            $enable_fallback = apply_filters( 'dqqb_enable_po_fallback_detection', true );
            if ( empty( $po_value ) && $enable_fallback ) {
                $string_fields = [];
                foreach ( $invoice_obj['CustomField'] as $field ) {
                    if ( ! empty( $field['StringValue'] ) && isset( $field['Type'] ) && $field['Type'] === 'StringType' ) {
                        $string_fields[] = $field;
                    }
                }
                
                // Only use fallback if there's exactly one StringType field (unambiguous)
                if ( count( $string_fields ) === 1 ) {
                    $field = $string_fields[0];
                    $val = trim( (string) $field['StringValue'] );
                    // Validate it looks like a PO number (starts alphanumeric, allows common separators)
                    if ( $val !== '' && strlen( $val ) < self::MAX_PO_LENGTH && preg_match( '/^[A-Z0-9][A-Z0-9\-_#]*$/i', $val ) ) {
                        $po_value = $val;
                        DQ_Logger::info( 'Found PO CustomField by StringValue fallback (single field)', [
                            'post_id' => $post_id,
                            'field_name' => isset( $field['Name'] ) ? $field['Name'] : '(unnamed)',
                            'field_type' => isset( $field['Type'] ) ? $field['Type'] : 'unknown',
                            'po_value' => $po_value
                        ] );
                    }
                }
            }

            if ( ! empty( $po_value ) ) {
                // Get or create the term in the purchase_order taxonomy
                // term_exists returns array or integer (depending on context and WordPress version)
                $term = term_exists( $po_value, 'purchase_order' );
                $term_id = null;
                
                if ( $term ) {
                    $term_id = self::extract_term_id( $term );
                    DQ_Logger::info( 'Found existing purchase_order term', [
                        'post_id' => $post_id,
                        'po_value' => $po_value,
                        'term_id' => $term_id
                    ] );
                } else {
                    // Term doesn't exist, create it
                    $term = wp_insert_term( $po_value, 'purchase_order' );
                    if ( is_wp_error( $term ) ) {
                        DQ_Logger::error( 'Failed to create purchase_order term', [
                            'post_id' => $post_id,
                            'po_value' => $po_value,
                            'error' => $term->get_error_message()
                        ] );
                        $term_id = null;
                    } else {
                        $term_id = self::extract_term_id( $term );
                        DQ_Logger::info( 'Created new purchase_order term', [
                            'post_id' => $post_id,
                            'po_value' => $po_value,
                            'term_id' => $term_id
                        ] );
                    }
                }

                // If we have a valid term_id, assign it to the post
                if ( $term_id ) {
                    // Use ACF field to save the term (this will link the term to the post)
                    $updated = update_field( 'field_dq_qi_purchase_order', $term_id, $post_id );
                    if ( $updated ) {
                        DQ_Logger::info( 'Updated purchase_order taxonomy from QBO CustomField', [
                            'post_id' => $post_id,
                            'po_value' => $po_value,
                            'term_id' => $term_id,
                            'acf_field' => 'field_dq_qi_purchase_order'
                        ] );
                    } else {
                        DQ_Logger::error( 'Failed to update purchase_order ACF field', [
                            'post_id' => $post_id,
                            'po_value' => $po_value,
                            'term_id' => $term_id,
                            'acf_field' => 'field_dq_qi_purchase_order'
                        ] );
                    }
                } else {
                    DQ_Logger::error( 'Could not determine valid term_id for purchase_order', [
                        'post_id' => $post_id,
                        'po_value' => $po_value,
                        'term_result_type' => is_array( $term ) ? 'array' : gettype( $term )
                    ] );
                }
            } else {
                DQ_Logger::debug( 'No PO CustomField found in QuickBooks invoice', [
                    'post_id' => $post_id,
                    'custom_field_count' => count( $invoice_obj['CustomField'] )
                ] );
            }
        }

        $res = self::map_lines_to_acf( $post_id, $invoice_obj );
        if ( is_wp_error($res) ) return $res;

        return 'Pulled invoice ' . $invoice_obj['DocNumber'] . ' (ID ' . $invoice_obj['Id'] . ') and updated fields + lines.';
    }

    public static function build_payload_from_cpt( $post_id ) {
        $payload = [];
        $lines = self::build_lines_from_acf( $post_id );
        if ( is_wp_error($lines) ) return $lines;
        if ( empty($lines) ) return new WP_Error('dq_qi_no_lines', 'No valid lines found in qi_invoice.');
        $payload['Line'] = $lines;

        // Retrieve the customer from the qi_customer ACF taxonomy field
        // This field is configured to return a WP_Term object (return_format='object')
        // so we can extract the term name to match against QBO customer DisplayName
        $customer_term = function_exists('get_field') ? get_field('qi_customer', $post_id) : null;
        
        // Extract the customer name from the term object or fall back to meta
        $customer_name = null;
        if ( $customer_term instanceof WP_Term ) {
            // ACF taxonomy field returned a term object - use the term name
            // This name should match exactly with the QBO customer DisplayName
            $customer_name = $customer_term->name;
        } elseif ( is_numeric($customer_term) ) {
            // Fallback: if we get a term ID (e.g., from old data or direct meta access)
            // This should rarely be needed since the field is configured to return objects
            // but provides backward compatibility
            $term = get_term( (int)$customer_term, 'qbo_customers' );
            if ( $term instanceof WP_Term && ! is_wp_error($term) ) {
                $customer_name = $term->name;
            }
        } elseif ( is_string($customer_term) && $customer_term !== '' ) {
            // Fallback: if we get a string directly, use it as-is
            $customer_name = trim($customer_term);
        } else {
            // Last resort: check raw post meta
            $raw_meta = get_post_meta($post_id, 'qi_customer', true);
            if ( is_string($raw_meta) && $raw_meta !== '' ) {
                $customer_name = trim($raw_meta);
            }
        }
        
        if ( $customer_name && $customer_name !== '' ) {
            // Match the customer name against QBO customer DisplayName
            // This uses exact matching to find or create the customer in QuickBooks
            // Note: For sub-customers (parent:child), QBO may require special handling
            // Future enhancement: parse parent/child DisplayName format if needed
            $customer_id = self::get_or_create_customer_id($customer_name);
            if ($customer_id) {
                $payload['CustomerRef'] = [ 'value' => (string)$customer_id ];
            } else {
                return new WP_Error('dq_qi_no_customer', "No QBO customer found/created for DisplayName: '$customer_name'");
            }
        } else {
            return new WP_Error('dq_qi_no_customer_field', "qi_customer ACF field is empty or invalid in CPT post ID $post_id");
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
        // Use the centralized timezone-aware helper function with the configured date format
        $fmt = dqqb_qi_date_format();
        return dqqb_normalize_date_for_storage( $val, $fmt );
    }

    /**
     * Get or create a QuickBooks customer ID by DisplayName.
     * 
     * This method performs exact matching against the QBO customer DisplayName field.
     * The customer name from the ACF taxonomy term must match exactly with the 
     * DisplayName in QuickBooks Online for the sync to work correctly.
     * 
     * Matching Logic:
     * 1. Query QBO for a customer with exact DisplayName match (case-sensitive)
     * 2. If found, return the customer ID
     * 3. If not found, attempt to create a new customer with that DisplayName
     * 4. Return the new customer ID or null on failure
     * 
     * Sub-customer Considerations:
     * - QBO sub-customers may have DisplayName format like "ParentName:SubName"
     * - Ensure taxonomy term names match this exact format if using sub-customers
     * - Future enhancement: Add parent/child parsing logic if hierarchical taxonomy is used
     * 
     * @param string $customer_name The exact DisplayName to match in QuickBooks
     * @return string|null Customer ID if found/created, null on failure
     */
    private static function get_or_create_customer_id($customer_name) {
        // Query QBO for exact DisplayName match
        // Note: QBO uses a SQL-like query language (not actual SQL) sent via REST API
        // addslashes() provides basic escaping for single quotes in the query string
        // The customer_name comes from trusted sources (ACF taxonomy term names)
        $sql  = "SELECT Id FROM Customer WHERE DisplayName = '".addslashes($customer_name)."' STARTPOSITION 1 MAXRESULTS 1";
        $resp = DQ_API::query($sql);
        $arr = $resp['QueryResponse']['Customer'] ?? [];
        
        // Return existing customer ID if found
        if (!empty($arr)) return $arr[0]['Id'];
        
        // Customer not found - attempt to create new customer in QBO
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
        if ( empty($rep) || empty($rep['key']) || empty($rep['sub_fields']) ) {
            DQ_Logger::error('qi_invoice repeater misconfigured', ['qi_invoice'=>$rep]);
            return 'Lines mapping skipped (qi_invoice repeater missing).';
        }

        $keys = [];
        foreach ( $rep['sub_fields'] as $sf ) $keys[$sf['name']] = $sf['key'];
        foreach ( ['activity','description','quantity','rate','amount'] as $need ) {
            if ( empty($keys[$need]) ) return new WP_Error('dq_acf_subfield_missing', 'ACF subfield missing: ' . $need);
        }

        $rows = [];
        foreach ($lines as $line) {
            if (!isset($line['DetailType']) || $line['DetailType'] !== 'SalesItemLineDetail') continue;

            $d    = $line['SalesItemLineDetail'];
            $name = isset($d['ItemRef']['name']) ? trim($d['ItemRef']['name']) : '';
            $qty  = isset($d['Qty'])       ? (float)$d['Qty']       : 0.0;
            $rate = isset($d['UnitPrice']) ? (float)$d['UnitPrice'] : 0.0;
            $amount = isset($line['Amount']) ? (float)$line['Amount'] : ($qty * $rate);
            $description = isset($line['Description']) ? trim($line['Description']) : '';

            // Direct mapping from QBO to ACF
            $rows[] = [
                $keys['activity']    => $name,
                $keys['description'] => $description,
                $keys['quantity']    => $qty,
                $keys['rate']        => $rate,
                $keys['amount']      => $amount,
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

    /**
     * Extract term_id from term_exists return value.
     * 
     * Handles multiple WordPress versions and return formats:
     * - Modern WordPress (5.x+): array with 'term_id' and 'term_taxonomy_id' keys
     * - Legacy WordPress (4.x): indexed array [term_id, term_taxonomy_id]
     * - By ID lookup: integer term_id
     * 
     * @param mixed $term Return value from term_exists()
     * @return int|null The term ID, or null if it cannot be determined
     */
    private static function extract_term_id( $term ) {
        if ( ! $term ) {
            return null;
        }
        
        if ( is_array( $term ) ) {
            if ( isset( $term['term_id'] ) ) {
                return (int) $term['term_id'];
            } elseif ( isset( $term[0] ) ) {
                return (int) $term[0];
            }
        } elseif ( is_numeric( $term ) ) {
            return (int) $term;
        }
        
        return null;
    }
}
