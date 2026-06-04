<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function background_job_actions(): array
{
    return [
        'refresh',
        'scan_all_discovery',
        'import_everything',
        'check_channels',
        'repair_dead',
        'scan_wanted',
    ];
}

function background_job_request(string $action, array $input): array
{
    if ($action === 'check_channels') {
        return [
            'type' => 'check_channels',
            'args' => [(int) ($input['limit'] ?? 0)],
            'message' => 'Проверяю каналы. Можно оставаться на странице или открыть каталог.',
        ];
    }

    if ($action === 'repair_dead') {
        return [
            'type' => 'repair_dead',
            'args' => [
                (int) ($input['channel_limit'] ?? 25),
                (int) ($input['source_limit'] ?? 0),
            ],
            'message' => 'Ищу живые замены для проблемных каналов.',
        ];
    }

    if ($action === 'scan_wanted') {
        return [
            'type' => 'scan_wanted',
            'args' => [],
            'message' => 'Ищу желаемые каналы по алиасам.',
        ];
    }

    if ($action === 'import_everything') {
        return [
            'type' => 'import_everything',
            'args' => [],
            'message' => 'Обновляю добавленные плейлисты и собираю каталог.',
        ];
    }

    if ($action === 'refresh') {
        return [
            'type' => 'refresh',
            'args' => [],
            'message' => 'Обновляю добавленные плейлисты и собираю каталог.',
        ];
    }

    if ($action === 'scan_all_discovery') {
        return [
            'type' => 'scan_discovery',
            'args' => [],
            'message' => 'Сканирую страницы и ищу M3U/M3U8-кандидаты.',
        ];
    }

    throw new InvalidArgumentException('Неизвестная фоновая задача.');
}

function start_background_job(string $type, array $args = []): array
{
    $allowed = [
        'import_everything' => 'import_everything.php',
        'check_channels' => 'check_channels.php',
        'repair_dead' => 'repair_dead.php',
        'refresh' => 'refresh_playlists.php',
        'scan_discovery' => 'scan.php',
        'scan_wanted' => 'scan_wanted.php',
    ];

    if (!isset($allowed[$type])) {
        throw new InvalidArgumentException('Неизвестная фоновая задача.');
    }

    $job = [
        'id' => 'job_' . date('Ymd_His') . '_' . substr(sha1($type . microtime(true)), 0, 8),
        'type' => $type,
        'status' => 'running',
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'args' => $args,
        'exit_code' => null,
        'pid' => null,
        'output' => '',
        'progress' => [
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => 'Запуск...',
        ],
    ];

    $jobFile = job_file($job['id']);
    write_job($jobFile, $job);

    $php = php_binary_path();
    $script = APP_ROOT . '/scripts/' . $allowed[$type];
    if (!is_file($script)) {
        $job['status'] = 'failed';
        $job['exit_code'] = 127;
        $job['progress']['message'] = 'Не найден CLI-скрипт задачи.';
        $job['output'] = 'Не найден файл: ' . $script;
        write_job($jobFile, $job);
        throw new RuntimeException($job['output']);
    }

    $command = build_job_command($php, $script, $args, $jobFile);

    $process = proc_open($command, [], $pipes, APP_ROOT, null, [
        'bypass_shell' => false,
    ]);

    if (!is_resource($process)) {
        $job['status'] = 'failed';
        $job['output'] = 'Не удалось запустить фоновую задачу.';
        write_job($jobFile, $job);
        throw new RuntimeException($job['output']);
    }

    $status = proc_get_status($process);
    $job['pid'] = $status['pid'] ?? null;
    write_job($jobFile, $job);
    proc_close($process);

    return $job;
}

function cancel_running_jobs(): int
{
    ensure_app_storage();
    $files = glob(JOBS_DIR . '/*.json') ?: [];
    $count = 0;

    foreach ($files as $file) {
        $job = read_job($file);
        if (!$job || ($job['status'] ?? '') !== 'running') {
            continue;
        }

        $job['status'] = 'failed';
        $job['updated_at'] = date(DATE_ATOM);
        $job['exit_code'] = 130;
        $job['progress']['message'] = 'Задача сброшена вручную.';
        $job['output'] = trim((string) ($job['output'] ?? '') . PHP_EOL . '[manual] Задача сброшена из админки.');
        write_job($file, $job);
        $count++;
    }

    return $count;
}

function latest_job(): ?array
{
    ensure_app_storage();
    mark_stale_jobs();
    $files = glob(JOBS_DIR . '/*.json') ?: [];
    if (!$files) {
        return null;
    }

    usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return read_job($files[0]);
}

function mark_stale_jobs(int $maxAgeSeconds = 120): void
{
    $files = glob(JOBS_DIR . '/*.json') ?: [];
    $now = time();
    foreach ($files as $file) {
        $job = read_job($file);
        if (!$job || ($job['status'] ?? '') !== 'running') {
            continue;
        }

        $updated = strtotime((string) ($job['updated_at'] ?? $job['created_at'] ?? '')) ?: $now;
        if (($now - $updated) <= $maxAgeSeconds) {
            continue;
        }

        $job['status'] = 'failed';
        $job['updated_at'] = date(DATE_ATOM);
        $job['exit_code'] = 124;
        $job['progress']['message'] = 'Задача зависла без обновлений и остановлена по таймауту статуса.';
        $job['output'] = trim((string) ($job['output'] ?? '') . PHP_EOL . '[timeout] Нет обновлений больше ' . $maxAgeSeconds . ' секунд.');
        write_job($file, $job);
    }
}

function job_summary(): array
{
    return [
        'latest' => latest_job(),
        'counts' => [
            'playlists' => playlist_count(),
            'channels_visible' => channel_count(false),
            'channels_total' => channel_count(true),
        ],
    ];
}

function job_file(string $id): string
{
    return JOBS_DIR . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $id) . '.json';
}

function read_job(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }

    $data = json_decode(file_get_contents($file) ?: '', true);
    return is_array($data) ? $data : null;
}

function write_job(string $file, array $job): void
{
    file_put_contents($file, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
}

function job_progress(string $message, ?int $current = null, ?int $total = null, array $extra = []): void
{
    $file = current_job_file();
    if ($file === null) {
        return;
    }

    $job = read_job($file);
    if (!$job) {
        return;
    }

    $progress = is_array($job['progress'] ?? null) ? $job['progress'] : [];
    if ($current !== null) {
        $progress['current'] = $current;
    }
    if ($total !== null) {
        $progress['total'] = $total;
    }

    $currentValue = (int) ($progress['current'] ?? 0);
    $totalValue = (int) ($progress['total'] ?? 0);
    $progress['percent'] = $totalValue > 0 ? round(($currentValue / $totalValue) * 100, 1) : 0;
    $progress['message'] = $message;
    $createdAt = strtotime((string) ($job['created_at'] ?? '')) ?: time();
    $elapsed = max(1, time() - $createdAt);
    $progress['elapsed_seconds'] = $elapsed;
    $progress['eta_seconds'] = ($currentValue > 0 && $totalValue > $currentValue)
        ? (int) round(($elapsed / $currentValue) * ($totalValue - $currentValue))
        : 0;
    $job['progress'] = array_merge($progress, $extra);
    $job['updated_at'] = date(DATE_ATOM);

    $line = '[' . date('H:i:s') . '] ' . $message;
    if ($totalValue > 0) {
        $line .= ' (' . $currentValue . '/' . $totalValue . ')';
    }
    $output = trim((string) ($job['output'] ?? ''));
    $output = trim($output . PHP_EOL . $line);
    if (strlen($output) > 12000) {
        $output = substr($output, -12000);
        $firstNewline = strpos($output, PHP_EOL);
        if ($firstNewline !== false) {
            $output = substr($output, $firstNewline + 1);
        }
    }
    $job['output'] = $output;

    write_job($file, $job);
}

function current_job_file(): ?string
{
    return $GLOBALS['MYIPTV_JOB_FILE'] ?? null;
}

function php_binary_path(): string
{
    $candidates = [];

    if (defined('PHP_BINARY') && PHP_BINARY !== '' && preg_match('/php(?:\.exe)?$/i', PHP_BINARY)) {
        $candidates[] = PHP_BINARY;
    }

    if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
        $candidates[] = rtrim(PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
    }

    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = 'C:\\xampp\\php\\php.exe';
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return 'php';
}

function build_job_command(string $php, string $script, array $args, string $jobFile): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $scriptArgs = array_merge([$script], array_map('strval', $args), ['--job-file=' . $jobFile]);
        $argumentList = implode(', ', array_map('powershell_quote', $scriptArgs));
        $command = 'Start-Process -FilePath ' . powershell_quote($php)
            . ' -ArgumentList @(' . $argumentList . ')'
            . ' -WorkingDirectory ' . powershell_quote(APP_ROOT)
            . ' -WindowStyle Hidden';

        return 'powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg($command);
    }

    $parts = [
        escapeshellarg($php),
        escapeshellarg($script),
    ];

    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }

    $parts[] = '--job-file=' . escapeshellarg($jobFile);
    return implode(' ', $parts) . ' > /dev/null 2>&1 &';
}

function powershell_quote(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function cli_extract_job_file(array $argv): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--job-file=')) {
            return substr($arg, 11);
        }
    }

    return null;
}

function cli_without_job_args(array $argv): array
{
    return array_values(array_filter($argv, static fn (string $arg): bool => !str_starts_with($arg, '--job-file=')));
}

function run_cli_job(array $argv, callable $callback): void
{
    $jobFile = cli_extract_job_file($argv);
    $cleanArgv = cli_without_job_args($argv);
    $job = $jobFile ? read_job($jobFile) : null;
    if ($jobFile) {
        $GLOBALS['MYIPTV_JOB_FILE'] = $jobFile;
    }

    if ($jobFile && $job) {
        $job['status'] = 'running';
        $job['updated_at'] = date(DATE_ATOM);
        $job['progress']['message'] = 'Работаю...';
        write_job($jobFile, $job);
    }

    ob_start();
    $exitCode = 0;
    try {
        $callback($cleanArgv);
    } catch (Throwable $exception) {
        $exitCode = 1;
        echo 'ERROR: ' . $exception->getMessage() . PHP_EOL;
    }
    $output = ob_get_clean();

    if ($jobFile && $job) {
        $latest = read_job($jobFile);
        if ($latest) {
            $job = $latest;
        }
        $job['status'] = $exitCode === 0 ? 'complete' : 'failed';
        $job['updated_at'] = date(DATE_ATOM);
        $job['exit_code'] = $exitCode;
        $job['output'] = trim(trim((string) ($job['output'] ?? '')) . PHP_EOL . trim((string) $output));
        if ($exitCode === 0) {
            $job['progress']['message'] = 'Готово';
            $job['progress']['percent'] = 100;
        }
        write_job($jobFile, $job);
    }

    echo $output;
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

