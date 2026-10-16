<?php
declare(strict_types=1);

/*
 * Run from the project root with PHP CLI after config.php exists:
 *   php database/migrate.php
 * This file intentionally refuses web requests.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/bootstrap.php';

$pdo = pc_db();
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$directory = __DIR__ . '/migrations';
$files = glob($directory . '/*.sql') ?: [];
sort($files, SORT_STRING);
$check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1');
$record = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
$applied = 0;
foreach ($files as $file) {
    $version = basename($file);
    $check->execute([$version]);
    if ($check->fetchColumn()) {
        echo "SKIP {$version}\n";
        continue;
    }
    $sql = file_get_contents($file);
    if (!is_string($sql) || trim($sql) === '') {
        fwrite(STDERR, "ERROR {$version}: empty or unreadable migration\n");
        exit(1);
    }
    try {
        // The project's migrations contain only ordinary DDL statements.
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $pdo->exec($statement);
        }
        $record->execute([$version]);
        $applied++;
        echo "APPLIED {$version}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR {$version}: migration stopped; inspect database before retrying. " . $e->getMessage() . "\n");
        exit(1);
    }
}
echo "Done. Newly applied migrations: {$applied}.\n";
