/* Rétro Rush - acte 4 : Les chiffres.
   The game half of the tickets; the server half is host/scenario/acte4.js. */
(function (T) {
  'use strict';
  var R = T.ref;

  T.add({
    id: 'R16', act: 4,
    deliver: [{ id: 'parconsole', label: 'Les jeux par console', kind: 'select', order: true,
      ref: 'SELECT c.nom, COUNT(j.idJeu) AS nbJeux\nFROM Console c\nLEFT JOIN Jeu j ON j.idConsole = c.idConsole\nGROUP BY c.idConsole, c.nom\nORDER BY c.nom;',
      say: { columns: 'Le nom de la console et son nombre de jeux', missing: 'Toutes les consoles y sont, avec le bon nombre', extra: 'Pas de jeu fantôme', order: 'Rangées par nom de console' } }],
    start: { parconsole: 'SELECT c.nom, COUNT(*) AS nbJeux\nFROM Console c\nLEFT JOIN Jeu j ON j.idConsole = c.idConsole\nGROUP BY c.nom\nORDER BY c.nom;' },
    hints: [
      { title: 'COUNT(*) ou COUNT(colonne)', ref: R.requetes, body: 'COUNT(*) compte les lignes du groupe. COUNT(colonne) compte les valeurs non NULL de cette colonne.\nAprès un LEFT JOIN, une console sans jeu a une ligne… dont toutes les colonnes de Jeu valent NULL.' },
      { title: 'La solution', body: 'SELECT c.nom, COUNT(j.idJeu) AS nbJeux\nFROM Console c\nLEFT JOIN Jeu j ON j.idConsole = c.idConsole\nGROUP BY c.idConsole, c.nom\nORDER BY c.nom;' }
    ],
    intro: [{ who: 'oceane', expr: 'perplexe', text: 'Neo Geo : 1 jeu. Je l\'ai cherché partout dans le rayon, ce jeu. Il n\'existe pas.' }],
    done: [{ who: 'oceane', expr: 'soulagee', text: 'Neo Geo, zéro. Pas de fantôme dans le menu.' }]
  });

  T.add({
    id: 'R17', act: 4,
    // A March sale of the year before, on M. Ferrand's article 32 (his in both bases): the trap of MONTH() without YEAR().
    setup: 'INSERT INTO Vente (idVente, dateVente, idClient, caisse) VALUES (100, \'2025-03-18\', 2, 1);\nINSERT INTO LigneVente (idVente, idArticle, quantite, prixUnitaire) VALUES (100, 32, 1, 90);',
    deliver: [{ id: 'releve', label: 'Le relevé de mars 2026', kind: 'select',
      ref: 'SELECT d.nom, SUM(l.quantite * l.prixUnitaire) AS total,\n       SUM(l.quantite * l.prixUnitaire * (1 - d.tauxCommission)) AS reversement\nFROM Deposant d\nJOIN Article a ON a.idDeposant = d.idDeposant\nJOIN LigneVente l ON l.idArticle = a.idArticle\nJOIN Vente v ON v.idVente = l.idVente\nWHERE d.nom = \'Ferrand\'\n  AND YEAR(v.dateVente) = 2026 AND MONTH(v.dateVente) = 3\nGROUP BY d.idDeposant, d.nom;',
      say: { columns: 'Mon nom, le total et mon reversement', missing: 'Le bon total de mars 2026, et le bon reversement', extra: 'Rien d\'une autre année' } }],
    start: { releve: 'SELECT d.nom, SUM(l.quantite * l.prixUnitaire) AS total,\n       SUM(l.quantite * l.prixUnitaire * (1 - d.tauxCommission)) AS reversement\nFROM Deposant d\nJOIN Article a ON a.idDeposant = d.idDeposant\nJOIN LigneVente l ON l.idArticle = a.idArticle\nJOIN Vente v ON v.idVente = l.idVente\nWHERE d.nom = \'Ferrand\'\n  AND MONTH(v.dateVente) = 3\nGROUP BY d.idDeposant, d.nom;' },
    hints: [
      { title: 'Les fonctions de date', ref: R.requetes, body: 'YEAR(d) donne l\'année, MONTH(d) le mois, DAY(d) le jour. MONTH(d) = 3, c\'est mars… de n\'importe quelle année.\nAutre écriture : v.dateVente BETWEEN \'2026-03-01\' AND \'2026-03-31\'.' },
      { title: 'La solution', body: 'Ajoute la condition sur l\'année :\nWHERE d.nom = \'Ferrand\'\n  AND YEAR(v.dateVente) = 2026 AND MONTH(v.dateVente) = 3' }
    ],
    intro: [
      { who: 'ferrand', expr: 'tatillon', text: 'Quatre ventes en mars : 25, 40, 55 et 30. Ça fait 150. Pas 240. J\'ai fait trente-huit ans de comptabilité, jeune homme, jeune femme, qui que vous soyez.' },
      { who: 'jade', expr: 'neutre', text: 'Il a ses tickets, et il a raison. Cherche d\'où viennent les 90 € de trop.' }
    ],
    done: [{ who: 'ferrand', expr: 'satisfait', text: '120 € pour moi, 30 pour la boutique. Je repartirai avec un Mario, tiens. En boîte.' }]
  });

  T.add({
    id: 'R18', act: 4,
    deliver: [{ id: 'chiffre', label: 'Les consoles qui rapportent', kind: 'select',
      ref: 'SELECT c.nom, SUM(l.quantite * l.prixUnitaire) AS chiffre\nFROM Console c\nJOIN Jeu j ON j.idConsole = c.idConsole\nJOIN Article a ON a.idJeu = j.idJeu\nJOIN LigneVente l ON l.idArticle = a.idArticle\nGROUP BY c.idConsole, c.nom\nHAVING SUM(l.quantite * l.prixUnitaire) > 100;',
      say: { columns: 'Le nom de la console et son chiffre d\'affaires', missing: 'Toutes les consoles au-dessus de 100 € y sont', extra: 'Aucune console à 100 € ou moins' } }],
    start: { chiffre: 'SELECT c.nom, SUM(l.quantite * l.prixUnitaire) AS chiffre\nFROM Console c\nJOIN Jeu j ON j.idConsole = c.idConsole\nJOIN Article a ON a.idJeu = j.idJeu\nJOIN LigneVente l ON l.idArticle = a.idArticle\nWHERE SUM(l.quantite * l.prixUnitaire) > 100\nGROUP BY c.idConsole, c.nom;' },
    hints: [
      { title: 'WHERE ou HAVING', ref: R.requetes, body: 'Ordre de calcul : FROM et jointures, puis WHERE (sur les lignes), puis GROUP BY, puis HAVING (sur les groupes), puis ORDER BY.\nUne condition sur SUM, COUNT ou AVG va donc dans le HAVING.' },
      { title: 'La solution', body: '…\nGROUP BY c.idConsole, c.nom\nHAVING SUM(l.quantite * l.prixUnitaire) > 100;' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'La Super Nintendo en tête, évidemment. Elle aura sa bannière sur la page d\'accueil.' }]
  });

  T.add({
    id: 'R19', act: 4,
    deliver: [{ id: 'moyenne', label: 'Le prix moyen par état', kind: 'select', order: true,
      ref: 'SELECT etat, ROUND(AVG(prixVente), 2) AS prixMoyen\nFROM Article\nGROUP BY etat\nORDER BY prixMoyen DESC, etat;',
      say: { columns: 'L\'état et le prix moyen', missing: 'Chaque état avec son juste prix moyen', extra: 'Une ligne par état, pas plus', order: 'Du plus cher au moins cher' } }],
    start: { moyenne: 'SELECT etat, AVG(prixVente) AS prixMoyen\nFROM Article\nGROUP BY etat;' },
    hints: [
      { title: 'AVG et ROUND', ref: R.requetes, body: 'AVG(colonne) calcule la moyenne de chaque groupe ; ROUND(valeur, 2) l\'arrondit au centime.\nOn peut trier sur l\'alias d\'une colonne calculée : ORDER BY prixMoyen DESC.' },
      { title: 'La solution', body: 'SELECT etat, ROUND(AVG(prixVente), 2) AS prixMoyen\nFROM Article\nGROUP BY etat\nORDER BY prixMoyen DESC;' }
    ],
    done: [{ who: 'oceane', expr: 'soulagee', text: 'En boîte, presque 39 € en moyenne ; loose, moins de 17. Les clients vont enfin comprendre pourquoi la boîte compte.' }]
  });

  T.add({
    id: 'R20', act: 4,
    deliver: [{ id: 'ecart', label: 'Les écarts d\'inventaire', kind: 'select', order: true,
      ref: 'SELECT a.idArticle, j.titre, a.stock, i.quantiteComptee,\n       i.quantiteComptee - a.stock AS ecart\nFROM Article a\nJOIN Inventaire i ON i.idArticle = a.idArticle\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE i.quantiteComptee <> a.stock\nORDER BY ecart DESC, a.idArticle;',
      say: { columns: 'Le numéro, le titre, le stock, le comptage et l\'écart', missing: 'Tous les articles en désaccord y sont', extra: 'Aucun article où tout tombe juste', order: 'Du plus gros écart au plus petit, puis par numéro' } }],
    start: { ecart: 'SELECT a.idArticle, a.stock, i.quantiteComptee\nFROM Article a\nJOIN Inventaire i ON i.idArticle = a.idArticle;' },
    hints: [
      { title: 'Une colonne calculée', ref: R.requetes, body: 'On peut calculer dans le SELECT : i.quantiteComptee - a.stock AS ecart. Le nom après AS sert d\'en-tête, et on peut trier dessus.\nPour ne garder que les désaccords : WHERE i.quantiteComptee <> a.stock.' },
      { title: 'La solution', body: 'SELECT a.idArticle, j.titre, a.stock, i.quantiteComptee,\n       i.quantiteComptee - a.stock AS ecart\nFROM Article a\nJOIN Inventaire i ON i.idArticle = a.idArticle\nJOIN Jeu j ON j.idJeu = a.idJeu\nWHERE i.quantiteComptee <> a.stock\nORDER BY ecart DESC, a.idArticle;' }
    ],
    intro: [
      { who: 'gilles', expr: 'furieux', text: 'J\'ai compté chaque étagère, samedi. Tout est dans Inventaire. Maintenant on va voir qui se sert.' },
      { who: 'oceane', expr: 'inquiete', text: '« Qui se sert »… Il me regarde en disant ça.' }
    ],
    done: [
      { who: 'jade', expr: 'sourcil', text: 'Huit articles, tous avec plus de jeux sur l\'étagère que dans la base. Un voleur fait l\'inverse.' },
      { who: 'oceane', expr: 'soulagee', text: 'Donc personne ne vole rien.' },
      { who: 'jade', expr: 'neutre', text: 'Donc quelque chose fait baisser la base sans que les jeux sortent. Reste à trouver quoi.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
