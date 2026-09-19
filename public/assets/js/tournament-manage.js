/**
 * tournament-manage.js
 *
 * Host-only management view for a single tournament (?id= in the
 * URL). Handles fixture generation, resolving matches (normal
 * score / walkover / void), and participant status changes.
 */

const STATUS_LABELS = {
  draft: 'Draft',
  open_for_registration: 'Open for registration',
  in_progress: 'In progress',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

const MATCH_STATUS_LABELS = {
  pending: 'Pending',
  awaiting_confirmation: 'Awaiting confirmation',
  disputed: 'Disputed',
  completed: 'Completed',
  forfeited: 'Walkover',
  void: 'Void',
};

const PARTICIPANT_STATUS_LABELS = {
  active: 'Active',
  disqualified: 'Disqualified',
  withdrawn: 'Withdrawn',
};

// Statuses a host can still act on (score entry / walkover / void).
const RESOLVABLE_MATCH_STATUSES = ['pending', 'awaiting_confirmation', 'disputed'];

const params = new URLSearchParams(window.location.search);
const tournamentId = params.get('id');

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
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

// ------------------------------------------------------------
// Auth + ownership check
// ------------------------------------------------------------
async function requireHostOwner() {
  if (!tournamentId) {
    window.location.href = 'host-dashboard.html';
    return null;
  }

  const meResult = await getJSON('/api/auth/me.php');
  if (meResult.status !== 200 || !meResult.data.success) {
    window.location.href = 'auth.html';
    return null;
  }

  const tournamentResult = await getJSON(`/api/tournaments/get.php?id=${encodeURIComponent(tournamentId)}`);
  if (tournamentResult.status !== 200 || !tournamentResult.data.success) {
    window.location.href = 'host-dashboard.html';
    return null;
  }

  const tournament = tournamentResult.data.tournament;
  if (tournament.host.id !== meResult.data.user.id) {
    window.location.href = 'host-dashboard.html';
    return null;
  }

  return { user: meResult.data.user, tournament };
}

// ------------------------------------------------------------
// Overview rendering
// ------------------------------------------------------------
function renderOverview(tournament) {
  document.getElementById('tournament-name').textContent = tournament.name;

  const statusBadge = document.getElementById('tournament-status-badge');
  statusBadge.textContent = STATUS_LABELS[tournament.status] || tournament.status;
  statusBadge.className = `status-badge status-${tournament.status}`;

  document.getElementById('tournament-format-label').textContent =
    tournament.format === 'knockout' ? 'Knockout' : 'Round-robin';
  document.getElementById('tournament-game-label').textContent = tournament.game_type;
  document.getElementById('tournament-description').textContent = tournament.description || '';

  const joinCodeEl = document.getElementById('join-code-display');
  if (tournament.join_code) {
    joinCodeEl.textContent = tournament.join_code;
  }

  const generateBtn = document.getElementById('generate-fixtures-btn');
  generateBtn.hidden = tournament.status !== 'open_for_registration';
}

function initCopyJoinCode() {
  document.getElementById('copy-join-code').addEventListener('click', async (event) => {
    const code = document.getElementById('join-code-display').textContent;
    try {
      await navigator.clipboard.writeText(code);
      const btn = event.currentTarget;
      const original = btn.textContent;
      btn.textContent = 'Copied';
      setTimeout(() => {
        btn.textContent = original;
      }, 1500);
    } catch (err) {
      // Clipboard API unavailable — code is still visible to copy manually.
    }
  });
}

function initGenerateFixtures() {
  document.getElementById('generate-fixtures-btn').addEventListener('click', async (event) => {
    const btn = event.currentTarget;
    btn.disabled = true;
    btn.textContent = 'Generating…';

    try {
      const { status, data } = await postJSON('/api/matches/generate-fixtures.php', {
        tournament_id: Number(tournamentId),
      });

      if (status !== 200 || !data.success) {
        alert(data.error || 'Could not generate fixtures.');
        return;
      }

      await loadAll();
    } finally {
      btn.disabled = false;
      btn.textContent = 'Generate fixtures now';
    }
  });
}

// ------------------------------------------------------------
// Match row rendering (shared by Disputes and Matches sections)
// ------------------------------------------------------------
function buildMatchRow(match) {
  const template = document.getElementById('match-row-template');
  const node = template.content.cloneNode(true);
  const row = node.querySelector('.match-row');

  const p1Name = match.player1 ? match.player1.username : 'TBD';
  const p2Name = match.player2 ? match.player2.username : 'TBD';
  node.querySelector('.player1-name').textContent = p1Name;
  node.querySelector('.player2-name').textContent = p2Name;

  const scoreEl = node.querySelector('.match-score');
  if (match.score_player1 !== null && match.score_player2 !== null) {
    scoreEl.textContent = `${match.score_player1} – ${match.score_player2}`;
  } else {
    scoreEl.textContent = '';
  }

  const statusBadge = node.querySelector('.match-status-badge');
  statusBadge.textContent = MATCH_STATUS_LABELS[match.status] || match.status;
  statusBadge.classList.add(
    match.status === 'disputed' ? 'status-cancelled' : match.status === 'completed' ? 'status-in_progress' : 'status-draft'
  );

  const pendingNote = node.querySelector('.match-pending-note');
  if (match.pending_submission) {
    pendingNote.hidden = false;
    const label = match.pending_submission.status === 'disputed' ? 'Disputed submission' : 'Pending confirmation';
    pendingNote.innerHTML = `${label}: <strong>${match.pending_submission.score_player1} – ${match.pending_submission.score_player2}</strong> (submitted by ${escapeHtml(match.pending_submission.submitted_by_username)})`;
  }

  const canResolve = RESOLVABLE_MATCH_STATUSES.includes(match.status) && match.player1 && match.player2;

  if (canResolve) {
    const resolvePanel = node.querySelector('.match-resolve');
    resolvePanel.hidden = false;

    const score1Input = node.querySelector('.score1-input');
    const score2Input = node.querySelector('.score2-input');
    const submitScoreBtn = node.querySelector('.submit-score-btn');
    const walkover1Btn = node.querySelector('.walkover1-btn');
    const walkover2Btn = node.querySelector('.walkover2-btn');
    const voidBtn = node.querySelector('.void-btn');

    walkover1Btn.textContent = `Walkover: ${p1Name}`;
    walkover2Btn.textContent = `Walkover: ${p2Name}`;

    submitScoreBtn.addEventListener('click', async () => {
      const score1 = Number(score1Input.value);
      const score2 = Number(score2Input.value);

      if (!Number.isInteger(score1) || !Number.isInteger(score2) || score1 < 0 || score2 < 0) {
        alert('Enter a valid score for both players.');
        return;
      }

      await resolveMatch(match.id, { resolution: 'normal', score1, score2 });
    });

    walkover1Btn.addEventListener('click', () => {
      resolveMatch(match.id, { resolution: 'walkover', winner_participant_id: match.player1.participant_id });
    });

    walkover2Btn.addEventListener('click', () => {
      resolveMatch(match.id, { resolution: 'walkover', winner_participant_id: match.player2.participant_id });
    });

    voidBtn.addEventListener('click', () => {
      if (confirm('Void this match? No points will be awarded to either player.')) {
        resolveMatch(match.id, { resolution: 'void' });
      }
    });
  }

  return row;
}

async function resolveMatch(matchId, payload) {
  const { status, data } = await postJSON('/api/matches/resolve.php', {
    match_id: matchId,
    ...payload,
  });

  if (status !== 200 || !data.success) {
    alert(data.error || 'Could not resolve this match.');
    return;
  }

  await loadAll();
}

// ------------------------------------------------------------
// Disputes + Matches sections
// ------------------------------------------------------------
async function loadMatches() {
  const { status, data } = await getJSON(`/api/matches/list.php?tournament_id=${encodeURIComponent(tournamentId)}`);
  if (status !== 200 || !data.success) return;

  const disputesList = document.getElementById('disputes-list');
  const roundsList = document.getElementById('rounds-list');
  disputesList.innerHTML = '';
  roundsList.innerHTML = '';

  const disputedMatches = [];

  data.rounds.forEach((roundGroup) => {
    const roundEl = document.createElement('div');
    roundEl.className = 'round-group';
    roundEl.innerHTML = `<h3 class="round-group-title">Round ${roundGroup.round}</h3>`;

    roundGroup.matches.forEach((match) => {
      if (match.status === 'disputed') {
        disputedMatches.push(match);
      }
      roundEl.appendChild(buildMatchRow(match));
    });

    roundsList.appendChild(roundEl);
  });

  if (disputedMatches.length === 0) {
    disputesList.innerHTML = '<p class="no-disputes">No disputed matches right now.</p>';
  } else {
    disputedMatches.forEach((match) => {
      const row = buildMatchRow(match);
      row.classList.add('dispute-card');
      disputesList.appendChild(row);
    });
  }
}

// ------------------------------------------------------------
// Participants section
// ------------------------------------------------------------
function participantActionButtons(participant) {
  if (participant.status === 'active') {
    return `
      <button type="button" class="btn btn-ghost btn-sm" data-action="disqualified" data-id="${participant.id}">Disqualify</button>
      <button type="button" class="btn btn-danger btn-sm" data-action="withdrawn" data-id="${participant.id}">Remove</button>
    `;
  }
  return `<button type="button" class="btn btn-ghost btn-sm" data-action="active" data-id="${participant.id}">Reinstate</button>`;
}

async function loadParticipants() {
  const { status, data } = await getJSON(`/api/participants/list.php?tournament_id=${encodeURIComponent(tournamentId)}`);
  if (status !== 200 || !data.success) return;

  const tbody = document.getElementById('participants-tbody');
  tbody.innerHTML = '';

  data.participants.forEach((p) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${escapeHtml(p.username)}</td>
      <td><span class="status-badge status-${p.status === 'active' ? 'in_progress' : 'cancelled'}">${PARTICIPANT_STATUS_LABELS[p.status] || p.status}</span></td>
      <td>${p.missed_match_count}</td>
      <td>${p.joined_at}</td>
      <td class="actions">${participantActionButtons(p)}</td>
    `;
    tbody.appendChild(tr);
  });

  tbody.querySelectorAll('button[data-action]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const { status: reqStatus, data: reqData } = await postJSON('/api/participants/update.php', {
        participant_id: Number(btn.dataset.id),
        status: btn.dataset.action,
      });

      if (reqStatus !== 200 || !reqData.success) {
        alert(reqData.error || 'Could not update this participant.');
        return;
      }

      await loadParticipants();
    });
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
// Load everything
// ------------------------------------------------------------
async function loadAll() {
  const { data } = await getJSON(`/api/tournaments/get.php?id=${encodeURIComponent(tournamentId)}`);
  if (data.success) {
    renderOverview(data.tournament);
  }
  await Promise.all([loadMatches(), loadParticipants()]);
}

document.addEventListener('DOMContentLoaded', async () => {
  const context = await requireHostOwner();
  if (!context) return;

  document.getElementById('username-display').textContent = context.user.username;
  renderOverview(context.tournament);

  initCopyJoinCode();
  initGenerateFixtures();
  initLogout();

  await Promise.all([loadMatches(), loadParticipants()]);
});
