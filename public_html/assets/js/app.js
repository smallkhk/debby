/* ── Eclipse Creator Studio — shared front-end helpers ─────────────────────── */

const API = {
  async call(path, { method = 'GET', body = null, form = null } = {}) {
    const opts = { method, credentials: 'same-origin' };
    if (form) {
      opts.body = form;
    } else if (body) {
      opts.headers = { 'Content-Type': 'application/json' };
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(path, opts);
    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error('Server returned an unreadable response.');
    }
    if (!res.ok || data.ok === false) {
      const err = new Error(data.error || 'Request failed.');
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  },
  get: (p) => API.call(p),
  post: (p, body) => API.call(p, { method: 'POST', body }),
};

function toast(msg, isError = false) {
  let el = document.getElementById('toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'toast';
    el.className = 'toast';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.classList.toggle('error', isError);
  el.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.remove('show'), 3800);
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Fetch the signed-in user, or null. Cached per page load. */
let _mePromise = null;
function me(force = false) {
  if (!_mePromise || force) _mePromise = API.get('/api/auth.php?action=me').catch(() => ({ user: null }));
  return _mePromise;
}

/** Redirect to login unless signed in. Returns the user. */
async function requireUser() {
  const { user } = await me();
  if (!user) {
    location.href = '/login.html?next=' + encodeURIComponent(location.pathname);
    return null;
  }
  return user;
}

/** Render the shared nav into #nav, highlighting balance + auth state. */
async function mountNav(active = '') {
  const host = document.getElementById('nav');
  if (!host) return null;
  const { user } = await me();
  const link = (href, label, key) =>
    `<a href="${href}"${active === key ? ' style="color:var(--text)"' : ''}>${label}</a>`;

  // Links are duplicated into a collapsible menu rather than simply hidden on
  // narrow screens. Hiding them left phone users with no route to the Create
  // page at all, so half the models looked as though they did not exist.
  const links = user
    ? [['/studio.html', 'Studio', 'studio'], ['/create.html', 'Create', 'create'], ['/topup.html', 'Top up', 'topup']]
    : [['/index.html#features', 'Features', ''], ['/index.html#pricing', 'Pricing', '']];

  host.innerHTML = `
    <div class="wrap nav-inner">
      <a class="brand" href="/"><span class="logo"></span> Eclipse</a>
      <div class="nav-links">
        ${links.map(([h, l, k]) => `<span class="hide-sm">${link(h, l, k)}</span>`).join('')}
        ${user ? `
          <a class="balance-chip" href="/topup.html" title="Your credit balance">
            <b id="navBalance">${user.points}</b> pts
          </a>
          <button class="btn btn-ghost btn-sm" id="navLogout">Log out</button>
        ` : `
          <a class="btn btn-ghost btn-sm" href="/login.html">Log in</a>
          <a class="btn btn-primary btn-sm" href="/register.html">Get started</a>
        `}
        <button class="nav-burger" id="navBurger" aria-label="Menu" aria-expanded="false">☰</button>
      </div>
    </div>
    <div class="nav-drawer" id="navDrawer" hidden>
      ${links.map(([h, l]) => `<a href="${h}">${l}</a>`).join('')}
    </div>`;

  const burger = document.getElementById('navBurger');
  const drawer = document.getElementById('navDrawer');
  burger?.addEventListener('click', () => {
    const open = drawer.hasAttribute('hidden');
    drawer.toggleAttribute('hidden', !open);
    burger.setAttribute('aria-expanded', String(open));
    burger.textContent = open ? '✕' : '☰';
  });

  const out = document.getElementById('navLogout');
  if (out) {
    out.addEventListener('click', async () => {
      await API.post('/api/auth.php?action=logout', {});
      location.href = '/';
    });
  }
  return user;
}

/** Update the balance chip after a spend/top-up without a full reload. */
function setBalance(points) {
  const el = document.getElementById('navBalance');
  if (el && typeof points === 'number') el.textContent = points;
}

function copyText(text, label = 'Copied') {
  const done = () => toast(label);
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(() => fallback());
  } else fallback();
  function fallback() {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch { toast('Copy failed', true); }
    ta.remove();
  }
}
