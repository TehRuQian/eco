<?php
/** SearchResult mapping regression tests. Run: php tests/test_searchresult.php */
require_once __DIR__ . '/../src/ECOProcessor.php';

function check($actual, $expected, string $label): void {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo "[PASS] {$label}\n";
}

// Record the actual SQL and bound values without requiring a production database.
class ImportTestPDO extends PDO {
    public array $master = [];
    public array $tracking = [];
    public array $writes = [];
    public array $columns;
    public string $cutInType = 'datetime';
    private bool $transaction = false;
    public function __construct() {
        $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
        foreach (['eco_master', 'eco_signoff', 'eco_tracking'] as $table) {
            preg_match('/CREATE TABLE IF NOT EXISTS `' . $table . '` \((.*?)\) ENGINE/s', $schema, $match);
            preg_match_all('/^\s*`([^`]+)`/m', $match[1], $columns);
            $this->columns[$table] = $columns[1];
        }
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return new ImportTestStatement($this, $query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ImportTestStatement($this, $query);
    }
    public function exec(string $statement): int|false {
        $this->writes[] = $statement;
        return 0;
    }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function inTransaction(): bool { return $this->transaction; }
}
class ImportTestStatement extends PDOStatement {
    private ImportTestPDO $db;
    private string $sql;
    private array $params = [];
    public function __construct(ImportTestPDO $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(?array $params = null): bool {
        $this->params = $params ?? [];
        if (preg_match('/^INSERT INTO `eco_(master|tracking)` \((.*?)\) VALUES/s', $this->sql, $match)) {
            preg_match_all('/`([^`]+)`/', $match[2], $columns);
            $record = array_combine($columns[1], $this->params);
            $table = $match[1];
            $this->db->{$table}[$record['eco_no']] = $record;
        } elseif (str_starts_with($this->sql, 'UPDATE `eco_master` SET')) {
            preg_match_all('/`([^`]+)` = \?/', $this->sql, $columns);
            $values = $this->params;
            $eco = array_pop($values);
            array_pop($columns[1]); // WHERE eco_no
            $this->db->master[$eco] = array_replace($this->db->master[$eco], array_combine($columns[1], $values));
        }
        if (!str_starts_with($this->sql, 'SELECT')) $this->db->writes[] = $this->sql;
        return true;
    }
    public function fetchColumn(int $column = 0): mixed {
        $table = str_contains($this->sql, '`eco_tracking`') ? 'tracking' : 'master';
        return isset($this->db->{$table}[$this->params[0]]) ? $this->params[0] : false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        preg_match('/SHOW COLUMNS FROM `([^`]+)`/', $this->sql, $match);
        $columns = $this->db->columns[$match[1]];
        if ($mode === PDO::FETCH_ASSOC) {
            return array_map(fn($field) => ['Field' => $field, 'Type' => $field === 'cut_in_date' ? $this->db->cutInType : 'varchar(100)'], $columns);
        }
        return $columns;
    }
}

check(ECOProcessor::normalizeImportStatus(' reviewer1 # No Controller '), 'Reviewer 1', 'Normalize reviewer status for ENUM');
check(ECOProcessor::normalizeImportStatus('COMPLETE'), 'Complete', 'Canonical Complete status');
check(ECOProcessor::normalizeImportStatus('# No Controller'), null, 'Standalone controller marker is skipped');
check(ECOProcessor::normalizeImportStatus(' #no   controller '), null, 'Standalone marker tolerates case and whitespace');
check(ECOProcessor::normalizeImportStatus('Reviewer 2 # No Controller'), 'Reviewer 2', 'Controller suffix preserves reviewer stage');
foreach (['Open', 'Closed', 'Reviewer 3'] as $invalidStatus) {
    try {
        ECOProcessor::normalizeImportStatus($invalidStatus, 'SearchResult row 2 (ECO001)');
        throw new RuntimeException('Unsupported status was accepted: ' . $invalidStatus);
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'SearchResult row 2 (ECO001)'), true, 'Invalid status identifies source record');
    }
}
check(ECOProcessor::splitSiteContacts(null), ['name' => null, 'email' => null], 'Empty contacts');
check(ECOProcessor::splitSiteContacts('Alice (alice@example.com); Bob (bob@example.com)'),
    ['name' => 'Alice;Bob', 'email' => 'alice@example.com;bob@example.com'], 'Multiple contacts keep matching order');
check(ECOProcessor::splitSiteContacts('Alice;Bob (bob@example.com)'),
    ['name' => 'Alice;Bob', 'email' => ';bob@example.com'], 'Missing email keeps its position');
check(ECOProcessor::splitSiteContacts('alice@example.com'),
    ['name' => null, 'email' => 'alice@example.com'], 'Email-only contact');
check(ECOProcessor::splitSiteContacts('PMC'), ['name' => 'PMC', 'email' => null], 'Name-only contact');
$labels = ECOProcessor::withSiteLabels([
    'pmc_name' => 'Alice;Bob', 'pmc_email' => ';bob@example.com',
    'qa_name' => null, 'qa_email' => 'qa@example.com',
]);
check($labels['pmc_site'], 'Alice;Bob (bob@example.com)', 'Display preserves multiple contact positions');
check($labels['qa_site'], 'qa@example.com', 'Display supports email-only contacts');
check(ECOProcessor::withSiteLabels([])['pmc_site'], '', 'Display supports missing contacts');
$headers = [
    'Final Complete Date', 'unrelated cut in date notes', 'Change Number',
    " c - How to deal with the warehouse\xC2\xA0 demand ( Page Three ) ",
    '*Cut in 1st M / O(Page Three)', 'Status', ' b-How to deal with the FG (Page Three) ',
    'QA_Site', ' * Cut in Date (Page Three) ', 'Date Originated',
    " a - How to deal with the WIP\n(Page Three). ", 'PMC_Site'
];
$values = ['2026/10/15', 'must not map', ' eco001 ', 'No Impact', 'MO123456', 'Controller',
    'No impact, new build, no FG.', 'QA', '2026/10/14', '2026-10-01',
    "No impact, new build, no WIP.\nKeep  spacing", 'PMC'];
$values[7] = 'Alice (alice@example.com);Bob (bob@example.com)';
$values[11] = 'Charlie (charlie@example.com)';
$files = [];
try {
    foreach (['csv', 'xlsx', 'xls'] as $extension) {
        $file = tempnam(sys_get_temp_dir(), 'eco_mapping_');
        $files[] = $file;
        $skipped = $values;
        $skipped[2] = 'ECO_SKIPPED';
        $skipped[5] = '# No Controller';
        $rows = [$headers, $values, $skipped];
        if ($extension === 'csv') {
            $handle = fopen($file, 'w');
            foreach ($rows as $row) fputcsv($handle, $row, ',', '"', '');
            fclose($handle);
        } else {
            $sheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet->getActiveSheet()->fromArray($rows, null, 'A1');
            PhpOffice\PhpSpreadsheet\IOFactory::createWriter($sheet, $extension === 'xlsx' ? 'Xlsx' : 'Xls')->save($file);
            $sheet->disconnectWorksheets();
        }
        $db = new ImportTestPDO();
        $processor = new ECOProcessor($db);
        $validation = $processor->preFlightCheck($file, 'SearchResult.' . $extension);
        check($validation['valid'], true, "$extension validation");
        foreach (['completed_date' => 0, 'warehouse_action' => 3, 'cut_in_first_mo' => 4,
            'fg_action' => 6, 'cut_in_date' => 8, 'wip_action' => 10] as $field => $index) {
            check($validation['master_map'][$field] ?? null, $index, "$extension $field column");
        }
        check($validation['info']['warnings'], [], "$extension all six detected");
        $result = $processor->processImport($file, 'SearchResult.' . $extension);
        check($result['success'], true, "$extension insert succeeds");
        check($result['stats']['master_inserted'], 1, "$extension new ECO");
        check(isset($db->master['ECO_SKIPPED']), false, "$extension blank cleaned status row skipped");
        $record = $db->master['ECO001'];
        check(array_key_exists('pmc_site', $record), false, "$extension stores PMC without legacy column");
        check(array_key_exists('qa_site', $record), false, "$extension stores QA without legacy column");
        foreach (['pmc_name' => 'Charlie', 'pmc_email' => 'charlie@example.com',
            'qa_name' => 'Alice;Bob', 'qa_email' => 'alice@example.com;bob@example.com',
            'pmc_site' => $values[11], 'qa_site' => $values[7]] as $field => $expected) {
            check(ECOProcessor::withSiteLabels($record)[$field], $expected, "$extension contact field $field");
        }
        foreach (['wip_action' => $values[10], 'fg_action' => $values[6], 'warehouse_action' => 'No Impact',
            'cut_in_first_mo' => 'MO123456', 'cut_in_date' => '2026-10-14 00:00:00',
            'completed_date' => '2026-10-15 00:00:00'] as $field => $expected) {
            check($record[$field], $expected, "$extension inserted $field");
        }
        $db->tracking['ECO001'] = ['eco_no' => 'ECO001', 'manual_cut_in_first_mo' => 'MANUAL', 'due_date' => '2027-01-01', 'pmc_result_completed' => 1];
        $tracking = $db->tracking;
        foreach (['wip_action', 'fg_action', 'warehouse_action', 'cut_in_first_mo'] as $field) {
            $db->master['ECO001'][$field] = 'old value';
        }
        $db->master['ECO001']['cut_in_date'] = '2020-01-01 00:00:00';
        $db->master['ECO001']['completed_date'] = '2020-01-02 00:00:00';
        $result = $processor->processImport($file, 'SearchResult.' . $extension);
        check($result['stats']['master_updated'], 1, "$extension existing ECO updated");
        check($db->master['ECO001'], $record, "$extension re-import updates all master fields");
        check($db->tracking, $tracking, "$extension manual tracking preserved");
    }

    $file = tempnam(sys_get_temp_dir(), 'eco_blank_');
    $files[] = $file;
    $handle = fopen($file, 'w');
    fputcsv($handle, $headers, ',', '"', '');
    $values[0] = ''; $values[3] = 'running change'; $values[6] = 'NA'; $values[8] = '2026-10-14'; $values[10] = '';
    fputcsv($handle, $values, ',', '"', '');
    fflush($handle);
    $runningDb = new ImportTestPDO();
    (new ECOProcessor($runningDb))->processImport($file, 'SearchResult.csv');
    check($runningDb->master['ECO001']['warehouse_action'], 'running change', 'running change preserved');
    $values[3] = 'No Impact'; // Same normalized ECO: last row wins.
    fputcsv($handle, $values, ',', '"', '');
    fclose($handle);
    $db = new ImportTestPDO();
    $result = (new ECOProcessor($db))->processImport($file, 'SearchResult.csv');
    check($result['stats']['master_inserted'], 1, 'duplicate ECO last row wins');
    check($db->master['ECO001']['warehouse_action'], 'No Impact', 'duplicate value');
    check($db->master['ECO001']['wip_action'], '', 'blank action');
    check($db->master['ECO001']['fg_action'], 'NA', 'NA preserved');
    check($db->master['ECO001']['completed_date'], null, 'blank date');
    check($db->master['ECO001']['cut_in_date'], '2026-10-14 00:00:00', 'ISO date');
    $db->master['ECO001']['wip_action'] = 'previous WIP action';
    $db->master['ECO001']['completed_date'] = '2020-01-01 00:00:00';
    $result = (new ECOProcessor($db))->processImport($file, 'SearchResult.csv');
    check($result['stats']['master_updated'], 1, 'latest CSV updates existing ECO');
    check($db->master['ECO001']['wip_action'], '', 'latest blank action clears old value');
    check($db->master['ECO001']['completed_date'], null, 'latest blank date clears old value');
    check(ECOProcessor::parseAgileDate(46309)->format('Y-m-d'), '2026-10-14', 'Excel serial date');
    check(ECOProcessor::parseAgileDate('10/14/2026')->format('Y-m-d'), '2026-10-14', 'US date');

    // TEXT column: preserve dates and non-date content on insert and re-import.
    foreach (['2026/10/14', 'NA', 'running change', ''] as $textValue) {
        $handle = fopen($file, 'w');
        fputcsv($handle, $headers, ',', '"', '');
        $values[8] = $textValue;
        fputcsv($handle, $values, ',', '"', '');
        fclose($handle);
        $textDb = new ImportTestPDO();
        $textDb->cutInType = 'text';
        $processor = new ECOProcessor($textDb);
        $result = $processor->processImport($file, 'SearchResult.csv');
        check($result['success'], true, 'TEXT Cut in Date import succeeds');
        check($textDb->master['ECO001']['cut_in_date'], $textValue, 'TEXT Cut in Date preserves uploaded value');
        $textDb->master['ECO001']['cut_in_date'] = 'old';
        $result = $processor->processImport($file, 'SearchResult.csv');
        check($result['stats']['master_updated'], 1, 'TEXT Cut in Date re-import updates');
        check($textDb->master['ECO001']['cut_in_date'], $textValue, 'TEXT Cut in Date replaces old value');
    }

    $handle = fopen($file, 'w');
    fputcsv($handle, ['Change Number', 'Status', 'PMC_Site', 'QA_Site', 'Date Originated'], ',', '"', '');
    fputcsv($handle, ['ECO002', 'Controller', 'PMC', 'QA', '2026-10-14'], ',', '"', '');
    fclose($handle);
    $validation = (new ECOProcessor(null))->preFlightCheck($file, 'SearchResult.csv');
    check($validation['valid'], true, 'legacy headers remain supported');
    check(count($validation['info']['warnings']), 6, 'six missing columns reported gracefully');
    check(count(array_filter($db->writes, fn($sql) => str_starts_with($sql, 'ALTER TABLE'))), 0, 'existing schema reused');
    echo "All SearchResult regression tests passed.\n";
} finally {
    foreach ($files as $file) unlink($file);
}