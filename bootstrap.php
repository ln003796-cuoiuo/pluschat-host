<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : [];

function pc_config(string $key, mixed $default = null): mixed {
    global $config;
    $envKey = 'PLUSCHAT_' . strtoupper($key);
    $env = getenv($envKey);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return $config[$key] ?? $default;
}

function pc_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $host = (string) pc_config('db_host', 'localhost');
    $name = (string) pc_config('db_name', 'pluschat');
    $user = (string) pc_config('db_user', 'admin');
    $password = (string) pc_config('db_password', '');
    if ($password === '') {
        throw new RuntimeException('Database password is not configured on the server.');
    }
    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function pc_json(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pc_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function pc_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        pc_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
}

function pc_token_user(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $matches)) {
        pc_json(401, ['ok' => false, 'error' => 'authentication_required']);
    }
    $stmt = pc_db()->prepare(
        'SELECT u.id, u.email, u.username, u.first_name, u.last_name
         FROM auth_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > UTC_TIMESTAMP() AND u.email_verified_at IS NOT NULL'
    );
    $stmt->execute([hash('sha256', $matches[1])]);
    $user = $stmt->fetch();
    if (!$user) {
        pc_json(401, ['ok' => false, 'error' => 'invalid_or_expired_token']);
    }
    return $user;
}


function pc_rate_limit(string $bucket, int $limit, int $windowSeconds): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $bucket . '|' . $ip);
    $windowSeconds = max(1, min(86400, $windowSeconds));
    $limit = max(1, min(1000, $limit));
    $pdo = pc_db();
    $sql = 'INSERT INTO api_rate_limits (bucket_key, window_started_at, hit_count) VALUES (?, UTC_TIMESTAMP(), 1)
        ON DUPLICATE KEY UPDATE
        hit_count = IF(TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP()) >= ' . $windowSeconds . ', 1, hit_count + 1),
        window_started_at = IF(TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP()) >= ' . $windowSeconds . ', UTC_TIMESTAMP(), window_started_at)';
    $pdo->prepare($sql)->execute([$key]);
    $stmt = $pdo->prepare('SELECT hit_count, GREATEST(1, ' . $windowSeconds . ' - TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP())) AS retry_after FROM api_rate_limits WHERE bucket_key = ?');
    $stmt->execute([$key]);
    $state = $stmt->fetch();
    if ($state && (int)$state['hit_count'] > $limit) {
        header('Retry-After: ' . (int)$state['retry_after']);
        pc_json(429, ['ok' => false, 'error' => 'rate_limit_exceeded', 'retry_after' => (int)$state['retry_after']]);
    }
}
