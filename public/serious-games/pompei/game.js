/* Les Tablettes de Pompéi — the game (design/validated/serious-games.md §1, §6, §8;
   design/validated/serious-games-pompei.md).

   Pompéi, an 78. Julia Felix has a villa built; the architect Caius Vibius Rufus dictates his
   instructions in Latin, and the apprentice traces them on wax tablets. Each tablet is one notion
   of the declensions: a series of 12 inscriptions - QCM, a form to type, a table to fill - traces
   it from 6/12, and its level is its best series (ébauchée 6-7, nette 8-9, gravée 10-11, dorée 12):
   it never goes down. A room is built when all its tablets are traced, and its frescoes are
   painted. A typical error made twice opens a crack (« lézarde ») in a wall, repaired in 3
   inscriptions. When the whole villa is traced, the Lararium opens: 12 inscriptions from every
   room, in the time the server gives, corrected at the end.

   The platform generates and corrects: this page never sees an answer before it is corrected, and
   writes nothing but its own display state (saveProgress). Nothing depends on time or absence:
   the wax never softens. The Lararium's clock is the platform's - shown in its bar, kept here only
   to close the session when it runs out.

   Screens: home (workshop, plan of the villa, the architect) · list · run (a series, a crack or
   the Lararium) · seriesEnd · cometIntro · cometEnd · finalIntro · review · library; the
   glossary and the scratch tablet open over any screen. */
(function () {
  'use strict';

  var SDK = window.SGSdk, Scene = window.POMScene, LIB = window.POMLibrary, esc = Scene.esc, E = window.SGEngage;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- wording ---------- */

  var LEVEL = { discrete: 'ébauchée', clear: 'nette', bright: 'gravée', blazing: 'dorée' };
  var PLURALS = { blazing: ['dorée', 'dorées'], bright: ['gravée', 'gravées'], clear: ['nette', 'nettes'], discrete: ['ébauchée', 'ébauchées'] };
  var ARCHITECT = 'Caius Vibius Rufus, architecte';

  var PROLOGUE = [
    '<i>Salve !</i> Je suis Caius Vibius Rufus, architecte. Julia Felix me confie sa villa, et moi je te confie les tablettes.',
    'Chaque tablette est une notion de grammaire. Je dicte en latin, tu traces. Douze inscriptions par tablette : six justes suffisent pour la tracer.',
    'Une tablette ne s’efface jamais : ta meilleure série reste ta meilleure série. Choisis une pièce sur le plan. Le glossaire t’explique mes mots latins, la bibliothèque garde tous les tableaux.'
  ];
  var BEATS = {
    7: ['Te voilà Dessinateur·rice. Tu ne recopies plus : tu lis le génitif avant de décliner, comme un architecte lit le terrain avant de creuser.',
      'Si une même erreur revient deux fois, une lézarde s’ouvre dans un mur. Elle se répare en trois inscriptions, et rien ne s’effondre en attendant.'],
    14: ['Métreur·euse ! La moitié de la villa sort de terre.',
      'Julia Felix est passée ce matin. Elle a regardé les fresques sans rien dire, ce qui, chez elle, est un compliment.'],
    21: ['Maître·sse d’œuvre adjoint·e. Au centre du plan, une pièce reste hachurée.',
      'Je ne te dirai pas encore ce qu’elle cache. Trace les dernières tablettes.'],
    all: ['La villa est tracée. Au centre du plan, le lararium apparaît : l’autel des Lares, les dieux de la maison.',
      'Julia Felix viendra elle-même lire ses inscriptions : douze, tirées de toutes les pièces, corrigées seulement à la fin. Tu peux t’y essayer autant de fois que tu voudras. Et prends ma groma : elle est à toi.']
  };
  var GREET = [
    'Le plan est presque vierge. Choisis une pièce : douze inscriptions suffisent pour tracer une tablette.',
    'Tu connais maintenant le chemin de l’atelier. Le plan commence à te ressembler.',
    'La moitié de la villa est debout. Aller vite ou lentement ne change rien au tracé d’une tablette.',
    'Il reste peu de tablettes vierges, et ce sont souvent les plus difficiles. Prends le temps qu’il faut.',
    'La villa est tracée. Au centre, le lararium attend.'
  ];
  var AFTER_ERROR = [
    'La cire se lisse et se regrave : une inscription manquée n’efface aucune tablette.',
    'Moi aussi, j’ai longtemps écrit regum pour regis. Relis toujours le génitif.',
    'Une erreur ne retire rien à ta meilleure série.'
  ];
  // what each level is called by the gauge of a series in progress (shared/engage.js)
  var GAUGE_LEVELS = {
    6: ['tracer la tablette', 'La tablette est tracée !'],
    8: ['le niveau nette', 'Niveau nette atteint !'],
    10: ['le niveau gravée', 'Niveau gravée atteint !'],
    12: ['le niveau dorée', 'Douze sur douze : dorée !']
  };
  var BESTIARY = 'Registre des lézardes';
  var AFTER_LIT = ['Tablette tracée : je la range dans le coffre du tablinum.', 'Un mur de plus sur le plan.', 'Julia Felix aime voir les plans avancer.'];

  // What to type, per answer type (never a hint about the answer itself)
  var TYPING = {
    latin: { help: 'Une forme latine. Les macrons sont facultatifs : rosa et rosā, c’est la même réponse.', keys: ['ā', 'ē', 'ī', 'ō', 'ū'] },
    forms: { help: 'Une forme par case. Les macrons sont facultatifs.', keys: ['ā', 'ē', 'ī', 'ō', 'ū'] },
    choice: { help: 'Choisis une proposition (touches 1 à 4), puis valide.', keys: [] },
    text: { help: 'Un mot ou une réponse courte.', keys: [] }
  };
  var ERRORS = {
    network: 'La liaison avec Formatool est coupée. Vérifie la connexion, puis réessaie.',
    timeout: 'Formatool ne répond pas. Ce jeu s’ouvre depuis la page Serious games de Formatool.',
    'unit-unavailable': 'Cette tablette n’est pas disponible pour le moment.',
    'final-locked': 'Le lararium n’apparaît qu’une fois toutes les tablettes tracées.',
    'comet-not-in-view': 'Cette lézarde est déjà réparée.',
    'session-ended': 'Cette série est déjà terminée.',
    'session-unknown': 'Cette série n’existe plus.',
    'data-too-large': 'Le plan est trop chargé pour être rangé.'
  };
  var ROOM_ORDER = ['peristylium', 'hortus', 'triclinium', 'thermae', 'atrium', 'tablinum'];

  /* ---------- state ---------- */

  var G = {
    user: null,
    progress: null,
    data: {},            // display state only, saved with saveProgress
    screen: 'home',
    room: null,          // the room whose panel is open on the main screen
    selected: null,      // the tablet whose card is open
    dlg: null,
    run: null,
    busy: false,
    fresh: null,         // a tablet traced by the last series, drawn once
    confirmEnd: false,
    rewards: [],
    endRes: null,
    libTab: 'declinaisons',
    glossTab: 'villa'
  };
  var app = document.getElementById('app'), live = document.getElementById('live'), layer = document.getElementById('layer'), overlay = document.getElementById('overlay');
  var clockTimer = null;

  /* ---------- the SDK, with a timeout ---------- */

  function call(method, params) {
    return new Promise(function (resolve, reject) {
      var done = false;
      var t = setTimeout(function () { if (!done) { done = true; reject(new Error('timeout')); } }, 25000);
      SDK.call(method, params || {}).then(function (r) { if (!done) { done = true; clearTimeout(t); resolve(r); } },
        function (e) { if (!done) { done = true; clearTimeout(t); reject(e); } });
    });
  }
  function errorText(e) {
    var code = e && e.message ? e.message : 'error';
    return ERRORS[code] || 'Une erreur est survenue (' + code + '). Réessaie dans un instant.';
  }
  function toast(msg) {
    var old = document.querySelector('.toast');
    if (old) old.remove();
    var t = document.createElement('div');
    t.className = 'toast';
    t.setAttribute('role', 'alert');
    t.innerHTML = '<span>' + esc(msg) + '</span><button type="button" class="btn btn--small" data-act="toast-close">Fermer</button>';
    document.body.appendChild(t);
  }
  function announce(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30); }
  function save() { call('saveProgress', { data: G.data }).catch(function () { /* the display state can wait */ }); }

  /* ---------- helpers ---------- */

  function lowerFirst(s) { return s ? s.charAt(0).toLowerCase() + s.slice(1) : s; }
  function typo(s) { return String(s === null || s === undefined ? '' : s).replace(/'/g, '’'); }
  function unitOf(code) { return (G.progress.units || []).filter(function (u) { return u.code === code; })[0] || null; }
  function chapterOf(code) { return (G.progress.chapters || []).filter(function (c) { return c.code === code; })[0] || null; }
  function chapterName(unit) { var c = unit && chapterOf(unit.chapter); return c ? c.label : ''; }
  function plain(html) { var d = document.createElement('div'); d.innerHTML = String(html || ''); return (d.textContent || '').replace(/\s+/g, ' ').trim(); }
  function minutes(sec) { return Math.round(sec / 60); }
  function pick(list, seed) { return list[Math.abs(seed) % list.length]; }
  function deW(w) { return (/^[aeiouyhéèêàâîôû]/i.test(w) ? 'd’' : 'de ') + w; }
  function stateOf(u) { return u.lit ? u.brightness : u.series > 0 ? 'tried' : 'never'; }
  function levelWord(u) { return u.lit ? LEVEL[u.brightness] : u.series > 0 ? 'tentée' : 'vierge'; }
  function nextLevel(best) {
    if (best === null || best === undefined || best < 6) return 'tracée, dès 6/12';
    if (best < 8) return 'nette, dès 8/12';
    if (best < 10) return 'gravée, dès 10/12';
    if (best < 12) return 'dorée, à 12/12';
    return 'au plus haut : dorée';
  }
  function tabletName(u) {
    var name = typo(u.label);
    if (u.lit) return name + ' — tablette ' + LEVEL[u.brightness] + ' (meilleure série ' + u.best + '/12)';
    if (u.series > 0 && u.best !== null) return name + ' — pas encore tracée (meilleure série ' + u.best + '/12)';
    return name + ' — vierge';
  }
  function levelsLabel() {
    var c = { blazing: 0, bright: 0, clear: 0, discrete: 0 };
    G.progress.units.forEach(function (u) { if (u.lit && !u.hidden && c[u.brightness] !== undefined) c[u.brightness]++; });
    var parts = ['blazing', 'bright', 'clear', 'discrete'].filter(function (k) { return c[k]; }).map(function (k) { return c[k] + ' ' + PLURALS[k][c[k] > 1 ? 1 : 0]; });
    return parts.length ? parts.join(' · ') : 'aucune tablette tracée';
  }
  function cometsIn(chapter) {
    return (G.progress.comets || []).filter(function (c) { var u = unitOf(c.unit); return u && u.chapter === chapter; });
  }
  function isBuilt(ch) { return ch.total > 0 && ch.lit >= ch.total; }

  /* The tablet the architect suggests: an untraced tablet of the room furthest along (the one
     already nearest to 6; on a tie, the rooms in the order of the villa, the Atrium first), else
     the least advanced traced tablet. */
  function suggestion() {
    var best = null;
    G.progress.chapters.forEach(function (ch) {
      var off = G.progress.units.filter(function (u) { return u.chapter === ch.code && !u.hidden && !u.lit; });
      if (!off.length) return;
      var share = ch.total ? ch.lit / ch.total : 0;
      if (!best || share > best.share) {
        var sorted = off.slice().sort(function (a, b) { return (b.best || 0) - (a.best || 0); });
        best = { unit: sorted[0], share: share };
      }
    });
    if (best) return { unit: best.unit, kind: 'trace' };
    var dim = G.progress.units.filter(function (u) { return !u.hidden && (u.best || 0) < 12; }).sort(function (a, b) { return (a.best || 0) - (b.best || 0); })[0];
    return dim ? { unit: dim, kind: 'better' } : null;
  }
  function tabletLine(u) {
    var head = '<b>' + esc(typo(u.label)) + '</b>, ' + esc(chapterName(u)) + '. ';
    if (u.lit) return head + 'Tablette ' + LEVEL[u.brightness] + ' : ta meilleure série est de ' + u.best + '/12.';
    if (u.series > 0) return head + 'Pas encore tracée : ta meilleure série est de ' + u.best + '/12. Il en faut 6.';
    return head + 'Tablette vierge. Six inscriptions justes sur douze la tracent.';
  }

  /* ---------- the top bar ---------- */

  function topbar(sub, right) {
    return '<header class="topbar"><div class="topbar__title"><h1 class="topbar__name">Les Tablettes de Pompéi</h1><span class="topbar__sub">' + esc(sub) + '</span></div>' +
      '<div class="topbar__right">' + right + E.sound.button() + '</div></header>';
  }
  function tabletsCount() {
    return '<span class="count" aria-label="' + G.progress.lit + ' tablettes tracées sur ' + G.progress.total + '"><b>' + G.progress.lit + '</b>/' + G.progress.total + ' TABLETTES</span>';
  }
  function glossaryButton() { return '<button type="button" class="btn btn--small" data-act="glossary" aria-haspopup="dialog">Glossaire</button>'; }

  /* ---------- rendering ---------- */

  function show(html, focusSelector) {
    stopClock();
    app.innerHTML = html;
    if (focusSelector === false) return; // the first screen: the frame does not take the focus from the page
    var target = focusSelector && app.querySelector(focusSelector);
    if (!target) target = app.querySelector('[data-autofocus]') || app.querySelector('h2[tabindex], .screen');
    window.scrollTo(0, 0);
    if (target) {
      try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
      var r = target.getBoundingClientRect();
      if (r.bottom > window.innerHeight || r.top < 0) target.scrollIntoView({ block: 'nearest' });
    }
  }

  /* ---------- 1. the main screen: the workshop, the plan, the architect ---------- */

  function homeDialog() {
    var p = G.progress, read = G.data.read || [], lines, id = null;
    if (read.indexOf('prologue') < 0) {
      lines = PROLOGUE.slice(); id = 'prologue';
    } else if (p.finished && read.indexOf('gall') < 0) {
      lines = BEATS.all.slice(); id = 'gall';
    } else {
      [21, 14, 7].some(function (th) {
        if (p.lit >= th && read.indexOf('g' + th) < 0) { lines = BEATS[th].slice(); id = 'g' + th; return true; }
        return false;
      });
    }
    var sg = suggestion();
    if (!lines) {
      var stage = p.finished ? 4 : p.lit >= 21 ? 3 : p.lit >= 14 ? 2 : p.lit >= 7 ? 1 : 0;
      lines = [GREET[stage]];
      if (p.final.unlocked) {
        lines.push(p.final.best === 12 ? 'Julia Felix a lu tout le lararium sans une faute. Tu peux y revenir pour le plaisir.' : 'Le lararium : douze inscriptions de toute la villa, ' + minutes(p.final.timeLimitSeconds) + ' minutes, et la correction seulement à la fin.');
      } else if (sg) {
        lines.push((sg.kind === 'trace' ? 'La tablette la plus proche d’être tracée : ' : 'Tout est tracé. La tablette la moins avancée : ') + tabletLine(sg.unit));
      }
      if (p.comets.length) lines.push('Une lézarde s’est ouverte dans un mur. Elle vient d’une erreur qui revient ; tu peux la réparer maintenant, ou plus tard.');
    }
    var acts = [];
    var open = G.data.open;
    if (open && open.session) acts.push({ label: open.mode === 'final' ? 'Reprendre le lararium' : open.mode === 'comet' ? 'Reprendre la réparation' : 'Reprendre : ' + typo((unitOf(open.unit) || {}).label || 'la série'), primary: true, act: 'resume' });
    if (p.final.unlocked) acts.push({ label: 'Le lararium', primary: !acts.length, act: 'final-intro' });
    else if (sg) acts.push({ label: 'Tracer : ' + typo(sg.unit.label), primary: !acts.length, act: 'series', arg: sg.unit.code });
    if (p.comets.length) acts.push({ label: 'Réparer la lézarde', act: 'comet', arg: p.comets[0].misconception });
    acts.push({ label: 'Toutes les tablettes', act: 'list' });
    acts.push({ label: 'Bibliothèque', act: 'library' });
    return { who: ARCHITECT, lines: lines, i: 0, id: id, actions: acts };
  }

  function dialogHtml() {
    var d = G.dlg, last = d.i >= d.lines.length - 1, acts = '';
    if (!last) acts = '<button type="button" class="btn btn--outline" data-act="dlg-next" data-autofocus>Suivant</button>';
    else acts = d.actions.map(function (a, k) {
      return '<button type="button" class="btn ' + (a.primary ? 'btn--primary' : 'btn--small') + '"' + (a.disabled ? ' disabled' : ' data-act="' + a.act + '"' + (a.arg ? ' data-arg="' + esc(a.arg) + '"' : '')) + (k === 0 ? ' data-autofocus' : '') + '>' + esc(a.label) + '</button>';
    }).join('');
    return '<div class="dlg"><div class="dlg__body"><div class="dlg__who"><b>' + esc(d.who) + '</b><span>' + (d.i + 1) + ' / ' + d.lines.length + '</span></div>' +
      '<p class="dlg__text">' + d.lines[d.i] + '</p></div><div class="dlg__acts">' + acts + '</div></div>';
  }

  function roomPanelHtml(ch) {
    var units = G.progress.units.filter(function (u) { return u.chapter === ch.code; });
    var cracks = cometsIn(ch.code);
    var cards = units.map(function (u) {
      if (u.hidden) return '<li class="tabcard is-hidden">' + Scene.tablet(null, 34) + '<b>' + esc(typo(u.label)) + '</b><span class="tabcard__state">indisponible</span></li>';
      return '<li><button type="button" class="tabcard' + (G.selected === u.code ? ' is-selected' : '') + '" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(tabletName(u)) + '">' + Scene.tablet(stateOf(u), 34) +
        '<b>' + esc(typo(u.label)) + '</b><span class="tabcard__state">' + esc(levelWord(u).toUpperCase()) + '<em>' + (u.best === null || u.best === undefined ? '—' : u.best + '/12') + '</em></span></button></li>';
    }).join('');
    return '<div class="roompanel"><div class="roompanel__head"><h2 class="roompanel__name" tabindex="-1">' + esc(ch.label) + '</h2><span class="kicker">' + esc(ch.topic.toUpperCase()) + '</span>' +
      '<span class="kicker">' + ch.lit + '/' + ch.total + ' TRACÉE' + (ch.lit > 1 ? 'S' : '') + (isBuilt(ch) ? ' · PIÈCE BÂTIE' : '') + '</span>' +
      cracks.map(function (c) { return '<button type="button" class="btn btn--crack" data-act="comet" data-arg="' + esc(c.misconception) + '">' + Scene.crack(20) + ' Lézarde · Réparer</button>'; }).join('') +
      '<button type="button" class="btn btn--small" data-act="deselect">Retour à l’architecte <span class="kbd">Échap</span></button></div>' +
      '<ul class="tabcards">' + cards + '</ul></div>';
  }

  function tabletCardHtml(u) {
    var ch = chapterOf(u.chapter);
    return '<div class="tabletcard">' + Scene.tablet(stateOf(u), 92) + '<div><div class="kicker">' + esc(ch ? ch.label.toUpperCase() : '') + '</div><h2 class="tabletcard__name" tabindex="-1">' + esc(typo(u.label)) + '</h2>' +
      '<dl class="facts"><dt>Tracé</dt><dd>' + esc(levelWord(u)) + '</dd><dt>Meilleure série</dt><dd class="num">' + (u.best === null || u.best === undefined ? '—' : u.best + '/12') + '</dd>' +
      '<dt>Prochain niveau</dt><dd>' + esc(nextLevel(u.best)) + '</dd></dl></div>' +
      '<div class="tabletcard__acts"><button type="button" class="btn btn--primary" data-act="series" data-arg="' + esc(u.code) + '" data-autofocus>Tracer : 12 inscriptions <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn btn--small" data-act="room" data-arg="' + esc(u.chapter) + '">Retour à la pièce <span class="kbd">Échap</span></button></div></div>';
  }

  function planHtml() {
    var p = G.progress, rooms = {};
    p.chapters.forEach(function (ch) { rooms[ch.code] = ch; });
    var cells = ROOM_ORDER.filter(function (code) { return rooms[code]; }).map(function (code) {
      var ch = rooms[code], built = isBuilt(ch);
      var tabs = p.units.filter(function (u) { return u.chapter === code; }).map(function (u) {
        if (u.hidden) return '<span class="plan__tab is-hidden">' + Scene.tablet(null, 28) + '</span>';
        return '<button type="button" class="plan__tab' + (G.fresh === u.code ? ' is-fresh' : '') + (G.selected === u.code ? ' is-selected' : '') + '" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(tabletName(u)) + '">' + Scene.tablet(stateOf(u), 28) + '</button>';
      }).join('');
      var cracks = cometsIn(code).map(function (c) {
        return '<button type="button" class="plan__crack" data-act="comet" data-arg="' + esc(c.misconception) + '" aria-label="Lézarde : ' + esc(typo(c.label)) + ' — la réparer">' + Scene.crack(30) + '</button>';
      }).join('');
      return '<div class="room room--' + code + (built ? ' is-built' : '') + (G.room === code ? ' is-selected' : '') + '">' +
        '<button type="button" class="room__head" data-act="room" data-arg="' + code + '" aria-label="' + esc(ch.label + ' : ' + ch.lit + ' tablettes tracées sur ' + ch.total + (built ? ', pièce bâtie' : '')) + '"><span class="room__name">' + esc(ch.label.replace(/^(L’|L'|Le |La |Les )/, '')) + '</span><span class="room__count">' + ch.lit + '/' + ch.total + '</span></button>' +
        '<div class="room__tabs">' + tabs + '</div>' + cracks + '</div>';
    }).join('');
    var f = p.final;
    var lararium = f.unlocked
      ? '<button type="button" class="room room--lararium is-open" data-act="final-intro" aria-label="Le lararium — l’épreuve' + (f.best !== null && f.best !== undefined ? ', meilleur score ' + f.best + '/12' : '') + '"><span class="lar__icon" aria-hidden="true">' + larIcon() + '</span><span class="room__name">Lararium</span>' + (f.best !== null && f.best !== undefined ? '<span class="room__count">' + f.best + '/12</span>' : '') + '</button>'
      : '<div class="room room--lararium" aria-label="Pièce hachurée : elle apparaît quand toute la villa est tracée"><span class="lar__q" aria-hidden="true">?</span></div>';
    G.fresh = null;
    return '<div class="plan" role="group" aria-label="Plan de la villa : ' + p.lit + ' tablettes tracées sur ' + p.total + '"><div class="plan__grid">' + cells + lararium + '</div><span class="plan__fauces">FAUCES</span></div>';
  }
  function larIcon() {
    return '<svg viewBox="0 0 40 34" width="40" height="34"><path d="M6 12 L20 3 L34 12 Z" fill="#C9923E"/><rect x="9" y="12" width="4" height="15" fill="#EFE3CB"/><rect x="27" y="12" width="4" height="15" fill="#EFE3CB"/><rect x="16" y="17" width="8" height="10" fill="#EFE3CB"/><rect x="6" y="27" width="28" height="3" fill="#EFE3CB"/><path d="M8 33 q3 -3 6 0 t6 0 t6 0 t6 0" stroke="#C9923E" stroke-width="1.6" fill="none"/></svg>';
  }

  function renderHome(focusSel) {
    G.screen = 'home';
    G.confirmEnd = false;
    if (!G.dlg) G.dlg = homeDialog();
    var u = G.selected ? unitOf(G.selected) : null, ch = !u && G.room ? chapterOf(G.room) : null;
    var panel = u ? tabletCardHtml(u) : ch ? roomPanelHtml(ch) : dialogHtml();
    var html = topbar('LATIN · 4E–2NDE', tabletsCount() + '<button type="button" class="btn btn--small" data-act="library">Bibliothèque</button>' + bestiaryButton() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="exit">Quitter</button>') +
      '<main class="home"><div class="scene-cell">' + Scene.scene(G.user.avatar) +
      '<div class="status"><span class="status__grade">' + esc(G.progress.grade) + '</span><span class="status__levels">' + esc(levelsLabel().toUpperCase()) + '</span></div></div>' +
      '<div class="plan-cell">' + planHtml() + '</div>' +
      '<section class="panel" aria-label="' + (u ? 'Tablette choisie' : ch ? 'Pièce choisie' : 'L’architecte') + '">' + panel + '</section></main>';
    show(html, focusSel);
    if (!u && !ch) markRead();
  }

  function markRead() {
    var d = G.dlg;
    if (!d || !d.id || d.i < d.lines.length - 1) return;
    var read = G.data.read || [];
    if (read.indexOf(d.id) >= 0) return;
    read.push(d.id);
    if (/^g\d+$/.test(d.id)) [7, 14, 21].forEach(function (th) { if (th < +d.id.slice(1) && read.indexOf('g' + th) < 0) read.push('g' + th); });
    if (d.id === 'gall') [7, 14, 21].forEach(function (th) { if (read.indexOf('g' + th) < 0) read.push('g' + th); });
    G.data.read = read;
    save();
  }

  /* ---------- 2. every tablet, room by room ---------- */

  function renderList() {
    G.screen = 'list';
    var p = G.progress;
    var cols = ROOM_ORDER.map(function (code) { return chapterOf(code); }).filter(Boolean).map(function (ch) {
      var items = p.units.filter(function (u) { return u.chapter === ch.code; }).map(function (u) {
        if (u.hidden) return '<li class="is-hidden">' + Scene.tablet(null, 26) + '<span>' + esc(typo(u.label)) + '</span><em>indisponible</em></li>';
        var state = u.lit ? LEVEL[u.brightness] + ' · ' + u.best + '/12' : u.series ? 'tentée · ' + u.best + '/12' : 'vierge';
        return '<li><button type="button" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(tabletName(u)) + '">' + Scene.tablet(stateOf(u), 26) + '<span>' + esc(typo(u.label)) + '</span><em>' + esc(state) + '</em></button></li>';
      }).join('');
      return '<section aria-labelledby="ch-' + esc(ch.code) + '"><h2 id="ch-' + esc(ch.code) + '">' + esc(ch.label) + ' <small>' + ch.lit + '/' + ch.total + '</small></h2><p class="kicker">' + esc(ch.topic) + '</p><ul>' + items + '</ul></section>';
    }).join('');
    var cracks = p.comets.length ? '<section aria-labelledby="ch-cracks"><h2 id="ch-cracks">Lézardes à réparer <small>' + p.comets.length + '</small></h2><ul>' + p.comets.map(function (c) {
      return '<li><button type="button" data-act="comet" data-arg="' + esc(c.misconception) + '">' + Scene.crack(24) + '<span>' + esc(typo(c.label)) + '</span><em>' + esc(typo(c.unitName)) + '</em></button></li>';
    }).join('') + '</ul></section>' : '';
    var lar = '<section aria-labelledby="ch-lar"><h2 id="ch-lar">Le lararium <small>' + (p.final.best !== null && p.final.best !== undefined ? p.final.best + '/12' : '—') + '</small></h2><ul><li>' +
      (p.final.unlocked ? '<button type="button" data-act="final-intro">' + larIcon() + '<span>L’épreuve</span><em>' + minutes(p.final.timeLimitSeconds) + ' min</em></button>' : '<span class="is-hidden">Apparaît quand toutes les tablettes sont tracées.</span>') + '</li></ul></section>';
    show(topbar('Toutes les tablettes', tabletsCount() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="home">Retour à la villa <span class="kbd">Échap</span></button>') +
      '<main class="screen" tabindex="-1"><h2 class="big-title" tabindex="-1" data-autofocus>Toutes les tablettes</h2>' +
      '<p class="kicker">Séries illimitées · 12 inscriptions · sans chronomètre</p><div class="list">' + cols + cracks + lar + '</div>' +
      '<p style="margin-top:22px">' + bestiaryButton() + '</p></main>');
  }

  /* ---------- 3. a run: a series, a crack or the Lararium ---------- */

  function startSeries(unit) {
    if (G.busy) return;
    var u = unitOf(unit);
    if (!u || u.hidden) { toast(ERRORS['unit-unavailable']); return; }
    G.busy = true;
    call('startSeries', { unit: unit }).then(function (r) {
      G.busy = false;
      G.run = { mode: 'series', session: r.session, unit: unit, size: r.size || 12, results: [], before: { best: u.best, brightness: u.brightness, lit: u.lit }, draft: '' };
      G.data.open = { mode: 'series', session: r.session, unit: unit };
      G.data.last = unit;
      save();
      nextQuestion();
    }, fail);
  }

  function startComet(misconception) {
    if (G.busy) return;
    G.busy = true;
    call('studyComet', { misconception: misconception }).then(function (r) {
      G.busy = false;
      G.run = { mode: 'comet', session: r.session, unit: r.comet && r.comet.unit, misconception: misconception, comet: r.comet, explanation: r.explanation, size: r.size || 3, results: [], draft: '' };
      G.data.open = { mode: 'comet', session: r.session, unit: G.run.unit, misconception: misconception, label: r.comet && r.comet.label };
      save();
      renderCometIntro();
    }, fail);
  }

  function startFinal() {
    if (G.busy) return;
    G.busy = true;
    call('startFinal', {}).then(function (r) {
      G.busy = false;
      var limit = r.timeLimitSeconds || G.progress.final.timeLimitSeconds || 1200;
      G.run = { mode: 'final', session: r.session, size: r.size || 12, results: [], limit: limit, localDeadline: Date.now() + limit * 1000, draft: '', beforeBest: G.progress.final.best };
      G.data.open = { mode: 'final', session: r.session, deadline: r.deadline, limit: limit };
      save();
      nextQuestion();
    }, fail);
  }

  function resume() {
    var o = G.data.open;
    if (!o || !o.session) return;
    G.run = { mode: o.mode, session: o.session, unit: o.unit, misconception: o.misconception, comet: o.mode === 'comet' ? { label: o.label, unit: o.unit } : null, size: o.mode === 'final' ? 12 : o.mode === 'comet' ? 3 : 12, results: [], draft: '', resumed: true };
    var u = o.unit ? unitOf(o.unit) : null;
    if (u) G.run.before = { best: u.best, brightness: u.brightness, lit: u.lit };
    if (o.mode === 'final') {
      G.run.limit = o.limit || G.progress.final.timeLimitSeconds;
      G.run.localDeadline = o.deadline ? Date.parse(o.deadline) : Date.now();
      if (Date.now() >= G.run.localDeadline) { endRun(); return; }
    }
    nextQuestion();
  }

  function nextQuestion() {
    var run = G.run;
    G.busy = true;
    call('nextQuestion', { session: run.session }).then(function (r) {
      G.busy = false;
      if (r.done) { endRun(); return; }
      run.size = r.size || run.size;
      run.position = r.position;
      for (var k = 0; k < r.position; k++) if (!run.results[k]) run.results[k] = 'past';
      run.q = r.question;
      run.correction = null;
      run.notice = null;
      run.choice = null;
      run.cells = null;
      run.typed = '';
      if (run.mode === 'final' && r.deadline && run.resumed) run.localDeadline = Date.parse(r.deadline);
      renderRun();
    }, function (e) {
      G.busy = false;
      if (e && (e.message === 'session-ended' || e.message === 'session-unknown')) { clearOpen(); refresh().then(function () { goHome(); }); return; }
      fail(e);
    });
  }

  function runTitle(run) {
    if (run.mode === 'final') return 'Le lararium' + (run.q ? ' · ' + chapterName(unitOf(run.q.unit)) : '');
    var u = unitOf(run.unit || (run.q && run.q.unit));
    if (run.mode === 'comet') return 'Réparer la lézarde · ' + typo(run.comet && run.comet.label ? run.comet.label : u ? u.label : '');
    return (u ? chapterName(u) + ' · ' + typo(u.label) : '');
  }

  function progressHtml(run) {
    var items = [];
    for (var k = 0; k < run.size; k++) {
      var r = run.results[k];
      if (run.mode === 'final') items.push('<li class="' + (k === run.position ? 'cur' : r ? 'done' : '') + '"></li>');
      else items.push('<li class="' + (k === run.position && !run.correction ? 'cur' : r === 'ok' ? 'ok' : r === 'ko' || r === 'dk' ? 'ko' : r ? 'done' : '') + '"></li>');
    }
    var label = run.mode === 'final' ? 'Inscriptions faites : ' + run.results.filter(Boolean).length + ' sur ' + run.size : 'Avancement de la série';
    return '<ol class="progress" aria-label="' + esc(label) + '">' + items.join('') + '</ol>';
  }

  /* The declension table of a correction goes beside it, on its parchment; the rule stays in the
     correction. */
  function splitMethod(html) {
    var d = document.createElement('div');
    d.innerHTML = html || '';
    var t = d.querySelector('table');
    var table = '';
    if (t) { table = t.outerHTML; t.remove(); }
    return { text: d.innerHTML, table: table };
  }

  function answerAreaHtml(run, q, done, c) {
    var type = q.answerType;
    if (type === 'choice') {
      var correct = done ? c.expectedText : null;
      return '<div class="choices" role="radiogroup" aria-labelledby="prompt">' + (q.choices || []).map(function (o, i) {
        var chosen = run.choice === i, cls = 'choice';
        if (done) {
          if (o.value === correct) cls += ' is-right';
          else if (chosen) cls += ' is-wrong';
        } else if (chosen) cls += ' is-chosen';
        return '<button type="button" class="' + cls + '" role="radio" aria-checked="' + chosen + '" data-act="choose" data-arg="' + i + '"' + (done ? ' disabled' : '') + (i === 0 ? ' data-first' : '') + '>' +
          '<span class="choice__key">' + 'ABCDEF'.charAt(i) + '</span><span class="choice__text">' + o.html + '</span></button>';
      }).join('') + '</div>';
    }
    if (type === 'forms') {
      var cells = run.cells || [];
      return '<div class="cells">' + (q.labels || []).map(function (label, i) {
        var val = done ? ((c.input || '').split('|')[i] || '') : (cells[i] || '');
        var ok = done && c.cells ? c.cells[i] : null;
        return '<label class="cell' + (done ? (ok ? ' is-ok' : ' is-ko') : '') + '"><span class="cell__label">' + esc(label) + '</span>' +
          '<input id="cell-' + i + '" class="cell__input" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" lang="la" value="' + esc(done && c.dontKnow ? '' : val) + '"' + (done ? ' readonly' : '') + '>' +
          (done && !ok && c.expectedCells ? '<span class="cell__expected">' + esc(c.expectedCells[i] || '') + '</span>' : '') + '</label>';
      }).join('') + '</div>';
    }
    var field = '<div class="field' + (done ? (c.ok ? ' is-ok' : ' is-ko') : '') + '"><input id="answer" type="text" inputmode="text" lang="la" autocomplete="off" autocapitalize="off" spellcheck="false" aria-describedby="typing-help" aria-label="Ta réponse" placeholder="' + (done ? '' : 'Ta réponse') + '"' +
      (done ? ' readonly value="' + esc(c.dontKnow ? '—' : (c.input || '')) + '"' : ' value="' + esc(run.typed || '') + '"') + '></div>';
    return field;
  }

  function renderRun() {
    G.screen = 'run';
    var run = G.run, q = run.q, done = !!run.correction, c = run.correction;
    var t = TYPING[q.answerType] || TYPING.latin;
    var right = '<button type="button" class="btn btn--small" data-act="draft" aria-expanded="' + !!run.draftOpen + '" aria-controls="draft">' + (run.draftOpen ? 'Fermer le brouillon' : 'Brouillon') + '</button>' +
      (run.mode === 'final'
        ? '<button type="button" class="btn btn--small" data-act="final-end">' + (G.confirmEnd ? 'Confirmer : terminer maintenant' : 'Terminer l’épreuve') + '</button>'
        : '<button type="button" class="btn btn--small" data-act="leave">' + (run.mode === 'comet' ? 'Retour à la villa' : 'Quitter la série') + '</button>');
    right = glossaryButton() + right;
    var sub = run.mode === 'final' ? 'L’épreuve' : run.mode === 'comet' ? 'Réparer la lézarde' : 'Série de 12';

    var chip = done ? (c.dontKnow ? '<span class="chip chip--dk">○ Sans réponse</span>' : c.ok ? '<span class="chip chip--ok">✓ Juste</span>' : '<span class="chip chip--ko">✗ Faux</span>') : '';
    var body = '<div class="qmain"><h2 class="prompt" id="prompt" tabindex="-1">' + q.prompt + '</h2>' +
      (q.figure ? '<div class="figure">' + q.figure + '</div>' : '') +
      '<div class="answer-row">' + answerAreaHtml(run, q, done, c) + chip + '</div>';
    if (!done) {
      body += '<div class="typing-help" id="typing-help">' + esc(t.help) + '</div>';
      if (t.keys.length) body += '<div class="keys" role="group" aria-label="Voyelles longues à insérer">' + t.keys.map(function (k) { return '<button type="button" data-act="key" data-arg="' + esc(k) + '" aria-label="Insérer ' + esc(k) + '">' + esc(k) + '</button>'; }).join('') + '</div>';
      if (run.notice) body += '<div class="notice' + (run.notice.warn ? ' notice--warn' : '') + '" role="alert">' + run.notice.html + '</div>';
      body += '<div class="qacts"><button type="button" class="btn" data-act="dont-know">Je ne sais pas</button>' +
        '<button type="button" class="btn btn--primary" data-act="submit">' + (run.mode === 'final' ? 'Inscription suivante' : 'Valider') + ' <span class="kbd">Entrée</span></button></div>';
    } else body += '<p id="typing-help" hidden></p>';
    body += '</div>';

    var draft = '';
    if (run.draftOpen) {
      draft = '<aside class="draft" id="draft"><div class="draft__head"><label for="draft-text">Brouillon</label><button type="button" class="btn btn--small" data-act="draft-clear">Lisser</button></div>' +
        '<textarea id="draft-text" spellcheck="false" lang="la" aria-describedby="draft-note"></textarea><span class="draft__note" id="draft-note">Une tablette de cire libre : jamais enregistrée ni envoyée, lissée à la fin ' + (run.mode === 'final' ? 'de l’épreuve' : run.mode === 'comet' ? 'de la réparation' : 'de la série') + '.</span></aside>';
    }

    var correction = '', aside = '';
    if (done) {
      var last = run.position + 1 >= run.size, m = splitMethod(c.method);
      var title = c.dontKnow ? 'La règle' : c.ok ? 'Inscription juste.' : 'La cire a glissé.';
      correction = '<section class="correction" aria-labelledby="corr-title"><h3 class="correction__title' + (c.ok ? '' : ' is-ko') + '" id="corr-title">' + esc(title) + '</h3>' +
        (!c.ok && !c.dontKnow && c.misconceptionLabel ? '<div class="correction__error">' + esc(typo(c.misconceptionLabel)) + '</div>' : '') +
        (c.ok ? '' : '<div class="expected"><span>Forme attendue</span><b>' + (c.expected || esc(c.expectedText || '')) + '</b></div>') +
        '<div class="kicker">Règle</div><p class="method">' + m.text + '</p>' +
        '<div class="correction__acts"><button type="button" class="btn btn--primary" data-act="next" data-autofocus>' + (last ? 'Voir le bilan' : 'Inscription suivante') + ' <span class="kbd">Entrée</span></button></div></section>';
      if (m.table) aside = '<aside class="parchment" aria-label="Le tableau">' + m.table + '</aside>';
    }

    show(topbar(sub, right) +
      '<main class="screen run"><div class="tabula"><div class="qhead"><span class="qhead__title">' + esc(runTitle(run)) + '</span>' + streakHtml(run) + '<span class="qhead__num">' + (run.mode === 'final' ? 'Inscription ' : 'Question ') + (run.position + 1) + '/' + run.size + progressHtml(run) + '</span></div>' +
      gaugeHtml(run) + body + correction + '</div>' + aside + draft + '</main>',
      done ? '[data-act="next"]' : (q.answerType === 'choice' ? '[data-first]' : q.answerType === 'forms' ? '#cell-0' : '#answer'));

    var ta = document.getElementById('draft-text');
    if (ta) { ta.value = run.draft || ''; ta.addEventListener('input', function () { run.draft = ta.value; }); }
    if (!done) wireInputs(run);
    if (run.mode === 'final') startClock();
  }

  function wireInputs(run) {
    var inp = document.getElementById('answer');
    if (inp) {
      inp.addEventListener('input', function () { run.typed = inp.value; });
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
      if (run.typed) inp.setSelectionRange(inp.value.length, inp.value.length);
    }
    var cells = app.querySelectorAll('.cell__input');
    Array.prototype.forEach.call(cells, function (cell, i) {
      cell.addEventListener('input', function () { run.cells = run.cells || []; run.cells[i] = cell.value; });
      cell.addEventListener('focus', function () { run.lastCell = i; });
      cell.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (i + 1 < cells.length) cells[i + 1].focus(); else submit();
      });
    });
  }

  function currentInput(run) {
    var q = run.q;
    if (q.answerType === 'choice') return run.choice === null || run.choice === undefined ? '' : q.choices[run.choice].value;
    if (q.answerType === 'forms') {
      var values = Array.prototype.map.call(app.querySelectorAll('.cell__input'), function (c) { return c.value.trim(); });
      if (values.some(function (v) { return !v; })) return null;
      return values.join('|');
    }
    var inp = document.getElementById('answer');
    return (inp ? inp.value : '').trim();
  }

  /* The gauge of a series or a crack's repair, from the corrections already given - never during
     the Lararium, whose answers are not corrected before its end. */
  function gaugeHtml(run) {
    if (run.mode === 'final') return '';
    return E.gauge({
      mode: run.mode, results: run.results, size: run.size,
      best: run.before ? run.before.best : null,
      solvedAt: 2, solve: 'la réparer', levels: GAUGE_LEVELS, record: 'ta meilleure série'
    }).html;
  }
  function streakHtml(run) {
    if (run.mode === 'final' || !run.correction) return '';
    var n = E.streak(run.results);
    return n >= 3 ? '<span class="sg-streak' + (n % 4 === 0 ? ' is-hot' : '') + '">' + n + ' d’affilée</span>' : '';
  }
  // the sound of a correction: the scale climbs with the streak, a flourish every fourth
  function correctionSound(run, r) {
    if (r.dontKnow) return;
    if (!r.ok) { E.sound.play('ko'); return; }
    var n = E.streak(run.results);
    E.sound.play(n >= 4 && n % 4 === 0 ? 'combo' : 'ok', n);
  }

  function submit(dontKnow) {
    var run = G.run;
    if (!run || run.correction || G.busy) return;
    var input = dontKnow ? 'dont_know' : currentInput(run);
    if (!input) {
      run.notice = { html: run.q.answerType === 'choice' ? 'Choisis une proposition, ou « Je ne sais pas ».' : run.q.answerType === 'forms' ? 'Remplis toutes les cases, ou choisis « Je ne sais pas ».' : 'Écris une réponse, ou choisis « Je ne sais pas ».', warn: true };
      renderRun();
      return;
    }
    G.busy = true;
    call('answer', { session: run.session, input: input }).then(function (r) {
      G.busy = false;
      if (!r.recorded) {
        var t = TYPING[run.q.answerType] || TYPING.latin;
        run.notice = r.unreadable
          ? { html: 'Je n’arrive pas à lire cette réponse : ' + esc(lowerFirst(t.help)) + ' Rien n’est compté.', warn: true }
          : { html: esc(r.formIssue || '') + ' Rien n’est compté : réécris-la, puis valide.', warn: true };
        renderRun();
        announce(plain(run.notice.html));
        return;
      }
      run.typed = '';
      run.cells = null;
      if (run.mode === 'final') {
        run.results[run.position] = 'done';
        if (run.position + 1 >= run.size) endRun(); else nextQuestion();
        return;
      }
      run.results[run.position] = r.dontKnow ? 'dk' : r.ok ? 'ok' : 'ko';
      run.correction = r;
      correctionSound(run, r);
      renderRun();
      announce(r.dontKnow ? 'Noté « je ne sais pas ». La règle est affichée.' : r.ok ? 'Inscription juste.' : 'Réponse fausse.' + (r.misconceptionLabel ? ' Erreur repérée : ' + lowerFirst(typo(r.misconceptionLabel)) + '.' : '') + ' Forme attendue : ' + plain(r.expected || r.expectedText));
    }, function (e) {
      G.busy = false;
      if (e && e.message === 'time-is-up') { endRun(); return; }
      if (e && e.message === 'no-question-served') { nextQuestion(); return; }
      fail(e);
    });
  }

  function afterCorrection() {
    var run = G.run;
    if (!run) return;
    if (run.position + 1 >= run.size) endRun(); else nextQuestion();
  }

  function insertKey(k) {
    var run = G.run, target = document.activeElement;
    if (!target || !/^(answer|cell-\d+)$/.test(target.id || '')) {
      target = run && run.lastCell !== undefined ? document.getElementById('cell-' + run.lastCell) : document.getElementById('answer') || document.getElementById('cell-0');
    }
    if (!target || target.readOnly) return;
    var s = target.selectionStart === null ? target.value.length : target.selectionStart, e = target.selectionEnd === null ? s : target.selectionEnd;
    target.value = target.value.slice(0, s) + k + target.value.slice(e);
    target.dispatchEvent(new Event('input'));
    target.focus();
    target.setSelectionRange(s + k.length, s + k.length);
  }

  /* The keys of the macron buttons keep the focus where the student was typing. */
  document.addEventListener('mousedown', function (e) { if (e.target.closest('.keys button')) e.preventDefault(); });

  /* ---------- the Lararium's clock: the platform shows it; this one only closes the session ---------- */

  function startClock() {
    stopClock();
    clockTimer = setInterval(function () {
      var run = G.run;
      if (!run || run.mode !== 'final') { stopClock(); return; }
      if (Date.now() >= run.localDeadline) { stopClock(); announce('Le temps est écoulé.'); endRun(); }
    }, 1000);
  }
  function stopClock() { if (clockTimer) { clearInterval(clockTimer); clockTimer = null; } }

  /* ---------- the end of a run ---------- */

  function clearOpen() { G.data.open = null; save(); }

  function endRun() {
    var run = G.run;
    if (!run || run.ending) return;
    run.ending = true;
    stopClock();
    var method = run.mode === 'final' ? 'endFinal' : run.mode === 'comet' ? 'endComet' : 'endSeries';
    G.busy = true;
    call(method, { session: run.session }).then(function (r) {
      G.busy = false;
      run.draft = '';
      G.endRes = r;
      clearOpen();
      return refresh().then(function () {
        if (r.mode === 'series' && r.newlyLit) G.fresh = r.unit;
        if (run.mode === 'final') renderReview(); else if (run.mode === 'comet') renderCometEnd(); else renderSeriesEnd();
      });
    }, function (e) {
      G.busy = false;
      run.ending = false;
      if (e && e.message === 'session-unfinished') { nextQuestion(); return; }
      if (e && e.message === 'session-ended') { clearOpen(); refresh().then(goHome); return; }
      fail(e);
    });
  }

  function boxesHtml(run, size) {
    var out = [];
    for (var k = 0; k < size; k++) {
      var r = run.results[k];
      out.push(r === 'ok' ? '<li class="ok" aria-label="Inscription ' + (k + 1) + ' : juste">✓</li>' : r === 'dk' ? '<li aria-label="Inscription ' + (k + 1) + ' : je ne sais pas">○</li>' : r === 'ko' ? '<li class="ko" aria-label="Inscription ' + (k + 1) + ' : fausse">✗</li>' : '<li aria-label="Inscription ' + (k + 1) + '">·</li>');
    }
    return '<ul class="boxes">' + out.join('') + '</ul>';
  }

  function cracksRaisedHtml(list) {
    if (!list || !list.length) return '';
    return list.map(function (c) {
      return '<div class="notice notice--crack">' + Scene.crack(34) + '<span><b>Une lézarde s’ouvre dans un mur</b> : « ' + esc(typo(c.label)) + ' ». La même erreur est revenue deux fois ; tu peux la réparer maintenant, ou plus tard.</span>' +
        '<button type="button" class="btn btn--small" data-act="comet" data-arg="' + esc(c.misconception) + '">Réparer la lézarde</button></div>';
    }).join('');
  }

  function renderSeriesEnd() {
    G.screen = 'seriesEnd';
    var r = G.endRes, run = G.run, u = unitOf(r.unit) || { label: r.unit };
    var before = run.before || {};
    var lit = !!r.brightness;
    var record = before.best !== null && before.best !== undefined && r.score > before.best && !r.newlyLit && !r.brighter;
    var note = r.score === 12 ? 'Douze sur douze. Je ne touche à rien : la tablette est dorée.' : r.newlyLit ? pick(AFTER_LIT, r.score + run.session) : r.brighter ? 'La tablette ' + deW(typo(u.label)) + ' passe à un tracé plus net. Une série moins réussie ne l’effacera pas.' : pick(AFTER_ERROR, r.score + run.session);
    var levelRow = lit
      ? (r.brighter && before.brightness ? '<span class="was">' + Scene.tablet(before.brightness, 22) + ' ' + esc(LEVEL[before.brightness]) + '</span> <span aria-label="devient">→</span> ' : '') + Scene.tablet(r.brightness, 24) + ' ' + esc(LEVEL[r.brightness])
      : 'pas encore tracée — il en faut 6';
    show(topbar('Série de 12', tabletsCount()) +
      '<main class="screen"><div class="tabula tabula--end"><div class="qhead"><span class="qhead__title">' + esc(chapterName(u) + ' · ' + typo(u.label)) + '</span></div>' +
      '<div class="result"><div class="score" aria-label="' + r.score + ' inscriptions justes sur ' + r.size + '"><b>' + r.score + '</b><span>/' + r.size + '</span></div>' +
      '<div class="result__tablet">' + (r.newlyLit ? '<span class="badge-new">Nouvelle tablette tracée</span>' : r.brighter ? '<span class="badge-new">' + (r.brightness === 'blazing' ? 'Tablette dorée' : 'Tracé plus net') + '</span>' : record ? '<span class="badge-new">Nouveau record</span>' : '') +
      '<div class="result__arrow">' + (r.newlyLit ? Scene.tablet(before.series ? 'tried' : 'never', 70) + '<span aria-hidden="true">→</span>' : '') + Scene.tablet(lit ? r.brightness : 'tried', 110) + '</div>' +
      '<span class="kicker">Prochain niveau : ' + esc(nextLevel(r.best)) + '</span></div></div>' +
      boxesHtml(run, r.size) +
      '<dl class="rows"><div><dt>Tracé</dt><dd>' + levelRow + '</dd></div><div><dt>Meilleure série</dt><dd class="num">' + (r.best === null || r.best === undefined ? '—' : r.best + '/12') + '</dd></div></dl>' +
      cracksRaisedHtml(r.comets) +
      '<p class="architect"><b>' + esc(ARCHITECT) + '</b> — ' + esc(note) + '</p>' +
      '<div class="qacts">' + againHtml(r) + '</div></div></main>');
    announce('Fin de la série : ' + r.score + ' sur ' + r.size + '.' + (r.newlyLit ? ' Nouvelle tablette tracée !' : r.brighter ? ' Le tracé est plus net.' : record ? ' Nouveau record.' : ''));
    celebrate(r.newlyLit || r.brighter ? 'level' : record ? 'record' : 'end', '.result__arrow svg:last-child');
  }

  /* « Encore une » in one key: the same tablet again unless it is golden, then the tablet nearest
     to its next level - a fact read on the plan, never a recommendation. */
  function againHtml(r) {
    var u = unitOf(r.unit), maxed = u && u.best >= 12, near = E.closest(G.progress.units, r.unit), acts = [];
    if (!maxed) acts.push('<button type="button" class="btn btn--primary" data-act="series" data-arg="' + esc(r.unit) + '" data-autofocus>Retracer la tablette <span class="kbd">Entrée</span></button>');
    if (near) acts.push('<button type="button" class="btn sg-next__near' + (maxed ? ' btn--primary' : '') + '" data-act="series" data-arg="' + esc(near.unit.code) + '"' + (maxed ? ' data-autofocus' : '') + '>Tracer : ' + esc(typo(near.unit.label)) +
      '<span class="sg-next__hint">' + esc(nearHint(near)) + '</span></button>');
    acts.push('<button type="button" class="btn btn--small" data-act="home"' + (acts.length ? '' : ' data-autofocus') + '>Retour à la villa <span class="kbd">Échap</span></button>');
    return '<div class="sg-next">' + acts.join('') + '</div>';
  }
  function nearHint(near) {
    var b = near.unit.best;
    return (b === null || b === undefined ? 'vierge' : 'meilleure série ' + b + '/12') + ' · ' + nextLevel(b);
  }
  function celebrate(cue, selector) {
    E.sound.play(cue);
    if (cue === 'level' || cue === 'record' || cue === 'solved') E.burst(app.querySelector(selector), cue === 'level');
  }

  function renderCometIntro() {
    G.screen = 'cometIntro';
    var run = G.run, c = run.comet || {}, u = unitOf(c.unit || run.unit);
    show(topbar('Réparer la lézarde', tabletsCount() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="leave">Retour à la villa</button>') +
      '<main class="screen"><div class="crack-study"><div class="crack-study__art">' + Scene.crackWall() + '</div><div>' +
      '<span class="kicker">LÉZARDE · ' + esc((u ? chapterName(u) + ' · ' + typo(u.label) : typo(c.unitName || '')).toUpperCase()) + '</span>' +
      '<h2 class="big-title" tabindex="-1">' + esc(typo(c.label || 'Une lézarde')) + '</h2>' +
      '<p class="explanation">' + esc(typo(run.explanation || '')) + '</p>' +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="comet-go" data-autofocus>Réparer : 3 inscriptions <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn btn--small" data-act="leave">Plus tard</button></div><p class="kicker">2 justes sur 3 pour la réparer</p></div></div></main>');
  }

  function renderCometEnd() {
    G.screen = 'cometEnd';
    var r = G.endRes, run = G.run, c = run.comet || {};
    show(topbar('Réparer la lézarde', tabletsCount()) +
      '<main class="screen"><div class="crack-study"><div class="crack-study__art' + (r.solved ? ' is-repaired' : '') + '">' + Scene.crackWall() + '</div><div><span class="kicker">RÉPARATION TERMINÉE · ' + esc(typo(c.label || '').toUpperCase()) + '</span>' +
      '<h2 class="big-title" tabindex="-1">' + (r.solved ? 'Lézarde réparée.' : 'Elle reste à réparer.') + '</h2>' +
      boxesHtml(run, r.size) +
      (r.solved ? '<div class="sg-bestiary-new">' + E.bestiaryArt('pompei', run.misconception, 110) + '<span class="kicker">Nouvelle ligne au ' + esc(BESTIARY.toLowerCase()) + '</span></div>' : '') +
      '<p class="explanation">' + (r.solved
        ? 'Le mur est rebouché : ' + r.score + ' justes sur ' + r.size + '. Elle ne se rouvrira que si l’erreur revient.'
        : r.score + ' juste' + (r.score > 1 ? 's' : '') + ' sur ' + r.size + '. Rien n’est perdu : la lézarde t’attend sur le plan, et deux justes sur trois suffisent pour la réparer.') + '</p>' +
      cracksRaisedHtml(r.comets) +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="home" data-autofocus>Retour à la villa <span class="kbd">Entrée</span></button>' +
      (r.solved ? '<button type="button" class="btn" data-act="bestiary">Ouvrir le ' + esc(BESTIARY.toLowerCase()) + '</button>' : '<button type="button" class="btn" data-act="comet" data-arg="' + esc(run.misconception) + '">Réessayer</button>') + '</div></div></div></main>');
    announce(r.solved ? 'Lézarde réparée. Elle entre au registre des lézardes.' : 'La lézarde reste à réparer.');
    celebrate(r.solved ? 'solved' : 'end', '.sg-bestiary-new svg');
  }

  function renderFinalIntro() {
    G.screen = 'finalIntro';
    var f = G.progress.final, extra = G.user.extraTimePercent || 0;
    if (!f.unlocked) { toast(ERRORS['final-locked']); return; }
    var mins = minutes(f.timeLimitSeconds);
    show(topbar('L’épreuve', tabletsCount() + '<button type="button" class="btn btn--small" data-act="home">Retour à la villa <span class="kbd">Échap</span></button>') +
      '<main class="screen"><div class="final-intro"><div class="final-intro__art">' + Scene.lararium({ flame: f.best !== null && f.best !== undefined }) + '</div><div>' +
      '<span class="kicker">LA PIÈCE COMMANDÉE</span><h2 class="big-title" tabindex="-1">' + esc(f.label || 'Le Lararium') + '</h2>' +
      '<blockquote class="architect-quote"><b>Caius Vibius Rufus</b>Julia Felix viendra elle-même lire le lararium. Douze inscriptions, tirées de toutes les pièces : je ne corrigerai qu’à la fin.</blockquote>' +
      '<dl class="final-facts"><div><dt>Inscriptions</dt><dd>' + f.size + ', toute la villa</dd></div><div><dt>Durée</dt><dd>' + mins + ' min' + (extra > 0 ? ' · temps majoré (+' + extra + ' %)' : '') + '</dd></div><div><dt>Correction</dt><dd>à la fin · rejouable</dd></div>' +
      '<div><dt>Meilleur score</dt><dd>' + (f.best !== null && f.best !== undefined ? f.best + '/' + f.size : '—') + '</dd></div></dl>' +
      '<div class="groma-row">' + ['brass', 'silver', 'gold'].map(function (fin, i) {
        var held = (G.progress.rewards || []).filter(function (rw) { return rw.kind === 'trophy'; })[0];
        var art = window.SGRewards ? window.SGRewards.art('trophy', 'groma', { size: 56, finish: fin, locked: !held || (i === 1 && held.finish === 'brass') || (i === 2 && held.finish !== 'gold') }) : '';
        return '<figure>' + art + '<figcaption>' + ['LAITON', '10/12', '12/12'][i] + '</figcaption></figure>';
      }).join('') + '</div>' +
      '<p class="method">« Je ne sais pas » reste possible à chaque inscription. Le temps restant s’affiche dans la barre de Formatool ; une inscription laissée sans réponse quand il s’écoule compte fausse. Les tablettes de la villa restent tracées quoi qu’il arrive.</p>' +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="final-go" data-autofocus>Commencer l’épreuve <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn" data-act="home">Retour à la villa</button></div></div></div></main>');
  }

  function reviewGiven(it) {
    if (it.dontKnow) return 'je ne sais pas';
    if (!it.answered) return 'sans réponse';
    return String(it.input || '').split('|').join(' · ');
  }

  function renderReview() {
    G.screen = 'review';
    var r = G.endRes, items = (r.review || []).map(function (it, k) {
      var m = splitMethod(it.method);
      var chip = it.ok ? '<span class="chip chip--ok">✓</span>' : it.answered && !it.dontKnow ? '<span class="chip chip--ko">✗</span>' : '<span class="chip chip--dk">○</span>';
      return '<li><span class="review__n">' + String(k + 1).padStart(2, '0') + '</span><div><div class="review__unit">' + esc(typo(it.unitName)) + '</div>' +
        '<div class="review__prompt">' + it.prompt + '</div>' +
        '<div class="review__given"><span>Ta réponse</span>' + esc(reviewGiven(it)) + '</div>' +
        (!it.ok && it.misconceptionLabel ? '<div class="correction__error">' + esc(typo(it.misconceptionLabel)) + '</div>' : '') +
        '<details' + (it.ok ? '' : ' open') + '><summary>' + (it.ok ? 'Voir la règle' : 'La règle') + '</summary><div class="expected"><span>Forme attendue</span><b>' + (it.expected || '') + '</b></div><p class="method">' + m.text + '</p>' + (m.table ? '<div class="parchment parchment--inline">' + m.table + '</div>' : '') + '</details></div>' + chip + '</li>';
    }).join('');
    var rewards = (G.progress.rewards || []).filter(function (rw) { return rw.kind === 'trophy' || rw.code === 'card-pompei-lararium'; });
    var note = r.score >= 12 ? 'Julia Felix a tout lu sans une faute. La groma est dorée.' : r.score >= 10 ? r.score + ' inscriptions justes : la groma passe à l’argent. Les tablettes de la villa restent toutes tracées.' : r.score + ' inscription' + (r.score > 1 ? 's' : '') + ' juste' + (r.score > 1 ? 's' : '') + '. L’épreuve ne retire rien : les tablettes de la villa restent tracées.';
    show(topbar('L’épreuve', tabletsCount()) +
      '<main class="screen"><div class="review-wrap"><div class="review-side"><span class="kicker">BILAN DU LARARIUM</span><div class="score"><b>' + r.score + '</b><span>/' + r.size + '</span></div>' +
      '<p class="kicker">Meilleur score · ' + (r.best !== null && r.best !== undefined ? r.best : r.score) + '/' + r.size + '</p>' +
      (r.timedOut ? '<div class="notice notice--warn">' + (G.run && G.run.endedByPlayer ? 'Épreuve terminée avant la dernière inscription' : 'Le temps s’est écoulé') + ' : les inscriptions restées sans réponse comptent fausses.</div>' : '') +
      (rewards.length && window.SGRewards ? '<div class="review-pieces">' + rewards.map(function (rw) { return '<figure>' + window.SGRewards.art(rw.kind, rw.code, { size: rw.kind === 'card' ? 90 : 110, finish: rw.finish || 'brass' }) + '<figcaption>' + esc(typo(rw.name)) + (rw.kind === 'trophy' ? ' · ' + ({ brass: 'laiton', silver: 'argent', gold: 'or' }[rw.finish] || 'laiton') : '') + '</figcaption></figure>'; }).join('') + '</div>' : '') +
      cracksRaisedHtml(r.comets) +
      '<p class="architect"><b>' + esc(ARCHITECT) + '</b> — ' + esc(note) + '</p>' +
      '<div class="qacts"><button type="button" class="btn" data-act="final-intro">Rejouer le lararium</button><button type="button" class="btn btn--primary" data-act="home" data-autofocus>Retour à la villa</button></div></div>' +
      '<ol class="review" aria-label="Correction, inscription par inscription">' + items + '</ol></div></main>');
    announce('Épreuve terminée : ' + r.score + ' sur ' + r.size + '.');
    var was = G.run ? G.run.beforeBest : null;
    celebrate(was !== null && was !== undefined && r.score > was ? 'record' : 'end', '.review-side .score');
  }

  /* ---------- 4. the library: the tables, to revise or to learn ---------- */

  var CASE_NAMES = ['Nominatif', 'Vocatif', 'Accusatif', 'Génitif', 'Datif', 'Ablatif'];
  var CASES_NO_VOC = ['Nominatif', 'Accusatif', 'Génitif', 'Datif', 'Ablatif'];
  function cellHtml(s) { return s === '—' || !s ? '—' : '<i lang="la">' + esc(s) + '</i>'; }
  function nounCard(n) {
    var rows = CASE_NAMES.map(function (name, i) { return '<tr><th scope="row">' + name + '</th><td>' + cellHtml(n.sg[i]) + '</td><td>' + cellHtml(n.pl[i]) + '</td></tr>'; }).join('');
    return '<article class="libcard"><h3><i lang="la">' + esc(n.entry[0] + ', ' + n.entry[1]) + '</i></h3><p class="kicker">' + esc(({ m: 'M.', f: 'F.', n: 'N.' })[n.entry[2]] + ' · ' + n.kind.toUpperCase()) + '</p><p class="libcard__meaning">' + esc(n.meaning) + '</p>' +
      '<table><thead><tr><th scope="col">Cas</th><th scope="col">Singulier</th><th scope="col">Pluriel</th></tr></thead><tbody>' + rows + '</tbody></table></article>';
  }
  function genderTable(row, cases, number) {
    return '<table><caption>' + (number === 'sg' ? 'Singulier' : 'Pluriel') + '</caption><thead><tr><th scope="col">Cas</th><th scope="col">Masc.</th><th scope="col">Fém.</th><th scope="col">Neutre</th></tr></thead><tbody>' +
      cases.map(function (name, i) { return '<tr><th scope="row">' + name + '</th><td>' + cellHtml(row[number].m[i]) + '</td><td>' + cellHtml(row[number].f[i]) + '</td><td>' + cellHtml(row[number].n[i]) + '</td></tr>'; }).join('') + '</tbody></table>';
  }
  function libraryBody(tab) {
    var T = LIB.tables;
    if (tab === 'adjectifs') {
      return '<p class="lib-intro">L’adjectif prend le genre, le nombre et le cas du nom, pas sa terminaison : <i lang="la">nautam bonum</i>, <i lang="la">regem bonum</i>.</p>' +
        T.adjectives.map(function (a) { return '<article class="libcard libcard--wide"><h3><i lang="la">' + esc(a.entry.slice(0, 3).join(', ')) + '</i></h3><p class="kicker">' + esc(a.kind.toUpperCase()) + ' · génitif <i lang="la">' + esc(a.entry[3]) + '</i></p><p class="libcard__meaning">' + esc(a.meaning) + '</p><div class="libcard__pair">' + genderTable(a, CASE_NAMES, 'sg') + genderTable(a, CASE_NAMES, 'pl') + '</div></article>'; }).join('');
    }
    if (tab === 'pronoms') {
      return T.pronouns.map(function (p) {
        if (p.personal) {
          var rows = CASE_NAMES.map(function (name, i) { return '<tr><th scope="row">' + name + '</th><td>' + cellHtml(p.sg[i]) + '</td><td>' + cellHtml(p.pl[i]) + '</td></tr>'; }).join('');
          return '<article class="libcard"><h3><i lang="la">' + esc(p.head) + '</i></h3><p class="libcard__meaning">' + esc(p.meaning) + '</p><table><thead><tr><th scope="col">Cas</th><th scope="col">Singulier</th><th scope="col">Pluriel</th></tr></thead><tbody>' + rows + '</tbody></table></article>';
        }
        return '<article class="libcard libcard--wide"><h3><i lang="la">' + esc(p.head) + '</i></h3><p class="libcard__meaning">' + esc(p.meaning) + ' — pas de vocatif.</p><div class="libcard__pair">' + genderTable(p, CASES_NO_VOC, 'sg') + genderTable(p, CASES_NO_VOC, 'pl') + '</div></article>';
      }).join('');
    }
    if (tab === 'fonctions') {
      return '<article class="libcard libcard--wide"><h3>À chaque fonction son cas</h3><table><thead><tr><th scope="col">Cas</th><th scope="col">Fonctions</th><th scope="col">Exemple</th></tr></thead><tbody>' +
        LIB.functions.map(function (f) { return '<tr><th scope="row">' + esc(f[0]) + '</th><td>' + esc(f[1]) + '</td><td><i lang="la">' + esc(f[2]) + '</i><br><small>' + esc(f[3]) + '</small></td></tr>'; }).join('') + '</tbody></table></article>' +
        '<article class="libcard"><h3>Prépositions + accusatif</h3><ul class="libcard__list">' + LIB.prepositions.acc.map(function (p) { return '<li>' + esc(p) + '</li>'; }).join('') + '</ul></article>' +
        '<article class="libcard"><h3>Prépositions + ablatif</h3><ul class="libcard__list">' + LIB.prepositions.abl.map(function (p) { return '<li>' + esc(p) + '</li>'; }).join('') + '</ul></article>' +
        '<article class="libcard"><h3>Lire la déclinaison sur le génitif</h3><table><thead><tr><th scope="col">Décl.</th><th scope="col">Génitif</th><th scope="col">Exemples</th></tr></thead><tbody>' +
        LIB.genitives.map(function (g) { return '<tr><th scope="row">' + esc(g[0]) + '</th><td>' + esc(g[1]) + '</td><td><i lang="la">' + esc(g[2]) + '</i></td></tr>'; }).join('') + '</tbody></table></article>';
    }
    return '<p class="lib-intro">Le dictionnaire donne le nominatif et le génitif : <i lang="la">rosa, rosae</i>. Le génitif dit la déclinaison ; sans sa terminaison, il donne le radical, auquel on ajoute les terminaisons des autres cas.</p>' + T.nouns.map(nounCard).join('');
  }
  function renderLibrary(tab) {
    G.screen = 'library';
    G.libTab = tab || G.libTab;
    var tabs = [['declinaisons', 'Déclinaisons'], ['adjectifs', 'Adjectifs'], ['pronoms', 'Pronoms'], ['fonctions', 'Cas et fonctions']];
    show(topbar('Bibliothèque', glossaryButton() + '<button type="button" class="btn btn--small" data-act="home">Retour à la villa <span class="kbd">Échap</span></button>') +
      '<main class="screen library"><nav class="library__nav" aria-label="Rubriques de la bibliothèque">' + tabs.map(function (t) {
        return '<button type="button" data-act="lib-tab" data-arg="' + t[0] + '"' + (G.libTab === t[0] ? ' aria-current="page" class="is-current"' : '') + '>' + t[1] + '</button>';
      }).join('') + '</nav><div class="library__body"><h2 class="big-title" tabindex="-1" data-autofocus>' + esc(tabs.filter(function (t) { return t[0] === G.libTab; })[0][1]) + '</h2><div class="libgrid">' + libraryBody(G.libTab) + '</div></div></main>');
  }

  /* ---------- the glossary: over any screen ---------- */

  function openGlossary(tab) {
    G.glossTab = tab || G.glossTab;
    var prev = overlay.firstChild ? overlay._prev : document.activeElement;
    var entries = LIB.glossary[G.glossTab === 'grammaire' ? 'grammaire' : 'villa'];
    overlay.innerHTML = '<div class="gloss-backdrop" data-act="glossary-close"></div><aside class="gloss" role="dialog" aria-modal="true" aria-labelledby="gloss-title"><div class="gloss__head"><h2 id="gloss-title">Glossaire</h2><button type="button" class="btn btn--small" data-act="glossary-close">Fermer <span class="kbd">Échap</span></button></div>' +
      '<div class="gloss__tabs" role="group" aria-label="Rubriques du glossaire"><button type="button" data-act="gloss-tab" data-arg="villa" aria-pressed="' + (G.glossTab !== 'grammaire') + '">Villa et objets</button><button type="button" data-act="gloss-tab" data-arg="grammaire" aria-pressed="' + (G.glossTab === 'grammaire') + '">Grammaire</button></div>' +
      '<dl class="gloss__list">' + entries.map(function (e) { return '<dt>' + (G.glossTab === 'grammaire' ? esc(e[0]) : '<i lang="la">' + esc(e[0]) + '</i>') + '</dt><dd>' + esc(e[1]) + '</dd>'; }).join('') + '</dl></aside>';
    overlay._prev = prev;
    app.setAttribute('aria-hidden', 'true');
    var b = overlay.querySelector('[aria-pressed="true"]');
    if (b) b.focus();
  }
  function closeGlossary() {
    var prev = overlay._prev;
    overlay.innerHTML = '';
    app.removeAttribute('aria-hidden');
    if (prev && document.body.contains(prev)) prev.focus();
  }

  /* ---------- 5. the registre des lézardes: every typical error repaired ---------- */

  function bestiaryButton() {
    var n = (G.progress.bestiary || []).length;
    return '<button type="button" class="btn btn--small" data-act="bestiary">Lézardes réparées <b>' + n + '</b></button>';
  }
  function renderBestiary() {
    G.screen = 'bestiary';
    var list = G.progress.bestiary || [], total = G.progress.bestiaryTotal || 0;
    var body = list.length ? '<ul class="sg-bestiary">' + list.map(function (b) {
      return '<li class="sg-bestiary__item">' + E.bestiaryArt('pompei', b.misconception, 96) +
        '<span class="sg-bestiary__unit">' + esc(typo(b.unitName)) + '</span>' +
        '<h3 class="sg-bestiary__label">' + esc(typo(b.label)) + '</h3>' +
        (b.explanation ? '<details><summary>Ce qu’en dit Caius</summary><p class="method">' + esc(typo(plain(b.explanation))) + '</p></details>' : '') +
        '<span class="sg-bestiary__count">Réparée le ' + esc(new Date(b.solvedAt).toLocaleDateString('fr-FR')) + '</span></li>';
    }).join('') + '</ul>'
      : '<p class="sg-bestiary__empty">Aucune lézarde réparée pour l’instant. Quand la même erreur revient deux fois, une lézarde s’ouvre dans un mur ; la réparer l’inscrit ici, avec la règle qui la referme.</p>';
    show(topbar(BESTIARY, tabletsCount() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="home">Retour à la villa <span class="kbd">Échap</span></button>') +
      '<main class="screen bestiary" tabindex="-1"><h2 class="big-title" tabindex="-1" data-autofocus>' + esc(BESTIARY) + '</h2>' +
      '<p class="sg-bestiary__count">' + list.length + ' lézarde' + (list.length > 1 ? 's' : '') + ' réparée' + (list.length > 1 ? 's' : '') + ' sur ' + total + ' erreurs que la villa connaît</p>' +
      body + '</main>');
  }

  /* ---------- 6. the reward scene ---------- */

  function finishText(rw) {
    if (rw.kind !== 'trophy' || !rw.finish) return '';
    var m = { brass: 'en laiton', silver: 'en argent', gold: 'en or' }[rw.finish] || '';
    return rw.raised ? 'passe à ' + ({ brass: 'laiton', silver: 'l’argent', gold: 'l’or' }[rw.finish] || '') : m;
  }
  function showReward() {
    if (layer.firstChild || !G.rewards.length) return;
    var rw = G.rewards[0], art = '';
    try { art = window.SGRewards ? window.SGRewards.art(rw.kind, rw.code, { size: rw.kind === 'card' ? 150 : 180, finish: rw.finish || 'brass' }) : ''; } catch (e) { art = ''; }
    var fin = finishText(rw);
    var prev = document.activeElement;
    layer.innerHTML = '<div class="reward" role="dialog" aria-modal="true" aria-labelledby="reward-name" aria-describedby="reward-lore"><div class="reward__card">' +
      '<span class="kicker">' + (rw.raised ? 'Ta pièce change de finition' : 'Une pièce rejoint ta vitrine') + '</span>' +
      '<div class="reward__art">' + art + '</div>' +
      '<h2 class="reward__name" id="reward-name">' + esc(typo(rw.name)) + (fin ? ' <span class="reward__finish">' + esc(fin) + '</span>' : '') + '</h2>' +
      '<p class="reward__lore" id="reward-lore">' + esc(typo(rw.lore || (rw.kind === 'card' ? 'Une carte de la villa, pour ta vitrine.' : rw.kind === 'pin' ? 'Elle se porte sur ton avatar, depuis ta page joueur.' : ''))) + '</p>' +
      '<button type="button" class="btn btn--primary" data-act="reward-close">' + (G.rewards.length > 1 ? 'Pièce suivante' : 'Continuer') + ' <span class="kbd">Entrée</span></button></div></div>';
    layer.querySelector('[data-act="reward-close"]').focus();
    E.sound.play('reward');
    layer._prev = prev;
    app.setAttribute('aria-hidden', 'true');
  }
  function closeReward() {
    G.rewards.shift();
    var prev = layer._prev;
    layer.innerHTML = '';
    app.removeAttribute('aria-hidden');
    if (G.rewards.length) { showReward(); return; }
    var back = app.querySelector('[data-autofocus]') || (prev && document.body.contains(prev) ? prev : null);
    if (back) back.focus();
  }

  /* ---------- navigation ---------- */

  function refresh() {
    return call('getProgress', {}).then(function (p) {
      G.progress = p;
      if (p.data && typeof p.data === 'object' && !Array.isArray(p.data)) G.data = Object.assign({}, p.data, G.data);
      return p;
    });
  }
  function goHome() {
    G.run = null;
    G.selected = null;
    G.dlg = null;
    renderHome();
  }
  function fail(e) {
    G.busy = false;
    toast(errorText(e));
  }

  var ACTIONS = {
    exit: function () { SDK.exit(); },
    home: goHome,
    list: renderList,
    bestiary: renderBestiary,
    sound: function () {
      E.sound.setMuted(!E.sound.muted());
      G.data.mute = E.sound.muted();
      save();
      var b = app.querySelector('[data-act="sound"]');
      if (b) { b.outerHTML = E.sound.button(); b = app.querySelector('[data-act="sound"]'); if (b) b.focus(); }
      if (!E.sound.muted()) E.sound.play('ok', 1);
    },
    library: function () { renderLibrary(); },
    'lib-tab': function (arg) { renderLibrary(arg); var b = app.querySelector('[data-arg="' + arg + '"]'); if (b) b.focus(); },
    glossary: function () { openGlossary(); },
    'glossary-close': closeGlossary,
    'gloss-tab': function (arg) { openGlossary(arg); },
    'dlg-next': function () { G.dlg.i = Math.min(G.dlg.i + 1, G.dlg.lines.length - 1); renderHome(); },
    room: function (arg) { G.room = arg; G.selected = null; renderHome('.roompanel__name'); },
    pick: function (arg) { var u = unitOf(arg); G.selected = arg; G.room = u ? u.chapter : G.room; G.data.last = arg; if (G.screen !== 'home') G.screen = 'home'; renderHome(); },
    deselect: function () {
      if (G.selected) { var was = G.selected; G.selected = null; renderHome('[data-arg="' + was + '"].tabcard'); return; }
      var room = G.room; G.room = null; renderHome(room ? '[data-arg="' + room + '"].room__head' : null);
    },
    series: function (arg) { startSeries(arg); },
    comet: function (arg) { startComet(arg); },
    'comet-go': function () { nextQuestion(); },
    'final-intro': renderFinalIntro,
    'final-go': startFinal,
    'final-end': function () {
      if (!G.confirmEnd) { G.confirmEnd = true; var b = app.querySelector('[data-act="final-end"]'); if (b) { b.textContent = 'Confirmer : terminer maintenant'; b.focus(); } return; }
      G.confirmEnd = false;
      if (G.run) G.run.endedByPlayer = true;
      endRun();
    },
    resume: resume,
    leave: function () { G.run = null; G.selected = null; G.dlg = null; renderHome(); },
    choose: function (arg) {
      var run = G.run;
      if (!run || run.correction) return;
      run.choice = +arg;
      Array.prototype.forEach.call(app.querySelectorAll('.choice'), function (b, i) {
        b.classList.toggle('is-chosen', i === run.choice);
        b.setAttribute('aria-checked', String(i === run.choice));
      });
    },
    submit: function () { submit(false); },
    'dont-know': function () { submit(true); },
    next: afterCorrection,
    key: insertKey,
    draft: function () {
      var run = G.run, inp = document.getElementById('answer');
      if (inp && !inp.readOnly) run.typed = inp.value;
      run.draftOpen = !run.draftOpen;
      renderRun();
      var ta = document.getElementById('draft-text');
      if (ta) ta.focus(); else { var b = app.querySelector('[data-act="draft"]'); if (b) b.focus(); }
    },
    'draft-clear': function () { var ta = document.getElementById('draft-text'); if (ta) { ta.value = ''; G.run.draft = ''; ta.focus(); } },
    'reward-close': closeReward,
    'toast-close': function () { var t = document.querySelector('.toast'); if (t) t.remove(); }
  };

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b || b.disabled) return;
    var fn = ACTIONS[b.getAttribute('data-act')];
    if (!fn) return;
    e.preventDefault();
    if (G.busy && ['toast-close', 'reward-close', 'key', 'draft', 'draft-clear', 'choose', 'glossary', 'glossary-close', 'gloss-tab', 'sound'].indexOf(b.getAttribute('data-act')) < 0) return;
    fn(b.getAttribute('data-arg'));
  });

  document.addEventListener('keydown', function (e) {
    var typing = e.target && /^(INPUT|TEXTAREA)$/.test(e.target.tagName);
    // a QCM answers from the keyboard: 1-4 or A-D choose, Entrée validates
    if (G.screen === 'run' && G.run && G.run.q && G.run.q.answerType === 'choice' && !G.run.correction && !typing && !overlay.firstChild && !layer.firstChild) {
      var n = '1234'.indexOf(e.key) >= 0 ? +e.key - 1 : 'abcd'.indexOf((e.key || '').toLowerCase());
      if (n >= 0 && n < (G.run.q.choices || []).length) { e.preventDefault(); ACTIONS.choose(String(n)); app.querySelectorAll('.choice')[n].focus(); return; }
      if (e.key === 'Enter' && G.run.choice !== null && G.run.choice !== undefined && !e.target.closest('[data-act]:not(.choice)')) { e.preventDefault(); submit(false); return; }
    }
    if (e.key !== 'Escape') return;
    if (layer.firstChild) { e.preventDefault(); closeReward(); return; }
    if (overlay.firstChild) { e.preventDefault(); closeGlossary(); return; }
    var t = document.querySelector('.toast');
    if (t) { t.remove(); return; }
    if (G.screen === 'home' && (G.selected || G.room)) { e.preventDefault(); ACTIONS.deselect(); return; }
    if (G.screen === 'list' || G.screen === 'finalIntro' || G.screen === 'library' || G.screen === 'bestiary' || G.screen === 'seriesEnd') { e.preventDefault(); goHome(); return; }
    if (G.screen === 'run' && G.run && G.run.draftOpen && document.activeElement && document.activeElement.id === 'draft-text') {
      e.preventDefault();
      var inp = document.getElementById('answer') || document.getElementById('cell-0') || app.querySelector('.choice');
      if (inp) inp.focus();
    }
  });

  // the focus stays inside a dialog
  document.addEventListener('focusin', function (e) {
    var box = layer.firstChild ? layer : overlay.firstChild ? overlay : null;
    if (box && !box.contains(e.target)) {
      var b = box.querySelector('button');
      if (b) b.focus();
    }
  });

  SDK.onReward(function (rewards) {
    (rewards || []).forEach(function (rw) { G.rewards.push(rw); });
    setTimeout(showReward, reduceMotion ? 0 : 450);
  });

  /* ---------- start ---------- */

  Promise.all([call('getUser', {}), call('getProgress', {})]).then(function (res) {
    G.user = res[0];
    G.progress = res[1];
    G.data = res[1].data && typeof res[1].data === 'object' && !Array.isArray(res[1].data) ? res[1].data : {};
    if (G.data.last && !unitOf(G.data.last)) G.data.last = null;
    E.sound.voice('lyre');
    E.sound.setMuted(G.data.mute === true); // on by default
    renderHome(false);
  }, function (e) {
    app.innerHTML = '<p class="loading" role="alert">' + esc(errorText(e)) + '</p>';
  });
})();
