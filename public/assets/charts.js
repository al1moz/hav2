/* ConsoV2 : petits graphiques SVG sans bibliothèque. Les couleurs viennent des variables CSS du thème. */
'use strict';
const Charts = (() => {
  const tipEl = () => document.getElementById('tip');
  const NF = {};
  function fmt(n, d = 1) {
    if (n === null || n === undefined || Number.isNaN(n)) return '–';
    if (!NF[d]) NF[d] = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d });
    return NF[d].format(n);
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function showTip(e, html) {
    const t = tipEl(); t.innerHTML = html; t.hidden = false;
    const x = Math.min(e.clientX + 14, innerWidth - t.offsetWidth - 8);
    const y = Math.min(e.clientY + 14, innerHeight - t.offsetHeight - 8);
    t.style.left = x + 'px'; t.style.top = y + 'px';
  }
  function hideTip() { tipEl().hidden = true; }
  /** Pas de graduation « rond » (1, 2, 2,5 ou 5 x 10^n) pour environ 5 graduations. */
  function niceStep(range, ticks = 5) {
    if (!(range > 0)) return 1;
    const raw = range / ticks, p = Math.pow(10, Math.floor(Math.log10(raw)));
    for (const m of [1, 2, 2.5, 5, 10]) if (raw <= m * p) return m * p;
    return 10 * p;
  }
  function scale(values, opts = {}) {
    const vals = values.filter(v => v !== null && v !== undefined && !Number.isNaN(v));
    let lo = Math.min(...vals, opts.min ?? Infinity), hi = Math.max(...vals, opts.max ?? -Infinity);
    if (!vals.length) { lo = 0; hi = 1; }
    if (lo === hi) { lo -= 1; hi += 1; }
    const step = opts.step || niceStep(hi - lo);
    return { lo: Math.floor(lo / step) * step, hi: Math.ceil(hi / step) * step, step };
  }
  function decimalsFor(step) { return step >= 1 ? 0 : step >= 0.1 ? 1 : 2; }
  function empty(el, text = 'Pas encore de données pour cette période.') { el.innerHTML = `<p class="empty">${esc(text)}</p>`; }
  /**
   * Étiquettes de l'axe des x, espacées selon leur longueur. major : étiquettes courtes aux
   * repères ronds (minuit, heures multiples de 3), null ailleurs ; utilisées s'il y en a au moins deux.
   */
  function xLabels(n, x, labels, H, major) {
    let s = '';
    const span = n > 1 ? Math.abs(x(n - 1) - x(0)) : 300;
    const marks = major ? major.map((m, i) => m === null || m === undefined ? null : [i, m]).filter(Boolean) : [];
    const list = marks.length >= 2 ? marks : labels.map((lb, i) => [i, lb]);
    const len = Math.max(...list.map(l => String(l[1]).length));
    const fit = Math.max(2, Math.floor(span / (len * 6.3 + 14)));
    const step = Math.max(1, Math.ceil(list.length / Math.min(7, fit)));
    const last = list.length - 1;
    list.forEach(([i, lb], k) => {
      if (k % step === 0 || marks.length < 2 && k === last && last % step > step * .75) s += `<text x="${x(i)}" y="${H - 6}" text-anchor="middle">${esc(lb)}</text>`;
    });
    return s;
  }
  function grid(sc, y, L, W, R) {
    let s = '';
    const d = decimalsFor(sc.step);
    for (let t = sc.lo; t <= sc.hi + 1e-9; t += sc.step) s += `<line x1="${L}" x2="${W - R}" y1="${y(t)}" y2="${y(t)}" stroke="var(--grid)"/><text x="${L - 6}" y="${y(t) + 4}" text-anchor="end">${fmt(t, d)}</text>`;
    return s;
  }

  /**
   * Zoom : glisser sur le graphique (souris ou doigt à l'horizontale) pour agrandir une zone,
   * double-clic ou bouton « Tout afficher » pour revenir. Les graphiques d'un même opts.zoomGroup
   * (même axe des x) zooment ensemble. draw(a, b) dessine les points a à b et renvoie la géométrie.
   */
  const groups = {};
  function zoomable(el, n, opts, draw) {
    const g = opts.zoomGroup ? (groups[opts.zoomGroup] = groups[opts.zoomGroup] || { range: null, members: new Map() }) : { range: null, members: new Map() };
    g.range = null; // nouvelles données : vue entière
    for (const k of g.members.keys()) if (!k.isConnected) g.members.delete(k);
    const render = () => {
      const r = g.range && g.range.n === n ? g.range : null;
      const a = r ? r.a : 0, b = r ? r.b : n - 1;
      const geo = draw(a, b);
      if (geo && n > 2) attachZoom(el, geo, a, b, n, g);
    };
    g.members.set(el, render);
    render();
  }
  function renderGroup(g) { g.members.forEach(fn => fn()); }
  function attachZoom(el, geo, a, b, n, g) {
    const svg = el.querySelector('svg');
    if (!svg) return;
    svg.classList.add('zoomable');
    const NS = 'http://www.w3.org/2000/svg';
    const px = e => { const r = svg.getBoundingClientRect(); return (e.clientX - r.left) / r.width * geo.W; };
    const clamp = v => Math.max(geo.L, Math.min(geo.L + geo.pw, v));
    let start = null, sel = null;
    const stop = () => { start = null; if (sel) { sel.remove(); sel = null; } el.classList.remove('zooming'); };
    svg.addEventListener('pointerdown', e => {
      if (e.button) return;
      const p = px(e);
      if (p < geo.L || p > geo.L + geo.pw) return;
      start = p;
    });
    svg.addEventListener('pointermove', e => {
      if (start === null) return;
      const p = clamp(px(e));
      if (!sel) {
        if (Math.abs(p - start) < 6) return;
        try { svg.setPointerCapture(e.pointerId); } catch (_) { /* pointeur déjà relâché */ }
        hideTip();
        el.classList.add('zooming');
        sel = document.createElementNS(NS, 'rect');
        sel.setAttribute('class', 'zoom-sel');
        sel.setAttribute('y', geo.T); sel.setAttribute('height', geo.ph);
        svg.appendChild(sel);
      }
      sel.setAttribute('x', Math.min(start, p)); sel.setAttribute('width', Math.abs(p - start));
    });
    svg.addEventListener('pointerup', e => {
      if (start !== null && sel) {
        const p = clamp(px(e));
        const i0 = geo.idx(Math.min(start, p)), i1 = geo.idx(Math.max(start, p));
        if (i1 > i0) { g.range = { a: a + i0, b: a + i1, n }; stop(); renderGroup(g); return; }
      }
      stop();
    });
    svg.addEventListener('pointercancel', stop);
    const zoomed = a > 0 || b < n - 1;
    const reset = () => { g.range = null; renderGroup(g); };
    svg.addEventListener('dblclick', () => { if (zoomed) reset(); });
    if (zoomed) {
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'zoom-reset'; btn.textContent = 'Tout afficher';
      btn.addEventListener('click', reset);
      el.appendChild(btn);
    }
  }
  /** opts.tips : libellés plus longs pour l'infobulle (sinon ceux de l'axe). */
  const sliceOpts = (opts, a, b) => Object.assign({}, opts, opts.tips ? { tips: opts.tips.slice(a, b + 1) } : {}, opts.major ? { major: opts.major.slice(a, b + 1) } : {});
  const slice = (series, a, b) => series.map(s => Object.assign({}, s, { values: s.values.slice(a, b + 1) }));

  /** Barres empilées. series : [{name, color, values[]}] dans l'ordre d'empilement. */
  function stacked(el, labels, series, opts = {}) {
    if (!labels.length) return empty(el);
    zoomable(el, labels.length, opts, (a, b) => drawStacked(el, labels.slice(a, b + 1), slice(series, a, b), sliceOpts(opts, a, b)));
  }
  function drawStacked(el, labels, series, opts) {
    const n = labels.length;
    const W = opts.w || (el.clientWidth && el.clientWidth < 520 ? 400 : 720), H = opts.h || 250, L = 40, B = 22, T = 8, R = 4, pw = W - L - R, ph = H - T - B;
    const totals = labels.map((_, i) => series.reduce((a, s) => a + (s.values[i] || 0), 0));
    const sc = scale(totals, { min: 0 });
    const y = v => T + ph - (v - sc.lo) / (sc.hi - sc.lo) * ph, bw = pw / n, gap = Math.max(1, Math.min(bw * .25, 8));
    let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(opts.aria || '')}">` + grid(sc, y, L, W, R);
    for (let i = 0; i < n; i++) {
      const x = L + i * bw + gap / 2, w = Math.max(1, bw - gap);
      let acc = 0;
      series.forEach(sr => {
        const v = sr.values[i] || 0; if (v <= 0) return;
        const y0 = y(acc), y1 = y(acc + v);
        s += `<rect x="${x}" y="${y1}" width="${w}" height="${Math.max(0, y0 - y1 - (bw > 8 ? 1.5 : 0))}" fill="${sr.color}"/>`;
        acc += v;
      });
      s += `<rect class="hit" data-i="${i}" x="${L + i * bw}" y="${T}" width="${bw}" height="${ph}"/>`;
    }
    s += xLabels(n, i => L + i * bw + bw / 2, labels, H, opts.major) + `<line x1="${L}" x2="${W - R}" y1="${y(0)}" y2="${y(0)}" stroke="var(--line)"/></svg>`;
    el.innerHTML = s;
    el.querySelectorAll('.hit').forEach(h => {
      h.addEventListener('mousemove', e => {
        if (el.classList.contains('zooming')) return;
        const i = +h.dataset.i;
        const rows = series.map(sr => [sr.name, sr.values[i] || 0]).filter(r => r[1] > 0.005).sort((a, b) => b[1] - a[1]).slice(0, 6);
        showTip(e, `<b>${esc((opts.tips || labels)[i])} : ${fmt(totals[i])} ${opts.unit || ''}</b><br>${rows.map(r => `${esc(r[0])} ${fmt(r[1])}`).join('<br>')}`);
      });
      h.addEventListener('mouseleave', hideTip);
    });
    return { W, L, T, pw, ph, idx: p => Math.max(0, Math.min(n - 1, Math.floor((p - L) / bw))) };
  }

  /** Barres horizontales triées. rows : [{name, value, color}] */
  function hbars(el, rows, opts = {}) {
    rows = rows.filter(r => r.value > 0).sort((a, b) => b.value - a.value);
    if (!rows.length) return empty(el);
    const W = 360, rowH = 24, L = 120, R = 60, H = rows.length * rowH + 4, max = rows[0].value;
    const total = rows.reduce((a, r) => a + r.value, 0);
    let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(opts.aria || '')}">`;
    rows.forEach((r, i) => {
      const w = (W - L - R) * r.value / max, yy = i * rowH + 4;
      s += `<text x="${L - 8}" y="${yy + 13}" text-anchor="end">${esc(r.name)}</text><rect x="${L}" y="${yy + 3}" width="${Math.max(1, w)}" height="13" rx="3" fill="${r.color}"/><text x="${L + w + 6}" y="${yy + 13}">${fmt(r.value, 0)}</text>`;
      s += `<rect class="hit" data-i="${i}" x="0" y="${yy}" width="${W}" height="${rowH}"/>`;
    });
    el.innerHTML = s + '</svg>';
    el.querySelectorAll('.hit').forEach(h => {
      const r = rows[+h.dataset.i];
      h.addEventListener('mousemove', e => showTip(e, `<b>${esc(r.name)}</b><br>${fmt(r.value)} ${opts.unit || ''}, ${fmt(r.value / total * 100, 0)} %`));
      h.addEventListener('mouseleave', hideTip);
    });
  }

  /** Courbes. series : [{name, color, values[] (null = trou)}]. opts : unit, min, max, band [a,b], bandLabel, area, dec, tips, major, zoomGroup. */
  function line(el, labels, series, opts = {}) {
    const all = series.flatMap(s => s.values).filter(v => v !== null && v !== undefined);
    if (!labels.length || !all.length) return empty(el, opts.emptyText);
    // L'échelle verticale suit la zone affichée.
    zoomable(el, labels.length, opts, (a, b) => drawLine(el, labels.slice(a, b + 1), slice(series, a, b), sliceOpts(opts, a, b)));
  }
  function drawLine(el, labels, series, opts) {
    const n = labels.length;
    const all = series.flatMap(s => s.values).filter(v => v !== null && v !== undefined);
    if (!all.length) all.push(opts.min ?? 0);
    const W = opts.w || (el.clientWidth && el.clientWidth < 520 ? 400 : 720), H = opts.h || 220, L = 40, B = 22, T = 10, R = 8, pw = W - L - R, ph = H - T - B;
    const sc = scale(all, { min: opts.min, max: opts.max });
    const x = i => L + (n === 1 ? pw / 2 : i / (n - 1) * pw), y = v => T + ph - (v - sc.lo) / (sc.hi - sc.lo) * ph;
    let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(opts.aria || '')}">` + grid(sc, y, L, W, R);
    if (opts.band) {
      const b0 = Math.max(opts.band[0], sc.lo), b1 = Math.min(opts.band[1], sc.hi);
      if (b1 > b0) s += `<rect x="${L}" y="${y(b1)}" width="${pw}" height="${y(b0) - y(b1)}" fill="var(--ok)" opacity=".1"/><text x="${W - R - 4}" y="${y(b1) + 13}" text-anchor="end">${esc(opts.bandLabel || '')}</text>`;
    }
    series.forEach(sr => {
      const segs = []; let cur = [];
      sr.values.forEach((v, i) => { if (v === null || v === undefined) { if (cur.length) segs.push(cur); cur = []; } else cur.push([x(i), y(v)]); });
      if (cur.length) segs.push(cur);
      segs.forEach(seg => {
        if (opts.area) s += `<path d="M${seg[0][0]},${y(sc.lo)} ${seg.map(p => `L${p[0]},${p[1]}`).join(' ')} L${seg[seg.length - 1][0]},${y(sc.lo)}Z" fill="${sr.color}" opacity=".12"/>`;
        if (seg.length === 1) s += `<circle cx="${seg[0][0]}" cy="${seg[0][1]}" r="2.5" fill="${sr.color}"/>`;
        else s += `<polyline points="${seg.map(p => p.join(',')).join(' ')}" fill="none" stroke="${sr.color}" stroke-width="${sr.width || 2}" stroke-linejoin="round" ${sr.dash ? `stroke-dasharray="${sr.dash}"` : ''}/>`;
      });
    });
    s += xLabels(n, x, labels, H, opts.major);
    s += `<line class="cross" x1="0" x2="0" y1="${T}" y2="${T + ph}" stroke="var(--muted)" stroke-dasharray="3 3" opacity="0"/><rect class="hit" x="${L}" y="${T}" width="${pw}" height="${ph}"/></svg>`;
    el.innerHTML = s;
    const svg = el.querySelector('svg'), cross = svg.querySelector('.cross'), hit = svg.querySelector('.hit');
    const d = opts.dec ?? 1;
    hit.addEventListener('mousemove', e => {
      if (el.classList.contains('zooming')) return;
      const r = svg.getBoundingClientRect(), px = (e.clientX - r.left) / r.width * W;
      const i = Math.max(0, Math.min(n - 1, Math.round((px - L) / pw * (n - 1))));
      cross.setAttribute('x1', x(i)); cross.setAttribute('x2', x(i)); cross.setAttribute('opacity', '1');
      showTip(e, `<b>${esc((opts.tips || labels)[i])}</b><br>${series.map(sr => `${esc(sr.name)} ${fmt(sr.values[i], d)} ${opts.unit || ''}`).join('<br>')}`);
    });
    hit.addEventListener('mouseleave', () => { cross.setAttribute('opacity', '0'); hideTip(); });
    return { W, L, T, pw, ph, idx: p => Math.max(0, Math.min(n - 1, Math.round((p - L) / pw * (n - 1)))) };
  }

  let clipId = 0;
  /** Nuage de points. pts : [{x, y, color, title}], fit : [[x,y],…] tracé en pointillés. */
  function scatter(el, pts, opts = {}) {
    if (!pts.length) return empty(el);
    const W = 360, H = 260, L = 34, B = 30, T = 10, R = 10, pw = W - L - R, ph = H - T - B;
    const sx = scale(pts.map(p => p.x), { step: 5 }), sy = scale(pts.map(p => p.y), { min: 0 });
    const x = v => L + (v - sx.lo) / (sx.hi - sx.lo) * pw, y = v => T + ph - (v - sy.lo) / (sy.hi - sy.lo) * ph;
    let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(opts.aria || '')}">` + grid(sy, y, L, W, R);
    for (let t = sx.lo; t <= sx.hi; t += sx.step) s += `<text x="${x(t)}" y="${H - 14}" text-anchor="middle">${t}°</text>`;
    s += `<text x="${W - R}" y="${H}" text-anchor="end">${esc(opts.xLabel || '')}</text>`;
    pts.forEach(p => { s += `<circle cx="${x(p.x)}" cy="${y(p.y)}" r="2.6" fill="${p.color}" fill-opacity=".55"><title>${esc(p.title || '')}</title></circle>`; });
    // La tendance est coupée aux bords du graphique : à ses extrémités, elle peut dépasser l'échelle des points.
    const clip = 'clip' + (++clipId);
    if (opts.fit) s += `<clipPath id="${clip}"><rect x="${L}" y="${T}" width="${pw}" height="${ph}"/></clipPath>`
      + `<polyline clip-path="url(#${clip})" points="${opts.fit.map(p => `${x(p[0])},${y(p[1])}`).join(' ')}" fill="none" stroke="var(--fg)" stroke-width="2" stroke-dasharray="5 4"/>`;
    el.innerHTML = s + '</svg>';
  }

  return { fmt, esc, showTip, hideTip, stacked, hbars, line, scatter, empty };
})();
