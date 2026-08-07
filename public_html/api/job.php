<?php
/**
 * job.php — batch (non-realtime) generation: image edits and video jobs.
 *
 * Points are debited up front and REFUNDED automatically if the provider
 * rejects the job, so a failed request never costs the user anything.
 *
 * Image results are written to data/tmp/ rather than the system temp dir —
 * on shared hosting /tmp is wiped aggressively and isn't shared between PHP
 * workers, which made results vanish before they could be downloaded.
 */
require_once __DIR__ . '/config.php';

define('TMP_DIR', DATA_DIR . '/tmp');
$action = $_GET['action'] ?? '';

function tmp_dir() {
    if (!is_dir(TMP_DIR)) @mkdir(TMP_DIR, 0775, true);
    // Sweep anything older than an hour so the account's disk quota stays sane.
    foreach (glob(TMP_DIR . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
    }
    return TMP_DIR;
}

function decart_key() {
    $k = cfg('decartApiKey');
    if (empty($k)) json_err('AI API key not configured.', 500);
    return $k;
}

switch ($action) {

case 'submit': {
    $user = require_login();
    rate_limit('job_submit', 30, 900);

    $model  = $_POST['model'] ?? '';
    $prompt = trim($_POST['prompt'] ?? '');
    if ($prompt === '') json_err('A prompt is required.');
    if (empty($_FILES['data']) || $_FILES['data']['error'] !== UPLOAD_ERR_OK) json_err('File upload failed.');

    $cost = model_cost($model);
    if (!$cost || ($cost['type'] ?? '') !== 'batch' || !isset($cost['flat'])) json_err('Invalid batch model.');
    $flat = (int)$cost['flat'];

    // Atomic debit that refuses to overdraft — see debit_points() in config.php.
    $balance = debit_points($user['id'], $flat);
    if ($balance === false) json_err('Not enough points. Please top up.', 402);
    if ($balance === null)  json_err('Account error.', 500);

    $tmp  = $_FILES['data']['tmp_name'];
    $name = $_FILES['data']['name'];
    $mime = @mime_content_type($tmp) ?: 'application/octet-stream';
    $isImage = ($model === 'lucy-image-2');
    $url = $isImage
        ? "https://api.decart.ai/v1/generate/{$model}"
        : "https://api.decart.ai/v1/jobs/{$model}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['data' => new CURLFile($tmp, $mime, $name), 'prompt' => $prompt],
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . decart_key()],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        $balance = adjust_points($user['id'], $flat);   // refund
        $err = json_decode((string)$body, true);
        json_err('Generation failed: ' . ($err['message'] ?? $err['error'] ?? 'provider error'), 502);
    }

    log_tx($user['id'], 'spend', -$flat, "Batch {$model}");

    if ($isImage) {
        // Response body IS the image. Park it for one retrieval.
        $id   = 'img_' . bin2hex(random_bytes(8));
        $path = tmp_dir() . '/' . $id;
        file_put_contents($path, $body);
        $_SESSION['jobs'][$id] = ['path' => $path, 'ct' => $ct ?: 'image/png', 'exp' => time() + 3600];
        json_out(['ok' => true, 'job_id' => $id, 'type' => 'image', 'balance' => $balance]);
    }

    $data = json_decode((string)$body, true) ?: [];
    $jobId = $data['job_id'] ?? $data['id'] ?? null;
    if (!$jobId) {
        $balance = adjust_points($user['id'], $flat);
        json_err('Provider did not return a job id.', 502);
    }
    $_SESSION['jobs'][$jobId] = ['remote' => true, 'exp' => time() + 86400];
    json_out(['ok' => true, 'job_id' => $jobId, 'type' => 'video', 'balance' => $balance]);
}

case 'status': {
    require_login();
    $id = $_GET['id'] ?? '';
    if (!$id) json_err('Missing id.');
    // Only ever report on jobs this session actually created.
    if (!isset($_SESSION['jobs'][$id])) json_err('Unknown job.', 404);

    if (strpos($id, 'img_') === 0) {
        $j = $_SESSION['jobs'][$id];
        json_out(['ok' => true, 'status' => (!empty($j['path']) && file_exists($j['path'])) ? 'completed' : 'failed']);
    }

    $ch = curl_init("https://api.decart.ai/v1/jobs/{$id}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . decart_key()],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$body, true) ?: [];
    json_out(['ok' => true, 'status' => $data['status'] ?? 'unknown']);
}

case 'content': {
    require_login();
    $id = $_GET['id'] ?? '';
    if (!$id || !isset($_SESSION['jobs'][$id])) json_err('Unknown job.', 404);

    if (strpos($id, 'img_') === 0) {
        $j = $_SESSION['jobs'][$id];
        if (empty($j['path']) || !file_exists($j['path'])) json_err('Result not found or expired.', 404);
        header('Content-Type: ' . $j['ct']);
        header('Content-Length: ' . filesize($j['path']));
        readfile($j['path']);
        @unlink($j['path']);
        unset($_SESSION['jobs'][$id]);
        exit;
    }

    $ch = curl_init("https://api.decart.ai/v1/jobs/{$id}/content");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . decart_key()],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) json_err('Could not fetch the result.', 502);
    header('Content-Type: ' . ($ct ?: 'video/mp4'));
    echo $body;
    exit;
}

// Models the studio may offer, with live pricing — drives the UI dropdown.
case 'models': {
    $out = [];
    foreach ((cfg('costs') ?? []) as $id => $c) {
        $out[] = ['id' => $id, 'label' => $c['label'] ?? $id, 'type' => $c['type'] ?? 'realtime',
            'perSecond' => $c['perSecond'] ?? null, 'flat' => $c['flat'] ?? null];
    }
    json_out(['ok' => true, 'models' => $out]);
}

default: json_err('Unknown action', 404);
}
