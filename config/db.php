<?php
/**
 * Database Connection for ECO Tracker System
 * Provides a shared PDO instance using utf8mb4 and prepared statement defaults.
 */

// Allow override via environment variables if configured in LAMP / Apache
$host     = getenv('DB_HOST') ?: 'localhost';
$dbname   = getenv('DB_NAME') ?: 'eco_db';
$username = getenv('DB_USER') ?: 'myuser';
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'StrongPassword123!';
$port     = getenv('DB_PORT') ?: 3306;

$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // If running in CLI / debug or web, return clean error message
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Database connection failed: " . $e->getMessage() . PHP_EOL);
    }
    // Set $pdo to null so calling scripts can handle gracefully if needed
    $pdo = null;
    $db_connection_error = $e->getMessage();
}