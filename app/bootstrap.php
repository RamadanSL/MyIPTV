<?php
declare(strict_types=1);

session_start();

const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';
const UPLOAD_DIR = DATA_DIR . '/uploads';
const DB_FILE = DATA_DIR . '/myiptv.sqlite';
const JOBS_DIR = DATA_DIR . '/jobs';

require_once __DIR__ . '/Support/text.php';
require_once __DIR__ . '/Support/http.php';
require_once __DIR__ . '/Support/flash.php';
require_once __DIR__ . '/Support/metadata.php';
require_once __DIR__ . '/Support/source_filters.php';
require_once __DIR__ . '/Database/migrations.php';
require_once __DIR__ . '/Database/connection.php';
require_once __DIR__ . '/Domain/Playlists/repository.php';
require_once __DIR__ . '/Domain/Wanted/wanted.php';
require_once __DIR__ . '/Domain/Favorites/favorites.php';
require_once __DIR__ . '/Domain/Channels/catalog.php';
require_once __DIR__ . '/Domain/Player/state.php';
require_once __DIR__ . '/Search/web_search.php';

ensure_app_storage();
seed_default_web_search_providers();
