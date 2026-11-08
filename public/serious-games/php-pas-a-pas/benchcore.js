/* PHP pas à pas — the bench of the levels (design/validated/serious-games-php-pas-a-pas.md §6).

   Run by the administrator's « Banc des tickets » (bench.html, fed the scenario by the host page) and by
   the developer under Node. For each level it checks the game's half against itself and, when the
   server's half is given, against it:

   - every program the game holds runs without a warning; a lesson's example too;
   - every reference passes its own check on the visible case and the hidden ones, and prints something
     on the visible case; an empty answer fails; **printing the visible case's output by hand fails** (a
     hidden case must differ); each trap - a mistake students make - passes the visible case and fails a
     hidden one: that is what the hidden cases are for;
   - the gaps of a « Compléter », filled with their words, pass; the starting program of a « Réparer » fails;
   - a prediction has exactly one option that is what the code prints; a « Suivre » table has 2 to 8 rows;
   - a « Écrire » phase asks for three programs, a level from the sixth on ends with two reminders, and a
     reminder always has a mastery pool to draw from;
   - a mastery challenge has more programs in its pool than it draws, a « lecture éclair » too;
   - with the scenario: the same levels, the same steps in the same kinds, as many written hints as the
     server counts, a question with exactly one right answer whose wrong ones name their misconception.

   PPPBench.run({ only, scenario }) → [{ id, title, ok, problems }] */
(function (root) {
  'use strict';
  var C = root.PPPContent, E = root.PPPEngine;
  var EXERCISE = { complete: 1, repair: 1, write: 1 };

  function strip(s) { return String(s || '').replace(/<[^>]+>/g, ''); }
  function fillTemplate(template, words) { var i = 0; return template.replace(/\[\[\]\]/g, function () { return words[i++] || ''; }); }
  function errorsOf(code, vars, html) { var r = E.run(code, vars || {}, { html: html }); return r.errors.length ? r.errors[0].raw + ' — ' + r.errors[0].fr : ''; }

  /* A program to write (or complete, or repair), wherever it lives: a level, a mastery pool. */
  function exerciseProblems(def, where, problems, opts) {
    opts = opts || {};
    if (!def.ask) problems.push(where + ' : pas de consigne');
    if (!def.ref) { problems.push(where + ' : pas de référence'); return; }
    var cases = E.casesOf(def);
    // A program of the very first level prints a fixed text: there is nothing for a hidden case to vary.
    if (def.fixed && cases.length !== 1) problems.push(where + ' : un programme à texte fixe n\'a pas de cas caché');
    if (!def.fixed && cases.length < 3) problems.push(where + ' : ' + (cases.length - 1) + ' cas caché(s), il en faut au moins 2');
    cases.forEach(function (c, i) {
      var err = errorsOf(def.ref, c.vars, def.html);
      if (err) problems.push(where + ' : la référence échoue sur le cas ' + (i ? 'caché ' + i : 'visible') + ' (' + err + ')');
    });
    var visible = E.expected(def, def.vars || {});
    if (!E.norm(visible, def.compare)) problems.push(where + ' : la référence n\'affiche rien sur le cas visible');
    var self = E.check(def, def.ref);
    if (!self.passed) problems.push(where + ' : la référence ne passe pas son propre contrôle (' + self.failed.join(', ') + ')');
    if (E.check(def, '').passed) problems.push(where + ' : une réponse vide passe');
    var byHand = def.html ? visible : 'echo ' + E.literal(visible) + ';';
    if (!def.fixed && E.check(def, byHand).passed) problems.push(where + ' : afficher à la main la sortie du cas visible passe (un cas caché doit donner autre chose)');
    if (opts.traps && (!def.traps || !def.traps.length)) problems.push(where + ' : aucun piège (une erreur typique que seul un cas caché révèle)');
    (def.traps || []).forEach(function (trap) {
      var r = E.check(def, trap), vis = r.deliverable && r.criteria.length && r.criteria[0].ok;
      if (!vis || r.passed) problems.push(where + ' : le piège « ' + trap.replace(/\s+/g, ' ').slice(0, 70) + ' » devrait passer le cas visible et échouer sur un cas caché' + (vis ? '' : ' (il échoue déjà sur le cas visible)') + (r.passed ? ' (il passe partout)' : ''));
    });
    if (opts.hints && (!Array.isArray(def.hints) || def.hints.length !== 2)) problems.push(where + ' : il faut deux indices (la méthode, puis un squelette)');
  }

  function stepProblems(lv, step, problems) {
    var where = lv.id + '/' + step.id;
    if (!C.measured(step)) return;
    if (step.before && !lv.lessons[step.before]) problems.push(where + ' : leçon inconnue ' + step.before);
    if (step.kind === 'recall') return;
    if ({ choose: 1, value: 1, trace: 1, cases: 1 }[step.kind] && String(step.code || '').split('\n').length > 12) problems.push(where + ' : code à lire de plus de 12 lignes');
    var want = C.hintsOf(step);
    if (!Array.isArray(step.hints) || step.hints.length !== want) problems.push(where + ' : ' + want + ' indice(s) attendu(s), ' + (step.hints ? step.hints.length : 0) + ' écrit(s)');
    if (!step.ask) problems.push(where + ' : pas de consigne');
    if (step.kind === 'choose') {
      var err = errorsOf(step.code, step.vars, step.html);
      if (err) problems.push(where + ' : le code à lire échoue (' + err + ')');
      var out = E.printed(step), mode = step.html ? 'html' : 'words';
      var good = (step.options || []).filter(function (o) { return E.norm(o, mode) === out; }).length;
      if (!step.options || step.options.length < 3) problems.push(where + ' : moins de 3 propositions');
      if (good !== 1) problems.push(where + ' : ' + good + ' proposition(s) égale(s) à la sortie « ' + out + ' »');
      return;
    }
    if (step.kind === 'value') {
      var e2 = errorsOf(step.code + (step.expr ? '\necho ' + step.expr + ';' : ''), step.vars, step.html);
      if (e2) problems.push(where + ' : le code échoue (' + e2 + ')');
      var v = valueOf(step);
      if (!v || v.length > 30) problems.push(where + ' : la valeur attendue « ' + v + ' » est vide ou trop longue');
      return;
    }
    if (step.kind === 'trace') {
      var e3 = errorsOf(step.code, step.vars, step.html);
      if (e3) problems.push(where + ' : le code échoue (' + e3 + ')');
      var rows = E.traceRows(step);
      if (rows.length < 2 || rows.length > 8) problems.push(where + ' : ' + rows.length + ' ligne(s) dans le tableau de suivi (2 à 8)');
      if (rows.some(function (r) { return r.some(function (x) { return x === null; }); })) problems.push(where + ' : une variable suivie n\'existe pas encore à la ligne ' + step.after);
      return;
    }
    if (step.kind === 'cases') {
      if (!step.values || step.values.length < 3 || step.values.length > 6) problems.push(where + ' : 3 à 6 valeurs à essayer');
      E.caseRows(step).forEach(function (o, i) { if (!o) problems.push(where + ' : rien ne s\'affiche pour la valeur ' + step.values[i]); });
      (step.values || []).forEach(function (val) { var o = {}; o[step.var] = val; var e4 = errorsOf(step.code, Object.assign({}, step.vars, o), step.html); if (e4) problems.push(where + ' : le code échoue pour ' + val + ' (' + e4 + ')'); });
      return;
    }
    if (step.kind === 'aim' || step.kind === 'aimw') {
      var data = (step.vars || {})[step.var];
      if (data === undefined) { problems.push(where + ' : la variable ' + step.var + ' n\'est pas préparée'); return; }
      var leaves = leavesOf(data, ''), target = leaves.filter(function (l) { return l.path === step.target; })[0];
      if (!target) problems.push(where + ' : la case ' + step.target + ' n\'existe pas');
      else if (step.kind === 'aimw' && leaves.filter(function (l) { return E.literal(l.value) === E.literal(target.value); }).length !== 1) problems.push(where + ' : la valeur de la case visée n\'est pas unique');
      return;
    }
    if (EXERCISE[step.kind]) {
      exerciseProblems(step, where, problems, { traps: step.kind !== 'complete' && core(lv.chapter), hints: true });
      if (step.kind === 'complete') {
        var holes = (step.template.match(/\[\[\]\]/g) || []).length;
        if (!step.fill || step.fill.length !== holes) problems.push(where + ' : ' + holes + ' trous pour ' + (step.fill ? step.fill.length : 0) + ' mots');
        else if (!E.check(step, fillTemplate(step.template, step.fill)).passed) problems.push(where + ' : le programme à trous, complété, ne passe pas');
        if (!step.words || !step.words.length) problems.push(where + ' : pas de mots à proposer pour les trous');
        else step.fill.forEach(function (w) { if (step.words.indexOf(w) === -1) problems.push(where + ' : le mot « ' + w + ' » manque à la barre des trous'); });
      }
      if (step.kind === 'repair') {
        if (!step.starter) problems.push(where + ' : pas de programme à réparer');
        else if (E.check(step, step.starter).passed) problems.push(where + ' : le programme à réparer passe déjà');
      }
    }
  }
  // Conditions, loops and arrays: there, every program to write carries at least one trap.
  function core(n) { var c = C.chapter(n); return !!(c && c.core); }
  function valueOf(step) { return E.printed(step); }
  function leavesOf(v, path) {
    if (v !== null && typeof v === 'object') {
      var out = [];
      if (Array.isArray(v)) v.forEach(function (x, i) { out = out.concat(leavesOf(x, path + '[' + i + ']')); });
      else Object.keys(v).forEach(function (k) { out = out.concat(leavesOf(v[k], path + (/^-?\d+$/.test(k) ? '[' + k + ']' : "['" + k + "']"))); });
      return out;
    }
    return [{ path: path, value: v }];
  }

  function levelProblems(lv, ticket) {
    var problems = [];
    if (lv.kind === 'level') {
      if (!lv.title || !lv.summary || !lv.notions || !lv.notions.length) problems.push(lv.id + ' : titre, résumé ou notions manquants');
      (lv.notions || []).forEach(function (n) { if (!C.notion(n)) problems.push(lv.id + ' : notion inconnue ' + n); });
      Object.keys(lv.lessons).forEach(function (k) {
        var l = lv.lessons[k];
        if (!l.title || !l.html) problems.push(lv.id + ' : leçon ' + k + ' incomplète');
        if (l.example) {
          var err = errorsOf(l.example, l.vars, (C.chapter(lv.chapter) || {}).compare === 'html');
          if (err && !l.fails) problems.push(lv.id + ' : l\'exemple de la leçon ' + k + ' échoue (' + err + ')');
          if (!err && l.fails) problems.push(lv.id + ' : l\'exemple de la leçon ' + k + ' devait montrer une erreur');
        }
      });
      var ids = Object.keys(lv.steps);
      ids.forEach(function (id) { stepProblems(lv, lv.steps[id], problems); });
      var kinds = ids.map(function (id) { return lv.steps[id].kind; });
      var writes = kinds.filter(function (k) { return k === 'write'; }).length;
      if (writes !== 3) problems.push(lv.id + ' : la phase « Écrire » demande ' + writes + ' programmes au lieu de 3');
      var recalls = kinds.filter(function (k) { return k === 'recall'; }).length;
      if (lv.num >= 6 && recalls !== 2) problems.push(lv.id + ' : ' + recalls + ' rappel(s), il en faut 2 à partir du niveau 6');
      if (lv.num < 6 && recalls) problems.push(lv.id + ' : pas de rappel avant le niveau 6');
      if (recalls && !C.all().some(function (m) { return m.kind === 'mastery' && (m.covers || []).every(function (id) { var c = C.get(id); return c && c.num < lv.num; }); })) problems.push(lv.id + ' : aucun défi de maîtrise avant ce niveau où tirer ses rappels');
      if (lv.atelier && kinds.filter(function (k) { return k === 'repair'; }).length !== 5) problems.push(lv.id + ' : un atelier fait réparer 5 programmes');
      var lessons = Object.keys(lv.lessons);
      lessons.forEach(function (k) { if (!ids.some(function (id) { return lv.steps[id].before === k; })) problems.push(lv.id + ' : aucune étape n\'ouvre la leçon ' + k); });
    } else if (lv.kind === 'mastery') {
      if (!lv.pool || lv.pool.length < 8) problems.push(lv.id + ' : ' + (lv.pool ? lv.pool.length : 0) + ' programmes dans la réserve, il en faut au moins 8');
      (lv.covers || []).forEach(function (id) { if (!C.get(id)) problems.push(lv.id + ' : couvre un niveau inconnu ' + id); });
      if (!lv.covers || !lv.covers.length) problems.push(lv.id + ' : ne couvre aucun niveau');
      (lv.pool || []).forEach(function (q, i) { exerciseProblems(q, lv.id + '/' + (i + 1), problems, { traps: core(lv.chapter), hints: true }); });
    } else if (lv.kind === 'final') {
      Object.keys(lv.perChapter).forEach(function (ch) {
        var n = lv.pool.filter(function (q) { return String(q.chapter) === ch; }).length;
        if (n < lv.perChapter[ch]) problems.push(lv.id + ' : chapitre ' + ch + ' : ' + n + ' programmes pour en tirer ' + lv.perChapter[ch]);
      });
    }
    if (ticket) {
      var steps = ticket.steps || [];
      if (lv.kind === 'level') {
        var mine = Object.keys(lv.steps), theirs = steps.map(function (s) { return s.id; });
        if (mine.join(',') !== theirs.join(',')) problems.push(lv.id + ' : étapes du jeu (' + mine.join(', ') + ') ≠ étapes du serveur (' + theirs.join(', ') + ')');
        var hints = 0;
        steps.forEach(function (s) {
          var d = lv.steps[s.id];
          if (!d) return;
          var fix = C.measured(d);
          if (fix !== (s.kind === 'fix')) problems.push(lv.id + '/' + s.id + ' : le jeu la mesure, le serveur la corrige (ou l\'inverse)');
          if (fix) hints += C.hintsOf(d);
          if (s.kind === 'explain' || s.kind === 'choice') {
            var good = (s.options || []).filter(function (o) { return o.ok; }).length;
            if (good !== 1) problems.push(lv.id + '/' + s.id + ' : ' + good + ' bonnes réponses');
            (s.options || []).forEach(function (o) { if (!o.ok && !o.misconception) problems.push(lv.id + '/' + s.id + ' : la réponse « ' + o.text + ' » ne nomme pas son idée reçue'); });
          }
        });
        // A level follows the level before it, whatever chapter that one is in.
        var prev = C.all().filter(function (l) { return l.kind === 'level' && l.num === lv.num - 1; })[0];
        if (prev && (ticket.after || []).indexOf(prev.id) === -1) problems.push(lv.id + ' : « after » ne nomme pas le niveau précédent ' + prev.id);
        if (ticket.act !== lv.chapter) problems.push(lv.id + ' : acte ' + ticket.act + ' pour le chapitre ' + lv.chapter);
        if ((ticket.hints || 0) !== hints) problems.push(lv.id + ' : le serveur compte ' + (ticket.hints || 0) + ' indices, le jeu en a ' + hints);
      } else if (steps.length !== 1 || steps[0].kind !== 'fix') problems.push(lv.id + ' : un défi est une seule étape mesurée');
    }
    return problems;
  }

  function flashProblems(f) {
    var problems = [];
    if (!f.pool || f.pool.length < 12) problems.push(f.id + ' : ' + (f.pool ? f.pool.length : 0) + ' codes dans la réserve, il en faut au moins 12');
    (f.covers || []).forEach(function (id) { if (!C.get(id)) problems.push(f.id + ' : couvre un niveau inconnu ' + id); });
    (f.pool || []).forEach(function (q, i) {
      var where = f.id + '/' + (i + 1), err = errorsOf(q.code, q.vars);
      if (err) problems.push(where + ' : le code échoue (' + err + ')');
      var out = E.norm(E.run(q.code, q.vars || {}).output, 'words');
      if (!q.options || q.options.length !== 4) problems.push(where + ' : il faut 4 propositions');
      var good = (q.options || []).filter(function (o) { return E.norm(o, 'words') === out; }).length;
      if (good !== 1) problems.push(where + ' : ' + good + ' proposition(s) égale(s) à la sortie « ' + out + ' »');
      if (q.code.split('\n').length > 9) problems.push(where + ' : trop long pour une lecture éclair (9 lignes au plus)');
    });
    return problems;
  }

  function run(opts) {
    opts = opts || {};
    var out = [], tickets = {};
    ((opts.scenario && opts.scenario.tickets) || []).forEach(function (t) { tickets[t.id] = t; });
    C.all().forEach(function (lv) {
      if (opts.only && lv.id !== opts.only) return;
      var problems = levelProblems(lv, opts.scenario ? tickets[lv.id] : null);
      if (opts.scenario && !tickets[lv.id]) problems.push(lv.id + ' : absent du scénario du serveur');
      out.push({ id: lv.id, title: lv.title, ok: !problems.length, problems: problems });
    });
    C.flashes().forEach(function (f) {
      if (opts.only && f.id !== opts.only) return;
      var problems = flashProblems(f);
      out.push({ id: f.id, title: f.title, ok: !problems.length, problems: problems });
    });
    if (opts.scenario && !opts.only) Object.keys(tickets).forEach(function (id) { if (!C.get(id)) out.push({ id: id, title: tickets[id].title || id, ok: false, problems: [id + ' : absent du jeu'] }); });
    return out;
  }

  root.PPPBench = { run: run, leavesOf: leavesOf, valueOf: valueOf };
})(typeof globalThis !== 'undefined' ? globalThis : window);
