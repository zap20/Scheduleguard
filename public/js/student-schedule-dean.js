(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName + " (" + user.role + ")";
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("schedule-alert");
  const gridEl = document.getElementById("schedule-grid");
  const emptyEl = document.getElementById("schedule-empty");
  const bodyEl = document.getElementById("schedule-body");
  const studentSelect = document.getElementById("filter-studentId");

  const blocksAlert = document.getElementById("blocks-alert");
  const blocksSuccess = document.getElementById("blocks-success");
  const blocksBody = document.getElementById("blocks-body");
  const blocksEmpty = document.getElementById("blocks-empty");
  const modal = document.getElementById("create-modal");
  let yearLevels = [];
  let timetableLoaded = false;

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

  function renderBlocks(list) {
    document.getElementById("blocks-count").textContent = String(list.length);
    if (!list.length) {
      blocksBody.innerHTML = "";
      blocksEmpty.hidden = false;
      return;
    }
    blocksEmpty.hidden = true;
    blocksBody.innerHTML = list
      .map(function (row) {
        return (
          "<tr>" +
          "<td class=\"cell-strong\">" +
          escapeHtml(row.name) +
          '<div class="cell-muted">' +
          escapeHtml(row.termLabel || "") +
          "</div></td>" +
          "<td>" +
          escapeHtml(row.yearLevel) +
          "</td>" +
          "<td>" +
          escapeHtml(row.studentType || "regular") +
          "</td>" +
          "<td>" +
          escapeHtml(row.memberCount) +
          "</td>" +
          "<td>" +
          escapeHtml(row.scheduleCount) +
          "</td>" +
          "<td><span class=\"status-badge status-present\">" +
          escapeHtml(row.status) +
          "</span></td>" +
          "<td class=\"action-cell\">" +
          '<button type="button" class="btn btn-secondary btn-small" data-view-schedule="' +
          escapeHtml(row.uid) +
          '" data-view-name="' +
          escapeHtml(row.name) +
          '">View schedule</button>' +
          "</td>" +
          "</tr>"
        );
      })
      .join("");

    blocksBody.querySelectorAll("[data-view-schedule]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openBlockSchedule(
          btn.getAttribute("data-view-schedule"),
          btn.getAttribute("data-view-name") || "Block"
        );
      });
    });
  }

  async function openBlockSchedule(classBlockId, blockName) {
    const modal = document.getElementById("block-schedule-modal");
    const grid = document.getElementById("block-schedule-grid");
    const body = document.getElementById("block-schedule-body");
    const empty = document.getElementById("block-schedule-empty");
    document.getElementById("block-schedule-title").textContent =
      "Schedule · " + blockName;
    document.getElementById("block-schedule-sub").textContent =
      "Weekly grid of meetings linked to this class block.";
    grid.innerHTML = "";
    body.innerHTML = "";
    empty.hidden = true;
    modal.hidden = false;
    try {
      const res = await Api.api(
        "/class-blocks/schedules.php?classBlockId=" +
          encodeURIComponent(classBlockId)
      );
      const rows = res.data.schedules || [];
      if (!rows.length) {
        empty.hidden = false;
        return;
      }
      grid.innerHTML = window.renderScheduleGrid(
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
      body.innerHTML = rows
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
    } catch (err) {
      modal.hidden = true;
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
  }

  async function loadStudents() {
    const res = await Api.api("/students/index.php");
    const students = res.data.students || [];
    students.forEach(function (s) {
      const opt = document.createElement("option");
      opt.value = s.uid;
      opt.textContent =
        s.fullName + (s.schoolId ? " (" + s.schoolId + ")" : "");
      studentSelect.appendChild(opt);
    });
  }

  async function loadTimetable() {
    alertEl.classList.remove("show");
    const params = new URLSearchParams();
    const studentId = studentSelect.value;
    if (studentId) params.set("studentId", studentId);
    const qs = params.toString() ? "?" + params.toString() : "";
    const result = await Api.api("/schedules/student-view.php" + qs);
    const rows = result.data.schedules || [];
    const term = result.data.term;
    if (term) {
      document.getElementById("term-chip").textContent =
        term.label || "Sem " + term.semester;
    }
    document.getElementById("result-count").textContent = String(rows.length);
    timetableLoaded = true;

    if (!rows.length) {
      gridEl.innerHTML = "";
      bodyEl.innerHTML = "";
      emptyEl.hidden = false;
      return;
    }

    emptyEl.hidden = true;
    gridEl.innerHTML = window.renderScheduleGrid(toGridBlocks(rows));
    bodyEl.innerHTML = rows
      .map(function (row) {
        return (
          "<tr>" +
          "<td class=\"cell-strong\">" +
          escapeHtml(row.studentName) +
          "</td>" +
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
          escapeHtml(row.roomLabel) +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
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

  document.getElementById("block-schedule-close").addEventListener("click", function () {
    document.getElementById("block-schedule-modal").hidden = true;
  });

  document.getElementById("block-schedule-modal").addEventListener("click", function (e) {
    if (e.target === document.getElementById("block-schedule-modal")) {
      document.getElementById("block-schedule-modal").hidden = true;
    }
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

  document.getElementById("filter-form").addEventListener("submit", function (e) {
    e.preventDefault();
    loadTimetable().catch(function (err) {
      alertEl.textContent = err.message || "Unable to load student schedules.";
      alertEl.classList.add("show");
    });
  });

  try {
    await loadStudents();
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
