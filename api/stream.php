<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Stream/resolver.php';

$channelId = stream_channel_id_from_request();
if ($channelId === '') {
    stream_error(400, 'channel is required');
}

try {
    $resolved = stream_resolve_channel($channelId, !empty($_GET['refresh']));
    $url = (string) ($resolved['url'] ?? '');
    if (!stream_is_http_url($url)) {
        stream_error(502, 'Resolver не вернул корректный stream URL.');
    }

    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
} catch (Throwable $exception) {
    stream_log('redirect-error', [
        'channel' => $channelId,
        'error' => $exception->getMessage(),
    ]);
    stream_error(502, 'Не удалось получить поток: ' . $exception->getMessage());
}
