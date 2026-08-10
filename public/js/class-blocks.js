(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName + " (" + user.role + ")";
  Att.bindLogout(document.getElementById("logout-btn"));

  const alertEl = document.getElementById("blocks-alert");
  const successEl = document.getElementById("blocks-success");
  const bodyEl = document.getElementById("blocks-body");
  const emptyEl = document.getElementById("blocks-empty");
  const modal = document.getElementById("create-modal");
  let yearLevels = [];

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

  function render(list) {
    document.getElementById("result-count").textContent = String(list.length);
    if (!list.length) {
      bodyEl.innerHTML = "";
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;
    bodyEl.innerHTML = list
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
          "</tr>"
        );
      })
      .join("");
  }

  async function load() {
    hideMessages();
    const params = new URLSearchParams();
    const yl = document.getElementById("filter-yearLevel").value;
    if (yl) params.set("yearLevel", yl);
    const qs = params.toString() ? "?" + params.toString() : "";
    const res = await Api.api("/class-blocks/list.php" + qs);
    const term = res.data.term;
    if (term) {
      document.getElementById("term-chip").textContent =
        term.label || "Sem " + term.semester;
    }
    fillYearSelects(res.data.yearLevels || []);
    render(res.data.blocks || []);
  }

  document.getElementById("filter-form").addEventListener("submit", function (e) {
    e.preventDefault();
    load().catch(function (err) {
      showError(err.message || "Unable to load class blocks.");
    });
  });

  document.getElementById("create-btn").addEventListener("click", function () {
    hideMessages();
    document.getElementById("create-form").reset();
    if (yearLevels.length) {
      document.getElementById("create-yearLevel").value = yearLevels[0];
    }
    modal.hidden = false;
  });

  document.getElementById("create-cancel").addEventListener("click", function () {
    modal.hidden = true;
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
      showSuccess('Created "' + (res.data.block && res.data.block.name) + '".');
      await load();
    } catch (err) {
      showError(err.message || "Create failed.");
    } finally {
      btn.disabled = false;
    }
  });

  try {
    await load();
  } catch (err) {
    showError(err.message || "Unable to load class blocks.");
  }
})();
