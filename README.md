# MyIPTV

MyIPTV is a small personal IPTV dashboard written in plain PHP. It reads user-provided M3U/M3U8 playlists, stores channels in SQLite, checks stream health, and gives a browser player with HLS, DASH, and configurable protected-stream metadata.

The project is intentionally local-first: no bundled public IPTV registry, no hidden playlist seeding, and no database required beyond the SQLite file created in `data/`.

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

No license is declared yet. Add one before publishing the repository publicly if you want others to reuse the code under clear terms.
