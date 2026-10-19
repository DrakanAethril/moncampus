/* L'Observatoire — the game (design/validated/serious-games.md §1, §6, §8).

   The student is the assistant of an observatory whose sky map a storm has wiped out. Each star
   is one automatisme: a series of 12 short-answer questions lights it from 6/12, and its
   brightness is the best series (discrète 6-7, nette 8-9, brillante 10-11, éclatante 12) - it never
   goes down. A typical error made twice raises a comet, studied in 3 questions. When every star is
   lit, La Couronne opens: the épreuve blanche, 12 questions in the time the server gives.

   The platform generates and corrects: this page never sees an answer before it is corrected, and
   writes nothing but its own display state (saveProgress). Nothing here depends on time or on
   absence, except the Couronne's clock - shown, never decided, here.

   Screens: home (scene, sky map, the carnet) · list (every star) · run (a question of a series, a
   comet or the Couronne) · seriesEnd · cometIntro · cometEnd · finalIntro · review. */
(function () {
  'use strict';

  var SDK = window.SGSdk, Scene = window.OBSScene, esc = Scene.esc, E = window.SGEngage;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- wording ---------- */

  var WORDS = { discrete: 'discrète', clear: 'nette', bright: 'brillante', blazing: 'éclatante' };
  var PLURALS = { blazing: ['éclatante', 'éclatantes'], bright: ['brillante', 'brillantes'], clear: ['nette', 'nettes'], discrete: ['discrète', 'discrètes'] };
  var CARNET = 'Carnet de l’astronome';

  // The carnet of Hélène Vasseur: the prologue, then one page at each grade (0/8/16/24/31 stars).
  var PROLOGUE = [
    'Si vous lisez ces lignes, la tempête est passée et la carte du ciel est blanche. Je suis partie avant elle ; ce carnet parlera à ma place. — Hélène Vasseur',
    'Les étoiles n’ont pas disparu : c’est nous qui ne savons plus où les chercher. Chacune revient par une série de douze calculs, et six justes suffisent à l’allumer.',
    'Choisissez-en une, sur la carte ou dans la liste. On calcule d’abord, on regarde ensuite.'
  ];
  var BEATS = {
    8: ['Vous voilà Assistant·e de nuit. Dans le tiroir de gauche, Hélène a laissé ses brouillons corrigés en rouge : ce sont ses propres erreurs, et ses méthodes.',
      'C’est pour cela que la méthode s’affiche après chaque calcul, juste ou non. La feuille de brouillon, sur le bureau, est à vous : rien de ce qu’on y écrit n’est gardé.'],
    16: ['Une lettre dépasse du tube du télescope : « Je n’ai jamais compté mes séries sur le Serpent. Ne comptez pas les vôtres. »',
      'Vous êtes maintenant Observateur·rice. Aller vite ou lentement ne change rien à l’éclat d’une étoile.'],
    24: ['Astronome adjoint·e. Au centre de la carte, un cercle reste vide.',
      'Le carnet parle d’une constellation qu’on ne voit qu’une fois toutes les autres retrouvées.'],
    31: ['Toutes les étoiles sont revenues. Sous la carte, une plaque de laiton se soulève : l’Astrolabe d’Hélène.',
      'Au centre de la carte, une sixième constellation se dessine : la Couronne, l’épreuve blanche. Douze calculs puisés dans tout le ciel, corrigés seulement à la fin, rejouables autant de fois que vous le voudrez.']
  };
  var GREET = {
    'Assistant·e stagiaire': 'La carte est presque blanche. Choisissez une étoile : douze calculs suffisent pour qu’elle revienne.',
    'Assistant·e de nuit': 'Vous connaissez maintenant le chemin du bureau. Le télescope aussi commence à vous connaître.',
    'Observateur·rice': 'La moitié du ciel est de retour. Hélène n’a jamais compté ses séries ; ne comptez pas les vôtres.',
    'Astronome adjoint·e': 'Il reste peu d’étoiles éteintes, et ce sont souvent les plus difficiles. Prenez le temps qu’il faut.',
    'Astronome': 'Le ciel est complet. Au centre, une couronne attend.'
  };
  var AFTER_ERROR = [
    'Le télescope a dévié de quelques degrés. Rien de grave : l’étoile reste où elle est, et nous aussi.',
    'J’ai fait exactement la même erreur, l’hiver 1998. Ma méthode est dans la marge.',
    'Une erreur n’éteint rien. Votre meilleure série reste votre meilleure série.'
  ];
  // what each level is called by the gauge of a series in progress (shared/engage.js)
  var GAUGE_LEVELS = {
    6: ['allumer l’étoile', 'L’étoile s’allume !'],
    8: ['l’éclat nette', 'Éclat nette atteint !'],
    10: ['l’éclat brillante', 'Éclat brillante atteint !'],
    12: ['l’éclat éclatante', 'Douze sur douze : éclatante !']
  };
  var BESTIARY = 'Carnet des comètes';
  var AFTER_LIT = ['Le télescope a trouvé ce qu’il cherchait.', 'Une étoile de plus, et la carte se souvient de vous.', 'Le ciel est un peu moins blanc.'];

  // What to type, per answer type (never a hint about the answer itself)
  var TYPING = {
    number: { help: 'Un nombre, comme 7,5 ou −2, ou une fraction comme 3/4.', keys: ['−', '/'] },
    fraction: { help: 'Une fraction comme 3/4, ou un nombre décimal.', keys: ['−', '/'] },
    power: { help: 'Une puissance, comme 2^7 ou a^6.', keys: ['^', '−'] },
    expression: { help: 'Une expression en x, comme 2x² − 3x + 1.', keys: ['x', '²', '^', '(', ')', '−'] },
    polynomial: { help: 'Une expression en x, comme 2x² − 3x + 1.', keys: ['x', '²', '^', '(', ')', '−'] },
    set: { help: 'Les solutions séparées par « ; », comme −2 ; 5, ou ∅ s’il n’y en a aucune.', keys: [';', '∅', '−', '/'] },
    interval: { help: 'Un intervalle comme ]−∞ ; 4], ou une inégalité comme x ≤ 4.', keys: ['[', ']', ';', '∞', '≤', '≥', '−'] },
    scientific: { help: 'Une écriture scientifique, comme 3,2 × 10^−3.', keys: ['×', '10^', '−'] },
    formula: { help: 'Une formule, comme t = d/v.', keys: ['=', '/', '×', '^'] },
    text: { help: 'Un mot ou une réponse courte.', keys: [] }
  };
  var FORMS = {
    developed: 'Forme développée et réduite, sans parenthèses.',
    factored: 'Forme factorisée : un produit, par exemple (x − 3)(x + 3).',
    factoredMax: 'Forme factorisée au maximum : un produit, par exemple 2(x − 3)(x + 3).'
  };
  var ERRORS = {
    network: 'La liaison avec Formatool est coupée. Vérifiez la connexion, puis réessayez.',
    timeout: 'Formatool ne répond pas. Ce jeu s’ouvre depuis la page Serious games de Formatool.',
    'unit-unavailable': 'Cette étoile n’est pas disponible pour le moment.',
    'final-locked': 'La Couronne n’apparaît qu’une fois toutes les étoiles allumées.',
    'comet-not-in-view': 'Cette comète n’est plus en vue.',
    'session-ended': 'Cette série est déjà terminée.',
    'session-unknown': 'Cette série n’existe plus.',
    'data-too-large': 'Le carnet est trop plein pour être rangé.'
  };

  /* ---------- state ---------- */

  var G = {
    user: null,
    progress: null,
    data: {},            // display state only, saved with saveProgress
    screen: 'home',
    selected: null,      // the unit whose card is open on the main screen
    dlg: null,           // {who, lines, i, id, actions}
    run: null,           // the session in progress
    busy: false,
    fresh: null,         // a star lit by the last series, drawn lighting up once
    confirmEnd: false,
    rewards: [],         // pieces to present
    endRes: null         // the result shown by seriesEnd / cometEnd / review
  };
  var app = document.getElementById('app'), live = document.getElementById('live'), layer = document.getElementById('layer');
  var clockTimer = null;

  /* ---------- the SDK, with a timeout (a page opened outside its host would wait for ever) ---------- */

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
    return ERRORS[code] || 'Une erreur est survenue (' + code + '). Réessayez dans un instant.';
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

  function save() {
    // display state only: what was read, the lamp, the session left open, the last star looked at
    call('saveProgress', { data: G.data }).catch(function () { /* the display state can wait */ });
  }

  /* ---------- helpers ---------- */

  function lowerFirst(s) { return s ? s.charAt(0).toLowerCase() + s.slice(1) : s; }
  function typo(s) { return String(s === null || s === undefined ? '' : s).replace(/'/g, '’'); }
  function unitOf(code) { return (G.progress.units || []).filter(function (u) { return u.code === code; })[0] || null; }
  function chapterOf(code) { return (G.progress.chapters || []).filter(function (c) { return c.code === code; })[0] || null; }
  function chapterName(unit) { var c = unit && chapterOf(unit.chapter); return c ? c.label : ''; }
  function plain(html) {
    var d = document.createElement('div');
    d.innerHTML = String(html || '').replace(/<span class="fr"><span class="n">([^<]*)<\/span><span class="d">([^<]*)<\/span><\/span>/g, '$1/$2');
    return (d.textContent || '').replace(/\s+/g, ' ').trim();
  }
  function minutes(sec) { return Math.round(sec / 60); }
  function clockText(sec) { sec = Math.max(0, Math.round(sec)); return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0'); }
  function pick(list, seed) { return list[Math.abs(seed) % list.length]; }

  function brightWord(b) { return WORDS[b] || 'éteinte'; }
  function nextLevel(best) {
    if (best === null || best === undefined || best < 6) return 'allumée, dès 6/12';
    if (best < 8) return 'nette, dès 8/12';
    if (best < 10) return 'brillante, dès 10/12';
    if (best < 12) return 'éclatante, à 12/12';
    return 'au plus haut';
  }
  // the accessible name of a star: « Taux d’évolution — éclat brillante (meilleure série 10/12) »
  function starName(u) {
    var name = typo(u.label);
    if (u.lit) return name + ' — éclat ' + brightWord(u.brightness) + ' (meilleure série ' + u.best + '/12)';
    if (u.series > 0 && u.best !== null) return name + ' — éteinte (meilleure série ' + u.best + '/12)';
    return name + ' — éteinte, jamais observée';
  }
  function eclatsLabel() {
    var c = { blazing: 0, bright: 0, clear: 0, discrete: 0 };
    G.progress.units.forEach(function (u) { if (u.lit && !u.hidden && c[u.brightness] !== undefined) c[u.brightness]++; });
    var parts = ['blazing', 'bright', 'clear', 'discrete'].filter(function (k) { return c[k]; }).map(function (k) { return c[k] + ' ' + PLURALS[k][c[k] > 1 ? 1 : 0]; });
    return parts.length ? parts.join(' · ') : 'aucune étoile allumée';
  }

  /* The star the carnet suggests: an unlit star of the constellation closest to completion (the
     one already nearest to 6), else the dimmest lit star. */
  function suggestion() {
    var best = null;
    G.progress.chapters.forEach(function (ch) {
      var off = G.progress.units.filter(function (u) { return u.chapter === ch.code && !u.hidden && !u.lit; });
      if (!off.length) return;
      if (!best || off.length < best.miss) {
        off.sort(function (a, b) { return (b.best || 0) - (a.best || 0); });
        best = { unit: off[0], miss: off.length };
      }
    });
    if (best) return { unit: best.unit, kind: 'light' };
    var dim = G.progress.units.filter(function (u) { return !u.hidden && (u.best || 0) < 12; }).sort(function (a, b) { return (a.best || 0) - (b.best || 0); })[0];
    return dim ? { unit: dim, kind: 'brighten' } : null;
  }
  function starLine(u) {
    var head = '<b>' + esc(typo(u.label)) + '</b>, ' + esc(chapterName(u)) + '. ';
    if (u.lit) return head + 'Étoile ' + brightWord(u.brightness) + ' : votre meilleure série est de ' + u.best + '/12. Une série moins bonne ne lui retire rien.';
    if (u.series > 0) return head + 'Étoile éteinte : votre meilleure série est de ' + u.best + '/12. Il en faut 6 pour l’allumer.';
    return head + 'Étoile éteinte, jamais observée. Six bonnes réponses sur douze l’allument.';
  }

  /* ---------- the top bar ---------- */

  function topbar(sub, right) {
    return '<header class="topbar"><div class="topbar__title"><h1 class="topbar__name">L’Observatoire</h1><span class="topbar__sub">' + esc(sub) + '</span></div>' +
      '<div class="topbar__right">' + right + E.sound.button() + '</div></header>';
  }
  function starsCount() {
    return '<span aria-label="' + G.progress.lit + ' étoiles allumées sur ' + G.progress.total + '"><b>' + G.progress.lit + '</b>/' + G.progress.total + ' ÉTOILES</span>';
  }

  /* ---------- rendering ---------- */

  function show(html, focusSelector) {
    stopClock();
    app.innerHTML = html;
    if (focusSelector === false) return; // the first screen: the frame does not take the focus from the page
    var target = focusSelector && app.querySelector(focusSelector);
    if (!target) target = app.querySelector('[data-autofocus]') || app.querySelector('h2[tabindex], .screen');
    // a new screen opens at its top; the focused control is then brought into view if needed
    window.scrollTo(0, 0);
    if (target) {
      try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
      var r = target.getBoundingClientRect();
      if (r.bottom > window.innerHeight || r.top < 0) target.scrollIntoView({ block: 'nearest' });
    }
  }

  /* ---------- 1. the main screen: the scene, the sky map, the carnet ---------- */

  function homeDialog() {
    var p = G.progress, read = G.data.read || [], lines, id = null;
    if (read.indexOf('prologue') < 0) {
      lines = PROLOGUE.slice(); id = 'prologue';
    } else {
      [31, 24, 16, 8].some(function (th) {
        if (p.lit >= th && read.indexOf('g' + th) < 0) { lines = BEATS[th].slice(); id = 'g' + th; return true; }
        return false;
      });
    }
    var sg = suggestion();
    if (!lines) {
      lines = [GREET[p.grade] || GREET['Assistant·e stagiaire']];
      if (p.final.unlocked) {
        lines.push(p.final.best === 12 ? 'La Couronne brille en entier. Vous pouvez rejouer l’épreuve pour le plaisir.' : 'La Couronne, c’est l’épreuve blanche : douze calculs puisés dans tout le ciel, ' + minutes(p.final.timeLimitSeconds) + ' minutes, et la correction seulement à la fin.');
      } else if (sg) {
        lines.push((sg.kind === 'light' ? 'L’étoile la plus proche de revenir : ' : 'Toutes les étoiles sont là. La moins brillante : ') + starLine(sg.unit));
      }
      if (p.comets.length) lines.push('Une comète traverse la carte. Hélène les notait toujours : elles reviennent par le même chemin. Vous pouvez l’étudier, ou la laisser passer.');
    }

    var acts = [];
    var open = G.data.open;
    if (open && open.session) acts.push({ label: open.mode === 'final' ? 'Reprendre la Couronne' : open.mode === 'comet' ? 'Reprendre la comète' : 'Reprendre : ' + typo((unitOf(open.unit) || {}).label || 'la série'), primary: true, act: 'resume' });
    if (p.final.unlocked) acts.push({ label: 'La Couronne', primary: !acts.length, act: 'final-intro' });
    else if (sg) acts.push({ label: 'Observer : ' + typo(sg.unit.label), primary: !acts.length, act: 'series', arg: sg.unit.code });
    if (p.comets.length) acts.push({ label: 'Étudier la comète', act: 'comet', arg: p.comets[0].misconception });
    acts.push({ label: 'Choisir une étoile', act: 'list' });
    if (!p.final.unlocked) acts.push({ label: 'La Couronne · verrouillée', disabled: true });
    return { who: CARNET, lines: lines, i: 0, id: id, actions: acts };
  }

  function dialogHtml() {
    var d = G.dlg, last = d.i >= d.lines.length - 1, acts = '';
    if (!last) acts = '<button type="button" class="btn btn--outline" data-act="dlg-next" data-autofocus>Suivant</button>';
    else acts = d.actions.map(function (a, k) {
      return '<button type="button" class="btn ' + (a.primary ? 'btn--primary' : a.disabled ? '' : 'btn--small') + '"' + (a.disabled ? ' disabled' : ' data-act="' + a.act + '"' + (a.arg ? ' data-arg="' + esc(a.arg) + '"' : '')) + (k === 0 && !a.disabled ? ' data-autofocus' : '') + '>' + esc(a.label) + '</button>';
    }).join('');
    return '<div class="dlg"><div class="dlg__body"><div class="dlg__who"><b>' + esc(d.who) + '</b><span>' + (d.i + 1) + ' / ' + d.lines.length + '</span></div>' +
      '<p class="dlg__text">' + d.lines[d.i] + '</p></div><div class="dlg__acts">' + acts + '</div></div>';
  }

  function starCardHtml(u) {
    var ch = chapterOf(u.chapter);
    return '<div class="starcard"><div><div class="starcard__chap">' + esc(ch ? ch.label : '') + '</div><h2 class="starcard__name" tabindex="-1">' + esc(typo(u.label)) + '</h2>' +
      '<dl class="facts"><dt>Éclat actuel</dt><dd>' + Scene.starIcon(u.lit ? u.brightness : null, 22) + esc(u.lit ? brightWord(u.brightness) : 'éteinte') + '</dd>' +
      '<dt>Meilleure série</dt><dd class="num">' + (u.best === null || u.best === undefined ? '—' : u.best + '/12') + '</dd>' +
      '<dt>Prochain éclat</dt><dd>' + esc(nextLevel(u.best)) + '</dd></dl></div>' +
      '<div class="starcard__acts"><button type="button" class="btn btn--primary" data-act="series" data-arg="' + esc(u.code) + '" data-autofocus>Observer : 12 questions <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn btn--small" data-act="deselect">Retour au carnet <span class="kbd">Échap</span></button></div></div>';
  }

  function mapHtml() {
    var m = Scene.skyMap(G.progress, { selected: G.selected, fresh: G.fresh });
    var spots = m.spots.map(function (s) {
      var pos = 'left:' + (s.x / 10).toFixed(2) + '%;top:' + (s.y / 10).toFixed(2) + '%';
      if (s.kind === 'star') {
        var u = unitOf(s.unit);
        return '<button type="button" class="spot" style="' + pos + '" data-act="select" data-arg="' + esc(s.unit) + '" aria-label="' + esc(starName(u)) + '"' + (G.selected === s.unit ? ' aria-pressed="true"' : '') + '></button>';
      }
      if (s.kind === 'comet') {
        var c = G.progress.comets.filter(function (x) { return x.misconception === s.comet; })[0];
        return '<button type="button" class="spot spot--comet" style="' + pos + '" data-act="comet" data-arg="' + esc(s.comet) + '" aria-label="Comète : ' + esc(typo(c ? c.label : '')) + ' (' + esc(typo(c ? c.unitName : '')) + ') — l’étudier"></button>';
      }
      var f = G.progress.final;
      return '<button type="button" class="spot spot--crown" style="' + pos + '" data-act="final-intro" aria-label="La Couronne — épreuve blanche' + (f.best !== null && f.best !== undefined ? ', meilleur score ' + f.best + '/12' : '') + '"></button>';
    }).join('');
    G.fresh = null; // the lighting is played once
    return '<div class="map" role="group" aria-label="Carte du ciel : ' + G.progress.lit + ' étoiles allumées sur ' + G.progress.total + '">' + m.svg + spots + '</div>';
  }

  function renderHome(focusSel) {
    G.screen = 'home';
    G.confirmEnd = false;
    if (!G.dlg) G.dlg = homeDialog();
    var u = G.selected ? unitOf(G.selected) : null;
    var lamp = !!G.data.lamp;
    var html = topbar('Automatismes · 1re', starsCount() + bestiaryButton() + '<button type="button" class="btn btn--small" data-act="exit">Quitter</button>') +
      '<main class="home">' + Scene.mountains() +
      '<div class="status"><span class="status__grade">' + esc(G.progress.grade) + '</span><span class="status__eclats">' + esc(eclatsLabel().toUpperCase()) + '</span>' +
      '<button type="button" class="btn btn--small lamp-toggle" data-act="lamp" aria-pressed="' + lamp + '">Lampe frontale : ' + (lamp ? 'allumée' : 'éteinte') + '</button></div>' +
      '<div class="scene-cell">' + Scene.scene(G.user.avatar, lamp) + '</div>' +
      '<div class="map-cell">' + mapHtml() + '</div>' +
      '<section class="panel panel--corners" aria-label="' + (u ? 'Étoile choisie' : CARNET) + '">' + (u ? starCardHtml(u) : dialogHtml()) + '</section>' +
      '</main>';
    show(html, focusSel);
    if (G.dlg && !u) markRead();
  }

  // once the last line of a page is shown, the page is read: it will not open again
  function markRead() {
    var d = G.dlg;
    if (!d || !d.id || d.i < d.lines.length - 1) return;
    var read = G.data.read || [];
    if (read.indexOf(d.id) >= 0) return;
    read.push(d.id);
    // reaching a grade also turns the pages of the grades below it
    if (/^g\d+$/.test(d.id)) [8, 16, 24, 31].forEach(function (th) { if (th < +d.id.slice(1) && read.indexOf('g' + th) < 0) read.push('g' + th); });
    G.data.read = read;
    save();
  }

  /* ---------- 2. every star, by constellation ---------- */

  function renderList() {
    G.screen = 'list';
    var p = G.progress;
    var cols = p.chapters.map(function (ch) {
      var items = p.units.filter(function (u) { return u.chapter === ch.code; }).map(function (u) {
        if (u.hidden) return '<li class="is-hidden">' + Scene.starIcon(null, 22) + '<span>' + esc(typo(u.label)) + '</span><em>indisponible</em></li>';
        var state = u.lit ? brightWord(u.brightness) + ' · ' + u.best + '/12' : u.series ? 'éteinte · ' + u.best + '/12' : 'jamais observée';
        return '<li><button type="button" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(starName(u)) + ' — observer">' + Scene.starIcon(u.lit ? u.brightness : null, 26) + '<span>' + esc(typo(u.label)) + '</span><em>' + esc(state) + '</em></button></li>';
      }).join('');
      return '<section aria-labelledby="ch-' + esc(ch.code) + '"><h2 id="ch-' + esc(ch.code) + '">' + esc(ch.label) + ' <small>' + ch.lit + '/' + ch.total + '</small></h2><ul>' + items + '</ul></section>';
    }).join('');
    var comets = p.comets.length ? '<section aria-labelledby="ch-comets"><h2 id="ch-comets">Comètes en vue <small>' + p.comets.length + '</small></h2><ul>' + p.comets.map(function (c) {
      return '<li><button type="button" data-act="comet" data-arg="' + esc(c.misconception) + '"><span aria-hidden="true">☄</span><span>' + esc(typo(c.label)) + '</span><em>' + esc(typo(c.unitName)) + '</em></button></li>';
    }).join('') + '</ul></section>' : '';
    var crown = '<section aria-labelledby="ch-crown"><h2 id="ch-crown">La Couronne <small>' + (p.final.best !== null && p.final.best !== undefined ? p.final.best + '/12' : '—') + '</small></h2><ul><li>' +
      (p.final.unlocked ? '<button type="button" data-act="final-intro"><span aria-hidden="true">♛</span><span>L’épreuve blanche</span><em>' + minutes(p.final.timeLimitSeconds) + ' min</em></button>' : '<span class="is-hidden" style="display:flex;gap:10px;padding:9px 10px;color:#A3ACD0">Apparaît quand toutes les étoiles sont allumées.</span>') + '</li></ul></section>';
    show(topbar('Choisir une étoile', starsCount() + '<button type="button" class="btn btn--small" data-act="home">Retour à la carte <span class="kbd">Échap</span></button>') +
      '<main class="screen" tabindex="-1"><h2 class="big-title" tabindex="-1" data-autofocus>Toutes les étoiles</h2>' +
      '<p class="kicker" style="margin-top:10px">Séries illimitées · 12 questions · sans chronomètre</p><div class="list">' + cols + comets + crown + '</div>' +
      '<p style="margin-top:22px">' + bestiaryButton() + '</p></main>');
  }

  /* ---------- 3. a run: a series, a comet or La Couronne ---------- */

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
      // the clock shown is counted from now (the local clock may differ from the server's);
      // the server keeps the real deadline and refuses an answer after it
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
      // positions already answered before a resume: done, result unknown here
      for (var k = 0; k < r.position; k++) if (!run.results[k]) run.results[k] = 'past';
      run.q = r.question;
      run.correction = null;
      run.notice = null;
      if (run.mode === 'final' && r.deadline && run.resumed) run.localDeadline = Date.parse(r.deadline);
      renderRun();
    }, function (e) {
      G.busy = false;
      if (e && (e.message === 'session-ended' || e.message === 'session-unknown')) { clearOpen(); refresh().then(function () { goHome(); }); return; }
      fail(e);
    });
  }

  function runTitle(run) {
    if (run.mode === 'final') return 'La Couronne' + (run.q ? ' · ' + chapterName(unitOf(run.q.unit)) : '');
    var u = unitOf(run.unit || (run.q && run.q.unit));
    if (run.mode === 'comet') return 'Comète · ' + typo(run.comet && run.comet.label ? run.comet.label : u ? u.label : '');
    return (u ? chapterName(u) + ' · ' + typo(u.label) : '');
  }

  function progressHtml(run) {
    var items = [];
    for (var k = 0; k < run.size; k++) {
      var r = run.results[k], cls = k === run.position && !run.correction ? 'cur' : r === 'ok' ? 'ok' : r === 'ko' || r === 'dk' ? 'ko' : r ? 'done' : '';
      if (run.mode === 'final') {
        items.push('<li class="' + (k === run.position ? 'cur' : r ? 'done' : '') + '">' + (k + 1) + ' ' + (r ? '◆' : '◇') + '</li>');
      } else items.push('<li class="' + cls + '"></li>');
    }
    var label = run.mode === 'final' ? 'Questions répondues : ' + run.results.filter(Boolean).length + ' sur ' + run.size : 'Avancement de la série';
    return '<ol class="progress' + (run.mode === 'final' ? ' progress--boxes' : '') + '" aria-label="' + esc(label) + '">' + items.join('') + '</ol>';
  }

  function renderRun() {
    G.screen = 'run';
    var run = G.run, q = run.q, done = !!run.correction, c = run.correction;
    var t = TYPING[q.answerType] || TYPING.number;
    var sub = run.mode === 'final' ? 'Épreuve blanche' : run.mode === 'comet' ? 'Étude de la comète' : 'Série de 12';
    var right;
    if (run.mode === 'final') {
      right = '<span class="clock" id="clock" role="timer" aria-label="Temps restant"><b id="clock-left">' + clockText((run.localDeadline - Date.now()) / 1000) + '</b> / ' + clockText(run.limit) + '</span>' +
        '<button type="button" class="btn btn--small" data-act="final-end">' + (G.confirmEnd ? 'Confirmer : terminer maintenant' : 'Terminer l’épreuve') + '</button>';
    } else right = '<button type="button" class="btn btn--small" data-act="leave">' + (run.mode === 'comet' ? 'Retour à la carte' : 'Quitter la série') + '</button>';

    var field = '<div class="field' + (done ? (c.ok ? ' is-ok' : ' is-locked') : '') + '"><input id="answer" type="text" inputmode="text" autocomplete="off" autocapitalize="off" spellcheck="false" aria-describedby="typing-help" aria-label="Votre réponse" placeholder="' + (done ? '' : 'Votre réponse') + '"' +
      (done ? ' readonly value="' + esc(c.dontKnow ? '—' : (c.input || '')) + '"' : ' value="' + esc(run.typed || '') + '"') + '>' +
      (q.unit_label ? '<span class="field__unit" aria-label="en ' + esc(q.unit_label === '%' ? 'pourcentage' : q.unit_label) + '">' + esc(q.unit_label) + '</span>' : '') + '</div>';
    var chip = done ? (c.dontKnow ? '<span class="chip chip--dk">○ Sans réponse</span>' : c.ok ? '<span class="chip chip--ok">✓ Juste</span>' : '<span class="chip chip--ko">✗ Faux</span>') : '';

    var body = '<div class="qmain"><h2 class="prompt" id="prompt" tabindex="-1">' + q.prompt + '</h2>' +
      (q.figure ? '<div class="figure">' + q.figure + '</div>' : '') +
      '<div class="answer-row">' + field + chip + '</div>';
    if (!done) {
      body += '<div class="typing-help" id="typing-help">' + esc(t.help) + (q.form && FORMS[q.form] ? ' ' + esc(FORMS[q.form]) : '') + '</div>';
      if (t.keys.length) body += '<div class="keys" role="group" aria-label="Symboles à insérer">' + t.keys.map(function (k) { return '<button type="button" data-act="key" data-arg="' + esc(k) + '" aria-label="Insérer ' + esc(k) + '">' + esc(k) + '</button>'; }).join('') + '</div>';
      if (run.notice) body += '<div class="notice' + (run.notice.warn ? ' notice--warn' : '') + '" role="alert">' + run.notice.html + '</div>';
      body += '<div class="qacts"><button type="button" class="btn btn--primary" data-act="submit">' + (run.mode === 'final' ? 'Question suivante' : 'Valider') + ' <span class="kbd">Entrée</span></button>' +
        '<button type="button" class="btn" data-act="dont-know">Je ne sais pas</button>' +
        '<button type="button" class="btn btn--link" data-act="draft" aria-expanded="' + !!run.draftOpen + '" aria-controls="draft">' + (run.draftOpen ? 'Fermer le brouillon' : 'Ouvrir le brouillon') + '</button></div>';
    } else {
      body += '<p id="typing-help" hidden></p>';
    }
    body += '</div>';

    var draft = run.draftOpen ? '<div class="draft" id="draft"><div class="draft__head"><label for="draft-text">Brouillon</label><button type="button" class="btn btn--small" data-act="draft-clear">Effacer</button></div>' +
      '<textarea id="draft-text" spellcheck="false" aria-describedby="draft-note"></textarea><span class="draft__note" id="draft-note">Jamais enregistré ni envoyé : effacé à la fin ' + (run.mode === 'final' ? 'de l’épreuve' : run.mode === 'comet' ? 'de l’étude' : 'de la série') + '.</span></div>' : '';

    var correction = '';
    if (done) {
      var last = run.position + 1 >= run.size;
      var title = c.dontKnow ? 'La méthode' : c.ok ? 'Calcul juste.' : 'Le télescope a dévié.';
      correction = '<div class="correction"><section class="panel" aria-labelledby="corr-title"><div class="dlg"><div class="correction__body">' +
        '<h3 class="correction__title' + (c.ok ? '' : ' is-ko') + '" id="corr-title">' + esc(title) + '</h3>' +
        (!c.ok && !c.dontKnow && c.misconceptionLabel ? '<div class="correction__error">Erreur repérée : ' + esc(lowerFirst(typo(c.misconceptionLabel))) + '</div>' : '') +
        '<p class="method">' + (c.method || '') + '</p>' +
        (c.ok ? '' : '<div class="expected"><span>Bonne réponse</span><b>' + (c.expected || esc(c.expectedText || '')) + (c.unit_label && String(c.expectedText || '').indexOf(c.unit_label) < 0 ? ' ' + esc(c.unit_label) : '') + '</b></div>') +
        '</div><div class="correction__acts"><button type="button" class="btn btn--primary" data-act="next" data-autofocus>' + (last ? 'Voir le bilan' : 'Question suivante') + ' <span class="kbd">Entrée</span></button></div></div></section></div>';
    }

    var legend = run.mode === 'final' ? '<div class="legend"><span>◆ répondue</span><span>◇ à faire</span><span>correction à la fin</span></div>' : '';
    show(topbar(sub, right) +
      '<main class="screen"><div class="qhead"><span class="qhead__title">' + esc(runTitle(run)) + '</span>' + streakHtml(run) + '<span class="qhead__num">Question ' + (run.position + 1) + '/' + run.size + '</span></div>' +
      progressHtml(run) + gaugeHtml(run) +
      '<div class="qbody' + (run.draftOpen ? ' with-draft' : '') + '">' + body + draft + '</div>' + correction + legend + '</main>',
      done ? '[data-act="next"]' : '#answer');

    var ta = document.getElementById('draft-text');
    if (ta) { ta.value = run.draft || ''; ta.addEventListener('input', function () { run.draft = ta.value; }); }
    var inp = document.getElementById('answer');
    if (inp && !done) {
      inp.addEventListener('input', function () { run.typed = inp.value; });
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
      if (run.typed) inp.setSelectionRange(inp.value.length, inp.value.length);
    }
    if (run.mode === 'final') startClock();
  }

  /* The gauge of a series or a comet study, from the corrections already given - never during the
     Couronne, whose answers are not corrected before its end. */
  function gaugeHtml(run) {
    if (run.mode === 'final') return '';
    return E.gauge({
      mode: run.mode, results: run.results, size: run.size,
      best: run.before ? run.before.best : null,
      solvedAt: 2, solve: 'l’élucider', levels: GAUGE_LEVELS, record: 'votre meilleure série'
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
    var inp = document.getElementById('answer');
    var input = dontKnow ? 'dont_know' : (inp ? inp.value : '').trim();
    if (!input) {
      run.notice = { html: 'Écrivez une réponse, ou choisissez « Je ne sais pas ».', warn: true };
      renderRun();
      return;
    }
    G.busy = true;
    call('answer', { session: run.session, input: input }).then(function (r) {
      G.busy = false;
      if (!r.recorded) {
        var t = TYPING[run.q.answerType] || TYPING.number;
        run.notice = r.unreadable
          ? { html: 'Je n’ai pas su lire cette réponse : écrivez ' + esc(lowerFirst(t.help)) + ' Rien n’est compté.', warn: true }
          : { html: 'La valeur est juste, mais pas l’écriture demandée. ' + esc(r.formIssue || '') + ' Rien n’est compté : réécrivez-la, puis validez.', warn: true };
        renderRun();
        announce(plain(run.notice.html));
        var i = document.getElementById('answer'); if (i) i.select();
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
      announce(r.dontKnow ? 'Noté « je ne sais pas ». La méthode est affichée.' : r.ok ? 'Calcul juste.' : 'Réponse fausse.' + (r.misconceptionLabel ? ' Erreur repérée : ' + lowerFirst(typo(r.misconceptionLabel)) + '.' : '') + ' Bonne réponse : ' + plain(r.expected || r.expectedText));
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
    var inp = document.getElementById('answer');
    if (!inp || inp.readOnly) return;
    var s = inp.selectionStart || inp.value.length, e = inp.selectionEnd || s;
    inp.value = inp.value.slice(0, s) + k + inp.value.slice(e);
    G.run.typed = inp.value;
    inp.focus();
    inp.setSelectionRange(s + k.length, s + k.length);
  }

  /* ---------- the Couronne's clock: shown here, decided by the server ---------- */

  function startClock() {
    stopClock();
    var tick = function () {
      var run = G.run;
      if (!run || run.mode !== 'final') { stopClock(); return; }
      var left = (run.localDeadline - Date.now()) / 1000, el = document.getElementById('clock-left');
      if (el) { el.textContent = clockText(left); el.parentNode.classList.toggle('low', left <= 120); }
      if (left <= 0) { stopClock(); announce('Le temps est écoulé.'); endRun(); }
    };
    clockTimer = setInterval(tick, 1000);
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
      run.draft = ''; // the scratch sheet goes with the session
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
      out.push(r === 'ok' ? '<li class="ok" aria-label="Question ' + (k + 1) + ' : juste">✓</li>' : r === 'dk' ? '<li aria-label="Question ' + (k + 1) + ' : je ne sais pas">○</li>' : r === 'ko' ? '<li class="ko" aria-label="Question ' + (k + 1) + ' : fausse">✗</li>' : '<li aria-label="Question ' + (k + 1) + '">·</li>');
    }
    return '<ul class="boxes">' + out.join('') + '</ul>';
  }

  function cometsRaisedHtml(list) {
    if (!list || !list.length) return '';
    return list.map(function (c) {
      return '<div class="notice" style="margin-top:18px;display:flex;gap:18px;align-items:center;flex-wrap:wrap"><span style="flex:1;min-width:220px"><b>Une comète traverse la carte</b> : « ' + esc(typo(c.label)) + ' ». La même erreur est revenue deux fois ; vous pouvez l’étudier, ou la laisser passer.</span>' +
        '<button type="button" class="btn btn--small" data-act="comet" data-arg="' + esc(c.misconception) + '">Étudier la comète</button></div>';
    }).join('');
  }

  // 4. the end of a series: the score, the best, the brightness, a star newly lit, a comet raised
  function renderSeriesEnd() {
    G.screen = 'seriesEnd';
    var r = G.endRes, run = G.run, u = unitOf(r.unit) || { label: r.unit };
    var before = run.before || {};
    var lit = !!r.brightness;
    var record = before.best !== null && before.best !== undefined && r.score > before.best && !r.newlyLit && !r.brighter;
    var note = r.score === 12 ? 'Douze sur douze. Je n’ai rien à ajouter dans la marge, et c’est rare.' : r.newlyLit ? pick(AFTER_LIT, r.score + run.session) : r.brighter ? 'L’étoile ' + deW(typo(u.label)) + ' brille davantage. Une série moins réussie ne la ternira pas : son éclat ne baisse jamais.' : pick(AFTER_ERROR, r.score + run.session);
    var eclatRow = lit
      ? (r.brighter && before.brightness ? '<span class="was">' + Scene.starIcon(before.brightness, 20) + ' ' + esc(brightWord(before.brightness)) + '</span> <span aria-label="devient">→</span> ' : '') + Scene.starIcon(r.brightness, 24) + esc(brightWord(r.brightness))
      : 'éteinte — il en faut 6 pour l’allumer';
    show(topbar('Série de 12', starsCount()) +
      '<main class="screen"><div class="end-head"><h2 class="big-title" tabindex="-1">Fin de la série</h2><span class="qhead__title">' + esc(chapterName(u) + ' · ' + typo(u.label)) + '</span></div>' +
      '<div class="result"><div class="result__star">' + Scene.starIcon(lit ? r.brightness : null, 180) + '<span class="result__word' + (lit ? '' : ' is-off') + '">' + esc(lit ? brightWord(r.brightness) : 'éteinte') + '</span>' +
      '<span class="kicker">Prochain éclat : ' + esc(nextLevel(r.best)) + '</span></div>' +
      '<div><div class="score-line"><div class="score" aria-label="' + r.score + ' calculs justes sur ' + r.size + '"><b>' + r.score + '</b><span>/ ' + r.size + '</span></div>' +
      (r.newlyLit ? '<p class="newlit">Nouvelle étoile allumée !</p>' : r.brighter ? '<p class="newlit">Plus brillante !</p>' : record ? '<p class="newlit">Nouveau record !</p>' : '') + '</div><div class="score-cap">Calculs justes</div>' +
      boxesHtml(run, r.size) +
      '<dl class="rows"><div><dt>Éclat</dt><dd>' + eclatRow + '</dd></div><div><dt>Meilleure série</dt><dd class="num">' + (r.best === null || r.best === undefined ? '—' : r.best + '/12') + '</dd></div></dl>' +
      cometsRaisedHtml(r.comets) + '</div></div>' +
      '<section class="panel result-note" aria-label="' + CARNET + '"><div class="dlg"><div class="dlg__body"><div class="dlg__who"><b>' + CARNET + '</b></div><p class="dlg__text">' + esc(note) + '</p></div>' +
      '<div class="dlg__acts">' + againHtml(r) + '</div></div></section></main>');
    announce('Fin de la série : ' + r.score + ' sur ' + r.size + '.' + (r.newlyLit ? ' Nouvelle étoile allumée !' : r.brighter ? ' L’étoile brille davantage.' : record ? ' Nouveau record.' : ''));
    celebrate(r.newlyLit || r.brighter ? 'level' : record ? 'record' : 'end', '.result__star svg');
  }

  /* « Encore une » in one key: the same star again unless it is at its highest, then the star
     nearest to its next level - a fact read on the map, never a recommendation. */
  function againHtml(r) {
    var u = unitOf(r.unit), maxed = u && u.best >= 12, near = E.closest(G.progress.units, r.unit), acts = [];
    if (!maxed) acts.push('<button type="button" class="btn btn--primary" data-act="series" data-arg="' + esc(r.unit) + '" data-autofocus>Encore une série <span class="kbd">Entrée</span></button>');
    if (near) acts.push('<button type="button" class="btn sg-next__near' + (maxed ? ' btn--primary' : '') + '" data-act="series" data-arg="' + esc(near.unit.code) + '"' + (maxed ? ' data-autofocus' : '') + '>Observer : ' + esc(typo(near.unit.label)) +
      '<span class="sg-next__hint">' + esc(nearHint(near)) + '</span></button>');
    acts.push('<button type="button" class="btn btn--small" data-act="home"' + (acts.length ? '' : ' data-autofocus') + '>Retour à la carte <span class="kbd">Échap</span></button>');
    return '<div class="sg-next">' + acts.join('') + '</div>';
  }
  function nearHint(near) {
    var b = near.unit.best;
    return (b === null || b === undefined ? 'jamais observée' : 'meilleure série ' + b + '/12') + ' · ' + nextLevel(b);
  }
  function celebrate(cue, selector) {
    E.sound.play(cue);
    if (cue === 'level' || cue === 'record' || cue === 'solved') E.burst(app.querySelector(selector), cue === 'level');
  }
  function deW(w) { return (/^[aeiouyhéèêàâîôû]/i.test(w) ? 'd’' : 'de ') + w; }

  // 5. a comet: the explanation, then 3 questions, then « élucidée » or « reste en vue »
  function renderCometIntro() {
    G.screen = 'cometIntro';
    var run = G.run, c = run.comet || {}, u = unitOf(c.unit || run.unit);
    show(topbar('Étude de la comète', starsCount() + '<button type="button" class="btn btn--small" data-act="leave">Retour à la carte</button>') +
      '<main class="screen"><div class="comet-study">' + Scene.cometArt() +
      '<span class="kicker">' + esc((u ? chapterName(u) + ' · ' + typo(u.label) : typo(c.unitName || '')).toUpperCase()) + '</span>' +
      '<h2 class="big-title is-italic" tabindex="-1">' + esc(typo(c.label || 'Une comète')) + '</h2>' +
      '<p class="explanation">' + (run.explanation || '') + '</p>' +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="comet-go" data-autofocus>Commencer les 3 questions <span class="kbd">Entrée</span></button>' +
      '<span class="kicker">2 justes sur 3 pour l’élucider</span></div>' +
      '<div><button type="button" class="btn btn--small" data-act="leave">Plus tard</button></div></div></main>');
  }

  function renderCometEnd() {
    G.screen = 'cometEnd';
    var r = G.endRes, run = G.run, c = run.comet || {};
    show(topbar('Étude de la comète', starsCount()) +
      '<main class="screen"><div class="comet-study"><span class="kicker">Étude terminée · ' + esc(typo(c.label || '')) + '</span>' +
      '<h2 class="big-title is-gold" tabindex="-1">' + (r.solved ? 'Comète élucidée.' : 'Elle reste en vue.') + '</h2>' +
      boxesHtml(run, r.size) +
      (r.solved ? '<div class="sg-bestiary-new">' + E.bestiaryArt('observatoire', run.misconception, 110) + '<span class="kicker">Nouvelle page au ' + esc(BESTIARY.toLowerCase()) + '</span></div>' : '') +
      '<p class="explanation">' + (r.solved
        ? 'Elle quitte la carte : ' + r.score + ' justes sur ' + r.size + '. Hélène notait les comètes dans la marge ; celle-ci y est aussi. Elle ne reviendra que si l’erreur revient.'
        : r.score + ' juste' + (r.score > 1 ? 's' : '') + ' sur ' + r.size + '. Rien n’est perdu : elle vous attend sur la carte, et deux justes sur trois suffisent pour l’élucider.') + '</p>' +
      cometsRaisedHtml(r.comets) +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="home" data-autofocus>Retour à la carte <span class="kbd">Entrée</span></button>' +
      (r.solved ? '<button type="button" class="btn" data-act="bestiary">Ouvrir le ' + esc(BESTIARY.toLowerCase()) + '</button>' : '<button type="button" class="btn" data-act="comet" data-arg="' + esc(run.misconception) + '">Étudier de nouveau</button>') + '</div></div></main>');
    announce(r.solved ? 'Comète élucidée. Elle rejoint le carnet des comètes.' : 'La comète reste en vue.');
    celebrate(r.solved ? 'solved' : 'end', '.sg-bestiary-new svg');
  }

  // 6. La Couronne: the introduction, then the review once it is over
  function renderFinalIntro() {
    G.screen = 'finalIntro';
    var f = G.progress.final, extra = G.user.extraTimePercent || 0;
    if (!f.unlocked) { toast(ERRORS['final-locked']); return; }
    var mins = minutes(f.timeLimitSeconds);
    show(topbar('Épreuve blanche', starsCount() + '<button type="button" class="btn btn--small" data-act="home">Retour à la carte <span class="kbd">Échap</span></button>') +
      '<main class="screen"><div class="final-intro"><span class="kicker">La sixième constellation</span>' +
      '<h2 class="big-title" tabindex="-1">' + esc(f.label || 'La Couronne') + '</h2>' +
      '<p class="explanation">' + f.size + ' questions courtes puisées dans les cinq constellations, ' + mins + ' minutes. Comme à l’épreuve, les réponses sont notées sans être corrigées : la correction vient à la fin, question par question.</p>' +
      '<div class="facts-line"><span><b>' + f.size + '</b> questions</span><span><b>' + mins + '</b> minutes' + (extra > 0 ? ' · temps majoré (+' + extra + ' %)' : '') + '</span><span>Meilleur score <b>' + (f.best !== null && f.best !== undefined ? f.best + '/' + f.size : '—') + '</b></span></div>' +
      '<p class="method">« Je ne sais pas » reste possible à chaque question. Une question laissée sans réponse quand le temps s’écoule compte fausse. L’épreuve se rejoue autant de fois que vous le voulez ; les étoiles de la carte restent allumées quoi qu’il arrive.</p>' +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="final-go" data-autofocus>Commencer l’épreuve <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn" data-act="home">Plus tard</button></div></div></main>');
  }

  function renderReview() {
    G.screen = 'review';
    var r = G.endRes, items = (r.review || []).map(function (it, k) {
      var given = it.dontKnow ? 'je ne sais pas' : it.answered ? (it.input || '') : 'sans réponse';
      var chip = it.ok ? '<span class="chip chip--ok">✓ Juste</span>' : it.answered && !it.dontKnow ? '<span class="chip chip--ko">✗ Faux</span>' : '<span class="chip chip--dk">○ Sans réponse</span>';
      return '<li><span class="review__n">' + String(k + 1).padStart(2, '0') + '</span><div><div class="review__unit">' + esc(typo(it.unitName)) + '</div>' +
        '<div class="review__prompt">' + it.prompt + '</div>' + (it.figure ? '<div class="figure">' + it.figure + '</div>' : '') +
        '<div class="review__given"><span>Votre réponse</span>' + esc(given) + '</div>' +
        (!it.ok && it.misconceptionLabel ? '<div class="correction__error" style="margin-top:6px">Erreur repérée : ' + esc(lowerFirst(typo(it.misconceptionLabel))) + '</div>' : '') +
        '<details' + (it.ok ? '' : ' open') + '><summary>' + (it.ok ? 'Voir la méthode' : 'La méthode') + '</summary><p class="method">' + (it.method || '') + '</p>' +
        '<div class="expected"><span>Réponse attendue</span><b>' + (it.expected || '') + '</b></div></details></div>' + chip + '</li>';
    }).join('');
    var note = r.score >= 12 ? 'La Couronne brille en entier. Douze calculs sans une erreur : la marge reste blanche.' : r.score >= 10 ? r.score + ' étoiles à la Couronne. Les autres attendent une prochaine épreuve ; celles de la carte restent toutes allumées.' : r.score + ' étoile' + (r.score > 1 ? 's' : '') + ' à la Couronne. Les étoiles de la carte restent toutes allumées : l’épreuve ne retire rien.';
    show(topbar('Épreuve blanche', starsCount()) +
      '<main class="screen"><div class="review-wrap"><div class="review-side"><h2 class="big-title" tabindex="-1">Bilan de l’épreuve</h2>' +
      '<span class="qhead__title">La Couronne</span>' + Scene.crown(r.score, 380) +
      '<div class="facts-line" style="flex-direction:column;gap:6px"><span><b>' + r.score + '</b> réponse' + (r.score > 1 ? 's' : '') + ' juste' + (r.score > 1 ? 's' : '') + ' sur ' + r.size + '</span><span>Meilleur score · <b>' + (r.best !== null && r.best !== undefined ? r.best : r.score) + '/' + r.size + '</b></span></div>' +
      (r.timedOut ? '<div class="notice notice--warn">' + (G.run && G.run.endedByPlayer ? 'Épreuve terminée avant la dernière question' : 'Le temps s’est écoulé') + ' : les questions restées sans réponse comptent fausses.</div>' : '') +
      cometsRaisedHtml(r.comets) +
      '<section class="panel" aria-label="' + CARNET + '"><div class="dlg__who"><b>' + CARNET + '</b></div><p class="dlg__text" style="margin-top:8px">' + esc(note) + '</p></section>' +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="home" data-autofocus>Retour à la carte</button><button type="button" class="btn" data-act="final-intro">Rejouer la Couronne</button></div></div>' +
      '<ol class="review" aria-label="Correction, question par question">' + items + '</ol></div></main>');
    announce('Épreuve terminée : ' + r.score + ' sur ' + r.size + '.');
    var was = G.run ? G.run.beforeBest : null;
    celebrate(was !== null && was !== undefined && r.score > was ? 'record' : 'end', '.review-side svg');
  }

  /* ---------- 7. the carnet des comètes: every typical error elucidated ---------- */

  function bestiaryButton() {
    var n = (G.progress.bestiary || []).length;
    return '<button type="button" class="btn btn--small" data-act="bestiary">' + esc(BESTIARY) + ' <b>' + n + '</b></button>';
  }
  function renderBestiary() {
    G.screen = 'bestiary';
    var list = G.progress.bestiary || [], total = G.progress.bestiaryTotal || 0;
    var body = list.length ? '<ul class="sg-bestiary">' + list.map(function (b) {
      return '<li class="sg-bestiary__item">' + E.bestiaryArt('observatoire', b.misconception, 96) +
        '<span class="sg-bestiary__unit">' + esc(typo(b.unitName)) + '</span>' +
        '<h3 class="sg-bestiary__label">' + esc(typo(b.label)) + '</h3>' +
        (b.explanation ? '<details><summary>Ce qu’en disait Hélène</summary><p class="method">' + b.explanation + '</p></details>' : '') +
        '<span class="sg-bestiary__count">Élucidée le ' + esc(new Date(b.solvedAt).toLocaleDateString('fr-FR')) + '</span></li>';
    }).join('') + '</ul>'
      : '<p class="sg-bestiary__empty">Aucune comète élucidée pour l’instant. Quand la même erreur revient deux fois, une comète traverse la carte ; l’étudier et l’élucider la range ici, avec la méthode qui en vient à bout.</p>';
    show(topbar(BESTIARY, starsCount() + '<button type="button" class="btn btn--small" data-act="home">Retour à la carte <span class="kbd">Échap</span></button>') +
      '<main class="screen bestiary" tabindex="-1"><h2 class="big-title" tabindex="-1" data-autofocus>' + esc(BESTIARY) + '</h2>' +
      '<p class="sg-bestiary__count" style="margin-top:10px">' + list.length + ' comète' + (list.length > 1 ? 's' : '') + ' élucidée' + (list.length > 1 ? 's' : '') + ' sur ' + total + ' erreurs que le ciel connaît</p>' +
      body + '</main>');
  }

  /* ---------- 8. the reward scene ---------- */

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
      '<span class="kicker">' + (rw.raised ? 'Votre pièce change de finition' : 'Une pièce rejoint votre vitrine') + '</span>' +
      '<div class="reward__art">' + art + '</div>' +
      '<h2 class="reward__name" id="reward-name">' + esc(typo(rw.name)) + (fin ? ' <span style="font-style:italic;font-weight:500;font-size:.7em;color:#EDE6D6">' + esc(fin) + '</span>' : '') + '</h2>' +
      '<p class="reward__lore" id="reward-lore">' + esc(typo(rw.lore || (rw.kind === 'card' ? 'Une carte de constellation, pour votre vitrine.' : rw.kind === 'pin' ? 'Elle se porte sur votre avatar.' : ''))) + '</p>' +
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
      if (p.data && typeof p.data === 'object' && !Array.isArray(p.data)) {
        // keep what this page holds (it was saved before), take the rest from the server
        G.data = Object.assign({}, p.data, G.data);
      }
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
    lamp: function () { G.data.lamp = !G.data.lamp; save(); renderHome('[data-act="lamp"]'); },
    'dlg-next': function () { G.dlg.i = Math.min(G.dlg.i + 1, G.dlg.lines.length - 1); renderHome(); },
    select: function (arg) { G.selected = arg; G.data.last = arg; renderHome(); },
    pick: function (arg) { G.selected = arg; G.data.last = arg; renderHome(); },
    deselect: function () { var was = G.selected; G.selected = null; renderHome(was ? '[data-arg="' + was + '"].spot' : null); },
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
    if (G.busy && ['toast-close', 'reward-close', 'key', 'draft', 'draft-clear', 'sound'].indexOf(b.getAttribute('data-act')) < 0) return;
    fn(b.getAttribute('data-arg'));
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (layer.firstChild) { e.preventDefault(); closeReward(); return; }
    var t = document.querySelector('.toast');
    if (t) { t.remove(); return; }
    if (G.screen === 'home' && G.selected) { e.preventDefault(); ACTIONS.deselect(); return; }
    if (G.screen === 'list' || G.screen === 'finalIntro' || G.screen === 'bestiary' || G.screen === 'seriesEnd') { e.preventDefault(); goHome(); return; }
    if (G.screen === 'run' && G.run && G.run.draftOpen && document.activeElement && document.activeElement.id === 'draft-text') {
      e.preventDefault();
      var inp = document.getElementById('answer');
      if (inp) inp.focus();
    }
  });

  // the focus stays inside the reward dialog
  document.addEventListener('focusin', function (e) {
    if (layer.firstChild && !layer.contains(e.target)) {
      var b = layer.querySelector('button');
      if (b) b.focus();
    }
  });

  SDK.onReward(function (rewards) {
    (rewards || []).forEach(function (rw) { G.rewards.push(rw); });
    // after the screen the reward came with has been drawn
    setTimeout(showReward, reduceMotion ? 0 : 450);
  });

  /* ---------- start ---------- */

  document.getElementById('sky').innerHTML = Scene.starfield();
  Promise.all([call('getUser', {}), call('getProgress', {})]).then(function (res) {
    G.user = res[0];
    G.progress = res[1];
    G.data = res[1].data && typeof res[1].data === 'object' && !Array.isArray(res[1].data) ? res[1].data : {};
    if (G.data.last && !unitOf(G.data.last)) G.data.last = null;
    E.sound.voice('bell');
    E.sound.setMuted(G.data.mute === true); // on by default
    renderHome(false);
  }, function (e) {
    app.innerHTML = '<p class="loading" role="alert">' + esc(errorText(e)) + '</p>';
  });
})();
