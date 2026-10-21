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
    $name = (string) pc_config('db_name', 'Pluschat');
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
    $maxBytes = 1024 * 1024;
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false) {
        pc_json(400, ['ok' => false, 'error' => 'request_body_unreadable']);
    }
    if (strlen($raw) > $maxBytes) {
        pc_json(413, ['ok' => false, 'error' => 'request_body_too_large', 'max_bytes' => $maxBytes]);
    }
    if (trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        pc_json(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    return $data;
}

function pc_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        pc_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
}

/**
 * Validate an opaque client-encrypted envelope without decrypting it.
 * Plaintext messages are deliberately rejected by write endpoints.
 */
function pc_encrypted_envelope(mixed $body): ?array {
    if (!is_string($body) || $body === '' || strlen($body) > 100000) return null;
    $envelope = json_decode($body, true);
    if (!is_array($envelope)
        || ($envelope['v'] ?? null) !== 'pc-e2ee-v1'
        || ($envelope['alg'] ?? null) !== 'AES-256-GCM'
        || !is_string($envelope['nonce'] ?? null)
        || !is_string($envelope['ciphertext'] ?? null)
        || !is_string($envelope['key_id'] ?? null)
        || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $envelope['key_id'])) {
        return null;
    }
    $decode = static function (string $value): string|false {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return false;
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        return base64_decode($padded, true);
    };
    $nonce = $decode($envelope['nonce']);
    $ciphertext = $decode($envelope['ciphertext']);
    if ($nonce === false || strlen($nonce) !== 12
        || $ciphertext === false || strlen($ciphertext) < 16 || strlen($ciphertext) > 64000) {
        return null;
    }
    return [
        'body' => json_encode([
            'v' => 'pc-e2ee-v1',
            'alg' => 'AES-256-GCM',
            'nonce' => $envelope['nonce'],
            'ciphertext' => $envelope['ciphertext'],
            'key_id' => $envelope['key_id'],
        ], JSON_UNESCAPED_SLASHES),
        'version' => 'pc-e2ee-v1',
    ];
}

function pc_token_user(bool $allowTwoFactorSetup = false): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\\s+([a-f0-9]{64})$/i', $header, $matches)) {
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
