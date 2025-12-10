# Payroll CSV/Excel Import Feature

## Overview

The Payroll Management system now supports bulk import of payroll entries via CSV or Excel (.xlsx) files. This feature allows administrators to efficiently upload multiple payroll records at once instead of entering them manually.

## Accessing the Import Feature

1. Navigate to **Financial Reports** in the WordPress admin dashboard
2. Click the **Manage Payroll** button
3. In the modal that opens, locate the **Import Payroll Records** section at the top

## CSV/Excel File Format

Your import file must contain the following columns (column names are case-insensitive):

- **date**: The payroll date in YYYY-MM-DD format (e.g., 2024-01-15)
- **amount**: The payroll amount as a number (e.g., 1500.00 or 1500)
- **name**: The display name of the WordPress user to assign the payroll to

### Example CSV File

```csv
date,amount,name
2024-01-15,1500.00,John Smith
2024-01-15,2000.50,Jane Doe
2024-01-16,1800.25,Bob Johnson
```

### Example Excel File

Create an Excel spreadsheet with the same structure:

| date       | amount  | name         |
|------------|---------|--------------|
| 2024-01-15 | 1500.00 | John Smith   |
| 2024-01-15 | 2000.50 | Jane Doe     |
| 2024-01-16 | 1800.25 | Bob Johnson  |

## Import Process

1. Prepare your CSV or Excel file with the required columns
2. Click **Choose File** in the Import section
3. Select your CSV or XLSX file
4. Click the **Import** button
5. Wait for the import to complete

## Import Results

After the import completes, you'll see a summary showing:

- **Created**: Number of payroll records successfully created
- **Skipped**: Number of rows that were skipped due to errors
- **Error Details**: List of specific errors for skipped rows

## Common Import Issues

### User Not Found

If a row is skipped with the message "user not found with display name", ensure:
- The user exists in WordPress
- The display name matches exactly (case-sensitive)
- The user has an active account

### Invalid Date Format

Dates must be in YYYY-MM-DD format (e.g., 2024-01-15). Common date format issues:
- Using MM/DD/YYYY or DD/MM/YYYY formats
- Using text dates (e.g., "January 15, 2024")
- Invalid dates (e.g., 2024-13-45)

### Invalid Amount

Amounts must be:
- Numeric values (e.g., 1500.00 or 1500)
- Positive numbers (negative amounts are not allowed)
- Valid decimal numbers

## Security & Permissions

- Only administrators (users with `manage_options` capability) can import payroll records
- File uploads are validated to ensure only CSV and XLSX files are accepted
- Excel files are converted to CSV securely using WordPress's built-in functions
- User lookups are validated against WordPress user database

## Technical Details

### Supported File Types
- `.csv` - Comma-separated values
- `.xlsx` - Microsoft Excel 2007+ format

### Field Mapping
- **date** column → Payroll date (stored in post_date)
- **amount** column → Payroll amount (stored in payroll_amount meta field)
- **name** column → WordPress user display name (resolved to user_id, stored in payroll_user_id meta field)

### Performance
- User lookups are cached during import to improve performance
- Large imports (hundreds of rows) are processed efficiently
- Temporary files are cleaned up automatically after import

## Troubleshooting

### Excel File Not Processing

If Excel import fails:
1. Ensure the file is a valid .xlsx format (not .xls)
2. Check that your server has the ZipArchive PHP extension installed
3. Try saving the Excel file as CSV and importing the CSV instead

### All Rows Skipped

If all rows are skipped:
1. Verify column names match exactly: `date`, `amount`, `name`
2. Check that at least one user name matches a WordPress display name
3. Ensure date format is YYYY-MM-DD
4. Verify amounts are valid numbers

### Import Timeout

For very large files (1000+ rows):
1. Break the file into smaller batches
2. Contact your server administrator to increase PHP max_execution_time
3. Import during off-peak hours

## Best Practices

1. **Test with a small file first**: Import 2-3 rows to verify formatting before importing large datasets
2. **Back up your data**: Create a database backup before large imports
3. **Use consistent naming**: Ensure user display names in the CSV match exactly with WordPress
4. **Validate dates**: Double-check all dates are in YYYY-MM-DD format
5. **Review errors**: After import, review any skipped rows and manually add them if needed
