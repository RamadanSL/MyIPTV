<?php
declare(strict_types=1);

require __DIR__ . '/../app/jobs.php';
require __DIR__ . '/../app/discovery.php';
require __DIR__ . '/../app/health.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

run_cli_job($argv, static function (): void {
    set_time_limit(0);

    $total = (int) (wanted_channels_summary()['total'] ?? 0);
    $startedAt = time();
    job_progress('Ищу желаемые каналы по названиям и алиасам', 0, $total, ['phase' => 'wanted']);
    $summary = scan_wanted_channels(static function (int $current, int $total, string $message): void {
        job_progress($message, $current, $total, ['phase' => 'wanted']);
    });

    echo 'Желаемых каналов проверено: ' . $summary['checked'] . PHP_EOL;
    echo 'Найдено живых: ' . $summary['found'] . PHP_EOL;
    echo 'Импортировано: ' . $summary['imported'] . PHP_EOL;
    echo 'Кандидатов проверено: ' . $summary['candidates'] . PHP_EOL;
    echo 'Неясно: ' . $summary['unknown'] . PHP_EOL;
    echo 'Мертвые кандидаты: ' . $summary['dead'] . PHP_EOL;
    echo 'Не найдено: ' . $summary['missing'] . PHP_EOL;
    echo 'Ошибок: ' . count($summary['errors']) . PHP_EOL;

    $foundRows = array_values(array_filter(load_wanted_channels(500), static function (array $row) use ($startedAt): bool {
        $lastSearchAt = strtotime((string) ($row['last_search_at'] ?? '')) ?: 0;
        return (string) ($row['status'] ?? '') === 'found' && $lastSearchAt >= $startedAt - 2;
    }));

    if ($foundRows) {
        echo 'Что найдено:' . PHP_EOL;
        foreach ($foundRows as $row) {
            $title = scan_wanted_log_value((string) ($row['title'] ?? 'Желаемый канал'));
            $foundName = scan_wanted_log_value((string) ($row['found_name'] ?? ''));
            $foundId = scan_wanted_log_value((string) ($row['last_found_channel_id'] ?? ''));
            $candidate = scan_wanted_log_value((string) (($row['last_candidate_title'] ?? '') ?: ($row['last_candidate_url'] ?? '')));
            $health = scan_wanted_log_value((string) ($row['found_health'] ?? ''));

            echo '- ' . $title
                . ' -> ' . ($foundName !== '' ? $foundName : $candidate)
                . ($health !== '' ? ' [' . $health . ']' : '')
                . ($foundId !== '' ? ' id=' . $foundId : '')
                . ($candidate !== '' ? ' candidate=' . $candidate : '')
                . PHP_EOL;
        }
    }
});

function scan_wanted_log_value(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > 180) {
        return mb_substr($value, 0, 177, 'UTF-8') . '...';
    }
    if (!function_exists('mb_strlen') && strlen($value) > 180) {
        return substr($value, 0, 177) . '...';
    }

    return $value;
}
