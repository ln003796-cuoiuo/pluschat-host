<?php
declare(strict_types=1);

/* CLI-only encrypted logical backup. Set PLUSCHAT_BACKUP_KEY and PLUSCHAT_BACKUP_DIR outside the public web root. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/bootstrap.php';
if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
    fwrite(STDERR, "ERROR: PHP sodium extension is required.\n"); exit(2);
}
$secret = (string)(getenv('PLUSCHAT_BACKUP_KEY') ?: pc_config('backup_encryption_key', ''));
if (strlen($secret) < 32) { fwrite(STDERR, "ERROR: Configure PLUSCHAT_BACKUP_KEY with a random secret of at least 32 characters.\n"); exit(2); }
$directory = (string)(getenv('PLUSCHAT_BACKUP_DIR') ?: pc_config('backup_directory', dirname(__DIR__) . '/../pluschat-private-backups'));
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { fwrite(STDERR, "ERROR: Cannot create private backup directory.\n"); exit(2); }
@chmod($directory, 0700);
$pdo = pc_db();
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$payload = ['format' => 'pluschat-logical-backup-v1', 'created_at' => gmdate('c'), 'database' => (string)pc_config('db_name', 'pluschat'), 'tables' => []];
foreach ($tables as $table) {
    if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;
    $quoted = chr(96) . $table . chr(96);
    $payload['tables'][$table] = $pdo->query("SELECT * FROM {$quoted}")->fetchAll(PDO::FETCH_ASSOC);
}
$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($json)) { fwrite(STDERR, "ERROR: Could not serialize database snapshot.\n"); exit(2); }
$compressed = gzencode($json, 6);
if (!is_string($compressed)) { fwrite(STDERR, "ERROR: Compression failed.\n"); exit(2); }
$key = hash('sha256', $secret, true);
$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($compressed, 'pluschat-backup-v1', $nonce, $key);
$filename = 'pluschat-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.pcbak';
$target = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
$temp = $target . '.tmp';
if (file_put_contents($temp, "PCBAK1" . $nonce . $ciphertext, LOCK_EX) === false || !rename($temp, $target)) {
    @unlink($temp); fwrite(STDERR, "ERROR: Could not write backup.\n"); exit(2);
}
@chmod($target, 0600);
$sha = hash_file('sha256', $target);
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_runs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, backup_name VARCHAR(255) NOT NULL, sha256 CHAR(64) NOT NULL, byte_size BIGINT UNSIGNED NOT NULL, status ENUM('created','verified','failed','restored') NOT NULL, details VARCHAR(1000) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_backup_runs_created (created_at, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->prepare("INSERT INTO backup_runs (backup_name, sha256, byte_size, status, details) VALUES (?, ?, ?, 'created', ?)")->execute([$filename, $sha, filesize($target), 'Authenticated encrypted logical snapshot; schema restored separately from versioned migrations.']);
} catch (Throwable $e) { fwrite(STDERR, "WARNING: Backup exists but audit row could not be saved.\n"); }
foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pluschat-*.pcbak') ?: [] as $old) {
    if (is_file($old) && filemtime($old) < time() - 90 * 86400) @unlink($old);
}
echo "BACKUP_OK " . $target . " bytes=" . filesize($target) . " sha256=" . $sha . PHP_EOL;
