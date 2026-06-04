<?php
declare(strict_types=1);

require __DIR__ . '/../app/jobs.php';
require __DIR__ . '/../app/repair.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

run_cli_job($argv, static function (array $argv): void {
    set_time_limit(0);
    $channelLimit = isset($argv[1]) ? max(1, (int) $argv[1]) : 25;
    $sourceLimit = isset($argv[2]) ? max(0, (int) $argv[2]) : 0;

    job_progress('Ищу рабочие ссылки взамен неработающих каналов', 0, $channelLimit, ['phase' => 'repair']);
    $summary = repair_dead_channels($channelLimit, $sourceLimit, static function (int $current, int $total, string $message): void {
        job_progress($message, $current, $total, ['phase' => 'repair']);
    });
    echo 'Проблемных каналов проверено: ' . $summary['dead_checked'] . PHP_EOL;
    echo 'Замен найдено: ' . $summary['repaired'] . PHP_EOL;
    echo 'Без замены: ' . $summary['not_found'] . PHP_EOL;
    echo 'Источников просмотрено: ' . $summary['sources_scanned'] . PHP_EOL;
    echo 'Ошибок: ' . count($summary['errors']) . PHP_EOL;
});
