<?php
declare(strict_types=1);

/*
 * CLI-only backup runner. Requires PDO MySQL and the mysqldump executable.
 * Configure PLUSCHAT_DB_HOST, PLUSCHAT_DB_NAME, PLUSCHAT_DB_USER,
 * PLUSCHAT_DB_PASSWORD and PLUSCHAT_BACKUP_DIR in the hosting environment.
 * Keep PLUSCHAT_BACKUP_DIR outside the web root and on protected storage.
 * Schedule daily via the host's cron facility. Never invoke this from HTTP.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!function_exists('proc_open')) {
    fwrite(STDERR, "proc_open is unavailable; use the host's managed backup facility.\n");
    exit(2);
}
$host = getenv('PLUSCHAT_DB_HOST') ?: 'localhost';
$name = getenv('PLUSCHAT_DB_NAME') ?: '';
$user = getenv('PLUSCHAT_DB_USER') ?: '';
$password = getenv('PLUSCHAT_DB_PASSWORD') ?: '';
$dir = getenv('PLUSCHAT_BACKUP_DIR') ?: '';
if ($name === '' || $user === '' || $password === '' || $dir === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    fwrite(STDERR, "Missing DB/backup environment configuration.\n");
    exit(2);
}
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create backup directory.\n");
    exit(2);
}
@chmod($dir, 0700);
$stamp = gmdate('Ymd-His');
$tmp = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.pluschat-' . $stamp . '.sql.tmp';
$final = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pluschat-' . $stamp . '.sql';
$dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->prepare("INSERT INTO backup_runs (run_type,status,artifact_name,started_at) VALUES ('database','started',?,UTC_TIMESTAMP())")
        ->execute([basename($final)]);
    $runId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable or backup_runs migration not installed.\n");
    exit(3);
}
$command = ['mysqldump', '--host=' . $host, '--user=' . $user, '--single-transaction', '--routines', '--triggers', '--default-character-set=utf8mb4', $name];
$spec = [0 => ['pipe','r'], 1 => ['file',$tmp,'w'], 2 => ['pipe','w']];
$env = ['MYSQL_PWD' => $password];
$proc = proc_open($command, $spec, $pipes, null, $env);
if (!is_resource($proc)) {
    @unlink($tmp);
    $pdo->prepare("UPDATE backup_runs SET status='failed',finished_at=UTC_TIMESTAMP(),details='Unable to start mysqldump' WHERE id=?")->execute([$runId]);
    fwrite(STDERR, "Unable to start mysqldump.\n");
    exit(4);
}
fclose($pipes[0]);
$error = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$code = proc_close($proc);
if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 64) {
    @unlink($tmp);
    $pdo->prepare("UPDATE backup_runs SET status='failed',finished_at=UTC_TIMESTAMP(),details=? WHERE id=?")
        ->execute([substr(trim((string)$error) ?: 'mysqldump failed', 0, 900), $runId]);
    fwrite(STDERR, "Backup failed; see backup_runs for details.\n");
    exit(5);
}
if (!rename($tmp, $final)) {
    @unlink($tmp);
    $pdo->prepare("UPDATE backup_runs SET status='failed',finished_at=UTC_TIMESTAMP(),details='Cannot finalize backup file' WHERE id=?")->execute([$runId]);
    fwrite(STDERR, "Cannot finalize backup file.\n");
    exit(6);
}
@chmod($final, 0600);
$sha = hash_file('sha256', $final);
$bytes = filesize($final);
$pdo->prepare("UPDATE backup_runs SET status='success',artifact_sha256=?,bytes_written=?,finished_at=UTC_TIMESTAMP() WHERE id=?")
    ->execute([$sha, $bytes, $runId]);
fwrite(STDOUT, "Backup success: " . basename($final) . " bytes=" . $bytes . " sha256=" . $sha . "\n");
