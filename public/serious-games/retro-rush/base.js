/* Rétro Rush — the shop's database, act by act.

   The visible base is the one the student sees and queries: written by hand, so that the story
   holds (the Zelda at 0 in the base and 2 on the shelf, M. Ferrand's March, the Panzer Dragoon).
   The hidden base has the same schema, the same consoles and depositors, and generated games,
   articles, clients, sales and reservations (a seeded generator, the same at every run): a query
   cut for the rows on screen - `WHERE idArticle IN (7, 33)` - fails on it.

   Act 1 is Gilles' spreadsheet only (TableurGilles). From act 2 on, the relational schema Jade
   migrated over the weekend, its two triggers of 2019 (one of them the « thief ») and the shelf
   count of 1 November. Each later act adds what an earlier act built: the price history (act 7),
   the sale triggers of act 7 (from act 8), the online catalogue view, the search index and the
   price constraint of act 8 (act 9).

   Written in MySQL and run through RetroSQL like the student's scripts - the triggers keep their
   MySQL source, which the schema panel shows. */
(function (root) {
  'use strict';

  // ——— The visible shop ———
  var CONSOLES = [
    [1, 'NES', 'Nintendo', 1987], [2, 'Super Nintendo', 'Nintendo', 1992], [3, 'Nintendo 64', 'Nintendo', 1997],
    [4, 'Game Boy', 'Nintendo', 1990], [5, 'Master System', 'Sega', 1987], [6, 'Mega Drive', 'Sega', 1990],
    [7, 'Saturn', 'Sega', 1995], [8, 'Dreamcast', 'Sega', 1999], [9, 'PlayStation', 'Sony', 1995],
    [10, 'PlayStation 2', 'Sony', 2000], [11, 'GameCube', 'Nintendo', 2002], [12, 'Neo Geo AES', 'SNK', 1991]
  ];
  var JEUX = [
    [1, 'Super Mario Bros. 3', 1991, 'Plateforme', 1], [2, 'The Legend of Zelda', 1988, 'Aventure', 1], [3, 'Mega Man 2', 1990, 'Plateforme', 1],
    [4, 'Super Mario World', 1992, 'Plateforme', 2], [5, 'Zelda : A Link to the Past', 1992, 'Aventure', 2], [6, 'Street Fighter II', 1993, 'Combat', 2],
    [7, 'Donkey Kong Country', 1994, 'Plateforme', 2], [8, 'Secret of Mana', 1994, 'RPG', 2], [9, 'F-Zero', 1992, 'Course', 2],
    [10, 'Super Mario 64', 1997, 'Plateforme', 3], [11, 'Zelda : Ocarina of Time', 1998, 'Aventure', 3], [12, 'Mario Kart 64', 1997, 'Course', 3],
    [13, 'GoldenEye 007', 1997, 'Tir', 3], [14, 'Tetris', 1990, 'Réflexion', 4], [15, 'Pokémon Rouge', 1999, 'RPG', 4],
    [16, 'Super Mario Land', 1990, 'Plateforme', 4], [17, 'Alex Kidd in Miracle World', null, 'Plateforme', 5], [18, 'Sonic the Hedgehog', 1991, 'Plateforme', 6],
    [19, 'Sonic 2', 1992, 'Plateforme', 6], [20, 'Streets of Rage 2', 1993, 'Combat', 6], [21, 'Gunstar Heroes', 1993, null, 6],
    [22, 'Panzer Dragoon', 1995, 'Tir', 7], [23, 'Sega Rally Championship', 1995, 'Course', 7], [24, 'Shenmue', 2000, 'Aventure', 8],
    [25, 'Soulcalibur', 1999, null, 8], [26, 'Final Fantasy VII', 1997, 'RPG', 9], [27, 'Metal Gear Solid', 1999, 'Action', 9],
    [28, 'Gran Turismo', 1998, 'Course', 9], [29, 'Crash Bandicoot', 1996, 'Plateforme', 9], [30, 'Silent Hill', 1999, 'Aventure', 9],
    [31, 'Shadow of the Colossus', 2006, 'Aventure', 10], [32, 'Final Fantasy X', 2002, 'RPG', 10], [33, 'Gran Turismo 4', 2005, 'Course', 10],
    [34, 'Super Mario Sunshine', 2002, 'Plateforme', 11], [35, 'Zelda : The Wind Waker', 2003, 'Aventure', 11], [36, 'Metroid Prime', 2003, 'Action', 11],
    [37, 'Ecco the Dolphin', 1993, null, 6], [38, 'Chrono Trigger', 1995, 'RPG', 2]
  ];
  var DEPOSANTS = [
    [1, 'Ferrand', 'Michel', '06 12 34 56 78', 0.2], [2, 'Nguyen', 'Linh', '06 98 76 54 32', 0.25], [3, 'Moreau', 'Kevin', '07 11 22 33 44', 0.25],
    [4, 'Diallo', 'Awa', '06 45 67 89 01', 0.2], [5, 'Lambert', 'Paul', null, 0.3], [6, 'Roux', 'Camille', '07 55 44 33 22', 0.25]
  ];
  // idArticle, idJeu, etat, prixVente, prixAchat, stock (as the base holds it), idDeposant
  var ARTICLES = [
    [1, 1, 'complet', 45, 25, 2, null], [2, 1, 'loose', 18, 8, 3, null], [3, 2, 'loose', 25, null, 0, 1], [4, 3, 'complet', 40, null, 1, 2],
    [5, 4, 'complet', 35, 20, 2, null], [6, 4, 'loose', 15, 6, 3, null], [7, 5, 'complet', 60, 35, 0, null], [8, 5, 'loose', 30, null, 0, 1],
    [9, 6, 'complet', 35, 18, 1, null], [10, 6, 'loose', 12, 5, 4, null], [11, 7, 'complet', 40, null, 0, 3], [12, 7, 'loose', 15, 7, 1, null],
    [13, 8, 'complet', 70, 40, 1, null], [14, 9, 'loose', 12, 5, 2, null], [15, 10, 'complet', 45, 25, 2, null], [16, 10, 'loose', 20, 9, 3, null],
    [17, 11, 'complet', 55, null, 0, 1], [18, 11, 'loose', 22, 10, 2, null], [19, 12, 'loose', 25, 12, 4, null], [20, 13, 'loose', 18, 8, 3, null],
    [21, 14, 'loose', 8, 2, 7, null], [22, 14, 'complet', 25, null, 1, 4], [23, 15, 'complet', 80, 45, 0, null], [24, 15, 'loose', 35, null, 1, 2],
    [25, 16, 'loose', 10, 4, 3, null], [26, 17, 'loose', 10, 4, 2, null], [27, 18, 'complet', 20, 8, 3, null], [28, 18, 'loose', 8, 3, 6, null],
    [29, 19, 'complet', 15, 6, 4, null], [30, 19, 'loose', 6, 2, 8, null], [31, 20, 'complet', 40, null, 0, 1], [32, 21, 'complet', 90, null, 1, 1],
    [33, 22, 'complet', 30, 14, 0, null], [34, 23, 'complet', 25, 12, 1, null], [35, 24, 'complet', 50, null, 0, 3], [36, 25, 'complet', 30, 15, 1, null],
    [37, 26, 'complet', 30, 12, 2, null], [38, 26, 'sans notice', 20, 9, 2, null], [39, 27, 'complet', 35, null, 1, 2], [40, 28, 'complet', 10, 3, 4, null],
    [41, 29, 'loose', 12, 4, 3, null], [42, 30, 'complet', 60, 30, -1, null], [43, 31, 'complet', 25, 10, 2, null], [44, 32, 'complet', 15, 6, 3, null],
    [45, 33, 'complet', 12, 5, 2, null], [46, 34, 'complet', 30, 14, 1, null], [47, 35, 'complet', 40, null, 1, 4], [48, 36, 'complet', 25, 10, 1, null],
    [49, 7, 'complet', 45, null, 0, 2], [50, 22, 'loose', 18, null, 1, 1]
  ];
  var CLIENTS = [
    [1, 'Martin', 'Léa', 'lea.martin@mail.fr', 'Limoges', '2025-03-12'], [2, 'Bernard', 'Hugo', 'hugo.b@mail.fr', 'Limoges', '2025-06-01'],
    [3, 'Petit', 'Chloé', null, 'Brive', '2025-09-20'], [4, 'Lefort', 'Sandrine', 's.lefort@mail.fr', 'Tulle', '2024-11-05'],
    [5, 'Garcia', 'Nathan', 'nathan.g@mail.fr', 'Limoges', '2026-01-15'], [6, 'Fontaine', 'Yanis', 'yanis.f@mail.fr', 'Guéret', '2026-02-02'],
    [7, 'Mercier', 'Emma', 'emma.m@mail.fr', 'Limoges', '2026-04-18'], [8, 'Blanc', 'Lucas', null, 'Brive', '2026-05-30'],
    [9, 'Faure', 'Manon', 'manon.faure@mail.fr', 'Limoges', '2026-07-07'], [10, 'Chevalier', 'Tom', 'tom.c@mail.fr', 'Tulle', '2026-08-21']
  ];
  var VENTES = [
    [1, '2025-11-08', 1, 1], [2, '2025-12-20', 2, 1], [3, '2026-01-10', null, 1], [4, '2026-01-24', 5, 1], [5, '2026-02-14', 7, 2],
    [6, '2026-03-03', 1, 1], [7, '2026-03-15', null, 1], [8, '2026-03-22', 9, 2], [9, '2026-03-29', 2, 1], [10, '2026-04-05', 5, 1],
    [11, '2026-04-19', null, 2], [12, '2026-05-02', 7, 1], [13, '2026-05-17', 3, 1], [14, '2026-06-06', 1, 2], [15, '2026-06-21', null, 1],
    [16, '2026-07-04', 9, 1], [17, '2026-07-18', 5, 2], [18, '2026-08-08', 2, 1], [19, '2026-08-29', null, 1], [20, '2026-09-12', 7, 1],
    [21, '2026-09-26', 4, 2], [22, '2026-10-03', 8, 1], [23, '2026-10-10', 9, 2], [24, '2026-10-17', 1, 1], [25, '2026-10-24', 4, 1]
  ];
  // idVente, idArticle, quantite, prixUnitaire
  var LIGNES = [
    [1, 2, 1, 18], [2, 21, 2, 8], [2, 27, 1, 20], [3, 30, 1, 6], [4, 5, 1, 35], [5, 15, 1, 45], [5, 19, 1, 25],
    [6, 3, 1, 25], [7, 31, 1, 40], [8, 17, 1, 55], [8, 28, 2, 8], [9, 8, 1, 30], [10, 6, 2, 15], [11, 40, 1, 10],
    [11, 41, 1, 12], [12, 37, 1, 30], [13, 24, 1, 35], [14, 13, 1, 70], [15, 21, 3, 8], [16, 46, 1, 30], [16, 48, 1, 25],
    [17, 9, 1, 35], [18, 49, 1, 45], [19, 29, 2, 15], [20, 42, 1, 60], [21, 23, 1, 80], [22, 11, 1, 40], [23, 35, 1, 50],
    [24, 7, 1, 60], [25, 36, 1, 30], [25, 12, 1, 15]
  ];
  // idReservation, idArticle, idClient, quantite, dateReservation, statut
  var RESERVATIONS = [
    [1, 33, 9, 1, '2026-06-10', 'annulée'], [2, 7, 5, 1, '2026-09-02', 'annulée'], [3, 23, 4, 1, '2026-09-20', 'retirée'],
    [4, 15, 1, 1, '2026-10-20', 'en cours'], [5, 13, 7, 1, '2026-10-22', 'en cours'], [6, 42, 2, 1, '2026-07-12', 'annulée'],
    [7, 50, 5, 1, '2026-08-15', 'annulée'], [8, 32, 9, 1, '2026-10-25', 'en cours'], [9, 19, 3, 2, '2026-05-03', 'retirée'],
    [10, 21, 8, 1, '2026-10-01', 'annulée'], [11, 42, 2, 1, '2026-08-30', 'annulée']
  ];
  // The spreadsheet of act 1: fifteen years of « Continue ? » in one sheet.
  var TABLEUR = [
    ['J0412', 'Sonic 2', 'Mega Drive', 'complet', 15, 4, '', ''], ['J0413', 'Streets of Rage 2', 'MEGADRIVE', 'complet', 40, 2, 'Ferrand', '06 12 34 56 78'],
    ['J0420', 'Gunstar Heroes', 'mega drive', 'complet', 90, 1, 'M. FERRAND', '06 12 34 56 78'], ['J0187', 'Zelda : A Link to the Past', 'Super Nintendo', 'complet', 60, 0, '', ''],
    ['J0188', 'Zelda 3 (loose)', 'SNES', 'loose', 30, 1, 'Ferrand', '0612345678'], ['J0090', 'Panzer Dragoon', 'Saturn', 'complet', 30, 0, '', ''],
    ['J0311', 'Tetris', 'Gameboy', 'loose', 8, 7, '', ''], ['J0312', 'Tetris', 'Game Boy', 'complet', 25, 1, 'Diallo', '06 45 67 89 01'],
    ['J0415', 'Sonic the Hedgehog', 'Megadrive', 'loose', 8, 6, '', ''], ['J0416', 'Ecco the Dolphin', 'MD', 'complet', 20, 0, '', ''],
    ['J0201', 'Super Mario World', 'Super Nintendo', 'loose', 15, 3, '', ''], ['J0202', 'Donkey Kong Country', 'Super Nes', 'complet', 40, 0, 'Moreau', '07 11 22 33 44'],
    ['J0500', 'Final Fantasy VII', 'PlayStation', 'complet', 30, 2, '', ''], ['J0501', 'Metal Gear Solid', 'PS1', 'complet', 35, 1, 'Nguyen', '06 98 76 54 32'],
    ['J0502', 'Gran Turismo', 'Playstation', 'complet', 10, 4, '', ''], ['J0417', 'Mega Man 2', 'NES', 'complet', 40, 1, 'Nguyen', '06 98 76 54 32'],
    ['J0418', 'Alex Kidd in Miracle World', 'Master System', 'loose', 10, 2, '', ''], ['J0419', 'Shenmue', 'Dreamcast', 'complet', 50, 0, 'Moreau', '07 11 22 33 44']
  ];

  // ——— The hidden shop: same consoles and depositors, generated stock ———
  var HIDDEN_TITLES = [
    ['Kirby\'s Adventure', 1, 'Plateforme'], ['Duck Tales', 1, 'Plateforme'], ['Castlevania', 1, 'Action'], ['Punch-Out!!', 1, 'Combat'],
    ['Super Metroid', 2, 'Action'], ['Star Fox', 2, 'Tir'], ['Super Mario Kart', 2, 'Course'], ['Final Fantasy VI', 2, 'RPG'], ['Earthworm Jim', 2, null],
    ['Banjo-Kazooie', 3, 'Plateforme'], ['Star Fox 64', 3, 'Tir'], ['Wave Race 64', 3, 'Course'], ['Paper Mario', 3, 'RPG'],
    ['Zelda : Link\'s Awakening', 4, 'Aventure'], ['Kirby\'s Dream Land', 4, 'Plateforme'], ['Wario Land', 4, null],
    ['Wonder Boy III', 5, 'Plateforme'], ['Phantasy Star', 5, 'RPG'],
    ['Castle of Illusion', 6, 'Plateforme'], ['Golden Axe', 6, 'Combat'], ['Phantasy Star IV', 6, 'RPG'], ['Comix Zone', 6, 'Action'], ['Sonic 3', 6, 'Plateforme'],
    ['Nights into Dreams', 7, 'Action'], ['Virtua Fighter 2', 7, 'Combat'], ['Guardian Heroes', 7, null], ['Daytona USA', 7, 'Course'],
    ['Jet Set Radio', 8, 'Action'], ['Crazy Taxi', 8, 'Course'], ['Skies of Arcadia', 8, 'RPG'],
    ['Tekken 3', 9, 'Combat'], ['Resident Evil', 9, 'Aventure'], ['Spyro the Dragon', 9, 'Plateforme'], ['Wipeout', 9, 'Course'], ['Final Fantasy IX', 9, 'RPG'],
    ['Ico', 10, 'Aventure'], ['Okami', 10, 'Aventure'], ['Kingdom Hearts', 10, 'RPG'], ['Burnout 3', 10, 'Course'],
    ['Pikmin', 11, 'Réflexion'], ['Super Smash Bros. Melee', 11, 'Combat'], ['Mario Kart: Double Dash!!', 11, 'Course'], ['Tetris', 1, 'Réflexion']
  ];
  var HIDDEN_PEOPLE = [['Durand', 'Inès'], ['Leroy', 'Mathis'], ['Roussel', 'Jade'], ['Morel', 'Louise'], ['Girard', 'Noah'], ['André', 'Lina'],
    ['Lefèvre', 'Adam'], ['Mercier', 'Rose'], ['Dupuis', 'Sacha'], ['Bonnet', 'Zoé'], ['Lopez', 'Ethan'], ['Fournier', 'Jade'], ['Henry', 'Malo'],
    ['Rousseau', 'Alice'], ['Perrin', 'Gabin']];
  var CITIES = ['Limoges', 'Limoges', 'Brive', 'Tulle', 'Guéret', 'Limoges', 'Saint-Junien'];
  var ETATS = ['complet', 'loose', 'sans notice'];

  function prng(seed) {
    return function () { seed |= 0; seed = seed + 0x6D2B79F5 | 0; var t = Math.imul(seed ^ seed >>> 15, 1 | seed); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };
  }
  function hiddenData() {
    var r = prng(20261029), pick = function (a) { return a[Math.floor(r() * a.length)]; }, int = function (a, b) { return a + Math.floor(r() * (b - a + 1)); };
    var jeux = HIDDEN_TITLES.map(function (t, i) { return [i + 1, t[0], 1985 + int(2, 20), t[2], t[1]]; });
    jeux[3][2] = null;
    var articles = [], aid = 0;
    jeux.forEach(function (j) {
      var n = int(1, 2);
      for (var k = 0; k < n; k++) {
        var dep = r() < 0.4 ? int(1, 4) : null, prix = 5 * int(1, 18), stock = r() < 0.2 ? 0 : int(1, 6);
        articles.push([++aid, j[0], ETATS[(k + j[0]) % 3], prix, dep ? null : Math.round(prix * 0.45), stock, dep]);
      }
    });
    var clients = HIDDEN_PEOPLE.map(function (p, i) { return [i + 1, p[0], p[1], r() < 0.2 ? null : (p[1] + '.' + p[0]).toLowerCase().replace(/[^a-z.]/g, '') + '@mail.fr', pick(CITIES), '20' + int(24, 26) + '-0' + int(1, 9) + '-1' + int(0, 9)]; });
    var ventes = [], lignes = [];
    for (var v = 1; v <= 42; v++) {
      var month = int(1, 10), y = r() < 0.15 ? 2025 : 2026;
      ventes.push([v, y + '-' + (month < 10 ? '0' : '') + month + '-' + (10 + int(0, 18)), r() < 0.25 ? null : int(1, clients.length - 3), int(1, 2)]);
      var used = {};
      for (var l = int(1, 3); l > 0; l--) {
        var a = pick(articles);
        if (used[a[0]]) continue;
        used[a[0]] = true;
        lignes.push([v, a[0], r() < 0.85 ? 1 : 2, a[3]]);
      }
    }
    var statuts = ['annulée', 'en cours', 'retirée', 'annulée'], reservations = [];
    for (var s = 1; s <= 14; s++) reservations.push([s, pick(articles)[0], int(1, clients.length), r() < 0.85 ? 1 : 2, '2026-' + '0' + int(4, 9) + '-1' + int(0, 9), pick(statuts)]);
    return { consoles: CONSOLES, jeux: jeux, deposants: DEPOSANTS, articles: articles, clients: clients, ventes: ventes, lignes: lignes, reservations: reservations, tableur: hiddenTableur(r, int) };
  }
  function hiddenTableur(r, int) {
    var spellings = ['Mega Drive', 'MEGADRIVE', 'mega drive', 'Megadrive', 'MD'];
    var rows = [];
    ['Golden Axe', 'Comix Zone', 'Sonic 3', 'Castle of Illusion', 'Phantasy Star IV', 'Ecco Jr.'].forEach(function (t, i) { rows.push(['K' + (100 + i), t, spellings[i % 5], ETATS[i % 3], 5 * int(2, 12), int(0, 5), i % 2 ? 'Ferrand' : '', i % 2 ? '06 12 34 56 78' : '']); });
    ['Super Metroid', 'Star Fox', 'Final Fantasy VI'].forEach(function (t, i) { rows.push(['K' + (200 + i), t, ['Super Nintendo', 'SNES', 'Super Nes'][i], 'complet', 5 * int(4, 14), int(0, 3), '', '']); });
    ['Tekken 3', 'Spyro the Dragon'].forEach(function (t, i) { rows.push(['K' + (300 + i), t, ['PlayStation', 'PS1'][i], 'complet', 5 * int(2, 8), int(1, 4), i ? 'Nguyen' : '', i ? '06 98 76 54 32' : '']); });
    rows.push(['K400', 'Wario Land', 'Gameboy', 'loose', 10, 3, '', '']);
    rows.push(['K401', 'Daytona USA', 'Saturn', 'complet', 25, 1, 'Diallo', '06 45 67 89 01']);
    return rows;
  }
  // The shelf count of 1 November: what is physically there - the base's stock, plus what is set
  // aside for a reservation in progress, plus what the « thief » took twice at each cancellation.
  function inventaire(articles, reservations) {
    var gap = {};
    reservations.forEach(function (rv) {
      if (rv[5] === 'en cours') gap[rv[1]] = (gap[rv[1]] || 0) + rv[3];
      if (rv[5] === 'annulée') gap[rv[1]] = (gap[rv[1]] || 0) + 2 * rv[3];
    });
    return articles.map(function (a) { return [a[0], a[5] + (gap[a[0]] || 0), '2026-11-01']; });
  }

  // ——— SQL ———
  function lit(v) { return v === null || v === undefined ? 'NULL' : (typeof v === 'number' ? String(v) : "'" + String(v).replace(/'/g, "''") + "'"); }
  function inserts(table, cols, rows) {
    if (!rows.length) return '';
    return 'INSERT INTO ' + table + ' (' + cols.join(', ') + ') VALUES\n' + rows.map(function (r) { return '(' + r.map(lit).join(', ') + ')'; }).join(',\n') + ';\n';
  }
  var SCHEMA = [
    'CREATE TABLE Console (\n  idConsole INT AUTO_INCREMENT PRIMARY KEY,\n  nom VARCHAR(40) NOT NULL UNIQUE,\n  fabricant VARCHAR(30) NOT NULL,\n  anneeSortie INT\n);',
    'CREATE TABLE Jeu (\n  idJeu INT AUTO_INCREMENT PRIMARY KEY,\n  titre VARCHAR(80) NOT NULL,\n  anneeSortie INT,\n  genre VARCHAR(30),\n  idConsole INT NOT NULL,\n  FOREIGN KEY (idConsole) REFERENCES Console(idConsole)\n);',
    'CREATE TABLE Deposant (\n  idDeposant INT AUTO_INCREMENT PRIMARY KEY,\n  nom VARCHAR(40) NOT NULL,\n  prenom VARCHAR(40) NOT NULL,\n  telephone VARCHAR(20),\n  tauxCommission DECIMAL(3,2) NOT NULL\n);',
    'CREATE TABLE Article (\n  idArticle INT AUTO_INCREMENT PRIMARY KEY,\n  idJeu INT NOT NULL,\n  etat VARCHAR(20) NOT NULL,\n  prixVente DECIMAL(6,2) NOT NULL,\n  prixAchat DECIMAL(6,2),\n  stock INT NOT NULL DEFAULT 0,\n  idDeposant INT,\n  FOREIGN KEY (idJeu) REFERENCES Jeu(idJeu),\n  FOREIGN KEY (idDeposant) REFERENCES Deposant(idDeposant)\n);',
    'CREATE TABLE Client (\n  idClient INT AUTO_INCREMENT PRIMARY KEY,\n  nom VARCHAR(40) NOT NULL,\n  prenom VARCHAR(40) NOT NULL,\n  email VARCHAR(80),\n  ville VARCHAR(40),\n  dateInscription DATE\n);',
    'CREATE TABLE Vente (\n  idVente INT AUTO_INCREMENT PRIMARY KEY,\n  dateVente DATE NOT NULL,\n  idClient INT,\n  caisse INT NOT NULL DEFAULT 1,\n  FOREIGN KEY (idClient) REFERENCES Client(idClient)\n);',
    'CREATE TABLE LigneVente (\n  idVente INT NOT NULL,\n  idArticle INT NOT NULL,\n  quantite INT NOT NULL,\n  prixUnitaire DECIMAL(6,2) NOT NULL,\n  PRIMARY KEY (idVente, idArticle),\n  FOREIGN KEY (idVente) REFERENCES Vente(idVente),\n  FOREIGN KEY (idArticle) REFERENCES Article(idArticle)\n);',
    'CREATE TABLE Reservation (\n  idReservation INT AUTO_INCREMENT PRIMARY KEY,\n  idArticle INT NOT NULL,\n  idClient INT NOT NULL,\n  quantite INT NOT NULL DEFAULT 1,\n  dateReservation DATE NOT NULL,\n  statut VARCHAR(20) NOT NULL DEFAULT \'en cours\',\n  FOREIGN KEY (idArticle) REFERENCES Article(idArticle),\n  FOREIGN KEY (idClient) REFERENCES Client(idClient)\n);',
    'CREATE TABLE Inventaire (\n  idArticle INT PRIMARY KEY,\n  quantiteComptee INT NOT NULL,\n  dateComptage DATE NOT NULL,\n  FOREIGN KEY (idArticle) REFERENCES Article(idArticle)\n);'
  ];
  // Written in 2019 by Gilles' nephew, before Jade: a reservation sets the article aside, a
  // cancellation… sets it aside a second time. The « thief » of the game.
  var TRIGGERS_2019 = [
    'DELIMITER //\nCREATE TRIGGER trg_reservation AFTER INSERT ON Reservation\nFOR EACH ROW\nBEGIN\n  UPDATE Article SET stock = stock - NEW.quantite\n  WHERE idArticle = NEW.idArticle;\nEND //\nDELIMITER ;',
    'DELIMITER //\nCREATE TRIGGER trg_annulation AFTER UPDATE ON Reservation\nFOR EACH ROW\nBEGIN\n  IF NEW.statut = \'annulée\' AND OLD.statut <> \'annulée\' THEN\n    UPDATE Article SET stock = stock - NEW.quantite\n    WHERE idArticle = NEW.idArticle;\n  END IF;\nEND //\nDELIMITER ;'
  ];
  // What later acts take for granted, once an earlier act built it (the reference answers).
  var LATER = [
    { from: 7, sql: 'CREATE TABLE HistoriquePrix (\n  idHistorique INT AUTO_INCREMENT PRIMARY KEY,\n  idArticle INT NOT NULL,\n  ancienPrix DECIMAL(6,2) NOT NULL,\n  nouveauPrix DECIMAL(6,2) NOT NULL,\n  dateChangement DATE NOT NULL,\n  FOREIGN KEY (idArticle) REFERENCES Article(idArticle)\n);' },
    { from: 8, sql: 'DELIMITER //\nCREATE TRIGGER trg_vente_stock AFTER INSERT ON LigneVente\nFOR EACH ROW\nBEGIN\n  UPDATE Article SET stock = stock - NEW.quantite\n  WHERE idArticle = NEW.idArticle;\nEND //\nDELIMITER ;' },
    { from: 8, sql: 'DELIMITER //\nCREATE TRIGGER trg_verif_stock BEFORE INSERT ON LigneVente\nFOR EACH ROW\nBEGIN\n  DECLARE dispo INT;\n  SELECT stock INTO dispo FROM Article WHERE idArticle = NEW.idArticle;\n  IF dispo < NEW.quantite THEN\n    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Stock insuffisant\';\n  END IF;\nEND //\nDELIMITER ;' },
    { from: 9, sql: 'CREATE VIEW CatalogueEnLigne AS\nSELECT j.titre, c.nom AS console, a.etat, a.prixVente, a.stock\nFROM Article a\nJOIN Jeu j ON j.idJeu = a.idJeu\nJOIN Console c ON c.idConsole = j.idConsole\nWHERE a.stock > 0;' },
    { from: 9, sql: 'CREATE INDEX idx_jeu_titre ON Jeu (titre);' },
    { from: 9, sql: 'ALTER TABLE Article ADD CONSTRAINT chk_prix CHECK (prixVente > 0);' }
  ];

  function script(act, which) {
    var d = which === 'hidden' ? hiddenData() : { consoles: CONSOLES, jeux: JEUX, deposants: DEPOSANTS, articles: ARTICLES, clients: CLIENTS, ventes: VENTES, lignes: LIGNES, reservations: RESERVATIONS, tableur: TABLEUR };
    if (act <= 1) {
      return 'CREATE TABLE TableurGilles (\n  ref VARCHAR(10),\n  titre VARCHAR(80),\n  console VARCHAR(40),\n  etat VARCHAR(20),\n  prix DECIMAL(6,2),\n  stock INT,\n  deposant VARCHAR(40),\n  telDeposant VARCHAR(20)\n);\n' +
        inserts('TableurGilles', ['ref', 'titre', 'console', 'etat', 'prix', 'stock', 'deposant', 'telDeposant'], d.tableur);
    }
    var out = SCHEMA.join('\n') + '\n' +
      inserts('Console', ['idConsole', 'nom', 'fabricant', 'anneeSortie'], d.consoles) +
      inserts('Jeu', ['idJeu', 'titre', 'anneeSortie', 'genre', 'idConsole'], d.jeux) +
      inserts('Deposant', ['idDeposant', 'nom', 'prenom', 'telephone', 'tauxCommission'], d.deposants) +
      inserts('Article', ['idArticle', 'idJeu', 'etat', 'prixVente', 'prixAchat', 'stock', 'idDeposant'], d.articles) +
      inserts('Client', ['idClient', 'nom', 'prenom', 'email', 'ville', 'dateInscription'], d.clients) +
      inserts('Vente', ['idVente', 'dateVente', 'idClient', 'caisse'], d.ventes) +
      inserts('LigneVente', ['idVente', 'idArticle', 'quantite', 'prixUnitaire'], d.lignes) +
      inserts('Reservation', ['idReservation', 'idArticle', 'idClient', 'quantite', 'dateReservation', 'statut'], d.reservations) +
      inserts('Inventaire', ['idArticle', 'quantiteComptee', 'dateComptage'], inventaire(d.articles, d.reservations));
    // The triggers come after the data: the stock of the base is already what they made of it.
    out += TRIGGERS_2019.join('\n') + '\n';
    LATER.forEach(function (l) { if (act >= l.from) out += l.sql + '\n'; });
    return out;
  }

  // The day the shop lives at, per act (NOW(), CURDATE()): results do not depend on the day one plays.
  var TODAY = { 1: '2026-11-02', 2: '2026-11-04', 3: '2026-11-06', 4: '2026-11-09', 5: '2026-11-12', 6: '2026-11-16', 7: '2026-11-20', 8: '2026-11-27', 9: '2026-11-30' };

  root.RetroBase = { script: script, today: function (act) { return TODAY[act] || TODAY[9]; }, data: { consoles: CONSOLES, jeux: JEUX, articles: ARTICLES, reservations: RESERVATIONS } };
})(typeof globalThis !== 'undefined' ? globalThis : window);
