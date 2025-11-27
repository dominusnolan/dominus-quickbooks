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

- **Pagination**: Displays 25 invoices per page (configurable with `per_page` attribute)
- **AJAX Navigation**: Page changes happen without full page reload
- **Responsive Design**: Works on desktop, tablet, and mobile devices
- **Smooth Scrolling**: Automatically scrolls to top of list after page change
- **Loading State**: Visual feedback while fetching new page
- **Filter UI**: Built-in filter bar with status, date type, and date range filters
- **Days Remaining**: Calculates days until due date for unpaid invoices

### Shortcode Attributes

| Attribute | Values | Default | Description |
|-----------|--------|---------|-------------|
| `status` | `paid`, `unpaid`, or empty | empty (all) | Filter by payment status |
| `unpaid_only` | `true` or empty | empty | When `true`, only show invoices with `qi_balance_due > 0` regardless of `qi_payment_status`. Hides the status dropdown in the filter UI. |
| `date_type` | `qi_invoice_date`, `qi_due_date` | `qi_invoice_date` | Which date field to filter on |
| `date_from` | `yyyy-mm-dd` | empty | Start date for date range filter |
| `date_to` | `yyyy-mm-dd` | empty | End date for date range filter |
| `per_page` | integer (1-100) | 25 | Number of invoices per page |

#### Filter by Payment Status

Show only paid invoices:
```
[dqqb_invoice_list status="paid"]
```

Show only unpaid invoices:
```
[dqqb_invoice_list status="unpaid"]
```

#### Show Only Invoices with Positive Balance

The `unpaid_only` attribute filters invoices by their numeric balance (`qi_balance_due > 0`) rather than the `qi_payment_status` meta field. This is useful when you want to show all invoices that still have an outstanding balance regardless of their payment status label.

```
[dqqb_invoice_list unpaid_only="true"]
```

When `unpaid_only="true"` is set:
- Only invoices with `qi_balance_due > 0` are displayed
- The status dropdown is hidden from the filter UI
- The `status` attribute is ignored

#### Alternative Shortcode for Unpaid Invoices

You can also use the dedicated shortcode for unpaid invoices:
```
[dqqb_unpaid_invoices]
```
This is equivalent to `[dqqb_invoice_list unpaid_only="true"]`.

#### Filter by Date Type

Choose which date field to filter on:
```
[dqqb_invoice_list date_type="qi_due_date"]
```

This affects both the initial filter and the date range filter in the UI. Options are:
- `qi_invoice_date` (default) - Filter by invoice date
- `qi_due_date` - Filter by due date

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

#### Control Pagination

Show 10 invoices per page:
```
[dqqb_invoice_list per_page="10"]
```

Show 50 invoices per page:
```
[dqqb_invoice_list per_page="50"]
```

#### Combined Filters

You can combine multiple filters:
```
[dqqb_invoice_list status="paid" date_from="2024-01-01" date_to="2024-12-31"]
```

Show unpaid invoices with balance, filtered by due date:
```
[dqqb_invoice_list unpaid_only="true" date_type="qi_due_date" date_from="2024-01-01"]
```

### Display Format

The invoice list displays the following columns:

| Column | Description |
|--------|-------------|
| **Invoice #** | Invoice number (`qi_invoice_no`) - links to the individual invoice page |
| **Workorder ID** | Related work order number (`qi_wo_number`) |
| **Amount** | Total billed amount (`qi_total_billed`) with currency formatting |
| **QBO Invoice** | Multiline cell showing: Billed, Balance, Paid, Terms, and Status (UNPAID/PAID badge) |
| **Customer** | Customer info showing: Customer (`qi_customer`), Bill to (`qi_bill_to`), Ship to (`qi_ship_to`) |
| **Invoice Date** | Invoice date (`qi_invoice_date`) in MM/DD/YYYY format |
| **Due Date** | Due date (`qi_due_date`) in MM/DD/YYYY format |
| **Days Remaining** | Calculated as (due_date - today) in whole days. Negative if past due. Only shown for unpaid invoices. |

### Filter UI

The shortcode includes a filter bar above the table with:
- **Status Select**: All / Paid / Unpaid (hidden when `unpaid_only="true"`)
- **Date Type Select**: Invoice Date / Due Date
- **Date From/To**: Date inputs for filtering by date range
- **Filter Button**: Apply button to refresh the table

Filtering works via AJAX and updates the table without reloading the page.

### Technical Notes

- Requires the `quickbooks_invoice` custom post type to be registered
- Uses ACF fields (or falls back to post meta):
  - `qi_invoice_no`: Invoice number
  - `qi_invoice_date`: Invoice date
  - `qi_due_date`: Due date
  - `qi_total_billed`: Total amount billed
  - `qi_balance_due`: Balance due amount
  - `qi_total_paid`: Total amount paid
  - `qi_terms`: Payment terms
  - `qi_payment_status`: Payment status (paid/unpaid)
  - `qi_customer`: Customer name
  - `qi_bill_to`: Bill to address
  - `qi_ship_to`: Ship to address
  - `qi_wo_number`: Related work order
- Works for logged-in and non-logged-in users
- AJAX requests are secured with WordPress nonces
- When `unpaid_only="true"`, filtering is based on `qi_balance_due > 0` (numeric comparison)

### Styling

The shortcode includes built-in styling with:
- Teal (#006d7b) header background
- Alternating row colors for better readability
- Hover effects on rows
- Color-coded status badges (green for PAID, yellow for UNPAID)
- Responsive table with horizontal scroll on mobile

You can override styles by targeting these CSS classes:

- `.dq-invoice-list-wrapper`: Main container
- `.dq-invoice-list-table`: Table element
- `.dq-invoice-list-table th`: Table headers
- `.dq-invoice-list-table td`: Table cells
- `.dq-invoice-list-filter`: Filter form container
- `.dq-invoice-list-pagination`: Pagination controls
- `.dq-invoice-list-status`: Status badges
- `.dq-invoice-list-status.paid`: Paid status badge
- `.dq-invoice-list-status.unpaid`: Unpaid status badge
- `.dq-invoice-list-qbo`: QBO Invoice cell content
- `.dq-invoice-list-customer`: Customer cell content
- `.dq-invoice-list-empty`: Empty state message

### Examples

**Show all invoices:**
```
[dqqb_invoice_list]
```

**Show only invoices with outstanding balance:**
```
[dqqb_invoice_list unpaid_only="true"]
```

**Show only unpaid invoices from the current year:**
```
[dqqb_invoice_list status="unpaid" date_from="2024-01-01"]
```

**Show paid invoices from Q1 2024:**
```
[dqqb_invoice_list status="paid" date_from="2024-01-01" date_to="2024-03-31"]
```

**Show invoices with balance, 15 per page, filtered by due date:**
```
[dqqb_invoice_list unpaid_only="true" per_page="15" date_type="qi_due_date"]
```

**Using the dedicated unpaid invoices shortcode:**
```
[dqqb_unpaid_invoices]
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

---

## Login Form Shortcode

### Basic Usage

Display a login form on any page (designed for the /access page):

```
[dq_login]
```

### Features

- **Username/Email Support**: Accepts both username and email address for login
- **AJAX Login**: Login happens without page reload with instant feedback
- **Remember Me**: Optional "remember me" checkbox for persistent sessions
- **Error Messages**: Clear, user-friendly error messages for failed login attempts
- **Automatic Redirect**: On successful login, redirects to /account-page/
- **Already Logged In**: Shows info message with link to /account-page/ if user is already logged in
- **Lost Password Link**: Includes link to WordPress password recovery
- **Responsive Design**: Works on desktop, tablet, and mobile devices
- **Styled to Match**: Uses same styling as dashboard components

### Display Format

The login form displays:

| Element | Description |
|---------|-------------|
| **Header** | "Sign In" heading with description |
| **Username/Email Field** | Text input for username or email address |
| **Password Field** | Password input field |
| **Remember Me** | Checkbox to keep user logged in |
| **Sign In Button** | Submit button with loading state |
| **Forgot Password** | Link to WordPress password recovery |

### Logged In State

When a user is already logged in, the shortcode displays:

- Success icon and "Already Signed In" heading
- Current user's display name
- "Go to Account Page" button linking to /account-page/
- "Sign out" link to log out

### Error Handling

The form displays user-friendly error messages for:

- Invalid username/email
- Incorrect password
- Empty fields
- Security/nonce failures
- Network errors

### Technical Notes

- Uses WordPress native `wp_signon()` for authentication
- AJAX requests are secured with WordPress nonces
- Supports both username and email address for login
- Redirects to `/account-page/` after successful login
- Compatible with the DQ Login Redirect handler (redirects wp-login.php to /access)
- Works with SSL (uses `is_ssl()` for secure cookie handling)

### Styling

The shortcode includes built-in styling matching the dashboard theme:

- Teal gradient header (#0a6b72 to #085f65)
- Clean form inputs with focus states
- Gradient submit button matching dashboard buttons
- Loading spinner during submission
- Error messages with red styling
- Success state with teal accents

You can override styles by targeting these CSS classes:

- `.dq-login-wrapper`: Main container
- `.dq-login-container`: Form card
- `.dq-login-header`: Header section
- `.dq-login-form`: Form element
- `.dq-form-group`: Form field container
- `.dq-login-button`: Submit button
- `.dq-login-error`: Error message container
- `.dq-login-footer`: Footer with forgot password link
- `.dq-logged-in`: Container when user is already logged in

### Example

**Display login form on /access page:**
```
[dq_login]
```
