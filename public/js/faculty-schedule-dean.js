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
  const detailAddLoadBtn = document.getElementById("detail-add-load-btn");
  const gridEl = document.getElementById("schedule-grid");
  const emptyEl = document.getElementById("schedule-empty");
  const bodyEl = document.getElementById("schedule-body");
  const listPanel = document.getElementById("list-panel");

  let browseMode = "room"; // room | faculty
  let allRows = [];
  let roomsCatalog = [];
  let facultyCatalog = [];
  let facultyFilterText = "";
  let facultyFilterId = "";
  let currentDetailKind = "";
  let currentDetailId = "";
  let pendingLoadCommand = "";
  let pendingLoadPreview = null;
  let pendingLoadFacultyId = "";
  let pendingLoadSubjectCode = "";
  let pendingSelectedOfferingKeys = [];

  const facultyFilterBar = document.getElementById("faculty-filter-bar");
  const facultyFilterTextEl = document.getElementById("faculty-filter-text");
  const facultyFilterSelectEl = document.getElementById("faculty-filter-select");

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

  /**
   * Teaching load = Σ (lecture+lab hours ÷ 3) once per subject offering
   * (same subject in another class block counts again).
   * Also equals total scheduled contact hours ÷ 3 when meetings match subject hours.
   */
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
        offerings[offerKey] = {
          code: row.subjectCode || sid,
          blockName: (row.blockName || "").trim() || "—",
          hours: (Number(row.lectureHours) || 0) + (Number(row.labHours) || 0),
          load: Math.round(piece * 10000) / 10000,
        };
      }

      if (!subjectsMeta[sid]) {
        subjectsMeta[sid] = row.subjectCode || sid;
      }

      // Contact time from actual meetings (for exact hours÷3 check).
      const start = parseTimeToMinutes(row.startTime);
      const end = parseTimeToMinutes(row.endTime);
      if (end > start) contactMinutes += end - start;
    });

    const offeringList = Object.keys(offerings).map(function (k) {
      return offerings[k];
    });
    let loadFromOfferings = 0;
    offeringList.forEach(function (o) {
      loadFromOfferings += o.load;
    });

    const contactHours = contactMinutes / 60;
    const loadFromContact = contactHours / 3;

    // Prefer contact-hours÷3 when meetings exist (matches scheduled reality).
    const load =
      contactMinutes > 0
        ? Math.round(loadFromContact * 100) / 100
        : Math.round(loadFromOfferings * 100) / 100;

    const classBlocks = {};
    rows.forEach(function (row) {
      const name = (row.blockName || "").trim();
      if (name) classBlocks[name] = true;
    });

    return {
      subjectCount: Object.keys(subjectsMeta).length,
      offeringCount: offeringList.length,
      load: load,
      loadExact: Math.round(loadFromContact * 10000) / 10000,
      contactHours: Math.round(contactHours * 100) / 100,
      subjects: offeringList,
      classBlockCount: Object.keys(classBlocks).length,
      classBlockNames: Object.keys(classBlocks).sort(),
      meetingCount: rows.length,
    };
  }

  function parseTimeToMinutes(value) {
    const raw = String(value || "").trim();
    const m = raw.match(/^(\d{1,2}):(\d{2})/);
    if (!m) return 0;
    return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
  }

  function formatLoadExact(value) {
    const n = Number(value) || 0;
    // Show up to 4 decimals, trim trailing zeros.
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

  /**
   * Drop meetings that overlap an earlier kept meeting (same faculty/room view).
   * Keeps a conflict-free set so the grid never shows OVERLAP cells.
   */
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

  function toGridBlocks(rows) {
    return rows.map(function (row) {
      return {
        day: row.day,
        startTime: row.startTime,
        endTime: row.endTime,
        label: window.ScheduleGrid.meetingGridLabel(row, {
          includeInstructor: true,
          includeBlock: true,
        }),
        scheduleId: row.uid || "",
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
          hasConflict: false,
        };
      }
      map[id].rows.push(row);
      if (String(row.status || "").toLowerCase() === "conflict") {
        map[id].hasConflict = true;
      }
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
          hasConflict: false,
        };
      } else if (r.roomType && map[r.uid].meta === "Room") {
        map[r.uid].meta = String(r.roomType);
      }
    });
    return Object.keys(map)
      .map(function (k) {
        const card = map[k];
        card.hasConflict = card.hasConflict || hasConflictRows(card.rows);
        return card;
      })
      .sort(function (a, b) {
        return a.title.localeCompare(b.title);
      });
  }

  function buildFacultyCards(rows) {
    const map = {};
    rows.forEach(function (row) {
      const fid = (row.facultyId || "").trim();
      if (fid === "") return; // TBF has its own Schedules → TBF page
      if (!map[fid]) {
        map[fid] = {
          id: fid,
          kind: "faculty",
          title: instructorLabel(row),
          meta: "Faculty",
          rows: [],
          hasConflict: false,
          teachingLoad: 0,
          loadBand: "underload",
        };
      }
      map[fid].rows.push(row);
      if (String(row.status || "").toLowerCase() === "conflict") {
        map[fid].hasConflict = true;
      }
    });
    facultyCatalog.forEach(function (f) {
      if (!map[f.uid]) {
        map[f.uid] = {
          id: f.uid,
          kind: "faculty",
          title: f.fullName,
          meta: "No meetings this term",
          rows: [],
          hasConflict: false,
          teachingLoad: 0,
          loadBand: "underload",
        };
      }
    });
    return Object.keys(map)
      .map(function (k) {
        const card = map[k];
        const clean = withoutOverlappingMeetings(card.rows);
        const tl = teachingLoad(clean);
        card.teachingLoad = Number(tl.load) || 0;
        card.loadBand = card.teachingLoad >= 8 ? "full" : "underload";
        card.hasConflict = card.hasConflict || hasConflictRows(card.rows);
        return card;
      })
      .sort(function (a, b) {
        return a.title.localeCompare(b.title);
      });
  }

  function cardHtml(card) {
    const count = card.rows.length;
    const days = uniqueDays(card.rows);
    let status;
    if (count === 0) {
      status = "Available · load 0";
    } else if (card.kind === "faculty") {
      const clean = withoutOverlappingMeetings(card.rows);
      const tl = teachingLoad(clean);
      status =
        "Teaching load: " +
        formatLoadExact(tl.load) +
        " · Total Blocks: " +
        tl.classBlockCount;
    } else {
      status =
        count +
        " meeting" +
        (count === 1 ? "" : "s") +
        " · " +
        days +
        " day" +
        (days === 1 ? "" : "s");
    }
    const classes = [
      "schedule-pick-card",
      card.hasConflict ? "schedule-pick-card--conflict" : "",
      card.kind === "faculty" && card.loadBand === "full"
        ? "schedule-pick-card--full"
        : card.kind === "faculty"
          ? "schedule-pick-card--underload"
          : "",
    ]
      .filter(Boolean)
      .join(" ");
    const eyebrow = card.hasConflict
      ? card.meta + " · conflict"
      : card.kind === "faculty"
        ? card.meta + (card.loadBand === "full" ? " · full load" : " · underload")
        : card.meta;
    return (
      '<button type="button" class="' +
      escapeHtml(classes) +
      '" data-kind="' +
      escapeHtml(card.kind) +
      '" data-id="' +
      escapeHtml(card.id) +
      '">' +
      '<span class="schedule-pick-card__eyebrow">' +
      escapeHtml(eyebrow) +
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

  function syncFacultyFilterBar() {
    if (!facultyFilterBar) return;
    facultyFilterBar.hidden = browseMode !== "faculty";
  }

  function populateFacultyFilterSelect(cards) {
    if (!facultyFilterSelectEl) return;
    const prev = facultyFilterId;
    const options =
      '<option value="">All faculty…</option>' +
      cards
        .map(function (card) {
          return (
            '<option value="' +
            escapeHtml(card.id) +
            '">' +
            escapeHtml(card.title) +
            "</option>"
          );
        })
        .join("");
    facultyFilterSelectEl.innerHTML = options;
    facultyFilterSelectEl.value = prev;
    if (facultyFilterSelectEl.value !== prev) {
      facultyFilterId = "";
      facultyFilterSelectEl.value = "";
    }
  }

  function applyFacultyFilters(cards) {
    const q = facultyFilterText.trim().toLowerCase();
    return cards.filter(function (card) {
      if (facultyFilterId && String(card.id) !== String(facultyFilterId)) {
        return false;
      }
      if (q && String(card.title || "").toLowerCase().indexOf(q) === -1) {
        return false;
      }
      return true;
    });
  }

  function renderCards() {
    syncFacultyFilterBar();
    let cards =
      browseMode === "room" ? buildRoomCards(allRows) : buildFacultyCards(allRows);

    if (browseMode === "faculty") {
      populateFacultyFilterSelect(cards);
      cards = applyFacultyFilters(cards);
    }

    if (!cards.length) {
      cardsEl.innerHTML = "";
      cardsEmpty.hidden = false;
      return;
    }
    cardsEmpty.hidden = true;

    if (browseMode === "room") {
      const groups = { LECTURE: [], LAB: [], OTHER: [] };
      cards.forEach(function (card) {
        groups[roomTypeKey(card.meta)].push(card);
      });
      const order = ["LECTURE", "LAB", "OTHER"];
      const labels = { LECTURE: "Lecture rooms", LAB: "Lab rooms", OTHER: "Other rooms" };
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
    } else {
      const full = cards.filter(function (card) {
        return card.loadBand === "full";
      });
      const under = cards.filter(function (card) {
        return card.loadBand !== "full";
      });
      cardsEl.className = "";
      cardsEl.innerHTML =
        (full.length
          ? '<section class="room-grid-section"><h3 class="room-grid-section__label">Faculty · Full load (8+)</h3><div class="schedule-card-grid">' +
            full.map(cardHtml).join("") +
            "</div></section>"
          : "") +
        (under.length
          ? '<section class="room-grid-section"><h3 class="room-grid-section__label">Faculty · Underload (&lt; 8)</h3><div class="schedule-card-grid">' +
            under.map(cardHtml).join("") +
            "</div></section>"
          : "");
    }

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
    currentDetailKind = kind || "";
    currentDetailId = id || "";
    // Show all meetings (including room/faculty overlaps) — same as mobile.
    // Teaching-load chips still use withoutOverlappingMeetings() in cardHtml.
    const rows = rowsForCard(kind, id);
    const title = cardTitle(kind, id);

    cardsView.hidden = true;
    detailView.hidden = false;
    listPanel.hidden = false;

    detailTitle.textContent = title;
    if (kind === "faculty") {
      if (detailAddLoadBtn) detailAddLoadBtn.hidden = false;
      const tl = teachingLoad(rows);
      detailSub.innerHTML =
        "Teaching load: " +
        escapeHtml(formatLoadExact(tl.load)) +
        "<br>Total Blocks: " +
        escapeHtml(String(tl.classBlockCount));
    } else {
      if (detailAddLoadBtn) detailAddLoadBtn.hidden = true;
      detailSub.textContent =
        "Room weekly availability · " + rows.length + " meeting(s)";
    }
    if (!rows.length) {
      gridEl.innerHTML = "";
      emptyEl.hidden = false;
      bodyEl.innerHTML = "";
      return;
    }

    emptyEl.hidden = true;
    gridEl.innerHTML = window.renderScheduleGrid(toGridBlocks(rows), {
      clickable: kind === "faculty",
    });
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

  async function removeLoadFromGrid(payload) {
    const scheduleId = (payload && payload.scheduleId) || "";
    if (!scheduleId) return;
    const label = (payload && payload.label) || "this load";
    const ok = window.confirm(
      "Are you sure you want to remove this load?\n\n" +
        label +
        "\n\nThis removes the whole subject + block group for this faculty (all related meetings return to TBF)."
    );
    if (!ok) return;

    alertEl.classList.remove("show");
    try {
      const res = await Api.api("/schedules/remove-load.php", {
        method: "POST",
        body: JSON.stringify({ scheduleId: scheduleId }),
      });
      const result = (res && res.data && res.data.result) || {};
      const kind = currentDetailKind;
      const id = currentDetailId;
      await load({ keepDetail: true });
      if (kind && id) {
        openDetail(kind, id);
      }
      if (result.subjectCode) {
        alertEl.classList.remove("alert-error");
        alertEl.classList.add("alert-success");
        alertEl.textContent =
          "Removed " +
          result.subjectCode +
          " / " +
          (result.blockName || "offering") +
          " (" +
          (result.updatedMeetings || 0) +
          " meeting(s) → TBF).";
        alertEl.classList.add("show");
        setTimeout(function () {
          alertEl.classList.remove("show");
          alertEl.classList.remove("alert-success");
          alertEl.classList.add("alert-error");
        }, 3500);
      }
    } catch (err) {
      alertEl.classList.remove("alert-success");
      alertEl.classList.add("alert-error");
      alertEl.textContent = err.message || "Unable to remove load.";
      alertEl.classList.add("show");
    }
  }

  function showCards() {
    currentDetailKind = "";
    currentDetailId = "";
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

  async function load(opts) {
    const keepDetail = !!(opts && opts.keepDetail);
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
      if (keepDetail && currentDetailKind && currentDetailId) {
        renderCards();
        return;
      }
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

  if (facultyFilterTextEl) {
    facultyFilterTextEl.addEventListener("input", function () {
      facultyFilterText = facultyFilterTextEl.value || "";
      renderCards();
    });
  }
  if (facultyFilterSelectEl) {
    facultyFilterSelectEl.addEventListener("change", function () {
      facultyFilterId = facultyFilterSelectEl.value || "";
      renderCards();
    });
  }

  document.getElementById("back-to-cards").addEventListener("click", function () {
    showCards();
  });

  if (detailAddLoadBtn) {
    detailAddLoadBtn.addEventListener("click", function () {
      if (currentDetailKind !== "faculty" || !currentDetailId) return;
      const select = document.getElementById("load-faculty-id");
      if (select) select.value = String(currentDetailId);
      const input = document.getElementById("load-command-text");
      if (input) input.focus();
      const panel = document.getElementById("load-command-panel");
      if (panel && panel.scrollIntoView) {
        panel.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  }

  if (window.ScheduleGrid && typeof window.ScheduleGrid.bindScheduleGridClicks === "function") {
    window.ScheduleGrid.bindScheduleGridClicks(gridEl, removeLoadFromGrid);
  } else {
    gridEl.addEventListener("click", function (ev) {
      const btn = ev.target && ev.target.closest
        ? ev.target.closest(".sg-block-label.is-action")
        : null;
      if (!btn || !gridEl.contains(btn)) return;
      removeLoadFromGrid({
        scheduleId: btn.getAttribute("data-schedule-id") || "",
        label: (btn.textContent || "").trim(),
      });
    });
  }

  // ── Load assignment UI ───────────────────────────────────────────────────
  const loadAlert = document.getElementById("load-command-alert");
  const loadSuccess = document.getElementById("load-command-success");
  const loadOfferingsPanel = document.getElementById("load-offerings-panel");
  const loadOfferingsBody = document.getElementById("load-offerings-body");
  const loadFacultyInfo = document.getElementById("load-faculty-info");
  const loadSelectionTotals = document.getElementById("load-selection-totals");
  const loadFetchBtn = document.getElementById("load-fetch-btn");
  const loadConfirmBtn = document.getElementById("load-confirm-assign");
  const loadCancelBtn = document.getElementById("load-cancel-parse");
  const loadSubjectSearch = document.getElementById("load-subject-search");
  const loadSubjectDropdown = document.getElementById("load-subject-dropdown");
  const loadSubjectChip = document.getElementById("load-subject-chip");
  const loadSubjectChipLabel = document.getElementById("load-subject-chip-label");
  const loadSubjectChipMeta = document.getElementById("load-subject-chip-meta");
  const loadSubjectClearBtn = document.getElementById("load-subject-clear");
  const loadFacultySelect = document.getElementById("load-faculty-id");
  const loadTargetNumber = document.getElementById("load-target-number");

  let loadPickedSubject = null;
  let loadCurrentOfferings = [];
  let loadCurrentFacultyData = null;
  let subjectSearchTimer = null;

  function hideLoadMessages() {
    loadAlert.classList.remove("show");
    loadSuccess.classList.remove("show");
  }

  function showLoadError(message) {
    loadSuccess.classList.remove("show");
    loadAlert.textContent = message;
    loadAlert.classList.add("show");
    loadAlert.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function showLoadSuccess(message) {
    loadAlert.classList.remove("show");
    loadSuccess.textContent = message;
    loadSuccess.classList.add("show");
  }

  function resetLoadUi() {
    if (loadOfferingsPanel) loadOfferingsPanel.hidden = true;
    if (loadOfferingsBody) loadOfferingsBody.innerHTML = "";
    loadCurrentOfferings = [];
    loadCurrentFacultyData = null;
    if (loadFacultyInfo) loadFacultyInfo.innerHTML = "";
    if (loadSelectionTotals) loadSelectionTotals.innerHTML = "";
    if (loadConfirmBtn) loadConfirmBtn.disabled = true;
    pendingSelectedOfferingKeys = [];
    pendingLoadFacultyId = "";
    pendingLoadSubjectCode = "";
  }

  function clearSubjectPick() {
    loadPickedSubject = null;
    if (loadSubjectSearch) { loadSubjectSearch.value = ""; loadSubjectSearch.hidden = false; }
    if (loadSubjectDropdown) loadSubjectDropdown.hidden = true;
    if (loadSubjectChip) loadSubjectChip.hidden = true;
    if (loadFetchBtn) loadFetchBtn.disabled = true;
    resetLoadUi();
    hideLoadMessages();
  }

  function setSubjectPick(subj) {
    loadPickedSubject = subj;
    if (loadSubjectSearch) loadSubjectSearch.hidden = true;
    if (loadSubjectDropdown) loadSubjectDropdown.hidden = true;
    if (loadSubjectChip) loadSubjectChip.hidden = false;
    if (loadSubjectChipLabel) {
      loadSubjectChipLabel.textContent = subj.code + " \u2014 " + subj.title;
    }
    if (loadSubjectChipMeta) {
      const loadPer = Math.round(((subj.lectureHours || 0) + (subj.labHours || 0)) / 3 * 10000) / 10000;
      loadSubjectChipMeta.textContent =
        "Lec " + (subj.lectureHours || 0) +
        " \u00b7 Lab " + (subj.labHours || 0) +
        " \u00b7 Load/offering \u2248 " + loadPer;
    }
    if (loadFetchBtn) {
      loadFetchBtn.disabled = !loadFacultySelect || !loadFacultySelect.value;
    }
    resetLoadUi();
  }

  function selectedOfferingKeysFromTable() {
    const checked = [];
    document
      .querySelectorAll("#load-offerings-table input.load-offering-pick:checked")
      .forEach(function (el) {
        const key = el.getAttribute("data-offering-key");
        if (key) checked.push(key);
      });
    return checked;
  }

  function recalcSelectionTotals() {
    if (!loadSelectionTotals) return;
    const keys = selectedOfferingKeysFromTable();
    pendingSelectedOfferingKeys = keys;
    let contactHours = 0;
    let loadVal = 0;
    loadCurrentOfferings.forEach(function (o) {
      if (keys.indexOf(String(o.offeringKey)) === -1) return;
      contactHours += Number(o.contactHours) || 0;
      loadVal += Number(o.load) || 0;
    });
    loadVal = Math.round(loadVal * 10000) / 10000;
    contactHours = Math.round(contactHours * 100) / 100;
    const target = loadTargetNumber ? Number(loadTargetNumber.value) || 0 : 0;
    const current = loadCurrentFacultyData ? Number(loadCurrentFacultyData.currentLoad) || 0 : 0;
    const afterAssign = Math.round((current + loadVal) * 100) / 100;
    loadSelectionTotals.innerHTML = keys.length === 0
      ? '<span class="cell-muted">No offerings selected yet.</span>'
      : "<strong>Selected:</strong> " +
        escapeHtml(String(keys.length)) + " offering(s) \u00b7 " +
        escapeHtml(String(contactHours)) + " h \u00f7 3 = <strong>" +
        escapeHtml(String(loadVal)) + "</strong> load" +
        (target > 0 ? " \u00b7 target <strong>" + escapeHtml(String(target)) + "</strong>" : "") +
        " \u00b7 current " + escapeHtml(String(current)) +
        " \u2192 after assign <strong>" + escapeHtml(String(afterAssign)) + "</strong>";
    if (loadConfirmBtn) {
      loadConfirmBtn.disabled = keys.length === 0;
    }
  }

  function renderOfferingsTable(offerings, subject) {
    if (!loadOfferingsBody) return;
    if (!offerings.length) {
      loadOfferingsBody.innerHTML =
        '<tr><td colspan="8" class="cell-muted" style="text-align:center;padding:1.25rem">No conflict-free schedules found for this faculty and subject.</td></tr>';
      return;
    }
    loadOfferingsBody.innerHTML = offerings
      .map(function (o) {
        const lec = o.lectureHours != null ? o.lectureHours : (subject ? subject.lectureHours : 0);
        const lab = o.labHours != null ? o.labHours : (subject ? subject.labHours : 0);
        return (
          "<tr>" +
          '<td><input type="checkbox" class="load-offering-pick" data-offering-key="' +
          escapeHtml(String(o.offeringKey || "")) +
          '" /></td>' +
          "<td>" + escapeHtml(o.blockLabel || "\u2014") + "</td>" +
          '<td class="cell-muted">' + escapeHtml(o.scheduleText || "\u2014") + "</td>" +
          "<td>" + escapeHtml(String(lec || 0)) + "</td>" +
          "<td>" + escapeHtml(String(lab || 0)) + "</td>" +
          "<td>" + escapeHtml(String(o.contactHours != null ? o.contactHours : "\u2014")) + "</td>" +
          "<td>" +
          escapeHtml(String(o.load != null ? o.load : "\u2014")) +
          (o.loadFormula ? '<div class="cell-muted">' + escapeHtml(o.loadFormula) + "</div>" : "") +
          "</td>" +
          '<td class="cell-muted"></td>' +
          "</tr>"
        );
      })
      .join("");
  }

  function fillLoadFacultySelect() {
    if (!loadFacultySelect) return;
    const keep = loadFacultySelect.value;
    loadFacultySelect.innerHTML = '<option value="">Select faculty\u2026</option>';
    facultyCatalog
      .slice()
      .sort(function (a, b) {
        return String(a.fullName || "").localeCompare(String(b.fullName || ""));
      })
      .forEach(function (f) {
        const opt = document.createElement("option");
        opt.value = f.uid;
        const emp = f.employmentType === "PartTime" ? "Part-time" : "Regular";
        const min = f.minLoad != null ? f.minLoad : (f.employmentType === "PartTime" ? 3 : 8);
        opt.textContent = f.fullName + " \u00b7 " + emp + " (min " + min + ")";
        loadFacultySelect.appendChild(opt);
      });
    if (keep) loadFacultySelect.value = keep;
  }

  // Subject autocomplete search
  if (loadSubjectSearch) {
    loadSubjectSearch.addEventListener("input", function () {
      clearTimeout(subjectSearchTimer);
      const q = loadSubjectSearch.value.trim();
      if (q.length < 1) {
        if (loadSubjectDropdown) loadSubjectDropdown.hidden = true;
        return;
      }
      subjectSearchTimer = setTimeout(async function () {
        try {
          const res = await Api.api("/subjects/list.php?q=" + encodeURIComponent(q) + "&status=Active");
          const subjects = (res.data && res.data.subjects) || [];
          if (!loadSubjectDropdown) return;
          if (!subjects.length) {
            loadSubjectDropdown.innerHTML = '<li class="load-subject-option load-subject-option--empty">No subjects found</li>';
            loadSubjectDropdown.hidden = false;
            return;
          }
          loadSubjectDropdown.innerHTML = subjects
            .slice(0, 12)
            .map(function (s) {
              return (
                '<li class="load-subject-option" data-uid="' + escapeHtml(s.uid) +
                '" data-code="' + escapeHtml(s.code) +
                '" data-title="' + escapeHtml(s.title) +
                '" data-lec="' + escapeHtml(String(s.lectureHours || 0)) +
                '" data-lab="' + escapeHtml(String(s.labHours || 0)) +
                '"><strong>' + escapeHtml(s.code) + "</strong> " +
                escapeHtml(s.title) +
                ' <span class="cell-muted">Lec ' + escapeHtml(String(s.lectureHours || 0)) +
                " Lab " + escapeHtml(String(s.labHours || 0)) + "</span>" +
                "</li>"
              );
            })
            .join("");
          loadSubjectDropdown.hidden = false;
        } catch (_) {
          if (loadSubjectDropdown) loadSubjectDropdown.hidden = true;
        }
      }, 220);
    });

    loadSubjectSearch.addEventListener("keydown", function (ev) {
      if (ev.key === "Escape" && loadSubjectDropdown) loadSubjectDropdown.hidden = true;
    });

    // Close dropdown when clicking outside
    document.addEventListener("click", function (ev) {
      if (loadSubjectDropdown && !loadSubjectDropdown.contains(ev.target) && ev.target !== loadSubjectSearch) {
        loadSubjectDropdown.hidden = true;
      }
    });
  }

  if (loadSubjectDropdown) {
    loadSubjectDropdown.addEventListener("click", function (ev) {
      const li = ev.target && ev.target.closest(".load-subject-option");
      if (!li || li.classList.contains("load-subject-option--empty")) return;
      setSubjectPick({
        uid: li.getAttribute("data-uid"),
        code: li.getAttribute("data-code"),
        title: li.getAttribute("data-title"),
        lectureHours: parseFloat(li.getAttribute("data-lec")) || 0,
        labHours: parseFloat(li.getAttribute("data-lab")) || 0,
      });
      loadSubjectDropdown.hidden = true;
    });
  }

  if (loadSubjectClearBtn) {
    loadSubjectClearBtn.addEventListener("click", clearSubjectPick);
  }

  if (loadFacultySelect) {
    loadFacultySelect.addEventListener("change", function () {
      resetLoadUi();
      hideLoadMessages();
      if (loadFetchBtn) {
        loadFetchBtn.disabled = !loadFacultySelect.value || !loadPickedSubject;
      }
    });
  }

  if (loadFetchBtn) {
    loadFetchBtn.addEventListener("click", async function () {
      hideLoadMessages();
      const facultyId = loadFacultySelect ? loadFacultySelect.value.trim() : "";
      if (!facultyId) { showLoadError("Select a faculty first."); return; }
      if (!loadPickedSubject) { showLoadError("Search and select a subject first."); return; }

      loadFetchBtn.disabled = true;
      loadFetchBtn.textContent = "Loading\u2026";
      resetLoadUi();

      try {
        const params = new URLSearchParams({
          facultyId: facultyId,
          subjectId: loadPickedSubject.uid,
        });
        const res = await Api.api("/schedules/load-offerings.php?" + params.toString());
        const data = res.data || {};
        const offerings = data.offerings || [];
        const subj = data.subject || {};
        const fac = data.faculty || {};

        loadCurrentOfferings = offerings;
        loadCurrentFacultyData = fac;
        pendingLoadFacultyId = facultyId;
        pendingLoadSubjectCode = subj.code || loadPickedSubject.code || "";

        if (loadFacultyInfo) {
          const selOpt = loadFacultySelect.options[loadFacultySelect.selectedIndex];
          const facLabel = selOpt ? selOpt.text : "";
          loadFacultyInfo.innerHTML =
            "<strong>" + escapeHtml(facLabel) + "</strong>" +
            " \u00b7 Current load: <strong>" + escapeHtml(String(fac.currentLoad || 0)) + "</strong>" +
            " (" + escapeHtml(String(fac.currentContactHours || 0)) + " h \u00f7 3)" +
            (data.conflictSkipped > 0
              ? ' \u00b7 <span style="color:var(--danger)">' +
                escapeHtml(String(data.conflictSkipped)) +
                " offering(s) skipped (time conflict)</span>"
              : "");
        }

        renderOfferingsTable(offerings, subj);
        recalcSelectionTotals();
        if (loadOfferingsPanel) loadOfferingsPanel.hidden = false;

        if (!offerings.length) {
          showLoadError(
            "No conflict-free schedules for " + (subj.code || "this subject") +
            (data.conflictSkipped > 0
              ? " \u2014 all " + data.conflictSkipped + " offering(s) conflict with this faculty's schedule."
              : ".")
          );
        }
      } catch (err) {
        showLoadError(err.message || "Failed to load offerings.");
      } finally {
        loadFetchBtn.disabled = false;
        loadFetchBtn.textContent = "Show available schedules";
      }
    });
  }

  if (loadOfferingsPanel) {
    loadOfferingsPanel.addEventListener("change", function (ev) {
      if (ev.target && ev.target.classList && ev.target.classList.contains("load-offering-pick")) {
        recalcSelectionTotals();
      }
    });
  }

  if (loadCancelBtn) {
    loadCancelBtn.addEventListener("click", function () {
      resetLoadUi();
      clearSubjectPick();
    });
  }

  if (loadConfirmBtn) {
    loadConfirmBtn.addEventListener("click", async function () {
      if (!pendingLoadFacultyId || !pendingLoadSubjectCode) return;
      const offeringKeys = selectedOfferingKeysFromTable();
      if (!offeringKeys.length) {
        showLoadError("Select at least one block to assign.");
        return;
      }
      hideLoadMessages();
      loadConfirmBtn.disabled = true;
      loadConfirmBtn.textContent = "Assigning\u2026";
      try {
        const target = loadTargetNumber ? Number(loadTargetNumber.value) || 1 : 1;
        const res = await Api.api("/schedules/assign-load-command.php", {
          method: "POST",
          body: JSON.stringify({
            command: String(target) + " load " + pendingLoadSubjectCode,
            facultyId: pendingLoadFacultyId,
            subjectCode: pendingLoadSubjectCode,
            offeringKeys: offeringKeys,
          }),
        });
        const result = res.data && res.data.result;
        resetLoadUi();
        clearSubjectPick();
        showLoadSuccess(
          result
            ? "Assigned " +
                escapeHtml(String(result.assignedLoad)) +
                " load (" + escapeHtml(String(result.assignedContactHours || "\u2014")) + " h \u00f7 3) of " +
                escapeHtml(result.subject.code) +
                " to " + escapeHtml(result.faculty.fullName) +
                " \u2014 " + (result.assignments || []).length + " offering(s), " +
                result.updatedMeetings + " meeting(s) updated."
            : "Load assigned."
        );
        await load();
      } catch (err) {
        showLoadError(err.message || "Assign failed.");
        loadConfirmBtn.disabled = false;
      } finally {
        loadConfirmBtn.textContent = "Confirm & assign selected";
      }
    });
  }

  // "Add load to this faculty" shortcut from detail panel
  if (detailAddLoadBtn) {
    detailAddLoadBtn.addEventListener("click", function () {
      if (currentDetailKind !== "faculty" || !currentDetailId) return;
      if (loadFacultySelect) loadFacultySelect.value = String(currentDetailId);
      if (loadSubjectSearch) loadSubjectSearch.focus();
      const panel = document.getElementById("load-command-panel");
      if (panel && panel.scrollIntoView) {
        panel.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  }

  await loadCatalogs();
  fillLoadFacultySelect();
  await load();
})();
