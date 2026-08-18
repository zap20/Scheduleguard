(function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;

  let page = 1;
  const pageSize = 10;
  let totalPages = 1;
  let roles = [];
  let usersById = {};
  let editingUid = null;
  let currentUser = null;

  const alertEl = document.getElementById("users-alert");
  const successEl = document.getElementById("users-success");
  const bodyEl = document.getElementById("users-body");
  const emptyEl = document.getElementById("users-empty");
  const form = document.getElementById("filter-form");

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

  function statusBadge(status) {
    const active = String(status).toLowerCase() === "active";
    const cls = active ? "status-present" : "status-none";
    return '<span class="status-badge ' + cls + '">' + escapeHtml(status) + "</span>";
  }

  function roleDetails(row) {
    if (row.role === "Faculty") {
      const emp = row.employmentType === "PartTime" ? "Part-time" : "Regular";
      const min = row.minLoad != null ? row.minLoad : emp === "Part-time" ? 3 : 8;
      return emp + " · min " + min;
    }
    if (row.role === "Student") {
      return (
        (row.yearLevel || "—") +
        " · " +
        (row.studentType || "Regular")
      );
    }
    return "—";
  }

  function syncRoleFields() {
    const role = document.getElementById("form-role").value;
    document.getElementById("faculty-fields").hidden = role !== "Faculty";
    document.getElementById("student-fields").hidden = role !== "Student";
    document.getElementById("form-employmentType").required = role === "Faculty";
    document.getElementById("form-yearLevel").required = role === "Student";
    document.getElementById("form-studentType").required = role === "Student";
  }

  function queryString() {
    const params = new URLSearchParams();
    if (form.q.value.trim()) params.set("q", form.q.value.trim());
    if (form.role.value) params.set("role", form.role.value);
    if (form.status.value) params.set("status", form.status.value);
    params.set("page", String(page));
    params.set("pageSize", String(pageSize));
    return "?" + params.toString();
  }

  function fillRoleSelects() {
    const filterRole = document.getElementById("role");
    const formRole = document.getElementById("form-role");
    const currentFilter = filterRole.value;

    filterRole.innerHTML = '<option value="">All roles</option>';
    formRole.innerHTML = "";

    roles.forEach(function (role) {
      const opt1 = document.createElement("option");
      opt1.value = role;
      opt1.textContent = role;
      filterRole.appendChild(opt1);

      const opt2 = document.createElement("option");
      opt2.value = role;
      opt2.textContent = role;
      formRole.appendChild(opt2);
    });

    filterRole.value = currentFilter;
  }

  function renderUsers(users) {
    usersById = {};
    if (!users.length) {
      bodyEl.innerHTML = "";
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    bodyEl.innerHTML = users
      .map(function (row) {
        usersById[row.uid] = row;
        const canDeactivate =
          row.status === "Active" && currentUser && row.uid !== currentUser.uid;

        return (
          "<tr>" +
          '<td class="cell-strong">' +
          escapeHtml(row.schoolId || "—") +
          "</td>" +
          "<td>" +
          escapeHtml(row.fullName) +
          "</td>" +
          "<td>" +
          escapeHtml(row.email) +
          "</td>" +
          "<td>" +
          escapeHtml(row.role) +
          "</td>" +
          "<td>" +
          escapeHtml(roleDetails(row)) +
          "</td>" +
          "<td>" +
          escapeHtml(row.departmentName || "—") +
          "</td>" +
          "<td>" +
          statusBadge(row.status) +
          "</td>" +
          "<td class=\"action-cell\">" +
          (row.role === "Faculty" && row.schoolId
            ? '<button type="button" class="btn btn-secondary btn-small" data-qr="' +
              escapeHtml(row.uid) +
              '">QR</button>'
            : "") +
          '<button type="button" class="btn btn-secondary btn-small" data-edit="' +
          escapeHtml(row.uid) +
          '">Edit</button>' +
          (canDeactivate
            ? '<button type="button" class="btn btn-small" data-deactivate="' +
              escapeHtml(row.uid) +
              '">Deactivate</button>'
            : "") +
          "</td>" +
          "</tr>"
        );
      })
      .join("");

    bodyEl.querySelectorAll("[data-edit]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openEdit(btn.getAttribute("data-edit"));
      });
    });
    bodyEl.querySelectorAll("[data-deactivate]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        deactivateUser(btn.getAttribute("data-deactivate"));
      });
    });
    bodyEl.querySelectorAll("[data-qr]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openFacultyQr(btn.getAttribute("data-qr"));
      });
    });
  }

  function openFacultyQr(uid) {
    const row = usersById[uid];
    if (!row || !row.schoolId) return;
    const payload = String(row.schoolId).trim();
    document.getElementById("faculty-qr-title").textContent =
      "Faculty QR · " + (row.fullName || row.schoolId);
    document.getElementById("faculty-qr-payload").textContent = payload;
    const host = document.getElementById("faculty-qr-code");
    host.innerHTML = "";
    if (typeof QRCode === "undefined") {
      host.textContent = "QR library failed to load. Payload: " + payload;
    } else {
      new QRCode(host, {
        text: payload,
        width: 220,
        height: 220,
        correctLevel: QRCode.CorrectLevel.M,
      });
    }
    document.getElementById("faculty-qr-modal").hidden = false;
  }

  function closeFacultyQr() {
    document.getElementById("faculty-qr-modal").hidden = true;
    document.getElementById("faculty-qr-code").innerHTML = "";
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

  async function loadUsers() {
    hideMessages();
    const result = await Api.api("/users/list.php" + queryString());
    if (result.data.roles) {
      roles = result.data.roles;
      fillRoleSelects();
    }
    renderUsers(result.data.users || []);
    updatePagination(result.data.pagination || {});
  }

  async function loadDepartments() {
    const result = await Api.api("/departments/index.php");
    const select = document.getElementById("form-departmentId");
    (result.data.departments || []).forEach(function (dept) {
      const opt = document.createElement("option");
      opt.value = dept.uid;
      opt.textContent = dept.name;
      select.appendChild(opt);
    });
  }

  function openModal(isCreate) {
    document.getElementById("user-modal").hidden = false;
    document.getElementById("user-modal-title").textContent = isCreate
      ? "Create user"
      : "Edit user";
    document.getElementById("user-modal-sub").textContent = isCreate
      ? "Password is required for new accounts."
      : "Leave password blank to keep the current password. Role changes apply on the user’s next request.";
    document.getElementById("password-hint").textContent = isCreate
      ? "(required)"
      : "(optional)";
    document.getElementById("form-password").required = isCreate;
  }

  function closeModal() {
    document.getElementById("user-modal").hidden = true;
    editingUid = null;
  }

  function openCreate() {
    editingUid = null;
    document.getElementById("user-form").reset();
    document.getElementById("form-uid").value = "";
    document.getElementById("form-status").value = "Active";
    document.getElementById("form-employmentType").value = "Regular";
    document.getElementById("form-yearLevel").value = "1st Year";
    document.getElementById("form-studentType").value = "Regular";
    if (roles.length) {
      document.getElementById("form-role").value = roles[0];
    }
    syncRoleFields();
    openModal(true);
  }

  function openEdit(uid) {
    const row = usersById[uid];
    if (!row) return;
    editingUid = uid;
    document.getElementById("form-uid").value = row.uid;
    document.getElementById("form-firstName").value = row.firstName;
    document.getElementById("form-lastName").value = row.lastName;
    document.getElementById("form-schoolId").value = row.schoolId || "";
    document.getElementById("form-email").value = row.email;
    document.getElementById("form-role").value = row.role;
    document.getElementById("form-departmentId").value = row.departmentId || "";
    document.getElementById("form-phoneNumber").value = row.phoneNumber || "";
    document.getElementById("form-status").value = row.status;
    document.getElementById("form-employmentType").value =
      row.employmentType || "Regular";
    document.getElementById("form-yearLevel").value = row.yearLevel || "1st Year";
    document.getElementById("form-studentType").value = row.studentType || "Regular";
    document.getElementById("form-password").value = "";
    syncRoleFields();
    openModal(false);
  }

  async function deactivateUser(uid) {
    const row = usersById[uid];
    if (!row) return;
    if (
      !window.confirm(
        "Deactivate " +
          row.fullName +
          "? Historical records stay intact; they will not be able to sign in."
      )
    ) {
      return;
    }
    hideMessages();
    try {
      await Api.api("/users/deactivate.php", {
        method: "POST",
        body: JSON.stringify({ uid: uid }),
      });
      showSuccess("User deactivated.");
      await loadUsers();
    } catch (err) {
      showError(err.message || "Deactivate failed.");
    }
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    page = 1;
    loadUsers().catch(function (err) {
      showError(err.message || "Unable to load users.");
    });
  });

  document.getElementById("prev-page").addEventListener("click", function () {
    if (page > 1) {
      page -= 1;
      loadUsers().catch(function (err) {
        showError(err.message || "Unable to load users.");
      });
    }
  });

  document.getElementById("next-page").addEventListener("click", function () {
    if (page < totalPages) {
      page += 1;
      loadUsers().catch(function (err) {
        showError(err.message || "Unable to load users.");
      });
    }
  });

  document.getElementById("create-btn").addEventListener("click", openCreate);
  document.getElementById("user-cancel").addEventListener("click", closeModal);
  document.getElementById("faculty-qr-close").addEventListener("click", closeFacultyQr);
  document.getElementById("faculty-qr-modal").addEventListener("click", function (e) {
    if (e.target === document.getElementById("faculty-qr-modal")) closeFacultyQr();
  });
  document.getElementById("faculty-qr-print").addEventListener("click", function () {
    const title = document.getElementById("faculty-qr-title").textContent;
    const payload = document.getElementById("faculty-qr-payload").textContent;
    const qrHtml = document.getElementById("faculty-qr-code").innerHTML;
    const w = window.open("", "_blank", "noopener,noreferrer,width=480,height=640");
    if (!w) return;
    w.document.write(
      "<!DOCTYPE html><html><head><title>" +
        escapeHtml(title) +
        "</title><style>body{font-family:system-ui,sans-serif;text-align:center;padding:24px}" +
        "code{word-break:break-all}</style></head><body>" +
        "<h1>" +
        escapeHtml(title) +
        "</h1><div>" +
        qrHtml +
        "</div><p><code>" +
        escapeHtml(payload) +
        "</code></p>" +
        "<script>window.onload=function(){window.print();}<\\/script>" +
        "</body></html>"
    );
    w.document.close();
  });

  document.getElementById("user-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    hideMessages();
    const submitBtn = document.getElementById("user-submit");
    submitBtn.disabled = true;

    const payload = {
      firstName: document.getElementById("form-firstName").value.trim(),
      lastName: document.getElementById("form-lastName").value.trim(),
      schoolId: document.getElementById("form-schoolId").value.trim(),
      email: document.getElementById("form-email").value.trim(),
      role: document.getElementById("form-role").value,
      departmentId: document.getElementById("form-departmentId").value,
      phoneNumber: document.getElementById("form-phoneNumber").value.trim(),
      status: document.getElementById("form-status").value,
      password: document.getElementById("form-password").value,
    };
    if (payload.role === "Faculty") {
      payload.employmentType = document.getElementById("form-employmentType").value;
    }
    if (payload.role === "Student") {
      payload.yearLevel = document.getElementById("form-yearLevel").value;
      payload.studentType = document.getElementById("form-studentType").value;
    }

    try {
      if (editingUid) {
        payload.uid = editingUid;
        if (!payload.password) {
          delete payload.password;
        }
        const result = await Api.api("/users/update.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        let msg = "User updated.";
        if (result.data.roleChanged) {
          msg +=
            " Role changed from " +
            result.data.previousRole +
            " to " +
            result.data.user.role +
            ".";
        }
        showSuccess(msg);
      } else {
        await Api.api("/users/create.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        showSuccess("User created.");
        page = 1;
      }
      closeModal();
      await loadUsers();
    } catch (err) {
      showError(err.message || "Save failed.");
    } finally {
      submitBtn.disabled = false;
    }
  });

  document.getElementById("form-role").addEventListener("change", syncRoleFields);

  (async function boot() {
    currentUser = await Att.requireRole(["Dean"]);
    if (!currentUser) return;

    document.getElementById("user-label").textContent =
      currentUser.firstName + " " + currentUser.lastName;
    Att.bindLogout(document.getElementById("logout-btn"));

    try {
      await loadDepartments();
      await loadUsers();
    } catch (err) {
      showError(err.message || "Unable to initialize user management.");
    }
  })();
})();
