<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

ini_set('display_errors', '0');

try {
    $configFile = dirname(__DIR__, 2) . '/lycan-config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Private database configuration file is missing');
    }

    $config = require $configFile;
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off';
    if (($config['require_https'] ?? true) && !$https) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Use the secure HTTPS site']);
        exit;
    }
    $db = $config['db'] ?? [];
    foreach (['host', 'name', 'user', 'password'] as $key) {
        if (!isset($db[$key]) || $db[$key] === '' || str_starts_with($db[$key], 'YOUR_')) {
            throw new RuntimeException("Database configuration is missing: {$key}");
        }
    }

    $charset = $db['charset'] ?? 'utf8mb4';
    $pdo = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset={$charset}",
        $db['user'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->query('SELECT 1');
    echo json_encode(['ok' => true]);
} catch (Throwable $error) {
    error_log('Lycan database check failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database connection is not configured']);
}
