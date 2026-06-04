<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/jobs.php';
require_once __DIR__ . '/../app/health.php';

header('Content-Type: application/json; charset=utf-8');

$summary = job_summary();
$summary['health'] = health_counts();
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
