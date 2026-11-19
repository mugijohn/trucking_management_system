# FleetFlow — Trucking Management System

A standalone PHP 8+ / MySQL trucking operations application with a responsive dark navy-blue interface.

## Included modules
- Dashboard with company-wide driver gross, deductions and net pay summary
- Truck, driver, broker and load records
- Driver-to-truck assignment dropdowns
- Factoring submission, fee, reserve and funding tracking
- BOL, POD and rate-confirmation uploads
- Fuel receipts and operating expense tracking per truck / driver / load
- Weekly payroll calculation for percentage, per-mile, fixed-per-load and weekly salary agreements
- Printable paystubs
- Broker invoice creation and payment status tracking
- IFTA jurisdiction mileage and fuel records with basic MPG calculation
- Driver and truck compliance document records with expiry status
- Profit and loss estimates by truck, load and company
- Admin and employee accounts, role restrictions, password hashing and audit logging

## Installation (XAMPP)
1. Copy the `trucking_management_system` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL in XAMPP.
3. Open `config.php` and confirm your MySQL host, database, username and password.
4. Visit `http://localhost/trucking_management_system/setup.php`.
5. After setup completes, sign in at `http://localhost/trucking_management_system/login.php`.

Initial administrator:
- Email: `admin@fleetflow.com`
- Password: `ChangeMe123!`

Change the initial password immediately after first login. For production, remove or restrict `setup.php`, use a dedicated database user, enable HTTPS, and configure secure backups.

## Notes / operational controls
- Currency is USD throughout the interface.
- Employee accounts can access operational modules; user management and destructive record actions are administrator-only.
- The IFTA page stores state-level miles and gallons and calculates basic MPG. It is a data-entry/reporting aid, not a tax filing engine. Confirm source records and official jurisdiction requirements before submitting a return.
- Payroll uses delivered/invoiced/paid loads with a delivery date inside the selected pay period. Percentage, per-mile and fixed-per-load methods use the driver's configured rate. Weekly Salary uses the configured weekly amount. Deductions are entered by the payroll operator.
- Profit and loss is a management estimate. Allocate all costs consistently and reconcile against accounting records before relying on it for tax or financial statements.
- Uploaded files are stored under `uploads/`. Protect this directory from script execution on production hosting and apply a retention/backup policy.
- This is a starter business system. Before production deployment, add formal permission matrices, email notifications, scheduled overdue-invoice reminders, secure document streaming, automated backups, and jurisdiction-specific IFTA calculations.

## Requirements
- PHP 8.1+
- MySQL 8.0+ or MariaDB 10.5+
- PDO MySQL extension
- File uploads enabled; recommended `upload_max_filesize=15M` and `post_max_size=20M`


## Driver-specific weekly insurance

- Insurance is now entered on the Add Driver / Edit Driver form as **Weekly insurance amount (USD)**.
- The configured amount is deducted automatically from that driver's gross pay on every weekly paystub.
- Insurance is no longer offered as a category on the Fuel & Expenses entry form.
- The driver table schema includes `insurance_amount`; the application also adds this column automatically on older databases when Drivers or Paystub is opened.
- Optional manual migration: import `upgrade_driver_insurance.sql` in phpMyAdmin. Do not run it if the column already exists.
- Existing historical expense records categorized as Insurance are not deleted. Review those records to avoid duplicate deductions if they overlap with the new driver-specific amount.

## Dispatcher Payroll

A separate **Dispatcher Payroll** page is available in the Financials menu. Add a dispatcher, set the commission multiplier (default `0.035`, meaning 3.5%), then generate a weekly paystub. The dispatcher gross is calculated from all non-cancelled loads for all drivers in the selected Wednesday-to-Tuesday period. The printable statement lists delivery/pickup date, truck number, driver name, company, and load amount, followed by total gross, commission, advances/payroll, savings, and take-home pay. The database tables are created automatically on first visit to the page; the optional SQL migration is `upgrade_dispatcher_payroll.sql`.
