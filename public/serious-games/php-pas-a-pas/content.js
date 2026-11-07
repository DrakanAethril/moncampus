/* PHP pas à pas — the game's half of the levels (design/validated/serious-games-php-pas-a-pas.md §3).

   The server's half lives in config/serious_games/php-pas-a-pas/tickets/*.yaml: each level's steps in
   order, the questions it corrects itself (« Comprendre »: an explanation to choose, each wrong one a
   named misconception), and how many written hints it may give. This half holds what the browser
   measures: the lessons, the programs the student predicts, follows, completes, repairs and writes, the
   reference each one is checked against on the visible case and the hidden ones, the written hints, the
   pools of the mastery challenges (which the reminders draw from too) and the « lectures éclair ». A step
   is found by its level and its identifier, never by its rank.

   PPPContent.level(def) · mastery(def) · final(def) · flash(def)   register (the chapter files do)
   PPPContent.get(id) · all() · flashes() · chapters · notions · chipsFor(seen, first) · hintsOf(def)
*/
(function (root) {
  'use strict';
  var levels = [], byId = {}, flashes = [];

  var CHAPTERS = [
    { n: 1, title: 'Afficher et calculer', time: '1 h 15', compare: 'words' },
    { n: 2, title: 'Décider', time: '3 h 30', compare: 'words', core: true },
    { n: 3, title: 'Répéter', time: '3 h 30', compare: 'words', core: true },
    { n: 4, title: 'Les tableaux', time: '3 h 30', compare: 'lines', core: true },
    { n: 5, title: 'Tableaux à deux dimensions', time: '3 h 30', compare: 'lines', core: true },
    { n: 6, title: 'Les fonctions', time: '1 h 45', compare: 'lines' },
    { n: 7, title: 'Révision', time: '30 min', compare: 'lines' },
    { n: 8, title: 'PHP dans une page', time: '1 h', compare: 'html' }
  ];

  /* The notions of the showcase, chapter by chapter. A notion is « vue » once one of its lessons was
     opened, « pratiquée » once a level that teaches it is done, « maîtrisée » once the mastery challenge
     covering it is won; ⚡ is added when a « lecture éclair » covering it reached 7 out of 8. `chips` are
     the words it adds to the bar of words. */
  var NOTIONS = [
    { id: 'echo', ch: 1, label: 'echo', g: '»', chips: ['echo', '"\\n"'] },
    { id: 'variables', ch: 1, label: 'variables', g: '$', chips: ['='] },
    { id: 'calculs', ch: 1, label: 'calculs', g: '+', chips: ['+', '-', '*', '/', '%', 'intdiv(', 'round('] },
    { id: 'concat', ch: 1, label: 'assembler du texte', g: '.', chips: ['.'] },
    { id: 'comparer', ch: 2, label: 'comparer', g: '<', chips: ['==', '!=', '<', '>', '<=', '>='] },
    { id: 'if', ch: 2, label: 'if', g: '?', chips: ['if', '()', '{}'] },
    { id: 'else', ch: 2, label: 'else', g: ':', chips: ['else'] },
    { id: 'elseif', ch: 2, label: 'elseif', g: '⋮', chips: ['elseif'] },
    { id: 'bornes', ch: 2, label: 'les bornes', g: '[', chips: [] },
    { id: 'etou', ch: 2, label: '&& ||', g: '&', chips: ['&&', '||'] },
    { id: 'non', ch: 2, label: '! et parenthèses', g: '!', chips: ['!'] },
    { id: 'imbrique', ch: 2, label: 'if imbriqués', g: '⊂', chips: [] },
    { id: 'identique', ch: 2, label: '=== et match', g: '≡', chips: ['===', '!==', 'match', '=>', 'default'] },
    { id: 'while', ch: 3, label: 'while', g: '↻', chips: ['while', '++', '--'] },
    { id: 'for', ch: 3, label: 'for', g: '#', chips: ['for'] },
    { id: 'pas', ch: 3, label: 'compter autrement', g: '↓', chips: ['+=', '-='] },
    { id: 'accumuler', ch: 3, label: 'accumuler', g: 'Σ', chips: ['+=', '*='] },
    { id: 'compter', ch: 3, label: 'compter', g: 'n', chips: [] },
    { id: 'extremum', ch: 3, label: 'le plus grand', g: '▲', chips: [] },
    { id: 'reste', ch: 3, label: '% continue break', g: '%', chips: ['continue', 'break'] },
    { id: 'chercher', ch: 3, label: 'chercher', g: '⌕', chips: ['true', 'false'] },
    { id: 'imbriquees', ch: 3, label: 'boucles imbriquées', g: '⊞', chips: [] },
    { id: 'liste', ch: 4, label: 'une liste', g: '[]', chips: ['[', ']', 'count('] },
    { id: 'parcourir', ch: 4, label: 'foreach', g: '→', chips: ['foreach', 'as'] },
    { id: 'ajouter', ch: 4, label: 'ajouter, modifier', g: '+[', chips: ['[] ='] },
    { id: 'totaliser', ch: 4, label: 'totaliser', g: 'Σ', chips: ['array_sum('] },
    { id: 'filtrer', ch: 4, label: 'filtrer', g: '⊃', chips: [] },
    { id: 'chercher-tab', ch: 4, label: 'chercher', g: '∈', chips: ['in_array(', 'array_search('] },
    { id: 'associatif', ch: 4, label: 'clés et valeurs', g: '⇒', chips: ['=>'] },
    { id: 'cle', ch: 4, label: 'clé absente', g: '??', chips: ['isset(', '??'] },
    { id: 'occurrences', ch: 4, label: 'compter les occurrences', g: '#', chips: [] },
    { id: 'grille', ch: 5, label: 'une grille', g: '▦', chips: ['][' ] },
    { id: 'parcours2d', ch: 5, label: 'parcourir une grille', g: '⊞', chips: [] },
    { id: 'enregistrements', ch: 5, label: 'liste d’enregistrements', g: '☰', chips: [] },
    { id: 'filtrer2d', ch: 5, label: 'filtrer, totaliser', g: '⊃', chips: [] },
    { id: 'meilleur', ch: 5, label: 'le meilleur', g: '★', chips: [] },
    { id: 'cletableau', ch: 5, label: 'un tableau dans une clé', g: '⊂', chips: [] },
    { id: 'regrouper', ch: 5, label: 'regrouper', g: '⧉', chips: [] },
    { id: 'construire', ch: 5, label: 'construire', g: '✚', chips: [] },
    { id: 'trier', ch: 5, label: 'trier', g: '↕', chips: ['sort(', 'rsort(', 'asort(', 'arsort(', 'ksort(', 'usort(', '<=>'] },
    { id: 'fnphp', ch: 6, label: 'les fonctions de PHP', g: 'ƒ', chips: ['strlen(', 'strtoupper(', 'strtolower(', 'ucfirst(', 'str_contains(', 'implode(', 'explode(', 'max(', 'min('] },
    { id: 'fonction', ch: 6, label: 'écrire une fonction', g: 'ƒ(', chips: ['function'] },
    { id: 'return', ch: 6, label: 'return', g: '↩', chips: ['return'] },
    { id: 'portee', ch: 6, label: 'ce qu’une fonction voit', g: '◌', chips: [] },
    { id: 'fntableau', ch: 6, label: 'une fonction et un tableau', g: 'ƒ[', chips: [] },
    { id: 'revision', ch: 7, label: 'tout ensemble', g: '✓', chips: [] },
    { id: 'html', ch: 8, label: 'PHP dans du HTML', g: '<>', chips: ['<?php', '?>', '<?=', '<li>', '</li>'] },
    { id: 'get', ch: 8, label: '$_GET et htmlspecialchars', g: '?', chips: ['$_GET', 'htmlspecialchars(', 'isset(', '??'] }
  ];
  var notionById = {};
  NOTIONS.forEach(function (n) { notionById[n.id] = n; });

  function chapter(n) { return CHAPTERS.filter(function (c) { return c.n === n; })[0]; }
  function defaults(def, ch) {
    var c = chapter(ch);
    if (!def.compare && c) def.compare = c.compare;
    if (def.html === undefined && c && c.compare === 'html') def.html = true;
    rulesFromAsk(def);
    return def;
  }
  /* What the output cannot check, read from the wording of the task itself: « Écris une fonction nom(…) »
     asks for that function, « qui renvoie » for a return, « sans max » forbids max(). */
  function rulesFromAsk(def) {
    var ask = String(def.ask || ''), m = /[ÉE]cris une fonction (\w+)\s*\(/.exec(ask);
    if (def.kind && !{ write: 1, repair: 1, complete: 1 }[def.kind]) return;
    def.must = def.must || [];
    def.mustNot = def.mustNot || [];
    if (m) {
      def.must.push({ re: new RegExp('function\\s+' + m[1] + '\\s*\\('), text: 'La consigne demande d’écrire la fonction <code>' + m[1] + '()</code> : déclare-la avec <code>function ' + m[1] + '(…) { … }</code>, puis appelle-la.' });
      if (/qui renvoie/.test(ask)) def.must.push({ re: /\breturn\b/, text: 'La fonction doit <b>renvoyer</b> son résultat avec <code>return</code>, pas l’afficher : c’est le programme qui l’affiche.' });
    }
    if (/^Avec match\b/.test(ask)) def.must.push({ re: /\bmatch\s*\(/, text: 'La consigne demande d’utiliser <code>match</code>.' });
    var no = /\(?sans (array_sum|max|min|in_array|count|array_search|sort)\b\)?/.exec(ask);
    if (no) def.mustNot.push({ re: new RegExp('\\b' + no[1] + '\\s*\\('), text: 'La consigne demande de le faire sans <code>' + no[1] + '()</code> : écris la boucle toi-même.' });
  }

  function level(def) {
    def.kind = 'level';
    def.steps = def.steps || {};
    def.lessons = def.lessons || {};
    Object.keys(def.steps).forEach(function (k) {
      var s = def.steps[k];
      s.id = k;
      defaults(s, def.chapter);
    });
    levels.push(def);
    byId[def.id] = def;
    return def;
  }
  function mastery(def) {
    def.kind = 'mastery';
    def.draw = def.draw || 3;
    (def.pool || []).forEach(function (q, i) { q.ref_id = def.id + '/' + (i + 1); defaults(q, def.chapter); });
    levels.push(def);
    byId[def.id] = def;
    return def;
  }
  /* The final test draws from every mastery pool, `perChapter` questions of each chapter, timed. */
  function final(def) {
    def.kind = 'final';
    def.pool = [];
    levels.forEach(function (lv) { if (lv.kind === 'mastery') lv.pool.forEach(function (q) { def.pool.push(Object.assign({ chapter: lv.chapter }, q)); }); });
    def.draw = Object.keys(def.perChapter).reduce(function (a, k) { return a + def.perChapter[k]; }, 0);
    levels.push(def);
    byId[def.id] = def;
    return def;
  }
  /* A « lecture éclair »: not a ticket, never sent; the game keeps its best score. */
  function flash(def) { def.kind = 'flash'; def.draw = def.draw || 8; def.seconds = def.seconds || 120; flashes.push(def); byId[def.id] = def; return def; }

  /* The written hints a measured step may give: one for a step answered by touch, two (a method, then a
     skeleton) for a program to complete, repair or write - a reminder draws its own, always two. */
  var ONE = { choose: 1, value: 1, trace: 1, cases: 1, aim: 1, aimw: 1 };
  var TWO = { complete: 1, repair: 1, write: 1, recall: 1 };
  function measured(step) { return !!(ONE[step.kind] || TWO[step.kind]); }
  function hintsOf(step) { return ONE[step.kind] ? 1 : (TWO[step.kind] ? 2 : 0); }

  /* The words of the bar, for the notions seen: the level's own first, then the others. */
  function chipsFor(seen, first) {
    var out = [], have = {};
    function add(w) { if (!have[w]) { have[w] = 1; out.push(w); } }
    (first || []).forEach(function (id) { if (seen[id] && notionById[id]) notionById[id].chips.forEach(add); });
    NOTIONS.forEach(function (n) { if (seen[n.id]) n.chips.forEach(add); });
    return out;
  }

  root.PPPContent = {
    level: level,
    mastery: mastery,
    final: final,
    flash: flash,
    get: function (id) { return byId[id] || null; },
    all: function () { return levels.slice(); },
    flashes: function () { return flashes.slice(); },
    chapters: CHAPTERS,
    chapter: chapter,
    notions: NOTIONS,
    notion: function (id) { return notionById[id] || null; },
    chipsFor: chipsFor,
    measured: measured,
    hintsOf: hintsOf
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
