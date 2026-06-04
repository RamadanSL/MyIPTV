# MyIPTV

Русская версия находится первой. English version follows below.

## Русский

MyIPTV - небольшой личный IPTV-кабинет на чистом PHP. Он читает пользовательские M3U/M3U8-плейлисты, хранит каналы в SQLite, проверяет живость потоков и дает браузерный плеер с HLS, DASH, радио/аудио и настраиваемыми параметрами доступа для сложных потоков.

Проект специально сделан локальным и аккуратным: без встроенного публичного реестра IPTV, без скрытого автозасева плейлистов и без обязательной внешней базы данных. Все рабочие данные создаются локально в `data/`.

### Статус и авторство

Автор проекта: SLIKK - публичный никнейм автора. GitHub-профиль: [@RamadanSL](https://github.com/RamadanSL).

Проект создавался как личный экспериментальный инструмент. Часть кода написана автором вручную, часть подготовлена, переработана и документирована при активной помощи AI-инструментов. Итоговая логика, требования, проверки и решения по проекту курировались автором.

Проект может содержать недоделанные, спорные или экспериментальные части. Он распространяется как есть, без гарантий работоспособности, пригодности для конкретной задачи или поддержки. Используй, форкай, меняй и дорабатывай свободно.

### Что умеет

- Добавляет прямую M3U/M3U8-ссылку и парсит именно эту ссылку.
- Принимает вставленный M3U-текст для ручных или локальных плейлистов.
- Понимает, что пользователь добавил: плейлист, веб-страницу, одиночный поток или непонятный ответ.
- Импортирует радио/аудио из M3U-плейлистов и прямых аудиоссылок: MP3, AAC, M4A, FLAC, WAV, OGG, Opus.
- Сканирует только пользовательские discovery-страницы на наличие M3U/M3U8-кандидатов.
- Хранит плейлисты, каналы, избранное, состояние плеера, фоновые задачи и диагностику в SQLite.
- Показывает каталог с поиском и фильтрами по названию, группе, стране, городу, источнику, статусу, избранному и быстрым пресетам.
- Воспроизводит каналы через `watch.php` с hls.js для HLS и Shaka Player для DASH/DRM-конфигов.
- Отслеживает live/dead/unknown, HTTP-статус, последнюю ошибку проверки, рабочую ссылку и качество источников.
- Определяет признаки защищенных потоков: DRM-маркеры, tokenized URL, auth-required ответы, geo-block и header-required потоки.
- Позволяет в админке редактировать доступ к каналу: URL, тип потока, тип доступа, headers, DRM system, license URL и license headers.
- Запускает тяжелые операции фоном: обновление плейлистов, проверку каналов, ремонт dead-каналов, discovery scan и поиск желаемых каналов.

### Чего в проекте нет

- Нет захардкоженного публичного IPTV-реестра.
- Нет предзагруженных каналов.
- Нет закоммиченной локальной базы, кешей, логов, загруженных плейлистов, секретов или скриншотов.
- Нет обхода DRM. Плеер может использовать настроенный license URL и headers через локальный license proxy, но зашифрованному DRM-медиа все равно нужен действительный лицензионный поток в браузере.

### Требования

- PHP 8.1 или новее.
- SQLite в PHP.
- cURL в PHP для сетевых проверок, чтения плейлистов, proxy и фоновых задач.
- Современный браузер для плеера.

На Windows с XAMPP PHP обычно лежит здесь:

```powershell
C:\xampp\php\php.exe
```

### Локальный запуск

Из корня проекта:

```powershell
php -S 127.0.0.1:8000 -t .
```

Если PHP не добавлен в `PATH`:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t .
```

Открыть:

```text
http://127.0.0.1:8000/
```

Полезные страницы:

- `index.php` - каталог каналов.
- `watch.php` - плеер.
- `admin.php` - добавление плейлистов, фоновые задачи, диагностика, редактор доступа и настройки.

### Первый запуск

1. Открой `admin.php`.
2. Вставь прямую M3U/M3U8-ссылку в умную форму добавления.
3. Дождись, пока форма определит тип ссылки: плейлист, одиночный поток, веб-страница или неподдерживаемый ответ.
4. Добавь источник.
5. Запусти проверку каналов в админке.
6. Открой каталог или плеер.

Прямое чтение M3U и web discovery разделены намеренно. Прямая ссылка на плейлист скачивается и парсится напрямую. Discovery используется только для добавленных пользователем страниц, где приложение должно искать кандидаты на плейлисты.

### Админ-панель

Админка разложена по рабочему процессу:

- `Добавить` - умное добавление URL и вставленный M3U-текст.
- `Активность` - состояние и логи фоновых задач.
- `Проверка` - сводка живости и действия обслуживания.
- `Доступ` - диагностика воспроизведения и редактор доступа по каналам.
- `Источники` - сохраненные плейлисты, discovery-страницы, кандидаты и web-search providers.
- `Поиск каналов` - желаемые каналы, алиасы и история поиска.
- `Диагностика` - проблемные каналы, ошибки источников, качество каталога и история замен.
- `Настройки` - локальные пути и PHP-окружение.

### Доступ и защищенные потоки

У канала могут быть метаданные доступа:

- `access_type`: `open`, `tokenized`, `header_required`, `auth_required`, `geo_blocked`, `drm` или `unsupported`.
- `access_notes`: понятная причина или заметка.
- `stream_headers`: HTTP-заголовки для health-check, resolver, proxy и Shaka там, где браузер это позволяет.
- `drm_system`: например `com.widevine.alpha`.
- `license_url`: upstream DRM license endpoint, проксируемый через `api/license.php`.
- `license_headers`: заголовки для license-запроса.

Поддерживаемые типы потоков: `hls`, `dash`, `audio`, `udp`, `rtp` и обычные direct streams. Для аудио используется та же страница плеера, но с компактным радио-видом вместо пустого видеокадра.

Редактор доступа принимает headers как JSON:

```json
{
  "Referer": "https://example.com/",
  "Origin": "https://example.com"
}
```

или обычными строками:

```text
Referer: https://example.com/
Origin: https://example.com
```

### Фоновые скрипты

Запускать из корня проекта.

Обновить все сохраненные плейлисты:

```powershell
C:\xampp\php\php.exe scripts\import_everything.php
```

Проверить живость каналов:

```powershell
C:\xampp\php\php.exe scripts\check_channels.php
```

Просканировать discovery-источники:

```powershell
C:\xampp\php\php.exe scripts\scan.php
```

Искать желаемые каналы:

```powershell
C:\xampp\php\php.exe scripts\scan_wanted.php
```

Попробовать починить dead-каналы:

```powershell
C:\xampp\php\php.exe scripts\repair_dead.php 50 0
```

Непрерывный цикл обслуживания:

```powershell
C:\xampp\php\php.exe scripts\keep_repairing.php 300 50
```

### Локальные данные

Runtime-файлы лежат в `data/`:

- `data/myiptv.sqlite` - локальная SQLite-база.
- `data/uploads/` - вставленные или локальные M3U-файлы.
- `data/jobs/` - состояние фоновых задач.
- `data/*.json` - кеши и локальное runtime-состояние.
- `data/*.secret` - локальные секреты.
- `data/*.log` - логи.

Эти файлы игнорируются Git. В репозитории остается только `data/.gitkeep`.

Если нужны свои resolver-правила для потоков, создай:

```text
data/stream_resolvers.json
```

В качестве шаблона используй `stream_resolvers.example.json`.

### Структура проекта

```text
api/                         HTTP endpoints для плеера, задач, proxy и playback config
app/                         код приложения
app/Database/                SQLite-схема и миграции
app/Domain/Channels/         каталог каналов, доступ, диагностика
app/Domain/Playlists/        хранение плейлистов
app/Domain/Wanted/           поиск желаемых каналов
app/Discovery/               сканирование discovery-источников
app/Health/                  health-check и определение защищенных потоков
app/Import/                  чтение и парсинг M3U/M3U8
app/Jobs/                    runner фоновых задач
app/Repair/                  поиск замен для dead-каналов
app/Search/                  пользовательский web search
app/Stream/                  playback resolver, HLS rewrite, license config
assets/                      CSS и браузерный JavaScript
data/                        локальные runtime-данные, игнорируются кроме .gitkeep
scripts/                     CLI-скрипты обслуживания
admin.php                    админ-панель
index.php                    каталог
watch.php                    плеер
```

### Проверки перед публикацией

Проверка PHP-синтаксиса:

```powershell
Get-ChildItem -Recurse -Filter *.php |
  Where-Object { $_.FullName -notlike '*\data\*' } |
  ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

Проверка, что в проект не вернулся встроенный публичный реестр плейлистов:

```powershell
C:\xampp\php\php.exe scripts\assert_no_hardcoded_playlists.php
```

Ожидаемый результат:

```text
OK: no hardcoded public playlist registry entries and no playlist-backed wanted search.
```

### Git

В репозиторий стоит коммитить:

- PHP-файлы приложения.
- `assets/`.
- `api/`.
- `scripts/`.
- `README.md`.
- `AGENTS.md`.
- `.gitignore`.
- `stream_resolvers.example.json`.
- `data/.gitkeep`.

Не коммитить:

- `data/myiptv.sqlite`
- `data/uploads/`
- `data/jobs/`
- `data/*.secret`
- `data/*.json`
- `data/*.log`
- скриншоты и локальные кеши

### Лицензия

Автор: SLIKK - публичный никнейм автора проекта. GitHub-профиль: [@RamadanSL](https://github.com/RamadanSL).

Проект распространяется свободно по MIT License. Можно использовать, копировать, форкать, менять, распространять и встраивать как угодно. Код предоставляется как есть, без гарантий.

Полный текст лицензии: `LICENSE`.

---

## English

MyIPTV is a small personal IPTV dashboard written in plain PHP. It reads user-provided M3U/M3U8 playlists, stores channels in SQLite, checks stream health, and gives a browser player with HLS, DASH, and configurable protected-stream metadata.

The project is intentionally local-first: no bundled public IPTV registry, no hidden playlist seeding, and no database required beyond the SQLite file created in `data/`.

## Status And Authorship

Author: SLIKK - the author's public nickname. GitHub profile: [@RamadanSL](https://github.com/RamadanSL).

This project was built as a personal experimental tool. Some code was written manually by the author, and some parts were generated, reworked, and documented with active help from AI tools. The final direction, requirements, checks, and project decisions were guided by the author.

The project may contain unfinished, rough, or experimental parts. It is distributed as is, without any warranty of functionality, fitness for a particular purpose, or support. Use, fork, modify, and improve it freely.

## What It Does

- Adds a direct M3U/M3U8 URL and parses that exact URL.
- Accepts pasted M3U text for local/manual playlists.
- Detects whether an added URL is a playlist, a web page, a single stream, or an unknown response.
- Imports radio/audio entries from M3U playlists and direct audio streams such as MP3, AAC, M4A, FLAC, WAV, OGG, and Opus.
- Scans user-added discovery pages for M3U/M3U8 candidates.
- Stores playlists, channels, favorites, player state, jobs, and diagnostics in SQLite.
- Shows a searchable catalog with filters by name, group, country, city, source, health, favorites, and quick presets.
- Plays channels through `watch.php` with hls.js for HLS and Shaka Player for DASH/DRM-capable configs.
- Tracks live/dead/unknown health, last HTTP status, last probe error, working URL, and source quality.
- Detects protected stream hints such as DRM markers, tokenized URLs, auth-required responses, geo-blocks, and header-required streams.
- Lets the admin edit stream access settings per channel: stream URL, stream type, access type, request headers, DRM system, license URL, and license headers.
- Runs heavier maintenance actions as background jobs: playlist refresh, health checks, dead-channel repair, discovery scan, and wanted-channel search.

## What It Does Not Ship

- No hardcoded public IPTV playlist registry.
- No preloaded channels.
- No committed local database, cache, logs, uploaded playlists, secrets, or screenshots.
- No DRM bypass. The player can use a configured license URL and headers through the local license proxy, but encrypted DRM media still needs a valid license flow in the browser.

## Requirements

- PHP 8.1 or newer.
- SQLite support in PHP.
- cURL support in PHP for network checks, playlist reads, proxies, and background tasks.
- A modern browser for the player.

On Windows with XAMPP, PHP is commonly available at:

```powershell
C:\xampp\php\php.exe
```

## Run Locally

From the project root:

```powershell
php -S 127.0.0.1:8000 -t .
```

If PHP is not in `PATH`:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t .
```

Open:

```text
http://127.0.0.1:8000/
```

Useful pages:

- `index.php` - channel catalog.
- `watch.php` - player.
- `admin.php` - playlist intake, jobs, diagnostics, access editor, and settings.

## First Use

1. Open `admin.php`.
2. Paste a direct M3U/M3U8 URL into the smart add form.
3. Let the form identify whether it is a playlist, a single stream, a web page, or an unsupported response.
4. Add the source.
5. Run a health check from the admin panel.
6. Open the catalog or player.

Direct M3U reading and web discovery are separate by design. A direct playlist URL is fetched and parsed directly. Discovery is only for user-added pages where the app should search for playlist candidates.

## Admin Panel

The admin panel is organized around the actual workflow:

- `Добавить` - smart URL intake and pasted M3U text.
- `Активность` - background job status and logs.
- `Проверка` - health summary and maintenance actions.
- `Доступ` - per-channel playback diagnosis and access config editor.
- `Источники` - saved playlists, discovery pages, candidates, and web-search providers.
- `Поиск каналов` - wanted-channel aliases and search history.
- `Диагностика` - problem channels, source errors, catalog quality, and replacement history.
- `Настройки` - local paths and PHP environment.

## Access And Protected Streams

Each channel can carry access metadata:

- `access_type`: `open`, `tokenized`, `header_required`, `auth_required`, `geo_blocked`, `drm`, or `unsupported`.
- `access_notes`: human-readable reason or reminder.
- `stream_headers`: HTTP headers used by health checks, resolver, proxy, and Shaka where browser rules allow it.
- `drm_system`: for example `com.widevine.alpha`.
- `license_url`: upstream DRM license endpoint, proxied locally through `api/license.php`.
- `license_headers`: headers for the license request.

Supported stream types include `hls`, `dash`, `audio`, `udp`, `rtp`, and generic direct streams. Audio streams use the same player page with a compact radio view instead of an empty video frame.

The access editor accepts headers as either JSON:

```json
{
  "Referer": "https://example.com/",
  "Origin": "https://example.com"
}
```

or plain lines:

```text
Referer: https://example.com/
Origin: https://example.com
```

## Background Scripts

Use these from the project root.

Refresh all saved playlists:

```powershell
C:\xampp\php\php.exe scripts\import_everything.php
```

Check channel health:

```powershell
C:\xampp\php\php.exe scripts\check_channels.php
```

Scan discovery sources:

```powershell
C:\xampp\php\php.exe scripts\scan.php
```

Search wanted channels:

```powershell
C:\xampp\php\php.exe scripts\scan_wanted.php
```

Try to repair dead channels:

```powershell
C:\xampp\php\php.exe scripts\repair_dead.php 50 0
```

Continuous maintenance loop:

```powershell
C:\xampp\php\php.exe scripts\keep_repairing.php 300 50
```

## Local Data

Runtime files live in `data/`:

- `data/myiptv.sqlite` - local SQLite database.
- `data/uploads/` - pasted/local M3U files.
- `data/jobs/` - background job state.
- `data/*.json` - caches and local runtime state.
- `data/*.secret` - generated local secrets.
- `data/*.log` - logs.

These files are ignored by Git. Keep only `data/.gitkeep` in the repository.

If you need custom stream resolver rules, create:

```text
data/stream_resolvers.json
```

Use `stream_resolvers.example.json` as the template.

## Project Structure

```text
api/                         HTTP endpoints for player, jobs, proxy, playback config
app/                         application code
app/Database/                SQLite schema and migrations
app/Domain/Channels/         channel catalog, access metadata, diagnostics
app/Domain/Playlists/        playlist persistence
app/Domain/Wanted/           wanted-channel search workflow
app/Discovery/               discovery-source scanning and candidate extraction
app/Health/                  stream health checks and protected-stream detection
app/Import/                  M3U/M3U8 reading and parsing
app/Jobs/                    background job runner
app/Repair/                  dead-channel replacement search
app/Search/                  user-configured web search
app/Stream/                  playback resolver, HLS rewrite helpers, license config
assets/                      CSS and browser JavaScript
data/                        local runtime data, ignored except .gitkeep
scripts/                     CLI maintenance entrypoints
admin.php                    admin dashboard
index.php                    catalog
watch.php                    player
```

## Checks Before Publishing

Run syntax checks:

```powershell
Get-ChildItem -Recurse -Filter *.php |
  Where-Object { $_.FullName -notlike '*\data\*' } |
  ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

Ensure the project still has no hardcoded public playlist registry:

```powershell
C:\xampp\php\php.exe scripts\assert_no_hardcoded_playlists.php
```

Expected result:

```text
OK: no hardcoded public playlist registry entries and no playlist-backed wanted search.
```

## Git Notes

Recommended first commit contents:

- PHP app files.
- `assets/`.
- `api/`.
- `scripts/`.
- `README.md`.
- `AGENTS.md`.
- `.gitignore`.
- `stream_resolvers.example.json`.
- `data/.gitkeep`.

Do not commit:

- `data/myiptv.sqlite`
- `data/uploads/`
- `data/jobs/`
- `data/*.secret`
- `data/*.json`
- `data/*.log`
- screenshots or local caches

## License

Author: SLIKK - the author's public nickname. GitHub profile: [@RamadanSL](https://github.com/RamadanSL).

This project is freely available under the MIT License. You may use, copy, fork, modify, distribute, and integrate it however you want. The code is provided as is, without warranty.

See `LICENSE` for the full license text.
