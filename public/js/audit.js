(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName;
  Att.bindLogout(document.getElementById("logout-btn"));

  let page = 1;
  const pageSize = 20;
  let totalPages = 1;
  let filtersLoaded = false;

  const form = document.getElementById("filter-form");
  const alertEl = document.getElementById("audit-alert");
  const bodyEl = document.getElementById("audit-body");
  const emptyEl = document.getElementById("audit-empty");

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function showError(message) {
    alertEl.textContent = message;
    alertEl.classList.add("show");
  }

  function hideError() {
    alertEl.classList.remove("show");
  }

  function queryString() {
    const params = new URLSearchParams();
    if (form.module.value) params.set("module", form.module.value);
    if (form.userId.value) params.set("userId", form.userId.value);
    if (form.dateFrom.value) params.set("dateFrom", form.dateFrom.value);
    if (form.dateTo.value) params.set("dateTo", form.dateTo.value);
    params.set("page", String(page));
    params.set("pageSize", String(pageSize));
    return "?" + params.toString();
  }

  function fillFilters(modules, users) {
    if (filtersLoaded) return;
    filtersLoaded = true;

    const moduleSelect = document.getElementById("module");
    modules.forEach(function (mod) {
      const opt = document.createElement("option");
      opt.value = mod;
      opt.textContent = mod;
      moduleSelect.appendChild(opt);
    });

    const userSelect = document.getElementById("userId");
    users.forEach(function (actor) {
      const opt = document.createElement("option");
      opt.value = actor.uid;
      opt.textContent = actor.label;
      userSelect.appendChild(opt);
    });
  }

  function renderRows(records) {
    if (!records.length) {
      bodyEl.innerHTML = "";
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    bodyEl.innerHTML = records
      .map(function (row) {
        return (
          "<tr>" +
          "<td>" +
          escapeHtml(Att.formatTimestamp(row.timestamp)) +
          "</td>" +
          "<td><div class=\"cell-strong\">" +
          escapeHtml(row.userName) +
          "</div><div class=\"cell-muted\">" +
          escapeHtml(row.userRole) +
          " · " +
          escapeHtml(row.userEmail) +
          "</div></td>" +
          "<td><span class=\"status-badge status-late\">" +
          escapeHtml(row.action) +
          "</span></td>" +
          "<td>" +
          escapeHtml(row.module) +
          "</td>" +
          "<td class=\"reason-cell\">" +
          escapeHtml(row.message) +
          "</td>" +
          "<td><code class=\"id-code\">" +
          escapeHtml(row.relatedRecordId || "—") +
          "</code></td>" +
          "</tr>"
        );
      })
      .join("");
  }

  function updatePagination(pagination) {
    totalPages = pagination.totalPages || 1;
    page = pagination.page || 1;
    document.getElementById("result-count").textContent = String(pagination.total || 0);
    document.getElementById("page-label").textContent =
      "Page " + page + " of " + totalPages;
    document.getElementById("prev-page").disabled = page <= 1;
    document.getElementById("next-page").disabled = page >= totalPages;
  }

  async function load() {
    hideError();
    try {
      const result = await Api.api("/audit/list.php" + queryString());
      fillFilters(result.data.modules || [], result.data.users || []);
      renderRows(result.data.records || []);
      updatePagination(result.data.pagination || {});
    } catch (err) {
      showError(err.message || "Unable to load audit trail.");
    }
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    page = 1;
    load();
  });

  document.getElementById("prev-page").addEventListener("click", function () {
    if (page > 1) {
      page -= 1;
      load();
    }
  });

  document.getElementById("next-page").addEventListener("click", function () {
    if (page < totalPages) {
      page += 1;
      load();
    }
  });

  load();
})();
