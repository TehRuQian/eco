<?php
/**
 * export.php
 * Exports ECO Tracker records to a UTF-8 encoded CSV with BOM.
 * Supports active dashboard filters and exports all standard tracker fields.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ECOProcessor.php';

if (!$pdo) {
    die("Database connection failed.");
}

// Build query based on optional filters
$where = [];
$params = [];

// Search filter
if (!empty($_GET['search'])) {
    $search = '%' . trim($_GET['search']) . '%';
    $where[] = "(em.eco_no LIKE ? OR em.ecr_no LIKE ? OR em.subject LIKE ? OR em.customer LIKE ? OR em.project LIKE ?)";
    array_push($params, $search, $search, $search, $search, $search);
}

// Customer filter
if (!empty($_GET['customer'])) {
    $where[] = "em.customer = ?";
    $params[] = trim($_GET['customer']);
}

// Project filter
if (!empty($_GET['project'])) {
    $where[] = "em.project = ?";
    $params[] = trim($_GET['project']);
}

// Agile Status filter
if (!empty($_GET['agile_status'])) {
    $where[] = "em.status_in_agile = ?";
    $params[] = trim($_GET['agile_status']);
}

// Tracker Progress filter
if (!empty($_GET['tracker_status'])) {
    $where[] = "et.status_progress = ?";
    $params[] = trim($_GET['tracker_status']);
}

// PMC Site filter
if (!empty($_GET['pmc_site'])) {
    $where[] = "em.pmc_site = ?";
    $params[] = trim($_GET['pmc_site']);
}

// QA Site filter
if (!empty($_GET['qa_site'])) {
    $where[] = "em.qa_site = ?";
    $params[] = trim($_GET['qa_site']);
}

// PMC Completed filter
if (isset($_GET['pmc_completed']) && $_GET['pmc_completed'] !== '') {
    $where[] = "et.pmc_result_completed = ?";
    $params[] = (int)$_GET['pmc_completed'];
}

// QA Completed filter
if (isset($_GET['qa_completed']) && $_GET['qa_completed'] !== '') {
    $where[] = "et.qa_result_completed = ?";
    $params[] = (int)$_GET['qa_completed'];
}

// Year filter
if (!empty($_GET['year'])) {
    $where[] = "em.year = ?";
    $params[] = (int)$_GET['year'];
}

// Work Week filter
if (!empty($_GET['ww'])) {
    $where[] = "em.ww = ?";
    $params[] = (int)$_GET['ww'];
}

// Status Changed filter
if (isset($_GET['status_changed']) && $_GET['status_changed'] === '1') {
    $where[] = "es.status_changed = 1";
}

// Unmatched filter
if (isset($_GET['unmatched']) && $_GET['unmatched'] === '1') {
    $where[] = "es.is_unmatched = 1";
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Ensure schema is up to date
ECOProcessor::ensureSchema($pdo);

$sql = "
    SELECT
        em.eco_no,
        em.ecr_no,
        em.customer,
        em.project,
        em.eco_implementation_method,
        em.ec_type_category,
        em.subject,
        em.status_in_agile,
        et.status_progress,
        em.pmc_site,
        et.pmc_result_completed,
        em.qa_site,
        et.qa_result_completed,
        es.internal_status AS signoff_status,
        em.originated_date,
        em.year,
        em.ww,
        et.due_date,
        et.first_mo_result,
        et.manual_cut_in_first_mo,
        et.type_of_changes,
        et.pending_checklist,
        et.impact_assessment_checklist,
        em.wip_action,
        em.fg_action,
        em.warehouse_action,
        em.cut_in_first_mo,
        em.cut_in_date,
        em.completed_date
    FROM eco_master em
    LEFT JOIN eco_signoff es ON em.eco_no = es.eco_no
    LEFT JOIN eco_tracking et ON em.eco_no = et.eco_no
    {$whereClause}
    ORDER BY em.year DESC, em.ww DESC, em.eco_no DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$filename = 'ECO_Tracker_Export_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Output UTF-8 BOM so Microsoft Excel correctly displays international text
fputs($output, "\xEF\xBB\xBF");

// Header row
fputcsv($output, [
    'ECO No',
    'ECR No',
    'Customer',
    'Project',
    'Change Method',
    'EC Type & Category',
    'Subject',
    'Agile Status',
    'Tracker Status',
    'PMC Site',
    'PMC Completed',
    'QA Site',
    'QA Completed',
    'Signoff Status',
    'Date Originated',
    'Year',
    'Work Week (WW)',
    'Due Date',
    'First M/O Result',
    'Cut In 1st M/O (Manual)',
    'Type of Changes',
    'Pending Checklist',
    'Impact Assessment Checklist',
    'WIP Action',
    'FG Action',
    'Warehouse Action',
    'Cut in 1st M/O',
    'Cut in Date',
    'Final Complete Date'
]);

while ($row = $stmt->fetch()) {
    fputcsv($output, [
        $row['eco_no'],
        $row['ecr_no'] ?? '',
        $row['customer'] ?? '',
        $row['project'] ?? '',
        $row['eco_implementation_method'] ?? '',
        $row['ec_type_category'] ?? '',
        $row['subject'] ?? '',
        $row['status_in_agile'] ?? '',
        $row['status_progress'] ?? 'Pending PMC',
        $row['pmc_site'] ?? '',
        !empty($row['pmc_result_completed']) ? 'Yes' : 'No',
        $row['qa_site'] ?? '',
        !empty($row['qa_result_completed']) ? 'Yes' : 'No',
        $row['signoff_status'] ?? '',
        $row['originated_date'] ?? '',
        $row['year'] ?? '',
        $row['ww'] ?? '',
        $row['due_date'] ?? '',
        $row['first_mo_result'] ?? '',
        $row['manual_cut_in_first_mo'] ?? '',
        $row['type_of_changes'] ?? '',
        $row['pending_checklist'] ?? '',
        $row['impact_assessment_checklist'] ?? '',
        $row['wip_action'] ?? '',
        $row['fg_action'] ?? '',
        $row['warehouse_action'] ?? '',
        $row['cut_in_first_mo'] ?? '',
        $row['cut_in_date'] ?? '',
        $row['completed_date'] ?? ''
    ]);
}

fclose($output);
exit;
