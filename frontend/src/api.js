async function request(path, { method = 'GET', body, csrfToken } = {}) {
  const headers = { Accept: 'application/json' };
  const isFormData = body instanceof FormData;
  if (body !== undefined && !isFormData) headers['Content-Type'] = 'application/json';
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

  const response = await fetch(`api/${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : isFormData ? body : JSON.stringify(body),
  });

  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.error || 'Permintaan tidak dapat diproses.');
  }
  return payload;
}

export const api = {
  session: () => request('session.php'),
  login: (body, csrfToken) => request('login.php', { method: 'POST', body, csrfToken }),
  logout: (csrfToken) => request('logout.php', { method: 'POST', body: {}, csrfToken }),
  changePassword: (body, csrfToken) =>
    request('change_password.php', { method: 'POST', body, csrfToken }),
  regulations: () => request('regulations.php'),
  lookups: () => request('lookups.php'),
  accounts: (type) => request(`accounts.php?type=${encodeURIComponent(type)}`),
  saveAccount: (type, body, csrfToken) =>
    request(`accounts.php?type=${encodeURIComponent(type)}`, { method: 'POST', body, csrfToken }),
  masterData: (type) => request(`master-data.php?type=${encodeURIComponent(type)}`),
  saveMasterData: (type, body, csrfToken) =>
    request(`master-data.php?type=${encodeURIComponent(type)}`, { method: 'POST', body, csrfToken }),
  saveRegulation: (body, csrfToken) =>
    request('regulations.php', { method: 'POST', body, csrfToken }),
  uploadDocuments: (proposalId, files, csrfToken) => {
    const body = new FormData();
    body.append('proposalId', String(proposalId));
    files.forEach((file) => body.append('documents[]', file));
    return request('documents.php', { method: 'POST', body, csrfToken });
  },
  documentUrl: (id) => `api/documents.php?download=${encodeURIComponent(id)}`,
  addNote: (body, csrfToken) => request('notes.php', { method: 'POST', body, csrfToken }),
};
