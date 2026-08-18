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
  const cardsView = document.getElementById("cards-view");
  const cardsEl = document.getElementById("schedule-cards");
  const cardsEmpty = document.getElementById("cards-empty");
  const detailView = document.getElementById("detail-view");
  const detailTitle = document.getElementById("detail-title");
  const detailSub = document.getElementById("detail-sub");
  const gridEl = document.getElementById("schedule-grid");
  const emptyEl = document.getElementById("schedule-empty");
  const bodyEl = document.getElementById("schedule-body");
  const listPanel = document.getElementById("list-panel");

  let allRows = [];
  let roomsCatalog = [];

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function instructorLabel(row) {
    const name = (row.instructor || row.facultyName || "").trim();
    return name !== "" ? name : "TBF";
  }

  function blockLabel(row) {
    return (row.blockName || "").trim() || "—";
  }

  function roomTitle(row) {
    return (row.roomLabel || row.roomName || "Room").trim();
  }

  function toGridBlocks(rows) {
    return rows.map(function (row) {
      const parts = [row.subjectCode, "TBF"];
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

  function parseTimeToMinutes(value) {
    const raw = String(value || "").trim();
    const m = raw.match(/^(\d{1,2}):(\d{2})/);
    if (!m) return 0;
    return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
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

  function hasConflictRows(rows) {
    if (!rows || !rows.length) return false;
    if (
      rows.some(function (row) {
        return String(row.status || "").toLowerCase() === "conflict";
      })
    ) {
      return true;
    }
    for (let i = 0; i < rows.length; i += 1) {
      for (let j = i + 1; j < rows.length; j += 1) {
        if (rowsOverlap(rows[i], rows[j])) return true;
      }
    }
    return false;
  }

  function uniqueDays(rows) {
    const seen = {};
    rows.forEach(function (r) {
      seen[r.day] = true;
    });
    return Object.keys(seen).length;
  }

  function buildRoomCards(rows) {
    const map = {};
    rows.forEach(function (row) {
      const id = row.roomId || roomTitle(row);
      if (!map[id]) {
        map[id] = {
          id: id,
          title: roomTitle(row),
          meta: row.roomType ? String(row.roomType) : "Room",
          rows: [],
          hasConflict: false,
        };
      }
      map[id].rows.push(row);
      if (String(row.status || "").toLowerCase() === "conflict") {
        map[id].hasConflict = true;
      }
    });
    // Only rooms that have at least one TBF meeting.
    return Object.keys(map)
      .map(function (k) {
        const card = map[k];
        card.hasConflict = card.hasConflict || hasConflictRows(card.rows);
        return card;
      })
      .filter(function (card) {
        return card.rows.length > 0;
      })
      .sort(function (a, b) {
        return a.title.localeCompare(b.title);
      });
  }

  function cardHtml(card) {
    const count = card.rows.length;
    const days = uniqueDays(card.rows);
    const status =
      count +
      " TBF meeting" +
      (count === 1 ? "" : "s") +
      " · " +
      days +
      " day" +
      (days === 1 ? "" : "s");
    const cls = card.hasConflict
      ? "schedule-pick-card schedule-pick-card--conflict"
      : "schedule-pick-card";
    return (
      '<button type="button" class="' +
      escapeHtml(cls) +
      '" data-id="' +
      escapeHtml(card.id) +
      '">' +
      '<span class="schedule-pick-card__eyebrow">' +
      escapeHtml(card.hasConflict ? card.meta + " · conflict" : card.meta) +
      "</span>" +
      '<span class="schedule-pick-card__title">' +
      escapeHtml(card.title) +
      "</span>" +
      '<span class="schedule-pick-card__stat">' +
      escapeHtml(status) +
      "</span>" +
      '<span class="schedule-pick-card__cta">View weekly grid →</span>' +
      "</button>"
    );
  }

  function roomTypeKey(meta) {
    const m = String(meta || "").toUpperCase();
    if (m.indexOf("LAB") !== -1) return "LAB";
    if (m.indexOf("LECTURE") !== -1) return "LECTURE";
    return "OTHER";
  }

  function renderCards() {
    const cards = buildRoomCards(allRows);

    if (!cards.length) {
      cardsEl.innerHTML = "";
      cardsEmpty.hidden = false;
      return;
    }
    cardsEmpty.hidden = true;

    const groups = { LECTURE: [], LAB: [], OTHER: [] };
    cards.forEach(function (card) {
      // Prefer roomType from catalog when available.
      const room = roomsCatalog.find(function (r) {
        return r.uid === card.id;
      });
      const meta = room && room.roomType ? room.roomType : card.meta;
      card.meta = meta;
      groups[roomTypeKey(meta)].push(card);
    });
    const order = ["LECTURE", "LAB", "OTHER"];
    const labels = {
      LECTURE: "Lecture rooms",
      LAB: "Lab rooms",
      OTHER: "Other rooms",
    };
    cardsEl.className = "";
    cardsEl.innerHTML = order
      .filter(function (key) {
        return groups[key].length > 0;
      })
      .map(function (key) {
        return (
          '<section class="room-grid-section">' +
          '<h3 class="room-grid-section__label">' +
          escapeHtml(labels[key]) +
          "</h3>" +
          '<div class="schedule-card-grid">' +
          groups[key].map(cardHtml).join("") +
          "</div></section>"
        );
      })
      .join("");

    cardsEl.querySelectorAll(".schedule-pick-card").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openDetail(btn.getAttribute("data-id"));
      });
    });
  }

  function rowsForRoom(id) {
    return allRows.filter(function (row) {
      return String(row.roomId) === String(id);
    });
  }

  function roomLabel(id) {
    const room = roomsCatalog.find(function (r) {
      return r.uid === id;
    });
    if (room) return room.label || room.building + " / " + room.name;
    const hit = allRows.find(function (r) {
      return String(r.roomId) === String(id);
    });
    return hit ? roomTitle(hit) : "Room";
  }

  function openDetail(id) {
    const rows = rowsForRoom(id);
    const title = roomLabel(id);

    cardsView.hidden = true;
    detailView.hidden = false;
    listPanel.hidden = false;

    detailTitle.textContent = title;
    detailSub.textContent =
      "TBF weekly grid by room · " + rows.length + " unassigned meeting(s)";

    if (!rows.length) {
      gridEl.innerHTML = "";
      emptyEl.hidden = false;
      bodyEl.innerHTML = "";
      return;
    }

    emptyEl.hidden = true;
    gridEl.innerHTML = window.renderScheduleGrid(toGridBlocks(rows));
    bodyEl.innerHTML = rows
      .map(function (row) {
        return (
          "<tr>" +
          '<td class="cell-strong">TBF</td>' +
          '<td class="cell-strong">' +
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
  }

  function showCards() {
    detailView.hidden = true;
    listPanel.hidden = true;
    cardsView.hidden = false;
    gridEl.innerHTML = "";
    bodyEl.innerHTML = "";
  }

  async function loadCatalogs() {
    const roomRes = await Api.api("/rooms/index.php");
    roomsCatalog = roomRes.data.rooms || [];
  }

  async function load() {
    alertEl.classList.remove("show");
    try {
      const result = await Api.api("/schedules/faculty-view.php?facultyId=TBF");
      allRows = result.data.schedules || [];
      const term = result.data.term;
      const termChip = document.getElementById("term-chip");
      if (termChip && term) {
        termChip.textContent = term.label || "Sem " + term.semester;
      }
      document.getElementById("result-count").textContent = String(allRows.length);
      showCards();
      renderCards();
    } catch (err) {
      alertEl.textContent = err.message || "Unable to load TBF schedules.";
      alertEl.classList.add("show");
    }
  }

  document.getElementById("back-to-cards").addEventListener("click", function () {
    showCards();
  });

  await loadCatalogs();
  await load();
})();
