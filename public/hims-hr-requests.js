/*
 * HIMS HR Requests (integration with HR2, HR3 and HR4)
 * ---------------------------------------------------------------------------
 * The "From HR2 / HR3 / HR4" part of the HR Requests page. The other HR systems send
 * requests to HIMS (backend: ?resource=integration-requests). An HR Officer or Admin approves
 * or rejects them here, and the sending system reads the decision back with its key.
 * The "Overtime encoded by HR Staff" part below it is still drawn by showRequests() in script.js.
 * Data: backend/file-exchange.php (?resource=hr-requests).
 */
(function () {
  "use strict";

  const PARTNERS = ["HR2", "HR3", "HR4", "ADMIN"];
  const LABEL = { ADMIN: "Administrators" };
  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v == null ? "" : v).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const toastOk = (m) => (window.showSuccessToast || console.log)(m);
  const toastErr = (e) => (window.showWarningToast || console.warn)(e && e.message ? e.message : String(e));
  const role = () => { try { return (JSON.parse(localStorage.getItem("currentUser") || "null") || {}).role || ""; } catch (e) { return ""; } };
  const canDecide = () => ["HR1", "HR3", "ADMIN", "HR_OFFICER"].includes(role());

  const state = { status: "Pending", partner: "" };

  function addStyle() {
    if ($("himsHrRequestsStyle")) return;
    const s = document.createElement("style");
    s.id = "himsHrRequestsStyle";
    s.textContent = `
      .pr-toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 4px 0 12px; }
      .pr-sep { width: 1px; align-self: stretch; background: var(--line); margin: 0 4px; }
      #approval .pr-chip { width: auto; min-width: 0; padding: 6px 14px; border-radius: 999px; }
      #approval .pr-chip:not(.is-active) { background: var(--panel-soft); color: var(--text); border: 1px solid var(--line); box-shadow: none; }
      .pr-count { display: inline-block; min-width: 20px; padding: 1px 7px; margin-left: 6px; border-radius: 999px; background: var(--danger); color: #fff; font-size: .74rem; font-weight: 700; line-height: 1.5; }
      .pr-status { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: .78rem; font-weight: 700; }
      .pr-status.is-pending { background: rgba(211,158,0,.14); color: #a37800; }
      .pr-status.is-approved { background: var(--green-soft); color: var(--green-dark); }
      .pr-status.is-rejected { background: rgba(239,68,68,.12); color: var(--danger); }
      #approval .request-table td small { display: block; opacity: .8; }
      .pr-actions { white-space: nowrap; }
      .pr-actions button { margin: 2px; padding: 6px 10px; }
      #approval .hx-card { margin-top: 16px; }
    `;
    document.head.appendChild(s);
  }

  async function load() {
    const box = $("partnerRequests");
    if (!box) return;
    addStyle();
    if (!canDecide()) { box.innerHTML = '<p class="table-empty">Only an HR Officer or Admin can review these requests.</p>'; return; }
    box.innerHTML = '<p class="table-empty">Loading...</p>';
    try {
      const query = { status: state.status };
      if (state.partner) query.partner = state.partner;
      const result = await window.apiRequest("hr-requests", { query });
      const rows = result.records || [];
      const pending = result.pending || {};
      const chip = (attr, value, label, count) =>
        `<button type="button" class="pr-chip ${(attr === "status" ? state.status : state.partner) === value ? "is-active" : ""}" data-pr-${attr}="${value}">${label}${count ? `<span class="pr-count">${count}</span>` : ""}</button>`;
      const toolbar = `<div class="pr-toolbar">
        ${chip("status", "Pending", "Pending", result.pendingTotal)}${chip("status", "Approved", "Approved")}${chip("status", "Rejected", "Rejected")}${chip("status", "all", "All")}
        <span class="pr-sep"></span>
        ${chip("partner", "", "All systems")}${PARTNERS.map((p) => chip("partner", p, LABEL[p] || p, pending[p])).join("")}
      </div>`;
      if (!rows.length) {
        box.innerHTML = toolbar + `<p class="table-empty">${state.status === "Pending"
          ? "No requests waiting. Requests sent by HR2, HR3, HR4 or the Administrators will show here."
          : "No requests to show."}</p>`;
        return;
      }
      box.innerHTML = toolbar + `<div class="request-table-wrap"><table class="request-table">
        <thead><tr><th>From</th><th>Request</th><th>Employee</th><th>Details</th><th>Received</th><th>Status</th><th></th></tr></thead>
        <tbody>${rows.map((r) => `<tr>
          <td><strong>${esc(LABEL[r.partner] || r.partner)}</strong>${r.requestedBy ? `<small>${esc(r.requestedBy)}</small>` : ""}<small>Ref: ${esc(r.reference)}</small></td>
          <td><strong>${esc(r.type)}</strong><small>${esc(r.title)}</small></td>
          <td>${r.employeeName || r.employeeCode ? `${esc(r.employeeName || "-")}<small>${esc(r.employeeCode || "")}</small>` : "-"}</td>
          <td>${r.details ? esc(r.details) : ""}${r.hours !== null && r.hours !== undefined ? `<small>${esc(r.hours)} hour(s)</small>` : ""}${r.requestDate ? `<small>Date: ${esc(r.requestDate)}</small>` : ""}${!r.details && r.hours == null && !r.requestDate ? "-" : ""}</td>
          <td>${esc(r.received)}</td>
          <td><span class="pr-status is-${esc(String(r.status).toLowerCase())}">${esc(r.status)}</span>${r.decidedBy ? `<small>by ${esc(r.decidedBy)} · ${esc(r.decidedAt)}</small>` : ""}${r.remark ? `<small>“${esc(r.remark)}”</small>` : ""}</td>
          <td class="pr-actions">${r.status === "Pending"
            ? `<button type="button" data-pr-decide="Approved" data-pr-id="${Number(r.id)}">Approve</button><button type="button" class="secondary-action" data-pr-decide="Rejected" data-pr-id="${Number(r.id)}">Reject</button>`
            : ""}</td>
        </tr>`).join("")}</tbody></table></div>`;
    } catch (e) {
      box.innerHTML = `<p class="table-empty">${esc(e.message)}</p>`;
    }
  }

  async function decide(id, status) {
    const remark = window.prompt(status === "Rejected"
      ? "Reason for rejecting (the sending system will see this):"
      : "Remark for the sending system (optional):", "");
    if (remark === null) return false; // cancelled
    if (status === "Rejected" && !remark.trim()) { toastErr("Enter a reason so the other team knows why it was rejected."); return false; }
    try {
      const result = await window.apiRequest("hr-requests", {
        method: "POST",
        headers: { "X-HR3-Request": "1" },
        body: JSON.stringify({ action: "decide", id: Number(id), status, remark: remark.trim() }),
      });
      toastOk(result.message);
      if (window.addNotification) window.addNotification(`HR request #${id} ${status.toLowerCase()}.`);
      return true;
    } catch (e) {
      toastErr(e);
      return false;
    }
  }
  window.decidePartnerRequest = decide;

  document.addEventListener("click", async (event) => {
    if (!event.target.closest || !event.target.closest("#partnerRequests")) return;
    const t = event.target.closest("[data-pr-status],[data-pr-partner],[data-pr-decide]");
    if (!t) return;
    if (t.dataset.prStatus !== undefined) { state.status = t.dataset.prStatus; load(); return; }
    if (t.dataset.prPartner !== undefined) { state.partner = t.dataset.prPartner; load(); return; }
    if (t.dataset.prDecide) {
      t.disabled = true;
      await decide(t.dataset.prId, t.dataset.prDecide);
      load();
    }
  });

  // "Refresh" on the page calls loadHRRequests(): reload both parts.
  const originalLoad = window.loadHRRequests;
  window.loadHRRequests = function () {
    if (typeof originalLoad === "function") originalLoad.apply(this, arguments);
    load();
  };

  function hook() {
    const previous = window.showModule;
    if (typeof previous !== "function" || previous.__pr) return;
    const show = function (id) {
      const result = previous.apply(this, arguments);
      if (id === "approval") {
        if (typeof window.showRequests === "function") { try { window.showRequests(); } catch (e) { /* page not ready */ } }
        load();
      }
      return result;
    };
    show.__pr = true;
    window.showModule = show;
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => setTimeout(hook, 0));
  else setTimeout(hook, 0);
})();
