-- FleetFlow: add fixed weekly insurance per driver
-- Run this once in phpMyAdmin against the FleetFlow database if desired.
ALTER TABLE drivers
  ADD COLUMN insurance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00;
