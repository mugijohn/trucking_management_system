-- Run once in phpMyAdmin against the trucking_management database.
-- Adds support for storing lumper receipts in the existing load_documents table.
ALTER TABLE load_documents
MODIFY document_type ENUM('Rate Confirmation','BOL','Lumper Receipt','POD','Other') NOT NULL;
