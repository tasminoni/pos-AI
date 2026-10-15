import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ChatApi } from '../ui/assets/api.mjs';
import { buildChartConfig } from '../ui/assets/charts.mjs';
import { ReportView } from '../ui/assets/report-view.mjs';
import { chartPoints, columnLabel, compareDecimal, dateRangeLabel, formatDate, formatNumber, formatValue, sortRows, toCsv } from '../ui/assets/report-utils.mjs';

class FakeNode {
  constructor(tag) { this.tag = tag; this.children = []; this.attributes = {}; this.events = {}; this._text = ''; }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
  get childElementCount() { return this.children.length; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this._text = ''; this.children = [...nodes]; }
  setAttribute(name, value) { this.attributes[name] = value; }
  addEventListener(name, fn) { this.events[name] = fn; }
}
globalThis.document = { createElement: tag => new FakeNode(tag) };
let chartConfig = null;
globalThis.window = { Chart: class {
  constructor(canvas, config) { chartConfig = config; this.config = config; }
  destroy() {}
} };

const malicious = '<img src=x onerror=alert(1)>';
const columns = [{ name: 'product', type: 'string' }, { name: 'sales_amount', type: 'decimal' }];
const response = {
  status: 'ok', assistant_text: malicious,
  kpis: [{ id: 'sales_amount', label: malicious, value: '123.45', source: 'verified_sql_aggregate' }],
  table: { columns, rows: [{ product: malicious, sales_amount: '123.45' }], row_count: 1, export_ready: true },
  chart: { type: 'bar', x: 'product', y: 'sales_amount', data: [{ product: malicious, sales_amount: '123.45' }] },
  filters: { tenant_zid: 777, shopnos: ['A'], selected_shopno: 'A' },
  time_range: { start: '2026-10-01', end_exclusive: '2026-10-14', timezone: 'Asia/Dhaka' },
  pagination: { page_size: 1, offset: 0, truncated: true, next_offset: 1 },
  metadata: { report: 'product_sales_amount', group_by: 'product', numeric_source: 'verified_sql' },
};
const view = new ReportView(response);
assert.match(view.root.textContent, /<img src=x onerror=alert\(1\)>/);
assert.equal(chartConfig.data.labels[0], malicious);
assert.equal(chartConfig.options.plugins.tooltip.callbacks.label({ label: malicious, dataIndex: 0 }),
  `${malicious}: 123.45`);
assert.equal(view.rows[0].sales_amount, '123.45');
assert.throws(() => view.appendPage({ ...response, filters: { shopnos: ['OTHER'] } }), /does not match/);
const next = { ...response,
  table: { ...response.table, rows: [{ product: 'SKU-2', sales_amount: '4.00' }], row_count: 1 },
  chart: { ...response.chart, data: [{ product: 'SKU-2', sales_amount: '4.00' }] },
  pagination: { page_size: 1, offset: 1, truncated: false, next_offset: null },
};
view.appendPage(next);
assert.equal(view.rows.length, 2);
assert.equal(view.nextOffset, null);

assert.equal(compareDecimal('9007199254740993.01', '9007199254740992.99'), 1);
assert.deepEqual(sortRows([{ amount: '10.2' }, { amount: '2.99' }], 'amount', 'decimal'),
  [{ amount: '2.99' }, { amount: '10.2' }]);
const csv = toCsv(columns, [{ product: '=SUM(1,2)', sales_amount: '-12.50' },
  { product: 'বাংলা "item"', sales_amount: '2.00' }]);
assert.ok(csv.startsWith('\ufeff'));
assert.match(csv, /"'=SUM\(1,2\)"/);
assert.match(csv, /"-12.50"/);
assert.match(csv, /"বাংলা ""item"""/);
assert.equal(chartPoints({ x: 'product', y: 'sales_amount', data: [{ product: 'X', sales_amount: '9007199254740993' }] }), null);
assert.equal(buildChartConfig({ x: 'product', y: 'sales_amount', data: [{ product: 'X', sales_amount: '-1' }] }, 'pie'), null);
assert.equal(buildChartConfig(response.chart, 'doughnut').type, 'doughnut');
assert.equal(buildChartConfig({ x: 'period', y: 'amount', data: [{ period: '2026-01', amount: '0' }] }, 'pie'), null);
const analysisView = new ReportView({ ...response,
  chart: { ...response.chart, type: 'pie' },
  insights: ['Highest complete period: February'],
  analysis: { period_changes: [{ from: '2026-01', to: '2026-02', percent_change: 25, adjacent_periods: true },
    { from: '2026-02', to: malicious, percent_change: null, adjacent_periods: false }] },
});
assert.equal(analysisView.chartKind, 'pie');
assert.match(analysisView.root.textContent, /Highest complete period: February/);
assert.match(analysisView.root.textContent, /\+25\.00%/);
assert.match(analysisView.root.textContent, /N\/A \(gap\)/);

let calls = 0;
const api = new ChatApi('../api/chat.php', async (url, options) => {
  calls++;
  assert.equal(options.credentials, 'same-origin');
  if (options.method === 'GET') {
    return { status: 200, text: async () => JSON.stringify({ status: 'ready', csrf_token: 'test-csrf', authorized_shopnos: ['A', 'B'], history: [] }) };
  }
  assert.equal(options.headers['X-CSRF-Token'], 'test-csrf');
  const body = JSON.parse(options.body);
  if (calls === 2) {
    assert.deepEqual(body.filters, { shopno: 'B', date_start: '2026-10-01', date_end_inclusive: '2026-10-13' });
    return { status: 200, text: async () => JSON.stringify({ status: 'ok', conversation_id: 'a'.repeat(32) }) };
  }
  assert.equal(body.action, 'page');
  assert.equal(body.conversation_id, 'a'.repeat(32));
  return { status: 200, text: async () => JSON.stringify({ status: 'ok' }) };
});
await api.bootstrap();
await api.ask('Sales this month', null, { shopno: 'B', date_start: '2026-10-01', date_end_inclusive: '2026-10-13' });
await api.page('a'.repeat(32));
assert.equal(calls, 3);

const historyId = 'a'.repeat(32);
const savedTurns = [{ question: 'Show sales', response }];
const historyApi = new ChatApi('/chat', async (url, options) => {
  assert.equal(options.credentials, 'same-origin');
  assert.equal(options.headers['X-CSRF-Token'], 'history-csrf');
  assert.deepEqual(JSON.parse(options.body), { action: 'restore', question: '', conversation_id: historyId });
  return { status: 200, text: async () => JSON.stringify({ status: 'restored', conversation_id: historyId, turns: savedTurns }) };
});
assert.throws(() => historyApi.page(historyId), error => error.code === 'NO_SESSION');
await assert.rejects(() => historyApi.restore(historyId), error => error.code === 'NO_SESSION');
historyApi.csrfToken = 'history-csrf';
assert.deepEqual((await historyApi.restore(historyId)).turns, savedTurns);
const deleteApi = new ChatApi('/chat', async (url, options) => {
  assert.equal(options.credentials, 'same-origin');
  assert.equal(options.headers['X-CSRF-Token'], 'delete-csrf');
  assert.deepEqual(JSON.parse(options.body), { action: 'delete', question: '', conversation_id: historyId });
  return { status: 200, text: async () => JSON.stringify({ status: 'deleted', conversation_id: historyId }) };
});
await assert.rejects(() => deleteApi.deleteConversation(historyId), error => error.code === 'NO_SESSION');
deleteApi.csrfToken = 'delete-csrf';
assert.equal((await deleteApi.deleteConversation(historyId)).status, 'deleted');
const failedDeleteApi = new ChatApi('/chat', async () => ({ status: 503,
  text: async () => JSON.stringify({ status: 'error', assistant_text: 'Try again.', error: { code: 'DELETE_FAILED' } }) }));
failedDeleteApi.csrfToken = 'delete-csrf';
await assert.rejects(() => failedDeleteApi.deleteConversation(historyId), error => error.code === 'DELETE_FAILED');
const mismatchedDeleteApi = new ChatApi('/chat', async () => ({ status: 200,
  text: async () => JSON.stringify({ status: 'deleted', conversation_id: 'b'.repeat(32) }) }));
mismatchedDeleteApi.csrfToken = 'delete-csrf';
await assert.rejects(() => mismatchedDeleteApi.deleteConversation(historyId), error => error.code === 'DELETE_FAILED');
const missingHistoryApi = new ChatApi('/chat', async () => ({ status: 404,
  text: async () => JSON.stringify({ status: 'error', error: { code: 'CONVERSATION_NOT_FOUND' } }) }));
missingHistoryApi.csrfToken = 'history-csrf';
await assert.rejects(() => missingHistoryApi.restore(historyId), error => error.code === 'CONVERSATION_NOT_FOUND');
const mismatchedHistoryApi = new ChatApi('/chat', async () => ({ status: 200,
  text: async () => JSON.stringify({ status: 'restored', conversation_id: 'b'.repeat(32), turns: savedTurns }) }));
mismatchedHistoryApi.csrfToken = 'history-csrf';
await assert.rejects(() => mismatchedHistoryApi.restore(historyId), error => error.code === 'INVALID_HISTORY');

assert.equal(new ChatApi().url, new URL('../api/chat.php', import.meta.url).href);
const receiverApi = new ChatApi('/chat', async function () {
  assert.equal(this, globalThis, 'Fetch must retain its browser global receiver');
  return { status: 200, text: async () => JSON.stringify({ status: 'ready', csrf_token: 'csrf', authorized_shopnos: ['A'] }) };
});
await receiverApi.bootstrap();
const missingApi = new ChatApi('/missing', async () => ({ status: 404, text: async () => '<html>Not found</html>' }));
await assert.rejects(() => missingApi.bootstrap(), error => error.code === 'ENDPOINT_NOT_FOUND');
const offlineApi = new ChatApi('/offline', async () => { throw new TypeError('Failed to fetch'); });
await assert.rejects(() => offlineApi.bootstrap(), error => error.code === 'NETWORK_ERROR' && error.message.includes('Apache'));
const signedOutApi = new ChatApi('/chat', async () => ({ status: 401, text: async () => JSON.stringify({ status: 'error' }) }));
await assert.rejects(() => signedOutApi.bootstrap(), error => error.code === 'UNAUTHORIZED');

const app = readFileSync(new URL('../ui/assets/app.mjs', import.meta.url), 'utf8');
const generatedView = new ReportView({ ...response, metadata: { report: 'generated_sql', title: 'Confirmed net amounts' },
  kpis: [{ id: 'total', label: 'Confirmed net amounts', value: '284425.00', source: 'validated_ai_sql' }] });
assert.ok(generatedView.root.textContent.includes('284,425.00'), 'Readable generated-SQL KPI was not rendered.');
assert.equal(generatedView.response.kpis[0].value, '284425.00', 'Formatting must preserve the source amount.');
assert.equal(columnLabel({ name: 'TotalSales' }), 'Total Sales');
assert.equal(columnLabel({ name: 'xnetamt', label: 'Net amount' }), 'Net amount');
assert.equal(formatDate('2026-08-01 00:00:00', { grain: 'month' }), 'August 2026');
assert.equal(formatDate('2026-10-14 00:00:00'), '14 Oct 2026');
assert.match(formatDate('2026-10-14 14:35:49'), /14 Oct 2026, 2:35 pm/i);
assert.match(formatDate('14:35:49'), /2:35 pm/i);
assert.match(formatDate('2026-10-14T08:35:00Z', { timezone: 'Asia/Dhaka' }), /14 Oct 2026, 2:35 pm/i);
assert.equal(formatDate('2026-02-30'), '2026-02-30', 'Invalid dates must not be silently shifted.');
assert.equal(formatNumber('9007199254740993.01'), '9,007,199,254,740,993.01');
assert.equal(formatValue('2026', { name: 'year', type: 'integer' }), '2026');
assert.equal(formatValue('000123456', { name: 'invoice_no', type: 'integer' }), '000123456');
assert.equal(formatValue(null, { name: 'amount', type: 'decimal' }), '—');
assert.equal(formatValue('0.00', { name: 'amount', type: 'decimal' }), '0.00');
assert.equal(dateRangeLabel({ start: '2026-04-01', end_exclusive: '2026-05-01' }), '1 Apr 2026 – 30 Apr 2026');
assert.equal(dateRangeLabel({ all_authorized_history: true }), 'All available dates');
const readableColumns = [{ name: 'month', label: 'Month', type: 'date' }, { name: 'TotalSales', label: 'Total sales', type: 'decimal' }];
const readableRows = [{ month: '2026-08-01 00:00:00', TotalSales: '417840.00' }];
const readableResponse = { ...response, table: { columns: readableColumns, rows: readableRows, row_count: 1 },
  chart: { type: 'bar', x: 'month', y: 'TotalSales', data: readableRows },
  metadata: { report: 'generated_sql', title: 'Monthly sales', presentation: { x: 'month', y: 'TotalSales', period_grain: 'month' } },
  analysis: { highest_period: { period: '2026-08-01 00:00:00', value: '417840.00', tied_periods: [] },
    latest_change: { from: '2026-08-01', to: '2026-09-01', percent_change: -31.93, direction: 'decreasing' } },
};
const readableView = new ReportView(readableResponse);
assert.match(readableView.root.textContent, /August 2026/);
assert.match(readableView.root.textContent, /417,840\.00/);
assert.match(readableView.root.textContent, /Highest month/);
assert.match(readableView.root.textContent, /-31\.93%/);
assert.equal(readableView.root.textContent.includes('2026-08-01 00:00:00'), false);
assert.equal(readableView.root.textContent.includes('TotalSales'), false);
assert.deepEqual(chartConfig.data.labels, ['August 2026']);
assert.equal(chartConfig.data.datasets[0].label, 'Total sales');
assert.match(chartConfig.options.plugins.tooltip.callbacks.label({ label: 'August 2026', dataIndex: 0 }), /417,840\.00/);
assert.match(toCsv(readableColumns.map(column => ({ ...column, display_grain: 'month' })), readableRows), /August 2026/);
assert.match(toCsv(readableColumns, readableRows), /"Total sales"/);
const report = readFileSync(new URL('../ui/assets/report-view.mjs', import.meta.url), 'utf8');
const html = readFileSync(new URL('../ui/index.html', import.meta.url), 'utf8');
const chartBundle = readFileSync(new URL('../ui/assets/vendor/chart.umd.min.js', import.meta.url));
assert.equal(/\binnerHTML\b|\beval\s*\(/.test(app + report), false);
assert.equal(/\son[a-z]+\s*=/.test(html), false);
assert.match(html, /Content-Security-Policy/);
assert.match(html, /assets\/vendor\/chart\.umd\.min\.js/);
assert.ok(chartBundle.length > 100000);

console.log('UI tests passed.');
