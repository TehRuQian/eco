-- Archive/restore migration for MySQL 8.x. Import in phpMyAdmin.
USE `eco_db`;
SET NAMES utf8mb4;
SELECT GROUP_CONCAT(CONCAT('ADD COLUMN `', wanted.column_name, '` ', wanted.definition)
                    ORDER BY wanted.position SEPARATOR ', ')
INTO @eco_archive_columns
FROM (
    SELECT 'archived_at' AS column_name, 'DATETIME NULL' AS definition, 1 AS position
    UNION ALL SELECT 'archived_by', 'VARCHAR(100) NULL', 2
    UNION ALL SELECT 'archive_reason', 'TEXT NULL', 3
) AS wanted
WHERE NOT EXISTS (
    SELECT 1 FROM information_schema.columns AS existing
    WHERE existing.table_schema = DATABASE()
      AND existing.table_name = 'eco_master'
      AND existing.column_name = wanted.column_name
);
SET @eco_archive_ddl = IF(@eco_archive_columns IS NULL,
    'SELECT ''Archive columns already exist'' AS message',
    CONCAT('ALTER TABLE `eco_master` ', @eco_archive_columns));
PREPARE eco_archive_statement FROM @eco_archive_ddl;
EXECUTE eco_archive_statement;
DEALLOCATE PREPARE eco_archive_statement;
CREATE TABLE IF NOT EXISTS eco_archive_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    eco_no VARCHAR(100) NOT NULL,
    action VARCHAR(10) NOT NULL,
    actor VARCHAR(100) NOT NULL,
    reason TEXT NOT NULL,
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_archive_history_eco (eco_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 'Archive migration complete. Existing ECOs remain active.' AS message;
