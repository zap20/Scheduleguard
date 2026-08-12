(function (window) {
  "use strict";

  const MODULES = {
    Faculty: [
      { href: "app.html", label: "Dashboard" },
      { href: "faculty-schedule.html", label: "Faculty schedule" },
      { href: "attendance-faculty.html", label: "My attendance" },
    ],
    Student: [
      { href: "app.html", label: "Dashboard" },
      { href: "student-schedule.html", label: "Student schedule" },
      { href: "student-blocks.html", label: "Blocking status" },
    ],
    Dean: [
      { href: "app.html", label: "Dashboard" },
      { href: "curriculum.html", label: "Curriculum" },
      { href: "rooms.html", label: "Rooms" },
      {
        label: "Schedules",
        children: [
          { href: "student-schedule-dean.html", label: "Student schedule" },
          { href: "faculty-schedule-dean.html", label: "Faculty schedule" },
        ],
      },
      { href: "users.html", label: "Users" },
      { href: "attendance-dean.html", label: "Attendance oversight" },
      { href: "blocking.html", label: "Blocking" },
      { href: "audit.html", label: "Audit trail" },
    ],
    HR: [
      { href: "app.html", label: "Dashboard" },
      { href: "attendance-hr.html", label: "Attendance review" },
      { href: "blocking.html", label: "Blocking list" },
    ],
    ProgramHead: [
      { href: "app.html", label: "Dashboard" },
      { href: "curriculum.html", label: "Curriculum" },
      { href: "enrollment.html", label: "Enrollment" },
      { href: "blocking.html", label: "Blocking" },
    ],
    Checker: [{ href: "app.html", label: "Dashboard" }],
  };

  const SCHEDULE_CHILD_PAGES = {
    "schedules-home.html": true,
    "student-schedule-dean.html": true,
    "faculty-schedule-dean.html": true,
    "schedules.html": true,
    "class-blocks.html": true,
  };

  function currentPage() {
    const path = window.location.pathname.replace(/\\/g, "/");
    const parts = path.split("/");
    return parts[parts.length - 1] || "app.html";
  }

  function ensureShellMarkup() {
    if (document.getElementById("app-drawer")) return;

    const drawer = document.createElement("aside");
    drawer.id = "app-drawer";
    drawer.className = "app-drawer";
    drawer.setAttribute("aria-label", "Modules");
    drawer.innerHTML =
      '<div class="drawer-head">' +
      "<div>" +
      '<p class="drawer-brand">ScheduleGuard</p>' +
      '<p class="drawer-user" id="drawer-user-label">…</p>' +
      "</div>" +
      "</div>" +
      '<nav class="drawer-nav" id="drawer-nav" aria-label="Modules"></nav>' +
      '<div class="drawer-foot">' +
      '<button type="button" class="btn btn-secondary" id="drawer-logout-btn">Sign out</button>' +
      "</div>";

    document.body.classList.add("has-app-drawer");
    document.body.insertBefore(drawer, document.body.firstChild);

    document.querySelectorAll('.topbar a.text-link[href="app.html"]').forEach(function (a) {
      a.hidden = true;
    });

    document.querySelectorAll(".topbar-actions #logout-btn, .topbar #logout-btn").forEach(function (btn) {
      btn.hidden = true;
    });
  }

  function renderNav(role) {
    const nav = document.getElementById("drawer-nav");
    if (!nav) return;
    const page = currentPage();
    const links = MODULES[role] || MODULES.Checker;

    nav.innerHTML = links
      .map(function (link, index) {
        if (link.children && link.children.length) {
          const childActive = link.children.some(function (c) {
            return c.href === page;
          });
          const groupActive = childActive || SCHEDULE_CHILD_PAGES[page];
          const open = groupActive ? " is-open" : "";
          const activeParent = groupActive ? " is-active" : "";
          const kids = link.children
            .map(function (child) {
              const active = page === child.href ? " is-active" : "";
              return (
                '<a class="drawer-sublink' +
                active +
                '" href="' +
                child.href +
                '">' +
                child.label +
                "</a>"
              );
            })
            .join("");
          return (
            '<div class="drawer-group' +
            open +
            '" data-drawer-group="' +
            index +
            '">' +
            '<button type="button" class="drawer-link drawer-toggle' +
            activeParent +
            '" aria-expanded="' +
            (groupActive ? "true" : "false") +
            '">' +
            '<span>' +
            link.label +
            "</span>" +
            '<span class="drawer-caret" aria-hidden="true"></span>' +
            "</button>" +
            '<div class="drawer-submenu">' +
            kids +
            "</div>" +
            "</div>"
          );
        }

        const active = page === link.href ? " is-active" : "";
        return (
          '<a class="drawer-link' +
          active +
          '" href="' +
          link.href +
          '">' +
          link.label +
          "</a>"
        );
      })
      .join("");

    nav.querySelectorAll(".drawer-toggle").forEach(function (btn) {
      btn.addEventListener("click", function () {
        const group = btn.closest(".drawer-group");
        if (!group) return;
        const willOpen = !group.classList.contains("is-open");
        group.classList.toggle("is-open", willOpen);
        btn.setAttribute("aria-expanded", willOpen ? "true" : "false");
      });
    });
  }

  async function bindLogout(button) {
    if (!button) return;
    button.addEventListener("click", async function () {
      button.disabled = true;
      try {
        await window.ScheduleGuardApi.api("/auth/logout.php", {
          method: "POST",
          body: "{}",
        });
      } catch {
        /* ignore */
      }
      window.ScheduleGuardApi.clearSession();
      window.location.href = "index.html";
    });
  }

  async function initShell() {
    if (!document.querySelector(".app-shell")) return;
    if (!window.ScheduleGuardApi || !window.ScheduleGuardApi.getToken()) return;

    ensureShellMarkup();

    try {
      const result = await window.ScheduleGuardApi.api("/auth/me.php", { method: "GET" });
      const user = result.data.user;
      window.ScheduleGuardApi.setSession(user, window.ScheduleGuardApi.getToken());

      const label = document.getElementById("drawer-user-label");
      if (label) {
        label.textContent = user.firstName + " " + user.lastName + " · " + user.role;
      }
      renderNav(user.role);
      bindLogout(document.getElementById("drawer-logout-btn"));
    } catch {
      /* page scripts handle auth redirects */
    }
  }

  window.ScheduleGuardShell = {
    init: initShell,
    modules: MODULES,
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initShell);
  } else {
    initShell();
  }
})(window);
