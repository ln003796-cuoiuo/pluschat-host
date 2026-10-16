<?php
declare(strict_types=1);

/*
 * Pluschat profile API routes.
 * Business logic remains in this feature module; shared HTTP/database helpers
 * are loaded by public/index.php through bootstrap.php.
 */
require_once dirname(__DIR__) . '/services/ProfileService.php';

function pc_profile_dispatch(string $path, string $method): bool
{
    if ($path === '/api/v1/auth/profile' && $method === 'POST') {
        $user = pc_require_verified_user();
        $result = PCProfileService::update((int)$user['id'], pc_input());
        pc_json($result['status'], $result['body']);
    }

    if ($path === '/api/v1/birthdays' && $method === 'GET') {
            $user = pc_require_verified_user();
            $sql = "SELECT u.id, u.username, u.first_name, u.last_name,
                CASE WHEN DATE_FORMAT(u.birth_date, '%m-%d') >= DATE_FORMAT(UTC_DATE(), '%m-%d')
                     THEN STR_TO_DATE(CONCAT(YEAR(UTC_DATE()), '-', DATE_FORMAT(u.birth_date, '%m-%d')), '%Y-%m-%d')
                     ELSE STR_TO_DATE(CONCAT(YEAR(UTC_DATE()) + 1, '-', DATE_FORMAT(u.birth_date, '%m-%d')), '%Y-%m-%d')
                END AS next_birthday,
                DATEDIFF(
                    CASE WHEN DATE_FORMAT(u.birth_date, '%m-%d') >= DATE_FORMAT(UTC_DATE(), '%m-%d')
                         THEN STR_TO_DATE(CONCAT(YEAR(UTC_DATE()), '-', DATE_FORMAT(u.birth_date, '%m-%d')), '%Y-%m-%d')
                         ELSE STR_TO_DATE(CONCAT(YEAR(UTC_DATE()) + 1, '-', DATE_FORMAT(u.birth_date, '%m-%d')), '%Y-%m-%d')
                    END, UTC_DATE()
                ) AS days_until
                FROM users u JOIN user_settings s ON s.user_id = u.id
                WHERE u.id <> ? AND u.email_verified_at IS NOT NULL AND u.birth_date IS NOT NULL
                  AND s.show_birth_date = 1 AND s.profile_visibility <> 'nobody'
                  AND (s.profile_visibility = 'everyone' OR EXISTS (
                      SELECT 1 FROM conversation_members mine
                      JOIN conversation_members theirs ON theirs.conversation_id = mine.conversation_id
                      WHERE mine.user_id = ? AND theirs.user_id = u.id
                  ))
                  AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.user_id = ? AND b.blocked_user_id = u.id) OR (b.user_id = u.id AND b.blocked_user_id = ?))
                HAVING days_until BETWEEN 0 AND 30
                ORDER BY days_until, u.username LIMIT 100";
            $stmt = pc_db()->prepare($sql);
            $stmt->execute([$user['id'], $user['id'], $user['id'], $user['id']]);
            pc_json(200, ['ok' => true, 'birthdays' => $stmt->fetchAll()]);
        }

    if ($path === '/api/v1/settings' && ($method === 'GET' || $method === 'POST')) {
            $user = pc_require_verified_user();
            $pdo = pc_db();
            $pdo->prepare('INSERT IGNORE INTO user_settings (user_id) VALUES (?)')->execute([$user['id']]);
    
            if ($method === 'POST') {
                $data = pc_input();
                $allowed = [
                    'profile_visibility' => ['everyone', 'contacts', 'nobody'],
                    'theme' => ['system', 'light', 'dark'],
                ];
                $updates = [];
                $values = [];
                foreach ($allowed as $field => $options) {
                    if (array_key_exists($field, $data)) {
                        if (!is_string($data[$field]) || !in_array($data[$field], $options, true)) {
                            pc_json(422, ['ok' => false, 'error' => 'invalid_' . $field]);
                        }
                        $updates[] = $field . ' = ?';
                        $values[] = $data[$field];
                    }
                }
                foreach (['show_birth_date', 'show_local_group', 'location_sharing', 'notifications_enabled'] as $field) {
                    if (array_key_exists($field, $data)) {
                        if (!is_bool($data[$field]) && !in_array($data[$field], [0, 1, '0', '1'], true)) {
                            pc_json(422, ['ok' => false, 'error' => 'invalid_' . $field]);
                        }
                        $updates[] = $field . ' = ?';
                        $values[] = (int)(bool)$data[$field];
                    }
                }
                if (array_key_exists('currency', $data)) {
                    $currency = strtoupper(pc_string($data, 'currency'));
                    if (!preg_match('/^[A-Z]{3}$/', $currency)) pc_json(422, ['ok' => false, 'error' => 'invalid_currency']);
                    $updates[] = 'currency = ?';
                    $values[] = $currency;
                }
                if (array_key_exists('language', $data)) {
                    $language = pc_string($data, 'language');
                    if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language)) pc_json(422, ['ok' => false, 'error' => 'invalid_language']);
                    $updates[] = 'language = ?';
                    $values[] = $language;
                }
                if (array_key_exists('area_code', $data)) {
                    $areaCode = pc_string($data, 'area_code');
                    if (pc_len($areaCode) > 100) pc_json(422, ['ok' => false, 'error' => 'invalid_area_code']);
                    $updates[] = 'area_code = ?';
                    $values[] = $areaCode === '' ? null : $areaCode;
                }
                if (!$updates) pc_json(422, ['ok' => false, 'error' => 'no_settings_to_update']);
                $values[] = $user['id'];
                $pdo->prepare('UPDATE user_settings SET ' . implode(', ', $updates) . ' WHERE user_id = ?')->execute($values);
    
                $stmt = $pdo->prepare('SELECT show_local_group, location_sharing, area_code FROM user_settings WHERE user_id = ?');
                $stmt->execute([$user['id']]);
                $prefs = $stmt->fetch();
                if ($prefs && (int)$prefs['show_local_group'] === 1 && (int)$prefs['location_sharing'] === 1 && !empty($prefs['area_code'])) {
                    $area = (string)$prefs['area_code'];
                    $find = $pdo->prepare("SELECT id FROM chat_groups WHERE group_kind = 'local_area' AND area_code = ? LIMIT 1");
                    $find->execute([$area]);
                    $local = $find->fetch();
                    if (!$local) {
                        try {
                            $invite = bin2hex(random_bytes(16));
                            $pdo->prepare("INSERT INTO chat_groups (owner_id, title, description, group_kind, area_code, is_private, invite_code) VALUES (?, ?, ?, 'local_area', ?, 0, ?)")
                                ->execute([$user['id'], 'Местная группа', 'Группа пользователей этого района', $area, $invite]);
                            $localId = (int)$pdo->lastInsertId();
                        } catch (PDOException $e) {
                            $find->execute([$area]);
                            $local = $find->fetch();
                            if (!$local) throw $e;
                            $localId = (int)$local['id'];
                        }
                    } else {
                        $localId = (int)$local['id'];
                    }
                    $pdo->prepare('INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')
                        ->execute([$localId, $user['id'], 'member']);
                }
            }
    
            $stmt = $pdo->prepare('SELECT profile_visibility, show_birth_date, show_local_group, location_sharing, area_code, language, currency, theme, notifications_enabled FROM user_settings WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            pc_json(200, ['ok' => true, 'settings' => $stmt->fetch()]);
        }
    return false;
}
