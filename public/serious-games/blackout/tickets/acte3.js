/* Black-out - acte 3 : Les étages se parlent.
   The game half of the tickets: bug, checks, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte3.yaml. */
(function (T) {
  'use strict';
  var C = T.courses;

  T.add({
    id: 'T09', act: 3, console: 'pc-a01',
    edit: ['r1:/etc/sysctl.d/99-routage.conf'],
    show: ['r1:/etc/network/interfaces'],
    bug: [{ file: 'r1:/etc/sysctl.d/99-routage.conf', find: 'net.ipv4.ip_forward = 1', replace: 'net.ipv4.ip_forward = 0' }],
    checks: [
      { label: 'Le rang A joint srv-jeu', test: function (h) { return h.ping('pc-a01', 'srv-jeu'); } },
      { label: 'Le rang C joint srv-jeu', test: function (h) { return h.ping('pc-c12', 'srv-jeu'); } },
      { label: 'Le rang B joint srv-jeu', test: function (h) { return h.ping('pc-b07', 'srv-jeu'); } }
    ],
    hints: [
      { title: 'Un Linux qui route', course: C.net05, body: 'Par défaut, Debian ne fait pas suivre les paquets d\'une interface à l\'autre, même s\'il en a plusieurs. Le réglage est net.ipv4.ip_forward : 1 pour router. Écrit dans /etc/sysctl.d/, il est appliqué à chaque démarrage.' },
      { title: 'La solution', body: 'Dans /etc/sysctl.d/99-routage.conf : « net.ipv4.ip_forward = 1 ».' }
    ],
    intro: [
      { who: 'karim', expr: 'inquiet', text: 'Les rangs sont dans leurs VLAN, ils ont leurs adresses. Et maintenant, ils ne voient plus le serveur. Aucun rang.' },
      { who: 'nadia', expr: 'sourcil', text: 'Chaque VLAN est un réseau à part. Pour passer de l\'un à l\'autre, il faut un routeur qui accepte de router. Regarde ce que r1 accepte.' }
    ],
    done: [
      { who: 'nadia', expr: 'sourire', text: 'Quelqu\'un avait remis ip_forward à 0 « pour la sécurité ». Couper le routage, c\'est une sécurité… qui coupe tout.' }
    ]
  });

  T.add({
    id: 'T10', act: 3, console: 'regie',
    edit: ['r-stream:/etc/network/interfaces'],
    show: ['r1:/etc/network/interfaces', 'encodeur:/etc/network/interfaces'],
    bug: [{ file: 'r-stream:/etc/network/interfaces', find: '    address 10.20.40.2/24\n    gateway 10.20.40.254\n', replace: '    address 10.20.40.2/24\n' }],
    checks: [
      { label: 'La régie joint l\'encodeur (10.20.41.10)', test: function (h) { return h.ping('regie', '10.20.41.10'); } },
      { label: 'L\'encodeur joint srv-jeu', test: function (h) { return h.ping('encodeur', 'srv-jeu'); } }
    ],
    hints: [
      { title: 'L\'aller et le retour', course: C.net05, body: 'Chaque routeur décide seul, d\'après sa table, où envoyer un paquet. L\'aller suit les routes de r1, le retour suit celles de r-stream. Ajouter une route d\'un côté sans penser à l\'autre, c\'est l\'erreur classique des routes statiques.' },
      { title: 'La solution', body: 'Sur r-stream, ajoute « gateway 10.20.40.254 » à l\'interface ens18 : r1 devient sa route par défaut.' }
    ],
    intro: [
      { who: 'lou', expr: 'inquiete', text: 'Mon stream est noir. Huit cents personnes regardent un écran noir avec écrit « reprise imminente ». Depuis vingt minutes.' },
      { who: 'nadia', expr: 'neutre', text: 'Fais un ping de la régie vers l\'encodeur, et suis-le sur la carte. Regarde bien où il meurt : à l\'aller, ou au retour ?' }
    ],
    done: [
      { who: 'lou', expr: 'joie', text: 'On a l\'image ! Le chat est en feu. Merci !' },
      { who: 'nadia', expr: 'neutre', text: 'Toujours le retour. Retiens-le : une route ne marche que si quelqu\'un sait revenir.' }
    ]
  });

  T.add({
    id: 'T11', act: 3, console: 'pc-a01',
    edit: ['r1:/etc/network/interfaces'],
    bug: [{ file: 'r1:/etc/network/interfaces', find: '    address 192.168.100.2/24\n    gateway 192.168.100.1\n', replace: '    address 192.168.100.2/24\n' }],
    checks: [
      { label: 'Les joueurs joignent Internet (203.0.113.80)', test: function (h) { return h.ping('pc-a01', '203.0.113.80'); } },
      { label: 'Ils trouvent les sites par leur nom (site.example)', test: function (h) { return h.resolve('pc-a01', 'site.example'); } },
      { label: 'Le rang A joint toujours srv-jeu', test: function (h) { return h.ping('pc-a01', 'srv-jeu'); } }
    ],
    hints: [
      { title: 'La route par défaut', course: C.net05, body: 'Un routeur connaît les réseaux branchés sur lui, et ceux qu\'on lui a appris. Pour tout le reste, il y a la route par défaut (0.0.0.0/0), vers le routeur suivant. Pour r1, c\'est la box : 192.168.100.1. Sous Debian, c\'est la ligne « gateway » de l\'interface.' },
      { title: 'La solution', body: 'Sur r1, ajoute « gateway 192.168.100.1 » sous l\'adresse de ens18.' }
    ],
    intro: [
      { who: 'karim', expr: 'panique', text: 'Plus d\'Internet ! Les scores ne partent plus en ligne, et les équipes à distance s\'impatientent !' },
      { who: 'nadia', expr: 'neutre', text: 'Le réseau local marche. C\'est la sortie qui manque. « ip r » sur r1.' }
    ],
    done: [
      { who: 'karim', expr: 'soulage', text: 'Les scores sont en ligne. Les Pingouins mènent 2 à 1, d\'ailleurs. Je ne suis pas content.' }
    ]
  });

  T.add({
    id: 'T12', act: 3,
    views: {
      routes: { title: 'r1 · ip route', lines: [
        'default via 192.168.100.1 dev ens18',
        '10.20.0.0/16 via 192.168.100.1 dev ens18   # ancienne route, laissée par l\'ancien bénévole',
        '10.20.10.0/24 dev ens19.10 proto kernel scope link src 10.20.10.254',
        '10.20.20.0/24 dev ens19.20 proto kernel scope link src 10.20.20.254',
        '10.20.30.0/25 dev ens19.30 proto kernel scope link src 10.20.30.126',
        '10.20.40.0/24 dev ens19.40 proto kernel scope link src 10.20.40.254',
        '10.20.41.0/24 via 10.20.40.2 dev ens19.40',
        '10.20.99.0/24 dev ens19.99 proto kernel scope link src 10.20.99.254',
        '192.168.100.0/24 dev ens18 proto kernel scope link src 192.168.100.2'
      ] }
    },
    intro: [{ who: 'nadia', expr: 'sourire', text: 'Un peu de calme. Profitons-en pour apprendre à lire une table de routage.' }],
    done: [{ who: 'nadia', expr: 'neutre', text: 'Je supprimerai cette vieille route demain. Ce soir, elle ne gêne personne : le plus long préfixe la contourne.' }]
  });
})(BlackoutTickets);
