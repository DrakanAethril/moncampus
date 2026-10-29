/* Black-out — the tickets, on the game's side.

   The platform half (title, request, steps and their right answers, graph, clues) is in
   host/scenario/. Here, what the game shows and checks by itself:

     BlackoutTickets.add({
       id: 'T03', act: 1,
       edit: ['srv-jeu:/etc/network/interfaces'],   // files the student may change at the « fix » step
       show: ['r1:/etc/network/interfaces'],         // files shown read-only (optional)
       bug: [{ file, find, replace }],               // applied to the reference files of the act (game/world.js)
       fix: [...],                                   // optional: the reference repair (else the bug undone)
       cables: [['dev:if', 'dev:if']],               // extra cables, with the bug and after the repair alike
       flags: { ghostDhcp: true },                   // optional world switches
       console: 'pc-a01',                            // the machine the console opens on
       checks: [{ label, test: function (h) { return boolean | { ok, detail } } }],
       views: { id: { title, lines: [...] } },       // the content of a « line » step (logs, tables, frames)
       hints: [{ title, body, course }],             // Nadia's knowledge-base articles, after her questions; the last gives the solution
       intro: [{ who, expr, text }],                 // the scene that opens the ticket
       done:  [{ who, expr, text }]                  // the reaction once closed
     });

   The checks are written in the requester's words: it is what they will check. They receive the
   simulator's helpers (net.js, helpers()): h.ping(from, to), h.tcp(from, to, port), h.udp(…),
   h.lease(dev), h.addr(dev), h.resolve(dev, name), h.vlanOf(dev), h.inNet(ip, cidr), h.port(sw, port). */
(function (root) {
  'use strict';
  var list = [], byId = {};
  var COURSES = {
    numeration: { label: 'SIO1 · La numération', url: 'https://cours.sautour.fr/courses/sio1/numbers/index.html' },
    net02: { label: 'SIO1 · net02 Adressage IPv4', url: 'https://cours.sautour.fr/courses/sio1/net02/index.html' },
    net03: { label: 'SIO1 · net03 Modèles OSI et TCP/IP', url: 'https://cours.sautour.fr/courses/sio1/net03/index.html' },
    net04: { label: 'SIO1 · net04 Ethernet et commutation', url: 'https://cours.sautour.fr/courses/sio1/net04/index.html' },
    net05: { label: 'SIO1 · net05 Le routage', url: 'https://cours.sautour.fr/courses/sio1/net05/index.html' },
    net06: { label: 'SIO1 · net06 Sous-réseaux et VLSM', url: 'https://cours.sautour.fr/courses/sio1/net06/index.html' },
    net07: { label: 'SIO1 · net07 Transport et HTTP', url: 'https://cours.sautour.fr/courses/sio1/net07/index.html' },
    sys06: { label: 'SIO1 · sys06 Logiciels, services et processus', url: 'https://cours.sautour.fr/courses/sio1/sys06/index.html' },
    dhcp: { label: 'SIO2 · Le service DHCP', url: 'https://cours.sautour.fr/courses/sio2/dhcp/index.html' },
    dns: { label: 'SIO2 · Le DNS', url: 'https://cours.sautour.fr/courses/sio2/dns/index.html' },
    http: { label: 'SIO2 · Le service HTTP', url: 'https://cours.sautour.fr/courses/sio2/http/index.html' },
    ssh: { label: 'SIO2 · SSH : connexion et authentification', url: 'https://cours.sautour.fr/courses/sio2/ssh/index.html' }
  };
  root.BlackoutTickets = {
    courses: COURSES,
    add: function (t) {
      if (byId[t.id]) throw new Error('Ticket en double : ' + t.id);
      t.edit = t.edit || [];
      t.show = t.show || [];
      t.checks = t.checks || [];
      t.hints = t.hints || [];
      t.views = t.views || {};
      list.push(t); byId[t.id] = t;
    },
    get: function (id) { return byId[id]; },
    all: function () { return list; }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
