(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("schedules-alert");
  const successEl = document.getElementById("schedules-success");
  const conflictPanel = document.getElementById("conflict-panel");
  const bodyEl = document.getElementById("schedules-body");
  const emptyEl = document.getElementById("schedules-empty");
  const countEl = document.getElementById("result-count");

  let faculty = [];
  let rooms = [];
  let departments = [];
  let subjects = [];
  let schedulesById = {};

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

  function statusBadge(status) {
    const key = String(status).toLowerCase();
    const cls =
      key === "confirmed"
        ? "status-present"
        : key === "conflict"
          ? "status-wrong"
          : "status-late";
    return '<span class="status-badge ' + cls + '">' + escapeHtml(status) + "</span>";
  }

  function instructorLabel(row) {
    const name = (row.instructor || row.facultyName || "").trim();
    return name !== "" ? name : "TBF";
  }

  function blockLabel(row) {
    return (row.blockName || "").trim() || "—";
  }

  function fillSelect(select, items, valueKey, labelFn, placeholder) {
    select.innerHTML = "";
    if (placeholder) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = placeholder;
      select.appendChild(opt);
    }
    items.forEach(function (item) {
      const opt = document.createElement("option");
      opt.value = item[valueKey];
      opt.textContent = labelFn(item);
      select.appendChild(opt);
    });
  }

  function queryString() {
    const params = new URLSearchParams();
    const status = document.getElementById("filter-status").value;
    const departmentId = document.getElementById("filter-departmentId").value;
    const academicYear = document.getElementById("filter-academicYear").value;
    const semester = document.getElementById("filter-semester").value;
    const yearLevel = document.getElementById("filter-yearLevel").value;
    const blockName = document.getElementById("filter-blockName").value;
    if (status) params.set("status", status);
    if (departmentId) params.set("departmentId", departmentId);
    if (academicYear) params.set("academicYear", academicYear);
    if (semester) params.set("semester", semester);
    if (yearLevel) params.set("yearLevel", yearLevel);
    if (blockName) params.set("blockName", blockName);
    const qs = params.toString();
    return qs ? "?" + qs : "";
  }

  let availableBlocks = [];

  function refreshBlockFilterOptions(selectedBlockName) {
    const yearLevel = document.getElementById("filter-yearLevel").value;
    const select = document.getElementById("filter-blockName");
    const previous = selectedBlockName != null ? selectedBlockName : select.value;
    select.innerHTML = '<option value="">All blocks</option>';
    availableBlocks
      .filter(function (b) {
        return !yearLevel || b.yearLevel === yearLevel;
      })
      .forEach(function (b) {
        const opt = document.createElement("option");
        opt.value = b.blockName;
        opt.textContent = b.blockName;
        select.appendChild(opt);
      });
    if (previous) {
      select.value = previous;
      if (select.value !== previous) {
        select.value = "";
      }
    }
  }

  function renderConflictPanel(schedule, conflicts) {
    if (!conflicts || !conflicts.length) {
      conflictPanel.hidden = true;
      conflictPanel.innerHTML = "";
      return;
    }

    const rows = conflicts
      .map(function (c) {
        return (
          "<li><strong>" +
          escapeHtml(c.subjectCode || c.subjectName) +
          "</strong> — " +
          escapeHtml(c.facultyName) +
          " / " +
          escapeHtml(c.roomLabel) +
          " · " +
          escapeHtml(c.day) +
          " " +
          escapeHtml(c.startTime) +
          "–" +
          escapeHtml(c.endTime) +
          " <em>(" +
          escapeHtml((c.conflictTypes || []).join(", ")) +
          ")</em></li>"
        );
      })
      .join("");

    conflictPanel.hidden = false;
    conflictPanel.innerHTML =
      "<div class=\"conflict-title\">Conflicts for " +
      escapeHtml(schedule.subjectCode || schedule.subjectName) +
      "</div>" +
      "<p>This schedule was marked <strong>conflict</strong>. Edit a row to resolve, or override to confirm anyway.</p>" +
      "<ul>" +
      rows +
      "</ul>" +
      '<div class="conflict-actions">' +
      '<button type="button" class="btn btn-secondary btn-small" data-edit="' +
      escapeHtml(schedule.uid) +
      '">Edit this schedule</button>' +
      '<button type="button" class="btn btn-small" data-override="' +
      escapeHtml(schedule.uid) +
      '">Confirm anyway (override)</button>' +
      "</div>";

    conflictPanel.querySelector("[data-edit]").addEventListener("click", function () {
      openEdit(schedule.uid);
    });
    conflictPanel.querySelector("[data-override]").addEventListener("click", function () {
      overrideConfirm(schedule.uid);
    });
  }

  function renderScheduleGridFromList(list) {
    const gridEl = document.getElementById("schedule-grid");
    const gridEmpty = document.getElementById("schedule-grid-empty");
    if (!gridEl) return;

    if (!list.length) {
      gridEl.innerHTML = "";
      if (gridEmpty) gridEmpty.hidden = false;
      return;
    }

    if (gridEmpty) gridEmpty.hidden = true;
    const blocks = list.map(function (row) {
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
    gridEl.innerHTML = window.renderScheduleGrid(blocks);
  }

  function renderSchedules(list) {
    schedulesById = {};
    countEl.textContent = String(list.length);
    renderScheduleGridFromList(list);

    if (!list.length) {
      bodyEl.innerHTML = "";
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    bodyEl.innerHTML = list
      .map(function (row) {
        schedulesById[row.uid] = row;
        const canConfirm =
          String(row.status).toLowerCase() === "draft" ||
          String(row.status).toLowerCase() === "conflict";

        return (
          "<tr>" +
          "<td><div class=\"cell-strong\">" +
          escapeHtml(row.subjectCode) +
          "</div><div class=\"cell-muted\">" +
          escapeHtml(row.subjectName) +
          " · " +
          escapeHtml(row.departmentName) +
          "</div><div class=\"cell-muted\">" +
          escapeHtml(row.termLabel || "") +
          "</div></td>" +
          "<td>" +
          escapeHtml(instructorLabel(row)) +
          "</td>" +
          "<td>" +
          escapeHtml(blockLabel(row)) +
          "</td>" +
          "<td>" +
          escapeHtml(row.roomLabel) +
          "</td>" +
          "<td>" +
          escapeHtml(row.day) +
          " " +
          escapeHtml(row.startTime) +
          "–" +
          escapeHtml(row.endTime) +
          "</td>" +
          "<td>" +
          statusBadge(row.status) +
          "</td>" +
          "<td class=\"action-cell\">" +
          (canConfirm
            ? '<button type="button" class="btn btn-small" data-confirm="' +
              escapeHtml(row.uid) +
              '">Confirm</button>'
            : "") +
          '<button type="button" class="btn btn-secondary btn-small" data-edit="' +
          escapeHtml(row.uid) +
          '">Edit</button>' +
          (String(row.status).toLowerCase() === "conflict"
            ? '<button type="button" class="btn btn-small" data-override="' +
              escapeHtml(row.uid) +
              '">Override</button>'
            : "") +
          "</td>" +
          "</tr>"
        );
      })
      .join("");

    bodyEl.querySelectorAll("[data-confirm]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        confirmSchedule(btn.getAttribute("data-confirm"));
      });
    });
    bodyEl.querySelectorAll("[data-edit]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openEdit(btn.getAttribute("data-edit"));
      });
    });
    bodyEl.querySelectorAll("[data-override]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        overrideConfirm(btn.getAttribute("data-override"));
      });
    });
  }

  async function loadSchedules() {
    hideMessages();
    const result = await Api.api("/schedules/list.php" + queryString());
    availableBlocks = (result.data.filterOptions && result.data.filterOptions.blocks) || [];
    refreshBlockFilterOptions(document.getElementById("filter-blockName").value);
    renderSchedules(result.data.schedules || []);
  }

  async function loadSubjectsForDepartment(departmentId, selectEls, selectedId) {
    const params = new URLSearchParams();
    params.set("status", "Active");
    if (departmentId) params.set("departmentId", departmentId);
    const res = await Api.api("/subjects/list.php?" + params.toString());
    subjects = res.data.subjects || [];
    selectEls.forEach(function (el) {
      if (!el) return;
      const current = selectedId || el.value;
      el.innerHTML = '<option value="">Select curriculum subject…</option>';
      subjects.forEach(function (s) {
        const opt = document.createElement("option");
        opt.value = s.uid;
        opt.textContent = s.code + " — " + s.title + " (" + s.yearLevel + ")";
        el.appendChild(opt);
      });
      if (current) el.value = current;
    });
  }

  async function loadLookups() {
    const [facultyRes, roomsRes, deptRes] = await Promise.all([
      Api.api("/faculty/index.php"),
      Api.api("/rooms/index.php"),
      Api.api("/departments/index.php"),
    ]);

    faculty = facultyRes.data.faculty || [];
    rooms = roomsRes.data.rooms || [];
    departments = deptRes.data.departments || [];

    fillSelect(
      document.getElementById("create-facultyId"),
      faculty,
      "uid",
      function (f) {
        return f.fullName + " (" + (f.schoolId || f.email) + ")";
      },
      "TBF (no instructor yet)"
    );
    fillSelect(
      document.getElementById("create-roomId"),
      rooms,
      "uid",
      function (r) {
        return (r.labelWithType || r.label) + (r.capacity ? " · cap " + r.capacity : "");
      },
      "Select room…"
    );
    fillSelect(
      document.getElementById("create-departmentId"),
      departments,
      "uid",
      function (d) {
        return d.name;
      },
      "Select department…"
    );

    fillSelect(
      document.getElementById("edit-facultyId"),
      faculty,
      "uid",
      function (f) {
        return f.fullName;
      },
      "TBF (no instructor yet)"
    );
    fillSelect(
      document.getElementById("edit-roomId"),
      rooms,
      "uid",
      function (r) {
        return r.labelWithType || r.label;
      }
    );
    fillSelect(
      document.getElementById("edit-departmentId"),
      departments,
      "uid",
      function (d) {
        return d.name;
      }
    );

    const filterDept = document.getElementById("filter-departmentId");
    departments.forEach(function (d) {
      const opt = document.createElement("option");
      opt.value = d.uid;
      opt.textContent = d.name;
      filterDept.appendChild(opt);
    });

    await loadSubjectsForDepartment(
      "",
      [
        document.getElementById("create-subjectId"),
        document.getElementById("edit-subjectId"),
      ],
      ""
    );

    document.getElementById("create-departmentId").addEventListener("change", function () {
      loadSubjectsForDepartment(
        this.value,
        [document.getElementById("create-subjectId")],
        ""
      ).catch(function (err) {
        showError(err.message || "Failed to load subjects.");
      });
    });
    document.getElementById("edit-departmentId").addEventListener("change", function () {
      loadSubjectsForDepartment(
        this.value,
        [document.getElementById("edit-subjectId")],
        ""
      ).catch(function (err) {
        showError(err.message || "Failed to load subjects.");
      });
    });
  }

  function openEdit(uid) {
    const row = schedulesById[uid];
    if (!row) return;
    document.getElementById("edit-uid").value = row.uid;
    document.getElementById("edit-facultyId").value = row.facultyId || "";
    document.getElementById("edit-roomId").value = row.roomId;
    document.getElementById("edit-departmentId").value = row.departmentId;
    document.getElementById("edit-academicYear").value = row.academicYear || "";
    document.getElementById("edit-semester").value = row.semester || "1";
    document.getElementById("edit-day").value = row.day;
    document.getElementById("edit-startTime").value = row.startTime;
    document.getElementById("edit-endTime").value = row.endTime;
    loadSubjectsForDepartment(
      row.departmentId,
      [document.getElementById("edit-subjectId")],
      row.subjectId
    )
      .then(function () {
        document.getElementById("edit-modal").hidden = false;
      })
      .catch(function (err) {
        showError(err.message || "Failed to load subjects.");
      });
  }

  async function confirmSchedule(uid) {
    hideMessages();
    try {
      const result = await Api.api("/schedules/confirm.php", {
        method: "POST",
        body: JSON.stringify({ uid: uid }),
      });
      if (result.data.confirmed) {
        conflictPanel.hidden = true;
        showSuccess("Schedule confirmed.");
      } else {
        showError(result.data.message || "Conflicts detected.");
        renderConflictPanel(result.data.schedule, result.data.conflicts || []);
      }
      await loadSchedules();
    } catch (err) {
      showError(err.message || "Confirm failed.");
    }
  }

  async function overrideConfirm(uid) {
    if (
      !window.confirm(
        "Confirm this schedule anyway? Existing room/faculty overlaps will remain."
      )
    ) {
      return;
    }
    hideMessages();
    try {
      await Api.api("/schedules/override.php", {
        method: "POST",
        body: JSON.stringify({ uid: uid }),
      });
      conflictPanel.hidden = true;
      showSuccess("Schedule confirmed via override.");
      await loadSchedules();
    } catch (err) {
      showError(err.message || "Override failed.");
    }
  }

  document.getElementById("create-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    hideMessages();
    try {
      await Api.api("/schedules/create.php", {
        method: "POST",
        body: JSON.stringify({
          facultyId: document.getElementById("create-facultyId").value,
          roomId: document.getElementById("create-roomId").value,
          departmentId: document.getElementById("create-departmentId").value,
          subjectId: document.getElementById("create-subjectId").value,
          academicYear: Number(document.getElementById("create-academicYear").value),
          semester: document.getElementById("create-semester").value,
          day: document.getElementById("create-day").value,
          startTime: document.getElementById("create-startTime").value,
          endTime: document.getElementById("create-endTime").value,
        }),
      });
      document.getElementById("create-form").reset();
      showSuccess("Draft schedule created.");
      await loadSchedules();
    } catch (err) {
      showError(err.message || "Create failed.");
    }
  });

  document.getElementById("import-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    hideMessages();
    const fileInput = document.getElementById("import-file");
    if (!fileInput.files || !fileInput.files[0]) {
      showError("Choose a CSV or XLSX file.");
      return;
    }

    const formData = new FormData();
    formData.append("file", fileInput.files[0]);

    try {
      const result = await Api.api("/schedules/import.php", {
        method: "POST",
        body: formData,
      });
      const report = document.getElementById("import-report");
      report.hidden = false;
      const failed = result.data.failed || [];
      const formatLabel =
        result.data.format === "semester-grid"
          ? "semester grid (TEACHER sheet)"
          : "flat CSV/XLSX";
      report.innerHTML =
        "<strong>Import complete</strong> via " +
        escapeHtml(formatLabel) +
        ": " +
        result.data.importedCount +
        " imported, " +
        result.data.failedCount +
        " failed." +
        (failed.length
          ? "<ul>" +
            failed
              .slice(0, 40)
              .map(function (f) {
                return (
                  "<li>Row " +
                  escapeHtml(f.row) +
                  (f.source ? " (" + escapeHtml(f.source) + ")" : "") +
                  ": " +
                  escapeHtml(f.error) +
                  "</li>"
                );
              })
              .join("") +
            "</ul>"
          : "");
      showSuccess("Import finished.");
      fileInput.value = "";
      await loadSchedules();
    } catch (err) {
      showError(err.message || "Import failed.");
    }
  });

  document.getElementById("filter-form").addEventListener("submit", function (event) {
    event.preventDefault();
    loadSchedules().catch(function (err) {
      showError(err.message || "Unable to load schedules.");
    });
  });

  document.getElementById("filter-yearLevel").addEventListener("change", function () {
    refreshBlockFilterOptions("");
  });

  document.getElementById("edit-cancel").addEventListener("click", function () {
    document.getElementById("edit-modal").hidden = true;
  });

  document.getElementById("edit-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    hideMessages();
    try {
      await Api.api("/schedules/update.php", {
        method: "POST",
        body: JSON.stringify({
          uid: document.getElementById("edit-uid").value,
          facultyId: document.getElementById("edit-facultyId").value,
          roomId: document.getElementById("edit-roomId").value,
          departmentId: document.getElementById("edit-departmentId").value,
          subjectId: document.getElementById("edit-subjectId").value,
          academicYear: Number(document.getElementById("edit-academicYear").value),
          semester: document.getElementById("edit-semester").value,
          day: document.getElementById("edit-day").value,
          startTime: document.getElementById("edit-startTime").value,
          endTime: document.getElementById("edit-endTime").value,
        }),
      });
      document.getElementById("edit-modal").hidden = true;
      conflictPanel.hidden = true;
      showSuccess("Schedule updated (back to draft).");
      await loadSchedules();
    } catch (err) {
      showError(err.message || "Update failed.");
    }
  });

  let pendingParsed = null;
  let pendingPlans = [];
  let pendingOptions = [];
  let pendingCommandText = "";
  let pendingSemester = "";

  const parseCard = document.getElementById("command-parse-card");
  const optionsEl = document.getElementById("command-options");
  const semesterWrap = document.getElementById("command-semester-wrap");

  function resetCommandUi() {
    pendingParsed = null;
    pendingPlans = [];
    pendingOptions = [];
    parseCard.hidden = true;
    optionsEl.hidden = true;
    optionsEl.innerHTML = "";
    semesterWrap.hidden = true;
  }

  function renderParseDetails(parsed) {
    document.getElementById("command-confirm-summary").textContent =
      parsed.confirmSummary || "";
    const needsSemester = !!(parsed.needsSemesterConfirm || (parsed.missing || []).indexOf("semester") !== -1);
    semesterWrap.hidden = !needsSemester;
    if (needsSemester && parsed.semester) {
      document.getElementById("command-semester").value = parsed.semester;
    } else if (needsSemester) {
      document.getElementById("command-semester").value = "1st Semester";
    }
    const details = document.getElementById("command-parse-details");
    details.innerHTML =
      "<div><dt>Year level</dt><dd>" +
      escapeHtml(parsed.yearLevel || "—") +
      "</dd></div>" +
      "<div><dt>Student type</dt><dd>" +
      escapeHtml(parsed.studentType || "—") +
      "</dd></div>" +
      "<div><dt>Curriculum year</dt><dd>" +
      escapeHtml(parsed.curriculumYear != null ? parsed.curriculumYear : "—") +
      (parsed.needsCurriculumYearConfirm ? " (default)" : "") +
      "</dd></div>" +
      "<div><dt>Semester</dt><dd>" +
      escapeHtml(parsed.semester || "(will use current / selection)") +
      "</dd></div>" +
      "<div><dt>Blocks</dt><dd>" +
      escapeHtml(parsed.blockCount != null ? parsed.blockCount : 1) +
      "</dd></div>" +
      "<div><dt>Days</dt><dd>" +
      escapeHtml(
        parsed.days && parsed.days.length
          ? parsed.days.join(", ")
          : parsed.dayCount != null
            ? parsed.dayCount + " day(s) (Mon…)"
            : "Full week"
      ) +
      "</dd></div>" +
      "<div><dt>Department</dt><dd>" +
      escapeHtml(parsed.departmentId || "—") +
      "</dd></div>";
  }

  function renderGeneratedOptions(payload) {
    pendingPlans = payload.plans || [];
    pendingOptions = (payload.result && payload.result.options) || [];
    const nextNames = payload.nextBlockNames || [];
    if (pendingPlans.length === 0) {
      optionsEl.hidden = false;
      optionsEl.innerHTML =
        "<p class=\"empty-state\">No conflict-free plans were generated. Adjust block count, day span, or curriculum subjects.</p>";
      return;
    }
    optionsEl.hidden = false;
    optionsEl.innerHTML =
      "<h3 class=\"form-title\">Ranked plans</h3>" +
      "<p class=\"panel-sub\">Saving a plan creates: <strong>" +
      escapeHtml(nextNames.join(", ") || "next block(s)") +
      "</strong>.</p>" +
      pendingPlans
        .map(function (plan, idx) {
          const blockHtml = (plan.blocks || [])
            .map(function (block) {
              const rows = (block.assignments || [])
                .map(function (a) {
                  return (
                    "<li><strong>" +
                    escapeHtml(a.subjectCode) +
                    "</strong> — " +
                    escapeHtml(a.day) +
                    " " +
                    escapeHtml(a.startTime) +
                    "–" +
                    escapeHtml(a.endTime) +
                    " · " +
                    escapeHtml(a.facultyName) +
                    " · " +
                    escapeHtml(a.roomLabel) +
                    "</li>"
                  );
                })
                .join("");
              return (
                "<div style=\"margin:0.5rem 0 0.75rem\">" +
                "<div class=\"conflict-title\">" +
                escapeHtml(block.previewBlockName || "Block") +
                " · score " +
                escapeHtml(block.score) +
                "</div>" +
                "<ul>" +
                rows +
                "</ul></div>"
              );
            })
            .join("");
          return (
            '<div class="conflict-panel" style="margin-bottom:0.75rem">' +
            "<div class=\"conflict-title\">Plan #" +
            escapeHtml(plan.rank) +
            " · score " +
            escapeHtml(plan.score) +
            " · " +
            escapeHtml(plan.blockCount) +
            " block(s)" +
            (plan.daysLabel
              ? " · " + escapeHtml(plan.daysLabel)
              : plan.dayCount != null
                ? " · " + escapeHtml(plan.dayCount) + " day(s)"
                : "") +
            "</div>" +
            blockHtml +
            '<button type="button" class="btn btn-small" data-save-plan="' +
            idx +
            '">Save plan (' +
            escapeHtml((plan.previewBlockNames || []).join(", ")) +
            ")</button>" +
            "</div>"
          );
        })
        .join("");
  }

  document.getElementById("command-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    hideMessages();
    optionsEl.hidden = true;
    optionsEl.innerHTML = "";
    pendingCommandText = document.getElementById("command-text").value.trim();
    try {
      const res = await Api.api("/schedules/parse-command.php", {
        method: "POST",
        body: JSON.stringify({ command: pendingCommandText }),
      });
      pendingParsed = res.data.parsed;
      renderParseDetails(pendingParsed);
      parseCard.hidden = false;
      if (!(pendingParsed.ready || (pendingParsed.missing || []).length === 1 && pendingParsed.missing[0] === "semester")) {
        if ((pendingParsed.missing || []).length) {
          showError(
            "Could not fully parse the command. Missing: " +
              pendingParsed.missing.join(", ") +
              ". Adjust the wording and parse again."
          );
        }
      }
    } catch (err) {
      resetCommandUi();
      showError(err.message || "Parse failed.");
    }
  });

  document.getElementById("command-cancel-parse").addEventListener("click", function () {
    resetCommandUi();
  });

  document
    .getElementById("command-confirm-generate")
    .addEventListener("click", async function () {
      hideMessages();
      if (!pendingCommandText) return;
      pendingSemester = semesterWrap.hidden
        ? pendingParsed && pendingParsed.semester
          ? pendingParsed.semester
          : ""
        : document.getElementById("command-semester").value;
      try {
        const payload = { command: pendingCommandText };
        if (pendingSemester) payload.semester = pendingSemester;
        const res = await Api.api("/schedules/generate-from-command.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        pendingParsed = res.data.parsed;
        renderParseDetails(pendingParsed);
        renderGeneratedOptions(res.data);
        showSuccess(
          "Generated " +
            ((res.data.plans && res.data.plans.length) || 0) +
            " plan(s). Choose one to save as " +
            ((res.data.nextBlockNames || []).join(", ") || "the next block(s)") +
            "."
        );
      } catch (err) {
        showError(err.message || "Generate failed.");
      }
    });

  optionsEl.addEventListener("click", async function (event) {
    const btn = event.target.closest("[data-save-plan]");
    if (!btn) return;
    const idx = Number(btn.getAttribute("data-save-plan"));
    const plan = pendingPlans[idx];
    if (!plan) return;
    hideMessages();
    try {
      const payload = {
        command: pendingCommandText,
        plan: plan,
      };
      if (pendingSemester) payload.semester = pendingSemester;
      const res = await Api.api("/schedules/save-option.php", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      showSuccess(
        'Saved "' +
          (res.data.blockNames || []).join(", ") +
          '" — ' +
          res.data.confirmedCount +
          " confirmed, " +
          res.data.conflictCount +
          " conflict."
      );
      resetCommandUi();
      document.getElementById("command-text").value = "";
      await loadSchedules();
    } catch (err) {
      showError(err.message || "Save failed.");
    }
  });

  loadLookups()
    .then(async function () {
      const bootstrap = await Api.api("/schedules/list.php");
      const term = bootstrap.data.term || {};
      const year = String(term.academicYear || new Date().getFullYear());
      const semester = term.semester || "1";
      document.getElementById("create-academicYear").value = year;
      document.getElementById("create-semester").value = semester;
      document.getElementById("filter-academicYear").value = year;
      document.getElementById("filter-semester").value = semester;
      await loadSchedules();
    })
    .catch(function (err) {
      showError(err.message || "Unable to initialize schedules page.");
    });
})();
