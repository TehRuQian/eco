-- =====================================================================
-- Automated ECO Tracker System - Database Schema
-- Version: 1.0.0
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `eco_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `eco_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. eco_master Table (Agile PLM SearchResult Master Data)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eco_master` (
    `eco_no`                     VARCHAR(100) NOT NULL COMMENT 'Normalized ECO Change Number',
    `ecr_no`                     VARCHAR(100) NULL,
    `customer`                   VARCHAR(150) NULL,
    `project`                    VARCHAR(150) NULL,
    `eco_implementation_method`  VARCHAR(100) NULL,
    `ec_type_category`           VARCHAR(100) NULL,
    `status`                     VARCHAR(100) NULL COMMENT 'Cleaned status (without # No Controller)',
    `subject`                    TEXT NULL,
    `status_in_agile`            VARCHAR(100) NULL,
    `wip_action`                 VARCHAR(100) NULL,
    `fg_action`                  VARCHAR(100) NULL,
    `warehouse_action`           VARCHAR(100) NULL,
    `cut_in_first_mo`            VARCHAR(100) NULL,
    `cut_in_date`                DATETIME NULL,
    `pmc_site`                   VARCHAR(150) NULL COMMENT 'Direct from SearchResult PMC_Site',
    `qa_site`                    VARCHAR(150) NULL COMMENT 'Direct from SearchResult QA_Site',
    `originated_date`            DATETIME NULL,
    `completed_date`             DATETIME NULL,
    `year`                       INT NULL,
    `ww`                         INT NULL COMMENT 'Thursday-based work week',
    `month`                      VARCHAR(20) NULL,
    `created_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`eco_no`),
    INDEX `idx_master_customer` (`customer`),
    INDEX `idx_master_project` (`project`),
    INDEX `idx_master_status_agile` (`status_in_agile`),
    INDEX `idx_master_year_ww` (`year`, `ww`),
    INDEX `idx_master_originated` (`originated_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. eco_signoff Table (Current Signoff Status & History Detection)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eco_signoff` (
    `eco_no`                     VARCHAR(100) NOT NULL COMMENT 'Normalized ECO Change Number',
    `internal_status`            VARCHAR(100) NULL COMMENT 'Current signoff status',
    `previous_internal_status`   VARCHAR(100) NULL COMMENT 'Previous imported status',
    `user_name`                  VARCHAR(150) NULL,
    `user_role`                  VARCHAR(100) NULL,
    `status_entry_date`          DATETIME NULL,
    `status_changed`             TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if status change detected',
    `status_changed_at`          DATETIME NULL,
    `is_unmatched`               TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if exists only in signoff file',
    `created_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`eco_no`),
    INDEX `idx_signoff_status` (`internal_status`),
    INDEX `idx_signoff_changed` (`status_changed`),
    INDEX `idx_signoff_unmatched` (`is_unmatched`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. eco_tracking Table (Manual Tracking Data - NEVER overwritten on import)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eco_tracking` (
    `id`                           INT AUTO_INCREMENT PRIMARY KEY,
    `eco_no`                       VARCHAR(100) NOT NULL,
    `rework_need`                  TEXT NULL,
    `ecr_category`                 VARCHAR(100) NULL,
    `pmc_result_completed`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=No, 1=Yes',
    `qa_result_completed`          TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=No, 1=Yes',
    `first_mo_result`              VARCHAR(100) NULL,
    `due_date`                     DATE NULL COMMENT 'Date Originated + 14 calendar days',
    `status_progress`              VARCHAR(50) NOT NULL DEFAULT 'Pending PMC' COMMENT 'Pending PMC / Pending QA / Completed',
    `impact_assessment_checklist`  TEXT NULL,
    `pending_checklist`            TEXT NULL,
    `updated_by`                   VARCHAR(100) NULL,
    `updated_date`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_tracking_eco_no` (`eco_no`),
    INDEX `idx_tracking_progress` (`status_progress`),
    INDEX `idx_tracking_due_date` (`due_date`),
    INDEX `idx_tracking_pmc_qa` (`pmc_result_completed`, `qa_result_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
