(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;

  function compareCardLabels(a, b) {
    return window.ScheduleGrid.compareCardLabels(a, b);
  }

  function catalogRoomTitle(room) {
    return (
      window.ScheduleGrid.formatScheduleRoom({
        roomBuilding: room.building,
        roomName: room.name,
        roomLabel: room.label,
      }) ||
      room.label ||
      room.building + " / " + room.name
    );
  }
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  const viewerDepartmentId = user.departmentId ? String(user.departmentId) : "";
  let viewerDepartmentName = "";

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
  let pendingLoadParsed = null;
  let pendingLoadFacultyId = "";
  let pendingLoadSubjectCode = "";
  let pendingSelectedOfferingKeys = [];

  function facultyCatalogById() {
    const map = {};
    facultyCatalog.forEach(function (f) {
      map[String(f.uid)] = f;
    });
    return map;
  }

  function syncDepartmentChip() {
    const chip = document.getElementById("department-chip");
    if (!chip) return;
    if (viewerDepartmentName) {
      chip.textContent = viewerDepartmentName;
      chip.hidden = false;
      chip.title = "Showing faculty and rooms for your department only";
    } else {
      chip.hidden = true;
    }
  }

  function filterRowsForViewer(rows) {
    if (!viewerDepartmentId) return rows.slice();
    const catalogIds = facultyCatalogById();
    return rows.filter(function (row) {
      const fid = (row.facultyId || "").trim();
      return fid === "" || !!catalogIds[fid];
    });
  }

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
          title: catalogRoomTitle(r),
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
        return compareCardLabels(a.title, b.title);
      });
  }

  function facultyEmploymentLabel(employmentType) {
    return employmentType === "PartTime" ? "Part-time" : "Full-time";
  }

  function applyFacultyEmployment(card, catalogFaculty) {
    if (catalogFaculty) {
      card.employmentType = catalogFaculty.employmentType || "Regular";
      card.employmentLabel = facultyEmploymentLabel(card.employmentType);
      card.minLoad =
        catalogFaculty.minLoad != null
          ? catalogFaculty.minLoad
          : card.employmentType === "PartTime"
            ? 3
            : 8;
    } else {
      card.employmentType = "Regular";
      card.employmentLabel = "Full-time";
      card.minLoad = 8;
    }
    return card;
  }

  function facultyCardEyebrow(card) {
    const emp = card.employmentLabel || "Full-time";
    if (card.hasConflict) {
      return emp + " · conflict";
    }
    const count = card.rows.length;
    if (count === 0) {
      return emp + " · no meetings · underload";
    }
    const loadPart = card.loadBand === "full" ? "full load" : "underload";
    return emp + " · " + loadPart;
  }

  function buildFacultyCards(rows) {
    const map = {};
    const catalogIds = facultyCatalogById();
    rows.forEach(function (row) {
      const fid = (row.facultyId || "").trim();
      if (fid === "") return; // TBF has its own Schedules → TBF page
      if (viewerDepartmentId && !catalogIds[fid]) return;
      if (!map[fid]) {
        const catalogFaculty = catalogIds[fid];
        map[fid] = applyFacultyEmployment(
          {
            id: fid,
            kind: "faculty",
            title: catalogFaculty ? catalogFaculty.fullName : instructorLabel(row),
            meta: "Faculty",
            rows: [],
            hasConflict: false,
            teachingLoad: 0,
            loadBand: "underload",
          },
          catalogFaculty
        );
      }
      map[fid].rows.push(row);
      if (String(row.status || "").toLowerCase() === "conflict") {
        map[fid].hasConflict = true;
      }
    });
    facultyCatalog.forEach(function (f) {
      if (!map[f.uid]) {
        map[f.uid] = applyFacultyEmployment(
          {
            id: f.uid,
            kind: "faculty",
            title: f.fullName,
            meta: "No meetings this term",
            rows: [],
            hasConflict: false,
            teachingLoad: 0,
            loadBand: "underload",
          },
          f
        );
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
        if (!card.employmentLabel) {
          applyFacultyEmployment(card, catalogIds[card.id]);
        }
        return card;
      })
      .sort(function (a, b) {
        return compareCardLabels(a.title, b.title);
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
      card.kind === "faculty" && card.employmentType === "PartTime"
        ? "schedule-pick-card--part-time"
        : "",
      card.kind === "faculty" && card.employmentType !== "PartTime"
        ? "schedule-pick-card--full-time"
        : "",
      card.kind === "faculty" && card.loadBand === "full"
        ? "schedule-pick-card--full"
        : card.kind === "faculty"
          ? "schedule-pick-card--underload"
          : "",
    ]
      .filter(Boolean)
      .join(" ");
    const eyebrow =
      card.kind === "faculty"
        ? facultyCardEyebrow(card)
        : card.hasConflict
          ? card.meta + " · conflict"
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

  function facultyHasNoLoad(card) {
    return (Number(card.teachingLoad) || 0) <= 0.001 || !(card.rows && card.rows.length);
  }

  function syncFacultyNoLoadChip(facultyCards) {
    const chip = document.getElementById("faculty-no-load-chip");
    if (!chip) return;
    if (browseMode !== "faculty" || !facultyCards.length) {
      chip.hidden = true;
      return;
    }
    const noLoadCount = facultyCards.filter(facultyHasNoLoad).length;
    chip.hidden = false;
    chip.textContent = noLoadCount + " faculty with no load yet";
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
      syncFacultyNoLoadChip(cards);
      populateFacultyFilterSelect(cards);
      cards = applyFacultyFilters(cards);
    } else {
      syncFacultyNoLoadChip([]);
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
          groups[key].sort(function (a, b) {
            return compareCardLabels(a.title, b.title);
          });
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
      }).sort(function (a, b) {
        return compareCardLabels(a.title, b.title);
      });
      const noLoad = cards
        .filter(function (card) {
          return facultyHasNoLoad(card);
        })
        .sort(function (a, b) {
          return compareCardLabels(a.title, b.title);
        });
      const under = cards
        .filter(function (card) {
          return card.loadBand !== "full" && !facultyHasNoLoad(card);
        })
        .sort(function (a, b) {
          return compareCardLabels(a.title, b.title);
        });
      cardsEl.className = "";
      cardsEl.innerHTML =
        (full.length
          ? '<section class="room-grid-section"><h3 class="room-grid-section__label">Faculty · Full load (8+)</h3><div class="schedule-card-grid">' +
            full.map(cardHtml).join("") +
            "</div></section>"
          : "") +
        (noLoad.length
          ? '<section class="room-grid-section"><h3 class="room-grid-section__label">Faculty · No load yet (' +
            noLoad.length +
            ')</h3><div class="schedule-card-grid">' +
            noLoad.map(cardHtml).join("") +
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

  function offeringGroupKey(row) {
    const sid = String(row.subjectId || row.subjectCode || "").trim();
    const block =
      String(row.classBlockId || "").trim() ||
      String(row.blockName || "").trim() ||
      "default";
    return sid + "::" + block;
  }

  function removeLoadButtonHtml(scheduleId, title) {
    if (!scheduleId) return "";
    return (
      '<button type="button" class="btn-icon-remove load-remove-btn" data-schedule-id="' +
      escapeHtml(String(scheduleId)) +
      '" title="' +
      escapeHtml(title || "Remove this load") +
      '" aria-label="Remove load">&times;</button>'
    );
  }

  async function removeLoadByScheduleId(scheduleId, label, opts) {
    const scheduleIdTrim = (scheduleId || "").trim();
    if (!scheduleIdTrim) return;
    const skipConfirm = !!(opts && opts.skipConfirm);
    if (!skipConfirm) {
      const ok = window.confirm(
        "Remove this meeting?\n\n" +
          (label || "Subject / time") +
          "\n\nOnly this time slot returns to TBF. Other meetings for the same subject stay assigned."
      );
      if (!ok) return;
    }

    alertEl.classList.remove("show");
    try {
      const res = await Api.api("/schedules/remove-load.php", {
        method: "POST",
        body: JSON.stringify({ scheduleId: scheduleIdTrim }),
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
        const when =
          result.day && result.startTime
            ? " (" + result.day + " " + result.startTime + ")"
            : "";
        alertEl.textContent =
          "Removed " +
          result.subjectCode +
          when +
          " → TBF.";
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
      const clean = withoutOverlappingMeetings(rows);
      const tl = teachingLoad(clean);
      const overCap = tl.load >= 8.99;
      detailSub.innerHTML =
        (overCap
          ? '<div class="load-cap-warning" style="margin-bottom:0.5rem">Teaching load is <strong>' +
            escapeHtml(formatLoadExact(tl.load)) +
            "</strong>. Use &times; on a block below to remove excess load.</div>"
          : "") +
        "Teaching load: <strong>" +
        escapeHtml(formatLoadExact(tl.load)) +
        "</strong>" +
        (tl.contactHours > 0
          ? " &nbsp;(<strong>" + escapeHtml(String(tl.contactHours)) + " h</strong> &divide; 3)"
          : "") +
        " &nbsp;&middot;&nbsp; Total blocks: <strong>" +
        escapeHtml(String(tl.classBlockCount)) +
        "</strong>" +
        " &nbsp;&middot;&nbsp; Meetings: <strong>" +
        escapeHtml(String(tl.meetingCount)) +
        "</strong>";
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

    const removeHead = document.getElementById("schedule-remove-head");
    if (removeHead) {
      removeHead.hidden = kind !== "faculty";
    }

    bodyEl.innerHTML = rows
      .map(function (row) {
        const removeCell =
          kind === "faculty"
            ? "<td class=\"cell-action\">" +
              removeLoadButtonHtml(
                row.uid,
                "Remove " +
                  (row.subjectCode || "meeting") +
                  " · " +
                  row.day +
                  " " +
                  row.startTime
              ) +
              "</td>"
            : "";
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
          removeCell +
          "</tr>"
        );
      })
      .join("");
  }

  async function removeLoadFromGrid(payload) {
    await removeLoadByScheduleId(
      (payload && payload.scheduleId) || "",
      (payload && payload.label) || "this load",
      { skipConfirm: false }
    );
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

  async function refreshScheduleNavBadges() {
    if (
      !window.ScheduleGuardShell ||
      typeof window.ScheduleGuardShell.refreshScheduleNavSummaries !== "function"
    ) {
      return;
    }
    let role = "Dean";
    try {
      const raw = window.localStorage.getItem("scheduleguard_user");
      if (raw) {
        const user = JSON.parse(raw);
        if (user && user.role) role = user.role;
      }
    } catch (_) {
      /* ignore */
    }
    await window.ScheduleGuardShell.refreshScheduleNavSummaries(role);
  }

  async function load(opts) {
    const keepDetail = !!(opts && opts.keepDetail);
    alertEl.classList.remove("show");
    try {
      const result = await Api.api("/schedules/faculty-view.php");
      const viewerDepartment = result.data.viewerDepartment;
      if (viewerDepartment && viewerDepartment.name) {
        viewerDepartmentName = String(viewerDepartment.name);
      } else if (viewerDepartmentId && result.data.schedules && result.data.schedules.length) {
        viewerDepartmentName = String(result.data.schedules[0].departmentName || "");
      }
      syncDepartmentChip();
      allRows = filterRowsForViewer(result.data.schedules || []);
      const term = result.data.term;
      const termChip = document.getElementById("term-chip");
      if (termChip && term) {
        termChip.textContent = term.label || "Sem " + term.semester;
      }
      document.getElementById("result-count").textContent = String(allRows.length);
      if (keepDetail && currentDetailKind && currentDetailId) {
        renderCards();
        await refreshScheduleNavBadges();
        return;
      }
      showCards();
      renderCards();
      await refreshScheduleNavBadges();
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
      if (input) {
        input.placeholder = "e.g. 3 loads  or  3 loads 5pm up  or  3 loads 7 am to 9 am";
        input.focus();
      }
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
        ? ev.target.closest(".sg-block-label.is-action, .sg-block-remove.is-action")
        : null;
      if (!btn || !gridEl.contains(btn)) return;
      removeLoadFromGrid({
        scheduleId: btn.getAttribute("data-schedule-id") || "",
        label: (btn.classList.contains("sg-block-remove")
          ? btn.parentElement &&
            btn.parentElement.querySelector(".sg-block-label")
            ? btn.parentElement.querySelector(".sg-block-label").textContent || ""
            : ""
          : btn.textContent || ""
        ).trim(),
      });
    });
  }

  bodyEl.addEventListener("click", function (ev) {
    const btn =
      ev.target && ev.target.closest
        ? ev.target.closest(".load-remove-btn")
        : null;
    if (!btn || !bodyEl.contains(btn)) return;
    ev.preventDefault();
    removeLoadByScheduleId(
      btn.getAttribute("data-schedule-id") || "",
      btn.getAttribute("title") || "this load",
      { skipConfirm: false }
    );
  });

  // ── Load assignment UI ───────────────────────────────────────────────────
  const loadAlert = document.getElementById("load-command-alert");
  const loadSuccess = document.getElementById("load-command-success");
  const loadParseCard = document.getElementById("load-parse-card");
  const loadAssignmentPreview = document.getElementById("load-assignment-preview");

  // Subject filter inside the offerings table (typed after table is shown)
  let offeringsSubjectFilter = "";
  let pendingLoadPreviewData = null; // full preview kept so we can re-filter

  function hideLoadMessages() {
    loadAlert.classList.remove("show");
    loadSuccess.classList.remove("show");
  }

  function showLoadError(message) {
    loadSuccess.classList.remove("show");
    loadAlert.className = "alert alert-error";
    loadAlert.textContent = message;
    loadAlert.classList.add("show");
    if (loadAlert.scrollIntoView) {
      loadAlert.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }

  function showLoadSuccess(message) {
    loadAlert.classList.remove("show");
    loadSuccess.textContent = message;
    loadSuccess.classList.add("show");
    if (loadSuccess.scrollIntoView) {
      loadSuccess.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }

  function resetLoadParseUi() {
    pendingLoadCommand = "";
    pendingLoadPreview = null;
    pendingLoadPreviewData = null;
    pendingLoadParsed = null;
    pendingLoadFacultyId = "";
    pendingLoadSubjectCode = "";
    pendingSelectedOfferingKeys = [];
    offeringsSubjectFilter = "";
    loadParseCard.hidden = true;
    document.getElementById("load-parse-details").innerHTML = "";
    document.getElementById("load-assignment-preview").innerHTML = "";
    document.getElementById("load-confirm-summary").textContent = "";
  }

  function selectedOfferingKeysFromPreview() {
    const checked = [];
    document
      .querySelectorAll("#load-offerings-table input.load-offering-pick:checked")
      .forEach(function (el) {
        const key = el.getAttribute("data-offering-key");
        if (key) checked.push(key);
      });
    return checked;
  }

  function uniqueOfferingsByKey(offerings, keys) {
    const seen = {};
    const out = [];
    (offerings || []).forEach(function (o) {
      const k = String(o.offeringKey || "");
      if (!k || keys.indexOf(k) === -1 || seen[k]) return;
      seen[k] = true;
      out.push(o);
    });
    return out;
  }

  function updateLoadSelectionTotals(preview) {
    const totalsEl = document.getElementById("load-selection-totals");
    if (!totalsEl || !preview) return;
    const keys = preview.requireUserPick
      ? selectedOfferingKeysFromPreview()
      : pendingSelectedOfferingKeys.slice();
    if (preview.requireUserPick) {
      pendingSelectedOfferingKeys = keys;
    }
    const offerings = preview.allOfferings || [];
    const picked = uniqueOfferingsByKey(offerings, keys);
    let contactHours = 0;
    let load = 0;
    picked.forEach(function (o) {
      contactHours += Number(o.contactHours) || 0;
      load += Number(o.load) || 0;
    });
    load = Math.round(load * 10000) / 10000;
    contactHours = Math.round(contactHours * 100) / 100;
    const current = preview.faculty && preview.faculty.currentLoad != null
      ? Number(preview.faculty.currentLoad)
      : 0;
    const afterAssign = Math.round((current + load) * 100) / 100;
    const windowMin =
      preview.loadWindowMin != null
        ? Number(preview.loadWindowMin)
        : preview.targetTotal != null
          ? Number(preview.targetTotal)
          : null;
    const windowMax =
      preview.loadWindowMax != null
        ? Number(preview.loadWindowMax)
        : preview.maxTotalLoad != null
          ? Number(preview.maxTotalLoad)
          : null;
    const overCap =
      preview.loadMode === "total" &&
      windowMax != null &&
      afterAssign >= Math.floor(windowMax) + 1 - 1e-9;
    const inWindow =
      preview.loadMode === "total" &&
      windowMin != null &&
      windowMax != null &&
      afterAssign + 1e-9 >= windowMin &&
      afterAssign <= windowMax + 1e-9;
    const label = preview.requireUserPick ? "Selected:" : "System choice:";
    totalsEl.innerHTML = keys.length === 0
      ? '<span class="cell-muted">' +
        (preview.requireUserPick
          ? "No offerings selected yet."
          : "No suitable offering was found.") +
        "</span>"
      : (overCap
          ? '<div class="load-cap-warning">Total load would be <strong>' +
            escapeHtml(String(afterAssign)) +
            "</strong>. For this command stay at or below <strong>" +
            escapeHtml(String(windowMax != null ? windowMax : "\u2014")) +
            "</strong> total load.</div>"
          : "") +
        "<strong>" + label + "</strong> " +
        escapeHtml(String(picked.length)) + " offering(s) &middot; " +
        escapeHtml(String(contactHours)) + " h &divide; 3 = <strong>" +
        escapeHtml(String(load)) + "</strong> load" +
        (windowMin != null && preview.loadMode === "total"
          ? " &middot; target window " +
            escapeHtml(String(windowMin)) +
            "&ndash;" +
            escapeHtml(String(windowMax)) +
            " total"
          : " &middot; target total " +
            escapeHtml(String((preview.targetTotal != null ? preview.targetTotal : preview.targetLoad) || "\u2014"))) +
        " &middot; after assign <strong class=\"" +
        (inWindow ? "load-total-ok" : "") +
        "\">" +
        escapeHtml(String(afterAssign)) + "</strong>";
  }

  function fillLoadFacultySelect() {
    const select = document.getElementById("load-faculty-id");
    if (!select) return;
    const keep = select.value;
    select.innerHTML = '<option value="">Select faculty\u2026</option>';
    facultyCatalog
      .slice()
      .sort(function (a, b) {
        return compareCardLabels(a.fullName, b.fullName);
      })
      .forEach(function (f) {
        const opt = document.createElement("option");
        opt.value = f.uid;
        const emp = f.employmentType === "PartTime" ? "Part-time" : "Regular";
        const min = f.minLoad != null ? f.minLoad : (f.employmentType === "PartTime" ? 3 : 8);
        opt.textContent = f.fullName + " \u00b7 " + emp + " (min " + min + ")";
        select.appendChild(opt);
      });
    if (keep) select.value = keep;
  }

  // Render the offerings table, optionally filtered by subject search term
  function renderOfferingsTableFiltered(preview, filterText) {
    const tableEl = document.getElementById("load-offerings-table");
    if (!tableEl) return;
    const offerings = (preview && preview.allOfferings) || [];
    const subj = (preview && preview.subject) || {};
    const q = (filterText || "").trim().toLowerCase();

    const filtered = q
      ? offerings.filter(function (o) {
          return (
            String(o.subjectCode || "").toLowerCase().includes(q) ||
            String(o.subjectTitle || "").toLowerCase().includes(q) ||
            String(o.blockLabel || "").toLowerCase().includes(q)
          );
        })
      : offerings;

    // Show or hide the subject column (when command is "8 loads" with no specific code, multiple subjects show)
    const showSubjectCol = offerings.some(function (o) {
      return (
        String(o.subjectCode || "") !== "" &&
        String(o.subjectCode) !== String(subj.code || "")
      );
    });

    // Rebuild thead to keep subject col in sync
    const thead = tableEl.querySelector("thead tr");
    if (thead) {
      thead.innerHTML =
        "<th>Pick</th>" +
        (showSubjectCol ? "<th>Subject</th>" : "") +
        "<th>Block</th><th>Schedule</th><th>Lec</th><th>Lab</th><th>Contact h</th><th>Load (h&divide;3)</th>";
    }

    const tbody = tableEl.querySelector("tbody");
    if (!tbody) return;

    if (!filtered.length) {
      const cols = 6 + (showSubjectCol ? 1 : 0) + 1;
      tbody.innerHTML =
        '<tr><td colspan="' + cols + '" class="cell-muted" style="text-align:center;padding:1.25rem">' +
        (q ? "No schedules match \u201c" + escapeHtml(q) + "\u201d." : "No conflict-free schedules available.") +
        "</td></tr>";
      return;
    }

    tbody.innerHTML = filtered
      .map(function (o) {
        const lec = o.lectureHours != null ? o.lectureHours : (subj.lectureHours != null ? subj.lectureHours : 0);
        const lab = o.labHours != null ? o.labHours : (subj.labHours != null ? subj.labHours : 0);
        // Keep checked state if user already ticked it
        const alreadyChecked = pendingSelectedOfferingKeys.indexOf(String(o.offeringKey)) !== -1;
        return (
          "<tr>" +
          '<td><input type="checkbox" class="load-offering-pick" data-offering-key="' +
          escapeHtml(String(o.offeringKey || "")) +
          '"' + (alreadyChecked ? " checked" : "") + " /></td>" +
          (showSubjectCol ? "<td>" + escapeHtml(o.subjectCode || "\u2014") + "</td>" : "") +
          "<td>" + escapeHtml(o.blockLabel || "\u2014") + "</td>" +
          '<td class="cell-muted">' + escapeHtml(o.scheduleText || "\u2014") + "</td>" +
          "<td>" + escapeHtml(String(lec)) + "</td>" +
          "<td>" + escapeHtml(String(lab)) + "</td>" +
          "<td>" + escapeHtml(String(o.contactHours != null ? o.contactHours : "\u2014")) + "</td>" +
          "<td>" +
          escapeHtml(String(o.load != null ? o.load : "\u2014")) +
          (o.loadFormula ? '<div class="cell-muted" style="font-size:0.82em">' + escapeHtml(o.loadFormula) + "</div>" : "") +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  function renderLoadPreview(parsed, preview) {
    document.getElementById("load-confirm-summary").textContent =
      (preview && preview.confirmSummary) || parsed.confirmSummary || "";
    const details = document.getElementById("load-parse-details");
    const subj = (preview && preview.subject) || {};
    const fac = (preview && preview.faculty) || {};
    const loadTotal = (preview && preview.loadTotal) || {};
    details.innerHTML =
      "<div><dt>Faculty</dt><dd>" +
      escapeHtml(fac.fullName || "\u2014") +
      (fac.employmentType
        ? " &middot; " + escapeHtml(fac.employmentType === "PartTime" ? "Part-time" : "Regular") +
          " (min " + escapeHtml(String(fac.minLoad != null ? fac.minLoad : "\u2014")) + ")"
        : "") +
      (fac.currentLoad != null
        ? '<div class="cell-muted">Current load: ' + escapeHtml(String(fac.currentLoad)) +
          (fac.currentContactHours != null
            ? " (" + escapeHtml(String(fac.currentContactHours)) + " h &divide; 3)"
            : "") + "</div>"
        : "") +
      "</dd></div>" +
      "<div><dt>Subject</dt><dd>" +
      escapeHtml(subj.label || subj.code || parsed.subjectCode || "any TBF subject") +
      '<div class="cell-muted">' + escapeHtml(subj.title || "") + "</div></dd></div>" +
      "<div><dt>Catalog hours</dt><dd>Lec " +
      escapeHtml(String(subj.lectureHours != null ? subj.lectureHours : 0)) +
      " &middot; Lab " +
      escapeHtml(String(subj.labHours != null ? subj.labHours : 0)) +
      "</dd></div>" +
      "<div><dt>Target total load</dt><dd>" +
      escapeHtml(String((preview && preview.targetTotal != null ? preview.targetTotal : parsed.targetLoad) || "\u2014")) +
      (preview && preview.loadGap != null && preview.faculty && preview.faculty.currentLoad > 0
        ? '<div class="cell-muted">Current ' +
          escapeHtml(String(preview.faculty.currentLoad)) +
          " &middot; assign " +
          escapeHtml(String(preview.loadGap)) +
          " more</div>"
        : "") +
      "</dd></div>" +
      (parsed.timeFilterLabel
        ? "<div><dt>Time window</dt><dd>" +
          escapeHtml(parsed.timeFilterLabel) +
          "</dd></div>"
        : "") +
      "<div><dt>Load formula</dt><dd>" +
      escapeHtml(subj.loadFormula || "(lecture + lab) &divide; 3 per offering") +
      "</dd></div>" +
      "<div><dt>Available (conflict-free)</dt><dd>" +
      escapeHtml(String((preview && preview.allOfferings && preview.allOfferings.length) || 0)) +
      " schedule(s)</dd></div>" +
      (preview && preview.shortageNotice
        ? "<div><dt>Notice</dt><dd>" + escapeHtml(preview.shortageNotice) + "</dd></div>"
        : "");

    const confirmBtn = document.getElementById("load-confirm-assign");
    const previewEl = document.getElementById("load-assignment-preview");
    const offerings = (preview && preview.allOfferings) || [];
    const suggestedKeys = (preview && preview.suggestedOfferingKeys) || [];
    const requirePick = !!(preview && preview.requireUserPick);

    if (!preview || !offerings.length) {
      previewEl.innerHTML = "";
      return;
    }

    pendingLoadPreviewData = preview;
    offeringsSubjectFilter = "";

    if (!requirePick) {
      if (!pendingSelectedOfferingKeys.length) {
        pendingSelectedOfferingKeys = suggestedKeys.slice();
      }
      const chosen = uniqueOfferingsByKey(
        offerings,
        pendingSelectedOfferingKeys
      );
      if (!pendingSelectedOfferingKeys.length) {
        pendingSelectedOfferingKeys = chosen.map(function (o) {
          return String(o.offeringKey);
        });
      }
      if (confirmBtn) confirmBtn.textContent = "Confirm & assign";
      previewEl.innerHTML =
        '<p class="panel-sub" style="margin:0 0 0.6rem"><strong>System-chosen schedule</strong> — conflict-free offerings totaling ' +
        (preview.loadWindowMin != null && preview.loadWindowMax != null
          ? "<strong>" + escapeHtml(String(preview.loadWindowMin)) + "&ndash;" + escapeHtml(String(preview.loadWindowMax)) + "</strong> load"
          : "your target") +
        '. Confirm to assign.</p>' +
        '<div class="table-wrap"><table class="data-table">' +
        "<thead><tr><th>Subject</th><th>Block</th><th>Schedule</th><th>Contact h</th><th>Load (h&divide;3)</th><th></th></tr></thead><tbody>" +
        chosen
          .map(function (o) {
            return (
              "<tr>" +
              "<td><strong>" +
              escapeHtml(o.subjectCode || subj.code || "—") +
              "</strong></td>" +
              "<td>" +
              escapeHtml(o.blockLabel || "—") +
              "</td>" +
              '<td class="cell-muted">' +
              escapeHtml(o.scheduleText || "—") +
              "</td>" +
              "<td>" +
              escapeHtml(String(o.contactHours != null ? o.contactHours : "—")) +
              "</td>" +
              "<td>" +
              escapeHtml(String(o.load != null ? o.load : "—")) +
              "</td>" +
              '<td class="cell-action">' +
              '<button type="button" class="btn-icon-remove load-offering-remove" data-offering-key="' +
              escapeHtml(String(o.offeringKey || "")) +
              '" title="Remove from plan" aria-label="Remove from plan">&times;</button>' +
              "</td>" +
              "</tr>"
            );
          })
          .join("") +
        "</tbody></table></div>" +
        '<div id="load-selection-totals" class="panel-sub" style="margin-top:0.75rem"></div>';
      updateLoadSelectionTotals(preview);
      return;
    }

    pendingSelectedOfferingKeys = [];
    if (confirmBtn) confirmBtn.textContent = "Confirm & assign selected";

    previewEl.innerHTML =
      '<div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.6rem">' +
      '<p class="panel-sub" style="margin:0;flex:1"><strong>Add another load</strong> — this faculty already has a schedule. Check the extra offering(s) to assign.</p>' +
      '<label style="display:flex;align-items:center;gap:0.4rem;font-size:0.88rem;margin:0">' +
      "Filter subject&nbsp;" +
      '<input id="offerings-subject-filter" type="search" autocomplete="off" ' +
      'placeholder="e.g. IT 322" style="width:160px;margin:0" />' +
      "</label></div>" +
      '<div class="table-wrap"><table class="data-table" id="load-offerings-table">' +
      "<thead><tr></tr></thead><tbody></tbody></table></div>" +
      '<div id="load-selection-totals" class="panel-sub" style="margin-top:0.75rem"></div>';

    renderOfferingsTableFiltered(preview, "");
    updateLoadSelectionTotals(preview);

    const filterInput = document.getElementById("offerings-subject-filter");
    if (filterInput) {
      filterInput.addEventListener("input", function () {
        offeringsSubjectFilter = filterInput.value;
        pendingSelectedOfferingKeys = selectedOfferingKeysFromPreview();
        renderOfferingsTableFiltered(pendingLoadPreviewData, offeringsSubjectFilter);
        updateLoadSelectionTotals(pendingLoadPreviewData);
      });
    }
  }

  // Live totals as user checks/unchecks
  if (loadAssignmentPreview) {
    loadAssignmentPreview.addEventListener("click", function (ev) {
      const removeBtn =
        ev.target && ev.target.closest
          ? ev.target.closest(".load-offering-remove")
          : null;
      if (removeBtn && pendingLoadPreviewData && pendingLoadParsed) {
        const key = removeBtn.getAttribute("data-offering-key") || "";
        if (!key) return;
        pendingSelectedOfferingKeys = pendingSelectedOfferingKeys.filter(function (k) {
          return String(k) !== String(key);
        });
        if (!pendingSelectedOfferingKeys.length) {
          showLoadError("No offerings left in the plan. Plan again or pick different load.");
          resetLoadParseUi();
          return;
        }
        renderLoadPreview(pendingLoadParsed, pendingLoadPreviewData);
        updateLoadSelectionTotals(pendingLoadPreviewData);
        return;
      }
    });
    loadAssignmentPreview.addEventListener("change", function (ev) {
      if (
        ev.target &&
        ev.target.classList &&
        ev.target.classList.contains("load-offering-pick") &&
        pendingLoadPreviewData
      ) {
        pendingSelectedOfferingKeys = selectedOfferingKeysFromPreview();
        updateLoadSelectionTotals(pendingLoadPreviewData);
      }
    });
  }

  document.getElementById("load-command-form").addEventListener("submit", async function (e) {
    e.preventDefault();
    hideLoadMessages();
    const facultyId = document.getElementById("load-faculty-id").value.trim();
    pendingLoadCommand = document.getElementById("load-command-text").value.trim();
    const planBtn = e.submitter || document.querySelector("#load-command-form button[type=submit]");
    if (!facultyId) {
      resetLoadParseUi();
      showLoadError("Select a faculty first.");
      return;
    }
    if (!pendingLoadCommand) {
      resetLoadParseUi();
      showLoadError('Enter a command such as "8 loads" or "8 load IT 322".');
      return;
    }
    const prevBtnLabel = planBtn ? planBtn.textContent : "";
    if (planBtn) {
      planBtn.disabled = true;
      planBtn.textContent = "Planning…";
    }
    try {
      const res = await Api.api("/schedules/parse-load-command.php", {
        method: "POST",
        body: JSON.stringify({
          command: pendingLoadCommand,
          facultyId: facultyId,
        }),
      });
      const parsed = res.data && res.data.parsed;
      if (!parsed || !parsed.ready) {
        resetLoadParseUi();
        showLoadError(
          'Could not parse. Use \u201c8 loads\u201d, \u201c8 load IT 322\u201d, \u201c3 loads 5pm up\u201d, or \u201c3 loads 7 am to 9 am\u201d' +
          (parsed && parsed.missing && parsed.missing.length
            ? " (missing: " + parsed.missing.join(", ") + ")"
            : "")
        );
        return;
      }
      if (res.data.previewError) {
        resetLoadParseUi();
        showLoadError(res.data.previewError);
        return;
      }
      pendingLoadFacultyId = facultyId;
      pendingLoadPreview = res.data.preview;
      pendingLoadParsed = parsed;
      pendingSelectedOfferingKeys = [];
      pendingLoadSubjectCode = parsed.subjectSpecified
        ? parsed.subjectCode || ""
        : "";
      renderLoadPreview(parsed, pendingLoadPreview);
      loadParseCard.hidden = false;
      if (loadParseCard.scrollIntoView) {
        loadParseCard.scrollIntoView({ behavior: "smooth", block: "start" });
      }
      if (pendingLoadPreview && pendingLoadPreview.shortageNotice) {
        showLoadError(pendingLoadPreview.shortageNotice);
      }
    } catch (err) {
      resetLoadParseUi();
      showLoadError(err.message || "Parse failed.");
    } finally {
      if (planBtn) {
        planBtn.disabled = false;
        planBtn.textContent = prevBtnLabel || "Plan suitable schedule";
      }
    }
  });

  document.getElementById("load-cancel-parse").addEventListener("click", function () {
    resetLoadParseUi();
    hideLoadMessages();
  });

  document.getElementById("load-confirm-assign").addEventListener("click", async function () {
    if (!pendingLoadCommand || !pendingLoadFacultyId) return;
    hideLoadMessages();
    const preview = pendingLoadPreviewData || pendingLoadPreview;
    const requirePick = !!(preview && preview.requireUserPick);
    const offeringKeys = pendingSelectedOfferingKeys.length
      ? pendingSelectedOfferingKeys
      : selectedOfferingKeysFromPreview();
    if (requirePick && !offeringKeys.length) {
      showLoadError("Select the extra offering(s) to add for this faculty.");
      return;
    }
    if (!requirePick && !offeringKeys.length) {
      showLoadError("No suitable schedule was chosen. Try another subject or faculty.");
      return;
    }
    const previewForCap = pendingLoadPreviewData || pendingLoadPreview;
    if (previewForCap && previewForCap.loadMode === "total") {
      let addLoad = 0;
      (previewForCap.allOfferings || []).forEach(function (o) {
        if (offeringKeys.indexOf(String(o.offeringKey)) === -1) return;
        addLoad += Number(o.load) || 0;
      });
      const current =
        previewForCap.faculty && previewForCap.faculty.currentLoad != null
          ? Number(previewForCap.faculty.currentLoad)
          : 0;
      const after = Math.round((current + addLoad) * 100) / 100;
      const ceiling =
        previewForCap.targetTotal != null
          ? Number(previewForCap.targetTotal) + 1
          : null;
      if (ceiling != null && after >= ceiling - 1e-9) {
        loadSuccess.classList.remove("show");
        loadAlert.className = "alert load-cap-warning show";
        loadAlert.innerHTML =
          "Total load would be <strong>" + escapeHtml(String(after)) +
          "</strong>. For this command stay below <strong>" + escapeHtml(String(ceiling)) +
          "</strong> (8.33 or 8.67 is fine). Use a smaller add-on command to go higher.";
        return;
      }
    }
    try {
      const res = await Api.api("/schedules/assign-load-command.php", {
        method: "POST",
        body: JSON.stringify({
          command: pendingLoadCommand,
          facultyId: pendingLoadFacultyId,
          subjectCode: pendingLoadSubjectCode,
          offeringKeys: offeringKeys,
        }),
      });
      const result = res.data && res.data.result;
      resetLoadParseUi();
      const assignedMsg = result
        ? "Assigned " + String(result.assignedLoad) +
          " load (" + String(result.assignedContactHours != null ? result.assignedContactHours : "\u2014") +
          " h \u00f7 3) of " + (result.subject.label || result.subject.code) +
          " to " + result.faculty.fullName +
          " \u2014 " + (result.assignments || []).length + " offering(s), " +
          result.updatedMeetings + " meeting(s) updated."
        : "Load assigned.";
      if (result && result.shortageNotice) {
        loadAlert.textContent = result.shortageNotice;
        loadAlert.classList.add("show");
        loadSuccess.textContent = assignedMsg;
        loadSuccess.classList.add("show");
      } else {
        showLoadSuccess(assignedMsg);
      }
      await load();
      await refreshScheduleNavBadges();
    } catch (err) {
      showLoadError(err.message || "Assign failed.");
    }
  });

  await loadCatalogs();
  fillLoadFacultySelect();
  await load();
})();
