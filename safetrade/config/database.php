<?php
/**
 * Database connection (mysqli) for SafeTrade.
 * XAMPP defaults assumed — change credentials for production.
 */
declare(strict_types=1);

define('DB_HOST', getenv('SAFETRADE_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('SAFETRADE_DB_NAME') ?: 'safetrade');
define('DB_USER', getenv('SAFETRADE_DB_USER') ?: 'root');
define('DB_PASS', getenv('SAFETRADE_DB_PASS') ?: '');
define('DB_PORT', (int)(getenv('SAFETRADE_DB_PORT') ?: 3306));

function db(): mysqli
{
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
        error_log('[SafeTrade] DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('Service temporarily unavailable. Please try again later.');
    }

    return $conn;
}
