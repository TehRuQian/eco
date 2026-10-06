<?php
/**
 * api.php
 * RESTful AJAX JSON API for the Automated ECO Tracker System.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ECOProcessor.php';

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (!$pdo) {
    jsonResponse([
        'success' => false,
        'message' => 'Database connection failed: ' . ($db_connection_error ?? 'Unknown error')
    ], 500);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$processor = new ECOProcessor($pdo);
ECOProcessor::ensureSchema($pdo);

try {
    switch ($action) {

        /* -------------------------------------------------------------
         * 1. PRE-FLIGHT VALIDATION
         * ------------------------------------------------------------- */
        case 'validate_upload':
            $searchFile = $_FILES['search_file'] ?? null;
            $signoffFile = $_FILES['signoff_file'] ?? null;

            if (!$searchFile || $searchFile['error'] !== UPLOAD_ERR_OK) {
                jsonResponse([
                    'success' => false,
                    'message' => 'Please provide a valid SearchResult file (.xls, .xlsx, or .csv).'
                ], 400);
            }

            $signoffTmp = ($signoffFile && $signoffFile['error'] === UPLOAD_ERR_OK) ? $signoffFile['tmp_name'] : null;
            $signoffName = ($signoffFile && $signoffFile['error'] === UPLOAD_ERR_OK) ? $signoffFile['name'] : null;

            $validation = $processor->preFlightCheck(
                $searchFile['tmp_name'],
                $searchFile['name'],
                $signoffTmp,
                $signoffName
            );

            jsonResponse([
                'success' => true,
                'valid'   => $validation['valid'],
                'errors'  => $validation['errors'],
                'info'    => $validation['info']
            ]);
            break;

        /* -------------------------------------------------------------
         * 2. FILE IMPORT (TRANSACTIONAL)
         * ------------------------------------------------------------- */
        case 'import':
            $searchFile = $_FILES['search_file'] ?? null;
            $signoffFile = $_FILES['signoff_file'] ?? null;
            $importedBy = trim((string)($_POST['imported_by'] ?? 'User'));

            if (!$searchFile || $searchFile['error'] !== UPLOAD_ERR_OK) {
                jsonResponse([
                    'success' => false,
                    'message' => 'SearchResult file is required for import.'
                ], 400);
            }

            $signoffTmp = ($signoffFile && $signoffFile['error'] === UPLOAD_ERR_OK) ? $signoffFile['tmp_name'] : null;
            $signoffName = ($signoffFile && $signoffFile['error'] === UPLOAD_ERR_OK) ? $signoffFile['name'] : null;

            $result = $processor->processImport(
                $searchFile['tmp_name'],
                $searchFile['name'],
                $signoffTmp,
                $signoffName,
                $importedBy ?: 'User'
            );

            if ($result['success']) {
                jsonResponse($result);
            } else {
                jsonResponse($result, 422);
            }
            break;

        /* -------------------------------------------------------------
         * 3. TOGGLE PMC / QA STATUS (AJAX Toggle)
         * ------------------------------------------------------------- */
        case 'toggle_status':
            $ecoNo = ECOProcessor::normalizeEcoNo($_POST['eco_no'] ?? $_GET['eco_no'] ?? '');
            $field = strtolower(trim((string)($_POST['field'] ?? $_GET['field'] ?? '')));

            if ($ecoNo === '') {
                jsonResponse(['success' => false, 'message' => 'ECO number is required.'], 400);
            }

            if (!in_array($field, ['pmc', 'qa'], true)) {
                jsonResponse(['success' => false, 'message' => 'Invalid toggle field. Must be "pmc" or "qa".'], 400);
            }

            // Check or initialize eco_tracking
            $stmt = $pdo->prepare("SELECT * FROM eco_tracking WHERE eco_no = ? LIMIT 1");
            $stmt->execute([$ecoNo]);
            $tracking = $stmt->fetch();

            if (!$tracking) {
                // Initialize new tracking record if missing
                $insert = $pdo->prepare("
                    INSERT INTO eco_tracking (eco_no, pmc_result_completed, qa_result_completed, status_progress)
                    VALUES (?, 0, 0, 'Pending PMC')
                ");
                $insert->execute([$ecoNo]);
                $pmcVal = 0;
                $qaVal = 0;
            } else {
                $pmcVal = (int)$tracking['pmc_result_completed'];
                $qaVal  = (int)$tracking['qa_result_completed'];
            }

            // Flip value
            if ($field === 'pmc') {
                $pmcVal = ($pmcVal === 1) ? 0 : 1;
            } else {
                $qaVal = ($qaVal === 1) ? 0 : 1;
            }

            // Recalculate status progress according to Section 19
            $newProgress = ECOProcessor::calculateStatusProgress($pmcVal, $qaVal);
            $user = trim((string)($_POST['updated_by'] ?? 'User'));

            $update = $pdo->prepare("
                UPDATE eco_tracking SET
                    pmc_result_completed = ?,
                    qa_result_completed  = ?,
                    status_progress      = ?,
                    updated_by           = ?
                WHERE eco_no = ?
            ");
            $update->execute([$pmcVal, $qaVal, $newProgress, $user, $ecoNo]);

            jsonResponse([
                'success'              => true,
                'eco_no'               => $ecoNo,
                'field'                => $field,
                'pmc_result_completed' => $pmcVal,
                'qa_result_completed'  => $qaVal,
                'status_progress'      => $newProgress,
                'message'              => strtoupper($field) . " completion updated to " . ($field === 'pmc' ? ($pmcVal ? 'YES' : 'NO') : ($qaVal ? 'YES' : 'NO'))
            ]);
            break;

        /* -------------------------------------------------------------
         * 4. UPDATE MANUAL TRACKING DETAILS (Modal Edit)
         * ------------------------------------------------------------- */
        case 'update_tracking':
            $ecoNo = ECOProcessor::normalizeEcoNo($_POST['eco_no'] ?? '');
            if ($ecoNo === '') {
                jsonResponse(['success' => false, 'message' => 'ECO number is required.'], 400);
            }

            $reworkNeed        = ECOProcessor::cleanText($_POST['rework_need'] ?? null);
            $ecrCategory       = ECOProcessor::cleanText($_POST['ecr_category'] ?? null);
            $firstMoResult     = ECOProcessor::cleanText($_POST['first_mo_result'] ?? null);
            $impactChecklist   = ECOProcessor::cleanText($_POST['impact_assessment_checklist'] ?? null);
            $pendingChecklist  = ECOProcessor::cleanText($_POST['pending_checklist'] ?? null);
            $updatedBy         = ECOProcessor::cleanText($_POST['updated_by'] ?? 'User');

            // Upsert tracking row
            $sql = "
                INSERT INTO eco_tracking (
                    eco_no, rework_need, ecr_category, first_mo_result,
                    impact_assessment_checklist, pending_checklist, updated_by
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?
                ) ON DUPLICATE KEY UPDATE
                    rework_need = VALUES(rework_need),
                    ecr_category = VALUES(ecr_category),
                    first_mo_result = VALUES(first_mo_result),
                    impact_assessment_checklist = VALUES(impact_assessment_checklist),
                    pending_checklist = VALUES(pending_checklist),
                    updated_by = VALUES(updated_by)
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $ecoNo,
                $reworkNeed ?: null,
                $ecrCategory ?: null,
                $firstMoResult ?: null,
                $impactChecklist ?: null,
                $pendingChecklist ?: null,
                $updatedBy ?: 'User'
            ]);

            jsonResponse([
                'success' => true,
                'eco_no'  => $ecoNo,
                'message' => 'Tracking information saved successfully.'
            ]);
            break;

        /* -------------------------------------------------------------
         * 5. GET FULL ECO DETAILS
         * ------------------------------------------------------------- */
        case 'get_eco':
            $ecoNo = ECOProcessor::normalizeEcoNo($_GET['eco_no'] ?? '');
            if ($ecoNo === '') {
                jsonResponse(['success' => false, 'message' => 'ECO number is required.'], 400);
            }

            $sql = "
                SELECT
                    em.*,
                    es.internal_status, es.previous_internal_status, es.user_name as signoff_user,
                    es.signoff_duration, es.user_role as signoff_role, es.status_entry_date as signoff_date,
                    es.status_changed, es.status_changed_at, es.is_unmatched,
                    et.rework_need, et.ecr_category, et.pmc_result_completed, et.qa_result_completed,
                    et.first_mo_result, et.due_date, et.status_progress,
                    et.impact_assessment_checklist, et.pending_checklist, et.updated_by, et.updated_date
                FROM eco_master em
                LEFT JOIN eco_signoff es ON em.eco_no = es.eco_no
                LEFT JOIN eco_tracking et ON em.eco_no = et.eco_no
                WHERE em.eco_no = ?
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ecoNo]);
            $data = $stmt->fetch();

            if (!$data) {
                // Check if signoff-only record
                $stmtSign = $pdo->prepare("SELECT * FROM eco_signoff WHERE eco_no = ? LIMIT 1");
                $stmtSign->execute([$ecoNo]);
                $signOnly = $stmtSign->fetch();
                if ($signOnly) {
                    jsonResponse(['success' => true, 'eco' => ['eco_no' => $ecoNo, 'is_unmatched' => 1, 'signoff' => $signOnly]]);
                }
                jsonResponse(['success' => false, 'message' => 'ECO not found.'], 404);
            }

            jsonResponse(['success' => true, 'eco' => $data]);
            break;

        /* -------------------------------------------------------------
         * 6. GET DASHBOARD SUMMARY (KPIS)
         * ------------------------------------------------------------- */
        case 'get_dashboard_summary':
            $totalStmt = $pdo->query("SELECT COUNT(*) FROM eco_master");
            $totalEco = (int)$totalStmt->fetchColumn();

            $pendingPmcStmt = $pdo->query("
                SELECT COUNT(*) FROM eco_tracking
                WHERE status_progress = 'Pending PMC' OR (pmc_result_completed = 0 AND qa_result_completed = 0)
            ");
            $pendingPmc = (int)$pendingPmcStmt->fetchColumn();

            $pendingQaStmt = $pdo->query("
                SELECT COUNT(*) FROM eco_tracking
                WHERE status_progress = 'Pending QA' OR (pmc_result_completed = 1 AND qa_result_completed = 0)
            ");
            $pendingQa = (int)$pendingQaStmt->fetchColumn();

            $completedStmt = $pdo->query("
                SELECT COUNT(*) FROM eco_tracking
                WHERE status_progress = 'Completed' OR (pmc_result_completed = 1 AND qa_result_completed = 1)
            ");
            $completed = (int)$completedStmt->fetchColumn();

            $overdueStmt = $pdo->query("
                SELECT COUNT(*) FROM eco_tracking
                WHERE due_date < CURDATE() AND status_progress != 'Completed'
            ");
            $overdue = (int)$overdueStmt->fetchColumn();

            $statusChangedStmt = $pdo->query("
                SELECT COUNT(*) FROM eco_signoff
                WHERE status_changed = 1
            ");
            $statusChanged = (int)$statusChangedStmt->fetchColumn();

            $unmatchedStmt = $pdo->query("
                SELECT COUNT(*) FROM eco_signoff
                WHERE is_unmatched = 1
            ");
            $unmatched = (int)$unmatchedStmt->fetchColumn();

            jsonResponse([
                'success' => true,
                'summary' => [
                    'total_eco'         => $totalEco,
                    'pending_pmc'       => $pendingPmc,
                    'pending_qa'        => $pendingQa,
                    'completed'         => $completed,
                    'overdue'           => $overdue,
                    'status_changed'    => $statusChanged,
                    'unmatched_signoff' => $unmatched,
                ]
            ]);
            break;

        /* -------------------------------------------------------------
         * 7. DISMISS STATUS CHANGE HIGHLIGHT
         * ------------------------------------------------------------- */
        case 'dismiss_status_change':
            $ecoNo = ECOProcessor::normalizeEcoNo($_POST['eco_no'] ?? $_GET['eco_no'] ?? '');
            if ($ecoNo === '' || $ecoNo === 'ALL') {
                $pdo->exec("UPDATE eco_signoff SET status_changed = 0");
                jsonResponse(['success' => true, 'message' => 'All status change highlights dismissed.']);
            } else {
                $stmt = $pdo->prepare("UPDATE eco_signoff SET status_changed = 0 WHERE eco_no = ?");
                $stmt->execute([$ecoNo]);
                jsonResponse(['success' => true, 'eco_no' => $ecoNo, 'message' => 'Status change highlight dismissed.']);
            }
            break;

        default:
            jsonResponse(['success' => false, 'message' => "Unknown action '{$action}'."], 400);
            break;
    }
} catch (Throwable $e) {
    error_log("API Error [{$action}]: " . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ], 500);
}
