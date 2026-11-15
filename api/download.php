<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
api_method('GET');
$pdo = api_db();
$user = api_user($pdo);
if (!api_full($user['role']) && !in_array($user['role'], ['hr'], true)) {
    api_json(403, ['ok' => false, 'error' => 'You do not have permission to access this file.']);
}
$id = api_text($_GET['id'] ?? '', 32);
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    api_json(404, ['ok' => false, 'error' => 'File not found.']);
}
$stmt = $pdo->prepare(
    "SELECT attachment_path, attachment_name FROM portal_records WHERE id = ? AND record_type = 'applications'"
);
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record || !$record['attachment_path']) {
    api_json(404, ['ok' => false, 'error' => 'File not found.']);
}
$base = realpath(api_storage_path('resumes'));
$path = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . basename($record['attachment_path']));
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    api_json(404, ['ok' => false, 'error' => 'File not found.']);
}
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)$record['attachment_name']);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
