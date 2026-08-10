(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Student"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("schedule-alert");
  const gridEl = document.getElementById("schedule-grid");
  const emptyEl = document.getElementById("schedule-empty");

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
      const room = (row.roomName || row.roomLabel || "").trim();
      if (room) parts.push(room);
      return {
        day: row.day,
        startTime: row.startTime,
        endTime: row.endTime,
        label: parts.join(" · "),
      };
    });
  }

  async function load() {
    try {
      const result = await Api.api("/student/schedule.php");
      const rows = result.data.schedules || [];
      const term = result.data.term;
      const termChip = document.getElementById("term-chip");
      if (termChip && term) {
        termChip.textContent = term.label || "Sem " + term.semester;
      }
      document.getElementById("result-count").textContent = String(rows.length);
      const body = document.getElementById("schedule-body");

      if (!rows.length) {
        gridEl.innerHTML = "";
        body.innerHTML = "";
        emptyEl.hidden = false;
        return;
      }

      emptyEl.hidden = true;
      gridEl.innerHTML = window.renderScheduleGrid(toGridBlocks(rows));

      body.innerHTML = rows
        .map(function (row) {
          return (
            "<tr>" +
            "<td class=\"cell-strong\">" +
            escapeHtml(row.subjectCode) +
            '<div class="cell-muted">' +
            escapeHtml(row.subjectName) +
            "</div></td>" +
            "<td>" +
            escapeHtml(instructorLabel(row)) +
            "</td>" +
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
      alertEl.textContent = err.message || "Unable to load schedule.";
      alertEl.classList.add("show");
    }
  }

  load();
})();
