# Eclipse Creator Studio — Windows desktop app

An Electron desktop client for the Eclipse Creator Studio website. Real-time AI video with proper device selection, Picture-in-Picture, an OBS-ready output window, local recording and global hotkeys.

It talks to the same API as the website, so accounts, credits and activation keys are shared — nothing separate to manage.

---

## Building the Windows installer

You need [Node.js](https://nodejs.org) 18+ installed.

```bash
cd desktop
npm install
npm run dist
```

The installer and portable `.exe` land in `desktop/dist/`:

- `EclipseCreatorStudio-1.0.0-x64.exe` — installer (creates a Start-menu and desktop shortcut)
- The portable build runs with no installation at all — handy for testing

To run it in development without building:

```bash
npm start
```

> Building a Windows `.exe` is easiest **on Windows**. On macOS or Linux you'd need Wine, so if you only have a Mac, use a Windows machine or a CI runner for the packaging step.

---

## First run

1. **Activate** — the app asks for an `ECLPS-…` key. Generate keys in your admin panel under **Desktop keys**. Each key binds to one computer.
2. **Sign in** with an Eclipse account (the same login as the website).
3. Pick a camera, a microphone and a model, then **Start live stream**.

The server address defaults to `https://eclipselivecam.online` and can be changed under **Settings** if you ever move hosts.

---

## Features

| | |
|---|---|
| **Device selection** | Explicit camera and microphone pickers, plus a camera-quality override (360p → 1080p) |
| **Live prompting** | Style presets and a free-text prompt you can change mid-stream |
| **Picture-in-Picture** | Floats the AI output over any other app |
| **OBS output** | A separate chrome-free window holding only the AI feed — capture it as a Window Capture source, then use OBS Virtual Camera anywhere |
| **Recording** | Records the AI output to a `.webm` file on disk |
| **Global hotkeys** | `Ctrl+Shift+S` start/stop · `Ctrl+Shift+P` Picture-in-Picture · `Ctrl+Shift+R` record — these fire even when the app is behind OBS or a game |
| **Credit meter** | Elapsed time, credits spent, and remaining session credit, updating live |

---

## Streaming to OBS

1. Start the stream, then click **OBS output** — a clean window opens with just the AI feed.
2. In OBS add a **Window Capture** source and pick that window.
3. Click **Start Virtual Camera** in OBS.
4. In Zoom, Meet or Discord, choose **OBS Virtual Camera** as your webcam.

---

## How it connects

```
Desktop app                    Your PHP server              Decart
───────────                    ───────────────              ──────
activate.php  ───────────▶     validates the key
auth.php      ───────────▶     signs in, sets a session cookie
session.php   ───────────▶     holds credits, mints a token ─────▶ ek_… token
     ◀───────  ek_… token  ◀───────────────────────────────────
realtime.connect(camera) ──────────────────────────────────────▶ live WebRTC
```

Two deliberate choices worth knowing:

- **API calls go through the Electron main process**, not the page. The window is loaded from `file://`, so a normal browser fetch would be blocked by CORS and would not carry the PHP session cookie. Electron's `net` module shares the app's cookie jar, so signing in behaves exactly like a browser.
- **The SDK is bundled locally** rather than fetched from a CDN. A desktop app that breaks because a third-party host is unreachable isn't acceptable, and an ES import failure would silently abort the whole renderer.

---

## Important: allowed origins

If you set **Allowed origins** in the website's admin panel, minted tokens are locked to those web addresses and **the desktop app will be rejected** — it has no web origin. Leave that field empty if you want the desktop app to work.

---

## Troubleshooting

**"Could not reach the server"** — check the address under Settings. It must include `https://` and match the site the customer's account is on.

**"This device is no longer activated"** — the key was revoked in the admin panel, or the app was moved to a new computer. Issue a new key, or revoke the old one to free it.

**Camera list is empty** — Windows privacy settings: *Settings → Privacy & security → Camera* → allow desktop apps.

**Stream won't start, "not enough credits"** — the provider enforces a 10-second minimum session, so a customer needs at least `10 × the per-second rate` in credits.
