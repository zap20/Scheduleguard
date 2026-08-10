(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName + " (" + user.role + ")";
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("schedule-alert");
  const gridEl = document.getElementById("schedule-grid");
  const emptyEl = document.getElementById("schedule-empty");
  const bodyEl = document.getElementById("schedule-body");
  const facultySelect = document.getElementById("filter-facultyId");

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function instructorLabel(row) {
    const name = (row.instructor || row.facultyName || "").trim();
    return name !== "" ? name : "TBF";
  }

  function blockLabel(row) {
    return (row.blockName || "").trim() || "—";
  }

  function toGridBlocks(rows) {
    return rows.map(function (row) {
      const parts = [row.subjectCode, instructorLabel(row)];
      if (row.blockName) parts.push(row.blockName);
      return {
        day: row.day,
        startTime: row.startTime,
        endTime: row.endTime,
        label: parts.join(" · "),
      };
    });
  }

  async function loadFaculty() {
    const res = await Api.api("/faculty/index.php");
    const faculty = res.data.faculty || [];
    faculty.forEach(function (f) {
      const opt = document.createElement("option");
      opt.value = f.uid;
      opt.textContent =
        f.fullName + (f.schoolId ? " (" + f.schoolId + ")" : "");
      facultySelect.appendChild(opt);
    });
  }

  async function load() {
    alertEl.classList.remove("show");
    try {
      const params = new URLSearchParams();
      const facultyId = facultySelect.value;
      if (facultyId) params.set("facultyId", facultyId);
      const qs = params.toString() ? "?" + params.toString() : "";
      const result = await Api.api("/schedules/faculty-view.php" + qs);
      const rows = result.data.schedules || [];
      const term = result.data.term;
      const termChip = document.getElementById("term-chip");
      if (termChip && term) {
        termChip.textContent = term.label || "Sem " + term.semester;
      }
      document.getElementById("result-count").textContent = String(rows.length);

      if (!rows.length) {
        gridEl.innerHTML = "";
        bodyEl.innerHTML = "";
        emptyEl.hidden = false;
        return;
      }

      emptyEl.hidden = true;
      gridEl.innerHTML = window.renderScheduleGrid(toGridBlocks(rows));
      bodyEl.innerHTML = rows
        .map(function (row) {
          return (
            "<tr>" +
            "<td class=\"cell-strong\">" +
            escapeHtml(instructorLabel(row)) +
            "</td>" +
            "<td class=\"cell-strong\">" +
            escapeHtml(row.subjectCode) +
            '<div class="cell-muted">' +
            escapeHtml(row.subjectName) +
            "</div></td>" +
            "<td>" +
            escapeHtml(blockLabel(row)) +
            "</td>" +
            "<td>" +
            escapeHtml(row.day) +
            " " +
            escapeHtml(row.startTime) +
            "–" +
            escapeHtml(row.endTime) +
            "</td>" +
            "<td>" +
            escapeHtml(row.roomLabel) +
            "</td>" +
            "</tr>"
          );
        })
        .join("");
    } catch (err) {
      alertEl.textContent = err.message || "Unable to load faculty schedules.";
      alertEl.classList.add("show");
    }
  }

  document.getElementById("filter-form").addEventListener("submit", function (e) {
    e.preventDefault();
    load();
  });

  await loadFaculty();
  await load();
})();
