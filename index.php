<?php
/**
 * index.php
 * Automated ECO Tracker System — Modern Executive Dashboard
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ECOProcessor.php';

function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function dash($v): string
{
    $v = trim((string)($v ?? ''));
    return $v === '' ? '<span style="color:#94a3b8;">—</span>' : h($v);
}

$records = [];
$totalEco = 0;
$pendingPmc = 0;
$pendingQa = 0;
$completed = 0;
$overdue = 0;
$statusChanged = 0;
$unmatchedSignoff = 0;

$customers = [];
$projects = [];
$agileStatuses = [];
$years = [];
$workWeeks = [];

if ($pdo) {
    try {
        // Automatically check and upgrade database tables and columns if missing
        ECOProcessor::ensureSchema($pdo);

        // Inspect actual column names in database
        $getCols = function(string $table) use ($pdo): array {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {
                return [];
            }
        };

        $masterCols   = $getCols('eco_master');
        $signoffCols  = $getCols('eco_signoff');
        $trackingCols = $getCols('eco_tracking');

        // Dynamic column mapping to guarantee 100% crash-proof queries
        $colEcoNo       = in_array('eco_no', $masterCols, true) ? 'em.eco_no' : "'' AS eco_no";
        $colEcrNo       = in_array('ecr_no', $masterCols, true) ? 'em.ecr_no' : "NULL AS ecr_no";
        $colCustomer    = in_array('customer', $masterCols, true) ? 'em.customer' : "NULL AS customer";
        $colProject     = in_array('project', $masterCols, true) ? 'em.project' : "NULL AS project";
        $colMethod      = in_array('eco_implementation_method', $masterCols, true) ? 'em.eco_implementation_method' : "NULL AS eco_implementation_method";
        $colCategory    = in_array('ec_type_category', $masterCols, true) ? 'em.ec_type_category' : "NULL AS ec_type_category";
        $colStatus      = in_array('status', $masterCols, true) ? 'em.status AS cleaned_status' : "NULL AS cleaned_status";
        $colSubject     = in_array('subject', $masterCols, true) ? 'em.subject' : "NULL AS subject";
        $colAgileStatus = in_array('status_in_agile', $masterCols, true) ? 'em.status_in_agile' : "NULL AS status_in_agile";
        $colWipAction   = in_array('wip_action', $masterCols, true) ? 'em.wip_action' : "NULL AS wip_action";
        $colFgAction    = in_array('fg_action', $masterCols, true) ? 'em.fg_action' : "NULL AS fg_action";
        $colWhAction    = in_array('warehouse_action', $masterCols, true) ? 'em.warehouse_action' : "NULL AS warehouse_action";
        $colCutInMo     = in_array('cut_in_first_mo', $masterCols, true) ? 'em.cut_in_first_mo' : "NULL AS cut_in_first_mo";
        $colCutInDate   = in_array('cut_in_date', $masterCols, true) ? 'em.cut_in_date' : "NULL AS cut_in_date";
        $colPmcSite     = in_array('pmc_site', $masterCols, true) ? 'em.pmc_site' : "NULL AS pmc_site";
        $colQaSite      = in_array('qa_site', $masterCols, true) ? 'em.qa_site' : "NULL AS qa_site";
        $colOrigDate    = in_array('originated_date', $masterCols, true) ? 'em.originated_date' : "NULL AS originated_date";
        $colCompDate    = in_array('completed_date', $masterCols, true) ? 'em.completed_date' : "NULL AS completed_date";
        $colYear        = in_array('year', $masterCols, true) ? 'em.year' : "NULL AS year";
        $colWw          = in_array('ww', $masterCols, true) ? 'em.ww' : "NULL AS ww";
        $colMonth       = in_array('month', $masterCols, true) ? 'em.month' : "NULL AS month";

        $colSignStatus  = in_array('internal_status', $signoffCols, true) ? 'es.internal_status AS signoff_status' : "NULL AS signoff_status";
        $colPrevStatus  = in_array('previous_internal_status', $signoffCols, true) ? 'es.previous_internal_status' : "NULL AS previous_internal_status";
        $colSignUser    = in_array('user_name', $signoffCols, true) ? 'es.user_name AS signoff_user' : "NULL AS signoff_user";
        $colSignDur     = in_array('signoff_duration', $signoffCols, true) ? 'es.signoff_duration' : "NULL AS signoff_duration";
        $colSignRole    = in_array('user_role', $signoffCols, true) ? 'es.user_role AS signoff_role' : "NULL AS signoff_role";
        $colSignDate    = in_array('status_entry_date', $signoffCols, true) ? 'es.status_entry_date AS signoff_date' : "NULL AS signoff_date";
        $colStatusChg   = in_array('status_changed', $signoffCols, true) ? 'es.status_changed' : "0 AS status_changed";
        $colStatusChgAt = in_array('status_changed_at', $signoffCols, true) ? 'es.status_changed_at' : "NULL AS status_changed_at";
        $colIsUnmatched = in_array('is_unmatched', $signoffCols, true) ? 'es.is_unmatched' : "0 AS is_unmatched";

        $colRework      = in_array('rework_need', $trackingCols, true) ? 'et.rework_need' : "NULL AS rework_need";
        $colEcrCat      = in_array('ecr_category', $trackingCols, true) ? 'et.ecr_category' : "NULL AS ecr_category";
        $colPmcComp     = in_array('pmc_result_completed', $trackingCols, true) ? 'et.pmc_result_completed' : (in_array('pme_result_completed', $trackingCols, true) ? 'et.pme_result_completed AS pmc_result_completed' : "0 AS pmc_result_completed");
        $colQaComp      = in_array('qa_result_completed', $trackingCols, true) ? 'et.qa_result_completed' : "0 AS qa_result_completed";
        $colFirstMo     = in_array('first_mo_result', $trackingCols, true) ? 'et.first_mo_result' : "NULL AS first_mo_result";
        $colDueDate     = in_array('due_date', $trackingCols, true) ? 'et.due_date' : "NULL AS due_date";
        $colProgress    = in_array('status_progress', $trackingCols, true) ? 'et.status_progress' : "'Pending PMC' AS status_progress";
        $colImpact      = in_array('impact_assessment_checklist', $trackingCols, true) ? 'et.impact_assessment_checklist' : "NULL AS impact_assessment_checklist";
        $colPending     = in_array('pending_checklist', $trackingCols, true) ? 'et.pending_checklist' : "NULL AS pending_checklist";
        $colUpdatedBy   = in_array('updated_by', $trackingCols, true) ? 'et.updated_by' : "NULL AS updated_by";
        $colUpdatedDate = in_array('updated_date', $trackingCols, true) ? 'et.updated_date' : "NULL AS updated_date";

        $hasSignoffTbl  = !empty($signoffCols);
        $hasTrackingTbl = !empty($trackingCols);

        $signoffJoin = $hasSignoffTbl ? "LEFT JOIN `eco_signoff` es ON em.eco_no = es.eco_no" : "";
        $trackingJoin = $hasTrackingTbl ? "LEFT JOIN `eco_tracking` et ON em.eco_no = et.eco_no" : "";

        $orderBy = in_array('year', $masterCols, true) && in_array('ww', $masterCols, true)
            ? "ORDER BY em.year DESC, em.ww DESC, em.eco_no DESC"
            : "ORDER BY em.eco_no DESC";

        $sql = "
            SELECT
                {$colEcoNo},
                {$colEcrNo},
                {$colCustomer},
                {$colProject},
                {$colMethod},
                {$colCategory},
                {$colStatus},
                {$colSubject},
                {$colAgileStatus},
                {$colWipAction},
                {$colFgAction},
                {$colWhAction},
                {$colCutInMo},
                {$colCutInDate},
                {$colPmcSite},
                {$colQaSite},
                {$colOrigDate},
                {$colCompDate},
                {$colYear},
                {$colWw},
                {$colMonth},
                {$colSignStatus},
                {$colPrevStatus},
                {$colSignUser},
                {$colSignDur},
                {$colSignRole},
                {$colSignDate},
                {$colStatusChg},
                {$colStatusChgAt},
                {$colIsUnmatched},
                {$colRework},
                {$colEcrCat},
                {$colPmcComp},
                {$colQaComp},
                {$colFirstMo},
                {$colDueDate},
                {$colProgress},
                {$colImpact},
                {$colPending},
                {$colUpdatedBy},
                {$colUpdatedDate}
            FROM `eco_master` em
            {$signoffJoin}
            {$trackingJoin}
            {$orderBy}
        ";

        $stmt = $pdo->query($sql);
        $records = $stmt->fetchAll();

        // Calculate KPI values
        $totalEco = count($records);
        $today = date('Y-m-d');

        foreach ($records as $r) {
            $progress = $r['status_progress'] ?: ECOProcessor::calculateStatusProgress($r['pmc_result_completed'] ?? 0, $r['qa_result_completed'] ?? 0);
            if ($progress === 'Completed') {
                $completed++;
            } elseif ($progress === 'Pending QA') {
                $pendingQa++;
            } else {
                $pendingPmc++;
            }

            if (!empty($r['due_date']) && $r['due_date'] < $today && $progress !== 'Completed') {
                $overdue++;
            }

            if (!empty($r['status_changed'])) {
                $statusChanged++;
            }

            if (!empty($r['is_unmatched'])) {
                $unmatchedSignoff++;
            }

            // Dropdown options
            if (!empty($r['customer'])) $customers[$r['customer']] = true;
            if (!empty($r['project'])) $projects[$r['project']] = true;
            if (!empty($r['status_in_agile'])) $agileStatuses[$r['status_in_agile']] = true;
            if (!empty($r['year'])) $years[$r['year']] = true;
            if (!empty($r['ww'])) $workWeeks[$r['ww']] = true;
        }

        // Also check signoff-only records count if table exists
        if ($hasSignoffTbl && in_array('is_unmatched', $signoffCols, true)) {
            $stmtSignOnly = $pdo->query("SELECT COUNT(*) FROM `eco_signoff` WHERE is_unmatched = 1");
            $unmatchedSignoff = (int)$stmtSignOnly->fetchColumn();
        }

        ksort($customers);
        ksort($projects);
        ksort($agileStatuses);
        krsort($years);
        ksort($workWeeks, SORT_NUMERIC);

    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automated ECO Tracker System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<div class="app-container">

    <!-- Top Executive Header -->
    <header class="app-header">
        <div class="header-title-group">
            <h1>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#0284c7;">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                    <polyline points="2 17 12 22 22 17"></polyline>
                    <polyline points="2 12 12 17 22 12"></polyline>
                </svg>
                Automated ECO Tracker System
            </h1>
            <p>Agile PLM Master Integration & Manual Engineering Change Tracking</p>
        </div>

        <div class="header-actions">
            <?php if ($statusChanged > 0): ?>
                <button type="button" id="dismissAllChangesBtn" class="btn btn-warning btn-sm" title="Dismiss all highlight badges">
                    Dismiss Highlights (<?= $statusChanged ?>)
                </button>
            <?php endif; ?>

            <button type="button" id="openUploadModal" class="btn btn-primary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                Upload Agile Files
            </button>

            <button type="button" id="exportCsvBtn" class="btn btn-outline">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Export CSV
            </button>
        </div>
    </header>

    <?php if (!$pdo): ?>
        <div style="background:#fee2e2; border:1px solid #f87171; border-radius:10px; padding:18px 24px; margin-bottom:24px; color:#991b1b;">
            <h3 style="margin-bottom:6px; font-size:16px;">Database Connection Notice</h3>
            <p style="font-size:13.5px;">Unable to connect to MySQL database: <strong><?= h($db_connection_error ?? 'Check credentials in db.php') ?></strong></p>
            <p style="font-size:12.5px; margin-top:6px; color:#7f1d1d;">When running on your LAMP server, ensure the database name <code>eco_db</code> and credentials are created using <code>schema.sql</code>.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($dbError)): ?>
        <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:18px 24px; margin-bottom:24px; color:#92400e;">
            <h3 style="margin-bottom:6px; font-size:16px;">Database Query Notice</h3>
            <p style="font-size:13.5px;">Query message: <strong><?= h($dbError) ?></strong></p>
            <p style="font-size:12.5px; margin-top:6px; color:#78350f;">If you have legacy tables from an older schema, you can run <a href="migration.sql" target="_blank" style="color:#b45309; font-weight:600;">migration.sql</a> in phpMyAdmin to upgrade them.</p>
        </div>
    <?php endif; ?>

    <!-- KPI Summary Cards -->
    <section class="kpi-grid">
        <div class="kpi-card total" data-filter="all">
            <div class="kpi-label">
                <span>Total ECOs</span>
                <span style="color:#0284c7;">●</span>
            </div>
            <div class="kpi-value" id="kpiTotalEco"><?= number_format($totalEco) ?></div>
            <div class="kpi-subtext">Agile Master records</div>
            <div class="kpi-indicator"></div>
        </div>

        <div class="kpi-card pending-pmc" data-filter="pending_pmc">
            <div class="kpi-label">
                <span>Pending PMC</span>
                <span style="color:#d97706;">●</span>
            </div>
            <div class="kpi-value" id="kpiPendingPmc"><?= number_format($pendingPmc) ?></div>
            <div class="kpi-subtext">Requires PMC signoff</div>
            <div class="kpi-indicator"></div>
        </div>

        <div class="kpi-card pending-qa" data-filter="pending_qa">
            <div class="kpi-label">
                <span>Pending QA</span>
                <span style="color:#2563eb;">●</span>
            </div>
            <div class="kpi-value" id="kpiPendingQa"><?= number_format($pendingQa) ?></div>
            <div class="kpi-subtext">PMC completed, waiting QA</div>
            <div class="kpi-indicator"></div>
        </div>

        <div class="kpi-card completed" data-filter="completed">
            <div class="kpi-label">
                <span>Completed</span>
                <span style="color:#16a34a;">●</span>
            </div>
            <div class="kpi-value" id="kpiCompleted"><?= number_format($completed) ?></div>
            <div class="kpi-subtext">Both PMC & QA completed</div>
            <div class="kpi-indicator"></div>
        </div>

        <div class="kpi-card overdue" data-filter="overdue">
            <div class="kpi-label">
                <span>Overdue</span>
                <span style="color:#dc2626;">●</span>
            </div>
            <div class="kpi-value" id="kpiOverdue"><?= number_format($overdue) ?></div>
            <div class="kpi-subtext">&gt;14 days from origin</div>
            <div class="kpi-indicator"></div>
        </div>

        <div class="kpi-card status-changed" data-filter="status_changed">
            <div class="kpi-label">
                <span>Status Changed</span>
                <span style="color:#8b5cf6;">●</span>
            </div>
            <div class="kpi-value" id="kpiStatusChanged"><?= number_format($statusChanged) ?></div>
            <div class="kpi-subtext">Recent signoff changes</div>
            <div class="kpi-indicator"></div>
        </div>

        <div class="kpi-card unmatched" data-filter="unmatched">
            <div class="kpi-label">
                <span>Unmatched</span>
                <span style="color:#64748b;">●</span>
            </div>
            <div class="kpi-value" id="kpiUnmatched"><?= number_format($unmatchedSignoff) ?></div>
            <div class="kpi-subtext">Signoff-only records</div>
            <div class="kpi-indicator"></div>
        </div>
    </section>

    <!-- Multi-Filter Toolbar -->
    <div class="filter-panel">
        <div class="filter-row-top">
            <div class="search-input-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchBox" class="search-input" placeholder="Search ECO#, ECR#, Subject, Customer, Project...">
            </div>

            <div class="filter-meta">
                <button type="button" id="resetFiltersBtn" class="btn btn-outline btn-sm">Reset Filters</button>
                <span id="tableRowCount" style="font-weight:600; color:#475569;">Showing <?= count($records) ?> of <?= count($records) ?> ECOs</span>
            </div>
        </div>

        <div class="filter-row-controls">
            <select id="filterCustomer" class="filter-select">
                <option value="">All Customers</option>
                <?php foreach (array_keys($customers) as $c): ?>
                    <option value="<?= h($c) ?>"><?= h($c) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filterProject" class="filter-select">
                <option value="">All Projects</option>
                <?php foreach (array_keys($projects) as $p): ?>
                    <option value="<?= h($p) ?>"><?= h($p) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filterAgileStatus" class="filter-select">
                <option value="">All Agile Statuses</option>
                <?php foreach (array_keys($agileStatuses) as $as): ?>
                    <option value="<?= h($as) ?>"><?= h($as) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filterTrackerStatus" class="filter-select">
                <option value="">All Tracker Statuses</option>
                <option value="Pending PMC">Pending PMC</option>
                <option value="Pending QA">Pending QA</option>
                <option value="Completed">Completed</option>
            </select>

            <select id="filterPmcCompleted" class="filter-select">
                <option value="">PMC: All</option>
                <option value="1">PMC: Completed (Yes)</option>
                <option value="0">PMC: Pending (No)</option>
            </select>

            <select id="filterQaCompleted" class="filter-select">
                <option value="">QA: All</option>
                <option value="1">QA: Completed (Yes)</option>
                <option value="0">QA: Pending (No)</option>
            </select>

            <select id="filterYear" class="filter-select">
                <option value="">All Years</option>
                <?php foreach (array_keys($years) as $y): ?>
                    <option value="<?= h($y) ?>"><?= h($y) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filterWw" class="filter-select">
                <option value="">All Work Weeks (WW)</option>
                <?php foreach (array_keys($workWeeks) as $w): ?>
                    <option value="<?= h($w) ?>">WW<?= h($w) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Data Table Container -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="eco-table">
                <thead>
                    <tr>
                        <th style="width: 38px;"></th>
                        <th>ECO Number</th>
                        <th>Customer / Project</th>
                        <th>Subject</th>
                        <th>Agile Status</th>
                        <th>Tracker Progress</th>
                        <th>PMC Site & Signoff</th>
                        <th>QA Site & Signoff</th>
                        <th>Signoff Approver</th>
                        <th>Due Date (14d)</th>
                        <th>WW / Year</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="ecoTableBody">
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="12">
                                <div class="empty-state">
                                    <h4>No ECO records found</h4>
                                    <p>Click "Upload Agile Files" above to import SearchResult and User Signoff spreadsheets.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r):
                            $ecoNo = h($r['eco_no']);
                            $pmcYes = !empty($r['pmc_result_completed']);
                            $qaYes  = !empty($r['qa_result_completed']);
                            $trackerStatus = $r['status_progress'] ?: ECOProcessor::calculateStatusProgress($pmcYes, $qaYes);

                            $isOverdue = (!empty($r['due_date']) && $r['due_date'] < $today && $trackerStatus !== 'Completed');
                            $hasChanged = !empty($r['status_changed']);
                            $agileUpper = strtoupper(trim((string)($r['status_in_agile'] ?? '')));
                        ?>
                        <tr class="eco-row expand-trigger <?= $hasChanged ? 'highlight-changed' : '' ?>"
                            id="row-<?= $ecoNo ?>"
                            data-eco="<?= $ecoNo ?>"
                            data-ecr="<?= h($r['ecr_no']) ?>"
                            data-customer="<?= h($r['customer']) ?>"
                            data-project="<?= h($r['project']) ?>"
                            data-subject="<?= h($r['subject']) ?>"
                            data-agile-status="<?= h($r['status_in_agile']) ?>"
                            data-tracker-status="<?= h($trackerStatus) ?>"
                            data-pmc-completed="<?= $pmcYes ? '1' : '0' ?>"
                            data-qa-completed="<?= $qaYes ? '1' : '0' ?>"
                            data-year="<?= h($r['year']) ?>"
                            data-ww="<?= h($r['ww']) ?>"
                        >
                            <td style="text-align:center; cursor:pointer;" title="Click to view full details">
                                <span class="expand-arrow" style="color:#0284c7; font-size:11px;">▶</span>
                            </td>

                            <!-- ECO No -->
                            <td>
                                <strong style="color:#1e3a8a; font-size:14px;"><?= $ecoNo ?></strong>
                                <?php if (!empty($r['ecr_no'])): ?>
                                    <div style="font-size:11.5px; color:#64748b;">ECR: <?= h($r['ecr_no']) ?></div>
                                <?php endif; ?>

                                <?php if ($hasChanged): ?>
                                    <div class="badge badge-changed" title="Previous: <?= h($r['previous_internal_status']) ?> &#10;New: <?= h($r['signoff_status']) ?>">
                                        🔄 STATUS CHANGED
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($r['is_unmatched'])): ?>
                                    <div class="badge" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;">
                                        Unmatched Signoff
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Customer / Project -->
                            <td>
                                <div><strong><?= dash($r['customer']) ?></strong></div>
                                <div style="font-size:12px; color:#64748b;"><?= dash($r['project']) ?></div>
                            </td>

                            <!-- Subject -->
                            <td style="max-width: 280px; white-space: normal; line-height: 1.35;">
                                <div style="font-size:12.5px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;" title="<?= h($r['subject']) ?>">
                                    <?= dash($r['subject']) ?>
                                </div>
                            </td>

                            <!-- Agile Status -->
                            <td>
                                <?php if ($agileUpper === 'OPEN'): ?>
                                    <span class="badge badge-open">OPEN</span>
                                <?php elseif ($agileUpper === 'CLOSED'): ?>
                                    <span class="badge badge-closed">CLOSED</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral"><?= dash($r['status_in_agile']) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Tracker Status -->
                            <td>
                                <?php if ($trackerStatus === 'Completed'): ?>
                                    <span class="badge badge-completed tracker-status-badge">Completed</span>
                                <?php elseif ($trackerStatus === 'Pending QA'): ?>
                                    <span class="badge badge-pending-qa tracker-status-badge">Pending QA</span>
                                <?php else: ?>
                                    <span class="badge badge-pending-pmc tracker-status-badge">Pending PMC</span>
                                <?php endif; ?>
                            </td>

                            <!-- PMC Site & Toggle -->
                            <td>
                                <span class="site-badge" title="<?= h($r['pmc_site']) ?>">
                                    <?= !empty($r['pmc_site']) ? h($r['pmc_site']) : '<span style="color:#94a3b8;">No PMC Site</span>' ?>
                                </span>
                                <button type="button"
                                        class="toggle-btn <?= $pmcYes ? 'yes' : 'no' ?>"
                                        data-eco="<?= $ecoNo ?>"
                                        data-field="pmc"
                                        title="Click to toggle PMC Completion"
                                >
                                    <?= $pmcYes ? '✓ YES' : '✗ NO' ?>
                                </button>
                            </td>

                            <!-- QA Site & Toggle -->
                            <td>
                                <span class="site-badge" title="<?= h($r['qa_site']) ?>">
                                    <?= !empty($r['qa_site']) ? h($r['qa_site']) : '<span style="color:#94a3b8;">No QA Site</span>' ?>
                                </span>
                                <button type="button"
                                        class="toggle-btn <?= $qaYes ? 'yes' : 'no' ?>"
                                        data-eco="<?= $ecoNo ?>"
                                        data-field="qa"
                                        title="Click to toggle QA Completion"
                                >
                                    <?= $qaYes ? '✓ YES' : '✗ NO' ?>
                                </button>
                            </td>

                            <!-- Signoff Approver & Status -->
                            <td>
                                <div style="font-weight:600; font-size:12.5px;"><?= dash($r['signoff_user']) ?></div>
                                <div style="font-size:11.5px; color:#64748b;">
                                    <?= dash($r['signoff_status']) ?>
                                    <?php if ($r['signoff_duration'] !== null): ?>
                                        (<?= h($r['signoff_duration']) ?>h)
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Due Date -->
                            <td>
                                <?php if ($isOverdue): ?>
                                    <span class="badge badge-overdue" title="Originated: <?= h($r['originated_date']) ?>">
                                        ⚠ <?= dash($r['due_date']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:12.5px;"><?= dash($r['due_date']) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- WW / Year -->
                            <td style="font-size:12.5px; color:#475569;">
                                <?= !empty($r['ww']) ? 'WW' . h($r['ww']) : '—' ?>
                                <span style="font-size:11px; color:#94a3b8;"><?= !empty($r['year']) ? '/' . h($r['year']) : '' ?></span>
                            </td>

                            <!-- Actions -->
                            <td style="text-align:center;">
                                <button type="button" class="btn btn-outline btn-sm open-edit-modal" data-eco="<?= $ecoNo ?>" title="Edit tracking details">
                                    Edit
                                </button>
                            </td>
                        </tr>

                        <!-- Collapsible Detail Accordion Tray -->
                        <tr class="detail-row" id="detail-<?= $ecoNo ?>">
                            <td colspan="12">
                                <div class="detail-content">
                                    <!-- Section 1: Agile PLM Master Data -->
                                    <div class="detail-section">
                                        <div class="detail-title">Agile PLM Specifications</div>
                                        <div class="detail-field"><span class="label">Implementation:</span> <span class="val"><?= dash($r['eco_implementation_method']) ?></span></div>
                                        <div class="detail-field"><span class="label">EC Type & Cat:</span> <span class="val"><?= dash($r['ec_type_category']) ?></span></div>
                                        <div class="detail-field"><span class="label">Originated Date:</span> <span class="val"><?= dash($r['originated_date']) ?></span></div>
                                        <div class="detail-field"><span class="label">Completed Date:</span> <span class="val"><?= dash($r['completed_date']) ?></span></div>
                                        <div class="detail-field"><span class="label">Cut In 1st M/O:</span> <span class="val"><?= dash($r['cut_in_first_mo']) ?></span></div>
                                        <div class="detail-field"><span class="label">Cut In Date:</span> <span class="val"><?= dash($r['cut_in_date']) ?></span></div>
                                    </div>

                                    <!-- Section 2: Actions & Signoff History -->
                                    <div class="detail-section">
                                        <div class="detail-title">Actions & Signoff Data</div>
                                        <div class="detail-field"><span class="label">WIP Action:</span> <span class="val"><?= dash($r['wip_action']) ?></span></div>
                                        <div class="detail-field"><span class="label">FG Action:</span> <span class="val"><?= dash($r['fg_action']) ?></span></div>
                                        <div class="detail-field"><span class="label">Warehouse Action:</span> <span class="val"><?= dash($r['warehouse_action']) ?></span></div>
                                        <div class="detail-field"><span class="label">Signoff User:</span> <span class="val"><?= dash($r['signoff_user']) ?></span></div>
                                        <div class="detail-field"><span class="label">Signoff Role:</span> <span class="val"><?= dash($r['signoff_role']) ?></span></div>
                                        <div class="detail-field"><span class="label">Signoff Date:</span> <span class="val"><?= dash($r['signoff_date']) ?></span></div>
                                        <?php if (!empty($r['status_changed_at'])): ?>
                                            <div class="detail-field"><span class="label">Status Changed:</span> <span class="val" style="color:#7c3aed;"><?= h($r['previous_internal_status']) ?> → <?= h($r['signoff_status']) ?> (<?= h($r['status_changed_at']) ?>)</span></div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Section 3: User Manual Tracking -->
                                    <div class="detail-section">
                                        <div class="detail-title">Manual Tracking Details</div>
                                        <div class="detail-field"><span class="label">First M/O Result:</span> <span class="val val-first-mo"><?= dash($r['first_mo_result']) ?></span></div>
                                        <div class="detail-field"><span class="label">Rework Need:</span> <span class="val val-rework"><?= dash($r['rework_need']) ?></span></div>
                                        <div class="detail-field"><span class="label">ECR Category:</span> <span class="val val-category"><?= dash($r['ecr_category']) ?></span></div>
                                        <div class="detail-field"><span class="label">Pending Checklist:</span> <span class="val val-pending"><?= dash($r['pending_checklist']) ?></span></div>
                                        <div class="detail-field"><span class="label">Impact Checklist:</span> <span class="val val-impact"><?= dash($r['impact_assessment_checklist']) ?></span></div>
                                        <div class="detail-field" style="margin-top:10px; font-size:11.5px; color:#64748b;">
                                            <span>Last updated by: <strong><?= dash($r['updated_by']) ?></strong> at <?= dash($r['updated_date']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal 1: Edit Tracking Details Modal -->
<div class="modal-overlay" id="editTrackingModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Edit Tracking Details: <span id="modalEcoNoTitle" style="color:#0284c7;"></span></h3>
            <button type="button" class="modal-close" id="closeEditModal">&times;</button>
        </div>
        <form id="editTrackingForm">
            <input type="hidden" name="eco_no" id="formEcoNo">
            <div class="modal-body">
                <div class="form-group">
                    <label for="formFirstMoResult">First M/O Result</label>
                    <input type="text" id="formFirstMoResult" name="first_mo_result" class="form-control" placeholder="e.g. Passed / Pending verification">
                </div>

                <div class="form-group">
                    <label for="formReworkNeed">Rework Need</label>
                    <textarea id="formReworkNeed" name="rework_need" class="form-control" placeholder="Describe rework requirements, supplier confirmation, etc."></textarea>
                </div>

                <div class="form-group">
                    <label for="formEcrCategory">ECR Category</label>
                    <input type="text" id="formEcrCategory" name="ecr_category" class="form-control" placeholder="e.g. Alternative Part, Design Change, Component Obsolescence">
                </div>

                <div class="form-group">
                    <label for="formPendingChecklist">Pending Checklist</label>
                    <textarea id="formPendingChecklist" name="pending_checklist" class="form-control" placeholder="Pending action items or deliverables"></textarea>
                </div>

                <div class="form-group">
                    <label for="formImpactChecklist">Impact Assessment Checklist</label>
                    <textarea id="formImpactChecklist" name="impact_assessment_checklist" class="form-control" placeholder="Impact on WIP, finished goods, scrap, or lead times"></textarea>
                </div>

                <div class="form-group">
                    <label for="formUpdatedBy">Updated By</label>
                    <input type="text" id="formUpdatedBy" name="updated_by" class="form-control" value="Dashboard User">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelEditModal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveTrackingBtn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Agile PLM Files Upload Wizard Modal -->
<div class="modal-overlay" id="uploadModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Upload Agile PLM Files</h3>
            <button type="button" class="modal-close" id="closeUploadModal">&times;</button>
        </div>
        <form id="uploadForm">
            <div class="modal-body">
                <p style="font-size:13px; color:#475569; margin-bottom:16px;">
                    Upload the latest export files from Agile PLM. The system will normalize ECO numbers, parse dates, calculate Thursday-based work weeks, detect signoff changes, and <strong>strictly preserve your manual tracking data</strong>.
                </p>

                <div class="form-group">
                    <label for="uploadSearchFile">1. SearchResult File (.xls, .xlsx, .csv) <span style="color:#dc2626;">*Required</span></label>
                    <input type="file" id="uploadSearchFile" name="search_file" class="form-control" accept=".xls,.xlsx,.csv" required>
                    <span style="font-size:11.5px; color:#64748b;">Expected columns: Change Number, Status, PMC_Site, QA_Site, Date Originated</span>
                </div>

                <div class="form-group">
                    <label for="uploadSignoffFile">2. User Signoff File (.xls, .xlsx) <span style="color:#0284c7;">(Optional / Recommended)</span></label>
                    <input type="file" id="uploadSignoffFile" name="signoff_file" class="form-control" accept=".xls,.xlsx,.csv">
                    <span style="font-size:11.5px; color:#64748b;">Expected columns: Change Number, Status, User Name, Signoff Duration</span>
                </div>

                <div class="form-group">
                    <label for="uploadImportedBy">Imported By</label>
                    <input type="text" id="uploadImportedBy" name="imported_by" class="form-control" value="Agile Importer">
                </div>

                <!-- Pre-flight check results container -->
                <div id="preFlightResults" style="display:none; margin-top:16px;"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="preFlightCheckBtn">Run Pre-Flight Check</button>
                <button type="submit" class="btn btn-primary" id="startImportBtn">Start Import</button>
                <button type="button" class="btn btn-outline" id="cancelUploadModal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>