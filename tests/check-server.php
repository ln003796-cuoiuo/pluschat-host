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
    'calls/api/Controller.php',
    'public/setup.php',
    'bootstrap.php',
    'database/schema.sql',
    'database/backup.php',
    'database/restore.php',
    'database/migrations/010_encrypted_media_keys_backups.sql',
    'docs/SECURITY_MEDIA_BACKUPS.md',
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
];
foreach ($requiredFiles as $relative) {
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
        $errors[] = 'Missing required file: ' . $relative;
    }
}

$schema = (string)file_get_contents($root . '/database/schema.sql');
$migrationSql = '';
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $migrationPath) {
    $migrationSql .= "\n" . (string)file_get_contents($migrationPath);
}
$allSchemaSql = $schema . "\n" . $migrationSql;
foreach (['users', 'auth_tokens', 'conversations', 'messages', 'chat_groups', 'channels', 'topics', 'message_attachments', 'notifications', 'reports', 'user_crypto_keys', 'calls', 'call_participants', 'call_signals', 'user_one_time_prekeys', 'encrypted_key_envelopes', 'backup_runs'] as $table) {
    if (!preg_match('/CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS\\s+' . preg_quote($table, '/') . '\\b/i', $allSchemaSql)) {
        $errors[] = 'Missing schema/migration table: ' . $table;
    }
}

$bootstrap = (string)file_get_contents($root . '/bootstrap.php');
if (!str_contains($bootstrap, 'function pc_encrypted_envelope')) {
    $errors[] = 'Missing encrypted-envelope validation';
}
$callSource = (string)file_get_contents($root . '/calls/api/Controller.php');
if (!str_contains($callSource, 'SELECT id FROM conversations WHERE id = ? FOR UPDATE')) {
    $errors[] = 'Call creation must serialize per conversation';
}
$cleanupSource = (string)file_get_contents($root . '/database/cleanup.php');
foreach (['two_factor_challenges', 'call_signals'] as $expiredTable) {
    if (!str_contains($cleanupSource, $expiredTable)) $errors[] = 'Cleanup missing expired records: ' . $expiredTable;
}
foreach (['messages/api/Controller.php', 'groups/api/Controller.php', 'channels/api/Controller.php', 'topics/validators/TopicValidator.php'] as $relative) {
    $source = (string)file_get_contents($root . '/' . $relative);
    if (!str_contains($source, 'pc_encrypted_envelope')) {
        $errors[] = 'Encrypted-message enforcement missing in: ' . $relative;
    }
}
foreach (['security/api/Controller.php'] as $relative) {
    $source = (string)file_get_contents($root . '/' . $relative);
    if ($source === '') $errors[] = 'Empty security module: ' . $relative;
}

$securitySource = (string)file_get_contents($root . '/security/api/Controller.php');
$deviceRepositorySource = (string)file_get_contents($root . '/security/repositories/DeviceRepository.php');
$deviceServiceSource = (string)file_get_contents($root . '/security/services/DeviceService.php');
if (!str_contains($securitySource, '/api/v1/auth/sessions/revoke-all')
    || !str_contains($deviceRepositorySource, 'function revokeAllSessions')
    || !str_contains($deviceServiceSource, 'function revokeAllSessions')) {
    $errors[] = 'Revoke-all-sessions endpoint is incomplete';
}
foreach (['/api/v1/security/one-time-prekeys', '/api/v1/security/one-time-prekey/', '/api/v1/security/key-envelopes'] as $keyRoute) {
    if (!str_contains($securitySource, $keyRoute)) $errors[] = 'Key exchange endpoint missing: ' . $keyRoute;
}
foreach (['database/backup.php', 'database/restore.php'] as $backupScript) {
    $source = (string)file_get_contents($root . '/' . $backupScript);
    if (!str_contains($source, 'sodium_crypto_aead_xchacha20poly1305_ietf')) $errors[] = 'Encrypted backup protection missing: ' . $backupScript;
}
if (str_contains($securitySource, 'Bearer\\\\\\\\s+')) {
    $errors[] = 'Security API contains an over-escaped Bearer-token regex';
}
$entrySource = (string)file_get_contents($root . '/public/index.php');
if (!str_contains($entrySource, "'schema' => 'ready'") || !str_contains($entrySource, "'schema' => 'incomplete'")) {
    $errors[] = 'Health endpoint must distinguish a ready schema from an incomplete schema';
}
if (!str_contains($entrySource, "'gif_provider' => \$giphyConfigured ? 'configured' : 'not_configured'")) {
    $errors[] = 'Health endpoint must expose only a safe GIF provider configuration status';
}

$rateLimitedWrites = [
    'messages/api/Controller.php' => 'message-send',
    'groups/api/Controller.php' => 'group-message-send',
    'channels/api/Controller.php' => 'channel-post-send',
    'topics/api/Controller.php' => 'topic-message-send',
    'files/api/Controller.php' => 'attachment-upload',
];
foreach ($rateLimitedWrites as $relative => $bucket) {
    $source = (string)file_get_contents($root . '/' . $relative);
    if (!str_contains($source, "pc_rate_limit('" . $bucket . "'")) {
        $errors[] = 'Missing write rate limit in: ' . $relative;
    }
}
foreach (explode("\n", $schema) as $lineNumber => $line) {
    if (str_starts_with(trim($line), '--') && str_contains($line, ';')) {
        $errors[] = 'SQL installer may split a comment at line ' . ($lineNumber + 1);
    }
}

if ($errors) {
    fwrite(STDERR, "Pluschat server check FAILED (" . count($errors) . " issue(s)):\n");
    foreach ($errors as $error) fwrite(STDERR, " - " . $error . "\n");
    exit(1);
}

echo "Pluschat server check OK. Parsed PHP files: {$checked}. Required modules and schema tables: OK.\n";
