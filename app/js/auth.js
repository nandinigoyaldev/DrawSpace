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
const authStatus = document.getElementById('auth-status');
const statusText = authStatus?.querySelector('[data-status-text]');
const rememberedUser = document.querySelector('[data-remembered-user]');
const registerUsername = registerForm.elements.namedItem('username');
const usernameHint = registerForm.querySelector('[data-username-hint]');
const confirmPassword = registerForm.elements.namedItem('confirm');
const matchHint = registerForm.querySelector('[data-match-hint]');
const passwordRules = registerForm.querySelectorAll('[data-rule]');

let activeTab = 'login';
let loginLockedUntil = 0;
const rememberedUsernameKey = 'drawspace_last_username';

function isSubmitting(form) {
  if (form.dataset.submitting === 'true') {
    return true;
  }
  form.dataset.submitting = 'true';
  return false;
}

function finishSubmitting(form) {
  form.dataset.submitting = 'false';
}

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

  updateStatus(navigator.onLine ? 'Ready when you are.' : 'You are offline. We will retry when you reconnect.');
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

function updateStatus(message, state = 'ready') {
  if (statusText) {
    statusText.textContent = message;
  }
  authStatus?.setAttribute('data-state', state);
}

function savedUsername() {
  try {
    return localStorage.getItem(rememberedUsernameKey) || '';
  } catch {
    return '';
  }
}

function rememberUsername(username) {
  try {
    localStorage.setItem(rememberedUsernameKey, username);
  } catch {
    // Storage is optional; authentication must still work.
  }
}

function clearRememberedUsername() {
  try {
    localStorage.removeItem(rememberedUsernameKey);
  } catch {
    // Storage is optional; authentication must still work.
  }
}

const lastUsername = savedUsername();
const loginUsername = loginForm.elements.namedItem('username');
if (lastUsername && loginUsername && rememberedUser) {
  loginUsername.value = lastUsername;
  rememberedUser.textContent = `Using ${lastUsername} · `;
  const forget = document.createElement('button');
  forget.type = 'button';
  forget.className = 'link';
  forget.textContent = 'forget';
  forget.addEventListener('click', () => {
    clearRememberedUsername();
    loginUsername.value = '';
    rememberedUser.hidden = true;
    loginUsername.focus({ preventScroll: true });
  });
  rememberedUser.append(forget);
  rememberedUser.hidden = false;
}

window.addEventListener('online', () => updateStatus('Back online. Ready when you are.'));
window.addEventListener('offline', () => updateStatus('You are offline. Check your connection before signing in.', 'offline'));

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

document.querySelector('[data-forgot-password]')?.addEventListener('click', () => {
  showError(loginForm, 'Password recovery is not available yet. Please create a new account or contact the board owner.');
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

  const password = registerPassword?.value || '';
  const checks = {
    length: password.length >= 10,
    mixed: /[a-z]/.test(password) && /[A-Z]/.test(password),
    variety: /\d/.test(password) && /[^a-zA-Z0-9]/.test(password),
  };
  passwordRules.forEach((rule) => {
    rule.dataset.valid = String(checks[rule.dataset.rule]);
  });
}

registerPassword?.addEventListener('input', updateStrength);
confirmPassword?.addEventListener('input', updatePasswordMatch);

function updatePasswordMatch() {
  if (!matchHint) {
    return;
  }
  if (!confirmPassword.value) {
    matchHint.textContent = '';
    return;
  }
  const matches = confirmPassword.value === registerPassword.value;
  matchHint.textContent = matches ? 'Passwords match.' : 'Passwords do not match yet.';
  matchHint.dataset.valid = String(matches);
}

document.querySelector('[data-generate-password]')?.addEventListener('click', () => {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*';
  const values = new Uint32Array(16);
  crypto.getRandomValues(values);
  const generated = Array.from(values, (value) => alphabet[value % alphabet.length]).join('');
  registerPassword.value = generated;
  confirmPassword.value = generated;
  registerPassword.type = 'text';
  confirmPassword.type = 'text';
  document.querySelectorAll('#panel-register [data-toggle-password]').forEach((button) => {
    button.textContent = 'Hide';
    button.setAttribute('aria-pressed', 'true');
    button.setAttribute('aria-label', 'Hide password');
  });
  updateStrength();
  updatePasswordMatch();
  registerPassword.focus({ preventScroll: true });
});

registerUsername?.addEventListener('input', () => {
  const username = registerUsername.value.trim();
  const valid = /^[a-zA-Z0-9_]{3,20}$/.test(username);
  usernameHint.textContent = username === ''
    ? '3–20 letters, numbers or underscores.'
    : valid ? 'Username format looks good.' : 'Use only 3–20 letters, numbers or underscores.';
  registerUsername.dataset.valid = String(valid);
});

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

  if (Date.now() < loginLockedUntil) {
    const seconds = Math.ceil((loginLockedUntil - Date.now()) / 1000);
    showError(loginForm, `Too many attempts. Try again in ${seconds} seconds.`);
    return;
  }

  if (!username) {
    invalidate(usernameInput, 'Enter your username.');
    return;
  }

  if (!password) {
    invalidate(passwordInput, 'Enter your password.');
    return;
  }

  if (isSubmitting(loginForm)) {
    return;
  }

  const button = loginForm.querySelector('button[type="submit"]');
  setButtonBusy(button, true, 'Signing in…');
  updateStatus('Checking your account…', 'busy');
  rememberUsername(username);

  try {
    const result = await api('/api/login', {
      method: 'POST',
      body: { username, password },
    });

    setCsrfToken(result.csrfToken);
    location.replace(nextPath);
  } catch (error) {
    setButtonBusy(button, false);
    finishSubmitting(loginForm);
    showError(loginForm, error.message || 'Could not sign in. Please try again.');
    updateStatus(error.status === 0 ? 'DrawSpace could not be reached.' : 'We could not sign you in yet.', 'error');

    if (error.status === 429) {
      toast('Too many attempts. Please wait a few minutes.', 'warning');
      loginLockedUntil = Date.now() + 60_000;
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

  if (scorePassword(password) < 3) {
    invalidate(passwordInput, 'Choose a stronger password with mixed case, a number and a symbol.');
    return;
  }

  if (password !== confirm) {
    invalidate(confirmInput, 'Those passwords do not match.');
    return;
  }

  if (isSubmitting(registerForm)) {
    return;
  }

  const button = registerForm.querySelector('button[type="submit"]');
  setButtonBusy(button, true, 'Creating account…');
  updateStatus('Setting up your first board…', 'busy');

  try {
    const result = await api('/api/register', {
      method: 'POST',
      body: { username, password },
    });

    setCsrfToken(result.csrfToken);
    location.replace(nextPath);
  } catch (error) {
    setButtonBusy(button, false);
    finishSubmitting(registerForm);
    showError(registerForm, error.message || 'Could not create your account. Please try again.');
    updateStatus(error.status === 0 ? 'DrawSpace could not be reached.' : 'We could not create your account yet.', 'error');

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

updateStrength();
updatePasswordMatch();

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
