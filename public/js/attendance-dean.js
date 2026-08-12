(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const form = document.getElementById("filter-form");
  const analyticsForm = document.getElementById("analytics-form");
  const alertEl = document.getElementById("attendance-alert");
  const deptSelect = document.getElementById("departmentId");
  const analyticsDept = document.getElementById("analytics-departmentId");
  const yearInput = document.getElementById("analytics-year");
  const semesterSelect = document.getElementById("analytics-semester");
  const termChip = document.getElementById("analytics-term-chip");
  const topBody = document.getElementById("top-absent-body");
  const topEmpty = document.getElementById("top-absent-empty");

  let chart = null;

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

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

  function analyticsQuery() {
    const params = new URLSearchParams();
    params.set("academicYear", yearInput.value);
    params.set("semester", semesterSelect.value);
    if (analyticsDept.value) params.set("departmentId", analyticsDept.value);
    return "?" + params.toString();
  }

  function formatHours(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return "0";
    return n % 1 === 0 ? String(n) : n.toFixed(2);
  }

  function renderTopAbsent(rows, term, graceMinutes) {
    if (termChip) {
      const graceLabel =
        graceMinutes != null ? " · " + graceMinutes + "-min grace" : "";
      termChip.textContent =
        (term && term.label ? term.label : "Semester") + graceLabel;
    }

    if (!rows.length) {
      topBody.innerHTML = "";
      if (topEmpty) topEmpty.hidden = false;
    } else {
      if (topEmpty) topEmpty.hidden = true;
      topBody.innerHTML = rows
        .map(function (row) {
          return (
            "<tr>" +
            "<td>" +
            escapeHtml(row.rank) +
            "</td>" +
            '<td><div class="cell-strong">' +
            escapeHtml(row.faculty.fullName) +
            '</div><div class="cell-muted">' +
            escapeHtml(row.faculty.email) +
            "</div></td>" +
            "<td>" +
            escapeHtml(row.department.name) +
            "</td>" +
            "<td>" +
            escapeHtml(formatHours(row.absentHours)) +
            "</td>" +
            "<td>" +
            escapeHtml(formatHours(row.lateHours)) +
            "</td>" +
            "<td><strong>" +
            escapeHtml(formatHours(row.totalHours)) +
            "</strong></td>" +
            "</tr>"
          );
        })
        .join("");
    }

    const labels = rows.map(function (r) {
      return r.faculty.fullName;
    });
    const values = rows.map(function (r) {
      return Number(r.totalHours) || 0;
    });

    const canvas = document.getElementById("top-absent-chart");
    if (!canvas || !window.Chart) return;

    if (chart) chart.destroy();
    chart = new window.Chart(canvas, {
      type: "bar",
      data: {
        labels: labels.length ? labels : ["No data"],
        datasets: [
          {
            label: "Counted hours (absent + late)",
            data: values.length ? values : [0],
            backgroundColor: "rgba(180, 35, 24, 0.55)",
            borderRadius: 8,
          },
        ],
      },
      options: {
        indexAxis: "y",
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: {
            beginAtZero: true,
            title: { display: true, text: "Hours" },
          },
        },
      },
    });
  }

  async function loadAnalytics() {
    alertEl.classList.remove("show");
    try {
      const result = await window.ScheduleGuardApi.api(
        "/attendance/analytics.php" + analyticsQuery()
      );
      renderTopAbsent(
        result.data.topAbsent || [],
        result.data.term,
        result.data.graceMinutes
      );
    } catch (err) {
      alertEl.textContent = err.message || "Unable to load absence analytics.";
      alertEl.classList.add("show");
    }
  }

  async function load() {
    alertEl.classList.remove("show");
    try {
      const result = await window.ScheduleGuardApi.api(
        "/attendance/oversight.php" + queryString()
      );
      Att.renderRows(result.data.records || [], { showFaculty: true });
    } catch (err) {
      alertEl.textContent = err.message || "Unable to load attendance oversight.";
      alertEl.classList.add("show");
    }
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    load();
  });

  analyticsForm.addEventListener("submit", function (event) {
    event.preventDefault();
    loadAnalytics();
  });

  Att.loadDepartments(deptSelect)
    .then(function () {
      return Att.loadDepartments(analyticsDept);
    })
    .then(async function () {
      // Seed year/semester from analytics API defaults (current term).
      const bootstrap = await window.ScheduleGuardApi.api("/attendance/analytics.php");
      const current = bootstrap.data.term.current || bootstrap.data.term;
      yearInput.value = String(current.academicYear || new Date().getFullYear());
      semesterSelect.value = current.semester || "1";
      renderTopAbsent(
        bootstrap.data.topAbsent || [],
        bootstrap.data.term,
        bootstrap.data.graceMinutes
      );
      return load();
    })
    .catch(function (err) {
      alertEl.textContent = err.message || "Unable to load departments.";
      alertEl.classList.add("show");
    });
})();
