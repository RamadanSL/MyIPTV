<?php
declare(strict_types=1);

require __DIR__ . '/../app/jobs.php';
require __DIR__ . '/../app/discovery.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

run_cli_job($argv, static function (): void {
    set_time_limit(0);
    job_progress('Просматриваю добавленные страницы поиска', 0, 0, ['phase' => 'discovery']);
    $summary = scan_all_discovery_sources();
    echo 'Источников просмотрено: ' . $summary['source_count'] . PHP_EOL;
    echo 'Кандидатов найдено: ' . $summary['candidate_count'] . PHP_EOL;
    echo 'Импортировано: ' . (int) ($summary['imported_count'] ?? 0) . PHP_EOL;
    echo 'Переименовано старых каналов: ' . (int) ($summary['renamed_count'] ?? 0) . PHP_EOL;

    if (!empty($summary['errors'])) {
        echo 'Ошибки:' . PHP_EOL;
        foreach ($summary['errors'] as $error) {
            echo '- ' . $error['source'] . ': ' . $error['error'] . PHP_EOL;
        }
    }
});
