<?php
/**
 * activate.php — desktop-app activation keys (ECLPS-XXXXX-XXXXX-XXXXX).
 *
 * A key is single-use: redeeming it binds a random device_token that the app
 * stores and presents on every launch. Keys live inside data/, which the
 * .htaccess there blocks from the web — they must never be publicly listable.
 */
require_once __DIR__ . '/config.php';

$input  = body_json();
$action = $_GET['action'] ?? $input['action'] ?? '';

function load_keys() {
    $d = read_store(KEYS_FILE);
    return $d['keys'] ?? [];
}

function gen_key() {
    // Excludes I/O/0/1 so keys are safe to read aloud or retype.
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $seg = function () use ($chars) {
        $out = '';
        for ($i = 0; $i < 5; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
        return $out;
    };
    return 'ECLPS-' . $seg() . '-' . $seg() . '-' . $seg();
}

switch ($action) {

// ─── Redeem a key (public) ────────────────────────────────────────────────────
case 'validate': {
    rate_limit('activate', 20, 900);
    $key    = strtoupper(trim($input['key'] ?? ''));
    $dtoken = trim($input['device_token'] ?? '');
    if (!$key) json_err('Enter an activation key.');

    $outcome = mutate_store(KEYS_FILE, function (&$d) use ($key, $dtoken) {
        $keys = &$d['keys'];
        // Already-activated device? Let it straight through.
        if ($dtoken) {
            foreach ($keys as $k) {
                if (($k['device_token'] ?? '') === $dtoken && !empty($k['used'])) {
                    return ['ok' => true, 'device_token' => $dtoken, 'already_activated' => true];
                }
            }
        }
        foreach ($keys as $i => $k) {
            if ($k['key'] !== $key) continue;
            if (!empty($k['used'])) return ['err' => 'This key has already been used on another device.'];
            $newToken = bin2hex(random_bytes(32));
            $keys[$i]['used']         = true;
            $keys[$i]['used_at']      = date('Y-m-d H:i:s');
            $keys[$i]['device_token'] = $newToken;
            return ['ok' => true, 'device_token' => $newToken];
        }
        return ['err' => 'Invalid activation key. Please check and try again.'];
    });

    if (!$outcome)              json_err('Key store unavailable.', 500);
    if (isset($outcome['err'])) json_err($outcome['err']);
    json_out($outcome);
}

// ─── Verify a device token (public, called on app launch) ─────────────────────
case 'verify': {
    $dtoken = trim($input['device_token'] ?? $_GET['t'] ?? '');
    if (!$dtoken) json_out(['ok' => false]);
    foreach (load_keys() as $k) {
        if (($k['device_token'] ?? '') === $dtoken && !empty($k['used'])) json_out(['ok' => true]);
    }
    json_out(['ok' => false]);
}

// ─── Admin: generate ──────────────────────────────────────────────────────────
case 'generate': {
    require_admin();
    $count = max(1, min(200, (int)($input['count'] ?? 1)));
    $label = trim($input['label'] ?? '');
    $new = mutate_store(KEYS_FILE, function (&$d) use ($count, $label) {
        if (!isset($d['keys'])) $d['keys'] = [];
        $made = [];
        for ($i = 0; $i < $count; $i++) {
            $k = ['key' => gen_key(), 'used' => false, 'label' => $label,
                  'created_at' => date('Y-m-d H:i:s'), 'used_at' => null, 'device_token' => null];
            $d['keys'][] = $k;
            $made[] = $k;
        }
        return $made;
    });
    json_out(['ok' => true, 'keys' => $new]);
}

// ─── Admin: list ──────────────────────────────────────────────────────────────
case 'list': {
    require_admin();
    $keys = load_keys();
    $used = count(array_filter($keys, fn($k) => !empty($k['used'])));
    json_out(['ok' => true, 'keys' => $keys, 'total' => count($keys),
        'used' => $used, 'available' => count($keys) - $used]);
}

// ─── Admin: revoke (frees the key for reuse) ──────────────────────────────────
case 'revoke': {
    require_admin();
    $key = strtoupper(trim($input['key'] ?? ''));
    $hit = mutate_store(KEYS_FILE, function (&$d) use ($key) {
        foreach (($d['keys'] ?? []) as $i => $k) {
            if ($k['key'] !== $key) continue;
            $d['keys'][$i]['used'] = false;
            $d['keys'][$i]['used_at'] = null;
            $d['keys'][$i]['device_token'] = null;
            return true;
        }
        return false;
    });
    if (!$hit) json_err('Key not found.', 404);
    json_out(['ok' => true, 'message' => 'Key revoked.']);
}

case 'delete': {
    require_admin();
    $key = strtoupper(trim($input['key'] ?? ''));
    mutate_store(KEYS_FILE, function (&$d) use ($key) {
        $d['keys'] = array_values(array_filter($d['keys'] ?? [], fn($k) => $k['key'] !== $key));
        return true;
    });
    json_out(['ok' => true]);
}

default: json_err('Unknown action', 404);
}
