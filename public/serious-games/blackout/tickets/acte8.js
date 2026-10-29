/* Black-out - acte 8 : Le Ghost frappe.
   The game half of the tickets: bug, checks, views to designate, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte8.yaml. */
(function (T) {
  'use strict';
  var C = T.courses;

  T.add({
    id: 'T29', act: 8,
    views: {
      capture: { title: 'Capture · sw-rang-b, port miroir · filtre « dhcp »', lines: [
        '1  02:11:58.104  0.0.0.0      → 255.255.255.255  DHCP  Discover · pc-b07 (bc:24:11:b0:07:01)',
        '2  02:11:58.109  10.20.20.66  → 255.255.255.255  DHCP  Offer 10.20.20.141 · passerelle 10.20.20.66 · DNS 10.20.20.66 · bail 60 s',
        '3  02:11:58.112  0.0.0.0      → 255.255.255.255  DHCP  Request 10.20.20.141 (serveur 10.20.20.66)',
        '4  02:11:58.113  10.20.20.66  → 255.255.255.255  DHCP  Ack 10.20.20.141',
        '5  02:11:58.117  10.20.20.254 → 10.20.10.5       DHCP  Discover relayé par r1',
        '6  02:11:58.121  0.0.0.0      → 255.255.255.255  DHCP  Discover · pc-b11',
        '7  02:11:58.139  10.20.10.5   → 10.20.20.254     DHCP  Offer 10.20.20.137 · passerelle 10.20.20.254 · DNS 10.20.10.6',
        '8  02:11:58.140  10.20.20.254 → 255.255.255.255  DHCP  Offer relayé · 10.20.20.137'
      ] }
    },
    intro: [
      { who: 'karim', expr: 'panique', text: 'Les joueurs tombent un par un ! Leur passerelle, c\'est 10.20.20.66. ENCORE cette adresse !' },
      { who: 'nadia', expr: 'sourcil', text: 'Notre fantôme a monté un serveur DHCP. Regarde la capture : qui répond, et qui répond le premier.' }
    ],
    done: [
      { who: 'nadia', expr: 'sourcil', text: 'Une MAC qui commence par b8:27:eb : c\'est un Raspberry Pi. Et le serveur DHCP s\'est annoncé GHOST-PC dès 21:52, sur le port de la table B14.' },
      { who: 'karim', expr: 'inquiet', text: 'B14. La table où personne n\'est inscrit. Celle de la boucle.' }
    ]
  });

  T.add({
    id: 'T30', act: 8, console: 'pc-b07',
    edit: ['sw-rang-b:/etc/nftables.conf'],
    show: ['sw-rang-b:/etc/network/interfaces'],
    bug: [{ file: 'sw-rang-b:/etc/nftables.conf', find: '        iifname != "ens18" udp sport 67 drop\n', replace: '' + '        # à écrire : ne laisser entrer les réponses DHCP que par le lien montant\n' }],
    checks: [
      { label: 'Les PC du rang B reçoivent l\'offre de notre serveur (passerelle 10.20.20.254)', test: function (h) { var l = h.lease('pc-b07'); return { ok: !!l && l.ok && l.via !== 'rogue' && h.ipstr(l.routers[0]) === '10.20.20.254', detail: l && l.ok ? 'passerelle reçue : ' + h.ipstr(l.routers[0]) : (l ? l.detail : '') }; } },
      { label: 'Les PC du rang A aussi', test: function (h) { var l = h.lease('pc-a01'); return { ok: !!l && l.ok && l.via !== 'rogue', detail: l && l.ok ? 'passerelle reçue : ' + h.ipstr(l.routers[0]) : (l ? l.detail : '') }; } },
      { label: 'Le rang B joint srv-jeu', test: function (h) { return h.ping('pc-b07', 'srv-jeu'); } }
    ],
    hints: [
      { title: 'Filtrer le DHCP sur le commutateur', course: C.dhcp, body: 'DHCP n\'authentifie rien : la protection vient du commutateur, qui ne laisse entrer les réponses de serveur (UDP port source 67) que par le port de confiance, celui d\'où vient le vrai serveur. Sur un pont Linux, une règle nftables dans une table de la famille bridge, chaîne forward : « iifname != "<lien montant>" udp sport 67 drop ».' },
      { title: 'La solution', body: 'Dans la chaîne forward de la table bridge de sw-rang-b : « iifname != "ens18" udp sport 67 drop ».' }
    ],
    intro: [
      { who: 'nadia', expr: 'sourcil', text: 'Le Pi est quelque part sous une table. En attendant de le trouver, on le rend muet.' },
      { who: 'nadia', expr: 'neutre', text: 'Ouvre /etc/nftables.conf de sw-rang-b : j\'ai préparé la table, il manque la règle.' }
    ],
    done: [{ who: 'karim', expr: 'soulage', text: 'Les joueurs reprennent leurs adresses, les bonnes. Le quart de finale continue. 2-2.' }]
  });

  T.add({
    id: 'T31', act: 8,
    views: {
      authlog: { title: 'srv-jeu · /var/log/auth.log · filtre « 10.20.20.66 »', lines: [
        '03:04:51 srv-jeu sshd[2290]: Failed password for root from 10.20.20.66 port 50311 ssh2',
        '03:04:53 srv-jeu sshd[2292]: Invalid user admin from 10.20.20.66 port 50324',
        '03:04:53 srv-jeu sshd[2292]: Failed password for invalid user admin from 10.20.20.66 port 50324 ssh2',
        '03:04:55 srv-jeu sshd[2294]: Failed password for karim from 10.20.20.66 port 50331 ssh2',
        '03:04:58 srv-jeu sshd[2294]: Disconnecting authenticating user karim 10.20.20.66 port 50331: Too many authentication failures',
        '03:05:01 srv-jeu sshd[2301]: Failed password for root from 10.20.20.66 port 50340 ssh2',
        '03:05:04 srv-jeu sshd[2303]: Failed password for karim from 10.20.20.66 port 50347 ssh2',
        '03:05:06 srv-jeu sshd[2303]: Accepted password for karim from 10.20.20.66 port 50347 ssh2',
        '03:05:06 srv-jeu sshd[2303]: pam_unix(sshd:session): session opened for user karim(uid=1001)',
        '03:05:41 srv-jeu sshd[2310]: Failed password for root from 10.20.20.66 port 50362 ssh2'
      ] }
    },
    intro: [{ who: 'karim', expr: 'panique', text: 'Les scores de la poule B ont changé ! Les Pingouins ont perdu un match qu\'ils avaient gagné !' }],
    done: [
      { who: 'nadia', expr: 'sourcil', text: 'Une force brute depuis le Pi, et le mot de passe de Karim a cédé. Il faut fermer la porte aux mots de passe, tout de suite.' },
      { who: 'karim', expr: 'inquiet', text: 'Je change mon mot de passe. Et je ne prends plus le nom du club.' }
    ]
  });

  T.add({
    id: 'T32', act: 8, console: 'regie',
    edit: ['srv-jeu:/etc/ssh/sshd_config.d/50-cloud-init.conf', 'srv-jeu:/etc/ssh/sshd_config.d/60-tournoi.conf'],
    show: ['srv-jeu:/etc/ssh/sshd_config'],
    bug: [{ file: 'srv-jeu:/etc/ssh/sshd_config.d/50-cloud-init.conf', find: 'PasswordAuthentication no', replace: 'PasswordAuthentication yes' }],
    checks: [
      { label: 'Une connexion par mot de passe est refusée (compte karim)', test: function (h) { var r = h.ssh('regie', 'srv-jeu', 'karim', 'password'); return { ok: !r.ok, detail: r.detail }; } },
      { label: 'Nadia se connecte avec sa clé', test: function (h) { return h.ssh('regie', 'srv-jeu', 'nadia', 'key'); } },
      { label: 'root ne se connecte pas, même avec une clé', test: function (h) { var r = h.ssh('regie', 'srv-jeu', 'root', 'key'); return { ok: !r.ok, detail: 'root se connecte' }; } }
    ],
    hints: [
      { title: 'L\'ordre des fichiers de sshd', course: C.ssh, body: 'sshd_config commence par « Include /etc/ssh/sshd_config.d/*.conf » ; les fichiers sont lus par ordre alphabétique, et pour chaque mot-clé, la PREMIÈRE valeur lue l\'emporte. Un 50-cloud-init.conf passe donc avant un 60-… : on nomme son durcissement 10-… , ou on corrige le fichier de cloud-init.' },
      { title: 'La solution', body: 'Dans 50-cloud-init.conf, mets « PasswordAuthentication no » (ou supprime la ligne).' }
    ],
    intro: [{ who: 'nadia', expr: 'sourcil', text: 'Mon durcissement est écrit, et sshd l\'ignore. Je déteste quand un fichier ment. Depuis la régie, essaie : « ssh karim@srv-jeu ».' }],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Clés seulement, root interdit. Notre fantôme peut deviner tous les mots de passe qu\'il veut, maintenant.' }]
  });
})(BlackoutTickets);
