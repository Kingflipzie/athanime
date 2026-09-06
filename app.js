(function () {
  'use strict';

  const state = {
    currentPage: 1,
    totalPages: 1,
    hasNextPage: false,
    results: [],
    loading: true
  };

  const els = {};

  function cacheEls() {
    els.loaderOverlay = document.getElementById('loaderOverlay');
    els.animeGrid = document.getElementById('animeGrid');
    els.pagination = document.getElementById('pagination');
    els.statPage = document.getElementById('statPage');
    els.statTotal = document.getElementById('statTotal');
    els.statCount = document.getElementById('statCount');
  }

  function showLoader() {
    if (els.loaderOverlay) {
      els.loaderOverlay.classList.remove('hidden');
    }
  }

  function hideLoader() {
    if (els.loaderOverlay) {
      setTimeout(() => {
        els.loaderOverlay.classList.add('hidden');
      }, 600);
    }
  }

  function cleanImageUrl(url) {
    if (!url) return '';
    return String(url).replace(/`/g, '').trim();
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  function createAnimeCard(anime, index) {
    const imageUrl = cleanImageUrl(anime.image);
    const title = escapeHtml(anime.title || 'Unknown');
    const type = escapeHtml(anime.type || 'TV');
    const dataId = escapeHtml(anime.dataId || '');
    const subCount = escapeHtml(anime.language?.sub || '0');
    const dubCount = escapeHtml(anime.language?.dub || '0');

    const card = document.createElement('article');
    card.className = 'anime-card';
    card.style.animationDelay = (index * 0.04) + 's';
    card.setAttribute('data-id', dataId);
    card.setAttribute('data-path', escapeHtml(anime.id || ''));

    card.innerHTML = `
      <div class="card-image-wrap" data-title="${title}">
        <div class="card-badges">
          <span class="badge-type">${type}</span>
          <span class="badge-dub">DUB</span>
        </div>
        <img
          class="card-image"
          src="${imageUrl}"
          alt="${title}"
          loading="lazy"
          onerror="this.onerror=null;this.parentElement.classList.add('img-broken');this.style.display='none';"
        />
        <div class="card-lang-info">
          <span class="lang-pill lang-sub">SUB ${subCount}</span>
          <span class="lang-pill lang-dub">DUB ${dubCount}</span>
        </div>
      </div>
      <div class="card-info">
        <h3 class="card-title">${title}</h3>
        <div class="card-meta">
          <span class="card-data-id">${dataId}</span>
          <span class="card-type-small">${type}</span>
        </div>
      </div>
    `;

    card.addEventListener('click', () => handleCardClick(anime));

    return card;
  }

  function handleCardClick(anime) {
    const qs = new URLSearchParams();
    if (anime.dataId) qs.set('id', String(anime.dataId));
    if (anime.id) qs.set('path', String(anime.id));
    if (anime.title) qs.set('title', String(anime.title));
    const target = 'watch.html?' + qs.toString();
    window.location.href = target;
  }

  function renderGrid(animeList) {
    if (!els.animeGrid) return;
    els.animeGrid.innerHTML = '';

    if (!Array.isArray(animeList) || animeList.length === 0) {
      renderError('No anime found. Please try another page.');
      return;
    }

    const frag = document.createDocumentFragment();
    animeList.forEach((anime, idx) => {
      frag.appendChild(createAnimeCard(anime, idx));
    });
    els.animeGrid.appendChild(frag);
  }

  function renderError(msg) {
    if (!els.animeGrid) return;
    els.animeGrid.innerHTML = `
      <div class="error-state">
        <h3>&#9888; Something went wrong</h3>
        <p>${escapeHtml(msg)}</p>
        <button class="retry-btn" type="button">Retry</button>
      </div>
    `;
    const retryBtn = els.animeGrid.querySelector('.retry-btn');
    if (retryBtn) {
      retryBtn.addEventListener('click', () => fetchDubbedAnime(state.currentPage));
    }
  }

  function getPageList(current, total) {
    const pages = [];
    const maxVisible = 7;
    if (total <= maxVisible) {
      for (let i = 1; i <= total; i++) pages.push(i);
      return pages;
    }
    pages.push(1);
    const left = Math.max(2, current - 1);
    const right = Math.min(total - 1, current + 1);
    if (left > 2) pages.push('left');
    for (let i = left; i <= right; i++) pages.push(i);
    if (right < total - 1) pages.push('right');
    pages.push(total);
    return pages;
  }

  function renderPagination() {
    if (!els.pagination) return;
    els.pagination.innerHTML = '';

    const { currentPage, totalPages, hasNextPage } = state;

    const prevBtn = document.createElement('button');
    prevBtn.className = 'page-btn';
    prevBtn.type = 'button';
    prevBtn.textContent = '‹ Prev';
    prevBtn.disabled = currentPage <= 1;
    prevBtn.addEventListener('click', () => {
      if (currentPage > 1) goToPage(currentPage - 1);
    });
    els.pagination.appendChild(prevBtn);

    const pageList = getPageList(currentPage, totalPages);
    pageList.forEach((item) => {
      if (item === 'left' || item === 'right') {
        const dots = document.createElement('span');
        dots.className = 'page-dots';
        dots.textContent = '...';
        els.pagination.appendChild(dots);
        return;
      }
      const btn = document.createElement('button');
      btn.className = 'page-btn' + (item === currentPage ? ' active' : '');
      btn.type = 'button';
      btn.textContent = String(item);
      btn.addEventListener('click', () => goToPage(item));
      els.pagination.appendChild(btn);
    });

    const nextBtn = document.createElement('button');
    nextBtn.className = 'page-btn';
    nextBtn.type = 'button';
    nextBtn.textContent = 'Next ›';
    nextBtn.disabled = !hasNextPage && currentPage >= totalPages;
    nextBtn.addEventListener('click', () => {
      if (hasNextPage || currentPage < totalPages) goToPage(currentPage + 1);
    });
    els.pagination.appendChild(nextBtn);
  }

  function updateStats() {
    if (els.statPage) els.statPage.textContent = String(state.currentPage);
    if (els.statTotal) els.statTotal.textContent = String(state.totalPages || 1);
    if (els.statCount) els.statCount.textContent = String(state.results.length || 0);
  }

  function goToPage(page) {
    const target = Math.max(1, Math.min(page, state.totalPages || 1));
    if (target === state.currentPage && state.results.length > 0) return;
    fetchDubbedAnime(target);
  }

  async function tryFetchJson(urls, opts) {
    let lastErr = null;
    for (const u of urls) {
      try {
        const res = await fetch(u, opts);
        if (!res.ok) { lastErr = new Error(`HTTP ${res.status}`); continue; }
        return await res.json();
      } catch (e) { lastErr = e; }
    }
    throw lastErr || new Error('All endpoints failed');
  }

  async function fetchDubbedAnime(page = 1) {
    state.loading = true;
    showLoader();
    try {
      const qs = `page=${encodeURIComponent(page)}`;
      const urls = [
        `/api/dubbed-anime?${qs}`,
        `/api/dubbed-anime.php?${qs}`,
      ];
      const data = await tryFetchJson(urls, {
        headers: { 'Accept': 'application/json' }
      });
      state.currentPage = data.page || page;
      state.totalPages = data.totalPage || data.totalPages || 1;
      state.hasNextPage = !!data.hasNextPage;
      state.results = Array.isArray(data.results) ? data.results : [];
      renderGrid(state.results);
      renderPagination();
      updateStats();
    } catch (err) {
      console.error('[Athanime] Fetch error:', err);
      renderError(err.message || 'Failed to load anime list');
    } finally {
      state.loading = false;
      hideLoader();
    }
  }

  function initSmoothScroll() {
    document.addEventListener('click', (e) => {
      const link = e.target.closest('a[href^="#"]');
      if (link) {
        const href = link.getAttribute('href');
        if (href && href.length > 1) {
          const target = document.querySelector(href);
          if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      }
    });
  }

  function init() {
    cacheEls();
    initSmoothScroll();
    fetchDubbedAnime(1);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
