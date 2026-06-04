<?php
declare(strict_types=1);

require __DIR__ . '/../app/repair.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$refresh = refresh_all_playlists();
echo 'Каналов после обновления: ' . $refresh['channel_count'] . PHP_EOL;

$check = check_all_channels();
echo 'Проверено: ' . $check['checked'] . ', работают: ' . $check['live'] . ', не открылись: ' . $check['dead'] . PHP_EOL;

$repair = repair_dead_channels(50, 0);
echo 'Замен найдено: ' . $repair['repaired'] . ', без замены: ' . $repair['not_found'] . PHP_EOL;
