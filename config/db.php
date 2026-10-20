<?php
/**
 * Database Connection for ECO Tracker System
 * Provides a shared PDO instance using utf8mb4 and prepared statement defaults.
 */

// Read local settings without exporting secrets to the process environment.
// Explicit LAMP / Apache environment variables take precedence, including empty passwords.
$localSettings = [];
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim(trim($key), "\"'");
        if (!in_array($key, ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'], true)) {
            continue;
        }
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $localSettings[$key] = $value;
    }
}
$dbSetting = static function (string $key, string $default) use ($localSettings): string {
    $value = getenv($key);
    return $value !== false ? $value : ($localSettings[$key] ?? $default);
};
$host     = $dbSetting('DB_HOST', 'localhost');
$dbname   = $dbSetting('DB_NAME', 'eco_db');
$username = $dbSetting('DB_USER', 'myuser');
$password = $dbSetting('DB_PASS', '');
unset($localSettings, $dbSetting, $value, $line);

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
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
