<?php
declare(strict_types=1);

require __DIR__ . '/../app/jobs.php';
require __DIR__ . '/../app/health.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

run_cli_job($argv, static function (array $argv): void {
    set_time_limit(0);
    $limit = isset($argv[1]) ? max(0, (int) $argv[1]) : 0;
    $total = $limit > 0 ? $limit : channel_count(true);
    job_progress('Проверяю, какие каналы реально открываются', 0, $total, ['phase' => 'health']);
    $summary = check_all_channels($limit, true, static function (int $current, int $total, string $message): void {
        job_progress($message, $current, $total, ['phase' => 'health']);
    });

    echo 'Проверено: ' . $summary['checked'] . PHP_EOL;
    echo 'Работают: ' . $summary['live'] . PHP_EOL;
    echo 'Не открылись: ' . $summary['dead'] . PHP_EOL;
    echo 'Неясно: ' . $summary['unknown'] . PHP_EOL;
});
