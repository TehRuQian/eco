<?php
/** Run: php database/migrate_contacts.php */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this migration from the command line.');
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/ECOProcessor.php';
if (!$pdo) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}
try {
    // MySQL DDL must finish before the backfill transaction.
    $columns = $pdo->query('SHOW COLUMNS FROM eco_master')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['pmc_name', 'pmc_email', 'qa_name', 'qa_email'] as $column) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE eco_master ADD COLUMN `{$column}` TEXT NULL");
        }
    }
    $pdo->beginTransaction();
    $rows = $pdo->query('SELECT eco_no, pmc_site, qa_site, pmc_name, pmc_email, qa_name, qa_email
        FROM eco_master WHERE (pmc_name IS NULL AND pmc_email IS NULL)
        OR (qa_name IS NULL AND qa_email IS NULL) FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $row) {
        foreach (['pmc', 'qa'] as $role) {
            if ($row[$role . '_name'] !== null || $row[$role . '_email'] !== null) continue;
            $contact = ECOProcessor::splitSiteContacts($row[$role . '_site']);
            if ($contact['name'] === null && $contact['email'] === null) continue;
            $statement = $pdo->prepare("UPDATE eco_master SET `{$role}_name` = ?, `{$role}_email` = ?,
                updated_at = updated_at WHERE eco_no = ?");
            $statement->execute([$contact['name'], $contact['email'], $row['eco_no']]);
            $count++;
        }
    }
    $pdo->commit();
    echo "Contact migration complete: {$count} PMC/QA contact groups populated.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Contact migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
