/* Black-out - acte 7 : Les invités et les absents.
   The game half of the tickets: bug, checks, written hints, scenes; the server half,
   with the right answers, is config/serious_games/blackout/tickets/acte7.yaml. */
(function (T) {
  'use strict';

  T.add({
    id: 'T25', act: 7, console: 'invite',
    edit: ['r1:/etc/nftables.conf', 'ap-salle:/etc/hostapd/hostapd.conf'],
    bug: [
      { file: 'r1:/etc/nftables.conf', find: '        iifname "ens19.60" oifname "ens18" accept\n', replace: '        iifname "ens19.60" accept\n' },
      { file: 'ap-salle:/etc/hostapd/hostapd.conf', find: 'ap_isolate=1', replace: 'ap_isolate=0' }
    ],
    checks: [
      { label: 'Un invité va sur Internet', test: function (h) { return h.ping('invite', '203.0.113.80'); } },
      { label: 'Il ne joint pas les PC des joueurs', test: function (h) { var r = h.ping('invite', 'pc-a01'); return { ok: !r.ok, detail: 'l\'invité joint pc-a01' }; } },
      { label: 'Il ne joint pas la régie', test: function (h) { var r = h.ping('invite', 'regie'); return { ok: !r.ok, detail: 'l\'invité joint la régie' }; } },
      { label: 'Il ne joint pas un autre invité', test: function (h) { var r = h.ping('invite', 'invite2'); return { ok: !r.ok, detail: 'les deux invités se voient' }; } }
    ],
    hints: [
      { title: 'Un réseau invité', body: 'Un réseau invité ne mène qu\'à Internet. Deux verrous : le pare-feu, qui n\'accepte du VLAN invités que ce qui sort vers Internet (oifname "ens18") ; et le point d\'accès, qui empêche les clients d\'un même Wi-Fi de se parler (ap_isolate=1 dans hostapd).' },
      { title: 'La solution', body: 'Sur r1 : « iifname "ens19.60" oifname "ens18" accept ». Dans hostapd.conf : « ap_isolate=1 ».' }
    ],
    intro: [
      { who: 'karim', expr: 'inquiet', text: 'Le Wi-Fi des familles est ouvert. Et un ado de douze ans s\'amuse à lister nos PC depuis son téléphone.' },
      { who: 'nadia', expr: 'neutre', text: 'Connecte-toi sur le téléphone d\'un invité, dans la console. Essaie d\'aller partout.' }
    ],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Internet, et rien d\'autre. C\'est ce qu\'on doit à un invité.' }]
  });

  T.add({
    id: 'T26', act: 7,
    intro: [{ who: 'enzo', expr: 'sourire', text: 'Salut ! Rien de grave, juste un petit service.' }],
    done: [
      { who: 'karim', expr: 'inquiet', text: 'Je viens de vérifier la liste. Il n\'y a pas d\'Enzo au rang B. Pas d\'Enzo du tout, d\'ailleurs.' },
      { who: 'nadia', expr: 'sourcil', text: 'Quelqu\'un a essayé d\'obtenir un accès en se faisant passer pour un joueur. On appelle ça de l\'ingénierie sociale. Ça marche plus souvent qu\'une attaque.' }
    ]
  });

  T.add({
    id: 'T27', act: 7, console: 'arbitre',
    edit: ['ap-salle:/etc/hostapd/hostapd.conf'],
    show: ['srv-dhcp:/etc/freeradius/3.0/clients.conf', 'srv-dhcp:/etc/freeradius/3.0/mods-config/files/authorize'],
    bug: [{ file: 'ap-salle:/etc/hostapd/hostapd.conf', find: 'auth_server_shared_secret=Rad1us-Arena-2026', replace: 'auth_server_shared_secret=Rad1us-Arena-2025' }],
    checks: [
      { label: 'Un arbitre se connecte au Wi-Fi des arbitres', test: function (h) { return h.wifi('arbitre'); } },
      { label: 'Il joint srv-jeu', test: function (h) { return h.ping('arbitre', 'srv-jeu'); } },
      { label: 'Un compte inconnu est toujours refusé', test: function (h) { var r = h.radius('inconnu', 'motdepasse'); return { ok: !r.ok, detail: 'un compte inconnu est accepté' }; } }
    ],
    hints: [
      { title: '802.1X et RADIUS', body: 'Avec WPA-EAP, le point d\'accès ne connaît aucun mot de passe : il transmet l\'identité de l\'arbitre au serveur RADIUS (freeradius), qui accepte ou refuse. Le point d\'accès et le serveur se reconnaissent par un secret partagé, écrit des deux côtés : auth_server_shared_secret dans hostapd, secret dans clients.conf.' },
      { title: 'La solution', body: 'Dans hostapd.conf : « auth_server_shared_secret=Rad1us-Arena-2026 », le même que dans clients.conf.' }
    ],
    intro: [
      { who: 'karim', expr: 'panique', text: 'Les arbitres ne peuvent plus saisir les scores ! Ils sont là, tablette en main, et rien.' },
      { who: 'nadia', expr: 'neutre', text: 'Le Wi-Fi des arbitres passe par un serveur RADIUS. Compare ce que dit le point d\'accès et ce qu\'attend le serveur.' }
    ],
    done: [{ who: 'nadia', expr: 'neutre', text: 'Un secret partagé, ça se change des deux côtés à la fois. Toujours.' }]
  });

  T.add({
    id: 'T28', act: 7, console: 'arbitre-distant',
    edit: ['r1:/etc/wireguard/wg0.conf'],
    show: ['arbitre-distant:/etc/wireguard/arbitre.pub', 'arbitre-distant:/etc/wireguard/wg0.conf'],
    bug: [{ file: 'r1:/etc/wireguard/wg0.conf', find: 'PublicKey = ArbPubHw2Lq9Xt7Rn4Mk1Vs8Zc5Yb3Ju6Dg0Fe2Ka=', replace: 'PublicKey = R1pubVg9aXcT4mZq7LwE2bN8sK5yH3jD6fU1oP0rQ=' }],
    checks: [
      { label: 'L\'arbitre, chez lui, joint srv-jeu par le tunnel', test: function (h) { return h.ping('arbitre-distant', '10.20.10.10'); } },
      { label: 'Le reste de son trafic ne passe pas par le lycée', test: function (h) { return { ok: h.routeDev('arbitre-distant', '203.0.113.80') === 'wan', detail: 'tout son Internet passe par le tunnel' }; } }
    ],
    hints: [
      { title: 'Les clés de WireGuard', body: 'Chaque côté a une clé privée, qui ne le quitte jamais, et une clé publique, qu\'il donne à l\'autre. Dans [Peer], on écrit la clé publique DE L\'AUTRE. AllowedIPs dit quelles adresses passent par le tunnel : chez l\'arbitre, seulement srv-jeu.' },
      { title: 'La solution', body: 'Dans le [Peer] de wg0.conf sur r1 : « PublicKey = » suivi de la clé de arbitre.pub.' }
    ],
    intro: [
      { who: 'karim', expr: 'inquiet', text: 'L\'arbitre en chef est malade, chez lui. Sans lui, aucun résultat n\'est officiel.' },
      { who: 'nadia', expr: 'sourcil', text: 'J\'ai monté le côté r1 en cinq minutes. Trop vite, sans doute. Tape « wg » sur r1 et sur le poste de l\'arbitre.' }
    ],
    done: [{ who: 'nadia', expr: 'sourire', text: 'Je me suis trompée de clé, et tu l\'as vu. C\'est exactement pour ça qu\'on travaille à deux.' }]
  });
})(BlackoutTickets);
