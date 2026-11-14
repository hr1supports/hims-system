// simple wrappers to keep the code DRY
function read(key) {
  return JSON.parse(localStorage.getItem(key) || "[]");
}

function write(key, value) {
  localStorage.setItem(key, JSON.stringify(value));
}

localStorage.removeItem("schedules");

const apiUrl = location.port === "5500"
  ? `${location.protocol}//${location.hostname}:8000/backend/api.php`
  : "backend/api.php";
const apiUsesCredentials = apiUrl.startsWith("http");

async function apiRequest(resource, options = {}) {
  const query = new URLSearchParams({ resource, ...(options.query || {}) });
  const requestOptions = { ...options };
  delete requestOptions.query;
  let response;
  try {
    response = await fetch(`${apiUrl}?${query.toString()}`, {
      credentials: apiUsesCredentials ? "include" : "same-origin",
      headers: { "Content-Type": "application/json", ...(requestOptions.headers || {}) },
      ...requestOptions,
    });
  } catch (error) {
    if (location.port === "5500") {
      throw new Error(`Cannot reach the PHP API at ${location.protocol}//${location.hostname}:8000. Run START HIMS.bat, then use the page opened at http://127.0.0.1:8000/.`);
    }
    throw error;
  }

  const responseText = await response.text();
  let result;
  try {
    result = responseText.trim() ? JSON.parse(responseText) : null;
  } catch (error) {
    result = null;
  }
  if (!result) {
    if (location.port === "5500") {
      throw new Error(`The PHP API at ${location.protocol}//${location.hostname}:8000 returned an invalid response. Check the PHP server output.`);
    }
    throw new Error(`The PHP API returned an empty or non-JSON response (HTTP ${response.status}). Check the PHP server output.`);
  }
  if (!response.ok) throw new Error(result.error || "API request failed.");
  return result;
}

function syncToDatabase(kind, record) {
  apiRequest("sync", {
    method: "POST",
    body: JSON.stringify({ kind, ...record }),
  }).catch(() => undefined);
}

async function loadSharedRecords() {
  const sources = [
    ["attendance", "attendance"],
    ["requests", "overtime"],
    ["leaves", "leave"],
    ["claims", "claim"],
    ["restdays", "restday"],
  ];
  await Promise.all(sources.map(async ([storageKey, kind]) => {
    try {
      const result = await apiRequest("sync", { method: "GET", query: { kind } });
      write(storageKey, result.records || []);
    } catch (error) {
      return undefined;
    }
  }));
}

async function restoreDatabaseSession() {
  const currentUser = JSON.parse(localStorage.getItem("currentUser") || "null");
  if (!currentUser) return false;
  try {
    const session = await apiRequest("me");
    if (session.user.email !== currentUser.email) {
      localStorage.removeItem("currentUser");
      return false;
    }
    localStorage.setItem("currentUser", JSON.stringify(session.user));
    return true;
  } catch (error) {
    localStorage.removeItem("currentUser");
    return false;
  }
}

function ensureUsers() {
  ["users", "employeeAccounts", "patientAccounts"].forEach((key) => localStorage.removeItem(key));
}

function showLoginMessage(message, type = "error") {
  const target = document.getElementById("loginMessage");
  target.textContent = message;
  target.className = `login-message ${type}`;
}

function showSuccessToast(message) {
  const container = document.getElementById("toastContainer");
  if (!container) return;
  const toast = document.createElement("div");
  toast.className = "success-toast";
  toast.innerHTML = `<span class="toast-check">&#10003;</span><span>${escapeHtml(message)}</span>`;
  container.appendChild(toast);
  window.setTimeout(() => toast.remove(), 3000);
}

function showWarningToast(message) {
  if (/HR3 access required/i.test(String(message && message.message ? message.message : message))) return;
  const container = document.getElementById("toastContainer");
  if (!container) return;
  const toast = document.createElement("div");
  toast.className = "success-toast warning-toast";
  toast.innerHTML = `<span class="toast-check">!</span><span>${escapeHtml(message)}</span>`;
  container.appendChild(toast);
  window.setTimeout(() => toast.remove(), 4200);
}

function validateRequiredFields(fields) {
  let valid = true;
  fields.forEach(({ id, label }) => {
    const input = document.getElementById(id);
    const oldWarning = input && input.parentElement.querySelector(`.field-warning[data-for="${id}"]`);
    if (oldWarning) oldWarning.remove();
    if (!input || !input.value.trim()) {
      valid = false;
      if (input) {
        const warning = document.createElement("small");
        warning.className = "field-warning";
        warning.dataset.for = id;
        warning.textContent = `${label} is required.`;
        input.insertAdjacentElement("afterend", warning);
      }
    }
  });
  if (!valid) showWarningToast("Please complete the required fields.");
  return valid;
}

function applyTheme(theme) {
  const isDark = theme === "dark";
  document.body.classList.toggle("dark-mode", isDark);
  const themeToggle = document.getElementById("themeToggle");
  if (themeToggle) {
    themeToggle.textContent = isDark ? "Light mode" : "Dark mode";
    themeToggle.setAttribute("aria-label", isDark ? "Enable light mode" : "Enable dark mode");
    themeToggle.setAttribute("aria-pressed", String(isDark));
  }
}

function toggleTheme() {
  const nextTheme = document.body.classList.contains("dark-mode") ? "light" : "dark";
  localStorage.setItem("theme", nextTheme);
  applyTheme(nextTheme);
  showSuccessToast(`${nextTheme === "dark" ? "Dark mode" : "Light mode"} enabled SUCCESSFULLY.`);
}

function addNotification(message) {
  const notifications = read("notifications");
  notifications.unshift({ message, date: new Date().toLocaleString(), read: false });
  write("notifications", notifications.slice(0, 300));
  renderNotifications();
  showSuccessToast(`${message} SUCCESSFULLY.`);
}

let showArchivedNotifications = false;

function renderNotifications() {
  const all = read("notifications");
  const notifications = all.filter((n) => !!n.archived === showArchivedNotifications);
  const list = document.getElementById("notificationList");
  const badge = document.getElementById("notificationBadge");
  if (!list || !badge) return;

  const unreadCount = all.filter((notification) => !notification.read && !notification.archived).length;
  badge.textContent = unreadCount;
  badge.hidden = unreadCount === 0;
  list.innerHTML = notifications.length
    ? notifications.map((notification) => `
        <div class="notification-item${notification.read ? "" : " unread"}">
          <strong>${escapeHtml(notification.message)}</strong>
          <small>${escapeHtml(notification.date)}</small>
        </div>`).join("")
    : `<p class="empty-notifications">${showArchivedNotifications ? "No archived notifications." : "No notifications yet."}</p>`;
  const toggle = document.getElementById("notifArchivedToggle");
  const archiveAll = document.getElementById("notifArchiveAll");
  if (toggle) toggle.textContent = showArchivedNotifications ? "Show current" : "Show archived";
  if (archiveAll) archiveAll.hidden = showArchivedNotifications;
}

function escapeHtml(value) {
  return String(value).replace(/[&<>'"]/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    "'": "&#39;",
    '"': "&quot;",
  })[character]);
}

function toggleNotifications() {
  const panel = document.getElementById("notificationPanel");
  const button = document.getElementById("notificationButton");
  const isOpen = !panel.hidden;
  panel.hidden = isOpen;
  button.setAttribute("aria-expanded", String(!isOpen));
  if (!isOpen) {
    const notifications = read("notifications").map((notification) => ({ ...notification, read: true }));
    write("notifications", notifications);
    renderNotifications();
  }
}

// Notifications are never deleted: "Archive all" hides them, and "Show archived" brings them back into view.
function archiveNotifications() {
  write("notifications", read("notifications").map((n) => ({ ...n, archived: true, read: true })));
  renderNotifications();
}

function toggleArchivedNotifications() {
  showArchivedNotifications = !showArchivedNotifications;
  renderNotifications();
}

const globalSearchSections = [
  { key: "claims", label: "Claims and Reimbursement", module: "claims" },
];

function searchGlobalRecords(query) {
  const results = [];
  const normalizedQuery = query.toLowerCase();
  const modules = [
    { label: "Search Employee", module: "search" },
    ...globalSearchSections,
  ];

  modules.forEach((item) => {
    if (item.label.toLowerCase().includes(normalizedQuery)) {
      results.push({ label: item.label, detail: "Open module", module: item.module });
    }
  });

  globalSearchSections.forEach((section) => {
    read(section.key).forEach((record) => {
      const values = Object.values(record).join(" ").toLowerCase();
      if (values.includes(normalizedQuery)) {
        const employee = record.name || record.id || "Record";
        const detail = Object.entries(record)
          .filter(([key]) => key !== "receipt")
          .slice(0, 3)
          .map(([key, value]) => `${key}: ${value}`)
          .join(" | ");
        results.push({ label: `${section.label}: ${employee}`, detail, module: section.module });
      }
    });
  });
  return results.slice(0, 20);
}

function renderGlobalSearch() {
  const input = document.getElementById("globalSearch");
  const resultsPanel = document.getElementById("globalSearchResults");
  const query = input.value.trim();
  if (!query) {
    resultsPanel.hidden = true;
    resultsPanel.innerHTML = "";
    return;
  }

  const results = searchGlobalRecords(query);
  resultsPanel.innerHTML = results.length
    ? results.map((result) => `
        <button class="global-search-result" type="button" data-module="${result.module}">
          <strong>${escapeHtml(result.label)}</strong>
          <small>${escapeHtml(result.detail)}</small>
        </button>`).join("")
    : '<p class="global-search-empty">No matching records or modules.</p>';
  resultsPanel.hidden = false;
}

function openGlobalResult(moduleId) {
  showModule(moduleId);
  document.getElementById("globalSearchResults").hidden = true;
  document.getElementById("globalSearch").blur();
}

function openPanel(panelId) {
  ["supportPanel", "profilePanel"].forEach((id) => {
    if (id !== panelId) closePanel(id);
  });
  const panel = document.getElementById(panelId);
  if (panelId === "profilePanel") {
    const currentUser = JSON.parse(localStorage.getItem("currentUser") || "null");
    if (currentUser && currentUser.email) {
      document.getElementById("profileEmail").textContent = currentUser.email;
    }
    document.getElementById("profileSession").textContent = `Active since ${new Date().toLocaleTimeString()}`;
  }
  if (panel) panel.hidden = false;
  document.body.classList.add("modal-open");
}

function closePanel(panelId) {
  const panel = document.getElementById(panelId);
  if (panel) panel.hidden = true;
  if (panel && panel.hidden) document.body.classList.remove("modal-open");
}

function closeAllPanels() {
  document.querySelectorAll(".modal-backdrop").forEach((panel) => {
    panel.hidden = true;
  });
  document.body.classList.remove("modal-open");
}

function submitSupportRequest(event) {
  event.preventDefault();
  const topic = document.getElementById("supportTopic").value;
  const details = document.getElementById("supportDetails").value.trim();
  if (!details) {
    document.getElementById("supportDetails").focus();
    return;
  }
  const user = JSON.parse(localStorage.getItem("currentUser") || "null");
  const sender = user ? `Name: ${user.name || "Not provided"}\nEmail: ${user.email || "Not provided"}\n` : "";
  const subject = `HIMS support request: ${topic}`;
  const body = `${sender}${sender ? "\n" : ""}Topic: ${topic}\n\nConcern:\n${details}`;
  window.location.href = `mailto:hr1supports@gmail.com?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  showSuccessToast("Email draft prepared. Send it from your email app to contact support.");
}

function createAiMessage(role, content) {
  const container = document.getElementById("aiChatMessages");
  if (!container) return;
  const message = document.createElement("div");
  message.className = `ai-message ai-message-${role}`;
  message.innerHTML = `<strong>${role === "bot" ? "AI" : "You"}:</strong><span>${escapeHtml(content)}</span>`;
  container.appendChild(message);
  container.scrollTop = container.scrollHeight;
}

function getAiInsight() {
  const attendance = read("attendance");
  const requests = read("requests");
  const leaves = read("leaves");
  const claims = read("claims");
  const restdays = read("restdays");

  const totalEmployees = new Set([
    ...attendance.map((record) => record.id),
    ...leaves.map((record) => record.id),
    ...claims.map((record) => record.id),
    ...restdays.map((record) => record.id),
  ]).size;

  const pendingRequests = requests.filter((request) => (request.status || "Pending").toLowerCase().includes("pending")).length;
  const approvedClaims = claims.filter((claim) => (claim.status || "").toLowerCase() === "approved").length;
  const today = new Date().toLocaleDateString();
  const presentToday = attendance.filter((record) => record.date === today && record.timeIn).length;

  return {
    totalEmployees,
    pendingRequests,
    approvedClaims,
    presentToday,
    attendanceCount: attendance.length,
    leaveCount: leaves.length,
    claimCount: claims.length,
  };
}

function generateAiReply(message) {
  const cleaned = message.trim();
  if (!cleaned) return "Please type a question so I can help with the HR dashboard.";

  const lower = cleaned.toLowerCase();
  const stats = getAiInsight();
  const pick = (items) => items[Math.floor(Math.random() * items.length)];
  const today = new Date();
  const todayText = today.toLocaleDateString(undefined, {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  });

  if (/(hi|hello|hey|good morning|good afternoon|good evening|good night)/.test(lower)) {
    return pick([
      `Hello! I can help with quick HR updates. Right now the dashboard shows ${stats.pendingRequests} pending requests and ${stats.presentToday} logged attendance entries today.`,
      `Hi there! I’m ready to help with attendance, leave, and approvals. The current system is tracking ${stats.totalEmployees} employees.`,
      `Good day! I can summarize the HR activity for you, including requests, leave, and claim status.`,
    ]);
  }

  if (/(who are you|what are you|your name|what is your name)/.test(lower)) {
    return pick([
      "I’m the HR assistant for this dashboard. I can answer quick questions about attendance, leave, claims, and requests.",
      "I’m your built-in HR helper for this system. I can summarize employee activity and answer common HR questions.",
      "I’m the AI assistant for this HR dashboard. I help with requests, attendance, balances, and status checks.",
    ]);
  }

  if (/(are you real|are you ai|fake ai|robot|human)/.test(lower)) {
    return pick([
      "I’m an AI-style assistant built into this dashboard, so yes, I’m a digital helper for HR tasks.",
      "I’m a local assistant in this system, designed to answer quick HR questions and summaries.",
      "I’m not a person, but I can still help with reports, attendance, leave, and approvals.",
    ]);
  }

  if (/(attendance|time in|time out|present|clock|late)/.test(lower)) {
    const completed = read("attendance").filter((record) => record.timeOut).length;
    const open = read("attendance").filter((record) => !record.timeOut).length;
    return pick([
      `Attendance is looking ${open > 0 ? "partially open" : "fully updated"}. There are ${completed} completed entries and ${open} still awaiting time-out.`,
      `I checked the attendance records: ${stats.attendanceCount} entries are in the system, with ${completed} completed and ${open} still open.`,
      `The attendance log currently shows ${completed} completed logs and ${open} records still needing closure.`,
    ]);
  }

  if (/(leave|vacation|sick|absence|time off)/.test(lower)) {
    const balance = computeLeaveBalances();
    const entries = Object.entries(balance);
    if (!entries.length) {
      return pick([
        "No leave records have been entered yet, so there are no balances to review right now.",
        "There are no leave requests on file yet. You can add one from the Leave Management section.",
      ]);
    }
    const summary = entries.map(([id, values]) => `${id}: sick ${values.sick}, vacation ${values.vacation}`).join("; ");
    return pick([
      `Current leave balances: ${summary}.`,
      `Here’s the leave snapshot: ${summary}.`,
      `The current leave status is: ${summary}.`,
    ]);
  }

  if (/(request|approval|pending|approve|reject)/.test(lower)) {
    return pick([
      `There are ${stats.pendingRequests} pending requests in the queue, so approvals may need a quick review.`,
      `${stats.pendingRequests} requests are still pending. The approval list is the best place to check next.`,
      `The request tracker currently shows ${stats.pendingRequests} still waiting for approval.`,
    ]);
  }

  if (/(claim|reimbursement|expense|receipt|finance)/.test(lower)) {
    return pick([
      `There are ${stats.claimCount} claim records in total, with ${stats.approvedClaims} approved so far.`,
      `The claims list is active with ${stats.claimCount} submissions, and ${stats.approvedClaims} of them have already been approved.`,
      `I can see ${stats.claimCount} claim entries. ${stats.approvedClaims} are approved and the rest may still need review.`,
    ]);
  }

  if (/(summary|overview|status|report|insight|update)/.test(lower)) {
    return pick([
      `Quick HR overview: ${stats.presentToday} attendance logs for today, ${stats.leaveCount} leave requests, ${stats.claimCount} claim submissions, and ${stats.pendingRequests} pending requests awaiting review.`,
      `Here is the current snapshot: ${stats.presentToday} present today, ${stats.leaveCount} leave records, and ${stats.pendingRequests} pending approvals.`,
      `Short summary: ${stats.pendingRequests} pending requests, ${stats.claimCount} claims logged, and ${stats.presentToday} attendance entries for today.`,
    ]);
  }

  if (/(help|what can you do|how do i|how to|can you help)/.test(lower)) {
    return pick([
      "I can summarize attendance, review leave balances, check pending requests, inspect claims, and explain the current HR dashboard status.",
      "I’m set up to help with attendance, leave records, claim status, and quick HR summaries.",
      "You can ask me about attendance, leave balances, approvals, and claims, and I’ll give a quick summary based on the dashboard data.",
    ]);
  }

  if (/(login|password|account|profile|logout|sign in|sign out)/.test(lower)) {
    return pick([
      "To access the dashboard, use the HR login page and the correct HR3 account credentials. If needed, you can reset the password from the profile or the login option.",
      "The login screen is the entry point for HR3 access. Use your registered email and password, or use the password reset option if needed.",
    ]);
  }

  if (/(support|issue|problem|bug|error|broken|not working)/.test(lower)) {
    return pick([
      "If the system is not behaving correctly, you can open the Support panel and submit a quick issue report with the relevant details.",
      "For technical concerns, the Support option is the best place to describe the problem and send it to the operations team.",
    ]);
  }

  if (/(what day is it|today|date|time)/.test(lower)) {
    return pick([
      `Today is ${todayText}.`,
      `The current date is ${todayText}.`,
      `Today is ${todayText}, and I’m ready to help with your HR questions.`,
    ]);
  }

  if (/(joke|funny|tell me something|laugh)/.test(lower)) {
    return pick([
      "I was going to tell you a joke about payroll, but it never added up.",
      "Why did the employee bring a ladder to HR? Because the leave request was a little high.",
      "I’m not a comedian, but I do know how to keep attendance records on time.",
    ]);
  }

  if (/(thanks|thank you|appreciate it|good job)/.test(lower)) {
    return pick([
      "You’re welcome. I’m here to make your HR checks faster and easier.",
      "Happy to help. Ask me anything related to attendance, leave, claims, or requests.",
      "Always glad to help. I can keep the HR summary short and useful.",
    ]);
  }

  if (/(bye|goodbye|see you|exit|close)/.test(lower)) {
    return pick([
      "Goodbye! I’ll be here when you need another quick HR update.",
      "See you later. I’m ready for the next attendance or leave check.",
      "Take care. I’ll still be here for your next HR question.",
    ]);
  }

  if (/(show|open|list|module|page)/.test(lower)) {
    return pick([
      "You can open the dashboard modules from the sidebar: attendance, leaves, claims, and requests.",
      "The main modules are Time Attendance, Leave Management, Claims, and HR Requests.",
      "The system includes attendance, leave, claims, and approvals, all available from the sidebar.",
    ]);
  }

  if (/(how many|count|number of)/.test(lower)) {
    return pick([
      `The dashboard currently tracks ${stats.totalEmployees} employees and ${stats.attendanceCount} attendance entries.`,
      `I can count ${stats.pendingRequests} pending requests, ${stats.claimCount} claims, and ${stats.attendanceCount} attendance entries right now.`,
      `Right now there are ${stats.leaveCount} leave records, ${stats.claimCount} claims, and ${stats.pendingRequests} open requests.`,
    ]);
  }

  if (/(search|find|employee|who is)/.test(lower)) {
    return pick([
      "Use the Search Employee module to look up a person by ID or name and review their activity across attendance, leave, and claims.",
      "The Search Employee section is the quickest place to locate a staff member and review their records.",
      "Search Employee is designed for quick lookups by employee ID or name across the dashboard."
    ]);
  }

  if (/(i am bored|boring|stupid|dumb|silly)/.test(lower)) {
    return pick([
      "That’s okay. Even the best HR assistants have a goofy moment. Ask me about attendance, leave, or claims and I’ll help.",
      "No worries. I may be digital, but I’m still here to make HR easier.",
      "I’m not offended. Try a practical question like attendance, leave balances, or requests.",
    ]);
  }

  if (/(color|sky|weather|hello world|test)/.test(lower)) {
    return pick([
      "The sky is blue, but in this dashboard the focus color is green and helpful highlights are used for key actions.",
      "This dashboard is designed with a clean green theme and clear highlights to keep important actions easy to spot.",
      "It’s a test of the system and the assistant is working fine — you can ask about attendance, leave, or claims next.",
    ]);
  }

  return pick([
    "I can help with attendance, leave, claims, and pending approvals. Try asking for a quick summary or one of those modules.",
    "I can give you a fast status update on HR activity. Ask me about attendance, leave balances, requests, or claims.",
    "If you want a quick HR overview, ask about attendance, leave, claims, or pending requests and I’ll give you a concise answer.",
    "I’m here to help with common HR questions, including staffing, leave, attendance, approvals, and claim review.",
    "I can answer simple questions, short summaries, and a few playful ones too — but I’m best with HR tasks and dashboard updates.",
  ]);
}

function toggleAiChat() {
  const widget = document.getElementById("aiChatWidget");
  if (!widget) return;
  widget.hidden = !widget.hidden;
}

function sendAiMessage(event) {
  event.preventDefault();
  const input = document.getElementById("aiChatInput");
  const text = input.value.trim();
  if (!text) return;

  createAiMessage("user", text);
  const reply = generateAiReply(text);
  window.setTimeout(() => {
    createAiMessage("bot", reply);
  }, 250);
  input.value = "";
}

function csvCell(value) {
  return `"${String(value ?? "").replace(/"/g, '""')}"`;
}

function reportValue(value, column) {
  if (value === null || value === undefined || value === "") return "";
  if (column.toLowerCase().includes("date")) {
    return `'${value}`;
  }
  return value;
}

function exportReports() {
  const sections = [
    ["Attendance", read("attendance")],
    ["Overtime Requests", read("requests")],
    ["Leave Requests", read("leaves")],
    ["Claims", read("claims")],
    ["Rest Days", read("restdays")],
  ];
  const rows = [["HR3 SYSTEM REPORT", "", "", "", "", ""]];
  sections.forEach(([name, records]) => {
    rows.push([], [name]);
    if (!records.length) {
      rows.push(["No records"]);
      return;
    }
    const columns = [...new Set(records.flatMap((record) => Object.keys(record)))];
    rows.push(columns);
    records.forEach((record) => {
      rows.push(columns.map((column) => reportValue(record[column], column)));
    });
  });

  const csv = rows.map((row) => row.map(csvCell).join(",")).join("\r\n");
  const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
  const link = document.createElement("a");
  link.href = url;
  link.download = `hr3-system-report-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  URL.revokeObjectURL(url);
  addNotification("System report downloaded and updated with the latest records.");
}

async function login() {
  ensureUsers();
  const email = document.getElementById("email").value.trim().toLowerCase();
  const pass = document.getElementById("password").value;

  try {
    const result = await apiRequest("login", {
      method: "POST",
      body: JSON.stringify({ email, password: pass }),
    });
    // The password was right: a 6-digit code is needed next (see hims-security.js).
    if (result.otp_required) {
      document.getElementById("password").value = "";
      if (window.HimsSecurity) {
        window.HimsSecurity.showCodeStep(result, completeLogin);
      } else {
        showLoginMessage("Sign-in needs a verification code, but the security script did not load. Refresh the page.");
      }
      return;
    }
    await completeLogin(result.user);
  } catch (error) {
    showLoginMessage(error.message || "Sign-in failed.");
  }
}

// Opens the right dashboard for a signed-in user (after the password and the sign-in code).
async function completeLogin(user) {
  try {
    const isSuperAdmin = user.role === "SUPER_ADMIN";
    const isEmployee = user.role === "EMPLOYEE";
    const isAdmin = ["HR1", "HR3", "ADMIN", "HR_STAFF", "HR_OFFICER"].includes(user.role);
    const isTrainee = user.role === "TRAINEE";
    if (!isSuperAdmin && !isEmployee && !isTrainee && !isAdmin) {
      await apiRequest("logout", { method: "POST" });
      showLoginMessage("This account has no portal access. Contact a Super Admin.");
      return;
    }
    document.getElementById("password").value = "";
    // Employee and Trainee portals are separate pages: they show the "signed in" popup when they open.
    if (isEmployee || isTrainee) {
      try { sessionStorage.setItem("himsLoginToast", "1"); } catch (e) { /* storage blocked: no popup */ }
    }
    if (isEmployee) {
      localStorage.removeItem("activePortalUser");
      window.location.href = "employee_dashboard.html";
      return;
    }
    if (isTrainee) {
      window.location.href = "trainee_dashboard.html";
      return;
    }
    localStorage.setItem("currentUser", JSON.stringify(user));
    showAuthenticatedDashboard(user);
    showSuccessToast(isSuperAdmin ? "Super Admin login completed." : "Login completed.");
  } catch (error) {
    showLoginMessage(error.message || "Sign-in failed.");
  }
}

function showAuthenticatedDashboard(user) {
  const isSuperAdmin = user.role === "SUPER_ADMIN";
  document.getElementById("loginPage").style.display = "none";
  document.getElementById("dashboard").style.display = "block";
  document.querySelector("[data-admin-nav]").hidden = isSuperAdmin;
  document.querySelector("[data-super-nav]").hidden = !isSuperAdmin;
  document.querySelectorAll("[data-admin-only]").forEach((element) => { element.hidden = isSuperAdmin; });
  document.getElementById("workspaceTitle").textContent = isSuperAdmin ? "SYSTEM ADMINISTRATION" : "ADMIN MANAGEMENT";
  document.querySelector("#workspaceWelcome h2").textContent = isSuperAdmin ? `Welcome, ${user.name || "Super Admin"}` : "Welcome back, Administrator";
  document.querySelector("#workspaceWelcome p").textContent = isSuperAdmin
    ? "Manage HIMS access, review security activity, and monitor account approvals."
    : "Review vacancies and applications, then coordinate accepted-candidate onboarding.";
  document.querySelector(".user-meta strong").textContent = user.name || (isSuperAdmin ? "Super Admin" : "Administrator");
  document.querySelector(".user-meta span").textContent = isSuperAdmin ? "Super Administrator" : "System Administrator";
  document.querySelector("#profileButton .user-avatar").textContent = isSuperAdmin ? "SA" : "AD";
  document.querySelector(".profile-summary span").textContent = isSuperAdmin ? "HIMS Super Administrator" : "HIMS System Administrator";
  document.querySelector(".profile-details strong").textContent = isSuperAdmin ? "Super Admin" : "HIMS Administrator";
  showModule(isSuperAdmin ? "super-overview" : "dashboard-panel");
  if (isSuperAdmin && typeof window.loadSuperAdminOverview === "function") window.loadSuperAdminOverview();
}

function forgotPassword() {
  const currentUser = JSON.parse(localStorage.getItem("currentUser") || "null");
  if (!currentUser) {
    showLoginMessage("Contact a Super Admin to reset your password.");
    return;
  }
  const current = prompt("Enter your current password.");
  if (current === null) return;
  const next = prompt("Enter a new password of at least 8 characters with a letter and a number.");
  if (next === null) return;
  apiRequest("change-password", {
    method: "POST",
    body: JSON.stringify({ current, new: next }),
  }).then(() => showSuccessToast("Password updated.")).catch(showWarningToast);
}

function showModule(id) {
  const currentUser = JSON.parse(localStorage.getItem("currentUser") || "null");
  const isSuperAdmin = currentUser && currentUser.role === "SUPER_ADMIN";
  const superModules = ["super-overview", "super-announcements", "super-accounts", "super-audit", "super-departments", "super-settings", "filex"];
  if ((isSuperAdmin && !superModules.includes(id)) || (!isSuperAdmin && id.startsWith("super-"))) return;

  document.querySelectorAll(".module").forEach((m) => (m.style.display = "none"));
  document.querySelectorAll(".nav-item").forEach((button) => {
    button.classList.toggle("active", button.dataset.module === id);
  });

  const welcome = document.querySelector(".welcome-panel");
  if (welcome && !["dashboard-panel", "super-overview"].includes(id)) {
    welcome.style.display = "none";
  } else if (welcome) {
    welcome.style.display = "block";
  }

  const target = document.getElementById(id);
  if (target) {
    target.style.display = "block";
  }

  if (id === "super-announcements" && window.loadSuperAnnouncements) window.loadSuperAnnouncements();
  if (id === "dashboard-panel") loadAnnouncementBox("adminAnnouncements", "adminAnnouncementList");
  if (id === "claims") showClaims();

  if (id === 'attendance') {
    generateCalendar(currentMonth, currentYear);
  }
}

function timeIn() {
  if (!validateRequiredFields([
    { id: "empID", label: "Employee ID" },
    { id: "empName", label: "Employee Name" },
  ])) return;
  const id = document.getElementById("empID").value;
  const name = document.getElementById("empName").value;
  const data = read("attendance");

  data.push({
    id,
    name,
    date: new Date().toLocaleDateString(),
    timeIn: new Date().toLocaleTimeString(),
    timeOut: "",
  });

  write("attendance", data);
  syncToDatabase("attendance", { id, name });
  addNotification(`Time in recorded for ${name || id}.`);
  loadAttendance();
  showTimekeepingSummary();
}

function timeOut() {
  if (!validateRequiredFields([{ id: "empID", label: "Employee ID" }])) return;
  const id = document.getElementById("empID").value;
  const data = read("attendance");
  const record = data.find((r) => r.id === id && r.timeOut === "");

  if (record) {
    record.timeOut = new Date().toLocaleTimeString();
    write("attendance", data);
    syncToDatabase("attendance", { id, name: record.name, timeOut: record.timeOut });
    addNotification(`Time out recorded for ${record.name || record.id}.`);
  }
  loadAttendance();
  showTimekeepingSummary();
}

function loadAttendance() {
  const data = read("attendance");
  let html = "";

  data.forEach((r) => {
    html += `<tr>
      <td>${r.id}</td>
      <td>${r.name}</td>
      <td>${r.date}</td>
      <td>${r.timeIn}</td>
      <td>${r.timeOut}</td>
    </tr>`;
  });

  const table = document.getElementById("attendanceTable");
  if (table) table.innerHTML = html; // Time Attendance page was removed; keep data loading safe
}

function requestOT() {
  if (!validateRequiredFields([
    { id: "otID", label: "Employee ID" },
    { id: "otName", label: "Employee Name" },
    { id: "otHours", label: "Hours" },
  ])) return;
  const req = read("requests");
  req.push({
    id: document.getElementById("otID").value,
    name: document.getElementById("otName").value,
    hours: document.getElementById("otHours").value,
    status: "Pending",
  });
  write("requests", req);
  syncToDatabase("overtime", req[req.length - 1]);
  addNotification(`Overtime request submitted for ${req[req.length - 1].name || req[req.length - 1].id}.`);
  showRequests();
  // clear
  document.getElementById("otID").value = "";
  document.getElementById("otName").value = "";
  document.getElementById("otHours").value = "";
}

function showRequests() {
  const req = read("requests");
  document.getElementById("requestList").innerHTML = `
    <div class="request-table-wrap">
      <table class="request-table">
        <thead>
          <tr><th>Source</th><th>Employee</th><th>Hours</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>${req.length ? req.map((request, index) => `
          <tr>
            <td><span class="activity-tag">${escapeHtml(request.source || "HR Staff (HIMS)")}</span></td>
            <td><strong>${escapeHtml(request.name || "Unnamed employee")}</strong><small>${escapeHtml(request.id || "No employee ID")}</small></td>
            <td>${escapeHtml(request.hours || "-")}</td>
            <td><span class="request-status">${escapeHtml(request.status || "Pending")}</span></td>
            <td><button type="button" onclick="approve(${index})">Approve</button></td>
          </tr>`).join("") : '<tr><td colspan="5" class="table-empty">No overtime waiting for approval.</td></tr>'}</tbody>
      </table>
    </div>`;
}

function loadHRRequests() {
  showRequests();
  addNotification("HR requests loaded and updated.");
}

function approve(i) {
  const req = read("requests");
  req[i].status = "Approved";
  write("requests", req);
  addNotification(`Overtime request for ${req[i].name || req[i].id} was approved.`);
  showRequests();
}

function submitLeave() {
  if (!validateRequiredFields([
    { id: "leaveID", label: "Employee ID" },
    { id: "leaveName", label: "Employee Name" },
    { id: "leaveStart", label: "Start date" },
    { id: "leaveEnd", label: "End date" },
  ])) return;
  const leaves = read("leaves");
  leaves.push({
    id: document.getElementById("leaveID").value,
    name: document.getElementById("leaveName")
      ? document.getElementById("leaveName").value
      : "",
    type: document.getElementById("leaveType").value,
    start: document.getElementById("leaveStart").value,
    end: document.getElementById("leaveEnd").value,
  });
  write("leaves", leaves);
  syncToDatabase("leave", leaves[leaves.length - 1]);
  addNotification(`Leave request submitted for ${leaves[leaves.length - 1].name || leaves[leaves.length - 1].id}.`);
  showLeaves();
  showLeaveBalances();
  generateCalendar(currentMonth, currentYear);
}

function showLeaves() {
  const leaves = read("leaves");
  let html = "";
  leaves.forEach((l) => {
    html += `<p>${l.id}${l.name ? ' (' + l.name + ')' : ''} | ${l.type} | ${l.start} to ${l.end}</p>`;
  });
  document.getElementById("leaveList").innerHTML = html;
}

function submitClaim() {
  if (!validateRequiredFields([
    { id: "claimID", label: "Employee ID" },
    { id: "claimName", label: "Employee Name" },
    { id: "claimAmount", label: "Amount" },
  ])) return;
  const claims = read("claims");
  const id = document.getElementById("claimID").value;
  const name = document.getElementById("claimName").value;
  const amount = document.getElementById("claimAmount").value;
  const fileInput = document.getElementById("claimReceipt");

  // helper to actually save the claim once we have receipt data (or null)
  const save = (receiptData) => {
    claims.push({
      id,
      name,
      amount,
      status: "Pending",
      date: new Date().toLocaleString(),
      receipt: receiptData, // base64 dataURL or null
    });
    write("claims", claims);
    syncToDatabase("claim", { id, name, amount });
    addNotification(`Claim submitted for ${name || id}.`);
    showClaims();

    // clear inputs
    document.getElementById("claimID").value = "";
    document.getElementById("claimName").value = "";
    document.getElementById("claimAmount").value = "";
    if (fileInput) fileInput.value = "";
  };

  if (fileInput && fileInput.files && fileInput.files[0]) {
    const reader = new FileReader();
    reader.onload = (e) => {
      save(e.target.result);
    };
    reader.readAsDataURL(fileInput.files[0]);
  } else {
    save(null);
  }
}

function showClaims() {
  const list = document.getElementById("claimList");
  if (!list) return;
  const claims = read("claims");
  if (!claims || claims.length === 0) {
    list.innerHTML = '<p class="table-empty">No claims recorded yet.</p>';
    return;
  }
  const user = JSON.parse(localStorage.getItem("currentUser") || "null") || {};
  const canDecide = user.role !== "HR_STAFF"; // HR Staff encode; an HR Officer or Admin decides
  const tone = { Pending: "pending", Approved: "approved", Rejected: "rejected" };
  const peso = (n) => "₱" + (Number(n) || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  // Only links HIMS made itself (server copy) or an image/PDF picked on this computer are shown.
  const safeReceipt = (r) => typeof r === "string" && (/^backend\/api\.php\?resource=shared-records&receipt=[A-Za-z0-9_-]+$/.test(r) || /^data:(image\/(png|jpe?g|webp)|application\/pdf);base64,/.test(r));
  const rows = claims.map((c, idx) => ({ c, idx })).reverse(); // newest first
  list.innerHTML = `<div class="request-table-wrap"><table class="request-table">
    <thead><tr><th>Employee</th><th>Amount</th><th>Filed</th><th>Receipt</th><th>Status</th><th></th></tr></thead>
    <tbody>${rows.map(({ c, idx }) => `<tr>
      <td><strong>${escapeHtml(c.name || "Unnamed employee")}</strong><small>${escapeHtml(c.id || "")}</small></td>
      <td>${peso(c.amount)}</td>
      <td>${escapeHtml(c.date || "-")}${c._createdBy ? `<small>by ${escapeHtml(c._createdBy)}</small>` : ""}</td>
      <td>${safeReceipt(c.receipt) ? `<a class="hx-link" href="${escapeHtml(c.receipt)}" target="_blank" rel="noopener">View receipt</a>` : '<span class="muted-text">None</span>'}</td>
      <td><span class="claim-status is-${tone[c.status] || "pending"}">${escapeHtml(c.status || "Pending")}</span>${c._updatedBy && c.status !== "Pending" ? `<small>by ${escapeHtml(c._updatedBy)}</small>` : ""}</td>
      <td class="claim-actions">${(c.status || "Pending") === "Pending" && canDecide
        ? `<button type="button" onclick="updateClaimStatus(${idx}, 'Approved')">Approve</button><button type="button" class="secondary-action" onclick="updateClaimStatus(${idx}, 'Rejected')">Reject</button>`
        : (c.status || "Pending") === "Pending" ? '<span class="muted-text">Waiting for HR Officer</span>' : ""}</td>
    </tr>`).join("")}</tbody></table></div>`;
}

function updateClaimStatus(index, newStatus) {
  const claims = read("claims");
  if (claims[index]) {
    claims[index].status = newStatus;
    write("claims", claims);
    addNotification(`Claim for ${claims[index].name || claims[index].id} was ${newStatus.toLowerCase()}.`);
    showClaims();
  }
}

function searchEmployee() {
  if (!validateRequiredFields([{ id: "searchInput", label: "Employee ID or Name" }])) return;
  const key = document.getElementById("searchInput").value.toLowerCase();
  const records = [
    ...read("attendance").map((record) => ({
      section: "Attendance",
      employee: record.name || record.id,
      date: record.date,
      details: `Time in: ${record.timeIn || "-"} | Time out: ${record.timeOut || "-"}`,
      status: record.timeOut ? "Completed" : "Open",
      id: record.id,
      name: record.name,
    })),
    ...read("requests").map((record) => ({
      section: "Overtime",
      employee: record.name || record.id,
      date: "-",
      details: `${record.hours || "-"} hour(s) requested`,
      status: record.status || "Pending",
      id: record.id,
      name: record.name,
    })),
    ...read("leaves").map((record) => ({
      section: "Leave",
      employee: record.name || record.id,
      date: `${record.start || "-"} to ${record.end || "-"}`,
      details: record.type || "Leave request",
      status: "Submitted",
      id: record.id,
      name: record.name,
    })),
    ...read("claims").map((record) => ({
      section: "Claim",
      employee: record.name || record.id,
      date: record.date || "-",
      details: `Amount: ${record.amount || "-"}`,
      status: record.status || "Pending",
      id: record.id,
      name: record.name,
    })),
    ...read("restdays").map((record) => ({
      section: "Rest day",
      employee: record.name || record.id,
      date: record.date,
      details: "Scheduled rest day",
      status: "Recorded",
      id: record.id,
      name: record.name,
    })),
  ];
  const result = records.filter((record) =>
    `${record.id || ""} ${record.name || ""}`.toLowerCase().includes(key)
  );
  const resultElement = document.getElementById("searchResult");
  if (!result.length) {
    resultElement.innerHTML = '<p class="search-empty">No records found for this employee.</p>';
    showSuccessToast("Employee search completed SUCCESSFULLY, but no records were found.");
    return;
  }

  resultElement.innerHTML = `
    <h4>Employee Activity</h4>
    <div class="search-table-wrap">
      <table class="employee-activity-table">
        <thead><tr><th>Section</th><th>Employee</th><th>Date</th><th>Details</th><th>Status</th></tr></thead>
        <tbody>${result.map((record) => `
          <tr>
            <td><span class="activity-tag">${escapeHtml(record.section)}</span></td>
            <td>${escapeHtml(record.employee)}</td>
            <td>${escapeHtml(record.date)}</td>
            <td>${escapeHtml(record.details)}</td>
            <td>${escapeHtml(record.status)}</td>
          </tr>`).join("")}</tbody>
      </table>
    </div>`;
  showSuccessToast("Employee search completed SUCCESSFULLY.");
}

function handleEnterKey(event) {
  if (event.key !== "Enter" || event.target.matches("textarea, [type='file'], [readonly]")) return;

  const target = event.target;
  if (target.id === "globalSearch") {
    event.preventDefault();
    renderGlobalSearch();
    return;
  }
  const actions = {
    loginPage: login,
    search: searchEmployee,
    attendance: timeIn,
    timesheet: requestOT,
    leave: submitLeave,
    claims: submitClaim,
  };

  if (target.id === "calSearchName" || target.id === "calSearchHoliday") return;
  if (target.id.startsWith("rest")) {
    event.preventDefault();
    addRestDay();
    return;
  }

  const section = target.closest("#loginPage, .module");
  const action = section && actions[section.id];
  if (action) {
    event.preventDefault();
    action();
  }
}

// initialise everything when the page is ready
async function init() {
  ensureUsers();
  applyTheme(localStorage.getItem("theme") || "light");
  document.querySelectorAll(".toggle-password").forEach((button) => {
    button.addEventListener("click", () => {
      const input = document.getElementById(button.dataset.target);
      if (!input) return;
      const isPassword = input.type === "password";
      input.type = isPassword ? "text" : "password";
      button.setAttribute("aria-label", isPassword ? "Hide password" : "Show password");
      button.title = isPassword ? "Hide password" : "Show password";
      button.setAttribute("aria-pressed", String(isPassword));
      button.classList.toggle("is-visible", isPassword);
    });
  });

  document.getElementById("themeToggle").addEventListener("click", toggleTheme);
  document.getElementById("notificationButton").addEventListener("click", toggleNotifications);
  document.getElementById("globalSearch").addEventListener("input", renderGlobalSearch);
  document.getElementById("globalSearchResults").addEventListener("click", (event) => {
    const result = event.target.closest(".global-search-result");
    if (result) openGlobalResult(result.dataset.module);
  });
  document.addEventListener("click", (event) => {
    if (!event.target.closest(".search-field")) {
      document.getElementById("globalSearchResults").hidden = true;
    }
  });
  document.getElementById("supportButton").addEventListener("click", () => openPanel("supportPanel"));
  document.getElementById("profileButton").addEventListener("click", () => openPanel("profilePanel"));
  const floatingAiButton = document.getElementById("floatingAiButton");
  if (floatingAiButton) {
    floatingAiButton.addEventListener("click", toggleAiChat);
  }
  document.getElementById("aiChatForm").addEventListener("submit", sendAiMessage);
  document.getElementById("aiSuggestions").addEventListener("click", (event) => {
    const button = event.target.closest("button[data-prompt]");
    if (!button) return;
    const input = document.getElementById("aiChatInput");
    input.value = button.dataset.prompt;
    document.getElementById("aiChatForm").dispatchEvent(new Event("submit", { cancelable: true }));
  });
  document.getElementById("supportForm").addEventListener("submit", submitSupportRequest);
  document.querySelectorAll(".modal-backdrop").forEach((panel) => {
    panel.addEventListener("click", (event) => {
      if (event.target === panel) closeAllPanels();
    });
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeAllPanels();
    }
  });
  document.addEventListener("keydown", handleEnterKey);
  if (await restoreDatabaseSession()) {
    const restoredUser = JSON.parse(localStorage.getItem("currentUser") || "null");
    if (restoredUser && restoredUser.role === "HR3") await loadSharedRecords();
  }
  renderNotifications();
  if (!localStorage.getItem('restdays')) write('restdays', []);

  // check login persistence
  const current = localStorage.getItem('currentUser');
  if (current) {
    const user = JSON.parse(current);
    if (["HR1", "HR3", "ADMIN", "HR_STAFF", "HR_OFFICER", "SUPER_ADMIN"].includes(user.role)) showAuthenticatedDashboard(user);
  }

  loadAttendance();
  showLeaves();
  showClaims();
  showRequests();
  showLeaveBalances();
  generateCalendar(currentMonth, currentYear);
}
// calculate and display monthly timekeeping summary
function showTimekeepingSummary() {
  const data = read("attendance");
  const byEmp = {};
  data.forEach((r) => {
    if (r.timeOut) {
      // parse date+time strings into Date objects
      const timeIn = new Date(`${r.date} ${r.timeIn}`);
      const timeOut = new Date(`${r.date} ${r.timeOut}`);
      const hrs = (timeOut - timeIn) / (1000 * 60 * 60);
      const key = `${r.id}|${r.name}`;
      if (!byEmp[key]) {
        byEmp[key] = { id: r.id, name: r.name, days: 0, hours: 0, details: [] };
      }
      byEmp[key].days += 1;
      byEmp[key].hours += hrs;
      byEmp[key].details.push({ date: r.date, hours: hrs.toFixed(2) });
    }
  });

  let html =
    "<table><tr><th>ID</th><th>Name</th><th>Days</th><th>Total Hours</th></tr>";
  Object.values(byEmp).forEach((emp) => {
    html += `<tr><td>${emp.id}</td><td>${emp.name}</td><td>${emp.days}</td><td>${emp.hours.toFixed(
      2
    )}</td></tr>`;
    html += `<tr><td colspan="4"><em>daily:</em> ${emp.details
      .map((d) => `${d.date}(${d.hours})`)
      .join(", ")}</td></tr>`;
  });
  html += "</table>";
  document.getElementById("summaryList").innerHTML = html;
}

// PH holidays for calendar (format YYYY-MM-DD)
const holidays = [
  { date: "2026-01-01", name: "New Year's Day" },
  { date: "2026-02-25", name: "EDSA Revolution Anniversary" },
  { date: "2026-04-09", name: "Araw ng Kagitingan" },
  { date: "2026-04-02", name: "Maundy Thursday" },
  { date: "2026-04-03", name: "Good Friday" },
  { date: "2026-05-01", name: "Labor Day" },
  { date: "2026-06-12", name: "Independence Day" },
  { date: "2026-08-31", name: "National Heroes Day" },
  { date: "2026-11-01", name: "All Saints' Day" },
  { date: "2026-11-30", name: "Bonifacio Day" },
  { date: "2026-12-25", name: "Christmas Day" },
  { date: "2026-12-30", name: "Rizal Day" },
];

let currentMonth = new Date().getMonth();
let currentYear = new Date().getFullYear();
let filterName = "";
let filterHoliday = "";

function logout() {
  apiRequest("logout", { method: "POST" }).catch(() => undefined);
  localStorage.removeItem('currentUser');
  document.getElementById('dashboard').style.display = 'none';
  document.getElementById('loginPage').style.display = '';
  document.getElementById('email').value = '';
  document.getElementById('password').value = '';
}

function addRestDay() {
  if (!validateRequiredFields([
    { id: "restEmpID", label: "Employee ID" },
    { id: "restEmpName", label: "Employee Name" },
    { id: "restDate", label: "Date" },
  ])) return;
  const id = document.getElementById("restEmpID").value;
  const name = document.getElementById("restEmpName").value;
  const date = document.getElementById("restDate").value;
  if (!id || !date) {
    alert("Provide employee ID and date");
    return;
  }
  const rest = read("restdays");
  rest.push({ id, name, date });
  write("restdays", rest);
  syncToDatabase("restday", { id, name, date });
  addNotification(`Rest day added for ${name || id}.`);
  generateCalendar(currentMonth, currentYear);
}

function updateCalendarFilter() {
  filterName = document.getElementById("calSearchName").value.toLowerCase();
  filterHoliday = document.getElementById("calSearchHoliday").value.toLowerCase();
  generateCalendar(currentMonth, currentYear);
}

// calculate leave balances based on stored leaves
function computeLeaveBalances() {
  const leaves = read("leaves");
  const balances = {};
  leaves.forEach((l) => {
    const id = l.id;
    if (!balances[id]) balances[id] = { sick: 5, vacation: 10 };
    // calculate number of days in range (inclusive)
    const start = new Date(l.start);
    const end = new Date(l.end);
    const days = Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1;
    if (l.type.toLowerCase().includes("sick")) balances[id].sick -= days;
    else if (l.type.toLowerCase().includes("vac")) balances[id].vacation -= days;
  });
  return balances;
}

function showLeaveBalances() {
  const bal = computeLeaveBalances();
  let html = "<table><tr><th>ID</th><th>Sick Left</th><th>Vacation Left</th></tr>";
  Object.keys(bal).forEach((id) => {
    html += `<tr><td>${id}</td><td>${bal[id].sick}</td><td>${bal[id].vacation}</td></tr>`;
  });
  html += "</table>";
  document.getElementById("leaveBalance").innerHTML = html;
}

function prevMonth() {
  currentMonth -= 1;
  if (currentMonth < 0) {
    currentMonth = 11;
    currentYear -= 1;
  }
  generateCalendar(currentMonth, currentYear);
}
function nextMonth() {
  currentMonth += 1;
  if (currentMonth > 11) {
    currentMonth = 0;
    currentYear += 1;
  }
  generateCalendar(currentMonth, currentYear);
}

function generateCalendar(month, year) {
  if (!document.getElementById('attendanceCalendar')) return; // calendar page removed
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const monthNames = [
    "January","February","March","April","May","June",
    "July","August","September","October","November","December"
  ];
  let html = '<table><tr>';
  const names = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
  names.forEach((n) => (html += `<th>${n}</th>`));
  html += '</tr><tr>';
  for (let i = 0; i < firstDay; i++) html += '<td></td>';
  for (let d = 1; d <= daysInMonth; d++) {
    const full = `${year}-${String(month + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
    let classes = [];
    let title = '';
    const hol = holidays.find((h) => h.date === full);
    if (hol) {
      classes.push('holiday');
      title = hol.name;
    }
    const restEntries = read("restdays").filter((r) => r.date === full);
    if (restEntries.length) {
      classes.push('rest');
      title += title ? '\n' : '';
      title += restEntries.map((r) => r.id + (r.name ? ` (${r.name})` : '')).join(',');
    }
    // leave entries that cover this day
    const leaveEntries = read("leaves").filter((l) => {
      const s = new Date(l.start);
      const e = new Date(l.end);
      const dDate = new Date(full);
      return dDate >= s && dDate <= e;
    });
    if (leaveEntries.length) {
      classes.push('leave');
      title += title ? '\n' : '';
      title += leaveEntries
        .map((l) => l.id + (l.name ? ` (${l.name})` : '') + ` ${l.type}`)
        .join(',');
    }
    if (filterHoliday && hol && hol.name.toLowerCase().includes(filterHoliday)) classes.push('highlight');
    if (
      filterName &&
      (restEntries.some(
        (r) =>
          r.id.toLowerCase().includes(filterName) ||
          (r.name && r.name.toLowerCase().includes(filterName))
      ) ||
      leaveEntries.some(
        (l) =>
          l.id.toLowerCase().includes(filterName) ||
          (l.name && l.name.toLowerCase().includes(filterName))
      ))
    )
      classes.push('highlight');

    // cell content includes date number and labels
    let cellContent = `<strong>${d}</strong>`;
    if (hol) cellContent += `<br><small>${hol.name}</small>`;
    if (restEntries.length)
      cellContent += `<br><small>${restEntries
        .map((r) => (r.name ? `${r.id} (${r.name})` : r.id))
        .join(',')}</small>`;
    if (leaveEntries.length)
      cellContent += `<br><small>${leaveEntries
        .map((l) =>
          `${l.id}${l.name ? ' (' + l.name + ')' : ''} ${l.type}`
        )
        .join(',')}</small>`;

    html += `<td class="${classes.join(' ')}" title="${title}">${cellContent}</td>`;
    if ((firstDay + d) % 7 === 0 && d !== daysInMonth) html += '</tr><tr>';
  }
  html += '</tr></table>';
  document.getElementById('attendanceCalendar').innerHTML = html;
  document.getElementById('calMonthYear').textContent = `${monthNames[month]} ${year}`;
}
 
window.addEventListener("DOMContentLoaded", init);

// ---------- job openings board on the login page ----------
async function loadJobBoard() {
  const list = document.getElementById("jobsList");
  if (!list) return;
  try {
    const result = await apiRequest("public-vacancies");
    const jobs = result.records || [];
    if (!jobs.length) {
      list.innerHTML = '<p class="jobs-empty">There are no open positions at the moment. Please check back soon.</p>';
      return;
    }
    list.innerHTML = jobs.map((job) => {
      const slots = `${Number(job.slots)} ${Number(job.slots) === 1 ? "slot" : "slots"}`;
      const meta = [job.department, job.type, slots, job.deadline ? "Apply by " + job.deadline : ""].filter(Boolean).map(escapeHtml).join(" &bull; ");
      return `<article class="job-card">
        <h4>${escapeHtml(job.title)}</h4>
        <div class="job-meta">${meta}</div>
        <p>${escapeHtml(job.description || "")}</p>
        ${jobQualsHtml(job)}
        <a class="job-apply" href="careers.html?vacancy=${Number(job.id)}">Apply now</a>
      </article>`;
    }).join("");
  } catch (error) {
    list.innerHTML = '<p class="jobs-empty">Open positions could not be loaded right now.</p>';
  }
}
// Qualifications (one per line) as a short list that opens on click.
function jobQualsHtml(job) {
  const list = String(job.qualifications || "").split(/\n+/).map((q) => q.trim()).filter(Boolean);
  if (!list.length) return "";
  return `<div class="job-quals"><strong>Qualifications Required:</strong><ul>${list.map((q) => `<li>${escapeHtml(q)}</li>`).join("")}</ul></div>`;
}
document.addEventListener("DOMContentLoaded", loadJobBoard);

// ---------- announcements box (Admin dashboard) ----------
async function loadAnnouncementBox(boxId, listId) {
  const box = document.getElementById(boxId);
  const list = document.getElementById(listId);
  if (!box || !list) return;
  try {
    const result = await apiRequest("announcements");
    const rows = (result.records || []).slice(0, 5);
    box.hidden = !rows.length;
    list.innerHTML = rows.map((a) => `<div class="announce-item"><strong>${escapeHtml(a.title)}</strong><small>${escapeHtml(a.created)}${a.author ? " &middot; " + escapeHtml(a.author) : ""}</small>${a.body ? `<p>${escapeHtml(a.body)}</p>` : ""}</div>`).join("");
  } catch (error) {
    box.hidden = true;
  }
}
