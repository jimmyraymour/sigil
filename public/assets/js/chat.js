import { API } from './api.js';

const $ = (id) => document.getElementById(id);

const els = {
  description: $('description'),
  logoDescription: $('logo-description'),
  brandName: $('brand-name'),
  entityType: $('entity-type'),
  style: $('style'),
  palette: $('palette'),
  generateBtn: $('generate-btn'),
  briefBox: $('brief-box'),
  briefText: $('brief-text'),
  modelPicker: $('model-picker'),
  variants: $('variants'),
  format: $('format'),
  renderBtn: $('render-btn'),
  costNote: $('cost-note'),
  chat: $('chat'),
  feedbackForm: $('feedback-form'),
  feedback: $('feedback'),
  renderStatus: $('render-status'),
  results: $('results'),
};

let currentBrief = null;

const selectedModel = () => els.modelPicker.querySelector('input[name="model"]:checked');

function setStatus(msg, kind) {
  els.renderStatus.textContent = msg || '';
  els.renderStatus.className = kind === 'error' ? 'error' : 'muted';
}

function addChat(role, text) {
  const div = document.createElement('div');
  div.className = 'chat-msg ' + role;
  div.textContent = text;
  els.chat.appendChild(div);
  els.chat.scrollTop = els.chat.scrollHeight;
}

function populateChat(chain) {
  els.chat.innerHTML = '';
  for (const b of chain) {
    if (b.feedback) addChat('user', b.feedback);
    else if (b.raw_description) addChat('user', b.raw_description);
    addChat('assistant', b.refined_brief);
  }
}

// ---------------------------------------------------------------- model

// Formats differ per model (only Recraft does SVG), so the format list is
// rebuilt from the selected model rather than hardcoded.
function syncModel() {
  const model = selectedModel();
  if (!model) {
    els.costNote.textContent = 'No model is configured. Add a key in .env, or enable the free model.';
    els.renderBtn.disabled = true;
    return;
  }

  const formats = (model.dataset.formats || 'png').split(',');
  els.format.innerHTML = '';
  for (const f of formats) {
    const opt = document.createElement('option');
    opt.value = f;
    opt.textContent = f.toUpperCase();
    if (f === model.dataset.defaultFormat) opt.selected = true;
    els.format.appendChild(opt);
  }
  els.format.disabled = formats.length < 2;

  updateCostNote();
  els.renderBtn.disabled = !currentBrief;
}

function updateCostNote() {
  const model = selectedModel();
  if (!model) return;
  const unit = parseFloat(model.dataset.cost || '0');
  const n = parseInt(els.variants.value, 10) || 1;
  els.costNote.textContent = unit === 0
    ? `${n} image${n > 1 ? 's' : ''} · free`
    : `${n} image${n > 1 ? 's' : ''} · about $${(unit * n).toFixed(2)} total`;
  els.renderBtn.textContent = unit === 0 ? 'Generate logos — free' : `Generate logos — ~$${(unit * n).toFixed(2)}`;
}

// --------------------------------------------------------------- brief

async function generateBrief() {
  const description = els.description.value.trim();
  if (!description) {
    setStatus('Say what it does first — that field is required.', 'error');
    els.description.focus();
    return;
  }

  els.generateBtn.disabled = true;
  els.generateBtn.textContent = 'Writing…';
  setStatus('Writing the concept on your local model…');

  try {
    const brief = await API.post('/brief', {
      description,
      logo_description: els.logoDescription.value.trim() || null,
      brand_name: els.brandName.value.trim() || null,
      entity_type: els.entityType.value,
      style: els.style.value,
      palette_hint: els.palette.value.trim() || null,
    });
    currentBrief = brief;
    els.briefBox.classList.remove('hidden');
    els.briefText.textContent = brief.refined_brief;
    els.feedbackForm.classList.remove('hidden');
    populateChat([brief]);
    els.renderBtn.disabled = !selectedModel();
    setStatus('Concept ready. Refine it for free, or generate images.');
  } catch (e) {
    setStatus(e.message, 'error');
  } finally {
    els.generateBtn.disabled = false;
    els.generateBtn.textContent = 'Write the concept — free';
  }
}

async function sendFeedback(ev) {
  ev.preventDefault();
  const feedback = els.feedback.value.trim();
  if (!feedback || !currentBrief) return;
  els.feedback.value = '';
  addChat('user', feedback);
  setStatus('Reworking the concept…');
  try {
    const brief = await API.post('/iterate', { brief_id: currentBrief.id, feedback });
    currentBrief = brief;
    addChat('assistant', brief.refined_brief);
    els.briefText.textContent = brief.refined_brief;
    setStatus('Concept updated.');
  } catch (e) {
    setStatus(e.message, 'error');
  }
}

// -------------------------------------------------------------- render

function resultCard(r, index) {
  const card = document.createElement('figure');
  card.className = 'result-card';

  if (r.status === 'succeeded' && r.asset_url) {
    const img = document.createElement('img');
    img.src = r.asset_url;
    img.alt = 'Logo option ' + (index + 1);
    img.loading = 'lazy';
    card.appendChild(img);

    const cap = document.createElement('figcaption');
    const a = document.createElement('a');
    a.href = r.asset_url;
    a.download = 'sigil-' + r.id.slice(0, 8) + '.' + r.output_format;
    a.textContent = 'Download ' + r.output_format.toUpperCase();
    a.className = 'btn small';
    cap.appendChild(a);
    card.appendChild(cap);
  } else {
    card.classList.add('failed');
    const p = document.createElement('p');
    p.textContent = r.error_message || 'This one failed.';
    card.appendChild(p);
  }
  return card;
}

// Generation takes ~40s per image on the free model. Showing the grid
// filling in beats a single line of status text that sits there for a minute.
function showSkeletons(n) {
  els.results.innerHTML = '';
  for (let i = 0; i < n; i++) {
    const card = document.createElement('figure');
    card.className = 'result-card skeleton';
    card.innerHTML = '<div class="skeleton-img"></div><figcaption>option ' + (i + 1) + '</figcaption>';
    els.results.appendChild(card);
  }
}

function emptyState(message) {
  els.results.innerHTML = '<p class="empty">' + message + '</p>';
}

// Renders one variant per request rather than the whole batch in one call.
// A batch takes minutes, which overruns PHP's execution limit and leaves the
// panel blank the whole time; this way each option appears as it finishes.
async function renderLogos() {
  if (!currentBrief) return;
  const model = selectedModel();
  if (!model) return;

  const n = parseInt(els.variants.value, 10) || 1;
  els.renderBtn.disabled = true;
  els.renderBtn.textContent = 'Generating…';
  showSkeletons(n);

  let done = 0;
  let failed = 0;
  let cost = 0;

  try {
    setStatus('Thinking up ' + n + ' different idea' + (n > 1 ? 's' : '') + '… (free)');
    const plan = await API.post('/concepts', { brief_id: currentBrief.id, count: n });
    const concepts = plan.concepts || [];

    // Re-shape the grid if the model proposed fewer ideas than asked.
    if (concepts.length !== n) showSkeletons(concepts.length);

    for (const [i, concept] of concepts.entries()) {
      setStatus(`Generating option ${i + 1} of ${concepts.length} with ${model.value}…`);
      try {
        const batch = await API.post('/render', {
          brief_id: currentBrief.id,
          provider: model.value,
          output_format: els.format.value,
          batch_id: plan.batch_id,
          variant_index: i,
          concept,
        });
        const r = batch.renders[0];
        cost += Number(batch.cost_usd || 0);
        if (r && r.status === 'succeeded') done++; else failed++;
        replaceSlot(i, r, i);
      } catch (e) {
        failed++;
        replaceSlot(i, { status: 'failed', error_message: e.message }, i);
      }
    }

    const costText = cost === 0 ? 'free' : '$' + cost.toFixed(4);
    setStatus(
      `${done} of ${concepts.length} generated · ${costText}` + (failed ? ` · ${failed} failed` : ''),
      failed && !done ? 'error' : undefined
    );
  } catch (e) {
    emptyState('Nothing generated — ' + e.message);
    setStatus(e.message, 'error');
  } finally {
    els.renderBtn.disabled = false;
    updateCostNote();
  }
}

// Swaps one skeleton for its finished card, leaving the others alone.
function replaceSlot(slot, render, index) {
  const card = resultCard(render, index);
  const existing = els.results.children[slot];
  if (existing) els.results.replaceChild(card, existing);
  else els.results.appendChild(card);
}

// ---------------------------------------------------------------- load

async function loadRender(id) {
  try {
    const render = await API.get('/render/' + id);
    const briefData = await API.get('/brief/' + render.brief_id);
    currentBrief = briefData.brief;
    els.briefBox.classList.remove('hidden');
    els.briefText.textContent = currentBrief.refined_brief;
    els.feedbackForm.classList.remove('hidden');
    populateChat(briefData.chain);

    // Open the whole batch it belonged to, not just the one image.
    const rows = render.batch_id
      ? (await API.get('/batch/' + render.batch_id)).renders
      : [render];
    rows.forEach((r, i) => els.results.appendChild(resultCard(r, i)));

    els.renderBtn.disabled = !selectedModel();
    setStatus('Loaded from the library.');
  } catch (e) {
    setStatus('Could not load that render: ' + e.message, 'error');
  }
}

els.generateBtn.addEventListener('click', generateBrief);
els.feedbackForm.addEventListener('submit', sendFeedback);
els.renderBtn.addEventListener('click', renderLogos);
els.variants.addEventListener('change', updateCostNote);
els.modelPicker.addEventListener('change', syncModel);

syncModel();

const params = new URLSearchParams(location.search);
const renderId = params.get('render');
if (renderId) loadRender(renderId);
