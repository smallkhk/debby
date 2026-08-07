# Eclipse Creator Studio

Real-time AI video for the browser — live restyling, virtual try-on, Picture-in-Picture and OBS-ready output — with accounts, credits and USDT top-ups.

**Pure PHP + static HTML.** No Node, no Composer, no build step. Upload the folder to cPanel and it runs.

---

## Why PHP and not Node

cPanel *does* offer Node, but it runs behind Phusion Passenger, which loads your startup file with `require()` (CommonJS). The official Decart SDK is **ESM-only**, so a Node backend throws `Cannot use import statement outside a module` on shared hosting. PHP sidesteps that entirely — and PHP is enabled on every cPanel plan.

The browser still uses the official Decart SDK (loaded from a CDN), so the AI features are unchanged. PHP only mints tokens.

---

## How it works

```
Browser                          Your PHP server                Decart
───────                          ───────────────                ──────
POST api/session.php ────────▶   holds credits, then
  ?action=start                  POST /v1/client/tokens ──────▶  mints ek_… token
       ◀──────────  ek_… token + holdId  ◀──────────────────────
createDecartClient({apiKey: ek_…})
realtime.connect(camera) ─────────────────────────────────────▶  live WebRTC
       ◀──────────  transformed video  ◀───────────────────────
POST api/session.php ────────▶   bills seconds used,
  ?action=stop                   refunds the remainder
```

Two things worth noting:

- **Your permanent key never reaches the browser.** It stays in `data/settings.json` on the server; the browser only ever gets a short-lived `ek_…` token, scoped to one model and (optionally) your domains.
- **The video never touches your server.** The browser streams directly to Decart over WebRTC. Your cPanel box does one small HTTPS call per session, so shared-hosting limits won't throttle the video.

---

## Deploying to cPanel (Namecheap)

1. **Upload.** Put the *contents* of `public_html/` into your domain's document root (usually `public_html`, or `public_html/subdomain` for a subdomain). Zip → upload → Extract in File Manager is fastest.
2. **Permissions.** `data/` must be writable by PHP — `0755` is usually right (`0775` if PHP runs as a different user). The app creates its own JSON stores on first request.
3. **Enable SSL.** cPanel → **SSL/TLS Status** → run **AutoSSL**. Then uncomment the "Force HTTPS" block in `.htaccess`.
   **This is not optional** — browsers block camera and microphone access on plain `http://`, so the Studio silently fails without it.
4. **Claim the admin account.** Visit `https://yourdomain.com/admin.html`. The first visit lets you set the admin username and password; after that it locks and can never be re-claimed.
5. **Add your Decart API key** in the admin panel (Settings → AI provider). Get one at [platform.decart.ai](https://platform.decart.ai).
6. **Set your wallets and pricing** — BSC and/or Tron receiving addresses, credits per USDT, and per-model rates.
7. **Configure SMTP** so signup and password-reset codes actually send. Without it, registration OTPs never arrive.

### Verify the deploy

| Check | Expected |
| --- | --- |
| `https://yourdomain.com/data/users.json` | **403 Forbidden** |
| `https://yourdomain.com/admin.html` | Admin gate loads |
| Studio → Start | Camera preview + AI output |

If `data/users.json` downloads instead of 403, your host is ignoring `.htaccess` — **stop**, move `data/` above the web root, and update `DATA_DIR` in `api/config.php`.

---

## Credits model

- Credits are bought with USDT (BEP-20 or TRC-20) and verified **on-chain** — contract, recipient, amount and confirmations are all checked before crediting, and each TXID can only ever be used once.
- Realtime models bill **per second**; batch jobs bill a flat rate.
- Starting a stream places a **hold** for the full session it could afford. Stopping bills only the seconds used and refunds the rest.

That hold matters: billing only at the end (the obvious approach) means a user who closes the tab is never charged and can restart forever on the same balance. Holding up front makes the worst case an over-charge that gets refunded — never free AI on your bill.

Because Decart enforces a **10-second minimum session**, a user needs at least `10 × perSecond` credits to start. Keep the signup bonus above that for your cheapest model or new users can't stream at all. (Default: 120 credits.)

---

## Layout

```
public_html/
  index.html         landing + pricing
  studio.html        realtime studio (camera/mic pickers, presets, PiP, OBS)
  output.html        chrome-free window for OBS Window Capture
  login/register/forgot/topup/activate/admin .html
  assets/css/app.css design system
  assets/js/app.js   shared API client, nav, toasts
  assets/js/studio.js realtime session + metering
  api/
    config.php       settings, JSON stores, atomic credit helpers, SMTP
    auth.php         OTP registration, login, password reset
    session.php      credit hold + Decart token minting + settlement
    payments.php     USDT verification (BSC + Tron), anti-replay
    job.php          batch image/video jobs with auto-refund on failure
    admin.php        admin auth, settings, users, stats
    activate.php     desktop activation keys + device binding
  data/              auto-created JSON stores (blocked from the web)
```

---

## Security notes

- Admin password is stored as a **bcrypt hash**, never plaintext.
- The Decart key and SMTP password are never returned by the settings API — the panel only shows whether they're set.
- Spends use an atomic, overdraft-refusing debit, so concurrent requests can't mint free credits.
- `data/` is denied by `.htaccess`, with a second rule blocking `*.json` site-wide as backup.
- Per-IP rate limits on login, signup, password reset, activation and session start.

**Back up `data/` before any redeploy — those JSON files are your database.**
