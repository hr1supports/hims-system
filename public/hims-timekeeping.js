/*
 * HIMS timekeeping (admin page)
 * ---------------------------------------------------------------------------
 * - Timekeeping Approvals page (HR Officer and Admin): approve or reject employee
 *   leave and overtime, verify attendance records, and see each employee's month.
 * - Monthly Timekeeping Summary (HR Staff, in Overtime Encoding) now reads the database,
 *   so every computer shows the same hours.
 * - Overtime approved in HR Requests, and overtime approved through ESS Requests,
 *   is saved to the database so it reaches the employee's payslip.
 * Data: backend/timekeeping.php (?resource=timekeeping).
 */
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v == null ? "" : v).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const toastOk = (m) => (window.showSuccessToast || console.log)(m);
  const toastErr = (e) => (window.showWarningToast || console.warn)(e && e.message ? e.message : String(e));
  const thisMonth = () => new Date().toISOString().slice(0, 7);
  const num = (n) => (Math.round(Number(n || 0) * 100) / 100).toString();
  const tk = (query) => window.apiRequest("timekeeping", { query });
  const tkPost = (body) => window.apiRequest("timekeeping", { method: "POST", body: JSON.stringify(body) });

  function addStyle() {
    if ($("himsTimekeepingStyle")) return;
    const s = document.createElement("style");
    s.id = "himsTimekeepingStyle";
    s.textContent = `
      .tk-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0 16px; }
      .tk-tabs button { border-radius: 999px; padding: 8px 14px; }
      .tk-tabs button.is-active { outline: 2px solid #18b57f; outline-offset: 1px; }
      .tk-section { margin: 18px 0 26px; }
      .tk-section h4 { margin: 0 0 4px; }
      .tk-section > p { margin: 0 0 10px; opacity: .8; font-size: .88rem; }
      .tk-actions { white-space: nowrap; }
      .tk-actions button { margin: 2px; }
      .tk-toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
      .tk-toolbar input[type=month] { max-width: 200px; }
      .tk-note { font-size: .82rem; opacity: .8; margin-top: 8px; }
      .tk-bad { color: #d9534f; font-weight: 700; }
      .tk-warn { color: #d39e00; font-weight: 700; }
      .request-table td small { display: block; opacity: .8; }
    `;
    document.head.appendChild(s);
  }

  // ---------------------------------------------------------------------
  // Monthly summary table (used on Timekeeping Approvals and in Overtime Encoding)
  // ---------------------------------------------------------------------
  function summaryTable(data) {
    const rows = data.records || [];
    if (!rows.length) return '<p class="table-empty">No employees on record yet.</p>';
    const verifyCol = rows.some((r) => r.unverified !== null);
    return `<div class="request-table-wrap"><table class="request-table">
      <thead><tr><th>Employee</th><th>Days present</th><th>Hours worked</th><th>Regular hrs</th><th>Undertime</th><th>Overtime (approved)</th><th>Leave days</th><th>Absences</th>${verifyCol ? "<th>Not verified</th>" : ""}</tr></thead>
      <tbody>${rows.map((r) => `<tr>
        <td><strong>${esc(r.name)}</strong><small>${esc(r.code)}${r.department ? " · " + esc(r.department) : ""}</small></td>
        <td>${r.daysPresent} / ${r.workDays}${r.openRecords ? `<small class="tk-warn">${r.openRecords} without time-out</small>` : ""}</td>
        <td>${num(r.hours)}</td>
        <td>${num(r.regularHours)}</td>
        <td>${r.undertimeHours ? `<span class="tk-warn">${num(r.undertimeHours)} h</span>` : "0"}</td>
        <td>${num(r.overtimeApproved)} h${r.overtimePending ? `<small>${num(r.overtimePending)} h pending</small>` : ""}</td>
        <td>${r.leaveDays}${r.pendingLeave ? `<small>${r.pendingLeave} pending</small>` : ""}</td>
        <td>${r.countedFrom ? (r.absences ? `<span class="tk-bad">${r.absences}</span><small>${esc(r.absentDates.slice(0, 4).map((d) => d.slice(5)).join(", "))}${r.absentDates.length > 4 ? "…" : ""}</small>` : "0") : '<small>No attendance yet</small>'}</td>
        ${verifyCol ? `<td>${r.unverified ? `<span class="tk-warn">${r.unverified}</span>` : "0"}</td>` : ""}
      </tr>`).join("")}</tbody></table></div>
      <p class="tk-note">Work days are Monday to Friday, minus Philippine holidays. A regular day is ${esc(data.dayHours || 8)} hours; less is undertime. Absences count work days that have passed with no attendance, approved leave or rest day, starting from the hire date or first attendance.</p>`;
  }

  async function loadSummaryInto(target, month) {
    target.innerHTML = '<p class="table-empty">Loading...</p>';
    try {
      const data = await tk({ view: "summary", month });
      target.innerHTML = summaryTable(data);
    } catch (error) {
      target.innerHTML = `<p class="table-empty">${esc(error.message || "Summary could not be loaded.")}</p>`;
    }
  }

  // Overtime Encoding > Monthly Timekeeping Summary: from the database, with a month picker.
  function upgradeStaffSummary() {
    const list = $("summaryList");
    if (!list || $("tkStaffMonth")) return;
    const button = list.parentElement.querySelector('button[onclick*="showTimekeepingSummary"]');
    const picker = document.createElement("input");
    picker.type = "month";
    picker.id = "tkStaffMonth";
    picker.value = thisMonth();
    picker.setAttribute("aria-label", "Summary month");
    picker.style.maxWidth = "200px";
    picker.style.marginRight = "8px";
    if (button) button.parentElement.insertBefore(picker, button);
    picker.addEventListener("change", () => window.showTimekeepingSummary());
  }
  window.showTimekeepingSummary = function () {
    addStyle();
    upgradeStaffSummary();
    const list = $("summaryList");
    if (list) loadSummaryInto(list, ($("tkStaffMonth") || {}).value || thisMonth());
  };

  // ---------------------------------------------------------------------
  // Timekeeping Approvals page
  // ---------------------------------------------------------------------
  let tab = "pending";
  let summaryMonth = thisMonth();

  async function renderApprovals() {
    const root = $("tkRoot");
    if (!root) return;
    addStyle();
    root.innerHTML = `<div class="tk-tabs" role="tablist">
        <button type="button" data-tk-tab="pending" class="${tab === "pending" ? "is-active" : "secondary-action"}">Waiting for decision</button>
        <button type="button" data-tk-tab="summary" class="${tab === "summary" ? "is-active" : "secondary-action"}">Monthly summary</button>
      </div><div id="tkBody"><p class="table-empty">Loading...</p></div>`;
    if (tab === "summary") {
      $("tkBody").innerHTML = `<div class="tk-toolbar"><label>Month <input type="month" id="tkMonth" value="${esc(summaryMonth)}"></label></div><div id="tkSummary"></div>`;
      loadSummaryInto($("tkSummary"), summaryMonth);
      return;
    }
    let data;
    try { data = await tk({ view: "pending" }); }
    catch (error) { $("tkBody").innerHTML = `<p class="table-empty">${esc(error.message || "Could not load.")}</p>`; return; }
    const can = !!data.canDecide;
    const decide = (kind, row) => can
      ? `<td class="tk-actions"><button type="button" data-tk-decide="${kind}" data-id="${Number(row.id)}" data-source="${esc(row.source || "")}" data-status="approved">Approve</button><button type="button" class="danger-action" data-tk-decide="${kind}" data-id="${Number(row.id)}" data-source="${esc(row.source || "")}" data-status="rejected">Reject</button></td>`
      : "<td><small>HR Officer decides</small></td>";
    const kpis = `<div class="hx-kpis" id="tkKpis"></div>`;
    $("tkBody").innerHTML = `${kpis}
      <section class="tk-section"><h4>Leave requests</h4><p>Filed by employees in their portal or encoded by HR Staff. Approved days count as paid leave on the payslip.</p>
        ${data.leave.length ? `<div class="request-table-wrap"><table class="request-table"><thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th>Reason</th><th>Filed</th><th></th></tr></thead><tbody>
          ${data.leave.map((r) => `<tr><td><strong>${esc(r.name)}</strong><small>${esc(r.code)}</small></td><td>${esc(r.type)}<small>${r.source === "hr" ? "Encoded by HR" : "Filed by employee"}</small></td>
            <td>${esc(r.start)} to ${esc(r.end)}<small>${Number(r.days)} day(s)</small></td><td>${esc(r.reason || "-")}</td><td>${esc(r.filed)}</td>${decide("leave", r)}</tr>`).join("")}
        </tbody></table></div>` : '<p class="table-empty">No leave waiting for a decision.</p>'}</section>
      <section class="tk-section"><h4>Overtime</h4><p>Encoded by HR Staff. Approved hours are paid at 125% of the hourly rate on the employee's payslip.</p>
        ${data.overtime.length ? `<div class="request-table-wrap"><table class="request-table"><thead><tr><th>Employee</th><th>Date</th><th>Hours</th><th>Notes</th><th>Filed</th><th></th></tr></thead><tbody>
          ${data.overtime.map((r) => `<tr><td><strong>${esc(r.name || "Unknown employee")}</strong><small>${esc(r.code || "")}</small></td><td>${esc(r.date)}</td><td>${num(r.hours)}</td><td>${esc(r.notes || "-")}</td><td>${esc(r.filed)}</td>${decide("overtime", r)}</tr>`).join("")}
        </tbody></table></div>` : '<p class="table-empty">No overtime waiting for a decision.</p>'}</section>
      <section class="tk-section"><h4>Attendance to verify</h4><p>Completed time-in/time-out records from the last 60 days that nobody has checked yet.</p>
        ${!data.verifyReady ? '<p class="table-empty">Run database/020_timekeeping_verification.sql in MySQL Workbench to turn on attendance verification.</p>'
          : data.attendance.length ? `${can ? '<div class="tk-toolbar"><button type="button" id="tkVerifySelected">Verify selected</button><label><input type="checkbox" id="tkSelectAll"> Select all</label></div>' : ""}
          <div class="request-table-wrap"><table class="request-table"><thead><tr>${can ? "<th></th>" : ""}<th>Employee</th><th>Date</th><th>Time in</th><th>Time out</th><th>Hours</th></tr></thead><tbody>
          ${data.attendance.map((r) => `<tr>${can ? `<td><input type="checkbox" data-tk-att="${Number(r.id)}" aria-label="Select record"></td>` : ""}<td><strong>${esc(r.name)}</strong><small>${esc(r.code)}</small></td><td>${esc(r.date)}</td><td>${esc(r.timeIn)}</td><td>${esc(r.timeOut)}</td>
            <td>${Number(r.hours) < 8 ? `<span class="tk-warn">${num(r.hours)}</span>` : num(r.hours)}</td></tr>`).join("")}
          </tbody></table></div>` : '<p class="table-empty">All attendance records are verified.</p>'}</section>`;
    if (window.HimsCharts && window.HimsCharts.kpis) {
      window.HimsCharts.kpis($("tkKpis"), [
        { label: "Leave waiting", value: data.leave.length, tone: data.leave.length ? "watch" : "main" },
        { label: "Overtime waiting", value: data.overtime.length, tone: data.overtime.length ? "watch" : "main" },
        { label: "Attendance to verify", value: data.verifyReady ? data.attendance.length : "-", tone: data.attendance.length ? "watch" : "main" },
      ]);
    }
  }

  document.addEventListener("click", async (event) => {
    const tabBtn = event.target.closest("[data-tk-tab]");
    if (tabBtn) { tab = tabBtn.dataset.tkTab; renderApprovals(); return; }

    const btn = event.target.closest("[data-tk-decide]");
    if (btn) {
      const kind = btn.dataset.tkDecide, status = btn.dataset.status;
      if (status === "rejected" && !window.confirm(`Reject this ${kind} request?`)) return;
      btn.disabled = true;
      try {
        await tkPost({ action: kind === "leave" ? "leave-decide" : "overtime-decide", id: Number(btn.dataset.id), source: btn.dataset.source, status });
        toastOk(`${kind === "leave" ? "Leave" : "Overtime"} ${status}.`);
        if (window.loadSharedRecords) window.loadSharedRecords().catch(() => null);
        renderApprovals();
      } catch (error) { toastErr(error); btn.disabled = false; }
      return;
    }

    if (event.target.id === "tkVerifySelected") {
      const ids = [...document.querySelectorAll("[data-tk-att]:checked")].map((c) => Number(c.dataset.tkAtt));
      if (!ids.length) { toastErr("Select at least one attendance record."); return; }
      event.target.disabled = true;
      try {
        const r = await tkPost({ action: "attendance-verify", ids });
        toastOk(r.message || "Attendance verified.");
        renderApprovals();
      } catch (error) { toastErr(error); event.target.disabled = false; }
    }
  });
  document.addEventListener("change", (event) => {
    if (event.target.id === "tkSelectAll") {
      document.querySelectorAll("[data-tk-att]").forEach((c) => { c.checked = event.target.checked; });
    }
    if (event.target.id === "tkMonth") {
      summaryMonth = event.target.value || thisMonth();
      loadSummaryInto($("tkSummary"), summaryMonth);
    }
  });

  // ---------------------------------------------------------------------
  // Overtime decisions made on the older pages also go to the database
  // ---------------------------------------------------------------------
  // HR Requests > Approve (overtime encoded by HR Staff)
  const originalApprove = window.approve;
  if (typeof originalApprove === "function") {
    window.approve = function (i) {
      const before = (window.read ? window.read("requests") : [])[i];
      const result = originalApprove.apply(this, arguments);
      if (before && before.rowId && String(before.status || "").toLowerCase() === "pending") {
        tkPost({ action: "overtime-decide", id: Number(before.rowId), status: "approved" })
          .catch((error) => { if (!/already decided/i.test(error.message || "")) toastErr(error); });
      }
      return result;
    };
  }

  function hookLaterFunctions() {
    // ESS Requests > Approve on an "Overtime" request: add the hours to the employee's payroll.
    const originalDecideEss = window.decideEss;
    if (typeof originalDecideEss === "function" && !originalDecideEss.tkWrapped) {
      const wrapped = function (id, status) {
        const result = originalDecideEss.apply(this, arguments);
        if (status !== "Approved") return result;
        let all = [];
        try { all = JSON.parse(localStorage.getItem("essRequests") || "[]"); } catch (e) { return result; }
        const r = all.find((x) => x.id === id);
        if (!r || r.status !== "Approved" || r.type !== "Overtime" || !Number(r.hours) || r.payrollSaved) return result;
        tkPost({ action: "ess-overtime", employeeCode: r.empId, hours: Number(r.hours), date: r.neededBy || "" })
          .then(() => {
            const fresh = JSON.parse(localStorage.getItem("essRequests") || "[]");
            const rec = fresh.find((x) => x.id === id);
            if (rec) { rec.payrollSaved = true; localStorage.setItem("essRequests", JSON.stringify(fresh)); }
            toastOk(`${r.hours} overtime hour(s) added to ${r.name}'s payroll.`);
          })
          .catch(toastErr);
        return result;
      };
      wrapped.tkWrapped = true;
      window.decideEss = wrapped;
    }

    // Open the page: render it.
    const previousShow = window.showModule;
    if (typeof previousShow === "function" && !previousShow.tkWrapped) {
      const show = function (id) {
        const result = previousShow.apply(this, arguments);
        if (id === "tkapprovals") renderApprovals();
        if (id === "timesheet") upgradeStaffSummary();
        return result;
      };
      show.tkWrapped = true;
      window.showModule = show;
    }
  }

  window.HimsTimekeeping = { renderApprovals, loadSummaryInto };
  // Runs after the page's own scripts, which define decideEss and wrap showModule.
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", hookLaterFunctions);
  else hookLaterFunctions();
})();
