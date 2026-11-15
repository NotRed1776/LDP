<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

$pdo = api_db();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$action = api_text($_GET['action'] ?? '', 40);

function public_user(array $user): array
{
    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'name' => $user['full_name'],
        'badge' => $user['badge'],
        'role' => $user['role'],
        'mustReset' => $user['must_reset'],
    ];
}

function send_notice(string $kind, string $subject, string $message): bool
{
    $mail = api_config()['mail'] ?? [];
    $to = $mail[$kind] ?? '';
    $from = $mail['from'] ?? '';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('Lycan notification email is not configured for ' . $kind);
        return false;
    }
    $headers = 'From: Lycan Division <' . $from . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return mail($to, 'Lycan Division: ' . $subject, $message, $headers);
}

function record_rows(PDO $pdo, string $type, ?int $owner = null): array
{
    if ($owner === null) {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.owner_user_id, r.payload, r.attachment_path, r.attachment_name, r.created_at, u.username AS owner_name
             FROM portal_records r LEFT JOIN users u ON u.id = r.owner_user_id
             WHERE r.record_type = ? ORDER BY r.sort_order ASC, r.created_at DESC'
        );
        $stmt->execute([$type]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.owner_user_id, r.payload, r.attachment_path, r.attachment_name, r.created_at, u.username AS owner_name
             FROM portal_records r LEFT JOIN users u ON u.id = r.owner_user_id
             WHERE r.record_type = ? AND r.owner_user_id = ? ORDER BY r.sort_order ASC, r.created_at DESC'
        );
        $stmt->execute([$type, $owner]);
    }
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $payload = api_decode($row['payload']);
        $payload['id'] = $row['id'];
        $payload['at'] = $row['created_at'];
        $payload['createdAt'] = $row['created_at'];
        if ($row['owner_name'] !== null) {
            $payload['by'] = $row['owner_name'];
        }
        if ($row['attachment_name'] !== null) {
            $payload['resumeName'] = $row['attachment_name'];
            $payload['resumeUrl'] = 'api/download.php?id=' . rawurlencode($row['id']);
        }
        if ($type === 'gallery' && $row['attachment_path'] !== null) {
            $payload['img'] = 'api/asset.php?id=' . rawurlencode($row['id']);
        }
        $rows[] = $payload;
    }
    return $rows;
}

function rate_limit(PDO $pdo, string $label, int $limit, int $minutes): void
{
    $config = api_config();
    $key = $config['app_key'] ?? '';
    if (!api_secret_is_valid((string)$key)) {
        api_json(503, ['ok' => false, 'error' => 'The security key is not configured on the server.']);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = hash_hmac('sha256', $label . '|' . $ip, $key);
    $stmt = $pdo->prepare('SELECT attempts, window_started_at, blocked_until FROM login_attempts WHERE bucket = ?');
    $stmt->execute([$bucket]);
    $row = $stmt->fetch();
    $now = time();
    if ($row && $row['blocked_until'] !== null && strtotime($row['blocked_until']) > $now) {
        api_json(429, ['ok' => false, 'error' => 'Too many attempts. Please wait before trying again.']);
    }
    if (!$row || ($now - strtotime($row['window_started_at'])) > $minutes * 60) {
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (bucket, attempts, window_started_at, blocked_until)
             VALUES (?, 1, NOW(), NULL)
             ON DUPLICATE KEY UPDATE attempts = 1, window_started_at = NOW(), blocked_until = NULL'
        );
        $stmt->execute([$bucket]);
        return;
    }
    if ((int)$row['attempts'] >= $limit) {
        api_json(429, ['ok' => false, 'error' => 'Too many attempts. Please wait before trying again.']);
    }
    $stmt = $pdo->prepare(
        'UPDATE login_attempts SET attempts = attempts + 1,
         blocked_until = IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), blocked_until)
         WHERE bucket = ?'
    );
    $stmt->execute([$limit, $bucket]);
}

function store_record(PDO $pdo, string $type, array $payload, ?int $owner = null, ?string $attachment = null, ?string $attachmentName = null, ?string $id = null): string
{
    $id ??= api_id();
    $stmt = $pdo->prepare(
        'INSERT INTO portal_records (id, record_type, owner_user_id, payload, attachment_path, attachment_name)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $type, $owner, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $attachment, $attachmentName]);
    return $id;
}

if ($method === 'GET') {
    if ($action === 'csrf') {
        api_json(200, ['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'me') {
        $stmt = $pdo->prepare('SELECT id, username, full_name, badge, role, must_reset, is_active FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id'] ?? 0]);
        $user = $stmt->fetch();
        if (!$user || !(int)$user['is_active']) {
            api_json(200, ['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => null]);
        }
        $user['id'] = (int)$user['id'];
        $user['must_reset'] = (bool)$user['must_reset'];
        unset($user['is_active']);
        api_json(200, ['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => public_user($user)]);
    }
    if ($action === 'public-gallery') {
        $gallery = record_rows($pdo, 'gallery');
        foreach ($gallery as &$photo) {
            unset($photo['by']);
        }
        unset($photo);
        api_json(200, ['ok' => true, 'gallery' => $gallery]);
    }
    if ($action === 'setup-status') {
        $setupKey = (string)(api_config()['setup_key'] ?? '');
        $appKey = (string)(api_config()['app_key'] ?? '');
        $owners = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner'")->fetchColumn();
        api_json(200, ['ok' => true, 'available' => $owners === 0 &&
            api_secret_is_valid($setupKey) && api_secret_is_valid($appKey)]);
    }
    if ($action === 'state') {
        $user = api_user($pdo);
        $stmt = $pdo->query('SELECT payload FROM portal_schedule WHERE id = 1');
        $schedule = $stmt->fetchColumn();
        $schedule = $schedule ? api_decode((string)$schedule) : null;
        if ($schedule !== null && !api_supervisor($user['role'])) {
            $username = $user['username'];
            $schedule['rows'] = array_values(array_filter(
                is_array($schedule['rows'] ?? null) ? $schedule['rows'] : [],
                static fn($row): bool => is_array($row) && ($row['owner'] ?? '') === $username
            ));
        }
        $allRecords = [];
        foreach (['posts', 'bolos', 'gallery', 'timeoff', 'complaints', 'quotes', 'applications'] as $type) {
            if (!api_can_view($user['role'], $type)) {
                continue;
            }
            $ownOnly = $type === 'timeoff' && !api_supervisor($user['role']);
            $allRecords[$type] = record_rows($pdo, $type, $ownOnly ? $user['id'] : null);
        }
        $stmt = $pdo->prepare('SELECT payload FROM portal_records WHERE record_type = ? AND owner_user_id = ? LIMIT 1');
        $stmt->execute(['profiles', $user['id']]);
        $profiles = [$user['username'] => api_decode((string)($stmt->fetchColumn() ?: '{}'))];
        $profiles = [$user['username'] => $profiles[$user['username']]];
        if (api_supervisor($user['role']) || $user['role'] === 'hr') {
            $stmt = $pdo->query(
                "SELECT r.owner_user_id, r.payload, u.username FROM portal_records r
                 JOIN users u ON u.id = r.owner_user_id WHERE r.record_type = 'profiles'"
            );
            foreach ($stmt->fetchAll() as $row) {
                $profile = api_decode($row['payload']);
                unset($profile['address'], $profile['homeAddress'], $profile['personalEmail'], $profile['email'],
                    $profile['fullName'], $profile['street'], $profile['city'], $profile['state'], $profile['zip']);
                $profiles[$row['username']] = $profile;
            }
        }
        $accounts = [];
        $stmt = $pdo->query('SELECT id, username, full_name, badge, role, must_reset, created_by, created_at FROM users WHERE is_active = 1 ORDER BY full_name');
        foreach ($stmt->fetchAll() as $row) {
            $accounts[] = [
                'id' => (int)$row['id'], 'username' => $row['username'], 'user' => $row['username'],
                'name' => $row['full_name'], 'badge' => $row['badge'], 'role' => $row['role'],
                'mustReset' => api_can_manage_staff($user['role']) ? (bool)$row['must_reset'] : false,
                'createdBy' => $row['created_by'] ? 'Supervisor' : '',
                'at' => $row['created_at'],
            ];
        }
        api_json(200, [
            'ok' => true,
            'csrf' => $_SESSION['csrf'],
            'user' => public_user($user),
            'accounts' => $accounts,
            'schedule' => $schedule,
            'profiles' => $profiles,
            'records' => $allRecords,
        ]);
    }
    api_json(404, ['ok' => false, 'error' => 'Unknown action.']);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    api_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

api_csrf();
$input = api_input();

if ($action === 'login') {
    rate_limit($pdo, 'login', 8, 15);
    $username = strtolower(api_text($input['username'] ?? '', 40));
    $password = (string)($input['password'] ?? '');
    if (strlen($password) > 200) {
        api_json(401, ['ok' => false, 'error' => 'Username or password is incorrect.']);
    }
    $stmt = $pdo->prepare('SELECT id, username, full_name, badge, role, password_hash, must_reset, is_active FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['is_active'] || !password_verify($password, (string)$user['password_hash'])) {
        api_json(401, ['ok' => false, 'error' => 'Username or password is incorrect.']);
    }
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE bucket = ?');
    $bucket = hash_hmac('sha256', 'login|' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), (string)api_config()['app_key']);
    $stmt->execute([$bucket]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $public = [
        'id' => (int)$user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'],
        'badge' => $user['badge'], 'role' => $user['role'], 'must_reset' => (bool)$user['must_reset'],
    ];
    api_json(200, ['ok' => true, 'user' => public_user($public), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'setup-password') {
    $user = api_user($pdo, true);
    $newPassword = (string)($input['newPassword'] ?? '');
    if (!$user['must_reset'] || strlen($newPassword) < 14 || strlen($newPassword) > 200) {
        api_json(400, ['ok' => false, 'error' => 'The new password must be at least 14 characters.']);
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    if (password_verify($newPassword, (string)$stmt->fetchColumn())) {
        api_json(400, ['ok' => false, 'error' => 'Choose a password different from your temporary password.']);
    }
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, must_reset = 0 WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
    api_json(200, ['ok' => true]);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    api_json(200, ['ok' => true]);
}

if ($action === 'submit') {
    $type = api_text($input['formType'] ?? '', 24);
    if (!in_array($type, ['quote', 'application', 'timeoff', 'complaint'], true)) {
        api_json(400, ['ok' => false, 'error' => 'Unsupported form.']);
    }
    rate_limit($pdo, 'submit-' . $type, 6, 15);
    $user = null;
    if ($type === 'timeoff') {
        $user = api_user($pdo);
    } elseif (isset($_SESSION['user_id'])) {
        $user = api_user($pdo);
    }
    $fields = match ($type) {
        'quote' => ['name' => 120, 'company' => 160, 'email' => 254, 'phone' => 40, 'service' => 100, 'message' => 3000],
        'application' => [
            'name' => 120, 'phone' => 40, 'email' => 254, 'city' => 120, 'state' => 80, 'age18' => 20,
            'position' => 120, 'employmentType' => 80, 'startDate' => 32, 'availability' => 500,
            'license' => 80, 'licenseNumber' => 100, 'firearms' => 80, 'transport' => 120,
            'experience' => 100, 'background' => 100, 'prevEmployer' => 200, 'references' => 2000, 'about' => 3000, 'certify' => 10,
        ],
        'timeoff' => ['name' => 120, 'employeeId' => 80, 'start' => 32, 'end' => 32, 'type' => 80, 'notes' => 3000],
        'complaint' => ['name' => 120, 'contact' => 254, 'date' => 32, 'location' => 200, 'category' => 120, 'involved' => 1000, 'details' => 5000],
        default => [],
    };
    if (!$fields) {
        api_json(400, ['ok' => false, 'error' => 'Unsupported form.']);
    }
    $payload = [];
    foreach ($fields as $field => $max) {
        $value = $input[$field] ?? '';
        $payload[$field] = is_array($value)
            ? implode(', ', array_map(static fn($v): string => api_text($v, 100), array_slice($value, 0, 20)))
            : api_text($value, $max);
    }
    if (isset($payload['email']) && $payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        api_json(400, ['ok' => false, 'error' => 'Enter a valid email address.']);
    }
    $requiredFields = match ($type) {
        'quote' => ['name', 'email', 'message'],
        'application' => ['name', 'phone', 'email', 'city', 'age18', 'position', 'references', 'about'],
        'timeoff' => ['name', 'start', 'end'],
        'complaint' => ['details'],
    };
    foreach ($requiredFields as $required) {
        if (array_key_exists($required, $payload) && $payload[$required] === '') {
            api_json(400, ['ok' => false, 'error' => 'Complete all required fields.']);
        }
        if ($type === 'application' && ($payload['age18'] !== 'Yes' || $payload['certify'] !== 'Yes')) {
            api_json(400, ['ok' => false, 'error' => 'Confirm the application requirements before submitting.']);
        }
        if ($type === 'timeoff') {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $payload['start']);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $payload['end']);
            if (!$start || !$end || $start->format('Y-m-d') !== $payload['start'] ||
                $end->format('Y-m-d') !== $payload['end'] || $end < $start) {
                api_json(400, ['ok' => false, 'error' => 'Enter a valid time-off date range.']);
            }
        }
    }
    if ($user !== null) {
        $payload['by'] = $user['username'];
    }
    if ($type === 'timeoff' && $user !== null) {
        $payload['name'] = $user['full_name'];
        $payload['employeeId'] = $user['badge'];
    }
    $typeMap = ['quote' => ['quotes', 'quote'], 'application' => ['applications', 'application'], 'timeoff' => ['timeoff', 'timeoff'], 'complaint' => ['complaints', 'concern']];
    [$recordType, $mailType] = $typeMap[$type];
    $payload['status'] = ['quote' => 'New', 'application' => 'New', 'timeoff' => 'Pending', 'complaint' => 'Open'][$type];
    $attachmentPath = null;
    $attachmentName = null;
    if ($type === 'application' && (!isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE)) {
        api_json(400, ['ok' => false, 'error' => 'Attach a PDF, DOC, or DOCX résumé.']);
    }
    if ($type === 'application' && isset($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['resume'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 1024 * 1024) {
            api_json(400, ['ok' => false, 'error' => 'Résumé upload failed or exceeds the 1 MB limit.']);
        }
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['pdf' => ['application/pdf'], 'doc' => ['application/msword'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document']];
        if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
            api_json(400, ['ok' => false, 'error' => 'Use a PDF, DOC, or DOCX résumé.']);
        }
        $directory = api_storage_path('resumes');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            error_log('Lycan private resume directory could not be created.');
            api_json(503, ['ok' => false, 'error' => 'Secure file storage is unavailable.']);
        }
        $attachmentPath = bin2hex(random_bytes(24)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $attachmentPath)) {
            api_json(503, ['ok' => false, 'error' => 'The résumé could not be stored securely.']);
        }
        $payload['resumeSize'] = (int)$file['size'];
        $attachmentName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string)$file['name']));
    }
    try {
        $id = store_record($pdo, $recordType, $payload, $user['id'] ?? null, $attachmentPath, $attachmentName);
    } catch (Throwable $error) {
        if ($attachmentPath !== null && isset($directory)) {
            @unlink($directory . DIRECTORY_SEPARATOR . $attachmentPath);
        }
        throw $error;
    }
    $subject = ucfirst($mailType) . ' submission';
    $body = "A new {$mailType} submission was received.\nReference: {$id}\n\n";
    foreach ($payload as $key => $value) {
        if ($key !== 'message') {
            $body .= ucfirst($key) . ': ' . (is_scalar($value) ? (string)$value : '') . "\n";
        }
    }
    foreach (['message', 'details', 'about'] as $messageKey) {
        if (isset($payload[$messageKey])) {
            $body .= "\n" . ucfirst($messageKey) . ":\n" . $payload[$messageKey] . "\n";
        }
    }
    if ($attachmentName !== null) {
        $body .= "\nRésumé stored securely as: " . $attachmentName . "\n";
    }
    $sent = send_notice($mailType, $subject, $body);
    api_json(201, ['ok' => true, 'notificationSent' => $sent, 'id' => $id]);
}

$user = api_user($pdo);

if ($action === 'profile-save') {
    $profile = $input['profile'] ?? null;
    if (!is_array($profile)) {
        api_json(400, ['ok' => false, 'error' => 'Invalid profile data.']);
    }
    $allowed = ['fullName', 'phone', 'email', 'street', 'city', 'state', 'zip', 'emergency'];
    $safe = [];
    foreach ($allowed as $key) {
        $safe[$key] = $key === 'emergency' && is_array($profile[$key] ?? null)
            ? array_slice(array_map(static function ($contact): array {
                if (!is_array($contact)) {
                    return [];
                }
                return [
                    'name' => api_text($contact['name'] ?? '', 120),
                    'relation' => api_text($contact['relation'] ?? '', 80),
                    'phone' => api_text($contact['phone'] ?? '', 40),
                ];
            }, $profile[$key]), 0, 5)
            : ($key === 'emergency' ? [] : api_text($profile[$key] ?? '', 254));
    }
    $stmt = $pdo->prepare("SELECT id FROM portal_records WHERE record_type = 'profiles' AND owner_user_id = ?");
    $stmt->execute([$user['id']]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $stmt = $pdo->prepare('UPDATE portal_records SET payload = ? WHERE id = ?');
        $stmt->execute([json_encode($safe, JSON_UNESCAPED_UNICODE), $existing]);
    } else {
        store_record($pdo, 'profiles', $safe, $user['id']);
    }
    api_json(200, ['ok' => true]);
}

if ($action === 'schedule-save') {
    api_require_roles($user, ['supervisor', 'captain', 'asst_chief', 'chief', 'owner']);
    if (!is_array($input['schedule'] ?? null)) {
        api_json(400, ['ok' => false, 'error' => 'Invalid schedule data.']);
    }
    $json = json_encode($input['schedule'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || strlen($json) > 50000) {
        api_json(400, ['ok' => false, 'error' => 'Schedule data is too large.']);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO portal_schedule (id, payload, updated_by) VALUES (1, ?, ?)
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$json, $user['id']]);
    api_json(200, ['ok' => true]);
}

if ($action === 'record-save') {
    api_require_roles($user, ['supervisor', 'captain', 'asst_chief', 'chief', 'owner']);
    $type = api_text($input['type'] ?? '', 24);
    if (!in_array($type, ['posts', 'bolos'], true) || !is_array($input['record'] ?? null)) {
        api_json(400, ['ok' => false, 'error' => 'Invalid record.']);
    }
    $record = $input['record'];
    $safe = [];
    $keys = $type === 'posts'
        ? ['tag' => 80, 'date' => 32, 'title' => 160, 'body' => 5000]
        : ['title' => 160, 'status' => 32, 'issued' => 32, 'description' => 2000, 'vehicle' => 500, 'lastSeen' => 500, 'reason' => 2000, 'action' => 2000, 'img' => 1200000];
    foreach ($keys as $key => $max) {
        $value = $record[$key] ?? '';
        $safe[$key] = $key === 'img' && $value !== ''
            ? (is_string($value) && preg_match('~^data:image/(?:jpeg|png|webp);base64,[A-Za-z0-9+/=]+$~', $value) ? $value : '')
            : api_text($value, $max);
    }
    if ($safe['title'] === '' || ($type === 'posts' && $safe['body'] === '')) {
        api_json(400, ['ok' => false, 'error' => 'A title and message are required.']);
    }
    $id = api_text($input['id'] ?? '', 32);
    if ($id !== '') {
        $stmt = $pdo->prepare('UPDATE portal_records SET payload = ? WHERE id = ? AND record_type = ?');
        $stmt->execute([json_encode($safe, JSON_UNESCAPED_UNICODE), $id, $type]);
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare('SELECT id FROM portal_records WHERE id = ? AND record_type = ?');
            $stmt->execute([$id, $type]);
            if (!$stmt->fetchColumn()) {
                api_json(404, ['ok' => false, 'error' => 'Record not found.']);
            }
        }
    } else {
        $id = store_record($pdo, $type, $safe, $user['id']);
    }
    api_json(200, ['ok' => true, 'id' => $id]);
}

if ($action === 'record-delete') {
    $type = api_text($input['type'] ?? '', 24);
    $id = api_text($input['id'] ?? '', 32);
    if (!in_array($type, ['posts', 'bolos', 'gallery', 'timeoff', 'complaints', 'quotes', 'applications'], true) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
        api_json(400, ['ok' => false, 'error' => 'Invalid record.']);
    }
    $stmt = $pdo->prepare('SELECT owner_user_id, attachment_path FROM portal_records WHERE id = ? AND record_type = ?');
    $stmt->execute([$id, $type]);
    $target = $stmt->fetch();
    if (!$target) {
        api_json(404, ['ok' => false, 'error' => 'Record not found.']);
    }
    $deleteRoles = match ($type) {
        'timeoff', 'posts', 'bolos', 'gallery' => ['supervisor', 'captain', 'asst_chief', 'chief', 'owner'],
        'complaints' => ['ia', 'asst_chief', 'chief', 'owner'],
        'quotes' => ['captain', 'asst_chief', 'chief', 'owner'],
        'applications' => ['hr', 'asst_chief', 'chief', 'owner'],
    };
    if ($type === 'timeoff' && (int)$target['owner_user_id'] === $user['id']) {
        // Employees may withdraw their own request.
    } else {
        api_require_roles($user, $deleteRoles);
    }
    $stmt = $pdo->prepare('DELETE FROM portal_records WHERE id = ? AND record_type = ?');
    $stmt->execute([$id, $type]);
    if ($target['attachment_path']) {
        $folder = $type === 'gallery' ? 'gallery' : 'resumes';
        $path = api_storage_path($folder) . DIRECTORY_SEPARATOR . basename($target['attachment_path']);
        if (is_file($path)) {
            unlink($path);
        }
    }
    api_json(200, ['ok' => true]);
}

if ($action === 'gallery-upload') {
    api_require_roles($user, ['supervisor', 'captain', 'asst_chief', 'chief', 'owner']);
    $caption = api_text($input['caption'] ?? '', 300);
    $file = $_FILES['photo'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
        api_json(400, ['ok' => false, 'error' => 'Choose an image under 5 MB.']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($file['tmp_name']);
    if (!isset($extensions[$mime]) || !$dimensions || $dimensions[0] > 6000 || $dimensions[1] > 6000 ||
        $dimensions[0] * $dimensions[1] > 25000000) {
        api_json(400, ['ok' => false, 'error' => 'Use a JPEG, PNG, or WebP image.']);
    }
    $directory = api_storage_path('gallery');
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        api_json(503, ['ok' => false, 'error' => 'Secure file storage is unavailable.']);
    }
    $stored = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $stored)) {
        api_json(503, ['ok' => false, 'error' => 'The image could not be stored securely.']);
    }
    try {
        $id = store_record($pdo, 'gallery', ['caption' => $caption, 'by' => $user['username']], $user['id'], $stored);
    } catch (Throwable $error) {
        @unlink($directory . DIRECTORY_SEPARATOR . $stored);
        throw $error;
    }
    api_json(201, ['ok' => true, 'id' => $id]);
}

if ($action === 'gallery-caption') {
    api_require_roles($user, ['supervisor', 'captain', 'asst_chief', 'chief', 'owner']);
    $id = api_text($input['id'] ?? '', 32);
    $stmt = $pdo->prepare("SELECT payload FROM portal_records WHERE id = ? AND record_type = 'gallery'");
    $stmt->execute([$id]);
    $payload = $stmt->fetchColumn();
    if ($payload === false) {
        api_json(404, ['ok' => false, 'error' => 'Photo not found.']);
    }
    $record = api_decode((string)$payload);
    $record['caption'] = api_text($input['caption'] ?? '', 300);
    $stmt = $pdo->prepare('UPDATE portal_records SET payload = ? WHERE id = ?');
    $stmt->execute([json_encode($record, JSON_UNESCAPED_UNICODE), $id]);
    api_json(200, ['ok' => true]);
}

if ($action === 'gallery-order') {
    api_require_roles($user, ['supervisor', 'captain', 'asst_chief', 'chief', 'owner']);
    $ids = $input['ids'] ?? null;
    if (!is_array($ids) || count($ids) > 200) {
        api_json(400, ['ok' => false, 'error' => 'Invalid photo order.']);
    }
    $pdo->beginTransaction();
    $update = $pdo->prepare("UPDATE portal_records SET sort_order = ? WHERE id = ? AND record_type = 'gallery'");
    foreach (array_values($ids) as $position => $id) {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            $pdo->rollBack();
            api_json(400, ['ok' => false, 'error' => 'Invalid photo order.']);
        }
        $update->execute([$position, $id]);
    }
    $pdo->commit();
    api_json(200, ['ok' => true]);
}

if ($action === 'record-action') {
    $type = api_text($input['type'] ?? '', 24);
    $id = api_text($input['id'] ?? '', 32);
    $verb = api_text($input['verb'] ?? '', 24);
    $allowedRoles = match ($type) {
        'timeoff' => ['supervisor', 'captain', 'asst_chief', 'chief', 'owner'],
        'complaints' => ['ia', 'asst_chief', 'chief', 'owner'],
        'quotes' => ['captain', 'asst_chief', 'chief', 'owner'],
        'applications' => ['hr', 'asst_chief', 'chief', 'owner'],
        default => [],
    };
    api_require_roles($user, $allowedRoles);
    if (!in_array($verb, ['status', 'note'], true)) {
        api_json(400, ['ok' => false, 'error' => 'Invalid action.']);
    }
    $stmt = $pdo->prepare('SELECT payload FROM portal_records WHERE id = ? AND record_type = ?');
    $stmt->execute([$id, $type]);
    $payload = $stmt->fetchColumn();
    if ($payload === false) {
        api_json(404, ['ok' => false, 'error' => 'Record not found.']);
    }
    $payload = api_decode((string)$payload);
    if ($verb === 'status') {
        $status = api_text($input['value'] ?? '', 32);
        $validStatus = [
            'timeoff' => ['Approved', 'Denied'], 'complaints' => ['Reviewed', 'Closed', 'Open'],
            'quotes' => ['Contacted', 'Closed', 'New'],
            'applications' => ['Interviewing', 'Hired', 'Not Selected', 'New'],
        ];
        if (!in_array($status, $validStatus[$type] ?? [], true)) {
            api_json(400, ['ok' => false, 'error' => 'Invalid status.']);
        }
        $payload['status'] = $status;
        $payload['decidedBy'] = $user['username'];
    } else {
        if (!in_array($type, ['timeoff', 'applications'], true)) {
            api_json(400, ['ok' => false, 'error' => 'Notes are not supported for this record.']);
        }
        $payload['note'] = api_text($input['note'] ?? '', 2000);
        $payload['noteBy'] = $user['username'];
    }
    $stmt = $pdo->prepare('UPDATE portal_records SET payload = ? WHERE id = ?');
    $stmt->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $id]);
    api_json(200, ['ok' => true]);
}

if ($action === 'change-password') {
    $current = (string)($input['currentPassword'] ?? '');
    $new = (string)($input['newPassword'] ?? '');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $currentHash = (string)$stmt->fetchColumn();
    if (!password_verify($current, $currentHash)) {
        api_json(400, ['ok' => false, 'error' => 'Current password is incorrect.']);
    }
    if (strlen($new) < 14 || strlen($new) > 200) {
        api_json(400, ['ok' => false, 'error' => 'New password must be at least 14 characters.']);
    }
    if (password_verify($new, $currentHash)) {
        api_json(400, ['ok' => false, 'error' => 'Choose a password different from your current password.']);
    }
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    api_json(200, ['ok' => true]);
}

if ($action === 'staff-create') {
    api_require_roles($user, ['supervisor', 'captain', 'hr', 'asst_chief', 'chief', 'owner']);
    $username = strtolower(api_text($input['username'] ?? '', 40));
    $name = api_text($input['name'] ?? '', 120);
    $badge = api_text($input['badge'] ?? '', 40);
    $badge = $badge === '' ? null : $badge;
    $role = api_text($input['role'] ?? '', 24);
    $temporary = (string)($input['temporaryPassword'] ?? '');
    $validRoles = ['employee', 'dispatcher', 'support', 'supervisor', 'ia', 'hr', 'captain', 'asst_chief', 'chief', 'owner'];
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username) || $name === '' || !in_array($role, $validRoles, true) ||
        strlen($temporary) < 14 || strlen($temporary) > 200) {
        api_json(400, ['ok' => false, 'error' => 'Enter valid account details and a temporary password of at least 14 characters.']);
    }
    if (!in_array($role, api_assignable_roles($user['role']), true)) {
        api_json(403, ['ok' => false, 'error' => 'You cannot create an account at that role level.']);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO users (username, full_name, badge, role, password_hash, must_reset, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)');
        $stmt->execute([$username, $name, $badge, $role, password_hash($temporary, PASSWORD_DEFAULT), $user['id']]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            api_json(409, ['ok' => false, 'error' => 'That username or badge is already in use.']);
        }
        throw $error;
    }
    api_json(201, ['ok' => true]);
}

if ($action === 'staff-reset') {
    api_require_roles($user, ['supervisor', 'captain', 'hr', 'asst_chief', 'chief', 'owner']);
    $targetId = (int)($input['userId'] ?? 0);
    $temporary = (string)($input['temporaryPassword'] ?? '');
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target || (int)$target['id'] === $user['id'] ||
        !api_can_manage_target($user['role'], $target['role']) || strlen($temporary) < 14 || strlen($temporary) > 200) {
        api_json(403, ['ok' => false, 'error' => 'That account cannot be reset by you.']);
    }
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, must_reset = 1 WHERE id = ?');
    $stmt->execute([password_hash($temporary, PASSWORD_DEFAULT), $targetId]);
    api_json(200, ['ok' => true]);
}

if ($action === 'staff-edit') {
    api_require_roles($user, ['supervisor', 'captain', 'hr', 'asst_chief', 'chief', 'owner']);
    $targetId = (int)($input['userId'] ?? 0);
    $name = api_text($input['name'] ?? '', 120);
    $badge = api_text($input['badge'] ?? '', 40);
    $badge = $badge === '' ? null : $badge;
    $role = api_text($input['role'] ?? '', 24);
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target || (int)$target['id'] === $user['id'] || $name === '' ||
        !in_array($role, api_assignable_roles($user['role']), true) ||
        !api_can_manage_target($user['role'], $target['role'])) {
        api_json(403, ['ok' => false, 'error' => 'That account cannot be edited by you.']);
    }
    try {
        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, badge = ?, role = ? WHERE id = ?');
        $stmt->execute([$name, $badge, $role, $targetId]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            api_json(409, ['ok' => false, 'error' => 'That badge number is already in use.']);
        }
        throw $error;
    }
    api_json(200, ['ok' => true]);
}

if ($action === 'staff-disable') {
    api_require_roles($user, ['supervisor', 'captain', 'hr', 'asst_chief', 'chief', 'owner']);
    $targetId = (int)($input['userId'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target || (int)$target['id'] === $user['id'] || !api_can_manage_target($user['role'], $target['role'])) {
        api_json(403, ['ok' => false, 'error' => 'That account cannot be disabled by you.']);
    }
    if ($target['role'] === 'owner') {
        $owners = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND is_active = 1")->fetchColumn();
        if ($owners <= 1) {
            api_json(409, ['ok' => false, 'error' => 'The last active owner cannot be disabled.']);
        }
    }
    $stmt = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
    $stmt->execute([$targetId]);
    api_json(200, ['ok' => true]);
}

api_json(404, ['ok' => false, 'error' => 'Unknown action.']);
