/*
 * HIMS login security (browser side)
 * ---------------------------------------------------------------------------
 * 1. Sign-in code screen: after the password is correct, the person types the
 *    6-digit code sent to their email (or printed in the PHP server window
 *    while no email account is set up). A code works for 2 minutes; the whole
 *    code step must be finished within 5 minutes.
 * 2. Session timeout: after 5 minutes with no mouse, keyboard, scroll or touch
 *    activity, the person is signed out. Activity in any HIMS tab counts.
 *
 * The server enforces the same limits (backend/accounts.php and config.php),
 * so these rules hold even if this file is changed or blocked.
 */
(function () {
  "use strict";

  const API = "backend/api.php";
  const IDLE_LIMIT_MS = 5 * 60 * 1000;
  const ACTIVITY_KEY = "himsLastActivity";
  const page = /employee_dashboard/i.test(location.pathname) ? "employee"
    : /trainee_dashboard/i.test(location.pathname) ? "trainee" : "main";

  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const clock = (sec) => `${Math.floor(Math.max(0, sec) / 60)}:${String(Math.max(0, sec) % 60).padStart(2, "0")}`;

  async function post(resource, body) {
    const res = await fetch(`${API}?resource=${resource}`, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json" }, body: JSON.stringify(body || {}),
    });
    let data = {};
    try { data = await res.json(); } catch (e) { /* empty reply */ }
    return { ok: res.ok, status: res.status, data };
  }

  function addStyle() {
    if ($("himsSecurityStyle")) return;
    const s = document.createElement("style");
    s.id = "himsSecurityStyle";
    s.textContent = `
      .otp-step { display: grid; gap: 12px; }
      .otp-step p { margin: 0; line-height: 1.45; }
      .otp-step .otp-note { font-size: .85rem; padding: 8px 10px; border-radius: 10px; background: rgba(24,181,127,.12); border: 1px dashed rgba(24,181,127,.55); }
      .otp-step #otpCode { font-size: 1.5rem; letter-spacing: .5em; text-align: center; font-weight: 700; }
      .otp-step .otp-timers { display: flex; justify-content: space-between; gap: 8px; font-size: .82rem; opacity: .85; }
      .otp-step .otp-timers .otp-late { color: #e0675b; opacity: 1; font-weight: 700; }
      .account-activity-summary { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 10px 0 12px; }
      .activity-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(128,128,128,.35); background: transparent; color: inherit; font-size: .82rem; font-weight: 600; cursor: pointer; box-shadow: none; }
      .activity-chip strong { font-size: .95rem; }
      .activity-chip.is-selected { outline: 2px solid #18b57f; outline-offset: 1px; }
      .activity-chip.tone-locked strong { color: #d9534f; }
      .activity-chip.tone-online strong { color: #18b57f; }
      .activity-note { flex-basis: 100%; opacity: .75; }
      .online-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #18b57f; box-shadow: 0 0 0 3px rgba(24,181,127,.2); margin-right: 4px; vertical-align: middle; }
      #super-accounts .online-label { color: #18b57f; font-weight: 700; display: block; margin-top: 3px; font-size: .72rem; }
      #super-accounts .super-table-heading select { margin-left: auto; max-width: 180px; }
      #super-accounts .device-label { display: block; margin-top: 3px; font-size: .72rem; opacity: .8; }
      .sessions-panel { width: min(980px, calc(100vw - 32px)); max-height: 85vh; overflow: auto; }
      .sessions-panel td small { display: block; }
      .otp-choose { display: grid; gap: 10px; }
      .otp-choose p { margin: 0 0 4px; }
      .otp-choose button:disabled { opacity: .5; cursor: not-allowed; }
      .otp-hint { font-size: .8rem; opacity: .8; line-height: 1.4; }
      .totp-panel { width: min(520px, calc(100vw - 32px)); max-height: 90vh; overflow: auto; }
      .totp-panel #totpBody { display: grid; gap: 10px; }
      .totp-steps { margin: 0; padding-left: 20px; line-height: 1.5; }
      .totp-qr { width: 220px; max-width: 100%; justify-self: center; background: #fff; padding: 6px; border-radius: 10px; color: #333; text-align: center; }
      .totp-qr svg { width: 100%; height: auto; display: block; }
      .totp-key code { font-size: 1rem; letter-spacing: .08em; word-break: break-all; }
      .totp-on { display: flex; align-items: center; gap: 6px; }
      /* Same look as the other HIMS windows (Support, Profile) */
      .totp-panel label { display: block; color: var(--text); font-size: .85rem; font-weight: 700; margin: 12px 0 6px; }
      .totp-panel input { width: 100%; box-sizing: border-box; border: 1px solid var(--line); border-radius: 10px;
        background: var(--panel-soft); color: var(--text); padding: 11px 12px; font: inherit; }
      .totp-panel input:focus { outline: 2px solid var(--green); outline-offset: 1px; border-color: transparent; }
      .totp-panel #totpCode { text-align: center; letter-spacing: .45em; font-size: 1.25rem; font-weight: 700; }
      .totp-panel .action-row { display: flex; gap: 10px; margin-top: 12px; }
      .totp-panel .action-row button { flex: 1; border: 0; border-radius: 10px; background: var(--green); color: #fff;
        padding: 12px 16px; font-weight: 700; cursor: pointer; transition: filter .15s ease, transform .15s ease; }
      .totp-panel .action-row button:hover { filter: brightness(1.08); transform: translateY(-1px); }
      .totp-panel .action-row button:disabled { opacity: .6; cursor: default; transform: none; }
      .totp-panel .action-row button.danger-action { background: rgba(185, 28, 28, 0.09); color: #a11b1b; border: 1px solid rgba(185, 28, 28, 0.35); }
      body.dark-mode .totp-panel .action-row button.danger-action { color: #ffaaaa; background: rgba(185, 28, 28, 0.16); }
      .totp-panel .login-message:empty { display: none; }
      .otp-step .otp-back { background: none; border: 0; padding: 0; color: inherit; text-decoration: underline; cursor: pointer; justify-self: start; font-size: .85rem; opacity: .8; box-shadow: none; }
      #loginPage .otp-step .otp-back { background: none !important; border: 0 !important; box-shadow: none !important; min-width: 0; padding: 0; margin-top: 2px; color: #cfe9df; font-weight: 600; }
      #loginPage .otp-step .otp-back:hover { transform: none; color: #fff; }
      .otp-step #resetCode { font-size: 1.5rem; letter-spacing: .5em; text-align: center; font-weight: 700; }
      #resetStep .password-field { margin: 0; }
      #resetStep .action-row button { flex: 1; }
    `;
    document.head.appendChild(s);
  }

  // -------------------------------------------------------------------------
  // 1. Sign-in code step (login page only)
  //    Email code (2 minutes each), or the authenticator app on the phone when
  //    the person has turned it on. 5 minutes in total to finish.
  // -------------------------------------------------------------------------
  let timer = null;
  let state = null; // { codeEnds, windowEnds, resendAt, onDone, methods, mode, emailSent, email, delivery }

  function loginMessage(text, type) {
    if (typeof window.showLoginMessage === "function") window.showLoginMessage(text, type || "error");
    else if ($("loginMessage")) $("loginMessage").textContent = text;
  }

  function buildCodeStep() {
    if ($("otpStep")) return;
    const step = document.createElement("div");
    step.id = "otpStep";
    step.className = "otp-step";
    step.hidden = true;
    step.innerHTML = `
      <div id="otpChoose" class="otp-choose" hidden>
        <p>How do you want to get your sign-in code?</p>
        <button type="button" id="otpPickEmail">Email me a code</button>
        <button type="button" id="otpPickApp" class="secondary-button">Use authenticator app</button>
        <small id="otpAppHint" class="otp-hint" hidden>Not set up yet for this account. After signing in, click your name, then "Authenticator app" to connect your phone.</small>
      </div>
      <div id="otpEnter">
        <p id="otpInfo"></p>
        <p id="otpNote" class="otp-note" hidden></p>
        <label for="otpCode" class="sr-only" style="position:absolute;left:-9999px">Sign-in code</label>
        <input id="otpCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••">
        <div class="otp-timers"><span id="otpCodeTimer"></span><span id="otpWindowTimer"></span></div>
        <div class="action-row">
          <button type="button" id="otpVerify">Verify code</button>
          <button type="button" id="otpResend" class="secondary-button">Send a new code</button>
        </div>
        <button type="button" id="otpSwitch" class="otp-back" hidden></button>
      </div>
      <button type="button" id="otpBack" class="otp-back">Back to email and password</button>`;
    const creds = $("loginCredentials");
    creds.parentNode.insertBefore(step, creds.nextSibling);

    const input = $("otpCode");
    input.addEventListener("input", () => {
      input.value = input.value.replace(/\D/g, "").slice(0, 6);
      if (input.value.length === 6) verify();
    });
    // Stop the page's own Enter handler (which would run login() again).
    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") { e.preventDefault(); e.stopPropagation(); verify(); }
    });
    $("otpVerify").addEventListener("click", verify);
    $("otpResend").addEventListener("click", resend);
    $("otpBack").addEventListener("click", () => backToPassword(""));
    $("otpPickEmail").addEventListener("click", () => useMethod("email"));
    $("otpPickApp").addEventListener("click", () => useMethod("app"));
    $("otpSwitch").addEventListener("click", () => useMethod(state && state.mode === "app" ? "email" : "app"));
  }

  function applyStatus(r) {
    const now = Date.now();
    if (r.codeExpiresIn !== undefined) state.codeEnds = now + (Number(r.codeExpiresIn) || 0) * 1000;
    if (r.windowEndsIn !== undefined) state.windowEnds = now + (Number(r.windowEndsIn) || 0) * 1000;
    if (r.resendIn !== undefined) state.resendAt = now + (Number(r.resendIn) || 0) * 1000;
    if (r.methods) state.methods = r.methods;
    if (r.emailSent !== undefined) state.emailSent = !!r.emailSent;
    if (r.email) state.email = r.email;
    if (r.delivery && r.delivery !== "wait" && r.delivery !== "choose") state.delivery = r.delivery;
    render();
  }

  function render() {
    if (!state) return;
    const choosing = state.mode === "choose";
    $("otpChoose").hidden = !choosing;
    $("otpEnter").hidden = choosing;
    const title = document.querySelector("#loginPanel h3");
    if (title) title.textContent = choosing ? "Verify it's you" : "Enter your sign-in code";
    if (choosing) {
      const appOn = (state.methods || []).includes("app");
      $("otpPickApp").disabled = !appOn;
      $("otpAppHint").hidden = appOn;
      tick();
      return;
    }
    const app = state.mode === "app";
    $("otpInfo").innerHTML = app
      ? "Open your authenticator app (Google or Microsoft Authenticator) and type the 6-digit code shown for <strong>HIMS Hospital System</strong>."
      : state.delivery === "email"
        ? `We sent a 6-digit code to <strong>${esc(state.email || "your email")}</strong>. Enter it below to finish signing in.`
        : `Enter the 6-digit sign-in code for <strong>${esc(state.email || "your account")}</strong>.`;
    const note = $("otpNote");
    note.hidden = app || state.delivery !== "terminal";
    note.textContent = "Local mode: email sending is not set up yet, so the code is shown in the PHP server window (the VS Code terminal running the site).";
    $("otpResend").hidden = app;
    const canSwitch = (state.methods || []).includes("app");
    $("otpSwitch").hidden = !canSwitch;
    $("otpSwitch").textContent = app ? "Email me a code instead" : "Use authenticator app instead";
    tick();
  }

  function tick() {
    if (!state) return;
    const now = Date.now();
    const codeLeft = Math.ceil((state.codeEnds - now) / 1000);
    const windowLeft = Math.ceil((state.windowEnds - now) / 1000);
    const resendLeft = Math.ceil((state.resendAt - now) / 1000);
    if (windowLeft <= 0) {
      backToPassword("Time is up. You have 5 minutes to enter the code. Enter your email and password again.");
      return;
    }
    const codeTimer = $("otpCodeTimer");
    if (state.mode === "email") {
      codeTimer.textContent = codeLeft > 0 ? `Code expires in ${clock(codeLeft)}` : "Code expired: send a new one";
      codeTimer.className = codeLeft > 0 ? "" : "otp-late";
    } else {
      codeTimer.textContent = state.mode === "app" ? "The app shows a new code every 30 seconds" : "";
      codeTimer.className = "";
    }
    $("otpWindowTimer").textContent = `${clock(windowLeft)} left to sign in`;
    const resend = $("otpResend");
    resend.disabled = resendLeft > 0;
    resend.textContent = resendLeft > 0 ? `Send a new code (${resendLeft}s)` : "Send a new code";
  }

  async function useMethod(method) {
    if (!state) return;
    loginMessage("", "");
    $("otpCode").value = "";
    if (method === "app") {
      state.mode = "app";
      render();
      setTimeout(() => $("otpCode").focus(), 50);
      return;
    }
    state.mode = "email";
    render();
    if (!state.emailSent) await resend(true); // first email code
    setTimeout(() => $("otpCode").focus(), 50);
  }

  function showCodeStep(result, onDone) {
    addStyle();
    buildCodeStep();
    state = { onDone, methods: ["email"], mode: "email", emailSent: false, codeEnds: 0, windowEnds: 0, resendAt: 0 };
    $("loginCredentials").hidden = true;
    $("otpStep").hidden = false;
    const title = document.querySelector("#loginPanel h3");
    if (title) { title.dataset.originalText = title.dataset.originalText || title.textContent; }
    loginMessage("", "");
    $("otpCode").value = "";
    state.mode = result.delivery === "choose" ? "choose" : "email";
    applyStatus(result);
    clearInterval(timer);
    timer = setInterval(tick, 1000);
    if (state.mode !== "choose") setTimeout(() => $("otpCode").focus(), 50);
  }

  function backToPassword(message) {
    clearInterval(timer);
    timer = null;
    state = null;
    if ($("otpStep")) $("otpStep").hidden = true;
    if ($("loginCredentials")) $("loginCredentials").hidden = false;
    const title = document.querySelector("#loginPanel h3");
    if (title && title.dataset.originalText) title.textContent = title.dataset.originalText;
    if (!message) post("logout"); // cancel the sign-in in progress on the server too
    loginMessage(message || "", "error");
    if ($("password")) $("password").focus();
  }

  let busy = false;
  async function verify() {
    if (!state || busy || state.mode === "choose") return;
    const code = $("otpCode").value.replace(/\D/g, "");
    if (code.length !== 6) { loginMessage("Enter all 6 digits of the code."); return; }
    busy = true;
    $("otpVerify").disabled = true;
    $("otpVerify").textContent = "Checking...";
    try {
      const r = await post("otp-verify", { code, method: state.mode });
      if (r.ok && r.data.user) {
        const done = state.onDone;
        backToPassword("Signed in.");
        loginMessage("", "");
        touch();
        await done(r.data.user);
        return;
      }
      if (r.data.restart) { backToPassword(r.data.error || "Please sign in again."); return; }
      if (r.data.codeExpiresIn !== undefined) applyStatus(r.data);
      loginMessage(r.data.error || "That code didn't work.");
      $("otpCode").select();
    } catch (e) {
      loginMessage("Could not reach the server. Make sure HIMS is running.");
    } finally {
      busy = false;
      if ($("otpVerify")) { $("otpVerify").disabled = false; $("otpVerify").textContent = "Verify code"; }
    }
  }

  async function resend(first) {
    if (!state) return;
    $("otpResend").disabled = true;
    try {
      const r = await post("otp-resend");
      if (r.data.restart) { backToPassword(r.data.error || "Please sign in again."); return; }
      if (r.data.codeExpiresIn !== undefined) applyStatus(r.data);
      const sentText = r.data.delivery === "email" ? (first ? "Code sent to your email." : "A new code was sent to your email.")
        : (first ? "Your code is in the PHP server window." : "A new code is in the PHP server window.");
      loginMessage(r.ok ? sentText : (r.data.error || "Could not send a code."), r.ok ? "success" : "error");
      $("otpCode").value = "";
      $("otpCode").focus();
    } catch (e) {
      loginMessage("Could not reach the server. Make sure HIMS is running.");
    }
  }

  // -------------------------------------------------------------------------
  // 1b. Authenticator app setup (profile panel on the admin and employee pages)
  // -------------------------------------------------------------------------
  async function api(method, body) {
    const res = await fetch(`${API}?resource=totp`, {
      method, credentials: "same-origin", cache: "no-store",
      headers: { "Content-Type": "application/json" }, body: body ? JSON.stringify(body) : undefined,
    });
    let data = {};
    try { data = await res.json(); } catch (e) { /* empty */ }
    if (!res.ok) throw new Error(data.error || "Request failed.");
    return data;
  }
  function loadQrLibrary() {
    if (window.qrcode) return Promise.resolve(true);
    return new Promise((resolve) => {
      const s = document.createElement("script");
      s.src = "qrcode.js";
      s.onload = () => resolve(!!window.qrcode);
      s.onerror = () => resolve(false);
      document.head.appendChild(s);
    });
  }
  function totpDialog() {
    let d = $("totpDialog");
    if (d) return d;
    d = document.createElement("div");
    d.id = "totpDialog";
    d.className = "modal-backdrop";
    d.hidden = true;
    d.innerHTML = `<section class="modal-panel totp-panel" role="dialog" aria-modal="true" aria-labelledby="totpTitle">
      <div class="modal-header">
        <div><span class="modal-eyebrow">Security</span><h2 id="totpTitle">Authenticator app</h2></div>
        <button class="modal-close" type="button" aria-label="Close" data-totp-close>&times;</button>
      </div>
      <div id="totpBody"></div>
    </section>`;
    document.body.appendChild(d);
    d.addEventListener("click", (e) => { if (e.target === d || e.target.closest("[data-totp-close]")) d.hidden = true; });
    return d;
  }
  function totpMsg(text, ok) {
    const el = $("totpMsg");
    if (el) { el.textContent = text; el.className = `login-message ${ok ? "success" : "error"}`; }
  }
  async function openTotp() {
    addStyle();
    const d = totpDialog();
    d.hidden = false;
    const body = $("totpBody");
    body.innerHTML = '<p>Loading...</p>';
    let status;
    try { status = await api("GET"); } catch (e) { body.innerHTML = `<p class="login-message error">${esc(e.message)}</p>`; return; }
    if (!status.ready) {
      body.innerHTML = '<p>The authenticator app needs <strong>database/021_authenticator.sql</strong>. Ask your administrator to run it in MySQL Workbench.</p>';
      return;
    }
    if (status.enabled) {
      body.innerHTML = `<p class="totp-on"><span class="online-dot"></span><strong>On</strong> since ${esc(status.enabledAt || "")}.</p>
        <p>When you log in, choose <strong>Use authenticator app</strong> and type the code from your phone. You can still choose an email code.</p>
        <label for="totpOffPassword">To turn it off, enter your password</label>
        <input id="totpOffPassword" type="password" autocomplete="current-password">
        <div class="action-row"><button type="button" class="danger-action" id="totpOff">Turn off</button></div>
        <p id="totpMsg" class="login-message" role="alert"></p>`;
      $("totpOff").addEventListener("click", async () => {
        try { const r = await api("POST", { action: "disable", password: $("totpOffPassword").value }); totpMsg(r.message, true); setTimeout(openTotp, 1200); }
        catch (e) { totpMsg(e.message, false); }
      });
      return;
    }
    body.innerHTML = `<p>Use your phone to get sign-in codes, even without internet or load.</p>
      <ol class="totp-steps">
        <li>Install <strong>Google Authenticator</strong> or <strong>Microsoft Authenticator</strong> on your phone.</li>
        <li>In the app, tap <strong>+</strong> and scan this QR code.</li>
        <li>Type the 6-digit code the app shows, then click <strong>Turn on</strong>.</li>
      </ol>
      <div id="totpQr" class="totp-qr">Making QR code...</div>
      <p class="totp-key">Can't scan? Choose "Enter a setup key" in the app and type:<br><code id="totpKey"></code></p>
      <input id="totpCode" type="text" inputmode="numeric" maxlength="6" placeholder="6-digit code" autocomplete="one-time-code">
      <div class="action-row"><button type="button" id="totpOn">Turn on</button></div>
      <p id="totpMsg" class="login-message" role="alert"></p>`;
    try {
      const setup = await api("POST", { action: "start" });
      $("totpKey").textContent = setup.secret;
      const ok = await loadQrLibrary();
      if (ok) {
        const qr = window.qrcode(0, "M");
        qr.addData(setup.uri);
        qr.make();
        $("totpQr").innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
      } else {
        $("totpQr").textContent = "QR code could not be shown. Use the setup key below.";
      }
    } catch (e) { totpMsg(e.message, false); return; }
    const input = $("totpCode");
    input.addEventListener("input", () => { input.value = input.value.replace(/\D/g, "").slice(0, 6); });
    input.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); e.stopPropagation(); $("totpOn").click(); } });
    $("totpOn").addEventListener("click", async () => {
      try { const r = await api("POST", { action: "confirm", code: input.value }); totpMsg(r.message, true); setTimeout(openTotp, 1800); }
      catch (e) { totpMsg(e.message, false); input.select(); }
    });
  }
  function addTotpButton() {
    const panel = document.querySelector("#profilePanel .profile-actions, #employeeProfilePanel .profile-actions");
    if (!panel || $("totpOpen")) return;
    const b = document.createElement("button");
    b.type = "button";
    b.id = "totpOpen";
    b.className = "secondary-action";
    b.textContent = "Authenticator app";
    b.addEventListener("click", () => {
      try { if (typeof window.closeAllPanels === "function") window.closeAllPanels(); } catch (e) { /* ignore */ }
      const ep = $("employeeProfilePanel"); if (ep) ep.hidden = true;
      openTotp();
    });
    panel.insertBefore(b, panel.firstChild);
  }

  // -------------------------------------------------------------------------
  // 2. Sign out after 5 minutes without activity
  // -------------------------------------------------------------------------
  let lastWrite = 0;
  function touch() {
    const now = Date.now();
    if (now - lastWrite < 5000) return; // write at most every 5 seconds
    lastWrite = now;
    try { localStorage.setItem(ACTIVITY_KEY, String(now)); } catch (e) { /* storage blocked */ }
    memoryLast = now;
  }
  let memoryLast = Date.now();
  function lastActivity() {
    let stored = 0;
    try { stored = Number(localStorage.getItem(ACTIVITY_KEY)) || 0; } catch (e) { /* storage blocked */ }
    return Math.max(stored, memoryLast);
  }
  function signedIn() {
    if (page !== "main") return true;
    const dash = $("dashboard");
    return !!dash && dash.style.display === "block";
  }

  let signingOut = false;
  const ENDED_MESSAGE = "This device was signed out by the Super Admin. Please log in again.";
  async function signOutForInactivity(endedByAdmin) {
    if (signingOut) return;
    signingOut = true;
    const message = endedByAdmin ? ENDED_MESSAGE : "You were signed out after 5 minutes of inactivity. Please log in again.";
    try { await post("logout"); } catch (e) { /* server may already have ended it */ }
    if (page !== "main") {
      location.href = endedByAdmin ? "index.html?ended=1" : "index.html?timeout=1";
      return;
    }
    try { if (typeof window.closeAllPanels === "function") window.closeAllPanels(); } catch (e) { /* ignore */ }
    const chat = $("aiChatWidget");
    if (chat) chat.hidden = true;
    if (typeof window.logout === "function") window.logout();
    loginMessage(message, "error");
    signingOut = false;
  }

  function watchIdle() {
    ["mousemove", "mousedown", "keydown", "scroll", "touchstart", "wheel"].forEach((ev) =>
      window.addEventListener(ev, touch, { passive: true, capture: true }));
    lastWrite = 0;
    touch(); // opening or reloading a page counts as activity
    setInterval(() => {
      if (signedIn() && Date.now() - lastActivity() >= IDLE_LIMIT_MS) signOutForInactivity();
    }, 10000);
    // While the person is active, tell the server once a minute so it doesn't end the session
    // in the middle of filling in a long form. Idle people send nothing, so the server times them out too.
    setInterval(async () => {
      if (!signedIn() || Date.now() - lastActivity() > 60000) return;
      try {
        const res = await fetch(`${API}?resource=me`, { credentials: "same-origin", cache: "no-store" });
        if (res.status === 401) {
          let data = {};
          try { data = await res.json(); } catch (e) { /* empty reply */ }
          signOutForInactivity(/Super Admin/i.test(String(data.error || "")));
        }
      } catch (e) { /* server offline: nothing to keep alive */ }
    }, 60000);
  }

  function showTimeoutNotice() {
    if (page !== "main") return;
    const params = new URLSearchParams(location.search);
    if (params.get("timeout") !== "1" && params.get("ended") !== "1") return;
    loginMessage(params.get("ended") === "1" ? ENDED_MESSAGE : "You were signed out after 5 minutes of inactivity. Please log in again.", "error");
    history.replaceState(null, "", location.pathname);
  }

  // -------------------------------------------------------------------------
  // 3. "Signed in" popup on the Employee and Trainee portals
  //    (the admin-side dashboard already shows "Login completed." itself)
  // -------------------------------------------------------------------------
  function showLoginToast() {
    if (page === "main") return;
    let flagged = false;
    try { flagged = sessionStorage.getItem("himsLoginToast") === "1"; sessionStorage.removeItem("himsLoginToast"); } catch (e) { return; }
    if (!flagged) return;
    let box = $("toastContainer");
    if (!box) {
      box = document.createElement("div");
      box.id = "toastContainer";
      box.className = "toast-container";
      box.setAttribute("aria-live", "polite");
      document.body.appendChild(box);
    }
    const toast = document.createElement("div");
    toast.className = "success-toast";
    toast.innerHTML = `<span class="toast-check">&#10003;</span><span>${page === "employee" ? "Login completed. Welcome to your Employee portal." : "Login completed. Welcome to your Trainee portal."}</span>`;
    box.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
  }

  // -------------------------------------------------------------------------
  // 1c. Forgot password: email a 6-digit code, then set a new password with it
  // -------------------------------------------------------------------------
  let resetTimer = null;
  let resetResendAt = 0;
  let resetEndsAt = 0;

  function buildResetStep() {
    if ($("resetStep")) return;
    const step = document.createElement("div");
    step.id = "resetStep";
    step.className = "otp-step";
    step.hidden = true;
    step.innerHTML = `
      <div id="resetAsk" class="otp-step">
        <p>Enter the email of your HIMS account. We will send you a 6-digit code to reset your password.</p>
        <input id="resetEmail" type="email" placeholder="Email" autocomplete="username">
        <div class="action-row"><button type="button" id="resetSend">Send code</button></div>
      </div>
      <div id="resetEnter" class="otp-step" hidden>
        <p id="resetInfo"></p>
        <input id="resetCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" aria-label="6-digit code">
        <div class="password-field"><input id="resetNew" type="password" placeholder="New password" autocomplete="new-password"></div>
        <div class="password-field"><input id="resetConfirm" type="password" placeholder="Confirm new password" autocomplete="new-password"></div>
        <small class="otp-hint">At least 8 characters, with a letter and a number.</small>
        <div class="otp-timers"><span id="resetCodeTimer"></span><span></span></div>
        <div class="action-row">
          <button type="button" id="resetSave">Change password</button>
          <button type="button" id="resetResend" class="secondary-button">Send a new code</button>
        </div>
      </div>
      <button type="button" id="resetBack" class="otp-back">Back to login</button>`;
    const creds = $("loginCredentials");
    creds.parentNode.insertBefore(step, creds.nextSibling);

    const code = $("resetCode");
    code.addEventListener("input", () => { code.value = code.value.replace(/\D/g, "").slice(0, 6); });
    // Enter inside this panel must not run login().
    step.addEventListener("keydown", (e) => {
      if (e.key !== "Enter") return;
      e.preventDefault(); e.stopPropagation();
      if (e.target.id === "resetEmail") sendResetCode(false);
      else saveNewPassword();
    });
    $("resetSend").addEventListener("click", () => sendResetCode(false));
    $("resetResend").addEventListener("click", () => sendResetCode(true));
    $("resetSave").addEventListener("click", saveNewPassword);
    $("resetBack").addEventListener("click", () => closeReset(""));
  }

  function resetTick() {
    const now = Date.now();
    const left = Math.ceil((resetEndsAt - now) / 1000);
    const wait = Math.ceil((resetResendAt - now) / 1000);
    const t = $("resetCodeTimer");
    if (t) { t.textContent = left > 0 ? `Code expires in ${clock(left)}` : "Code expired: send a new one"; t.className = left > 0 ? "" : "otp-late"; }
    const r = $("resetResend");
    if (r) { r.disabled = wait > 0; r.textContent = wait > 0 ? `Send a new code (${wait}s)` : "Send a new code"; }
  }

  function openReset() {
    addStyle();
    buildResetStep();
    if (state) backToPassword("");
    $("loginCredentials").hidden = true;
    $("resetStep").hidden = false;
    $("resetAsk").hidden = false;
    $("resetEnter").hidden = true;
    const title = document.querySelector("#loginPanel h3");
    if (title) { title.dataset.originalText = title.dataset.originalText || title.textContent; title.textContent = "Reset your password"; }
    $("resetEmail").value = ($("email") && $("email").value.trim()) || "";
    loginMessage("", "");
    setTimeout(() => $("resetEmail").focus(), 50);
  }

  function closeReset(message, type) {
    clearInterval(resetTimer);
    resetTimer = null;
    if ($("resetStep")) $("resetStep").hidden = true;
    if ($("loginCredentials")) $("loginCredentials").hidden = false;
    const title = document.querySelector("#loginPanel h3");
    if (title && title.dataset.originalText) title.textContent = title.dataset.originalText;
    ["resetCode", "resetNew", "resetConfirm"].forEach((id) => { if ($(id)) $(id).value = ""; });
    loginMessage(message || "", type || "error");
    if ($("password")) $("password").focus();
  }

  let resetBusy = false;
  async function sendResetCode(again) {
    if (resetBusy) return;
    const email = $("resetEmail").value.trim();
    if (!/^\S+@\S+\.\S+$/.test(email)) { loginMessage("Enter the email address of your HIMS account."); $("resetEmail").focus(); return; }
    resetBusy = true;
    const btn = again ? $("resetResend") : $("resetSend");
    btn.disabled = true;
    try {
      const r = await post("forgot-start", { email });
      if (!r.ok) {
        loginMessage(r.data.error || "Could not send a code.");
        if (r.data.resendIn) { resetResendAt = Date.now() + r.data.resendIn * 1000; }
        return;
      }
      resetEndsAt = Date.now() + (Number(r.data.expiresIn) || 600) * 1000;
      resetResendAt = Date.now() + (Number(r.data.resendIn) || 60) * 1000;
      $("resetAsk").hidden = true;
      $("resetEnter").hidden = false;
      $("resetInfo").innerHTML = `Enter the 6-digit code sent for <strong>${esc(email)}</strong>, then choose a new password.`;
      loginMessage(r.data.message || "Code sent.", "success");
      clearInterval(resetTimer);
      resetTimer = setInterval(resetTick, 1000);
      resetTick();
      $("resetCode").value = "";
      $("resetCode").focus();
    } catch (e) {
      loginMessage("Could not reach the server. Make sure HIMS is running.");
    } finally {
      resetBusy = false;
      if (!again) btn.disabled = false;
      resetTick();
    }
  }

  async function saveNewPassword() {
    if (resetBusy) return;
    const code = $("resetCode").value.replace(/\D/g, "");
    const pass = $("resetNew").value;
    if (code.length !== 6) { loginMessage("Enter all 6 digits of the code."); $("resetCode").focus(); return; }
    if (pass.length < 8 || !/[A-Za-z]/.test(pass) || !/\d/.test(pass)) { loginMessage("The new password needs at least 8 characters, with a letter and a number."); $("resetNew").focus(); return; }
    if (pass !== $("resetConfirm").value) { loginMessage("The two passwords do not match."); $("resetConfirm").focus(); return; }
    resetBusy = true;
    $("resetSave").disabled = true;
    $("resetSave").textContent = "Saving...";
    try {
      const r = await post("forgot-reset", { code, password: pass });
      if (r.ok) {
        const email = $("resetEmail").value.trim();
        closeReset(r.data.message || "Password changed. You can now log in.", "success");
        if ($("email")) $("email").value = email;
        return;
      }
      if (r.data.restart) {
        $("resetAsk").hidden = false;
        $("resetEnter").hidden = true;
        clearInterval(resetTimer);
      }
      loginMessage(r.data.error || "The password could not be changed.");
    } catch (e) {
      loginMessage("Could not reach the server. Make sure HIMS is running.");
    } finally {
      resetBusy = false;
      if ($("resetSave")) { $("resetSave").disabled = false; $("resetSave").textContent = "Change password"; }
    }
  }

  // The login page's "Forgot Password" opens the code step. Signed in, the profile's
  // "Change password" keeps working as before (current password + new password).
  function hookForgotPassword() {
    const original = window.forgotPassword;
    if (page !== "main" || typeof original !== "function" || original.__reset) return;
    const wrapped = function () {
      if (signedIn()) return original.apply(this, arguments);
      openReset();
    };
    wrapped.__reset = true;
    window.forgotPassword = wrapped;
  }

  window.HimsSecurity = { showCodeStep, touch, idleLimitMinutes: IDLE_LIMIT_MS / 60000, openReset };

  function init() {
    addStyle();
    watchIdle();
    showTimeoutNotice();
    showLoginToast();
    addTotpButton();
    hookForgotPassword();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
