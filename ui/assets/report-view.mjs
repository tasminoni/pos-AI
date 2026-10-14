import { drawChart } from './charts.mjs';
import { dateRangeLabel, reportTitle, sortRows, text, toCsv } from './report-utils.mjs';

function element(tag, className = '', content = null) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (content !== null) node.textContent = text(content);
  return node;
}

function button(label, className, callback) {
  const node = element('button', className, label);
  node.type = 'button';
  node.addEventListener('click', callback);
  return node;
}

export class ReportView {
  constructor(response, { onMore, onPrint } = {}) {
    this.response = response;
    this.rows = Array.isArray(response.table?.rows) ? [...response.table.rows] : [];
    this.columns = Array.isArray(response.table?.columns) ? response.table.columns : [];
    this.chartData = Array.isArray(response.chart?.data) ? [...response.chart.data] : [];
    this.nextOffset = response.pagination?.next_offset ?? null;
    this.chartKind = ['bar', 'line', 'pie', 'doughnut'].includes(response.chart?.type) ? response.chart.type : 'bar';
    this.sortColumn = null;
    this.sortDirection = 'asc';
    this.tablePage = 0;
    this.pageSize = 10;
    this.onMore = onMore;
    this.onPrint = onPrint;
    this.chartInstance = null;
    this.busy = false;
    this.error = '';
    this.root = element('section', 'report-card');
    this.root.setAttribute('aria-label', 'Verified report');
    this.render();
  }

  appendPage(next) {
    if (next.status !== 'ok' && next.status !== 'no_data') throw new Error('The next report page failed.');
    if (next.metadata?.report !== this.response.metadata?.report
      || next.metadata?.group_by !== this.response.metadata?.group_by
      || JSON.stringify(next.table?.columns) !== JSON.stringify(this.columns)
      || JSON.stringify(next.filters) !== JSON.stringify(this.response.filters)
      || JSON.stringify(next.time_range) !== JSON.stringify(this.response.time_range)
      || next.pagination?.offset !== this.nextOffset) {
      throw new Error('The next report page does not match this report.');
    }
    this.rows.push(...next.table.rows);
    if (Array.isArray(next.chart?.data)) this.chartData.push(...next.chart.data);
    this.nextOffset = next.pagination?.next_offset ?? null;
    this.busy = false;
    this.error = '';
    this.render();
  }

  setLoading(loading) { this.busy = loading; this.render(); }
  setError(message) { this.busy = false; this.error = text(message); this.render(); }
  destroy() { if (this.chartInstance) this.chartInstance.destroy(); this.chartInstance = null; }

  render() {
    this.destroy();
    this.root.replaceChildren();
    const header = element('header', 'report-head');
    const heading = element('div');
    heading.append(element('h2', 'report-title', this.response.metadata?.title || reportTitle(this.response.metadata?.report, this.response.metadata?.group_by)),
      element('p', 'report-subtitle', `${dateRangeLabel(this.response.time_range)} · ${this.scopeLabel()}`));
    header.append(heading, element('span', 'verified-badge', '✓ Verified SQL data'));
    this.root.append(header);

    if (Array.isArray(this.response.kpis) && this.response.kpis.length) this.renderKpis();
    if (this.response.status === 'no_data') {
      this.root.append(element('div', 'state-card no-data', 'No rows matched this report period and shop scope. Try a different range or grouping.'));
    } else {
      this.renderChart();
      this.renderTable();
    }
    if (Array.isArray(this.response.insights) && this.response.insights.length) this.renderInsights();
    if (this.error) this.root.append(element('p', 'report-error', this.error));
    this.root.append(element('p', 'export-note', 'Amounts and rows are returned by the approved Query API. Charts are visual views of the loaded rows.'));
  }

  scopeLabel() {
    const shops = this.response.filters?.shopnos;
    if (!Array.isArray(shops) || !shops.length) return 'Configured database';
    return shops.length === 1 ? `Shop ${text(shops[0])}` : `${shops.length} authorized shops`;
  }

  renderKpis() {
    const grid = element('div', 'kpi-grid');
    for (const kpi of this.response.kpis) {
      if (!kpi || !['verified_sql_aggregate','validated_ai_sql'].includes(kpi.source)) continue;
      const card = element('div', 'kpi');
      card.append(element('span', 'kpi-label', kpi.label || kpi.id),
        element('strong', 'kpi-value', kpi.value),
        element('small', 'kpi-source', kpi.source==='validated_ai_sql'?'Database query result':'Verified database aggregate'));
      grid.append(card);
    }
    if (grid.childElementCount) this.root.append(grid);
  }

  renderChart() {
    if (!this.chartData.length || !this.response.chart?.x || !this.response.chart?.y) return;
    const section = element('section', 'report-section');
    const head = element('div', 'section-head');
    head.append(element('h3', '', 'Visual overview'));
    const switcher = element('div', 'chart-switcher');
    switcher.setAttribute('role', 'group');
    switcher.setAttribute('aria-label', 'Chart type');
    for (const kind of ['bar', 'line', 'doughnut', 'pie']) {
      const option = button(kind === 'doughnut' ? 'Donut' : kind[0].toUpperCase() + kind.slice(1),
        kind === this.chartKind ? 'active' : '', () => { this.chartKind = kind; this.render(); });
      option.setAttribute('aria-pressed', kind === this.chartKind ? 'true' : 'false');
      switcher.append(option);
    }
    head.append(switcher);
    const wrap = element('div', 'chart-wrap');
    section.append(head, wrap);
    if (this.nextOffset !== null) section.append(element('p', 'chart-note', 'More rows are available. This chart shows loaded rows only.'));
    else section.append(element('p', 'chart-note', 'Exact values are available in the table below.'));
    this.root.append(section);
    this.chartInstance = drawChart(wrap,
      { ...this.response.chart, data: this.chartData }, this.chartKind, this.chartInstance);
  }

  renderTable() {
    const section = element('section', 'report-section');
    const head = element('div', 'section-head');
    head.append(element('h3', '', 'Report data'));
    const actions = element('div', 'report-actions');
    const csv = button('↓ Export CSV', 'outline-button', () => this.exportCsv());
    csv.disabled = !this.rows.length;
    const print = button('⎙ Print / Save PDF', 'outline-button', () => this.onPrint?.(this.root));
    print.disabled = !this.rows.length;
    actions.append(csv, print);
    head.append(actions);
    section.append(head);

    const toolbar = element('div', 'table-toolbar');
    const count = element('span', 'section-caption', `${this.rows.length} loaded row${this.rows.length === 1 ? '' : 's'}`);
    const select = element('select');
    select.setAttribute('aria-label', 'Rows per table page');
    for (const size of [10, 25, 50]) {
      const option = element('option', '', `${size} rows`);
      option.value = String(size);
      if (size === this.pageSize) option.selected = true;
      select.append(option);
    }
    select.addEventListener('change', () => { this.pageSize = Number(select.value); this.tablePage = 0; this.render(); });
    toolbar.append(count, select);
    section.append(toolbar);

    const wrap = element('div', 'table-wrap');
    const table = element('table', 'data-table');
    const caption = element('caption', 'sr-only', 'Verified report rows');
    table.append(caption);
    const thead = element('thead');
    const heading = element('tr');
    for (const column of this.columns) {
      const cell = element('th', ['integer', 'decimal', 'number'].includes(column.type) ? 'numeric' : '');
      cell.scope = 'col';
      const label = this.sortColumn === column.name
        ? `${column.name} ${this.sortDirection === 'asc' ? '↑' : '↓'}` : `${column.name} ↕`;
      cell.append(button(label, '', () => {
        this.sortDirection = this.sortColumn === column.name && this.sortDirection === 'asc' ? 'desc' : 'asc';
        this.sortColumn = column.name;
        this.tablePage = 0;
        this.render();
      }));
      heading.append(cell);
    }
    thead.append(heading);
    table.append(thead);
    const tbody = element('tbody');
    const sorted = this.sortColumn
      ? sortRows(this.rows, this.sortColumn, this.columns.find(column => column.name === this.sortColumn)?.type, this.sortDirection)
      : this.rows;
    const start = this.tablePage * this.pageSize;
    for (const row of sorted.slice(start, start + this.pageSize)) {
      const tr = element('tr');
      for (const column of this.columns) {
        const td = element('td', ['integer', 'decimal', 'number'].includes(column.type) ? 'numeric' : '', row[column.name]);
        td.title = text(row[column.name]);
        tr.append(td);
      }
      tbody.append(tr);
    }
    table.append(tbody);
    wrap.append(table);
    section.append(wrap);

    const footer = element('div', 'table-footer');
    const visibleEnd = Math.min(start + this.pageSize, this.rows.length);
    footer.append(element('span', '', this.rows.length ? `Showing ${start + 1}–${visibleEnd} of ${this.rows.length} loaded` : 'No rows loaded'));
    const buttons = element('div', 'page-buttons');
    const previous = button('Previous', '', () => { this.tablePage--; this.render(); });
    previous.disabled = this.tablePage === 0;
    const next = button('Next', '', () => { this.tablePage++; this.render(); });
    next.disabled = visibleEnd >= this.rows.length;
    buttons.append(previous, next);
    if (this.nextOffset !== null && this.onMore) {
      const more = button(this.busy ? 'Loading…' : 'Load next data page', 'load-more', () => this.onMore(this));
      more.disabled = this.busy;
      buttons.append(more);
    }
    footer.append(buttons);
    section.append(footer);
    this.root.append(section);
  }

  renderInsights() {
    const section = element('section', 'report-section');
    section.append(element('h3', '', 'Assistant insights'));
    for (const insight of this.response.insights.slice(0, 5)) {
      if (typeof insight === 'string') section.append(element('p', 'narrative-note', insight));
    }
    this.root.append(section);
  }

  exportCsv() {
    const csv = toCsv(this.columns, this.rows);
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = element('a');
    link.href = url;
    const report = text(this.response.metadata?.report).replace(/[^a-z0-9_-]/gi, '') || 'report';
    link.download = `${report}-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
}
