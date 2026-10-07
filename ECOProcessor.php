<?php
/**
 * ECOProcessor.php
 * Core business engine for the Automated ECO Tracker System.
 *
 * Handles:
 * 1. File ingestion (SearchResult: XLS/XLSX/CSV, user_signoff: XLS/XLSX/CSV)
 * 2. Pre-flight column validation
 * 3. ECO number normalization: strtoupper(trim($eco_no))
 * 4. Status cleaning (removes '# No Controller')
 * 5. Agile date parsing (handles 2025/09/02 10:34:40 AM CST and standard formats)
 * 6. Thursday-based work week (WW) calculation
 * 7. Due date calculation (Originated Date + 14 calendar days)
 * 8. Status change detection (previous_internal_status vs internal_status)
 * 9. Creates ECO records for unmatched signoff entries
 * 10. Manual tracking data preservation on re-import
 * 11. Transaction integrity (all-or-nothing rollback)
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ECOProcessor
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inspects and safely updates the database schema if tables or columns are missing.
     * Prevents SQL errors when running against pre-existing tables.
     */
    public static function ensureSchema(?PDO $pdo): array
    {
        if (!$pdo) {
            return ['success' => false, 'message' => 'No PDO connection'];
        }

        $actions = [];
        $getTableColumns = function(string $table) use ($pdo): array {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {
                return [];
            }
        };

        // 1. Check eco_master table
        $masterCols = $getTableColumns('eco_master');
        if (empty($masterCols)) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `eco_master` (
                        `eco_no` VARCHAR(100) NOT NULL PRIMARY KEY,
                        `ecr_no` VARCHAR(100) NULL,
                        `customer` VARCHAR(150) NULL,
                        `project` VARCHAR(150) NULL,
                        `eco_implementation_method` VARCHAR(100) NULL,
                        `ec_type_category` VARCHAR(100) NULL,
                        `status` VARCHAR(100) NULL,
                        `subject` TEXT NULL,
                        `status_in_agile` VARCHAR(100) NULL,
                        `wip_action` VARCHAR(100) NULL,
                        `fg_action` VARCHAR(100) NULL,
                        `warehouse_action` VARCHAR(100) NULL,
                        `cut_in_first_mo` VARCHAR(100) NULL,
                        `cut_in_date` DATETIME NULL,
                        `pmc_site` VARCHAR(150) NULL,
                        `qa_site` VARCHAR(150) NULL,
                        `originated_date` DATETIME NULL,
                        `completed_date` DATETIME NULL,
                        `year` INT NULL,
                        `ww` INT NULL,
                        `month` VARCHAR(20) NULL,
                        `created_at` DATETIME NULL,
                        `updated_at` DATETIME NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $actions[] = "Created table eco_master";
            } catch (Throwable $e) {}
        } else {
            $requiredMaster = [
                'cut_in_first_mo' => "VARCHAR(100) NULL",
                'cut_in_date'     => "DATETIME NULL",
                'pmc_site'        => "VARCHAR(150) NULL",
                'qa_site'         => "VARCHAR(150) NULL",
                'originated_date' => "DATETIME NULL",
                'completed_date'  => "DATETIME NULL",
                'created_at'      => "DATETIME NULL",
                'updated_at'      => "DATETIME NULL",
            ];
            foreach ($requiredMaster as $col => $colDef) {
                if (!in_array($col, $masterCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE `eco_master` ADD COLUMN `{$col}` {$colDef}");
                        $actions[] = "Added missing column `{$col}` to eco_master";
                    } catch (Throwable $e) {
                        error_log("Schema update warning (eco_master.{$col}): " . $e->getMessage());
                    }
                }
            }
        }

        // 2. Check eco_signoff table
        $signoffCols = $getTableColumns('eco_signoff');
        if (empty($signoffCols)) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `eco_signoff` (
                        `eco_no` VARCHAR(100) NOT NULL PRIMARY KEY,
                        `internal_status` VARCHAR(100) NULL,
                        `previous_internal_status` VARCHAR(100) NULL,
                        `status_changed` TINYINT(1) NOT NULL DEFAULT 0,
                        `status_changed_at` DATETIME NULL,
                        `is_unmatched` TINYINT(1) NOT NULL DEFAULT 0,
                        `created_at` DATETIME NULL,
                        `updated_at` DATETIME NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $actions[] = "Created table eco_signoff";
            } catch (Throwable $e) {}
        } else {
            $requiredSignoff = [
                'internal_status'          => "VARCHAR(100) NULL",
                'previous_internal_status' => "VARCHAR(100) NULL",
                'status_changed'           => "TINYINT(1) NOT NULL DEFAULT 0",
                'status_changed_at'        => "DATETIME NULL",
                'is_unmatched'             => "TINYINT(1) NOT NULL DEFAULT 0",
                'created_at'               => "DATETIME NULL",
                'updated_at'               => "DATETIME NULL",
            ];
            foreach ($requiredSignoff as $col => $colDef) {
                if (!in_array($col, $signoffCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE `eco_signoff` ADD COLUMN `{$col}` {$colDef}");
                        $actions[] = "Added missing column `{$col}` to eco_signoff";
                    } catch (Throwable $e) {
                        error_log("Schema update warning (eco_signoff.{$col}): " . $e->getMessage());
                    }
                }
            }
        }

        // 3. Check eco_tracking table
        $trackingCols = $getTableColumns('eco_tracking');
        if (empty($trackingCols)) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `eco_tracking` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `eco_no` VARCHAR(100) NOT NULL UNIQUE,
                        `manual_cut_in_first_mo` TEXT NULL,
                        `type_of_changes` VARCHAR(100) NULL,
                        `pmc_result_completed` TINYINT(1) NOT NULL DEFAULT 0,
                        `qa_result_completed` TINYINT(1) NOT NULL DEFAULT 0,
                        `first_mo_result` VARCHAR(100) NULL,
                        `due_date` DATE NULL,
                        `status_progress` VARCHAR(50) NOT NULL DEFAULT 'Pending PMC',
                        `impact_assessment_checklist` TEXT NULL,
                        `pending_checklist` TEXT NULL,
                        `updated_by` VARCHAR(100) NULL,
                        `updated_date` DATETIME NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $actions[] = "Created table eco_tracking";
            } catch (Throwable $e) {}
        } else {
            if (in_array('pme_result_completed', $trackingCols, true) && !in_array('pmc_result_completed', $trackingCols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `eco_tracking` CHANGE COLUMN `pme_result_completed` `pmc_result_completed` TINYINT(1) NOT NULL DEFAULT 0");
                    $actions[] = "Renamed column pme_result_completed to pmc_result_completed";
                } catch (Throwable $e) {}
            }

            $requiredTracking = [
                'manual_cut_in_first_mo'      => "TEXT NULL",
                'type_of_changes'             => "VARCHAR(100) NULL",
                'pmc_result_completed'        => "TINYINT(1) NOT NULL DEFAULT 0",
                'qa_result_completed'         => "TINYINT(1) NOT NULL DEFAULT 0",
                'first_mo_result'             => "VARCHAR(100) NULL",
                'due_date'                    => "DATE NULL",
                'status_progress'             => "VARCHAR(50) NOT NULL DEFAULT 'Pending PMC'",
                'impact_assessment_checklist' => "TEXT NULL",
                'pending_checklist'           => "TEXT NULL",
                'updated_by'                  => "VARCHAR(100) NULL",
                'updated_date'                => "DATETIME NULL",
            ];
            foreach ($requiredTracking as $col => $colDef) {
                if (!in_array($col, $trackingCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE `eco_tracking` ADD COLUMN `{$col}` {$colDef}");
                        $actions[] = "Added missing column `{$col}` to eco_tracking";
                    } catch (Throwable $e) {
                        error_log("Schema update warning (eco_tracking.{$col}): " . $e->getMessage());
                    }
                }
            }
        }

        // 4. Ensure initial tracking records exist for existing eco_master rows
        try {
            $currentTrackingCols = $getTableColumns('eco_tracking');
            $pmcCol = in_array('pmc_result_completed', $currentTrackingCols, true) ? 'pmc_result_completed' : (in_array('pme_result_completed', $currentTrackingCols, true) ? 'pme_result_completed' : null);
            if ($pmcCol && in_array('qa_result_completed', $currentTrackingCols, true) && in_array('status_progress', $currentTrackingCols, true)) {
                $pdo->exec("
                    INSERT IGNORE INTO `eco_tracking` (`eco_no`, `{$pmcCol}`, `qa_result_completed`, `status_progress`)
                    SELECT `eco_no`, 0, 0, 'Pending PMC' FROM `eco_master`
                ");
            }
        } catch (Throwable $e) {}

        return ['success' => true, 'actions' => $actions];
    }

    /**
     * Clean and normalize raw strings
     */
    public static function cleanText(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        // Remove UTF-8 BOM if present
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        // Replace non-breaking space
        $value = str_replace("\xC2\xA0", " ", $value);
        // Normalize Unicode spaces
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }

    /**
     * Main ECO Number Normalizer
     * Rule: strtoupper(trim($eco_no))
     */
    public static function normalizeEcoNo(?string $ecoNo): string
    {
        return strtoupper(self::cleanText($ecoNo));
    }

    /**
     * Only actual ECO identifiers are eligible for import.
     */
    public static function isEcoNumber(?string $ecoNo): bool
    {
        return str_starts_with(self::normalizeEcoNo($ecoNo), 'ECO');
    }

    /**
     * Agile Status Cleaner
     * Rule: Remove '# No Controller'
     */
    public static function cleanStatus(?string $rawStatus): string
    {
        $cleaned = str_ireplace('# No Controller', '', (string)$rawStatus);
        return self::cleanText($cleaned);
    }

    /**
     * Derive Agile status from the reviewer status.
     */
    public static function statusToAgileStatus(?string $status): string
    {
        return strcasecmp(self::cleanStatus($status), 'Complete') === 0 ? 'Closed' : 'Open';
    }

    /**
     * Parse Agile PLM dates safely into DateTime object
     * Handles formats like "2025/09/02 10:34:40 AM CST" or Excel serial floats
     */
    public static function parseAgileDate($rawDate): ?DateTime
    {
        if ($rawDate === null || $rawDate === '') {
            return null;
        }

        // If numeric and looks like an Excel timestamp
        if (is_numeric($rawDate) && (float)$rawDate > 20000 && (float)$rawDate < 70000) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float)$rawDate);
                return $dt;
            } catch (Throwable $t) {
                // fall through to text parser
            }
        }

        $str = self::cleanText((string)$rawDate);
        if ($str === '' || $str === '-' || $str === 'N/A' || $str === 'null') {
            return null;
        }

        // Strip known trailing timezone tags (CST, PST, EST, UTC, etc.)
        $strClean = preg_replace('/\s+(CST|CDT|PST|PDT|EST|EDT|UTC|GMT|SGT|MYT)$/i', '', $str);

        // Try direct DateTime constructor
        try {
            return new DateTime($strClean);
        } catch (Throwable $e) {
            // Try specific common formats
            $formats = [
                'Y/m/d h:i:s A', 'Y/m/d H:i:s', 'Y-m-d H:i:s', 'Y/m/d', 'Y-m-d',
                'm/d/Y h:i:s A', 'm/d/Y H:i:s', 'm/d/Y', 'd/m/Y H:i:s', 'd/m/Y'
            ];
            foreach ($formats as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $strClean);
                if ($dt !== false) {
                    return $dt;
                }
            }
        }

        return null;
    }

    /**
     * Thursday-based Work Week Calculation
     * Formula: Adjusted Date = Date Originated + 3 days
     * WW = ISO Week Number of Adjusted Date
     */
    public static function calculateWorkWeek(DateTime $originatedDate): int
    {
        $adjustedDate = (clone $originatedDate)->modify('+3 days');
        return (int)$adjustedDate->format('W');
    }

    /**
     * Due Date Calculation
     * Formula: Date Originated + 14 calendar days
     */
    public static function calculateDueDate(DateTime $originatedDate): string
    {
        $due = (clone $originatedDate)->modify('+14 days');
        return $due->format('Y-m-d');
    }

    /**
     * Status Progress Logic
     * | PMC | QA | status_progress |
     * | No  | No | Pending PMC     |
     * | Yes | No | Pending QA      |
     * | No  | Yes| Pending PMC     |
     * | Yes | Yes| Completed       |
     */
    public static function calculateStatusProgress($pmcCompleted, $qaCompleted): string
    {
        $pmc = !empty($pmcCompleted);
        $qa  = !empty($qaCompleted);

        if ($pmc && $qa) {
            return 'Completed';
        }
        if ($pmc && !$qa) {
            return 'Pending QA';
        }
        return 'Pending PMC';
    }

    /**
     * Normalize header name for reliable mapping
     */
    private static function normalizeHeader(string $header): string
    {
        $h = self::cleanText($header);
        $h = strtolower($h);
        $h = str_replace(['_', '-', '.', '/', '\\'], ' ', $h);
        $h = preg_replace('/\s+/', ' ', $h);
        return trim($h);
    }

    /**
     * Read any supported file (.xlsx, .xls, .csv) into an array of rows
     */
    public function readFileRows(string $filePath, string $originalName = ''): array
    {
        $ext = strtolower(pathinfo($originalName ?: $filePath, PATHINFO_EXTENSION));

        // If CSV, try fast fgetcsv first with delimiter detection
        if ($ext === 'csv') {
            $rows = $this->readCsvFile($filePath);
            if (!empty($rows)) {
                return $rows;
            }
        }

        // Use PhpSpreadsheet for Excel or fallback
        $readerType = IOFactory::identify($filePath);
        $reader = IOFactory::createReader($readerType);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows ?: [];
    }

    /**
     * Read CSV with automatic delimiter detection and UTF-8 handling
     */
    private function readCsvFile(string $filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        // Read first line to detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = ',';
        if ($firstLine !== false) {
            $commaCount = substr_count($firstLine, ',');
            $tabCount   = substr_count($firstLine, "\t");
            $semiCount  = substr_count($firstLine, ';');
            if ($tabCount > $commaCount && $tabCount > $semiCount) {
                $delimiter = "\t";
            } elseif ($semiCount > $commaCount && $semiCount > $tabCount) {
                $delimiter = ';';
            }
        }

        while (($data = fgetcsv($handle, 20000, $delimiter, '"', "\\")) !== false) {
            // Convert to UTF-8 if necessary
            $data = array_map(function($v) {
                if ($v === null) return '';
                return mb_convert_encoding((string)$v, 'UTF-8', 'auto');
            }, $data);
            $rows[] = $data;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Find header row and map columns in raw spreadsheet rows
     */
    private function findHeaderRow(array $rows, array $aliasesMap, int $maxSearchRows = 100): ?array
    {
        $limit = min($maxSearchRows, count($rows));
        for ($r = 0; $r < $limit; $r++) {
            $rawRow = $rows[$r] ?? [];
            if (!is_array($rawRow)) continue;

            $normalizedRow = array_map(fn($v) => self::normalizeHeader((string)$v), $rawRow);

            $matched = [];
            foreach ($aliasesMap as $standardKey => $aliases) {
                foreach ($normalizedRow as $colIdx => $colName) {
                    if ($colName === '') continue;
                    foreach ($aliases as $alias) {
                        if ($colName === $alias || strpos($colName, $alias) !== false) {
                            $matched[$standardKey] = $colIdx;
                            break 2;
                        }
                    }
                }
            }

            // Check if minimum required keys found
            $hasEcoNo = isset($matched['eco_no']);
            $hasStatus = isset($matched['status']) || isset($matched['internal_status']);
            if ($hasEcoNo && ($hasStatus || count($matched) >= 3)) {
                return [
                    'header_index' => $r,
                    'column_map'   => $matched,
                ];
            }
        }
        return null;
    }

    /**
     * Pre-Flight Validation for SearchResult and user_signoff files
     */
    public function preFlightCheck(
        ?string $searchResultPath,
        ?string $searchResultName,
        ?string $signoffPath = null,
        ?string $signoffName = null
    ): array {
        $errors = [];
        $info   = [];

        // Aliases definitions for SearchResult
        $masterAliases = [
            'eco_no'            => ['change number', 'change no', 'eco no', 'eco number', 'change_number'],
            'status'            => ['status', 'agile status'],
            'pmc_site'          => ['pmc site', 'pmc_site', 'pmc approver', 'pmc'],
            'qa_site'           => ['qa site', 'qa_site', 'qa approver', 'qa'],
            'originated_date'   => ['date originated', 'originated date', 'date_originated', 'created date'],
            'ecr_no'            => ['ecr no', 'ecr number', 'ecr_no'],
            'customer'          => ['customer', 'customer name', 'brand'],
            'project'           => ['project', 'project name'],
            'change_method'     => ['change method', 'eco implementation method', 'implementation method'],
            'ec_type_category'  => ['ec type & category', 'ec type category', 'type category'],
            'subject'           => ['subject', 'description'],
            'status_in_agile'   => ['status in agile', 'agile status'],
            'wip_action'        => ['wip action'],
            'fg_action'         => ['fg action'],
            'warehouse_action'  => ['warehouse action'],
            'cut_in_first_mo'   => ['cut in 1st m o', 'cut in 1st mo', 'first mo'],
            'cut_in_date'       => ['cut in date'],
            'completed_date'    => ['final complete date', 'completed date', 'complete date']
        ];

        // Required headers for SearchResult
        $requiredMaster = ['eco_no', 'status', 'pmc_site', 'qa_site', 'originated_date'];

        $masterFound = [];
        $masterHeaderIdx = null;

        if ($searchResultPath && file_exists($searchResultPath)) {
            try {
                $rows = $this->readFileRows($searchResultPath, $searchResultName ?? '');
                $detect = $this->findHeaderRow($rows, $masterAliases);
                if (!$detect) {
                    $errors[] = "SearchResult: Header row could not be identified within first 100 rows.";
                } else {
                    $masterFound = $detect['column_map'];
                    $masterHeaderIdx = $detect['header_index'];

                    foreach ($requiredMaster as $req) {
                        if (!isset($masterFound[$req])) {
                            $readable = ucwords(str_replace('_', ' ', $req));
                            $errors[] = "SearchResult missing required column: '{$readable}' (e.g. Change Number, Status, PMC_Site, QA_Site, Date Originated).";
                        }
                    }

                    $dataRowCount = max(0, count($rows) - ($masterHeaderIdx + 1));
                    $info['search_result_rows'] = $dataRowCount;
                }
            } catch (Throwable $e) {
                $errors[] = "SearchResult read error: " . $e->getMessage();
            }
        } else {
            $errors[] = "SearchResult file is missing or not readable.";
        }

        // Aliases definitions for user_signoff
        $signoffAliases = [
            'eco_no'             => ['change number', 'change no', 'eco no', 'eco number', 'change_number'],
            'internal_status'    => ['status', 'internal status', 'signoff status'],
        ];

        $requiredSignoff = ['eco_no', 'internal_status'];
        $signoffFound = [];

        if ($signoffPath && file_exists($signoffPath)) {
            try {
                $rows = $this->readFileRows($signoffPath, $signoffName ?? '');
                $detect = $this->findHeaderRow($rows, $signoffAliases);
                if (!$detect) {
                    $errors[] = "user_signoff: Header row could not be identified within first 100 rows. Expected Change Number and Status columns on the same row.";
                } else {
                    $signoffFound = $detect['column_map'];
                    $signoffHeaderIdx = $detect['header_index'];

                    foreach ($requiredSignoff as $req) {
                        if (!isset($signoffFound[$req])) {
                            $readable = ucwords(str_replace('_', ' ', $req));
                            $errors[] = "user_signoff missing required column: '{$readable}' (e.g. Change Number, Status).";
                        }
                    }

                    $dataRowCount = max(0, count($rows) - ($signoffHeaderIdx + 1));
                    $info['signoff_rows'] = $dataRowCount;
                }
            } catch (Throwable $e) {
                $errors[] = "user_signoff read error: " . $e->getMessage();
            }
        }

        return [
            'valid'              => empty($errors),
            'errors'             => $errors,
            'info'               => $info,
            'master_map'         => $masterFound,
            'master_header_idx'  => $masterHeaderIdx ?? 0,
            'signoff_map'        => $signoffFound,
            'signoff_header_idx' => $signoffHeaderIdx ?? 0,
        ];
    }

    /**
     * Main Processing Pipeline inside a Database Transaction
     */
    public function processImport(
        string $searchResultPath,
        string $searchResultName,
        ?string $signoffPath = null,
        ?string $signoffName = null,
        string $importedBy = 'System'
    ): array {
        if (!$this->pdo) {
            throw new RuntimeException("Database connection is not available.");
        }

        // 1. Run Pre-Flight Validation
        $validation = $this->preFlightCheck($searchResultPath, $searchResultName, $signoffPath, $signoffName);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors'  => $validation['errors'],
                'message' => 'Pre-flight validation failed. No data was imported.'
            ];
        }

        $masterMap  = $validation['master_map'];
        $signoffMap = $validation['signoff_map'];

        // Read SearchResult rows
        $searchRows = $this->readFileRows($searchResultPath, $searchResultName);
        $searchHeaderIdx = $validation['master_header_idx'] ?? 0;

        // Parse Master records keyed by normalized eco_no
        $masterData = [];
        for ($i = $searchHeaderIdx + 1; $i < count($searchRows); $i++) {
            $row = $searchRows[$i];
            if (!is_array($row) || empty($row)) continue;

            $rawEco = $row[$masterMap['eco_no']] ?? null;
            $ecoNo  = self::normalizeEcoNo($rawEco);
            if (!self::isEcoNumber($ecoNo)) continue;

            // Dates & Derived WW/Due Date
            $rawOriginated = $row[$masterMap['originated_date']] ?? null;
            $dtOriginated  = self::parseAgileDate($rawOriginated);

            $rawCompleted  = isset($masterMap['completed_date']) ? ($row[$masterMap['completed_date']] ?? null) : null;
            $dtCompleted   = self::parseAgileDate($rawCompleted);

            $rawCutIn      = isset($masterMap['cut_in_date']) ? ($row[$masterMap['cut_in_date']] ?? null) : null;
            $dtCutIn       = self::parseAgileDate($rawCutIn);

            $year  = $dtOriginated ? (int)$dtOriginated->format('Y') : null;
            $month = $dtOriginated ? $dtOriginated->format('M') : null;
            $ww    = $dtOriginated ? self::calculateWorkWeek($dtOriginated) : null;
            $due   = $dtOriginated ? self::calculateDueDate($dtOriginated) : null;

            // Raw Status & Cleaned Status (without # No Controller)
            $rawStatus = (string)($row[$masterMap['status']] ?? '');
            $cleanStatus = self::cleanStatus($rawStatus);

            $statusInAgile = self::statusToAgileStatus($cleanStatus);

            // PMC & QA sites directly from SearchResult
            $pmcSite = self::cleanText((string)($row[$masterMap['pmc_site']] ?? ''));
            $qaSite  = self::cleanText((string)($row[$masterMap['qa_site']] ?? ''));

            $masterData[$ecoNo] = [
                'eco_no'                     => $ecoNo,
                'ecr_no'                     => isset($masterMap['ecr_no']) ? self::cleanText((string)($row[$masterMap['ecr_no']] ?? '')) : null,
                'customer'                   => isset($masterMap['customer']) ? self::cleanText((string)($row[$masterMap['customer']] ?? '')) : null,
                'project'                    => isset($masterMap['project']) ? self::cleanText((string)($row[$masterMap['project']] ?? '')) : null,
                'eco_implementation_method'  => isset($masterMap['change_method']) ? self::cleanText((string)($row[$masterMap['change_method']] ?? '')) : null,
                'ec_type_category'           => isset($masterMap['ec_type_category']) ? self::cleanText((string)($row[$masterMap['ec_type_category']] ?? '')) : null,
                'status'                     => $cleanStatus,
                'subject'                    => isset($masterMap['subject']) ? self::cleanText((string)($row[$masterMap['subject']] ?? '')) : null,
                'status_in_agile'            => $statusInAgile,
                'wip_action'                 => isset($masterMap['wip_action']) ? self::cleanText((string)($row[$masterMap['wip_action']] ?? '')) : null,
                'fg_action'                  => isset($masterMap['fg_action']) ? self::cleanText((string)($row[$masterMap['fg_action']] ?? '')) : null,
                'warehouse_action'           => isset($masterMap['warehouse_action']) ? self::cleanText((string)($row[$masterMap['warehouse_action']] ?? '')) : null,
                'cut_in_first_mo'            => isset($masterMap['cut_in_first_mo']) ? self::cleanText((string)($row[$masterMap['cut_in_first_mo']] ?? '')) : null,
                'cut_in_date'                => $dtCutIn ? $dtCutIn->format('Y-m-d H:i:s') : null,
                'pmc_site'                   => $pmcSite,
                'qa_site'                    => $qaSite,
                'originated_date'            => $dtOriginated ? $dtOriginated->format('Y-m-d H:i:s') : null,
                'completed_date'             => $dtCompleted ? $dtCompleted->format('Y-m-d H:i:s') : null,
                'year'                       => $year,
                'ww'                         => $ww,
                'month'                      => $month,
                'calculated_due_date'        => $due,
            ];
        }

        // Parse Signoff rows if provided
        $signoffData = [];
        if ($signoffPath && file_exists($signoffPath)) {
            $signRows = $this->readFileRows($signoffPath, $signoffName ?? '');
            $signHeaderIdx = $validation['signoff_header_idx'] ?? 0;

            for ($j = $signHeaderIdx + 1; $j < count($signRows); $j++) {
                $row = $signRows[$j];
                if (!is_array($row) || empty($row)) continue;

                $rawEco = $row[$signoffMap['eco_no']] ?? null;
                $ecoNo  = self::normalizeEcoNo($rawEco);
                if (!self::isEcoNumber($ecoNo)) continue;

                $rawInternalStatus = (string)($row[$signoffMap['internal_status']] ?? '');
                $internalStatus    = self::cleanStatus($rawInternalStatus);
                // Signoff represents the latest signoff record for each ECO
                $signoffData[$ecoNo] = [
                    'eco_no'             => $ecoNo,
                    'internal_status'    => $internalStatus,
                ];
            }
        }

        // Ensure schema is updated before beginning import
        self::ensureSchema($this->pdo);

        $getTableColumns = function(string $table): array {
            try {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
                return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {
                return [];
            }
        };

        $masterCols   = $getTableColumns('eco_master');
        $signoffCols  = $getTableColumns('eco_signoff');
        $trackingCols = $getTableColumns('eco_tracking');

        // Execute Database Transactions
        $this->pdo->beginTransaction();

        try {
            $stats = [
                'master_inserted'   => 0,
                'master_updated'    => 0,
                'tracking_created'  => 0,
                'tracking_preserved'=> 0,
                'signoff_upserted'  => 0,
                'status_changed'    => 0,
                'unmatched_signoff' => 0,
            ];

            $stmtCheckMaster   = $this->pdo->prepare("SELECT `eco_no` FROM `eco_master` WHERE `eco_no` = ? LIMIT 1");
            $stmtCheckTracking = $this->pdo->prepare("SELECT `id` FROM `eco_tracking` WHERE `eco_no` = ? LIMIT 1");
            $stmtGetSignoff    = !empty($signoffCols)
                ? $this->pdo->prepare("SELECT * FROM `eco_signoff` WHERE `eco_no` = ? LIMIT 1")
                : null;

            // 1. Process Master Data
            foreach ($masterData as $ecoNo => $m) {
                $stmtCheckMaster->execute([$ecoNo]);
                $exists = (bool)$stmtCheckMaster->fetchColumn();

                $allMasterFields = [
                    'eco_no'                     => $m['eco_no'],
                    'ecr_no'                     => $m['ecr_no'],
                    'customer'                   => $m['customer'],
                    'project'                    => $m['project'],
                    'eco_implementation_method'  => $m['eco_implementation_method'],
                    'ec_type_category'           => $m['ec_type_category'],
                    'status'                     => $m['status'],
                    'subject'                    => $m['subject'],
                    'status_in_agile'            => $m['status_in_agile'],
                    'wip_action'                 => $m['wip_action'],
                    'fg_action'                  => $m['fg_action'],
                    'warehouse_action'           => $m['warehouse_action'],
                    'cut_in_first_mo'            => $m['cut_in_first_mo'],
                    'cut_in_date'                => $m['cut_in_date'],
                    'pmc_site'                   => $m['pmc_site'],
                    'qa_site'                    => $m['qa_site'],
                    'originated_date'            => $m['originated_date'],
                    'completed_date'             => $m['completed_date'],
                    'year'                       => $m['year'],
                    'ww'                         => $m['ww'],
                    'month'                      => $m['month'],
                ];

                // Filter down to columns that exist in the database table
                $filteredMaster = array_filter(
                    $allMasterFields,
                    fn($k) => in_array($k, $masterCols, true),
                    ARRAY_FILTER_USE_KEY
                );

                if (!$exists) {
                    $colsStr = '`' . implode('`, `', array_keys($filteredMaster)) . '`';
                    $placeholders = implode(', ', array_fill(0, count($filteredMaster), '?'));
                    $insertSql = "INSERT INTO `eco_master` ({$colsStr}) VALUES ({$placeholders})";
                    $stmt = $this->pdo->prepare($insertSql);
                    $stmt->execute(array_values($filteredMaster));
                    $stats['master_inserted']++;
                } else {
                    $updateFields = $filteredMaster;
                    unset($updateFields['eco_no']); // do not update primary key
                    $setClauses = [];
                    foreach (array_keys($updateFields) as $c) {
                        $setClauses[] = "`{$c}` = ?";
                    }
                    $setStr = implode(', ', $setClauses);
                    $updateSql = "UPDATE `eco_master` SET {$setStr} WHERE `eco_no` = ?";
                    $values = array_values($updateFields);
                    $values[] = $ecoNo;
                    $stmt = $this->pdo->prepare($updateSql);
                    $stmt->execute($values);
                    $stats['master_updated']++;
                }

                // Check eco_tracking: PRESERVE IF EXISTS, CREATE IF NEW
                if (!empty($trackingCols)) {
                    $stmtCheckTracking->execute([$ecoNo]);
                    $trackExists = (bool)$stmtCheckTracking->fetchColumn();

                    if (!$trackExists) {
                        $allTrackFields = [
                            'eco_no'               => $ecoNo,
                            'status_progress'      => 'Pending PMC',
                            'due_date'             => $m['calculated_due_date'],
                            'updated_by'           => $importedBy,
                        ];

                        if (in_array('pmc_result_completed', $trackingCols, true)) {
                            $allTrackFields['pmc_result_completed'] = 0;
                        } elseif (in_array('pme_result_completed', $trackingCols, true)) {
                            $allTrackFields['pme_result_completed'] = 0;
                        }

                        if (in_array('qa_result_completed', $trackingCols, true)) {
                            $allTrackFields['qa_result_completed'] = 0;
                        }

                        $filteredTrack = array_filter(
                            $allTrackFields,
                            fn($k) => in_array($k, $trackingCols, true),
                            ARRAY_FILTER_USE_KEY
                        );

                        $tCols = '`' . implode('`, `', array_keys($filteredTrack)) . '`';
                        $tPlaceholders = implode(', ', array_fill(0, count($filteredTrack), '?'));
                        $tSql = "INSERT INTO `eco_tracking` ({$tCols}) VALUES ({$tPlaceholders})";
                        $tStmt = $this->pdo->prepare($tSql);
                        $tStmt->execute(array_values($filteredTrack));
                        $stats['tracking_created']++;
                    } else {
                        // Manual tracking data is preserved as required!
                        $stats['tracking_preserved']++;
                    }
                }
            }

            // Resolve signoff-only records when their SearchResult data arrives.
            if (in_array('is_unmatched', $signoffCols, true)) {
                $resolveUnmatched = $this->pdo->prepare("UPDATE `eco_signoff` SET `is_unmatched` = 0 WHERE `eco_no` = ?");
                foreach (array_keys($masterData) as $ecoNo) {
                    $resolveUnmatched->execute([$ecoNo]);
                }
            }

            // 2. Process Signoff Data
            if (!empty($signoffCols) && $stmtGetSignoff) {
                foreach ($signoffData as $ecoNo => $s) {
                    $isMatched = isset($masterData[$ecoNo]);
                    if (!$isMatched) {
                        $stmtCheckMaster->execute([$ecoNo]);
                        $isMatched = (bool)$stmtCheckMaster->fetchColumn();
                    }

                    if (!$isMatched) {
                        // Create the parent before signoff/tracking to satisfy foreign keys.
                        // Unknown SearchResult fields remain NULL until a master import.
                        $newMaster = array_filter([
                            'eco_no' => $ecoNo,
                            'status' => $s['internal_status'],
                            'status_in_agile' => self::statusToAgileStatus($s['internal_status']),
                        ], fn($k) => in_array($k, $masterCols, true), ARRAY_FILTER_USE_KEY);
                        $columns = '`' . implode('`, `', array_keys($newMaster)) . '`';
                        $holders = implode(', ', array_fill(0, count($newMaster), '?'));
                        $this->pdo->prepare("INSERT INTO `eco_master` ({$columns}) VALUES ({$holders})")
                            ->execute(array_values($newMaster));
                        $stats['master_inserted']++;

                        if (!empty($trackingCols)) {
                            $newTracking = array_filter([
                                'eco_no' => $ecoNo,
                                'status_progress' => 'Pending PMC',
                                'updated_by' => $importedBy,
                            ], fn($k) => in_array($k, $trackingCols, true), ARRAY_FILTER_USE_KEY);
                            $columns = '`' . implode('`, `', array_keys($newTracking)) . '`';
                            $holders = implode(', ', array_fill(0, count($newTracking), '?'));
                            $this->pdo->prepare("INSERT INTO `eco_tracking` ({$columns}) VALUES ({$holders})")
                                ->execute(array_values($newTracking));
                            $stats['tracking_created']++;
                        }
                    }

                    $stmtGetSignoff->execute([$ecoNo]);
                    $prevRecord = $stmtGetSignoff->fetch();
                    $isUnmatched = !isset($masterData[$ecoNo]) &&
                        (!$isMatched || !empty($prevRecord['is_unmatched']));
                    if ($isUnmatched) $stats['unmatched_signoff']++;

                    $oldStatus = $prevRecord ? ($prevRecord['internal_status'] ?? '') : null;
                    $newStatus = $s['internal_status'];

                    $statusChanged = 0;
                    $statusChangedAt = null;
                    $prevStatusVal = $oldStatus;

                    if ($prevRecord) {
                        if ($oldStatus !== null && $oldStatus !== '' && $newStatus !== '' && $oldStatus !== $newStatus) {
                            $statusChanged = 1;
                            $statusChangedAt = date('Y-m-d H:i:s');
                            $prevStatusVal = $oldStatus;
                            $stats['status_changed']++;
                        } else {
                            $statusChanged   = (int)($prevRecord['status_changed'] ?? 0);
                            $statusChangedAt = $prevRecord['status_changed_at'] ?? null;
                            $prevStatusVal   = $prevRecord['previous_internal_status'] ?? $oldStatus;
                        }
                    }

                    $allSignFields = [
                        'eco_no'                   => $ecoNo,
                        'internal_status'          => $newStatus,
                        'previous_internal_status' => $prevStatusVal,
                        'status_changed'           => $statusChanged,
                        'status_changed_at'        => $statusChangedAt,
                        'is_unmatched'             => (int)$isUnmatched,
                    ];

                    $filteredSign = array_filter(
                        $allSignFields,
                        fn($k) => in_array($k, $signoffCols, true),
                        ARRAY_FILTER_USE_KEY
                    );

                    if (!$prevRecord) {
                        $sCols = '`' . implode('`, `', array_keys($filteredSign)) . '`';
                        $sHolders = implode(', ', array_fill(0, count($filteredSign), '?'));
                        $sSql = "INSERT INTO `eco_signoff` ({$sCols}) VALUES ({$sHolders})";
                        $stmtS = $this->pdo->prepare($sSql);
                        $stmtS->execute(array_values($filteredSign));
                    } else {
                        $sUpdate = $filteredSign;
                        unset($sUpdate['eco_no']);
                        $setParts = [];
                        foreach (array_keys($sUpdate) as $sc) {
                            $setParts[] = "`{$sc}` = ?";
                        }
                        $sSql = "UPDATE `eco_signoff` SET " . implode(', ', $setParts) . " WHERE `eco_no` = ?";
                        $vals = array_values($sUpdate);
                        $vals[] = $ecoNo;
                        $stmtS = $this->pdo->prepare($sSql);
                        $stmtS->execute($vals);
                    }

                    $stats['signoff_upserted']++;
                }
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'stats'   => $stats,
                'message' => sprintf(
                    "Import successful! Master: %d inserted, %d updated. Tracking: %d new, %d preserved. Signoff: %d processed (%d changed, %d unmatched retained).",
                    $stats['master_inserted'],
                    $stats['master_updated'],
                    $stats['tracking_created'],
                    $stats['tracking_preserved'],
                    $stats['signoff_upserted'],
                    $stats['status_changed'],
                    $stats['unmatched_signoff']
                )
            ];

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Import error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'errors'  => [$e->getMessage() . " (at line " . $e->getLine() . ")"],
                'message' => 'Database import error: ' . $e->getMessage()
            ];
        }
    }
}
