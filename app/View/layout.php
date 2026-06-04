<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function render_header(string $title, string $active = ''): void
{
    $channelTotal = channel_count(false);
    $playlistTotal = playlist_count();
    $flash = flash_take();
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> - MyIPTV</title>
        <link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
    </head>
    <body>
        <header class="topbar">
            <a class="brand" href="<?= e(app_url('index.php')) ?>">
                <span class="brand-mark">IPTV</span>
                <span>MyIPTV</span>
            </a>
            <nav class="nav">
                <a class="<?= $active === 'catalog' ? 'active' : '' ?>" href="<?= e(app_url('index.php')) ?>">Каталог</a>
                <a class="<?= $active === 'watch' ? 'active' : '' ?>" href="<?= e(app_url('watch.php')) ?>">Плеер</a>
                <a class="<?= $active === 'admin' ? 'active' : '' ?>" href="<?= e(app_url('admin.php')) ?>">Плейлисты</a>
            </nav>
            <div class="topbar-stats">
                <span><?= $channelTotal ?> каналов</span>
                <span><?= $playlistTotal ?> источников</span>
            </div>
        </header>
        <main class="shell">
            <?php if ($flash): ?>
                <div class="flash <?= e((string) ($flash['type'] ?? 'info')) ?>">
                    <?= e((string) ($flash['message'] ?? '')) ?>
                </div>
            <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
        </main>
        <script src="<?= e(asset_url('assets/app.js')) ?>" defer></script>
    </body>
    </html>
    <?php
}

