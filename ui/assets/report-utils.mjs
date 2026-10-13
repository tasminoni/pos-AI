export const text = value => value === null || value === undefined ? '' : String(value);

export function reportTitle(report, group) {
  const names = {
    sales_amount: 'Sales amount', product_sales_amount: 'Product sales',
    invoice_list: 'Invoices', invoice_lines: 'Invoice lines',
  };
  const groups = { year: 'by year', month: 'by month', day: 'by day', shop: 'by shop', product: 'by product' };
  return [names[report] || 'Business report', groups[group] || ''].filter(Boolean).join(' ');
}

export function dateRangeLabel(range) {
  if (!range || range.all_authorized_history) return 'All authorized history';
  if (!range.start || !range.end_exclusive) return 'Date range unavailable';
  const last = new Date(`${range.end_exclusive}T00:00:00Z`);
  if (!Number.isNaN(last.getTime())) last.setUTCDate(last.getUTCDate() - 1);
  return `${range.start} – ${Number.isNaN(last.getTime()) ? range.end_exclusive : last.toISOString().slice(0, 10)} · Asia/Dhaka`;
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
  return points;
}

function csvCell(value, type) {
  let content = text(value);
  const numeric = ['integer', 'decimal', 'number'].includes(type) && /^-?\d+(?:\.\d+)?$/.test(content);
  if (!numeric && /^[\s\u0000-\u001f]*[=+\-@]/.test(content)) content = `'${content}`;
  return `"${content.replaceAll('"', '""')}"`;
}

export function toCsv(columns, rows) {
  const header = columns.map(column => csvCell(column.name, 'string')).join(',');
  const lines = rows.map(row => columns.map(column => csvCell(row[column.name], column.type)).join(','));
  return `\ufeff${[header, ...lines].join('\r\n')}\r\n`;
}
