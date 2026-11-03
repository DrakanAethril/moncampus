/* Fusion — the game's screens: title, scenes, act announcements, the workshop (the home: the model of
   the two agencies and one tag per step), the workstation (the request, the plan editor or the
   configuration form, the console, the night), the questions, Hélène's help, the leak file, the end.
   The platform is reached through the SDK only (sdk.js); a plan is played by the night simulator
   (sim.js), a configuration checked by its cases (steps.js). */
(function () {
  'use strict';
  var app = document.getElementById('app');
  var CH = window.FSChars, SND = window.FSSound, STORY = window.FSStory, ST = window.FSSteps, SIM = window.FSSim, WORLD = window.FSWorld;
  var api = function (m, p) { return window.SGSdk.call(m, p); };

  var G = { rewards: [], data: { seen: {}, drafts: {} }, sc: null, actView: null, session: null, ticket: null, def: null, answer: null, step: 0, hints: 0, guide: [], guideStep: 0, cons: null, log: [], history: [], hist: -1, pane: 'console', delivery: null, reaction: null, wrong: {}, sel: null, order: null, stage: null };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function h(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }
  function $(sel, root) { return (root || app).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
  function copy(o) { return JSON.parse(JSON.stringify(o)); }
  var saveTimer = null;
  function save(now) {
    clearTimeout(saveTimer);
    var go = function () { api('saveProgress', { data: G.data }).catch(function () { }); };
    if (now) go(); else saveTimer = setTimeout(go, 800);
  }
  function nameOf(w) { return CH.name(w); }
  function gradeOf(n) { var g = STORY.grades[0][1]; STORY.grades.forEach(function (x) { if (n >= x[0]) g = x[1]; }); return g; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function idFor(act, k) { return 'E' + pad(8 * (act - 1) + k); }
  function isClosed(c) { return c.status === 'done' || c.status === 'reassigned'; }
  function closedCount() { return G.sc.cards.filter(isClosed).length; }
  function starsHTML(n) { return '<span class="etoiles" aria-label="' + (n || 0) + ' étoiles sur 3">' + [1, 2, 3].map(function (i) { return '<i class="' + (i <= n ? 'on' : '') + '"></i>'; }).join('') + '</span>'; }
  function closedIds() { var o = {}; (G.sc ? G.sc.cards : []).forEach(function (c) { if (isClosed(c)) o[c.id] = 1; }); return o; }
  /** Planks of the footbridge: one per act whose eight steps are closed. */
  function planks() { var ids = closedIds(), n = 0; for (var a = 1; a <= 5; a++) { var all = true; for (var k = 1; k <= 8; k++) if (!ids[idFor(a, k)]) all = false; if (all) n++; } return n; }

  // ——— The model, drawn in isometric view ———
  function maquetteSVG(o) {
    o = o || {};
    var OX = 520, OY = 210;
    function p(x, y, z) { return [OX + (x - y) * 0.866, OY + (x + y) * 0.5 - z]; }
    function poly(pts, fill, stroke, sw, extra) { return '<polygon points="' + pts.map(function (q) { return q[0].toFixed(1) + ',' + q[1].toFixed(1); }).join(' ') + '" fill="' + fill + '" stroke="' + (stroke || '#2E3236') + '" stroke-width="' + (sw === undefined ? 1.2 : sw) + '" stroke-linejoin="round" ' + (extra || '') + '/>'; }
    function box(x, y, z, w, d, hh, top, left, right, stroke, extra) {
      var t = [p(x, y, z + hh), p(x + w, y, z + hh), p(x + w, y + d, z + hh), p(x, y + d, z + hh)];
      var r = [p(x + w, y, z), p(x + w, y + d, z), p(x + w, y + d, z + hh), p(x + w, y, z + hh)];
      var l = [p(x, y + d, z), p(x + w, y + d, z), p(x + w, y + d, z + hh), p(x, y + d, z + hh)];
      return poly(l, left, stroke, 1.2, extra) + poly(r, right, stroke, 1.2, extra) + poly(t, top, stroke, 1.2, extra);
    }
    var s = '<svg viewBox="0 64 1040 650" role="img" aria-label="La maquette des deux agences : ' + (o.planks || 0) + ' planches de la passerelle posées sur 5">';
    s += '<defs><filter id="fs-flou" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="14"/></filter></defs>';
    s += '<g transform="translate(26 30)" opacity=".2" filter="url(#fs-flou)">' + poly([p(-30, -30, -16), p(560, -30, -16), p(560, 340, -16), p(-30, 340, -16)], '#2E3236', 'none', 0) + '</g>';
    s += box(-30, -30, -16, 590, 370, 16, '#D8C29D', '#B9A27B', '#C9B289', '#8C7752');
    for (var i = 0; i < 9; i++) { var a = p(-30 + i * 64, -30, 0), b = p(-30 + i * 64 + 10, 340, 0); s += '<line x1="' + a[0].toFixed(0) + '" y1="' + a[1].toFixed(0) + '" x2="' + b[0].toFixed(0) + '" y2="' + b[1].toFixed(0) + '" stroke="#C4AC82" stroke-width="1"/>'; }
    s += box(0, 0, 0, 200, 160, 112, '#FAFAF7', '#E6E6E1', '#D3D3CD');
    for (var wi = 0; wi < 4; wi++) for (var wj = 0; wj < 2; wj++) { var x0 = 22 + wi * 34, z0 = 30 + wj * 42; s += poly([p(200, x0, z0), p(200, x0 + 20, z0), p(200, x0 + 20, z0 + 24), p(200, x0, z0 + 24)], '#BFC5C3', '#2E3236', 0.8); }
    s += box(330, 30, 0, 160, 130, 74, '#FAFAF7', '#E6E6E1', '#D3D3CD');
    for (var k = 0; k < 3; k++) { var x1 = 352 + k * 40; s += poly([p(x1, 160, 22), p(x1 + 24, 160, 22), p(x1 + 24, 160, 48), p(x1, 160, 48)], '#BFC5C3', '#2E3236', 0.8); }
    var n = o.planks || 0;
    for (var j = 0; j < 5; j++) {
      var xp = 203 + j * 25;
      if (j < n) s += box(xp, 66, 52, 23, 34, 5, '#E3CFA9', '#B9A27B', '#C9B289', '#8C7752');
      else s += poly([p(xp, 66, 57), p(xp + 23, 66, 57), p(xp + 23, 100, 57), p(xp, 100, 57)], 'none', '#6E6A60', 1, 'stroke-dasharray="4 3"');
    }
    if (!o.nasGone) s += box(470, 250, 0, 44, 34, 24, '#EFEFEA', '#D9D9D3', '#C6C6BF', '#2E3236', 'stroke-dasharray="4 3"');
    [[40, 230], [110, 280], [250, 250], [520, 40], [300, 300], [20, 300]].forEach(function (t) {
      var b0 = p(t[0], t[1], 0), tp = p(t[0], t[1], 30);
      s += '<line x1="' + b0[0].toFixed(0) + '" y1="' + b0[1].toFixed(0) + '" x2="' + tp[0].toFixed(0) + '" y2="' + tp[1].toFixed(0) + '" stroke="#6B5A3E" stroke-width="2"/><ellipse cx="' + (b0[0] + 8).toFixed(0) + '" cy="' + (b0[1] + 3).toFixed(0) + '" rx="16" ry="6" fill="#2E3236" opacity=".15"/><circle cx="' + tp[0].toFixed(0) + '" cy="' + (tp[1] - 8).toFixed(0) + '" r="17" fill="#7F9157"/><circle cx="' + (tp[0] - 5).toFixed(0) + '" cy="' + (tp[1] - 13).toFixed(0) + '" r="7" fill="#9AAB71"/>';
    });
    function flag(pt, text, col, up) {
      col = col || '#2E3236'; up = up || 70;
      var w = text.length * 7.4 + 18;
      return '<line x1="' + pt[0].toFixed(0) + '" y1="' + pt[1].toFixed(0) + '" x2="' + pt[0].toFixed(0) + '" y2="' + (pt[1] - up).toFixed(0) + '" stroke="' + col + '" stroke-width="1.4"/><circle cx="' + pt[0].toFixed(0) + '" cy="' + pt[1].toFixed(0) + '" r="3" fill="' + col + '"/><g transform="translate(' + (pt[0] + 2).toFixed(0) + ' ' + (pt[1] - up).toFixed(0) + ')"><rect width="' + w.toFixed(0) + '" height="24" fill="#FFFFFF" stroke="' + col + '"/><text x="9" y="16.5" font-family="IBM Plex Mono, monospace" font-size="12" fill="' + col + '">' + esc(text) + '</text></g>';
    }
    s += flag(p(60, 40, 112), 'Atelier Delorme · Limoges');
    s += flag(p(400, 70, 74), 'Kerbrat · Brive', '#2E3236', 74);
    s += flag(p(232, 83, 57), 'passerelle · ' + n + ' planche' + (n > 1 ? 's' : '') + ' sur 5', '#E07A3F', 170);
    if (!o.nasGone) s += flag(p(490, 266, 24), 'vieux NAS ?', '#E07A3F', 58);
    return s + '</svg>';
  }

  // ——— Scenes ———
  function playScene(lines, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var scene = h('<section class="scene" aria-label="Scène"><div class="haut"><div class="tags"><span>' + esc(opts.tag || 'ATELIER') + '</span></div><button type="button" class="btn petit fantome passer">Passer</button></div><div class="scene-zone"><div class="perso gauche" aria-hidden="true"></div><div class="perso droite" aria-hidden="true"></div></div>' +
        '<div class="lower" aria-live="polite"><div class="nom"><b></b><span></span></div><div class="texte"></div><div class="suite"><button type="button" class="btn petit plein suivant">Suivant ▸</button></div></div></section>');
      app.appendChild(scene);
      var speakers = [];
      lines.forEach(function (l) { if (l.who !== 'narr' && speakers.indexOf(l.who) === -1) speakers.push(l.who); });
      var slots = { gauche: speakers[0] || null, droite: speakers[1] || null }, i = 0;
      function show() {
        var l = lines[i], lower = $('.lower', scene);
        lower.classList.toggle('narr', l.who === 'narr');
        $('.nom', scene).style.visibility = l.who === 'narr' ? 'hidden' : '';
        if (l.who !== 'narr') {
          if (slots.gauche !== l.who && slots.droite !== l.who) { if (speakers.indexOf(l.who) % 2) slots.droite = l.who; else slots.gauche = l.who; }
          $('.nom b', scene).textContent = nameOf(l.who); $('.nom span', scene).textContent = CH.role(l.who);
        }
        ['gauche', 'droite'].forEach(function (side) {
          var el = $('.perso.' + side, scene), who = slots[side];
          if (!who) { el.style.display = 'none'; return; }
          el.style.display = '';
          var speaking = who === l.who;
          el.innerHTML = CH.svg(who, speaking ? (l.expr || 'neutre') : (el.dataset.expr || 'neutre'));
          if (speaking) el.dataset.expr = l.expr || 'neutre';
          el.classList.toggle('parle', speaking); el.classList.toggle('eteint', !speaking);
        });
        $('.texte', scene).textContent = l.text;
        SND.click();
        $('.suivant', scene).focus();
      }
      function next() { i++; if (i >= lines.length) { end(); return; } show(); }
      function end() { document.removeEventListener('keydown', key); scene.remove(); resolve(); }
      function key(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); next(); } if (e.key === 'Escape') end(); }
      $('.suivant', scene).addEventListener('click', next);
      $('.passer', scene).addEventListener('click', end);
      document.addEventListener('keydown', key);
      show();
    });
  }

  // ——— Title ———
  function showTitle() {
    app.innerHTML = '';
    var started = !!G.data.prologue, done = closedCount();
    var view = h('<section class="titre"><div class="gauche"><div class="sur">Delorme + Kerbrat · maquette au 1/100</div><h1>fusion</h1><p class="sous">le plan de bascule</p>' +
      '<p class="pitch">Deux cabinets d\'architectes, le même réseau des deux côtés, douze semaines pour n\'en faire qu\'un. Et, quelque part dans la migration, le chemin par où les plans d\'un concours ont fui.</p><div class="nav"></div></div>' +
      '<div class="maquette">' + maquetteSVG({ planks: planks(), nasGone: !!closedIds().E16 }) + '</div></section>');
    var nav = $('.nav', view);
    var first = h('<button type="button" class="btn plein">' + (started ? 'Reprendre la maquette' : 'Commencer') + '<small>' + (started ? 'Acte ' + G.sc.act + ' · ' + esc(G.sc.acts[G.sc.act - 1].title) + ' — ' + done + ' étapes closes' : 'Acte 1 · Deux SI pour un') + '</small></button>');
    first.addEventListener('click', function () { SND.click(); startGame(); });
    nav.appendChild(first);
    if (started) {
      var again = h('<button type="button" class="btn">Revoir le prologue</button>');
      again.addEventListener('click', function () { playScene(STORY.prologue, { tag: 'PROLOGUE' }); });
      nav.appendChild(again);
      if (G.sc.clues.length) { var cb = h('<button type="button" class="btn fantome">Dossier de la fuite</button>'); cb.addEventListener('click', showDossier); nav.appendChild(cb); }
      var nw = h('<button type="button" class="btn fantome">Recommencer depuis le début</button>');
      nw.addEventListener('click', function () { nw.classList.add('confirm'); if (nw.dataset.armed) { api('newGame', {}).catch(function () { }).then(function () { location.reload(); }); return; } nw.dataset.armed = '1'; nw.textContent = 'Confirmer : tout effacer'; });
      nav.appendChild(nw);
    }
    var son = h('<button type="button" class="btn fantome" aria-pressed="' + (!SND.muted()) + '">' + (SND.muted() ? 'Son coupé' : 'Son activé') + '</button>');
    son.addEventListener('click', function () { SND.setMuted(!SND.muted()); G.data.mute = SND.muted(); save(); son.textContent = SND.muted() ? 'Son coupé' : 'Son activé'; son.setAttribute('aria-pressed', String(!SND.muted())); });
    nav.appendChild(son);
    app.appendChild(view);
    first.focus();
  }
  function startGame() {
    if (!G.data.prologue) playScene(STORY.prologue, { tag: 'PROLOGUE' }).then(function () { G.data.prologue = true; save(true); showAtelier(); });
    else showAtelier();
  }

  // ——— Act announcement ———
  function actIntro(n) {
    return new Promise(function (resolve) {
      SND.act();
      var a = G.sc.acts[n - 1], st = STORY.acts[n] || {};
      var list = G.sc.acts.map(function (x) {
        var cls = x.n < n ? 'fait' : (x.n === n ? 'ici' : '');
        return '<li class="' + cls + '"><b>' + x.n + '</b><span style="flex:1;font-weight:600">' + (x.n <= n ? esc(x.title) : '· · ·') + '</span><span style="font:600 13px var(--mono);color:var(--dim)">' + (x.n < n ? 'planche posée' : (x.n === n ? 'à jouer' : '')) + '</span></li>';
      }).join('');
      var view = h('<section class="acte-intro" aria-label="Acte ' + n + '"><div><div class="num"><small>ACTE</small>' + n + '</div><h1>' + esc(a.title) + '</h1><div class="quand">' + esc((st.time || '') + ' · ' + (st.dates || '')) + '</div><p>' + esc(st.blurb || '') + '</p><div class="notions">' + (st.notions || []).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div><button type="button" class="btn plein go">Entrer dans l\'acte ▸</button></div><ol aria-label="Les actes">' + list + '</ol></section>');
      app.appendChild(view);
      $('.go', view).focus();
      $('.go', view).addEventListener('click', function () { api('markActSeen', { act: n }).then(function () { view.remove(); resolve(); }); });
    });
  }

  // ——— The workshop (home) ———
  function refreshScenario() { return api('getScenario').then(function (sc) { G.sc = sc; if (!G.actView || G.actView > sc.act) G.actView = sc.act; return sc; }); }
  function topBar(current) {
    var b = h('<header class="bar"><div class="logo">fusion</div><nav aria-label="Navigation"></nav><div class="droite"></div></header>');
    var nav = $('nav', b);
    [['atelier', 'Atelier', function () { save(true); showAtelier(); }], ['dossier', 'Dossier de la fuite', function () { save(true); showDossier(); }]].forEach(function (x) {
      var bt = h('<button type="button"' + (x[0] === current ? ' aria-current="page"' : '') + '>' + x[1] + '</button>'); bt.addEventListener('click', function () { SND.click(); x[2](); }); nav.appendChild(bt);
    });
    return b;
  }
  function showAtelier(newIds) {
    if (!Array.isArray(newIds)) newIds = [];
    G.ticket = null; G.def = null;
    refreshScenario().then(function (sc) {
      if (sc.finished && !G.review) { showEnd(); return; }
      if (!sc.actSeen && !sc.finished) { actIntro(sc.act).then(function () { showAtelier(newIds); }); return; }
      var act = G.actView || sc.act, st = STORY.acts[act] || {}, done = closedCount();
      var stars = 0, nStars = 0;
      sc.cards.forEach(function (c) { if (c.quality) { stars += c.quality; nStars++; } });
      var view = h('<section class="atelier"></section>');
      var bar = topBar('atelier');
      var d = $('.droite', bar);
      d.appendChild(h('<span class="chip">' + esc(st.dates || '') + '</span>'));
      var son = h('<button type="button" class="btn petit fantome">' + (SND.muted() ? 'Son coupé' : 'Son') + '</button>'); son.addEventListener('click', function () { SND.setMuted(!SND.muted()); G.data.mute = SND.muted(); save(); son.textContent = SND.muted() ? 'Son coupé' : 'Son'; }); d.appendChild(son);
      var back = h('<button type="button" class="btn petit fantome">Écran titre</button>'); back.addEventListener('click', function () { save(true); showTitle(); }); d.appendChild(back);
      view.appendChild(bar);
      var centre = h('<div class="centre"><div class="scene-maquette"><div class="legende"><h2>Acte ' + act + ' · ' + esc(sc.acts[act - 1].title) + '</h2><p>' + esc(st.blurb || '') + '</p></div><div class="maquette" style="margin-top:60px">' + maquetteSVG({ planks: planks(), nasGone: !!closedIds().E16 }) + '</div></div><div class="cote"></div></div>');
      var cote = $('.cote', centre);
      var open = sc.cards.filter(function (c) { return c.status === 'todo' || c.status === 'doing'; });
      var rank = { 'tres-haute': 0, haute: 1, moyenne: 2, basse: 3 };
      var next = open.filter(function (c) { return c.status === 'doing'; })[0] || open.slice().sort(function (a, b) { return (rank[a.priority] - rank[b.priority]) || (a.id > b.id ? 1 : -1); })[0];
      if (next && act === sc.act) cote.appendChild(h('<div class="carte consigne"><div class="tete">' + CH.svg('helene', 'sourire') + '</div><div><b>Hélène</b>' + esc(next.status === 'doing' ? 'Reprends « ' + next.title + ' » : tu l\'as commencée.' : 'Prends « ' + next.title + ' ». ' + (open.length > 1 ? 'Les étiquettes épinglées en orange sont ouvertes : tu choisis l\'ordre.' : '')) + '</div></div>'));
      cote.appendChild(h('<div class="carte"><h3>Avancement</h3><div class="grand">' + done + ' étapes sur ' + sc.total + '</div><div style="color:var(--dim)">' + (nStars ? stars + ' étoiles sur ' + 3 * nStars : 'aucune étoile encore') + '</div></div>'));
      cote.appendChild(h('<div class="carte"><h3>Grade</h3><div class="grand">' + esc(gradeOf(done)) + '</div></div>'));
      var dos = h('<button type="button" class="carte" style="border:0;text-align:left;cursor:pointer"><h3>Dossier de la fuite</h3><div>' + sc.clues.length + ' indice' + (sc.clues.length > 1 ? 's' : '') + ' sur 5</div><div class="pistes">' + [0, 1, 2, 3, 4].map(function (i) { return '<i class="' + (i < sc.clues.length ? 'on' : '') + '"></i>'; }).join('') + '</div></button>');
      dos.addEventListener('click', showDossier); cote.appendChild(dos);
      view.appendChild(centre);
      var bas = h('<div><div class="actes"></div><div class="etiquettes"></div></div>');
      sc.acts.forEach(function (a) { var b = h('<button type="button" aria-current="' + (a.n === act) + '"' + (a.n > sc.act ? ' disabled' : '') + '>Acte ' + a.n + '</button>'); b.addEventListener('click', function () { G.actView = a.n; showAtelier(); }); $('.actes', bas).appendChild(b); });
      for (var k = 1; k <= 8; k++) {
        (function (id) {
          var c = sc.cards.filter(function (x) { return x.id === id; })[0];
          var cls = !c ? 'fermee' : (c.status === 'doing' ? 'encours ouverte' : (c.status === 'todo' ? 'ouverte' : 'faite'));
          var g = h('<button type="button" class="etiquette ' + cls + (newIds.indexOf(id) !== -1 ? ' nouveau' : '') + '"' + (!c ? ' disabled aria-label="Étape ' + id.slice(1) + ', à venir"' : '') + '><span class="num">' + id.slice(1) + '</span><span class="nom">' + esc(c ? c.title : '· · ·') + '</span>' + (c && c.quality ? starsHTML(c.quality) : '<span class="etoiles"></span>') + '</button>');
          if (c) g.addEventListener('click', function () { SND.fader(); if (isClosed(c)) reviewTicket(c); else openTicket(c.id); });
          $('.etiquettes', bas).appendChild(g);
        })(idFor(act, k));
      }
      view.appendChild(bas);
      app.innerHTML = ''; app.appendChild(view);
      var f = $('.etiquette.ouverte', view) || $('.etiquette:not(.fermee)', view); if (f) f.focus();
    });
  }
  function reviewTicket(c) {
    var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true"><h2>' + esc(c.title) + '</h2><p>Étape close' + (c.quality ? ' · ' + c.quality + ' étoile' + (c.quality > 1 ? 's' : '') + ' sur 3' : '') + '.</p><div class="actions"><button type="button" class="btn plein">Fermer</button></div></div></div>');
    $('.btn', w).addEventListener('click', function () { w.remove(); });
    app.appendChild(w); $('.btn', w).focus();
  }

  // ——— The leak file ———
  function showDossier() {
    refreshScenario().then(function (sc) {
      var view = h('<section class="dossier"></section>');
      view.appendChild(topBar('dossier'));
      var corps = h('<div class="corps"><h1>Le dossier de la fuite</h1><p style="font-size:18px;margin:0;max-width:60ch">La planche PL 07 du concours de la médiathèque a été retrouvée sous l\'esquisse de l\'Atelier Varenne. Chaque migration montre un morceau du SI : Hélène range ici ce qu\'on apprend. Il y a cinq indices ; réunis, ils disent par quel chemin les plans sont sortis.</p></div>');
      for (var i = 0; i < 5; i++) corps.appendChild(h('<div class="piste' + (sc.clues[i] ? '' : ' vide') + '"><b>' + (i + 1) + '</b><span>' + esc(sc.clues[i] || 'Un indice reste à trouver.') + '</span></div>'));
      view.appendChild(corps);
      app.innerHTML = ''; app.appendChild(view);
    });
  }

  // ——— The workstation ———
  function openTicket(id) {
    api('startTicket', { ticket: id }).then(function (r) {
      G.session = r.session; G.ticket = r.ticket; G.step = r.step; G.hints = r.hints || 0; G.guide = r.guide || []; G.guideStep = r.guideStep || 0;
      G.def = ST.get(id) || null; G.reaction = null; G.wrong = {}; G.sel = null; G.order = null; G.delivery = null; G.pane = 'console'; G.log = []; G.history = []; G.hist = -1; G.stage = null;
      var draft = G.data.drafts && G.data.drafts[id];
      G.answer = G.def && G.def.tool ? copy(draft !== undefined ? draft : G.def.start) : null;
      G.cons = WORLD.console(G.ticket.act);
      var begin = function () { renderPoste(); };
      if (G.def && G.def.intro && !(G.data.seen && G.data.seen[id + ':i'])) { G.data.seen = G.data.seen || {}; G.data.seen[id + ':i'] = 1; save(); playScene(G.def.intro, { tag: 'ATELIER' }).then(begin); } else begin();
    }).catch(function (e) { app.appendChild(h('<div class="voile"><div class="fenetre"><h2>Étape inaccessible</h2><p>' + esc(e.message) + '</p></div></div>')); setTimeout(showAtelier, 1500); });
  }
  function curStep() { return G.ticket.steps[G.step]; }
  function rungs() { return G.guide.length + (G.def ? G.def.hints.length : 0); }
  function guideOpen() { return G.guideStep < G.guide.length && G.hints > G.guideStep ? G.guide[G.guideStep] : null; }
  function keepDraft() { G.data.drafts = G.data.drafts || {}; G.data.drafts[G.ticket.id] = copy(G.answer); save(); }

  function renderPoste() {
    app.innerHTML = '';
    var view = h('<section class="poste"></section>');
    var bar = topBar('poste');
    var st = STORY.acts[G.ticket.act] || {};
    $('.droite', bar).appendChild(h('<span class="chip">Acte ' + G.ticket.act + ' · ' + esc(st.dates || '') + '</span>'));
    view.appendChild(bar);
    view.appendChild(h('<div class="grille"><div class="col gauche"></div><div class="col droite"></div></div>'));
    app.appendChild(view);
    renderLeft(); renderRight();
  }
  function reactionHTML(r, good) { return '<div class="reaction' + (good ? ' bien' : '') + '"><div class="tete">' + CH.svg(r.who || 'helene', r.expr || 'neutre') + '</div><div><b>' + esc(nameOf(r.who || 'helene')) + '</b>' + esc(r.text) + '</div></div>'; }

  function renderLeft() {
    var col = $('.col.gauche'), t = G.ticket, st = curStep();
    col.innerHTML = '';
    var p = h('<div class="panneau"></div>');
    p.innerHTML = '<div class="demandeur"><div class="tete">' + CH.svg(t.requester, 'neutre') + '</div><div><b>' + esc(nameOf(t.requester)) + '</b><small>' + esc(CH.role(t.requester)) + '</small></div></div>' +
      '<h1 class="titre-etape">' + esc(t.title) + '</h1><p class="demande">' + esc(t.request) + '</p>' +
      '<div class="avancement">' + t.steps.map(function (s, i) { return '<i class="' + (i < G.step ? 'fait' : (i === G.step ? 'ici' : '')) + '" title="' + esc(s.label || s.kind) + '"></i>'; }).join('') + '</div>';
    if (st && st.kind === 'fix' && G.def) {
      var vis = G.def.cases.filter(function (c) { return c.visible; }), hid = G.def.cases.length - vis.length, res = G.delivery;
      var noun = G.def.tool === 'plan' ? 'nuits' : 'contrôles';
      var items = vis.map(function (c) { var r = res && res.results.filter(function (x) { return x.id === c.id; })[0]; return '<li class="' + (r ? (r.ok ? 'ok' : 'ko') : '') + '">' + esc(nameOf(t.requester) + ' vérifiera : ' + c.label) + '</li>'; }).join('');
      p.appendChild(h('<div style="margin-top:12px"><h2>Critères</h2><ul class="criteres">' + items + '<li class="cache">…et ' + hid + ' ' + noun + ' que vous ne voyez pas, où quelque chose tourne mal.</li></ul></div>'));
    }
    if (G.reaction) p.appendChild(h(reactionHTML(G.reaction.r, G.reaction.good)));
    col.appendChild(p);
    if (st && st.kind === 'fix' && G.def && rungs()) col.appendChild(helpPanel());
  }
  function helpPanel() {
    var p = h('<div class="panneau aide"><h2>Hélène</h2></div>');
    var gq = guideOpen(), cards = Math.max(0, G.hints - G.guide.length);
    if (gq) {
      var q = h('<div><b style="font-family:var(--titre)">Question d\'Hélène</b><p style="margin:6px 0">' + esc(gq.prompt) + '</p><div class="options"></div></div>');
      gq.options.forEach(function (o, i) {
        var b = h('<button type="button" class="option' + (G.wrong['g' + G.guideStep + ':' + i] ? ' faux' : '') + '"><b>' + String.fromCharCode(65 + i) + '</b><span>' + esc(o) + '</span></button>');
        b.addEventListener('click', function () { submitGuide(gq, i); });
        $('.options', q).appendChild(b);
      });
      p.appendChild(q);
    }
    for (var i = 0; i < Math.min(cards, G.def.hints.length); i++) p.appendChild(h('<div class="art"><b>' + esc(G.def.hints[i].title) + '</b><pre>' + esc(G.def.hints[i].body) + '</pre></div>'));
    var more = G.hints < rungs() && !gq;
    if (more) {
      var b = h('<button type="button" class="btn petit" style="margin-top:10px">' + (G.hints === 0 ? 'Demander à Hélène' : 'Un coup de pouce de plus') + '<small>coûte une étoile</small></button>');
      b.addEventListener('click', function () { api('useHint', { session: G.session }).then(function (r) { G.hints = r.hints; G.guideStep = r.guideStep; SND.click(); renderLeft(); }); });
      p.appendChild(b);
    }
    if (!gq && !more && G.hints === 0) p.appendChild(h('<p style="color:var(--dim)">Hélène connaît ce genre de chantier. Demande-lui si tu bloques.</p>'));
    return p;
  }
  function submitGuide(gq, i) {
    api('submitGuide', { session: G.session, step: gq.id, input: i }).then(function (res) {
      if (res.ok) { G.guideStep = res.next; G.reaction = res.reaction ? { r: res.reaction, good: true } : null; SND.right(); }
      else { G.wrong['g' + G.guideStep + ':' + i] = true; G.reaction = res.reaction ? { r: res.reaction, good: false } : null; SND.wrong(); }
      renderLeft();
    });
  }

  function renderRight() {
    var col = $('.col.droite'), st = curStep();
    col.innerHTML = '';
    col.appendChild(st.kind === 'fix' ? (G.def.tool === 'plan' ? planPanel() : formPanel()) : questionPanel(st));
    col.appendChild(bottomPane());
  }

  // ——— Questions ———
  function questionPanel(st) {
    var box = h('<div class="panneau question"></div>');
    if (st.kind === 'choice' || st.kind === 'explain') {
      box.innerHTML = '<h2>' + esc(st.prompt) + '</h2><div class="options"></div>';
      var opts = $('.options', box), ready = G.stage === 'ok:' + st.id;
      st.options.forEach(function (o, i) {
        var b = h('<button type="button" class="option' + (G.wrong[st.id + ':' + i] ? ' faux' : '') + '"' + (ready ? ' disabled' : '') + '><b>' + String.fromCharCode(65 + i) + '</b><span>' + esc(o) + '</span></button>');
        b.addEventListener('click', function () { submit(st, i); });
        opts.appendChild(b);
      });
      if (ready) { var c = h('<button type="button" class="btn plein" style="justify-self:start">Continuer ▸</button>'); c.addEventListener('click', function () { G.stage = null; G.reaction = null; advance(); }); box.appendChild(c); setTimeout(function () { c.focus(); }, 0); }
    } else if (st.kind === 'line') {
      var v = G.def ? ST.view(G.def, st.view) : null;
      box.innerHTML = '<h2>' + esc(st.prompt) + '</h2><div class="vue' + (v && !v.mono ? ' prose' : '') + '"><div class="titre-vue">' + esc(v ? v.title : '') + '</div></div><div><button type="button" class="btn plein valider" disabled>Valider cette ligne</button></div>';
      var vue = $('.vue', box);
      (v ? v.lines : []).forEach(function (l, i) {
        var b = h('<button type="button" class="ligne"><i>' + (i + 1) + '</i><span>' + esc(l) + '</span></button>');
        b.addEventListener('click', function () { $$('.ligne', vue).forEach(function (x) { x.classList.remove('choisie'); }); b.classList.add('choisie'); G.sel = l; $('.valider', box).disabled = false; SND.click(); });
        vue.appendChild(b);
      });
      $('.valider', box).addEventListener('click', function () { submit(st, G.sel); });
      G.sel = null;
    } else if (st.kind === 'order') {
      if (!G.order) G.order = st.items.map(function (_, i) { return i; });
      box.innerHTML = '<h2>' + esc(st.prompt) + '</h2><ol class="ordre"></ol><div><button type="button" class="btn plein valider">Valider l\'ordre</button></div>';
      var ol = $('.ordre', box);
      G.order.forEach(function (idx, pos) {
        var li = h('<li><b>' + (pos + 1) + '</b><span>' + esc(st.items[idx]) + '</span><span><button type="button" aria-label="Monter"' + (pos === 0 ? ' disabled' : '') + '>▲</button> <button type="button" aria-label="Descendre"' + (pos === G.order.length - 1 ? ' disabled' : '') + '>▼</button></span></li>');
        var bs = $$('button', li);
        bs[0].addEventListener('click', function () { var t = G.order[pos - 1]; G.order[pos - 1] = G.order[pos]; G.order[pos] = t; SND.fader(); renderRight(); });
        bs[1].addEventListener('click', function () { var t = G.order[pos + 1]; G.order[pos + 1] = G.order[pos]; G.order[pos] = t; SND.fader(); renderRight(); });
        ol.appendChild(li);
      });
      $('.valider', box).addEventListener('click', function () { submit(st, G.order.slice()); });
    }
    return box;
  }

  // ——— The plan editor ———
  function dur(m) { if (m >= 2880) return Math.round(m / 1440) + ' jours'; return m >= 60 ? Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + pad(m % 60) : '') : m + ' min'; }
  function planPanel() {
    var P = G.def.plan, A = G.answer;
    A.prep = A.prep || []; A.night = A.night || [];
    var used = {}; A.prep.forEach(function (id) { used[id] = 1; }); A.night.forEach(function (r) { used[r.op] = 1; });
    var box = h('<div class="panneau outil"></div>');
    box.appendChild(h('<div class="but"><b>Objectif.</b> ' + esc(P.aim || '') + '</div>'));
    var fen = h('<div><h2>Fenêtre de maintenance</h2><div class="fenetres" role="radiogroup"></div></div>');
    P.windows.forEach(function (w) {
      var l = h('<label><input type="radio" name="fenetre" value="' + esc(w.id) + '"' + (A.window === w.id ? ' checked' : '') + '> ' + esc(w.label) + '</label>');
      $('input', l).addEventListener('change', function () { A.window = w.id; keepDraft(); SND.click(); renderRight(); });
      $('.fenetres', fen).appendChild(l);
    });
    box.appendChild(fen);
    var plan = h('<div class="plan"><div class="catalogue"><h2>Opérations possibles</h2></div><div class="feuille"></div></div>');
    P.ops.forEach(function (op) {
      var row = h('<div class="op' + (used[op.id] ? ' pris' : '') + '"><span>' + esc(op.label) + ' <small>' + dur(op.dur) + '</small></span><span class="ajouts"></span></div>');
      if (!used[op.id]) {
        var a1 = h('<button type="button" class="mini" title="À faire quelques jours avant la nuit">avant</button>'), a2 = h('<button type="button" class="mini" title="À faire pendant la nuit">+ nuit</button>');
        a1.addEventListener('click', function () { A.prep.push(op.id); keepDraft(); SND.plank(); renderRight(); });
        a2.addEventListener('click', function () { A.night.push({ op: op.id }); keepDraft(); SND.plank(); renderRight(); });
        $('.ajouts', row).appendChild(a1); $('.ajouts', row).appendChild(a2);
      }
      $('.catalogue', plan).appendChild(row);
    });
    var f = $('.feuille', plan);
    f.appendChild(h('<h3>Avant la nuit (J-8)</h3>'));
    var lp = h('<div class="liste-plan"></div>');
    if (!A.prep.length) lp.appendChild(h('<div class="vide-plan">Rien à préparer avant la nuit.</div>'));
    A.prep.forEach(function (id, i) {
      var op = SIM.find(P.ops, id);
      var r = h('<div class="rang"><b>·</b><div class="lib">' + esc(op.label) + '</div><div class="bouts"><button type="button" class="mini" aria-label="Retirer">✕</button></div></div>');
      $('button', r).addEventListener('click', function () { A.prep.splice(i, 1); keepDraft(); renderRight(); });
      lp.appendChild(r);
    });
    f.appendChild(lp);
    var win = SIM.find(P.windows, A.window), total = A.night.reduce(function (s, r) { var o = SIM.find(P.ops, r.op); return s + (o ? o.dur : 0); }, 0);
    f.appendChild(h('<h3>La nuit' + (win ? ' · ' + esc(win.label) : '') + ' · ' + dur(total) + ' d\'opérations' + (win ? ' pour ' + dur(win.end - win.start) : '') + '</h3>'));
    var ln = h('<div class="liste-plan"></div>');
    var nr = A.noReturn === undefined || A.noReturn === null ? null : Math.min(A.noReturn, A.night.length);
    function marker() {
      var m = h('<div class="non-retour"><span>◆</span><span>Point de non-retour : après lui, on répare en avant</span><span class="bouts"><button type="button" class="mini" aria-label="Monter le point">▲</button><button type="button" class="mini" aria-label="Descendre le point">▼</button><button type="button" class="mini" aria-label="Retirer le point">✕</button></span></div>');
      var bs = $$('button', m);
      bs[0].disabled = nr === 0; bs[1].disabled = nr >= A.night.length;
      bs[0].addEventListener('click', function () { A.noReturn = nr - 1; keepDraft(); renderRight(); });
      bs[1].addEventListener('click', function () { A.noReturn = nr + 1; keepDraft(); renderRight(); });
      bs[2].addEventListener('click', function () { A.noReturn = null; keepDraft(); renderRight(); });
      return m;
    }
    if (!A.night.length) ln.appendChild(h('<div class="vide-plan">Ajoute les opérations de la nuit depuis la liste, avec « + nuit ».</div>'));
    A.night.forEach(function (row, i) {
      if (nr === i) ln.appendChild(marker());
      var op = SIM.find(P.ops, row.op);
      var r = h('<div class="rang"><b>' + (i + 1) + '</b><div><div class="lib">' + esc(op.label) + '<small>' + dur(op.dur) + '</small></div><div class="reglages"><label>Contrôle</label><select aria-label="Contrôle après « ' + esc(op.label) + ' »"><option value="">— aucun</option></select><label>Retour</label><select aria-label="Retour arrière de « ' + esc(op.label) + ' »"><option value="">— aucun</option></select></div></div><div class="bouts"><button type="button" class="mini" aria-label="Monter">▲</button><button type="button" class="mini" aria-label="Descendre">▼</button><button type="button" class="mini" aria-label="Retirer">✕</button></div></div>');
      var sels = $$('select', r);
      P.checks.forEach(function (c) { sels[0].appendChild(h('<option value="' + esc(c.id) + '"' + (row.check === c.id ? ' selected' : '') + '>' + esc(c.label) + '</option>')); });
      P.backs.forEach(function (b) { sels[1].appendChild(h('<option value="' + esc(b.id) + '"' + (row.back === b.id ? ' selected' : '') + '>' + esc(b.label) + '</option>')); });
      sels[0].addEventListener('change', function () { if (sels[0].value) row.check = sels[0].value; else delete row.check; keepDraft(); });
      sels[1].addEventListener('change', function () { if (sels[1].value) row.back = sels[1].value; else delete row.back; keepDraft(); });
      var bs = $$('.bouts button', r);
      bs[0].disabled = i === 0; bs[1].disabled = i === A.night.length - 1;
      bs[0].addEventListener('click', function () { var t = A.night[i - 1]; A.night[i - 1] = A.night[i]; A.night[i] = t; keepDraft(); SND.fader(); renderRight(); });
      bs[1].addEventListener('click', function () { var t = A.night[i + 1]; A.night[i + 1] = A.night[i]; A.night[i] = t; keepDraft(); SND.fader(); renderRight(); });
      bs[2].addEventListener('click', function () { A.night.splice(i, 1); if (nr !== null && i < nr) A.noReturn = nr - 1; keepDraft(); renderRight(); });
      ln.appendChild(r);
    });
    if (nr !== null && nr === A.night.length && A.night.length) ln.appendChild(marker());
    f.appendChild(ln);
    if (nr === null && A.night.length) {
      var place = h('<button type="button" class="mini" style="justify-self:start">◆ Placer le point de non-retour</button>');
      place.addEventListener('click', function () { A.noReturn = A.night.length; keepDraft(); renderRight(); });
      f.appendChild(place);
    }
    box.appendChild(plan);
    box.appendChild(footer('Jouer la nuit'));
    return box;
  }
  function footer(label) {
    var pied = h('<div class="pied"></div>');
    if (G.stage === 'ok:' + curStep().id) {
      var c = h('<button type="button" class="btn plein">Continuer ▸<small>' + (G.def.tool === 'plan' ? 'le plan tient : on explique à ' : 'la configuration est acceptée : on explique à ') + esc(nameOf(G.ticket.requester)) + '</small></button>');
      c.addEventListener('click', function () { G.stage = null; advance(); });
      pied.appendChild(c); setTimeout(function () { c.focus(); }, 0);
      return pied;
    }
    var go = h('<button type="button" class="btn plein">' + label + '<small>puis les nuits que tu ne vois pas</small></button>');
    if (G.def.tool === 'form') go = h('<button type="button" class="btn plein">' + label + '<small>à ' + esc(nameOf(G.ticket.requester)) + '</small></button>');
    go.addEventListener('click', deliverNow);
    var reset = h('<button type="button" class="btn petit fantome">Repartir de zéro</button>');
    reset.addEventListener('click', function () { if (reset.dataset.armed) { G.answer = copy(G.def.start); if (G.data.drafts) delete G.data.drafts[G.ticket.id]; save(); renderRight(); return; } reset.dataset.armed = '1'; reset.textContent = 'Confirmer : effacer mon travail'; });
    pied.appendChild(go); pied.appendChild(reset);
    return pied;
  }

  // ——— Configuration forms ———
  function formPanel() {
    var F = G.def.form, A = G.answer;
    var box = h('<div class="panneau outil"></div>');
    if (F.intro) box.appendChild(h('<div class="but">' + esc(F.intro) + '</div>'));
    (F.docs || []).forEach(function (d) { box.appendChild(h('<div class="vue docs' + (d.mono === false ? ' prose' : '') + '"><div class="titre-vue">' + esc(d.title) + '</div><pre>' + esc(d.lines.join('\n')) + '</pre></div>')); });
    F.fields.forEach(function (fd) { box.appendChild(field(fd, A)); });
    box.appendChild(footer(F.deliver || 'Rendre'));
    return box;
  }
  function field(fd, A) {
    var w = h('<div class="champ"><div class="lab">' + esc(fd.label) + '</div></div>');
    if (fd.help) w.appendChild(h('<div style="color:var(--dim);font-size:14px">' + esc(fd.help) + '</div>'));
    var set = function (v) { A[fd.id] = v; keepDraft(); };
    if (fd.type === 'text') {
      var inp = h('<input type="text" class="' + (fd.mono ? 'mono' : '') + '" aria-label="' + esc(fd.label) + '" placeholder="' + esc(fd.placeholder || '') + '" spellcheck="false">');
      inp.value = A[fd.id] || ''; inp.addEventListener('input', function () { set(inp.value); }); w.appendChild(inp);
    } else if (fd.type === 'select') {
      var s = h('<select aria-label="' + esc(fd.label) + '"><option value="">— choisir</option></select>');
      fd.options.forEach(function (o) { s.appendChild(h('<option value="' + esc(o[0]) + '"' + (A[fd.id] === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>')); });
      s.addEventListener('change', function () { set(s.value); }); w.appendChild(s);
    } else if (fd.type === 'checks') {
      var c = h('<div class="cases"></div>'), cur = (A[fd.id] || []).slice();
      fd.options.forEach(function (o) {
        var l = h('<label><input type="checkbox" value="' + esc(o[0]) + '"' + (cur.indexOf(o[0]) !== -1 ? ' checked' : '') + '> ' + esc(o[1]) + '</label>');
        $('input', l).addEventListener('change', function (e) { var v = (A[fd.id] || []).filter(function (x) { return x !== o[0]; }); if (e.target.checked) v.push(o[0]); set(v); });
        c.appendChild(l);
      });
      w.appendChild(c);
    } else if (fd.type === 'table') {
      var rows = A[fd.id] = A[fd.id] || [];
      var n = fd.fixedRows ? fd.fixedRows.length : Math.max(fd.rows || 4, rows.length);
      while (rows.length < n) rows.push({});
      var t = h('<table class="tab"><thead><tr>' + (fd.fixedRows ? '<th></th>' : '') + fd.columns.map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead><tbody></tbody></table>');
      rows.forEach(function (r, i) {
        var tr = h('<tr>' + (fd.fixedRows ? '<td class="rl">' + esc(fd.fixedRows[i]) + '</td>' : '') + '</tr>');
        fd.columns.forEach(function (c) {
          var td = document.createElement('td'), el;
          if (c.type === 'select') { el = h('<select><option value="">—</option></select>'); c.options.forEach(function (o) { el.appendChild(h('<option value="' + esc(o[0]) + '"' + (r[c.id] === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>')); }); el.addEventListener('change', function () { r[c.id] = el.value; keepDraft(); }); }
          else { el = h('<input type="text" spellcheck="false" placeholder="' + esc(c.placeholder || '') + '">'); if (c.mono) el.style.fontFamily = 'var(--mono)'; el.value = r[c.id] || ''; el.addEventListener('input', function () { r[c.id] = el.value; keepDraft(); }); }
          el.setAttribute('aria-label', c.label + (fd.fixedRows ? ' · ' + fd.fixedRows[i] : ' · ligne ' + (i + 1)));
          td.appendChild(el); tr.appendChild(td);
        });
        $('tbody', t).appendChild(tr);
      });
      w.appendChild(h('<div style="overflow-x:auto"></div>')).appendChild(t);
      if (!fd.fixedRows) { var add = h('<button type="button" class="mini" style="justify-self:start">+ une ligne</button>'); add.addEventListener('click', function () { rows.push({}); keepDraft(); renderRight(); }); w.appendChild(add); }
    } else if (fd.type === 'matrix') {
      var m = A[fd.id] = A[fd.id] || {};
      var tb = h('<table class="tab mat"><thead><tr><th></th>' + fd.cols.map(function (c) { return '<th>' + esc(c[1]) + '</th>'; }).join('') + '</tr></thead><tbody></tbody></table>');
      fd.rows.forEach(function (rw) {
        m[rw[0]] = m[rw[0]] || {};
        var tr = h('<tr><td class="rl">' + esc(rw[1]) + '</td></tr>');
        fd.cols.forEach(function (c) {
          var td = h('<td class="c"></td>'), sel = h('<select aria-label="' + esc(rw[1] + ' · ' + c[1]) + '"></select>');
          fd.values.forEach(function (v) { sel.appendChild(h('<option value="' + esc(v[0]) + '"' + ((m[rw[0]][c[0]] || '') === v[0] ? ' selected' : '') + '>' + esc(v[1]) + '</option>')); });
          sel.addEventListener('change', function () { m[rw[0]][c[0]] = sel.value; keepDraft(); });
          td.appendChild(sel); tr.appendChild(td);
        });
        $('tbody', tb).appendChild(tr);
      });
      w.appendChild(h('<div style="overflow-x:auto"></div>')).appendChild(tb);
    }
    return w;
  }

  // ——— Bottom pane: the console and the night ———
  function bottomPane() {
    var st = curStep(), fix = st.kind === 'fix';
    var label = fix && G.def.tool === 'plan' ? 'La nuit' : 'Vérification';
    if (!fix && G.pane !== 'console') G.pane = 'console';
    var box = h('<div class="panneau bas-poste"><div class="tbar" role="tablist"><button type="button" role="tab" data-p="console" aria-selected="' + (G.pane === 'console') + '">Console</button>' + (fix ? '<button type="button" role="tab" data-p="nuit" aria-selected="' + (G.pane === 'nuit') + '">' + label + '</button>' : '') + '<span class="machine">alternant@PC-ARPEGE</span></div><div class="corps" style="display:grid;min-height:0"></div></div>');
    $$('.tbar button', box).forEach(function (b) { b.addEventListener('click', function () { G.pane = b.dataset.p; renderRight(); }); });
    var corps = $('.corps', box);
    if (G.pane === 'nuit') { corps.appendChild(nightPanel()); return box; }
    var cons = h('<div class="console"><div class="sortie" aria-live="polite"></div><div class="entree"><label for="cmd">' + esc(G.cons.prompt) + '</label><input id="cmd" autocomplete="off" spellcheck="false" placeholder="aide, scan, ping dc-kerbrat, nslookup …"></div></div>');
    var out = $('.sortie', cons), input = $('input', cons);
    function paint() { out.innerHTML = G.log.map(function (l) { return '<div class="' + l.c + '">' + esc(l.t) + '</div>'; }).join('') || '<div class="m">Le portable de l\'alternant·e, branché sur le réseau de Limoges. Tape « aide ».</div>'; out.scrollTop = out.scrollHeight; }
    paint();
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp') { if (G.history.length) { G.hist = Math.max(0, (G.hist < 0 ? G.history.length : G.hist) - 1); input.value = G.history[G.hist]; } e.preventDefault(); return; }
      if (e.key === 'ArrowDown') { if (G.hist >= 0) { G.hist++; input.value = G.hist >= G.history.length ? '' : G.history[G.hist]; if (G.hist >= G.history.length) G.hist = -1; } e.preventDefault(); return; }
      if (e.key !== 'Enter') return;
      var line = input.value; input.value = '';
      if (!line.trim()) return;
      G.history.push(line); G.hist = -1;
      if (line.trim() === 'clear') { G.log = []; paint(); return; }
      G.log.push({ c: 'p', t: G.cons.prompt + ' ' + line });
      var r = G.cons.run(line);
      r.out.split('\n').forEach(function (t) { G.log.push({ c: r.err ? 'e' : '', t: t }); });
      paint();
    });
    corps.appendChild(cons);
    return box;
  }
  function nightPanel() {
    var box = h('<div class="nuit"></div>'), r = G.delivery, plan = G.def.tool === 'plan';
    if (!r) { box.innerHTML = '<p style="color:var(--dim);margin:0">' + (plan ? 'Quand ton plan te convient, joue la nuit : elle se déroule heure par heure, puis le plan est rejoué sur des nuits que tu ne vois pas.' : 'Quand ta configuration te convient, rends-la : elle est vérifiée sur ce que voit le demandeur, puis sur des contrôles que tu ne vois pas.') + '</p>'; return box; }
    var vis = r.results.filter(function (x) { return x.visible; }), hid = r.results.filter(function (x) { return !x.visible; }), hidKo = hid.filter(function (x) { return !x.ok; });
    var html = '<div class="bilan ' + (r.passed ? 'ok' : 'ko') + '">' + (r.passed ? (plan ? 'Le plan tient toutes les nuits' : 'Configuration acceptée') : (plan ? 'Le plan ne tient pas' : 'Configuration refusée')) + '</div>';
    if (plan && vis[0] && vis[0].timeline) {
      var tl = vis[0].timeline.filter(function (e) { return e.kind !== 'prep'; }), days = tl.length && (Math.floor(tl[tl.length - 1].t / 1440) !== Math.floor(tl[0].t / 1440));
      var DAY = ['sam.', 'dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.'];
      var stamp = function (t) { return (days ? DAY[((Math.floor(t / 1440) % 7) + 7) % 7] + ' ' : '') + SIM.hour(t); };
      html += '<h3>La nuit visible</h3><ol class="frise">' + vis[0].timeline.map(function (e) { return '<li class="k-' + e.kind + '"><span class="h">' + (e.kind === 'prep' ? 'J-8' : stamp(e.t)) + '</span>' + esc(e.text) + '</li>'; }).join('') + '</ol>';
      if (!vis[0].ok) html += '<div class="res"><div class="ko"><span>' + esc(vis[0].label) + '<small>' + esc(vis[0].detail) + '</small></span></div></div>';
    } else {
      html += '<h3>Ce que vérifie ' + esc(nameOf(G.ticket.requester)) + '</h3><div class="res">' + vis.map(function (x) { return '<div class="' + (x.ok ? '' : 'ko') + '"><span>' + esc(x.label) + (x.ok || !x.detail ? '' : '<small>' + esc(x.detail) + '</small>') + '</span></div>'; }).join('') + '</div>';
    }
    html += '<h3>' + (plan ? 'Nuits' : 'Contrôles') + ' que vous ne voyez pas : ' + (hid.length - hidKo.length) + ' sur ' + hid.length + '</h3>';
    html += '<div class="res">' + hid.map(function (x) { return '<div class="' + (x.ok ? '' : 'ko') + '"><span>' + esc(x.label) + (x.detail ? '<small>' + esc(x.detail) + '</small>' : '') + '</span></div>'; }).join('') + '</div>';
    box.innerHTML = html;
    return box;
  }
  function deliverNow() {
    var st = curStep();
    SND.deliver();
    var r = ST.runCases(G.def, G.answer);
    G.delivery = r; G.pane = 'nuit';
    renderLeft(); renderRight();
    submit(st, { passed: r.passed, failed: r.failed });
  }

  // ——— Sending steps ———
  function submit(st, input) {
    api('submitStep', { session: G.session, step: st.id, input: input }).then(function (res) {
      if (res.ok) {
        SND.right();
        G.reaction = res.reaction ? { r: res.reaction, good: true } : null;
        if (st.kind === 'fix') { G.stage = 'ok:' + st.id; renderLeft(); renderRight(); return; }
        if (res.reaction && (st.kind === 'choice' || st.kind === 'explain')) { G.stage = 'ok:' + st.id; renderLeft(); renderRight(); return; }
        if (res.reaction) { renderLeft(); setTimeout(function () { G.reaction = null; advance(); }, 1100); return; }
        advance();
      } else {
        SND.wrong();
        if (st.kind === 'choice' || st.kind === 'explain') G.wrong[st.id + ':' + input] = true;
        if (st.kind === 'fix') { var n = input.failed ? input.failed.length : 0; G.reaction = { r: { who: 'helene', expr: 'sourcil', text: 'Pas encore : ' + (n ? n + (G.def.tool === 'plan' ? ' nuit' : ' contrôle') + (n > 1 ? 's' : '') + ' ne passe' + (n > 1 ? 'nt' : '') + ' pas. Lis ce qui s\'est passé, corrige, et recommence.' : 'relis le bilan.') }, good: false }; }
        else G.reaction = res.reaction ? { r: res.reaction, good: false } : null;
        renderLeft(); if (st.kind !== 'fix') renderRight();
      }
    }).catch(function (e) { G.reaction = { r: { who: 'helene', expr: 'sourcil', text: 'La plateforme ne répond pas : ' + e.message }, good: false }; renderLeft(); });
  }
  function advance() {
    G.step++; G.reaction = null; G.wrong = {}; G.sel = null; G.order = null; G.stage = null;
    if (G.step >= G.ticket.steps.length) { finishTicket(); return; }
    if (curStep().kind !== 'fix') G.pane = 'console';
    renderLeft(); renderRight();
  }
  function finishTicket() {
    var before = planks();
    api('endTicket', { session: G.session, hints: G.hints }).then(function (res) {
      if (G.data.drafts) { delete G.data.drafts[G.ticket.id]; save(true); }
      SND.stamp();
      var after = function () {
        var go = function () {
          if (res.finished) { showEnd(); return; }
          G.actView = null;
          var ids = (res.newCards || []).map(function (c) { return c.id; });
          var next = function () { if (res.newAct) { actIntro(res.newAct).then(function () { showAtelier(ids); }); } else showAtelier(ids); };
          refreshScenario().then(function () { if (planks() > before) SND.plank(); next(); });
        };
        var afterDone = function () { rewardsScene().then(go); };
        if (G.def && G.def.done && !(G.data.seen && G.data.seen[G.ticket.id + ':d'])) { G.data.seen = G.data.seen || {}; G.data.seen[G.ticket.id + ':d'] = 1; save(); playScene(G.def.done, { tag: 'ATELIER' }).then(afterDone); } else afterDone();
      };
      var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true"><h2>Étape close</h2><div class="etoiles-grandes">' + [1, 2, 3].map(function (i) { return '<i class="' + (i <= res.quality ? 'on' : '') + '"></i>'; }).join('') + '</div>' +
        (res.clue ? '<p style="margin:8px 0 0;font-weight:600;color:var(--fil)">Nouvel indice dans le dossier de la fuite</p><div class="clue">' + esc(res.clue) + '</div>' : '<p>' + esc(res.quality === 3 ? 'Sans faute, sans aide.' : (res.quality === 2 ? 'Bien joué.' : 'Résolu. Tu peux y revenir pour viser les trois étoiles.')) + '</p>') +
        '<div class="actions"><button type="button" class="btn plein">Retour à l\'atelier ▸</button></div></div></div>');
      if (res.clue) SND.clue();
      app.appendChild(w); $('.btn', w).focus();
      $('.btn', w).addEventListener('click', function () { w.remove(); after(); });
    });
  }
  // The collection pieces the platform granted at the closing of a step (SGSdk.onReward).
  function rewardsScene() {
    var list = G.rewards.splice(0);
    if (!list.length || !window.SGRewards) return Promise.resolve();
    return new Promise(function (resolve) {
      SND.clue();
      var FINISH = { brass: 'laiton', silver: 'argent', gold: 'or' };
      var items = list.map(function (r) { return '<li>' + window.SGRewards.art(r.kind, r.code, { size: r.kind === 'card' ? 84 : 72, finish: r.finish || undefined }) + '<span>' + esc(r.name) + (r.finish ? ' · ' + (FINISH[r.finish] || r.finish) : '') + (r.raised ? ' (finition relevée)' : '') + '</span></li>'; }).join('');
      var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true" aria-labelledby="recTitre"><h2 id="recTitre">Dans ta collection</h2><ul class="pieces">' + items + '</ul><div class="actions"><button type="button" class="btn plein">Continuer ▸</button></div></div></div>');
      $('.btn', w).addEventListener('click', function () { w.remove(); resolve(); });
      app.appendChild(w); $('.btn', w).focus();
    });
  }

  // ——— The end ———
  function showEnd() {
    refreshScenario().then(function (sc) {
      var show = function () {
        var stars = 0, n = 0, three = 0; sc.cards.forEach(function (c) { if (c.quality) { stars += c.quality; n++; if (c.quality === 3) three++; } });
        var finish = three >= 36 ? 'or' : (three >= 27 ? 'argent' : 'laiton');
        var view = h('<section class="fin"><div><h1>Delorme Kerbrat Architectes</h1><p>Un réseau, un domaine, une messagerie. Les deux agences sont reliées, et la fuite a un chemin, une date et une explication : la seule migration faite sans plan.</p><p class="grade">' + esc(gradeOf(closedCount())) + '</p><p style="color:var(--dim)">' + stars + ' étoiles sur ' + 3 * n + ', dont ' + three + ' étapes sans erreur ni aide.</p><p>Trophée : <b>l\'Équerre de bascule, ' + finish + '</b>, et son épinglette Passerelle, dans ta vitrine.</p><div class="nav" style="display:flex;gap:10px;flex-wrap:wrap"></div></div><div class="maquette">' + maquetteSVG({ planks: 5, nasGone: true }) + '</div></section>');
        var nav = $('.nav', view);
        var c = h('<button type="button" class="btn">Dossier de la fuite</button>'); c.addEventListener('click', showDossier); nav.appendChild(c);
        var fresh = h('<button type="button" class="btn fantome">Nouvelle partie</button>'); fresh.addEventListener('click', function () { if (!fresh.dataset.armed) { fresh.dataset.armed = '1'; fresh.textContent = 'Confirmer : tout recommencer'; return; } api('newGame', {}).then(function () { location.reload(); }); }); nav.appendChild(fresh);
        var r = h('<button type="button" class="btn plein">Retour à l\'atelier</button>'); r.addEventListener('click', function () { G.review = true; G.actView = 5; showAtelier(); }); nav.appendChild(r);
        app.innerHTML = ''; app.appendChild(view);
      };
      if (!G.data.epilogue) { G.data.epilogue = true; save(true); playScene(STORY.epilogue, { tag: 'ÉPILOGUE' }).then(show); } else show();
    });
  }

  // ——— Start ———
  window.SGSdk.onReward(function (rewards) { G.rewards = G.rewards.concat(rewards); });
  Promise.all([api('getProgress'), api('getScenario')]).then(function (r) {
    G.data = r[0].data || {}; G.data.seen = G.data.seen || {}; G.data.drafts = G.data.drafts || {};
    if (G.data.mute) SND.setMuted(true);
    G.sc = r[1]; G.actView = G.sc.act;
    var go = /open=(E\d+)/.exec(location.hash); // a shortcut for the tests and the screenshots of the prototype
    if (go) { G.data.prologue = true; G.data.seen[go[1] + ':i'] = 1; G.data.seen[go[1] + ':d'] = 1; refreshScenario().then(function () { return api('markActSeen', { act: G.sc.act }); }).then(function () { openTicket(go[1]); }); return; }
    if (/atelier/.test(location.hash)) { G.data.prologue = true; refreshScenario().then(function () { return api('markActSeen', { act: G.sc.act }); }).then(function () { showAtelier(); }); return; }
    showTitle();
  }).catch(function (e) { app.textContent = 'Erreur de chargement : ' + e.message; });
})();
