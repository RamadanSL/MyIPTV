<?php
declare(strict_types=1);

const WANTED_MAX_ALIASES_PER_CHANNEL = 10;
const WANTED_MAX_SOURCES_PER_ALIAS = 18;
const WANTED_MAX_PAGE_LINKS_PER_SOURCE = 10;
const WANTED_MAX_CANDIDATES_PER_ALIAS = 36;
const WANTED_MAX_PLAYLIST_MATCHES_TO_PROBE = 4;
const WANTED_MAX_WEB_RESULTS_PER_ALIAS = 18;
const WANTED_FETCH_BYTES = 8388608;
const WANTED_FETCH_TIMEOUT = 10;

function make_wanted_channel_id(string $seed): string
{
    return 'want_' . substr(sha1(text_lower($seed)), 0, 18);
}

function normalize_wanted_aliases(string $aliases): string
{
    $parts = preg_split('~[;\r\n]+~u', $aliases) ?: [];
    $result = [];
    foreach ($parts as $part) {
        $part = clean_text($part);
        if ($part !== '') {
            $result[$part] = true;
        }
    }

    return implode("\n", array_keys($result));
}

function wanted_alias_list(array $wanted): array
{
    $aliases = [(string) ($wanted['title'] ?? '') => true];
    foreach (preg_split('~\r\n|\r|\n|;~u', (string) ($wanted['aliases'] ?? '')) ?: [] as $alias) {
        $alias = clean_text($alias);
        if ($alias !== '') {
            $aliases[$alias] = true;
        }
    }

    return array_slice(
        array_values(array_filter(array_keys($aliases), static fn (string $alias): bool => trim($alias) !== '')),
        0,
        WANTED_MAX_ALIASES_PER_CHANNEL
    );
}

function load_wanted_channels(int $limit = 100): array
{
    ensure_app_storage();
    $stmt = db()->prepare('
        SELECT wc.*, c.name AS found_name, c.health_status AS found_health, c.source_title AS found_source
        FROM wanted_channels wc
        LEFT JOIN channels c ON c.id = wc.last_found_channel_id
        ORDER BY wc.priority DESC, wc.title COLLATE NOCASE
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function load_wanted_search_results(int $limit = 80): array
{
    ensure_app_storage();
    $stmt = db()->prepare('
        SELECT r.*, w.title AS wanted_title
        FROM wanted_search_results r
        LEFT JOIN wanted_channels w ON w.id = r.wanted_id
        ORDER BY r.created_at DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function wanted_channels_summary(): array
{
    ensure_app_storage();
    $summary = ['total' => 0, 'found' => 0, 'unknown' => 0, 'dead' => 0, 'missing' => 0];
    foreach (db()->query('SELECT status, COUNT(*) AS c FROM wanted_channels GROUP BY status')->fetchAll() as $row) {
        $status = (string) ($row['status'] ?? 'missing');
        $count = (int) ($row['c'] ?? 0);
        if (!isset($summary[$status])) {
            $status = 'missing';
        }
        $summary[$status] += $count;
        $summary['total'] += $count;
    }

    return $summary;
}

function add_wanted_channel(string $title, string $aliases, string $category = '', int $priority = 50): void
{
    ensure_app_storage();
    $title = clean_text($title);
    if ($title === '') {
        throw new InvalidArgumentException('Укажи название желаемого канала.');
    }

    $category = clean_text($category);
    $priority = max(1, min(100, $priority));
    $now = date(DATE_ATOM);
    $stmt = db()->prepare("
        INSERT INTO wanted_channels (
            id, title, aliases, category, priority, status,
            last_found_channel_id, last_found_at, last_search_at, last_search_note,
            last_candidate_url, last_candidate_title, created_at, updated_at
        ) VALUES (
            :id, :title, :aliases, :category, :priority, 'missing',
            '', NULL, NULL, '', '', '', :created_at, :updated_at
        )
        ON CONFLICT(id) DO UPDATE SET
            aliases = excluded.aliases,
            category = excluded.category,
            priority = excluded.priority,
            status = 'missing',
            last_found_channel_id = '',
            last_found_at = NULL,
            last_search_note = '',
            last_candidate_url = '',
            last_candidate_title = '',
            updated_at = excluded.updated_at
    ");
    $stmt->execute([
        ':id' => make_wanted_channel_id($title),
        ':title' => $title,
        ':aliases' => normalize_wanted_aliases($aliases),
        ':category' => $category,
        ':priority' => $priority,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function delete_wanted_channel(string $id): void
{
    ensure_app_storage();
    $history = db()->prepare('DELETE FROM wanted_search_results WHERE wanted_id = :id');
    $history->execute([':id' => $id]);
    $stmt = db()->prepare('DELETE FROM wanted_channels WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function scan_wanted_channels(?callable $progress = null): array
{
    return discover_wanted_channels($progress);
}

function discover_wanted_channels(?callable $progress = null): array
{
    ensure_app_storage();
    wanted_require_external_tools();

    $wantedRows = load_wanted_channels(500);
    $summary = [
        'checked' => 0,
        'found' => 0,
        'unknown' => 0,
        'dead' => 0,
        'missing' => 0,
        'imported' => 0,
        'candidates' => 0,
        'errors' => [],
    ];

    $total = count($wantedRows);
    foreach ($wantedRows as $index => $wanted) {
        $title = (string) ($wanted['title'] ?? 'Желаемый канал');
        if ($progress) {
            $progress($index + 1, $total, 'Ищу внешний вариант: ' . $title);
        }

        try {
            $result = discover_single_wanted_channel($wanted, static function (string $message) use ($progress, $index, $total): void {
                if ($progress) {
                    $progress($index + 1, $total, $message);
                }
            });
        } catch (Throwable $exception) {
            $result = [
                'status' => 'missing',
                'imported' => false,
                'candidates' => 0,
                'note' => $exception->getMessage(),
            ];
            update_wanted_scan_state(
                (string) ($wanted['id'] ?? ''),
                'missing',
                '',
                null,
                $exception->getMessage(),
                '',
                ''
            );
            $summary['errors'][] = [
                'title' => $title,
                'error' => $exception->getMessage(),
            ];
        }

        $status = (string) ($result['status'] ?? 'missing');
        if (!isset($summary[$status])) {
            $status = 'missing';
        }
        $summary['checked']++;
        $summary[$status]++;
        $summary['candidates'] += (int) ($result['candidates'] ?? 0);
        if (!empty($result['imported'])) {
            $summary['imported']++;
        }
    }

    return $summary;
}

function discover_single_wanted_channel(array $wanted, ?callable $progress = null): array
{
    wanted_require_external_tools();

    $wantedId = (string) ($wanted['id'] ?? '');
    $title = (string) ($wanted['title'] ?? 'Желаемый канал');
    $aliases = wanted_alias_list($wanted);
    if (!$aliases) {
        update_wanted_scan_state($wantedId, 'missing', '', null, 'Нет алиасов для внешнего поиска.', '', '');
        return ['status' => 'missing', 'imported' => false, 'candidates' => 0, 'note' => 'Нет алиасов.'];
    }

    $sources = wanted_search_sources();
    $hasWebSearch = (bool) load_web_search_providers(true);
    if (!$sources && !$hasWebSearch) {
        $note = 'Нет поискового провайдера. Добавь веб-поиск с URL-шаблоном {query}.';
        update_wanted_scan_state($wantedId, 'missing', '', null, $note, '', '');
        return ['status' => 'missing', 'imported' => false, 'candidates' => 0, 'note' => $note];
    }

    $seenPages = [];
    $seenCandidates = [];
    $checkedCandidates = 0;
    $deadCandidates = 0;
    $unknownCandidates = 0;
    $errors = [];
    $lastCandidateUrl = '';
    $lastCandidateTitle = '';

    foreach ($aliases as $alias) {
        $webAttempt = wanted_try_web_search_for_alias($wanted, $alias, $seenCandidates, $progress);
        $checkedCandidates += (int) ($webAttempt['candidates'] ?? 0);
        $deadCandidates += (int) ($webAttempt['dead'] ?? 0);
        $unknownCandidates += (int) ($webAttempt['unknown'] ?? 0);
        $lastCandidateUrl = (string) ($webAttempt['last_url'] ?? $lastCandidateUrl);
        $lastCandidateTitle = (string) ($webAttempt['last_title'] ?? $lastCandidateTitle);
        if (($webAttempt['status'] ?? '') === 'found') {
            $channel = $webAttempt['channel'] ?? [];
            $channelId = (string) ($channel['id'] ?? '');
            $candidateUrl = (string) ($webAttempt['candidate_url'] ?? $lastCandidateUrl);
            $candidateTitle = (string) ($webAttempt['candidate_title'] ?? $lastCandidateTitle);
            update_wanted_scan_state(
                $wantedId,
                'found',
                $channelId,
                date(DATE_ATOM),
                'Найдено через веб-поиск по алиасу "' . $alias . '".',
                $candidateUrl,
                $candidateTitle
            );

            return [
                'status' => 'found',
                'imported' => true,
                'candidates' => $checkedCandidates,
                'channel' => $channel,
            ];
        }

        $aliasSources = array_slice($sources, 0, WANTED_MAX_SOURCES_PER_ALIAS);
        foreach ($aliasSources as $source) {
            $pageUrl = wanted_source_url_for_alias((string) ($source['url'] ?? ''), $alias);
            if ($pageUrl === '' || isset($seenPages[$alias . '|' . $pageUrl])) {
                continue;
            }
            $seenPages[$alias . '|' . $pageUrl] = true;

            if ($progress) {
                $progress('Ищу по алиасу "' . $alias . '": ' . (string) ($source['title'] ?? $pageUrl));
            }

            try {
                $content = wanted_fetch_url($pageUrl);
                $attempt = wanted_try_content_for_alias(
                    $wanted,
                    $source,
                    $alias,
                    $pageUrl,
                    $content,
                    $seenCandidates,
                    $progress
                );
                $checkedCandidates += (int) ($attempt['candidates'] ?? 0);
                $deadCandidates += (int) ($attempt['dead'] ?? 0);
                $unknownCandidates += (int) ($attempt['unknown'] ?? 0);
                $lastCandidateUrl = (string) ($attempt['last_url'] ?? $lastCandidateUrl);
                $lastCandidateTitle = (string) ($attempt['last_title'] ?? $lastCandidateTitle);

                if (($attempt['status'] ?? '') === 'found') {
                    $channel = $attempt['channel'] ?? [];
                    $channelId = (string) ($channel['id'] ?? '');
                    $candidateUrl = (string) ($attempt['candidate_url'] ?? $lastCandidateUrl);
                    $candidateTitle = (string) ($attempt['candidate_title'] ?? $lastCandidateTitle);
                    update_wanted_scan_state(
                        $wantedId,
                        'found',
                        $channelId,
                        date(DATE_ATOM),
                        'Найдено и импортировано по алиасу "' . $alias . '".',
                        $candidateUrl,
                        $candidateTitle
                    );

                    return [
                        'status' => 'found',
                        'imported' => true,
                        'candidates' => $checkedCandidates,
                        'channel' => $channel,
                    ];
                }
            } catch (Throwable $exception) {
                $errors[] = (string) ($source['title'] ?? $pageUrl) . ': ' . $exception->getMessage();
                record_wanted_search_result(
                    $wantedId,
                    $alias,
                    (string) ($source['title'] ?? $pageUrl),
                    $pageUrl,
                    'page',
                    'error',
                    0,
                    $exception->getMessage(),
                    ''
                );
            }
        }
    }

    $status = $checkedCandidates > 0 && $deadCandidates >= $checkedCandidates ? 'dead' : 'missing';
    if ($checkedCandidates > 0 && $unknownCandidates > 0 && $deadCandidates < $checkedCandidates) {
        $status = 'unknown';
    }

    $note = $checkedCandidates > 0
        ? 'Проверено кандидатов: ' . $checkedCandidates . ', живой вариант не найден.'
        : 'Внешние источники не дали подходящих M3U/M3U8-кандидатов.';
    if (!$hasWebSearch) {
        $note .= ' Веб-поиск не настроен: добавь поисковый провайдер с {query}.';
    } else {
        $providerErrors = web_search_provider_error_summary();
        if ($providerErrors !== '') {
            $note .= ' Поисковики: ' . $providerErrors;
        }
    }
    if ($errors) {
        $note .= ' Ошибки: ' . implode(' | ', array_slice($errors, 0, 3));
    }

    update_wanted_scan_state($wantedId, $status, '', null, $note, $lastCandidateUrl, $lastCandidateTitle);

    return [
        'status' => $status,
        'imported' => false,
        'candidates' => $checkedCandidates,
        'note' => $note,
    ];
}

function wanted_require_external_tools(): void
{
    require_once APP_ROOT . '/app/m3u.php';
    require_once APP_ROOT . '/app/discovery.php';
    require_once APP_ROOT . '/app/health.php';
    require_once APP_ROOT . '/app/source_registry.php';
    require_once APP_ROOT . '/app/web_search.php';
}

function wanted_try_web_search_for_alias(
    array $wanted,
    string $alias,
    array &$seenCandidates,
    ?callable $progress = null
): array {
    $summary = [
        'status' => 'missing',
        'candidates' => 0,
        'dead' => 0,
        'unknown' => 0,
        'last_url' => '',
        'last_title' => '',
    ];

    $results = web_search_alias_results($alias, $progress);
    if (!$results) {
        return $summary;
    }

    foreach (array_slice($results, 0, WANTED_MAX_WEB_RESULTS_PER_ALIAS) as $result) {
        $resultUrl = trim((string) ($result['url'] ?? ''));
        if ($resultUrl === '') {
            continue;
        }

        $sourceTitle = clean_text(implode(' ', [
            (string) ($result['title'] ?? ''),
            (string) ($result['provider_title'] ?? ''),
            (string) ($result['query'] ?? ''),
        ]));
        if ($sourceTitle === '') {
            $sourceTitle = 'Веб-поиск';
        }

        $source = [
            'title' => $sourceTitle,
            'url' => $resultUrl,
            'country' => '',
            'city' => '',
            'origin' => 'web_search',
        ];

        if ($progress) {
            $progress('Открываю результат веб-поиска: ' . $sourceTitle);
        }

        try {
            $content = wanted_fetch_url($resultUrl);
            $attempt = wanted_try_content_for_alias(
                $wanted,
                $source,
                $alias,
                $resultUrl,
                $content,
                $seenCandidates,
                $progress
            );
            if ((int) ($attempt['candidates'] ?? 0) === 0) {
                record_wanted_search_result(
                    (string) ($wanted['id'] ?? ''),
                    $alias,
                    $sourceTitle,
                    $resultUrl,
                    'web',
                    'no_candidates',
                    0,
                    'Страница открыта, но ссылок M3U/M3U8 на ней не найдено.',
                    ''
                );
            }
            $summary = wanted_merge_attempt_summary($summary, $attempt);
            if (($summary['status'] ?? '') === 'found') {
                return $summary;
            }
        } catch (Throwable $exception) {
            record_wanted_search_result(
                (string) ($wanted['id'] ?? ''),
                $alias,
                $sourceTitle,
                $resultUrl,
                'web',
                'error',
                0,
                $exception->getMessage(),
                ''
            );
        }
    }

    return $summary;
}

function wanted_search_sources(): array
{
    wanted_require_external_tools();

    $sources = [];
    foreach (load_discovery_sources() as $source) {
        if (!source_record_is_russian_relevant($source)) {
            continue;
        }
        wanted_add_search_source($sources, [
            'title' => (string) ($source['title'] ?? 'Источник поиска'),
            'url' => (string) ($source['url'] ?? ''),
            'country' => (string) ($source['default_country'] ?? ''),
            'city' => (string) ($source['default_city'] ?? ''),
            'origin' => 'discovery',
        ]);
    }

    return array_values($sources);
}

function wanted_add_search_source(array &$sources, array $source): void
{
    $url = trim((string) ($source['url'] ?? ''));
    if ($url === '' || !is_http_url(wanted_source_url_for_alias($url, 'test'))) {
        return;
    }

    $key = text_lower($url);
    if (isset($sources[$key])) {
        return;
    }

    $source['country'] = normalize_country_value((string) ($source['country'] ?? ''));
    $sources[$key] = $source;
}

function wanted_source_url_for_alias(string $url, string $alias): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $encoded = rawurlencode($alias);
    $plain = str_replace(' ', '+', $alias);
    return str_replace(['{query}', '{alias}', '{q}'], [$encoded, $encoded, $plain], $url);
}

function wanted_try_content_for_alias(
    array $wanted,
    array $source,
    string $alias,
    string $pageUrl,
    string $content,
    array &$seenCandidates,
    ?callable $progress = null
): array {
    $summary = [
        'status' => 'missing',
        'candidates' => 0,
        'dead' => 0,
        'unknown' => 0,
        'last_url' => '',
        'last_title' => '',
    ];

    $trimmed = ltrim($content);
    if (str_starts_with($trimmed, '#EXTM3U')) {
        $kind = wanted_m3u_content_kind($pageUrl, $content);
        $candidate = [
            'title' => (string) ($source['title'] ?? $pageUrl),
            'url' => $pageUrl,
            'kind' => $kind,
            'content' => $content,
        ];
        if ($kind !== 'playlist' && !wanted_candidate_matches_aliases($wanted, $candidate, $alias, $source)) {
            return $summary;
        }
        $result = wanted_try_candidate($wanted, $source, $alias, $candidate, $seenCandidates, $progress);
        return wanted_merge_attempt_summary($summary, $result);
    }

    $candidates = extract_playlist_candidates($content, $pageUrl, (string) ($source['title'] ?? $pageUrl));
    $directResult = wanted_try_candidate_list($wanted, $source, $alias, $candidates, $seenCandidates, $progress);
    $summary = wanted_merge_attempt_summary($summary, $directResult);
    if (($summary['status'] ?? '') === 'found') {
        return $summary;
    }

    $links = wanted_extract_page_links($content, $pageUrl, $alias);
    foreach ($links as $link) {
        if ($progress) {
            $progress('Проверяю страницу-кандидат: ' . (string) ($link['title'] ?: $link['url']));
        }

        try {
            $linkContent = wanted_fetch_url((string) $link['url']);
            $nestedSource = $source;
            $nestedSource['title'] = (string) ($link['title'] ?: $source['title'] ?? $link['url']);
            $nested = wanted_try_content_for_alias_without_links(
                $wanted,
                $nestedSource,
                $alias,
                (string) $link['url'],
                $linkContent,
                $seenCandidates,
                $progress
            );
            $summary = wanted_merge_attempt_summary($summary, $nested);
            if (($summary['status'] ?? '') === 'found') {
                return $summary;
            }
        } catch (Throwable $exception) {
            record_wanted_search_result(
                (string) ($wanted['id'] ?? ''),
                $alias,
                (string) ($link['title'] ?: $link['url']),
                (string) $link['url'],
                'page',
                'error',
                0,
                $exception->getMessage(),
                ''
            );
        }
    }

    return $summary;
}

function wanted_try_content_for_alias_without_links(
    array $wanted,
    array $source,
    string $alias,
    string $pageUrl,
    string $content,
    array &$seenCandidates,
    ?callable $progress = null
): array {
    $summary = [
        'status' => 'missing',
        'candidates' => 0,
        'dead' => 0,
        'unknown' => 0,
        'last_url' => '',
        'last_title' => '',
    ];

    if (str_starts_with(ltrim($content), '#EXTM3U')) {
        $kind = wanted_m3u_content_kind($pageUrl, $content);
        $candidate = [
            'title' => (string) ($source['title'] ?? $pageUrl),
            'url' => $pageUrl,
            'kind' => $kind,
            'content' => $content,
        ];
        if ($kind !== 'playlist' && !wanted_candidate_matches_aliases($wanted, $candidate, $alias, $source)) {
            return $summary;
        }

        return wanted_try_candidate($wanted, $source, $alias, [
            'title' => (string) $candidate['title'],
            'url' => (string) $candidate['url'],
            'kind' => (string) $candidate['kind'],
            'content' => (string) $candidate['content'],
        ], $seenCandidates, $progress);
    }

    $candidates = extract_playlist_candidates($content, $pageUrl, (string) ($source['title'] ?? $pageUrl));
    return wanted_merge_attempt_summary(
        $summary,
        wanted_try_candidate_list($wanted, $source, $alias, $candidates, $seenCandidates, $progress)
    );
}

function wanted_try_candidate_list(
    array $wanted,
    array $source,
    string $alias,
    array $candidates,
    array &$seenCandidates,
    ?callable $progress = null
): array {
    $summary = [
        'status' => 'missing',
        'candidates' => 0,
        'dead' => 0,
        'unknown' => 0,
        'last_url' => '',
        'last_title' => '',
    ];

    $checkedForAlias = 0;
    foreach ($candidates as $candidate) {
        if ($checkedForAlias >= WANTED_MAX_CANDIDATES_PER_ALIAS) {
            break;
        }

        $kind = (string) ($candidate['kind'] ?? candidate_kind((string) ($candidate['url'] ?? '')));
        if ($kind !== 'playlist' && !wanted_candidate_matches_aliases($wanted, $candidate, $alias, $source)) {
            continue;
        }

        $checkedForAlias++;
        $result = wanted_try_candidate($wanted, $source, $alias, $candidate, $seenCandidates, $progress);
        $summary = wanted_merge_attempt_summary($summary, $result);
        if (($summary['status'] ?? '') === 'found') {
            return $summary;
        }
    }

    return $summary;
}

function wanted_try_candidate(
    array $wanted,
    array $source,
    string $alias,
    array $candidate,
    array &$seenCandidates,
    ?callable $progress = null
): array {
    $url = trim((string) ($candidate['url'] ?? ''));
    $title = clean_text((string) ($candidate['title'] ?? $url));
    $kind = (string) ($candidate['kind'] ?? candidate_kind($url));
    if ($url === '' || !is_http_url($url)) {
        return ['status' => 'missing', 'candidates' => 0, 'dead' => 0, 'unknown' => 0];
    }

    $key = text_lower($url);
    if (isset($seenCandidates[$key])) {
        return ['status' => 'missing', 'candidates' => 0, 'dead' => 0, 'unknown' => 0];
    }
    $seenCandidates[$key] = true;

    if ($progress) {
        $progress('Проверяю кандидат: ' . ($title !== '' ? $title : $url));
    }

    if ($kind === 'playlist') {
        return wanted_try_playlist_candidate($wanted, $source, $alias, $candidate);
    }

    return wanted_try_stream_candidate($wanted, $source, $alias, $candidate);
}

function wanted_m3u_content_kind(string $url, string $content): string
{
    $kind = candidate_kind($url);
    if ($kind === 'hls' || str_contains(text_lower($content), '#ext-x-')) {
        return 'hls';
    }

    return 'playlist';
}

function wanted_try_playlist_candidate(array $wanted, array $source, string $alias, array $candidate): array
{
    $url = (string) ($candidate['url'] ?? '');
    $title = clean_text((string) ($candidate['title'] ?? $url));
    $content = (string) ($candidate['content'] ?? '');
    if ($content === '') {
        $content = wanted_fetch_url($url);
    }

    $playlistMeta = [
        'id' => 'wanted_probe_' . substr(sha1($url . microtime(true)), 0, 12),
        'title' => $title !== '' ? $title : (string) ($source['title'] ?? 'Внешний плейлист'),
        'source' => $url,
        'default_country' => (string) ($source['country'] ?? ''),
        'default_city' => (string) ($source['city'] ?? ''),
    ];
    $channels = parse_m3u_playlist($content, $playlistMeta);
    $matches = wanted_rank_playlist_channels($wanted, $channels);

    if (!$matches) {
        record_wanted_search_result(
            (string) ($wanted['id'] ?? ''),
            $alias,
            $title,
            $url,
            'playlist',
            'missing',
            0,
            'Плейлист прочитан, совпадений по алиасам нет.',
            ''
        );
        return [
            'status' => 'missing',
            'candidates' => 1,
            'dead' => 0,
            'unknown' => 0,
            'last_url' => $url,
            'last_title' => $title,
        ];
    }

    $dead = 0;
    $unknown = 0;
    foreach (array_slice($matches, 0, WANTED_MAX_PLAYLIST_MATCHES_TO_PROBE) as $channel) {
        $probe = probe_channel_health($channel);
        $probeStatus = (string) $probe['status'];
        record_wanted_search_result(
            (string) ($wanted['id'] ?? ''),
            $alias,
            (string) ($channel['name'] ?? $title),
            (string) ($channel['url'] ?? $url),
            (string) ($channel['stream_type'] ?? infer_stream_type((string) ($channel['url'] ?? ''))),
            $probeStatus,
            (int) ($probe['http_status'] ?? 0),
            (string) ($probe['error'] ?? ''),
            ''
        );

        if ($probeStatus === 'live') {
            $import = wanted_import_channel_candidate($wanted, $channel, $source, $probe);
            record_wanted_search_result(
                (string) ($wanted['id'] ?? ''),
                $alias,
                (string) ($channel['name'] ?? $title),
                (string) ($channel['url'] ?? $url),
                (string) ($channel['stream_type'] ?? infer_stream_type((string) ($channel['url'] ?? ''))),
                'imported',
                (int) ($probe['http_status'] ?? 0),
                '',
                (string) ($import['playlist']['id'] ?? '')
            );

            return [
                'status' => 'found',
                'candidates' => 1,
                'dead' => $dead,
                'unknown' => $unknown,
                'last_url' => (string) ($channel['url'] ?? $url),
                'last_title' => (string) ($channel['name'] ?? $title),
                'candidate_url' => (string) ($channel['url'] ?? $url),
                'candidate_title' => (string) ($channel['name'] ?? $title),
                'channel' => $import['channel'],
            ];
        }

        if ($probeStatus === 'dead') {
            $dead++;
        } else {
            $unknown++;
        }
    }

    return [
        'status' => $unknown > 0 ? 'unknown' : 'dead',
        'candidates' => 1,
        'dead' => $dead,
        'unknown' => $unknown,
        'last_url' => $url,
        'last_title' => $title,
    ];
}

function wanted_try_stream_candidate(array $wanted, array $source, string $alias, array $candidate): array
{
    $url = (string) ($candidate['url'] ?? '');
    $title = wanted_preferred_channel_title($wanted, (string) ($candidate['title'] ?? infer_name_from_url($url)));

    $channel = [
        'name' => $title,
        'url' => $url,
        'logo' => '',
        'genre' => clean_text((string) ($wanted['category'] ?? '')) ?: clean_text((string) ($source['title'] ?? 'Желаемые')),
        'country' => normalize_country_value((string) ($source['country'] ?? '')),
        'city' => clean_text((string) ($source['city'] ?? '')),
        'tvg_id' => '',
        'stream_type' => infer_stream_type($url),
        'source_title' => clean_text((string) ($source['title'] ?? 'Внешний поиск')),
    ];
    $probe = probe_channel_health($channel);
    $status = (string) $probe['status'];
    record_wanted_search_result(
        (string) ($wanted['id'] ?? ''),
        $alias,
        $title,
        $url,
        (string) $channel['stream_type'],
        $status,
        (int) ($probe['http_status'] ?? 0),
        (string) ($probe['error'] ?? ''),
        ''
    );

    if ($status !== 'live') {
        return [
            'status' => $status === 'dead' ? 'dead' : 'unknown',
            'candidates' => 1,
            'dead' => $status === 'dead' ? 1 : 0,
            'unknown' => $status === 'dead' ? 0 : 1,
            'last_url' => $url,
            'last_title' => $title,
        ];
    }

    $import = wanted_import_channel_candidate($wanted, $channel, $source, $probe);
    record_wanted_search_result(
        (string) ($wanted['id'] ?? ''),
        $alias,
        $title,
        $url,
        (string) $channel['stream_type'],
        'imported',
        (int) ($probe['http_status'] ?? 0),
        '',
        (string) ($import['playlist']['id'] ?? '')
    );

    return [
        'status' => 'found',
        'candidates' => 1,
        'dead' => 0,
        'unknown' => 0,
        'last_url' => $url,
        'last_title' => $title,
        'candidate_url' => $url,
        'candidate_title' => $title,
        'channel' => $import['channel'],
    ];
}

function wanted_merge_attempt_summary(array $base, array $next): array
{
    $base['candidates'] = (int) ($base['candidates'] ?? 0) + (int) ($next['candidates'] ?? 0);
    $base['dead'] = (int) ($base['dead'] ?? 0) + (int) ($next['dead'] ?? 0);
    $base['unknown'] = (int) ($base['unknown'] ?? 0) + (int) ($next['unknown'] ?? 0);
    if (!empty($next['last_url'])) {
        $base['last_url'] = (string) $next['last_url'];
    }
    if (!empty($next['last_title'])) {
        $base['last_title'] = (string) $next['last_title'];
    }
    if (($next['status'] ?? '') === 'found') {
        return array_merge($base, $next);
    }
    if (($base['status'] ?? '') !== 'found' && in_array(($next['status'] ?? ''), ['dead', 'unknown'], true)) {
        $base['status'] = (string) $next['status'];
    }

    return $base;
}

function wanted_preferred_channel_title(array $wanted, string $candidateTitle): string
{
    $wantedTitle = clean_text((string) ($wanted['title'] ?? ''));
    $candidateTitle = clean_text($candidateTitle);

    if (
        $candidateTitle === ''
        || is_generic_candidate_title($candidateTitle)
        || wanted_candidate_title_is_noise($candidateTitle)
    ) {
        return $wantedTitle !== '' ? $wantedTitle : 'Желаемый канал';
    }

    return $candidateTitle;
}

function wanted_candidate_title_is_noise(string $title): bool
{
    $lower = text_lower(clean_text($title));
    if ($lower === '') {
        return true;
    }

    $tooLong = function_exists('mb_strlen')
        ? mb_strlen($lower, 'UTF-8') > 120
        : strlen($lower) > 120;
    if ($tooLong) {
        return true;
    }

    foreach (['window.', '__initial_state', 'whoami', 'anonymous', 'app version', 'function(', 'var ', 'const ', 'let '] as $needle) {
        if (str_contains($lower, $needle)) {
            return true;
        }
    }

    return preg_match('~[{}=]{2,}|^\s*[\[{]~u', $title) === 1;
}

function wanted_rank_playlist_channels(array $wanted, array $channels): array
{
    $ranked = [];
    foreach ($channels as $channel) {
        $score = wanted_channel_match_score($wanted, $channel);
        if ($score <= 0) {
            continue;
        }
        $channel['_wanted_score'] = $score;
        $ranked[] = $channel;
    }

    usort($ranked, static fn (array $a, array $b): int => ($b['_wanted_score'] <=> $a['_wanted_score']));
    return $ranked;
}

function wanted_import_channel_candidate(array $wanted, array $channel, array $source, array $probe): array
{
    $title = wanted_preferred_channel_title($wanted, (string) ($channel['name'] ?? ''));
    $channel['name'] = $title;

    $playlistTitle = 'Желаемый: ' . clean_text((string) ($wanted['title'] ?? $title));
    $country = normalize_country_value((string) ($channel['country'] ?? $source['country'] ?? ''));
    $city = clean_text((string) ($channel['city'] ?? $source['city'] ?? ''));
    $content = wanted_single_channel_m3u($wanted, $channel, $source);
    $playlist = wanted_existing_import_playlist($wanted);

    if ($playlist) {
        wanted_write_playlist_file($playlist, $content);
        db()->prepare('
            UPDATE playlists
            SET title = :title, default_country = :country, default_city = :city, updated_at = :updated_at, last_error = NULL
            WHERE id = :id
        ')->execute([
            ':title' => $playlistTitle,
            ':country' => $country,
            ':city' => $city,
            ':updated_at' => date(DATE_ATOM),
            ':id' => (string) $playlist['id'],
        ]);
        $playlist['title'] = $playlistTitle;
        $playlist['default_country'] = $country;
        $playlist['default_city'] = $city;
    } else {
        $playlist = add_text_playlist($playlistTitle, $content, $country, $city);
    }

    $importedChannel = wanted_refresh_single_playlist($playlist);
    if (!$importedChannel) {
        throw new RuntimeException('Поток найден, но не удалось создать канал в локальном плейлисте.');
    }

    update_channel_health((string) $importedChannel['id'], $probe);
    $stmt = db()->prepare('SELECT * FROM channels WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (string) $importedChannel['id']]);
    $fresh = $stmt->fetch();
    if (is_array($fresh)) {
        $importedChannel = $fresh;
    }

    return [
        'playlist' => $playlist,
        'channel' => $importedChannel,
    ];
}

function wanted_existing_import_playlist(array $wanted): ?array
{
    $channelId = trim((string) ($wanted['last_found_channel_id'] ?? ''));
    if ($channelId === '') {
        return null;
    }

    $stmt = db()->prepare('
        SELECT p.*
        FROM channels c
        JOIN playlists p ON p.id = c.source_id
        WHERE c.id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $channelId]);
    $playlist = $stmt->fetch();
    if (!is_array($playlist) || ($playlist['type'] ?? '') !== 'file') {
        return null;
    }

    return str_starts_with((string) ($playlist['title'] ?? ''), 'Желаемый:') ? $playlist : null;
}

function wanted_single_channel_m3u(array $wanted, array $channel, array $source): string
{
    $title = str_replace('"', '', clean_text((string) ($channel['name'] ?? $wanted['title'] ?? 'Канал')));
    $group = str_replace('"', '', clean_text((string) ($channel['genre'] ?? $wanted['category'] ?? $source['title'] ?? 'Желаемые')));
    if ($group === '') {
        $group = 'Желаемые';
    }

    $attrs = [];
    $tvgId = str_replace('"', '', clean_text((string) ($channel['tvg_id'] ?? '')));
    $logo = clean_url((string) ($channel['logo'] ?? ''));
    if ($tvgId !== '') {
        $attrs[] = 'tvg-id="' . $tvgId . '"';
    }
    if ($logo !== '') {
        $attrs[] = 'tvg-logo="' . str_replace('"', '', $logo) . '"';
    }
    $attrs[] = 'group-title="' . $group . '"';

    return "#EXTM3U\n"
        . '#EXTINF:-1 ' . implode(' ', $attrs) . ',' . $title . "\n"
        . (string) ($channel['url'] ?? '') . "\n";
}

function wanted_write_playlist_file(array $playlist, string $content): void
{
    $path = realpath(DATA_DIR . '/' . (string) ($playlist['source'] ?? ''));
    $uploadRoot = realpath(UPLOAD_DIR);
    if (!$path || !$uploadRoot || !str_starts_with($path, $uploadRoot)) {
        throw new RuntimeException('Нельзя перезаписать этот плейлист как результат желаемого канала.');
    }

    if (file_put_contents($path, trim($content) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось обновить локальный плейлист желаемого канала.');
    }
}

function wanted_refresh_single_playlist(array $playlist): ?array
{
    $content = read_playlist_content($playlist);
    $channels = dedupe_channels(parse_m3u_playlist($content, $playlist));
    save_channels_for_source((string) ($playlist['id'] ?? ''), $channels);
    update_playlist_status((string) ($playlist['id'] ?? ''), count($channels), null);

    $stmt = db()->prepare('SELECT * FROM channels WHERE source_id = :source_id ORDER BY name COLLATE NOCASE LIMIT 1');
    $stmt->execute([':source_id' => (string) ($playlist['id'] ?? '')]);
    $channel = $stmt->fetch();
    return is_array($channel) ? $channel : null;
}

function wanted_candidate_matches_aliases(array $wanted, array $candidate, string $alias, array $source = []): bool
{
    $haystack = implode(' ', [
        (string) ($candidate['title'] ?? ''),
        (string) ($candidate['url'] ?? ''),
        (string) ($source['title'] ?? ''),
    ]);

    if (wanted_text_matches_alias($alias, $haystack)) {
        return true;
    }

    foreach (wanted_alias_list($wanted) as $item) {
        if (wanted_text_matches_alias($item, $haystack)) {
            return true;
        }
    }

    return false;
}

function wanted_text_matches_alias(string $alias, string $text): bool
{
    $alias = clean_text($alias);
    $text = clean_text($text);
    if ($alias === '' || $text === '') {
        return false;
    }

    $aliasLower = text_lower($alias);
    $textLower = text_lower($text);
    if ($aliasLower === $textLower) {
        return true;
    }

    if (preg_match('~(?<![\pL\pN])' . preg_quote($aliasLower, '~') . '(?![\pL\pN])~u', $textLower) === 1) {
        return true;
    }

    $aliasTokens = channel_name_tokens($alias);
    $textTokens = channel_name_tokens($text);
    if (!$aliasTokens || !$textTokens) {
        return false;
    }

    $matches = count(array_intersect($aliasTokens, $textTokens));
    return $matches >= min(2, count($aliasTokens));
}

function wanted_extract_page_links(string $content, string $baseUrl, string $alias): array
{
    $links = [];
    if (!preg_match_all('~<a\b([^>]*)>(.*?)</a>~isu', $content, $matches, PREG_SET_ORDER)) {
        return [];
    }

    foreach ($matches as $match) {
        $attrs = parse_html_attributes($match[1]);
        $href = (string) ($attrs['href'] ?? $attrs['data-href'] ?? '');
        $url = resolve_url($href, $baseUrl);
        if ($url === '' || !is_http_url($url) || is_candidate_url($url) || wanted_link_is_noise($url)) {
            continue;
        }

        $label = best_candidate_label([
            (string) ($attrs['title'] ?? ''),
            (string) ($attrs['aria-label'] ?? ''),
            html_text($match[2]),
            $url,
        ]);
        if ($label === '' && !wanted_text_matches_alias($alias, $url)) {
            continue;
        }
        if ($label !== '' && !wanted_text_matches_alias($alias, $label . ' ' . $url)) {
            continue;
        }

        $key = text_lower($url);
        if (isset($links[$key])) {
            continue;
        }

        $links[$key] = [
            'title' => $label,
            'url' => $url,
        ];
        if (count($links) >= WANTED_MAX_PAGE_LINKS_PER_SOURCE) {
            break;
        }
    }

    return array_values($links);
}

function wanted_link_is_noise(string $url): bool
{
    $path = text_lower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if (preg_match('~\.(?:jpg|jpeg|png|webp|gif|svg|css|js|ico|pdf|zip|rar|7z|exe|apk)$~u', $path) === 1) {
        return true;
    }

    return preg_match('~/(?:login|logout|register|privacy|terms|contacts?)/?$~u', $path) === 1;
}

function wanted_fetch_url(string $url): string
{
    if (!is_http_url($url)) {
        throw new RuntimeException('Недопустимая ссылка для внешнего поиска.');
    }

    if (function_exists('is_candidate_url') && is_candidate_url($url)) {
        return fetch_url($url);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => WANTED_FETCH_TIMEOUT,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_USERAGENT => health_user_agent(),
            CURLOPT_HTTPHEADER => health_request_headers(),
            CURLOPT_RANGE => '0-' . WANTED_FETCH_BYTES,
            CURLOPT_ENCODING => '',
        ]);
        $content = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Пустой ответ внешнего источника: ' . ($error ?: 'нет данных'));
        }
        if ($status >= 400) {
            throw new RuntimeException('Внешний источник вернул HTTP ' . $status . '.');
        }

        return normalize_playlist_encoding($content);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => WANTED_FETCH_TIMEOUT,
            'header' => "User-Agent: MyIPTV/1.0 wanted finder\r\n",
        ],
    ]);
    $content = file_get_contents($url, false, $context, 0, WANTED_FETCH_BYTES + 1);
    if ($content === false || $content === '') {
        throw new RuntimeException('Не удалось скачать внешний источник.');
    }

    return normalize_playlist_encoding($content);
}

function update_wanted_scan_state(
    string $id,
    string $status,
    string $channelId,
    ?string $foundAt,
    string $note,
    string $candidateUrl,
    string $candidateTitle
): void {
    if ($id === '') {
        return;
    }

    $stmt = db()->prepare('
        UPDATE wanted_channels
        SET status = :status,
            last_found_channel_id = :last_found_channel_id,
            last_found_at = :last_found_at,
            last_search_at = :last_search_at,
            last_search_note = :last_search_note,
            last_candidate_url = :last_candidate_url,
            last_candidate_title = :last_candidate_title,
            updated_at = :updated_at
        WHERE id = :id
    ');
    $stmt->execute([
        ':status' => in_array($status, ['found', 'unknown', 'dead', 'missing'], true) ? $status : 'missing',
        ':last_found_channel_id' => $channelId,
        ':last_found_at' => $foundAt,
        ':last_search_at' => date(DATE_ATOM),
        ':last_search_note' => clean_text($note),
        ':last_candidate_url' => $candidateUrl,
        ':last_candidate_title' => clean_text($candidateTitle),
        ':updated_at' => date(DATE_ATOM),
        ':id' => $id,
    ]);
}

function record_wanted_search_result(
    string $wantedId,
    string $alias,
    string $title,
    string $url,
    string $kind,
    string $status,
    int $httpStatus,
    string $error,
    string $playlistId
): void {
    if ($wantedId === '' || $url === '') {
        return;
    }

    $stmt = db()->prepare('
        INSERT INTO wanted_search_results (
            id, wanted_id, alias, title, url, kind, status, http_status,
            error, imported_playlist_id, created_at
        ) VALUES (
            :id, :wanted_id, :alias, :title, :url, :kind, :status, :http_status,
            :error, :imported_playlist_id, :created_at
        )
    ');
    $stmt->execute([
        ':id' => 'wsr_' . substr(sha1($wantedId . '|' . $url . '|' . microtime(true)), 0, 22),
        ':wanted_id' => $wantedId,
        ':alias' => clean_text($alias),
        ':title' => clean_text($title),
        ':url' => $url,
        ':kind' => clean_text($kind),
        ':status' => clean_text($status),
        ':http_status' => $httpStatus > 0 ? $httpStatus : null,
        ':error' => clean_text($error),
        ':imported_playlist_id' => $playlistId !== '' ? $playlistId : null,
        ':created_at' => date(DATE_ATOM),
    ]);
}

function find_best_wanted_channel_match(array $wanted, array $channels): ?array
{
    $best = null;
    $bestScore = 0.0;
    foreach ($channels as $channel) {
        $score = wanted_channel_match_score($wanted, $channel);
        if ($score > $bestScore) {
            $best = $channel;
            $bestScore = $score;
        }
    }

    return $bestScore >= 32 ? $best : null;
}

function wanted_channel_match_score(array $wanted, array $channel): float
{
    $score = 0.0;
    $name = text_lower((string) ($channel['name'] ?? ''));
    $search = text_lower(implode(' ', [
        (string) ($channel['name'] ?? ''),
        (string) ($channel['tvg_id'] ?? ''),
        (string) ($channel['genre'] ?? ''),
        (string) ($channel['source_title'] ?? ''),
    ]));
    $nameTokens = channel_name_tokens((string) ($channel['name'] ?? ''));
    $hasAliasEvidence = false;

    foreach (wanted_alias_list($wanted) as $alias) {
        $aliasLower = text_lower($alias);
        $aliasTokens = channel_name_tokens($alias);
        if ($aliasLower !== '' && $name === $aliasLower) {
            $hasAliasEvidence = true;
            $score += 90;
        } elseif ($aliasLower !== '' && preg_match('~(?<![\pL\pN])' . preg_quote($aliasLower, '~') . '(?![\pL\pN])~u', $search)) {
            $hasAliasEvidence = true;
            $score += 58;
        } elseif ($aliasTokens && $nameTokens) {
            $matches = count(array_intersect($aliasTokens, $nameTokens));
            if ($matches > 0 && ($matches >= min(2, count($aliasTokens)) || count($aliasTokens) === 1)) {
                $hasAliasEvidence = true;
                $score += ($matches / max(1, count($aliasTokens))) * 38;
            }
        }
    }

    if (!$hasAliasEvidence) {
        return 0.0;
    }

    $categoryTokens = channel_name_tokens((string) ($wanted['category'] ?? ''));
    if ($categoryTokens) {
        $searchTokens = channel_name_tokens($search);
        $matches = count(array_intersect($categoryTokens, $searchTokens));
        if ($matches > 0) {
            $score += min(12, $matches * 4);
        }
    }

    $health = (string) ($channel['health_status'] ?? 'unknown');
    if ($health === 'live') {
        $score += 14;
    } elseif ($health === 'unknown') {
        $score += 4;
    } elseif ($health === 'dead') {
        $score -= 12;
    }

    if (channel_quality_label($channel) !== '') {
        $score += 4;
    }
    if (!empty($channel['logo'])) {
        $score += 2;
    }

    return $score;
}

function wanted_channel_filter_condition(array &$params): string
{
    $ids = [];
    foreach (load_wanted_channels(500) as $wanted) {
        $id = trim((string) ($wanted['last_found_channel_id'] ?? ''));
        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    if (!$ids) {
        return '0 = 1';
    }

    $parts = [];
    foreach (array_keys($ids) as $index => $id) {
        $key = ':wanted_id_' . $index;
        $parts[] = $key;
        $params[$key] = $id;
    }

    return 'id IN (' . implode(', ', $parts) . ')';
}
