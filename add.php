<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO eco_records
        (year, ww, month, eco_no, ecr_no, customer, project, eco_method, ec_type_category, status, subject, status_in_agile)
        VALUES (:year,:ww,:month,:eco_no,:ecr_no,:customer,:project,:method,:ec,:status,:subject,:agile)");
    $stmt->execute([
        ':year' => $_POST['year'],
        ':ww' => $_POST['ww'],
        ':month' => $_POST['month'],
        ':eco_no' => $_POST['eco_no'],
        ':ecr_no' => $_POST['ecr_no'],
        ':customer' => $_POST['customer'],
        ':project' => $_POST['project'],
        ':method' => $_POST['eco_method'],
        ':ec' => $_POST['ec_type_category'],
        ':status' => $_POST['status'],
        ':subject' => $_POST['subject'],
        ':agile' => $_POST['status_in_agile'],
    ]);
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Add ECO Record</title>
<style>
    body { font-family: Arial, sans-serif; background:#f4f6f9; padding:20px; }
    .form-box { max-width:700px; margin:0 auto; background:#fff; padding:25px; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
    label { display:block; margin-top:12px; font-weight:600; font-size:13px; }
    input, select, textarea { width:100%; padding:8px; margin-top:4px; border:1px solid #ccc; border-radius:6px; font-size:14px; }
    button { margin-top:20px; padding:10px 20px; background:#1e8e3e; color:#fff; border:none; border-radius:6px; cursor:pointer; }
    a { text-decoration:none; color:#1a73e8; }
    .row { display:flex; gap:15px; }
    .row > div { flex:1; }
</style>
</head>
<body>
<div class="form-box">
    <h2>Add New ECO Record</h2>
    <form method="post">
        <div class="row">
            <div><label>Year</label><input type="number" name="year" value="2026" required></div>
            <div><label>WW</label><input type="number" name="ww" required></div>
            <div><label>Month</label><input type="text" name="month" placeholder="Jan" required></div>
        </div>
        <label>ECO No.</label><input type="text" name="eco_no" required>
        <label>ECR No.</label><input type="text" name="ecr_no">
        <label>Customer</label><input type="text" name="customer">
        <label>Project</label><input type="text" name="project">
        <label>ECO Implement Method</label>
        <select name="eco_method">
            <option>Immediate Change</option>
            <option>Running Change</option>
        </select>
        <label>EC Type and Category</label><input type="text" name="ec_type_category" placeholder="Material|EE Related">
        <label>Status</label>
        <select name="status">
            <option>Complete</option>
            <option>In Progress</option>
            <option>Pending</option>
            <option>Cancelled</option>
        </select>
        <label>Subject</label><textarea name="subject" rows="4"></textarea>
        <label>Status in Agile</label>
        <select name="status_in_agile">
            <option>CLOSED</option>
            <option>OPEN</option>
            <option>IN REVIEW</option>
        </select>
        <button type="submit">Save Record</button>
    </form>
    <p style="margin-top:15px;"><a href="index.php">&larr; Back to list</a></p>
</div>
</body>
</html>