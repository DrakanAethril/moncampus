/* SQL pas à pas — the base the whole game reads, written as the MySQL a student would type.

   One schema, two sets of rows: the « visible » base the student sees, tries and is shown, and the
   « hidden » base every check replays the query on. The hidden rows are chosen to set traps the visible
   ones cannot: other identifiers (a query listing ids fails), years exactly on the boundaries the
   questions name (1900, 1910, 1950, 1958, 2000), NULL where the visible base has a value, equal values
   where an ORDER BY or a GROUP BY must cope with them, names that a careless LIKE catches.

   SPPBase.script(which) → the MySQL script that builds that base ('visible' or 'hidden')
   SPPBase.TODAY        → what CURDATE() answers, so that a date question has one right answer */
(function (root) {
  'use strict';

  var TODAY = '2026-11-02';

  // id, inventaire, nom, classe, origine, annee_entree, salle, poids_kg, valeur, derniere_revue
  var SPECIMENS = {
    visible: [
      [1, 'MHN-1872-004', 'Grand pingouin', 'Oiseaux', 'Europe', 1872, 'R1', 5.2, 12000, '2024-03-12'],
      [2, 'MHN-1881-112', 'Loup gris', 'Mammifères', 'Europe', 1881, 'R2', 38.5, 4500, '2025-06-02'],
      [3, 'MHN-1890-031', 'Ammonite géante', 'Fossiles', 'Europe', 1890, 'R3', 64, 8000, '2023-11-20'],
      [4, 'MHN-1902-210', 'Martin-pêcheur', 'Oiseaux', 'Europe', 1902, 'R1', 0.04, 300, '2025-01-15'],
      [5, 'MHN-1905-007', 'Ours brun', 'Mammifères', 'Europe', 1905, 'R2', 180, 9500, '2022-09-30'],
      [6, 'MHN-1911-145', 'Trilobite', 'Fossiles', 'Afrique', 1911, 'R3', 0.3, 1500, null],
      [7, 'MHN-1923-056', 'Chouette effraie', 'Oiseaux', 'Europe', 1923, 'R1', 0.35, 650, '2025-01-15'],
      [8, 'MHN-1930-018', 'Tortue luth', 'Reptiles', 'Amérique', 1930, 'R2', 310, 7200, '2024-07-08'],
      [9, 'MHN-1934-090', 'Lynx boréal', 'Mammifères', 'Europe', 1934, 'R2', 22, 3800, '2025-06-02'],
      [10, 'MHN-1947-003', 'Coelacanthe', 'Poissons', 'Afrique', 1947, 'R3', 80, 25000, '2024-10-21'],
      [11, 'MHN-1951-077', 'Héron cendré', 'Oiseaux', 'Europe', 1951, 'R1', 1.8, 420, null],
      [12, 'MHN-1958-140', 'Crocodile du Nil', 'Reptiles', 'Afrique', 1958, 'R2', 240, 6800, '2023-04-17'],
      [13, 'MHN-1962-021', 'Dent de mégalodon', 'Fossiles', 'Amérique', 1962, 'R3', 1.1, 5200, '2025-09-09'],
      [14, 'MHN-1969-066', 'Aigle royal', 'Oiseaux', 'Europe', 1969, 'R1', 4.6, 2100, '2024-03-12'],
      [15, 'MHN-1974-102', 'Brochet', 'Poissons', 'Europe', 1974, 'R3', 7.5, 180, '2022-02-14'],
      [16, 'MHN-1979-008', 'Renard roux', 'Mammifères', 'Europe', 1979, 'R2', 6.2, 350, '2025-06-02'],
      [17, 'MHN-1983-055', 'Vipère aspic', 'Reptiles', 'Europe', 1983, null, 0.2, 120, null],
      [18, 'MHN-1988-131', 'Flamant rose', 'Oiseaux', 'Afrique', 1988, 'R1', 3.1, 900, '2024-03-12'],
      [19, 'MHN-1991-017', 'Hérisson', 'Mammifères', 'Europe', 1991, 'R2', 0.9, 150, '2025-06-02'],
      [20, 'MHN-1996-044', 'Archéoptéryx (moulage)', 'Fossiles', 'Europe', 1996, 'R3', 2.4, 3000, '2023-11-20'],
      [21, 'MHN-2003-009', 'Esturgeon', 'Poissons', 'Europe', 2003, 'R3', 95, 2600, '2024-10-21'],
      [22, 'MHN-2008-120', 'Rouge-gorge', 'Oiseaux', 'Europe', 2008, 'R1', 0.02, 80, null],
      [23, 'MHN-2015-033', 'Salamandre tachetée', 'Amphibiens', 'Europe', 2015, 'R2', 0.04, 90, '2025-03-03'],
      [24, 'MHN-2021-002', 'Pic noir', 'Oiseaux', 'Europe', 2021, 'R1', 0.3, 250, '2025-01-15'],
      [25, 'MHN-1899-061', 'Morpho bleu', 'Insectes', 'Amérique', 1899, 'R4', null, 400, '2024-05-06'],
      [26, 'MHN-1937-012', 'Scarabée sacré', 'Insectes', 'Afrique', 1937, 'R4', null, 220, '2024-05-06'],
      [27, 'MHN-1965-088', 'Panda géant', 'Mammifères', 'Asie', 1965, null, 105, 15000, '2023-08-25'],
      [28, 'MHN-1977-031', 'Python royal', 'Reptiles', 'Afrique', 1977, 'R2', 1.5, 600, '2023-04-17'],
      [29, 'MHN-2012-047', 'Kiwi', 'Oiseaux', 'Océanie', 2012, null, 2.8, 1800, '2025-09-09'],
      [30, 'MHN-1954-019', 'Grenouille rousse', 'Amphibiens', 'Europe', 1954, 'R2', null, 60, '2025-03-03']
    ],
    hidden: [
      [101, 'MHN-1866-019', 'Phoque veau-marin', 'Mammifères', 'Europe', 1866, 'R2', 85, 5400, '2024-02-01'],
      [102, 'MHN-1879-044', 'Grand tétras', 'Oiseaux', 'Europe', 1879, 'R1', 4.1, 1300, '2025-02-11'],
      [103, 'MHN-1895-002', 'Nautile', 'Fossiles', 'Océanie', 1895, 'R3', 0.8, 2400, null],
      [104, 'MHN-1900-071', 'Gypaète barbu', 'Oiseaux', 'Europe', 1900, null, 6, 3100, '2024-02-01'],
      [105, 'MHN-1910-150', 'Esturgeon d\'Europe', 'Poissons', 'Europe', 1910, 'R3', 120, 2600, '2023-06-19'],
      [106, 'MHN-1927-033', 'Lézard ocellé', 'Reptiles', 'Europe', 1927, 'R2', 0.5, 180, '2025-05-20'],
      [107, 'MHN-1939-008', 'Bouquetin', 'Mammifères', 'Europe', 1939, 'R2', 75, 2600, '2025-05-20'],
      [108, 'MHN-1950-061', 'Castor d\'Europe', 'Mammifères', 'Europe', 1950, 'R2', 20, 1200, null],
      [109, 'MHN-1950-062', 'Outarde canepetière', 'Oiseaux', 'Europe', 1950, 'R1', 0.9, 700, '2024-09-30'],
      [110, 'MHN-1966-014', 'Requin pèlerin (mâchoire)', 'Poissons', 'Europe', 1966, 'R3', 45, 4100, '2023-06-19'],
      [111, 'MHN-1972-090', 'Grue cendrée', 'Oiseaux', 'Europe', 1972, 'R1', 5.5, 1300, '2025-02-11'],
      [112, 'MHN-1985-027', 'Triton crêté', 'Amphibiens', 'Europe', 1985, null, 0.01, 40, '2025-05-20'],
      [113, 'MHN-1993-005', 'Oursin fossile', 'Fossiles', 'Afrique', 1993, 'R3', null, 75, '2023-01-10'],
      [114, 'MHN-2000-118', 'Loutre', 'Mammifères', 'Europe', 2000, 'R2', 9, 800, '2024-09-30'],
      [115, 'MHN-2012-040', 'Huppe fasciée', 'Oiseaux', 'Afrique', 2012, 'R1', 0.07, 140, null],
      [116, 'MHN-2019-003', 'Couleuvre verte et jaune', 'Reptiles', 'Europe', 2019, 'R2', 0.6, 110, '2025-08-14'],
      [117, 'MHN-1958-201', 'Varan de Komodo', 'Reptiles', 'Asie', 1958, 'R2', 70, 9800, '2024-12-02'],
      [118, 'MHN-1908-077', 'Lucane cerf-volant', 'Insectes', 'Europe', 1908, 'R4', null, 35, '2024-12-02'],
      [119, 'MHN-1949-030', 'Papillon machaon', 'Insectes', 'Europe', 1949, 'R4', null, 50, null],
      [120, 'MHN-1931-062', 'Tigre de Sibérie', 'Mammifères', 'Asie', 1931, null, 210, 18000, '2023-01-10'],
      [121, 'MHN-1961-015', 'Albatros hurleur', 'Oiseaux', 'Océanie', 1961, 'R1', 9, 2200, '2025-08-14'],
      [122, 'MHN-1977-090', 'Raie manta', 'Poissons', 'Océanie', 1977, 'R3', 400, 5000, '2024-06-03'],
      [123, 'MHN-1902-006', 'Mammouth (défense)', 'Fossiles', 'Asie', 1902, 'R3', 55, 30000, '2025-02-11'],
      [124, 'MHN-2024-001', 'Rougequeue noir', 'Oiseaux', 'Europe', 2024, 'R1', 0.02, 60, null],
      [125, 'MHN-1988-200', 'Axolotl', 'Amphibiens', 'Amérique', 1988, 'R2', 0.1, 95, '2025-08-14'],
      [126, 'MHN-1945-010', 'Gorille', 'Mammifères', 'Afrique', 1945, 'R2', 160, 12000, '2024-06-03'],
      [127, 'MHN-1999-033', 'Mygale', 'Insectes', 'Amérique', 1999, 'R4', null, 70, '2025-05-20'],
      [128, 'MHN-1926-008', 'Pingouin torda', 'Oiseaux', 'Europe', 1926, 'R1', 0.7, 500, '2024-09-30'],
      [129, 'MHN-2005-044', 'Anguille', 'Poissons', 'Europe', 2005, null, 1.2, 30, '2023-06-19'],
      [130, 'MHN-1984-019', 'Caméléon', 'Reptiles', 'Afrique', 1984, 'R2', 0.15, 210, null]
    ]
  };
  // code, libelle, etage, surface_m2
  var SALLES = {
    visible: [['R1', 'Galerie des oiseaux', 1, 220], ['R2', 'Salle des vertébrés', 1, 340], ['R3', 'Paléontologie', 0, 410], ['R4', 'Entomologie', 2, 90], ['R5', 'Réserve froide', -1, 60]],
    hidden: [['R1', 'Volière', 1, 180], ['R2', 'Grande galerie', 1, 500], ['R3', 'Fossiles et mers', 0, 300], ['R4', 'Insectarium', 2, 75], ['R6', 'Salle des minéraux', 2, 120]]
  };
  // id, nom, ville, pays
  var INSTITUTIONS = {
    visible: [[1, 'Muséum de Lyon', 'Lyon', 'France'], [2, 'Muséum de Genève', 'Genève', 'Suisse'], [3, 'Natural History Museum', 'Londres', 'Royaume-Uni'], [4, 'Muséum de Toulouse', 'Toulouse', 'France'], [5, 'Museo di Storia Naturale', 'Florence', 'Italie'], [6, 'Muséum de Nantes', 'Nantes', 'France']],
    hidden: [[11, 'Muséum de Bordeaux', 'Bordeaux', 'France'], [12, 'Naturalis', 'Leyde', 'Pays-Bas'], [13, 'Muséum de Toulouse', 'Toulouse', 'France'], [14, 'Senckenberg', 'Francfort', 'Allemagne'], [15, 'Muséum de Rouen', 'Rouen', 'France'], [16, 'Museo Nacional', 'Madrid', 'Espagne']]
  };
  // id, specimen_id, institution_id, depart, retour
  var PRETS = {
    visible: [[1, 10, 3, '2019-03-01', '2019-09-30'], [2, 1, 2, '2021-05-10', '2021-12-20'], [3, 27, 1, '2022-01-15', '2022-06-30'], [4, 10, 5, '2023-02-01', '2023-08-31'], [5, 13, 4, '2024-04-01', null], [6, 20, 1, '2024-09-15', null], [7, 12, 2, '2025-01-10', '2025-06-30'], [8, 5, 3, '2025-03-01', null], [9, 3, 4, '2020-06-01', '2020-12-31']],
    hidden: [[21, 123, 12, '2018-05-01', '2018-11-30'], [22, 120, 14, '2020-02-01', null], [23, 101, 11, '2021-03-15', '2021-09-15'], [24, 123, 16, '2022-06-01', '2022-12-01'], [25, 117, 13, '2023-01-20', null], [26, 110, 11, '2023-05-05', '2023-10-05'], [27, 126, 12, '2024-02-10', null], [28, 104, 13, '2024-11-01', '2025-03-31'], [29, 122, 14, '2025-04-01', null], [30, 103, 16, '2019-07-01', '2019-12-31']]
  };

  var SCHEMA = [
    'CREATE TABLE salle (\n  code VARCHAR(4) PRIMARY KEY,\n  libelle VARCHAR(40) NOT NULL,\n  etage INT NOT NULL,\n  surface_m2 INT NOT NULL\n)',
    'CREATE TABLE specimen (\n  id INT PRIMARY KEY,\n  inventaire VARCHAR(12) NOT NULL UNIQUE,\n  nom VARCHAR(60) NOT NULL,\n  classe VARCHAR(20) NOT NULL,\n  origine VARCHAR(20) NOT NULL,\n  annee_entree INT NOT NULL,\n  salle VARCHAR(4) NULL,\n  poids_kg DECIMAL(7,2) NULL,\n  valeur INT NOT NULL,\n  derniere_revue DATE NULL,\n  FOREIGN KEY (salle) REFERENCES salle(code)\n)',
    'CREATE TABLE institution (\n  id INT PRIMARY KEY,\n  nom VARCHAR(60) NOT NULL,\n  ville VARCHAR(40) NOT NULL,\n  pays VARCHAR(40) NOT NULL\n)',
    'CREATE TABLE pret (\n  id INT PRIMARY KEY,\n  specimen_id INT NOT NULL,\n  institution_id INT NOT NULL,\n  depart DATE NOT NULL,\n  retour DATE NULL,\n  FOREIGN KEY (specimen_id) REFERENCES specimen(id),\n  FOREIGN KEY (institution_id) REFERENCES institution(id)\n)'
  ];

  function lit(v) {
    if (v === null || v === undefined) return 'NULL';
    if (typeof v === 'number') return String(v);
    return "'" + String(v).replace(/'/g, "''") + "'";
  }
  function insert(table, rows) { return 'INSERT INTO ' + table + ' VALUES\n' + rows.map(function (r) { return '  (' + r.map(lit).join(', ') + ')'; }).join(',\n'); }

  function script(which) {
    var w = which === 'hidden' ? 'hidden' : 'visible';
    return SCHEMA.join(';\n') + ';\n' + [insert('salle', SALLES[w]), insert('specimen', SPECIMENS[w]), insert('institution', INSTITUTIONS[w]), insert('pret', PRETS[w])].join(';\n') + ';\n';
  }

  /** The tables of the base and their columns, as the map of the base shows them. */
  var TABLES = [
    { name: 'specimen', columns: ['id', 'inventaire', 'nom', 'classe', 'origine', 'annee_entree', 'salle', 'poids_kg', 'valeur', 'derniere_revue'], keys: { id: 'pk', salle: 'fk' }, from: 1 },
    { name: 'salle', columns: ['code', 'libelle', 'etage', 'surface_m2'], keys: { code: 'pk' }, from: 5 },
    { name: 'institution', columns: ['id', 'nom', 'ville', 'pays'], keys: { id: 'pk' }, from: 5 },
    { name: 'pret', columns: ['id', 'specimen_id', 'institution_id', 'depart', 'retour'], keys: { id: 'pk', specimen_id: 'fk', institution_id: 'fk' }, from: 5 }
  ];

  root.SPPBase = { script: script, TODAY: TODAY, TABLES: TABLES, SPECIMENS: SPECIMENS };
})(typeof globalThis !== 'undefined' ? globalThis : window);
