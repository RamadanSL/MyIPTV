const STORAGE_KEY = 'myiptv.state.v1';

function readLocalState() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
  } catch {
    return {};
  }
}

function writeLocalState(state) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

async function readRemoteState() {
  try {
    const response = await fetch('api/state.php', { cache: 'no-store' });
    if (!response.ok) return {};
    const data = await response.json();
    return data && typeof data === 'object' ? data : {};
  } catch {
    return {};
  }
}

function editableTarget(target) {
  return Boolean(target?.closest?.('input, textarea, select, button, a, [contenteditable="true"]'));
}

function parseChannelsData() {
  const script = document.getElementById('channels-data');
  if (!script) return [];
  try {
    return JSON.parse(script.textContent || '[]');
  } catch {
    return [];
  }
}

function formatTime(seconds) {
  if (!Number.isFinite(seconds) || seconds <= 0) return '00:00';
  const total = Math.floor(seconds);
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(s).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

function formatDuration(seconds) {
  const value = Math.max(0, Number(seconds) || 0);
  const h = Math.floor(value / 3600);
  const m = Math.floor((value % 3600) / 60);
  const s = Math.floor(value % 60);
  if (h > 0) return `${h}ч ${m}м`;
  if (m > 0) return `${m}м ${s}с`;
  return `${s}с`;
}

function initImportInspector() {
  const form = document.querySelector('[data-import-inspector]');
  if (!form) return;

  const input = form.querySelector('[data-import-url]');
  const button = form.querySelector('[data-import-submit]');
  const hint = form.querySelector('[data-import-hint]');
  const title = form.querySelector('[data-import-hint-title]');
  const text = form.querySelector('[data-import-hint-text]');
  const meta = form.querySelector('[data-import-hint-meta]');
  const probeUrl = form.dataset.importProbeUrl || 'api/import_probe.php';
  if (!input || !button || !hint || !title || !text) return;

  const defaultButtonText = button.textContent;
  let timer = null;
  let controller = null;
  let pendingUrl = '';
  let inspectedUrl = '';

  function setHint(state, titleText, bodyText, metaText = '') {
    hint.className = `admin-v2-import-hint ${state}`;
    title.textContent = titleText;
    text.textContent = bodyText;
    if (meta) {
      meta.textContent = metaText;
      meta.hidden = metaText === '';
    }
  }

  function buttonTextForKind(kind) {
    if (kind === 'playlist') return 'Добавить плейлист';
    if (kind === 'stream') return 'Добавить канал';
    if (kind === 'page') return 'Сканировать страницу';
    return defaultButtonText;
  }

  function busyTextForKind(kind) {
    if (kind === 'playlist') return 'Читаю M3U...';
    if (kind === 'stream') return 'Добавляю поток...';
    if (kind === 'page') return 'Сканирую страницу...';
    return 'Разбираю...';
  }

  function localHint(value) {
    let path = '';
    try {
      path = new URL(value).pathname.toLowerCase();
    } catch {
      return null;
    }

    if (path.endsWith('.m3u') || path.endsWith('.m3u8')) {
      return ['checking', 'Похоже на M3U', 'Проверяю ответ сервера перед добавлением.'];
    }
    if (path.endsWith('.mpd')) {
      return ['checking', 'Похоже на DASH', 'Проверяю поток и признаки защиты.'];
    }
    return ['checking', 'Проверяю ссылку', 'Сейчас пойму, это плейлист, страница или поток.'];
  }

  async function probe(value) {
    if (controller) controller.abort();
    controller = new AbortController();

    const target = new URL(probeUrl, window.location.href);
    target.searchParams.set('url', value);

    try {
      const response = await fetch(target.toString(), {
        cache: 'no-store',
        signal: controller.signal,
      });
      const data = await response.json();
      if (!response.ok || data?.ok === false) {
        throw new Error(data?.message || data?.error || 'Не удалось разобрать ссылку.');
      }

      const kind = data.kind || 'unknown';
      const metaParts = [];
      if (data.status) metaParts.push(`HTTP ${data.status}`);
      if (data.content_type) metaParts.push(data.content_type);
      form.dataset.importKind = kind;
      button.textContent = buttonTextForKind(kind);
      setHint(
        kind,
        `${data.label || 'Ссылка'}: ${data.action_label || 'разберу'}`,
        data.message || 'Готово к добавлению.',
        metaParts.join(' / ')
      );
    } catch (error) {
      if (error?.name === 'AbortError') return;
      form.dataset.importKind = 'error';
      button.textContent = defaultButtonText;
      setHint('error', 'Не смог разобрать', error?.message || 'Проверь ссылку и попробуй снова.');
    }
  }

  function scheduleProbe() {
    const value = input.value.trim();

    if (value === '') {
      window.clearTimeout(timer);
      pendingUrl = '';
      inspectedUrl = '';
      form.dataset.importKind = '';
      button.textContent = defaultButtonText;
      setHint('idle', 'Жду ссылку', 'Когда вставишь URL, покажу, что именно с ним сделаю.');
      return;
    }

    if (!input.validity.valid) {
      window.clearTimeout(timer);
      pendingUrl = '';
      form.dataset.importKind = 'error';
      button.textContent = defaultButtonText;
      setHint('error', 'Ссылка неполная', 'Нужен обычный http:// или https:// URL.');
      return;
    }

    const guessed = localHint(value);
    if (guessed) setHint(...guessed);

    if (value === pendingUrl || value === inspectedUrl) return;
    window.clearTimeout(timer);
    pendingUrl = value;
    timer = window.setTimeout(() => {
      pendingUrl = '';
      inspectedUrl = value;
      probe(value);
    }, 500);
  }

  input.addEventListener('input', scheduleProbe);
  input.addEventListener('paste', () => window.setTimeout(scheduleProbe, 0));
  input.addEventListener('blur', scheduleProbe);

  form.addEventListener('submit', () => {
    window.clearTimeout(timer);
    if (controller) controller.abort();
    button.disabled = true;
    form.classList.add('is-submitting');
    button.textContent = busyTextForKind(form.dataset.importKind || '');
  });

  scheduleProbe();
}

function initAdminNav() {
  const nav = document.querySelector('.admin-v2-nav');
  if (!nav) return;

  const links = Array.from(nav.querySelectorAll('a[href^="#"]'));
  const sections = links
    .map((link) => document.querySelector(link.getAttribute('href') || ''))
    .filter(Boolean);
  if (links.length === 0 || sections.length === 0) return;

  function setActive(id) {
    links.forEach((link) => {
      link.classList.toggle('active-section', link.getAttribute('href') === `#${id}`);
    });
  }

  links.forEach((link) => {
    link.addEventListener('click', () => {
      const target = (link.getAttribute('href') || '').slice(1);
      if (target) setActive(target);
    });
  });

  if (!('IntersectionObserver' in window)) {
    setActive(sections[0].id);
    return;
  }

  const observer = new IntersectionObserver((entries) => {
    const visible = entries
      .filter((entry) => entry.isIntersecting)
      .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
    if (visible?.target?.id) {
      setActive(visible.target.id);
    }
  }, {
    rootMargin: '-18% 0px -62% 0px',
    threshold: [0.1, 0.35, 0.6],
  });

  sections.forEach((section) => observer.observe(section));
  setActive(sections[0].id);
}

async function initResumePanel() {
  const panel = document.querySelector('[data-resume-panel]');
  if (!panel) return;

  const state = readLocalState();
  const remoteState = await readRemoteState();
  const localUpdatedAt = Date.parse(state.updatedAt || '') || 0;
  const remoteUpdatedAt = Date.parse(remoteState.updated_at || '') || 0;
  let lastChannelId = state.lastChannelId || '';
  if (remoteState.last_channel_id && remoteUpdatedAt >= localUpdatedAt) {
    lastChannelId = remoteState.last_channel_id;
    state.lastChannelId = lastChannelId;
    state.updatedAt = remoteState.updated_at || new Date().toISOString();
    state.volume = Number.isFinite(remoteState.volume) ? remoteState.volume : state.volume;
    state.muted = Boolean(remoteState.muted);
    writeLocalState(state);
  }
  if (!lastChannelId) return;

  let channel = null;
  const channels = parseChannelsData();
  channel = channels.find((item) => item.id === lastChannelId) || null;
  if (!channel) {
    try {
      const response = await fetch(`api/channels.php?id=${encodeURIComponent(lastChannelId)}`, { cache: 'no-store' });
      if (response.ok) {
        channel = await response.json();
      }
    } catch {
      channel = null;
    }
  }
  if (!channel) return;

  const title = panel.querySelector('[data-resume-title]');
  const meta = panel.querySelector('[data-resume-meta]');
  const link = panel.querySelector('[data-resume-link]');
  title.textContent = channel.name || 'Последний канал';
  meta.textContent = [channel.genre, channel.country, channel.city].filter(Boolean).join(' / ');
  link.href = `watch.php?id=${encodeURIComponent(channel.id)}`;
  panel.hidden = false;
}

function initCatalogMarkers() {
  const state = readLocalState();
  if (!state.lastChannelId) return;

  document.querySelectorAll('[data-channel-card]').forEach((card) => {
    if (card.dataset.channelId === state.lastChannelId) {
      card.classList.add('last-watched');
    }
  });
}

function initPlayer() {
  const root = document.querySelector('[data-player]');
  if (!root) return;

  const video = root.querySelector('video');
  let streamUrl = root.dataset.streamUrl;
  let streamType = root.dataset.streamType || '';
  let hlsUrl = root.dataset.hlsUrl;
  const channelId = root.dataset.channelId;
  let accessType = root.dataset.accessType || 'open';
  let accessNotes = root.dataset.accessNotes || '';
  let drmSystem = root.dataset.drmSystem || 'com.widevine.alpha';
  let licenseUrl = root.dataset.licenseUrl || '';
  let streamHeaders = parseJsonObject(root.dataset.streamHeaders || '');
  const playbackConfigUrl = root.dataset.playbackConfigUrl || '';
  const stateUrl = root.dataset.stateUrl;
  const faultUrl = root.dataset.faultUrl;
  const repairUrl = root.dataset.repairUrl;
  const favoriteUrl = root.dataset.favoriteUrl;
  const message = root.querySelector('[data-player-message]');
  const progress = root.querySelector('[data-progress]');
  const currentTimes = root.querySelectorAll('[data-current-time]');
  const durations = root.querySelectorAll('[data-duration]');
  const volume = root.querySelector('[data-volume]');
  const playButtons = root.querySelectorAll('[data-action="toggle-play"]');
  const markDeadButton = root.querySelector('[data-action="mark-dead"]');
  const repairButton = root.querySelector('[data-action="repair-channel"]');
  const favoriteButton = root.querySelector('[data-action="toggle-favorite"]');
  const muteButton = root.querySelector('[data-action="mute"]');
  const fullscreenButton = root.querySelector('[data-action="fullscreen"]');
  let hls = null;
  let shakaPlayer = null;
  let usingRelay = false;
  let triedDirectAfterRelay = false;
  let reportedFailure = false;
  let loadTimer = null;
  let saveTimer = null;
  let restoredPosition = false;
  let userNavigating = false;

  const localState = readLocalState();
  const channelState = localState.channels?.[channelId] || {};
  video.volume = Number.isFinite(localState.volume) ? localState.volume : 1;
  video.muted = localState.muteSetByUser ? Boolean(localState.muted) : false;
  video.autoplay = true;
  volume.value = String(video.volume);

  function setMessage(text) {
    if (!message) return;
    message.textContent = text;
    message.hidden = !text;
  }

  function parseJsonObject(value) {
    if (!value) return {};
    try {
      const parsed = JSON.parse(value);
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
      return {};
    }
  }

  function canSeek() {
    return Number.isFinite(video.duration) && video.duration > 0 && video.duration !== Infinity;
  }

  function updateControls() {
    const playing = !video.paused && !video.ended;
    root.classList.toggle('is-playing', playing);
    playButtons.forEach((button) => {
      button.textContent = playing ? '⏸' : '▶';
      button.setAttribute('aria-label', playing ? 'Пауза' : 'Воспроизвести');
    });

    muteButton.textContent = video.muted || video.volume === 0 ? '🔇' : '🔊';
    currentTimes.forEach((node) => {
      node.textContent = formatTime(video.currentTime);
    });

    if (canSeek()) {
      durations.forEach((node) => {
        node.textContent = formatTime(video.duration);
      });
      progress.value = String(Math.round((video.currentTime / video.duration) * 1000));
    } else {
      durations.forEach((node) => {
        node.textContent = 'LIVE';
      });
      progress.value = '0';
    }
  }

  function statePayload() {
    const next = readLocalState();
    next.lastChannelId = channelId;
    next.updatedAt = new Date().toISOString();
    next.volume = video.volume;
    next.muted = video.muted;
    next.channels = next.channels || {};
    next.channels[channelId] = {
      currentTime: canSeek() ? video.currentTime : 0,
      duration: canSeek() ? video.duration : 0,
      updatedAt: new Date().toISOString(),
    };
    writeLocalState(next);

    return {
      channel_id: channelId,
      current_time: next.channels[channelId].currentTime,
      duration: next.channels[channelId].duration,
      volume: next.volume,
      muted: next.muted,
    };
  }

  function saveState(useBeacon = false) {
    const payload = statePayload();
    const body = JSON.stringify(payload);

    if (useBeacon && navigator.sendBeacon) {
      const blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon(stateUrl, blob);
      return;
    }

    fetch(stateUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body,
    }).catch(() => {});
  }

  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveState, 350);
  }

  function restorePosition() {
    if (restoredPosition || !canSeek()) return;
    restoredPosition = true;
    const saved = Number(channelState.currentTime || 0);
    if (saved > 5 && saved < video.duration - 5) {
      video.currentTime = saved;
    }
  }

  function isAudioStreamUrl(value) {
    try {
      const path = new URL(value, window.location.href).pathname.toLowerCase();
      return /\.(mp3|aac|m4a|flac|wav|oga|ogg|opus)$/.test(path);
    } catch {
      return /\.(mp3|aac|m4a|flac|wav|oga|ogg|opus)(?:$|[?#])/i.test(value || '');
    }
  }

  function applyMediaMode() {
    const audioMode = streamType === 'audio' || isAudioStreamUrl(streamUrl);
    root.classList.toggle('is-audio-stream', audioMode);
    const visual = root.querySelector('.player-audio-visual');
    if (visual) {
      visual.setAttribute('aria-hidden', audioMode ? 'false' : 'true');
    }
  }

  function loadStream(sourceUrl = streamUrl, fromRelay = false) {
    usingRelay = fromRelay;
    applyMediaMode();
    armLoadTimeout();
    setMessage(fromRelay ? 'Пробую локальный HLS relay для совместимости с браузером.' : '');
    const nativeHls = video.canPlayType('application/vnd.apple.mpegurl');
    if (hls) {
      hls.destroy();
      hls = null;
    }
    if (shakaPlayer) {
      shakaPlayer.destroy().catch(() => {});
      shakaPlayer = null;
    }

    const lowerUrl = sourceUrl.toLowerCase();
    const isHlsSource = streamType === 'hls' || lowerUrl.includes('.m3u8') || fromRelay;
    const shouldUseShaka = streamType === 'dash'
      || lowerUrl.includes('.mpd')
      || (accessType === 'drm' && licenseUrl);
    if (shouldUseShaka) {
      loadShakaStream(sourceUrl);
      return;
    }

    if (isHlsSource && window.Hls && window.Hls.isSupported()) {
      hls = new window.Hls({ enableWorker: true, lowLatencyMode: true });
      hls.loadSource(sourceUrl);
      hls.attachMedia(video);
      hls.on(window.Hls.Events.MANIFEST_PARSED, () => {
        attemptAutoplay();
      });
      hls.on(window.Hls.Events.ERROR, (_event, data) => {
        if (data?.fatal) {
          if (usingRelay && !triedDirectAfterRelay) {
            triedDirectAfterRelay = true;
            setMessage('Relay не смог открыть поток. Пробую прямую ссылку.');
            loadStream(streamUrl, false);
            return;
          }

          reportPlaybackFailure(data?.details || 'fatal hls error');
        }
      });
      return;
    }

    if ((isHlsSource && nativeHls) || !isHlsSource) {
      video.src = sourceUrl;
      attemptAutoplay();
      return;
    }

    setMessage('Этот браузер не умеет HLS без hls.js.');
  }

  async function loadShakaStream(sourceUrl) {
    if (!window.shaka) {
      setMessage('Для DASH/DRM нужен Shaka Player, но он не загрузился.');
      reportPlaybackFailure('shaka missing');
      return;
    }

    try {
      window.shaka.polyfill?.installAll?.();
      if (window.shaka.Player?.isBrowserSupported && !window.shaka.Player.isBrowserSupported()) {
        setMessage('Этот браузер не поддерживает EME/MSE для такого потока.');
        reportPlaybackFailure('shaka unsupported');
        return;
      }

      shakaPlayer = new window.shaka.Player();
      if (typeof shakaPlayer.attach === 'function') {
        await shakaPlayer.attach(video);
      } else {
        shakaPlayer = new window.shaka.Player(video);
      }

      shakaPlayer.addEventListener('error', (event) => {
        const detail = event?.detail?.message || event?.detail?.code || 'shaka error';
        reportPlaybackFailure(detail);
      });

      if (licenseUrl) {
        shakaPlayer.configure({
          drm: {
            servers: {
              [drmSystem || 'com.widevine.alpha']: licenseUrl,
            },
          },
        });
      }

      const networking = shakaPlayer.getNetworkingEngine?.();
      if (networking && Object.keys(streamHeaders).length > 0) {
        networking.registerRequestFilter((_type, request) => {
          request.headers = request.headers || {};
          Object.entries(streamHeaders).forEach(([name, value]) => {
            if (!/^(cookie|referer|origin|user-agent)$/i.test(name)) {
              request.headers[name] = String(value);
            }
          });
        });
      }

      setMessage(licenseUrl ? 'Пробую DASH/DRM через Shaka и локальный license proxy.' : 'Пробую DASH через Shaka.');
      await shakaPlayer.load(sourceUrl);
      clearLoadTimeout();
      attemptAutoplay();
    } catch (error) {
      reportPlaybackFailure(error?.message || 'shaka load failed');
    }
  }

  function attemptAutoplay() {
    if (userNavigating || !video.paused) return;

    const playAttempt = video.play();
    if (!playAttempt || typeof playAttempt.catch !== 'function') return;

    playAttempt.catch((error) => {
      const blocked = error?.name === 'NotAllowedError' || /play\(\)|autoplay|user gesture|not allowed/i.test(String(error?.message || error || ''));
      setMessage(blocked
        ? 'Браузер не дал автозапуск со звуком. Нажми play один раз.'
        : 'Не удалось запустить поток. Нажми play еще раз.');
    });
  }

  function togglePlay() {
    if (video.paused || video.ended) {
      video.play().catch(() => setMessage('Браузер заблокировал автозапуск. Нажми воспроизведение еще раз.'));
      return;
    }

    video.pause();
  }

  playButtons.forEach((button) => {
    button.addEventListener('click', togglePlay);
  });

  video.addEventListener('click', togglePlay);

  markDeadButton?.addEventListener('click', () => {
    reportPlaybackFailure('manual mark dead');
  });

  repairButton?.addEventListener('click', async () => {
    if (!repairUrl) return;
    setMessage('Ищу живую замену этому каналу...');
    try {
      const response = await fetch(repairUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ channel_id: channelId }),
      });
      const data = await response.json();
      if (!response.ok || data?.error) {
        throw new Error(data.error || 'Не удалось выполнить ремонт.');
      }
      if (data.repaired) {
        setMessage('Живая замена найдена. Перезагружаю канал.');
        window.setTimeout(() => window.location.reload(), 900);
        return;
      }
      if (data.not_needed) {
        setMessage('Канал сейчас помечен как live. Для принудительной замены сначала пометь его битым.');
        return;
      }
      setMessage('Живая замена пока не найдена. История попытки сохранена.');
    } catch (error) {
      setMessage(error.message || 'Не удалось выполнить ремонт.');
    }
  });

  favoriteButton?.addEventListener('click', async () => {
    if (!favoriteUrl) return;
    const nextFavorite = !favoriteButton.classList.contains('active');
    try {
      const response = await fetch(favoriteUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ channel_id: channelId, favorite: nextFavorite }),
      });
      const data = await response.json();
      if (!response.ok || data?.error) {
        throw new Error(data.error || 'Не удалось обновить избранное.');
      }
      favoriteButton.classList.toggle('active', data.favorite);
      favoriteButton.textContent = data.favorite ? '★' : '☆';
      favoriteButton.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
    } catch (error) {
      setMessage(error.message || 'Не удалось обновить избранное.');
    }
  });

  muteButton.addEventListener('click', () => {
    video.muted = !video.muted;
    const next = readLocalState();
    next.muteSetByUser = true;
    writeLocalState(next);
    updateControls();
    scheduleSave();
  });

  fullscreenButton.addEventListener('click', () => {
    if (document.fullscreenElement) {
      document.exitFullscreen();
      return;
    }
    root.requestFullscreen?.();
  });

  favoriteButton?.setAttribute('aria-pressed', favoriteButton.classList.contains('active') ? 'true' : 'false');

  document.addEventListener('keydown', (event) => {
    if (editableTarget(event.target)) return;

    if (event.key === ' ' || event.key.toLowerCase() === 'k') {
      event.preventDefault();
      togglePlay();
      return;
    }

    if (event.key.toLowerCase() === 'm') {
      event.preventDefault();
      muteButton.click();
      return;
    }

    if (event.key.toLowerCase() === 'f') {
      event.preventDefault();
      fullscreenButton.click();
      return;
    }

    if (event.key === 'ArrowLeft' && canSeek()) {
      event.preventDefault();
      video.currentTime = Math.max(0, video.currentTime - 10);
      updateControls();
      scheduleSave();
      return;
    }

    if (event.key === 'ArrowRight' && canSeek()) {
      event.preventDefault();
      video.currentTime = Math.min(video.duration, video.currentTime + 10);
      updateControls();
      scheduleSave();
    }
  });

  volume.addEventListener('input', () => {
    video.volume = Number(volume.value);
    video.muted = video.volume === 0;
    const next = readLocalState();
    next.muteSetByUser = true;
    writeLocalState(next);
    updateControls();
    scheduleSave();
  });

  progress.addEventListener('input', () => {
    if (!canSeek()) return;
    video.currentTime = (Number(progress.value) / 1000) * video.duration;
    updateControls();
    scheduleSave();
  });

  ['play', 'playing', 'canplay', 'pause', 'ended', 'volumechange', 'timeupdate', 'durationchange', 'loadedmetadata'].forEach((eventName) => {
    video.addEventListener(eventName, () => {
      if (['playing', 'canplay', 'loadedmetadata'].includes(eventName)) {
        clearLoadTimeout();
        setMessage('');
      }
      if (['canplay', 'loadedmetadata'].includes(eventName)) {
        attemptAutoplay();
      }
      restorePosition();
      updateControls();
      if (['timeupdate', 'pause', 'volumechange'].includes(eventName)) {
        scheduleSave();
      }
    });
  });

  video.addEventListener('error', () => {
    reportPlaybackFailure('video element error');
  });

  document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[href]');
    if (!link) return;

    const href = link.getAttribute('href') || '';
    if (href === '' || href === '#') return;

    userNavigating = true;
    clearLoadTimeout();
  }, { capture: true });

  function armLoadTimeout() {
    clearLoadTimeout();
    loadTimer = window.setTimeout(() => {
      if (!reportedFailure && video.readyState < 2) {
        reportPlaybackFailure('stream load timeout');
      }
    }, 15000);
  }

  function clearLoadTimeout() {
    if (loadTimer) {
      window.clearTimeout(loadTimer);
      loadTimer = null;
    }
  }

  async function reportPlaybackFailure(detail) {
    if (reportedFailure || userNavigating) return;
    if (['drm', 'auth_required', 'geo_blocked', 'header_required', 'tokenized'].includes(accessType)) {
      const reason = accessNotes || 'Каналу нужны особые условия доступа.';
      const text = detail ? ` Деталь: ${detail}.` : '';
      setMessage(`Поток не удалось воспроизвести, но я не помечаю его мертвым.${text} ${reason}`);
      return;
    }
    reportedFailure = true;
    const text = detail ? ` Деталь: ${detail}.` : '';
    setMessage(`Поток не удалось воспроизвести.${text} Помечаю канал как мертвый.`);

    if (!faultUrl) return;

    try {
      const response = await fetch(faultUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          channel_id: channelId,
          detail,
        }),
      });
      const data = await response.json();
      if (data?.next?.id) {
        setMessage(`Канал помечен как мертвый. Следующий живой: ${data.next.name}.`);
        return;
      }
      setMessage('Канал помечен как мертвый. Других живых каналов пока нет, запусти импорт или ремонт в админке.');
    } catch {
      setMessage(`Поток не удалось воспроизвести.${text} Не смог пометить канал как мертвый через API.`);
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      userNavigating = true;
      clearLoadTimeout();
      clearTimeout(saveTimer);
    }
  });

  async function resolvePlaybackConfig() {
    if (!playbackConfigUrl) return;
    try {
      const response = await fetch(playbackConfigUrl, { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || data?.error) {
        throw new Error(data?.error || 'Не удалось получить playback config.');
      }
      streamUrl = data.url || streamUrl;
      streamType = data.stream_type || streamType;
      hlsUrl = data.hls_url || hlsUrl;
      accessType = data.access_type || accessType;
      accessNotes = data.access_notes || accessNotes;
      drmSystem = data.drm_system || drmSystem;
      licenseUrl = data.license_url || licenseUrl;
      streamHeaders = data.headers && typeof data.headers === 'object' ? data.headers : streamHeaders;
      applyMediaMode();
    } catch (error) {
      setMessage(error.message || 'Не удалось подготовить поток.');
    }
  }

  async function startPlayback() {
    await resolvePlaybackConfig();
    if (accessType === 'drm' && !licenseUrl) {
      setMessage(accessNotes || 'Канал защищен DRM. Добавь license_url/license_headers, чтобы плеер попробовал Shaka/EME.');
      updateControls();
      return;
    }
    const useHlsProxy = streamUrl.includes('.m3u8') && hlsUrl;
    loadStream(useHlsProxy ? hlsUrl : streamUrl, Boolean(useHlsProxy));
    updateControls();
    attemptAutoplay();
    saveState();
  }

  startPlayback();
  applyMediaMode();
  updateControls();
}

function initRailCollapsibles() {
  document.querySelectorAll('[data-rail-key]').forEach((rail) => {
    const key = `myiptv.rail.${rail.dataset.railKey}`;
    const stored = localStorage.getItem(key);
    if (stored === 'closed') {
      rail.open = false;
    } else if (stored === 'open') {
      rail.open = true;
    }

    rail.addEventListener('toggle', () => {
      localStorage.setItem(key, rail.open ? 'open' : 'closed');
    });
  });

  window.requestAnimationFrame(() => {
    const active = document.querySelector('.main-channel-rail .rail-item.active');
    const container = active?.closest('.rail-scroll');
    if (active && container) {
      container.scrollTop = active.offsetTop - (container.clientHeight / 2) + (active.clientHeight / 2);
    }
  });
}

function initFavoriteButtons() {
  document.querySelectorAll('[data-favorite-url]').forEach((root) => {
    const favoriteUrl = root.dataset.favoriteUrl;
    if (!favoriteUrl) return;

    root.querySelectorAll('[data-favorite-button]').forEach((button) => {
      button.textContent = button.classList.contains('active') ? '★' : '☆';
      button.setAttribute('aria-pressed', button.classList.contains('active') ? 'true' : 'false');

      button.addEventListener('click', async () => {
        const card = button.closest('[data-channel-card]');
        const channelId = card?.dataset.channelId;
        if (!channelId) return;

        const nextFavorite = !button.classList.contains('active');
        try {
          const response = await fetch(favoriteUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel_id: channelId, favorite: nextFavorite }),
          });
          const data = await response.json();
          if (!response.ok || data?.error) {
            throw new Error(data.error || 'Не удалось обновить избранное.');
          }
          button.classList.toggle('active', data.favorite);
          button.textContent = data.favorite ? '★' : '☆';
          button.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
        } catch {
          button.classList.toggle('error', true);
          window.setTimeout(() => button.classList.remove('error'), 1200);
        }
      });
    });
  });
}

function initJobPanel() {
  const panel = document.querySelector('[data-job-panel]');
  if (!panel) return;

  const status = panel.querySelector('[data-job-status]');
  const output = panel.querySelector('[data-job-output]');
  const bar = panel.querySelector('[data-job-progress-bar]');
  const percentLabel = panel.querySelector('[data-job-progress-percent]');
  const messageLabel = panel.querySelector('[data-job-progress-message]');
  const url = panel.dataset.jobsUrl;
  const startUrl = panel.dataset.startJobUrl || 'api/start_job.php';
  if (!url) return;

  const jobTypeLabels = {
    import_everything: 'Обновление каналов',
    refresh: 'Обновление каналов',
    check_channels: 'Проверка живости',
    repair_dead: 'Поиск замен',
    scan_discovery: 'Сканирование источников',
    scan_wanted: 'Поиск желаемых каналов',
  };

  const jobStatusLabels = {
    running: 'работает',
    complete: 'готово',
    failed: 'ошибка',
  };

  async function update() {
    try {
      const response = await fetch(url, { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      const latest = data.latest;
      if (latest) {
        const typeLabel = jobTypeLabels[latest.type] || latest.type || 'Фоновая задача';
        const statusLabel = jobStatusLabels[latest.status] || latest.status || 'нет статуса';
        status.textContent = `${typeLabel}: ${statusLabel}. Обновлено: ${latest.updated_at || latest.created_at || ''}`;
        output.textContent = latest.output || '';
        const progress = latest.progress || {};
        const percent = Number(progress.percent || 0);
        if (bar) bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
        if (percentLabel) percentLabel.textContent = `${percent}%`;
        if (messageLabel) {
          const eta = Number(progress.eta_seconds || 0);
          messageLabel.textContent = eta > 0 ? `${progress.message || ''} / осталось примерно ${formatDuration(eta)}` : (progress.message || '');
        }
      } else {
        status.textContent = 'Сейчас ничего не выполняется.';
        output.textContent = '';
        if (bar) bar.style.width = '0%';
        if (percentLabel) percentLabel.textContent = '0%';
        if (messageLabel) messageLabel.textContent = '';
      }

      const counts = data.counts || {};
      const health = data.health || {};
      const map = [
        ['[data-count-playlists]', `${counts.playlists ?? 0} источников`],
        ['[data-count-total]', `${counts.channels_total ?? 0} каналов всего`],
        ['[data-count-visible]', `${counts.channels_visible ?? 0} видимых`],
        ['[data-health-total]', health.total ?? 0],
        ['[data-health-live]', health.live ?? 0],
        ['[data-health-dead]', health.dead ?? 0],
        ['[data-health-unknown]', health.unknown ?? 0],
      ];
      map.forEach(([selector, value]) => {
        document.querySelectorAll(selector).forEach((node) => {
          node.textContent = value;
        });
      });
    } catch {
    }
  }

  update();
  setInterval(update, 5000);

  let jobFrame = document.querySelector('iframe[name="myiptv-job-frame"]');
  if (!jobFrame) {
    jobFrame = document.createElement('iframe');
    jobFrame.name = 'myiptv-job-frame';
    jobFrame.hidden = true;
    document.body.appendChild(jobFrame);
  }

  document.querySelectorAll('form[data-background-job]').forEach((form) => {
    form.action = startUrl;
    form.method = 'post';
    form.target = 'myiptv-job-frame';

    form.addEventListener('submit', () => {
      status.textContent = 'Запускаю задачу...';
      output.textContent = '';
      if (bar) bar.style.width = '0%';
      if (percentLabel) percentLabel.textContent = '0%';
      if (messageLabel) messageLabel.textContent = 'Запуск...';
      window.setTimeout(update, 800);
      window.setTimeout(update, 1800);
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initImportInspector();
  initAdminNav();
  initResumePanel();
  initCatalogMarkers();
  initFavoriteButtons();
  initRailCollapsibles();
  initPlayer();
  initJobPanel();
});
