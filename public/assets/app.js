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
  const dayParam = d => d === '24h' || d === '7' ? d : +d;
  // ---- périodes : 24 h (toutes les 5 min), 7 jours (par heure), sinon par jour ----
  const wdFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, weekday: 'short' });
  const hourFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, hour: '2-digit' });
  const dayNumFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, day: 'numeric' });
  const iso = t => new Date(t).toISOString().slice(0, 19) + 'Z';
  /** p : '24h', '7' ou un nombre de jours. hourly : 24 h par heure (minimum et maximum). */
  function period(p, hourly = false) {
    if (p === '24h' || p === '7') {
      const step = p === '24h' && !hourly ? 300000 : 3600000, span = p === '24h' ? 86400000 : 7 * 86400000;
      const end = Math.floor(Date.now() / step) * step, slots = [];
      for (let t = end - span + step; t <= end; t += step) slots.push(t);
      const raw = step === 300000;
      return {
        step: raw ? 'raw' : 'hour', from: iso(slots[0]), to: iso(end + step), slots,
        labels: slots.map(t => raw ? timeFmt.format(new Date(t)) : (p === '7' ? wdFmt.format(new Date(t)) + ' ' : '') + hourFmt.format(new Date(t))),
        tips: slots.map(t => dayTimeFmt.format(new Date(t))),
        // Repères de l'axe : minuit sur 7 jours (« mar. 7 »), toutes les 3 heures sur 24 h (« 15 h »).
        major: slots.map(t => {
          const d = new Date(t), h = +hourFmt.format(d).slice(0, 2);
          if (raw && timeFmt.format(d).slice(3) !== '00') return null;
          if (p === '7') return h === 0 ? wdFmt.format(d) + ' ' + dayNumFmt.format(d) : null;
          return h % 3 === 0 ? h + ' h' : null;
        }),
        per: raw ? 'moyenne sur 5 minutes' : 'moyenne par heure',
      };
    }
    const to = today(), days = daysBetween(addDays(to, -(p - 1)), to);
    return { step: 'day', from: days[0], to, slots: days, labels: days.map(dayLabel), tips: days.map(d => dateFmt.format(new Date(d + 'T12:00:00Z'))), per: 'moyenne du jour' };
  }
  /**
   * Valeurs d'une mesure alignées sur les créneaux de la période : get('avg' | 'min' | 'max' | 'kwh').
   * minN : relevés minimum par jour pour garder l'énergie d'un jour (jour incomplet = trou).
   */
  /** extra : paramètres de plus pour l'API, par exemple { nonzero: 1 } pour ignorer les valeurs à 0. */
  async function fetchValues(metric, P, minN = 0, extra = {}) {
    const map = {};
    if (P.step === 'day') {
      const r = await api('summary', Object.assign({ metric, period: 'day', from: P.from, to: P.to }, extra));
      if (r) r.rows.forEach(x => { map[x.period] = { avg: x.avg, min: x.min, max: x.max, kwh: x.n >= minN ? x.energy_kwh : null }; });
    } else {
      const r = await api('series', Object.assign({ metric, step: P.step, from: P.from, to: P.to }, extra));
      const step = P.step === 'raw' ? 300000 : 3600000;
      if (r) r.points.forEach(x => {
        const k = Math.floor(Date.parse(x[0]) / step) * step;
        map[k] = P.step === 'raw' ? { avg: x[1], min: x[1], max: x[1], kwh: null } : { avg: x[1], min: x[2], max: x[3], kwh: x[4] / 1000 };
      });
    }
    return field => P.slots.map(k => map[k] && map[k][field] !== undefined ? map[k][field] : null);
  }
  const legend = (id, series) => { $(id).innerHTML = series.map(s => `<span><i style="background:${s.color}"></i>${esc(s.name)}</span>`).join(''); };
  /** Libellé de l'heure d'une mesure : « à 10:35 », ou la date si elle date d'avant aujourd'hui. */
  const at = ts => isoDay.format(new Date(ts)) === today() ? 'à ' + timeFmt.format(new Date(ts)) : 'le ' + dayTimeFmt.format(new Date(ts));
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
    /** Bouton de période : paramètres de /breakdown, unité des barres, durée et libellés. */
    function slices(p) {
      const t = today();
      if (p === '24h') {
        const end = Math.floor(Date.now() / 3600000) * 3600000;
        return { params: { period: 'hour', from: iso(end - 23 * 3600000), to: iso(end + 3600000) }, per: 'heure', span: '24 dernières heures',
          label: x => hourFmt.format(new Date(x)), tip: x => dayTimeFmt.format(new Date(x)) };
      }
      if (p === '7' || p === '30') return { params: { period: 'day', from: addDays(t, -(+p - 1)), to: t }, per: 'jour', span: `${p} derniers jours`, label: dayLabel };
      if (p === '12m') {
        const m = new Date(t.slice(0, 8) + '01T12:00:00Z'); m.setUTCMonth(m.getUTCMonth() - 11);
        return { params: { period: 'month', from: m.toISOString().slice(0, 10), to: t }, per: 'mois', span: '12 derniers mois', label: monthLabel };
      }
      return { params: { period: 'year', from: '2000-01-01', to: t }, per: 'année', span: 'depuis le début', label: x => x };
    }
    // La consommation et le coût demandent souvent la même période : une seule requête pour les deux.
    const asked = {};
    function breakdown(params) {
      const k = JSON.stringify(params), m = asked[k];
      if (m && Date.now() - m.t < 60000) return m.r;
      const r = api('breakdown', params);
      asked[k] = { t: Date.now(), r };
      r.catch(() => { delete asked[k]; });
      return r;
    }
    /** Une série par circuit, puis le reste et la part sans Shelly : en kWh, ou en euros si euros est vrai. */
    function parts(r, euros) {
      const c = euros ? 'circuits_cost' : 'circuits', rest = euros ? 'rest_cost' : 'rest';
      const series = r.circuits.map((m, k) => ({ name: m.label, color: COLORS[k % 8], values: r.rows.map(x => x[c][m.code] ?? 0) }));
      // Période où les Shelly ne répondaient pas : la différence n'est pas un vrai « reste ».
      const full = x => x.coverage !== null && x.coverage >= 0.9;
      series.push({ name: 'Reste', color: 'var(--faint)', values: r.rows.map(x => full(x) ? x[rest] ?? 0 : 0) });
      if (r.rows.some(x => !full(x) && x[rest])) series.push({ name: 'Shelly absents', color: 'var(--absent)', values: r.rows.map(x => full(x) ? 0 : x[rest] ?? 0) });
      return series;
    }
    const total = s => ({ name: s.name, color: s.color, value: s.values.reduce((a, b) => a + b, 0) });
    async function load(p) {
      const S = slices(p);
      $('elec-cap').textContent = `kWh par ${S.per}, ${S.span}`;
      const r = await breakdown(S.params);
      const labels = r.rows.map(x => S.label(x.period));
      const tips = S.tip && r.rows.map(x => S.tip(x.period));
      const series = parts(r, false);
      stacked($('c-elec'), labels, series, { unit: 'kWh', tips, aria: 'Consommation par circuit' });
      legend('lg-elec', series);
      $('t-elec').innerHTML = `<table><tr><th>Période</th>${series.map(s => `<th>${esc(s.name)}</th>`).join('')}<th>Total Linky</th></tr>${r.rows.map((x, i) => `<tr><td>${esc((tips || labels)[i])}</td>${series.map(s => `<td>${fmt(s.values[i])}</td>`).join('')}<td>${fmt(x.total)}</td></tr>`).join('')}</table>`;
      $('rep-cap').textContent = 'kWh par circuit, ' + S.span;
      hbars($('c-rep'), series.map(total), { unit: 'kWh', aria: 'Répartition par circuit' });
    }
    onControls('elec-ctl', ds => load(ds.p).catch(failed($('c-elec'))));

    // Coût : énergie au prix du kWh de chaque jour, plus l'abonnement s'il est saisi dans l'administration.
    // [tuile, période de comparaison, moyenne, heures par unité de la moyenne]
    const COST = {
      '24h': ['24 dernières heures', '24 h d’avant', 'Par heure en moyenne', 1],
      7: ['7 derniers jours', '7 jours d’avant', 'Par jour en moyenne', 24],
      30: ['30 derniers jours', '30 jours d’avant', 'Par jour en moyenne', 24],
      '12m': ['12 derniers mois', 'un an plus tôt', 'Par mois en moyenne', 730.5],
      y: ['Cette année', `${today().slice(0, 4) - 1} à la même date`, 'Par mois en moyenne', 730.5],
    };
    const eur = (v, d = 2) => v === null || v === undefined ? '–' : fmt(v, d) + '\u00a0€';
    async function loadCost(p) {
      const S = slices(p), [now, before, avg, hours] = COST[p], d = p === '12m' || p === 'y' ? 0 : 2;
      const [c, r] = await Promise.all([api('cost', { period: p }), breakdown(S.params)]);
      const cur = c.current, prev = c.previous;
      const pct = cur.eur !== null && prev.eur ? ` (${cur.eur >= prev.eur ? '+' : ''}${fmt((cur.eur - prev.eur) / prev.eur * 100, 0)}\u00a0%)` : '';
      const price = cur.kwh_price === null ? '' : Math.abs(cur.kwh_price - c.kwh_price) < 0.00001
        ? `kWh à ${eur(c.kwh_price, 4)}` : `kWh à ${eur(cur.kwh_price, 4)} en moyenne`;
      const tiles = [
        tile(now, fmt(cur.eur, d), '€', `${before} : ${prev.eur === null ? 'pas de données' : eur(prev.eur, d) + pct}`),
        tile('Consommation', fmt(cur.kwh, d ? 1 : 0), 'kWh', prev.kwh === null ? '' : `${before} : ${fmt(prev.kwh, d ? 1 : 0)} kWh`),
        tile(avg, fmt(cur.hours ? cur.eur / cur.hours * hours : null, 2), '€', price),
      ];
      if (cur.subscription_eur > 0) tiles.push(tile('Dont abonnement', fmt(cur.subscription_eur, d), '€', `${eur(c.subscription_month)} par mois`));
      $('cost-tiles').innerHTML = tiles.join('');

      const labels = r.rows.map(x => S.label(x.period));
      const tips = S.tip && r.rows.map(x => S.tip(x.period));
      // Couleurs neutres : celles de la palette désignent les circuits, à côté.
      const series = [{ name: 'Électricité', color: 'var(--fg)', values: r.rows.map(x => x.cost ?? 0) }];
      const sub = r.rows.some(x => x.subscription > 0);
      if (sub) series.push({ name: 'Abonnement', color: 'var(--muted)', values: r.rows.map(x => x.subscription) });
      $('cost-cap').textContent = `€ par ${S.per}, ${S.span}` + (c.subscription_month > 0 ? ', abonnement compris' : '. Abonnement non compté : à saisir dans l’administration');
      stacked($('c-cost'), labels, series, { unit: '€', dec: 2, tips, w: 360, h: 200, aria: 'Coût par période' });
      legend('lg-cost', sub ? series : []);
      $('t-cost').innerHTML = `<table><tr><th>Période</th><th>kWh</th><th>Électricité</th>${sub ? '<th>Abonnement</th><th>Total</th>' : ''}</tr>${r.rows.map((x, i) =>
        `<tr><td>${esc((tips || labels)[i])}</td><td>${fmt(x.total)}</td><td>${eur(x.cost)}</td>${sub ? `<td>${eur(x.subscription)}</td><td>${eur((x.cost ?? 0) + x.subscription)}</td>` : ''}</tr>`).join('')}</table>`;
      $('costrep-cap').textContent = '€ par circuit, ' + S.span;
      hbars($('c-costrep'), parts(r, true).map(total), { unit: '€', dec: d, aria: 'Coût par circuit' });
    }
    onControls('cost-ctl', ds => loadCost(ds.p).catch(failed($('c-cost'))));

    // Compteur Linky : index, puissance apparente et tension.
    const dash = api('dashboard');
    async function loadLinky(p) {
      const P = period(p), daily = P.step === 'day', o = { tips: P.tips, major: P.major, zoomGroup: 'linky' };
      const [va, volt, idx] = await Promise.all(['elec_power', 'elec_voltage', 'elec_index'].map(m => fetchValues(m, P)));
      $('va-unit').textContent = P.step === 'raw' ? 'VA, moyenne sur 5 minutes' : `VA, moyenne et maximum ${daily ? 'du jour' : 'par heure'}`;
      const vaS = P.step === 'raw' ? [{ name: 'Moyenne', color: 'var(--s1)', values: va('avg') }]
        : [{ name: 'Moyenne', color: 'var(--s1)', values: va('avg') }, { name: 'Maximum', color: 'var(--s2)', values: va('max') }];
      line($('c-va'), P.labels, vaS, Object.assign({ unit: 'VA', min: 0, dec: 0, area: true, aria: 'Puissance apparente' }, o));
      legend('lg-va', vaS);
      $('volt-unit').textContent = P.step === 'raw' ? 'V, moyenne sur 5 minutes' : `V, moyenne, minimum et maximum ${daily ? 'du jour' : 'par heure'}`;
      const vS = P.step === 'raw' ? [{ name: 'Tension', color: 'var(--s1)', values: volt('avg') }]
        : [{ name: 'Maximum', color: 'var(--s2)', values: volt('max') }, { name: 'Moyenne', color: 'var(--s1)', values: volt('avg') }, { name: 'Minimum', color: 'var(--s3)', values: volt('min') }];
      line($('c-volt'), P.labels, vS, Object.assign({ w: 360, h: 200, unit: 'V', dec: 0, aria: 'Tension du réseau' }, o));
      legend('lg-volt', vS);
      $('idx-unit').textContent = 'kWh, ' + (P.step === 'raw' ? 'relevé toutes les 5 minutes' : daily ? 'en fin de journée' : 'en fin d’heure');
      line($('c-idx'), P.labels, [{ name: 'Index', color: 'var(--s1)', values: idx(P.step === 'raw' ? 'avg' : 'max') }], Object.assign({ w: 360, h: 200, unit: 'kWh', dec: 0, aria: 'Index du compteur Linky' }, o));
    }
    onControls('linky-ctl', ds => loadLinky(dayParam(ds.d)).catch(failed($('c-va'))));
    const L = (await dash).latest, lt = [];
    if (L.elec_index) lt.push(tile('Index Linky', fmt(L.elec_index.value, 0), 'kWh', 'relevé ' + at(L.elec_index.ts)));
    if (L.elec_power) lt.push(tile('Puissance apparente', fmt(L.elec_power.value, 0), 'VA', 'mesurée ' + at(L.elec_power.ts)));
    if (L.elec_voltage) lt.push(tile('Tension', fmt(L.elec_voltage.value, 0), 'V', 'mesurée ' + at(L.elec_voltage.ts)));
    $('linky-tiles').innerHTML = lt.join('');
    await Promise.all([load('30'), loadCost('30').catch(failed($('c-cost'))), loadLinky(30)]);
    const p = await prof;
    line($('c-prof'), p.kw.map((_, h) => h + ' h'), [{ name: 'Moyenne', color: 'var(--s1)', values: p.kw }], { w: 360, h: 200, unit: 'kW', min: 0, area: true, dec: 2, aria: 'Profil horaire moyen' });
  }

  // =============== Chauffage ===============
  async function heating() {
    async function load(p) {
      const P = period(p), daily = P.step === 'day', o = { tips: P.tips, major: P.major, zoomGroup: 'chauffage' };
      const [pac, ext, extPac] = await Promise.all([fetchValues('circuit_geothermie', P, 144), fetchValues('temp_outdoor', P), L.pac_exterieur_temp ? fetchValues('pac_exterieur_temp', P) : null]);
      // Par jour : kWh du jour (jours où le Shelly répondait). Sur 24 h ou 7 jours : puissance moyenne en kW.
      $('pac-unit').textContent = daily ? 'kWh par jour (circuit Géothermie, jours où le Shelly répondait)' : `kW, ${P.per} (circuit Géothermie)`;
      $('ext-unit').textContent = '°C, ' + P.per;
      const pacValues = daily ? pac('kwh') : pac('avg').map(v => v === null ? null : v / 1000);
      line($('c-pac'), P.labels, [{ name: 'PAC', color: 'var(--s1)', values: pacValues }], Object.assign({ w: 360, h: 190, unit: daily ? 'kWh' : 'kW', min: 0, area: true, dec: daily ? 1 : 2, aria: 'Consommation de la PAC' }, o));
      // Sonde Netatmo et sonde extérieure de la PAC, quand elle envoie.
      const extS = [{ name: 'Netatmo', color: 'var(--s2)', values: ext('avg') }];
      if (extPac) extS.push({ name: 'Sonde de la PAC', color: 'var(--s5)', values: extPac('avg') });
      line($('c-ext'), P.labels, extS, Object.assign({ w: 360, h: 190, unit: '°C', aria: 'Température extérieure moyenne' }, o));
      legend('lg-ext', extS.length > 1 ? extS : []);
      if (zones.length) {
        // Consigne à 0 = chauffage arrêté : ignorée, la ligne pointillée s'interrompt.
        const maps = await Promise.all(zones.map(([code]) => fetchValues(code, P, 0, code.includes('consigne') ? { nonzero: 1 } : {})));
        const series = zones.map(([, name, color, dash], i) => ({ name, color, dash, values: maps[i]('avg') }));
        $('zones-cap').textContent = `°C, ${P.per}. Température intérieure lue par la PAC (trait plein) et consigne (pointillés, absente quand le chauffage est arrêté).`;
        line($('c-zones'), P.labels, series, Object.assign({ unit: '°C', aria: 'Zones de chauffage de la PAC' }, o));
        legend('lg-zones', series);
      }
      if (press.length) {
        const maps = await Promise.all(press.map(([code]) => fetchValues(code, P)));
        const series = press.map(([, name, color], i) => ({ name, color, values: maps[i]('avg') }));
        $('press-cap').textContent = 'bar, ' + P.per;
        line($('c-press'), P.labels, series, Object.assign({ unit: 'bar', dec: 2, aria: 'Pressions d’eau de la PAC' }, o));
        legend('lg-press', series);
      }
      if (water.length) {
        const maps = await Promise.all(water.map(([code]) => fetchValues(code, P)));
        const series = water.map(([, name, color], i) => ({ name, color, values: maps[i]('avg') }));
        $('pac-cap').textContent = `°C, ${P.per}. Dernière lecture il y a ${ago(newest)}.`;
        line($('c-water'), P.labels, series, Object.assign({ unit: '°C', aria: 'Températures d’eau de la PAC' }, o));
        legend('lg-water', series);
      }
    }
    // Températures d'eau de la PAC (Arkteos) : seulement les mesures déjà reçues.
    const WATER = [['pac_primaire_temp_eau_aller', 'Départ', 'var(--s1)'], ['pac_primaire_temp_eau_retour', 'Retour', 'var(--s3)'],
      ['pac_ecs_temp_eau_milieu', 'Ballon milieu', 'var(--s2)'], ['pac_ecs_temp_eau_bas', 'Ballon bas', 'var(--s4)']];
    const L = (await api('dashboard')).latest;
    const ZONES = [['pac_zone1_temp_interieur', 'Zone 1', 'var(--s1)'], ['pac_zone1_consigne', 'Consigne zone 1', 'var(--s1)', '5 4'],
      ['pac_zone2_temp_interieur', 'Zone 2', 'var(--s2)'], ['pac_zone2_consigne', 'Consigne zone 2', 'var(--s2)', '5 4']];
    const consigneNote = c => !c ? '' : c.value === 0 ? 'chauffage arrêté' : 'consigne ' + fmt(c.value) + ' °C';
    const PRESS = [['pac_primaire_pression', 'Primaire (dedans)', 'var(--s1)'], ['pac_externe_pression', 'Captage (dehors)', 'var(--s3)']];
    const water = WATER.filter(([code]) => L[code]);
    const zones = ZONES.filter(([code]) => L[code]), press = PRESS.filter(([code]) => L[code]);
    $('zones-box').hidden = !zones.length; $('press-box').hidden = !press.length;
    const newest = water.length ? water.map(([code]) => L[code].ts).sort().pop() : null;
    if (water.length) {
      const tiles = water.map(([code, name]) => tile(name, fmt(L[code].value), '°C'));
      for (const [code, name] of ZONES) if (L[code] && !code.includes('consigne')) tiles.push(tile('Intérieur ' + name.toLowerCase(), fmt(L[code].value), '°C', consigneNote(L[code.replace('temp_interieur', 'consigne')])));
      for (const [code, name] of PRESS) if (L[code]) tiles.push(tile('Pression ' + name.toLowerCase(), fmt(L[code].value, 1), 'bar'));
      if (L.pac_exterieur_temp) tiles.push(tile('Sonde extérieure PAC', fmt(L.pac_exterieur_temp.value), '°C'));
      $('pac-tiles').innerHTML = tiles.join('');
    }
    onControls('heat-ctl', ds => load(dayParam(ds.d)).catch(failed($('c-pac'))));
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
    async function load(p) {
      const P = period(p), H = p === '24h' ? period(p, true) : P; // minimum et maximum : par heure sur 24 h
      const [lv, up, out, outH] = await Promise.all([
        fetchValues('temp_living', P), fetchValues('temp_upstairs', P), fetchValues('temp_outdoor', P), H === P ? null : fetchValues('temp_outdoor', H)]);
      $('temp-unit').textContent = '°C, ' + P.per;
      $('minmax-unit').textContent = P.step === 'day' ? '°C par jour' : '°C par heure';
      const series = [['Salon', lv, 'var(--s1)'], ['Étage', up, 'var(--s3)'], ['Extérieur', out, 'var(--s2)']]
        .map(([name, m, color]) => ({ name, color, values: m('avg') }))
        .filter(s => s.values.some(v => v !== null));
      line($('c-temp'), P.labels, series, { unit: '°C', tips: P.tips, major: P.major, aria: 'Températures moyennes' });
      $('lg-temp').innerHTML = series.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
      const o = outH || out;
      const mm = [{ name: 'Maximum', color: 'var(--s2)', values: o('max') }, { name: 'Minimum', color: 'var(--s1)', values: o('min') }];
      line($('c-minmax'), H.labels, mm, { unit: '°C', tips: H.tips, major: H.major, aria: 'Minimum et maximum extérieurs' });
      const hpa = await fetchValues('pressure_outdoor', P);
      $('hpa-unit').textContent = 'hPa, ' + P.per;
      line($('c-hpa'), P.labels, [{ name: 'Pression', color: 'var(--s4)', values: hpa('avg') }], { unit: 'hPa', dec: 0, tips: P.tips, major: P.major,
        aria: 'Pression atmosphérique', emptyText: 'Pas encore de pression reçue : l’entité Netatmo est à relayer dans l’add-on (pressure_outdoor, en hPa).' });
      $('lg-minmax').innerHTML = mm.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
    }
    onControls('temp-ctl', ds => load(dayParam(ds.d)).catch(failed($('c-temp'))));
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
    async function load(p) {
      const P = period(p), none = () => P.slots.map(() => null);
      // Seules les mesures déjà reçues sont demandées.
      const [hl, hu, hout, tl, tu, tout] = (await Promise.all(['humidity_living', 'humidity_upstairs', 'humidity_outdoor', 'temp_living', 'temp_upstairs', 'temp_outdoor']
        .map(m => L[m] ? fetchValues(m, P) : null))).map(f => f ? f('avg') : none());
      $('hum-unit').textContent = `%, ${P.per}. Bande verte : zone de confort 40 à 60 %`;
      const o = { tips: P.tips, major: P.major, zoomGroup: 'humidite' };
      const rel = [['Salon', hl, 'var(--s1)'], ['Étage', hu, 'var(--s3)'], ['Extérieur', hout, 'var(--s2)']]
        .map(([name, values, color]) => ({ name, color, values })).filter(s => s.values.some(x => x !== null));
      line($('c-hum'), P.labels, rel, Object.assign({ unit: '%', band: [40, 60], bandLabel: 'confort', dec: 0, aria: 'Humidité relative' }, o));
      $('lg-hum').innerHTML = rel.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
      const abs = [['Salon', hl, tl, 'var(--s1)'], ['Étage', hu, tu, 'var(--s3)'], ['Extérieur', hout, tout, 'var(--s2)']]
        .map(([name, h, t, color]) => ({ name, color, values: h.map((x, i) => x !== null && t[i] !== null ? absHum(t[i], x) : null) }))
        .filter(s => s.values.some(x => x !== null));
      line($('c-abs'), P.labels, abs, Object.assign({ unit: 'g/m³', aria: 'Humidité absolue', emptyText: 'Il faut la température et l’humidité au même endroit.' }, o));
      const co2 = L.co2_living ? await fetchValues('co2_living', P) : null;
      $('co2-unit').textContent = `ppm, ${P.per}. Bande verte : air sain, sous 1 000 ppm ; au-delà, aérer.`;
      line($('c-co2'), P.labels, co2 ? [{ name: 'CO₂ salon', color: 'var(--s5)', values: co2('avg') }] : [],
        Object.assign({ unit: 'ppm', min: 400, dec: 0, band: [400, 1000], bandLabel: 'air sain', aria: 'CO₂ du salon' }, o));
      $('lg-abs').innerHTML = abs.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
    }
    onControls('hum-ctl', ds => load(dayParam(ds.d)).catch(failed($('c-hum'))));
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
      // Tendance tracée seulement sur l'étendue des points : le coude n'apparaît que s'il tombe dedans.
      const model = t => best.base + best.slope * Math.max(0, best.th - t);
      const fit = [xmin, Math.min(Math.max(best.th, xmin), xmax), xmax].map(t => [t, model(t)]);
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

  // =============== Météo ===============
  // Vent observé (rose des vents avec les pistes, flèche du vent, manche à air), périodes d'aujourd'hui et de demain,
  // METAR et TAF. Les calculs (moyennes, vent de travers, pastilles) sont faits par le serveur (src/Weather.php).
  const CARD16 = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSO', 'SO', 'OSO', 'O', 'ONO', 'NO', 'NNO'];
  const LEVELS = { ok: 'Dans les limites', warn: 'Proche des limites', bad: 'Hors limites' };
  const kmh = v => v === null || v === undefined ? '–' : fmt(v, 0);
  const deg = d => d === null || d === undefined ? 'variable' : String(Math.round(d) % 360).padStart(3, '0') + '°';
  const card16 = d => d === null || d === undefined ? '' : CARD16[Math.round(d / 22.5) % 16];
  const rwyNum = r => String(Math.round(r / 10) || 36).padStart(2, '0');
  const side = c => c > 0 ? 'de droite' : c < 0 ? 'de gauche' : '';
  const hm = iso => iso ? timeFmt.format(new Date(iso)) : '–';
  const COVER = { FEW: 'Peu nombreux (1 à 2/8)', SCT: 'Épars (3 à 4/8)', BKN: 'Fragmentés (5 à 7/8)', OVC: 'Couvert (8/8)', VV: 'Ciel invisible' };
  const CLEAR = { CAVOK: 'CAVOK : pas de nuage sous 5 000 ft', NSC: 'Aucun nuage significatif', NCD: 'Aucun nuage détecté', SKC: 'Ciel clair', CLR: 'Ciel clair' };
  /** Hauteur de nuages en pieds, avec les mètres. */
  const feet = ft => ft === null || ft === undefined ? '–' : `${fmt(ft, 0)} ft (${fmt(Math.round(ft * 0.3048 / 10) * 10, 0)} m)`;
  const visi = v => v === null || v === undefined ? '–' : v >= 9999 ? '10 km ou plus' : v >= 5000 ? fmt(v / 1000, 0) + ' km' : fmt(v, 0) + ' m';
  /** Couches de nuages d'un METAR ou d'un groupe de TAF, une par ligne. */
  const layers = s => s.clear ? [CLEAR[s.clear] || s.clear] : s.layers.map(l =>
    `${COVER[l.cover]} à ${l.base === null ? '?' : feet(l.base)}${l.type === 'CB' ? ', cumulonimbus' : l.type === 'TCU' ? ', cumulus bourgeonnants' : ''}`);
  const lower = t => t.charAt(0).toLowerCase() + t.slice(1);
  /** Plafond, visibilité et temps prévus par le TAF sur une période. */
  function tafText(w) {
    if (!w) return '';
    const m = w.main, t = w.temp;
    let s = `plafond ${m.ceiling !== null ? fmt(m.ceiling, 0) + ' ft' : 'aucun'}`;
    if (m.visibility !== null && m.visibility < 9999) s += `, visibilité ${visi(m.visibility)}`;
    if (m.weather.length) s += ', ' + m.weather.map(lower).join(', ');
    if (t) {
      const bits = [];
      if (t.ceiling !== null) bits.push('plafond ' + fmt(t.ceiling, 0) + ' ft');
      if (t.visibility !== null) bits.push('visibilité ' + visi(t.visibility));
      bits.push(...t.weather.map(lower));
      if (bits.length) s += ` ; ${t.prob ? t.prob + ' % de risque, ' : ''}temporairement ${bits.join(', ')}`;
    }
    return s;
  }
  /** Petite flèche dans le sens où va le vent (il vient de d). */
  const arrow = d => d === null || d === undefined ? ''
    : `<svg class="wx-arr" viewBox="-10 -10 20 20" aria-hidden="true"><path transform="rotate(${(Math.round(d) + 180) % 360})" d="M0-8 5.5 5 0 2-5.5 5z"/></svg>`;

  /** Famille de pictogramme d'un code de temps de Météo Concept. */
  function skyKind(c) {
    if (c === null || c === undefined) return null;
    if ((c >= 100 && c <= 142)) return 'orage';
    if (c === 235) return 'grele';
    if (c === 6 || c === 7) return 'brouillard';
    if ((c >= 20 && c <= 32) || (c >= 60 && c <= 78) || (c >= 220 && c <= 232)) return 'neige';
    if (c >= 40 && c <= 48) return 'averses';
    if (c >= 10) return 'pluie';
    return ['soleil', 'peu', 'voile', 'nuageux', 'nuageux', 'couvert'][c] || 'nuageux';
  }
  /** Pictogramme du ciel, tracé au trait (couleur du texte du thème). La lune remplace le soleil la nuit. */
  function sky(c, night) {
    const k = skyKind(c); if (!k) return '';
    const cloud = (x = 0, y = 0, s = 1) => `<path transform="translate(${x} ${y}) scale(${s})" d="M9 25h14.5a5.5 5.5 0 0 0 .6-11A7.5 7.5 0 0 0 9.8 12.6 6.2 6.2 0 0 0 9 25z"/>`;
    const sun = (x, y, r) => night
      ? `<path d="M${x + r * .3} ${y - r}a${r} ${r} 0 1 0 ${r * .9} ${r * 1.55}A${r * .78} ${r * .78} 0 0 1 ${x + r * .3} ${y - r}z"/>`
      : `<circle cx="${x}" cy="${y}" r="${r}"/>` + [0, 45, 90, 135, 180, 225, 270, 315].map(a => {
        const c1 = Math.cos(a * Math.PI / 180), s1 = Math.sin(a * Math.PI / 180);
        return `<line x1="${(x + c1 * r * 1.5).toFixed(1)}" y1="${(y + s1 * r * 1.5).toFixed(1)}" x2="${(x + c1 * r * 2.1).toFixed(1)}" y2="${(y + s1 * r * 2.1).toFixed(1)}"/>`;
      }).join('');
    const drops = n => [11, 16, 21].slice(0, n).map(x => `<line x1="${x}" y1="27" x2="${x - 1.5}" y2="30.5"/>`).join('');
    const parts = {
      soleil: sun(16, 16, 6),
      peu: sun(11, 11, 4.2) + cloud(3, 3, .82),
      voile: sun(16, 13, 5.5) + '<line x1="5" y1="24" x2="27" y2="24"/><line x1="8" y1="28" x2="24" y2="28"/>',
      nuageux: cloud(0, -2),
      couvert: cloud(-4, -6, .85) + cloud(2, -1),
      brouillard: '<line x1="5" y1="12" x2="27" y2="12"/><line x1="3" y1="17" x2="29" y2="17"/><line x1="5" y1="22" x2="27" y2="22"/><line x1="8" y1="27" x2="24" y2="27"/>',
      pluie: cloud(0, -4) + drops(3),
      averses: sun(9, 9, 3.6) + cloud(3, -2, .9) + drops(2),
      neige: cloud(0, -4) + '<circle cx="11" cy="28" r="1.2"/><circle cx="16" cy="30" r="1.2"/><circle cx="21" cy="28" r="1.2"/>',
      grele: cloud(0, -4) + '<circle cx="11" cy="28.5" r="1.8"/><circle cx="18" cy="29" r="1.8"/>',
      orage: cloud(0, -5) + '<path class="wx-bolt" d="M17 20 13 26h3.5l-1.5 5 5-7h-3.5l1.5-4z"/>',
    };
    return `<svg class="wx-sky" viewBox="0 0 32 32" aria-hidden="true">${parts[k]}</svg>`;
  }

  /**
   * Rose des vents : pistes (vue de dessus, numéros à leurs seuils), flèche du vent venant de sa direction,
   * manche à air plantée à côté de la piste, du côté où va le vent, gonflée selon la force (pleine à 28 km/h).
   */
  function windRose(w, runways, level) {
    const C = 160, R = 128, rad = a => a * Math.PI / 180, f = n => n.toFixed(1);
    const pt = (a, r) => [C + r * Math.sin(rad(a)), C - r * Math.cos(rad(a))];
    const color = { ok: 'var(--ok)', warn: 'var(--warn)', bad: 'var(--danger)' }[level] || 'var(--fg)';
    let s = `<circle cx="${C}" cy="${C}" r="${R}" fill="none" stroke="var(--line)"/>`;
    for (let a = 0; a < 360; a += 10) {
      const [x1, y1] = pt(a, R), [x2, y2] = pt(a, a % 30 ? R - 6 : R - 12);
      s += `<line x1="${f(x1)}" y1="${f(y1)}" x2="${f(x2)}" y2="${f(y2)}" stroke="var(--faint)" stroke-width="${a % 90 ? 1 : 2}"/>`;
    }
    [['N', 0], ['E', 90], ['S', 180], ['O', 270]].forEach(([t, a]) => { const [x, y] = pt(a, R - 24); s += `<text x="${f(x)}" y="${f(y + 4)}" text-anchor="middle" class="wx-card">${t}</text>`; });
    // Une bande par axe de piste (07 et 25 forment un seul axe).
    const axes = [...new Set(runways.map(r => r % 180))];
    axes.forEach(ax => {
      const lo = runways.includes(ax) ? ax : ax + 180, hi = (lo + 180) % 360;
      s += `<g transform="rotate(${lo} ${C} ${C})"><rect x="${C - 14}" y="${C - 98}" width="28" height="196" rx="2" class="wx-rwy"/>`
        + `<line x1="${C}" y1="${C - 68}" x2="${C}" y2="${C + 68}" class="wx-axis"/>`;
      for (let i = 0; i < 4; i++) s += `<rect x="${C - 11 + i * 6.5}" y="${C - 94}" width="3" height="9" class="wx-mark"/><rect x="${C - 11 + i * 6.5}" y="${C + 85}" width="3" height="9" class="wx-mark"/>`;
      s += `<text x="${C}" y="${C + 80}" text-anchor="middle" class="wx-num">${rwyNum(lo)}</text>`
        + `<text x="${C}" y="${C - 72}" text-anchor="middle" class="wx-num" transform="rotate(180 ${C} ${C - 76})">${rwyNum(hi)}</text></g>`;
    });
    if (w && w.wind !== null) {
      const dir = w.dir === null ? 0 : w.dir, down = dir + 180;
      // Manche à air : du côté de la piste où va le vent, pour ne pas la croiser.
      const ax = axes.length ? axes[0] : 0, sides = [ax + 90, ax + 270];
      const dist = a => Math.abs(((a - down) % 360 + 540) % 360 - 180);
      const [mx, my] = pt(dist(sides[0]) < dist(sides[1]) ? sides[0] : sides[1], axes.length ? 58 : 0);
      const L = 74 * Math.max(.35, Math.min(1, w.wind / 28)), lit = Math.min(5, Math.round(w.wind / 5.6));
      s += `<g transform="translate(${f(mx)} ${f(my)}) rotate(${down - 90})">`;
      for (let i = 0; i < 5; i++) {
        const x0 = i * L / 5, x1 = (i + 1) * L / 5, w0 = 13 - 6 * i / 5, w1 = 13 - 6 * (i + 1) / 5, on = i < lit;
        s += `<polygon points="${f(x0)},${f(-w0)} ${f(x1)},${f(-w1)} ${f(x1)},${f(w1)} ${f(x0)},${f(w0)}" fill="${on ? (i % 2 ? 'var(--fg)' : color) : 'var(--faint)'}" fill-opacity="${on ? .95 : .35}" stroke="var(--bg)" stroke-width=".8"/>`;
      }
      s += `<circle r="3.5" fill="var(--fg)"/></g>`;
      if (w.dir !== null) {
        const [x0, y0] = pt(dir, 156), [x1, y1] = pt(dir, 112), [hx, hy] = pt(dir, 98), [lx, ly] = pt(dir - 7, 116), [rx, ry] = pt(dir + 7, 116);
        s += `<line x1="${f(x0)}" y1="${f(y0)}" x2="${f(x1)}" y2="${f(y1)}" stroke="${color}" stroke-width="4" stroke-linecap="round"/>`
          + `<polygon points="${f(hx)},${f(hy)} ${f(lx)},${f(ly)} ${f(rx)},${f(ry)}" fill="${color}"/>`;
      }
    }
    const label = w && w.wind !== null ? `Vent du ${deg(w.dir)}, ${kmh(w.wind)} km/h` : 'Pas de vent observé';
    return `<svg viewBox="0 0 320 320" role="img" aria-label="${esc(label)}">${s}</svg>`;
  }

  /** Ligne « piste conseillée, face, travers » d'une évaluation du serveur. */
  function runwayText(a) {
    const r = a && a.runway; if (!r) return '';
    const gc = r.gust_cross !== null && Math.abs(r.gust_cross) > Math.abs(r.cross) ? `, ${Math.abs(r.gust_cross)} en rafale` : '';
    return `piste ${rwyNum(r.runway)} : face ${r.head}, travers ${Math.abs(r.cross)}${gc}`;
  }

  function periodCard(p, night) {
    if (!p) return '<div class="wx-period past"><div class="empty">Pas de prévision</div></div>';
    const a = p.assess, gusts = p.gust_max !== null && p.gust_min !== null && p.gust_max - p.gust_min >= 5 ? ` <small>(${kmh(p.gust_min)} à ${kmh(p.gust_max)})</small>` : '';
    const rain = p.rain ? `pluie ${fmt(p.rain, 1)} mm` : 'pas de pluie';
    return `<div class="wx-period ${p.state} lv-${a.level}"${p.state === 'now' ? ' aria-current="true"' : ''}>`
      + `<div class="wx-ph"><span class="lbl">${esc(p.name)}${p.state === 'now' ? ' · en cours' : ''}</span><i class="wx-dot" title="${LEVELS[a.level]}"></i></div>`
      + `<div class="wx-sk">${sky(p.weather, night)}<span>${esc(p.label || '')}</span></div>`
      + `<div class="wx-wind">${arrow(p.dir)}<b>${deg(p.dir)}</b> ${kmh(p.wind)} <small>km/h</small></div>`
      + `<div class="wx-line">rafales ${kmh(p.gust)} km/h${gusts}</div>`
      + (a.runway ? `<div class="wx-line">${esc(runwayText(a))}</div>` : '')
      + `<div class="wx-line">${fmt(p.temp, 0)} °C · ${rain}${p.probarain !== null ? ` · risque ${fmt(p.probarain, 0)} %` : ''}</div>`
      + (p.taf ? `<div class="wx-line">TAF : ${esc(tafText(p.taf))}</div>` : '')
      + (a.reasons.length ? `<div class="wx-why">${esc(a.reasons.join(' · '))}</div>` : '')
      + '</div>';
  }

  async function weather() {
    const d = await api('weather');
    const lv = d.observed ? d.observed.assess.level : null;

    $('wx-problems').innerHTML = d.problems.length
      ? `<div class="alert" role="alert"><span class="ico" aria-hidden="true">!</span><div><b>Page incomplète.</b><ul>${d.problems.map(p => `<li>${esc(p)}</li>`).join('')}</ul></div></div>` : '';

    // ---- Vent observé ----
    $('wx-rose').innerHTML = windRose(d.observed, d.runways, lv);
    const o = d.observed;
    if (o) {
      $('wx-now-cap').textContent = `Moyenne de ${o.n} station${o.n > 1 ? 's' : ''}, pondérée par la distance à l’aéroclub. Dernier relevé ${at(o.ts)}.`;
      const r = o.assess.runway, now = d.days[0] && d.days[0].periods.find(p => p && p.state === 'now');
      const rows = [];
      if (r) {
        rows.push(['Piste conseillée', rwyNum(r.runway)], ['Vent de face', r.head + ' km/h'], ['Vent de travers', `${Math.abs(r.cross)} km/h ${side(r.cross)}`]);
        if (r.gust_cross !== null) rows.push(['Travers en rafale', Math.abs(r.gust_cross) + ' km/h']);
      }
      if (o.temp !== null) rows.push(['Température', fmt(o.temp) + ' °C']);
      if (now) rows.push(['Vent prévu en ce moment', `${deg(now.dir)}, ${kmh(now.wind)} km/h, rafales ${kmh(now.gust)}`]);
      $('wx-now').innerHTML = `<div class="lbl">Vent</div>`
        + `<div class="big">${deg(o.dir)}<small>${card16(o.dir)}</small> ${kmh(o.wind)}<small>km/h</small></div>`
        + `<div class="delta">rafales ${kmh(o.gust)} km/h${o.gust_max !== null && o.gust_max - (o.gust || 0) >= 5 ? `, jusqu’à ${kmh(o.gust_max)} selon les stations` : ''}</div>`
        + `<div class="wx-rows">${rows.map(([k, v]) => `<div><span>${esc(k)}</span><span>${esc(v)}</span></div>`).join('')}</div>`
        + `<div class="wx-level lv-${lv}"><i class="wx-dot"></i><span><b>${LEVELS[lv]}</b>${o.assess.reasons.length ? ' : ' + esc(o.assess.reasons.join(', ')) : ''}</span></div>`
        + `<p class="note">Limites : travers ${d.limits.cross_max} km/h (orange dès ${d.limits.cross_warn}), rafales ${d.limits.gust_max} km/h (orange dès ${d.limits.gust_warn}).</p>`;
    } else {
      $('wx-now-cap').textContent = d.stations.length ? 'Aucune station n’a de relevé de vent récent.' : 'Pas encore d’observation.';
      $('wx-now').innerHTML = '';
    }
    $('wx-st-cap').textContent = `Stations retenues dans un rayon de ${d.radius_km} km (réglables dans l’administration). Flèche : sens du vent ; poids : part dans la moyenne.`;
    $('wx-stations').innerHTML = d.stations.length ? `<table><tr><th>Station</th><th>Distance</th><th>Direction</th><th>Vent</th><th>Rafales</th><th>Temp.</th><th>Relevé</th><th>Poids</th></tr>${d.stations.map(s =>
      `<tr${s.used ? '' : ' class="muted"'}><td>${esc(s.name)}</td><td>${fmt(s.dist)} km</td><td>${arrow(s.dir)} ${s.dir !== null ? deg(s.dir) : '–'}</td><td>${kmh(s.wind)} km/h</td><td>${kmh(s.gust)} km/h</td>`
      + `<td>${s.temp !== null ? fmt(s.temp) + ' °C' : '–'}</td><td>${s.ts ? esc(at(s.ts)) : 'aucun'}</td><td>${s.used ? s.weight + ' %' : (s.wind === null ? 'sans vent' : 'trop ancien')}</td></tr>`).join('')}</table>`
      : '<p class="empty">Aucune station pour l’instant.</p>';

    // ---- Ciel : observé (METAR) et prévu pour la période en cours ----
    const m = d.metar, night = !!(d.sun && d.sun.night && d.sun.night.night);
    const cur = d.days[0] && d.days[0].periods.find(p => p && p.state === 'now');
    const skyBlock = (title, code, label, rows) => `<div class="wx-skyblock"><div class="lbl">${esc(title)}</div>`
      + `<div class="wx-skyhead">${sky(code, night)}<span>${esc(label || '–')}</span></div>`
      + `<div class="wx-rows">${rows.filter(r => r).map(([k, v]) => `<div><span>${esc(k)}</span><span>${v}</span></div>`).join('')}</div></div>`;
    let skyHtml = '';
    if (m && m.sky) {
      const k = m.sky;
      skyHtml += skyBlock(`Observé : METAR ${d.icao}${m.obs ? ' ' + at(m.obs) : ''}`, k.code, k.label, [
        ['Plafond', k.ceiling !== null ? esc(feet(k.ceiling)) : 'aucun'],
        ['Nuages', layers(k).map(esc).join('<br>') || '–'],
        ['Visibilité', esc(visi(k.visibility))],
        k.weather.length ? ['Temps présent', esc(k.weather.join(', '))] : null,
        ['Conditions', esc(m.category || '–')],
      ]);
    }
    if (cur) {
      skyHtml += skyBlock(`Prévu ${{ Nuit: 'cette nuit', Matin: 'ce matin', 'Après-midi': 'cet après-midi', Soir: 'ce soir' }[cur.name] || ''} : Météo Concept`, cur.weather, cur.label, [
        ['Pluie sur la période', cur.rain ? esc(fmt(cur.rain, 1)) + ' mm' : 'aucune'],
        ['Risque de pluie', cur.probarain !== null ? esc(fmt(cur.probarain, 0)) + ' %' : '–'],
        cur.probafog ? ['Risque de brouillard', esc(fmt(cur.probafog, 0)) + ' %'] : null,
        ['Température', esc(fmt(cur.temp, 0)) + ' °C'],
        cur.taf ? ['TAF', esc(tafText(cur.taf))] : null,
      ]);
    }
    $('wx-sky').innerHTML = skyHtml || '<p class="empty">Pas encore de données sur le ciel.</p>';

    // ---- Prévisions ----
    const sun = d.sun && d.sun.days, isNight = (day, p) => {
      if (!sun || !sun[day] || !p) return false;
      const mid = (Date.parse(p.start) + Date.parse(p.end)) / 2;
      return mid < Date.parse(sun[day].sunrise) || mid > Date.parse(sun[day].sunset);
    };
    [['wx-today', 0], ['wx-tomorrow', 1]].forEach(([id, i]) => {
      const day = d.days[i];
      $(id).innerHTML = day ? day.periods.map(p => periodCard(p, isNight(i, p))).join('') : '<p class="empty">Pas de prévision pour l’instant.</p>';
    });
    if (d.days[1]) $('wx-tomorrow-title').textContent = 'Demain, ' + new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(d.days[1].date + 'T12:00:00Z'));
    $('wx-hours-box').hidden = !d.hours.length;
    $('wx-hours').innerHTML = d.hours.length ? `<table><tr><th>Heure</th><th>Ciel</th><th>Vent</th><th>Rafales</th><th>Piste</th><th>Pluie</th><th></th></tr>${d.hours.map(h =>
      `<tr><td>${hm(h.ts)}</td><td class="wx-skycell">${sky(h.weather, false)}${esc(h.label || '')}</td><td>${arrow(h.dir)} ${deg(h.dir)} ${kmh(h.wind)} km/h</td><td>${kmh(h.gust)} km/h</td>`
      + `<td>${esc(runwayText(h.assess))}</td><td>${h.rain ? fmt(h.rain, 1) + ' mm' : '–'}</td><td><i class="wx-dot lv-${h.assess.level}" title="${esc(LEVELS[h.assess.level] + (h.assess.reasons.length ? ' : ' + h.assess.reasons.join(', ') : ''))}"></i></td></tr>`).join('')}</table>` : '';

    // ---- TAF et METAR ----
    const kt = w => !w ? '–' : `${w.dir === null || w.dir === 'VRB' ? 'VRB' : String(w.dir).padStart(3, '0') + '°'} ${w.kt} kt (${fmt(w.kt * 1.852, 0)} km/h)`
      + (w.gust_kt ? `, rafales ${w.gust_kt} kt (${fmt(w.gust_kt * 1.852, 0)} km/h)` : '');
    const TAF_TYPES = { BASE: 'Prévision', FM: 'À partir de', BECMG: 'Devient', TEMPO: 'Temporairement' };
    const when = iso => iso ? wdFmt.format(new Date(iso)) + ' ' + timeFmt.format(new Date(iso)) : '?';
    const t = d.taf;
    $('wx-taf-title').textContent = 'TAF' + (d.icao ? ' ' + d.icao : '');
    $('wx-taf-cap').textContent = t ? `Émis ${at(t.issued)}, valable du ${when(t.from)} au ${when(t.to)} (heures locales). Hauteurs des nuages au-dessus de l’aérodrome.` : '';
    $('wx-taf').innerHTML = !d.icao ? '<p class="note">Code OACI de l’aérodrome à saisir dans l’administration (section Tablette).</p>'
      : !t ? '<p class="note">Pas de TAF valide pour cet aérodrome en ce moment.</p>'
      : `<table class="wx-taf"><tr><th>Période</th><th>Évolution</th><th>Vent</th><th>Visibilité</th><th>Temps</th><th>Nuages</th></tr>${t.groups.map(g =>
        `<tr${g.to && Date.parse(g.to) <= Date.now() ? ' class="muted"' : ''}><td>${esc(when(g.from))} – ${esc(g.type === 'FM' && !g.to ? 'fin' : when(g.to))}</td><td>${g.prob ? g.prob + ' % de risque, ' + lower(TAF_TYPES[g.type]) : esc(TAF_TYPES[g.type] || g.type)}</td>`
        + `<td>${g.wind ? esc(kt(g.wind)) : '–'}</td><td>${g.visibility !== null ? esc(visi(g.visibility)) : '–'}</td>`
        + `<td class="wx-skycell">${g.code !== null ? sky(g.code, false) : ''}${esc(g.weather.length ? g.weather.join(', ') : (g.label || '–'))}</td>`
        + `<td class="wx-skycell">${layers(g).map(esc).join('<br>') || '–'}</td></tr>`).join('')}</table>`
        + `<details><summary>Texte du TAF</summary><pre class="wx-raw">${t.groups.map(g => esc(g.text)).join('\n')}</pre></details>`;
    $('wx-metar-title').textContent = 'METAR' + (d.icao ? ' ' + d.icao : '');
    $('wx-metar').innerHTML = !d.icao ? '<p class="note">Code OACI de l’aérodrome à saisir dans l’administration (section Tablette).</p>'
      : !m ? '<p class="note">METAR indisponible pour le moment.</p>'
      : `<div class="wx-rows">${[
        ['Observé', m.obs ? esc(at(m.obs)) : '–'],
        ['Conditions', esc(m.category || '–')],
        ['Vent', esc(kt(m.wind))],
        ['Visibilité', esc(visi(m.sky.visibility))],
        ['Nuages', layers(m.sky).map(esc).join('<br>') || '–'],
        ['QNH', m.qnh ? m.qnh + ' hPa' : '–'],
      ].map(([k, v]) => `<div><span>${esc(k)}</span><span>${v}</span></div>`).join('')}</div><pre class="wx-raw">${esc(m.raw)}</pre>`;

    // ---- Soleil ----
    if (sun) {
      const n = d.sun.night, t = sun[0], tm = sun[1];
      $('wx-sun').innerHTML = tile('Maintenant', n ? (n.night ? 'Nuit' : 'Jour') : '–', '', n ? (n.night ? 'jour aéronautique à ' : 'nuit aéronautique à ') + hm(n.until) : '')
        + tile('Lever', hm(t.sunrise), '', 'aube civile à ' + hm(t.dawn))
        + tile('Coucher', hm(t.sunset), '', 'nuit aéronautique à ' + hm(t.dusk))
        + tile('Demain', hm(tm.sunrise) + ' – ' + hm(tm.sunset), '', `jour aéronautique de ${hm(tm.dawn)} à ${hm(tm.dusk)}`);
    }

    const pts = d.points.filter(p => !p.dup), dup = d.points.length - pts.length, u = d.updated;
    $('wx-foot').textContent = (pts.length ? `Prévisions : moyenne de ${pts.length} point${pts.length > 1 ? 's' : ''} de grille de Météo Concept (${pts.map(p => p.label).join(', ')}), pondérée par la distance à l’aéroclub`
      + (dup ? ` ; ${dup} point${dup > 1 ? 's' : ''} tombant sur la même grille compté${dup > 1 ? 's' : ''} une seule fois` : '') + '. ' : '')
      + `Mis à jour : observations ${u.observations ? hm(u.observations) : '–'}, prévisions ${u.forecast ? hm(u.forecast) : '–'}. `
      + `Appels à Météo Concept aujourd’hui : ${d.calls.today} sur ${d.calls.quota} (arrêt à ${d.calls.stop_at}).`;
  }
  /** Page Météo : rechargée toutes les 5 minutes tant qu'elle est visible (le serveur garde les réponses en cache). */
  async function meteo() {
    await weather();
    setInterval(() => { if (document.visibilityState === 'visible') weather().catch(e => console.error(e)); }, 300000);
  }

  // Menu « burger » (petits écrans) : ouvert par le bouton, fermé par Échap ou un clic ailleurs.
  const nav = document.querySelector('nav'), burger = nav && nav.querySelector('.burger');
  if (burger) {
    const setOpen = open => { nav.classList.toggle('open', open); burger.setAttribute('aria-expanded', open); };
    burger.addEventListener('click', () => setOpen(!nav.classList.contains('open')));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && nav.classList.contains('open')) { setOpen(false); burger.focus(); } });
    document.addEventListener('click', e => { if (!nav.contains(e.target)) setOpen(false); });
  }

  const pages = { accueil: home, electricite: electricity, chauffage: heating, temperatures, humidite: humidity, meteo, comparer: compare };
  const run = pages[document.body.dataset.page];
  if (run) run().catch(e => { console.error(e); const m = document.querySelector('main'); m.insertAdjacentHTML('afterbegin', '<div class="alert" role="alert"><span class="ico">!</span><div><b>Impossible de charger les données.</b> Recharger la page ; si ça persiste, regarder les journaux du conteneur php.</div></div>'); });
})();
