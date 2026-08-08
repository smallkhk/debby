<?php
/**
 * migrate-from-old.php — convert an old EclipseLiveCam data/ folder to the
 * schema this build expects, preserving every customer, balance and device.
 *
 *   php tools/migrate-from-old.php /path/to/old/data /path/to/new/data
 *
 * What changes, and why:
 *
 *   users.json         unchanged. Password hashes are bcrypt ($2y$10$), which
 *                      password_verify() reads natively, so every customer
 *                      keeps their existing password.
 *   transactions.json  unchanged.
 *   payments.json      unchanged (payments + usedTxids replay ledger).
 *   keys.json          RESHAPED. The old file is a flat array; this build
 *                      stores {"keys": [...]}. Uploading the old shape makes
 *                      every activated desktop device fail its token check.
 *   settings.json      RESHAPED. The old file holds `adminPassword` in
 *                      PLAINTEXT and has no `adminPassHash`. This build treats
 *                      a missing hash as "admin not claimed yet", so the old
 *                      file would leave /admin.html open for anyone to claim.
 *                      A bcrypt hash is written instead, and a strong random
 *                      admin password is generated and printed once.
 *
 * The script never overwrites an existing destination file unless --force.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Run this from the command line.\n"); exit(1); }

$args  = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--force'));
$force = in_array('--force', $argv, true);
$src   = rtrim($args[0] ?? '', '/');
$dst   = rtrim($args[1] ?? '', '/');

if ($src === '' || $dst === '') {
    fwrite(STDERR, "Usage: php tools/migrate-from-old.php <old-data-dir> <new-data-dir> [--force]\n");
    exit(1);
}
if (!is_dir($src)) { fwrite(STDERR, "Source not found: $src\n"); exit(1); }
if (!is_dir($dst)) { mkdir($dst, 0755, true); }

function readJson($path, $fallback) {
    if (!file_exists($path)) return $fallback;
    $d = json_decode(file_get_contents($path), true);
    return is_array($d) ? $d : $fallback;
}
function writeJson($path, $data, $force) {
    if (file_exists($path) && !$force) {
        echo "  SKIP (exists, use --force): " . basename($path) . "\n";
        return;
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "  wrote " . basename($path) . "\n";
}

echo "\nMigrating\n  from: $src\n  to:   $dst\n\n";

// ── users / transactions / payments: pass through ────────────────────────────
$users = readJson("$src/users.json", ['users' => []]);
if (!isset($users['users']) && array_is_list($users)) $users = ['users' => $users];
echo "Users: " . count($users['users']) . "\n";
writeJson("$dst/users.json", $users, $force);

$tx = readJson("$src/transactions.json", ['transactions' => []]);
if (!isset($tx['transactions']) && array_is_list($tx)) $tx = ['transactions' => $tx];
echo "Transactions: " . count($tx['transactions']) . "\n";
writeJson("$dst/transactions.json", $tx, $force);

$pay = readJson("$src/payments.json", ['payments' => [], 'usedTxids' => []]);
$pay['payments']  = $pay['payments']  ?? [];
$pay['usedTxids'] = $pay['usedTxids'] ?? [];
echo "Payments: " . count($pay['payments']) . " (used TXIDs: " . count($pay['usedTxids']) . ")\n";
writeJson("$dst/payments.json", $pay, $force);

// ── keys: flat array -> {"keys": [...]} ──────────────────────────────────────
$rawKeys = readJson("$src/keys.json", []);
$keyList = array_is_list($rawKeys) ? $rawKeys : ($rawKeys['keys'] ?? []);
$activated = 0;
foreach ($keyList as $k) if (!empty($k['used']) && !empty($k['device_token'])) $activated++;
echo "Activation keys: " . count($keyList) . " ({$activated} bound to a device)\n";
writeJson("$dst/keys.json", ['keys' => array_values($keyList)], $force);

// ── settings: plaintext password -> bcrypt hash ──────────────────────────────
$old = readJson("$src/settings.json", []);
$newAdminPass = null;

$settings = [
    'decartApiKey'   => $old['decartApiKey'] ?? '',
    'adminUsername'  => $old['adminUsername'] ?? 'admin',
    'adminPassHash'  => '',
    'brandName'      => $old['brandName'] ?? 'Eclipse Creator Studio',
    'signupBonus'    => (int)($old['signupBonus'] ?? 120),
    'pointsPerUsdt'  => (int)($old['pointsPerUsdt'] ?? 100),
    'minUsdt'        => $old['minUsdt'] ?? 20,
    'allowedOrigins' => $old['allowedOrigins'] ?? [],
    'bscAddress'     => $old['bscAddress'] ?? '',
    'tronAddress'    => $old['tronAddress'] ?? '',
    'costs'          => [],
    'smtpHost'     => $old['smtpHost'] ?? '',
    'smtpPort'     => (int)($old['smtpPort'] ?? 587),
    'smtpUser'     => $old['smtpUser'] ?? '',
    'smtpPass'     => $old['smtpPass'] ?? '',
    'smtpFrom'     => $old['smtpFrom'] ?? '',
    'smtpFromName' => $old['smtpFromName'] ?? 'Eclipse Creator Studio',
    'smtpSecure'   => $old['smtpSecure'] ?? 'tls',
    'notifyAdminEmail'    => $old['notifyAdminEmail'] ?? '',
    'notifyOnSignup'      => $old['notifyOnSignup'] ?? true,
    'notifyOnPayment'     => $old['notifyOnPayment'] ?? true,
    'notifyOnLowBalance'  => $old['notifyOnLowBalance'] ?? true,
    'lowBalanceThreshold' => (int)($old['lowBalanceThreshold'] ?? 5),
];

// Admin password: never carry plaintext forward. Hash the old one if it looks
// strong, otherwise mint a strong random one so /admin.html is locked the
// moment this deploys (an empty hash would leave it open to be claimed).
$oldPlain = (string)($old['adminPassword'] ?? '');
if ($oldPlain !== '' && strlen($oldPlain) >= 12) {
    $settings['adminPassHash'] = password_hash($oldPlain, PASSWORD_BCRYPT);
    echo "Admin: existing password hashed (kept).\n";
} else {
    $newAdminPass = bin2hex(random_bytes(9));
    $settings['adminPassHash'] = password_hash($newAdminPass, PASSWORD_BCRYPT);
    echo "Admin: old password was weak/empty — generated a strong replacement.\n";
}

// Costs: keep the operator's rates, drop the contradictory extra field. A
// realtime model carrying a stray `flat` (and vice versa) confuses pricing.
$knownLabels = [
    'lucy-2.1'         => 'Lucy 2.1 — Live edit',
    'lucy-2.5'         => 'Lucy 2.5 — Live edit',
    'lucy-restyle-2'   => 'Lucy Restyle 2 — Restyle',
    'lucy-vton-3'      => 'Lucy VTON 3 — Virtual try-on',
    'lucy-vton-2'      => 'Lucy VTON 2 — Virtual try-on',
    'lucy-image-2'     => 'Lucy Image 2 — Image edit',
    'lucy-2-v2v'       => 'Lucy 2 — Video to video',
    'lucy-restyle-v2v' => 'Lucy Restyle — Video to video',
];
foreach (($old['costs'] ?? []) as $id => $c) {
    $type = $c['type'] ?? 'realtime';
    // Defaulting the label to the id would show customers a raw model id.
    $entry = ['type' => $type,
        'label' => $c['label'] ?? $knownLabels[$id] ?? ucwords(str_replace('-', ' ', $id))];
    if ($type === 'realtime') $entry['perSecond'] = (int)($c['perSecond'] ?? 6);
    else                      $entry['flat']      = (int)($c['flat'] ?? 10);
    $settings['costs'][$id] = $entry;
}
if (!$settings['costs']) {
    $settings['costs'] = [
        'lucy-2.5'       => ['type' => 'realtime', 'perSecond' => 6, 'label' => 'Lucy 2.5 — Live edit'],
        'lucy-restyle-2' => ['type' => 'realtime', 'perSecond' => 3, 'label' => 'Lucy Restyle 2'],
        'lucy-vton-3'    => ['type' => 'realtime', 'perSecond' => 6, 'label' => 'Lucy VTON 3 — Try-on'],
        'lucy-image-2'   => ['type' => 'batch',    'flat' => 10,     'label' => 'Lucy Image 2'],
    ];
}
echo "API key carried over: " . ($settings['decartApiKey'] ? 'yes' : 'NO — set it in the admin panel') . "\n";
writeJson("$dst/settings.json", $settings, $force);

// ── Warn about customers who cannot start a session ──────────────────────────
// Decart refuses maxSessionDuration below 10s, so a balance under
// 10 x perSecond can't open a stream on that model at all.
$cheapest = null;
foreach ($settings['costs'] as $c) {
    if (($c['type'] ?? '') !== 'realtime') continue;
    $cheapest = $cheapest === null ? $c['perSecond'] : min($cheapest, $c['perSecond']);
}
if ($cheapest) {
    $floor = $cheapest * 10;
    $stuck = array_values(array_filter($users['users'], fn($u) => (int)$u['points'] < $floor));
    if ($stuck) {
        echo "\n  ⚠  " . count($stuck) . " customer(s) hold less than {$floor} credits — the minimum\n";
        echo "     for a 10-second session on your cheapest model. They cannot start\n";
        echo "     a stream until topped up:\n";
        foreach ($stuck as $u) echo "       - {$u['email']} ({$u['points']} credits)\n";
    }
}

echo "\nDone.\n";
if ($newAdminPass) {
    echo "\n  ════════════════════════════════════════════════════\n";
    echo "   ADMIN LOGIN — shown once, save it now\n";
    echo "     username: {$settings['adminUsername']}\n";
    echo "     password: {$newAdminPass}\n";
    echo "   Change it in the admin panel after your first login.\n";
    echo "  ════════════════════════════════════════════════════\n";
}
echo "\nUpload the contents of {$dst} into public_html/data/ on your host.\n\n";
