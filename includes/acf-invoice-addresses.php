<?php
/**
 * Map QuickBooks Invoice BillAddr/ShipAddr into ACF fields.
 * - ACF: wo_bill_to, wo_ship_to (workorder)
 * - ACF: qi_bill_to, qi_ship_to (quickbooks_invoice CPT)
 * - Falls back to update_post_meta if ACF isn't loaded.
 *
 * Usage:
 *   dominus_qb_update_acf_bill_ship($post_id, $invoice);     // workorder
 *   dominus_qb_update_acf_bill_ship_qi($post_id, $invoice);  // quickbooks_invoice
 */

if (!function_exists('dominus_qb_update_acf_bill_ship')) {

    /**
     * Format a QuickBooks Address object (BillAddr/ShipAddr) to a human-friendly string.
     *
     * @param object|array|null $addr  QuickBooks address (has Line1/Line2/Line3/City/CountrySubDivisionCode/PostalCode/Country)
     * @return string
     */
    function dominus_qb_format_qb_address($addr) {
        if (!$addr) return '';

        // Normalize to array for convenience
        $a = is_array($addr) ? $addr : (array) $addr;

        $lines = [];

        // Up to 3 address lines are typical in QB
        foreach (['Line1', 'Line2', 'Line3'] as $k) {
            if (!empty($a[$k])) $lines[] = trim($a[$k]);
        }

        // City, State/Province, PostalCode
        $cityParts = [];
        if (!empty($a['City'])) $cityParts[] = trim($a['City']);
        if (!empty($a['CountrySubDivisionCode'])) $cityParts[] = trim($a['CountrySubDivisionCode']);
        if (!empty($a['PostalCode'])) $cityParts[] = trim($a['PostalCode']);

        if ($cityParts) $lines[] = implode(', ', $cityParts);

        // Country (optional)
        if (!empty($a['Country'])) $lines[] = trim($a['Country']);

        // Remove empties & duplicates, join as multi-line block
        $lines = array_values(array_unique(array_filter($lines, fn($v) => $v !== null && $v !== '')));

        return implode("\n", $lines);
    }

    /**
     * Parse a human-friendly multiline/string address into a QuickBooks BillAddr/ShipAddr structure.
     * Accepts either:
     * - newline-separated text
     * - single line comma-separated text
     * Attempts to detect "City, ST ZIP" on the last line.
     *
     * @param string $text
     * @return array  Keys: Line1, Line2, Line3, City, CountrySubDivisionCode, PostalCode, Country (where detected)
     */
    function dominus_qb_parse_address_string($text) {
        $addr = [
            'Line1' => '', 'Line2' => '', 'Line3' => '',
            'City'  => '', 'CountrySubDivisionCode' => '', 'PostalCode' => '', 'Country' => '',
        ];
        if (!is_string($text) || $text === '') return array_filter($addr);

        // Normalize to lines
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        if (count($lines) === 1) {
            // try splitting by comma if single line
            $lines = array_map('trim', explode(',', $lines[0]));
        }
        $lines = array_values(array_filter(array_map('trim', $lines), fn($v) => $v !== ''));

        // Assign up to 3 address lines from the top
        foreach ([0,1,2] as $i) {
            if (isset($lines[$i])) {
                $addr['Line' . ($i+1)] = $lines[$i];
            }
        }

        // Try parsing the last line as City/State/Zip
        $tail = end($lines);
        if ($tail) {
            // Typical "City, ST 12345" or "City, State 12345"
            if (preg_match('/^(.+?),\s*([A-Za-z]{2,})\s+([A-Za-z0-9\- ]{3,})$/', $tail, $m)) {
                $addr['City'] = trim($m[1]);
                $addr['CountrySubDivisionCode'] = trim($m[2]);
                $addr['PostalCode'] = trim($m[3]);
                // Remove this from Line3 if we duplicated
                if ($addr['Line3'] === $tail) $addr['Line3'] = '';
            } else {
                // If we can't parse, leave as lines only (QBO will accept just Line1/2/3)
            }
        }

        // Clean empties
        return array_filter($addr, fn($v) => $v !== '' && $v !== null);
    }

    /**
     * Update ACF (or post meta fallback) with Bill To / Ship To from QuickBooks Invoice.
     * Target fields: wo_bill_to / wo_ship_to (Work Order CPT)
     *
     * @param int   $post_id  WordPress post ID that represents this invoice/workorder
     * @param mixed $invoice  QuickBooks Invoice object/array (must include BillAddr, ShipAddr when present)
     * @return void
     */
    function dominus_qb_update_acf_bill_ship($post_id, $invoice) {
        if (!$post_id || !$invoice) return;

        $inv = is_array($invoice) ? $invoice : (array) $invoice;

        $billAddr = $inv['BillAddr'] ?? null;
        $shipAddr = $inv['ShipAddr'] ?? null;

        $billTo = dominus_qb_format_qb_address($billAddr);
        $shipTo = dominus_qb_format_qb_address($shipAddr);

        // Prefer ACF if available, otherwise fall back to post meta.
        $has_acf = function_exists('update_field');

        if ($has_acf) {
            // ACF text/textarea fields named wo_bill_to / wo_ship_to
            update_field('wo_bill_to', $billTo, $post_id);
            update_field('wo_ship_to', $shipTo, $post_id);
        } else {
            update_post_meta($post_id, 'wo_bill_to', $billTo);
            update_post_meta($post_id, 'wo_ship_to', $shipTo);
        }
    }

    /**
     * Update ACF (or post meta fallback) with Bill To / Ship To from QuickBooks Invoice.
     * Target fields: qi_bill_to / qi_ship_to (QuickBooks Invoice CPT)
     *
     * @param int   $post_id
     * @param mixed $invoice
     * @return void
     */
    function dominus_qb_update_acf_bill_ship_qi($post_id, $invoice) {
        if (!$post_id || !$invoice) return;

        $inv = is_array($invoice) ? $invoice : (array) $invoice;

        $billAddr = $inv['BillAddr'] ?? null;
        $shipAddr = $inv['ShipAddr'] ?? null;

        $billTo = dominus_qb_format_qb_address($billAddr);
        $shipTo = dominus_qb_format_qb_address($shipAddr);

        $has_acf = function_exists('update_field');

        if ($has_acf) {
            update_field('qi_bill_to', $billTo, $post_id);
            update_field('qi_ship_to', $shipTo, $post_id);
        } else {
            update_post_meta($post_id, 'qi_bill_to', $billTo);
            update_post_meta($post_id, 'qi_ship_to', $shipTo);
        }
    }
}
