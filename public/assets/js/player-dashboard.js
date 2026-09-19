/**
 * player-dashboard.js
 *
 * Loads tournaments the logged-in player has joined, renders them
 * as cards, and handles the Join Tournament modal form.
 */

const TOURNAMENT_STATUS_LABELS = {
  draft: 'Draft',
  open_for_registration: 'Open for registration',
  in_progress: 'In progress',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

const PARTICIPANT_STATUS_LABELS = {
  active: null, // no extra badge needed for the normal case
  disqualified: 'Disqualified',
  withdrawn: 'Withdrawn',
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
  const data = await response.json();
  return { status: response.status, data };
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

// ------------------------------------------------------------
// Auth check
// ------------------------------------------------------------
async function requirePlayer() {
  const { status, data } = await getJSON('/api/auth/me.php');

  if (status !== 200 || !data.success) {
    window.location.href = 'auth.html';
    return null;
  }

  if (!['player', 'both'].includes(data.user.role)) {
    window.location.href = 'host-dashboard.html';
    return null;
  }

  return data.user;
}

// ------------------------------------------------------------
// Tournament list rendering
// ------------------------------------------------------------
function renderParticipationCard(entry) {
  const t = entry.tournament;
  const card = document.createElement('a');
  card.className = 'card tournament-card';
  card.href = `tournament-view.html?id=${t.id}`;

  const statusLabel = TOURNAMENT_STATUS_LABELS[t.status] || t.status;
  const participantBadgeLabel = PARTICIPANT_STATUS_LABELS[entry.participant_status];
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
    ${participantBadgeLabel ? `<span class="status-badge status-cancelled">${participantBadgeLabel}</span>` : ''}
  `;

  return card;
}

async function loadParticipations() {
  const grid = document.getElementById('tournament-grid');
  const emptyState = document.getElementById('empty-state');

  const { status, data } = await getJSON('/api/tournaments/my-participations.php');

  if (status !== 200 || !data.success) {
    return;
  }

  grid.innerHTML = '';

  if (data.participations.length === 0) {
    grid.hidden = true;
    emptyState.hidden = false;
    return;
  }

  emptyState.hidden = true;
  grid.hidden = false;

  data.participations.forEach((entry) => {
    grid.appendChild(renderParticipationCard(entry));
  });
}

// ------------------------------------------------------------
// Join Tournament modal
// ------------------------------------------------------------
function initModal() {
  const overlay = document.getElementById('join-modal-overlay');
  const openButtons = [
    document.getElementById('open-join-modal'),
    document.getElementById('empty-state-join-btn'),
  ];
  const closeBtn = document.getElementById('close-join-modal');
  const form = document.getElementById('join-tournament-form');
  const alertEl = document.getElementById('join-alert');

  function openModal() {
    overlay.hidden = false;
    document.getElementById('join-code-input').focus();
  }

  function closeModal() {
    overlay.hidden = true;
    form.reset();
    setAlert(alertEl, null);
  }

  openButtons.forEach((btn) => btn.addEventListener('click', openModal));
  closeBtn.addEventListener('click', closeModal);

  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) closeModal();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !overlay.hidden) closeModal();
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setAlert(alertEl, null);

    const submitBtn = form.querySelector('button[type="submit"]');
    const joinCode = form.join_code.value.trim();

    submitBtn.disabled = true;
    submitBtn.textContent = 'Joining…';

    try {
      const { status, data } = await postJSON('/api/tournaments/join.php', { join_code: joinCode });

      if (status !== 201 || !data.success) {
        setAlert(alertEl, data.error || 'Something went wrong. Please try again.');
        return;
      }

      closeModal();
      await loadParticipations();
    } catch (err) {
      setAlert(alertEl, 'Could not reach the server. Check your connection and try again.');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Join tournament';
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
  const user = await requirePlayer();
  if (!user) return;

  document.getElementById('username-display').textContent = user.username;

  initModal();
  initLogout();
  await loadParticipations();
});
