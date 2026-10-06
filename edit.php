<?php
require 'db.php';

$id = $_GET['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("UPDATE eco_records SET
        year=:year, ww=:ww, month=:month, eco_no=:eco_no, ecr_no=:ecr_no,
        customer=:customer, project=:project, eco_method=:method,
        ec_type_category=:ec, status=:status, subject=:subject, status_in_agile=:agile
        WHERE id=:id");
    $stmt->execute([
        ':year' => $_POST['year'], ':ww' => $_POST['ww'], ':month' => $_POST['month'],
        ':eco_no' => $_POST['eco_no'], ':ecr_no' => $_POST['ecr_no'],
        ':customer' => $_POST['customer'], ':project' => $_POST['project'],
        ':method' => $_POST['eco_method'], ':ec' => $_POST['ec_type_category'],
        ':status' => $_POST['status'], ':subject' => $_POST['subject'],
        ':agile' => $_POST['status_in_agile'], ':id' => $id
    ]);
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM eco_records WHERE id = ?");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) { die("Record not found."); }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Edit ECO Record</title>
<style>
    body { font-family: Arial, sans-serif; background:#f4f6f9; padding:20px; }
    .form-box { max-width:700px; margin:0 auto; background:#fff; padding:25px; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
    label { display:block; margin-top:12px; font-weight:600; font-size:13px; }
    input, select, textarea { width:100%; padding:8px; margin-top:4px; border:1px solid #ccc; border-radius:6px; font-size:14px; }
    button { margin-top:20px; padding:10px 20px; background:#1a73e8; color:#fff; border:none; border-radius:6px; cursor:pointer; }
    a { text-decoration:none; color:#1a73e8; }
    .row { display:flex; gap:15px; }
    .row > div { flex:1; }
</style>
</head>
<body>
<div class="form-box">
    <h2>Edit ECO Record</h2>
    <form method="post">
        <div class="row">
            <div><label>Year</label><input type="number" name="year" value="<?= htmlspecialchars($r['year']) ?>" required></div>
            <div><label>WW</label><input type="number" name="ww" value="<?= htmlspecialchars($r['ww']) ?>" required></div>
            <div><label>Month</label><input type="text" name="month" value="<?= htmlspecialchars($r['month']) ?>" required></div>
        </div>
        <label>ECO No.</label><input type="text" name="eco_no" value="<?= htmlspecialchars($r['eco_no']) ?>" required>
        <label>ECR No.</label><input type="text" name="ecr_no" value="<?= htmlspecialchars($r['ecr_no']) ?>">
        <label>Customer</label><input type="text" name="customer" value="<?= htmlspecialchars($r['customer']) ?>">
        <label>Project</label><input type="text" name="project" value="<?= htmlspecialchars($r['project']) ?>">
        <label>ECO Implement Method</label>
        <select name="eco_method">
            <option <?= $r['eco_method']==='Immediate Change'?'selected':'' ?>>Immediate Change</option>
            <option <?= $r['eco_method']==='Running Change'?'selected':'' ?>>Running Change</option>
        </select>
        <label>EC Type and Category</label><input type="text" name="ec_type_category" value="<?= htmlspecialchars($r['ec_type_category']) ?>">
        <label>Status</label>
        <select name="status">
            <?php foreach (['Complete','In Progress','Pending','Cancelled'] as $s): ?>
                <option <?= $r['status']===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <label>Subject</label><textarea name="subject" rows="4"><?= htmlspecialchars($r['subject']) ?></textarea>
        <label>Status in Agile</label>
        <select name="status_in_agile">
            <?php foreach (['CLOSED','OPEN','IN REVIEW'] as $s): ?>
                <option <?= $r['status_in_agile']===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Update Record</button>
    </form>
    <p style="margin-top:15px;"><a href="index.php">&larr; Back to list</a></p>
</div>
</body>
</html>