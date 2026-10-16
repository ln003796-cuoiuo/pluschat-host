<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'friends/api/Controller.php',
    'friends/services/FriendService.php',
    'friends/repositories/FriendRepository.php',
    'saved/api/Controller.php',
    'saved/services/SavedMessageService.php',
    'saved/repositories/SavedMessageRepository.php',
    'polls/api/Controller.php',
    'polls/services/PollService.php',
    'polls/repositories/PollRepository.php',
    'chatprefs/api/Controller.php',
    'chatprefs/services/ChatPreferenceService.php',
    'chatprefs/repositories/ChatPreferenceRepository.php',
    'database/migrations/001_social_features.sql',
    'database/migrations/002_chat_organization.sql',
    'stories/api/Controller.php',
    'stories/services/StoryService.php',
    'stories/repositories/StoryRepository.php',
    'database/migrations/003_stories.sql',
    'password/api/Controller.php',
    'password/services/PasswordResetService.php',
    'password/repositories/PasswordResetRepository.php',
    'password/mail/Mailer.php',
    'database/migrations/004_password_recovery.sql',
    'database/migrations/005_message_hiding.sql',
    'database/migrations/006_mandatory_two_factor.sql',
    'security/services/TwoFactorService.php',
    'public/password-reset.php',
    'database/migrate.php',
    'database/cleanup.php',
];
$errors = [];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) $errors[] = 'Missing file: ' . $file;
}
$schema = (string)file_get_contents($root . '/database/schema.sql');
foreach (['friend_requests','friendships','saved_messages','polls','poll_options','poll_votes','chat_preferences','chat_folders','chat_folder_items','stories','story_views','password_reset_tokens','message_hidden','user_two_factor','two_factor_setups','two_factor_challenges'] as $table) {
    if (strpos($schema, 'CREATE TABLE IF NOT EXISTS ' . $table) === false) $errors[] = 'Missing table: ' . $table;
}
$index = (string)file_get_contents($root . '/public/index.php');
foreach (['pc_friends_dispatch','pc_saved_dispatch','pc_polls_dispatch','pc_chatprefs_dispatch','pc_stories_dispatch','pc_password_dispatch'] as $route) {
    if (strpos($index, $route) === false) $errors[] = 'Router missing: ' . $route;
}
$registrationValidator = (string)file_get_contents($root . '/register/validators/RegistrationValidator.php');
if (!str_contains($registrationValidator, 'first_name_required')) $errors[] = 'Registration must require first name';
$registrationRepository = (string)file_get_contents($root . '/register/repositories/RegistrationRepository.php');
if (!str_contains($registrationRepository, 'confirmCode') || !str_contains($registrationRepository, 'INTERVAL 15 MINUTE')) $errors[] = 'Short-lived email verification code flow is missing';
$registrationMailer = (string)file_get_contents($root . '/register/mail/mailconfirm/Mailer.php');
if (!str_contains($registrationMailer, 'Код подтверждения Pluschat')) $errors[] = 'Email verification code mailer is missing';
$twoFactorService = (string)file_get_contents($root . '/security/services/TwoFactorService.php');
if (!str_contains($twoFactorService, 'sodium_crypto_secretbox') || !str_contains($twoFactorService, 'verifyCode')) $errors[] = 'Encrypted TOTP secret storage or TOTP validation is missing';
$loginService = (string)file_get_contents($root . '/login/services/LoginService.php');
if (!str_contains($loginService, 'requires_2fa_setup') || !str_contains($loginService, 'requires_2fa')) $errors[] = 'Mandatory 2FA login flow is missing';
$securityController = (string)file_get_contents($root . '/security/api/Controller.php');
foreach (['/api/v1/auth/2fa/setup','/api/v1/auth/2fa/confirm','/api/v1/auth/2fa/verify','two_factor_is_mandatory'] as $route) {
    if (!str_contains($securityController, $route)) $errors[] = '2FA route missing: ' . $route;
}
$bootstrap = (string)file_get_contents($root . '/bootstrap.php');
if (!str_contains($bootstrap, 'two_factor_setup_required')) $errors[] = 'Authenticated API does not enforce mandatory 2FA enrollment';

$registrationController = (string)file_get_contents($root . '/register/api/Controller.php');
if (!str_contains($registrationController, 'confirmCode')) $errors[] = 'Email code verification route is missing';

$messageController = (string)file_get_contents($root . '/messages/api/Controller.php');
if (!str_contains($messageController, 'messaging_blocked')) $errors[] = 'Direct-message block enforcement is missing';
$attachmentRepo = (string)file_get_contents($root . '/files/repositories/AttachmentRepository.php');
if (!str_contains($attachmentRepo, "'pc-e2ee-v1'")) $errors[] = 'Attachment encryption version must be pc-e2ee-v1';
if (!str_contains($attachmentRepo, 'deleteForSender')) $errors[] = 'Sender-authorized attachment deletion is missing';
$attachmentValidator = (string)file_get_contents($root . '/files/validators/AttachmentValidator.php');
if (!str_contains($attachmentValidator, 'PCF1') || !str_contains($attachmentValidator, 'invalid_encrypted_attachment_envelope')) $errors[] = 'Attachment upload must require the encrypted-file envelope marker';
$attachmentController = (string)file_get_contents($root . '/files/api/Controller.php');
if (!str_contains($attachmentController, '/delete$')) $errors[] = 'Attachment delete API route is missing';
$migrationRunner = (string)file_get_contents($root . '/database/migrate.php');
$cleanupRunner = (string)file_get_contents($root . '/database/cleanup.php');
if (!str_contains($migrationRunner, "PHP_SAPI !== 'cli'")) $errors[] = 'Migration runner must refuse web requests';
if (!str_contains($cleanupRunner, "PHP_SAPI !== 'cli'")) $errors[] = 'Cleanup runner must refuse web requests';

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo 'Pluschat feature modules check OK' . PHP_EOL;
