/* Page tablette : horloge, rechargement des valeurs chaque minute et de la webcam toutes les 5 minutes.
   ES5 seulement (pas de fetch, de flèches ni de let) : la tablette peut avoir un vieux navigateur. */
(function () {
  'use strict';
  var JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  var MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  var root = document.documentElement;
  var timeEl = document.getElementById('time');
  var dateEl = document.getElementById('date');
  var offlineEl = document.getElementById('offline');
  var panel = document.getElementById('panel');
  var version = panel.getAttribute('data-v');
  var background = panel.getAttribute('data-bg') || '';
  var lastOk = new Date();

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  // L'horloge est construite une fois ; seul le séparateur clignote (pas en thème Encre).
  timeEl.innerHTML = '<span id="hh"></span><span id="sep">:</span><span id="mm"></span>';
  var hh = document.getElementById('hh'), sep = document.getElementById('sep'), mm = document.getElementById('mm');

  function tick() {
    var d = new Date();
    hh.innerHTML = pad(d.getHours());
    mm.innerHTML = pad(d.getMinutes());
    var still = root.getAttribute('data-theme') === 'encre';
    sep.style.visibility = still || d.getSeconds() % 2 === 0 ? 'visible' : 'hidden';
    dateEl.innerHTML = JOURS[d.getDay()] + ' ' + d.getDate() + ' ' + MOIS[d.getMonth()];
  }

  function setBackground(url) {
    background = url;
    if (!url) {
      document.body.style.backgroundImage = 'none';
      return;
    }
    var src = url + (url.indexOf('?') < 0 ? '?' : '&') + '_=' + new Date().getTime();
    var img = new Image();
    img.onload = function () {
      if (background === url) {
        document.body.style.backgroundImage = 'url("' + src.replace(/"/g, '%22') + '")';
      }
    };
    img.src = src; // en cas d'échec, l'image précédente reste affichée
  }

  function showOffline() {
    offlineEl.innerHTML = 'Site injoignable depuis ' + pad(lastOk.getHours()) + ':' + pad(lastOk.getMinutes());
    offlineEl.removeAttribute('hidden');
  }

  function refresh() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', location.pathname + '?partiel=1', true);
    xhr.timeout = 20000;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) { return; }
      if (xhr.status === 401) { location.reload(); return; }
      if (xhr.status !== 200) { showOffline(); return; }
      var box = document.createElement('div');
      box.innerHTML = xhr.responseText;
      var fresh = box.firstChild;
      if (!fresh || fresh.id !== 'panel') { showOffline(); return; }
      if (fresh.getAttribute('data-v') !== version) { location.reload(); return; }
      panel.parentNode.replaceChild(fresh, panel);
      panel = fresh;
      root.setAttribute('data-theme', fresh.getAttribute('data-theme'));
      document.getElementById('veil').style.opacity = fresh.getAttribute('data-veil') || '0';
      if ((fresh.getAttribute('data-bg') || '') !== background) { setBackground(fresh.getAttribute('data-bg') || ''); }
      lastOk = new Date();
      offlineEl.setAttribute('hidden', '');
    };
    xhr.send();
  }

  tick();
  setInterval(tick, 1000);
  setInterval(refresh, 60000);
  setInterval(function () { setBackground(background); }, 300000);
})();
