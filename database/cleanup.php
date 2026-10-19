<?php
declare(strict_types=1);

/* Run from the project root using PHP CLI, e.g. daily via the host scheduler. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/bootstrap.php';

$pdo = pc_db();
$jobs = [
    'expired stories' => 'DELETE FROM stories WHERE expires_at <= UTC_TIMESTAMP()',
    'expired password reset tokens' => 'DELETE FROM password_reset_tokens WHERE expires_at <= UTC_TIMESTAMP()',
    'expired email verification tokens' => 'DELETE FROM email_verification_tokens WHERE expires_at <= UTC_TIMESTAMP()',
    'expired phone verification codes' => 'DELETE FROM phone_verification_codes WHERE expires_at <= UTC_TIMESTAMP()',
    'expired 2FA setup records' => 'DELETE FROM two_factor_setups WHERE expires_at <= UTC_TIMESTAMP()',
    'expired 2FA login challenges' => 'DELETE FROM two_factor_challenges WHERE expires_at <= UTC_TIMESTAMP()',
    'expired call signaling records' => 'DELETE FROM call_signals WHERE expires_at <= UTC_TIMESTAMP()',
    'expired ringing calls' => "UPDATE calls SET status = 'missed', ended_at = UTC_TIMESTAMP() WHERE status = 'ringing' AND expires_at <= UTC_TIMESTAMP()",
    'expired active calls' => "UPDATE calls SET status = 'ended', ended_at = UTC_TIMESTAMP() WHERE status = 'active' AND expires_at <= UTC_TIMESTAMP()",
    'expired access tokens' => 'DELETE FROM auth_tokens WHERE expires_at <= UTC_TIMESTAMP()',
    'old rate-limit buckets' => 'DELETE FROM api_rate_limits WHERE window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
];
$total = 0;
foreach ($jobs as $label => $sql) {
    $count = $pdo->exec($sql);
    $count = $count === false ? 0 : $count;
    $total += $count;
    echo $label . ': ' . $count . PHP_EOL;
}
echo 'Cleanup complete; rows removed: ' . $total . PHP_EOL;
