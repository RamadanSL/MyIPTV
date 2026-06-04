<?php
declare(strict_types=1);

const HLS_AUTH_API_TEMPLATE_DEFAULT = 'https://api.example.com/get-stream?id={channel}';
const HLS_CACHE_TTL_SECONDS = 300;
const HLS_MAX_AUTH_BYTES = 1048576;
const HLS_MAX_MANIFEST_BYTES = 8388608;
const HLS_MAX_SEGMENT_BYTES = 52428800;
const HLS_MAX_PLAYLIST_BYTES = 15728640;
const HLS_MAX_DISCOVERY_BYTES = 1048576;
const HLS_DISCOVERY_CACHE_TTL_SECONDS = 21600;
const HLS_DISCOVERY_MAX_QUERIES = 4;
const HLS_DISCOVERY_MAX_PROVIDERS = 4;
const HLS_DISCOVERY_MAX_PAGE_PROBES = 8;
const HLS_DISCOVERY_MAX_SOURCES = 40;

define('HLS_DATA_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data');
define('HLS_CACHE_FILE', HLS_DATA_DIR . DIRECTORY_SEPARATOR . 'hls_stream_cache.json');
define('HLS_DISCOVERY_CACHE_FILE', HLS_DATA_DIR . DIRECTORY_SEPARATOR . 'hls_discovery_cache.json');
define('HLS_LOG_FILE', HLS_DATA_DIR . DIRECTORY_SEPARATOR . 'hls_proxy.log');
define('HLS_SEGMENT_SECRET_FILE', HLS_DATA_DIR . DIRECTORY_SEPARATOR . 'hls_segment.secret');
define('HLS_M3U_SOURCES_FILE', HLS_DATA_DIR . DIRECTORY_SEPARATOR . 'hls_m3u_sources.txt');
define('HLS_CHANNEL_ALIASES_FILE', HLS_DATA_DIR . DIRECTORY_SEPARATOR . 'hls_channel_aliases.txt');
define('HLS_DB_FILE', HLS_DATA_DIR . DIRECTORY_SEPARATOR . 'myiptv.sqlite');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    hls_send_cors_headers();
    http_response_code(204);
    exit;
}

if (!function_exists('curl_init')) {
    hls_fail(500, 'В PHP не включено расширение cURL.');
}

if (isset($_GET['segment'])) {
    hls_handle_segment((string) $_GET['segment']);
}

$channel = hls_read_channel();
$streamUrl = hls_resolve_channel_stream($channel);
$manifest = hls_http_get($streamUrl, hls_cdn_headers($streamUrl), 25, HLS_MAX_MANIFEST_BYTES);
$manifestUrl = $manifest['effective_url'] !== '' ? $manifest['effective_url'] : $streamUrl;

if (!hls_looks_like_manifest($manifestUrl, $manifest['content_type'], $manifest['body'])) {
    hls_fail(502, 'CDN вернул не HLS-manifest для канала: ' . $channel);
}

hls_send_cors_headers();
header('Cache-Control: no-store');
header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');

echo hls_rewrite_manifest($manifest['body'], $manifestUrl);

function hls_handle_segment(string $encodedUrl): never
{
    $targetUrl = hls_decode_segment_url($encodedUrl);
    if (!hls_is_http_url($targetUrl)) {
        hls_fail(400, 'Некорректный URL сегмента.');
    }
    if (!hls_segment_signature_valid($targetUrl, (string) ($_GET['sig'] ?? ''))) {
        hls_fail(403, 'Некорректная подпись HLS-сегмента.');
    }

    $response = hls_http_get($targetUrl, hls_cdn_headers($targetUrl), 30, HLS_MAX_SEGMENT_BYTES);
    $effectiveUrl = $response['effective_url'] !== '' ? $response['effective_url'] : $targetUrl;

    hls_send_cors_headers();
    header('Cache-Control: no-store');

    if (hls_looks_like_manifest($effectiveUrl, $response['content_type'], $response['body'])) {
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        echo hls_rewrite_manifest($response['body'], $effectiveUrl);
        exit;
    }

    header('Content-Type: ' . hls_content_type($effectiveUrl, $response['content_type']));
    echo $response['body'];
    exit;
}

function hls_read_channel(): string
{
    $channel = trim((string) ($_GET['channel'] ?? ''));
    if ($channel === '') {
        hls_fail(400, 'GET-параметр channel обязателен.');
    }
    if (strlen($channel) > 128 || preg_match('/[\x00-\x1F\x7F]/', $channel)) {
        hls_fail(400, 'Некорректный идентификатор channel.');
    }

    return $channel;
}

function hls_resolve_channel_stream(string $channel): string
{
    $cachedUrl = hls_cache_get($channel);
    if ($cachedUrl !== null) {
        hls_log('cache-hit', ['channel' => $channel]);
        return $cachedUrl;
    }

    hls_log('cache-miss', ['channel' => $channel]);

    $streamUrl = hls_find_stream_in_public_lists($channel);
    if ($streamUrl === null && hls_env('HLS_AUTH_API_TEMPLATE', '') !== '') {
        $streamUrl = hls_resolve_channel_stream_from_auth_api($channel);
    }

    if ($streamUrl === null) {
        hls_log('stream-not-found', ['channel' => $channel]);
        hls_fail(502, 'Не удалось найти HLS-стрим для канала: ' . $channel);
    }

    hls_register_resolved_channel($channel, $streamUrl);
    hls_cache_set($channel, $streamUrl, HLS_CACHE_TTL_SECONDS);
    return $streamUrl;
}

function hls_resolve_channel_stream_from_auth_api(string $channel): ?string
{
    $apiUrl = hls_auth_api_url($channel);
    $headers = hls_cdn_headers($apiUrl);
    $headers['Accept'] = 'application/json, text/plain, */*';

    $response = hls_http_get($apiUrl, $headers, 15, HLS_MAX_AUTH_BYTES);
    $data = json_decode($response['body'], true);

    if (!is_array($data)) {
        hls_log('auth-json-error', ['channel' => $channel, 'json_error' => json_last_error_msg()]);
        hls_fail(502, 'API авторизации вернул некорректный JSON.');
    }

    $streamUrl = trim((string) ($data['url'] ?? ''));
    if (!hls_is_http_url($streamUrl)) {
        hls_log('auth-url-error', ['channel' => $channel]);
        hls_fail(502, 'В JSON-ответе API нет корректного поля url.');
    }

    return $streamUrl;
}

function hls_find_stream_in_public_lists(string $channel): ?string
{
    $channels_map = hls_channels_map();
    $searchNames = $channels_map[$channel] ?? hls_default_channel_aliases($channel);

    $localUrl = hls_find_stream_in_local_catalog($searchNames);
    if ($localUrl !== null) {
        hls_log('local-catalog-match', ['channel' => $channel]);
        return $localUrl;
    }

    $m3u_sources = hls_m3u_sources();
    foreach ($m3u_sources as $m3uUrl) {
        $m3uContent = hls_fetch_m3u_silent($m3uUrl);
        if ($m3uContent === null) {
            hls_log('playlist-skip', ['url' => $m3uUrl]);
            continue;
        }

        $streamUrl = hls_find_stream_in_m3u($m3uContent, $searchNames);
        if ($streamUrl !== null) {
            hls_log('playlist-match', ['channel' => $channel, 'source' => $m3uUrl]);
            return $streamUrl;
        }
    }

    foreach (hls_discover_m3u_sources($searchNames) as $m3uUrl) {
        if (in_array($m3uUrl, $m3u_sources, true)) {
            continue;
        }

        $m3uContent = hls_fetch_m3u_silent($m3uUrl);
        if ($m3uContent === null) {
            hls_log('discovered-playlist-skip', ['url' => $m3uUrl]);
            continue;
        }

        $streamUrl = hls_find_stream_in_m3u($m3uContent, $searchNames);
        if ($streamUrl !== null) {
            hls_log('discovered-playlist-match', ['channel' => $channel, 'source' => $m3uUrl]);
            return $streamUrl;
        }
    }

    return null;
}

function hls_m3u_sources(): array
{
    $sources = [];
    $sources = array_merge($sources, hls_local_playlist_sources());
    $sources = array_merge($sources, hls_split_config_values(hls_env('HLS_M3U_SOURCES', '')));

    if (is_file(HLS_M3U_SOURCES_FILE)) {
        hls_assert_data_file(HLS_M3U_SOURCES_FILE);
        $sources = array_merge($sources, hls_read_config_lines(HLS_M3U_SOURCES_FILE));
    }

    $clean = [];
    foreach ($sources as $source) {
        $source = trim((string) $source);
        if ($source !== '' && hls_is_http_url($source)) {
            $clean[$source] = $source;
        }
    }

    return array_values($clean);
}

function hls_find_stream_in_local_catalog(array $searchNames): ?string
{
    $pdo = hls_db_readonly();
    if (!$pdo instanceof PDO) {
        return null;
    }

    $normalizedSearchNames = hls_normalized_aliases($searchNames);
    if ($normalizedSearchNames === []) {
        return null;
    }

    try {
        $rows = $pdo->query("
            SELECT name, url, tvg_id, genre, health_status, working_url
            FROM channels
            ORDER BY
                CASE health_status
                    WHEN 'live' THEN 0
                    WHEN 'unknown' THEN 1
                    ELSE 2
                END,
                updated_at DESC
            LIMIT 20000
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        hls_log('local-catalog-error', ['error' => $exception->getMessage()]);
        return null;
    }

    foreach ($rows as $row) {
        $haystack = implode(' ', [
            (string) ($row['name'] ?? ''),
            (string) ($row['tvg_id'] ?? ''),
            (string) ($row['genre'] ?? ''),
        ]);

        if (!hls_text_matches_aliases($haystack, $normalizedSearchNames)) {
            continue;
        }

        $workingUrl = trim((string) ($row['working_url'] ?? ''));
        if (hls_is_http_url($workingUrl)) {
            return $workingUrl;
        }

        $url = trim((string) ($row['url'] ?? ''));
        if (hls_is_http_url($url)) {
            return $url;
        }
    }

    return null;
}

function hls_local_playlist_sources(): array
{
    $pdo = hls_db_readonly();
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        $rows = $pdo->query("
            SELECT source
            FROM playlists
            WHERE type = 'url'
              AND source LIKE 'http%'
            ORDER BY created_at DESC
            LIMIT 500
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $exception) {
        hls_log('local-playlists-error', ['error' => $exception->getMessage()]);
        return [];
    }

    $sources = [];
    foreach ($rows as $source) {
        $source = trim((string) $source);
        if ($source !== '' && hls_is_http_url($source)) {
            $sources[] = $source;
        }
    }

    return $sources;
}

function hls_register_resolved_channel(string $channel, string $streamUrl): void
{
    if (hls_env('HLS_AUTO_REGISTER_CHANNELS', '1') === '0') {
        return;
    }

    $pdo = hls_db_writable();
    if (!$pdo instanceof PDO || !hls_db_table_exists($pdo, 'channels')) {
        return;
    }

    $id = 'hls_' . substr(sha1($channel), 0, 20);
    $name = hls_channel_display_name($channel);
    $now = date(DATE_ATOM);
    $streamType = hls_looks_like_manifest($streamUrl, '', '') ? 'hls' : 'stream';

    try {
        $stmt = $pdo->prepare('SELECT id FROM channels WHERE id = :id OR (url = :url AND name = :name) LIMIT 1');
        $stmt->execute([
            ':id' => $id,
            ':url' => $streamUrl,
            ':name' => $name,
        ]);
        $existingId = $stmt->fetchColumn();

        if (is_string($existingId) && $existingId !== '') {
            $stmt = $pdo->prepare('
                UPDATE channels
                SET name = :name,
                    url = :url,
                    source_id = :source_id,
                    source_title = :source_title,
                    stream_type = :stream_type,
                    health_status = CASE
                        WHEN health_status = "dead" THEN "unknown"
                        ELSE health_status
                    END,
                    working_url = :working_url,
                    updated_at = :updated_at
                WHERE id = :id
            ');
            $stmt->execute([
                ':id' => $existingId,
                ':name' => $name,
                ':url' => $streamUrl,
                ':source_id' => 'hls_auto_discovery',
                ':source_title' => 'HLS auto discovery',
                ':stream_type' => $streamType,
                ':working_url' => $streamUrl,
                ':updated_at' => $now,
            ]);
            hls_log('channel-registered-update', ['channel' => $channel, 'id' => $existingId]);
            return;
        }

        $stmt = $pdo->prepare('
            INSERT INTO channels (
                id, name, url, logo, genre, country, city, source_id, source_title,
                tvg_id, stream_type, health_status, last_checked_at, last_http_status,
                last_probe_error, working_url, updated_at
            ) VALUES (
                :id, :name, :url, "", "Автонайдено", "", "", :source_id, :source_title,
                "", :stream_type, "unknown", NULL, NULL, "", :working_url, :updated_at
            )
        ');
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':url' => $streamUrl,
            ':source_id' => 'hls_auto_discovery',
            ':source_title' => 'HLS auto discovery',
            ':stream_type' => $streamType,
            ':working_url' => $streamUrl,
            ':updated_at' => $now,
        ]);

        hls_log('channel-registered-insert', ['channel' => $channel, 'id' => $id]);
    } catch (Throwable $exception) {
        hls_log('channel-register-error', ['channel' => $channel, 'error' => $exception->getMessage()]);
    }
}

function hls_channel_display_name(string $channel): string
{
    $map = hls_channels_map();
    $name = trim((string) ($map[$channel][0] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $name = trim(str_replace(['-', '_', '.'], ' ', $channel));
    return $name !== '' ? ucwords($name) : $channel;
}

function hls_channels_map(): array
{
    $map = [];
    if (is_file(HLS_CHANNEL_ALIASES_FILE)) {
        hls_assert_data_file(HLS_CHANNEL_ALIASES_FILE);
        foreach (hls_read_config_lines(HLS_CHANNEL_ALIASES_FILE) as $line) {
            [$id, $aliases] = array_pad(explode('=', $line, 2), 2, '');
            $id = trim($id);
            if ($id === '') {
                continue;
            }

            $values = array_filter(array_map('trim', explode('|', $aliases)), static fn (string $value): bool => $value !== '');
            if ($values !== []) {
                $map[$id] = array_values($values);
            }
        }
    }

    return $map;
}

function hls_default_channel_aliases(string $channel): array
{
    $spaced = trim(str_replace(['-', '_', '.'], ' ', $channel));
    $aliases = [$channel];
    if ($spaced !== '' && $spaced !== $channel) {
        $aliases[] = $spaced;
        $aliases[] = ucwords($spaced);
    }

    return array_values(array_unique($aliases));
}

function hls_find_stream_in_m3u(string $m3uContent, array $searchNames): ?string
{
    $lines = preg_split('/\r\n|\r|\n/', $m3uContent) ?: [];
    $linesCount = count($lines);
    $normalizedSearchNames = hls_normalized_aliases($searchNames);

    for ($i = 0; $i < $linesCount; $i++) {
        $line = trim($lines[$i]);
        if (!str_starts_with($line, '#EXTINF') || !hls_extinf_matches_alias($line, $normalizedSearchNames)) {
            continue;
        }

        for ($j = $i + 1; $j < $linesCount; $j++) {
            $nextLine = trim($lines[$j]);
            if ($nextLine === '') {
                continue;
            }
            if (str_starts_with($nextLine, '#EXTINF')) {
                break;
            }
            if (str_starts_with($nextLine, '#')) {
                continue;
            }
            if (hls_is_http_url($nextLine)) {
                return $nextLine;
            }
        }
    }

    return null;
}

function hls_fetch_m3u_silent(string $url): ?string
{
    if (!hls_is_http_url($url)) {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => hls_header_lines([
            'Accept' => 'application/vnd.apple.mpegurl, application/x-mpegURL, audio/x-mpegurl, audio/*, */*',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        ]),
        CURLOPT_USERAGENT => 'MyIPTV/1.0 public playlist reader',
        CURLOPT_RANGE => '0-' . HLS_MAX_PLAYLIST_BYTES,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
        $options[CURLOPT_CONNECTTIMEOUT_MS] = 5000;
    } else {
        $options[CURLOPT_CONNECTTIMEOUT] = 5;
    }

    if (defined('CURLOPT_TIMEOUT_MS')) {
        $options[CURLOPT_TIMEOUT_MS] = 12000;
    } else {
        $options[CURLOPT_TIMEOUT] = 12;
    }

    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $status < 200 || $status >= 300 || strlen($body) > HLS_MAX_PLAYLIST_BYTES) {
        hls_log('playlist-fetch-error', ['url' => $url, 'status' => $status, 'error' => $error]);
        return null;
    }

    return $body;
}

function hls_discover_m3u_sources(array $searchNames): array
{
    if (hls_env('HLS_ENABLE_WEB_DISCOVERY', '1') === '0') {
        return [];
    }

    $normalizedAliases = hls_normalized_aliases($searchNames);
    if ($normalizedAliases === []) {
        return [];
    }

    $cacheKey = sha1(implode('|', $normalizedAliases));
    $cached = hls_discovery_cache_get($cacheKey);
    if ($cached !== null) {
        hls_log('discovery-cache-hit', ['key' => $cacheKey, 'sources' => count($cached)]);
        return $cached;
    }

    hls_log('discovery-cache-miss', ['key' => $cacheKey]);

    $sources = [];
    $pageCandidates = [];
    $providers = array_slice(hls_search_provider_templates(), 0, HLS_DISCOVERY_MAX_PROVIDERS);
    $queries = array_slice(hls_discovery_queries($searchNames), 0, HLS_DISCOVERY_MAX_QUERIES);

    foreach ($queries as $query) {
        foreach ($providers as $provider) {
            $searchUrl = str_replace('{query}', rawurlencode($query), $provider);
            $html = hls_fetch_discovery_page_silent($searchUrl);
            if ($html === null) {
                continue;
            }

            foreach (hls_extract_candidate_urls($html, $searchUrl) as $candidateUrl) {
                $candidateUrl = hls_clean_candidate_url($candidateUrl);
                if ($candidateUrl === null || !hls_is_public_http_url($candidateUrl)) {
                    continue;
                }

                if (hls_url_looks_like_m3u($candidateUrl)) {
                    $sources[$candidateUrl] = $candidateUrl;
                    if (count($sources) >= HLS_DISCOVERY_MAX_SOURCES) {
                        break 3;
                    }
                    continue;
                }

                if (count($pageCandidates) < HLS_DISCOVERY_MAX_PAGE_PROBES && hls_url_looks_like_probe_page($candidateUrl)) {
                    $pageCandidates[$candidateUrl] = $candidateUrl;
                }
            }
        }
    }

    foreach ($pageCandidates as $pageUrl) {
        if (count($sources) >= HLS_DISCOVERY_MAX_SOURCES) {
            break;
        }

        $html = hls_fetch_discovery_page_silent($pageUrl);
        if ($html === null) {
            continue;
        }

        foreach (hls_extract_candidate_urls($html, $pageUrl) as $candidateUrl) {
            $candidateUrl = hls_clean_candidate_url($candidateUrl);
            if ($candidateUrl !== null && hls_is_public_http_url($candidateUrl) && hls_url_looks_like_m3u($candidateUrl)) {
                $sources[$candidateUrl] = $candidateUrl;
                if (count($sources) >= HLS_DISCOVERY_MAX_SOURCES) {
                    break 2;
                }
            }
        }
    }

    $result = array_values($sources);
    hls_discovery_cache_set($cacheKey, $result);
    hls_log('discovery-finished', ['key' => $cacheKey, 'sources' => count($result)]);

    return $result;
}

function hls_search_provider_templates(): array
{
    return [
        'https://www.bing.com/search?q={query}',
        'https://duckduckgo.com/html/?q={query}',
        'https://search.yahoo.com/search?p={query}',
        'https://yandex.ru/search/?text={query}',
    ];
}

function hls_discovery_queries(array $searchNames): array
{
    $queries = [];
    foreach (array_slice($searchNames, 0, 4) as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }

        $queries[] = '"' . $name . '" "#EXTM3U"';
        $queries[] = '"' . $name . '" m3u iptv';
    }

    return array_values(array_unique($queries));
}

function hls_fetch_discovery_page_silent(string $url): ?string
{
    if (!hls_is_public_http_url($url)) {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => hls_header_lines([
            'Accept' => 'text/html, application/xhtml+xml, application/xml;q=0.9, text/plain;q=0.8, */*;q=0.7',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control' => 'no-cache',
        ]),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36 MyIPTVDiscovery',
        CURLOPT_RANGE => '0-' . HLS_MAX_DISCOVERY_BYTES,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
        $options[CURLOPT_CONNECTTIMEOUT_MS] = 4000;
    } else {
        $options[CURLOPT_CONNECTTIMEOUT] = 4;
    }

    if (defined('CURLOPT_TIMEOUT_MS')) {
        $options[CURLOPT_TIMEOUT_MS] = 8000;
    } else {
        $options[CURLOPT_TIMEOUT] = 8;
    }

    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $status < 200 || $status >= 400 || strlen($body) > HLS_MAX_DISCOVERY_BYTES) {
        hls_log('discovery-fetch-error', ['url' => $url, 'status' => $status, 'error' => $error]);
        return null;
    }

    return $body;
}

function hls_extract_candidate_urls(string $html, string $baseUrl): array
{
    $urls = [];
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/iu', $decoded, $matches)) {
        foreach ($matches[1] as $href) {
            $url = hls_url_from_href((string) $href, $baseUrl);
            if ($url !== null) {
                $urls[$url] = $url;
            }
        }
    }

    if (preg_match_all('~https?://[^\s<>"\']+~iu', $decoded, $matches)) {
        foreach ($matches[0] as $url) {
            $url = hls_clean_candidate_url((string) $url);
            if ($url !== null) {
                $urls[$url] = $url;
            }
        }
    }

    return array_values($urls);
}

function hls_url_from_href(string $href, string $baseUrl): ?string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
        return null;
    }

    $query = (string) (parse_url($href, PHP_URL_QUERY) ?: '');
    if ($query !== '') {
        parse_str($query, $params);
        foreach (['uddg', 'url', 'u', 'q', 'to', 'target'] as $key) {
            $value = $params[$key] ?? null;
            if (is_string($value) && hls_is_http_url($value)) {
                return hls_clean_candidate_url($value);
            }
        }
    }

    if (str_starts_with($href, '//')) {
        $scheme = (string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
        return hls_clean_candidate_url($scheme . ':' . $href);
    }

    if (hls_is_http_url($href)) {
        return hls_clean_candidate_url($href);
    }

    if (str_starts_with($href, '/')) {
        return hls_clean_candidate_url(hls_resolve_url($href, $baseUrl));
    }

    return null;
}

function hls_clean_candidate_url(string $url): ?string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $url = trim($url, " \t\n\r\0\x0B\"'<>),.;");
    $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
    if (!is_string($url) || !hls_is_http_url($url)) {
        return null;
    }

    $url = hls_transform_repository_url_to_raw($url);
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $clean = strtolower((string) $parts['scheme']) . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $clean .= ':' . $parts['port'];
    }
    $clean .= (string) ($parts['path'] ?? '/');
    if (isset($parts['query'])) {
        $clean .= '?' . $parts['query'];
    }

    return $clean;
}

function hls_transform_repository_url_to_raw(string $url): string
{
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');

    if ($host === 'github.com' && preg_match('~^/([^/]+)/([^/]+)/blob/([^/]+)/(.+\.(?:m3u8?|txt))$~i', $path, $match)) {
        return 'https://raw.githubusercontent.com/' . $match[1] . '/' . $match[2] . '/' . $match[3] . '/' . $match[4]
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    if ($host === 'gitlab.com' && preg_match('~^/([^/]+)/([^/]+)/-/blob/([^/]+)/(.+\.(?:m3u8?|txt))$~i', $path, $match)) {
        return 'https://gitlab.com/' . $match[1] . '/' . $match[2] . '/-/raw/' . $match[3] . '/' . $match[4]
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    return $url;
}

function hls_url_looks_like_m3u(string $url): bool
{
    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    return str_ends_with($path, '.m3u')
        || str_ends_with($path, '.m3u8')
        || str_ends_with($path, '.m3u.txt');
}

function hls_url_looks_like_probe_page(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));

    if ($host === '' || hls_host_is_search_engine($host) || hls_url_looks_like_m3u($url)) {
        return false;
    }

    foreach (['.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.css', '.js', '.zip', '.rar', '.7z', '.pdf', '.mp4', '.ts'] as $ext) {
        if (str_ends_with($path, $ext)) {
            return false;
        }
    }

    return true;
}

function hls_host_is_search_engine(string $host): bool
{
    foreach (['google.', 'bing.com', 'duckduckgo.com', 'yandex.', 'yahoo.com', 'search.brave.com'] as $needle) {
        if (str_contains($host, $needle)) {
            return true;
        }
    }

    return false;
}

function hls_auth_api_url(string $channel): string
{
    $template = hls_env('HLS_AUTH_API_TEMPLATE', HLS_AUTH_API_TEMPLATE_DEFAULT);
    if (str_contains($template, '{channel}')) {
        return str_replace('{channel}', rawurlencode($channel), $template);
    }

    $separator = str_contains($template, '?') ? '&' : '?';
    return $template . $separator . 'id=' . rawurlencode($channel);
}

function hls_cdn_headers(string $targetUrl): array
{
    $referer = hls_env('HLS_CDN_REFERER', 'https://example.com/');
    $origin = hls_env('HLS_CDN_ORIGIN', hls_origin_from_url($referer) ?: hls_origin_from_url($targetUrl));
    $cookie = hls_env('HLS_CDN_COOKIE', '');

    $headers = [
        'User-Agent' => hls_env(
            'HLS_CDN_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36 MyIPTV'
        ),
        'Accept' => 'application/vnd.apple.mpegurl, application/x-mpegURL, audio/*, video/mp2t, */*',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Referer' => $referer,
        'Origin' => $origin,
    ];

    if ($cookie !== '') {
        $headers['Cookie'] = $cookie;
    }

    return $headers;
}

function hls_http_get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): array
{
    if (!hls_is_http_url($url)) {
        hls_fail(400, 'Поддерживаются только HTTP/HTTPS URL.');
    }

    $timeoutSeconds = max(1, min(60, $timeoutSeconds));
    $connectTimeoutMs = min(10000, max(1000, (int) round($timeoutSeconds * 1000 / 3)));
    $requestTimeoutMs = $timeoutSeconds * 1000;

    $ch = curl_init($url);
    if ($ch === false) {
        hls_fail(500, 'Не удалось создать cURL handle.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => hls_header_lines($headers),
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
        $options[CURLOPT_CONNECTTIMEOUT_MS] = $connectTimeoutMs;
    } else {
        $options[CURLOPT_CONNECTTIMEOUT] = (int) ceil($connectTimeoutMs / 1000);
    }

    if (defined('CURLOPT_TIMEOUT_MS')) {
        $options[CURLOPT_TIMEOUT_MS] = $requestTimeoutMs;
    } else {
        $options[CURLOPT_TIMEOUT] = $timeoutSeconds;
    }

    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $effectiveUrl = trim((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) {
        hls_log('curl-error', ['url' => $url, 'error' => $error]);
        hls_fail(502, 'CDN/API не ответил: ' . ($error ?: 'ошибка cURL'));
    }

    if ($status >= 400) {
        hls_log('http-error', ['url' => $url, 'status' => $status]);
        hls_fail(502, 'CDN/API вернул HTTP ' . $status . '.');
    }

    if ($maxBytes > 0 && strlen($body) > $maxBytes) {
        hls_log('size-error', ['url' => $url, 'bytes' => strlen($body)]);
        hls_fail(502, 'Ответ CDN/API слишком большой.');
    }

    return [
        'body' => $body,
        'content_type' => strtolower(trim(explode(';', $contentType)[0])),
        'effective_url' => $effectiveUrl,
    ];
}

function hls_rewrite_manifest(string $manifest, string $manifestUrl): string
{
    $lines = preg_split('/\r\n|\r|\n/', $manifest) ?: [];
    $rewritten = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $rewritten[] = $line;
            continue;
        }

        if (str_starts_with($trimmed, '#')) {
            $rewritten[] = hls_rewrite_manifest_attributes($line, $manifestUrl);
            continue;
        }

        $absoluteUrl = hls_resolve_url($trimmed, $manifestUrl);
        $rewritten[] = hls_is_http_url($absoluteUrl) ? hls_proxy_url($absoluteUrl) : $line;
    }

    return implode("\n", $rewritten) . "\n";
}

function hls_rewrite_manifest_attributes(string $line, string $manifestUrl): string
{
    return preg_replace_callback('/URI="([^"]+)"/i', static function (array $match) use ($manifestUrl): string {
        $absoluteUrl = hls_resolve_url($match[1], $manifestUrl);
        if (!hls_is_http_url($absoluteUrl)) {
            return $match[0];
        }

        return 'URI="' . hls_proxy_url($absoluteUrl) . '"';
    }, $line) ?? $line;
}

function hls_proxy_url(string $absoluteUrl): string
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '') {
        $script = 'hls.php';
    }

    return $script
        . '?segment=' . rawurlencode(base64_encode($absoluteUrl))
        . '&sig=' . rawurlencode(hls_sign_segment_url($absoluteUrl));
}

function hls_decode_segment_url(string $encodedUrl): string
{
    $value = str_replace(' ', '+', trim($encodedUrl));
    $decoded = base64_decode($value, true);

    if (!is_string($decoded)) {
        $urlSafe = strtr($value, '-_', '+/');
        $urlSafe .= str_repeat('=', (4 - strlen($urlSafe) % 4) % 4);
        $decoded = base64_decode($urlSafe, true);
    }

    return is_string($decoded) ? trim($decoded) : '';
}

function hls_cache_get(string $channel): ?string
{
    $cache = hls_cache_read();
    $entry = $cache[$channel] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $url = trim((string) ($entry['url'] ?? ''));
    $expiresAt = (int) ($entry['expires_at'] ?? 0);
    if ($url === '' || $expiresAt <= time()) {
        return null;
    }

    return $url;
}

function hls_cache_set(string $channel, string $url, int $ttlSeconds): void
{
    $cache = hls_cache_read();
    $now = time();

    foreach ($cache as $key => $entry) {
        if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) <= $now) {
            unset($cache[$key]);
        }
    }

    $cache[$channel] = [
        'url' => $url,
        'expires_at' => $now + $ttlSeconds,
        'saved_at' => $now,
    ];

    hls_cache_write($cache);
}

function hls_cache_read(): array
{
    hls_assert_data_file(HLS_CACHE_FILE);
    if (!is_file(HLS_CACHE_FILE)) {
        return [];
    }

    $raw = file_get_contents(HLS_CACHE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $cache = json_decode($raw, true);
    return is_array($cache) ? $cache : [];
}

function hls_cache_write(array $cache): void
{
    hls_ensure_data_dir();
    hls_assert_data_file(HLS_CACHE_FILE);

    file_put_contents(
        HLS_CACHE_FILE,
        json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function hls_discovery_cache_get(string $key): ?array
{
    $cache = hls_discovery_cache_read();
    $entry = $cache[$key] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    if ((int) ($entry['expires_at'] ?? 0) <= time()) {
        return null;
    }

    $sources = $entry['sources'] ?? null;
    if (!is_array($sources)) {
        return null;
    }

    return array_values(array_filter(array_map('strval', $sources), 'hls_is_public_http_url'));
}

function hls_discovery_cache_set(string $key, array $sources): void
{
    $cache = hls_discovery_cache_read();
    $now = time();

    foreach ($cache as $entryKey => $entry) {
        if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) <= $now) {
            unset($cache[$entryKey]);
        }
    }

    $cache[$key] = [
        'sources' => array_values(array_slice(array_unique($sources), 0, HLS_DISCOVERY_MAX_SOURCES)),
        'expires_at' => $now + HLS_DISCOVERY_CACHE_TTL_SECONDS,
        'saved_at' => $now,
    ];

    hls_discovery_cache_write($cache);
}

function hls_discovery_cache_read(): array
{
    hls_assert_data_file(HLS_DISCOVERY_CACHE_FILE);
    if (!is_file(HLS_DISCOVERY_CACHE_FILE)) {
        return [];
    }

    $raw = file_get_contents(HLS_DISCOVERY_CACHE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $cache = json_decode($raw, true);
    return is_array($cache) ? $cache : [];
}

function hls_discovery_cache_write(array $cache): void
{
    hls_ensure_data_dir();
    hls_assert_data_file(HLS_DISCOVERY_CACHE_FILE);

    file_put_contents(
        HLS_DISCOVERY_CACHE_FILE,
        json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function hls_resolve_url(string $url, string $baseUrl): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || str_starts_with($url, 'data:')) {
        return $url;
    }
    if (hls_is_http_url($url)) {
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

    return hls_normalize_url_path($root . $dir . $url);
}

function hls_normalize_url_path(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $path = (string) ($parts['path'] ?? '/');
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
    if (isset($parts['fragment'])) {
        $normalized .= '#' . $parts['fragment'];
    }

    return $normalized;
}

function hls_looks_like_manifest(string $url, string $contentType, string $body): bool
{
    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));

    return str_ends_with($path, '.m3u8')
        || str_contains($contentType, 'mpegurl')
        || str_starts_with(ltrim($body), '#EXTM3U');
}

function hls_content_type(string $url, string $upstreamType): string
{
    if ($upstreamType !== '') {
        return $upstreamType;
    }

    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if (str_ends_with($path, '.ts')) {
        return 'video/mp2t';
    }
    if (str_ends_with($path, '.m4s')) {
        return 'video/iso.segment';
    }
    if (str_ends_with($path, '.mp4')) {
        return 'video/mp4';
    }
    if (str_ends_with($path, '.mp3')) {
        return 'audio/mpeg';
    }
    if (str_ends_with($path, '.aac')) {
        return 'audio/aac';
    }
    if (str_ends_with($path, '.m4a')) {
        return 'audio/mp4';
    }
    if (str_ends_with($path, '.flac')) {
        return 'audio/flac';
    }
    if (str_ends_with($path, '.wav')) {
        return 'audio/wav';
    }
    if (str_ends_with($path, '.opus')) {
        return 'audio/opus';
    }
    if (str_ends_with($path, '.oga') || str_ends_with($path, '.ogg')) {
        return 'audio/ogg';
    }
    if (str_ends_with($path, '.key')) {
        return 'application/octet-stream';
    }

    return 'application/octet-stream';
}

function hls_is_http_url(string $url): bool
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

function hls_is_public_http_url(string $url): bool
{
    if (!hls_is_http_url($url)) {
        return false;
    }

    $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?: ''), '[]'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    return true;
}

function hls_origin_from_url(string $url): string
{
    $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?: '');
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    if ($scheme === '' || $host === '') {
        return '';
    }

    $origin = $scheme . '://' . $host;
    $port = parse_url($url, PHP_URL_PORT);
    if (is_int($port)) {
        $origin .= ':' . $port;
    }

    return $origin;
}

function hls_header_lines(array $headers): array
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $lines[] = $name . ': ' . $value;
    }

    return $lines;
}

function hls_db_readonly(): ?PDO
{
    static $pdo = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($pdo === null) {
        return null;
    }

    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $pdo = null;
        return null;
    }

    hls_assert_data_file(HLS_DB_FILE);
    if (!is_file(HLS_DB_FILE) || filesize(HLS_DB_FILE) <= 0) {
        $pdo = null;
        return null;
    }

    try {
        $connection = new PDO('sqlite:' . HLS_DB_FILE);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $connection->exec('PRAGMA busy_timeout = 1000');
        $connection->exec('PRAGMA query_only = ON');
        $pdo = $connection;
        return $pdo;
    } catch (Throwable $exception) {
        hls_log('db-readonly-error', ['error' => $exception->getMessage()]);
        $pdo = null;
        return null;
    }
}

function hls_db_writable(): ?PDO
{
    static $pdo = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($pdo === null) {
        return null;
    }

    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $pdo = null;
        return null;
    }

    hls_assert_data_file(HLS_DB_FILE);
    if (!is_file(HLS_DB_FILE) || filesize(HLS_DB_FILE) <= 0) {
        $pdo = null;
        return null;
    }

    try {
        $connection = new PDO('sqlite:' . HLS_DB_FILE);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $connection->exec('PRAGMA busy_timeout = 1000');
        $pdo = $connection;
        return $pdo;
    } catch (Throwable $exception) {
        hls_log('db-writable-error', ['error' => $exception->getMessage()]);
        $pdo = null;
        return null;
    }
}

function hls_db_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        return $stmt->fetchColumn() === $table;
    } catch (Throwable $exception) {
        hls_log('db-table-check-error', ['table' => $table, 'error' => $exception->getMessage()]);
        return false;
    }
}

function hls_read_config_lines(string $path): array
{
    hls_assert_data_file($path);
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $clean[] = $line;
    }

    return $clean;
}

function hls_split_config_values(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $value) ?: [])));
}

function hls_normalized_aliases(array $aliases): array
{
    $normalized = [];
    foreach ($aliases as $alias) {
        $alias = hls_normalize_alias_text((string) $alias);
        if ($alias !== '' && strlen($alias) >= 3) {
            $normalized[$alias] = $alias;
        }
    }

    return array_values($normalized);
}

function hls_extinf_matches_alias(string $extinfLine, array $normalizedAliases): bool
{
    if ($normalizedAliases === []) {
        return false;
    }

    return hls_text_matches_aliases($extinfLine, $normalizedAliases);
}

function hls_text_matches_aliases(string $value, array $normalizedAliases): bool
{
    $line = hls_normalize_alias_text($value);
    foreach ($normalizedAliases as $alias) {
        if (str_contains($line, $alias)) {
            return true;
        }
    }

    return false;
}

function hls_normalize_alias_text(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(['Ё', 'ё'], ['Е', 'е'], $value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    if (!is_string($value)) {
        return '';
    }

    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function hls_send_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Range, Origin, Referer, User-Agent, Content-Type');
}

function hls_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
        return $value;
    }

    return $default;
}

function hls_sign_segment_url(string $url): string
{
    return hash_hmac('sha256', $url, hls_segment_secret());
}

function hls_segment_signature_valid(string $url, string $signature): bool
{
    if (hls_env('HLS_ALLOW_UNSIGNED_SEGMENTS', '') === '1') {
        return true;
    }

    return $signature !== '' && hash_equals(hls_sign_segment_url($url), $signature);
}

function hls_segment_secret(): string
{
    hls_ensure_data_dir();
    hls_assert_data_file(HLS_SEGMENT_SECRET_FILE);

    if (is_file(HLS_SEGMENT_SECRET_FILE)) {
        $secret = trim((string) file_get_contents(HLS_SEGMENT_SECRET_FILE));
        if ($secret !== '') {
            return $secret;
        }
    }

    $secret = bin2hex(random_bytes(32));
    file_put_contents(HLS_SEGMENT_SECRET_FILE, $secret . PHP_EOL, LOCK_EX);

    return $secret;
}

function hls_ensure_data_dir(): void
{
    if (!is_dir(HLS_DATA_DIR)) {
        mkdir(HLS_DATA_DIR, 0775, true);
    }

    $root = realpath(dirname(__DIR__));
    $data = realpath(HLS_DATA_DIR);
    if (!is_string($root) || !is_string($data) || !hls_path_is_inside($data, $root)) {
        hls_fail(500, 'Некорректный путь к data-каталогу.');
    }
}

function hls_assert_data_file(string $path): void
{
    $base = realpath(HLS_DATA_DIR);
    if (!is_string($base)) {
        $base = HLS_DATA_DIR;
    }

    $directory = dirname($path);
    $resolvedDirectory = realpath($directory);
    if (!is_string($resolvedDirectory)) {
        $resolvedDirectory = $directory;
    }

    if (!hls_path_is_inside($resolvedDirectory, $base)) {
        hls_fail(500, 'Файл кэша/лога находится вне data-каталога.');
    }
}

function hls_path_is_inside(string $path, string $base): bool
{
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return str_starts_with(strtolower($path), strtolower($base));
}

function hls_log(string $event, array $context = []): void
{
    hls_ensure_data_dir();
    hls_assert_data_file(HLS_LOG_FILE);

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $event;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $line .= PHP_EOL;

    file_put_contents(HLS_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function hls_fail(int $status, string $message): never
{
    http_response_code($status);
    hls_send_cors_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    hls_log('error', ['status' => $status, 'message' => $message]);
    echo $message;
    exit;
}
