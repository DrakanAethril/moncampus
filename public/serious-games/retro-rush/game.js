/* Rétro Rush — the game's screens: title, scenes, act announcements, the forge (issues), the SQL
   workshop (the ticket, the editor, the results, the schema), the review of the merge request, the
   chat with the requester, the stock file (the investigation), the end. The platform is reached
   through the SDK only (sdk.js); the database through the engine (engine.js), sql.js in the frame. */
(function () {
  'use strict';
  var app = document.getElementById('app');
  var E = window.RetroEngine, CH = window.RetroChars, SND = window.RetroSound, STORY = window.RetroStory, TK = window.RetroTickets;
  var api = function (m, p) { return window.SGSdk.call(m, p); };

  var G = { user: null, rewards: [], data: { seen: {}, drafts: {} }, sc: null, session: null, ticket: null, def: null, step: 0, guide: [], guideStep: 0, hints: 0, errors: 0, results: null, tab: null, texts: {}, box: null, report: null, refused: {}, order: null };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function h(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }
  function $(sel, root) { return (root || app).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  // The frame is sandboxed without allow-modals: window.alert() would be swallowed silently.
  function notice(text) {
    var w = h('<div class="voile"><div class="fenetre" role="alertdialog" aria-modal="true"><p>' + esc(text) + '</p><div class="actions"><button type="button" class="btn btn-atomic">FERMER</button></div></div></div>');
    $('.btn', w).addEventListener('click', function () { w.remove(); });
    app.appendChild(w); $('.btn', w).focus();
  }
  var saveTimer = null;
  function save(now) {
    clearTimeout(saveTimer);
    var go = function () { api('saveProgress', { data: G.data }).catch(function () {}); };
    if (now) go(); else saveTimer = setTimeout(go, 800);
  }
  function nameOf(who) { return CH.name(who); }
  function num(id) { return '#' + String(+id.slice(1)); }
  function actOf() { return (G.ticket && G.ticket.act) || (G.sc && G.sc.act) || 1; }
  function dateOf() { var a = STORY.acts[actOf()]; return a ? a.date : ''; }
  function gradeOf(n) { var g = STORY.grades[0][1]; STORY.grades.forEach(function (x) { if (n >= x[0]) g = x[1]; }); return g; }
  function stars(err, hints) { return err === 0 && hints === 0 ? 3 : (err + hints <= 2 ? 2 : 1); }
  function jauge(n) { return '<span class="jauge" aria-label="' + n + ' étoiles sur 3">' + [1, 2, 3].map(function (i) { return '<span class="' + (i <= n ? 'on' : '') + '"></span>'; }).join('') + '</span>'; }
  var STATUS = { todo: 'Ouverte', doing: 'En cours', done: 'Fermée', reassigned: 'Fermée' };

  // ——— Scenery ———
  function traces() {
    return '<svg class="decor" aria-hidden="true" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice"><rect width="1440" height="900" fill="#EDE7F7"/>' +
      '<g fill="none" stroke="#D9CEEE" stroke-width="3"><path d="M0 120 H220 L260 160 H520"/><path d="M0 820 H160 L200 780 H600 L640 740 H760"/><path d="M120 0 V60 L160 100 V300"/><path d="M1440 860 H1200 L1160 820 H980"/></g>' +
      '<g fill="#D9CEEE"><circle cx="520" cy="160" r="7"/><circle cx="760" cy="740" r="7"/><circle cx="160" cy="300" r="7"/><circle cx="980" cy="820" r="7"/></g></svg>';
  }
  function shell(who, expr, name, role, style) {
    return '<div class="coque" style="' + (style || '') + '" aria-hidden="true"><div class="ecran" style="left:12%;right:12%;top:8%;bottom:24%">' + CH.svg(who, expr) + '</div>' +
      '<div class="plaque" style="bottom:7%">' + esc(name) + '<small>' + esc(role) + '</small></div></div>';
  }

  // ——— Scenes ———
  function playScene(lines, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var scene = h('<section class="scene" aria-label="Scène"></section>');
      scene.innerHTML = traces() + '<div class="haut"><div class="tags"><span style="background:#6A3EA0;color:#FFFFFF">' + esc(opts.tag || 'BOUTIQUE') + '</span>' +
        (opts.sub ? '<span style="background:#FFFFFF;color:#3C2F57">' + esc(opts.sub) + '</span>' : '') + '</div><button type="button" class="passer">PASSER</button></div>' +
        '<div class="perso gauche" aria-hidden="true"></div><div class="perso droite" aria-hidden="true"></div>' +
        '<div class="lower" aria-live="polite"><div class="nom"><b></b><span></span></div><div class="texte"><span class="t"></span><span class="suite"><button type="button" class="btn btn-atomic">SUIVANT ▸</button></span></div></div>';
      app.appendChild(scene);
      var speakers = [];
      lines.forEach(function (l) { if (l.who !== 'narr' && speakers.indexOf(l.who) === -1) speakers.push(l.who); });
      var slots = { gauche: speakers[0] || null, droite: speakers[1] || null };
      var i = 0;
      function show() {
        var l = lines[i], lower = $('.lower', scene);
        lower.classList.toggle('narr', l.who === 'narr');
        $('.nom', scene).style.visibility = l.who === 'narr' ? 'hidden' : '';
        if (l.who !== 'narr') {
          if (slots.gauche !== l.who && slots.droite !== l.who) { if (speakers.indexOf(l.who) % 2) slots.droite = l.who; else slots.gauche = l.who; }
          $('.nom b', scene).textContent = nameOf(l.who).toUpperCase();
          $('.nom span', scene).textContent = CH.role(l.who);
        }
        ['gauche', 'droite'].forEach(function (side) {
          var el = $('.perso.' + side, scene), who = slots[side];
          if (!who) { el.style.display = 'none'; return; }
          el.style.display = '';
          var speaking = who === l.who;
          el.innerHTML = CH.svg(who, speaking ? (l.expr || 'neutre') : (el.dataset.expr || 'neutre'));
          if (speaking) el.dataset.expr = l.expr || 'neutre';
          el.classList.toggle('eteint', !speaking);
        });
        $('.t', scene).textContent = l.text;
        SND.click();
        $('.suite .btn', scene).focus();
      }
      function next() { i++; if (i >= lines.length) { end(); return; } show(); }
      function end() { document.removeEventListener('keydown', key); scene.remove(); resolve(); }
      function key(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); next(); } if (e.key === 'Escape') end(); }
      $('.suite .btn', scene).addEventListener('click', next);
      $('.passer', scene).addEventListener('click', end);
      document.addEventListener('keydown', key);
      show();
    });
  }

  // ——— Title ———
  function showTitle() {
    app.innerHTML = '';
    var started = !!G.data.prologue;
    var done = G.sc.cards.filter(function (c) { return c.status === 'done' || c.status === 'reassigned'; }).length;
    var view = h('<section class="titre"></section>');
    view.innerHTML = traces() +
      '<div class="bloc"><div class="sur">« CONTINUE ? » · JEUX RÉTRO · DÉPÔT-VENTE</div><h1 class="chrome">RÉTRO<br>RUSH</h1><p class="sous">Le stock qui ne tombe jamais juste.</p>' +
      '<p class="pitch">Quinze ans de jeux rétro dans un tableur, une boutique en ligne qui ouvre dans un mois et des cartouches qui disparaissent. Alternant·e chez Gilles, vous regardez enfin ce qu\'il y a dedans, requête après requête, avec Jade.</p><nav aria-label="Menu"></nav></div>' +
      shell('jade', 'sourire', 'JADE', 'Tutrice · dev freelance', 'right:5%;top:8%;width:34%;height:82%') +
      '<div class="pastilles" aria-hidden="true"><span style="background:rgba(106,62,160,.62);color:#fff">Atomic</span><span style="background:rgba(224,69,123,.7)">Framboise</span><span style="background:rgba(255,138,61,.75)">Mandarine</span><span style="background:rgba(143,209,79,.8)">Citron vert</span><span style="background:rgba(191,230,255,.9)">Glacier</span></div>';
    var nav = $('nav', view);
    var first = h('<button type="button" class="btn btn-mandarine">' + (started ? 'REPRENDRE' : 'ALLUMER') + '<small>' + (started ? 'Acte ' + G.sc.act + ' · ' + esc(G.sc.acts[G.sc.act - 1].title) + ' — ' + done + ' issue' + (done > 1 ? 's' : '') + ' fermée' + (done > 1 ? 's' : '') : 'Acte 1 · Le tableur de Gilles') + '</small></button>');
    first.addEventListener('click', function () { SND.click(); startGame(); });
    nav.appendChild(first);
    if (started) {
      var again = h('<button type="button" class="btn btn-givre">REVOIR LE PROLOGUE<small>Lundi 2 novembre, 9 h</small></button>');
      again.addEventListener('click', function () { playScene(STORY.prologue, { tag: 'PROLOGUE', sub: 'LUNDI 2 NOV. · 9:00' }); });
      nav.appendChild(again);
    }
    nav.appendChild(soundButton());
    app.appendChild(view);
    first.focus();
  }
  function soundButton() {
    var b = h('<button type="button" class="btn btn-fantome" aria-pressed="' + (!SND.muted()) + '">' + (SND.muted() ? 'SON COUPÉ' : 'SON ACTIVÉ') + '</button>');
    b.addEventListener('click', function () {
      SND.setMuted(!SND.muted()); G.data.mute = SND.muted(); save();
      b.textContent = SND.muted() ? 'SON COUPÉ' : 'SON ACTIVÉ'; b.setAttribute('aria-pressed', String(!SND.muted()));
    });
    return b;
  }
  function startGame() {
    if (!G.data.prologue) playScene(STORY.prologue, { tag: 'PROLOGUE', sub: 'LUNDI 2 NOV. · 9:00' }).then(function () { G.data.prologue = true; save(true); showForge(); });
    else showForge();
  }

  // ——— Act announcement ———
  function actIntro(n) {
    return new Promise(function (resolve) {
      SND.act();
      var a = G.sc.acts[n - 1], st = STORY.acts[n] || {};
      var view = h('<section class="acte" aria-label="Acte ' + n + '"></section>');
      var list = G.sc.acts.map(function (x) {
        var cls = x.n < n ? 'fait' : (x.n === n ? 'ici' : '');
        return '<li class="' + cls + '"><b>0' + x.n + '</b><span style="flex:1;font-weight:700">' + (x.n <= n ? esc(x.title) : '· · ·') + '</span><span>' + (x.n < n ? 'FAIT' : (x.n === n ? 'EN COURS' : '')) + '</span></li>';
      }).join('');
      view.innerHTML = traces() +
        '<div class="bloc"><div class="tags"><span style="background:#6A3EA0;color:#fff">JALON SUIVANT</span><span style="background:#fff;color:#3C2F57">' + esc(st.date || '') + '</span></div>' +
        '<div class="num"><small>ACTE</small><b class="chrome">0' + n + '</b></div><h1>' + esc(a.title) + '</h1><p>' + esc(st.blurb || '') + '</p>' +
        '<div class="notions">' + (st.notions || []).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div>' +
        '<div><button type="button" class="btn btn-mandarine go">OUVRIR LE JALON ▸</button></div></div><ol class="liste" aria-label="Les actes">' + list + '</ol>';
      app.appendChild(view);
      $('.go', view).focus();
      $('.go', view).addEventListener('click', function () { api('markActSeen', { act: n }).then(function () { view.remove(); resolve(); }); });
    });
  }

  // ——— Bar ———
  function bar(current) {
    var b = h('<header class="bar"><div class="logo">RÉTRO RUSH</div><nav aria-label="Outils"></nav><div class="droite"></div></header>');
    var nav = $('nav', b);
    [['forge', 'LA FORGE', showForge], ['dossier', 'DOSSIER DU STOCK', showDossier]].forEach(function (x) {
      var bt = h('<button type="button"' + (x[0] === current ? ' aria-current="page"' : '') + '>' + x[1] + '</button>');
      bt.addEventListener('click', function () { SND.click(); save(true); x[2](); });
      nav.appendChild(bt);
    });
    var d = $('.droite', b);
    d.appendChild(h('<span class="chip chip-acte">ACTE ' + actOf() + '</span>'));
    d.appendChild(h('<span class="date">' + esc(dateOf()) + '</span>'));
    var son = h('<button type="button" class="son" aria-pressed="' + (!SND.muted()) + '">' + (SND.muted() ? 'Son coupé' : 'Son') + '</button>');
    son.addEventListener('click', function () { SND.setMuted(!SND.muted()); G.data.mute = SND.muted(); save(); son.textContent = SND.muted() ? 'Son coupé' : 'Son'; });
    d.appendChild(son);
    var me = avatar(34);
    if (me) d.appendChild(h('<span class="moi" title="' + esc(G.user.firstName || '') + '">' + me + '</span>'));
    return b;
  }
  // The student's avatar, from the platform (getUser), with the shop's cap - the game's costume.
  function avatar(size) {
    if (!G.user || !G.user.avatar || !window.SGAvatar) return '';
    return window.SGAvatar.html(G.user.avatar, size, { costume: 'retro-rush' });
  }

  // ——— The forge ———
  function refreshScenario() { return api('getScenario').then(function (sc) { G.sc = sc; return sc; }); }
  function closedCards(sc) { return sc.cards.filter(function (c) { return c.status === 'done' || c.status === 'reassigned'; }); }
  function showForge(newIds) {
    if (!Array.isArray(newIds)) newIds = [];
    G.ticket = null;
    refreshScenario().then(function (sc) {
      if (sc.finished) { showEnd(); return; }
      if (!sc.actSeen) { actIntro(sc.act).then(function () { showForge(newIds); }); return; }
      app.innerHTML = '';
      app.appendChild(bar('forge'));
      var open = sc.cards.filter(function (c) { return c.status === 'todo' || c.status === 'doing'; });
      var closed = closedCards(sc);
      var wrap = h('<div class="forge"><aside></aside><main><h1>Issues assignées à moi</h1><p class="sous-titre">boutique-continue · jalon « ' + esc(sc.acts[sc.act - 1].title) + ' »</p><div class="issues" role="table" aria-label="Issues"></div></main></div>');
      var aside = $('aside', wrap);
      aside.appendChild(h('<div style="display:flex;flex-direction:column;gap:8px"><div class="k">En cours</div><div class="jauge">' + [0, 1, 2, 3].map(function (i) { return '<span class="' + (i < open.length ? 'on' : '') + '" style="width:44px;height:10px"></span>'; }).join('') + '</div><div style="font-size:14px;color:var(--texte-2)">' + open.length + ' sur ' + sc.capacity + (open.length < sc.capacity ? '' : ' : fermes-en une pour en recevoir une autre') + '</div></div>'));
      aside.appendChild(h('<div style="display:flex;flex-direction:column;gap:6px"><div class="k">Fermées</div><div style="font-weight:800;font-size:22px">' + closed.length + ' <span style="font-weight:600;font-size:14px;color:var(--texte-3)">sur ' + sc.total + '</span></div></div>'));
      aside.appendChild(h('<div style="display:flex;flex-direction:column;gap:6px"><div class="k">Grade</div><div style="font-weight:800;font-size:17px">' + esc(gradeOf(closed.length)) + '</div></div>'));
      aside.appendChild(h('<div style="display:flex;flex-direction:column;gap:8px"><div class="k">Dossier du stock</div><div class="indices">' + [0, 1, 2, 3, 4].map(function (i) { return '<span class="' + (i < sc.clues.length ? 'on' : '') + '"></span>'; }).join('') + '</div><div style="color:var(--texte-2);font-size:14px">' + sc.clues.length + ' indice' + (sc.clues.length > 1 ? 's' : '') + ' sur 5</div></div>'));
      var table = $('.issues', wrap);
      table.appendChild(h('<div class="entete" role="row"><span>N°</span><span>TITRE</span><span>STATUT</span><span>ÉTIQUETTE</span><span>DEMANDEUR</span></div>'));
      var order = sc.cards.slice().sort(function (a, b) {
        var r = { doing: 0, todo: 1, done: 2, reassigned: 2 };
        if (r[a.status] !== r[b.status]) return r[a.status] - r[b.status];
        return r[a.status] === 2 ? (b.id > a.id ? 1 : -1) : (a.id > b.id ? 1 : -1);
      });
      order.forEach(function (c) {
        var isClosed = c.status === 'done' || c.status === 'reassigned';
        var row = h('<button type="button" role="row" class="ligne ' + (isClosed ? 'done' : c.status) + (newIds.indexOf(c.id) !== -1 ? ' nouveau' : '') + '"></button>');
        row.innerHTML = '<span class="id" role="cell">' + num(c.id) + '</span><span class="t" role="cell"><b>' + esc(c.title) + '</b><small>' + esc(c.category || '') + (c.status === 'doing' ? ' · étape ' + (c.step + 1) + ' sur ' + c.steps : '') + (isClosed && c.quality ? ' · ' + c.quality + ' étoile' + (c.quality > 1 ? 's' : '') : '') + '</small></span>' +
          '<span class="st ' + (isClosed ? 'done' : c.status) + '" role="cell"><i></i>' + STATUS[c.status] + '</span>' +
          '<span role="cell"><span class="lbl ' + (c.kind === 'demande' ? 'lbl-demande' : 'lbl-bug') + '">' + (c.kind === 'demande' ? 'demande' : 'bug') + '</span></span>' +
          '<span role="cell" style="color:var(--texte-2)">' + esc(nameOf(c.requester)) + '</span>';
        if (isClosed) row.addEventListener('click', function () { reviewCard(c); });
        else row.addEventListener('click', function () { SND.click(); openTicket(c.id); });
        table.appendChild(row);
      });
      app.appendChild(wrap);
      var doing = order.filter(function (c) { return c.status === 'doing'; })[0], todo = order.filter(function (c) { return c.status === 'todo'; })[0];
      var next = doing || todo;
      if (next) app.appendChild(h('<div class="conseil"><b>JADE</b><span>' + esc(doing ? 'Reprends la ' + num(doing.id) + ' : tu l\'as commencée.' : 'Prends la ' + num(todo.id) + ' : « ' + todo.title + ' ».') + '</span></div>'));
      var f = $('.issues button.ligne', app);
      if (f) f.focus();
    });
  }
  function reviewCard(c) {
    var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true"><h2>' + num(c.id) + ' · ' + esc(c.title) + '</h2><p>Issue fermée' + (c.quality ? ' · ' + c.quality + ' étoile' + (c.quality > 1 ? 's' : '') + ' sur 3' : '') + '.</p><div class="actions"><button type="button" class="btn btn-atomic">FERMER</button></div></div></div>');
    $('.btn', w).addEventListener('click', function () { w.remove(); });
    app.appendChild(w); $('.btn', w).focus();
  }

  // ——— The stock file ———
  function showDossier() {
    refreshScenario().then(function (sc) {
      app.innerHTML = '';
      app.appendChild(bar('dossier'));
      var v = h('<section class="dossier"><h1>Dossier du stock</h1><p class="note">Ce que les requêtes ont appris sur les jeux qui disparaissent. On note des faits, pas des soupçons.</p><div class="grille-indices"></div><div class="suspects"></div></section>');
      var grid = $('.grille-indices', v);
      for (var i = 0; i < 5; i++) {
        var c = sc.clues[i];
        grid.appendChild(h('<div class="indice' + (c ? '' : ' vide') + '"><b>INDICE ' + (i + 1) + '</b><p>' + (c ? esc(c) : '· · ·') + '</p></div>'));
      }
      var cleared = sc.clues.length >= 5;
      var sus = $('.suspects', v);
      sus.appendChild(h('<div class="suspect' + (cleared ? ' innocent' : '') + '">' + CH.svg('oceane', cleared ? 'soulagee' : 'inquiete', 'tete') + '<div>' + (cleared ? 'Océane · innocentée' : 'Océane · soupçonnée par Gilles') + '</div></div>'));
      if (cleared) sus.appendChild(h('<div class="suspect"><svg viewBox="0 0 200 170" aria-hidden="true"><rect width="200" height="170" fill="#1E1433"/><text x="100" y="78" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="15" fill="#FF8DB2">trg_annulation</text><text x="100" y="104" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="13" fill="#EDE7F7">stock - NEW.quantite</text></svg><div>Le voleur : un trigger de 2019</div></div>'));
      app.appendChild(v);
    });
  }

  // ——— The ticket ———
  function openTicket(id) {
    api('startTicket', { ticket: id }).then(function (r) {
      G.session = r.session; G.ticket = r.ticket; G.step = r.step;
      G.guide = r.guide || []; G.guideStep = r.guideStep || 0; G.hints = r.hints || 0; G.errors = 0;
      G.def = TK.get(id);
      if (!G.def) { notice('Issue introuvable dans le jeu : ' + id); return; }
      G.results = null; G.report = null; G.refused = {}; G.order = null;
      G.texts = {};
      G.def.deliver.forEach(function (d) { G.texts[d.id] = G.def.start[d.id] || ''; });
      G.texts.console = '';
      var draft = G.data.drafts[id];
      if (draft) Object.keys(draft).forEach(function (k) { if (k in G.texts) G.texts[k] = draft[k]; });
      G.tab = G.def.deliver.length ? G.def.deliver[0].id : 'console';
      resetBox();
      var intro = !G.data.seen[id] && G.def.intro && G.def.intro.length ? G.def.intro : null;
      (intro ? playScene(intro, { tag: num(id), sub: G.ticket.title.toUpperCase() }) : Promise.resolve()).then(function () {
        G.data.seen[id] = true; save();
        renderPoste();
        // Every step answered, the closing interrupted: the ticket closes now rather than stay stuck.
        if (G.step >= G.ticket.steps.length) { finishTicket(); return; }
        var st = currentStep();
        if (st && st.kind === 'explain') say('jade', st.expr || 'neutre', st.prompt);
        else if (st && st.kind === 'choice') say(G.ticket.requester, st.expr || 'neutre', st.prompt);
        else say(G.ticket.requester, 'neutre', G.ticket.request);
      });
    }).catch(function (e) { notice('Impossible d\'ouvrir l\'issue : ' + e.message); });
  }
  function currentStep() { return G.ticket.steps[G.step]; }
  function rungs() { return G.guide.length + G.def.hints.length; }
  function guideOpen() { return G.guideStep < G.guide.length && G.hints > G.guideStep ? G.guide[G.guideStep] : null; }
  function resetBox() {
    try { G.box = E.open(G.def.act, 'visible', G.def.setup); } catch (e) { G.box = null; notice('La base de l\'issue ne se charge pas : ' + e.message); }
  }

  function renderPoste() {
    app.innerHTML = '';
    app.appendChild(bar('ticket'));
    var st = currentStep();
    var poste = h('<main class="poste"></main>');
    poste.appendChild(h('<section class="panneau gauche" aria-label="Issue"></section>'));
    if (st && (st.kind === 'explain' || st.kind === 'choice' || st.kind === 'order')) poste.appendChild(h('<section class="revue" aria-label="' + (st.kind === 'explain' ? 'Revue de la merge request' : 'Messagerie') + '"></section>'));
    else {
      poste.appendChild(h('<section class="panneau centre" aria-label="Atelier SQL"></section>'));
      poste.appendChild(h('<section class="panneau schema" aria-label="Schéma de la base"></section>'));
    }
    poste.appendChild(h('<div class="dialogue" aria-live="polite"><div class="av"></div><span class="qui"></span><p class="dit"></p></div>'));
    app.appendChild(poste);
    renderLeft();
    if ($('.revue')) renderRevue(); else { renderCentre(); renderSchema(); }
  }
  function say(who, expr, text) {
    var d = $('.dialogue');
    if (!d) return;
    $('.av', d).innerHTML = CH.svg(who, expr || 'neutre', 'tete');
    $('.qui', d).textContent = nameOf(who).toUpperCase();
    $('.qui', d).style.background = who === 'jade' ? 'var(--atomic)' : 'var(--framboise)';
    $('.dit', d).textContent = text;
  }

  var STEP_LABEL = { fix: 'Écrire et livrer', explain: 'Revue', choice: 'Décider', line: 'Désigner', order: 'Ordonner' };
  function choiceButtons(options, send) {
    var box = h('<div class="choix"></div>');
    options.forEach(function (o, i) {
      var b = h('<button type="button">' + esc(o) + '</button>');
      b.addEventListener('click', function () {
        if (b.classList.contains('faux')) return;
        send(i).then(function (res) { if (!res.ok) { b.classList.add('faux'); b.setAttribute('aria-disabled', 'true'); } });
      });
      box.appendChild(b);
    });
    return box;
  }

  function renderLeft() {
    var p = $('.gauche'), t = G.ticket, st = currentStep();
    p.innerHTML = '';
    var back = h('<button type="button" class="btn btn-fantome" style="padding:0;min-height:30px">← LA FORGE</button>');
    back.addEventListener('click', function () { save(true); showForge(); });
    var head = h('<div class="tete-ticket"><span class="k">' + num(t.id) + ' · ' + (t.kind === 'demande' ? 'DEMANDE' : 'BUG') + '</span></div>');
    head.appendChild(back);
    p.appendChild(head);
    p.appendChild(h('<h1>' + esc(t.title) + '</h1>'));
    p.appendChild(h('<div class="chips"><span class="chip" style="background:#FFF1E6;color:#A8521A">EN COURS</span><span class="lbl ' + (t.kind === 'demande' ? 'lbl-demande' : 'lbl-bug') + '">' + (t.kind === 'demande' ? 'demande' : 'bug') + '</span>' + (t.category ? '<span class="chip" style="background:var(--panneau-2);color:var(--texte-3)">' + esc(t.category) + '</span>' : '') + '</div>'));
    p.appendChild(h('<div class="demande"><span class="av">' + CH.svg(t.requester, 'neutre', 'tete') + '</span><div><div class="de"><b>' + esc(nameOf(t.requester)) + '</b> · ' + esc(CH.role(t.requester)) + '</div><p>' + esc(t.request) + '</p></div></div>'));
    var ol = h('<ol class="etapes" aria-label="Étapes"></ol>');
    t.steps.forEach(function (s, i) { ol.appendChild(h('<li class="' + (i < G.step ? 'fait' : (i === G.step ? 'actif' : '')) + '"' + (i === G.step ? ' aria-current="step"' : '') + '>' + (i < G.step ? '✓ ' : '') + esc(s.label || STEP_LABEL[s.kind]) + '</li>')); });
    p.appendChild(ol);
    if (!st) return;
    if (st.kind === 'fix') {
      p.appendChild(h('<p class="consigne">' + esc(st.prompt) + '</p>'));
      p.appendChild(h('<p class="note">Écris ' + (G.def.deliver.length > 1 ? 'chaque livrable dans son onglet' : 'ta requête au centre') + ', exécute-la sur le bac à sable autant que tu veux, puis livre : le pipeline la rejoue sur une base neuve et sur le stock complet.</p>'));
      p.appendChild(criteriaEl());
    } else if (st.kind === 'line') {
      p.appendChild(h('<p class="consigne">' + esc(st.prompt) + '</p>'));
      p.appendChild(h('<p class="note">Clique directement sur la ligne, au centre.</p>'));
    } else {
      p.appendChild(h('<p class="consigne">' + esc(st.kind === 'explain' ? 'La livraison est acceptée. Jade relit ta merge request : réponds à son commentaire, à droite.' : (st.kind === 'order' ? 'Remets les éléments dans l\'ordre, à droite.' : 'Réponds dans la messagerie, à droite.')) + '</p>'));
    }
    var foot = h('<div class="pied-gauche"></div>');
    foot.appendChild(h('<div class="ligne-jauge"><span>Étoiles en jeu</span>' + jauge(stars(G.errors, G.hints)) + '</div>'));
    if (st.kind === 'fix') {
      var deliverBtn = h('<button type="button" class="btn btn-mandarine" style="align-items:center">LANCER LE PIPELINE ET LIVRER</button>');
      deliverBtn.addEventListener('click', deliver);
      foot.appendChild(deliverBtn);
    }
    if ((st.kind === 'fix' || st.kind === 'line') && rungs()) foot.appendChild(helpZone());
    p.appendChild(foot);
  }

  function criteriaEl() {
    var box = h('<div class="criteres"><div class="k">Le pipeline · ce que ' + esc(nameOf(G.ticket.requester)) + ' vérifiera' + (G.results ? ' · dernière livraison' : '') + '</div></div>');
    G.def.deliver.forEach(function (d) {
      if (G.def.deliver.length > 1) box.appendChild(h('<div class="groupe">' + esc(d.label) + '</div>'));
      E.labels(d).forEach(function (label) {
        var r = G.results && G.results.filter(function (x) { return x.deliver === d.id && x.label === label; })[0];
        box.appendChild(h('<div class="c ' + (r ? (r.ok ? 'ok' : 'ko') : '') + '"><i aria-hidden="true">' + (r ? (r.ok ? '✓' : '✗') : '·') + '</i><span>' + esc(label) + (r && !r.ok && r.detail ? '<span class="det">' + esc(r.detail) + '</span>' : '') + '</span></div>'));
      });
    });
    return box;
  }

  function helpZone() {
    var z = h('<div class="aide"></div>'), guide = guideOpen(), total = rungs();
    if (guide) {
      z.appendChild(h('<div class="de">JADE · COMMENTAIRE DE REVUE</div>'));
      z.appendChild(h('<p>' + esc(guide.prompt) + '</p>'));
      if (guide.kind === 'choice') z.appendChild(choiceButtons(guide.options, function (i) { return submitGuide(guide, i); }));
      else z.appendChild(h('<p class="ref">Clique la ligne directement, au centre.</p>'));
      return z;
    }
    var k = G.hints - G.guide.length - 1;
    if (k >= 0) {
      var art = G.def.hints[Math.min(k, G.def.hints.length - 1)];
      z.appendChild(h('<div class="de">WIKI DU PROJET · ' + esc(art.title.toUpperCase()) + '</div>'));
      z.appendChild(h('<p style="white-space:pre-line">' + esc(art.body) + '</p>'));
      if (art.ref) z.appendChild(h('<p class="ref">Référentiel : ' + esc(art.ref.label) + '</p>'));
    }
    if (G.hints < total) {
      var b = h('<button type="button" class="btn btn-plat">' + (G.hints === 0 ? 'DEMANDER À JADE' : 'ENCORE UN COUP DE MAIN') + ' (' + (G.hints + 1) + '/' + total + ')</button>');
      b.addEventListener('click', function () {
        b.disabled = true;
        api('useHint', { session: G.session }).then(function (r) {
          G.hints = r.hints;
          var g = guideOpen();
          if (g) say('jade', 'neutre', g.prompt);
          else { var kk = G.hints - G.guide.length - 1; say('jade', kk === G.def.hints.length - 1 ? 'sourire' : 'neutre', 'Lis la page du wiki « ' + G.def.hints[kk].title + ' ».'); }
          renderLeft(); if ($('.centre')) renderCentre();
        });
      });
      z.appendChild(b);
    }
    return z;
  }

  function submit(st, input) {
    return api('submitStep', { session: G.session, step: st.id, input: input }).then(function (res) {
      if (res.ok) {
        SND.right();
        G.step = res.next;
        if (res.done) { if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text); var said = res.reaction ? res.reaction.text : ''; wait(said ? Math.min(5000, 1200 + said.length * 30) : 400).then(finishTicket); return res; }
        var nst = currentStep();
        renderPoste();
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
        else if (nst && nst.kind === 'explain') say('jade', nst.expr || 'neutre', nst.prompt);
        else if (nst && nst.kind === 'choice') say(G.ticket.requester, nst.expr || 'neutre', nst.prompt);
      } else {
        SND.wrong();
        G.errors++;
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
        renderLeft();
      }
      return res;
    }).catch(function (e) { say('jade', 'sourcil', 'Erreur de la plateforme : ' + e.message); return { ok: false }; });
  }
  function submitGuide(st, input) {
    return api('submitGuide', { session: G.session, step: st.id, input: input }).then(function (res) {
      if (res.ok) {
        SND.right();
        say(res.reaction ? res.reaction.who : 'jade', res.reaction ? res.reaction.expr : 'neutre', res.reaction ? res.reaction.text : ['Exact.', 'C\'est ça.', 'Bien vu. À toi, maintenant.'][Math.floor(Math.random() * 3)]);
        G.guideStep = res.next;
        renderLeft(); if ($('.centre')) renderCentre();
      } else {
        SND.wrong();
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
      }
      return res;
    }).catch(function (e) { say('jade', 'sourcil', 'Erreur de la plateforme : ' + e.message); return { ok: false }; });
  }

  // ——— The SQL workshop: editor and results ———
  var KW = /\b(SELECT|FROM|WHERE|AND|OR|NOT|IN|IS|NULL|AS|ON|JOIN|LEFT|RIGHT|INNER|OUTER|GROUP|BY|HAVING|ORDER|ASC|DESC|LIMIT|OFFSET|UNION|ALL|DISTINCT|CASE|WHEN|THEN|ELSE|END|EXISTS|BETWEEN|LIKE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|CREATE|TABLE|VIEW|INDEX|TRIGGER|BEFORE|AFTER|FOR|EACH|ROW|BEGIN|IF|ELSEIF|DECLARE|DEFAULT|SIGNAL|SQLSTATE|MESSAGE_TEXT|PRIMARY|KEY|FOREIGN|REFERENCES|CONSTRAINT|CHECK|UNIQUE|DROP|ALTER|ADD|START|TRANSACTION|COMMIT|ROLLBACK|DELIMITER|NEW|OLD|INT|INTEGER|VARCHAR|DECIMAL|DATE|TEXT|AUTO_INCREMENT|REPLACE|GRANT|REVOKE|TO|EXPLAIN|WITH)\b/gi;
  var FN = /\b(COUNT|SUM|AVG|MIN|MAX|ROUND|YEAR|MONTH|DAY|DATEDIFF|NOW|CURDATE|CONCAT|IFNULL|COALESCE|UPPER|LOWER|LENGTH|SUBSTRING|GROUP_CONCAT)(?=\s*\()/gi;
  function highlight(src) {
    return src.split('\n').map(function (line) {
      var parts = [], rest = line, m;
      var cut = /^(.*?)(--.*|#.*)$/.exec(line);
      var comment = '';
      if (cut && (cut[1].split("'").length % 2 === 1)) { rest = cut[1]; comment = cut[2]; }
      var re = /('(?:[^']|'')*'?)/g, last = 0;
      while ((m = re.exec(rest))) { parts.push({ code: rest.slice(last, m.index) }); parts.push({ str: m[1] }); last = m.index + m[1].length; }
      parts.push({ code: rest.slice(last) });
      var out = parts.map(function (p) {
        if (p.str !== undefined) return '<span class="hl-s">' + esc(p.str) + '</span>';
        return esc(p.code).replace(FN, '<span class="hl-f">$1</span>').replace(KW, '<span class="hl-k">$1</span>').replace(/(^|[^\w.])(\d+(?:\.\d+)?)(?![\w])/g, '$1<span class="hl-n">$2</span>');
      }).join('');
      return out + (comment ? '<span class="hl-c">' + esc(comment) + '</span>' : '');
    }).join('\n') + '\n';
  }
  function tabsList() {
    var list = G.def.deliver.map(function (d) { return { id: d.id, label: d.label }; });
    list.push({ id: 'console', label: 'Console libre' });
    return list;
  }
  function renderCentre() {
    var p = $('.centre'), st = currentStep();
    if (!p) return;
    p.innerHTML = '';
    var guide = guideOpen();
    var viewStep = st && st.kind === 'line' ? st : (guide && guide.kind === 'line' ? guide : null);
    if (viewStep) {
      var view = G.def.views[viewStep.view] || { title: '?', lines: [] };
      p.appendChild(h('<div class="onglets"><button type="button" aria-selected="true">' + esc(view.title) + '</button><span class="etat">clique sur une ligne</span></div>'));
      p.appendChild(h('<div class="consigne-ligne">' + esc(viewStep.prompt) + '</div>'));
      var lv = h('<div class="lignes" role="list"></div>');
      view.lines.forEach(function (l, i) {
        var b = h('<button type="button" role="listitem"><span class="n">' + (i + 1) + '</span><span>' + (esc(l) || ' ') + '</span></button>');
        b.addEventListener('click', function () {
          (viewStep === st ? submit(st, l) : submitGuide(viewStep, l)).then(function (res) { b.classList.add(res.ok ? 'juste' : 'faux'); });
        });
        lv.appendChild(b);
      });
      p.appendChild(lv);
      return;
    }
    var tabs = h('<div class="onglets" role="tablist" aria-label="Livrables"></div>');
    tabsList().forEach(function (t) {
      var b = h('<button type="button" role="tab" aria-selected="' + (t.id === G.tab) + '">' + esc(t.label) + (t.id === 'console' ? '' : ' <span style="color:var(--mandarine)">✎</span>') + '</button>');
      b.addEventListener('click', function () { G.tab = t.id; renderCentre(); });
      tabs.appendChild(b);
    });
    tabs.appendChild(h('<span class="etat">' + (G.tab === 'console' ? 'essais libres, sur le bac à sable' : 'livrable') + '</span>'));
    p.appendChild(tabs);
    var tools = h('<div class="outils-sql"><button type="button" class="run">▶ EXÉCUTER</button><button type="button" class="plan">PLAN D\'EXÉCUTION</button><button type="button" class="raz">BASE NEUVE</button>' + (G.tab !== 'console' && G.def.start[G.tab] ? '<button type="button" class="depart">TEXTE DE DÉPART</button>' : '') + '<span class="aide-clavier">Ctrl + Entrée</span></div>');
    p.appendChild(tools);
    var src = G.texts[G.tab] || '';
    var zone = h('<div class="code"></div>');
    var numEl = h('<div class="numeros" aria-hidden="true"></div>');
    var count = function (s) { var n = s.split('\n').length, o = ''; for (var i = 1; i <= n; i++) o += i + '\n'; return o; };
    numEl.textContent = count(src);
    var pre = h('<pre aria-hidden="true"></pre>'); pre.innerHTML = highlight(src);
    var ta = h('<textarea spellcheck="false" autocapitalize="off" autocomplete="off" aria-label="Éditeur SQL : ' + esc(G.tab) + '" placeholder="' + (G.tab === 'console' ? 'SELECT * FROM …' : 'Écris ta requête ici') + '"></textarea>');
    ta.value = src;
    zone.appendChild(numEl); zone.appendChild(pre); zone.appendChild(ta);
    var tab = G.tab;
    ta.addEventListener('input', function () {
      G.texts[tab] = ta.value;
      pre.innerHTML = highlight(ta.value);
      numEl.textContent = count(ta.value);
      G.data.drafts[G.ticket.id] = Object.assign({}, G.data.drafts[G.ticket.id] || {}, (function () { var o = {}; o[tab] = ta.value; return o; })()); save();
      if (G.results) { G.results = null; renderLeft(); }
    });
    ta.addEventListener('scroll', function () { pre.scrollTop = ta.scrollTop; pre.scrollLeft = ta.scrollLeft; numEl.scrollTop = ta.scrollTop; });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); run(); return; }
      if (e.key === 'Tab' && !e.shiftKey) {
        e.preventDefault();
        var s0 = ta.selectionStart, s1 = ta.selectionEnd;
        ta.value = ta.value.slice(0, s0) + '  ' + ta.value.slice(s1);
        ta.selectionStart = ta.selectionEnd = s0 + 2;
        ta.dispatchEvent(new Event('input'));
      }
    });
    p.appendChild(zone);
    var res = h('<div class="resultats" aria-live="polite"></div>');
    p.appendChild(res);
    paintReport();
    $('.run', tools).addEventListener('click', run);
    $('.plan', tools).addEventListener('click', function () {
      try { var lines = G.box.plan(lastStatement(G.texts[G.tab] || '')); showMessage('<div class="msg info">Plan d\'exécution (EXPLAIN QUERY PLAN) :</div>' + lines.map(function (l) { return '<div class="msg" style="font-family:var(--mono)">' + esc(l) + '</div>'; }).join('')); }
      catch (e) { showMessage('<div class="msg err">' + esc(e.refused ? e.message : window.RetroSQL.frenchError(e.message)) + '</div>'); }
    });
    $('.raz', tools).addEventListener('click', function () { resetBox(); G.report = null; showMessage('<div class="msg info">Bac à sable remis à neuf : la base telle que l\'issue l\'a trouvée.</div>'); renderSchema(); });
    var dep = $('.depart', tools);
    if (dep) dep.addEventListener('click', function () { G.texts[tab] = G.def.start[tab]; renderCentre(); });
    ta.focus();
  }
  function lastStatement(text) { var s = window.RetroSQL.split(text); return s.length ? s[s.length - 1].sql : ''; }
  // A deliverable runs on a fresh base each time, like the pipeline; the free console keeps its sandbox.
  function run() {
    var text = G.texts[G.tab] || '';
    if (!text.trim()) return;
    SND.run();
    if (G.tab !== 'console') resetBox();
    if (!G.box) return;
    G.report = G.box.run(text);
    paintReport();
    renderSchema();
  }
  function showMessage(html) { var r = $('.resultats'); if (r) r.innerHTML = html; }
  function paintReport() {
    var r = $('.resultats');
    if (!r) return;
    var rep = G.report;
    if (!rep) { r.innerHTML = '<div class="msg info">Les résultats s\'affichent ici. Clique une table du schéma pour voir son contenu.</div>'; return; }
    var html = '';
    var sel = null, changes = 0, ddl = 0;
    rep.steps.forEach(function (s) { if (s.kind === 'select') sel = s; if (s.kind === 'modify') changes += s.changes; if (s.kind === 'ddl' || s.kind === 'trigger') ddl++; });
    if (rep.error) html += '<div class="msg err">✗ Ligne ' + rep.error.line + ' : ' + esc(rep.error.message) + '</div>';
    if (rep.rolledBack) html += '<div class="msg err">Transaction annulée (ROLLBACK) : ' + (rep.error ? 'une erreur l\'a interrompue' : 'elle n\'a pas été validée par COMMIT') + '. Rien n\'a été enregistré.</div>';
    if (!rep.error && !sel) html += '<div class="msg ok">✓ ' + rep.steps.length + ' instruction' + (rep.steps.length > 1 ? 's' : '') + ' exécutée' + (rep.steps.length > 1 ? 's' : '') + (changes ? ' · ' + changes + ' ligne' + (changes > 1 ? 's' : '') + ' modifiée' + (changes > 1 ? 's' : '') : '') + (ddl ? ' · schéma modifié' : '') + '.</div>';
    if (sel) html += grid(sel.columns, sel.rows, (rep.steps.length > 1 ? 'Résultat du dernier SELECT' : 'Résultat'));
    r.innerHTML = html;
  }
  function grid(columns, rows, caption) {
    var shown = rows.slice(0, 200);
    return '<div class="msg info">' + esc(caption) + ' · ' + rows.length + ' ligne' + (rows.length > 1 ? 's' : '') + (rows.length > 200 ? ' (200 premières affichées)' : '') + '</div>' +
      '<table class="grille"><thead><tr>' + columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>' +
      shown.map(function (row) { return '<tr>' + row.map(function (v) { return v === null ? '<td class="null">NULL</td>' : '<td>' + esc(typeof v === 'number' ? E.show([v]) : v) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>';
  }

  // ——— Schema panel ———
  function renderSchema() {
    var p = $('.schema');
    if (!p || !G.box) return;
    p.innerHTML = '';
    p.appendChild(h('<h2>SCHÉMA RELATIONNEL</h2>'));
    var rel = h('<div class="rel"></div>');
    G.box.tables().forEach(function (t) {
      var cols = G.box.columns(t);
      var txt = '<b>' + esc(t) + '</b> (' + cols.map(function (c) { var n = (c.fk ? '#' : '') + esc(c.name); return c.pk ? '<span class="pk">' + n + '</span>' : n; }).join(', ') + ')';
      var b = h('<button type="button">' + txt + '<span class="n">' + G.box.count(t) + ' l.</span></button>');
      b.addEventListener('click', function () { var q = G.box.query('SELECT * FROM "' + t + '" LIMIT 200'); showMessage(grid(q.columns, q.rows, 'Contenu de ' + t + ' (bac à sable)')); });
      rel.appendChild(b);
    });
    p.appendChild(rel);
    p.appendChild(h('<p class="note" style="font-size:12px">Clé primaire soulignée, clé étrangère précédée de #, comme à l\'examen.</p>'));
    var views = G.box.views();
    if (views.length) {
      p.appendChild(h('<h2>VUES</h2>'));
      var vl = h('<div class="rel"></div>');
      views.forEach(function (v) {
        var b = h('<button type="button"><b>' + esc(v.name) + '</b></button>');
        b.addEventListener('click', function () { var q = G.box.query('SELECT * FROM "' + v.name + '" LIMIT 200'); showMessage('<div class="msg" style="font-family:var(--mono);white-space:pre-wrap">' + esc(v.sql) + '</div>' + grid(q.columns, q.rows, 'Contenu de la vue ' + v.name)); });
        vl.appendChild(b);
      });
      p.appendChild(vl);
    }
    if (actOf() >= 7 || G.def.showTriggers) {
      var trs = G.box.triggers();
      if (trs.length) {
        p.appendChild(h('<h2>TRIGGERS</h2>'));
        var tl = h('<div class="rel"></div>');
        trs.forEach(function (t) {
          var b = h('<button type="button"><b>' + esc(t.name) + '</b> · ' + esc(t.table) + '</button>');
          b.addEventListener('click', function () { showMessage('<div class="msg info">Source du trigger ' + esc(t.name) + ' :</div><pre class="msg" style="font-family:var(--mono);margin:0">' + esc(t.source) + '</pre>'); });
          tl.appendChild(b);
        });
        p.appendChild(tl);
      }
    }
  }

  // ——— Review, chat, order ———
  function renderRevue() {
    var p = $('.revue'), st = currentStep(), t = G.ticket;
    p.innerHTML = '';
    var left = h('<section class="panneau mr"></section>');
    if (st.kind === 'explain') {
      var main = G.def.deliver[0];
      var text = main ? (G.texts[main.id] || '') : '';
      left.appendChild(h('<div class="entete"><b>!' + (+t.id.slice(1)) + ' · ' + esc(t.title) + '</b><span>Merge request · pipeline ✓ · livrée à ' + esc(nameOf(t.requester)) + ' · en attente de la revue de Jade</span></div>'));
      if (text) left.appendChild(h('<div class="diff">' + text.split('\n').map(function (l) { return '<div class="plus">+ ' + esc(l) + '</div>'; }).join('') + '</div>'));
      left.appendChild(h('<div class="commentaire"><span class="av">' + CH.svg('jade', st.expr || 'neutre', 'tete') + '</span><div class="bulle"><b>Jade · commentaire de revue</b>' + esc(st.prompt) + '</div></div>'));
      left.appendChild(optionsEl(st, 'Ta réponse', 'MODIFICATIONS DEMANDÉES'));
      p.appendChild(left);
      p.appendChild(portrait('jade', st.expr || 'neutre'));
      return;
    }
    if (st.kind === 'order') {
      left.appendChild(h('<div class="entete"><b>' + esc(st.label || 'Remettre dans l\'ordre') + '</b><span>' + esc(st.prompt) + '</span></div>'));
      var box = h('<div style="padding:14px 18px"></div>'); box.appendChild(orderEl(st)); left.appendChild(box);
      p.appendChild(left);
      p.appendChild(portrait(t.requester, st.expr || 'neutre'));
      return;
    }
    var chat = h('<section class="panneau chat"></section>');
    chat.appendChild(h('<div class="k">Messagerie · ' + esc(nameOf(t.requester)) + '</div>'));
    chat.appendChild(h('<div class="msg-in"><span class="av">' + CH.svg(t.requester, st.expr || 'neutre', 'tete') + '</span><div class="bulle"><b>' + esc(nameOf(t.requester)) + '</b>' + esc(st.prompt) + '</div></div>'));
    chat.appendChild(optionsEl(st, 'Ta réponse', 'NON'));
    p.appendChild(chat);
    p.appendChild(portrait(t.requester, st.expr || 'neutre'));
  }
  function optionsEl(st, title, refusedLabel) {
    var box = h('<div class="reponses" role="group"><div class="k">' + esc(title) + '</div></div>');
    st.options.forEach(function (o, i) {
      var key = st.id + ':' + i, refused = G.refused[key];
      var b = h('<button type="button" class="' + (refused ? 'refusee' : '') + '"><b>' + 'ABCDEFG'[i] + '</b><span>' + esc(o) + '</span>' + (refused ? '<span class="tampon-r">' + refusedLabel + '</span>' : '') + '</button>');
      b.addEventListener('click', function () {
        if (G.refused[key]) return;
        submit(st, i).then(function (res) { if (!res.ok) { G.refused[key] = true; if (currentStep() === st) renderRevue(); } });
      });
      box.appendChild(b);
    });
    return box;
  }
  function portrait(who, expr) {
    return h('<div class="portrait" aria-hidden="true">' + CH.svg(who, expr) + '<div class="plaque"><b>' + esc(nameOf(who).toUpperCase()) + '</b><small>' + esc(CH.role(who)) + '</small></div></div>');
  }
  function orderEl(st) {
    G.order = G.order && G.order.length === st.items.length ? G.order : st.items.map(function (x, i) { return i; });
    var box = h('<div class="ordre"></div>');
    G.order.forEach(function (idx, pos) {
      var it = h('<div class="it"><b>' + (pos + 1) + '</b><span>' + esc(st.items[idx]) + '</span><span><button type="button" aria-label="Monter">▲</button> <button type="button" aria-label="Descendre">▼</button></span></div>');
      var bs = $$('button', it);
      bs[0].addEventListener('click', function () { if (pos > 0) { var x = G.order[pos - 1]; G.order[pos - 1] = idx; G.order[pos] = x; renderRevue(); } });
      bs[1].addEventListener('click', function () { if (pos < G.order.length - 1) { var x = G.order[pos + 1]; G.order[pos + 1] = idx; G.order[pos] = x; renderRevue(); } });
      box.appendChild(it);
    });
    var go = h('<button type="button" class="btn btn-mandarine" style="align-self:flex-start;margin-top:8px">VALIDER L\'ORDRE</button>');
    go.addEventListener('click', function () { submit(st, G.order.slice()); });
    box.appendChild(go);
    return box;
  }

  // ——— Delivery and end of a ticket ———
  function deliver() {
    var st = currentStep();
    try { G.results = E.check(G.def, G.texts); } catch (e) { say('jade', 'sourcil', 'Le pipeline a planté : ' + e.message); return; }
    var failed = G.results.filter(function (r) { return !r.ok; }).map(function (r) { return r.label; });
    SND.deliver();
    renderLeft();
    submit(st, { passed: failed.length === 0, failed: failed }).then(function (res) {
      if (!res.ok) {
        var first = G.results.filter(function (r) { return !r.ok; })[0];
        say(G.ticket.requester, 'perplexe', 'Ça ne va pas encore : ' + first.label.charAt(0).toLowerCase() + first.label.slice(1) + '.' + (first.detail ? ' (' + first.detail + ')' : ''));
        if (G.errors === 2 && !G.hints && rungs()) wait(4500).then(function () { say('jade', 'neutre', 'Si tu tournes en rond, demande-moi : je te poserai les bonnes questions.'); });
      }
    });
  }
  function finishTicket() {
    api('endTicket', { session: G.session, hints: G.hints }).then(function (res) {
      delete G.data.drafts[G.ticket.id]; save(true);
      SND.stamp();
      var stamp = h('<div class="tampon"><span>FERMÉE ✓</span></div>');
      app.appendChild(stamp);
      return wait(1300).then(function () {
        stamp.remove();
        return G.def.done && G.def.done.length ? playScene(G.def.done, { tag: num(G.ticket.id), sub: 'FERMÉE · ' + res.quality + ' ÉTOILE' + (res.quality > 1 ? 'S' : '') }) : null;
      }).then(function () { if (res.clue) return clueWindow(res); }).then(rewardsScene).then(function () {
        if (res.finished) { showEnd(); return; }
        showForge((res.newCards || []).map(function (c) { return c.id; }));
      });
    }).catch(function (e) { say('jade', 'sourcil', 'Erreur de la plateforme : ' + e.message); });
  }
  function clueWindow(res) {
    return new Promise(function (resolve) {
      SND.clue();
      var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true"><div class="k" style="color:var(--framboise-2)">Dossier du stock · indice ' + res.clueIndex + ' sur 5</div><h2>Un fait de plus</h2><p>' + esc(res.clue) + '</p><div class="actions"><button type="button" class="btn btn-atomic">NOTÉ DANS LE DOSSIER</button></div></div></div>');
      $('.btn', w).addEventListener('click', function () { w.remove(); resolve(); });
      app.appendChild(w); $('.btn', w).focus();
    });
  }
  // The collection pieces the platform granted at the closing of a ticket (SGSdk.onReward).
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
      var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true" aria-labelledby="recompensesTitre"><div class="k" style="color:var(--citron-2)">Collection</div><h2 id="recompensesTitre">' + (list.length > 1 ? 'Nouvelles pièces de collection' : 'Nouvelle pièce de collection') + '</h2><ul class="pieces">' + items + '</ul><p>Elles rejoignent ta vitrine, sur ta page joueur.</p><div class="actions"><button type="button" class="btn btn-atomic">SUPER !</button></div></div></div>');
      $('.btn', w).addEventListener('click', function () { w.remove(); resolve(); });
      app.appendChild(w); $('.btn', w).focus();
    });
  }
  function showEnd(skipEpilogue) {
    var first = !G.data.epilogue && !skipEpilogue;
    (first ? playScene(STORY.epilogue, { tag: 'ÉPILOGUE', sub: 'MARDI 1ER DÉC. · 9:00' }).then(function () { G.data.epilogue = true; save(true); }) : Promise.resolve()).then(refreshScenario).then(function (sc) {
      app.innerHTML = '';
      var v = h('<section class="fin"></section>');
      var done = closedCards(sc);
      var st = done.reduce(function (s, c) { return s + (c.quality || 0); }, 0);
      var three = done.filter(function (c) { return c.quality === 3; }).length;
      var finish = three >= 40 ? 'or' : (three >= 30 ? 'argent' : 'laiton');
      v.innerHTML = traces() +
        '<div class="bloc"><div class="sur">ATTESTATION D\'ALTERNANCE · « CONTINUE ? »</div><h1 class="chrome">BOUTIQUE<br>OUVERTE</h1>' +
        '<p class="pitch">' + done.length + ' issues fermées, ' + st + ' étoiles sur ' + (done.length * 3) + ', dont ' + three + ' issues sans erreur ni aide. Grade : <b>' + esc(gradeOf(done.length)) + '</b>.</p>' +
        '<p class="pitch">Trophée : <b>la Cartouche d\'or, finition ' + finish + '</b>, et l\'épinglette Joystick, dans ta vitrine.</p>' +
        '<p class="pitch" style="font-size:14px">Signé : Jade, développeuse · Gilles, fondateur de « Continue ? » · Océane, vendeuse.</p><nav aria-label="Fin"></nav></div>' +
        shell('jade', 'sourire', 'JADE', 'Tutrice · dev freelance', 'right:5%;top:10%;width:32%;height:78%');
      var nav = $('nav', v);
      var again = h('<button type="button" class="btn btn-mandarine">REVOIR L\'ÉPILOGUE<small>Mardi 1er décembre, 9 h</small></button>');
      again.addEventListener('click', function () { playScene(STORY.epilogue, { tag: 'ÉPILOGUE', sub: 'MARDI 1ER DÉC. · 9:00' }); });
      var dos = h('<button type="button" class="btn btn-givre">LE DOSSIER DU STOCK<small>Les cinq indices</small></button>');
      dos.addEventListener('click', showDossier);
      var fresh = h('<button type="button" class="btn btn-fantome">NOUVELLE PARTIE<small>Les issues repartent de zéro ; ta collection et ton grade restent</small></button>');
      fresh.addEventListener('click', function () {
        fresh.disabled = true;
        api('newGame').then(function () {
          G.data = { seen: {}, drafts: {}, mute: SND.muted() }; save(true);
          return refreshScenario();
        }).then(showTitle).catch(function (e) { fresh.disabled = false; notice('Erreur de la plateforme : ' + e.message); });
      });
      nav.appendChild(again); nav.appendChild(dos); nav.appendChild(fresh);
      app.appendChild(v);
    });
  }

  // ——— Start ———
  window.SGSdk.onReward(function (rewards) { G.rewards = G.rewards.concat(rewards); });
  Promise.all([E.ready(), api('getProgress'), api('getScenario'), api('getUser').catch(function () { return null; })]).then(function (r) {
    G.user = r[3];
    G.data = Object.assign(G.data, r[1].data || {});
    ['seen', 'drafts'].forEach(function (k) { G.data[k] = G.data[k] || {}; });
    SND.setMuted(!!G.data.mute);
    G.sc = r[2];
    showTitle();
  }).catch(function (e) {
    app.innerHTML = '<p style="padding:40px">Le jeu n\'arrive pas à démarrer : ' + esc(e.message) + '</p>';
  });
})();
