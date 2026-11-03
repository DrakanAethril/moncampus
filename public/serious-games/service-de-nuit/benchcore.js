/* Service de nuit — the bench of the nights: runs under Node (tool/bench.js) and in Formatool's « Banc des
   étapes ».

   For every night: both halves exist; the graph is valid; a night to build starts with the site, followed
   by the explanation; a choice has exactly one right answer and every wrong one names its misconception;
   a « veille » whose code runs shows, in a browser, exactly its right option and none of the wrong ones; a
   « line » step designates exactly one line of its view; an « order » places every item once and does not
   start solved. For the site: at least one visible visitor and two hidden ones; the night's clean start
   fails what the requester checks; the reference serves every visitor, hidden ones included, without a
   single PHP warning, and keeps its meter within bounds; every trap - an invisible mistake - passes every
   visible check and fails at least one hidden visitor. For the notebooks: every notion a night brings has
   its page, every example runs without error, every exercise's reference runs cleanly on every run and
   its start does not already pass. */
(function (root) {
  'use strict';

  function run(opts) {
    opts = opts || {};
    var SC = root.SDNScenario, ST = root.SDNSteps, C = root.SDNCarnets, P = root.SDNPhp, H = ST.helpers;
    var out = [], ids = {};
    SC.all().forEach(function (t) { ids[t.id] = t; });
    SC.all().forEach(function (t) {
      var p = [], def = ST.get(t.id);
      if (opts.only && opts.only !== t.id) return;
      if (!def) p.push('pas de moitié « jeu » (game/steps/)');
      else if (def.act !== t.act) p.push('acte différent dans les deux moitiés');
      if (!/^N\d\d$/.test(t.id)) p.push('identifiant hors du modèle N00');
      (t.after || []).forEach(function (a) {
        if (!ids[a]) p.push('dépend d\'une nuit inconnue : ' + a);
        else if (ids[a].act > t.act) p.push('dépend d\'une nuit d\'un acte suivant : ' + a);
      });
      var hasFix = t.steps.some(function (s) { return s.kind === 'fix'; });
      if (hasFix && (t.steps[0].kind !== 'fix' || !t.steps[1] || t.steps[1].kind !== 'explain')) p.push('une nuit à construire commence par le site, suivi de l\'explication');
      if (!hasFix && t.guide && t.guide.length) p.push('aide guidée sur une nuit sans site');
      t.steps.concat(t.guide || []).forEach(function (s) {
        if (s.kind === 'choice' || s.kind === 'explain') {
          if (!s.options || s.options.length < 3) p.push(s.id + ' : au moins trois propositions');
          var oks = (s.options || []).filter(function (o) { return o.ok; });
          if (oks.length !== 1) p.push(s.id + ' : ' + oks.length + ' bonnes réponses au lieu d\'une');
          (s.options || []).forEach(function (o) { if (!o.ok && !o.misconception) p.push(s.id + ' : une mauvaise réponse sans idée reçue nommée'); });
          if (s.view && def) {
            var v = def.views[s.view];
            if (!v) p.push(s.id + ' : vue inconnue ' + s.view);
            else if (v.run) {
              var shown = ST.viewOutput(def, s.view);
              if (shown.res.errors.length) p.push(s.id + ' : le code de la veille affiche une erreur : ' + shown.res.errors[0].message);
              if (oks[0] && oks[0].text !== shown.text) p.push(s.id + ' : la bonne réponse dit « ' + oks[0].text + ' », le navigateur montre « ' + shown.text + ' »');
              (s.options || []).forEach(function (o) { if (!o.ok && o.text === shown.text) p.push(s.id + ' : une mauvaise réponse est exactement ce que montre le navigateur'); });
            }
          }
        }
        if (s.kind === 'line') {
          var lv = def && s.view ? ST.view(def, s.view) : null;
          if (!lv) p.push(s.id + ' : rien à désigner (vue ' + s.view + ')');
          else {
            var hits = lv.lines.filter(function (l) { return [].concat(s.answer).some(function (a) { return l.indexOf(a) !== -1; }); }).length;
            if (hits !== 1) p.push(s.id + ' : la réponse désigne ' + hits + ' lignes au lieu d\'une');
          }
        }
        if (s.kind === 'order') {
          var sorted = (s.answer || []).slice().sort(function (a, b) { return a - b; });
          if (!s.items || s.items.length < 2 || sorted.length !== s.items.length || sorted.some(function (x, i) { return x !== i; })) p.push(s.id + ' : l\'ordre attendu ne place pas chaque élément une fois');
          else if (s.answer.every(function (x, i) { return x === i; })) p.push(s.id + ' : l\'ordre de départ est déjà le bon');
        }
      });
      if (hasFix && def) {
        var visitors = def.cases.filter(function (c) { return !c.source; });
        var vis = visitors.filter(function (c) { return c.visible; }), hid = def.cases.filter(function (c) { return !c.visible; });
        if (vis.length < 1) p.push('aucun visiteur visible');
        if (hid.length < 2) p.push('au moins deux visiteurs cachés');
        var seen = {};
        def.cases.forEach(function (c) {
          if (!c.label) p.push('cas « ' + c.id + ' » sans libellé');
          if (seen[c.id]) p.push('cas en double : ' + c.id);
          seen[c.id] = 1;
          if (typeof c.check !== 'function') p.push('cas « ' + c.id + ' » sans vérification');
          if (!c.source && !/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(c.at || '')) p.push('cas « ' + c.id + ' » sans heure de visite');
        });
        if (!def.ref) p.push('pas de référence');
        else {
          var start = ST.startOf(t.id), ref = ST.refOf(t.id);
          var bad = ST.runCases(def, start, start);
          if (!bad.results.some(function (r) { return r.visible && !r.ok; })) p.push('la version propre du départ réussit déjà ce que vérifie le demandeur : rien à faire');
          var good = ST.runCases(def, ref, start);
          good.results.forEach(function (r) { if (!r.ok) p.push('la référence échoue : ' + r.label + (r.detail ? ' (' + r.detail + ')' : '')); });
          if (def.cost && def.cost.measure(ref, H, start) > def.cost.max) p.push('la référence dépasse le compteur : ' + def.cost.label);
          def.traps.forEach(function (trap) {
            var r = ST.runCases(def, ST.withFiles(ref, trap.files), start);
            var visibleKo = r.results.filter(function (x) { return x.visible && !x.ok; });
            if (visibleKo.length) p.push('le piège « ' + trap.label + ' » se voit déjà : ' + visibleKo[0].label + (visibleKo[0].detail ? ' (' + visibleKo[0].detail + ')' : ''));
            if (!r.results.some(function (x) { return !x.visible && !x.ok; })) p.push('le piège « ' + trap.label + ' » ne fait échouer aucun visiteur caché');
          });
          if (opts.verbose) bad.results.forEach(function (r) { if (r.ok) p.push('note : le départ passe déjà « ' + r.label + ' »'); });
        }
      }
      if (def) def.learn.forEach(function (n) { if (!C.get(n)) p.push('pas de page de carnet pour ' + n); });
      if (def) def.notions.forEach(function (n) { if (!C.get(n)) p.push('notion inconnue : ' + n); });
      out.push({ id: t.id, title: t.title, ok: !p.filter(function (x) { return x.indexOf('note :') !== 0; }).length, problems: p });
    });
    ST.all().forEach(function (d) { if (!ids[d.id] && !opts.only) out.push({ id: d.id, title: '?', ok: false, problems: ['pas de moitié « plateforme » (host/scenario/)'] }); });

    if (!opts.only && C) {
      var learnt = {};
      ST.all().forEach(function (d) { d.learn.forEach(function (n) { learnt[n] = 1; }); });
      C.all().forEach(function (n) {
        var p = [];
        if (!learnt[n.id]) p.push('aucune nuit n\'ouvre cette page');
        n.blocks.forEach(function (b, i) {
          if (!b.code) return;
          var r = P.run({ files: { 'exemple.php': b.code }, entry: 'exemple.php', now: (b.run && b.run.at) || '2026-11-05 23:12:00', get: (b.run && b.run.get) || {}, globals: (b.run && b.run.reg) || {}, seed: 7 });
          if (r.errors.length) p.push('exemple ' + (i + 1) + ' : ' + r.errors[0].level + ' : ' + r.errors[0].message);
        });
        n.exos.forEach(function (x) {
          var same = true;
          x.runs.forEach(function (ctx, k) {
            var go = function (src) { return P.run({ files: { 'exercice.php': src }, entry: 'exercice.php', now: ctx.at || '2026-11-05 23:12:00', get: ctx.get || {}, globals: ctx.reg || {}, seed: 7 }); };
            var r = go(x.ref), s = go(x.start);
            if (r.errors.length) p.push(x.id + ' : la référence affiche une erreur (essai ' + (k + 1) + ') : ' + r.errors[0].message);
            if (s.errors.length || H.text(s.output) !== H.text(r.output)) same = false;
          });
          if (same) p.push(x.id + ' : le point de départ réussit déjà l\'exercice');
        });
        out.push({ id: 'carnet ' + n.letter, title: n.title, ok: !p.length, problems: p });
      });
    }
    return out;
  }
  root.SDNBench = { run: run };
})(typeof globalThis !== 'undefined' ? globalThis : window);
