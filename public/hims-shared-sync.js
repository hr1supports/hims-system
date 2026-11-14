/*
 * HIMS shared records sync
 * ---------------------------------------------------------------------------
 * Claims ("claims") and ESS requests ("essRequests") used to live only in one browser's
 * localStorage, so an HR Officer on another computer never saw them. The pages still read and
 * write those same lists, but this file now:
 *   - sends every change to the database (backend/shared-records.php), and
 *   - loads the latest lists from the database after login, when you switch pages, and
 *     when you come back to the tab.
 * The server checks the role rules (only an HR Officer or Admin approves; employees only
 * send their own requests), so a refused change is undone on the next load.
 * Load this BEFORE the page's other scripts.
 */
(function () {
  "use strict";
  if (window.HIMS_SHARED) return;

  const KINDS = ["claims", "essRequests"];
  const store = window.localStorage;
  const rawSet = Storage.prototype.setItem;
  const api = location.port === "5500"
    ? `${location.protocol}//${location.hostname}:8000/backend/api.php`
    : (location.pathname.includes("/backend/") ? "api.php" : "backend/api.php");
  const credentials = api.startsWith("http") ? "include" : "same-origin";

  const snapshot = { claims: null, essRequests: null }; // uid -> JSON text, as the server last confirmed
  const ready = { claims: false, essRequests: false };
  let disabled = false; // table missing or not signed in
  let queue = Promise.resolve();
  let lastPull = 0;

  // Old records have no id of their own. Make one from their content, so every computer
  // that still has the same old record gives it the same id (no duplicates when it is uploaded).
  function hash(text) {
    let h1 = 0x811c9dc5, h2 = 0x01000193;
    for (let i = 0; i < text.length; i++) {
      const c = text.charCodeAt(i);
      h1 = Math.imul(h1 ^ c, 16777619);
      h2 = Math.imul(h2 ^ c, 2246822519);
    }
    return (h1 >>> 0).toString(36) + (h2 >>> 0).toString(36);
  }
  function uidFor(kind, r) {
    if (kind === "essRequests" && r.id && /^[A-Za-z0-9_-]{3,36}$/.test(String(r.id))) return "e-" + r.id;
    return (kind === "claims" ? "c-" : "e-") + hash([r.id, r.name, r.amount, r.date || r.created, r.type, r.details].join("|"));
  }
  function withUids(kind, list) {
    let changed = false;
    list.forEach((r) => { if (r && typeof r === "object" && !r._uid) { r._uid = uidFor(kind, r); changed = true; } });
    return changed;
  }
  const parse = (text) => { try { const v = JSON.parse(text); return Array.isArray(v) ? v : null; } catch (e) { return null; } };
  const writeLocal = (kind, list) => rawSet.call(store, kind, JSON.stringify(list));
  const notifyPages = (kind) => {
    try { window.dispatchEvent(new StorageEvent("storage", { key: kind })); } catch (e) { /* old browser */ }
    window.dispatchEvent(new CustomEvent("hims-shared-updated", { detail: { kind } }));
  };
  const warn = (m) => (window.showWarningToast || console.warn)(m);

  async function call(query, options) {
    const res = await fetch(`${api}?${new URLSearchParams({ resource: "shared-records", ...query })}`, {
      credentials,
      ...options,
      headers: { "Content-Type": "application/json", "X-HR3-Request": "1", ...((options && options.headers) || {}) },
    });
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (res.status === 401 || res.status === 403) { const err = new Error((data && data.error) || "Not signed in."); err.quiet = true; throw err; }
    if (!res.ok) { const err = new Error((data && data.error) || `Server error (HTTP ${res.status}).`); err.setup = !!(data && data.setup); throw err; }
    return data;
  }

  // ---------------------------------------------------------------------
  // Push: compare the list with what the server last confirmed and send only the differences.
  // ---------------------------------------------------------------------
  function push(kind, list) {
    if (disabled || !ready[kind]) return queue;
    const known = snapshot[kind] || {};
    const upserts = list.filter((r) => r && r._uid && known[r._uid] !== JSON.stringify(r));
    const present = new Set(list.map((r) => r && r._uid));
    const deletes = Object.keys(known).filter((uid) => !present.has(uid));
    if (!upserts.length && !deletes.length) return queue;
    queue = queue.then(async () => {
      try {
        const result = await call({}, { method: "POST", body: JSON.stringify({ kind, upserts, deletes }) });
        const current = parse(store.getItem(kind)) || [];
        (result.saved || []).forEach((rec) => {
          known[rec._uid] = JSON.stringify(rec);
          const i = current.findIndex((r) => r && r._uid === rec._uid);
          if (i >= 0) current[i] = rec; // e.g. the receipt became a link to the server copy
        });
        (result.deleted || []).forEach((uid) => { delete known[uid]; });
        snapshot[kind] = known;
        writeLocal(kind, current);
        if ((result.refused || []).length) {
          const n = result.refused.length;
          warn(`${n > 1 ? n + " changes were" : "A change was"} not saved: ${result.refused[0].error}`);
          lastPull = 0;
          pull(kind, true); // put back what the server has
        }
      } catch (e) {
        if (!e.quiet) warn(`Could not save to the database: ${e.message}`);
      }
    });
    return queue;
  }

  // Every write to "claims" / "essRequests" goes through here.
  Storage.prototype.setItem = function (key, value) {
    if (this !== store || !KINDS.includes(key)) return rawSet.call(this, key, value);
    const list = parse(String(value));
    if (!list) return rawSet.call(this, key, value);
    const changed = withUids(key, list);
    rawSet.call(this, key, changed ? JSON.stringify(list) : value);
    push(key, list);
    return undefined;
  };

  // ---------------------------------------------------------------------
  // Pull: load the server's list. The first time, records that exist only in this browser are uploaded.
  // ---------------------------------------------------------------------
  async function pull(kind, force) {
    if (disabled && !force) return;
    await queue; // never overwrite a change that is still being sent
    let result;
    try {
      result = await call({ kind });
    } catch (e) {
      if (e.setup) { disabled = true; console.warn(e.message); }
      return;
    }
    disabled = false;
    const server = result.records || [];
    const known = {};
    server.forEach((r) => { if (r && r._uid) known[r._uid] = JSON.stringify(r); });
    let toUpload = [];
    if (!ready[kind]) {
      const local = parse(store.getItem(kind)) || [];
      withUids(kind, local);
      toUpload = local.filter((r) => r && r._uid && !known[r._uid]);
    }
    snapshot[kind] = known;
    ready[kind] = true;
    const merged = server.concat(toUpload);
    const before = store.getItem(kind) || "[]";
    writeLocal(kind, merged);
    if (toUpload.length) push(kind, merged);
    if (before !== JSON.stringify(merged)) notifyPages(kind);
  }

  // The employee portal only has the employee's own ESS requests.
  const isPortal = /employee_dashboard/.test(location.pathname);
  const pullKinds = isPortal ? ["essRequests"] : KINDS;
  let inFlight = Promise.resolve();
  function pullAll(force) {
    if (!force && Date.now() - lastPull < 10000) return inFlight; // a recent load is still good (or still running)
    lastPull = Date.now();
    inFlight = Promise.all(pullKinds.map((k) => pull(k, force)));
    return inFlight;
  }

  // When to load: page open, after login, page switches, and coming back to the tab.
  // Only HR roles use claims and ESS requests on this page (Super Admin and trainees do not).
  const signedIn = () => {
    try {
      const u = JSON.parse(store.getItem("currentUser") || "null");
      return !!u && ["HR1", "HR3", "ADMIN", "HR_OFFICER", "HR_STAFF"].includes(u.role);
    } catch (e) { return false; }
  };
  function maybePull(force) { if (isPortal || signedIn()) pullAll(force); }

  document.addEventListener("visibilitychange", () => { if (!document.hidden) maybePull(false); });
  document.addEventListener("click", (e) => { if (e.target.closest && e.target.closest(".nav-item")) maybePull(false); }, true);
  window.addEventListener("load", () => {
    maybePull(true);
    const prevAuth = window.showAuthenticatedDashboard;
    if (typeof prevAuth === "function" && !prevAuth.__shared) {
      const wrapped = function () { const r = prevAuth.apply(this, arguments); disabled = false; ready.claims = ready.essRequests = false; if (signedIn()) pullAll(true); return r; };
      wrapped.__shared = true;
      window.showAuthenticatedDashboard = wrapped;
    }
  });

  window.HIMS_SHARED = {
    pull: (force) => pullAll(force !== false),
    isLive: (kind) => !!ready[kind] && !disabled,
  };
})();
