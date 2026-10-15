<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function install_page(int $status, string $title, string $message): never {
    http_response_code($status);
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<main style="font:16px system-ui;max-width:680px;margin:10vh auto;padding:24px;border:1px solid #ddd;border-radius:16px">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></main></html>';
    exit;
}

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) install_page(503, 'Шлюз не настроен', 'Сначала создай config.php на основе config.example.php и заполни данные MySQL.');
$config = require $configPath;
$expected = (string)(getenv('INSTALL_TOKEN') ?: ($config['install_token'] ?? ''));
$provided = (string)($_GET['token'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    install_page(403, 'Доступ запрещён', 'Ссылка установки недействительна. Укажи корректный INSTALL_TOKEN.');
}
if (empty($config['db_password']) || empty($config['db_name']) || empty($config['db_user'])) {
    install_page(503, 'База данных не настроена', 'Заполни параметры MySQL в config.php.');
}

$missingExtensions = array_values(array_filter(['pdo_mysql', 'mbstring', 'fileinfo'], static fn(string $ext): bool => !extension_loaded($ext)));
if ($missingExtensions !== []) {
    install_page(503, 'Не хватает расширений PHP', 'Включи расширения: ' . implode(', ', $missingExtensions) . '.');
}

try {
    $dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
    $db = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Upgrade an existing database safely: CREATE TABLE IF NOT EXISTS alone does not add new columns.
    $table = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='users'");
    $table->execute([$config['db_name']]);
    if ((int)$table->fetchColumn() > 0) {
        $column = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND COLUMN_NAME='avatar_path'");
        $column->execute([$config['db_name']]);
        if ((int)$column->fetchColumn() === 0) $db->exec('ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER education');
    }

    $avatarDir = dirname(__DIR__) . '/storage/avatars';
    if (!is_dir($avatarDir) && !mkdir($avatarDir, 0750, true) && !is_dir($avatarDir)) {
        install_page(503, 'Нет доступа к хранилищу', 'Создай storage/avatars и разреши PHP записывать в эту папку.');
    }
    if (!is_writable($avatarDir)) {
        install_page(503, 'Нет доступа к хранилищу', 'Разреши PHP записывать в storage/avatars.');
    }

    $chatsTable = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='chats'");
    $chatsTable->execute([$config['db_name']]);
    if ((int)$chatsTable->fetchColumn() > 0) {
        $db->exec("ALTER TABLE chats MODIFY type ENUM('direct','group','channel') NOT NULL DEFAULT 'direct'");
        $description = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='chats' AND COLUMN_NAME='description'");
        $description->execute([$config['db_name']]);
        if ((int)$description->fetchColumn() === 0) $db->exec('ALTER TABLE chats ADD COLUMN description TEXT NULL AFTER title');
    }

    $membersTable = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='chat_members'");
    $membersTable->execute([$config['db_name']]);
    if ((int)$membersTable->fetchColumn() > 0) {
        $roleColumn = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='chat_members' AND COLUMN_NAME='role'");
        $roleColumn->execute([$config['db_name']]);
        if ((int)$roleColumn->fetchColumn() === 0) $db->exec("ALTER TABLE chat_members ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'member' AFTER user_id");
    }

    $schemaPath = __DIR__ . '/schema.sql';
    $schema = file_get_contents($schemaPath);
    if ($schema === false) throw new RuntimeException('schema.sql is missing');
    $statements = preg_split('/;\\s*(?:\\r?\\n|$)/', $schema);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $db->exec($statement);
    }

    install_page(200, 'Шлюз Pluschat установлен', 'Структура MySQL создана или обновлена. Теперь удали INSTALL_TOKEN из config.php или переменных окружения и отключи/удали публичную страницу установки.');
} catch (Throwable $e) {
    error_log('Pluschat gateway installer: ' . $e->getMessage());
    install_page(500, 'Установка не завершена', 'Не удалось создать структуру базы. Проверь доступ пользователя MySQL и журнал ошибок PHP на хостинге.');
}
