-- PMC/QA contact migration for MySQL 8.4 (the version in your export).
-- In phpMyAdmin select eco_db, then import this file.
-- Change USE below if the application's DB_NAME is different.
-- Keeps pmc_site, qa_site, status, and updated_at unchanged.
-- Safe to rerun: only contact groups with both new fields NULL are populated.
USE `eco_db`;
SET NAMES utf8mb4;

-- Add only missing columns; works if the application already added some/all.
SELECT GROUP_CONCAT(CONCAT('ADD COLUMN `', wanted.column_name, '` TEXT NULL')
                    ORDER BY wanted.position SEPARATOR ', ')
INTO @eco_contact_columns
FROM (
    SELECT 'pmc_name' AS column_name, 1 AS position
    UNION ALL SELECT 'pmc_email', 2
    UNION ALL SELECT 'qa_name', 3
    UNION ALL SELECT 'qa_email', 4
) AS wanted
WHERE NOT EXISTS (
    SELECT 1 FROM information_schema.columns AS existing
    WHERE existing.table_schema = DATABASE()
      AND existing.table_name = 'eco_master'
      AND existing.column_name = wanted.column_name
);
SET @eco_contact_ddl = IF(@eco_contact_columns IS NULL,
    'SELECT ''Contact columns already exist'' AS message',
    CONCAT('ALTER TABLE `eco_master` ', @eco_contact_columns));
PREPARE eco_contact_statement FROM @eco_contact_ddl;
EXECUTE eco_contact_statement;
DEALLOCATE PREPARE eco_contact_statement;

-- Avoid GROUP_CONCAT truncation when a site contains multiple contacts.
SET @eco_previous_group_concat = @@SESSION.group_concat_max_len;
SET SESSION group_concat_max_len = 65535;
DROP TEMPORARY TABLE IF EXISTS eco_contact_parts;
START TRANSACTION;

-- JSON_QUOTE safely handles quotes/backslashes before splitting on semicolons.
CREATE TEMPORARY TABLE eco_contact_parts AS
SELECT eco_no, role,
       NULLIF(GROUP_CONCAT(contact_name ORDER BY contact_position SEPARATOR ';'), '') AS names,
       NULLIF(GROUP_CONCAT(contact_email ORDER BY contact_position SEPARATOR ';'), '') AS emails
FROM (
    SELECT eco_no, role, contact_position,
           CASE WHEN REGEXP_LIKE(contact, '[(][^()[:space:]]+@[^()[:space:]]+[)]$')
                THEN TRIM(REGEXP_REPLACE(contact, '[[:space:]]*[(][^()[:space:]]+@[^()[:space:]]+[)]$', ''))
                WHEN REGEXP_LIKE(contact, '^[^()[:space:]]+@[^()[:space:]]+$') THEN ''
                ELSE contact END AS contact_name,
           CASE WHEN REGEXP_LIKE(contact, '[(][^()[:space:]]+@[^()[:space:]]+[)]$')
                THEN SUBSTRING_INDEX(SUBSTRING_INDEX(contact, '(', -1), ')', 1)
                WHEN REGEXP_LIKE(contact, '^[^()[:space:]]+@[^()[:space:]]+$') THEN contact
                ELSE '' END AS contact_email
    FROM (
        SELECT source.eco_no, source.role, parts.contact_position,
               TRIM(REGEXP_REPLACE(parts.contact, '[[:space:]]+', ' ')) AS contact
        FROM (
            SELECT eco_no, 'pmc' AS role, pmc_site AS site FROM eco_master
            WHERE pmc_name IS NULL AND pmc_email IS NULL
            UNION ALL
            SELECT eco_no, 'qa', qa_site FROM eco_master
            WHERE qa_name IS NULL AND qa_email IS NULL
        ) AS source
        JOIN JSON_TABLE(
            CONCAT('[', REPLACE(JSON_QUOTE(COALESCE(source.site, '')), ';', '","'), ']'),
            '$[*]' COLUMNS (
                contact_position FOR ORDINALITY,
                contact TEXT PATH '$'
            )
        ) AS parts ON TRUE
    ) AS cleaned
    WHERE contact <> ''
) AS parsed
GROUP BY eco_no, role;

UPDATE eco_master AS master
JOIN eco_contact_parts AS contacts ON contacts.eco_no = master.eco_no AND contacts.role = 'pmc'
SET master.pmc_name = contacts.names, master.pmc_email = contacts.emails,
    master.updated_at = master.updated_at
WHERE master.pmc_name IS NULL AND master.pmc_email IS NULL;

UPDATE eco_master AS master
JOIN eco_contact_parts AS contacts ON contacts.eco_no = master.eco_no AND contacts.role = 'qa'
SET master.qa_name = contacts.names, master.qa_email = contacts.emails,
    master.updated_at = master.updated_at
WHERE master.qa_name IS NULL AND master.qa_email IS NULL;

COMMIT;
DROP TEMPORARY TABLE eco_contact_parts;
SET SESSION group_concat_max_len = @eco_previous_group_concat;

-- Review the separated values.
SELECT eco_no, pmc_name, pmc_email, qa_name, qa_email
FROM eco_master ORDER BY eco_no;
