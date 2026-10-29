/* Rétro Rush - the ticket bench (design/validated/serious-games-retro-rush.md §2).

   Opened by an administrator from Paramètres › Serious games, in a sandboxed frame like the game.
   The host page sends the scenario - the server's half of the tickets, with their right answers,
   which the game's package never holds - and the bench checks each ticket against the game's half
   (benchcore.js): the graph and the steps, then every reference answer run through the pipeline on
   the visible and the hidden base, the starting text failing at least one check.

     host → bench  { sg: 1, type: 'bench.scenario', scenario, only }
     bench → host  { sg: 1, type: 'bench.ready' } · { sg: 1, type: 'bench.done', total, failures } */
(function () {
  'use strict';
  var T = window.RetroTickets, started = false;
  function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
  function post(message) { window.parent.postMessage(Object.assign({ sg: 1 }, message), '*'); }

  function run(scenario, only) {
    var byId = {}, ids = {};
    (scenario.tickets || []).forEach(function (t) { byId[t.id] = t; ids[t.id] = true; });
    T.all().forEach(function (t) { ids[t.id] = true; });
    var list = Object.keys(ids).sort();
    if (only) list = list.filter(function (id) { return id === only; });
    var rows = document.getElementById('rows'), failures = 0;
    list.forEach(function (id) {
      var p;
      try { p = window.RetroBench.check(byId[id], T.get(id), byId); } catch (e) { p = ['vérification : ' + e.message]; }
      if (p.length) failures++;
      var def = T.get(id);
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><strong>' + esc(id) + '</strong></td><td>' + esc(byId[id] ? byId[id].title : '') + '</td><td>' + (def ? def.deliver.map(function (d) { return esc(d.kind); }).join(', ') || '—' : '—') + '</td><td class="' + (p.length ? 'ko' : 'ok') + '">' + (p.length ? esc(p.join(' ; ')) : 'ok') + '</td>';
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
    document.getElementById('sum').textContent = 'Banc en cours : chaque référence passe le pipeline sur les deux bases…';
    window.RetroEngine.ready().then(function () {
      setTimeout(function () {
        try { run(m.scenario, typeof m.only === 'string' ? m.only : ''); } catch (e) {
          document.getElementById('sum').textContent = 'Erreur : ' + e.message;
          post({ type: 'bench.done', total: 0, failures: -1 });
        }
      }, 0);
    }).catch(function (e) {
      document.getElementById('sum').textContent = 'Le moteur SQL ne se charge pas : ' + e.message;
      post({ type: 'bench.done', total: 0, failures: -1 });
    });
  });
  post({ type: 'bench.ready' });
})();
