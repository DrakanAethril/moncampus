/* Radio-Comète — the game's screens: title, scenes, act announcements, the rack (the home: one
   fader per step), the workstation (the request, the script, the terminal), the questions, Colette's
   notebook, the end. The platform is reached through the SDK only (sdk.js); the machines are
   simulated by the Bash engine (vfs.js … fleet.js), rebuilt for every delivery. */
(function () {
  'use strict';
  var app = document.getElementById('app');
  var CH = window.RCChars, SND = window.RCSound, STORY = window.RCStory, ST = window.RCSteps;
  var api = function (m, p) { return window.SGSdk.call(m, p); };

  var G = { rewards: [], data: { seen: {}, drafts: {} }, sc: null, actView: null, session: null, ticket: null, def: null, files: {}, tab: 0, step: 0, errors: 0, hints: 0, guide: [], guideStep: 0, fleet: null, mach: null, sess: null, log: [], history: [], hist: -1, pane: 'terminal', delivery: null, reaction: null, wrong: {}, sel: null, order: null, stage: null };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function h(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }
  function $(sel, root) { return (root || app).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
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
  function closedCount() { return G.sc.cards.filter(function (c) { return c.status === 'done' || c.status === 'reassigned'; }).length; }
  function stars(err, hints) { return err === 0 && hints === 0 ? 3 : (err + hints <= 2 ? 2 : 1); }
  function starsHTML(n) { return '<span class="etoiles">' + [1, 2, 3].map(function (i) { return '<i class="' + (i <= n ? 'on' : '') + '"></i>'; }).join('') + '</span>'; }

  // ——— Drawn parts of the rack ———
  function clockSVG(hh, mm) {
    var ha = ((hh % 12) + mm / 60) * 30, ma = mm * 6, tick = '';
    for (var a = 0; a < 360; a += 30) { var r = a * Math.PI / 180; tick += '<line x1="' + (48 + 38 * Math.sin(r)).toFixed(1) + '" y1="' + (48 - 38 * Math.cos(r)).toFixed(1) + '" x2="' + (48 + 43 * Math.sin(r)).toFixed(1) + '" y2="' + (48 - 43 * Math.cos(r)).toFixed(1) + '" stroke="#14181C" stroke-width="3"/>'; }
    return '<svg class="pendule" viewBox="0 0 96 96" role="img" aria-label="Pendule : ' + pad(hh) + ' h ' + pad(mm) + '"><circle cx="48" cy="48" r="45" fill="#E9E1CF" stroke="#0E1114" stroke-width="4"/>' + tick +
      '<line x1="48" y1="48" x2="48" y2="24" stroke="#14181C" stroke-width="5" stroke-linecap="round" transform="rotate(' + ma + ' 48 48)"/><line x1="48" y1="48" x2="48" y2="32" stroke="#14181C" stroke-width="7" stroke-linecap="round" transform="rotate(' + ha + ' 48 48)"/><circle cx="48" cy="48" r="4" fill="#D8503F"/></svg>';
  }
  function vuSVG(v, label) {
    var ang = (150 - Math.max(0, Math.min(1, v)) * 120) * Math.PI / 180, cx = 80, cy = 96, r = 70;
    return '<div class="vu"><svg viewBox="0 0 160 106" role="img" aria-label="' + esc(label) + ' : ' + Math.round(v * 100) + ' %"><path d="M14 84 A70 70 0 0 1 146 84" fill="none" stroke="#2A3138" stroke-width="2"/><path d="M112 36 A70 70 0 0 1 146 84" fill="none" stroke="#D8503F" stroke-width="6"/>' +
      '<line x1="' + cx + '" y1="' + cy + '" x2="' + (cx + r * Math.cos(ang)).toFixed(1) + '" y2="' + (cy - r * Math.sin(ang)).toFixed(1) + '" stroke="#14181C" stroke-width="3"/><circle cx="' + cx + '" cy="' + cy + '" r="6" fill="#14181C"/></svg><div class="nom">' + esc(label) + '</div></div>';
  }

  // ——— Scenes ———
  function playScene(lines, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var scene = h('<section class="scene" aria-label="Scène"><div class="haut"><div class="tags"><span>' + esc(opts.tag || 'RÉGIE') + '</span></div><button type="button" class="btn petit fantome passer">Passer</button></div><div class="scene-zone"><div class="perso gauche" aria-hidden="true"></div><div class="perso droite" aria-hidden="true"></div></div>' +
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
          $('.nom b', scene).textContent = nameOf(l.who).toUpperCase(); $('.nom span', scene).textContent = CH.role(l.who);
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
  function deco(n) { var s = ''; for (var i = 0; i < n; i++) s += '<div class="fader-deco"><i></i><b style="top:' + ((i * 37) % 44) + 'px"></b></div>'; return s; }
  function showTitle() {
    app.innerHTML = '';
    var started = !!G.data.prologue, done = closedCount();
    var view = h('<section class="titre"><div class="bois g"></div><div class="bois d"></div><div class="chassis"><div class="u haut">' + clockSVG(2, 58) + '<div class="dymo">Radio-Comète · Régie 2</div><div class="onair">ON AIR</div></div>' +
      '<div class="ecran"><div><div class="sur">RADIO COMÈTE · 3 H 00</div><h1>0 3 * * *</h1><p class="sous">« Tout est dans les scripts. » Chaque nuit à 3 h, quelque chose s\'exécute que personne n\'a écrit. Alternant·e du prestataire, vous avez jusqu\'à vendredi matin pour trouver quoi, sous l\'œil de Colette.</p><div class="nav"></div></div></div>' +
      '<div class="u bas" style="display:flex;justify-content:center;gap:34px;padding:16px">' + deco(8) + '</div></div></section>');
    var nav = $('.nav', view);
    var first = h('<button type="button" class="btn plein">' + (started ? 'Reprendre la nuit' : 'Commencer') + '<small>' + (started ? 'Acte ' + G.sc.act + ' · ' + esc(G.sc.acts[G.sc.act - 1].title) + ' — ' + done + ' étapes closes' : 'Acte 1 · Le mot de Marc') + '</small></button>');
    first.addEventListener('click', function () { SND.click(); startGame(); });
    nav.appendChild(first);
    if (started) {
      var again = h('<button type="button" class="btn">Revoir le prologue</button>');
      again.addEventListener('click', function () { playScene(STORY.prologue, { tag: 'PROLOGUE' }); });
      nav.appendChild(again);
      if (G.sc.clues.length) { var cb = h('<button type="button" class="btn fantome">Carnet de Colette</button>'); cb.addEventListener('click', showCarnet); nav.appendChild(cb); }
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
    if (!G.data.prologue) playScene(STORY.prologue, { tag: 'PROLOGUE' }).then(function () { G.data.prologue = true; save(true); showRegie(); });
    else showRegie();
  }

  // ——— Act announcement ———
  function actIntro(n) {
    return new Promise(function (resolve) {
      SND.act();
      var a = G.sc.acts[n - 1], st = STORY.acts[n] || {};
      var list = G.sc.acts.map(function (x) {
        var cls = x.n < n ? 'fait' : (x.n === n ? 'ici' : '');
        return '<li class="' + cls + '"><b>0' + x.n + '</b><span style="flex:1;font-weight:600">' + (x.n <= n ? esc(x.title) : '· · ·') + '</span><span style="font:600 13px var(--mono)">' + (x.n < n ? 'TERMINÉ' : (x.n === n ? 'À JOUER' : '')) + '</span></li>';
      }).join('');
      var view = h('<section class="acte-intro" aria-label="Acte ' + n + '"><div><div class="tags"><span>ACTE SUIVANT</span><span style="background:#2D353C;color:#E9E1CF">' + esc(st.time || '') + '</span></div><div class="num"><small>ACTE</small><b>0' + n + '</b></div><h1>' + esc(a.title) + '</h1><p>' + esc(st.blurb || '') + '</p><div class="notions">' + (st.notions || []).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div><button type="button" class="btn plein go" style="margin-top:14px">Entrer dans l\'acte ▸</button></div><ol class="liste" aria-label="Les actes" style="list-style:none">' + list + '</ol></section>');
      app.appendChild(view);
      $('.go', view).focus();
      $('.go', view).addEventListener('click', function () { api('markActSeen', { act: n }).then(function () { view.remove(); resolve(); }); });
    });
  }

  // ——— The rack (home) ———
  function refreshScenario() { return api('getScenario').then(function (sc) { G.sc = sc; if (!G.actView || G.actView > sc.act) G.actView = sc.act; return sc; }); }
  function showRegie(newIds) {
    if (!Array.isArray(newIds)) newIds = [];
    G.ticket = null; G.def = null;
    refreshScenario().then(function (sc) {
      if (sc.finished && !G.review) { showEnd(); return; }
      if (!sc.actSeen && !sc.finished) { actIntro(sc.act).then(function () { showRegie(newIds); }); return; }
      var act = G.actView || sc.act, st = STORY.acts[act] || { clock: [10, 20] }, done = closedCount();
      var stars3 = 0, nStars = 0;
      sc.cards.forEach(function (c) { if (c.quality) { stars3 += c.quality; nStars++; } });
      var view = h('<section class="regie"><div class="bois g"></div><div class="bois d"></div><div class="chassis"><div class="u haut">' + clockSVG(st.clock[0], st.clock[1]) +
        '<div style="text-align:center"><div class="etiquette">Acte ' + act + ' · ' + esc(st.time || '') + '</div><div class="dymo" style="margin-top:6px">' + esc(sc.acts[act - 1].title) + '</div></div><div class="onair' + (sc.cards.some(function (c) { return c.status === 'doing'; }) ? '' : ' eteint') + '">ON AIR</div></div>' +
        '<div class="milieu"><div class="u cote">' + vuSVG(done / sc.total, 'Étapes closes') + vuSVG(nStars ? (stars3 / (3 * nStars)) : 0, 'Étoiles') + '<div class="etiquette" style="text-align:center">Grade</div><div class="dymo" style="text-align:center">' + esc(gradeOf(done)) + '</div></div>' +
        '<div class="ecran"></div><div class="u cote"><div class="etiquette" style="text-align:center">Pistes de Colette</div><div class="leds">' + [0, 1, 2, 3, 4].map(function (i) { return '<i class="amb ' + (i < sc.clues.length ? 'on' : '') + '"></i>'; }).join('') + '</div><div class="barre" style="flex-direction:column"></div></div></div>' +
        '<div class="u" style="padding:6px 8px"><div class="actes"></div><div class="bas"></div></div></div></section>');
      var ecran = $('.ecran', view);
      ecran.innerHTML = '<div class="acte">ACTE ' + act + ' SUR 5</div><h2>' + esc(sc.acts[act - 1].title) + '</h2><p>' + esc((STORY.acts[act] || {}).blurb || '') + '</p>';
      var open = sc.cards.filter(function (c) { return c.status === 'todo' || c.status === 'doing'; });
      var rank = { 'tres-haute': 0, haute: 1, moyenne: 2, basse: 3 };
      var next = open.filter(function (c) { return c.status === 'doing'; })[0] || open.slice().sort(function (a, b) { return (rank[a.priority] - rank[b.priority]) || (a.id > b.id ? 1 : -1); })[0];
      if (next && act === sc.act) ecran.appendChild(h('<div class="consigne"><div class="tete">' + CH.svg('colette', 'sourire') + '</div><div><b>COLETTE</b>' + esc(next.status === 'doing' ? 'Reprends « ' + next.title + ' » : tu l\'as commencée.' : 'Prends « ' + next.title + ' ». ' + (open.length > 1 ? 'Les faders allumés sont ouverts, tu choisis l\'ordre.' : '')) + '</div></div>'));
      var bar = $('.barre', view);
      var carnet = h('<button type="button" class="btn petit fantome">Carnet</button>'); carnet.addEventListener('click', showCarnet); bar.appendChild(carnet);
      var back = h('<button type="button" class="btn petit fantome">Écran titre</button>'); back.addEventListener('click', function () { save(true); showTitle(); }); bar.appendChild(back);
      var son = h('<button type="button" class="btn petit fantome">' + (SND.muted() ? 'Son coupé' : 'Son') + '</button>'); son.addEventListener('click', function () { SND.setMuted(!SND.muted()); G.data.mute = SND.muted(); save(); son.textContent = SND.muted() ? 'Son coupé' : 'Son'; }); bar.appendChild(son);
      var actes = $('.actes', view);
      sc.acts.forEach(function (a) { var b = h('<button type="button" aria-current="' + (a.n === act) + '"' + (a.n > sc.act ? ' disabled' : '') + '>Acte ' + a.n + '</button>'); b.addEventListener('click', function () { G.actView = a.n; showRegie(); }); actes.appendChild(b); });
      var bas = $('.bas', view);
      for (var k = 1; k <= 8; k++) {
        (function (id) {
          var c = sc.cards.filter(function (x) { return x.id === id; })[0];
          var cls = !c ? 'fermee' : (c.status === 'doing' ? 'encours ouverte' : (c.status === 'todo' ? 'ouverte' : 'faite'));
          var top = !c ? 118 : (c.status === 'doing' ? 56 : (c.status === 'todo' ? 76 : 2));
          var g = h('<button type="button" class="glissiere ' + cls + (newIds.indexOf(id) !== -1 ? ' nouveau' : '') + '"' + (!c ? ' disabled aria-label="Étape à venir"' : '') + '><span class="rail"><span class="num">' + id.slice(1) + '</span><span class="pion" style="top:' + top + 'px"></span></span><span class="nom">' + esc(c ? c.title : '· · ·') + '</span>' + (c && c.quality ? starsHTML(c.quality) : '<span class="etoiles"></span>') + '</button>');
          if (c) g.addEventListener('click', function () { SND.fader(); if (c.status === 'done' || c.status === 'reassigned') reviewTicket(c); else openTicket(c.id); });
          bas.appendChild(g);
        })(idFor(act, k));
      }
      app.innerHTML = ''; app.appendChild(view);
      var f = $('.glissiere.ouverte', view) || $('.glissiere:not(.fermee)', view); if (f) f.focus();
    });
  }
  function reviewTicket(c) {
    var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true"><h2>' + esc(c.title) + '</h2><p>Étape close' + (c.quality ? ' · ' + c.quality + ' étoile' + (c.quality > 1 ? 's' : '') + ' sur 3' : '') + '.</p><div class="actions"><button type="button" class="btn plein">Fermer</button></div></div></div>');
    $('.btn', w).addEventListener('click', function () { w.remove(); });
    app.appendChild(w); $('.btn', w).focus();
  }

  // ——— Colette's notebook ———
  var CLUE_ORDER = 5;
  function showCarnet() {
    refreshScenario().then(function (sc) {
      var view = h('<section class="carnet"><header class="bar"><div class="logo">RADIO-COMÈTE</div><nav><button type="button">Régie</button><button type="button" aria-current="page">Carnet de Colette</button></nav></header><div class="corps"></div></section>');
      $$('nav button', view)[0].addEventListener('click', function () { showRegie(); });
      var corps = $('.corps', view);
      corps.appendChild(h('<h1>Le carnet de Colette</h1>'));
      corps.appendChild(h('<p style="font-size:19px;margin:0">Chaque piste, Colette la note. Il y en a cinq : réunies, elles disent qui lance la tâche de 3 h, et pourquoi.</p>'));
      for (var i = 0; i < CLUE_ORDER; i++) corps.appendChild(h('<div class="piste' + (sc.clues[i] ? '' : ' vide') + '"><b>' + (i + 1) + '</b><span>' + esc(sc.clues[i] || 'Une piste reste à trouver.') + '</span></div>'));
      app.innerHTML = ''; app.appendChild(view);
    });
  }

  // ——— The workstation ———
  function bar(current) {
    var b = h('<header class="bar"><div class="logo">RADIO-COMÈTE</div><nav aria-label="Navigation"></nav><div class="droite"></div></header>');
    var nav = $('nav', b);
    [['regie', 'Régie', function () { save(true); showRegie(); }], ['carnet', 'Carnet', function () { save(true); showCarnet(); }]].forEach(function (x) {
      var bt = h('<button type="button"' + (x[0] === current ? ' aria-current="page"' : '') + '>' + x[1] + '</button>'); bt.addEventListener('click', function () { SND.click(); x[2](); }); nav.appendChild(bt);
    });
    var d = $('.droite', b), st = STORY.acts[G.ticket ? G.ticket.act : 1] || {};
    d.appendChild(h('<span class="chip">Acte ' + (G.ticket ? G.ticket.act : '') + '</span>'));
    if (G.def && G.def.machine) d.appendChild(h('<span class="chip">admin@' + esc(G.def.machine) + '</span>'));
    d.appendChild(h('<span class="heure">' + esc((st.time || '').replace(' H ', ' h ')) + '</span>'));
    return b;
  }
  function openTicket(id) {
    api('startTicket', { ticket: id }).then(function (r) {
      G.session = r.session; G.ticket = r.ticket; G.step = r.step; G.errors = 0; G.hints = r.hints || 0; G.guide = r.guide || []; G.guideStep = r.guideStep || 0;
      G.def = ST.get(id) || null; G.reaction = null; G.wrong = {}; G.sel = null; G.order = null; G.delivery = null; G.pane = 'terminal'; G.tab = 0; G.log = []; G.history = []; G.hist = -1; G.stage = null;
      var drafts = (G.data.drafts && G.data.drafts[id]) || {};
      G.files = {};
      if (G.def) { G.def.edit.forEach(function (f) { G.files[f] = drafts[f] !== undefined ? drafts[f] : G.def.start[f]; }); G.fleet = ST.consoleFleet(G.def, G.files); G.mach = G.fleet.get(G.def.machine); G.sess = G.mach.session(); }
      var begin = function () { renderPoste(); };
      if (G.def && G.def.intro && !(G.data.seen && G.data.seen[id + ':i'])) { G.data.seen = G.data.seen || {}; G.data.seen[id + ':i'] = 1; save(); playScene(G.def.intro, { tag: 'RÉGIE' }).then(begin); } else begin();
    }).catch(function (e) { alert('Étape inaccessible : ' + e.message); showRegie(); });
  }
  function curStep() { return G.ticket.steps[G.step]; }
  function rungs() { return G.guide.length + (G.def ? G.def.hints.length : 0); }
  function guideOpen() { return G.guideStep < G.guide.length && G.hints > G.guideStep ? G.guide[G.guideStep] : null; }

  function renderPoste() {
    app.innerHTML = '';
    var view = h('<section class="poste"></section>');
    view.appendChild(bar('poste'));
    var grille = h('<div class="grille"><div class="col gauche"></div><div class="col droite"></div></div>');
    view.appendChild(grille);
    app.appendChild(view);
    renderLeft(); renderRight();
  }

  function reactionHTML(r, good) { return '<div class="reaction' + (good ? ' bien' : '') + '"><div class="tete">' + CH.svg(r.who || 'colette', r.expr || 'neutre') + '</div><div><b>' + esc(nameOf(r.who || 'colette')) + '</b>' + esc(r.text) + '</div></div>'; }

  function renderLeft() {
    var col = $('.col.gauche'), t = G.ticket, st = curStep();
    col.innerHTML = '';
    var p = h('<div class="panneau"></div>');
    p.innerHTML = '<div class="demandeur"><div class="tete">' + CH.svg(t.requester, 'neutre') + '</div><div><b>' + esc(nameOf(t.requester)) + '</b><small>' + esc(CH.role(t.requester)) + '</small></div></div>' +
      '<h1 class="titre-etape">' + esc(t.title) + '</h1><p class="demande">' + esc(t.request) + '</p>' +
      '<div class="avancement">' + t.steps.map(function (s, i) { return '<i class="' + (i < G.step ? 'fait' : (i === G.step ? 'ici' : '')) + '" title="' + esc(s.label || s.kind) + '"></i>'; }).join('') + '</div>';
    if (st && st.kind === 'fix' && G.def) {
      var vis = G.def.cases.filter(function (c) { return c.visible; }), hid = G.def.cases.length - vis.length, res = G.delivery;
      var items = vis.map(function (c) { var r = res && res.results.filter(function (x) { return x.id === c.id; })[0]; return '<li class="' + (r ? (r.ok ? 'ok' : 'ko') : '') + '">' + esc(nameOf(t.requester) + ' vérifiera : ' + c.label) + '</li>'; }).join('');
      p.appendChild(h('<div><h2>Critères</h2><ul class="criteres">' + items + '<li class="cache">…et sur ' + hid + ' machines que vous ne voyez pas, qui ne ressemblent pas toutes à celle-ci.</li></ul></div>'));
    }
    if (G.reaction) p.appendChild(h(reactionHTML(G.reaction.r, G.reaction.good)));
    col.appendChild(p);
    if (st && st.kind === 'fix' && G.def && rungs()) col.appendChild(helpPanel());
  }
  function helpPanel() {
    var p = h('<div class="panneau aide"><h2>Colette</h2></div>'), used = Math.min(G.hints, rungs());
    var gq = guideOpen();
    var articlesShown = Math.max(0, G.hints - G.guide.length);
    if (G.guideStep >= G.guide.length || !G.guide.length) { /* guided questions finished */ }
    if (gq) {
      var q = h('<div><b style="font-family:var(--titre);letter-spacing:.1em;color:var(--vert)">QUESTION DE COLETTE</b><p style="margin:6px 0">' + esc(gq.prompt) + '</p><div class="options"></div></div>');
      gq.options.forEach(function (o, i) {
        var b = h('<button type="button" class="option' + (G.wrong['g' + G.guideStep + ':' + i] ? ' faux' : '') + '"><b>' + String.fromCharCode(65 + i) + '</b><span>' + esc(o) + '</span></button>');
        b.addEventListener('click', function () { submitGuide(gq, i); });
        $('.options', q).appendChild(b);
      });
      p.appendChild(q);
    }
    if (articlesShown > 0 && G.def) {
      for (var i = 0; i < Math.min(articlesShown, G.def.hints.length); i++) {
        var a = G.def.hints[i];
        p.appendChild(h('<div class="art"><b>' + esc(a.title) + '</b><pre>' + esc(a.body) + '</pre></div>'));
      }
    }
    var more = G.hints < rungs() && !gq;
    if (more) {
      var b = h('<button type="button" class="btn petit" style="margin-top:10px">' + (G.hints === 0 ? 'Demander à Colette' : 'Un coup de pouce de plus') + '<small>coûte une étoile</small></button>');
      b.addEventListener('click', function () { api('useHint', { session: G.session }).then(function (r) { G.hints = r.hints; G.guideStep = r.guideStep; SND.click(); renderLeft(); }); });
      p.appendChild(b);
    }
    if (!gq && !more && G.hints === 0) p.appendChild(h('<p style="color:var(--dim)">Colette connaît ce script. Demande-lui, si tu bloques.</p>'));
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
    var withTerm = !!(G.def && G.def.machine);
    col.style.gridTemplateRows = withTerm ? '' : '1fr';
    col.appendChild(atelier(st));
    if (withTerm) col.appendChild(terminalPanel());
  }

  // ——— The editor ———
  function atelier(st) {
    var box;
    if (st.kind === 'fix') return editorPanel();
    box = h('<div class="panneau question"></div>');
    if (st.kind === 'choice' || st.kind === 'explain') {
      box.innerHTML = '<h2>' + esc(st.prompt) + '</h2><div class="options"></div>';
      var opts = $('.options', box), ready = G.stage === 'ok:' + st.id;
      st.options.forEach(function (o, i) {
        var b = h('<button type="button" class="option' + (G.wrong[st.id + ':' + i] ? ' faux' : '') + '"' + (ready ? ' disabled' : '') + '><b>' + String.fromCharCode(65 + i) + '</b><span>' + esc(o) + '</span></button>');
        b.addEventListener('click', function () { submit(st, i); });
        opts.appendChild(b);
      });
      if (ready) { var c = h('<button type="button" class="btn plein">Continuer ▸</button>'); c.addEventListener('click', function () { G.stage = null; G.reaction = null; advance(); }); box.appendChild(c); c.focus(); }
    } else if (st.kind === 'line') {
      var v = G.def ? ST.view(G.def, st.view) : null;
      box.innerHTML = '<h2>' + esc(st.prompt) + '</h2><div class="vue"><div class="titre-vue">' + esc(v ? v.title : '') + '</div></div><div><button type="button" class="btn plein valider" disabled>Valider cette ligne</button></div>';
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

  function currentFiles() { return Object.assign({}, G.files); }
  function editorPanel() {
    var def = G.def, tabs = def.edit.map(function (f) { return { name: f, edit: true }; }).concat(def.show.map(function (f) { return { name: f, edit: false }; }));
    if (G.tab >= tabs.length) G.tab = 0;
    var tab = tabs[G.tab];
    var box = h('<div class="panneau atelier"><div class="onglets" role="tablist"></div><div class="editeur"><div class="nums" aria-hidden="true"></div><textarea spellcheck="false" autocapitalize="off" autocomplete="off" aria-label="Contenu du fichier"></textarea></div><div class="pied-editeur"></div></div>');
    var ongl = $('.onglets', box);
    tabs.forEach(function (t, i) {
      var b = h('<button type="button" role="tab" aria-selected="' + (i === G.tab) + '">' + esc(t.name.replace(/^.*\//, '')) + (t.edit ? '' : '<span class="ro">lecture seule</span>') + '</button>');
      b.addEventListener('click', function () { G.tab = i; renderRight(); }); ongl.appendChild(b);
    });
    var ta = $('textarea', box), nums = $('.nums', box);
    var content;
    if (tab.edit) content = G.files[tab.name]; else { try { content = G.mach.vfs.read(tab.name); } catch (e) { content = '(fichier absent)'; } ta.readOnly = true; }
    ta.value = content;
    function count() { var n = ta.value.split('\n').length, s = ''; for (var i = 1; i <= n; i++) s += i + '\n'; nums.textContent = s; }
    count();
    ta.addEventListener('scroll', function () { nums.scrollTop = ta.scrollTop; });
    ta.addEventListener('input', function () {
      G.files[tab.name] = ta.value; count();
      G.data.drafts = G.data.drafts || {}; (G.data.drafts[G.ticket.id] = G.data.drafts[G.ticket.id] || {})[tab.name] = ta.value; save();
    });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Tab' && tab.edit) { e.preventDefault(); var s = ta.selectionStart; ta.value = ta.value.slice(0, s) + '    ' + ta.value.slice(ta.selectionEnd); ta.selectionStart = ta.selectionEnd = s + 4; ta.dispatchEvent(new Event('input')); }
    });
    var pied = $('.pied-editeur', box);
    var deliver = h('<button type="button" class="btn plein">Livrer<small>à ' + esc(nameOf(G.ticket.requester)) + '</small></button>');
    deliver.addEventListener('click', function () { deliverNow(); });
    var reset = h('<button type="button" class="btn petit fantome">Revenir au script de départ</button>');
    reset.addEventListener('click', function () { if (reset.dataset.armed) { def.edit.forEach(function (f) { G.files[f] = def.start[f]; }); if (G.data.drafts) delete G.data.drafts[G.ticket.id]; save(); reset.dataset.armed = ''; renderRight(); return; } reset.dataset.armed = '1'; reset.textContent = 'Confirmer : perdre mes changements'; });
    pied.appendChild(deliver); pied.appendChild(reset);
    pied.appendChild(h('<span class="etat">' + (/\.sh$/.test(def.edit[0]) ? 'Teste dans le terminal : bash ' + esc(def.edit[0].replace(/^.*\//, '')) : 'Teste dans le terminal : systemctl, journalctl…') + '</span>'));
    setTimeout(function () { if (tab.edit) ta.focus(); }, 0);
    return box;
  }

  // ——— The terminal ———
  function prompt() { return 'admin@' + G.def.machine + ':' + (G.sess.cwd() === '/home/admin' ? '~' : G.sess.cwd().replace(/^\/home\/admin/, '~')) + '$'; }
  function terminalPanel() {
    var box = h('<div class="panneau terminal"><div class="tbar" role="tablist"><button type="button" role="tab" data-p="terminal" aria-selected="' + (G.pane === 'terminal') + '">Terminal</button><button type="button" role="tab" data-p="livraison" aria-selected="' + (G.pane === 'livraison') + '">Livraison</button><span class="machine">' + esc(G.def.machine) + '</span></div><div class="corps" style="display:grid;min-height:0"></div></div>');
    $$('.tbar button', box).forEach(function (b) { b.addEventListener('click', function () { G.pane = b.dataset.p; renderRight(); }); });
    var corps = $('.corps', box);
    if (G.pane === 'livraison') { corps.appendChild(livraisonPanel()); return box; }
    var cons = h('<div class="console"><div class="sortie" aria-live="polite"></div><div class="entree"><label for="cmd"></label><input id="cmd" autocomplete="off" spellcheck="false" placeholder="ls, cat, bash script.sh, crontab -l, ssh serveur …"></div></div>');
    var out = $('.sortie', cons), input = $('input', cons);
    $('label', cons).textContent = prompt();
    function paint() {
      out.innerHTML = G.log.map(function (l) { return '<div class="' + l.c + '">' + esc(l.t) + '</div>'; }).join('') || '<div class="m">Un vrai Bash simulé : essaie « ls », « cat », « bash ' + esc(G.def.edit[0] ? G.def.edit[0].replace(/^.*\//, '') : 'script.sh') + ' ».</div>';
      out.scrollTop = out.scrollHeight;
    }
    paint();
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp') { if (G.history.length) { G.hist = Math.max(0, (G.hist < 0 ? G.history.length : G.hist) - 1); input.value = G.history[G.hist]; } e.preventDefault(); return; }
      if (e.key === 'ArrowDown') { if (G.hist >= 0) { G.hist++; input.value = G.hist >= G.history.length ? '' : G.history[G.hist]; if (G.hist >= G.history.length) G.hist = -1; } e.preventDefault(); return; }
      if (e.key !== 'Enter') return;
      var line = input.value; input.value = '';
      if (!line.trim()) return;
      G.history.push(line); G.hist = -1;
      G.log.push({ c: 'p', t: prompt() + ' ' + line });
      if (line.trim() === 'clear') { G.log = []; paint(); return; }
      ST.writeFiles(G.mach, G.files);
      var r = G.sess.run(line);
      r.ev.forEach(function (ev) { ev.s.replace(/\n$/, '').split('\n').forEach(function (t, k, all) { if (k === all.length - 1 && t === '' && all.length > 1) return; G.log.push({ c: ev.fd === 2 ? 'e' : '', t: t }); }); });
      $('label', cons).textContent = prompt();
      paint();
    });
    corps.appendChild(cons);
    return box;
  }

  function livraisonPanel() {
    var box = h('<div class="livraison"></div>'), r = G.delivery;
    if (!r) { box.innerHTML = '<p style="color:var(--dim)">Quand ton script te convient, livre-le : il est rejoué sur la machine visible et sur celles que tu ne vois pas.</p>'; return box; }
    var vis = r.results.filter(function (x) { return x.visible; }), hid = r.results.filter(function (x) { return !x.visible; }), hidKo = hid.filter(function (x) { return !x.ok; });
    var html = '<div class="bilan ' + (r.passed ? 'ok' : 'ko') + '">' + (r.passed ? 'Livraison acceptée' : 'Livraison refusée') + '</div><h3>Machine visible</h3><div class="res">' +
      vis.map(function (x) { return '<div class="' + (x.ok ? 'ok' : 'ko') + '"><span>' + esc(x.label) + (x.ok || !x.detail ? '' : '<small>' + esc(x.detail) + '</small>') + '</span></div>'; }).join('') + '</div>';
    var shown = vis[0];
    if (shown && shown.out) html += '<h3>Ce que le script a affiché</h3><pre>' + esc(shown.out.replace(/\n$/, '')) + '</pre>';
    html += '<h3>Machines que vous ne voyez pas : ' + (hid.length - hidKo.length) + ' sur ' + hid.length + '</h3>';
    if (hidKo.length) html += '<div class="res">' + hidKo.map(function (x) { return '<div class="ko"><span>' + esc(x.label) + (x.detail ? '<small>' + esc(x.detail) + '</small>' : '') + '</span></div>'; }).join('') + '</div>';
    else if (hid.length) html += '<p style="color:var(--vert)">Aucune ne résiste.</p>';
    box.innerHTML = html;
    return box;
  }

  function deliverNow() {
    var def = G.def, st = curStep();
    SND.deliver();
    var r = ST.runCases(def, currentFiles());
    G.delivery = r; G.pane = 'livraison';
    renderLeft(); renderRight();
    submit(st, { passed: r.passed, failed: r.failed });
  }

  // ——— Sending steps ———
  function submit(st, input) {
    api('submitStep', { session: G.session, step: st.id, input: input }).then(function (res) {
      if (res.ok) {
        SND.right();
        G.reaction = res.reaction ? { r: res.reaction, good: true } : null;
        if (st.kind === 'fix') { renderLeft(); setTimeout(advance, 900); return; }
        if (res.reaction && (st.kind === 'choice' || st.kind === 'explain')) { G.stage = 'ok:' + st.id; renderLeft(); renderRight(); return; }
        if (res.reaction) { renderLeft(); setTimeout(function () { G.reaction = null; advance(); }, 1100); return; }
        advance();
      } else {
        G.errors++; SND.wrong();
        if (st.kind === 'choice' || st.kind === 'explain') G.wrong[st.id + ':' + input] = true;
        if (st.kind === 'fix') { G.reaction = { r: { who: 'colette', expr: 'sourcil', text: 'Pas encore : ' + (input.failed && input.failed.length ? input.failed.length + ' machine' + (input.failed.length > 1 ? 's' : '') + ' ne passe' + (input.failed.length > 1 ? 'nt' : '') + ' pas. Lis la livraison, teste dans le terminal, corrige.' : 'relis la livraison.') }, good: false }; }
        else G.reaction = res.reaction ? { r: res.reaction, good: false } : null;
        renderLeft(); if (st.kind !== 'fix') renderRight();
      }
    }).catch(function (e) { alert('Erreur : ' + e.message); });
  }
  function advance() {
    G.step++; G.reaction = null; G.wrong = {}; G.sel = null; G.order = null; G.stage = null;
    if (G.step >= G.ticket.steps.length) { finishTicket(); return; }
    renderLeft(); renderRight();
  }
  function finishTicket() {
    api('endTicket', { session: G.session, hints: G.hints }).then(function (res) {
      if (G.data.drafts) { delete G.data.drafts[G.ticket.id]; save(true); }
      SND.stamp();
      var after = function () {
        var go = function () {
          if (res.finished) { showEnd(); return; }
          G.actView = null;
          var ids = (res.newCards || []).map(function (c) { return c.id; });
          if (res.newAct) { refreshScenario().then(function () { actIntro(res.newAct).then(function () { showRegie(ids); }); }); } else showRegie(ids);
        };
        var afterDone = function () { rewardsScene().then(go); };
        if (G.def && G.def.done && !(G.data.seen && G.data.seen[G.ticket.id + ':d'])) { G.data.seen = G.data.seen || {}; G.data.seen[G.ticket.id + ':d'] = 1; save(); playScene(G.def.done, { tag: 'RÉGIE' }).then(afterDone); } else afterDone();
      };
      var w = h('<div class="voile"><div class="fenetre indice-fenetre" role="dialog" aria-modal="true"><h2>Étape close</h2><div class="etoiles-grandes">' + [1, 2, 3].map(function (i) { return '<i class="' + (i <= res.quality ? 'on' : '') + '"></i>'; }).join('') + '</div>' +
        (res.clue ? '<p style="margin:12px 0 0;color:var(--vert);font-family:var(--titre);letter-spacing:.14em">NOUVELLE PISTE DANS LE CARNET</p><div class="clue">' + esc(res.clue) + '</div>' : '<p>' + esc(res.quality === 3 ? 'Sans faute, sans aide.' : (res.quality === 2 ? 'Bien joué.' : 'Résolu. Tu peux y revenir pour viser les trois étoiles.')) + '</p>') +
        '<div class="actions"><button type="button" class="btn plein">Retour à la régie ▸</button></div></div></div>');
      if (res.clue) SND.clue();
      app.appendChild(w); $('.btn', w).focus();
      $('.btn', w).addEventListener('click', function () { w.remove(); after(); });
    });
  }

  // The collection pieces the platform granted at the closing of a step (SGSdk.onReward): a card per
  // act done, the Fader d'or and its Comète pin once the final is closed, its finish raised by the
  // steps with three stars. Drawn by the platform's shared module, when the page loaded it.
  function rewardsScene() {
    var list = G.rewards.splice(0);
    if (!list.length || !window.SGRewards) return Promise.resolve();
    return new Promise(function (resolve) {
      SND.clue();
      var FINISH = { brass: 'laiton', silver: 'argent', gold: 'or' };
      var items = list.map(function (r) {
        var art = window.SGRewards.art(r.kind, r.code, { size: r.kind === 'card' ? 84 : 72, finish: r.finish || undefined });
        return '<li class="piece">' + art + '<span>' + esc(r.name) + (r.finish ? ' · ' + (FINISH[r.finish] || r.finish) : '') + (r.raised ? ' (finition relevée)' : '') + '</span></li>';
      }).join('');
      var w = h('<div class="voile"><div class="fenetre recompenses" role="dialog" aria-modal="true" aria-labelledby="recompensesTitre"><h2 id="recompensesTitre">Dans ta collection</h2><ul class="pieces">' + items + '</ul><div class="actions"><button type="button" class="btn plein">Continuer ▸</button></div></div></div>');
      $('.btn', w).addEventListener('click', function () { w.remove(); resolve(); });
      app.appendChild(w); $('.btn', w).focus();
    });
  }

  // ——— The end ———
  function showEnd() {
    refreshScenario().then(function (sc) {
      var show = function () {
        var stars3 = 0, n = 0, three = 0; sc.cards.forEach(function (c) { if (c.quality) { stars3 += c.quality; n++; if (c.quality === 3) three++; } });
        var finish = three >= 36 ? 'or' : (three >= 27 ? 'argent' : 'laiton');
        var view = h('<section class="fin"><div><h1>3 h 00 : fin de la nuit</h1><p>Le nettoyage a été réécrit avec des garde-fous, la tâche de 3 h est un timer qui se méfie de lui-même, et les archives de Yanis sont revenues.</p><p style="font-family:var(--titre);font-size:30px;letter-spacing:.08em;color:var(--ambre)">' + esc(gradeOf(closedCount())) + '</p><p style="color:var(--dim)">' + stars3 + ' étoiles sur ' + 3 * n + ', dont ' + three + ' étapes sans erreur ni aide.</p><p>Trophée : <b style="color:var(--ambre)">le Fader d\'or, ' + finish + '</b> et son épinglette Comète, dans ta vitrine.</p><div class="nav" style="display:flex;gap:12px;justify-content:center;margin-top:18px"></div></div></section>');
        var nav = $('.nav', view);
        var c = h('<button type="button" class="btn">Carnet de Colette</button>'); c.addEventListener('click', showCarnet); nav.appendChild(c);
        var fresh = h('<button type="button" class="btn fantome">Nouvelle partie</button>'); fresh.addEventListener('click', function () { if (!fresh.dataset.armed) { fresh.dataset.armed = '1'; fresh.textContent = 'Confirmer : tout recommencer'; return; } api('newGame', {}).then(function () { location.reload(); }); }); nav.appendChild(fresh);
        var r = h('<button type="button" class="btn plein">Retour à la régie</button>'); r.addEventListener('click', function () { G.review = true; G.actView = 5; showRegie(); }); nav.appendChild(r);
        app.innerHTML = ''; app.appendChild(view);
      };
      if (!G.data.epilogue) { G.data.epilogue = true; save(true); playScene(STORY.epilogue, { tag: 'ÉPILOGUE' }).then(show); } else show();
    });
  }

  // ——— Start ———
  window.SGSdk.onReward(function (rewards) { G.rewards = G.rewards.concat(rewards); });
  Promise.all([api('getProgress'), api('getScenario')]).then(function (r) {
    G.data = r[0].data || {};
    // An empty map comes back from the server as [] (PHP knows no empty object): a key set on that
    // array would never reach JSON, which is how drafts and the scenes seen stopped being saved.
    ['seen', 'drafts'].forEach(function (k) { G.data[k] = G.data[k] && !Array.isArray(G.data[k]) ? G.data[k] : {}; });
    if (G.data.mute) SND.setMuted(true);
    G.sc = r[1]; G.actView = G.sc.act;
    var go = /open=(E\d+)/.exec(location.hash); // a shortcut for the tests and the screenshots of the prototype
    if (go) { G.data.prologue = true; G.data.seen[go[1] + ':i'] = 1; G.data.seen[go[1] + ':d'] = 1; refreshScenario().then(function () { return api('markActSeen', { act: G.sc.act }); }).then(function () { openTicket(go[1]); }); return; }
    if (/regie/.test(location.hash)) { G.data.prologue = true; refreshScenario().then(function () { return api('markActSeen', { act: G.sc.act }); }).then(function () { showRegie(); }); return; }
    showTitle();
  }).catch(function (e) { app.textContent = 'Erreur de chargement : ' + e.message; });
})();
