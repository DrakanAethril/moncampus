/* Fusion - the step bench (design/validated/serious-games-fusion.md §6).

   Opened by an administrator from Paramètres › Serious games, in a sandboxed frame like the game. The host
   page sends the scenario - the server's half of the steps, with their right answers, which the game's
   package never holds - and the bench (benchcore.js) checks each step against the game's half: both
   halves exist, the graph is valid, a choice has one right answer and each wrong one names its
   misconception, a line designates one line, an order places every item, and above all the plan or
   configuration each step starts from fails what the requester checks, the reference passes every case,
   the hidden ones included, and every hidden night catches a mistake the visible night does not show -
   played by the night simulator, which needs no rendering.

     host → bench  { sg: 1, type: 'bench.scenario', scenario, only }
     bench → host  { sg: 1, type: 'bench.ready' } · { sg: 1, type: 'bench.done', total, failures } */
(function () {
  'use strict';
  var started = false;
  function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
  function post(message) { window.parent.postMessage(Object.assign({ sg: 1 }, message), '*'); }
  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) return;
    var m = event.data;
    if (!m || m.sg !== 1 || m.type !== 'bench.scenario' || started || !m.scenario) return;
    started = true;
    document.getElementById('sum').textContent = 'Banc en cours…';
    setTimeout(function () {
      try {
        var tickets = (m.scenario.tickets || []).map(function (t) { t.after = t.after || []; return t; }), byId = {};
        tickets.forEach(function (t) { byId[t.id] = t; });
        window.FSScenario = { all: function () { return tickets; }, get: function (id) { return byId[id]; } };
        var res = window.FSBench.run({ only: typeof m.only === 'string' && m.only ? m.only : '' }), rows = document.getElementById('rows'), failures = 0;
        res.forEach(function (r) {
          if (!r.ok) failures++;
          var tr = document.createElement('tr');
          tr.innerHTML = '<td><strong>' + esc(r.id) + '</strong> · ' + esc(r.title) + '</td><td class="' + (r.ok ? 'ok' : 'ko') + '">' + (r.ok ? 'ok' : esc(r.problems.join(' ; '))) + '</td>';
          rows.appendChild(tr);
        });
        var sum = document.getElementById('sum');
        sum.className = failures ? 'ko' : 'ok';
        sum.textContent = res.length + ' étapes, ' + failures + ' en échec.';
        post({ type: 'bench.done', total: res.length, failures: failures });
      } catch (e) {
        document.getElementById('sum').textContent = 'Erreur : ' + e.message;
        post({ type: 'bench.done', total: 0, failures: -1 });
      }
    }, 0);
  });
  post({ type: 'bench.ready' });
})();
