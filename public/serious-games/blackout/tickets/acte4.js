/* Black-out - acte 4 : Les adresses tombent du ciel.
   The game half of the tickets: bug, checks, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte4.yaml. */
(function (T) {
  'use strict';
  var C = T.courses;

  T.add({
    id: 'T13', act: 4, console: 'pc-c12',
    edit: ['r1:/etc/default/isc-dhcp-relay'],
    show: ['srv-dhcp:/etc/kea/kea-dhcp4.conf', 'pc-c12:/etc/network/interfaces'],
    bug: [{ file: 'r1:/etc/default/isc-dhcp-relay', find: 'INTERFACES="ens19.10 ens19.20 ens19.30"', replace: 'INTERFACES="ens19.10 ens19.20"' }],
    checks: [
      { label: 'Un PC du rang C obtient une adresse en 10.20.30.x', test: function (h) { var l = h.lease('pc-c12'); return { ok: !!l && l.ok && h.inNet(h.ipstr(l.ip), '10.20.30.0/25'), detail: l && !l.ok ? l.detail : 'adresse ' + h.addr('pc-c12') }; } },
      { label: 'Il joint srv-jeu', test: function (h) { return h.ping('pc-c12', 'srv-jeu'); } },
      { label: 'Les rangs A et B gardent leur adresse', test: function (h) { var l = h.lease('pc-a01'); return { ok: !!l && l.ok, detail: l ? l.detail : '' }; } }
    ],
    hints: [
      { title: 'Le relais DHCP', course: C.dhcp, body: 'Un poste sans adresse crie sa demande en diffusion ; un routeur ne fait jamais suivre une diffusion. L\'agent relais (isc-dhcp-relay) l\'écoute sur les interfaces listées dans INTERFACES, et l\'envoie directement au serveur (SERVERS). Il doit écouter sur chaque réseau client, et sur celui qui mène au serveur.' },
      { title: 'La solution', body: 'Dans /etc/default/isc-dhcp-relay de r1 : INTERFACES="ens19.10 ens19.20 ens19.30".' }
    ],
    intro: [
      { who: 'karim', expr: 'inquiet', text: 'Le rang C est passé en DHCP. Et il n\'a plus d\'adresse. On revient aux post-it ?' },
      { who: 'nadia', expr: 'neutre', text: 'Pas question. Le nouvel outil, la capture, te montrera ce qui circule. Mais commence par « dhclient -v ens18 » sur un PC du rang C.' }
    ],
    done: [
      { who: 'karim', expr: 'soulage', text: 'Le rang C a ses adresses ! Les post-it vont à la poubelle. Enfin, au recyclage.' }
    ]
  });

  T.add({
    id: 'T14', act: 4, console: 'imprimante',
    edit: ['srv-dhcp:/etc/kea/kea-dhcp4.conf'],
    show: ['imprimante:/etc/network/interfaces'],
    bug: [{ file: 'srv-dhcp:/etc/kea/kea-dhcp4.conf', find: '"data": "10.20.10.254" } ],\n            "reservations": [\n                { "hw-address": "00:1b:a9:42:0f:20", "ip-address": "10.20.10.20", "hostname": "imprimante-badges" }\n            ]', replace: '"data": "10.20.10.254" } ]' }],
    checks: [
      { label: 'L\'imprimante reçoit l\'adresse 10.20.10.20', test: function (h) { return { ok: h.addr('imprimante') === '10.20.10.20', detail: 'elle a reçu ' + h.addr('imprimante') }; } },
      { label: 'La régie la joint en 10.20.10.20', test: function (h) { return h.ping('regie', '10.20.10.20'); } }
    ],
    hints: [
      { title: 'Les réservations', course: C.dhcp, body: 'Une réservation lie une adresse à une adresse MAC : le serveur la donne toujours à cette machine, et jamais à une autre. Dans Kea, c\'est une liste « reservations » dans le sous-réseau, avec « hw-address » et « ip-address ». Mieux vaut une adresse hors de la plage.' },
      { title: 'La solution', body: 'Dans le sous-réseau 10.20.10.0/24 de Kea, ajoute : "reservations": [ { "hw-address": "00:1b:a9:42:0f:20", "ip-address": "10.20.10.20" } ] (attention à la virgule avant).' }
    ],
    intro: [
      { who: 'karim', expr: 'neutre', text: 'L\'imprimante des badges, encore elle. Elle se balade d\'adresse en adresse depuis qu\'on l\'a passée en DHCP.' },
      { who: 'nadia', expr: 'sourcil', text: 'Sur l\'imprimante, « ip a » : note sa MAC. Puis ouvre la configuration de Kea.' }
    ],
    done: [
      { who: 'nadia', expr: 'sourcil', text: 'En relisant les baux, une chose me gêne. 10.20.20.66 répond au ping, et n\'a aucun bail. Quelqu\'un a posé cette adresse à la main.' },
      { who: 'karim', expr: 'inquiet', text: 'Aucun joueur n\'a le droit de configurer son PC à la main. C\'est dans le règlement.' }
    ]
  });

  T.add({
    id: 'T15', act: 4, console: 'pc-a01',
    edit: ['srv-dns:/etc/bind/db.tournoi.lan'],
    bug: [{ file: 'srv-dns:/etc/bind/db.tournoi.lan', find: 'bracket    CNAME srv-jeu\n', replace: 'bracket    CNAME srv-jeu.tournoi.lan\n' }],
    checks: [
      { label: 'bracket.tournoi.lan mène à srv-jeu (10.20.10.10)', test: function (h) { var r = h.resolve('pc-a01', 'bracket.tournoi.lan'); return { ok: r.ok && h.ipstr(r.ip) === '10.20.10.10', detail: r.detail }; } },
      { label: 'srv-jeu.tournoi.lan répond toujours', test: function (h) { return h.resolve('pc-a01', 'srv-jeu.tournoi.lan'); } },
      { label: 'Les autres noms aussi (regie.tournoi.lan)', test: function (h) { return h.resolve('pc-a01', 'regie.tournoi.lan'); } }
    ],
    hints: [
      { title: 'Le point final', course: C.dns, body: 'Dans un fichier de zone, un nom qui ne finit pas par un point est relatif : bind9 lui ajoute $ORIGIN. « srv-jeu » devient srv-jeu.tournoi.lan. ; « srv-jeu.tournoi.lan » devient srv-jeu.tournoi.lan.tournoi.lan. — le piège le plus classique du DNS.' },
      { title: 'La solution', body: 'Écris « bracket    CNAME srv-jeu » (nom court) ou « bracket    CNAME srv-jeu.tournoi.lan. » (avec le point final).' }
    ],
    intro: [
      { who: 'karim', expr: 'inquiet', text: 'Le tableau du tournoi est introuvable. Deux cents joueurs rafraîchissent une page d\'erreur.' },
      { who: 'nadia', expr: 'neutre', text: '« dig bracket.tournoi.lan » sur un PC joueur. Lis bien la réponse, lettre par lettre.' }
    ],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Tout le monde l\'oublie une fois. Ceux qui l\'oublient deux fois finissent par mettre des points partout.' }]
  });

  T.add({
    id: 'T16', act: 4, console: 'pc-c12',
    edit: ['srv-dhcp:/etc/kea/kea-dhcp4.conf'],
    bug: [{ file: 'srv-dhcp:/etc/kea/kea-dhcp4.conf', find: '{ "name": "domain-name-servers", "data": "10.20.10.6" }', replace: '{ "name": "domain-name-servers", "data": "10.20.10.5" }' }],
    checks: [
      { label: 'Un PC du rang C trouve srv-jeu.tournoi.lan', test: function (h) { return h.resolve('pc-c12', 'srv-jeu.tournoi.lan'); } },
      { label: 'Un PC du rang A aussi', test: function (h) { return h.resolve('pc-a01', 'srv-jeu.tournoi.lan'); } },
      { label: 'Ils joignent toujours 10.20.10.10 par son adresse', test: function (h) { return h.ping('pc-c12', '10.20.10.10'); } }
    ],
    hints: [
      { title: 'Ce que donne le DHCP', course: C.dhcp, body: 'Avec l\'adresse, le serveur DHCP donne des options : le masque, la passerelle (routers, option 3) et les serveurs DNS (domain-name-servers, option 6). Une option fausse, et tous les postes héritent de l\'erreur à leur prochain bail.' },
      { title: 'La solution', body: 'Dans Kea, l\'option domain-name-servers doit valoir 10.20.10.6, l\'adresse de srv-dns.' }
    ],
    intro: [
      { who: 'lou', expr: 'inquiete', text: 'Les joueurs me disent que « Internet ne marche plus » mais quand ils tapent une adresse, ça marche. Je ne comprends rien.' },
      { who: 'nadia', expr: 'neutre', text: 'Les noms, pas les adresses. Demande-toi qui dit aux PC où se trouve leur serveur DNS.' }
    ],
    done: [{ who: 'lou', expr: 'joie', text: 'Les joueurs ont retrouvé leurs noms. Et moi, mon chat apaisé.' }]
  });
})(BlackoutTickets);
