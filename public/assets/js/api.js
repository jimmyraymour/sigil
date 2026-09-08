// Thin fetch wrapper around the Sigil REST API.
// The API key and the mount point are injected server-side via <meta> tags —
// Sigil can be served from a subdirectory (XAMPP puts it at /sigil/), so no
// URL here may assume the domain root.
export const API = {
  key() {
    const el = document.querySelector('meta[name="api-key"]');
    return el ? el.content : '';
  },

  base() {
    const el = document.querySelector('meta[name="base-path"]');
    return el ? el.content : '';
  },

  async request(method, path, body) {
    const headers = { 'Content-Type': 'application/json' };
    const key = this.key();
    if (key) headers['Authorization'] = 'Bearer ' + key;

    const res = await fetch(this.base() + '/api/v1' + path, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });

    let data = null;
    try {
      data = await res.json();
    } catch (e) {
      /* non-JSON response */
    }

    if (!res.ok) {
      const err = (data && data.error) || { message: 'HTTP ' + res.status };
      throw new Error(err.message || 'Request failed');
    }
    return data;
  },

  get(path) {
    return this.request('GET', path);
  },

  post(path, body) {
    return this.request('POST', path, body);
  },
};
