<?php
declare(strict_types=1);

function load_channel_favorites(string $sessionKey): array
{
    ensure_app_storage();
    $stmt = db()->prepare('SELECT channel_id FROM channel_favorites WHERE session_key = :session_key');
    $stmt->execute([':session_key' => $sessionKey]);
    return array_map(static fn (array $row): string => (string) $row['channel_id'], $stmt->fetchAll());
}

function is_channel_favorite(string $sessionKey, string $channelId): bool
{
    if ($sessionKey === '' || $channelId === '') {
        return false;
    }

    $stmt = db()->prepare('SELECT 1 FROM channel_favorites WHERE session_key = :session_key AND channel_id = :channel_id LIMIT 1');
    $stmt->execute([
        ':session_key' => $sessionKey,
        ':channel_id' => $channelId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function set_channel_favorite(string $sessionKey, string $channelId, bool $favorite): bool
{
    ensure_app_storage();
    if ($sessionKey === '' || $channelId === '') {
        throw new InvalidArgumentException('Не указан канал.');
    }

    if ($favorite) {
        $stmt = db()->prepare('
            INSERT OR IGNORE INTO channel_favorites (session_key, channel_id, created_at)
            VALUES (:session_key, :channel_id, :created_at)
        ');
        $stmt->execute([
            ':session_key' => $sessionKey,
            ':channel_id' => $channelId,
            ':created_at' => date(DATE_ATOM),
        ]);
        return true;
    }

    $stmt = db()->prepare('DELETE FROM channel_favorites WHERE session_key = :session_key AND channel_id = :channel_id');
    $stmt->execute([
        ':session_key' => $sessionKey,
        ':channel_id' => $channelId,
    ]);

    return false;
}
