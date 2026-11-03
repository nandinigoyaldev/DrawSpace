import { api } from './api.js';
import { setButtonBusy } from './ui.js';

const requestForm = document.querySelector('[data-request-form]');
const resetForm = document.querySelector('[data-reset-form]');
const status = document.getElementById('reset-status');
const statusText = status?.querySelector('[data-status-text]');
const subtitle = document.querySelector('[data-reset-subtitle]');
const token = new URLSearchParams(location.search).get('token') || '';

function updateStatus(message, state = 'ready') {
  if (statusText) statusText.textContent = message;
  status?.setAttribute('data-state', state);
}

function errorBox(form) {
  return form.querySelector('.form-error');
}

function clearError(form) {
  const box = errorBox(form);
  if (box) {
    box.hidden = true;
    box.textContent = '';
  }
}

function showError(form, message) {
  const box = errorBox(form);
  if (box) {
    box.hidden = false;
    box.textContent = message;
  }
}

function showResetForm() {
  requestForm.hidden = true;
  resetForm.hidden = false;
  resetForm.elements.namedItem('token').value = token;
  subtitle.textContent = 'Choose a new password for your DrawSpace account.';
  updateStatus('Your link is ready. Make it memorable and strong.');
  resetForm.elements.namedItem('password').focus({ preventScroll: true });
}

document.querySelectorAll('[data-toggle-password]').forEach((button) => {
  button.addEventListener('click', () => {
    const input = button.closest('.password-wrap')?.querySelector('input');
    if (!input) return;
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.textContent = reveal ? 'Hide' : 'Show';
    button.setAttribute('aria-pressed', String(reveal));
    button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
  });
});

requestForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearError(requestForm);
  const email = requestForm.elements.namedItem('email').value.trim();
  if (!email || !email.includes('@')) {
    showError(requestForm, 'Enter the email you used for your account.');
    requestForm.elements.namedItem('email').focus({ preventScroll: true });
    return;
  }

  const button = requestForm.querySelector('button[type="submit"]');
  setButtonBusy(button, true, 'Sending…');
  updateStatus('Preparing your private reset link…', 'busy');

  try {
    const result = await api('/api/request-reset', { method: 'POST', body: { email } });
    requestForm.hidden = true;
    subtitle.textContent = 'Check your inbox, then open the link in that email.';
    updateStatus(result.message || 'If the email matches, a reset link is on its way.', 'ready');
  } catch (error) {
    setButtonBusy(button, false);
    showError(requestForm, error.message || 'Could not send the reset email. Please try again.');
    updateStatus('We could not send that email yet.', 'error');
  }
});

resetForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearError(resetForm);
  const password = resetForm.elements.namedItem('password').value;
  const confirm = resetForm.elements.namedItem('confirm').value;
  if (password.length < 10) {
    showError(resetForm, 'Passwords need at least 10 characters.');
    return;
  }
  if (password !== confirm) {
    showError(resetForm, 'Those passwords do not match.');
    return;
  }

  const button = resetForm.querySelector('button[type="submit"]');
  setButtonBusy(button, true, 'Saving…');
  updateStatus('Securing your account…', 'busy');
  try {
    const result = await api('/api/reset-password', { method: 'POST', body: { token, password } });
    resetForm.hidden = true;
    subtitle.textContent = result.message || 'Your password was reset successfully.';
    updateStatus('All set. Redirecting you to sign in…', 'ready');
    window.setTimeout(() => location.replace('/login'), 700);
  } catch (error) {
    setButtonBusy(button, false);
    showError(resetForm, error.message || 'That reset link is invalid or expired.');
    updateStatus('This link needs attention.', 'error');
  }
});

if (token) {
  showResetForm();
}
