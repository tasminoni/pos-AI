export const text = value => value === null || value === undefined ? '' : String(value);

export function columnLabel(column) {
  const supplied = text(column?.label).trim();
  if (supplied) return supplied;
  const name = text(typeof column === 'string' ? column : column?.name);
  return name.replace(/([a-z\d])([A-Z])/g, '$1 $2').replace(/([A-Z])([A-Z][a-z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ').trim().replace(/\b\w/g, letter => letter.toUpperCase()) || 'Value';
}

export function formatNumber(value) {
  const raw = text(value);
  if (!/^-?\d+(?:\.\d+)?$/.test(raw)) return raw;
  const [whole, fraction] = raw.split('.');
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction === undefined ? '' : `.${fraction}`);
}

// SQL timestamps without an offset are local wall times: never shift them by browser timezone.
export function formatDate(value, { grain = 'none', locale = 'en-GB', timezone = 'Asia/Dhaka', time = true } = {}) {
  const raw = text(value);
  const clock = /^(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?$/.exec(raw);
  if (clock && Number(clock[1]) < 24 && Number(clock[2]) < 60 && Number(clock[3] || 0) < 60) {
    return new Intl.DateTimeFormat(locale, { timeZone: 'UTC', hour: 'numeric', minute: '2-digit', hour12: true })
      .format(new Date(Date.UTC(2000, 0, 1, Number(clock[1]), Number(clock[2]))));
  }
  if (grain === 'year' && /^\d{4}$/.test(raw)) return raw;
  const match = /^(\d{4})-(\d{2})(?:-(\d{2}))?(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-]\d{2}:\d{2})?)?$/.exec(raw);
  if (!match) return raw;
  const [, year, month, day = '01', hour = '00', minute = '00', second = '00', offset] = match;
  const wall = new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}Z`);
  if (Number.isNaN(wall.getTime()) || wall.getUTCFullYear() !== Number(year) || wall.getUTCMonth() + 1 !== Number(month)
    || wall.getUTCDate() !== Number(day) || wall.getUTCHours() !== Number(hour) || wall.getUTCMinutes() !== Number(minute)) return raw;
  const date = offset ? new Date(raw.replace(' ', 'T')) : wall;
  const options = { timeZone: offset ? timezone : 'UTC', year: 'numeric', month: grain === 'month' ? 'long' : 'short' };
  if (grain === 'year') delete options.month;
  else if (grain !== 'month') options.day = 'numeric';
  if (grain === 'none' && time && match[4] !== undefined && (hour !== '00' || minute !== '00' || second !== '00')) {
    options.hour = 'numeric'; options.minute = '2-digit'; options.hour12 = true;
  }
  try { return new Intl.DateTimeFormat(locale, options).format(date); } catch { return raw; }
}

export function formatValue(value, column = {}, options = {}) {
  if (value === null || value === undefined || value === '') return '—';
  const name = text(column.name), raw = text(value);
  const grain = options.grain || 'none';
  if (column.type === 'date' || grain !== 'none' || /date|time|period|month|year/i.test(name)) {
    if (grain === 'year' || /^(?:year|sales_year|report_year)$/i.test(name)) return /^\d{4}$/.test(raw) ? raw : formatDate(raw, { ...options, grain: 'year' });
    const date = formatDate(raw, options);
    if (date !== raw || column.type === 'date') return date;
  }
  if (['integer', 'decimal', 'number'].includes(column.type) && !/(?:^|_)(?:id|code|sku|serial|no)(?:$|_)|(?:Id|ID|Code|Number|No)$/.test(name)) return formatNumber(raw);
  return raw;
}

export function reportTitle(report, group) {
  const names = {
    sales_amount: 'Sales amount', product_sales_amount: 'Product sales',
    invoice_list: 'Invoices', invoice_lines: 'Invoice lines',
  };
  const groups = { year: 'by year', month: 'by month', day: 'by day', shop: 'by shop', product: 'by product' };
  return [names[report] || 'Business report', groups[group] || ''].filter(Boolean).join(' ');
}

export function dateRangeLabel(range) {
  if (!range || range.all_authorized_history) return 'All available dates';
  if (!range.start) return range.end_exclusive ? `Before ${formatDate(range.end_exclusive)}` : 'Selected dates';
  if (!range.end_exclusive) return `From ${formatDate(range.start)}`;
  const last = new Date(`${range.end_exclusive}T00:00:00Z`);
  if (!Number.isNaN(last.getTime())) last.setUTCDate(last.getUTCDate() - 1);
  return `${formatDate(range.start)} – ${formatDate(Number.isNaN(last.getTime()) ? range.end_exclusive : last.toISOString().slice(0, 10))}`;
}

function decimalParts(value) {
  const raw = text(value).trim();
  if (!/^-?\d+(?:\.\d+)?$/.test(raw)) return null;
  const negative = raw.startsWith('-');
  const [integer, fraction = ''] = (negative ? raw.slice(1) : raw).split('.');
  return { negative, integer: integer.replace(/^0+(?=\d)/, ''), fraction: fraction.replace(/0+$/, '') };
}

export function compareDecimal(a, b) {
  const left = decimalParts(a), right = decimalParts(b);
  if (!left || !right) return text(a).localeCompare(text(b));
  const leftZero = left.integer === '0' && !left.fraction;
  const rightZero = right.integer === '0' && !right.fraction;
  if (leftZero && rightZero) return 0;
  if (left.negative !== right.negative) return left.negative ? -1 : 1;
  let magnitude = left.integer.length - right.integer.length;
  if (!magnitude) magnitude = left.integer.localeCompare(right.integer);
  if (!magnitude) {
    const length = Math.max(left.fraction.length, right.fraction.length);
    magnitude = left.fraction.padEnd(length, '0').localeCompare(right.fraction.padEnd(length, '0'));
  }
  return left.negative ? -Math.sign(magnitude) : Math.sign(magnitude);
}

export function sortRows(rows, column, type, direction = 'asc') {
  const factor = direction === 'desc' ? -1 : 1;
  return rows.map((row, index) => ({ row, index })).sort((a, b) => {
    const av = a.row[column], bv = b.row[column];
    if (av === null || av === undefined) return bv === null || bv === undefined ? a.index - b.index : 1;
    if (bv === null || bv === undefined) return -1;
    const comparison = ['integer', 'decimal', 'number'].includes(type)
      ? compareDecimal(av, bv) : text(av).localeCompare(text(bv), undefined, { numeric: true });
    return comparison * factor || a.index - b.index;
  }).map(item => item.row);
}

export function chartPoints(chart, kind = 'bar') {
  if (!chart || !Array.isArray(chart.data) || typeof chart.x !== 'string' || typeof chart.y !== 'string') return null;
  const points = [];
  for (const row of chart.data) {
    if (!row || typeof row !== 'object') return null;
    const exact = text(row[chart.y]);
    if (!/^-?\d+(?:\.\d+)?$/.test(exact)) return null;
    const numeric = Number(exact);
    if (!Number.isFinite(numeric) || Math.abs(numeric) > Number.MAX_SAFE_INTEGER
      || (['pie', 'doughnut'].includes(kind) && numeric < 0)) return null;
    points.push({ label: text(row[chart.x]), value: numeric, exact });
  }
  if (['pie', 'doughnut'].includes(kind) && !points.some(point => point.value > 0)) return null;
  return points;
}

function csvCell(value, type) {
  let content = text(value);
  const numeric = ['integer', 'decimal', 'number'].includes(type) && /^-?\d+(?:\.\d+)?$/.test(content);
  if (!numeric && /^[\s\u0000-\u001f]*[=+\-@]/.test(content)) content = `'${content}`;
  return `"${content.replaceAll('"', '""')}"`;
}

export function toCsv(columns, rows) {
  const header = columns.map(column => csvCell(columnLabel(column), 'string')).join(',');
  const lines = rows.map(row => columns.map(column => csvCell(column.type === 'date' ? formatDate(row[column.name], { grain: column.display_grain || 'none' }) : row[column.name], column.type)).join(','));
  return `\ufeff${[header, ...lines].join('\r\n')}\r\n`;
}
