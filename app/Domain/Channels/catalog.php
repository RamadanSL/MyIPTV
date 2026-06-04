<?php
declare(strict_types=1);

function channel_count(bool $includeDead = false): int
{
    ensure_app_storage();
    $where = $includeDead ? '' : "WHERE health_status != 'dead'";
    return (int) db()->query('SELECT COUNT(*) FROM channels ' . $where)->fetchColumn();
}

function query_channels(array $filters = [], int $limit = 300, int $offset = 0, bool $includeDead = false): array
{
    ensure_app_storage();
    [$where, $params] = channel_filter_sql($filters, $includeDead);
    $sql = 'SELECT * FROM channels ' . $where . channel_order_sql($filters) . ' LIMIT :limit OFFSET :offset';
    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function count_filtered_channels(array $filters = [], bool $includeDead = false): int
{
    ensure_app_storage();
    [$where, $params] = channel_filter_sql($filters, $includeDead);
    $stmt = db()->prepare('SELECT COUNT(*) FROM channels ' . $where);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function unique_channel_values(string $key, bool $includeDead = false, int $limit = 500): array
{
    $allowed = ['genre', 'country', 'city', 'source_id'];
    if (!in_array($key, $allowed, true)) {
        throw new InvalidArgumentException('Недопустимый фильтр каналов.');
    }

    if ($key === 'country') {
        return unique_channel_countries($includeDead, $limit);
    }

    $where = $includeDead ? "WHERE $key != ''" : "WHERE health_status != 'dead' AND $key != ''";
    $sql = "SELECT DISTINCT $key AS value FROM channels $where ORDER BY value COLLATE NOCASE LIMIT :limit";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return array_map(static fn (array $row): string => (string) $row['value'], $stmt->fetchAll());
}

function unique_channel_countries(bool $includeDead = false, int $limit = 500): array
{
    $where = $includeDead ? "WHERE country != ''" : "WHERE health_status != 'dead' AND country != ''";
    $stmt = db()->prepare("SELECT country AS value, COUNT(*) AS count FROM channels $where GROUP BY country LIMIT :limit");
    $stmt->bindValue(':limit', max(1, $limit * 3), PDO::PARAM_INT);
    $stmt->execute();

    $countries = [];
    foreach ($stmt->fetchAll() as $row) {
        $country = normalize_country_value((string) ($row['value'] ?? ''));
        if ($country !== '') {
            $countries[$country] = ($countries[$country] ?? 0) + (int) ($row['count'] ?? 0);
        }
    }

    uksort($countries, static function (string $left, string $right) use ($countries): int {
        $count = $countries[$right] <=> $countries[$left];
        return $count !== 0 ? $count : country_compare($left, $right);
    });

    return array_slice(array_keys($countries), 0, $limit);
}

function channel_filter_sql(array $filters, bool $includeDead): array
{
    $conditions = [];
    $params = [];

    if (!$includeDead) {
        $conditions[] = "health_status != 'dead'";
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $searchConditions = [];
        foreach (channel_search_terms($q) as $index => $term) {
            $param = ':q' . $index;
            $searchConditions[] = "(
                name LIKE $param ESCAPE '\\'
                OR genre LIKE $param ESCAPE '\\'
                OR country LIKE $param ESCAPE '\\'
                OR city LIKE $param ESCAPE '\\'
                OR source_title LIKE $param ESCAPE '\\'
                OR tvg_id LIKE $param ESCAPE '\\'
                OR url LIKE $param ESCAPE '\\'
            )";
            $params[$param] = channel_like_pattern($term);
        }
        if ($searchConditions) {
            $conditions[] = '(' . implode(' AND ', $searchConditions) . ')';
        }
    }

    foreach (['genre', 'city'] as $key) {
        $value = trim((string) ($filters[$key] ?? ''));
        if ($value !== '') {
            $conditions[] = $key . ' = :' . $key;
            $params[':' . $key] = $value;
        }
    }

    $country = normalize_country_value((string) ($filters['country'] ?? ''));
    if ($country !== '') {
        $conditions[] = country_filter_condition($country, $params);
    }

    $source = trim((string) ($filters['source'] ?? ''));
    if ($source !== '') {
        $conditions[] = 'source_id = :source';
        $params[':source'] = $source;
    }

    $health = trim((string) ($filters['health'] ?? ''));
    if (in_array($health, ['live', 'unknown', 'dead'], true)) {
        $conditions[] = 'health_status = :health';
        $params[':health'] = $health;
    }

    if (!empty($filters['has_logo'])) {
        $conditions[] = "logo != ''";
    }

    if (!empty($filters['quality_hd'])) {
        $conditions[] = "(name LIKE :quality_hd OR url LIKE :quality_hd OR tvg_id LIKE :quality_hd)";
        $params[':quality_hd'] = '%hd%';
    }

    $quick = trim((string) ($filters['quick'] ?? ''));
    if ($quick === 'hd') {
        $conditions[] = "(name LIKE :quick_hd OR name LIKE :quick_720 OR name LIKE :quick_1080 OR url LIKE :quick_1080)";
        $params[':quick_hd'] = '%HD%';
        $params[':quick_720'] = '%720%';
        $params[':quick_1080'] = '%1080%';
    } elseif ($quick === '4k') {
        $conditions[] = "(name LIKE :quick_4k OR name LIKE :quick_uhd OR name LIKE :quick_2160 OR url LIKE :quick_2160)";
        $params[':quick_4k'] = '%4K%';
        $params[':quick_uhd'] = '%UHD%';
        $params[':quick_2160'] = '%2160%';
    } elseif ($quick === 'radio') {
        $conditions[] = "stream_type = 'audio'";
    } elseif ($quick === 'live') {
        $conditions[] = "health_status = 'live'";
    } elseif ($quick === 'unchecked') {
        $conditions[] = "(health_status = 'unknown' OR last_checked_at IS NULL OR last_checked_at = '')";
    } elseif ($quick === 'logos') {
        $conditions[] = "logo != ''";
    } elseif ($quick === 'wanted') {
        $conditions[] = wanted_channel_filter_condition($params);
    }

    $favoriteSession = trim((string) ($filters['favorite_session'] ?? ''));
    if (!empty($filters['favorite']) && $favoriteSession !== '') {
        $conditions[] = 'EXISTS (SELECT 1 FROM channel_favorites cf WHERE cf.channel_id = channels.id AND cf.session_key = :favorite_session)';
        $params[':favorite_session'] = $favoriteSession;
    }

    return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
}

function channel_search_terms(string $query): array
{
    $query = clean_text($query);
    if ($query === '') {
        return [];
    }

    $parts = preg_split('/\s+/u', $query) ?: [];
    $terms = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
        if ($length < 2 && !ctype_digit($part)) {
            continue;
        }

        $terms[$part] = true;
    }

    if (!$terms) {
        $terms[$query] = true;
    }

    return array_slice(array_keys($terms), 0, 8);
}

function channel_like_pattern(string $value): string
{
    return '%' . strtr($value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]) . '%';
}

function channel_order_sql(array $filters = []): string
{
    $sort = trim((string) ($filters['sort'] ?? 'health'));
    if ($sort === 'name') {
        return ' ORDER BY name COLLATE NOCASE';
    }
    if ($sort === 'recent') {
        $session = trim((string) ($filters['favorite_session'] ?? ''));
        if ($session !== '') {
            return " ORDER BY (SELECT COALESCE(MAX(updated_at), '') FROM player_positions pp WHERE pp.channel_id = channels.id AND pp.session_key = " . db()->quote($session) . ") DESC, name COLLATE NOCASE";
        }
    }
    if ($sort === 'source') {
        return ' ORDER BY source_title COLLATE NOCASE, name COLLATE NOCASE';
    }
    if ($sort === 'country') {
        return ' ORDER BY CASE health_status WHEN \'live\' THEN 0 WHEN \'unknown\' THEN 1 ELSE 2 END, ' . country_order_case_sql('country') . ', country COLLATE NOCASE, city COLLATE NOCASE, name COLLATE NOCASE';
    }

    return " ORDER BY CASE health_status WHEN 'live' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END, genre COLLATE NOCASE, name COLLATE NOCASE";
}

function load_channels(bool $includeDead = false): array
{
    ensure_app_storage();
    $where = $includeDead ? '' : "WHERE health_status != 'dead'";
    return db()->query('SELECT * FROM channels ' . $where . ' ORDER BY genre COLLATE NOCASE, name COLLATE NOCASE')->fetchAll();
}

function catalog_insights(string $sessionKey = ''): array
{
    ensure_app_storage();
    $pdo = db();
    $health = [
        'live' => 0,
        'dead' => 0,
        'unknown' => 0,
        'total' => 0,
    ];

    foreach ($pdo->query('SELECT health_status, COUNT(*) AS c FROM channels GROUP BY health_status')->fetchAll() as $row) {
        $status = (string) ($row['health_status'] ?? 'unknown');
        $count = (int) ($row['c'] ?? 0);
        if (!isset($health[$status])) {
            $status = 'unknown';
        }
        $health[$status] += $count;
        $health['total'] += $count;
    }

    $total = max(0, (int) $health['total']);
    $visible = max(0, $total - (int) $health['dead']);
    $staleAfter = date(DATE_ATOM, time() - (3 * 86400));
    $favoriteCount = 0;
    $recentCount = 0;
    if ($sessionKey !== '') {
        $favoriteStmt = $pdo->prepare('SELECT COUNT(*) FROM channel_favorites WHERE session_key = :session_key');
        $favoriteStmt->execute([':session_key' => $sessionKey]);
        $favoriteCount = (int) $favoriteStmt->fetchColumn();

        $recentStmt = $pdo->prepare('SELECT COUNT(*) FROM player_positions WHERE session_key = :session_key');
        $recentStmt->execute([':session_key' => $sessionKey]);
        $recentCount = (int) $recentStmt->fetchColumn();
    }

    return [
        'health' => $health,
        'visible' => $visible,
        'dead_hidden' => max(0, (int) $health['dead']),
        'live_ratio' => catalog_percent((int) $health['live'], $total),
        'unknown_ratio' => catalog_percent((int) $health['unknown'], $total),
        'dead_ratio' => catalog_percent((int) $health['dead'], $total),
        'logo_count' => catalog_scalar_count("SELECT COUNT(*) FROM channels WHERE logo != '' AND health_status != 'dead'"),
        'logo_ratio' => catalog_percent(catalog_scalar_count("SELECT COUNT(*) FROM channels WHERE logo != '' AND health_status != 'dead'"), max(1, $visible)),
        'hls_count' => catalog_scalar_count("SELECT COUNT(*) FROM channels WHERE health_status != 'dead' AND (stream_type = 'hls' OR lower(url) LIKE '%.m3u8%')"),
        'audio_count' => catalog_scalar_count("SELECT COUNT(*) FROM channels WHERE health_status != 'dead' AND stream_type = 'audio'"),
        'protected_count' => catalog_scalar_count("SELECT COUNT(*) FROM channels WHERE access_type != '' AND access_type != 'open'"),
        'drm_count' => catalog_scalar_count("SELECT COUNT(*) FROM channels WHERE access_type = 'drm'"),
        'source_count' => catalog_scalar_count("SELECT COUNT(DISTINCT NULLIF(source_id, '')) FROM channels"),
        'genre_count' => catalog_scalar_count("SELECT COUNT(DISTINCT NULLIF(genre, '')) FROM channels WHERE health_status != 'dead'"),
        'country_count' => catalog_scalar_count("SELECT COUNT(DISTINCT NULLIF(country, '')) FROM channels WHERE health_status != 'dead'"),
        'stale_count' => catalog_scalar_count(
            "SELECT COUNT(*) FROM channels WHERE health_status != 'dead' AND (last_checked_at IS NULL OR last_checked_at = '' OR last_checked_at < :stale_after)",
            [':stale_after' => $staleAfter]
        ),
        'favorite_count' => $favoriteCount,
        'recent_count' => $recentCount,
        'latest_update' => (string) ($pdo->query("SELECT COALESCE(MAX(updated_at), '') FROM channels")->fetchColumn() ?: ''),
        'latest_check' => (string) ($pdo->query("SELECT COALESCE(MAX(last_checked_at), '') FROM channels")->fetchColumn() ?: ''),
    ];
}

function catalog_scalar_count(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function catalog_percent(int $part, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int) round(($part / $total) * 100);
}

function catalog_top_values(string $column, int $limit = 6, bool $includeDead = false): array
{
    $allowed = ['genre', 'country', 'city', 'source_title', 'source_id', 'health_status'];
    if (!in_array($column, $allowed, true)) {
        throw new InvalidArgumentException('Недопустимая аналитика каналов.');
    }

    $where = $includeDead ? "$column != ''" : "health_status != 'dead' AND $column != ''";
    $stmt = db()->prepare("
        SELECT $column AS value, COUNT(*) AS count
        FROM channels
        WHERE $where
        GROUP BY $column
        ORDER BY count DESC, value COLLATE NOCASE
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return array_map(static fn (array $row): array => [
        'value' => (string) $row['value'],
        'count' => (int) $row['count'],
    ], $stmt->fetchAll());
}

function catalog_source_health_report(int $limit = 12): array
{
    ensure_app_storage();
    $stmt = db()->prepare("
        SELECT
            COALESCE(NULLIF(source_id, ''), 'unknown') AS source_id,
            COALESCE(NULLIF(source_title, ''), 'Без источника') AS source_title,
            COUNT(*) AS total,
            SUM(CASE WHEN health_status = 'live' THEN 1 ELSE 0 END) AS live,
            SUM(CASE WHEN health_status = 'dead' THEN 1 ELSE 0 END) AS dead,
            SUM(CASE WHEN health_status = 'unknown' THEN 1 ELSE 0 END) AS unknown,
            SUM(CASE WHEN logo != '' THEN 1 ELSE 0 END) AS logos,
            MAX(COALESCE(last_checked_at, '')) AS last_checked_at,
            MAX(COALESCE(updated_at, '')) AS updated_at
        FROM channels
        GROUP BY COALESCE(NULLIF(source_id, ''), 'unknown'), COALESCE(NULLIF(source_title, ''), 'Без источника')
        ORDER BY (dead + unknown) DESC, total DESC, source_title COLLATE NOCASE
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $total = max(0, (int) $row['total']);
        $live = max(0, (int) $row['live']);
        $dead = max(0, (int) $row['dead']);
        $unknown = max(0, (int) $row['unknown']);
        $logos = max(0, (int) $row['logos']);
        $rows[] = [
            'source_id' => (string) $row['source_id'],
            'source_title' => (string) $row['source_title'],
            'total' => $total,
            'live' => $live,
            'dead' => $dead,
            'unknown' => $unknown,
            'logos' => $logos,
            'live_ratio' => catalog_percent($live, $total),
            'attention_ratio' => catalog_percent($dead + $unknown, $total),
            'logo_ratio' => catalog_percent($logos, $total),
            'last_checked_at' => (string) ($row['last_checked_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

function catalog_duplicate_channel_groups(int $limit = 8): array
{
    ensure_app_storage();
    $stmt = db()->prepare("
        SELECT
            lower(trim(name)) AS normalized_name,
            MIN(name) AS name,
            COUNT(*) AS count,
            SUM(CASE WHEN health_status = 'live' THEN 1 ELSE 0 END) AS live,
            GROUP_CONCAT(DISTINCT source_title) AS sources
        FROM channels
        WHERE health_status != 'dead' AND trim(name) != ''
        GROUP BY lower(trim(name))
        HAVING COUNT(*) > 1
        ORDER BY count DESC, live DESC, name COLLATE NOCASE
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return array_map(static fn (array $row): array => [
        'name' => (string) $row['name'],
        'count' => (int) $row['count'],
        'live' => (int) $row['live'],
        'sources' => array_slice(array_filter(array_map('trim', explode(',', (string) ($row['sources'] ?? '')))), 0, 4),
    ], $stmt->fetchAll());
}

function catalog_parser_report(int $sourceLimit = 10): array
{
    ensure_app_storage();
    $row = db()->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN logo != '' THEN 1 ELSE 0 END) AS logos,
            SUM(CASE WHEN genre != '' AND genre != 'Без группы' THEN 1 ELSE 0 END) AS groups_found,
            SUM(CASE WHEN country != '' THEN 1 ELSE 0 END) AS countries,
            SUM(CASE WHEN city != '' THEN 1 ELSE 0 END) AS cities,
            SUM(CASE WHEN tvg_id != '' THEN 1 ELSE 0 END) AS tvg_ids,
            SUM(CASE WHEN stream_type = 'hls' THEN 1 ELSE 0 END) AS hls,
            SUM(CASE WHEN stream_type IN ('udp', 'rtp', 'dash', 'audio') THEN 1 ELSE 0 END) AS special_streams
        FROM channels
    ")->fetch() ?: [];

    $total = max(0, (int) ($row['total'] ?? 0));
    $fields = [
        parser_metric('Логотипы', (int) ($row['logos'] ?? 0), $total),
        parser_metric('Группы', (int) ($row['groups_found'] ?? 0), $total),
        parser_metric('Страны', (int) ($row['countries'] ?? 0), $total),
        parser_metric('Города', (int) ($row['cities'] ?? 0), $total),
        parser_metric('TVG ID', (int) ($row['tvg_ids'] ?? 0), $total),
        parser_metric('HLS', (int) ($row['hls'] ?? 0), $total),
    ];

    $stmt = db()->prepare("
        SELECT
            COALESCE(NULLIF(source_id, ''), 'unknown') AS source_id,
            COALESCE(NULLIF(source_title, ''), 'Без источника') AS source_title,
            COUNT(*) AS total,
            SUM(CASE WHEN logo != '' THEN 1 ELSE 0 END) AS logos,
            SUM(CASE WHEN genre != '' AND genre != 'Без группы' THEN 1 ELSE 0 END) AS groups_found,
            SUM(CASE WHEN country != '' THEN 1 ELSE 0 END) AS countries,
            SUM(CASE WHEN tvg_id != '' THEN 1 ELSE 0 END) AS tvg_ids,
            SUM(CASE WHEN stream_type = 'hls' THEN 1 ELSE 0 END) AS hls
        FROM channels
        GROUP BY COALESCE(NULLIF(source_id, ''), 'unknown'), COALESCE(NULLIF(source_title, ''), 'Без источника')
        ORDER BY total DESC, source_title COLLATE NOCASE
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', max(1, $sourceLimit), PDO::PARAM_INT);
    $stmt->execute();

    $sources = [];
    foreach ($stmt->fetchAll() as $source) {
        $sourceTotal = max(0, (int) $source['total']);
        $richScore = (int) round((
            catalog_percent((int) $source['logos'], $sourceTotal)
            + catalog_percent((int) $source['groups_found'], $sourceTotal)
            + catalog_percent((int) $source['countries'], $sourceTotal)
            + catalog_percent((int) $source['tvg_ids'], $sourceTotal)
        ) / 4);
        $sources[] = [
            'source_id' => (string) $source['source_id'],
            'source_title' => (string) $source['source_title'],
            'total' => $sourceTotal,
            'logos_ratio' => catalog_percent((int) $source['logos'], $sourceTotal),
            'groups_ratio' => catalog_percent((int) $source['groups_found'], $sourceTotal),
            'countries_ratio' => catalog_percent((int) $source['countries'], $sourceTotal),
            'tvg_ratio' => catalog_percent((int) $source['tvg_ids'], $sourceTotal),
            'hls_ratio' => catalog_percent((int) $source['hls'], $sourceTotal),
            'rich_score' => $richScore,
        ];
    }

    return [
        'total' => $total,
        'fields' => $fields,
        'special_streams' => (int) ($row['special_streams'] ?? 0),
        'sources' => $sources,
    ];
}

function parser_metric(string $label, int $count, int $total): array
{
    return [
        'label' => $label,
        'count' => max(0, $count),
        'ratio' => catalog_percent(max(0, $count), max(0, $total)),
    ];
}

function catalog_recommendations(array $insights, array $sourceReport = [], array $playlistErrors = [], array $duplicates = []): array
{
    $items = [];
    $health = $insights['health'] ?? [];
    $unknown = (int) ($health['unknown'] ?? 0);
    $dead = (int) ($health['dead'] ?? 0);
    $total = (int) ($health['total'] ?? 0);
    $stale = (int) ($insights['stale_count'] ?? 0);

    if ($total === 0) {
        $items[] = [
            'tone' => 'warning',
            'title' => 'Каталог пустой',
            'body' => 'Начни с добавления M3U-ссылки или вставки своего M3U.',
            'url' => app_url('admin.php#admin-import'),
            'action' => 'Добавить плейлист',
        ];
        return $items;
    }

    if ($unknown > 0 || $stale > 0) {
        $items[] = [
            'tone' => 'warning',
            'title' => 'Нужна проверка живости',
            'body' => $unknown . ' каналов unknown, ' . $stale . ' давно не проверялись.',
            'url' => app_url('admin.php#admin-check'),
            'action' => 'Проверить',
        ];
    }

    if ($dead > 0) {
        $items[] = [
            'tone' => 'danger',
            'title' => 'Есть очередь ремонта',
            'body' => $dead . ' каналов помечены dead и скрыты из каталога.',
            'url' => app_url('admin.php#admin-check'),
            'action' => 'Искать замену',
        ];
    }

    $weakSource = $sourceReport[0] ?? null;
    if ($weakSource && (int) ($weakSource['attention_ratio'] ?? 0) >= 35 && (int) ($weakSource['total'] ?? 0) >= 5) {
        $items[] = [
            'tone' => 'warning',
            'title' => 'Проседает источник',
            'body' => (string) $weakSource['source_title'] . ': ' . (int) $weakSource['attention_ratio'] . '% dead/unknown.',
            'url' => app_url('index.php?source=' . urlencode((string) $weakSource['source_id']) . '&show_dead=1'),
            'action' => 'Открыть',
        ];
    }

    if ($playlistErrors) {
        $items[] = [
            'tone' => 'danger',
            'title' => 'Ошибки источников',
            'body' => count($playlistErrors) . ' источников вернули ошибку последнего обновления.',
            'url' => app_url('admin.php#admin-errors'),
            'action' => 'Посмотреть',
        ];
    }

    if ($duplicates) {
        $items[] = [
            'tone' => 'info',
            'title' => 'Есть дубли каналов',
            'body' => 'Найдено повторяющихся названий: ' . count($duplicates) . '. Можно оставить живые варианты.',
            'url' => app_url('admin.php#admin-quality'),
            'action' => 'Разобрать',
        ];
    }

    if ((int) ($insights['logo_ratio'] ?? 0) < 30 && (int) ($insights['visible'] ?? 0) > 10) {
        $items[] = [
            'tone' => 'info',
            'title' => 'Мало логотипов',
            'body' => 'Покрытие логотипами: ' . (int) ($insights['logo_ratio'] ?? 0) . '%. Вид “логотипы” будет беднее.',
            'url' => app_url('index.php?quick=logos'),
            'action' => 'Проверить',
        ];
    }

    return array_slice($items, 0, 5);
}

function load_related_channels(array $channel, string $mode = 'genre', int $limit = 8): array
{
    ensure_app_storage();
    $id = (string) ($channel['id'] ?? '');
    $conditions = ["id != :id", "health_status != 'dead'"];
    $params = [':id' => $id];

    if ($mode === 'source') {
        $sourceId = trim((string) ($channel['source_id'] ?? ''));
        if ($sourceId === '') {
            return [];
        }
        $conditions[] = 'source_id = :source_id';
        $params[':source_id'] = $sourceId;
    } elseif ($mode === 'country') {
        $country = normalize_country_value((string) ($channel['country'] ?? ''));
        if ($country === '') {
            return [];
        }
        $conditions[] = country_filter_condition($country, $params);
    } else {
        $genre = trim((string) ($channel['genre'] ?? ''));
        if ($genre === '') {
            return [];
        }
        $conditions[] = 'genre = :genre';
        $params[':genre'] = $genre;
    }

    $stmt = db()->prepare('
        SELECT *
        FROM channels
        WHERE ' . implode(' AND ', $conditions) . "
        ORDER BY CASE health_status WHEN 'live' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END, name COLLATE NOCASE
        LIMIT :limit
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function save_channels(array $channels): void
{
    ensure_app_storage();
    usort($channels, static function (array $a, array $b): int {
        return [$a['genre'] ?? '', $a['name'] ?? ''] <=> [$b['genre'] ?? '', $b['name'] ?? ''];
    });

    $pdo = db();
    $previousRows = $pdo->query('SELECT * FROM channels')->fetchAll();
    $previous = [];
    foreach ($previousRows as $row) {
        $previous[channel_state_key((string) $row['url'], (string) $row['name'])] = $row;
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM channels');
    $statements = channel_save_statements($pdo);

    foreach ($channels as $channel) {
        $old = $previous[channel_state_key((string) ($channel['url'] ?? ''), (string) ($channel['name'] ?? ''))] ?? [];
        channel_save_one($pdo, $statements, $channel, $old);
    }
    $pdo->commit();
}

function channel_save_statements(PDO $pdo): array
{
    return [
        'select_by_key' => $pdo->prepare('SELECT * FROM channels WHERE url = :url AND name = :name LIMIT 1'),
        'select_by_id' => $pdo->prepare('SELECT * FROM channels WHERE id = :id LIMIT 1'),
        'insert' => $pdo->prepare('
            INSERT INTO channels (
                id, name, url, logo, genre, country, city, source_id, source_title,
                tvg_id, stream_type, access_type, access_notes, stream_headers,
                drm_system, license_url, license_headers,
                health_status, last_checked_at, last_http_status, last_probe_error,
                working_url, updated_at
            ) VALUES (
                :id, :name, :url, :logo, :genre, :country, :city, :source_id, :source_title,
                :tvg_id, :stream_type, :access_type, :access_notes, :stream_headers,
                :drm_system, :license_url, :license_headers,
                :health_status, :last_checked_at, :last_http_status, :last_probe_error,
                :working_url, :updated_at
            )
        '),
        'update' => $pdo->prepare('
            UPDATE channels
            SET name = :name,
                url = :url,
                logo = :logo,
                genre = :genre,
                country = :country,
                city = :city,
                source_id = :source_id,
                source_title = :source_title,
                tvg_id = :tvg_id,
                stream_type = :stream_type,
                access_type = :access_type,
                access_notes = :access_notes,
                stream_headers = :stream_headers,
                drm_system = :drm_system,
                license_url = :license_url,
                license_headers = :license_headers,
                health_status = :health_status,
                last_checked_at = :last_checked_at,
                last_http_status = :last_http_status,
                last_probe_error = :last_probe_error,
                working_url = :working_url,
                updated_at = :updated_at
            WHERE id = :target_id
        '),
    ];
}

function channel_save_one(PDO $pdo, array $statements, array $channel, array $fallback = [], string $defaultSourceId = ''): ?array
{
    $row = channel_normalize_for_save($channel, $fallback, $defaultSourceId);
    if ($row === null) {
        return null;
    }

    $existing = channel_find_existing_for_save($statements, $row);
    if ($existing) {
        $row = channel_normalize_for_save($channel, $existing + $fallback, $defaultSourceId);
        if ($row === null) {
            return null;
        }
        $row['id'] = (string) $existing['id'];
        channel_execute_update($statements['update'], $row, (string) $existing['id']);
        return $row;
    }

    try {
        channel_execute_insert($statements['insert'], $row);
        return $row;
    } catch (PDOException $exception) {
        if (!str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
            throw $exception;
        }

        $existing = channel_find_existing_for_save($statements, $row);
        if (!$existing) {
            throw $exception;
        }

        $row = channel_normalize_for_save($channel, $existing + $fallback, $defaultSourceId);
        if ($row === null) {
            return null;
        }
        $row['id'] = (string) $existing['id'];
        channel_execute_update($statements['update'], $row, (string) $existing['id']);
        return $row;
    }
}

function channel_find_existing_for_save(array $statements, array $row): array
{
    $statements['select_by_key']->execute([
        ':url' => $row['url'],
        ':name' => $row['name'],
    ]);
    $existing = $statements['select_by_key']->fetch();
    if (is_array($existing)) {
        return $existing;
    }

    $statements['select_by_id']->execute([':id' => $row['id']]);
    $existing = $statements['select_by_id']->fetch();
    return is_array($existing) ? $existing : [];
}

function channel_normalize_for_save(array $channel, array $fallback = [], string $defaultSourceId = ''): ?array
{
    $name = clean_text((string) ($channel['name'] ?? $fallback['name'] ?? ''));
    if ($name === '') {
        $name = 'Канал без названия';
    }

    $url = trim((string) ($channel['url'] ?? $fallback['url'] ?? ''));
    if ($url === '') {
        return null;
    }

    $sourceId = trim((string) ($channel['source_id'] ?? ''));
    if ($sourceId === '') {
        $sourceId = trim($defaultSourceId !== '' ? $defaultSourceId : (string) ($fallback['source_id'] ?? ''));
    }
    $id = trim((string) ($channel['id'] ?? ''));
    if ($id === '') {
        $id = trim((string) ($fallback['id'] ?? ''));
    }
    if ($id === '') {
        $id = 'ch_' . substr(sha1($sourceId . '|' . $url . '|' . $name), 0, 20);
    }

    $genre = clean_text((string) ($channel['genre'] ?? $fallback['genre'] ?? ''));
    if ($genre === '') {
        $genre = 'Без группы';
    }

    return [
        'id' => $id,
        'name' => $name,
        'url' => $url,
        'logo' => (string) ($channel['logo'] ?? $fallback['logo'] ?? ''),
        'genre' => $genre,
        'country' => normalize_country_value((string) ($channel['country'] ?? $fallback['country'] ?? '')),
        'city' => clean_text((string) ($channel['city'] ?? $fallback['city'] ?? '')),
        'source_id' => $sourceId,
        'source_title' => clean_text((string) ($channel['source_title'] ?? $fallback['source_title'] ?? 'Плейлист')),
        'tvg_id' => clean_text((string) ($channel['tvg_id'] ?? $fallback['tvg_id'] ?? '')),
        'stream_type' => clean_text((string) ($channel['stream_type'] ?? $fallback['stream_type'] ?? 'stream')),
        'access_type' => channel_access_type((string) ($channel['access_type'] ?? $fallback['access_type'] ?? 'open')),
        'access_notes' => clean_text((string) ($channel['access_notes'] ?? $fallback['access_notes'] ?? '')),
        'stream_headers' => channel_stream_headers_json($channel['stream_headers'] ?? $fallback['stream_headers'] ?? ''),
        'drm_system' => channel_drm_system((string) ($channel['drm_system'] ?? $fallback['drm_system'] ?? '')),
        'license_url' => clean_url((string) ($channel['license_url'] ?? $fallback['license_url'] ?? '')),
        'license_headers' => channel_stream_headers_json($channel['license_headers'] ?? $fallback['license_headers'] ?? ''),
        'health_status' => clean_text((string) ($channel['health_status'] ?? $fallback['health_status'] ?? 'unknown')),
        'last_checked_at' => $channel['last_checked_at'] ?? $fallback['last_checked_at'] ?? null,
        'last_http_status' => $channel['last_http_status'] ?? $fallback['last_http_status'] ?? null,
        'last_probe_error' => $channel['last_probe_error'] ?? $fallback['last_probe_error'] ?? null,
        'working_url' => $channel['working_url'] ?? $fallback['working_url'] ?? null,
        'updated_at' => (string) ($channel['updated_at'] ?? date(DATE_ATOM)),
    ];
}

function channel_execute_insert(PDOStatement $stmt, array $row): void
{
    $stmt->execute(channel_write_params($row));
}

function channel_execute_update(PDOStatement $stmt, array $row, string $targetId): void
{
    $params = channel_write_params($row);
    unset($params[':id']);
    $params[':target_id'] = $targetId;
    $stmt->execute($params);
}

function channel_write_params(array $row): array
{
    return [
        ':id' => (string) $row['id'],
        ':name' => (string) $row['name'],
        ':url' => (string) $row['url'],
        ':logo' => (string) $row['logo'],
        ':genre' => (string) $row['genre'],
        ':country' => (string) $row['country'],
        ':city' => (string) $row['city'],
        ':source_id' => (string) $row['source_id'],
        ':source_title' => (string) $row['source_title'],
        ':tvg_id' => (string) $row['tvg_id'],
        ':stream_type' => (string) $row['stream_type'],
        ':access_type' => (string) $row['access_type'],
        ':access_notes' => (string) $row['access_notes'],
        ':stream_headers' => (string) $row['stream_headers'],
        ':drm_system' => (string) $row['drm_system'],
        ':license_url' => (string) $row['license_url'],
        ':license_headers' => (string) $row['license_headers'],
        ':health_status' => (string) $row['health_status'],
        ':last_checked_at' => $row['last_checked_at'],
        ':last_http_status' => $row['last_http_status'],
        ':last_probe_error' => $row['last_probe_error'],
        ':working_url' => $row['working_url'],
        ':updated_at' => (string) $row['updated_at'],
    ];
}

function channel_access_type(string $value): string
{
    $value = clean_text(text_lower($value));
    return in_array($value, ['open', 'tokenized', 'header_required', 'auth_required', 'geo_blocked', 'drm', 'unsupported'], true)
        ? $value
        : 'open';
}

function channel_stream_headers_json(mixed $value): string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return channel_stream_headers_json($decoded);
        }
        return '';
    }

    if (!is_array($value)) {
        return '';
    }

    $headers = [];
    foreach ($value as $name => $headerValue) {
        $name = trim((string) $name);
        $headerValue = trim((string) $headerValue);
        if ($name === '' || $headerValue === '' || preg_match('/[\r\n:]/', $name)) {
            continue;
        }
        $headers[$name] = $headerValue;
    }

    return $headers ? json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
}

function channel_headers_text_to_json(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return channel_stream_headers_json($decoded);
    }

    $headers = [];
    foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $separator = str_contains($line, ':') ? ':' : (str_contains($line, '=') ? '=' : '');
        if ($separator === '') {
            throw new InvalidArgumentException('Заголовки пиши JSON-объектом или строками Header: value.');
        }

        [$name, $headerValue] = array_map('trim', explode($separator, $line, 2));
        if ($name === '' || $headerValue === '' || preg_match('/[\r\n:]/', $name) || preg_match('/[\r\n]/', $headerValue)) {
            throw new InvalidArgumentException('Некорректный HTTP-заголовок: ' . $line);
        }
        $headers[$name] = $headerValue;
    }

    return channel_stream_headers_json($headers);
}

function channel_headers_json_to_text(string $value): string
{
    $decoded = json_decode(trim($value), true);
    if (!is_array($decoded)) {
        return '';
    }

    $lines = [];
    foreach ($decoded as $name => $headerValue) {
        $name = trim((string) $name);
        $headerValue = trim((string) $headerValue);
        if ($name !== '' && $headerValue !== '') {
            $lines[] = $name . ': ' . $headerValue;
        }
    }

    return implode("\n", $lines);
}

function channel_drm_system(string $value): string
{
    $value = clean_text(text_lower($value));
    return match ($value) {
        'widevine', 'com.widevine.alpha' => 'com.widevine.alpha',
        'playready', 'com.microsoft.playready' => 'com.microsoft.playready',
        'fairplay', 'com.apple.fps', 'com.apple.fps.1_0' => 'com.apple.fps.1_0',
        'clearkey', 'org.w3.clearkey' => 'org.w3.clearkey',
        default => $value,
    };
}

function channel_state_key(string $url, string $name): string
{
    return sha1(text_lower(trim($url)) . '|' . text_lower(trim($name)));
}

function find_channel(string $id): ?array
{
    ensure_app_storage();
    $stmt = db()->prepare('SELECT * FROM channels WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $channel = $stmt->fetch();
    return is_array($channel) ? $channel : null;
}

function update_channel_access_settings(string $id, array $data): array
{
    $channel = find_channel($id);
    if (!$channel) {
        throw new InvalidArgumentException('Канал не найден.');
    }

    $url = trim((string) ($data['url'] ?? $channel['url'] ?? ''));
    if ($url === '' || !is_http_url($url)) {
        throw new InvalidArgumentException('У канала должна быть http:// или https:// ссылка.');
    }

    $streamType = clean_text((string) ($data['stream_type'] ?? $channel['stream_type'] ?? 'stream'));
    if (!in_array($streamType, ['stream', 'audio', 'hls', 'dash', 'udp', 'rtp'], true)) {
        $streamType = infer_stream_type($url);
    }

    $row = [
        'url' => $url,
        'stream_type' => $streamType,
        'access_type' => channel_access_type((string) ($data['access_type'] ?? 'open')),
        'access_notes' => clean_text((string) ($data['access_notes'] ?? '')),
        'stream_headers' => channel_headers_text_to_json((string) ($data['stream_headers'] ?? '')),
        'drm_system' => channel_drm_system((string) ($data['drm_system'] ?? '')),
        'license_url' => clean_url((string) ($data['license_url'] ?? '')),
        'license_headers' => channel_headers_text_to_json((string) ($data['license_headers'] ?? '')),
        'updated_at' => date(DATE_ATOM),
        'id' => $id,
    ];

    $stmt = db()->prepare('
        UPDATE channels
        SET url = :url,
            stream_type = :stream_type,
            access_type = :access_type,
            access_notes = :access_notes,
            stream_headers = :stream_headers,
            drm_system = :drm_system,
            license_url = :license_url,
            license_headers = :license_headers,
            updated_at = :updated_at
        WHERE id = :id
    ');
    $stmt->execute([
        ':url' => $row['url'],
        ':stream_type' => $row['stream_type'],
        ':access_type' => $row['access_type'],
        ':access_notes' => $row['access_notes'],
        ':stream_headers' => $row['stream_headers'],
        ':drm_system' => $row['drm_system'],
        ':license_url' => $row['license_url'],
        ':license_headers' => $row['license_headers'],
        ':updated_at' => $row['updated_at'],
        ':id' => $row['id'],
    ]);

    return find_channel($id) ?: $channel;
}

function load_access_editor_channels(int $limit = 80): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM channels
        WHERE access_type != 'open'
            OR health_status IN ('dead', 'unknown')
            OR stream_headers != ''
            OR license_url != ''
            OR license_headers != ''
            OR last_probe_error != ''
        ORDER BY
            CASE WHEN access_type != 'open' THEN 0 ELSE 1 END,
            CASE health_status WHEN 'dead' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END,
            COALESCE(last_checked_at, '') DESC,
            name COLLATE NOCASE
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function channel_playback_diagnosis(array $channel): array
{
    $items = [];
    $accessType = channel_access_type((string) ($channel['access_type'] ?? 'open'));
    $health = (string) ($channel['health_status'] ?? 'unknown');
    $httpStatus = (int) ($channel['last_http_status'] ?? 0);
    $error = trim((string) ($channel['last_probe_error'] ?? ''));
    $streamHeaders = trim((string) ($channel['stream_headers'] ?? ''));
    $licenseUrl = trim((string) ($channel['license_url'] ?? ''));
    $licenseHeaders = trim((string) ($channel['license_headers'] ?? ''));
    $url = trim((string) ($channel['url'] ?? ''));
    $streamType = (string) ($channel['stream_type'] ?? infer_stream_type($url));

    if ($accessType === 'drm') {
        $items[] = [
            'tone' => $licenseUrl !== '' ? 'info' : 'warning',
            'title' => $licenseUrl !== '' ? 'DRM настроен для Shaka' : 'DRM без license URL',
            'body' => $licenseUrl !== ''
                ? 'Плеер попробует DASH/HLS через Shaka и локальный license proxy.'
                : 'Браузер не сможет декодировать защищённый поток без рабочей license-сессии.',
        ];
    } elseif ($accessType !== 'open') {
        $items[] = [
            'tone' => 'warning',
            'title' => channel_access_label($accessType),
            'body' => (string) ($channel['access_notes'] ?? 'Каналу нужны особые условия доступа.'),
        ];
    }

    if (in_array($httpStatus, [401, 403, 451], true)) {
        $items[] = [
            'tone' => 'warning',
            'title' => 'HTTP ' . $httpStatus,
            'body' => match ($httpStatus) {
                401 => 'Источник требует авторизацию или свежий токен.',
                403 => 'Источник запретил запрос. Часто нужны Referer, Origin, Cookie или свежая подпись.',
                451 => 'Похоже на региональное или юридическое ограничение.',
            },
        ];
    }

    if ($streamHeaders !== '') {
        $items[] = [
            'tone' => 'info',
            'title' => 'Есть stream headers',
            'body' => 'Проверка живости и плеер будут использовать сохранённые HTTP-заголовки там, где браузер разрешает.',
        ];
    }

    if ($licenseUrl !== '' || $licenseHeaders !== '') {
        $items[] = [
            'tone' => 'info',
            'title' => 'Есть license config',
            'body' => 'License-запрос идёт через локальный proxy, чтобы можно было передать нужные headers.',
        ];
    }

    if ($streamType === 'dash' || str_contains(text_lower($url), '.mpd')) {
        $items[] = [
            'tone' => 'info',
            'title' => 'DASH/MPD',
            'body' => 'Такой поток открывается через Shaka Player, обычный HLS-путь к нему не применяется.',
        ];
    }

    if ($streamType === 'audio') {
        $items[] = [
            'tone' => 'info',
            'title' => 'Радио / аудио',
            'body' => 'Это аудиопоток. Плеер покажет компактную радио-карточку вместо пустого видеокадра.',
        ];
    }

    if ($health === 'dead') {
        $items[] = [
            'tone' => 'danger',
            'title' => 'Канал скрыт как dead',
            'body' => $error !== '' ? $error : 'Плеер не будет открывать этот канал, пока проверка не вернёт его в live/unknown.',
        ];
    } elseif ($health === 'unknown') {
        $items[] = [
            'tone' => 'warning',
            'title' => 'Статус unknown',
            'body' => $error !== '' ? $error : 'Проверка не смогла уверенно понять, живой поток или нет.',
        ];
    }

    if (!$items) {
        $items[] = [
            'tone' => 'info',
            'title' => 'Явных проблем нет',
            'body' => 'Канал выглядит как обычный открытый поток. Если не играет, проверь формат, CORS и сам stream URL.',
        ];
    }

    return $items;
}

function channel_quality_label(array $channel): string
{
    $haystack = text_lower((string) ($channel['name'] ?? '') . ' ' . (string) ($channel['url'] ?? '') . ' ' . (string) ($channel['tvg_id'] ?? ''));
    if (str_contains($haystack, '2160') || str_contains($haystack, 'uhd') || str_contains($haystack, '4k')) {
        return '4K';
    }
    if (str_contains($haystack, '1080') || str_contains($haystack, 'fhd')) {
        return 'FHD';
    }
    if (str_contains($haystack, '720') || preg_match('~\bhd\b~i', $haystack)) {
        return 'HD';
    }
    return '';
}

function health_label(string $status): string
{
    return match ($status) {
        'live' => 'live',
        'dead' => 'dead',
        default => 'unknown',
    };
}

function channel_access_label(string $type): string
{
    return match (channel_access_type($type)) {
        'tokenized' => 'токен',
        'header_required' => 'заголовки',
        'auth_required' => 'авторизация',
        'geo_blocked' => 'геоблок',
        'drm' => 'DRM',
        'unsupported' => 'неподдерживаемый',
        default => 'открытый',
    };
}

function adjacent_channel(string $channelId, string $direction = 'next'): ?array
{
    $channels = query_channels([], 10000, 0, false);
    if (!$channels) {
        return null;
    }

    $index = null;
    foreach ($channels as $i => $channel) {
        if ((string) $channel['id'] === $channelId) {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        return $channels[0] ?? null;
    }

    $count = count($channels);
    $step = $direction === 'prev' ? -1 : 1;
    for ($offset = 1; $offset < $count; $offset++) {
        $nextIndex = ($index + ($step * $offset) + $count) % $count;
        if ((string) ($channels[$nextIndex]['health_status'] ?? '') !== 'dead') {
            return $channels[$nextIndex];
        }
    }

    return null;
}

function next_similar_channel(array $current): ?array
{
    $candidates = query_channels([], 600, 0, false);
    $best = null;
    $bestScore = -1;

    foreach ($candidates as $candidate) {
        if ((string) $candidate['id'] === (string) $current['id']) {
            continue;
        }

        $score = channel_similarity_score($current, $candidate);
        if ($score > $bestScore) {
            $best = $candidate;
            $bestScore = $score;
        }
    }

    return $best;
}

function channel_similarity_score(array $a, array $b): float
{
    $score = 0.0;
    $aTvg = trim(text_lower((string) ($a['tvg_id'] ?? '')));
    $bTvg = trim(text_lower((string) ($b['tvg_id'] ?? '')));
    if ($aTvg !== '' && $aTvg === $bTvg) {
        $score += 70;
    }

    $aTokens = channel_name_tokens((string) ($a['name'] ?? ''));
    $bTokens = channel_name_tokens((string) ($b['name'] ?? ''));
    if ($aTokens && $bTokens) {
        $matches = count(array_intersect($aTokens, $bTokens));
        $score += ($matches / max(1, count(array_unique(array_merge($aTokens, $bTokens))))) * 45;
    }

    foreach (['genre' => 12, 'country' => 10, 'city' => 6] as $key => $weight) {
        $left = trim(text_lower((string) ($a[$key] ?? '')));
        $right = trim(text_lower((string) ($b[$key] ?? '')));
        if ($left !== '' && $left === $right) {
            $score += $weight;
        }
    }

    if (channel_quality_label($a) !== '' && channel_quality_label($a) === channel_quality_label($b)) {
        $score += 6;
    }

    if (!empty($b['logo'])) {
        $score += 2;
    }

    if (($b['health_status'] ?? '') === 'live') {
        $score += 8;
    }

    return $score;
}

function channel_name_tokens(string $name): array
{
    $name = text_lower($name);
    $name = preg_replace('~\[[^\]]+\]|\([^\)]+\)~u', ' ', $name) ?? $name;
    $name = preg_replace('~\b(?:hd|fhd|uhd|sd|1080p|720p|576p|480p|live|tv|channel|канал|эфир)\b~u', ' ', $name) ?? $name;
    $name = preg_replace('~[^\pL\pN]+~u', ' ', $name) ?? $name;
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        $length = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
        if ($length >= 2) {
            $tokens[$part] = true;
        }
    }

    return array_keys($tokens);
}

function load_channel_alternatives(array $channel, int $limit = 8): array
{
    $rows = query_channels([], 1200, 0, false);
    $scored = [];
    foreach ($rows as $row) {
        if ((string) $row['id'] === (string) $channel['id']) {
            continue;
        }
        $score = channel_similarity_score($channel, $row);
        if ($score < 10) {
            continue;
        }
        $row['similarity_score'] = $score;
        $scored[] = $row;
    }

    usort($scored, static fn (array $a, array $b): int => ($b['similarity_score'] <=> $a['similarity_score']) ?: strcmp((string) $a['name'], (string) $b['name']));
    return array_slice($scored, 0, $limit);
}

function load_problem_channels(int $limit = 60): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM channels
        WHERE health_status IN ('dead', 'unknown')
        ORDER BY CASE health_status WHEN 'dead' THEN 0 ELSE 1 END, COALESCE(last_checked_at, '') DESC, name COLLATE NOCASE
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function unique_values(array $items, string $key): array
{
    $values = [];
    foreach ($items as $item) {
        $value = trim((string) ($item[$key] ?? ''));
        if ($value !== '') {
            $values[$value] = true;
        }
    }

    $values = array_keys($values);
    natcasesort($values);
    return array_values($values);
}
