(function () {
  "use strict";

  const Api = window.ScheduleGuardApi;
  const root = document.getElementById("dashboard-root");
  const alertEl = document.getElementById("dash-alert");
  const navEl = document.getElementById("role-nav");
  const logoutBtn = document.getElementById("logout-btn");

  const NAV = {
    Faculty: [
      { href: "faculty-schedule.html", label: "Faculty schedule" },
      { href: "attendance-faculty.html", label: "My attendance" },
    ],
    Student: [
      { href: "student-schedule.html", label: "Student schedule" },
      { href: "student-blocks.html", label: "Blocking status" },
    ],
    Dean: [
      { href: "curriculum.html", label: "Curriculum" },
      { href: "rooms.html", label: "Rooms" },
      { href: "student-schedule-dean.html", label: "Schedules" },
      { href: "users.html", label: "Users" },
      { href: "attendance-dean.html", label: "Attendance" },
      { href: "blocking.html", label: "Blocking" },
      { href: "audit.html", label: "Audit" },
    ],
    HR: [
      { href: "attendance-hr.html", label: "Attendance review" },
      { href: "blocking.html", label: "Blocking list" },
    ],
    ProgramHead: [
      { href: "enrollment.html", label: "Enrollment" },
      { href: "blocking.html", label: "Blocking" },
    ],
  };

  const BLURBS = {
    Faculty: "Your attendance snapshot and confirmed teaching load.",
    Student: "Clearance status and classes distributed to you.",
    Dean: "Conflicts, attendance oversight, and admin shortcuts.",
    HR: "Campus-wide faculty attendance at a glance.",
    ProgramHead: "Pending enrollments, blocks, and distribution progress.",
  };

  const charts = [];

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function showError(message) {
    alertEl.textContent = message;
    alertEl.classList.add("show");
  }

  function renderNav(role) {
    navEl.innerHTML = "";
    (NAV[role] || []).forEach(function (link) {
      const a = document.createElement("a");
      a.href = link.href;
      a.textContent = link.label;
      navEl.appendChild(a);
    });
  }

  function widget(title, bodyHtml, footerHref, footerLabel, extraClass) {
    return (
      '<article class="dash-card ' +
      (extraClass || "") +
      '">' +
      "<h3>" +
      escapeHtml(title) +
      "</h3>" +
      '<div class="dash-card-body">' +
      bodyHtml +
      "</div>" +
      (footerHref
        ? '<a class="dash-card-link" href="' +
          escapeHtml(footerHref) +
          '">' +
          escapeHtml(footerLabel || "Open full view") +
          " →</a>"
        : "") +
      "</article>"
    );
  }

  function listPreview(items, emptyText, lineFn) {
    if (!items || !items.length) {
      return '<p class="dash-empty">' + escapeHtml(emptyText) + "</p>";
    }
    return (
      "<ul class=\"dash-list\">" +
      items
        .map(function (item) {
          return "<li>" + lineFn(item) + "</li>";
        })
        .join("") +
      "</ul>"
    );
  }

  function metric(label, value) {
    return (
      '<div class="dash-metric"><span class="dash-metric-value">' +
      escapeHtml(String(value)) +
      '</span><span class="dash-metric-label">' +
      escapeHtml(label) +
      "</span></div>"
    );
  }

  function shortcutButtons(shortcuts) {
    return (
      '<div class="dash-shortcuts">' +
      shortcuts
        .map(function (s) {
          return (
            '<a class="btn ' +
            (s.tone === "primary" ? "" : "btn-secondary") +
            '" style="width:auto;min-width:0" href="' +
            escapeHtml(s.href) +
            '">' +
            escapeHtml(s.label) +
            "</a>"
          );
        })
        .join("") +
      "</div>"
    );
  }

  function makeChart(canvasId, config) {
    const el = document.getElementById(canvasId);
    if (!el || typeof Chart === "undefined") return;
    charts.push(
      new Chart(el, {
        ...config,
        options: Object.assign(
          {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: "bottom" } },
          },
          config.options || {}
        ),
      })
    );
  }

  function renderFaculty(d) {
    root.innerHTML =
      widget(
        "Attendance status",
        metric("Scans on record", d.attendance.total) +
          '<div class="chart-wrap"><canvas id="chart-attendance"></canvas></div>' +
          listPreview(
            d.attendance.recent,
            "No attendance scans yet.",
            function (row) {
              return (
                "<strong>" +
                escapeHtml(row.subjectName) +
                "</strong> — " +
                escapeHtml(row.status)
              );
            }
          ),
        d.attendance.href,
        "Open attendance view"
      ) +
      widget(
        "My schedule",
        metric("Confirmed classes", d.schedule.total) +
          listPreview(
            d.schedule.preview,
            "No confirmed teaching assignments.",
            function (row) {
              return (
                "<strong>" +
                escapeHtml(row.subjectCode || row.subjectName) +
                "</strong><br><span class=\"cell-muted\">" +
                escapeHtml(row.blockName ? row.blockName + " · " : "") +
                escapeHtml(row.day) +
                " " +
                escapeHtml(row.startTime) +
                "–" +
                escapeHtml(row.endTime) +
                " · " +
                escapeHtml(row.roomLabel) +
                "</span>"
              );
            }
          ),
        d.schedule.href,
        "Open teaching schedule"
      );

    makeChart("chart-attendance", {
      type: "doughnut",
      data: {
        labels: d.attendance.byStatus.labels,
        datasets: [
          {
            data: d.attendance.byStatus.values,
            backgroundColor: ["#2e7d4f", "#f67b56", "#c62828", "#6b6b6b"],
          },
        ],
      },
    });
  }

  function renderStudent(d) {
    const blocked = !d.blocking.cleared;
    root.innerHTML =
      widget(
        "Blocking status",
        '<div class="dash-status ' +
          (blocked ? "is-blocked" : "is-clear") +
          '">' +
          escapeHtml(d.blocking.status) +
          "</div>" +
          (blocked && d.blocking.reason
            ? '<p class="dash-reason"><strong>Reason:</strong> ' +
              escapeHtml(d.blocking.reason) +
              "</p>"
            : '<p class="dash-empty">You are cleared for enrollment.</p>'),
        d.blocking.href,
        "View blocking details",
        blocked ? "dash-card-alert" : ""
      ) +
      widget(
        "Finalized schedule",
        metric("Distributed classes", d.schedule.total) +
          listPreview(
            d.schedule.preview,
            "No distributed classes yet.",
            function (row) {
              return (
                "<strong>" +
                escapeHtml(row.subjectCode || row.subjectName) +
                "</strong><br><span class=\"cell-muted\">" +
                escapeHtml(row.instructor || row.facultyName || "TBF") +
                (row.blockName ? " · " + escapeHtml(row.blockName) : "") +
                " · " +
                escapeHtml(row.day) +
                " " +
                escapeHtml(row.startTime) +
                "–" +
                escapeHtml(row.endTime) +
                " · " +
                escapeHtml(row.roomLabel) +
                "</span>"
              );
            }
          ),
        d.schedule.href,
        "Open my schedule"
      );
  }

  function renderDean(d) {
    const conflictBanner =
      d.conflicts.total > 0
        ? '<div class="conflict-banner">' +
          "<strong>" +
          d.conflicts.total +
          " schedule(s) in conflict</strong> need review before confirmation." +
          listPreview(d.conflicts.items, "", function (row) {
            return (
              "<strong>" +
              escapeHtml(row.subjectName) +
              "</strong> — " +
              escapeHtml(row.facultyName) +
              " · " +
              escapeHtml(row.day) +
              " " +
              escapeHtml(row.startTime) +
              "–" +
              escapeHtml(row.endTime)
            );
          }) +
          '<a class="dash-card-link" href="' +
          escapeHtml(d.conflicts.href) +
          '">Resolve in schedule management →</a></div>'
        : '<div class="conflict-banner is-clear">No schedules are currently flagged as conflict.</div>';

    root.innerHTML =
      '<div class="dash-span-2">' +
      conflictBanner +
      "</div>" +
      widget(
        "Schedule workspace",
        shortcutButtons(d.shortcuts) +
          '<div class="dash-metrics-row">' +
          metric("Draft", d.scheduleBreakdown.draft) +
          metric("Conflict", d.scheduleBreakdown.conflict) +
          metric("Confirmed", d.scheduleBreakdown.confirmed) +
          "</div>",
        "student-schedule-dean.html",
        "Open student schedule"
      ) +
      widget(
        "Attendance oversight",
        metric("Total scans", d.attendanceChart.total) +
          '<div class="chart-wrap"><canvas id="chart-attendance"></canvas></div>',
        "attendance-dean.html",
        "Open attendance oversight"
      );

    makeChart("chart-attendance", {
      type: "bar",
      data: {
        labels: d.attendanceChart.labels,
        datasets: [
          {
            label: "Attendance",
            data: d.attendanceChart.values,
            backgroundColor: ["#2e7d4f", "#f67b56", "#c62828", "#6b6b6b"],
          },
        ],
      },
      options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  function renderHr(d) {
    root.innerHTML =
      widget(
        "Faculty attendance summary",
        metric("Total scans", d.attendanceChart.total) +
          '<div class="chart-wrap tall"><canvas id="chart-attendance"></canvas></div>',
        "attendance-hr.html",
        "Open filterable attendance review",
        "dash-span-2"
      ) +
      widget("Shortcuts", shortcutButtons(d.shortcuts));

    makeChart("chart-attendance", {
      type: "doughnut",
      data: {
        labels: d.attendanceChart.labels,
        datasets: [
          {
            data: d.attendanceChart.values,
            backgroundColor: ["#2e7d4f", "#f67b56", "#c62828", "#6b6b6b"],
          },
        ],
      },
    });
  }

  function renderProgramHead(d) {
    root.innerHTML =
      widget(
        "Pending enrollments",
        metric("Cleared students awaiting assignment", d.pendingEnrollments.count) +
          '<p class="dash-empty">Department: ' +
          escapeHtml(d.department.name) +
          "</p>",
        d.pendingEnrollments.href,
        "Open enrollment workspace"
      ) +
      widget(
        "Distribution status",
        '<div class="dash-metrics-row">' +
          metric("Assigned", d.distribution.assigned) +
          metric("Distributed", d.distribution.distributed) +
          "</div>" +
          '<div class="chart-wrap"><canvas id="chart-distribution"></canvas></div>',
        d.distribution.href,
        "Manage distribution"
      ) +
      widget(
        "Blocking by department",
        '<div class="chart-wrap tall"><canvas id="chart-blocking"></canvas></div>',
        d.blockingChart.href,
        "Open blocking management",
        "dash-span-2"
      );

    makeChart("chart-distribution", {
      type: "doughnut",
      data: {
        labels: ["Assigned", "Distributed"],
        datasets: [
          {
            data: [d.distribution.assigned, d.distribution.distributed],
            backgroundColor: ["#f67b56", "#c19be9"],
          },
        ],
      },
    });

    makeChart("chart-blocking", {
      type: "bar",
      data: {
        labels: d.blockingChart.labels,
        datasets: [
          {
            label: "Active",
            data: d.blockingChart.active,
            backgroundColor: "#c62828",
          },
          {
            label: "Cleared",
            data: d.blockingChart.cleared,
            backgroundColor: "#2e7d4f",
          },
        ],
      },
      options: {
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  function renderDashboard(role, dashboard) {
    document.getElementById("dash-title").textContent = role + " dashboard";
    document.getElementById("dash-blurb").textContent =
      BLURBS[role] || "Your ScheduleGuard workspace.";

    if (role === "Faculty") renderFaculty(dashboard);
    else if (role === "Student") renderStudent(dashboard);
    else if (role === "Dean") renderDean(dashboard);
    else if (role === "HR") renderHr(dashboard);
    else if (role === "ProgramHead") renderProgramHead(dashboard);
    else {
      root.innerHTML =
        '<article class="dash-card dash-span-2"><p class="dash-empty">' +
        escapeHtml(
          dashboard.message ||
            "No dashboard widgets are configured for this role."
        ) +
        "</p></article>";
    }
  }

  async function boot() {
    if (!Api.getToken()) {
      window.location.href = "index.html";
      return;
    }

    try {
      const result = await Api.api("/dashboard/summary.php");
      if (!result || !result.data || !result.data.user) {
        throw new Error(
          (result && result.error) ||
            "Dashboard response was incomplete. Try signing in again, or run php database/install.php."
        );
      }
      const user = result.data.user;
      Api.setSession(
        Object.assign({}, Api.getStoredUser() || {}, user),
        Api.getToken()
      );

      document.getElementById("user-name").textContent =
        user.firstName + " " + user.lastName;
      document.getElementById("user-role").textContent = user.role;
      document.getElementById("user-email").textContent = user.email;
      renderNav(user.role);
      renderDashboard(user.role, result.data.dashboard);
    } catch (err) {
      if (err.status === 401 || err.status === 403) {
        Api.clearSession();
        window.location.href = "index.html";
        return;
      }
      showError(err.message || "Unable to load dashboard.");
    }
  }

  logoutBtn.addEventListener("click", async () => {
    logoutBtn.disabled = true;
    try {
      await Api.api("/auth/logout.php", { method: "POST", body: "{}" });
    } catch {
      /* ignore */
    }
    Api.clearSession();
    window.location.href = "index.html";
  });

  boot();
})();
