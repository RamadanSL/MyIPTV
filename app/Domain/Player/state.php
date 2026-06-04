<?php
declare(strict_types=1);

function load_player_state(string $sessionKey): array
{
    ensure_app_storage();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM player_state WHERE session_key = :session_key');
    $stmt->execute([':session_key' => $sessionKey]);
    $state = $stmt->fetch() ?: [];

    $positionsStmt = $pdo->prepare('SELECT * FROM player_positions WHERE session_key = :session_key');
    $positionsStmt->execute([':session_key' => $sessionKey]);
    $positions = [];
    foreach ($positionsStmt->fetchAll() as $position) {
        $positions[(string) $position['channel_id']] = [
            'current_time' => (float) $position['current_time'],
            'duration' => (float) $position['duration'],
            'updated_at' => (string) $position['updated_at'],
        ];
    }

    return [
        'last_channel_id' => (string) ($state['last_channel_id'] ?? ''),
        'volume' => isset($state['volume']) ? (float) $state['volume'] : 1,
        'muted' => !empty($state['muted']),
        'updated_at' => (string) ($state['updated_at'] ?? ''),
        'positions' => $positions,
    ];
}

function save_player_state(string $sessionKey, array $payload): array
{
    ensure_app_storage();
    $channelId = trim((string) ($payload['channel_id'] ?? ''));
    if ($channelId === '') {
        throw new InvalidArgumentException('channel_id is required');
    }

    $previous = load_player_state($sessionKey);
    $volume = max(0, min(1, (float) ($payload['volume'] ?? ($previous['volume'] ?? 1))));
    $muted = !empty($payload['muted']);
    $now = date(DATE_ATOM);
    $pdo = db();

    $stmt = $pdo->prepare('
        INSERT INTO player_state (session_key, last_channel_id, volume, muted, updated_at)
        VALUES (:session_key, :last_channel_id, :volume, :muted, :updated_at)
        ON CONFLICT(session_key) DO UPDATE SET
            last_channel_id = excluded.last_channel_id,
            volume = excluded.volume,
            muted = excluded.muted,
            updated_at = excluded.updated_at
    ');
    $stmt->execute([
        ':session_key' => $sessionKey,
        ':last_channel_id' => $channelId,
        ':volume' => $volume,
        ':muted' => $muted ? 1 : 0,
        ':updated_at' => $now,
    ]);

    $positionStmt = $pdo->prepare('
        INSERT INTO player_positions (session_key, channel_id, current_time, duration, updated_at)
        VALUES (:session_key, :channel_id, :current_time, :duration, :updated_at)
        ON CONFLICT(session_key, channel_id) DO UPDATE SET
            current_time = excluded.current_time,
            duration = excluded.duration,
            updated_at = excluded.updated_at
    ');
    $positionStmt->execute([
        ':session_key' => $sessionKey,
        ':channel_id' => $channelId,
        ':current_time' => max(0, (float) ($payload['current_time'] ?? 0)),
        ':duration' => max(0, (float) ($payload['duration'] ?? 0)),
        ':updated_at' => $now,
    ]);

    return load_player_state($sessionKey);
}
