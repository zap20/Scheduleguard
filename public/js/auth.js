(function () {
  "use strict";

  const form = document.getElementById("login-form");
  const alertEl = document.getElementById("login-alert");
  const submitBtn = document.getElementById("login-submit");

  if (!form) return;

  // Already logged in → bounce to app shell
  const existing = window.ScheduleGuardApi.getStoredUser();
  if (existing && window.ScheduleGuardApi.getToken()) {
    window.location.href = "app.html";
    return;
  }

  function showError(message) {
    alertEl.textContent = message;
    alertEl.classList.add("show");
  }

  function hideError() {
    alertEl.textContent = "";
    alertEl.classList.remove("show");
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    hideError();

    const email = form.email.value.trim();
    const password = form.password.value;

    if (!email || !password) {
      showError("Email and password are required.");
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = "Signing in…";

    try {
      const result = await window.ScheduleGuardApi.api("/auth/login.php", {
        method: "POST",
        body: JSON.stringify({ email, password }),
      });

      const user = result.data.user;
      const token = result.data.token;
      window.ScheduleGuardApi.setSession(user, token);
      window.location.href = "app.html";
    } catch (err) {
      showError(err.message || "Login failed.");
      submitBtn.disabled = false;
      submitBtn.textContent = "Sign in";
    }
  });
})();
