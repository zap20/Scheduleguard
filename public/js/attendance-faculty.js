(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const user = await Att.requireRole(["Faculty"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("attendance-alert");

  async function load() {
    alertEl.classList.remove("show");
    try {
      const result = await window.ScheduleGuardApi.api("/attendance/mine.php");
      Att.renderRows(result.data.records || [], { showFaculty: false });
    } catch (err) {
      alertEl.textContent = err.message || "Unable to load attendance.";
      alertEl.classList.add("show");
    }
  }

  load();
})();
