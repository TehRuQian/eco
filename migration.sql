-- =====================================================================
-- Migration Script: Upgrade Existing eco_db to Automated ECO Tracker
-- Run this in phpMyAdmin if you have existing tables from previous versions.
-- =====================================================================

USE `eco_db`;

-- 1. Upgrade eco_master table with missing columns
ALTER TABLE `eco_master`
    ADD COLUMN IF NOT EXISTS `cut_in_first_mo` VARCHAR(100) NULL AFTER `warehouse_action`,
    ADD COLUMN IF NOT EXISTS `cut_in_date` DATETIME NULL AFTER `cut_in_first_mo`,
    ADD COLUMN IF NOT EXISTS `pmc_site` VARCHAR(150) NULL AFTER `cut_in_date`,
    ADD COLUMN IF NOT EXISTS `qa_site` VARCHAR(150) NULL AFTER `pmc_site`,
    ADD COLUMN IF NOT EXISTS `originated_date` DATETIME NULL AFTER `qa_site`,
    ADD COLUMN IF NOT EXISTS `completed_date` DATETIME NULL AFTER `originated_date`,
    ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 2. Upgrade eco_signoff table with missing columns
CREATE TABLE IF NOT EXISTS `eco_signoff` (
    `eco_no` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`eco_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `eco_signoff`
    ADD COLUMN IF NOT EXISTS `internal_status` VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS `previous_internal_status` VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS `user_name` VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS `user_role` VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS `status_entry_date` DATETIME NULL,
    ADD COLUMN IF NOT EXISTS `status_changed` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `status_changed_at` DATETIME NULL,
    ADD COLUMN IF NOT EXISTS `is_unmatched` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 3. Upgrade eco_tracking table
CREATE TABLE IF NOT EXISTS `eco_tracking` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `eco_no` VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fix pme_result_completed column name if it existed in old versions
-- Note: In older MySQL, run this line if pme_result_completed is present:
-- ALTER TABLE `eco_tracking` CHANGE COLUMN `pme_result_completed` `pmc_result_completed` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `eco_tracking`
    ADD COLUMN IF NOT EXISTS `rework_need` TEXT NULL,
    ADD COLUMN IF NOT EXISTS `ecr_category` VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS `pmc_result_completed` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `qa_result_completed` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `first_mo_result` VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS `due_date` DATE NULL,
    ADD COLUMN IF NOT EXISTS `status_progress` VARCHAR(50) NOT NULL DEFAULT 'Pending PMC',
    ADD COLUMN IF NOT EXISTS `impact_assessment_checklist` TEXT NULL,
    ADD COLUMN IF NOT EXISTS `pending_checklist` TEXT NULL,
    ADD COLUMN IF NOT EXISTS `updated_by` VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS `updated_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 4. Automatically generate initial tracking records for any existing ECO master rows
INSERT IGNORE INTO `eco_tracking` (`eco_no`, `pmc_result_completed`, `qa_result_completed`, `status_progress`)
SELECT `eco_no`, 0, 0, 'Pending PMC' FROM `eco_master`;

SELECT 'Migration completed successfully. Refresh the dashboard.' AS `Result`;
