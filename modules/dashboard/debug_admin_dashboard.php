<?php
header('Content-Type: text/plain; charset=utf-8');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "Debug admin-dashboard wrapper\n";
echo "Time: " . date('c') . "\n";
echo "PHP: " . PHP_VERSION . "\n\n";

try {
    ob_start();
    require __DIR__ . '/admin-dashboard.php';
    ob_end_clean();
    echo "admin-dashboard.php: completed\n";
} catch (Throwable $e) {
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    echo "admin-dashboard.php: FAILED\n";
    echo "error: " . $e->getMessage() . "\n";
    echo "file: " . $e->getFile() . "\n";
    echo "line: " . $e->getLine() . "\n";
    echo "\ntrace:\n" . $e->getTraceAsString() . "\n";
}

