import { ChatApi, ChatApiError } from './api.mjs?v=20261015-delete';
import { ReportView } from './report-view.mjs?v=20261014-readable';
import { dateRangeLabel, formatDate, reportTitle, text } from './report-utils.mjs?v=20261014-readable';
import { questionBytes, nearBottom } from './chat-behavior.mjs';

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
  search: byId('history-search'), jump: byId('jump-latest'), toast: byId('toast'),
  composerFilter: byId('composer-filter'), limit: byId('input-limit'),
};
const api = new ChatApi();
const state = { conversationId: null, busy: false, currentReport: null, history: [], authenticated: false, views: [] };
let followLatest = true;
let toastTimer;
let panelTrigger = null;

function toast(message) {
  clearTimeout(toastTimer);
  dom.toast.textContent = message;
  dom.toast.hidden = false;
  toastTimer = setTimeout(() => { dom.toast.hidden = true; }, 3500);
}

function messageActions(entry, content, user = false) {
  const actions = node('div', 'message-actions');
  const copy = node('button', 'message-action', 'Copy');
  copy.type = 'button';
  copy.setAttribute('aria-label', user ? 'Copy question' : 'Copy response');
  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(content);
      toast('Copied to clipboard');
    } catch {
      const range = document.createRange();
      range.selectNodeContents(entry.bubble);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      toast('Text selected. Use your device’s copy command.');
    }
  });
  actions.append(copy);
  if (user) {
    const edit = node('button', 'message-action', 'Edit & resend');
    edit.type = 'button';
    edit.addEventListener('click', () => {
      if (state.busy) return toast('Wait for the current request to finish.');
      dom.question.value = content;
      resizeComposer();
      dom.question.focus();
    });
    actions.append(edit);
  }
  entry.stack.append(actions);
}

function clearMessages() {
  for (const view of state.views) view.destroy();
  state.views = [];
  state.currentReport = null;
  dom.messages.replaceChildren();
  followLatest = true;
  dom.jump.hidden = true;
}

function node(tag, className = '', value = null) {
  const item = document.createElement(tag);
  if (className) item.className = className;
  if (value !== null) item.textContent = text(value);
  return item;
}

function status(message) { dom.live.textContent = message; }
function scrollDown(force = false) {
  if (force) followLatest = true;
  if (!followLatest) { dom.jump.hidden = false; return; }
  requestAnimationFrame(() => {
    dom.conversation.scrollTo({ top: dom.conversation.scrollHeight, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
  });
}
function resizeComposer() {
  dom.question.style.height = 'auto';
  dom.question.style.height = `${Math.min(dom.question.scrollHeight, 160)}px`;
  const bytes = questionBytes(dom.question.value);
  dom.count.textContent = bytes > 900 ? `${bytes} / 1200` : '';
  dom.limit.hidden = bytes <= 1200;
  dom.question.setAttribute('aria-invalid', bytes > 1200 ? 'true' : 'false');
  dom.send.disabled = state.busy || !state.authenticated || !dom.question.value.trim() || bytes > 1200;
}
function setBusy(busy) {
  state.busy = busy;
  dom.send.disabled = busy || !state.authenticated || !dom.question.value.trim() || questionBytes(dom.question.value) > 1200;
  dom.newChat.disabled = busy;
  for (const item of dom.history.querySelectorAll('button')) item.disabled = busy;
  dom.question.disabled = !state.authenticated;
  dom.composerFilter.disabled = busy;
  for (const control of [dom.shop, dom.start, dom.end, dom.clearDates]) {
    control.disabled = busy || !state.authenticated || (control === dom.shop && dom.shop.options.length <= 1);
  }
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
  const entry = { article, stack, bubble };
  if (role === 'user') messageActions(entry, content, true);
  return entry;
}

function loadingMessage() {
  const entry = message('assistant');
  entry.article.classList.add('loading');
  const progress = node('div', 'progress');
  const spinner = node('span', 'thinking-dots');
  spinner.setAttribute('aria-hidden', 'true');
  spinner.append(node('i'), node('i'), node('i'));
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

function showState(entry, response, restored = false, retryQuestion = null) {
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
    const providerErrors = ['AI_TIMEOUT', 'AI_CONNECTION_FAILED', 'AI_TEMPORARILY_UNAVAILABLE', 'AI_RATE_LIMITED', 'AI_ACCESS_REJECTED'];
    const providerIssue = providerErrors.includes(response.error?.code);
    const bangla = retryQuestion && /[\u0980-\u09ff]/u.test(retryQuestion);
    const providerDetails = {
      AI_TIMEOUT: ['AI সার্ভিস থেকে সময়মতো উত্তর পাওয়া যায়নি। এটি প্রশ্নের ভাষার কারণে নয়। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'The AI service did not respond in time. This is not a question-language error. Please try again shortly.'],
      AI_CONNECTION_FAILED: ['সার্ভার থেকে AI সার্ভিসে সংযোগ করা যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'The server could not connect to the AI service. Please try again shortly.'],
      AI_RATE_LIMITED: ['AI সার্ভিসের ব্যবহারসীমা পূর্ণ হয়েছে। সীমা পুনরায় উপলব্ধ হলে চেষ্টা করুন।', 'The AI service has reached its usage limit. Retry after the provider limit resets.'],
      AI_ACCESS_REJECTED: ['AI সার্ভিসের অনুমোদনে সমস্যা হয়েছে। প্রশাসককে API সেটিংস পরীক্ষা করতে হবে।', 'An administrator needs to check the AI service credentials and permissions.'],
    };
    const detail = providerIssue
      ? (providerDetails[response.error?.code]?.[bangla ? 0 : 1] || (bangla ? 'AI সার্ভিস এখন ব্যস্ত। কিছুক্ষণ পর আবার চেষ্টা করুন।'
        : 'The AI service is temporarily unavailable. Please try again shortly.'))
      : type === 'clarification' ? 'Please refine your question and send it again.'
      : response.error?.code === 'QUERY_TIMEOUT' ? 'The query timed out. Try a shorter date range.'
        : 'No report data was shown. You can retry or change your filters.';
    entry.stack.append(node('div', `state-card ${type === 'error' ? 'error' : ''}`, detail));
    status(entry.bubble.textContent);
    const retryable = !['AI_ACCESS_REJECTED', 'AI_RATE_LIMITED'].includes(response.error?.code);
    if (type === 'error' && retryQuestion && retryable) {
      const retry = node('button', 'message-action retry-question', bangla ? 'আবার চেষ্টা করুন' : 'Try again');
      retry.type = 'button';
      retry.addEventListener('click', () => {
        if (state.busy) return;
        if (dom.question.value.trim() && dom.question.value.trim() !== retryQuestion) {
          toast('Your next question is already drafted. Send it or clear it before retrying.');
          dom.question.focus();
          return;
        }
        dom.question.value = retryQuestion;
        resizeComposer();
        dom.form.requestSubmit();
      });
      entry.stack.append(retry);
    }
  }
  messageActions(entry, entry.bubble.textContent);
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
  const search = dom.search.value.trim().toLocaleLowerCase();
  const entries = state.history.slice(0, 8).filter(entry => {
    const last = entry.turns?.at(-1);
    return `${last?.title || reportTitle(last?.report, last?.group_by)} ${last?.date_start || ''}`.toLocaleLowerCase().includes(search);
  });
  if (!entries.length) dom.history.append(node('p', 'sidebar-muted', 'No matching conversations.'));
  for (const entry of entries) {
    if (typeof entry.conversation_id !== 'string') continue;
    const last = Array.isArray(entry.turns) ? entry.turns.at(-1) : null;
    const title = last?.title || reportTitle(last?.report, last?.group_by);
    const item = node('button', `history-item ${entry.conversation_id === state.conversationId ? 'active' : ''}`);
    item.type = 'button';
    item.disabled = state.busy;
    item.append(node('span', 'history-title', title),
      node('span', 'history-meta', last?.date_start ? `${last.date_start} → ${last.date_end_exclusive || ''}` : 'Continue conversation'));
    item.addEventListener('click', () => restoreConversation(entry.conversation_id));
    const row = node('div', 'history-row');
    const remove = node('button', 'history-delete', '×');
    remove.type = 'button';
    remove.disabled = state.busy;
    remove.setAttribute('aria-label', `Delete report: ${title}`);
    remove.title = 'Delete report';
    remove.addEventListener('click', () => deleteConversation(entry.conversation_id));
    row.append(item, remove);
    dom.history.append(row);
  }
}

async function deleteConversation(id) {
  if (state.busy || !state.authenticated) return;
  setBusy(true);
  try {
    await api.deleteConversation(id);
    state.history = state.history.filter(entry => entry.conversation_id !== id);
    if (state.conversationId === id) {
      clearMessages();
      state.conversationId = null;
      dom.welcome.hidden = false;
    }
    renderHistory();
    status('Report deleted from recent reports.');
    toast('Conversation deleted');
  } catch (error) {
    const notice = node('div', 'history-error', error.message || 'The report could not be deleted. Please retry.');
    notice.setAttribute('role', 'alert');
    dom.history.append(notice);
    status('The report could not be deleted.');
  } finally {
    setBusy(false);
    const next = dom.history.querySelector('button');
    (next || dom.newChat).focus();
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
        showState(entry, turn.response, true, turn.question);
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
  if (questionBytes(question) > 1200) { resizeComposer(); dom.question.focus(); return; }
  let filters;
  try { filters = selectedFilters(); }
  catch (error) { status(error.message); dom.activeFilters.replaceChildren(node('span', 'filter-chip', error.message)); return; }
  if (state.currentReport) {
    state.currentReport.onMore = null;
    state.currentReport.render();
    state.currentReport = null;
  }
  followLatest = true;
  message('user', question);
  const pending = loadingMessage();
  dom.question.value = '';
  resizeComposer();
  setBusy(true);
  try {
    const response = await api.ask(question, state.conversationId, filters);
    pending.stop();
    showState(pending, response, false, question);
    remember(response);
  } catch (error) {
    pending.stop();
    showState(pending, { status: 'error', assistant_text: error instanceof ChatApiError ? error.message
      : 'The report could not be loaded. Please retry.', error: { code: error.code || 'NETWORK_ERROR' } }, false, question);
  } finally {
    setBusy(false);
    if (window.matchMedia('(pointer: fine)').matches) dom.question.focus();
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
  panelTrigger = document.activeElement;
  dom.sidebar.classList.toggle('open', sidebar);
  dom.filtersPanel.classList.toggle('open', !sidebar);
  dom.filtersPanel.inert = sidebar;
  dom.sidebar.inert = window.matchMedia('(max-width: 860px)').matches && !sidebar;
  dom.overlay.hidden = false;
  dom.overlay.classList.add('visible');
  dom.menuToggle.setAttribute('aria-expanded', sidebar || (!window.matchMedia('(max-width: 860px)').matches && !dom.sidebar.parentElement.classList.contains('sidebar-collapsed')) ? 'true' : 'false');
  dom.filterToggle.setAttribute('aria-expanded', sidebar ? 'false' : 'true');
  dom.composerFilter.setAttribute('aria-expanded', sidebar ? 'false' : 'true');
  requestAnimationFrame(() => (sidebar ? dom.search : dom.panelClose).focus());
}
function closePanels(restoreFocus = false) {
  dom.sidebar.classList.remove('open');
  dom.filtersPanel.classList.remove('open');
  dom.filtersPanel.inert = true;
  dom.sidebar.inert = window.matchMedia('(max-width: 860px)').matches;
  dom.overlay.classList.remove('visible');
  dom.overlay.hidden = true;
  dom.menuToggle.setAttribute('aria-expanded', !window.matchMedia('(max-width: 860px)').matches && !dom.sidebar.parentElement.classList.contains('sidebar-collapsed') ? 'true' : 'false');
  dom.filterToggle.setAttribute('aria-expanded', 'false');
  dom.composerFilter.setAttribute('aria-expanded', 'false');
  if (restoreFocus && panelTrigger) panelTrigger.focus();
  panelTrigger = null;
}

dom.form.addEventListener('submit', submitQuestion);
dom.question.addEventListener('keydown', event => {
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    event.preventDefault();
    dom.form.requestSubmit();
  }
});
dom.question.addEventListener('input', resizeComposer);
let lastViewportWidth = window.innerWidth;
window.addEventListener('resize', () => {
  if (window.innerWidth !== lastViewportWidth) { closePanels(); lastViewportWidth = window.innerWidth; }
  resizeComposer();
});
dom.search.addEventListener('input', renderHistory);
dom.jump.addEventListener('click', () => scrollDown(true));
dom.conversation.addEventListener('scroll', () => {
  followLatest = nearBottom(dom.conversation);
  dom.jump.hidden = followLatest || !dom.messages.children.length;
}, { passive: true });
if ('ResizeObserver' in window) {
  new ResizeObserver(() => {
    if (followLatest) {
      dom.conversation.scrollTop = dom.conversation.scrollHeight;
    } else dom.jump.hidden = false;
  }).observe(dom.messages);
}
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
dom.menuToggle.addEventListener('click', () => {
  if (window.matchMedia('(max-width: 860px)').matches) {
    dom.sidebar.classList.contains('open') ? closePanels(true) : openPanel('sidebar');
  } else {
    const collapsed = dom.sidebar.parentElement.classList.toggle('sidebar-collapsed');
    dom.menuToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  }
});
dom.filterToggle.addEventListener('click', () => dom.filtersPanel.classList.contains('open') ? closePanels() : openPanel('filters'));
dom.composerFilter.addEventListener('click', () => openPanel('filters'));
dom.panelClose.addEventListener('click', () => closePanels(true));
dom.overlay.addEventListener('click', () => closePanels(true));
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') closePanels(true);
  if (event.key === 'Tab' && !dom.overlay.hidden) {
    const panel = dom.filtersPanel.classList.contains('open') ? dom.filtersPanel : dom.sidebar;
    const controls = [...panel.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled)')].filter(item => item.getClientRects().length);
    const first = controls[0], last = controls.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  }
});
closePanels();

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
