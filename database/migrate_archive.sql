-- History-only archive migration for MySQL 8.x. Import in phpMyAdmin.
USE `eco_db`;
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS eco_archive_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    eco_no VARCHAR(100) NOT NULL,
    action VARCHAR(10) NOT NULL,
    actor VARCHAR(100) NOT NULL,
    reason TEXT NOT NULL,
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_archive_history_eco (eco_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Preserve legacy archives with no history before dropping the old fields.
SELECT COUNT(*) INTO @eco_has_archive_date FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'eco_master' AND column_name = 'archived_at';
SELECT IF(COUNT(*) > 0, 'COALESCE(em.archived_by, ''User'')', '''User''') INTO @eco_archive_actor
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'eco_master' AND column_name = 'archived_by';
SELECT IF(COUNT(*) > 0, 'COALESCE(em.archive_reason, '''')', '''''') INTO @eco_archive_reason
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'eco_master' AND column_name = 'archive_reason';
SET @eco_archive_copy = IF(@eco_has_archive_date = 0, 'SELECT ''No legacy archives'' AS message',
    CONCAT('INSERT INTO eco_archive_history (eco_no, action, actor, reason, performed_at) ',
        'SELECT em.eco_no, ''archive'', ', @eco_archive_actor, ', ', @eco_archive_reason, ', em.archived_at ',
        'FROM eco_master em WHERE em.archived_at IS NOT NULL ',
        'AND NOT EXISTS (SELECT 1 FROM eco_archive_history ah WHERE ah.eco_no COLLATE utf8mb4_unicode_ci = em.eco_no COLLATE utf8mb4_unicode_ci)'));
PREPARE eco_archive_statement FROM @eco_archive_copy;
EXECUTE eco_archive_statement;
DEALLOCATE PREPARE eco_archive_statement;
SELECT GROUP_CONCAT(CONCAT('DROP COLUMN `', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ')
INTO @eco_archive_drop FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'eco_master'
    AND column_name IN ('archived_at', 'archived_by', 'archive_reason');
SET @eco_archive_ddl = IF(@eco_archive_drop IS NULL, 'SELECT ''Legacy archive columns already removed'' AS message',
    CONCAT('ALTER TABLE eco_master ', @eco_archive_drop));
PREPARE eco_archive_statement FROM @eco_archive_ddl;
EXECUTE eco_archive_statement;
DEALLOCATE PREPARE eco_archive_statement;
SELECT 'Archive state now uses the history entry with the greatest id for each ECO.' AS message;
