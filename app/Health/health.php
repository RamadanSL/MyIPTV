<?php
declare(strict_types=1);

require_once __DIR__ . '/../m3u.php';

const HEALTH_CHECK_CONCURRENCY = 32;
const HEALTH_CONNECT_TIMEOUT = 3;
const HEALTH_FAST_TIMEOUT = 7;
const HEALTH_FAST_RANGE_END = 65535;

function check_all_channels(int $limit = 0, bool $includeDead = true, ?callable $progress = null): array
{
    $sql = 'SELECT * FROM channels';
    if (!$includeDead) {
        $sql .= " WHERE health_status != 'dead'";
    }
    $sql .= " ORDER BY COALESCE(last_checked_at, '') ASC, name COLLATE NOCASE";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }

    $channels = db()->query($sql)->fetchAll();
    $summary = [
        'checked' => 0,
        'live' => 0,
        'dead' => 0,
        'unknown' => 0,
    ];

    $total = count($channels);
    if ($total === 0) {
        return $summary;
    }

    if (function_exists('curl_multi_init')) {
        return check_channels_fast_parallel($channels, $summary, $progress);
    }

    foreach ($channels as $index => $channel) {
        if ($progress) {
            $progress($index + 1, $total, 'Проверяю: ' . (string) ($channel['name'] ?? $channel['url']));
        }
        $result = probe_channel_health($channel);
        update_channel_health((string) $channel['id'], $result);
        $summary['checked']++;
        $summary[$result['status']]++;
    }

    return $summary;
}

function check_channels_fast_parallel(array $channels, array $summary, ?callable $progress = null): array
{
    $multi = curl_multi_init();
    $queue = array_values($channels);
    $active = [];
    $completed = 0;
    $total = count($queue);

    $fillQueue = static function () use (&$queue, &$active, $multi): void {
        while (count($active) < HEALTH_CHECK_CONCURRENCY && $queue) {
            $channel = array_shift($queue);
            $handle = health_fast_handle($channel);
            $key = health_handle_key($handle);
            $active[$key] = [
                'handle' => $handle,
                'channel' => $channel,
            ];
            curl_multi_add_handle($multi, $handle);
        }
    };

    $fillQueue();
    do {
        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($multi)) {
            $handle = $info['handle'];
            $key = health_handle_key($handle);
            $meta = $active[$key] ?? null;
            if (!$meta) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                continue;
            }

            $channel = $meta['channel'];
            $result = health_result_from_fast_handle($handle, $channel);
            update_channel_health((string) $channel['id'], $result);

            $summary['checked']++;
            $summary[$result['status']]++;
            $completed++;

            if ($progress) {
                $progress(
                    $completed,
                    $total,
                    'Проверено: ' . $completed . '/' . $total . '. '
                        . (string) ($channel['name'] ?? $channel['url'])
                        . ' -> ' . $result['status']
                );
            }

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            unset($active[$key]);
            $fillQueue();
        }

        if ($running && curl_multi_select($multi, 0.5) === -1) {
            usleep(100000);
        }
    } while ($running || $active);

    curl_multi_close($multi);

    return $summary;
}

function health_counts(): array
{
    $counts = [
        'live' => 0,
        'dead' => 0,
        'unknown' => 0,
        'total' => 0,
    ];

    foreach (db()->query('SELECT health_status, COUNT(*) AS c FROM channels GROUP BY health_status')->fetchAll() as $row) {
        $status = (string) ($row['health_status'] ?? 'unknown');
        $count = (int) ($row['c'] ?? 0);
        if (!isset($counts[$status])) {
            $status = 'unknown';
        }
        $counts[$status] += $count;
        $counts['total'] += $count;
    }

    return $counts;
}

function probe_channel_health(array $channel): array
{
    $url = (string) ($channel['url'] ?? '');
    if (!is_http_url($url)) {
        return health_result('dead', 0, 'Неподдерживаемый URL.', null);
    }

    $type = (string) ($channel['stream_type'] ?? infer_stream_type($url));
    if ((string) ($channel['access_type'] ?? '') === 'drm') {
        $note = trim((string) ($channel['access_notes'] ?? ''));
        return health_result('unknown', 0, $note !== '' ? $note : 'Канал защищен DRM. Нужна легальная лицензия DRM.', null, 'drm', $note);
    }

    if ($type === 'hls' || str_contains(text_lower($url), '.m3u8')) {
        return probe_hls_health($url, 0, health_channel_headers($channel));
    }
    if ($type === 'dash' || str_contains(text_lower($url), '.mpd')) {
        return probe_dash_health($url, health_channel_headers($channel));
    }

    $response = health_fetch($url, 0, 4096, HEALTH_FAST_TIMEOUT, health_channel_headers($channel));
    if ($response['status'] >= 200 && $response['status'] < 400) {
        return health_result('live', $response['status'], null, $url, health_existing_access_type($channel), (string) ($channel['access_notes'] ?? ''));
    }

    $protected = health_protected_result_from_http($response['status'], $response['error'] ?: '', $channel);
    if ($protected !== null) {
        return $protected;
    }

    return health_result('dead', $response['status'], $response['error'] ?: 'HTTP ' . $response['status'], null);
}

function probe_hls_health(string $url, int $depth = 0, array $headers = []): array
{
    if ($depth > 3) {
        return health_result('live', 200, null, $url);
    }

    $response = health_fetch($url, 0, HEALTH_FAST_RANGE_END, HEALTH_FAST_TIMEOUT, $headers);
    $status = $response['status'];
    $body = $response['body'];
    if ($status < 200 || $status >= 400 || trim($body) === '') {
        $protected = health_protected_result_from_http($status, $response['error'] ?: '', []);
        if ($protected !== null) {
            return $protected;
        }
        return health_result('dead', $status, $response['error'] ?: 'HTTP ' . $status, null);
    }

    if (!str_starts_with(ltrim($body), '#EXTM3U')) {
        return health_result('dead', $status, 'Ответ не похож на HLS playlist.', null);
    }

    $protection = detect_hls_protection($body);
    if ($protection['type'] === 'drm') {
        return health_result('unknown', $status, $protection['notes'], null, 'drm', $protection['notes']);
    }

    $uris = hls_playlist_uris($body, $url);
    if (!$uris) {
        return health_result('live', $status, null, $url, (string) ($protection['type'] ?? ''), (string) ($protection['notes'] ?? ''));
    }

    $nestedError = null;
    foreach ($uris as $uri) {
        $path = text_lower((string) (parse_url($uri, PHP_URL_PATH) ?: ''));
        if (str_ends_with($path, '.m3u8')) {
            $nested = probe_hls_health($uri, $depth + 1, $headers);
            if ($nested['status'] === 'live') {
                return health_result('live', $status, null, $url, (string) ($nested['access_type'] ?? ''), (string) ($nested['access_notes'] ?? ''));
            }
            $error = trim((string) ($nested['error'] ?? ''));
            if ($error !== '') {
                $nestedError ??= $error;
            }
            continue;
        }

        $segment = health_fetch($uri, 0, 2048, HEALTH_FAST_TIMEOUT, $headers);
        if ($segment['status'] >= 200 && $segment['status'] < 400) {
            return health_result('live', $status, null, $url, (string) ($protection['type'] ?? ''), (string) ($protection['notes'] ?? ''));
        }
    }

    if ($nestedError !== null) {
        return health_result('dead', $status, $nestedError, null);
    }

    return health_result('dead', $status, 'HLS playlist есть, но сегменты не открылись.', null);
}

function probe_dash_health(string $url, array $headers = []): array
{
    $response = health_fetch($url, 0, HEALTH_FAST_RANGE_END, HEALTH_FAST_TIMEOUT, $headers);
    $status = $response['status'];
    $body = $response['body'];
    if ($status < 200 || $status >= 400 || trim($body) === '') {
        $protected = health_protected_result_from_http($status, $response['error'] ?: '', []);
        if ($protected !== null) {
            return $protected;
        }
        return health_result('dead', $status, $response['error'] ?: 'HTTP ' . $status, null);
    }

    $protection = detect_dash_protection($body);
    if ($protection['type'] === 'drm') {
        return health_result('unknown', $status, $protection['notes'], null, 'drm', $protection['notes']);
    }

    return health_result('live', $status, null, $url);
}

function update_channel_health(string $channelId, array $result): void
{
    $stmt = db()->prepare("
        UPDATE channels
        SET health_status = :health_status,
            last_checked_at = :last_checked_at,
            last_http_status = :last_http_status,
            last_probe_error = :last_probe_error,
            working_url = :working_url,
            access_type = CASE WHEN :access_type != '' THEN :access_type ELSE access_type END,
            access_notes = CASE WHEN :access_notes != '' THEN :access_notes ELSE access_notes END
        WHERE id = :id
    ");
    $stmt->execute([
        ':health_status' => $result['status'],
        ':last_checked_at' => date(DATE_ATOM),
        ':last_http_status' => $result['http_status'],
        ':last_probe_error' => $result['error'],
        ':working_url' => $result['working_url'],
        ':access_type' => (string) ($result['access_type'] ?? ''),
        ':access_notes' => (string) ($result['access_notes'] ?? ''),
        ':id' => $channelId,
    ]);
}

function health_result(string $status, int $httpStatus, ?string $error, ?string $workingUrl, string $accessType = '', string $accessNotes = ''): array
{
    return [
        'status' => in_array($status, ['live', 'dead', 'unknown'], true) ? $status : 'unknown',
        'http_status' => $httpStatus,
        'error' => $error,
        'working_url' => $workingUrl,
        'access_type' => trim($accessType) === '' ? '' : channel_access_type($accessType),
        'access_notes' => clean_text($accessNotes),
    ];
}

function hls_playlist_uris(string $playlist, string $baseUrl): array
{
    $uris = [];
    foreach (preg_split('/\r\n|\r|\n/', $playlist) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $uris[] = health_resolve_url($line, $baseUrl);
    }

    return $uris;
}

function health_fetch(string $url, ?int $rangeStart = null, ?int $rangeEnd = null, int $timeout = 12, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $headers = health_request_headers($extraHeaders);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => min(HEALTH_CONNECT_TIMEOUT, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => health_user_agent(),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
    ]);

    if ($rangeStart !== null && $rangeEnd !== null) {
        curl_setopt($ch, CURLOPT_RANGE, $rangeStart . '-' . $rangeEnd);
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function health_fast_handle(array $channel): CurlHandle
{
    $url = (string) ($channel['url'] ?? '');
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => HEALTH_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => HEALTH_FAST_TIMEOUT,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => health_user_agent(),
        CURLOPT_HTTPHEADER => health_request_headers(health_channel_headers($channel)),
        CURLOPT_ENCODING => '',
    ]);

    if (is_http_url($url)) {
        curl_setopt($handle, CURLOPT_RANGE, '0-' . HEALTH_FAST_RANGE_END);
    }

    return $handle;
}

function health_result_from_fast_handle(CurlHandle $handle, array $channel): array
{
    $url = (string) ($channel['url'] ?? '');
    if (!is_http_url($url)) {
        return health_result('dead', 0, 'Неподдерживаемый URL.', null);
    }

    $body = curl_multi_getcontent($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    $contentType = text_lower((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE));
    $error = curl_error($handle);
    $type = (string) ($channel['stream_type'] ?? infer_stream_type($url));
    $workingUrl = $effectiveUrl !== '' ? $effectiveUrl : $url;

    if ((string) ($channel['access_type'] ?? '') === 'drm') {
        $note = trim((string) ($channel['access_notes'] ?? ''));
        return health_result('unknown', $status, $note !== '' ? $note : 'Канал защищен DRM. Нужна легальная лицензия DRM.', null, 'drm', $note);
    }

    if ($status === 0) {
        return health_result('unknown', 0, $error ?: 'Нет ответа за ' . HEALTH_FAST_TIMEOUT . ' сек.', null);
    }

    if ($status >= 400) {
        $protected = health_protected_result_from_http($status, $error, $channel);
        if ($protected !== null) {
            return $protected;
        }
        return health_result('dead', $status, $error ?: 'HTTP ' . $status, null);
    }

    if ($status < 200 || $status >= 400) {
        return health_result('unknown', $status, $error ?: 'HTTP ' . $status, null);
    }

    if ($type === 'hls' || str_contains(text_lower($url), '.m3u8') || str_contains($contentType, 'mpegurl')) {
        $trimmed = ltrim(is_string($body) ? $body : '');
        if (str_starts_with($trimmed, '#EXTM3U')) {
            $protection = detect_hls_protection($trimmed);
            if ($protection['type'] === 'drm') {
                return health_result('unknown', $status, $protection['notes'], null, 'drm', $protection['notes']);
            }

            return health_result('live', $status, null, $workingUrl, (string) ($protection['type'] ?? health_existing_access_type($channel)), (string) ($protection['notes'] ?? $channel['access_notes'] ?? ''));
        }

        return health_result('dead', $status, 'Ответ не похож на HLS playlist.', null);
    }

    return health_result('live', $status, null, $workingUrl, health_existing_access_type($channel), (string) ($channel['access_notes'] ?? ''));
}

function health_handle_key(CurlHandle $handle): int
{
    return spl_object_id($handle);
}

function health_user_agent(): string
{
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125 Safari/537.36 MyIPTV';
}

function health_request_headers(array $extraHeaders = []): array
{
    $headers = [
        'Accept' => 'application/vnd.apple.mpegurl, application/x-mpegURL, audio/*, video/*, */*',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
    ];

    foreach ($extraHeaders as $name => $value) {
        $name = trim((string) $name);
        $value = trim((string) $value);
        if ($name !== '' && $value !== '' && !preg_match('/[\r\n:]/', $name) && !preg_match('/[\r\n]/', $value)) {
            $headers[$name] = $value;
        }
    }

    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }

    return $lines;
}

function health_channel_headers(array $channel): array
{
    $raw = trim((string) ($channel['stream_headers'] ?? ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $headers = [];
    foreach ($decoded as $name => $value) {
        $name = trim((string) $name);
        $value = trim((string) $value);
        if ($name !== '' && $value !== '' && !preg_match('/[\r\n:]/', $name) && !preg_match('/[\r\n]/', $value)) {
            $headers[$name] = $value;
        }
    }

    return $headers;
}

function health_existing_access_type(array $channel): string
{
    $type = channel_access_type((string) ($channel['access_type'] ?? 'open'));
    return $type === 'open' ? '' : $type;
}

function health_protected_result_from_http(int $status, string $error, array $channel): ?array
{
    if ($status === 401) {
        return health_result('unknown', $status, 'Источник требует авторизацию. Нужны легальные cookies/token/headers от провайдера.', null, 'auth_required', 'Источник вернул HTTP 401.');
    }
    if ($status === 451) {
        return health_result('unknown', $status, 'Источник недоступен по региону или юридическому ограничению.', null, 'geo_blocked', 'Источник вернул HTTP 451.');
    }
    if ($status === 403) {
        $type = health_existing_access_type($channel);
        if ($type === 'header_required' || $type === 'tokenized') {
            return health_result('unknown', $status, 'Источник отклонил запрос. Вероятно, токен истек или нужны другие HTTP-заголовки.', null, $type, (string) ($channel['access_notes'] ?? ''));
        }

        return health_result('unknown', $status, 'Источник запрещает доступ без авторизации, нужного региона или правильных заголовков.', null, 'auth_required', 'Источник вернул HTTP 403.');
    }

    if ($error !== '' && preg_match('~(?:geo|region|country|forbidden|unauthori[sz]ed|token|signature|license)~i', $error)) {
        return health_result('unknown', $status, $error, null, 'auth_required', $error);
    }

    return null;
}

function detect_hls_protection(string $manifest): array
{
    $lower = text_lower($manifest);
    if (
        str_contains($lower, 'com.apple.streamingkeydelivery')
        || str_contains($lower, 'widevine')
        || str_contains($lower, 'playready')
        || str_contains($lower, 'skd://')
        || str_contains($lower, 'edef8ba9-79d6-4ace-a3c8-27dcd51d21ed')
        || str_contains($lower, '9a04f079-9840-4286-ab92-e65be0885f95')
    ) {
        return [
            'type' => 'drm',
            'notes' => 'HLS manifest содержит DRM KEYFORMAT/licence marker. Нужна легальная DRM-лицензия; обычный M3U/HLS proxy это не расшифрует.',
        ];
    }

    if (preg_match('~#EXT-X-(?:SESSION-)?KEY:.*METHOD=(?!NONE)([^,\\s]+)~i', $manifest, $match)) {
        $method = strtoupper(trim((string) ($match[1] ?? '')));
        if ($method !== '' && $method !== 'AES-128') {
            return [
                'type' => 'drm',
                'notes' => 'HLS manifest использует защищенный метод ключа: ' . $method . '.',
            ];
        }
        if ($method === 'AES-128') {
            return [
                'type' => 'header_required',
                'notes' => 'HLS использует AES-128 key URI. Если ключ открывается с теми же заголовками, proxy сможет отдать поток браузеру.',
            ];
        }
    }

    return [
        'type' => '',
        'notes' => '',
    ];
}

function detect_dash_protection(string $manifest): array
{
    $lower = text_lower($manifest);
    if (
        str_contains($lower, '<contentprotection')
        || str_contains($lower, 'cenc:pssh')
        || str_contains($lower, 'widevine')
        || str_contains($lower, 'playready')
        || str_contains($lower, 'edef8ba9-79d6-4ace-a3c8-27dcd51d21ed')
        || str_contains($lower, '9a04f079-9840-4286-ab92-e65be0885f95')
    ) {
        return [
            'type' => 'drm',
            'notes' => 'DASH manifest содержит ContentProtection/PSSH. Нужна легальная DRM-лицензия.',
        ];
    }

    return [
        'type' => '',
        'notes' => '',
    ];
}

function health_resolve_url(string $url, string $baseUrl): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (is_http_url($url)) {
        return $url;
    }

    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return $url;
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

    return health_normalize_url_path($root . $dir . $url);
}

function health_normalize_url_path(string $url): string
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
