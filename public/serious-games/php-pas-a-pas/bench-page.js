/* PHP pas à pas - the bench of the levels (design/validated/serious-games-php-pas-a-pas.md §6).

   Opened by an administrator from Paramètres › Serious games, in a sandboxed frame like the game. The host
   page sends the scenario - the server's half of the levels, with the right answers of the questions,
   which the game's package never holds - and the bench (benchcore.js) checks each level against the
   game's half: the same steps in the same kinds, as many written hints as the server counts, one right
   answer per question, and above all every reference passing its own check on the visible case and the
   hidden ones, every trap caught by a hidden case, every starting program of a « Réparer » failing.

     host → bench  { sg: 1, type: 'bench.scenario', scenario, only }
     bench → host  { sg: 1, type: 'bench.ready' } · { sg: 1, type: 'bench.done', total, failures } */
(function () {
  'use strict';
  var started = false;
  function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
  function post(message) { window.parent.postMessage(Object.assign({ sg: 1 }, message), '*'); }
  function fail(message) {
    document.getElementById('sum').textContent = 'Erreur : ' + message;
    post({ type: 'bench.done', total: 0, failures: -1 });
  }
  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) return;
    var m = event.data;
    if (!m || m.sg !== 1 || m.type !== 'bench.scenario' || started || !m.scenario) return;
    started = true;
    document.getElementById('sum').textContent = 'Banc en cours…';
    window.PPPEngine.ready().then(function () {
      try {
        var res = window.PPPBench.run({ only: typeof m.only === 'string' && m.only ? m.only : '', scenario: m.scenario }), rows = document.getElementById('rows'), failures = 0;
        res.forEach(function (r) {
          if (!r.ok) failures++;
          var tr = document.createElement('tr');
          tr.innerHTML = '<td><strong>' + esc(r.id) + '</strong> · ' + esc(r.title) + '</td><td class="' + (r.ok ? 'ok' : 'ko') + '">' + (r.ok ? 'ok' : esc(r.problems.join(' ; '))) + '</td>';
          rows.appendChild(tr);
        });
        var sum = document.getElementById('sum');
        sum.className = failures ? 'ko' : 'ok';
        sum.textContent = res.length + ' contrôles, ' + failures + ' en échec.';
        post({ type: 'bench.done', total: res.length, failures: failures });
      } catch (e) { fail(e.message); }
    }).catch(function (e) { fail(e.message); });
  });
  post({ type: 'bench.ready' });
})();
