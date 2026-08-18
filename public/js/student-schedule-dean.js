(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;

  function compareCardLabels(a, b) {
    return window.ScheduleGrid.compareCardLabels(a, b);
  }

  function compareBlockCards(a, b) {
    const na = parseInt(a.blockNumber, 10);
    const nb = parseInt(b.blockNumber, 10);
    const aOk = Number.isFinite(na);
    const bOk = Number.isFinite(nb);
    if (aOk && bOk && na !== nb) return na - nb;
    if (aOk !== bOk) return aOk ? -1 : 1;
    return compareCardLabels(a.name, b.name);
  }
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName + " (" + user.role + ")";
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("schedule-alert");
  const gridEl = document.getElementById("schedule-grid");
  const emptyEl = document.getElementById("schedule-empty");
  const bodyEl = document.getElementById("schedule-body");
  const studentCardsEl = document.getElementById("student-cards");
  const studentsEmpty = document.getElementById("students-empty");
  const studentCardsView = document.getElementById("student-cards-view");
  const studentDetailView = document.getElementById("student-detail-view");
  const studentListPanel = document.getElementById("student-list-panel");
  const studentDetailTitle = document.getElementById("student-detail-title");
  const studentDetailSub = document.getElementById("student-detail-sub");

  const blocksAlert = document.getElementById("blocks-alert");
  const blocksSuccess = document.getElementById("blocks-success");
  const blocksCardsEl = document.getElementById("blocks-cards");
  const blocksEmpty = document.getElementById("blocks-empty");
  const blocksCardsView = document.getElementById("blocks-cards-view");
  const blocksDetailView = document.getElementById("blocks-detail-view");
  const blocksListPanel = document.getElementById("blocks-list-panel");
  const blockDetailTitle = document.getElementById("block-detail-title");
  const blockDetailSub = document.getElementById("block-detail-sub");
  const blockScheduleGrid = document.getElementById("block-schedule-grid");
  const blockScheduleBody = document.getElementById("block-schedule-body");
  const blockScheduleEmpty = document.getElementById("block-schedule-empty");
  const modal = document.getElementById("create-modal");
  let yearLevels = [];
  let timetableLoaded = false;
  let allBlocks = [];
  let pendingImportFile = null;
  let pendingImportSheet = "";
  let enrolledRows = [];
  let enrolledStudents = [];

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function blockLabel(row) {
    return (row.blockName || "").trim() || "—";
  }

  function statusBadge(status) {
    const s = String(status || "").toLowerCase();
    const cls =
      s === "confirmed"
        ? "status-present"
        : s === "conflict"
          ? "status-wrong"
          : "status-late";
    return (
      '<span class="status-badge ' + cls + '">' + escapeHtml(status || "—") + "</span>"
    );
  }

  function showBlocksError(message) {
    blocksSuccess.classList.remove("show");
    blocksAlert.textContent = message;
    blocksAlert.classList.add("show");
  }

  function showBlocksSuccess(message) {
    blocksAlert.classList.remove("show");
    blocksSuccess.textContent = message;
    blocksSuccess.classList.add("show");
  }

  function hideBlocksMessages() {
    blocksAlert.classList.remove("show");
    blocksSuccess.classList.remove("show");
  }

  function setPane(pane) {
    document.querySelectorAll(".module-tab").forEach(function (tab) {
      tab.classList.toggle("is-active", tab.getAttribute("data-pane") === pane);
    });
    document.querySelectorAll(".module-pane").forEach(function (el) {
      el.hidden = el.getAttribute("data-pane") !== pane;
    });
    if (pane === "timetable" && !timetableLoaded) {
      loadTimetable().catch(function (err) {
        alertEl.textContent = err.message || "Unable to load student schedules.";
        alertEl.classList.add("show");
      });
    }
    if (window.location.hash !== "#" + pane) {
      history.replaceState(null, "", "#" + pane);
    }
  }

  function fillYearSelects(levels) {
    yearLevels = levels || [];
    const filter = document.getElementById("filter-yearLevel");
    const create = document.getElementById("create-yearLevel");
    const keep = filter.value;
    filter.innerHTML = '<option value="">All year levels</option>';
    create.innerHTML = "";
    yearLevels.forEach(function (yl) {
      const o1 = document.createElement("option");
      o1.value = yl;
      o1.textContent = yl;
      filter.appendChild(o1);
      const o2 = document.createElement("option");
      o2.value = yl;
      o2.textContent = yl;
      create.appendChild(o2);
    });
    if (keep) filter.value = keep;
  }

  function instructorLabel(row) {
    const name = (row.instructor || row.facultyName || "").trim();
    return name !== "" ? name : "TBF";
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
      escapeHtml(row.status || "Active") +
      "</span>" +
      '<span class="schedule-pick-card__title">' +
      escapeHtml(row.name) +
      "</span>" +
      '<span class="schedule-pick-card__stat">' +
      escapeHtml(status) +
      "</span>" +
      '<span class="schedule-pick-card__cta">View weekly grid →</span>' +
      "</button>"
    );
  }

  function showBlocksCards() {
    blocksCardsView.hidden = false;
    blocksDetailView.hidden = true;
    blocksListPanel.hidden = true;
    blockScheduleGrid.innerHTML = "";
    blockScheduleBody.innerHTML = "";
  }

  function renderBlocks(list) {
    allBlocks = list || [];
    document.getElementById("blocks-count").textContent = String(allBlocks.length);
    showBlocksCards();

    if (!allBlocks.length) {
      blocksCardsEl.className = "schedule-card-grid";
      blocksCardsEl.innerHTML = "";
      blocksEmpty.hidden = false;
      return;
    }
    blocksEmpty.hidden = true;

    const groups = {};
    allBlocks.forEach(function (row) {
      const key = row.yearLevel || "Other";
      if (!groups[key]) groups[key] = [];
      groups[key].push(row);
    });
    const order = Object.keys(groups).sort(compareCardLabels);

    blocksCardsEl.className = "";
    blocksCardsEl.innerHTML = order
      .map(function (key) {
        const cards = groups[key]
          .slice()
          .sort(compareBlockCards)
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
        openBlockSchedule(
          btn.getAttribute("data-block-id"),
          btn.getAttribute("data-block-name") || "Block"
        );
      });
    });
  }

  async function openBlockSchedule(classBlockId, blockName) {
    hideBlocksMessages();
    blocksCardsView.hidden = true;
    blocksDetailView.hidden = false;
    blocksListPanel.hidden = false;

    blockDetailTitle.textContent = blockName;
    blockDetailSub.textContent = "Class block weekly grid";
    blockScheduleGrid.innerHTML = "";
    blockScheduleBody.innerHTML = "";
    blockScheduleEmpty.hidden = true;

    try {
      const res = await Api.api(
        "/class-blocks/schedules.php?classBlockId=" +
          encodeURIComponent(classBlockId)
      );
      const rows = res.data.schedules || [];
      const meta = allBlocks.find(function (b) {
        return String(b.uid) === String(classBlockId);
      });
      if (meta) {
        blockDetailSub.textContent =
          (meta.yearLevel || "") +
          " · " +
          (meta.studentType || "regular") +
          " · " +
          (Number(meta.memberCount) || 0) +
          " student(s) · " +
          rows.length +
          " meeting(s)";
      } else {
        blockDetailSub.textContent = rows.length + " meeting(s)";
      }

      if (!rows.length) {
        blockScheduleEmpty.hidden = false;
        return;
      }

      blockScheduleGrid.innerHTML = window.renderScheduleGrid(
        rows.map(function (row) {
          return {
            day: row.day,
            startTime: row.startTime,
            endTime: row.endTime,
            label: window.ScheduleGrid.meetingGridLabel(row, { excelStyle: true }),
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
            escapeHtml(window.ScheduleGrid.formatScheduleRoom(row) || row.roomLabel) +
            "</td>" +
            "<td>" +
            statusBadge(row.status) +
            "</td>" +
            "</tr>"
          );
        })
        .join("");
    } catch (err) {
      showBlocksCards();
      showBlocksError(err.message || "Unable to load block schedule.");
    }
  }

  async function loadBlocks() {
    hideBlocksMessages();
    const params = new URLSearchParams();
    const yl = document.getElementById("filter-yearLevel").value;
    if (yl) params.set("yearLevel", yl);
    const qs = params.toString() ? "?" + params.toString() : "";
    const res = await Api.api("/class-blocks/list.php" + qs);
    const term = res.data.term;
    if (term) {
      const label = term.label || "Sem " + term.semester;
      document.getElementById("blocks-term-chip").textContent = label;
      document.getElementById("term-chip").textContent = label;
    }
    fillYearSelects(res.data.yearLevels || []);
    renderBlocks(res.data.blocks || []);
  }

  function toGridBlocks(rows) {
    return rows.map(function (row) {
      return {
        day: row.day,
        startTime: row.startTime,
        endTime: row.endTime,
        label: window.ScheduleGrid.meetingGridLabel(row, { excelStyle: true }),
      };
    });
  }

  function schoolYearFromRow(row) {
    const schoolId = String(row.studentSchoolId || "").trim();
    const match = schoolId.match(/^(\d{4})/);
    if (match) return match[1];
    const ay = Number(row.academicYear) || 0;
    return ay > 0 ? String(ay) : "Unknown";
  }

  function yearLevelFromRow(row) {
    return String(row.studentYearLevel || "").trim() || "Unspecified";
  }

  function collectEnrolledStudents(rows) {
    const byId = {};
    (rows || []).forEach(function (row) {
      const id = String(row.studentId || "");
      if (!id) return;
      if (!byId[id]) {
        byId[id] = {
          uid: id,
          fullName: row.studentName || "Student",
          schoolId: row.studentSchoolId || "",
          schoolYear: schoolYearFromRow(row),
          yearLevel: yearLevelFromRow(row),
          studentType: row.studentType || "",
          blocks: [],
          meetings: 0,
        };
      }
      byId[id].meetings += 1;
      const block = (row.blockName || "").trim();
      if (block && byId[id].blocks.indexOf(block) === -1) {
        byId[id].blocks.push(block);
      }
    });
    return Object.keys(byId)
      .map(function (id) {
        return byId[id];
      })
      .sort(function (a, b) {
        return compareCardLabels(a.fullName, b.fullName);
      });
  }

  function studentCardHtml(student) {
    const type = (student.studentType || "").toString();
    const blocks = student.blocks.length ? student.blocks.join(", ") : "No block name";
    const stat =
      student.meetings +
      " meeting" +
      (student.meetings === 1 ? "" : "s") +
      " · " +
      blocks +
      (type ? " · " + type : "");
    return (
      '<button type="button" class="schedule-pick-card" data-student-id="' +
      escapeHtml(student.uid) +
      '">' +
      '<span class="schedule-pick-card__eyebrow">' +
      escapeHtml(student.schoolId || "No school ID") +
      " · " +
      escapeHtml(student.yearLevel) +
      "</span>" +
      '<span class="schedule-pick-card__title">' +
      escapeHtml(student.fullName) +
      "</span>" +
      '<span class="schedule-pick-card__stat">' +
      escapeHtml(stat) +
      "</span>" +
      '<span class="schedule-pick-card__cta">View weekly grid →</span>' +
      "</button>"
    );
  }

  function showStudentCards() {
    studentCardsView.hidden = false;
    studentDetailView.hidden = true;
    studentListPanel.hidden = true;
    gridEl.innerHTML = "";
    bodyEl.innerHTML = "";
  }

  function renderEnrolledStudents(list) {
    enrolledStudents = list || [];
    document.getElementById("result-count").textContent = String(enrolledStudents.length);
    showStudentCards();

    if (!enrolledStudents.length) {
      studentCardsEl.className = "schedule-card-grid";
      studentCardsEl.innerHTML = "";
      studentsEmpty.hidden = false;
      return;
    }
    studentsEmpty.hidden = true;

    const yearOrder = ["1st Year", "2nd Year", "3rd Year", "4th Year"];
    const bySchoolYear = {};
    enrolledStudents.forEach(function (student) {
      const sy = student.schoolYear || "Unknown";
      if (!bySchoolYear[sy]) bySchoolYear[sy] = {};
      const yl = student.yearLevel || "Unspecified";
      if (!bySchoolYear[sy][yl]) bySchoolYear[sy][yl] = [];
      bySchoolYear[sy][yl].push(student);
    });

    const schoolYears = Object.keys(bySchoolYear).sort(function (a, b) {
      if (a === "Unknown") return 1;
      if (b === "Unknown") return -1;
      return Number(b) - Number(a) || String(a).localeCompare(String(b));
    });

    studentCardsEl.className = "";
    studentCardsEl.innerHTML = schoolYears
      .map(function (schoolYear) {
        const levels = Object.keys(bySchoolYear[schoolYear]).sort(function (a, b) {
          const ia = yearOrder.indexOf(a);
          const ib = yearOrder.indexOf(b);
          if (ia !== -1 && ib !== -1) return ia - ib;
          if (ia !== -1) return -1;
          if (ib !== -1) return 1;
          return a.localeCompare(b);
        });
        const sections = levels
          .map(function (yearLevel) {
            const cards = bySchoolYear[schoolYear][yearLevel]
              .slice()
              .sort(function (a, b) {
                return compareCardLabels(a.fullName, b.fullName);
              })
              .map(studentCardHtml)
              .join("");
            return (
              '<section class="room-grid-section">' +
              '<h3 class="room-grid-section__label">' +
              escapeHtml(yearLevel) +
              " · " +
              bySchoolYear[schoolYear][yearLevel].length +
              " student" +
              (bySchoolYear[schoolYear][yearLevel].length === 1 ? "" : "s") +
              "</h3>" +
              '<div class="schedule-card-grid">' +
              cards +
              "</div></section>"
            );
          })
          .join("");
        return (
          '<section class="room-grid-section" style="margin-bottom: 1.5rem">' +
          '<h2 class="room-grid-section__title">' +
          "School year " +
          escapeHtml(schoolYear) +
          "</h2>" +
          sections +
          "</section>"
        );
      })
      .join("");

    studentCardsEl.querySelectorAll(".schedule-pick-card").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openStudentSchedule(btn.getAttribute("data-student-id"));
      });
    });
  }

  function openStudentSchedule(studentId) {
    alertEl.classList.remove("show");
    const student = enrolledStudents.find(function (s) {
      return String(s.uid) === String(studentId);
    });
    const rows = enrolledRows.filter(function (row) {
      return String(row.studentId) === String(studentId);
    });

    studentCardsView.hidden = true;
    studentDetailView.hidden = false;
    studentListPanel.hidden = false;

    studentDetailTitle.textContent = student ? student.fullName : "Weekly grid";
    studentDetailSub.textContent = student
      ? [student.schoolId, student.yearLevel, student.studentType, student.blocks.join(", ")]
          .filter(Boolean)
          .join(" · ")
      : rows.length + " meeting(s)";

    gridEl.innerHTML = "";
    bodyEl.innerHTML = "";
    emptyEl.hidden = true;

    if (!rows.length) {
      emptyEl.hidden = false;
      return;
    }

    gridEl.innerHTML = window.renderScheduleGrid(toGridBlocks(rows));
    bodyEl.innerHTML = rows
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
          escapeHtml(window.ScheduleGrid.formatScheduleRoom(row) || row.roomLabel) +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  async function loadTimetable() {
    alertEl.classList.remove("show");
    const result = await Api.api("/schedules/student-view.php");
    enrolledRows = result.data.schedules || [];
    const term = result.data.term;
    if (term) {
      document.getElementById("term-chip").textContent =
        term.label || "Sem " + term.semester;
    }
    timetableLoaded = true;
    renderEnrolledStudents(collectEnrolledStudents(enrolledRows));
  }

  document.querySelectorAll(".module-tab").forEach(function (tab) {
    tab.addEventListener("click", function () {
      setPane(tab.getAttribute("data-pane"));
    });
  });

  document.getElementById("blocks-filter-form").addEventListener("submit", function (e) {
    e.preventDefault();
    loadBlocks().catch(function (err) {
      showBlocksError(err.message || "Unable to load class blocks.");
    });
  });

  function occupantLabel(row) {
    const year = (row.withYearLevel || "").trim();
    const block = (row.withBlockName || "").trim();
    const subj = (row.withSubject || "").trim();
    const parts = [];
    if (year) parts.push(year);
    if (block) parts.push(block);
    if (subj) parts.push(subj);
    return parts.length ? parts.join(" · ") : "another class in this room";
  }

  function renderImportConflicts(data) {
    const box = document.getElementById("blocks-import-conflicts");
    const summary = document.getElementById("blocks-import-conflict-summary");
    const body = document.getElementById("blocks-import-conflict-body");
    const conflicts = data.conflicts || [];
    const blocks = {};
    conflicts.forEach(function (row) {
      const key = (row.yearLevel || "") + " · " + (row.blockName || "Block " + (row.blockNumber || ""));
      blocks[key] = (blocks[key] || 0) + 1;
    });
    const blockList = Object.keys(blocks)
      .map(function (k) {
        return k + " (" + blocks[k] + ")";
      })
      .join("; ");
    summary.innerHTML =
      "<strong>" +
      escapeHtml(String(conflicts.length)) +
      " room conflict(s)</strong> — " +
      escapeHtml(String(data.okCount || 0)) +
      " meeting(s) are free to import. Affected: " +
      escapeHtml(blockList) +
      ".";
    body.innerHTML = conflicts
      .map(function (row) {
        return (
          "<tr>" +
          "<td>" +
          escapeHtml(row.yearLevel || "—") +
          "</td>" +
          "<td>" +
          escapeHtml(row.blockName || "Block " + (row.blockNumber || "—")) +
          "</td>" +
          "<td>" +
          escapeHtml(row.subjectCode || row.subjectName || "—") +
          "</td>" +
          "<td>" +
          escapeHtml(row.day || "—") +
          "</td>" +
          "<td>" +
          escapeHtml((row.startTime || "") + "–" + (row.endTime || "")) +
          "</td>" +
          "<td>" +
          escapeHtml(row.roomLabel || "—") +
          "</td>" +
          "<td>" +
          escapeHtml(occupantLabel(row)) +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
    box.hidden = false;
  }

  async function commitBlockImport(resolveConflicts) {
    if (!pendingImportFile) {
      showBlocksError("Choose the semester XLSX workbook.");
      return;
    }
    const report = document.getElementById("blocks-import-report");
    const conflictBox = document.getElementById("blocks-import-conflicts");
    const btn = document.getElementById("blocks-import-submit");
    const yesBtn = document.getElementById("blocks-import-resolve-conflicts");
    const noBtn = document.getElementById("blocks-import-skip-conflicts");
    const formData = new FormData();
    formData.append("file", pendingImportFile);
    formData.append("sheet", pendingImportSheet);
    formData.append("preview", "0");
    formData.append("resolveConflicts", resolveConflicts ? "1" : "0");
    btn.disabled = true;
    yesBtn.disabled = true;
    noBtn.disabled = true;
    report.hidden = true;
    try {
      const result = await Api.api("/class-blocks/import.php", {
        method: "POST",
        body: formData,
      });
      conflictBox.hidden = true;
      const failed = result.data.failed || [];
      const reassigned = result.data.reassignedCount || 0;
      const clonedVenues = result.data.clonedVenueCount || 0;
      const skippedConflicts = resolveConflicts
        ? 0
        : result.data.conflictCount || 0;
      report.hidden = false;
      report.innerHTML =
        "<strong>Import complete</strong>: " +
        (result.data.importedBlockCount || 0) +
        " class block(s), " +
        (result.data.importedCount || 0) +
        " meeting(s) imported" +
        (reassigned ? ", " + reassigned + " moved to a vacant room" : "") +
        (clonedVenues
          ? ", " + clonedVenues + " GYM/Field/SEAIT conflict(s) placed in GYM2 / Field2 / SEAIT2"
          : "") +
        (skippedConflicts ? ", " + skippedConflicts + " conflict(s) skipped" : "") +
        ", " +
        (result.data.failedCount || 0) +
        " failed" +
        (result.data.skippedIrregCount
          ? ", " + result.data.skippedIrregCount + " IRREG meeting(s) skipped"
          : "") +
        "." +
        (failed.length
          ? "<ul>" +
            failed
              .slice(0, 12)
              .map(function (row) {
                return (
                  "<li>" +
                  escapeHtml(row.source || "row") +
                  ": " +
                  escapeHtml(row.error || "failed") +
                  "</li>"
                );
              })
              .join("") +
            "</ul>"
          : "");
      showBlocksSuccess(
        "Imported " +
          (result.data.importedBlockCount || 0) +
          " class block(s) from the workbook."
      );
      await loadBlocks();
    } catch (err) {
      showBlocksError(err.message || "Import failed.");
    } finally {
      btn.disabled = false;
      yesBtn.disabled = false;
      noBtn.disabled = false;
    }
  }

  document.getElementById("blocks-import-resolve-conflicts").addEventListener("click", function () {
    hideBlocksMessages();
    commitBlockImport(true);
  });
  document.getElementById("blocks-import-skip-conflicts").addEventListener("click", function () {
    hideBlocksMessages();
    commitBlockImport(false);
  });

  document.getElementById("blocks-import-form").addEventListener("submit", async function (e) {
    e.preventDefault();
    hideBlocksMessages();
    const fileInput = document.getElementById("blocks-import-file");
    const report = document.getElementById("blocks-import-report");
    const conflictBox = document.getElementById("blocks-import-conflicts");
    const btn = document.getElementById("blocks-import-submit");
    if (!fileInput.files || !fileInput.files[0]) {
      showBlocksError("Choose the semester XLSX workbook.");
      return;
    }
    pendingImportFile = fileInput.files[0];
    pendingImportSheet = document.getElementById("blocks-import-sheet").value;
    const formData = new FormData();
    formData.append("file", pendingImportFile);
    formData.append("sheet", pendingImportSheet);
    formData.append("preview", "1");
    btn.disabled = true;
    report.hidden = true;
    conflictBox.hidden = true;
    try {
      const result = await Api.api("/class-blocks/import.php", {
        method: "POST",
        body: formData,
      });
      const conflicts = (result.data && result.data.conflicts) || [];
      if (conflicts.length) {
        renderImportConflicts(result.data);
        showBlocksError(
          conflicts.length +
            " room conflict(s) found. Review the year and block below, then choose whether to find vacant rooms."
        );
        return;
      }
      await commitBlockImport(false);
    } catch (err) {
      showBlocksError(err.message || "Import failed.");
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById("create-btn").addEventListener("click", function () {
    hideBlocksMessages();
    document.getElementById("create-form").reset();
    if (yearLevels.length) {
      document.getElementById("create-yearLevel").value = yearLevels[0];
    }
    modal.hidden = false;
  });

  document.getElementById("create-cancel").addEventListener("click", function () {
    modal.hidden = true;
  });

  document.getElementById("blocks-back-to-cards").addEventListener("click", function () {
    showBlocksCards();
  });

  document.getElementById("create-form").addEventListener("submit", async function (e) {
    e.preventDefault();
    const btn = document.getElementById("create-submit");
    btn.disabled = true;
    try {
      const payload = {
        yearLevel: document.getElementById("create-yearLevel").value,
        studentType: document.getElementById("create-studentType").value,
      };
      const num = document.getElementById("create-blockNumber").value.trim();
      const name = document.getElementById("create-name").value.trim();
      if (num) payload.blockNumber = Number(num);
      if (name) payload.name = name;
      const res = await Api.api("/class-blocks/create.php", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      modal.hidden = true;
      showBlocksSuccess('Created "' + (res.data.block && res.data.block.name) + '".');
      await loadBlocks();
    } catch (err) {
      showBlocksError(err.message || "Create failed.");
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById("students-back-to-cards").addEventListener("click", function () {
    showStudentCards();
  });

  try {
    await loadBlocks();
    if (window.ScheduleCommandPanel) {
      window.ScheduleCommandPanel.bind({
        api: Api,
        onError: showBlocksError,
        onSuccess: showBlocksSuccess,
        onSaved: async function () {
          await loadBlocks();
        },
      });
    }
    const hash = (window.location.hash || "#blocks").replace("#", "");
    setPane(hash === "timetable" ? "timetable" : "blocks");
  } catch (err) {
    showBlocksError(err.message || "Unable to load student schedule module.");
  }
})();
