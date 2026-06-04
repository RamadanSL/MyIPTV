<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/health.php';
require_once __DIR__ . '/../app/jobs.php';

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

$channel = find_channel($channelId);
if (!$channel) {
    http_response_code(404);
    echo json_encode(['error' => 'Канал не найден'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$detail = trim((string) ($payload['detail'] ?? 'Ошибка воспроизведения'));
$error = trim('Плеер не открыл поток' . ($detail !== '' ? ': ' . $detail : ''));

update_channel_health($channelId, health_result('dead', 0, $error, null));

$next = next_similar_channel($channel) ?: adjacent_channel($channelId, 'next');
$repairStarted = false;
try {
    start_background_job('repair_dead', [1, 0]);
    $repairStarted = true;
} catch (Throwable) {
    $repairStarted = false;
}

echo json_encode([
    'ok' => true,
    'next' => $next,
    'repair_started' => $repairStarted,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
