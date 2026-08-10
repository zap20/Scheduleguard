(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const user = await Att.requireRole(["HR"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const form = document.getElementById("filter-form");
  const alertEl = document.getElementById("attendance-alert");
  const deptSelect = document.getElementById("departmentId");

  function queryString() {
    const params = new URLSearchParams();
    const departmentId = deptSelect.value;
    const dateFrom = form.dateFrom.value;
    const dateTo = form.dateTo.value;
    if (departmentId) params.set("departmentId", departmentId);
    if (dateFrom) params.set("dateFrom", dateFrom);
    if (dateTo) params.set("dateTo", dateTo);
    const qs = params.toString();
    return qs ? "?" + qs : "";
  }

  async function load() {
    alertEl.classList.remove("show");
    try {
      const result = await window.ScheduleGuardApi.api(
        "/attendance/review.php" + queryString()
      );
      Att.renderRows(result.data.records || [], { showFaculty: true });
    } catch (err) {
      alertEl.textContent = err.message || "Unable to load attendance review.";
      alertEl.classList.add("show");
    }
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    load();
  });

  Att.loadDepartments(deptSelect)
    .then(load)
    .catch(function (err) {
      alertEl.textContent = err.message || "Unable to load departments.";
      alertEl.classList.add("show");
    });
})();
