<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Stream/resolver.php';

$mode = (string) ($_GET['mode'] ?? 'proxy');
if (!in_array($mode, ['proxy', 'redirect'], true)) {
    $mode = 'proxy';
}

$includeDead = !empty($_GET['show_dead']);
$channels = query_channels(['sort' => 'health'], 10000, 0, $includeDead);

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Cache-Control: no-store');

echo "#EXTM3U\n";
foreach ($channels as $channel) {
    $id = (string) ($channel['id'] ?? '');
    if ($id === '') {
        continue;
    }

    $attrs = [];
    $tvgId = clean_text((string) ($channel['tvg_id'] ?? ''));
    $logo = trim((string) ($channel['logo'] ?? ''));
    $group = clean_text((string) ($channel['genre'] ?? 'Без группы'));
    if ($tvgId !== '') {
        $attrs[] = 'tvg-id="' . str_replace('"', '', $tvgId) . '"';
    }
    if ($logo !== '') {
        $attrs[] = 'tvg-logo="' . str_replace('"', '', $logo) . '"';
    }
    if ($group !== '') {
        $attrs[] = 'group-title="' . str_replace('"', '', $group) . '"';
    }

    $name = str_replace(["\r", "\n"], ' ', clean_text((string) ($channel['name'] ?? 'Канал')));
    echo '#EXTINF:-1 ' . implode(' ', $attrs) . ',' . $name . "\n";
    echo stream_playlist_url($id, $mode) . "\n";
}
