#!/usr/bin/env node
// Dessine les fonds des thèmes (public/assets/img/fond-<thème>.jpg, et domotique-fond.jpg pour Domotique) :
// une scène futuriste en SVG (maison holographique, sol en grille, globe, cadrans), la même pour tous les thèmes,
// recolorée pour chacun. Le tirage aléatoire est fixe : relancer le script redonne les mêmes images.
// Rendu en JPEG 2560x1440 par Chromium (Playwright) : node bin/fonds.js [thème ...] (sans argument : tous).
'use strict';
const fs = require('fs');
const path = require('path');
const W = 1920, H = 1080;
const f = n => n.toFixed(1);

function scene(t) {
  let seed = 20261010;
  const rnd = () => { seed |= 0; seed = seed + 0x6D2B79F5 | 0; let x = Math.imul(seed ^ seed >>> 15, 1 | seed); x = x + Math.imul(x ^ x >>> 7, 61 | x) ^ x; return ((x ^ x >>> 14) >>> 0) / 4294967296; };
  const G = t.glow ? ' filter="url(#glow)"' : '', G2 = t.glow ? ' filter="url(#glow2)"' : '';
  const k = t.dim || 1; // atténuation générale des tracés
  const o = v => String(+(v * k).toFixed(3)).replace(/^0\./, '.');
  let s = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
<defs>
  <radialGradient id="bg" cx="50%" cy="58%" r="75%"><stop offset="0" stop-color="${t.bg[0]}"/><stop offset=".45" stop-color="${t.bg[1]}"/><stop offset="1" stop-color="${t.bg[2]}"/></radialGradient>
  <linearGradient id="floorFade" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity="0"/><stop offset=".25" stop-color="#fff" stop-opacity=".35"/><stop offset="1" stop-color="#fff" stop-opacity="1"/></linearGradient>
  <mask id="floorMask"><rect x="0" y="640" width="${W}" height="${H - 640}" fill="url(#floorFade)"/></mask>
  <linearGradient id="horizon" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="${t.hz[0]}" stop-opacity="0"/><stop offset=".3" stop-color="${t.hz[0]}" stop-opacity=".9"/><stop offset=".5" stop-color="${t.hz[1]}"/><stop offset=".7" stop-color="${t.hz[2]}" stop-opacity=".9"/><stop offset="1" stop-color="${t.hz[2]}" stop-opacity="0"/></linearGradient>
  <radialGradient id="glowC"><stop offset="0" stop-color="${t.main}" stop-opacity="${o(.55 * (t.halo ?? 1))}"/><stop offset="1" stop-color="${t.main}" stop-opacity="0"/></radialGradient>
  <radialGradient id="vig" cx="50%" cy="50%" r="72%"><stop offset=".55" stop-color="${t.vig[0]}" stop-opacity="0"/><stop offset="1" stop-color="${t.vig[0]}" stop-opacity="${t.vig[1]}"/></radialGradient>
  <linearGradient id="houseFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${t.house}" stop-opacity=".02"/><stop offset="1" stop-color="${t.house}" stop-opacity="${t.houseFill ?? .16}"/></linearGradient>
  <linearGradient id="beam" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${t.house}" stop-opacity="0"/><stop offset="1" stop-color="${t.house}" stop-opacity="${t.beam ?? .18}"/></linearGradient>
  <filter id="glow" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="4" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
  <filter id="glow2" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="9" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
  <filter id="bokeh"><feGaussianBlur stdDeviation="3"/></filter>
  <filter id="soft"><feGaussianBlur stdDeviation="40"/></filter>
  <filter id="beamBlur" x="-30%" y="-10%" width="160%" height="120%"><feGaussianBlur stdDeviation="22"/></filter>
  <pattern id="hex" width="56" height="97" patternUnits="userSpaceOnUse"><path d="M28 0 L56 16 L56 48 L28 64 L0 48 L0 16 Z M28 64 L28 97" fill="none" stroke="${t.hex[0]}" stroke-width="1"/></pattern>
  <radialGradient id="hexFade" cx="50%" cy="20%" r="65%"><stop offset="0" stop-color="#fff" stop-opacity=".9"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>
  <mask id="hexMask"><rect width="${W}" height="640" fill="url(#hexFade)"/></mask>
</defs>
<rect width="${W}" height="${H}" fill="url(#bg)"/>
`;
  // Nappes de lumière douces.
  const sf = t.soft;
  s += `<g filter="url(#soft)"><ellipse cx="420" cy="330" rx="420" ry="240" fill="${sf[0]}" opacity="${sf[1]}"/><ellipse cx="1560" cy="300" rx="380" ry="230" fill="${sf[2]}" opacity="${sf[3]}"/><ellipse cx="960" cy="660" rx="760" ry="120" fill="${sf[4]}" opacity="${sf[5]}"/></g>\n`;
  s += `<rect width="${W}" height="640" fill="url(#hex)" opacity="${t.hex[1]}" mask="url(#hexMask)"/>\n`;
  // Sol en perspective.
  const hy = 640, vx = 960, vy = 560;
  let floor = '';
  for (let i = -40; i <= 40; i++) { const xb = vx + i * 120, tt = (hy - vy) / (H - vy), xh = vx + (xb - vx) * tt; floor += `<line x1="${f(xh)}" y1="${hy}" x2="${f(xb)}" y2="${H}"/>`; }
  for (let q = 1; q <= 16; q++) { const y = hy + (H - hy) * Math.pow(q / 16, 1.9); floor += `<line x1="0" y1="${f(y)}" x2="${W}" y2="${f(y)}"/>`; }
  s += `<g mask="url(#floorMask)"><g stroke="${t.floor[0]}" stroke-width="1.4" opacity="${t.floor[1]}"${G}>${floor}</g></g>\n`;
  s += `<ellipse cx="960" cy="${hy}" rx="980" ry="46" fill="url(#glowC)"/><rect x="0" y="${hy - 1}" width="${W}" height="2.5" fill="url(#horizon)"${G}/>\n`;
  // Maison holographique.
  const hx = 960, base = hy - 2, hw = 190, wallTop = base - 190, apex = base - 330, HC = t.house;
  s += `<polygon points="${hx - 330},${H} ${hx + 330},${H} ${hx + hw},${base} ${hx - hw},${base}" fill="url(#beam)" opacity=".8" filter="url(#beamBlur)"/>\n`;
  s += `<g fill="url(#houseFill)" stroke="${HC}" stroke-width="2.4" stroke-linejoin="round"${G2} opacity="${o(.85)}">
  <path d="M${hx - hw} ${base} V${wallTop} L${hx} ${apex} L${hx + hw} ${wallTop} V${base} Z"/>
  <path d="M${hx - hw - 34} ${wallTop + 22} L${hx} ${apex - 26} L${hx + hw + 34} ${wallTop + 22}" fill="none"/>
  <rect x="${hx - 34}" y="${base - 112}" width="68" height="112" rx="4"/>
  <rect x="${hx - 150}" y="${base - 160}" width="74" height="60" rx="4"/><path d="M${hx - 113} ${base - 160} V${base - 100} M${hx - 150} ${base - 130} H${hx - 76}" fill="none"/>
  <rect x="${hx + 76}" y="${base - 160}" width="74" height="60" rx="4"/><path d="M${hx + 113} ${base - 160} V${base - 100} M${hx + 76} ${base - 130} H${hx + 150}" fill="none"/>
  <circle cx="${hx}" cy="${wallTop - 40}" r="22"/>
  <path d="M${hx - 6} ${wallTop - 52} L${hx - 12} ${wallTop - 36} H${hx + 2} L${hx - 4} ${wallTop - 24} L${hx + 12} ${wallTop - 44} H${hx - 2} L${hx + 4} ${wallTop - 52} Z" fill="${t.bolt}" stroke="${t.bolt}" stroke-width="1.2"/>
</g>
<g fill="none" stroke="${HC}" stroke-width="2.4" stroke-linecap="round"${G} opacity="${o(.75)}">
  <path d="M${hx - 40} ${apex - 50} A56 56 0 0 1 ${hx + 40} ${apex - 50}"/><path d="M${hx - 72} ${apex - 80} A100 100 0 0 1 ${hx + 72} ${apex - 80}"/><path d="M${hx - 104} ${apex - 110} A144 144 0 0 1 ${hx + 104} ${apex - 110}" opacity=".6"/>
</g>\n`;
  // Globe filaire.
  const gx = 300, gy = 400, gr = 200;
  let globe = `<circle cx="${gx}" cy="${gy}" r="${gr}"/>`;
  for (let a = 15; a < 180; a += 22.5) globe += `<ellipse cx="${gx}" cy="${gy}" rx="${f(gr * Math.abs(Math.cos(a * Math.PI / 180)))}" ry="${gr}"/>`;
  for (let lat = -60; lat <= 60; lat += 20) { const y = gy + gr * Math.sin(lat * Math.PI / 180), rx = gr * Math.cos(lat * Math.PI / 180); globe += `<ellipse cx="${gx}" cy="${f(y)}" rx="${f(rx)}" ry="${f(rx * 0.16)}"/>`; }
  s += `<g transform="rotate(-16 ${gx} ${gy})"><g fill="none" stroke="${t.globe[0]}" stroke-width="1.2" opacity="${o(.42)}"${G}>${globe}</g>`;
  const cities = [];
  for (let i = 0; i < 46; i++) { const th = rnd() * Math.PI * 2, ph = Math.acos(2 * rnd() - 1); const x = Math.sin(ph) * Math.cos(th), y = Math.cos(ph), z = Math.sin(ph) * Math.sin(th); if (z < 0.05) continue; cities.push([gx + gr * x, gy + gr * y]); }
  let links = '';
  for (let i = 0; i + 1 < cities.length; i += 3) { const [a, b] = [cities[i], cities[i + 1]]; const mx = (a[0] + b[0]) / 2, my = (a[1] + b[1]) / 2 - 60; links += `<path d="M${f(a[0])} ${f(a[1])} Q${f(mx)} ${f(my)} ${f(b[0])} ${f(b[1])}"/>`; }
  s += `<g fill="none" stroke="${t.globe[1]}" stroke-width="1.6" opacity="${o(.7)}"${G}>${links}</g>`;
  s += `<g${G}>${cities.map((c, i) => `<circle cx="${f(c[0])}" cy="${f(c[1])}" r="${i % 5 ? 2.4 : 4}" fill="${i % 4 ? t.globe[0] : t.globe[1]}"/>`).join('')}</g></g>\n`;
  s += `<ellipse cx="${gx}" cy="${gy + gr + 40}" rx="150" ry="14" fill="url(#glowC)"/>\n`;
  // Cadrans tête haute.
  const rx0 = 1630, ry0 = 380, R = t.hud;
  const arc = (r, a0, a1) => { const p = a => [rx0 + r * Math.cos(a * Math.PI / 180), ry0 + r * Math.sin(a * Math.PI / 180)]; const [x0, y0] = p(a0), [x1, y1] = p(a1); return `M${f(x0)} ${f(y0)} A${r} ${r} 0 ${a1 - a0 > 180 ? 1 : 0} 1 ${f(x1)} ${f(y1)}`; };
  let hud = '';
  for (const r of [70, 118, 182, 236]) hud += `<circle cx="${rx0}" cy="${ry0}" r="${r}" fill="none" stroke="${R[0]}" stroke-width="1" opacity="${o(.35)}"/>`;
  hud += `<circle cx="${rx0}" cy="${ry0}" r="270" fill="none" stroke="${R[0]}" stroke-width="1.5" stroke-dasharray="2 9" opacity="${o(.5)}"/>`;
  let ticks = '';
  for (let i = 0; i < 90; i++) { const a = i * 4 * Math.PI / 180, r1 = 240, r2 = i % 5 ? 250 : 262; ticks += `<line x1="${f(rx0 + r1 * Math.cos(a))}" y1="${f(ry0 + r1 * Math.sin(a))}" x2="${f(rx0 + r2 * Math.cos(a))}" y2="${f(ry0 + r2 * Math.sin(a))}"/>`; }
  hud += `<g stroke="${R[0]}" stroke-width="1.2" opacity="${o(.45)}">${ticks}</g>`;
  hud += `<g fill="none" stroke-linecap="round"${G}>
  <path d="${arc(150, -200, 40)}" stroke="${R[1]}" stroke-width="12" opacity="${o(.8)}"/>
  <path d="${arc(150, 48, 118)}" stroke="${R[2]}" stroke-width="12" opacity="${o(.85)}"/>
  <path d="${arc(208, -150, -30)}" stroke="${R[3]}" stroke-width="6" opacity="${o(.75)}"/>
  <path d="${arc(208, -20, 60)}" stroke="${R[4]}" stroke-width="6" opacity="${o(.75)}"/>
  <path d="${arc(94, 0, 260)}" stroke="${R[1]}" stroke-width="4" opacity="${o(.6)}"/>
</g>
<circle cx="${rx0}" cy="${ry0}" r="46" fill="${R[1]}" opacity="${o(.12)}"/><circle cx="${rx0}" cy="${ry0}" r="46" fill="none" stroke="${R[1]}" stroke-width="2" opacity="${o(.7)}"${G}/>`;
  s += hud + '\n';
  // Anneaux de jauge.
  const ring = (cx, cy, r, p, c) => { const a1 = -90 + 360 * p; const pt = a => [cx + r * Math.cos(a * Math.PI / 180), cy + r * Math.sin(a * Math.PI / 180)]; const [x1, y1] = pt(a1); return `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${t.track}" stroke-width="7" opacity=".18"/><path d="M${cx} ${cy - r} A${r} ${r} 0 ${p > .5 ? 1 : 0} 1 ${f(x1)} ${f(y1)}" fill="none" stroke="${c}" stroke-width="7" stroke-linecap="round" opacity="${o(.8)}"${G}/>`; };
  s += ring(1450, 900, 38, .63, t.rings[0]) + ring(1560, 900, 38, .39, t.rings[1]) + ring(1670, 900, 38, .52, t.rings[2]) + ring(1780, 900, 38, .77, t.rings[3]) + '\n';
  // Barres de données.
  const bw = [210, 300, 360, 240, 330, 270];
  s += `<g${G} opacity="${o(.7)}">${t.bars.map((c, i) => `<rect x="120" y="${820 + i * 26}" width="${bw[i]}" height="9" rx="4.5" fill="${c}"/>`).join('')}</g>\n`;
  // Pistes de circuit.
  let traces = '';
  for (let i = 0; i < 26; i++) {
    const left = i % 2 === 0;
    let x = left ? 0 : W, y = 30 + rnd() * 560;
    let d = `M${f(x)} ${f(y)}`;
    const dir = left ? 1 : -1, segs = 2 + Math.floor(rnd() * 3);
    for (let q = 0; q < segs; q++) {
      const len = 40 + rnd() * 160;
      if (q % 2 === 0) x += dir * len; else { const dy = (rnd() < .5 ? -1 : 1) * (20 + rnd() * 50); x += dir * Math.abs(dy); y += dy; }
      d += ` L${f(x)} ${f(y)}`;
    }
    const c = rnd() < .2 ? t.traces[1] : t.traces[0];
    traces += `<path d="${d}" stroke="${c}"/><circle cx="${f(x)}" cy="${f(y)}" r="4" stroke="${c}" fill="${t.node}"/>`;
  }
  s += `<g fill="none" stroke-width="1.6" opacity="${o(.55)}"${G}>${traces}</g>\n`;
  // Particules.
  let parts = '';
  for (let i = 0; i < 90; i++) { const x = rnd() * W, y = rnd() * H * 0.95, r = 1 + rnd() * (i < 15 ? 9 : 3); const c = t.parts[Math.floor(rnd() * 5)]; const op = .15 + rnd() * .5; parts += `<circle cx="${f(x)}" cy="${f(y)}" r="${f(r)}" fill="${c}" opacity="${f(op * (t.partsK ?? 1))}"/>`; }
  if ((t.partsK ?? 1) > 0) s += `<g filter="url(#bokeh)">${parts}</g>\n`;
  s += `<rect width="${W}" height="${H}" fill="url(#vig)"/>\n`;
  if (t.veil) s += `<rect width="${W}" height="${H}" fill="${t.veil[0]}" opacity="${t.veil[1]}"/>\n`;
  return s + '</svg>\n';
}

const CY = '#22c4ea', CY2 = '#1fa8c9', OR = '#f0913b', CO = '#d9393b', TE = '#17a196';
const T = {
  // Domotique : image d'origine, dessinée d'après la photo d'Alain (cyan, orange, corail, sable, sarcelle).
  domotique: { bg: ['#0d2230', '#07121a', '#020407'], soft: [CY2, .16, OR, .10, CY, .22], hex: [CY, .07], floor: [CY, .55], hz: [CY, '#bff3ff', OR], main: CY,
    vig: ['#000', .75], house: CY, bolt: OR, globe: [CY, OR], hud: [CY, CY, OR, CO, TE], track: '#9fb0b8', rings: [CY, TE, OR, CO],
    bars: [CO, OR, '#e8c98a', '#e8c98a', CY, TE], traces: [CY, OR], node: '#07121a', parts: [CY, CY, CY, OR, '#ffffff'], glow: true },
  jarvis: { bg: ['#0b2a44', '#051626', '#01060c'], soft: ['#2f7dff', .18, '#00d4ff', .10, '#00d4ff', .22], hex: ['#00d4ff', .08], floor: ['#00b4f0', .55], hz: ['#2f7dff', '#d6f4ff', '#00d4ff'], main: '#00d4ff',
    vig: ['#000', .75], house: '#00d4ff', bolt: '#ffb347', globe: ['#00d4ff', '#ffb347'], hud: ['#00d4ff', '#00d4ff', '#ffb347', '#2f7dff', '#00b39b'], track: '#86bed2', rings: ['#00d4ff', '#2f7dff', '#ffb347', '#00b39b'],
    bars: ['#2f7dff', '#00d4ff', '#c4f3ff', '#ffb347', '#00b39b', '#00a8e0'], traces: ['#00d4ff', '#ffb347'], node: '#051626', parts: ['#00d4ff', '#00d4ff', '#2f7dff', '#ffb347', '#ffffff'], glow: true },
  ironman: { bg: ['#2a0c10', '#140608', '#050102'], soft: ['#c8202a', .24, '#f2b630', .10, '#f2b630', .16], hex: ['#f2b630', .06], floor: ['#e2222b', .6], hz: ['#e2222b', '#ffe2a0', '#f2b630'], main: '#f2b630',
    vig: ['#000', .75], house: '#f2b630', bolt: '#4fd8ff', globe: ['#f2b630', '#4fd8ff'], hud: ['#f2b630', '#f2b630', '#4fd8ff', '#e2222b', '#ff7a1f'], track: '#cfae80', rings: ['#f2b630', '#ff7a1f', '#4fd8ff', '#e2222b'],
    bars: ['#e2222b', '#ff7a1f', '#f2b630', '#ffe2a0', '#4fd8ff', '#c9ccd2'], traces: ['#f2b630', '#e2222b'], node: '#140608', parts: ['#f2b630', '#f2b630', '#e2222b', '#4fd8ff', '#ffffff'], glow: true },
  hologramme: { bg: ['#1a1d5a', '#0b0d30', '#03040f'], soft: ['#965aff', .22, '#00dcff', .12, '#7cc7ff', .20], hex: ['#a479ff', .08], floor: ['#7c9cff', .55], hz: ['#a479ff', '#e0eaff', '#3be8ff'], main: '#7cc7ff',
    vig: ['#000', .75], house: '#7cc7ff', bolt: '#e64fd0', globe: ['#7cc7ff', '#e64fd0'], hud: ['#7cc7ff', '#7cc7ff', '#e64fd0', '#a479ff', '#7cffc4'], track: '#9aaedc', rings: ['#7cc7ff', '#7cffc4', '#a479ff', '#e64fd0'],
    bars: ['#e64fd0', '#c2407f', '#a479ff', '#4a5cff', '#7cc7ff', '#7cffc4'], traces: ['#7cc7ff', '#a479ff'], node: '#0b0d30', parts: ['#7cc7ff', '#7cc7ff', '#a479ff', '#e64fd0', '#ffffff'], glow: true },
  sombre: { bg: ['#1f2b26', '#121815', '#060807'], soft: ['#3987e5', .12, '#f0a35a', .07, '#5cc79f', .16], hex: ['#5cc79f', .06], floor: ['#5cc79f', .48], hz: ['#5cc79f', '#dff5ec', '#f0a35a'], main: '#5cc79f',
    vig: ['#000', .7], house: '#5cc79f', bolt: '#f0a35a', globe: ['#5cc79f', '#f0a35a'], hud: ['#5cc79f', '#5cc79f', '#f0a35a', '#e66767', '#3987e5'], track: '#a3b0aa', rings: ['#5cc79f', '#3987e5', '#f0a35a', '#e66767'],
    bars: ['#e66767', '#f0a35a', '#c98500', '#5cc79f', '#3987e5', '#9085e9'], traces: ['#5cc79f', '#f0a35a'], node: '#121815', parts: ['#5cc79f', '#5cc79f', '#3987e5', '#f0a35a', '#ffffff'], glow: true, dim: .9, halo: .7 },
  nuit: { bg: ['#1c0d06', '#100704', '#040201'], soft: ['#d86226', .12, '#ff7a33', .07, '#ff7a33', .14], hex: ['#d86226', .05], floor: ['#d86226', .42], hz: ['#d86226', '#ffbf8a', '#ff7a33'], main: '#ff7a33',
    vig: ['#000', .8], house: '#ff9b5c', bolt: '#ffd2ad', globe: ['#d86226', '#ffbf8a'], hud: ['#d86226', '#ff9b5c', '#ffbf8a', '#a3461b', '#ff7a33'], track: '#b06a40', rings: ['#ff9b5c', '#ffbf8a', '#ff7a33', '#a3461b'],
    bars: ['#a3461b', '#d86226', '#ff7a33', '#ff9b5c', '#ffbf8a', '#ffd2ad'], traces: ['#d86226', '#ffbf8a'], node: '#100704', parts: ['#ff7a33', '#ff9b5c', '#d86226', '#ffbf8a', '#ffd2ad'], glow: true, dim: .75, halo: .6, partsK: .6 },
  clair: { bg: ['#ffffff', '#f1f4f2', '#dde4e0'], soft: ['#2a78d6', .07, '#eb6834', .05, '#1f7a5c', .08], hex: ['#1f7a5c', .07], floor: ['#1f7a5c', .38], hz: ['#2a78d6', '#1f7a5c', '#eb6834'], main: '#1f7a5c',
    vig: ['#5d6a65', .22], house: '#1f7a5c', bolt: '#eb6834', globe: ['#2a78d6', '#eb6834'], hud: ['#1f7a5c', '#2a78d6', '#eb6834', '#e34948', '#1baf7a'], track: '#8a9690', rings: ['#2a78d6', '#1baf7a', '#eda100', '#e34948'],
    bars: ['#e34948', '#eb6834', '#eda100', '#1baf7a', '#2a78d6', '#4a3aa7'], traces: ['#1f7a5c', '#eb6834'], node: '#f1f4f2', parts: ['#1f7a5c', '#2a78d6', '#2a78d6', '#eb6834', '#1baf7a'], glow: false, dim: .8, halo: .35, houseFill: .08, beam: .1, partsK: .5 },
  encre: { bg: ['#f2f1ec', '#ecebe6', '#e1e0d9'], soft: ['#111', 0, '#111', 0, '#111', 0], hex: ['#111', .05], floor: ['#111', .3], hz: ['#111', '#111', '#111'], main: '#111',
    vig: ['#111', .1], house: '#111', bolt: '#111', globe: ['#111', '#555'], hud: ['#111', '#111', '#555', '#888', '#2b2b2b'], track: '#888', rings: ['#111', '#555', '#888', '#2b2b2b'],
    bars: ['#111', '#2b2b2b', '#444', '#555', '#777', '#888'], traces: ['#111', '#555'], node: '#ecebe6', parts: ['#111', '#111', '#111', '#111', '#111'], glow: false, dim: .6, halo: 0, houseFill: .04, beam: .05, partsK: 0 },
};

const OUT = path.join(__dirname, '..', 'public', 'assets', 'img');
const file = name => path.join(OUT, name === 'domotique' ? 'domotique-fond.jpg' : `fond-${name}.jpg`);

let chromium;
try { ({ chromium } = require('playwright')); } catch (e) {
  try { ({ chromium } = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright')); } catch (e2) {
    console.error('Playwright introuvable : npm install -g playwright, puis npx playwright install chromium.');
    process.exit(1);
  }
}

(async () => {
  const names = process.argv.slice(2).length ? process.argv.slice(2) : Object.keys(T);
  for (const n of names) if (!T[n]) { console.error(`Thème inconnu : ${n} (${Object.keys(T).join(', ')})`); process.exit(1); }
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3 })).newPage();
  for (const n of names) {
    await page.setContent(`<style>html,body{margin:0}svg{display:block}</style>${scene(T[n])}`);
    await page.waitForTimeout(600);
    await page.screenshot({ path: file(n), type: 'jpeg', quality: n === 'clair' || n === 'encre' ? 85 : 80 });
    console.log(path.relative(process.cwd(), file(n)));
  }
  await browser.close();
})();
