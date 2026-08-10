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

  let browseMode = "room"; // room | faculty
  let allRows = [];
  let roomsCatalog = [];
  let facultyCatalog = [];

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
          kind: "room",
          title: roomTitle(row),
          meta: row.roomType ? String(row.roomType) : "Room",
          rows: [],
        };
      }
      map[id].rows.push(row);
    });
    // Include empty rooms from catalog so availability is visible.
    roomsCatalog.forEach(function (r) {
      if (!map[r.uid]) {
        map[r.uid] = {
          id: r.uid,
          kind: "room",
          title: r.label || r.building + " / " + r.name,
          meta: (r.roomType || "Room") + " · free",
          rows: [],
        };
      } else if (r.roomType && map[r.uid].meta === "Room") {
        map[r.uid].meta = String(r.roomType);
      }
    });
    return Object.keys(map)
      .map(function (k) {
        return map[k];
      })
      .sort(function (a, b) {
        return a.title.localeCompare(b.title);
      });
  }

  function buildFacultyCards(rows) {
    const map = {};
    rows.forEach(function (row) {
      const fid = (row.facultyId || "").trim();
      const id = fid !== "" ? fid : "TBF";
      if (!map[id]) {
        map[id] = {
          id: id,
          kind: "faculty",
          title: instructorLabel(row),
          meta: id === "TBF" ? "Unassigned instructor" : "Faculty",
          rows: [],
        };
      }
      map[id].rows.push(row);
    });
    facultyCatalog.forEach(function (f) {
      if (!map[f.uid]) {
        map[f.uid] = {
          id: f.uid,
          kind: "faculty",
          title: f.fullName,
          meta: "No meetings this term",
          rows: [],
        };
      }
    });
    if (!map.TBF) {
      map.TBF = {
        id: "TBF",
        kind: "faculty",
        title: "TBF",
        meta: "Unassigned instructor",
        rows: [],
      };
    }
    return Object.keys(map)
      .map(function (k) {
        return map[k];
      })
      .sort(function (a, b) {
        if (a.id === "TBF") return -1;
        if (b.id === "TBF") return 1;
        return a.title.localeCompare(b.title);
      });
  }

  function renderCards() {
    const cards =
      browseMode === "room" ? buildRoomCards(allRows) : buildFacultyCards(allRows);

    if (!cards.length) {
      cardsEl.innerHTML = "";
      cardsEmpty.hidden = false;
      return;
    }
    cardsEmpty.hidden = true;

    cardsEl.innerHTML = cards
      .map(function (card) {
        const count = card.rows.length;
        const days = uniqueDays(card.rows);
        const status =
          count === 0
            ? "Available"
            : count + " meeting" + (count === 1 ? "" : "s") + " · " + days + " day" + (days === 1 ? "" : "s");
        return (
          '<button type="button" class="schedule-pick-card" data-kind="' +
          escapeHtml(card.kind) +
          '" data-id="' +
          escapeHtml(card.id) +
          '">' +
          '<span class="schedule-pick-card__eyebrow">' +
          escapeHtml(card.meta) +
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
      })
      .join("");

    cardsEl.querySelectorAll(".schedule-pick-card").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openDetail(btn.getAttribute("data-kind"), btn.getAttribute("data-id"));
      });
    });
  }

  function rowsForCard(kind, id) {
    if (kind === "room") {
      return allRows.filter(function (row) {
        return String(row.roomId) === String(id);
      });
    }
    if (id === "TBF") {
      return allRows.filter(function (row) {
        return !(row.facultyId || "").trim();
      });
    }
    return allRows.filter(function (row) {
      return String(row.facultyId) === String(id);
    });
  }

  function cardTitle(kind, id) {
    if (kind === "room") {
      const room = roomsCatalog.find(function (r) {
        return r.uid === id;
      });
      if (room) return room.label || room.building + " / " + room.name;
      const hit = allRows.find(function (r) {
        return String(r.roomId) === String(id);
      });
      return hit ? roomTitle(hit) : "Room";
    }
    if (id === "TBF") return "TBF (unassigned)";
    const fac = facultyCatalog.find(function (f) {
      return f.uid === id;
    });
    if (fac) return fac.fullName;
    const hit = allRows.find(function (r) {
      return String(r.facultyId) === String(id);
    });
    return hit ? instructorLabel(hit) : "Faculty";
  }

  function openDetail(kind, id) {
    const rows = rowsForCard(kind, id);
    const title = cardTitle(kind, id);

    cardsView.hidden = true;
    detailView.hidden = false;
    listPanel.hidden = false;

    detailTitle.textContent = title;
    detailSub.textContent =
      (kind === "room" ? "Room weekly availability" : "Faculty weekly teaching load") +
      " · " +
      rows.length +
      " meeting(s)";

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
          "<td class=\"cell-strong\">" +
          escapeHtml(instructorLabel(row)) +
          "</td>" +
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
  }

  function showCards() {
    detailView.hidden = true;
    listPanel.hidden = true;
    cardsView.hidden = false;
    gridEl.innerHTML = "";
    bodyEl.innerHTML = "";
  }

  async function loadCatalogs() {
    const [facRes, roomRes] = await Promise.all([
      Api.api("/faculty/index.php"),
      Api.api("/rooms/index.php"),
    ]);
    facultyCatalog = facRes.data.faculty || [];
    roomsCatalog = roomRes.data.rooms || [];
  }

  async function load() {
    alertEl.classList.remove("show");
    try {
      const result = await Api.api("/schedules/faculty-view.php");
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
      alertEl.textContent = err.message || "Unable to load faculty schedules.";
      alertEl.classList.add("show");
    }
  }

  document.querySelectorAll(".module-tab[data-browse]").forEach(function (tab) {
    tab.addEventListener("click", function () {
      browseMode = tab.getAttribute("data-browse") || "room";
      document.querySelectorAll(".module-tab[data-browse]").forEach(function (t) {
        t.classList.toggle("is-active", t === tab);
      });
      showCards();
      renderCards();
    });
  });

  document.getElementById("back-to-cards").addEventListener("click", function () {
    showCards();
  });

  await loadCatalogs();
  await load();
})();
