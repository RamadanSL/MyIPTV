<?php
declare(strict_types=1);

function ensure_app_storage(): void
{
    foreach ([DATA_DIR, UPLOAD_DIR, JOBS_DIR] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    db();
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    try {
        $pdo = db_open_connection();
        db_assert_healthy($pdo);
        db_migrate($pdo);
        db_assert_healthy($pdo);
    } catch (PDOException $exception) {
        if (!db_exception_is_corruption($exception)) {
            throw $exception;
        }

        $pdo = null;
        $backupDir = db_backup_corrupt_database($exception);
        $pdo = db_open_connection();
        db_migrate($pdo);
        db_note_recovery($backupDir);
    }

    return $pdo;
}

function db_open_connection(): PDO
{
    try {
        $pdo = db_connect_raw();
        db_configure_connection($pdo);
    } catch (PDOException $exception) {
        if (!db_exception_is_storage_error($exception)) {
            throw $exception;
        }

        db_backup_sqlite_journal_files($exception);
        $pdo = db_connect_raw();
        db_configure_connection($pdo);
    }

    return $pdo;
}

function db_connect_raw(): PDO
{
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function db_configure_connection(PDO $pdo): void
{
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = DELETE');
}

function db_assert_healthy(PDO $pdo): void
{
    $result = (string) $pdo->query('PRAGMA quick_check')->fetchColumn();
    if (strtolower(trim($result)) !== 'ok') {
        throw new PDOException('SQLite quick_check failed: ' . $result);
    }
}

function db_exception_is_corruption(Throwable $exception): bool
{
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'database disk image is malformed')
        || str_contains($message, 'sqlite quick_check failed')
        || str_contains($message, 'file is not a database')
        || str_contains($message, 'disk i/o error');
}

function db_exception_is_storage_error(Throwable $exception): bool
{
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'disk i/o error')
        || str_contains($message, 'unable to open database file')
        || str_contains($message, 'file is not a database')
        || str_contains($message, 'database is locked');
}

function db_backup_sqlite_journal_files(Throwable $exception): string
{
    $backupDir = DATA_DIR . '/corrupt-backups/' . date('Ymd_His') . '_journal';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }

    foreach ([DB_FILE . '-wal', DB_FILE . '-shm', DB_FILE . '-journal'] as $file) {
        if (!is_file($file)) {
            continue;
        }

        $target = $backupDir . '/' . basename($file);
        if (!@rename($file, $target)) {
            @copy($file, $target);
            @unlink($file);
        }
    }

    file_put_contents($backupDir . '/reason.txt', $exception->getMessage() . PHP_EOL, LOCK_EX);

    return $backupDir;
}

function db_backup_corrupt_database(Throwable $exception): string
{
    $backupDir = DATA_DIR . '/corrupt-backups/' . date('Ymd_His');
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }

    foreach ([DB_FILE, DB_FILE . '-wal', DB_FILE . '-shm'] as $file) {
        if (!is_file($file)) {
            continue;
        }

        $target = $backupDir . '/' . basename($file);
        if (!@rename($file, $target)) {
            @copy($file, $target);
            @unlink($file);
            if (is_file($file)) {
                if ($file === DB_FILE) {
                    @file_put_contents($file, '');
                } else {
                    @unlink($file);
                }
            }
        }
    }

    file_put_contents($backupDir . '/reason.txt', $exception->getMessage() . PHP_EOL, LOCK_EX);

    return $backupDir;
}

function db_note_recovery(string $backupDir): void
{
    $_SESSION['flash'] = [
        'type' => 'warning',
        'message' => 'SQLite-база была повреждена и перенесена в ' . $backupDir . '. Создана чистая база, нужно заново выполнить импорт.',
    ];
}
