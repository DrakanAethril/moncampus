/* Rétro Rush - acte 1 : Le tableur de Gilles.
   The game half of the tickets: deliverables and their references, starting texts, views, written
   hints, scenes; the server half, with the right answers of the choices, is host/scenario/acte1.js. */
(function (T) {
  'use strict';
  var R = T.ref;

  T.add({
    id: 'R01', act: 1,
    views: {
      tableur: { title: 'TableurGilles · extrait', lines: [
        'ref    titre                        console         etat      prix  stock  deposant    telDeposant',
        'J0412  Sonic 2                      Mega Drive      complet     15      4',
        'J0413  Streets of Rage 2            MEGADRIVE       complet     40      2  Ferrand     06 12 34 56 78',
        'J0420  Gunstar Heroes               mega drive      complet     90      1  M. FERRAND  06 12 34 56 78',
        'J0187  Zelda : A Link to the Past   Super Nintendo  complet     60      0',
        'J0188  Zelda 3 (loose)              SNES            loose       30      1  Ferrand     0612345678',
        'J0090  Panzer Dragoon               Saturn          complet     30      0',
        'J0311  Tetris                       Gameboy         loose        8      7',
        'J0312  Tetris                       Game Boy        complet     25      1  Diallo      06 45 67 89 01',
        'J0416  Ecco the Dolphin             MD              complet     20      0',
        'J0202  Donkey Kong Country          Super Nes       complet     40      0  Moreau      07 11 22 33 44'
      ] }
    },
    intro: [
      { who: 'gilles', expr: 'ravi', text: 'Voilà mon tableur. Quinze ans de boutique. Tout y est !' },
      { who: 'jade', expr: 'sourcil', text: 'Tout y est… plusieurs fois, j\'ai l\'impression. Regarde avec moi.' }
    ],
    done: [
      { who: 'jade', expr: 'neutre', text: 'Un déposant, une console, un jeu : chacun aura sa table, écrit une seule fois. Les autres tables n\'en garderont que la clé.' }
    ]
  });

  T.add({
    id: 'R02', act: 1,
    deliver: [{ id: 'maj', label: 'La mise à jour du tableur', kind: 'modify', tables: ['TableurGilles'],
      ref: 'UPDATE TableurGilles SET console = \'Mega Drive\'\nWHERE console IN (\'MEGADRIVE\', \'mega drive\', \'Megadrive\', \'MD\');',
      say: { changed: 'Toutes mes Mega Drive s\'écrivent « Mega Drive »', untouched: 'Les autres consoles n\'ont pas bougé', hidden: 'Ça marche aussi sur le tableur complet' } }],
    start: { maj: 'UPDATE TableurGilles SET console = \'Mega Drive\'\nWHERE console = \'MEGADRIVE\';' },
    hints: [
      { title: 'UPDATE … WHERE … IN', ref: R.requetes, body: 'UPDATE table SET colonne = valeur WHERE condition;\nSans WHERE, toutes les lignes changent. Pour viser une liste de valeurs exactes : WHERE console IN (\'A\', \'B\', \'C\').\nAstuce : avant un UPDATE, écris le SELECT avec le même WHERE, et regarde quelles lignes il renvoie.' },
      { title: 'La solution', body: 'UPDATE TableurGilles SET console = \'Mega Drive\'\nWHERE console IN (\'MEGADRIVE\', \'mega drive\', \'Megadrive\', \'MD\');' }
    ],
    intro: [
      { who: 'gilles', expr: 'furieux', text: 'MD ! Qui écrit « MD » dans un tableur ? …Bon. C\'est moi, en 2014.' },
      { who: 'jade', expr: 'neutre', text: 'Avant de toucher au tableur, regarde d\'abord quelles lignes ton WHERE attrape, avec un SELECT dans la console libre.' }
    ],
    done: [
      { who: 'gilles', expr: 'ravi', text: 'Tous mes Sonic ensemble. C\'est beau, une colonne propre.' }
    ]
  });

  T.add({
    id: 'R03', act: 1,
    views: {
      schema: { title: 'Le schéma relationnel de Jade', lines: [
        '-- clé primaire entre [ ], clé étrangère précédée de #',
        'Console ([idConsole], nom, fabricant, anneeSortie)',
        'Jeu ([idJeu], titre, anneeSortie, genre, #idConsole)',
        'Deposant ([idDeposant], nom, prenom, telephone, tauxCommission)',
        'Article ([idArticle], #idJeu, etat, prixVente, prixAchat, stock, #idDeposant)',
        'Client ([idClient], nom, prenom, email, ville, dateInscription)',
        'Vente ([idVente], dateVente, #idClient, caisse)',
        'LigneVente ([#idVente, #idArticle], quantite, prixUnitaire)',
        'Reservation ([idReservation], #idArticle, #idClient, quantite, dateReservation, statut)'
      ] }
    },
    intro: [
      { who: 'jade', expr: 'neutre', text: 'À l\'examen, on te donne un schéma comme celui-là, et toutes les requêtes en partent. Apprends à le lire comme une carte.' }
    ],
    done: [
      { who: 'jade', expr: 'sourire', text: 'Tu lis un schéma comme à l\'examen. Je lance l\'import ce week-end.' }
    ]
  });

  var CREATE = 'CREATE TABLE Console (\n  idConsole INT AUTO_INCREMENT PRIMARY KEY,\n  nom VARCHAR(40) NOT NULL UNIQUE,\n  fabricant VARCHAR(30) NOT NULL,\n  anneeSortie INT\n);\n\nCREATE TABLE Jeu (\n  idJeu INT AUTO_INCREMENT PRIMARY KEY,\n  titre VARCHAR(80) NOT NULL,\n  anneeSortie INT,\n  genre VARCHAR(30),\n  idConsole INT NOT NULL,\n  FOREIGN KEY (idConsole) REFERENCES Console(idConsole)\n);';
  function has(h, t) { return h.hasTable(t); }
  T.add({
    id: 'R04', act: 1,
    deliver: [{ id: 'tables', label: 'Le script de création', kind: 'script', ref: CREATE, checks: [
      { label: 'La table Console existe, avec ses quatre colonnes', test: function (h) {
        if (!has(h, 'Console')) return { ok: false, detail: 'pas de table Console' };
        var c = h.columns('Console').map(function (x) { return x.name.toLowerCase(); });
        var miss = ['idconsole', 'nom', 'fabricant', 'anneesortie'].filter(function (n) { return c.indexOf(n) === -1; });
        return { ok: !miss.length, detail: miss.length ? 'il manque : ' + miss.join(', ') : '' };
      } },
      { label: 'idConsole est la clé primaire de Console', test: function (h) { var c = has(h, 'Console') && h.column('Console', 'idConsole'); return c && c.pk ? true : { ok: false, detail: 'pas de PRIMARY KEY sur idConsole' }; } },
      { label: 'Un nom de console n\'existe qu\'une fois', test: function (h) {
        if (!has(h, 'Console')) return { ok: false, detail: 'pas de table Console' };
        var r = h.tryRun('INSERT INTO Console (idConsole, nom, fabricant) VALUES (901, \'Saturn\', \'Sega\');\nINSERT INTO Console (idConsole, nom, fabricant) VALUES (902, \'Saturn\', \'Sega\');');
        return r.ok ? { ok: false, detail: 'deux consoles « Saturn » ont été acceptées : il manque UNIQUE sur nom' } : (/Doublon/.test(r.error) ? true : { ok: false, detail: r.error });
      } },
      { label: 'La table Jeu existe, avec sa clé primaire idJeu et sa colonne idConsole', test: function (h) {
        if (!has(h, 'Jeu')) return { ok: false, detail: 'pas de table Jeu' };
        var k = h.column('Jeu', 'idJeu'), c = h.column('Jeu', 'idConsole');
        return k && k.pk && c ? true : { ok: false, detail: !k || !k.pk ? 'pas de PRIMARY KEY sur idJeu' : 'pas de colonne idConsole' };
      } },
      { label: 'Un jeu refuse une console qui n\'existe pas', test: function (h) {
        if (!has(h, 'Jeu')) return { ok: false, detail: 'pas de table Jeu' };
        var r = h.tryRun('INSERT INTO Jeu (idJeu, titre, idConsole) VALUES (901, \'Essai\', 999);');
        return r.ok ? { ok: false, detail: 'un jeu sur la console 999, qui n\'existe pas, a été accepté : il manque la clé étrangère' } : (/clé étrangère/.test(r.error) ? true : { ok: false, detail: r.error });
      } },
      { label: 'Un jeu sur une console existante est accepté', test: function (h) {
        if (!has(h, 'Jeu') || !has(h, 'Console')) return { ok: false, detail: 'il manque une des deux tables' };
        var r = h.tryRun('INSERT INTO Console (idConsole, nom, fabricant) VALUES (901, \'Mega Drive\', \'Sega\');\nINSERT INTO Jeu (idJeu, titre, idConsole) VALUES (901, \'Sonic 2\', 901);');
        return r.ok ? true : { ok: false, detail: r.error };
      } }
    ] }],
    start: { tables: 'CREATE TABLE Console (\n  idConsole INT AUTO_INCREMENT PRIMARY KEY,\n  nom VARCHAR(40),\n  fabricant VARCHAR(30),\n  anneeSortie INT\n);\n' },
    hints: [
      { title: 'CREATE TABLE', ref: R.concevoir, body: 'CREATE TABLE Console (\n  idConsole INT AUTO_INCREMENT PRIMARY KEY,\n  nom VARCHAR(40) NOT NULL UNIQUE,\n  …\n);\nUNIQUE interdit deux fois la même valeur dans la colonne.' },
      { title: 'La clé étrangère', ref: R.concevoir, body: 'Dans Jeu, après les colonnes : FOREIGN KEY (idConsole) REFERENCES Console(idConsole).\nLa table Console doit exister avant Jeu.' },
      { title: 'La solution', body: CREATE }
    ],
    intro: [
      { who: 'jade', expr: 'neutre', text: 'Je pourrais tout créer moi-même, mais tu dois savoir l\'écrire : c\'est une question classique d\'examen. Commence par ces deux-là.' }
    ],
    done: [
      { who: 'jade', expr: 'sourire', text: 'Parfait. J\'ai écrit les sept autres sur le même modèle, et j\'importe tout ce week-end.' },
      { who: 'gilles', expr: 'perplexe', text: 'Et mon tableur ?' },
      { who: 'jade', expr: 'sourire', text: 'Il prend sa retraite, Gilles. Une retraite bien méritée.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis.RetroTickets : window.RetroTickets);
