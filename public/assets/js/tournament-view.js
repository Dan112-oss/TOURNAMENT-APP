/**
 * tournament-view.js
 *
 * Public page — no login required to view. Renders a standings
 * table + fixture list for round_robin tournaments, or a bracket
 * tree for knockout tournaments. Polls every 15s for live updates.
 */

const STATUS_LABELS = {
  draft: 'Draft',
  open_for_registration: 'Open for registration',
  in_progress: 'In progress',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

const POLL_INTERVAL_MS = 15000;

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
// Topbar — adapts to logged-in / logged-out, doesn't block the page
// ------------------------------------------------------------
async function renderTopbar() {
  const area = document.getElementById('topbar-auth-area');
  const { status, data } = await getJSON('/api/auth/me.php');

  if (status === 200 && data.success) {
    const dashboardHref = data.user.role === 'player' ? 'player-dashboard.html' : 'host-dashboard.html';
    area.innerHTML = `
      <span class="dashboard-username">${escapeHtml(data.user.username)}</span>
      <a class="btn btn-ghost" href="${dashboardHref}">Dashboard</a>
    `;
  } else {
    area.innerHTML = `<a class="btn btn-ghost" href="auth.html">Log in</a>`;
  }
}

// ------------------------------------------------------------
// Header
// ------------------------------------------------------------
let currentFormat = null;

function renderHeader(tournament) {
  currentFormat = tournament.format;

  document.getElementById('tournament-name').textContent = tournament.name;

  const statusBadge = document.getElementById('tournament-status-badge');
  statusBadge.textContent = STATUS_LABELS[tournament.status] || tournament.status;
  statusBadge.className = `status-badge status-${tournament.status}`;

  document.getElementById('tournament-format-label').textContent =
    tournament.format === 'knockout' ? 'Knockout' : 'Round-robin';
  document.getElementById('tournament-game-label').textContent = tournament.game_type;
  document.getElementById('tournament-host-label').textContent = `Hosted by ${tournament.host.username}`;

  document.getElementById('join-box').hidden = tournament.status !== 'open_for_registration';

  document.getElementById('standings-section').hidden = tournament.format !== 'round_robin';
  document.getElementById('rr-matches-section').hidden = tournament.format !== 'round_robin';
  document.getElementById('bracket-section').hidden = tournament.format !== 'knockout';
}

// ------------------------------------------------------------
// Join widget — logged-in users join directly; logged-out users
// are sent to auth.html with the code carried along, then joined
// automatically after login/register (see auth.js).
// ------------------------------------------------------------
function initJoinBox() {
  const input = document.getElementById('join-code-input');
  const btn = document.getElementById('join-btn');
  const errorEl = document.getElementById('join-error');

  btn.addEventListener('click', async () => {
    const code = input.value.trim();
    errorEl.hidden = true;

    if (!code) {
      errorEl.hidden = false;
      errorEl.textContent = 'Enter a join code.';
      return;
    }

    const { status: meStatus, data: meData } = await getJSON('/api/auth/me.php');

    if (meStatus !== 200 || !meData.success) {
      const returnUrl = `tournament-view.html?id=${encodeURIComponent(tournamentId)}`;
      window.location.href = `auth.html?join_code=${encodeURIComponent(code)}&return=${encodeURIComponent(returnUrl)}`;
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Joining…';

    try {
      const { status, data } = await postJSON('/api/tournaments/join.php', { join_code: code });

      if (status !== 201 || !data.success) {
        errorEl.hidden = false;
        errorEl.textContent = data.error || 'Could not join this tournament.';
        return;
      }

      window.location.href = 'player-dashboard.html';
    } finally {
      btn.disabled = false;
      btn.textContent = 'Join tournament';
    }
  });
}

// ------------------------------------------------------------
// Standings (round-robin)
// ------------------------------------------------------------
async function loadStandings() {
  const { status, data } = await getJSON(`/api/standings/get.php?tournament_id=${encodeURIComponent(tournamentId)}`);
  if (status !== 200 || !data.success) return;

  const tbody = document.getElementById('standings-tbody');
  tbody.innerHTML = '';

  data.standings.forEach((row) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="standings-position">${row.position}</td>
      <td>${escapeHtml(row.username)}</td>
      <td class="numeric">${row.played}</td>
      <td class="numeric">${row.wins}</td>
      <td class="numeric">${row.draws}</td>
      <td class="numeric">${row.losses}</td>
      <td class="numeric">${row.goals_for}</td>
      <td class="numeric">${row.goals_against}</td>
      <td class="numeric">${row.goal_difference}</td>
      <td class="numeric points-col">${row.points}</td>
    `;
    tbody.appendChild(tr);
  });
}

// ------------------------------------------------------------
// Matches — round-robin fixture list (view-only) or knockout bracket
// ------------------------------------------------------------
function renderRoundRobinMatches(rounds) {
  const container = document.getElementById('rr-rounds-list');
  container.innerHTML = '';

  rounds.forEach((roundGroup) => {
    const roundEl = document.createElement('div');
    roundEl.className = 'round-group';

    const title = document.createElement('h3');
    title.className = 'round-group-title';
    title.textContent = `Round ${roundGroup.round}`;
    roundEl.appendChild(title);

    roundGroup.matches.forEach((match) => {
      const row = document.createElement('div');
      row.className = 'card view-match-row';

      const p1 = match.player1 ? match.player1.username : 'TBD';
      const p2 = match.player2 ? match.player2.username : 'TBD';
      const score = match.score_player1 !== null ? `${match.score_player1} – ${match.score_player2}` : '';

      row.innerHTML = `
        <div class="view-match-players">
          <span>${escapeHtml(p1)}</span>
          <span class="vs">vs</span>
          <span>${escapeHtml(p2)}</span>
        </div>
        <span class="view-match-score">${score}</span>
      `;
      roundEl.appendChild(row);
    });

    container.appendChild(roundEl);
  });
}

function renderBracketSlot(player, score, isWinner) {
  if (!player) {
    return `<div class="bracket-slot"><span class="bracket-slot-name bracket-tbd">TBD</span></div>`;
  }
  return `
    <div class="bracket-slot ${isWinner ? 'winner' : ''}">
      <span class="bracket-slot-name">${escapeHtml(player.username)}</span>
      <span class="bracket-slot-score">${score !== null ? score : ''}</span>
    </div>
  `;
}

function renderBracket(rounds) {
  const bracket = document.getElementById('bracket');
  bracket.innerHTML = '';

  rounds.forEach((roundGroup) => {
    const roundEl = document.createElement('div');
    roundEl.className = 'bracket-round';

    const title = document.createElement('div');
    title.className = 'bracket-round-title';
    title.textContent = roundGroup.matches.length === 1 ? 'Final' : `Round ${roundGroup.round}`;
    roundEl.appendChild(title);

    roundGroup.matches.forEach((match) => {
      const matchEl = document.createElement('div');
      matchEl.className = 'bracket-match';

      const p1IsWinner = match.winner_id !== null && match.player1 && match.winner_id === match.player1.participant_id;
      const p2IsWinner = match.winner_id !== null && match.player2 && match.winner_id === match.player2.participant_id;

      matchEl.innerHTML =
        renderBracketSlot(match.player1, match.score_player1, p1IsWinner) +
        renderBracketSlot(match.player2, match.score_player2, p2IsWinner);

      roundEl.appendChild(matchEl);
    });

    bracket.appendChild(roundEl);
  });
}

async function loadMatches() {
  const { status, data } = await getJSON(`/api/matches/list.php?tournament_id=${encodeURIComponent(tournamentId)}`);
  if (status !== 200 || !data.success) return;

  if (currentFormat === 'round_robin') {
    renderRoundRobinMatches(data.rounds);
  } else {
    renderBracket(data.rounds);
  }
}

// ------------------------------------------------------------
// Load + poll
// ------------------------------------------------------------
async function loadHeaderAndData() {
  const { status, data } = await getJSON(`/api/tournaments/get.php?id=${encodeURIComponent(tournamentId)}`);
  if (status !== 200 || !data.success) {
    document.getElementById('tournament-name').textContent = 'Tournament not found';
    return;
  }

  renderHeader(data.tournament);

  if (data.tournament.format === 'round_robin') {
    await loadStandings();
  }
  await loadMatches();
}

async function pollLiveData() {
  if (currentFormat === 'round_robin') {
    await loadStandings();
  }
  await loadMatches();
}

document.addEventListener('DOMContentLoaded', async () => {
  if (!tournamentId) {
    document.getElementById('tournament-name').textContent = 'No tournament specified';
    return;
  }

  renderTopbar();
  initJoinBox();
  await loadHeaderAndData();

  setInterval(pollLiveData, POLL_INTERVAL_MS);
});
