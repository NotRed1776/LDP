<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
api_method('GET');
$pdo = api_db();
$id = api_text($_GET['id'] ?? '', 32);
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    api_json(404, ['ok' => false, 'error' => 'Image not found.']);
}
$stmt = $pdo->prepare("SELECT attachment_path FROM portal_records WHERE id = ? AND record_type = 'gallery'");
$stmt->execute([$id]);
$storedName = $stmt->fetchColumn();
if (!$storedName) {
    api_json(404, ['ok' => false, 'error' => 'Image not found.']);
}
$base = realpath(api_storage_path('gallery'));
$path = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . basename((string)$storedName));
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    api_json(404, ['ok' => false, 'error' => 'Image not found.']);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    api_json(404, ['ok' => false, 'error' => 'Image not found.']);
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
readfile($path);
