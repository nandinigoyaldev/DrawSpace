/** DrawSpace app: boards, realtime polling, canvas tools. */

import { api, setCsrfToken, goToLogin, delay } from './api.js';
import { toast, initials, setButtonBusy } from './ui.js';

const LAST_ROOM_KEY = 'drawspace:last-room';
const SHARED_ROOMS_KEY = 'drawspace:shared-rooms';
const POLL_INTERVAL = 1500;
const MAX_POINTS_PER_STROKE = 1500;
const SHARE_ICON = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7c2.6 0 3.2-3.6 5.8-3.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
const BOARD_ICON = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="4.5" width="17" height="13" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M8 20.5h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
const TRASH_ICON = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13M10 11v5M14 11v5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';

const $ = (id) => document.getElementById(id);

const els = {
  body: document.body,
  sidebar: $('sidebar'),
  sidebarBackdrop: $('sidebar-backdrop'),
  menuBtn: $('menu-btn'),
  sidebarClose: $('sidebar-close'),
  newBoardBtn: $('new-board-btn'),
  emptyCreateBtn: $('empty-create-btn'),
  roomList: $('room-list'),
  roomListEmpty: $('room-list-empty'),
  sharedBlock: $('shared-block'),
  sharedList: $('shared-list'),
  userAvatar: $('user-avatar'),
  userName: $('user-name'),
  logoutBtn: $('logout-btn'),
  roomTitle: $('room-title'),
  renameBtn: $('rename-btn'),
  roomOwner: $('room-owner'),
  status: $('status'),
  statusText: $('status-text'),
  shareBtn: $('share-btn'),
  canvasShell: $('canvas-shell'),
  canvas: $('drawing-canvas'),
  emptyState: $('empty-state'),
  stageLoading: $('stage-loading'),
  toolbar: $('toolbar'),
  toolPen: $('tool-pen'),
  toolEraser: $('tool-eraser'),
  swatches: $('swatches'),
  customColor: $('custom-color'),
  sizeRange: $('size-range'),
  sizeLabel: $('size-label'),
  undoBtn: $('undo-btn'),
  clearBtn: $('clear-btn'),
  downloadBtn: $('download-btn'),
  boardDialog: $('board-dialog'),
  boardDialogTitle: $('board-dialog-title'),
  boardDialogHint: $('board-dialog-hint'),
  boardForm: $('board-form'),
  boardName: $('board-name'),
  confirmDialog: $('confirm-dialog'),
  confirmTitle: $('confirm-title'),
  confirmMessage: $('confirm-message'),
  confirmForm: $('confirm-form'),
  confirmOk: $('confirm-ok'),
  confirmCancel: $('confirm-cancel'),
  shareDialog: $('share-dialog'),
  shareForm: $('share-form'),
  shareUrl: $('share-url'),
};

const ctx = els.canvas.getContext('2d');

const state = {
  user: null,
  rooms: [],
  shared: [],
  room: null,
  version: null,
  strokes: [],
  pending: 0,
  loading: false,
  polling: false,
  tool: 'pen',
  color: '#111827',
  width: 4,
  stroke: null,
  strokePointerId: null,
  lastPoint: null,
  view: { w: 1, h: 1, dpr: 1 },
  pollTimer: 0,
  boardDialogMode: 'create',
  renameTarget: null,
  confirmResolver: null,
  resizePending: false,
};

/* ─────────────────────────────── Local storage ───────────────────────────── */

function loadSharedRooms() {
  try {
    const parsed = JSON.parse(localStorage.getItem(SHARED_ROOMS_KEY) || '[]');

    if (!Array.isArray(parsed)) {
      return [];
    }

    return parsed
      .filter((room) => room && typeof room.id === 'string' && typeof room.name === 'string')
      .slice(0, 12);
  } catch {
    return [];
  }
}

function saveSharedRooms() {
  try {
    localStorage.setItem(SHARED_ROOMS_KEY, JSON.stringify(state.shared.slice(0, 12)));
  } catch {
    // Storage can be disabled; sharing still works via the URL.
  }
}

function rememberSharedRoom(room) {
  if (!room || room.isOwner) {
    return;
  }

  state.shared = state.shared.filter((entry) => entry.id !== room.id);
  state.shared.unshift({ id: room.id, name: room.name, owner: room.owner });
  state.shared = state.shared.slice(0, 12);
  saveSharedRooms();
}

function forgetSharedRoom(roomId) {
  const next = state.shared.filter((entry) => entry.id !== roomId);

  if (next.length !== state.shared.length) {
    state.shared = next;
    saveSharedRooms();
  }
}

/* ────────────────────────────────── Boot ─────────────────────────────────── */

async function boot() {
  bindUi();
  setStatus('connecting');

  try {
    const me = await api('/api/me');
    state.user = me.user;
    setCsrfToken(me.csrfToken);
    renderUser();
  } catch (error) {
    if (error.status === 401) {
      goToLogin();
      return;
    }

    toast('Could not load your account. Please refresh.', 'error', 6000);
    showEmpty();
    return;
  }

  state.shared = loadSharedRooms();
  await loadRooms();
  renderRooms();

  const params = new URLSearchParams(location.search);
  const requested = params.get('r');

  let remembered = null;

  try {
    remembered = localStorage.getItem(LAST_ROOM_KEY);
  } catch {
    remembered = null;
  }

  const fallbackRoom = state.rooms[0]?.id || null;
  const candidate = requested || remembered || fallbackRoom;

  if (candidate) {
    await openRoom(candidate, { allowFallback: true });
  } else {
    showEmpty();
  }

  startPolling();
}

async function loadRooms() {
  try {
    const data = await api('/api/rooms');
    state.rooms = Array.isArray(data.rooms) ? data.rooms : [];
  } catch (error) {
    if (error.status === 401) {
      goToLogin();
      return;
    }

    state.rooms = [];
    toast('Could not load your boards.', 'error');
  }
}

function renderUser() {
  if (!state.user) {
    return;
  }

  els.userName.textContent = state.user.username;
  els.userAvatar.textContent = initials(state.user.username);
}

/* ────────────────────────────────── Rooms ────────────────────────────────── */

function makeRoomItem(room, { shared = false } = {}) {
  const item = document.createElement('li');
  item.className = 'room-item';
  item.classList.toggle('is-active', state.room?.id === room.id);

  const open = document.createElement('button');
  open.type = 'button';
  open.className = 'room-open';
  open.title = room.name;
  open.setAttribute('aria-current', state.room?.id === room.id ? 'page' : 'false');

  const glyph = document.createElement('span');
  glyph.className = 'room-glyph';
  glyph.innerHTML = shared ? SHARE_ICON : BOARD_ICON;

  const name = document.createElement('span');
  name.className = 'room-name';
  name.textContent = room.name;

  open.append(glyph, name);

  if (!shared && Number(room.strokeCount) > 0) {
    const count = document.createElement('span');
    count.className = 'room-count';
    count.textContent = String(room.strokeCount);
    count.title = `${room.strokeCount} strokes`;
    open.append(count);
  }

  open.addEventListener('click', () => {
    closeSidebar();
    openRoom(room.id, { allowFallback: true });
  });

  item.append(open);

  if (!shared) {
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'room-delete';
    remove.title = `Delete ${room.name}`;
    remove.setAttribute('aria-label', `Delete board ${room.name}`);
    remove.innerHTML = TRASH_ICON;
    remove.addEventListener('click', (event) => {
      event.stopPropagation();
      deleteRoom(room);
    });
    item.append(remove);
  }

  return item;
}

function renderRooms() {
  els.roomList.replaceChildren();

  for (const room of state.rooms) {
    els.roomList.append(makeRoomItem(room));
  }

  els.roomListEmpty.hidden = state.rooms.length > 0;

  const ownedIds = new Set(state.rooms.map((room) => room.id));
  const allShared = state.shared.filter((room) => !ownedIds.has(room.id));

  els.sharedList.replaceChildren();

  for (const room of allShared) {
    els.sharedList.append(makeRoomItem(room, { shared: true }));
  }

  els.sharedBlock.hidden = allShared.length === 0;
}

function showEmpty() {
  state.room = null;
  state.strokes = [];
  state.version = null;
  state.stroke = null;

  els.canvasShell.hidden = true;
  els.toolbar.hidden = true;
  els.stageLoading.hidden = true;
  els.emptyState.hidden = false;
  els.roomTitle.textContent = 'DrawSpace';
  els.roomOwner.textContent = '';
  els.roomOwner.classList.remove('is-shared');
  els.renameBtn.hidden = true;
  els.shareBtn.setAttribute('aria-disabled', 'true');
  els.clearBtn.setAttribute('aria-disabled', 'true');
  els.undoBtn.setAttribute('aria-disabled', 'true');
  document.title = 'DrawSpace · Shared drawing board';

  renderRooms();
  setStatus('connecting');
}

function showBoard() {
  els.canvasShell.hidden = false;
  els.toolbar.hidden = false;
  els.emptyState.hidden = true;
  els.stageLoading.hidden = true;
  els.shareBtn.removeAttribute('aria-disabled');
  els.undoBtn.removeAttribute('aria-disabled');
}

function renderRoomHeader() {
  if (!state.room) {
    return;
  }

  els.roomTitle.textContent = state.room.name;
  els.roomTitle.title = state.room.name;
  document.title = `${state.room.name} · DrawSpace`;

  if (state.room.isOwner) {
    els.roomOwner.textContent = 'Your board';
    els.roomOwner.classList.remove('is-shared');
    els.renameBtn.hidden = false;
    els.clearBtn.removeAttribute('aria-disabled');
  } else {
    els.roomOwner.textContent = `Shared by @${state.room.owner}`;
    els.roomOwner.classList.add('is-shared');
    els.renameBtn.hidden = true;
    els.clearBtn.setAttribute('aria-disabled', 'true');
  }
}

function updateOwnedRoom(room) {
  const owned = state.rooms.find((entry) => entry.id === room.id);

  if (!owned) {
    return;
  }

  owned.name = room.name;
  owned.version = room.version;
  owned.updatedAt = room.updatedAt;

  if (room.strokeCount !== null && room.strokeCount !== undefined) {
    owned.strokeCount = room.strokeCount;
  }
}

async function openRoom(roomId, { allowFallback = false } = {}) {
  if (!roomId || state.loading || (state.room && state.room.id === roomId)) {
    return;
  }

  state.loading = true;
  setStatus('connecting');

  try {
    const data = await api(`/api/load?room=${encodeURIComponent(roomId)}`);

    if (!data || data.unchanged) {
      return;
    }

    state.room = data.room;
    state.strokes = Array.isArray(data.strokes) ? data.strokes : [];
    state.version = data.version;
    state.stroke = null;

    try {
      localStorage.setItem(LAST_ROOM_KEY, roomId);
    } catch {
      // Ignore storage failures.
    }

    if (state.room.isOwner) {
      forgetSharedRoom(roomId);
    } else {
      rememberSharedRoom(state.room);
    }

    updateOwnedRoom(state.room);
    history.replaceState({}, '', `/?r=${encodeURIComponent(roomId)}`);

    showBoard();
    renderRoomHeader();
    renderRooms();
    resizeCanvas(true);
    redraw();
    setStatus('live');
  } catch (error) {
    if (error.status === 401) {
      goToLogin();
      return;
    }

    if (error.status === 404 || error.status === 400) {
      forgetSharedRoom(roomId);

      try {
        if (localStorage.getItem(LAST_ROOM_KEY) === roomId) {
          localStorage.removeItem(LAST_ROOM_KEY);
        }
      } catch {
        // Ignore.
      }

      state.room = null;

      if (allowFallback) {
        const fallback = state.rooms.find((room) => room.id !== roomId);

        if (fallback) {
          state.loading = false;
          await openRoom(fallback.id, { allowFallback: false });
          return;
        }
      }

      toast('That board is no longer available.', 'warning');
      showEmpty();
      return;
    }

    toast(error.message || 'Could not open that board.', 'error');
    setStatus('offline');

    if (!state.room) {
      showEmpty();
    }
  } finally {
    state.loading = false;
  }
}

async function createRoom(name) {
  const data = await api('/api/rooms', { method: 'POST', body: { name } });
  state.rooms.unshift({ ...data.room, strokeCount: 0 });
  renderRooms();
  await openRoom(data.room.id, { allowFallback: false });
}

async function renameRoom(room, name) {
  const data = await api('/api/rooms', {
    method: 'PATCH',
    body: { id: room.id, name },
  });

  state.room = {
    ...state.room,
    name: data.room.name,
    version: data.room.version,
    updatedAt: data.room.updatedAt,
    isOwner: data.room.isOwner,
    owner: data.room.owner,
  };
  updateOwnedRoom(data.room);
  renderRoomHeader();
  renderRooms();
  toast('Board renamed.', 'success');
}

async function deleteRoom(room) {
  if (state.pending > 0) {
    toast('Wait for the current stroke to finish saving.', 'info');
    return;
  }

  const confirmed = await confirmAction({
    title: 'Delete this board?',
    message: `“${room.name}” and everything drawn on it will be permanently removed for everyone.`,
    confirmLabel: 'Delete board',
  });

  if (!confirmed) {
    return;
  }

  try {
    await api('/api/rooms', { method: 'DELETE', body: { id: room.id } });
  } catch (error) {
    if (error.status === 401) {
      goToLogin();
      return;
    }

    toast(error.message || 'Could not delete the board.', 'error');
    return;
  }

  state.rooms = state.rooms.filter((entry) => entry.id !== room.id);
  forgetSharedRoom(room.id);
  toast('Board deleted.', 'success');

  if (state.room?.id === room.id) {
    state.room = null;
    state.strokes = [];
    state.version = null;

    if (state.rooms[0]) {
      await openRoom(state.rooms[0].id);
    } else {
      history.replaceState({}, '', '/');
      showEmpty();
    }
  } else {
    renderRooms();
  }
}

/* ───────────────────────────────── Polling ───────────────────────────────── */

function startPolling() {
  stopPolling();
  state.pollTimer = window.setInterval(poll, POLL_INTERVAL);
}

function stopPolling() {
  if (state.pollTimer) {
    window.clearInterval(state.pollTimer);
    state.pollTimer = 0;
  }
}

async function poll() {
  if (
    !state.room
    || state.loading
    || state.polling
    || state.pending > 0
    || state.stroke
    || document.hidden
  ) {
    return;
  }

  state.polling = true;

  try {
    const data = await api(
      `/api/load?room=${encodeURIComponent(state.room.id)}&v=${state.version}`,
    );

    if (!data || data.unchanged) {
      setStatus('live');
      return;
    }

    if (!state.room || data.room.id !== state.room.id) {
      return;
    }

    // A stroke can begin after the request was sent. Applying the older
    // snapshot now would wipe the wet stroke off the canvas.
    if (state.stroke || state.pending > 0) {
      return;
    }

    state.room = data.room;
    state.strokes = Array.isArray(data.strokes) ? data.strokes : [];
    state.version = data.version;

    if (state.room.isOwner) {
      forgetSharedRoom(state.room.id);
    } else {
      rememberSharedRoom(state.room);
    }

    updateOwnedRoom(state.room);
    renderRoomHeader();
    renderRooms();
    redraw();
    setStatus('live');
  } catch (error) {
    if (error.status === 401) {
      goToLogin();
      return;
    }

    if (error.status === 404) {
      handleRoomGone();
      return;
    }

    setStatus('offline');
  } finally {
    state.polling = false;
  }
}

function handleRoomGone() {
  const goneId = state.room?.id;
  toast('This board was deleted by its owner.', 'warning', 5000);

  if (goneId) {
    state.rooms = state.rooms.filter((room) => room.id !== goneId);
    forgetSharedRoom(goneId);
  }

  state.room = null;
  state.strokes = [];
  state.version = null;
  renderRooms();

  if (state.rooms[0]) {
    openRoom(state.rooms[0].id);
  } else {
    history.replaceState({}, '', '/');
    showEmpty();
  }
}

async function refreshRoom() {
  if (!state.room) {
    return;
  }

  const roomId = state.room.id;

  try {
    const data = await api(`/api/load?room=${encodeURIComponent(roomId)}`);

    if (!data || !state.room || state.room.id !== roomId) {
      return;
    }

    // Keep the canvas untouched if the user has started a new stroke while the
    // refresh was in flight; the next poll will catch up.
    if (state.stroke || state.pending > 0) {
      return;
    }

    state.room = data.room;
    state.strokes = Array.isArray(data.strokes) ? data.strokes : [];
    state.version = data.version;

    if (state.room.isOwner) {
      forgetSharedRoom(state.room.id);
    } else {
      rememberSharedRoom(state.room);
    }

    updateOwnedRoom(state.room);
    renderRoomHeader();
    renderRooms();
    redraw();
    setStatus('live');
  } catch (error) {
    if (error.status === 401) {
      goToLogin();
      return;
    }

    setStatus('offline');
  }
}

function setStatus(status) {
  const labels = {
    live: 'Live',
    saving: 'Saving…',
    offline: 'Reconnecting…',
    connecting: 'Connecting…',
  };

  els.status.dataset.state = status;
  els.statusText.textContent = labels[status] || 'Connecting…';
}

/* ───────────────────────────────── Canvas ────────────────────────────────── */

function resizeCanvas(force = false) {
  if (!state.room) {
    return;
  }

  if (state.stroke) {
    state.resizePending = true;
    return;
  }

  const rect = els.canvasShell.getBoundingClientRect();
  const w = Math.max(1, Math.round(rect.width));
  const h = Math.max(1, Math.round(rect.height));
  const dpr = Math.min(window.devicePixelRatio || 1, 2);

  if (!force && state.view.w === w && state.view.h === h && state.view.dpr === dpr) {
    return;
  }

  els.canvas.width = Math.round(w * dpr);
  els.canvas.height = Math.round(h * dpr);
  state.view = { w, h, dpr };

  redraw();
}

function redraw() {
  const { w, h, dpr } = state.view;

  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.clearRect(0, 0, els.canvas.width, els.canvas.height);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

  for (const stroke of state.strokes) {
    paintStroke(ctx, stroke, w, h);
  }
}

function paintStroke(context, stroke, w, h, { flatten = false } = {}) {
  const points = Array.isArray(stroke.points) ? stroke.points : [];

  if (points.length === 0) {
    return;
  }

  const eraser = stroke.mode === 'eraser';

  context.save();

  if (eraser) {
    context.globalCompositeOperation = flatten ? 'source-over' : 'destination-out';
  }

  const color = eraser && flatten ? '#ffffff' : stroke.color;

  context.strokeStyle = color;
  context.fillStyle = color;
  context.lineWidth = Number(stroke.width) || 4;
  context.lineCap = 'round';
  context.lineJoin = 'round';

  if (points.length === 1) {
    context.beginPath();
    context.arc(
      points[0][0] * w,
      points[0][1] * h,
      Math.max(context.lineWidth / 2, 0.5),
      0,
      Math.PI * 2,
    );
    context.fill();
  } else {
    context.beginPath();
    context.moveTo(points[0][0] * w, points[0][1] * h);

    for (let index = 1; index < points.length; index += 1) {
      context.lineTo(points[index][0] * w, points[index][1] * h);
    }

    context.stroke();
  }

  context.restore();
}

function pointFromEvent(event) {
  const rect = els.canvas.getBoundingClientRect();

  if (!rect.width || !rect.height) {
    return null;
  }

  return [
    Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)),
    Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)),
  ];
}

function beginStroke(point) {
  const stroke = {
    id: `local-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    mode: state.tool === 'eraser' ? 'eraser' : 'pen',
    color: state.color,
    width: state.width,
    points: [point],
    mine: true,
    local: true,
  };

  state.stroke = stroke;
  state.lastPoint = point;

  ctx.save();
  ctx.globalCompositeOperation = stroke.mode === 'eraser' ? 'destination-out' : 'source-over';
  ctx.strokeStyle = stroke.color;
  ctx.fillStyle = stroke.color;
  ctx.lineWidth = stroke.width;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
}

function drawStrokePoint(point) {
  if (!state.stroke) {
    return;
  }

  const { w, h } = state.view;
  const x = point[0] * w;
  const y = point[1] * h;

  ctx.beginPath();
  ctx.arc(x, y, Math.max(state.stroke.width / 2, 0.5), 0, Math.PI * 2);
  ctx.fill();
}

function drawStrokeSegment(from, to) {
  if (!state.stroke) {
    return;
  }

  const { w, h } = state.view;

  ctx.beginPath();
  ctx.moveTo(from[0] * w, from[1] * h);
  ctx.lineTo(to[0] * w, to[1] * h);
  ctx.stroke();
}

function appendPoint(point) {
  const stroke = state.stroke;

  if (!stroke) {
    return;
  }

  if (stroke.points.length >= MAX_POINTS_PER_STROKE) {
    // Split extremely long continuous strokes so every point reaches the
    // server, while keeping the same pointer capture alive.
    endStroke(true);
    beginStroke(point);
    drawStrokePoint(point);
    return;
  }

  const last = stroke.points[stroke.points.length - 1];
  const { w, h } = state.view;
  const dx = (point[0] - last[0]) * w;
  const dy = (point[1] - last[1]) * h;

  if (Math.hypot(dx, dy) < 1.2) {
    return;
  }

  stroke.points.push(point);
  state.lastPoint = point;
  drawStrokeSegment(last, point);
}

function endStroke(save) {
  const stroke = state.stroke;

  if (!stroke) {
    return;
  }

  if (stroke.points.length === 1) {
    drawStrokePoint(stroke.points[0]);
  }

  ctx.restore();
  state.stroke = null;
  state.lastPoint = null;

  if (!save || !state.room) {
    return;
  }

  const roomId = state.room.id;
  state.strokes.push(stroke);
  persistStroke(stroke, roomId);
}

async function persistStroke(stroke, roomId) {
  state.pending += 1;

  if (state.room?.id === roomId) {
    setStatus('saving');
  }

  const payload = {
    room: roomId,
    stroke: {
      mode: stroke.mode,
      color: stroke.color,
      width: stroke.width,
      points: stroke.points,
    },
  };

  let saved = false;
  let redirected = false;

  for (let attempt = 0; attempt < 3 && !saved; attempt += 1) {
    try {
      await api('/api/save', { method: 'POST', body: payload });
      saved = true;
    } catch (error) {
      if (error.status === 401) {
        goToLogin();
        redirected = true;
        break;
      }

      if (error.status === 409) {
        toast(error.message, 'warning', 5000);
        break;
      }

      if (error.status === 429) {
        toast('Saving too quickly — pausing for a moment.', 'warning');
        await delay(1500);
      } else if (attempt < 2) {
        await delay(600 * (attempt + 1));
      } else {
        toast('Could not save that stroke. Check your connection.', 'error', 5000);
      }
    }
  }

  state.pending = Math.max(0, state.pending - 1);

  if (redirected || state.room?.id !== roomId) {
    return;
  }

  if (saved) {
    setStatus('live');
    // Keep the old version on purpose: the next poll fetches the full snapshot
    // including any strokes other people added in the meantime.
    poll();
  } else {
    state.strokes = state.strokes.filter((entry) => entry !== stroke);
    redraw();
    setStatus('offline');
    poll();
  }
}

function onPointerDown(event) {
  if (!state.room || state.loading) {
    return;
  }

  if (event.pointerType === 'mouse' && event.button !== 0) {
    return;
  }

  const point = pointFromEvent(event);

  if (!point) {
    return;
  }

  event.preventDefault();

  if (state.stroke) {
    endStroke(true);
  }

  try {
    els.canvas.setPointerCapture(event.pointerId);
  } catch {
    // Some browsers refuse capture for synthetic events; drawing still works.
  }

  state.strokePointerId = event.pointerId;
  beginStroke(point);
  drawStrokePoint(point);
}

function onPointerMove(event) {
  if (!state.stroke || event.pointerId !== state.strokePointerId) {
    return;
  }

  event.preventDefault();

  const events = typeof event.getCoalescedEvents === 'function'
    ? event.getCoalescedEvents()
    : [event];

  const samples = events.length > 0 ? events : [event];

  for (const sample of samples) {
    const point = pointFromEvent(sample);

    if (point) {
      appendPoint(point);
    }
  }
}

function onPointerUp(event) {
  if (!state.stroke || event.pointerId !== state.strokePointerId) {
    return;
  }

  if (els.canvas.hasPointerCapture?.(event.pointerId)) {
    els.canvas.releasePointerCapture(event.pointerId);
  }

  state.strokePointerId = null;
  endStroke(true);

  if (state.resizePending) {
    state.resizePending = false;
    resizeCanvas(true);
  }
}

/* ────────────────────────────────── Tools ────────────────────────────────── */

function setTool(tool) {
  state.tool = tool === 'eraser' ? 'eraser' : 'pen';

  els.toolPen.classList.toggle('is-active', state.tool === 'pen');
  els.toolEraser.classList.toggle('is-active', state.tool === 'eraser');
  els.toolPen.setAttribute('aria-pressed', String(state.tool === 'pen'));
  els.toolEraser.setAttribute('aria-pressed', String(state.tool === 'eraser'));
  els.canvasShell.dataset.tool = state.tool;
}

function setColor(color) {
  if (!/^#[0-9a-fA-F]{6}$/.test(color)) {
    return;
  }

  state.color = color.toLowerCase();
  els.customColor.value = state.color;

  let matched = false;

  els.swatches.querySelectorAll('.swatch').forEach((swatch) => {
    const active = swatch.dataset.color?.toLowerCase() === state.color;
    swatch.classList.toggle('is-active', active);
    matched = matched || active;
  });

  if (!matched && state.tool === 'eraser') {
    setTool('pen');
  }
}

function setWidth(width) {
  state.width = Math.min(32, Math.max(1, Number(width) || 4));
  els.sizeRange.value = String(state.width);
  els.sizeLabel.textContent = String(state.width);
}

/* ────────────────────────────────── Actions ──────────────────────────────── */

async function undoLastStroke() {
  if (!state.room) {
    return;
  }

  if (state.pending > 0 || els.undoBtn.getAttribute('aria-disabled') === 'true') {
    toast('Wait for your stroke to finish saving.', 'info');
    return;
  }

  const roomId = state.room.id;
  els.undoBtn.setAttribute('aria-disabled', 'true');

  try {
    const result = await api('/api/undo', { method: 'POST', body: { room: roomId } });

    if (!result.removed) {
      toast('Nothing of yours to undo on this board.', 'info');
      return;
    }

    await refreshRoom();
  } catch (error) {
    if (error.status === 401) {
      goToLogin();
      return;
    }

    toast(error.message || 'Could not undo.', 'error');
  } finally {
    els.undoBtn.removeAttribute('aria-disabled');
  }
}

async function clearBoard() {
  if (!state.room) {
    return;
  }

  if (!state.room.isOwner) {
    toast('Only the board owner can clear it.', 'warning');
    return;
  }

  if (state.pending > 0) {
    toast('Wait for the current stroke to finish saving.', 'info');
    return;
  }

  const confirmed = await confirmAction({
    title: 'Clear the whole board?',
    message: `Every stroke on “${state.room.name}” will be erased for everyone. This cannot be undone.`,
    confirmLabel: 'Clear board',
  });

  if (!confirmed) {
    return;
  }

  const roomId = state.room.id;

  try {
    await api('/api/clear', { method: 'POST', body: { room: roomId } });
    await refreshRoom();
    toast('Board cleared.', 'success');
  } catch (error) {
    if (error.status === 401) {
      goToLogin();
      return;
    }

    toast(error.message || 'Could not clear the board.', 'error');
  }
}

function downloadPng() {
  if (!state.room || state.strokes.length === 0) {
    toast('Nothing to export yet.', 'info');
    return;
  }

  const { w, h } = state.view;
  const scale = Math.min(2, Math.max(1, window.devicePixelRatio || 1));
  const output = document.createElement('canvas');
  output.width = Math.max(1, Math.round(w * scale));
  output.height = Math.max(1, Math.round(h * scale));

  const outCtx = output.getContext('2d');
  outCtx.fillStyle = '#ffffff';
  outCtx.fillRect(0, 0, output.width, output.height);
  outCtx.scale(scale, scale);

  for (const stroke of state.strokes) {
    paintStroke(outCtx, stroke, w, h, { flatten: true });
  }

  output.toBlob((blob) => {
    if (!blob) {
      toast('Could not create the image.', 'error');
      return;
    }

    const slug = (state.room.name || 'drawspace')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 40) || 'drawspace';

    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${slug}.png`;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    toast('Image downloaded.', 'success');
  }, 'image/png');
}

/* ────────────────────────────────── Sharing ──────────────────────────────── */

function shareUrlFor(room) {
  const url = new URL('/', location.origin);
  url.searchParams.set('r', room.id);
  return url.toString();
}

async function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // Fall through to the legacy path.
    }
  }

  const area = document.createElement('textarea');
  area.value = text;
  area.className = 'copy-fallback';
  area.setAttribute('readonly', '');
  area.setAttribute('aria-hidden', 'true');
  document.body.append(area);
  area.select();

  let copied = false;

  try {
    copied = document.execCommand('copy');
  } catch {
    copied = false;
  }

  area.remove();

  return copied;
}

async function shareBoard() {
  if (!state.room) {
    toast('Open a board first.', 'info');
    return;
  }

  const link = shareUrlFor(state.room);

  if (await copyText(link)) {
    toast('Share link copied to clipboard.', 'success');
    return;
  }

  els.shareUrl.value = link;
  els.shareDialog.showModal();
  els.shareUrl.select();
}

/* ────────────────────────────────── Dialogs ──────────────────────────────── */

function openBoardDialog(mode, room = null) {
  state.boardDialogMode = mode;
  state.renameTarget = mode === 'rename' ? room : null;

  els.boardDialogTitle.textContent = mode === 'rename' ? 'Rename board' : 'New board';
  els.boardDialogHint.textContent = mode === 'rename'
    ? 'Pick a name that is easy to spot in your sidebar.'
    : 'Give it a name you will recognise later. Leave it blank for “Untitled board”.';
  const submit = els.boardForm.querySelector('button[type="submit"]');
  const label = submit.querySelector('[data-label]');
  label.textContent = mode === 'rename' ? 'Save name' : 'Create board';
  delete submit.dataset.idleLabel;
  els.boardName.value = mode === 'rename' && room ? room.name : '';
  els.boardName.placeholder = mode === 'rename' ? 'Board name' : 'Sprint sketches';
  els.boardDialog.showModal();
  els.boardName.focus();
  els.boardName.select();
}

function closeDialog(dialog) {
  if (dialog?.open) {
    dialog.close();
  }
}

function confirmAction({ title, message, confirmLabel = 'Confirm', danger = true }) {
  if (state.confirmResolver) {
    resolveConfirm(false);
  }

  els.confirmTitle.textContent = title;
  els.confirmMessage.textContent = message;
  els.confirmOk.querySelector('[data-label]').textContent = confirmLabel;
  els.confirmOk.className = danger ? 'btn btn-danger' : 'btn btn-primary';

  els.confirmDialog.showModal();
  els.confirmOk.focus();

  return new Promise((resolve) => {
    state.confirmResolver = resolve;
  });
}

function resolveConfirm(value) {
  const resolver = state.confirmResolver;
  state.confirmResolver = null;
  closeDialog(els.confirmDialog);
  resolver?.(value);
}

/* ─────────────────────────────────── Events ──────────────────────────────── */

function bindUi() {
  els.toolPen.addEventListener('click', () => setTool('pen'));
  els.toolEraser.addEventListener('click', () => setTool('eraser'));
  els.downloadBtn.addEventListener('click', downloadPng);
  els.undoBtn.addEventListener('click', undoLastStroke);
  els.clearBtn.addEventListener('click', clearBoard);
  els.sizeRange.addEventListener('input', (event) => setWidth(event.target.value));

  els.swatches.querySelectorAll('.swatch').forEach((swatch) => {
    swatch.addEventListener('click', () => {
      setColor(swatch.dataset.color);
      setTool('pen');
    });
  });

  els.customColor.addEventListener('input', (event) => {
    setColor(event.target.value);
    setTool('pen');
  });

  els.canvas.addEventListener('pointerdown', onPointerDown);
  els.canvas.addEventListener('pointermove', onPointerMove);
  els.canvas.addEventListener('pointerup', onPointerUp);
  els.canvas.addEventListener('pointercancel', onPointerUp);
  els.canvas.addEventListener('contextmenu', (event) => {
    if (state.stroke) {
      event.preventDefault();
    }
  });

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && state.room) {
      poll();
    }
  });

  const observer = new ResizeObserver(() => resizeCanvas(false));
  observer.observe(els.canvasShell);
  window.addEventListener('orientationchange', () => {
    window.setTimeout(() => resizeCanvas(true), 150);
  });

  window.addEventListener('beforeunload', (event) => {
    if (state.pending > 0) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  els.newBoardBtn.addEventListener('click', () => openBoardDialog('create'));
  els.emptyCreateBtn.addEventListener('click', () => openBoardDialog('create'));

  els.renameBtn.addEventListener('click', () => {
    if (state.room?.isOwner) {
      openBoardDialog('rename', state.room);
    }
  });

  els.shareBtn.addEventListener('click', shareBoard);

  els.logoutBtn.addEventListener('click', async () => {
    els.logoutBtn.disabled = true;

    try {
      await api('/api/logout', { method: 'POST' });
      location.replace('/login');
    } catch (error) {
      if (error.status === 401) {
        location.replace('/login');
        return;
      }

      els.logoutBtn.disabled = false;
      toast('Could not sign out. Please try again.', 'error');
    }
  });

  els.menuBtn.addEventListener('click', () => {
    if (els.body.classList.contains('sidebar-open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  });

  els.sidebarClose.addEventListener('click', closeSidebar);
  els.sidebarBackdrop.addEventListener('click', closeSidebar);

  els.boardForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const button = els.boardForm.querySelector('button[type="submit"]');
    const name = els.boardName.value.trim();

    setButtonBusy(button, true, state.boardDialogMode === 'rename' ? 'Saving…' : 'Creating…');

    try {
      if (state.boardDialogMode === 'rename' && state.renameTarget) {
        await renameRoom(state.renameTarget, name);
      } else {
        await createRoom(name);
      }

      closeDialog(els.boardDialog);
    } catch (error) {
      if (error.status === 401) {
        goToLogin();
        return;
      }

      toast(error.message || 'Could not save the board.', 'error');
    } finally {
      setButtonBusy(button, false);
    }
  });

  els.shareForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (await copyText(els.shareUrl.value)) {
      toast('Share link copied to clipboard.', 'success');
    } else {
      els.shareUrl.select();
      toast('Select the link and copy it manually.', 'warning');
    }
  });

  els.confirmForm.addEventListener('submit', (event) => {
    event.preventDefault();
    resolveConfirm(true);
  });

  els.confirmCancel.addEventListener('click', () => resolveConfirm(false));

  els.confirmDialog.addEventListener('close', () => {
    // A close event can arrive after the dialog was reopened for a new
    // confirmation; ignore it in that case.
    if (els.confirmDialog.open) {
      return;
    }

    if (state.confirmResolver) {
      const resolver = state.confirmResolver;
      state.confirmResolver = null;
      resolver(false);
    }
  });

  document.querySelectorAll('[data-close-dialog]').forEach((button) => {
    button.addEventListener('click', () => closeDialog(button.closest('dialog')));
  });

  document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        dialog.close();
      }
    });
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 900) {
      closeSidebar();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeSidebar();
      return;
    }

    const target = event.target;

    if (
      target instanceof HTMLElement
      && (target.closest('input, textarea, select, button, dialog') || target.isContentEditable)
    ) {
      return;
    }

    if (document.querySelector('dialog[open]')) {
      return;
    }

    const key = event.key.toLowerCase();

    if ((event.metaKey || event.ctrlKey) && key === 'z') {
      event.preventDefault();
      undoLastStroke();
      return;
    }

    if ((event.metaKey || event.ctrlKey) && key === 's') {
      event.preventDefault();
      downloadPng();
      return;
    }

    if (event.metaKey || event.ctrlKey || event.altKey) {
      return;
    }

    if (key === 'b') {
      setTool('pen');
    } else if (key === 'e') {
      setTool('eraser');
    }
  });
}

function openSidebar() {
  els.body.classList.add('sidebar-open');
  els.sidebarBackdrop.hidden = false;
  els.menuBtn.setAttribute('aria-expanded', 'true');
}

function closeSidebar() {
  els.body.classList.remove('sidebar-open');
  els.sidebarBackdrop.hidden = true;
  els.menuBtn.setAttribute('aria-expanded', 'false');
}

/* ─────────────────────────────────── Start ───────────────────────────────── */

setTool('pen');
setWidth(4);
setColor('#111827');
boot();
