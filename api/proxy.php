<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Stream/resolver.php';

$channelId = stream_channel_id_from_request();
if ($channelId === '') {
    stream_error(400, 'channel is required');
}

try {
    $resolved = stream_resolve_channel($channelId, !empty($_GET['refresh']));
    $headers = is_array($resolved['headers'] ?? null) ? $resolved['headers'] : [];
    $targetUrl = (string) ($resolved['url'] ?? '');

    if (!empty($_GET['u'])) {
        $decoded = stream_base64url_decode((string) $_GET['u']);
        if (!is_string($decoded) || $decoded === '') {
            stream_error(400, 'Некорректная proxy-ссылка.');
        }
        if (!stream_signature_valid($channelId, $decoded, (string) ($_GET['sig'] ?? ''))) {
            stream_error(403, 'Некорректная подпись proxy-ссылки.');
        }
        $targetUrl = $decoded;
    }

    if (!stream_is_http_url($targetUrl)) {
        stream_error(403, 'Proxy поддерживает только HTTP/HTTPS URL.');
    }

    $maxBytes = isset($_GET['u']) ? STREAM_MAX_SEGMENT_BYTES : STREAM_MAX_MANIFEST_BYTES;
    $response = stream_http_request($targetUrl, $headers, 'GET', null, $maxBytes);
    $body = (string) $response['body'];
    $contentType = (string) ($response['content_type'] ?? '');
    $effectiveUrl = (string) ($response['effective_url'] ?: $targetUrl);

    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');

    if (stream_looks_like_playlist($effectiveUrl, $contentType, $body)) {
        stream_log('proxy-manifest', ['channel' => $channelId, 'url' => stream_log_url($effectiveUrl)]);
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        echo stream_rewrite_m3u8($body, $effectiveUrl, $channelId);
        exit;
    }

    stream_log('proxy-segment', ['channel' => $channelId, 'url' => stream_log_url($effectiveUrl)]);
    header('Content-Type: ' . ($contentType ?: stream_content_type_from_url($effectiveUrl)));
    echo $body;
} catch (Throwable $exception) {
    stream_log('proxy-error', [
        'channel' => $channelId,
        'error' => $exception->getMessage(),
    ]);
    stream_error(502, 'Ошибка proxy: ' . $exception->getMessage());
}
