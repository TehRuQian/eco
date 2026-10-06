<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

$sql = "
    SELECT
        eco_no,
        ecr_no,
        customer,
        project,
        eco_implementation_method,
        ec_type_category,
        status,
        status_in_agile
    FROM eco_master
    ORDER BY year DESC, ww DESC
";

$stmt = $pdo->query($sql);

?>

<!DOCTYPE html>
<html>
<head>

    <title>ECO Dashboard</title>

    <style>

        body{
            font-family: Arial, sans-serif;
            margin:20px;
        }

        h2{
            margin-bottom:20px;
        }

        table{
            width:100%;
            border-collapse:collapse;
        }

        th{
            background:#0078d4;
            color:white;
            padding:10px;
            border:1px solid #ddd;
        }

        td{
            padding:8px;
            border:1px solid #ddd;
        }

        tr:nth-child(even){
            background:#f5f5f5;
        }

        .open{
            color:red;
            font-weight:bold;
        }

        .closed{
            color:green;
            font-weight:bold;
        }

        .menu{
            margin-bottom:20px;
        }

        .menu a{
            text-decoration:none;
            padding:10px 15px;
            background:#0078d4;
            color:white;
            border-radius:5px;
            margin-right:10px;
        }

        .menu a:hover{
            background:#005ea6;
        }

    </style>

</head>

<body>

<h2>ECO Dashboard</h2>

<div class="menu">
    <a href="dashboard.php">Dashboard</a>
    upload_tracker.phpUpload Tracker</a>
</div>

<table>

    <tr>
        <th>ECO No</th>
        <th>ECR No</th>
        <th>Customer</th>
        <th>Project</th>
        <th>Method</th>
        <th>Category</th>
        <th>Status</th>
        <th>Agile Status</th>
    </tr>

<?php while($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>

    <tr>

        <td><?= htmlspecialchars($row['eco_no']) ?></td>

        <td><?= htmlspecialchars($row['ecr_no']) ?></td>

        <td><?= htmlspecialchars($row['customer']) ?></td>

        <td><?= htmlspecialchars($row['project']) ?></td>

        <td><?= htmlspecialchars($row['eco_implementation_method']) ?></td>

        <td><?= htmlspecialchars($row['ec_type_category']) ?></td>

        <td><?= htmlspecialchars($row['status']) ?></td>

        <td>

            <?php if(strtoupper($row['status_in_agile']) == 'OPEN'): ?>

                <span class="open">OPEN</span>

            <?php else: ?>

                <span class="closed">
                    <?= htmlspecialchars($row['status_in_agile']) ?>
                </span>

            <?php endif; ?>

        </td>

    </tr>

<?php endwhile; ?>

</table>

</body>
</html>