/**
 * Preload — the only bridge between the renderer and Node.
 * Everything is an explicit, narrow method; no ipcRenderer is exposed directly.
 */

'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('eclipse', {
  // ── API (proxied through main so cookies and CORS behave) ──────────────────
  api: {
    get:  (pathname)       => ipcRenderer.invoke('api', { pathname, method: 'GET' }),
    post: (pathname, body) => ipcRenderer.invoke('api', { pathname, method: 'POST', body: body || {} }),
  },

  // ── Local settings ─────────────────────────────────────────────────────────
  config: {
    get: ()      => ipcRenderer.invoke('config:get'),
    set: (patch) => ipcRenderer.invoke('config:set', patch),
  },

  // ── Windows ────────────────────────────────────────────────────────────────
  toggleAlwaysOnTop: () => ipcRenderer.invoke('output:toggleAlwaysOnTop'),

  // ── Recording ──────────────────────────────────────────────────────────────
  saveRecording: (arrayBuffer, suggestedName) =>
    ipcRenderer.invoke('recording:save', { buffer: new Uint8Array(arrayBuffer), suggestedName }),
  showItemInFolder: (p) => ipcRenderer.invoke('shell:showItem', p),
  openExternal: (url)   => ipcRenderer.invoke('shell:openExternal', url),

  // ── Global hotkeys ─────────────────────────────────────────────────────────
  onHotkey: (handler) => {
    ipcRenderer.on('hotkey', (_e, name) => handler(name));
  },
});
