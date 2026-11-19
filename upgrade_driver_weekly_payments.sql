-- Creates weekly driver payment tracking used by Summary > Dispatch Summary.
-- The application also creates this table automatically when summary.php is opened.
CREATE TABLE IF NOT EXISTS driver_weekly_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    paid_by INT NULL,
    paid_at DATETIME NULL,
    UNIQUE KEY uq_driver_week (driver_id, week_start, week_end),
    INDEX idx_driver_week_status (week_start, week_end, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
