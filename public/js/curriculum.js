(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Dean", "ProgramHead"]);
  if (!user) return;

  const isProgramHead = user.role === "ProgramHead";
  let departments = [];
  let subjects = [];

  const alertEl = document.getElementById("curriculum-alert");
  const successEl = document.getElementById("curriculum-success");
  const groupsEl = document.getElementById("curriculum-groups");
  const emptyEl = document.getElementById("curriculum-empty");
  const countEl = document.getElementById("result-count");
  const modal = document.getElementById("subject-modal");
  const form = document.getElementById("subject-form");

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName + " (" + user.role + ")";
  Att.bindLogout(document.getElementById("logout-btn"));

  if (isProgramHead) {
    document.getElementById("department-filter-wrap").hidden = true;
    document.getElementById("subject-department-wrap").hidden = true;
    document.getElementById("subject-departmentId").required = false;
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

  function queryString() {
    const params = new URLSearchParams();
    const dept = document.getElementById("filter-departmentId").value;
    const year = document.getElementById("filter-yearLevel").value;
    const sem = document.getElementById("filter-semester").value;
    const status = document.getElementById("filter-status").value;
    const q = document.getElementById("filter-q").value.trim();
    if (!isProgramHead && dept) params.set("departmentId", dept);
    if (year) params.set("yearLevel", year);
    if (sem) params.set("semester", sem);
    if (status) params.set("status", status);
    if (q) params.set("q", q);
    const s = params.toString();
    return s ? "?" + s : "";
  }

  function groupKey(row) {
    return row.yearLevel + " · " + row.semester;
  }

  function renderSubjects(rows) {
    subjects = rows;
    countEl.textContent = String(rows.length);
    groupsEl.innerHTML = "";

    if (rows.length === 0) {
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    const groups = {};
    rows.forEach(function (row) {
      const key = groupKey(row);
      if (!groups[key]) groups[key] = [];
      groups[key].push(row);
    });

    Object.keys(groups).forEach(function (key) {
      const section = document.createElement("div");
      section.className = "curriculum-group";
      section.innerHTML =
        "<h3 class=\"form-title\" style=\"margin-top:1.25rem\">" +
        escapeHtml(key) +
        ' <span class="count-chip" style="display:inline-flex;margin-left:0.5rem">' +
        groups[key].length +
        "</span></h3>";

      const wrap = document.createElement("div");
      wrap.className = "table-wrap";
      const table = document.createElement("table");
      table.className = "data-table";
      table.innerHTML =
        "<thead><tr>" +
        "<th>Code</th><th>Title</th><th>Units</th><th>Room</th><th>Curriculum</th><th>Department</th><th>Status</th><th>Actions</th>" +
        "</tr></thead>";
      const tbody = document.createElement("tbody");

      groups[key].forEach(function (row) {
        const tr = document.createElement("tr");
        const archived = String(row.status).toLowerCase() === "archived";
        tr.innerHTML =
          "<td><strong>" +
          escapeHtml(row.code) +
          "</strong></td>" +
          "<td>" +
          escapeHtml(row.title) +
          "</td>" +
          "<td>" +
          escapeHtml(row.units) +
          "</td>" +
          "<td>" +
          escapeHtml(row.preferredRoomType || "LECTURE") +
          "</td>" +
          "<td>" +
          escapeHtml(row.curriculumYear) +
          "</td>" +
          "<td>" +
          escapeHtml(row.departmentName) +
          "</td>" +
          "<td><span class=\"status-badge " +
          (archived ? "status-wrong" : "status-present") +
          '">' +
          escapeHtml(row.status) +
          "</span></td>" +
          "<td class=\"row-actions\">" +
          '<button type="button" class="btn btn-secondary btn-edit" data-uid="' +
          escapeHtml(row.uid) +
          '" style="width:auto;min-width:70px">Edit</button> ' +
          (archived
            ? ""
            : '<button type="button" class="btn btn-secondary btn-archive" data-uid="' +
              escapeHtml(row.uid) +
              '" style="width:auto;min-width:80px">Archive</button>') +
          "</td>";
        tbody.appendChild(tr);
      });

      table.appendChild(tbody);
      wrap.appendChild(table);
      section.appendChild(wrap);
      groupsEl.appendChild(section);
    });
  }

  async function loadDepartments() {
    if (isProgramHead) return;
    const res = await Api.api("/departments/index.php");
    departments = res.data.departments || [];
    const filter = document.getElementById("filter-departmentId");
    const formSelect = document.getElementById("subject-departmentId");
    departments.forEach(function (d) {
      const o1 = document.createElement("option");
      o1.value = d.uid;
      o1.textContent = d.name;
      filter.appendChild(o1);
      const o2 = document.createElement("option");
      o2.value = d.uid;
      o2.textContent = d.name;
      formSelect.appendChild(o2);
    });
  }

  async function loadSubjects() {
    hideMessages();
    const res = await Api.api("/subjects/list.php" + queryString());
    if (!res || !res.data) {
      throw new Error(
        (res && res.error) ||
          "Subjects response was incomplete. Run php database/install.php if the database is out of date."
      );
    }
    renderSubjects(res.data.subjects || []);
  }

  function openModal(row) {
    document.getElementById("subject-modal-title").textContent = row
      ? "Edit subject"
      : "Add subject";
    document.getElementById("subject-uid").value = row ? row.uid : "";
    document.getElementById("subject-code").value = row ? row.code : "";
    document.getElementById("subject-title").value = row ? row.title : "";
    document.getElementById("subject-yearLevel").value = row ? row.yearLevel : "1st Year";
    document.getElementById("subject-semester").value = row ? row.semester : "1st Semester";
    document.getElementById("subject-curriculumYear").value = row
      ? row.curriculumYear
      : new Date().getFullYear();
    document.getElementById("subject-preferredRoomType").value = row
      ? row.preferredRoomType || "LECTURE"
      : "LECTURE";
    document.getElementById("subject-units").value = row ? row.units : 3;
    if (!isProgramHead) {
      document.getElementById("subject-departmentId").value = row
        ? row.departmentId
        : "";
    }
    modal.hidden = false;
  }

  function closeModal() {
    modal.hidden = true;
    form.reset();
    document.getElementById("subject-uid").value = "";
  }

  document.getElementById("create-btn").addEventListener("click", function () {
    openModal(null);
  });
  document.getElementById("subject-cancel").addEventListener("click", closeModal);
  modal.addEventListener("click", function (e) {
    if (e.target === modal) closeModal();
  });

  document.getElementById("filter-form").addEventListener("submit", function (e) {
    e.preventDefault();
    loadSubjects().catch(function (err) {
      showError(err.message || "Failed to load subjects.");
    });
  });

  groupsEl.addEventListener("click", async function (e) {
    const editBtn = e.target.closest(".btn-edit");
    const archiveBtn = e.target.closest(".btn-archive");
    if (editBtn) {
      const row = subjects.find(function (s) {
        return s.uid === editBtn.getAttribute("data-uid");
      });
      if (row) openModal(row);
      return;
    }
    if (archiveBtn) {
      const uid = archiveBtn.getAttribute("data-uid");
      if (!window.confirm("Archive this subject? Past schedules will keep their reference.")) {
        return;
      }
      try {
        hideMessages();
        await Api.api("/subjects/archive.php", {
          method: "POST",
          body: JSON.stringify({ subjectId: uid }),
        });
        showSuccess("Subject archived.");
        await loadSubjects();
      } catch (err) {
        showError(err.message || "Archive failed.");
      }
    }
  });

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    const uid = document.getElementById("subject-uid").value;
    const payload = {
      code: document.getElementById("subject-code").value.trim(),
      title: document.getElementById("subject-title").value.trim(),
      yearLevel: document.getElementById("subject-yearLevel").value,
      semester: document.getElementById("subject-semester").value,
      curriculumYear: Number(document.getElementById("subject-curriculumYear").value),
      preferredRoomType: document.getElementById("subject-preferredRoomType").value,
      units: Number(document.getElementById("subject-units").value),
    };
    if (!isProgramHead) {
      payload.departmentId = document.getElementById("subject-departmentId").value;
    }
    try {
      hideMessages();
      if (uid) {
        payload.subjectId = uid;
        await Api.api("/subjects/update.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        showSuccess("Subject updated.");
      } else {
        await Api.api("/subjects/create.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        showSuccess("Subject created.");
      }
      closeModal();
      await loadSubjects();
    } catch (err) {
      showError(err.message || "Save failed.");
    }
  });

  try {
    await loadDepartments();
    await loadSubjects();
  } catch (err) {
    showError(err.message || "Failed to load curriculum.");
  }
})();
