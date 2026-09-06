(function () {
  'use strict';

  const qs = new URLSearchParams(window.location.search);
  const animeId = qs.get('id') || '';
  const animePath = qs.get('path') || '';
  const animeTitleParam = qs.get('title') || '';
  const initialEp = parseInt(qs.get('ep') || '1', 10) || 1;

  const state = {
    anime: null,
    episodes: [],
    currentEpNum: initialEp,
    currentLang: 'dub',
    currentServerIdx: 0,
    sources: [],
    hls: null
  };

  const els = {};

  function cacheEls() {
    els.loaderOverlay = document.getElementById('loaderOverlay');
    els.animeTitle = document.getElementById('animeTitle');
    els.animeTypeBadge = document.getElementById('animeTypeBadge');
    els.animeEpInfo = document.getElementById('animeEpInfo');
    els.animeDubTag = document.getElementById('animeDubTag');
    els.watchHeroBg = document.getElementById('watchHeroBg');
    els.infoTitle = document.getElementById('infoTitle');
    els.infoPoster = document.getElementById('infoPoster');
    els.infoSynopsis = document.getElementById('infoSynopsis');
    els.episodesGrid = document.getElementById('episodesGrid');
    els.epCountText = document.getElementById('epCountText');
    els.epJump = document.getElementById('epJump');
    els.btnEpJump = document.getElementById('btnEpJump');
    els.videoPlayer = document.getElementById('videoPlayer');
    els.playerMessage = document.getElementById('playerMessage');
    els.pmTitle = document.getElementById('pmTitle');
    els.pmText = document.getElementById('pmText');
    els.serverList = document.getElementById('serverList');
    els.serverChips = document.getElementById('serverChips');
    els.qualitySwitcher = document.getElementById('qualitySwitcher');
    els.qualityChips = document.getElementById('qualityChips');
    els.langSwitcher = document.getElementById('langSwitcher');
  }

  function showLoader() {
    if (els.loaderOverlay) els.loaderOverlay.classList.remove('hidden');
  }
  function hideLoader() {
    if (els.loaderOverlay) setTimeout(() => els.loaderOverlay.classList.add('hidden'), 500);
  }
  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = String(str || '');
    return d.innerHTML;
  }

  function showPlayerMessage(title, text, isError) {
    if (els.playerMessage) {
      els.playerMessage.classList.add('visible');
      if (els.pmTitle) els.pmTitle.textContent = title || '';
      if (els.pmText) els.pmText.textContent = text || '';
      els.playerMessage.classList.toggle('error', !!isError);
    }
    if (state.hls) {
      try { state.hls.destroy(); } catch(_){}
      state.hls = null;
    }
    if (els.videoPlayer) {
      els.videoPlayer.pause();
      els.videoPlayer.removeAttribute('src');
      els.videoPlayer.load();
    }
  }
  function hidePlayerMessage() {
    if (els.playerMessage) els.playerMessage.classList.remove('visible', 'error');
  }

  function navigateToWatch(params) {
    const merged = { id: animeId, path: animePath, title: state.anime?.title || animeTitleParam, ...params };
    const search = new URLSearchParams();
    if (merged.id) search.set('id', merged.id);
    if (merged.path) search.set('path', merged.path);
    if (merged.title) search.set('title', merged.title);
    if (merged.ep) search.set('ep', String(merged.ep));
    window.location.search = '?' + search.toString();
  }

  function renderInfo(data) {
    const a = data.anime || {};
    state.anime = a;
    state.episodes = Array.isArray(data.episodes) ? data.episodes : [];
    const title = a.title || animeTitleParam || 'Unknown Anime';
    const type = a.type || 'TV';
    const img = a.image || '';
    const subC = a.language?.sub || String(state.episodes.length || 0);
    const dubC = a.language?.dub || '0';

    if (els.animeTitle) els.animeTitle.textContent = title;
    if (els.infoTitle) els.infoTitle.textContent = title;
    if (els.animeTypeBadge) { els.animeTypeBadge.textContent = type; }
    if (els.animeEpInfo) els.animeEpInfo.textContent = `${state.episodes.length || subC} Episodes`;
    if (els.animeDubTag) {
      const hasDub = parseInt(dubC, 10) > 0;
      els.animeDubTag.textContent = hasDub ? `DUB ${dubC} \u2022 SUB ${subC}` : `SUB ${subC}`;
      els.animeDubTag.style.display = '';
    }
    if (els.infoPoster) {
      els.infoPoster.src = img;
      els.infoPoster.alt = title;
    }
    if (els.watchHeroBg) {
      els.watchHeroBg.style.backgroundImage = img ? `url("${img.replace(/"/g, '%22')}")` : '';
    }
    document.title = `${title} - Athanime Watch`;
    if (els.infoSynopsis) {
      const syn = a.synopsis || (data.synopsis ? String(data.synopsis) : '');
      els.infoSynopsis.innerHTML = syn ? `<p>${escapeHtml(syn)}</p>` : `<p>No synopsis available. Select an episode below to start watching — <strong>${escapeHtml(title)}</strong>.</p>`;
    }
    renderEpisodes();
  }

  function renderEpisodes() {
    if (!els.episodesGrid) return;
    if (!state.episodes.length) {
      els.episodesGrid.innerHTML = `
        <div class="episodes-empty">
          <h3>No episodes loaded.</h3>
          <p>If live scrapers are blocked, a metadata-only fallback is used. Try clicking below to use client-side streaming sources or pick another anime.</p>
          <button type="button" class="retry-btn" id="btnRetryEps">Retry loading episodes</button>
        </div>`;
      const r = document.getElementById('btnRetryEps');
      if (r) r.addEventListener('click', () => fetchAnimeInfo(true));
      if (els.epCountText) els.epCountText.textContent = '(0)';
      return;
    }
    if (els.epCountText) els.epCountText.textContent = `(${state.episodes.length})`;
    els.episodesGrid.innerHTML = '';
    const frag = document.createDocumentFragment();
    state.episodes.forEach((ep) => {
      const num = ep.number ? parseInt(ep.number, 10) : 0;
      if (!num) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ep-btn' + (num === state.currentEpNum ? ' active' : '');
      btn.dataset.epNum = String(num);
      btn.dataset.epId = ep.id || '';
      btn.dataset.epTitle = ep.title || '';
      btn.innerHTML = `<span class="ep-num">${num}</span><span class="ep-title-short">${escapeHtml(ep.title || `Episode ${num}`)}</span>`;
      btn.addEventListener('click', () => selectEpisode(num));
      frag.appendChild(btn);
    });
    els.episodesGrid.appendChild(frag);
    scrollToEp(state.currentEpNum);
    if (els.epJump) {
      els.epJump.max = String(state.episodes.length);
      els.epJump.value = String(Math.min(state.currentEpNum, state.episodes.length));
    }
  }

  function scrollToEp(num) {
    requestAnimationFrame(() => {
      const el = document.querySelector(`.ep-btn[data-ep-num="${num}"]`);
      if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  }

  function selectEpisode(num) {
    state.currentEpNum = num;
    document.querySelectorAll('.ep-btn').forEach(b => {
      b.classList.toggle('active', String(b.dataset.epNum) === String(num));
    });
    if (els.epJump) els.epJump.value = String(num);
    fetchSources();
  }

  function renderServers(sources) {
    if (!els.serverChips || !els.serverList) return;
    state.sources = sources || [];
    if (!state.sources.length) {
      els.serverList.style.display = 'none';
      return;
    }
    els.serverList.style.display = '';
    els.serverChips.innerHTML = '';
    state.sources.forEach((s, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'server-chip' + (i === state.currentServerIdx ? ' active' : '');
      b.textContent = s.name || `Server ${i + 1}`;
      b.title = s.url || '';
      b.addEventListener('click', () => {
        state.currentServerIdx = i;
        document.querySelectorAll('.server-chip').forEach((x, j) => x.classList.toggle('active', i === j));
        playSource(state.sources[i]);
      });
      els.serverChips.appendChild(b);
    });
  }

  function renderQualities(levels) {
    if (!els.qualityChips || !els.qualitySwitcher) return;
    if (!Array.isArray(levels) || levels.length === 0) {
      els.qualitySwitcher.style.display = 'none';
      return;
    }
    els.qualitySwitcher.style.display = '';
    els.qualityChips.innerHTML = '';
    const autoBtn = document.createElement('button');
    autoBtn.type = 'button';
    autoBtn.className = 'quality-chip active';
    autoBtn.textContent = 'Auto';
    autoBtn.addEventListener('click', () => {
      if (state.hls && state.hls.levels) {
        state.hls.currentLevel = -1;
        document.querySelectorAll('.quality-chip').forEach((q, idx) => q.classList.toggle('active', idx === 0));
      }
    });
    els.qualityChips.appendChild(autoBtn);
    levels.forEach((lv, idx) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'quality-chip';
      const h = lv.height ? `${lv.height}p` : `L${idx}`;
      b.textContent = h;
      b.addEventListener('click', () => {
        if (state.hls && state.hls.levels) {
          state.hls.currentLevel = idx;
          document.querySelectorAll('.quality-chip').forEach((q, j) => q.classList.toggle('active', j === idx + 1));
        }
      });
      els.qualityChips.appendChild(b);
    });
  }

  function playSource(src) {
    if (!els.videoPlayer || !els.playerContainer) return;
    const url = (src && src.url) ? String(src.url) : '';
    const isIframe = !!(src && src.isIframe);
    const oldIframe = els.playerContainer.querySelector('.player-iframe');
    if (oldIframe) oldIframe.remove();
    if (state.hls) {
      try { state.hls.destroy(); } catch(_){}
      state.hls = null;
    }
    if (!url) {
      showPlayerMessage('No stream URL for this source', 'Try another server or opposite language.', true);
      return;
    }
    if (isIframe) {
      els.videoPlayer.pause();
      els.videoPlayer.removeAttribute('src');
      els.videoPlayer.load();
      els.videoPlayer.style.display = 'none';
      hidePlayerMessage();
      renderQualities([]);
      const iframe = document.createElement('iframe');
      iframe.className = 'player-iframe';
      iframe.src = url;
      iframe.allow = 'autoplay; fullscreen; encrypted-media; picture-in-picture';
      iframe.allowFullscreen = true;
      iframe.referrerPolicy = 'no-referrer';
      iframe.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;background:#000;z-index:6;';
      els.playerContainer.appendChild(iframe);
      return;
    } else {
      const existing = els.playerContainer.querySelector('.player-iframe');
      if (existing) existing.remove();
      els.videoPlayer.style.display = 'block';
    }
    hidePlayerMessage();
    const isHls = /\.m3u8(\?|$)/i.test(url);
    const wasPaused = els.videoPlayer.paused;
    const currentTime = els.videoPlayer.currentTime || 0;
    const tryDirect = () => {
      els.videoPlayer.src = url;
      els.videoPlayer.play().catch(() => {});
    };
    if (isHls && window.Hls && Hls.isSupported()) {
      const hls = new Hls({ enableWorker: true, lowLatencyMode: false, backBufferLength: 90 });
      state.hls = hls;
      hls.loadSource(url);
      hls.attachMedia(els.videoPlayer);
      hls.on(Hls.Events.MANIFEST_PARSED, (_e, data) => {
        renderQualities(data.levels || []);
        if (!wasPaused || currentTime === 0) {
          els.videoPlayer.play().catch(() => {});
        } else if (currentTime > 0) {
          els.videoPlayer.currentTime = currentTime;
          els.videoPlayer.play().catch(() => {});
        }
      });
      hls.on(Hls.Events.LEVEL_SWITCHED, () => {});
      hls.on(Hls.Events.ERROR, (_e, data) => {
        if (data.fatal) {
          switch (data.type) {
            case Hls.ErrorTypes.NETWORK_ERROR:
              try { hls.startLoad(); } catch(_) {
                showPlayerMessage('Network error loading stream', 'Source may be offline. Try another server.', true);
              }
              break;
            case Hls.ErrorTypes.MEDIA_ERROR:
              try { hls.recoverMediaError(); } catch(_) {
                showPlayerMessage('Media error', 'Try another server or quality.', true);
              }
              break;
            default:
              try { hls.destroy(); } catch(_){}
              showPlayerMessage('Failed to play this source', 'Try the other language or another server.', true);
              break;
          }
        }
      });
    } else if (els.videoPlayer.canPlayType('application/vnd.apple.mpegurl')) {
      tryDirect();
    } else {
      tryDirect();
    }
  }

  async function tryFetchJson(urls, opts) {
    let lastErr = null;
    for (const u of urls) {
      try {
        const res = await fetch(u, opts);
        if (!res.ok) { lastErr = new Error('HTTP ' + res.status); continue; }
        return await res.json();
      } catch (e) { lastErr = e; }
    }
    throw lastErr || new Error('All endpoints failed');
  }

  async function fetchSources() {
    const ep = state.episodes.find(e => parseInt(e.number, 10) === state.currentEpNum) || state.episodes[state.currentEpNum - 1] || null;
    const epId = ep ? (ep.id || '') : '';
    const query = new URLSearchParams({
      id: animeId || '',
      path: animePath || '',
      ep: String(state.currentEpNum),
      epid: epId,
      lang: state.currentLang,
      title: state.anime?.title || animeTitleParam || ''
    });
    try {
      showPlayerMessage(`Loading Episode ${state.currentEpNum} (${state.currentLang.toUpperCase()})...`, 'Fetching stream source...');
      const qs = query.toString();
      const urls = [
        `api/sources?${qs}`,
        `api/sources.php?${qs}`,
      ];
      const data = await tryFetchJson(urls, {
        headers: { 'Accept': 'application/json' }
      }) || {};
      const list = Array.isArray(data.sources) ? data.sources : [];
      if (!list.length) {
        const msg = data.message || 'No stream sources found for this episode/language.';
        showPlayerMessage('No sources available', msg + ' Try switching language or server.', true);
        renderServers([]);
        renderQualities([]);
        return;
      }
      state.currentServerIdx = 0;
      renderServers(list);
      playSource(list[0]);
    } catch (err) {
      showPlayerMessage('Failed to load sources', String(err.message || err), true);
    }
  }

  async function fetchAnimeInfo(forceReload) {
    showLoader();
    const query = new URLSearchParams({
      id: animeId || '',
      path: animePath || '',
      title: animeTitleParam || ''
    });
    try {
      const qs = query.toString();
      const urls = [
        `api/info?${qs}`,
        `api/info.php?${qs}`,
      ];
      const data = await tryFetchJson(urls, {
        headers: { 'Accept': 'application/json' }
      }) || {};
      if ((!data || !data.anime) && forceReload) {
        throw new Error('No data returned');
      }
      renderInfo(data || {});
      if (state.episodes.length && state.currentEpNum) {
        fetchSources();
      } else {
        showPlayerMessage(
          state.episodes.length ? 'Select an episode to start' : 'Episode list unavailable',
          state.episodes.length
            ? 'Live scraper returned data but episode list is empty. Choose from above, or fallback to stream discovery.'
            : 'Live hianime clones may be blocked. Use API sources from another backend, or click below to try again.'
        );
      }
    } catch (err) {
      renderInfo({ anime: { title: animeTitleParam || 'Anime', id: animeId, image: '', type: 'TV', language: { sub: '0', dub: '0' } }, episodes: [] });
      showPlayerMessage('Info load failed', String(err.message || err), true);
    } finally {
      hideLoader();
    }
  }

  function initLangSwitcher() {
    if (!els.langSwitcher) return;
    els.langSwitcher.addEventListener('click', (e) => {
      const btn = e.target.closest('.lang-btn');
      if (!btn) return;
      const lang = btn.dataset.lang === 'sub' ? 'sub' : 'dub';
      if (lang === state.currentLang) return;
      state.currentLang = lang;
      document.querySelectorAll('.lang-btn').forEach(b => b.classList.toggle('active', b.dataset.lang === lang));
      fetchSources();
    });
  }

  function initEpJump() {
    if (els.btnEpJump && els.epJump) {
      const go = () => {
        const v = parseInt(els.epJump.value, 10) || 1;
        const clamped = Math.min(Math.max(1, v), state.episodes.length || v);
        selectEpisode(clamped);
      };
      els.btnEpJump.addEventListener('click', go);
      els.epJump.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
    }
  }

  function initKeyboard() {
    document.addEventListener('keydown', (e) => {
      const tag = (e.target && e.target.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea') return;
      if (e.key === 'ArrowRight' && !els.videoPlayer.paused === false ? true : (els.videoPlayer.paused)) {
        if (e.key === 'ArrowRight' && e.shiftKey && state.episodes.length) {
          e.preventDefault();
          const n = Math.min(state.episodes.length, state.currentEpNum + 1);
          if (n !== state.currentEpNum) selectEpisode(n);
          return;
        }
      }
      if (e.key === 'ArrowLeft' && e.shiftKey && state.episodes.length) {
        e.preventDefault();
        const n = Math.max(1, state.currentEpNum - 1);
        if (n !== state.currentEpNum) selectEpisode(n);
      }
    });
  }

  function init() {
    cacheEls();
    initLangSwitcher();
    initEpJump();
    initKeyboard();
    if (!animeId && !animePath && !animeTitleParam) {
      showPlayerMessage('No anime selected', 'Return to browse and pick an anime to watch.', true);
      hideLoader();
      return;
    }
    fetchAnimeInfo(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
