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
    'database/migrations/007_private_profile_avatars.sql',
    'security/services/TwoFactorService.php',
    'profile/services/AvatarService.php',
    'public/password-reset.php',
    'database/migrate.php',
    'database/cleanup.php',
    'database/backup.php',
    'database/restore.php',
    'database/migrations/010_encrypted_media_keys_backups.sql',
];
$errors = [];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) $errors[] = 'Missing file: ' . $file;
}
$schema = (string)file_get_contents($root . '/database/schema.sql');
foreach (['friend_requests','friendships','saved_messages','polls','poll_options','poll_votes','chat_preferences','chat_folders','chat_folder_items','stories','story_views','password_reset_tokens','message_hidden','user_two_factor','two_factor_setups','two_factor_challenges','user_avatars'] as $table) {
    if (strpos($schema, 'CREATE TABLE IF NOT EXISTS ' . $table) === false) $errors[] = 'Missing table: ' . $table;
}
$index = (string)file_get_contents($root . '/public/index.php');
foreach (['pc_friends_dispatch','pc_saved_dispatch','pc_polls_dispatch','pc_chatprefs_dispatch','pc_stories_dispatch','pc_password_dispatch'] as $route) {
    if (strpos($index, $route) === false) $errors[] = 'Router missing: ' . $route;
}
$setup = (string)file_get_contents($root . '/public/setup.php');
$updater = (string)file_get_contents($root . '/public/update-database.php');
if (!str_contains($setup, "\$name = 'Pluschat'") || !str_contains($setup, "foreach (['admin', 'Admin']")) $errors[] = 'Installer must use the Pluschat database and admin account first';
if (!str_contains($setup, 'database/migrations/*.sql') || str_contains($setup, 'textbee_api_key')) $errors[] = 'Installer must apply migrations without requiring SMS provider credentials';
if (!str_contains($updater, 'HTTPS') || !str_contains($updater, 'hash_equals') || !str_contains($updater, 'schema_migrations')) $errors[] = 'Database updater must require HTTPS, verify the password, and track migrations';
$giphyService = (string)file_get_contents($root . '/gifs/services/GiphyService.php');
$giphyController = (string)file_get_contents($root . '/gifs/api/Controller.php');
if (!str_contains($setup, 'name="giphy_api_key"') || !str_contains($setup, "'giphy_api_key' => \$giphyApiKey")) $errors[] = 'Installer must store optional GIPHY API key privately';
if (!str_contains($index, 'pc_giphy_dispatch') || !str_contains($index, "'gif_provider' => \$giphyConfigured")) $errors[] = 'GIPHY endpoint and safe health status must be wired';
if (!str_contains($giphyService, 'api.giphy.com/v1/gifs/search') || !str_contains($giphyService, 'pc_config')) $errors[] = 'GIPHY service must use the private server-side API key';
if (!str_contains($giphyController, "pc_rate_limit('giphy-search', 30, 60)") || !str_contains($giphyController, 'pc_require_verified_user')) $errors[] = 'GIF search must be authenticated and rate-limited';
$registrationController = (string)file_get_contents($root . '/register/api/Controller.php');
$registrationValidator = (string)file_get_contents($root . '/register/validators/RegistrationValidator.php');
$registrationService = (string)file_get_contents($root . '/register/services/RegistrationService.php');
$profileValidator = (string)file_get_contents($root . '/profile/validators/ProfileValidator.php');
$profileService = (string)file_get_contents($root . '/profile/services/ProfileService.php');
if (!str_contains($registrationService, 'pc_pending_') || !str_contains($profileValidator, "updates['username']")) $errors[] = 'Nickname must be assigned during profile completion, not registration';
if (!str_contains($profileService, 'username_taken') || !str_contains($profileService, 'username_required')) $errors[] = 'Profile completion must require a unique public nickname';
$entryProfile = (string)file_get_contents($root . '/public/index.php');
if (!str_contains($entryProfile, 'profile_completion_required') || !str_contains($entryProfile, "'profile_completed' => \$profileCompleted")) $errors[] = 'Incomplete profiles must be blocked from feature APIs and hidden in public user data';
if (!str_contains($registrationValidator, 'first_name_required')) $errors[] = 'Registration must require first name';
$registrationRepository = (string)file_get_contents($root . '/register/repositories/RegistrationRepository.php');
if (!str_contains($registrationRepository, 'confirmCode') || !str_contains($registrationRepository, 'INTERVAL 15 MINUTE')) $errors[] = 'Short-lived email verification code flow is missing';
$registrationMailer = (string)file_get_contents($root . '/register/mail/mailconfirm/Mailer.php');
if (!str_contains($registrationMailer, 'Код подтверждения Pluschat')) $errors[] = 'Email verification code mailer is missing';
if (!str_contains($registrationMailer, 'text/html; charset=UTF-8') || !str_contains($registrationMailer, 'multipart/alternative') || !str_contains($registrationMailer, 'Pluschat')) $errors[] = 'Branded HTML and plain-text email alternatives are required';
$avatarService = (string)file_get_contents($root . '/profile/services/AvatarService.php');
if (!str_contains($avatarService, '5 * 1024 * 1024') || !str_contains($avatarService, 'image/webp')) $errors[] = 'Private avatar validation is missing';
$profileController = (string)file_get_contents($root . '/profile/api/Controller.php');
if (!str_contains($profileController, '/api/v1/me/avatar')) $errors[] = 'Avatar API routes are missing';
$twoFactorService = (string)file_get_contents($root . '/security/services/TwoFactorService.php');
if (!str_contains($twoFactorService, 'sodium_crypto_secretbox') || !str_contains($twoFactorService, 'verifyCode')) $errors[] = 'Encrypted TOTP secret storage or TOTP validation is missing';
$loginService = (string)file_get_contents($root . '/login/services/LoginService.php');
$bootstrapAuth = (string)file_get_contents($root . '/bootstrap.php');
if (!str_contains($loginService, 'requires_2fa') || str_contains($loginService, 'requires_2fa_setup')) $errors[] = '2FA must be optional unless a user has enabled it';
$securityController = (string)file_get_contents($root . '/security/api/Controller.php');
foreach (['/api/v1/auth/2fa/setup','/api/v1/auth/2fa/confirm','/api/v1/auth/2fa/verify','/api/v1/auth/2fa/disable','verifyCurrentCode'] as $route) {
    if (!str_contains($securityController, $route)) $errors[] = '2FA route missing: ' . $route;
}
$bootstrap = (string)file_get_contents($root . '/bootstrap.php');
if (str_contains($bootstrap, 'two_factor_setup_required')) $errors[] = 'API must not require optional 2FA enrollment';

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

// Exercise the RFC 6238 verifier with a freshly computed valid TOTP code.
require_once $root . '/security/services/TwoFactorService.php';
$totpSecret = 'JBSWY3DPEHPK3PXP';
$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
$bits = '';
foreach (str_split($totpSecret) as $character) $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
$totpKey = '';
foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $totpKey .= chr(bindec($chunk));
$counter = intdiv(time(), 30);
$counterBytes = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
$totpHash = hash_hmac('sha1', $counterBytes, $totpKey, true);
$offset = ord($totpHash[19]) & 0x0f;
$binary = ((ord($totpHash[$offset]) & 0x7f) << 24)
    | ((ord($totpHash[$offset + 1]) & 0xff) << 16)
    | ((ord($totpHash[$offset + 2]) & 0xff) << 8)
    | (ord($totpHash[$offset + 3]) & 0xff);
$validTotp = str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
$totpVerifier = new ReflectionMethod(PCTwoFactorService::class, 'verifyCode');
$totpVerifier->setAccessible(true);
if (!$totpVerifier->invoke(null, $totpSecret, $validTotp, time())) $errors[] = 'TOTP verifier rejected a valid RFC 6238 code';
if ($totpVerifier->invoke(null, $totpSecret, 'ABC123', time())) $errors[] = 'TOTP verifier accepted an invalid code';

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo 'Pluschat feature modules check OK' . PHP_EOL;
