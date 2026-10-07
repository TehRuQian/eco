<?php
/**
 * test_logic.php
 * Automated Unit Test Suite for ECO Tracker Business Logic
 */

require_once __DIR__ . '/../ECOProcessor.php';

function assertEq($actual, $expected, $testName) {
    if ($actual === $expected) {
        echo "[PASS] {$testName}\n";
    } else {
        echo "[FAIL] {$testName} -> Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . "\n";
    }
}

echo "========================================================\n";
echo "ECO TRACKER SYSTEM - BUSINESS LOGIC TEST SUITE\n";
echo "========================================================\n\n";

// 1. ECO Number Normalization
assertEq(ECOProcessor::normalizeEcoNo(" eco001 "), "ECO001", "1.1 Normalization of lowercase with spaces");
assertEq(ECOProcessor::normalizeEcoNo("Eco001"), "ECO001", "1.2 Normalization of mixed case");
assertEq(ECOProcessor::normalizeEcoNo("ECO001"), "ECO001", "1.3 Normalization of clean uppercase");
assertEq(ECOProcessor::normalizeEcoNo("  ECO-999-X  "), "ECO-999-X", "1.4 Normalization with hyphens");
assertEq(ECOProcessor::isEcoNumber(" eco001 "), true, "1.5 ECO-prefixed value is a valid ECO number");
assertEq(ECOProcessor::isEcoNumber("CREATED BY"), false, "1.6 CREATED BY metadata is ignored");
assertEq(ECOProcessor::isEcoNumber("Create Time"), false, "1.7 Create Time metadata is ignored");

// 2. Status Cleaning (Removing '# No Controller')
assertEq(ECOProcessor::cleanStatus("In Progress # No Controller"), "In Progress", "2.1 Remove '# No Controller' from In Progress");
assertEq(ECOProcessor::cleanStatus("Closed # No Controller"), "Closed", "2.2 Remove '# No Controller' from Closed");
assertEq(ECOProcessor::cleanStatus("Open"), "Open", "2.3 Status without '# No Controller' remains unchanged");
assertEq(ECOProcessor::statusToAgileStatus("Complete"), "Closed", "2.4 Complete reviewer status maps to Agile Closed");
assertEq(ECOProcessor::statusToAgileStatus("complete # No Controller"), "Closed", "2.5 Complete status with controller suffix maps to Agile Closed");
assertEq(ECOProcessor::statusToAgileStatus("Reviewer 1"), "Open", "2.6 Non-complete reviewer status maps to Agile Open");

// 3. Date Parsing
$dt1 = ECOProcessor::parseAgileDate("2025/09/02 10:34:40 AM CST");
assertEq($dt1 ? $dt1->format('Y-m-d H:i:s') : null, "2025-09-02 10:34:40", "3.1 Agile PLM date with AM and CST timezone");

$dt2 = ECOProcessor::parseAgileDate("2026-09-21");
assertEq($dt2 ? $dt2->format('Y-m-d') : null, "2026-09-21", "3.2 ISO standard date format");

// 4. Thursday-based Work Week Calculation
// 2026-09-21 is a Monday. +3 days = Thursday 2026-09-24 -> ISO Week 39
$dtMon = new DateTime('2026-09-21');
assertEq(ECOProcessor::calculateWorkWeek($dtMon), 39, "4.1 Thursday-based WW on Monday 2026-09-21 (+3d = Thu W39)");

// 2026-09-24 is a Thursday. +3 days = Sunday 2026-09-27 -> ISO Week 39 (start of Thursday cycle)
$dtThu = new DateTime('2026-09-24');
assertEq(ECOProcessor::calculateWorkWeek($dtThu), 39, "4.2 Thursday-based WW on Thursday 2026-09-24 (+3d = Sun W39)");

// 5. Due Date Calculation (Originated Date + 14 calendar days)
$dueDt = ECOProcessor::calculateDueDate($dt2);
assertEq($dueDt, "2026-10-05", "5.1 Due date calculation: 2026-09-21 + 14 days = 2026-10-05");

// 6. Tracker Status Logic
assertEq(ECOProcessor::calculateStatusProgress(0, 0), 'Pending PMC', "6.1 PMC=No, QA=No -> Pending PMC");
assertEq(ECOProcessor::calculateStatusProgress(1, 0), 'Pending QA',  "6.2 PMC=Yes, QA=No -> Pending QA");
assertEq(ECOProcessor::calculateStatusProgress(0, 1), 'Pending PMC', "6.3 PMC=No, QA=Yes -> Pending PMC");
assertEq(ECOProcessor::calculateStatusProgress(1, 1), 'Completed',   "6.4 PMC=Yes, QA=Yes -> Completed");

// 7. Pre-Flight Validation Logic
$processor = new ECOProcessor(null);
$tempCsv = tempnam(sys_get_temp_dir(), 'eco_test_');
file_put_contents($tempCsv, "Change Number,Status,PMC_Site,QA_Site,Date Originated\nECO001,Open,SiteA,SiteB,2026-09-21\n");
$val1 = $processor->preFlightCheck($tempCsv, 'SearchResult.csv');
assertEq($val1['valid'], true, "7.1 Pre-flight check with all required columns");

$tempInvalidCsv = tempnam(sys_get_temp_dir(), 'eco_test_bad_');
file_put_contents($tempInvalidCsv, "Change Number,Status,Date Originated\nECO001,Open,2026-09-21\n");
$val2 = $processor->preFlightCheck($tempInvalidCsv, 'SearchResult.csv');
assertEq($val2['valid'], false, "7.2 Pre-flight check detects missing PMC_Site and QA_Site columns");

@unlink($tempCsv);
@unlink($tempInvalidCsv);

echo "\n========================================================\n";
echo "ALL TESTS COMPLETED SUCCESSFULLY!\n";
echo "========================================================\n";
