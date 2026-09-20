/**
 * host-dashboard.js
 *
 * Loads the logged-in host's tournaments, renders them as cards,
 * and handles the Create Tournament modal form.
 */

const STATUS_LABELS = {
  draft: 'Draft',
  open_for_registration: 'Open for registration',
  in_progress: 'In progress',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

function setAlert(el, message) {
  if (!message) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = message;
}

function setFieldError(id, message) {
  const el = document.getElementById(id);
  if (!el) return;
  if (!message) {
    el.hidden = true;
    el.textContent = '';
    return;
  }
  el.hidden = false;
  el.textContent = message;
}

function clearFieldErrors(ids) {
  ids.forEach((id) => setFieldError(id, null));
}

async function getJSON(url) {
  const response = await fetch(url, { credentials: 'same-origin' });
  const data = await response.json();
  return { status: response.status, data };
}

async function postJSON(url, body) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  });
  const rawText = await response.text();
  try {
    const data = JSON.parse(rawText);
    return { status: response.status, data };
  } catch (err) {
    // TEMP DIAGNOSTIC: surface the actual response body instead of
    // swallowing it as a generic parse failure.
    throw new Error(`Non-JSON response (status ${response.status}): ${rawText.slice(0, 500)}`);
  }
}

// ------------------------------------------------------------
// Auth check
// ------------------------------------------------------------
async function requireHost() {
  const { status, data } = await getJSON('/api/auth/me.php');

  if (status !== 200 || !data.success) {
    window.location.href = 'auth.html';
    return null;
  }

  if (!['host', 'both'].includes(data.user.role)) {
    window.location.href = 'player-dashboard.html';
    return null;
  }

  return data.user;
}

// ------------------------------------------------------------
// Tournament list rendering
// ------------------------------------------------------------
function renderTournamentCard(t) {
  const card = document.createElement('a');
  card.className = 'card tournament-card';
  card.href = `tournament-manage.html?id=${t.id}`;
  card.style.display = 'flex';

  const statusLabel = STATUS_LABELS[t.status] || t.status;
  const cap = t.max_participants ? `/ ${t.max_participants}` : '';

  card.innerHTML = `
    <div class="tournament-card-top">
      <div>
        <div class="tournament-card-name">${escapeHtml(t.name)}</div>
        <div class="tournament-card-meta">${escapeHtml(t.game_type)} · ${t.format === 'knockout' ? 'Knockout' : 'Round-robin'}</div>
      </div>
      <span class="status-badge status-${t.status}">${statusLabel}</span>
    </div>
    <div class="tournament-card-stats">
      <span>${t.active_participant_count} ${cap} players</span>
      ${t.start_date ? `<span>Starts ${t.start_date}</span>` : ''}
    </div>
    <div class="tournament-card-code">
      Join code: <code>${escapeHtml(t.join_code)}</code>
      <button type="button" class="copy-btn" data-code="${escapeHtml(t.join_code)}">Copy</button>
    </div>
  `;

  return card;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

async function loadTournaments() {
  const grid = document.getElementById('tournament-grid');
  const emptyState = document.getElementById('empty-state');

  const { status, data } = await getJSON('/api/tournaments/my-tournaments.php');

  if (status !== 200 || !data.success) {
    return; // fails quietly here — the auth check already handles the redirect case
  }

  grid.innerHTML = '';

  if (data.tournaments.length === 0) {
    grid.hidden = true;
    emptyState.hidden = false;
    return;
  }

  emptyState.hidden = true;
  grid.hidden = false;

  data.tournaments.forEach((t) => {
    grid.appendChild(renderTournamentCard(t));
  });

  // Wire up copy buttons for this render pass.
  grid.querySelectorAll('.copy-btn').forEach((btn) => {
    btn.addEventListener('click', async (event) => {
      event.preventDefault();
      event.stopPropagation();
      try {
        await navigator.clipboard.writeText(btn.dataset.code);
        const original = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(() => {
          btn.textContent = original;
        }, 1500);
      } catch (err) {
        // Clipboard API unavailable — the code is still visible to copy manually.
      }
    });
  });
}

// ------------------------------------------------------------
// Create Tournament modal
// ------------------------------------------------------------
function initModal() {
  const overlay = document.getElementById('create-modal-overlay');
  const openButtons = [
    document.getElementById('open-create-modal'),
    document.getElementById('empty-state-create-btn'),
  ];
  const closeBtn = document.getElementById('close-create-modal');
  const form = document.getElementById('create-tournament-form');
  const formatSelect = document.getElementById('format');
  const legsField = document.getElementById('legs-field');

  function openModal() {
    overlay.hidden = false;
    document.getElementById('tournament-name').focus();
  }

  function closeModal() {
    overlay.hidden = true;
    form.reset();
    setAlert(document.getElementById('create-alert'), null);
    clearFieldErrors(['name-error', 'game_type-error', 'format-error', 'max_participants-error', 'start_date-error']);
    legsField.hidden = false;
  }

  openButtons.forEach((btn) => btn.addEventListener('click', openModal));
  closeBtn.addEventListener('click', closeModal);

  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) closeModal();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !overlay.hidden) closeModal();
  });

  // Legs only make sense for round-robin.
  formatSelect.addEventListener('change', () => {
    legsField.hidden = formatSelect.value !== 'round_robin';
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const alertEl = document.getElementById('create-alert');
    const submitBtn = form.querySelector('button[type="submit"]');
    setAlert(alertEl, null);
    clearFieldErrors(['name-error', 'game_type-error', 'format-error', 'max_participants-error', 'start_date-error']);

    const formData = new FormData(form);
    const payload = {
      name: formData.get('name').trim(),
      game_type: formData.get('game_type'),
      format: formData.get('format'),
      description: formData.get('description').trim() || null,
      max_participants: formData.get('max_participants') ? Number(formData.get('max_participants')) : null,
      start_date: formData.get('start_date') || null,
      no_show_policy: formData.get('no_show_policy'),
      grace_period_hours: Number(formData.get('grace_period_hours')),
      max_missed_matches: formData.get('max_missed_matches') ? Number(formData.get('max_missed_matches')) : null,
    };

    if (payload.format === 'round_robin') {
      payload.legs = Number(formData.get('legs'));
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating…';

    try {
      const { status, data } = await postJSON('/api/tournaments/create.php', payload);

      if (status === 422 && data.errors) {
        Object.entries(data.errors).forEach(([field, message]) => {
          setFieldError(`${field}-error`, message);
        });
        return;
      }

      if (status !== 201 || !data.success) {
        setAlert(alertEl, data.error || 'Something went wrong. Please try again.');
        return;
      }

      closeModal();
      await loadTournaments();
    } catch (err) {
      setAlert(alertEl, err.message || 'Could not reach the server. Check your connection and try again.');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Create tournament';
    }
  });
}

// ------------------------------------------------------------
// Logout
// ------------------------------------------------------------
function initLogout() {
  document.getElementById('logout-btn').addEventListener('click', async () => {
    await postJSON('/api/auth/logout.php', {});
    window.location.href = 'auth.html';
  });
}

// ------------------------------------------------------------
// Init
// ------------------------------------------------------------
document.addEventListener('DOMContentLoaded', async () => {
  const user = await requireHost();
  if (!user) return;

  document.getElementById('username-display').textContent = user.username;

  initModal();
  initLogout();
  await loadTournaments();
});
