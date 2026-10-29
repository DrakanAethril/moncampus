/* Black-out - acte 9 : L'incident.
   The game half of the tickets: bug, checks, views to designate, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte9.yaml. */
(function (T) {
  'use strict';
  var PARENT = { src: '198.51.100.40' };

  T.add({
    id: 'T33', act: 9,
    intro: [
      { who: 'roussel', expr: 'severe', text: 'Le surveillant l\'a trouvé sous la table B14. Un petit boîtier, une prise réseau, un chargeur de téléphone.' },
      { who: 'nadia', expr: 'neutre', text: 'Ne touche à rien. On réfléchit avant.' }
    ],
    done: [{ who: 'nadia', expr: 'neutre', text: 'Isoler, conserver, transmettre. Ce n\'est pas à nous de juger. C\'est à nous de ne rien abîmer.' }]
  });

  T.add({
    id: 'T34', act: 9,
    views: {
      conntrack: { title: 'r1 · conntrack -L · source 10.20.20.66 (03:00 → 03:20)', lines: [
        'udp  src=10.20.20.66 dst=10.20.10.6 sport=41022 dport=53 [ASSURED]',
        'tcp  ESTABLISHED src=10.20.20.66 dst=10.20.10.10 sport=50347 dport=22 [ASSURED]',
        'udp  src=10.20.20.66 dst=198.51.100.23 sport=51820 dport=51820 [ASSURED] · 14 Mo envoyés',
        'tcp  TIME_WAIT src=10.20.20.66 dst=10.20.10.10 sport=50362 dport=22',
        'icmp src=10.20.20.66 dst=10.20.20.254 type=8 code=0'
      ] }
    },
    intro: [{ who: 'nadia', expr: 'sourcil', text: 'Le Pi est dans une enveloppe. Mais avant d\'y entrer, il a eu le temps de parler. À qui ?' }],
    done: [
      { who: 'nadia', expr: 'sourcil', text: 'Un tunnel vers l\'extérieur, quatorze mégaoctets envoyés. Le fantôme pilotait son Pi de chez lui.' },
      { who: 'karim', expr: 'inquiet', text: 'Donc ce n\'est pas forcément un joueur. Ça me soulage… et ça m\'inquiète.' }
    ]
  });

  T.add({
    id: 'T35', act: 9,
    intro: [{ who: 'karim', expr: 'inquiet', text: 'Les arbitres ont gardé les feuilles de match sur papier. Heureusement que certains ne font pas confiance aux ordinateurs.' }],
    done: [{ who: 'karim', expr: 'soulage', text: 'srv-jeu est propre, les vrais scores sont revenus. Les Pingouins ont retrouvé leur victoire. Je ne suis toujours pas content, mais c\'est juste.' }]
  });

  T.add({
    id: 'T36', act: 9, console: 'regie',
    edit: ['sw-rang-b:/etc/network/interfaces'],
    show: ['sw-rang-b:/etc/nftables.conf'],
    bug: [{ file: 'sw-rang-b:/etc/network/interfaces', find: 'bridge-ports ens18 ens19 ens31 ens33', replace: 'bridge-ports ens18 ens19 ens31 ens32 ens33' }],
    checks: [
      { label: 'La prise de la table B14 est coupée : le Pi n\'atteint plus rien', test: function (h) { var r = h.ping('pi-b14', '10.20.20.254'); return { ok: !r.ok, detail: 'le Pi joint encore r1' }; } },
      { label: 'Les tables B13 et B15 restent en service', test: function (h) { return { ok: h.port('sw-rang-b', 'ens31') && h.port('sw-rang-b', 'ens33'), detail: 'une prise voisine a été coupée' }; } },
      { label: 'Les trois rangs joignent srv-jeu', test: function (h) { var a = h.ping('pc-a01', 'srv-jeu'), b = h.ping('pc-b07', 'srv-jeu'), c = h.ping('pc-c12', 'srv-jeu'); return { ok: a.ok && b.ok && c.ok, detail: !a.ok ? 'rang A : ' + a.detail : (!b.ok ? 'rang B : ' + b.detail : 'rang C : ' + c.detail) }; } },
      { label: 'Le tableau bracket.tournoi.lan répond', test: function (h) { return h.resolve('pc-a01', 'bracket.tournoi.lan'); } },
      { label: 'Les familles s\'inscrivent depuis chez elles', test: function (h) { return h.https('internet', 'inscriptions.tournoi.example', PARENT); } },
      { label: 'Les arbitres, sur place et à distance, joignent srv-jeu', test: function (h) { var a = h.ping('arbitre', 'srv-jeu'), b = h.ping('arbitre-distant', '10.20.10.10'); return { ok: a.ok && b.ok, detail: !a.ok ? a.detail : b.detail }; } },
      { label: 'srv-jeu refuse toujours les mots de passe', test: function (h) { var r = h.ssh('regie', 'srv-jeu', 'karim', 'password'); return { ok: !r.ok, detail: r.detail }; } }
    ],
    hints: [
      { title: 'Couper un port', body: 'Sur un pont Linux, un port ne fait partie du commutateur que s\'il est listé dans bridge-ports. L\'en retirer coupe la prise sans toucher aux autres ; on peut garder sa déclaration plus bas, avec un commentaire, pour la remettre plus tard.' },
      { title: 'La solution', body: 'Sur sw-rang-b : « bridge-ports ens18 ens19 ens31 ens33 », sans ens32.' }
    ],
    intro: [
      { who: 'narr', text: '05:30. Le jour se lève sur le gymnase. Deux cents joueurs dorment sur des tapis de sol.' },
      { who: 'roussel', expr: 'neutre', text: 'Dernière ligne droite. Un réseau propre, et un rapport. Ensuite, vous irez dormir.' }
    ],
    done: [
      { who: 'karim', expr: 'soulage', text: 'Team Kitsune contre les Pingouins, troisième manche, en direct. On y est. Merci. Vraiment.' },
      { who: 'lou', expr: 'joie', text: 'Mille deux cents spectateurs ! Et un chat qui t\'appelle « l\'alternant·e de l\'ombre ». Tu as un fan-club.' },
      { who: 'nadia', expr: 'sourire', text: 'Une nuit entière, trente-six tickets, et pas une réparation à la main sans fichier. Je signe ton rapport.' }
    ]
  });
})(BlackoutTickets);
