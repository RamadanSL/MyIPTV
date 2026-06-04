<?php
declare(strict_types=1);

require_once __DIR__ . '/../m3u.php';

function add_discovery_source(string $title, string $url, string $country = '', string $city = '', bool $autoImport = false): array
{
    $url = trim($url);
    if (!is_http_url($url)) {
        throw new InvalidArgumentException('Нужна ссылка http:// или https:// на страницу или M3U.');
    }

    $title = trim($title);
    if ($title === '') {
        $title = parse_url($url, PHP_URL_HOST) ?: 'Источник поиска';
    }

    $source = [
        'id' => 'ds_' . substr(sha1($url . microtime(true)), 0, 16),
        'title' => $title,
        'url' => $url,
        'mode' => 'page',
        'auto_import' => $autoImport ? 1 : 0,
        'default_country' => trim($country),
        'default_city' => trim($city),
        'created_at' => date(DATE_ATOM),
        'updated_at' => null,
        'last_error' => null,
        'candidate_count' => 0,
    ];

    $stmt = db()->prepare('
        INSERT INTO discovery_sources (
            id, title, url, mode, auto_import, default_country, default_city,
            created_at, updated_at, last_error, candidate_count
        ) VALUES (
            :id, :title, :url, :mode, :auto_import, :default_country, :default_city,
            :created_at, :updated_at, :last_error, :candidate_count
        )
    ');
    $stmt->execute([
        ':id' => $source['id'],
        ':title' => $source['title'],
        ':url' => $source['url'],
        ':mode' => $source['mode'],
        ':auto_import' => $source['auto_import'],
        ':default_country' => $source['default_country'],
        ':default_city' => $source['default_city'],
        ':created_at' => $source['created_at'],
        ':updated_at' => $source['updated_at'],
        ':last_error' => $source['last_error'],
        ':candidate_count' => $source['candidate_count'],
    ]);

    return $source;
}

function delete_discovery_source(string $id): void
{
    $stmt = db()->prepare('DELETE FROM discovery_sources WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function purge_non_russian_discovery_sources(): array
{
    $summary = [
        'checked' => 0,
        'removed' => 0,
        'kept' => 0,
    ];

    foreach (load_discovery_sources() as $source) {
        $summary['checked']++;
        if (source_record_is_russian_relevant($source)) {
            $summary['kept']++;
            continue;
        }

        delete_discovery_source((string) ($source['id'] ?? ''));
        $summary['removed']++;
    }

    return $summary;
}

function load_discovery_sources(int $limit = 0): array
{
    $sql = 'SELECT * FROM discovery_sources ORDER BY created_at DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    return db()->query($sql)->fetchAll();
}

function load_discovery_candidates(?string $status = null, int $limit = 0): array
{
    if ($status === null || $status === '') {
        $sql = '
            SELECT c.*, s.title AS source_title
            FROM discovery_candidates c
            JOIN discovery_sources s ON s.id = c.source_id
            ORDER BY c.last_seen_at DESC
        ';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        return db()->query($sql)->fetchAll();
    }

    $sql = '
        SELECT c.*, s.title AS source_title
        FROM discovery_candidates c
        JOIN discovery_sources s ON s.id = c.source_id
        WHERE c.status = :status
        ORDER BY c.last_seen_at DESC
    ';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([':status' => $status]);
    return $stmt->fetchAll();
}

function scan_all_discovery_sources(): array
{
    purge_non_russian_discovery_sources();

    $summary = [
        'source_count' => 0,
        'candidate_count' => 0,
        'imported_count' => 0,
        'renamed_count' => 0,
        'errors' => [],
    ];

    foreach (load_discovery_sources() as $source) {
        $summary['source_count']++;
        try {
            $result = scan_discovery_source((string) $source['id']);
            $summary['candidate_count'] += $result['candidate_count'];
            $summary['imported_count'] += $result['imported_count'] ?? 0;
            $summary['renamed_count'] += $result['renamed_count'] ?? 0;
        } catch (Throwable $exception) {
            $summary['errors'][] = [
                'source' => (string) ($source['title'] ?? $source['id']),
                'error' => $exception->getMessage(),
            ];
        }
    }

    return $summary;
}

function scan_discovery_source(string $id): array
{
    $source = find_discovery_source($id);
    if (!$source) {
        throw new InvalidArgumentException('Источник поиска не найден.');
    }

    try {
        $content = fetch_url((string) $source['url']);
        $candidates = extract_playlist_candidates($content, (string) $source['url'], (string) $source['title']);
        save_discovery_candidates((string) $source['id'], $candidates);
        update_discovery_source($id, count($candidates), null);
        $renamedCount = repair_imported_candidate_names($id);
        $importedCount = !empty($source['auto_import']) ? import_new_discovery_candidates($id) : 0;
        if ($importedCount > 0 || $renamedCount > 0) {
            refresh_all_playlists();
        }

        return [
            'source_id' => $id,
            'candidate_count' => count($candidates),
            'imported_count' => $importedCount,
            'renamed_count' => $renamedCount,
        ];
    } catch (Throwable $exception) {
        update_discovery_source($id, 0, $exception->getMessage());
        throw $exception;
    }
}

function import_discovery_candidate(string $candidateId): array
{
    $candidate = find_discovery_candidate($candidateId);
    if (!$candidate) {
        throw new InvalidArgumentException('Кандидат не найден.');
    }

    $source = find_discovery_source((string) $candidate['source_id']);
    $title = (string) ($candidate['title'] ?: $candidate['url']);
    $country = (string) ($source['default_country'] ?? '');
    $city = (string) ($source['default_city'] ?? '');

    if (($candidate['kind'] ?? '') === 'playlist') {
        $playlist = add_url_playlist($title, (string) $candidate['url'], $country, $city);
    } else {
        $group = clean_text((string) ($source['title'] ?? 'Автоимпорт'));
        $content = "#EXTM3U\n"
            . '#EXTINF:-1 group-title="' . str_replace('"', '', $group) . '",' . $title . "\n"
            . (string) $candidate['url'] . "\n";
        $playlist = add_text_playlist($title, $content, $country, $city);
    }

    $stmt = db()->prepare('
        UPDATE discovery_candidates
        SET status = :status, imported_playlist_id = :playlist_id, note = :note
        WHERE id = :id
    ');
    $stmt->execute([
        ':status' => 'imported',
        ':playlist_id' => $playlist['id'],
        ':note' => 'Импортировано в плейлисты',
        ':id' => $candidateId,
    ]);

    return $playlist;
}

function import_new_discovery_candidates(string $sourceId): int
{
    $stmt = db()->prepare('
        SELECT id
        FROM discovery_candidates
        WHERE source_id = :source_id AND status = :status
        ORDER BY first_seen_at ASC
    ');
    $stmt->execute([':source_id' => $sourceId, ':status' => 'new']);

    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        import_discovery_candidate((string) $row['id']);
        $count++;
    }

    return $count;
}

function repair_imported_candidate_names(?string $sourceId = null): int
{
    $sql = '
        SELECT c.*, s.title AS discovery_title
        FROM discovery_candidates c
        JOIN discovery_sources s ON s.id = c.source_id
        WHERE c.imported_playlist_id IS NOT NULL
    ';
    $params = [];
    if ($sourceId !== null) {
        $sql .= ' AND c.source_id = :source_id';
        $params[':source_id'] = $sourceId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $renamed = 0;
    foreach ($stmt->fetchAll() as $candidate) {
        $title = clean_candidate_label((string) ($candidate['title'] ?? ''));
        if ($title === '' || is_generic_candidate_title($title)) {
            continue;
        }

        $playlistId = (string) ($candidate['imported_playlist_id'] ?? '');
        if ($playlistId === '') {
            continue;
        }

        $playlist = find_playlist($playlistId);
        if (!$playlist) {
            continue;
        }

        $playlistTitle = $title;
        db()->prepare('UPDATE playlists SET title = :title WHERE id = :id')->execute([
            ':title' => $playlistTitle,
            ':id' => $playlistId,
        ]);

        if (($candidate['kind'] ?? '') === 'playlist') {
            db()->prepare('UPDATE channels SET source_title = :source_title WHERE source_id = :source_id')->execute([
                ':source_title' => $playlistTitle,
                ':source_id' => $playlistId,
            ]);
            $renamed++;
            continue;
        }

        rewrite_single_stream_playlist($playlist, $title, (string) $candidate['url'], (string) ($candidate['discovery_title'] ?? 'Автоимпорт'));
        db()->prepare('
            UPDATE channels
            SET name = :name, source_title = :source_title
            WHERE source_id = :source_id
        ')->execute([
            ':name' => $title,
            ':source_title' => $playlistTitle,
            ':source_id' => $playlistId,
        ]);
        $renamed++;
    }

    return $renamed;
}

function ignore_discovery_candidate(string $candidateId): void
{
    $stmt = db()->prepare('UPDATE discovery_candidates SET status = :status WHERE id = :id');
    $stmt->execute([':status' => 'ignored', ':id' => $candidateId]);
}

function find_discovery_source(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM discovery_sources WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $source = $stmt->fetch();
    return is_array($source) ? $source : null;
}

function find_discovery_candidate(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM discovery_candidates WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $candidate = $stmt->fetch();
    return is_array($candidate) ? $candidate : null;
}

function find_playlist(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM playlists WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $playlist = $stmt->fetch();
    return is_array($playlist) ? $playlist : null;
}

function rewrite_single_stream_playlist(array $playlist, string $title, string $url, string $group): void
{
    if (($playlist['type'] ?? '') !== 'file') {
        return;
    }

    $path = realpath(DATA_DIR . '/' . (string) ($playlist['source'] ?? ''));
    $uploadRoot = realpath(UPLOAD_DIR);
    if (!$path || !$uploadRoot || !str_starts_with($path, $uploadRoot)) {
        return;
    }

    $safeTitle = str_replace('"', '', $title);
    $safeGroup = str_replace('"', '', clean_text($group));
    $content = "#EXTM3U\n"
        . '#EXTINF:-1 group-title="' . $safeGroup . '",' . $safeTitle . "\n"
        . $url . "\n";
    file_put_contents($path, $content, LOCK_EX);
}

function extract_playlist_candidates(string $content, string $baseUrl, string $sourceTitle): array
{
    $found = [];

    if (str_starts_with(ltrim($content), '#EXTM3U') && is_candidate_url($baseUrl)) {
        add_candidate_url($found, $baseUrl, $sourceTitle, $sourceTitle, 100);
    }

    if (preg_match_all('~<a\b([^>]*)>(.*?)</a>~isu', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $attrs = parse_html_attributes($match[1][0]);
            $href = $attrs['href'] ?? $attrs['data-href'] ?? '';
            $resolved = resolve_url($href, $baseUrl);
            if ($resolved === '' || !is_candidate_url($resolved)) {
                continue;
            }

            $label = best_candidate_label([
                $attrs['title'] ?? '',
                $attrs['aria-label'] ?? '',
                html_text($match[2][0]),
                surrounding_candidate_label($content, $match[0][1], $resolved),
            ]);
            add_candidate_url($found, $resolved, $label, $sourceTitle, 90);
        }
    }

    if (preg_match_all('~(?:href|src|data-src|data-href)=["\']([^"\']+)["\']~iu', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $resolved = resolve_url($match[1][0], $baseUrl);
            if ($resolved !== '' && is_candidate_url($resolved)) {
                add_candidate_url($found, $resolved, surrounding_candidate_label($content, $match[0][1], $resolved), $sourceTitle, 70);
            }
        }
    }

    if (preg_match_all('~https?://[^\s"\'<>]+~iu', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $url = $match[0][0];
            $clean = trim($url, " \t\n\r\0\x0B\"'()[]{}<>,");
            if (is_candidate_url($clean)) {
                $label = surrounding_candidate_label($content, $match[0][1], $clean);
                add_candidate_url($found, $clean, $label, $sourceTitle, $label !== '' ? 55 : 10);
            }
        }
    }

    $candidates = [];
    foreach ($found as $url => $item) {
        $candidates[] = [
            'id' => 'dc_' . substr(sha1($baseUrl . '|' . $url), 0, 20),
            'title' => candidate_title($url, $sourceTitle, (string) ($item['title'] ?? '')),
            'url' => $url,
            'kind' => candidate_kind($url),
        ];
    }

    usort($candidates, static fn (array $a, array $b): int => [$a['kind'], $a['title']] <=> [$b['kind'], $b['title']]);
    return $candidates;
}

function add_candidate_url(array &$found, string $url, string $titleHint, string $sourceTitle, int $score): void
{
    $title = candidate_title($url, $sourceTitle, $titleHint);
    if (!isset($found[$url]) || $score > (int) ($found[$url]['score'] ?? 0)) {
        $found[$url] = [
            'title' => $title,
            'score' => $score,
        ];
    }
}

function parse_html_attributes(string $html): array
{
    $attrs = [];
    if (preg_match_all('~([a-z0-9_-]+)\s*=\s*(["\'])(.*?)\2~isu', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $attrs[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return $attrs;
}

function html_text(string $html): string
{
    $html = preg_replace('~<\s*br\s*/?\s*>~iu', ' ', $html) ?? $html;
    $text = strip_tags($html);
    return clean_candidate_label(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function surrounding_candidate_label(string $content, int $offset, string $url): string
{
    foreach ([
        ['~<tr\b[^>]*>~iu', '~</tr>~iu'],
        ['~<li\b[^>]*>~iu', '~</li>~iu'],
        ['~<article\b[^>]*>~iu', '~</article>~iu'],
        ['~<div\b[^>]*>~iu', '~</div>~iu'],
    ] as [$openPattern, $closePattern]) {
        $block = enclosing_html_block($content, $offset, $openPattern, $closePattern);
        if ($block !== '') {
            $label = clean_candidate_label(str_replace($url, ' ', html_text($block)));
            if ($label !== '' && !is_generic_candidate_title($label)) {
                return $label;
            }
        }
    }

    $lineStart = strrpos(substr($content, 0, $offset), "\n");
    $lineEnd = strpos($content, "\n", $offset);
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = $lineEnd === false ? strlen($content) : $lineEnd;
    $line = substr($content, $lineStart, $lineEnd - $lineStart);

    return clean_candidate_label(str_replace($url, ' ', html_text($line)));
}

function enclosing_html_block(string $content, int $offset, string $openPattern, string $closePattern): string
{
    $before = substr($content, 0, $offset);
    if (!preg_match_all($openPattern, $before, $opens, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $start = end($opens[0]);
    if (!is_array($start)) {
        return '';
    }

    $after = substr($content, $offset);
    if (!preg_match($closePattern, $after, $close, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $startOffset = (int) $start[1];
    $endOffset = $offset + (int) $close[0][1] + strlen($close[0][0]);
    if ($endOffset <= $startOffset || $endOffset - $startOffset > 5000) {
        return '';
    }

    return substr($content, $startOffset, $endOffset - $startOffset);
}

function best_candidate_label(array $labels): string
{
    foreach ($labels as $label) {
        $clean = clean_candidate_label((string) $label);
        if ($clean !== '' && !is_generic_candidate_title($clean)) {
            return $clean;
        }
    }

    return '';
}

function save_discovery_candidates(string $sourceId, array $candidates): void
{
    $now = date(DATE_ATOM);
    $stmt = db()->prepare('
        INSERT INTO discovery_candidates (
            id, source_id, title, url, kind, status, first_seen_at, last_seen_at
        ) VALUES (
            :id, :source_id, :title, :url, :kind, :status, :first_seen_at, :last_seen_at
        )
        ON CONFLICT(source_id, url) DO UPDATE SET
            title = excluded.title,
            kind = excluded.kind,
            last_seen_at = excluded.last_seen_at
    ');

    foreach ($candidates as $candidate) {
        $stmt->execute([
            ':id' => $candidate['id'],
            ':source_id' => $sourceId,
            ':title' => $candidate['title'],
            ':url' => $candidate['url'],
            ':kind' => $candidate['kind'],
            ':status' => 'new',
            ':first_seen_at' => $now,
            ':last_seen_at' => $now,
        ]);
    }
}

function update_discovery_source(string $id, int $count, ?string $error): void
{
    $stmt = db()->prepare('
        UPDATE discovery_sources
        SET updated_at = :updated_at, last_error = :last_error, candidate_count = :candidate_count
        WHERE id = :id
    ');
    $stmt->execute([
        ':updated_at' => date(DATE_ATOM),
        ':last_error' => $error,
        ':candidate_count' => $count,
        ':id' => $id,
    ]);
}

function is_candidate_url(string $url): bool
{
    if (!is_http_url($url)) {
        return false;
    }

    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    $query = strtolower((string) (parse_url($url, PHP_URL_QUERY) ?: ''));
    $full = strtolower($url);

    return (bool) preg_match('~\.(m3u8?)(?:$|[?#])~i', $full)
        || (bool) preg_match('~(?:^|[?&;])(?:type|format|output|file|ext)=m3u8?(?:$|[&;])~i', $query)
        || (bool) preg_match('~(?:^|[?&;])(?:url|src|stream)=[^&;]*\.m3u8?(?:$|[&;])~i', $query)
        || (bool) preg_match('~\.(m3u8?)$~i', $path);
}

function candidate_kind(string $url): string
{
    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    $query = strtolower((string) (parse_url($url, PHP_URL_QUERY) ?: ''));
    $full = strtolower($url);

    if (str_ends_with($path, '.m3u')
        || preg_match('~(?:^|[?&;])(?:type|format|output|file|ext)=m3u(?:$|[&;])~i', $query)
        || preg_match('~(?:^|[?&;])(?:url|src|stream)=[^&;]*\.m3u(?:$|[&;])~i', $query)
    ) {
        return 'playlist';
    }
    if (str_ends_with($path, '.m3u8')
        || preg_match('~\.(m3u8)(?:$|[?#])~i', $full)
        || preg_match('~(?:^|[?&;])(?:type|format|output|file|ext)=m3u8(?:$|[&;])~i', $query)
        || preg_match('~(?:^|[?&;])(?:url|src|stream)=[^&;]*\.m3u8(?:$|[&;])~i', $query)
    ) {
        return 'hls';
    }
    return 'stream';
}

function candidate_title(string $url, string $sourceTitle, string $hint = ''): string
{
    $hint = clean_candidate_label($hint);
    if ($hint !== '' && !is_generic_candidate_title($hint)) {
        return $hint;
    }

    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $name = pathinfo($path, PATHINFO_FILENAME);
    $name = clean_candidate_label(str_replace(['_', '-', '.'], ' ', $name));
    return ($name !== '' && !is_generic_candidate_title($name)) ? $name : $sourceTitle;
}

function clean_candidate_label(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('~https?://\S+~iu', ' ', $value) ?? $value;
    $value = preg_replace('~\b[\w.-]+\.(?:m3u8?|ts|mpd)\b~iu', ' ', $value) ?? $value;
    $value = strip_tags($value);
    $value = str_replace(['_', '|', "\t"], ' ', $value);
    $value = preg_replace('~\b(?:m3u8?|playlist|download|скачать|смотреть|онлайн|online|url|link|stream|live|source|файл|index|master)\b~iu', ' ', $value) ?? $value;
    $value = preg_replace('~\s+~u', ' ', $value) ?? $value;
    $value = trim($value, " \t\n\r\0\x0B-–—:;,.()[]{}\"'");

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > 96) {
            $value = trim(mb_substr($value, 0, 96, 'UTF-8'));
        }
    } elseif (strlen($value) > 96) {
        $value = trim(substr($value, 0, 96));
    }

    return $value;
}

function is_generic_candidate_title(string $title): bool
{
    $title = text_lower(clean_text($title));
    $title = trim($title, " \t\n\r\0\x0B-–—:;,.()[]{}\"'");
    if ($title === '') {
        return true;
    }

    $generic = [
        'index',
        'master',
        'playlist',
        'stream',
        'live',
        'main',
        'default',
        'video',
        'channel',
        'канал',
    ];

    return in_array($title, $generic, true) || preg_match('~^(index|master|playlist|stream|live)\s*\d*$~u', $title) === 1;
}

function resolve_url(string $url, string $baseUrl): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || str_starts_with($url, 'javascript:') || str_starts_with($url, '#')) {
        return '';
    }

    if (is_http_url($url)) {
        return $url;
    }

    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return '';
    }

    if (str_starts_with($url, '//')) {
        return $base['scheme'] . ':' . $url;
    }

    $root = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    if (str_starts_with($url, '/')) {
        return $root . $url;
    }

    $dir = isset($base['path']) ? preg_replace('~/[^/]*$~', '/', $base['path']) : '/';
    if (!is_string($dir) || $dir === '') {
        $dir = '/';
    }

    return normalize_url_path($root . $dir . $url);
}

function normalize_url_path(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $path = $parts['path'] ?? '/';
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    $normalized = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $normalized .= ':' . $parts['port'];
    }
    $normalized .= '/' . implode('/', $segments);
    if (isset($parts['query'])) {
        $normalized .= '?' . $parts['query'];
    }

    return $normalized;
}

