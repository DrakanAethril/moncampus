/* Rétro Rush — the checks of the ticket bench, shared by the bench page of Formatool (bench.js, in a
   sandboxed frame) and the prototype's Node bench (tool/bench.js).

   RetroBench.check(t, def, byId) → the list of problems of one ticket (empty when it is sound):
   - both halves exist, on the same act; the graph names known tickets of the same act or before;
   - a ticket to deliver starts with the delivery, followed by the review (three answers at least);
   - a choice has one right answer and every wrong one names its misconception; a « line » step
     designates exactly one line of its view; an order places every item once;
   - the reference answers pass every check of their own ticket, on the visible and the hidden base;
     the starting text (when there is one) fails at least one; an empty delivery fails them all.
   `t` is the server's half (with its right answers), `def` the game's, `byId` the server's tickets. */
(function (root) {
  'use strict';
  function check(t, def, byId) {
    var E = root.RetroEngine, p = [];
    if (!t) p.push('pas de partie plateforme');
    if (!def) p.push('pas de partie jeu');
    if (!t || !def) return p;
    if (def.act !== t.act) p.push('acte différent dans les deux moitiés');
    (t.after || []).forEach(function (a) { if (!byId[a]) p.push('after inconnu : ' + a); else if (byId[a].act > t.act) p.push('dépend d\'un ticket d\'un acte suivant : ' + a); });
    var steps = t.steps || [], guide = t.guide || [];
    var hasFix = steps.some(function (s) { return s.kind === 'fix'; });
    if (hasFix && (steps[0].kind !== 'fix' || !steps[1] || steps[1].kind !== 'explain')) p.push('une issue à livrer commence par la livraison, suivie de la revue');
    if (hasFix && !def.deliver.length) p.push('aucun livrable');
    if (!hasFix && def.deliver.length) p.push('des livrables sans étape de livraison');
    steps.concat(guide).forEach(function (s) {
      var options = s.options || [];
      if (s.kind === 'choice' || s.kind === 'explain') {
        var oks = options.filter(function (o) { return o.ok; }).length;
        if (oks !== 1) p.push(s.id + ' : ' + oks + ' bonnes réponses au lieu d\'une');
        if (s.kind === 'explain' && options.length < 3) p.push(s.id + ' : une revue propose au moins trois réponses');
        options.forEach(function (o) { if (!o.ok && !o.misconception) p.push(s.id + ' : une mauvaise réponse sans idée reçue nommée'); });
      }
      if (s.kind === 'line') {
        var v = def.views[s.view];
        if (!v) p.push(s.id + ' : vue inconnue ' + s.view);
        else {
          var answers = [].concat(s.answer || []);
          var hits = v.lines.filter(function (l) { return answers.some(function (a) { return l.indexOf(a) !== -1; }); }).length;
          if (hits !== 1) p.push(s.id + ' : la réponse désigne ' + hits + ' lignes au lieu d\'une');
        }
      }
      if (s.kind === 'order' && (!s.items || !Array.isArray(s.answer) || s.answer.length !== s.items.length || s.answer.slice().sort(function (a, b) { return a - b; }).join() !== s.items.map(function (x, i) { return i; }).join())) p.push(s.id + ' : l\'ordre attendu ne place pas chaque élément une fois');
    });
    if (!hasFix) return p;
    try { E.open(def.act, 'visible', def.setup); E.open(def.act, 'hidden', def.setup); } catch (e) { p.push('mise en place : ' + e.message); return p; }
    var refs = {};
    def.deliver.forEach(function (d) { refs[d.id] = d.ref || ''; });
    var solved;
    try { solved = E.check(def, refs); } catch (e) { p.push('référence : ' + e.message); return p; }
    solved.filter(function (r) { return !r.ok; }).forEach(function (r) { p.push('la référence échoue : ' + r.label + (r.detail ? ' (' + r.detail + ')' : '')); });
    if (Object.keys(def.start).length) {
      var start = {};
      def.deliver.forEach(function (d) { start[d.id] = def.start[d.id] || ''; });
      if (E.check(def, start).every(function (x) { return x.ok; })) p.push('le texte de départ passe déjà tous les critères');
    }
    if (E.check(def, {}).some(function (x) { return x.ok; })) p.push('une livraison vide passe un critère');
    return p;
  }
  root.RetroBench = { check: check };
})(typeof globalThis !== 'undefined' ? globalThis : window);
