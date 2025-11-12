<?php
/**
 * CSV → QuickBooks Sandbox Invoice Importer
 *
 * Usage (WP-CLI):
 *   wp dqqb import-csv --file=/absolute/path/to/invoices.csv [--delimiter=,] [--date_format=Y-m-d] [--dry-run]
 *
 * Expected CSV headers (case/spacing-insensitive, synonyms allowed):
 *   InvoiceNo | DocNumber, Customer, TxnDate, DueDate, Description, TotalAmt | Total, Balance, Paid | PaymentDate | PaymentRef
 *
 * Behavior:
 *   • Creates a summary-only Invoice per unique DocNumber with a single line item "Imported Services" = TotalAmt
 *   • If Paid > 0, creates a Payment linked to the Invoice (supports partial payments)
 *   • Leaves Invoice open for Balance > 0
 *
 * Notes:
 *   • Requires a wrapper `DQ_API` with methods: query($sql), create($entity, $payload)
 *   • Will auto-create Customer (DisplayName) if missing
 *   • Will ensure an Item named "Imported Services" exists (Type=Service); picks the first Income account found
 */
if (!defined('ABSPATH')) { exit; }

class DQ_CSV_InvoiceImporter {
    public static function run($csv_path, $opts = []) {
        $defaults = [
            'delimiter'   => null,          // auto-detect if null
            'date_format' => 'Y-m-d',
            'dry_run'     => false,
        ];
        $o = array_merge($defaults, $opts);

        if (!is_readable($csv_path)) {
            return new WP_Error('dq_csv_missing', 'CSV file not readable: '.$csv_path);
        }

        $fh = fopen($csv_path, 'r');
        if (!$fh) {
            return new WP_Error('dq_csv_open', 'Unable to open CSV file.');
        }

        // Read header
        $first_line = fgets($fh);
        if ($first_line === false) {
            fclose($fh);
            return new WP_Error('dq_csv_empty', 'CSV appears empty.');
        }

        // Delimiter detection if not supplied
        $delimiter = $o['delimiter'] ?: self::detect_delimiter($first_line);
        $headers   = str_getcsv($first_line, $delimiter);
        $map       = self::header_map($headers);

        // Required minimal columns
        $required = ['docnumber','customer','totalamt'];
        foreach ($required as $col) {
            if (!isset($map[$col])) {
                fclose($fh);
                return new WP_Error('dq_csv_headers', "Missing required column: $col (need DocNumber, Customer, TotalAmt)");
            }
        }

        // Rewind to begin of rows
        fseek($fh, 0);
        // Use fgetcsv now that we know delimiter
        $header_row = fgetcsv($fh, 0, $delimiter);

        $rows = [];
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            if (count($row) == 1 && trim(implode('', $row)) === '') { continue; }
            $assoc = self::assoc_row($header_row, $row);
            $rows[] = $assoc;
        }
        fclose($fh);

        // Group by Invoice number
        $groups = self::group_by_invoice($rows);

        $invoices_created = 0;
        $payments_created = 0;
        $skipped          = 0;
        $errors           = [];

        // Ensure we have the default line item
        $services_item_id = $o['dry_run'] ? null : self::ensure_services_item();
        if (is_wp_error($services_item_id)) return $services_item_id;

        foreach ($groups as $docnum => $g) {
            // Normalize fields
            $customer_name = self::pick($g, ['customer']);
            $total         = self::to_amount(self::pick($g, ['totalamt','total']));
            $balance       = self::to_amount(self::pick($g, ['balance']));
            $paid          = self::to_amount(self::pick($g, ['paid']));
            $txn_date      = self::normalize_date(self::pick($g, ['txndate']), $o['date_format']);
            $due_date      = self::normalize_date(self::pick($g, ['duedate']), $o['date_format']);
            $payment_date  = self::normalize_date(self::pick($g, ['paymentdate']), $o['date_format']);
            $memo          = self::pick($g, ['description','memo','note']);

            if ($paid === null && $total !== null && $balance !== null) {
                $paid = max(round(($total - $balance), 2), 0.0);
            }

            // Basic validations
            if (!$customer_name || $total === null) {
                $skipped++; $errors[] = "Invoice $docnum skipped: missing customer or total"; continue;
            }

            if ($o['dry_run']) {
                // Just count what WOULD happen
                $invoices_created++;
                if ($paid > 0) { $payments_created++; }
                continue;
            }

            // Ensure Customer
            $cust_id = self::ensure_customer($customer_name);
            if (is_wp_error($cust_id)) { $skipped++; $errors[] = "Invoice $docnum: ".$cust_id->get_error_message(); continue; }

            // Build Invoice payload (summary line)
            $line_desc = $memo ?: 'Imported from CSV';
            $invoice_payload = [
                'DocNumber'    => (string)$docnum,
                'TxnDate'      => $txn_date ?: date('Y-m-d'),
                'DueDate'      => $due_date ?: null,
                'CustomerRef'  => ['value' => (string)$cust_id],
                'PrivateNote'  => 'Imported via DQ CSV on '.current_time('mysql'),
                'Line' => [
                    [
                        'DetailType' => 'SalesItemLineDetail',
                        'Amount'     => (float)$total,
                        'Description'=> $line_desc,
                        'SalesItemLineDetail' => [
                            'ItemRef' => ['value' => (string)$services_item_id, 'name' => 'Imported Services']
                        ],
                    ],
                ],
            ];

            $inv_resp = DQ_API::create_invoice($invoice_payload);
            if (is_wp_error($inv_resp)) { $skipped++; $errors[] = "Invoice $docnum: ".$inv_resp->get_error_message(); continue; }

            $invoice_id = $inv_resp['Invoice']['Id'] ?? null;
            if (!$invoice_id) { $skipped++; $errors[] = "Invoice $docnum: missing Id in response"; continue; }
            $invoices_created++;

            // Create Payment if any
            if ($paid && $paid > 0) {
                $p_txn_date = $payment_date ?: ($txn_date ?: date('Y-m-d'));
                $payment_payload = [
                    'CustomerRef' => ['value' => (string)$cust_id],
                    'TotalAmt'    => (float)$paid,
                    'TxnDate'     => $p_txn_date,
                    'PrivateNote' => 'Imported via DQ CSV',
                    'Line' => [
                        [
                            'Amount'    => (float)$paid,
                            'LinkedTxn' => [ [ 'TxnId' => (string)$invoice_id, 'TxnType' => 'Invoice' ] ],
                        ],
                    ],
                ];
                $pay_resp = DQ_API::post('payment', $payment_payload, 'Create Payment');
                if (is_wp_error($pay_resp)) {
                    // Don't fail the whole import; note the error
                    $errors[] = "Payment for $docnum: ".$pay_resp->get_error_message();
                } else {
                    $payments_created++;
                }
            }
        }

        return [
            'invoices_created' => $invoices_created,
            'payments_created' => $payments_created,
            'skipped'          => $skipped,
            'errors'           => $errors,
        ];
    }

    // -------------- Helpers -------------- //

    private static function detect_delimiter($line) {
        $candidates = [',', '\t', ';', '|'];
        $best = ','; $best_count = 0;
        foreach ($candidates as $d) {
            $cnt = substr_count($line, $d);
            if ($cnt > $best_count) { $best = $d; $best_count = $cnt; }
        }
        return $best;
    }

    private static function header_map($headers) {
        $map = [];
        foreach ($headers as $i => $h) {
            $k = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', $h)));
            // synonyms
            if ($k === 'invoiceno') $k = 'docnumber';
            if ($k === 'docnum')    $k = 'docnumber';
            if ($k === 'total')     $k = 'totalamt';
            $map[$k] = $i;
        }
        return $map;
    }

    private static function assoc_row($header_row, $row) {
        $assoc = [];
        foreach ($header_row as $i => $h) {
            $k = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', $h)));
            if ($k === 'invoiceno') $k = 'docnumber';
            if ($k === 'docnum')    $k = 'docnumber';
            if ($k === 'total')     $k = 'totalamt';
            $assoc[$k] = isset($row[$i]) ? trim($row[$i]) : '';
        }
        return $assoc;
    }

    private static function group_by_invoice($rows) {
        $groups = [];
        foreach ($rows as $r) {
            $doc = $r['docnumber'] ?? '';
            if ($doc === '') { $doc = 'NO-DOC-'.md5(json_encode($r)); }
            if (!isset($groups[$doc])) { $groups[$doc] = []; }
            $groups[$doc][] = $r;
        }
        // Collapse to one representative row + merged notes
        foreach ($groups as $doc => $arr) {
            $first = $arr[0];
            // Merge descriptions if multiple
            $descs = [];
            foreach ($arr as $a) {
                if (!empty($a['description'])) $descs[] = $a['description'];
            }
            if ($descs) { $first['description'] = implode(' | ', array_unique($descs)); }
            $groups[$doc] = $first;
        }
        return $groups;
    }

    private static function pick($assoc, $keys) {
        foreach ($keys as $k) {
            if (isset($assoc[$k]) && $assoc[$k] !== '') return $assoc[$k];
        }
        return null;
    }

    private static function to_amount($val) {
        if ($val === null || $val === '') return null;
        // Strip currency symbols and thousands
        $v = preg_replace('/[^0-9\-\.,]/', '', (string)$val);
        // Convert commas if used as thousands
        if (substr_count($v, ',') && substr_count($v, '.') === 1) {
            $v = str_replace(',', '', $v);
        } else if (substr_count($v, ',') === 1 && substr_count($v, '.') === 0) {
            // European decimal
            $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '', $v);
        }
        return (float) $v;
    }

    private static function normalize_date($val, $format) {
        if (!$val) return null;
        $val = trim($val);
        // If already YYYY-MM-DD, trust it
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
        $ts = strtotime($val);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }

    private static function ensure_customer($display_name) {
        $name = trim($display_name);
        $sql  = "SELECT Id FROM Customer WHERE DisplayName = '".addslashes($name)."' STARTPOSITION 1 MAXRESULTS 1";
        $resp = DQ_API::query($sql);
        if (is_wp_error($resp)) return $resp;
        $arr = $resp['QueryResponse']['Customer'] ?? [];
        if (!empty($arr)) return $arr[0]['Id'];
        $payload = [ 'DisplayName' => $name ];
        $c = DQ_API::create_customer($payload);
        if (is_wp_error($c)) return $c;
        return $c['Customer']['Id'] ?? new WP_Error('dq_no_customer_id', 'Customer create ok but Id missing');
    }

    private static function ensure_services_item() {
        // Try to find existing item
        $resp = DQ_API::query("SELECT Id, Name FROM Item WHERE Name = 'Imported Services' STARTPOSITION 1 MAXRESULTS 1");
        if (is_wp_error($resp)) return $resp;
        $arr = $resp['QueryResponse']['Item'] ?? [];
        if (!empty($arr)) return $arr[0]['Id'];

        // Need an income account
        $acc = self::pick_income_account();
        if (is_wp_error($acc)) return $acc;
        $acc_id = $acc['Id'];

        $payload = [
            'Name'  => 'Imported Services',
            'Type'  => 'Service',
            'IncomeAccountRef' => ['value' => (string)$acc_id],
            'Taxable' => false,
        ];
        $it = DQ_API::post('item', $payload, 'Create Item');
        if (is_wp_error($it)) return $it;
        return $it['Item']['Id'] ?? new WP_Error('dq_no_item_id', 'Item create ok but Id missing');
    }

    private static function pick_income_account() {
        // Prefer a common one; otherwise pick the first Income account available
        $try = [
            "SELECT Id, Name FROM Account WHERE AccountType = 'Income' AND Name = 'Sales of Product Income' STARTPOSITION 1 MAXRESULTS 1",
            "SELECT Id, Name FROM Account WHERE AccountType = 'Income' STARTPOSITION 1 MAXRESULTS 1",
        ];
        foreach ($try as $sql) {
            $resp = DQ_API::query($sql);
            if (is_wp_error($resp)) return $resp;
            $arr = $resp['QueryResponse']['Account'] ?? [];
            if (!empty($arr)) return $arr[0];
        }
        return new WP_Error('dq_income_acct', 'No Income account found');
    }
}

// ---- WP-CLI integration ---- //
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('dqqb import-csv', function($args, $assoc){
        $file = $assoc['file'] ?? null;
        if (!$file) { WP_CLI::error('--file is required'); }
        $opts = [
            'delimiter'   => $assoc['delimiter'] ?? null,
            'date_format' => $assoc['date_format'] ?? 'Y-m-d',
            'dry_run'     => isset($assoc['dry-run']),
        ];
        $res = DQ_CSV_InvoiceImporter::run($file, $opts);
        if (is_wp_error($res)) {
            WP_CLI::error($res->get_error_message());
        } else {
            $msg = sprintf(
                'OK%s: %d invoice(s), %d payment(s), %d skipped',
                $opts['dry_run'] ? ' (dry run)' : '',
                $res['invoices_created'], $res['payments_created'], $res['skipped']
            );
            WP_CLI::success($msg);
            if (!empty($res['errors'])) {
                foreach ($res['errors'] as $e) { WP_CLI::log('  - '.$e); }
            }
        }
    });
}
