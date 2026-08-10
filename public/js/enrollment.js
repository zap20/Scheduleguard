(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["ProgramHead"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("enrollment-alert");
  const successEl = document.getElementById("enrollment-success");
  const blockSelect = document.getElementById("classBlockId");
  const studentSelect = document.getElementById("studentId");

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

  function fillSelect(select, items, valueKey, labelFn, placeholder) {
    const keep = select.value;
    select.innerHTML = "";
    const first = document.createElement("option");
    first.value = "";
    first.textContent = placeholder;
    select.appendChild(first);
    items.forEach(function (item) {
      const opt = document.createElement("option");
      opt.value = item[valueKey];
      opt.textContent = labelFn(item);
      select.appendChild(opt);
    });
    if (keep) select.value = keep;
  }

  function renderMembers(members) {
    document.getElementById("member-count").textContent = String(members.length);
    const body = document.getElementById("members-body");
    const empty = document.getElementById("members-empty");
    if (!members.length) {
      body.innerHTML = "";
      empty.hidden = false;
      return;
    }
    empty.hidden = true;
    body.innerHTML = members
      .map(function (row) {
        return (
          "<tr>" +
          "<td class=\"cell-strong\">" +
          escapeHtml(row.studentName) +
          "</td>" +
          "<td>" +
          escapeHtml(row.studentEmail) +
          '<div class="cell-muted">' +
          escapeHtml(row.schoolId || "") +
          "</div></td>" +
          "<td><span class=\"status-badge status-late\">" +
          escapeHtml(row.status) +
          "</span></td>" +
          "<td>" +
          escapeHtml(row.assignedByName) +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  function renderAssigned(enrollments) {
    document.getElementById("assigned-count").textContent = String(enrollments.length);
    const body = document.getElementById("assigned-body");
    const empty = document.getElementById("assigned-empty");

    if (!enrollments.length) {
      body.innerHTML = "";
      empty.hidden = false;
      return;
    }
    empty.hidden = true;
    body.innerHTML = enrollments
      .map(function (row) {
        return (
          "<tr>" +
          "<td><div class=\"cell-strong\">" +
          escapeHtml(row.studentName) +
          "</div><div class=\"cell-muted\">" +
          escapeHtml(row.studentEmail) +
          "</div></td>" +
          "<td><div class=\"cell-strong\">" +
          escapeHtml(row.subjectCode || row.subjectName) +
          "</div><div class=\"cell-muted\">" +
          escapeHtml(row.blockName ? row.blockName + " · " : "") +
          escapeHtml(row.instructor || row.facultyName || "TBF") +
          "</div></td>" +
          "<td>" +
          escapeHtml(row.day) +
          " " +
          escapeHtml(row.startTime) +
          "–" +
          escapeHtml(row.endTime) +
          "<div class=\"cell-muted\">" +
          escapeHtml(row.roomLabel) +
          "</div></td>" +
          "<td><span class=\"status-badge status-late\">" +
          escapeHtml(row.status) +
          "</span></td>" +
          "<td><button type=\"button\" class=\"btn btn-small\" data-distribute=\"" +
          escapeHtml(row.uid) +
          "\">Distribute</button></td>" +
          "</tr>"
        );
      })
      .join("");

    body.querySelectorAll("[data-distribute]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        distribute(btn.getAttribute("data-distribute"));
      });
    });
  }

  async function loadBlocks() {
    const res = await Api.api("/class-blocks/list.php");
    document.getElementById("term-label").textContent =
      (res.data.term && res.data.term.label) || "Current Term";
    fillSelect(
      blockSelect,
      res.data.blocks || [],
      "uid",
      function (b) {
        return (
          b.name +
          " · " +
          b.memberCount +
          " student(s) · " +
          b.scheduleCount +
          " class(es)"
        );
      },
      "Select block…"
    );
  }

  async function loadBlockDetails() {
    const classBlockId = blockSelect.value;
    if (!classBlockId) {
      renderMembers([]);
      fillSelect(studentSelect, [], "uid", function () {}, "Select student…");
      document.getElementById("members-empty").hidden = false;
      document.getElementById("members-empty").textContent =
        "Select a class block to see its students, or add the first student above.";
      return;
    }
    const res = await Api.api(
      "/class-blocks/members.php?classBlockId=" + encodeURIComponent(classBlockId)
    );
    renderMembers(res.data.members || []);
    fillSelect(
      studentSelect,
      res.data.availableStudents || [],
      "uid",
      function (s) {
        return s.fullName + " (" + (s.schoolId || s.email) + ")";
      },
      "Select student…"
    );
    if (!(res.data.availableStudents || []).length) {
      document.getElementById("members-empty").textContent =
        (res.data.members || []).length
          ? "All cleared students in your department are already in this block."
          : "No cleared students available to add. Check Blocking clearance first.";
      if (!(res.data.members || []).length) {
        document.getElementById("members-empty").hidden = false;
      }
    }
  }

  async function loadAssigned() {
    const result = await Api.api("/enrollment/list.php?status=assigned");
    renderAssigned(result.data.enrollments || []);
  }

  async function reload() {
    hideMessages();
    await loadBlocks();
    await loadBlockDetails();
    await loadAssigned();
  }

  async function distribute(uid) {
    hideMessages();
    try {
      await Api.api("/enrollment/distribute.php", {
        method: "POST",
        body: JSON.stringify({ uid: uid }),
      });
      showSuccess("Schedule distributed to the student.");
      await loadAssigned();
    } catch (err) {
      showError(err.message || "Distribute failed.");
    }
  }

  blockSelect.addEventListener("change", function () {
    loadBlockDetails().catch(function (err) {
      showError(err.message || "Unable to load block members.");
    });
  });

  document.getElementById("enroll-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    hideMessages();
    try {
      const res = await Api.api("/class-blocks/assign-student.php", {
        method: "POST",
        body: JSON.stringify({
          classBlockId: blockSelect.value,
          studentId: studentSelect.value,
        }),
      });
      const n = (res.data && res.data.enrollmentCount) || 0;
      showSuccess(
        "Student added to block" +
          (n ? " (" + n + " schedule enrollment(s) created)." : " (no schedules linked yet).") +
          " Distribute when ready."
      );
      await reload();
    } catch (err) {
      const reason =
        err.data && err.data.blockReason ? " Reason: " + err.data.blockReason : "";
      showError((err.message || "Add to block failed.") + reason);
    }
  });

  try {
    await reload();
  } catch (err) {
    showError(err.message || "Unable to load enrollment workspace.");
  }
})();
