/* Rétro Rush - acte 3 : Les jointures.
   The game half of the tickets; the server half is host/scenario/acte3.js. */
(function (T) {
  'use strict';
  var R = T.ref;

  T.add({
    id: 'R10', act: 3,
    deliver: [{ id: 'catalogue', label: 'Le catalogue', kind: 'select', order: true,
      ref: 'SELECT j.titre, c.nom\nFROM Jeu j\nJOIN Console c ON c.idConsole = j.idConsole\nORDER BY c.nom, j.titre;',
      say: { columns: 'Le titre, puis le nom de la console', missing: 'Tous les jeux y sont', extra: 'Chaque jeu une seule fois', order: 'Rangés par console, puis par titre' } }],
    start: { catalogue: 'SELECT titre, idConsole\nFROM Jeu\nORDER BY idConsole, titre;' },
    hints: [
      { title: 'La jointure', ref: R.requetes, body: 'SELECT … FROM Jeu j JOIN Console c ON c.idConsole = j.idConsole\nLe ON dit quelles lignes vont ensemble : celles qui ont le même idConsole. j et c sont des alias, pour écrire j.titre et c.nom.' },
      { title: 'La solution', body: 'SELECT j.titre, c.nom\nFROM Jeu j\nJOIN Console c ON c.idConsole = j.idConsole\nORDER BY c.nom, j.titre;' }
    ],
    intro: [
      { who: 'oceane', expr: 'perplexe', text: '« Console 6 », « console 2 »… Les clients ne vont pas deviner que 6, c\'est la Mega Drive.' },
      { who: 'jade', expr: 'neutre', text: 'Le nom est dans Console, le titre dans Jeu. Pour les avoir ensemble, il faut les relier : c\'est une jointure.' }
    ],
    done: [{ who: 'oceane', expr: 'soulagee', text: 'Des noms, enfin. « Mega Drive », écrit une seule fois dans la base, et partout sur la liste.' }]
  });

  T.add({
    id: 'R11', act: 3,
    deliver: [{ id: 'snes', label: 'Les jeux Super Nintendo', kind: 'select',
      ref: 'SELECT j.titre, a.etat, a.stock\nFROM Jeu j\nJOIN Console c ON c.idConsole = j.idConsole\nLEFT JOIN Article a ON a.idJeu = j.idJeu AND a.stock > 0\nWHERE c.nom = \'Super Nintendo\';',
      say: { columns: 'Le titre, l\'état et le stock', missing: 'Tous mes jeux Super Nintendo y sont, le Zelda compris', extra: 'Pas d\'exemplaire épuisé affiché comme s\'il était en rayon' } }],
    start: { snes: 'SELECT j.titre, a.etat, a.stock\nFROM Jeu j\nJOIN Console c ON c.idConsole = j.idConsole\nLEFT JOIN Article a ON a.idJeu = j.idJeu\nWHERE c.nom = \'Super Nintendo\'\n  AND a.stock > 0;' },
    views: { requete: { title: 'La requête de Gilles', lines: [
      'SELECT j.titre, a.etat, a.stock',
      'FROM Jeu j',
      'JOIN Console c ON c.idConsole = j.idConsole',
      'LEFT JOIN Article a ON a.idJeu = j.idJeu',
      'WHERE c.nom = \'Super Nintendo\'',
      '  AND a.stock > 0;'
    ] } },
    hints: [
      { title: 'Ce que garde un LEFT JOIN', ref: R.requetes, body: 'Un LEFT JOIN garde chaque ligne de gauche ; quand rien ne correspond à droite, les colonnes de droite valent NULL.\nPuis le WHERE s\'applique : une condition sur une colonne de droite (a.stock > 0) est fausse pour ces NULL, et la ligne disparaît.' },
      { title: 'ON ou WHERE', ref: R.requetes, body: 'Une condition qui choisit QUELLES lignes de droite joindre va dans le ON :\nLEFT JOIN Article a ON a.idJeu = j.idJeu AND a.stock > 0\nUne condition sur la table de gauche (la console) reste dans le WHERE.' },
      { title: 'La solution', body: 'SELECT j.titre, a.etat, a.stock\nFROM Jeu j\nJOIN Console c ON c.idConsole = j.idConsole\nLEFT JOIN Article a ON a.idJeu = j.idJeu AND a.stock > 0\nWHERE c.nom = \'Super Nintendo\';' }
    ],
    intro: [
      { who: 'gilles', expr: 'furieux', text: 'Je fais ma liste de rachats, et LE jeu que je dois racheter n\'y est pas. Le Zelda ! Celui que la base croit épuisé !' },
      { who: 'jade', expr: 'sourcil', text: 'Il a pourtant écrit un LEFT JOIN. Regarde bien ce qui se passe après.' }
    ],
    done: [
      { who: 'gilles', expr: 'ravi', text: 'Zelda, vide. Chrono Trigger, vide. Voilà ma liste de rachats !' },
      { who: 'jade', expr: 'neutre', text: 'Vide dans la base. Sur l\'étagère, tu m\'as dit qu\'il y en avait deux, de ces Zelda. On n\'oublie pas.' }
    ]
  });

  T.add({
    id: 'R12', act: 3,
    deliver: [{ id: 'mars', label: 'Les ventes de mars 2026', kind: 'select',
      ref: 'SELECT v.dateVente, j.titre, l.quantite, l.prixUnitaire\nFROM Vente v\nJOIN LigneVente l ON l.idVente = v.idVente\nJOIN Article a ON a.idArticle = l.idArticle\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE v.dateVente BETWEEN \'2026-03-01\' AND \'2026-03-31\';',
      say: { columns: 'La date, le titre, la quantité et le prix payé', missing: 'Toutes les lignes de vente de mars 2026 y sont', extra: 'Rien d\'un autre mois' } }],
    start: { mars: 'SELECT v.dateVente, l.idArticle, l.quantite, l.prixUnitaire\nFROM Vente v\nJOIN LigneVente l ON l.idVente = v.idVente\nWHERE v.dateVente BETWEEN \'2026-03-01\' AND \'2026-03-31\';' },
    hints: [
      { title: 'Suivre les clés', ref: R.requetes, body: 'Vente → LigneVente (idVente) → Article (idArticle) → Jeu (idJeu).\nUne jointure par étape, chacune avec son ON sur la clé étrangère.' },
      { title: 'Les dates', ref: R.requetes, body: 'Une date s\'écrit \'AAAA-MM-JJ\'. Pour un mois : dateVente BETWEEN \'2026-03-01\' AND \'2026-03-31\', ou YEAR(dateVente) = 2026 AND MONTH(dateVente) = 3.' },
      { title: 'La solution', body: 'SELECT v.dateVente, j.titre, l.quantite, l.prixUnitaire\nFROM Vente v\nJOIN LigneVente l ON l.idVente = v.idVente\nJOIN Article a ON a.idArticle = l.idArticle\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE v.dateVente BETWEEN \'2026-03-01\' AND \'2026-03-31\';' }
    ],
    done: [{ who: 'oceane', expr: 'neutre', text: 'Mars : quatre jeux de M. Ferrand sur cinq lignes. Il va encore venir compter.' }]
  });

  T.add({
    id: 'R13', act: 3,
    deliver: [{ id: 'depot', label: 'Les jeux de M. Ferrand', kind: 'select',
      ref: 'SELECT j.titre, c.nom, a.etat, a.prixVente, a.stock\nFROM Article a\nJOIN Deposant d ON d.idDeposant = a.idDeposant\nJOIN Jeu j ON j.idJeu = a.idJeu\nJOIN Console c ON c.idConsole = j.idConsole\nWHERE d.nom = \'Ferrand\';',
      say: { columns: 'Le titre, la console, l\'état, le prix et le stock', missing: 'Tous mes jeux en dépôt y sont', extra: 'Aucun jeu qui n\'est pas à moi' } }],
    start: { depot: 'SELECT j.titre, c.nom, a.etat, a.prixVente, a.stock\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nJOIN Console c ON c.idConsole = j.idConsole\nWHERE d.nom = \'Ferrand\';' },
    hints: [
      { title: 'Un alias se déclare', ref: R.requetes, body: 'd.nom veut dire « la colonne nom de la table que j\'ai appelée d ». Encore faut-il l\'avoir appelée : JOIN Deposant d ON d.idDeposant = a.idDeposant.' },
      { title: 'La solution', body: 'SELECT j.titre, c.nom, a.etat, a.prixVente, a.stock\nFROM Article a\nJOIN Deposant d ON d.idDeposant = a.idDeposant\nJOIN Jeu j ON j.idJeu = a.idJeu\nJOIN Console c ON c.idConsole = j.idConsole\nWHERE d.nom = \'Ferrand\';' }
    ],
    intro: [{ who: 'ferrand', expr: 'tatillon', text: 'J\'ai six jeux en dépôt ici. Six. Je veux les voir, avec leur prix, et vérifier qu\'aucun n\'a disparu.' }],
    done: [{ who: 'ferrand', expr: 'satisfait', text: 'Gunstar Heroes, 90 €. Il les vaut. Bon. Je repasserai lundi, avec ma calculatrice.' }]
  });

  T.add({
    id: 'R14', act: 3,
    deliver: [{ id: 'jamais', label: 'Les clients sans achat', kind: 'select',
      ref: 'SELECT c.nom, c.prenom\nFROM Client c\nLEFT JOIN Vente v ON v.idClient = c.idClient\nWHERE v.idVente IS NULL;',
      say: { columns: 'Le nom et le prénom', missing: 'Tous les clients qui n\'ont jamais acheté y sont', extra: 'Aucun client qui a déjà acheté' } }],
    start: { jamais: 'SELECT c.nom, c.prenom\nFROM Client c\nJOIN Vente v ON v.idClient = c.idClient;' },
    hints: [
      { title: 'Trouver les « jamais »', ref: R.requetes, body: 'LEFT JOIN garde tous les clients ; pour ceux qui n\'ont aucune vente, les colonnes de Vente valent NULL.\nIl ne reste qu\'à garder ces lignes-là : WHERE v.idVente IS NULL.' },
      { title: 'La solution', body: 'SELECT c.nom, c.prenom\nFROM Client c\nLEFT JOIN Vente v ON v.idClient = c.idClient\nWHERE v.idVente IS NULL;' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Deux bons d\'achat à envoyer. Fontaine et Chevalier, préparez-vous.' }]
  });

  T.add({
    id: 'R15', act: 3,
    // Two more sales of the Super Mario World loose (article 6): it now sits on three sale lines.
    setup: 'INSERT INTO Vente (idVente, dateVente, idClient, caisse) VALUES (101, \'2026-10-26\', NULL, 1), (102, \'2026-10-27\', NULL, 2);\nINSERT INTO LigneVente (idVente, idArticle, quantite, prixUnitaire) VALUES (101, 6, 1, 15), (102, 6, 1, 15);',
    deliver: [{ id: 'rayon', label: 'Les étiquettes du rayon', kind: 'select',
      ref: 'SELECT j.titre, a.etat, a.prixVente\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE a.stock > 0;',
      say: { columns: 'Le titre, l\'état et le prix', missing: 'Tous les articles en rayon y sont', extra: 'Chaque article une seule fois' } }],
    start: { rayon: 'SELECT j.titre, a.etat, a.prixVente\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nJOIN LigneVente l ON l.idArticle = a.idArticle\nWHERE a.stock > 0;' },
    views: { requete: { title: 'La requête d\'Océane', lines: [
      'SELECT j.titre, a.etat, a.prixVente',
      'FROM Article a',
      'JOIN Jeu j ON j.idJeu = a.idJeu',
      'JOIN LigneVente l ON l.idArticle = a.idArticle',
      'WHERE a.stock > 0;'
    ] } },
    hints: [
      { title: 'D\'où viennent les doublons', ref: R.requetes, body: 'Une jointure donne une ligne par correspondance. Un article vendu trois fois a trois lignes de vente : joint à LigneVente, il apparaît trois fois. Et un article jamais vendu n\'a aucune correspondance : il disparaît.' },
      { title: 'La solution', body: 'SELECT j.titre, a.etat, a.prixVente\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE a.stock > 0;' }
    ],
    intro: [{ who: 'oceane', expr: 'perplexe', text: 'Trois étiquettes « Super Mario World, loose, 15 € ». Et aucune pour le Mario 3 en boîte, qui est là, devant moi.' }],
    done: [{ who: 'oceane', expr: 'soulagee', text: 'Une étiquette par article. Comme sur l\'étagère.' }]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
