/* Black-out - acte 6 : Le pare-feu.
   The game half of the tickets: bug, checks, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte6.yaml. */
(function (T) {
  'use strict';

  var FIRST_MATCH = 'nftables lit les règles d\'une chaîne de haut en bas et s\'arrête à la première qui correspond (accept ou drop). Si aucune ne correspond, la politique de la chaîne décide. L\'ordre des règles est donc toute la logique du pare-feu.';

  T.add({
    id: 'T21', act: 6, console: 'pc-a01',
    edit: ['r1:/etc/nftables.conf'],
    bug: [{ file: 'r1:/etc/nftables.conf', find: '        iifname "ens19.10" accept\n', replace: '        iifname "ens19.*" accept\n' }],
    checks: [
      { label: 'Les joueurs ne joignent plus la régie', test: function (h) { var r = h.ping('pc-a01', 'regie'); return { ok: !r.ok, detail: 'pc-a01 joint la régie' }; } },
      { label: 'Ils joignent toujours srv-jeu', test: function (h) { return h.ping('pc-a01', 'srv-jeu'); } },
      { label: 'La régie joint toujours Internet', test: function (h) { return h.ping('regie', '203.0.113.80'); } }
    ],
    hints: [
      { title: 'La première règle qui correspond', body: FIRST_MATCH },
      { title: 'La solution', body: 'Remplace « iifname "ens19.*" accept » par « iifname "ens19.10" accept » : seuls les orgas vont partout.' }
    ],
    intro: [
      { who: 'nadia', expr: 'neutre', text: 'Pare-feu en place : chacun ne va que là où il doit. En théorie.' },
      { who: 'karim', expr: 'inquiet', text: 'En pratique, un joueur vient de m\'envoyer une capture de nos fiches d\'inscription.' }
    ],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Un caractère joker dans un pare-feu, c\'est toujours une porte qui s\'ouvre plus grand que prévu.' }]
  });

  T.add({
    id: 'T22', act: 6, console: 'pc-a01',
    edit: ['r1:/etc/nftables.conf'],
    bug: [{ file: 'r1:/etc/nftables.conf', find: '        ct state established,related accept\n', replace: '' + '        # (règle supprimée « pour faire le ménage »)\n' }],
    checks: [
      { label: 'Les joueurs joignent Internet', test: function (h) { return h.ping('pc-a01', '203.0.113.80'); } },
      { label: 'Le stream joint Internet', test: function (h) { return h.ping('encodeur', '203.0.113.80'); } },
      { label: 'Les joueurs ne joignent toujours pas la régie', test: function (h) { var r = h.ping('pc-a01', 'regie'); return { ok: !r.ok, detail: 'pc-a01 joint la régie' }; } }
    ],
    hints: [
      { title: 'Un pare-feu à états', body: 'nftables suit les connexions (conntrack) : un paquet qui répond à une connexion déjà acceptée est « established ». Une seule règle en tête de chaîne, « ct state established,related accept », laisse revenir toutes les réponses, sans ouvrir aucun port.' },
      { title: 'La solution', body: 'Remets « ct state established,related accept » en première règle de la chaîne forward.' }
    ],
    intro: [
      { who: 'karim', expr: 'panique', text: 'Plus d\'Internet, encore ! Quelqu\'un a fait « le ménage » dans le pare-feu. Je vais interdire le ménage.' },
      { who: 'nadia', expr: 'neutre', text: 'Ping vers Internet depuis un joueur. Regarde où meurt la réponse, pas la question.' }
    ],
    done: [{ who: 'nadia', expr: 'neutre', text: 'Cette ligne-là, on ne la supprime jamais. Retiens-la par cœur.' }]
  });

  T.add({
    id: 'T23', act: 6, console: 'srv-jeu',
    edit: ['r1:/etc/nftables.conf'],
    bug: [{ file: 'r1:/etc/nftables.conf', find: '        oifname "ens19.99" drop\n', replace: '' + '        # (règle de refus vers ens19.99 effacée)\n' }],
    checks: [
      { label: 'La régie joint sw-core (10.20.99.1)', test: function (h) { return h.ping('regie', '10.20.99.1'); } },
      { label: 'srv-jeu n\'atteint pas l\'administration des commutateurs', test: function (h) { var r = h.ping('srv-jeu', '10.20.99.1'); return { ok: !r.ok, detail: 'srv-jeu joint sw-core' }; } },
      { label: 'Les joueurs non plus', test: function (h) { var r = h.ping('pc-a01', '10.20.99.1'); return { ok: !r.ok, detail: 'pc-a01 joint sw-core' }; } }
    ],
    hints: [
      { title: 'Autoriser, puis refuser', body: FIRST_MATCH + ' Pour réserver un accès : d\'abord la règle qui autorise la seule source permise, puis une règle qui refuse tout le reste vers la même destination, et seulement ensuite les règles générales.' },
      { title: 'La solution', body: 'Juste après « ip saddr 10.20.10.130 oifname "ens19.99" accept », remets « oifname "ens19.99" drop ».' }
    ],
    intro: [{ who: 'nadia', expr: 'sourcil', text: 'Je suis sur srv-jeu, et je joins l\'administration de sw-core. Ça ne devrait pas être possible.' }],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Seule la régie administre. Si un serveur tombe cette nuit, il tombera seul.' }]
  });

  T.add({
    id: 'T24', act: 6,
    intro: [{ who: 'roussel', expr: 'neutre', text: 'Une demande de monsieur le proviseur. Je préfère vous la soumettre avant de dire oui.' }],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Bien. Et tant mieux : on monte justement un VPN pour l\'arbitre resté chez lui.' }]
  });
})(BlackoutTickets);
