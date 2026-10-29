/* Black-out - the ticket bench (design/validated/serious-games-blackout.md §6).

   Opened by an administrator from Paramètres › Serious games, in a sandboxed frame like the game.
   The host page sends the scenario - the server's half of the tickets, with their right answers,
   which the game's package never holds - and the bench checks each ticket against the game's half:

   - both halves exist, on the same act; the graph names known tickets of the same act or before;
   - a ticket to repair starts with the repair, followed by the solution;
   - a choice has one right answer and every wrong one names its misconception; a « line » step
     designates exactly one line of its file or of its view; an order places every item;
   - **the configuration with the bug** loads and fails at least one check; **the repaired one**
     (the bug undone, or the ticket's `fix`) and **the reference of the act** pass them all - run
     in the network simulator, which needs no rendering.

     host → bench  { sg: 1, type: 'bench.scenario', scenario, only }
     bench → host  { sg: 1, type: 'bench.ready' } · { sg: 1, type: 'bench.done', total, failures } */
(function () {
  'use strict';

  var N = window.BlackoutNet, T = window.BlackoutTickets, started = false;

  function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
  function format(results) { return results.map(function (r) { return (r.ok ? '✓ ' : '✗ ') + esc(r.label) + (!r.ok && r.detail ? ' (' + esc(r.detail) + ')' : ''); }).join('<br>'); }
  function post(message) { window.parent.postMessage(Object.assign({ sg: 1 }, message), '*'); }

  function checkTicket(byId, id) {
    var t = byId[id], def = T.get(id), p = [];
    var row = { id: id, problems: p, buggy: null, solved: null };
    if (!t) p.push('pas de partie plateforme');
    if (!def) p.push('pas de partie jeu');
    if (!t || !def) return row;

    var steps = t.steps || [], guide = t.guide || [];
    if (def.act !== t.act) p.push('acte différent dans les deux moitiés');
    (t.after || []).forEach(function (a) {
      if (!byId[a]) p.push('after inconnu : ' + a);
      else if (byId[a].act > t.act) p.push('dépend d\'un ticket d\'un acte suivant : ' + a);
    });
    var hasFix = steps.some(function (s) { return s.kind === 'fix'; });
    if (hasFix && (steps[0].kind !== 'fix' || !steps[1] || steps[1].kind !== 'explain')) p.push('un ticket à réparer commence par la réparation, suivie de la solution');
    var files = null;
    try { files = N.ticketFiles(def); } catch (e) { p.push('le bug ne s\'applique pas : ' + e.message); }

    steps.concat(guide).forEach(function (s) {
      var options = s.options || [];
      if (s.kind === 'choice' || s.kind === 'explain') {
        var oks = options.filter(function (o) { return o.ok; }).length;
        if (oks !== 1) p.push(s.id + ' : ' + oks + ' bonnes réponses au lieu d\'une');
        options.forEach(function (o) { if (!o.ok && !o.misconception) p.push(s.id + ' : une mauvaise réponse sans idée reçue nommée'); });
      }
      if (s.kind === 'line') {
        var lines = null;
        if (s.view) lines = def.views[s.view] ? def.views[s.view].lines : null;
        else if (s.file && files) lines = files[s.file] !== undefined ? files[s.file].split('\n') : null;
        if (!lines) p.push(s.id + ' : rien à désigner (' + (s.view || s.file) + ')');
        else {
          var answers = [].concat(s.answer || []);
          var hits = lines.filter(function (l) { return answers.some(function (a) { return l.indexOf(a) !== -1; }); }).length;
          if (hits !== 1) p.push(s.id + ' : la réponse désigne ' + hits + ' lignes au lieu d\'une');
        }
      }
      if (s.kind === 'order' && (!s.items || !Array.isArray(s.answer) || s.answer.length !== s.items.length)) p.push(s.id + ' : l\'ordre attendu ne couvre pas tous les éléments');
    });

    if (!hasFix || !files) return row;
    if (!def.checks.length) p.push('aucun critère');
    if (!def.edit.length) p.push('aucun fichier modifiable');
    def.edit.concat(def.show).forEach(function (f) { if (files[f] === undefined) p.push('fichier absent à l\'acte ' + def.act + ' : ' + f); });
    var bugWorld = N.ticketWorld(def, files);
    if (bugWorld.errors.length) p.push('la configuration buggée ne se charge pas : ' + bugWorld.errors[0].file + ' : ' + bugWorld.errors[0].message);
    row.buggy = N.check(bugWorld, def.checks);
    if (row.buggy.every(function (r) { return r.ok; })) p.push('le bug ne fait échouer aucun critère');
    row.solved = N.checkTicket(def, N.fixedFiles(def));
    row.solved.forEach(function (r) { if (!r.ok) p.push('corrigé, le critère échoue encore : ' + r.label); });
    N.checkTicket(def, N.referenceFiles(def.act)).forEach(function (r) { if (!r.ok) p.push('la référence de l\'acte échoue : ' + r.label); });
    return row;
  }

  function run(scenario, only) {
    var byId = {}, ids = {};
    (scenario.tickets || []).forEach(function (t) { byId[t.id] = t; ids[t.id] = true; });
    T.all().forEach(function (t) { ids[t.id] = true; });
    var list = Object.keys(ids).sort();
    if (only) list = list.filter(function (id) { return id === only; });

    var rows = document.getElementById('rows'), failures = 0;
    list.forEach(function (id) {
      var r;
      try { r = checkTicket(byId, id); } catch (e) { r = { id: id, problems: ['vérification : ' + e.message], buggy: null, solved: null }; }
      if (r.problems.length) failures++;
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><strong>' + esc(r.id) + '</strong></td><td class="' + (r.problems.length ? 'ko' : 'ok') + '">' + (r.problems.length ? esc(r.problems.join(' ; ')) : 'ok') + '</td><td>' + (r.buggy ? format(r.buggy) : '—') + '</td><td>' + (r.solved ? format(r.solved) : '—') + '</td>';
      rows.appendChild(tr);
    });
    var sum = document.getElementById('sum');
    sum.className = failures ? 'ko' : 'ok';
    sum.textContent = list.length + ' tickets, ' + failures + ' en échec.';
    post({ type: 'bench.done', total: list.length, failures: failures });
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) return;
    var m = event.data;
    if (!m || m.sg !== 1 || m.type !== 'bench.scenario' || started || !m.scenario) return;
    started = true;
    document.getElementById('sum').textContent = 'Banc en cours…';
    // Let the status paint before the simulator runs thirty-six tickets.
    setTimeout(function () {
      try { run(m.scenario, typeof m.only === 'string' ? m.only : ''); } catch (e) {
        document.getElementById('sum').textContent = 'Erreur : ' + e.message;
        post({ type: 'bench.done', total: 0, failures: -1 });
      }
    }, 0);
  });
  post({ type: 'bench.ready' });
})();
