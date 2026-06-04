<?php
declare(strict_types=1);

require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/m3u.php';
require_once __DIR__ . '/app/discovery.php';
require_once __DIR__ . '/app/repair.php';
require_once __DIR__ . '/app/jobs.php';
require_once __DIR__ . '/app/source_registry.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['run_job'])) {
    try {
        $request = background_job_request((string) $_GET['run_job'], $_GET);
        start_background_job($request['type'], $request['args']);
        flash_set('success', $request['message']);
    } catch (Throwable $exception) {
        flash_set('error', $exception->getMessage());
    }

    redirect_to('admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cancel_jobs'])) {
    $count = cancel_running_jobs();
    flash_set($count > 0 ? 'success' : 'warning', $count > 0 ? 'Сброшено зависших задач: ' . $count . '.' : 'Активных фоновых задач не найдено.');
    redirect_to('admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add_url') {
            $url = (string) ($_POST['url'] ?? '');
            $title = (string) ($_POST['title'] ?? '');
            $country = (string) ($_POST['country'] ?? '');
            $city = (string) ($_POST['city'] ?? '');
            $inspection = inspect_import_url($url);
            $kind = (string) ($inspection['kind'] ?? 'unknown');

            if ($kind === 'playlist') {
                $playlist = add_url_playlist($title, $url, $country, $city);
                $channelCount = refresh_playlist_channels($playlist, true);
                flash_set('success', 'Это M3U-плейлист. Скачал напрямую по ссылке, веб-поиск не запускал. Каналов добавлено: ' . $channelCount . '.');
            } elseif ($kind === 'stream') {
                $streamTitle = trim($title) !== '' ? $title : infer_name_from_url((string) ($inspection['effective_url'] ?: $url));
                $playlist = add_text_playlist($streamTitle, single_stream_playlist_content($streamTitle, (string) ($inspection['effective_url'] ?: $url)), $country, $city);
                $channelCount = refresh_playlist_channels($playlist, true);
                flash_set('success', 'Это одиночный поток, а не список каналов. Добавил его как отдельный канал. Каналов добавлено: ' . $channelCount . '.');
            } elseif ($kind === 'page') {
                $source = add_discovery_source($title, $url, $country, $city, false);
                $result = scan_discovery_source((string) $source['id']);
                $message = 'Это веб-страница, а не прямой M3U. Добавил ее в источники поиска и просканировал. Найдено кандидатов: ' . (int) ($result['candidate_count'] ?? 0) . '.';
                if ((int) ($result['candidate_count'] ?? 0) > 0) {
                    $message .= ' Проверь блок "Кандидаты" ниже и импортируй нужное.';
                }
                flash_set('warning', $message);
            } else {
                throw new InvalidArgumentException((string) ($inspection['message'] ?? 'Не понял, что это за ссылка.'));
            }
        } elseif ($action === 'add_text') {
            $playlist = add_text_playlist(
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['content'] ?? ''),
                (string) ($_POST['country'] ?? ''),
                (string) ($_POST['city'] ?? '')
            );
            try {
                $channelCount = refresh_playlist_channels($playlist, true);
                flash_set('success', 'Текст M3U разобран. Каналов добавлено: ' . $channelCount . '.');
            } catch (Throwable $refreshException) {
                flash_set('warning', 'Текст M3U сохранен, но разобрать каналы не получилось: ' . $refreshException->getMessage());
            }
        } elseif ($action === 'delete') {
            delete_playlist((string) ($_POST['id'] ?? ''));
            flash_set('success', 'Плейлист удален вместе с его каналами.');
        } elseif ($action === 'update_channel_access') {
            $updated = update_channel_access_settings((string) ($_POST['id'] ?? ''), $_POST);
            flash_set('success', 'Настройки доступа сохранены для канала: ' . (string) ($updated['name'] ?? 'канал') . '.');
            redirect_to('admin.php?channel=' . urlencode((string) ($updated['id'] ?? '')) . '#admin-access');
        } elseif ($action === 'probe_channel_once') {
            $channel = find_channel((string) ($_POST['id'] ?? ''));
            if (!$channel) {
                throw new InvalidArgumentException('Канал не найден.');
            }
            $result = probe_channel_health($channel);
            update_channel_health((string) $channel['id'], $result);
            flash_set('success', 'Канал проверен: ' . (string) $result['status'] . (($result['error'] ?? '') ? '. ' . (string) $result['error'] : '.'));
            redirect_to('admin.php?channel=' . urlencode((string) $channel['id']) . '#admin-access');
        } elseif (in_array($action, background_job_actions(), true)) {
            $request = background_job_request($action, $_POST);
            start_background_job($request['type'], $request['args']);
            flash_set('success', $request['message']);
        } elseif ($action === 'add_discovery_source') {
            add_discovery_source(
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['url'] ?? ''),
                (string) ($_POST['country'] ?? ''),
                (string) ($_POST['city'] ?? ''),
                isset($_POST['auto_import'])
            );
            flash_set('success', 'Источник поиска добавлен. Теперь его можно просканировать.');
        } elseif ($action === 'scan_discovery_source') {
            $result = scan_discovery_source((string) ($_POST['id'] ?? ''));
            $message = 'Сканирование завершено. Найдено кандидатов: ' . $result['candidate_count'] . '.';
            if (($result['imported_count'] ?? 0) > 0) {
                $message .= ' Автоимпортировано: ' . $result['imported_count'] . '.';
            }
            if (($result['renamed_count'] ?? 0) > 0) {
                $message .= ' Переименовано старых каналов: ' . $result['renamed_count'] . '.';
            }
            flash_set('success', $message);
        } elseif ($action === 'import_candidate') {
            import_discovery_candidate((string) ($_POST['id'] ?? ''));
            refresh_all_playlists();
            flash_set('success', 'Кандидат импортирован и каналы обновлены.');
        } elseif ($action === 'ignore_candidate') {
            ignore_discovery_candidate((string) ($_POST['id'] ?? ''));
            flash_set('success', 'Кандидат скрыт.');
        } elseif ($action === 'delete_discovery_source') {
            delete_discovery_source((string) ($_POST['id'] ?? ''));
            flash_set('success', 'Источник поиска удален.');
        } elseif ($action === 'seed_public_sources') {
            seed_public_playlist_sources((int) ($_POST['limit'] ?? 0));
            flash_set('warning', 'Встроенный импорт публичных плейлистов отключен. Добавляй нужные M3U только вручную или через источники поиска.');
        } elseif ($action === 'purge_non_russian_sources') {
            $playlistsSummary = purge_non_russian_playlist_sources();
            $discoverySummary = purge_non_russian_discovery_sources();
            flash_set('success', 'Нерусские источники удалены. Плейлистов: ' . $playlistsSummary['removed'] . ', источников поиска: ' . $discoverySummary['removed'] . '.');
        } elseif ($action === 'add_web_search_provider') {
            add_web_search_provider(
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['url_template'] ?? ''),
                isset($_POST['enabled'])
            );
            flash_set('success', 'Поисковый провайдер добавлен. Теперь алиасы смогут искать через него веб-результаты.');
        } elseif ($action === 'toggle_web_search_provider') {
            toggle_web_search_provider((string) ($_POST['id'] ?? ''), !empty($_POST['enabled']));
            flash_set('success', 'Поисковый провайдер обновлен.');
        } elseif ($action === 'delete_web_search_provider') {
            delete_web_search_provider((string) ($_POST['id'] ?? ''));
            flash_set('success', 'Поисковый провайдер удален.');
        } elseif ($action === 'add_wanted_channel') {
            add_wanted_channel(
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['aliases'] ?? ''),
                (string) ($_POST['category'] ?? ''),
                (int) ($_POST['priority'] ?? 50)
            );
            flash_set('success', 'Желаемый канал добавлен. Запусти внешний поиск по алиасам, когда будешь готов.');
        } elseif ($action === 'scan_wanted_channels') {
            $request = background_job_request('scan_wanted', $_POST);
            start_background_job($request['type'], $request['args']);
            flash_set('success', $request['message']);
        } elseif ($action === 'delete_wanted_channel') {
            delete_wanted_channel((string) ($_POST['id'] ?? ''));
            flash_set('success', 'Желаемый канал удален из отслеживания.');
        }
    } catch (Throwable $exception) {
        flash_set('error', $exception->getMessage());
    }

    redirect_to('admin.php');
}

$sessionKey = session_id();
$playlists = load_playlists(40);
$playlistTotal = playlist_count();
$channelTotal = channel_count(true);
$visibleTotal = channel_count(false);
$discoverySources = load_discovery_sources(30);
$discoveryCandidates = load_discovery_candidates(null, 40);
$webSearchProviders = load_web_search_providers(false, 40);
$healthCounts = health_counts();
$latestJob = latest_job();
$problemChannels = load_problem_channels(40);
$replacementHistory = load_replacement_history(40);
$playlistErrors = array_values(array_filter($playlists, static fn (array $playlist): bool => trim((string) ($playlist['last_error'] ?? '')) !== ''));
$wantedChannels = load_wanted_channels(80);
$wantedSummary = wanted_channels_summary();
$wantedResults = load_wanted_search_results(60);
$catalogInsights = catalog_insights($sessionKey);
$sourceHealthReport = catalog_source_health_report(12);
$duplicateGroups = catalog_duplicate_channel_groups(8);
$parserReport = catalog_parser_report(10);
$prefillImportUrl = trim((string) ($_GET['url'] ?? ''));
$accessEditorChannels = load_access_editor_channels(80);
$selectedAccessChannelId = trim((string) ($_GET['channel'] ?? ''));
if ($selectedAccessChannelId === '' && $accessEditorChannels) {
    $selectedAccessChannelId = (string) ($accessEditorChannels[0]['id'] ?? '');
}
$selectedAccessChannel = $selectedAccessChannelId !== '' ? find_channel($selectedAccessChannelId) : null;
$selectedAccessDiagnosis = $selectedAccessChannel ? channel_playback_diagnosis($selectedAccessChannel) : [];
$latestCheckTime = strtotime((string) $catalogInsights['latest_check']);
$latestCheckLabel = $latestCheckTime ? date('d.m H:i', $latestCheckTime) : 'нет данных';
$repairQueue = (int) $catalogInsights['health']['dead'] + (int) $catalogInsights['health']['unknown'];
$recommendations = catalog_recommendations($catalogInsights, $sourceHealthReport, $playlistErrors, $duplicateGroups);
$environment = [
    'Проект' => APP_ROOT,
    'PHP' => php_binary_path(),
    'SQLite' => DB_FILE,
    'Jobs' => JOBS_DIR,
    'curl' => function_exists('curl_init') ? 'есть' : 'нет',
    'curl_multi' => function_exists('curl_multi_init') ? 'есть' : 'нет',
];

function admin_datetime_label(string $value): string
{
    $timestamp = strtotime($value);
    if (!$timestamp) {
        return 'нет данных';
    }

    return date('d.m H:i', $timestamp);
}

function job_type_label(string $type): string
{
    return match ($type) {
        'import_everything', 'refresh' => 'Обновление каналов',
        'check_channels' => 'Проверка живости',
        'repair_dead' => 'Поиск замен',
        'scan_discovery' => 'Сканирование источников',
        'scan_wanted' => 'Поиск желаемых каналов',
        default => $type !== '' ? $type : 'Фоновая задача',
    };
}

function job_status_label(string $status): string
{
    return match ($status) {
        'running' => 'работает',
        'complete' => 'готово',
        'failed' => 'ошибка',
        default => $status !== '' ? $status : 'нет статуса',
    };
}

function admin_wanted_status_label(string $status): string
{
    return match ($status) {
        'found' => 'найден',
        'unknown' => 'неясно',
        'dead' => 'мертвый',
        'missing' => 'не найден',
        default => $status !== '' ? $status : 'нет статуса',
    };
}

function admin_access_type_options(): array
{
    return [
        'open' => 'Открытый',
        'tokenized' => 'Токен в ссылке',
        'header_required' => 'Нужны headers',
        'auth_required' => 'Авторизация',
        'geo_blocked' => 'Геоблок',
        'drm' => 'DRM',
        'unsupported' => 'Не поддерживается',
    ];
}

function admin_stream_type_options(): array
{
    return [
        'stream' => 'Обычный поток',
        'audio' => 'Радио / аудио',
        'hls' => 'HLS / m3u8',
        'dash' => 'DASH / mpd',
        'udp' => 'UDP',
        'rtp' => 'RTP',
    ];
}

render_header('Плейлисты', 'admin');
?>
<div class="admin-v2">
    <aside class="admin-v2-rail">
        <div class="admin-v2-rail-head">
            <p class="eyebrow">MyIPTV</p>
            <h1>Админка</h1>
            <p>Сначала добавляем плейлист, потом проверяем качество. Поиск и автосканирование отдельно.</p>
        </div>
        <nav class="admin-v2-nav" aria-label="Разделы админки">
            <a href="#admin-start">Добавить</a>
            <a href="#admin-activity">Активность</a>
            <a href="#admin-health">Проверка</a>
            <a href="#admin-access">Доступ</a>
            <a href="#admin-sources">Источники</a>
            <a href="#admin-wanted">Поиск каналов</a>
            <a href="#admin-diagnostics">Диагностика</a>
            <a href="#admin-settings">Настройки</a>
        </nav>
        <div class="admin-v2-rail-stat">
            <span>В каталоге</span>
            <strong data-count-visible><?= $visibleTotal ?></strong>
            <small><?= $playlistTotal ?> плейлистов, <?= $channelTotal ?> каналов всего</small>
        </div>
    </aside>

    <main class="admin-v2-main">
        <section class="admin-v2-hero" id="admin-start">
            <div class="admin-v2-hero-copy">
                <p class="eyebrow">Умное добавление</p>
                <h1>Вставь ссылку. Я сам пойму, что это.</h1>
                <p>M3U читается напрямую, страница уходит в сканер ссылок, одиночный поток добавляется как канал. DRM и авторизацию приложение пометит отдельно.</p>
            </div>

            <form class="admin-v2-import" method="post" data-import-inspector data-import-probe-url="<?= e(app_url('api/import_probe.php')) ?>">
                <input type="hidden" name="action" value="add_url">
                <label class="admin-v2-url-field">
                    <span>Ссылка на плейлист, страницу или поток</span>
                    <input type="url" name="url" required placeholder="https://example.com/playlist.m3u" autocomplete="url" value="<?= e($prefillImportUrl) ?>" data-import-url>
                </label>
                <button class="button primary" type="submit" data-import-submit>Разобрать и добавить</button>
                <div class="admin-v2-import-hint idle" data-import-hint aria-live="polite">
                    <strong data-import-hint-title>Жду ссылку</strong>
                    <span data-import-hint-text>Когда вставишь URL, покажу, что именно с ним сделаю.</span>
                    <small data-import-hint-meta></small>
                </div>
                <details class="admin-v2-soft-details">
                    <summary>Название и метки</summary>
                    <div class="admin-v2-form-grid">
                        <label>
                            <span>Название</span>
                            <input type="text" name="title" placeholder="Русские каналы">
                        </label>
                        <label>
                            <span>Страна</span>
                            <input type="text" name="country" placeholder="RU">
                        </label>
                        <label>
                            <span>Город</span>
                            <input type="text" name="city" placeholder="Москва">
                        </label>
                    </div>
                </details>
            </form>

            <div class="admin-v2-hero-side">
                <article>
                    <span>Плейлистов</span>
                    <strong data-count-playlists><?= $playlistTotal ?></strong>
                </article>
                <article>
                    <span>Каналов</span>
                    <strong data-count-total><?= $channelTotal ?></strong>
                </article>
                <article>
                    <span>Видимых</span>
                    <strong data-count-visible><?= $visibleTotal ?></strong>
                </article>
                <form method="post" data-background-job>
                    <input type="hidden" name="action" value="refresh">
                    <button class="button" type="submit">Перечитать все</button>
                </form>
                <a class="button" href="<?= e(app_url('index.php')) ?>">Открыть каталог</a>
                <details class="admin-v2-soft-details">
                    <summary>Вставить M3U текстом</summary>
                    <form class="admin-v2-stack" method="post">
                        <input type="hidden" name="action" value="add_text">
                        <label>
                            <span>Название</span>
                            <input type="text" name="title" placeholder="Локальный список">
                        </label>
                        <textarea name="content" rows="7" required placeholder="#EXTM3U&#10;#EXTINF:-1 tvg-name=&quot;Канал&quot;,Канал&#10;https://example.com/live/channel.m3u8"></textarea>
                        <button class="button primary" type="submit">Сохранить и разобрать</button>
                    </form>
                </details>
            </div>
        </section>

        <section class="admin-v2-section" id="admin-activity">
            <div class="admin-v2-section-head">
                <div>
                    <p class="eyebrow">Активность</p>
                    <h2>Что сейчас происходит</h2>
                </div>
                <form method="get">
                    <input type="hidden" name="cancel_jobs" value="1">
                    <button class="button ghost" type="submit">Сбросить зависшую</button>
                </form>
            </div>

            <div class="admin-v2-job" data-job-panel data-jobs-url="<?= e(app_url('api/jobs.php')) ?>" data-start-job-url="<?= e(app_url('api/start_job.php')) ?>">
                <div>
                    <strong data-job-status>
                        <?php if ($latestJob): ?>
                            <?= e(job_type_label((string) $latestJob['type'])) ?>: <?= e(job_status_label((string) $latestJob['status'])) ?>.
                        <?php else: ?>
                            Сейчас ничего не выполняется.
                        <?php endif; ?>
                    </strong>
                    <span data-job-progress-message><?= e((string) ($latestJob['progress']['message'] ?? '')) ?></span>
                </div>
                <div class="admin-v2-progress">
                    <span data-job-progress-bar style="width: <?= e((string) ($latestJob['progress']['percent'] ?? 0)) ?>%"></span>
                </div>
                <small data-job-progress-percent><?= e((string) ($latestJob['progress']['percent'] ?? 0)) ?>%</small>
                <details class="admin-v2-soft-details">
                    <summary>Технический журнал</summary>
                    <pre class="job-output" data-job-output><?php if ($latestJob && !empty($latestJob['output'])): ?><?= e((string) $latestJob['output']) ?><?php endif; ?></pre>
                </details>
            </div>

            <?php if ($recommendations): ?>
                <div class="admin-v2-alert-grid">
                    <?php foreach ($recommendations as $item): ?>
                        <article class="admin-v2-alert <?= e((string) $item['tone']) ?>">
                            <div>
                                <strong><?= e((string) $item['title']) ?></strong>
                                <span><?= e((string) $item['body']) ?></span>
                            </div>
                            <a class="button" href="<?= e((string) $item['url']) ?>"><?= e((string) $item['action']) ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-v2-section" id="admin-health">
            <div class="admin-v2-section-head">
                <div>
                    <p class="eyebrow">Проверка</p>
                    <h2><?= (int) $catalogInsights['live_ratio'] ?>% каналов работают</h2>
                    <p>Видимых: <?= (int) $catalogInsights['visible'] ?>. Не проверены или требуют ремонта: <?= $repairQueue ?>. Последняя проверка: <?= e($latestCheckLabel) ?>.</p>
                </div>
            </div>
            <div class="admin-v2-metrics">
                <a href="<?= e(app_url('index.php?quick=live')) ?>"><span>Живые</span><strong><?= (int) $catalogInsights['health']['live'] ?></strong><small><?= (int) $catalogInsights['hls_count'] ?> HLS</small></a>
                <a href="<?= e(app_url('index.php?quick=unchecked')) ?>"><span>Проверить</span><strong><?= (int) $catalogInsights['health']['unknown'] ?></strong><small><?= (int) $catalogInsights['stale_count'] ?> старых</small></a>
                <a href="<?= e(app_url('index.php?show_dead=1&health=dead')) ?>"><span>Ремонт</span><strong><?= (int) $catalogInsights['health']['dead'] ?></strong><small><?= count($replacementHistory) ?> замен</small></a>
                <a href="<?= e(app_url('index.php?quick=logos')) ?>"><span>Логотипы</span><strong><?= (int) $catalogInsights['logo_ratio'] ?>%</strong><small><?= (int) $catalogInsights['logo_count'] ?> шт.</small></a>
                <a href="<?= e(app_url('index.php?show_dead=1')) ?>"><span>Особый доступ</span><strong><?= (int) $catalogInsights['protected_count'] ?></strong><small><?= (int) $catalogInsights['drm_count'] ?> DRM</small></a>
            </div>
            <div class="admin-v2-actions">
                <form method="post" data-background-job>
                    <input type="hidden" name="action" value="check_channels">
                    <label>
                        <span>Сколько проверить</span>
                        <input type="number" name="limit" min="0" value="0">
                    </label>
                    <button class="button primary" type="submit">Проверить</button>
                </form>
                <form method="post" data-background-job>
                    <input type="hidden" name="action" value="repair_dead">
                    <label>
                        <span>Мертвых за проход</span>
                        <input type="number" name="channel_limit" min="1" value="25">
                    </label>
                    <label>
                        <span>Лимит источников</span>
                        <input type="number" name="source_limit" min="0" value="0">
                    </label>
                    <button class="button primary" type="submit">Искать замену</button>
                </form>
            </div>
        </section>

        <section class="admin-v2-section" id="admin-access">
            <div class="admin-v2-section-head">
                <div>
                    <p class="eyebrow">Доступ</p>
                    <h2>Почему канал не играет</h2>
                    <p>Здесь правятся поток, заголовки, тип доступа и license-настройки выбранного канала.</p>
                </div>
                <?php if ($selectedAccessChannel): ?>
                    <a class="button" href="<?= e(app_url('watch.php?id=' . urlencode((string) $selectedAccessChannel['id']))) ?>">Открыть плеер</a>
                <?php endif; ?>
            </div>

            <?php if (!$accessEditorChannels && !$selectedAccessChannel): ?>
                <div class="admin-v2-empty">
                    <strong>Каналов для ручной правки пока нет</strong>
                    <span>После проверки здесь появятся dead/unknown, DRM и источники с особыми заголовками.</span>
                </div>
            <?php else: ?>
                <div class="admin-v2-access-layout">
                    <div class="admin-v2-card">
                        <form class="admin-v2-stack" method="get">
                            <label>
                                <span>Канал</span>
                                <select name="channel">
                                    <?php foreach ($accessEditorChannels as $item): ?>
                                        <?php $itemId = (string) ($item['id'] ?? ''); ?>
                                        <option value="<?= e($itemId) ?>" <?= $itemId === $selectedAccessChannelId ? 'selected' : '' ?>>
                                            <?= e((string) ($item['name'] ?? 'Канал')) ?> · <?= e(channel_access_label((string) ($item['access_type'] ?? 'open'))) ?> · <?= e((string) ($item['health_status'] ?? 'unknown')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if ($selectedAccessChannel && !in_array((string) $selectedAccessChannel['id'], array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $accessEditorChannels), true)): ?>
                                        <option value="<?= e((string) $selectedAccessChannel['id']) ?>" selected><?= e((string) $selectedAccessChannel['name']) ?></option>
                                    <?php endif; ?>
                                </select>
                            </label>
                            <button class="button" type="submit">Выбрать</button>
                        </form>

                        <?php if ($selectedAccessChannel): ?>
                            <div class="admin-v2-access-summary">
                                <strong><?= e((string) $selectedAccessChannel['name']) ?></strong>
                                <small><?= e((string) $selectedAccessChannel['source_title']) ?> · <?= e((string) $selectedAccessChannel['health_status']) ?><?= !empty($selectedAccessChannel['last_http_status']) ? ' · HTTP ' . e((string) $selectedAccessChannel['last_http_status']) : '' ?></small>
                                <code><?= e((string) $selectedAccessChannel['url']) ?></code>
                            </div>

                            <div class="admin-v2-alert-grid compact">
                                <?php foreach ($selectedAccessDiagnosis as $item): ?>
                                    <article class="admin-v2-alert <?= e((string) $item['tone']) ?>">
                                        <div>
                                            <strong><?= e((string) $item['title']) ?></strong>
                                            <span><?= e((string) $item['body']) ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($selectedAccessChannel): ?>
                        <form class="admin-v2-card-form" method="post">
                            <input type="hidden" name="action" value="update_channel_access">
                            <input type="hidden" name="id" value="<?= e((string) $selectedAccessChannel['id']) ?>">
                            <label>
                                <span>Stream URL</span>
                                <input type="url" name="url" value="<?= e((string) $selectedAccessChannel['url']) ?>" required>
                            </label>
                            <div class="admin-v2-form-grid">
                                <label>
                                    <span>Тип потока</span>
                                    <select name="stream_type">
                                        <?php foreach (admin_stream_type_options() as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= $value === (string) ($selectedAccessChannel['stream_type'] ?? 'stream') ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Тип доступа</span>
                                    <select name="access_type">
                                        <?php foreach (admin_access_type_options() as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= $value === channel_access_type((string) ($selectedAccessChannel['access_type'] ?? 'open')) ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>DRM system</span>
                                    <input type="text" name="drm_system" value="<?= e((string) ($selectedAccessChannel['drm_system'] ?? '')) ?>" placeholder="com.widevine.alpha">
                                </label>
                                <label>
                                    <span>License URL</span>
                                    <input type="url" name="license_url" value="<?= e((string) ($selectedAccessChannel['license_url'] ?? '')) ?>">
                                </label>
                            </div>
                            <label>
                                <span>Заметка</span>
                                <textarea name="access_notes" rows="3" placeholder="Что нужно этому каналу: Referer, Cookie, токен, регион, license server..."><?= e((string) ($selectedAccessChannel['access_notes'] ?? '')) ?></textarea>
                            </label>
                            <div class="admin-v2-form-grid">
                                <label>
                                    <span>Stream headers</span>
                                    <textarea name="stream_headers" rows="6" placeholder="Referer: https://example.com/&#10;Origin: https://example.com"><?= e(channel_headers_json_to_text((string) ($selectedAccessChannel['stream_headers'] ?? ''))) ?></textarea>
                                </label>
                                <label>
                                    <span>License headers</span>
                                    <textarea name="license_headers" rows="6" placeholder="Authorization: Bearer ...&#10;X-Token: ..."><?= e(channel_headers_json_to_text((string) ($selectedAccessChannel['license_headers'] ?? ''))) ?></textarea>
                                </label>
                            </div>
                            <div class="inline-actions">
                                <button class="button primary" type="submit">Сохранить доступ</button>
                                <button class="button" name="action" value="probe_channel_once" type="submit">Проверить канал</button>
                                <a class="button" href="<?= e(app_url('watch.php?id=' . urlencode((string) $selectedAccessChannel['id']))) ?>">Плеер</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-v2-section" id="admin-sources">
            <div class="admin-v2-section-head">
                <div>
                    <p class="eyebrow">Источники</p>
                    <h2>Плейлисты и продвинутый поиск ссылок</h2>
                    <p>Список ниже — уже добавленные плейлисты. Автопоиск и веб-поиск не участвуют в обычном добавлении M3U.</p>
                </div>
                <form method="post" data-background-job>
                    <input type="hidden" name="action" value="refresh">
                    <button class="button primary" type="submit">Перечитать плейлисты</button>
                </form>
            </div>

            <?php if (!$playlists): ?>
                <div class="admin-v2-empty">
                    <strong>Плейлистов пока нет</strong>
                    <span>Добавь прямую M3U-ссылку в первом блоке.</span>
                </div>
            <?php else: ?>
                <div class="admin-v2-list">
                    <?php foreach ($playlists as $playlist): ?>
                        <article class="admin-v2-row">
                            <div>
                                <strong><?= e((string) ($playlist['title'] ?? 'Плейлист')) ?></strong>
                                <small><?= e((string) ($playlist['source'] ?? '')) ?></small>
                                <?php if (!empty($playlist['last_error'])): ?>
                                    <em><?= e((string) $playlist['last_error']) ?></em>
                                <?php endif; ?>
                            </div>
                            <span><?= (int) ($playlist['channel_count'] ?? 0) ?> каналов</span>
                            <?php if (!empty($playlist['default_country'])): ?><span><?= e((string) $playlist['default_country']) ?></span><?php endif; ?>
                            <form method="post" onsubmit="return confirm('Удалить плейлист и его каналы?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= e((string) ($playlist['id'] ?? '')) ?>">
                                <button class="button danger" type="submit">Удалить</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="admin-v2-split">
                <details class="admin-v2-tool" open>
                    <summary>Автопоиск M3U/M3U8 на страницах</summary>
                    <form class="admin-v2-stack" method="post">
                        <input type="hidden" name="action" value="add_discovery_source">
                        <label>
                            <span>Название</span>
                            <input type="text" name="title" placeholder="Страница с плейлистами">
                        </label>
                        <label>
                            <span>Страница для поиска</span>
                            <input type="url" name="url" required placeholder="https://example.com/page-with-playlists">
                        </label>
                        <div class="admin-v2-form-grid">
                            <label>
                                <span>Страна</span>
                                <input type="text" name="country" placeholder="RU">
                            </label>
                            <label>
                                <span>Город</span>
                                <input type="text" name="city" placeholder="Москва">
                            </label>
                            <label class="admin-v2-check">
                                <input type="checkbox" name="auto_import" value="1">
                                <span>Автоимпорт найденного</span>
                            </label>
                        </div>
                        <button class="button primary" type="submit">Добавить страницу</button>
                    </form>
                    <form method="post" data-background-job>
                        <input type="hidden" name="action" value="scan_all_discovery">
                        <button class="button" type="submit">Сканировать все страницы</button>
                    </form>
                    <?php if ($discoverySources): ?>
                        <div class="admin-v2-list compact">
                            <?php foreach ($discoverySources as $source): ?>
                                <article class="admin-v2-row">
                                    <div>
                                        <strong><?= e((string) $source['title']) ?></strong>
                                        <small><?= e((string) $source['url']) ?></small>
                                    </div>
                                    <span><?= (int) $source['candidate_count'] ?> кандидатов</span>
                                    <form class="inline-actions" method="post">
                                        <input type="hidden" name="id" value="<?= e((string) $source['id']) ?>">
                                        <button class="button" name="action" value="scan_discovery_source" type="submit">Сканировать</button>
                                        <button class="button danger" name="action" value="delete_discovery_source" type="submit" onclick="return confirm('Удалить источник и его кандидатов?');">Удалить</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </details>

                <details class="admin-v2-tool">
                    <summary>Веб-поиск отдельных каналов</summary>
                    <form class="admin-v2-stack" method="post">
                        <input type="hidden" name="action" value="add_web_search_provider">
                        <label>
                            <span>Название поисковика</span>
                            <input type="text" name="title" placeholder="Мой веб-поиск">
                        </label>
                        <label>
                            <span>URL или шаблон</span>
                            <input type="url" name="url_template" required placeholder="https://search.example/search?q={query}">
                        </label>
                        <label class="admin-v2-check">
                            <input type="checkbox" name="enabled" value="1" checked>
                            <span>Включен</span>
                        </label>
                        <button class="button primary" type="submit">Добавить поисковик</button>
                    </form>
                    <?php if ($webSearchProviders): ?>
                        <div class="admin-v2-list compact">
                            <?php foreach ($webSearchProviders as $provider): ?>
                                <article class="admin-v2-row">
                                    <div>
                                        <strong><?= e((string) $provider['title']) ?></strong>
                                        <small><?= e((string) $provider['url_template']) ?></small>
                                    </div>
                                    <span><?= !empty($provider['enabled']) ? 'включен' : 'выключен' ?></span>
                                    <form class="inline-actions" method="post">
                                        <input type="hidden" name="id" value="<?= e((string) $provider['id']) ?>">
                                        <input type="hidden" name="action" value="toggle_web_search_provider">
                                        <input type="hidden" name="enabled" value="<?= !empty($provider['enabled']) ? '0' : '1' ?>">
                                        <button class="button" type="submit"><?= !empty($provider['enabled']) ? 'Выключить' : 'Включить' ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Удалить поисковый провайдер?');">
                                        <input type="hidden" name="action" value="delete_web_search_provider">
                                        <input type="hidden" name="id" value="<?= e((string) $provider['id']) ?>">
                                        <button class="button danger" type="submit">Удалить</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </details>
            </div>

            <?php if ($discoveryCandidates): ?>
                <div class="admin-v2-subsection">
                    <h3>Найденные кандидаты</h3>
                    <div class="admin-v2-list compact">
                        <?php foreach ($discoveryCandidates as $candidate): ?>
                            <article class="admin-v2-row">
                                <div>
                                    <strong><?= e((string) $candidate['title']) ?></strong>
                                    <small><?= e((string) $candidate['url']) ?></small>
                                </div>
                                <span><?= e(strtoupper((string) $candidate['kind'])) ?></span>
                                <span><?= e((string) $candidate['status']) ?></span>
                                <form class="inline-actions" method="post">
                                    <input type="hidden" name="id" value="<?= e((string) $candidate['id']) ?>">
                                    <?php if (($candidate['status'] ?? '') !== 'imported'): ?>
                                        <button class="button primary" name="action" value="import_candidate" type="submit">Импорт</button>
                                    <?php endif; ?>
                                    <?php if (($candidate['status'] ?? '') !== 'ignored'): ?>
                                        <button class="button" name="action" value="ignore_candidate" type="submit">Скрыть</button>
                                    <?php endif; ?>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-v2-section" id="admin-wanted">
            <div class="admin-v2-section-head">
                <div>
                    <p class="eyebrow">Поиск каналов</p>
                    <h2>Найти отдельный канал</h2>
                    <p>Этот режим ищет по названию через веб-поиск и страницы автопоиска. Обычные плейлисты здесь не становятся поисковыми источниками.</p>
                </div>
                <form method="post" data-background-job>
                    <input type="hidden" name="action" value="scan_wanted">
                    <button class="button primary" type="submit">Искать каналы</button>
                </form>
            </div>
            <div class="admin-v2-split">
                <form class="admin-v2-card-form" method="post">
                    <input type="hidden" name="action" value="add_wanted_channel">
                    <label>
                        <span>Название</span>
                        <input type="text" name="title" placeholder="Название канала">
                    </label>
                    <textarea name="aliases" rows="3" placeholder="Матч Премьер; Match Premier; другое написание"></textarea>
                    <div class="admin-v2-form-grid">
                        <label>
                            <span>Категория</span>
                            <input type="text" name="category" placeholder="Любая метка">
                        </label>
                        <label>
                            <span>Приоритет</span>
                            <input type="number" name="priority" min="1" max="100" value="70">
                        </label>
                    </div>
                    <button class="button primary" type="submit">Добавить в отслеживание</button>
                </form>

                <div class="admin-v2-card">
                    <div class="admin-v2-mini-metrics">
                        <span>Найдено <strong><?= (int) $wantedSummary['found'] ?></strong></span>
                        <span>Неясно <strong><?= (int) $wantedSummary['unknown'] ?></strong></span>
                        <span>Мертвые <strong><?= (int) $wantedSummary['dead'] ?></strong></span>
                        <span>Не найдено <strong><?= (int) $wantedSummary['missing'] ?></strong></span>
                    </div>
                </div>
            </div>

            <?php if (!$wantedChannels): ?>
                <div class="admin-v2-empty">
                    <strong>Желаемых каналов пока нет</strong>
                    <span>Добавь название канала, потом запусти поиск.</span>
                </div>
            <?php else: ?>
                <div class="admin-v2-list">
                    <?php foreach ($wantedChannels as $wanted): ?>
                        <?php
                            $wantedStatus = (string) ($wanted['status'] ?? 'missing');
                            $foundId = (string) ($wanted['last_found_channel_id'] ?? '');
                        ?>
                        <article class="admin-v2-row">
                            <div>
                                <strong><?= e((string) $wanted['title']) ?></strong>
                                <small><?= e(str_replace("\n", ' · ', (string) ($wanted['aliases'] ?? ''))) ?></small>
                                <?php if (!empty($wanted['last_search_note'])): ?><em><?= e((string) $wanted['last_search_note']) ?></em><?php endif; ?>
                            </div>
                            <span><?= e(admin_wanted_status_label($wantedStatus)) ?></span>
                            <span><?= e((string) $wanted['category']) ?></span>
                            <div class="inline-actions">
                                <?php if ($foundId !== ''): ?>
                                    <a class="button" href="<?= e(app_url('watch.php?id=' . urlencode($foundId))) ?>">Плеер</a>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Удалить из желаемых?');">
                                    <input type="hidden" name="action" value="delete_wanted_channel">
                                    <input type="hidden" name="id" value="<?= e((string) $wanted['id']) ?>">
                                    <button class="button danger" type="submit">Удалить</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($wantedResults): ?>
                <details class="admin-v2-tool">
                    <summary>История внешнего поиска</summary>
                    <div class="admin-v2-list compact">
                        <?php foreach ($wantedResults as $result): ?>
                            <article class="admin-v2-row">
                                <div>
                                    <strong><?= e((string) ($result['wanted_title'] ?: $result['title'] ?: $result['alias'])) ?></strong>
                                    <small><?= e((string) ($result['title'] ?: $result['url'])) ?></small>
                                </div>
                                <span><?= e(strtoupper((string) $result['kind'])) ?></span>
                                <span><?= e((string) $result['status']) ?></span>
                                <small><?= e((string) ($result['http_status'] ?? '')) ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <section class="admin-v2-section" id="admin-diagnostics">
            <div class="admin-v2-section-head">
                <div>
                    <p class="eyebrow">Диагностика</p>
                    <h2>Ошибки, качество и быстрые переходы</h2>
                </div>
                <a class="button" href="<?= e(app_url('index.php?show_dead=1&health=dead')) ?>">Открыть проблемные</a>
            </div>

            <div class="admin-v2-split">
                <div class="admin-v2-card">
                    <h3>Проблемные каналы</h3>
                    <?php if (!$problemChannels): ?>
                        <p class="muted">Проблемных каналов сейчас нет.</p>
                    <?php else: ?>
                        <div class="admin-v2-list compact">
                            <?php foreach ($problemChannels as $item): ?>
                                <article class="admin-v2-row">
                                    <div>
                                        <strong><?= e((string) $item['name']) ?></strong>
                                        <small><?= e((string) ($item['last_probe_error'] ?? $item['source_title'] ?? '')) ?></small>
                                    </div>
                                    <span><?= e((string) $item['health_status']) ?></span>
                                    <a class="button" href="<?= e(app_url('watch.php?id=' . urlencode((string) $item['id']))) ?>">Плеер</a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="admin-v2-card">
                    <h3>Ошибки источников</h3>
                    <?php if (!$playlistErrors): ?>
                        <p class="muted">Источники без текущих ошибок.</p>
                    <?php else: ?>
                        <div class="admin-v2-list compact">
                            <?php foreach ($playlistErrors as $playlist): ?>
                                <article class="admin-v2-row">
                                    <div>
                                        <strong><?= e((string) $playlist['title']) ?></strong>
                                        <small><?= e((string) $playlist['last_error']) ?></small>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <details class="admin-v2-tool" open>
                <summary>Качество каталога</summary>
                <div class="admin-v2-quality">
                    <div>
                        <strong><?= (int) $parserReport['total'] ?> каналов разобрано</strong>
                        <p class="muted">Покрытие метаданных показывает, насколько источники дают логотипы, группы, страны и tvg-id.</p>
                    </div>
                    <div class="admin-v2-mini-metrics">
                        <?php foreach ($parserReport['fields'] as $field): ?>
                            <span><?= e((string) $field['label']) ?> <strong><?= (int) $field['ratio'] ?>%</strong></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($sourceHealthReport): ?>
                    <h3>Источники по риску</h3>
                    <div class="admin-v2-list compact">
                        <?php foreach ($sourceHealthReport as $source): ?>
                            <article class="admin-v2-row">
                                <div>
                                    <strong><?= e((string) $source['source_title']) ?></strong>
                                    <small><?= (int) $source['total'] ?> каналов · проверка <?= e(admin_datetime_label((string) $source['last_checked_at'])) ?></small>
                                </div>
                                <span><?= (int) $source['attention_ratio'] ?>% риск</span>
                                <a class="button" href="<?= e(app_url('index.php?source=' . urlencode((string) $source['source_id']) . '&show_dead=1')) ?>">Открыть</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </details>

            <div class="admin-v2-metrics">
                <a href="<?= e(app_url('index.php?health=live')) ?>"><span>Live</span><strong><?= (int) $healthCounts['live'] ?></strong></a>
                <a href="<?= e(app_url('index.php?health=unknown')) ?>"><span>Unknown</span><strong><?= (int) $healthCounts['unknown'] ?></strong></a>
                <a href="<?= e(app_url('index.php?show_dead=1&health=dead')) ?>"><span>Dead</span><strong><?= (int) $healthCounts['dead'] ?></strong></a>
                <a href="<?= e(app_url('index.php?favorite=1')) ?>"><span>Избранное</span><strong><?= count(load_channel_favorites(session_id())) ?></strong></a>
            </div>

            <?php if ($replacementHistory): ?>
                <details class="admin-v2-tool">
                    <summary>История автозамен</summary>
                    <div class="admin-v2-list compact">
                        <?php foreach ($replacementHistory as $row): ?>
                            <article class="admin-v2-row">
                                <div>
                                    <strong><?= e((string) $row['old_name']) ?></strong>
                                    <small><?= e((string) $row['replacement_name']) ?> <?= $row['replacement_url'] ? '· ' . e((string) $row['replacement_url']) : '' ?></small>
                                </div>
                                <span><?= e((string) $row['status']) ?></span>
                                <span><?= e((string) round((float) $row['score'], 1)) ?></span>
                                <small><?= e(date('d.m H:i', strtotime((string) $row['created_at']))) ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <section class="admin-v2-section" id="admin-settings">
            <div class="admin-v2-section-head">
                <div>
                    <p class="eyebrow">Настройки</p>
                    <h2>Окружение</h2>
                    <p>Где лежит проект, какой PHP используется и включены ли сетевые расширения.</p>
                </div>
            </div>
            <dl class="admin-v2-settings">
                <?php foreach ($environment as $label => $value): ?>
                    <div>
                        <dt><?= e($label) ?></dt>
                        <dd><?= e((string) $value) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    </main>
</div>
<?php
render_footer();
