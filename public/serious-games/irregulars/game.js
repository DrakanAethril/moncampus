/* The Irregulars — the game (design/validated/serious-games.md §1, §6, §8;
   design/validated/serious-games-irregulars.md).

   London, autumn 1895, around Baker Street. The cases pile up on the desk of 221B; the learner is
   one of the Irregulars, the street children who run London for the consulting detective, and
   questions the witnesses. Each case is one point of English grammar: an investigation of 12
   statements - a form to type, a QCM, the wrong word of a line to point at, the words of a
   question to put in order - solves it from 6 clues out of 12, and its mention is its best
   investigation (de justesse 6-7, résolue 8-9, brillamment 10-11, élémentaire 12): it never goes
   down. During an investigation the corkboard pins a clue per right statement and a red thread
   per error, which a later right statement lifts - a display, nothing is kept. A typical error
   made twice is a false lead (« fausse piste ») pinned on the board, lifted in 3 statements. When
   every case is solved, The Final Case opens: 12 statements from every case, in the time the
   server gives, corrected at the end - the detective is away, the Irregulars conclude alone.

   The platform generates and corrects: this page never sees an answer before it is corrected, and
   writes nothing but its own display state (saveProgress). Nothing depends on time or absence.
   The final case's clock is the platform's - shown in its bar, kept here only to close the
   session when it runs out.

   The statements of the witnesses come in English from the server; the interface speaks French,
   and Wiggins speaks English with his French underneath.

   Screens: home (Baker Street, the case board, Wiggins) · list · run (an investigation, a false
   lead or the final case) · seriesEnd · cometIntro · cometEnd · finalIntro · review · casebook;
   the glossary and the scratch notebook open over any screen. */
(function () {
  'use strict';

  var SDK = window.SGSdk, Scene = window.IRRScene, BOOK = window.IRRCasebook, esc = Scene.esc, E = window.SGEngage;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- wording ---------- */

  var LEVEL = { discrete: 'résolue de justesse', clear: 'résolue', bright: 'brillamment résolue', blazing: 'élémentaire' };
  var PLURALS = { blazing: ['élémentaire', 'élémentaires'], bright: ['brillamment résolue', 'brillamment résolues'], clear: ['résolue', 'résolues'], discrete: ['résolue de justesse', 'résolues de justesse'] };
  var GUIDE = 'Wiggins, chef des Irregulars';

  // A line of Wiggins is [English, French], or French alone when he explains the rules.
  var PROLOGUE = [
    ['Oi! You there. Wiggins, chief of the Irregulars. The guv’nor at 221B has more cases than he can follow.', 'Hé, toi ! Wiggins, chef des Irregulars. Le patron du 221B a plus d’affaires qu’il ne peut en suivre.'],
    ['Ask proper questions, or nobody talks to you.', 'Posez les bonnes questions, sinon personne ne vous parle.'],
    'Chaque affaire est un point de grammaire. Douze témoignages par enquête : six indices justes, et l’affaire est résolue. Une affaire résolue le reste : seule ta meilleure enquête compte.',
    'Choisis un lieu sur le tableau. Le carnet garde la liste des verbes irréguliers et les règles de chaque lieu ; le glossaire explique les mots de Londres.'
  ];
  var BEATS = {
    5: [['Street detective now, mate. The cabmen know your face.', 'Te voilà Street detective. Les cochers connaissent ta tête.'],
      'Si une même erreur revient deux fois, une fausse piste s’épingle au tableau, en fil rouge. Trois témoignages suffisent pour la lever, et rien ne bloque en attendant.'],
    10: [['Baker Street regular! Mrs Hudson gave you a biscuit. She never does that.', 'Un·e habitué·e de Baker Street ! Mrs Hudson t’a donné un biscuit. Elle ne fait jamais ça.'],
      'La moitié des affaires est classée. Aller vite ou lentement ne change rien à la mention d’une affaire.'],
    15: [['Lestrade asks for you by name now. Don’t let it go to your head.', 'Lestrade te demande par ton nom, maintenant. Ne prends pas la grosse tête.'],
      'Il reste quelques affaires, souvent les plus difficiles. Prends le temps qu’il faut.'],
    all: [['Every case closed. The guv’nor is away tonight… so the Irregulars conclude alone.', 'Toutes les affaires sont classées. Le patron est absent ce soir… alors les Irregulars concluent seuls.'],
      'The Final Case : douze témoignages de toutes les affaires, corrigés seulement à la fin. Tu peux t’y essayer autant de fois que tu voudras. Et le patron t’a laissé sa loupe : elle est à toi.']
  };
  var GREET = [
    'Le tableau est presque vide. Choisis un lieu : douze témoignages suffisent pour résoudre une affaire.',
    'Tu connais maintenant le chemin de Baker Street. Le tableau commence à se remplir.',
    'La moitié des affaires est classée. Une enquête manquée ne retire rien à la meilleure.',
    'Il reste peu d’affaires ouvertes, et ce sont souvent les plus coriaces.',
    'Toutes les affaires sont classées. Ce soir, The Final Case.'
  ];
  var AFTER_ERROR = [
    ['Not enough clues yet. Another round of witnesses?', 'Pas encore assez d’indices. On refait le tour des témoins ?'],
    ['No harm done, mate. A bad round never closes a case.', 'Pas de casse. Une mauvaise tournée ne ferme jamais une affaire.'],
    ['Even the guv’nor gets it wrong sometimes. Not often, mind.', 'Même le patron se trompe, parfois. Pas souvent, remarque.']
  ];
  // what each mention is called by the gauge of an investigation in progress (shared/engage.js)
  var GAUGE_LEVELS = {
    6: ['résoudre l’affaire', 'Affaire résolue !'],
    8: ['la mention « résolue »', 'Mention « résolue » obtenue !'],
    10: ['la mention « brillamment »', 'Brillamment résolue !'],
    12: ['la mention « élémentaire »', 'Douze sur douze : élémentaire !']
  };
  var BESTIARY = 'Dossier des fausses pistes';
  var AFTER_SOLVED = [
    ['Six clues out of twelve. Case closed, mate. Now we knock at 221B.', 'Six indices sur douze. Affaire classée. On frappe au 221B.'],
    ['Case closed. The guv’nor will want the file.', 'Affaire classée. Le patron voudra le dossier.'],
    ['Another one for the cabinet. Spot on.', 'Une de plus dans le classeur. Bien vu.']
  ];

  // What to answer, per answer type (never a hint about the answer itself)
  var TYPING = {
    text: 'Une forme ou quelques mots en anglais. Les orthographes britannique et américaine sont acceptées.',
    choice: 'Choisis une proposition (touches 1 à 4), puis valide.',
    spot: 'Clique sur le mot faux, ou sur « La phrase est correcte », puis valide.',
    order: 'Clique sur les mots dans l’ordre de la question ; clique sur un mot placé pour le retirer.'
  };
  var ERRORS = {
    network: 'La liaison avec Formatool est coupée. Vérifie la connexion, puis réessaie.',
    timeout: 'Formatool ne répond pas. Ce jeu s’ouvre depuis la page Serious games de Formatool.',
    'unit-unavailable': 'Cette affaire n’est pas disponible pour le moment.',
    'final-locked': 'The Final Case n’ouvre qu’une fois toutes les affaires résolues.',
    'comet-not-in-view': 'Cette fausse piste est déjà levée.',
    'session-ended': 'Cette enquête est déjà terminée.',
    'session-unknown': 'Cette enquête n’existe plus.',
    'data-too-large': 'Le tableau est trop chargé pour être rangé.'
  };

  /* ---------- state ---------- */

  var G = {
    user: null,
    progress: null,
    data: {},            // display state only, saved with saveProgress
    screen: 'home',
    place: null,         // the place whose panel is open on the main screen
    selected: null,      // the case whose file is open
    dlg: null,
    run: null,
    busy: false,
    fresh: null,         // a case solved by the last investigation, drawn once
    confirmEnd: false,
    rewards: [],
    endRes: null,
    bookTab: 'verbs',
    glossTab: 'london'
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
  function stateOf(u) { return u.lit ? u.brightness : u.series > 0 ? 'tried' : 'never'; }
  function levelWord(u) { return u.lit ? LEVEL[u.brightness] : u.series > 0 ? 'en cours' : 'non ouverte'; }
  function nextLevel(best) {
    if (best === null || best === undefined || best < 6) return 'résolue, dès 6/12';
    if (best < 8) return 'résolue, dès 8/12';
    if (best < 10) return 'brillamment résolue, dès 10/12';
    if (best < 12) return 'élémentaire, à 12/12';
    return 'au plus haut : élémentaire';
  }
  function caseName(u) {
    var name = typo(u.label);
    if (u.lit) return name + ' — affaire ' + LEVEL[u.brightness] + ' (meilleure enquête ' + u.best + '/12)';
    if (u.series > 0 && u.best !== null) return name + ' — pas encore résolue (meilleure enquête ' + u.best + '/12)';
    return name + ' — dossier non ouvert';
  }
  function levelsLabel() {
    var c = { blazing: 0, bright: 0, clear: 0, discrete: 0 };
    G.progress.units.forEach(function (u) { if (u.lit && !u.hidden && c[u.brightness] !== undefined) c[u.brightness]++; });
    var parts = ['blazing', 'bright', 'clear', 'discrete'].filter(function (k) { return c[k]; }).map(function (k) { return c[k] + ' ' + PLURALS[k][c[k] > 1 ? 1 : 0]; });
    return parts.length ? parts.join(' · ') : 'aucune affaire résolue';
  }
  function leadsIn(chapter) {
    return (G.progress.comets || []).filter(function (c) { var u = unitOf(c.unit); return u && u.chapter === chapter; });
  }
  function isClosed(ch) { return ch.total > 0 && ch.lit >= ch.total; }

  /* The case Wiggins suggests: an unsolved case of the place furthest along (the one already nearest
     to 6), else the solved case with the lowest mention. */
  function suggestion() {
    var best = null;
    G.progress.chapters.forEach(function (ch) {
      var open = G.progress.units.filter(function (u) { return u.chapter === ch.code && !u.hidden && !u.lit; });
      if (!open.length) return;
      var share = ch.total ? ch.lit / ch.total : 0;
      if (!best || share > best.share) {
        var sorted = open.slice().sort(function (a, b) { return (b.best || 0) - (a.best || 0); });
        best = { unit: sorted[0], share: share };
      }
    });
    if (best) return { unit: best.unit, kind: 'solve' };
    var dim = G.progress.units.filter(function (u) { return !u.hidden && (u.best || 0) < 12; }).sort(function (a, b) { return (a.best || 0) - (b.best || 0); })[0];
    return dim ? { unit: dim, kind: 'better' } : null;
  }
  function caseLine(u) {
    var head = '<b>' + esc(typo(u.label)) + '</b>, ' + esc(chapterName(u)) + '. ';
    if (u.lit) return head + 'Affaire ' + LEVEL[u.brightness] + ' : ta meilleure enquête est de ' + u.best + '/12.';
    if (u.series > 0) return head + 'Pas encore résolue : ta meilleure enquête est de ' + u.best + '/12. Il en faut 6.';
    return head + 'Dossier jamais ouvert. Six indices sur douze le résolvent.';
  }
  /* Wiggins's line: his English in quotes, the French underneath; a French line alone is a rule. */
  function lineHtml(line) {
    if (Array.isArray(line)) return '<span class="en" lang="en">« ' + esc(line[0]) + ' »</span><span class="fr">' + esc(line[1]) + '</span>';
    return line;
  }

  /* ---------- the top bar ---------- */

  function topbar(sub, right) {
    return '<header class="topbar"><div class="topbar__title"><h1 class="topbar__name">The Irregulars</h1><span class="topbar__sub">' + esc(sub) + '</span></div>' +
      '<div class="topbar__right">' + right + E.sound.button() + '</div></header>';
  }
  function casesCount() {
    return '<span class="count" aria-label="' + G.progress.lit + ' affaires résolues sur ' + G.progress.total + '"><b>' + G.progress.lit + '</b>/' + G.progress.total + ' AFFAIRES</span>';
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

  /* ---------- 1. the main screen: Baker Street, the case board, Wiggins ---------- */

  function homeDialog() {
    var p = G.progress, read = G.data.read || [], lines, id = null;
    if (read.indexOf('prologue') < 0) {
      lines = PROLOGUE.slice(); id = 'prologue';
    } else if (p.finished && read.indexOf('gall') < 0) {
      lines = BEATS.all.slice(); id = 'gall';
    } else {
      [15, 10, 5].some(function (th) {
        if (p.lit >= th && read.indexOf('g' + th) < 0) { lines = BEATS[th].slice(); id = 'g' + th; return true; }
        return false;
      });
    }
    var sg = suggestion();
    if (!lines) {
      var stage = p.finished ? 4 : p.lit >= 15 ? 3 : p.lit >= 10 ? 2 : p.lit >= 5 ? 1 : 0;
      lines = [GREET[stage]];
      if (p.final.unlocked) {
        lines.push(p.final.best === 12 ? 'The Final Case conclu sans une faute. Tu peux y revenir pour le plaisir.' : 'The Final Case : douze témoignages de toutes les affaires, ' + minutes(p.final.timeLimitSeconds) + ' minutes, et la correction seulement à la fin.');
      } else if (sg) {
        lines.push((sg.kind === 'solve' ? 'L’affaire la plus proche d’être résolue : ' : 'Tout est résolu. L’affaire à reprendre : ') + caseLine(sg.unit));
      }
      if (p.comets.length) lines.push(['That’s a false lead, mate. Red thread on the board till we clear it.', 'Une fausse piste est épinglée au tableau : une erreur qui revient. Tu peux la lever maintenant, ou plus tard.']);
    }
    var acts = [];
    var open = G.data.open;
    if (open && open.session) acts.push({ label: open.mode === 'final' ? 'Reprendre The Final Case' : open.mode === 'comet' ? 'Reprendre la fausse piste' : 'Reprendre : ' + typo((unitOf(open.unit) || {}).label || 'l’enquête'), primary: true, act: 'resume' });
    if (p.final.unlocked) acts.push({ label: 'The Final Case', primary: !acts.length, act: 'final-intro' });
    else if (sg) acts.push({ label: 'Enquêter : ' + typo(sg.unit.label), primary: !acts.length, act: 'series', arg: sg.unit.code });
    if (p.comets.length) acts.push({ label: 'Lever la fausse piste', act: 'comet', arg: p.comets[0].misconception });
    acts.push({ label: 'Toutes les affaires', act: 'list' });
    acts.push({ label: 'Le carnet', act: 'casebook' });
    return { who: GUIDE, lines: lines, i: 0, id: id, actions: acts };
  }

  function dialogHtml() {
    var d = G.dlg, last = d.i >= d.lines.length - 1, acts = '';
    if (!last) acts = '<button type="button" class="btn btn--outline" data-act="dlg-next" data-autofocus>Suivant</button>';
    else acts = d.actions.map(function (a, k) {
      return '<button type="button" class="btn ' + (a.primary ? 'btn--primary' : 'btn--small') + '" data-act="' + a.act + '"' + (a.arg ? ' data-arg="' + esc(a.arg) + '"' : '') + (k === 0 ? ' data-autofocus' : '') + '>' + esc(a.label) + '</button>';
    }).join('');
    return '<div class="dlg"><div class="dlg__body"><div class="dlg__who"><b>' + esc(d.who) + '</b><span>' + (d.i + 1) + ' / ' + d.lines.length + '</span></div>' +
      '<p class="dlg__text">' + lineHtml(d.lines[d.i]) + '</p></div><div class="dlg__acts">' + acts + '</div></div>';
  }

  function placePanelHtml(ch) {
    var units = G.progress.units.filter(function (u) { return u.chapter === ch.code; });
    var leads = leadsIn(ch.code);
    var cards = units.map(function (u) {
      if (u.hidden) return '<li class="casecard is-hidden">' + Scene.file(null, 34) + '<b>' + esc(typo(u.label)) + '</b><span class="casecard__state">indisponible</span></li>';
      return '<li><button type="button" class="casecard' + (G.selected === u.code ? ' is-selected' : '') + '" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(caseName(u)) + '">' + Scene.file(stateOf(u), 34) +
        '<b>' + esc(typo(u.label)) + '</b><span class="casecard__state">' + esc(levelWord(u).toUpperCase()) + '<em>' + (u.best === null || u.best === undefined ? '—' : u.best + '/12') + '</em></span></button></li>';
    }).join('');
    return '<div class="placepanel"><div class="placepanel__head"><h2 class="placepanel__name" tabindex="-1">' + esc(ch.label) + '</h2><span class="kicker">' + esc(ch.topic.toUpperCase()) + '</span>' +
      '<span class="kicker">' + ch.lit + '/' + ch.total + ' RÉSOLUE' + (ch.lit > 1 ? 'S' : '') + (isClosed(ch) ? ' · LIEU BOUCLÉ' : '') + '</span>' +
      leads.map(function (c) { return '<button type="button" class="btn btn--lead" data-act="comet" data-arg="' + esc(c.misconception) + '">' + Scene.thread(20) + ' Fausse piste · Lever</button>'; }).join('') +
      '<button type="button" class="btn btn--small" data-act="deselect">Retour à Wiggins <span class="kbd">Échap</span></button></div>' +
      '<ul class="casecards">' + cards + '</ul></div>';
  }

  function caseCardHtml(u) {
    var ch = chapterOf(u.chapter);
    return '<div class="casefile">' + Scene.file(stateOf(u), 92) + '<div><div class="kicker">' + esc(ch ? ch.label.toUpperCase() : '') + '</div><h2 class="casefile__name" tabindex="-1">' + esc(typo(u.label)) + '</h2>' +
      '<dl class="facts"><dt>Mention</dt><dd>' + esc(levelWord(u)) + '</dd><dt>Meilleure enquête</dt><dd class="num">' + (u.best === null || u.best === undefined ? '—' : u.best + '/12') + '</dd>' +
      '<dt>Prochaine mention</dt><dd>' + esc(nextLevel(u.best)) + '</dd></dl></div>' +
      '<div class="casefile__acts"><button type="button" class="btn btn--primary" data-act="series" data-arg="' + esc(u.code) + '" data-autofocus>Enquêter : 12 témoignages <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn btn--small" data-act="place" data-arg="' + esc(u.chapter) + '">Retour au lieu <span class="kbd">Échap</span></button></div></div>';
  }

  /* The case board: the five places as columns of case files, the false leads pinned in red on
     their place, The Final Case at the foot - closed until every case is solved. */
  function boardHtml() {
    var p = G.progress;
    var cols = p.chapters.map(function (ch) {
      var closed = isClosed(ch);
      var files = p.units.filter(function (u) { return u.chapter === ch.code; }).map(function (u) {
        if (u.hidden) return '<li><span class="board__file is-hidden">' + Scene.file(null, 30) + '</span></li>';
        return '<li><button type="button" class="board__file' + (G.fresh === u.code ? ' is-fresh' : '') + (G.selected === u.code ? ' is-selected' : '') + '" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(caseName(u)) + '">' + Scene.file(stateOf(u), 30) + '</button></li>';
      }).join('');
      var leads = leadsIn(ch.code).map(function (c) {
        return '<button type="button" class="board__lead" data-act="comet" data-arg="' + esc(c.misconception) + '" aria-label="Fausse piste : ' + esc(typo(c.label)) + ' — la lever">' + Scene.thread(28) + '</button>';
      }).join('');
      return '<div class="board__col' + (closed ? ' is-closed' : '') + (G.place === ch.code ? ' is-selected' : '') + '">' +
        '<button type="button" class="board__head" data-act="place" data-arg="' + esc(ch.code) + '" aria-label="' + esc(ch.label + ' : ' + ch.lit + ' affaires résolues sur ' + ch.total + (closed ? ', lieu bouclé' : '')) + '"><span class="board__name">' + esc(ch.label) + '</span><span class="board__count">' + ch.lit + '/' + ch.total + '</span></button>' +
        '<ul class="board__files">' + files + '</ul>' + (leads ? '<div class="board__leads">' + leads + '</div>' : '') + '</div>';
    }).join('');
    var f = p.final;
    var final = f.unlocked
      ? '<button type="button" class="board__final is-open" data-act="final-intro" aria-label="The Final Case — l’épreuve' + (f.best !== null && f.best !== undefined ? ', meilleur score ' + f.best + '/12' : '') + '"><span class="board__final-name">The Final Case</span><span class="board__count">' + (f.best !== null && f.best !== undefined ? f.best + '/12' : minutes(f.timeLimitSeconds) + ' MIN') + '</span></button>'
      : '<div class="board__final" aria-label="The Final Case : ouvre quand toutes les affaires sont résolues"><span class="board__final-name">The Final Case</span><span class="board__count">TOUTES LES AFFAIRES D’ABORD</span></div>';
    G.fresh = null;
    return '<div class="board" role="group" aria-label="Le tableau des affaires : ' + p.lit + ' résolues sur ' + p.total + '"><div class="board__cols">' + cols + '</div>' + final + '</div>';
  }

  function renderHome(focusSel) {
    G.screen = 'home';
    G.confirmEnd = false;
    if (!G.dlg) G.dlg = homeDialog();
    var u = G.selected ? unitOf(G.selected) : null, ch = !u && G.place ? chapterOf(G.place) : null;
    var panel = u ? caseCardHtml(u) : ch ? placePanelHtml(ch) : dialogHtml();
    var html = topbar('ANGLAIS · 4E–3E', casesCount() + '<button type="button" class="btn btn--small" data-act="casebook">Le carnet</button>' + bestiaryButton() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="exit">Quitter</button>') +
      '<main class="home"><div class="scene-cell">' + Scene.scene(G.user.avatar) +
      '<div class="status"><span class="status__grade">' + esc(G.progress.grade) + '</span><span class="status__levels">' + esc(levelsLabel().toUpperCase()) + '</span></div></div>' +
      '<div class="board-cell">' + boardHtml() + '</div>' +
      '<section class="panel" aria-label="' + (u ? 'Affaire choisie' : ch ? 'Lieu choisi' : 'Wiggins') + '">' + panel + '</section></main>';
    show(html, focusSel);
    if (!u && !ch) markRead();
  }

  function markRead() {
    var d = G.dlg;
    if (!d || !d.id || d.i < d.lines.length - 1) return;
    var read = G.data.read || [];
    if (read.indexOf(d.id) >= 0) return;
    read.push(d.id);
    if (/^g\d+$/.test(d.id)) [5, 10, 15].forEach(function (th) { if (th < +d.id.slice(1) && read.indexOf('g' + th) < 0) read.push('g' + th); });
    if (d.id === 'gall') [5, 10, 15].forEach(function (th) { if (read.indexOf('g' + th) < 0) read.push('g' + th); });
    G.data.read = read;
    save();
  }

  /* ---------- 2. every case, place by place ---------- */

  function renderList() {
    G.screen = 'list';
    var p = G.progress;
    var cols = p.chapters.map(function (ch) {
      var items = p.units.filter(function (u) { return u.chapter === ch.code; }).map(function (u) {
        if (u.hidden) return '<li class="is-hidden">' + Scene.file(null, 26) + '<span>' + esc(typo(u.label)) + '</span><em>indisponible</em></li>';
        var state = u.lit ? LEVEL[u.brightness] + ' · ' + u.best + '/12' : u.series ? 'en cours · ' + u.best + '/12' : 'non ouverte';
        return '<li><button type="button" data-act="pick" data-arg="' + esc(u.code) + '" aria-label="' + esc(caseName(u)) + '">' + Scene.file(stateOf(u), 26) + '<span>' + esc(typo(u.label)) + '</span><em>' + esc(state) + '</em></button></li>';
      }).join('');
      return '<section aria-labelledby="ch-' + esc(ch.code) + '"><h2 id="ch-' + esc(ch.code) + '">' + esc(ch.label) + ' <small>' + ch.lit + '/' + ch.total + '</small></h2><p class="kicker">' + esc(ch.topic) + '</p><ul>' + items + '</ul></section>';
    }).join('');
    var leads = p.comets.length ? '<section aria-labelledby="ch-leads"><h2 id="ch-leads">Fausses pistes à lever <small>' + p.comets.length + '</small></h2><ul>' + p.comets.map(function (c) {
      return '<li><button type="button" data-act="comet" data-arg="' + esc(c.misconception) + '">' + Scene.thread(24) + '<span>' + esc(typo(c.label)) + '</span><em>' + esc(typo(c.unitName)) + '</em></button></li>';
    }).join('') + '</ul></section>' : '';
    var fin = '<section aria-labelledby="ch-final"><h2 id="ch-final">The Final Case <small>' + (p.final.best !== null && p.final.best !== undefined ? p.final.best + '/12' : '—') + '</small></h2><ul><li>' +
      (p.final.unlocked ? '<button type="button" data-act="final-intro">' + Scene.file('blazing', 26) + '<span>L’épreuve</span><em>' + minutes(p.final.timeLimitSeconds) + ' min</em></button>' : '<span class="is-hidden">Ouvre quand toutes les affaires sont résolues.</span>') + '</li></ul></section>';
    show(topbar('Toutes les affaires', casesCount() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="home">Retour à Baker Street <span class="kbd">Échap</span></button>') +
      '<main class="screen" tabindex="-1"><h2 class="big-title" tabindex="-1" data-autofocus>Toutes les affaires</h2>' +
      '<p class="kicker">Enquêtes illimitées · 12 témoignages · sans chronomètre</p><div class="list">' + cols + leads + fin + '</div>' +
      '<p style="margin-top:22px">' + bestiaryButton() + '</p></main>');
  }

  /* ---------- 3. a run: an investigation, a false lead or the final case ---------- */

  function freshRun(extra) {
    return Object.assign({ results: [], draft: '', clues: 0, threads: 0 }, extra);
  }

  function startSeries(unit) {
    if (G.busy) return;
    var u = unitOf(unit);
    if (!u || u.hidden) { toast(ERRORS['unit-unavailable']); return; }
    G.busy = true;
    call('startSeries', { unit: unit }).then(function (r) {
      G.busy = false;
      G.run = freshRun({ mode: 'series', session: r.session, unit: unit, size: r.size || 12, before: { best: u.best, brightness: u.brightness, lit: u.lit } });
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
      G.run = freshRun({ mode: 'comet', session: r.session, unit: r.comet && r.comet.unit, misconception: misconception, comet: r.comet, explanation: r.explanation, size: r.size || 3 });
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
      G.run = freshRun({ mode: 'final', session: r.session, size: r.size || 12, limit: limit, localDeadline: Date.now() + limit * 1000, beforeBest: G.progress.final.best });
      G.data.open = { mode: 'final', session: r.session, deadline: r.deadline, limit: limit };
      save();
      nextQuestion();
    }, fail);
  }

  function resume() {
    var o = G.data.open;
    if (!o || !o.session) return;
    G.run = freshRun({ mode: o.mode, session: o.session, unit: o.unit, misconception: o.misconception, comet: o.mode === 'comet' ? { label: o.label, unit: o.unit } : null, size: o.mode === 'comet' ? 3 : 12, resumed: true });
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
      run.spot = null;
      run.placed = [];
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
    if (run.mode === 'final') return 'The Final Case' + (run.q ? ' · ' + chapterName(unitOf(run.q.unit)) : '');
    var u = unitOf(run.unit || (run.q && run.q.unit));
    if (run.mode === 'comet') return 'Fausse piste · ' + typo(run.comet && run.comet.label ? run.comet.label : u ? u.label : '');
    return (u ? chapterName(u) + ' · ' + typo(u.label) : '');
  }

  function progressHtml(run) {
    var items = [];
    for (var k = 0; k < run.size; k++) {
      var r = run.results[k];
      if (run.mode === 'final') items.push('<li class="' + (k === run.position ? 'cur' : r ? 'done' : '') + '"></li>');
      else items.push('<li class="' + (k === run.position && !run.correction ? 'cur' : r === 'ok' ? 'ok' : r === 'ko' || r === 'dk' ? 'ko' : r ? 'done' : '') + '"></li>');
    }
    var label = run.mode === 'final' ? 'Témoignages faits : ' + run.results.filter(Boolean).length + ' sur ' + run.size : 'Avancement de l’enquête';
    return '<ol class="progress" aria-label="' + esc(label) + '">' + items.join('') + '</ol>';
  }

  /* The index the correction expects for a word to point at: its text is the place sent (a number)
     or « correct ». */
  function expectedSpot(c) {
    if (!c) return null;
    var t = String(c.expectedText || '').trim();
    return t === 'correct' ? 'correct' : /^\d+$/.test(t) ? +t : null;
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
          '<span class="choice__key">' + 'ABCDEF'.charAt(i) + '</span><span class="choice__text" lang="en">' + o.html + '</span></button>';
      }).join('') + '</div>';
    }
    if (type === 'spot') {
      var want = done ? expectedSpot(c) : null, sent = done ? (c.dontKnow ? null : (String(c.input || '') === 'correct' ? 'correct' : /^\d+$/.test(String(c.input || '')) ? +c.input : null)) : run.spot;
      var tokens = (q.labels || []).map(function (word, i) {
        var cls = 'token', chosen = sent === i;
        if (done) {
          if (want === i) cls += ' is-right';
          else if (chosen) cls += ' is-wrong';
        } else if (chosen) cls += ' is-chosen';
        return '<button type="button" class="' + cls + '" role="radio" aria-checked="' + chosen + '" data-act="spot" data-arg="' + i + '"' + (done ? ' disabled' : '') + (i === 0 ? ' data-first' : '') + ' lang="en">' + esc(word) + '</button>';
      }).join(' ');
      var okCls = 'btn token-ok', okChosen = sent === 'correct';
      if (done) { if (want === 'correct') okCls += ' is-right'; else if (okChosen) okCls += ' is-wrong'; } else if (okChosen) okCls += ' is-chosen';
      return '<div class="spot"><div class="tokens" role="radiogroup" aria-label="Les mots de la phrase : clique sur le mot faux">' + tokens + '</div>' +
        '<button type="button" class="' + okCls + '" role="radio" aria-checked="' + okChosen + '" data-act="spot" data-arg="correct"' + (done ? ' disabled' : '') + '>La phrase est correcte</button></div>';
    }
    if (type === 'order') {
      var choices = q.choices || [];
      if (done) {
        var given = c.dontKnow ? [] : String(c.input || '').split('|');
        var cells = c.cells || [];
        return '<div class="order is-done"><ol class="slots" aria-label="Ta question">' + (given.length ? given : choices.map(function () { return ''; })).map(function (w, i) {
          return '<li class="slot is-filled' + (cells[i] ? ' is-ok' : ' is-ko') + '" lang="en">' + (w ? esc(w) : '·') + '</li>';
        }).join('') + '</ol>' +
          (!c.ok && c.expectedCells ? '<div class="order__expected"><span>Ordre attendu</span><b lang="en">' + esc(c.expectedCells.join(' ')) + '</b></div>' : '') + '</div>';
      }
      var placed = run.placed || [];
      var slots = choices.map(function (o, i) {
        var idx = placed[i];
        if (idx === undefined) return '<li class="slot" aria-label="Case ' + (i + 1) + ' vide"></li>';
        return '<li><button type="button" class="slot is-filled" data-act="unplace" data-arg="' + i + '" aria-label="Retirer « ' + esc(plain(choices[idx].html)) + ' », place ' + (i + 1) + '" lang="en">' + choices[idx].html + '</button></li>';
      }).join('');
      var pool = choices.map(function (o, i) {
        var used = placed.indexOf(i) >= 0;
        return '<button type="button" class="word' + (used ? ' is-used' : '') + '" data-act="place-word" data-arg="' + i + '"' + (used ? ' disabled aria-hidden="true" tabindex="-1"' : '') + (i === firstFree(choices, placed) ? ' data-first' : '') + ' lang="en">' + o.html + '</button>';
      }).join('');
      return '<div class="order"><ol class="slots" aria-label="Ta question, ' + placed.length + ' mot' + (placed.length > 1 ? 's' : '') + ' sur ' + choices.length + '">' + slots + '</ol>' +
        '<div class="pool" role="group" aria-label="Les mots à ranger">' + pool + '</div>' +
        (placed.length ? '<button type="button" class="btn btn--small" data-act="order-clear">Tout retirer</button>' : '') + '</div>';
    }
    var field = '<div class="field' + (done ? (c.ok ? ' is-ok' : ' is-ko') : '') + '"><input id="answer" type="text" inputmode="text" lang="en" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" aria-describedby="typing-help" aria-label="Ta réponse" placeholder="' + (done ? '' : 'Your answer') + '"' +
      (done ? ' readonly value="' + esc(c.dontKnow ? '—' : (c.input || '')) + '"' : ' value="' + esc(run.typed || '') + '"') + '></div>';
    return field;
  }
  function firstFree(choices, placed) {
    for (var i = 0; i < choices.length; i++) if (placed.indexOf(i) < 0) return i;
    return -1;
  }

  /* The corkboard beside an investigation: a clue pinned per right statement, a red thread per
     error, lifted by the next right statement. A display of this run only. */
  function boardAside(run) {
    if (run.mode !== 'series') return '';
    return '<aside class="cork" aria-label="Le tableau de l’enquête"><div class="cork__head"><span class="kicker">LE TABLEAU</span><span class="cork__counts"><b>' + run.clues + '</b> indice' + (run.clues > 1 ? 's' : '') + ' · <b class="is-red">' + run.threads + '</b> fil' + (run.threads > 1 ? 's' : '') + ' rouge' + (run.threads > 1 ? 's' : '') + '</span></div>' +
      Scene.board(run.clues, run.threads) +
      '<p class="cork__note">' + (run.clues >= 6 ? 'Six indices : l’affaire est résolue. Les suivants améliorent sa mention.' : 'Encore ' + (6 - run.clues) + ' indice' + (6 - run.clues > 1 ? 's' : '') + ' pour résoudre l’affaire.') + '</p></aside>';
  }

  function renderRun() {
    G.screen = 'run';
    var run = G.run, q = run.q, done = !!run.correction, c = run.correction;
    var help = TYPING[q.answerType] || TYPING.text;
    var right = '<button type="button" class="btn btn--small" data-act="draft" aria-expanded="' + !!run.draftOpen + '" aria-controls="draft">' + (run.draftOpen ? 'Fermer le brouillon' : 'Brouillon') + '</button>' +
      (run.mode === 'final'
        ? '<button type="button" class="btn btn--small" data-act="final-end">' + (G.confirmEnd ? 'Confirmer : terminer maintenant' : 'Terminer l’épreuve') + '</button>'
        : '<button type="button" class="btn btn--small" data-act="leave">' + (run.mode === 'comet' ? 'Retour à Baker Street' : 'Quitter l’enquête') + '</button>');
    right = glossaryButton() + right;
    var sub = run.mode === 'final' ? 'The Final Case' : run.mode === 'comet' ? 'Lever la fausse piste' : 'Enquête de 12';

    var chip = done ? (c.dontKnow ? '<span class="chip chip--dk">○ Sans réponse</span>' : c.ok ? '<span class="chip chip--ok">✓ Indice</span>' : '<span class="chip chip--ko">✗ Fausse piste</span>') : '';
    var body = '<div class="qmain"><div class="witness"><span class="kicker">TÉMOIGNAGE</span><h2 class="prompt" id="prompt" tabindex="-1" lang="en">' + q.prompt + '</h2></div>' +
      (q.figure ? '<div class="figure">' + q.figure + '</div>' : '') +
      '<div class="answer-row">' + answerAreaHtml(run, q, done, c) + chip + '</div>';
    if (!done) {
      body += '<div class="typing-help" id="typing-help">' + esc(help) + '</div>';
      if (run.notice) body += '<div class="notice' + (run.notice.warn ? ' notice--warn' : '') + '" role="alert">' + run.notice.html + '</div>';
      body += '<div class="qacts"><button type="button" class="btn" data-act="dont-know">Je ne sais pas</button>' +
        '<button type="button" class="btn btn--primary" data-act="submit">' + (run.mode === 'final' ? 'Témoignage suivant' : 'Valider') + ' <span class="kbd">Entrée</span></button></div>';
    } else body += '<p id="typing-help" hidden></p>';
    body += '</div>';

    var draft = '';
    if (run.draftOpen) {
      draft = '<aside class="draft" id="draft"><div class="draft__head"><label for="draft-text">Brouillon</label><button type="button" class="btn btn--small" data-act="draft-clear">Effacer</button></div>' +
        '<textarea id="draft-text" spellcheck="false" aria-describedby="draft-note"></textarea><span class="draft__note" id="draft-note">Une page de carnet libre : jamais enregistrée ni envoyée, effacée à la fin ' + (run.mode === 'final' ? 'de l’épreuve' : run.mode === 'comet' ? 'de la fausse piste' : 'de l’enquête') + '.</span></aside>';
    }

    var correction = '';
    if (done) {
      var last = run.position + 1 >= run.size;
      var title = c.dontKnow ? 'La règle' : c.ok ? pick(['That’s a clue. Pin it on the board.', 'Spot on.', 'Good. The witness likes you now.'], run.position) : pick(['That’s a false lead, mate.', 'Nope. Red thread.'], run.position);
      correction = '<section class="correction" aria-labelledby="corr-title"><h3 class="correction__title' + (c.ok ? '' : ' is-ko') + '" id="corr-title"' + (c.dontKnow ? '' : ' lang="en"') + '>' + esc(title) + '</h3>' +
        (!c.ok && !c.dontKnow && c.misconceptionLabel ? '<div class="correction__error">' + esc(typo(c.misconceptionLabel)) + '</div>' : '') +
        (c.ok || q.answerType === 'order' ? '' : '<div class="expected"><span>' + (q.answerType === 'spot' ? 'Ce qu’il fallait voir' : 'Réponse attendue') + '</span><b lang="en">' + (c.expected || esc(c.expectedText || '')) + '</b></div>') +
        '<div class="kicker">Règle</div><p class="method">' + (c.method || '') + '</p>' +
        '<div class="correction__acts"><button type="button" class="btn btn--primary" data-act="next" data-autofocus>' + (last ? 'Voir le bilan' : 'Témoignage suivant') + ' <span class="kbd">Entrée</span></button></div></section>';
    }

    var focus = done ? '[data-act="next"]' : (q.answerType === 'text' || !q.answerType ? '#answer' : '[data-first]');
    if (!done && q.answerType === 'order' && run.lastFocus) focus = run.lastFocus;
    show(topbar(sub, right) +
      '<main class="screen run"><div class="sheet sheet--' + esc(q.answerType || 'text') + '"><div class="qhead"><span class="qhead__title">' + esc(runTitle(run)) + '</span>' + streakHtml(run) + '<span class="qhead__num">' + 'Témoignage ' + (run.position + 1) + '/' + run.size + progressHtml(run) + '</span></div>' +
      gaugeHtml(run) + body + correction + '</div>' + boardAside(run) + draft + '</main>', focus);

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
    if (q.answerType === 'spot') return run.spot === null || run.spot === undefined ? '' : String(run.spot);
    if (q.answerType === 'order') {
      var choices = q.choices || [];
      if ((run.placed || []).length < choices.length) return null;
      return run.placed.map(function (i) { return choices[i].value; }).join('|');
    }
    var inp = document.getElementById('answer');
    return (inp ? inp.value : '').trim();
  }

  function emptyNotice(type) {
    if (type === 'choice') return 'Choisis une proposition, ou « Je ne sais pas ».';
    if (type === 'spot') return 'Clique sur le mot faux ou sur « La phrase est correcte », ou choisis « Je ne sais pas ».';
    if (type === 'order') return 'Range tous les mots, ou choisis « Je ne sais pas ».';
    return 'Écris une réponse, ou choisis « Je ne sais pas ».';
  }

  /* The gauge of an investigation or a false lead, from the corrections already given - never
     during The Final Case, whose answers are not corrected before its end. */
  function gaugeHtml(run) {
    if (run.mode === 'final') return '';
    return E.gauge({
      mode: run.mode, results: run.results, size: run.size,
      best: run.before ? run.before.best : null,
      solvedAt: 2, solve: 'la lever', levels: GAUGE_LEVELS, record: 'ta meilleure enquête'
    }).html;
  }
  function streakHtml(run) {
    if (run.mode === 'final' || !run.correction) return '';
    var n = E.streak(run.results);
    return n >= 3 ? '<span class="sg-streak' + (n % 4 === 0 ? ' is-hot' : '') + '">' + n + ' indices d’affilée</span>' : '';
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
      run.notice = { html: emptyNotice(run.q.answerType), warn: true };
      renderRun();
      return;
    }
    G.busy = true;
    call('answer', { session: run.session, input: input }).then(function (r) {
      G.busy = false;
      if (!r.recorded) {
        var help = TYPING[run.q.answerType] || TYPING.text;
        run.notice = r.unreadable
          ? { html: 'Wiggins n’arrive pas à lire cette réponse : ' + esc(lowerFirst(help)) + ' Rien n’est compté.', warn: true }
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
      if (r.ok) { run.clues++; if (run.threads > 0) run.threads--; } else if (!r.dontKnow) run.threads++;
      run.correction = r;
      correctionSound(run, r);
      renderRun();
      announce(r.dontKnow ? 'Noté « je ne sais pas ». La règle est affichée.' : r.ok ? 'Indice trouvé.' : 'Fausse piste.' + (r.misconceptionLabel ? ' Erreur repérée : ' + lowerFirst(typo(r.misconceptionLabel)) + '.' : '') + ' Réponse attendue : ' + plain(r.expected || r.expectedText));
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

  /* ---------- the final case's clock: the platform shows it; this one only closes the session ---------- */

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
      out.push(r === 'ok' ? '<li class="ok" aria-label="Témoignage ' + (k + 1) + ' : indice">✓</li>' : r === 'dk' ? '<li aria-label="Témoignage ' + (k + 1) + ' : je ne sais pas">○</li>' : r === 'ko' ? '<li class="ko" aria-label="Témoignage ' + (k + 1) + ' : fausse piste">✗</li>' : '<li aria-label="Témoignage ' + (k + 1) + '">·</li>');
    }
    return '<ul class="boxes">' + out.join('') + '</ul>';
  }

  function leadsRaisedHtml(list) {
    if (!list || !list.length) return '';
    return list.map(function (c) {
      return '<div class="notice notice--lead">' + Scene.thread(34) + '<span><b>Une fausse piste s’épingle au tableau</b> : « ' + esc(typo(c.label)) + ' ». La même erreur est revenue deux fois ; tu peux la lever maintenant, ou plus tard.</span>' +
        '<button type="button" class="btn btn--small" data-act="comet" data-arg="' + esc(c.misconception) + '">Lever la fausse piste</button></div>';
    }).join('');
  }

  function renderSeriesEnd() {
    G.screen = 'seriesEnd';
    var r = G.endRes, run = G.run, u = unitOf(r.unit) || { label: r.unit };
    var before = run.before || {};
    var lit = !!r.brightness;
    var record = before.best !== null && before.best !== undefined && r.score > before.best && !r.newlyLit && !r.brighter;
    var note = r.score === 12 ? ['Twelve out of twelve. Elementary.', 'Douze sur douze. Élémentaire : le patron n’aurait pas mieux fait.'] : r.newlyLit ? pick(AFTER_SOLVED, r.score + run.session) : r.brighter ? 'L’affaire « ' + typo(u.label) + ' » gagne une meilleure mention. Une enquête moins réussie ne la lui retirera pas.' : pick(AFTER_ERROR, r.score + run.session);
    var levelRow = lit
      ? (r.brighter && before.brightness ? '<span class="was">' + Scene.file(before.brightness, 22) + ' ' + esc(LEVEL[before.brightness]) + '</span> <span aria-label="devient">→</span> ' : '') + Scene.file(r.brightness, 24) + ' ' + esc(LEVEL[r.brightness])
      : 'pas encore résolue — il faut 6 indices';
    show(topbar('Enquête de 12', casesCount()) +
      '<main class="screen"><div class="sheet sheet--end"><div class="qhead"><span class="qhead__title">' + esc(chapterName(u) + ' · ' + typo(u.label)) + '</span></div>' +
      '<div class="result"><div class="score" aria-label="' + r.score + ' indices sur ' + r.size + '"><b>' + r.score + '</b><span>/' + r.size + '</span></div>' +
      '<div class="result__file">' + (r.newlyLit ? '<span class="badge-new">Nouvelle affaire résolue</span>' : r.brighter ? '<span class="badge-new">Meilleure mention</span>' : record ? '<span class="badge-new">Nouveau record</span>' : '') +
      '<div class="result__arrow">' + (r.newlyLit ? Scene.file(before.series ? 'tried' : 'never', 70) + '<span aria-hidden="true">→</span>' : '') + Scene.file(lit ? r.brightness : 'tried', 110) + '</div>' +
      '<span class="kicker">Prochaine mention : ' + esc(nextLevel(r.best)) + '</span></div></div>' +
      boxesHtml(run, r.size) +
      '<dl class="rows"><div><dt>Mention</dt><dd>' + levelRow + '</dd></div><div><dt>Meilleure enquête</dt><dd class="num">' + (r.best === null || r.best === undefined ? '—' : r.best + '/12') + '</dd></div></dl>' +
      leadsRaisedHtml(r.comets) +
      '<p class="guide"><b>' + esc(GUIDE) + '</b> — ' + lineHtml(note) + '</p>' +
      '<div class="qacts">' + againHtml(r) + '</div></div></main>');
    announce('Fin de l’enquête : ' + r.score + ' indices sur ' + r.size + '.' + (r.newlyLit ? ' Nouvelle affaire résolue !' : r.brighter ? ' Meilleure mention.' : record ? ' Nouveau record.' : ''));
    celebrate(r.newlyLit || r.brighter ? 'level' : record ? 'record' : 'end', '.result__arrow svg:last-child');
  }

  /* « Encore une » in one key: the same case again unless it is at its highest, then the case
     nearest to its next mention - a fact read on the board, never a recommendation. */
  function againHtml(r) {
    var u = unitOf(r.unit), maxed = u && u.best >= 12, near = E.closest(G.progress.units, r.unit), acts = [];
    if (!maxed) acts.push('<button type="button" class="btn btn--primary" data-act="series" data-arg="' + esc(r.unit) + '" data-autofocus>Encore une enquête <span class="kbd">Entrée</span></button>');
    if (near) acts.push('<button type="button" class="btn sg-next__near' + (maxed ? ' btn--primary' : '') + '" data-act="series" data-arg="' + esc(near.unit.code) + '"' + (maxed ? ' data-autofocus' : '') + '>Enquêter : ' + esc(typo(near.unit.label)) +
      '<span class="sg-next__hint">' + esc(nearHint(near)) + '</span></button>');
    acts.push('<button type="button" class="btn btn--small" data-act="home"' + (acts.length ? '' : ' data-autofocus') + '>Retour à Baker Street <span class="kbd">Échap</span></button>');
    return '<div class="sg-next">' + acts.join('') + '</div>';
  }
  function nearHint(near) {
    var b = near.unit.best;
    return (b === null || b === undefined ? 'dossier non ouvert' : 'meilleure enquête ' + b + '/12') + ' · ' + nextLevel(b);
  }
  function celebrate(cue, selector) {
    E.sound.play(cue);
    if (cue === 'level' || cue === 'record' || cue === 'solved') E.burst(app.querySelector(selector), cue === 'level');
  }

  function renderCometIntro() {
    G.screen = 'cometIntro';
    var run = G.run, c = run.comet || {}, u = unitOf(c.unit || run.unit);
    show(topbar('Lever la fausse piste', casesCount() + glossaryButton() + '<button type="button" class="btn btn--small" data-act="leave">Retour à Baker Street</button>') +
      '<main class="screen"><div class="lead-study"><div class="lead-study__art">' + Scene.leadBoard() + '</div><div>' +
      '<span class="kicker">FAUSSE PISTE · ' + esc((u ? chapterName(u) + ' · ' + typo(u.label) : typo(c.unitName || '')).toUpperCase()) + '</span>' +
      '<h2 class="big-title" tabindex="-1">' + esc(typo(c.label || 'Une fausse piste')) + '</h2>' +
      '<p class="explanation">' + esc(typo(run.explanation || '')) + '</p>' +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="comet-go" data-autofocus>Lever : 3 témoignages <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn btn--small" data-act="leave">Plus tard</button></div><p class="kicker">2 justes sur 3 pour la lever</p></div></div></main>');
  }

  function renderCometEnd() {
    G.screen = 'cometEnd';
    var r = G.endRes, run = G.run, c = run.comet || {};
    show(topbar('Lever la fausse piste', casesCount()) +
      '<main class="screen"><div class="lead-study"><div class="lead-study__art' + (r.solved ? ' is-cleared' : '') + '">' + Scene.leadBoard() + '</div><div><span class="kicker">FIN DE LA VÉRIFICATION · ' + esc(typo(c.label || '').toUpperCase()) + '</span>' +
      '<h2 class="big-title" tabindex="-1">' + (r.solved ? 'Fausse piste levée.' : 'Elle reste à lever.') + '</h2>' +
      boxesHtml(run, r.size) +
      (r.solved ? '<div class="sg-bestiary-new">' + E.bestiaryArt('irregulars', run.misconception, 110) + '<span class="kicker">Une fiche de plus au ' + esc(lowerFirst(BESTIARY)) + '</span></div>' : '') +
      '<p class="explanation">' + (r.solved
        ? 'Le fil rouge est décroché : ' + r.score + ' justes sur ' + r.size + '. Il ne reviendra que si l’erreur revient.'
        : r.score + ' juste' + (r.score > 1 ? 's' : '') + ' sur ' + r.size + '. Rien n’est perdu : le fil reste épinglé au tableau, et deux justes sur trois suffisent pour le lever.') + '</p>' +
      leadsRaisedHtml(r.comets) +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="home" data-autofocus>Retour à Baker Street <span class="kbd">Entrée</span></button>' +
      (r.solved ? '<button type="button" class="btn" data-act="bestiary">Ouvrir le ' + esc(lowerFirst(BESTIARY)) + '</button>' : '<button type="button" class="btn" data-act="comet" data-arg="' + esc(run.misconception) + '">Réessayer</button>') + '</div></div></div></main>');
    announce(r.solved ? 'Fausse piste levée. Elle rejoint le dossier des fausses pistes.' : 'La fausse piste reste à lever.');
    celebrate(r.solved ? 'solved' : 'end', '.sg-bestiary-new svg');
  }

  function renderFinalIntro() {
    G.screen = 'finalIntro';
    var f = G.progress.final, extra = G.user.extraTimePercent || 0;
    if (!f.unlocked) { toast(ERRORS['final-locked']); return; }
    var mins = minutes(f.timeLimitSeconds);
    var held = (G.progress.rewards || []).filter(function (rw) { return rw.kind === 'trophy'; })[0];
    show(topbar('L’épreuve', casesCount() + '<button type="button" class="btn btn--small" data-act="home">Retour à Baker Street <span class="kbd">Échap</span></button>') +
      '<main class="screen"><div class="final-intro"><div class="final-intro__art">' + Scene.armchair({ flame: f.best !== null && f.best !== undefined }) + '</div><div>' +
      '<span class="kicker">LA DERNIÈRE AFFAIRE</span><h2 class="big-title" tabindex="-1">' + esc(f.label || 'The Final Case') + '</h2>' +
      '<blockquote class="guide-quote"><b>Wiggins</b><span lang="en">« The guv’nor’s away tonight. Twelve statements, every case we’ve had. We conclude alone, and nobody checks till the end. »</span><small>« Le patron est absent ce soir. Douze témoignages, de toutes nos affaires. On conclut seuls, et personne ne vérifie avant la fin. »</small></blockquote>' +
      '<dl class="final-facts"><div><dt>Témoignages</dt><dd>' + f.size + ', toutes les affaires</dd></div><div><dt>Durée</dt><dd>' + mins + ' min' + (extra > 0 ? ' · temps majoré (+' + extra + ' %)' : '') + '</dd></div><div><dt>Correction</dt><dd>à la fin · rejouable</dd></div>' +
      '<div><dt>Meilleur score</dt><dd>' + (f.best !== null && f.best !== undefined ? f.best + '/' + f.size : '—') + '</dd></div></dl>' +
      '<div class="loupe-row">' + ['brass', 'silver', 'gold'].map(function (fin, i) {
        var art = window.SGRewards ? window.SGRewards.art('trophy', 'loupe', { size: 56, finish: fin, locked: !held || (i === 1 && held.finish === 'brass') || (i === 2 && held.finish !== 'gold') }) : '';
        return '<figure>' + art + '<figcaption>' + ['LAITON', '10/12', '12/12'][i] + '</figcaption></figure>';
      }).join('') + '</div>' +
      '<p class="method">« Je ne sais pas » reste possible à chaque témoignage. Le temps restant s’affiche dans la barre de Formatool ; un témoignage laissé sans réponse quand il s’écoule compte faux. Les affaires résolues le restent quoi qu’il arrive.</p>' +
      '<div class="qacts"><button type="button" class="btn btn--primary" data-act="final-go" data-autofocus>Commencer l’épreuve <span class="kbd">Entrée</span></button>' +
      '<button type="button" class="btn" data-act="home">Retour à Baker Street</button></div></div></div></main>');
  }

  function reviewGiven(it) {
    if (it.dontKnow) return 'je ne sais pas';
    if (!it.answered) return 'sans réponse';
    var input = String(it.input || '');
    if (it.answerType === 'spot') {
      if (input === 'correct') return 'phrase jugée correcte';
      var word = (it.labels || [])[+input];
      return word ? 'mot désigné : ' + word : input;
    }
    if (it.answerType === 'order') return input.split('|').join(' ');
    return input;
  }

  function renderReview() {
    G.screen = 'review';
    var r = G.endRes, items = (r.review || []).map(function (it, k) {
      var chip = it.ok ? '<span class="chip chip--ok">✓</span>' : it.answered && !it.dontKnow ? '<span class="chip chip--ko">✗</span>' : '<span class="chip chip--dk">○</span>';
      var expected = it.answerType === 'order' && it.expectedCells ? esc(it.expectedCells.join(' ')) : (it.expected || '');
      return '<li><span class="review__n">' + String(k + 1).padStart(2, '0') + '</span><div><div class="review__unit">' + esc(typo(it.unitName)) + '</div>' +
        '<div class="review__prompt" lang="en">' + it.prompt + '</div>' +
        (it.figure ? '<div class="figure">' + it.figure + '</div>' : '') +
        '<div class="review__given"><span>Ta réponse</span><span lang="en">' + esc(reviewGiven(it)) + '</span></div>' +
        (!it.ok && it.misconceptionLabel ? '<div class="correction__error">' + esc(typo(it.misconceptionLabel)) + '</div>' : '') +
        '<details' + (it.ok ? '' : ' open') + '><summary>' + (it.ok ? 'Voir la règle' : 'La règle') + '</summary><div class="expected"><span>Réponse attendue</span><b lang="en">' + expected + '</b></div><p class="method">' + (it.method || '') + '</p></details></div>' + chip + '</li>';
    }).join('');
    var rewards = (G.progress.rewards || []).filter(function (rw) { return rw.kind === 'trophy' || rw.code === 'card-irregulars-final-case'; });
    var note = r.score >= 12 ? ['Elementary, mate. Twelve out of twelve.', 'Élémentaire. La loupe est en or.'] : r.score >= 10 ? r.score + ' témoignages justes : la loupe passe à l’argent. Les affaires résolues le restent toutes.' : r.score + ' témoignage' + (r.score > 1 ? 's' : '') + ' juste' + (r.score > 1 ? 's' : '') + '. L’épreuve ne retire rien : les affaires résolues le restent.';
    show(topbar('L’épreuve', casesCount()) +
      '<main class="screen"><div class="review-wrap"><div class="review-side"><span class="kicker">BILAN DE THE FINAL CASE</span><div class="score"><b>' + r.score + '</b><span>/' + r.size + '</span></div>' +
      '<p class="kicker">Meilleur score · ' + (r.best !== null && r.best !== undefined ? r.best : r.score) + '/' + r.size + '</p>' +
      (r.timedOut ? '<div class="notice notice--warn">' + (G.run && G.run.endedByPlayer ? 'Épreuve terminée avant le dernier témoignage' : 'Le temps s’est écoulé') + ' : les témoignages restés sans réponse comptent faux.</div>' : '') +
      (rewards.length && window.SGRewards ? '<div class="review-pieces">' + rewards.map(function (rw) { return '<figure>' + window.SGRewards.art(rw.kind, rw.code, { size: rw.kind === 'card' ? 90 : 110, finish: rw.finish || 'brass' }) + '<figcaption>' + esc(typo(rw.name)) + (rw.kind === 'trophy' ? ' · ' + ({ brass: 'laiton', silver: 'argent', gold: 'or' }[rw.finish] || 'laiton') : '') + '</figcaption></figure>'; }).join('') + '</div>' : '') +
      leadsRaisedHtml(r.comets) +
      '<p class="guide"><b>' + esc(GUIDE) + '</b> — ' + lineHtml(note) + '</p>' +
      '<div class="qacts"><button type="button" class="btn" data-act="final-intro">Rejouer The Final Case</button><button type="button" class="btn btn--primary" data-act="home" data-autofocus>Retour à Baker Street</button></div></div>' +
      '<ol class="review" aria-label="Correction, témoignage par témoignage">' + items + '</ol></div></main>');
    announce('Épreuve terminée : ' + r.score + ' sur ' + r.size + '.');
    var was = G.run ? G.run.beforeBest : null;
    celebrate(was !== null && was !== undefined && r.score > was ? 'record' : 'end', '.review-side .score');
  }

  /* ---------- 4. the casebook: the irregular verbs and the memos, outside any investigation ---------- */

  function casebookBody(tab) {
    if (tab === 'verbs') {
      return '<p class="lib-intro">La liste des verbes irréguliers que les témoins emploient : la base, le prétérit (deuxième colonne), le participe passé (troisième colonne). Orthographe britannique, comme à Londres en 1895.</p>' +
        '<article class="libcard libcard--wide"><table><thead><tr><th scope="col">Base</th><th scope="col">Prétérit</th><th scope="col">Participe passé</th><th scope="col">Sens</th></tr></thead><tbody>' +
        BOOK.verbs.map(function (v) { return '<tr><th scope="row" lang="en">' + esc(v[0]) + '</th><td lang="en">' + esc(v[1]) + '</td><td lang="en">' + esc(v[2]) + '</td><td>' + esc(v[3]) + '</td></tr>'; }).join('') + '</tbody></table></article>';
    }
    var memo = BOOK.memos.filter(function (m) { return m[0] === tab; })[0];
    if (!memo) return '';
    return '<article class="libcard libcard--wide"><h3>' + esc(memo[1]) + '</h3><ul class="libcard__list">' + memo[2].map(function (p) { return '<li>' + esc(p) + '</li>'; }).join('') + '</ul>' +
      '<p class="kicker">Exemples</p><ul class="libcard__examples">' + memo[3].map(function (e) { return '<li lang="en">' + esc(e) + '</li>'; }).join('') + '</ul></article>';
  }
  function renderCasebook(tab) {
    G.screen = 'casebook';
    G.bookTab = tab || G.bookTab;
    var tabs = [['verbs', 'Verbes irréguliers']].concat(BOOK.memos.map(function (m) {
      var ch = chapterOf(m[0]);
      return [m[0], (ch ? ch.label + ' · ' : '') + m[1]];
    }));
    show(topbar('Le carnet', glossaryButton() + '<button type="button" class="btn btn--small" data-act="home">Retour à Baker Street <span class="kbd">Échap</span></button>') +
      '<main class="screen library"><nav class="library__nav" aria-label="Pages du carnet">' + tabs.map(function (t) {
        return '<button type="button" data-act="book-tab" data-arg="' + t[0] + '"' + (G.bookTab === t[0] ? ' aria-current="page" class="is-current"' : '') + '>' + esc(t[1]) + '</button>';
      }).join('') + '</nav><div class="library__body"><h2 class="big-title" tabindex="-1" data-autofocus>' + esc(tabs.filter(function (t) { return t[0] === G.bookTab; })[0][1]) + '</h2><div class="libgrid">' + casebookBody(G.bookTab) + '</div></div></main>');
  }

  /* ---------- the dossier des fausses pistes: every typical error lifted ---------- */

  function bestiaryButton() {
    var n = (G.progress.bestiary || []).length;
    return '<button type="button" class="btn btn--small" data-act="bestiary">Fausses pistes <b>' + n + '</b></button>';
  }
  function renderBestiary() {
    G.screen = 'bestiary';
    var list = G.progress.bestiary || [], total = G.progress.bestiaryTotal || 0;
    var body = list.length ? '<ul class="sg-bestiary">' + list.map(function (b) {
      return '<li class="sg-bestiary__item">' + E.bestiaryArt('irregulars', b.misconception, 96) +
        '<span class="sg-bestiary__unit">' + esc(typo(b.unitName)) + '</span>' +
        '<h3 class="sg-bestiary__label">' + esc(typo(b.label)) + '</h3>' +
        (b.explanation ? '<details><summary>Ce qu’en dit Wiggins</summary><p class="method">' + esc(typo(b.explanation)) + '</p></details>' : '') +
        '<span class="sg-bestiary__count">Levée le ' + esc(new Date(b.solvedAt).toLocaleDateString('fr-FR')) + '</span></li>';
    }).join('') + '</ul>'
      : '<p class="sg-bestiary__empty">Aucune fausse piste levée pour l’instant. Quand la même erreur revient deux fois, un fil rouge s’épingle au tableau ; la vérifier et la lever range sa fiche ici, avec la règle qui en vient à bout.</p>';
    show(topbar(BESTIARY, casesCount() + '<button type="button" class="btn btn--small" data-act="home">Retour à Baker Street <span class="kbd">Échap</span></button>') +
      '<main class="screen bestiary" tabindex="-1"><h2 class="big-title" tabindex="-1" data-autofocus>' + esc(BESTIARY) + '</h2>' +
      '<p class="sg-bestiary__count">' + list.length + ' fausse' + (list.length > 1 ? 's' : '') + ' piste' + (list.length > 1 ? 's' : '') + ' levée' + (list.length > 1 ? 's' : '') + ' sur ' + total + ' erreurs que Scotland Yard connaît</p>' +
      body + '</main>');
  }

  /* ---------- the glossary: over any screen ---------- */

  function openGlossary(tab) {
    G.glossTab = tab || G.glossTab;
    var prev = overlay.firstChild ? overlay._prev : document.activeElement;
    var entries = BOOK.glossary[G.glossTab === 'grammar' ? 'grammar' : 'london'];
    overlay.innerHTML = '<div class="gloss-backdrop" data-act="glossary-close"></div><aside class="gloss" role="dialog" aria-modal="true" aria-labelledby="gloss-title"><div class="gloss__head"><h2 id="gloss-title">Glossaire</h2><button type="button" class="btn btn--small" data-act="glossary-close">Fermer <span class="kbd">Échap</span></button></div>' +
      '<div class="gloss__tabs" role="group" aria-label="Rubriques du glossaire"><button type="button" data-act="gloss-tab" data-arg="london" aria-pressed="' + (G.glossTab !== 'grammar') + '">Londres, 1895</button><button type="button" data-act="gloss-tab" data-arg="grammar" aria-pressed="' + (G.glossTab === 'grammar') + '">Grammaire</button></div>' +
      '<dl class="gloss__list">' + entries.map(function (e) { return '<dt' + (G.glossTab === 'grammar' ? '' : ' lang="en"') + '>' + esc(e[0]) + '</dt><dd>' + esc(e[1]) + '</dd>'; }).join('') + '</dl></aside>';
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

  /* ---------- 5. the reward scene ---------- */

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
      '<p class="reward__lore" id="reward-lore">' + esc(typo(rw.lore || (rw.kind === 'card' ? 'Une carte de Londres, pour ta vitrine.' : rw.kind === 'pin' ? 'Elle se porte sur ton avatar, depuis ta page joueur.' : ''))) + '</p>' +
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
    casebook: function () { renderCasebook(); },
    bestiary: renderBestiary,
    sound: function () {
      E.sound.setMuted(!E.sound.muted());
      G.data.mute = E.sound.muted();
      save();
      var b = app.querySelector('[data-act="sound"]');
      if (b) { b.outerHTML = E.sound.button(); b = app.querySelector('[data-act="sound"]'); if (b) b.focus(); }
      if (!E.sound.muted()) E.sound.play('ok', 1);
    },
    'book-tab': function (arg) { renderCasebook(arg); var b = app.querySelector('[data-arg="' + arg + '"]'); if (b) b.focus(); },
    glossary: function () { openGlossary(); },
    'glossary-close': closeGlossary,
    'gloss-tab': function (arg) { openGlossary(arg); },
    'dlg-next': function () { G.dlg.i = Math.min(G.dlg.i + 1, G.dlg.lines.length - 1); renderHome(); },
    place: function (arg) { G.place = arg; G.selected = null; renderHome('.placepanel__name'); },
    pick: function (arg) { var u = unitOf(arg); G.selected = arg; G.place = u ? u.chapter : G.place; G.data.last = arg; if (G.screen !== 'home') G.screen = 'home'; renderHome(); },
    deselect: function () {
      if (G.selected) { var was = G.selected; G.selected = null; renderHome('[data-arg="' + was + '"].casecard'); return; }
      var place = G.place; G.place = null; renderHome(place ? '[data-arg="' + place + '"].board__head' : null);
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
    spot: function (arg) {
      var run = G.run;
      if (!run || run.correction) return;
      run.spot = arg === 'correct' ? 'correct' : +arg;
      Array.prototype.forEach.call(app.querySelectorAll('[data-act="spot"]'), function (b) {
        var on = b.getAttribute('data-arg') === String(run.spot);
        b.classList.toggle('is-chosen', on);
        b.setAttribute('aria-checked', String(on));
      });
    },
    'place-word': function (arg) {
      var run = G.run;
      if (!run || run.correction) return;
      var i = +arg;
      if ((run.placed || []).indexOf(i) >= 0) return;
      run.placed = (run.placed || []).concat([i]);
      var next = firstFree(run.q.choices || [], run.placed);
      run.lastFocus = next >= 0 ? '[data-act="place-word"][data-arg="' + next + '"]' : '[data-act="submit"]';
      renderRun();
    },
    unplace: function (arg) {
      var run = G.run;
      if (!run || run.correction) return;
      var k = +arg, removed = run.placed[k];
      run.placed = run.placed.filter(function (x, j) { return j !== k; });
      run.lastFocus = '[data-act="place-word"][data-arg="' + removed + '"]';
      renderRun();
    },
    'order-clear': function () {
      var run = G.run;
      if (!run || run.correction) return;
      run.placed = [];
      run.lastFocus = '[data-first]';
      renderRun();
    },
    submit: function () { submit(false); },
    'dont-know': function () { submit(true); },
    next: afterCorrection,
    draft: function () {
      var run = G.run, inp = document.getElementById('answer');
      if (inp && !inp.readOnly) run.typed = inp.value;
      run.draftOpen = !run.draftOpen;
      run.lastFocus = null;
      renderRun();
      var ta = document.getElementById('draft-text');
      if (ta) ta.focus(); else { var b = app.querySelector('[data-act="draft"]'); if (b) b.focus(); }
    },
    'draft-clear': function () { var ta = document.getElementById('draft-text'); if (ta) { ta.value = ''; G.run.draft = ''; ta.focus(); } },
    'reward-close': closeReward,
    'toast-close': function () { var t = document.querySelector('.toast'); if (t) t.remove(); }
  };

  var FREE_WHEN_BUSY = ['toast-close', 'reward-close', 'sound', 'draft', 'draft-clear', 'choose', 'spot', 'place-word', 'unplace', 'order-clear', 'glossary', 'glossary-close', 'gloss-tab'];

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b || b.disabled) return;
    var fn = ACTIONS[b.getAttribute('data-act')];
    if (!fn) return;
    e.preventDefault();
    if (G.busy && FREE_WHEN_BUSY.indexOf(b.getAttribute('data-act')) < 0) return;
    fn(b.getAttribute('data-arg'));
  });

  document.addEventListener('keydown', function (e) {
    var typing = e.target && /^(INPUT|TEXTAREA)$/.test(e.target.tagName);
    var run = G.run;
    if (G.screen === 'run' && run && run.q && !run.correction && !typing && !overlay.firstChild && !layer.firstChild) {
      // a QCM answers from the keyboard: 1-4 or A-D choose, Entrée validates
      if (run.q.answerType === 'choice') {
        var n = '1234'.indexOf(e.key) >= 0 ? +e.key - 1 : 'abcd'.indexOf((e.key || '').toLowerCase());
        if (n >= 0 && n < (run.q.choices || []).length) { e.preventDefault(); ACTIONS.choose(String(n)); app.querySelectorAll('.choice')[n].focus(); return; }
        if (e.key === 'Enter' && run.choice !== null && run.choice !== undefined && !e.target.closest('[data-act]:not(.choice)')) { e.preventDefault(); submit(false); return; }
      }
      // a word pointed at: the arrows move between the words, Entrée validates once one is chosen
      if (run.q.answerType === 'spot') {
        var spots = Array.prototype.slice.call(app.querySelectorAll('[data-act="spot"]'));
        var at = spots.indexOf(document.activeElement);
        if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && at >= 0) {
          e.preventDefault();
          spots[(at + (e.key === 'ArrowRight' ? 1 : spots.length - 1)) % spots.length].focus();
          return;
        }
        if (e.key === 'Enter' && run.spot !== null && run.spot !== undefined && !e.target.closest('[data-act]:not([data-act="spot"])')) { e.preventDefault(); submit(false); return; }
      }
      if (run.q.answerType === 'order' && e.key === 'Backspace' && (run.placed || []).length) { e.preventDefault(); ACTIONS.unplace(String(run.placed.length - 1)); return; }
    }
    if (e.key !== 'Escape') return;
    if (layer.firstChild) { e.preventDefault(); closeReward(); return; }
    if (overlay.firstChild) { e.preventDefault(); closeGlossary(); return; }
    var t = document.querySelector('.toast');
    if (t) { t.remove(); return; }
    if (G.screen === 'home' && (G.selected || G.place)) { e.preventDefault(); ACTIONS.deselect(); return; }
    if (G.screen === 'list' || G.screen === 'finalIntro' || G.screen === 'casebook' || G.screen === 'bestiary' || G.screen === 'seriesEnd') { e.preventDefault(); goHome(); return; }
    if (G.screen === 'run' && run && run.draftOpen && document.activeElement && document.activeElement.id === 'draft-text') {
      e.preventDefault();
      var inp = document.getElementById('answer') || app.querySelector('[data-first]');
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
    E.sound.voice('keys');
    E.sound.setMuted(G.data.mute === true); // on by default
    renderHome(false);
  }, function (e) {
    app.innerHTML = '<p class="loading" role="alert">' + esc(errorText(e)) + '</p>';
  });
})();
