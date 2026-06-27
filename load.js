<script>
(function () {
  const kontenAsli = document.body.innerHTML;
  const DURASI = 5000; // total durasi loading dalam ms

  const SIZE = 88, STROKE = 6;
  const RADIUS = (SIZE - STROKE) / 2;
  const CIRC = 2 * Math.PI * RADIUS;

  document.body.innerHTML = `
    <div id="loading-overlay" style="
      position: fixed; inset: 0;
      display: flex; justify-content: center; align-items: center;
      background: linear-gradient(135deg, #f6f8fc 0%, #e9edf6 100%);
      font-family: 'Segoe UI', Arial, sans-serif;
      z-index: 9999;
      transition: opacity .45s ease;
    ">
      <div style="text-align:center;">
        <div style="position:relative; width:${SIZE}px; height:${SIZE}px; margin:0 auto 20px; filter: drop-shadow(0 4px 14px rgba(59,130,246,.25));">
          <svg width="${SIZE}" height="${SIZE}" viewBox="0 0 ${SIZE} ${SIZE}" style="transform: rotate(-90deg);">
            <defs>
              <linearGradient id="ls-grad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#6366f1"/>
                <stop offset="100%" stop-color="#3b82f6"/>
              </linearGradient>
            </defs>
            <circle cx="${SIZE/2}" cy="${SIZE/2}" r="${RADIUS}" fill="none" stroke="#e3e8f3" stroke-width="${STROKE}"/>
            <circle id="ls-ring" cx="${SIZE/2}" cy="${SIZE/2}" r="${RADIUS}" fill="none" stroke="url(#ls-grad)" stroke-width="${STROKE}" stroke-linecap="round" stroke-dasharray="${CIRC}" stroke-dashoffset="${CIRC}"/>
          </svg>
          <span id="ls-percent" style="
            position:absolute; inset:0;
            display:flex; align-items:center; justify-content:center;
            font-size:18px; font-weight:700; color:#3b56d4;
            font-variant-numeric: tabular-nums;
          ">0%</span>
        </div>
        <p style="font-size:14px; color:#7a8499; margin:0; letter-spacing:.3px;">Mohon tunggu sebentar…</p>
      </div>
    </div>
  `;

  const ring = document.getElementById('ls-ring');
  const percentEl = document.getElementById('ls-percent');
  const start = performance.now();

  function tick(now) {
    const elapsed = now - start;
    const ratio = Math.min(elapsed / DURASI, 1);
    if (ring) ring.style.strokeDashoffset = CIRC * (1 - ratio);
    if (percentEl) percentEl.textContent = Math.round(ratio * 100) + '%';

    if (ratio < 1) {
      requestAnimationFrame(tick);
    } else {
      const overlay = document.getElementById('loading-overlay');
      if (overlay) {
        overlay.style.opacity = '0';
        setTimeout(() => { document.body.innerHTML = kontenAsli; }, 450);
      } else {
        document.body.innerHTML = kontenAsli;
      }
    }
  }
  requestAnimationFrame(tick);
})();
</script>
