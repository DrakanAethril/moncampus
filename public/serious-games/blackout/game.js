/* Black-out — the game's screens: title, scenes, act announcements, the helpdesk, the workstation
   (the ticket, the configuration files, the map of the room, the console), the solution, the
   inventory. The platform is reached through the SDK only (sdk.js); the network through the
   simulator (net.js), rebuilt from the student's files whenever they change. */
(function () {
  'use strict';
  var app = document.getElementById('app');
  var N = window.BlackoutNet, W = window.BlackoutWorld, CH = window.BlackoutChars, SND = window.BlackoutSound, STORY = window.BlackoutStory, TK = window.BlackoutTickets;
  var api = function (m, p) { return window.SGSdk.call(m, p); };

  var G = { user: null, rewards: [], data: { seen: {}, drafts: {} }, sc: null, session: null, ticket: null, def: null, files: null, tab: null, results: null, errors: 0, hints: 0, guide: [], guideStep: 0, step: 0, consoleDev: null, consoleLog: [], trace: null, world: null, history: [], hist: -1 };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function h(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }
  function $(sel, root) { return (root || app).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  // The frame is sandboxed without allow-modals: window.alert() would be swallowed silently.
  function notice(text) {
    var w = h('<div class="voile"><div class="fenetre" role="alertdialog" aria-modal="true"><p>' + esc(text) + '</p><div class="actions"><button type="button" class="btn btn-magenta">FERMER</button></div></div></div>');
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
  function num(id) { return '#00' + id.slice(1); }
  function actOf() { return (G.ticket && G.ticket.act) || (G.sc && G.sc.act) || 1; }
  function clock() { var a = STORY.acts[actOf()]; return a ? a.time : ''; }
  function gradeOf(n) { var g = STORY.grades[0][1]; STORY.grades.forEach(function (x) { if (n >= x[0]) g = x[1]; }); return g; }
  function stars(err, hints) { return err === 0 && hints === 0 ? 3 : (err + hints <= 2 ? 2 : 1); }
  function jauge(n) { return '<span class="jauge" aria-label="' + n + ' étoiles sur 3">' + [1, 2, 3].map(function (i) { return '<span class="' + (i <= n ? 'on' : '') + '"></span>'; }).join('') + '</span>'; }
  var STATUS = { todo: 'Nouveau', doing: 'En cours', done: 'Clos', reassigned: 'Clos' };
  var PRIO = { 'tres-haute': 'Très haute', haute: 'Haute', moyenne: 'Moyenne', basse: 'Basse' };

  // ——— Scenery shared by title, scenes and acts ———
  function bandsSVG(dense) {
    return '<svg class="bandes" aria-hidden="true" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice">' +
      '<path d="M820 0 L1440 0 L1440 900 L560 900 Z" fill="#140C2B"/>' +
      '<path d="M1040 0 L1110 0 L830 900 L760 900 Z" fill="#FF2E88" opacity="' + (dense ? '.9' : '.75') + '"/>' +
      '<path d="M1130 0 L1150 0 L870 900 L850 900 Z" fill="#00E5FF" opacity=".8"/>' +
      '<path d="M0 640 L520 640 L470 900 L0 900 Z" fill="#110A24"/></svg>';
  }
  function hallSVG() {
    var s = '<svg class="decor" aria-hidden="true" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice"><rect width="1440" height="520" fill="#0E0920"/><rect y="520" width="1440" height="380" fill="#0A0618"/>' +
      '<path d="M0 96 L1440 96" stroke="#1B1236" stroke-width="10"/><path d="M300 0 L420 0 L180 900 L60 900 Z" fill="#FF2E88" opacity=".07"/><path d="M1080 0 L1180 0 L1400 900 L1300 900 Z" fill="#00E5FF" opacity=".06"/>' +
      '<path d="M640 0 L660 0 L560 900 L540 900 Z" fill="#FF2E88" opacity=".5"/><path d="M676 0 L684 0 L584 900 L576 900 Z" fill="#00E5FF" opacity=".5"/>';
    for (var r = 0; r < 2; r++) for (var i = 0; i < (r ? 12 : 16); i++) {
      var k = r * 16 + i, lit = (k * 7) % 5 < 2, c = k % 3 === 0 ? '0,229,255' : '178,107,255';
      var x = (r ? 10 : 40) + i * (r ? 118 : 86), y = r ? 520 : 440, w = r ? 74 : 52, hh = r ? 46 : 32;
      s += '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + hh + '" fill="' + (lit ? 'rgba(' + c + ',.28)' : '#120D25') + '"/>';
    }
    return s + '</svg>';
  }

  // ——— Scenes (visual novel) ———
  function playScene(lines, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var scene = h('<section class="scene" aria-label="Scène"></section>');
      scene.innerHTML = hallSVG() + '<div class="haut"><div class="tags"><span style="background:#F4F2FF;color:#0A0618">' + esc(opts.tag || 'COULISSES') + '</span>' +
        (opts.sub ? '<span style="background:#1B1236;color:#C2BDD9">' + esc(opts.sub) + '</span>' : '') + '</div><button type="button" class="passer">PASSER</button></div>' +
        '<div class="perso gauche" aria-hidden="true"></div><div class="perso droite" aria-hidden="true"></div><div class="flash"></div>' +
        '<div class="lower" aria-live="polite"><div class="nom"><b></b><span></span></div><div class="texte"><span class="t"></span><span class="suite"><button type="button" class="btn btn-blanc" style="background:#0A0618;color:#F4F2FF">SUIVANT ▸</button></span></div></div>';
      app.appendChild(scene);
      var speakers = [];
      lines.forEach(function (l) { if (l.who !== 'narr' && speakers.indexOf(l.who) === -1) speakers.push(l.who); });
      var slots = { gauche: speakers[0] || null, droite: speakers[1] || null };
      var i = 0;
      function show() {
        var l = lines[i];
        var lower = $('.lower', scene);
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
          el.classList.toggle('parle', speaking);
          el.classList.toggle('eteint', !speaking);
        });
        $('.t', scene).textContent = l.text;
        if (l.cut) { SND.cut(); var f = $('.flash', scene); f.classList.remove('go'); void f.offsetWidth; f.classList.add('go'); }
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
    view.innerHTML = bandsSVG(true) +
      '<div class="bloc"><div class="direct"><span class="live">● EN DIRECT</span><span class="perdu">SIGNAL PERDU · 21:47</span></div>' +
      '<div class="sur">LAN DU LYCÉE · 48 H · 200 JOUEURS</div><h1 class="split">BLACK-OUT</h1><p class="sous">LA NUIT DU TOURNOI</p>' +
      '<p class="pitch">Deux cents joueurs, un réseau monté à la va-vite et parti avec son auteur. Alternant·e chez le prestataire, vous avez jusqu\'à l\'aube pour tout remettre en ligne, sous l\'œil de Nadia. Et quelqu\'un, dans la salle, ne veut pas que le tournoi aille au bout.</p>' +
      '<nav aria-label="Menu"></nav></div>' +
      '<div class="carte-perso" aria-hidden="true">' + CH.svg('nadia', 'sourcil') + '<div class="plaque"><span style="font-style:italic;font-size:24px">NADIA</span><span style="font-size:12px;letter-spacing:.14em;color:#5A4F80">TUTRICE · ADMIN RÉSEAU</span></div></div>' +
      '<div class="score"><div class="a">QUART DE FINALE</div><div class="b"><span>TEAM KITSUNE</span><span style="color:#C6FF3D">1 — 1</span><span>LES PINGOUINS</span><span style="font-size:13px;color:#FF2E88;letter-spacing:.12em">MATCH EN PAUSE</span></div><div class="c">REPRISE : ?</div></div>';
    var nav = $('nav', view);
    var first = h('<button type="button" class="btn btn-magenta">' + (started ? 'REPRENDRE LA NUIT' : 'COMMENCER LA NUIT') + '<small>' + (started ? 'Acte ' + G.sc.act + ' · ' + esc(G.sc.acts[G.sc.act - 1].title) + ' — ' + done + ' tickets clos' : 'Acte 1 · Le local technique') + '</small></button>');
    first.addEventListener('click', function () { SND.click(); startGame(); });
    nav.appendChild(first);
    if (started) {
      var again = h('<button type="button" class="btn btn-cyan">REVOIR LE PROLOGUE<small>La coupure de 21 h 47</small></button>');
      again.addEventListener('click', function () { playScene(STORY.prologue, { tag: 'PROLOGUE', sub: 'VENDREDI · 21:46' }); });
      nav.appendChild(again);
      // Two clicks, never a confirm(): the first one only says what the second will do.
      var restart = h('<button type="button" class="btn btn-fantome">RECOMMENCER DEPUIS LE DÉBUT<small>Les tickets repartent de zéro ; ta collection et ton grade restent</small></button>');
      restart.addEventListener('click', function () {
        if (!restart.classList.contains('armed')) {
          restart.classList.add('armed');
          restart.innerHTML = 'CONFIRMER : TOUT RECOMMENCER<small>Les tickets clos et en cours repartent de zéro</small>';
          return;
        }
        SND.click(); newGame(restart);
      });
      nav.appendChild(restart);
    }
    nav.appendChild(soundButton());
    app.appendChild(view);
    first.focus();
  }
  // « Nouvelle partie »: the platform puts the tickets back to zero, the game its display state.
  function newGame(button) {
    button.disabled = true;
    api('newGame').then(function () {
      G.data = { seen: {}, drafts: {}, mute: SND.muted() }; save(true);
      return refreshScenario();
    }).then(showTitle).catch(function (e) { button.disabled = false; notice('Impossible de recommencer la partie : ' + e.message); });
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
    if (!G.data.prologue) {
      playScene(STORY.prologue, { tag: 'PROLOGUE', sub: 'VENDREDI · 21:46' }).then(function () { G.data.prologue = true; save(true); showHelpdesk(); });
    } else showHelpdesk();
  }

  // ——— Act announcement ———
  function actIntro(n) {
    return new Promise(function (resolve) {
      SND.act();
      var a = G.sc.acts[n - 1], st = STORY.acts[n] || {};
      var view = h('<section class="acte" aria-label="Acte ' + n + '"></section>');
      var list = G.sc.acts.map(function (x) {
        var cls = x.n < n ? 'fait' : (x.n === n ? 'ici' : '');
        return '<li class="' + cls + '"><b>0' + x.n + '</b><span style="flex:1;font-weight:600">' + (x.n <= n ? esc(x.title) : '· · ·') + '</span><span style="font-family:var(--mono);font-size:13px">' + (x.n < n ? 'TERMINÉ' : (x.n === n ? 'À JOUER' : '')) + '</span></li>';
      }).join('');
      view.innerHTML = bandsSVG(false) +
        '<div class="bloc"><div class="tags"><span style="background:#C6FF3D;color:#0A0618">MANCHE SUIVANTE</span><span style="background:#1B1236;color:#C2BDD9">' + esc(st.time || '') + '</span></div>' +
        '<div class="num"><small>ACTE</small><b>0' + n + '</b></div><h1>' + esc(a.title) + '</h1><p>' + esc(st.blurb || '') + '</p>' +
        '<div class="notions">' + (st.notions || []).map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</div>' +
        '<button type="button" class="btn btn-magenta go">ENTRER DANS L\'ACTE ▸</button></div><ol class="liste" aria-label="Les actes de la nuit">' + list + '</ol>';
      app.appendChild(view);
      $('.go', view).focus();
      $('.go', view).addEventListener('click', function () {
        api('markActSeen', { act: n }).then(function () { view.remove(); resolve(); });
      });
    });
  }

  // ——— Bar ———
  function bar(current) {
    var b = h('<header class="bar"><div class="logo">BLACK-OUT</div><nav aria-label="Outils"></nav><div class="droite"></div></header>');
    var nav = $('nav', b);
    [['helpdesk', 'HELPDESK', showHelpdesk], ['carte', 'CARTE DE LA SALLE', showMapPage], ['inventaire', 'INVENTAIRE', showInventory]].forEach(function (x) {
      var bt = h('<button type="button"' + (x[0] === current ? ' aria-current="page"' : '') + '>' + x[1] + '</button>');
      bt.addEventListener('click', function () { SND.click(); save(true); x[2](); });
      nav.appendChild(bt);
    });
    var d = $('.droite', b);
    d.appendChild(h('<span class="chip" style="background:#1B1236;color:#F4F2FF"><span>ACTE ' + actOf() + '</span></span>'));
    d.appendChild(h('<span class="heure">' + esc(clock()) + '</span>'));
    var son = h('<button type="button" class="son" aria-pressed="' + (!SND.muted()) + '">' + (SND.muted() ? 'Son coupé' : 'Son') + '</button>');
    son.addEventListener('click', function () { SND.setMuted(!SND.muted()); G.data.mute = SND.muted(); save(); son.textContent = SND.muted() ? 'Son coupé' : 'Son'; });
    d.appendChild(son);
    var me = avatar(34);
    if (me) d.appendChild(h('<span class="moi" title="' + esc(G.user.firstName || '') + '">' + me + '</span>'));
    return b;
  }
  // The student's avatar, from the platform (getUser), with the control room's headset - the
  // game's costume. An adult has one too; only a student collects.
  function avatar(size) {
    if (!G.user || !G.user.avatar || !window.SGAvatar) return '';
    return window.SGAvatar.html(G.user.avatar, size, { costume: 'blackout' });
  }

  // ——— Helpdesk ———
  function refreshScenario() { return api('getScenario').then(function (sc) { G.sc = sc; return sc; }); }
  function showHelpdesk(newIds) {
    if (!Array.isArray(newIds)) newIds = [];
    G.ticket = null;
    refreshScenario().then(function (sc) {
      if (sc.finished) { showEnd(); return; }
      if (!sc.actSeen) { actIntro(sc.act).then(function () { showHelpdesk(newIds); }); return; }
      app.innerHTML = '';
      app.appendChild(bar('helpdesk'));
      app.appendChild(h('<div class="ticker" aria-hidden="true"><span>EN PAUSE</span><span>QUART DE FINALE · TEAM KITSUNE 1 — 1 LES PINGOUINS</span><span>' + esc(sc.acts[sc.act - 1].title.toUpperCase()) + '</span><span>' + sc.cards.filter(function (c) { return c.status === 'done' || c.status === 'reassigned'; }).length + ' / ' + sc.total + ' TICKETS CLOS</span></div>'));
      var open = sc.cards.filter(function (c) { return c.status === 'todo' || c.status === 'doing'; });
      var closedC = sc.cards.filter(function (c) { return c.status === 'done' || c.status === 'reassigned'; });
      var wrap = h('<div class="helpdesk"><aside></aside><main><h1>Tickets attribués à moi</h1><div class="tickets" role="table" aria-label="Tickets"></div></main></div>');
      var aside = $('aside', wrap);
      aside.appendChild(h('<div style="display:flex;flex-direction:column;gap:8px"><div class="k">En cours</div><div class="jauge" style="gap:6px">' +
        [0, 1, 2, 3].map(function (i) { return '<span class="' + (i < open.length ? 'on' : '') + '" style="flex:1"></span>'; }).join('') + '</div><div style="font-size:14px;color:var(--texte-2)">' + open.length + ' sur ' + sc.capacity + (open.length < sc.capacity ? '' : ' : terminez-en un pour en recevoir un autre') + '</div></div>'));
      aside.appendChild(h('<div class="vues"><div class="sel">Attribués à moi <b>' + open.length + '</b></div><div>Nouveaux <b style="color:var(--cyan)">' + sc.cards.filter(function (c) { return c.status === 'todo'; }).length + '</b></div><div>En cours <b>' + sc.cards.filter(function (c) { return c.status === 'doing'; }).length + '</b></div><div>Clos <b>' + closedC.length + '</b></div></div>'));
      aside.appendChild(h('<div style="display:flex;flex-direction:column;gap:6px"><div class="k">Grade</div><div style="font-family:var(--titre);font-style:italic;font-weight:700;font-size:18px">' + esc(gradeOf(closedC.length)) + '</div></div>'));
      aside.appendChild(h('<div style="display:flex;flex-direction:column;gap:8px"><div class="k">Groupe</div><div style="color:var(--texte-2)">Réseau · prestataire</div><div class="k" style="margin-top:8px">Indices sur le Ghost</div><div class="indices">' +
        [0, 1, 2, 3, 4].map(function (i) { return '<span class="' + (i < sc.clues.length ? 'on' : '') + '"></span>'; }).join('') + '</div><div style="color:var(--texte-2)">' + sc.clues.length + ' sur 5</div></div>'));
      var table = $('.tickets', wrap);
      table.appendChild(h('<div class="entete" role="row"><span>ID</span><span>TITRE</span><span>STATUT</span><span>PRIORITÉ</span><span>DEMANDEUR</span><span>CATÉGORIE</span></div>'));
      var order = sc.cards.slice().sort(function (a, b) {
        var r = { doing: 0, todo: 1, done: 2, reassigned: 2 };
        if (r[a.status] !== r[b.status]) return r[a.status] - r[b.status];
        return r[a.status] === 2 ? (b.id > a.id ? 1 : -1) : (a.id > b.id ? 1 : -1);
      });
      order.forEach(function (c) {
        var closed = c.status === 'done' || c.status === 'reassigned';
        var row = h('<button type="button" role="row" class="ligne ' + (closed ? 'done' : c.status) + (newIds.indexOf(c.id) !== -1 ? ' nouveau' : '') + '"></button>');
        row.innerHTML = '<span class="id" role="cell">' + num(c.id) + '</span><span class="t" role="cell"><b>' + esc(c.title) + '</b><small>' + (c.kind === 'demande' ? 'Demande' : 'Incident') + (c.status === 'doing' ? ' · étape ' + (c.step + 1) + ' sur ' + c.steps : '') + (closed && c.quality ? ' · ' + c.quality + ' étoile' + (c.quality > 1 ? 's' : '') : '') + '</small></span>' +
          '<span class="st" role="cell"><i></i>' + STATUS[c.status] + '</span><span role="cell"><span class="chip prio-' + c.priority + '"><span>' + (PRIO[c.priority] || c.priority).toUpperCase() + '</span></span></span>' +
          '<span role="cell" style="color:var(--texte-2)">' + esc(nameOf(c.requester)) + '</span><span class="cat" role="cell">' + esc(c.category) + '</span>';
        if (closed) row.addEventListener('click', function () { reviewTicket(c); });
        else row.addEventListener('click', function () { SND.click(); openTicket(c.id); });
        table.appendChild(row);
      });
      app.appendChild(wrap);
      var rank = { 'tres-haute': 0, haute: 1, moyenne: 2, basse: 3 };
      var firstOpen = order.filter(function (c) { return c.status === 'doing'; })[0] ||
        order.filter(function (c) { return c.status === 'todo'; }).sort(function (a, b) { return (rank[a.priority] - rank[b.priority]) || (a.id > b.id ? 1 : -1); })[0];
      if (firstOpen) {
        var adv = firstOpen.status === 'doing' ? 'Reprends le ' + num(firstOpen.id) + ' : tu l\'as commencé.' :
          (firstOpen.priority === 'tres-haute' ? 'Prends le ' + num(firstOpen.id) + ' d\'abord : priorité très haute.' : 'Prends le ' + num(firstOpen.id) + ' : « ' + firstOpen.title + ' ».');
        app.appendChild(h('<div class="consigne-nadia"><b>NADIA</b><span>' + esc(adv) + '</span></div>'));
      }
      var f = $('.tickets button.ligne', app);
      if (f) f.focus();
    });
  }
  function reviewTicket(c) {
    var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true"><h2>' + num(c.id) + ' · ' + esc(c.title) + '</h2><p>Ticket clos' + (c.quality ? ' · ' + c.quality + ' étoile' + (c.quality > 1 ? 's' : '') + ' sur 3' : '') + '.</p><div class="actions"><button type="button" class="btn btn-magenta">FERMER</button></div></div></div>');
    $('.btn', w).addEventListener('click', function () { w.remove(); });
    app.appendChild(w); $('.btn', w).focus();
  }

  // ——— The map ———
  var KIND_FILL = { router: '#F4F2FF', box: '#2A2342', internet: '#2A2342' };
  function vlanColor(v) { return (W.vlans[v] && W.vlans[v].color) || '#6E6890'; }
  function mapSVG(M, trace, onPick) {
    var devs = M.devices, showGhost = G.sc && G.sc.clues.length >= 4;
    var svg = '<svg class="carte" viewBox="0 0 800 500" role="img" aria-label="Carte de la salle">';
    var seen = {};
    Object.keys(M.ends).forEach(function (a) {
      var b = M.ends[a], key = [a, b.dev + ':' + b.port].sort().join('|');
      if (seen[key]) return; seen[key] = true;
      var da = a.split(':'), A = devs[da[0]], B = devs[b.dev];
      if (!A || !B) return;
      if ((A.def.hidden && !showGhost) || (B.def.hidden && !showGhost)) return;
      var color = '#6E6890', width = 3, dash = '';
      [[A, da[1]], [B, b.port]].forEach(function (pair) {
        var d = pair[0];
        var pn = pair[1].split('#')[0];
        if (pair[1].indexOf('#') !== -1) dash = '4 5';
        if (d.kind === 'switch' && d.ports[pn]) {
          var p = d.ports[pn];
          if (p.vids.length) { color = '#C2BDD9'; width = 4; } else color = vlanColor(p.pvid);
        }
      });
      if (A.id === B.id) {
        var x = A.def.x, y = A.def.y + 16;
        svg += '<path d="M' + (x - 22) + ' ' + y + ' C' + (x - 40) + ' ' + (y + 46) + ' ' + (x + 40) + ' ' + (y + 46) + ' ' + (x + 22) + ' ' + y + '" fill="none" stroke="' + (M.storm ? '#FF3B3B' : color) + '" stroke-width="4"/>';
        return;
      }
      svg += '<path d="M' + A.def.x + ' ' + A.def.y + ' L' + B.def.x + ' ' + B.def.y + '" stroke="' + color + '" stroke-width="' + width + '"' + (dash ? ' stroke-dasharray="' + dash + '"' : '') + '/>';
    });
    if (M.wg['r1'] && devs['arbitre-distant']) svg += '<path d="M' + devs['arbitre-distant'].def.x + ' ' + devs['arbitre-distant'].def.y + ' C 560 70 470 120 ' + devs.r1.def.x + ' ' + devs.r1.def.y + '" fill="none" stroke="#00E5FF" stroke-width="2.5" stroke-dasharray="8 5"/>';
    if (devs['arbitre-distant'] && devs.internet) svg += '<path d="M' + devs['arbitre-distant'].def.x + ' ' + devs['arbitre-distant'].def.y + ' L' + devs.internet.def.x + ' ' + devs.internet.def.y + '" stroke="#6E6890" stroke-width="2" stroke-dasharray="3 6"/>';
    var box = devs.box, inet = devs.internet;
    if (box && inet) svg += '<path d="M' + box.def.x + ' ' + box.def.y + ' L' + inet.def.x + ' ' + inet.def.y + '" stroke="#6E6890" stroke-width="3" stroke-dasharray="6 6"/>';
    // trace
    if (trace && trace.there) {
      var pts = trace.there.hops.map(function (hp) { var d = devs[hp.dev]; return d ? d.def.x + ',' + d.def.y : null; }).filter(Boolean);
      if (trace.ok && trace.back) pts = pts.concat(trace.back.hops.slice(1).map(function (hp) { var d = devs[hp.dev]; return d ? (d.def.x + 6) + ',' + (d.def.y + 6) : null; }).filter(Boolean));
      if (pts.length > 1) svg += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + (trace.ok ? '#C6FF3D' : '#FF2E88') + '" stroke-width="3" stroke-dasharray="2 7" stroke-linecap="round"/>';
    }
    Object.keys(devs).forEach(function (id) {
      var d = devs[id];
      if (d.def.hidden && !showGhost) return;
      var x = d.def.x, y = d.def.y, w = id === 'internet' ? 96 : 92, hh = 28;
      var stroke = d.kind === 'switch' ? (M.storm ? '#FF3B3B' : '#C2BDD9') : (d.kind === 'ghost' ? '#FF2E88' : (d.kind === 'internet' || d.kind === 'box' ? '#6E6890' : null));
      if (!stroke) { var p = N.primary(M, id); var v = p ? N.domain(M, id, p.name).vlan : null; stroke = v ? vlanColor(v) : (p ? '#C2BDD9' : '#FF3B3B'); }
      var fill = KIND_FILL[d.kind] || '#1B1236', ink = d.kind === 'router' ? '#0A0618' : '#F4F2FF';
      var label = d.def.label.toUpperCase() + (d.def.count ? ' ×' + d.def.count : '');
      svg += '<g class="noeud" data-dev="' + id + '" tabindex="0" role="button" aria-label="' + esc(d.def.label) + '">' +
        '<polygon points="' + (x - w / 2 + 6) + ',' + (y - hh / 2) + ' ' + (x + w / 2) + ',' + (y - hh / 2) + ' ' + (x + w / 2 - 6) + ',' + (y + hh / 2) + ' ' + (x - w / 2) + ',' + (y + hh / 2) + '" fill="' + fill + '" stroke="' + stroke + '" stroke-width="2.5"/>' +
        '<text x="' + x + '" y="' + (y + 5) + '" text-anchor="middle" font-family="Chakra Petch, sans-serif" font-weight="700" font-size="12" fill="' + ink + '">' + esc(label) + '</text></g>';
    });
    if (trace && !trace.ok && trace.there && trace.there.died || (trace && trace.back && trace.back.died)) {
      var died = (trace.back && trace.back.died) || trace.there.died, dd = devs[died.dev];
      if (dd) {
        var X = dd.def.x + 52, Y = dd.def.y - 18;
        svg += '<g><circle cx="' + X + '" cy="' + Y + '" r="12" fill="#FF3B3B"/><path d="M' + (X - 5) + ' ' + (Y - 5) + ' L' + (X + 5) + ' ' + (Y + 5) + ' M' + (X + 5) + ' ' + (Y - 5) + ' L' + (X - 5) + ' ' + (Y + 5) + '" stroke="#fff" stroke-width="3" stroke-linecap="round"/></g>';
      }
    }
    if (M.storm) { var sw = devs[M.storm.at]; svg += '<polygon points="' + (sw.def.x - 60) + ',' + (sw.def.y - 50) + ' ' + (sw.def.x + 70) + ',' + (sw.def.y - 50) + ' ' + (sw.def.x + 64) + ',' + (sw.def.y - 30) + ' ' + (sw.def.x - 66) + ',' + (sw.def.y - 30) + '" fill="#FF3B3B"/><text x="' + (sw.def.x + 2) + '" y="' + (sw.def.y - 36) + '" text-anchor="middle" font-family="Chakra Petch, sans-serif" font-weight="700" font-size="12" fill="#fff">TEMPÊTE DE DIFFUSION</text>'; }
    svg += '</svg>';
    var el = h(svg);
    if (onPick) $$('.noeud', el).forEach(function (g) {
      var pick = function () { onPick(g.getAttribute('data-dev')); };
      g.addEventListener('click', pick);
      g.addEventListener('keydown', function (e) { if (e.key === 'Enter') pick(); });
    });
    return el;
  }
  function legend(M) {
    var used = {};
    Object.keys(M.devices).forEach(function (id) { var d = M.devices[id]; if (d.kind === 'switch') Object.keys(d.ports).forEach(function (p) { if (!d.ports[p].vids.length) used[d.ports[p].pvid] = true; }); });
    return h('<div class="legende">' + Object.keys(used).sort().map(function (v) { return '<span><i style="background:' + vlanColor(+v) + '"></i>' + v + ' ' + esc((W.vlans[v] || {}).label || '') + '</span>'; }).join('') + '<span><i style="background:#C2BDD9;height:5px"></i>trunk</span></div>');
  }
  function traceLine(trace) {
    if (!trace) return null;
    if (trace.ok) return h('<div class="raison ok">Aller et retour : ça passe.</div>');
    var died = (trace.back && trace.back.died) || (trace.there && trace.there.died);
    return h('<div class="raison">✗ ' + (died ? 'ÉLIMINÉ À ' + esc(String(died.dev).toUpperCase()) + ' · ' : '') + esc(trace.detail) + '</div>');
  }

  // ——— The console ———
  function consoleDevices(M) {
    return Object.keys(M.devices).filter(function (id) { var d = M.devices[id]; return !d.def.hidden && ['internet', 'box'].indexOf(d.kind) === -1; });
  }
  var consoleSeq = 0;
  function consolePanel(getWorld, onTrace) {
    var M = getWorld();
    var devs = consoleDevices(M);
    if (devs.indexOf(G.consoleDev) === -1) G.consoleDev = devs[0];
    // Two consoles can be on screen (the workstation's and the enlarged map's): each its own input id.
    var cmdId = 'cmd' + (++consoleSeq);
    var panel = h('<section class="panneau outils" aria-label="Console"><div class="tabs" role="tablist"><button type="button" role="tab" aria-selected="true">CONSOLE</button><select aria-label="Machine"></select></div>' +
      '<div class="console"><div class="sortie" aria-live="polite"></div><div class="entree"><label for="' + cmdId + '"></label><input id="' + cmdId + '" autocomplete="off" spellcheck="false" placeholder="ping srv-jeu, ip a, ip r, dhclient -v ens18, dig bracket… (aide)"></div></div></section>');
    var sel = $('select', panel);
    devs.forEach(function (id) { var o = document.createElement('option'); o.value = id; o.textContent = id; if (id === G.consoleDev) o.selected = true; sel.appendChild(o); });
    sel.addEventListener('change', function () { G.consoleDev = sel.value; $('label', panel).textContent = 'root@' + G.consoleDev + ':~#'; });
    $('label', panel).textContent = 'root@' + G.consoleDev + ':~#';
    var out = $('.sortie', panel);
    function paint() {
      out.innerHTML = G.consoleLog.map(function (l) { return '<div class="' + (l.p ? 'p' : (/^✗/.test(l.t) ? 'ko' : '')) + '">' + esc(l.t) + '</div>'; }).join('') || '<div style="color:var(--muted)">Tape « aide » pour la liste des commandes. Clique une machine sur la carte pour t\'y connecter.</div>';
      out.scrollTop = out.scrollHeight;
    }
    paint();
    var input = $('input', panel);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp') { if (G.history.length) { G.hist = Math.max(0, (G.hist < 0 ? G.history.length : G.hist) - 1); input.value = G.history[G.hist]; } e.preventDefault(); return; }
      if (e.key === 'ArrowDown') { if (G.hist >= 0) { G.hist = Math.min(G.history.length, G.hist + 1); input.value = G.history[G.hist] || ''; } e.preventDefault(); return; }
      if (e.key !== 'Enter') return;
      var cmd = input.value.trim(); input.value = ''; G.hist = -1;
      if (!cmd) return;
      G.history.push(cmd);
      if (cmd === 'clear') { G.consoleLog = []; paint(); return; }
      var world = getWorld();
      world.lastTrace = null;
      G.consoleLog.push({ p: true, t: 'root@' + G.consoleDev + ':~# ' + cmd });
      N.run(world, G.consoleDev, cmd).forEach(function (l) { G.consoleLog.push({ t: l }); });
      if (G.consoleLog.length > 300) G.consoleLog = G.consoleLog.slice(-300);
      SND.type();
      paint();
      if (world.lastTrace && onTrace) onTrace(world.lastTrace);
    });
    panel.focusInput = function () { input.focus(); };
    return panel;
  }

  // ——— Map page and inventory ———
  function showMapPage() {
    refreshScenario().then(function () {
      var M = N.world(G.sc.act);
      app.innerHTML = '';
      app.appendChild(bar('carte'));
      var page = h('<div class="page-carte"><section class="panneau carte-panneau" style="height:auto"><div class="tete"><b>CARTE DE LA SALLE</b><span class="note">La salle telle qu\'elle doit être, acte ' + G.sc.act + '</span></div></section><div></div></div>');
      var cp = $('.carte-panneau', page);
      var draw = function (trace) {
        $$('.carte, .legende, .raison', cp).forEach(function (x) { x.remove(); });
        cp.appendChild(mapSVG(M, trace, function (dev) { G.consoleDev = dev; showMapPageConsole(); }));
        cp.appendChild(legend(M));
        var r = traceLine(trace); if (r) cp.appendChild(r);
      };
      var slot = page.lastElementChild;
      function showMapPageConsole() { slot.innerHTML = ''; slot.appendChild(consolePanel(function () { return M; }, draw)); slot.firstChild.style.height = '100%'; }
      draw(null);
      showMapPageConsole();
      app.appendChild(page);
    });
  }
  function showInventory() {
    refreshScenario().then(function (sc) {
      app.innerHTML = '';
      app.appendChild(bar('inventaire'));
      var v = h('<section class="inventaire"><h1>Inventaire · l\'enquête</h1><p class="note">Ce que la nuit a appris sur le Ghost. Une adresse MAC se change en une commande : on note, on ne conclut pas.</p><div class="indices-liste"></div></section>');
      var list = $('.indices-liste', v);
      for (var i = 0; i < 5; i++) {
        var c = sc.clues[i];
        list.appendChild(h('<div class="indice' + (c ? '' : ' vide') + '"><b>INDICE ' + (i + 1) + '</b><p>' + (c ? esc(c) : '· · ·') + '</p></div>'));
      }
      if (sc.clues.length >= 2) {
        var f = [['Adresse IP', '10.20.20.66', 'hors de la plage DHCP, posée à la main', 2]];
        f.push(['Activité', 'balayage de la DMZ à 23:47', '1 024 ports en 40 s', 3]);
        f.push(['Adresse MAC', 'b8:27:eb:4c:11:9e', 'constructeur : Raspberry Pi', 4]);
        f.push(['Nom annoncé', 'GHOST-PC', 'depuis 21:52, table B14 (sw-rang-b, ens32)', 4]);
        f.push(['Liaison sortante', '198.51.100.23, UDP 51820', 'un tunnel WireGuard vers l\'extérieur', 5]);
        var sheet = h('<section style="margin-top:22px;padding:20px 24px;background:var(--panneau);border:1px solid var(--filet);max-width:900px"><div style="display:flex;align-items:center;gap:14px"><b style="font-family:var(--mono);font-size:28px">' + (sc.clues.length >= 4 ? 'GHOST-PC' : '10.20.20.66') + '</b><span class="chip" style="background:var(--rouge);color:#fff"><span>À SURVEILLER</span></span></div><dl style="display:grid;grid-template-columns:200px 1fr;gap:10px 18px;margin:16px 0 0;font-size:15px"></dl></section>');
        var dl = $('dl', sheet);
        f.forEach(function (x) {
          if (sc.clues.length < x[3]) return;
          dl.appendChild(h('<dt style="color:var(--texte-3)">' + esc(x[0]) + '</dt>'));
          dl.appendChild(h('<dd style="margin:0"><span style="font-family:var(--mono)">' + esc(x[1]) + '</span> <span style="color:var(--magenta-2);font-size:13px">' + esc(x[2]) + '</span></dd>'));
        });
        v.appendChild(sheet);
      }
      app.appendChild(v);
    });
  }

  // ——— The ticket ———
  function openTicket(id) {
    api('startTicket', { ticket: id }).then(function (r) {
      G.session = r.session; G.ticket = r.ticket; G.step = r.step;
      G.guide = r.guide || []; G.guideStep = r.guideStep || 0; G.hints = r.hints || 0; G.errors = 0;
      G.def = TK.get(id);
      if (!G.def) { notice('Ticket introuvable dans le jeu : ' + id); return; }
      G.files = N.ticketFiles(G.def);
      var draft = G.data.drafts[id];
      if (draft) Object.keys(draft).forEach(function (f) { if (G.def.edit.indexOf(f) !== -1) G.files[f] = draft[f]; });
      G.results = null; G.trace = null; G.world = null; G.consoleLog = []; G.refused = {};
      G.consoleDev = G.def.console || null;
      G.tab = G.def.edit[0] || G.def.show[0] || null;
      var intro = !G.data.seen[id] && G.def.intro && G.def.intro.length ? G.def.intro : null;
      (intro ? playScene(intro, { tag: num(id), sub: G.ticket.title.toUpperCase() }) : Promise.resolve()).then(function () {
        G.data.seen[id] = true; save();
        renderPoste();
        // Every step answered, the closing interrupted (the tab closed during the requester's
        // last line): the ticket closes now rather than stay stuck, all ticked.
        if (G.step >= G.ticket.steps.length) { finishTicket(); return; }
        var st = currentStep();
        if (st && (st.kind === 'explain' || st.kind === 'choice')) say(G.ticket.requester, st.expr || 'neutre', st.kind === 'explain' ? st.prompt : G.ticket.request);
        else say(G.ticket.requester, 'neutre', G.ticket.request);
      });
    }).catch(function (e) { notice('Impossible d\'ouvrir le ticket : ' + e.message); });
  }
  function currentStep() { return G.ticket.steps[G.step]; }
  function rungs() { return G.guide.length + G.def.hints.length; }
  function guideOpen() { return G.guideStep < G.guide.length && G.hints > G.guideStep ? G.guide[G.guideStep] : null; }
  function world() { if (!G.world) G.world = N.ticketWorld(G.def, G.files); return G.world; }

  function renderPoste() {
    app.innerHTML = '';
    app.appendChild(bar('ticket'));
    var st = currentStep();
    var poste = h('<main class="poste"></main>');
    poste.appendChild(h('<section class="panneau gauche" aria-label="Ticket"></section>'));
    if (st && (st.kind === 'explain' || st.kind === 'choice' || st.kind === 'order')) {
      poste.appendChild(h('<section class="solution" aria-label="' + (st.kind === 'explain' ? 'La solution' : 'Décider') + '"></section>'));
    } else {
      poste.appendChild(h('<section class="panneau editeur" aria-label="Configuration"></section>'));
      poste.appendChild(h('<div class="droite-col"></div>'));
    }
    poste.appendChild(h('<div class="dialogue" aria-live="polite"><div class="av"></div><span class="qui"></span><p class="dit"></p></div>'));
    app.appendChild(poste);
    renderLeft();
    if ($('.solution')) renderSolution(); else { renderEditor(); renderRight(); }
  }

  function say(who, expr, text) {
    var d = $('.dialogue');
    if (!d) return;
    $('.av', d).innerHTML = CH.svg(who, expr || 'neutre', 'tete');
    $('.qui', d).textContent = nameOf(who).toUpperCase();
    $('.qui', d).style.background = who === 'nadia' ? 'var(--cyan)' : 'var(--magenta)';
    $('.dit', d).textContent = text;
  }

  var STEP_LABEL = { fix: 'Réparer', explain: 'Solution', choice: 'Décider', line: 'Désigner', order: 'Ordonner' };
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
    var back = h('<button type="button" class="btn-fantome" style="font-family:var(--titre);font-weight:700;font-size:13px;letter-spacing:.1em">← HELPDESK</button>');
    back.addEventListener('click', function () { save(true); showHelpdesk(); });
    var head = h('<div class="tete-ticket"><span class="k">' + num(t.id) + ' · ' + (t.kind === 'demande' ? 'DEMANDE' : 'INCIDENT') + '</span></div>');
    head.appendChild(back);
    p.appendChild(head);
    p.appendChild(h('<h1>' + esc(t.title) + '</h1>'));
    p.appendChild(h('<div class="chips"><span class="chip chip-statut"><span>EN COURS</span></span><span class="chip prio-' + t.priority + '"><span>' + (PRIO[t.priority] || '').toUpperCase() + '</span></span>' + (t.category ? '<span class="chip chip-gris">' + esc(t.category) + '</span>' : '') + '</div>'));
    p.appendChild(h('<div class="demande"><span class="av">' + CH.svg(t.requester, 'neutre', 'tete') + '</span><div><div class="de"><b>' + esc(nameOf(t.requester)) + '</b> · demandeur</div><p>' + esc(t.request) + '</p></div></div>'));
    var ol = h('<ol class="etapes" aria-label="Étapes"></ol>');
    t.steps.forEach(function (s, i) { ol.appendChild(h('<li class="' + (i < G.step ? 'fait' : (i === G.step ? 'actif' : '')) + '"' + (i === G.step ? ' aria-current="step"' : '') + '>' + (i < G.step ? '✓ ' : '') + esc(s.label || STEP_LABEL[s.kind]) + '</li>')); });
    p.appendChild(ol);
    if (!st) return;
    if (st.kind === 'fix') {
      p.appendChild(h('<p class="consigne">' + esc(st.prompt) + '</p>'));
      p.appendChild(h('<p class="note">Lis le ticket, teste depuis la console, regarde la carte, puis corrige les fichiers au centre. Modifiables : ' + G.def.edit.map(function (f) { return esc(f.replace(':', ' · ')); }).join(', ') + '.</p>'));
      p.appendChild(criteriaEl());
    } else if (st.kind === 'line') {
      p.appendChild(h('<p class="consigne">' + esc(st.prompt) + '</p>'));
      p.appendChild(h('<p class="note">Clique directement sur la ligne, au centre.</p>'));
    } else {
      p.appendChild(h('<p class="consigne">' + (st.kind === 'explain' ? 'La réparation est acceptée. ' + esc(nameOf(t.requester)) + ' veut comprendre : rédige la solution, à droite.' : esc(st.kind === 'order' ? 'Remets les éléments dans l\'ordre, à droite.' : 'Décide, à droite.')) + '</p>'));
    }
    var foot = h('<div class="pied-gauche"></div>');
    foot.appendChild(h('<div class="ligne-jauge"><span>Étoiles en jeu</span>' + jauge(stars(G.errors, G.hints)) + '</div>'));
    if (st.kind === 'fix') {
      var reset = h('<button type="button" class="btn btn-fantome" style="min-height:34px;font-size:12px;justify-content:flex-start;padding:0">Remettre la configuration de départ</button>');
      reset.addEventListener('click', function () {
        var orig = N.ticketFiles(G.def);
        G.def.edit.forEach(function (f) { G.files[f] = orig[f]; });
        delete G.data.drafts[G.ticket.id]; save();
        G.world = null; G.results = null; renderEditor(); renderRight(); renderLeft();
      });
      foot.appendChild(reset);
      var deliverBtn = h('<button type="button" class="btn btn-magenta">LIVRER LA CORRECTION À ' + esc(nameOf(t.requester).toUpperCase()) + '</button>');
      deliverBtn.addEventListener('click', deliver);
      foot.appendChild(deliverBtn);
    }
    if ((st.kind === 'fix' || st.kind === 'line') && rungs()) foot.appendChild(helpZone());
    p.appendChild(foot);
  }

  function criteriaEl() {
    var box = h('<div class="criteres"><div class="k">Ce que ' + esc(nameOf(G.ticket.requester)) + ' vérifiera' + (G.results ? ' · dernière livraison' : '') + '</div></div>');
    G.def.checks.forEach(function (c, i) {
      var r = G.results && G.results[i];
      box.appendChild(h('<div class="c ' + (r ? (r.ok ? 'ok' : 'ko') : '') + '"><i aria-hidden="true">' + (r ? (r.ok ? '✓' : '✗') : '·') + '</i><span>' + esc(c.label) + (r && !r.ok && r.detail ? '<span class="det">' + esc(r.detail) + '</span>' : '') + '</span></div>'));
    });
    return box;
  }

  function helpZone() {
    var z = h('<div class="aide"></div>'), guide = guideOpen(), total = rungs();
    if (guide) {
      z.appendChild(h('<div class="de">NADIA · SUIVI PRIVÉ</div>'));
      z.appendChild(h('<p>' + esc(guide.prompt) + '</p>'));
      if (guide.kind === 'choice') z.appendChild(choiceButtons(guide.options, function (i) { return submitGuide(guide, i); }));
      else z.appendChild(h('<p class="cours">Clique la ligne directement dans le fichier, au centre.</p>'));
      return z;
    }
    var k = G.hints - G.guide.length - 1;
    if (k >= 0) {
      var art = G.def.hints[Math.min(k, G.def.hints.length - 1)];
      z.appendChild(h('<div class="de">BASE DE CONNAISSANCES · ' + esc(art.title.toUpperCase()) + '</div>'));
      z.appendChild(h('<p>' + esc(art.body) + '</p>'));
      if (art.course) z.appendChild(h('<p class="cours">Revoir le cours : ' + esc(art.course.label) + '</p>'));
    }
    if (G.hints < total) {
      var b = h('<button type="button" class="btn btn-cyan" style="min-height:40px;font-size:13px">' + (G.hints === 0 ? 'DEMANDER À NADIA' : 'ENCORE UN COUP DE MAIN') + ' (' + (G.hints + 1) + '/' + total + ')</button>');
      b.addEventListener('click', function () {
        b.disabled = true;
        api('useHint', { session: G.session }).then(function (r) {
          G.hints = r.hints;
          var g = guideOpen();
          if (g) { say('nadia', 'neutre', g.prompt); if (g.kind === 'line' && g.file) G.tab = g.file; }
          else { var kk = G.hints - G.guide.length - 1; say('nadia', kk === G.def.hints.length - 1 ? 'sourire' : 'neutre', 'Lis l\'article « ' + G.def.hints[kk].title + ' ».'); }
          renderLeft(); if ($('.editeur')) renderEditor();
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
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
        G.step = res.next;
        if (res.done) { var said = res.reaction ? res.reaction.text : ''; wait(said ? Math.min(5000, 1200 + said.length * 30) : 400).then(finishTicket); return res; }
        var nst = currentStep();
        renderPoste();
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
        else if (nst && nst.kind === 'explain') say(G.ticket.requester, nst.expr || 'neutre', nst.prompt);
      } else {
        SND.wrong();
        G.errors++;
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
        renderLeft();
      }
      return res;
    }).catch(function (e) { say('nadia', 'sourcil', 'Erreur de la plateforme : ' + e.message); return { ok: false }; });
  }
  function submitGuide(st, input) {
    return api('submitGuide', { session: G.session, step: st.id, input: input }).then(function (res) {
      if (res.ok) {
        SND.right();
        say(res.reaction ? res.reaction.who : 'nadia', res.reaction ? res.reaction.expr : 'neutre', res.reaction ? res.reaction.text : ['Exact.', 'C\'est ça.', 'Bien vu. À toi, maintenant.'][Math.floor(Math.random() * 3)]);
        G.guideStep = res.next;
        renderLeft(); if ($('.editeur')) renderEditor();
      } else {
        SND.wrong();
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
      }
      return res;
    }).catch(function (e) { say('nadia', 'sourcil', 'Erreur de la plateforme : ' + e.message); return { ok: false }; });
  }

  // ——— Editor ———
  function highlight(src) {
    return src.split('\n').map(function (line) {
      var m = /^(\s*)([#;].*|\/\/.*)$/.exec(line);
      if (m) return m[1] + '<span class="hl-c">' + esc(m[2]) + '</span>';
      var out = esc(line);
      out = out.replace(/(&quot;[^&]*?&quot;)/g, '<span class="hl-s">$1</span>');
      out = out.replace(/^(\s*)([A-Za-z$][\w.-]*)/, '$1<span class="hl-k">$2</span>');
      out = out.replace(/(\s)(\d{1,3}(?:\.\d{1,3}){3}(?:\/\d{1,2})?)/g, '$1<span class="hl-n">$2</span>');
      return out;
    }).join('\n') + '\n';
  }
  function tabsList() {
    var list = [];
    function add(f) { if (f && list.indexOf(f) === -1 && G.files[f] !== undefined) list.push(f); }
    G.def.edit.forEach(add); G.def.show.forEach(add);
    G.guide.forEach(function (s) { add(s.file); });
    return list;
  }
  function renderEditor() {
    var p = $('.editeur'), st = currentStep();
    p.innerHTML = '';
    if (st && st.kind === 'line' && st.view) {
      var view = G.def.views[st.view] || { title: '?', lines: [] };
      p.appendChild(h('<div class="onglets"><button type="button" aria-selected="true">' + esc(view.title) + '</button><span class="etat">clique sur une ligne</span></div>'));
      p.appendChild(h('<div class="consigne-ligne">' + esc(st.prompt) + '</div>'));
      var lv = h('<div class="lignes" role="list"></div>');
      view.lines.forEach(function (l, i) {
        var b = h('<button type="button" role="listitem"><span class="n">' + (i + 1) + '</span><span>' + (esc(l) || ' ') + '</span></button>');
        b.addEventListener('click', function () { submit(st, l).then(function (res) { b.classList.add(res.ok ? 'juste' : 'faux'); }); });
        lv.appendChild(b);
      });
      p.appendChild(lv);
      return;
    }
    var tabs = tabsList();
    var guide = guideOpen();
    var lineMode = !!(guide && guide.kind === 'line');
    if (lineMode && guide.file) G.tab = guide.file;
    if (tabs.indexOf(G.tab) === -1) G.tab = tabs[0];
    if (!G.tab) { p.appendChild(h('<p class="note" style="padding:20px">Aucun fichier pour ce ticket.</p>')); return; }
    var editable = !lineMode && st && st.kind === 'fix' && G.def.edit.indexOf(G.tab) !== -1;
    var bar2 = h('<div class="onglets" role="tablist" aria-label="Fichiers"></div>');
    tabs.forEach(function (f) {
      var ro = G.def.edit.indexOf(f) === -1;
      var b = h('<button type="button" role="tab" class="' + (ro ? 'lecture' : '') + '" aria-selected="' + (f === G.tab) + '">' + esc(f.replace(':', ' · ')) + (ro ? '' : ' <span style="color:var(--magenta)">✎</span>') + '</button>');
      b.addEventListener('click', function () { G.tab = f; renderEditor(); });
      bar2.appendChild(b);
    });
    bar2.appendChild(h('<span class="etat">' + (editable ? 'modifiable' : (lineMode ? 'clique sur une ligne' : 'lecture seule')) + '</span>'));
    p.appendChild(bar2);
    var src = G.files[G.tab] || '';
    if (lineMode) {
      p.appendChild(h('<div class="consigne-ligne">' + esc(guide.prompt) + '</div>'));
      var lines = h('<div class="lignes" role="list"></div>');
      src.split('\n').forEach(function (l, i) {
        var b = h('<button type="button" role="listitem"><span class="n">' + (i + 1) + '</span><span>' + (esc(l) || ' ') + '</span></button>');
        b.addEventListener('click', function () { submitGuide(guide, l).then(function (res) { b.classList.add(res.ok ? 'juste' : 'faux'); }); });
        lines.appendChild(b);
      });
      p.appendChild(lines);
      return;
    }
    var zone = h('<div class="code' + (editable ? '' : ' lecture') + '"></div>');
    var numEl = h('<div class="numeros" aria-hidden="true"></div>');
    var count = function (s) { var n = s.split('\n').length, o = ''; for (var i = 1; i <= n; i++) o += i + '\n'; return o; };
    numEl.textContent = count(src);
    var pre = h('<pre aria-hidden="' + (editable ? 'true' : 'false') + '"></pre>'); pre.innerHTML = highlight(src);
    zone.appendChild(numEl); zone.appendChild(pre);
    if (editable) {
      var ta = h('<textarea spellcheck="false" autocapitalize="off" autocomplete="off" aria-label="Fichier ' + esc(G.tab) + '"></textarea>');
      ta.value = src;
      var file = G.tab, timer = null;
      ta.addEventListener('input', function () {
        G.files[file] = ta.value;
        pre.innerHTML = highlight(ta.value);
        numEl.textContent = count(ta.value);
        G.data.drafts[G.ticket.id] = G.data.drafts[G.ticket.id] || {};
        G.data.drafts[G.ticket.id][file] = ta.value; save();
        G.world = null;
        clearTimeout(timer); timer = setTimeout(function () { G.trace = null; renderMap(); }, 500);
        if (G.results) { G.results = null; renderLeft(); }
      });
      ta.addEventListener('scroll', function () { pre.scrollTop = ta.scrollTop; pre.scrollLeft = ta.scrollLeft; numEl.scrollTop = ta.scrollTop; });
      ta.addEventListener('keydown', function (e) {
        if (e.key === 'Tab' && !e.shiftKey) {
          e.preventDefault();
          var s0 = ta.selectionStart, s1 = ta.selectionEnd;
          ta.value = ta.value.slice(0, s0) + '    ' + ta.value.slice(s1);
          ta.selectionStart = ta.selectionEnd = s0 + 4;
          ta.dispatchEvent(new Event('input'));
        }
      });
      zone.appendChild(ta);
    } else pre.addEventListener('scroll', function () { numEl.scrollTop = pre.scrollTop; });
    p.appendChild(zone);
  }

  // ——— Right column: map and console ———
  function renderRight() {
    var col = $('.droite-col');
    if (!col) return;
    col.innerHTML = '';
    var cp = h('<section class="panneau carte-panneau" aria-label="Carte de la salle"><div class="tete"><b>CARTE DE LA SALLE</b><span class="note">Clique une machine</span><button type="button" class="agrandir" aria-haspopup="dialog" title="Agrandir la carte, avec sa console">⤢ AGRANDIR</button></div></section>');
    $('.agrandir', cp).addEventListener('click', function () { SND.click(); openBigMap(); });
    col.appendChild(cp);
    renderMap();
    col.appendChild(consolePanel(world, function (trace) { G.trace = trace; renderMap(); }));
  }
  // The map of a panel, and what clicking a machine does there: the console beside it (`scope`)
  // connects to that machine. Without arguments, the workstation's own map and console.
  function renderMap(cp, scope) {
    scope = scope || $('.droite-col');
    cp = cp || (scope && $('.carte-panneau', scope));
    if (!cp) return;
    $$('.carte, .legende, .raison', cp).forEach(function (x) { x.remove(); });
    var M = world();
    cp.appendChild(mapSVG(M, G.trace, function (dev) {
      G.consoleDev = dev;
      var sel = $('.outils select', scope); if (sel) { sel.value = dev; sel.dispatchEvent(new Event('change')); }
      var inp = $('.outils input', scope); if (inp) inp.focus();
    }));
    if (M.errors.length) {
      var e = M.errors[0];
      cp.appendChild(h('<div class="raison">✗ ' + esc(e.file.replace(':', ' · ')) + (e.line ? ', ligne ' + e.line : '') + ' : ' + esc(e.message) + '</div>'));
    } else {
      cp.appendChild(legend(M));
      var r = traceLine(G.trace); if (r) cp.appendChild(r);
    }
  }
  /* The map of the room, enlarged over the workstation with its own console beside it: every machine
     still connects the console when clicked, every ping and traceroute still draws its path and says
     where it died. The console's history is the workstation's (G.consoleLog), so closing loses
     nothing; Escape or « Réduire » closes. */
  function openBigMap() {
    var v = h('<div class="voile carte-grande" role="dialog" aria-modal="true" aria-label="Carte de la salle, agrandie"><div class="grande">' +
      '<section class="panneau carte-panneau"><div class="tete"><b>CARTE DE LA SALLE</b><span class="note">Clique une machine pour t\'y connecter</span><button type="button" class="agrandir reduire">✕ RÉDUIRE</button></div></section>' +
      '<div class="grande-console"></div></div></div>');
    app.appendChild(v);
    var cp = $('.carte-panneau', v);
    var draw = function () { renderMap(cp, v); };
    draw();
    var cons = consolePanel(world, function (trace) { G.trace = trace; draw(); });
    $('.grande-console', v).appendChild(cons);
    function close() { document.removeEventListener('keydown', key); v.remove(); renderRight(); var b = $('.droite-col .agrandir'); if (b) b.focus(); }
    function key(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
    $('.reduire', v).addEventListener('click', close);
    document.addEventListener('keydown', key);
    cons.focusInput();
  }

  // ——— Solution, decision, order ———
  function renderSolution() {
    var p = $('.solution'), st = currentStep(), t = G.ticket;
    p.innerHTML = '';
    var left = h('<div></div>');
    if (st.kind === 'explain') {
      left.appendChild(h('<div class="k" style="color:var(--cyan)">Résoudre le ticket · la solution</div>'));
      left.appendChild(h('<h2>La solution part chez ' + esc(nameOf(t.requester)) + ', qui l\'approuvera ou la refusera. Qu\'écris-tu dans le ticket ?</h2>'));
    } else if (st.kind === 'order') {
      left.appendChild(h('<div class="k" style="color:var(--cyan)">Remettre dans l\'ordre</div>'));
      left.appendChild(h('<h2>' + esc(st.prompt) + '</h2>'));
    } else {
      left.appendChild(h('<div class="k" style="color:var(--cyan)">Décider · ' + esc(st.label || '') + '</div>'));
      left.appendChild(h('<h2>' + esc(st.prompt) + '</h2>'));
    }
    if (st.kind === 'order') left.appendChild(orderEl(st));
    else {
      var opts = h('<div class="opts" role="group"></div>');
      st.options.forEach(function (o, i) {
        var key = st.id + ':' + i;
        var refused = G.refused[key];
        var b = h('<button type="button" class="' + (refused ? 'refusee' : '') + '"><b>' + 'ABCDEFG'[i] + '</b><span>' + esc(o) + '</span>' + (refused ? '<span class="tampon-r">' + (st.kind === 'explain' ? 'REFUSÉE PAR ' + esc(nameOf(t.requester).toUpperCase()) : 'NON') + '</span>' : '') + '</button>');
        b.addEventListener('click', function () {
          if (G.refused[key]) return;
          submit(st, i).then(function (res) { if (!res.ok) { G.refused[key] = true; if (currentStep() === st) renderSolution(); } });
        });
        opts.appendChild(b);
      });
      left.appendChild(opts);
    }
    p.appendChild(left);
    var who = t.requester;
    var right = h('<div><div class="portrait" aria-hidden="true">' + CH.svg(who, st.expr || 'neutre') + '<div class="plaque"><span style="font-style:italic;font-size:22px">' + esc(nameOf(who).toUpperCase()) + '</span><small>' + esc(CH.role(who).toUpperCase()) + '</small></div></div></div>');
    if (st.kind === 'explain') right.appendChild(h('<div class="question"><b>' + esc(nameOf(who)) + ' :</b> « ' + esc(st.prompt) + ' »</div>'));
    p.appendChild(right);
  }
  function orderEl(st) {
    G.order = G.order && G.order.length === st.items.length ? G.order : st.items.map(function (x, i) { return i; });
    var box = h('<div class="ordre"></div>');
    G.order.forEach(function (idx, pos) {
      var it = h('<div class="it"><b>' + (pos + 1) + '</b><span>' + esc(st.items[idx]) + '</span><span class="fl"><button type="button" aria-label="Monter">▲</button><button type="button" aria-label="Descendre">▼</button></span></div>');
      var bs = $$('button', it);
      bs[0].addEventListener('click', function () { if (pos > 0) { var t = G.order[pos - 1]; G.order[pos - 1] = idx; G.order[pos] = t; renderSolution(); } });
      bs[1].addEventListener('click', function () { if (pos < G.order.length - 1) { var t = G.order[pos + 1]; G.order[pos + 1] = idx; G.order[pos] = t; renderSolution(); } });
      box.appendChild(it);
    });
    var go = h('<button type="button" class="btn btn-magenta" style="align-self:flex-start;margin-top:8px">VALIDER L\'ORDRE</button>');
    go.addEventListener('click', function () { submit(st, G.order.slice()); });
    box.appendChild(go);
    return box;
  }

  // ——— Delivery and end of a ticket ———
  function deliver() {
    var st = currentStep();
    var M = N.ticketWorld(G.def, G.files);
    G.results = N.check(M, G.def.checks);
    var failed = G.results.filter(function (r) { return !r.ok; }).map(function (r) { return r.label; });
    SND.deliver();
    renderLeft();
    submit(st, { passed: failed.length === 0, failed: failed }).then(function (res) {
      if (!res.ok) {
        var first = G.results.filter(function (r) { return !r.ok; })[0];
        say(G.ticket.requester, 'inquiet', 'Ça ne va pas encore : ' + first.label.charAt(0).toLowerCase() + first.label.slice(1) + '. ' + (first.detail ? '(' + first.detail + ')' : ''));
        if (G.errors === 2 && !G.hints && rungs()) wait(4500).then(function () { say('nadia', 'neutre', 'Si tu tournes en rond, demande-moi : je te poserai les bonnes questions.'); });
      }
    });
  }
  function finishTicket() {
    api('endTicket', { session: G.session, hints: G.hints }).then(function (res) {
      delete G.data.drafts[G.ticket.id]; save(true);
      SND.stamp();
      var stamp = h('<div class="tampon"><span>' + (res.status === 'reassigned' ? 'RÉAFFECTÉ' : 'RÉSOLU') + '</span></div>');
      app.appendChild(stamp);
      return wait(1300).then(function () {
        stamp.remove();
        return G.def.done && G.def.done.length ? playScene(G.def.done, { tag: num(G.ticket.id), sub: 'CLOS · ' + res.quality + ' ÉTOILE' + (res.quality > 1 ? 'S' : '') }) : null;
      }).then(function () {
        if (res.clue) return clueWindow(res);
      }).then(rewardsScene).then(function () {
        if (res.finished) { showEnd(); return; }
        showHelpdesk((res.newCards || []).map(function (c) { return c.id; }));
      });
    }).catch(function (e) { say('nadia', 'sourcil', 'Erreur de la plateforme : ' + e.message); });
  }
  function clueWindow(res) {
    return new Promise(function (resolve) {
      SND.clue();
      var w = h('<div class="voile"><div class="fenetre" role="dialog" aria-modal="true"><div class="k" style="color:var(--magenta)">Inventaire · indice ' + res.clueIndex + ' sur 5</div><h2>Une trace du Ghost</h2><p>' + esc(res.clue) + '</p><div class="actions"><button type="button" class="btn btn-magenta">NOTÉ DANS L\'INVENTAIRE</button></div></div></div>');
      $('.btn', w).addEventListener('click', function () { w.remove(); resolve(); });
      app.appendChild(w); $('.btn', w).focus();
    });
  }
  // The collection pieces the platform granted at the closing of a ticket (SGSdk.onReward): a card
  // per act done, the Testeur de câble and its Ghost pin once the final is closed, its finish
  // raised by the tickets with three stars. Drawn by the platform's shared module.
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
      var w = h('<div class="voile"><div class="fenetre recompenses" role="dialog" aria-modal="true" aria-labelledby="recompensesTitre"><div class="k" style="color:var(--citron)">Collection</div><h2 id="recompensesTitre">' + (list.length > 1 ? 'Nouvelles pièces de collection' : 'Nouvelle pièce de collection') + '</h2><ul class="pieces">' + items + '</ul><p>Elles rejoignent ta vitrine, sur ta page joueur.</p><div class="actions"><button type="button" class="btn btn-magenta">SUPER !</button></div></div></div>');
      $('.btn', w).addEventListener('click', function () { w.remove(); resolve(); });
      app.appendChild(w); $('.btn', w).focus();
    });
  }
  function showEnd(skipEpilogue) {
    var first = !G.data.epilogue && !skipEpilogue;
    (first ? playScene(STORY.epilogue, { tag: 'ÉPILOGUE', sub: 'LUNDI · 8:10' }).then(function () { G.data.epilogue = true; save(true); }) : Promise.resolve()).then(refreshScenario).then(function (sc) {
      app.innerHTML = '';
      var v = h('<section class="titre"></section>');
      var done = sc.cards.filter(function (c) { return c.status === 'done' || c.status === 'reassigned'; });
      var st = done.reduce(function (s, c) { return s + (c.quality || 0); }, 0);
      var three = done.filter(function (c) { return c.quality === 3; }).length;
      var finish = three >= 32 ? ['OR', '#FFE58A'] : (three >= 24 ? ['ARGENT', '#E9ECF2'] : ['LAITON', '#E8C47A']);
      v.innerHTML = bandsSVG(true) +
        '<div class="bloc"><div class="direct"><span class="live">● EN DIRECT</span><span class="perdu">FINALE · 6:00</span></div><div class="sur">ATTESTATION DE STAGE · LA NUIT DU TOURNOI</div><h1 class="split">SIGNAL RÉTABLI</h1>' +
        '<p class="pitch">' + done.length + ' tickets clos, ' + st + ' étoiles sur ' + (done.length * 3) + ', dont ' + three + ' tickets sans erreur ni aide. Grade : <b>' + esc(gradeOf(done.length)) + '</b>.</p>' +
        '<p class="pitch">Trophée : <b style="color:' + finish[1] + '">le Testeur de câble, ' + finish[0].toLowerCase() + '</b> et son épinglette Ghost, dans ta vitrine.</p>' +
        '<p class="pitch" style="font-size:14px;color:var(--texte-3)">Signé : Nadia, admin réseau du prestataire · Mme Roussel, proviseure adjointe · Karim, président du club e-sport.</p><nav aria-label="Fin"></nav></div>' +
        '<div class="carte-perso" aria-hidden="true">' + CH.svg('nadia', 'sourire') + '<div class="plaque"><span style="font-style:italic;font-size:24px">NADIA</span><span style="font-size:12px;letter-spacing:.14em;color:#5A4F80">TUTRICE · ADMIN RÉSEAU</span></div></div>';
      var nav = $('nav', v);
      var again = h('<button type="button" class="btn btn-magenta">REVOIR L\'ÉPILOGUE<small>Lundi, 8 h 10</small></button>');
      again.addEventListener('click', function () { playScene(STORY.epilogue, { tag: 'ÉPILOGUE', sub: 'LUNDI · 8:10' }); });
      var inv = h('<button type="button" class="btn btn-cyan">L\'INVENTAIRE DE L\'ENQUÊTE<small>Les cinq indices</small></button>');
      inv.addEventListener('click', showInventory);
      var fresh = h('<button type="button" class="btn btn-fantome">NOUVELLE PARTIE<small>Les tickets repartent de zéro ; ta collection et ton grade restent</small></button>');
      fresh.addEventListener('click', function () { newGame(fresh); });
      nav.appendChild(again); nav.appendChild(inv); nav.appendChild(fresh);
      app.appendChild(v);
    });
  }

  // ——— Start ———
  window.SGSdk.onReward(function (rewards) { G.rewards = G.rewards.concat(rewards); });
  Promise.all([api('getProgress'), api('getScenario'), api('getUser').catch(function () { return null; })]).then(function (r) {
    G.user = r[2];
    var data = r[0].data || {};
    G.data = Object.assign(G.data, data);
    ['seen', 'drafts'].forEach(function (k) { G.data[k] = G.data[k] || {}; });
    SND.setMuted(!!G.data.mute);
    G.sc = r[1];
    showTitle();
  }).catch(function (e) {
    app.innerHTML = '<p style="color:#fff;padding:40px">Le jeu n\'arrive pas à joindre la plateforme : ' + esc(e.message) + '</p>';
  });
})();
