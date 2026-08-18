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
  const blocksCardsEl = document.getElementById("blocks-cards");
  const blocksEmpty = document.getElementById("blocks-empty");
  const blocksCardsView = document.getElementById("blocks-cards-view");
  const blocksDetailView = document.getElementById("blocks-detail-view");
  const blocksListPanel = document.getElementById("blocks-list-panel");
  const enrollPanel = document.getElementById("enroll-panel");
  const blockDetailTitle = document.getElementById("block-detail-title");
  const blockDetailSub = document.getElementById("block-detail-sub");
  const blockScheduleGrid = document.getElementById("block-schedule-grid");
  const blockScheduleBody = document.getElementById("block-schedule-body");
  const blockScheduleEmpty = document.getElementById("block-schedule-empty");

  let blocksCache = [];
  let currentBlockId = "";

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

  function instructorLabel(row) {
    const name = (row.instructor || row.facultyName || "").trim();
    return name !== "" ? name : "TBF";
  }

  function statusBadge(status) {
    const s = String(status || "").toLowerCase();
    let cls = "status-late";
    if (s === "approved" || s === "confirmed" || s === "distributed") cls = "status-present";
    if (s === "rejected" || s === "conflict") cls = "status-wrong";
    return (
      '<span class="status-badge ' +
      cls +
      '">' +
      escapeHtml(status || "—") +
      "</span>"
    );
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

  function blockCardHtml(row) {
    const meetings = Number(row.scheduleCount) || 0;
    const students = Number(row.memberCount) || 0;
    const type = (row.studentType || "regular").toString();
    const status =
      meetings +
      " meeting" +
      (meetings === 1 ? "" : "s") +
      " · " +
      students +
      " student" +
      (students === 1 ? "" : "s") +
      " · " +
      type;
    return (
      '<button type="button" class="schedule-pick-card" data-block-id="' +
      escapeHtml(row.uid) +
      '" data-block-name="' +
      escapeHtml(row.name) +
      '">' +
      '<span class="schedule-pick-card__eyebrow">' +
      escapeHtml(row.yearLevel || "Class block") +
      " · " +
      escapeHtml(row.status || "Open") +
      "</span>" +
      '<span class="schedule-pick-card__title">' +
      escapeHtml(row.name) +
      "</span>" +
      '<span class="schedule-pick-card__stat">' +
      escapeHtml(status) +
      "</span>" +
      '<span class="schedule-pick-card__cta">Open block →</span>' +
      "</button>"
    );
  }

  function showBlocksCards() {
    currentBlockId = "";
    blockSelect.value = "";
    blocksCardsView.hidden = false;
    blocksDetailView.hidden = true;
    blocksListPanel.hidden = true;
    enrollPanel.hidden = true;
    blockScheduleGrid.innerHTML = "";
    blockScheduleBody.innerHTML = "";
  }

  function renderBlocks(list) {
    blocksCache = list || [];
    document.getElementById("blocks-count").textContent = String(blocksCache.length);

    if (!currentBlockId) {
      showBlocksCards();
    }

    if (!blocksCache.length) {
      blocksCardsEl.className = "schedule-card-grid";
      blocksCardsEl.innerHTML = "";
      blocksEmpty.hidden = false;
      return;
    }
    blocksEmpty.hidden = true;

    const groups = {};
    blocksCache.forEach(function (row) {
      const key = row.yearLevel || "Other";
      if (!groups[key]) groups[key] = [];
      groups[key].push(row);
    });
    const order = Object.keys(groups).sort(function (a, b) {
      return a.localeCompare(b, undefined, { numeric: true });
    });

    blocksCardsEl.className = "";
    blocksCardsEl.innerHTML = order
      .map(function (key) {
        const cards = groups[key]
          .slice()
          .sort(function (a, b) {
            return String(a.name || "").localeCompare(String(b.name || ""));
          })
          .map(blockCardHtml)
          .join("");
        return (
          '<section class="room-grid-section">' +
          '<h3 class="room-grid-section__label">' +
          escapeHtml(key) +
          "</h3>" +
          '<div class="schedule-card-grid">' +
          cards +
          "</div></section>"
        );
      })
      .join("");

    blocksCardsEl.querySelectorAll(".schedule-pick-card").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openBlock(btn.getAttribute("data-block-id"));
      });
    });
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
          "<td>" +
          statusBadge(row.status) +
          "</td>" +
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
          "<td>" +
          statusBadge(row.status) +
          "</td>" +
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
    const term = res.data && res.data.term;
    document.getElementById("term-label").textContent =
      (term && (term.label || term.semester)) || "Current Term";
    renderBlocks((res.data && res.data.blocks) || []);
  }

  async function openBlock(classBlockId) {
    if (!classBlockId) return;
    hideMessages();
    currentBlockId = classBlockId;
    blockSelect.value = classBlockId;

    const meta = blocksCache.find(function (b) {
      return String(b.uid) === String(classBlockId);
    });

    blocksCardsView.hidden = true;
    blocksDetailView.hidden = false;
    blocksListPanel.hidden = false;
    enrollPanel.hidden = false;

    blockDetailTitle.textContent = (meta && meta.name) || "Class block";
    blockDetailSub.textContent = meta
      ? (meta.yearLevel || "") +
        " · " +
        (meta.studentType || "regular") +
        " · add Approved students only"
      : "Add Approved students only (see Evaluation)";

    blockScheduleGrid.innerHTML = "";
    blockScheduleBody.innerHTML = "";
    blockScheduleEmpty.hidden = true;

    try {
      const [schedRes, memberRes] = await Promise.all([
        Api.api(
          "/class-blocks/schedules.php?classBlockId=" +
            encodeURIComponent(classBlockId)
        ),
        Api.api(
          "/class-blocks/members.php?classBlockId=" +
            encodeURIComponent(classBlockId)
        ),
      ]);

      const rows = (schedRes.data && schedRes.data.schedules) || [];
      if (!rows.length) {
        blockScheduleEmpty.hidden = false;
      } else {
        blockScheduleGrid.innerHTML = window.renderScheduleGrid(
          rows.map(function (row) {
            const parts = [row.subjectCode, instructorLabel(row)];
            const room = (row.roomName || row.roomLabel || "").trim();
            if (room) parts.push(room);
            return {
              day: row.day,
              startTime: row.startTime,
              endTime: row.endTime,
              label: parts.join(" · "),
            };
          })
        );
        blockScheduleBody.innerHTML = rows
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
              escapeHtml(row.day) +
              "</td>" +
              "<td>" +
              escapeHtml(row.startTime) +
              "–" +
              escapeHtml(row.endTime) +
              "</td>" +
              "<td>" +
              escapeHtml(row.roomLabel) +
              "</td>" +
              "<td>" +
              statusBadge(row.status) +
              "</td>" +
              "</tr>"
            );
          })
          .join("");
      }

      renderMembers(memberRes.data.members || []);
      fillSelect(
        studentSelect,
        memberRes.data.availableStudents || [],
        "uid",
        function (s) {
          return (
            s.fullName +
            " · " +
            (s.yearLevel || "?") +
            " " +
            (s.studentType || "Regular") +
            " (" +
            (s.schoolId || s.email) +
            ")"
          );
        },
        (memberRes.data.availableStudents || []).length
          ? "Select approved student…"
          : "No Approved students yet — use Evaluation first…"
      );
    } catch (err) {
      showBlocksCards();
      showError(err.message || "Unable to open class block.");
    }
  }

  async function loadAssigned() {
    const result = await Api.api("/enrollment/list.php?status=assigned");
    renderAssigned(result.data.enrollments || []);
  }

  async function reload() {
    hideMessages();
    const keepId = currentBlockId;
    try {
      await loadBlocks();
    } catch (err) {
      renderBlocks([]);
      showError(err.message || "Unable to load class blocks.");
      throw err;
    }
    if (keepId) {
      await openBlock(keepId);
    }
    try {
      await loadAssigned();
    } catch (err) {
      renderAssigned([]);
      showError(err.message || "Unable to load assigned enrollments.");
    }
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

  document.getElementById("blocks-back-to-cards").addEventListener("click", function () {
    showBlocksCards();
  });

  document.getElementById("enroll-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    hideMessages();
    if (!blockSelect.value) {
      showError("Open a class block card first.");
      return;
    }
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
        err.payload && err.payload.blockReason
          ? " " + err.payload.blockReason
          : "";
      showError((err.message || "Add to block failed.") + reason);
    }
  });

  try {
    await reload();
  } catch (err) {
    showError(err.message || "Unable to load enrollment workspace.");
  }
})();
