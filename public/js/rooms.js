(async function () {
  "use strict";

  const Att = window.ScheduleGuardAttendance;
  const Api = window.ScheduleGuardApi;
  const user = await Att.requireRole(["Dean"]);
  if (!user) return;

  let rooms = [];

  const alertEl = document.getElementById("rooms-alert");
  const successEl = document.getElementById("rooms-success");
  const bodyEl = document.getElementById("rooms-body");
  const emptyEl = document.getElementById("rooms-empty");
  const countEl = document.getElementById("result-count");
  const modal = document.getElementById("room-modal");
  const form = document.getElementById("room-form");

  document.getElementById("user-label").textContent =
    user.firstName + " " + user.lastName + " (" + user.role + ")";
  Att.bindLogout(document.getElementById("logout-btn"));

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
    const type = document.getElementById("filter-roomType").value;
    const q = document.getElementById("filter-q").value.trim();
    if (type) params.set("roomType", type);
    if (q) params.set("q", q);
    const s = params.toString();
    return s ? "?" + s : "";
  }

  function renderRooms(rows) {
    rooms = rows;
    countEl.textContent = String(rows.length);
    bodyEl.innerHTML = "";
    if (rows.length === 0) {
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;
    rows.forEach(function (row) {
      const tr = document.createElement("tr");
      tr.innerHTML =
        "<td><span class=\"status-badge " +
        (row.roomType === "LAB" ? "status-late" : "status-present") +
        '">' +
        escapeHtml(row.roomType) +
        "</span></td>" +
        "<td>" +
        escapeHtml(row.building) +
        "</td>" +
        "<td><strong>" +
        escapeHtml(row.name) +
        "</strong></td>" +
        "<td>" +
        escapeHtml(row.capacity) +
        "</td>" +
        '<td class="row-actions">' +
        '<button type="button" class="btn btn-secondary btn-qr" data-uid="' +
        escapeHtml(row.uid) +
        '" style="width:auto;min-width:70px">QR</button> ' +
        '<button type="button" class="btn btn-secondary btn-edit" data-uid="' +
        escapeHtml(row.uid) +
        '" style="width:auto;min-width:70px">Edit</button>' +
        "</td>";
      bodyEl.appendChild(tr);
    });
  }

  function roomQrPayload(uid) {
    return "SGROOM:" + uid;
  }

  function openQrModal(row) {
    const payload = roomQrPayload(row.uid);
    document.getElementById("qr-modal-title").textContent =
      "QR · " + row.building + " / " + row.name;
    document.getElementById("qr-payload").textContent = payload;
    const host = document.getElementById("qr-code");
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
    document.getElementById("qr-modal").hidden = false;
  }

  function closeQrModal() {
    document.getElementById("qr-modal").hidden = true;
    document.getElementById("qr-code").innerHTML = "";
  }

  async function loadRooms() {
    hideMessages();
    const res = await Api.api("/rooms/index.php" + queryString());
    if (!res || !res.data) {
      throw new Error((res && res.error) || "Rooms response was incomplete.");
    }
    renderRooms(res.data.rooms || []);
  }

  function openModal(row) {
    document.getElementById("room-modal-title").textContent = row ? "Edit room" : "Add room";
    document.getElementById("room-uid").value = row ? row.uid : "";
    document.getElementById("room-roomType").value = row ? row.roomType : "LECTURE";
    document.getElementById("room-building").value = row ? row.building : "";
    document.getElementById("room-name").value = row ? row.name : "";
    document.getElementById("room-capacity").value = row ? row.capacity : 40;
    modal.hidden = false;
  }

  function closeModal() {
    modal.hidden = true;
    form.reset();
    document.getElementById("room-uid").value = "";
  }

  document.getElementById("create-btn").addEventListener("click", function () {
    openModal(null);
  });
  document.getElementById("room-cancel").addEventListener("click", closeModal);
  modal.addEventListener("click", function (e) {
    if (e.target === modal) closeModal();
  });

  document.getElementById("filter-form").addEventListener("submit", function (e) {
    e.preventDefault();
    loadRooms().catch(function (err) {
      showError(err.message || "Failed to load rooms.");
    });
  });

  bodyEl.addEventListener("click", function (e) {
    const qrBtn = e.target.closest(".btn-qr");
    if (qrBtn) {
      const row = rooms.find(function (r) {
        return r.uid === qrBtn.getAttribute("data-uid");
      });
      if (row) openQrModal(row);
      return;
    }
    const btn = e.target.closest(".btn-edit");
    if (!btn) return;
    const row = rooms.find(function (r) {
      return r.uid === btn.getAttribute("data-uid");
    });
    if (row) openModal(row);
  });

  document.getElementById("qr-close").addEventListener("click", closeQrModal);
  document.getElementById("qr-modal").addEventListener("click", function (e) {
    if (e.target === document.getElementById("qr-modal")) closeQrModal();
  });
  document.getElementById("qr-print").addEventListener("click", function () {
    const title = document.getElementById("qr-modal-title").textContent;
    const payload = document.getElementById("qr-payload").textContent;
    const qrHtml = document.getElementById("qr-code").innerHTML;
    const w = window.open("", "_blank", "noopener,noreferrer,width=480,height=640");
    if (!w) {
      showError("Pop-up blocked. Allow pop-ups to print the QR.");
      return;
    }
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

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    const uid = document.getElementById("room-uid").value;
    const payload = {
      roomType: document.getElementById("room-roomType").value,
      building: document.getElementById("room-building").value.trim(),
      name: document.getElementById("room-name").value.trim(),
      capacity: Number(document.getElementById("room-capacity").value),
    };
    try {
      hideMessages();
      if (uid) {
        payload.roomId = uid;
        await Api.api("/rooms/update.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        showSuccess("Room updated.");
      } else {
        await Api.api("/rooms/create.php", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        showSuccess("Room created.");
      }
      closeModal();
      await loadRooms();
    } catch (err) {
      showError(err.message || "Save failed.");
    }
  });

  try {
    await loadRooms();
  } catch (err) {
    showError(err.message || "Failed to load rooms.");
  }
})();
