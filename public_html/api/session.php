<?php
/**
 * session.php — Mints a short-lived Decart CLIENT TOKEN (ek_...) so the
 * permanent key (dct_...) never reaches the browser.
 *
 * Endpoint (confirmed against the live API and the official SDK source):
 *   POST https://api.decart.ai/v1/client/tokens
 *   Header: x-api-key: <permanent dct_... key>
 *   Body:   {"expiresIn": <secs>, "allowedModels": ["<model>"], ...}
 *
 * BILLING MODEL — why this differs from the old build:
 * The old flow only charged in charge.php *after* the stream ended, so a user
 * who closed the tab was never billed, and could restart forever on the same
 * balance. Here we place a HOLD up front (debit the whole projected session),
 * then refund the unused remainder when the client reports its real duration.
 * Worst case for us now is that we over-charge and refund late — never free AI.
 *
 * Actions:
 *   POST ?action=start   { model }            -> ek_ token + hold
 *   POST ?action=stop    { holdId, seconds }  -> settle + refund remainder
 *   POST ?action=beat    { holdId, seconds }  -> keep-alive settle (long sessions)
 */
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'start';
$input  = body_json();
$user   = require_login();

switch ($action) {

// ─── START ────────────────────────────────────────────────────────────────────
case 'start': {
    rate_limit('session_start', 20, 600);

    $model = trim($input['model'] ?? '');
    $cost  = model_cost($model);
    if (!$cost || ($cost['type'] ?? '') !== 'realtime' || empty($cost['perSecond'])) {
        json_err('Invalid realtime model.');
    }
    $perSecond = (int)$cost['perSecond'];

    $apiKey = cfg('decartApiKey');
    if (empty($apiKey))                       json_err('AI API key not configured. Set it in the admin panel.', 500);
    if (strpos($apiKey, '…') !== false)        json_err('API key looks masked — re-enter it in admin settings.', 500);

    // Decart rejects maxSessionDuration below 10s, so a balance that can't
    // cover 10 seconds can't open a session at all — say so in credits, not
    // seconds, so the user knows exactly how much to top up.
    define('MIN_SESSION_SECS', 10);
    $minCredits = MIN_SESSION_SECS * $perSecond;

    // Seconds of usage held as a deposit while a stream runs. Sized to comfortably
    // exceed the client's heartbeat interval, so an abandoned session is still
    // covered for the time it went unreported.
    define('DEPOSIT_SECS', 60);

    // How long can this balance afford? Cap the single-session hold so one
    // session can't lock a huge balance; the client can simply start another.
    $fresh = find_user_by_id($user['id']);
    $affordable = (int)floor($fresh['points'] / $perSecond);
    if ($affordable < MIN_SESSION_SECS) {
        json_err("Not enough credits — this model needs at least {$minCredits} "
            . "credits to start (you have {$fresh['points']}). Please top up.", 402);
    }

    // How long the provider will let this session run. The token carries this
    // as a hard cap, so it also bounds what the customer can ever spend here.
    $maxSecs = max(MIN_SESSION_SECS, min($affordable, 3600));

    // Take only a small DEPOSIT up front, not the whole affordable session.
    // Reserving everything made a 4,600-credit balance read as "2" the instant
    // the stream started, which looks indistinguishable from being robbed. The
    // deposit exists purely to cover the last unbilled seconds if the customer
    // vanishes between heartbeats; live usage is billed incrementally instead.
    $depositSecs = min(DEPOSIT_SECS, $maxSecs);
    $deposit     = $depositSecs * $perSecond;

    // Debit atomically, refusing to overdraft: two concurrent starts would
    // otherwise each debit a clamped amount and later refund in full.
    $balanceAfter = debit_points($fresh['id'], $deposit);
    if ($balanceAfter === false) json_err('Not enough credits — another session may have just used them.', 402);
    if ($balanceAfter === null)  json_err('Account error.', 500);

    $holdId = bin2hex(random_bytes(8));
    $_SESSION['holds'][$holdId] = [
        'model' => $model, 'perSecond' => $perSecond,
        'deposit' => $deposit, 'maxSecs' => $maxSecs,
        // `collected` is the cash actually taken from the balance for this
        // session and starts at the deposit; `billed` is what the elapsed time
        // says is owed. Settlement returns the difference.
        'collected' => $deposit, 'billed' => 0,
        'startedAt' => time(),
    ];

    // Token TTL: session length + headroom so it can't expire mid-stream.
    $ttl = max(300, min(3600, $maxSecs + 120));

    $payload = [
        'expiresIn'     => $ttl,
        'allowedModels' => [$model],
        'constraints'   => ['realtime' => ['maxSessionDuration' => $maxSecs]],
    ];
    $origins = cfg('allowedOrigins');
    if (!empty($origins) && is_array($origins)) $payload['allowedOrigins'] = array_values($origins);

    $ch = curl_init('https://api.decart.ai/v1/client/tokens');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp    = curl_exec($ch);
    $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // Any failure => release the hold immediately, the user spends nothing.
    if ($resp === false) {
        adjust_points($fresh['id'], $deposit);
        unset($_SESSION['holds'][$holdId]);
        json_err('Could not reach the AI service: ' . $curlErr, 502);
    }

    $tok = json_decode($resp, true) ?: [];
    // The API has shipped this field as both `apiKey` and `token` — accept either.
    $clientToken = $tok['apiKey'] ?? $tok['token'] ?? null;

    if ($http < 200 || $http >= 300 || !$clientToken) {
        adjust_points($fresh['id'], $deposit);
        unset($_SESSION['holds'][$holdId]);
        $detail = $tok['error'] ?? $tok['message'] ?? substr((string)$resp, 0, 200);
        json_err('AI auth failed (' . $http . '): ' . $detail, 502);
    }

    json_out([
        'ok'         => true,
        'apiKey'     => $clientToken,
        'holdId'     => $holdId,
        'maxSeconds' => $maxSecs,
        'perSecond'  => $perSecond,
        'deposit'    => $deposit,
        'balance'    => $balanceAfter,
        'expiresAt'  => $tok['expiresAt'] ?? null,
    ]);
}

// ─── SETTLE (stop / heartbeat) ────────────────────────────────────────────────
case 'stop':
case 'beat': {
    $holdId = trim($input['holdId'] ?? '');
    $secs   = max(0, (int)($input['seconds'] ?? 0));
    $hold   = $_SESSION['holds'][$holdId] ?? null;
    if (!$hold) json_out(['ok' => true, 'balance' => $user['points'], 'note' => 'no active hold']);

    // Never bill beyond the cap the provider was given.
    $secs = min($secs, $hold['maxSecs']);
    $due  = $secs * $hold['perSecond'];

    // Bill only what hasn't been billed by an earlier heartbeat, so the balance
    // ticks down as the stream runs rather than lurching at the end.
    $newlyDue  = max(0, $due - $hold['billed']);
    $balance   = (int)$user['points'];
    $exhausted = false;

    if ($newlyDue > 0) {
        $after = debit_points($user['id'], $newlyDue);
        if ($after !== false && $after !== null) {
            // Normal path: the increment was paid in full.
            $took    = $newlyDue;
            $balance = (int)$after;
        } else {
            // Out of credit mid-stream: sweep whatever is left and signal a stop.
            $took    = $balance;                     // balance before the sweep
            if ($took > 0) debit_points($user['id'], $took);
            $balance = 0;
            $exhausted = true;
        }
        $_SESSION['holds'][$holdId]['collected'] = (int)$hold['collected'] + $took;
        $_SESSION['holds'][$holdId]['billed']    = $due;
    }

    if ($action === 'beat') {
        json_out(['ok' => true, 'billedSoFar' => $due, 'balance' => $balance, 'exhausted' => $exhausted]);
    }

    // Stop: settle up. `collected` is the cash actually taken for this session
    // (deposit + every increment); anything beyond what was used goes back.
    // Refunding the deposit blindly would over-refund a session that ran the
    // balance dry, because the deposit had already paid for real usage.
    $collected = (int)$_SESSION['holds'][$holdId]['collected'];
    $refund    = max(0, $collected - $due);
    if ($refund > 0) $balance = adjust_points($user['id'], $refund);
    if ($due > 0) log_tx($user['id'], 'spend', -min($due, $collected), "Realtime {$hold['model']} — {$secs}s");
    unset($_SESSION['holds'][$holdId]);

    // Low-balance nudge
    $threshold = (int)(cfg('lowBalanceThreshold') ?? 5);
    if (cfg('notifyOnLowBalance') && $balance <= $threshold && $due > 0) {
        send_email($user['email'], 'Your balance is running low',
            email_shell('Balance running low',
                "<p style='color:#9aa3b8'>Hi " . htmlspecialchars($user['name'])
                . ", you're down to <strong style='color:#eef1f8'>{$balance} points</strong>. Top up to keep creating.</p>"));
    }

    json_out(['ok' => true, 'charged' => $due, 'refunded' => $refund, 'balance' => $balance]);
}

default: json_err('Unknown action', 404);
}
