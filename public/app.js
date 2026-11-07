/*
 * Search page logic (vanilla JS, no build step).
 *
 * Flow: submit -> loading skeletons -> GET api/search.php?q= -> results | empty | error.
 * - The query is kept in the URL (?q=...) so a search can be shared and Back/Forward work.
 * - A new search cancels the previous in-flight request (AbortController).
 * - Product data is inserted with textContent only (never innerHTML) to rule out XSS.
 */
(() => {
  'use strict';

  const EXAMPLES = [
    'שואב אבק',
    'iPhone 16',
    'אייפון',
    'מתנה למישהו שאוהב לבשל',
    'משהו שיעזור לנקות שערות של כלב מהספה',
  ];
  const REQUEST_TIMEOUT_MS = 15000;
  const SKELETON_COUNT = 8;
  const FALLBACK_IMAGE =
    'data:image/svg+xml,' +
    encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" fill="#eef0f3"/>' +
        '<path d="M38 80l16-20 12 14 8-10 12 16z" fill="#c9cdd4"/><circle cx="48" cy="46" r="7" fill="#c9cdd4"/></svg>'
    );

  const $ = (id) => document.getElementById(id);
  const form = $('search-form');
  const input = $('q');
  const clearBtn = $('clear');
  const grid = $('grid');
  const title = $('results-title');
  const statusEl = $('status');
  const notice = $('notice');
  const emptyEl = $('empty');
  const emptyText = $('empty-text');
  const errorEl = $('error');
  const retryBtn = $('retry');

  const priceFormat = new Intl.NumberFormat('he-IL', { style: 'currency', currency: 'ILS', maximumFractionDigits: 0 });

  let controller = null;
  let lastQuery = '';

  // ---------- helpers ----------
  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function renderChips(container) {
    for (const example of EXAMPLES) {
      const chip = el('button', 'chip', example);
      chip.type = 'button';
      chip.addEventListener('click', () => {
        input.value = example;
        syncClearButton();
        search(example);
      });
      container.append(chip);
    }
  }

  function syncClearButton() {
    clearBtn.hidden = input.value.length === 0;
  }

  function setView(view) {
    grid.hidden = !(view === 'results' || view === 'loading');
    emptyEl.hidden = view !== 'empty';
    errorEl.hidden = view !== 'error';
    title.hidden = !(view === 'results' || view === 'loading');
    grid.setAttribute('aria-busy', String(view === 'loading'));
    if (view !== 'results') notice.hidden = true;
  }

  function updateUrl(query, replace) {
    const url = query ? `?q=${encodeURIComponent(query)}` : location.pathname;
    const sameAsCurrent = (new URLSearchParams(location.search).get('q') || '') === query;
    if (replace || sameAsCurrent) history.replaceState({ q: query }, '', url); // no duplicate Back steps
    else history.pushState({ q: query }, '', url);
    document.title = query ? `${query} · חיפוש חכם` : 'חיפוש חכם בקטלוג';
  }

  // ---------- rendering ----------
  function renderSkeletons() {
    grid.replaceChildren();
    for (let i = 0; i < SKELETON_COUNT; i++) {
      const card = el('li', 'card skeleton');
      card.setAttribute('aria-hidden', 'true');
      const body = el('div', 'card-body');
      body.append(el('div', 'bar short'), el('div', 'bar long'), el('div', 'bar price'));
      card.append(el('div', 'card-media'), body);
      grid.append(card);
    }
  }

  function renderCard(product) {
    const card = el('li', 'card');

    const media = el('div', 'card-media');
    const img = document.createElement('img');
    img.src = product.image_url;
    img.alt = product.name;
    img.loading = 'lazy';
    img.decoding = 'async';
    img.width = 600;
    img.height = 600;
    img.addEventListener('error', () => { img.src = FALLBACK_IMAGE; }, { once: true });
    media.append(img);

    const body = el('div', 'card-body');
    if (product.brand) body.append(el('p', 'card-brand', product.brand));
    body.append(el('h3', 'card-name', product.name));
    body.append(el('p', 'card-price', priceFormat.format(product.price)));

    card.append(media, body);
    return card;
  }

  function renderResults(data, query) {
    if (data.results.length === 0) {
      emptyText.textContent = `לא מצאנו תוצאות עבור „${query}”. נסו לנסח אחרת, לתאר את הצורך או לבחור אחת מהדוגמאות:`;
      setView('empty');
      statusEl.textContent = 'לא נמצאו תוצאות';
      return;
    }
    grid.replaceChildren(...data.results.map(renderCard));
    title.textContent = 'מצאנו מוצרים בשבילכם';
    statusEl.textContent = `${data.results.length} תוצאות`;
    setView('results');
    notice.hidden = data.mode !== 'keyword';
  }

  // ---------- search ----------
  async function search(rawQuery, { updateHistory = true, replaceHistory = false } = {}) {
    const query = rawQuery.trim();
    if (!query) {
      input.focus();
      return;
    }
    lastQuery = query;
    if (updateHistory) updateUrl(query, replaceHistory);

    if (controller) controller.abort();
    const current = new AbortController();
    controller = current;
    let timedOut = false;
    const timer = setTimeout(() => { timedOut = true; current.abort(); }, REQUEST_TIMEOUT_MS);

    title.textContent = 'מחפשים…';
    statusEl.textContent = 'טוען תוצאות';
    renderSkeletons();
    setView('loading');

    try {
      const response = await fetch(`api/search.php?q=${encodeURIComponent(query)}`, {
        signal: current.signal,
        headers: { Accept: 'application/json' },
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !Array.isArray(data.results)) {
        throw new Error(`Bad response (HTTP ${response.status})`);
      }
      if (controller === current) renderResults(data, query);
    } catch (err) {
      if (current.signal.aborted && !timedOut) return; // replaced by a newer search
      if (controller !== current) return;
      console.error('Search failed:', err);
      statusEl.textContent = 'אירעה שגיאה';
      setView('error');
    } finally {
      clearTimeout(timer);
    }
  }

  function resetToIdle() {
    if (controller) controller.abort();
    controller = null;
    lastQuery = '';
    grid.replaceChildren();
    statusEl.textContent = '';
    setView('idle');
  }

  // ---------- events ----------
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    search(input.value);
  });

  input.addEventListener('input', syncClearButton);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && input.value) {
      event.preventDefault();
      clearBtn.click();
    }
  });

  clearBtn.addEventListener('click', () => {
    input.value = '';
    syncClearButton();
    input.focus();
    resetToIdle();
    updateUrl('', false);
  });

  retryBtn.addEventListener('click', () => search(lastQuery, { updateHistory: false }));

  window.addEventListener('popstate', () => {
    const query = new URLSearchParams(location.search).get('q') || '';
    input.value = query;
    syncClearButton();
    if (query) search(query, { updateHistory: false });
    else resetToIdle();
  });

  // ---------- init ----------
  renderChips($('examples'));
  renderChips($('empty-examples'));
  setView('idle');

  const initialQuery = new URLSearchParams(location.search).get('q') || '';
  if (initialQuery) {
    input.value = initialQuery;
    syncClearButton();
    search(initialQuery, { replaceHistory: true });
  } else if (window.matchMedia('(pointer: fine)').matches) {
    input.focus(); // desktop only — on phones this would pop the keyboard unasked
  }
})();
