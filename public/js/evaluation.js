(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;

  function compareCardLabels(a, b) {
    return String(a || "").localeCompare(String(b || ""), undefined, {
      numeric: true,
      sensitivity: "base",
    });
  }
  const user = await Att.requireRole(["ProgramHead"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("eval-alert");
  const successEl = document.getElementById("eval-success");
  const groupsEl = document.getElementById("eval-groups");
  const emptyEl = document.getElementById("eval-empty");
  const countEl = document.getElementById("list-count");
  const termChip = document.getElementById("term-chip");
  const modal = document.getElementById("eval-modal");

  let tab = "pending"; // pending | evaluated (Approved only)
  let allStudents = [];
  let termLabel = "Current semester";

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function showError(message) {
    successEl.classList.remove("show");
    alertEl.textContent = message;
    alertEl.classList.add("show");
  }

  function showSuccess(message) {
    alertEl.classList.remove("show");
    successEl.textContent = message;
    successEl.classList.add("show");
  }

  function hideMessages() {
    alertEl.classList.remove("show");
    successEl.classList.remove("show");
  }

  function evalStatusOf(row) {
    return String(row.enrollmentEvalStatus || "Pending");
  }

  function studentTypeOf(row) {
    const t = String(row.studentType || "Regular").trim();
    return t === "Irregular" ? "Irregular" : "Regular";
  }

  function filteredList() {
    const year = document.getElementById("filter-yearLevel").value;
    const type = document.getElementById("filter-studentType").value;

    return allStudents.filter(function (row) {
      const st = evalStatusOf(row);
      if (tab === "pending") {
        if (st === "Approved") return false;
      } else if (st !== "Approved") {
        return false;
      }
      if (year && String(row.yearLevel || "") !== year) return false;
      if (type && studentTypeOf(row) !== type) return false;
      return true;
    });
  }

  function studentCardHtml(row) {
    const st = evalStatusOf(row);
    const year = row.yearLevel || "Year unset";
    const type = studentTypeOf(row);
    const idLine = row.schoolId || row.email || "";
    return (
      '<button type="button" class="schedule-pick-card" data-student-id="' +
      escapeHtml(row.uid) +
      '" data-student-name="' +
      escapeHtml(row.fullName) +
      '" data-student-year="' +
      escapeHtml(year) +
      '" data-student-type="' +
      escapeHtml(type) +
      '" data-student-idline="' +
      escapeHtml(idLine) +
      '">' +
      '<span class="schedule-pick-card__eyebrow">' +
      escapeHtml(termLabel) +
      "</span>" +
      '<span class="schedule-pick-card__title">' +
      escapeHtml(row.fullName) +
      "</span>" +
      '<span class="schedule-pick-card__stat">' +
      "Year level: " +
      escapeHtml(year) +
      "<br>Status: " +
      escapeHtml(type) +
      (idLine ? "<br>" + escapeHtml(idLine) : "") +
      (st === "Approved" ? "<br>" + statusBadgeHtml("Approved") : "") +
      "</span>" +
      '<span class="schedule-pick-card__cta">' +
      (st === "Approved" ? "Already approved" : "Approve →") +
      "</span>" +
      "</button>"
    );
  }

  function statusBadgeHtml(status) {
    return (
      '<span class="status-badge status-present">' +
      escapeHtml(status) +
      "</span>"
    );
  }

  function render() {
    const list = filteredList();
    countEl.textContent = String(list.length);

    if (!list.length) {
      groupsEl.innerHTML = "";
      emptyEl.hidden = false;
      emptyEl.textContent =
        tab === "pending"
          ? "No non-evaluated cleared students match these filters for this semester."
          : "No approved students match these filters for this semester.";
      return;
    }
    emptyEl.hidden = true;

    const groups = {};
    list.forEach(function (row) {
      const key = row.yearLevel || "Year unset";
      if (!groups[key]) groups[key] = [];
      groups[key].push(row);
    });
    const order = Object.keys(groups).sort(compareCardLabels);

    groupsEl.innerHTML = order
      .map(function (key) {
        // Sub-group Regular / Irregular within year level
        const byType = { Regular: [], Irregular: [] };
        groups[key].forEach(function (row) {
          byType[studentTypeOf(row)].push(row);
        });
        const typeSections = ["Regular", "Irregular"]
          .filter(function (t) {
            return byType[t].length > 0;
          })
          .map(function (t) {
            const cards = byType[t]
              .slice()
              .sort(function (a, b) {
                return compareCardLabels(a.fullName, b.fullName);
              })
              .map(studentCardHtml)
              .join("");
            return (
              '<h4 class="room-grid-section__label" style="margin-top:0.65rem">' +
              escapeHtml(t) +
              " · " +
              byType[t].length +
              "</h4>" +
              '<div class="schedule-card-grid">' +
              cards +
              "</div>"
            );
          })
          .join("");

        return (
          '<section class="room-grid-section">' +
          '<h3 class="room-grid-section__label">' +
          escapeHtml(key) +
          " · " +
          groups[key].length +
          " student" +
          (groups[key].length === 1 ? "" : "s") +
          "</h3>" +
          typeSections +
          "</section>"
        );
      })
      .join("");

    groupsEl.querySelectorAll(".schedule-pick-card").forEach(function (btn) {
      btn.addEventListener("click", function () {
        const st = btn.querySelector(".schedule-pick-card__cta");
        if (st && /already approved/i.test(st.textContent || "")) {
          return;
        }
        openModal(
          btn.getAttribute("data-student-id"),
          btn.getAttribute("data-student-name") || "Student",
          btn.getAttribute("data-student-year") || "Year unset",
          btn.getAttribute("data-student-type") || "Regular",
          btn.getAttribute("data-student-idline") || ""
        );
      });
    });
  }

  function openModal(studentId, name, year, type, idLine) {
    document.getElementById("eval-student-id").value = studentId || "";
    document.getElementById("eval-student-summary").textContent =
      "Approve this student for " + termLabel + "?";
    document.getElementById("eval-details").innerHTML =
      "<div><dt>Student</dt><dd>" +
      escapeHtml(name) +
      "</dd></div>" +
      "<div><dt>Year level</dt><dd>" +
      escapeHtml(year) +
      "</dd></div>" +
      "<div><dt>Status (semester)</dt><dd>" +
      escapeHtml(type) +
      "</dd></div>" +
      (idLine
        ? "<div><dt>ID / Email</dt><dd>" + escapeHtml(idLine) + "</dd></div>"
        : "") +
      "<div><dt>Semester</dt><dd>" +
      escapeHtml(termLabel) +
      "</dd></div>";
    document.getElementById("eval-notes").value = "";
    modal.hidden = false;
  }

  async function load() {
    hideMessages();
    try {
      const res = await Api.api("/students/evaluation-queue.php");
      allStudents = (res.data && res.data.students) || [];
      const term = res.data && res.data.term;
      termLabel =
        (term && (term.label || "Sem " + term.semester)) || "Current semester";
      if (termChip) termChip.textContent = termLabel;
      render();
    } catch (err) {
      allStudents = [];
      render();
      showError(err.message || "Unable to load evaluation list.");
    }
  }

  document.querySelectorAll(".module-tab[data-eval-tab]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      tab = btn.getAttribute("data-eval-tab") || "pending";
      document.querySelectorAll(".module-tab[data-eval-tab]").forEach(function (t) {
        t.classList.toggle("is-active", t === btn);
      });
      render();
    });
  });

  document.getElementById("filter-form").addEventListener("submit", function (e) {
    e.preventDefault();
    render();
  });

  document.getElementById("eval-cancel").addEventListener("click", function () {
    modal.hidden = true;
  });
  modal.addEventListener("click", function (e) {
    if (e.target === modal) modal.hidden = true;
  });

  document.getElementById("eval-form").addEventListener("submit", async function (e) {
    e.preventDefault();
    hideMessages();
    const btn = document.getElementById("eval-submit");
    btn.disabled = true;
    try {
      await Api.api("/students/evaluate.php", {
        method: "POST",
        body: JSON.stringify({
          studentId: document.getElementById("eval-student-id").value,
          notes: document.getElementById("eval-notes").value.trim(),
        }),
      });
      modal.hidden = true;
      showSuccess("Student approved for " + termLabel + ". They can be added on Enrollment.");
      await load();
    } catch (err) {
      showError(err.message || "Approval failed.");
    } finally {
      btn.disabled = false;
    }
  });

  await load();
})();
