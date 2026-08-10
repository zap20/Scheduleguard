/**
 * Reusable weekly schedule grid.
 *
 * Usage:
 *   const html = ScheduleGrid.renderScheduleGrid(classBlocks, {
 *     startHour: 7,          // inclusive, default 7 (7:00 AM)
 *     endHour: 20.5,         // exclusive end as hour+fraction, default 20.5 (8:30 PM)
 *     // or: startTime: "07:00", endTime: "20:30"
 *   });
 *   container.innerHTML = html;
 *
 * Each class block:
 *   {
 *     day: "Mon" | "Monday" | "Tue" | ...,  // case-insensitive aliases accepted
 *     startTime: "08:00" | "8:00 AM" | "08:00:00",
 *     endTime: "09:30",
 *     label: "CS101 · Lab 101 · Fiona Faculty"  // free-form display text
 *   }
 */
(function (window) {
  "use strict";

  const DAY_ORDER = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  const DAY_ALIASES = {
    mon: "Mon",
    monday: "Mon",
    tue: "Tue",
    tues: "Tue",
    tuesday: "Tue",
    wed: "Wed",
    wednesday: "Wed",
    thu: "Thu",
    thur: "Thu",
    thurs: "Thu",
    thursday: "Thu",
    fri: "Fri",
    friday: "Fri",
    sat: "Sat",
    saturday: "Sat",
    sun: "Sun",
    sunday: "Sun",
  };

  function pad2(n) {
    return String(n).padStart(2, "0");
  }

  function minutesToLabel(totalMinutes) {
    const h24 = Math.floor(totalMinutes / 60);
    const m = totalMinutes % 60;
    const suffix = h24 >= 12 ? "PM" : "AM";
    const h12 = h24 % 12 === 0 ? 12 : h24 % 12;
    return h12 + ":" + pad2(m) + " " + suffix;
  }

  function formatSlotRange(startMin, endMin) {
    return minutesToLabel(startMin) + " - " + minutesToLabel(endMin);
  }

  /**
   * Parse "HH:MM", "HH:MM:SS", or "H:MM AM/PM" into minutes from midnight.
   */
  function parseTimeToMinutes(value) {
    if (typeof value === "number" && Number.isFinite(value)) {
      return Math.round(value);
    }

    const raw = String(value || "").trim();
    if (!raw) return null;

    const ampm = raw.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])$/);
    if (ampm) {
      let h = parseInt(ampm[1], 10);
      const m = parseInt(ampm[2], 10);
      const ap = ampm[4].toLowerCase();
      if (h === 12) h = 0;
      if (ap === "pm") h += 12;
      return h * 60 + m;
    }

    const mil = raw.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
    if (mil) {
      const h = parseInt(mil[1], 10);
      const m = parseInt(mil[2], 10);
      if (h > 23 || m > 59) return null;
      return h * 60 + m;
    }

    return null;
  }

  function hourOptionToMinutes(hourValue, fallback) {
    if (hourValue === undefined || hourValue === null || hourValue === "") {
      return fallback;
    }
    if (typeof hourValue === "string" && hourValue.indexOf(":") !== -1) {
      const parsed = parseTimeToMinutes(hourValue);
      return parsed === null ? fallback : parsed;
    }
    const n = Number(hourValue);
    if (!Number.isFinite(n)) return fallback;
    // Allow 20.5 => 20:30
    return Math.round(n * 60);
  }

  function normalizeDay(day) {
    if (day === undefined || day === null) return null;
    const key = String(day).trim().toLowerCase();
    return DAY_ALIASES[key] || null;
  }

  /** Round start down / end up to 30-minute boundaries. */
  function roundOutwardToSlot(startMin, endMin) {
    const start = Math.floor(startMin / 30) * 30;
    let end = Math.ceil(endMin / 30) * 30;
    if (end <= start) end = start + 30;
    return { start, end };
  }

  function buildSlots(rangeStart, rangeEnd) {
    const slots = [];
    for (let t = rangeStart; t < rangeEnd; t += 30) {
      slots.push({ start: t, end: t + 30, label: formatSlotRange(t, t + 30) });
    }
    return slots;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /**
   * Normalize and clamp blocks into the visible grid window.
   */
  function prepareBlocks(classBlocks, rangeStart, rangeEnd) {
    const prepared = [];

    (classBlocks || []).forEach(function (raw, index) {
      const day = normalizeDay(raw.day);
      const startMin = parseTimeToMinutes(raw.startTime);
      const endMin = parseTimeToMinutes(raw.endTime);
      if (!day || startMin === null || endMin === null || endMin <= startMin) {
        return;
      }

      const rounded = roundOutwardToSlot(startMin, endMin);
      let start = Math.max(rounded.start, rangeStart);
      let end = Math.min(rounded.end, rangeEnd);
      if (end <= start) {
        return; // entirely outside operating hours
      }

      prepared.push({
        id: raw.id || "block-" + index,
        day: day,
        start: start,
        end: end,
        label: raw.label == null ? "" : String(raw.label),
        meta: raw,
      });
    });

    prepared.sort(function (a, b) {
      if (a.day !== b.day) {
        return DAY_ORDER.indexOf(a.day) - DAY_ORDER.indexOf(b.day);
      }
      if (a.start !== b.start) return a.start - b.start;
      return a.end - b.end;
    });

    return prepared;
  }

  /**
   * Place blocks into per-day slot maps with rowspan + overlap stacking.
   *
   * dayMaps[day][slotIndex] =
   *   { kind: "empty" }
   *   { kind: "start", labels: [...], rowspan, overlap: boolean }
   *   { kind: "covered" }
   */
  function placeBlocks(blocks, slots) {
    const slotIndexByStart = {};
    slots.forEach(function (slot, idx) {
      slotIndexByStart[slot.start] = idx;
    });

    const dayMaps = {};
    DAY_ORDER.forEach(function (day) {
      dayMaps[day] = slots.map(function () {
        return { kind: "empty" };
      });
    });

    blocks.forEach(function (block) {
      const startIdx = slotIndexByStart[block.start];
      if (startIdx === undefined) return;
      const rowspan = Math.max(1, (block.end - block.start) / 30);
      const endIdx = startIdx + rowspan;

      const map = dayMaps[block.day];
      let conflict = false;
      let hostIdx = startIdx;

      for (let i = startIdx; i < endIdx; i++) {
        const cell = map[i];
        if (cell.kind === "start") {
          conflict = true;
          hostIdx = i;
          break;
        }
        if (cell.kind === "covered") {
          conflict = true;
          // Walk back to the owning start cell.
          let j = i;
          while (j > 0 && map[j].kind === "covered") j--;
          if (map[j].kind === "start") hostIdx = j;
          break;
        }
      }

      if (!conflict) {
        map[startIdx] = {
          kind: "start",
          labels: [block.label],
          rowspan: rowspan,
          overlap: false,
        };
        for (let i = startIdx + 1; i < endIdx; i++) {
          map[i] = { kind: "covered" };
        }
        return;
      }

      // Overlap: stack onto the existing start cell and extend rowspan if needed.
      const host = map[hostIdx];
      if (host.kind !== "start") {
        // Fallback: force a start at this slot.
        map[startIdx] = {
          kind: "start",
          labels: [block.label],
          rowspan: rowspan,
          overlap: true,
        };
        for (let i = startIdx + 1; i < endIdx; i++) {
          if (map[i].kind === "empty") map[i] = { kind: "covered" };
        }
        return;
      }

      host.labels.push(block.label);
      host.overlap = true;
      const hostEnd = hostIdx + host.rowspan;
      const neededEnd = Math.max(hostEnd, endIdx);
      if (neededEnd > hostEnd) {
        for (let i = hostEnd; i < neededEnd; i++) {
          if (map[i].kind === "start") {
            // Absorb another block's labels if we extend over it.
            host.labels = host.labels.concat(map[i].labels || []);
            host.overlap = true;
          }
          map[i] = { kind: "covered" };
        }
        host.rowspan = neededEnd - hostIdx;
      }
    });

    return dayMaps;
  }

  function computeFitRange(classBlocks, padMinutes) {
    let minStart = null;
    let maxEnd = null;
    (classBlocks || []).forEach(function (raw) {
      const startMin = parseTimeToMinutes(raw.startTime);
      const endMin = parseTimeToMinutes(raw.endTime);
      if (startMin === null || endMin === null || endMin <= startMin) return;
      if (minStart === null || startMin < minStart) minStart = startMin;
      if (maxEnd === null || endMin > maxEnd) maxEnd = endMin;
    });
    if (minStart === null || maxEnd === null) {
      return { start: 7 * 60, end: 20 * 60 + 30 };
    }
    const pad = padMinutes == null ? 30 : padMinutes;
    const start = Math.max(0, Math.floor((minStart - pad) / 30) * 30);
    let end = Math.min(24 * 60, Math.ceil((maxEnd + pad) / 30) * 30);
    if (end <= start + 30) end = start + 60;
    return { start: start, end: end };
  }

  function resolveDayColumns(classBlocks, options) {
    if (Array.isArray(options.days) && options.days.length) {
      return options.days
        .map(normalizeDay)
        .filter(Boolean)
        .filter(function (day, idx, arr) {
          return arr.indexOf(day) === idx;
        });
    }
    if (options.activeDaysOnly) {
      const used = {};
      (classBlocks || []).forEach(function (raw) {
        const day = normalizeDay(raw.day);
        if (day) used[day] = true;
      });
      const active = DAY_ORDER.filter(function (day) {
        return used[day];
      });
      return active.length ? active : DAY_ORDER.slice(0, 5);
    }
    return DAY_ORDER.slice();
  }

  /**
   * @param {Array<{day:string,startTime:string,endTime:string,label:string}>} classBlocks
   * @param {{startHour?:number|string,endHour?:number|string,startTime?:string,endTime?:string,showMeta?:boolean,compact?:boolean,fitToBlocks?:boolean,activeDaysOnly?:boolean,days?:string[]}} [options]
   * @returns {string} HTML markup for the weekly timetable
   */
  function renderScheduleGrid(classBlocks, options) {
    options = options || {};

    const hasExplicitRange =
      options.startTime !== undefined ||
      options.endTime !== undefined ||
      options.startHour !== undefined ||
      options.endHour !== undefined;

    let rangeStart;
    let rangeEnd;
    if (hasExplicitRange) {
      rangeStart = hourOptionToMinutes(
        options.startTime !== undefined ? options.startTime : options.startHour,
        7 * 60
      );
      rangeEnd = hourOptionToMinutes(
        options.endTime !== undefined ? options.endTime : options.endHour,
        20 * 60 + 30
      );
    } else if (options.fitToBlocks === true) {
      const fitted = computeFitRange(classBlocks, options.fitPaddingMinutes);
      rangeStart = fitted.start;
      rangeEnd = fitted.end;
    } else {
      rangeStart = 7 * 60;
      rangeEnd = 20 * 60 + 30;
    }

    if (rangeEnd <= rangeStart + 30) {
      throw new Error("Schedule grid end time must be at least 30 minutes after start.");
    }

    const dayColumns = resolveDayColumns(classBlocks, options);
    const slots = buildSlots(rangeStart, rangeEnd);
    const blocks = prepareBlocks(classBlocks, rangeStart, rangeEnd);
    const dayMaps = placeBlocks(blocks, slots);

    const wrapClass =
      "schedule-grid-wrap" + (options.compact ? " is-compact" : "");
    const tableClass =
      "schedule-grid" + (options.compact ? " is-compact" : "");

    let html = "";
    if (options.showMeta !== false) {
      html +=
        '<p class="schedule-grid-meta">Weekly grid · ' +
        escapeHtml(minutesToLabel(rangeStart)) +
        " – " +
        escapeHtml(minutesToLabel(rangeEnd)) +
        " · " +
        blocks.length +
        " block(s)</p>";
    }

    html += '<div class="' + wrapClass + '"><table class="' + tableClass + '">';
    html += "<thead><tr><th class=\"sg-time\">Time</th>";
    dayColumns.forEach(function (day) {
      html += "<th>" + day + "</th>";
    });
    html += "</tr></thead><tbody>";

    slots.forEach(function (slot, slotIdx) {
      html += "<tr>";
      html += '<th class="sg-time" scope="row">' + escapeHtml(slot.label) + "</th>";

      dayColumns.forEach(function (day) {
        const cell = dayMaps[day][slotIdx];
        if (cell.kind === "covered") {
          return; // spanned by a rowspan above — do not emit a cell
        }
        if (cell.kind === "start") {
          const cls = "sg-block" + (cell.overlap ? " is-overlap" : "");
          html +=
            '<td class="' +
            cls +
            '" rowspan="' +
            cell.rowspan +
            '">';
          if (cell.overlap) {
            html += '<span class="sg-overlap-flag">Overlap</span>';
          }
          cell.labels.forEach(function (label) {
            html +=
              '<span class="sg-block-label">' + escapeHtml(label) + "</span>";
          });
          html += "</td>";
          return;
        }
        html += '<td class="sg-empty"></td>';
      });

      html += "</tr>";
    });

    html += "</tbody></table></div>";
    return html;
  }

  /**
   * Convenience: render into a DOM element.
   */
  function mountScheduleGrid(container, classBlocks, options) {
    const el =
      typeof container === "string"
        ? document.querySelector(container)
        : container;
    if (!el) {
      throw new Error("Schedule grid container not found.");
    }
    el.innerHTML = renderScheduleGrid(classBlocks, options);
    return el;
  }

  window.ScheduleGrid = {
    renderScheduleGrid: renderScheduleGrid,
    mountScheduleGrid: mountScheduleGrid,
    DAY_ORDER: DAY_ORDER.slice(),
  };

  // Also expose the bare function name for callers that prefer it.
  window.renderScheduleGrid = renderScheduleGrid;
})(window);
