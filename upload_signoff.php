<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function normalizeHeader($value)
{
    $value = str_replace("\xC2\xA0", ' ', (string)$value);
    $value = preg_replace('/\s+/u', ' ', trim($value));
    return strtolower(trim($value));
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$invalidRows = [];
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $errors[] = 'Your session token is invalid. Reload this page and try again.';
    }

    $upload = $_FILES['signoff_file'] ?? null;
    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Choose a report file that uploaded successfully.';
    } elseif ($upload['size'] > 10 * 1024 * 1024) {
        $errors[] = 'The report must be 10 MB or smaller.';
    } elseif (!is_uploaded_file($upload['tmp_name'])) {
        $errors[] = 'The uploaded file could not be verified.';
    } else {
        $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            $errors[] = 'Upload the User Signoff report as an .xls or .xlsx file.';
        }
    }

    if (!$errors) {
        try {
            $readerType = IOFactory::identify($upload['tmp_name']);
            if (!in_array($readerType, ['Xls', 'Xlsx', 'Html'], true)) {
                throw new RuntimeException('Unsupported spreadsheet format.');
            }

            $reader = IOFactory::createReader($readerType);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($upload['tmp_name']);
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            $spreadsheet->disconnectWorksheets();

            $requiredHeaders = ['change number', 'status'];
            $headerMap = [];
            $headerRowIndex = null;

            foreach (array_slice($rows, 0, 10, true) as $rowIndex => $row) {
                $normalized = array_map('normalizeHeader', $row);
                $matched = array_intersect($requiredHeaders, $normalized);
                if (count($matched) === count($requiredHeaders)) {
                    foreach ($normalized as $columnIndex => $header) {
                        if (in_array($header, $requiredHeaders, true)) {
                            $headerMap[$header] = $columnIndex;
                        }
                    }
                    $headerRowIndex = $rowIndex;
                    break;
                }
            }

            if ($headerRowIndex === null) {
                throw new RuntimeException('Required report headers were not found in the first 10 rows.');
            }

            $validRows = [];
            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex <= $headerRowIndex) {
                    continue;
                }

                $ecoNo = trim((string)($row[$headerMap['change number']] ?? ''));
                $internalStatus = trim((string)($row[$headerMap['status']] ?? ''));

                if ($ecoNo === '') {
                    continue;
                }

                $excelRow = $rowIndex + 1;
                if ($ecoNo === '' || $internalStatus === '') {
                    $invalidRows[] = "Row {$excelRow}: Change Number and Status are required.";
                    continue;
                }
                $validRows[] = [
                    'eco_no' => $ecoNo,
                    'internal_status' => $internalStatus,
                ];
            }

            if (!$validRows && !$invalidRows) {
                $errors[] = 'No signoff data rows were found in the report.';
            } elseif (!$invalidRows) {
                $findEco = $pdo->prepare('SELECT 1 FROM eco_master WHERE eco_no = ? LIMIT 1');
                $findDuplicate = $pdo->prepare(
                    'SELECT 1 FROM eco_signoff
                     WHERE eco_no = ? AND internal_status = ?
                     LIMIT 1'
                );
                $insert = $pdo->prepare(
                    'INSERT INTO eco_signoff (eco_no, internal_status)
                     VALUES (?, ?)'
                );

                $inserted = 0;
                $duplicates = 0;
                $unmatched = 0;
                $pdo->beginTransaction();

                try {
                    foreach ($validRows as $record) {
                        $findEco->execute([$record['eco_no']]);
                        if (!$findEco->fetchColumn()) {
                            $unmatched++;
                            continue;
                        }

                        $values = [
                            $record['eco_no'],
                            $record['internal_status'],
                        ];
                        $findDuplicate->execute($values);
                        if ($findDuplicate->fetchColumn()) {
                            $duplicates++;
                            continue;
                        }

                        $insert->execute($values);
                        $inserted++;
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }

                $result = [
                    'inserted' => $inserted,
                    'duplicates' => $duplicates,
                    'unmatched' => $unmatched,
                ];
            }
        } catch (Throwable $e) {
            error_log('Signoff import failed: ' . $e->getMessage());
            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'The report could not be read or imported. Check the PHP error log.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upload User Signoff Report</title>
<style>
:root{--bg:#f2f4f7;--panel:#fff;--ink:#1b2430;--muted:#6b7686;--line:#e3e7ed;--accent:#0b6e8a;--error:#9b2c2c;--error-bg:#fff0ef;--success:#236b42;--success-bg:#e8f5ec}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 "Segoe UI",system-ui,sans-serif}
main{max-width:760px;margin:48px auto;padding:0 20px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:24px}
h1{margin:0 0 8px;font-size:24px}
p{color:var(--muted)}
label{display:block;font-weight:600;margin:20px 0 8px}
input[type=file]{display:block;width:100%;padding:12px;border:1px solid var(--line);border-radius:6px;background:#fafbfc}
.actions{display:flex;gap:12px;align-items:center;margin-top:20px}
button,.link{display:inline-block;padding:9px 14px;border:1px solid var(--accent);border-radius:6px;background:var(--accent);color:white;font:inherit;text-decoration:none;cursor:pointer}
.link{background:white;color:var(--accent)}
.notice,.message{padding:12px 14px;border-radius:6px;margin-top:16px}
.notice{background:#f4f7f8;color:var(--muted)}
.error{background:var(--error-bg);color:var(--error)}
.success{background:var(--success-bg);color:var(--success)}
ul{margin-bottom:0}
</style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Upload User Signoff Report</h1>
        <p>Select the Oracle User Signoff report. Supported formats are .xls and .xlsx.</p>
        <div class="notice">
            Imported fields are Change Number and Status.
            Rows whose ECO number is not in eco_master are skipped. Exact repeats are not inserted again.
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="message error"><?= h($error) ?></div>
        <?php endforeach; ?>

        <?php if ($invalidRows): ?>
            <div class="message error">
                No rows were imported because the report has invalid rows:
                <ul><?php foreach ($invalidRows as $invalid): ?><li><?= h($invalid) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <div class="message success">
                Import complete: <?= h($result['inserted']) ?> inserted,
                <?= h($result['duplicates']) ?> already present,
                <?= h($result['unmatched']) ?> skipped because no matching ECO exists.
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <label for="signoff_file">Signoff report</label>
            <input id="signoff_file" name="signoff_file" type="file" accept=".xls,.xlsx" required>
            <div class="actions">
                <button type="submit">Upload and Import</button>
                <a class="link" href="index.php">Back to Dashboard</a>
            </div>
        </form>
    </section>
</main>
</body>
</html>
