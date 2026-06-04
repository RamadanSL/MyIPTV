<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$channels = db()->query('SELECT id, name, url FROM channels ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

foreach ($channels as $channel) {
    $url = (string) $channel['url'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 MyIPTV stream probe',
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_RANGE => '0-4095',
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    $headers = is_string($response) ? substr($response, 0, $headerSize) : '';
    $body = is_string($response) ? substr($response, $headerSize) : '';
    $cors = preg_match('/^access-control-allow-origin:\s*(.+)$/im', $headers, $match) ? trim($match[1]) : '-';
    $looksM3u = str_contains($body, '#EXTM3U') ? 'm3u=yes' : 'm3u=no';
    $hasSegments = preg_match('~\.(?:ts|m4s|aac|mp4)(?:[?#]|$)~i', $body) ? 'segments=yes' : 'segments=no';

    echo $channel['name'], PHP_EOL;
    echo '  status=', $status, ' cors=', $cors, ' ', $looksM3u, ' ', $hasSegments, PHP_EOL;
    if ($error !== '') {
        echo '  error=', $error, PHP_EOL;
    }

    $relay = probe_local_relay((string) $channel['id']);
    echo '  relay=', $relay, PHP_EOL;
}

function probe_local_relay(string $channelId): string
{
    $url = 'http://127.0.0.1:8000/api/hls.php?channel=' . rawurlencode($channelId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        return 'fail ' . ($error ?: 'empty');
    }

    return $status . ' ' . (str_starts_with(ltrim($body), '#EXTM3U') ? 'm3u=yes' : 'm3u=no');
}
