<div align="center">

<!-- ─────────────── LOGO / TITLE ─────────────── -->
<h1>🎨 DrawSpace</h1>

<h3><i>Open the link. Grab a color. Draw together — in real time.</i></h3>

<p>
  A tiny, dependency-light <b>shared whiteboard</b> with real accounts and private board links.
  Sign in, create a board, share the URL — everyone with the link draws on the same canvas,
  <b>at the same time</b>.
</p>

<!-- ─────────────── BADGES ─────────────── -->
<p>
  <img src="https://img.shields.io/badge/version-1.0.0-8A2BE2?style=for-the-badge&color=7C3AED" alt="version" />
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black" alt="JavaScript" />
  <img src="https://img.shields.io/badge/PostgreSQL%20%7C%20SQLite-database-4479A1?style=for-the-badge&logo=postgresql&logoColor=white" alt="Database" />
  <img src="https://img.shields.io/badge/license-MIT-green?style=for-the-badge" alt="license" />
</p>

<p>
  <a href="#-features">Features</a> •
  <a href="#-quick-start">Quick start</a> •
  <a href="#-how-it-works">How it works</a> •
  <a href="#-project-structure">Structure</a> •
  <a href="#-api">API</a> •
  <a href="#-security">Security</a> •
  <a href="#%EF%B8%8F-deployment">Deployment</a> •
  <a href="#-roadmap">Roadmap</a>
</p>

</div>

---

## ✨ Features

- 🔐 **Real accounts** — username + password sign-up, login and logout
- 🗂️ **Personal boards** — create, rename and delete your own canvases
- 🔗 **Capability links** — share a board with one URL; anyone signed in who has the link can draw
- 👥 **Live multiplayer** — other people's strokes appear while you watch (1.5 s polling)
- 🖌️ **Proper tools** — pen, eraser, 8 colours + custom picker, brush sizes, undo, clear, PNG export
- ↕️ **Resolution independent** — points are stored as 0…1 coordinates, so the board looks right on every screen
- 🖱️ **Mouse, touch and stylus** — pointer events with pressure-friendly coalesced sampling
- 💾 **Persistent** — refresh or come back tomorrow; the board is still there
- 📱 **Responsive UI** — collapsible sidebar, touch toolbar, high-DPI canvas
- ⌨️ **Shortcuts** — `B` pen, `E` eraser, `Ctrl/Cmd+Z` undo, `Ctrl/Cmd+S` export
- 🪶 **No build step** — plain PHP + vanilla ES modules + CSS. No npm, no framework
- 🛡️ **Security-first backend** — see the [security section](#-security)

## 🚀 Quick start

### Requirements

- PHP **8.1+** with `pdo_pgsql` (production) or `pdo_sqlite` (local)
- PostgreSQL 14+ **or** SQLite 3
- Any web server; no Composer or Node dependencies

### Option A — SQLite (fastest, great for local dev)

```bash
git clone <your-fork> DrawSpace
cd DrawSpace
cp .env.example .env
```

Edit `.env`:

```dotenv
DB_DRIVER=sqlite
DB_PATH=storage/drawspace.sqlite
APP_DEBUG=true
TRUST_PROXY=false
```

Then start PHP's built-in server with the included router:

```bash
php -S 127.0.0.1:8000 router.php
```

Open <http://127.0.0.1:8000>. The database and schema are created automatically on the
first request. `storage/` is git-ignored and denied by Apache.

> SQLite is perfect locally, but **not** suitable for Vercel or other serverless hosts —
> the filesystem is ephemeral. Use a managed PostgreSQL database such as Neon there.

### Option B — PostgreSQL (Neon)

Create a free Neon project and copy its connection details from the Neon
dashboard. Use the `database`, `role`, password, host, and port from Neon:

```dotenv
DB_DRIVER=postgres
DB_HOST=ep-example.us-east-2.aws.neon.tech
DB_PORT=5432
DB_NAME=neondb
DB_USER=neondb_owner
DB_PASS=use-your-neon-password
DB_SSL=true
APP_DEBUG=false
```

The tables are created automatically on the first API request. For local
development, keep using the SQLite configuration above.

### Environment variables

| Variable                          | Default                      | Notes                                                                                                        |
| --------------------------------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `APP_ENV`                         | `local`                      | Informational                                                                                                |
| `APP_DEBUG`                       | `false`                      | Adds error details to JSON **only** when `true`. Keep `false` in production                                  |
| `DB_DRIVER`                       | `sqlite`                     | `postgres`, `mysql`, or `sqlite`                                                                             |
| `DB_HOST` / `DB_PORT`             | `127.0.0.1` / `5432`         | PostgreSQL/Neon or MySQL                                                                                     |
| `DB_NAME` / `DB_USER` / `DB_PASS` | `drawspace` / `postgres` / empty | PostgreSQL/Neon or MySQL                                                                                  |
| `DB_SSL`                          | `false`                      | Enable TLS; use `true` for Neon                                                                               |
| `DB_SSL_VERIFY`                   | `true`                       | Verify the database certificate (set `false` only if your host provides no CA)                               |
| `DB_SSL_CA`                       | —                            | Path to a CA bundle                                                                                          |
| `DATABASE_URL`                    | —               | Optional complete PostgreSQL/Neon URL; takes precedence over individual `DB_*` connection values            |
| `DB_PATH`                         | `storage/drawspace.sqlite`   | SQLite file                                                                                                  |
| `SESSION_TTL_DAYS`                | `14`                         | Session lifetime, clamped to 1–90                                                                            |
| `TRUST_PROXY`                     | `false`                      | Set `true` behind a trusted non-Vercel proxy so real client IPs and HTTPS are detected; Vercel is detected automatically |

### Deploying to Vercel with Neon

Create a Neon project, then add these variables in Vercel for the
Production environment. Copy the host, database name, role, and password from
Neon's **Connect** dialog:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_DRIVER=postgres
DB_HOST=ep-example.us-east-2.aws.neon.tech
DB_PORT=5432
DB_NAME=neondb
DB_USER=neondb_owner
DB_PASS=your-neon-password
DB_SSL=true
```

Do not paste the full Neon connection string into the repository or commit
the password. Tables are created automatically on the first API request.
Deployments that leave `DB_DRIVER=sqlite` on Vercel are rejected because
Vercel's filesystem is ephemeral.

## 💡 How it works

```
   You ──┐                       ┌── Friend A
         │   ┌───────────────┐   │
   You ──┼──▶│  DrawSpace    │◀──┼── Friend B
         │   │  (shared board)│  │
   You ──┘   └───────┬───────┘  └── Friend C
                     │
              ┌──────▼──────┐
              │ PostgreSQL/ │  ← strokes, accounts, sessions
              │  SQLite     │
              └─────────────┘
```

1. `app/index.html` + `app/js/draw.js` render the board and call `/api/me` to check the session.
2. Every finished stroke is `POST`ed to `/api/save` as normalised coordinates (0…1).
3. Clients poll `/api/load?room=…&v=…`. If the board version is unchanged, the server replies
   with `{ "unchanged": true }` — otherwise it sends a fresh snapshot.
4. Undo deletes **your** most recent stroke; clear (owner only) deletes all strokes. Both bump
   the board version so every client refreshes.
5. A board's 18-character public id is unguessable and acts as the share capability.

> Polling is intentional: it keeps the project deployable on cheap PHP hosting.
> WebSockets are on the [roadmap](#-roadmap).

## 🛠️ Tech stack

| Layer    | Choice                               | Why                                       |
| -------- | ------------------------------------ | ----------------------------------------- |
| Frontend | Vanilla ES modules + Canvas 2D       | Zero dependencies, instant load           |
| Styling  | Plain CSS (custom properties)        | No framework tax                          |
| Backend  | PHP 8.1+ + PDO                       | Simple, ubiquitous hosting                |
| Storage  | PostgreSQL / SQLite                 | Reliable persistence, easy local setup    |
| Auth     | Argon2id/bcrypt + DB-backed sessions | No framework, still safe                  |
| Realtime | Versioned polling                    | Ships today, scales fine for small boards |

## 📁 Project structure

```
DrawSpace/
├── app/                     ← 🌐 static frontend (served at /)
│   ├── index.html           # Whiteboard app
│   ├── login.html           # Sign in / create account
│   ├── css/
│   │   ├── base.css         # Design tokens, buttons, fields, dialogs
│   │   ├── auth.css         # Login page
│   │   └── app.css          # Sidebar, topbar, canvas, toolbar
│   ├── js/
│   │   ├── api.js           # fetch wrapper + CSRF + safe redirects
│   │   ├── auth.js          # Login / registration UI
│   │   ├── draw.js          # Canvas, tools, polling, dialogs
│   │   └── ui.js            # Toasts, initials, busy states
│   ├── assets/favicon.svg
│   └── .htaccess            # Apache security headers
│
├── api/                     ← 🔒 PHP endpoints (served at /api/*)
│   ├── bootstrap.php        # Env, error handler, JSON helpers, headers
│   ├── database.php         # PDO connection + automatic schema
│   ├── auth.php             # Passwords, sessions, CSRF, rate limits
│   ├── room_service.php     # Board lookups/validation + stroke helpers
│   ├── register.php         # POST /api/register
│   ├── login.php            # POST /api/login
│   ├── logout.php           # POST /api/logout
│   ├── me.php               # GET  /api/me
│   ├── rooms.php            # GET/POST/PATCH/DELETE /api/rooms
│   ├── load.php             # GET  /api/load
│   ├── save.php             # POST /api/save
│   ├── undo.php             # POST /api/undo
│   └── clear.php            # POST /api/clear
│
├── storage/                 # 🔒 SQLite database (deny-all .htaccess)
├── router.php               # Local PHP built-in server router
├── .htaccess                # Apache: deny secrets, clean URLs, headers
├── vercel.json              # Vercel routes, headers, PHP runtime
├── .env.example
├── .env                     # your secrets (git-ignored)
└── README.md
```

## 🔌 API

All authenticated endpoints use the `drawspace_session` HttpOnly cookie. State-changing
requests must include the per-session CSRF token in `X-CSRF-Token` (the browser app gets it
from `/api/me`). Errors are JSON: `{ "error": "…" }`.

| Method   | Endpoint        | Body                     | Description                                          |
| -------- | --------------- | ------------------------ | ---------------------------------------------------- |
| `POST`   | `/api/register` | `{username,email,password}` | Create an account and a first board, issue a session |
| `POST`   | `/api/login`    | `{username,password}`    | Rotate and issue a session                           |
| `POST`   | `/api/request-reset` | `{email}`            | Send a one-time password reset link                  |
| `POST`   | `/api/reset-password` | `{token,password}` | Set a new password and revoke old sessions            |
| `POST`   | `/api/logout`   | —                        | Revoke the current session                           |
| `GET`    | `/api/me`       | —                        | `{user, csrfToken, expiresAt}`                       |
| `GET`    | `/api/rooms`    | —                        | Boards owned by the current user                     |
| `POST`   | `/api/rooms`    | `{name?}`                | Create a board                                       |
| `PATCH`  | `/api/rooms`    | `{id,name}`              | Rename a board (owner)                               |
| `DELETE` | `/api/rooms`    | `{id}`                   | Delete a board + strokes (owner)                     |
| `GET`    | `/api/load`     | `?room=<id>&v=<version>` | Board snapshot, or `{unchanged:true}`                |
| `POST`   | `/api/save`     | `{room,stroke}`          | Append one stroke                                    |
| `POST`   | `/api/undo`     | `{room}`                 | Delete your latest stroke on the board               |
| `POST`   | `/api/clear`    | `{room}`                 | Delete every stroke (owner only)                     |

A stroke is:

```json
{
  "mode": "pen",
  "color": "#8b5cf6",
  "width": 6,
  "points": [
    [0.1012, 0.2244],
    [0.1983, 0.3211]
  ]
}
```

`mode` is `pen` or `eraser`, `color` is `#rrggbb`, `width` is 1–64, and points are
`[x,y]` pairs normalised to 0…1.

### Limits

- 2,500 strokes per board; 150,000 points per board
- 1,500 points per stroke
- 256 KB JSON request cap (192 KB for a stroke request)
- 40 boards per user
- Login: 6 failed attempts per username+IP / 15 min, 40 per IP / 15 min
- Sign-up: 5 per IP / hour; new boards: 10 per user / hour
- Saving: 360 strokes/min; undo: 120/min; clear: 10/min

## 🛡️ Security

This project is deliberately small, but the security model is not an afterthought:

**Passwords & accounts**

- Passwords are hashed with **Argon2id** when available, otherwise **bcrypt cost 12**
- Length policy (10–72 bytes), common-password and repetition checks
- Usernames normalised and restricted to `[a-z0-9_]{3,20}`
- Login failures use a generic message, run a dummy `password_verify()` for unknown users,
  and are rate-limited by account + IP
- Hashes are upgraded automatically when the algorithm/cost changes

**Sessions**

- 256-bit random tokens; only their **SHA-256 hashes** are stored in the database
- `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, `Path=/`
- New session on every login (session-fixation defence); logout deletes the DB row
- Absolute expiry (default 14 days), periodic cleanup of expired rows

**CSRF & request origin**

- Per-session synchroniser token, required in `X-CSRF-Token` for every write
- `Origin`/`Referer` must match the host when present
- `SameSite=Lax` cookies as defence in depth

**Input & data**

- PDO prepared statements everywhere, native prepares, strict SQL mode
- Body-size caps, JSON shape checks, finite/out-of-bounds coordinate rejection
- Strokes are re-encoded server-side — the raw request body is never stored
- Board ids are 72-bit random capability tokens; owner-only actions are checked server-side
- Undo only deletes the caller's own stroke; clear/rename/delete are owner-only

**Browser hardening**

- Strict CSP (`script-src 'self'`, no inline scripts/styles), `nosniff`, `DENY` framing,
  `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS
- API responses are `no-store` and never echo database errors
- All user text is rendered with `textContent`; only static icon markup uses `innerHTML`
- Session/CSRF tokens never touch `localStorage`

**Operational**

- Secrets live in `.env` (git-ignored); Apache denies dotfiles and `storage/`
- Database user should only have rights on the app database
- Serve over HTTPS in production
- Real exceptions go to the PHP error log, not the browser

### Not implemented (yet)

- Email verification, 2FA, passkeys
- Per-board membership/roles beyond owner vs. link-holder
- Audit log of who cleared a board

Password recovery is available at `/reset`. New accounts must provide a recovery
email. Configure `RESEND_API_KEY`, `MAIL_FROM`, and `APP_URL` in the deployment
environment. Resend's `onboarding@resend.dev` sender is suitable for testing
only and can deliver only to the Resend account email; verify a domain before
sending recovery links to other users.

## 📜 License

Distributed under the **MIT License** — see [`LICENSE`](LICENSE) for details.

---

<div align="center">

**⭐ If this project makes you smile, star the repo — it helps more people find it! ⭐**

<sub>Built with ☕ and `<canvas>` by <b>Nandini</b> • 2026</sub>

</div>
