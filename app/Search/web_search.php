<?php
declare(strict_types=1);

const WEB_SEARCH_MAX_QUERY_VARIANTS = 5;
const WEB_SEARCH_MAX_RESULTS_PER_ALIAS = 24;
const WEB_SEARCH_FETCH_BYTES = 3145728;
const WEB_SEARCH_FETCH_TIMEOUT = 10;

function default_web_search_providers(): array
{
    return [
        [
            'title' => 'Bing RSS',
            'url_template' => 'https://www.bing.com/search?q={query}&format=rss',
            'enabled' => true,
        ],
        [
            'title' => 'Bing HTML',
            'url_template' => 'https://www.bing.com/search?q={query}',
            'enabled' => true,
        ],
        [
            'title' => 'DuckDuckGo HTML',
            'url_template' => 'https://duckduckgo.com/html/?q={query}',
            'enabled' => true,
        ],
        [
            'title' => 'Mojeek',
            'url_template' => 'https://www.mojeek.com/search?q={query}',
            'enabled' => true,
        ],
    ];
}

function seed_default_web_search_providers(): array
{
    ensure_app_storage();

    $summary = [
        'added' => 0,
        'skipped' => 0,
    ];

    foreach (default_web_search_providers() as $provider) {
        $template = web_search_normalize_provider_template((string) $provider['url_template']);
        if (web_search_provider_template_exists($template)) {
            $summary['skipped']++;
            continue;
        }

        add_web_search_provider(
            (string) $provider['title'],
            $template,
            !empty($provider['enabled'])
        );
        $summary['added']++;
    }

    return $summary;
}

function make_web_search_provider_id(string $seed): string
{
    return 'wsp_' . substr(sha1(text_lower($seed) . microtime(true)), 0, 18);
}

function add_web_search_provider(string $title, string $urlTemplate, bool $enabled = true): array
{
    ensure_app_storage();

    $title = clean_text($title);
    $urlTemplate = web_search_normalize_provider_template($urlTemplate);
    if ($title === '') {
        $title = web_search_title_from_template($urlTemplate);
    }
    if ($urlTemplate === '' || !str_contains($urlTemplate, '{query}')) {
        throw new InvalidArgumentException('Нужна ссылка поисковика или URL-шаблон с {query}.');
    }

    $testUrl = web_search_template_url($urlTemplate, 'test');
    if (!web_search_is_http_url($testUrl)) {
        throw new InvalidArgumentException('URL-шаблон должен давать http:// или https:// ссылку.');
    }

    $provider = [
        'id' => make_web_search_provider_id($urlTemplate),
        'title' => $title,
        'url_template' => $urlTemplate,
        'enabled' => $enabled ? 1 : 0,
        'created_at' => date(DATE_ATOM),
        'updated_at' => null,
        'last_error' => null,
        'last_result_count' => 0,
    ];

    $stmt = db()->prepare('
        INSERT INTO web_search_providers (
            id, title, url_template, enabled, created_at, updated_at, last_error, last_result_count
        ) VALUES (
            :id, :title, :url_template, :enabled, :created_at, :updated_at, :last_error, :last_result_count
        )
    ');
    $stmt->execute([
        ':id' => $provider['id'],
        ':title' => $provider['title'],
        ':url_template' => $provider['url_template'],
        ':enabled' => $provider['enabled'],
        ':created_at' => $provider['created_at'],
        ':updated_at' => $provider['updated_at'],
        ':last_error' => $provider['last_error'],
        ':last_result_count' => $provider['last_result_count'],
    ]);

    return $provider;
}

function web_search_provider_template_exists(string $urlTemplate): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM web_search_providers WHERE url_template = :url_template');
    $stmt->execute([':url_template' => $urlTemplate]);
    return (int) $stmt->fetchColumn() > 0;
}

function web_search_normalize_provider_template(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (str_contains($url, '{query}')) {
        return $url;
    }

    if (!web_search_is_http_url($url)) {
        return $url;
    }

    $parts = parse_url($url);
    $host = text_lower((string) ($parts['host'] ?? ''));
    $host = preg_replace('~^www\.~', '', $host) ?? $host;

    if (str_contains($host, 'bing.com')) {
        return 'https://www.bing.com/search?q={query}&format=rss';
    }
    if (str_contains($host, 'google.')) {
        return 'https://www.google.com/search?q={query}';
    }
    if (str_contains($host, 'yandex.')) {
        return 'https://yandex.ru/search/?text={query}';
    }
    if (str_contains($host, 'duckduckgo.com')) {
        return 'https://duckduckgo.com/html/?q={query}';
    }
    if (str_contains($host, 'yahoo.')) {
        return 'https://search.yahoo.com/search?p={query}';
    }
    if (str_contains($host, 'mojeek.com')) {
        return 'https://www.mojeek.com/search?q={query}';
    }
    if (str_contains($host, 'brave.com')) {
        return 'https://search.brave.com/search?q={query}';
    }

    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'q={query}';
}

function web_search_title_from_template(string $template): string
{
    $host = text_lower((string) (parse_url($template, PHP_URL_HOST) ?: ''));
    $host = preg_replace('~^www\.~', '', $host) ?? $host;

    if (str_contains($host, 'bing.com')) {
        return str_contains($template, 'format=rss') ? 'Bing RSS' : 'Bing HTML';
    }
    if (str_contains($host, 'google.')) {
        return 'Google';
    }
    if (str_contains($host, 'yandex.')) {
        return 'Yandex';
    }
    if (str_contains($host, 'duckduckgo.com')) {
        return 'DuckDuckGo HTML';
    }
    if (str_contains($host, 'yahoo.')) {
        return 'Yahoo';
    }
    if (str_contains($host, 'mojeek.com')) {
        return 'Mojeek';
    }
    if (str_contains($host, 'brave.com')) {
        return 'Brave';
    }

    return $host !== '' ? $host : 'Веб-поиск';
}

function delete_web_search_provider(string $id): void
{
    ensure_app_storage();
    $stmt = db()->prepare('DELETE FROM web_search_providers WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function toggle_web_search_provider(string $id, bool $enabled): void
{
    ensure_app_storage();
    $stmt = db()->prepare('
        UPDATE web_search_providers
        SET enabled = :enabled, updated_at = :updated_at
        WHERE id = :id
    ');
    $stmt->execute([
        ':enabled' => $enabled ? 1 : 0,
        ':updated_at' => date(DATE_ATOM),
        ':id' => $id,
    ]);
}

function load_web_search_providers(bool $enabledOnly = true, int $limit = 100): array
{
    ensure_app_storage();
    $sql = 'SELECT * FROM web_search_providers';
    if ($enabledOnly) {
        $sql .= ' WHERE enabled = 1';
    }
    $sql .= ' ORDER BY created_at DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }

    return db()->query($sql)->fetchAll();
}

function web_search_provider_error_summary(int $limit = 3): string
{
    $errors = [];
    foreach (load_web_search_providers(true) as $provider) {
        $error = clean_text((string) ($provider['last_error'] ?? ''));
        if ($error === '') {
            continue;
        }
        $errors[] = (string) ($provider['title'] ?? 'поисковик') . ': ' . $error;
        if (count($errors) >= $limit) {
            break;
        }
    }

    return implode(' | ', $errors);
}

function web_search_alias_results(string $alias, ?callable $progress = null): array
{
    $alias = clean_text($alias);
    if ($alias === '') {
        return [];
    }

    $providers = load_web_search_providers(true);
    if (!$providers) {
        return [];
    }

    $results = [];
    $seen = [];
    foreach ($providers as $provider) {
        $providerCount = 0;
        $rawCount = 0;
        $filteredCount = 0;
        $lastError = null;
        foreach (web_search_query_variants($alias) as $query) {
            if ($progress) {
                $progress('Веб-поиск: ' . (string) ($provider['title'] ?? 'поисковик') . ' -> ' . $query);
            }

            $url = web_search_template_url((string) ($provider['url_template'] ?? ''), $query);
            try {
                $content = web_search_fetch($url);
                $blockReason = web_search_block_reason($content, $url);
                if ($blockReason !== null) {
                    throw new RuntimeException($blockReason);
                }

                $items = web_search_extract_result_urls($content, $url);
                $rawCount += count($items);
                if (!$items) {
                    $lastError = 'Поисковик ответил, но ссылок в выдаче не найдено.';
                    continue;
                }

                foreach ($items as $item) {
                    $itemUrl = (string) ($item['url'] ?? '');
                    if ($itemUrl === '' || web_search_result_is_noise($itemUrl)) {
                        $filteredCount++;
                        continue;
                    }
                    $key = text_lower($itemUrl);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $results[] = [
                        'title' => clean_text((string) ($item['title'] ?? '')),
                        'url' => $itemUrl,
                        'provider_id' => (string) ($provider['id'] ?? ''),
                        'provider_title' => (string) ($provider['title'] ?? ''),
                        'query' => $query,
                    ];
                    $providerCount++;
                    if (count($results) >= WEB_SEARCH_MAX_RESULTS_PER_ALIAS) {
                        web_search_update_provider_status((string) ($provider['id'] ?? ''), $providerCount, null);
                        return $results;
                    }
                }
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }
        }

        if ($providerCount > 0) {
            web_search_update_provider_status((string) ($provider['id'] ?? ''), $providerCount, null);
        } elseif ($lastError !== null) {
            web_search_update_provider_status((string) ($provider['id'] ?? ''), 0, $lastError);
        } elseif ($rawCount > 0) {
            web_search_update_provider_status(
                (string) ($provider['id'] ?? ''),
                0,
                'Поисковик дал ' . $rawCount . ' ссылок, но все они внутренние/служебные или отфильтрованы.'
            );
        } else {
            web_search_update_provider_status((string) ($provider['id'] ?? ''), 0, 'Поисковик не дал внешних ссылок.');
        }
    }

    return $results;
}

function web_search_query_variants(string $alias): array
{
    $alias = clean_text($alias);
    $variants = [
        $alias,
        $alias . ' m3u8',
        $alias . ' m3u',
        $alias . ' iptv',
        $alias . ' плейлист',
        $alias . ' эфир',
    ];

    $unique = [];
    foreach ($variants as $variant) {
        $variant = clean_text($variant);
        if ($variant !== '') {
            $unique[$variant] = true;
        }
    }

    return array_slice(array_keys($unique), 0, WEB_SEARCH_MAX_QUERY_VARIANTS);
}

function web_search_template_url(string $template, string $query): string
{
    $encoded = rawurlencode($query);
    $plus = str_replace('%20', '+', $encoded);

    return str_replace(
        ['{query}', '{query_plus}', '{q}', '{alias}'],
        [$encoded, $plus, $encoded, $encoded],
        $template
    );
}

function web_search_fetch(string $url): string
{
    if (!web_search_is_http_url($url)) {
        throw new RuntimeException('Недопустимый URL поисковика.');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => WEB_SEARCH_FETCH_TIMEOUT,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_USERAGENT => function_exists('health_user_agent') ? health_user_agent() : 'MyIPTV/1.0 web search',
            CURLOPT_HTTPHEADER => web_search_request_headers(),
            CURLOPT_RANGE => '0-' . WEB_SEARCH_FETCH_BYTES,
            CURLOPT_ENCODING => '',
        ]);
        $content = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Пустой ответ поисковика: ' . ($error ?: 'нет данных'));
        }
        if ($status >= 400) {
            throw new RuntimeException('Поисковик вернул HTTP ' . $status . '.');
        }

        return web_search_normalize_content($content);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => WEB_SEARCH_FETCH_TIMEOUT,
            'header' => "User-Agent: MyIPTV/1.0 web search\r\n"
                . implode("\r\n", web_search_request_headers()) . "\r\n",
        ],
    ]);
    $content = file_get_contents($url, false, $context, 0, WEB_SEARCH_FETCH_BYTES + 1);
    if ($content === false || $content === '') {
        throw new RuntimeException('Не удалось скачать страницу поисковика.');
    }

    return web_search_normalize_content($content);
}

function web_search_extract_result_urls(string $content, string $baseUrl): array
{
    $trimmed = ltrim($content);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $json = json_decode($content, true);
        if (is_array($json)) {
            return web_search_extract_json_urls($json);
        }
    }

    if (preg_match('~^\s*<(?:\?xml|rss|feed|urlset)\b~iu', $content) === 1) {
        $xmlItems = web_search_extract_xml_urls($content);
        if ($xmlItems) {
            return $xmlItems;
        }
    }

    $items = [];
    if (preg_match_all('~<a\b([^>]*)>(.*?)</a>~isu', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $attrs = function_exists('parse_html_attributes') ? parse_html_attributes($match[1]) : web_search_parse_html_attributes($match[1]);
            $href = (string) ($attrs['href'] ?? $attrs['data-href'] ?? $attrs['data-url'] ?? '');
            $url = web_search_unwrap_result_url(web_search_resolve_url($href, $baseUrl));
            if ($url === '' || !web_search_is_http_url($url)) {
                continue;
            }
            $label = function_exists('html_text') ? html_text($match[2]) : clean_text(strip_tags($match[2]));
            $items[] = [
                'title' => $label,
                'url' => $url,
            ];
        }
    }

    if (preg_match_all('~https?://[^\s"\'<>]+~iu', $content, $matches)) {
        foreach ($matches[0] as $url) {
            $url = web_search_unwrap_result_url(trim($url, " \t\n\r\0\x0B\"'()[]{}<>,"));
            if ($url !== '' && web_search_is_http_url($url)) {
                $items[] = [
                    'title' => '',
                    'url' => $url,
                ];
            }
        }
    }

    return web_search_unique_items($items);
}

function web_search_extract_xml_urls(string $content): array
{
    $items = [];

    if (preg_match_all('~<item\b.*?</item>~isu', $content, $matches)) {
        foreach ($matches[0] as $item) {
            $title = '';
            $url = '';
            if (preg_match('~<title[^>]*>(.*?)</title>~isu', $item, $titleMatch)) {
                $title = html_entity_decode(strip_tags($titleMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (preg_match('~<link[^>]*>(.*?)</link>~isu', $item, $linkMatch)) {
                $url = html_entity_decode(trim(strip_tags($linkMatch[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ($url !== '' && web_search_is_http_url($url)) {
                $items[] = ['title' => clean_text($title), 'url' => $url];
            }
        }
    }

    if (preg_match_all('~<entry\b.*?</entry>~isu', $content, $matches)) {
        foreach ($matches[0] as $entry) {
            $title = '';
            $url = '';
            if (preg_match('~<title[^>]*>(.*?)</title>~isu', $entry, $titleMatch)) {
                $title = html_entity_decode(strip_tags($titleMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (preg_match('~<link\b[^>]*href=(["\'])(.*?)\1~isu', $entry, $linkMatch)) {
                $url = html_entity_decode($linkMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ($url !== '' && web_search_is_http_url($url)) {
                $items[] = ['title' => clean_text($title), 'url' => $url];
            }
        }
    }

    return web_search_unique_items($items);
}

function web_search_extract_json_urls(array $data): array
{
    $items = [];
    web_search_walk_json($data, $items);
    return web_search_unique_items($items);
}

function web_search_walk_json(mixed $value, array &$items): void
{
    if (!is_array($value)) {
        return;
    }

    $url = '';
    foreach (['url', 'link', 'href'] as $key) {
        if (isset($value[$key]) && is_string($value[$key])) {
            $url = web_search_unwrap_result_url($value[$key]);
            break;
        }
    }
    if ($url !== '' && web_search_is_http_url($url)) {
        $items[] = [
            'title' => clean_text((string) ($value['title'] ?? $value['name'] ?? '')),
            'url' => $url,
        ];
    }

    foreach ($value as $child) {
        if (is_array($child)) {
            web_search_walk_json($child, $items);
        }
    }
}

function web_search_unwrap_result_url(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['query'])) {
        return $url;
    }

    parse_str((string) $parts['query'], $query);
    foreach (['url', 'u', 'uddg', 'target', 'to', 'q', 'r', 'ru'] as $key) {
        if (!isset($query[$key]) || !is_string($query[$key])) {
            continue;
        }
        $candidate = html_entity_decode($query[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bingDecoded = web_search_decode_bing_url($candidate);
        if ($bingDecoded !== '') {
            return $bingDecoded;
        }
        if (web_search_is_http_url($candidate)) {
            return $candidate;
        }
    }

    return $url;
}

function web_search_decode_bing_url(string $value): string
{
    $value = trim($value);
    if (!str_starts_with($value, 'a1')) {
        return '';
    }

    $encoded = substr($value, 2);
    $encoded = strtr($encoded, '-_', '+/');
    $padding = strlen($encoded) % 4;
    if ($padding > 0) {
        $encoded .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || $decoded === '') {
        return '';
    }

    $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return web_search_is_http_url($decoded) ? $decoded : '';
}

function web_search_unique_items(array $items): array
{
    $unique = [];
    foreach ($items as $item) {
        $url = (string) ($item['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $key = text_lower($url);
        if (!isset($unique[$key])) {
            $unique[$key] = [
                'title' => clean_text((string) ($item['title'] ?? '')),
                'url' => $url,
            ];
        }
    }

    return array_values($unique);
}

function web_search_result_is_noise(string $url): bool
{
    $host = text_lower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $path = text_lower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if ($host === '' || preg_match('~(^|\.)(?:google|bing|yandex|duckduckgo|mojeek|brave|yahoo)\.~u', $host) === 1) {
        return true;
    }
    if ($host === 'go.microsoft.com' || ($host === 'microsoft.com' && str_contains($path, '/fwlink'))) {
        return true;
    }
    if (preg_match('~\.(?:jpg|jpeg|png|webp|gif|svg|css|js|ico|pdf|zip|rar|7z|exe|apk)$~u', $path) === 1) {
        return true;
    }

    return false;
}

function web_search_block_reason(string $content, string $url): ?string
{
    $host = text_lower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $text = text_lower(substr(strip_tags($content), 0, 4000));

    if (str_contains($text, 'captcha') || str_contains($text, 'unusual traffic')) {
        return 'Поисковик отдал captcha/anti-bot вместо выдачи.';
    }
    if (str_contains($text, 'enablejs') || str_contains($text, 'включите javascript') || str_contains($text, 'enable javascript')) {
        return 'Поисковик требует JavaScript и не отдал обычную HTML-выдачу.';
    }
    if (str_contains($text, 'consent') && str_contains($host, 'google')) {
        return 'Google отдал consent-страницу вместо результатов.';
    }
    if (str_contains($text, 'just a moment') && str_contains($text, 'cloudflare')) {
        return 'Сайт защищен Cloudflare/anti-bot и не отдал выдачу.';
    }

    return null;
}

function web_search_request_headers(): array
{
    return [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/rss+xml;q=0.8,application/json;q=0.8,*/*;q=0.7',
        'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.7,en;q=0.6',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
}

function web_search_parse_html_attributes(string $html): array
{
    $attrs = [];
    if (preg_match_all('~([a-z0-9_-]+)\s*=\s*(["\'])(.*?)\2~isu', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $attrs[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return $attrs;
}

function web_search_update_provider_status(string $id, int $count, ?string $error): void
{
    if ($id === '') {
        return;
    }

    $stmt = db()->prepare('
        UPDATE web_search_providers
        SET updated_at = :updated_at, last_error = :last_error, last_result_count = :last_result_count
        WHERE id = :id
    ');
    $stmt->execute([
        ':updated_at' => date(DATE_ATOM),
        ':last_error' => $error,
        ':last_result_count' => $count,
        ':id' => $id,
    ]);
}

function web_search_is_http_url(string $url): bool
{
    if (function_exists('is_http_url')) {
        return is_http_url($url);
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    return in_array($scheme, ['http', 'https'], true) && !empty($parts['host']);
}

function web_search_normalize_content(string $content): string
{
    if (function_exists('normalize_playlist_encoding')) {
        return normalize_playlist_encoding($content);
    }

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

function web_search_resolve_url(string $url, string $baseUrl): string
{
    if (function_exists('resolve_url')) {
        return resolve_url($url, $baseUrl);
    }

    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || str_starts_with($url, 'javascript:') || str_starts_with($url, '#')) {
        return '';
    }

    if (web_search_is_http_url($url)) {
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

    return $root . $dir . $url;
}
