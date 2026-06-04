<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/repair.php';

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
if ($channelId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'channel_id is required'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $result = repair_single_channel($channelId, (int) ($payload['source_limit'] ?? 0), !empty($payload['force']));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(409);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
