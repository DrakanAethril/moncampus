/* Rétro Rush - acte 9 : Les accès et l'inventaire.
   The game half of the tickets; the server half is host/scenario/acte9.js. */
(function (T) {
  'use strict';
  var R = T.ref;

  T.add({
    id: 'R40', act: 9,
    views: {
      grant: { title: 'Le script de l\'hébergeur', lines: [
        '-- Compte de l\'application web « Continue ? »',
        'CREATE USER \'site\'@\'%\' IDENTIFIED BY \'••••••••••••\';',
        'GRANT ALL PRIVILEGES ON boutique.* TO \'site\'@\'%\';',
        'FLUSH PRIVILEGES;'
      ] }
    },
    intro: [{ who: 'jade', expr: 'neutre', text: 'À l\'examen, les droits se lisent et s\'écrivent sur le papier. Ici aussi : l\'atelier tourne sous SQLite, qui n\'a pas de comptes.' }],
    done: [{ who: 'jade', expr: 'sourire', text: 'J\'envoie la version corrigée à l\'hébergeur. Un compte, deux droits, pas un de plus.' }]
  });

  T.add({
    id: 'R41', act: 9,
    views: {
      journal: { title: 'Journal des requêtes du site · nuit du 29 au 30', lines: [
        '23:12:03  SELECT titre, prixVente FROM CatalogueEnLigne WHERE titre LIKE \'%zelda%\'',
        '23:12:41  SELECT titre, prixVente FROM CatalogueEnLigne WHERE titre LIKE \'%sonic%\'',
        '23:58:17  SELECT titre, prixVente FROM CatalogueEnLigne WHERE titre LIKE \'%final fantasy%\'',
        '02:16:22  SELECT titre, prixVente FROM CatalogueEnLigne WHERE titre LIKE \'%\' UNION SELECT email, nom FROM Client -- %\'',
        '02:17:05  SELECT titre, prixVente FROM CatalogueEnLigne WHERE titre LIKE \'%mario kart%\'',
        '07:41:50  SELECT titre, prixVente FROM CatalogueEnLigne WHERE titre LIKE \'%panzer%\''
      ] }
    },
    intro: [
      { who: 'jade', expr: 'severe', text: 'Deux heures seize du matin. Quelqu\'un a cherché un drôle de titre.' },
      { who: 'gilles', expr: 'perplexe', text: 'Un client insomniaque ?' },
      { who: 'jade', expr: 'sourcil', text: 'Un client qui connaît le SQL, alors.' }
    ],
    done: [{ who: 'jade', expr: 'neutre', text: 'Je passe toutes les requêtes du site en requêtes préparées ce soir. Note l\'injection dans ton rapport d\'alternance : c\'est une question d\'examen, et une vraie nuit de travail.' }]
  });

  T.add({
    id: 'R42', act: 9,
    deliver: [{ id: 'anonymiser', label: 'L\'anonymisation de Mme Lefort', kind: 'modify', tables: ['Client', 'Vente', 'LigneVente', 'Reservation'],
      ref: 'UPDATE Client\nSET nom = \'Anonyme\', prenom = \'Anonyme\', email = NULL, ville = NULL\nWHERE idClient = 4;',
      say: { changed: 'Plus rien ne permet de reconnaître Mme Lefort', untouched: 'Ses achats sont toujours là, comme les autres clients', hidden: 'Le script ne vise que la cliente n° 4, quel que soit le reste de la base' } }],
    start: { anonymiser: 'DELETE FROM Client WHERE idClient = 4;' },
    hints: [
      { title: 'Le droit à l\'effacement', ref: R.rgpd, body: 'Le RGPD demande qu\'une personne ne soit plus identifiable. Quand ses données servent encore (la comptabilité garde les ventes), on n\'efface pas la ligne : on remplace ce qui l\'identifie.' },
      { title: 'UPDATE sur plusieurs colonnes', ref: R.requetes, body: 'UPDATE table SET col1 = valeur1, col2 = valeur2 WHERE condition;\nPour vider une colonne : col = NULL (ici, c\'est bien un =, c\'est une affectation, pas une comparaison).' },
      { title: 'La solution', body: 'UPDATE Client\nSET nom = \'Anonyme\', prenom = \'Anonyme\', email = NULL, ville = NULL\nWHERE idClient = 4;' }
    ],
    intro: [{ who: 'gilles', expr: 'perplexe', text: 'Elle a acheté un Pokémon Rouge à 80 €, en septembre. Si j\'efface Mme Lefort, j\'efface aussi mes 80 € ?' }],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Je lui réponds que c\'est fait. Et mes 80 € restent dans les comptes. Tout le monde est content.' }]
  });

  T.add({
    id: 'R43', act: 9, showTriggers: true,
    views: {
      trigger: { title: 'trg_annulation · écrit en 2019', lines: [
        'CREATE TRIGGER trg_annulation AFTER UPDATE ON Reservation',
        'FOR EACH ROW',
        'BEGIN',
        '  IF NEW.statut = \'annulée\' AND OLD.statut <> \'annulée\' THEN',
        '    UPDATE Article SET stock = stock - NEW.quantite',
        '    WHERE idArticle = NEW.idArticle;',
        '  END IF;',
        'END'
      ] }
    },
    intro: [
      { who: 'jade', expr: 'neutre', text: 'Gilles, Océane, venez voir. On a cinq jours de requêtes, et une seule explication tient debout.' },
      { who: 'oceane', expr: 'inquiete', text: 'Si c\'est encore moi, dites-le tout de suite.' },
      { who: 'jade', expr: 'sourcil', text: 'Ce n\'est personne. Regarde le code.' }
    ],
    done: [
      { who: 'gilles', expr: 'perplexe', text: '…Un signe moins. Des années que je soupçonne tout le monde à cause d\'un signe moins.' },
      { who: 'oceane', expr: 'soulagee', text: 'Je peux le dire ? Je le savais.' },
      { who: 'gilles', expr: 'ravi', text: 'Océane, je vous dois des excuses. Et le neveu va m\'entendre.' }
    ]
  });

  var FIX = 'DROP TRIGGER trg_annulation;\n\nDELIMITER //\nCREATE TRIGGER trg_annulation AFTER UPDATE ON Reservation\nFOR EACH ROW\nBEGIN\n  IF NEW.statut = \'annulée\' AND OLD.statut <> \'annulée\' THEN\n    UPDATE Article SET stock = stock + NEW.quantite\n    WHERE idArticle = NEW.idArticle;\n  END IF;\nEND //\nDELIMITER ;';
  T.add({
    id: 'R44', act: 9, showTriggers: true,
    deliver: [{ id: 'trigger', label: 'Le trigger corrigé', kind: 'trigger', tables: ['Article', 'Reservation'], ref: FIX,
      say: { script: 'Le script remplace l\'ancien trigger' },
      trial: [
        { label: 'Annuler une réservation rend l\'article au stock', sql: 'UPDATE Reservation SET statut = \'annulée\' WHERE idReservation = 4;' },
        { label: 'Une réservation retirée ne touche pas au stock', sql: 'UPDATE Reservation SET statut = \'retirée\' WHERE idReservation = 5;' },
        { label: 'Annuler deux fois ne rend l\'article qu\'une fois', sql: 'UPDATE Reservation SET statut = \'annulée\' WHERE idReservation = 4;\nUPDATE Reservation SET statut = \'annulée\' WHERE idReservation = 4;' },
        { label: 'Réserver puis annuler laisse le stock comme avant', sql: 'INSERT INTO Reservation (idReservation, idArticle, idClient, quantite, dateReservation) VALUES (100, 1, 2, 1, CURDATE());\nUPDATE Reservation SET statut = \'annulée\' WHERE idReservation = 100;' }
      ] }],
    start: { trigger: 'DELIMITER //\nCREATE TRIGGER trg_annulation AFTER UPDATE ON Reservation\nFOR EACH ROW\nBEGIN\n  IF NEW.statut = \'annulée\' AND OLD.statut <> \'annulée\' THEN\n    UPDATE Article SET stock = stock - NEW.quantite\n    WHERE idArticle = NEW.idArticle;\n  END IF;\nEND //\nDELIMITER ;' },
    hints: [
      { title: 'Remplacer un trigger', ref: R.sgbd, body: 'MySQL ne remplace pas un trigger existant : DROP TRIGGER nom; puis CREATE TRIGGER nom …\nEntre les deux, DELIMITER // permet d\'écrire les ; du corps sans couper l\'instruction.' },
      { title: 'NEW et OLD dans un trigger de mise à jour', ref: R.sgbd, body: 'Dans un AFTER UPDATE, OLD est la ligne avant la modification, NEW la ligne après. La condition « NEW.statut = \'annulée\' AND OLD.statut <> \'annulée\' » ne s\'applique qu\'au passage à « annulée », une seule fois.' },
      { title: 'La solution', body: FIX }
    ],
    done: [{ who: 'jade', expr: 'sourire', text: 'Le voleur est en prison. Il reste à rendre ce qu\'il a pris.' }]
  });

  var MIGRATION = 'START TRANSACTION;\n\nUPDATE Article\nSET stock = stock + 2 * (SELECT SUM(r.quantite) FROM Reservation r\n                        WHERE r.idArticle = Article.idArticle AND r.statut = \'annulée\')\nWHERE idArticle IN (SELECT idArticle FROM Reservation WHERE statut = \'annulée\');\n\nCOMMIT;';
  T.add({
    id: 'R45', act: 9,
    deliver: [{ id: 'stock', label: 'La remise à niveau du stock', kind: 'modify', tables: ['Article'], requireTx: true, ref: MIGRATION,
      say: { changed: 'Chaque article rend deux exemplaires par réservation annulée', untouched: 'Les autres articles n\'ont pas bougé', hidden: 'Ça tient sur tout le stock, pas seulement sur les articles connus' } }],
    start: { stock: 'UPDATE Article SET stock = stock + 2\nWHERE idArticle IN (SELECT idArticle FROM Reservation WHERE statut = \'annulée\');' },
    hints: [
      { title: 'Une sous-requête dans le SET', ref: R.requetes, body: 'On peut calculer la nouvelle valeur avec une sous-requête corrélée :\nSET stock = stock + 2 * (SELECT SUM(quantite) FROM Reservation r WHERE r.idArticle = Article.idArticle AND …)\nAttention à un article qui a deux réservations annulées : il récupère quatre exemplaires.' },
      { title: 'Tout ou rien', ref: R.administrer, body: 'START TRANSACTION; … COMMIT;\nSi une instruction échoue entre les deux, MySQL annule tout ce qui a été fait depuis START TRANSACTION.' },
      { title: 'La solution', body: MIGRATION }
    ],
    intro: [
      { who: 'gilles', expr: 'neutre', text: 'Le site ouvre demain à 9 h. Je veux un stock juste. Pour la première fois en six ans.' },
      { who: 'jade', expr: 'sourcil', text: 'Attention au Silent Hill : deux réservations annulées, donc quatre exemplaires perdus.' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Le Zelda est à 2. Le Silent Hill est à 3. La base dit enfin ce qu\'il y a sur l\'étagère.' }]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
