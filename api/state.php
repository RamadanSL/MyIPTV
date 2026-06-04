<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$sessionKey = session_id();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(load_player_state($sessionKey), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$channelId = trim((string) ($payload['channel_id'] ?? ''));
if ($channelId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'channel_id is required']);
    exit;
}

$_SESSION['last_channel_id'] = $channelId;

$state = save_player_state($sessionKey, $payload);

echo json_encode(['ok' => true, 'state' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
