<?php
declare(strict_types=1);

/*
 * Manual project check. Run from the repository root:
 *     php tests/check-server.php
 * This does not need GitHub Actions, credentials, or a database connection.
 */
$root = dirname(__DIR__);
$errors = [];
$checked = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) continue;
    try {
        token_get_all((string)file_get_contents($path), TOKEN_PARSE);
        $checked++;
    } catch (ParseError $error) {
        $errors[] = $path . ': ' . $error->getMessage();
    }
}

$requiredFiles = [
    'public/index.php',
    'public/setup.php',
    'bootstrap.php',
    'database/schema.sql',
    'register/api/Controller.php',
    'register/services/RegistrationService.php',
    'register/repositories/RegistrationRepository.php',
    'register/validators/RegistrationValidator.php',
    'register/mail/mailconfirm/Mailer.php',
    'login/api/Controller.php',
    'login/services/LoginService.php',
    'login/repositories/LoginRepository.php',
    'login/validators/LoginValidator.php',
    'profile/api/Controller.php',
    'profile/services/ProfileService.php',
    'users/api/Controller.php',
    'chats/api/Controller.php',
    'chats/services/ChatService.php',
    'messages/api/Controller.php',
    'messages/services/ReactionService.php',
    'groups/api/Controller.php',
    'groups/services/GroupService.php',
    'channels/api/Controller.php',
    'channels/services/ChannelService.php',
    'topics/api/Controller.php',
    'topics/services/TopicService.php',
    'files/api/Controller.php',
    'files/services/AttachmentService.php',
    'notifications/api/Controller.php',
    'notifications/services/NotificationService.php',
    'search/api/Controller.php',
    'search/services/SearchService.php',
    'security/api/Controller.php',
    'security/services/DeviceService.php',
    'admin/api/Controller.php',
    'admin/services/ReportService.php',
    'api/integrations/calls/api/Controller.php',
];
foreach ($requiredFiles as $relative) {
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
        $errors[] = 'Missing required file: ' . $relative;
    }
}

$schema = (string)file_get_contents($root . '/database/schema.sql');
foreach (['users', 'auth_tokens', 'conversations', 'messages', 'chat_groups', 'channels', 'topics', 'message_attachments', 'notifications', 'reports'] as $table) {
    if (!preg_match('/CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS\\s+' . preg_quote($table, '/') . '\\b/i', $schema)) {
        $errors[] = 'Missing schema table: ' . $table;
    }
}

if ($errors) {
    fwrite(STDERR, "Pluschat server check FAILED (" . count($errors) . " issue(s)):\n");
    foreach ($errors as $error) fwrite(STDERR, " - " . $error . "\n");
    exit(1);
}

echo "Pluschat server check OK. Parsed PHP files: {$checked}. Required modules and schema tables: OK.\n";
