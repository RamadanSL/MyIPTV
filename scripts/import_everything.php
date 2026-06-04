<?php
declare(strict_types=1);

require __DIR__ . '/../app/jobs.php';
require __DIR__ . '/../app/repair.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

run_cli_job($argv, static function (): void {
    set_time_limit(0);

    job_progress('Скачиваю добавленные плейлисты и разбираю каналы', 0, playlist_count(), ['phase' => 'playlists']);
    $refresh = refresh_all_playlists(static function (int $current, int $total, string $message): void {
        job_progress($message, $current, $total, [
            'phase' => 'playlists',
            'channels_total' => channel_count(true),
            'channels_visible' => channel_count(false),
        ]);
    });
    echo 'Плейлистов обработано: ' . $refresh['playlist_count'] . PHP_EOL;
    echo 'Каналов найдено: ' . $refresh['channel_count'] . PHP_EOL;
    echo 'Ошибок обновления: ' . count($refresh['errors']) . PHP_EOL;
});
