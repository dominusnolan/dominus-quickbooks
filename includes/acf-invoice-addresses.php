<?php
/**
 * Map QuickBooks Invoice BillAddr/ShipAddr into ACF fields.
 * - ACF: wo_bill_to, wo_ship_to
 * - Falls back to update_post_meta if ACF isn't loaded.
 *
 * Usage:
 *   dominus_qb_update_acf_bill_ship($post_id, $invoice);
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
     * Update ACF (or post meta fallback) with Bill To / Ship To from QuickBooks Invoice.
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
}
