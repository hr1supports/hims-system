/*
 * HIMS role-aware assistant
 * ---------------------------------------------------------------------------
 * One assistant for every signed-in account except Trainee. It answers in
 * plain language, gives step-by-step guides for the screens each role can use,
 * and reads live numbers from the database through backend/api.php.
 *
 *  - Admin page (index.html): Super Admin, Admin (HR1/HR3/ADMIN), HR Staff, HR Officer
 *  - Employee page (employee_dashboard.html): Employee
 *
 * Answers trained in "Train AI" still win on the admin page: the Train AI script
 * in index.html wraps window.generateAiReply after this file sets it.
 * Everything here is read-only: the assistant never changes any record.
 */
(function () {
  "use strict";

  const API = "backend/api.php";
  const IS_EMPLOYEE_PAGE = /employee_dashboard/i.test(location.pathname) || !!document.querySelector(".employee-shell");

  // -------------------------------------------------------------------------
  // Who is signed in
  // -------------------------------------------------------------------------
  const ADMIN_ROLES = ["HR1", "HR3", "ADMIN"];
  const ROLE_LABEL = {
    SUPER_ADMIN: "Super Admin", HR1: "Admin", HR3: "Admin", ADMIN: "Admin",
    HR_STAFF: "HR Staff", HR_OFFICER: "HR Officer", EMPLOYEE: "Employee",
  };

  function storedUser() {
    try { return JSON.parse(localStorage.getItem("currentUser") || "null"); } catch (e) { return null; }
  }
  let portalUser = null; // filled on the employee page from the API
  function currentUser() {
    if (IS_EMPLOYEE_PAGE) return portalUser || Object.assign({}, storedUser() || {}, { role: "EMPLOYEE" });
    return storedUser();
  }
  function roleGroup() {
    const r = (currentUser() || {}).role || "";
    if (r === "SUPER_ADMIN") return "SUPER";
    if (ADMIN_ROLES.includes(r)) return "ADMIN";
    if (r === "HR_STAFF") return "STAFF";
    if (r === "HR_OFFICER") return "OFFICER";
    if (r === "EMPLOYEE" || IS_EMPLOYEE_PAGE) return "EMPLOYEE";
    return "GUEST";
  }
  const firstName = () => {
    const name = String((currentUser() || {}).name || "").trim();
    // Built-in accounts like "HR1 Super Administrator" have no real first name.
    if (!name || /\b(admin|administrator)\b/i.test(name) || /^hr\d*\b/i.test(name)) return "";
    return name.split(/\s+/)[0];
  };
  const roleLabel = () => ROLE_LABEL[(currentUser() || {}).role] || (IS_EMPLOYEE_PAGE ? "Employee" : "HR");

  // -------------------------------------------------------------------------
  // Live data (read-only). Loaded when the chat opens, refreshed every 30 s.
  // -------------------------------------------------------------------------
  const D = { loadedAt: 0, loading: null, owner: "" };
  const ownerKey = () => { if (IS_EMPLOYEE_PAGE) return "employee-page"; const u = currentUser() || {}; return `${u.email || ""}|${u.role || ""}`; };

  async function get(resource, query) {
    const qs = new URLSearchParams(Object.assign({ resource }, query || {}));
    const res = await fetch(`${API}?${qs}`, { credentials: "same-origin", headers: { Accept: "application/json" } });
    if (!res.ok) throw new Error(String(res.status));
    return res.json();
  }
  const records = (r) => (r && r.status === "fulfilled" && r.value && Array.isArray(r.value.records) ? r.value.records : null);
  const value = (r) => (r && r.status === "fulfilled" ? r.value : null);
  const local = (key) => { try { const v = JSON.parse(localStorage.getItem(key) || "[]"); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
  const lower = (s) => String(s || "").toLowerCase();
  const isoToday = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; };

  async function loadData(force) {
    const group = roleGroup();
    if (group === "GUEST") return;
    const owner = ownerKey();
    if (owner !== D.owner) { // someone else signed in: forget the previous account's numbers
      Object.keys(D).forEach((k) => { delete D[k]; });
      D.loadedAt = 0; D.loading = null; D.owner = owner; force = true;
    }
    if (!force && Date.now() - D.loadedAt < 30000) return;
    if (D.loading) return D.loading;
    const T = {};
    const job = (group === "EMPLOYEE" ? loadEmployee(T) : loadHr(T, group))
      .catch(() => null)
      .then(() => { if (D.owner === owner) { Object.assign(D, T); D.loadedAt = Date.now(); D.loading = null; } });
    D.loading = job;
    return D.loading;
  }

  async function loadHr(T, group) {
    const calls = [
      get("employees"), get("vacancies"), get("applications"), get("onboarding"),
      get("sync", { kind: "claim" }), get("sync", { kind: "overtime" }), get("sync", { kind: "leave" }), get("sync", { kind: "attendance" }),
      get("settings"),
      group === "SUPER" ? get("super-admin", { view: "overview" }) : Promise.reject(new Error("skip")),
    ];
    const [emp, vac, apps, onb, claims, ot, leave, att, settings, sa] = await Promise.allSettled(calls);
    const today = isoToday();

    const e = records(emp);
    T.employees = e ? { total: e.length, active: e.filter((x) => lower(x.status) === "active").length } : null;

    const v = records(vac);
    T.vacancies = v ? (() => { const live = v.filter((x) => !Number(x.archived)); return {
      open: live.filter((x) => x.status === "Open").length,
      pending: live.filter((x) => x.status === "Pending").length,
      closed: live.filter((x) => x.status === "Closed").length,
      openTitles: live.filter((x) => x.status === "Open").map((x) => x.title).slice(0, 5),
    }; })() : null;

    const a = records(apps);
    T.applications = a ? (() => { const live = a.filter((x) => !Number(x.archived)); const by = {};
      live.forEach((x) => { by[x.status] = (by[x.status] || 0) + 1; }); return { total: live.length, by }; })() : null;

    const o = records(onb);
    T.onboarding = o ? (() => { const live = o.filter((x) => !Number(x.archived) && x.status !== "Completed"); return {
      active: live.length,
      ready: live.filter((x) => Number(x.done) >= Number(x.total) && Number(x.total) > 0).length,
      completed: o.filter((x) => x.status === "Completed").length,
    }; })() : null;

    const c = records(claims);
    T.claims = c ? { total: c.length, pending: c.filter((x) => lower(x.status || "pending") === "pending").length } : null;
    const t = records(ot);
    T.overtime = t ? { total: t.length, pending: t.filter((x) => lower(x.status || "pending") === "pending").length } : null;
    const l = records(leave);
    T.leaves = l ? { total: l.length } : null;
    const at = records(att);
    T.attendance = at ? { today: at.filter((x) => x.date === today).length, open: at.filter((x) => x.date === today && !x.timeOut).length } : null;

    const ess = local("essRequests").filter((x) => !x.archived);
    T.ess = { pending: ess.filter((x) => x.status === "Pending").length, approved: ess.filter((x) => x.status === "Approved").length };
    const iv = local("interviews").filter((x) => !x.archived && x.status === "Scheduled");
    T.interviews = { scheduled: iv.length };

    const s = value(settings);
    T.settings = s && s.settings ? s.settings : null;
    const sv = value(sa);
    T.super = sv && sv.stats ? sv : null;
  }

  async function loadEmployee(T) {
    const [portal, settings, ann, jobs, me] = await Promise.allSettled([
      get("employee-portal"), get("settings"), get("announcements"), get("public-vacancies"), get("me"),
    ]);
    const p = value(portal);
    const m = value(me);
    if (m && m.user) portalUser = m.user;
    T.portal = p || null;
    const s = value(settings);
    T.settings = s && s.settings ? s.settings : null;
    T.announcements = records(ann);
    T.jobs = records(jobs);
    const myId = p && p.profile ? p.profile.id : null;
    T.myEss = myId ? local("essRequests").filter((x) => x.empId === myId) : [];
  }

  // -------------------------------------------------------------------------
  // Small helpers for writing answers
  // -------------------------------------------------------------------------
  const steps = (list) => list.map((s, i) => `${i + 1}. ${s}`).join("\n");
  const bullets = (list) => list.map((s) => `• ${s}`).join("\n");
  const plural = (n, word, many) => `${n} ${n === 1 ? word : many || word + "s"}`;
  const join = (...parts) => parts.filter(Boolean).join("\n\n");
  const peso = (n) => "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const setting = (key, fallback) => (D.settings && D.settings[key] !== undefined && D.settings[key] !== "" ? D.settings[key] : fallback);
  const quotas = () => ({
    "Vacation Leave": Number(setting("leave_vacation", 15)),
    "Sick Leave": Number(setting("leave_sick", 10)),
    "Emergency Leave": Number(setting("leave_emergency", 5)),
  });
  const days = (start, end) => {
    const a = new Date(start), b = new Date(end);
    return isNaN(a) || isNaN(b) ? 0 : Math.round((b - a) / 86400000) + 1;
  };
  function leaveBalance() {
    const p = D.portal; if (!p) return null;
    const q = (p.leaveQuotas && Object.keys(p.leaveQuotas).length) ? p.leaveQuotas : quotas();
    const used = {};
    (p.leaves || []).forEach((l) => { if (lower(l.status) !== "rejected") used[l.type] = (used[l.type] || 0) + days(l.start, l.end); });
    return Object.keys(q).map((type) => ({ type, quota: Number(q[type]), used: used[type] || 0, left: Math.max(0, Number(q[type]) - (used[type] || 0)) }));
  }

  // -------------------------------------------------------------------------
  // Understanding the question
  // -------------------------------------------------------------------------
  // A few Filipino words people often mix in.
  const SYNONYMS = [
    [/\bpaano\b/g, "how"], [/\bsaan\b/g, "where"], [/\bilan\b/g, "how many"], [/\bmagkano\b/g, "how much"],
    [/\bsahod\b/g, "salary"], [/\bsweldo\b/g, "salary"], [/\bbakasyon\b/g, "vacation"], [/\bpasok\b/g, "attendance"],
    [/\bot\b/g, "overtime"], [/\bcoe\b/g, "certificate of employment"], [/\bpw\b/g, "password"], [/\bpass\b/g, "password"],
    [/\bacc\b/g, "account"], [/\bpls\b|\bplease\b|\bpo\b/g, " "],
  ];
  function normalize(text) {
    let t = lower(text).replace(/[’']/g, "").replace(/[^a-z0-9₱%\s-]/g, " ");
    SYNONYMS.forEach(([re, to]) => { t = t.replace(re, to); });
    return ` ${t.replace(/\s+/g, " ").trim()} `;
  }
  const wantsHow = (t) => /\b(how (do|can|to|should|will)|steps?|guide|procedure|where|can i|do i)\b/.test(t);
  const wantsCount = (t) => /\b(how many|count|number of|total|status|pending|summary|overview|stats?)\b/.test(t);

  // Each topic: words that point to it (with weights) and an answer per role group.
  // A role that has no answer gets a polite "who does this" redirect from `other`.
  const TOPICS = [];
  function topic(id, keys, answers, other) { TOPICS.push({ id, keys, answers, other }); }

  // ---------------- General ----------------
  topic("greet", [[/^\s*(hi|hello|hey|hiya|yo|good (morning|afternoon|evening|day)|kumusta|musta|hola)\b/, 3]], {
    ALL: () => {
      const n = firstName();
      return join(`Hi${n ? " " + n : ""}! I'm your ${roleLabel()} assistant.`, summaryLine(), "Ask me how to do something, or what needs your attention today.");
    },
  });
  topic("about", [[/\b(who|what) are you\b|\byour name\b|\bare you (real|an? ai|human|a robot|a bot)\b|\bchatbot\b/, 3]], {
    ALL: () => `I'm the HIMS assistant built into this system. I know the screens your ${roleLabel()} account can use, I can walk you through tasks step by step, and I read live numbers from the database. I can only look things up; I never change records.`,
  });
  topic("thanks", [[/\b(thanks|thank you|salamat|ty|appreciate)\b/, 3]], {
    ALL: () => "You're welcome! Ask me anytime.",
  });
  topic("bye", [[/^\s*(bye|goodbye|see you|later|ok bye)\b/, 3]], {
    ALL: () => "Goodbye! Close this chat with the × button. Your work is saved.",
  });
  topic("datetime", [[/\bwhat (day|date|time) is it\b|\b(date|day) today\b|\btodays date\b|\bcurrent time\b/, 3]], {
    ALL: () => `It's ${new Date().toLocaleDateString(undefined, { weekday: "long", year: "numeric", month: "long", day: "numeric" })}, ${new Date().toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" })}.`,
  });
  topic("joke", [[/\b(joke|funny|make me laugh)\b/, 3]], {
    ALL: () => "Why did the nurse bring a red pen to work? In case she needed to draw blood. Now, what can I help you with?",
  });

  topic("capabilities", [
    [/\bwhat can (you|i) do\b|\bwhat do i do\b|\bmy (role|access|permissions?|tasks?|job)\b|\bwhat (am i|is my role)\b/, 3],
    [/^\s*(help|menu|options|commands)\s*$/, 3],
  ], {
    SUPER: () => join("As Super Admin you manage the system itself, not day-to-day HR work:", bullets([
      "Accounts & Roles: create accounts, change roles, disable, unlock, reset passwords",
      "Create trainee accounts for hired applicants, then promote them to Employee",
      "Departments & Positions, Announcements, and Settings (leave days, lockout rules, targets)",
      "System Overview: security, sign-ins, database health and backups",
    ]), "Ask me things like \"how do I create an account?\" or \"how many failed logins today?\""),
    ADMIN: () => join("As Admin you can do every HR task:", bullets([
      "Claims, ESS requests and HR requests, including approvals",
      "Vacancies, applications, interviews and onboarding",
      "Employee search, directory and records",
      "Dashboard analytics, Audit Log, and Train AI to teach me new answers",
    ]), "Ask me things like \"how do I hire an applicant?\" or \"what needs my attention?\""),
    STAFF: () => join("As HR Staff you encode and process:", bullets([
      "Leave, overtime and claims",
      "Add applicants and move them up to Interview, schedule interviews",
      "Tick onboarding checklist items",
      "Prepare ESS requests after an HR Officer approves them, then mark them Completed",
    ]), "Approvals and hiring decisions are done by an HR Officer. Try asking \"how do I encode leave?\""),
    OFFICER: () => join("As HR Officer you review and decide:", bullets([
      "Approve or reject claims, overtime and ESS requests",
      "Make the final Hired or Rejected decision on applicants",
      "Approve onboarding as complete, publish or decline hire orders",
      "View analytics, the scorecard and audit log, and export reports",
    ]), "HR Staff do the encoding. Try asking \"what's waiting for my approval?\""),
    EMPLOYEE: () => join("Here's what you can do in your portal:", bullets([
      "Leave Request: file leave and check your balance",
      "My Requests: COE, info updates, overtime, reimbursements, schedule changes",
      "Payroll: view and print your payslip estimate",
      "Performance: self-assessment, goals and KPIs",
      "Job Openings: apply for another position in the hospital",
      "Reports and Profile",
    ]), "Try asking \"how many leave days do I have left?\""),
  });

  topic("summary", [
    [/\b(summary|overview|snapshot|update me|status report)\b/, 3],
    [/\bwhat (needs|need) (my )?attention\b|\bwhat should i do\b|\bto ?do\b|\bwhats (pending|new)\b|\banything pending\b/, 3],
    [/\bdashboard\b/, 1],
  ], {
    ALL: () => summaryFull(),
  });

  // ---------------- Account & help ----------------
  topic("password", [[/\bpassword\b/, 3], [/\b(change|reset|forgot|update|new)\b/, 1]], {
    SUPER: (t) => /\b(users?|employees?|staff|someone|their|his|her|others?|accounts?)\b/.test(t) && !/\b(my|own)\b/.test(t)
      ? join("To reset someone else's password:", steps([
        "Open Accounts & Roles in the sidebar.",
        "Find the account in System accounts.",
        "Click Reset password and type a temporary password (8 to 72 characters, with at least one letter and one number).",
        "Give the temporary password to the user privately.",
      ]))
      : join("To change your own password:", steps([
        "Click your name at the top right.",
        "Click Change password.",
        "Enter your current password, then the new one (at least 8 characters, with a letter and a number).",
      ]), "To reset another user's password, ask me \"how do I reset a user's password?\""),
    STAFF: () => adminPasswordSteps(), OFFICER: () => adminPasswordSteps(), ADMIN: () => adminPasswordSteps(),
    EMPLOYEE: () => "The employee portal doesn't have a change-password option. If you forgot your password or want a new one, ask the Super Admin to reset it. They'll give you a temporary password.",
  });
  function adminPasswordSteps() {
    return join("To change your password:", steps([
      "Click your name at the top right.",
      "Click Change password.",
      "Enter your current password, then your new one (at least 8 characters, with a letter and a number).",
    ]), "Forgot your current password? Ask the Super Admin to reset it.");
  }

  topic("locked", [[/\b(locked|lock out|lockout|too many (attempts|tries)|cant (log ?in|sign ?in)|cannot (log ?in|sign ?in)|unlock)\b/, 3]], {
    SUPER: () => join(lockRule(), "To unlock an account now:", steps(["Open Accounts & Roles.", "Find the locked account in System accounts.", "Click Unlock (it only shows on locked accounts)."]),
      D.super ? `Failed sign-ins in the last 24 hours: ${D.super.stats.failedLogins}.` : ""),
    ALL: () => join(lockRule(), "Wait for the lock to end, or ask the Super Admin to unlock your account right away. If you forgot your password, they can reset it too."),
  });
  function lockRule() {
    return `After ${setting("lock_max_failures", 5)} wrong passwords within ${setting("lock_minutes", 5)} minutes, the account locks to protect it from guessing. It unlocks by itself after ${setting("lock_minutes", 5)} minutes.`;
  }

  topic("otp", [[/\b(otp|one.time (password|code)|sign.?in code|login code|verification code|6.digit|didnt (get|receive) (the |a |my )?code|no code|authenticator|2fa|two.factor)\b/, 4], [/\bcode\b/, 1]], {
    ALL: (t) => /\b(authenticator|app|phone|google|microsoft|qr|scan)\b/.test(t)
      ? join("To use your phone for sign-in codes (authenticator app):", steps([
        "Install Google Authenticator or Microsoft Authenticator on your phone.",
        "In HIMS, click your name at the top right, then Authenticator app.",
        "Scan the QR code with the app (or type the setup key), enter the 6-digit code it shows, and click Turn on.",
        "Next time you log in, choose Use authenticator app and type the code from your phone.",
      ]), "It works without internet or load. Lost your phone? Ask the Super Admin to remove the authenticator; you can still use email codes.")
      : join("After your password, HIMS asks for a 6-digit sign-in code:", steps([
        "Enter your email and password and click Login.",
        "If you set up the authenticator app, choose Email me a code or Use authenticator app.",
        "Email codes go to your email. While email isn't set up, they appear in the PHP server window (the terminal running HIMS).",
        "Type the 6 digits. It signs you in automatically.",
      ]), "Email codes work for 2 minutes (ask for a new one every 30 seconds). You have 5 minutes in total; after that, or after 5 wrong codes, enter your email and password again. Wrong codes count toward the account lock. Ask me \"how do I set up the authenticator app?\" to use your phone."),
  });
  topic("timeout", [[/\b(session|time ?out|timed out|signed out|logged out|kicked out|auto(matic)? logout)\b/, 3]], {
    ALL: () => "For security, HIMS signs you out after 5 minutes without any activity (no clicking, typing or scrolling). Just log in again with your password and sign-in code. Unsaved form entries are lost, so submit forms before stepping away.",
  });
  topic("logout", [[/\b(log ?out|sign ?out|logoff)\b/, 3]], {
    EMPLOYEE: () => "Click your name at the top right, then click Log out.",
    ALL: () => "Click your name at the top right, then click Log out. You can also use Log out at the bottom of the sidebar.",
  });
  topic("theme", [[/\b(dark|light) (mode|theme)\b|\btheme\b/, 3]], {
    ALL: () => "Click the Dark mode button at the top right to switch between dark and light mode.",
  });
  topic("support", [[/\b(support|bug|error|broken|not working|problem|issue|glitch)\b/, 2]], {
    EMPLOYEE: () => join("If something isn't working:", steps([
      "Refresh the page first.",
      "If it's about your records (leave, payroll, attendance), send it to HR through My Requests and choose Other.",
      "If you can't sign in, ask the Super Admin to unlock or reset your account.",
    ])),
    ALL: () => join("If something isn't working:", steps([
      "Refresh the page; many issues clear up after a reload.",
      "Click Support in the sidebar, choose a topic, describe the problem and send it.",
      "You can also email hr1supports@gmail.com (Monday to Friday, 8 AM to 5 PM).",
    ])),
  });

  topic("announcements", [[/\b(announcement|announcements|memo|notice|news)\b/, 3]], {
    SUPER: () => join("To post an announcement:", steps([
      "Open Announcements in the sidebar.",
      "Type the title and message.",
      "Choose who sees it: Everyone, Admins only, Employees only, or Trainees only.",
      "Click Post announcement.",
    ]), "You can archive old announcements from the Posted announcements list."),
    EMPLOYEE: () => {
      const a = D.announcements;
      const latest = a && a.length ? `The latest one is "${a[0].title}" (${a[0].created}).` : "There are no announcements for you right now.";
      return join("Announcements for employees appear on your Dashboard, under My Data Analytics.", latest);
    },
    ALL: () => "Announcements are posted by the Super Admin. The ones meant for HR appear in your notifications (the bell icon at the top right).",
  });

  topic("leavepolicy", [[/\b(leave (credits?|days|quota|allowance|policy|entitlement)|how many (leave|days off)|vacation days|sick days)\b/, 3]], {
    EMPLOYEE: () => myLeaveBalance(),
    SUPER: () => join(leaveQuotaText(), "To change these, open Settings and edit the leave days, then click Save settings."),
    ALL: () => join(leaveQuotaText(), "The Super Admin can change these in Settings."),
  });
  function leaveQuotaText() {
    const q = quotas();
    return `Each employee gets these leave days per year:\n${bullets(Object.entries(q).map(([k, v]) => `${k}: ${v} days`))}`;
  }
  function myLeaveBalance() {
    const b = leaveBalance();
    if (!b) return join(leaveQuotaText(), "I couldn't load your own balance just now. Open Leave Request to see it.");
    const pending = (D.portal.leaves || []).filter((l) => lower(l.status) === "pending").length;
    return join("Your leave balance this year:", bullets(b.map((x) => `${x.type}: ${x.left} of ${x.quota} days left`)),
      pending ? `You have ${plural(pending, "leave request")} still pending.` : "");
  }

  function myRequestsOf(typeRe, label) {
    const mine = (D.myEss || []).filter((r) => typeRe.test(r.type || ""));
    if (!mine.length) return `You don't have any ${label} requests yet.`;
    return join(`Your ${label} requests:`, bullets(mine.slice(0, 4).map((r) => `${r.type}${r.amount ? " " + peso(r.amount) : ""}${r.hours ? " " + r.hours + " hr(s)" : ""}: ${r.status}${r.hrNote ? ` (HR note: ${r.hrNote})` : ""}`)),
      mine.some((r) => r.status === "Rejected" && !r.hrNote) ? "If no reason was given, ask HR by sending a new request with type Other." : "");
  }

  topic("schedule", [[/\b(shift|schedules?|duty|roster|work hours)\b/, 3]], {
    EMPLOYEE: () => join("HIMS doesn't keep shift schedules, so your shift comes from your department head.",
      "To ask for a change:", steps(["Open My Requests.", "Choose Schedule change.", "Write the dates and the change you need, then click Submit Request."])),
    ALL: () => "HIMS doesn't keep shift schedules (shift scheduling was removed). Employees ask for changes through My Requests → Schedule change, which arrives in ESS Requests.",
  });

  topic("hiringflow", [[/\b(hiring (process|flow|workflow)|recruitment process|how does hiring work|applicant to employee|workflow|whole process)\b/, 3]], {
    EMPLOYEE: () => join("When you apply for another position:", steps([
      "Your application goes to HR as an internal applicant.",
      "HR Staff screen it and may schedule an interview.",
      "An HR Officer makes the final decision.",
      "HR contacts you about the result.",
    ])),
    ALL: () => join("This is how someone goes from applicant to employee:", steps([
      "They apply on the careers page (or as an internal applicant).",
      "HR Staff move them through Applied, Screening and Interview, and schedule the interview.",
      "An HR Officer marks them Hired or Rejected.",
      "Hired people appear in Onboarding automatically with a checklist.",
      "The Super Admin creates their Trainee account.",
      "HR Staff complete the checklist and an HR Officer approves onboarding.",
      "After training, the Super Admin changes the account to Employee.",
    ])),
  });

  topic("roles", [[/\b(difference between|what does (an? )?(hr )?(staff|officer|admin|super admin) do|who (approves|decides|encodes)|roles?)\b/, 2]], {
    ALL: () => join("Here's who does what in HIMS:", bullets([
      "HR Staff: encode attendance, leave, overtime and claims; screen applicants; schedule interviews; tick onboarding items",
      "HR Officer: approve claims, overtime and ESS requests; make hiring decisions; approve onboarding; publish hire orders",
      "Admin: can do all HR tasks, plus Train AI and analytics",
      "Super Admin: manages accounts, roles, departments, announcements, settings and backups",
      "Employee: uses the self-service portal for leave, requests, payroll and performance",
    ])),
  });

  // ---------------- HR work ----------------
  topic("attendance", [[/\b(attendance|time ?in|time ?out|clock ?(in|out)|timekeeping|tardy|late|absent|present)\b/, 3], [/\brest ?days?\b/, 3]], {
    STAFF: () => join("The Time Attendance page has been removed from the HR Staff workspace, so time in, time out and rest days are no longer encoded here.",
      "Use Monthly Timekeeping Summary (in Overtime Encoding) to see attendance totals for the month.",
      D.attendance ? `Today: ${plural(D.attendance.today, "time-in")} recorded, ${D.attendance.open} still without a time-out.` : ""),
    ADMIN: () => join("Time in and time out are no longer encoded on a Time Attendance page in HIMS.",
      "To review someone's attendance, open Employee Records and search for the employee.",
      D.attendance ? `Today: ${plural(D.attendance.today, "time-in")} recorded, ${D.attendance.open} still without a time-out.` : ""),
    OFFICER: () => join("To verify attendance, open Timekeeping Approvals, tick the records under Attendance to verify, and click Verify selected. The Monthly summary tab shows each employee's hours, undertime, overtime, leave and absences.",
      D.attendance ? `Today: ${plural(D.attendance.today, "time-in")} recorded, ${D.attendance.open} still without a time-out.` : ""),
    EMPLOYEE: () => {
      const n = D.portal ? (D.portal.attendance || []).length : null;
      return join("Your attendance records on file are shown below. You don't need to do anything in the portal.",
        n !== null ? `You have ${plural(n, "attendance record")} on file.` : "",
        "If a record looks wrong, send it to HR in My Requests and choose Schedule change or Other.");
    },
  }, "Attendance is handled by HR Staff.");

  topic("leave", [[/\b(leave|vacation|sick|emergency leave|day off|days off|absence)\b/, 2], [/\b(file|apply|request|submit|encode|send)\b/, 1]], {
    EMPLOYEE: (t) => {
      if (/\b(left|balance|remaining|credits?|how many)\b/.test(t)) return myLeaveBalance();
      if (/\b(status|approved|pending|rejected|my leave)\b/.test(t) && !wantsHow(t)) {
        const l = D.portal ? (D.portal.leaves || []) : [];
        if (!l.length) return "You don't have any leave requests yet. Ask me \"how do I file leave?\" to start one.";
        return join("Your latest leave requests:", bullets(l.slice(0, 3).map((x) => `${x.type}, ${x.start} to ${x.end}: ${x.status}`)));
      }
      return join("To file a leave:", steps([
        "Open Leave Request in the sidebar.",
        "Choose the leave type (Vacation, Sick or Emergency).",
        "Pick the start and end dates and type a short reason.",
        "Click Submit Leave Request.",
      ]), "It starts as Pending; you'll see HR's decision in the table below the form. You can't request more days than you have left.", myLeaveBalanceShort());
    },
    STAFF: () => join("To encode a leave:", steps([
      "Open Leave Encoding in the sidebar.",
      "Enter the Employee ID and name.",
      "Choose Sick, Vacation or Emergency Leave and pick the dates.",
      "Click Submit Leave.",
    ]), D.leaves ? `There are ${plural(D.leaves.total, "leave record")} in the system.` : ""),
    ADMIN: () => join("To approve or reject leave, open Timekeeping Approvals. It lists leave filed by employees and leave encoded by HR Staff. The employee sees your decision in their Leave Request page.", leaveQuotaText()),
    OFFICER: () => join("To approve or reject leave:", steps(["Open Timekeeping Approvals in the sidebar.", "Under Leave requests, check the dates and reason.", "Click Approve or Reject. The employee sees the result in their portal, and approved days count as paid leave."]), leaveQuotaText()),
    SUPER: () => join(leaveQuotaText(), "To change the number of leave days, open Settings, edit the values, and click Save settings."),
  });
  function myLeaveBalanceShort() {
    const b = leaveBalance();
    return b ? `Days left: ${b.map((x) => `${x.type.replace(" Leave", "")} ${x.left}`).join(", ")}.` : "";
  }

  topic("overtime", [[/\bovertime\b|\bextra hours\b/, 3]], {
    STAFF: () => join("To encode overtime:", steps([
      "Open Overtime Encoding in the sidebar.",
      "Enter the Employee ID, name and number of hours.",
      "Click Submit. It goes to an HR Officer for approval.",
    ]), D.overtime ? `${plural(D.overtime.pending, "overtime request")} waiting for approval.` : ""),
    OFFICER: () => join("To approve or reject overtime:", steps([
      "Open Timekeeping Approvals in the sidebar.",
      "Under Overtime, check the date and hours.",
      "Click Approve or Reject. Approved hours go to the employee's payslip at 125%.",
    ]), D.overtime ? `${plural(D.overtime.pending, "overtime request")} waiting for your decision.` : ""),
    ADMIN: () => join("To review overtime, open Timekeeping Approvals and click Approve or Reject. Approved hours go to the employee's payslip. Overtime employees request in My Requests is approved in ESS Requests and is added to their payroll too.",
      D.overtime ? `${plural(D.overtime.pending, "overtime request")} pending.` : ""),
    EMPLOYEE: (t) => /\b(status|rejected|approved|why|pending|my overtime)\b/.test(t) && !wantsHow(t) ? myRequestsOf(/overtime/i, "overtime") : join("To request overtime:", steps([
      "Open My Requests in the sidebar.",
      "Set Request type to Overtime and enter the number of hours.",
      "Write the details (date and reason) and click Submit Request.",
    ]), "Once approved, overtime is added to that month's payroll at 125% of your hourly rate."),
  }, "Overtime is encoded by HR Staff and approved by an HR Officer.");

  topic("claims", [[/\b(claims?|reimburse(ment)?|expense|receipt|refund)\b/, 3]], {
    STAFF: () => join("To encode a claim:", steps([
      "Open Claims in the sidebar.",
      "Enter the Employee ID, name and amount.",
      "Upload the receipt.",
      "Click Submit Claim. An HR Officer will approve or reject it.",
    ]), D.claims ? `${plural(D.claims.pending, "claim")} waiting for approval.` : ""),
    OFFICER: () => join("To approve or reject claims:", steps([
      "Open Claims (or Approvals and filter by Claims).",
      "Check the amount and receipt.",
      "Click Approve or Reject.",
    ]), D.claims ? `${plural(D.claims.pending, "claim")} waiting for your decision.` : ""),
    ADMIN: () => join("Open Claims in the sidebar to encode a claim (with receipt) or approve and reject pending ones.",
      D.claims ? `${plural(D.claims.pending, "claim")} pending out of ${D.claims.total}.` : ""),
    EMPLOYEE: (t) => /\b(status|rejected|approved|why|pending|my claims?)\b/.test(t) && !wantsHow(t) ? myRequestsOf(/claim/i, "reimbursement") : join("To ask for a reimbursement:", steps([
      "Open My Requests.",
      "Set Request type to Reimbursement / claim and enter the amount.",
      "Describe what it's for, then click Submit Request.",
      "Give the original receipt to HR.",
    ]), "An HR Officer approves it, and you'll see the result and any note in My Requests."),
  }, "Claims are encoded by HR Staff and approved by an HR Officer.");

  topic("ess", [[/\b(ess|self.service|my requests?|employee requests?|hr requests?)\b/, 3], [/\brequests?\b/, 1]], {
    EMPLOYEE: (t) => {
      const mine = D.myEss || [];
      if (/\b(status|my requests|pending|approved|rejected)\b/.test(t) && !wantsHow(t)) {
        if (!mine.length) return "You haven't sent any requests yet. Open My Requests to create one.";
        return join("Your latest requests:", bullets(mine.slice(0, 4).map((r) => `${r.type}: ${r.status}${r.hrNote ? ` (HR note: ${r.hrNote})` : ""}`)));
      }
      return join("To send a request to HR:", steps([
        "Open My Requests in the sidebar.",
        "Choose the request type: Certificate of Employment, Update personal information, Overtime, Reimbursement / claim, Schedule change, or Other.",
        "Write the details and, if needed, the date you need it by.",
        "Click Submit Request.",
      ]), "An HR Officer approves or rejects it, then HR Staff prepare it. You'll see the status and HR's note in the table.",
      mine.length ? `You have ${plural(mine.filter((r) => r.status === "Pending").length, "request")} pending.` : "");
    },
    STAFF: () => join("Your part in ESS requests:", steps([
      "Open ESS Requests and filter by Approved.",
      "Prepare what was approved (for example, print the COE).",
      "Click Mark completed so the employee sees it's done.",
    ]), "Approving or rejecting is done by an HR Officer.", `${plural(D.ess ? D.ess.approved : 0, "approved request")} waiting for you to prepare.`),
    OFFICER: () => join("To decide on ESS requests:", steps([
      "Open ESS Requests (or Approvals and filter by ESS requests).",
      "Read the details and add a note if needed.",
      "Click Approve or Reject. The employee sees your decision and note.",
    ]), `${plural(D.ess ? D.ess.pending : 0, "request")} waiting for your decision.`),
    ADMIN: () => join("Open ESS Requests to approve, reject or complete requests employees send from their portal. Use HR Requests for requests coming from HR2, HR3 and HR4.",
      D.ess ? `${plural(D.ess.pending, "ESS request")} pending, ${D.ess.approved} approved and waiting to be prepared.` : ""),
  }, "ESS requests are handled by HR Officers and HR Staff.");

  topic("coe", [[/\b(certificate of employment|employment certificate|coe)\b/, 4]], {
    EMPLOYEE: () => join("To request a Certificate of Employment:", steps([
      "Open My Requests.",
      "Choose Certificate of Employment.",
      "In Details, write the purpose and who it's addressed to (for example: \"For a bank loan, addressed to BDO\").",
      "Add the date you need it by, then click Submit Request.",
    ]), "An HR Officer approves it and HR Staff prepare it. The status shows in My Requests."),
    ALL: () => "Employees request a COE through My Requests in their portal. An HR Officer approves it in ESS Requests, then HR Staff prepare it and mark it Completed.",
  });

  topic("profile", [[/\b(profile|personal info(rmation)?|emergency contact|contact number|address|update my (info|details))\b/, 3]], {
    EMPLOYEE: (t) => {
      const p = D.portal && D.portal.profile;
      if (wantsHow(t) || /\b(update|change|edit)\b/.test(t)) {
        return join("To update your personal details or emergency contact:", steps([
          "Open My Requests.",
          "Choose Update personal information.",
          "Write what should change, then click Submit Request.",
        ]), "HR updates your record after approving it.");
      }
      return join(p ? `Your profile: ${p.position || "No position"} in ${p.department || "no department"}, employee ID ${p.id}.` : "",
        "See all your details in Employee Profile in the sidebar. To change them, ask me \"how do I update my information?\"");
    },
    ALL: () => "Click your name at the top right to see your account. Employee details are kept in Employee Records.",
  });

  topic("payroll", [[/\b(payroll|payslip|pay slip|salary|pay ?day|net pay|deductions?|sss|philhealth|pag.?ibig|tax)\b/, 3]], {
    EMPLOYEE: () => {
      const p = D.portal && D.portal.profile;
      return join("To see your payslip:", steps([
        "Open Payroll in the sidebar.",
        "Pick the month.",
        "Click Print Payslip if you need a copy.",
      ]), "It's an estimate: government contributions and tax use simplified rates. Approved overtime is added at 125% of your hourly rate (monthly salary ÷ 22 days ÷ 8 hours).",
      p && Number(p.salary) > 0 ? `Your monthly salary on record is ${peso(p.salary)}.` : "");
    },
    SUPER: () => "Payroll is shown in each employee's portal as an estimate. The monthly salary is set when you create the account in Accounts & Roles (Monthly salary field).",
    ALL: () => "HIMS doesn't run payroll for HR. Employees see an estimated payslip in their portal, based on the monthly salary the Super Admin set on their account.",
  });

  topic("performance", [[/\b(performance|self.?assessment|appraisal|evaluation|rating|competency|review period)\b/, 3], [/\b(goals?|kpis?)\b/, 2]], {
    EMPLOYEE: (t) => {
      const p = D.portal;
      if (/\b(goals?|kpis?)\b/.test(t)) {
        const g = p ? (p.goals || []) : [];
        return join("To add a goal:", steps(["Open Competency / Performance.", "Type it under Goals & KPIs and click Add Goal.", "Update the % as you make progress."]),
          g.length ? `Your goals: ${g.map((x) => `${x.text} (${x.progress}%)`).join("; ")}.` : "");
      }
      const r = p ? (p.reviews || []) : [];
      const last = r.length ? r[r.length - 1] : null;
      return join("To submit your self-assessment:", steps([
        "Open Competency / Performance.",
        "Rate each skill from 1 (Needs Improvement) to 5 (Outstanding).",
        "Choose the review period and add comments.",
        "Click Submit Assessment.",
      ]), last ? `Your latest review (${last.period}) averaged ${Number(last.avg).toFixed(2)} out of 5.` : "You haven't submitted a self-assessment yet.");
    },
    OFFICER: () => "Open Dashboard to see HR analytics and the scorecard. Each employee's own ratings and goals are in their Employee Record.",
    ADMIN: () => "Open Dashboard to see HR analytics and the scorecard. Each employee's own ratings and goals are in their Employee Record.",
    STAFF: () => "Employees submit their own self-assessments in their portal. You can view an employee's file in Employee Records.",
    SUPER: () => "You can change the scorecard targets in Settings. Employees submit their own self-assessments in their portal.",
  });

  topic("jobs", [[/\b(job openings?|open positions?|vacanc(y|ies)|hire orders?|apply for|internal (application|applicant|job)|transfer|promotion)\b/, 3], [/\bjobs?\b/, 2], [/\b(publish|decline)\b/, 1]], {
    EMPLOYEE: (t) => {
      const jobs = D.jobs || [];
      const list = jobs.length ? `Open now: ${jobs.slice(0, 5).map((j) => `${j.title} (${j.department})`).join(", ")}.` : "There are no open positions right now.";
      if (!wantsHow(t) && /\b(what|any|list|available|open)\b/.test(t)) return list;
      return join("To apply for another position:", steps([
        "Open Job Openings in the sidebar.",
        "Click Apply on the job you want.",
        "Add your phone number and resume (PDF, DOC, DOCX, JPG or PNG, up to 5 MB).",
        "Optionally say why you're applying, then click Send Application.",
      ]), "HR sees you as an internal applicant and will contact you.", list);
    },
    OFFICER: () => join("Hire orders arrive from HR3. To handle one:", steps([
      "Open Vacancies.",
      "Check the job title, department and slots.",
      "Click Publish to show it on the careers page, or Decline it.",
    ]), vacancyLine()),
    ADMIN: () => join("Open Vacancies to publish or decline hire orders from HR3, or archive old ones. Published jobs appear on the careers page.", vacancyLine()),
    STAFF: () => join("You can view hire orders in Vacancies, but publishing or declining them is done by an HR Officer.", vacancyLine()),
    SUPER: () => join("Vacancies are managed by HR in the HR workspace.", vacancyLine()),
  });
  function vacancyLine() {
    const v = D.vacancies; if (!v) return "";
    return `${plural(v.open, "job")} open on the careers page, ${plural(v.pending, "hire order")} waiting to be published.`;
  }

  topic("applicants", [[/\b(applicants?|applications?|candidates?|screening|resume)\b/, 3], [/\badd\b/, 1]], {
    STAFF: (t) => join(/\b(add|new|encode|walk.?in)\b/.test(t)
      ? join("To add an applicant:", steps(["Open Applications.", "Select the vacancy, then enter the applicant's name, email and phone.", "Click Add Applicant."]))
      : join("To move an applicant forward:", steps([
        "Open Applications and find the applicant.",
        "Change the status to Screening, then Interview.",
        "Schedule the interview in the Interview page.",
      ]), "You can move applicants up to Interview. The Hired or Rejected decision is made by an HR Officer."), applicationLine()),
    OFFICER: () => join("Open Applications to review candidates. After the interview, set the status to Hired or Rejected. Hired people move to Onboarding automatically.",
      "You can also archive applicants who are no longer active.", applicationLine()),
    ADMIN: () => join("Open Applications to add applicants, move them through Applied, Screening and Interview, and set them Hired or Rejected. Hired people move to Onboarding automatically.", applicationLine()),
    SUPER: () => join("Applicants don't get accounts while applying. When HR marks one Hired, you create their trainee account: ask me \"how do I create a trainee account?\"", applicationLine()),
  }, "Applications are handled by HR.");
  function applicationLine() {
    const a = D.applications; if (!a) return "";
    if (!a.total) return "There are no active applications right now.";
    const order = ["Applied", "Screening", "Interview", "Hired", "Rejected"];
    return `Active applications: ${order.filter((s) => a.by[s]).map((s) => `${a.by[s]} ${s}`).join(", ")}.`;
  }

  topic("interview", [[/\binterviews?\b/, 4]], {
    STAFF: () => interviewSteps(), ADMIN: () => interviewSteps(), OFFICER: () => interviewSteps(),
  }, "Interviews are scheduled by HR.");
  function interviewSteps() {
    return join("To schedule an interview:", steps([
      "Open Interview in the sidebar.",
      "Select the applicant and pick the date and time.",
      "Type the interviewer's name and choose On-site, Online or Phone.",
      "Click Schedule Interview.",
    ]), "After the interview, record the result in the same list.", D.interviews ? `${plural(D.interviews.scheduled, "interview")} currently scheduled.` : "");
  }

  topic("hire", [[/\b(hire(?! orders?)|hired|hiring decision|reject (an |the )?applicant|accept (an |the )?applicant)\b/, 4]], {
    OFFICER: () => join("To make the hiring decision:", steps([
      "Open Applications (or Approvals and filter by Hiring decisions).",
      "Find the applicant who finished the interview.",
      "Set the status to Hired or Rejected.",
    ]), "Hired applicants appear in Onboarding automatically."),
    ADMIN: () => "Open Applications and set the applicant's status to Hired or Rejected. Hired applicants appear in Onboarding automatically.",
    SUPER: () => "HR decides who is hired. After an applicant is marked Hired, you create their trainee account: ask me \"how do I create a trainee account?\"",
  }, "The final Hired or Rejected decision is made by an HR Officer. HR Staff can move applicants up to Interview.");

  topic("onboarding", [[/\b(onboarding|checklist|new hires?|orientation|medical exam|pre.?employment)\b/, 3]], {
    STAFF: () => join("To work on onboarding:", steps([
      "Open Onboarding in the sidebar.",
      "Open the new hire and tick each item as it's done: documents, medical exam, government IDs, contract, orientation, ID and uniform.",
      "When every item is done, an HR Officer approves it.",
    ]), onboardingLine()),
    OFFICER: () => join("To approve onboarding:", steps([
      "Open Onboarding and filter by Ready for approval.",
      "Check that every checklist item is done.",
      "Click Approve onboarding complete.",
    ]), "After that, the Super Admin changes the trainee's account to Employee.", onboardingLine()),
    ADMIN: () => join("Open Onboarding to tick checklist items and approve completed onboarding. Everyone HR marks Hired appears there automatically.", onboardingLine()),
    SUPER: () => join("When an HR Officer approves someone's onboarding, change their account from Trainee to Employee in Accounts & Roles.", onboardingLine()),
  });
  function onboardingLine() {
    const o = D.onboarding; if (!o) return "";
    return `${plural(o.active, "person", "people")} in onboarding, ${o.ready} ready for approval.`;
  }

  topic("approvals", [[/\b(approvals?|approve|reject|for approval|decision|waiting for (me|my))\b/, 2]], {
    OFFICER: () => join("Everything waiting for you is on the Approvals page. Use the filter to show Claims, Overtime, ESS requests, Hiring decisions, Hire orders or Onboarding, and click Approve or Reject.", officerQueue()),
    ADMIN: () => join("You can approve claims (Claims), ESS requests (ESS Requests), overtime (HR Requests), hire orders (Vacancies), hiring decisions (Applications) and onboarding (Onboarding).", officerQueue()),
    EMPLOYEE: () => "HR reviews your leave and requests. You'll see each decision, and any note from HR, in Leave Request and My Requests.",
  }, "Approvals are done by an HR Officer. HR Staff encode and prepare.");
  function officerQueue() {
    const items = [];
    if (D.claims) items.push(plural(D.claims.pending, "claim"));
    if (D.overtime) items.push(`${D.overtime.pending} overtime`);
    if (D.ess) items.push(plural(D.ess.pending, "ESS request"));
    if (D.vacancies) items.push(plural(D.vacancies.pending, "hire order"));
    if (D.applications) items.push(`${D.applications.by.Interview || 0} at Interview stage`);
    if (D.onboarding) items.push(`${D.onboarding.ready} onboarding ready`);
    return items.length ? `Waiting now: ${items.join(", ")}.` : "";
  }

  topic("employees", [[/\b(employees?|staff list|directory|headcount|employee records?|search (an )?employee|find (an )?employee|workforce)\b/, 2]], {
    EMPLOYEE: () => {
      const c = D.portal && D.portal.employeeCount;
      return join(c ? `The hospital has ${plural(c, "employee")} on record.` : "", "Open Reports to see headcount by department.");
    },
    SUPER: () => join("Employee accounts are created in Accounts & Roles. HR manages employee files in their workspace.", employeeLine()),
    ALL: (t) => join(wantsHow(t) || /\b(search|find|look)\b/.test(t)
      ? join("To find an employee:", bullets([
        "Search Employee: type an ID or name and click Search.",
        "Employee Directory: browse everyone; click Export File to download the list.",
        "Employee Records: open someone's full file (leave, claims, overtime, attendance, requests). Printing or downloading asks for your password.",
      ])) : "", employeeLine()),
  });
  function employeeLine() {
    const e = D.employees; if (!e) return "";
    return `${e.total === 1 ? "There is" : "There are"} ${plural(e.total, "employee")} on record, ${e.active} active.`;
  }

  topic("reports", [[/\b(export|download|reports?|analytics|scorecard|csv|print)\b/, 2]], {
    EMPLOYEE: () => join("Open Reports in the sidebar. You can export your summary, leaves, reviews or profile as a file. The system asks for your password before downloading.",
      "To print your payslip, open Payroll and click Print Payslip."),
    OFFICER: () => "Open Dashboard for HR analytics and the scorecard. On the Approvals page, click Export HR report to download a password-protected report.",
    ADMIN: () => "Open Dashboard for HR analytics and the scorecard. You can export the employee list from Employee Directory and the audit trail from Audit Log.",
    STAFF: () => "Exporting HR reports is done by an HR Officer or Admin. You can still view Employee Records, and export the list from Employee Directory.",
    SUPER: () => "System Overview shows sign-in activity, account analytics and the security scorecard. Security Audit has an Export CSV button.",
  });

  topic("audit", [[/\b(audit|activity log|logs?|history|who did)\b/, 3]], {
    OFFICER: () => "Open Audit Log in the sidebar to see who did what and when. Click Export CSV to download it.",
    ADMIN: () => "Open Audit Log in the sidebar to see who did what and when. Click Export CSV to download it.",
    SUPER: () => join("Open Security Audit to see sign-ins, failed attempts, account changes and their source addresses. Click Export CSV to download it.",
      D.super ? `Failed sign-ins in the last 24 hours: ${D.super.stats.failedLogins}.` : ""),
  }, "The audit log is available to HR Officers and Admins.");

  topic("trainai", [[/\b(train (the )?ai|teach (you|the ai)|trained answers?|custom answers?|add (an )?answer)\b/, 4]], {
    ADMIN: () => join("To teach me a new answer:", steps([
      "Open Train AI in the sidebar.",
      "Type keywords people might use, separated by commas (for example: payday, salary date).",
      "Type the answer and click Save answer.",
      "Use Test the assistant to try it.",
    ]), "Recent questions marked \"No good answer\" are the best ones to teach next."),
  }, "Only Admins can teach the assistant new answers in Train AI.");

  // ---------------- Super Admin ----------------
  topic("createaccount", [[/\b(create|add|make|new|register)\b.*\b(account|user|login)\b|\b(account|user) creation\b/, 4]], {
    SUPER: () => join("To create an account:", steps([
      "Open Accounts & Roles.",
      "Fill in name, email, role, department and position.",
      "For an Employee, also fill in contact, hire date, emergency contact and monthly salary.",
      "Type a temporary password (8 to 72 characters, with a letter and a number; avoid common passwords).",
      "Click Create account, then give the temporary password to the user privately.",
    ]), "For a hired applicant, create a Trainee account instead. Ask me how."),
  }, "Only the Super Admin creates accounts. Ask them for a new account.");

  topic("trainee", [[/\btrainees?\b/, 3], [/\b(promote|convert|change)\b/, 1]], {
    SUPER: (t) => /\b(promote|convert|change|employee)\b/.test(t)
      ? join("To change a trainee to an employee:", steps([
        "Make sure an HR Officer has approved their onboarding.",
        "Open Accounts & Roles and find the trainee.",
        "Change the role to Employee and save.",
      ]), "It's the same account, so they keep their login.")
      : join("To create a trainee account for a hired applicant:", steps([
        "Open Accounts & Roles and set Role to Trainee.",
        "Pick the Accepted application (only applicants HR marked Hired appear).",
        "Enter the training program, start date and expected end date.",
        "Set a temporary password and click Create account.",
      ]), "Name and email come from the application, so no duplicate is created."),
    ALL: () => "Trainee accounts are created by the Super Admin after HR marks an applicant Hired. After onboarding and training, the Super Admin changes the account to Employee.",
  });

  topic("accountstatus", [[/\b(disable|deactivate|enable|activate|suspend|remove)\b.*\b(account|user)\b|\b(change|update) (the )?role\b/, 4]], {
    SUPER: () => join("In Accounts & Roles, find the account in System accounts. From its Actions you can:", bullets([
      "Change its role",
      "Disable it (they can't sign in) or enable it again",
      "Unlock it after too many failed sign-ins",
      "Reset its password",
    ]), D.super ? `Right now: ${D.super.stats.activeAccounts} active and ${D.super.stats.disabledAccounts} disabled accounts.` : ""),
  }, "Account changes are done by the Super Admin.");

  topic("sessions", [[/\b(sessions?|devices?|which (phone|computer|laptop)|ip address|sign (them|him|her) out|kick (out|someone)|online now|who is online|logged in (where|from))\b/, 4]], {
    SUPER: () => join("To see where an account is signed in:", steps([
      "Open Accounts & Roles. Each account shows its last device (for example Chrome on Windows · Computer) and \"Online now\" when active.",
      "Click Sessions on the account to see its latest sign-ins: device, IP address, start time, last activity and status.",
      "To kick a device out, click Sign out next to an active session. That device must log in again.",
    ]), "Disabling an account also signs it out everywhere. Use the Online now, Signed in today, Locked and Disabled buttons above the list to filter."),
  }, "Only the Super Admin can see sign-in sessions and devices.");

  topic("departments", [[/\b(departments?|positions?|units?|wards?)\b/, 2]], {
    SUPER: () => join("To manage departments:", steps([
      "Open Departments & Positions.",
      "Type the department name and click Add department.",
      "Archive ones you no longer use.",
    ]), "Keep one spelling per department so the reports and graphs stay accurate."),
    EMPLOYEE: () => {
      const d = D.portal && D.portal.departmentCounts;
      return d && d.length ? `Headcount by department: ${d.map((x) => `${x.department} ${x.total}`).join(", ")}.` : "Open Reports to see headcount by department.";
    },
    ALL: () => "Departments and positions are managed by the Super Admin in Departments & Positions. You'll see the same list in the forms.",
  });

  topic("settings", [[/\b(settings?|configure|configuration|hospital name|logo|lockout rules?|targets?)\b/, 3]], {
    SUPER: () => join("Open Settings to change how HIMS works without editing code:", bullets([
      "Hospital name and logo",
      `Leave days per year (now: Vacation ${setting("leave_vacation", 15)}, Sick ${setting("leave_sick", 10)}, Emergency ${setting("leave_emergency", 5)})`,
      `Account lockout (now: ${setting("lock_max_failures", 5)} tries, ${setting("lock_minutes", 5)} minutes)`,
      `Onboarding overdue after ${setting("onboarding_overdue_days", 30)} days`,
      "Scorecard targets",
    ]), "Click Save settings. Everyone sees the change after they refresh."),
  }, "System settings are changed by the Super Admin.");

  topic("backup", [[/\b(backup|back up|restore|database)\b/, 3]], {
    SUPER: () => join("To back up the database:", steps([
      "Open System Overview.",
      "Under Database backups, click Download backup.",
      "Enter your password. A .sql file downloads.",
    ]), "To restore: open phpMyAdmin or MySQL Workbench, select the database, and import the .sql file. Restoring replaces the current data.",
    D.super && D.super.database ? `Database: ${D.super.database.name || "connected"}.` : ""),
  }, "Database backups are handled by the Super Admin.");

  topic("security", [[/\b(security|failed (logins?|sign.?ins?)|sign.?in activity|suspicious|hack)\b/, 3]], {
    SUPER: () => join(D.super ? `In the last 24 hours: ${plural(D.super.stats.failedLogins, "failed sign-in")}. Active accounts: ${D.super.stats.activeAccounts}, disabled: ${D.super.stats.disabledAccounts}, waiting for approval: ${D.super.stats.pendingApprovals}.` : "",
      "Open System Overview for sign-in activity and the security scorecard, or Security Audit for the full list with source addresses."),
  }, "Security monitoring is handled by the Super Admin.");

  // -------------------------------------------------------------------------
  // Summaries
  // -------------------------------------------------------------------------
  function summaryLine() {
    const g = roleGroup();
    if (g === "EMPLOYEE") { const b = leaveBalance(); return b ? `You have ${b.reduce((s, x) => s + x.left, 0)} leave days left this year.` : ""; }
    if (g === "SUPER" && D.super) return `${plural(D.super.stats.activeAccounts, "active account")}, ${plural(D.super.stats.failedLogins, "failed sign-in")} in the last 24 hours.`;
    if (g === "OFFICER") return officerQueue();
    if (g === "STAFF" && D.ess) return `${plural(D.ess.approved, "approved ESS request")} to prepare${D.interviews ? `, ${plural(D.interviews.scheduled, "interview")} scheduled` : ""}.`;
    if (g === "ADMIN" && D.employees) return `${plural(D.employees.total, "employee")} on record${D.applications ? `, ${plural(D.applications.total, "active application")}` : ""}.`;
    return "";
  }
  function summaryFull() {
    const g = roleGroup();
    if (!D.loadedAt) return "I'm still loading the latest numbers. Ask me again in a moment.";
    if (g === "EMPLOYEE") {
      const p = D.portal || {};
      const pendingLeave = (p.leaves || []).filter((l) => lower(l.status) === "pending").length;
      const pendingEss = (D.myEss || []).filter((r) => r.status === "Pending").length;
      const goals = p.goals || [];
      return join("Here's your quick summary:", bullets([
        myLeaveBalanceShort() || "Leave balance: open Leave Request",
        `Pending: ${plural(pendingLeave, "leave request")}, ${plural(pendingEss, "HR request")}`,
        goals.length ? `Goals: ${goals.length}, average progress ${Math.round(goals.reduce((s, x) => s + Number(x.progress || 0), 0) / goals.length)}%` : "Goals: none yet",
        D.jobs ? `Job openings: ${D.jobs.length}` : "",
      ].filter(Boolean)));
    }
    if (g === "SUPER") {
      const s = D.super ? D.super.stats : null;
      return join("System summary:", bullets([
        s ? `Accounts: ${s.activeAccounts} active, ${s.disabledAccounts} disabled, ${s.pendingApprovals} waiting for approval` : "",
        s ? `Failed sign-ins (24 h): ${s.failedLogins}` : "",
        D.onboarding ? `Onboarding: ${D.onboarding.ready} ready (their trainee accounts can be promoted after approval)` : "",
        D.applications ? `Hired applicants: ${D.applications.by.Hired || 0} (may need trainee accounts)` : "",
      ].filter(Boolean)));
    }
    const lines = [];
    if (g === "STAFF") {
      if (D.attendance) lines.push(`Attendance today: ${D.attendance.today} time-ins, ${D.attendance.open} without time-out`);
      if (D.ess) lines.push(`ESS requests to prepare: ${D.ess.approved}`);
      if (D.applications) lines.push(`Applicants to screen: ${(D.applications.by.Applied || 0) + (D.applications.by.Screening || 0)}`);
      if (D.interviews) lines.push(`Interviews scheduled: ${D.interviews.scheduled}`);
      if (D.onboarding) lines.push(`People in onboarding: ${D.onboarding.active}`);
      return join("Your work today:", bullets(lines), "Open My Work Today for the full list.");
    }
    if (D.claims) lines.push(`Claims pending: ${D.claims.pending}`);
    if (D.overtime) lines.push(`Overtime pending: ${D.overtime.pending}`);
    if (D.ess) lines.push(`ESS requests pending: ${D.ess.pending}`);
    if (D.vacancies) lines.push(`Hire orders to publish: ${D.vacancies.pending} (open jobs: ${D.vacancies.open})`);
    if (D.applications) lines.push(`Applicants at Interview: ${D.applications.by.Interview || 0}`);
    if (D.onboarding) lines.push(`Onboarding ready for approval: ${D.onboarding.ready}`);
    if (g === "ADMIN" && D.employees) lines.push(`Employees: ${D.employees.total} (${D.employees.active} active)`);
    return join(g === "OFFICER" ? "Waiting for your decision:" : "HR summary:", bullets(lines), g === "OFFICER" ? "Open Approvals to work through them." : "");
  }

  // -------------------------------------------------------------------------
  // Choosing the answer
  // -------------------------------------------------------------------------
  function score(t, tp) {
    return tp.keys.reduce((s, [re, w]) => (re.test(t) ? s + w : s), 0);
  }
  function suggestionsText() {
    const map = {
      SUPER: ["How do I create an account?", "How do I make a trainee an employee?", "How do I unlock an account?", "How do I back up the database?"],
      ADMIN: ["What needs my attention?", "How do I hire an applicant?", "How do I approve a claim?", "How do I train the AI?"],
      STAFF: ["How do I encode overtime?", "How do I encode leave?", "How do I add an applicant?", "How do I schedule an interview?"],
      OFFICER: ["What's waiting for my approval?", "How do I approve overtime?", "How do I hire an applicant?", "How do I approve onboarding?"],
      EMPLOYEE: ["How many leave days do I have left?", "How do I file leave?", "How do I get a COE?", "How do I see my payslip?"],
    };
    return map[roleGroup()] || map.ADMIN;
  }

  function reply(message) {
    const raw = String(message || "").trim();
    if (!raw) return "Type a question and I'll help.";
    const g = roleGroup();
    if (g === "GUEST") return "Please sign in first. After you sign in I can help with the pages your account can use.";
    if (Date.now() - D.loadedAt > 30000 || ownerKey() !== D.owner) loadData(); // refresh in the background for the next answer

    const t = normalize(raw);
    const ranked = TOPICS.map((tp) => ({ tp, s: score(t, tp) })).filter((x) => x.s > 0).sort((a, b) => b.s - a.s);
    // A greeting only wins when nothing else was asked ("hi, how do I file leave?" answers the leave part).
    let best = ranked[0];
    if (best && best.tp.id === "greet" && ranked[1] && ranked[1].s >= 2) best = ranked[1];
    if (!best || best.s < 2) return fallback();

    // "Can I approve…" from a role that doesn't approve goes to the approvals answer.
    if (["STAFF", "EMPLOYEE"].includes(g) && /\b(approve|reject)\b/.test(t) && !/\bwho\b/.test(t)) {
      best = { tp: TOPICS.find((x) => x.id === "approvals"), s: 9 };
    }
    const tp = best.tp;
    const fn = tp.answers[g] || tp.answers.ALL;
    if (!fn) {
      return tp.other
        ? `${tp.other} That isn't part of the ${roleLabel()} account. Ask me "what can I do?" to see what you can use.`
        : fallback();
    }
    const out = fn(t, raw);
    return out && out.trim() ? out.trim() : fallback();
  }

  function fallback() {
    const g = roleGroup();
    return join("Sorry, I'm not sure what you mean. Try asking it another way, for example:",
      bullets(suggestionsText()),
      g === "ADMIN" ? "You can also teach me new answers in Train AI." : "Or ask \"what can I do?\" to see everything I can help with.");
  }

  // Exposed so the Train AI wrapper in index.html (and anything else) uses this assistant.
  window.HimsAssistant = { reply, refresh: () => loadData(true), data: D };
  window.generateAiReply = reply;

  // -------------------------------------------------------------------------
  // Chat window
  // -------------------------------------------------------------------------
  const STYLE = `
    .ai-chat-widget .ai-chat-messages { height: 340px; max-height: 50vh; }
    .ai-chat-widget .ai-message span { white-space: pre-line; overflow-wrap: anywhere; }
    .ai-chat-widget .ai-suggestions { max-height: 92px; overflow-y: auto; }
    .ai-chat-widget .ai-message-typing span { opacity: .7; font-style: italic; }
  `;
  function addStyle() {
    if (document.getElementById("himsAssistantStyle")) return;
    const s = document.createElement("style");
    s.id = "himsAssistantStyle";
    s.textContent = STYLE;
    document.head.appendChild(s);
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }

  // The employee page has no chat yet: add the same button and window the admin page uses.
  function buildEmployeeWidget() {
    if (document.getElementById("aiChatWidget")) return;
    const wrap = document.createElement("div");
    wrap.innerHTML = `
      <button id="floatingAiButton" class="floating-ai-button" type="button" aria-label="Open AI Assistant">
        <span class="floating-ai-icon">✦</span><span class="floating-ai-text">AI</span>
      </button>
      <div id="aiChatWidget" class="ai-chat-widget" hidden>
        <div class="ai-chat-header">
          <div><span class="modal-eyebrow">AI Support</span><h3>Employee Assistant</h3></div>
          <button class="modal-close" type="button" aria-label="Close AI assistant" data-ai-close>&times;</button>
        </div>
        <div id="aiChatMessages" class="ai-chat-messages" aria-live="polite"></div>
        <div class="ai-suggestions" id="aiSuggestions"></div>
        <form id="aiChatForm" class="ai-chat-form">
          <input id="aiChatInput" type="text" placeholder="Ask about leave, payroll, requests..." autocomplete="off" maxlength="300">
          <button type="submit">Send</button>
        </form>
      </div>`;
    while (wrap.firstElementChild) document.body.appendChild(wrap.firstElementChild);

    const widget = document.getElementById("aiChatWidget");
    const toggle = () => { widget.hidden = !widget.hidden; if (!widget.hidden) onOpen(); };
    document.getElementById("floatingAiButton").addEventListener("click", toggle);
    widget.querySelector("[data-ai-close]").addEventListener("click", toggle);
    document.getElementById("aiChatForm").addEventListener("submit", (event) => {
      event.preventDefault();
      const input = document.getElementById("aiChatInput");
      const text = input.value.trim();
      if (!text) return;
      input.value = "";
      say("user", text);
      const typing = say("bot", "Typing…", true);
      Promise.resolve(D.loading).then(() => {
        window.setTimeout(() => { typing.remove(); say("bot", reply(text)); }, 250);
      });
    });
    document.getElementById("aiSuggestions").addEventListener("click", (event) => {
      const b = event.target.closest("button[data-prompt]");
      if (!b) return;
      document.getElementById("aiChatInput").value = b.dataset.prompt;
      document.getElementById("aiChatForm").requestSubmit();
    });
  }

  function say(role, text, typing) {
    const box = document.getElementById("aiChatMessages");
    if (!box) return document.createElement("div");
    const m = document.createElement("div");
    m.className = `ai-message ai-message-${role}${typing ? " ai-message-typing" : ""}`;
    m.innerHTML = `<strong>${role === "bot" ? "AI" : "You"}:</strong><span>${esc(text)}</span>`;
    box.appendChild(m);
    box.scrollTop = box.scrollHeight;
    return m;
  }

  // Set the greeting, title and quick questions for whoever is signed in.
  let shownFor = null;
  function personalize() {
    const u = currentUser() || {};
    const key = `${u.email || ""}|${u.role || ""}`;
    const header = document.querySelector("#aiChatWidget .ai-chat-header h3");
    if (header) header.textContent = `${roleLabel()} Assistant`;
    const chips = document.getElementById("aiSuggestions");
    if (chips) chips.innerHTML = suggestionsText().map((q) => `<button type="button" data-prompt="${esc(q)}">${esc(q.replace(/\?$/, ""))}</button>`).join("");
    if (shownFor === key) return;
    shownFor = key;
    const box = document.getElementById("aiChatMessages");
    if (!box) return;
    box.innerHTML = "";
    const n = firstName();
    say("bot", `Hi${n ? " " + n : ""}! I'm your ${roleLabel()} assistant. Ask me how to do something, or tap a question below.`);
  }

  function onOpen() {
    personalize();
    loadData().then(() => personalize());
    const input = document.getElementById("aiChatInput");
    if (input) window.setTimeout(() => input.focus(), 50);
  }

  function init() {
    addStyle();
    if (IS_EMPLOYEE_PAGE) buildEmployeeWidget();
    const button = document.getElementById("floatingAiButton");
    // The admin page already toggles the window in script.js; we only refresh content when it opens.
    if (button && !IS_EMPLOYEE_PAGE) button.addEventListener("click", () => window.setTimeout(() => {
      const w = document.getElementById("aiChatWidget");
      if (w && !w.hidden) onOpen();
    }, 0));
    if (roleGroup() !== "GUEST") loadData();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
