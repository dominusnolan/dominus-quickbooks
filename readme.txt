=== Dominus QuickBooks ===
Contributors: dominus
Tags: quickbooks, quickbooks online, intuit, sandbox, accounting, oauth, api
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

A minimal, secure connector between WordPress and Intuit QuickBooks Online with Sandbox/Production toggle. Implements OAuth 2.0, token refresh, and sample API calls without external libraries.

== Description ==
- Connect to a QuickBooks Online company using OAuth 2.0.
- Choose **Sandbox** or **Production**.
- Securely stores and refreshes tokens.
- Demo action: Fetch Company Info.
- Frontend invoice list with AJAX pagination: `[dqqb_invoice_list]`

== Installation ==
1. Upload `dominus-quickbooks` to `/wp-content/plugins/` and activate.
2. Go to Settings → Dominus QuickBooks.
3. Enter your Intuit Client ID, Client Secret, Redirect URI (must match Intuit app config).
4. Click **Connect to QuickBooks**.

== Frequently Asked Questions ==
= Where do I get Client ID/Secret? =
From your Intuit Developer app. Ensure the Redirect URI matches your WP settings page callback.

= Does this support WooCommerce? =
This is a framework: add order/customer syncs by calling `DQ_API` methods (see `create_customer`).

= How do I display invoices on the frontend? =
Use the shortcode `[dqqb_invoice_list]` on any page or post. It supports filters: `status="paid"`, `date_from="2024-01-01"`, `date_to="2024-12-31"`. See SHORTCODE_DOCUMENTATION.md for details.

== Changelog ==
= 0.1.0 =
* Initial release.
```

---

# Extending for WooCommerce (quick sketch)

* Hook into `woocommerce_thankyou` to push completed orders to QBO.
* Map WooCommerce order fields → QBO SalesReceipt/Invoice payloads.
* Use `DQ_API::create_customer()` as a pattern for POST endpoints.

Example hook stub (add to a new file or `class-dq-api.php`):

```php
add_action( 'woocommerce_thankyou', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) { return; }

    // 1) Ensure customer exists in QBO (by email, create if missing)
    // 2) Build QBO SalesReceipt/Invoice payload from $order items, totals, tax, etc.
    // 3) POST to QBO via DQ_API.
}, 10 );
```

# Security & Notes

* Tokens are stored in a single option `dq_settings` (autoload=no). For multi‑site or stricter storage, swap to a dedicated table or encrypted storage.
* Always keep your Client Secret safe; restrict admin access.
* Intuit scopes can be extended (e.g., `openid`, `profile`) if you intend to use identity endpoints.
* The `minorversion` parameter (`73` here) can be updated per the latest QBO API docs.
