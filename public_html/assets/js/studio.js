/* ── Eclipse Creator Studio — realtime studio ──────────────────────────────── */
// The SDK is served from this site, not a CDN. A CDN outage, a corporate
// firewall or a country-level block would otherwise kill this whole module —
// and because an ES import failure aborts the script, the page would render
// with dead dropdowns and no explanation. Keep it local.
import { createDecartClient, models } from './vendor/decart-sdk.js';

const $ = (id) => document.getElementById(id);
const cameraSelect = $('cameraSelect');
const micSelect = $('micSelect');
const modelSelect = $('modelSelect');
const presetsEl = $('presets');
const promptInput = $('promptInput');
const applyPromptBtn = $('applyPrompt');
const startBtn = $('startBtn');
const stopBtn = $('stopBtn');
const pipBtn = $('pipBtn');
const obsBtn = $('obsBtn');
const fsBtn = $('fsBtn');
const localVideo = $('localVideo');
const remoteVideo = $('remoteVideo');
const localPlaceholder = $('localPlaceholder');
const remotePlaceholder = $('remotePlaceholder');
const statusEl = $('status');
const statusText = $('statusText');
const meterEl = $('meter');
const meterTime = $('meterTime');
const meterCost = $('meterCost');
const meterBar = $('meterBar');

let realtimeClient = null;
let localStream = null;
let obsWindow = null;
let session = null;     // { holdId, maxSeconds, perSecond, startedAt }
let tickTimer = null;
let beatTimer = null;
let modelCatalog = [];

const PRESETS = [
  { label: 'Anime', prompt: 'anime style, vibrant cel shading, expressive eyes' },
  { label: 'Cyberpunk', prompt: 'cyberpunk neon city, rain, cinematic lighting, teal and magenta' },
  { label: 'Claymation', prompt: 'claymation character, soft studio light, stop-motion look' },
  { label: 'Oil painting', prompt: 'classical oil painting, thick brush strokes, warm palette' },
  { label: 'Pixel art', prompt: 'retro 16-bit pixel art, limited palette' },
  { label: 'Noir', prompt: 'black and white film noir, dramatic shadows, high contrast' },
  { label: 'Watercolor', prompt: 'soft watercolor illustration, paper texture, pastel' },
  { label: '3D cartoon', prompt: 'polished 3D animated movie character, subsurface skin' },
];

function setStatus(state, text) {
  statusEl.className = 'status' + (state ? ' ' + state : '');
  statusText.textContent = text;
}

function setLiveUI(live) {
  startBtn.disabled = live;
  stopBtn.disabled = !live;
  pipBtn.disabled = !live;
  obsBtn.disabled = !live;
  fsBtn.disabled = !live;
  cameraSelect.disabled = live;
  micSelect.disabled = live;
  modelSelect.disabled = live;
  meterEl.classList.toggle('hidden', !live);
}

// ── Devices ─────────────────────────────────────────────────────────────────
async function primeDevices() {
  if (!navigator.mediaDevices?.getUserMedia) {
    cameraSelect.innerHTML = '<option value="">Camera unavailable — needs HTTPS</option>';
    micSelect.innerHTML = '<option value="">Microphone unavailable — needs HTTPS</option>';
    startBtn.disabled = true;
    toast('Your browser blocks camera access on this connection. The site must be served over HTTPS.', true);
    return;
  }

  // List first, THEN ask for permission. Blocking on getUserMedia leaves the
  // dropdowns stuck on "Requesting devices…" for as long as the browser's
  // permission prompt sits unanswered — which can be forever.
  await listDevices();

  try {
    const prime = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    prime.getTracks().forEach((t) => t.stop());
    await listDevices();   // re-list: labels are only exposed once permission is granted
  } catch {
    toast('Allow camera and microphone access to pick your devices.', true);
  }
  navigator.mediaDevices.addEventListener('devicechange', listDevices);
}

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
  fill(cameraSelect, devices.filter((d) => d.kind === 'videoinput'), 'Camera');
  fill(micSelect, devices.filter((d) => d.kind === 'audioinput'), 'Microphone');
}

async function loadModels() {
  const { models: list } = await API.get('/api/job.php?action=models');
  modelCatalog = list.filter((m) => m.type === 'realtime');

  // The Studio can only offer models that stream. Say where the rest live,
  // otherwise they look missing rather than simply being a different tool.
  const batch = list.filter((m) => m.type === 'batch');
  const hint = document.getElementById('batchHint');
  if (hint) {
    hint.innerHTML = batch.length
      ? `Live models only. ${batch.length} more for photo &amp; video editing — `
        + `<a href="/create.html" style="color:var(--brand-2);font-weight:600">open Create</a>.`
      : '';
  }
  modelSelect.innerHTML = '';
  for (const m of modelCatalog) {
    const o = document.createElement('option');
    o.value = m.id;
    o.textContent = `${m.label} — ${m.perSecond} pts/sec`;
    modelSelect.appendChild(o);
  }
  if (!modelCatalog.length) {
    modelSelect.innerHTML = '<option value="">No realtime models configured</option>';
    startBtn.disabled = true;
  }
}

function buildPresets() {
  PRESETS.forEach((p) => {
    const chip = document.createElement('button');
    chip.className = 'chip';
    chip.type = 'button';
    chip.textContent = p.label;
    chip.addEventListener('click', () => {
      presetsEl.querySelectorAll('.chip').forEach((c) => c.classList.remove('active'));
      chip.classList.add('active');
      promptInput.value = p.prompt;
      if (realtimeClient) applyPrompt();
    });
    presetsEl.appendChild(chip);
  });
}

// ── Metering ────────────────────────────────────────────────────────────────
function elapsed() {
  return session ? Math.floor((Date.now() - session.startedAt) / 1000) : 0;
}

function startMeter() {
  const tick = () => {
    const s = elapsed();
    const left = Math.max(0, session.maxSeconds - s);
    const mm = String(Math.floor(s / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    meterTime.textContent = `${mm}:${ss}`;
    meterCost.textContent = `${s * session.perSecond} pts`;
    meterBar.style.width = (session.maxSeconds ? (left / session.maxSeconds) * 100 : 0) + '%';
    if (left <= 0) {
      toast('Your credits ran out — stopping the stream.');
      stop();
    } else if (left === 30) {
      toast('30 seconds of credit left.');
    }
  };
  tick();
  tickTimer = setInterval(tick, 1000);
  // Report progress so usage is billed as it happens and the balance in the nav
  // ticks down live, rather than the whole cost landing when the stream ends.
  beatTimer = setInterval(async () => {
    if (!session) return;
    try {
      const r = await API.post('/api/session.php?action=beat', {
        holdId: session.holdId, seconds: elapsed(),
      });
      if (typeof r.balance === 'number') setBalance(r.balance);
      if (r.exhausted) {
        toast('Your credits ran out — stopping the stream.');
        stop();
      }
    } catch { /* a missed beat is settled on stop */ }
  }, 20000);
}

function stopMeter() {
  clearInterval(tickTimer);
  clearInterval(beatTimer);
  tickTimer = beatTimer = null;
}

// ── Reference image ─────────────────────────────────────────────────────────
// Try-on models need a garment photo, and the restyle models accept one as a
// style reference. It can be set before connecting or swapped mid-stream.
const refInput = document.getElementById('refInput');
const refDrop = document.getElementById('refDrop');
const refPreview = document.getElementById('refPreview');
const refEmpty = document.getElementById('refEmpty');
const refClear = document.getElementById('refClear');
// This file and studio.html are updated together, but they are uploaded one at
// a time. If the newer script lands first, these elements do not exist yet —
// and a throw here would abort the whole module, taking the device pickers,
// model list and presets down with it. Degrade instead.
const hasRefUi = refInput && refDrop && refPreview && refEmpty && refClear;
const MAX_REF_BYTES = 10 * 1024 * 1024;
let refImage = null;
let refPreviewUrl = null;

async function setRefImage(file) {
  if (!file || !hasRefUi) return;
  if (!file.type.startsWith('image/')) return toast('That file is not an image.', true);
  if (file.size > MAX_REF_BYTES) return toast('Reference image must be under 10 MB.', true);

  refImage = file;
  if (refPreviewUrl) URL.revokeObjectURL(refPreviewUrl);
  refPreviewUrl = URL.createObjectURL(file);
  refPreview.src = refPreviewUrl;
  refPreview.hidden = false;
  refEmpty.classList.add('hidden');
  refClear.classList.remove('hidden');

  // Apply immediately if a stream is already running.
  if (realtimeClient) {
    try {
      await realtimeClient.setImage(file);
      toast('Reference image applied');
    } catch {
      toast('Could not apply the reference image.', true);
    }
  }
}

async function clearRefImage() {
  refImage = null;
  if (!hasRefUi) return;
  if (refPreviewUrl) { URL.revokeObjectURL(refPreviewUrl); refPreviewUrl = null; }
  refPreview.removeAttribute('src');
  refPreview.hidden = true;
  refEmpty.classList.remove('hidden');
  refClear.classList.add('hidden');
  refInput.value = '';
  if (realtimeClient) {
    try { await realtimeClient.setImage(null); toast('Reference image removed'); } catch {}
  }
}

if (hasRefUi) {
  refDrop.addEventListener('click', () => refInput.click());
  refDrop.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); refInput.click(); }
  });
  refInput.addEventListener('change', () => setRefImage(refInput.files[0]));
  refClear.addEventListener('click', (e) => { e.stopPropagation(); clearRefImage(); });
  ['dragenter', 'dragover'].forEach((ev) =>
    refDrop.addEventListener(ev, (e) => { e.preventDefault(); refDrop.classList.add('drag'); }));
  ['dragleave', 'drop'].forEach((ev) =>
    refDrop.addEventListener(ev, (e) => { e.preventDefault(); refDrop.classList.remove('drag'); }));
  refDrop.addEventListener('drop', (e) => setRefImage(e.dataTransfer?.files?.[0]));
} else {
  console.warn('Reference image UI not found — studio.html is out of date. '
             + 'Upload the matching studio.html to enable try-on.');
}

// ── Connection watchdog ─────────────────────────────────────────────────────
// A stalled WebRTC handshake reports nothing at all, so give it a deadline and
// say what actually tends to cause it instead of hanging on "Connecting…".
let connectWatchdog = null;
const CONNECT_TIMEOUT_MS = 45000;

function armConnectWatchdog() {
  clearConnectWatchdog();
  connectWatchdog = setTimeout(() => {
    setStatus('err', 'Could not connect');
    toast('The AI stream did not start. This is usually a firewall or VPN blocking video traffic, '
        + 'or the provider being at capacity. Try again, or a different network.', true);
    stop();
  }, CONNECT_TIMEOUT_MS);
}
function clearConnectWatchdog() {
  clearTimeout(connectWatchdog);
  connectWatchdog = null;
}

// Map SDK error codes to something a customer can act on.
function friendlyConnectError(err) {
  const code = err?.code || '';
  if (code === 'INVALID_API_KEY')  return 'The session token was rejected. Please try starting again.';
  if (code === 'WEBRTC_ICE_ERROR' || code === 'WEBRTC_TIMEOUT_ERROR')
    return 'Could not open a video connection. A firewall, VPN or restricted network is usually the cause.';
  if (code === 'WEBRTC_WEBSOCKET_ERROR' || code === 'WEBRTC_SIGNALING_ERROR')
    return 'Could not reach the AI service. Check your internet connection.';
  if (code === 'WEBRTC_SERVER_ERROR')
    return 'The AI service reported an error. It may be busy — please try again shortly.';
  return err?.message || 'Could not start the stream.';
}

// ── Session ─────────────────────────────────────────────────────────────────
async function start() {
  const modelId = modelSelect.value;
  if (!modelId) return;

  // Try-on has nothing to put on you without a garment photo. Blocking here
  // beats letting the customer spend credits on a session that cannot work.
  if (/vton/i.test(modelId) && !refImage) {
    toast('Try-on needs a reference image — add the outfit photo first.', true);
    refDrop.focus();
    return;
  }

  setLiveUI(true);
  setStatus('wait', 'Reserving credits…');

  try {
    // 1. Server holds credits and mints an ephemeral token.
    const s = await API.post('/api/session.php?action=start', { model: modelId });
    setBalance(s.balance);

    // 2. Open the chosen camera/mic at the model's native spec.
    setStatus('wait', 'Opening camera…');
    const model = models.realtime(modelId);
    localStream = await navigator.mediaDevices.getUserMedia({
      video: {
        deviceId: cameraSelect.value ? { exact: cameraSelect.value } : undefined,
        frameRate: model.fps,
        width: model.width,
        height: model.height,
      },
      audio: micSelect.value ? { deviceId: { exact: micSelect.value } } : true,
    });
    localVideo.srcObject = localStream;
    localPlaceholder.style.display = 'none';

    // 3. Connect with the short-lived token — never the permanent key.
    setStatus('wait', 'Connecting to AI…');
    const client = createDecartClient({ apiKey: s.apiKey });

    // Without a watchdog the UI can sit on "Connecting to AI…" indefinitely:
    // connect() can resolve before any video arrives, and a blocked WebRTC
    // path produces no error at all. Queue updates push the deadline back,
    // because waiting in line is normal and not a failure.
    armConnectWatchdog();

    realtimeClient = await client.realtime.connect(localStream, {
      model,
      onRemoteStream: (remoteStream) => {
        clearConnectWatchdog();
        remoteVideo.srcObject = remoteStream;
        remotePlaceholder.style.display = 'none';
        window.__eclipseStream = remoteStream;   // picked up by the OBS window
        setStatus('on', 'Live');
      },
      onQueuePosition: ({ position, queueSize }) => {
        armConnectWatchdog();
        setStatus('wait', `Waiting in queue — ${position} of ${queueSize}`);
        remotePlaceholder.textContent =
          `You're number ${position} in the queue. The stream starts automatically when it's your turn.`;
      },
      onConnectionChange: (state) => {
        if (state === 'connecting')    setStatus('wait', 'Connecting to AI…');
        if (state === 'connected')     setStatus('wait', 'Connected — starting the model…');
        if (state === 'generating')  { clearConnectWatchdog(); setStatus('on', 'Live'); }
        if (state === 'reconnecting')  setStatus('wait', 'Connection dropped — reconnecting…');
        if (state === 'disconnected' && session) {
          setStatus('err', 'Disconnected');
          toast('The AI connection dropped. Stopping.', true);
          stop();
        }
      },
      initialState: {
        prompt: { text: promptInput.value.trim() || 'cinematic, high quality', enhance: true },
        ...(refImage ? { image: refImage } : {}),
      },
    });

    session = { holdId: s.holdId, maxSeconds: s.maxSeconds, perSecond: s.perSecond, startedAt: Date.now() };
    startMeter();
    const mins = Math.floor(s.maxSeconds / 60);
    toast(mins >= 1
      ? `Live — up to ${mins} min on your balance`
      : `Live — up to ${s.maxSeconds}s on your balance`);
  } catch (err) {
    console.error(err);
    clearConnectWatchdog();
    toast(friendlyConnectError(err), true);
    setStatus('err', 'Error');
    await stop();
  }
}

function applyPrompt() {
  const text = promptInput.value.trim();
  if (!realtimeClient || !text) return;
  try {
    realtimeClient.setPrompt(text);
    toast('Prompt updated');
  } catch {
    toast('Could not update the prompt.', true);
  }
}

async function stop() {
  const seconds = elapsed();
  const holdId = session?.holdId;
  stopMeter();
  clearConnectWatchdog();
  remotePlaceholder.textContent =
    'The transformed output appears here — this is the feed to capture in OBS.';

  try { realtimeClient?.disconnect(); } catch {}
  realtimeClient = null;
  if (localStream) {
    localStream.getTracks().forEach((t) => t.stop());
    localStream = null;
  }
  localVideo.srcObject = null;
  remoteVideo.srcObject = null;
  window.__eclipseStream = null;
  localPlaceholder.style.display = '';
  remotePlaceholder.style.display = '';
  if (obsWindow && !obsWindow.closed) obsWindow.close();
  session = null;
  setLiveUI(false);
  setStatus('', 'Idle');

  // Settle the hold: bill the seconds used, refund the rest.
  if (holdId) {
    try {
      const r = await API.post('/api/session.php?action=stop', { holdId, seconds });
      setBalance(r.balance);
      if (r.charged) toast(`Charged ${r.charged} pts for ${seconds}s — ${r.balance} pts left`);
    } catch { /* the hold expires with the session anyway */ }
  }
}

// ── Output ──────────────────────────────────────────────────────────────────
async function togglePip() {
  try {
    if (document.pictureInPictureElement) await document.exitPictureInPicture();
    else if (remoteVideo.srcObject) await remoteVideo.requestPictureInPicture();
  } catch {
    toast("Picture-in-Picture isn't available in this browser.", true);
  }
}

function openObsOutput() {
  if (!window.__eclipseStream) return;
  obsWindow = window.open('/output.html', 'eclipse-obs-output', 'width=1280,height=720');
  if (!obsWindow) toast('Allow pop-ups to open the OBS output window.', true);
  else toast('Add this window in OBS as a Window Capture source.');
}

async function goFullscreen() {
  try { await remoteVideo.requestFullscreen(); }
  catch { toast('Fullscreen is not available.', true); }
}

// ── Wire up ─────────────────────────────────────────────────────────────────
startBtn.addEventListener('click', start);
stopBtn.addEventListener('click', stop);
applyPromptBtn.addEventListener('click', applyPrompt);
pipBtn.addEventListener('click', togglePip);
obsBtn.addEventListener('click', openObsOutput);
fsBtn.addEventListener('click', goFullscreen);
promptInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) applyPrompt();
});
// Best-effort settle if the tab is closed mid-stream. The server also caps the
// session server-side, so a missed beacon can't hand out free minutes.
window.addEventListener('pagehide', () => {
  if (session && navigator.sendBeacon) {
    navigator.sendBeacon('/api/session.php?action=stop',
      new Blob([JSON.stringify({ holdId: session.holdId, seconds: elapsed() })],
        { type: 'application/json' }));
  }
});

(async function init() {
  try {
    await mountNav('studio');
    const user = await requireUser();
    if (!user) return;
    buildPresets();
    await loadModels();
    await primeDevices();
  } catch (err) {
    // Never leave the panel sitting on "Loading…" with no explanation.
    console.error('Studio failed to initialise:', err);
    setStatus('err', 'Studio failed to load');
    startBtn.disabled = true;
    toast('The Studio could not start up. Please refresh — if it keeps happening, contact support.', true);
  }
})();
