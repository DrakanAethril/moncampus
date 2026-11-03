/* Fusion — the story, on the game's side: the weeks of the acts, their announcements, the prologue and
   the epilogue (scenes), and the grades. The platform holds the mystery (the five clues); the words
   around it are here. */
(function (root) {
  'use strict';
  root.FSStory = {
    acts: {
      1: { time: 'Semaines 1 et 2', dates: '12 – 23 octobre', blurb: 'Deux cabinets, deux réseaux, deux domaines, et un trimestre. Avant de toucher à quoi que ce soit, on regarde : ce qui existe, ce qui dépend de quoi, et ce que Julien a déjà fait.', notions: ['inventaire', 'adresses fixes', 'dépendances', 'premier plan'] },
      2: { time: 'Semaines 3 à 5', dates: '26 octobre – 13 novembre', blurb: 'Les deux sites portent le même réseau. On dessine un plan d\'adressage, on relie les sites par un tunnel, et on renumérote Brive en une nuit, sans que lundi personne ne s\'en aperçoive.', notions: ['sous-réseaux', 'NAT', 'IPsec', 'DHCP', 'décommission'] },
      3: { time: 'Semaines 6 à 8', dates: '16 novembre – 4 décembre', blurb: 'Un poste de Limoges ne trouve rien à Brive : il demande à son DNS, qui ne connaît pas kerbrat.lan. Redirecteurs, TTL, approbation, puis un seul domaine pour tout le monde.', notions: ['DNS', 'redirecteurs', 'TTL', 'approbation', 'cycle de vie des comptes'] },
      4: { time: 'Semaines 9 et 10', dates: '7 – 18 décembre', blurb: 'Les fichiers des deux cabinets vont sur un seul partage. Avant de les copier, on se demande qui doit les lire. Et on découvre qui pouvait déjà.', notions: ['ACL et partages', 'AGDLP', 'groupes imbriqués', 'audit', 'recette'] },
      5: { time: 'Semaines 11 et 12', dates: '21 – 31 décembre', blurb: 'Il reste la messagerie, que Julien croyait réglée. Le suivi des messages raconte ce que personne n\'a regardé en septembre.', notions: ['MX, SPF, DMARC', 'suivi des messages', 'règles de boîte', 'preuves'] }
    },
    prologue: [
      { who: 'narr', text: 'Lundi 12 octobre 2026. L\'Atelier Delorme, à Limoges, et Kerbrat Architectes, à Brive, ont annoncé leur fusion le 1er. Au 1er janvier, ils seront Delorme Kerbrat Architectes.' },
      { who: 'julien', expr: 'sourire', text: 'Un seul cabinet, une seule équipe, un seul réseau. Le 2 janvier, je veux que tout le monde ouvre les mêmes dossiers. C\'est simple, non ?' },
      { who: 'maelle', expr: 'sourcil', text: 'Simple. Nos deux informatiques ont été montées par deux prestataires qui ne se sont jamais parlé. Je veux surtout que rien ne se perde en route.' },
      { who: 'helene', expr: 'sourire', text: 'Hélène, d\'Arpège Informatique, cheffe de projet. Et ta tutrice pour ce trimestre. On va fusionner deux systèmes d\'information, migration par migration.' },
      { who: 'helene', expr: 'neutre', text: 'Chaque migration aura son plan de bascule : une fenêtre, des opérations dans l\'ordre, des contrôles, un retour arrière. Et une maquette sur la table, pour voir où on en est.' }
    ],
    epilogue: [
      { who: 'narr', text: 'Jeudi 31 décembre, 23 h 40. Le MX de kerbrat-architectes.fr pointe sur Exchange, l\'ancien hébergeur est coupé, les transferts vers l\'extérieur sont bloqués. La dernière planche de la passerelle est posée.' },
      { who: 'maelle', expr: 'neutre', text: 'J\'ai appelé Bastien. Il recevait nos mails depuis septembre. Il a cru à une erreur de liste de diffusion, il les a ouverts, il en a parlé à son nouvel employeur. Il est désolé. Varenne a retiré son esquisse.' },
      { who: 'bastien', expr: 'neutre', text: '« Cette redirection, je l\'avais mise pour suivre le concours pendant mon congé, en 2024. L\'hébergeur la bloquait, je l\'avais oubliée. Je n\'aurais pas dû les ouvrir. »' },
      { who: 'julien', expr: 'sourcil', text: 'Et elle s\'est réveillée le soir où j\'ai importé les boîtes, tout seul, sans plan. La seule migration qu\'on n\'a pas faite ensemble.' },
      { who: 'helene', expr: 'sourire', text: 'Une migration emporte tout ce qu\'on n\'a pas inventorié. Tu as fait les autres avec un plan, un contrôle et un retour arrière. Bonne année, et bienvenue chez Delorme Kerbrat Architectes.' }
    ],
    grades: [[0, 'Alternant·e'], [10, 'Technicien·ne'], [20, 'Intégrateur·rice'], [30, 'Chef·fe de bascule'], [40, 'Architecte du SI']]
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
