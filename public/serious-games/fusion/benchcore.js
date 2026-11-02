/* Fusion — the bench of the steps: runs in selftest.html, under Node (tool/bench.js) and in Formatool's
   « Banc des étapes ».

   For every step: both halves exist; the graph is valid; a choice has exactly one right answer and
   every wrong one names its misconception; a « line » step designates exactly one line of a view that
   exists; an « order » places every item once and does not start solved; a « fix » step starts with the
   plan or the configuration, followed by the explanation; the answer the student starts from fails
   what the requester checks, the reference passes every case, hidden ones included; and for a plan,
   every hidden night is failed by at least one plan next to the reference (one operation, one check,
   one rollback or the point of no return taken away, two operations swapped, an operation moved before
   the night) that still passes the visible night: a mistake the student cannot see, which only that
   hidden night reveals. A hidden night no such mistake fails teaches nothing. */
(function (root) {
  'use strict';

  function copy(o) { return JSON.parse(JSON.stringify(o)); }
  /** Plans one change away from the reference. */
  function neighbours(ref) {
    var out = [];
    (ref.prep || []).forEach(function (_, i) { var a = copy(ref); a.prep.splice(i, 1); out.push(a); });
    ref.night.forEach(function (row, i) {
      var a = copy(ref); a.night.splice(i, 1); if (a.noReturn !== null && a.noReturn !== undefined && i < a.noReturn) a.noReturn--; out.push(a);
      if (row.check) { var b = copy(ref); delete b.night[i].check; out.push(b); }
      if (row.back) { var c = copy(ref); delete c.night[i].back; out.push(c); }
      if (i + 1 < ref.night.length) { var d = copy(ref); var t = d.night[i]; d.night[i] = d.night[i + 1]; d.night[i + 1] = t; out.push(d); }
      var e = copy(ref); e.prep = (e.prep || []).concat([row.op]); e.night.splice(i, 1); if (e.noReturn !== null && e.noReturn !== undefined && i < e.noReturn) e.noReturn--; out.push(e);
    });
    if (ref.noReturn !== null && ref.noReturn !== undefined) { var f = copy(ref); f.noReturn = null; out.push(f); if (ref.noReturn > 0) { var g = copy(ref); g.noReturn = 0; out.push(g); } }
    return out;
  }

  function run(opts) {
    opts = opts || {};
    var SC = root.FSScenario, ST = root.FSSteps, SIM = root.FSSim;
    var out = [], ids = {};
    SC.all().forEach(function (t) { ids[t.id] = t; });
    SC.all().forEach(function (t) {
      var p = [], def = ST.get(t.id);
      if (opts.only && opts.only !== t.id) return;
      if (def && def.act !== t.act) p.push('acte différent dans les deux moitiés');
      if (!/^E\d\d$/.test(t.id) || Number(t.id.slice(1)) < 8 * (t.act - 1) + 1 || Number(t.id.slice(1)) > 8 * t.act) p.push('numéro hors de son acte');
      (t.after || []).forEach(function (a) {
        if (!ids[a]) p.push('dépend d\'une étape inconnue : ' + a);
        else if (ids[a].act > t.act) p.push('dépend d\'une étape d\'un acte suivant : ' + a);
      });
      var hasFix = t.steps.some(function (s) { return s.kind === 'fix'; });
      if (hasFix && (t.steps[0].kind !== 'fix' || !t.steps[1] || t.steps[1].kind !== 'explain')) p.push('une étape à construire commence par le plan ou la configuration, suivi de l\'explication');
      if (!hasFix && t.guide && t.guide.length) p.push('aide guidée sur une étape sans construction');
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
        if (s.kind === 'order') {
          var sorted = (s.answer || []).slice().sort(function (a, b) { return a - b; });
          if (!s.items || s.items.length < 2 || sorted.length !== s.items.length || sorted.some(function (x, i) { return x !== i; })) p.push(s.id + ' : l\'ordre attendu ne place pas chaque élément une fois');
          else if (s.answer.every(function (x, i) { return x === i; })) p.push(s.id + ' : l\'ordre de départ est déjà le bon');
        }
      });
      if (hasFix && def) {
        if (def.tool !== 'plan' && def.tool !== 'form') p.push('outil inconnu : ' + def.tool);
        var vis = def.cases.filter(function (c) { return c.visible; }), hid = def.cases.filter(function (c) { return !c.visible; });
        if (vis.length < 1) p.push('aucun cas visible');
        if (hid.length < 2) p.push('au moins deux cas cachés');
        var seen = {}; def.cases.forEach(function (c) { if (!c.label) p.push('cas « ' + c.id + ' » sans libellé'); if (seen[c.id]) p.push('cas en double : ' + c.id); seen[c.id] = 1; if (def.tool === 'form' && typeof c.check !== 'function') p.push('cas « ' + c.id + ' » sans vérification'); });
        if (!def.ref) p.push('pas de référence');
        else {
          if (def.tool === 'plan') { var shape = SIM.shape(def, def.ref); if (shape.length) p.push('la référence est mal formée : ' + shape.join(' ; ')); }
          var bad = ST.runCases(def, def.start);
          if (!bad.results.some(function (r) { return r.visible && !r.ok; })) p.push('le point de départ réussit ce que vérifie le demandeur : l\'élève n\'a rien à faire');
          var good = ST.runCases(def, def.ref);
          good.results.forEach(function (r) { if (!r.ok) p.push('la référence échoue : ' + r.label + (r.detail ? ' (' + r.detail + ')' : '')); });
          if (def.tool === 'plan' && good.passed) {
            var muts = neighbours(def.ref).map(function (a) { return ST.runCases(def, a); });
            hid.forEach(function (c) {
              var caught = muts.some(function (m) { return m.results.every(function (r) { return !r.visible || r.ok; }) && m.results.some(function (r) { return r.id === c.id && !r.ok; }); });
              if (!caught) p.push('la nuit cachée « ' + c.label + ' » ne fait échouer aucun plan voisin qui réussit la nuit visible : elle n\'apprend rien');
            });
            if (opts.verbose) muts.forEach(function (m, i) { if (m.passed) p.push('note : le voisin ' + i + ' passe aussi'); });
          }
          if (def.tool === 'form' && opts.verbose) bad.results.forEach(function (r) { if (r.ok) p.push('note : le départ passe déjà « ' + r.label + ' »'); });
        }
      }
      out.push({ id: t.id, title: t.title, ok: !p.filter(function (x) { return x.indexOf('note :') !== 0; }).length, problems: p });
    });
    ST.all().forEach(function (d) { if (!ids[d.id] && !opts.only) out.push({ id: d.id, title: '?', ok: false, problems: ['pas de moitié « plateforme » (host/scenario/)'] }); });
    return out;
  }
  root.FSBench = { run: run, neighbours: neighbours };
})(typeof globalThis !== 'undefined' ? globalThis : window);
