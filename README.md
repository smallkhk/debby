# Prism Studio

A professional, browser-based front-end for **Decart** real-time video AI (Lucy live editing, restyle, and virtual try-on). Pick a camera and mic, choose a look, and stream AI-transformed video — with **Picture-in-Picture** and an **OBS-ready output** view.

Built on the official [`@decartai/sdk`](https://github.com/DecartAI/sdk).

> **Security model:** your permanent Decart API key lives **only** on the server. Each browser session receives a short-lived, model-scoped **ephemeral token** (`ek_...`, default 5-minute TTL). The real key is never sent to any browser.

---

## Features

- 🎥 Real-time restyle & virtual try-on at 720p (Decart Lucy models)
- 🎙️ Explicit **camera** and **microphone** selection
- ⚡ Change the style mid-stream (prompt box + one-tap presets)
- 🖼️ **Picture-in-Picture** — float the AI output over any app
- 📡 **OBS output** — a clean, chrome-free window built to be captured as a Window Capture / Browser Source, then piped anywhere via OBS Virtual Camera
- 🔒 Server-side ephemeral tokens, per-IP rate limiting, and an optional access-code gate

---

## Quick start

```bash
# 1. Install
npm install

# 2. Configure
cp .env.example .env
#    then edit .env and set DECART_API_KEY (from https://platform.decart.ai)

# 3. Run
npm start
#    → http://localhost:3000
```

Open the landing page at `/`, or jump straight to the app at `/studio`.

> Camera access requires a **secure context**. `localhost` works out of the box; for any other host you must serve over **HTTPS** (put it behind a TLS-terminating proxy such as Caddy, Nginx, or a platform like Render/Fly/Railway).

---

## Configuration (`.env`)

| Variable | Purpose |
| --- | --- |
| `DECART_API_KEY` | Your permanent Decart key. Server-only. **Required.** |
| `PORT` | Port to listen on (default `3000`). |
| `ALLOWED_MODELS` | Comma-separated models this deployment offers. Tokens are scoped to these. |
| `TOKEN_TTL_SECONDS` | Lifetime of each ephemeral session token (default `300`). |
| `RATE_LIMIT_MAX` / `RATE_LIMIT_WINDOW_SECONDS` | Per-IP cap on session mints — protects your spend. |
| `ACCESS_MODE` | `open` (rate-limited public) or `code` (require an access code). |
| `ACCESS_CODES` | Comma-separated codes accepted when `ACCESS_MODE=code`. |

---

## How the pieces fit

```
Browser (studio.js)                    Server (server/index.js)         Decart
─────────────────────                  ────────────────────────         ──────
POST /api/session  ───────────────▶    tokens.create({expiresIn,
                                          allowedModels})  ───────────▶  mint ek_...
       ◀───────────  { token: ek_... }  ◀──────────────────────────────
createDecartClient({ apiKey: ek_... })
realtime.connect(camStream, …)  ─────────────────────────────────────▶  live WebRTC
       ◀───────────  transformed video stream (onRemoteStream)  ◀──────
```

The permanent key never leaves the server; the browser only ever holds a short-lived token.

---

## Stream to OBS, Zoom, Meet, Discord

1. In the Studio, **Start** a stream, then click **📡 OBS output** — a clean window opens with only the AI feed.
2. In OBS, add a **Window Capture** source and select that window.
3. Click **Start Virtual Camera** in OBS.
4. In Zoom/Meet/Discord, choose **OBS Virtual Camera** as your webcam.

(You can also just capture the whole browser tab, or use the **⛶ Fullscreen** button on the output.)

---

## Making it a business

The app is production-shaped, but the pricing tiers on the landing page are **UI only**. To actually charge:

1. **Payments** — wire the pricing buttons to Stripe Checkout or PayPal subscriptions.
2. **Gate the session endpoint** — on a successful subscription, either:
   - set `ACCESS_MODE=code` and issue each subscriber a unique access code, **or**
   - add real user auth and check the customer's plan inside `POST /api/session` before minting a token.
3. **Meter usage** — track minutes per customer and stop minting tokens once their plan's quota is spent, so your Decart bill can never exceed what you collect.
4. **Scale the limiter** — the in-memory rate limiter resets per process; move it to Redis if you run more than one instance.

> Use your **own** Decart account and key, and price so your revenue covers your Decart usage. Don't build on credentials you don't own — they can be revoked at any time, taking every paying customer down with them.

---

## Project layout

```
server/index.js      Express server: token minting, rate limiting, static hosting
public/index.html    Landing + pricing page
public/studio.html   The studio app (device pickers, presets, live preview)
public/output.html   Chrome-free output view for OBS capture
public/js/studio.js  Studio logic (SDK connect, PiP, OBS, fullscreen)
public/css/styles.css Design system
```

## License

MIT
