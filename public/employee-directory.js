// Admin module: read-only employee table. Accounts are created by the Super Admin.
(function () {
  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v == null ? "" : v).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  let employees = [];

  const fromDatabase = (row) => ({
    id: row.employee_code,
    name: `${row.first_name} ${row.last_name}`.trim(),
    email: row.email || "",
    position: row.position || "",
    department: row.department || "",
    contact: row.contact || "",
    status: row.status === "on_leave" ? "On Leave" : row.status === "active" ? "Active" : "Inactive",
    hireDate: row.hire_date || "",
  });

  async function loadEmployeeDirectory() {
    try {
      const result = await apiRequest("employees");
      employees = (result.records || []).map(fromDatabase);
      $("edMsg").textContent = "";
    } catch (error) {
      employees = [];
      $("edMsg").textContent = error.message || "Employee records could not be loaded.";
      $("edMsg").style.color = "#ff7b7b";
    }
    renderEmployeeDirectory();
  }

  window.exportEmployeeDirectory = function () {
    const rows = [["Employee ID", "Name", "Position", "Department", "Status", "Date Hired", "Contact"]].concat(
      employees.map((e) => [e.id, e.name, e.position, e.department, e.status, e.hireDate, e.contact]));
    const csv = rows.map((r) => r.map((c) => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")).join("\n");
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8;" }));
    a.download = "employee-directory.csv"; a.click(); URL.revokeObjectURL(a.href);
  };

  function renderEmployeeDirectory() {
    const q = ($("edSearch").value || "").toLowerCase();
    const rows = employees.filter((e) => [e.id, e.name, e.department, e.position].join(" ").toLowerCase().includes(q));
    $("edTable").innerHTML = rows.length ? rows.map((e) => `<tr><td>${esc(e.id)}</td>
      <td><strong>${esc(e.name)}</strong><small>${esc(e.email)}</small></td>
      <td>${esc(e.position)}</td><td>${esc(e.department)}</td>
      <td><span class="request-status">${esc(e.status)}</span></td>
      <td>${esc(e.hireDate || "-")}</td><td>${esc(e.contact || "-")}</td></tr>`).join("")
      : '<tr><td colspan="7" class="table-empty">No matching employees.</td></tr>';
  }
  window.renderEmployeeDirectory = renderEmployeeDirectory;

  const prevShow = window.showModule;
  window.showModule = function (id) {
    prevShow(id);
    if (id === "empdir") loadEmployeeDirectory();
  };
  $("edSearch").addEventListener("input", renderEmployeeDirectory);
})();
