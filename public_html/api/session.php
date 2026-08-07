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

    // How long can this balance afford? Cap the single-session hold so one
    // session can't lock a huge balance; the client can simply start another.
    $fresh = find_user_by_id($user['id']);
    $affordable = (int)floor($fresh['points'] / $perSecond);
    if ($affordable < MIN_SESSION_SECS) {
        json_err("Not enough credits — this model needs at least {$minCredits} "
            . "credits to start (you have {$fresh['points']}). Please top up.", 402);
    }

    $maxSecs   = max(MIN_SESSION_SECS, min($affordable, 3600));
    $holdCost  = $maxSecs * $perSecond;

    // Debit the full hold atomically, before handing out any token. This must
    // refuse to overdraft: two concurrent starts would otherwise each debit a
    // clamped amount and later refund the full hold, minting free credits.
    $balanceAfter = debit_points($fresh['id'], $holdCost);
    if ($balanceAfter === false) json_err('Not enough credits — another session may have just used them.', 402);
    if ($balanceAfter === null)  json_err('Account error.', 500);

    $holdId = bin2hex(random_bytes(8));
    $_SESSION['holds'][$holdId] = [
        'model' => $model, 'perSecond' => $perSecond,
        'held' => $holdCost, 'maxSecs' => $maxSecs,
        'settled' => 0, 'startedAt' => time(),
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
        adjust_points($fresh['id'], $holdCost);
        unset($_SESSION['holds'][$holdId]);
        json_err('Could not reach the AI service: ' . $curlErr, 502);
    }

    $tok = json_decode($resp, true) ?: [];
    // The API has shipped this field as both `apiKey` and `token` — accept either.
    $clientToken = $tok['apiKey'] ?? $tok['token'] ?? null;

    if ($http < 200 || $http >= 300 || !$clientToken) {
        adjust_points($fresh['id'], $holdCost);
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
        'held'       => $holdCost,
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

    // Never bill beyond what was held.
    $secs = min($secs, $hold['maxSecs']);
    $due  = $secs * $hold['perSecond'];

    // Charge only the delta not already settled by an earlier heartbeat.
    $newlyDue = max(0, $due - $hold['settled']);

    if ($action === 'beat') {
        $_SESSION['holds'][$holdId]['settled'] = $due;
        json_out(['ok' => true, 'billedSoFar' => $due]);
    }

    // Stop: refund whatever the session didn't use.
    $refund = max(0, $hold['held'] - $due);
    $balance = $user['points'];
    if ($refund > 0) $balance = adjust_points($user['id'], $refund);
    if ($due > 0) log_tx($user['id'], 'spend', -$due, "Realtime {$hold['model']} — {$secs}s");
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
