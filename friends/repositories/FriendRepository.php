<?php
declare(strict_types=1);

final class PCFriendRepository
{
    public static function friends(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT u.id, u.username, u.first_name, u.last_name FROM friendships f JOIN users u ON u.id = f.friend_id WHERE f.user_id = ? AND u.email_verified_at IS NOT NULL ORDER BY u.username LIMIT 500');
        $stmt->execute([$userId]);
        return array_map(static fn(array $r): array => [
            'id' => (string)$r['id'], 'username' => $r['username'],
            'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
        ], $stmt->fetchAll());
    }

    public static function requests(PDO $pdo, int $userId, string $direction): array
    {
        $filter = $direction === 'outgoing' ? 'fr.requester_id = ?' : 'fr.recipient_id = ?';
        $sql = "SELECT fr.id, fr.requester_id, fr.created_at, u.id AS peer_id, u.username, u.first_name, u.last_name
                FROM friend_requests fr
                JOIN users u ON u.id = CASE WHEN fr.requester_id = ? THEN fr.recipient_id ELSE fr.requester_id END
                WHERE $filter AND fr.status = 'pending'
                ORDER BY fr.created_at DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId]);
        return array_map(static fn(array $r): array => [
            'id' => (string)$r['id'],
            'user' => ['id' => (string)$r['peer_id'], 'username' => $r['username'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name']],
            'direction' => (int)$r['requester_id'] === $userId ? 'outgoing' : 'incoming',
            'created_at' => $r['created_at'],
        ], $stmt->fetchAll());
    }
}
