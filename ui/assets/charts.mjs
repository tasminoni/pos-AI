import { chartPoints } from './report-utils.mjs';

const palette = ['#df7955', '#247f84', '#778ad1', '#e4ad63', '#9d80b9', '#5c9f75', '#c66d8d', '#738a9c'];

export function buildChartConfig(chart, kind) {
  const points = chartPoints(chart, kind);
  if (!points || !points.length) return null;
  const circular = kind === 'pie' || kind === 'doughnut';
  const dataset = {
    label: 'Verified sales amount',
    data: points.map(point => point.value),
    backgroundColor: circular ? points.map((_, index) => palette[index % palette.length]) :
      (kind === 'bar' ? '#df7955' : 'rgba(223,121,85,.12)'),
    borderColor: circular ? '#ffffff' : '#df7955',
    borderWidth: circular ? 2 : (kind === 'line' ? 2.5 : 0),
    borderRadius: kind === 'bar' ? 6 : 0,
    tension: kind === 'line' ? .32 : 0,
    fill: kind === 'line',
    pointRadius: kind === 'line' ? 3 : 0,
    maxBarThickness: 44,
  };
  const options = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
      legend: { display: circular, position: 'bottom', labels: { color: '#617184', boxWidth: 10, padding: 14 } },
      tooltip: { callbacks: { label: item => `${item.label}: ${points[item.dataIndex]?.exact ?? ''}` } },
    },
  };
  if (!circular) {
    options.scales = {
      x: { grid: { display: false }, ticks: { color: '#8190a0', maxRotation: 35, autoSkip: true } },
      y: { beginAtZero: false, grid: { color: '#e9edf1' }, ticks: { color: '#8190a0' } },
    };
  }
  return { type: kind, data: { labels: points.map(point => point.label), datasets: [dataset] }, options };
}

export function drawChart(container, chart, kind, current = null) {
  if (current) current.destroy();
  container.replaceChildren();
  const config = buildChartConfig(chart, kind);
  if (!config) {
    const note = document.createElement('div');
    note.className = 'chart-unavailable';
    note.textContent = 'This chart cannot display the current values. The exact table remains available.';
    container.append(note);
    return null;
  }
  if (typeof window.Chart !== 'function') {
    const note = document.createElement('div');
    note.className = 'chart-unavailable';
    note.textContent = 'Chart library unavailable. The report table is still available.';
    container.append(note);
    return null;
  }
  const canvas = document.createElement('canvas');
  canvas.setAttribute('role', 'img');
  canvas.setAttribute('aria-label', `${kind} chart of verified ${chart.y} by ${chart.x}`);
  container.append(canvas);
  return new window.Chart(canvas, config);
}
