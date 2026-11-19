-- FleetFlow comprehensive database compatibility upgrade
-- Select the FleetFlow database (normally `trucking_management`) before running this file.
-- This migration checks INFORMATION_SCHEMA and adds only missing columns.
-- Existing records are retained. Back up the database before running any migration.

SET @sql_trucks_trailer_no = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `trucks` ADD COLUMN `trailer_no` VARCHAR(50) DEFAULT NULL',
    'SELECT ''trucks.trailer_no already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trucks' AND COLUMN_NAME = 'trailer_no'
);
PREPARE stmt FROM @sql_trucks_trailer_no;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_trucks_current_mileage = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `trucks` ADD COLUMN `current_mileage` INT(11) NOT NULL DEFAULT 0',
    'SELECT ''trucks.current_mileage already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trucks' AND COLUMN_NAME = 'current_mileage'
);
PREPARE stmt FROM @sql_trucks_current_mileage;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_drivers_truck_id = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `drivers` ADD COLUMN `truck_id` INT(11) DEFAULT NULL',
    'SELECT ''drivers.truck_id already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'truck_id'
);
PREPARE stmt FROM @sql_drivers_truck_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_drivers_pay_method = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `drivers` ADD COLUMN `pay_method` VARCHAR(40) NOT NULL DEFAULT \'Fixed Per Load\'',
    'SELECT ''drivers.pay_method already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'pay_method'
);
PREPARE stmt FROM @sql_drivers_pay_method;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_drivers_pay_rate = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `drivers` ADD COLUMN `pay_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT ''drivers.pay_rate already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'pay_rate'
);
PREPARE stmt FROM @sql_drivers_pay_rate;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_drivers_license_no = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `drivers` ADD COLUMN `license_no` VARCHAR(80) DEFAULT NULL',
    'SELECT ''drivers.license_no already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'license_no'
);
PREPARE stmt FROM @sql_drivers_license_no;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_drivers_license_expiry = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `drivers` ADD COLUMN `license_expiry` DATE DEFAULT NULL',
    'SELECT ''drivers.license_expiry already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'license_expiry'
);
PREPARE stmt FROM @sql_drivers_license_expiry;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_broker = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `broker` VARCHAR(150) DEFAULT NULL',
    'SELECT ''loads.broker already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'broker'
);
PREPARE stmt FROM @sql_loads_broker;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_broker_id = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `broker_id` INT(11) DEFAULT NULL',
    'SELECT ''loads.broker_id already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'broker_id'
);
PREPARE stmt FROM @sql_loads_broker_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_broker_contact = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `broker_contact` VARCHAR(100) DEFAULT NULL',
    'SELECT ''loads.broker_contact already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'broker_contact'
);
PREPARE stmt FROM @sql_loads_broker_contact;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_pickup_location = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `pickup_location` VARCHAR(255) DEFAULT NULL',
    'SELECT ''loads.pickup_location already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'pickup_location'
);
PREPARE stmt FROM @sql_loads_pickup_location;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_delivery_location = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `delivery_location` VARCHAR(255) DEFAULT NULL',
    'SELECT ''loads.delivery_location already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'delivery_location'
);
PREPARE stmt FROM @sql_loads_delivery_location;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_pickup_date = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `pickup_date` DATE DEFAULT NULL',
    'SELECT ''loads.pickup_date already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'pickup_date'
);
PREPARE stmt FROM @sql_loads_pickup_date;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_delivery_date = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `delivery_date` DATE DEFAULT NULL',
    'SELECT ''loads.delivery_date already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'delivery_date'
);
PREPARE stmt FROM @sql_loads_delivery_date;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_truck_id = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `truck_id` INT(11) DEFAULT NULL',
    'SELECT ''loads.truck_id already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'truck_id'
);
PREPARE stmt FROM @sql_loads_truck_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_driver_id = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `driver_id` INT(11) DEFAULT NULL',
    'SELECT ''loads.driver_id already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'driver_id'
);
PREPARE stmt FROM @sql_loads_driver_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_rate = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT ''loads.rate already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'rate'
);
PREPARE stmt FROM @sql_loads_rate;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_miles = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `miles` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT ''loads.miles already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'miles'
);
PREPARE stmt FROM @sql_loads_miles;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_factoring_status = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `factoring_status` VARCHAR(40) NOT NULL DEFAULT \'Not Factored\'',
    'SELECT ''loads.factoring_status already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'factoring_status'
);
PREPARE stmt FROM @sql_loads_factoring_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_reference_no = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `reference_no` VARCHAR(100) DEFAULT NULL',
    'SELECT ''loads.reference_no already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'reference_no'
);
PREPARE stmt FROM @sql_loads_reference_no;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_driver_id = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `driver_id` INT(11) DEFAULT NULL',
    'SELECT ''expenses.driver_id already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'driver_id'
);
PREPARE stmt FROM @sql_expenses_driver_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_vendor = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `vendor` VARCHAR(150) DEFAULT NULL',
    'SELECT ''expenses.vendor already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'vendor'
);
PREPARE stmt FROM @sql_expenses_vendor;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_gallons = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `gallons` DECIMAL(10,2) NOT NULL DEFAULT 0.00',
    'SELECT ''expenses.gallons already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'gallons'
);
PREPARE stmt FROM @sql_expenses_gallons;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_state_code = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `state_code` VARCHAR(10) DEFAULT NULL',
    'SELECT ''expenses.state_code already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'state_code'
);
PREPARE stmt FROM @sql_expenses_state_code;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_receipt_path = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `receipt_path` VARCHAR(255) DEFAULT NULL',
    'SELECT ''expenses.receipt_path already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'receipt_path'
);
PREPARE stmt FROM @sql_expenses_receipt_path;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_users_password_hash = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `users` ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL',
    'SELECT ''users.password_hash already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_hash'
);
PREPARE stmt FROM @sql_users_password_hash;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_users_status = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `users` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT \'Active\'',
    'SELECT ''users.status already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status'
);
PREPARE stmt FROM @sql_users_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_invoices_paid_date = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `invoices` ADD COLUMN `paid_date` DATE DEFAULT NULL',
    'SELECT ''invoices.paid_date already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'paid_date'
);
PREPARE stmt FROM @sql_invoices_paid_date;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_invoices_payment_reference = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `invoices` ADD COLUMN `payment_reference` VARCHAR(120) DEFAULT NULL',
    'SELECT ''invoices.payment_reference already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'payment_reference'
);
PREPARE stmt FROM @sql_invoices_payment_reference;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_invoices_follow_up_notes = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `invoices` ADD COLUMN `follow_up_notes` TEXT DEFAULT NULL',
    'SELECT ''invoices.follow_up_notes already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'follow_up_notes'
);
PREPARE stmt FROM @sql_invoices_follow_up_notes;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_factoring_reserve_amount = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `factoring` ADD COLUMN `reserve_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT ''factoring.reserve_amount already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factoring' AND COLUMN_NAME = 'reserve_amount'
);
PREPARE stmt FROM @sql_factoring_reserve_amount;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_factoring_funded_amount = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `factoring` ADD COLUMN `funded_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT ''factoring.funded_amount already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factoring' AND COLUMN_NAME = 'funded_amount'
);
PREPARE stmt FROM @sql_factoring_funded_amount;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_factoring_funded_date = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `factoring` ADD COLUMN `funded_date` DATE DEFAULT NULL',
    'SELECT ''factoring.funded_date already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factoring' AND COLUMN_NAME = 'funded_date'
);
PREPARE stmt FROM @sql_factoring_funded_date;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_factoring_reference_no = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `factoring` ADD COLUMN `reference_no` VARCHAR(120) DEFAULT NULL',
    'SELECT ''factoring.reference_no already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factoring' AND COLUMN_NAME = 'reference_no'
);
PREPARE stmt FROM @sql_factoring_reference_no;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_factoring_notes = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `factoring` ADD COLUMN `notes` TEXT DEFAULT NULL',
    'SELECT ''factoring.notes already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factoring' AND COLUMN_NAME = 'notes'
);
PREPARE stmt FROM @sql_factoring_notes;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_ifta_mileage_fuel_amount = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `ifta_mileage` ADD COLUMN `fuel_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT ''ifta_mileage.fuel_amount already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ifta_mileage' AND COLUMN_NAME = 'fuel_amount'
);
PREPARE stmt FROM @sql_ifta_mileage_fuel_amount;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_payroll_payment_method = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `payroll` ADD COLUMN `payment_method` VARCHAR(80) DEFAULT NULL',
    'SELECT ''payroll.payment_method already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'payment_method'
);
PREPARE stmt FROM @sql_payroll_payment_method;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_payroll_status = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `payroll` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT \'Draft\'',
    'SELECT ''payroll.status already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'status'
);
PREPARE stmt FROM @sql_payroll_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_payroll_notes = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `payroll` ADD COLUMN `notes` TEXT DEFAULT NULL',
    'SELECT ''payroll.notes already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'notes'
);
PREPARE stmt FROM @sql_payroll_notes;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_drivers_status = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `drivers` ADD COLUMN `status` VARCHAR(40) NOT NULL DEFAULT \'Available\'',
    'SELECT ''drivers.status already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers' AND COLUMN_NAME = 'status'
);
PREPARE stmt FROM @sql_drivers_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_loads_status = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `loads` ADD COLUMN `status` VARCHAR(40) NOT NULL DEFAULT \'Booked\'',
    'SELECT ''loads.status already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loads' AND COLUMN_NAME = 'status'
);
PREPARE stmt FROM @sql_loads_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_trucks_status = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `trucks` ADD COLUMN `status` VARCHAR(40) NOT NULL DEFAULT \'Available\'',
    'SELECT ''trucks.status already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trucks' AND COLUMN_NAME = 'status'
);
PREPARE stmt FROM @sql_trucks_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_users_role = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `users` ADD COLUMN `role` VARCHAR(40) NOT NULL DEFAULT \'Admin\'',
    'SELECT ''users.role already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
);
PREPARE stmt FROM @sql_users_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_category = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `category` VARCHAR(80) NOT NULL DEFAULT \'Other\'',
    'SELECT ''expenses.category already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'category'
);
PREPARE stmt FROM @sql_expenses_category;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_amount = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
    'SELECT ''expenses.amount already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'amount'
);
PREPARE stmt FROM @sql_expenses_amount;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_expense_date = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `expense_date` DATE DEFAULT NULL',
    'SELECT ''expenses.expense_date already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'expense_date'
);
PREPARE stmt FROM @sql_expenses_expense_date;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_expenses_description = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `expenses` ADD COLUMN `description` TEXT DEFAULT NULL',
    'SELECT ''expenses.description already exists'' AS migration_status'
  ) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'description'
);
PREPARE stmt FROM @sql_expenses_description;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Normalize status columns to flexible VARCHAR types used by current FleetFlow screens.
ALTER TABLE `loads` MODIFY COLUMN `status` VARCHAR(40) NOT NULL DEFAULT 'Booked';
ALTER TABLE `drivers` MODIFY COLUMN `status` VARCHAR(40) NOT NULL DEFAULT 'Available';
ALTER TABLE `trucks` MODIFY COLUMN `status` VARCHAR(40) NOT NULL DEFAULT 'Available';
ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(40) NOT NULL DEFAULT 'Admin';

-- Preserve legacy driver pay labels while allowing current FleetFlow methods.
ALTER TABLE `drivers` MODIFY COLUMN `pay_method` VARCHAR(40) NOT NULL DEFAULT 'Fixed Per Load';

SELECT 'FleetFlow database compatibility migration completed.' AS migration_status;