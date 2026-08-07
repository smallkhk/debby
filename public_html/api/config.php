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
        // Origins the minted Decart token is allowed to be used from.
        // Leave empty to let Decart accept any origin.
        'allowedOrigins' => [],
        'bscAddress'     => '',
        'tronAddress'    => '',
        'costs' => [
            'lucy-2.5'       => ['type' => 'realtime', 'perSecond' => 6,  'label' => 'Lucy 2.5 — Live edit'],
            'lucy-restyle-2' => ['type' => 'realtime', 'perSecond' => 3,  'label' => 'Lucy Restyle 2'],
            'lucy-vton-3'    => ['type' => 'realtime', 'perSecond' => 6,  'label' => 'Lucy VTON 3 — Try-on'],
            'lucy-image-2'   => ['type' => 'batch',    'flat'      => 10, 'label' => 'Lucy Image 2'],
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
