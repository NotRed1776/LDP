<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $error): never {
    error_log('Lycan portal API failure: ' . $error->getMessage());
    api_json(500, ['ok' => false, 'error' => 'The server could not complete the request.']);
});

function api_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function api_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $file = dirname(__DIR__, 2) . '/lycan-config.php';
    if (!is_file($file)) {
        api_json(503, ['ok' => false, 'error' => 'The private server configuration is not installed.']);
    }

    $config = require $file;
    if (!is_array($config) || !isset($config['db'], $config['storage_dir'])) {
        api_json(503, ['ok' => false, 'error' => 'The private server configuration is incomplete.']);
    }
    return $config;
}

function api_storage_path(string $directory = ''): string
{
    $configured = api_config()['storage_dir'] ?? '';
    if (!is_string($configured) || trim($configured) === '') {
        api_json(503, ['ok' => false, 'error' => 'Private file storage is not configured.']);
    }

    $configured = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($configured));
    if ($configured[0] !== DIRECTORY_SEPARATOR && !preg_match('/^[A-Za-z]:\\\\/', $configured)) {
        $configured = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $configured;
    }
    $configured = rtrim($configured, DIRECTORY_SEPARATOR);
    $webRoot = realpath(dirname(__DIR__));
    $existing = $configured;
    while (!file_exists($existing)) {
        $parent = dirname($existing);
        if ($parent === $existing) {
            api_json(503, ['ok' => false, 'error' => 'Private file storage is not available.']);
        }
        $existing = $parent;
    }
    $resolved = realpath($existing);
    $webPrefix = $webRoot === false ? '' : rtrim($webRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($resolved === false || $webRoot === false || $resolved === $webRoot ||
        str_starts_with($resolved . DIRECTORY_SEPARATOR, $webPrefix)) {
        api_json(503, ['ok' => false, 'error' => 'Private file storage must be outside the public web root.']);
    }

    $path = $configured . ($directory === '' ? '' : DIRECTORY_SEPARATOR . $directory);
    if (file_exists($path)) {
        $resolvedPath = realpath($path);
        if ($resolvedPath === false || $resolvedPath === $webRoot ||
            str_starts_with($resolvedPath . DIRECTORY_SEPARATOR, $webPrefix)) {
            api_json(503, ['ok' => false, 'error' => 'Private file storage must be outside the public web root.']);
        }
    }
    return $path;
}

function api_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = api_config()['db'];
    foreach (['host', 'name', 'user', 'password'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '' || str_starts_with($config[$key], 'YOUR_')) {
            api_json(503, ['ok' => false, 'error' => 'The database connection is not configured.']);
        }
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . $config['host'] . ';dbname=' . $config['name'] . ';charset=utf8mb4',
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $error) {
        error_log('Lycan database connection failed: ' . $error->getMessage());
        api_json(503, ['ok' => false, 'error' => 'The database is temporarily unavailable.']);
    }
    return $pdo;
}

function api_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off';
    if (($config = api_config()) && ($config['require_https'] ?? true) && !$https) {
        api_json(400, ['ok' => false, 'error' => 'Use the secure HTTPS site to access the portal.']);
    }
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    session_name('lycan_portal');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 1800) {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    $_SESSION['last_activity'] = time();
}

function api_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        api_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
}

function api_input(): array
{
    if (isset($_POST['_json'])) {
        $input = json_decode((string)$_POST['_json'], true);
    } else {
        $raw = file_get_contents('php://input');
        $input = $raw === '' ? [] : json_decode($raw, true);
    }
    if (!is_array($input)) {
        api_json(400, ['ok' => false, 'error' => 'Invalid request body.']);
    }
    return $input;
}

function api_csrf(): void
{
    api_start_session();
    $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($given) || !hash_equals((string)$_SESSION['csrf'], $given)) {
        api_json(403, ['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']);
    }
}

function api_user(PDO $pdo, bool $allowPasswordReset = false): array
{
    api_start_session();
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        api_json(401, ['ok' => false, 'error' => 'Please sign in.']);
    }
    $stmt = $pdo->prepare('SELECT id, username, full_name, badge, role, must_reset, is_active FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['is_active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        api_json(401, ['ok' => false, 'error' => 'Please sign in again.']);
    }
    if ((int)$user['must_reset'] && !$allowPasswordReset) {
        api_json(403, ['ok' => false, 'error' => 'Choose a new password before using the portal.', 'must_reset' => true]);
    }
    $user['id'] = (int)$user['id'];
    $user['must_reset'] = (bool)$user['must_reset'];
    unset($user['is_active']);
    return $user;
}

function api_rank(string $role): int
{
    return [
        'employee' => 0, 'dispatcher' => 0, 'support' => 0, 'supervisor' => 1,
        'ia' => 1, 'hr' => 2, 'captain' => 2, 'asst_chief' => 3, 'chief' => 4, 'owner' => 5,
    ][$role] ?? 0;
}

function api_full(string $role): bool
{
    return in_array($role, ['owner', 'chief', 'asst_chief'], true);
}

function api_supervisor(string $role): bool
{
    return api_full($role) || in_array($role, ['supervisor', 'captain'], true);
}

function api_can_manage_staff(string $role): bool
{
    return api_supervisor($role) || $role === 'hr';
}

function api_can_manage_target(string $actorRole, string $targetRole): bool
{
    return $actorRole === 'owner' || api_rank($targetRole) < api_rank($actorRole);
}

function api_can_view(string $role, string $type): bool
{
    return match ($type) {
        'posts', 'bolos', 'gallery' => true,
        'timeoff' => true,
        'complaints' => api_full($role) || $role === 'ia',
        'quotes' => api_full($role) || $role === 'captain',
        'applications' => api_full($role) || $role === 'hr',
        default => false,
    };
}

function api_assignable_roles(string $role): array
{
    return match ($role) {
        'owner' => ['employee', 'dispatcher', 'support', 'supervisor', 'ia', 'hr', 'captain', 'asst_chief', 'chief', 'owner'],
        'chief', 'asst_chief' => ['employee', 'dispatcher', 'support', 'supervisor', 'ia', 'hr', 'captain', 'asst_chief', 'chief'],
        'captain' => ['employee', 'dispatcher', 'support', 'supervisor', 'ia'],
        'hr' => ['employee', 'dispatcher', 'support', 'supervisor'],
        'supervisor' => ['employee', 'dispatcher', 'support'],
        default => [],
    };
}

function api_secret_is_valid(string $secret): bool
{
    return preg_match('/^[a-f0-9]{64}$/i', $secret) === 1;
}

function api_require_roles(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        api_json(403, ['ok' => false, 'error' => 'You do not have permission to do that.']);
    }
}

function api_text(mixed $value, int $max = 2000): string
{
    if (!is_scalar($value)) {
        return '';
    }
    return mb_substr(trim((string)$value), 0, $max);
}

function api_id(): string
{
    return bin2hex(random_bytes(16));
}

function api_decode(string $json): array
{
    $value = json_decode($json, true);
    return is_array($value) ? $value : [];
}

api_start_session();
