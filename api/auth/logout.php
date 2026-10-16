<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Http.php';

Http::method('POST');
$db = Database::connection();
$user = Http::authenticate($db);
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/^Bearer\s+(.+)$/i', $header, $matches);
$hash = hash('sha256', trim($matches[1]));
$delete = $db->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND token_hash = ?');
$delete->execute([(int)$user['id'], $hash]);

Http::respond(200, ['status' => 'logged_out']);
