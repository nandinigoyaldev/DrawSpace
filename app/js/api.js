/**
 * Tiny fetch wrapper for the DrawSpace API.
 *
 * - always sends the CSRF token on state-changing requests
 * - turns non-2xx responses into ApiError instances
 * - never caches API responses
 */

let csrfToken = '';

export class ApiError extends Error {
  constructor(status, message, payload = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload;
  }
}

export function setCsrfToken(token) {
  csrfToken = typeof token === 'string' ? token : '';
}

export function getCsrfToken() {
  return csrfToken;
}

export async function api(path, { method = 'GET', body, signal, timeoutMs = 15000 } = {}) {
  const headers = { Accept: 'application/json' };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (csrfToken && method !== 'GET' && method !== 'HEAD') {
    headers['X-CSRF-Token'] = csrfToken;
  }

  let response;
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
  const abortFromCaller = () => controller.abort();

  if (signal) {
    if (signal.aborted) {
      controller.abort();
    } else {
      signal.addEventListener('abort', abortFromCaller, { once: true });
    }
  }

  try {
    response = await fetch(path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller.signal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw new ApiError(0, 'The server is taking too long to respond. Please try again.');
    }

    throw new ApiError(0, 'Could not reach the server. Check your connection.');
  } finally {
    window.clearTimeout(timeout);
    signal?.removeEventListener('abort', abortFromCaller);
  }

  const text = await response.text();
  let data = null;

  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = null;
    }
  }

  if (!response.ok) {
    const message = typeof data?.error === 'string'
      ? data.error
      : `Request failed (${response.status}).`;

    throw new ApiError(response.status, message, data);
  }

  return data;
}

export function goToLogin() {
  const here = location.pathname + location.search;
  const next = here && here !== '/' ? `?next=${encodeURIComponent(here)}` : '';
  location.replace(`/login${next}`);
}

export function safeNextPath(raw, fallback = '/') {
  if (typeof raw !== 'string' || raw === '') {
    return fallback;
  }

  // Only same-origin absolute paths; never protocol-relative or external URLs.
  if (!raw.startsWith('/') || raw.startsWith('//') || raw.includes('\\')) {
    return fallback;
  }

  return raw;
}

export function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
