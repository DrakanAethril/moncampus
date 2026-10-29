/* Rétro Rush - acte 2 : Premières requêtes.
   The game half of the tickets; the server half is host/scenario/acte2.js. */
(function (T) {
  'use strict';
  var R = T.ref;

  T.add({
    id: 'R05', act: 2,
    deliver: [{ id: 'clients', label: 'Les clients de Limoges', kind: 'select', order: true,
      ref: 'SELECT nom, prenom, email\nFROM Client\nWHERE ville = \'Limoges\'\nORDER BY nom;',
      say: { columns: 'Le nom, le prénom et l\'e-mail, dans cet ordre', missing: 'Tous les clients de Limoges y sont', extra: 'Pas de client d\'une autre ville', order: 'Rangés par nom, de A à Z' } }],
    start: { clients: 'SELECT * FROM Client;' },
    hints: [
      { title: 'SELECT … WHERE … ORDER BY', ref: R.requetes, body: 'SELECT colonne1, colonne2 FROM table WHERE condition ORDER BY colonne;\nOn ne liste que les colonnes demandées, dans l\'ordre demandé. ORDER BY range de A à Z (ASC, par défaut) ; DESC dans l\'autre sens.' },
      { title: 'La solution', body: 'SELECT nom, prenom, email\nFROM Client\nWHERE ville = \'Limoges\'\nORDER BY nom;' }
    ],
    intro: [
      { who: 'oceane', expr: 'neutre', text: 'Gilles veut que j\'appelle les clients pour l\'ouverture. Au moins, pendant ce temps-là, il ne me regarde pas de travers.' },
      { who: 'jade', expr: 'neutre', text: 'La base est en ligne depuis ce matin. Clique sur une table du schéma, à droite, pour voir ce qu\'elle contient.' }
    ],
    done: [{ who: 'oceane', expr: 'soulagee', text: 'Cinq appels. Je commence par Mme Faure : elle achète toujours quelque chose.' }]
  });

  T.add({
    id: 'R06', act: 2,
    deliver: [{ id: 'sansgenre', label: 'Les jeux sans genre', kind: 'select',
      ref: 'SELECT titre\nFROM Jeu\nWHERE genre IS NULL;',
      say: { columns: 'Le titre seulement', missing: 'Tous les jeux sans genre y sont', extra: 'Aucun jeu qui a un genre' } }],
    start: { sansgenre: 'SELECT titre FROM Jeu\nWHERE genre = NULL;' },
    views: { requete: { title: 'La requête de Gilles', lines: ['SELECT titre FROM Jeu', 'WHERE genre = NULL;'] } },
    hints: [
      { title: 'IS NULL', ref: R.requetes, body: 'NULL veut dire « pas de valeur ». On ne le compare pas avec = : on écrit colonne IS NULL, ou colonne IS NOT NULL.' },
      { title: 'La solution', body: 'SELECT titre\nFROM Jeu\nWHERE genre IS NULL;' }
    ],
    intro: [{ who: 'gilles', expr: 'perplexe', text: 'Zéro ligne. Le site va croire que tout est rangé, alors que j\'ai des jeux sans étiquette.' }],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Gunstar Heroes, Soulcalibur, Ecco… Je vais leur trouver un genre. « Inclassable », ça compte ?' }]
  });

  T.add({
    id: 'R07', act: 2,
    deliver: [{ id: 'bac', label: 'Le bac à petit prix', kind: 'select',
      ref: 'SELECT idArticle, idJeu, prixVente\nFROM Article\nWHERE etat = \'loose\' AND prixVente BETWEEN 10 AND 30;',
      say: { columns: 'Le numéro de l\'article, son jeu et son prix', missing: 'Tous les loose de 10 à 30 € y sont', extra: 'Pas d\'article en boîte, ni hors de mes prix' } }],
    start: { bac: 'SELECT idArticle, idJeu, prixVente\nFROM Article\nWHERE etat = \'loose\' OR prixVente >= 10 AND prixVente <= 30;' },
    hints: [
      { title: 'AND, OR et les parenthèses', ref: R.requetes, body: 'AND est évalué avant OR. « A OR B AND C » veut dire « A OR (B AND C) ».\nPour garder les lignes qui remplissent deux conditions à la fois : A AND B.\nBETWEEN 10 AND 30 inclut les deux bornes.' },
      { title: 'La solution', body: 'SELECT idArticle, idJeu, prixVente\nFROM Article\nWHERE etat = \'loose\' AND prixVente BETWEEN 10 AND 30;' }
    ],
    done: [{ who: 'oceane', expr: 'soulagee', text: 'Voilà un vrai petit bac. Et pas un seul jeu en boîte égaré dedans.' }]
  });

  T.add({
    id: 'R08', act: 2,
    deliver: [{ id: 'mario', label: 'Tous les Mario', kind: 'select', order: true,
      ref: 'SELECT titre, anneeSortie\nFROM Jeu\nWHERE titre LIKE \'%Mario%\'\nORDER BY anneeSortie DESC, titre;',
      say: { columns: 'Le titre et l\'année', missing: 'Tous les Mario y sont, Super Mario compris', extra: 'Rien que des Mario', order: 'Du plus récent au plus ancien, puis par titre' } }],
    start: { mario: 'SELECT titre, anneeSortie FROM Jeu\nWHERE titre LIKE \'Mario%\'\nORDER BY anneeSortie;' },
    hints: [
      { title: 'LIKE et ses jokers', ref: R.requetes, body: '% remplace n\'importe quelle suite de caractères, même vide ; _ en remplace exactement un.\n\'Mario%\' : commence par Mario. \'%Mario\' : finit par Mario. \'%Mario%\' : contient Mario.' },
      { title: 'Trier dans les deux sens', ref: R.requetes, body: 'ORDER BY anneeSortie DESC, titre : d\'abord par année décroissante, puis, à année égale, par titre croissant.' },
      { title: 'La solution', body: 'SELECT titre, anneeSortie\nFROM Jeu\nWHERE titre LIKE \'%Mario%\'\nORDER BY anneeSortie DESC, titre;' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Six Mario. Le client va pleurer de joie. Ou devant les prix.' }]
  });

  T.add({
    id: 'R09', act: 2,
    deliver: [{ id: 'rupture', label: 'Les articles en rupture', kind: 'select', order: true,
      ref: 'SELECT idArticle, idJeu, stock\nFROM Article\nWHERE stock <= 0\nORDER BY stock, idArticle;',
      say: { columns: 'Le numéro de l\'article, son jeu et son stock', missing: 'Tous les articles à zéro ou moins y sont', extra: 'Aucun article encore en rayon', order: 'Du plus petit stock au plus grand, puis par numéro' } }],
    start: { rupture: 'SELECT idArticle, idJeu, stock FROM Article\nWHERE stock = 0;' },
    hints: [
      { title: 'Les opérateurs de comparaison', ref: R.requetes, body: '=, <> (différent), <, <=, >, >=. « Zéro ou en dessous » s\'écrit stock <= 0.\nPour trier sur deux colonnes : ORDER BY stock, idArticle.' },
      { title: 'La solution', body: 'SELECT idArticle, idJeu, stock\nFROM Article\nWHERE stock <= 0\nORDER BY stock, idArticle;' }
    ],
    intro: [{ who: 'gilles', expr: 'furieux', text: 'Le Zelda en boîte est à zéro dans votre base. J\'en ai DEUX sur l\'étagère, je viens de les compter !' }],
    done: [
      { who: 'gilles', expr: 'perplexe', text: 'Moins un. J\'ai un Silent Hill en moins que zéro. Même un voleur n\'arrive pas à ça.' },
      { who: 'jade', expr: 'neutre', text: 'Note-le dans le dossier du stock. Pour l\'instant, on observe, on ne conclut pas.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
