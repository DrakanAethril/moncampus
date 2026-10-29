/* Panique à la Neko-Con — the game: title screen, scenes, Kanban board, workstation.

   Progress is held by the platform (SGSdk → host page → API); the game keeps only its display
   state (saveProgress): lines already seen, code drafts, sound muted, hints read. */
(function () {
  'use strict';

  var app = document.getElementById('app');
  var E = window.NekoEngine, C = window.NekoChars, SND = window.NekoSound, STORY = window.NekoStory;
  var api = function (m, p) { return window.SGSdk.call(m, p); };

  var G = {
    data: { seen: {}, drafts: {}, hints: {}, mute: false, prologue: false },
    user: null, rewards: [],
    sc: null, ticket: null, def: null, session: null, step: 0, files: null,
    page: 'index.html', device: 1280, tool: 'apercu', tab: null, results: null, requests: [], reader: 'all'
  };

  // ——— Helpers ———
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function h(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }
  function $(sel, root) { return (root || app).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
  var saveTimer = null;
  function save(now) {
    clearTimeout(saveTimer);
    var go = function () { api('saveProgress', { data: G.data }); };
    if (now) go(); else saveTimer = setTimeout(go, 800);
  }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  // The frame is sandboxed without allow-modals: window.alert() would be swallowed silently.
  function notice(text) {
    var w = h('<div class="overlay"><div class="window" role="alertdialog" aria-modal="true"><p>' + esc(text) + '</p><div class="actions"><button type="button" class="btn btn-pink">Fermer</button></div></div></div>');
    $('.btn', w).addEventListener('click', function () { w.remove(); });
    app.appendChild(w); $('.btn', w).focus();
  }
  // The student's avatar, from the platform (getUser): drawn with the badge's lanyard, the game's
  // costume. An adult has one too; only a student collects.
  function avatar(size) {
    if (!G.user || !window.SGAvatar) return '';
    return window.SGAvatar.html(G.user.avatar, size, { costume: 'neko-con' });
  }
  var NAMES = { maelle: 'Maëlle', ines: 'Inès', theo: 'Théo', mika: 'Mika S.', client: 'Client', jordan: 'Jordan' };
  function nameOf(who, from) { return from || NAMES[who] || who; }
  function colorOf(who) { return (C.cast[who] && C.cast[who].color) || '#a39cbc'; }

  var ICON = {
    sound: '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9 H8 L13 5 V19 L8 15 H4 Z M16 9 C18 11 18 13 16 15 M18.5 6.5 C22 10 22 14 18.5 17.5" fill="none" stroke="#fbf9ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    muted: '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9 H8 L13 5 V19 L8 15 H4 Z M16 9 L22 15 M22 9 L16 15" fill="none" stroke="#fbf9ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    mail: '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="30" fill="#efeaf8"/><path d="M16 22 H48 V44 H16 Z M16 22 L32 34 L48 22" fill="none" stroke="#5b3fb0" stroke-width="3" stroke-linejoin="round"/></svg>',
    jordan: '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="30" fill="#2b2350"/><circle cx="32" cy="26" r="10" fill="#6a5f94"/><path d="M14 52 C18 40 46 40 50 52" fill="#6a5f94"/><text x="44" y="22" font-family="Dela Gothic One" font-size="14" fill="#ffd166">?</text></svg>'
  };
  function face(who, expr, crop) {
    if (C.cast[who]) return C.svg(who, expr, { crop: crop || 'head' });
    if (who === 'jordan') return ICON.jordan;
    return ICON.mail;
  }

  // ——— Backdrops ———
  function hallSVG(lit) {
    var bulbs = '';
    for (var i = 0; i < 15; i++) {
      var x = 660 + i * 46, y = 372 + Math.round(10 * Math.sin(i * 0.9));
      bulbs += '<circle cx="' + x + '" cy="' + y + '" r="5" fill="' + (lit ? ['#ffd166', '#ff6f91', '#7fd8d6'][i % 3] : '#5a5178') + '"/>';
    }
    return '<svg class="title-backdrop" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" aria-hidden="true">' +
      '<rect width="1440" height="900" fill="' + (lit ? '#3a3170' : '#241f3a') + '"/><rect y="540" width="1440" height="360" fill="' + (lit ? '#463c86' : '#2c2649') + '"/>' +
      (lit ? '<circle cx="1290" cy="120" r="70" fill="#ffd9a8" opacity=".5"/>' : '<circle cx="1290" cy="110" r="44" fill="#ffe9b0"/><circle cx="1274" cy="98" r="7" fill="#f2d48d"/>') +
      '<g fill="#fbf9ff"><circle cx="560" cy="80" r="2"/><circle cx="650" cy="160" r="1.5"/><circle cx="760" cy="60" r="2.2"/><circle cx="880" cy="140" r="1.4"/><circle cx="1010" cy="70" r="2"/><circle cx="1130" cy="180" r="1.6"/><circle cx="700" cy="215" r="1.3"/></g>' +
      '<path d="M580 312 C800 262 1180 262 1400 312 L1400 334 L580 334 Z" fill="#4a4080"/><rect x="600" y="330" width="780" height="480" fill="#3b3266"/>' + bulbs +
      '<rect x="690" y="410" width="600" height="110" rx="14" fill="#2a2440"/>' +
      '<text x="820" y="490" font-family="Rampart One" font-size="64" letter-spacing="4" fill="' + (lit ? '#ff6f91' : '#5a4a7a') + '">NEKO-CON</text>' +
      '<g opacity="' + (lit ? 1 : .55) + '"><path d="M716 488 L720 432 L746 452 Z M790 488 L786 432 L760 452 Z" fill="' + (lit ? '#fff' : '#6a4a7a') + '"/><ellipse cx="753" cy="474" rx="40" ry="32" fill="' + (lit ? '#fff' : '#6a4a7a') + '"/><path d="M736 472 C740 466 746 466 750 472 M758 472 C762 466 768 466 772 472" fill="none" stroke="#2a2440" stroke-width="3" stroke-linecap="round"/></g>' +
      '<rect x="850" y="590" width="280" height="220" rx="6" fill="' + (lit ? '#ffe9b0' : '#1f1a33') + '"/><rect x="988" y="590" width="4" height="220" fill="#3b3266"/>' +
      (lit ? '' : '<rect x="870" y="620" width="96" height="130" rx="4" fill="#fbf9ff"/><text x="918" y="652" text-anchor="middle" font-family="Dela Gothic One" font-size="15" fill="#c2185b">OUVERTURE</text><text x="918" y="676" text-anchor="middle" font-family="Dela Gothic One" font-size="15" fill="#1d1830">SAMEDI</text><text x="918" y="706" text-anchor="middle" font-family="Dela Gothic One" font-size="26" fill="#1d1830">10 H</text>') +
      '<rect y="800" width="1440" height="100" fill="#1a162b"/>' +
      (lit
        ? '<g transform="translate(1290 560)"><path d="M10 240 C0 120 40 40 110 40 C180 40 220 120 210 240 Z" fill="#e8457a"/><path d="M50 70 L40 0 L90 46 Z M170 70 L180 0 L130 46 Z" fill="#e8457a"/><path d="M72 120 C80 108 92 108 100 120 M120 120 C128 108 140 108 148 120" fill="none" stroke="#2a2440" stroke-width="5" stroke-linecap="round"/></g>'
        : '<path d="M1300 806 C1292 744 1318 712 1360 704 C1410 696 1446 730 1452 806 Z" fill="#8a3a6a"/><path d="M1332 716 C1316 700 1312 676 1324 664 C1342 668 1346 690 1348 706 Z M1392 706 C1404 688 1424 680 1436 690 C1430 706 1414 712 1400 716 Z" fill="#8a3a6a"/><path d="M1346 742 L1362 742 M1384 744 L1400 744" stroke="#2a2440" stroke-width="3.5" stroke-linecap="round"/><text x="1318" y="660" font-family="Dela Gothic One" font-size="18" fill="#cfc8e6" opacity=".7">z</text><text x="1302" y="636" font-family="Dela Gothic One" font-size="24" fill="#cfc8e6" opacity=".55">z</text>') +
      '<ellipse cx="540" cy="806" rx="150" ry="22" fill="#ffd166" opacity=".14"/><rect x="534" y="430" width="12" height="376" fill="#15122a"/><path d="M510 430 H570 L558 396 H522 Z" fill="#15122a"/><circle cx="540" cy="440" r="70" fill="#ffd166" opacity=".12"/><rect x="526" y="408" width="28" height="22" fill="#ffe9b0"/></svg>';
  }
  function clubSVG() {
    return '<svg class="scene-backdrop" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" aria-hidden="true">' +
      '<rect width="1440" height="900" fill="#2f2850"/><rect y="600" width="1440" height="300" fill="#262044"/>' +
      '<rect x="980" y="110" width="340" height="300" rx="14" fill="#3b3266"/><rect x="1004" y="134" width="292" height="252" rx="8" fill="#ffb88a" opacity=".8"/><rect x="1004" y="256" width="292" height="6" fill="#3b3266"/><rect x="1147" y="134" width="6" height="252" fill="#3b3266"/>' +
      '<rect x="150" y="150" width="150" height="210" rx="6" fill="#e8457a" opacity=".85" transform="rotate(-4 225 255)"/><rect x="330" y="170" width="130" height="180" rx="6" fill="#7fd8d6" opacity=".8" transform="rotate(3 395 260)"/>' +
      '<rect x="120" y="520" width="520" height="22" rx="6" fill="#4a4080"/><rect x="160" y="440" width="40" height="80" fill="#ffd166"/><rect x="206" y="456" width="34" height="64" fill="#ff6f91"/><rect x="246" y="430" width="44" height="90" fill="#7fd8d6"/><rect x="296" y="450" width="30" height="70" fill="#cfc2ff"/>' +
      '</svg>';
  }

  // ——— Typewriter ———
  function typewriter(node, text, who) {
    var i = 0, timer = null, finished = false, resolveDone;
    var done = new Promise(function (r) { resolveDone = r; });
    node.textContent = '';
    function tick() {
      if (i >= text.length) { finished = true; resolveDone(); return; }
      i = Math.min(text.length, i + 1);
      node.textContent = text.slice(0, i);
      if (i % 3 === 0 && who && who !== 'narration') SND.blip(who);
      timer = setTimeout(tick, /[.!?…]/.test(text.charAt(i - 1)) ? 140 : 22);
    }
    tick();
    return {
      done: done,
      isDone: function () { return finished; },
      finish: function () { clearTimeout(timer); node.textContent = text; finished = true; resolveDone(); }
    };
  }

  // ——— Scenes ———
  /**
   * Plays a run of lines full screen. opts.decor: 'club' | 'hall' | 'hall-lit' | a node;
   * opts.setup(scene) prepares the set (the bascule); opts.delay holds back the first line.
   */
  function playScene(lines, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var scene = h('<section class="scene" role="dialog" aria-modal="true" aria-label="Scène"></section>');
      if (opts.decor === 'hall' || opts.decor === 'hall-lit') scene.appendChild(h(hallSVG(opts.decor === 'hall-lit')));
      else scene.appendChild(h(clubSVG()));
      scene.appendChild(h('<div class="dots"></div>'));
      if (opts.card) scene.appendChild(h('<div class="scene-act"><div class="num">' + esc(opts.card.num) + '</div><div class="name">' + esc(opts.card.title) + '</div></div>'));
      var left = h('<div class="character left" style="display:none"></div>'), right = h('<div class="character right" style="display:none"></div>');
      scene.appendChild(left); scene.appendChild(right);
      var box = h('<div class="dialog-box" tabindex="0"><div class="name"></div><p class="line"></p><span class="more" aria-hidden="true">▼</span><button type="button" class="btn btn-ghost skip">Passer la scène</button><span class="help">Clic, Entrée ou Espace pour continuer</span></div>');
      scene.appendChild(box);
      app.appendChild(scene);
      if (opts.setup) opts.setup(scene);
      var idx = -1, tw = null, sides = { left: null, right: null };
      function sideOf(who) { return who === 'ines' ? 'left' : 'right'; }
      function show() {
        idx++;
        if (idx >= lines.length) { close(); return; }
        var l = lines[idx], who = l.who;
        var nameNode = $('.name', box);
        if (who === 'narration') { nameNode.style.display = 'none'; }
        else { nameNode.style.display = ''; nameNode.textContent = nameOf(who, l.from); nameNode.style.background = colorOf(who); }
        if (C.cast[who]) {
          var side = sideOf(who), slot = side === 'left' ? left : right;
          var fresh = sides[side] !== who;
          sides[side] = who;
          slot.innerHTML = C.svg(who, l.expr || 'neutre', { crop: 'full' });
          slot.style.display = '';
          slot.classList.toggle('entering', fresh);
          slot.classList.remove('muted');
          var other = side === 'left' ? right : left;
          other.classList.add('muted');
        } else {
          left.classList.add('muted'); right.classList.add('muted');
        }
        tw = typewriter($('.line', box), l.text, who);
      }
      function next() {
        SND.click();
        if (tw && !tw.isDone()) { tw.finish(); return; }
        show();
      }
      function close() {
        document.removeEventListener('keydown', onKey, true);
        scene.remove();
        resolve();
      }
      function onKey(e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); next(); }
        else if (e.key === 'Escape') { e.preventDefault(); close(); }
      }
      box.addEventListener('click', function (e) { if (e.target.closest('.skip')) { close(); return; } next(); });
      document.addEventListener('keydown', onKey, true);
      setTimeout(function () { box.focus(); show(); }, opts.delay || 0);
    });
  }

  // ——— Title screen ———
  function showTitle() {
    app.innerHTML = '';
    var sc = G.sc, closedN = sc.cards.filter(function (c) { return c.status === 'done' || c.status === 'reassigned'; }).length;
    var started = G.data.prologue;
    var view = h('<section class="title-screen"></section>');
    view.appendChild(h(hallSVG(sc.finished)));
    view.appendChild(h('<div class="dots"></div>'));
    var m = h('<div class="character" style="left:55%;width:22%">' + C.svg('maelle', 'joie', { crop: 'full' }) + '</div>');
    var i = h('<div class="character" style="left:74%;width:19%">' + C.svg('ines', 'neutre', { crop: 'full' }) + '</div>');
    view.appendChild(m); view.appendChild(i);
    var actTitle = (sc.acts[sc.act - 1] || {}).title || '';
    var txt = h('<div class="title-text">' +
      '<div class="title-kicker">UN ESCAPE GAME DE DÉBOGAGE</div>' +
      '<h1><span class="l1">Panique</span><span class="l2">à la Neko-Con</span></h1>' +
      '<p class="pitch">La convention manga du lycée ouvre demain. L\'ancien président du club est parti étudier au Japon sans laisser les accès du site. Et tout est cassé.</p>' +
      '<nav aria-label="Menu principal"></nav></div>');
    var nav = $('nav', txt);
    if (!started) {
      var b = h('<button type="button" class="btn btn-pink">Nouvelle partie</button>');
      b.addEventListener('click', startGame); nav.appendChild(b);
    } else {
      var c = h('<button type="button" class="btn btn-white"><span>Continuer<small>' + (sc.finished ? 'Partie terminée · la convention est ouverte' : 'Acte ' + sc.act + ' · ' + esc(actTitle) + ' · ' + closedN + ' ticket' + (closedN > 1 ? 's' : '') + ' clos') + '</small></span></button>');
      c.addEventListener('click', continueGame); nav.appendChild(c);
      var p = h('<button type="button" class="btn btn-ghost">Revoir le prologue</button>');
      p.addEventListener('click', function () { playScene(STORY.prologue, { decor: 'club' }); });
      nav.appendChild(p);
    }
    nav.appendChild(soundButton('btn btn-ghost', true));
    view.appendChild(txt);
    view.appendChild(h('<div class="title-footer"><span>Révision HTML et CSS · BTS SIO</span><span>S. Tharaud · CC BY-NC 4.0</span></div>'));
    app.appendChild(view);
    $('nav .btn', view).focus();
  }

  function soundButton(cls, withLabel) {
    var b = h('<button type="button" class="' + cls + '"></button>');
    function paint() {
      b.innerHTML = (SND.isMuted() ? ICON.muted : ICON.sound) + (withLabel ? (SND.isMuted() ? ' Son coupé' : ' Son activé') : '');
      b.setAttribute('aria-label', SND.isMuted() ? 'Activer le son' : 'Couper le son');
      b.setAttribute('aria-pressed', SND.isMuted() ? 'true' : 'false');
    }
    b.addEventListener('click', function () { SND.setMuted(!SND.isMuted()); G.data.mute = SND.isMuted(); save(); paint(); });
    paint();
    return b;
  }

  function startGame() {
    G.data.prologue = true; save(true);
    playScene(STORY.prologue, { decor: 'club' }).then(function () { return actIntro(1); }).then(showBoard);
  }
  function continueGame() {
    if (G.sc.finished) { showEnd(); return; }
    (G.sc.actSeen ? Promise.resolve() : actIntro(G.sc.act)).then(showBoard);
  }
  function actIntro(n) {
    var act = G.sc.acts[n - 1];
    return playScene(STORY.acts[n] || [], { decor: 'club', card: { num: 'ACTE ' + n, title: act ? act.title : '' }, delay: 1300 })
      .then(function () { return api('markActSeen', { act: n }); });
  }

  // ——— Top bar ———
  function topBar(extra) {
    var sc = G.sc;
    var bar = h('<header class="top-bar"><span class="game-name">Panique à la Neko-Con</span><span class="chip act">Acte ' + sc.act + ' · ' + esc((sc.acts[sc.act - 1] || {}).title || '') + '</span><div class="right"></div></header>');
    if (G.user) bar.insertBefore(h('<span class="trainee">' + avatar(32) + '<span>Stagiaire</span></span>'), bar.firstChild);
    var right = $('.right', bar);
    if (extra) right.appendChild(extra);
    var password = h('<div class="password" aria-label="Morceaux du mot de passe"><span class="label">Mot de passe</span></div>');
    sc.fragments.forEach(function (f, i) { password.appendChild(h('<span class="slot' + (f ? ' ok' : '') + '" title="Morceau ' + (i + 1) + '">' + (f ? esc(f) : '') + '</span>')); });
    right.appendChild(password);
    var notebook = h('<button type="button" class="btn btn-pink" style="min-height:40px">Carnet · ' + (sc.fragments.filter(Boolean).length + sc.clues.length) + '</button>');
    notebook.addEventListener('click', showNotebook);
    right.appendChild(notebook);
    right.appendChild(soundButton('round-btn', false));
    return bar;
  }

  function showNotebook() {
    var sc = G.sc;
    var w = h('<div class="overlay"><div class="window notebook" role="dialog" aria-modal="true" aria-labelledby="notebookTitle"><h2 id="notebookTitle">Carnet d\'enquête</h2>' +
      '<p>Les morceaux du mot de passe de Jordan, dans l\'ordre où ils doivent être tapés :</p><div class="notebook-slots"></div>' +
      '<h3 style="margin-top:18px">Indices</h3>' + (sc.clues.length ? '<ul>' + sc.clues.map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('') + '</ul>' : '<p class="note">Rien pour l\'instant. Ouvre l\'œil en lisant le code.</p>') +
      '<div class="actions"><button type="button" class="btn btn-pink">Fermer</button></div></div></div>');
    var slots = $('.notebook-slots', w);
    sc.fragments.forEach(function (f, i) { slots.appendChild(h('<span class="slot' + (f ? ' ok' : '') + '">' + (f ? esc(f) : (i + 1)) + '</span>')); });
    function close() { w.remove(); document.removeEventListener('keydown', onKey, true); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    $('.actions .btn', w).addEventListener('click', close);
    w.addEventListener('click', function (e) { if (e.target === w) close(); });
    document.addEventListener('keydown', onKey, true);
    app.appendChild(w);
    $('.actions .btn', w).focus();
  }

  // ——— Kanban board ———
  function refreshScenario() { return api('getScenario').then(function (sc) { G.sc = sc; return sc; }); }

  function showBoard(newIds) {
    if (!Array.isArray(newIds)) newIds = [];
    refreshScenario().then(function (sc) {
      if (sc.finished) { showEnd(); return; }
      if (!sc.actSeen) { actIntro(sc.act).then(function () { showBoard(newIds); }); return; }
      app.innerHTML = '';
      app.appendChild(h('<div class="dots"></div>'));
      app.appendChild(topBar());
      var cols = [
        { key: 'todo', title: 'À faire', empty: 'Rien à faire pour l\'instant.' },
        { key: 'doing', title: 'En cours', empty: 'Clique sur un ticket « À faire » pour le prendre en charge.' },
        { key: 'done', title: 'Résolus', empty: 'Aucun ticket résolu… pour l\'instant.' },
        { key: 'reassigned', title: 'Réaffectés', empty: 'Les tickets qui ne relèvent pas du site web.' }
      ];
      var board = h('<main class="kanban" aria-label="Tableau Kanban"></main>');
      var open = sc.cards.filter(function (c) { return c.status === 'todo' || c.status === 'doing'; }).length;
      cols.forEach(function (col) {
        var cards = sc.cards.filter(function (c) { return c.status === col.key; });
        if (col.key === 'done' || col.key === 'reassigned') cards = cards.slice().reverse();
        var colEl = h('<section class="column" aria-label="' + col.title + '"><h2>' + col.title + ' <span class="n">' + cards.length + '</span></h2></section>');
        if (col.key === 'todo') colEl.appendChild(h('<div class="wip">Tickets ouverts : ' + open + ' / ' + sc.capacity + '</div>'));
        if (!cards.length) colEl.appendChild(h('<p class="empty">' + col.empty + '</p>'));
        cards.forEach(function (c) { colEl.appendChild(cardEl(c, newIds && newIds.indexOf(c.id) !== -1)); });
        board.appendChild(colEl);
      });
      app.appendChild(board);
      var first = $('.card.in-progress', board) || $('.column .card', board);
      if (first) first.focus();
    });
  }

  function cardEl(c, fresh) {
    var closed = c.status === 'done' || c.status === 'reassigned';
    var who = c.requester;
    var b = h('<button type="button" class="card' + (c.status === 'doing' ? ' in-progress' : '') + (closed ? ' closed' : '') + (fresh ? ' new' : '') + '"></button>');
    b.innerHTML = '<span class="av" style="background:' + ((C.cast[who] && C.cast[who].bg) || '#efeaf8') + '">' + face(who, 'neutre', 'head') + '</span>' +
      '<span class="meta"><span>' + c.id + ' · ' + esc(nameOf(who, c.from)) + '</span><span>' + (c.status === 'doing' ? 'étape ' + (c.step + 1) + '/' + c.steps : (closed && c.quality ? '<span class="stars" aria-label="' + c.quality + ' sur 3">' + '★★★'.slice(0, c.quality) + '</span>' : '')) + '</span></span>' +
      '<span class="card-title">' + esc(c.title) + '</span>' +
      (closed ? '' : '<span class="card-foot"><span class="chip prio-' + c.priority + '">Priorité ' + c.priority + '</span>' + (c.duplicates > 1 ? '<span class="chip duplicate">×' + c.duplicates + ' demandeurs</span>' : '') + '</span>');
    if (closed) b.addEventListener('click', function () { reviewTicket(c); });
    else b.addEventListener('click', function () { SND.click(); openTicket(c.id); });
    return b;
  }

  function reviewTicket(c) {
    var w = h('<div class="overlay"><div class="window" role="dialog" aria-modal="true"><h2>' + c.id + ' · ' + esc(c.title) + '</h2><p>' + (c.status === 'reassigned' ? 'Ticket réaffecté : il ne relevait pas du site web.' : 'Ticket résolu' + (c.quality ? ' · ' + '★★★'.slice(0, c.quality) : '') + '.') + '</p><div class="actions"><button type="button" class="btn btn-pink">Fermer</button></div></div></div>');
    $('.btn', w).addEventListener('click', function () { w.remove(); });
    app.appendChild(w); $('.btn', w).focus();
  }

  // ——— Ticket ———
  function openTicket(id) {
    api('startTicket', { ticket: id }).then(function (r) {
      G.session = r.session; G.ticket = r.ticket; G.step = r.step;
      G.guide = r.guide || []; G.guideStep = r.guideStep || 0; G.hints = r.hints || 0; G.failed = 0;
      G.def = window.NekoTickets.get(id);
      if (!G.def) { notice('Ticket introuvable dans le jeu : ' + id); return; }
      G.files = E.ticketFiles(G.def);
      var draft = G.data.drafts[id];
      if (draft) Object.keys(draft).forEach(function (f) { if (G.def.edit.indexOf(f) !== -1) G.files[f] = draft[f]; });
      G.page = G.def.page; G.tool = 'apercu'; G.results = null; G.requests = []; G.reader = 'all';
      G.device = G.def.startDevice || 1280;
      var st = G.ticket.steps[G.step];
      G.tab = st && st.file ? st.file : (G.def.edit[0] || G.def.page);
      var intro = !G.data.seen[id] && G.def.intro && G.def.intro.length ? G.def.intro : null;
      (intro ? playScene(intro, { decor: 'club' }) : Promise.resolve()).then(function () {
        G.data.seen[id] = true; save();
        renderWorkstation();
        var last = intro ? intro[intro.length - 1] : { who: G.ticket.requester, expr: 'neutre', text: G.ticket.request };
        if (st && st.kind === 'explain') last = { who: G.ticket.requester, expr: st.expr, text: st.prompt };
        say(last.who, last.expr, last.text);
      });
    }).catch(function (e) { notice('Impossible d\'ouvrir le ticket : ' + e.message); });
  }

  function tools() { var act = G.sc.acts[(G.ticket ? G.ticket.act : G.sc.act) - 1]; return act ? act.tools : ['apercu']; }
  function devices() { return G.def.devices || (tools().indexOf('appareils') !== -1 ? [375, 768, 1280] : [1280]); }
  function currentStep() { return G.ticket.steps[G.step]; }
  // Inès's help is a ladder: her guided questions first (G.guide), then the written hints
  // (G.def.hints). Every rung opened counts as a hint.
  function rungs() { return G.guide.length + G.def.hints.length; }
  function guideOpen() { return G.guideStep < G.guide.length && G.hints > G.guideStep ? G.guide[G.guideStep] : null; }

  function renderWorkstation() {
    app.innerHTML = '';
    app.appendChild(h('<div class="dots"></div>'));
    var back = h('<button type="button" class="btn btn-ghost" style="min-height:40px">← Tableau Kanban</button>');
    back.addEventListener('click', function () { save(true); showBoard(); });
    app.appendChild(topBar(back));
    var station = h('<main class="workstation"></main>');
    station.appendChild(h('<section class="panel left" aria-label="Ticket"></section>'));
    station.appendChild(h('<section class="panel editor" aria-label="Code"></section>'));
    station.appendChild(h('<section class="panel preview" aria-label="Aperçu du site"></section>'));
    station.appendChild(h('<div class="dialogue" aria-live="polite"><div class="face"></div><span class="who"></span><p class="says"></p></div>'));
    station.appendChild(h('<iframe class="test-frame" title="Vérification" sandbox="allow-scripts" aria-hidden="true" tabindex="-1"></iframe>'));
    app.appendChild(station);
    renderLeft(); renderEditor(); renderPreviewPanel();
  }

  var sayTw = null;
  function say(who, expr, text, from) {
    var d = $('.dialogue');
    if (!d) return;
    $('.face', d).innerHTML = face(who, expr || 'neutre', 'bust');
    var q = $('.who', d);
    q.textContent = nameOf(who, from || (who === 'client' && G.ticket ? G.ticket.from : null));
    q.style.background = colorOf(who);
    if (sayTw) sayTw.finish();
    sayTw = typewriter($('.says', d), text, who);
  }

  // Left panel: the ticket, its steps, the current step, Inès's help.
  var STEP_LABEL = { line: 'Localiser', fix: 'Réparer', explain: 'Expliquer', password: 'Mot de passe' };
  function choiceButtons(options, send) {
    var box = h('<div class="choices"></div>');
    options.forEach(function (o, i) {
      var b = h('<button type="button">' + esc(o) + '</button>');
      b.addEventListener('click', function () {
        if (b.classList.contains('wrong')) return;
        send(i).then(function (res) { if (!res.ok) { b.classList.add('wrong'); b.setAttribute('aria-disabled', 'true'); } });
      });
      box.appendChild(b);
    });
    return box;
  }
  function renderLeft() {
    var p = $('.left'), t = G.ticket, st = currentStep();
    p.innerHTML = '';
    p.appendChild(h('<div class="ticket-head"><span>' + t.id + '</span><span class="chip prio-' + t.priority + '">Priorité ' + t.priority + '</span>' + (t.duplicates > 1 ? '<span class="chip duplicate">×' + t.duplicates + '</span>' : '') + '</div>'));
    p.appendChild(h('<h1 class="ticket-title">' + esc(t.title) + '</h1>'));
    if (!st || st.kind !== 'explain') p.appendChild(h('<div class="request"><div class="from">' + esc(nameOf(t.requester, t.from)) + ' a écrit :</div>' + esc(t.request) + '</div>'));
    var ol = h('<ol class="steps" aria-label="Étapes du ticket"></ol>');
    t.steps.forEach(function (s, i) {
      var label = s.kind === 'choice' ? (s.label || stepName(s)) : STEP_LABEL[s.kind];
      ol.appendChild(h('<li class="' + (i < G.step ? 'done' : (i === G.step ? 'active' : '')) + '"' + (i === G.step ? ' aria-current="step"' : '') + '><span class="step-num">' + (i < G.step ? '✓' : i + 1) + '</span>' + esc(label) + '</li>'));
    });
    p.appendChild(ol);
    if (!st) { p.appendChild(h('<p class="instruction">Toutes les étapes sont faites.</p>')); return; }
    var guide = guideOpen();
    if (st.kind === 'explain') {
      var who = nameOf(t.requester, t.from);
      p.appendChild(h('<p class="instruction">' + esc(who) + ' veut comprendre ce qui s\'est passé.</p>'));
      p.appendChild(h('<div class="requester-question"><div class="from">' + esc(who) + ' :</div>« ' + esc(st.prompt) + ' »</div>'));
      p.appendChild(h('<p class="note">Que lui expliques-tu ?</p>'));
      p.appendChild(choiceButtons(st.options, function (i) { return submit(st, i); }));
    } else {
      p.appendChild(h('<p class="instruction">' + esc(st.prompt) + '</p>'));
    }
    if (st.kind === 'choice') {
      p.appendChild(choiceButtons(st.options, function (i) { return submit(st, i); }));
    } else if (st.kind === 'fix') {
      p.appendChild(h('<p class="note">Observe l\'aperçu, sers-toi des outils, puis modifie le code au centre : l\'aperçu se met à jour tout seul. Quand tout te semble bon, livre ta correction (en bas de l\'aperçu). Fichiers modifiables : ' + G.def.edit.map(esc).join(', ') + '.</p>'));
      var reset = h('<button type="button" class="btn btn-white" style="align-self:flex-start;min-height:36px;font-size:13px">Remettre le code de départ</button>');
      reset.addEventListener('click', function () {
        var orig = E.ticketFiles(G.def);
        G.def.edit.forEach(function (f) { G.files[f] = orig[f]; });
        delete G.data.drafts[G.ticket.id]; save();
        renderEditor(); refreshPreview(); markChecksStale();
      });
      p.appendChild(reset);
    } else if (st.kind === 'password') {
      // No <form>: the game's sandboxed frame (without allow-forms) blocks a form submission.
      var f = h('<div class="password-entry"><label class="sr-only" for="passwordInput">Mot de passe</label><input id="passwordInput" type="password" autocomplete="off" placeholder="Les cinq morceaux, dans l\'ordre"><button class="btn btn-pink" type="button">Valider</button></div>');
      var send = function () { submit(st, $('#passwordInput').value); };
      $('button', f).addEventListener('click', send);
      $('input', f).addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); send(); } });
      p.appendChild(f);
    }
    if (st.kind === 'explain' || !rungs()) return;
    var hz = h('<div class="hint-zone"></div>');
    if (guide) {
      var box = h('<div class="guided-help"><div class="from">Inès te guide</div><p>' + esc(guide.prompt) + '</p></div>');
      if (guide.kind === 'choice') box.appendChild(choiceButtons(guide.options, function (i) { return submitGuide(guide, i); }));
      else if (guide.kind === 'line') box.appendChild(h('<p class="note">Clique directement sur la ligne, dans l\'éditeur au centre. Tu retrouveras ton code juste après.</p>'));
      hz.appendChild(box);
    } else {
      var total = rungs();
      var hb = h('<button type="button" class="btn btn-turquoise">' + (G.hints < total ? 'Demander de l\'aide à Inès (' + (G.hints + 1) + '/' + total + ')' : 'Revoir le dernier indice') + '</button>');
      hb.addEventListener('click', function () {
        if (G.hints >= total) { say('ines', 'neutre', G.def.hints[G.def.hints.length - 1]); return; }
        hb.disabled = true;
        api('useHint', { session: G.session }).then(function (r) {
          G.hints = r.hints;
          var g = guideOpen();
          if (g) {
            say('ines', 'neutre', g.prompt);
            if (g.kind === 'line' && g.file) G.tab = g.file;
          } else {
            var k = G.hints - G.guide.length - 1;
            say('ines', k === G.def.hints.length - 1 ? 'sourire' : 'neutre', G.def.hints[Math.max(0, k)]);
          }
          renderLeft(); renderEditor();
        });
      });
      hz.appendChild(hb);
    }
    p.appendChild(hz);
  }
  function stepName(s) {
    var p = s.prompt.toLowerCase();
    if (/html ou (en|dans le) css|html ou css/.test(p)) return 'HTML ou CSS ?';
    if (/réaffect|qui doit|relève/.test(p)) return 'Trier';
    if (/où|quelle page|quel appareil|reproduire/.test(p)) return 'Reproduire';
    return 'Diagnostiquer';
  }

  function submit(st, input) {
    return api('submitStep', { session: G.session, step: st.id, input: input }).then(function (res) {
      if (res.ok) {
        SND.right();
        var thanks = st.kind === 'explain' && !res.reaction ? 'Ah, d\'accord ! Je comprends mieux, merci.' : null;
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
        else if (thanks) say(G.ticket.requester, 'joie', thanks);
        else if (st.kind !== 'fix') say('ines', 'neutre', ['Exact.', 'C\'est ça.', 'Bien vu.', 'Oui. On continue.'][Math.floor(Math.random() * 4)]);
        G.step = res.next;
        if (res.done) {
          // Leave time to read the last line before the stamp.
          var said = res.reaction ? res.reaction.text : (thanks || '');
          wait(said ? Math.min(4500, 900 + said.length * 28) : 0).then(finishTicket);
          return res;
        }
        var nst = currentStep();
        if (nst && nst.kind === 'explain') say(G.ticket.requester, nst.expr || 'neutre', nst.prompt);
        if (nst && nst.file) G.tab = nst.file;
        else if (nst && nst.kind === 'fix' && G.def.edit.length) G.tab = G.def.edit[0];
        renderLeft(); renderEditor(); renderChecks();
      } else {
        SND.wrong();
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
      }
      return res;
    }).catch(function (e) { say('ines', 'sourcil', 'Erreur de la plateforme : ' + e.message); return { ok: false }; });
  }

  function submitGuide(st, input) {
    return api('submitGuide', { session: G.session, step: st.id, input: input }).then(function (res) {
      if (res.ok) {
        SND.right();
        say(res.reaction ? res.reaction.who : 'ines', res.reaction ? res.reaction.expr : 'neutre', res.reaction ? res.reaction.text : ['Exact.', 'C\'est ça.', 'Bien vu. À toi de jouer, maintenant.'][Math.floor(Math.random() * 3)]);
        G.guideStep = res.next;
        var st0 = currentStep();
        if (st0 && st0.kind === 'fix' && G.def.edit.indexOf(G.tab) === -1 && G.def.edit.length) G.tab = G.def.edit[0];
        renderLeft(); renderEditor();
      } else {
        SND.wrong();
        if (res.reaction) say(res.reaction.who, res.reaction.expr, res.reaction.text);
      }
      return res;
    }).catch(function (e) { say('ines', 'sourcil', 'Erreur de la plateforme : ' + e.message); return { ok: false }; });
  }

  // ——— Editor ———
  function hlHTML(src) {
    var out = '', re = /<!--[\s\S]*?-->|<\/?[a-zA-Z!][^>]*>|[^<]+|</g, m;
    while ((m = re.exec(src))) {
      var t = m[0];
      if (t.indexOf('<!--') === 0) out += '<span class="t-com">' + esc(t) + '</span>';
      else if (t.charAt(0) === '<' && t.length > 1) {
        var mm = t.match(/^(<\/?)([a-zA-Z0-9!-]+)([\s\S]*?)(\/?>)$/);
        if (!mm) { out += esc(t); continue; }
        var attrs = mm[3].replace(/([^\s=]+)(\s*=\s*)?("[^"]*"|'[^']*'|[^\s"']+)?/g, function (a, n, eq, v) {
          return '\u0001' + n + '\u0002' + (eq || '') + (v ? '\u0003' + v + '\u0004' : '');
        });
        attrs = esc(attrs).replace(/\u0001/g, '<span class="t-att">').replace(/\u0002/g, '</span>').replace(/\u0003/g, '<span class="t-val">').replace(/\u0004/g, '</span>');
        out += esc(mm[1]) + '<span class="t-tag">' + esc(mm[2]) + '</span>' + attrs + esc(mm[4]);
      } else out += esc(t);
    }
    return out;
  }
  function hlCSS(src) {
    var out = '', re = /\/\*[\s\S]*?\*\/|[{}]|[^{}\/]+|\//g, m;
    while ((m = re.exec(src))) {
      var t = m[0];
      if (t.indexOf('/*') === 0) out += '<span class="t-com">' + esc(t) + '</span>';
      else if (t === '{' || t === '}' || t === '/') out += esc(t);
      else if (src.charAt(re.lastIndex) === '{') out += '<span class="' + (/^\s*@/.test(t) ? 't-at' : 't-sel') + '">' + esc(t) + '</span>';
      else {
        var parts = '', last = 0, d = /([\w-]+)(\s*:\s*)([^;]*)/g, k;
        while ((k = d.exec(t))) {
          parts += esc(t.slice(last, k.index)) + '<span class="t-prop">' + esc(k[1]) + '</span>' + esc(k[2]) + '<span class="t-val">' + esc(k[3]) + '</span>';
          last = d.lastIndex;
        }
        out += parts + esc(t.slice(last));
      }
    }
    return out;
  }
  function highlight(file, src) { return (/\.css$/.test(file) ? hlCSS(src) : hlHTML(src)) + '\n'; }

  function tabsList() {
    var list = [];
    function add(f) { if (f && list.indexOf(f) === -1 && G.files[f] !== undefined) list.push(f); }
    G.def.edit.forEach(add);
    (G.def.show || []).forEach(add);
    G.ticket.steps.concat(G.guide).forEach(function (s) { add(s.file); });
    if (/\.html$/.test(G.def.page)) add(G.def.page);
    if (G.def.phase === 'css') add('css/style.css');
    return list;
  }

  function renderEditor() {
    var p = $('.editor'), st = currentStep();
    var tabs = tabsList();
    if (tabs.indexOf(G.tab) === -1) G.tab = tabs[0];
    var guide = guideOpen();
    var lineMode = !!(guide && guide.kind === 'line');
    var editable = !lineMode && st && st.kind === 'fix' && G.def.edit.indexOf(G.tab) !== -1;
    p.innerHTML = '';
    var bar = h('<div class="tabs" role="tablist" aria-label="Fichiers"></div>');
    tabs.forEach(function (f) {
      var b = h('<button type="button" role="tab" aria-selected="' + (f === G.tab) + '">' + esc(f) + (G.def.edit.indexOf(f) !== -1 && st && st.kind === 'fix' ? ' ✎' : '') + '</button>');
      b.addEventListener('click', function () { G.tab = f; renderEditor(); });
      bar.appendChild(b);
    });
    bar.appendChild(h('<span class="state">' + (editable ? 'modifiable' : (lineMode ? 'clique sur une ligne' : 'lecture seule')) + '</span>'));
    p.appendChild(bar);
    var src = G.files[G.tab] || '';
    var zone = h('<div class="code-zone' + (editable ? '' : ' read-only') + '"></div>');
    if (lineMode) {
      var lines = h('<div class="lines" role="list"></div>');
      src.split('\n').forEach(function (l, i) {
        var b = h('<button type="button" role="listitem"><span class="n">' + (i + 1) + '</span><span>' + (esc(l) || ' ') + '</span></button>');
        b.addEventListener('click', function () {
          submitGuide(guide, l).then(function (res) { b.classList.add(res.ok ? 'correct' : 'wrong'); });
        });
        lines.appendChild(b);
      });
      zone.appendChild(lines);
    } else {
      var n = src.split('\n').length, nums = '';
      for (var i = 1; i <= n; i++) nums += i + '\n';
      var numEl = h('<div class="line-numbers" aria-hidden="true"></div>'); numEl.textContent = nums;
      var pre = h('<pre aria-hidden="' + (editable ? 'true' : 'false') + '"></pre>'); pre.innerHTML = highlight(G.tab, src);
      zone.appendChild(numEl); zone.appendChild(pre);
      if (editable) {
        var ta = h('<textarea spellcheck="false" autocapitalize="off" autocomplete="off" aria-label="Code de ' + esc(G.tab) + '"></textarea>');
        ta.value = src;
        var file = G.tab, timer = null;
        ta.addEventListener('input', function () {
          G.files[file] = ta.value;
          pre.innerHTML = highlight(file, ta.value);
          var c = ta.value.split('\n').length, s = '';
          for (var k = 1; k <= c; k++) s += k + '\n';
          numEl.textContent = s;
          G.data.drafts[G.ticket.id] = G.data.drafts[G.ticket.id] || {};
          G.data.drafts[G.ticket.id][file] = ta.value; save();
          clearTimeout(timer); timer = setTimeout(refreshPreview, 350);
          markChecksStale();
        });
        ta.addEventListener('scroll', function () { pre.scrollTop = ta.scrollTop; pre.scrollLeft = ta.scrollLeft; numEl.scrollTop = ta.scrollTop; });
        ta.addEventListener('keydown', function (e) {
          if (e.key === 'Tab' && !e.shiftKey) {
            e.preventDefault();
            var s0 = ta.selectionStart, s1 = ta.selectionEnd;
            ta.value = ta.value.slice(0, s0) + '  ' + ta.value.slice(s1);
            ta.selectionStart = ta.selectionEnd = s0 + 2;
            ta.dispatchEvent(new Event('input'));
          }
        });
        zone.appendChild(ta);
      } else {
        pre.addEventListener('scroll', function () { numEl.scrollTop = pre.scrollTop; });
      }
    }
    p.appendChild(zone);
  }

  // ——— Preview and tools ———
  var TOOL_LABEL = { apercu: 'Aperçu', lecteur: 'Lecteur d\'écran', recherche: 'Moteur de recherche', requetes: 'Requêtes' };
  var DEVICE_LABEL = { 375: 'Téléphone', 768: 'Tablette', 1280: 'Ordinateur' };
  function renderPreviewPanel() {
    var p = $('.preview'), st = currentStep();
    p.innerHTML = '';
    var bar = h('<div class="tools" role="tablist" aria-label="Outils"></div>');
    tools().filter(function (t) { return TOOL_LABEL[t]; }).forEach(function (t) {
      var b = h('<button type="button" role="tab" class="tab" aria-selected="' + (G.tool === t) + '">' + TOOL_LABEL[t] + '</button>');
      b.addEventListener('click', function () { G.tool = t; renderPreviewPanel(); });
      bar.appendChild(b);
    });
    var zoom = h('<button type="button" class="tab" aria-pressed="' + (G.zoom ? 'true' : 'false') + '" title="Afficher la page à sa taille réelle, avec des barres de défilement">' + (G.zoom ? 'Ajuster' : '100 %') + '</button>');
    zoom.addEventListener('click', function () { G.zoom = !G.zoom; renderPreviewPanel(); });
    bar.appendChild(zoom);
    var devs = devices();
    if (devs.length > 1) {
      var dv = h('<div class="devices" role="group" aria-label="Largeur d\'écran"></div>');
      devs.forEach(function (d) {
        var b = h('<button type="button" aria-pressed="' + (G.device === d) + '">' + (DEVICE_LABEL[d] || d) + ' · ' + d + '</button>');
        b.addEventListener('click', function () { G.device = d; renderPreviewPanel(); });
        dv.appendChild(b);
      });
      bar.appendChild(dv);
    }
    p.appendChild(bar);
    var addr = h('<div class="address"><span class="url">https://neko-con.lycee.example/' + esc(G.page) + '</span></div>');
    var pages = Object.keys(G.files).filter(function (f) { return /\.html$/.test(f) && f !== 'coulisses.html' || f === G.def.page; });
    var sel = h('<select aria-label="Page affichée" style="font:inherit;font-size:12px;border-radius:999px;border:0;padding:3px 8px"></select>');
    pages.forEach(function (f) { var o = h('<option></option>'); o.value = f; o.textContent = f; if (f === G.page) o.selected = true; sel.appendChild(o); });
    if (pages.indexOf(G.page) === -1) { var o404 = h('<option></option>'); o404.value = G.page; o404.textContent = G.page; o404.selected = true; sel.appendChild(o404); }
    sel.addEventListener('change', function () { G.page = sel.value; renderPreviewPanel(); });
    addr.appendChild(sel);
    p.appendChild(addr);
    var view = h('<div class="view"><div class="frame"><iframe title="Aperçu du site" sandbox="allow-scripts"></iframe></div></div>');
    p.appendChild(view);
    var checksBox = h('<div class="checks"></div>');
    p.appendChild(checksBox);
    renderChecks();
    refreshPreview();
  }

  function fit() {
    var view = $('.view'), box = $('.view .frame'), frame = $('.view iframe');
    if (!view || !frame) return;
    var W = view.clientWidth, H = view.clientHeight, layout = +frame.dataset.layout || G.device;
    var visual = G.zoom ? G.device : Math.min(G.device, W - 16), scale = visual / layout;
    view.style.overflow = G.zoom ? 'auto' : 'hidden';
    if (G.zoom) { box.style.left = '8px'; box.style.transform = 'scale(' + scale + ')'; frame.style.height = Math.max(H, (G.lastSize && G.lastSize.h) || H) + 'px'; return; }
    box.style.left = Math.max(8, (W - visual) / 2) + 'px';
    box.style.transform = 'scale(' + scale + ')';
    frame.style.height = Math.ceil(H / scale) + 'px';
  }
  window.addEventListener('resize', fit);

  var renderSeq = 0;
  function refreshPreview() {
    var frame = $('.view iframe');
    if (!frame) return;
    var seq = ++renderSeq;
    E.render(frame, G.files, G.page, G.device).then(function (r) {
      if (seq !== renderSeq) return;
      G.lastSize = r.size;
      fit();
      E.wire(frame, {
        navigate: function (href) { G.page = href; renderPreviewPanel(); },
        request: function (req) {
          G.requests.unshift(req);
          if (tools().indexOf('requetes') !== -1) { say('ines', 'neutre', 'Formulaire envoyé. Regarde l\'onglet « Requêtes » pour voir ce qui est parti vers le serveur.'); }
          else say('ines', 'neutre', 'Formulaire envoyé. (Le moniteur de requêtes se débloquera bientôt.)');
          if (G.tool === 'requetes') showTool(r);
        },
        invalid: function () { say('ines', 'neutre', 'Le navigateur refuse d\'envoyer : un champ obligatoire ou une valeur ne respecte pas les règles du formulaire.'); }
      });
      showTool(r);
    });
  }

  function showTool(r) {
    var view = $('.view');
    var old = $('.tool-panel', view);
    if (old) old.remove();
    if (G.tool === 'apercu') return;
    var panel = h('<div class="tool-panel"></div>');
    if (G.tool === 'lecteur') {
      r.call('reader').then(function (items) { fillReader(panel, items, r); }).catch(function () {});
    } else if (G.tool === 'recherche') {
      r.call('search').then(function (s) { fillSearch(panel, s); }).catch(function () {});
    } else if (G.tool === 'requetes') {
      fillRequests(panel);
    }
    view.appendChild(panel);
  }

  function fillReader(panel, items, r) {
    var filters = { all: function () { return true; }, headings: function (i) { return i.kind === 'heading'; }, links: function (i) { return i.kind === 'link'; }, landmarks: function (i) { return i.kind === 'landmark'; }, images: function (i) { return i.kind === 'image'; } };
    var labels = { all: 'Tout', headings: 'Titres', links: 'Liens', landmarks: 'Repères', images: 'Images' };
    panel.classList.add('reader');
    panel.appendChild(h('<div style="font-family:var(--font-title);font-size:16px;margin-bottom:8px">Lecteur d\'écran : ce qu\'entend un visiteur non-voyant</div>'));
    var bar = h('<div class="filters" role="group" aria-label="Parcourir par"></div>');
    Object.keys(filters).forEach(function (k) {
      var b = h('<button type="button" aria-pressed="' + (G.reader === k) + '">' + labels[k] + '</button>');
      b.addEventListener('click', function () { G.reader = k; panel.innerHTML = ''; fillReader(panel, items, r); });
      bar.appendChild(b);
    });
    panel.appendChild(bar);
    var ol = h('<ol></ol>'), shown = items.filter(filters[G.reader]);
    shown.forEach(function (it, i) {
      ol.appendChild(h('<li class="' + (it.missing || it.missingLabel ? 'alert' : '') + '"><span class="k">' + (i + 1) + '</span><span>« ' + esc(it.text) + ' »</span></li>'));
    });
    if (!shown.length) ol.appendChild(h('<li><span class="k"></span><span>Rien à annoncer dans cette catégorie.</span></li>'));
    panel.appendChild(ol);
  }

  function fillSearch(panel, s) {
    panel.appendChild(h('<div style="font-family:var(--font-title);font-size:16px;margin-bottom:12px">Votre page dans un moteur de recherche</div>'));
    panel.appendChild(h('<div class="serp"><div class="u">neko-con.lycee.example › ' + esc(G.page) + '</div><div class="t">' + esc(s.title || G.page) + '</div><div class="d">' + (s.description ? esc(s.description) : '<em>Aucune description : le moteur prend un bout de texte au hasard dans la page.</em>') + '</div></div>'));
    panel.appendChild(h('<p class="note" style="color:#cfc8e6;margin-top:12px">Le titre vient de la balise &lt;title&gt;, la description de &lt;meta name="description"&gt;.</p>'));
  }

  function fillRequests(panel) {
    panel.appendChild(h('<div style="font-family:var(--font-title);font-size:16px;margin-bottom:12px">Moniteur de requêtes</div>'));
    if (!G.requests.length) panel.appendChild(h('<p class="note" style="color:#cfc8e6">Aucune requête. Remplis un formulaire dans l\'aperçu et envoie-le : la requête apparaîtra ici.</p>'));
    G.requests.forEach(function (q) {
      var rows = q.fields.map(function (f) { return '<tr><td>' + esc(f[0]) + '</td><td>' + esc(f[1] || '(vide)') + '</td></tr>'; }).join('');
      panel.appendChild(h('<div class="http-request"><div><span class="m">' + q.method + '</span>' + esc(q.url) + '</div>' +
        (q.method === 'POST' ? '<div style="color:#a39cbc;margin-top:4px">Corps de la requête : ' + esc(q.body || '(vide)') + '</div>' : '') +
        '<table>' + (rows || '<tr><td>Aucun champ envoyé.</td></tr>') + '</table>' +
        (q.unnamed.length ? '<div style="color:#ffd166;margin-top:6px">Champs sans attribut name, donc jamais envoyés : ' + esc(q.unnamed.join(', ')) + '</div>' : '') + '</div>'));
    });
  }

  // ——— Checking and delivery ———
  function renderChecks() {
    var v = $('.checks'), st = currentStep();
    if (!v) return;
    v.innerHTML = '';
    if (!G.def.checks.length && !(G.def.forbid || []).length && !(G.def.locked || []).length) { v.style.display = 'none'; return; }
    v.style.display = '';
    var ul = h('<ul aria-label="Critères de vérification"></ul>');
    ul.appendChild(h('<li class="checks-title" style="display:block">Ce que ' + esc(nameOf(G.ticket.requester, G.ticket.from)) + ' vérifiera</li>'));
    var labels = G.def.checks.map(function (c) { return c.label; })
      .concat((G.def.forbid || []).map(function (f) { return f.label; }))
      .concat((G.def.locked || []).map(function (l) { return l.label || 'La règle ' + l.rule + ' n\'a pas été modifiée'; }));
    labels.forEach(function (lab, i) {
      var r = G.results && G.results[i];
      ul.appendChild(h('<li class="' + (r ? (r.ok ? 'ok' : 'ko') : '') + '"><span class="dot" aria-hidden="true"></span><span>' + esc(lab) + (r ? (r.ok ? ' <span class="sr-only">(validé)</span>' : ' <span class="sr-only">(pas encore)</span>') : '') + (r && !r.ok && r.detail ? '<br><span class="detail">' + esc(r.detail) + '</span>' : '') + '</span></li>'));
    });
    v.appendChild(ul);
    if (st && st.kind === 'fix') {
      var b = h('<button type="button" class="btn btn-pink" style="flex:none">Livrer à ' + esc(nameOf(G.ticket.requester, G.ticket.from)) + '</button>');
      b.addEventListener('click', deliver);
      v.appendChild(b);
    }
  }
  function markChecksStale() { if (G.results) { G.results = null; renderChecks(); } }

  function deliver() {
    var st = currentStep();
    var btn = $('.checks .btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Vérification…'; }
    E.check(G.def, G.files, $('.test-frame')).then(function (results) {
      G.results = results;
      var failed = results.filter(function (r) { return !r.ok; }).map(function (r) { return r.label; });
      var delivered = {};
      G.def.edit.forEach(function (f) { delivered[f] = G.files[f]; });
      renderChecks();
      return submit(st, { passed: failed.length === 0, failed: failed, files: delivered }).then(function (res) {
        if (!res.ok) {
          var who = G.ticket.requester === 'client' ? 'ines' : G.ticket.requester;
          say(who, who === 'ines' ? 'sourcil' : 'inquiete', 'Hmm… ça ne va pas encore : ' + failed[0].charAt(0).toLowerCase() + failed[0].slice(1) + '.');
          G.failed++;
          if (G.failed === 2 && !G.hints && rungs()) wait(3500).then(function () { say('ines', 'neutre', 'Si tu tournes en rond, demande-moi de l\'aide : je te poserai les bonnes questions.'); });
        }
      });
    }).catch(function (e) {
      say('ines', 'sourcil', 'La vérification a planté : ' + e.message);
      renderChecks();
    });
  }

  // ——— End of a ticket ———
  function finishTicket() {
    renderLeft();
    api('endTicket', { session: G.session, hints: G.hints }).then(function (res) {
      delete G.data.drafts[G.ticket.id]; save(true);
      SND.stamp();
      var stamp = h('<div class="stamp"><span class="' + (res.status === 'reassigned' ? 'reassigned' : '') + '">' + (res.status === 'reassigned' ? 'RÉAFFECTÉ' : 'RÉSOLU') + '</span></div>');
      app.appendChild(stamp);
      return wait(1300).then(function () {
        stamp.remove();
        return G.def.done && G.def.done.length ? playScene(G.def.done, { decor: 'club' }) : null;
      }).then(function () {
        if (res.fragment || res.clue) return rewardWindow(res);
      }).then(function () {
        return rewardsScene();
      }).then(function () {
        if (res.bascule) return playBascule();
      }).then(function () {
        if (res.finished) { G.sc.finished = true; return playFinal(); }
        showBoard((res.newCards || []).map(function (c) { return c.id; }));
      });
    }).catch(function (e) { say('ines', 'sourcil', 'Erreur de la plateforme : ' + e.message); });
  }

  function rewardWindow(res) {
    return new Promise(function (resolve) {
      if (res.fragment) SND.fragment(); else SND.notify();
      var body = '';
      if (res.fragment) body += '<p>Morceau n° ' + res.fragment.index + ' du mot de passe de Jordan :</p><div class="notebook-slots"><span class="slot ok" style="font-size:22px;height:56px;min-width:120px">' + esc(res.fragment.value) + '</span></div><p class="note">' + esc(res.fragment.where || '') + '</p>';
      if (res.clue) body += '<p><strong>Nouvel indice au carnet :</strong> ' + esc(res.clue) + '</p>';
      var w = h('<div class="overlay"><div class="window" role="dialog" aria-modal="true"><h2>' + (res.fragment ? 'Un morceau du mot de passe !' : 'Un indice pour l\'enquête') + '</h2>' + body + '<div class="actions"><button type="button" class="btn btn-pink">Noté dans le carnet</button></div></div></div>');
      $('.btn', w).addEventListener('click', function () { w.remove(); resolve(); });
      app.appendChild(w); $('.btn', w).focus();
    });
  }

  // The collection pieces the platform granted at the closing of a ticket (SGSdk.onReward): a card
  // per act done, the Badge Staff and its Neko pin once the site is online, its finish raised by the
  // tickets with three stars. Drawn by the platform's shared module.
  function rewardsScene() {
    var list = G.rewards.splice(0);
    if (!list.length || !window.SGRewards) return Promise.resolve();
    return new Promise(function (resolve) {
      SND.fanfare();
      var FINISH = { brass: 'laiton', silver: 'argent', gold: 'or' };
      var items = list.map(function (r) {
        var art = window.SGRewards.art(r.kind, r.code, { size: r.kind === 'card' ? 84 : 72, finish: r.finish || undefined });
        var label = esc(r.name) + (r.finish ? ' · ' + (FINISH[r.finish] || r.finish) : '') + (r.raised ? ' (finition relevée)' : '');
        return '<li class="piece">' + art + '<span>' + label + '</span></li>';
      }).join('');
      var w = h('<div class="overlay"><div class="window rewards" role="dialog" aria-modal="true" aria-labelledby="rewardsTitle"><h2 id="rewardsTitle">' + (list.length > 1 ? 'Nouvelles pièces de collection' : 'Nouvelle pièce de collection') + '</h2><ul class="pieces">' + items + '</ul><p class="note">Elles rejoignent ta vitrine, sur ta page joueur.</p><div class="actions"><button type="button" class="btn btn-pink">Super !</button></div></div></div>');
      $('.btn', w).addEventListener('click', function () { w.remove(); resolve(); });
      app.appendChild(w); $('.btn', w).focus();
    });
  }

  // ——— The bascule: the site gets its colours ———
  function playBascule() {
    var before = Object.assign({}, window.NekoSite.files);
    E.applyMutations(before, [{ file: '*.html', find: 'href="css/style.css"', replace: 'href="css/styles.css"' }]);
    var after = Object.assign({}, window.NekoSite.files);
    return playScene(STORY.bascule, {
      decor: 'club', delay: 4600,
      setup: function (scene) {
        scene.appendChild(h('<div class="bascule-rays" aria-hidden="true"></div>'));
        var site = h('<div class="bascule-site" aria-label="Le site prend ses couleurs"><div class="layer before"><iframe title="Avant" sandbox="allow-scripts" tabindex="-1"></iframe></div><div class="layer after"><iframe title="Après" sandbox="allow-scripts" tabindex="-1"></iframe></div></div>');
        scene.appendChild(site);
        scene.appendChild(h('<div class="bascule-code">&lt;link rel="stylesheet" href="<s>css/styles.css</s> <b>css/style.css</b>"&gt;</div>'));
        scene.appendChild(h('<div class="bascule-flash" aria-hidden="true"></div>'));
        var frames = $$('iframe', site), w = site.clientWidth;
        [[frames[0], before], [frames[1], after]].forEach(function (pair) {
          E.render(pair[0], pair[1], 'index.html', 1280).then(function () {
            var scale = w / 1280;
            pair[0].style.transform = 'scale(' + scale + ')';
            pair[0].style.height = Math.ceil(site.clientHeight / scale) + 'px';
          });
        });
        setTimeout(function () { SND.bascule(); }, 1400);
        var right = $('.character.right', scene);
        right.style.display = 'none';
      }
    });
  }

  // ——— End of the game ———
  function playFinal() {
    SND.fanfare();
    return playScene(STORY.final, { decor: 'hall-lit', card: { num: 'SAMEDI', title: 'Ouverture des portes' }, delay: 1500 }).then(showEnd);
  }
  function showEnd() {
    refreshScenario().then(function (sc) {
      app.innerHTML = '';
      var view = h('<section class="title-screen"></section>');
      view.appendChild(h(hallSVG(true)));
      var resolved = sc.cards.filter(function (c) { return c.status === 'done'; });
      var stars = resolved.reduce(function (s, c) { return s + (c.quality || 0); }, 0);
      var skills = Object.keys(STORY.skills).map(function (k) { return '<li>' + esc(STORY.skills[k]) + '</li>'; }).join('');
      var cert = h('<div class="overlay" style="position:absolute"><div class="certificate" role="dialog" aria-labelledby="certificateTitle">' + (G.user ? '<div class="certificate-avatar">' + avatar(88) + '</div>' : '') + '<h2 id="certificateTitle">Attestation de stage · Neko-Con</h2>' +
        '<p>Le site de la convention est en ligne. ' + resolved.length + ' tickets résolus, ' + stars + ' étoiles sur ' + (resolved.length * 3) + '.</p>' +
        '<h3>Compétences mobilisées</h3><ul>' + skills + '</ul>' +
        '<p class="note">Signé : Maëlle, présidente du club manga · Inès, tutrice</p>' +
        '<div class="actions" style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap"><button type="button" class="btn btn-white" id="replayEndBtn">Revoir la fin</button><button type="button" class="btn btn-white" id="boardBtn">Revoir le tableau Kanban</button><button type="button" class="btn btn-pink" id="newGameBtn">Nouvelle partie</button></div>' +
        '<p class="note" id="newGameNote">Une nouvelle partie remet les tickets à zéro. Tes pièces de collection, ton grade et ton historique restent.</p></div></div>');
      view.appendChild(cert);
      app.appendChild(view);
      $('#replayEndBtn').addEventListener('click', function () { playFinal(); });
      $('#boardBtn').addEventListener('click', function () { showFinishedBoard(); });
      $('#newGameBtn').addEventListener('click', newGame);
    });
  }
  // « Nouvelle partie » (§3.5): the platform puts the tickets back to zero, the game its display state.
  function newGame() {
    var b = $('#newGameBtn');
    if (b) b.disabled = true;
    api('newGame').then(function () {
      G.data = { seen: {}, drafts: {}, hints: {}, mute: SND.isMuted(), prologue: false };
      save(true);
      return refreshScenario();
    }).then(showTitle).catch(function (e) {
      if (b) b.disabled = false;
      notice('Impossible de recommencer la partie : ' + e.message);
    });
  }
  function showFinishedBoard() {
    app.innerHTML = '';
    app.appendChild(topBar());
    var board = h('<main class="kanban"><section class="column" style="grid-column:1/-1"><h2>Tous les tickets</h2></section></main>');
    var col = $('.column', board);
    G.sc.cards.forEach(function (c) { col.appendChild(cardEl(c)); });
    var back = h('<button type="button" class="btn btn-pink" style="align-self:flex-start">Retour à l\'attestation</button>');
    back.addEventListener('click', showEnd);
    col.appendChild(back);
    app.appendChild(board);
  }

  // ——— Start-up ———
  window.SGSdk.onReward(function (rewards) { G.rewards = G.rewards.concat(rewards); });
  Promise.all([api('getProgress'), api('getScenario'), api('getUser')]).then(function (r) {
    G.user = r[2] || null;
    var data = r[0].data || {};
    G.data = Object.assign(G.data, data);
    ['seen', 'drafts', 'hints'].forEach(function (k) { G.data[k] = G.data[k] || {}; });
    SND.setMuted(!!G.data.mute);
    G.sc = r[1];
    showTitle();
  }).catch(function (e) {
    app.innerHTML = '<p style="color:#fff;padding:40px">Le jeu n\'arrive pas à joindre la plateforme : ' + esc(e.message) + '</p>';
  });
})();
