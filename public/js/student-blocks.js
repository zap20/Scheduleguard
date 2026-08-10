(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Student"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("blocks-alert");

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function statusBadge(status) {
    const active = String(status).toLowerCase() === "active";
    const cls = active ? "status-wrong" : "status-present";
    return '<span class="status-badge ' + cls + '">' + escapeHtml(status) + "</span>";
  }

  async function load() {
    try {
      const result = await Api.api("/student/blocks.php");
      const cleared = !!result.data.cleared;
      const blocks = result.data.blocks || [];
      const active = result.data.activeBlock;

      const chip = document.getElementById("status-chip");
      chip.textContent = cleared ? "Cleared" : "Blocked";
      chip.style.background = cleared
        ? "rgba(6, 118, 71, 0.12)"
        : "rgba(180, 35, 24, 0.12)";
      chip.style.color = cleared ? "var(--ok)" : "var(--danger)";

      const reasonPanel = document.getElementById("active-reason");
      if (!cleared && active) {
        reasonPanel.hidden = false;
        reasonPanel.innerHTML =
          '<div class="conflict-title">Active block reason</div>' +
          "<p>" +
          escapeHtml(active.reason) +
          "</p>";
      } else {
        reasonPanel.hidden = true;
        reasonPanel.innerHTML = "";
      }

      document.getElementById("result-count").textContent = String(blocks.length);
      const body = document.getElementById("blocks-body");
      const empty = document.getElementById("blocks-empty");

      if (!blocks.length) {
        body.innerHTML = "";
        empty.hidden = false;
        return;
      }

      empty.hidden = true;
      body.innerHTML = blocks
        .map(function (row) {
          return (
            "<tr>" +
            "<td>" +
            statusBadge(row.status) +
            "</td>" +
            "<td class=\"reason-cell\">" +
            escapeHtml(row.reason) +
            "</td>" +
            "<td>" +
            escapeHtml(row.departmentName) +
            "</td>" +
            "<td>" +
            escapeHtml(row.issuedByName) +
            "</td>" +
            "<td>" +
            escapeHtml(Att.formatTimestamp(row.createdAt)) +
            "</td>" +
            "</tr>"
          );
        })
        .join("");
    } catch (err) {
      alertEl.textContent = err.message || "Unable to load blocking status.";
      alertEl.classList.add("show");
    }
  }

  load();
})();
