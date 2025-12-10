<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CSV → CPT quickbooks_invoice importer (hardcoded ACF keys, no date for qi_invoice)
 */
class DQ_QI_CSV_Import {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=quickbooks_invoice',
            'Import Invoices (CSV)',
            'Import CSV',
            'edit_posts',
            'dq-qi-import',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Permission denied' );
        $notice = '';
        if ( isset( $_POST['dq_qi_import_nonce'] ) && wp_verify_nonce( $_POST['dq_qi_import_nonce'], 'dq_qi_import' ) ) {
            $notice = self::handle_import();
        }
        echo '<div class="wrap"><h1>Import QuickBooks Invoices from CSV</h1>';
        if ( $notice ) echo wp_kses_post( $notice );
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field( 'dq_qi_import', 'dq_qi_import_nonce' );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>CSV File</th><td><input type="file" name="csv_file" accept=".csv,text/csv" required></td></tr>';
        echo '<tr><th>Delimiter</th><td><input type="text" name="delimiter" value="," size="2"><p class="description">Default ,</p></td></tr>';
        echo '<tr><th>Date Format</th><td><input type="text" name="date_format" value="n/j/Y"><p class="description">For TxnDate/DueDate (e.g. 2/5/2025)</p></td></tr>';
        echo '<tr><th>Default Terms</th><td><input type="text" name="default_terms" value="Net 60"></td></tr>';
        echo '<tr><th>Update Existing</th><td><label><input type="checkbox" name="update_existing" value="1"> Update existing invoices by DocNumber</label></td></tr>';
        echo '<tr><th>Auto Prefix "WO-"</th><td>';
        $pref = get_option('dqqb_auto_prefix_wo', '1');
        echo '<label><input type="checkbox" name="auto_prefix_wo" value="1" '.checked($pref,'1',false).'> Enable automatic "WO-" prefixing (02xxxxxx)</label>';
        echo '<p class="description">Stores option dqqb_auto_prefix_wo. You can also override via filter dqqb_auto_prefix_wo_prefix.</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button('Import CSV');
        echo '</form></div>';
    }

    private static function handle_import() {
        $auto_prefix = isset($_POST['auto_prefix_wo']) ? '1' : '0';
        update_option('dqqb_auto_prefix_wo', $auto_prefix, 'no');

        if ( empty( $_FILES['csv_file']['name'] ) ) {
            return '<div class="notice notice-error"><p>No CSV selected.</p></div>';
        }

        $delimiter       = isset($_POST['delimiter']) && $_POST['delimiter'] !== '' ? substr((string)$_POST['delimiter'],0,1) : ',';
        $date_format     = isset($_POST['date_format']) && $_POST['date_format'] !== '' ? (string)$_POST['date_format'] : 'n/j/Y';
        $default_terms   = isset($_POST['default_terms']) && $_POST['default_terms'] !== '' ? sanitize_text_field($_POST['default_terms']) : 'Net 60';
        $update_existing = ! empty($_POST['update_existing']);

        $file = wp_handle_upload($_FILES['csv_file'], ['test_form'=>false]);
        if ( isset($file['error']) ) return '<div class="notice notice-error"><p>Upload error: '.esc_html($file['error']).'</p></div>';

        $fh = @fopen($file['file'], 'r');
        if (!$fh) return '<div class="notice notice-error"><p>Unable to open uploaded file.</p></div>';

        $headers = fgetcsv($fh, 0, $delimiter);
        if ( ! is_array($headers) || empty($headers) ) {
            fclose($fh);
            return '<div class="notice notice-error"><p>CSV seems to have no header row.</p></div>';
        }
        $map = self::build_header_map($headers);

        // HARDCODED subfield keys for qi_invoice, no date subfield
        $subkeys = [
            'activity'    => 'field_6914fa53a5070',
            'description' => 'field_6914fa53a50a9',
            'quantity'    => 'field_6914fa53a50e4',
            'rate'        => 'field_6914fa53a5100',
            'amount'      => 'field_6914fa53a5139',
        ];

        $created = $updated = $skipped = $errors = 0;
        $msgs = [];
        while ( ($row = fgetcsv($fh,0,$delimiter)) !== false ) {
            if ( empty(array_filter($row, fn($v)=>trim((string)$v)!=='') ) ) continue;
            $data = self::row_to_assoc($map, $row);

            $doc     = self::val($data, ['docnumber','invoiceno','invoice no']);
            $txn     = self::val($data, ['txndate','date']);
            $cust    = self::val($data, ['customer','displayname']);
            $memo    = self::val($data, ['customermemo','memo']);
            $due     = self::val($data, ['duedate']);
            $activity= self::val($data, ['activity']);
            $desc    = self::val($data, ['description','desc']);
            $qty     = self::val($data, ['quantity','qty']);
            $rate    = self::val($data, ['rate']);
            $total   = self::num(self::val($data, ['totalamt','total','amount']));
            $balance = self::num(self::val($data, ['balance','balance due']));
            $paid_csv= self::num(self::val($data, ['paid']));
            $po      = self::val($data, ['purchaseorder','po','purchase order']);
            $terms   = self::val($data, ['terms']);

            if ( $doc === '' ) { $skipped++; $msgs[]='Skipped empty DocNumber row.'; continue; }

            $post_id = self::find_existing_invoice_post($doc);
            $is_update = false;
            $post_date = self::parse_date($txn, $date_format);
            $post_date_gmt = get_gmt_from_date($post_date);

            if ( $post_id && $update_existing ) {
                $is_update = true;
                wp_update_post([
                    'ID' => $post_id,
                    'post_title' => $doc,
                    'post_date' => $post_date,
                    'post_date_gmt' => $post_date_gmt,
                ]);
            } elseif ( ! $post_id ) {
                $post_id = wp_insert_post([
                    'post_type' => 'quickbooks_invoice',
                    'post_status'=> 'publish',
                    'post_title' => $doc,
                    'post_date'  => $post_date,
                    'post_date_gmt' => $post_date_gmt,
                ]);
                if ( is_wp_error($post_id) || ! $post_id ) {
                    $errors++; $msgs[]='Failed creating post for DocNumber '.$doc; continue;
                }
            } else {
                $skipped++; $msgs[]='Exists (no update): '.$doc; continue;
            }

            dqqb_sync_invoice_number_to_workorders($post_id);

            $set = function($key,$val) use ($post_id){
                if ( function_exists('update_field') ) update_field($key,$val,$post_id);
                else update_post_meta($post_id,$key,$val);
            };

            $set('qi_invoice_no',$doc);
            
            // Handle customer taxonomy field
            // The qi_customer field is a taxonomy field for qbo_customers
            // We need to find or create the term and assign it to the post
            if ($cust !== '') {
                $term_id = null;
                
                // Check if term exists (returns array with 'term_id' and 'term_taxonomy_id' or null)
                $existing_term = term_exists($cust, 'qbo_customers');
                if ($existing_term && isset($existing_term['term_id'])) {
                    $term_id = (int)$existing_term['term_id'];
                } else {
                    // Create the term if it doesn't exist (returns array or WP_Error)
                    $new_term = wp_insert_term($cust, 'qbo_customers');
                    if (!is_wp_error($new_term) && isset($new_term['term_id'])) {
                        $term_id = (int)$new_term['term_id'];
                    }
                }
                
                // Assign the term to the post if we have a valid term_id
                if ($term_id) {
                    // Set the taxonomy term (ACF will handle this properly)
                    wp_set_object_terms($post_id, $term_id, 'qbo_customers');
                    // Also update the ACF field with term ID (ACF will return it as object based on return_format)
                    $set('qi_customer', $term_id);
                }
            }

            self::set_wo_number_from_memo($post_id, $memo);

            if ($txn!=='') $set('qi_invoice_date', self::format_date_for_meta($txn,$date_format));
            if ($due!=='') $set('qi_due_date', self::format_date_for_meta($due,$date_format));

            if ($total!==null) $set('qi_total_billed',$total);
            if ($balance!==null) $set('qi_balance_due',$balance);

            $paid_calc = null; $status = null;
            if ($total!==null && $balance!==null) {
                $paid_calc = max(0, round($total - $balance,2));
                $status = $balance>0 ? 'Unpaid' : 'Paid';
            } elseif ($paid_csv!==null && $total!==null) {
                $paid_calc = $paid_csv;
                $status = ($paid_csv >= $total && $total>0)?'Paid':'Unpaid';
            } elseif ($paid_csv!==null) {
                $paid_calc = $paid_csv;
                $status = $paid_csv>0 ? 'Paid':'Unpaid';
            }
            if ($paid_calc!==null) $set('qi_total_paid',$paid_calc);
            if ($status!==null)    $set('qi_payment_status',$status);

            if ($po!=='') $set('qi_purchase_order',$po);
            $set('qi_terms', $terms!=='' ? $terms : $default_terms);

            // Save single row to repeater -- NO date!
            $row = [
                $subkeys['activity']    => !empty($activity) ? $activity : 'Labor Rate HR',
                $subkeys['description'] => !empty($desc) ? $desc : 'import',
                $subkeys['quantity']    => ($qty !== '' ? self::num($qty) : 1),
                $subkeys['rate']        => ($rate !== '' ? self::num($rate) : ($total !== null ? $total : 0)),
                $subkeys['amount']      => ($total !== null ? $total : 0),
            ];
            DQ_Logger::info('qi_invoice row going into update_field', $row);

            update_field('qi_invoice', [ $row ], $post_id);

            $raw_meta = get_post_meta($post_id, 'qi_invoice', true);
            $acf_value = function_exists('get_field') ? get_field('qi_invoice', $post_id) : null;
            DQ_Logger::info('qi_invoice meta after import', [
                'raw_meta' => $raw_meta,
                'acf_value' => $acf_value
            ]);

            $is_update ? $updated++ : $created++;
        }
        fclose($fh);

        $out = '<div class="notice notice-success"><p>Import complete. Created: '.intval($created).', Updated: '.intval($updated).', Skipped: '.intval($skipped).', Errors: '.intval($errors).'.</p></div>';
        if ( $msgs ) {
            $out .= '<div class="notice notice-info"><ul style="margin-left:20px;list-style:disc;">';
            foreach($msgs as $m) $out.='<li>'.esc_html($m).'</li>';
            $out .= '</ul></div>';
        }
        return $out;
    }

    // CORRECTED: Handles comma, space, and semicolon separation robustly for WO tokens
    private static function set_wo_number_from_memo( int $post_id, string $memo ) {
        // Parse comma/semicolon/whitespace separated tokens
        $tokens = [];
        if ($memo !== '') {
            $memo_norm = preg_replace('/[;,]/', ' ', $memo);
            $raw = preg_split('/\s+/', $memo_norm);
            foreach ($raw as $t) {
                $t = trim($t, " \t\n\r\0\x0B,;");
                if ($t === '') continue;
                if (preg_match('/^wo-/i', $t)) {
                    $t = 'WO-' . substr($t, 3);
                } elseif (preg_match('/^02\d{6}$/', $t)) {
                    $t = 'WO-' . $t;
                } elseif (preg_match('/^wo-02\d{6}$/i', $t)) {
                    $t = 'WO-' . substr($t, 3);
                }
                $tokens[] = $t;
            }
        }

        // Resolve to Work Order post IDs (by title exactly)
        $ids = [];
        $unmatched = [];
        foreach ($tokens as $title) {
            $p = get_page_by_title($title, OBJECT, 'workorder');
            if ($p instanceof WP_Post) {
                $ids[] = (int) $p->ID;
            } else {
                $unmatched[] = $title;
            }
        }

        // Save to Post Object field as multiple values
        $field_obj = function_exists('get_field_object') ? get_field_object('qi_wo_number', $post_id) : null;
        $is_post_obj = is_array($field_obj) && in_array($field_obj['type'], ['post_object','relationship'], true);
        if ($is_post_obj) {
            if (!empty($ids)) {
                update_field($field_obj['key'], $ids, $post_id);
            } else {
                update_field($field_obj['key'], null, $post_id);
            }
        } else {
            // Fallback: store matched tokens/IDs as array
            update_field('qi_wo_number', $ids, $post_id);
        }

        if ($unmatched) {
            DQ_Logger::info('Unmatched WO tokens (CSV import)', [
                'post_id'=>$post_id,
                'docnumber'=>get_field('qi_invoice_no',$post_id),
                'tokens'=>$unmatched
            ]);
        }
    }
    private static function build_header_map( array $headers ) : array {
        $map = [];
        foreach ($headers as $i=>$h) {
            $key = strtolower(trim((string)$h));
            $key = preg_replace('/\s+/',' ',$key);
            $map[$i]=$key;
        }
        return $map;
    }

    private static function row_to_assoc( array $map, array $row ) : array {
        $out = [];
        foreach ($map as $i=>$key) {
            $out[$key] = isset($row[$i]) ? trim((string)$row[$i]) : '';
        }
        return $out;
    }

    private static function val( array $data, array $candidates ) : string {
        foreach ($candidates as $c) {
            if ( isset($data[$c]) && $data[$c] !== '' ) return trim((string)$data[$c]);
        }
        return '';
    }

    private static function num( $v ) {
        if ($v==='' || $v===null) return null;
        if ( is_numeric($v) ) return (float)$v;
        $clean = preg_replace('/[^0-9.\-]/','',(string)$v);
        if ($clean==='' || !is_numeric($clean)) return null;
        return (float)$clean;
    }

    private static function parse_date( string $val, string $format ) : string {
        $val = trim($val);
        if ($val==='') return current_time('mysql');
        
        // Parse date in site timezone and convert to MySQL datetime format
        $dt = DateTime::createFromFormat($format, $val, wp_timezone());
        if ($dt instanceof DateTime) {
            // Convert to GMT for storage
            return get_gmt_from_date($dt->format('Y-m-d H:i:s'));
        }
        
        // Fallback to strtotime with timezone awareness
        $ts = strtotime($val);
        if ($ts) {
            $local_date = date('Y-m-d H:i:s', $ts);
            return get_gmt_from_date($local_date);
        }
        
        return current_time('mysql', true); // Return GMT time
    }

    private static function format_date_for_meta( string $val, string $import_format ) : string {
        // Use the centralized timezone-aware helper function
        return dqqb_normalize_date_for_storage( $val, $import_format );
    }

    private static function find_existing_invoice_post( string $docnumber ) {
        $q = new WP_Query([
            'post_type'=>'quickbooks_invoice',
            'post_status'=>'any',
            'posts_per_page'=>1,
            'fields'=>'ids',
            'meta_query'=>[[ 'key'=>'qi_invoice_no','value'=>$docnumber ]]
        ]);
        if ( $q->have_posts() ) return (int)$q->posts[0];

        $q2 = new WP_Query([
            'post_type'=>'quickbooks_invoice',
            'post_status'=>'any',
            'posts_per_page'=>1,
            'fields'=>'ids',
            'title'=>$docnumber,
            's'=>$docnumber,
        ]);
        if ( $q2->have_posts() ) {
            foreach ( $q2->posts as $pid ) {
                $p = get_post($pid);
                if ( $p && $p->post_title === $docnumber ) return (int)$pid;
            }
        }
        return 0;
    }
}

if ( is_admin() ) DQ_QI_CSV_Import::init();