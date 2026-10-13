export class ChatApiError extends Error {
  constructor(message, code = 'NETWORK_ERROR', status = 0) {
    super(message);
    this.name = 'ChatApiError';
    this.code = code;
    this.status = status;
  }
}

export class ChatApi {
  constructor(url = new URL('../../api/chat.php', import.meta.url).href, fetcher = globalThis.fetch) {
    this.url = url;
    // Browser fetch requires its Window receiver, rather than a ChatApi instance.
    this.fetcher = fetcher.bind(globalThis);
    this.csrfToken = null;
  }

  async bootstrap() {
    const payload = await this.request('GET');
    if (payload.status !== 'ready' || typeof payload.csrf_token !== 'string'
      || !Array.isArray(payload.authorized_shopnos)) {
      throw new ChatApiError('The reporting session could not be loaded.', 'INVALID_SESSION');
    }
    this.csrfToken = payload.csrf_token;
    return payload;
  }

  ask(question, conversationId, filters = {}) {
    if (!this.csrfToken) throw new ChatApiError('Refresh the reporting session.', 'NO_SESSION');
    return this.request('POST', { action: 'ask', question, conversation_id: conversationId, filters });
  }

  page(conversationId) {
    if (!this.csrfToken) throw new ChatApiError('Refresh the reporting session.', 'NO_SESSION');
    return this.request('POST', { action: 'page', question: '', conversation_id: conversationId });
  }

  async request(method, payload = null) {
    if (globalThis.location?.protocol === 'file:') {
      throw new ChatApiError('Open this app through Apache at http://localhost/test_ai_project/ui/index.html. PHP reporting cannot run from a local HTML file.', 'SERVER_REQUIRED');
    }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 130000);
    try {
      const response = await this.fetcher(this.url, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        headers: method === 'POST' ? {
          'Content-Type': 'application/json',
          'X-CSRF-Token': this.csrfToken,
        } : { Accept: 'application/json' },
        body: method === 'POST' ? JSON.stringify(payload) : undefined,
        signal: controller.signal,
      });
      const body = await response.text();
      if (response.status === 404) {
        throw new ChatApiError('The reporting endpoint was not found. Open this app through XAMPP Apache at http://localhost/test_ai_project/ui/index.html.', 'ENDPOINT_NOT_FOUND', 404);
      }
      if (body.length > 2300000) throw new ChatApiError('The report response is too large.', 'RESPONSE_TOO_LARGE');
      let data;
      try { data = JSON.parse(body); }
      catch { throw new ChatApiError('The report service returned an invalid response.', 'INVALID_RESPONSE', response.status); }
      if (!data || typeof data !== 'object' || typeof data.status !== 'string') {
        throw new ChatApiError('The report service returned an invalid response.', 'INVALID_RESPONSE', response.status);
      }
      if (response.status === 401) {
        throw new ChatApiError('Reporting requires a signed-in POS account. This session has no reporting access; the POS login must be connected to reporting.', 'UNAUTHORIZED', 401);
      }
      if (response.status === 403) {
        throw new ChatApiError('Your reporting session changed. Refresh this page and try again.', 'CSRF_REJECTED', 403);
      }
      return data;
    } catch (error) {
      if (error?.name === 'AbortError') {
        throw new ChatApiError('The request took too long. Try a shorter date range.', 'CLIENT_TIMEOUT');
      }
      if (error instanceof ChatApiError) throw error;
      throw new ChatApiError('Cannot connect to the reporting API. Start Apache in XAMPP and open http://localhost/test_ai_project/ui/index.html, then refresh this page.');
    } finally {
      clearTimeout(timeout);
    }
  }
}
