<div align="center">

<!-- ─────────────── LOGO / TITLE ─────────────── -->
<h1>🎨 DrawSpace</h1>

<h3><i>Open the link. Grab a color. Draw together — in real time.</i></h3>

<p>
  A tiny, dependency-light <b>shared whiteboard</b>. Everyone who opens the same URL sees the
  same canvas, and everyone can draw on it <b>at the same time</b>.
</p>

<!-- ─────────────── BADGES ─────────────── -->
<p>
  <img src="https://img.shields.io/badge/version-0.1.0-8A2BE2?style=for-the-badge&color=7C3AED" alt="version" />
  <img src="https://img.shields.io/badge/status-early%20development-orange?style=for-the-badge" alt="status" />
  <img src="https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black" alt="JavaScript" />
  <img src="https://img.shields.io/badge/MySQL-database-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL" />
  <img src="https://img.shields.io/badge/license-MIT-green?style=for-the-badge" alt="license" />
</p>

<p>
  <a href="#-features">Features</a> •
  <a href="#-how-it-works">How it works</a> •
  <a href="#-getting-started">Getting started</a> •
  <a href="#-project-structure">Structure</a> •
  <a href="#-api">API</a> •
  <a href="#%EF%B8%8F-hosting--deployment">Hosting</a> •
  <a href="#-roadmap">Roadmap</a> •
  <a href="#-contributing">Contributing</a>
</p>

</div>

---

## ✨ Features

- 🔗 **One URL, one canvas** — anyone you send the link to lands on the exact same board
- 👥 **Multiplayer drawing** — several people can sketch at the same time
- 🔄 **Live sync** — strokes made by others appear on your screen while you watch
- 🖌️ **Zero friction** — no accounts, no build step; open the link and draw
- 💾 **Persistent canvas** — the board is saved, so refreshing (or coming back later) keeps the drawing
- 🪶 **Featherweight** — plain PHP + vanilla JS + CSS. No build step, no framework, no npm install
- 📁 **Host-ready layout** — `public/` is the web root, app logic and config stay off the internet
- 📱 **Works anywhere** — mouse or touch, desktop or phone

## 💡 How it works

```
   You ──┐                       ┌── Friend A
         │   ┌───────────────┐   │
   You ──┼──▶│  DrawSpace    │◀──┼── Friend B
         │   │  (shared board)│  │
   You ──┘   └───────┬───────┘  └── Friend C
                     │
              ┌──────▼──────┐
              │  MySQL DB   │  ← every stroke is stored
              └─────────────┘
```

1. `public/index.php` renders the drawing surface and loads the current board state.
2. Every stroke you make is `POST`ed to `public/api/save.php`, which writes it to the database.
3. Clients call `public/api/load.php` to pull down new strokes, so the board stays in sync for everybody.
4. Refresh the page — `load.php` replays everything, and your drawing is right where you left it.

> 🧱 This is intentionally the simplest thing that works. The polling layer is the part that
> gets swapped for **WebSockets** as the project grows — see the [Roadmap](#-roadmap).

## 🛠️ Tech stack

| Layer    | Choice                                | Why                                |
| -------- | ------------------------------------- | ---------------------------------- |
| Frontend | Vanilla JS + Canvas API               | Zero dependencies, instant load    |
| Styling  | Plain CSS                             | No framework tax                   |
| Backend  | PHP 8 + PDO                           | Dead simple for a small shared app |
| Storage  | MySQL                                 | Reliable persistence for strokes   |
| Config   | `.env`                                | Secrets stay out of git            |
| Realtime | Polling _(now)_ → WebSockets _(soon)_ | Ship today, scale tomorrow         |

## 📁 Project structure

```
DrawSpace/
├── public/                 ← 🌐 set this as your document root
│   ├── index.php           # Entry point — renders the shared canvas
│   ├── .htaccess           # Apache: index, hardening, pretty /save & /load
│   ├── api/
│   │   ├── save.php        # POST  — persists a stroke
│   │   └── load.php        # GET   — returns strokes for syncing clients
│   ├── css/
│   │   └── style.css       # Layout & tool palette
│   └── js/
│       └── draw.js         # Canvas drawing + sync logic
│
├── app/                    ← 🔒 never web-accessible
│   ├── database.php        # DB connection (PDO) — reads from .env
│   └── .htaccess           # Safety net: deny all access
│
├── .env.example            # 📋 copy to .env and fill in your values
├── .env                    # your secrets (git-ignored)
├── .gitignore
├── LICENSE
└── README.md
```

**Why this layout?** Everything the browser needs lives in `public/`; everything else
(`app/`) sits _outside_ the document root, so even a misconfigured server can't leak
your database credentials.

### Deployment checklist

- [ ] Document root points at `public/` (not the repository root)
- [ ] `.env` exists on the server with production values, `APP_DEBUG=false`
- [ ] MySQL user has rights to **only** the `drawspace` database
- [ ] HTTPS enabled (certbot / Let's Encrypt / your host's panel)
- [ ] `app/.htaccess` present as a second line of defence
- [ ] PHP errors not printed to the browser (`display_errors=Off`)

## 🔌 API

| Method | Endpoint        | Body / Params                     | Description                          |
| ------ | --------------- | --------------------------------- | ------------------------------------ |
| `GET`  | `/`             | —                                 | Renders the canvas                   |
| `GET`  | `/api/load.php` | —                                 | Returns the board as a strokes array |
| `POST` | `/api/save.php` | JSON `[[{x,y},…],…]` (full board) | Replaces the board state             |

> With the shipped `.htaccess`, `/save` and `/load` also work as pretty aliases.
> `save.php` rejects anything that isn't a stroke array (400), payloads over 1 MB (413),
> non-`POST` requests (405) and more than 120 saves per minute per IP (429).

<details>
<summary><b>Example request / response</b></summary>

```bash
curl -X POST http://localhost:8000/api/save.php \
  -H 'Content-Type: application/json' \
  -d '[[{"x":10,"y":10},{"x":20,"y":25},{"x":35,"y":40}]]'
```

```json
{ "ok": true }
```

```bash
curl http://localhost:8000/api/load.php
```

```json
[
  [
    { "x": 10, "y": 10 },
    { "x": 20, "y": 25 },
    { "x": 35, "y": 40 }
  ]
]
```

</details>

## 🔒 Security notes

- Credentials live in `.env`, which is **git-ignored** — commit `.env.example` only
- `app/` sits outside the document root **and** carries a deny-all `.htaccess`
- **Prepared statements** (PDO) for every query, with emulation disabled
- `save.php` **validates the payload** (stroke-array shape + 1 MB cap), stores only the
  re-encoded, validated data — never the raw request body — and is **rate-limited** per IP
- API failures return JSON with generic messages; real errors go to the **server log**, not the browser
- `index.php` sends **CSP / `X-Frame-Options` / `nosniff` / Referrer-Policy** headers (plus HSTS on HTTPS)
- Serve over **HTTPS** in production

## 🗺️ Roadmap

- [x] Shared canvas served from a single URL
- [x] Draw + persist strokes
- [x] Hosting-ready folder layout (`public/` web root, `.env` config)
- [ ] 🎨 Tool palette — pen, eraser, colors, brush sizes
- [ ] 🧹 Clear board / undo / redo
- [ ] 🔁 Replace polling with **WebSockets** for true instant sync
- [ ] 🖼️ Export the canvas as PNG
- [ ] 👤 Anonymous avatars so you can tell who drew what
- [ ] 📄 Multiple rooms — `/room/design`, `/room/brainstorm`
- [ ] 📝 Text tool, shapes, and an image layer
- [x] 🛡️ Rate limiting + input validation hardening
- [ ] 🔐 Optional write password (protect the board from strangers)
- [ ] ⚡ Docker image + live demo

> Got an idea? Open an [issue](../../issues) or a [pull request](../../pulls) — see below. 👇

## 🤝 Contributing

Contributions, issues and feature requests are welcome!

1. Fork the repo
2. Create your branch → `git checkout -b feature/awesome-thing`
3. Commit → `git commit -m "Add awesome thing"`
4. Push → `git push origin feature/awesome-thing`
5. Open a Pull Request

Please keep PRs small and focused.

## 📜 License

Distributed under the **MIT License** — see [`LICENSE`](LICENSE) for details.

---

<div align="center">

**⭐ If this project makes you smile, star the repo — it helps more people find it! ⭐**

<sub>Built with ☕ and `<canvas>` by <b>Nandini</b> • 2026</sub>

</div>
