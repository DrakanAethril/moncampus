/* Rétro Rush - acte 7 : Les automatismes.
   The game half of the tickets; the server half is host/scenario/acte7.js. A trigger is checked by
   its effects: each trial runs on a fresh base after the script (engine.js, checkTrigger). */
(function (T) {
  'use strict';
  var R = T.ref;

  var VENTE_STOCK = 'DELIMITER //\nCREATE TRIGGER trg_vente_stock AFTER INSERT ON LigneVente\nFOR EACH ROW\nBEGIN\n  UPDATE Article SET stock = stock - NEW.quantite\n  WHERE idArticle = NEW.idArticle;\nEND //\nDELIMITER ;';
  var VERIF_STOCK = 'DELIMITER //\nCREATE TRIGGER trg_verif_stock BEFORE INSERT ON LigneVente\nFOR EACH ROW\nBEGIN\n  DECLARE dispo INT;\n  SELECT stock INTO dispo FROM Article WHERE idArticle = NEW.idArticle;\n  IF dispo < NEW.quantite THEN\n    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Stock insuffisant\';\n  END IF;\nEND //\nDELIMITER ;';
  function sale(lines) { return 'INSERT INTO Vente (idVente, dateVente, caisse) VALUES (100, CURDATE(), 1);\nINSERT INTO LigneVente (idVente, idArticle, quantite, prixUnitaire) VALUES ' + lines + ';'; }

  T.add({
    id: 'R31', act: 7,
    deliver: [{ id: 'trigger', label: 'Le trigger de vente', kind: 'trigger', tables: ['Article', 'Vente', 'LigneVente'], ref: VENTE_STOCK,
      trial: [
        { label: 'Un Tetris loose vendu : son stock passe de 7 à 6', sql: sale('(100, 21, 1, 8)') },
        { label: 'Deux Sonic loose d\'un coup : le stock baisse de deux', sql: sale('(100, 28, 2, 8)') },
        { label: 'Une vente de deux jeux différents fait baisser les deux', sql: sale('(100, 21, 1, 8), (100, 30, 1, 6)') }
      ] }],
    start: { trigger: 'DELIMITER //\nCREATE TRIGGER trg_vente_stock AFTER INSERT ON LigneVente\nFOR EACH ROW\nBEGIN\n  UPDATE Article SET stock = stock - 1\n  WHERE idArticle = NEW.idArticle;\nEND //\nDELIMITER ;' },
    hints: [
      { title: 'Créer un trigger', ref: R.sgbd, body: 'DELIMITER //\nCREATE TRIGGER nom AFTER INSERT ON LaTable\nFOR EACH ROW\nBEGIN\n  -- instructions ; NEW.colonne est la ligne insérée\nEND //\nDELIMITER ;\nDELIMITER change le séparateur le temps de la création, pour que les ; du corps ne la coupent pas.' },
      { title: 'NEW.quantite', ref: R.sgbd, body: 'Une ligne de vente peut porter plusieurs exemplaires : le stock baisse de NEW.quantite, pas de 1.' },
      { title: 'La solution', body: VENTE_STOCK }
    ],
    intro: [
      { who: 'oceane', expr: 'inquiete', text: 'J\'ai vendu trois jeux ce matin, et le stock affiche toujours pareil. Gilles va encore croire que je cache quelque chose.' },
      { who: 'jade', expr: 'neutre', text: 'Ce n\'est pas à toi de baisser le stock à la main : c\'est le travail d\'un trigger. Il y en a une liste, à droite, sous le schéma.' }
    ],
    done: [
      { who: 'jade', expr: 'sourcil', text: 'Tiens. En listant les triggers, j\'en vois deux autres, que je n\'ai pas écrits : trg_reservation et trg_annulation. De 2019.' },
      { who: 'gilles', expr: 'perplexe', text: 'Mon neveu. Il avait commencé un site de réservation, l\'été où il était en stage. Le site n\'a jamais ouvert.' },
      { who: 'jade', expr: 'neutre', text: 'Le site, non. Ses triggers, eux, tournent toujours. Je le note dans le dossier.' }
    ]
  });

  T.add({
    id: 'R32', act: 7,
    setup: VENTE_STOCK,
    deliver: [{ id: 'trigger', label: 'Le trigger de contrôle', kind: 'trigger', tables: ['Article', 'Vente', 'LigneVente'], ref: VERIF_STOCK,
      trial: [
        { label: 'Une vente avec assez de stock passe', sql: sale('(100, 21, 1, 8)') },
        { label: 'Deux Street Fighter II en boîte, il en reste un : refusé', sql: sale('(100, 9, 2, 35)'), refused: true },
        { label: 'Le Zelda en boîte, à 0 dans la base : refusé', sql: sale('(100, 7, 1, 60)'), refused: true },
        { label: 'Le dernier Street Fighter II en boîte se vend', sql: sale('(100, 9, 1, 35)') }
      ] }],
    start: { trigger: 'DELIMITER //\nCREATE TRIGGER trg_verif_stock BEFORE INSERT ON LigneVente\nFOR EACH ROW\nBEGIN\n  DECLARE dispo INT;\n  SELECT stock INTO dispo FROM Article WHERE idArticle = NEW.idArticle;\n  IF dispo <= NEW.quantite THEN\n    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Stock insuffisant\';\n  END IF;\nEND //\nDELIMITER ;' },
    hints: [
      { title: 'Refuser avec SIGNAL', ref: R.sgbd, body: 'IF condition THEN\n  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Le message\';\nEND IF;\nSIGNAL annule l\'instruction en cours et renvoie le message à l\'application.' },
      { title: 'Lire une valeur dans une variable', ref: R.sgbd, body: 'DECLARE dispo INT;\nSELECT stock INTO dispo FROM Article WHERE idArticle = NEW.idArticle;\nLes DECLARE se placent au début du BEGIN.' },
      { title: 'La solution', body: VERIF_STOCK }
    ],
    intro: [
      { who: 'gilles', expr: 'furieux', text: 'Deux Street Fighter vendus, un seul en rayon. Et devinez qui doit appeler le client ?' },
      { who: 'jade', expr: 'neutre', text: 'Tu as déjà un trigger qui baisse le stock. Il en faut un second, qui dit non avant.' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: '« Stock insuffisant ». Enfin une caisse qui me dit non avant le client.' }]
  });

  var HISTO = 'DELIMITER //\nCREATE TRIGGER trg_historique_prix AFTER UPDATE ON Article\nFOR EACH ROW\nBEGIN\n  IF NEW.prixVente <> OLD.prixVente THEN\n    INSERT INTO HistoriquePrix (idArticle, ancienPrix, nouveauPrix, dateChangement)\n    VALUES (NEW.idArticle, OLD.prixVente, NEW.prixVente, CURDATE());\n  END IF;\nEND //\nDELIMITER ;';
  T.add({
    id: 'R33', act: 7,
    deliver: [{ id: 'trigger', label: 'Le trigger d\'historique', kind: 'trigger', tables: ['Article', 'HistoriquePrix'], ref: HISTO,
      trial: [
        { label: 'Le Zelda passe de 60 à 50 € : une ligne d\'historique', sql: 'UPDATE Article SET prixVente = 50 WHERE idArticle = 7;' },
        { label: 'Changer seulement le stock n\'écrit rien', sql: 'UPDATE Article SET stock = stock + 1 WHERE idArticle = 7;' },
        { label: 'Les soldes sur trois articles laissent trois lignes', sql: 'UPDATE Article SET prixVente = prixVente * 0.7 WHERE idArticle IN (33, 34, 50);' },
        { label: 'Remettre le même prix n\'écrit rien', sql: 'UPDATE Article SET prixVente = 60 WHERE idArticle = 7;' }
      ] }],
    start: { trigger: 'DELIMITER //\nCREATE TRIGGER trg_historique_prix AFTER UPDATE ON Article\nFOR EACH ROW\nBEGIN\n  INSERT INTO HistoriquePrix (idArticle, ancienPrix, nouveauPrix, dateChangement)\n  VALUES (NEW.idArticle, OLD.prixVente, NEW.prixVente, CURDATE());\nEND //\nDELIMITER ;' },
    hints: [
      { title: 'OLD et NEW', ref: R.sgbd, body: 'Dans un trigger UPDATE : OLD.colonne est la valeur avant, NEW.colonne la valeur après.\nEn INSERT, seulement NEW ; en DELETE, seulement OLD.' },
      { title: 'Un IF dans un trigger', ref: R.sgbd, body: 'IF NEW.prixVente <> OLD.prixVente THEN\n  …\nEND IF;\nAFTER UPDATE se déclenche à chaque mise à jour de la ligne, même si seul le stock change.' },
      { title: 'La solution', body: HISTO }
    ],
    intro: [{ who: 'gilles', expr: 'perplexe', text: 'M. Ferrand jure que son Gunstar était à 95 € l\'an dernier. Moi, je dis 90. Sans trace, c\'est sa parole contre la mienne.' }],
    done: [{ who: 'gilles', expr: 'ravi', text: 'La prochaine fois que M. Ferrand dit « vous l\'aviez mis à combien ? », je lui sors la liste.' }]
  });

  var FIDELITE = 'DELIMITER //\nCREATE TRIGGER trg_fidelite BEFORE INSERT ON LigneVente\nFOR EACH ROW\nBEGIN\n  DECLARE nbAchats INT;\n  SELECT COUNT(*) INTO nbAchats FROM Vente\n  WHERE idClient = (SELECT idClient FROM Vente WHERE idVente = NEW.idVente);\n  IF nbAchats >= 3 THEN\n    SET NEW.prixUnitaire = NEW.prixUnitaire * 0.9;\n  END IF;\nEND //\nDELIMITER ;';
  function clientSale(client, line) { return 'INSERT INTO Vente (idVente, dateVente, idClient, caisse) VALUES (100, CURDATE(), ' + client + ', 1);\nINSERT INTO LigneVente (idVente, idArticle, quantite, prixUnitaire) VALUES ' + line + ';'; }
  T.add({
    id: 'R34', act: 7,
    deliver: [{ id: 'trigger', label: 'Le trigger corrigé', kind: 'trigger', tables: ['Vente', 'LigneVente', 'Article'], ref: FIDELITE,
      trial: [
        { label: 'Léa Martin, habituée, paie son Mario 64 à 40,50 € au lieu de 45', sql: clientSale(1, '(100, 15, 1, 45)') },
        { label: 'Yanis Fontaine, premier achat, paie plein tarif', sql: clientSale(6, '(100, 15, 1, 45)') },
        { label: 'Une vente au comptoir, sans client, paie plein tarif', sql: clientSale('NULL', '(100, 21, 1, 8)') },
        { label: 'Sandrine Lefort, à son troisième passage, a sa remise', sql: clientSale(4, '(100, 21, 2, 8)') }
      ] }],
    start: { trigger: FIDELITE.replace('BEFORE INSERT', 'AFTER INSERT') },
    hints: [
      { title: 'BEFORE ou AFTER', ref: R.sgbd, body: 'BEFORE : le trigger voit la ligne avant son écriture, et peut changer ses valeurs avec SET NEW.colonne = … .\nAFTER : la ligne est écrite ; NEW se lit, mais ne se modifie plus.' },
      { title: 'La solution', body: FIDELITE }
    ],
    intro: [{ who: 'oceane', expr: 'inquiete', text: 'J\'ai copié l\'exemple de Jade et changé deux mots. MySQL me répond une phrase que je ne comprends pas. Tu regardes ?' }],
    done: [
      { who: 'oceane', expr: 'soulagee', text: 'Mme Faure a eu sa remise, elle m\'a fait un grand sourire. Au moins un trigger qui m\'aime bien.' },
      { who: 'jade', expr: 'sourire', text: 'Tu as corrigé le code de quelqu\'un d\'autre sans le réécrire. C\'est la moitié du métier.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
