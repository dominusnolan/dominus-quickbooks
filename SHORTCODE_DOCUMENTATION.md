# Dominus QuickBooks Shortcodes

## Work Order Table Shortcode

### Basic Usage

Display a paginated table of work orders on any page or post:

```
[workorder_table]
```

### Features

- **Configurable Pagination**: Control rows per page with `per_page` attribute (default: 10)
- **AJAX Navigation**: Page changes happen without full page reload
- **Responsive Design**: Works on desktop, tablet, and mobile devices
- **Smooth Scrolling**: Automatically scrolls to top of table after page change
- **Loading State**: Visual feedback while fetching new page
- **Styled Table**: Teal headers, alternating row colors, grouped cells, clean spacing

### Shortcode Attributes

#### Control Rows Per Page

Show 20 work orders per page:
```
[workorder_table per_page="20"]
```

Show 5 work orders per page:
```
[workorder_table per_page="5"]
```

#### Filter by Status

Show only open work orders:
```
[workorder_table status="open"]
```

Show only closed work orders:
```
[workorder_table status="close"]
```

Show only scheduled work orders:
```
[workorder_table status="scheduled"]
```

#### Filter by State

Show work orders from a specific state:
```
[workorder_table state="California"]
```

#### Combined Filters

You can combine multiple filters:
```
[workorder_table per_page="15" status="open" state="Texas"]
```

### Display Format

The work order table displays the following information:

- **WO ID**: Work order number with link to individual work order page
- **Customer**: Customer name (bold styling)
- **Location**: Combined address, city, and state
- **Service Details**: Grouped cell showing:
  - Service Type
  - Equipment
  - Serial Number
- **Dates**: Grouped cell showing:
  - Date Requested
  - Scheduled Date
  - Closed Date
- **Status**: Visual badge with color-coded status (Open, Scheduled, Closed)

### Technical Notes

- Requires the `workorder` custom post type to be registered
- Uses ACF fields (or falls back to post meta):
  - `wo_number`: Work order number
  - `wo_customer`: Customer name
  - `wo_address`: Street address
  - `wo_city`: City
  - `wo_state`: State
  - `service_type`: Type of service
  - `equipment`: Equipment name
  - `serial_number`: Equipment serial number
  - `date_requested_by_customer`: Requested date
  - `schedule_date_time`: Scheduled date
  - `closed_on`: Closed date
- Works for logged-in and non-logged-in users
- AJAX requests are secured with WordPress nonces
- Server-side pagination for efficient handling of large datasets

### Styling

The shortcode includes built-in styling with:
- Teal (#0996a0) header background
- Alternating row colors for better readability
- Hover effects on rows
- Responsive table with horizontal scroll on mobile
- Color-coded status badges

You can override styles by targeting these CSS classes:

- `.dq-workorder-table-wrapper`: Main container
- `.dq-workorder-table`: Table element
- `.dq-workorder-table-pagination`: Pagination controls
- `.dq-workorder-status`: Status badges
- `.wo-id-cell`: Work order ID column
- `.wo-customer-cell`: Customer name column
- `.wo-grouped-cell`: Grouped content cells (service details, dates)

### Examples

**Show all work orders with default 10 per page:**
```
[workorder_table]
```

**Show 25 work orders per page:**
```
[workorder_table per_page="25"]
```

**Show open work orders, 15 per page:**
```
[workorder_table per_page="15" status="open"]
```

**Show work orders from California:**
```
[workorder_table state="California"]
```

---

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

---

## Work Order Table Shortcode

### Basic Usage

Display a table listing all Work Orders on any page or post:

```
[workorder_table]
```

### Features

- **Complete Overview**: Displays all work orders in a comprehensive table
- **15 Column Layout**: Shows all important work order information at a glance
- **Responsive Design**: Horizontal scrolling on smaller screens
- **View Links**: Direct links to individual work order pages
- **Status Badges**: Visual status indicators for each work order
- **Date Calculations**: Automatic calculation of FSC contact days

### Display Format

The work order table displays the following columns:

| Column | Description |
|--------|-------------|
| **Work Order ID** | The post title of the work order |
| **Location** | Account (`wo_location`), City (`wo_city`), State (`wo_state`) |
| **Field Engineer** | The author's display name |
| **Product ID** | Installed product ID (`installed_product_id`) |
| **Customer Info** | Name (`wo_contact_name`), Address (`wo_contact_address`), Email (`wo_contact_email`), Number (`wo_service_contact_number`) |
| **Date Received** | Date requested by customer (`date_requested_by_customer`) |
| **FSC Contact Date** | FSC contact date (`wo_fsc_contact_date`) |
| **FSC Contact Days** | Calculated: FSC Contact Date - Date Received (displayed as "X days") |
| **Scheduled Date** | Scheduled date and time (`schedule_date_time`) |
| **Service Completed** | Date service completed by FSE (`date_service_completed_by_fse`) |
| **Closed On** | Date closed (`closed_on`) |
| **Reports Sent** | Date FSR and DIA reports sent to customer (`date_fsr_and_dia_reports_sent_to_customer`) |
| **Leads** | Lead (`wo_leads`) and Category (`wo_lead_category`) |
| **Status** | Status taxonomy term (excludes "Uncategorized") |
| **View** | Button linking to the single work order page |

### Technical Notes

- Requires the `workorder` custom post type to be registered
- Uses ACF fields (or falls back to post meta) for all custom fields
- Queries work orders with status: publish, draft, pending, or private
- All dates are formatted as MM/DD/YYYY
- Works for logged-in and non-logged-in users

### Styling

The shortcode includes built-in styling. You can override styles by targeting these CSS classes:

- `.workorder-table-wrapper`: Main container with horizontal scroll
- `.workorder-table`: Table element
- `.wo-location-cell`: Location column content
- `.wo-customer-cell`: Customer info column content
- `.wo-leads-cell`: Leads column content
- `.wo-status-badge`: Status badge styling
- `.wo-view-btn`: View button styling

### Example

**Display all work orders:**
```
[workorder_table]
```
