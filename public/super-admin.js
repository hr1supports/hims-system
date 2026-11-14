(() => {
  const $ = (id) => document.getElementById(id);
  const roles = ["HR1", "HR3", "ADMIN", "HR_OFFICER", "HR_STAFF", "EMPLOYEE", "TRAINEE"];
  const roleName = { ADMIN: "Admin", HR_OFFICER: "HR Officer", HR_STAFF: "HR Staff", EMPLOYEE: "Employee", TRAINEE: "Trainee", HR1: "Admin (HR1)", HR3: "Admin (HR3)" };
  const STAFF_ROLES = ["HR_OFFICER", "HR_STAFF", "ADMIN", "HR1", "HR3"];
  let accountRows = [];
  let auditRows = [];
  let auditTimer;

  const esc = (value) => escapeHtml(value == null ? "" : value);
  const actor = () => JSON.parse(localStorage.getItem("currentUser") || "null");
  const request = (body) => apiRequest("super-admin", { method: "POST", body: JSON.stringify(body) });
  const showError = (error) => showWarningToast(error && error.message ? error.message : "Could not load Super Admin data.");
  let acceptedApplications = [];
  const updateAccountRoleFields = (role) => {
    const trainee = role === "TRAINEE";
    const staff = STAFF_ROLES.includes(role);
    $("traineeTrainingFields").hidden = !trainee;
    if ($("superAddEmployeeRow")) $("superAddEmployeeRow").hidden = !staff;
    // Contact, hire date, emergency contact and salary are for employee records:
    // employees always, HR staff when "Also add to the Employee Directory" is ticked.
    const employeeFields = role === "EMPLOYEE" || (staff && !!($("superAddEmployee") && $("superAddEmployee").checked));
    document.querySelectorAll(".employee-account-field").forEach((field) => { field.hidden = !employeeFields; });
    if ($("superEmployeeFields")) $("superEmployeeFields").hidden = !employeeFields;
    $("superName").readOnly = trainee;
    $("superEmail").readOnly = trainee;
    $("superDepartment").readOnly = trainee;
    $("superPosition").readOnly = trainee;
    $("superAcceptedApplication").required = trainee;
    if (!trainee) $("superAccountForm").querySelector('button[type="submit"]').disabled = false;
    if (trainee) loadAcceptedApplications();
  };

  async function loadAcceptedApplications() {
    const select = $("superAcceptedApplication");
    select.innerHTML = '<option value="">Loading accepted applications...</option>';
    try {
      const result = await apiRequest("super-admin", { query: { view: "accepted-applications" } });
      acceptedApplications = result.records || [];
      select.innerHTML = acceptedApplications.length
        ? '<option value="">Select an accepted applicant</option>' + acceptedApplications.map((row) =>
          `<option value="${Number(row.id)}">${esc(row.name)} · ${esc(row.email)} · ${esc(row.program || "Accepted")}</option>`).join("")
        : '<option value="">No eligible accepted applicants</option>';
      $("superAccountForm").querySelector('button[type="submit"]').disabled =
        $("superRole").value === "TRAINEE" && acceptedApplications.length === 0;
    } catch (error) {
      select.innerHTML = '<option value="">Could not load accepted applications</option>';
      $("superAccountForm").querySelector('button[type="submit"]').disabled = $("superRole").value === "TRAINEE";
      showError(error);
    }
  }

  function fillTraineeApplicant(applicationId) {
    const row = acceptedApplications.find((application) => Number(application.id) === Number(applicationId));
    if (!row) return;
    $("superName").value = row.name;
    $("superEmail").value = row.email;
    $("superDepartment").value = row.department || "";
    $("superPosition").value = row.program || "";
    const program = $("superAccountForm").elements.namedItem("training_program");
    if (program) program.value = row.program || "General onboarding";
  }
  async function loadSuperAdminOverview() {
    try {
      const result = await apiRequest("super-admin", { query: { view: "overview" } });
      $("superActiveAccounts").textContent = Number(result.stats.activeAccounts).toLocaleString();
      $("superDisabledAccounts").textContent = Number(result.stats.disabledAccounts).toLocaleString();
      $("superFailedLogins").textContent = Number(result.stats.failedLogins).toLocaleString();
      $("superRecentTable").innerHTML = result.recent.length
        ? result.recent.map((row) => `<tr><td>${esc(row.created)}</td><td>${esc(row.actor || "Former account")}</td><td><span class="super-action-tag">${esc(row.action)}</span></td><td>${esc(row.details)}</td></tr>`).join("")
        : '<tr><td colspan="4" class="table-empty">No administrative activity recorded.</td></tr>';
      const status = document.querySelector(".system-status");
      status.classList.remove("is-offline");
      const dbase = result.database || {};
      const where = dbase.mode === "cloud" ? "Cloud database" : "Local database";
      status.innerHTML = dbase.fallback
        ? `<i></i> Local database (backup) connected`
        : `<i></i> ${where} connected`;
      status.classList.toggle("is-fallback", !!dbase.fallback);
      if (dbase.fallback) showWarningToast(`${dbase.reason || "Cloud database unavailable"}. HIMS switched to the local database.`);
      status.title = dbase.host ? `${dbase.name || ""} on ${dbase.host}${dbase.version ? " \u00B7 MySQL " + dbase.version : ""}` : "";
      status.dataset.mode = dbase.mode || "local";
    } catch (error) {
      const status = document.querySelector(".system-status");
      status.classList.add("is-offline");
      status.innerHTML = "<i></i> Database unavailable";
      $("superRecentTable").innerHTML = '<tr><td colspan="4" class="table-empty">System activity could not be loaded.</td></tr>';
      showError(error);
    }
  }

  async function loadSuperAdminAccounts() {
    try {
      const result = await apiRequest("super-admin", { query: { view: "accounts" } });
      accountRows = result.records || [];
      renderAccounts();
    } catch (error) {
      $("superAccountsTable").innerHTML = '<tr><td colspan="6" class="table-empty">Accounts could not be loaded.</td></tr>';
      showError(error);
    }
  }

  // Online now / signed in today / locked / disabled (Super Admin > Accounts & Roles)
  const isOnline = (row) => !!row.online;
  const signedInToday = (row) => Number(row.loginToday) === 1;
  const isDisabled = (row) => Number(row.active) !== 1;
  const ACCOUNT_FILTERS = {
    all: () => true, online: isOnline, today: signedInToday,
    locked: (row) => !!row.locked, disabled: isDisabled,
  };
  function renderAccountSummary() {
    const box = $("superAccountSummary");
    if (!box) return;
    const count = (fn) => accountRows.filter(fn).length;
    const chip = (key, label, n, tone) => `<button type="button" class="activity-chip ${tone}${($("superAccountFilter") || {}).value === key ? " is-selected" : ""}" data-account-filter="${key}">${label} <strong>${n}</strong></button>`;
    const unavailable = accountRows.some((row) => Number(row.onlineUnavailable) === 1);
    box.innerHTML = chip("online", '<span class="online-dot"></span>Online now', count(isOnline), "tone-online")
      + chip("today", "Signed in today", count(signedInToday), "tone-today")
      + chip("locked", "Locked", count((row) => !!row.locked), "tone-locked")
      + chip("disabled", "Disabled", count(isDisabled), "tone-muted")
      + (unavailable ? '<small class="activity-note">"Online now" starts working after database/018_online_status.sql is run in MySQL Workbench.</small>' : "");
  }
  function lastSignInCell(row) {
    if (!row.lastLogin) return "Never";
    const today = signedInToday(row);
    const times = Number(row.loginsToday) || 0;
    const when = today ? `Today ${esc(row.lastLoginTime || "")}` : esc(row.lastLogin);
    const extra = today && times > 1 ? `<small>${times} sign-ins today</small>` : "";
    const seen = !isOnline(row) && row.lastSeenTime && today ? `<small>Last active ${esc(row.lastSeenTime)}</small>` : "";
    const device = row.lastDevice ? `<small class="device-label">${esc(row.lastDevice)}</small>` : "";
    return when + extra + seen + device;
  }

  // ---------- Sessions: every sign-in of an account, with device, IP and a Sign out button ----------
  let sessionsFor = null;
  function sessionsDialog() {
    let dialog = $("superSessionsDialog");
    if (dialog) return dialog;
    dialog = document.createElement("div");
    dialog.id = "superSessionsDialog";
    dialog.className = "modal-backdrop";
    dialog.hidden = true;
    dialog.innerHTML = `<section class="modal-panel sessions-panel" role="dialog" aria-modal="true" aria-labelledby="superSessionsTitle">
      <div class="modal-header">
        <div><span class="modal-eyebrow">Login sessions</span><h2 id="superSessionsTitle">Sessions</h2><p id="superSessionsSub" class="modal-supporting-text"></p></div>
        <button class="modal-close" type="button" aria-label="Close sessions" data-sessions-close>&times;</button>
      </div>
      <div id="superSessionsBody" class="request-table-wrap"></div>
    </section>`;
    document.body.appendChild(dialog);
    dialog.addEventListener("click", async (event) => {
      if (event.target === dialog || event.target.closest("[data-sessions-close]")) { dialog.hidden = true; return; }
      const end = event.target.closest("[data-end-session]");
      if (!end || !sessionsFor) return;
      if (!window.confirm(`Sign out this device of ${sessionsFor.name}? They will have to log in again on it.`)) return;
      end.disabled = true;
      try {
        await request({ action: "end-session", id: sessionsFor.id, session_id: Number(end.dataset.endSession) });
        showSuccessToast("Device signed out.");
        await showSessions(sessionsFor.id, sessionsFor.name);
        loadSuperAdminAccounts();
      } catch (error) { showError(error); end.disabled = false; }
    });
    return dialog;
  }
  async function showSessions(id, name) {
    sessionsFor = { id, name };
    const dialog = sessionsDialog();
    $("superSessionsTitle").textContent = `Sessions: ${name}`;
    $("superSessionsSub").textContent = "Latest 30 sign-ins. A session is active while the person uses HIMS; it times out after 5 idle minutes.";
    $("superSessionsBody").innerHTML = '<p class="table-empty">Loading sessions...</p>';
    dialog.hidden = false;
    try {
      const result = await apiRequest("super-admin", { query: { view: "sessions", id } });
      if (!result.ready) {
        $("superSessionsBody").innerHTML = '<p class="table-empty">Run database/019_login_sessions.sql in MySQL Workbench to start recording devices.</p>';
        return;
      }
      const rows = result.records || [];
      $("superSessionsBody").innerHTML = rows.length ? `<table class="request-table"><thead><tr><th>Device</th><th>IP address</th><th>Signed in</th><th>Last active</th><th>Status</th><th></th></tr></thead><tbody>${rows.map((s) => `<tr>
          <td><strong>${esc(s.device || "Unknown device")}</strong>${s.current ? '<small class="online-label">This device (you)</small>' : ""}</td>
          <td>${esc(s.ip || "-")}</td>
          <td>${esc(s.started || "-")}</td>
          <td>${esc(s.active ? s.lastActive : (s.ended || s.lastActive || "-"))}</td>
          <td><span class="super-status ${s.active ? "is-active" : "is-disabled"}">${esc(s.status)}</span></td>
          <td>${s.active && !s.current ? `<button type="button" class="danger-action" data-end-session="${Number(s.id)}">Sign out</button>` : ""}</td>
        </tr>`).join("")}</tbody></table>`
        : '<p class="table-empty">No sign-ins recorded yet. New sign-ins appear here.</p>';
    } catch (error) {
      $("superSessionsBody").innerHTML = `<p class="table-empty">${esc(error.message || "Sessions could not be loaded.")}</p>`;
    }
  }

  function renderAccounts() {
    renderAccountSummary();
    const query = $("superAccountSearch").value.trim().toLowerCase();
    const show = ACCOUNT_FILTERS[($("superAccountFilter") || {}).value || "all"] || ACCOUNT_FILTERS.all;
    const filtered = accountRows.filter((row) => show(row) && [row.name, row.email, row.role, row.department, row.position]
      .join(" ").toLowerCase().includes(query));
    const current = actor();
    $("superAccountsTable").innerHTML = filtered.length ? filtered.map((row) => {
      const isActive = Number(row.active) === 1;
      const isSelf = current && Number(current.id) === Number(row.id);
      const roleControl = row.role === "SUPER_ADMIN"
        ? '<span class="super-role-lock">SUPER ADMIN</span>'
        : `<select data-role-select data-account-id="${Number(row.id)}" aria-label="Role for ${esc(row.name)}">${roles.map((role) => `<option value="${role}"${role === row.role ? " selected" : ""}${role === "TRAINEE" && row.role !== "TRAINEE" ? " disabled" : ""}>${roleName[role] || role}</option>`).join("")}</select>`;
      const status = isActive ? (row.locked ? "Locked" : "Active") : "Disabled";
      const approval = row.approval || "approved";
      const approvalControls = row.role === "EMPLOYEE" && approval === "pending"
        ? `<button type="button" data-super-action="approve" data-account-id="${Number(row.id)}">Approve</button><button type="button" class="danger-action" data-super-action="reject" data-account-id="${Number(row.id)}">Reject</button>`
        : "";
      const statusAction = isSelf && row.role === "SUPER_ADMIN"
        ? '<button type="button" disabled>Current account</button>'
        : `<button type="button" data-super-action="toggle" data-account-id="${Number(row.id)}" data-active="${!isActive}" class="${isActive ? "danger-action" : ""}">${isActive ? "Disable" : "Enable"}</button>`;
      const trainingAction = row.role === "TRAINEE"
        ? `<button type="button" data-super-action="training" data-account-id="${Number(row.id)}">Training</button>`
        : "";
      return `<tr>
        <td><strong>${esc(row.name)}</strong><small>${esc(row.email)}</small></td>
        <td>${roleControl}${row.role === "TRAINEE" ? `<small>${esc(row.trainingProgram || "No program")} · ${esc(row.trainingStatus || "Not started")}</small>` : ""}</td>
        <td>${esc(row.department || "-")}${row.position ? `<small>${esc(row.position)}</small>` : ""}</td>
        <td><span class="super-status ${isActive && !row.locked ? "is-active" : "is-disabled"}">${status}</span>${row.locked ? `<small>${Number(row.lockFailures) || ""} failed tries · until ${esc(row.lockedUntil || "")}</small>` : ""}${isOnline(row) ? '<small class="online-label"><span class="online-dot"></span>Online now</small>' : ""}${row.totpEnabled ? '<small>Authenticator app on</small>' : ""}</td>
        <td>${lastSignInCell(row)}</td>
        <td class="super-row-actions">${row.locked ? `<button type="button" data-super-action="unlock" data-account-id="${Number(row.id)}" data-account-name="${esc(row.name)}">Unlock</button>` : ""}<button type="button" class="secondary-action" data-super-action="sessions" data-account-id="${Number(row.id)}" data-account-name="${esc(row.name)}">Sessions${Number(row.activeSessions) ? ` (${Number(row.activeSessions)} active)` : ""}</button>${row.totpEnabled ? `<button type="button" class="secondary-action" data-super-action="reset2fa" data-account-id="${Number(row.id)}" data-account-name="${esc(row.name)}">Remove authenticator</button>` : ""}<button type="button" data-super-action="reset" data-account-id="${Number(row.id)}" data-account-name="${esc(row.name)}">Reset password</button>${trainingAction}${statusAction}</td>
      </tr>`;
    }).join("") : '<tr><td colspan="6" class="table-empty">No matching accounts.</td></tr>';
  }

  async function loadSuperAdminAudit() {
    try {
      const query = $("superAuditSearch").value.trim();
      const result = await apiRequest("super-admin", { query: { view: "audit", q: query } });
      auditRows = result.records || [];
      $("superAuditTable").innerHTML = auditRows.length ? auditRows.map((row) => `<tr>
        <td>${esc(row.created)}</td><td>${esc(row.actor || "Former account")}<small>${esc(row.actorEmail || "")}</small></td>
        <td><span class="super-action-tag">${esc(row.action)}</span></td><td>${esc(row.details)}</td><td>${esc(row.ip || "-")}</td>
      </tr>`).join("") : '<tr><td colspan="5" class="table-empty">No matching audit events.</td></tr>';
    } catch (error) {
      $("superAuditTable").innerHTML = '<tr><td colspan="5" class="table-empty">Audit history could not be loaded.</td></tr>';
      showError(error);
    }
  }

  window.updateSuperAdminRole = async (id, role) => {
    if (!roles.includes(role) || !window.confirm(`Change this account's role to ${role}?`)) {
      await loadSuperAdminAccounts();
      return;
    }
    try {
      await request({ action: "set-role", id, role });
      showSuccessToast("Account role updated.");
      await Promise.all([loadSuperAdminAccounts(), loadSuperAdminOverview()]);
    } catch (error) { showError(error); await loadSuperAdminAccounts(); }
  };

  window.toggleSuperAdminAccount = async (id, active) => {
    const action = active ? "enable" : "disable";
    if (!window.confirm(`Are you sure you want to ${action} this account?`)) return;
    try {
      await request({ action: "set-active", id, active });
      showSuccessToast(`Account ${active ? "enabled" : "disabled"}.`);
      await Promise.all([loadSuperAdminAccounts(), loadSuperAdminOverview()]);
    } catch (error) { showError(error); }
  };

  window.setSuperAdminApproval = async (id, approval) => {
    if (!window.confirm(`${approval === "approved" ? "Approve" : "Reject"} this employee account?`)) return;
    try {
      await request({ action: "set-approval", id, approval });
      showSuccessToast(`Employee account ${approval}.`);
      await Promise.all([loadSuperAdminAccounts(), loadSuperAdminOverview()]);
    } catch (error) { showError(error); }
  };

  window.unlockSuperAdminAccount = async (id, name) => {
    if (!window.confirm(`Unlock the account of ${name}? They can sign in again right away.`)) return;
    try {
      await request({ action: "unlock", id });
      showSuccessToast("Account unlocked.");
      await Promise.all([loadSuperAdminAccounts(), loadSuperAdminOverview()]);
      if (window.refreshSuperTools) window.refreshSuperTools();
    } catch (error) { showError(error); }
  };

  window.resetSuperAdminPassword = (id, name) => {
    $("superPasswordResetForm").reset();
    $("superResetAccountId").value = id;
    $("superResetAccount").textContent = name;
    $("superResetError").textContent = "";
    $("superPasswordResetDialog").hidden = false;
    document.body.classList.add("modal-open");
    $("superResetPassword").focus();
  };

  window.closeSuperPasswordReset = () => {
    $("superPasswordResetDialog").hidden = true;
    document.body.classList.remove("modal-open");
  };

  window.openTraineeTraining = (id) => {
    const row = accountRows.find((account) => Number(account.id) === Number(id));
    if (!row || row.role !== "TRAINEE") return;
    $("traineeTrainingForm").reset();
    $("traineeTrainingAccountId").value = row.id;
    $("traineeTrainingAccount").textContent = `${row.name} · ${row.email}`;
    $("traineeTrainingProgram").value = row.trainingProgram || "General onboarding";
    $("traineeTrainingStart").value = row.trainingStart || "";
    $("traineeTrainingEnd").value = row.trainingEnd || "";
    $("traineeTrainingStatus").value = row.trainingStatus || "Not started";
    $("traineeTrainingNotes").value = row.trainingNotes || "";
    $("traineeTrainingError").textContent = "";
    $("traineeTrainingDialog").hidden = false;
    document.body.classList.add("modal-open");
    $("traineeTrainingProgram").focus();
  };

  window.closeTraineeTraining = () => {
    $("traineeTrainingDialog").hidden = true;
    document.body.classList.remove("modal-open");
  };

  window.exportSuperAudit = () => {
    const rows = [["Date", "Administrator", "Email", "Action", "Details", "Source"]]
      .concat(auditRows.map((row) => [row.created, row.actor, row.actorEmail, row.action, row.details, row.ip]));
    const csv = rows.map((row) => row.map((value) => `"${String(value || "").replace(/"/g, '""')}"`).join(",")).join("\n");
    const link = document.createElement("a");
    link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
    link.download = "hr1-security-audit.csv";
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  };

  const audienceLabel = { all: "Everyone", admin: "Admins", employee: "Employees", trainee: "Trainees" };

  async function loadSuperAnnouncements() {
    try {
      const result = await apiRequest("announcements");
      const rows = result.records || [];
      $("superAnnouncementsTable").innerHTML = rows.length ? rows.map((row) => {
        const archived = Number(row.archived) === 1;
        return `<tr class="${archived ? "is-archived" : ""}">
        <td>${esc(row.created)}</td><td><strong>${esc(row.title)}</strong>${row.body ? `<small>${esc(row.body)}</small>` : ""}</td>
        <td>${esc(audienceLabel[row.audience] || row.audience)}${archived ? ' <span class="super-status is-disabled">Archived</span>' : ""}</td><td>${esc(row.author || "-")}</td>
        <td class="super-row-actions"><button type="button" data-announcement-id="${Number(row.id)}" data-announcement-action="${archived ? "restore" : "archive"}">${archived ? "Restore" : "Archive"}</button></td>
      </tr>`;
      }).join("") : '<tr><td colspan="5" class="table-empty">No announcements posted yet.</td></tr>';
    } catch (error) {
      $("superAnnouncementsTable").innerHTML = '<tr><td colspan="5" class="table-empty">Announcements could not be loaded. Run database/008_announcements.sql if you have not yet.</td></tr>';
      showError(error);
    }
  }

  window.postSuperAnnouncement = async () => {
    const title = $("annTitle").value.trim();
    if (!title) { showWarningToast("Enter a title for the announcement."); return; }
    try {
      await request({ action: "announcement-create", title, body: $("annBody").value.trim(), audience: $("annAudience").value });
      $("annTitle").value = "";
      $("annBody").value = "";
      showSuccessToast("Announcement posted.");
      await loadSuperAnnouncements();
    } catch (error) { showError(error); }
  };

  $("superAnnouncementsTable").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-announcement-id]");
    if (!button) return;
    const archive = button.dataset.announcementAction === "archive";
    if (archive && !window.confirm("Archive this announcement? It will be hidden from dashboards.")) return;
    try {
      await request({ action: archive ? "announcement-archive" : "announcement-restore", id: Number(button.dataset.announcementId) });
      showSuccessToast(archive ? "Announcement archived." : "Announcement restored.");
      await loadSuperAnnouncements();
    } catch (error) { showError(error); }
  });

  window.loadSuperAnnouncements = loadSuperAnnouncements;
  window.loadSuperAdminOverview = loadSuperAdminOverview;
  window.loadSuperAdminAccounts = loadSuperAdminAccounts;
  window.loadSuperAdminAudit = loadSuperAdminAudit;

  $("superAccountForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget; // keep a reference: currentTarget becomes null after the first await
    const values = Object.fromEntries(new FormData(form));
    if (!values.role) {
      showWarningToast("Choose a role: HR Officer, HR Staff, Employee, Admin or Trainee.");
      $("superRole").focus();
      return;
    }
    if (!STAFF_ROLES.includes(values.role)) delete values.add_employee;
    if (values.role === "TRAINEE" && !values.application_id) {
      showWarningToast("Select an accepted application before creating a trainee account.");
      return;
    }
    try {
      const result = await request({ action: "create-account", ...values });
      form.reset();
      updateAccountRoleFields($("superRole").value);
      await loadAcceptedApplications();
      showSuccessToast(result.message || `${roleName[values.role] || values.role} account created.`);
      await Promise.all([loadSuperAdminAccounts(), loadSuperAdminOverview()]);
    } catch (error) { showError(error); }
  });
  $("superRole").addEventListener("change", (event) => {
    updateAccountRoleFields(event.target.value);
  });
  if ($("superAddEmployee")) $("superAddEmployee").addEventListener("change", () => updateAccountRoleFields($("superRole").value));
  updateAccountRoleFields($("superRole").value); // start with only the fields that apply
  $("superAcceptedApplication").addEventListener("change", (event) => {
    fillTraineeApplicant(event.target.value);
  });
  $("traineeTrainingForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.currentTarget));
    values.id = Number(values.id);
    try {
      await request({ action: "update-trainee-training", ...values });
      window.closeTraineeTraining();
      showSuccessToast("Trainee training updated.");
      await loadSuperAdminAccounts();
    } catch (error) {
      $("traineeTrainingError").textContent = error.message || "Training update failed.";
    }
  });
  $("superPasswordResetForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    $("superResetError").textContent = "";
    try {
      await request({
        action: "reset-password",
        id: Number($("superResetAccountId").value),
        password: $("superResetPassword").value,
      });
      window.closeSuperPasswordReset();
      showSuccessToast("Password reset.");
      await loadSuperAdminOverview();
    } catch (error) {
      $("superResetError").textContent = error.message || "Password reset failed.";
    }
  });
  $("superAccountsTable").addEventListener("click", (event) => {
    const button = event.target.closest("[data-super-action]");
    if (!button) return;
    if (button.dataset.superAction === "toggle") {
      window.toggleSuperAdminAccount(Number(button.dataset.accountId), button.dataset.active === "true");
    } else if (button.dataset.superAction === "approve" || button.dataset.superAction === "reject") {
      window.setSuperAdminApproval(Number(button.dataset.accountId), button.dataset.superAction === "approve" ? "approved" : "rejected");
    } else if (button.dataset.superAction === "reset") {
      window.resetSuperAdminPassword(Number(button.dataset.accountId), button.dataset.accountName);
    } else if (button.dataset.superAction === "unlock") {
      window.unlockSuperAdminAccount(Number(button.dataset.accountId), button.dataset.accountName);
    } else if (button.dataset.superAction === "training") {
      window.openTraineeTraining(Number(button.dataset.accountId));
    } else if (button.dataset.superAction === "sessions") {
      showSessions(Number(button.dataset.accountId), button.dataset.accountName);
    } else if (button.dataset.superAction === "reset2fa") {
      const name = button.dataset.accountName;
      if (!window.confirm(`Remove the authenticator app of ${name}? Use this when they lost or changed their phone. They will get sign-in codes by email until they set it up again.`)) return;
      request({ action: "reset-2fa", id: Number(button.dataset.accountId) })
        .then((r) => { showSuccessToast(r.message || "Authenticator removed."); loadSuperAdminAccounts(); })
        .catch(showError);
    }
  });
  $("superAccountsTable").addEventListener("change", (event) => {
    const select = event.target.closest("[data-role-select]");
    if (select) window.updateSuperAdminRole(Number(select.dataset.accountId), select.value);
  });
  $("superAccountSearch").addEventListener("input", renderAccounts);
  if ($("superAccountFilter")) $("superAccountFilter").addEventListener("change", renderAccounts);
  if ($("superAccountSummary")) $("superAccountSummary").addEventListener("click", (event) => {
    const chip = event.target.closest("[data-account-filter]");
    if (!chip) return;
    const select = $("superAccountFilter");
    select.value = select.value === chip.dataset.accountFilter ? "all" : chip.dataset.accountFilter; // click again to show all
    renderAccounts();
  });
  // Keep "Online now" fresh while the Accounts & Roles page is open.
  setInterval(() => {
    const page = $("super-accounts");
    if (page && page.offsetParent !== null && document.visibilityState === "visible") loadSuperAdminAccounts();
  }, 60000);
  $("superAuditSearch").addEventListener("input", () => {
    window.clearTimeout(auditTimer);
    auditTimer = window.setTimeout(loadSuperAdminAudit, 250);
  });
  document.querySelector("[data-super-nav]").addEventListener("click", (event) => {
    const button = event.target.closest("[data-module]");
    if (!button) return;
    if (button.dataset.module === "super-accounts") loadSuperAdminAccounts();
    if (button.dataset.module === "super-accounts") loadAcceptedApplications();
    if (button.dataset.module === "super-audit") loadSuperAdminAudit();
    if (button.dataset.module === "super-overview") loadSuperAdminOverview();
    if (button.dataset.module === "super-announcements") loadSuperAnnouncements();
  });
})();
