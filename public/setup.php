<?php
declare(strict_types=1);

/*
 * One-time Pluschat setup. The only value the owner must enter is the MySQL password.
 * Delete this file after setup as an extra precaution. HTTPS is required.
 */
$configPath = dirname(__DIR__) . '/config.php';
$alreadyConfigured = is_file($configPath);
$error = '';
$success = false;
$requirements = [];
if (PHP_VERSION_ID < 80100) $requirements[] = 'PHP 8.1 или новее';
if (!extension_loaded('pdo_mysql')) $requirements[] = 'расширение pdo_mysql';
if (!extension_loaded('sodium')) $requirements[] = 'расширение sodium для криптографии';
if (!filter_var((string)ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN)) $requirements[] = 'включённая загрузка файлов (file_uploads)';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Strict',
    ]);
    session_start();
}
if (empty($_SESSION['pluschat_setup_csrf'])) {
    $_SESSION['pluschat_setup_csrf'] = bin2hex(random_bytes(32));
}

if (!$alreadyConfigured && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    $password = is_string($_POST['db_password'] ?? null) ? $_POST['db_password'] : '';

    if (!hash_equals((string)$_SESSION['pluschat_setup_csrf'], $csrf)) {
        $error = 'Сессия формы истекла. Обновите страницу и попробуйте снова.';
    } elseif ($password === '' || strlen($password) > 1024) {
        $error = 'Введите пароль базы данных.';
    } elseif (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $error = 'Для безопасной настройки сначала включите HTTPS на домене.';
    } elseif ($requirements !== []) {
        $error = 'Хостинг пока не готов. Необходимо включить: ' . implode(', ', $requirements) . '.';
    } else {
        try {
            $host = 'localhost';
            $name = 'pluschat';
            $user = 'admin';

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

            $schemaPath = dirname(__DIR__) . '/database/schema.sql';
            $schema = is_file($schemaPath) ? file_get_contents($schemaPath) : false;
            if ($schema === false || trim($schema) === '') {
                throw new RuntimeException('Schema file unavailable');
            }

            foreach (explode(';', $schema) as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }

            $requiredTables = [
                'users', 'email_verification_tokens', 'auth_tokens', 'conversations',
                'conversation_members', 'messages', 'user_settings', 'user_devices',
                'user_blocks', 'chat_groups', 'group_members', 'channels',
                'channel_members', 'topics', 'topic_messages', 'message_attachments',
                'message_receipts', 'message_reactions', 'reports', 'admin_users',
                'notifications', 'group_messages', 'channel_posts', 'api_rate_limits',
                'user_crypto_keys', 'calls', 'call_signals', 'friend_requests', 'friendships', 'saved_messages', 'polls', 'poll_options', 'poll_votes', 'chat_preferences', 'chat_folders', 'chat_folder_items', 'stories', 'story_views',
            ];
            $tableCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $missingTables = [];
            foreach ($requiredTables as $table) {
                $tableCheck->execute([$table]);
                if ((int)$tableCheck->fetchColumn() !== 1) $missingTables[] = $table;
            }
            if ($missingTables !== []) {
                throw new RuntimeException('Schema verification failed. Missing tables: ' . implode(', ', $missingTables));
            }

            $config = [
                'db_host' => $host,
                'db_name' => $name,
                'db_user' => $user,
                'db_password' => $password,
                'mail_from' => 'register@плюсчат.рф',
                'app_base_url' => 'https://xn--80avljg2a1c.xn--p1ai',
                'allowed_origins' => [
                    'https://xn--80avljg2a1c.xn--p1ai',
                    'https://xn--n1aabel.xn--80avljg2a1c.xn--p1ai',
                ],
            ];

            $php = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
            $temporaryPath = $configPath . '.tmp-' . bin2hex(random_bytes(6));
            if (file_put_contents($temporaryPath, $php, LOCK_EX) === false || !rename($temporaryPath, $configPath)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Could not save private configuration');
            }
            @chmod($configPath, 0600);
            $alreadyConfigured = true;
            $success = true;
            unset($_POST['db_password']);
            $_SESSION = [];
            session_regenerate_id(true);
        } catch (Throwable $e) {
            error_log('[Pluschat setup] ' . $e->getMessage());
            $error = 'Не удалось подключить базу или создать таблицы. Проверьте пароль и убедитесь, что база pluschat и пользователь admin созданы на хостинге.';
        }
    }
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Настройка Pluschat</title>
<style>
:root{color-scheme:dark;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#090a0d;color:#f4f5f7}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(ellipse at top,#1b202b 0,transparent 58%),#090a0d}
main{width:min(100%,480px);border:1px solid #303540;border-radius:24px;background:rgba(19,21,27,.96);padding:clamp(24px,6vw,38px);box-shadow:0 24px 80px #0008}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}.mark{width:46px;height:46px;border-radius:15px;background:#f4f5f7;color:#101116;display:grid;place-items:center;font-size:27px;font-weight:800}.brand strong{font-size:21px;letter-spacing:-.6px}.brand span{display:block;color:#9298a5;font-size:13px;margin-top:2px}
h1{font-size:26px;letter-spacing:-.7px;margin:0 0 10px}p{color:#aab0bd;line-height:1.55;margin:0 0 22px;font-size:14px}
label{display:block;font-size:14px;font-weight:650;margin-bottom:9px}input{width:100%;border:1px solid #3b414e;background:#0d0f14;color:#fff;border-radius:12px;padding:15px 14px;font:inherit;outline:none}input:focus{border-color:#cdd2dc;box-shadow:0 0 0 3px #cdd2dc22}
button{width:100%;margin-top:16px;border:0;border-radius:12px;padding:15px;background:#f2f3f6;color:#11131a;font:inherit;font-weight:750;cursor:pointer}button:hover{background:#fff}
.notice{padding:14px;border-radius:12px;font-size:14px;line-height:1.5;margin-top:18px}.error{border:1px solid #743c43;background:#31191e;color:#ffcbd0}.ok{border:1px solid #285d48;background:#14291f;color:#baf4d6}.hint{font-size:12px;color:#858c9b;margin-top:12px}.pill{display:inline-block;border:1px solid #3b414e;border-radius:999px;padding:5px 9px;color:#aeb4c0;font-size:11px;margin-bottom:15px}
</style>
</head>
<body>
<main>
  <div class="brand"><div class="mark">+</div><div><strong>Pluschat</strong><span>Безопасная настройка сервера</span></div></div>
  <?php if ($alreadyConfigured): ?>
    <div class="pill">НАСТРОЙКА ЗАВЕРШЕНА</div>
    <h1>Сервер настроен</h1>
    <p>Файл конфигурации уже существует. Повторная настройка через эту страницу отключена.</p>
    <div class="notice ok">Проверьте API: <strong>/api/v1/health</strong>. Если проверка не проходит, проверьте настройки PHP и подключение к MySQL на хостинге.</div>
  <?php else: ?>
    <div class="pill">ОДИН РАЗ · ТОЛЬКО ПАРОЛЬ БД</div>
    <h1>Подключить базу данных</h1>
    <p>Имя базы, пользователь, адрес сервера и адрес Pluschat уже указаны. Введите только пароль MySQL. После проверки сервер автоматически создаст нужные таблицы и сохранит настройки вне публичной папки.</p>
    <?php if ($requirements !== []): ?>
      <div class="notice error">Для работы сервера хостинг должен поддерживать: <?= htmlspecialchars(implode(', ', $requirements), ENT_QUOTES, 'UTF-8') ?>.</div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$_SESSION['pluschat_setup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
      <label for="db_password">Пароль базы данных</label>
      <input id="db_password" name="db_password" type="password" required maxlength="1024" autocomplete="new-password" autofocus>
      <button type="submit">Проверить и настроить сервер</button>
    </form>
    <div class="hint">Пароль не выводится на экран и не сохраняется в GitHub. Настройка требует HTTPS.</div>
    <?php if ($error !== ''): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php endif; ?>
  <?php if ($success): ?><div class="notice ok">Готово: подключение к MySQL проверено, таблицы созданы, настройки сохранены. Теперь можно проверить <strong>/api/v1/health</strong>.</div><?php endif; ?>
</main>
</body>
</html>
