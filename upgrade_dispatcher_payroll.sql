CREATE TABLE IF NOT EXISTS dispatchers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  commission_rate DECIMAL(8,5) NOT NULL DEFAULT 0.03500,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dispatcher_payroll (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dispatcher_id INT NOT NULL,
  week_start DATE NOT NULL,
  week_end DATE NOT NULL,
  pay_date DATE NOT NULL,
  total_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_rate DECIMAL(8,5) NOT NULL DEFAULT 0.03500,
  commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  advances DECIMAL(12,2) NOT NULL DEFAULT 0,
  savings DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('Draft','Approved','Paid') NOT NULL DEFAULT 'Draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY dispatcher_period (dispatcher_id, week_start, week_end),
  CONSTRAINT fk_dispatcher_payroll_dispatcher FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Staff position and pay method support (safe to run on existing installations).
ALTER TABLE dispatchers ADD COLUMN staff_type VARCHAR(20) NOT NULL DEFAULT 'Dispatcher';
ALTER TABLE dispatchers ADD COLUMN pay_type VARCHAR(20) NOT NULL DEFAULT 'Commission';
ALTER TABLE dispatchers ADD COLUMN fixed_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE dispatcher_payroll ADD COLUMN pay_type VARCHAR(20) NOT NULL DEFAULT 'Commission';
ALTER TABLE dispatcher_payroll ADD COLUMN fixed_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00;
