import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { questionBytes, nearBottom } from '../ui/assets/chat-behavior.mjs';

class Element {
  constructor(tag = 'div') {
    this.tag = tag; this.children = []; this.events = {}; this.attributes = {}; this.style = {};
    this.value = ''; this.textContent = ''; this.hidden = false; this.disabled = false;
    this.scrollHeight = 500; this.clientHeight = 500; this.scrollTop = 0;
    const classes = new Set();
    this.classList = { contains: key => classes.has(key), add: key => classes.add(key),
      remove: key => classes.delete(key), toggle(key, enabled = !classes.has(key)) { enabled ? classes.add(key) : classes.delete(key); return enabled; } };
  }
  append(...items) { items.forEach(item => { item.parentElement = this; this.children.push(item); }); }
  replaceChildren(...items) { this.children = []; this.append(...items); }
  setAttribute(key, value) { this.attributes[key] = value; }
  addEventListener(key, fn) { this.events[key] = fn; }
  focus() { document.activeElement = this; }
  get options() { return this.children; }
  querySelectorAll(selector) { return this.children.flatMap(child => [...(child.tag === 'button' ? [child] : []), ...child.querySelectorAll(selector)]); }
  querySelector(selector) { return this.querySelectorAll(selector)[0]; }
  closest() { return null; }
  scrollTo({ top }) { this.scrollTop = top; }
}
const nodes = new Map();
const document = { activeElement: null, getElementById(id) {
  if (!nodes.has(id)) nodes.set(id, new Element());
  return nodes.get(id);
}, createElement: tag => new Element(tag), querySelectorAll: () => [], addEventListener() {} };
const shell = new Element();
shell.append(document.getElementById('sidebar'));
document.getElementById('shop-filter').append(new Element('option'));
const id = 'a'.repeat(32);
const asks = [];
class ChatApi {
  async bootstrap() { return { authorized_shopnos: ['Shop A'], history: [{ conversation_id: id, turns: [{ title: 'Monthly sales' }] }] }; }
  async ask(question) { asks.push(question); return { status: 'error', assistant_text: 'The AI provider took too long.', error: { code: 'AI_TIMEOUT' }, conversation_id: id }; }
  async deleteConversation() {}
}
class ChatApiError extends Error {}
const context = vm.createContext({ document, TextEncoder, questionBytes, nearBottom, ChatApi, ChatApiError,
  ReportView: class {}, text: value => String(value ?? ''), reportTitle: () => 'Report',
  requestAnimationFrame: fn => fn(), setTimeout: () => 1, clearTimeout() {}, setInterval: () => 1, clearInterval() {},
  navigator: { clipboard: { async writeText() {} } },
  window: { innerWidth: 1200, addEventListener() {}, matchMedia: query => ({ matches: !query.includes('max-width') && !query.includes('reduced-motion') }) } });
const source = readFileSync(new URL('../ui/assets/app.mjs', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');
await vm.runInContext(`(async () => { ${source} })()`, context);
const get = id => document.getElementById(id);
assert.equal(get('question').disabled, false, 'Authenticated composer should be available.');
assert.equal(get('send-button').disabled, true, 'Empty questions should not send.');
get('question').value = 'ক'.repeat(401);
get('question').events.input();
assert.equal(get('send-button').disabled, true, 'Bangla byte limit must match the PHP API.');
assert.equal(get('input-limit').hidden, false);
get('question').value = 'Show sales';
get('question').events.input();
assert.equal(get('send-button').disabled, false);
const pending = get('chat-form').events.submit({ preventDefault() {} });
assert.equal(get('question').disabled, false, 'Users should be able to draft while a request runs.');
get('question').value = 'Next question';
get('question').events.input();
assert.equal(get('send-button').disabled, true, 'Concurrent sends must be blocked.');
await get('chat-form').events.submit({ preventDefault() {} });
await pending;
assert.deepEqual(asks, ['Show sales']);
assert.equal(get('messages').children.length, 2, 'Question and response should render.');
assert.equal(get('question').value, 'Next question', 'The next draft must survive the response.');
assert.equal(get('send-button').disabled, false, 'The next draft should be ready to send.');
const assistantStack = get('messages').children[1].children[1];
assert.match(assistantStack.children[2].textContent, /AI service did not respond in time/);
const retry = assistantStack.children.find(item => item.className?.includes('retry-question'));
assert.ok(retry, 'Backend AI timeout responses should offer a retry button.');
retry.events.click();
assert.equal(get('question').value, 'Next question', 'Retry must not overwrite a different pending draft.');
const userActions = get('messages').children[0].children[0].children[1];
userActions.children[1].events.click();
assert.equal(get('question').value, 'Show sales', 'Edit should refill the composer.');
get('history-search').value = 'unmatched';
get('history-search').events.input();
assert.equal(get('history-list').children[0].textContent, 'No matching conversations.');
get('menu-toggle').events.click();
assert.equal(shell.classList.contains('sidebar-collapsed'), true);
get('conversation').scrollHeight = 2000;
get('conversation').events.scroll();
assert.equal(get('jump-latest').hidden, false, 'Reading older messages should expose the jump button.');
get('jump-latest').events.click();
assert.equal(get('conversation').scrollTop, 2000);
get('new-chat').events.click();
assert.equal(get('messages').children.length, 0);
assert.equal(get('welcome').hidden, false);
assert.equal(questionBytes('বাংলা'), 15);
assert.equal(nearBottom({ scrollHeight: 1000, scrollTop: 600, clientHeight: 400 }), true);
assert.equal(nearBottom({ scrollHeight: 1000, scrollTop: 0, clientHeight: 400 }), false);
console.log('Chat interaction tests passed.');
