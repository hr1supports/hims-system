/*
 * HIMS File Exchange (integration with HR2, HR3 and HR4)
 * ---------------------------------------------------------------------------
 * - Inbox: files the HR2 / HR3 / HR4 systems sent to HIMS.
 * - Sent: files HIMS sent to them, and whether their system has picked them up.
 * - Send a file: pick one or more systems, add a subject and note, attach the file.
 * - Connections: which systems are connected. The Super Admin creates or removes each
 *   system's connection key here, and the page shows the partner teams how to connect.
 * Used by HR Staff, HR Officer, Admin and Super Admin.
 * Data: backend/file-exchange.php (?resource=file-exchange).
 */
(function () {
  "use strict";

  const PARTNERS = ["HR2", "HR3", "HR4", "ADMIN"];
  const LABEL = { HR2: "HR2", HR3: "HR3", HR4: "HR4", ADMIN: "Administrators" };
  const label = (p) => LABEL[p] || p;
  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v == null ? "" : v).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const toastOk = (m) => (window.showSuccessToast || console.log)(m);
  const toastErr = (e) => (window.showWarningToast || console.warn)(e && e.message ? e.message : String(e));
  const role = () => { try { return (JSON.parse(localStorage.getItem("currentUser") || "null") || {}).role || ""; } catch (e) { return ""; } };
  const allowed = () => ["HR1", "HR3", "ADMIN", "SUPER_ADMIN", "HR_OFFICER", "HR_STAFF"].includes(role());
  const base = () => (typeof apiUrl !== "undefined" ? apiUrl : "backend/api.php");
  const creds = () => (typeof apiUsesCredentials !== "undefined" && apiUsesCredentials ? "include" : "same-origin");
  const get = (query) => window.apiRequest("file-exchange", { query });
  const post = (body) => window.apiRequest("file-exchange", { method: "POST", headers: { "X-HR3-Request": "1" }, body: JSON.stringify(body) });

  const state = { tab: "inbox", partner: "", q: "", archived: false, unread: { HR2: 0, HR3: 0, HR4: 0, ADMIN: 0 }, total: 0 };

  function size(n) {
    n = Number(n || 0);
    if (n < 1024) return `${n} B`;
    if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / 1048576).toFixed(1)} MB`;
  }

  function addStyle() {
    if ($("himsFileExchangeStyle")) return;
    const s = document.createElement("style");
    s.id = "himsFileExchangeStyle";
    s.textContent = `
      .fx-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 14px 0 16px; }
      .fx-tabs button { border-radius: 999px; padding: 8px 14px; }
      #filex .fx-tabs button:not(.is-active), #filex .fx-chip:not(.is-active) { background: var(--panel-soft); color: var(--text); border: 1px solid var(--line); box-shadow: none; }
      #filex .fx-chip { width: auto; min-width: 0; padding: 6px 14px; }
      .fx-count { display: inline-block; min-width: 20px; padding: 1px 7px; margin-left: 6px; border-radius: 999px; background: var(--danger); color: #fff; font-size: .74rem; font-weight: 700; line-height: 1.5; }
      .fx-toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
      .fx-toolbar input[type=search] { flex: 1 1 220px; max-width: 320px; }
      .fx-chip { border-radius: 999px; }
      .fx-check { display: inline-flex; align-items: center; gap: 6px; font-size: .88rem; color: var(--muted); white-space: nowrap; }
      .fx-check input { width: auto; margin: 0; }
      .fx-file strong { display: block; word-break: break-all; }
      .request-table td small { display: block; opacity: .8; }
      .fx-status { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: .78rem; font-weight: 700; background: var(--panel-soft); border: 1px solid var(--line); }
      .fx-status.is-new { background: rgba(239,68,68,.12); color: var(--danger); border-color: transparent; }
      .fx-status.is-waiting { background: rgba(211,158,0,.14); color: #a37800; border-color: transparent; }
      .fx-status.is-done { background: var(--green-soft); color: var(--green-dark); border-color: transparent; }
      tr.fx-unread td { font-weight: 600; }
      .fx-actions { white-space: nowrap; }
      .fx-actions button { margin: 2px; padding: 6px 10px; }
      .fx-form { display: grid; gap: 14px; max-width: 640px; }
      .fx-form label { display: grid; gap: 6px; font-weight: 600; }
      .fx-form textarea { min-height: 90px; resize: vertical; }
      .fx-targets { display: flex; gap: 10px; flex-wrap: wrap; }
      .fx-targets label { display: flex; align-items: center; gap: 8px; font-weight: 600; padding: 10px 14px; border: 1px solid var(--line); border-radius: 12px; cursor: pointer; background: var(--panel-soft); }
      .fx-targets label small { font-weight: 400; color: var(--muted); }
      .fx-drop { border: 2px dashed var(--line); border-radius: 14px; padding: 22px; text-align: center; cursor: pointer; background: var(--panel-soft); color: var(--muted); }
      .fx-drop.is-over { border-color: var(--green); background: var(--green-soft); }
      .fx-drop strong { color: var(--text); }
      .fx-progress { height: 8px; border-radius: 999px; background: var(--line); overflow: hidden; }
      .fx-progress > div { height: 100%; width: 0; background: var(--green); transition: width .15s; }
      .fx-hint { font-size: .84rem; color: var(--muted); margin: 0; }
      .fx-conn-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 20px; }
      .fx-conn { border: 1px solid var(--line); border-radius: 14px; padding: 16px; background: var(--panel-soft); }
      .fx-conn h4 { margin: 0 0 6px; display: flex; justify-content: space-between; align-items: center; }
      .fx-conn p { margin: 4px 0; font-size: .86rem; color: var(--muted); }
      .fx-conn .fx-actions { margin-top: 10px; white-space: normal; display: flex; flex-wrap: wrap; gap: 6px; }
      .fx-conn .fx-actions button { margin: 0; }
      .fx-key { margin-top: 10px; padding: 10px; border-radius: 10px; background: var(--panel); border: 1px solid var(--green); font-family: ui-monospace, Consolas, monospace; font-size: .8rem; word-break: break-all; }
      .fx-guide pre { white-space: pre-wrap; word-break: break-all; background: var(--panel-soft); border: 1px solid var(--line); border-radius: 10px; padding: 12px; font-size: .8rem; }
      .fx-guide h4 { margin: 18px 0 6px; }
      .fx-push .fx-conn form { display: grid; gap: 8px; margin-top: 10px; }
      .fx-push .fx-conn label { display: grid; gap: 4px; font-size: .84rem; font-weight: 600; }
      .fx-push .fx-conn label.fx-check { display: inline-flex; }
      .fx-events { display: flex; flex-wrap: wrap; gap: 4px; margin: 6px 0; }
      .fx-events code { font-size: .74rem; padding: 2px 7px; border-radius: 999px; background: var(--panel); border: 1px solid var(--line); }
      .fx-push .fx-status.is-failed { background: rgba(239,68,68,.12); color: var(--danger); border-color: transparent; }
      .fx-err { max-width: 320px; word-break: break-word; }
    `;
    document.head.appendChild(s);
  }

  // ---------------------------------------------------------------------
  // Page shell
  // ---------------------------------------------------------------------
  function render() {
    const root = $("fxRoot");
    if (!root) return;
    const badge = state.total ? `<span class="fx-count">${state.total}</span>` : "";
    root.innerHTML = `
      <div class="fx-tabs" role="tablist">
        <button type="button" data-fx-tab="inbox" class="${state.tab === "inbox" ? "is-active" : ""}">Inbox${badge}</button>
        <button type="button" data-fx-tab="sent" class="${state.tab === "sent" ? "is-active" : ""}">Sent</button>
        <button type="button" data-fx-tab="send" class="${state.tab === "send" ? "is-active" : ""}">Send a file</button>
        <button type="button" data-fx-tab="push" class="${state.tab === "push" ? "is-active" : ""}">Data HIMS sends</button>
        <button type="button" data-fx-tab="connections" class="${state.tab === "connections" ? "is-active" : ""}">Connections</button>
      </div>
      <div id="fxBody"></div>`;
    if (state.tab === "inbox" || state.tab === "sent") renderList();
    if (state.tab === "send") renderSend();
    if (state.tab === "connections") renderConnections();
    if (state.tab === "push") renderPush();
  }

  function toolbar() {
    const chip = (p, label) => {
      const n = p && state.tab === "inbox" ? state.unread[p] : 0;
      return `<button type="button" class="fx-chip ${state.partner === p ? "is-active" : ""}" data-fx-partner="${p}">${label}${n ? `<span class="fx-count">${n}</span>` : ""}</button>`;
    };
    return `<div class="fx-toolbar">
      ${chip("", "All systems")}${PARTNERS.map((p) => chip(p, label(p))).join("")}
      <input type="search" id="fxSearch" placeholder="Search subject, file or sender" value="${esc(state.q)}">
      <label class="fx-check"><input type="checkbox" id="fxArchived" ${state.archived ? "checked" : ""}> Show archived</label>
    </div>`;
  }

  async function renderList() {
    const body = $("fxBody");
    const inbox = state.tab === "inbox";
    body.innerHTML = `${toolbar()}<div id="fxTable"><p class="table-empty">Loading...</p></div>`;
    try {
      const query = { view: "list", box: inbox ? "incoming" : "outgoing", archived: state.archived ? "1" : "0" };
      if (state.partner) query.partner = state.partner;
      if (state.q) query.q = state.q;
      const rows = (await get(query)).records || [];
      if (!$("fxTable")) return;
      if (!rows.length) {
        $("fxTable").innerHTML = `<p class="table-empty">${inbox
          ? (state.archived ? "No archived files." : "No files received yet. Files sent by HR2, HR3 or HR4 will show here.")
          : (state.archived ? "No archived files." : "You have not sent any files yet. Use \"Send a file\".")}</p>`;
        return;
      }
      const status = (r) => {
        if (r.status === "New") return '<span class="fx-status is-new">New</span>';
        if (r.status === "Opened") return `<span class="fx-status is-done">Opened</span>${r.openedBy ? `<small>by ${esc(r.openedBy)}</small>` : ""}`;
        if (r.status === "Waiting") return '<span class="fx-status is-waiting">Waiting for pickup</span>';
        return `<span class="fx-status is-done">Picked up</span>${r.pickedUpAt ? `<small>${esc(r.pickedUpAt)}</small>` : ""}`;
      };
      const actions = (r) => `<td class="fx-actions">
        <button type="button" data-fx-download="${Number(r.id)}" data-fx-name="${esc(r.fileName)}">Download</button>
        ${inbox && r.status !== "New" && !Number(r.archived) ? `<button type="button" class="secondary-action" data-fx-act="mark-unread" data-fx-id="${Number(r.id)}">Mark new</button>` : ""}
        <button type="button" class="secondary-action" data-fx-act="${Number(r.archived) ? "restore" : "archive"}" data-fx-id="${Number(r.id)}">${Number(r.archived) ? "Restore" : "Archive"}</button>
      </td>`;
      const head = inbox
        ? "<th>From</th><th>Subject</th><th>File</th><th>Received</th><th>Status</th><th></th>"
        : "<th>To</th><th>Subject</th><th>File</th><th>Sent</th><th>Status</th><th></th>";
      $("fxTable").innerHTML = `<div class="request-table-wrap"><table class="request-table"><thead><tr>${head}</tr></thead><tbody>
        ${rows.map((r) => `<tr class="${inbox && r.status === "New" ? "fx-unread" : ""}">
          <td><strong>${esc(r.partner)}</strong>${inbox && r.partnerSender ? `<small>${esc(r.partnerSender)}</small>` : ""}</td>
          <td>${esc(r.subject)}${r.note ? `<small>${esc(r.note)}</small>` : ""}${r.partnerRef ? `<small>Ref: ${esc(r.partnerRef)}</small>` : ""}</td>
          <td class="fx-file"><strong>${esc(r.fileName)}</strong><small>${size(r.size)}</small></td>
          <td>${esc(r.created)}${!inbox && r.sentBy ? `<small>by ${esc(r.sentBy)}</small>` : ""}</td>
          <td>${status(r)}</td>
          ${actions(r)}
        </tr>`).join("")}
      </tbody></table></div>`;
    } catch (e) {
      if ($("fxTable")) $("fxTable").innerHTML = `<p class="table-empty">${esc(e.message)}</p>`;
    }
  }

  // ---------------------------------------------------------------------
  // Send a file
  // ---------------------------------------------------------------------
  let chosenFile = null;
  function renderSend() {
    chosenFile = null;
    const pre = state.partner;
    $("fxBody").innerHTML = `<form class="fx-form" id="fxSendForm" novalidate>
      <div><strong>Send to</strong>
        <div class="fx-targets">${PARTNERS.map((p) => `<label><input type="checkbox" name="partners[]" value="${p}" ${pre === p ? "checked" : ""}> ${label(p)}</label>`).join("")}</div>
        <p class="fx-hint">Pick one or more. Each system gets its own copy.</p>
      </div>
      <label>Subject <input name="subject" maxlength="150" required placeholder="e.g. October payroll inputs"></label>
      <label>Note <span class="fx-hint">(optional)</span><textarea name="note" maxlength="2000" placeholder="Anything the receiving team should know"></textarea></label>
      <div>
        <div class="fx-drop" id="fxDrop" tabindex="0" role="button" aria-label="Choose a file">
          <div id="fxDropText"><strong>Click to choose a file</strong> or drag it here</div>
          <p class="fx-hint">PDF, Word, Excel, PowerPoint, CSV, TXT, JPG, PNG or ZIP · up to 10 MB</p>
        </div>
        <input type="file" id="fxFile" hidden accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.jpg,.jpeg,.png,.zip">
      </div>
      <div class="fx-progress" id="fxProgress" hidden><div></div></div>
      <div><button type="submit" id="fxSendBtn">Send file</button></div>
    </form>`;
    const drop = $("fxDrop");
    const input = $("fxFile");
    const pick = (file) => {
      if (!file) return;
      if (file.size > 10 * 1024 * 1024) { toastErr("The file is larger than 10 MB."); return; }
      chosenFile = file;
      $("fxDropText").innerHTML = `<strong>${esc(file.name)}</strong> · ${size(file.size)} <span class="fx-hint">(click to change)</span>`;
    };
    drop.addEventListener("click", () => input.click());
    drop.addEventListener("keydown", (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); input.click(); } });
    input.addEventListener("change", () => pick(input.files[0]));
    ["dragenter", "dragover"].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add("is-over"); }));
    ["dragleave", "drop"].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove("is-over"); }));
    drop.addEventListener("drop", (e) => pick(e.dataTransfer.files[0]));
    $("fxSendForm").addEventListener("submit", sendFile);
  }

  function sendFile(event) {
    event.preventDefault();
    const form = event.target;
    const data = new FormData(form);
    if (!data.getAll("partners[]").length) { toastErr("Choose at least one system: HR2, HR3 or HR4."); return; }
    if (!String(data.get("subject") || "").trim()) { toastErr("Enter a subject."); return; }
    if (!chosenFile) { toastErr("Choose a file to send."); return; }
    data.append("file", chosenFile);
    const bar = $("fxProgress");
    const btn = $("fxSendBtn");
    bar.hidden = false;
    btn.disabled = true;
    // XMLHttpRequest instead of fetch so the progress bar can move while the file uploads.
    const xhr = new XMLHttpRequest();
    xhr.open("POST", `${base()}?resource=file-exchange`);
    xhr.withCredentials = creds() === "include";
    xhr.setRequestHeader("X-HR3-Request", "1");
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) bar.firstElementChild.style.width = `${Math.round((e.loaded / e.total) * 100)}%`; };
    xhr.onload = () => {
      let result = null;
      try { result = JSON.parse(xhr.responseText); } catch (e) { result = null; }
      btn.disabled = false;
      bar.hidden = true;
      if (xhr.status >= 200 && xhr.status < 300) {
        toastOk((result && result.message) || "File sent.");
        state.tab = "sent";
        render();
      } else {
        toastErr((result && result.error) || `Upload failed (HTTP ${xhr.status}).`);
      }
    };
    xhr.onerror = () => { btn.disabled = false; bar.hidden = true; toastErr("Could not reach the server."); };
    xhr.send(data);
  }

  // ---------------------------------------------------------------------
  // Connections (+ instructions for the partner teams)
  // ---------------------------------------------------------------------
  async function renderConnections(newKey) {
    const body = $("fxBody");
    body.innerHTML = '<p class="table-empty">Loading...</p>';
    try {
      const result = await get({ view: "connections" });
      if (!$("fxBody")) return;
      const manage = !!result.canManage;
      const endpoint = new URL(base(), location.href);
      endpoint.search = "?resource=integration-files";
      const url = endpoint.toString();
      endpoint.search = "?resource=integration-requests";
      const reqUrl = endpoint.toString();
      endpoint.search = "?resource=hire-order";
      const hireUrl = endpoint.toString();
      body.innerHTML = `
        <div class="fx-conn-grid">${(result.records || []).map((c) => `
          <div class="fx-conn">
            <h4>${esc(label(c.partner))} <span class="fx-status ${c.connected ? "is-done" : "is-waiting"}">${c.connected ? "Connected" : "Not connected"}</span></h4>
            ${c.connected
              ? `<p>${esc(c.source)}${c.hint ? ` · key ending ${esc(c.hint)}` : ""}</p>${c.createdAt ? `<p>Created ${esc(c.createdAt)}</p>` : ""}<p>${c.lastUsedAt ? `Last used ${esc(c.lastUsedAt)}` : "Not used yet"}</p>`
              : `<p>${manage ? "Create a key and give it to the " + esc(c.partner) + " team." : "Ask the Super Admin to create a key for " + esc(c.partner) + "."}</p>`}
            ${manage ? `<div class="fx-actions">
              <button type="button" data-fx-key="generate" data-fx-p="${esc(c.partner)}">${c.source === "HIMS key" ? "Replace key" : "Create key"}</button>
              ${c.source === "HIMS key" ? `<button type="button" class="danger-action" data-fx-key="revoke" data-fx-p="${esc(c.partner)}">Remove key</button>` : ""}
            </div>` : ""}
            ${newKey && newKey.partner === c.partner ? `<div class="fx-key" id="fxNewKey">${esc(newKey.key)}</div>
              <div class="fx-actions"><button type="button" data-fx-copy="1">Copy key</button></div>
              <p>Copy it now and send it privately to the ${esc(c.partner)} team. It will not be shown again.</p>` : ""}
          </div>`).join("")}
        </div>
        <section class="fx-guide">
          <h4>How HR2, HR3, HR4 and the Administrators connect (give this to their developers)</h4>
          <p class="fx-hint">One key per system works for files, requests and (for HR3) hire orders.</p>
          <p class="fx-hint"><strong>Laravel teams:</strong> use the REST API at <code>${esc(new URL("api/v1", location.origin + "/").toString())}</code> with <code>Authorization: Bearer YOUR_KEY</code>
            (GET /ping, GET|POST /files, GET /files/{id}, GET|POST /requests, GET /requests/{reference}, POST /hire-orders). The examples below use the original addresses, which still work.</p>
          <p class="fx-hint">Every request sends the system's key in the <code>X-Integration-Key</code> header. Files up to 10 MB: PDF, Word, Excel, PowerPoint, CSV, TXT, JPG, PNG or ZIP.</p>
          <h4>1. Send a file to HIMS</h4>
          <pre>curl -X POST "${esc(url)}" \\
  -H "X-Integration-Key: YOUR_KEY" \\
  -F "subject=October payroll report" \\
  -F "note=Optional message" \\
  -F "sender=Juan Dela Cruz (HR4)" \\
  -F "reference=PR-2026-10" \\
  -F "file=@report.pdf"</pre>
          <h4>2. See the files HIMS sent you</h4>
          <pre>curl "${esc(url)}" -H "X-Integration-Key: YOUR_KEY"
# add &amp;status=all to include files you already picked up</pre>
          <h4>3. Download one (HIMS marks it "Picked up")</h4>
          <pre>curl "${esc(url)}&amp;id=FILE_ID" -H "X-Integration-Key: YOUR_KEY" -o file.pdf</pre>
          <h4>4. Send a request (shows on HIMS → HR Requests)</h4>
          <pre>curl -X POST "${esc(reqUrl)}" \
  -H "X-Integration-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"reference":"OT-0012","type":"Overtime","title":"Overtime for Oct 30",
       "employee_code":"EMP-001","employee_name":"Juan Dela Cruz",
       "hours":3,"date":"2026-10-30","details":"ER coverage",
       "requested_by":"HR4 Payroll"}'</pre>
          <p class="fx-hint">reference, type and title are required. Use your own unique reference number for every request.</p>
          <h4>5. Check the decision (Pending, Approved or Rejected, with the HR Officer's remark)</h4>
          <pre>curl "${esc(reqUrl)}&amp;reference=OT-0012" -H "X-Integration-Key: YOUR_KEY"
# or every request you sent: curl "${esc(reqUrl)}" -H "X-Integration-Key: YOUR_KEY"</pre>
          <h4>6. HR3 only: send a hire order (shows on HIMS → Vacancies)</h4>
          <pre>curl -X POST "${esc(hireUrl)}" \
  -H "X-Integration-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"reference":"HO-2026-031","title":"Staff Nurse","department":"Emergency Room",
       "type":"Full-time","slots":2,"deadline":"2026-12-15","requested_by":"HR3 Planning"}'</pre>
        </section>`;
    } catch (e) {
      if ($("fxBody")) body.innerHTML = `<p class="table-empty">${esc(e.message)}</p>`;
    }
  }


  // ---------------------------------------------------------------------
  // Data HIMS sends to the other systems (push)
  // ---------------------------------------------------------------------
  const WHAT = {
    HR2: "Employee information after onboarding, and new employee accounts.",
    HR3: "Trainee accounts, and applicant information after onboarding.",
    HR4: "Decided claims and reimbursements, and monthly payroll inputs.",
    ADMIN: "Hired applicants.",
  };
  const thisMonth = () => new Date().toISOString().slice(0, 7);
  async function renderPush() {
    const body = $("fxBody");
    body.innerHTML = '<p class="table-empty">Loading...</p>';
    try {
      const r = await get({ view: "push" });
      if (!$("fxBody") || state.tab !== "push") return;
      const manage = !!r.canManage, send = !!r.canSend;
      const pill = (st) => `<span class="fx-status ${st === "sent" ? "is-done" : st === "failed" ? "is-failed" : "is-waiting"}">${st === "sent" ? "Sent" : st === "failed" ? "Failed" : "Waiting"}</span>`;
      body.innerHTML = `<div class="fx-push">
        <p class="fx-hint">HIMS sends these records to each system automatically when they happen. Each system gives you the address of their API (and a key, if they use one). If a system is offline, HIMS keeps the record and sends it later.</p>
        <div class="fx-conn-grid">${(r.partners || []).map((c) => `
          <div class="fx-conn">
            <h4>${esc(c.label)} <span class="fx-status ${c.url && c.enabled ? "is-done" : "is-waiting"}">${c.url ? (c.enabled ? "Sending" : "Paused") : "No address yet"}</span></h4>
            <p>${esc(WHAT[c.partner] || "")}</p>
            <div class="fx-events">${c.events.map((e) => `<code>${esc(e)}</code>`).join("")}</div>
            <p>${c.counts.sent} sent · ${c.counts.pending} waiting · ${c.counts.failed} failed</p>
            ${manage ? `<form data-fx-push-form="${esc(c.partner)}">
              <label>Their API address<input type="url" name="url" maxlength="500" placeholder="https://their-system/api/hims" value="${esc(c.url)}"></label>
              <label>Their key ${c.hasToken ? "(saved; leave empty to keep it)" : "(if they gave one)"}<input type="password" name="token" maxlength="1000" autocomplete="new-password" placeholder="${c.hasToken ? "••••••••" : "Optional"}"></label>
              ${c.hasToken ? `<label class="fx-check"><input type="checkbox" name="clearToken"> Remove the saved key</label>` : ""}
              <label class="fx-check"><input type="checkbox" name="enabled" ${c.enabled || !c.url ? "checked" : ""}> Send automatically</label>
              <div class="fx-actions"><button type="submit">Save</button></div>
            </form>` : `<p>${c.url ? "Address set by the Super Admin." : "The Super Admin sets the address."}</p>`}
            ${send ? `<div class="fx-actions">
              <button type="button" data-fx-push="push-test" data-fx-p="${esc(c.partner)}" ${c.url ? "" : "disabled"}>Send a test</button>
              <button type="button" data-fx-push="push-backfill" data-fx-p="${esc(c.partner)}" ${c.url ? "" : "disabled"}>Send all existing records</button>
              ${c.counts.pending + c.counts.failed ? `<button type="button" data-fx-push="push-retry" data-fx-p="${esc(c.partner)}">Retry waiting (${c.counts.pending + c.counts.failed})</button>` : ""}
            </div>` : ""}
            ${send && c.partner === "HR4" ? `<form class="fx-actions" data-fx-payroll="1">
              <input type="month" name="month" value="${thisMonth()}" max="${thisMonth()}" aria-label="Payroll month" style="width:auto">
              <button type="submit">Send payroll inputs</button>
            </form><p>Salary, days present, hours, approved overtime, leave and absences for every active employee. HR4 computes the pay.</p>` : ""}
          </div>`).join("")}
        </div>
        <h4>Delivery log</h4>
        ${(r.log || []).length ? `<div class="table-wrap"><table class="request-table"><thead><tr><th>#</th><th>System</th><th>What</th><th>Status</th><th>When</th><th>Result</th></tr></thead><tbody>
          ${r.log.map((l) => `<tr><td>${l.id}</td><td>${esc(label(l.partner))}</td><td>${esc(l.event)}<small>${esc(l.ref || "")}</small></td>
            <td>${pill(l.status)}<small>${l.attempts} attempt(s)</small></td>
            <td>${esc(String(l.sent_at || l.created_at || "").replace("T", " ").slice(0, 16))}</td>
            <td class="fx-err">${l.status === "sent" ? `HTTP ${esc(l.response_code)}` : esc(l.last_error || "")}</td></tr>`).join("")}
        </tbody></table></div>` : '<p class="table-empty">Nothing sent yet.</p>'}
        <p class="fx-hint">Give the partner teams the "Data HIMS sends" section of HIMS-LARAVEL-README.md: it lists every field HIMS sends.</p>
      </div>`;
    } catch (e) {
      if ($("fxBody")) body.innerHTML = `<p class="table-empty">${esc(e.message)}</p>`;
    }
  }
  async function pushAction(body, button) {
    if (button) { button.disabled = true; button.dataset.text = button.textContent; button.textContent = "Sending..."; }
    try {
      const result = await post(body);
      toastOk(result.message);
    } catch (e) { toastErr(e); }
    if (state.tab === "push") renderPush();
  }
  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!form.closest || !form.closest("#filex")) return;
    if (form.dataset.fxPushForm) {
      event.preventDefault();
      const f = new FormData(form);
      const body = { action: "push-save", partner: form.dataset.fxPushForm, url: String(f.get("url") || "").trim(), enabled: !!f.get("enabled") };
      const token = String(f.get("token") || "");
      if (f.get("clearToken")) body.token = "";
      else if (token) body.token = token;
      pushAction(body, form.querySelector("button[type=submit]"));
    }
    if (form.dataset.fxPayroll) {
      event.preventDefault();
      const month = String(new FormData(form).get("month") || "");
      if (!month) return toastErr("Choose a month.");
      pushAction({ action: "push-payroll", month }, form.querySelector("button[type=submit]"));
    }
  });

  // ---------------------------------------------------------------------
  // Download (fetch so a server error shows as a message, not a broken file)
  // ---------------------------------------------------------------------
  async function download(id, name) {
    try {
      const response = await fetch(`${base()}?resource=file-exchange&download=${encodeURIComponent(id)}`, { credentials: creds() });
      const type = response.headers.get("Content-Type") || "";
      if (!response.ok || type.includes("application/json")) {
        let message = `Download failed (HTTP ${response.status}).`;
        try { message = (await response.json()).error || message; } catch (e) { /* keep default */ }
        throw new Error(message);
      }
      const blob = await response.blob();
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.download = name || "file";
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(link.href), 4000);
      if (state.tab === "inbox") { refreshCounts(true); renderList(); }
    } catch (e) {
      toastErr(e);
    }
  }

  // ---------------------------------------------------------------------
  // Unread badge on the sidebar (refreshed when the user moves between pages, never by a timer,
  // so the 5-minute idle sign-out still works)
  // ---------------------------------------------------------------------
  let lastCount = 0;
  async function refreshCounts(force) {
    if (!allowed()) return;
    if (!force && Date.now() - lastCount < 60000) return;
    lastCount = Date.now();
    try {
      const result = await get({ view: "counts" });
      state.unread = result.unread || state.unread;
      state.total = Number(result.total || 0);
    } catch (e) {
      return;
    }
    document.querySelectorAll('.nav-item[data-module="filex"]').forEach((b) => {
      let tag = b.querySelector(".fx-count");
      if (!state.total) { if (tag) tag.remove(); return; }
      if (!tag) { tag = document.createElement("span"); tag.className = "fx-count"; b.appendChild(tag); }
      tag.textContent = state.total;
    });
  }

  function open() {
    if (!allowed()) return;
    addStyle();
    refreshCounts(true).then(render);
    render();
  }

  // ---------------------------------------------------------------------
  // Events
  // ---------------------------------------------------------------------
  let searchTimer = null;
  document.addEventListener("click", async (event) => {
    if (!event.target.closest || !event.target.closest("#filex")) return;
    const t = event.target.closest("[data-fx-tab],[data-fx-partner],[data-fx-download],[data-fx-act],[data-fx-key],[data-fx-copy],[data-fx-push]");
    if (!t) return;
    if (t.dataset.fxTab) { state.tab = t.dataset.fxTab; render(); return; }
    if (t.dataset.fxPartner !== undefined) { state.partner = t.dataset.fxPartner; renderList(); return; }
    if (t.dataset.fxPush) {
      const partner = t.dataset.fxP, act = t.dataset.fxPush;
      if (act === "push-backfill" && !window.confirm(`Send every existing record to ${label(partner)} now? Use this once when the connection is new.`)) return;
      pushAction({ action: act, partner, month: thisMonth() }, t);
      return;
    }
    if (t.dataset.fxDownload) { download(t.dataset.fxDownload, t.dataset.fxName); return; }
    if (t.dataset.fxCopy) {
      const key = ($("fxNewKey") || {}).textContent || "";
      try { await navigator.clipboard.writeText(key); toastOk("Key copied."); } catch (e) { toastErr("Select the key and copy it manually."); }
      return;
    }
    if (t.dataset.fxAct) {
      try {
        const result = await post({ action: t.dataset.fxAct, id: Number(t.dataset.fxId) });
        toastOk(result.message);
        refreshCounts(true);
        renderList();
      } catch (e) { toastErr(e); }
      return;
    }
    if (t.dataset.fxKey) {
      const partner = t.dataset.fxP;
      const generate = t.dataset.fxKey === "generate";
      const ask = generate
        ? `Create a new key for ${partner}? If ${partner} already has a key, the old one stops working.`
        : `Remove the ${partner} key? ${partner} will no longer be able to send or pick up files.`;
      if (!window.confirm(ask)) return;
      try {
        const result = await post({ action: generate ? "generate-key" : "revoke-key", partner });
        toastOk(result.message);
        renderConnections(generate ? { partner, key: result.key } : null);
      } catch (e) { toastErr(e); }
    }
  });
  document.addEventListener("input", (event) => {
    if (event.target.id !== "fxSearch") return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { state.q = event.target.value.trim(); renderList().then(() => { const s = $("fxSearch"); if (s) { s.focus(); s.setSelectionRange(s.value.length, s.value.length); } }); }, 350);
  });
  document.addEventListener("change", (event) => {
    if (event.target.id === "fxArchived") { state.archived = event.target.checked; renderList(); }
  });

  // Hook into page switching (runs after the page's own scripts wrap showModule).
  function hook() {
    const previous = window.showModule;
    if (typeof previous !== "function" || previous.__fx) return;
    const show = function (id) {
      const result = previous.apply(this, arguments);
      if (id === "filex") open();
      else refreshCounts(false);
      return result;
    };
    show.__fx = true;
    window.showModule = show;
  }
  window.loadFileExchange = open;
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => setTimeout(hook, 0));
  else setTimeout(hook, 0);
})();
