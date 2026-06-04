<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/m3u.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$url = trim((string) ($_GET['url'] ?? ''));
if ($url === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Вставь ссылку http:// или https://.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $inspection = inspect_import_url($url);
    $kind = (string) ($inspection['kind'] ?? 'unknown');

    $actions = [
        'playlist' => 'Скачаю и разберу напрямую',
        'stream' => 'Добавлю как один канал',
        'page' => 'Просканирую страницу на ссылки',
        'unknown' => 'Попрошу другую ссылку',
    ];

    $tones = [
        'playlist' => 'success',
        'stream' => 'success',
        'page' => 'warning',
        'unknown' => 'error',
    ];

    echo json_encode([
        'ok' => true,
        'kind' => $kind,
        'label' => (string) ($inspection['label'] ?? 'Ссылка'),
        'message' => (string) ($inspection['message'] ?? ''),
        'action_label' => $actions[$kind] ?? $actions['unknown'],
        'tone' => $tones[$kind] ?? $tones['error'],
        'status' => (int) ($inspection['status'] ?? 0),
        'content_type' => (string) ($inspection['content_type'] ?? ''),
        'effective_url' => (string) ($inspection['effective_url'] ?? $url),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'kind' => 'error',
        'label' => 'Не удалось разобрать',
        'message' => $exception->getMessage(),
        'action_label' => 'Проверь ссылку',
        'tone' => 'error',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
