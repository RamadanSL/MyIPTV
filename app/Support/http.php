<?php
declare(strict_types=1);

function app_url(string $path): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($base === '/' || $base === '\\') {
        $base = '';
    }

    return $base . '/' . ltrim($path, '/');
}

function asset_url(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/\\');
    $version = is_file($file) ? (string) filemtime($file) : (string) time();

    return app_url($path) . '?v=' . rawurlencode($version);
}

function redirect_to(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}
