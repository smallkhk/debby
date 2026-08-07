Eclipse Creator Studio — data directory
=======================================

The application creates and manages these files automatically on first run:

  settings.json      API key, pricing, wallets, SMTP, admin password hash
  users.json         accounts, bcrypt password hashes, credit balances
  payments.json      crypto payments + consumed TXIDs (anti-replay ledger)
  transactions.json  credit ledger (signups, top-ups, spend)
  keys.json          desktop activation keys + bound device tokens
  ratelimit.json     per-IP request counters
  tmp/               short-lived generated image results (auto-swept hourly)

DO NOT delete or hand-edit these while the site is live — writes are flock()'d
and a partial edit can corrupt a store.

SECURITY
--------
The .htaccess here denies all web access. Verify it works after deploying by
visiting https://yourdomain.com/data/users.json — you MUST get 403 Forbidden.
If you see JSON instead, your host is ignoring .htaccess: stop and move this
folder above the web root, then update DATA_DIR in api/config.php.

PERMISSIONS
-----------
This folder must be writable by PHP: 0755 is usually right on cPanel
(0775 if PHP runs as a different user than the file owner). Files: 0644.

BACKUPS
-------
These JSON files ARE your database. Back them up before any redeploy — copying
the whole data/ folder is enough to preserve every account and balance.
