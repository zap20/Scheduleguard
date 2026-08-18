(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Faculty"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("schedule-alert");
  const gridEl = document.getElementById("schedule-grid");
  const emptyEl = document.getElementById("schedule-empty");
  const loadSummaryEl = document.getElementById("load-summary");
  const resultCountEl = document.getElementById("result-count");

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

  function parseTimeToMinutes(value) {
    const raw = String(value || "").trim();
    const m = raw.match(/^(\d{1,2}):(\d{2})/);
    if (!m) return 0;
    return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
  }

  function formatLoadExact(value) {
    const n = Number(value) || 0;
    return String(parseFloat((Math.round(n * 10000) / 10000).toFixed(4)));
  }

  function rowsOverlap(a, b) {
    if (String(a.day || "").toUpperCase() !== String(b.day || "").toUpperCase()) {
      return false;
    }
    const aStart = parseTimeToMinutes(a.startTime);
    const aEnd = parseTimeToMinutes(a.endTime);
    const bStart = parseTimeToMinutes(b.startTime);
    const bEnd = parseTimeToMinutes(b.endTime);
    return aStart < bEnd && aEnd > bStart;
  }

  /** Same conflict-free keep rule as the Dean faculty grid. */
  function withoutOverlappingMeetings(rows) {
    const sorted = rows.slice().sort(function (a, b) {
      const dayCmp = String(a.day || "").localeCompare(String(b.day || ""));
      if (dayCmp !== 0) return dayCmp;
      const t = parseTimeToMinutes(a.startTime) - parseTimeToMinutes(b.startTime);
      if (t !== 0) return t;
      return parseTimeToMinutes(a.endTime) - parseTimeToMinutes(b.endTime);
    });
    const kept = [];
    sorted.forEach(function (row) {
      const clashes = kept.some(function (k) {
        return rowsOverlap(k, row);
      });
      if (!clashes) kept.push(row);
    });
    return kept;
  }

  function teachingLoad(rows) {
    const offerings = {};
    const subjectsMeta = {};
    let contactMinutes = 0;

    rows.forEach(function (row) {
      const sid = String(row.subjectId || row.subjectCode || "").trim();
      if (!sid) return;
      const blockKey =
        String(row.classBlockId || "").trim() ||
        String(row.blockName || "").trim() ||
        "default";
      const offerKey = sid + "::" + blockKey;

      let piece = 0;
      if (typeof row.subjectLoad === "number" && row.subjectLoad > 0) {
        piece = row.subjectLoad;
      } else {
        const hours =
          (Number(row.lectureHours) || 0) + (Number(row.labHours) || 0);
        piece = hours > 0 ? hours / 3 : 0;
      }

      if (!offerings[offerKey]) {
        offerings[offerKey] = true;
      }
      if (!subjectsMeta[sid]) {
        subjectsMeta[sid] = row.subjectCode || sid;
      }

      const start = parseTimeToMinutes(row.startTime);
      const end = parseTimeToMinutes(row.endTime);
      if (end > start) contactMinutes += end - start;
    });

    const contactHours = contactMinutes / 60;
    const load =
      contactMinutes > 0 ? Math.round((contactHours / 3) * 100) / 100 : 0;

    const classBlocks = {};
    rows.forEach(function (row) {
      const name = (row.blockName || "").trim();
      if (name) classBlocks[name] = true;
    });

    return {
      subjectCount: Object.keys(subjectsMeta).length,
      load: load,
      classBlockCount: Object.keys(classBlocks).length,
      meetingCount: rows.length,
    };
  }

  function toGridBlocks(rows) {
    return rows.map(function (row) {
      const parts = [row.subjectCode];
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

  async function load() {
    try {
      const result = await Api.api("/faculty/schedule.php");
      let rows = result.data.schedules || [];
      // Match Dean faculty timetable: do not show overlapping meetings.
      rows = withoutOverlappingMeetings(rows);

      const term = result.data.term;
      const termChip = document.getElementById("term-chip");
      if (termChip && term) {
        termChip.textContent = term.label || "Sem " + term.semester;
      }

      const tl = teachingLoad(rows);
      if (resultCountEl) {
        resultCountEl.textContent = formatLoadExact(tl.load);
      }
      if (loadSummaryEl) {
        loadSummaryEl.innerHTML =
          "Teaching load: " +
          escapeHtml(formatLoadExact(tl.load)) +
          "<br>Total Blocks: " +
          escapeHtml(String(tl.classBlockCount)) +
          "<br>" +
          escapeHtml(String(tl.subjectCount)) +
          " subject" +
          (tl.subjectCount === 1 ? "" : "s") +
          " · " +
          escapeHtml(String(tl.meetingCount)) +
          " meeting" +
          (tl.meetingCount === 1 ? "" : "s");
      }

      const body = document.getElementById("schedule-body");

      if (!rows.length) {
        gridEl.innerHTML = "";
        body.innerHTML = "";
        emptyEl.hidden = false;
        return;
      }

      emptyEl.hidden = true;
      gridEl.innerHTML = window.renderScheduleGrid(toGridBlocks(rows));

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
    } catch (err) {
      alertEl.textContent = err.message || "Unable to load teaching schedule.";
      alertEl.classList.add("show");
    }
  }

  load();
})();
