CREATE TABLE IF NOT EXISTS users (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, role ENUM('admin','employee') NOT NULL DEFAULT 'employee',
 status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS trucks (
 id INT AUTO_INCREMENT PRIMARY KEY, unit_no VARCHAR(50) NOT NULL UNIQUE, vin VARCHAR(80), make VARCHAR(80), model VARCHAR(80),
 year INT NULL, plate_no VARCHAR(40), status VARCHAR(40) DEFAULT 'Active', notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS drivers (
 id INT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL,
 email VARCHAR(190), phone VARCHAR(50), truck_id INT NULL, pay_method ENUM('Percentage','Per Mile','Fixed Per Load','Weekly Salary') DEFAULT 'Percentage',
 pay_rate DECIMAL(12,2) DEFAULT 0, status VARCHAR(40) DEFAULT 'Available', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(truck_id) REFERENCES trucks(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS brokers (
 id INT AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(160) NOT NULL, contact_name VARCHAR(120), email VARCHAR(190),
 phone VARCHAR(50), payment_terms INT DEFAULT 30, address TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS loads (
 id INT AUTO_INCREMENT PRIMARY KEY, load_no VARCHAR(60) NOT NULL UNIQUE, broker_id INT NULL, truck_id INT NULL, driver_id INT NULL,
 pickup_location VARCHAR(255), delivery_location VARCHAR(255), pickup_date DATE NULL, delivery_date DATE NULL,
 rate DECIMAL(12,2) NOT NULL DEFAULT 0, miles DECIMAL(12,2) DEFAULT 0,
 status ENUM('Booked','Dispatched','In Transit','Delivered','Invoiced','Paid','Cancelled') DEFAULT 'Booked',
 factoring_status VARCHAR(40) DEFAULT 'Not Factored', notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(broker_id) REFERENCES brokers(id) ON DELETE SET NULL,
 FOREIGN KEY(truck_id) REFERENCES trucks(id) ON DELETE SET NULL,
 FOREIGN KEY(driver_id) REFERENCES drivers(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS load_documents (
 id INT AUTO_INCREMENT PRIMARY KEY, load_id INT NOT NULL, document_type ENUM('Rate Confirmation','BOL','POD','Other') NOT NULL,
 file_path VARCHAR(255) NOT NULL, original_name VARCHAR(255), uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(load_id) REFERENCES loads(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS expenses (
 id INT AUTO_INCREMENT PRIMARY KEY, truck_id INT NULL, driver_id INT NULL, load_id INT NULL,
 expense_date DATE NOT NULL, category VARCHAR(80) NOT NULL, description VARCHAR(255), amount DECIMAL(12,2) NOT NULL,
 gallons DECIMAL(10,2) DEFAULT 0, state_code VARCHAR(10), receipt_path VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(truck_id) REFERENCES trucks(id) ON DELETE SET NULL,
 FOREIGN KEY(driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
 FOREIGN KEY(load_id) REFERENCES loads(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS invoices (
 id INT AUTO_INCREMENT PRIMARY KEY, load_id INT NOT NULL, invoice_no VARCHAR(60) NOT NULL UNIQUE,
 invoice_date DATE NOT NULL, due_date DATE NULL, amount DECIMAL(12,2) NOT NULL, status ENUM('Draft','Sent','Paid','Overdue') DEFAULT 'Draft',
 paid_date DATE NULL, payment_reference VARCHAR(120), follow_up_notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(load_id) REFERENCES loads(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS factoring (
 id INT AUTO_INCREMENT PRIMARY KEY, load_id INT NOT NULL, factoring_company VARCHAR(160) NOT NULL,
 submitted_date DATE NOT NULL, gross_amount DECIMAL(12,2) NOT NULL, fee DECIMAL(12,2) DEFAULT 0,
 reserve_amount DECIMAL(12,2) DEFAULT 0, funded_amount DECIMAL(12,2) NOT NULL, status ENUM('Submitted','Funded','Reserve Released','Rejected') DEFAULT 'Submitted',
 funded_date DATE NULL, reference_no VARCHAR(120), notes TEXT, FOREIGN KEY(load_id) REFERENCES loads(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS payroll (
 id INT AUTO_INCREMENT PRIMARY KEY, driver_id INT NOT NULL, week_start DATE NOT NULL, week_end DATE NOT NULL,
 gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0, deductions DECIMAL(12,2) NOT NULL DEFAULT 0, net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
 payment_method VARCHAR(80), status ENUM('Draft','Approved','Paid') DEFAULT 'Draft', notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY driver_week (driver_id,week_start,week_end), FOREIGN KEY(driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ifta_mileage (
 id INT AUTO_INCREMENT PRIMARY KEY, truck_id INT NOT NULL, quarter_label VARCHAR(20) NOT NULL, state_code VARCHAR(10) NOT NULL,
 miles DECIMAL(12,2) NOT NULL DEFAULT 0, fuel_gallons DECIMAL(12,2) NOT NULL DEFAULT 0, fuel_amount DECIMAL(12,2) DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(truck_id) REFERENCES trucks(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS compliance_documents (
 id INT AUTO_INCREMENT PRIMARY KEY, subject_type ENUM('Driver','Truck') NOT NULL, subject_id INT NOT NULL,
 document_type VARCHAR(100) NOT NULL, document_no VARCHAR(100), issue_date DATE NULL, expiry_date DATE NULL,
 file_path VARCHAR(255), notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_logs (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, action VARCHAR(80), entity VARCHAR(80), entity_id INT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;