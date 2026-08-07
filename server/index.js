import "dotenv/config";
import express from "express";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createDecartClient } from "@decartai/sdk";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, "..", "public");

// ── Config ────────────────────────────────────────────────────────────────────
const PORT = Number(process.env.PORT || 3000);
const DECART_API_KEY = process.env.DECART_API_KEY;
const ALLOWED_MODELS = (process.env.ALLOWED_MODELS || "lucy-2.5,lucy-restyle-2,lucy-vton-3")
  .split(",")
  .map((s) => s.trim())
  .filter(Boolean);
const TOKEN_TTL_SECONDS = Number(process.env.TOKEN_TTL_SECONDS || 300);
const RATE_LIMIT_MAX = Number(process.env.RATE_LIMIT_MAX || 30);
const RATE_LIMIT_WINDOW_SECONDS = Number(process.env.RATE_LIMIT_WINDOW_SECONDS || 3600);
const ACCESS_MODE = (process.env.ACCESS_MODE || "open").toLowerCase();
const ACCESS_CODES = new Set(
  (process.env.ACCESS_CODES || "")
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean)
);

if (!DECART_API_KEY) {
  console.error(
    "\n[Prism Studio] FATAL: DECART_API_KEY is not set.\n" +
      "Copy .env.example to .env and add your key from https://platform.decart.ai\n"
  );
  process.exit(1);
}

// The Decart client is created ONCE with your permanent key and lives only in
// this server process. It is never exposed to any HTTP response.
const decart = createDecartClient({ apiKey: DECART_API_KEY });

const app = express();
app.disable("x-powered-by");
app.use(express.json({ limit: "16kb" }));

// ── Tiny in-memory rate limiter (per IP) ────────────────────────────────────────
// Protects YOUR Decart spend: every session runs against your key, so we cap how
// many tokens a single visitor can mint. Swap for Redis if you run multiple nodes.
const hits = new Map(); // ip -> number[] (timestamps, ms)
function rateLimited(ip) {
  const now = Date.now();
  const windowMs = RATE_LIMIT_WINDOW_SECONDS * 1000;
  const arr = (hits.get(ip) || []).filter((t) => now - t < windowMs);
  if (arr.length >= RATE_LIMIT_MAX) {
    hits.set(ip, arr);
    return true;
  }
  arr.push(now);
  hits.set(ip, arr);
  return false;
}
// Periodic cleanup so the map doesn't grow unbounded.
setInterval(() => {
  const now = Date.now();
  const windowMs = RATE_LIMIT_WINDOW_SECONDS * 1000;
  for (const [ip, arr] of hits) {
    const kept = arr.filter((t) => now - t < windowMs);
    if (kept.length) hits.set(ip, kept);
    else hits.delete(ip);
  }
}, 60_000).unref();

function clientIp(req) {
  const fwd = req.headers["x-forwarded-for"];
  if (typeof fwd === "string" && fwd.length) return fwd.split(",")[0].trim();
  return req.socket.remoteAddress || "unknown";
}

// ── Public config for the frontend (no secrets) ────────────────────────────────
app.get("/api/config", (_req, res) => {
  res.json({
    allowedModels: ALLOWED_MODELS,
    accessMode: ACCESS_MODE, // "open" | "code"
    tokenTtlSeconds: TOKEN_TTL_SECONDS,
  });
});

// ── Mint a short-lived ephemeral token for a browser session ───────────────────
// This is the ONLY way the browser gets to talk to Decart. The returned "ek_..."
// token expires in TOKEN_TTL_SECONDS and is scoped to ALLOWED_MODELS.
app.post("/api/session", async (req, res) => {
  const ip = clientIp(req);

  if (rateLimited(ip)) {
    return res.status(429).json({
      error: "rate_limited",
      message: "Too many sessions from this address. Please try again later.",
    });
  }

  if (ACCESS_MODE === "code") {
    const code = String(req.body?.accessCode || "").trim();
    if (!code || !ACCESS_CODES.has(code)) {
      return res.status(403).json({
        error: "invalid_access_code",
        message: "A valid access code is required to start a session.",
      });
    }
  }

  const requestedModel = String(req.body?.model || "").trim();
  const model = ALLOWED_MODELS.includes(requestedModel) ? requestedModel : null;
  if (!model) {
    return res.status(400).json({
      error: "invalid_model",
      message: `Model must be one of: ${ALLOWED_MODELS.join(", ")}`,
    });
  }

  try {
    const token = await decart.tokens.create({
      expiresIn: TOKEN_TTL_SECONDS,
      // Scope the token to only the model this session will use.
      allowedModels: [model],
    });
    res.json({
      token: token.token,
      expiresIn: token.expiresIn ?? TOKEN_TTL_SECONDS,
      model,
    });
  } catch (err) {
    console.error("[Prism Studio] token mint failed:", err?.message || err);
    res.status(502).json({
      error: "token_mint_failed",
      message:
        "Could not create a session with the AI provider. Check the server's DECART_API_KEY and account status.",
    });
  }
});

app.get("/api/health", (_req, res) => res.json({ ok: true }));

// ── Static site ────────────────────────────────────────────────────────────────
app.use(
  express.static(PUBLIC_DIR, {
    extensions: ["html"],
    setHeaders: (res) => {
      res.setHeader("X-Content-Type-Options", "nosniff");
      res.setHeader("Referrer-Policy", "strict-origin-when-cross-origin");
    },
  })
);

app.listen(PORT, () => {
  console.log(`\n  Prism Studio running → http://localhost:${PORT}`);
  console.log(`  Models:  ${ALLOWED_MODELS.join(", ")}`);
  console.log(`  Access:  ${ACCESS_MODE}   Token TTL: ${TOKEN_TTL_SECONDS}s\n`);
});
