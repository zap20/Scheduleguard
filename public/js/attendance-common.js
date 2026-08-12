(function (window) {
  "use strict";

  const STATUS_CLASS = {
    Present: "status-present",
    Late: "status-late",
    WrongRoom: "status-wrong",
    NoSchedule: "status-none",
    Absent: "status-absent",
  };

  /**
   * Refresh the signed-in user from the server (role/status are never trusted
   * from localStorage alone), then enforce client-side role gating.
   * Returns a Promise resolving to the user object, or null after redirect.
   */
  async function requireRole(allowedRoles) {
    const Api = window.ScheduleGuardApi;
    if (!Api.getToken()) {
      window.location.href = "index.html";
      return null;
    }

    try {
      const result = await Api.api("/auth/me.php", { method: "GET" });
      if (!result || !result.data || !result.data.user) {
        throw new Error((result && result.error) || "Session response was incomplete.");
      }
      const user = result.data.user;
      Api.setSession(user, Api.getToken());

      if (!allowedRoles.includes(user.role)) {
        window.location.href = "app.html";
        return null;
      }
      if (user.status && user.status !== "Active") {
        Api.clearSession();
        window.location.href = "index.html";
        return null;
      }
      return user;
    } catch (err) {
      Api.clearSession();
      window.location.href = "index.html";
      return null;
    }
  }

  function formatTimestamp(value) {
    if (!value) return "—";
    const d = new Date(value.replace(" ", "T"));
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  function statusBadge(status) {
    const cls = STATUS_CLASS[status] || "status-none";
    return '<span class="status-badge ' + cls + '">' + escapeHtml(status) + "</span>";
  }

  function formatCounted(counted) {
    if (!counted) return "—";
    const late = Number(counted.lateMinutes) || 0;
    const absent = Number(counted.absentMinutes) || 0;
    if (late <= 0 && absent <= 0) return "0";
    const parts = [];
    if (absent > 0) parts.push(absent + "m abs");
    if (late > 0) parts.push(late + "m late");
    return parts.join(" · ");
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function renderRows(records, options) {
    const showFaculty = options && options.showFaculty;
    const tbody = document.getElementById("attendance-body");
    const empty = document.getElementById("attendance-empty");
    const countEl = document.getElementById("result-count");

    if (!tbody) return;

    if (countEl) {
      countEl.textContent = String(records.length);
    }

    if (!records.length) {
      tbody.innerHTML = "";
      if (empty) empty.hidden = false;
      return;
    }

    if (empty) empty.hidden = true;

    tbody.innerHTML = records
      .map(function (row) {
        const facultyCell = showFaculty
          ? "<td><div class=\"cell-strong\">" +
            escapeHtml(row.faculty.fullName) +
            "</div><div class=\"cell-muted\">" +
            escapeHtml(row.faculty.email) +
            "</div></td>"
          : "";

        return (
          "<tr>" +
          facultyCell +
          "<td><div class=\"cell-strong\">" +
          escapeHtml(row.schedule.subjectName) +
          "</div><div class=\"cell-muted\">" +
          escapeHtml(row.department.name) +
          "</div></td>" +
          "<td>" +
          escapeHtml(row.room.label) +
          "</td>" +
          "<td>" +
          escapeHtml(row.schedule.expectedLabel) +
          "</td>" +
          "<td>" +
          escapeHtml(formatTimestamp(row.timestamp)) +
          "</td>" +
          "<td>" +
          statusBadge(row.status) +
          (row.isOffline ? '<span class="offline-tag">offline</span>' : "") +
          "</td>" +
          "<td>" +
          formatCounted(row.counted) +
          "</td>" +
          "<td>" +
          escapeHtml(row.checker.fullName) +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  async function loadDepartments(selectEl) {
    if (!selectEl) return;
    const result = await window.ScheduleGuardApi.api("/departments/index.php");
    const departments = result.data.departments || [];
    departments.forEach(function (dept) {
      const opt = document.createElement("option");
      opt.value = dept.uid;
      opt.textContent = dept.name;
      selectEl.appendChild(opt);
    });
  }

  function bindLogout(button) {
    if (!button) return;
    button.addEventListener("click", async function () {
      button.disabled = true;
      try {
        await window.ScheduleGuardApi.api("/auth/logout.php", {
          method: "POST",
          body: "{}",
        });
      } catch {
        /* ignore */
      }
      window.ScheduleGuardApi.clearSession();
      window.location.href = "index.html";
    });
  }

  window.ScheduleGuardAttendance = {
    requireRole,
    renderRows,
    loadDepartments,
    bindLogout,
    formatTimestamp,
  };
})(window);
