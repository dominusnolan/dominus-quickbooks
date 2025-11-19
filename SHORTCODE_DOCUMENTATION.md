# Dominus QuickBooks Shortcodes

## Invoice List Shortcode

### Basic Usage

Display a paginated list of QuickBooks invoices on any page or post:

```
[dqqb_invoice_list]
```

### Features

- **Pagination**: Displays 25 invoices per page
- **AJAX Navigation**: Page changes happen without full page reload
- **Responsive Design**: Works on desktop, tablet, and mobile devices
- **Smooth Scrolling**: Automatically scrolls to top of list after page change
- **Loading State**: Visual feedback while fetching new page

### Shortcode Attributes

#### Filter by Payment Status

Show only paid invoices:
```
[dqqb_invoice_list status="paid"]
```

Show only unpaid invoices:
```
[dqqb_invoice_list status="unpaid"]
```

#### Filter by Date Range

Show invoices from a specific date onwards:
```
[dqqb_invoice_list date_from="2024-01-01"]
```

Show invoices up to a specific date:
```
[dqqb_invoice_list date_to="2024-12-31"]
```

Show invoices within a date range:
```
[dqqb_invoice_list date_from="2024-01-01" date_to="2024-12-31"]
```

#### Combined Filters

You can combine multiple filters:
```
[dqqb_invoice_list status="paid" date_from="2024-01-01" date_to="2024-12-31"]
```

### Display Format

The invoice list displays the following information in a table:

- **Invoice #**: Links to the individual invoice page
- **Date**: Invoice date in MM/DD/YYYY format
- **Customer**: Customer name from related work order
- **Amount**: Total billed amount with currency formatting
- **Status**: Visual badge showing "Paid" or "Unpaid"

### Technical Notes

- Requires the `quickbooks_invoice` custom post type to be registered
- Uses ACF fields (or falls back to post meta):
  - `qi_invoice_no`: Invoice number
  - `qi_invoice_date`: Invoice date
  - `qi_total_billed`: Total amount billed
  - `qi_payment_status`: Payment status (paid/unpaid)
  - `qi_wo_number`: Related work order (for customer name)
- Works for logged-in and non-logged-in users
- AJAX requests are secured with WordPress nonces

### Styling

The shortcode includes built-in styling that matches the plugin's design. You can override styles by targeting these CSS classes:

- `.dq-invoice-list-wrapper`: Main container
- `.dq-invoice-list-table`: Table element
- `.dq-invoice-list-pagination`: Pagination controls
- `.dq-invoice-list-status`: Status badges

### Examples

**Show all invoices:**
```
[dqqb_invoice_list]
```

**Show only unpaid invoices from the current year:**
```
[dqqb_invoice_list status="unpaid" date_from="2024-01-01"]
```

**Show paid invoices from Q1 2024:**
```
[dqqb_invoice_list status="paid" date_from="2024-01-01" date_to="2024-03-31"]
```
