<?php
declare(strict_types=1);

/* Destructive CLI restore. Requires the matching schema and an explicit confirmation flag. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/bootstrap.php';
if (($argv[1] ?? '') !== '--confirm-restore' || empty($argv[2])) {
    fwrite(STDERR, "Usage: php database/restore.php --confirm-restore /absolute/path/to/file.pcbak\n"); exit(2);
}
if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) { fwrite(STDERR, "ERROR: PHP sodium extension is required.\n"); exit(2); }
$secret = (string)(getenv('PLUSCHAT_BACKUP_KEY') ?: pc_config('backup_encryption_key', ''));
if (strlen($secret) < 32) { fwrite(STDERR, "ERROR: PLUSCHAT_BACKUP_KEY is not configured.\n"); exit(2); }
$file = realpath((string)$argv[2]);
if ($file === false || !is_file($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'pcbak') { fwrite(STDERR, "ERROR: Backup file not found or extension invalid.\n"); exit(2); }
$raw = file_get_contents($file);
$nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
if (!is_string($raw) || strlen($raw) <= 6 + $nonceLength || substr($raw, 0, 6) !== 'PCBAK1') { fwrite(STDERR, "ERROR: Invalid backup format.\n"); exit(2); }
$key = hash('sha256', $secret, true);
$nonce = substr($raw, 6, $nonceLength);
$compressed = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, 6 + $nonceLength), 'pluschat-backup-v1', $nonce, $key);
$json = is_string($compressed) ? gzdecode($compressed) : false;
$data = is_string($json) ? json_decode($json, true) : null;
if (!is_array($data) || ($data['format'] ?? '') !== 'pluschat-logical-backup-v1' || !is_array($data['tables'] ?? null)) { fwrite(STDERR, "ERROR: Authentication failed or backup is invalid.\n"); exit(2); }
$pdo = pc_db();
$currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!hash_equals((string)($data['database'] ?? ''), $currentDb)) { fwrite(STDERR, "ERROR: Backup database name does not match target database.\n"); exit(2); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
try {
    $pdo->beginTransaction();
    foreach ($data['tables'] as $table => $rows) {
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table) || !is_array($rows)) throw new RuntimeException('Invalid table payload');
        $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = 'BASE TABLE'");
        $exists->execute([$table]);
        if ((int)$exists->fetchColumn() !== 1) throw new RuntimeException('Missing table ' . $table . '; apply matching schema migrations first');
    }
    foreach (array_keys($data['tables']) as $table) {
        $quoted = chr(96) . $table . chr(96);
        $pdo->exec("DELETE FROM {$quoted}");
    }
    foreach ($data['tables'] as $table => $rows) {
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) continue;
            $columns = array_keys($row);
            foreach ($columns as $column) if (!is_string($column) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) throw new RuntimeException('Invalid column in backup');
            $q = chr(96);
            $quotedColumns = implode(',', array_map(static fn(string $c): string => $q . $c . $q, $columns));
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $tableName = $q . $table . $q;
            $stmt = $pdo->prepare("INSERT INTO {$tableName} ({$quotedColumns}) VALUES ({$placeholders})");
            $stmt->execute(array_values($row));
        }
    }
    $pdo->commit();
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_runs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, backup_name VARCHAR(255) NOT NULL, sha256 CHAR(64) NOT NULL, byte_size BIGINT UNSIGNED NOT NULL, status ENUM('created','verified','failed','restored') NOT NULL, details VARCHAR(1000) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_backup_runs_created (created_at, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->prepare("INSERT INTO backup_runs (backup_name, sha256, byte_size, status, details) VALUES (?, ?, ?, 'restored', ?)")->execute([basename($file), hash_file('sha256', $file), filesize($file), 'Restored by CLI restore utility.']);
    echo "RESTORE_OK database=" . $currentDb . " tables=" . count($data['tables']) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERROR: Restore failed: " . $e->getMessage() . PHP_EOL); exit(1);
} finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
