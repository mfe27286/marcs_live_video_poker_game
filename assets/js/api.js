// Tiny fetch wrapper for the PHP API
const API = {
  base: (window.BASE_PATH || "") + "/api",
  async request(path, { method = "GET", body } = {}) {
    const headers = { "X-Requested-With": "XMLHttpRequest" };
    if (window.CSRF) headers["X-CSRF-Token"] = window.CSRF;
    if (body !== undefined) headers["Content-Type"] = "application/json";
    const res = await fetch(this.base + path, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined, credentials: "same-origin" });
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok) {
      const err = new Error((data && data.error) || `Request failed (${res.status})`);
      err.status = res.status;
      throw err;
    }
    return data;
  },
  get(path) { return this.request(path); },
  post(path, body) { return this.request(path, { method: "POST", body: body || {} }); },
};
