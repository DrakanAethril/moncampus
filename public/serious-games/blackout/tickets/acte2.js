/* Black-out - acte 2 : Un VLAN par usage.
   The game half of the tickets: bug, checks, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte2.yaml. */
(function (T) {
  'use strict';
  var C = T.courses;

  T.add({
    id: 'T05', act: 2, console: 'pc-b07',
    edit: ['sw-rang-b:/etc/network/interfaces'],
    show: ['sw-core:/etc/network/interfaces'],
    bug: [{ file: 'sw-rang-b:/etc/network/interfaces', find: '# les PC du rang B\niface ens19\n    bridge-pvid 20', replace: '# les PC du rang B\niface ens19\n    bridge-pvid 10' }],
    checks: [
      { label: 'Les PC du rang B ont une adresse de joueur, en 10.20.20.x', test: function (h) { var a = h.addr('pc-b07'); return { ok: !!a && h.inNet(a, '10.20.20.0/24'), detail: 'pc-b07 a l\'adresse ' + a }; } },
      { label: 'Ils sont dans le VLAN des joueurs (20)', test: function (h) { var v = h.vlanOf('pc-b07'); return { ok: v === 20, detail: 'pc-b07 est dans le VLAN ' + v }; } },
      { label: 'Ils joignent toujours srv-jeu', test: function (h) { return h.ping('pc-b07', 'srv-jeu'); } }
    ],
    hints: [
      { title: 'Port d\'accès et trunk', course: C.net04,
        body: 'Un PC envoie des trames sans étiquette de VLAN. C\'est le port du commutateur qui les range : sur un pont Linux, « bridge-pvid » donne le VLAN des trames sans étiquette. Un trunk, lui, transporte plusieurs VLAN étiquetés, listés dans « bridge-vids ».' },
      { title: 'La solution', body: 'Sur sw-rang-b, le port ens19 (les PC du rang B) doit avoir « bridge-pvid 20 ».' }
    ],
    intro: [
      { who: 'roussel', expr: 'severe', text: 'Bonsoir. Je viens de voir, sur l\'écran d\'un joueur, la liste de nos serveurs. Les joueurs n\'ont rien à faire là.' },
      { who: 'nadia', expr: 'neutre', text: 'Elle a raison. Regarde l\'adresse d\'un PC du rang B, puis le commutateur auquel il est branché.' }
    ],
    done: [
      { who: 'roussel', expr: 'satisfaite', text: 'Bien. Les joueurs d\'un côté, nous de l\'autre. C\'est ainsi que je l\'entendais.' },
      { who: 'nadia', expr: 'sourcil', text: 'Ce port avait été changé à la main. Par qui, et pourquoi… on verra.' }
    ]
  });

  T.add({
    id: 'T06', act: 2, console: 'pc-c12',
    edit: ['sw-core:/etc/network/interfaces'],
    show: ['sw-rang-c:/etc/network/interfaces', 'pc-c12:/etc/network/interfaces'],
    bug: [{ file: 'sw-core:/etc/network/interfaces', find: '# vers sw-rang-c (trunk)\niface ens21\n    bridge-vids 30 99', replace: '# vers sw-rang-c (trunk)\niface ens21\n    bridge-vids 99' }],
    checks: [
      { label: 'Un PC du rang C joint r1 (10.20.30.126)', test: function (h) { return h.ping('pc-c12', '10.20.30.126'); } },
      { label: 'Il joint srv-jeu', test: function (h) { return h.ping('pc-c12', 'srv-jeu'); } },
      { label: 'Le rang A joint toujours srv-jeu', test: function (h) { return h.ping('pc-a01', 'srv-jeu'); } }
    ],
    hints: [
      { title: 'Ce qui passe sur un trunk', course: C.net04,
        body: 'Un trunk ne transporte que les VLAN que ses deux bouts acceptent. Sur un pont Linux, c\'est la liste « bridge-vids » du port. Il suffit qu\'un bout l\'oublie pour que le VLAN s\'arrête là, sans message.' },
      { title: 'La solution', body: 'Sur sw-core, le port ens21 doit porter le VLAN 30 : « bridge-vids 30 99 ».' }
    ],
    intro: [
      { who: 'karim', expr: 'joie', text: 'Le rang C est installé ! Vingt PC de plus, vingt joueurs de plus. Bon, ils ne marchent pas encore, mais ils sont beaux.' },
      { who: 'nadia', expr: 'neutre', text: 'Un VLAN tout neuf, le 30. Suis-le de bout en bout : le PC, son commutateur, le câble, sw-core, r1.' }
    ],
    done: [
      { who: 'karim', expr: 'soulage', text: 'Le rang C est en ligne ! Avec des adresses fixes écrites à la main sur des post-it, mais en ligne.' },
      { who: 'nadia', expr: 'sourire', text: 'On passera le rang C en DHCP plus tard. Chaque chose en son temps.' }
    ]
  });

  T.add({
    id: 'T07', act: 2, console: 'pc-a01',
    edit: ['sw-rang-b:/etc/network/interfaces'],
    cables: [['sw-rang-b:ens31', 'sw-rang-b:ens33']],
    bug: [{ file: 'sw-rang-b:/etc/network/interfaces', find: 'bridge-stp on', replace: 'bridge-stp off' }],
    checks: [
      { label: 'Le rang A joint srv-jeu', test: function (h) { return h.ping('pc-a01', 'srv-jeu'); } },
      { label: 'Le rang B joint srv-jeu', test: function (h) { return h.ping('pc-b07', 'srv-jeu'); } },
      { label: 'La régie joint l\'imprimante', test: function (h) { return h.ping('regie', 'imprimante'); } },
      { label: 'Les prises des tables B13 et B15 restent en service', test: function (h) { return { ok: h.port('sw-rang-b', 'ens31') && h.port('sw-rang-b', 'ens33'), detail: 'un port a été retiré du pont : les tables B13 et B15 n\'ont plus de réseau' }; } }
    ],
    hints: [
      { title: 'Les boucles et le STP', course: C.net04,
        body: 'Une diffusion est recopiée sur tous les ports d\'un commutateur. S\'il existe une boucle, elle revient, repart, se multiplie : en quelques secondes, tout est saturé. Le STP (spanning tree) détecte les boucles et bloque un port pour les casser. Sur un pont Linux : « bridge-stp on ».' },
      { title: 'La solution', body: 'Sur sw-rang-b, remets « bridge-stp on » dans la déclaration de br0. Le câble peut rester : le STP bloquera l\'une des deux prises.' }
    ],
    intro: [
      { who: 'karim', expr: 'panique', text: 'TOUT EST TOMBÉ ! Tout ! Les trois rangs, la régie, le stream ! Les voyants clignotent comme une boîte de nuit !' },
      { who: 'nadia', expr: 'sourcil', text: 'Ça, c\'est une boucle. Regarde la carte, côté rang B. Et regarde ce que quelqu\'un a fait au STP de sw-rang-b.' }
    ],
    done: [
      { who: 'nadia', expr: 'sourcil', text: 'Le STP était coupé à la main, et ce câble n\'est pas tombé du ciel. Quelqu\'un a branché une boucle sur une table où personne n\'est inscrit.' },
      { who: 'karim', expr: 'inquiet', text: 'Quelqu\'un… exprès ? Pendant mon tournoi ?' },
      { who: 'nadia', expr: 'neutre', text: 'Je note dans l\'inventaire. Pour l\'instant, on répare. On enquête en même temps.' }
    ]
  });

  T.add({
    id: 'T08', act: 2,
    intro: [
      { who: 'roussel', expr: 'neutre', text: 'Une question de principe, que me pose le proviseur. J\'aimerais une réponse simple.' }
    ],
    done: [
      { who: 'nadia', expr: 'sourire', text: 'Bien répondu. Séparer, c\'est la première protection, avant tous les mots de passe du monde.' }
    ]
  });
})(BlackoutTickets);
