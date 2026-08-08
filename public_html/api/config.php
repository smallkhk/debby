<?php
/**
 * Eclipse Creator Studio — api/config.php
 * Shared bootstrap: settings store, users, sessions, email, JSON helpers.
 *
 * Runs on stock cPanel PHP 7.4+ — no Composer, no extensions beyond curl/json.
 */

// ── PATHS ────────────────────────────────────────────────────────────────────
define('DATA_DIR',      __DIR__ . '/../data');
define('USERS_FILE',    DATA_DIR . '/users.json');
define('TX_FILE',       DATA_DIR . '/transactions.json');
define('PAYMENTS_FILE', DATA_DIR . '/payments.json');
define('SETTINGS_FILE', DATA_DIR . '/settings.json');
define('KEYS_FILE',     DATA_DIR . '/keys.json');

// ── SESSION ──────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 365 * 24 * 60 * 60; // stay logged in for a year
    @ini_set('session.gc_maxlifetime', $lifetime);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');

// ── JSON STORE (file-locked) ─────────────────────────────────────────────────
function ensure_data_files() {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $seed = [
        USERS_FILE    => ['users' => []],
        TX_FILE       => ['transactions' => []],
        PAYMENTS_FILE => ['payments' => [], 'usedTxids' => []],
        SETTINGS_FILE => default_settings(),
        KEYS_FILE     => ['keys' => []],
    ];
    foreach ($seed as $file => $content) {
        if (!file_exists($file)) @file_put_contents($file, json_encode($content, JSON_PRETTY_PRINT));
    }
    // Belt and braces: if the data/.htaccess is missing, recreate it. This folder
    // holds password hashes, balances and device tokens — it must never be served.
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
    }
}

function read_store($file) {
    ensure_data_files();
    $fp = @fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($raw, true) ?: [];
}

function write_store($file, $data) {
    ensure_data_files();
    $fp = @fopen($file, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Atomically read-modify-write a store under an exclusive lock.
 *
 * The old build did find_user() ... save_user(), which leaves a gap where two
 * concurrent requests both read the same balance and the second write clobbers
 * the first — a user could spend the same credits twice. $mutator receives the
 * decoded store by reference and returns whatever the caller needs.
 */
function mutate_store($file, callable $mutator) {
    ensure_data_files();
    $fp = @fopen($file, 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $raw  = stream_get_contents($fp);
    $data = json_decode($raw, true) ?: [];
    $result = $mutator($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

// ── SETTINGS ─────────────────────────────────────────────────────────────────
function default_settings() {
    return [
        // Never ship a real key in the repo — set it in the admin panel.
        'decartApiKey'   => '',
        'adminUsername'  => 'admin',
        // Empty hash => first visit to the admin login sets the password.
        'adminPassHash'  => '',
        'brandName'      => 'Eclipse Creator Studio',
        // Must cover at least 10s on the cheapest realtime model (the provider's
        // minimum session length) or new users can't start a stream at all.
        'signupBonus'    => 120,
        'pointsPerUsdt'  => 100,
        'minUsdt'        => 20,
        // Top-up packages the customer picks before paying. `bonusPct` is extra
        // credits on top of usdt * pointsPerUsdt; it is applied server-side when
        // a payment is verified, so what the page advertises is what is granted.
        'packages' => [
            ['usdt' => 20,  'bonusPct' => 0,  'tag' => ''],
            ['usdt' => 50,  'bonusPct' => 5,  'tag' => ''],
            ['usdt' => 100, 'bonusPct' => 10, 'tag' => 'Best value'],
            ['usdt' => 250, 'bonusPct' => 15, 'tag' => ''],
        ],
        // Origins the minted Decart token is allowed to be used from.
        // Leave empty to let Decart accept any origin.
        'allowedOrigins' => [],
        'bscAddress'     => '',
        'tronAddress'    => '',
        'costs' => [
            'lucy-2.5'       => ['type' => 'realtime', 'perSecond' => 6,  'label' => 'Eclipse Live 2.5'],
            'lucy-restyle-2' => ['type' => 'realtime', 'perSecond' => 3,  'label' => 'Eclipse Restyle 2'],
            'lucy-vton-3'    => ['type' => 'realtime', 'perSecond' => 6,  'label' => 'Eclipse VTON 3'],
            'lucy-image-2'   => ['type' => 'batch',    'flat'      => 10, 'label' => 'Eclipse Image 2'],
        ],
        'smtpHost' => '', 'smtpPort' => 587, 'smtpUser' => '', 'smtpPass' => '',
        'smtpFrom' => '', 'smtpFromName' => 'Eclipse Creator Studio', 'smtpSecure' => 'tls',
        'notifyAdminEmail'    => '',
        'notifyOnSignup'      => true,
        'notifyOnPayment'     => true,
        'notifyOnLowBalance'  => true,
        'lowBalanceThreshold' => 5,
    ];
}

function get_settings() {
    ensure_data_files();
    $raw = read_store(SETTINGS_FILE);
    $defaults = default_settings();
    if (empty($raw)) return $defaults;
    $merged = array_merge($defaults, $raw);
    $merged['costs'] = array_merge($defaults['costs'], $raw['costs'] ?? []);
    return $merged;
}

function save_settings($s) { write_store(SETTINGS_FILE, $s); }

function cfg($key) {
    static $s = null;
    if ($s === null) $s = get_settings();
    return $s[$key] ?? null;
}

function model_cost($modelId) {
    $costs = cfg('costs') ?? [];
    return $costs[$modelId] ?? null;
}

/**
 * Credits granted for a verified USDT amount, including any package bonus.
 *
 * The bonus is decided here, from the amount actually confirmed on-chain —
 * never from what the browser claims was bought. A customer who pays a tier's
 * price gets that tier's bonus whether or not they clicked the tile, and
 * paying above a tier keeps that tier's rate rather than silently dropping it.
 */
function credits_for_usdt($usdt) {
    $usdt = (float)$usdt;
    $base = $usdt * (float)cfg('pointsPerUsdt');
    $bonusPct = 0.0;
    foreach ((cfg('packages') ?? []) as $p) {
        $tier = (float)($p['usdt'] ?? 0);
        // Tolerance mirrors the on-chain check: a tiny shortfall from rounding
        // or a transfer fee shouldn't drop someone out of the tier they paid for.
        if ($tier > 0 && $usdt + 0.01 >= $tier) {
            $bonusPct = max($bonusPct, (float)($p['bonusPct'] ?? 0));
        }
    }
    // Scale by (100 + pct)/100 rather than (1 + pct/100): the latter leaves
    // float dust that turned a clean 28,750 into 28,749.
    return (int)floor($base * (100 + $bonusPct) / 100 + 1e-9);
}

/**
 * Customer-facing name for a model.
 *
 * The provider's own ids (lucy-*) are never shown: this is a white-labelled
 * product and the upstream branding is not the operator's to advertise. Only
 * the raw id is ever sent upstream. An operator label set in the admin panel
 * wins; a label equal to the id counts as unset, because older migrations
 * defaulted it that way.
 */
function model_label($modelId) {
    static $known = [
        'lucy-2.1'         => 'Eclipse Live 2.1',
        'lucy-2.5'         => 'Eclipse Live 2.5',
        'lucy-restyle-2'   => 'Eclipse Restyle 2',
        'lucy-vton-3'      => 'Eclipse VTON 3',
        'lucy-vton-2'      => 'Eclipse VTON 2',
        'lucy-image-2'     => 'Eclipse Image 2',
        'lucy-2-v2v'       => 'Eclipse Video 2',
        'lucy-restyle-v2v' => 'Eclipse Restyle Video',
    ];
    $c = model_cost($modelId);
    $set = ($c['label'] ?? '') !== '' && $c['label'] !== $modelId ? $c['label'] : null;
    return $set ?? $known[$modelId] ?? ucwords(str_replace('-', ' ', $modelId));
}

// ── USERS ────────────────────────────────────────────────────────────────────
function find_user_by_email($email) {
    $s = read_store(USERS_FILE);
    foreach (($s['users'] ?? []) as $u) {
        if (strtolower($u['email']) === strtolower($email)) return $u;
    }
    return null;
}

function find_user_by_id($id) {
    $s = read_store(USERS_FILE);
    foreach (($s['users'] ?? []) as $u) if ($u['id'] === $id) return $u;
    return null;
}

function save_user($user) {
    mutate_store(USERS_FILE, function (&$s) use ($user) {
        if (!isset($s['users'])) $s['users'] = [];
        foreach ($s['users'] as $i => $u) {
            if ($u['id'] === $user['id']) { $s['users'][$i] = $user; return true; }
        }
        $s['users'][] = $user;
        return true;
    });
}

/**
 * Atomically debit points, REFUSING to overdraft.
 *
 * Use this for every spend. adjust_points() clamps a too-large debit to zero,
 * which silently takes less than asked — pair that with a later refund of the
 * full amount and the user ends up richer. Two concurrent "start stream"
 * clicks hit exactly that path, so spends must fail loudly instead.
 *
 * Returns the new balance, false if funds were insufficient, null if no user.
 */
function debit_points($userId, $amount) {
    $amount = max(0, (int)$amount);
    return mutate_store(USERS_FILE, function (&$s) use ($userId, $amount) {
        foreach (($s['users'] ?? []) as $i => $u) {
            if ($u['id'] !== $userId) continue;
            if ((int)$u['points'] < $amount) return false;
            $s['users'][$i]['points'] = (int)$u['points'] - $amount;
            return $s['users'][$i]['points'];
        }
        return null;
    });
}

/**
 * Atomically add (or subtract) points. Never lets a balance go below zero.
 * Returns the new balance, or null if the user vanished.
 *
 * Safe for credits and admin adjustments; use debit_points() for spends.
 */
function adjust_points($userId, $delta) {
    return mutate_store(USERS_FILE, function (&$s) use ($userId, $delta) {
        foreach ($s['users'] as $i => $u) {
            if ($u['id'] !== $userId) continue;
            $new = (int)$u['points'] + (int)$delta;
            if ($new < 0) $new = 0;
            $s['users'][$i]['points'] = $new;
            return $new;
        }
        return null;
    });
}

function current_user() { return empty($_SESSION['uid']) ? null : find_user_by_id($_SESSION['uid']); }
function require_login() {
    $u = current_user();
    if (!$u) json_err('Not logged in', 401);
    if (!empty($u['banned'])) json_err('This account has been suspended.', 403);
    return $u;
}
function require_admin() { if (empty($_SESSION['is_admin'])) json_err('Admin access required', 403); }

function public_user($u) {
    return ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'points' => (int)$u['points']];
}

// ── TRANSACTION LOG ──────────────────────────────────────────────────────────
function log_tx($userId, $type, $points, $note = '') {
    mutate_store(TX_FILE, function (&$s) use ($userId, $type, $points, $note) {
        if (!isset($s['transactions'])) $s['transactions'] = [];
        $s['transactions'][] = [
            'id' => bin2hex(random_bytes(8)), 'userId' => $userId, 'type' => $type,
            'points' => $points, 'note' => $note, 'time' => date('c'),
        ];
        // Keep the log bounded so the JSON file can't grow forever on shared hosting.
        if (count($s['transactions']) > 5000) {
            $s['transactions'] = array_slice($s['transactions'], -5000);
        }
        return true;
    });
}

// ── JSON RESPONSES ───────────────────────────────────────────────────────────
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function json_err($msg, $code = 400) { json_out(['ok' => false, 'error' => $msg], $code); }
function body_json() { return json_decode(file_get_contents('php://input'), true) ?: []; }

// ── RATE LIMITING (per IP, file-backed) ──────────────────────────────────────
function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) return trim(explode(',', $_SERVER[$h])[0]);
    }
    return 'unknown';
}

function rate_limit($bucket, $max, $windowSecs) {
    $file = DATA_DIR . '/ratelimit.json';
    if (!file_exists($file)) @file_put_contents($file, '{}');
    $key = $bucket . '|' . client_ip();
    $allowed = mutate_store($file, function (&$d) use ($key, $max, $windowSecs) {
        $now = time();
        foreach ($d as $k => $times) {
            $d[$k] = array_values(array_filter($times, fn($t) => $now - $t < $windowSecs));
            if (!$d[$k]) unset($d[$k]);
        }
        $mine = $d[$key] ?? [];
        if (count($mine) >= $max) return false;
        $mine[] = $now;
        $d[$key] = $mine;
        return true;
    });
    if (!$allowed) json_err('Too many requests. Please slow down and try again shortly.', 429);
}

// ── EMAIL ────────────────────────────────────────────────────────────────────
function smtp_cmd($fp, $cmd) {
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    $r = '';
    while (($line = fgets($fp, 512)) !== false) {
        $r .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $r;
}

function send_email($to, $subject, $htmlBody) {
    try {
        $s = get_settings();
        if (empty($s['smtpHost']) || empty($s['smtpUser'])) {
            if (!empty($s['smtpFrom'])) {
                @mail($to, $subject, strip_tags($htmlBody),
                    "From: {$s['smtpFromName']} <{$s['smtpFrom']}>\r\n"
                    . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n");
            }
            return;
        }
        smtp_send($s, $to, $subject, $htmlBody);
    } catch (\Throwable $e) {
        error_log('[Eclipse] Email error: ' . $e->getMessage());
    }
}

function smtp_send($s, $to, $subject, $html) {
    $secure = strtolower($s['smtpSecure'] ?? 'tls');
    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $fp = @fsockopen($prefix . $s['smtpHost'], (int)($s['smtpPort'] ?? 587), $errno, $errstr, 10);
    if (!$fp) return;
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    smtp_cmd($fp, null);
    smtp_cmd($fp, 'EHLO ' . $host);
    if ($secure === 'tls') {
        smtp_cmd($fp, 'STARTTLS');
        @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        smtp_cmd($fp, 'EHLO ' . $host);
    }
    smtp_cmd($fp, 'AUTH LOGIN');
    smtp_cmd($fp, base64_encode($s['smtpUser']));
    smtp_cmd($fp, base64_encode($s['smtpPass'] ?? ''));
    smtp_cmd($fp, 'MAIL FROM:<' . $s['smtpFrom'] . '>');
    smtp_cmd($fp, 'RCPT TO:<' . $to . '>');
    smtp_cmd($fp, 'DATA');
    $from = ($s['smtpFromName'] ?? 'Eclipse') . ' <' . $s['smtpFrom'] . '>';
    $msg  = "From: $from\r\nTo: $to\r\nSubject: $subject\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n.";
    smtp_cmd($fp, $msg);
    smtp_cmd($fp, 'QUIT');
    fclose($fp);
}

function notify_admin($subject, $body) {
    $email = cfg('notifyAdminEmail');
    if ($email) send_email($email, $subject, $body);
}

function email_shell($title, $inner) {
    $brand = htmlspecialchars(cfg('brandName') ?: 'Eclipse Creator Studio');
    return "<div style='font-family:Inter,Segoe UI,sans-serif;max-width:480px;margin:0 auto;"
        . "background:#0d0f1a;color:#eef1f8;border-radius:16px;padding:32px'>"
        . "<div style='text-align:center;font-size:1.1rem;font-weight:800;margin-bottom:20px'>{$brand}</div>"
        . "<h2 style='font-size:1.15rem;text-align:center;margin:0 0 16px'>{$title}</h2>{$inner}</div>";
}

ensure_data_files();
