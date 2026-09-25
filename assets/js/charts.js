(() => {
  const el = document.getElementById('performanceChart');
  if (!el || !window.PROMETHEUS_PERF) return;
  const labels = window.PROMETHEUS_PERF.map(r => `${r.module} ${r.horizon}`);
  const data = window.PROMETHEUS_PERF.map(r => Number(r.accuracy || 0) * 100);
  new Chart(el, {
    type: 'bar',
    data: { labels, datasets: [{ label: 'Acerto %', data, backgroundColor: '#2f6fed' }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 100 } } }
  });
})();
