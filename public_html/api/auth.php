<?php
/**
 * auth.php — registration (email OTP), login, session, password reset.
 */
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$input  = body_json();

switch ($action) {

// ─── REGISTER STEP 1: send OTP ────────────────────────────────────────────────
case 'send_otp': {
    rate_limit('send_otp', 8, 900);
    $name  = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $pass  = $input['password'] ?? '';

    if (!$name || !$email || !$pass)                    json_err('All fields are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     json_err('Invalid email address.');
    if (strlen($pass) < 6)                              json_err('Password must be at least 6 characters.');
    if (find_user_by_email($email))                     json_err('An account with this email already exists.');

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp_data'] = [
        'otp' => $otp, 'email' => $email, 'name' => $name,
        'pass' => password_hash($pass, PASSWORD_BCRYPT),
        'expires' => time() + 600, 'attempts' => 0,
    ];

    send_email($email, 'Your verification code', email_shell('Your verification code',
        "<p style='color:#9aa3b8;text-align:center;font-size:.9rem'>Enter this code to finish creating your account.</p>"
      . "<div style='text-align:center;background:rgba(124,92,255,.15);border:1px solid rgba(124,92,255,.4);"
      . "border-radius:12px;padding:24px;margin:20px 0'>"
      . "<div style='font-size:2.4rem;font-weight:800;letter-spacing:.3em;color:#c4b5fd'>{$otp}</div></div>"
      . "<p style='color:#626b82;font-size:.78rem;text-align:center'>Expires in 10 minutes. "
      . "If you didn't request this, ignore this email.</p>"));

    json_out(['ok' => true, 'message' => 'Code sent']);
}

// ─── REGISTER STEP 2: verify OTP + create account ─────────────────────────────
case 'verify_otp': {
    $email = strtolower(trim($input['email'] ?? ''));
    $otp   = trim($input['otp'] ?? '');
    $data  = $_SESSION['otp_data'] ?? null;

    if (!$data)                                 json_err('Session expired. Please start over.');
    if (time() > $data['expires'])            { unset($_SESSION['otp_data']); json_err('Code expired. Request a new one.'); }
    if (strtolower($data['email']) !== $email)  json_err('Email mismatch. Please start over.');

    $_SESSION['otp_data']['attempts']++;
    if ($_SESSION['otp_data']['attempts'] > 5) { unset($_SESSION['otp_data']); json_err('Too many attempts. Please start over.'); }
    if (!hash_equals($data['otp'], $otp))       json_err('Invalid code. Please try again.');
    if (find_user_by_email($email))             json_err('An account with this email already exists.');

    $bonus = (int)(cfg('signupBonus') ?? 20);
    $user = [
        'id' => bin2hex(random_bytes(10)), 'name' => $data['name'], 'email' => $email,
        'password' => $data['pass'], 'points' => $bonus, 'createdAt' => date('c'),
        'banned' => false, 'verified' => true,
    ];
    save_user($user);
    log_tx($user['id'], 'signup', $bonus, 'Signup bonus');
    unset($_SESSION['otp_data']);
    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];

    send_email($email, 'Welcome!', email_shell('Welcome, ' . htmlspecialchars($data['name']) . '!',
        "<p style='color:#9aa3b8'>Your account is ready with <strong style='color:#eef1f8'>{$bonus} free points</strong>. "
      . "Head to the Studio and start creating.</p>"));

    if (cfg('notifyOnSignup')) {
        notify_admin('New signup: ' . $data['name'],
            '<p>New user: <strong>' . htmlspecialchars($data['name']) . '</strong> (' . htmlspecialchars($email) . ')</p>');
    }
    json_out(['ok' => true, 'user' => public_user($user)]);
}

// ─── LOGIN ────────────────────────────────────────────────────────────────────
case 'login': {
    rate_limit('login', 15, 900);
    $email = strtolower(trim($input['email'] ?? ''));
    $pass  = $input['password'] ?? '';
    if (!$email || !$pass) json_err('Email and password are required.');

    $user = find_user_by_email($email);
    if (!$user || !password_verify($pass, $user['password'])) json_err('Invalid email or password.', 401);
    if (!empty($user['banned'])) json_err('This account has been suspended.', 403);

    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];
    json_out(['ok' => true, 'user' => public_user($user)]);
}

case 'logout': {
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

case 'me': {
    $u = current_user();
    json_out(['ok' => true, 'user' => $u ? public_user($u) : null, 'isAdmin' => !empty($_SESSION['is_admin'])]);
}

// ─── PASSWORD RESET ───────────────────────────────────────────────────────────
case 'forgot_password': {
    rate_limit('forgot', 8, 900);
    $email = strtolower(trim($input['email'] ?? ''));
    if (!$email) json_err('Email is required.');

    $user = find_user_by_email($email);
    $otp  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['reset_data'] = ['email' => $email, 'otp' => $otp, 'expires' => time() + 600, 'attempts' => 0];

    if ($user) {
        send_email($email, 'Reset your password', email_shell('Password reset code',
            "<p style='color:#9aa3b8'>Hi " . htmlspecialchars($user['name']) . ", here's your reset code:</p>"
          . "<div style='text-align:center;background:rgba(124,92,255,.15);border:1px solid rgba(124,92,255,.4);"
          . "border-radius:12px;padding:24px;margin:20px 0'>"
          . "<div style='font-size:2.4rem;font-weight:800;letter-spacing:.3em;color:#c4b5fd'>{$otp}</div></div>"
          . "<p style='color:#626b82;font-size:.78rem;text-align:center'>Expires in 10 minutes.</p>"));
    }
    // Always ok — never reveal whether the address is registered.
    json_out(['ok' => true]);
}

case 'verify_reset_otp': {
    $email = strtolower(trim($input['email'] ?? ''));
    $otp   = trim($input['otp'] ?? '');
    $data  = $_SESSION['reset_data'] ?? null;
    if (!$data)                        json_err('Session expired. Request a new code.');
    if ($data['email'] !== $email)     json_err('Email mismatch.');
    if (time() > $data['expires'])   { unset($_SESSION['reset_data']); json_err('Code expired. Request a new one.'); }

    $data['attempts']++;
    $_SESSION['reset_data'] = $data;
    if ($data['attempts'] > 5)       { unset($_SESSION['reset_data']); json_err('Too many attempts. Request a new code.'); }
    if (!hash_equals($data['otp'], $otp)) json_err('Invalid code. Try again.');

    $token = bin2hex(random_bytes(32));
    $_SESSION['reset_token'] = ['email' => $email, 'token' => $token, 'expires' => time() + 600];
    unset($_SESSION['reset_data']);
    json_out(['ok' => true, 'token' => $token]);
}

case 'reset_password': {
    $email = strtolower(trim($input['email'] ?? ''));
    $token = trim($input['token'] ?? '');
    $pass  = $input['password'] ?? '';
    $rt    = $_SESSION['reset_token'] ?? null;

    if (!$rt || $rt['email'] !== $email || !hash_equals($rt['token'], $token)) json_err('Invalid or expired reset session.');
    if (time() > $rt['expires']) { unset($_SESSION['reset_token']); json_err('Session expired. Please start over.'); }
    if (strlen($pass) < 6) json_err('Password must be at least 6 characters.');

    $user = find_user_by_email($email);
    if (!$user) json_err('Account not found.');
    $user['password'] = password_hash($pass, PASSWORD_BCRYPT);
    save_user($user);
    unset($_SESSION['reset_token']);
    json_out(['ok' => true]);
}

default: json_err('Unknown action', 404);
}
