/**
 * Shared NL schedule-command panel (parse → generate → save plans as class blocks).
 *
 * Usage:
 *   ScheduleCommandPanel.bind({
 *     api: window.ScheduleGuardApi,
 *     onError: fn,
 *     onSuccess: fn,
 *     onSaved: async fn,   // after successful save
 *   });
 */
(function (window) {
  "use strict";

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function bind(options) {
    const Api = options.api;
    const onError = options.onError || function () {};
    const onSuccess = options.onSuccess || function () {};
    const onSaved = options.onSaved || function () {};

    const form = document.getElementById("command-form");
    const parseCard = document.getElementById("command-parse-card");
    const optionsEl = document.getElementById("command-options");
    const semesterWrap = document.getElementById("command-semester-wrap");
    if (!form || !parseCard || !optionsEl) return null;

    let pendingCommandText = "";
    let pendingParsed = null;
    let pendingPlans = [];
    let pendingSemester = "";

    function resetCommandUi() {
      pendingCommandText = "";
      pendingParsed = null;
      pendingPlans = [];
      pendingSemester = "";
      parseCard.hidden = true;
      optionsEl.hidden = true;
      optionsEl.innerHTML = "";
      if (semesterWrap) semesterWrap.hidden = true;
    }

    function renderParseDetails(parsed) {
      document.getElementById("command-confirm-summary").textContent =
        parsed.confirmSummary || "";
      const needsSemester = !!(
        parsed.needsSemesterConfirm ||
        (parsed.missing || []).indexOf("semester") !== -1
      );
      if (semesterWrap) {
        semesterWrap.hidden = !needsSemester;
        if (needsSemester && parsed.semester) {
          document.getElementById("command-semester").value = parsed.semester;
        } else if (needsSemester) {
          document.getElementById("command-semester").value = "1st Semester";
        }
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
      const nextNames = payload.nextBlockNames || [];
      if (pendingPlans.length === 0) {
        optionsEl.hidden = false;
        optionsEl.innerHTML =
          '<p class="empty-state">No conflict-free plans were generated. Adjust block count, day span, or curriculum subjects.</p>';
        return;
      }
      optionsEl.hidden = false;
      optionsEl.innerHTML =
        '<h3 class="form-title">Ranked plans</h3>' +
        '<p class="panel-sub">Saving a plan creates: <strong>' +
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
                      escapeHtml(a.facultyName || "TBF") +
                      " · " +
                      escapeHtml(a.roomLabel) +
                      "</li>"
                    );
                  })
                  .join("");
                return (
                  '<div style="margin:0.5rem 0 0.75rem">' +
                  '<div class="conflict-title">' +
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
              '<div class="conflict-title">Plan #' +
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
              '<p class="panel-sub">This plan creates <strong>' +
              escapeHtml((plan.blocks || []).length) +
              "</strong> class block(s): " +
              escapeHtml((plan.previewBlockNames || []).join(", ")) +
              "</p>" +
              blockHtml +
              '<button type="button" class="btn btn-small" data-save-plan="' +
              idx +
              '">Save ' +
              escapeHtml(String((plan.blocks || []).length)) +
              " block(s) (" +
              escapeHtml((plan.previewBlockNames || []).join(", ")) +
              ")</button>" +
              "</div>"
            );
          })
          .join("");
    }

    form.addEventListener("submit", async function (event) {
      event.preventDefault();
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
        const missing = pendingParsed.missing || [];
        const onlySemester =
          missing.length === 1 && missing[0] === "semester";
        if (!(pendingParsed.ready || onlySemester) && missing.length) {
          onError(
            "Could not fully parse the command. Missing: " +
              missing.join(", ") +
              ". Adjust the wording and parse again."
          );
        }
      } catch (err) {
        resetCommandUi();
        onError(err.message || "Parse failed.");
      }
    });

    const cancelBtn = document.getElementById("command-cancel-parse");
    if (cancelBtn) {
      cancelBtn.addEventListener("click", function () {
        resetCommandUi();
      });
    }

    const generateBtn = document.getElementById("command-confirm-generate");
    if (generateBtn) {
      generateBtn.addEventListener("click", async function () {
        if (!pendingCommandText) return;
        pendingSemester =
          semesterWrap && !semesterWrap.hidden
            ? document.getElementById("command-semester").value
            : pendingParsed && pendingParsed.semester
              ? pendingParsed.semester
              : "";
        try {
          const payload = { command: pendingCommandText };
          if (pendingSemester) payload.semester = pendingSemester;
          const res = await Api.api("/schedules/generate-from-command.php", {
            method: "POST",
            body: JSON.stringify(payload),
          });
          const data = res && res.data;
          if (!data || !data.parsed) {
            onError(
              (res && res.error) ||
                "Generate returned an unexpected response. Try again."
            );
            return;
          }
          pendingParsed = data.parsed;
          renderParseDetails(pendingParsed);
          renderGeneratedOptions(data);
          onSuccess(
            "Generated " +
              ((data.plans && data.plans.length) || 0) +
              " plan(s). Choose one to save as " +
              ((data.nextBlockNames || []).join(", ") || "the next block(s)") +
              "."
          );
        } catch (err) {
          onError(err.message || "Generate failed.");
        }
      });
    }

    optionsEl.addEventListener("click", async function (event) {
      const btn = event.target.closest("[data-save-plan]");
      if (!btn) return;
      const idx = Number(btn.getAttribute("data-save-plan"));
      const plan = pendingPlans[idx];
      if (!plan) return;
      const blockList = Array.isArray(plan.blocks) ? plan.blocks : [];
      const expected = Number(
        (pendingParsed && pendingParsed.blockCount) || plan.blockCount || blockList.length || 1
      );
      if (blockList.length < 1) {
        onError("This plan has no blocks to save. Generate again.");
        return;
      }
      if (blockList.length !== expected) {
        onError(
          "This plan has " +
            blockList.length +
            " block(s) but the command asked for " +
            expected +
            ". Generate again, then save."
        );
        return;
      }
      try {
        const payload = {
          command: pendingCommandText,
          plan: {
            rank: plan.rank,
            score: plan.score,
            blockCount: expected,
            previewBlockNames: plan.previewBlockNames || [],
            blocks: blockList,
          },
        };
        if (pendingSemester) payload.semester = pendingSemester;
        const res = await Api.api("/schedules/save-option.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        const names = res.data.blockNames || [];
        onSuccess(
          "Saved " +
            names.length +
            " class block(s): " +
            names.join(", ") +
            " — " +
            res.data.confirmedCount +
            " confirmed, " +
            res.data.conflictCount +
            " conflict."
        );
        resetCommandUi();
        document.getElementById("command-text").value = "";
        await onSaved(res.data);
      } catch (err) {
        onError(err.message || "Save failed.");
      }
    });

    return { reset: resetCommandUi };
  }

  window.ScheduleCommandPanel = { bind: bind };
})(window);
