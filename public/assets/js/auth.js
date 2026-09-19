/**
 * auth.js
 *
 * Wires up the auth.html tabs and forms to /api/auth/register.php
 * and /api/auth/login.php. Client-side checks mirror the server's
 * rules for instant feedback, but the server response is always
 * the source of truth for what actually gets accepted.
 */

const ROLE_REDIRECTS = {
  host: 'host-dashboard.html',
  player: 'player-dashboard.html',
  both: 'dashboard-select.html',
};

/**
 * If the person arrived here from a tournament page's join box while
 * logged out (?join_code=...&return=...), join that tournament right
 * after auth succeeds, then send them back instead of the default
 * dashboard. Falls back to the normal role-based redirect otherwise.
 */
async function handlePostAuthRedirect(role) {
  const params = new URLSearchParams(window.location.search);
  const joinCode = params.get('join_code');
  const returnUrl = params.get('return');

  if (joinCode) {
    try {
      await postJSON('/api/tournaments/join.php', { join_code: joinCode });
    } catch (err) {
      // Join failed (already joined, tournament closed, etc.) — still
      // send them back to the tournament page, which will show why.
    }
    window.location.href = returnUrl || (ROLE_REDIRECTS[role] || 'player-dashboard.html');
    return;
  }

  window.location.href = ROLE_REDIRECTS[role] || 'player-dashboard.html';
}

function initTabs() {
  const tabButtons = document.querySelectorAll('.tab-btn');
  const forms = {
    login: document.getElementById('login-form'),
    register: document.getElementById('register-form'),
  };

  tabButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.dataset.tab;

      tabButtons.forEach((btn) => {
        const isActive = btn === button;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', String(isActive));
      });

      Object.entries(forms).forEach(([name, form]) => {
        form.hidden = name !== target;
      });
    });
  });
}

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

function setButtonLoading(button, isLoading, loadingText, defaultText) {
  button.disabled = isLoading;
  button.textContent = isLoading ? loadingText : defaultText;
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

function initLoginForm() {
  const form = document.getElementById('login-form');
  const alertEl = document.getElementById('login-alert');
  const submitBtn = form.querySelector('button[type="submit"]');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setAlert(alertEl, null);

    const email = form.email.value.trim();
    const password = form.password.value;

    setButtonLoading(submitBtn, true, 'Logging in…', 'Log in');

    try {
      const { status, data } = await postJSON('/api/auth/login.php', { email, password });

      if (status !== 200 || !data.success) {
        setAlert(alertEl, data.error || 'Something went wrong. Please try again.');
        return;
      }

      await handlePostAuthRedirect(data.user.role);
    } catch (err) {
      setAlert(alertEl, 'Could not reach the server. Check your connection and try again.');
    } finally {
      setButtonLoading(submitBtn, false, 'Logging in…', 'Log in');
    }
  });
}

function initRegisterForm() {
  const form = document.getElementById('register-form');
  const alertEl = document.getElementById('register-alert');
  const submitBtn = form.querySelector('button[type="submit"]');
  const fieldErrorIds = ['username-error', 'email-error', 'password-error', 'role-error'];

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setAlert(alertEl, null);
    clearFieldErrors(fieldErrorIds);

    const username = form.username.value.trim();
    const email = form.email.value.trim();
    const password = form.password.value;
    const roleInput = form.querySelector('input[name="role"]:checked');
    const role = roleInput ? roleInput.value : '';

    if (!role) {
      setFieldError('role-error', 'Choose how you plan to use the platform.');
      return;
    }

    setButtonLoading(submitBtn, true, 'Creating account…', 'Create account');

    try {
      const { status, data } = await postJSON('/api/auth/register.php', {
        username,
        email,
        password,
        role,
      });

      if (status === 422 && data.errors) {
        if (data.errors.username) setFieldError('username-error', data.errors.username);
        if (data.errors.email) setFieldError('email-error', data.errors.email);
        if (data.errors.password) setFieldError('password-error', data.errors.password);
        if (data.errors.role) setFieldError('role-error', data.errors.role);
        return;
      }

      if (status !== 201 || !data.success) {
        setAlert(alertEl, data.error || 'Something went wrong. Please try again.');
        return;
      }

      await handlePostAuthRedirect(data.user.role);
    } catch (err) {
      setAlert(alertEl, 'Could not reach the server. Check your connection and try again.');
    } finally {
      setButtonLoading(submitBtn, false, 'Creating account…', 'Create account');
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initTabs();
  initLoginForm();
  initRegisterForm();
});
