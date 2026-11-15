<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
api_method('POST');
api_csrf();
$input = api_input();

$config = api_config();
$setupKey = (string)($config['setup_key'] ?? '');
$pdo = api_db();
$appKey = (string)($config['app_key'] ?? '');
if (!api_secret_is_valid($setupKey) || !api_secret_is_valid($appKey)) {
    api_json(503, ['ok' => false, 'error' => 'Initial setup is not configured on the server.']);
}
$bucket = hash_hmac('sha256', 'setup|' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $appKey);
$stmt = $pdo->prepare('SELECT attempts, window_started_at, blocked_until FROM login_attempts WHERE bucket = ?');
$stmt->execute([$bucket]);
$attempt = $stmt->fetch();
if ($attempt && $attempt['blocked_until'] !== null && strtotime($attempt['blocked_until']) > time()) {
    api_json(429, ['ok' => false, 'error' => 'Too many setup attempts. Please wait before trying again.']);
}
if (!hash_equals($setupKey, (string)($input['setupKey'] ?? ''))) {
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (bucket, attempts, window_started_at, blocked_until)
         VALUES (?, 1, NOW(), NULL)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1,
         blocked_until = IF(attempts + 1 >= 5, DATE_ADD(NOW(), INTERVAL 30 MINUTE), blocked_until)'
    );
    $stmt->execute([$bucket]);
    api_json(403, ['ok' => false, 'error' => 'The setup key is invalid.']);
}

$username = strtolower(api_text($input['username'] ?? '', 40));
$name = api_text($input['name'] ?? '', 120);
$password = (string)($input['password'] ?? '');
if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username) || $name === '' || strlen($password) < 14 || strlen($password) > 200) {
    api_json(400, ['ok' => false, 'error' => 'Enter a valid username and name, and a password of at least 14 characters.']);
}

try {
    $pdo->beginTransaction();
    $pdo->exec('INSERT IGNORE INTO portal_installation (id) VALUES (1)');
    $pdo->query('SELECT id FROM portal_installation WHERE id = 1 FOR UPDATE')->fetch();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner'")->fetchColumn();
    if ($count > 0) {
        $pdo->rollBack();
        api_json(409, ['ok' => false, 'error' => 'An owner account already exists; initial setup is closed.']);
    }
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, full_name, role, password_hash) VALUES (?, ?, 'owner', ?)"
    );
    $stmt->execute([$username, $name, password_hash($password, PASSWORD_DEFAULT)]);
    $pdo->exec('UPDATE portal_installation SET owner_created_at = NOW() WHERE id = 1');
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE bucket = ?');
    $stmt->execute([$bucket]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($error->getCode() === '23000') {
        api_json(409, ['ok' => false, 'error' => 'That username is already in use.']);
    }
    throw $error;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$stmt->fetchColumn();
$_SESSION['csrf'] = bin2hex(random_bytes(32));
api_json(201, ['ok' => true, 'csrf' => $_SESSION['csrf']]);
