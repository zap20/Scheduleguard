(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["ProgramHead", "Dean", "HR"]);
  if (!user) return;

  const canWrite = user.role === "ProgramHead" || user.role === "Dean";
  const isProgramHead = user.role === "ProgramHead";

  let page = 1;
  const pageSize = 10;
  let totalPages = 1;
  let departments = [];

  const alertEl = document.getElementById("blocking-alert");
  const successEl = document.getElementById("blocking-success");
  const bodyEl = document.getElementById("blocking-body");
  const emptyEl = document.getElementById("blocking-empty");
  const countEl = document.getElementById("result-count");
  const form = document.getElementById("filter-form");
  const deptSelect = document.getElementById("departmentId");
  const issueBtn = document.getElementById("issue-btn");
  const actionsCol = document.getElementById("actions-col");

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName + " (" + user.role + ")";
  Att.bindLogout(document.getElementById("logout-btn"));

  if (user.role === "HR") {
    document.getElementById("page-title").textContent = "Blocking list (read-only)";
    document.getElementById("page-sub").textContent =
      "Campus-wide student blocks for HR review. Issuing or clearing blocks requires Program Head or Dean.";
  } else if (user.role === "ProgramHead") {
    document.getElementById("page-sub").textContent =
      "Blocks for your department. Issue new blocks or update Active / Cleared status.";
  } else {
    document.getElementById("page-sub").textContent =
      "Cross-department blocking oversight. Issue new blocks or update status.";
  }

  if (canWrite) {
    issueBtn.hidden = false;
    actionsCol.hidden = false;
  }

  if (isProgramHead) {
    document.getElementById("department-filter-wrap").hidden = true;
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

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function statusBadge(status) {
    const cls =
      String(status).toLowerCase() === "active" ? "status-wrong" : "status-present";
    return '<span class="status-badge ' + cls + '">' + escapeHtml(status) + "</span>";
  }

  function queryString() {
    const params = new URLSearchParams();
    if (!isProgramHead && deptSelect.value) {
      params.set("departmentId", deptSelect.value);
    }
    if (form.status.value) params.set("status", form.status.value);
    params.set("page", String(page));
    params.set("pageSize", String(pageSize));
    return "?" + params.toString();
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
        const actions = canWrite
          ? '<td><button type="button" class="btn btn-secondary btn-small update-status-btn" data-uid="' +
            escapeHtml(row.uid) +
            '" data-name="' +
            escapeHtml(row.studentName) +
            '" data-status="' +
            escapeHtml(row.status) +
            '">Update status</button></td>'
          : "";

        return (
          "<tr>" +
          "<td><div class=\"cell-strong\">" +
          escapeHtml(row.studentName) +
          "</div><div class=\"cell-muted\">" +
          escapeHtml(row.studentEmail) +
          "</div></td>" +
          "<td>" +
          escapeHtml(row.departmentName) +
          "</td>" +
          "<td class=\"reason-cell\">" +
          escapeHtml(row.reason) +
          "</td>" +
          "<td>" +
          statusBadge(row.status) +
          "</td>" +
          "<td>" +
          escapeHtml(row.issuedByName) +
          "</td>" +
          "<td>" +
          escapeHtml(Att.formatTimestamp(row.createdAt)) +
          "</td>" +
          actions +
          "</tr>"
        );
      })
      .join("");

    bodyEl.querySelectorAll(".update-status-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openStatusModal(btn.dataset.uid, btn.dataset.name, btn.dataset.status);
      });
    });
  }

  function updatePagination(pagination) {
    totalPages = pagination.totalPages || 1;
    page = pagination.page || 1;
    countEl.textContent = String(pagination.total || 0);
    document.getElementById("page-label").textContent =
      "Page " + page + " of " + totalPages;
    document.getElementById("prev-page").disabled = page <= 1;
    document.getElementById("next-page").disabled = page >= totalPages;
  }

  async function loadList() {
    hideMessages();
    try {
      const result = await Api.api("/blocking/list.php" + queryString());
      renderRows(result.data.records || []);
      updatePagination(result.data.pagination || {});
    } catch (err) {
      showError(err.message || "Unable to load blocks.");
    }
  }

  async function loadDepartments() {
    const result = await Api.api("/departments/index.php");
    departments = result.data.departments || [];

    departments.forEach(function (dept) {
      const opt = document.createElement("option");
      opt.value = dept.uid;
      opt.textContent = dept.name;
      deptSelect.appendChild(opt);

      const issueOpt = document.createElement("option");
      issueOpt.value = dept.uid;
      issueOpt.textContent = dept.name;
      document.getElementById("issue-departmentId").appendChild(issueOpt);
    });

    if (isProgramHead && user.departmentId) {
      document.getElementById("issue-departmentId").value = user.departmentId;
      document.getElementById("issue-department-wrap").hidden = true;
    }
  }

  async function loadStudents() {
    const select = document.getElementById("issue-studentId");
    select.innerHTML = '<option value="">Select student…</option>';
    const result = await Api.api("/students/index.php");
    (result.data.students || []).forEach(function (student) {
      const opt = document.createElement("option");
      opt.value = student.uid;
      opt.textContent = student.fullName + " (" + (student.schoolId || student.email) + ")";
      select.appendChild(opt);
    });
  }

  function openModal(id) {
    document.getElementById(id).hidden = false;
  }

  function closeModal(id) {
    document.getElementById(id).hidden = true;
  }

  function openStatusModal(uid, name, status) {
    document.getElementById("status-blockId").value = uid;
    document.getElementById("status-value").value =
      String(status).toLowerCase() === "cleared" ? "Cleared" : "Active";
    document.getElementById("status-summary").textContent =
      "Update status for " + name + ".";
    openModal("status-modal");
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    page = 1;
    loadList();
  });

  document.getElementById("prev-page").addEventListener("click", function () {
    if (page > 1) {
      page -= 1;
      loadList();
    }
  });

  document.getElementById("next-page").addEventListener("click", function () {
    if (page < totalPages) {
      page += 1;
      loadList();
    }
  });

  issueBtn.addEventListener("click", async function () {
    hideMessages();
    try {
      await loadStudents();
      document.getElementById("issue-form").reset();
      if (isProgramHead && user.departmentId) {
        document.getElementById("issue-departmentId").value = user.departmentId;
      }
      openModal("issue-modal");
    } catch (err) {
      showError(err.message || "Unable to load students.");
    }
  });

  document.getElementById("issue-cancel").addEventListener("click", function () {
    closeModal("issue-modal");
  });
  document.getElementById("status-cancel").addEventListener("click", function () {
    closeModal("status-modal");
  });

  document.getElementById("issue-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    const submitBtn = document.getElementById("issue-submit");
    submitBtn.disabled = true;
    try {
      const payload = {
        studentId: document.getElementById("issue-studentId").value,
        departmentId: document.getElementById("issue-departmentId").value,
        reason: document.getElementById("issue-reason").value.trim(),
      };
      await Api.api("/blocking/create.php", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      closeModal("issue-modal");
      showSuccess("Block issued.");
      page = 1;
      await loadList();
    } catch (err) {
      showError(err.message || "Unable to issue block.");
    } finally {
      submitBtn.disabled = false;
    }
  });

  document.getElementById("status-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    const submitBtn = document.getElementById("status-submit");
    submitBtn.disabled = true;
    try {
      await Api.api("/blocking/update.php", {
        method: "POST",
        body: JSON.stringify({
          uid: document.getElementById("status-blockId").value,
          status: document.getElementById("status-value").value,
        }),
      });
      closeModal("status-modal");
      showSuccess("Block status updated.");
      await loadList();
    } catch (err) {
      showError(err.message || "Unable to update block.");
    } finally {
      submitBtn.disabled = false;
    }
  });

  loadDepartments()
    .then(loadList)
    .catch(function (err) {
      showError(err.message || "Unable to initialize blocking page.");
    });
})();
