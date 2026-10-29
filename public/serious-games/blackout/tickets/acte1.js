/* Black-out - acte 1 : Le local technique.
   The game half of the tickets: bug, checks, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte1.yaml. */
(function (T) {
  'use strict';
  var C = T.courses;

  T.add({
    id: 'T01', act: 1, console: 'regie',
    edit: ['r1:/etc/network/interfaces'],
    bug: [{ file: 'r1:/etc/network/interfaces', find: 'auto ens19.10\niface ens19.10', replace: 'iface ens19.10' }],
    checks: [
      { label: 'La régie joint sa passerelle, r1 (10.20.10.254)', test: function (h) { return h.ping('regie', '10.20.10.254'); } },
      { label: 'Le rang A joint srv-jeu', test: function (h) { return h.ping('pc-a01', 'srv-jeu'); } },
      { label: 'Le rang B joint srv-jeu', test: function (h) { return h.ping('pc-b07', 'srv-jeu'); } }
    ],
    hints: [
      { title: 'Les interfaces au démarrage', course: C.net02,
        body: 'Au démarrage, Debian monte les interfaces annoncées par une ligne « auto » (ou « allow-hotplug »). Une interface déclarée par « iface » sans « auto » reste éteinte : elle ne monte qu\'à la main, avec ifup, et retombe au redémarrage suivant.' },
      { title: 'La solution', body: 'Ajoute la ligne « auto ens19.10 » juste avant « iface ens19.10 inet static », comme pour les autres VLAN.' }
    ],
    intro: [
      { who: 'karim', expr: 'panique', text: 'Le courant est revenu ! Les PC se rallument, les écrans aussi… mais personne ne joint le serveur de jeu. Rien. Le quart de finale attend !' },
      { who: 'nadia', expr: 'neutre', text: 'Respire, Karim. Tout a redémarré d\'un coup, r1 compris. On commence par lui : c\'est par lui que tout passe.' },
      { who: 'nadia', expr: 'sourcil', text: 'Toi, ouvre la console de la régie et essaie de joindre sa passerelle. Puis regarde le fichier des interfaces de r1.' }
    ],
    done: [
      { who: 'nadia', expr: 'sourire', text: 'r1 est debout. Note bien : quelqu\'un avait « réparé » ça à la main, sans toucher au fichier. Une réparation qui ne survit pas à un redémarrage n\'en est pas une.' },
      { who: 'karim', expr: 'soulage', text: 'Les joueurs voient le serveur ! Bon. Il reste… trois tickets. Pour l\'instant.' }
    ]
  });

  T.add({
    id: 'T02', act: 1, console: 'regie',
    edit: ['imprimante:/etc/network/interfaces'],
    show: ['regie:/etc/network/interfaces'],
    bug: [{ file: 'imprimante:/etc/network/interfaces', find: 'address 10.20.10.20/24', replace: 'address 10.20.10.20/25' }],
    checks: [
      { label: 'La régie joint l\'imprimante (10.20.10.20)', test: function (h) { return h.ping('regie', '10.20.10.20'); } },
      { label: 'L\'imprimante joint sa passerelle, r1 (10.20.10.254)', test: function (h) { return h.ping('imprimante', '10.20.10.254'); } },
      { label: 'L\'imprimante garde son adresse, 10.20.10.20', test: function (h) { return { ok: h.addr('imprimante') === '10.20.10.20', detail: 'l\'imprimante a l\'adresse ' + h.addr('imprimante') }; } }
    ],
    hints: [
      { title: 'Le masque dit qui est « chez moi »', course: C.net02,
        body: 'Une machine compare l\'adresse de destination à la sienne sur la longueur du masque. Si elles coïncident, elle parle directement ; sinon, elle passe par sa passerelle. Avec /25, le réseau 10.20.10.0 s\'arrête à 10.20.10.127 ; tout ce qui est au-delà est « ailleurs ».' },
      { title: 'La solution', body: 'Sur l\'imprimante, remplace /25 par /24 : « address 10.20.10.20/24 ».' }
    ],
    intro: [
      { who: 'karim', expr: 'inquiet', text: 'Les badges ! Si on n\'imprime pas les badges, les joueurs de la finale ne passent pas l\'entrée du gymnase demain matin.' },
      { who: 'nadia', expr: 'neutre', text: 'La régie est en .130, l\'imprimante en .20. Compare leurs fichiers, et fais un ping dans chaque sens.' }
    ],
    done: [
      { who: 'karim', expr: 'soulage', text: 'Elle imprime ! Le premier badge est sorti. Il est un peu de travers, mais c\'est la faute de l\'imprimante, pas du réseau.' },
      { who: 'nadia', expr: 'neutre', text: 'Retiens : deux machines dans le même réseau doivent avoir le même masque. Sinon, chacune voit le monde à sa façon.' }
    ]
  });

  T.add({
    id: 'T03', act: 1, console: 'pc-a01',
    edit: ['srv-jeu:/etc/network/interfaces'],
    show: ['r1:/etc/network/interfaces'],
    bug: [{ file: 'srv-jeu:/etc/network/interfaces', find: 'gateway 10.20.10.254', replace: 'gateway 10.20.10.1' }],
    checks: [
      { label: 'Le rang A joint srv-jeu', test: function (h) { return h.ping('pc-a01', 'srv-jeu'); } },
      { label: 'Le rang B joint srv-jeu', test: function (h) { return h.ping('pc-b07', 'srv-jeu'); } },
      { label: 'La régie joint toujours srv-jeu', test: function (h) { return h.ping('regie', 'srv-jeu'); } }
    ],
    hints: [
      { title: 'La passerelle par défaut', course: C.net05,
        body: 'Tout ce qui n\'est pas dans son propre réseau, une machine l\'envoie à sa passerelle : Internet, mais aussi les autres VLAN. La passerelle est toujours une adresse de son réseau, celle du routeur. Ici, c\'est r1 : 10.20.10.254 dans le VLAN 10.' },
      { title: 'La solution', body: 'Sur srv-jeu, remplace « gateway 10.20.10.1 » par « gateway 10.20.10.254 ».' }
    ],
    intro: [
      { who: 'karim', expr: 'inquiet', text: 'La régie se connecte au serveur de jeu sans problème. Les joueurs, non. Même serveur, même réseau, même câble !' },
      { who: 'nadia', expr: 'sourcil', text: 'Pas même réseau, justement. Lance un ping depuis un PC du rang A, et regarde où il s\'arrête sur la carte.' }
    ],
    done: [
      { who: 'karim', expr: 'soulage', text: 'Les joueurs sont sur le serveur ! Le quart de finale va pouvoir reprendre. Enfin, quand tout le reste marchera.' },
      { who: 'nadia', expr: 'neutre', text: 'Un serveur qui entend mais ne répond pas : c\'est presque toujours une passerelle. Tu le reverras.' }
    ]
  });

  T.add({
    id: 'T04', act: 1,
    intro: [
      { who: 'karim', expr: 'neutre', text: 'Question de plan, cette fois. Pas de panne. Enfin, pas encore.' }
    ],
    done: [
      { who: 'nadia', expr: 'sourire', text: 'Un /25, passerelle en .126, la dernière adresse utilisable. On l\'utilisera dès qu\'on branche le rang C.' }
    ]
  });
})(BlackoutTickets);
