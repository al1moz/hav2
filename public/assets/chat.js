/* Bouton « Demander à Claude » : la question part vers /api/v1/chat, qui répond en lignes JSON
   (étapes de lecture, puis la réponse). La discussion est gardée le temps de l'onglet. */
'use strict';
(() => {
  const box = document.getElementById('chat');
  if (!box) return;
  const $ = id => document.getElementById(id);
  const openBtn = $('chat-open'), log = $('chat-log'), form = $('chat-form'), input = $('chat-q');
  const send = form.querySelector('button');
  const KEY = 'consov2_chat';
  const EXEMPLES = [
    'Combien ai-je consommé hier, et combien ça a coûté ?',
    'Quel circuit consomme le plus ce mois-ci ?',
    'Cet hiver est-il plus économe que le précédent à froid égal ?',
  ];
  let history = [];
  let busy = false;
  let shown = false;

  const store = {
    get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* stockage indisponible : discussion non gardée */ } },
  };
  try { const h = JSON.parse(store.get(KEY) || '[]'); if (Array.isArray(h)) history = h; } catch (e) { history = []; }
  const save = () => store.set(KEY, JSON.stringify(history.slice(-20)));

  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Mise en forme réduite : paragraphes, listes, **gras** et `code`. Tout le reste est échappé.
  function md(text) {
    const inline = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>');
    const out = [];
    let list = null;
    for (const raw of String(text).split('\n')) {
      const line = raw.trim();
      const item = line.match(/^(?:[-*•]|\d+[.)])\s+(.*)$/);
      if (item) { (list = list || []).push('<li>' + inline(item[1]) + '</li>'); continue; }
      if (list) { out.push('<ul>' + list.join('') + '</ul>'); list = null; }
      if (line !== '') out.push('<p>' + inline(line.replace(/^#+\s*/, '')) + '</p>');
    }
    if (list) out.push('<ul>' + list.join('') + '</ul>');
    return out.join('');
  }

  function bubble(cls, html) {
    const d = document.createElement('div');
    d.className = 'msg ' + cls;
    d.innerHTML = html;
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
    return d;
  }
  function render() {
    log.innerHTML = '';
    if (!history.length) {
      bubble('hint', '<p>Pose une question sur la consommation, les températures ou la PAC.</p>'
        + EXEMPLES.map(e => `<button type="button" class="chip">${esc(e)}</button>`).join(''));
    }
    history.forEach(t => { bubble('q', esc(t.q)); bubble('a', md(t.a)); });
  }
  log.addEventListener('click', e => {
    const b = e.target.closest('.chip');
    if (b && !busy) { input.value = b.textContent; ask(); }
  });

  function toggle(show) {
    box.hidden = !show;
    openBtn.hidden = show;
    openBtn.setAttribute('aria-expanded', String(show));
    store.set(KEY + '_ouvert', show ? '1' : '');
    if (show) {
      if (!shown) { render(); shown = true; }
      input.focus();
    } else {
      openBtn.focus();
    }
  }

  async function lines(r, fn) {
    const handle = line => { if (line.trim()) { try { fn(JSON.parse(line)); } catch (e) { /* ligne incomplète */ } } };
    if (!r.body || !r.body.getReader) { (await r.text()).split('\n').forEach(handle); return; }
    const reader = r.body.getReader(), dec = new TextDecoder();
    let buf = '';
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      buf += dec.decode(value, { stream: true });
      let i;
      while ((i = buf.indexOf('\n')) >= 0) { handle(buf.slice(0, i)); buf = buf.slice(i + 1); }
    }
    handle(buf + dec.decode());
  }

  async function ask() {
    const question = input.value.trim();
    if (!question || busy) return;
    busy = true;
    send.disabled = true;
    input.value = '';
    const hint = log.querySelector('.hint');
    if (hint) hint.remove();
    bubble('q', esc(question));
    const wait = bubble('a wait', '<span class="step">Je regarde les données</span>');
    let answer = null, error = null;
    try {
      const r = await fetch('/api/v1/chat', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/x-ndjson', 'X-CSRF-Token': box.dataset.csrf },
        body: JSON.stringify({ question, history: history.slice(-6) }),
      });
      if (r.status === 401) { location.href = '/connexion'; return; }
      if (!r.ok) {
        const j = await r.json().catch(() => null);
        throw new Error(j && j.error ? j.error.message : 'Erreur ' + r.status + '.');
      }
      await lines(r, ev => {
        if (ev.type === 'step') wait.querySelector('.step').textContent = ev.text;
        else if (ev.type === 'answer') answer = ev.text;
        else if (ev.type === 'error') error = ev.text;
      });
      if (answer === null && error === null) error = 'Réponse interrompue. Réessaie.';
    } catch (e) {
      error = e.message && !/fetch|network/i.test(e.message) ? e.message : 'Le site ne répond pas.';
    }
    if (answer !== null) {
      wait.className = 'msg a';
      wait.innerHTML = md(answer);
      history.push({ q: question, a: answer });
      save();
    } else {
      wait.className = 'msg a err';
      wait.textContent = error;
    }
    log.scrollTop = log.scrollHeight;
    busy = false;
    send.disabled = false;
    if (!box.hidden) input.focus();
  }

  openBtn.addEventListener('click', () => toggle(true));
  $('chat-close').addEventListener('click', () => toggle(false));
  $('chat-new').addEventListener('click', () => { if (busy) return; history = []; save(); render(); input.focus(); });
  box.addEventListener('keydown', e => { if (e.key === 'Escape') toggle(false); });
  form.addEventListener('submit', e => { e.preventDefault(); ask(); });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(); }
  });
  if (store.get(KEY + '_ouvert') === '1') toggle(true);
})();
