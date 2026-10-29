/* Rétro Rush - acte 6 : Remettre le stock d'aplomb.
   The game half of the tickets; the server half is host/scenario/acte6.js. */
(function (T) {
  'use strict';
  var R = T.ref;

  var SATURN = 'UPDATE Article\nSET prixVente = prixVente * 0.7\nWHERE idJeu IN (\n  SELECT idJeu FROM Jeu\n  WHERE idConsole = (SELECT idConsole FROM Console WHERE nom = \'Saturn\')\n);';
  T.add({
    id: 'R26', act: 6,
    deliver: [{ id: 'soldes', label: 'Les soldes de la Saturn', kind: 'modify', tables: ['Article'], ref: SATURN,
      say: { changed: 'Tous les articles Saturn sont à −30 %', untouched: 'Les autres articles gardent leur prix', hidden: 'Ça marche aussi sur le stock complet, futurs Saturn compris' } }],
    start: { soldes: 'UPDATE Article\nSET prixVente = prixVente * 0.7;' },
    hints: [
      { title: 'UPDATE et sous-requête', ref: R.requetes, body: 'UPDATE Article SET … WHERE idJeu IN (SELECT idJeu FROM Jeu WHERE …)\nLa sous-requête renvoie la liste des jeux visés ; l\'UPDATE ne touche que leurs articles.\nAvant de lancer un UPDATE, écris le SELECT avec le même WHERE et regarde les lignes.' },
      { title: 'Remonter jusqu\'à la console', ref: R.requetes, body: 'Article → Jeu par idJeu, Jeu → Console par idConsole. La console se trouve par son nom : (SELECT idConsole FROM Console WHERE nom = \'Saturn\').' },
      { title: 'La solution', body: SATURN }
    ],
    intro: [
      { who: 'oceane', expr: 'inquiete', text: 'Je l\'ai lancée sur ma copie pour essayer. Tout est à −30 %. Le Gunstar à 63 €, le Tetris à 5,60 €…' },
      { who: 'gilles', expr: 'furieux', text: 'Sur ta copie, heureusement ! Sur la vraie, j\'étais ruiné avant midi.' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Panzer Dragoon à 21 €, Sega Rally à 17,50 €. Et le reste du magasin à son prix. Parfait.' }]
  });

  var ARRIVAGE = 'INSERT INTO Article (idJeu, etat, prixVente, prixAchat, stock, idDeposant)\nVALUES (24, \'loose\', 35, NULL, 2, 6);';
  T.add({
    id: 'R27', act: 6,
    deliver: [{ id: 'arrivage', label: 'L\'arrivage de Camille Roux', kind: 'modify', tables: ['Article'], ref: ARRIVAGE,
      say: { changed: 'Le nouvel article est enregistré : deux Shenmue loose à 35 €, déposés par Camille Roux', untouched: 'Les autres articles n\'ont pas bougé', hidden: 'L\'article est numéroté par la base, pas à la main' } }],
    start: { arrivage: 'INSERT INTO Article (idJeu, etat, prixVente, prixAchat, stock, idDeposant)\nVALUES (24, \'loose\', 35, NULL, 2, 7);' },
    hints: [
      { title: 'INSERT INTO … VALUES', ref: R.requetes, body: 'INSERT INTO table (col1, col2, …) VALUES (val1, val2, …);\nOn ne cite pas idArticle : AUTO_INCREMENT le numérote. Chaque clé étrangère doit désigner une ligne qui existe.' },
      { title: 'La solution', body: ARRIVAGE }
    ],
    done: [{ who: 'oceane', expr: 'soulagee', text: 'Deux Shenmue en rayon, et Camille Roux bien notée comme déposante. Merci.' }]
  });

  var VENTE = 'UPDATE LigneVente SET quantite = 1\nWHERE idVente = 15 AND idArticle = 21;\n\nUPDATE Article SET stock = stock + 2\nWHERE idArticle = 21;';
  T.add({
    id: 'R28', act: 6,
    deliver: [{ id: 'correction', label: 'La correction de la vente', kind: 'modify', tables: ['LigneVente', 'Article'], ref: VENTE,
      say: { changed: 'La vente n° 15 compte un seul Tetris, et les deux autres sont revenus en stock', untouched: 'Aucune autre vente ni aucun autre stock n\'a bougé', hidden: 'Ça tient aussi sur les ventes de plusieurs lignes' } }],
    start: { correction: 'UPDATE LigneVente SET quantite = 1\nWHERE idVente = 15 AND idArticle = 21;' },
    hints: [
      { title: 'Viser une ligne de LigneVente', ref: R.requetes, body: 'Sa clé primaire est (idVente, idArticle) : le WHERE doit nommer les deux colonnes pour ne viser qu\'une ligne.' },
      { title: 'Le stock', ref: R.requetes, body: 'Le stock ne suit pas tout seul : UPDATE Article SET stock = stock + 2 WHERE idArticle = 21;\nDeux instructions dans le même livrable, chacune terminée par un point-virgule.' },
      { title: 'La solution', body: VENTE }
    ],
    done: [
      { who: 'oceane', expr: 'soulagee', text: 'Deux Tetris de retour sur l\'étagère, sur le papier aussi. Une erreur de moins à mon nom.' },
      { who: 'jade', expr: 'neutre', text: 'Retiens le problème : deux UPDATE qui doivent passer ensemble. On en reparlera.' }
    ]
  });

  T.add({
    id: 'R29', act: 6,
    views: {
      erreur: { title: 'La console de Gilles', lines: [
        'mysql> DELETE FROM Article WHERE idArticle = 3;',
        'ERROR 1451 (23000): Cannot delete or update a parent row: a foreign key constraint fails',
        '(`boutique`.`LigneVente`, CONSTRAINT `LigneVente_ibfk_2` FOREIGN KEY (`idArticle`) REFERENCES `Article` (`idArticle`))',
        'mysql> SELECT * FROM LigneVente WHERE idArticle = 3;',
        '+---------+-----------+----------+--------------+',
        '| idVente | idArticle | quantite | prixUnitaire |',
        '+---------+-----------+----------+--------------+',
        '|       6 |         3 |        1 |        25.00 |',
        '+---------+-----------+----------+--------------+'
      ] }
    },
    intro: [
      { who: 'gilles', expr: 'furieux', text: '« Cannot delete or update a parent row. » Je ne suis le parent de personne, moi !' },
      { who: 'jade', expr: 'sourcil', text: 'Si, justement. Lis le message jusqu\'au bout : il dit qui dépend de cet article.' }
    ],
    done: [{ who: 'jade', expr: 'sourire', text: 'Une base ne perd pas son histoire pour ranger une liste. On filtre à l\'affichage, on ne supprime pas.' }]
  });

  var LOOSE = 'UPDATE Article SET prixVente = prixVente + 2\nWHERE etat = \'loose\' AND idDeposant IS NULL;';
  T.add({
    id: 'R30', act: 6,
    deliver: [{ id: 'hausse', label: 'La hausse des loose', kind: 'modify', tables: ['Article'], ref: LOOSE,
      say: { changed: 'Tous les loose de la boutique prennent 2 €', untouched: 'Les articles en dépôt et les jeux en boîte gardent leur prix', hidden: 'Ça marche aussi sur le stock complet' } }],
    start: { hausse: 'UPDATE Article SET prixVente = prixVente + 2\nWHERE etat = \'loose\' AND idDeposant = NULL;' },
    views: { requete: { title: 'La requête d\'Océane', lines: ['UPDATE Article SET prixVente = prixVente + 2', 'WHERE etat = \'loose\'', '  AND idDeposant = NULL;'] } },
    hints: [
      { title: 'NULL, encore', ref: R.requetes, body: 'Dans un UPDATE comme dans un SELECT, on ne compare pas à NULL avec = : colonne IS NULL, ou colonne IS NOT NULL.' },
      { title: 'La solution', body: LOOSE }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Deux euros de plus sur mes loose, rien sur ceux des déposants. M. Ferrand n\'aura rien à redire. Pour une fois.' }]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
