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
      { href: "curriculum.html", label: "Subjects" },
      { href: "rooms.html", label: "Rooms" },
      { href: "student-schedule-dean.html", label: "Schedules" },
      { href: "users.html", label: "Users" },
      { href: "blocking.html", label: "Blocking" },
      { href: "audit.html", label: "Audit" },
    ],
    HR: [
      { href: "attendance-hr.html", label: "Attendance review" },
      { href: "blocking.html", label: "Blocking list" },
    ],
    ProgramHead: [
      { href: "enrollment.html", label: "Class blocks / Enrollment" },
      { href: "blocking.html", label: "Blocking" },
    ],
  };

  const BLURBS = {
    Faculty: "Your attendance snapshot and confirmed teaching load.",
    Student: "Clearance status and classes distributed to you.",
    Dean: "Attendance oversight and schedule conflict alerts.",
    HR: "Campus-wide faculty attendance at a glance.",
    ProgramHead: "Pending enrollments, blocks, and distribution progress.",
  };

  const charts = [];
  let selectedPeriod = "monthly";
  let currentRole = null;

  function clearCharts() {
    while (charts.length) {
      const chart = charts.pop();
      try {
        chart.destroy();
      } catch (e) {
        /* ignore */
      }
    }
  }

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

  function periodFilterBar(activePeriod, periodMeta) {
    const options = [
      { id: "daily", label: "Daily" },
      { id: "weekly", label: "Weekly" },
      { id: "monthly", label: "Monthly" },
      { id: "semester", label: "Semester" },
      { id: "year", label: "Year" },
    ];
    const range =
      periodMeta && periodMeta.dateFrom && periodMeta.dateTo
        ? periodMeta.dateFrom === periodMeta.dateTo
          ? periodMeta.dateFrom
          : periodMeta.dateFrom + " → " + periodMeta.dateTo
        : "";

    return (
      '<div class="period-filter-bar">' +
      '<div class="period-filter-tabs" role="tablist" aria-label="Attendance period">' +
      options
        .map(function (opt) {
          const active = opt.id === activePeriod ? " is-active" : "";
          return (
            '<button type="button" class="period-filter-tab' +
            active +
            '" data-period="' +
            escapeHtml(opt.id) +
            '" role="tab" aria-selected="' +
            (opt.id === activePeriod ? "true" : "false") +
            '">' +
            escapeHtml(opt.label) +
            "</button>"
          );
        })
        .join("") +
      "</div>" +
      '<span class="period-filter-label">' +
      escapeHtml((periodMeta && periodMeta.label) || "") +
      (range ? " · " + escapeHtml(range) : "") +
      "</span>" +
      "</div>"
    );
  }

  function bindPeriodFilters() {
    root.querySelectorAll("[data-period]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        const next = btn.getAttribute("data-period") || "monthly";
        if (next === selectedPeriod) return;
        selectedPeriod = next;
        loadDashboard(selectedPeriod);
      });
    });
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
            backgroundColor: ["#067647", "#d97706", "#b42318", "#64748b"],
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

  function formatHours(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return "0";
    return n % 1 === 0 ? String(n) : n.toFixed(2);
  }

  function renderDean(d) {
    const conflictBanner =
      d.conflicts.total > 0
        ? '<div class="conflict-banner">' +
          "<strong>" +
          d.conflicts.total +
          " schedule(s) in conflict</strong> need review before confirmation." +
          listPreview(d.conflicts.items, "", function (row) {
            const block =
              row.blockName && String(row.blockName).trim() !== ""
                ? String(row.blockName).trim()
                : "No block";
            return (
              "<strong>" +
              escapeHtml(row.subjectName) +
              "</strong> — " +
              escapeHtml(row.facultyName) +
              " · " +
              escapeHtml(block) +
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

    const labels = d.attendanceChart.labels || [];
    const values = d.attendanceChart.values || [];
    let statusMetrics = "";
    for (let i = 0; i < labels.length; i++) {
      statusMetrics += metric(labels[i], values[i] || 0);
    }

    const topAbsent = d.topAbsent || [];
    const topPresent = d.topPresent || [];
    const termLabel = (d.term && d.term.label) || "Current term";
    const grace = d.graceMinutes != null ? d.graceMinutes : 20;

    function facultyRankTable(rows, emptyText, columns) {
      if (!rows.length) {
        return '<p class="dash-empty">' + escapeHtml(emptyText) + "</p>";
      }
      return (
        '<div class="table-wrap"><table class="data-table"><thead><tr>' +
        columns
          .map(function (c) {
            return "<th>" + escapeHtml(c.label) + "</th>";
          })
          .join("") +
        "</tr></thead><tbody>" +
        rows
          .map(function (row) {
            return (
              "<tr>" +
              columns
                .map(function (c) {
                  return "<td>" + c.render(row) + "</td>";
                })
                .join("") +
              "</tr>"
            );
          })
          .join("") +
        "</tbody></table></div>"
      );
    }

    const absentTable = facultyRankTable(
      topAbsent,
      "No absences recorded for " + termLabel + ".",
      [
        {
          label: "#",
          render: function (row) {
            return escapeHtml(row.rank);
          },
        },
        {
          label: "Faculty",
          render: function (row) {
            return (
              '<div class="cell-strong">' +
              escapeHtml(row.faculty.fullName) +
              '</div><div class="cell-muted">' +
              escapeHtml(row.faculty.email) +
              "</div>"
            );
          },
        },
        {
          label: "Absent hrs",
          render: function (row) {
            return "<strong>" + escapeHtml(formatHours(row.absentHours)) + "</strong>";
          },
        },
        {
          label: "Late hrs",
          render: function (row) {
            return escapeHtml(formatHours(row.lateHours));
          },
        },
      ]
    );

    const presentTable = facultyRankTable(
      topPresent,
      "No present records for " + termLabel + ".",
      [
        {
          label: "#",
          render: function (row) {
            return escapeHtml(row.rank);
          },
        },
        {
          label: "Faculty",
          render: function (row) {
            return (
              '<div class="cell-strong">' +
              escapeHtml(row.faculty.fullName) +
              '</div><div class="cell-muted">' +
              escapeHtml(row.faculty.email) +
              "</div>"
            );
          },
        },
        {
          label: "Present hrs",
          render: function (row) {
            return "<strong>" + escapeHtml(formatHours(row.presentHours)) + "</strong>";
          },
        },
        {
          label: "Scans",
          render: function (row) {
            return escapeHtml(row.presentCount);
          },
        },
      ]
    );

    const periodMeta = d.period || { period: selectedPeriod, label: termLabel };
    const activePeriod = periodMeta.period || selectedPeriod;

    root.innerHTML =
      '<div class="dash-span-2">' +
      conflictBanner +
      "</div>" +
      widget(
        "Attendance oversight",
        periodFilterBar(activePeriod, periodMeta) +
          '<p class="dash-empty" style="margin-top:0.65rem">' +
          escapeHtml(String(grace)) +
          "-min grace · statuses: Present, Late, Absent" +
          "</p>" +
          '<div class="dash-metrics-row dash-metrics-row--4">' +
          metric("Total scans", d.attendanceChart.total) +
          statusMetrics +
          "</div>" +
          '<div class="chart-wrap tall" style="margin-top:0.75rem"><canvas id="chart-attendance"></canvas></div>' +
          '<div class="analytics-grid" style="margin-top:1rem">' +
          '<div class="dash-rank-panel">' +
          '<h4 class="dash-section-title">Top absences</h4>' +
          '<div class="chart-wrap"><canvas id="chart-top-absent"></canvas></div>' +
          absentTable +
          "</div>" +
          '<div class="dash-rank-panel">' +
          '<h4 class="dash-section-title">Top present</h4>' +
          '<div class="chart-wrap"><canvas id="chart-top-present"></canvas></div>' +
          presentTable +
          "</div>" +
          "</div>",
        null,
        null,
        "dash-span-2"
      );

    bindPeriodFilters();

    const tickColor =
      getComputedStyle(document.documentElement)
        .getPropertyValue("--ink-soft")
        .trim() || "#3a4d68";
    const gridColor =
      getComputedStyle(document.documentElement)
        .getPropertyValue("--line")
        .trim() || "rgba(16,35,63,0.12)";

    makeChart("chart-attendance", {
      type: "bar",
      data: {
        labels: d.attendanceChart.labels,
        datasets: [
          {
            label: "Attendance",
            data: d.attendanceChart.values,
            backgroundColor: ["#067647", "#d97706", "#0b6e6e"],
          },
        ],
      },
      options: {
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { color: tickColor }, grid: { color: gridColor } },
          y: {
            beginAtZero: true,
            ticks: { precision: 0, color: tickColor },
            grid: { color: gridColor },
          },
        },
      },
    });

    makeChart("chart-top-absent", {
      type: "bar",
      data: {
        labels: topAbsent.length
          ? topAbsent.map(function (r) {
              return r.faculty.fullName;
            })
          : ["No data"],
        datasets: [
          {
            label: "Absent hours",
            data: topAbsent.length
              ? topAbsent.map(function (r) {
                  return Number(r.absentHours) || 0;
                })
              : [0],
            backgroundColor: "rgba(180, 35, 24, 0.55)",
            borderRadius: 8,
          },
        ],
      },
      options: {
        indexAxis: "y",
        plugins: { legend: { display: false } },
        scales: {
          x: {
            beginAtZero: true,
            ticks: { color: tickColor },
            grid: { color: gridColor },
            title: { display: true, text: "Absent hours", color: tickColor },
          },
          y: { ticks: { color: tickColor }, grid: { color: gridColor } },
        },
      },
    });

    makeChart("chart-top-present", {
      type: "bar",
      data: {
        labels: topPresent.length
          ? topPresent.map(function (r) {
              return r.faculty.fullName;
            })
          : ["No data"],
        datasets: [
          {
            label: "Present hours",
            data: topPresent.length
              ? topPresent.map(function (r) {
                  return Number(r.presentHours) || 0;
                })
              : [0],
            backgroundColor: "rgba(6, 118, 71, 0.55)",
            borderRadius: 8,
          },
        ],
      },
      options: {
        indexAxis: "y",
        plugins: { legend: { display: false } },
        scales: {
          x: {
            beginAtZero: true,
            ticks: { color: tickColor },
            grid: { color: gridColor },
            title: { display: true, text: "Present hours", color: tickColor },
          },
          y: { ticks: { color: tickColor }, grid: { color: gridColor } },
        },
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
            backgroundColor: ["#067647", "#d97706", "#b42318", "#64748b"],
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
        "Class blocks",
        metric("Blocks this term", (d.classBlocks && d.classBlocks.count) || 0) +
          '<p class="dash-empty">Created by the Dean — assign students on Enrollment.</p>',
        (d.classBlocks && d.classBlocks.href) || "enrollment.html",
        "View class blocks"
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
            backgroundColor: ["#d97706", "#0b6e6e"],
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
            backgroundColor: "#b42318",
          },
          {
            label: "Cleared",
            data: d.blockingChart.cleared,
            backgroundColor: "#067647",
          },
        ],
      },
      options: {
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  function renderDashboard(role, dashboard) {
    clearCharts();
    currentRole = role;
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

  async function loadDashboard(period) {
    const qs =
      currentRole === "Dean" || !currentRole
        ? "?period=" + encodeURIComponent(period || selectedPeriod || "monthly")
        : "";
    const result = await Api.api("/dashboard/summary.php" + qs);
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

    if (user.role === "Dean" && result.data.dashboard && result.data.dashboard.period) {
      selectedPeriod = result.data.dashboard.period.period || selectedPeriod;
    }

    renderDashboard(user.role, result.data.dashboard);
  }

  async function boot() {
    if (!Api.getToken()) {
      window.location.href = "index.html";
      return;
    }

    try {
      await loadDashboard(selectedPeriod);
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
