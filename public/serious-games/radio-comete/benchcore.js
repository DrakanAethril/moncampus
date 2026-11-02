/* Radio-Comète — the bench of the steps: runs in selftest.html and under Node (tool/bench.js).

   For every step: both halves exist; the graph is valid; a choice has exactly one right answer and
   every wrong one names its misconception; a « line » step designates exactly one line of a view
   that exists; a script step starts with the script followed by the explanation; and above all
   the starting scripts (with their bug) fail at least one case, the reference scripts pass them
   all, on every machine, hidden ones included. */
(function (root) {
  'use strict';

  function run(opts) {
    opts = opts || {};
    var SC = root.RCScenario, ST = root.RCSteps, P = root.RCParse;
    var out = [], ids = {};
    SC.all().forEach(function (t) { ids[t.id] = t; });
    SC.all().forEach(function (t) {
      var p = [], def = ST.get(t.id);
      if (opts.only && opts.only !== t.id) return;
      if (def && def.act !== t.act) p.push('acte différent dans les deux moitiés');
      t.after.forEach(function (a) {
        if (!ids[a]) p.push('dépend d\'une étape inconnue : ' + a);
        else if (ids[a].act > t.act) p.push('dépend d\'une étape d\'un acte suivant : ' + a);
      });
      var hasFix = t.steps.some(function (s) { return s.kind === 'fix'; });
      if (hasFix && (t.steps[0].kind !== 'fix' || !t.steps[1] || t.steps[1].kind !== 'explain')) p.push('une étape à script commence par le script, suivi de l\'explication');
      if (hasFix && !def) p.push('pas de moitié « jeu » (game/steps/)');
      if (!t.steps.length) p.push('aucune question');
      t.steps.concat(t.guide || []).forEach(function (s) {
        if (s.kind === 'choice' || s.kind === 'explain') {
          if (!s.options || s.options.length < 3) p.push(s.id + ' : au moins trois propositions');
          var oks = (s.options || []).filter(function (o) { return o.ok; }).length;
          if (oks !== 1) p.push(s.id + ' : ' + oks + ' bonnes réponses au lieu d\'une');
          (s.options || []).forEach(function (o) { if (!o.ok && !o.misconception) p.push(s.id + ' : une mauvaise réponse sans idée reçue nommée'); });
        }
        if (s.kind === 'line') {
          var v = def && s.view ? ST.view(def, s.view) : null;
          if (!v) p.push(s.id + ' : rien à désigner (vue ' + s.view + ')');
          else {
            var hits = v.lines.filter(function (l) { return [].concat(s.answer).some(function (a) { return l.indexOf(a) !== -1; }); }).length;
            if (hits !== 1) p.push(s.id + ' : la réponse désigne ' + hits + ' lignes au lieu d\'une');
          }
        }
        if (s.kind === 'order' && (!s.items || s.answer.length !== s.items.length)) p.push(s.id + ' : l\'ordre attendu ne couvre pas tous les éléments');
      });
      if (hasFix && def) {
        if (!def.edit.length) p.push('aucun fichier modifiable');
        if (def.cases.filter(function (c) { return c.visible; }).length < 1) p.push('aucune machine visible');
        if (def.cases.filter(function (c) { return !c.visible; }).length < 2) p.push('au moins deux machines cachées');
        def.edit.forEach(function (f) {
          if (def.start[f] === undefined) p.push('pas de script de départ pour ' + f);
          if (def.ref[f] === undefined) p.push('pas de script de référence pour ' + f);
          [['départ', def.start[f]], ['référence', def.ref[f]]].forEach(function (x) { if (x[1] === undefined || !/\.sh$/.test(f)) return; try { P.parse(x[1]); } catch (e) { p.push('le script de ' + x[0] + ' ne se lit pas (' + f + ') : ' + e.message); } });
        });
        var bad = ST.runCases(def, def.start);
        if (bad.passed) p.push('le script de départ passe toutes les machines : le bug ne fait rien échouer');
        var visBug = bad.results.filter(function (r) { return r.visible && !r.ok; }).length;
        if (!visBug) p.push('le script de départ réussit la machine visible : l\'élève ne voit pas son bug');
        var good = ST.runCases(def, def.ref);
        good.results.forEach(function (r) { if (!r.ok) p.push('la référence échoue : ' + r.label + (r.detail ? ' (' + r.detail + ')' : '') + (opts.verbose ? '\n        ' + r.out.replace(/\n/g, '\n        ') : '')); });
        def.cases.forEach(function (c) { if (!c.label || !c.run || !c.check) p.push('machine « ' + c.id + ' » incomplète'); });
        if (def.cases.length) { var seen = {}; def.cases.forEach(function (c) { if (seen[c.id]) p.push('machine en double : ' + c.id); seen[c.id] = 1; }); }
      }
      out.push({ id: t.id, title: t.title, ok: !p.length, problems: p });
    });
    ST.all().forEach(function (d) { if (!ids[d.id] && !opts.only) out.push({ id: d.id, title: '?', ok: false, problems: ['pas de moitié « plateforme » (host/scenario/)'] }); });
    return out;
  }
  root.RCBench = { run: run };
})(typeof globalThis !== 'undefined' ? globalThis : window);
