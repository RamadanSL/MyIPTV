<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$id = trim((string) ($_GET['id'] ?? ''));
if ($id !== '') {
    echo json_encode(find_channel($id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 300)));
echo json_encode(query_channels([], $limit, 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
