// Admin modules: Vacancies, Applicants, Interview, Audit Log
// Uses the same read()/write()/addNotification()/escapeHtml() helpers as script.js (localStorage).
(function () {
  const $ = (id) => document.getElementById(id);
  const esc = (v) => escapeHtml(v == null ? "" : v);
  const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

  function currentActor() {
    try {
      const u = JSON.parse(localStorage.getItem("currentUser") || "null");
      return (u && (u.name || u.username || u.email)) || "Administrator";
    } catch (e) { return "Administrator"; }
  }

  // ---------- Audit log ----------
  function logAudit(module, action) {
    const logs = read("auditLog");
    logs.unshift({ id: uid(), time: new Date().toLocaleString(), user: currentActor(), module, action });
    write("auditLog", logs.slice(0, 500));
  }

  // Every existing notification (attendance, leave, claims...) is also recorded in the audit log.
  const baseNotify = window.addNotification;
  window.addNotification = function (message) {
    baseNotify(message);
    logAudit("System", message);
    if ($("auditTable")) renderAudit();
  };

  function renderAudit() {
    const q = ($("auditSearch").value || "").toLowerCase();
    const rows = read("auditLog").filter((l) =>
      [l.time, l.user, l.module, l.action].join(" ").toLowerCase().includes(q));
    $("auditTable").innerHTML = rows.length
      ? rows.map((l) => `<tr><td>${esc(l.time)}</td><td>${esc(l.user)}</td><td>${esc(l.module)}</td><td>${esc(l.action)}</td></tr>`).join("")
      : '<tr><td colspan="4" class="table-empty">No audit entries found.</td></tr>';
  }

  window.exportAuditLog = function () {
    const rows = [["Time", "User", "Module", "Action"]].concat(
      read("auditLog").map((l) => [l.time, l.user, l.module, l.action]));
    const csv = rows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(",")).join("\n");
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
    a.download = "audit-log.csv";
    a.click();
    URL.revokeObjectURL(a.href);
  };

  // ---------- helpers ----------
  function need(fields) {
    for (const [id, label] of fields) {
      if (!$(id).value.trim()) { alert(label + " is required."); $(id).focus(); return false; }
    }
    return true;
  }
  const clear = (ids) => ids.forEach((id) => { $(id).value = ""; });
  const empty = (cols, msg) => `<tr><td colspan="${cols}" class="table-empty">${msg}</td></tr>`;
  const badge = (t) => `<span class="request-status">${esc(t)}</span>`;
  function statusSelect(list, current, fn, id) {
    return `<select onchange="${fn}('${id}', this.value)">` +
      list.map((s) => `<option${s === current ? " selected" : ""}>${s}</option>`).join("") + "</select>";
  }
  function note(module, msg) { logAudit(module, msg); baseNotify(msg); }
  const viewArchived = (id) => !!($(id) && $(id).checked);

  // ---------- Server data (MySQL through backend/api.php) ----------
  let VACS = [], APPS = [];
  // Recruitment source: an applicant is Internal when they apply from the employee portal
  // (their note is tagged "[Internal applicant") or their email belongs to an employee.
  let EMP_EMAILS = new Set();
  async function loadEmployeeEmails() {
    try {
      const rows = (await apiRequest("employees")).records || [];
      EMP_EMAILS = new Set(rows.map((e) => String(e.email || "").trim().toLowerCase()).filter(Boolean));
    } catch (e) { /* keep the previous list */ }
  }
  function appSource(r) {
    if (/\[Internal applicant/i.test(String(r.note || ""))) return "Internal";
    return EMP_EMAILS.has(String(r.email || "").trim().toLowerCase()) ? "Internal" : "External";
  }
  window.appSource = appSource;
  // Which site an external applicant came from (LinkedIn, JobStreet, Facebook, Careers page, Walk-in).
  function appChannel(r) { return appSource(r) === "Internal" ? "Internal" : (r.channel || (r.resumeName ? "Careers page" : "Walk-in")); }
  window.appChannel = appChannel;
  function renderChannelSummary(list) {
    const box = $("channelSummary"); if (!box) return;
    const order = ["LinkedIn", "JobStreet", "Facebook", "Careers page", "Walk-in", "Internal"];
    const count = {}; list.forEach((r) => { const c = appChannel(r); count[c] = (count[c] || 0) + 1; });
    const total = list.length || 1;
    box.innerHTML = `<strong>Where applicants come from</strong><div class="channel-bars">` + order.map((c) => {
      const n = count[c] || 0, pct = Math.round(n * 100 / total);
      return `<button type="button" class="channel-bar channel-${c.toLowerCase().replace(/[^a-z]/g, "")}" onclick="document.getElementById('appSourceFilter').value='${c}';renderApplicants()" title="Show only ${c}">
        <span>${c}</span><b>${n}</b><i style="width:${pct}%"></i></button>`;
    }).join("") + "</div>";
  }
  window.loadEmployeeEmails = loadEmployeeEmails;
  const fail = (e) => (window.showWarningToast || alert)(e && e.message ? e.message : "Something went wrong.");
  const send = (resource, body) => apiRequest(resource, { method: "POST", body: JSON.stringify(body) });
  async function loadVacancies() {
    try { VACS = (await apiRequest("vacancies")).records; } catch (e) { fail(e); }
    renderVacancies(); refreshSelects();
  }
  async function loadApplicants() {
    await loadEmployeeEmails();
    try { APPS = (await apiRequest("applications")).records; } catch (e) { fail(e); }
    renderApplicants(); refreshSelects();
  }
  // New applications from the careers page show up without a manual refresh.
  setInterval(() => { const p = $("applicants"); if (p && p.offsetParent !== null) loadApplicants(); }, 30000);

  // ---------- Vacancies ----------
  window.setVacancyStatus = async function (id, status) {
    const x = VACS.find((r) => String(r.id) === String(id)); if (!x) return;
    try { await send("vacancies", { action: "status", id, status }); } catch (e) { return fail(e); }
    const word = status === "Open" ? "published to the careers page" : status === "Closed" ? "closed" : "set to " + status;
    note("Vacancies", `Hire order "${x.title}" ${word}.`);
    loadVacancies();
  };
  window.archiveVacancy = async function (id) {
    const x = VACS.find((r) => String(r.id) === String(id)); if (!x) return;
    if (!confirm(`Archive "${x.title}"? It is taken off the careers page and hidden from this list. Its applications are kept. You can restore it from "Show archived".`)) return;
    try { await send("vacancies", { action: "archive", id }); } catch (e) { return fail(e); }
    note("Vacancies", `Hire order "${x.title}" archived.`);
    loadVacancies();
  };
  window.restoreVacancy = async function (id) {
    const x = VACS.find((r) => String(r.id) === String(id)); if (!x) return;
    try { await send("vacancies", { action: "restore", id }); } catch (e) { return fail(e); }
    note("Vacancies", `Hire order "${x.title}" restored from the archive.`);
    loadVacancies();
  };
  window.viewVacancyApplications = function (id) {
    const v = VACS.find((r) => String(r.id) === String(id)); if (!v) return;
    showModule("applicants");
    $("appSearch").value = v.title;
    renderApplicants();
  };
  function vacancyActions(r) {
    const b = (label, status) => `<button type="button" onclick="setVacancyStatus('${r.id}', '${status}')">${label}</button>`;
    const main = r.status === "Pending" ? b("Publish", "Open") + b("Decline", "Closed")
      : r.status === "Open" ? b("Close", "Closed") : b("Reopen", "Open");
    return main + `<button type="button" onclick="editVacancyQualifications('${r.id}')">Qualifications</button>`
      + `<button type="button" onclick="openJobBoards('${r.id}')">Job boards</button>`
      + `<button type="button" onclick="archiveVacancy('${r.id}')">Archive</button>`;
  }
  // ---------- Qualifications (one per line, shown on the careers page) ----------
  const qualLines = (r) => String((r && r.qualifications) || "").split(/\n+/).map((q) => q.trim()).filter(Boolean);
  function qualSummary(r) {
    const q = qualLines(r);
    return q.length
      ? `<details class="vac-quals"><summary>Qualifications Required (${q.length})</summary><ul>${q.map((x) => `<li>${esc(x)}</li>`).join("")}</ul></details>`
      : `<small class="vac-quals-none">No qualifications listed yet</small>`;
  }
  window.editVacancyQualifications = function (id) {
    const v = VACS.find((r) => String(r.id) === String(id)); if (!v) return;
    let dlg = $("qualDialog");
    if (!dlg) {
      dlg = document.createElement("dialog");
      dlg.id = "qualDialog"; dlg.className = "qual-dialog";
      dlg.innerHTML = `<form method="dialog" class="qual-panel">
        <p class="qual-kicker">Careers page</p>
        <h3 id="qualTitle"></h3>
        <label for="qualText">Qualifications <small>(one per line)</small></label>
        <textarea id="qualText" rows="8" placeholder="Graduate of BS Nursing&#10;Valid PRC license&#10;At least 1 year of hospital experience"></textarea>
        <p class="qual-msg" id="qualMsg" role="alert"></p>
        <div class="qual-actions">
          <button type="button" class="secondary-action" id="qualCancel">Cancel</button>
          <button type="button" id="qualSave">Save</button>
        </div></form>`;
      document.body.appendChild(dlg);
      $("qualCancel").onclick = () => dlg.close();
    }
    $("qualTitle").textContent = v.title + (v.dept ? " \u2014 " + v.dept : "");
    $("qualText").value = qualLines(v).join("\n");
    $("qualMsg").textContent = "";
    $("qualSave").onclick = async () => {
      $("qualSave").disabled = true;
      try {
        await send("vacancies", { action: "qualifications", id: v.id, qualifications: $("qualText").value });
        dlg.close();
        note("Vacancies", `Qualifications updated for "${v.title}".`);
        loadVacancies();
      } catch (e) { $("qualMsg").textContent = e && e.message ? e.message : "Could not save."; }
      $("qualSave").disabled = false;
    };
    dlg.showModal();
    $("qualText").focus();
  };

  // ---------- Job boards: LinkedIn / JobStreet ----------
  // Real auto-posting needs LinkedIn/SEEK partner approval, so HR posts the job there and links back here.
  // Each board gets its own careers link (?source=...) so HIMS knows where every applicant came from.
  const careersLink = (v, source) => {
    const base = new URL("careers.html", location.href);
    base.search = ""; base.hash = "";
    base.searchParams.set("vacancy", v.id);
    if (source) base.searchParams.set("source", source);
    return base.href;
  };
  function boardTags(r) {
    const t = [];
    if (r.linkedinUrl) t.push('<span class="board-tag li">LinkedIn</span>');
    if (r.jobstreetUrl) t.push('<span class="board-tag js">JobStreet</span>');
    return t.length ? `<div class="board-tags">Posted on ${t.join(" ")}</div>` : "";
  }
  function jobPostText(v, source) {
    const q = qualLines(v);
    return [
      `We're hiring: ${v.title} (${v.dept})`,
      `${v.type} • ${v.slots} ${Number(v.slots) === 1 ? "slot" : "slots"}${v.deadline ? " • Apply by " + v.deadline : ""}`,
      v.description ? "\n" + v.description : "",
      q.length ? "\nQualifications Required:\n" + q.map((x) => "• " + x).join("\n") : "",
      `\nApply here: ${careersLink(v, source)}`,
    ].filter(Boolean).join("\n");
  }
  async function copyText(text, btn) {
    try { await navigator.clipboard.writeText(text); }
    catch (e) {
      const t = document.createElement("textarea"); t.value = text; document.body.appendChild(t); t.select();
      try { document.execCommand("copy"); } catch (x) { /* ignore */ } t.remove();
    }
    const old = btn.textContent; btn.textContent = "Copied!"; setTimeout(() => { btn.textContent = old; }, 1400);
  }
  window.openJobBoards = function (id) {
    const v = VACS.find((r) => String(r.id) === String(id)); if (!v) return;
    let dlg = $("boardsDialog");
    if (!dlg) {
      dlg = document.createElement("dialog");
      dlg.id = "boardsDialog"; dlg.className = "qual-dialog boards-dialog";
      document.body.appendChild(dlg);
    }
    const local = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);
    const row = (key, label, mark) => `<div class="board-row">
        <div class="board-name"><span class="board-dot ${key === "linkedin" ? "li" : key === "jobstreet" ? "js" : "fb"}">${mark}</span>${label}</div>
        <input readonly value="${esc(careersLink(v, key))}" aria-label="${label} tracking link">
        <button type="button" class="secondary-action" data-copy="${key}">Copy link</button>
        <button type="button" class="secondary-action" data-post="${key}">Copy job post</button>
      </div>`;
    dlg.innerHTML = `<form method="dialog" class="qual-panel">
      <p class="qual-kicker">Job boards</p>
      <h3>${esc(v.title)}${v.dept ? " — " + esc(v.dept) : ""}</h3>
      ${v.status !== "Open" ? `<p class="board-warn">This job is <strong>${esc(v.status)}</strong>. Publish it first so the careers link works.</p>` : ""}
      <p class="board-step"><strong>Step 1.</strong> Post the job. "Copy job post" gives you ready text with the right link inside.</p>
      ${row("linkedin", "LinkedIn", "in")}
      ${row("jobstreet", "JobStreet", "J")}
      ${row("facebook", "Facebook", "f")}
      <div class="board-share">
        <button type="button" id="shareLinkedIn"><span class="board-dot li">in</span> Share on LinkedIn</button>
        <button type="button" id="shareFacebook" class="secondary-action"><span class="board-dot fb">f</span> Share on Facebook</button>
      </div>
      ${local ? `<p class="board-warn">You're on <strong>${esc(location.host)}</strong>. These links only open on this PC for now; they work for everyone once HIMS is online.</p>` : ""}
      <p class="board-step"><strong>Step 2.</strong> Paste the link of the post so applicants see "Also posted on" on the careers page.</p>
      <label for="boardLinkedIn">LinkedIn job link</label>
      <input id="boardLinkedIn" type="url" placeholder="https://www.linkedin.com/jobs/view/..." value="${esc(v.linkedinUrl || "")}">
      <label for="boardJobStreet">JobStreet job link</label>
      <input id="boardJobStreet" type="url" placeholder="https://ph.jobstreet.com/job/..." value="${esc(v.jobstreetUrl || "")}">
      <p class="qual-msg" id="boardMsg" role="alert"></p>
      <div class="qual-actions">
        <button type="button" class="secondary-action" id="boardClose">Close</button>
        <button type="button" id="boardSave">Save links</button>
      </div></form>`;
    dlg.querySelectorAll("[data-copy]").forEach((b) => { b.onclick = () => copyText(careersLink(v, b.dataset.copy), b); });
    dlg.querySelectorAll("[data-post]").forEach((b) => { b.onclick = () => copyText(jobPostText(v, b.dataset.post), b); });
    $("shareLinkedIn").onclick = () => window.open("https://www.linkedin.com/sharing/share-offsite/?url=" + encodeURIComponent(careersLink(v, "linkedin")), "_blank", "noopener,width=640,height=720");
    $("shareFacebook").onclick = () => window.open("https://www.facebook.com/sharer/sharer.php?u=" + encodeURIComponent(careersLink(v, "facebook")), "_blank", "noopener,width=640,height=720");
    $("boardClose").onclick = () => dlg.close();
    $("boardSave").onclick = async () => {
      $("boardSave").disabled = true;
      try {
        await send("vacancies", { action: "boards", id: v.id, linkedinUrl: $("boardLinkedIn").value.trim(), jobstreetUrl: $("boardJobStreet").value.trim() });
        dlg.close();
        note("Vacancies", `Job board links updated for "${v.title}".`);
        loadVacancies();
      } catch (e) { $("boardMsg").textContent = e && e.message ? e.message : "Could not save."; }
      $("boardSave").disabled = false;
    };
    dlg.showModal();
  };

  window.renderVacancies = renderVacancies;
  function renderVacancies() {
    const av = viewArchived("vacShowArchived");
    const list = VACS.filter((r) => !!Number(r.archived) === av);
    $("vacancyTable").innerHTML = list.length ? list.map((r) => `<tr>
      <td><strong>${esc(r.source || "HR1")}</strong>${r.sourceRef ? `<small>${esc(r.sourceRef)}</small>` : ""}</td>
      <td><strong>${esc(r.title)}</strong><small>${esc(r.type)}${r.deadline ? " &bull; apply by " + esc(r.deadline) : ""}</small>${boardTags(r)}${qualSummary(r)}</td>
      <td>${esc(r.dept)}</td><td>${esc(r.slots)}</td><td>${esc(r.requestedBy || "-")}</td><td>${esc(r.received || "-")}</td>
      <td><button type="button" onclick="viewVacancyApplications('${r.id}')" title="Show these applications">${esc(r.applications)} received</button></td>
      <td>${badge(r.status)}</td>
      <td>${av ? `<button type="button" onclick="restoreVacancy('${r.id}')">Restore</button>` : vacancyActions(r)}</td></tr>`).join("")
      : empty(9, av ? "No archived hire orders." : "No hire orders received from HR3 yet.");
  }

  // ---------- Applications ----------
  const APP_STATUS = ["Applied", "Screening", "Interview", "Hired", "Rejected"];
  window.addApplicant = async function () {
    if (!need([["appName", "Applicant name"], ["appEmail", "Email"], ["appVacancy", "Vacancy"]])) return;
    const name = $("appName").value.trim();
    try {
      await send("applications", { action: "create", name, email: $("appEmail").value.trim(), phone: $("appPhone").value.trim(), vacancy_id: Number($("appVacancy").value) });
    } catch (e) { return fail(e); }
    clear(["appName", "appEmail", "appPhone"]);
    note("Applicants", `Applicant ${name} added.`);
    loadApplicants(); loadVacancies();
  };
  window.setApplicantStatus = async function (id, status) {
    const x = APPS.find((r) => String(r.id) === String(id)); if (!x) return;
    try { await send("applications", { action: "status", id, status }); } catch (e) { return fail(e); }
    note("Applicants", `Applicant ${x.name} status changed to ${status}.${status === "Hired" ? " Super Admin can now provision the trainee account." : ""}`);
    loadApplicants();
  };
  window.archiveApplicant = async function (id) {
    const x = APPS.find((r) => String(r.id) === String(id)); if (!x) return;
    if (!confirm(`Archive applicant ${x.name}? They will be hidden from this list. You can restore them from "Show archived".`)) return;
    try { await send("applications", { action: "archive", id }); } catch (e) { return fail(e); }
    note("Applicants", `Applicant ${x.name} archived.`);
    loadApplicants();
  };
  window.restoreApplicant = async function (id) {
    const x = APPS.find((r) => String(r.id) === String(id)); if (!x) return;
    try { await send("applications", { action: "restore", id }); } catch (e) { return fail(e); }
    note("Applicants", `Applicant ${x.name} restored from the archive.`);
    loadApplicants();
  };
  function renderApplicants() {
    const q = ($("appSearch").value || "").toLowerCase();
    const archivedView = !!($("appShowArchived") && $("appShowArchived").checked);
    const src = ($("appSourceFilter") && $("appSourceFilter").value) || "All";
    const a = APPS.filter((r) => !!Number(r.archived) === archivedView && (src === "All" || appSource(r) === src || appChannel(r) === src)
      && [r.name, r.email, r.vacancy, r.status, appSource(r), appChannel(r)].join(" ").toLowerCase().includes(q));
    renderChannelSummary(APPS.filter((r) => !!Number(r.archived) === archivedView));
    $("applicantTable").innerHTML = a.length ? a.map((r) => `<tr>
      <td>${esc(r.name)}<br><small>${esc(r.email)}${r.phone ? " · " + esc(r.phone) : ""}</small></td>
      <td><span class="source-tag source-${appSource(r).toLowerCase()}">${appSource(r)}</span>${appSource(r) === "External" ? `<small class="channel-tag channel-${appChannel(r).toLowerCase().replace(/[^a-z]/g, "")}">via ${esc(appChannel(r))}</small>` : ""}</td>
      <td>${esc(r.vacancy || "—")}</td><td>${esc(r.date)}</td>
      <td>${r.resumeName ? `<button type="button" onclick="openResume('${r.id}')">View</button> <button type="button" onclick="downloadResume('${r.id}')">Download</button>` : "—"}</td>
      <td>${badge(r.status)}</td>
      <td>${statusSelect(APP_STATUS, r.status, "setApplicantStatus", r.id)}
          ${r.status === "Hired" ? '<small>Ready for Super Admin trainee account setup</small>' : ""}
          ${archivedView
            ? `<button type="button" onclick="restoreApplicant('${r.id}')">Restore</button>`
            : `<button type="button" onclick="archiveApplicant('${r.id}')">Archive</button>`}</td></tr>`).join("")
      : empty(7, archivedView ? "No archived applicants." : "No applications found.");
  }

  // ---------- Resume viewer (files are fetched from the server with the admin's login) ----------
  async function resumeBlob(id) {
    const res = await fetch(`${apiUrl}?resource=resume&id=${encodeURIComponent(id)}`, { credentials: "same-origin" });
    if (!res.ok) { const d = await res.json().catch(() => ({})); fail(new Error(d.error || "Could not load the resume.")); return null; }
    return res.blob();
  }
  let resumeUrl = null;
  window.closeResume = () => {
    $("resumePanel").hidden = true; $("resumeBody").innerHTML = "";
    if (resumeUrl) { URL.revokeObjectURL(resumeUrl); resumeUrl = null; }
  };
  window.openResume = async (id) => {
    const x = APPS.find((r) => String(r.id) === String(id)); if (!x) return;
    const blob = await resumeBlob(id); if (!blob) return;
    if (resumeUrl) URL.revokeObjectURL(resumeUrl);
    resumeUrl = URL.createObjectURL(blob);
    const name = x.resumeName || "resume", body = $("resumeBody");
    $("resumeTitle").textContent = x.name;
    $("resumeMeta").textContent = (x.vacancy || "Application") + " \u2022 " + name;
    $("resumeDownload").onclick = () => downloadResume(id);
    if (/\.pdf$/i.test(name)) {
      body.innerHTML = `<iframe src="${resumeUrl}" title="Resume of ${esc(x.name)}" style="width:100%;height:70vh;border:1px solid var(--line);border-radius:12px;background:#fff"></iframe>`;
    } else if (/\.(jpe?g|png)$/i.test(name)) {
      body.innerHTML = `<img src="${resumeUrl}" alt="Resume of ${esc(x.name)}" style="display:block;max-width:100%;max-height:70vh;margin:0 auto;border-radius:12px">`;
    } else {
      body.innerHTML = `<p class="table-empty">Word files can't be previewed in the browser. Use Download to open ${esc(name)}.</p>`;
    }
    if (x.note) body.insertAdjacentHTML("beforeend", `<p><strong>Message from applicant:</strong> ${esc(x.note)}</p>`);
    $("resumePanel").hidden = false;
  };
  window.downloadResume = async (id) => {
    const x = APPS.find((r) => String(r.id) === String(id)); if (!x) return;
    const blob = await resumeBlob(id); if (!blob) return;
    const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = x.resumeName || "resume"; a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  };

  // ---------- Interview ----------
  const INT_STATUS = ["Scheduled", "Completed", "Cancelled"];
  const INT_RESULT = ["Pending", "Passed", "Failed"];
  window.addInterview = function () {
    if (!need([["intApplicant", "Applicant"], ["intDate", "Date"], ["intTime", "Time"], ["intInterviewer", "Interviewer"]])) return;
    const app = APPS.find((r) => String(r.id) === $("intApplicant").value);
    if (!app) return;
    const i = read("interviews");
    i.unshift({ id: uid(), applicantId: app.id, name: app.name, vacancy: app.vacancy, date: $("intDate").value,
      time: $("intTime").value, interviewer: $("intInterviewer").value.trim(), mode: $("intMode").value,
      status: "Scheduled", result: "Pending" });
    write("interviews", i);
    clear(["intDate", "intTime", "intInterviewer"]);
    note("Interview", `Interview scheduled for ${app.name} on ${i[0].date}.`);
    renderInterviews();
  };
  window.setInterviewField = function (id, field, value) {
    const i = read("interviews"); const x = i.find((r) => r.id === id); if (!x) return;
    x[field] = value; write("interviews", i);
    note("Interview", `Interview for ${x.name}: ${field} set to ${value}.`);
    renderInterviews();
  };
  window.archiveInterview = function (id) {
    const i = read("interviews"); const x = i.find((r) => r.id === id); if (!x) return;
    if (!confirm(`Archive the interview for ${x.name}? You can restore it from "Show archived".`)) return;
    x.archived = true; write("interviews", i);
    note("Interview", `Interview for ${x.name} archived.`);
    renderInterviews();
  };
  window.restoreInterview = function (id) {
    const i = read("interviews"); const x = i.find((r) => r.id === id); if (!x) return;
    x.archived = false; write("interviews", i);
    note("Interview", `Interview for ${x.name} restored from the archive.`);
    renderInterviews();
  };
  function renderInterviews() {
    const av = viewArchived("intShowArchived");
    const i = read("interviews").filter((r) => !!r.archived === av);
    $("interviewTable").innerHTML = i.length ? i.map((r) => `<tr>
      <td>${esc(r.name)}<br><small>${esc(r.vacancy || "")}</small></td>
      <td>${esc(r.date)} ${esc(r.time)}</td><td>${esc(r.interviewer)}</td><td>${esc(r.mode)}</td>
      <td><select onchange="setInterviewField('${r.id}','status',this.value)">${INT_STATUS.map((s) => `<option${s === r.status ? " selected" : ""}>${s}</option>`).join("")}</select></td>
      <td><select onchange="setInterviewField('${r.id}','result',this.value)">${INT_RESULT.map((s) => `<option${s === r.result ? " selected" : ""}>${s}</option>`).join("")}</select></td>
      <td>${av ? `<button type="button" onclick="restoreInterview('${r.id}')">Restore</button>`
        : `<button type="button" onclick="archiveInterview('${r.id}')">Archive</button>`}</td></tr>`).join("")
      : empty(7, av ? "No archived interviews." : "No interviews scheduled yet.");
  }

  // ---------- dropdowns + navigation ----------
  function refreshSelects() {
    if (!$("appVacancy")) return;
    $("appVacancy").innerHTML = '<option value="">Select vacancy</option>' +
      VACS.filter((v) => v.status === "Open" && !Number(v.archived)).map((v) => `<option value="${v.id}">${esc(v.title)} (${esc(v.dept)})</option>`).join("");
    $("intApplicant").innerHTML = '<option value="">Select applicant</option>' +
      APPS.filter((a) => a.status !== "Rejected" && !Number(a.archived)).map((a) => `<option value="${a.id}">${esc(a.name)}${a.vacancy ? " – " + esc(a.vacancy) : ""}</option>`).join("");
  }

  const baseShow = window.showModule;
  window.showModule = function (id) {
    baseShow(id);
    if (id === "vacancies") loadVacancies();
    if (id === "applicants") { loadVacancies(); loadApplicants(); }
    if (id === "interview") loadApplicants();
    if (id === "auditlog") renderAudit();
  };

  window.renderApplicants = renderApplicants;
  window.renderVacancies = renderVacancies;
  window.renderInterviews = renderInterviews;
  window.renderAudit = renderAudit;
  window.addEventListener("DOMContentLoaded", refreshSelects);
})();
