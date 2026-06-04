<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Stream/resolver.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    http_response_code(204);
    exit;
}

$channelId = stream_channel_id_from_request();
if ($channelId === '') {
    stream_error(400, 'channel is required');
}

$channel = find_channel($channelId);
if (!$channel) {
    stream_error(404, 'Канал не найден.');
}

$rules = stream_resolver_rules_for_channel($channel);
$licenseUrl = stream_channel_license_url($channel, $rules);
if ($licenseUrl === '') {
    stream_error(400, 'У канала не настроен license_url.');
}

$challenge = file_get_contents('php://input');
if (!is_string($challenge)) {
    stream_error(400, 'Пустой license challenge.');
}

$headers = stream_channel_license_headers($channel, $rules);
$contentType = trim((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if ($contentType !== '') {
    $headers['Content-Type'] = $contentType;
}
$headers['Accept'] ??= '*/*';

try {
    $response = stream_http_request($licenseUrl, $headers, 'POST', $challenge, STREAM_MAX_MANIFEST_BYTES);
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    header('Content-Type: ' . ((string) ($response['content_type'] ?? '') ?: 'application/octet-stream'));
    echo (string) $response['body'];
} catch (Throwable $exception) {
    stream_log('license-error', [
        'channel' => $channelId,
        'error' => $exception->getMessage(),
    ]);
    stream_error(502, 'License proxy error: ' . $exception->getMessage());
}
