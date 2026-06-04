<?php
declare(strict_types=1);

require_once __DIR__ . '/app/layout.php';

$sessionKey = session_id();
$fallbackChannels = query_channels(['sort' => 'health'], 1, 0, false);
$requestedId = trim((string) ($_GET['id'] ?? ''));
$sessionId = (string) ($_SESSION['last_channel_id'] ?? '');
$channel = null;

if ($requestedId !== '') {
    $channel = find_channel($requestedId);
}

if (!$channel && $sessionId !== '') {
    $channel = find_channel($sessionId);
}

if (!$channel && $fallbackChannels) {
    $channel = $fallbackChannels[0];
}

if ($channel) {
    $_SESSION['last_channel_id'] = (string) $channel['id'];
}

$previousChannel = $channel ? adjacent_channel((string) $channel['id'], 'prev') : null;
$nextChannel = $channel ? adjacent_channel((string) $channel['id'], 'next') : null;
$isFavorite = $channel ? is_channel_favorite($sessionKey, (string) $channel['id']) : false;
$currentWatchUrl = $channel ? app_url('watch.php?id=' . urlencode((string) $channel['id'])) : app_url('watch.php');
$previousWatchUrl = $previousChannel ? app_url('watch.php?id=' . urlencode((string) $previousChannel['id'])) : $currentWatchUrl;
$nextWatchUrl = $nextChannel ? app_url('watch.php?id=' . urlencode((string) $nextChannel['id'])) : $currentWatchUrl;
$alternatives = $channel ? load_channel_alternatives($channel, 8) : [];
$genreChannels = $channel ? load_related_channels($channel, 'genre', 8) : [];
$sourceChannels = $channel ? load_related_channels($channel, 'source', 8) : [];
$quality = $channel ? channel_quality_label($channel) : '';
$accessType = $channel ? channel_access_type((string) ($channel['access_type'] ?? 'open')) : 'open';
$accessNotes = $channel ? trim((string) ($channel['access_notes'] ?? '')) : '';
$drmSystem = $channel ? channel_drm_system((string) ($channel['drm_system'] ?? '')) : '';
$licenseUrl = ($channel && trim((string) ($channel['license_url'] ?? '')) !== '') ? app_url('api/license.php?channel=' . urlencode((string) $channel['id'])) : '';
$streamHeaders = $channel ? (string) ($channel['stream_headers'] ?? '') : '';
$checkedAt = $channel ? strtotime((string) ($channel['last_checked_at'] ?? '')) : false;
$checkedLabel = $checkedAt ? date('d.m H:i', $checkedAt) : 'не проверялся';
$isAudioStream = $channel && (string) ($channel['stream_type'] ?? '') === 'audio';

render_header('Плеер', 'watch');
?>
<?php if (!$channel): ?>
    <section class="empty-state">
        <h1>Пока нечего смотреть</h1>
        <p>Добавь хотя бы один плейлист и обнови каналы.</p>
        <a class="button primary" href="<?= e(app_url('admin.php')) ?>">Добавить плейлист</a>
    </section>
<?php else: ?>
    <section class="watch-layout">
        <div class="player-wrap <?= $isAudioStream ? 'is-audio-stream' : '' ?>" data-player
            data-channel-id="<?= e((string) $channel['id']) ?>"
            data-stream-url="<?= e((string) $channel['url']) ?>"
            data-stream-type="<?= e((string) $channel['stream_type']) ?>"
            data-access-type="<?= e($accessType) ?>"
            data-access-notes="<?= e($accessNotes) ?>"
            data-drm-system="<?= e($drmSystem !== '' ? $drmSystem : 'com.widevine.alpha') ?>"
            data-license-url="<?= e($licenseUrl) ?>"
            data-stream-headers="<?= e($streamHeaders) ?>"
            data-hls-url="<?= e(app_url('api/proxy.php?channel=' . urlencode((string) $channel['id']))) ?>"
            data-playback-config-url="<?= e(app_url('api/playback.php?channel=' . urlencode((string) $channel['id']))) ?>"
            data-state-url="<?= e(app_url('api/state.php')) ?>"
            data-fault-url="<?= e(app_url('api/channel_fault.php')) ?>"
            data-repair-url="<?= e(app_url('api/channel_repair.php')) ?>"
            data-favorite-url="<?= e(app_url('api/favorite.php')) ?>"
            data-is-favorite="<?= $isFavorite ? '1' : '0' ?>">
            <video class="iptv-video" autoplay playsinline></video>
            <div class="player-audio-visual" aria-hidden="<?= $isAudioStream ? 'false' : 'true' ?>">
                <div class="player-audio-mark">
                    <?php if (!empty($channel['logo'])): ?>
                        <img src="<?= e((string) $channel['logo']) ?>" alt="">
                    <?php else: ?>
                        <span><?= e(text_initial((string) $channel['name'])) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="eyebrow">Радио</p>
                    <strong><?= e((string) $channel['name']) ?></strong>
                    <small><?= e((string) $channel['source_title']) ?></small>
                </div>
            </div>
            <div class="player-top-gradient">
                <div class="player-title">
                    <strong><?= e((string) $channel['name']) ?></strong>
                    <span><?= e((string) $channel['source_title']) ?> · <?= e((string) $channel['health_status']) ?></span>
                </div>
            </div>
            <div class="player-overlay" data-player-overlay>
                <button class="icon-button large" type="button" data-action="toggle-play" aria-label="Воспроизвести">▶</button>
            </div>
            <div class="player-controls">
                <div class="timeline">
                    <span data-current-time>00:00</span>
                    <input type="range" min="0" max="1000" value="0" step="1" data-progress aria-label="Позиция">
                    <span data-duration>LIVE</span>
                </div>
                <div class="player-control-row">
                    <div class="player-control-group">
                        <button class="icon-button" type="button" data-action="toggle-play" aria-label="Воспроизвести" title="Воспроизвести">▶</button>
                        <a class="icon-button <?= $previousChannel ? '' : 'muted-control' ?>" href="<?= e($previousWatchUrl) ?>" aria-label="Предыдущий канал" title="Предыдущий канал">⏮</a>
                        <a class="icon-button <?= $nextChannel ? '' : 'muted-control' ?>" href="<?= e($nextWatchUrl) ?>" aria-label="Следующий канал" title="Следующий канал">⏭</a>
                        <button class="icon-button" type="button" data-action="mute" aria-label="Выключить звук" title="Звук">🔊</button>
                        <input class="volume" type="range" min="0" max="1" value="1" step="0.01" data-volume aria-label="Громкость">
                        <span class="player-time"><span data-current-time>00:00</span> / <span data-duration>LIVE</span></span>
                    </div>
                    <div class="player-control-group right">
                        <button class="icon-button text" type="button" data-action="mark-dead" aria-label="Пометить битым" title="Пометить битым">Битый</button>
                        <button class="icon-button text" type="button" data-action="repair-channel" aria-label="Искать замену" title="Искать замену">Замена</button>
                        <button class="icon-button text <?= $isFavorite ? 'active' : '' ?>" type="button" data-action="toggle-favorite" aria-label="Избранное" title="Избранное"><?= $isFavorite ? '★' : '☆' ?></button>
                        <button class="icon-button" type="button" data-action="fullscreen" aria-label="На весь экран" title="На весь экран">⛶</button>
                    </div>
                </div>
            </div>
            <div class="player-message" data-player-message hidden></div>
        </div>
        <aside class="watch-sidebar">
            <section class="now-playing">
                <span class="logo-box big">
                    <?php if (!empty($channel['logo'])): ?>
                        <img src="<?= e((string) $channel['logo']) ?>" alt="">
                    <?php else: ?>
                        <span><?= e(text_initial((string) $channel['name'])) ?></span>
                    <?php endif; ?>
                </span>
                <div>
                    <p class="eyebrow">Сейчас</p>
                    <h1><?= e((string) $channel['name']) ?></h1>
                    <p>
                        <span class="status-badge <?= e((string) $channel['health_status']) ?>"><?= e(health_label((string) $channel['health_status'])) ?></span>
                        <?php if ($quality !== ''): ?><span class="quality-badge"><?= e($quality) ?></span><?php endif; ?>
                        <?php if ($isAudioStream): ?><span class="quality-badge">RADIO</span><?php endif; ?>
                        <?php if ($accessType !== 'open'): ?><span class="access-badge <?= e($accessType) ?>"><?= e(channel_access_label($accessType)) ?></span><?php endif; ?>
                    </p>
                </div>
            </section>

            <dl class="details">
                <div>
                    <dt>Группа</dt>
                    <dd><?= e((string) $channel['genre']) ?></dd>
                </div>
                <div>
                    <dt>Регион</dt>
                    <dd><?= e(trim((string) $channel['country'] . ' ' . (string) $channel['city']) ?: 'не указан') ?></dd>
                </div>
                <div>
                    <dt>Источник</dt>
                    <dd><a href="<?= e(app_url('index.php?source=' . urlencode((string) $channel['source_id']))) ?>"><?= e((string) $channel['source_title']) ?></a></dd>
                </div>
                <div>
                    <dt>Проверка</dt>
                    <dd><?= e($checkedLabel) ?></dd>
                </div>
                <?php if ($accessType !== 'open'): ?>
                    <div>
                        <dt>Доступ</dt>
                        <dd><?= e(channel_access_label($accessType)) ?><?= $accessNotes !== '' ? ': ' . e($accessNotes) : '' ?></dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($channel['last_probe_error'])): ?>
                    <div>
                        <dt>Ошибка</dt>
                        <dd><?= e((string) $channel['last_probe_error']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if ($alternatives): ?>
                <details class="channel-rail collapsible-rail" open data-rail-key="alternatives">
                    <summary>Похожие каналы <small><?= count($alternatives) ?></small></summary>
                    <div class="rail-scroll">
                        <?php foreach ($alternatives as $alternative): ?>
                            <a class="rail-item" href="<?= e(app_url('watch.php?id=' . urlencode((string) $alternative['id']))) ?>">
                                <span><?= e((string) $alternative['name']) ?></span>
                                <small><?= e((string) $alternative['genre']) ?> · <?= e((string) $alternative['source_title']) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if ($genreChannels): ?>
                <details class="channel-rail collapsible-rail" open data-rail-key="same-genre">
                    <summary>В этой группе <small><?= count($genreChannels) ?></small></summary>
                    <div class="rail-scroll">
                        <?php foreach ($genreChannels as $item): ?>
                            <a class="rail-item" href="<?= e(app_url('watch.php?id=' . urlencode((string) $item['id']))) ?>">
                                <span><?= e((string) $item['name']) ?></span>
                                <small><?= e((string) $item['source_title']) ?> · <?= e((string) $item['health_status']) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if ($sourceChannels): ?>
                <details class="channel-rail collapsible-rail" data-rail-key="same-source">
                    <summary>Из этого источника <small><?= count($sourceChannels) ?></small></summary>
                    <div class="rail-scroll">
                        <?php foreach ($sourceChannels as $item): ?>
                            <a class="rail-item" href="<?= e(app_url('watch.php?id=' . urlencode((string) $item['id']))) ?>">
                                <span><?= e((string) $item['name']) ?></span>
                                <small><?= e((string) $item['genre']) ?> · <?= e((string) $item['health_status']) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </aside>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/hls.js@1" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/shaka-player@4/dist/shaka-player.compiled.min.js" defer></script>
<?php endif; ?>
<?php
render_footer();
