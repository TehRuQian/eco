<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

/*
|--------------------------------------------------------------------------
| Clean Text Function
|--------------------------------------------------------------------------
*/

function cleanText($value)
{
    if ($value === null) {
        return '';
    }

    // Remove non-breaking space
    $value = str_replace("\xC2\xA0", " ", $value);

    // Convert to UTF-8 safely
    $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

    return trim($value);
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {

        $file = $_FILES['csv_file']['tmp_name'];

        $handle = fopen($file, "r");

        if ($handle) {

            $count = 0;

            while (($data = fgetcsv($handle, 10000, ",", '"', "\\")) !== FALSE) {

                // Skip empty row
                if (empty($data)) {
                    continue;
                }

                // Skip rows that don't start with a year
                if (!isset($data[0]) || !is_numeric(trim($data[0]))) {
                    continue;
                }

                // Skip incomplete rows
                if (count($data) < 15) {
                    continue;
                }

                $year             = (int) cleanText($data[0]);
                $ww               = (int) cleanText($data[1]);
                $month            = cleanText($data[2]);
                $eco_no           = cleanText($data[3]);
                $ecr_no           = cleanText($data[4]);
                $customer         = cleanText($data[5]);
                $project          = cleanText($data[6]);
                $eco_method       = cleanText($data[7]);
                $ec_type_category = cleanText($data[8]);
                $status           = cleanText($data[9]);
                $subject          = cleanText($data[10]);
                $status_in_agile  = cleanText($data[11]);
                $wip_action       = cleanText($data[12]);
                $fg_action        = cleanText($data[13]);
                $warehouse_action = cleanText($data[14]);

                // Skip blank ECO No
                if (empty($eco_no)) {
                    continue;
                }

                $sql = "
                    INSERT INTO eco_master (
                        eco_no,
                        ecr_no,
                        customer,
                        project,
                        eco_implementation_method,
                        ec_type_category,
                        status,
                        subject,
                        status_in_agile,
                        wip_action,
                        fg_action,
                        warehouse_action,
                        year,
                        ww,
                        month
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )

                    ON DUPLICATE KEY UPDATE

                        ecr_no = VALUES(ecr_no),
                        customer = VALUES(customer),
                        project = VALUES(project),
                        eco_implementation_method = VALUES(eco_implementation_method),
                        ec_type_category = VALUES(ec_type_category),
                        status = VALUES(status),
                        subject = VALUES(subject),
                        status_in_agile = VALUES(status_in_agile),
                        wip_action = VALUES(wip_action),
                        fg_action = VALUES(fg_action),
                        warehouse_action = VALUES(warehouse_action),
                        year = VALUES(year),
                        ww = VALUES(ww),
                        month = VALUES(month)
                ";

                $stmt = $pdo->prepare($sql);

                try {

                    $stmt->execute([
                        $eco_no,
                        $ecr_no,
                        $customer,
                        $project,
                        $eco_method,
                        $ec_type_category,
                        $status,
                        $subject,
                        $status_in_agile,
                        $wip_action,
                        $fg_action,
                        $warehouse_action,
                        $year,
                        $ww,
                        $month
                    ]);

                    $count++;

                } catch (Exception $e) {

                    echo "<pre>";
                    echo "ERROR ON ECO: " . $eco_no . "\n";
                    echo $e->getMessage();
                    echo "</pre>";

                    exit;
                }
            }

            fclose($handle);

            $message = "? Import Successful. Imported {$count} record(s).";

        } else {

            $message = "? Unable to open CSV file.";

        }

    } else {

        $message = "? Please select a CSV file.";

    }
}

?>

<!DOCTYPE html>
<html>
<head>

    <title>Upload ECO Tracker</title>

    <style>

        body{
            font-family: Arial, sans-serif;
            margin:40px;
        }

        .container{
            max-width:800px;
        }

        button{
            padding:8px 16px;
        }

    </style>

</head>

<body>

<div class="container">

    <h2>Upload ECO Tracker CSV</h2>

    <form method="post" enctype="multipart/form-data">

        <input type="file" name="csv_file" accept=".csv" required>

        <br><br>

        <button type="submit">
            Upload
        </button>

    </form>

    <br>

    <?php if (!empty($message)): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

</div>

</body>
</html>