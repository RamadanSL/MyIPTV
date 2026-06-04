<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

const STREAM_CACHE_FILE = DATA_DIR . '/stream_cache.json';
const STREAM_LOG_FILE = DATA_DIR . '/stream_resolver.log';
const STREAM_DEFAULT_TTL = 600;
const STREAM_MIN_TTL = 30;
const STREAM_MAX_TTL = 3600;
const STREAM_TTL_SAFETY_SECONDS = 30;
const STREAM_MAX_MANIFEST_BYTES = 8388608;
const STREAM_MAX_SEGMENT_BYTES = 52428800;

function parseOriginalStream(string $channelId): array
{
    $channel = find_channel($channelId);
    if (!$channel) {
        throw new RuntimeException('Канал не найден.');
    }
    $rules = stream_resolver_rules_for_channel($channel);
    if ((string) ($channel['access_type'] ?? '') === 'drm' && stream_channel_license_url($channel, $rules) === '') {
        $note = trim((string) ($channel['access_notes'] ?? ''));
        throw new RuntimeException($note !== '' ? $note : 'Канал защищен DRM. Добавь license_url/license_headers, чтобы плеер попробовал Shaka/EME.');
    }
    if ((string) ($channel['health_status'] ?? '') === 'dead') {
        $reason = trim((string) ($channel['last_probe_error'] ?? ''));
        throw new RuntimeException('Канал помечен как мертвый' . ($reason !== '' ? ': ' . $reason : '.'));
    }

    $configured = stream_resolve_from_local_config($channel);
    if ($configured !== null) {
        return $configured;
    }

    $url = trim((string) ($channel['working_url'] ?? ''));
    if ($url === '') {
        $url = trim((string) ($channel['url'] ?? ''));
    }
    if (!stream_is_http_url($url)) {
        throw new RuntimeException('У канала нет HTTP/HTTPS stream URL.');
    }

    return [
        'url' => $url,
        'headers' => stream_channel_headers($channel, $url),
        'drm_system' => stream_channel_drm_system($channel),
        'license_url' => stream_channel_license_proxy_url((string) ($channel['id'] ?? ''), $channel),
        'ttl' => stream_ttl_from_url($url, STREAM_DEFAULT_TTL),
        'channel' => $channel,
        'source' => 'channel_url',
    ];
}

function stream_resolve_channel(string $channelId, bool $forceRefresh = false): array
{
    if (!$forceRefresh) {
        $cached = stream_cache_get($channelId);
        if ($cached !== null) {
            stream_log('cache-hit', ['channel' => $channelId, 'url' => stream_log_url((string) $cached['url'])]);
            return $cached;
        }
    }

    stream_log('cache-miss', ['channel' => $channelId]);
    $resolved = parseOriginalStream($channelId);
    $ttl = max(STREAM_MIN_TTL, min(STREAM_MAX_TTL, (int) ($resolved['ttl'] ?? STREAM_DEFAULT_TTL)));
    $resolved['expires_at'] = time() + $ttl;
    stream_cache_set($channelId, $resolved);
    stream_log('resolved', [
        'channel' => $channelId,
        'ttl' => $ttl,
        'source' => (string) ($resolved['source'] ?? ''),
        'url' => stream_log_url((string) ($resolved['url'] ?? '')),
    ]);

    return $resolved;
}

function stream_resolve_from_local_config(array $channel): ?array
{
    $rules = stream_resolver_rules_for_channel($channel);
    if (!$rules) {
        return null;
    }

    $mode = (string) ($rules['mode'] ?? 'static');
    if ($mode === 'static') {
        $url = trim((string) ($rules['url'] ?? ''));
        if (!stream_is_http_url($url)) {
            throw new RuntimeException('stream_resolvers.json: static url некорректен.');
        }

        return [
            'url' => $url,
            'headers' => stream_headers_from_config($url, $rules['headers'] ?? []),
            'drm_system' => stream_channel_drm_system($channel, $rules),
            'license_url' => stream_channel_license_proxy_url((string) ($channel['id'] ?? ''), $channel, $rules),
            'ttl' => stream_ttl_from_url($url, (int) ($rules['ttl'] ?? STREAM_DEFAULT_TTL)),
            'channel' => $channel,
            'source' => 'config_static',
        ];
    }

    if ($mode === 'http' || $mode === 'browser') {
        return stream_resolve_via_http_rule($channel, $rules);
    }

    throw new RuntimeException('stream_resolvers.json: неизвестный режим resolver.');
}

function stream_resolver_rules_for_channel(array $channel): array
{
    $configFile = DATA_DIR . '/stream_resolvers.json';
    if (!is_file($configFile)) {
        return [];
    }

    $config = json_decode((string) file_get_contents($configFile), true);
    if (!is_array($config)) {
        return [];
    }

    $channelId = (string) ($channel['id'] ?? '');
    $rules = $config['channels'][$channelId] ?? null;
    return is_array($rules) ? $rules : [];
}

function stream_resolve_via_http_rule(array $channel, array $rules): array
{
    $endpoint = trim((string) ($rules['endpoint'] ?? ''));
    if (!stream_is_http_url($endpoint)) {
        throw new RuntimeException('HTTP resolver endpoint некорректен.');
    }

    $method = strtoupper((string) ($rules['method'] ?? 'GET'));
    $headers = stream_headers_from_config($endpoint, $rules['headers'] ?? []);
    $body = isset($rules['body']) ? (string) $rules['body'] : null;
    $response = stream_http_request($endpoint, $headers, $method, $body, STREAM_MAX_MANIFEST_BYTES);
    $content = (string) $response['body'];

    $url = '';
    if (!empty($rules['json_path'])) {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $url = stream_json_path($decoded, (string) $rules['json_path']);
        }
    }
    if ($url === '' && !empty($rules['regex'])) {
        $regex = (string) $rules['regex'];
        if (@preg_match($regex, $content, $match) && !empty($match[1])) {
            $url = (string) $match[1];
        }
    }

    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (!stream_is_http_url($url)) {
        throw new RuntimeException('HTTP resolver не вернул корректную m3u8-ссылку.');
    }

    return [
        'url' => $url,
        'headers' => stream_headers_from_config($url, $rules['stream_headers'] ?? $rules['headers'] ?? []),
        'drm_system' => stream_channel_drm_system($channel, $rules),
        'license_url' => stream_channel_license_proxy_url((string) ($channel['id'] ?? ''), $channel, $rules),
        'ttl' => stream_ttl_from_url($url, (int) ($rules['ttl'] ?? STREAM_DEFAULT_TTL)),
        'channel' => $channel,
        'source' => 'config_http',
    ];
}

function stream_json_path(array $data, string $path): string
{
    $current = $data;
    foreach (explode('.', trim($path, '.')) as $part) {
        if ($part === '' || !is_array($current) || !array_key_exists($part, $current)) {
            return '';
        }
        $current = $current[$part];
    }

    return is_scalar($current) ? (string) $current : '';
}

function stream_http_request(string $url, array $headers = [], string $method = 'GET', ?string $body = null, int $maxBytes = STREAM_MAX_SEGMENT_BYTES): array
{
    $ch = curl_init($url);
    $headerLines = stream_header_lines($headers);
    $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '') {
        $headerLines[] = 'Range: ' . $range;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headerLines,
    ]);

    if ($body !== null && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Источник не ответил: ' . ($error ?: 'пустой ответ'));
    }

    $responseBody = substr($raw, $headerSize);
    if ($status >= 400) {
        throw new RuntimeException('Источник вернул HTTP ' . $status . '.');
    }
    if (strlen($responseBody) > $maxBytes) {
        throw new RuntimeException('Ответ источника слишком большой.');
    }

    return [
        'status' => $status,
        'content_type' => trim(explode(';', $contentType)[0]),
        'body' => $responseBody,
        'effective_url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
    ];
}

function stream_rewrite_m3u8(string $playlist, string $currentUrl, string $channelId): string
{
    $lines = preg_split('/\r\n|\r|\n/', $playlist) ?: [];
    $rewritten = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $rewritten[] = $line;
            continue;
        }

        if (str_starts_with($trimmed, '#')) {
            $rewritten[] = preg_replace_callback('/URI="([^"]+)"/i', static function (array $match) use ($currentUrl, $channelId): string {
                $absolute = stream_resolve_url($match[1], $currentUrl);
                return 'URI="' . stream_proxy_url($channelId, $absolute) . '"';
            }, $line) ?? $line;
            continue;
        }

        $absolute = stream_resolve_url($trimmed, $currentUrl);
        $rewritten[] = stream_proxy_url($channelId, $absolute);
    }

    return implode("\n", $rewritten) . "\n";
}

function stream_proxy_url(string $channelId, string $targetUrl): string
{
    return stream_public_path('api/proxy.php')
        . '?channel=' . rawurlencode($channelId)
        . '&u=' . stream_base64url_encode($targetUrl)
        . '&sig=' . rawurlencode(stream_sign_url($channelId, $targetUrl));
}

function stream_playlist_url(string $channelId, string $mode = 'proxy'): string
{
    $endpoint = $mode === 'redirect' ? 'api/stream.php' : 'api/proxy.php';
    return stream_absolute_url(stream_public_path($endpoint . '?channel=' . rawurlencode($channelId)));
}

function stream_public_path(string $path): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(dirname($script), '/');
    if ($base === '.' || $base === '/') {
        $base = '';
    }
    if ($base === '/api' || str_ends_with($base, '/api')) {
        $base = substr($base, 0, -4);
    }

    return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
}

function stream_absolute_url(string $path): string
{
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    $path = preg_replace('~^\./~', '', $path) ?? $path;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function stream_looks_like_playlist(string $url, string $contentType, string $body): bool
{
    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    return str_ends_with($path, '.m3u8')
        || str_contains(strtolower($contentType), 'mpegurl')
        || str_starts_with(ltrim($body), '#EXTM3U');
}

function stream_content_type_from_url(string $url): string
{
    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if (str_ends_with($path, '.m3u8')) {
        return 'application/vnd.apple.mpegurl';
    }
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

    return 'application/octet-stream';
}

function stream_default_headers(string $url): array
{
    return [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125 Safari/537.36 MyIPTV',
        'Accept' => 'application/vnd.apple.mpegurl, application/x-mpegURL, audio/*, video/*, */*',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Referer' => stream_origin($url),
        'Origin' => rtrim(stream_origin($url), '/'),
    ];
}

function stream_channel_headers(array $channel, string $url): array
{
    $headers = stream_default_headers($url);
    $raw = trim((string) ($channel['stream_headers'] ?? ''));
    if ($raw === '') {
        return $headers;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $headers;
    }

    foreach ($decoded as $name => $value) {
        $name = trim((string) $name);
        $value = trim((string) $value);
        if ($name === '' || $value === '' || preg_match('/[\r\n:]/', $name) || preg_match('/[\r\n]/', $value)) {
            continue;
        }
        $headers[$name] = $value;
    }

    return $headers;
}

function stream_channel_drm_system(array $channel, array $rules = []): string
{
    $value = trim((string) ($rules['drm_system'] ?? $rules['key_system'] ?? $channel['drm_system'] ?? ''));
    return $value !== '' ? channel_drm_system($value) : 'com.widevine.alpha';
}

function stream_channel_license_url(array $channel, array $rules = []): string
{
    $url = trim((string) ($rules['license_url'] ?? $rules['license_server'] ?? $channel['license_url'] ?? ''));
    return stream_is_http_url($url) ? $url : '';
}

function stream_channel_license_headers(array $channel, array $rules = []): array
{
    $headers = [];
    $raw = $rules['license_headers'] ?? $channel['license_headers'] ?? '';
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (is_array($raw)) {
        foreach ($raw as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name !== '' && $value !== '' && !preg_match('/[\r\n:]/', $name) && !preg_match('/[\r\n]/', $value)) {
                $headers[$name] = $value;
            }
        }
    }

    return $headers;
}

function stream_channel_license_proxy_url(string $channelId, array $channel, array $rules = []): string
{
    if ($channelId === '' || stream_channel_license_url($channel, $rules) === '') {
        return '';
    }

    return stream_absolute_url(stream_public_path('api/license.php?channel=' . rawurlencode($channelId)));
}

function stream_headers_from_config(string $url, mixed $headers): array
{
    $result = stream_default_headers($url);
    if (is_array($headers)) {
        foreach ($headers as $key => $value) {
            $key = trim((string) $key);
            if ($key !== '') {
                $result[$key] = (string) $value;
            }
        }
    }

    return $result;
}

function stream_header_lines(array $headers): array
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $name = trim((string) $name);
        $value = (string) $value;
        if ($name === '' || trim($value) === '') {
            continue;
        }
        $lines[] = $name . ': ' . $value;
    }

    return $lines;
}

function stream_ttl_from_url(string $url, int $fallback): int
{
    $candidates = [];
    $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
    parse_str($query, $params);
    foreach (['expires', 'expire', 'exp', 'e', 'end', 'token_expires', 'tokenExpires'] as $key) {
        if (isset($params[$key]) && is_scalar($params[$key])) {
            $candidates[] = (string) $params[$key];
        }
    }
    if (preg_match_all('~(?:^|[?&;:/_-])exp(?:ires)?[=/:-](\d{10,13})~i', $url, $matches)) {
        foreach ($matches[1] as $match) {
            $candidates[] = $match;
        }
    }

    foreach ($candidates as $candidate) {
        if (!preg_match('~^\d{10,13}$~', $candidate)) {
            continue;
        }
        $timestamp = (int) $candidate;
        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }
        $ttl = $timestamp - time() - STREAM_TTL_SAFETY_SECONDS;
        if ($ttl > STREAM_MIN_TTL) {
            return min(STREAM_MAX_TTL, $ttl);
        }
    }

    return max(STREAM_MIN_TTL, min(STREAM_MAX_TTL, $fallback - STREAM_TTL_SAFETY_SECONDS));
}

function stream_cache_get(string $channelId): ?array
{
    $redis = stream_redis();
    if ($redis !== null) {
        $raw = $redis->get(stream_cache_key($channelId));
        $item = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($item) ? $item : null;
    }

    $cache = stream_cache_read();
    $item = $cache[$channelId] ?? null;
    if (!is_array($item)) {
        return null;
    }
    if ((int) ($item['expires_at'] ?? 0) <= time()) {
        unset($cache[$channelId]);
        stream_cache_write($cache);
        return null;
    }

    return $item;
}

function stream_cache_set(string $channelId, array $value): void
{
    $item = [
        'url' => (string) ($value['url'] ?? ''),
        'headers' => is_array($value['headers'] ?? null) ? $value['headers'] : [],
        'drm_system' => (string) ($value['drm_system'] ?? ''),
        'license_url' => (string) ($value['license_url'] ?? ''),
        'expires_at' => (int) ($value['expires_at'] ?? (time() + STREAM_DEFAULT_TTL)),
        'source' => (string) ($value['source'] ?? ''),
    ];

    $redis = stream_redis();
    if ($redis !== null) {
        $ttl = max(STREAM_MIN_TTL, (int) $item['expires_at'] - time());
        $redis->setex(stream_cache_key($channelId), $ttl, json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return;
    }

    $cache = stream_cache_read();
    $cache[$channelId] = $item;
    stream_cache_write($cache);
}

function stream_cache_key(string $channelId): string
{
    return 'myiptv:stream:' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', $channelId);
}

function stream_redis(): ?object
{
    static $redis = false;
    if ($redis !== false) {
        return $redis;
    }
    if (!class_exists('Redis')) {
        $redis = null;
        return null;
    }

    $url = getenv('REDIS_URL') ?: '';
    $host = getenv('REDIS_HOST') ?: '';
    if ($url === '' && $host === '') {
        $redis = null;
        return null;
    }

    $parts = $url !== '' ? parse_url($url) : [];
    $host = (string) ($parts['host'] ?? $host ?: '127.0.0.1');
    $port = (int) ($parts['port'] ?? (getenv('REDIS_PORT') ?: 6379));
    $timeout = (float) (getenv('REDIS_TIMEOUT') ?: 1.5);

    try {
        $client = new Redis();
        $client->connect($host, $port, $timeout);
        $password = (string) ($parts['pass'] ?? getenv('REDIS_PASSWORD') ?: '');
        if ($password !== '') {
            $client->auth($password);
        }
        $db = isset($parts['path']) ? (int) trim((string) $parts['path'], '/') : (int) (getenv('REDIS_DB') ?: 0);
        if ($db > 0) {
            $client->select($db);
        }
        $redis = $client;
        return $redis;
    } catch (Throwable $exception) {
        stream_log('redis-disabled', ['error' => $exception->getMessage()]);
        $redis = null;
        return null;
    }
}

function stream_cache_read(): array
{
    if (!is_file(STREAM_CACHE_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(STREAM_CACHE_FILE), true);
    return is_array($data) ? $data : [];
}

function stream_cache_write(array $cache): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    file_put_contents(STREAM_CACHE_FILE, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
}

function stream_sign_url(string $channelId, string $targetUrl): string
{
    return hash_hmac('sha256', $channelId . "\n" . $targetUrl, stream_secret());
}

function stream_signature_valid(string $channelId, string $targetUrl, string $signature): bool
{
    return hash_equals(stream_sign_url($channelId, $targetUrl), $signature);
}

function stream_secret(): string
{
    $file = DATA_DIR . '/stream_proxy.secret';
    if (is_file($file)) {
        return trim((string) file_get_contents($file));
    }

    $secret = bin2hex(random_bytes(32));
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    file_put_contents($file, $secret . PHP_EOL, LOCK_EX);
    return $secret;
}

function stream_channel_id_from_request(): string
{
    $channel = trim((string) ($_GET['channel'] ?? $_GET['channel_id'] ?? ''));
    if ($channel !== '') {
        return $channel;
    }

    $path = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
    if ($path === '') {
        return '';
    }

    return (string) preg_replace('~/.*$~', '', $path);
}

function stream_resolve_url(string $url, string $baseUrl): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (stream_is_http_url($url)) {
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
        return stream_maybe_inherit_query($root . $url, $url, $baseUrl);
    }

    $dir = isset($base['path']) ? preg_replace('~/[^/]*$~', '/', $base['path']) : '/';
    if (!is_string($dir) || $dir === '') {
        $dir = '/';
    }

    return stream_maybe_inherit_query(stream_normalize_url_path($root . $dir . $url), $url, $baseUrl);
}

function stream_maybe_inherit_query(string $resolvedUrl, string $relativeUrl, string $baseUrl): string
{
    if (str_contains($relativeUrl, '?') || !empty(parse_url($resolvedUrl, PHP_URL_QUERY))) {
        return $resolvedUrl;
    }

    $query = (string) (parse_url($baseUrl, PHP_URL_QUERY) ?: '');
    if ($query === '') {
        return $resolvedUrl;
    }

    if (!preg_match('~(?:token|sign|sig|expires|expire|hdnts|auth|key|session|st=|e=)~i', $query)) {
        return $resolvedUrl;
    }

    return $resolvedUrl . '?' . $query;
}

function stream_normalize_url_path(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $segments = [];
    foreach (explode('/', $parts['path'] ?? '/') as $segment) {
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

function stream_origin(string $url): string
{
    $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?: 'https');
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    if ($host === '') {
        return '';
    }

    $origin = $scheme . '://' . $host;
    $port = parse_url($url, PHP_URL_PORT);
    if (is_int($port)) {
        $origin .= ':' . $port;
    }

    return $origin . '/';
}

function stream_is_http_url(string $url): bool
{
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    return in_array($scheme, ['http', 'https'], true) && !empty($parts['host']);
}

function stream_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function stream_base64url_decode(string $value): string|false
{
    $padded = strtr($value, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    return base64_decode($padded, true);
}

function stream_log(string $event, array $context = []): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    $line = json_encode([
        'time' => date(DATE_ATOM),
        'event' => $event,
        'context' => $context,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents(STREAM_LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function stream_log_url(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['path']) ? $parts['path'] : '');
}

function stream_error(int $status, string $message): never
{
    http_response_code($status);
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}
