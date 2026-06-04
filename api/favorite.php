<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$channelId = trim((string) ($payload['channel_id'] ?? ''));
$favorite = !empty($payload['favorite']);

if ($channelId === '' || !find_channel($channelId)) {
    http_response_code(404);
    echo json_encode(['error' => 'Канал не найден'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$state = set_channel_favorite(session_id(), $channelId, $favorite);
echo json_encode(['ok' => true, 'favorite' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

