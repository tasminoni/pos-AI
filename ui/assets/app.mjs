import { ChatApi, ChatApiError } from './api.mjs?v=20261014-history';
import { ReportView } from './report-view.mjs?v=20261014-readable';
import { dateRangeLabel, formatDate, reportTitle, text } from './report-utils.mjs?v=20261014-readable';

const byId = id => document.getElementById(id);
const dom = {
  form: byId('chat-form'), question: byId('question'), send: byId('send-button'),
  messages: byId('messages'), conversation: byId('conversation'), welcome: byId('welcome'),
  history: byId('history-list'), newChat: byId('new-chat'),
  shop: byId('shop-filter'), start: byId('date-start'), end: byId('date-end'),
  clearDates: byId('clear-dates'), activeFilters: byId('active-filters'),
  sidebar: byId('sidebar'), filtersPanel: byId('filters-panel'), overlay: byId('mobile-overlay'),
  menuToggle: byId('menu-toggle'), filterToggle: byId('filter-toggle'), panelClose: byId('panel-close'),
  live: byId('live-status'), count: byId('question-count'),
};
const api = new ChatApi();
const state = { conversationId: null, busy: false, currentReport: null, history: [], authenticated: false, views: [] };

function clearMessages() {
  for (const view of state.views) view.destroy();
  state.views = [];
  state.currentReport = null;
  dom.messages.replaceChildren();
}

function node(tag, className = '', value = null) {
  const item = document.createElement(tag);
  if (className) item.className = className;
  if (value !== null) item.textContent = text(value);
  return item;
}

function status(message) { dom.live.textContent = message; }
function scrollDown() { requestAnimationFrame(() => dom.conversation.scrollTo({ top: dom.conversation.scrollHeight, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' })); }
function resizeComposer() {
  dom.question.style.height = 'auto';
  dom.question.style.height = `${Math.min(dom.question.scrollHeight, 160)}px`;
  dom.count.textContent = `${dom.question.value.length} / 1200`;
  dom.send.disabled = state.busy || !state.authenticated || !dom.question.value.trim();
}
function setBusy(busy) {
  state.busy = busy;
  dom.send.disabled = busy || !state.authenticated || !dom.question.value.trim();
  dom.newChat.disabled = busy;
  for (const item of dom.history.querySelectorAll('button')) item.disabled = busy;
  dom.question.disabled = busy || !state.authenticated;
  dom.form.setAttribute('aria-busy', busy ? 'true' : 'false');
}

function message(role, content = '') {
  const article = node('article', `message ${role}`);
  const avatar = node('div', 'avatar', role === 'user' ? 'U' : '✦');
  avatar.setAttribute('aria-hidden', 'true');
  const stack = node('div', 'message-stack');
  if (role !== 'user') stack.append(node('span', 'assistant-label', 'Assistant'));
  const bubble = node('div', 'bubble', content);
  stack.append(bubble);
  if (role === 'user') article.append(stack, avatar);
  else article.append(avatar, stack);
  dom.messages.append(article);
  dom.welcome.hidden = true;
  scrollDown();
  return { article, stack, bubble };
}

function loadingMessage() {
  const entry = message('assistant');
  entry.article.classList.add('loading');
  const progress = node('div', 'progress');
  const spinner = node('span', 'spinner');
  spinner.setAttribute('aria-hidden', 'true');
  const label = node('span', '', 'Preparing your report…');
  progress.append(spinner, label);
  entry.bubble.replaceChildren(progress);
  const started = Date.now();
  const ticker = setInterval(() => {
    const seconds = Math.floor((Date.now() - started) / 1000);
    label.textContent = seconds < 15 ? `Preparing your results · ${seconds}s`
      : `Still preparing your report · ${seconds}s`;
  }, 1000);
  return { ...entry, stop: () => clearInterval(ticker) };
}

function showState(entry, response, restored = false) {
  const type = ['ok', 'no_data', 'clarification', 'error'].includes(response.status) ? response.status : 'error';
  entry.article.classList.remove('loading');
  entry.article.classList.add(type);
  entry.bubble.textContent = text(response.assistant_text || 'The report could not be completed.');
  if (type === 'ok' && Array.isArray(response.insights) && response.insights.length) {
    entry.bubble.textContent = response.metadata?.presentation?.language === 'bn' ? 'আপনার রিপোর্ট তৈরি। নিচে বিস্তারিত দেখুন।' : 'Your report is ready. See the summary and details below.';
  }
  if (type === 'ok' || type === 'no_data') {
    const view = new ReportView(response, { onMore: restored ? null : loadMore, onPrint: printReport });
    state.views.push(view);
    entry.stack.append(view.root);
    state.currentReport = view;
    status(type === 'ok' ? 'Report ready.' : 'No data for this report.');
  } else {
    const detail = type === 'clarification' ? 'Please refine your question and send it again.'
      : response.error?.code === 'QUERY_TIMEOUT' ? 'The query timed out. Try a shorter date range.'
        : 'No report data was shown. You can retry or change your filters.';
    entry.stack.append(node('div', `state-card ${type === 'error' ? 'error' : ''}`, detail));
    status(entry.bubble.textContent);
  }
  scrollDown();
}

function selectedFilters() {
  const start = dom.start.value, end = dom.end.value;
  if ((start && !end) || (!start && end)) throw new Error('Select both dates, or clear both date fields.');
  if (start && end && start > end) throw new Error('The end date must be on or after the start date.');
  const filters = { shopno: dom.shop.value || null };
  if (start && end) {
    filters.date_start = start;
    filters.date_end_inclusive = end;
  }
  return filters;
}

function renderFilterChips() {
  dom.activeFilters.replaceChildren();
  if (dom.shop.value) dom.activeFilters.append(node('span', 'filter-chip', `Shop: ${dom.shop.value}`));
  if (dom.start.value && dom.end.value) {
    dom.activeFilters.append(node('span', 'filter-chip', `${dom.start.value} – ${dom.end.value}`));
  }
}

function renderHistory() {
  dom.history.replaceChildren();
  if (!state.history.length) {
    dom.history.append(node('p', 'sidebar-muted', 'Your report history will appear here.'));
    return;
  }
  for (const entry of state.history.slice(0, 8)) {
    if (typeof entry.conversation_id !== 'string') continue;
    const last = Array.isArray(entry.turns) ? entry.turns.at(-1) : null;
    const title = last?.title || reportTitle(last?.report, last?.group_by);
    const item = node('button', `history-item ${entry.conversation_id === state.conversationId ? 'active' : ''}`);
    item.type = 'button';
    item.disabled = state.busy;
    item.append(node('span', 'history-title', title),
      node('span', 'history-meta', last?.date_start ? `${last.date_start} → ${last.date_end_exclusive || ''}` : 'Continue conversation'));
    item.addEventListener('click', () => restoreConversation(entry.conversation_id));
    dom.history.append(item);
  }
}

async function restoreConversation(id) {
  if (state.busy || !state.authenticated) return;
  setBusy(true);
  closePanels();
  status('Loading saved conversation.');
  // Keep the current conversation visible until the restore request succeeds.
  try {
    const saved = await api.restore(id);
    clearMessages();
    state.conversationId = id;
    dom.welcome.hidden = true;
    for (const turn of saved.turns) {
      if (turn.question) message('user', turn.question);
      if (turn.response) {
        const entry = message('assistant');
        showState(entry, turn.response, true);
        if (turn.response.pagination?.truncated) {
          entry.stack.append(node('div', 'state-card', 'Showing the saved first page. Ask a follow-up question for more results.'));
        }
      } else {
        const entry = message('assistant', turn.title || 'Previous report');
        entry.stack.append(node('div', 'state-card', 'The results for this older report were not saved or are no longer retained. Ask a follow-up question to generate a new report in this conversation.'));
      }
    }
    if (!saved.turns.length) message('assistant', 'This conversation has no saved reports. Ask a question to continue.');
    renderHistory();
    status('Saved conversation loaded.');
    scrollDown();
  } catch (error) {
    dom.messages.append(node('div', 'state-card error', error.message || 'The conversation could not be loaded. Please retry.'));
    status('The conversation could not be loaded.');
    scrollDown();
  } finally {
    setBusy(false);
    dom.question.focus();
  }
}

function remember(response) {
  const id = response.conversation_id;
  if (typeof id !== 'string' || !/^[a-f0-9]{32}$/.test(id)) return;
  state.conversationId = id;
  const turn = {
    report: response.metadata?.report ?? null, group_by: response.metadata?.group_by ?? null,
    date_start: response.time_range?.start ?? null, date_end_exclusive: response.time_range?.end_exclusive ?? null,
    status: response.status, title: response.metadata?.title || null,
  };
  const existing = state.history.find(item => item.conversation_id === id);
  if (existing) {
    existing.turns = [...(existing.turns || []), turn].slice(-12);
    state.history = [existing, ...state.history.filter(item => item !== existing)];
  } else state.history = [{ conversation_id: id, turns: [turn] }, ...state.history].slice(0, 8);
  renderHistory();
}

async function submitQuestion(event) {
  event.preventDefault();
  if (state.busy || !state.authenticated) return;
  const question = dom.question.value.trim();
  if (!question) return;
  let filters;
  try { filters = selectedFilters(); }
  catch (error) { status(error.message); dom.activeFilters.replaceChildren(node('span', 'filter-chip', error.message)); return; }
  if (state.currentReport) {
    state.currentReport.onMore = null;
    state.currentReport.render();
    state.currentReport = null;
  }
  message('user', question);
  const pending = loadingMessage();
  dom.question.value = '';
  resizeComposer();
  setBusy(true);
  try {
    const response = await api.ask(question, state.conversationId, filters);
    pending.stop();
    showState(pending, response);
    remember(response);
  } catch (error) {
    pending.stop();
    showState(pending, { status: 'error', assistant_text: error instanceof ChatApiError ? error.message
      : 'The report could not be loaded. Please retry.', error: { code: error.code || 'NETWORK_ERROR' } });
  } finally {
    setBusy(false);
    dom.question.focus();
  }
}

async function loadMore(view) {
  if (state.busy || view !== state.currentReport || !state.conversationId || view.nextOffset === null) return;
  setBusy(true);
  view.setLoading(true);
  status('Loading the next report page.');
  try {
    const next = await api.page(state.conversationId);
    if (next.status === 'error') throw new Error(next.assistant_text || 'The next page could not be loaded.');
    view.appendPage(next);
    status('Additional report rows loaded.');
  } catch (error) {
    view.setError(error instanceof ChatApiError ? error.message : error.message || 'The next page could not be loaded.');
    status('The next report page could not be loaded.');
  } finally {
    setBusy(false);
  }
}

function printReport(root) {
  const messageNode = root.closest('.message');
  if (!messageNode) return;
  messageNode.classList.add('print-current');
  const cleanup = () => messageNode.classList.remove('print-current');
  window.addEventListener('afterprint', cleanup, { once: true });
  window.print();
  setTimeout(cleanup, 5000);
}

function openPanel(which) {
  const sidebar = which === 'sidebar';
  dom.sidebar.classList.toggle('open', sidebar);
  dom.filtersPanel.classList.toggle('open', !sidebar);
  dom.overlay.hidden = false;
  dom.overlay.classList.add('visible');
  dom.menuToggle.setAttribute('aria-expanded', sidebar ? 'true' : 'false');
  dom.filterToggle.setAttribute('aria-expanded', sidebar ? 'false' : 'true');
}
function closePanels() {
  dom.sidebar.classList.remove('open');
  dom.filtersPanel.classList.remove('open');
  dom.overlay.classList.remove('visible');
  dom.overlay.hidden = true;
  dom.menuToggle.setAttribute('aria-expanded', 'false');
  dom.filterToggle.setAttribute('aria-expanded', 'false');
}

dom.form.addEventListener('submit', submitQuestion);
dom.question.addEventListener('keydown', event => {
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    event.preventDefault();
    dom.form.requestSubmit();
  }
});
dom.question.addEventListener('input', resizeComposer);
window.addEventListener('resize', () => { closePanels(); resizeComposer(); });
dom.newChat.addEventListener('click', () => {
  if (state.busy) return;
  clearMessages();
  state.conversationId = null;
  dom.welcome.hidden = false;
  dom.question.value = '';
  resizeComposer();
  renderHistory();
  closePanels();
  dom.question.focus();
});
for (const prompt of document.querySelectorAll('[data-prompt]')) {
  prompt.addEventListener('click', () => { dom.question.value = prompt.dataset.prompt || ''; resizeComposer(); dom.question.focus(); });
}
for (const control of [dom.shop, dom.start, dom.end]) control.addEventListener('change', renderFilterChips);
dom.clearDates.addEventListener('click', () => { dom.start.value = ''; dom.end.value = ''; renderFilterChips(); });
dom.menuToggle.addEventListener('click', () => dom.sidebar.classList.contains('open') ? closePanels() : openPanel('sidebar'));
dom.filterToggle.addEventListener('click', () => dom.filtersPanel.classList.contains('open') ? closePanels() : openPanel('filters'));
dom.panelClose.addEventListener('click', closePanels);
dom.overlay.addEventListener('click', closePanels);
document.addEventListener('keydown', event => { if (event.key === 'Escape') closePanels(); });

setBusy(true);
try {
  const session = await api.bootstrap();
  for (const shop of session.authorized_shopnos) {
    if (typeof shop !== 'string') continue;
    const option = node('option', '', shop);
    option.value = shop;
    dom.shop.append(option);
  }
  dom.shop.disabled = !session.authorized_shopnos.length;
  if (!session.authorized_shopnos.length) dom.shop.closest('label')?.setAttribute('hidden', '');
  state.history = Array.isArray(session.history) ? session.history : [];
  state.authenticated = true;
  renderHistory();
  status('Reporting session ready.');
} catch (error) {
  state.authenticated = false;
  dom.messages.append(node('div', 'state-card error', error instanceof ChatApiError ? error.message
    : 'Reporting is unavailable. Refresh after signing in.'));
  status('Reporting session unavailable.');
} finally {
  setBusy(false);
}
