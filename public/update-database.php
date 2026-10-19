<?php
declare(strict_types=1);

/*
 * Protected database updater. The DB password is submitted only via HTTPS POST,
 * never in the URL. Remove this page after the update has completed.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Strict',
    ]);
    session_start();
}
if (empty($_SESSION['pc_db_update_csrf'])) {
    $_SESSION['pc_db_update_csrf'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['pc_db_update_attempts'])) $_SESSION['pc_db_update_attempts'] = 0;

$error = '';
$success = false;
$changed = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    $password = is_string($_POST['db_password'] ?? null) ? $_POST['db_password'] : '';
    unset($_POST['db_password']);

    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $error = 'Для обновления включите HTTPS.';
    } elseif (!hash_equals((string)$_SESSION['pc_db_update_csrf'], $csrf)) {
        $error = 'Сессия формы истекла. Обновите страницу.';
    } elseif ((int)$_SESSION['pc_db_update_attempts'] >= 5) {
        $error = 'Слишком много попыток. Закройте страницу и обратитесь к администратору хостинга.';
    } else {
        $_SESSION['pc_db_update_attempts'] = (int)$_SESSION['pc_db_update_attempts'] + 1;
        $configuredPassword = (string)pc_config('db_password', '');
        if ($password === '' || $configuredPassword === '' || !hash_equals($configuredPassword, $password)) {
            $error = 'Пароль базы данных неверный.';
        } else {
            try {
                $pdo = pc_db();
                $schemaPath = dirname(__DIR__) . '/database/schema.sql';
                $schema = is_file($schemaPath) ? file_get_contents($schemaPath) : false;
                if (!is_string($schema) || trim($schema) === '') {
                    throw new RuntimeException('Database schema file is unavailable');
                }
                foreach (explode(';', $schema) as $statement) {
                    $statement = trim($statement);
                    if ($statement !== '') $pdo->exec($statement);
                }

                $migrationFiles = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
                sort($migrationFiles, SORT_STRING);
                if ($migrationFiles === []) throw new RuntimeException('No database migrations found');

                $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(190) NOT NULL PRIMARY KEY,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1');
                $record = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
                foreach ($migrationFiles as $migrationPath) {
                    $version = basename($migrationPath);
                    $check->execute([$version]);
                    if ($check->fetchColumn()) continue;
                    $migration = file_get_contents($migrationPath);
                    if (!is_string($migration) || trim($migration) === '') {
                        throw new RuntimeException('Migration file unavailable: ' . $version);
                    }
                    foreach (explode(';', $migration) as $statement) {
                        $statement = trim($statement);
                        if ($statement !== '') $pdo->exec($statement);
                    }
                    $record->execute([$version]);
                    $changed[] = $version;
                }

                $required = ['users','email_verification_tokens','phone_verification_codes','auth_tokens','conversations','conversation_members','messages','user_settings','user_devices','chat_groups','group_members','channels','topics','message_attachments','notifications','user_crypto_keys','user_two_factor','user_avatars','calls','call_participants','call_signals'];
                $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $missing = [];
                foreach ($required as $table) {
                    $tableCheck->execute([$table]);
                    if ((int)$tableCheck->fetchColumn() !== 1) $missing[] = $table;
                }
                if ($missing !== []) throw new RuntimeException('Required tables missing: ' . implode(', ', $missing));

                $success = true;
                $_SESSION['pc_db_update_attempts'] = 0;
                $_SESSION['pc_db_update_csrf'] = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                error_log('[Pluschat database update] ' . get_class($e) . ': update failed');
                $error = 'Обновление не завершено. Проверьте резервную копию, права MySQL и журналы PHP на хостинге.';
            }
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
<title>Обновление базы — Pluschat</title>
<style>
:root{color-scheme:dark;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#090a0d;color:#f4f5f7}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;background:radial-gradient(ellipse at top,#1b202b 0,transparent 58%),#090a0d}main{width:min(100%,520px);border:1px solid #303540;border-radius:24px;background:#13151bf5;padding:clamp(24px,6vw,38px);box-shadow:0 24px 80px #0008}.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}.mark{width:46px;height:46px;border-radius:15px;background:#f4f5f7;color:#101116;display:grid;place-items:center;font-size:27px;font-weight:800}.brand strong{font-size:21px}.brand span{display:block;color:#9298a5;font-size:13px;margin-top:2px}.pill{display:inline-block;border:1px solid #3b414e;border-radius:999px;padding:5px 9px;color:#aeb4c0;font-size:11px;margin-bottom:15px}h1{font-size:26px;letter-spacing:-.7px;margin:0 0 10px}p{color:#aab0bd;line-height:1.55;margin:0 0 22px;font-size:14px}label{display:block;font-size:14px;font-weight:650;margin-bottom:9px}input{width:100%;border:1px solid #3b414e;background:#0d0f14;color:#fff;border-radius:12px;padding:15px 14px;font:inherit;outline:none}input:focus{border-color:#cdd2dc;box-shadow:0 0 0 3px #cdd2dc22}button{width:100%;margin-top:16px;border:0;border-radius:12px;padding:15px;background:#f2f3f6;color:#11131a;font:inherit;font-weight:750;cursor:pointer}.notice{padding:14px;border-radius:12px;font-size:14px;line-height:1.5;margin-top:18px}.error{border:1px solid #743c43;background:#31191e;color:#ffcbd0}.ok{border:1px solid #285d48;background:#14291f;color:#baf4d6}.hint{font-size:12px;color:#858c9b;margin-top:12px}
</style>
</head>
<body><main>
<div class="brand"><div class="mark">+</div><div><strong>Pluschat</strong><span>Безопасное обновление сервера</span></div></div>
<div class="pill">МИГРАЦИИ БАЗЫ ДАННЫХ</div>
<h1>Обновить базу данных</h1>
<p>Проверьте резервную копию перед запуском. Страница применяет схему и недостающие миграции к настроенной базе Pluschat. Пароль отправляется только через HTTPS POST и не добавляется в адрес страницы.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$_SESSION['pc_db_update_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<label for="db_password">Подтвердите пароль MySQL</label>
<input id="db_password" name="db_password" type="password" required maxlength="1024" autocomplete="current-password">
<button type="submit">Проверить и обновить базу</button>
</form>
<div class="hint">Не открывайте эту страницу через HTTP. После успешного обновления удалите public/update-database.php с хостинга.</div>
<?php if ($error !== ''): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($success): ?><div class="notice ok"><strong>Проверка таблиц пройдена.</strong><br>Новых миграций применено: <?= count($changed) ?>.<?php if ($changed !== []): ?><ul><?php foreach ($changed as $version): ?><li><?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul><?php endif; ?> Теперь проверьте /api/v1/health и выполните функциональные тесты.</div><?php endif; ?>
</main></body></html>
