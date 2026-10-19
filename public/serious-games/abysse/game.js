/* Station Abysse — the game (design/validated/serious-games.md §1, §6, §8;
   design/validated/serious-games-abysse.md).

   2031. The station rests 3 000 m down on the mid-Atlantic ridge, beside a field of hydrothermal
   vents. The learner, a biologist on a first stay, fills the station's notebook of species under
   the eye of Commandante Ysé Marin. Each species is one SVT skill: a dive of 12 observations,
   descending through three depths (200 m, 1 000 m, 3 000 m - the server serves 4 questions of each
   difficulty), catalogues it from 6/12, and its sketch is its best dive (esquissée 6-7, annotée
   8-9, coloriée 10-11, encadrée 12): it never fades. A wrong observation lets the specimen escape;
   the dive goes on. A typical error made twice shows as an anomaly on the sonar, identified in 3
   observations. When the whole notebook is filled, the ascent opens: 12 observations of every
   zone, in the time the server gives, corrected at the surface.

   The platform generates and corrects: this page never sees an answer before it is corrected, and
   writes nothing but its own display state (saveProgress). Nothing depends on time or absence. The
   ascent's clock is the platform's - shown in its bar, kept here only to close the session.

   Screens: home (porthole, notebook, the commandante) · list · run (a dive, an anomaly or the
   ascent) · seriesEnd · cometIntro · cometEnd · finalIntro · review; the glossary and the scratch
   pad open over any screen. */
(function () {
  'use strict';

  var SDK = window.SGSdk, Scene = window.ABYScene, GLOSS = window.ABYGlossary, esc = Scene.esc, E = window.SGEngage;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- wording ---------- */

  var LEVEL = { discrete: 'esquissée', clear: 'annotée', bright: 'coloriée', blazing: 'encadrée' };
  var PLURALS = { blazing: ['encadrée', 'encadrées'], bright: ['coloriée', 'coloriées'], clear: ['annotée', 'annotées'], discrete: ['esquissée', 'esquissées'] };
  var GUIDE = 'Commandante Ysé Marin';
  var DEPTHS = [200, 1000, 3000];

  var PROLOGUE = [
    'Bienvenue à bord, biologiste. Je suis Ysé Marin, je pilote cette station depuis vingt ans. Ici, ce qu’on ne note pas n’existe pas.',
    'Le carnet de la mission précédente est vide aux deux tiers : trente espèces à cataloguer. Le bathyscaphe de relève attend, amarré. Il ne remontera qu’avec un carnet complet.',
    'Douze observations, trois paliers, six justes : l’espèce entre au carnet. Et son croquis garde ta meilleure plongée, quoi qu’il arrive ensuite. Choisis une zone dans le carnet.'
  ];
  var BEATS = {
    8: ['Te voilà plongeur·se scientifique. Tu ne regardes plus le hublot : tu l’observes.',
      'Si une même erreur revient deux fois, une anomalie s’affiche sur le sonar. Trois observations suffisent pour l’identifier, et rien ne presse.'],
    15: ['Biologiste de plongée ! La moitié du carnet est remplie.',
      'Les équipes de surface ont lu tes croquis ce matin. Elles en redemandent.'],
    23: ['Chef·fe de mission. Il reste quelques pages blanches, souvent les plus profondes.',
      'Le bathyscaphe chauffe ses moteurs. Finis le carnet.'],
    all: ['Le carnet est complet. Trente espèces, toutes croquées de ta main.',
      'La remontée peut commencer : douze observations de tout le carnet pendant que le bathyscaphe monte, corrigées à la surface. Tu peux la refaire autant de fois que tu veux. Et garde ce nautile : il est à toi.']
  };
  var GREET = [
    'Le carnet est presque vide. Choisis une espèce : six observations justes sur douze suffisent pour la cataloguer.',
    'Tu connais maintenant le chemin du hublot. Le carnet commence à te ressembler.',
    'La moitié des pages est remplie. Plonger vite ou lentement ne change rien à un croquis.',
    'Il reste peu de pages blanches, et ce sont souvent les plus profondes. Prends le temps qu’il faut.',
    'Le carnet est complet. Le bathyscaphe attend la remontée.'
  ];
  var AFTER_ERROR = [
    'Il nous a filé entre les doigts. On replonge quand tu veux : une plongée moins bonne n’efface rien.',
    'Moi aussi, j’ai lu des flèches à l’envers pendant des années. Relis toujours la légende.',
    'Une erreur ne retire rien à ta meilleure plongée.'
  ];
  // what each level is called by the gauge of a dive in progress (shared/engage.js)
  var GAUGE_LEVELS = {
    6: ['cataloguer l’espèce', 'Espèce cataloguée !'],
    8: ['un croquis annoté', 'Croquis annoté !'],
    10: ['un croquis colorié', 'Croquis colorié !'],
    12: ['un croquis encadré', 'Douze sur douze : croquis encadré !']
  };
  var BESTIARY = 'Registre des anomalies';
  var AFTER_LIT = ['Espèce cataloguée. Elle entre au carnet.', 'Une page de plus au carnet.', 'Bien observé. Les équipes de surface vont aimer ce croquis.'];
  var OK_LINES = ['Spécimen consigné.', 'Bien observé. On descend.', 'Le carnet te remercie.'];
  var KO_LINES = ['Le spécimen a filé. On continue la descente.', 'Pas tout à fait. On garde notre calme et on descend.'];

  // What to type, per answer type (never a hint about the answer itself)
  var TYPING = {
    number: { help: 'Un nombre : virgule ou point.', keys: [] },
    fraction: { help: 'Un nombre ou une fraction.', keys: [] },
    sequence: { help: 'Lettres A, T, C, G (ou U) ; les espaces ne comptent pas.', keys: ['A', 'T', 'C', 'G', 'U'] },
    text: { help: 'Un mot ou une réponse courte.', keys: [] },
    choice: { help: 'Choisis une proposition (touches 1 à 4), puis valide.', keys: [] },
    order: { help: 'Touche les éléments dans l’ordre : chacun prend la place suivante. Touche une place remplie pour la vider.', keys: [] }
  };
  var ERRORS = {
    network: 'La liaison avec Formatool est coupée. Vérifie la connexion, puis réessaie.',
    timeout: 'Formatool ne répond pas. Ce jeu s’ouvre depuis la page Serious games de Formatool.',
    'unit-unavailable': 'Cette espèce n’est pas observable pour le moment.',
    'final-locked': 'La remontée n’a lieu qu’une fois toutes les espèces cataloguées.',
    'comet-not-in-view': 'Cette anomalie est déjà identifiée.',
    'session-ended': 'Cette plongée est déjà terminée.',
    'session-unknown': 'Cette plongée n’existe plus.',
    'data-too-large': 'Le carnet est trop chargé pour être rangé.'
  };

  /* ---------- state ---------- */

  var G = {
    user: null,
    progress: null,
    data: {},            // display state only, saved with saveProgress
    screen: 'home',
    zone: null,          // the zone whose panel is open on the main screen
    selected: null,      // the species whose card is open
    dlg: null,
    run: null,
    busy: false,
    fresh: null,         // a species catalogued by the last dive, drawn once
    confirmEnd: false,
    rewards: [],
    endRes: null,
    glossTab: 'station'
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
  function levelWord(u) { return u.lit ? LEVEL[u.brightness] : u.series > 0 ? 'observée' : 'à cataloguer'; }
  function metres(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' m'; }
  function nextLevel(best) {
    if (best === null || best === undefined || best < 6) return 'cataloguée, dès 6/12';
    if (best < 8) return 'annotée, dès 8/12';
    if (best < 10) return 'coloriée, dès 10/12';
    if (best < 12) return 'encadrée, à 12/12';
    return 'au plus haut : encadrée';
  }
  function speciesName(u) {
    var name = typo(u.label);
    if (u.lit) return name + ' — espèce cataloguée, croquis ' + LEVEL[u.brightness] + ' (meilleure plongée ' + u.best + '/12)';
    if (u.series > 0 && u.best !== null) return name + ' — pas encore cataloguée (meilleure plongée ' + u.best + '/12)';
    return name + ' — à cataloguer';
  }
  function levelsLabel() {
    var c = { blazing: 0, bright: 0, clear: 0, discrete: 0 };
    G.progress.units.forEach(function (u) { if (u.lit && !u.hidden && c[u.brightness] !== undefined) c[u.brightness]++; });
    var parts = ['blazing', 'bright', 'clear', 'discrete'].filter(function (k) { return c[k]; }).map(function (k) { return c[k] + ' ' + PLURALS[k][c[k] > 1 ? 1 : 0]; });
    return parts.length ? parts.join(' · ') : 'aucune espèce cataloguée';
  }
  function cometsIn(chapter) {
    return (G.progress.comets || []).filter(function (c) { var u = unitOf(c.unit); return u && u.chapter === chapter; });
  }
  function isExplored(ch) { return ch.total > 0 && ch.lit >= ch.total; }
  function depthAt(position) { return DEPTHS[Math.min(2, Math.floor(Math.min(11, position) / 4))]; }

  /* The species the commandante suggests: one not yet catalogued in the zone furthest along (the
     one already nearest to 6), else the least advanced catalogued species. */
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
    if (best) return { unit: best.unit, kind: 'catalogue' };
    var dim = G.progress.units.filter(function (u) { return !u.hidden && (u.best || 0) < 12; }).sort(function (a, b) { return (a.best || 0) - (b.best || 0); })[0];
    return dim ? { unit: dim, kind: 'better' } : null;
  }
  function speciesLine(u) {
    var head = '<b>' + esc(typo(u.label)) + '</b>, ' + esc(chapterName(u)) + '. ';
    if (u.lit) return head + 'Croquis ' + LEVEL[u.brightness] + ' : ta meilleure plongée est de ' + u.best + '/12.';
    if (u.series > 0) return head + 'Pas encore cataloguée : ta meilleure plongée est de ' + u.best + '/12. Il en faut 6.';
    return head + 'Page blanche. Six observations justes sur douze la cataloguent.';
  }

  /* ---------- the top bar ---------- */

  function topbar(sub, right) {
    return '<header class="topbar"><div class="topbar__title"><h1 class="topbar__name">Station Abysse</h1><span class="topbar__sub">' + esc(sub) + '</span></div>' +
      '<div class="topbar__right">' + right + E.sound.button() + '</div></header>';
  }
  function speciesCount() {
    return '<span class="count" aria-label="' + G.progress.lit + ' espèces cataloguées sur ' + G.progress.total + '"><b>' + G.progress.lit + '</b>/' + G.progress.total + ' ESPÈCES</span>';
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

  /* ---------- 1. the main screen: the porthole, the notebook, the commandante ---------- */

  function homeDialog() {
    var p = G.progress, read = G.data.read || [], lines, id = null;
    if (read.indexOf('prologue') < 0) {
      lines = PROLOGUE.slice(); id = 'prologue';
    } else if (p.finished && read.indexOf('gall') < 0) {
      lines = BEATS.all.slice(); id = 'gall';
    } else {
      [23, 15, 8].some(function (th) {
        if (p.lit >= th && read.indexOf('g' + th) < 0) { lines = BEATS[th].slice(); id = 'g' + th; return true; }
        return false;
      });
    }
    var sg = suggestion();
    if (!lines) {
      var stage = p.finished ? 4 : p.lit >= 23 ? 3 : p.lit >= 15 ? 2 : p.lit >= 8 ? 1 : 0;
      lines = [GREET[stage]];
      if (p.final.unlocked) {
        lines.push(p.final.best === 12 ? 'Douze sur douze pendant la remontée. Planche complète, je l’encadre. Tu peux y retourner pour le plaisir.' : 'La remontée : douze observations de tout le carnet, ' + minutes(p.final.timeLimitSeconds) + ' minutes, et la correction seulement à la surface.');
      } else if (sg) {
        lines.push((sg.kind === 'catalogue' ? 'L’espèce la plus proche d’entrer au carnet : ' : 'Tout est catalogué. Le croquis le moins avancé : ') + speciesLine(sg.unit));
      }
      if (p.comets.length) lines.push('Le sonar montre une anomalie. Elle vient d’une erreur qui revient ; tu peux l’identifier maintenant, ou plus tard.');
    }
    var acts = [];
    var open = G.data.open;
    if (open && open.session) acts.push({ label: open.mode === 'final' ? 'Reprendre la remontée' : open.mode === 'comet' ? 'Reprendre l’analyse' : 'Reprendre : ' + typo((unitOf(open.unit) || {}).label || 'la plongée'), primary: true, act: 'resume' });
    if (p.final.unlocked) acts.push({ label: 'La remontée', primary: !acts.length, act: 'final-intro' });
    else if (sg) acts.push({ label: 'Plonger : ' + typo(sg.unit.label), primary: !acts.length, act: 'series', arg: sg.unit.code });
    if (p.comets.length) acts.push({ label: 'Analyser l’anomalie', act: 'comet', arg: p.comets[0].misconception });
    acts.push({ label: 'Tout le carnet', act: 'list' });
    return { who: GUIDE, lines: lines, i: 0, id: id, actions: acts };
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

  function zonePanelHtml(ch) {
    var units = G.progress.units.filter(function (u) { return u.chapter === ch.code; });
    var anomalies = cometsIn(ch.code);
    var cards = units.map(function (u) {
      if (u.hidden) return '<li class="spcard is-hidden">' + Scene.sketch(ch.code, null, 40) + '<b>' + esc(typo(u.label)) + '</b><span class="spcard__state">indisponible</span></li>';
      return '<li><button type="button" class="spcard' + (G.selected === u.code ? ' is-selected' : '') + '" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(speciesName(u)) + '">' + Scene.sketch(ch.code, stateOf(u), 40) +
        '<b>' + esc(typo(u.label)) + '</b><span class="spcard__state">' + esc(levelWord(u).toUpperCase()) + '<em>' + (u.best === null || u.best === undefined ? '—' : u.best + '/12') + '</em></span></button></li>';
    }).join('');
    return '<div class="zonepanel"><div class="zonepanel__head"><h2 class="zonepanel__name" tabindex="-1">' + esc(ch.label) + '</h2><span class="kicker">' + esc(ch.topic.toUpperCase()) + '</span>' +
      '<span class="kicker">' + ch.lit + '/' + ch.total + ' CATALOGUÉE' + (ch.lit > 1 ? 'S' : '') + (isExplored(ch) ? ' · ZONE EXPLORÉE' : '') + '</span>' +
      anomalies.map(function (c) { return '<button type="button" class="btn btn--anomaly" data-act="comet" data-arg="' + esc(c.misconception) + '">' + Scene.blip(20) + ' Anomalie · Analyser</button>'; }).join('') +
      '<button type="button" class="btn btn--small" data-act="deselect">Retour à la commandante <span class="kbd">Échap</span></button></div>' +
      '<ul class="spcards">' + cards + '</ul></div>';
  }

  function speciesCardHtml(u) {
    var ch = chapterOf(u.chapter);
    return '<div class="speciescard">' + Scene.sketch(u.chapter, stateOf(u), 110) + '<div><div class="kicker">' + esc(ch ? ch.label.toUpperCase() : '') + '</div><h2 class="speciescard__name" tabindex="-1">' + esc(typo(u.label)) + '</h2>' +
      '<dl class="facts"><dt>Croquis</dt><dd>' + esc(levelWord(u)) + '</dd><dt>Meilleure plongée</dt><dd class="num">' + (u.best === null || u.best === undefined ? '—' : u.best + '/12') + '</dd>' +
      '<dt>Prochain niveau</dt><dd>' + esc(nextLevel(u.best)) + '</dd></dl></div>' +
      '<div class="speciescard__acts"><button type="button" class="btn btn--primary" data-act="series" data-arg="' + esc(u.code) + '" data-autofocus>Plonger : 12 observations <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn btn--small" data-act="zone" data-arg="' + esc(u.chapter) + '">Retour à la zone <span class="kbd">Échap</span></button></div></div>';
  }

  function notebookHtml() {
    var p = G.progress;
    var zones = p.chapters.map(function (ch) {
      var explored = isExplored(ch);
      var items = p.units.filter(function (u) { return u.chapter === ch.code; }).map(function (u) {
        if (u.hidden) return '<span class="nb__sp is-hidden">' + Scene.sketch(ch.code, null, 30) + '</span>';
        return '<button type="button" class="nb__sp' + (G.fresh === u.code ? ' is-fresh' : '') + (G.selected === u.code ? ' is-selected' : '') + '" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(speciesName(u)) + '">' + Scene.sketch(ch.code, stateOf(u), 30) + '</button>';
      }).join('');
      var blips = cometsIn(ch.code).map(function (c) {
        return '<button type="button" class="nb__blip" data-act="comet" data-arg="' + esc(c.misconception) + '" aria-label="Anomalie au sonar : ' + esc(typo(c.label)) + ' — l’analyser">' + Scene.blip(24) + '</button>';
      }).join('');
      return '<div class="nb__zone' + (explored ? ' is-explored' : '') + (G.zone === ch.code ? ' is-selected' : '') + '">' +
        '<button type="button" class="nb__head" data-act="zone" data-arg="' + esc(ch.code) + '" aria-label="' + esc(ch.label + ' : ' + ch.lit + ' espèces cataloguées sur ' + ch.total + (explored ? ', zone explorée' : '')) + '"><span class="nb__name">' + esc(ch.label) + '</span><span class="nb__count">' + ch.lit + '/' + ch.total + '</span></button>' +
        '<div class="nb__row">' + items + blips + '</div></div>';
    }).join('');
    var f = p.final;
    var ascent = f.unlocked
      ? '<button type="button" class="nb__ascent is-open" data-act="final-intro" aria-label="La remontée — l’épreuve' + (f.best !== null && f.best !== undefined ? ', meilleur score ' + f.best + '/12' : '') + '"><span class="nb__name">La Remontée</span><span class="nb__count">' + (f.best !== null && f.best !== undefined ? f.best + '/12' : minutes(f.timeLimitSeconds) + ' min') + '</span></button>'
      : '<div class="nb__ascent" aria-label="La remontée : elle a lieu quand tout le carnet est rempli"><span class="nb__name">La Remontée</span><span class="nb__count">carnet incomplet</span></div>';
    G.fresh = null;
    return '<div class="notebook" role="group" aria-label="Carnet d’espèces : ' + p.lit + ' espèces cataloguées sur ' + p.total + '"><div class="notebook__head"><span class="kicker">CARNET D’ESPÈCES · STATION ABYSSE</span></div>' + zones + ascent + '</div>';
  }

  function renderHome(focusSel) {
    G.screen = 'home';
    G.confirmEnd = false;
    if (!G.dlg) G.dlg = homeDialog();
    var u = G.selected ? unitOf(G.selected) : null, ch = !u && G.zone ? chapterOf(G.zone) : null;
    var panel = u ? speciesCardHtml(u) : ch ? zonePanelHtml(ch) : dialogHtml();
    var depth = G.progress.finished ? 3 : G.progress.lit >= 15 ? 2 : G.progress.lit >= 8 ? 1 : 0;
    var html = topbar('SVT · 2NDE–1RE', speciesCount() + bestiaryButton() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="exit">Quitter</button>') +
      '<main class="home"><div class="scene-cell">' + Scene.scene(G.user.avatar, { anomalies: G.progress.comets.length > 0, depth: depth }) +
      '<div class="status"><span class="status__grade">' + esc(G.progress.grade) + '</span><span class="status__levels">' + esc(levelsLabel().toUpperCase()) + '</span></div></div>' +
      '<div class="nb-cell">' + notebookHtml() + '</div>' +
      '<section class="panel" aria-label="' + (u ? 'Espèce choisie' : ch ? 'Zone choisie' : 'La commandante') + '">' + panel + '</section></main>';
    show(html, focusSel);
    if (!u && !ch) markRead();
  }

  function markRead() {
    var d = G.dlg;
    if (!d || !d.id || d.i < d.lines.length - 1) return;
    var read = G.data.read || [];
    if (read.indexOf(d.id) >= 0) return;
    read.push(d.id);
    if (/^g\d+$/.test(d.id)) [8, 15, 23].forEach(function (th) { if (th < +d.id.slice(1) && read.indexOf('g' + th) < 0) read.push('g' + th); });
    if (d.id === 'gall') [8, 15, 23].forEach(function (th) { if (read.indexOf('g' + th) < 0) read.push('g' + th); });
    G.data.read = read;
    save();
  }

  /* ---------- 2. the whole notebook, zone by zone ---------- */

  function renderList() {
    G.screen = 'list';
    var p = G.progress;
    var cols = p.chapters.map(function (ch) {
      var items = p.units.filter(function (u) { return u.chapter === ch.code; }).map(function (u) {
        if (u.hidden) return '<li class="is-hidden">' + Scene.sketch(ch.code, null, 30) + '<span>' + esc(typo(u.label)) + '</span><em>indisponible</em></li>';
        var state = u.lit ? LEVEL[u.brightness] + ' · ' + u.best + '/12' : u.series ? 'observée · ' + u.best + '/12' : 'à cataloguer';
        return '<li><button type="button" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(speciesName(u)) + '">' + Scene.sketch(ch.code, stateOf(u), 30) + '<span>' + esc(typo(u.label)) + '</span><em>' + esc(state) + '</em></button></li>';
      }).join('');
      return '<section aria-labelledby="ch-' + esc(ch.code) + '"><h2 id="ch-' + esc(ch.code) + '">' + esc(ch.label) + ' <small>' + ch.lit + '/' + ch.total + '</small></h2><p class="kicker">' + esc(ch.topic) + '</p><ul>' + items + '</ul></section>';
    }).join('');
    var anomalies = p.comets.length ? '<section aria-labelledby="ch-anomalies"><h2 id="ch-anomalies">Anomalies à identifier <small>' + p.comets.length + '</small></h2><ul>' + p.comets.map(function (c) {
      return '<li><button type="button" data-act="comet" data-arg="' + esc(c.misconception) + '">' + Scene.blip(24) + '<span>' + esc(typo(c.label)) + '</span><em>' + esc(typo(c.unitName)) + '</em></button></li>';
    }).join('') + '</ul></section>' : '';
    var ascent = '<section aria-labelledby="ch-ascent"><h2 id="ch-ascent">La remontée <small>' + (p.final.best !== null && p.final.best !== undefined ? p.final.best + '/12' : '—') + '</small></h2><ul><li>' +
      (p.final.unlocked ? '<button type="button" data-act="final-intro"><span>L’épreuve</span><em>' + minutes(p.final.timeLimitSeconds) + ' min</em></button>' : '<span class="is-hidden">A lieu quand toutes les espèces sont cataloguées.</span>') + '</li></ul></section>';
    show(topbar('Tout le carnet', speciesCount() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="home">Retour au hublot <span class="kbd">Échap</span></button>') +
      '<main class="screen" tabindex="-1"><h2 class="big-title" tabindex="-1" data-autofocus>Le carnet d’espèces</h2>' +
      '<p class="kicker">Plongées illimitées · 12 observations · trois paliers · sans chronomètre</p><div class="list">' + cols + anomalies + ascent + '</div>' +
      '<p style="margin-top:22px">' + bestiaryButton() + '</p></main>');
  }

  /* ---------- 3. a run: a dive, an anomaly or the ascent ---------- */

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
      run.order = [];
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
    if (run.mode === 'final') return 'La remontée' + (run.q ? ' · ' + chapterName(unitOf(run.q.unit)) : '');
    var u = unitOf(run.unit || (run.q && run.q.unit));
    if (run.mode === 'comet') return 'Anomalie · ' + typo(run.comet && run.comet.label ? run.comet.label : u ? u.label : '');
    return (u ? chapterName(u) + ' · ' + typo(u.label) : '');
  }

  /* The depth of the observation in hand: the dive goes down by tiers, the ascent comes up. */
  function depthHtml(run) {
    if (run.mode === 'series') {
      var d = depthAt(run.position || 0);
      return '<span class="depth" aria-label="Profondeur : ' + metres(d) + '">PROF. <b>' + metres(d) + '</b>' +
        '<span class="depth__gauge" aria-hidden="true">' + DEPTHS.map(function (m) { return '<i class="' + (m === d ? 'is-cur' : m < d ? 'is-past' : '') + '"></i>'; }).join('') + '</span></span>';
    }
    if (run.mode === 'final') {
      var up = Math.max(0, 3000 - (run.position || 0) * 250);
      return '<span class="depth" aria-label="Profondeur du bathyscaphe : ' + metres(up) + '">PROF. <b>' + metres(up) + '</b></span>';
    }
    return '';
  }

  function progressHtml(run) {
    var items = [];
    for (var k = 0; k < run.size; k++) {
      var r = run.results[k];
      var tier = run.mode === 'series' && (k === 4 || k === 8) ? ' tier' : '';
      if (run.mode === 'final') items.push('<li class="' + (k === run.position ? 'cur' : r ? 'done' : '') + '"></li>');
      else items.push('<li class="' + (k === run.position && !run.correction ? 'cur' : r === 'ok' ? 'ok' : r === 'ko' || r === 'dk' ? 'ko' : r ? 'done' : '') + tier + '"></li>');
    }
    var label = run.mode === 'final' ? 'Observations faites : ' + run.results.filter(Boolean).length + ' sur ' + run.size : 'Avancement de la plongée';
    return '<ol class="progress" aria-label="' + esc(label) + '">' + items.join('') + '</ol>';
  }

  function orderHtml(run, q, done, c) {
    var items = q.choices || [], labels = q.labels || [];
    if (done) {
      var sent = c.dontKnow ? [] : String(c.input || '').split('|');
      var expected = c.expectedCells || [];
      return '<div class="order is-done"><ol class="order__slots">' + expected.map(function (x, i) {
        var ok = c.cells ? c.cells[i] : false, given = sent[i] || '—';
        return '<li class="order__slot ' + (ok ? 'is-ok' : 'is-ko') + '"><span class="order__n">' + (i + 1) + '</span><span class="order__text">' + esc(given) + '</span>' +
          (!ok ? '<span class="order__expected">' + esc(x) + '</span>' : '') + '</li>';
      }).join('') + '</ol></div>';
    }
    var placed = run.order || [];
    var slots = items.map(function (x, i) {
      var idx = placed[i];
      var label = i === 0 && labels[0] ? '<span class="order__end">' + esc(labels[0]) + '</span>' : i === items.length - 1 && labels[labels.length - 1] ? '<span class="order__end">' + esc(labels[labels.length - 1]) + '</span>' : '';
      if (idx === undefined) return '<li class="order__slot is-empty"><span class="order__n">' + (i + 1) + '</span><span class="order__text">' + (i === placed.length ? 'à placer' : '') + '</span>' + label + '</li>';
      return '<li class="order__slot"><button type="button" class="order__placed" data-act="unplace" data-arg="' + i + '" aria-label="Place ' + (i + 1) + ' : ' + esc(plain(items[idx].html)) + ' — retirer"><span class="order__n">' + (i + 1) + '</span><span class="order__text">' + items[idx].html + '</span><span class="order__x" aria-hidden="true">×</span></button>' + label + '</li>';
    }).join('');
    var pool = items.map(function (x, i) {
      if (placed.indexOf(i) >= 0) return '';
      return '<button type="button" class="order__item" data-act="place" data-arg="' + i + '"' + (placed.length === 0 && i === firstFree(placed, items) ? ' data-first' : '') + '>' + x.html + '</button>';
    }).join('');
    return '<div class="order"><ol class="order__slots" aria-label="L’ordre choisi">' + slots + '</ol>' +
      '<div class="order__pool" role="group" aria-label="Éléments à placer">' + (pool || '<span class="order__done">Tout est placé. Valide, ou touche une place pour la vider.</span>') + '</div>' +
      (placed.length ? '<button type="button" class="btn btn--small order__reset" data-act="order-reset">Tout reprendre</button>' : '') + '</div>';
  }
  function firstFree(placed, items) { for (var i = 0; i < items.length; i++) if (placed.indexOf(i) < 0) return i; return -1; }

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
    if (type === 'order') return orderHtml(run, q, done, c);
    var seq = type === 'sequence';
    var field = '<div class="field' + (seq ? ' field--seq' : '') + (done ? (c.ok ? ' is-ok' : ' is-ko') : '') + '"><input id="answer" type="text" inputmode="' + (type === 'number' || type === 'fraction' ? 'decimal' : 'text') + '" autocomplete="off" autocapitalize="' + (seq ? 'characters' : 'off') + '" spellcheck="false" aria-describedby="typing-help" aria-label="Ta réponse" placeholder="' + (done ? '' : 'Ta réponse') + '"' +
      (done ? ' readonly value="' + esc(c.dontKnow ? '—' : (c.input || '')) + '"' : ' value="' + esc(run.typed || '') + '"') + '>' +
      (q.unit_label ? '<span class="field__unit">' + esc(q.unit_label) + '</span>' : '') + '</div>';
    return field;
  }

  function renderRun() {
    G.screen = 'run';
    var run = G.run, q = run.q, done = !!run.correction, c = run.correction;
    var t = TYPING[q.answerType] || TYPING.text;
    var right = '<button type="button" class="btn btn--small" data-act="draft" aria-expanded="' + !!run.draftOpen + '" aria-controls="draft">' + (run.draftOpen ? 'Fermer le brouillon' : 'Brouillon') + '</button>' +
      (run.mode === 'final'
        ? '<button type="button" class="btn btn--small" data-act="final-end">' + (G.confirmEnd ? 'Confirmer : terminer maintenant' : 'Terminer la remontée') + '</button>'
        : '<button type="button" class="btn btn--small" data-act="leave">' + (run.mode === 'comet' ? 'Retour au hublot' : 'Quitter la plongée') + '</button>');
    right = glossaryButton() + right;
    var sub = run.mode === 'final' ? 'La remontée' : run.mode === 'comet' ? 'Analyser l’anomalie' : 'Plongée de 12';

    var chip = done ? (c.dontKnow ? '<span class="chip chip--dk">○ Sans réponse</span>' : c.ok ? '<span class="chip chip--ok">✓ Juste</span>' : '<span class="chip chip--ko">✗ Faux</span>') : '';
    var body = '<div class="qmain"><h2 class="prompt" id="prompt" tabindex="-1">' + q.prompt + '</h2>' +
      (q.figure ? '<div class="figure">' + q.figure + '</div>' : '') +
      '<div class="answer-row">' + answerAreaHtml(run, q, done, c) + chip + '</div>';
    if (!done) {
      body += '<div class="typing-help" id="typing-help">' + esc(t.help) + '</div>';
      if (t.keys.length) body += '<div class="keys" role="group" aria-label="Lettres à insérer">' + t.keys.map(function (k) { return '<button type="button" data-act="key" data-arg="' + esc(k) + '" aria-label="Insérer ' + esc(k) + '">' + esc(k) + '</button>'; }).join('') + '</div>';
      if (run.notice) body += '<div class="notice' + (run.notice.warn ? ' notice--warn' : '') + '" role="alert">' + run.notice.html + '</div>';
      body += '<div class="qacts"><button type="button" class="btn" data-act="dont-know">Je ne sais pas</button>' +
        '<button type="button" class="btn btn--primary" data-act="submit">' + (run.mode === 'final' ? 'Observation suivante' : 'Valider') + ' <span class="kbd">Entrée</span></button></div>';
    } else body += '<p id="typing-help" hidden></p>';
    body += '</div>';

    var draft = '';
    if (run.draftOpen) {
      draft = '<aside class="draft" id="draft"><div class="draft__head"><label for="draft-text">Brouillon</label><button type="button" class="btn btn--small" data-act="draft-clear">Effacer</button></div>' +
        '<textarea id="draft-text" spellcheck="false" aria-describedby="draft-note"></textarea><span class="draft__note" id="draft-note">Une ardoise libre : jamais enregistrée ni envoyée, effacée à la fin ' + (run.mode === 'final' ? 'de la remontée' : run.mode === 'comet' ? 'de l’analyse' : 'de la plongée') + '.</span></aside>';
    }

    var correction = '';
    if (done) {
      var last = run.position + 1 >= run.size;
      var title = c.dontKnow ? 'La méthode' : c.ok ? pick(OK_LINES, run.position + (q.seed || 0)) : pick(KO_LINES, run.position + (q.seed || 0));
      correction = '<section class="correction" aria-labelledby="corr-title"><h3 class="correction__title' + (c.ok ? '' : ' is-ko') + '" id="corr-title">' + esc(title) + '</h3>' +
        (!c.ok && !c.dontKnow && c.misconceptionLabel ? '<div class="correction__error">' + esc(typo(c.misconceptionLabel)) + '</div>' : '') +
        (c.ok || q.answerType === 'order' ? '' : '<div class="expected"><span>Réponse attendue</span><b>' + (c.expected || esc(c.expectedText || '')) + (c.unit_label ? ' ' + esc(c.unit_label) : '') + '</b></div>') +
        '<div class="kicker">Méthode</div><p class="method">' + (c.method || '') + '</p>' +
        '<div class="correction__acts"><button type="button" class="btn btn--primary" data-act="next" data-autofocus>' + (last ? 'Voir le bilan' : 'Observation suivante') + ' <span class="kbd">Entrée</span></button></div></section>';
    }

    show(topbar(sub, right) +
      '<main class="screen run"><div class="console"><div class="qhead"><span class="qhead__title">' + esc(runTitle(run)) + '</span>' + streakHtml(run) + '<span class="qhead__num">' + (run.mode === 'comet' ? 'Analyse ' : 'Observation ') + (run.position + 1) + '/' + run.size + depthHtml(run) + progressHtml(run) + '</span></div>' +
      gaugeHtml(run) + body + correction + '</div>' + draft + '</main>',
      done ? '[data-act="next"]' : (q.answerType === 'choice' || q.answerType === 'order' ? '[data-first]' : '#answer'));

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
  }

  function currentInput(run) {
    var q = run.q;
    if (q.answerType === 'choice') return run.choice === null || run.choice === undefined ? '' : q.choices[run.choice].value;
    if (q.answerType === 'order') {
      if ((run.order || []).length !== (q.choices || []).length) return null;
      return run.order.map(function (i) { return q.choices[i].value; }).join('|');
    }
    var inp = document.getElementById('answer');
    return (inp ? inp.value : '').trim();
  }

  /* The gauge of a dive or an anomaly's analysis, from the corrections already given - never during
     the ascent, whose observations are not corrected before the surface. */
  function gaugeHtml(run) {
    if (run.mode === 'final') return '';
    return E.gauge({
      mode: run.mode, results: run.results, size: run.size,
      best: run.before ? run.before.best : null,
      solvedAt: 2, solve: 'l’identifier', levels: GAUGE_LEVELS, record: 'ta meilleure plongée'
    }).html;
  }
  function streakHtml(run) {
    if (run.mode === 'final' || !run.correction) return '';
    var n = E.streak(run.results);
    return n >= 3 ? '<span class="sg-streak' + (n % 4 === 0 ? ' is-hot' : '') + '">' + n + ' d’affilée</span>' : '';
  }
  // the sound of a correction: the sonar climbs with the streak, a flourish every fourth
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
      run.notice = { html: run.q.answerType === 'choice' ? 'Choisis une proposition, ou « Je ne sais pas ».' : run.q.answerType === 'order' ? 'Place tous les éléments, ou choisis « Je ne sais pas ».' : 'Écris une réponse, ou choisis « Je ne sais pas ».', warn: true };
      renderRun();
      return;
    }
    G.busy = true;
    call('answer', { session: run.session, input: input }).then(function (r) {
      G.busy = false;
      if (!r.recorded) {
        var t = TYPING[run.q.answerType] || TYPING.text;
        run.notice = r.unreadable
          ? { html: 'Je n’arrive pas à lire cette réponse : ' + esc(lowerFirst(t.help)) + ' Rien n’est compté.', warn: true }
          : { html: esc(r.formIssue || '') + ' Rien n’est compté : réécris-la, puis valide.', warn: true };
        renderRun();
        announce(plain(run.notice.html));
        return;
      }
      run.typed = '';
      if (run.mode === 'final') {
        run.results[run.position] = 'done';
        if (run.position + 1 >= run.size) endRun(); else nextQuestion();
        return;
      }
      run.results[run.position] = r.dontKnow ? 'dk' : r.ok ? 'ok' : 'ko';
      run.correction = r;
      correctionSound(run, r);
      renderRun();
      announce(r.dontKnow ? 'Noté « je ne sais pas ». La méthode est affichée.' : r.ok ? 'Observation juste.' : 'Observation fausse : le spécimen a filé.' + (r.misconceptionLabel ? ' Erreur repérée : ' + lowerFirst(typo(r.misconceptionLabel)) + '.' : '') + (run.q.answerType === 'order' ? ' Ordre attendu : ' + (r.expectedCells || []).join(', ') : ' Réponse attendue : ' + plain(r.expected || r.expectedText)));
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
    var target = document.getElementById('answer');
    if (!target || target.readOnly) return;
    var s = target.selectionStart === null ? target.value.length : target.selectionStart, e = target.selectionEnd === null ? s : target.selectionEnd;
    target.value = target.value.slice(0, s) + k + target.value.slice(e);
    target.dispatchEvent(new Event('input'));
    target.focus();
    target.setSelectionRange(s + k.length, s + k.length);
  }

  /* The letter keys keep the focus where the student was typing. */
  document.addEventListener('mousedown', function (e) { if (e.target.closest('.keys button')) e.preventDefault(); });

  function placeItem(i) {
    var run = G.run;
    if (!run || run.correction) return;
    run.order = run.order || [];
    if (run.order.indexOf(i) >= 0) return;
    run.order.push(i);
    run.notice = null;
    renderRun();
    var next = app.querySelector('.order__item') || app.querySelector('[data-act="submit"]');
    if (next) next.focus();
    announce('Place ' + run.order.length + ' : ' + plain(run.q.choices[i].html) + '.');
  }
  function unplaceItem(slot) {
    var run = G.run;
    if (!run || run.correction) return;
    var removed = run.order.splice(slot, 1)[0];
    renderRun();
    var back = app.querySelector('.order__item[data-arg="' + removed + '"]');
    if (back) back.focus();
    announce('Place ' + (slot + 1) + ' vidée.');
  }

  /* ---------- the ascent's clock: the platform shows it; this one only closes the session ---------- */

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
      out.push(r === 'ok' ? '<li class="ok" aria-label="Observation ' + (k + 1) + ' : juste">✓</li>' : r === 'dk' ? '<li aria-label="Observation ' + (k + 1) + ' : je ne sais pas">○</li>' : r === 'ko' ? '<li class="ko" aria-label="Observation ' + (k + 1) + ' : fausse">✗</li>' : '<li aria-label="Observation ' + (k + 1) + '">·</li>');
    }
    return '<ul class="boxes">' + out.join('') + '</ul>';
  }

  function anomaliesRaisedHtml(list) {
    if (!list || !list.length) return '';
    return list.map(function (c) {
      return '<div class="notice notice--anomaly">' + Scene.blip(34) + '<span><b>Une anomalie s’affiche au sonar</b> : « ' + esc(typo(c.label)) + ' ». La même erreur est revenue deux fois ; tu peux l’identifier maintenant, ou plus tard.</span>' +
        '<button type="button" class="btn btn--small" data-act="comet" data-arg="' + esc(c.misconception) + '">Analyser l’anomalie</button></div>';
    }).join('');
  }

  function renderSeriesEnd() {
    G.screen = 'seriesEnd';
    var r = G.endRes, run = G.run, u = unitOf(r.unit) || { label: r.unit, chapter: run.unit && unitOf(run.unit) ? unitOf(run.unit).chapter : 'recif' };
    var before = run.before || {};
    var lit = !!r.brightness;
    var record = before.best !== null && before.best !== undefined && r.score > before.best && !r.newlyLit && !r.brighter;
    var note = r.score === 12 ? 'Douze sur douze. Planche complète, je l’encadre.' : r.newlyLit ? pick(AFTER_LIT, r.score + run.session) : r.brighter ? 'Le croquis ' + deW(typo(u.label)) + ' gagne en précision. Une plongée moins bonne ne l’effacera pas.' : pick(AFTER_ERROR, r.score + run.session);
    var levelRow = lit
      ? (r.brighter && before.brightness ? '<span class="was">' + Scene.sketch(u.chapter, before.brightness, 28) + ' ' + esc(LEVEL[before.brightness]) + '</span> <span aria-label="devient">→</span> ' : '') + Scene.sketch(u.chapter, r.brightness, 30) + ' ' + esc(LEVEL[r.brightness])
      : 'pas encore cataloguée — il en faut 6';
    show(topbar('Plongée de 12', speciesCount()) +
      '<main class="screen"><div class="console console--end"><div class="qhead"><span class="qhead__title">' + esc(chapterName(u) + ' · ' + typo(u.label)) + '</span><span class="kicker">RETOUR À LA STATION</span></div>' +
      '<div class="result"><div class="score" aria-label="' + r.score + ' observations justes sur ' + r.size + '"><b>' + r.score + '</b><span>/' + r.size + '</span></div>' +
      '<div class="result__sketch">' + (r.newlyLit ? '<span class="badge-new">Nouvelle espèce au carnet</span>' : r.brighter ? '<span class="badge-new">Croquis plus précis</span>' : record ? '<span class="badge-new">Nouveau record</span>' : '') +
      '<div class="result__arrow">' + (r.newlyLit ? Scene.sketch(u.chapter, before.series ? 'tried' : 'never', 70) + '<span aria-hidden="true">→</span>' : '') + Scene.sketch(u.chapter, lit ? r.brightness : 'tried', 120) + '</div>' +
      '<span class="kicker">Prochain niveau : ' + esc(nextLevel(r.best)) + '</span></div></div>' +
      boxesHtml(run, r.size) +
      '<dl class="rows"><div><dt>Croquis</dt><dd>' + levelRow + '</dd></div><div><dt>Meilleure plongée</dt><dd class="num">' + (r.best === null || r.best === undefined ? '—' : r.best + '/12') + '</dd></div></dl>' +
      anomaliesRaisedHtml(r.comets) +
      '<p class="guide"><b>' + esc(GUIDE) + '</b> — ' + esc(note) + '</p>' +
      againHtml(r) + '</div></main>');
    announce('Fin de la plongée : ' + r.score + ' sur ' + r.size + '.' + (r.newlyLit ? ' Nouvelle espèce au carnet !' : r.brighter ? ' Le croquis gagne en précision.' : record ? ' Nouveau record.' : ''));
    celebrate(r.newlyLit || r.brighter ? 'level' : record ? 'record' : 'end', '.result__arrow > svg:last-child');
  }

  /* « Encore une » in one key: the same species again unless its sketch is framed, then the species
     nearest to its next level - a fact read on the notebook, never a recommendation. */
  function againHtml(r) {
    var u = unitOf(r.unit), maxed = u && u.best >= 12, near = E.closest(G.progress.units, r.unit), acts = [];
    if (!maxed) acts.push('<button type="button" class="btn btn--primary" data-act="series" data-arg="' + esc(r.unit) + '" data-autofocus>Encore une plongée <span class="kbd">Entrée</span></button>');
    if (near) acts.push('<button type="button" class="btn sg-next__near' + (maxed ? ' btn--primary' : '') + '" data-act="series" data-arg="' + esc(near.unit.code) + '"' + (maxed ? ' data-autofocus' : '') + '>Plonger : ' + esc(typo(near.unit.label)) +
      '<span class="sg-next__hint">' + esc(nearHint(near)) + '</span></button>');
    acts.push('<button type="button" class="btn btn--small" data-act="home"' + (acts.length ? '' : ' data-autofocus') + '>Retour au hublot <span class="kbd">Échap</span></button>');
    return '<div class="sg-next">' + acts.join('') + '</div>';
  }
  function nearHint(near) {
    var b = near.unit.best;
    return (b === null || b === undefined ? 'à cataloguer' : 'meilleure plongée ' + b + '/12') + ' · ' + nextLevel(b);
  }
  function celebrate(cue, selector) {
    E.sound.play(cue);
    if (cue === 'level' || cue === 'record' || cue === 'solved') E.burst(app.querySelector(selector), cue === 'level');
  }

  function renderCometIntro() {
    G.screen = 'cometIntro';
    var run = G.run, c = run.comet || {}, u = unitOf(c.unit || run.unit);
    show(topbar('Analyser l’anomalie', speciesCount() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="leave">Retour au hublot</button>') +
      '<main class="screen"><div class="study"><div class="study__art">' + Scene.sonar(1, false) + '</div><div>' +
      '<span class="kicker">ANOMALIE AU SONAR · ' + esc((u ? chapterName(u) + ' · ' + typo(u.label) : typo(c.unitName || '')).toUpperCase()) + '</span>' +
      '<h2 class="big-title" tabindex="-1">' + esc(typo(c.label || 'Une anomalie')) + '</h2>' +
      '<p class="explanation">' + esc(typo(run.explanation || '')) + '</p>' +
      '<div class="qacts qacts--start"><button type="button" class="btn btn--primary" data-act="comet-go" data-autofocus>Analyser : 3 observations <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn btn--small" data-act="leave">Plus tard</button></div><p class="kicker">2 justes sur 3 pour l’identifier</p></div></div></main>');
  }

  function renderCometEnd() {
    G.screen = 'cometEnd';
    var r = G.endRes, run = G.run, c = run.comet || {};
    show(topbar('Analyser l’anomalie', speciesCount()) +
      '<main class="screen"><div class="study"><div class="study__art">' + Scene.sonar(1, !!r.solved) + '</div><div><span class="kicker">ANALYSE TERMINÉE · ' + esc(typo(c.label || '').toUpperCase()) + '</span>' +
      '<h2 class="big-title" tabindex="-1">' + (r.solved ? 'Anomalie identifiée.' : 'Elle reste à identifier.') + '</h2>' +
      boxesHtml(run, r.size) +
      (r.solved ? '<div class="sg-bestiary-new">' + E.bestiaryArt('abysse', run.misconception, 110) + '<span class="kicker">Nouvelle fiche au ' + esc(BESTIARY.toLowerCase()) + '</span></div>' : '') +
      '<p class="explanation">' + (r.solved
        ? 'L’écho est expliqué : ' + r.score + ' justes sur ' + r.size + '. Il ne reviendra que si l’erreur revient.'
        : r.score + ' juste' + (r.score > 1 ? 's' : '') + ' sur ' + r.size + '. Rien n’est perdu : l’anomalie reste sur le sonar, et deux justes sur trois suffisent pour l’identifier.') + '</p>' +
      anomaliesRaisedHtml(r.comets) +
      '<div class="qacts qacts--start"><button type="button" class="btn btn--primary" data-act="home" data-autofocus>Retour au hublot <span class="kbd">Entrée</span></button>' +
      (r.solved ? '<button type="button" class="btn" data-act="bestiary">Ouvrir le ' + esc(BESTIARY.toLowerCase()) + '</button>' : '<button type="button" class="btn" data-act="comet" data-arg="' + esc(run.misconception) + '">Réessayer</button>') + '</div></div></div></main>');
    announce(r.solved ? 'Anomalie identifiée. Elle rejoint le registre des anomalies.' : 'L’anomalie reste à identifier.');
    celebrate(r.solved ? 'solved' : 'end', '.sg-bestiary-new svg');
  }

  function renderFinalIntro() {
    G.screen = 'finalIntro';
    var f = G.progress.final, extra = G.user.extraTimePercent || 0;
    if (!f.unlocked) { toast(ERRORS['final-locked']); return; }
    var mins = minutes(f.timeLimitSeconds);
    var held = (G.progress.rewards || []).filter(function (rw) { return rw.kind === 'trophy'; })[0];
    show(topbar('L’épreuve', speciesCount() + '<button type="button" class="btn btn--small" data-act="home">Retour au hublot <span class="kbd">Échap</span></button>') +
      '<main class="screen"><div class="final-intro"><div class="final-intro__art">' + Scene.bathyscaphe({ light: f.best !== null && f.best !== undefined }) + '</div><div>' +
      '<span class="kicker">LE BATHYSCAPHE EST LÀ</span><h2 class="big-title" tabindex="-1">' + esc(f.label || 'La Remontée') + '</h2>' +
      '<blockquote class="guide-quote"><b>' + esc(GUIDE) + '</b>Douze observations de tout le carnet, pendant que nous remontons. Je ne corrige qu’à la surface.</blockquote>' +
      '<dl class="final-facts"><div><dt>Observations</dt><dd>' + f.size + ', tout le carnet</dd></div><div><dt>Durée</dt><dd>' + mins + ' min' + (extra > 0 ? ' · temps majoré (+' + extra + ' %)' : '') + '</dd></div><div><dt>Correction</dt><dd>à la surface · rejouable</dd></div>' +
      '<div><dt>Meilleur score</dt><dd>' + (f.best !== null && f.best !== undefined ? f.best + '/' + f.size : '—') + '</dd></div></dl>' +
      '<div class="trophy-row">' + ['brass', 'silver', 'gold'].map(function (fin, i) {
        var art = window.SGRewards ? window.SGRewards.art('trophy', 'nautile', { size: 56, finish: fin, locked: !held || (i === 1 && held.finish === 'brass') || (i === 2 && held.finish !== 'gold') }) : '';
        return '<figure>' + art + '<figcaption>' + ['LAITON', '10/12', '12/12'][i] + '</figcaption></figure>';
      }).join('') + '</div>' +
      '<p class="method">« Je ne sais pas » reste possible à chaque observation. Le temps restant s’affiche dans la barre de Formatool ; une observation laissée sans réponse quand il s’écoule compte fausse. Les espèces du carnet restent cataloguées quoi qu’il arrive.</p>' +
      '<div class="qacts qacts--start"><button type="button" class="btn btn--primary" data-act="final-go" data-autofocus>Commencer la remontée <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn" data-act="home">Retour au hublot</button></div></div></div></main>');
  }

  function reviewGiven(it) {
    if (it.dontKnow) return 'je ne sais pas';
    if (!it.answered) return 'sans réponse';
    return String(it.input || '').split('|').join(it.answerType === 'order' ? ' → ' : ' · ');
  }

  function renderReview() {
    G.screen = 'review';
    var r = G.endRes, items = (r.review || []).map(function (it, k) {
      var chip = it.ok ? '<span class="chip chip--ok">✓</span>' : it.answered && !it.dontKnow ? '<span class="chip chip--ko">✗</span>' : '<span class="chip chip--dk">○</span>';
      var expected = it.answerType === 'order' && it.expectedCells ? it.expectedCells.map(esc).join(' → ') : (it.expected || '');
      return '<li><span class="review__n">' + String(k + 1).padStart(2, '0') + '</span><div><div class="review__unit">' + esc(typo(it.unitName)) + '</div>' +
        '<div class="review__prompt">' + it.prompt + '</div>' +
        (it.figure ? '<details class="review__fig"><summary>Voir la figure</summary><div class="figure figure--small">' + it.figure + '</div></details>' : '') +
        '<div class="review__given"><span>Ta réponse</span>' + esc(reviewGiven(it)) + '</div>' +
        (!it.ok && it.misconceptionLabel ? '<div class="correction__error">' + esc(typo(it.misconceptionLabel)) + '</div>' : '') +
        '<details' + (it.ok ? '' : ' open') + '><summary>' + (it.ok ? 'Voir la méthode' : 'La méthode') + '</summary><div class="expected"><span>Réponse attendue</span><b>' + expected + '</b></div><p class="method">' + (it.method || '') + '</p></details></div>' + chip + '</li>';
    }).join('');
    var rewards = (G.progress.rewards || []).filter(function (rw) { return rw.kind === 'trophy' || rw.code === 'card-abysse-remontee'; });
    var note = r.score >= 12 ? 'Douze sur douze. Planche complète : le nautile est en or.' : r.score >= 10 ? r.score + ' observations justes : le nautile passe à l’argent. Les espèces du carnet restent toutes cataloguées.' : r.score + ' observation' + (r.score > 1 ? 's' : '') + ' juste' + (r.score > 1 ? 's' : '') + '. La remontée ne retire rien : les espèces du carnet restent cataloguées.';
    show(topbar('L’épreuve', speciesCount()) +
      '<main class="screen"><div class="review-wrap"><div class="review-side"><span class="kicker">À LA SURFACE · BILAN DE LA REMONTÉE</span><div class="score"><b>' + r.score + '</b><span>/' + r.size + '</span></div>' +
      '<p class="kicker">Meilleur score · ' + (r.best !== null && r.best !== undefined ? r.best : r.score) + '/' + r.size + '</p>' +
      (r.timedOut ? '<div class="notice notice--warn">' + (G.run && G.run.endedByPlayer ? 'Remontée terminée avant la dernière observation' : 'Le temps s’est écoulé') + ' : les observations restées sans réponse comptent fausses.</div>' : '') +
      (rewards.length && window.SGRewards ? '<div class="review-pieces">' + rewards.map(function (rw) { return '<figure>' + window.SGRewards.art(rw.kind, rw.code, { size: rw.kind === 'card' ? 90 : 110, finish: rw.finish || 'brass' }) + '<figcaption>' + esc(typo(rw.name)) + (rw.kind === 'trophy' ? ' · ' + ({ brass: 'laiton', silver: 'argent', gold: 'or' }[rw.finish] || 'laiton') : '') + '</figcaption></figure>'; }).join('') + '</div>' : '') +
      anomaliesRaisedHtml(r.comets) +
      '<p class="guide"><b>' + esc(GUIDE) + '</b> — ' + esc(note) + '</p>' +
      '<div class="qacts qacts--start"><button type="button" class="btn" data-act="final-intro">Refaire la remontée</button><button type="button" class="btn btn--primary" data-act="home" data-autofocus>Retour au hublot</button></div></div>' +
      '<ol class="review" aria-label="Correction, observation par observation">' + items + '</ol></div></main>');
    announce('Remontée terminée : ' + r.score + ' sur ' + r.size + '.');
    var was = G.run ? G.run.beforeBest : null;
    celebrate(was !== null && was !== undefined && r.score > was ? 'record' : 'end', '.review-side .score');
  }

  /* ---------- the glossary: over any screen ---------- */

  function openGlossary(tab) {
    G.glossTab = tab || G.glossTab;
    var prev = overlay.firstChild ? overlay._prev : document.activeElement;
    var entries = GLOSS[G.glossTab === 'svt' ? 'svt' : 'station'];
    overlay.innerHTML = '<div class="gloss-backdrop" data-act="glossary-close"></div><aside class="gloss" role="dialog" aria-modal="true" aria-labelledby="gloss-title"><div class="gloss__head"><h2 id="gloss-title">Glossaire</h2><button type="button" class="btn btn--small" data-act="glossary-close">Fermer <span class="kbd">Échap</span></button></div>' +
      '<div class="gloss__tabs" role="group" aria-label="Rubriques du glossaire"><button type="button" data-act="gloss-tab" data-arg="station" aria-pressed="' + (G.glossTab !== 'svt') + '">La station</button><button type="button" data-act="gloss-tab" data-arg="svt" aria-pressed="' + (G.glossTab === 'svt') + '">SVT</button></div>' +
      '<dl class="gloss__list">' + entries.map(function (e) { return '<dt>' + esc(e[0]) + '</dt><dd>' + esc(e[1]) + '</dd>'; }).join('') + '</dl></aside>';
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

  /* ---------- the registre des anomalies: every typical error identified ---------- */

  function bestiaryButton() {
    var n = (G.progress.bestiary || []).length;
    return '<button type="button" class="btn btn--small" data-act="bestiary">' + esc(BESTIARY) + ' <b>' + n + '</b></button>';
  }
  function renderBestiary() {
    G.screen = 'bestiary';
    var list = G.progress.bestiary || [], total = G.progress.bestiaryTotal || 0;
    var body = list.length ? '<ul class="sg-bestiary">' + list.map(function (b) {
      return '<li class="sg-bestiary__item">' + E.bestiaryArt('abysse', b.misconception, 96) +
        '<span class="sg-bestiary__unit">' + esc(typo(b.unitName)) + '</span>' +
        '<h3 class="sg-bestiary__label">' + esc(typo(b.label)) + '</h3>' +
        (b.explanation ? '<details><summary>La note de la commandante</summary><p class="method">' + esc(typo(b.explanation)) + '</p></details>' : '') +
        '<span class="sg-bestiary__count">Identifiée le ' + esc(new Date(b.solvedAt).toLocaleDateString('fr-FR')) + '</span></li>';
    }).join('') + '</ul>'
      : '<p class="sg-bestiary__empty">Aucune anomalie identifiée pour l’instant. Quand la même erreur revient deux fois, une anomalie s’affiche au sonar ; l’analyser et l’identifier la range ici, avec la note qui l’explique.</p>';
    show(topbar(BESTIARY, speciesCount() + '<button type="button" class="btn btn--small" data-act="home">Retour au hublot <span class="kbd">Échap</span></button>') +
      '<main class="screen bestiary" tabindex="-1"><h2 class="big-title" tabindex="-1" data-autofocus>' + esc(BESTIARY) + '</h2>' +
      '<p class="sg-bestiary__count" style="margin-top:10px">' + list.length + ' anomalie' + (list.length > 1 ? 's' : '') + ' identifiée' + (list.length > 1 ? 's' : '') + ' sur ' + total + ' erreurs que le sonar connaît</p>' +
      body + '</main>');
  }

  /* ---------- 4. the reward scene ---------- */

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
      '<p class="reward__lore" id="reward-lore">' + esc(typo(rw.lore || (rw.kind === 'card' ? 'Une carte de la station, pour ta vitrine.' : rw.kind === 'pin' ? 'Elle se porte sur ton avatar, depuis ta page joueur.' : ''))) + '</p>' +
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
    glossary: function () { openGlossary(); },
    'glossary-close': closeGlossary,
    'gloss-tab': function (arg) { openGlossary(arg); },
    'dlg-next': function () { G.dlg.i = Math.min(G.dlg.i + 1, G.dlg.lines.length - 1); renderHome(); },
    zone: function (arg) { G.zone = arg; G.selected = null; renderHome('.zonepanel__name'); },
    pick: function (arg) { var u = unitOf(arg); G.selected = arg; G.zone = u ? u.chapter : G.zone; G.data.last = arg; if (G.screen !== 'home') G.screen = 'home'; renderHome(); },
    deselect: function () {
      if (G.selected) { var was = G.selected; G.selected = null; renderHome('[data-arg="' + was + '"].spcard'); return; }
      var zone = G.zone; G.zone = null; renderHome(zone ? '[data-arg="' + zone + '"].nb__head' : null);
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
    place: function (arg) { placeItem(+arg); },
    unplace: function (arg) { unplaceItem(+arg); },
    'order-reset': function () { if (G.run && !G.run.correction) { G.run.order = []; renderRun(); var f = app.querySelector('.order__item'); if (f) f.focus(); } },
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
    if (G.busy && ['toast-close', 'reward-close', 'key', 'draft', 'draft-clear', 'choose', 'place', 'unplace', 'order-reset', 'glossary', 'glossary-close', 'gloss-tab', 'sound'].indexOf(b.getAttribute('data-act')) < 0) return;
    fn(b.getAttribute('data-arg'));
  });

  document.addEventListener('keydown', function (e) {
    var typing = e.target && /^(INPUT|TEXTAREA)$/.test(e.target.tagName);
    var free = G.screen === 'run' && G.run && G.run.q && !G.run.correction && !typing && !overlay.firstChild && !layer.firstChild;
    // a QCM answers from the keyboard: 1-4 or A-D choose, Entrée validates
    if (free && G.run.q.answerType === 'choice') {
      var n = '1234'.indexOf(e.key) >= 0 ? +e.key - 1 : 'abcd'.indexOf((e.key || '').toLowerCase());
      if (n >= 0 && n < (G.run.q.choices || []).length) { e.preventDefault(); ACTIONS.choose(String(n)); app.querySelectorAll('.choice')[n].focus(); return; }
      if (e.key === 'Enter' && G.run.choice !== null && G.run.choice !== undefined && !e.target.closest('[data-act]:not(.choice)')) { e.preventDefault(); submit(false); return; }
    }
    // an order whose every place is filled validates with Entrée, from anywhere but a button
    if (free && G.run.q.answerType === 'order' && e.key === 'Enter' && (G.run.order || []).length === (G.run.q.choices || []).length && !e.target.closest('[data-act]')) { e.preventDefault(); submit(false); return; }
    if (e.key !== 'Escape') return;
    if (layer.firstChild) { e.preventDefault(); closeReward(); return; }
    if (overlay.firstChild) { e.preventDefault(); closeGlossary(); return; }
    var t = document.querySelector('.toast');
    if (t) { t.remove(); return; }
    if (G.screen === 'home' && (G.selected || G.zone)) { e.preventDefault(); ACTIONS.deselect(); return; }
    if (G.screen === 'list' || G.screen === 'finalIntro' || G.screen === 'bestiary' || G.screen === 'seriesEnd') { e.preventDefault(); goHome(); return; }
    if (G.screen === 'run' && G.run && G.run.draftOpen && document.activeElement && document.activeElement.id === 'draft-text') {
      e.preventDefault();
      var inp = document.getElementById('answer') || app.querySelector('.choice') || app.querySelector('.order__item');
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
    E.sound.voice('sonar');
    E.sound.setMuted(G.data.mute === true); // on by default
    renderHome(false);
  }, function (e) {
    app.innerHTML = '<p class="loading" role="alert">' + esc(errorText(e)) + '</p>';
  });
})();
