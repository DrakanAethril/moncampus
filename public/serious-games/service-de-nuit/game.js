/* Service de nuit — the game's screens: the title and its sign, the scenes, the act announcements, the
   street (the home: the sign whose letters are the notions, and one window per night), the loge (the
   request, the site's editor, the visitors, the cut between what the server sends and what the browser
   shows), the questions, Sami's help, Augustin's notebooks, the morning report and the end.

   The platform is reached through the SDK only (sdk.js). The student's site is one, from the first night
   to the last: it lives in the game's display state (saveProgress), with the sign's notions. Trying the
   site on the visible visitors is free and stays in the game; serving the night - every visitor, hidden
   ones included - is the delivery the platform counts, and only {passed, failed} leaves the frame. */
(function () {
  'use strict';
  var app = document.getElementById('app');
  var CH = window.SDNChars, SND = window.SDNSound, STORY = window.SDNStory, ST = window.SDNSteps, C = window.SDNCarnets, P = window.SDNPhp, ED = window.SDNEditor, H = ST.helpers;
  var api = function (m, p) { return window.SGSdk.call(m, p); };

  var G = { rewards: [], data: {}, sc: null, session: null, ticket: null, def: null, step: 0, hints: 0, guide: [], guideStep: 0, file: null, visitor: null, tries: null, delivery: null, reaction: null, wrong: {}, sel: null, order: null, stage: null, editor: null, live: null };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function h(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }
  function $(sel, root) { return (root || app).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
  function copy(o) { return JSON.parse(JSON.stringify(o)); }
  var saveTimer = null;
  function save(now) {
    clearTimeout(saveTimer);
    var go = function () { api('saveProgress', { data: G.data }).catch(function () { }); };
    if (now) go(); else saveTimer = setTimeout(go, 900);
  }
  function nameOf(w) { return CH.name(w); }
  function gradeOf(n) { var g = STORY.grades[0][1]; STORY.grades.forEach(function (x) { if (n >= x[0]) g = x[1]; }); return g; }
  function isClosed(c) { return c.status === 'done' || c.status === 'reassigned'; }
  function closedIds() { var o = {}; (G.sc ? G.sc.cards : []).forEach(function (c) { if (isClosed(c)) o[c.id] = 1; }); return o; }
  function closedCount() { return G.sc ? G.sc.cards.filter(isClosed).length : 0; }
  function starsHTML(n) { return '<span class="etoiles" aria-label="' + (n || 0) + ' étoiles sur 3">' + [1, 2, 3].map(function (i) { return '<i class="' + (i <= n ? 'on' : '') + '"></i>'; }).join('') + '</span>'; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  var DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  function when(at) { var d = new Date(at.slice(0, 10) + 'T12:00:00'); return DAYS[d.getDay()] + ' ' + Number(at.slice(8, 10)) + ' · ' + at.slice(11, 16); }

  // ——— The sign: each letter is a notion ———
  function exercisedBy(id) { return ST.all().filter(function (d) { return d.notions.indexOf(id) !== -1; }).length; }
  function notionOf(id) { var n = G.data.notions[id]; if (!n) n = G.data.notions[id] = { pts: 0, shaky: false, exos: {} }; return n; }
  /** off: never met · flicker: met, not yet held · on: held (three proofs, or as many as the game offers). */
  function letterState(id) {
    if (!id) return 'off';
    var n = G.data.notions[id] || { pts: 0 }, note = C.get(id);
    var avail = Math.min(3, exercisedBy(id) + (note ? note.exos.length : 0));
    if (avail > 0 && n.pts >= avail && !n.shaky) return 'on';
    return G.data.met[id] || n.pts > 0 ? 'flicker' : 'off';
  }
  function signStates() { var o = {}; C.all().forEach(function (n) { o[n.id] = letterState(n.id); }); return o; }
  function signHTML(opts) {
    opts = opts || {};
    var s = C.sign(), fresh = opts.fresh || {};
    function letter(l, color) {
      var st = letterState(l.notion), note = C.get(l.notion);
      var label = note ? note.title + ' : ' + (st === 'on' ? 'maîtrisé' : st === 'flicker' ? 'vu, à consolider' : 'pas encore vu') : '';
      return '<button type="button" class="l ' + color + ' ' + st + (fresh[l.notion] ? ' neuf' : '') + '" data-notion="' + esc(l.notion || '') + '"' + (st === 'off' || opts.inert ? ' disabled' : '') + ' title="' + esc(label) + '" aria-label="' + esc(l.char + ' · ' + label) + '">' + esc(l.char) + '</button>';
    }
    return '<div class="enseigne' + (opts.small ? ' petite' : '') + '" role="group" aria-label="L\'enseigne : chaque lettre est une notion"><div class="ligne hotel">' + s.hotel.map(function (l) { return letter(l, 'rose'); }).join('') + '</div><div class="ligne quatre">' + s.quatre.map(function (l) { return letter(l, 'cyan'); }).join('') + '</div>' + (opts.legend ? '<div class="legende">Une lettre grésille quand tu as vu sa notion, elle brille quand tu la tiens. Clique sur une lettre pour ouvrir sa page du carnet.</div>' : '') + '</div>';
  }
  function bindSign(root) { $$('.l[data-notion]', root).forEach(function (b) { if (!b.disabled) b.addEventListener('click', function () { SND.click(); openCarnet(b.dataset.notion); }); }); }

  // ——— Scenes ———
  function playScene(lines, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!lines || !lines.length) { resolve(); return; }
      var scene = h('<section class="scene" aria-label="Scène"><div class="haut"><div class="tags"><span>' + esc(opts.tag || 'LA LOGE') + '</span></div><button type="button" class="btn petit fantome passer">Passer</button></div><div class="scene-zone"><div class="perso gauche" aria-hidden="true"></div><div class="perso droite" aria-hidden="true"></div></div>' +
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
      function key(e) { if (e.target && e.target.tagName === 'TEXTAREA') return; if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); next(); } if (e.key === 'Escape') end(); }
      $('.suivant', scene).addEventListener('click', next);
      $('.passer', scene).addEventListener('click', end);
      document.addEventListener('keydown', key);
      show();
    });
  }
  function sceneOnce(key, lines, tag) {
    if (!lines || !lines.length || G.data.seen[key]) return Promise.resolve();
    G.data.seen[key] = 1; save();
    return playScene(lines, { tag: tag });
  }

  // ——— Title ———
  function showTitle() {
    app.innerHTML = '';
    var started = !!G.data.prologue, done = closedCount();
    var view = h('<section class="titre"><div class="gauche"><div class="sur">Rue des Quatre-Vents · ouvert la nuit</div><h1>Service<br>de nuit</h1><p class="sous">Les carnets d\'Augustin</p>' +
      '<p class="pitch">Augustin a tenu la réception trente ans et écrit le site de l\'hôtel en PHP. Il est parti sans prévenir. Ce soir, c\'est toi.</p><div class="nav"></div></div>' +
      '<div class="droite">' + signHTML({ legend: started }) + '<div class="libres">CHAMBRES LIBRES</div><div class="horloge">23:12</div></div></section>');
    var nav = $('.nav', view);
    var act = G.sc.acts[G.sc.act - 1];
    var first = h('<button type="button" class="btn plein">' + (started ? 'Reprendre le service' : 'Prendre son service') + '<small>' + (started ? 'Acte ' + G.sc.act + ' · ' + esc(act.title) + ' — ' + done + ' nuit' + (done > 1 ? 's' : '') + ' sur ' + G.sc.total : 'Acte 1 · La loge') + '</small></button>');
    first.addEventListener('click', function () { SND.neon(); startGame(); });
    nav.appendChild(first);
    if (started) {
      var c = h('<button type="button" class="btn">Le carnet d\'Augustin</button>'); c.addEventListener('click', function () { openCarnet(null); }); nav.appendChild(c);
      var again = h('<button type="button" class="btn fantome">Revoir le prologue</button>');
      again.addEventListener('click', function () { playScene(STORY.prologue, { tag: 'PROLOGUE' }); });
      nav.appendChild(again);
      var nw = h('<button type="button" class="btn fantome">Recommencer depuis le début</button>');
      nw.addEventListener('click', function () { nw.classList.add('confirm'); if (nw.dataset.armed) { api('newGame', {}).catch(function () { }).then(function () { location.reload(); }); return; } nw.dataset.armed = '1'; nw.textContent = 'Confirmer : tout effacer, ton site aussi'; });
      nav.appendChild(nw);
    }
    var son = h('<button type="button" class="btn fantome" aria-pressed="' + (!SND.muted()) + '">' + (SND.muted() ? 'Son coupé' : 'Son activé') + '</button>');
    son.addEventListener('click', function () { SND.setMuted(!SND.muted()); G.data.mute = SND.muted(); save(); son.textContent = SND.muted() ? 'Son coupé' : 'Son activé'; son.setAttribute('aria-pressed', String(!SND.muted())); });
    nav.appendChild(son);
    app.appendChild(view);
    bindSign(view);
    first.focus();
  }
  function startGame() {
    if (!G.data.prologue) playScene(STORY.prologue, { tag: 'PROLOGUE' }).then(function () { G.data.prologue = true; save(true); showRue(); });
    else showRue();
  }

  // ——— Act announcement ———
  function actIntro(n) {
    return new Promise(function (resolve) {
      SND.act();
      var a = G.sc.acts[n - 1], st = STORY.acts[n] || {};
      var list = G.sc.acts.map(function (x) {
        var cls = x.n < n ? 'fait' : (x.n === n ? 'ici' : '');
        return '<li class="' + cls + '"><b>' + x.n + '</b><span style="flex:1;font-weight:600">' + (x.n <= n ? esc(x.title) : '· · ·') + '</span><span style="font:500 13px var(--mono)">' + (x.n < n ? 'fait' : (x.n === n ? 'ce soir' : '')) + '</span></li>';
      }).join('');
      var view = h('<section class="acte-intro" aria-label="Acte ' + n + '"><div><div class="num"><small>ACTE</small>' + n + '</div><h1>' + esc(a.title) + '</h1><div class="quand">' + esc(st.time || '') + (n === 4 ? ' · niveau 2' : '') + '</div><p>' + esc(st.blurb || '') + '</p><div class="notions">' + (st.notions || []).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div><button type="button" class="btn plein go">Entrer dans l\'acte ▸</button></div><ol aria-label="Les actes">' + list + '</ol></section>');
      app.appendChild(view);
      $('.go', view).focus();
      $('.go', view).addEventListener('click', function () { api('markActSeen', { act: n }).then(function () { view.remove(); resolve(); }); });
    });
  }

  // ——— Top bar ———
  function refreshScenario() { return api('getScenario').then(function (sc) { G.sc = sc; return sc; }); }
  function topBar(current, extra) {
    var b = h('<header class="bar"><div class="logo">Quatre-Vents</div><nav aria-label="Navigation"></nav><div class="droite"></div></header>');
    var nav = $('nav', b);
    [['rue', 'La rue', function () { leaveNight(); showRue(); }], ['carnet', 'Le carnet', function () { openCarnet(null); }], ['dossier', 'Le pied de page', function () { leaveNight(); showDossier(); }]].forEach(function (x) {
      var bt = h('<button type="button"' + (x[0] === current ? ' aria-current="page"' : '') + '>' + x[1] + '</button>'); bt.addEventListener('click', function () { SND.click(); x[2](); }); nav.appendChild(bt);
    });
    var d = $('.droite', b);
    if (extra) d.appendChild(extra);
    var son = h('<button type="button" class="btn petit fantome">' + (SND.muted() ? 'Son coupé' : 'Son') + '</button>'); son.addEventListener('click', function () { SND.setMuted(!SND.muted()); G.data.mute = SND.muted(); save(); son.textContent = SND.muted() ? 'Son coupé' : 'Son'; }); d.appendChild(son);
    var back = h('<button type="button" class="btn petit fantome">Écran titre</button>'); back.addEventListener('click', function () { leaveNight(); save(true); showTitle(); }); d.appendChild(back);
    return b;
  }
  function leaveNight() { if (G.ticket) { save(true); } G.ticket = null; G.def = null; G.editor = null; }

  // ——— The street (home) ———
  function siteLines() { var n = 0; for (var f in G.data.site) n += G.data.site[f].split('\n').length; return n; }
  function showRue(newIds) {
    if (!Array.isArray(newIds)) newIds = [];
    G.ticket = null; G.def = null;
    refreshScenario().then(function (sc) {
      if (sc.finished && !G.review) { showEnd(); return; }
      if (!sc.actSeen && !sc.finished) { actIntro(sc.act).then(function () { showRue(newIds); }); return; }
      var done = closedCount(), stars = 0, nStars = 0;
      sc.cards.forEach(function (c) { if (c.quality) { stars += c.quality; nStars++; } });
      var view = h('<section class="rue"></section>');
      view.appendChild(topBar('rue', h('<span class="chip">' + esc((STORY.acts[sc.act] || {}).time || '') + '</span>')));
      var centre = h('<div class="centre"><div class="facade">' + signHTML({ legend: true }) + '<div class="nuits"></div></div><div class="cote"></div></div>');
      var nuits = $('.nuits', centre), byId = {};
      sc.cards.forEach(function (c) { byId[c.id] = c; });
      sc.acts.forEach(function (a) {
        var row = h('<div class="acte"><h3>Acte ' + a.n + '<small>' + esc(a.n <= sc.act ? a.title : '· · ·') + (a.n === 4 ? ' · niveau 2' : '') + '</small></h3><div class="fenetres"></div></div>');
        ST.all().filter(function (d) { return d.act === a.n; }).forEach(function (d) {
          var c = byId[d.id];
          var cls = !c ? 'fermee' : (isClosed(c) ? 'faite' : 'ouverte');
          var w = h('<button type="button" class="fenetre-nuit ' + cls + (newIds.indexOf(d.id) !== -1 ? ' nouveau' : '') + '"' + (!c ? ' disabled' : '') + ' aria-label="Nuit ' + Number(d.id.slice(1)) + (c ? ' : ' + esc(c.title) : ', à venir') + '"><span class="num">Nuit ' + Number(d.id.slice(1)) + '</span><span class="nom">' + esc(c ? c.title : '· · ·') + '</span>' + (c && c.quality ? starsHTML(c.quality) : '<span></span>') + '</button>');
          if (c) w.addEventListener('click', function () { SND.bell(); if (isClosed(c)) reviewNight(c); else openNight(c.id); });
          $('.fenetres', row).appendChild(w);
        });
        nuits.appendChild(row);
      });
      var cote = $('.cote', centre);
      var open = sc.cards.filter(function (c) { return !isClosed(c); })[0];
      if (open) {
        var cons = h('<div class="carte consigne"><div class="tete">' + CH.svg('sami', 'sourire') + '</div><div><b>Sami</b>' + esc(open.status === 'doing' ? 'Reprends « ' + open.title + ' » : tu l\'as commencée.' : 'Ce soir : « ' + open.title + ' ». ') + '<div style="margin-top:10px"></div></div></div>');
        var go = h('<button type="button" class="btn plein petit">Prendre la nuit ▸</button>'); go.addEventListener('click', function () { SND.bell(); openNight(open.id); });
        $('div > div:last-child', cons).appendChild(go);
        cote.appendChild(cons);
      }
      cote.appendChild(h('<div class="carte"><h3>Avancement</h3><div class="grand">' + done + ' nuit' + (done > 1 ? 's' : '') + ' sur ' + sc.total + '</div><div style="color:var(--dim)">' + (nStars ? stars + ' étoiles sur ' + 3 * nStars : 'aucune étoile encore') + '</div></div>'));
      cote.appendChild(h('<div class="carte"><h3>Grade</h3><div class="grand">' + esc(gradeOf(done)) + '</div></div>'));
      var held = C.all().filter(function (n) { return letterState(n.id) === 'on'; }).length;
      cote.appendChild(h('<div class="carte"><h3>Ton site</h3><div class="grand">' + Object.keys(G.data.site).length + ' fichier' + (Object.keys(G.data.site).length > 1 ? 's' : '') + ' · ' + siteLines() + ' lignes</div><div style="color:var(--dim)">' + held + ' lettre' + (held > 1 ? 's' : '') + ' de l\'enseigne sur ' + C.all().length + ' brillent</div></div>'));
      var dos = h('<button type="button" class="carte" style="text-align:left;cursor:pointer;color:inherit"><h3>Le pied de page</h3><div>' + sc.clues.length + ' indice' + (sc.clues.length > 1 ? 's' : '') + ' sur 5</div><div class="pistes">' + [0, 1, 2, 3, 4].map(function (i) { return '<i class="' + (i < sc.clues.length ? 'on' : '') + '"></i>'; }).join('') + '</div></button>');
      dos.addEventListener('click', showDossier); cote.appendChild(dos);
      view.appendChild(centre);
      app.innerHTML = ''; app.appendChild(view);
      bindSign(view);
      var f = $('.fenetre-nuit.ouverte', view); if (f) f.focus();
    });
  }
  function reviewNight(c) {
    var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true"><h2>' + esc(c.title) + '</h2><p>Nuit servie' + (c.quality ? ' · ' + c.quality + ' étoile' + (c.quality > 1 ? 's' : '') + ' sur 3' : '') + '.</p><div class="actions"><button type="button" class="btn plein">Fermer</button></div></div></div>');
    $('.btn', w).addEventListener('click', function () { w.remove(); });
    app.appendChild(w); $('.btn', w).focus();
  }

  // ——— The red thread ———
  function showDossier() {
    refreshScenario().then(function (sc) {
      var view = h('<section class="rue"></section>');
      view.appendChild(topBar('dossier'));
      var corps = h('<div class="centre" style="grid-template-columns:1fr"><div style="max-width:820px;display:grid;gap:14px"><h1 style="font:400 30px var(--titre);margin:0">Le pied de page</h1><p style="font-size:18px;margin:0;color:#DCD6EA">Certaines nuits, une ligne apparaît tout en bas des pages de l\'hôtel. Personne ne l\'a écrite dans tes fichiers. Mireille et Sami notent ici ce que vous apprenez. Il y a cinq indices.</p></div></div>');
      var col = $('.centre > div', corps);
      for (var i = 0; i < 5; i++) col.appendChild(h('<div class="carte" style="display:grid;grid-template-columns:34px 1fr;gap:10px' + (sc.clues[i] ? '' : ';opacity:.5') + '"><b style="font:400 22px var(--titre);color:var(--ambre)">' + (i + 1) + '</b><span>' + esc(sc.clues[i] || 'Un indice reste à trouver.') + '</span></div>'));
      view.appendChild(corps);
      app.innerHTML = ''; app.appendChild(view);
    });
  }

  // ——— The night ———
  function siteFiles() {
    var order = ['accueil.php', 'chambres.php', 'navette.php', 'arrivees.php', 'tarifs.php', 'fonctions.php', 'reservation.php', 'veilleurs.php'];
    var names = Object.keys(G.data.site).sort(function (a, b) { var i = order.indexOf(a), j = order.indexOf(b); return (i < 0 ? 99 : i) - (j < 0 ? 99 : j) || (a < b ? -1 : 1); });
    return names;
  }
  function openNight(id) {
    api('startTicket', { ticket: id }).then(function (r) {
      G.session = r.session; G.ticket = r.ticket; G.step = r.step; G.hints = r.hints || 0; G.guide = r.guide || []; G.guideStep = r.guideStep || 0;
      G.def = ST.get(id) || null; G.reaction = null; G.wrong = {}; G.sel = null; G.order = null; G.delivery = null; G.tries = null; G.stage = null;
      var def = G.def;
      if (def) {
        for (var k in def.add || {}) if (!(k in G.data.site)) G.data.site[k] = def.add[k];
        if (!G.data.start || G.data.start.id !== id) G.data.start = { id: id, files: copy(G.data.site) };
        G.file = def.page in G.data.site ? def.page : siteFiles()[0];
        var vis = def.cases.filter(function (c) { return c.visible && !c.source; });
        G.visitor = vis.length ? vis[0].id : null;
        save();
      }
      var begin = function () { renderLoge(); };
      sceneOnce(id + ':i', def && def.intro, 'NUIT ' + Number(id.slice(1))).then(begin);
    }).catch(function (e) { app.appendChild(h('<div class="voile"><div class="fenetre"><h2>Nuit inaccessible</h2><p>' + esc(e.message) + '</p></div></div>')); setTimeout(showRue, 1500); });
  }
  function curStep() { return G.ticket.steps[G.step]; }
  function rungs() { return G.guide.length + (G.def ? G.def.hints.length : 0); }
  function guideOpen() { return G.guideStep < G.guide.length && G.hints > G.guideStep ? G.guide[G.guideStep] : null; }
  function baseFiles() { return G.data.start && G.data.start.id === G.ticket.id ? G.data.start.files : ST.startOf(G.ticket.id); }

  function renderLoge() {
    app.innerHTML = '';
    var view = h('<section class="loge"></section>');
    var n = Number(G.ticket.id.slice(1));
    view.appendChild(topBar('loge', h('<span class="chip">Nuit ' + n + ' · acte ' + G.ticket.act + '</span>')));
    view.appendChild(h('<div class="grille"><div class="col gauche"></div><div class="col droite"></div></div>'));
    app.appendChild(view);
    renderLeft(); renderRight();
  }
  function reactionHTML(r, good) { return '<div class="reaction' + (good ? ' bien' : '') + '"><div class="tete">' + CH.svg(r.who || 'sami', r.expr || 'neutre') + '</div><div><b>' + esc(nameOf(r.who || 'sami')) + '</b>' + esc(r.text) + '</div></div>'; }

  function renderLeft() {
    var col = $('.col.gauche'), t = G.ticket, st = curStep();
    if (!col) return;
    col.innerHTML = '';
    var p = h('<div class="panneau"></div>');
    p.innerHTML = '<div class="demandeur"><div class="tete">' + CH.svg(t.requester, 'neutre') + '</div><div><b>' + esc(nameOf(t.requester)) + '</b><small>' + esc(CH.role(t.requester)) + '</small></div></div>' +
      '<h1 class="titre-nuit">' + esc(t.title) + '</h1><p class="demande">' + esc(t.request) + '</p>' +
      '<div class="avancement">' + t.steps.map(function (s, i) { return '<i class="' + (i < G.step ? 'fait' : (i === G.step ? 'ici' : '')) + '" title="' + esc(s.label || s.kind) + '"></i>'; }).join('') + '</div>';
    if (G.reaction) p.appendChild(h(reactionHTML(G.reaction.r, G.reaction.good)));
    col.appendChild(p);
    if (st && st.kind === 'fix' && G.def) {
      col.appendChild(criteriaPanel());
      if (rungs()) col.appendChild(helpPanel());
    }
  }
  function criteriaPanel() {
    var def = G.def, t = G.ticket, box = h('<div class="panneau"><h2>Ce que vérifiera ' + esc(nameOf(t.requester)) + '</h2><ul class="criteres"></ul></div>');
    var res = G.delivery || G.tries, byId = {};
    (res ? res.results : []).forEach(function (r) { byId[r.id] = r; });
    var ul = $('.criteres', box);
    def.cases.filter(function (c) { return c.visible; }).forEach(function (c) {
      var r = byId[c.id];
      ul.appendChild(h('<li class="' + (r ? (r.ok ? 'ok' : 'ko') : '') + '">' + esc(c.label) + (r && !r.ok && r.detail ? '<small>' + esc(r.detail) + '</small>' : '') + '</li>'));
    });
    var hid = def.cases.filter(function (c) { return !c.visible; });
    if (G.delivery) {
      hid.forEach(function (c) { var r = byId[c.id]; ul.appendChild(h('<li class="' + (r && r.ok ? 'ok' : 'ko') + '">Caché · ' + esc(c.label) + (r && !r.ok && r.detail ? '<small>' + esc(r.detail) + '</small>' : '') + '</li>')); });
    } else ul.appendChild(h('<li class="cache">…et ' + hid.length + ' visiteurs que tu ne vois pas : ils viendront quand tu serviras la nuit.</li>'));
    if (def.cost) {
      var v = def.cost.measure(G.data.site, H, baseFiles()), okc = v <= def.cost.max;
      box.appendChild(h('<div class="compteur' + (okc ? ' ok' : '') + '"><small>' + esc(def.cost.label) + '</small><span class="val">' + v + '<small style="font-size:14px"> · Augustin : ' + def.cost.max + ' au plus</small></span></div>'));
    }
    var acts = h('<div class="actions" style="margin-top:12px"></div>');
    if (G.stage === 'ok:' + curStep().id) {
      var c = h('<button type="button" class="btn plein">Continuer ▸<small>la nuit est servie : on explique à ' + esc(nameOf(t.requester)) + '</small></button>');
      c.addEventListener('click', function () { G.stage = null; advance(); });
      acts.appendChild(c); setTimeout(function () { c.focus(); }, 0);
    } else {
      var essai = h('<button type="button" class="btn cyan">Essayer<small>les visiteurs visibles, sans compter</small></button>');
      essai.addEventListener('click', tryNow);
      var servir = h('<button type="button" class="btn plein">Servir la nuit<small>tous les visiteurs, même cachés</small></button>');
      servir.addEventListener('click', deliverNow);
      acts.appendChild(essai); acts.appendChild(servir);
    }
    box.appendChild(acts);
    var learn = (def.learn || []).filter(function (n) { return C.get(n); });
    if (learn.length) {
      var tried = !!G.data.tried[G.ticket.id], opened = learn.every(function (n) { return G.data.met[n]; });
      var cb = h('<button type="button" class="btn petit' + (tried && !opened ? ' appel' : '') + '" style="margin-top:10px"' + (tried ? '' : ' disabled') + '>Le carnet d\'Augustin<small>' + (tried ? esc(learn.map(function (n) { return C.get(n).title; }).join(' · ')) : 'essaie d\'abord avec ce que tu sais') + '</small></button>');
      cb.addEventListener('click', function () { openCarnet(learn[0]); });
      box.appendChild(cb);
    }
    return box;
  }
  function helpPanel() {
    var p = h('<div class="panneau aide"><h2>Sami</h2></div>');
    var gq = guideOpen(), cards = Math.max(0, G.hints - G.guide.length);
    if (gq) {
      var q = h('<div><b style="font:400 14px var(--titre);color:var(--cyan)">La question de Sami</b><p style="margin:6px 0">' + esc(gq.prompt) + '</p><div class="options"></div></div>');
      gq.options.forEach(function (o, i) {
        var b = h('<button type="button" class="option' + (G.wrong['g' + G.guideStep + ':' + i] ? ' faux' : '') + '"><b>' + String.fromCharCode(65 + i) + '</b><span class="code">' + esc(o) + '</span></button>');
        b.addEventListener('click', function () { submitGuide(gq, i); });
        $('.options', q).appendChild(b);
      });
      p.appendChild(q);
    }
    for (var i = 0; i < Math.min(cards, G.def.hints.length); i++) p.appendChild(h('<div class="art"><b>' + esc(G.def.hints[i].title) + '</b><pre>' + esc(G.def.hints[i].body) + '</pre></div>'));
    var more = G.hints < rungs() && !gq;
    if (more) {
      var b = h('<button type="button" class="btn petit" style="margin-top:10px">' + (G.hints === 0 ? 'Demander à Sami' : 'Un coup de pouce de plus') + '<small>coûte une étoile</small></button>');
      b.addEventListener('click', function () { api('useHint', { session: G.session }).then(function (r) { G.hints = r.hints; G.guideStep = r.guideStep; SND.click(); renderLeft(); }); });
      p.appendChild(b);
    }
    if (!gq && !more && G.hints === 0) p.appendChild(h('<p style="color:var(--dim)">Sami connaît ce genre de nuit. Demande-lui si tu bloques.</p>'));
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
    if (!col) return;
    col.innerHTML = '';
    G.editor = null;
    if (st.kind === 'fix') { col.appendChild(atelier()); col.appendChild(coupe()); refreshCut(); }
    else { col.style.gridTemplateRows = 'minmax(0, 1fr)'; col.appendChild(questionPanel(st)); return; }
    col.style.gridTemplateRows = '';
  }

  // ——— The editor and its files ———
  function piedReadable() { return !!closedIds().N11; }
  function atelier() {
    var box = h('<div class="atelier"><div class="onglets" role="tablist" aria-label="Les fichiers du site"></div><div class="zone-ed" style="min-height:0;display:grid"></div></div>');
    var tabs = $('.onglets', box);
    siteFiles().concat(['pied.php']).forEach(function (f) {
      var b = h('<button type="button" role="tab" aria-selected="' + (f === G.file) + '">' + esc(f) + (f === 'pied.php' && !piedReadable() ? ' 🔒' : '') + '</button>');
      b.addEventListener('click', function () { G.file = f; SND.click(); renderRight(); });
      tabs.appendChild(b);
    });
    var tools = h('<div class="outils"></div>');
    if (G.file !== 'pied.php') {
      var reset = h('<button type="button" class="mini">Reprendre la version propre</button>');
      reset.addEventListener('click', function () {
        if (!reset.dataset.armed) { reset.dataset.armed = '1'; reset.textContent = 'Confirmer : remettre ' + G.file + ' comme au début de la nuit (version d\'Augustin)'; return; }
        var clean = ST.startOf(G.ticket.id);
        if (clean[G.file] !== undefined) G.data.site[G.file] = clean[G.file];
        save(); renderRight(); renderLeft();
      });
      tools.appendChild(reset);
    }
    tabs.appendChild(tools);
    var zone = $('.zone-ed', box);
    if (G.file === 'pied.php') {
      if (!piedReadable()) zone.appendChild(h('<div class="verrou"><b>pied.php · verrouillé</b><p>Le fichier qu\'Augustin a laissé, ajouté par l\'hôtel en bas de toutes les pages. Il est protégé par un mot de passe que personne ne connaît… pour l\'instant.</p></div>'));
      else G.editor = ED.create(zone, { value: ST.PIED, readOnly: true, label: 'pied.php, en lecture seule' });
      return box;
    }
    G.editor = ED.create(zone, {
      value: G.data.site[G.file] || '', label: 'Le fichier ' + G.file,
      onChange: function (v) { G.data.site[G.file] = v; save(); scheduleLive(); }
    });
    return box;
  }
  function scheduleLive() { clearTimeout(G.live); G.live = setTimeout(function () { refreshCut(); renderLeftMeter(); }, 350); }
  function renderLeftMeter() {
    if (!G.def || !G.def.cost) return;
    var m = $('.compteur'); if (!m) return;
    var v = G.def.cost.measure(G.data.site, H, baseFiles()), okc = v <= G.def.cost.max;
    m.classList.toggle('ok', okc);
    m.querySelector('.val').firstChild.nodeValue = String(v);
  }

  // ——— The cut ———
  function visibleVisitors() { return G.def.cases.filter(function (c) { return c.visible && !c.source; }); }
  function coupe() {
    var box = h('<div class="coupe" aria-label="La coupe : ce que renvoie le serveur, ce que voit le navigateur"><div class="visiteurs"><span class="lab">Visiteurs</span></div><div class="registre"></div><div class="volets"><div class="volet"><div class="t">CE QUE RENVOIE LE SERVEUR<span>survole un passage écrit par PHP</span></div><pre class="renvoi"></pre></div><div class="volet"><div class="t">CE QUE VOIT LE NAVIGATEUR<span></span></div><div class="navigateur"><iframe title="La page, vue par le navigateur du visiteur" sandbox=""></iframe></div></div></div></div>');
    var byId = {};
    ((G.delivery || G.tries) ? (G.delivery || G.tries).results : []).forEach(function (r) { byId[r.id] = r; });
    visibleVisitors().forEach(function (c) {
      var r = byId[c.id];
      var b = h('<button type="button" class="visiteur' + (r ? (r.ok ? ' ok' : ' ko') : '') + '" aria-pressed="' + (c.id === G.visitor) + '"><span>' + esc(c.who || c.id) + '</span><span class="h">' + esc(when(c.at)) + '</span>' + (r ? '<span class="etat">' + (r.ok ? '✓' : '✗') + '</span>' : '') + '</button>');
      b.addEventListener('click', function () { G.visitor = c.id; SND.click(); $$('.visiteur', box).forEach(function (x) { x.setAttribute('aria-pressed', 'false'); }); b.setAttribute('aria-pressed', 'true'); refreshCut(); });
      $('.visiteurs', box).appendChild(b);
    });
    return box;
  }
  function usedRegistre(c) {
    var src = '', out = [];
    for (var f in G.data.site) src += G.data.site[f];
    for (var k in c.reg || {}) if (new RegExp('\\$' + k + '\\b').test(src) || new RegExp('\\$' + k + '\\b').test(ST.startOf(G.ticket.id)[G.def.page] || '') || (G.ticket.request.indexOf('$' + k) !== -1)) out.push(k);
    return out;
  }
  function phpValue(v) {
    if (typeof v === 'string') return '<span class="s">\'' + esc(v) + '\'</span>';
    if (Array.isArray(v)) return '[' + v.map(phpValue).join(', ') + ']';
    if (v && typeof v === 'object') return '[' + Object.keys(v).map(function (k) { return '<span class="s">\'' + esc(k) + '\'</span> => ' + phpValue(v[k]); }).join(', ') + ']';
    return esc(String(v));
  }
  function refreshCut() {
    var box = $('.coupe'); if (!box || !G.def) return;
    var c = G.def.cases.filter(function (x) { return x.id === G.visitor; })[0];
    if (!c) return;
    var reg = $('.registre', box), keys = usedRegistre(c);
    reg.innerHTML = esc(when(c.at)) + ' · ' + esc(c.page || 'accueil.php') + (c.get && Object.keys(c.get).length ? '?' + Object.keys(c.get).map(function (k) { return esc(k) + '=' + esc(c.get[k]); }).join('&amp;') : '') + (keys.length ? ' · registre : ' + keys.map(function (k) { return '<b>$' + esc(k) + '</b> = ' + phpValue(c.reg[k]); }).join(' · ') : '');
    var res;
    try { res = ST.serve(G.data.site, c); } catch (e) { res = { output: '', segments: [], errors: [{ level: 'Erreur', message: e.message, file: '', line: 0, fr: '' }] }; }
    var pre = $('.renvoi', box), frame = $('iframe', box);
    pre.innerHTML = '';
    if (res.errors.length) {
      var er = h('<div class="erreurs"></div>');
      res.errors.slice(0, 3).forEach(function (e) { er.appendChild(h('<p><b>' + esc(e.level) + '</b> : ' + esc(e.message) + ' dans ' + esc(e.file) + ', ligne ' + esc(e.line) + '</p>')); if (e.fr) er.appendChild(h('<p class="fr">' + esc(e.fr) + '</p>')); });
      pre.appendChild(er);
      if (G.editor && res.errors[0].file === G.file) G.editor.mark(res.errors[0].line);
    }
    (res.segments || []).forEach(function (s) {
      var sp = document.createElement('span');
      sp.className = s.php ? 'php' : 'html';
      sp.textContent = s.text;
      if (s.php) {
        sp.title = s.file + ', ligne ' + s.line;
        sp.addEventListener('mouseenter', function () { if (s.file === G.file && G.editor) G.editor.mark(s.line); });
        sp.addEventListener('mouseleave', function () { if (G.editor) G.editor.mark(null); });
        sp.addEventListener('click', function () { if (s.file !== G.file && G.data.site[s.file] !== undefined) { G.file = s.file; renderRight(); } if (G.editor) G.editor.mark(s.line); });
      }
      pre.appendChild(sp);
    });
    frame.srcdoc = res.output || '<p style="font-family:sans-serif;color:#888">(page vide)</p>';
  }

  // ——— Trying and serving ———
  function tryNow() {
    SND.run();
    var r = ST.runCases(G.def, G.data.site, baseFiles());
    G.tries = { results: r.results.filter(function (x) { return x.visible; }), passed: r.results.every(function (x) { return !x.visible || x.ok; }) };
    var first = !G.data.tried[G.ticket.id];
    G.data.tried[G.ticket.id] = true; save();
    var learn = (G.def.learn || []).filter(function (n) { return C.get(n) && !G.data.met[n]; });
    if (G.tries.passed) G.reaction = { r: { who: 'sami', expr: 'sourire', text: 'Les visiteurs visibles sont bien servis. Il en reste que tu ne vois pas : quand tu es sûr·e de toi, sers la nuit.' }, good: true };
    else if (first && learn.length) G.reaction = { r: { who: 'sami', expr: 'neutre', text: 'Tu butes, c\'est normal : c\'est là qu\'Augustin a écrit une page de son carnet. Ouvre-le, puis reviens.' }, good: false };
    else G.reaction = { r: { who: 'sami', expr: 'sourcil', text: 'Pas encore. Regarde les visiteurs en rouge : leur page, et ce qui ne va pas.' }, good: false };
    if (G.tries.passed) SND.right(); else SND.wrong();
    var bad = G.tries.results.filter(function (x) { return !x.ok && !x.id.match(/^(once|li|ifs|une|regle)$/) && x.at; })[0];
    if (bad) G.visitor = bad.id;
    renderLeft(); renderRight();
  }
  function deliverNow() {
    var st = curStep();
    SND.bell();
    var r = ST.runCases(G.def, G.data.site, baseFiles());
    G.delivery = r; G.tries = null;
    G.data.tried[G.ticket.id] = true;
    submit(st, { passed: r.passed, failed: r.failed });
  }

  // ——— Questions ———
  function viewHTML(v) {
    return '<div class="vue"><div class="titre-vue">' + esc(v.title) + '</div><pre>' + ED.highlight(v.lines.join('\n')) + '</pre></div>';
  }
  function questionPanel(st) {
    var box = h('<div class="panneau question"></div>');
    var v = st.view && G.def ? ST.view(G.def, st.view) : null;
    if (st.kind === 'choice' || st.kind === 'explain') {
      box.innerHTML = (st.label ? '<h2 style="font:400 14px var(--titre);color:var(--cyan)">' + esc(st.label) + '</h2>' : '') + '<h2>' + esc(st.prompt) + '</h2>' + (v ? viewHTML(v) : '') + '<div class="options"></div>';
      var opts = $('.options', box), ready = G.stage === 'ok:' + st.id;
      st.options.forEach(function (o, i) {
        var b = h('<button type="button" class="option' + (G.wrong[st.id + ':' + i] ? ' faux' : '') + '"' + (ready ? ' disabled' : '') + '><b>' + String.fromCharCode(65 + i) + '</b><span class="' + (v ? 'code' : '') + '">' + esc(o) + '</span></button>');
        b.addEventListener('click', function () { submit(st, i); });
        opts.appendChild(b);
      });
      if (ready) { var c = h('<button type="button" class="btn plein" style="justify-self:start">Continuer ▸</button>'); c.addEventListener('click', function () { G.stage = null; G.reaction = null; advance(); }); box.appendChild(c); setTimeout(function () { c.focus(); }, 0); }
    } else if (st.kind === 'line') {
      box.innerHTML = '<h2>' + esc(st.prompt) + '</h2><div class="vue"><div class="titre-vue">' + esc(v ? v.title : '') + '</div><div class="lignes" style="padding:8px 0"></div></div><div><button type="button" class="btn plein valider" disabled>Valider cette ligne</button></div>';
      var lignes = $('.lignes', box);
      (v ? v.lines : []).forEach(function (l, i) {
        var b = h('<button type="button" class="ligne-vue"><i>' + (i + 1) + '</i><span>' + ED.highlight(l) + '</span></button>');
        b.addEventListener('click', function () { $$('.ligne-vue', lignes).forEach(function (x) { x.classList.remove('choisie'); }); b.classList.add('choisie'); G.sel = l; $('.valider', box).disabled = false; SND.click(); });
        lignes.appendChild(b);
      });
      $('.valider', box).addEventListener('click', function () { submit(st, G.sel); });
      G.sel = null;
    } else if (st.kind === 'order') {
      if (!G.order) G.order = st.items.map(function (_, i) { return i; });
      box.innerHTML = '<h2>' + esc(st.prompt) + '</h2><ol class="ordre"></ol><div><button type="button" class="btn plein valider">Valider l\'ordre</button></div>';
      var ol = $('.ordre', box);
      G.order.forEach(function (idx, pos) {
        var li = h('<li><b>' + (pos + 1) + '</b><span>' + esc(st.items[idx]) + '</span><span><button type="button" class="mini" aria-label="Monter"' + (pos === 0 ? ' disabled' : '') + '>▲</button> <button type="button" class="mini" aria-label="Descendre"' + (pos === G.order.length - 1 ? ' disabled' : '') + '>▼</button></span></li>');
        var bs = $$('button', li);
        bs[0].addEventListener('click', function () { var t = G.order[pos - 1]; G.order[pos - 1] = G.order[pos]; G.order[pos] = t; SND.click(); renderRight(); });
        bs[1].addEventListener('click', function () { var t = G.order[pos + 1]; G.order[pos + 1] = G.order[pos]; G.order[pos] = t; SND.click(); renderRight(); });
        ol.appendChild(li);
      });
      $('.valider', box).addEventListener('click', function () { submit(st, G.order.slice()); });
    }
    return box;
  }

  // ——— Sending steps ———
  function submit(st, input) {
    api('submitStep', { session: G.session, step: st.id, input: input }).then(function (res) {
      if (res.ok) {
        SND.right();
        G.reaction = res.reaction ? { r: res.reaction, good: true } : null;
        if (st.kind === 'fix') { G.stage = 'ok:' + st.id; G.reaction = { r: { who: G.ticket.requester, expr: 'sourire', text: 'Tous les visiteurs sont servis, même ceux que tu ne voyais pas.' }, good: true }; renderLeft(); renderRight(); return; }
        if (res.reaction && (st.kind === 'choice' || st.kind === 'explain')) { G.stage = 'ok:' + st.id; renderLeft(); renderRight(); return; }
        if (res.reaction) { renderLeft(); setTimeout(function () { G.reaction = null; advance(); }, 1100); return; }
        advance();
      } else {
        SND.wrong();
        if (st.kind === 'choice' || st.kind === 'explain') G.wrong[st.id + ':' + input] = true;
        if (st.kind === 'fix') { var n = input.failed ? input.failed.length : 0; G.reaction = { r: { who: 'sami', expr: 'sourcil', text: 'Pas encore : ' + n + ' visiteur' + (n > 1 ? 's' : '') + ' mal servi' + (n > 1 ? 's' : '') + '. Les visiteurs cachés sont maintenant dans la liste, avec ce qui n\'allait pas. Corrige, essaie, et sers de nouveau.' }, good: false }; renderLeft(); renderRight(); return; }
        G.reaction = res.reaction ? { r: res.reaction, good: false } : null;
        renderLeft(); renderRight();
      }
    }).catch(function (e) { G.reaction = { r: { who: 'sami', expr: 'sourcil', text: 'La plateforme ne répond pas : ' + e.message }, good: false }; renderLeft(); });
  }
  function advance() {
    G.step++; G.reaction = null; G.wrong = {}; G.sel = null; G.order = null; G.stage = null;
    if (G.step >= G.ticket.steps.length) { finishNight(); return; }
    renderLeft(); renderRight();
  }

  // ——— The end of a night: the morning report ———
  function finishNight() {
    var id = G.ticket.id, def = G.def, before = signStates();
    var start = G.data.start && G.data.start.id === id ? G.data.start.files : ST.startOf(id);
    api('endTicket', { session: G.session, hints: G.hints }).then(function (res) {
      SND.stamp();
      // The sign: every notion the night exercised.
      (def ? def.notions : []).forEach(function (n) {
        var st = notionOf(n);
        G.data.met[n] = true;
        if (res.quality === 3 || (G.hints === 0 && res.quality >= 2)) st.pts++;
        if (res.quality === 1) st.shaky = true;
      });
      G.data.nights[id] = { quality: res.quality, hints: G.hints };
      G.data.history[id] = { lignes: siteLines(), fichiers: Object.keys(G.data.site).length };
      var after = signStates(), fresh = {};
      for (var k in after) if (after[k] !== before[k]) fresh[k] = 1;
      delete G.data.start;
      save(true);
      var go = function () {
        if (res.finished) { showEnd(); return; }
        var ids = (res.newCards || []).map(function (c) { return c.id; });
        var next = function () { if (res.newAct) { actIntro(res.newAct).then(function () { showRue(ids); }); } else showRue(ids); };
        refreshScenario().then(next);
      };
      sceneOnce(id + ':d', def && def.done, 'FIN DE NUIT').then(function () { return morningReport(id, res, start, fresh); }).then(rewardsScene).then(go);
    });
  }

  /** A line diff of two texts (longest common subsequence), with long unchanged runs folded. */
  function diffLines(a, b) {
    a = a.split('\n'); b = b.split('\n');
    var n = a.length, m = b.length, L = [], i, j;
    for (i = 0; i <= n; i++) { L.push(new Array(m + 1).fill(0)); }
    for (i = n - 1; i >= 0; i--) for (j = m - 1; j >= 0; j--) L[i][j] = a[i] === b[j] ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1]);
    var out = []; i = 0; j = 0;
    while (i < n && j < m) { if (a[i] === b[j]) { out.push({ t: 'eq', s: a[i] }); i++; j++; } else if (L[i + 1][j] >= L[i][j + 1]) { out.push({ t: 'del', s: a[i++] }); } else { out.push({ t: 'add', s: b[j++] }); } }
    while (i < n) out.push({ t: 'del', s: a[i++] });
    while (j < m) out.push({ t: 'add', s: b[j++] });
    return out;
  }
  function diffHTML(rows) {
    var html = '', run = [];
    function flush(last) {
      if (run.length > 6) { html += run.slice(0, 2).map(line).join('') + '<span class="d cut">… ' + (run.length - 4) + ' lignes inchangées</span>' + run.slice(-2).map(line).join(''); }
      else html += run.map(line).join('');
      run = [];
    }
    function line(r) { return '<span class="d ' + r.t + '">' + (r.t === 'add' ? '+ ' : r.t === 'del' ? '- ' : '  ') + esc(r.s) + '</span>'; }
    rows.forEach(function (r) { if (r.t === 'eq') run.push(r); else { flush(); html += line(r); } });
    flush(true);
    return html;
  }
  function morningReport(id, res, start, fresh) {
    return new Promise(function (resolve) {
      var def = G.def, changed = [], plus = 0, moins = 0;
      Object.keys(G.data.site).forEach(function (f) {
        var a = start[f] || '', b = G.data.site[f];
        if (a === b) return;
        var rows = diffLines(a, b);
        rows.forEach(function (r) { if (r.t === 'add') plus++; if (r.t === 'del') moins++; });
        changed.push({ f: f, rows: rows });
      });
      var served = def ? def.cases.filter(function (c) { return !c.source; }).length : 0;
      var lit = Object.keys(fresh).filter(function (k) { return letterState(k) === 'on'; }).map(function (k) { return C.get(k); });
      var view = h('<section class="rapport" aria-label="Le rapport du matin"><div class="haut"><span class="heure">06:00</span><h1>Le rapport du matin</h1><div class="etoiles-grandes" style="margin-left:auto">' + [1, 2, 3].map(function (i) { return '<i class="' + (i <= res.quality ? 'on' : '') + '"></i>'; }).join('') + '</div></div><div class="corps"><div class="diff"></div><div class="cote" style="overflow:auto"></div></div><div class="bas"><button type="button" class="btn plein">Rentrer dormir ▸</button></div></section>');
      var diff = $('.diff', view);
      if (changed.length) {
        diff.innerHTML = '<div class="t"><b>Ton code cette nuit</b><span class="plus">+' + plus + ' lignes</span><span class="moins">−' + moins + ' lignes</span><span>' + changed.map(function (c) { return esc(c.f); }).join(' · ') + '</span></div><pre>' + changed.map(function (c) { return '<span class="d cut">— ' + esc(c.f) + '</span>' + diffHTML(c.rows); }).join('') + '</pre>';
      } else diff.innerHTML = '<div class="t"><b>Cette nuit, tu as lu le code d\'Augustin</b></div><pre><span class="d eq">  Aucun fichier modifié : la nuit se jouait en lecture.</span></pre>';
      var cote = $('.cote', view);
      cote.appendChild(h('<div class="carte"><h3>L\'enseigne</h3>' + signHTML({ small: true, fresh: fresh, inert: true }) + '<p style="margin:8px 0 0;color:var(--dim)">' + (lit.length ? 'Allumée cette nuit : ' + lit.map(function (n) { return esc(n.letter + ' · ' + n.title); }).join(', ') + '.' : (Object.keys(fresh).length ? 'Des lettres se sont mises à grésiller : leurs notions sont vues. Trois preuves sans aide, ou les exercices du carnet, et elles brilleront.' : 'Les lettres de cette nuit brillent déjà, ou attendent une nuit sans aide.')) + '</p></div>'));
      if (def && served) cote.appendChild(h('<div class="carte"><h3>Les visiteurs</h3><div class="grand">' + served + ' servis</div><div style="color:var(--dim)">dont ' + def.cases.filter(function (c) { return !c.visible && !c.source; }).length + ' que tu ne voyais pas</div></div>'));
      cote.appendChild(h('<div class="carte"><h3>Ton site</h3><div class="grand">' + siteLines() + ' lignes</div><div style="color:var(--dim)">' + Object.keys(G.data.site).length + ' fichier' + (Object.keys(G.data.site).length > 1 ? 's' : '') + '</div></div>'));
      if (res.clue) { SND.clue(); cote.appendChild(h('<div class="carte"><h3>Le pied de page · indice ' + (res.clueIndex || '') + '</h3><div class="clue">' + esc(res.clue) + '</div></div>')); }
      app.appendChild(view);
      if (Object.keys(fresh).length) setTimeout(function () { SND.neon(); }, 300);
      var b = $('.bas .btn', view); b.focus();
      b.addEventListener('click', function () { view.remove(); resolve(); });
    });
  }
  // The collection pieces the platform granted at the closing of a night (SGSdk.onReward).
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

  // ——— Augustin's notebook ———
  function runSnippet(code, ctx, name) {
    ctx = ctx || {};
    return P.run({ files: (function () { var f = {}; f[name || 'exemple.php'] = code; return f; })(), entry: name || 'exemple.php', now: ctx.at || '2026-11-05 23:12:00', get: ctx.get || {}, globals: ctx.reg || {}, seed: 7 });
  }
  function openCarnet(id) {
    var met = function (n) { return G.data.met[n.id] || letterState(n.id) !== 'off'; };
    var list = C.all(), cur = id ? C.get(id) : (list.filter(met).slice(-1)[0] || null);
    if (cur) { G.data.met[cur.id] = true; save(); }
    var view = h('<div class="carnet" role="dialog" aria-modal="true" aria-label="Le carnet d\'Augustin"><div class="livre"><nav class="sommaire" aria-label="Les pages"><h2>Les carnets d\'Augustin</h2></nav><div class="page"></div></div></div>');
    var nav = $('.sommaire', view);
    function paintNav() {
      $$('button', nav).forEach(function (b) { b.remove(); });
      list.forEach(function (n) {
        var st = letterState(n.id), ok = met(n);
        var b = h('<button type="button" class="' + st + '"' + (ok ? '' : ' disabled') + (cur && cur.id === n.id ? ' aria-current="true"' : '') + '><b>' + esc(n.letter) + '</b><span>' + esc(ok ? n.title : 'Page encore collée') + '</span></button>');
        if (ok) b.addEventListener('click', function () { cur = n; G.data.met[n.id] = true; save(); SND.page(); paintNav(); paintPage(); });
        nav.appendChild(b);
      });
      var close = h('<button type="button" style="margin-top:14px;justify-content:center"><b></b><span>Fermer le carnet</span></button>');
      close.addEventListener('click', shut); nav.appendChild(close);
    }
    function paintPage() {
      var page = $('.page', view);
      page.innerHTML = '';
      if (!cur) { page.appendChild(h('<p class="intro">Le carnet est encore vide. Ses pages s\'ouvrent au fil des nuits.</p>')); return; }
      var st = letterState(cur.id);
      page.appendChild(h('<div class="haut-page"><div><h1>' + esc(cur.title) + '</h1><div style="color:var(--dim)">La lettre ' + esc(cur.letter) + ' de ' + (cur.word === 'hotel' ? 'HÔTEL' : 'QUATRE-VENTS') + ' · ' + (st === 'on' ? 'elle brille' : st === 'flicker' ? 'elle grésille' : 'éteinte') + '</div></div><button type="button" class="btn petit fantome fermer">Fermer</button></div>'));
      $('.fermer', page).addEventListener('click', shut);
      page.appendChild(h('<p class="intro">' + esc(cur.intro) + '</p>'));
      cur.blocks.forEach(function (b) {
        if (b.text) { page.appendChild(h('<p class="txt">' + esc(b.text) + '</p>')); return; }
        var ex = h('<div class="exemple"><div class="src" style="min-height:0;display:grid"></div><div class="sortie"><div class="barre"><span>Le navigateur</span><button type="button" class="mini">Exécuter ▸</button></div><div class="err"></div><iframe title="Résultat de l\'exemple" sandbox=""></iframe></div>' + (b.note ? '<div class="note">' + esc(b.note) + '</div>' : '') + '</div>');
        var ed = ED.create($('.src', ex), { value: b.code, label: 'Exemple modifiable' });
        var exec = function () { var r = runSnippet(ed.value(), b.run); $('iframe', ex).srcdoc = r.output || ''; $('.err', ex).innerHTML = r.errors.length ? '<div class="erreurs"><p><b>' + esc(r.errors[0].level) + '</b> : ' + esc(r.errors[0].message) + ', ligne ' + r.errors[0].line + '</p><p class="fr">' + esc(r.errors[0].fr || '') + '</p></div>' : ''; };
        $('.mini', ex).addEventListener('click', function () { SND.run(); exec(); });
        page.appendChild(ex); exec();
      });
      page.appendChild(h('<div class="retenir"><h3>À retenir</h3><ul>' + cur.retenir.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></div>'));
      cur.exos.forEach(function (x, i) {
        var done = !!notionOf(cur.id).exos[x.id];
        var box = h('<div class="exo' + (done ? ' fait' : '') + '"><div class="tete-exo"><span><b>À toi ' + (i + 1) + '</b>' + esc(x.prompt) + '</span><button type="button" class="btn petit cyan">Vérifier</button></div><div class="src" style="display:grid"></div><div class="verdict">' + (done ? '<span class="ok">Réussi : la lettre a gagné une preuve.</span>' : '<span style="color:var(--dim)">Réussir un exercice consolide la lettre ' + esc(cur.letter) + ' : sur plusieurs essais, ta page doit montrer la même chose que celle d\'Augustin.</span>') + '</div></div>');
        var key = cur.id + ':' + x.id;
        G.data.exoDrafts = G.data.exoDrafts || {};
        var ed = ED.create($('.src', box), { value: G.data.exoDrafts[key] !== undefined ? G.data.exoDrafts[key] : x.start, label: 'Ton code pour l\'exercice ' + (i + 1), onChange: function (v) { G.data.exoDrafts[key] = v; save(); } });
        $('.tete-exo .btn', box).addEventListener('click', function () {
          var verdict = $('.verdict', box), allOk = true, lines = [];
          x.runs.forEach(function (ctx, k) {
            var mine = runSnippet(ed.value(), ctx, 'exercice.php'), ref = runSnippet(x.ref, ctx, 'exercice.php');
            var a = H.text(mine.output), b = H.text(ref.output);
            var ok = !mine.errors.length && a === b;
            if (!ok) allOk = false;
            var label = (ctx.at ? when(ctx.at) : '') + (ctx.reg ? ' ' + Object.keys(ctx.reg).map(function (n) { return '$' + n + ' = ' + JSON.stringify(ctx.reg[n]); }).join(', ') : '') + (ctx.get && Object.keys(ctx.get).length ? ' ?' + Object.keys(ctx.get).map(function (n) { return n + '=' + ctx.get[n]; }).join('&') : '');
            lines.push('<div class="' + (ok ? 'ok' : 'ko') + '">' + (ok ? '✓' : '✗') + ' Essai ' + (k + 1) + (label.trim() ? ' (' + esc(label.trim()) + ')' : '') + (ok ? '' : ' : ta page montre <code>' + esc(mine.errors.length ? mine.errors[0].level + ' : ' + mine.errors[0].message : a || '(rien)') + '</code>, il fallait <code>' + esc(b) + '</code>') + '</div>');
          });
          verdict.innerHTML = lines.join('');
          if (allOk) {
            SND.right();
            var n = notionOf(cur.id), wasOn = letterState(cur.id) === 'on';
            if (!n.exos[x.id]) { n.exos[x.id] = true; n.pts++; }
            n.shaky = false;
            save(true);
            box.classList.add('fait');
            verdict.innerHTML += '<div class="ok">' + (letterState(cur.id) === 'on' && !wasOn ? 'La lettre ' + esc(cur.letter) + ' s\'allume pour de bon.' : 'Une preuve de plus pour la lettre ' + esc(cur.letter) + '.') + '</div>';
            if (letterState(cur.id) === 'on' && !wasOn) SND.neon();
            paintNav();
          } else SND.wrong();
        });
        page.appendChild(box);
      });
      page.scrollTop = 0;
    }
    function shut() { document.removeEventListener('keydown', key); view.remove(); if (G.ticket) { renderLeft(); } else if ($('.rue') || $('.titre')) { if ($('.titre')) showTitle(); else showRue(); } }
    function key(e) { if (e.key === 'Escape') shut(); }
    document.addEventListener('keydown', key);
    paintNav(); paintPage();
    app.appendChild(view);
    SND.page();
  }

  // ——— The end ———
  function showEnd() {
    refreshScenario().then(function (sc) {
      var show = function () {
        var stars = 0, n = 0, three = 0; sc.cards.forEach(function (c) { if (c.quality) { stars += c.quality; n++; if (c.quality === 3) three++; } });
        var finish = three >= 15 ? 'or' : (three >= 11 ? 'argent' : 'laiton');
        var view = h('<section class="fin"><div><h1>La loge est à toi</h1><p>Dix-sept nuits. Un site qui donne l\'heure, dit bonsoir, compte les chambres, parle français et ferme la porte aux petits malins. Et un ancien veilleur qui revient le jeudi, pour le plaisir.</p><p class="grade">' + esc(gradeOf(closedCount())) + '</p><p style="color:var(--dim)">' + stars + ' étoiles sur ' + 3 * n + ', dont ' + three + ' nuits sans erreur ni aide.</p><p>Trophée : <b>la clé de la 12, ' + finish + '</b>, et son épinglette Néon, dans ta vitrine.</p><div class="nav" style="display:flex;gap:10px;flex-wrap:wrap"></div></div><div style="display:grid;justify-items:center;gap:20px">' + signHTML({ legend: true }) + '<div class="libres">COMPLET CE SOIR</div></div></section>');
        var nav = $('.nav', view);
        var c = h('<button type="button" class="btn">Le carnet</button>'); c.addEventListener('click', function () { openCarnet(null); }); nav.appendChild(c);
        var fresh = h('<button type="button" class="btn fantome">Nouvelle partie</button>'); fresh.addEventListener('click', function () { if (!fresh.dataset.armed) { fresh.dataset.armed = '1'; fresh.textContent = 'Confirmer : tout recommencer'; return; } api('newGame', {}).then(function () { location.reload(); }); }); nav.appendChild(fresh);
        var r = h('<button type="button" class="btn plein">Retour dans la rue</button>'); r.addEventListener('click', function () { G.review = true; showRue(); }); nav.appendChild(r);
        app.innerHTML = ''; app.appendChild(view);
        bindSign(view);
      };
      if (!G.data.epilogue) { G.data.epilogue = true; save(true); playScene(STORY.epilogue, { tag: 'ÉPILOGUE' }).then(show); } else show();
    });
  }

  // ——— Start ———
  window.SGSdk.onReward(function (rewards) { G.rewards = G.rewards.concat(rewards); });
  Promise.all([api('getProgress'), api('getScenario')]).then(function (r) {
    var d = r[0].data;
    G.data = d && typeof d === 'object' && !Array.isArray(d) ? d : {};
    ['seen', 'site', 'notions', 'met', 'tried', 'nights', 'history'].forEach(function (k) { if (!G.data[k] || typeof G.data[k] !== 'object' || Array.isArray(G.data[k])) G.data[k] = {}; });
    if (G.data.mute) SND.setMuted(true);
    G.sc = r[1];
    var go = /open=(N\d+)/.exec(location.hash); // a shortcut for the tests and the screenshots of the prototype
    if (go) {
      G.data.prologue = true; G.data.seen[go[1] + ':i'] = 1; G.data.seen[go[1] + ':d'] = 1;
      if (!Object.keys(G.data.site).length) G.data.site = ST.startOf(go[1]);
      refreshScenario().then(function () { return api('markActSeen', { act: G.sc.act }); }).then(function () { openNight(go[1]); });
      return;
    }
    if (/rue/.test(location.hash)) { G.data.prologue = true; refreshScenario().then(function () { return api('markActSeen', { act: G.sc.act }); }).then(function () { showRue(); }); return; }
    showTitle();
  }).catch(function (e) { app.textContent = 'Erreur de chargement : ' + e.message; });
})();
