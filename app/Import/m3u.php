<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

const MAX_PLAYLIST_BYTES = 31457280;
const IMPORT_PROBE_BYTES = 1048576;

function add_url_playlist(string $title, string $url, string $country = '', string $city = ''): array
{
    $title = trim($title);
    $url = trim($url);
    $country = normalize_country_value($country);
    $city = trim($city);

    $title = normalize_playlist_title($title, $url, 'url');

    if (!is_http_url($url)) {
        throw new InvalidArgumentException('Нужна ссылка http:// или https:// на M3U/M3U8-плейлист.');
    }

    $playlist = [
        'id' => make_playlist_id($url . microtime(true)),
        'title' => $title,
        'type' => 'url',
        'source' => $url,
        'default_country' => $country,
        'default_city' => $city,
        'created_at' => date(DATE_ATOM),
        'updated_at' => null,
        'last_error' => null,
        'channel_count' => 0,
    ];

    insert_playlist($playlist);

    return $playlist;
}

function add_text_playlist(string $title, string $content, string $country = '', string $city = ''): array
{
    $title = normalize_playlist_title($title, '', 'file');
    $country = normalize_country_value($country);
    $city = trim($city);
    $content = trim($content);

    if ($content === '') {
        throw new InvalidArgumentException('Плейлист пустой.');
    }

    $id = make_playlist_id($title . microtime(true));
    $path = UPLOAD_DIR . '/' . $id . '.m3u';
    $result = file_put_contents($path, $content . PHP_EOL, LOCK_EX);
    if ($result === false) {
        throw new RuntimeException('Не удалось сохранить локальный плейлист.');
    }

    $playlist = [
        'id' => $id,
        'title' => $title,
        'type' => 'file',
        'source' => 'uploads/' . $id . '.m3u',
        'default_country' => $country,
        'default_city' => $city,
        'created_at' => date(DATE_ATOM),
        'updated_at' => null,
        'last_error' => null,
        'channel_count' => 0,
    ];

    insert_playlist($playlist);

    return $playlist;
}

function insert_playlist(array $playlist): void
{
    $stmt = db()->prepare('
        INSERT OR IGNORE INTO playlists (
            id, title, type, source, default_country, default_city,
            created_at, updated_at, last_error, channel_count
        ) VALUES (
            :id, :title, :type, :source, :default_country, :default_city,
            :created_at, :updated_at, :last_error, :channel_count
        )
    ');
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

function delete_playlist(string $id): void
{
    $remaining = [];
    foreach (load_playlists() as $playlist) {
        if (($playlist['id'] ?? '') === $id) {
            if (($playlist['type'] ?? '') === 'file') {
                $path = realpath(DATA_DIR . '/' . ($playlist['source'] ?? ''));
                $uploadRoot = realpath(UPLOAD_DIR);
                if ($path && $uploadRoot && str_starts_with($path, $uploadRoot) && is_file($path)) {
                    unlink($path);
                }
            }
            continue;
        }
        $remaining[] = $playlist;
    }

    save_playlists($remaining);

    $channels = array_values(array_filter(load_channels(true), static function (array $channel) use ($id): bool {
        return ($channel['source_id'] ?? '') !== $id;
    }));
    save_channels($channels);
}

function purge_non_russian_playlist_sources(): array
{
    $summary = [
        'checked' => 0,
        'removed' => 0,
        'kept' => 0,
    ];
    $kept = [];
    $removedIds = [];

    foreach (load_playlists() as $playlist) {
        $summary['checked']++;
        if (source_record_is_russian_relevant($playlist)) {
            $playlist['title'] = russian_source_title((string) ($playlist['source'] ?? ''), (string) ($playlist['title'] ?? ''));
            $kept[] = $playlist;
            $summary['kept']++;
            continue;
        }

        $id = (string) ($playlist['id'] ?? '');
        if ($id !== '') {
            $removedIds[$id] = true;
        }
        if (($playlist['type'] ?? '') === 'file') {
            $path = realpath(DATA_DIR . '/' . ($playlist['source'] ?? ''));
            $uploadRoot = realpath(UPLOAD_DIR);
            if ($path && $uploadRoot && str_starts_with($path, $uploadRoot) && is_file($path)) {
                unlink($path);
            }
        }
        $summary['removed']++;
    }

    if ($removedIds || $summary['kept'] > 0) {
        save_playlists($kept);
    }

    if ($removedIds) {
        $channels = array_values(array_filter(load_channels(true), static function (array $channel) use ($removedIds): bool {
            return !isset($removedIds[(string) ($channel['source_id'] ?? '')]);
        }));
        save_channels($channels);
    }

    return $summary;
}

function refresh_all_playlists(?callable $progress = null): array
{
    $playlists = load_playlists();
    $total = count($playlists);
    $summary = [
        'playlist_count' => $total,
        'channel_count' => 0,
        'errors' => [],
    ];

    foreach ($playlists as $index => $playlist) {
        $title = (string) ($playlist['title'] ?? $playlist['source'] ?? $playlist['id'] ?? 'Плейлист');
        if ($progress) {
            $progress($index + 1, $total, 'Скачиваю: ' . $title);
        }

        try {
            $channelCount = refresh_playlist_channels($playlist);
            if ($progress) {
                $progress($index + 1, $total, 'Добавлено из источника: ' . $title . ' — ' . $channelCount);
            }
        } catch (Throwable $exception) {
            $summary['errors'][] = [
                'playlist' => $playlist['title'] ?? $playlist['id'] ?? 'playlist',
                'error' => $exception->getMessage(),
            ];
            if ($progress) {
                $progress($index + 1, $total, 'Ошибка источника: ' . $title . ' — ' . $exception->getMessage());
            }
        }
    }

    $summary['channel_count'] = channel_count(true);
    $summary['wanted'] = wanted_channels_summary();

    return $summary;
}

function refresh_playlist_channels(array $playlist, bool $requireChannels = false): int
{
    $playlistId = (string) ($playlist['id'] ?? '');

    try {
        $content = read_playlist_content($playlist);
        $channels = dedupe_channels(parse_m3u_playlist($content, $playlist));
        if ($requireChannels && count($channels) === 0) {
            throw new RuntimeException('Плейлист скачался, но каналы не найдены. Проверь, что ссылка ведет прямо на M3U/M3U8, а не на страницу сайта.');
        }

        save_channels_for_source($playlistId, $channels);
        update_playlist_status($playlistId, count($channels), null);
        return count($channels);
    } catch (Throwable $exception) {
        update_playlist_status($playlistId, (int) ($playlist['channel_count'] ?? 0), $exception->getMessage());
        throw $exception;
    }
}

function inspect_import_url(string $url): array
{
    $url = trim($url);
    if (!is_http_url($url)) {
        throw new InvalidArgumentException('Нужна ссылка http:// или https://.');
    }

    $probe = fetch_url_probe($url, IMPORT_PROBE_BYTES);
    $kind = classify_import_probe(
        (string) ($probe['effective_url'] ?: $url),
        (string) ($probe['content_type'] ?? ''),
        (string) ($probe['body'] ?? '')
    );

    return $kind + $probe;
}

function fetch_url_probe(string $url, int $maxBytes = IMPORT_PROBE_BYTES): array
{
    if (!is_http_url($url)) {
        throw new RuntimeException('Недопустимая ссылка.');
    }

    if (!function_exists('curl_init')) {
        $content = file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'header' => "User-Agent: MyIPTV/1.0 smart intake\r\nAccept: application/vnd.apple.mpegurl, application/x-mpegURL, text/html, audio/*, video/*, */*\r\n",
            ],
        ]), 0, $maxBytes + 1);

        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Не удалось прочитать ссылку.');
        }

        return [
            'status' => 200,
            'content_type' => '',
            'effective_url' => $url,
            'body' => substr($content, 0, $maxBytes),
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 14,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_USERAGENT => 'MyIPTV/1.0 smart intake',
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.apple.mpegurl, application/x-mpegURL, text/html, audio/*, video/*, */*',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        CURLOPT_RANGE => '0-' . $maxBytes,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $effectiveUrl = trim((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Ссылка не ответила: ' . ($error ?: 'пустой ответ'));
    }
    if ($status >= 400) {
        throw new RuntimeException('Ссылка вернула HTTP ' . $status . '.');
    }

    return [
        'status' => $status,
        'content_type' => trim(explode(';', $contentType)[0]),
        'effective_url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        'body' => substr($body, 0, $maxBytes),
    ];
}

function classify_import_probe(string $url, string $contentType, string $body): array
{
    $bodyStart = ltrim(normalize_playlist_encoding(substr($body, 0, 65536)));
    $path = text_lower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    $contentType = text_lower($contentType);

    if (!str_starts_with($bodyStart, '#EXTM3U') && import_probe_looks_like_html($contentType, $bodyStart)) {
        return [
            'kind' => 'page',
            'label' => 'Веб-страница',
            'message' => 'Это страница сайта, а не прямой плейлист. Её можно просканировать как источник поиска ссылок.',
        ];
    }

    if (import_probe_looks_like_m3u($url, $contentType, $bodyStart)) {
        if (import_probe_looks_like_single_hls($bodyStart)) {
            return [
                'kind' => 'stream',
                'label' => 'Одиночный HLS-поток',
                'message' => 'Это не список каналов, а один HLS-поток. Его можно добавить как один канал.',
            ];
        }

        return [
            'kind' => 'playlist',
            'label' => 'M3U-плейлист',
            'message' => 'Это прямой M3U/M3U8-плейлист. Читаю его как список каналов.',
        ];
    }

    if (str_ends_with($path, '.mpd')) {
        return [
            'kind' => 'stream',
            'label' => 'DASH-поток',
            'message' => 'Это одиночный DASH-поток. Если внутри есть DRM, приложение пометит его отдельно.',
        ];
    }

    if (preg_match('~\.(?:mp3|aac|m4a|flac|wav|oga|ogg|opus|mp4|webm|mkv|avi|mov|m4v|ts)(?:$|[?#])~i', $path) || str_starts_with($contentType, 'video/') || str_starts_with($contentType, 'audio/')) {
        return [
            'kind' => 'stream',
            'label' => str_starts_with($contentType, 'audio/') || preg_match('~\.(?:mp3|aac|m4a|flac|wav|oga|ogg|opus)(?:$|[?#])~i', $path) ? 'Одиночный аудиопоток' : 'Одиночный поток',
            'message' => str_starts_with($contentType, 'audio/') || preg_match('~\.(?:mp3|aac|m4a|flac|wav|oga|ogg|opus)(?:$|[?#])~i', $path)
                ? 'Это аудиопоток, а не список каналов. Добавляю как одну радиостанцию.'
                : 'Это медиа-поток, а не плейлист. Добавляю как один канал.',
        ];
    }

    return [
        'kind' => 'unknown',
        'label' => 'Неизвестная ссылка',
        'message' => 'Ссылка открылась, но это не похоже ни на M3U-плейлист, ни на HLS/DASH-поток, ни на страницу со ссылками.',
    ];
}

function import_probe_looks_like_m3u(string $url, string $contentType, string $bodyStart): bool
{
    $path = text_lower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    return str_starts_with($bodyStart, '#EXTM3U')
        || str_contains($contentType, 'mpegurl')
        || str_ends_with($path, '.m3u')
        || str_ends_with($path, '.m3u8');
}

function import_probe_looks_like_single_hls(string $bodyStart): bool
{
    if (!str_starts_with($bodyStart, '#EXTM3U')) {
        return false;
    }

    $lower = text_lower($bodyStart);
    if (str_contains($lower, '#ext-x-stream-inf') || str_contains($lower, '#ext-x-targetduration') || str_contains($lower, '#ext-x-media-sequence')) {
        return true;
    }

    return str_contains($lower, '#ext-x-key') || str_contains($lower, '#ext-x-session-key');
}

function import_probe_looks_like_html(string $contentType, string $bodyStart): bool
{
    $lower = text_lower(substr($bodyStart, 0, 4096));
    return str_contains($contentType, 'html')
        || str_starts_with($lower, '<!doctype html')
        || str_starts_with($lower, '<html')
        || str_contains($lower, '<body')
        || str_contains($lower, '<a ');
}

function single_stream_playlist_content(string $title, string $url, string $group = 'Ручные потоки'): string
{
    $title = clean_text($title);
    if ($title === '') {
        $title = infer_name_from_url($url);
    }

    $group = str_replace('"', '', clean_text($group) ?: 'Ручные потоки');
    return "#EXTM3U\n"
        . '#EXTINF:-1 group-title="' . $group . '",' . str_replace(["\r", "\n"], ' ', $title) . "\n"
        . $url . "\n";
}

function update_playlist_status(string $playlistId, int $channelCount, ?string $error): void
{
    if ($playlistId === '') {
        return;
    }

    $stmt = db()->prepare('
        UPDATE playlists
        SET updated_at = :updated_at,
            last_error = :last_error,
            channel_count = :channel_count
        WHERE id = :id
    ');
    $stmt->execute([
        ':updated_at' => date(DATE_ATOM),
        ':last_error' => $error,
        ':channel_count' => $channelCount,
        ':id' => $playlistId,
    ]);
}

function save_channels_for_source(string $sourceId, array $channels): void
{
    if ($sourceId === '') {
        return;
    }

    $pdo = db();
    $previousStmt = $pdo->prepare('SELECT * FROM channels WHERE source_id = :source_id');
    $previousStmt->execute([':source_id' => $sourceId]);
    $previousRows = $previousStmt->fetchAll();
    $previous = [];
    foreach ($previousRows as $row) {
        $previous[channel_state_key((string) $row['url'], (string) $row['name'])] = $row;
    }

    $statements = channel_save_statements($pdo);

    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE FROM channels WHERE source_id = :source_id');
    $delete->execute([':source_id' => $sourceId]);

    foreach ($channels as $channel) {
        $old = $previous[channel_state_key((string) ($channel['url'] ?? ''), (string) ($channel['name'] ?? ''))] ?? [];
        channel_save_one($pdo, $statements, $channel, $old, $sourceId);
    }

    $pdo->commit();
}

function read_playlist_content(array $playlist): string
{
    $type = (string) ($playlist['type'] ?? '');
    $source = (string) ($playlist['source'] ?? '');

    if ($type === 'url') {
        return fetch_url($source);
    }

    if ($type === 'file') {
        $path = realpath(DATA_DIR . '/' . $source);
        $dataRoot = realpath(DATA_DIR);
        if (!$path || !$dataRoot || !str_starts_with($path, $dataRoot) || !is_file($path)) {
            throw new RuntimeException('Локальный файл плейлиста не найден.');
        }

        $content = file_get_contents($path, false, null, 0, MAX_PLAYLIST_BYTES + 1);
        if ($content === false) {
            throw new RuntimeException('Не удалось прочитать локальный плейлист.');
        }

        if (strlen($content) > MAX_PLAYLIST_BYTES) {
            throw new RuntimeException('Плейлист слишком большой.');
        }

        return normalize_playlist_encoding($content);
    }

    throw new RuntimeException('Неизвестный тип плейлиста.');
}

function fetch_url(string $url): string
{
    if (!is_http_url($url)) {
        throw new RuntimeException('Недопустимая ссылка плейлиста.');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_USERAGENT => 'MyIPTV/1.0 personal playlist reader',
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.apple.mpegurl, application/x-mpegURL, text/plain, audio/*, video/*, */*',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
            CURLOPT_RANGE => '0-' . MAX_PLAYLIST_BYTES,
        ]);
        $content = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Не удалось скачать плейлист: ' . ($error ?: 'пустой ответ'));
        }

        if ($status >= 400) {
            throw new RuntimeException('Источник вернул HTTP ' . $status . '.');
        }

        if (strlen($content) > MAX_PLAYLIST_BYTES) {
            throw new RuntimeException('Плейлист слишком большой.');
        }

        return normalize_playlist_encoding($content);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "User-Agent: MyIPTV/1.0 personal playlist reader\r\nAccept: application/vnd.apple.mpegurl, application/x-mpegURL, text/plain, audio/*, video/*, */*\r\n",
        ],
    ]);
    $content = file_get_contents($url, false, $context, 0, MAX_PLAYLIST_BYTES + 1);
    if ($content === false || $content === '') {
        throw new RuntimeException('Не удалось скачать плейлист.');
    }

    if (strlen($content) > MAX_PLAYLIST_BYTES) {
        throw new RuntimeException('Плейлист слишком большой.');
    }

    return normalize_playlist_encoding($content);
}

function parse_m3u_playlist(string $content, array $playlist): array
{
    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $channels = [];
    $current = [];
    $baseUrl = playlist_base_url($playlist);

    foreach ($lines as $line) {
        $line = trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($line === '') {
            continue;
        }

        if (stripos($line, '#EXTINF:') === 0) {
            $current = array_merge($current, parse_extinf($line));
            continue;
        }

        if (stripos($line, '#EXTGRP:') === 0) {
            $current['group-title'] = clean_text(substr($line, 8));
            continue;
        }

        if (stripos($line, '#EXTVLCOPT:') === 0 || stripos($line, '#KODIPROP:') === 0) {
            $option = parse_m3u_option_line($line);
            if ($option) {
                $current[$option[0]] = $option[1];
            }
            continue;
        }

        if ($line[0] === '#') {
            continue;
        }

        $urlHints = parse_stream_url_hints($line);
        $url = normalize_stream_url_line($line, $baseUrl);
        if (!is_stream_url($url)) {
            $current = [];
            continue;
        }

        $name = clean_text((string) (
            $current['name']
            ?? $current['tvg-name']
            ?? $current['channel-name']
            ?? $current['title']
            ?? ''
        ));
        if ($name === '' || metadata_value_is_empty($name)) {
            $name = infer_name_from_url($url);
        }

        $genre = clean_text((string) (
            $current['group-title']
            ?? $current['tvg-group']
            ?? $current['group']
            ?? $current['category']
            ?? ''
        ));
        if (metadata_value_is_empty($genre)) {
            $genre = '';
        }

        $rawCountry = clean_text((string) (
            $current['tvg-country']
            ?? $current['tvg-country-code']
            ?? $current['country']
            ?? $playlist['default_country']
            ?? ''
        ));
        $country = infer_country_from_context($rawCountry, [
            (string) ($playlist['default_country'] ?? ''),
            (string) ($playlist['title'] ?? ''),
            (string) ($playlist['source'] ?? ''),
            $name,
            $genre,
            $url,
        ]);
        $city = clean_text((string) (
            $current['city']
            ?? $current['tvg-city']
            ?? $current['region']
            ?? $current['tvg-region']
            ?? $playlist['default_city']
            ?? ''
        ));
        if (metadata_value_is_empty($city)) {
            $city = '';
        }

        $access = classify_stream_access($url, $current, $urlHints);

        $channel = [
            'id' => make_channel_id((string) ($playlist['id'] ?? ''), $url, $name),
            'name' => $name,
            'url' => $url,
            'logo' => clean_url((string) ($current['tvg-logo'] ?? $current['logo'] ?? $current['icon'] ?? '')),
            'genre' => $genre !== '' ? $genre : 'Без группы',
            'country' => $country,
            'city' => $city,
            'source_id' => (string) ($playlist['id'] ?? ''),
            'source_title' => (string) ($playlist['title'] ?? 'Плейлист'),
            'tvg_id' => clean_text((string) ($current['tvg-id'] ?? $current['channel-id'] ?? $current['id'] ?? '')),
            'stream_type' => infer_channel_stream_type($url, $current, $playlist),
            'access_type' => $access['type'],
            'access_notes' => $access['notes'],
            'stream_headers' => $access['headers'],
            'drm_system' => $access['drm_system'],
            'license_url' => $access['license_url'],
            'license_headers' => $access['license_headers'],
            'updated_at' => date(DATE_ATOM),
        ];

        $channels[] = $channel;
        $current = [];
    }

    return $channels;
}

function parse_extinf(string $line): array
{
    $attributes = [];
    if (preg_match_all('/([A-Za-z0-9_.:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s,]+))/u', $line, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $value = '';
            foreach ([2, 3, 4] as $index) {
                if (isset($match[$index]) && $match[$index] !== null && $match[$index] !== '') {
                    $value = (string) $match[$index];
                    break;
                }
            }
            $attributes[strtolower($match[1])] = clean_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }

    $title = m3u_title_after_comma($line);

    $attributes['name'] = clean_text($title);
    return $attributes;
}

function dedupe_channels(array $channels): array
{
    $seen = [];
    $result = [];

    foreach ($channels as $channel) {
        $key = sha1(m3u_url_key((string) ($channel['url'] ?? '')) . '|' . text_lower((string) ($channel['name'] ?? '')));
        if (isset($seen[$key])) {
            $result[$seen[$key]] = merge_channel_metadata($result[$seen[$key]], $channel);
            continue;
        }
        $seen[$key] = count($result);
        $result[] = $channel;
    }

    return $result;
}

function playlist_base_url(array $playlist): string
{
    if (($playlist['type'] ?? '') !== 'url') {
        return '';
    }

    return is_http_url((string) ($playlist['source'] ?? '')) ? (string) $playlist['source'] : '';
}

function parse_m3u_option_line(string $line): ?array
{
    $line = trim(preg_replace('/^#(?:EXTVLCOPT|KODIPROP):/i', '', $line) ?? '');
    if ($line === '' || !str_contains($line, '=')) {
        return null;
    }

    [$key, $value] = explode('=', $line, 2);
    $key = 'option-' . text_lower(clean_text($key));
    $value = clean_text($value);
    return $key !== 'option-' && $value !== '' ? [$key, $value] : null;
}

function parse_stream_url_hints(string $line): array
{
    if (!str_contains($line, '|')) {
        return [];
    }

    [, $rawHints] = explode('|', $line, 2);
    $rawHints = trim($rawHints);
    if ($rawHints === '') {
        return [];
    }

    $headers = [];
    $pairs = preg_split('/[&;]/', $rawHints) ?: [];
    foreach ($pairs as $pair) {
        if (!str_contains($pair, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $pair, 2);
        $key = urldecode(trim($key));
        $value = urldecode(trim($value));
        $header = m3u_header_name_from_key($key, true);
        if ($header !== null && $value !== '') {
            $headers[$header] = $value;
        }
    }

    return $headers;
}

function classify_stream_access(string $url, array $metadata = [], array $urlHeaders = []): array
{
    $headers = stream_headers_from_m3u_metadata($metadata, $urlHeaders);
    $license = stream_license_from_m3u_metadata($metadata, $url);
    $notes = [];
    $type = 'open';
    $haystack = text_lower($url . ' ' . implode(' ', array_map('strval', $metadata)));

    if (stream_metadata_has_drm($metadata, $url) || $license['url'] !== '') {
        $type = 'drm';
        $system = $license['system'] !== '' ? $license['system'] : 'DRM';
        $notes[] = $license['url'] !== ''
            ? 'M3U передал license server для ' . $system . '. Плеер попробует Shaka/EME через локальный license proxy.'
            : 'Найден признак DRM/licence server в M3U. Если добавить license_url/license_headers, плеер попробует Shaka/EME.';
    } elseif (stream_url_has_token($url)) {
        $type = 'tokenized';
        $notes[] = 'В URL есть временный токен или подпись. Приложение будет учитывать срок жизни ссылки и обновлять cache осторожнее.';
    }

    if ($headers) {
        if ($type === 'open') {
            $type = 'header_required';
        }
        $notes[] = 'Плейлист передал HTTP-заголовки: ' . implode(', ', array_keys($headers)) . '. Proxy будет использовать их при проверке и воспроизведении.';
    }

    if (str_contains($haystack, 'geo') || str_contains($haystack, 'geoblock') || str_contains($haystack, 'region')) {
        if ($type === 'open') {
            $type = 'geo_blocked';
        }
        $notes[] = 'Есть намек на региональное ограничение. Если сервер вернет 451/403, канал будет помечен как геоблок, а не как обычная поломка.';
    }

    return [
        'type' => $type,
        'notes' => implode(' ', array_values(array_unique($notes))),
        'headers' => $headers,
        'drm_system' => $license['system'],
        'license_url' => $license['url'],
        'license_headers' => $license['headers'],
    ];
}

function stream_license_from_m3u_metadata(array $metadata, string $streamUrl = ''): array
{
    $system = '';
    $licenseValue = '';
    $headers = [];

    foreach ($metadata as $key => $value) {
        $normalizedKey = text_lower(str_replace('_', '-', (string) $key));
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }

        if (str_contains($normalizedKey, 'license-type')) {
            $system = m3u_drm_system_from_value($value);
            continue;
        }

        if (str_contains($normalizedKey, 'license-key') || str_contains($normalizedKey, 'license-url')) {
            $licenseValue = $value;
            continue;
        }

        if (str_contains($normalizedKey, 'license-headers') || str_contains($normalizedKey, 'license-header')) {
            foreach (m3u_parse_header_query($value, true) as $name => $headerValue) {
                $headers[$name] = $headerValue;
            }
        }
    }

    $url = '';
    if ($licenseValue !== '') {
        $parsed = m3u_parse_license_value($licenseValue);
        $url = $parsed['url'];
        $headers = $headers + $parsed['headers'];
        if ($system === '') {
            $system = $parsed['system'];
        }
    }

    if ($system === '' && stream_metadata_has_drm($metadata, $streamUrl)) {
        $system = m3u_drm_system_from_value(implode(' ', array_map('strval', $metadata)));
    }

    return [
        'system' => $system,
        'url' => $url,
        'headers' => m3u_clean_headers($headers),
    ];
}

function m3u_parse_license_value(string $value): array
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = array_map('trim', explode('|', $value));
    $url = trim((string) ($parts[0] ?? ''));
    $headers = [];
    $system = '';

    if (isset($parts[1]) && $parts[1] !== '') {
            foreach (m3u_parse_header_query($parts[1], true) as $name => $headerValue) {
            $headers[$name] = $headerValue;
        }
    }

    foreach ($parts as $part) {
        $system = $system !== '' ? $system : m3u_drm_system_from_value($part);
        if (str_contains($part, '=') && !str_starts_with(text_lower($part), 'r{')) {
            foreach (m3u_parse_header_query($part, true) as $name => $headerValue) {
                $headers[$name] = $headerValue;
            }
        }
    }

    return [
        'url' => is_http_url($url) ? $url : '',
        'headers' => $headers,
        'system' => $system,
    ];
}

function m3u_drm_system_from_value(string $value): string
{
    $value = text_lower($value);
    if (str_contains($value, 'widevine') || str_contains($value, 'edef8ba9-79d6-4ace-a3c8-27dcd51d21ed')) {
        return 'com.widevine.alpha';
    }
    if (str_contains($value, 'playready') || str_contains($value, '9a04f079-9840-4286-ab92-e65be0885f95')) {
        return 'com.microsoft.playready';
    }
    if (str_contains($value, 'fairplay') || str_contains($value, 'skd://') || str_contains($value, 'com.apple.streamingkeydelivery')) {
        return 'com.apple.fps.1_0';
    }
    if (str_contains($value, 'clearkey') || str_contains($value, 'org.w3.clearkey')) {
        return 'org.w3.clearkey';
    }

    return '';
}

function stream_headers_from_m3u_metadata(array $metadata, array $urlHeaders = []): array
{
    $headers = [];

    foreach ($metadata as $key => $value) {
        $key = (string) $key;
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }

        if (str_ends_with($key, 'stream_headers')) {
            foreach (m3u_parse_header_query($value, true) as $name => $headerValue) {
                $headers[$name] = $headerValue;
            }
            continue;
        }

        $header = m3u_header_name_from_key($key);
        if ($header !== null) {
            $headers[$header] = $value;
        }
    }

    foreach ($urlHeaders as $name => $value) {
        $name = trim((string) $name);
        $value = trim((string) $value);
        if ($name !== '' && $value !== '') {
            $headers[$name] = $value;
        }
    }

    return m3u_clean_headers($headers);
}

function m3u_parse_header_query(string $value, bool $allowAny = false): array
{
    $headers = [];
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pairs = preg_split('/[&;]/', $value) ?: [];
    foreach ($pairs as $pair) {
        if (!str_contains($pair, '=')) {
            continue;
        }
        [$key, $headerValue] = explode('=', $pair, 2);
        $header = m3u_header_name_from_key(urldecode(trim($key)), $allowAny);
        $headerValue = urldecode(trim($headerValue));
        if ($header !== null && $headerValue !== '') {
            $headers[$header] = $headerValue;
        }
    }

    return $headers;
}

function m3u_header_name_from_key(string $key, bool $allowAny = false): ?string
{
    $rawKey = trim($key);
    $key = text_lower($rawKey);
    $key = preg_replace('/^option-/', '', $key) ?? $key;
    $key = str_replace('_', '-', $key);

    $known = match ($key) {
        'http-user-agent', 'user-agent', 'useragent' => 'User-Agent',
        'http-referrer', 'http-referer', 'referrer', 'referer' => 'Referer',
        'http-origin', 'origin' => 'Origin',
        'http-cookie', 'cookie' => 'Cookie',
        'x-forwarded-for' => 'X-Forwarded-For',
        default => null,
    };

    if ($known !== null || !$allowAny) {
        return $known;
    }

    $candidate = str_replace('_', '-', $rawKey);
    if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^`|~-]+(?:-[A-Za-z0-9!#$%&\'*+.^`|~-]+)*$/', $candidate)) {
        return null;
    }

    return implode('-', array_map(
        static fn (string $part): string => $part === '' ? $part : strtoupper($part[0]) . substr($part, 1),
        explode('-', $candidate)
    ));
}

function m3u_clean_headers(array $headers): array
{
    $clean = [];
    foreach ($headers as $name => $value) {
        $name = trim((string) $name);
        $value = trim((string) $value);
        if ($name === '' || $value === '' || preg_match('/[\r\n:]/', $name) || preg_match('/[\r\n]/', $value)) {
            continue;
        }
        $clean[$name] = $value;
    }

    return $clean;
}

function stream_metadata_has_drm(array $metadata, string $url = ''): bool
{
    $text = text_lower($url . ' ' . implode(' ', array_map('strval', $metadata)));
    return str_contains($text, 'widevine')
        || str_contains($text, 'playready')
        || str_contains($text, 'fairplay')
        || str_contains($text, 'com.apple.streamingkeydelivery')
        || str_contains($text, 'license_key')
        || str_contains($text, 'license-key')
        || str_contains($text, 'license_type')
        || str_contains($text, 'license-type')
        || str_contains($text, 'inputstream.adaptive.license')
        || str_contains($text, 'skd://')
        || str_contains($text, 'edef8ba9-79d6-4ace-a3c8-27dcd51d21ed')
        || str_contains($text, '9a04f079-9840-4286-ab92-e65be0885f95');
}

function stream_url_has_token(string $url): bool
{
    $query = text_lower((string) (parse_url($url, PHP_URL_QUERY) ?: ''));
    if ($query === '') {
        return false;
    }

    return preg_match('~(?:token|signature|sig|auth|expires|expire|exp|hdnts|hdnea|session|jwt|st=|e=|key=)~i', $query) === 1;
}

function normalize_stream_url_line(string $line, string $baseUrl = ''): string
{
    $line = trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $line = trim($line, " \t\n\r\0\x0B\"'");
    $line = preg_replace('~^\s*(?:url|file|stream)\s*=\s*~iu', '', $line) ?? $line;

    if (str_contains($line, '|')) {
        [$candidate] = explode('|', $line, 2);
        if (is_stream_url(resolve_m3u_url(trim($candidate), $baseUrl))) {
            $line = trim($candidate);
        }
    }

    return resolve_m3u_url($line, $baseUrl);
}

function resolve_m3u_url(string $url, string $baseUrl = ''): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (is_stream_url($url)) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        $scheme = (string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
        return $scheme . ':' . $url;
    }

    if ($baseUrl === '' || !is_http_url($baseUrl)) {
        return $url;
    }

    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return $url;
    }

    $root = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    if (str_starts_with($url, '/')) {
        return m3u_normalize_url_path($root . $url);
    }

    $dir = isset($base['path']) ? preg_replace('~/[^/]*$~', '/', $base['path']) : '/';
    if (!is_string($dir) || $dir === '') {
        $dir = '/';
    }

    return m3u_normalize_url_path($root . $dir . $url);
}

function m3u_normalize_url_path(string $url): string
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

function m3u_title_after_comma(string $line): string
{
    $quote = null;
    $firstComma = false;
    $length = strlen($line);
    for ($i = 0; $i < $length; $i++) {
        $char = $line[$i];
        if (($char === '"' || $char === "'") && ($i === 0 || $line[$i - 1] !== '\\')) {
            $quote = $quote === $char ? null : ($quote ?? $char);
            continue;
        }
        if ($char === ',' && $quote === null) {
            $firstComma = $i;
            break;
        }
    }

    return $firstComma === false ? '' : trim(substr($line, $firstComma + 1));
}

function m3u_url_key(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return text_lower(trim($url));
    }

    $normalized = text_lower((string) $parts['scheme']) . '://' . text_lower((string) $parts['host']);
    if (isset($parts['port'])) {
        $normalized .= ':' . $parts['port'];
    }
    $normalized .= (string) ($parts['path'] ?? '/');
    if (isset($parts['query'])) {
        $normalized .= '?' . $parts['query'];
    }

    return $normalized;
}

function merge_channel_metadata(array $base, array $candidate): array
{
    foreach (['name', 'logo', 'genre', 'country', 'city', 'tvg_id', 'stream_type', 'access_type', 'access_notes', 'stream_headers', 'drm_system', 'license_url', 'license_headers'] as $key) {
        $current = clean_text((string) ($base[$key] ?? ''));
        $nextValue = $candidate[$key] ?? '';
        $next = is_array($nextValue) ? channel_stream_headers_json($nextValue) : clean_text((string) $nextValue);
        if ($next === '' || metadata_value_is_empty($next)) {
            continue;
        }
        if ($current === '' || metadata_value_is_empty($current) || ($key === 'genre' && $current === 'Без группы') || ($key === 'name' && $current === 'Канал без названия')) {
            $base[$key] = $candidate[$key];
        }
    }

    return $base;
}

function normalize_playlist_encoding(string $content): string
{
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    if (!function_exists('mb_check_encoding') || !function_exists('mb_convert_encoding')) {
        return $content;
    }

    if (!mb_check_encoding($content, 'UTF-8')) {
        $converted = mb_convert_encoding($content, 'UTF-8', 'Windows-1251, ISO-8859-1, UTF-8');
        if (is_string($converted)) {
            return $converted;
        }
    }

    return $content;
}

function make_playlist_id(string $seed): string
{
    return 'pl_' . substr(sha1($seed), 0, 16);
}

function make_channel_id(string $playlistId, string $url, string $name): string
{
    return 'ch_' . substr(sha1($playlistId . '|' . $url . '|' . $name), 0, 20);
}

function is_http_url(string $url): bool
{
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    return in_array($scheme, ['http', 'https'], true) && !empty($parts['host']);
}

function is_stream_url(string $url): bool
{
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    return in_array($scheme, ['http', 'https', 'rtmp', 'rtsp', 'udp', 'rtp'], true);
}

if (!function_exists('clean_text')) {
    function clean_text(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return is_string($value) ? $value : '';
    }
}

function clean_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return is_http_url($value) ? $value : '';
}

function infer_name_from_url(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $name = trim(pathinfo($path, PATHINFO_FILENAME));
    $name = str_replace(['_', '-'], ' ', $name);
    return $name !== '' ? clean_text($name) : 'Канал без названия';
}

function infer_channel_stream_type(string $url, array $metadata = [], array $playlist = []): string
{
    $type = infer_stream_type($url);
    if ($type === 'audio') {
        return 'audio';
    }

    $signals = [];
    foreach (['type', 'tvg-type', 'media-type', 'content-type', 'kind', 'group-title', 'group', 'category'] as $key) {
        $value = $metadata[$key] ?? null;
        if (is_scalar($value)) {
            $signals[] = (string) $value;
        }
    }

    foreach (['title', 'default_group'] as $key) {
        $value = $playlist[$key] ?? null;
        if (is_scalar($value)) {
            $signals[] = (string) $value;
        }
    }

    $signal = text_lower(clean_text(implode(' ', $signals)));
    if ($signal !== '' && preg_match('/(^|[\s,;|\/_-])(radio|audio|радио|радиостанц)/u', $signal)) {
        return 'audio';
    }

    return $type;
}

function infer_stream_type(string $url): string
{
    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
    if (in_array($scheme, ['udp', 'rtp'], true)) {
        return $scheme;
    }

    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if (str_ends_with($path, '.m3u8')) {
        return 'hls';
    }
    if (str_ends_with($path, '.mpd')) {
        return 'dash';
    }
    if (preg_match('/\.(mp3|aac|m4a|flac|wav|oga|ogg|opus)$/', $path)) {
        return 'audio';
    }
    if (preg_match('/\.(mp4|webm|ogg|mkv|avi|mov|m4v|ts)$/', $path)) {
        return 'file';
    }
    return 'stream';
}

