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
  <a href="#-api">API</a> •
  <a href="#-project-structure">Structure</a> •
  <a href="#-roadmap">Roadmap</a> •
  <a href="#-contributing">Contributing</a>
</p>

</div>

---

## ✨ Features

- 🔗 **One URL, one canvas** — anyone you send the link to lands on the exact same board
- 👥 **Multiplayer drawing** — several people can sketch at the same time
- 🔄 **Live sync** — strokes made by others appear on your screen while you watch
- 🖌️ **Simple tools** — freehand pen, color picker, brush size, clear board
- 💾 **Persistent canvas** — the board is saved, so refreshing (or coming back later) keeps the drawing
- 🪶 **Featherweight** — plain PHP + vanilla JS + CSS. No build step, no framework, no npm install
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

1. `index.php` renders the drawing surface and loads the current board state.
2. Every stroke you make is sent to `save.php`, which writes it to the database.
3. The clients poll `load.php` to pull down new strokes, so the board stays in sync for everybody.
4. Refresh the page — `load.php` replays everything, and your drawing is right where you left it.

> 🧱 This is intentionally the simplest thing that works. The polling layer is the part that
> gets swapped for **WebSockets** as the project grows — see the [Roadmap](#-roadmap).

## 🛠️ Tech stack

| Layer     | Choice        | Why                                 |
| --------- | ------------- | ----------------------------------- |
| Frontend  | Vanilla JS + Canvas API | Zero dependencies, instant load |
| Styling   | Plain CSS     | No framework tax                    |
| Backend   | PHP           | Dead simple for a small shared app  |
| Storage   | MySQL         | Reliable persistence for strokes    |
| Realtime  | Polling *(now)* → WebSockets *(soon)* | Ship today, scale tomorrow |

## 🚀 Getting started

### Prerequisites

- PHP 8.x (with PDO/MySQL extension)
- MySQL 8 (or MariaDB)
- A web server — Apache/Nginx, or just the built-in PHP dev server

### 1. Clone

```bash
git clone https://github.com/<your-username>/DrawSpace.git
cd DrawSpace
```

### 2. Create the database

```sql
CREATE DATABASE drawspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE drawspace;

CREATE TABLE strokes (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  color     VARCHAR(20)  NOT NULL,
  width     TINYINT      NOT NULL DEFAULT 3,
  path      LONGTEXT     NOT NULL,
  user_id   VARCHAR(64)  NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 3. Configure

Point `database.php` at your server:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'drawspace');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');
```

> 💡 Keep secrets out of git — see `.gitignore` and the tip in [Going to production](#-going-to-production).

### 4. Run

```bash
php -S localhost:8000
```

Open **http://localhost:8000** in two browser windows and start drawing in one — watch it show up in the other. 🎉

## 🔌 API

| Method | Endpoint     | Body / Params                     | Description                        |
| ------ | ------------ | --------------------------------- | ---------------------------------- |
| `GET`  | `index.php`  | —                                 | Renders the canvas                 |
| `GET`  | `load.php`   | `since=<stroke id>` *(optional)*  | Returns strokes drawn since `since`|
| `POST` | `save.php`   | `color`, `width`, `path`, `user`  | Persists a new stroke              |

<details>
<summary><b>Example request / response</b></summary>

```bash
curl -X POST http://localhost:8000/save.php \
  -d 'color=%237C3AED&width=4&path=10,10;20,25;35,40'
```

```json
{ "ok": true, "id": 42 }
```

```bash
curl "http://localhost:8000/load.php?since=42"
```

```json
{
  "ok": true,
  "strokes": [
    { "id": 43, "color": "#FF5733", "width": 3, "path": "5,5;15,18" }
  ]
}
```
</details>

## 📁 Project structure

```
DrawSpace/
├── index.php        # Entry point — renders the shared canvas
├── save.php         # Writes a stroke to the database
├── load.php         # Returns strokes for syncing clients
├── database.php     # DB connection (PDO)
├── css/
│   └── style.css    # Layout & tool palette
├── js/
│   └── draw.js      # Canvas drawing + sync logic
├── LICENSE          # MIT
└── README.md
```

## 🗺️ Roadmap

- [x] Shared canvas served from a single URL
- [x] Draw + persist strokes
- [ ] 🎨 Tool palette — pen, eraser, colors, brush sizes
- [ ] 🧹 Clear board / undo / redo
- [ ] 🔁 Replace polling with **WebSockets** for true instant sync
- [ ] 🖼️ Export the canvas as PNG
- [ ] 👤 Anonymous avatars so you can tell who drew what
- [ ] 📄 Multiple rooms — `/room/design`, `/room/brainstorm`
- [ ] 📝 Text tool, shapes, and an image layer
- [ ] 🛡️ Rate limiting + input validation hardening
- [ ] ⚡ Deployment (Docker + Nginx) and a live demo

> Got an idea? Open an [issue](../../issues) or a [pull request](../../pulls) — see below. 👇

## 🚀 Going to production

- Move credentials out of `database.php` into environment variables
- Serve over **HTTPS** (browsers restrict canvas/geo APIs on plain HTTP)
- Add prepared-statement validation on every `save.php` field
- Swap polling for WebSockets once stroke volume grows

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
