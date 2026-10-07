<?php

error_reporting(E_ALL);
ini_set('display_errors', '0'); // don't print errors into JSON output
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');

session_start();
require 'db.php'; // must provide $pdo (PDO connection)
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/* ======================================================================
   AJAX BACKEND ACTIONS
   ====================================================================== */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    /* ---------------- UPLOAD & VALIDATE ---------------- */
    if ($action === 'upload') {
        $requiredCols = ['Change Number','Status','Status Entry Date','User Name','User Role','User Add Date','Signoff Date'];
        $log = [];
        $allRows = [];
        $ok = true;

        if (empty($_FILES['files']['name'][0])) {
            echo json_encode(['success' => false, 'log' => [['type'=>'warn','text'=>'No files uploaded.']]]);
            exit;
        }

        $fileCount = count($_FILES['files']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $tmpPath = $_FILES['files']['tmp_name'][$i];
            $name = $_FILES['files']['name'][$i];
            $log[] = ['type'=>'info', 'text'=>"Reading file: $name ..."];

            try {
                $spreadsheet = IOFactory::load($tmpPath);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, false);
            } catch (Exception $e) {
                $log[] = ['type'=>'warn', 'text'=>"[ERROR] Could not read $name: " . $e->getMessage()];
                $ok = false;
                continue;
            }

            if (count($rows) < 1) {
                $log[] = ['type'=>'warn', 'text'=>"[ERROR] $name is empty."];
                $ok = false;
                continue;
            }

            // Find header row (search first 5 rows for the required headers)
            $headerRowIndex = -1;
            $headerMap = [];
            for ($r = 0; $r < min(5, count($rows)); $r++) {
                $rowVals = array_map(function($v){ return trim((string)$v); }, $rows[$r]);
                $matchCount = 0;
                foreach ($requiredCols as $col) {
                    if (in_array($col, $rowVals)) $matchCount++;
                }
                if ($matchCount >= 5) { // majority matched -> treat as header
                    $headerRowIndex = $r;
                    foreach ($rowVals as $colIdx => $val) {
                        $headerMap[$val] = $colIdx;
                    }
                    break;
                }
            }

            if ($headerRowIndex === -1) {
                $log[] = ['type'=>'warn', 'text'=>"[ERROR] Could not detect header row in $name."];
                $ok = false;
                continue;
            }

            foreach ($requiredCols as $col) {
                if (isset($headerMap[$col])) {
                    $log[] = ['type'=>'ok', 'text'=>"[OK] $col - Found in $name"];
                } else {
                    $log[] = ['type'=>'warn', 'text'=>"[MISSING] $col - Not found in $name"];
                    $ok = false;
                }
            }

            if (!$ok) continue;

            for ($r = $headerRowIndex + 1; $r < count($rows); $r++) {
                $row = $rows[$r];
                $changeNumber = trim((string)($row[$headerMap['Change Number']] ?? ''));
                if ($changeNumber === '') continue; // skip empty rows

                $allRows[] = [
                    'eco_no'            => $changeNumber,
                    'status'            => trim((string)($row[$headerMap['Status']] ?? '')),
                    'status_entry_date' => trim((string)($row[$headerMap['Status Entry Date']] ?? '')),
                    'user_name'         => trim((string)($row[$headerMap['User Name']] ?? '')),
                    'user_role'         => trim((string)($row[$headerMap['User Role']] ?? '')),
                    'user_add_date'     => trim((string)($row[$headerMap['User Add Date']] ?? '')),
                    'signoff_date'      => trim((string)($row[$headerMap['Signoff Date']] ?? '')),
                ];
            }

            $log[] = ['type'=>'ok', 'text'=>"[DONE] Parsed $name successfully. Total rows so far: " . count($allRows)];
        }

        if ($ok && count($allRows) > 0) {
            $_SESSION['signoff_rows'] = $allRows;
            $log[] = ['type'=>'ok', 'text'=>'[DONE] All required columns validated. Ready for matching.'];
        } else if (count($allRows) === 0) {
            $ok = false;
            $log[] = ['type'=>'warn', 'text'=>'[ERROR] No valid data rows found.'];
        }

        echo json_encode(['success' => $ok, 'log' => $log]);
        exit;
    }

    /* ---------------- CLEAN & MATCH ---------------- */
    if ($action === 'match') {
        if (empty($_SESSION['signoff_rows'])) {
            echo json_encode(['success' => false, 'message' => 'No uploaded data found. Please upload again.']);
            exit;
        }

        $rows = $_SESSION['signoff_rows'];

        // Remove duplicate rows entirely
        $seen = [];
        $cleanRows = [];
        foreach ($rows as $r) {
            $key = md5(json_encode($r));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $cleanRows[] = $r;
            }
        }

        // Group by eco_no, take the FIRST status value found per eco_no
        $grouped = [];
        foreach ($cleanRows as $r) {
            $eco = $r['eco_no'];
            if (!isset($grouped[$eco])) {
                $grouped[$eco] = $r; // first row's status represents this ECO's status
            }
        }

        $matched = [];
        $mismatched = [];
        $newRecords = [];

        foreach ($grouped as $ecoNo => $fileRow) {
            $stmt = $pdo->prepare("SELECT * FROM eco_records WHERE eco_no = :eco_no LIMIT 1");
            $stmt->execute([':eco_no' => $ecoNo]);
            $dbRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($dbRow) {
                $fileStatus = trim($fileRow['status']);
                $dbStatus = trim($dbRow['status']);

                if (strcasecmp($fileStatus, $dbStatus) === 0) {
                    $matched[] = [
                        'eco_no' => $ecoNo,
                        'db_status' => $dbStatus,
                        'file_status' => $fileStatus,
                        'result' => 'OK'
                    ];
                } else {
                    // Flag mismatch immediately in DB
                    $upd = $pdo->prepare("UPDATE eco_records SET status_flag = 1, pending_status = :pending WHERE eco_no = :eco_no");
                    $upd->execute([':pending' => $fileStatus, ':eco_no' => $ecoNo]);

                    $mismatched[] = [
                        'id' => $dbRow['id'],
                        'eco_no' => $ecoNo,
                        'db_status' => $dbStatus,
                        'file_status' => $fileStatus,
                        'result' => 'MISMATCH'
                    ];
                }
            } else {
                // Prepare new record
                $dateStr = $fileRow['status_entry_date'] ?: date('Y-m-d');
                $ts = strtotime(str_replace('/', '-', $dateStr));
                if ($ts === false) $ts = time();
                $year = date('Y', $ts);
                $month = date('M', $ts);
                $ww = (int)date('W', $ts);

                $newRecords[] = [
                    'eco_no' => $ecoNo,
                    'year' => $year,
                    'ww' => $ww,
                    'month' => $month,
                    'ecr_no' => '',
                    'customer' => '',
                    'project' => '',
                    'eco_method' => '',
                    'ec_type_category' => '',
                    'status' => $fileRow['status'],
                    'subject' => '',
                    'status_in_agile' => 'OPEN'
                ];
            }
        }

        $_SESSION['match_new'] = $newRecords;
        $_SESSION['match_mismatched'] = $mismatched;
        $_SESSION['match_ok'] = $matched;
        $_SESSION['signoff_rows_clean'] = $cleanRows;

        echo json_encode([
            'success' => true,
            'matched' => $matched,
            'mismatched' => $mismatched,
            'newRecords' => $newRecords,
            'summary' => [
                'total' => count($grouped),
                'ok' => count($matched),
                'mismatch' => count($mismatched),
                'new' => count($newRecords)
            ]
        ]);
        exit;
    }

    /* ---------------- CHECKED BUTTON (resolve mismatch) ---------------- */
    if ($action === 'checked') {
        $id = $_POST['id'] ?? null;
        if (!$id) {
            echo json_encode(['success'=>false, 'message'=>'Missing ID']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT pending_status FROM eco_records WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['pending_status'] === null) {
            echo json_encode(['success'=>false, 'message'=>'No pending status found']);
            exit;
        }

        $upd = $pdo->prepare("UPDATE eco_records SET status = :status, status_flag = 0, pending_status = NULL WHERE id = :id");
        $upd->execute([':status' => $row['pending_status'], ':id' => $id]);

        // Remove from session mismatched list
        if (!empty($_SESSION['match_mismatched'])) {
            $_SESSION['match_mismatched'] = array_filter($_SESSION['match_mismatched'], function($m) use ($id) {
                return $m['id'] != $id;
            });
        }

        echo json_encode(['success' => true]);
        exit;
    }

    /* ---------------- IMPORT NEW RECORDS + SAVE SIGNOFF DETAILS ---------------- */
    if ($action === 'import') {
        $newRecords = $_SESSION['match_new'] ?? [];
        $signoffRows = $_SESSION['signoff_rows_clean'] ?? [];

        $inserted = 0;
        foreach ($newRecords as $rec) {
            $stmt = $pdo->prepare("INSERT INTO eco_records
                (year, ww, month, eco_no, ecr_no, customer, project, eco_method, ec_type_category, status, subject, status_in_agile, status_flag, pending_status)
                VALUES (:year, :ww, :month, :eco_no, :ecr_no, :customer, :project, :eco_method, :ec_type_category, :status, :subject, :status_in_agile, 0, NULL)");
            $stmt->execute([
                ':year' => $rec['year'], ':ww' => $rec['ww'], ':month' => $rec['month'],
                ':eco_no' => $rec['eco_no'], ':ecr_no' => $rec['ecr_no'], ':customer' => $rec['customer'],
                ':project' => $rec['project'], ':eco_method' => $rec['eco_method'],
                ':ec_type_category' => $rec['ec_type_category'], ':status' => $rec['status'],
                ':subject' => $rec['subject'], ':status_in_agile' => $rec['status_in_agile']
            ]);
            $inserted++;
        }

        // Save all signoff detail rows (matched + new + mismatched all included)
        foreach ($signoffRows as $r) {
            $stmt = $pdo->prepare("INSERT INTO signoff_details
                (eco_no, status, status_entry_date, user_name, user_role, user_add_date, signoff_date)
                VALUES (:eco_no, :status, :status_entry_date, :user_name, :user_role, :user_add_date, :signoff_date)");
            $stmt->execute([
                ':eco_no' => $r['eco_no'],
                ':status' => $r['status'],
                ':status_entry_date' => $r['status_entry_date'] ? date('Y-m-d', strtotime(str_replace('/','-',$r['status_entry_date']))) : null,
                ':user_name' => $r['user_name'],
                ':user_role' => $r['user_role'],
                ':user_add_date' => $r['user_add_date'] ? date('Y-m-d', strtotime(str_replace('/','-',$r['user_add_date']))) : null,
                ':signoff_date' => $r['signoff_date'] ? date('Y-m-d', strtotime(str_replace('/','-',$r['signoff_date']))) : null,
            ]);
        }

        unset($_SESSION['signoff_rows'], $_SESSION['signoff_rows_clean'], $_SESSION['match_new'], $_SESSION['match_mismatched'], $_SESSION['match_ok']);

        echo json_encode(['success' => true, 'inserted' => $inserted]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

/* ======================================================================
   NORMAL PAGE LOAD (Dashboard)
   ====================================================================== */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$ww_filter = isset($_GET['ww']) ? trim($_GET['ww']) : '';
$year_filter = isset($_GET['year']) ? trim($_GET['year']) : '';

$sql = "SELECT * FROM eco_records WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (subject LIKE :search OR eco_no LIKE :search2 OR ecr_no LIKE :search3 OR project LIKE :search4 OR customer LIKE :search5)";
    $params[':search'] = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
    $params[':search4'] = "%$search%";
    $params[':search5'] = "%$search%";
}
if ($status_filter !== '') { $sql .= " AND status = :status"; $params[':status'] = $status_filter; }
if ($ww_filter !== '') { $sql .= " AND ww = :ww"; $params[':ww'] = $ww_filter; }
if ($year_filter !== '') { $sql .= " AND year = :year"; $params[':year'] = $year_filter; }

$sql .= " ORDER BY year DESC, ww DESC, id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statuses = $pdo->query("SELECT DISTINCT status FROM eco_records ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
$wws = $pdo->query("SELECT DISTINCT ww FROM eco_records ORDER BY ww")->fetchAll(PDO::FETCH_COLUMN);
$years = $pdo->query("SELECT DISTINCT year FROM eco_records ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);

$completeCount = count(array_filter($records, fn($r) => $r['status'] === 'Complete'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ECO Tracker Dashboard</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; color: #333; }
    .container { max-width: 1500px; margin: 0 auto; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); padding: 25px; }
    h1 { color: #1a3c6e; border-bottom: 3px solid #1a73e8; padding-bottom: 10px; margin-top: 0; display:flex; justify-content:space-between; align-items:center; }
    .toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; align-items: center; }
    .toolbar input[type=text], .toolbar select { padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
    .toolbar input[type=text] { flex: 1; min-width: 200px; }
    .toolbar button, .btn { padding: 8px 16px; background: #1a73e8; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
    .toolbar button:hover, .btn:hover { background: #0f5bc7; }
    .btn-add { background: #1e8e3e; }
    .btn-add:hover { background: #17752f; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { border: 1px solid #e0e0e0; padding: 8px 10px; text-align: left; vertical-align: top; white-space: nowrap; }
    th { background: #1a3c6e; color: #fff; position: sticky; top: 0; font-weight: 600; }
    tr:nth-child(even) { background: #f9fafc; }
    tr:hover { background: #eef3fc; }
    .status-badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; display: inline-block; }
    .status-complete { background: #d4edda; color: #1e7e34; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-inprogress { background: #cce5ff; color: #004085; }
    .status-cancelled { background: #f8d7da; color: #721c24; }
    .agile-closed { color: #1e7e34; font-weight: 700; }
    .agile-open { color: #b8860b; font-weight: 700; }
    .table-wrap { overflow-x: auto; max-height: 70vh; overflow-y: auto; }
    .subject-cell { max-width: 350px; white-space: normal; }
    .stats { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
    .stat-card { background: linear-gradient(135deg, #1a73e8, #1a3c6e); color: #fff; padding: 15px 20px; border-radius: 8px; min-width: 150px; }
    .stat-card.warn { background: linear-gradient(135deg,#e05555,#a12b2b); }
    .stat-card .num { font-size: 26px; font-weight: bold; }
    .stat-card .label { font-size: 13px; opacity: 0.9; }
    .actions a { margin-right: 8px; color: #1a73e8; text-decoration: none; font-size: 12px; }
    .actions a.delete { color: #d93025; }
    .mismatch-flag { background:#f8d7da; color:#721c24; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
    .btn-checked { background:#1e8e3e; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-size:11px; cursor:pointer; }
    .btn-checked:hover { background:#17752f; }

    /* MODAL */
    .modal-overlay { display:none; position:fixed; top:0;left:0;right:0;bottom:0; background:rgba(0,0,0,0.55); z-index:1000; align-items:flex-start; justify-content:center; overflow-y:auto; padding:30px 15px; }
    .modal-overlay.active { display:flex; }
    .modal-box { background:#f4f6f9; border-radius:10px; max-width:1200px; width:100%; padding:25px; position:relative; }
    .modal-close { position:absolute; top:12px; right:18px; font-size:22px; color:#666; cursor:pointer; background:none; border:none; }
    .modal-close:hover { color:#d93025; }
    .stepper { display:flex; justify-content:space-between; margin-bottom:25px; position:relative; }
    .stepper::before { content:''; position:absolute; top:18px; left:5%; right:5%; height:3px; background:#ddd; z-index:0; }
    .step { flex:1; text-align:center; position:relative; z-index:1; }
    .step .circle { width:36px;height:36px;border-radius:50%;background:#ddd;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 6px;font-weight:bold;font-size:14px; }
    .step.active .circle { background:#1a73e8; }
    .step.done .circle { background:#1e8e3e; }
    .step .label { font-size:11px; color:#666; max-width:90px; margin:0 auto; }
    .card { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.08); padding:25px; }
    .card h2 { margin-top:0; color:#1a3c6e; font-size:18px; border-bottom:2px solid #eef3fc; padding-bottom:10px; }
    .upload-box-single { border:2px dashed #b0c4de; border-radius:10px; padding:40px 25px; text-align:center; background:#f8fafd; cursor:pointer; }
    .upload-box-single:hover { border-color:#1a73e8; background:#eef3fc; }
    .upload-box-single.dragover { border-color:#1e8e3e; background:#eafaf0; }
    .upload-icon { font-size:36px; color:#1a73e8; font-weight:700; margin-bottom:8px; }
    .upload-text { font-size:18px; font-weight:700; color:#1a3c6e; margin-bottom:6px; }
    .upload-subtext { font-size:13px; color:#777; }
    .uploaded-files-list { margin-top:15px; display:flex; flex-direction:column; gap:8px; }
    .uploaded-file-item { display:flex; align-items:center; justify-content:space-between; background:#eafaf0; border:1px solid #1e8e3e; border-radius:6px; padding:10px 14px; font-size:13px; }
    .uploaded-file-item .file-info { display:flex; align-items:center; gap:10px; }
    .uploaded-file-item .file-badge { background:#1e8e3e; color:#fff; font-size:10px; font-weight:700; padding:2px 8px; border-radius:4px; text-transform:uppercase; }
    .uploaded-file-item .remove-file { color:#d93025; cursor:pointer; font-weight:700; font-size:14px; background:none; border:none; }
    .req-columns { font-size:12px; color:#777; margin-top:10px; text-align:left; background:#fff; border-radius:6px; padding:8px 12px; border:1px solid #eee; }
    .actions-row { margin-top:20px; display:flex; gap:10px; justify-content:flex-end; }
    .log { font-family:'Consolas', monospace; font-size:13px; background:#0d1117; color:#c9d1d9; border-radius:8px; padding:15px; max-height:260px; overflow-y:auto; line-height:1.6; }
    .log .ok { color:#3fb950; }
    .log .warn { color:#d29922; }
    .log .info { color:#58a6ff; }
    .progress-wrap { background:#eee; border-radius:20px; height:22px; overflow:hidden; margin:15px 0; }
    .progress-bar { height:100%; background:linear-gradient(90deg,#1a73e8,#1e8e3e); width:0%; transition:width 0.4s ease; display:flex; align-items:center; justify-content:center; color:#fff; font-size:12px; font-weight:600; }
    .summary-cards { display:flex; gap:15px; flex-wrap:wrap; margin-bottom:15px; }
    .summary-card { background:linear-gradient(135deg,#1a73e8,#1a3c6e); color:#fff; padding:15px 20px; border-radius:8px; min-width:140px; }
    .summary-card .num { font-size:24px; font-weight:bold; }
    .summary-card .label { font-size:12px; opacity:0.9; }
    .summary-card.orange { background:linear-gradient(135deg,#f5a623,#b8770e); }
    .summary-card.red { background:linear-gradient(135deg,#e05555,#a12b2b); }
    .hidden { display:none; }
</style>
</head>
<body>
<div class="container">
    <h1>ECO Tracker Dashboard <button class="btn btn-add" onclick="openModal()">+ Upload Signoff File</button></h1>

    <div class="stats">
        <div class="stat-card"><div class="num"><?= count($records) ?></div><div class="label">Total Records (filtered)</div></div>
        <div class="stat-card"><div class="num"><?= $completeCount ?></div><div class="label">Completed</div></div>
        <div class="stat-card warn"><div class="num"><?= count(array_filter($records, fn($r)=>$r['status_flag']==1)) ?></div><div class="label">Status Mismatches</div></div>
    </div>

    <form method="get" class="toolbar">
        <input type="text" name="search" placeholder="Search by subject, ECO No., ECR No., project, customer..." value="<?= htmlspecialchars($search) ?>">
        <select name="year">
            <option value="">All Years</option>
            <?php foreach ($years as $y): ?><option value="<?= htmlspecialchars($y) ?>" <?= $year_filter == $y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option><?php endforeach; ?>
        </select>
        <select name="ww">
            <option value="">All Weeks</option>
            <?php foreach ($wws as $w): ?><option value="<?= htmlspecialchars($w) ?>" <?= $ww_filter == $w ? 'selected' : '' ?>>WW<?= htmlspecialchars($w) ?></option><?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach ($statuses as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
        <a href="index.php" class="btn">Reset</a>
        <a href="add.php" class="btn btn-add">+ Add New</a>
        <a href="export_csv.php<?= $_SERVER['QUERY_STRING'] ? '?'.htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="btn">Export CSV</a>
    </form>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Year</th><th>WW</th><th>Month</th><th>ECO No.</th><th>ECR No.</th><th>Customer</th>
            <th>Project</th><th>ECO Implement Method</th><th>EC Type and Category</th><th>Status</th>
            <th>Subject</th><th>Status in Agile</th><th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($records)): ?>
            <tr><td colspan="13" style="text-align:center;padding:20px;">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($records as $r):
            $statusClass = 'status-pending';
            if (stripos($r['status'], 'complete') !== false) $statusClass = 'status-complete';
            elseif (stripos($r['status'], 'progress') !== false) $statusClass = 'status-inprogress';
            elseif (stripos($r['status'], 'cancel') !== false) $statusClass = 'status-cancelled';
            $agileClass = stripos($r['status_in_agile'], 'closed') !== false ? 'agile-closed' : 'agile-open';
        ?>
            <tr id="row-<?= $r['id'] ?>">
                <td><?= htmlspecialchars($r['year']) ?></td>
                <td><?= htmlspecialchars($r['ww']) ?></td>
                <td><?= htmlspecialchars($r['month']) ?></td>
                <td><?= htmlspecialchars($r['eco_no']) ?></td>
                <td><?= htmlspecialchars($r['ecr_no']) ?></td>
                <td><?= htmlspecialchars($r['customer']) ?></td>
                <td><?= htmlspecialchars($r['project']) ?></td>
                <td><?= htmlspecialchars($r['eco_method']) ?></td>
                <td><?= htmlspecialchars($r['ec_type_category']) ?></td>
                <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td class="subject-cell"><?= htmlspecialchars($r['subject']) ?></td>
                <td class="<?= $agileClass ?>"><?= htmlspecialchars($r['status_in_agile']) ?></td>
                <td class="actions">
                    <a href="edit.php?id=<?= $r['id'] ?>">Edit</a>
                    <a href="delete.php?id=<?= $r['id'] ?>" class="delete" onclick="return confirm('Delete this record?');">Delete</a>
                    <?php if ($r['status_flag'] == 1): ?>
                        <br><span class="mismatch-flag">New: <?= htmlspecialchars($r['pending_status']) ?></span>
                        <button class="btn-checked" onclick="markChecked(<?= $r['id'] ?>)">CHECKED</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ============ UPLOAD MODAL ============ -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">&times;</button>

        <div class="stepper" id="stepper">
            <div class="step active" data-step="1"><div class="circle">1</div><div class="label">Upload Files</div></div>
            <div class="step" data-step="2"><div class="circle">2</div><div class="label">Validate</div></div>
            <div class="step" data-step="3"><div class="circle">3</div><div class="label">Clean and Match</div></div>
            <div class="step" data-step="4"><div class="circle">4</div><div class="label">Review Matches</div></div>
            <div class="step" data-step="5"><div class="circle">5</div><div class="label">Import New Records</div></div>
        </div>

        <!-- STEP 1 -->
        <div class="card" id="panel-1">
            <h2>Step 1: Upload Files</h2>
            <div class="upload-box-single" id="uploadBoxSingle" onclick="document.getElementById('fileInputMulti').click()">
                <div class="upload-icon">+</div>
                <div class="upload-text">Upload File</div>
                <div class="upload-subtext">Click here or drag and drop files</div>
                <div class="upload-subtext">Supported formats: .xls, .xlsx, .csv</div>
                <input type="file" id="fileInputMulti" multiple accept=".xls,.xlsx,.csv" style="display:none;">
            </div>
            <div class="uploaded-files-list" id="uploadedFilesList"></div>
            <div class="req-columns" style="margin-top:15px;">
                <b>Required file:</b> user_signoff file with columns: Change Number, Status, Status Entry Date, User Name, User Role, User Add Date, Signoff Date
            </div>
            <div class="actions-row">
                <button class="btn" id="btnValidate" disabled onclick="goToStep2()">Validate Files</button>
            </div>
        </div>

        <!-- STEP 2 -->
        <div class="card hidden" id="panel-2">
            <h2>Step 2: Validate Files</h2>
            <div class="log" id="validationLog"></div>
            <div class="actions-row">
                <button class="btn" onclick="backToStep(1)">Back</button>
                <button class="btn btn-green" id="btnToStep3" onclick="goToStep3()" disabled style="background:#1e8e3e;">Continue</button>
            </div>
        </div>

        <!-- STEP 3 -->
        <div class="card hidden" id="panel-3">
            <h2>Step 3: Clean and Match Records</h2>
            <div class="progress-wrap"><div class="progress-bar" id="progressBar">0%</div></div>
            <div class="log" id="matchLog"></div>
            <div class="actions-row">
                <button class="btn" onclick="backToStep(2)">Back</button>
                <button class="btn" id="btnToStep4" onclick="goToStep4()" disabled style="background:#1e8e3e;">Continue</button>
            </div>
        </div>

        <!-- STEP 4 -->
        <div class="card hidden" id="panel-4">
            <h2>Step 4: Review Match Results</h2>
            <div class="summary-cards" id="step4Summary"></div>
            <h3 style="font-size:14px;color:#721c24;">? Mismatched Status (requires confirmation)</h3>
            <div class="table-wrap" style="max-height:220px;">
                <table>
                    <thead><tr><th>ECO No.</th><th>Current DB Status</th><th>New File Status</th><th>Action</th></tr></thead>
                    <tbody id="mismatchBody"></tbody>
                </table>
            </div>
            <h3 style="font-size:14px;color:#1e7e34;margin-top:20px;">? Already Up-to-date</h3>
            <div class="table-wrap" style="max-height:150px;">
                <table>
                    <thead><tr><th>ECO No.</th><th>Status</th></tr></thead>
                    <tbody id="okBody"></tbody>
                </table>
            </div>
            <div class="actions-row">
                <button class="btn" onclick="backToStep(3)">Back</button>
                <button class="btn" style="background:#1e8e3e;" onclick="goToStep5()">Continue to New Records</button>
            </div>
        </div>

        <!-- STEP 5 -->
        <div class="card hidden" id="panel-5">
            <h2>Step 5: Review & Import New Records</h2>
            <div class="summary-cards">
                <div class="summary-card"><div class="num" id="sumNew">0</div><div class="label">New Records to Import</div></div>
            </div>
            <div class="table-wrap" style="max-height:300px;">
                <table id="finalTable">
                    <thead><tr><th>Year</th><th>WW</th><th>Month</th><th>ECO No.</th><th>Status</th><th>Status in Agile</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="actions-row">
                <button class="btn" onclick="backToStep(4)">Back</button>
                <button class="btn" style="background:#1e8e3e;" onclick="importIntoDashboard()">Import into Dashboard</button>
            </div>
        </div>
    </div>
</div>

<script>
let uploadedFiles = [];
let matchNewRecords = [];

function openModal() {
    document.getElementById('modalOverlay').classList.add('active');
    resetModalState();
}
function closeModal() {
    document.getElementById('modalOverlay').classList.remove('active');
    if (document.getElementById('sumNew') && parseInt(document.getElementById('sumNew').textContent) >= 0) {
        // reload page to reflect any changes
    }
}
function resetModalState() {
    setStep(1);
    uploadedFiles = [];
    document.getElementById('uploadedFilesList').innerHTML = '';
    document.getElementById('fileInputMulti').value = '';
    document.getElementById('btnValidate').disabled = true;
}
function setStep(n) {
    document.querySelectorAll('.step').forEach(s => {
        const stepNum = parseInt(s.dataset.step);
        s.classList.remove('active','done');
        if (stepNum < n) s.classList.add('done');
        if (stepNum === n) s.classList.add('active');
    });
    for (let i = 1; i <= 5; i++) {
        document.getElementById('panel-' + i).classList.toggle('hidden', i !== n);
    }
}
function backToStep(n) { setStep(n); }

/* ---------------- STEP 1: Upload ---------------- */
const uploadBoxSingle = document.getElementById('uploadBoxSingle');
const fileInputMulti = document.getElementById('fileInputMulti');

fileInputMulti.addEventListener('change', function(e){
    handleFiles(e.target.files);
    fileInputMulti.value = '';
});
uploadBoxSingle.addEventListener('dragover', function(e){ e.preventDefault(); uploadBoxSingle.classList.add('dragover'); });
uploadBoxSingle.addEventListener('dragleave', function(e){ uploadBoxSingle.classList.remove('dragover'); });
uploadBoxSingle.addEventListener('drop', function(e){
    e.preventDefault();
    uploadBoxSingle.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});

function handleFiles(fileList) {
    Array.from(fileList).forEach(file => {
        const ext = file.name.split('.').pop().toLowerCase();
        if (['xls','xlsx','csv'].includes(ext)) uploadedFiles.push(file);
    });
    renderUploadedFiles();
    document.getElementById('btnValidate').disabled = uploadedFiles.length < 1;
}
function renderUploadedFiles() {
    const listEl = document.getElementById('uploadedFilesList');
    listEl.innerHTML = '';
    uploadedFiles.forEach((file, index) => {
        const ext = file.name.split('.').pop().toUpperCase();
        const item = document.createElement('div');
        item.className = 'uploaded-file-item';
        item.innerHTML = '<div class="file-info"><span class="file-badge">' + ext + '</span><span>' + file.name + '</span></div>' +
            '<button class="remove-file" onclick="removeFile(' + index + ')">Remove</button>';
        listEl.appendChild(item);
    });
}
function removeFile(index) {
    uploadedFiles.splice(index, 1);
    renderUploadedFiles();
    document.getElementById('btnValidate').disabled = uploadedFiles.length < 1;
}

/* ---------------- STEP 2: Validate (real upload via AJAX) ---------------- */
function goToStep2() {
    setStep(2);
    const log = document.getElementById('validationLog');
    log.innerHTML = '<div class="info">Uploading and validating files...</div>';
    document.getElementById('btnToStep3').disabled = true;

    const formData = new FormData();
    uploadedFiles.forEach(f => formData.append('files[]', f));

    fetch('index.php?action=upload', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            log.innerHTML = '';
            data.log.forEach(line => {
                const div = document.createElement('div');
                div.className = line.type;
                div.textContent = line.text;
                log.appendChild(div);
            });
            log.scrollTop = log.scrollHeight;
            document.getElementById('btnToStep3').disabled = !data.success;
        })
        .catch(err => {
            log.innerHTML = '<div class="warn">[ERROR] Upload failed: ' + err + '</div>';
        });
}

/* ---------------- STEP 3: Clean & Match (real AJAX) ---------------- */
function goToStep3() {
    setStep(3);
    const log = document.getElementById('matchLog');
    const bar = document.getElementById('progressBar');
    log.innerHTML = '';
    bar.style.width = '10%'; bar.textContent = '10%';
    document.getElementById('btnToStep4').disabled = true;

    const steps = ['Removing duplicate records...','Grouping records by Change Number...','Comparing against database records...'];
    let i = 0;
    const interval = setInterval(() => {
        if (i < steps.length) {
            const div = document.createElement('div');
            div.className = 'info';
            div.textContent = steps[i];
            log.appendChild(div);
            bar.style.width = (10 + (i+1)*20) + '%';
            bar.textContent = (10 + (i+1)*20) + '%';
            i++;
        } else {
            clearInterval(interval);
            runMatch(log, bar);
        }
    }, 300);
}

function runMatch(log, bar) {
    fetch('index.php?action=match')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                const div = document.createElement('div');
                div.className = 'warn';
                div.textContent = '[ERROR] ' + (data.message || 'Matching failed.');
                log.appendChild(div);
                return;
            }
            bar.style.width = '100%'; bar.textContent = '100%';

            data.matched.forEach(m => {
                const div = document.createElement('div');
                div.className = 'ok';
                div.textContent = '[OK] ' + m.eco_no + ' - status already up to date (' + m.db_status + ').';
                log.appendChild(div);
            });
            data.mismatched.forEach(m => {
                const div = document.createElement('div');
                div.className = 'warn';
                div.textContent = '[MISMATCH] ' + m.eco_no + ' - DB: "' + m.db_status + '" vs File: "' + m.file_status + '" -> flagged.';
                log.appendChild(div);
            });
            data.newRecords.forEach(n => {
                const div = document.createElement('div');
                div.className = 'info';
                div.textContent = '[NEW] ' + n.eco_no + ' - not found in database, will be added.';
                log.appendChild(div);
            });

            const summary = document.createElement('div');
            summary.className = 'ok';
            summary.style.marginTop = '10px';
            summary.textContent = '[DONE] Matching complete: ' + data.summary.ok + ' OK, ' + data.summary.mismatch + ' mismatched, ' + data.summary.new + ' new.';
            log.appendChild(summary);
            log.scrollTop = log.scrollHeight;

            matchNewRecords = data.newRecords;
            window._matchData = data;
            document.getElementById('btnToStep4').disabled = false;
        });
}

/* ---------------- STEP 4: Review matches ---------------- */
function goToStep4() {
    setStep(4);
    const data = window._matchData;
    document.getElementById('step4Summary').innerHTML =
        '<div class="summary-card"><div class="num">' + data.summary.total + '</div><div class="label">Total Compared</div></div>' +
        '<div class="summary-card orange"><div class="num">' + data.summary.mismatch + '</div><div class="label">Mismatched</div></div>' +
        '<div class="summary-card"><div class="num">' + data.summary.ok + '</div><div class="label">Already OK</div></div>' +
        '<div class="summary-card red"><div class="num">' + data.summary.new + '</div><div class="label">New Records</div></div>';

    const mismatchBody = document.getElementById('mismatchBody');
    mismatchBody.innerHTML = '';
    data.mismatched.forEach(m => {
        const tr = document.createElement('tr');
        tr.id = 'mismatch-row-' + m.id;
        tr.innerHTML = '<td>' + m.eco_no + '</td><td>' + m.db_status + '</td><td>' + m.file_status + '</td>' +
            '<td><button class="btn-checked" onclick="markCheckedModal(' + m.id + ')">CHECKED</button></td>';
        mismatchBody.appendChild(tr);
    });
    if (data.mismatched.length === 0) {
        mismatchBody.innerHTML = '<tr><td colspan="4" style="text-align:center;">No mismatches found.</td></tr>';
    }

    const okBody = document.getElementById('okBody');
    okBody.innerHTML = '';
    data.matched.forEach(m => {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + m.eco_no + '</td><td>' + m.db_status + '</td>';
        okBody.appendChild(tr);
    });
    if (data.matched.length === 0) {
        okBody.innerHTML = '<tr><td colspan="2" style="text-align:center;">None.</td></tr>';
    }
}

function markCheckedModal(id) {
    fetch('index.php?action=checked', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'id=' + encodeURIComponent(id)
    }).then(res => res.json()).then(data => {
        if (data.success) {
            const row = document.getElementById('mismatch-row-' + id);
            if (row) row.remove();
        } else {
            alert(data.message || 'Failed to update.');
        }
    });
}

/* CHECKED button directly on main dashboard table */
function markChecked(id) {
    fetch('index.php?action=checked', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'id=' + encodeURIComponent(id)
    }).then(res => res.json()).then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Failed to update.');
        }
    });
}

/* ---------------- STEP 5: Review new records & import ---------------- */
function goToStep5() {
    setStep(5);
    const tbody = document.querySelector('#finalTable tbody');
    tbody.innerHTML = '';
    matchNewRecords.forEach(rec => {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + rec.year + '</td><td>' + rec.ww + '</td><td>' + rec.month + '</td>' +
            '<td>' + rec.eco_no + '</td><td>' + rec.status + '</td><td>' + rec.status_in_agile + '</td>';
        tbody.appendChild(tr);
    });
    document.getElementById('sumNew').textContent = matchNewRecords.length;
}

function importIntoDashboard() {
    fetch('index.php?action=import')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Import complete: ' + data.inserted + ' new record(s) added.');
                closeModal();
                location.reload();
            } else {
                alert('Import failed.');
            }
        });
}
</script>
</body>
</html>
