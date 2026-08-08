<?php
/**
 * payments.php — USDT top-ups on BSC and Tron, verified on-chain.
 *
 * A submitted TXID is only credited if ALL of these hold:
 *   1. The TXID has never been consumed before (anti-replay)
 *   2. The transaction succeeded and has enough confirmations
 *   3. The token contract is the real USDT contract (not a look-alike)
 *   4. The recipient is exactly your configured address
 *   5. The transferred amount covers what was claimed
 *
 * Anything that fails lands in "pending" for manual admin review rather than
 * being rejected outright — an unconfirmed tx is usually just early.
 */
require_once __DIR__ . '/config.php';

define('BSC_USDT_CONTRACT',  '0x55d398326f99059ff775485246999027b3197955');
define('BSC_USDT_DECIMALS',  18);
define('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
define('TRON_USDT_DECIMALS', 6);
define('BSC_RPC_URL',        'https://bsc-dataseed.binance.org/');
define('TRON_API_URL',       'https://api.trongrid.io');
define('BSC_MIN_CONFIRMATIONS',  3);
define('TRON_MIN_CONFIRMATIONS', 19);
define('TRANSFER_TOPIC', 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef');
define('AMOUNT_TOLERANCE', 0.01);

$action = $_GET['action'] ?? '';
$input  = body_json();

switch ($action) {

case 'info': {
    json_out(['ok' => true, 'info' => [
        'bscAddress'    => cfg('bscAddress'),
        'tronAddress'   => cfg('tronAddress'),
        'pointsPerUsdt' => cfg('pointsPerUsdt'),
        'minUsdt'       => cfg('minUsdt'),
        'packages'      => array_values(cfg('packages') ?? []),
    ]]);
}

case 'submit': {
    $user = require_login();
    rate_limit('pay_submit', 10, 900);

    $network = strtolower(trim($input['network'] ?? ''));
    $txid    = trim($input['txid'] ?? '');
    $usdt    = (float)($input['usdt'] ?? 0);

    if (!in_array($network, ['bsc', 'tron'], true)) json_err('Invalid network.');
    if ($txid === '')                               json_err('Transaction hash (TXID) is required.');
    if ($usdt < (float)cfg('minUsdt'))              json_err('Minimum payment is ' . cfg('minUsdt') . ' USDT.');

    $wallet = $network === 'bsc' ? cfg('bscAddress') : cfg('tronAddress');
    if (empty($wallet)) json_err('That network is not accepting payments right now.', 503);

    if ($network === 'bsc' && strpos($txid, '0x') !== 0) $txid = '0x' . $txid;
    $txKey = strtolower($network . ':' . $txid);

    $store = read_store(PAYMENTS_FILE);
    if (in_array($txKey, $store['usedTxids'] ?? [], true)) json_err('This transaction has already been used.', 409);
    foreach (($store['payments'] ?? []) as $p) {
        if (strtolower($p['network'] . ':' . $p['txid']) === $txKey
            && in_array($p['status'], ['approved', 'pending'], true)) {
            json_err('This transaction has already been submitted.', 409);
        }
    }

    $payment = [
        'id' => bin2hex(random_bytes(8)), 'userId' => $user['id'],
        'userName' => $user['name'], 'userEmail' => $user['email'],
        'network' => $network, 'txid' => $txid,
        'usdtClaimed' => $usdt, 'usdtVerified' => null, 'points' => null,
        'status' => 'pending', 'reason' => '', 'createdAt' => date('c'),
    ];

    $result = ($network === 'bsc') ? verify_bsc($txid, $usdt, $wallet) : verify_tron($txid, $usdt, $wallet);

    if (!$result['ok']) {
        $payment['reason'] = $result['reason'];
        mutate_store(PAYMENTS_FILE, function (&$s) use ($payment) {
            $s['payments'][] = $payment;
            return true;
        });
        json_out(['ok' => false, 'status' => 'pending',
            'error' => 'Could not auto-verify: ' . $result['reason'] . '. Your payment is pending admin review.']);
    }

    $points = credits_for_usdt($result['usdt']);
    $payment['status'] = 'approved';
    $payment['usdtVerified'] = $result['usdt'];
    $payment['points'] = $points;
    $payment['reason'] = 'Auto-verified on-chain';

    // Consume the TXID and record the payment in one atomic step. If another
    // request claimed the same TXID microseconds earlier, we lose and bail out.
    $claimed = mutate_store(PAYMENTS_FILE, function (&$s) use ($payment, $txKey) {
        if (in_array($txKey, $s['usedTxids'] ?? [], true)) return false;
        $s['usedTxids'][] = $txKey;
        $s['payments'][]  = $payment;
        return true;
    });
    if (!$claimed) json_err('This transaction has already been used.', 409);

    $balance = adjust_points($user['id'], $points);
    log_tx($user['id'], 'topup', $points, "Crypto {$network} — {$result['usdt']} USDT");

    if (cfg('notifyOnPayment')) {
        notify_admin('Crypto payment approved',
            '<p><strong>' . htmlspecialchars($user['name']) . '</strong> (' . htmlspecialchars($user['email'])
          . ") paid <strong>{$result['usdt']} USDT</strong> on " . strtoupper($network) . ". Points added: {$points}</p>");
    }
    send_email($user['email'], 'Payment confirmed', email_shell('Payment confirmed',
        "<p style='color:#9aa3b8'>Your payment of <strong style='color:#eef1f8'>{$result['usdt']} USDT</strong> was verified "
      . "and <strong style='color:#eef1f8'>{$points} points</strong> were added. New balance: {$balance}.</p>"));

    json_out(['ok' => true, 'status' => 'approved', 'points' => $points, 'balance' => $balance,
        'message' => "Payment verified! {$points} points added."]);
}

case 'mine': {
    $user  = require_login();
    $store = read_store(PAYMENTS_FILE);
    $mine  = array_values(array_filter($store['payments'] ?? [], fn($p) => $p['userId'] === $user['id']));
    usort($mine, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    json_out(['ok' => true, 'payments' => $mine]);
}

case 'all': {
    require_admin();
    $store = read_store(PAYMENTS_FILE);
    $all = $store['payments'] ?? [];
    usort($all, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    json_out(['ok' => true, 'payments' => $all]);
}

case 'manual': {
    require_admin();
    $paymentId = $input['paymentId'] ?? '';
    $approve   = !empty($input['approve']);

    $outcome = mutate_store(PAYMENTS_FILE, function (&$s) use ($paymentId, $approve) {
        foreach (($s['payments'] ?? []) as $i => $p) {
            if ($p['id'] !== $paymentId) continue;
            if ($p['status'] === 'approved') return ['err' => 'Already approved.'];
            if (!$approve) {
                $s['payments'][$i]['status'] = 'rejected';
                $s['payments'][$i]['reason'] = 'Rejected by admin';
                return ['status' => 'rejected'];
            }
            $txKey = strtolower($p['network'] . ':' . $p['txid']);
            if (in_array($txKey, $s['usedTxids'] ?? [], true)) return ['err' => 'That TXID was already consumed.'];
            $usdt   = $p['usdtVerified'] ?? $p['usdtClaimed'];
            $points = credits_for_usdt($usdt);
            $s['payments'][$i]['status'] = 'approved';
            $s['payments'][$i]['points'] = $points;
            $s['payments'][$i]['reason'] = 'Manually approved by admin';
            $s['usedTxids'][] = $txKey;
            return ['status' => 'approved', 'points' => $points, 'userId' => $p['userId'], 'usdt' => $usdt, 'network' => $p['network']];
        }
        return ['err' => 'Payment not found.'];
    });

    if (!$outcome)                 json_err('Payment store unavailable.', 500);
    if (isset($outcome['err']))    json_err($outcome['err'], 409);
    if ($outcome['status'] === 'rejected') json_out(['ok' => true, 'status' => 'rejected']);

    adjust_points($outcome['userId'], $outcome['points']);
    log_tx($outcome['userId'], 'topup', $outcome['points'],
        "Crypto {$outcome['network']} (manual) — {$outcome['usdt']} USDT");
    json_out(['ok' => true, 'status' => 'approved', 'points' => $outcome['points']]);
}

default: json_err('Unknown action', 404);
}

/* ══ BSC verification via public JSON-RPC ═══════════════════════════════════ */
function verify_bsc($txid, $claimedUsdt, $myAddress) {
    $receipt = bsc_rpc('eth_getTransactionReceipt', [$txid]);
    if (!$receipt || !isset($receipt['status'])) return ['ok' => false, 'reason' => 'Transaction not found or not yet mined'];
    if (hexdec($receipt['status']) !== 1)         return ['ok' => false, 'reason' => 'Transaction failed on-chain'];

    $confs = hexdec(bsc_rpc('eth_blockNumber', [])) - hexdec($receipt['blockNumber']) + 1;
    if ($confs < BSC_MIN_CONFIRMATIONS) {
        return ['ok' => false, 'reason' => "Only {$confs} confirmations (need " . BSC_MIN_CONFIRMATIONS . ')'];
    }

    $usdtContract = strtolower(BSC_USDT_CONTRACT);
    $toPadded = '0x000000000000000000000000' . strtolower(substr($myAddress, 2));

    foreach (($receipt['logs'] ?? []) as $log) {
        if (strtolower($log['address']) !== $usdtContract) continue;
        if (strtolower(ltrim($log['topics'][0], '0x')) !== TRANSFER_TOPIC) continue;
        if (strtolower($log['topics'][2]) !== strtolower($toPadded)) continue;

        $amount = hexbig_to_float($log['data'], BSC_USDT_DECIMALS);
        if ($amount + AMOUNT_TOLERANCE < $claimedUsdt) {
            return ['ok' => false, 'reason' => "On-chain amount ({$amount} USDT) is less than claimed ({$claimedUsdt} USDT)"];
        }
        return ['ok' => true, 'usdt' => $amount];
    }
    return ['ok' => false, 'reason' => 'No matching USDT transfer to your address in this transaction'];
}

function bsc_rpc($method, $params) {
    $ch = curl_init(BSC_RPC_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return (json_decode($res, true)['result']) ?? null;
}

/* ══ Tron verification via TronGrid ════════════════════════════════════════ */
function verify_tron($txid, $claimedUsdt, $myAddress) {
    $txid = ltrim($txid, '0x');
    $info = tron_post('/wallet/gettransactioninfobyid', ['value' => $txid]);
    if (!$info || empty($info['id'])) return ['ok' => false, 'reason' => 'Transaction not found or not yet confirmed'];
    if (isset($info['receipt']['result']) && $info['receipt']['result'] !== 'SUCCESS') {
        return ['ok' => false, 'reason' => 'Transaction failed on-chain'];
    }

    $now   = tron_post('/wallet/getnowblock', []);
    $confs = ($now['block_header']['raw_data']['number'] ?? 0) - ($info['blockNumber'] ?? 0) + 1;
    if ($confs < TRON_MIN_CONFIRMATIONS) {
        return ['ok' => false, 'reason' => "Only {$confs} confirmations (need " . TRON_MIN_CONFIRMATIONS . ')'];
    }

    $usdtHex = tron_addr_to_hex(TRON_USDT_CONTRACT);
    $toHex   = tron_addr_to_hex($myAddress);
    if (empty($info['log'])) return ['ok' => false, 'reason' => 'No token transfer logs in this transaction'];

    foreach ($info['log'] as $log) {
        $logContract  = strtolower($log['address'] ?? '');
        $usdtNoPrefix = strtolower(substr($usdtHex, 2));
        if ($logContract !== $usdtNoPrefix && $logContract !== strtolower($usdtHex)) continue;

        $topics = $log['topics'] ?? [];
        if (empty($topics) || strtolower($topics[0]) !== TRANSFER_TOPIC) continue;
        if ('41' . substr(strtolower($topics[2] ?? ''), -40) !== strtolower($toHex)) continue;

        $amount = hexbig_to_float($log['data'] ?? '0', TRON_USDT_DECIMALS);
        if ($amount + AMOUNT_TOLERANCE < $claimedUsdt) {
            return ['ok' => false, 'reason' => "On-chain amount ({$amount} USDT) is less than claimed ({$claimedUsdt} USDT)"];
        }
        return ['ok' => true, 'usdt' => $amount];
    }
    return ['ok' => false, 'reason' => 'No matching USDT transfer to your address in this transaction'];
}

function tron_post($path, $body) {
    $ch = curl_init(TRON_API_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

/* ══ Big-number helpers ════════════════════════════════════════════════════ */
// USDT on BSC has 18 decimals, so raw amounts overflow PHP floats — use BCMath
// when it's available and only fall back to lossy float math if it isn't.
function hexbig_to_float($hex, $decimals) {
    $hex = ltrim(strtolower($hex), '0x');
    if ($hex === '') return 0.0;
    if (function_exists('bcdiv')) {
        return (float)bcdiv(hex_to_dec_str($hex), bcpow('10', (string)$decimals, 0), 8);
    }
    return hexdec($hex) / pow(10, $decimals);
}

function hex_to_dec_str($hex) {
    $hex = strtolower(ltrim($hex, '0x'));
    $dec = '0';
    for ($i = 0, $len = strlen($hex); $i < $len; $i++) {
        $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($hex[$i]), 0);
    }
    return $dec;
}

function tron_addr_to_hex($base58) {
    if (strpos($base58, '41') === 0 && ctype_xdigit($base58)) return strtolower($base58);
    if (!function_exists('bcadd')) return '';
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $num = '0';
    for ($i = 0, $len = strlen($base58); $i < $len; $i++) {
        $idx = strpos($alphabet, $base58[$i]);
        if ($idx === false) return '';
        $num = bcadd(bcmul($num, '58', 0), (string)$idx, 0);
    }
    $hex = '';
    while (bccomp($num, '0', 0) > 0) {
        $hex = dechex((int)bcmod($num, '16')) . $hex;
        $num = bcdiv($num, '16', 0);
    }
    return strtolower(substr($hex, 0, -8)); // drop the 4-byte base58check checksum
}
