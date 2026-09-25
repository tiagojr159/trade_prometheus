(() => {
  const select = document.getElementById('autoRefreshInterval');
  const status = document.getElementById('autoRefreshStatus');
  const storageKey = 'prometheus_auto_refresh_seconds';
  let timer = null;
  let running = false;

  const setStatus = (text, muted = true) => {
    if (!status) {
      return;
    }
    status.textContent = text;
    status.classList.toggle('text-muted', muted);
    status.classList.toggle('text-danger', !muted);
  };

  const runUpdate = async () => {
    if (running) {
      return;
    }

    running = true;
    setStatus('atualizando...');

    try {
      const response = await fetch('../cron.php?task=all', { cache: 'no-store' });
      if (!response.ok && response.status !== 409) {
        throw new Error(`HTTP ${response.status}`);
      }
      await response.text();
      setStatus('recarregando...');
      window.location.reload();
    } catch (error) {
      running = false;
      setStatus('falha ao atualizar', false);
      console.error('Falha na atualizacao automatica do PROMETHEUS:', error);
    }
  };

  const schedule = (seconds) => {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }

    if (!seconds) {
      setStatus('manual');
      return;
    }

    setStatus(`a cada ${seconds / 60} min`);
    timer = setInterval(runUpdate, seconds * 1000);
  };

  if (!select) {
    return;
  }

  const saved = localStorage.getItem(storageKey);
  if (saved !== null && select.querySelector(`option[value="${saved}"]`)) {
    select.value = saved;
  }

  select.addEventListener('change', () => {
    localStorage.setItem(storageKey, select.value);
    schedule(Number(select.value));
  });

  schedule(Number(select.value));
})();
