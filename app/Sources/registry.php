<?php
declare(strict_types=1);

require_once __DIR__ . '/../m3u.php';

function seed_public_playlist_sources(int $limit = 0, ?callable $progress = null): array
{
    $entries = public_playlist_registry_entries();
    if ($limit > 0) {
        $entries = array_slice($entries, 0, $limit);
    }

    $summary = [
        'available' => count($entries),
        'added' => 0,
        'skipped' => 0,
        'removed' => 0,
        'errors' => [],
    ];

    if ($entries === []) {
        if ($progress) {
            $progress(0, 0, 'Встроенный реестр публичных плейлистов отключен.');
        }
        return $summary;
    }

    foreach ($entries as $index => $entry) {
        $url = (string) ($entry['url'] ?? '');
        $title = (string) ($entry['title'] ?? '');
        if ($progress) {
            $progress($index + 1, count($entries), 'Добавляю публичный источник: ' . ($title ?: $url));
        }

        try {
            if ($url === '' || playlist_source_exists($url)) {
                $summary['skipped']++;
                continue;
            }

            add_url_playlist(
                $title,
                $url,
                (string) ($entry['country'] ?? ''),
                (string) ($entry['city'] ?? '')
            );
            $summary['added']++;
        } catch (Throwable $exception) {
            $summary['errors'][] = [
                'source' => $title ?: $url,
                'error' => $exception->getMessage(),
            ];
        }
    }

    return $summary;
}

function public_playlist_registry_entries(): array
{
    return [];
}

function playlist_source_exists(string $url): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM playlists WHERE source = :source');
    $stmt->execute([':source' => $url]);
    return (int) $stmt->fetchColumn() > 0;
}

function registry_title_from_line(string $line, string $url): string
{
    if (preg_match('~<tr><td>(.*?)</td><td[^>]*>.*?</td><td[^>]*><code>' . preg_quote($url, '~') . '</code></td></tr>~u', $line, $match)) {
        $label = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } else {
        $withoutUrl = str_replace(['`' . $url . '`', '<code>' . $url . '</code>', $url], '', $line);
        $withoutUrl = preg_replace('~\s+\d+\s*$~', '', $withoutUrl) ?? $withoutUrl;
        $label = trim(strip_tags($withoutUrl));
    }

    $label = preg_replace('~\s+~u', ' ', $label) ?? $label;
    $label = trim($label, " \t\n\r\0\x0B|");

    $path = parse_url($url, PHP_URL_PATH) ?: '';
    if (str_contains($path, '/countries/')) {
        $country = normalize_country_value($label);
        if ($country === '') {
            $country = registry_country_from_url($url);
        }
        return russian_source_title($url, 'Публичный источник: ' . ($country ?: strtoupper(basename($path, '.m3u'))));
    }
    if (str_contains($path, '/languages/')) {
        return russian_source_title($url, 'Публичный источник: язык');
    }

    return 'Публичный источник: ' . ($label ?: basename($path, '.m3u'));
}

function registry_country_from_url(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    if (!str_contains($path, '/countries/')) {
        return '';
    }

    $label = country_code_to_label(strtoupper(basename($path, '.m3u')));
    return $label !== '' ? $label : strtoupper(basename($path, '.m3u'));
}

