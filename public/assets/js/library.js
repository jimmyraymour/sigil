import { API } from './api.js';

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}

async function load() {
  const box = document.getElementById('library');
  box.innerHTML = '<p class="empty">Loading…</p>';
  try {
    const data = await API.get('/library?limit=50&offset=0');
    if (!data.renders || !data.renders.length) {
      box.innerHTML = '<p class="empty">No renders yet. Head to the Studio and generate a few — the default model is free.</p>';
      return;
    }
    box.innerHTML = '';
    for (const r of data.renders) {
      const card = document.createElement('div');
      card.className = 'card';

      let img;
      if (r.status === 'succeeded' && r.asset_url) {
        img = '<img src="' + esc(r.asset_url) + '" alt="render" loading="lazy">';
      } else {
        img = '<div class="placeholder">' + esc(r.status) + '</div>';
      }

      const snippet = r.brief_snippet ? r.brief_snippet.slice(0, 120) + '…' : '';
      const cost = Number(r.cost_usd || 0);
      const costLabel = cost > 0 ? ' · $' + cost.toFixed(3) : ' · free';
      card.innerHTML =
        '<a class="card-img" href="' + (API.base() || '') + '/?render=' + esc(r.id) + '">' + img + '</a>' +
        '<div class="card-body">' +
        '<div class="card-meta">' + esc(r.provider) + ' · ' + esc(r.output_format) + costLabel + ' · ' + esc(r.created_at) + '</div>' +
        '<div class="card-snippet">' + esc(snippet) + '</div>' +
        '</div>';
      box.appendChild(card);
    }
  } catch (e) {
    box.innerHTML = '<p class="error">' + esc(e.message) + '</p>';
  }
}

load();
