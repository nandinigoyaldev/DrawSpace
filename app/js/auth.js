/** Sign-in / registration page controller. */

import { api, setCsrfToken, safeNextPath } from './api.js';
import { toast, setButtonBusy } from './ui.js';

const nextPath = safeNextPath(new URLSearchParams(location.search).get('next'));

const tabs = {
  login: document.getElementById('tab-login'),
  register: document.getElementById('tab-register'),
};

const panels = {
  login: document.getElementById('panel-login'),
  register: document.getElementById('panel-register'),
};

const cardTitle = document.getElementById('card-title');
const cardSubtitle = document.getElementById('card-subtitle');

const loginForm = document.getElementById('login-form');
const registerForm = document.getElementById('register-form');
const registerPassword = registerForm.querySelector('input[name="password"]');
const strength = document.getElementById('strength');
const strengthLabel = document.getElementById('strength-label');

let activeTab = 'login';

/* ────────────────────────────────── Tabs ─────────────────────────────────── */

function showTab(name, { focus = false } = {}) {
  activeTab = name === 'register' ? 'register' : 'login';

  for (const [key, tab] of Object.entries(tabs)) {
    tab.setAttribute('aria-selected', String(key === activeTab));
  }

  panels.login.hidden = activeTab !== 'login';
  panels.register.hidden = activeTab !== 'register';

  cardTitle.textContent = activeTab === 'login' ? 'Welcome back' : 'Create your account';
  cardSubtitle.textContent = activeTab === 'login'
    ? 'Pick up your board where you left off.'
    : 'Your first board is ready the moment you sign up.';

  for (const form of [loginForm, registerForm]) {
    clearError(form);
  }

  if (activeTab === 'register') {
    updateStrength();
  }

  if (focus) {
    panels[activeTab].querySelector('input')?.focus();
  }
}

for (const [name, tab] of Object.entries(tabs)) {
  tab.addEventListener('click', () => showTab(name));
}

document.querySelectorAll('[data-tab-target]').forEach((button) => {
  button.addEventListener('click', () => showTab(button.dataset.tabTarget, { focus: true }));
});

document.querySelector('.tabs')?.addEventListener('keydown', (event) => {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
    return;
  }

  event.preventDefault();
  const target = activeTab === 'login' ? 'register' : 'login';
  showTab(target, { focus: true });
});

/* ─────────────────────────────── Password toggles ────────────────────────── */

document.querySelectorAll('[data-toggle-password]').forEach((button) => {
  button.addEventListener('click', () => {
    const input = button.closest('.password-wrap')?.querySelector('input');

    if (!input) {
      return;
    }

    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.textContent = reveal ? 'Hide' : 'Show';
    button.setAttribute('aria-pressed', String(reveal));
    button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    input.focus({ preventScroll: true });
  });
});

/* ────────────────────────────── Password strength ────────────────────────── */

function scorePassword(password) {
  if (!password) {
    return 0;
  }

  let score = 0;

  if (password.length >= 10) score += 1;
  if (password.length >= 14) score += 1;
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score += 1;
  if (/\d/.test(password) && /[^a-zA-Z0-9]/.test(password)) score += 1;

  return Math.min(4, score);
}

function updateStrength() {
  const score = scorePassword(registerPassword?.value || '');
  const labels = ['Weak', 'Weak', 'Fair', 'Good', 'Strong'];

  if (strength) {
    strength.dataset.score = String(score);
  }

  if (strengthLabel) {
    strengthLabel.textContent = labels[score];
  }
}

registerPassword?.addEventListener('input', updateStrength);

/* ─────────────────────────────── Form helpers ────────────────────────────── */

function errorBox(form) {
  return form.querySelector('.form-error');
}

function showError(form, message) {
  const box = errorBox(form);

  if (!box) {
    return;
  }

  box.textContent = message;
  box.hidden = false;
}

function clearError(form) {
  const box = errorBox(form);

  if (box) {
    box.hidden = true;
    box.textContent = '';
  }

  form.querySelectorAll('[aria-invalid="true"]').forEach((input) => {
    input.removeAttribute('aria-invalid');
  });
}

function invalidate(input, message) {
  input.setAttribute('aria-invalid', 'true');
  input.focus({ preventScroll: true });
  input.select?.();
  showError(input.form, message);
}

/* ────────────────────────────────── Submit ───────────────────────────────── */

loginForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearError(loginForm);

  const usernameInput = loginForm.elements.namedItem('username');
  const passwordInput = loginForm.elements.namedItem('password');
  const username = usernameInput.value.trim();
  const password = passwordInput.value;

  if (!username) {
    invalidate(usernameInput, 'Enter your username.');
    return;
  }

  if (!password) {
    invalidate(passwordInput, 'Enter your password.');
    return;
  }

  const button = loginForm.querySelector('button[type="submit"]');
  setButtonBusy(button, true, 'Signing in…');

  try {
    const result = await api('/api/login', {
      method: 'POST',
      body: { username, password },
    });

    setCsrfToken(result.csrfToken);
    location.replace(nextPath);
  } catch (error) {
    setButtonBusy(button, false);
    showError(loginForm, error.message || 'Could not sign in. Please try again.');

    if (error.status === 429) {
      toast('Too many attempts. Please wait a few minutes.', 'warning');
    }
  }
});

registerForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearError(registerForm);

  const usernameInput = registerForm.elements.namedItem('username');
  const passwordInput = registerForm.elements.namedItem('password');
  const confirmInput = registerForm.elements.namedItem('confirm');
  const username = usernameInput.value.trim();
  const password = passwordInput.value;
  const confirm = confirmInput.value;

  if (!/^[a-zA-Z0-9_]{3,20}$/.test(username)) {
    invalidate(usernameInput, 'Use 3–20 letters, numbers or underscores.');
    return;
  }

  if (password.length < 10) {
    invalidate(passwordInput, 'Passwords need at least 10 characters.');
    return;
  }

  if (password !== confirm) {
    invalidate(confirmInput, 'Those passwords do not match.');
    return;
  }

  const button = registerForm.querySelector('button[type="submit"]');
  setButtonBusy(button, true, 'Creating account…');

  try {
    const result = await api('/api/register', {
      method: 'POST',
      body: { username, password },
    });

    setCsrfToken(result.csrfToken);
    location.replace(nextPath);
  } catch (error) {
    setButtonBusy(button, false);
    showError(registerForm, error.message || 'Could not create your account. Please try again.');

    if (error.status === 409) {
      usernameInput.focus({ preventScroll: true });
    }

    if (error.status === 429) {
      toast('Too many sign-ups from this network. Try again later.', 'warning');
    }
  }
});

for (const form of [loginForm, registerForm]) {
  form.addEventListener('input', () => clearError(form));
}

/* ──────────────────────────────── Boot ───────────────────────────────────── */

if (location.hash === '#register') {
  showTab('register');
} else {
  showTab('login');
}

api('/api/me')
  .then((data) => {
    setCsrfToken(data.csrfToken);
    location.replace(nextPath);
  })
  .catch((error) => {
    // 401 is expected for signed-out visitors.
    if (error.status === 0) {
      toast('Cannot reach the server right now. Please refresh.', 'error', 6000);
    }
  });
