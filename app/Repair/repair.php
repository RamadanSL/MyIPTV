<?php
declare(strict_types=1);

require_once __DIR__ . '/../health.php';
require_once __DIR__ . '/../source_registry.php';

function repair_dead_channels(int $channelLimit = 25, int $sourceLimit = 0, ?callable $progress = null): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM channels
        WHERE health_status = 'dead'
        ORDER BY COALESCE(last_checked_at, '') ASC, name COLLATE NOCASE
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $channelLimit, PDO::PARAM_INT);
    $stmt->execute();
    $deadChannels = $stmt->fetchAll();

    $sources = load_playlists();
    if ($sourceLimit > 0) {
        $sources = array_slice($sources, 0, $sourceLimit);
    }

    $summary = [
        'dead_checked' => 0,
        'repaired' => 0,
        'not_found' => 0,
        'sources_scanned' => 0,
        'errors' => [],
    ];

    $total = count($deadChannels);
    foreach ($deadChannels as $index => $dead) {
        if ($progress) {
            $progress($index + 1, $total, 'Ищу замену: ' . (string) ($dead['name'] ?? $dead['url']));
        }
        $summary['dead_checked']++;
        $replacement = find_live_replacement_for_channel(
            $dead,
            $sources,
            $summary,
            static function (string $message) use ($progress, $index, $total): void {
                if ($progress) {
                    $progress($index + 1, $total, $message);
                }
            }
        );
        if ($replacement) {
            replace_channel_with_live_variant($dead, $replacement);
            $summary['repaired']++;
            continue;
        }

        record_channel_replacement($dead, null, 'not_found', 'Живой вариант не найден.', 0);
        $summary['not_found']++;
    }

    return $summary;
}

function repair_single_channel(string $channelId, int $sourceLimit = 0, bool $force = false): array
{
    $channel = find_channel($channelId);
    if (!$channel) {
        throw new InvalidArgumentException('Канал не найден.');
    }

    if (!$force && (string) ($channel['health_status'] ?? '') === 'live') {
        return [
            'ok' => true,
            'repaired' => false,
            'not_needed' => true,
            'channel' => $channel,
            'replacement' => null,
            'summary' => [
                'dead_checked' => 0,
                'repaired' => 0,
                'not_found' => 0,
                'sources_scanned' => 0,
                'errors' => [],
            ],
        ];
    }

    $sources = load_playlists();
    if ($sourceLimit > 0) {
        $sources = array_slice($sources, 0, $sourceLimit);
    }

    $summary = [
        'dead_checked' => 1,
        'repaired' => 0,
        'not_found' => 0,
        'sources_scanned' => 0,
        'errors' => [],
    ];

    $replacement = find_live_replacement_for_channel($channel, $sources, $summary);
    if ($replacement) {
        $updatedChannel = replace_channel_with_live_variant($channel, $replacement);
        $summary['repaired'] = 1;
        return [
            'ok' => true,
            'repaired' => true,
            'channel' => $updatedChannel,
            'replacement' => $replacement,
            'summary' => $summary,
        ];
    }

    record_channel_replacement($channel, null, 'not_found', 'Живой вариант не найден.', 0);
    $summary['not_found'] = 1;
    return [
        'ok' => true,
        'repaired' => false,
        'channel' => $channel,
        'replacement' => null,
        'summary' => $summary,
    ];
}

function find_live_replacement_for_channel(array $dead, array $sources, array &$summary, ?callable $progress = null): ?array
{
    $deadTokens = channel_name_tokens((string) ($dead['name'] ?? ''));
    if (!$deadTokens && trim((string) ($dead['tvg_id'] ?? '')) === '') {
        return null;
    }

    $blockedUrls = replacement_blocked_urls((string) ($dead['id'] ?? ''), (string) ($dead['url'] ?? ''));
    $candidates = [];

    foreach ($sources as $source) {
        if ($progress) {
            $progress('Сканирую плейлист для замены: ' . (string) ($source['title'] ?? $source['source'] ?? 'Плейлист'));
        }

        try {
            $content = read_playlist_content($source);
            $summary['sources_scanned']++;
            $channels = parse_m3u_playlist($content, $source);
        } catch (Throwable $exception) {
            $summary['errors'][] = [
                'source' => (string) ($source['title'] ?? $source['source'] ?? ''),
                'error' => $exception->getMessage(),
            ];
            continue;
        }

        foreach ($channels as $candidate) {
            $candidateUrl = (string) ($candidate['url'] ?? '');
            if ($candidateUrl === '' || $candidateUrl === (string) ($dead['url'] ?? '') || isset($blockedUrls[$candidateUrl])) {
                continue;
            }

            $score = replacement_candidate_score($dead, $candidate);
            if ($score < 24) {
                continue;
            }

            $candidate['replacement_score'] = $score;
            $candidates[] = $candidate;
        }
    }

    usort($candidates, static fn (array $a, array $b): int => ($b['replacement_score'] <=> $a['replacement_score']) ?: strcmp((string) $a['name'], (string) $b['name']));
    $candidates = array_slice($candidates, 0, 12);

    foreach ($candidates as $candidate) {
        if ($progress) {
            $progress('Проверяю кандидата: ' . (string) ($candidate['name'] ?? $candidate['url'] ?? 'поток'));
        }

        $probe = probe_channel_health($candidate);
        if ($probe['status'] === 'live') {
            $candidate['health_status'] = 'live';
            $candidate['last_checked_at'] = date(DATE_ATOM);
            $candidate['last_http_status'] = $probe['http_status'];
            $candidate['last_probe_error'] = null;
            $candidate['working_url'] = $probe['working_url'] ?? $candidate['url'];
            return $candidate;
        }

        record_channel_replacement($dead, $candidate, 'failed_probe', $probe['error'] ?: 'Проверка не подтвердила живой поток.', (float) ($candidate['replacement_score'] ?? 0));
    }

    return find_external_replacement_for_channel($dead, $summary, $progress);
}

function find_external_replacement_for_channel(array $dead, array &$summary, ?callable $progress = null): ?array
{
    if (!function_exists('discover_single_wanted_channel')) {
        return null;
    }

    $aliases = repair_channel_aliases($dead);
    if (!$aliases) {
        return null;
    }

    $wanted = [
        'id' => '',
        'title' => $aliases[0],
        'aliases' => implode("\n", array_slice($aliases, 1)),
        'category' => (string) ($dead['genre'] ?? ''),
        'priority' => 50,
        'last_found_channel_id' => '',
    ];

    if ($progress) {
        $progress('Локально замена не найдена, запускаю внешний поиск: ' . $aliases[0]);
    }

    try {
        $result = discover_single_wanted_channel($wanted, static function (string $message) use ($progress): void {
            if ($progress) {
                $progress($message);
            }
        });
    } catch (Throwable $exception) {
        $summary['errors'][] = [
            'source' => 'Внешний поиск ремонта',
            'error' => $exception->getMessage(),
        ];
        return null;
    }

    $summary['external_candidates'] = (int) ($summary['external_candidates'] ?? 0) + (int) ($result['candidates'] ?? 0);
    $channel = $result['channel'] ?? null;
    if (($result['status'] ?? '') !== 'found' || !is_array($channel)) {
        return null;
    }

    $channel['replacement_score'] = max(95, replacement_candidate_score($dead, $channel));
    return $channel;
}

function repair_channel_aliases(array $channel): array
{
    $values = [];
    $name = clean_text((string) ($channel['name'] ?? ''));
    if ($name !== '') {
        $values[$name] = true;
        $withoutQuality = preg_replace('~[\[\(]\s*(?:\d{3,4}p|hd|fhd|uhd|sd|live)\s*[\]\)]~iu', ' ', $name) ?? $name;
        $withoutQuality = clean_text($withoutQuality);
        if ($withoutQuality !== '') {
            $values[$withoutQuality] = true;
        }

        $tokens = channel_name_tokens($name);
        if ($tokens) {
            $values[implode(' ', $tokens)] = true;
        }
    }

    $tvgId = clean_text((string) ($channel['tvg_id'] ?? ''));
    if ($tvgId !== '') {
        $values[$tvgId] = true;
    }

    return array_slice(array_values(array_filter(array_keys($values), static fn (string $value): bool => trim($value) !== '')), 0, 6);
}

function replace_channel_with_live_variant(array $dead, array $replacement): ?array
{
    $deadId = (string) ($dead['id'] ?? '');
    $replacementName = (string) ($replacement['name'] ?? $dead['name']);
    $replacementUrl = (string) ($replacement['url'] ?? $dead['url']);

    $existing = find_channel_by_url_name($replacementUrl, $replacementName);
    if ($existing && (string) ($existing['id'] ?? '') !== $deadId) {
        update_channel_from_replacement((string) $existing['id'], $dead, $replacement);
        merge_channel_records($deadId, (string) $existing['id']);
        record_channel_replacement(
            $dead,
            $replacement,
            'applied',
            'Автозамена применена через уже существующий live-вариант.',
            (float) ($replacement['replacement_score'] ?? replacement_candidate_score($dead, $replacement))
        );
        return find_channel((string) $existing['id']);
    }

    try {
        update_channel_from_replacement($deadId, $dead, $replacement);
    } catch (PDOException $exception) {
        if (!str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
            throw $exception;
        }

        $existing = find_channel_by_url_name($replacementUrl, $replacementName);
        if (!$existing || (string) ($existing['id'] ?? '') === $deadId) {
            throw $exception;
        }

        update_channel_from_replacement((string) $existing['id'], $dead, $replacement);
        merge_channel_records($deadId, (string) $existing['id']);
        record_channel_replacement(
            $dead,
            $replacement,
            'applied',
            'Автозамена применена через уже существующий live-вариант.',
            (float) ($replacement['replacement_score'] ?? replacement_candidate_score($dead, $replacement))
        );
        return find_channel((string) $existing['id']);
    }

    record_channel_replacement(
        $dead,
        $replacement,
        'applied',
        'Автозамена применена.',
        (float) ($replacement['replacement_score'] ?? replacement_candidate_score($dead, $replacement))
    );

    return find_channel($deadId);
}

function find_channel_by_url_name(string $url, string $name): ?array
{
    $stmt = db()->prepare('SELECT * FROM channels WHERE url = :url AND name = :name LIMIT 1');
    $stmt->execute([
        ':url' => $url,
        ':name' => $name,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function update_channel_from_replacement(string $channelId, array $dead, array $replacement): void
{
    $stmt = db()->prepare('
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
            health_status = :health_status,
            last_checked_at = :last_checked_at,
            last_http_status = :last_http_status,
            last_probe_error = :last_probe_error,
            working_url = :working_url,
            updated_at = :updated_at
        WHERE id = :id
    ');

    $stmt->execute([
        ':name' => (string) ($replacement['name'] ?? $dead['name']),
        ':url' => (string) ($replacement['url'] ?? $dead['url']),
        ':logo' => (string) ($replacement['logo'] ?? ''),
        ':genre' => (string) ($replacement['genre'] ?? $dead['genre'] ?? 'Без группы'),
        ':country' => (string) ($replacement['country'] ?? $dead['country'] ?? ''),
        ':city' => (string) ($replacement['city'] ?? $dead['city'] ?? ''),
        ':source_id' => (string) ($replacement['source_id'] ?? $dead['source_id']),
        ':source_title' => (string) ($replacement['source_title'] ?? $dead['source_title']),
        ':tvg_id' => (string) ($replacement['tvg_id'] ?? ''),
        ':stream_type' => (string) ($replacement['stream_type'] ?? infer_stream_type((string) ($replacement['url'] ?? ''))),
        ':health_status' => 'live',
        ':last_checked_at' => (string) ($replacement['last_checked_at'] ?? date(DATE_ATOM)),
        ':last_http_status' => $replacement['last_http_status'] ?? null,
        ':last_probe_error' => null,
        ':working_url' => (string) ($replacement['working_url'] ?? $replacement['url'] ?? ''),
        ':updated_at' => date(DATE_ATOM),
        ':id' => $channelId,
    ]);
}

function merge_channel_records(string $fromChannelId, string $toChannelId): void
{
    if ($fromChannelId === '' || $toChannelId === '' || $fromChannelId === $toChannelId) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT OR IGNORE INTO channel_favorites (session_key, channel_id, created_at)
            SELECT session_key, :to_id, created_at
            FROM channel_favorites
            WHERE channel_id = :from_id
        ')->execute([
            ':to_id' => $toChannelId,
            ':from_id' => $fromChannelId,
        ]);
        $pdo->prepare('DELETE FROM channel_favorites WHERE channel_id = :from_id')->execute([':from_id' => $fromChannelId]);

        $pdo->prepare('
            INSERT OR IGNORE INTO player_positions (session_key, channel_id, current_time, duration, updated_at)
            SELECT session_key, :to_id, current_time, duration, updated_at
            FROM player_positions
            WHERE channel_id = :from_id
        ')->execute([
            ':to_id' => $toChannelId,
            ':from_id' => $fromChannelId,
        ]);
        $pdo->prepare('DELETE FROM player_positions WHERE channel_id = :from_id')->execute([':from_id' => $fromChannelId]);

        $pdo->prepare('UPDATE player_state SET last_channel_id = :to_id WHERE last_channel_id = :from_id')->execute([
            ':to_id' => $toChannelId,
            ':from_id' => $fromChannelId,
        ]);
        $pdo->prepare('UPDATE wanted_channels SET last_found_channel_id = :to_id WHERE last_found_channel_id = :from_id')->execute([
            ':to_id' => $toChannelId,
            ':from_id' => $fromChannelId,
        ]);
        $pdo->prepare('DELETE FROM channels WHERE id = :from_id')->execute([':from_id' => $fromChannelId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function channel_names_match(array $deadTokens, string $candidateName): bool
{
    $candidateTokens = channel_name_tokens($candidateName);
    if (!$candidateTokens) {
        return false;
    }

    $matches = count(array_intersect($deadTokens, $candidateTokens));
    $needed = min(count($deadTokens), 2);
    return $matches >= max(1, $needed);
}

function replacement_candidate_score(array $dead, array $candidate): float
{
    $score = channel_similarity_score($dead, $candidate);

    $deadName = text_lower(trim((string) ($dead['name'] ?? '')));
    $candidateName = text_lower(trim((string) ($candidate['name'] ?? '')));
    if ($deadName !== '' && $deadName === $candidateName) {
        $score += 35;
    }

    $deadHost = text_lower((string) (parse_url((string) ($dead['url'] ?? ''), PHP_URL_HOST) ?: ''));
    $candidateHost = text_lower((string) (parse_url((string) ($candidate['url'] ?? ''), PHP_URL_HOST) ?: ''));
    if ($deadHost !== '' && $candidateHost !== '' && $deadHost !== $candidateHost) {
        $score += 5;
    }

    $sourceId = (string) ($candidate['source_id'] ?? '');
    if ($sourceId !== '') {
        $score += source_health_score($sourceId);
    }

    return $score;
}

function source_health_score(string $sourceId): float
{
    static $cache = [];
    if (isset($cache[$sourceId])) {
        return $cache[$sourceId];
    }

    $stmt = db()->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN health_status = 'live' THEN 1 ELSE 0 END) AS live_count,
            SUM(CASE WHEN health_status = 'dead' THEN 1 ELSE 0 END) AS dead_count
        FROM channels
        WHERE source_id = :source_id
    ");
    $stmt->execute([':source_id' => $sourceId]);
    $row = $stmt->fetch() ?: [];
    $total = max(1, (int) ($row['total'] ?? 0));
    $live = (int) ($row['live_count'] ?? 0);
    $dead = (int) ($row['dead_count'] ?? 0);
    $score = (($live / $total) * 10) - (($dead / $total) * 4);
    $cache[$sourceId] = $score;

    return $score;
}

function replacement_blocked_urls(string $channelId, string $currentUrl): array
{
    $blocked = [$currentUrl => true];
    if ($channelId === '') {
        return $blocked;
    }

    $stmt = db()->prepare("
        SELECT replacement_url
        FROM channel_replacements
        WHERE channel_id = :channel_id
          AND status IN ('failed_probe', 'applied')
          AND replacement_url != ''
    ");
    $stmt->execute([':channel_id' => $channelId]);
    foreach ($stmt->fetchAll() as $row) {
        $blocked[(string) $row['replacement_url']] = true;
    }

    return $blocked;
}

function record_channel_replacement(array $dead, ?array $replacement, string $status, string $reason, float $score): void
{
    $stmt = db()->prepare('
        INSERT INTO channel_replacements (
            id, channel_id, old_name, old_url, replacement_name, replacement_url,
            source_id, source_title, score, status, reason, created_at
        ) VALUES (
            :id, :channel_id, :old_name, :old_url, :replacement_name, :replacement_url,
            :source_id, :source_title, :score, :status, :reason, :created_at
        )
    ');
    $stmt->execute([
        ':id' => 'rep_' . substr(sha1((string) ($dead['id'] ?? '') . ($replacement['url'] ?? '') . microtime(true)), 0, 20),
        ':channel_id' => (string) ($dead['id'] ?? ''),
        ':old_name' => (string) ($dead['name'] ?? ''),
        ':old_url' => (string) ($dead['url'] ?? ''),
        ':replacement_name' => (string) ($replacement['name'] ?? ''),
        ':replacement_url' => (string) ($replacement['url'] ?? ''),
        ':source_id' => (string) ($replacement['source_id'] ?? ''),
        ':source_title' => (string) ($replacement['source_title'] ?? ''),
        ':score' => $score,
        ':status' => $status,
        ':reason' => $reason,
        ':created_at' => date(DATE_ATOM),
    ]);
}

function load_replacement_history(int $limit = 80): array
{
    $stmt = db()->prepare('
        SELECT *
        FROM channel_replacements
        ORDER BY created_at DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

