<?php
declare(strict_types=1);

require_once __DIR__ . '/app/layout.php';

$sessionKey = session_id();
$includeDead = !empty($_GET['show_dead']);
$viewParam = (string) ($_GET['view'] ?? 'cards');
$view = in_array($viewParam, ['cards', 'table', 'logos'], true) ? $viewParam : 'cards';
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'genre' => trim((string) ($_GET['genre'] ?? '')),
    'country' => normalize_country_value((string) ($_GET['country'] ?? '')),
    'city' => trim((string) ($_GET['city'] ?? '')),
    'source' => trim((string) ($_GET['source'] ?? '')),
    'quick' => trim((string) ($_GET['quick'] ?? '')),
    'health' => trim((string) ($_GET['health'] ?? '')),
    'sort' => trim((string) ($_GET['sort'] ?? 'health')),
    'favorite' => !empty($_GET['favorite']),
    'favorite_session' => $sessionKey,
    'has_logo' => $view === 'logos',
];

$perPage = 240;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalChannels = channel_count($includeDead);
$visibleChannels = channel_count(false);
$deadHidden = max(0, channel_count(true) - $visibleChannels);
$filteredTotal = count_filtered_channels($filters, $includeDead);
$channels = query_channels($filters, $perPage, $offset, $includeDead);
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
$favoriteIds = array_flip(load_channel_favorites($sessionKey));

$genres = unique_channel_values('genre');
$countries = unique_channel_values('country');
$cities = unique_channel_values('city');
$playlists = load_playlists();
$insights = catalog_insights($sessionKey);
$topGenres = catalog_top_values('genre', 6);
$topCountries = catalog_top_values('country', 6);
$topSources = catalog_top_values('source_title', 6);

function catalog_url(array $changes = []): string
{
    $query = array_merge($_GET, $changes);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || $value === false) {
            unset($query[$key]);
        }
    }
    unset($query['page']);

    return app_url('index.php' . ($query ? '?' . http_build_query($query) : ''));
}

function catalog_datetime_label(string $value): string
{
    $timestamp = strtotime($value);
    if (!$timestamp) {
        return 'нет данных';
    }

    return date('d.m H:i', $timestamp);
}

render_header('Каталог каналов', 'catalog');
?>
<section class="hero-panel">
    <div>
        <p class="eyebrow">Личный IPTV-каталог</p>
        <h1>Каналы, группы и быстрый возврат к просмотру.</h1>
        <p class="lede">Добавь свои M3U/M3U8-плейлисты, обнови базу и смотри потоки через встроенный плеер.</p>
    </div>
    <div class="hero-actions">
        <a class="button primary" href="<?= e(app_url('admin.php')) ?>">Добавить плейлист</a>
        <a class="button" href="<?= e(app_url('watch.php')) ?>">Открыть плеер</a>
    </div>
</section>

<section class="resume-panel" data-resume-panel hidden>
    <div>
        <p class="eyebrow">Продолжить</p>
        <h2 data-resume-title>Последний канал</h2>
        <p data-resume-meta></p>
    </div>
    <a class="button primary" data-resume-link href="<?= e(app_url('watch.php')) ?>">Смотреть</a>
</section>

<nav class="quick-filters">
    <a class="<?= $filters['quick'] === '' && empty($filters['favorite']) ? 'active' : '' ?>" href="<?= e(catalog_url(['quick' => '', 'favorite' => ''])) ?>">Все</a>
    <a class="<?= $filters['quick'] === 'live' ? 'active' : '' ?>" href="<?= e(catalog_url(['quick' => 'live', 'favorite' => '', 'health' => ''])) ?>">Live</a>
    <a class="<?= $filters['quick'] === 'unchecked' ? 'active' : '' ?>" href="<?= e(catalog_url(['quick' => 'unchecked', 'favorite' => '', 'health' => ''])) ?>">На проверку</a>
    <a class="<?= $filters['quick'] === 'wanted' ? 'active' : '' ?>" href="<?= e(catalog_url(['quick' => 'wanted', 'favorite' => ''])) ?>">Желаемые</a>
    <a class="<?= $filters['quick'] === 'radio' ? 'active' : '' ?>" href="<?= e(catalog_url(['quick' => 'radio', 'favorite' => ''])) ?>">Радио</a>
    <a class="<?= $filters['quick'] === 'hd' ? 'active' : '' ?>" href="<?= e(catalog_url(['quick' => 'hd', 'favorite' => ''])) ?>">HD</a>
    <a class="<?= $filters['quick'] === '4k' ? 'active' : '' ?>" href="<?= e(catalog_url(['quick' => '4k', 'favorite' => ''])) ?>">4K</a>
    <a class="<?= $filters['quick'] === 'logos' ? 'active' : '' ?>" href="<?= e(catalog_url(['quick' => 'logos', 'favorite' => ''])) ?>">С логотипами</a>
    <a class="<?= !empty($filters['favorite']) ? 'active' : '' ?>" href="<?= e(catalog_url(['favorite' => '1', 'quick' => ''])) ?>">Избранное</a>
</nav>

<?php if ($totalChannels > 0): ?>
    <section class="catalog-dashboard" aria-label="Аналитика каталога">
        <a class="metric-card live" href="<?= e(catalog_url(['quick' => 'live', 'health' => '', 'favorite' => ''])) ?>">
            <span>Live</span>
            <strong><?= (int) $insights['health']['live'] ?></strong>
            <small><?= (int) $insights['live_ratio'] ?>% базы</small>
        </a>
        <a class="metric-card warning" href="<?= e(catalog_url(['quick' => 'unchecked', 'health' => '', 'favorite' => ''])) ?>">
            <span>Проверить</span>
            <strong><?= (int) $insights['health']['unknown'] ?></strong>
            <small><?= (int) $insights['stale_count'] ?> устарели</small>
        </a>
        <a class="metric-card danger" href="<?= e(catalog_url(['show_dead' => '1', 'health' => 'dead', 'quick' => '', 'favorite' => ''])) ?>">
            <span>Dead</span>
            <strong><?= (int) $insights['health']['dead'] ?></strong>
            <small><?= (int) $insights['dead_ratio'] ?>% скрыто</small>
        </a>
        <a class="metric-card" href="<?= e(catalog_url(['quick' => 'logos', 'favorite' => ''])) ?>">
            <span>Логотипы</span>
            <strong><?= (int) $insights['logo_count'] ?></strong>
            <small><?= (int) $insights['logo_ratio'] ?>% видимых</small>
        </a>
        <a class="metric-card" href="<?= e(catalog_url(['favorite' => '1', 'quick' => ''])) ?>">
            <span>Избранное</span>
            <strong><?= (int) $insights['favorite_count'] ?></strong>
            <small><?= (int) $insights['recent_count'] ?> с позицией</small>
        </a>
    </section>

    <section class="insight-panel">
        <div class="insight-copy">
            <p class="eyebrow">Состояние базы</p>
            <h2><?= (int) $insights['visible'] ?> видимых каналов из <?= (int) $insights['health']['total'] ?></h2>
            <p>
                Источников: <?= (int) $insights['source_count'] ?>,
                групп: <?= (int) $insights['genre_count'] ?>,
                стран: <?= (int) $insights['country_count'] ?>,
                HLS: <?= (int) $insights['hls_count'] ?>,
                радио: <?= (int) $insights['audio_count'] ?>.
                Последняя проверка: <?= e(catalog_datetime_label((string) $insights['latest_check'])) ?>.
            </p>
        </div>
        <div class="health-bars" style="--live: <?= (int) $insights['live_ratio'] ?>%; --unknown: <?= (int) $insights['unknown_ratio'] ?>%; --dead: <?= (int) $insights['dead_ratio'] ?>%;">
            <span class="bar-live"></span>
            <span class="bar-unknown"></span>
            <span class="bar-dead"></span>
        </div>
        <div class="top-slices">
            <div>
                <h3>Топ групп</h3>
                <?php foreach ($topGenres as $item): ?>
                    <a href="<?= e(catalog_url(['genre' => $item['value'], 'quick' => '', 'favorite' => ''])) ?>"><?= e($item['value']) ?> <span><?= (int) $item['count'] ?></span></a>
                <?php endforeach; ?>
            </div>
            <div>
                <h3>Топ стран</h3>
                <?php foreach ($topCountries as $item): ?>
                    <a href="<?= e(catalog_url(['country' => $item['value'], 'quick' => '', 'favorite' => ''])) ?>"><?= e($item['value']) ?> <span><?= (int) $item['count'] ?></span></a>
                <?php endforeach; ?>
            </div>
            <div>
                <h3>Топ источников</h3>
                <?php foreach ($topSources as $item): ?>
                    <span><?= e($item['value']) ?> <b><?= (int) $item['count'] ?></b></span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<form class="filters" method="get" action="<?= e(app_url('index.php')) ?>">
    <label>
        <span>Поиск</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Название, жанр, город">
    </label>
    <input type="hidden" name="quick" value="<?= e($filters['quick']) ?>">
    <?php if (!empty($filters['favorite'])): ?><input type="hidden" name="favorite" value="1"><?php endif; ?>
    <label>
        <span>Жанр</span>
        <select name="genre">
            <option value="">Все жанры</option>
            <?php foreach ($genres as $genre): ?>
                <option value="<?= e($genre) ?>" <?= $filters['genre'] === $genre ? 'selected' : '' ?>><?= e($genre) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Страна</span>
        <select name="country">
            <option value="">Все страны</option>
            <?php foreach ($countries as $country): ?>
                <option value="<?= e($country) ?>" <?= $filters['country'] === $country ? 'selected' : '' ?>><?= e($country) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Город</span>
        <select name="city">
            <option value="">Все города</option>
            <?php foreach ($cities as $city): ?>
                <option value="<?= e($city) ?>" <?= $filters['city'] === $city ? 'selected' : '' ?>><?= e($city) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Статус</span>
        <select name="health">
            <option value="">Все видимые</option>
            <option value="live" <?= $filters['health'] === 'live' ? 'selected' : '' ?>>live</option>
            <option value="unknown" <?= $filters['health'] === 'unknown' ? 'selected' : '' ?>>unknown</option>
            <?php if ($includeDead): ?><option value="dead" <?= $filters['health'] === 'dead' ? 'selected' : '' ?>>dead</option><?php endif; ?>
        </select>
    </label>
    <label>
        <span>Источник</span>
        <select name="source">
            <option value="">Все источники</option>
            <?php foreach ($playlists as $playlist): ?>
                <?php $id = (string) ($playlist['id'] ?? ''); ?>
                <option value="<?= e($id) ?>" <?= $filters['source'] === $id ? 'selected' : '' ?>><?= e((string) ($playlist['title'] ?? $id)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Вид</span>
        <select name="view">
            <option value="cards" <?= $view === 'cards' ? 'selected' : '' ?>>Плитки</option>
            <option value="table" <?= $view === 'table' ? 'selected' : '' ?>>Таблица</option>
            <option value="logos" <?= $view === 'logos' ? 'selected' : '' ?>>Только логотипы</option>
        </select>
    </label>
    <label>
        <span>Сортировка</span>
        <select name="sort">
            <option value="health" <?= $filters['sort'] === 'health' ? 'selected' : '' ?>>Живые сверху</option>
            <option value="name" <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>По имени</option>
            <option value="recent" <?= $filters['sort'] === 'recent' ? 'selected' : '' ?>>Последние</option>
            <option value="source" <?= $filters['sort'] === 'source' ? 'selected' : '' ?>>По источнику</option>
            <option value="country" <?= $filters['sort'] === 'country' ? 'selected' : '' ?>>По стране</option>
        </select>
    </label>
    <label class="check-label">
        <input type="checkbox" name="show_dead" value="1" <?= $includeDead ? 'checked' : '' ?>>
        <span>Показывать dead</span>
    </label>
    <button class="button primary" type="submit">Фильтровать</button>
    <a class="button ghost" href="<?= e(app_url('index.php')) ?>">Сбросить</a>
</form>

<?php if ($totalChannels === 0): ?>
    <section class="empty-state">
        <h2>Каналы пока не добавлены</h2>
        <p>Открой страницу плейлистов, добавь ссылку или вставь содержимое M3U, затем нажми обновление.</p>
        <a class="button primary" href="<?= e(app_url('admin.php')) ?>">Перейти к плейлистам</a>
    </section>
<?php else: ?>
    <div class="section-head">
        <h2><?= $filteredTotal ?> из <?= $totalChannels ?> каналов</h2>
        <p>Показаны <?= count($channels) ?> каналов на странице <?= $page ?> из <?= $totalPages ?>. Dead скрыто: <?= $includeDead ? 0 : $deadHidden ?>.</p>
    </div>
    <?php if ($view === 'table'): ?>
        <section class="channel-table-wrap" data-favorite-url="<?= e(app_url('api/favorite.php')) ?>">
            <table class="channel-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Канал</th>
                        <th>Статус</th>
                        <th>Жанр</th>
                        <th>Страна</th>
                        <th>Источник</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($channels as $channel): ?>
                        <?php $id = (string) $channel['id']; ?>
                        <tr data-channel-card data-channel-id="<?= e($id) ?>">
                            <td><button class="favorite-button <?= isset($favoriteIds[$id]) ? 'active' : '' ?>" type="button" data-favorite-button aria-label="Избранное">★</button></td>
                            <td><a href="<?= e(app_url('watch.php?id=' . urlencode($id))) ?>"><?= e((string) $channel['name']) ?></a></td>
                            <td><span class="status-badge <?= e((string) $channel['health_status']) ?>"><?= e(health_label((string) $channel['health_status'])) ?></span></td>
                            <td><?= e((string) $channel['genre']) ?></td>
                            <td><?= e(trim((string) $channel['country'] . ' ' . (string) $channel['city'])) ?></td>
                            <td><?= e((string) $channel['source_title']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php else: ?>
        <section class="channel-grid <?= $view === 'logos' ? 'logo-grid' : '' ?>" data-channel-grid data-favorite-url="<?= e(app_url('api/favorite.php')) ?>">
            <?php foreach ($channels as $channel): ?>
                <?php
                    $id = (string) $channel['id'];
                    $quality = channel_quality_label($channel);
                ?>
                <article class="channel-card" data-channel-card data-channel-id="<?= e($id) ?>">
                    <button class="favorite-button <?= isset($favoriteIds[$id]) ? 'active' : '' ?>" type="button" data-favorite-button aria-label="Избранное">★</button>
                    <a class="channel-link" href="<?= e(app_url('watch.php?id=' . urlencode($id))) ?>">
                        <span class="logo-box">
                            <?php if (!empty($channel['logo'])): ?>
                                <img src="<?= e((string) $channel['logo']) ?>" alt="">
                            <?php else: ?>
                                <span><?= e(text_initial((string) $channel['name'])) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="channel-main">
                            <strong><?= e((string) $channel['name']) ?></strong>
                            <small><?= e((string) $channel['genre']) ?></small>
                            <span class="badge-row">
                                <span class="status-badge <?= e((string) $channel['health_status']) ?>"><?= e(health_label((string) $channel['health_status'])) ?></span>
                                <?php if ($quality !== ''): ?><span class="quality-badge"><?= e($quality) ?></span><?php endif; ?>
                            </span>
                        </span>
                        <span class="channel-meta">
                            <?php if (!empty($channel['country'])): ?><small><?= e((string) $channel['country']) ?></small><?php endif; ?>
                            <?php if (!empty($channel['city'])): ?><small><?= e((string) $channel['city']) ?></small><?php endif; ?>
                        </span>
                    </a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php if ($totalPages > 1): ?>
        <nav class="pagination">
            <?php
                $query = $_GET;
                $query['page'] = max(1, $page - 1);
            ?>
            <a class="button <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(app_url('index.php?' . http_build_query($query))) ?>">Назад</a>
            <span>Страница <?= $page ?> / <?= $totalPages ?></span>
            <?php $query['page'] = min($totalPages, $page + 1); ?>
            <a class="button <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(app_url('index.php?' . http_build_query($query))) ?>">Вперед</a>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script type="application/json" id="channels-data"><?= json_encode(array_map(static fn (array $channel): array => [
    'id' => (string) ($channel['id'] ?? ''),
    'name' => (string) ($channel['name'] ?? ''),
    'genre' => (string) ($channel['genre'] ?? ''),
    'country' => (string) ($channel['country'] ?? ''),
    'city' => (string) ($channel['city'] ?? ''),
], $channels), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php
render_footer();
