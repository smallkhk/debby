/**
 * Eclipse Creator Studio — Electron main process.
 *
 * All calls to the website's API are proxied through here rather than made
 * from the renderer. The renderer is loaded from file://, so a browser-style
 * fetch would be blocked by CORS and would not carry the PHP session cookie.
 * Electron's net module shares the app session's cookie jar, so logging in
 * here behaves exactly like logging in through a browser.
 */

'use strict';

const { app, BrowserWindow, ipcMain, net, session, shell, globalShortcut, dialog } = require('electron');
const path = require('node:path');
const fs = require('node:fs');

const DEFAULT_SERVER = 'https://eclipselivecam.online';
const CONFIG_FILE = () => path.join(app.getPath('userData'), 'config.json');

let mainWindow = null;

// ── Config (server URL + device activation token) ────────────────────────────
function readConfig() {
  try {
    return JSON.parse(fs.readFileSync(CONFIG_FILE(), 'utf8'));
  } catch {
    return { serverUrl: DEFAULT_SERVER, deviceToken: '' };
  }
}
function writeConfig(patch) {
  const next = { ...readConfig(), ...patch };
  try {
    fs.mkdirSync(path.dirname(CONFIG_FILE()), { recursive: true });
    fs.writeFileSync(CONFIG_FILE(), JSON.stringify(next, null, 2));
  } catch (e) {
    console.error('Could not save config:', e.message);
  }
  return next;
}

function serverUrl() {
  const u = (readConfig().serverUrl || DEFAULT_SERVER).trim();
  return u.replace(/\/+$/, '');
}

// ── API proxy ────────────────────────────────────────────────────────────────
function apiRequest({ pathname, method = 'GET', body = null }) {
  return new Promise((resolve) => {
    let target;
    try {
      target = new URL(pathname, serverUrl() + '/').toString();
    } catch {
      return resolve({ ok: false, error: 'Invalid server address. Check it in Settings.' });
    }

    const req = net.request({ method, url: target, session: session.defaultSession, useSessionCookies: true });
    req.setHeader('Accept', 'application/json');
    if (body) req.setHeader('Content-Type', 'application/json');

    let raw = '';
    req.on('response', (res) => {
      res.on('data', (c) => (raw += c.toString()));
      res.on('end', () => {
        let data;
        try {
          data = JSON.parse(raw);
        } catch {
          // An HTML error page means we hit the wrong URL or the host is down.
          return resolve({
            ok: false,
            status: res.statusCode,
            error: res.statusCode === 404
              ? 'Not found on the server — check the address in Settings.'
              : `Server returned an unexpected response (HTTP ${res.statusCode}).`,
          });
        }
        resolve({ ...data, status: res.statusCode });
      });
    });
    req.on('error', (err) => {
      resolve({ ok: false, error: 'Could not reach the server: ' + err.message });
    });
    if (body) req.write(JSON.stringify(body));
    req.end();
  });
}

// ── Windows ──────────────────────────────────────────────────────────────────
function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1360,
    height: 880,
    minWidth: 1024,
    minHeight: 680,
    backgroundColor: '#06070d',
    show: false,
    autoHideMenuBar: true,
    title: 'Eclipse Creator Studio',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      // The output window is opened with window.open and reads the live
      // MediaStream off its opener, which requires a shared process.
      sandbox: false,
    },
  });

  mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));
  mainWindow.once('ready-to-show', () => mainWindow.show());

  mainWindow.on('closed', () => { mainWindow = null; });
}

// ── IPC ──────────────────────────────────────────────────────────────────────
ipcMain.handle('api', (_e, payload) => apiRequest(payload));
ipcMain.handle('config:get', () => readConfig());
ipcMain.handle('config:set', (_e, patch) => writeConfig(patch));

ipcMain.handle('output:toggleAlwaysOnTop', (e) => {
  const win = BrowserWindow.fromWebContents(e.sender);
  if (!win) return false;
  const next = !win.isAlwaysOnTop();
  win.setAlwaysOnTop(next, 'screen-saver');
  return next;
});

ipcMain.handle('recording:save', async (_e, { buffer, suggestedName }) => {
  const { canceled, filePath } = await dialog.showSaveDialog(mainWindow, {
    title: 'Save recording',
    defaultPath: path.join(app.getPath('videos'), suggestedName),
    filters: [{ name: 'WebM video', extensions: ['webm'] }],
  });
  if (canceled || !filePath) return { ok: false };
  try {
    fs.writeFileSync(filePath, Buffer.from(buffer));
    return { ok: true, filePath };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

ipcMain.handle('shell:showItem', (_e, p) => shell.showItemInFolder(p));
ipcMain.handle('shell:openExternal', (_e, url) => {
  if (/^https?:\/\//i.test(url)) shell.openExternal(url);
});

// ── Lifecycle ────────────────────────────────────────────────────────────────
// A second launch should focus the running window, not start a rival instance
// that fights over the camera.
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore();
      mainWindow.focus();
    }
  });

  app.whenReady().then(() => {
    createMainWindow();

    // Global hotkeys work even when the app is behind OBS or a game.
    globalShortcut.register('CommandOrControl+Shift+S', () =>
      mainWindow?.webContents.send('hotkey', 'toggle-stream'));
    globalShortcut.register('CommandOrControl+Shift+P', () =>
      mainWindow?.webContents.send('hotkey', 'toggle-pip'));
    globalShortcut.register('CommandOrControl+Shift+R', () =>
      mainWindow?.webContents.send('hotkey', 'toggle-record'));

    app.on('activate', () => {
      if (BrowserWindow.getAllWindows().length === 0) createMainWindow();
    });
  });

  app.on('will-quit', () => globalShortcut.unregisterAll());
  app.on('window-all-closed', () => { if (process.platform !== 'darwin') app.quit(); });
}
