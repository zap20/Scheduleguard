(function (window) {
  "use strict";

  /**
   * Resolve API base relative to /public so this works under
   * http://localhost/ScheduleGuard/public or a vhost pointed at public/.
   */
  function apiBase() {
    const path = window.location.pathname.replace(/\\/g, "/");
    const publicIdx = path.lastIndexOf("/public/");
    if (publicIdx !== -1) {
      return path.slice(0, publicIdx) + "/api";
    }
    // Built-in server / vhost with router.php: site root maps to public/, API at /api.
    return "/api";
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
