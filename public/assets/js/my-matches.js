/**
 * my-matches.js
 *
 * Fetches every match the logged-in player is part of, splits them
 * into "Today", "Needs your attention", and everything else, and
 * wires up result submission (with required proof screenshot),
 * confirmation, and dispute actions.
 */

const MATCH_STATUS_LABELS = {
  pending: 'Pending',
  awaiting_confirmation: 'Awaiting confirmation',
  disputed: 'Disputed',
  completed: 'Completed',
  forfeited: 'Walkover',
  void: 'Void',
};

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

async function postFormData(url, formData) {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    body: formData,
  });
  const data = await response.json();
  return { status: response.status, data };
}

function isToday(dateString) {
  if (!dateString) return false;
  const today = new Date();
  const target = new Date(dateString);
  return (
    today.getFullYear() === target.getFullYear() &&
    today.getMonth() === target.getMonth() &&
    today.getDate() === target.getDate()
  );
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
// Card rendering
// ------------------------------------------------------------
function needsMyAction(match) {
  if (!match.opponent) return false;
  if (['completed', 'forfeited', 'void', 'disputed'].includes(match.status)) return false;

  if (match.pending_submission) {
    return !match.pending_submission.submitted_by_is_me;
  }

  return match.status === 'pending';
}

function buildResolvedBody(match) {
  const wrap = document.createElement('div');

  if (match.status === 'disputed') {
    wrap.innerHTML = `<p class="match-status-note">This match is disputed and awaiting your host's review.</p>`;
    return wrap;
  }

  if (!match.opponent) {
    wrap.innerHTML = `<p class="match-status-note">Waiting for your opponent to be determined.</p>`;
    return wrap;
  }

  // completed / forfeited / void
  const myScore = match.is_player1 ? match.score_player1 : match.score_player2;
  const oppScore = match.is_player1 ? match.score_player2 : match.score_player1;

  if (match.status === 'void') {
    wrap.innerHTML = `<p class="match-status-note">This match was voided \u2014 no points awarded.</p>`;
  } else if (myScore !== null && oppScore !== null) {
    const resultWord = myScore > oppScore ? 'won' : myScore < oppScore ? 'lost' : 'drew';
    wrap.innerHTML = `<p class="pending-score-display">${myScore} \u2013 ${oppScore}</p><p class="match-status-note">You <strong>${resultWord}</strong> this match${match.resolution_type === 'walkover' ? ' (walkover)' : ''}.</p>`;
  } else {
    wrap.innerHTML = `<p class="match-status-note">Result resolved by your host.</p>`;
  }

  return wrap;
}

function buildWaitingBody(match) {
  const wrap = document.createElement('div');
  const s = match.pending_submission;
  wrap.innerHTML = `
    <p class="pending-score-display">${s.score_player1} \u2013 ${s.score_player2}</p>
    <p class="match-status-note">You submitted this result \u2014 waiting for <strong>${escapeHtml(match.opponent.username)}</strong> to confirm.</p>
  `;
  return wrap;
}

function buildConfirmDisputeBody(match, onDone) {
  const wrap = document.createElement('div');
  const s = match.pending_submission;

  wrap.innerHTML = `
    <p class="pending-score-display">${s.score_player1} \u2013 ${s.score_player2}</p>
    <p class="match-status-note"><strong>${escapeHtml(match.opponent.username)}</strong> submitted this result. Confirm if it's correct, or dispute it.</p>
  `;

  const actionRow = document.createElement('div');
  actionRow.className = 'confirm-dispute-row';
  actionRow.style.marginTop = 'var(--space-3)';

  const confirmBtn = document.createElement('button');
  confirmBtn.type = 'button';
  confirmBtn.className = 'btn btn-primary';
  confirmBtn.textContent = 'Confirm';

  const disputeBtn = document.createElement('button');
  disputeBtn.type = 'button';
  disputeBtn.className = 'btn btn-danger';
  disputeBtn.textContent = 'Dispute';

  confirmBtn.addEventListener('click', async () => {
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Confirming…';
    const { status, data } = await postJSON('/api/results/confirm.php', { match_id: match.id });
    if (status !== 200 || !data.success) {
      alert(data.error || 'Could not confirm this result.');
      confirmBtn.disabled = false;
      confirmBtn.textContent = 'Confirm';
      return;
    }
    onDone();
  });

  disputeBtn.addEventListener('click', async () => {
    if (!confirm('Dispute this result? Your host will need to resolve it manually.')) return;
    disputeBtn.disabled = true;
    disputeBtn.textContent = 'Disputing…';
    const { status, data } = await postJSON('/api/results/dispute.php', { match_id: match.id });
    if (status !== 200 || !data.success) {
      alert(data.error || 'Could not dispute this result.');
      disputeBtn.disabled = false;
      disputeBtn.textContent = 'Dispute';
      return;
    }
    onDone();
  });

  actionRow.appendChild(confirmBtn);
  actionRow.appendChild(disputeBtn);
  wrap.appendChild(actionRow);

  return wrap;
}

function buildSubmitBody(match, onDone) {
  const wrap = document.createElement('div');

  const submitRow = document.createElement('div');
  submitRow.className = 'submit-row';

  const score1 = document.createElement('input');
  score1.type = 'number';
  score1.min = '0';
  score1.setAttribute('aria-label', 'Your score');
  score1.placeholder = 'You';

  const dash = document.createElement('span');
  dash.textContent = '\u2013';

  const score2 = document.createElement('input');
  score2.type = 'number';
  score2.min = '0';
  score2.setAttribute('aria-label', "Opponent's score");
  score2.placeholder = 'Opp';

  submitRow.appendChild(score1);
  submitRow.appendChild(dash);
  submitRow.appendChild(score2);

  const fileWrap = document.createElement('div');
  fileWrap.className = 'file-input-wrap';
  fileWrap.innerHTML = `<label>Proof screenshot (required)</label>`;
  const fileInput = document.createElement('input');
  fileInput.type = 'file';
  fileInput.accept = 'image/jpeg,image/png,image/webp';
  fileWrap.appendChild(fileInput);

  const errorEl = document.createElement('p');
  errorEl.className = 'field-error';
  errorEl.hidden = true;

  const submitBtn = document.createElement('button');
  submitBtn.type = 'button';
  submitBtn.className = 'btn btn-primary';
  submitBtn.textContent = 'Submit result';

  submitBtn.addEventListener('click', async () => {
    errorEl.hidden = true;

    const s1 = Number(score1.value);
    const s2 = Number(score2.value);

    if (score1.value === '' || score2.value === '' || !Number.isInteger(s1) || !Number.isInteger(s2) || s1 < 0 || s2 < 0) {
      errorEl.hidden = false;
      errorEl.textContent = 'Enter a valid score for both sides.';
      return;
    }

    if (!fileInput.files || fileInput.files.length === 0) {
      errorEl.hidden = false;
      errorEl.textContent = 'A proof screenshot is required.';
      return;
    }

    // The endpoint expects score1/score2 mapped to player1/player2,
    // not "me/opponent" — translate based on which slot I'm in.
    const mappedScore1 = match.is_player1 ? s1 : s2;
    const mappedScore2 = match.is_player1 ? s2 : s1;

    const formData = new FormData();
    formData.append('match_id', String(match.id));
    formData.append('score1', String(mappedScore1));
    formData.append('score2', String(mappedScore2));
    formData.append('proof', fileInput.files[0]);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting…';

    const { status, data } = await postFormData('/api/results/submit.php', formData);

    if (status !== 201 || !data.success) {
      errorEl.hidden = false;
      errorEl.textContent = data.error || 'Could not submit this result.';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit result';
      return;
    }

    onDone();
  });

  wrap.appendChild(submitRow);
  wrap.appendChild(fileWrap);
  wrap.appendChild(errorEl);
  wrap.appendChild(submitBtn);

  return wrap;
}

function buildMatchCard(match, onDone) {
  const template = document.getElementById('match-card-template');
  const node = template.content.cloneNode(true);

  node.querySelector('.match-card-tournament').textContent = `${match.tournament.name} \u00b7 Round ${match.round}`;
  node.querySelector('.me-name').textContent = match.me.username;
  node.querySelector('.opponent-name').textContent = match.opponent ? match.opponent.username : 'TBD';

  const statusBadge = node.querySelector('.match-status-badge');
  statusBadge.textContent = MATCH_STATUS_LABELS[match.status] || match.status;
  statusBadge.classList.add(
    match.status === 'disputed' ? 'status-cancelled' : match.status === 'completed' ? 'status-in_progress' : 'status-draft'
  );

  if (match.scheduled_deadline) {
    node.querySelector('.match-card-deadline').textContent = `Due ${match.scheduled_deadline}`;
  }

  const body = node.querySelector('.match-card-body');

  if (!match.opponent || ['completed', 'forfeited', 'void', 'disputed'].includes(match.status)) {
    body.appendChild(buildResolvedBody(match));
  } else if (match.pending_submission) {
    body.appendChild(
      match.pending_submission.submitted_by_is_me
        ? buildWaitingBody(match)
        : buildConfirmDisputeBody(match, onDone)
    );
  } else if (match.status === 'pending') {
    body.appendChild(buildSubmitBody(match, onDone));
  } else {
    body.innerHTML = `<p class="match-status-note">No action needed right now.</p>`;
  }

  return node;
}

// ------------------------------------------------------------
// Load + categorize
// ------------------------------------------------------------
async function loadMatches() {
  const { status, data } = await getJSON('/api/matches/my-matches.php');
  if (status !== 200 || !data.success) return;

  const todayEl = document.getElementById('today-matches');
  const actionEl = document.getElementById('action-matches');
  const otherEl = document.getElementById('other-matches');

  todayEl.innerHTML = '';
  actionEl.innerHTML = '';
  otherEl.innerHTML = '';

  const today = data.matches.filter((m) => isToday(m.scheduled_deadline));
  const needsAction = data.matches.filter(needsMyAction);
  const other = data.matches.filter((m) => !isToday(m.scheduled_deadline) && !needsMyAction(m));

  const render = (list, container, emptyText) => {
    if (list.length === 0) {
      container.innerHTML = `<p class="empty-matches">${emptyText}</p>`;
      return;
    }
    list.forEach((match) => {
      container.appendChild(buildMatchCard(match, loadMatches));
    });
  };

  render(today, todayEl, 'Nothing due today.');
  render(needsAction, actionEl, "You're all caught up \u2014 nothing needs your input right now.");
  render(other, otherEl, 'No other matches.');
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

  initLogout();
  await loadMatches();
});
