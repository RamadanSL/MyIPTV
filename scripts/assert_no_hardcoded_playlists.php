<?php
declare(strict_types=1);

require __DIR__ . '/../app/source_registry.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$entries = public_playlist_registry_entries();
if ($entries !== []) {
    fwrite(STDERR, "Hardcoded public playlist registry must stay empty.\n");
    exit(1);
}

$wantedFile = APP_ROOT . '/app/Domain/Wanted/wanted.php';
$wantedSource = is_file($wantedFile) ? (string) file_get_contents($wantedFile) : '';
foreach ([
    'load_playlists(' => 'Wanted search must not reuse ordinary playlist URLs as external search sources.',
    "origin' => 'playlist'" => 'Wanted search must not mark playlist URLs as search origins.',
    "origin' => 'registry'" => 'Wanted search must not use the disabled public playlist registry.',
] as $needle => $message) {
    if (str_contains($wantedSource, $needle)) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo "OK: no hardcoded public playlist registry entries and no playlist-backed wanted search.\n";
