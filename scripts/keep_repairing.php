<?php
declare(strict_types=1);

require __DIR__ . '/../app/repair.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$sleepSeconds = isset($argv[1]) ? max(30, (int) $argv[1]) : 300;
$deadPerRound = isset($argv[2]) ? max(1, (int) $argv[2]) : 50;

while (true) {
    echo '[' . date('Y-m-d H:i:s') . '] обновляю плейлисты' . PHP_EOL;
    $refresh = refresh_all_playlists();
    echo '  каналов=' . $refresh['channel_count'] . ' ошибок=' . count($refresh['errors']) . PHP_EOL;

    echo '[' . date('Y-m-d H:i:s') . '] проверяю каналы' . PHP_EOL;
    $check = check_all_channels();
    echo '  проверено=' . $check['checked'] . ' работают=' . $check['live'] . ' не_открылись=' . $check['dead'] . PHP_EOL;

    echo '[' . date('Y-m-d H:i:s') . '] ищу замены' . PHP_EOL;
    $repair = repair_dead_channels($deadPerRound, 0);
    echo '  замен=' . $repair['repaired'] . ' без_замены=' . $repair['not_found'] . PHP_EOL;

    $counts = health_counts();
    if ((int) $counts['dead'] === 0 && (int) $counts['unknown'] === 0) {
        echo '[' . date('Y-m-d H:i:s') . '] проблемных каналов нет, жду' . PHP_EOL;
    }

    sleep($sleepSeconds);
}
