<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = pc_config('allowed_origins', ['https://xn--80avljg2a1c.xn--p1ai']);
if (is_array($allowedOrigins) && $origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function pc_len(string $value): int {
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    $count = preg_match_all('/./us', $value);
    return $count === false ? strlen($value) : $count;
}

function pc_string(array $data, string $key): string {
    $value = $data[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function pc_public_user(array $user): array {
    return [
        'id' => (string)$user['id'],
        'email' => (string)$user['email'],
        'username' => (string)$user['username'],
        'first_name' => (string)($user['first_name'] ?? ''),
        'last_name' => (string)($user['last_name'] ?? ''),
        'birth_date' => $user['birth_date'] ?? null,
        'email_verified' => !empty($user['email_verified_at']),
        'created_at' => $user['created_at'] ?? null,
    ];
}

function pc_send_verification(string $email, string $username, string $token): bool {
    $baseUrl = rtrim((string)pc_config('app_base_url', 'https://xn--80avljg2a1c.xn--p1ai'), '/');
    $from = (string)pc_config('mail_from', 'register@плюсчат.рф');
    $link = $baseUrl . '/api/v1/auth/verify?token=' . rawurlencode($token);
    $subject = 'Подтверждение почты Pluschat';
    $body = "Здравствуйте, {$username}!\n\nПодтвердите адрес электронной почты для Pluschat по ссылке:\n{$link}\n\nСсылка действует 24 часа. Если вы не регистрировались, просто проигнорируйте это письмо.";
    $headers = [
        'From: Pluschat <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: Pluschat PHP',
    ];
    return @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

function pc_require_verified_user(): array {
    return pc_token_user();
}

try {
    if ($path === '/' || $path === '/index.php') {
        pc_json(200, [
            'name' => 'Pluschat API',
            'status' => 'online',
            'api_version' => 1,
            'entrypoint_available' => true,
            'health' => '/api/v1/health',
        ]);
    }

    if ($path === '/api/v1/health' && $method === 'GET') {
        pc_db()->query('SELECT 1')->fetchColumn();
        pc_json(200, [
            'ok' => true,
            'service' => 'pluschat-api',
            'api_version' => 1,
            'database' => 'connected',
        ]);
    }

    // Feature-based API modules. Each module owns its routes; unmatched requests continue.
    require_once dirname(__DIR__) . '/profile/api/Controller.php';
    require_once dirname(__DIR__) . '/users/api/Controller.php';
    require_once dirname(__DIR__) . '/chats/api/Controller.php';
    require_once dirname(__DIR__) . '/messages/api/Controller.php';
    require_once dirname(__DIR__) . '/topics/api/Controller.php';
    require_once dirname(__DIR__) . '/security/api/Controller.php';
    require_once dirname(__DIR__) . '/groups/api/Controller.php';
    require_once dirname(__DIR__) . '/channels/api/Controller.php';
    require_once dirname(__DIR__) . '/api/integrations/calls/api/Controller.php';
    require_once dirname(__DIR__) . '/admin/api/Controller.php';
    require_once dirname(__DIR__) . '/files/api/Controller.php';
    if (pc_profile_dispatch($path, $method)) { exit; }
    if (pc_users_dispatch($path, $method)) { exit; }
    if (pc_chats_dispatch($path, $method)) { exit; }
    if (pc_messages_dispatch($path, $method)) { exit; }
    if (pc_topics_dispatch($path, $method)) { exit; }
    if (pc_security_dispatch($path, $method)) { exit; }
    if (pc_groups_dispatch($path, $method)) { exit; }
    if (pc_channels_dispatch($path, $method)) { exit; }
    if (pc_calls_integration_dispatch($path, $method)) { exit; }
    if (pc_admin_dispatch($path, $method)) { exit; }
    if (pc_files_dispatch($path, $method)) { exit; }


    require_once dirname(__DIR__) . '/register/api/Controller.php';
    require_once dirname(__DIR__) . '/login/api/Controller.php';
    if (pc_register_dispatch($path, $method)) {
        exit;
    }
    if (pc_login_dispatch($path, $method)) {
        exit;
    }

    

    

    

    


    

    



    

    

    


    

    


    

    

    

    

    

    

    


    

    

    

    

    

    

    

    

    


    

    

    

    

    pc_json(404, ['ok' => false, 'error' => 'endpoint_not_found', 'path' => $path]);
} catch (PDOException $e) {
    error_log('[Pluschat API] Database error: ' . $e->getMessage());
    pc_json(503, ['ok' => false, 'error' => 'database_unavailable']);
} catch (Throwable $e) {
    error_log('[Pluschat API] Server error: ' . $e->getMessage());
    pc_json(500, ['ok' => false, 'error' => 'internal_server_error']);
}
