/* Rétro Rush - acte 5 : Questions difficiles.
   The game half of the tickets; the server half is host/scenario/acte5.js. */
(function (T) {
  'use strict';
  var R = T.ref;

  T.add({
    id: 'R21', act: 5,
    deliver: [{ id: 'vitrine', label: 'Les articles haut de gamme', kind: 'select', order: true,
      ref: 'SELECT idArticle, idJeu, prixVente\nFROM Article\nWHERE prixVente > (SELECT AVG(prixVente) FROM Article)\nORDER BY prixVente DESC, idArticle;',
      say: { columns: 'Le numéro de l\'article, son jeu et son prix', missing: 'Tous les articles au-dessus de la moyenne y sont', extra: 'Aucun article sous la moyenne', order: 'Du plus cher au moins cher, puis par numéro' } }],
    start: { vitrine: 'SELECT idArticle, idJeu, prixVente\nFROM Article\nWHERE prixVente > AVG(prixVente)\nORDER BY prixVente DESC, idArticle;' },
    views: { requete: { title: 'La requête de Gilles', lines: ['SELECT idArticle, idJeu, prixVente', 'FROM Article', 'WHERE prixVente > AVG(prixVente)', 'ORDER BY prixVente DESC, idArticle;'] } },
    hints: [
      { title: 'La sous-requête scalaire', ref: R.requetes, body: 'Une sous-requête entre parenthèses qui renvoie une seule valeur peut remplacer un nombre dans une condition :\nWHERE prixVente > (SELECT AVG(prixVente) FROM Article)\nElle est calculée une fois, puis chaque ligne est comparée au résultat.' },
      { title: 'La solution', body: 'SELECT idArticle, idJeu, prixVente\nFROM Article\nWHERE prixVente > (SELECT AVG(prixVente) FROM Article)\nORDER BY prixVente DESC, idArticle;' }
    ],
    intro: [
      { who: 'gilles', expr: 'perplexe', text: '« Fonction d\'agrégat mal placée. » Elle est très bien placée, ma moyenne, elle est juste à côté du prix !' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Secret of Mana, Gunstar Heroes, Pokémon Rouge… La vitrine va briller.' }]
  });

  T.add({
    id: 'R22', act: 5,
    deliver: [{ id: 'jamais', label: 'Les clients jamais passés en caisse', kind: 'select',
      ref: 'SELECT c.nom, c.prenom\nFROM Client c\nWHERE NOT EXISTS (\n  SELECT * FROM Vente v WHERE v.idClient = c.idClient\n);',
      say: { columns: 'Le nom et le prénom', missing: 'Tous les clients qui n\'ont jamais acheté y sont', extra: 'Aucun client qui a déjà acheté' } }],
    start: { jamais: 'SELECT nom, prenom\nFROM Client\nWHERE idClient NOT IN (SELECT idClient FROM Vente);' },
    hints: [
      { title: 'NOT IN et les NULL', ref: R.requetes, body: 'x NOT IN (1, 2, NULL) veut dire x <> 1 AND x <> 2 AND x <> NULL. La dernière comparaison donne « inconnu », donc le tout n\'est jamais vrai.\nDeux remèdes : exclure les NULL dans la sous-requête (WHERE idClient IS NOT NULL), ou utiliser NOT EXISTS.' },
      { title: 'NOT EXISTS', ref: R.requetes, body: 'WHERE NOT EXISTS (SELECT * FROM Vente v WHERE v.idClient = c.idClient)\nGarde les clients pour lesquels la sous-requête ne trouve aucune vente.' },
      { title: 'La solution', body: 'SELECT c.nom, c.prenom\nFROM Client c\nWHERE NOT EXISTS (\n  SELECT * FROM Vente v WHERE v.idClient = c.idClient\n);' }
    ],
    intro: [{ who: 'gilles', expr: 'perplexe', text: 'D\'après la base, tous mes clients ont acheté quelque chose. J\'aimerais bien, mais je connais Yanis : il vient, il regarde, il repart.' }],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Yanis Fontaine et Tom Chevalier. Deux bons de réduction, et peut-être deux clients de plus.' }]
  });

  T.add({
    id: 'R23', act: 5,
    deliver: [{ id: 'reserves', label: 'Les articles manquants réservés', kind: 'select',
      ref: 'SELECT a.idArticle, a.stock, i.quantiteComptee\nFROM Article a\nJOIN Inventaire i ON i.idArticle = a.idArticle\nWHERE i.quantiteComptee <> a.stock\n  AND EXISTS (\n    SELECT * FROM Reservation r\n    WHERE r.idArticle = a.idArticle\n      AND r.statut IN (\'annulée\', \'en cours\')\n  );',
      say: { columns: 'Le numéro de l\'article, son stock et ce que Gilles a compté', missing: 'Tous les articles en écart qui ont une réservation y sont', extra: 'Chaque article une seule fois, et rien d\'autre' } }],
    start: { reserves: 'SELECT a.idArticle, a.stock, i.quantiteComptee\nFROM Article a\nJOIN Inventaire i ON i.idArticle = a.idArticle\nJOIN Reservation r ON r.idArticle = a.idArticle\nWHERE i.quantiteComptee <> a.stock\n  AND r.statut IN (\'annulée\', \'en cours\');' },
    hints: [
      { title: 'EXISTS corrélé', ref: R.requetes, body: 'WHERE EXISTS (SELECT * FROM Reservation r WHERE r.idArticle = a.idArticle AND …)\nLa sous-requête cherche des réservations de l\'article de la ligne en cours. EXISTS est vrai dès qu\'elle en trouve une : l\'article n\'est jamais répété.' },
      { title: 'La solution', body: 'SELECT a.idArticle, a.stock, i.quantiteComptee\nFROM Article a\nJOIN Inventaire i ON i.idArticle = a.idArticle\nWHERE i.quantiteComptee <> a.stock\n  AND EXISTS (\n    SELECT * FROM Reservation r\n    WHERE r.idArticle = a.idArticle\n      AND r.statut IN (\'annulée\', \'en cours\')\n  );' }
    ],
    intro: [
      { who: 'jade', expr: 'sourcil', text: 'J\'ai regardé l\'écart d\'inventaire d\'hier. Les numéros me disent quelque chose. Vérifions avec les réservations.' },
      { who: 'gilles', expr: 'perplexe', text: 'Les réservations ? C\'est le site de mon neveu, ça. Il les a branchées en 2019 et plus personne n\'y a touché.' }
    ],
    done: [
      { who: 'jade', expr: 'sourcil', text: 'Les mêmes articles que l\'écart d\'inventaire. Tous. Les jeux jamais réservés tombent juste.' },
      { who: 'oceane', expr: 'inquiete', text: 'Les réservations, ce n\'est pas moi qui les annule. C\'est le client, depuis le site.' }
    ]
  });

  T.add({
    id: 'R24', act: 5,
    deliver: [{ id: 'appel', label: 'La liste d\'appel', kind: 'select',
      ref: 'SELECT c.nom, c.prenom\nFROM Client c\nJOIN Reservation r ON r.idClient = c.idClient\nWHERE r.statut = \'en cours\'\nUNION\nSELECT c.nom, c.prenom\nFROM Client c\nJOIN Vente v ON v.idClient = c.idClient\nWHERE v.dateVente BETWEEN \'2026-10-01\' AND \'2026-10-31\';',
      say: { columns: 'Le nom et le prénom', missing: 'Tous les clients à appeler y sont', extra: 'Chaque personne une seule fois' } }],
    start: { appel: 'SELECT c.nom, c.prenom\nFROM Client c\nJOIN Reservation r ON r.idClient = c.idClient\nWHERE r.statut = \'en cours\'\nUNION ALL\nSELECT c.nom, c.prenom\nFROM Client c\nJOIN Vente v ON v.idClient = c.idClient\nWHERE v.dateVente BETWEEN \'2026-10-01\' AND \'2026-10-31\';' },
    hints: [
      { title: 'UNION et UNION ALL', ref: R.requetes, body: 'SELECT … UNION SELECT … empile les lignes des deux requêtes, qui doivent avoir le même nombre de colonnes.\nUNION retire les lignes identiques ; UNION ALL les garde toutes.' },
      { title: 'La solution', body: 'Remplace UNION ALL par UNION dans la requête d\'Océane : le reste est juste.' }
    ],
    done: [{ who: 'oceane', expr: 'neutre', text: 'Cinq appels, pas un de plus. Et pendant que j\'appelle, Gilles ne regarde pas la caisse.' }]
  });

  T.add({
    id: 'R25', act: 5,
    deliver: [{ id: 'console', label: 'Plus cher que sa console', kind: 'select',
      ref: 'SELECT j.titre, a.etat, a.prixVente\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE a.prixVente > (\n  SELECT AVG(a2.prixVente)\n  FROM Article a2\n  JOIN Jeu j2 ON j2.idJeu = a2.idJeu\n  WHERE j2.idConsole = j.idConsole\n);',
      say: { columns: 'Le titre, l\'état et le prix', missing: 'Tous les articles au-dessus de la moyenne de leur console y sont', extra: 'Aucun article sous la moyenne de sa console' } }],
    start: { console: 'SELECT j.titre, a.etat, a.prixVente\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE a.prixVente > (SELECT AVG(prixVente) FROM Article);' },
    hints: [
      { title: 'La sous-requête corrélée', ref: R.requetes, body: 'Une sous-requête peut utiliser une colonne de la requête principale : elle est alors recalculée pour chaque ligne.\nWHERE a.prixVente > (SELECT AVG(…) FROM … WHERE j2.idConsole = j.idConsole)\nIl faut des alias différents dedans et dehors (a2, j2 contre a, j).' },
      { title: 'La solution', body: 'SELECT j.titre, a.etat, a.prixVente\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE a.prixVente > (\n  SELECT AVG(a2.prixVente)\n  FROM Article a2\n  JOIN Jeu j2 ON j2.idJeu = a2.idJeu\n  WHERE j2.idConsole = j.idConsole\n);' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Enfin une liste juste : mon Tetris en boîte est cher… pour un Game Boy.' }]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
