/**
 * Eclipse Creator Studio — renderer.
 *
 * The SDK is bundled locally rather than pulled from a CDN: a desktop app that
 * stops working because a third-party host is unreachable is not acceptable,
 * and an ES import failure would abort this whole module silently.
 */
import { createDecartClient, models } from './vendor/decart-sdk.js';

const api = window.eclipse.api;
const $ = (id) => document.getElementById(id);

// ── State ───────────────────────────────────────────────────────────────────
let session = null;          // { holdId, maxSeconds, perSecond, startedAt }
let realtimeClient = null;
let localStream = null;
let outputWindow = null;
let recorder = null;
let recordedChunks = [];
let tickTimer = null;
let beatTimer = null;
let currentUser = null;
let config = { serverUrl: '', deviceToken: '' };

const PRESETS = [
  ['Anime', 'anime style, vibrant cel shading, expressive eyes'],
  ['Cyberpunk', 'cyberpunk neon city, rain, cinematic lighting, teal and magenta'],
  ['Claymation', 'claymation character, soft studio light, stop-motion look'],
  ['Oil painting', 'classical oil painting, thick brush strokes, warm palette'],
  ['Pixel art', 'retro 16-bit pixel art, limited palette'],
  ['Noir', 'black and white film noir, dramatic shadows, high contrast'],
  ['Watercolor', 'soft watercolor illustration, paper texture, pastel'],
  ['3D cartoon', 'polished 3D animated movie character, subsurface skin'],
];

// ── Helpers ─────────────────────────────────────────────────────────────────
function show(view) {
  document.querySelectorAll('.view').forEach((v) => v.classList.add('hidden'));
  $('view-' + view).classList.remove('hidden');
}

function toast(msg, isError = false) {
  const el = $('toast');
  el.textContent = msg;
  el.classList.toggle('error', isError);
  el.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.remove('show'), 3600);
}

function showErr(id, msg) {
  $(id).innerHTML = msg ? `<div class="alert alert-err"></div>` : '';
  if (msg) $(id).firstChild.textContent = msg;
}

function setStatus(state, text) {
  $('status').className = 'status' + (state ? ' ' + state : '');
  $('statusText').textContent = text;
}

function setBalance(n) {
  if (typeof n !== 'number') return;
  if (currentUser) currentUser.points = n;
  $('balance').textContent = n.toLocaleString();
  $('balanceChip').classList.toggle('low', n < 200);
}

// ── Boot ────────────────────────────────────────────────────────────────────
async function boot() {
  config = await window.eclipse.config.get();
  $('serverUrl').value = config.serverUrl || '';
  $('devTokenOut').textContent = config.deviceToken ? config.deviceToken.slice(0, 24) + '…' : 'Not activated';

  // Device must be activated before anything else is reachable.
  if (!config.deviceToken) return show('activate');

  const v = await api.post('/api/activate.php?action=verify', { device_token: config.deviceToken });
  if (v.ok !== true) {
    // Distinguish "revoked" from "server unreachable" — telling someone their
    // key is invalid when the internet is down sends them to support for nothing.
    if (v.error && /reach|unexpected|Not found/i.test(v.error)) {
      showErr('actErr', v.error + ' Check your connection, then try again.');
    } else {
      showErr('actErr', 'This device is no longer activated. Enter a key to reactivate.');
      await window.eclipse.config.set({ deviceToken: '' });
    }
    return show('activate');
  }

  const me = await api.get('/api/auth.php?action=me');
  if (me.ok && me.user) {
    currentUser = me.user;
    return enterStudio();
  }
  show('login');
}

// ── Activation ──────────────────────────────────────────────────────────────
$('actBtn').addEventListener('click', async () => {
  const key = $('actKey').value.trim().toUpperCase();
  if (!key) return showErr('actErr', 'Enter your activation key.');
  showErr('actErr', '');
  $('actBtn').disabled = true;
  $('actBtn').textContent = 'Activating…';

  const r = await api.post('/api/activate.php?action=validate', {
    key, device_token: config.deviceToken || '',
  });

  $('actBtn').disabled = false;
  $('actBtn').textContent = 'Activate';

  if (r.ok !== true) return showErr('actErr', r.error || 'Activation failed.');
  config = await window.eclipse.config.set({ deviceToken: r.device_token });
  toast('Device activated');
  boot();
});

// ── Login ───────────────────────────────────────────────────────────────────
$('loginBtn').addEventListener('click', doLogin);
$('password').addEventListener('keydown', (e) => { if (e.key === 'Enter') doLogin(); });
$('email').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('password').focus(); });

async function doLogin() {
  const email = $('email').value.trim();
  const password = $('password').value;
  if (!email || !password) return showErr('loginErr', 'Enter your email and password.');
  showErr('loginErr', '');
  $('loginBtn').disabled = true;
  $('loginBtn').textContent = 'Signing in…';

  const r = await api.post('/api/auth.php?action=login', { email, password });

  $('loginBtn').disabled = false;
  $('loginBtn').textContent = 'Sign in';

  if (r.ok !== true) return showErr('loginErr', r.error || 'Sign-in failed.');
  currentUser = r.user;
  $('password').value = '';
  enterStudio();
}

$('logoutBtn').addEventListener('click', async () => {
  await stopStream();
  await api.post('/api/auth.php?action=logout', {});
  currentUser = null;
  show('login');
});

// ── External links ──────────────────────────────────────────────────────────
const openOnSite = (p) => window.eclipse.openExternal((config.serverUrl || '') + p);
$('openSignup').addEventListener('click', (e) => { e.preventDefault(); openOnSite('/register.html'); });
$('openForgot').addEventListener('click', (e) => { e.preventDefault(); openOnSite('/forgot.html'); });
$('topUpBtn').addEventListener('click', () => openOnSite('/topup.html'));

// ── Settings ────────────────────────────────────────────────────────────────
let settingsReturn = 'login';
const openSettings = (from) => { settingsReturn = from; show('settings'); };
$('actSettings').addEventListener('click', (e) => { e.preventDefault(); openSettings('activate'); });
$('loginSettings').addEventListener('click', (e) => { e.preventDefault(); openSettings('login'); });
$('settingsBtn').addEventListener('click', () => openSettings('studio'));
$('settingsBack').addEventListener('click', (e) => { e.preventDefault(); show(settingsReturn); });

$('saveSettings').addEventListener('click', async () => {
  let url = $('serverUrl').value.trim().replace(/\/+$/, '');
  if (url && !/^https?:\/\//i.test(url)) url = 'https://' + url;
  config = await window.eclipse.config.set({ serverUrl: url });
  toast('Settings saved');
  boot();
});

$('clearActivation').addEventListener('click', async () => {
  config = await window.eclipse.config.set({ deviceToken: '' });
  toast('Activation cleared');
  show('activate');
});

// ── Studio ──────────────────────────────────────────────────────────────────
async function enterStudio() {
  show('studio');
  setBalance(currentUser?.points ?? 0);
  buildPresets();
  await loadModels();
  await listDevices();
  primePermissions();
  navigator.mediaDevices.addEventListener('devicechange', listDevices);
}

function buildPresets() {
  const host = $('presets');
  if (host.childElementCount) return;
  PRESETS.forEach(([label, prompt]) => {
    const b = document.createElement('button');
    b.className = 'chip-preset';
    b.textContent = label;
    b.addEventListener('click', () => {
      host.querySelectorAll('.chip-preset').forEach((c) => c.classList.remove('active'));
      b.classList.add('active');
      $('promptInput').value = prompt;
      if (realtimeClient) applyPrompt();
    });
    host.appendChild(b);
  });
}

async function loadModels() {
  const r = await api.get('/api/job.php?action=models');
  const sel = $('modelSelect');
  sel.innerHTML = '';
  const realtime = (r.models || []).filter((m) => m.type === 'realtime');
  if (!realtime.length) {
    sel.innerHTML = '<option value="">No models available</option>';
    $('startBtn').disabled = true;
    return;
  }
  realtime.forEach((m) => {
    const o = document.createElement('option');
    o.value = m.id;
    o.textContent = `${m.label} — ${m.perSecond}/sec`;
    sel.appendChild(o);
  });
}

// Enumerate first so the pickers are never stuck waiting on a permission
// prompt; labels fill in once access is granted.
async function listDevices() {
  const devices = await navigator.mediaDevices.enumerateDevices();
  const fill = (sel, list, kind) => {
    const prev = sel.value;
    sel.innerHTML = '';
    list.forEach((d, i) => {
      const o = document.createElement('option');
      o.value = d.deviceId;
      o.textContent = d.label || `${kind} ${i + 1}`;
      sel.appendChild(o);
    });
    if (!list.length) sel.innerHTML = `<option value="">No ${kind.toLowerCase()} found</option>`;
    if (prev) sel.value = prev;
  };
  fill($('cameraSelect'), devices.filter((d) => d.kind === 'videoinput'), 'Camera');
  fill($('micSelect'), devices.filter((d) => d.kind === 'audioinput'), 'Microphone');
}

async function primePermissions() {
  try {
    const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    s.getTracks().forEach((t) => t.stop());
    await listDevices();
  } catch {
    toast('Allow camera and microphone access to pick your devices.', true);
  }
}

// ── Streaming ───────────────────────────────────────────────────────────────
function videoConstraints(model) {
  const choice = $('resSelect').value;
  const base = { deviceId: $('cameraSelect').value ? { exact: $('cameraSelect').value } : undefined,
                 frameRate: model.fps };
  if (choice === 'model') return { ...base, width: model.width, height: model.height };
  const [w, h] = choice.split('x').map(Number);
  return { ...base, width: w, height: h };
}

let connectWatchdog = null;
const CONNECT_TIMEOUT_MS = 45000;

function armConnectWatchdog() {
  clearConnectWatchdog();
  connectWatchdog = setTimeout(() => {
    setStatus('err', 'Could not connect');
    toast('The AI stream did not start. This is usually a firewall or VPN blocking video traffic, '
        + 'or the provider being at capacity. Try again, or a different network.', true);
    stopStream();
  }, CONNECT_TIMEOUT_MS);
}
function clearConnectWatchdog() {
  clearTimeout(connectWatchdog);
  connectWatchdog = null;
}

// Map SDK error codes to something a customer can act on.
function friendlyConnectError(err) {
  const code = err?.code || '';
  if (code === 'INVALID_API_KEY') return 'The session token was rejected. Please try starting again.';
  if (code === 'WEBRTC_ICE_ERROR' || code === 'WEBRTC_TIMEOUT_ERROR')
    return 'Could not open a video connection. A firewall, VPN or restricted network is usually the cause.';
  if (code === 'WEBRTC_WEBSOCKET_ERROR' || code === 'WEBRTC_SIGNALING_ERROR')
    return 'Could not reach the AI service. Check your internet connection.';
  if (code === 'WEBRTC_SERVER_ERROR')
    return 'The AI service reported an error. It may be busy — please try again shortly.';
  return err?.message || 'Could not start the stream.';
}

async function startStream() {
  const modelId = $('modelSelect').value;
  if (!modelId || session) return;

  setLive(true);
  setStatus('wait', 'Reserving credits…');

  try {
    const s = await api.post('/api/session.php?action=start', { model: modelId });
    if (s.ok !== true) throw new Error(s.error || 'Could not start a session.');
    setBalance(s.balance);

    setStatus('wait', 'Opening camera…');
    const model = models.realtime(modelId);
    localStream = await navigator.mediaDevices.getUserMedia({
      video: videoConstraints(model),
      audio: $('micSelect').value ? { deviceId: { exact: $('micSelect').value } } : true,
    });
    $('localVideo').srcObject = localStream;
    $('localPlaceholder').style.display = 'none';

    setStatus('wait', 'Connecting to AI…');
    const client = createDecartClient({ apiKey: s.apiKey });

    // A stalled WebRTC handshake reports nothing at all, so give it a deadline
    // rather than sitting on "Connecting…" forever. Queue updates push the
    // deadline back, because waiting in line is normal, not a failure.
    armConnectWatchdog();

    realtimeClient = await client.realtime.connect(localStream, {
      model,
      onRemoteStream: (remote) => {
        clearConnectWatchdog();
        $('remoteVideo').srcObject = remote;
        $('remotePlaceholder').style.display = 'none';
        window.__eclipseStream = remote;   // read by the OBS output window
        setStatus('on', 'Live');
      },
      onQueuePosition: ({ position, queueSize }) => {
        armConnectWatchdog();
        setStatus('wait', `Waiting in queue — ${position} of ${queueSize}`);
        $('remotePlaceholder').textContent =
          `You're number ${position} in the queue. The stream starts automatically when it's your turn.`;
      },
      onConnectionChange: (state) => {
        if (state === 'connecting')   setStatus('wait', 'Connecting to AI…');
        if (state === 'connected')    setStatus('wait', 'Connected — starting the model…');
        if (state === 'generating') { clearConnectWatchdog(); setStatus('on', 'Live'); }
        if (state === 'reconnecting') setStatus('wait', 'Connection dropped — reconnecting…');
        if (state === 'disconnected' && session) {
          setStatus('err', 'Disconnected');
          toast('The AI connection dropped. Stopping.', true);
          stopStream();
        }
      },
      initialState: {
        prompt: { text: $('promptInput').value.trim() || 'cinematic, high quality', enhance: true },
      },
    });

    session = { holdId: s.holdId, maxSeconds: s.maxSeconds, perSecond: s.perSecond, startedAt: Date.now() };
    startMeter();
    const mins = Math.floor(s.maxSeconds / 60);
    toast(mins >= 1 ? `Live — up to ${mins} min on your balance` : `Live — up to ${s.maxSeconds}s`);
  } catch (err) {
    console.error(err);
    clearConnectWatchdog();
    toast(friendlyConnectError(err), true);
    setStatus('err', 'Error');
    await stopStream();
  }
}

function elapsed() {
  return session ? Math.floor((Date.now() - session.startedAt) / 1000) : 0;
}

function startMeter() {
  $('meter').classList.remove('hidden');
  const tick = () => {
    if (!session) return;
    const s = elapsed();
    const left = Math.max(0, session.maxSeconds - s);
    $('meterTime').textContent =
      String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    $('meterCost').textContent = (s * session.perSecond).toLocaleString();
    $('meterBar').style.width = (session.maxSeconds ? (left / session.maxSeconds) * 100 : 0) + '%';
    $('meterLeft').textContent = left > 0 ? `${left}s of credit left this session` : 'Credit exhausted';
    if (left <= 0) { toast('Credits ran out — stopping.'); stopStream(); }
    else if (left === 30) toast('30 seconds of credit left.');
  };
  tick();
  tickTimer = setInterval(tick, 1000);

  // Bill as we go so the balance moves in step with the stream.
  beatTimer = setInterval(async () => {
    if (!session) return;
    const r = await api.post('/api/session.php?action=beat', {
      holdId: session.holdId, seconds: elapsed(),
    });
    if (typeof r.balance === 'number') setBalance(r.balance);
    if (r.exhausted) { toast('Credits ran out — stopping.'); stopStream(); }
  }, 20000);
}

async function stopStream() {
  const seconds = elapsed();
  const holdId = session?.holdId;

  clearInterval(tickTimer); clearInterval(beatTimer);
  tickTimer = beatTimer = null;
  clearConnectWatchdog();
  $('remotePlaceholder').textContent = 'The transformed output — capture this in OBS';
  if (recorder && recorder.state !== 'inactive') stopRecording();

  try { realtimeClient?.disconnect(); } catch {}
  realtimeClient = null;
  if (localStream) { localStream.getTracks().forEach((t) => t.stop()); localStream = null; }
  $('localVideo').srcObject = null;
  $('remoteVideo').srcObject = null;
  window.__eclipseStream = null;
  $('localPlaceholder').style.display = '';
  $('remotePlaceholder').style.display = '';
  if (outputWindow && !outputWindow.closed) { outputWindow.close(); outputWindow = null; }
  session = null;
  $('meter').classList.add('hidden');
  setLive(false);
  setStatus('', 'Idle');

  if (holdId) {
    const r = await api.post('/api/session.php?action=stop', { holdId, seconds });
    if (typeof r.balance === 'number') setBalance(r.balance);
    if (r.charged) toast(`Charged ${r.charged} credits for ${seconds}s`);
  }
}

function setLive(live) {
  $('startBtn').disabled = live;
  $('stopBtn').disabled = !live;
  ['pipBtn', 'outBtn', 'recBtn', 'fsBtn'].forEach((id) => { $(id).disabled = !live; });
  ['cameraSelect', 'micSelect', 'modelSelect', 'resSelect'].forEach((id) => { $(id).disabled = live; });
}

function applyPrompt() {
  const text = $('promptInput').value.trim();
  if (!realtimeClient || !text) return;
  try { realtimeClient.setPrompt(text); toast('Prompt updated'); }
  catch { toast('Could not update the prompt.', true); }
}

// ── Output: PiP, OBS window, fullscreen, recording ──────────────────────────
async function togglePip() {
  try {
    if (document.pictureInPictureElement) await document.exitPictureInPicture();
    else if ($('remoteVideo').srcObject) await $('remoteVideo').requestPictureInPicture();
  } catch { toast('Picture-in-Picture unavailable.', true); }
}

function toggleOutputWindow() {
  if (outputWindow && !outputWindow.closed) { outputWindow.close(); outputWindow = null; return; }
  if (!window.__eclipseStream) return;
  outputWindow = window.open('output.html', 'eclipse-output', 'width=1280,height=720');
  if (!outputWindow) toast('Could not open the output window.', true);
  else toast('Add this window in OBS as a Window Capture source.');
}

async function goFullscreen() {
  try { await $('remoteVideo').requestFullscreen(); }
  catch { toast('Fullscreen unavailable.', true); }
}

function toggleRecording() {
  if (recorder && recorder.state !== 'inactive') return stopRecording();
  const stream = window.__eclipseStream;
  if (!stream) return;

  // Pick a container the runtime actually supports rather than assuming.
  const type = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm']
    .find((t) => MediaRecorder.isTypeSupported(t));
  if (!type) return toast('Recording is not supported here.', true);

  recordedChunks = [];
  recorder = new MediaRecorder(stream, { mimeType: type, videoBitsPerSecond: 8_000_000 });
  recorder.ondataavailable = (e) => { if (e.data.size) recordedChunks.push(e.data); };
  recorder.onstop = async () => {
    const blob = new Blob(recordedChunks, { type });
    recordedChunks = [];
    const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    const res = await window.eclipse.saveRecording(await blob.arrayBuffer(), `eclipse-${stamp}.webm`);
    if (res.ok) {
      toast('Recording saved');
      window.eclipse.showItemInFolder(res.filePath);
    } else if (res.error) {
      toast('Could not save: ' + res.error, true);
    }
  };
  recorder.start(1000);
  $('recBtn').classList.add('recording');
  $('recBtn').textContent = '■ Stop recording';
  toast('Recording started');
}

function stopRecording() {
  try { recorder?.stop(); } catch {}
  recorder = null;
  $('recBtn').classList.remove('recording');
  $('recBtn').textContent = '● Record';
}

// ── Wiring ──────────────────────────────────────────────────────────────────
$('startBtn').addEventListener('click', startStream);
$('stopBtn').addEventListener('click', stopStream);
$('applyPrompt').addEventListener('click', applyPrompt);
$('pipBtn').addEventListener('click', togglePip);
$('outBtn').addEventListener('click', toggleOutputWindow);
$('recBtn').addEventListener('click', toggleRecording);
$('fsBtn').addEventListener('click', goFullscreen);
$('promptInput').addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) applyPrompt();
});

window.eclipse.onHotkey((name) => {
  if ($('view-studio').classList.contains('hidden')) return;
  if (name === 'toggle-stream') session ? stopStream() : startStream();
  if (name === 'toggle-pip') togglePip();
  if (name === 'toggle-record' && session) toggleRecording();
});

// Settle the session if the app is closed mid-stream. The server also forfeits
// the deposit for unreported time, so a missed call cannot mean free usage.
window.addEventListener('beforeunload', () => {
  if (session) {
    api.post('/api/session.php?action=stop', { holdId: session.holdId, seconds: elapsed() });
  }
});

boot();
