<?php
require 'db.php';
$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("DELETE FROM eco_records WHERE id = ?");
$stmt->execute([$id]);
header("Location: index.php");
exit;
?>