<?php
/**
 * admin.php — admin login + settings, users, stats.
 *
 * The old build kept the admin password as PLAINTEXT in settings.json, so
 * anyone who read that file owned the panel. Here it's a bcrypt hash, and the
 * very first login claims the account (set your password once, then it locks).
 */
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$input  = body_json();

switch ($action) {

// ─── STATUS: is an admin password set yet? ────────────────────────────────────
case 'status': {
    json_out(['ok' => true, 'claimed' => cfg('adminPassHash') !== '', 'isAdmin' => !empty($_SESSION['is_admin'])]);
}

// ─── CLAIM: first-run setup of the admin password ─────────────────────────────
case 'claim': {
    $s = get_settings();
    if ($s['adminPassHash'] !== '') json_err('Admin account is already set up.', 409);
    $user = trim($input['username'] ?? '');
    $pass = $input['password'] ?? '';
    if (strlen($user) < 3) json_err('Username must be at least 3 characters.');
    if (strlen($pass) < 8) json_err('Admin password must be at least 8 characters.');
    $s['adminUsername'] = $user;
    $s['adminPassHash'] = password_hash($pass, PASSWORD_BCRYPT);
    save_settings($s);
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    json_out(['ok' => true]);
}

case 'login': {
    rate_limit('admin_login', 10, 900);
    $s = get_settings();
    if ($s['adminPassHash'] === '') json_err('Admin account is not set up yet.', 409);
    $user = trim($input['username'] ?? '');
    $pass = $input['password'] ?? '';
    if (!hash_equals(strtolower($s['adminUsername']), strtolower($user))
        || !password_verify($pass, $s['adminPassHash'])) {
        json_err('Invalid admin credentials.', 401);
    }
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    json_out(['ok' => true]);
}

case 'logout': {
    unset($_SESSION['is_admin']);
    json_out(['ok' => true]);
}

// ─── SETTINGS ─────────────────────────────────────────────────────────────────
case 'get_settings': {
    require_admin();
    $s = get_settings();
    // Never send secrets back to the browser — show only whether they're set.
    $s['decartApiKeySet'] = !empty($s['decartApiKey']);
    $s['smtpPassSet']     = !empty($s['smtpPass']);
    unset($s['decartApiKey'], $s['smtpPass'], $s['adminPassHash']);
    json_out(['ok' => true, 'settings' => $s]);
}

case 'save_settings': {
    require_admin();
    $s = get_settings();
    $incoming = $input['settings'] ?? [];

    // Only these keys may be written from the panel.
    $allowed = ['brandName', 'signupBonus', 'pointsPerUsdt', 'minUsdt', 'bscAddress', 'tronAddress',
        'costs', 'allowedOrigins', 'smtpHost', 'smtpPort', 'smtpUser', 'smtpFrom', 'smtpFromName',
        'smtpSecure', 'notifyAdminEmail', 'notifyOnSignup', 'notifyOnPayment', 'notifyOnLowBalance',
        'lowBalanceThreshold', 'adminUsername'];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $incoming)) $s[$k] = $incoming[$k];
    }
    // Secrets only overwrite when a non-empty value is supplied.
    if (!empty($incoming['decartApiKey'])) $s['decartApiKey'] = trim($incoming['decartApiKey']);
    if (!empty($incoming['smtpPass']))     $s['smtpPass']     = $incoming['smtpPass'];
    if (!empty($incoming['adminPassword'])) {
        if (strlen($incoming['adminPassword']) < 8) json_err('Admin password must be at least 8 characters.');
        $s['adminPassHash'] = password_hash($incoming['adminPassword'], PASSWORD_BCRYPT);
    }
    save_settings($s);
    json_out(['ok' => true]);
}

// ─── USERS ────────────────────────────────────────────────────────────────────
case 'users': {
    require_admin();
    $store = read_store(USERS_FILE);
    $users = array_map(fn($u) => [
        'id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'],
        'points' => (int)$u['points'], 'createdAt' => $u['createdAt'] ?? null,
        'banned' => !empty($u['banned']),
    ], $store['users'] ?? []);
    usort($users, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    json_out(['ok' => true, 'users' => $users]);
}

case 'adjust_points': {
    require_admin();
    $userId = $input['userId'] ?? '';
    $delta  = (int)($input['delta'] ?? 0);
    if (!$userId || $delta === 0) json_err('User and a non-zero amount are required.');
    $balance = adjust_points($userId, $delta);
    if ($balance === null) json_err('User not found.', 404);
    log_tx($userId, $delta > 0 ? 'admin_credit' : 'admin_debit', $delta, 'Adjusted by admin');
    json_out(['ok' => true, 'balance' => $balance]);
}

case 'ban_user': {
    require_admin();
    $userId = $input['userId'] ?? '';
    $banned = !empty($input['banned']);
    $u = find_user_by_id($userId);
    if (!$u) json_err('User not found.', 404);
    $u['banned'] = $banned;
    save_user($u);
    json_out(['ok' => true, 'banned' => $banned]);
}

// ─── STATS ────────────────────────────────────────────────────────────────────
case 'stats': {
    require_admin();
    $users    = read_store(USERS_FILE)['users'] ?? [];
    $payments = read_store(PAYMENTS_FILE)['payments'] ?? [];
    $txs      = read_store(TX_FILE)['transactions'] ?? [];

    $approved = array_filter($payments, fn($p) => $p['status'] === 'approved');
    $spent = 0;
    foreach ($txs as $t) if ($t['type'] === 'spend') $spent += abs($t['points']);

    json_out(['ok' => true, 'stats' => [
        'users'          => count($users),
        'pointsInWallets'=> array_sum(array_map(fn($u) => (int)$u['points'], $users)),
        'pointsSpent'    => $spent,
        'payments'       => count($payments),
        'pendingPayments'=> count(array_filter($payments, fn($p) => $p['status'] === 'pending')),
        'usdtTotal'      => round(array_sum(array_map(fn($p) => (float)($p['usdtVerified'] ?? $p['usdtClaimed']), $approved)), 2),
    ]]);
}

case 'transactions': {
    require_admin();
    $txs = read_store(TX_FILE)['transactions'] ?? [];
    $txs = array_slice(array_reverse($txs), 0, 200);
    json_out(['ok' => true, 'transactions' => $txs]);
}

default: json_err('Unknown action', 404);
}
