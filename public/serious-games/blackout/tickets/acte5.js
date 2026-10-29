/* Black-out - acte 5 : Ouverture sur Internet.
   The game half of the tickets: bug, checks, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte5.yaml. */
(function (T) {
  'use strict';
  var C = T.courses;
  var PARENT = { src: '198.51.100.40' };

  T.add({
    id: 'T17', act: 5, console: 'regie',
    edit: ['r1:/etc/nftables.conf'],
    show: ['www:/etc/network/interfaces'],
    bug: [{ file: 'r1:/etc/nftables.conf', find: 'tcp dport 443 dnat to 10.20.50.10', replace: 'tcp dport 443 dnat to 10.20.50.11' }],
    checks: [
      { label: 'Depuis Internet, un parent ouvre https://inscriptions.tournoi.example', test: function (h) { return h.https('internet', 'inscriptions.tournoi.example', PARENT); } },
      { label: 'Depuis Internet, rien d\'autre ne répond (le 22 reste fermé)', test: function (h) { var r = h.tcp('internet', '203.0.113.2', 22, { src: h.ip('198.51.100.40') }); return { ok: !r.ok, detail: 'le port 22 répond depuis Internet' }; } },
      { label: 'La régie voit toujours le site (10.20.50.10)', test: function (h) { return h.tcp('regie', '10.20.50.10', 443); } }
    ],
    hints: [
      { title: 'La redirection de port', course: C.net07, body: 'Vu d\'Internet, toute la salle n\'a qu\'une adresse. Pour qu\'un serveur interne soit joignable, le routeur réécrit la destination des paquets qui arrivent sur un port donné : c\'est la DNAT, dans la chaîne prerouting de la table nat (« tcp dport 443 dnat to … »).' },
      { title: 'La solution', body: 'Dans /etc/nftables.conf de r1 : « iifname "ens18" tcp dport 443 dnat to 10.20.50.10 ».' }
    ],
    intro: [
      { who: 'karim', expr: 'inquiet', text: 'Les parents doivent s\'inscrire pour la finale. Le site est en ligne… pour nous. Pas pour eux.' },
      { who: 'nadia', expr: 'neutre', text: 'Depuis Internet, un parent ne voit qu\'une adresse : celle de la box. Suis son paquet jusqu\'au site.' }
    ],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Une adresse de travers d\'un chiffre, et le site n\'existe plus pour Internet. Les redirections, on les relit deux fois.' }]
  });

  T.add({
    id: 'T18', act: 5, console: 'regie',
    edit: ['www:/etc/nginx/sites-enabled/inscriptions'],
    show: ['www:/etc/ssl/tournoi/inscriptions.crt', 'www:/etc/ssl/tournoi/www.crt'],
    bug: [{ file: 'www:/etc/nginx/sites-enabled/inscriptions', find: 'ssl_certificate     /etc/ssl/tournoi/inscriptions.crt;', replace: 'ssl_certificate     /etc/ssl/tournoi/www.crt;' }],
    checks: [
      { label: 'Le navigateur d\'un parent n\'affiche plus d\'alerte', test: function (h) { return h.https('internet', 'inscriptions.tournoi.example', PARENT); } }
    ],
    hints: [
      { title: 'À qui appartient un certificat', course: C.http, body: 'Un certificat lie une clé à des noms (le CN et les « Subject Alternative Name »). Le navigateur compare le nom tapé par l\'utilisateur à cette liste ; s\'il n\'y est pas, il alerte. nginx présente le fichier indiqué par ssl_certificate.' },
      { title: 'La solution', body: 'Dans le site nginx : « ssl_certificate /etc/ssl/tournoi/inscriptions.crt; » (et la clé qui va avec reste inscriptions.key).' }
    ],
    intro: [
      { who: 'roussel', expr: 'severe', text: 'Un parent m\'a demandé si nous étions piratés. J\'aimerais une réponse avant minuit.' },
      { who: 'nadia', expr: 'neutre', text: 'Ouvre la configuration nginx du site, et les deux certificats du serveur. Compare les noms.' }
    ],
    done: [{ who: 'roussel', expr: 'satisfaite', text: 'Plus d\'alerte. Je rappelle ce parent pour le rassurer. Avec vos mots, encore une fois.' }]
  });

  T.add({
    id: 'T19', act: 5,
    intro: [{ who: 'lou', expr: 'joie', text: 'J\'ai une idée géniale trouvée sur un forum !' }],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Ouvrir pour se rassurer, c\'est le réflexe le plus dangereux du métier. Tu as bien fait de dire non.' }]
  });

  T.add({
    id: 'T20', act: 5,
    views: {
      conntrack: { title: 'r1 · connexions vers la DMZ (10.20.50.10), 23:40 → 23:50', lines: [
        '198.51.100.40  → 10.20.50.10 : tcp/443 · 12 connexions (un parent s\'inscrit)',
        '198.51.100.12  → 10.20.50.10 : tcp/443 · 9 connexions',
        '203.0.113.150  → 10.20.50.10 : tcp/443 · 4 connexions',
        '10.20.20.66    → 10.20.50.10 : 1 024 ports tentés en 40 s (21, 22, 23, 25, 80, 110, 139, 443, 445, 3306…)',
        '198.51.100.88  → 10.20.50.10 : tcp/443 · 15 connexions',
        '10.20.10.130   → 10.20.50.10 : tcp/443 · 3 connexions (la régie vérifie le site)'
      ] }
    },
    intro: [{ who: 'nadia', expr: 'sourcil', text: 'Le serveur du site rame. Pas à cause des parents, je pense. Regarde.' }],
    done: [
      { who: 'nadia', expr: 'sourcil', text: '10.20.20.66. L\'adresse sans bail, posée à la main, dans le VLAN des joueurs. Elle teste toutes les portes de la DMZ.' },
      { who: 'karim', expr: 'inquiet', text: 'Un joueur qui pirate le site d\'inscription ? Pendant un tournoi de jeux vidéo ?' },
      { who: 'nadia', expr: 'neutre', text: 'Une adresse, pas un joueur. On note. On ne conclut pas.' }
    ]
  });
})(BlackoutTickets);
