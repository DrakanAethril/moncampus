/* Rétro Rush — the story between the tickets: the prologue, the announcement of each act, the end.
   A line is { who, expr, text }; who 'narr' is the caption (no portrait). */
(function (root) {
  'use strict';
  root.RetroStory = {
    prologue: [
      { who: 'narr', text: 'Lundi 2 novembre, 9 h. « Continue ? », jeux vidéo rétro, achat, vente et dépôt-vente depuis quinze ans.' },
      { who: 'gilles', expr: 'neutre', text: 'Quinze ans que je tiens tout dans mon tableur. Les jeux, les prix, les dépôts, les ventes. Tout. Et ça marchait.' },
      { who: 'gilles', expr: 'furieux', text: 'Sauf que la boutique en ligne ouvre le 1er décembre. Et que mon stock ne tombe JAMAIS juste. Il me manque des jeux. Des Zelda, des Panzer Dragoon…' },
      { who: 'gilles', expr: 'perplexe', text: 'Je ne dis pas que quelqu\'un se sert dans le rayon. Je dis juste que les jeux ne partent pas tout seuls.' },
      { who: 'oceane', expr: 'inquiete', text: 'Vous ne le dites pas, mais vous le pensez. Je suis la seule à tenir la caisse avec vous.' },
      { who: 'jade', expr: 'sourire', text: 'Bonjour ! Jade, développeuse indépendante : c\'est moi qui monte le site. Et je serai ta tutrice pour l\'alternance, surtout en visio et dans les merge requests.' },
      { who: 'jade', expr: 'sourcil', text: 'Première règle de la maison : on ne soupçonne personne. On interroge les données.' },
      { who: 'jade', expr: 'neutre', text: 'Les demandes arrivent dans la forge, quatre issues ouvertes au plus. Tu écris la requête, le pipeline la vérifie, je relis ta merge request, et le demandeur valide.' }
    ],
    epilogue: [
      { who: 'narr', text: 'Mardi 1er décembre, 9 h. La boutique en ligne ouvre.' },
      { who: 'gilles', expr: 'ravi', text: 'Première commande ! Un Panzer Dragoon, en boîte. Celui-là même qu\'on croyait volé.' },
      { who: 'oceane', expr: 'soulagee', text: 'Il n\'avait jamais quitté l\'étagère. C\'est la base qui le faisait disparaître. Deux fois de suite.' },
      { who: 'gilles', expr: 'perplexe', text: 'Océane… je vous dois des excuses. Et un trigger de 2019 me doit une bonne dizaine de cartouches.' },
      { who: 'jade', expr: 'sourire', text: 'Le neveu avait écrit « stock - » là où il fallait « stock + ». Un signe. Des années d\'inventaires faux.' },
      { who: 'jade', expr: 'neutre', text: 'Retiens surtout la méthode : une question, une requête, une preuve. Les données ne soupçonnent personne.' },
      { who: 'narr', text: 'La boutique a fait son meilleur mois. Gilles a gardé son tableur, « au cas où ». Il n\'y a plus rien écrit dedans.' }
    ],
    grades: [[0, 'Alternant·e'], [11, 'Développeur·se junior'], [22, 'Développeur·se SQL'], [33, 'Architecte des données'], [45, 'Gardien·ne du stock']],
    acts: {
      1: { date: 'LUNDI 2 NOV.', blurb: 'Quinze ans de boutique dans une seule feuille de calcul. Avant d\'écrire la moindre requête, il faut comprendre pourquoi un tableur ne suffit plus.', notions: ['Tableur', 'Redondance', 'Schéma relationnel', 'Clés'] },
      2: { date: 'MERCREDI 4 NOV.', blurb: 'Jade a migré le tableur pendant le week-end : neuf tables, et des questions qui tombent toutes seules.', notions: ['SELECT', 'WHERE', 'NULL', 'LIKE', 'ORDER BY'] },
      3: { date: 'VENDREDI 6 NOV.', blurb: 'Le titre est dans Jeu, le prix dans Article, la console dans Console. Pour répondre, il faut tout relier.', notions: ['INNER JOIN', 'LEFT JOIN', 'Plusieurs tables', 'Doublons'] },
      4: { date: 'LUNDI 9 NOV.', blurb: 'Combien, combien en moyenne, combien par console. M. Ferrand a sa calculatrice, et il compte.', notions: ['COUNT', 'SUM', 'AVG', 'GROUP BY', 'HAVING'] },
      5: { date: 'JEUDI 12 NOV.', blurb: 'Les questions de Gilles ne tiennent plus en une seule requête.', notions: ['Sous-requête', 'NOT IN et NULL', 'EXISTS', 'UNION'] },
      6: { date: 'LUNDI 16 NOV.', blurb: 'Lire ne suffit plus : il faut corriger. Et un UPDATE, ça ne se relance pas comme un SELECT.', notions: ['UPDATE', 'INSERT', 'DELETE', 'Clés étrangères'] },
      7: { date: 'VENDREDI 20 NOV.', blurb: 'Ce que la base doit faire toute seule, à chaque vente, sans qu\'on y pense : les triggers.', notions: ['Trigger', 'BEFORE et AFTER', 'NEW et OLD', 'SIGNAL'] },
      8: { date: 'VENDREDI 27 NOV.', blurb: 'Black Friday en boutique, le site en préouverture, deux caisses qui tournent. Tout doit tenir en même temps.', notions: ['Transactions', 'Vues', 'Index', 'Contraintes'] },
      9: { date: 'LUNDI 30 NOV.', blurb: 'Demain, le site ouvre. Il reste à fermer les accès, à respecter les clients… et à trouver le voleur.', notions: ['GRANT', 'Injection SQL', 'RGPD', 'Migration'] }
    }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
