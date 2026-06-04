<?php
declare(strict_types=1);

function load_playlists(int $limit = 0): array
{
    ensure_app_storage();
    $sql = 'SELECT * FROM playlists ORDER BY created_at DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    return db()->query($sql)->fetchAll();
}

function playlist_count(): int
{
    ensure_app_storage();
    return (int) db()->query('SELECT COUNT(*) FROM playlists')->fetchColumn();
}

function save_playlists(array $playlists): void
{
    ensure_app_storage();
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM playlists');
    $stmt = $pdo->prepare('
        INSERT INTO playlists (
            id, title, type, source, default_country, default_city,
            created_at, updated_at, last_error, channel_count
        ) VALUES (
            :id, :title, :type, :source, :default_country, :default_city,
            :created_at, :updated_at, :last_error, :channel_count
        )
    ');

    foreach ($playlists as $playlist) {
        $stmt->execute([
            ':id' => (string) ($playlist['id'] ?? ''),
            ':title' => normalize_playlist_title((string) ($playlist['title'] ?? ''), (string) ($playlist['source'] ?? ''), (string) ($playlist['type'] ?? 'url')),
            ':type' => (string) ($playlist['type'] ?? 'url'),
            ':source' => (string) ($playlist['source'] ?? ''),
            ':default_country' => normalize_country_value((string) ($playlist['default_country'] ?? '')),
            ':default_city' => (string) ($playlist['default_city'] ?? ''),
            ':created_at' => (string) ($playlist['created_at'] ?? date(DATE_ATOM)),
            ':updated_at' => $playlist['updated_at'] ?? null,
            ':last_error' => $playlist['last_error'] ?? null,
            ':channel_count' => (int) ($playlist['channel_count'] ?? 0),
        ]);
    }
    $pdo->commit();
}
