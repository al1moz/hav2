/* ConsoV2 : remplit chaque page à partir de l'API JSON (session du site). */
'use strict';
(() => {
  const { fmt, esc, stacked, hbars, line, scatter, empty } = Charts;
  const TZ = document.body.dataset.tz || 'Europe/Paris';
  const $ = id => document.getElementById(id);
  const COLORS = ['var(--s1)', 'var(--s2)', 'var(--s3)', 'var(--s4)', 'var(--s5)', 'var(--s6)', 'var(--s7)', 'var(--s8)'];
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

  async function api(path, params = {}) {
    const q = new URLSearchParams(params).toString();
    const r = await fetch('/api/v1/' + path + (q ? '?' + q : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (r.status === 401) { location.href = '/connexion'; throw new Error('non connecté'); }
    if (r.status === 422) { await r.text(); return null; } // mesure absente (pas encore envoyée)
    if (!r.ok) throw new Error('API ' + r.status);
    return r.json();
  }

  // ---- dates (journées locales) ----
  const dayFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, day: 'numeric', month: 'short' });
  const dateFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, day: 'numeric', month: 'short', year: 'numeric' });
  const timeFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
  const dayTimeFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  const isoDay = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' });
  const today = () => isoDay.format(new Date());
  function addDays(day, n) { const d = new Date(day + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
  function daysBetween(from, to) { const out = []; for (let d = from; d <= to; d = addDays(d, 1)) out.push(d); return out; }
  const dayLabel = d => dayFmt.format(new Date(d + 'T12:00:00Z'));
  const monthLabel = m => MOIS[+m.slice(5, 7) - 1] + ' ' + m.slice(2, 4);
  function ago(iso) {
    const s = Math.max(0, (Date.now() - Date.parse(iso)) / 1000);
    if (s < 3600) return Math.round(s / 60) + ' min';
    if (s < 86400) { const h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60); return h + ' h' + (m ? ' ' + String(m).padStart(2, '0') : ''); }
    return Math.round(s / 86400) + ' j';
  }
  const SOURCES = { linky: 'Linky', shelly: 'Shelly (circuits)', arkteos: 'PAC Arkteos', netatmo: 'Netatmo' };
  const srcName = s => SOURCES[s] || s;
  function tile(label, value, unit, delta, cls = '') {
    return `<div class="tile ${cls}"><div class="lbl">${esc(label)}</div><div class="big">${value}${unit ? `<small>${esc(unit)}</small>` : ''}</div>${delta ? `<div class="delta">${delta}</div>` : ''}</div>`;
  }
  function pressed(group, btn) { group.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', b === btn)); }
  function onControls(id, fn) {
    const g = $(id); if (!g) return;
    g.addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; pressed(g, b); fn(b.dataset); });
  }
  async function summaryDays(metric, from, to) {
    const r = await api('summary', { metric, period: 'day', from, to });
    const map = {}; if (r) r.rows.forEach(x => { map[x.period] = x; });
    return map;
  }
  const failed = el => e => { console.error(e); if (el) empty(el, 'Impossible de charger les données.'); };

  // =============== Aujourd'hui ===============
  async function home() {
    const d = await api('dashboard');
    const L = d.latest, stale = d.stale;
    $('home-sub').textContent = d.last_ts ? `Dernière mesure reçue le ${dayTimeFmt.format(new Date(d.last_ts))}.` : 'Aucune mesure reçue pour l’instant.';
    let alerts = '';
    if (stale) {
      const late = d.sources.filter(s => s.stale);
      alerts += `<div class="alert" role="alert"><span class="ico" aria-hidden="true">!</span><div><b>Aucune donnée reçue depuis ${d.last_ts ? ago(d.last_ts) : 'le début'}.</b> Les chiffres ci-dessous datent de ce moment.
        <ul>${late.map(s => `<li>${esc(srcName(s.source))} : dernière mesure ${dayTimeFmt.format(new Date(s.last_ts))}</li>`).join('')}<li>À vérifier : l’add-on dans Home Assistant et la connexion internet de la maison.</li></ul></div></div>`;
    } else {
      const late = d.sources.filter(s => s.stale);
      if (late.length) alerts += `<div class="alert" role="alert"><span class="ico" aria-hidden="true">!</span><div><b>Une source ne répond plus.</b><ul>${late.map(s => `<li>${esc(srcName(s.source))} : rien depuis ${ago(s.last_ts)}</li>`).join('')}</ul></div></div>`;
    }
    const ecs = d.ecs_alert.episodes;
    if (ecs.length) {
      alerts += `<div class="alert" role="alert"><span class="ico" aria-hidden="true">!</span><div><b>La résistance d’appoint du ballon ECS s’est allumée.</b> ${ecs.length} fois sur les ${d.ecs_alert.days} derniers jours.
        <ul>${ecs.slice(0, 3).map(e => `<li>${dayTimeFmt.format(new Date(e.start))}, ${e.minutes} min, ${fmt(e.kwh, 2)} kWh</li>`).join('')}<li><a href="/chauffage#appoint">Voir le détail</a></li></ul></div></div>`;
    }
    $('alerts').innerHTML = alerts;
    const sc = stale ? 'stale' : '';
    const t = [];
    t.push(d.power ? tile('Puissance', fmt(d.power.value, 0), d.power.unit, `mesurée à ${timeFmt.format(new Date(d.power.ts))}`, sc) : tile('Puissance', '–', '', 'pas de mesure récente'));
    const diff = d.today_kwh !== null && d.yesterday_same_time_kwh ? d.today_kwh - d.yesterday_same_time_kwh : null;
    t.push(tile('Depuis minuit', fmt(d.today_kwh), 'kWh', d.yesterday_same_time_kwh !== null ? `hier à la même heure : ${fmt(d.yesterday_same_time_kwh)}${diff !== null ? ` (${diff >= 0 ? '+' : ''}${fmt(diff)})` : ''}` : '', sc));
    t.push(tile('Coût estimé', fmt(d.today_cost_eur, 2), '€', `depuis minuit, à ${fmt(d.kwh_price, 4)} €/kWh`, sc));
    const tl = L.temp_living, to = L.temp_outdoor;
    t.push(tile('Salon / extérieur', `${tl ? fmt(tl.value) : '–'} / ${to ? fmt(to.value) : '–'}`, '°C', tl ? `mesuré à ${timeFmt.format(new Date(tl.ts))}` : '', sc));
    $('home-tiles').innerHTML = t.join('');

    // Puissance sur 24 h à partir de l'index Linky (ou des 24 h avant la dernière mesure).
    const end = d.last_ts ? Math.min(Date.now(), Date.parse(d.last_ts) + 60000) : Date.now();
    const s = await api('series', { metric: 'elec_index', step: 'raw', from: new Date(end - 86400000).toISOString().slice(0, 19) + 'Z', to: new Date(end).toISOString().slice(0, 19) + 'Z' });
    const el = $('c-day');
    if (!s || s.points.length < 2) return empty(el);
    const labels = [], kw = [];
    for (let i = 1; i < s.points.length; i++) {
      const dt = (Date.parse(s.points[i][0]) - Date.parse(s.points[i - 1][0])) / 1000;
      labels.push(timeFmt.format(new Date(s.points[i][0])));
      kw.push(dt > 0 && dt <= 900 ? (s.points[i][1] - s.points[i - 1][1]) * 3600 / dt : null);
    }
    line(el, labels, [{ name: 'Puissance', color: 'var(--s1)', values: kw }], { unit: 'kW', min: 0, area: true, dec: 2, aria: 'Puissance des dernières 24 heures' });
  }

  // =============== Électricité ===============
  async function electricity() {
    const prof = api('profile', { from: addDays(today(), -29), to: today() });
    async function load(p) {
      const t = today();
      let params, cap, label;
      if (p === '7' || p === '30') { params = { period: 'day', from: addDays(t, -(+p - 1)), to: t }; cap = `kWh par jour, ${p} derniers jours`; label = dayLabel; }
      else if (p === '12m') { const m = new Date(t + 'T12:00:00Z'); m.setUTCMonth(m.getUTCMonth() - 11); params = { period: 'month', from: m.toISOString().slice(0, 8) + '01', to: t }; cap = 'kWh par mois, 12 derniers mois'; label = monthLabel; }
      else { params = { period: 'year', from: '2000-01-01', to: t }; cap = 'kWh par année'; label = x => x; }
      $('elec-cap').textContent = cap;
      const r = await api('breakdown', params);
      const labels = r.rows.map(x => label(x.period));
      const series = r.circuits.map((c, k) => ({ name: c.label, color: COLORS[k % 8], values: r.rows.map(x => x.circuits[c.code] ?? 0) }));
      // Période où les Shelly ne répondaient pas : la différence n'est pas un vrai « reste ».
      const full = x => x.coverage !== null && x.coverage >= 0.9;
      series.push({ name: 'Reste', color: 'var(--faint)', values: r.rows.map(x => full(x) ? x.rest ?? 0 : 0) });
      if (r.rows.some(x => !full(x) && x.rest)) series.push({ name: 'Shelly absents', color: 'var(--absent)', values: r.rows.map(x => full(x) ? 0 : x.rest ?? 0) });
      stacked($('c-elec'), labels, series, { unit: 'kWh', aria: 'Consommation par circuit' });
      $('lg-elec').innerHTML = series.map(s => `<span><i style="background:${s.color}"></i>${esc(s.name)}</span>`).join('');
      $('t-elec').innerHTML = `<table><tr><th>Période</th>${series.map(s => `<th>${esc(s.name)}</th>`).join('')}<th>Total Linky</th></tr>${r.rows.map((x, i) => `<tr><td>${esc(labels[i])}</td>${series.map(s => `<td>${fmt(s.values[i])}</td>`).join('')}<td>${fmt(x.total)}</td></tr>`).join('')}</table>`;
      $('rep-cap').textContent = 'kWh par circuit, ' + cap.replace(/^kWh par (jour|mois|année), ?/, '');
      hbars($('c-rep'), series.map(s => ({ name: s.name, value: s.values.reduce((a, b) => a + b, 0), color: s.color })), { unit: 'kWh', aria: 'Répartition par circuit' });
    }
    onControls('elec-ctl', ds => load(ds.p).catch(failed($('c-elec'))));
    await load('30');
    const p = await prof;
    line($('c-prof'), p.kw.map((_, h) => h + ' h'), [{ name: 'Moyenne', color: 'var(--s1)', values: p.kw }], { w: 360, h: 200, unit: 'kW', min: 0, area: true, dec: 2, aria: 'Profil horaire moyen' });
  }

  // =============== Chauffage ===============
  async function heating() {
    async function load(n) {
      const to = today(), from = addDays(to, -(n - 1)), days = daysBetween(from, to), labels = days.map(dayLabel);
      const [pac, ext] = await Promise.all([summaryDays('circuit_geothermie', from, to), summaryDays('temp_outdoor', from, to)]);
      line($('c-pac'), labels, [{ name: 'PAC', color: 'var(--s1)', values: days.map(d => pac[d] && pac[d].n >= 144 ? pac[d].energy_kwh : null) }], { w: 360, h: 190, unit: 'kWh', min: 0, area: true, aria: 'Consommation quotidienne de la PAC' });
      line($('c-ext'), labels, [{ name: 'Extérieur', color: 'var(--s2)', values: days.map(d => ext[d] ? ext[d].avg : null) }], { w: 360, h: 190, unit: '°C', aria: 'Température extérieure moyenne' });
      if (water.length) {
        const maps = await Promise.all(water.map(([code]) => summaryDays(code, from, to)));
        const series = water.map(([, name, color], i) => ({ name, color, values: days.map(d => maps[i][d] ? maps[i][d].avg : null) }));
        line($('c-water'), labels, series, { unit: '°C', aria: 'Températures d’eau moyennes par jour' });
        $('lg-water').innerHTML = series.map(s => `<span><i style="background:${s.color}"></i>${esc(s.name)}</span>`).join('');
      }
    }
    // Températures d'eau de la PAC (Arkteos) : seulement les mesures déjà reçues.
    const WATER = [['pac_primaire_temp_eau_aller', 'Départ', 'var(--s1)'], ['pac_primaire_temp_eau_retour', 'Retour', 'var(--s3)'],
      ['pac_ecs_temp_eau_milieu', 'Ballon milieu', 'var(--s2)'], ['pac_ecs_temp_eau_bas', 'Ballon bas', 'var(--s4)']];
    const L = (await api('dashboard')).latest;
    const water = WATER.filter(([code]) => L[code]);
    if (water.length) {
      const newest = water.map(([code]) => L[code].ts).sort().pop();
      $('pac-cap').textContent = `Moyenne par jour. Dernière lecture il y a ${ago(newest)}.`;
      const tiles = water.map(([code, name]) => tile(name, fmt(L[code].value), '°C'));
      if (L.pac_primaire_pression) tiles.push(tile('Pression primaire', fmt(L.pac_primaire_pression.value, 1), 'bar'));
      $('pac-tiles').innerHTML = tiles.join('');
    }
    onControls('heat-ctl', ds => load(+ds.d).catch(failed($('c-pac'))));
    const [, e] = await Promise.all([load(30), api('ecs')]);
    const last = e.episodes[0], year = today().slice(0, 4);
    $('ecs-cap').textContent = `Elle ne devrait jamais s’allumer. Activation comptée quand le Shelly mesure au moins ${e.threshold_w} W (au repos il indique environ 4 W).`;
    $('ecs-tiles').innerHTML = [
      last ? `<div class="tile"><div class="lbl">Dernière activation</div><div class="big date">${dateFmt.format(new Date(last.start))}</div><div class="delta">${timeFmt.format(new Date(last.start))}, ${last.minutes} min, ${fmt(last.kwh, 2)} kWh${last.outdoor_c !== null ? `, ${fmt(last.outdoor_c)} °C dehors` : ''}</div></div>`
        : tile('Dernière activation', 'aucune', '', ''),
      tile('En ' + year, String(e.by_year[year] || 0), '', (e.by_year[year] || 0) ? 'activations' : 'aucune activation'),
      tile('Depuis ' + (e.since ? e.since.slice(0, 4) : 'le début'), String(e.count), '', 'activations'),
      tile('Énergie totale', fmt(e.kwh), 'kWh', `≈ ${fmt(e.cost_eur, 2)} € au prix actuel`),
    ].join('');
    $('ecs-table').innerHTML = e.episodes.length ? `<table><tr><th>Début</th><th>Durée</th><th>Énergie</th><th>Puissance max</th><th>Extérieur</th></tr>${e.episodes.map(x =>
      `<tr><td>${dateFmt.format(new Date(x.start))}, ${timeFmt.format(new Date(x.start))}</td><td>${x.minutes} min</td><td>${fmt(x.kwh, 2)} kWh</td><td>${fmt(x.max_w, 0)} W</td><td>${x.outdoor_c === null ? '–' : fmt(x.outdoor_c) + ' °C'}</td></tr>`).join('')}</table>` : '';
  }

  // =============== Températures ===============
  async function temperatures() {
    async function load(n) {
      const to = today(), from = addDays(to, -(n - 1)), days = daysBetween(from, to), labels = days.map(dayLabel);
      const [lv, up, out] = await Promise.all(['temp_living', 'temp_upstairs', 'temp_outdoor'].map(m => summaryDays(m, from, to)));
      const series = [['Salon', lv, 'var(--s1)'], ['Étage', up, 'var(--s3)'], ['Extérieur', out, 'var(--s2)']]
        .map(([name, m, color]) => ({ name, color, values: days.map(d => m[d] ? m[d].avg : null) }))
        .filter(s => s.values.some(v => v !== null));
      line($('c-temp'), labels, series, { unit: '°C', aria: 'Températures moyennes par jour' });
      $('lg-temp').innerHTML = series.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
      const mm = [{ name: 'Maximum', color: 'var(--s2)', values: days.map(d => out[d] ? out[d].max : null) }, { name: 'Minimum', color: 'var(--s1)', values: days.map(d => out[d] ? out[d].min : null) }];
      line($('c-minmax'), labels, mm, { unit: '°C', aria: 'Minimum et maximum extérieurs' });
      $('lg-minmax').innerHTML = mm.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
    }
    onControls('temp-ctl', ds => load(+ds.d).catch(failed($('c-temp'))));
    await load(30);
  }

  // =============== Humidité ===============
  // Humidité absolue (g/m³) à partir de la température (°C) et de l'humidité relative (%).
  const absHum = (t, rh) => 6.112 * Math.exp(17.67 * t / (t + 243.5)) * rh * 2.1674 / (273.15 + t);
  const dewPoint = (t, rh) => { const g = Math.log(rh / 100) + 17.62 * t / (243.12 + t); return 243.12 * g / (17.62 - g); };
  async function humidity() {
    const d = await api('dashboard');
    const L = d.latest, comfort = v => v < 40 ? 'air sec' : v > 60 ? 'air humide' : 'dans la zone de confort';
    // Une tuile par mesure reçue : une sonde absente (ex. humidité de l'étage) n'apparaît pas.
    const tiles = [];
    for (const [code, label] of [['humidity_living', 'Salon'], ['humidity_upstairs', 'Étage']]) {
      if (L[code]) tiles.push(tile(label, fmt(L[code].value, 0), '%', comfort(L[code].value)));
    }
    const ho = L.humidity_outdoor, to = L.temp_outdoor;
    if (ho) tiles.push(tile('Extérieur', fmt(ho.value, 0), '%', to ? `point de rosée ${fmt(dewPoint(to.value, ho.value))} °C` : ''));
    if (L.co2_living) tiles.push(tile('CO₂ salon', fmt(L.co2_living.value, 0), 'ppm', ''));
    $('hum-tiles').innerHTML = tiles.join('');
    async function load(n) {
      const to = today(), from = addDays(to, -(n - 1)), days = daysBetween(from, to), labels = days.map(dayLabel);
      // Seules les mesures déjà reçues sont demandées.
      const [hl, hu, hout, tl, tu, tout] = await Promise.all(['humidity_living', 'humidity_upstairs', 'humidity_outdoor', 'temp_living', 'temp_upstairs', 'temp_outdoor'].map(m => L[m] ? summaryDays(m, from, to) : {}));
      const v = (m, d) => m[d] ? m[d].avg : null;
      const rel = [['Salon', hl, 'var(--s1)'], ['Étage', hu, 'var(--s3)'], ['Extérieur', hout, 'var(--s2)']]
        .map(([name, m, color]) => ({ name, color, values: days.map(d => v(m, d)) })).filter(s => s.values.some(x => x !== null));
      line($('c-hum'), labels, rel, { unit: '%', band: [40, 60], bandLabel: 'confort', dec: 0, aria: 'Humidité relative' });
      $('lg-hum').innerHTML = rel.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
      const abs = [['Salon', hl, tl, 'var(--s1)'], ['Étage', hu, tu, 'var(--s3)'], ['Extérieur', hout, tout, 'var(--s2)']]
        .map(([name, h, t, color]) => ({ name, color, values: days.map(d => v(h, d) !== null && v(t, d) !== null ? absHum(v(t, d), v(h, d)) : null) }))
        .filter(s => s.values.some(x => x !== null));
      line($('c-abs'), labels, abs, { unit: 'g/m³', aria: 'Humidité absolue', emptyText: 'Il faut la température et l’humidité au même endroit.' });
      $('lg-abs').innerHTML = abs.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
    }
    onControls('hum-ctl', ds => load(+ds.d).catch(failed($('c-hum'))));
    await load(30);
  }

  // =============== Comparer ===============
  async function compare() {
    const c = await api('compare');
    const years = [...new Set(c.months.map(m => +m.month.slice(0, 4)))].sort((a, b) => b - a);
    if (!years.length) return empty($('c-ym'));
    const color = {}; years.forEach((y, i) => { color[y] = COLORS[i % 8]; });
    // Un mois incomplet (mois en cours, ou début de l'historique) n'est pas tracé : il fausserait la comparaison.
    const complete = m => m.days >= new Date(+m.month.slice(0, 4), +m.month.slice(5, 7), 0).getDate() - 1;
    const byYear = key => { const o = {}; years.forEach(y => { o[y] = Array(12).fill(null); }); c.months.forEach(m => { if (complete(m) || key === 'temp') o[+m.month.slice(0, 4)][+m.month.slice(5, 7) - 1] = complete(m) ? m[key] : null; }); return o; };
    const kwh = byYear('kwh'), temp = byYear('temp');
    const shown = new Set(years.slice(0, 3));
    const cur = years[0], curMonths = c.months.filter(m => +m.month.slice(0, 4) === cur);
    // Dernier mois complet de l'année en cours (pour comparer à périmètre égal).
    const lastMonth = curMonths.reduce((a, m) => { const n = +m.month.slice(5, 7); const full = m.days >= new Date(cur, n, 0).getDate(); return full ? Math.max(a, n) : a; }, 0) || 12;

    function render() {
      const ys = years.filter(y => shown.has(y));
      const mk = data => ys.map(y => ({ name: String(y), color: color[y], values: data[y], width: y === cur ? 2.5 : 2 }));
      line($('c-ym'), MOIS, mk(kwh), { unit: 'kWh', min: 0, dec: 0, aria: 'Consommation par mois et par année' });
      line($('c-yt'), MOIS, mk(temp), { unit: '°C', aria: 'Température extérieure par mois et par année' });
      const lg = ys.map(y => `<span><i style="background:${color[y]}"></i>${y}</span>`).join('');
      $('lg-ym').innerHTML = lg; $('lg-yt').innerHTML = lg;
      const sum = (a, n) => a.slice(0, n).reduce((x, v) => x + (v || 0), 0);
      const rows = years.map(y => ({ y, part: sum(kwh[y], lastMonth), full: kwh[y].every(v => v !== null) ? sum(kwh[y], 12) : null, t: temp[y].slice(0, lastMonth).filter(v => v !== null) }));
      $('t-yr').innerHTML = `<table><tr><th>Année</th><th>${MOIS[0]} à ${MOIS[lastMonth - 1]}</th><th>Écart vs n-1</th><th>Année entière</th><th>Temp. moy. ${MOIS[0]}–${MOIS[lastMonth - 1]}</th></tr>${rows.map((r, i) => {
        const p = rows[i + 1], dlt = p && p.part ? (r.part - p.part) / p.part * 100 : null;
        const tm = r.t.length ? r.t.reduce((a, b) => a + b, 0) / r.t.length : null;
        if (!r.part) return `<tr><td>${r.y}</td><td>–</td><td></td><td>incomplète</td><td>–</td></tr>`;
        return `<tr><td>${r.y}</td><td>${fmt(r.part, 0)} kWh</td><td>${dlt === null ? '' : (dlt > 0 ? '+' : '') + fmt(dlt) + ' %'}</td><td>${r.full === null ? (r.y === cur ? 'en cours' : 'incomplète') : fmt(r.full, 0) + ' kWh'}</td><td>${tm === null ? '–' : fmt(tm) + ' °C'}</td></tr>`;
      }).join('')}</table>`;
      renderScatter(ys);
    }

    function renderScatter(ys) {
      const pts = c.days.filter(p => shown.has(+p[0].slice(0, 4)) && p[2] > 2 && p[2] < 70).map(p => ({ x: p[1], y: p[2], color: color[+p[0].slice(0, 4)], title: `${p[0]} : ${fmt(p[1])} °C, ${fmt(p[2])} kWh` }));
      if (pts.length < 20) { empty($('c-sc')); $('corr').innerHTML = ''; return; }
      // Modèle « en coude » : kWh = base + pente x max(0, seuil - T). Seuil cherché entre 10 et 20 °C.
      let best = null;
      for (let th = 10; th <= 20; th += 0.5) {
        const xs = pts.map(p => Math.max(0, th - p.x)), n = pts.length;
        const mx = xs.reduce((a, b) => a + b, 0) / n, my = pts.reduce((a, p) => a + p.y, 0) / n;
        const sxx = xs.reduce((a, x) => a + (x - mx) ** 2, 0); if (!sxx) continue;
        const slope = xs.reduce((a, x, i) => a + (x - mx) * (pts[i].y - my), 0) / sxx, base = my - slope * mx;
        const sse = xs.reduce((a, x, i) => a + (pts[i].y - base - slope * x) ** 2, 0);
        if (!best || sse < best.sse) best = { th, slope, base, sse };
      }
      const n = pts.length, mX = pts.reduce((a, p) => a + p.x, 0) / n, mY = pts.reduce((a, p) => a + p.y, 0) / n;
      const r = pts.reduce((a, p) => a + (p.x - mX) * (p.y - mY), 0) / Math.sqrt(pts.reduce((a, p) => a + (p.x - mX) ** 2, 0) * pts.reduce((a, p) => a + (p.y - mY) ** 2, 0));
      const xmin = Math.min(...pts.map(p => p.x)), xmax = Math.max(...pts.map(p => p.x));
      const fit = [[xmin, best.base + best.slope * Math.max(0, best.th - xmin)], [best.th, best.base], [xmax, best.base]];
      scatter($('c-sc'), pts, { fit, xLabel: 'température extérieure', aria: 'Consommation du jour selon la température extérieure' });
      $('lg-sc').innerHTML = ys.map(y => `<span><i style="background:${color[y]};border-radius:50%"></i>${y}</span>`).join('') + '<span><i style="background:none;border-top:2px dashed var(--fg);height:0;border-radius:0"></i>Tendance</span>';
      $('corr').innerHTML = tile('Par degré en moins', '+' + fmt(best.slope, 2), 'kWh/jour', `sous ${fmt(best.th)} °C de moyenne journalière, chaque degré perdu ajoute autant`)
        + tile('Consommation hors chauffage', fmt(best.base), 'kWh/jour', `jours à plus de ${fmt(best.th)} °C`)
        + tile('Corrélation', fmt(r, 2), '', `sur ${n} jours complets ; plus c’est proche de −1, plus la température explique la consommation`);
    }

    $('yr-ctl').innerHTML = years.map(y => `<button type="button" data-y="${y}" aria-pressed="${shown.has(y)}"><i style="display:inline-block;width:9px;height:9px;border-radius:2px;background:${color[y]};margin-right:5px"></i>${y}</button>`).join('');
    $('yr-ctl').addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      const y = +b.dataset.y;
      if (shown.has(y) && shown.size > 1) shown.delete(y); else shown.add(y);
      b.setAttribute('aria-pressed', shown.has(y)); render();
    });
    render();
    c.seasons = c.seasons.filter(x => x.days >= 30);
    $('t-dju').innerHTML = c.seasons.length ? `<table><tr><th>Hiver</th><th>Jours complets</th><th>DJU</th><th>kWh</th><th>kWh par DJU</th></tr>${c.seasons.map(s =>
      `<tr><td>${esc(s.season)}</td><td>${s.days}</td><td>${fmt(s.dju, 0)}</td><td>${fmt(s.kwh, 0)}</td><td>${fmt(s.kwh_per_dju, 2)}</td></tr>`).join('')}</table>` : '';
  }

  const pages = { accueil: home, electricite: electricity, chauffage: heating, temperatures, humidite: humidity, comparer: compare };
  const run = pages[document.body.dataset.page];
  if (run) run().catch(e => { console.error(e); const m = document.querySelector('main'); m.insertAdjacentHTML('afterbegin', '<div class="alert" role="alert"><span class="ico">!</span><div><b>Impossible de charger les données.</b> Recharger la page ; si ça persiste, regarder les journaux du conteneur php.</div></div>'); });
})();
