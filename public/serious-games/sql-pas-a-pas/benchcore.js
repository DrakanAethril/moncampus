/* SQL pas à pas — the bench of the levels (design/validated/serious-games-sql-pas-a-pas.md §6).

   Run by the administrator's « Banc des tickets » (bench.html, fed the scenario by the host page) and by
   the developer under Node. For each level it checks the game's half against itself and, when the
   server's half is given, against it:

   - every query the game holds runs on the visible base and on the hidden one, and returns rows on both
     (an empty result would let anything pass);
   - every reference passes its own check, on both bases; the gaps of a « Compléter » filled with their
     words pass; the starting query of a « Réparer » fails; an empty answer fails;
   - a prediction designates some rows but not all, a value is one value;
   - a « Écrire » phase asks for three queries;
   - a timed challenge has more questions in its pool than it draws, each one sound;
   - with the scenario: the same levels, the same steps in the same kinds, as many written hints as the
     server counts, a question with exactly one right answer.

   SPPBench.run({ only, scenario }) → [{ id, title, ok, problems }] */
(function (root) {
  'use strict';
  var C = root.SPPContent, E = root.SPPEngine;
  var FIX = { pick: 1, value: 1, complete: 1, repair: 1, write: 1 };

  function fillTemplate(template, words) { var i = 0; return template.replace(/\[\[\]\]/g, function () { return words[i++] || ''; }); }
  function rowsOf(which, sql) {
    var s = E.open(which);
    try { var r = s.run(sql); if (r.error) return { error: r.error.fr.replace(/<[^>]+>/g, '') }; var last = null; r.steps.forEach(function (st) { if (st.kind === 'select') last = st; }); return { rows: last ? last.rows : null }; } finally { s.close(); }
  }
  function checkQuery(def, where, problems) {
    if (def.check === 'create' || def.check === 'modify') {
      ['visible'].concat(def.hiddenless || def.check === 'create' ? [] : ['hidden']).forEach(function (w) {
        var s = E.open(w); try { var r = s.run(def.ref); if (r.error) problems.push(where + ' : la référence échoue sur la base ' + w + ' (' + r.error.fr.replace(/<[^>]+>/g, '') + ')'); } finally { s.close(); }
      });
    } else {
      ['visible', 'hidden'].forEach(function (w) {
        var r = rowsOf(w, def.ref);
        if (r.error) problems.push(where + ' : la référence échoue sur la base ' + w + ' (' + r.error + ')');
        else if (!r.rows) problems.push(where + ' : la référence ne lit rien (pas de SELECT)');
        else if (!r.rows.length && !def.allowEmpty) problems.push(where + ' : la référence ne renvoie aucune ligne sur la base ' + w);
      });
    }
    var self = E.check(def, def.ref);
    if (!self.passed) problems.push(where + ' : la référence ne passe pas son propre contrôle (' + self.failed.join(', ') + ')');
    var empty = E.check(def, '');
    if (empty.passed) problems.push(where + ' : une réponse vide passe');
  }

  function stepProblems(lv, step, problems) {
    var where = lv.id + '/' + step.id;
    if (!FIX[step.kind]) return;
    if (!Array.isArray(step.hints) || !step.hints.length) problems.push(where + ' : aucun indice écrit');
    if (step.before && !lv.lessons[step.before]) problems.push(where + ' : leçon inconnue ' + step.before);
    if (step.kind === 'pick') {
      var all = rowsOf('visible', 'SELECT id FROM specimen').rows.length, picked = rowsOf('visible', step.pick);
      if (picked.error) problems.push(where + ' : la sélection échoue (' + picked.error + ')');
      else if (!picked.rows.length || picked.rows.length >= all) problems.push(where + ' : la prédiction désigne ' + picked.rows.length + ' lignes sur ' + all);
      var shown = rowsOf('visible', step.query);
      if (shown.error) problems.push(where + ' : la requête affichée échoue (' + shown.error + ')');
      if (!E.check({ check: 'pick', pick: step.pick }, picked.rows ? picked.rows.map(function (r) { return r[0]; }) : []).passed) problems.push(where + ' : la bonne prédiction ne passe pas');
      return;
    }
    if (step.kind === 'value') {
      var v = rowsOf('visible', step.value);
      if (v.error || !v.rows || v.rows.length !== 1 || v.rows[0].length !== 1) problems.push(where + ' : la valeur attendue n\'est pas une seule valeur');
      if (step.query && !/^\s*(CREATE|INSERT|UPDATE|DELETE|START)/i.test(step.query)) { var q = rowsOf('visible', step.query); if (q.error) problems.push(where + ' : la requête affichée échoue (' + q.error + ')'); }
      else if (v.rows && !E.check({ check: 'value', value: step.value }, String(v.rows[0][0])).passed) problems.push(where + ' : la bonne valeur ne passe pas');
      return;
    }
    if (!step.ask) problems.push(where + ' : pas de consigne');
    checkQuery(step, where, problems);
    if (step.kind === 'complete') {
      var holes = (step.template.match(/\[\[\]\]/g) || []).length;
      if (!step.fill || step.fill.length !== holes) problems.push(where + ' : ' + holes + ' trous pour ' + (step.fill ? step.fill.length : 0) + ' mots');
      else if (!E.check(step, fillTemplate(step.template, step.fill)).passed) problems.push(where + ' : la requête à trous, complétée, ne passe pas');
    }
    if (step.kind === 'repair') {
      if (!step.starter) problems.push(where + ' : pas de requête à réparer');
      else if (E.check(step, step.starter).passed) problems.push(where + ' : la requête à réparer passe déjà');
    }
    (step.traps || []).forEach(function (trap) {
      var r = E.check(step, trap), vis = r.criteria.filter(function (c) { return !/cachée/.test(c.label); }).every(function (c) { return c.ok; });
      if (!vis || r.passed) problems.push(where + ' : le piège « ' + trap + ' » devrait passer la base visible et échouer sur la cachée');
    });
  }

  function levelProblems(lv, ticket) {
    var problems = [];
    if (lv.kind === 'level') {
      Object.keys(lv.lessons).forEach(function (k) {
        var l = lv.lessons[k];
        if (!l.title || !l.html) problems.push(lv.id + ' : leçon ' + k + ' incomplète');
        if (l.example) {
          var s = E.open('visible');
          try {
            var r = s.run(l.example);
            if (r.error && !l.fails) problems.push(lv.id + ' : l\'exemple de la leçon ' + k + ' échoue (' + r.error.fr.replace(/<[^>]+>/g, '') + ')');
            if (!r.error && l.fails) problems.push(lv.id + ' : l\'exemple de la leçon ' + k + ' devait montrer une erreur');
          } finally { s.close(); }
        }
      });
      var ids = Object.keys(lv.steps);
      ids.forEach(function (id) { stepProblems(lv, lv.steps[id], problems); });
      var writes = ids.filter(function (id) { return lv.steps[id].kind === 'write'; }).length;
      if (writes && writes !== 3) problems.push(lv.id + ' : la phase « Écrire » demande ' + writes + ' requêtes au lieu de 3');
      var lessons = Object.keys(lv.lessons), first = ids.map(function (id) { return lv.steps[id]; }).filter(function (s) { return s.before; });
      if (lessons.length && !first.length) problems.push(lv.id + ' : aucune étape n\'ouvre sa leçon');
    } else {
      if (!lv.pool || lv.pool.length <= lv.draw) problems.push(lv.id + ' : ' + (lv.pool ? lv.pool.length : 0) + ' questions pour en tirer ' + lv.draw);
      (lv.pool || []).forEach(function (q, i) {
        var def = Object.assign({ hiddenless: q.hiddenless }, q);
        if (!q.ask) problems.push(lv.id + '/' + (i + 1) + ' : pas de consigne');
        checkQuery(def, lv.id + '/' + (i + 1), problems);
      });
    }
    if (ticket) {
      var steps = ticket.steps || [];
      if (lv.kind === 'level') {
        var mine = Object.keys(lv.steps);
        var theirs = steps.map(function (s) { return s.id; });
        if (mine.join(',') !== theirs.join(',')) problems.push(lv.id + ' : étapes du jeu (' + mine.join(', ') + ') ≠ étapes du serveur (' + theirs.join(', ') + ')');
        var hints = 0;
        steps.forEach(function (s) {
          var d = lv.steps[s.id];
          if (!d) return;
          var fix = !!FIX[d.kind];
          if (fix !== (s.kind === 'fix')) problems.push(lv.id + '/' + s.id + ' : le jeu la mesure, le serveur la corrige (ou l\'inverse)');
          if (fix) hints += (d.hints || []).length;
          if (s.kind === 'explain' || s.kind === 'choice') {
            var good = (s.options || []).filter(function (o) { return o.ok; }).length;
            if (good !== 1) problems.push(lv.id + '/' + s.id + ' : ' + good + ' bonnes réponses');
            (s.options || []).forEach(function (o) { if (!o.ok && !o.misconception) problems.push(lv.id + '/' + s.id + ' : la réponse « ' + o.text + ' » ne nomme pas son idée reçue'); });
          }
        });
        if ((ticket.hints || 0) !== hints) problems.push(lv.id + ' : le serveur compte ' + (ticket.hints || 0) + ' indices, le jeu en a ' + hints);
      } else if (steps.length !== 1 || steps[0].kind !== 'fix') problems.push(lv.id + ' : un défi chrono est une seule étape mesurée');
    }
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
    if (opts.scenario && !opts.only) Object.keys(tickets).forEach(function (id) { if (!C.get(id)) out.push({ id: id, title: tickets[id].title || id, ok: false, problems: [id + ' : absent du jeu'] }); });
    return out;
  }

  root.SPPBench = { run: run };
})(typeof globalThis !== 'undefined' ? globalThis : window);
