FLEETFLOW COMPLETE DATABASE COMPATIBILITY UPGRADE

Included changes
- Comprehensive idempotent compatibility migration for older FleetFlow databases.
- Adds missing driver payroll, load miles/factoring, expense/fuel, truck, user,
  invoice and reporting columns when absent.
- Uses flexible VARCHAR status fields to avoid ENUM truncation when current
  screens use statuses not present in older schema versions.
- Updates the fresh-install database.sql schema with load miles and factoring_status.
- Aligns paystub load detail eligibility with payroll: assigned, non-cancelled
  loads in the pay period, using delivery date and pickup-date fallback.

BACKUP FIRST
1. In phpMyAdmin, export your existing `trucking_management` database.
2. Extract this ZIP.
3. Select `trucking_management` in phpMyAdmin.
4. Open the SQL tab and import/run `upgrade_database_compatibility.sql`.
5. Reload FleetFlow and test Drivers, Loads, Payroll, Paystub, Expenses, IFTA,
   Factoring, Invoices, Dashboard and Reports.

IMPORTANT
- Run the migration against the existing database, not database.sql.
- database.sql is for fresh installations.
- The migration preserves existing rows and adds only missing columns, but
  schema changes should always be preceded by a database backup.
- If your database uses a different name, select that database before running
  the migration.


PAYSTUB EXPENSE CATEGORY UPDATE
- Paystubs now list every expense category recorded in Fuel & Expenses.
- Existing statement categories keep their established labels.
- Any new/custom category is automatically shown as a separate expense line.
- Savings, Others/Other, Fuel Discount Refund, and Other Adjustment categories
  are treated as credits/add-backs, consistent with the prior paystub behavior.
- All other categories are treated as deductions.
- Expenses assigned directly to the driver are included, as are expenses
  assigned to trucks used on that driver's loads during the pay period.
- No additional database column is required for this paystub enhancement.


PAYSTUB WARNING FIX
The fuel summary previously referenced $totals['diesel'], but that variable
was not defined in the updated paystub. It now reads the diesel amount from
the category totals array and safely defaults to zero when no diesel expense
exists. This removes the undefined-variable/array-key warning in the fuel
summary.


EXPENSE TYPE AND PAYSTUB RECIPIENT DISPLAY
- The Fuel & Expenses form and table now label the selected category explicitly
  as "Expense Type / Category" (Fuel, Insurance, Repair, Maintenance, Tolls,
  Permits, etc.). Existing category values and saved records are unchanged.
- The paystub TO section now identifies the driver by full name as the recipient.
  POMILLY TRANSPORT LLC remains shown as the company, and the truck number remains
  displayed beneath it.

PAYROLL SCHEDULE UPDATE
- Weekly pay period is Wednesday through the following Tuesday (7 days).
- Payment date is calculated as the Friday immediately after the Tuesday period end.
- Payroll generation form automatically fills and locks the current weekly dates.
- Manually submitted periods are validated to ensure Wednesday-to-Tuesday dates.
- Paystub payment date is derived from the period end date, not the record creation date.
- Payroll history displays the calculated Friday payday.

WEEKLY DRIVER PAYMENT STATUS
The Summary page now shows UNPAID / PAID status and a Mark as Paid action for each driver in DISPATCH SUMMARY — DRIVER NET PAY. After the dispatcher has sent the driver's net pay, click Mark as Paid and confirm. The status is saved against that driver and the selected Wednesday–Tuesday payroll week, with the date/time and logged-in user ID. The status is independent for each week. An Undo action is available to correct an accidental mark. The table is created automatically when Summary is opened; the optional SQL migration is upgrade_driver_weekly_payments.sql.
