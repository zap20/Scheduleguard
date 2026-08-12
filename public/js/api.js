(function (window) {
  "use strict";

  /**
   * Resolve API base for:
   * - http://localhost/Scheduleguard/… (WAMP subdirectory)
   * - http://localhost/Scheduleguard/public/… (legacy)
   * - http://127.0.0.1:8765/… (php -S + router.php)
   */
  function apiBase() {
    const path = window.location.pathname.replace(/\\/g, "/");
    const publicIdx = path.lastIndexOf("/public/");
    if (publicIdx !== -1) {
      return path.slice(0, publicIdx) + "/api";
    }

    let dir = path;
    if (dir.endsWith("/")) {
      dir = dir.slice(0, -1);
    } else {
      const leaf = dir.split("/").pop() || "";
      // Strip page filenames (app.html); keep bare folder segments (…/Scheduleguard).
      if (/\.[a-zA-Z0-9]+$/.test(leaf)) {
        dir = dir.replace(/\/[^/]+$/, "");
      }
    }

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
