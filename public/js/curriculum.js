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
  const modalAlertEl = document.getElementById("subject-modal-alert");
  const groupsEl = document.getElementById("curriculum-groups");
  const emptyEl = document.getElementById("curriculum-empty");
  const countEl = document.getElementById("result-count");
  const modal = document.getElementById("subject-modal");
  const form = document.getElementById("subject-form");
  const typeSelect = document.getElementById("subject-subjectType");
  const servingWrap = document.getElementById("subject-serving-wrap");
  const servingNameInput = document.getElementById("subject-servingDepartmentName");
  const servingSuggestions = document.getElementById("serving-department-suggestions");
  const ownerSelect = document.getElementById("subject-departmentId");
  const labHoursInput = document.getElementById("subject-labHours");
  const lectureHoursInput = document.getElementById("subject-lectureHours");
  const sessionHint = document.getElementById("subject-session-hint");
  const filterServing = document.getElementById("filter-servingDepartmentId");

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName + " (" + user.role + ")";
  Att.bindLogout(document.getElementById("logout-btn"));

  if (isProgramHead) {
    document.getElementById("department-filter-wrap").hidden = true;
    document.getElementById("subject-department-wrap").hidden = true;
    ownerSelect.required = false;
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
    hideModalError();
  }

  function showModalError(message) {
    modalAlertEl.textContent = message;
    modalAlertEl.classList.add("show");
  }

  function hideModalError() {
    modalAlertEl.classList.remove("show");
    modalAlertEl.textContent = "";
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function owningDepartmentId() {
    if (isProgramHead) {
      return user.departmentId || "";
    }
    return ownerSelect.value || "";
  }

  /** Refresh datalist + Serves filter from departments list. */
  function refreshServingSuggestions() {
    if (servingSuggestions) {
      servingSuggestions.innerHTML = "";
      departments.forEach(function (d) {
        const opt = document.createElement("option");
        opt.value = d.name;
        servingSuggestions.appendChild(opt);
      });
    }
    if (filterServing) {
      const keep = filterServing.value;
      filterServing.innerHTML =
        '<option value="">All</option><option value="none">None (majors)</option>';
      departments.forEach(function (d) {
        const o = document.createElement("option");
        o.value = d.uid;
        o.textContent = d.name;
        filterServing.appendChild(o);
      });
      if (keep) filterServing.value = keep;
    }
  }

  function syncTypeFields() {
    // Serves field is always visible. Typing a name implies Minor.
    const name = (servingNameInput.value || "").trim();
    if (name !== "") {
      typeSelect.value = "MINOR";
    }
  }

  function syncSessionHint() {
    const lec = Number(lectureHoursInput.value) || 0;
    const lab = Number(labHoursInput.value) || 0;
    const parts = [];
    if (lec > 0) parts.push("Lecture " + lec + "h");
    if (lab > 0) {
      const half = Math.round((lab / 2) * 100) / 100;
      parts.push(
        "Lab " + lab + "h → when scheduling, split into 2 days (" + half + "h each) if slots allow"
      );
    }
    sessionHint.textContent =
      parts.length > 0
        ? parts.join(" · ")
        : "Enter lecture and/or lab hours. Lab totals (3h, 6h, …) are split into two day-sessions when a schedule is created.";
  }

  typeSelect.addEventListener("change", function () {
    if (typeSelect.value === "MAJOR") {
      servingNameInput.value = "";
    }
  });
  servingNameInput.addEventListener("input", syncTypeFields);
  lectureHoursInput.addEventListener("input", syncSessionHint);
  labHoursInput.addEventListener("input", syncSessionHint);

  function queryString() {
    const params = new URLSearchParams();
    const dept = document.getElementById("filter-departmentId").value;
    const type = document.getElementById("filter-subjectType").value;
    const serves = filterServing ? filterServing.value : "";
    const year = document.getElementById("filter-yearLevel").value;
    const sem = document.getElementById("filter-semester").value;
    const status = document.getElementById("filter-status").value;
    const q = document.getElementById("filter-q").value.trim();
    if (!isProgramHead && dept) params.set("departmentId", dept);
    if (type) params.set("subjectType", type);
    if (serves) params.set("servingDepartmentId", serves);
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

  function groupSortKey(key) {
    const yearOrder = {
      "1st Year": 1,
      "2nd Year": 2,
      "3rd Year": 3,
      "4th Year": 4,
    };
    const semOrder = {
      "1st Semester": 1,
      "2nd Semester": 2,
      Summer: 3,
    };
    const parts = String(key).split(" · ");
    const y = yearOrder[parts[0]] || 99;
    const s = semOrder[parts[1]] || 99;
    return y * 10 + s;
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

    Object.keys(groups)
      .sort(function (a, b) {
        return groupSortKey(a) - groupSortKey(b);
      })
      .forEach(function (key) {
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
        "<th>Code</th><th>Title</th><th>Type</th><th>Hours</th><th>Units</th>" +
        "<th>Year</th><th>Owner</th><th>Serves</th><th>Status</th><th>Actions</th>" +
        "</tr></thead>";
      const tbody = document.createElement("tbody");

      groups[key].forEach(function (row) {
        const tr = document.createElement("tr");
        const archived = String(row.status).toLowerCase() === "archived";
        const typeLabel = row.subjectType === "MINOR" ? "Minor" : "Major";

        tr.innerHTML =
          "<td><strong>" +
          escapeHtml(row.code) +
          "</strong></td>" +
          "<td>" +
          escapeHtml(row.title) +
          "</td>" +
          "<td>" +
          escapeHtml(typeLabel) +
          "</td>" +
          "<td>" +
          escapeHtml(row.sessionSummary || "—") +
          "</td>" +
          "<td>" +
          escapeHtml(row.units) +
          "</td>" +
          "<td>" +
          escapeHtml(row.curriculumYear) +
          "</td>" +
          "<td>" +
          escapeHtml(row.departmentName) +
          "</td>" +
          "<td>" +
          escapeHtml(row.servingDepartmentName || "—") +
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

  function fillDepartmentSelects(list) {
    const filter = document.getElementById("filter-departmentId");
    departments = list || [];

    if (!isProgramHead) {
      filter.innerHTML = '<option value="">All departments</option>';
      ownerSelect.innerHTML = '<option value="">Select department…</option>';
      departments.forEach(function (d) {
        const o1 = document.createElement("option");
        o1.value = d.uid;
        o1.textContent = d.name;
        filter.appendChild(o1);
        const o2 = document.createElement("option");
        o2.value = d.uid;
        o2.textContent = d.name;
        ownerSelect.appendChild(o2);
      });
    }
    refreshServingSuggestions();
  }

  async function loadDepartments() {
    const res = await Api.api("/departments/index.php");
    fillDepartmentSelects(res.data.departments || []);
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
    hideModalError();
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
    document.getElementById("subject-units").value = row ? row.units : 3;
    typeSelect.value = row && row.subjectType === "MINOR" ? "MINOR" : "MAJOR";
    lectureHoursInput.value = row ? row.lectureHours ?? 1.5 : 1.5;
    labHoursInput.value = row ? row.labHours ?? 0 : 0;
    if (!isProgramHead) {
      ownerSelect.value = row ? row.departmentId : "";
    }
    servingNameInput.value = row && row.servingDepartmentName ? row.servingDepartmentName : "";
    syncTypeFields();
    syncSessionHint();
    modal.hidden = false;
  }

  function closeModal() {
    modal.hidden = true;
    hideModalError();
    form.reset();
    document.getElementById("subject-uid").value = "";
    typeSelect.value = "MAJOR";
    syncTypeFields();
    syncSessionHint();
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

  if (filterServing) {
    filterServing.addEventListener("change", function () {
      loadSubjects().catch(function (err) {
        showError(err.message || "Failed to load subjects.");
      });
    });
  }

  // Serves is edited only via the Edit modal text field.

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
    hideModalError();
    const uid = document.getElementById("subject-uid").value;
    const servingName = (servingNameInput.value || "").trim();
    // Serves name decides type: filled → Minor, blank → Major.
    const subjectType = servingName !== "" ? "MINOR" : "MAJOR";
    typeSelect.value = subjectType;

    const payload = {
      code: document.getElementById("subject-code").value.trim(),
      title: document.getElementById("subject-title").value.trim(),
      yearLevel: document.getElementById("subject-yearLevel").value,
      semester: document.getElementById("subject-semester").value,
      curriculumYear: Number(document.getElementById("subject-curriculumYear").value),
      units: Number(document.getElementById("subject-units").value),
      subjectType: subjectType,
      lectureHours: Number(lectureHoursInput.value) || 0,
      labHours: Number(labHoursInput.value) || 0,
      servingDepartmentName: servingName,
      servingDepartmentId: "",
    };
    if (!isProgramHead) {
      payload.departmentId = ownerSelect.value;
    }

    try {
      hideMessages();
      let saved = null;
      if (uid) {
        payload.subjectId = uid;
        const res = await Api.api("/subjects/update.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        saved = res && res.data && res.data.subject;
        if (
          filterServing &&
          filterServing.value &&
          filterServing.value !== "none" &&
          saved &&
          saved.servingDepartmentId &&
          filterServing.value !== saved.servingDepartmentId
        ) {
          filterServing.value = "";
        }
        showSuccess(
          "Subject updated." +
            (saved && saved.servingDepartmentName
              ? " Serves: " + saved.servingDepartmentName
              : " Serves: —")
        );
      } else {
        const res = await Api.api("/subjects/create.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        saved = res && res.data && res.data.subject;
        showSuccess(
          "Subject created." +
            (saved && saved.servingDepartmentName
              ? " Serves: " + saved.servingDepartmentName
              : "")
        );
      }

      await loadDepartments();
      closeModal();
      await loadSubjects();
    } catch (err) {
      showModalError(err.message || "Save failed.");
    }
  });

  try {
    await loadDepartments();
    syncTypeFields();
    syncSessionHint();
    await loadSubjects();
  } catch (err) {
    showError(err.message || "Failed to load subjects.");
  }
})();
