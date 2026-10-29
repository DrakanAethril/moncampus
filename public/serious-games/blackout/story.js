/* Black-out — the story between the tickets: the prologue, the announcement of each act, the end.
   A line is { who, expr, text }; who 'narr' is the broadcast's caption (no portrait). */
(function (root) {
  'use strict';
  root.BlackoutStory = {
    prologue: [
      { who: 'narr', text: 'Vendredi, 21 h 46. La LAN du lycée : 48 heures de tournoi, deux cents joueurs, le quart de finale en direct.' },
      { who: 'karim', expr: 'joie', text: 'Team Kitsune contre les Pingouins, un partout, dernière manche. Lou, on est en direct ? On est en direct !' },
      { who: 'lou', expr: 'joie', text: 'Huit cents spectateurs sur le stream, Karim. Huit cents ! Ne dis pas de bêtise.' },
      { who: 'narr', text: '21 h 47. Coupure de courant.', cut: true },
      { who: 'karim', expr: 'panique', text: 'Non. Non non non non. Pas maintenant !' },
      { who: 'narr', text: 'Le courant revient au bout de trois minutes. Le réseau, non.' },
      { who: 'nadia', expr: 'neutre', text: 'Bonsoir. Nadia, du prestataire réseau. C\'est moi qu\'on appelle quand ça ne repart pas. Et toi, tu es mon alternant·e pour la nuit.' },
      { who: 'nadia', expr: 'sourcil', text: 'Le type qui a monté ce réseau est parti l\'an dernier sans rien laisser. Pas de schéma à jour, pas de documentation. On va tout découvrir ensemble.' },
      { who: 'nadia', expr: 'neutre', text: 'Les demandes arrivent dans le helpdesk. Tu en prends quatre au plus à la fois. On répare, on explique, on ferme. Et on ne touche à rien sans savoir pourquoi.' }
    ],
    epilogue: [
      { who: 'narr', text: 'Lundi, 8 h 10. Le bureau de la proviseure adjointe.' },
      { who: 'roussel', expr: 'neutre', text: 'J\'ai remis le boîtier et votre rapport à qui de droit. Je ne vous dirai pas tout. Mais je peux vous dire ceci.' },
      { who: 'roussel', expr: 'severe', text: 'L\'adresse du tunnel menait chez quelqu\'un que Karim connaît très bien. Quelqu\'un qui avait monté ce réseau, l\'an dernier, et qui n\'avait pas apprécié qu\'on prenne un prestataire pour le remplacer.' },
      { who: 'karim', expr: 'inquiet', text: 'L\'ancien bénévole. Il connaissait la table B14, le STP, les mots de passe… Il connaissait tout.' },
      { who: 'nadia', expr: 'neutre', text: 'Il connaissait le réseau d\'hier. Pas celui qu\'on a reconstruit cette nuit.' },
      { who: 'roussel', expr: 'satisfaite', text: 'Ce n\'est pas une adresse MAC qui l\'a désigné, c\'est un dossier propre, des preuves intactes, une chronologie. Le vôtre. Merci.' },
      { who: 'narr', text: 'Team Kitsune a gagné la finale, 3 à 2. Les Pingouins ont demandé une revanche. Elle aura lieu l\'an prochain, sur un réseau documenté.' }
    ],
    grades: [[0, 'Alternant·e'], [9, 'Technicien·ne réseau'], [18, 'Administrateur·rice réseau'], [27, 'Analyste sécurité'], [36, 'Régisseur·se de la nuit']],
    acts: {
      1: { time: '21:50', blurb: 'Le courant est revenu, le réseau non. Tout part du local technique : le routeur, ses interfaces, ses adresses.', notions: ['Interfaces', 'Adressage IPv4', 'Masque', 'Passerelle'] },
      2: { time: '22:20', blurb: 'Les joueurs d\'un côté, les organisateurs de l\'autre, le stream à part. Et un rang tout neuf à brancher.', notions: ['VLAN', 'Port d\'accès', 'Trunk', 'STP'] },
      3: { time: '23:00', blurb: 'Chaque VLAN est un réseau. Pour qu\'ils se parlent, il faut un routeur qui veuille bien faire suivre, et des routes dans les deux sens.', notions: ['Routage', 'ip_forward', 'Routes statiques', 'Route par défaut'] },
      4: { time: '23:20', blurb: 'Le rang C est branché, mais personne ne lui donne d\'adresse. Et ceux qui en ont une ne trouvent plus les serveurs par leur nom.', notions: ['DHCP et bail', 'Agent relais', 'Réservations', 'DNS'] },
      5: { time: '23:45', blurb: 'Les parents s\'inscrivent pour la finale, le stream doit sortir. Ouvrir sur Internet, oui ; tout ouvrir, non.', notions: ['NAT', 'Redirection de port', 'DMZ', 'Certificats'] },
      6: { time: '00:15', blurb: 'Le réseau marche. Maintenant, il faut qu\'il ne laisse passer que ce qu\'on a décidé.', notions: ['nftables', 'Première règle', 'Filtrage avec état', 'Entre VLAN'] },
      7: { time: '01:00', blurb: 'Les invités veulent du Wi-Fi, les arbitres un accès sûr, et l\'un d\'eux est resté chez lui.', notions: ['Wi-Fi invités', '802.1X', 'RADIUS', 'WireGuard'] },
      8: { time: '02:00', blurb: 'Le Ghost ne se cache plus.', notions: ['DHCP pirate', 'Filtrage sur le commutateur', 'Force brute SSH', 'sshd'] },
      9: { time: '03:15', blurb: 'On a trouvé le boîtier. Il reste à remettre le serveur d\'aplomb, garder les preuves et écrire le rapport.', notions: ['Preuves', 'Journaux', 'Sauvegardes', 'Chronologie'] }
    }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
