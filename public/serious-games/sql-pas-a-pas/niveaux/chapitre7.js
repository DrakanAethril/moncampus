/* SQL pas à pas — chapter 7, « Créer sa base »: CREATE TABLE, types, keys, FOREIGN KEY, ALTER TABLE; then
   the final test, drawn from every timed challenge. A structure is checked on what the student created -
   its columns, their types and constraints - then by probes: statements that must pass or be refused. */
(function (C) {
  'use strict';
  function col(name, type, more) { var c = { name: name, type: type }; for (var k in more || {}) c[k] = more[k]; return c; }

  C.level({
    id: 'N25', chapter: 7, num: 25, title: 'Créer une table', summary: 'CREATE TABLE · INT · VARCHAR · DATE · DECIMAL · NOT NULL · DEFAULT', notions: ['create'],
    lessons: {
      create: {
        title: 'CREATE TABLE et les types',
        html: `<p>On crée une table en donnant, pour chaque colonne, son nom et son <b>type</b> :</p>
<div class="rule"><span class="kw">CREATE TABLE</span> visiteur (<br>&nbsp;&nbsp;id <span class="kw">INT</span>,<br>&nbsp;&nbsp;nom <span class="kw">VARCHAR</span>(60),<br>&nbsp;&nbsp;inscrit_le <span class="kw">DATE</span><br>);</div>
<ul><li><code>INT</code> : un nombre entier ;</li><li><code>VARCHAR(n)</code> : un texte de <code>n</code> caractères au plus ;</li><li><code>DATE</code> : une date ;</li><li><code>DECIMAL(8,2)</code> : un nombre à virgule, 8 chiffres dont 2 après la virgule (un prix).</li></ul>
<p>Le type dit ce que la colonne accepte : MySQL refuse un texte dans une colonne <code>INT</code>, et sait trier ou calculer avec une <code>DATE</code>.</p>`,
        example: 'CREATE TABLE visiteur (id INT, nom VARCHAR(60), inscrit_le DATE);',
        tryit: 'Ajoute une colonne ville de 40 caractères.'
      },
      contraintes: {
        title: 'NOT NULL et DEFAULT',
        html: `<p>Après le type, on peut ajouter des contraintes :</p>
<ul><li><code>NOT NULL</code> : la colonne est obligatoire, MySQL refuse une ligne qui la laisse vide ;</li><li><code>DEFAULT 0</code> : la valeur mise quand l'INSERT ne la donne pas.</li></ul>
<div class="rule"><span class="kw">CREATE TABLE</span> exposition (id <span class="kw">INT</span>, titre <span class="kw">VARCHAR</span>(80) <span class="kw">NOT NULL</span>, visiteurs <span class="kw">INT DEFAULT</span> 0);</div>
<p>Les colonnes se séparent par des virgules ; la dernière n'en a pas.</p>`,
        example: "CREATE TABLE exposition (id INT, titre VARCHAR(80) NOT NULL, visiteurs INT DEFAULT 0); INSERT INTO exposition (id, titre) VALUES (1, 'Dinosaures'); SELECT * FROM exposition;",
        tryit: 'Essaie d\'insérer une exposition sans titre.'
      }
    },
    steps: {
      predire: { before: 'create', kind: 'value', ask: 'Combien de colonnes aura la table créée par cette requête ?', query: 'CREATE TABLE visiteur (id INT, nom VARCHAR(40), ville VARCHAR(40));', value: 'SELECT 3', hints: ['Une colonne par nom suivi d\'un type.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Crée la table atelier : un id entier et un nom de 40 caractères au plus.', template: 'CREATE TABLE atelier (id [[]], nom [[]](40));', fill: ['INT', 'VARCHAR'], ref: 'CREATE TABLE atelier (id INT, nom VARCHAR(40))', check: 'create', expect: [{ table: 'atelier', columns: [col('id', 'int'), col('nom', 'text')] }], hints: ['Un type d\'entier, puis un type de texte.', 'CREATE TABLE atelier (id ___, nom _______(40));'] },
      reparer: { before: 'contraintes', kind: 'repair', ask: 'Cette requête doit créer la table animateur : un id entier, un nom obligatoire de 40 caractères au plus. MySQL la refuse : répare-la.', starter: 'CREATE TABLE animateur (id INT, nom VARCHAR(40) NOT NULL,);', ref: 'CREATE TABLE animateur (id INT, nom VARCHAR(40) NOT NULL)', check: 'create', expect: [{ table: 'animateur', columns: [col('id', 'int'), col('nom', 'text', { notNull: true })] }], hints: ['Regarde juste avant la parenthèse fermante.', 'CREATE TABLE animateur (id INT, nom VARCHAR(40) NOT NULL);'] },
      ecrire1: { kind: 'write', ask: 'Crée la table visiteur avec les colonnes id (entier), nom (60 caractères au plus, obligatoire) et date_inscription (une date).', ref: 'CREATE TABLE visiteur (id INT, nom VARCHAR(60) NOT NULL, date_inscription DATE)', check: 'create', expect: [{ table: 'visiteur', columns: [col('id', 'int'), col('nom', 'text', { notNull: true }), col('date_inscription', 'date')] }], head: 0, hints: ['Trois colonnes, chacune son type ; « obligatoire », c\'est NOT NULL.', 'CREATE TABLE visiteur (id INT, nom VARCHAR(60) ________, date_inscription ____);'] },
      ecrire2: { kind: 'write', ask: 'Crée la table don avec les colonnes id (entier), montant (DECIMAL(8,2)), date_don (une date) et donateur (60 caractères au plus).', ref: 'CREATE TABLE don (id INT, montant DECIMAL(8,2), date_don DATE, donateur VARCHAR(60))', check: 'create', expect: [{ table: 'don', columns: [col('id', 'int'), col('montant', 'decimal'), col('date_don', 'date'), col('donateur', 'text')] }], head: 0, hints: ['Quatre colonnes ; un montant en euros et centimes est un DECIMAL.', 'CREATE TABLE don (id INT, montant DECIMAL(8,2), date_don ____, donateur _______(60));'] },
      ecrire3: { kind: 'write', ask: 'Crée la table exposition avec id (entier), titre (80 caractères, obligatoire), debut et fin (deux dates) et visiteurs (entier, 0 par défaut).', ref: 'CREATE TABLE exposition (id INT, titre VARCHAR(80) NOT NULL, debut DATE, fin DATE, visiteurs INT DEFAULT 0)', check: 'create', expect: [{ table: 'exposition', columns: [col('id', 'int'), col('titre', 'text', { notNull: true }), col('debut', 'date'), col('fin', 'date'), col('visiteurs', 'int', { dflt: 0 })] }], head: 0, hints: ['« 0 par défaut » : DEFAULT 0, après le type.', 'CREATE TABLE exposition (id INT, titre VARCHAR(80) NOT NULL, debut DATE, fin DATE, visiteurs INT _______ _);'] }
    }
  });

  C.level({
    id: 'N26', chapter: 7, num: 26, title: 'Identifier les lignes', summary: 'PRIMARY KEY · AUTO_INCREMENT · UNIQUE', notions: ['pk'],
    lessons: {
      pk: {
        title: 'La clé primaire',
        html: `<p>Chaque table devrait avoir une <b>clé primaire</b> : une colonne dont la valeur désigne une ligne et une seule. MySQL refuse deux lignes avec la même clé, et une clé vide.</p>
<div class="rule"><span class="kw">CREATE TABLE</span> badge (id <span class="kw">INT AUTO_INCREMENT PRIMARY KEY</span>, code <span class="kw">VARCHAR</span>(10));</div>
<p><code>AUTO_INCREMENT</code> numérote tout seul : un INSERT qui ne donne pas d'id reçoit le suivant (1, 2, 3…).</p>`,
        example: "CREATE TABLE badge (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(10)); INSERT INTO badge (code) VALUES ('A'), ('B'); SELECT * FROM badge;",
        tryit: 'Insère un badge avec un id déjà pris : lis l\'erreur.'
      },
      unique: {
        title: 'UNIQUE',
        html: `<p><code>UNIQUE</code> interdit les doublons dans une autre colonne que la clé primaire : une adresse électronique, un numéro de carte.</p>
<div class="rule">email <span class="kw">VARCHAR</span>(100) <span class="kw">NOT NULL UNIQUE</span></div>
<p>Deux membres ne peuvent pas avoir la même adresse : le second INSERT est refusé (<code>ERROR 1062 Duplicate entry</code>).</p>`,
        example: "CREATE TABLE membre (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL UNIQUE); INSERT INTO membre (email) VALUES ('a@b.fr'); INSERT INTO membre (email) VALUES ('a@b.fr');",
        fails: true,
        tryit: 'Change la seconde adresse : l\'insertion passe.'
      }
    },
    steps: {
      predire: { before: 'pk', kind: 'value', ask: 'Quel id reçoit le troisième badge ?', query: "CREATE TABLE badge (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(10));\nINSERT INTO badge (code) VALUES ('A');\nINSERT INTO badge (code) VALUES ('B');\nINSERT INTO badge (code) VALUES ('C');", value: 'SELECT 3', hints: ['AUTO_INCREMENT numérote 1, 2, 3…'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Crée la table badge : un id entier clé primaire, et un code de 10 caractères.', template: 'CREATE TABLE badge (id INT [[]] [[]], code VARCHAR(10));', fill: ['PRIMARY', 'KEY'], ref: 'CREATE TABLE badge (id INT PRIMARY KEY, code VARCHAR(10))', check: 'create', expect: [{ table: 'badge', columns: [col('id', 'int', { pk: true }), col('code', 'text')] }], probes: [{ sql: "INSERT INTO badge (id, code) VALUES (1, 'A'); INSERT INTO badge (id, code) VALUES (1, 'B');", expect: 'refused', label: 'Deux badges de même id sont refusés' }], hints: ['Deux mots : la clé primaire.', 'CREATE TABLE badge (id INT _______ ___, code VARCHAR(10));'] },
      reparer: { before: 'unique', kind: 'repair', ask: 'Cette requête doit créer la table carte : un id entier, clé primaire auto-incrémentée, et un numero de 12 caractères, unique. Il lui manque deux contraintes : répare-la.', starter: 'CREATE TABLE carte (id INT AUTO_INCREMENT, numero VARCHAR(12));', ref: 'CREATE TABLE carte (id INT AUTO_INCREMENT PRIMARY KEY, numero VARCHAR(12) UNIQUE)', check: 'create', expect: [{ table: 'carte', columns: [col('id', 'int', { pk: true }), col('numero', 'text', { unique: true })] }], probes: [{ sql: "INSERT INTO carte (numero) VALUES ('C-001'); INSERT INTO carte (numero) VALUES ('C-001');", expect: 'refused', label: 'Deux cartes de même numéro sont refusées' }], hints: ['La clé primaire manque à id, l\'unicité à numero.', 'CREATE TABLE carte (id INT AUTO_INCREMENT PRIMARY KEY, numero VARCHAR(12) ______);'] },
      ecrire1: { kind: 'write', ask: 'Crée la table membre : id (entier, clé primaire auto-incrémentée), email (100 caractères, obligatoire et unique) et nom (60 caractères).', ref: 'CREATE TABLE membre (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL UNIQUE, nom VARCHAR(60))', check: 'create', expect: [{ table: 'membre', columns: [col('id', 'int', { pk: true, auto: true }), col('email', 'text', { notNull: true, unique: true }), col('nom', 'text')] }], probes: [{ sql: "INSERT INTO membre (email, nom) VALUES ('ana@mail.fr', 'Ana')", expect: 'ok', label: 'Un membre sans id reçoit un numéro' }, { sql: "INSERT INTO membre (email, nom) VALUES ('ana@mail.fr', 'Bob')", expect: 'refused', label: 'Une adresse déjà prise est refusée' }, { sql: "INSERT INTO membre (nom) VALUES ('Cy')", expect: 'refused', label: 'Un membre sans adresse est refusé' }], head: 0, hints: ['AUTO_INCREMENT PRIMARY KEY pour id ; NOT NULL UNIQUE pour email.', 'CREATE TABLE membre (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) ___ ____ ______, nom VARCHAR(60));'] },
      ecrire2: { kind: 'write', ask: 'Crée la table ticket_entree : numero (entier, clé primaire), date_visite (une date, obligatoire) et tarif (DECIMAL(5,2)).', ref: 'CREATE TABLE ticket_entree (numero INT PRIMARY KEY, date_visite DATE NOT NULL, tarif DECIMAL(5,2))', check: 'create', expect: [{ table: 'ticket_entree', columns: [col('numero', 'int', { pk: true }), col('date_visite', 'date', { notNull: true }), col('tarif', 'decimal')] }], probes: [{ sql: "INSERT INTO ticket_entree (numero, date_visite, tarif) VALUES (1, '2026-11-02', 8.5); INSERT INTO ticket_entree (numero, date_visite, tarif) VALUES (1, '2026-11-03', 8.5);", expect: 'refused', label: 'Deux tickets de même numéro sont refusés' }], head: 0, hints: ['La clé primaire est numero, pas id.', 'CREATE TABLE ticket_entree (numero INT _______ ___, date_visite DATE ___ ____, tarif DECIMAL(5,2));'] },
      ecrire3: { kind: 'write', ask: 'Crée la table salle_expo : code (4 caractères, clé primaire) et nom (40 caractères, obligatoire et unique).', ref: 'CREATE TABLE salle_expo (code VARCHAR(4) PRIMARY KEY, nom VARCHAR(40) NOT NULL UNIQUE)', check: 'create', expect: [{ table: 'salle_expo', columns: [col('code', 'text', { pk: true }), col('nom', 'text', { notNull: true, unique: true })] }], probes: [{ sql: "INSERT INTO salle_expo (code, nom) VALUES ('E1', 'Océans'); INSERT INTO salle_expo (code, nom) VALUES ('E2', 'Océans');", expect: 'refused', label: 'Deux salles de même nom sont refusées' }], head: 0, hints: ['Une clé primaire peut être un texte.', 'CREATE TABLE salle_expo (code VARCHAR(4) PRIMARY KEY, nom VARCHAR(40) NOT NULL ______);'] }
    }
  });

  C.level({
    id: 'N27', chapter: 7, num: 27, title: 'Relier deux tables', summary: 'FOREIGN KEY · REFERENCES · ALTER TABLE', notions: ['fk', 'alter'],
    lessons: {
      fk: {
        title: 'FOREIGN KEY',
        html: `<p>Une <b>clé étrangère</b> déclare qu'une colonne fait référence à la clé primaire d'une autre table. Elle s'écrit après les colonnes :</p>
<div class="rule"><span class="kw">CREATE TABLE</span> visite (<br>&nbsp;&nbsp;id <span class="kw">INT PRIMARY KEY</span>,<br>&nbsp;&nbsp;specimen_id <span class="kw">INT</span>,<br>&nbsp;&nbsp;<span class="kw">FOREIGN KEY</span> (specimen_id) <span class="kw">REFERENCES</span> specimen(id)<br>);</div>
<p>MySQL refuse alors une visite dont le <code>specimen_id</code> n'existe pas dans <code>specimen</code> (<code>ERROR 1452</code>), et refuse de supprimer un spécimen qu'une visite désigne. La table référencée doit exister avant.</p>`,
        example: "CREATE TABLE visite (id INT PRIMARY KEY, specimen_id INT, FOREIGN KEY (specimen_id) REFERENCES specimen(id)); INSERT INTO visite (id, specimen_id) VALUES (1, 999);",
        fails: true,
        tryit: 'Remplace 999 par 1 : l\'insertion passe.'
      },
      alter: {
        title: 'Modifier une table : ALTER TABLE',
        html: `<p>Une table déjà remplie se modifie sans la recréer :</p>
<div class="rule"><span class="kw">ALTER TABLE</span> salle <span class="kw">ADD</span> climatisee <span class="kw">INT</span>;</div>
<p>La nouvelle colonne vaut <code>NULL</code> (ou sa valeur par défaut) sur les lignes existantes. <code>ALTER TABLE … ADD CONSTRAINT …</code> ajoute une contrainte, refusée si des lignes ne la respectent pas déjà.</p>`,
        example: 'ALTER TABLE salle ADD climatisee INT; SELECT * FROM salle;',
        tryit: 'Ajoute plutôt une colonne responsable de 60 caractères.'
      }
    },
    steps: {
      predire: { before: 'fk', kind: 'value', ask: 'visite.membre_id fait référence à membre.id, et la table membre ne contient que le membre 1. Combien de ces trois insertions réussissent ?', query: "INSERT INTO visite (id, membre_id) VALUES (1, 1);\nINSERT INTO visite (id, membre_id) VALUES (2, 1);\nINSERT INTO visite (id, membre_id) VALUES (3, 2);", value: 'SELECT 2', hints: ['Le membre 2 existe-t-il ?'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Crée la table visite : id (entier, clé primaire) et specimen_id (entier), qui fait référence à specimen(id).', template: 'CREATE TABLE visite (id INT PRIMARY KEY, specimen_id INT, [[]] [[]] (specimen_id) REFERENCES specimen(id));', fill: ['FOREIGN', 'KEY'], ref: 'CREATE TABLE visite (id INT PRIMARY KEY, specimen_id INT, FOREIGN KEY (specimen_id) REFERENCES specimen(id))', check: 'create', expect: [{ table: 'visite', columns: [col('id', 'int', { pk: true }), col('specimen_id', 'int', { fk: 'specimen.id' })] }], probes: [{ sql: 'INSERT INTO visite (id, specimen_id) VALUES (1, 999)', expect: 'refused', label: 'Une visite d\'un spécimen inconnu est refusée' }, { sql: 'INSERT INTO visite (id, specimen_id) VALUES (2, 1)', expect: 'ok', label: 'Une visite d\'un spécimen connu passe' }], hints: ['Deux mots : la clé étrangère.', 'CREATE TABLE visite (id INT PRIMARY KEY, specimen_id INT, _______ ___ (specimen_id) REFERENCES specimen(id));'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit créer la table restauration : id (entier, clé primaire), specimen_id (entier, qui fait référence à specimen(id)) et debut (une date). MySQL la refuse : répare-la.', starter: 'CREATE TABLE restauration (id INT PRIMARY KEY, specimen_id INT, debut DATE, FOREIGN KEY specimen_id REFERENCES specimen(id));', ref: 'CREATE TABLE restauration (id INT PRIMARY KEY, specimen_id INT, debut DATE, FOREIGN KEY (specimen_id) REFERENCES specimen(id))', check: 'create', expect: [{ table: 'restauration', columns: [col('id', 'int', { pk: true }), col('specimen_id', 'int', { fk: 'specimen.id' }), col('debut', 'date')] }], probes: [{ sql: "INSERT INTO restauration (id, specimen_id, debut) VALUES (1, 999, '2026-11-02')", expect: 'refused', label: 'Une restauration d\'un spécimen inconnu est refusée' }], hints: ['La colonne de la clé étrangère s\'écrit entre parenthèses.', '… FOREIGN KEY (specimen_id) REFERENCES specimen(id));'] },
      ecrire1: { before: 'alter', kind: 'write', ask: 'Ajoute à la table salle une colonne climatisee de type entier (0 ou 1).', ref: 'ALTER TABLE salle ADD climatisee INT', check: 'create', expect: [{ table: 'salle', columns: [col('code', 'text', { pk: true }), col('climatisee', 'int')] }], head: 0, hints: ['ALTER TABLE, puis ADD et la définition de la colonne.', 'ALTER TABLE salle ADD __________ INT;'] },
      ecrire2: { kind: 'write', ask: 'Crée la table commentaire : id (entier, clé primaire), specimen_id (entier, obligatoire, qui fait référence à specimen(id)) et texte (255 caractères, obligatoire).', ref: 'CREATE TABLE commentaire (id INT PRIMARY KEY, specimen_id INT NOT NULL, texte VARCHAR(255) NOT NULL, FOREIGN KEY (specimen_id) REFERENCES specimen(id))', check: 'create', expect: [{ table: 'commentaire', columns: [col('id', 'int', { pk: true }), col('specimen_id', 'int', { notNull: true, fk: 'specimen.id' }), col('texte', 'text', { notNull: true })] }], probes: [{ sql: "INSERT INTO commentaire (id, specimen_id, texte) VALUES (1, 999, 'Très beau')", expect: 'refused', label: 'Un commentaire d\'un spécimen inconnu est refusé' }, { sql: "INSERT INTO commentaire (id, texte) VALUES (2, 'Sans spécimen')", expect: 'refused', label: 'Un commentaire sans spécimen est refusé' }, { sql: "INSERT INTO commentaire (id, specimen_id, texte) VALUES (3, 1, 'Magnifique')", expect: 'ok', label: 'Un commentaire d\'un spécimen connu passe' }], head: 0, hints: ['NOT NULL sur specimen_id, et une FOREIGN KEY à la fin.', 'CREATE TABLE commentaire (id INT PRIMARY KEY, specimen_id INT NOT NULL, texte VARCHAR(255) NOT NULL, FOREIGN KEY (specimen_id) REFERENCES ________(__));'] },
      ecrire3: { kind: 'write', ask: 'Crée, dans cet ordre, la table atelier (id entier clé primaire, nom de 40 caractères) puis la table seance (id entier clé primaire, atelier_id entier qui fait référence à atelier(id), jour de type date).', ref: 'CREATE TABLE atelier (id INT PRIMARY KEY, nom VARCHAR(40)); CREATE TABLE seance (id INT PRIMARY KEY, atelier_id INT, jour DATE, FOREIGN KEY (atelier_id) REFERENCES atelier(id))', check: 'create', expect: [{ table: 'atelier', columns: [col('id', 'int', { pk: true }), col('nom', 'text')] }, { table: 'seance', columns: [col('id', 'int', { pk: true }), col('atelier_id', 'int', { fk: 'atelier.id' }), col('jour', 'date')] }], probes: [{ sql: "INSERT INTO seance (id, atelier_id, jour) VALUES (1, 5, '2026-11-02')", expect: 'refused', label: 'Une séance d\'un atelier inconnu est refusée' }, { sql: "INSERT INTO atelier (id, nom) VALUES (5, 'Moulage'); INSERT INTO seance (id, atelier_id, jour) VALUES (1, 5, '2026-11-02')", expect: 'ok', label: 'Une séance d\'un atelier connu passe' }], head: 0, hints: ['Deux CREATE TABLE séparés par un point-virgule ; atelier d\'abord, puisque seance y fait référence.', 'CREATE TABLE atelier (…); CREATE TABLE seance (…, FOREIGN KEY (atelier_id) REFERENCES atelier(id));'] }
    }
  });

  C.chrono({
    id: 'C10', chapter: 7, title: 'Structure', covers: ['N25', 'N26', 'N27'], seconds: 360, draw: 5,
    pool: [
      { ask: 'Crée la table auteur : id (entier, clé primaire) et nom (60 caractères, obligatoire).', ref: 'CREATE TABLE auteur (id INT PRIMARY KEY, nom VARCHAR(60) NOT NULL)', check: 'create', expect: [{ table: 'auteur', columns: [col('id', 'int', { pk: true }), col('nom', 'text', { notNull: true })] }] },
      { ask: 'Crée la table livre : isbn (13 caractères, clé primaire), titre (100 caractères, obligatoire) et prix (DECIMAL(6,2)).', ref: 'CREATE TABLE livre (isbn VARCHAR(13) PRIMARY KEY, titre VARCHAR(100) NOT NULL, prix DECIMAL(6,2))', check: 'create', expect: [{ table: 'livre', columns: [col('isbn', 'text', { pk: true }), col('titre', 'text', { notNull: true }), col('prix', 'decimal')] }] },
      { ask: 'Ajoute à la table institution une colonne site_web de 100 caractères.', ref: 'ALTER TABLE institution ADD site_web VARCHAR(100)', check: 'create', expect: [{ table: 'institution', columns: [col('site_web', 'text')] }] },
      { ask: 'Crée la table photo : id (entier, clé primaire auto-incrémentée), specimen_id (entier, qui fait référence à specimen(id)) et prise_le (une date).', ref: 'CREATE TABLE photo (id INT AUTO_INCREMENT PRIMARY KEY, specimen_id INT, prise_le DATE, FOREIGN KEY (specimen_id) REFERENCES specimen(id))', check: 'create', expect: [{ table: 'photo', columns: [col('id', 'int', { pk: true, auto: true }), col('specimen_id', 'int', { fk: 'specimen.id' }), col('prise_le', 'date')] }] },
      { ask: 'Crée la table stagiaire : id (entier, clé primaire) et email (100 caractères, unique).', ref: 'CREATE TABLE stagiaire (id INT PRIMARY KEY, email VARCHAR(100) UNIQUE)', check: 'create', expect: [{ table: 'stagiaire', columns: [col('id', 'int', { pk: true }), col('email', 'text', { unique: true })] }] },
      { ask: 'Crée la table vitrine : code (4 caractères, clé primaire) et salle (4 caractères, qui fait référence à salle(code)).', ref: 'CREATE TABLE vitrine (code VARCHAR(4) PRIMARY KEY, salle VARCHAR(4), FOREIGN KEY (salle) REFERENCES salle(code))', check: 'create', expect: [{ table: 'vitrine', columns: [col('code', 'text', { pk: true }), col('salle', 'text', { fk: 'salle.code' })] }] },
      { ask: 'Crée la table evenement : id (entier, clé primaire), nom (80 caractères, obligatoire) et jour (une date, obligatoire).', ref: 'CREATE TABLE evenement (id INT PRIMARY KEY, nom VARCHAR(80) NOT NULL, jour DATE NOT NULL)', check: 'create', expect: [{ table: 'evenement', columns: [col('id', 'int', { pk: true }), col('nom', 'text', { notNull: true }), col('jour', 'date', { notNull: true })] }] }
    ]
  });

  /* The final test: twelve queries drawn from every timed challenge's pool, two from each of the first
     five chapters and one from each of the last two, in 30 minutes (accommodations' extra time added). */
  var finalPool = [];
  C.all().forEach(function (lv) { if (lv.kind === 'chrono') lv.pool.forEach(function (q) { finalPool.push(Object.assign({ chapter: lv.chapter }, q)); }); });
  C.chrono({ id: 'FIN', chapter: 7, final: true, title: 'L\'épreuve finale', covers: [], seconds: 1800, draw: 12, perChapter: { 1: 2, 2: 2, 3: 2, 4: 2, 5: 2, 6: 1, 7: 1 }, pool: finalPool });
})(window.SPPContent);
