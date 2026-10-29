/* Panique à la Neko-Con — the ticket bench (design/validated/serious-games-neko-con.md §8, lot 2).

   Opened by an administrator from Paramètres › Serious games, in a sandboxed frame like the game.
   The host page sends the scenario - the server's half of the tickets, with their right answers,
   which the game's package never holds - and the bench checks each ticket against the game's half:

   - both halves exist, the graph names known tickets;
   - a ticket to repair starts with the repair, followed by the explanation to the requester; a
     guided question is a choice or a line, on a ticket that has a repair;
   - every explanation or choice has one right answer, an explanation at least three options and a
     reaction for each wrong one; a guided line designates exactly one line of its file;
   - **the buggy code fails** at least one criterion and **the repaired code** (the bug reversed,
     or the ticket's `fix`) **passes them all**, measured in a real rendering.

     host → bench  { sg: 1, type: 'bench.scenario', scenario, only }
     bench → host  { sg: 1, type: 'bench.ready' } · { sg: 1, type: 'bench.done', total, failures } */
(function () {
  'use strict';

  var E = window.NekoEngine, T = window.NekoTickets, frame = document.getElementById('frame');
  var started = false;

  function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
  function format(results) { return results.map(function (r) { return (r.ok ? '✓ ' : '✗ ') + esc(r.label) + (r.detail ? ' (' + esc(r.detail) + ')' : ''); }).join('<br>'); }
  function post(message) { window.parent.postMessage(Object.assign({ sg: 1 }, message), '*'); }

  function run(scenario, only) {
    var byId = {};
    (scenario.tickets || []).forEach(function (t) { byId[t.id] = t; });
    var ids = {};
    Object.keys(byId).forEach(function (id) { ids[id] = true; });
    T.all().forEach(function (t) { ids[t.id] = true; });
    var list = Object.keys(ids).sort();
    if (only) list = list.filter(function (id) { return id === only; });
    var report = [];

    var chain = Promise.resolve();
    list.forEach(function (id) {
      chain = chain.then(function () { return checkTicket(byId, id).then(function (row) { report.push(row); }); });
    });

    return chain.then(function () {
      var rows = document.getElementById('rows'), failures = 0;
      report.forEach(function (r) {
        if (r.problems.length) failures++;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><strong>' + esc(r.id) + '</strong></td><td class="' + (r.problems.length ? 'ko' : 'ok') + '">' + (r.problems.length ? esc(r.problems.join(' ; ')) : 'ok') + '</td><td>' + (r.buggy ? format(r.buggy) : '—') + '</td><td>' + (r.solved ? format(r.solved) : '—') + '</td>';
        rows.appendChild(tr);
      });
      var sum = document.getElementById('sum');
      sum.className = failures ? 'ko' : 'ok';
      sum.textContent = report.length + ' tickets, ' + failures + ' en échec.';
      post({ type: 'bench.done', total: report.length, failures: failures });
    });
  }

  function checkTicket(byId, id) {
    var s = byId[id], c = T.get(id), problems = [];
    var row = { id: id, problems: problems, buggy: null, solved: null };
    if (!s) problems.push('pas de partie plateforme');
    if (!c) problems.push('pas de partie jeu');
    if (!s || !c) return Promise.resolve(row);

    var steps = s.steps || [], guide = s.guide || [];
    (s.after || []).forEach(function (a) { if (!byId[a]) problems.push('after inconnu : ' + a); });
    var files;
    try { files = E.ticketFiles(c); } catch (e) { problems.push('bug : ' + e.message); return Promise.resolve(row); }
    var fixAt = steps.map(function (st) { return st.kind; }).indexOf('fix');
    if (fixAt > 0) problems.push('l\'étape « réparer » doit venir en premier');
    if (fixAt === 0 && (!steps[1] || steps[1].kind !== 'explain')) problems.push('pas d\'explication au demandeur après la réparation');
    if (fixAt === -1 && guide.length) problems.push('aide guidée sur un ticket sans réparation');
    guide.forEach(function (st) { if (st.kind !== 'line' && st.kind !== 'choice') problems.push(st.id + ' : type d\'aide inconnu'); });
    steps.forEach(function (st) { if (st.kind === 'line') problems.push(st.id + ' : une étape « ligne » n\'existe que dans l\'aide d\'Inès'); });
    steps.concat(guide).forEach(function (st) {
      var options = st.options || [];
      if (st.kind === 'explain' && options.length < 3) problems.push(st.id + ' : moins de trois explications');
      if (st.kind === 'explain' && options.some(function (o) { return !o.ok && !o.reaction; })) problems.push(st.id + ' : une explication fausse sans réaction');
      if (st.kind === 'line') {
        var src = files[st.file];
        if (src === undefined) { problems.push(st.id + ' : fichier ' + st.file + ' inconnu'); return; }
        var answers = [].concat(st.answer || []);
        var lines = src.split('\n').filter(function (l) { return answers.some(function (a) { return l.indexOf(a) !== -1; }); });
        if (lines.length !== 1) problems.push(st.id + ' : la réponse désigne ' + lines.length + ' lignes');
      }
      if (st.kind === 'choice' || st.kind === 'explain') {
        var good = options.filter(function (o) { return o.ok; }).length;
        if (good !== 1) problems.push(st.id + ' : ' + good + ' bonnes réponses');
      }
    });

    var hasFix = steps.some(function (st) { return st.kind === 'fix'; });
    if (hasFix && !c.checks.length) problems.push('étape « corriger » sans critère');
    if (hasFix && !c.edit.length) problems.push('étape « corriger » sans fichier modifiable');
    if (!hasFix) return Promise.resolve(row);

    return E.check(c, files, frame).then(function (buggy) {
      row.buggy = buggy;
      if (buggy.every(function (r) { return r.ok; })) problems.push('le code buggé passe tous les critères');
      var solvedFiles;
      try { solvedFiles = E.ticketFiles(c, { solved: true }); } catch (e) { problems.push('correction : ' + e.message); return row; }
      return E.check(c, solvedFiles, frame).then(function (solved) {
        row.solved = solved;
        if (!solved.every(function (r) { return r.ok; })) problems.push('le code corrigé échoue');
        return row;
      });
    }).catch(function (e) { problems.push('vérification : ' + e.message); return row; });
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) return;
    var m = event.data;
    if (!m || m.sg !== 1 || m.type !== 'bench.scenario' || started || !m.scenario) return;
    started = true;
    document.getElementById('sum').textContent = 'Banc en cours…';
    run(m.scenario, typeof m.only === 'string' ? m.only : '').catch(function (e) {
      document.getElementById('sum').textContent = 'Erreur : ' + e.message;
      post({ type: 'bench.done', total: 0, failures: -1 });
    });
  });
  post({ type: 'bench.ready' });
})();
