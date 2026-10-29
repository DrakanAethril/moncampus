/* Rétro Rush - acte 8 : Le grand rush.
   The game half of the tickets; the server half is host/scenario/acte8.js. The act 8 base already
   holds the sale triggers of act 7 (trg_vente_stock, trg_verif_stock). */
(function (T) {
  'use strict';
  var R = T.ref;

  T.add({
    id: 'R35', act: 8,
    views: {
      journal: { title: 'Journal de la base · vendredi 27 novembre', lines: [
        'heure     source    instruction                                                    stock du Wind Waker',
        '10:02:11  site      SELECT stock FROM Article WHERE idArticle = 47;                1 : affiché « en stock »',
        '10:02:12  caisse 1  SELECT stock FROM Article WHERE idArticle = 47;                1 : on peut vendre',
        '10:02:14  caisse 1  INSERT INTO Vente (idVente, dateVente, caisse) VALUES (301, …)',
        '10:02:14  caisse 1  INSERT INTO LigneVente VALUES (301, 47, 1, 40);                trg_vente_stock : stock 0',
        '10:02:17  site      INSERT INTO Reservation (idArticle, idClient, quantite, …)',
        '10:02:17  site        VALUES (47, 7, 1, …);                                        trg_reservation : stock −1',
        '10:02:18  site      « Votre réservation est confirmée ! »'
      ] }
    },
    intro: [
      { who: 'oceane', expr: 'inquiete', text: 'Il me reste un Wind Waker, je le vends. Et dix minutes après, une cliente arrive avec une réservation confirmée pour le même. Le même !' },
      { who: 'jade', expr: 'neutre', text: 'Personne n\'a triché. Regarde l\'heure de chaque instruction, et ce que chacune croyait savoir du stock.' }
    ],
    done: [
      { who: 'jade', expr: 'neutre', text: 'La caisse et le site ont lu le même 1, chacun de leur côté. Entre leur lecture et leur écriture, la porte était ouverte.' },
      { who: 'oceane', expr: 'soulagee', text: 'Donc ce n\'est pas moi. J\'ai encore besoin de l\'entendre, oui.' }
    ]
  });

  var VENTE = 'START TRANSACTION;\nDELETE FROM Vente WHERE idVente = 200;\nINSERT INTO Vente (idVente, dateVente, idClient, caisse)\nVALUES (201, CURDATE(), 9, 1);\nINSERT INTO LigneVente (idVente, idArticle, quantite, prixUnitaire)\nVALUES (201, 29, 1, 15), (201, 21, 2, 8);\nCOMMIT;';
  T.add({
    id: 'R36', act: 8,
    setup: 'INSERT INTO Vente (idVente, dateVente, idClient, caisse) VALUES (200, CURDATE(), 9, 1);\nUPDATE Article SET stock = stock + 3 WHERE idArticle IN (21, 29) AND stock < 3;',
    deliver: [{ id: 'vente', label: 'La vente, en une transaction', kind: 'modify', requireTx: true, tables: ['Vente', 'LigneVente', 'Article'], ref: VENTE,
      say: { changed: 'La vente 201 est là, avec ses deux lignes, et le stock a baissé une fois', untouched: 'La vente 200 a disparu, rien d\'autre n\'a bougé', hidden: 'Le script tient aussi sur le stock complet' } }],
    start: { vente: 'INSERT INTO Vente (idVente, dateVente, idClient, caisse)\nVALUES (201, CURDATE(), 9, 1);\nINSERT INTO LigneVente (idVente, idArticle, quantite, prixUnitaire)\nVALUES (201, 29, 1, 15), (201, 21, 2, 8);' },
    hints: [
      { title: 'Une transaction', ref: R.administrer, body: 'START TRANSACTION;\n  -- les instructions qui vont ensemble\nCOMMIT;\nSi une instruction échoue, ROLLBACK annule tout ce qui a été fait depuis START TRANSACTION. Sans COMMIT, rien n\'est gardé.' },
      { title: 'Le stock bouge tout seul', ref: R.sgbd, body: 'trg_vente_stock baisse le stock à chaque ligne de vente insérée. N\'écris pas d\'UPDATE du stock en plus : il baisserait deux fois.' },
      { title: 'La solution', body: VENTE }
    ],
    intro: [{ who: 'gilles', expr: 'furieux', text: 'Une vente sans rien dedans. La caisse a planté entre deux lignes, et maintenant la base croit que Manon Faure est passée pour rien.' }],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Tout ou rien. J\'aurais dû écrire ça sur la porte de la boutique il y a quinze ans.' }]
  });

  var VUE = 'CREATE OR REPLACE VIEW CatalogueEnLigne AS\nSELECT j.titre, c.nom AS console, a.etat, a.prixVente, a.stock\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nJOIN Console c ON c.idConsole = j.idConsole\nWHERE a.stock > 0;';
  var REF_ROWS = 'SELECT j.titre, c.nom, a.etat, a.prixVente, a.stock FROM Article a JOIN Jeu j ON j.idJeu = a.idJeu JOIN Console c ON c.idConsole = j.idConsole WHERE a.stock > 0';
  function columns(h) { return h.session.query('SELECT * FROM CatalogueEnLigne LIMIT 1').columns.map(function (c) { return c.toLowerCase(); }); }
  T.add({
    id: 'R37', act: 8,
    setup: 'CREATE VIEW CatalogueEnLigne AS\nSELECT j.titre, c.nom AS console, a.etat, a.prixVente, a.prixAchat, a.idDeposant, a.stock\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nJOIN Console c ON c.idConsole = j.idConsole;',
    deliver: [{ id: 'vue', label: 'La vue du catalogue', kind: 'script', ref: VUE, checks: [
      { label: 'La vue CatalogueEnLigne existe toujours', test: function (h) { return h.hasView('CatalogueEnLigne') ? true : { ok: false, detail: 'le site n\'a plus de vue à lire' }; } },
      { label: 'Le prix d\'achat et le déposant ne se voient plus', test: function (h) {
        if (!h.hasView('CatalogueEnLigne')) return { ok: false, detail: 'pas de vue' };
        var bad = columns(h).filter(function (c) { return c === 'prixachat' || c === 'iddeposant'; });
        return bad.length ? { ok: false, detail: 'la vue montre encore : ' + bad.join(', ') } : true;
      } },
      { label: 'Le site trouve ses cinq colonnes : titre, console, etat, prixVente, stock', test: function (h) {
        if (!h.hasView('CatalogueEnLigne')) return { ok: false, detail: 'pas de vue' };
        var got = columns(h), want = ['titre', 'console', 'etat', 'prixvente', 'stock'];
        return got.join() === want.join() ? true : { ok: false, detail: 'colonnes de la vue : ' + got.join(', ') };
      } },
      { label: 'Seuls les articles en stock y figurent, tous', test: function (h) {
        if (!h.hasView('CatalogueEnLigne')) return { ok: false, detail: 'pas de vue' };
        return h.sameAs('SELECT titre, console, etat, prixVente, stock FROM CatalogueEnLigne', REF_ROWS);
      } }
    ] }],
    hints: [
      { title: 'Une vue', ref: R.sgbd, body: 'CREATE VIEW nom AS SELECT … ;\nUne vue est une requête enregistrée : on la lit comme une table, mais elle ne stocke rien. Pour la remplacer : CREATE OR REPLACE VIEW, ou DROP VIEW puis CREATE VIEW.' },
      { title: 'Choisir ce qu\'on montre', ref: R.sgbd, body: 'Le site ne doit lire que titre, console, etat, prixVente et stock, et seulement les articles dont le stock est positif : c\'est le SELECT de la vue qui en décide.' },
      { title: 'La solution', body: VUE }
    ],
    intro: [
      { who: 'gilles', expr: 'furieux', text: '« Vous achetez à 35 et vous revendez à 60 ? » Évidemment ! C\'est un commerce, pas une association !' },
      { who: 'jade', expr: 'sourcil', text: 'Le site lit une vue qui montre tout. Clique-la dans le schéma : tu verras ce que voient les clients.' }
    ],
    done: [{ who: 'gilles', expr: 'ravi', text: 'Le client voit le prix. Moi, je garde ma marge pour moi. Tout le monde est content.' }]
  });

  var INDEX = 'CREATE INDEX idx_jeu_titre ON Jeu (titre);';
  var SEARCH = 'SELECT * FROM Jeu WHERE titre = \'Tetris\'';
  T.add({
    id: 'R38', act: 8,
    deliver: [{ id: 'index', label: 'L\'index de recherche', kind: 'script', ref: INDEX, checks: [
      { label: 'L\'index idx_jeu_titre existe sur Jeu', test: function (h) {
        var ix = h.session.indexes().filter(function (i) { return i.name.toLowerCase() === 'idx_jeu_titre'; })[0];
        return ix && ix.table.toLowerCase() === 'jeu' ? true : { ok: false, detail: ix ? 'il est posé sur ' + ix.table : 'aucun index de ce nom' };
      } },
      { label: 'La recherche par titre passe par l\'index', test: function (h) {
        var p = h.plan(SEARCH);
        return /USING (COVERING )?INDEX/.test(p) ? true : { ok: false, detail: 'plan : ' + p };
      } }
    ] }],
    hints: [
      { title: 'CREATE INDEX', ref: R.administrer, body: 'CREATE INDEX nom ON Table (colonne);\nLe plan d\'exécution montre la différence : SCAN Jeu (toute la table) avant, SEARCH Jeu USING INDEX après.' },
      { title: 'La solution', body: INDEX }
    ],
    intro: [{ who: 'oceane', expr: 'inquiete', text: 'J\'ai tapé « Tetris » dans la recherche du site, j\'ai eu le temps de faire un café. Un vrai, à la machine.' }],
    done: [{ who: 'oceane', expr: 'soulagee', text: 'Instantané. Je n\'aurai plus d\'excuse pour le café.' }]
  });

  var PRIX = 'UPDATE Article SET prixVente = 35 WHERE idArticle = 24;\nALTER TABLE Article ADD CONSTRAINT chk_prix CHECK (prixVente > 0);';
  T.add({
    id: 'R39', act: 8,
    setup: 'UPDATE Article SET prixVente = 0 WHERE idArticle = 24;',
    deliver: [{ id: 'prix', label: 'Le prix et la contrainte', kind: 'script', ref: PRIX, checks: [
      { label: 'Le Pokémon Rouge loose est revenu à 35 €', test: function (h) {
        var v = h.rows('SELECT prixVente FROM Article WHERE idArticle = 24')[0][0];
        return v === 35 ? true : { ok: false, detail: 'il est à ' + v + ' €' };
      } },
      { label: 'Un prix à 0 € est refusé', test: function (h) {
        var r = h.tryRun('UPDATE Article SET prixVente = 0 WHERE idArticle = 1;');
        return r.ok ? { ok: false, detail: 'la base a accepté un prix à 0 €' } : true;
      } },
      { label: 'Un prix négatif est refusé', test: function (h) {
        var r = h.tryRun('INSERT INTO Article (idArticle, idJeu, etat, prixVente, stock) VALUES (900, 1, \'loose\', -5, 1);');
        return r.ok ? { ok: false, detail: 'la base a accepté un article à −5 €' } : true;
      } },
      { label: 'Un prix normal passe toujours', test: function (h) {
        var r = h.tryRun('UPDATE Article SET prixVente = 49 WHERE idArticle = 1;');
        return r.ok ? true : { ok: false, detail: r.error };
      } }
    ] }],
    start: { prix: 'ALTER TABLE Article ADD CONSTRAINT chk_prix CHECK (prixVente > 0);' },
    hints: [
      { title: 'Ajouter une contrainte', ref: R.concevoir, body: 'ALTER TABLE Table ADD CONSTRAINT nom CHECK (condition);\nMySQL vérifie toutes les lignes déjà présentes : si l\'une ne respecte pas la condition, la contrainte est refusée.' },
      { title: 'D\'abord les données', ref: R.requetes, body: 'Corrige la ligne fautive avec un UPDATE, puis pose la contrainte, dans le même script.' },
      { title: 'La solution', body: PRIX }
    ],
    intro: [{ who: 'gilles', expr: 'furieux', text: 'Zéro euro. Trois clients ont déjà réservé le Pokémon. Mme Nguyen va me tuer.' }],
    done: [
      { who: 'gilles', expr: 'ravi', text: 'Plus jamais un jeu gratuit par erreur. Par générosité, peut-être. Par erreur, jamais.' },
      { who: 'jade', expr: 'sourire', text: 'Le site ouvre lundi soir. Il ne reste qu\'une chose à régler avant… et elle est dans ton dossier du stock.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
