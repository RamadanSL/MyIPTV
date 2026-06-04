<?php
declare(strict_types=1);

require __DIR__ . '/../app/source_registry.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$limit = isset($argv[1]) ? max(0, (int) $argv[1]) : 0;
$summary = seed_public_playlist_sources($limit);

echo "Встроенный импорт публичных плейлистов отключен. Добавляй M3U вручную через админку.\n";
echo 'Доступно во встроенном реестре: ' . $summary['available'] . PHP_EOL;
echo 'Добавлено: ' . $summary['added'] . PHP_EOL;
echo 'Пропущено: ' . $summary['skipped'] . PHP_EOL;
echo 'Ошибок: ' . count($summary['errors']) . PHP_EOL;

foreach ($summary['errors'] as $error) {
    echo '- ' . $error['url'] . ': ' . $error['error'] . PHP_EOL;
}
