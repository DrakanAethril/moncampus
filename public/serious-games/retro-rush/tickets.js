/* Rétro Rush — the tickets, on the game's side.

   The platform half (title, request, steps and their right answers, graph, clues) is in
   host/scenario/ (config/serious_games/retro-rush/tickets/ once integrated). Here, what the game
   shows and checks by itself:

     RetroTickets.add({
       id: 'R11', act: 3,
       setup: 'UPDATE …;',                       // optional: applied to both bases before anything (the ticket's state)
       deliver: [{ id, label, kind, ref, … }],   // what the student writes, checked by RetroEngine.check (engine.js)
       start: { id: 'SELECT …' },                // optional: the text an editor opens on (the requester's broken query)
       views: { id: { title, lines: [...] } },   // the content of a « line » step (a log, the spreadsheet, a trigger)
       hints: [{ title, body, ref }],            // Jade's wiki pages, after her questions; the last gives the solution
       intro: [{ who, expr, text }],             // the scene that opens the ticket
       done:  [{ who, expr, text }]              // the reaction once closed
     });

   A ticket refers to the BTS SIO referential (`ref`), not to a published course: there is none on
   these notions yet. */
(function (root) {
  'use strict';
  var list = [], byId = {};
  var REF = {
    concevoir: { label: 'Bloc 2 SLAM · Concevoir ou adapter une base de données' },
    requetes: { label: 'Bloc 2 SLAM · Exploiter des données à l\'aide d\'un langage de requêtes' },
    sgbd: { label: 'Bloc 2 SLAM · Développer des fonctionnalités au sein d\'un SGBD (vues, déclencheurs)' },
    administrer: { label: 'Bloc 2 SLAM · Administrer et déployer une base de données' },
    injection: { label: 'Bloc 3 SLAM · Prévenir les attaques d\'une solution applicative (injection SQL)' },
    rgpd: { label: 'Bloc 3 · Protéger les données à caractère personnel' }
  };
  root.RetroTickets = {
    ref: REF,
    add: function (t) {
      if (byId[t.id]) throw new Error('Ticket en double : ' + t.id);
      t.deliver = t.deliver || [];
      t.start = t.start || {};
      t.hints = t.hints || [];
      t.views = t.views || {};
      list.push(t); byId[t.id] = t;
    },
    get: function (id) { return byId[id]; },
    all: function () { return list; }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
