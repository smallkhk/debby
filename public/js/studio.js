import { createDecartClient, models } from "@decartai/sdk";

// ── DOM refs ────────────────────────────────────────────────────────────────
const $ = (id) => document.getElementById(id);
const cameraSelect = $("cameraSelect");
const micSelect = $("micSelect");
const modelSelect = $("modelSelect");
const accessField = $("accessField");
const accessCode = $("accessCode");
const presetsEl = $("presets");
const promptInput = $("promptInput");
const applyPromptBtn = $("applyPrompt");
const startBtn = $("startBtn");
const stopBtn = $("stopBtn");
const pipBtn = $("pipBtn");
const obsBtn = $("obsBtn");
const fsBtn = $("fsBtn");
const localVideo = $("localVideo");
const remoteVideo = $("remoteVideo");
const localPlaceholder = $("localPlaceholder");
const remotePlaceholder = $("remotePlaceholder");
const statusEl = $("status");
const statusText = $("statusText");
const toastEl = $("toast");

// ── State ───────────────────────────────────────────────────────────────────
let realtimeClient = null;
let localStream = null;
let config = { allowedModels: [], accessMode: "open", tokenTtlSeconds: 300 };
let obsWindow = null;

const PRESETS = [
  { label: "Anime", prompt: "anime style, vibrant cel shading, expressive eyes" },
  { label: "Cyberpunk", prompt: "cyberpunk neon city, rain, cinematic lighting, teal and magenta" },
  { label: "Claymation", prompt: "claymation character, soft studio light, stop-motion look" },
  { label: "Oil painting", prompt: "classical oil painting, thick brush strokes, warm palette" },
  { label: "Pixel art", prompt: "retro 16-bit pixel art, limited palette" },
  { label: "Noir", prompt: "black and white film noir, dramatic shadows, high contrast" },
  { label: "Watercolor", prompt: "soft watercolor illustration, paper texture, pastel" },
  { label: "Cartoon 3D", prompt: "polished 3D animated movie character, subsurface skin" },
];

// ── Helpers ─────────────────────────────────────────────────────────────────
function toast(msg, isError = false) {
  toastEl.textContent = msg;
  toastEl.classList.toggle("error", isError);
  toastEl.classList.add("show");
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toastEl.classList.remove("show"), 3600);
}

function setStatus(state, text) {
  statusEl.className = "status" + (state ? " " + state : "");
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
}

// ── Bootstrap ───────────────────────────────────────────────────────────────
async function loadConfig() {
  try {
    const res = await fetch("/api/config");
    config = await res.json();
  } catch {
    toast("Could not reach the server.", true);
  }
  // Models
  modelSelect.innerHTML = "";
  const labels = {
    "lucy-2.5": "Lucy 2.5 — Live edit (720p)",
    "lucy-restyle-2": "Lucy Restyle 2 — Restyle (720p)",
    "lucy-vton-3": "Lucy VTON 3 — Virtual try-on (720p)",
  };
  for (const id of config.allowedModels) {
    const opt = document.createElement("option");
    opt.value = id;
    opt.textContent = labels[id] || id;
    modelSelect.appendChild(opt);
  }
  if (!config.allowedModels.length) {
    modelSelect.innerHTML = '<option value="">No models configured</option>';
    startBtn.disabled = true;
    toast("Server has no models configured.", true);
  }
  // Access gate
  if (config.accessMode === "code") accessField.style.display = "";
}

async function primeAndListDevices() {
  try {
    // A quick permission prime so device labels are populated.
    const prime = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    prime.getTracks().forEach((t) => t.stop());
  } catch {
    toast("Camera/mic permission is needed to list your devices.", true);
  }
  await listDevices();
  navigator.mediaDevices.addEventListener("devicechange", listDevices);
}

async function listDevices() {
  const devices = await navigator.mediaDevices.enumerateDevices();
  const cams = devices.filter((d) => d.kind === "videoinput");
  const mics = devices.filter((d) => d.kind === "audioinput");

  const fill = (sel, list, kind) => {
    const prev = sel.value;
    sel.innerHTML = "";
    list.forEach((d, i) => {
      const opt = document.createElement("option");
      opt.value = d.deviceId;
      opt.textContent = d.label || `${kind} ${i + 1}`;
      sel.appendChild(opt);
    });
    if (!list.length) sel.innerHTML = `<option value="">No ${kind} found</option>`;
    if (prev) sel.value = prev;
  };
  fill(cameraSelect, cams, "Camera");
  fill(micSelect, mics, "Microphone");
}

function buildPresets() {
  PRESETS.forEach((p) => {
    const chip = document.createElement("button");
    chip.className = "chip";
    chip.textContent = p.label;
    chip.addEventListener("click", () => {
      presetsEl.querySelectorAll(".chip").forEach((c) => c.classList.remove("active"));
      chip.classList.add("active");
      promptInput.value = p.prompt;
      if (realtimeClient) applyPrompt();
    });
    presetsEl.appendChild(chip);
  });
}

// ── Live session ────────────────────────────────────────────────────────────
async function mintToken(modelId) {
  const body = { model: modelId };
  if (config.accessMode === "code") body.accessCode = accessCode.value.trim();
  const res = await fetch("/api/session", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || "Could not start a session.");
  return data.token;
}

async function start() {
  const modelId = modelSelect.value;
  if (!modelId) return;

  setStatus("wait", "Requesting session…");
  setLiveUI(true);

  try {
    // 1) Server mints a short-lived, model-scoped ephemeral token.
    const token = await mintToken(modelId);

    // 2) Resolve model + open the selected camera/mic at the model's specs.
    const model = models.realtime(modelId);
    setStatus("wait", "Opening camera…");
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
    localPlaceholder.style.display = "none";

    // 3) Connect to Decart using the EPHEMERAL token (never the real key).
    setStatus("wait", "Connecting to AI…");
    const client = createDecartClient({ apiKey: token });
    realtimeClient = await client.realtime.connect(localStream, {
      model,
      onRemoteStream: (remoteStream) => {
        remoteVideo.srcObject = remoteStream;
        remotePlaceholder.style.display = "none";
        window.__prismRemoteStream = remoteStream; // shared with the OBS output window
        setStatus("on", "Live");
        toast("You're live ✨");
      },
      initialState: {
        prompt: { text: promptInput.value.trim() || "cinematic, high quality", enhance: true },
      },
    });
  } catch (err) {
    console.error(err);
    toast(err.message || "Failed to start.", true);
    setStatus("err", "Error");
    await stop();
  }
}

function applyPrompt() {
  const text = promptInput.value.trim();
  if (!realtimeClient || !text) return;
  try {
    realtimeClient.setPrompt(text);
    toast("Prompt updated");
  } catch (err) {
    toast("Could not update prompt.", true);
  }
}

async function stop() {
  try { realtimeClient?.disconnect(); } catch {}
  realtimeClient = null;
  if (localStream) {
    localStream.getTracks().forEach((t) => t.stop());
    localStream = null;
  }
  remoteVideo.srcObject = null;
  localVideo.srcObject = null;
  window.__prismRemoteStream = null;
  localPlaceholder.style.display = "";
  remotePlaceholder.style.display = "";
  if (obsWindow && !obsWindow.closed) obsWindow.close();
  setLiveUI(false);
  setStatus("", "Idle");
}

// ── Output actions ──────────────────────────────────────────────────────────
async function togglePip() {
  try {
    if (document.pictureInPictureElement) {
      await document.exitPictureInPicture();
    } else if (remoteVideo.srcObject) {
      await remoteVideo.requestPictureInPicture();
    }
  } catch {
    toast("Picture-in-Picture isn't available here.", true);
  }
}

function openObsOutput() {
  if (!window.__prismRemoteStream) return;
  obsWindow = window.open("/output.html", "prism-obs-output", "width=1280,height=720");
  if (!obsWindow) toast("Allow pop-ups to open the OBS output window.", true);
  else toast("Capture this new window in OBS as a Window Capture source.");
}

async function goFullscreen() {
  try {
    await remoteVideo.requestFullscreen();
  } catch {
    toast("Fullscreen not available.", true);
  }
}

// ── Wire up ─────────────────────────────────────────────────────────────────
startBtn.addEventListener("click", start);
stopBtn.addEventListener("click", stop);
applyPromptBtn.addEventListener("click", applyPrompt);
pipBtn.addEventListener("click", togglePip);
obsBtn.addEventListener("click", openObsOutput);
fsBtn.addEventListener("click", goFullscreen);
promptInput.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) applyPrompt();
});
window.addEventListener("beforeunload", stop);

buildPresets();
loadConfig().then(primeAndListDevices);
