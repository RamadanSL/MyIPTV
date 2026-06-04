<?php
declare(strict_types=1);

function db_migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS playlists (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            type TEXT NOT NULL,
            source TEXT NOT NULL,
            default_country TEXT NOT NULL DEFAULT '',
            default_city TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT,
            last_error TEXT,
            channel_count INTEGER NOT NULL DEFAULT 0
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS channels (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            logo TEXT NOT NULL DEFAULT '',
            genre TEXT NOT NULL DEFAULT 'Без группы',
            country TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            source_id TEXT NOT NULL,
            source_title TEXT NOT NULL,
            tvg_id TEXT NOT NULL DEFAULT '',
            stream_type TEXT NOT NULL DEFAULT 'stream',
            access_type TEXT NOT NULL DEFAULT 'open',
            access_notes TEXT NOT NULL DEFAULT '',
            stream_headers TEXT NOT NULL DEFAULT '',
            drm_system TEXT NOT NULL DEFAULT '',
            license_url TEXT NOT NULL DEFAULT '',
            license_headers TEXT NOT NULL DEFAULT '',
            health_status TEXT NOT NULL DEFAULT 'unknown',
            last_checked_at TEXT,
            last_http_status INTEGER,
            last_probe_error TEXT,
            working_url TEXT,
            updated_at TEXT NOT NULL,
            UNIQUE(url, name)
        );
    ");

    db_add_column_if_missing($pdo, 'channels', 'health_status', "TEXT NOT NULL DEFAULT 'unknown'");
    db_add_column_if_missing($pdo, 'channels', 'last_checked_at', 'TEXT');
    db_add_column_if_missing($pdo, 'channels', 'last_http_status', 'INTEGER');
    db_add_column_if_missing($pdo, 'channels', 'last_probe_error', 'TEXT');
    db_add_column_if_missing($pdo, 'channels', 'working_url', 'TEXT');
    db_add_column_if_missing($pdo, 'channels', 'access_type', "TEXT NOT NULL DEFAULT 'open'");
    db_add_column_if_missing($pdo, 'channels', 'access_notes', "TEXT NOT NULL DEFAULT ''");
    db_add_column_if_missing($pdo, 'channels', 'stream_headers', "TEXT NOT NULL DEFAULT ''");
    db_add_column_if_missing($pdo, 'channels', 'drm_system', "TEXT NOT NULL DEFAULT ''");
    db_add_column_if_missing($pdo, 'channels', 'license_url', "TEXT NOT NULL DEFAULT ''");
    db_add_column_if_missing($pdo, 'channels', 'license_headers', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec('CREATE INDEX IF NOT EXISTS channels_genre_idx ON channels(genre)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS channels_country_idx ON channels(country)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS channels_city_idx ON channels(city)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS channels_source_idx ON channels(source_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS channels_health_idx ON channels(health_status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS channels_access_type_idx ON channels(access_type)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS player_state (
            session_key TEXT PRIMARY KEY,
            last_channel_id TEXT NOT NULL DEFAULT '',
            volume REAL NOT NULL DEFAULT 1,
            muted INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS player_positions (
            session_key TEXT NOT NULL,
            channel_id TEXT NOT NULL,
            current_time REAL NOT NULL DEFAULT 0,
            duration REAL NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (session_key, channel_id)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS channel_favorites (
            session_key TEXT NOT NULL,
            channel_id TEXT NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (session_key, channel_id)
        );
    ");
    db_migrate_channel_favorites($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wanted_channels (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            aliases TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL DEFAULT '',
            priority INTEGER NOT NULL DEFAULT 50,
            status TEXT NOT NULL DEFAULT 'missing',
            last_found_channel_id TEXT NOT NULL DEFAULT '',
            last_found_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");
    db_add_column_if_missing($pdo, 'wanted_channels', 'last_search_at', 'TEXT');
    db_add_column_if_missing($pdo, 'wanted_channels', 'last_search_note', "TEXT NOT NULL DEFAULT ''");
    db_add_column_if_missing($pdo, 'wanted_channels', 'last_candidate_url', "TEXT NOT NULL DEFAULT ''");
    db_add_column_if_missing($pdo, 'wanted_channels', 'last_candidate_title', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec('CREATE INDEX IF NOT EXISTS wanted_channels_status_idx ON wanted_channels(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS wanted_channels_found_idx ON wanted_channels(last_found_channel_id)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wanted_search_results (
            id TEXT PRIMARY KEY,
            wanted_id TEXT NOT NULL,
            alias TEXT NOT NULL DEFAULT '',
            title TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '',
            kind TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT '',
            http_status INTEGER,
            error TEXT NOT NULL DEFAULT '',
            imported_playlist_id TEXT,
            created_at TEXT NOT NULL
        );
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS wanted_search_results_wanted_idx ON wanted_search_results(wanted_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS wanted_search_results_status_idx ON wanted_search_results(status)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS channel_replacements (
            id TEXT PRIMARY KEY,
            channel_id TEXT NOT NULL,
            old_name TEXT NOT NULL DEFAULT '',
            old_url TEXT NOT NULL DEFAULT '',
            replacement_name TEXT NOT NULL DEFAULT '',
            replacement_url TEXT NOT NULL DEFAULT '',
            source_id TEXT NOT NULL DEFAULT '',
            source_title TEXT NOT NULL DEFAULT '',
            score REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL,
            reason TEXT,
            created_at TEXT NOT NULL
        );
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS channel_replacements_channel_idx ON channel_replacements(channel_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS channel_replacements_url_idx ON channel_replacements(channel_id, replacement_url)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS discovery_sources (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            url TEXT NOT NULL,
            mode TEXT NOT NULL DEFAULT 'page',
            auto_import INTEGER NOT NULL DEFAULT 0,
            default_country TEXT NOT NULL DEFAULT '',
            default_city TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT,
            last_error TEXT,
            candidate_count INTEGER NOT NULL DEFAULT 0
        );
    ");

    db_add_column_if_missing($pdo, 'discovery_sources', 'auto_import', 'INTEGER NOT NULL DEFAULT 0');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS discovery_candidates (
            id TEXT PRIMARY KEY,
            source_id TEXT NOT NULL,
            title TEXT NOT NULL,
            url TEXT NOT NULL,
            kind TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'new',
            imported_playlist_id TEXT,
            note TEXT,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            UNIQUE(source_id, url),
            FOREIGN KEY(source_id) REFERENCES discovery_sources(id) ON DELETE CASCADE
        );
    ");

    $pdo->exec('CREATE INDEX IF NOT EXISTS discovery_candidates_status_idx ON discovery_candidates(status)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS web_search_providers (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            url_template TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT,
            last_error TEXT,
            last_result_count INTEGER NOT NULL DEFAULT 0
        );
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS web_search_providers_enabled_idx ON web_search_providers(enabled)');

    db_migrate_playlist_metadata($pdo);
    db_remove_autoseeded_wanted_channels($pdo);
    db_migrate_channel_countries($pdo);
}

function db_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $columns = array_column($stmt->fetchAll(), 'name');
    if (!in_array($column, $columns, true)) {
        try {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        } catch (PDOException $exception) {
            if (!str_contains(strtolower($exception->getMessage()), 'duplicate column')) {
                throw $exception;
            }
        }
    }
}

function db_migrate_channel_favorites(PDO $pdo): void
{
    $foreignKeys = $pdo->query('PRAGMA foreign_key_list(channel_favorites)')->fetchAll();
    if (!$foreignKeys) {
        return;
    }

    $pdo->beginTransaction();
    $pdo->exec('ALTER TABLE channel_favorites RENAME TO channel_favorites_old');
    $pdo->exec("
        CREATE TABLE channel_favorites (
            session_key TEXT NOT NULL,
            channel_id TEXT NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (session_key, channel_id)
        );
    ");
    $pdo->exec('
        INSERT OR IGNORE INTO channel_favorites (session_key, channel_id, created_at)
        SELECT session_key, channel_id, created_at FROM channel_favorites_old
    ');
    $pdo->exec('DROP TABLE channel_favorites_old');
    $pdo->commit();
}

function db_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE "key" = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : $default;
}

function db_set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO app_settings ("key", value, updated_at)
        VALUES (:key, :value, :updated_at)
        ON CONFLICT("key") DO UPDATE SET
            value = excluded.value,
            updated_at = excluded.updated_at
    ');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => date(DATE_ATOM),
    ]);
}

function db_migrate_channel_countries(PDO $pdo): void
{
    if (db_setting($pdo, 'channel_country_normalized_v3') === '1') {
        return;
    }

    $rows = $pdo->query("
        SELECT channels.id,
               channels.country,
               channels.name,
               channels.genre,
               channels.source_title,
               channels.url,
               playlists.source AS playlist_source,
               playlists.default_country
        FROM channels
        LEFT JOIN playlists ON playlists.id = channels.source_id
    ")->fetchAll();

    if ($rows) {
        $stmt = $pdo->prepare('UPDATE channels SET country = :country WHERE id = :id');
        $pdo->beginTransaction();
        foreach ($rows as $row) {
            $country = infer_country_from_context(
                (string) ($row['country'] ?? ''),
                [
                    (string) ($row['default_country'] ?? ''),
                    (string) ($row['source_title'] ?? ''),
                    (string) ($row['playlist_source'] ?? ''),
                    (string) ($row['name'] ?? ''),
                    (string) ($row['genre'] ?? ''),
                    (string) ($row['url'] ?? ''),
                ]
            );

            if ($country === (string) ($row['country'] ?? '')) {
                continue;
            }

            $stmt->execute([
                ':country' => $country,
                ':id' => (string) $row['id'],
            ]);
        }
        $pdo->commit();
    }

    db_set_setting($pdo, 'channel_country_normalized_v3', '1');
}

function db_migrate_playlist_metadata(PDO $pdo): void
{
    if (db_setting($pdo, 'playlist_metadata_normalized_v1') === '1') {
        return;
    }

    $rows = $pdo->query('SELECT id, title, type, source, default_country FROM playlists')->fetchAll();
    if ($rows) {
        $stmt = $pdo->prepare('
            UPDATE playlists
            SET title = :title,
                default_country = :default_country
            WHERE id = :id
        ');
        $pdo->beginTransaction();
        foreach ($rows as $row) {
            $title = normalize_playlist_title(
                (string) ($row['title'] ?? ''),
                (string) ($row['source'] ?? ''),
                (string) ($row['type'] ?? 'url')
            );
            $country = infer_country_from_context(
                (string) ($row['default_country'] ?? ''),
                [(string) ($row['title'] ?? ''), (string) ($row['source'] ?? '')]
            );
            $stmt->execute([
                ':title' => $title,
                ':default_country' => $country,
                ':id' => (string) $row['id'],
            ]);
        }
        $pdo->commit();
    }

    db_set_setting($pdo, 'playlist_metadata_normalized_v1', '1');
}

function db_remove_autoseeded_wanted_channels(PDO $pdo): void
{
    if (db_setting($pdo, 'wanted_channels_autoseed_removed_v1') === '1') {
        return;
    }

    db_set_setting($pdo, 'wanted_channels_autoseed_removed_v1', '1');
}
