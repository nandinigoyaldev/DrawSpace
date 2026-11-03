import { api, setCsrfToken } from './api.js';
import { setButtonBusy } from './ui.js';

const form = document.getElementById('recovery-form');
const signIn = document.getElementById('recovery-sign-in');
const status = document.getElementById('recovery-status');
const statusText = status.querySelector('[data-status-text]');

function updateStatus(message, state = 'ready') {
  statusText.textContent = message;
  status.dataset.state = state;
}

function showError(message) {
  const box = form.querySelector('.form-error');
  box.textContent = message;
  box.hidden = false;
}

api('/api/me')
  .then((data) => {
    setCsrfToken(data.csrfToken);
    form.hidden = false;
    updateStatus(`Signed in as ${data.user.username}.`);
    form.elements.namedItem('email').focus({ preventScroll: true });
  })
  .catch((error) => {
    updateStatus(error.status === 0 ? 'DrawSpace could not be reached.' : 'Sign in to update account recovery.', 'error');
    signIn.hidden = false;
  });

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  form.querySelector('.form-error').hidden = true;
  const email = form.elements.namedItem('email').value.trim();
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    showError('Enter a valid recovery email address.');
    return;
  }

  const button = form.querySelector('button[type="submit"]');
  setButtonBusy(button, true, 'Saving…');
  updateStatus('Saving your private recovery email…', 'busy');

  try {
    const result = await api('/api/recovery-email', {
      method: 'POST',
      body: { email },
    });
    form.hidden = true;
    updateStatus(result.message, 'ready');
  } catch (error) {
    setButtonBusy(button, false);
    showError(error.message || 'Could not save your recovery email.');
    updateStatus('We could not save that email yet.', 'error');
  }
});
