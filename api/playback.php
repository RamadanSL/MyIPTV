<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Stream/resolver.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$channelId = stream_channel_id_from_request();
if ($channelId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'channel is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $resolved = stream_resolve_channel($channelId, !empty($_GET['refresh']));
    $channel = is_array($resolved['channel'] ?? null) ? $resolved['channel'] : (find_channel($channelId) ?: []);
    $rules = stream_resolver_rules_for_channel($channel);
    $url = (string) ($resolved['url'] ?? '');
    $streamType = infer_stream_type($url);

    echo json_encode([
        'url' => $url,
        'stream_type' => $streamType,
        'headers' => is_array($resolved['headers'] ?? null) ? $resolved['headers'] : [],
        'hls_url' => stream_playlist_url($channelId, 'proxy'),
        'access_type' => channel_access_type((string) ($channel['access_type'] ?? 'open')),
        'access_notes' => (string) ($channel['access_notes'] ?? ''),
        'drm_system' => (string) ($resolved['drm_system'] ?? stream_channel_drm_system($channel, $rules)),
        'license_url' => (string) ($resolved['license_url'] ?? stream_channel_license_proxy_url($channelId, $channel, $rules)),
        'source' => (string) ($resolved['source'] ?? ''),
        'expires_at' => (int) ($resolved['expires_at'] ?? 0),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(502);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
