<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Http
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::respond(400, ['error' => 'invalid_json', 'message' => 'Request body must be a JSON object.']);
        }
        return $data;
    }

    public static function method(string $method): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
            header('Allow: ' . strtoupper($method));
            self::respond(405, ['error' => 'method_not_allowed']);
        }
    }

    public static function respond(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function authenticate(PDO $db): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            self::respond(401, ['error' => 'unauthorized']);
        }

        $hash = hash('sha256', trim($matches[1]));
        $stmt = $db->prepare(
            'SELECT u.id, u.email, u.username, u.first_name, u.last_name
             FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > UTC_TIMESTAMP() AND u.email_verified_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $user = $stmt->fetch();
        if (!$user) {
            self::respond(401, ['error' => 'invalid_or_expired_token']);
        }
        return $user;
    }

    public static function originUrl(): string
    {
        return rtrim(Config::required('APP_BASE_URL'), '/');
    }
}
