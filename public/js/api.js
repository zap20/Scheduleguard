(function (window) {
  "use strict";

  /**
   * Resolve API base from this script's URL so it works under:
   *   http://127.0.0.1:8765/          (PHP built-in server + router.php)
   *   http://localhost/schoolguard/   (Apache subdirectory, public/ rewritten)
   *   http://localhost/schoolguard/public/
   */
  function apiBase() {
    const script = document.querySelector('script[src*="js/api.js"]');
    if (script && script.getAttribute("src")) {
      try {
        const url = new URL(script.src, window.location.href);
        let dir = url.pathname.replace(/\\/g, "/").replace(/\/js\/api\.js.*$/i, "");
        dir = dir.replace(/\/public$/i, "");
        return (dir || "") + "/api";
      } catch {
        // fall through
      }
    }

    const path = window.location.pathname.replace(/\\/g, "/");
    const publicIdx = path.lastIndexOf("/public/");
    if (publicIdx !== -1) {
      return path.slice(0, publicIdx) + "/api";
    }

    const file = path.split("/").pop() || "";
    const dir = file.includes(".")
      ? path.slice(0, path.lastIndexOf("/"))
      : path.replace(/\/$/, "");
    return (dir || "") + "/api";
  }

  const TOKEN_KEY = "scheduleguard_token";
  const USER_KEY = "scheduleguard_user";

  function getToken() {
    return window.localStorage.getItem(TOKEN_KEY);
  }

  function setSession(user, token) {
    window.localStorage.setItem(USER_KEY, JSON.stringify(user));
    if (token) {
      window.localStorage.setItem(TOKEN_KEY, token);
    }
  }

  function clearSession() {
    window.localStorage.removeItem(USER_KEY);
    window.localStorage.removeItem(TOKEN_KEY);
  }

  function getStoredUser() {
    const raw = window.localStorage.getItem(USER_KEY);
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }

  async function api(path, options = {}) {
    const isFormData =
      typeof FormData !== "undefined" && options.body instanceof FormData;

    const headers = Object.assign({ Accept: "application/json" }, options.headers || {});
    if (!isFormData && !headers["Content-Type"]) {
      headers["Content-Type"] = "application/json";
    }

    const token = getToken();
    if (token) {
      headers.Authorization = "Bearer " + token;
    }

    const response = await fetch(apiBase() + path, {
      credentials: "include",
      ...options,
      headers,
    });

    let payload = null;
    const text = await response.text();
    if (text) {
      try {
        payload = JSON.parse(text);
      } catch {
        payload = { success: false, error: text };
      }
    }

    if (!response.ok) {
      const error = new Error(
        (payload && payload.error) || "Request failed (" + response.status + ")"
      );
      error.status = response.status;
      error.payload = payload;
      throw error;
    }

    return payload;
  }

  window.ScheduleGuardApi = {
    apiBase,
    api,
    getToken,
    setSession,
    clearSession,
    getStoredUser,
  };
})(window);
