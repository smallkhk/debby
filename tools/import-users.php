<?php
/**
 * import-users.php — one-time bulk import of customers + activation keys.
 *
 * Written to run ON THE LIVE SERVER so it edits the real data/ files in place.
 * Importing by editing a local copy and re-uploading would silently wipe any
 * signups, top-ups or credit spends that happened in the meantime.
 *
 * HOW TO USE
 *   1. Edit IMPORT_TOKEN below to any random string of your own.
 *   2. Edit the $PEOPLE list.
 *   3. Upload this file to  public_html/tools/import-users.php
 *   4. Visit  https://yourdomain.com/tools/import-users.php?token=YOUR_TOKEN
 *   5. Save the passwords it prints — they are shown ONCE.
 *
 * The script DELETES ITSELF after a successful run. If that fails it says so;
 * remove it by hand. Never leave a script that can create accounts on a live
 * site.
 *
 * Re-running is safe: an email that already exists is skipped, never
 * overwritten, so nobody loses their balance.
 */

// ── EDIT THESE ───────────────────────────────────────────────────────────────
const IMPORT_TOKEN = 'change-me-to-something-random';

// Credits given to each new account. Your provider refuses sessions shorter
// than 10 seconds, so anything below (10 x your per-second rate) leaves the
// customer unable to start a stream at all. 120 covers 40s on a 3/sec model
// or 20s on a 6/sec one.
const DEFAULT_CREDITS = 120;

// Replace these with the people you are importing. Real customer addresses are
// deliberately NOT kept in this file in version control — anything committed
// here stays in git history permanently.
$PEOPLE = [
    // name, email, keyLabel  (keyLabel = the tag shown on their activation key)
    ['name' => 'Example One', 'email' => 'first@example.com',  'keyLabel' => 'tag-one'],
    ['name' => 'Example Two', 'email' => 'second@example.com', 'keyLabel' => 'tag-two'],
];
// ── END EDIT ─────────────────────────────────────────────────────────────────

// Find the app's config.php. Checking several locations means this still works
// whether you drop the file in tools/, in the web root, or beside the API.
$configPath = null;
foreach ([
    __DIR__ . '/../api/config.php',          // public_html/tools/  (recommended)
    __DIR__ . '/api/config.php',             // public_html/
    __DIR__ . '/../public_html/api/config.php',
    __DIR__ . '/config.php',                 // beside the API itself
] as $candidate) {
    if (file_exists($candidate)) { $configPath = $candidate; break; }
}
if (!$configPath) {
    $msg = "Could not find api/config.php relative to " . __DIR__ . "\n"
         . "Upload this file to public_html/tools/ and try again.\n";
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg); } else { http_response_code(500); echo $msg; }
    exit(1);
}
require_once $configPath;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (IMPORT_TOKEN === 'change-me-to-something-random') {
        http_response_code(403);
        exit("Refusing to run: set IMPORT_TOKEN in this file to your own random string first.\n");
    }
    if (!hash_equals(IMPORT_TOKEN, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden.\n");
    }
}

function gen_activation_key() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no I/O/0/1
    $seg = function () use ($chars) {
        $o = '';
        for ($i = 0; $i < 5; $i++) $o .= $chars[random_int(0, strlen($chars) - 1)];
        return $o;
    };
    return 'ECLPS-' . $seg() . '-' . $seg() . '-' . $seg();
}

$report = [];
$made   = 0;

foreach ($PEOPLE as $p) {
    $email = strtolower(trim($p['email']));
    $name  = trim($p['name']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $report[] = "SKIP  {$email} — not a valid email address";
        continue;
    }
    if (find_user_by_email($email)) {
        $report[] = "SKIP  {$email} — an account already exists (left untouched)";
        continue;
    }

    // Readable but strong: 12 hex chars is ~48 bits of entropy.
    $password = bin2hex(random_bytes(6));

    $user = [
        'id'        => bin2hex(random_bytes(10)),
        'name'      => $name,
        'email'     => $email,
        'password'  => password_hash($password, PASSWORD_BCRYPT),
        'points'    => DEFAULT_CREDITS,
        'createdAt' => date('c'),
        'banned'    => false,
        'verified'  => true,
    ];
    save_user($user);
    log_tx($user['id'], 'admin_credit', DEFAULT_CREDITS, 'Imported by admin');

    // Activation key for their desktop app, tagged as requested.
    $key = gen_activation_key();
    mutate_store(KEYS_FILE, function (&$d) use ($key, $p) {
        if (!isset($d['keys'])) $d['keys'] = [];
        $d['keys'][] = [
            'key'          => $key,
            'used'         => false,
            'label'        => $p['keyLabel'],
            'created_at'   => date('Y-m-d H:i:s'),
            'used_at'      => null,
            'device_token' => null,
        ];
        return true;
    });

    $made++;
    $report[] = "ADDED {$name} <{$email}>\n"
              . "        password     : {$password}\n"
              . "        credits      : " . DEFAULT_CREDITS . "\n"
              . "        activation   : {$key}  (tag: {$p['keyLabel']})";
}

echo "\n";
echo "Import complete — {$made} account(s) created.\n";
echo str_repeat('=', 62) . "\n\n";
foreach ($report as $line) echo $line . "\n\n";
echo str_repeat('=', 62) . "\n";
echo "SAVE THE PASSWORDS ABOVE — they are not stored anywhere in readable\n";
echo "form and cannot be shown again. Customers can change theirs from the\n";
echo "'Forgot password' link once your SMTP is working.\n\n";

// Remove the file so it can't be re-run or found by anyone else.
if (!$isCli) {
    if (@unlink(__FILE__)) {
        echo "This importer has deleted itself. Nothing further to do.\n";
    } else {
        echo "!! COULD NOT DELETE THIS FILE — remove tools/import-users.php from\n";
        echo "!! your server NOW. Leaving it in place lets anyone with the token\n";
        echo "!! create accounts.\n";
    }
}
