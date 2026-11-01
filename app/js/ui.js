/** Shared UI helpers: toasts, initials, button busy states. */

export function toast(message, kind = 'info', timeout = 3600) {
  const container = document.getElementById('toasts');

  if (!container) {
    return;
  }

  const element = document.createElement('div');
  element.className = 'toast';
  element.dataset.kind = kind;
  element.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  element.textContent = message;
  container.append(element);

  window.setTimeout(() => {
    element.classList.add('is-leaving');
    window.setTimeout(() => element.remove(), 200);
  }, timeout);
}

export function initials(username) {
  const clean = String(username || '?').replace(/[^a-zA-Z0-9_]/g, '');
  return (clean.slice(0, 2) || '?').toUpperCase();
}

export function setButtonBusy(button, busy, busyText) {
  if (!button) {
    return;
  }

  const label = button.querySelector('[data-label]');
  const original = button.dataset.idleLabel || (label ? label.textContent : button.textContent);

  if (!button.dataset.idleLabel) {
    button.dataset.idleLabel = original;
  }

  button.disabled = busy;
  button.setAttribute('aria-busy', busy ? 'true' : 'false');

  if (label) {
    if (busy) {
      label.textContent = busyText || 'Working…';
    } else {
      label.textContent = button.dataset.idleLabel;
    }
  }
}

export function debounce(callback, wait = 100) {
  let timer = 0;

  return (...args) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => callback(...args), wait);
  };
}
