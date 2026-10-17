<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Permissions-Policy: geolocation=(), microphone=(self), camera=(self)');
header('Cross-Origin-Opener-Policy: same-origin');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

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
        'phone_verified' => !empty($user['phone_verified_at']),
        'created_at' => $user['created_at'] ?? null,
    ];
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
        $pdo = pc_db();
        $pdo->query('SELECT 1')->fetchColumn();
        $requiredTables = [
            'users', 'email_verification_tokens', 'phone_verification_codes', 'auth_tokens', 'conversations',
            'conversation_members', 'messages', 'user_settings', 'user_devices',
            'user_blocks', 'chat_groups', 'group_members', 'channels',
            'channel_members', 'topics', 'topic_messages', 'message_attachments',
            'message_receipts', 'message_reactions', 'reports', 'admin_users',
            'notifications', 'group_messages', 'channel_posts', 'api_rate_limits',
            'user_crypto_keys', 'friend_requests', 'friendships', 'saved_messages', 'polls', 'poll_options', 'poll_votes', 'chat_preferences', 'chat_folders', 'chat_folder_items', 'stories', 'story_views', 'password_reset_tokens', 'message_hidden', 'user_two_factor', 'two_factor_setups', 'two_factor_challenges', 'user_avatars',
        ];
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $missing = [];
        foreach ($requiredTables as $table) {
            $stmt->execute([$table]);
            if ((int)$stmt->fetchColumn() !== 1) $missing[] = $table;
        }
        if ($missing !== []) {
            pc_json(503, [
                'ok' => false,
                'service' => 'pluschat-api',
                'database' => 'connected',
                'schema' => 'incomplete',
                'missing_table_count' => count($missing),
            ]);
        }
        pc_json(200, [
            'ok' => true,
            'service' => 'pluschat-api',
            'api_version' => 1,
            'database' => 'connected',
            'schema' => 'ready',
        ]);
    }

    // Load and dispatch each feature module independently.
    require_once dirname(__DIR__) . '/register/api/Controller.php';
    require_once dirname(__DIR__) . '/login/api/Controller.php';
    require_once dirname(__DIR__) . '/search/api/Controller.php';
    require_once dirname(__DIR__) . '/notifications/api/Controller.php';
    require_once dirname(__DIR__) . '/profile/api/Controller.php';
    require_once dirname(__DIR__) . '/users/api/Controller.php';
    require_once dirname(__DIR__) . '/chats/api/Controller.php';
    require_once dirname(__DIR__) . '/messages/api/Controller.php';
    require_once dirname(__DIR__) . '/topics/api/Controller.php';
    require_once dirname(__DIR__) . '/security/api/Controller.php';
    require_once dirname(__DIR__) . '/groups/api/Controller.php';
    require_once dirname(__DIR__) . '/channels/api/Controller.php';
    require_once dirname(__DIR__) . '/admin/api/Controller.php';
    require_once dirname(__DIR__) . '/files/api/Controller.php';
    require_once dirname(__DIR__) . '/friends/api/Controller.php';
    require_once dirname(__DIR__) . '/saved/api/Controller.php';
    require_once dirname(__DIR__) . '/polls/api/Controller.php';
    require_once dirname(__DIR__) . '/chatprefs/api/Controller.php';
    require_once dirname(__DIR__) . '/stories/api/Controller.php';
    require_once dirname(__DIR__) . '/password/api/Controller.php';

    if (pc_register_dispatch($path, $method)) { exit; }
    if (pc_login_dispatch($path, $method)) { exit; }
    if (pc_search_dispatch($path, $method)) { exit; }
    if (pc_notifications_dispatch($path, $method)) { exit; }
    if (pc_profile_dispatch($path, $method)) { exit; }
    if (pc_users_dispatch($path, $method)) { exit; }
    if (pc_chats_dispatch($path, $method)) { exit; }
    if (pc_messages_dispatch($path, $method)) { exit; }
    if (pc_topics_dispatch($path, $method)) { exit; }
    if (pc_security_dispatch($path, $method)) { exit; }
    if (pc_groups_dispatch($path, $method)) { exit; }
    if (pc_channels_dispatch($path, $method)) { exit; }
    if (pc_admin_dispatch($path, $method)) { exit; }
    if (pc_files_dispatch($path, $method)) { exit; }
    if (pc_friends_dispatch($path, $method)) { exit; }
    if (pc_saved_dispatch($path, $method)) { exit; }
    if (pc_polls_dispatch($path, $method)) { exit; }
    if (pc_chatprefs_dispatch($path, $method)) { exit; }
    if (pc_stories_dispatch($path, $method)) { exit; }
    if (pc_password_dispatch($path, $method)) { exit; }

    pc_json(404, ['ok' => false, 'error' => 'endpoint_not_found', 'path' => $path]);
} catch (PDOException $e) {
    error_log('[Pluschat API] Database error: ' . $e->getMessage());
    pc_json(503, ['ok' => false, 'error' => 'database_unavailable']);
} catch (Throwable $e) {
    error_log('[Pluschat API] Server error: ' . $e->getMessage());
    pc_json(500, ['ok' => false, 'error' => 'internal_server_error']);
}
