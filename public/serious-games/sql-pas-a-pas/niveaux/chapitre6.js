/* SQL pas à pas — chapter 6, « Modifier les données »: INSERT, UPDATE, DELETE. A modification is checked
   on the state the tables are left in; one that names rows by an id the hidden base does not have is
   checked on the visible base only (`hiddenless`). */
(function (C) {
  'use strict';

  C.level({
    id: 'N22', chapter: 6, num: 22, title: 'Ajouter', summary: 'INSERT INTO … VALUES', notions: ['insert'],
    lessons: {
      insert: {
        title: 'INSERT INTO',
        html: `<p>Pour ajouter une ligne, on nomme la table et les colonnes qu'on remplit, puis les valeurs, dans le même ordre :</p>
<div class="rule"><span class="kw">INSERT INTO</span> salle (code, libelle, etage, surface_m2)<br><span class="kw">VALUES</span> ('R6', 'Minéraux', 2, 120);</div>
<p>Une colonne non nommée reçoit <code>NULL</code> (ou sa valeur par défaut). Pour une valeur inconnue, on peut aussi écrire <code>NULL</code> en clair, sans apostrophes.</p>
<p>Dans le jeu, chaque exécution repart d'une base neuve : la console affiche la table modifiée, puis tout est oublié. Rien de ce que tu essaies ne casse la base.</p>`,
        example: "INSERT INTO salle (code, libelle, etage, surface_m2) VALUES ('R6', 'Minéraux', 2, 120);",
        tryit: 'Ajoute aussi une salle R7 de ton choix.'
      },
      plusieurs: {
        title: 'Plusieurs lignes, et l\'apostrophe',
        html: `<p>Une seule requête peut ajouter plusieurs lignes, séparées par des virgules :</p>
<div class="rule"><span class="kw">INSERT INTO</span> institution (id, nom, ville, pays) <span class="kw">VALUES</span><br>(8, 'Muséum de Lille', 'Lille', 'France'),<br>(9, 'Musée de Namur', 'Namur', 'Belgique');</div>
<p>Une apostrophe à l'intérieur d'un texte se <b>double</b> : <code>'Salle de l''Aigle'</code>.</p>`,
        example: "INSERT INTO institution (id, nom, ville, pays) VALUES (8, 'Muséum de Lille', 'Lille', 'France'), (9, 'Musée de Namur', 'Namur', 'Belgique');",
        tryit: "Ajoute une institution dont le nom contient une apostrophe."
      }
    },
    steps: {
      predire: { before: 'insert', kind: 'value', ask: 'La table salle compte 5 lignes. Combien en compte-t-elle après cette requête ?', query: "INSERT INTO salle (code, libelle, etage, surface_m2) VALUES ('R6', 'Minéraux', 2, 120);", value: 'SELECT COUNT(*) + 1 FROM salle', hints: ['Une requête INSERT avec une seule liste de valeurs ajoute une ligne.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Ajoute l\'institution 7 : le Muséum de Grenoble, à Grenoble, en France.', template: "[[]] [[]] institution (id, nom, ville, pays) VALUES (7, 'Muséum de Grenoble', 'Grenoble', 'France');", fill: ['INSERT', 'INTO'], ref: "INSERT INTO institution (id, nom, ville, pays) VALUES (7, 'Muséum de Grenoble', 'Grenoble', 'France')", check: 'modify', tables: ['institution'], hints: ['Les deux mots qui commencent un ajout.', 'INSERT ____ institution (…) VALUES (…);'] },
      reparer: { before: 'plusieurs', kind: 'repair', ask: 'Cette requête doit ajouter la salle R7, « Salle de l\'Aigle », au rez-de-chaussée (étage 0), de 85 m². MySQL la refuse : répare-la.', starter: "INSERT INTO salle (code, libelle, etage, surface_m2) VALUES ('R7', 'Salle de l'Aigle', 0, 85);", ref: "INSERT INTO salle (code, libelle, etage, surface_m2) VALUES ('R7', 'Salle de l''Aigle', 0, 85)", check: 'modify', tables: ['salle'], hints: ['L\'apostrophe de « l\'Aigle » ferme le texte trop tôt.', "… VALUES ('R7', 'Salle de l''Aigle', 0, 85);"] },
      ecrire1: { kind: 'write', ask: 'Ajoute le spécimen 31 : numéro MHN-2026-001, Hibou grand-duc, classe Oiseaux, origine Europe, entré en 2026, salle R1, 2.4 kg, valeur 1 500 euros, jamais revu.', ref: "INSERT INTO specimen (id, inventaire, nom, classe, origine, annee_entree, salle, poids_kg, valeur, derniere_revue) VALUES (31, 'MHN-2026-001', 'Hibou grand-duc', 'Oiseaux', 'Europe', 2026, 'R1', 2.4, 1500, NULL)", check: 'modify', tables: ['specimen'], head: 0, hints: ['Dix colonnes, dix valeurs, dans le même ordre ; « jamais revu », c\'est NULL.', "INSERT INTO specimen (id, inventaire, nom, classe, origine, annee_entree, salle, poids_kg, valeur, derniere_revue) VALUES (31, 'MHN-2026-001', …);"] },
      ecrire2: { kind: 'write', ask: 'Enregistre le prêt 10 : le spécimen 14 prêté à l\'institution 6, parti le 2026-11-02, pas encore revenu.', ref: "INSERT INTO pret (id, specimen_id, institution_id, depart, retour) VALUES (10, 14, 6, '2026-11-02', NULL)", check: 'modify', tables: ['pret'], hiddenless: true, head: 0, hints: ['Une date s\'écrit entre apostrophes ; « pas encore revenu », c\'est NULL.', "INSERT INTO pret (id, specimen_id, institution_id, depart, retour) VALUES (10, 14, 6, '__________', NULL);"] },
      ecrire3: { kind: 'write', ask: 'Ajoute en une seule requête les institutions 8 (Muséum de Lille, à Lille, en France) et 9 (Naturhistorisches Museum, à Vienne, en Autriche).', ref: "INSERT INTO institution (id, nom, ville, pays) VALUES (8, 'Muséum de Lille', 'Lille', 'France'), (9, 'Naturhistorisches Museum', 'Vienne', 'Autriche')", check: 'modify', tables: ['institution'], head: 0, hints: ['Deux listes de valeurs après VALUES, séparées par une virgule.', "INSERT INTO institution (id, nom, ville, pays) VALUES (8, …), (9, …);"] }
    }
  });

  C.level({
    id: 'N23', chapter: 6, num: 23, title: 'Modifier', summary: 'UPDATE … SET … WHERE', notions: ['update'],
    lessons: {
      update: {
        title: 'UPDATE',
        html: `<p>Pour modifier des lignes existantes :</p>
<div class="rule"><span class="kw">UPDATE</span> specimen <span class="kw">SET</span> salle = 'R2' <span class="kw">WHERE</span> salle <span class="kw">IS NULL</span>;</div>
<p><code>SET</code> dit quoi changer, <code>WHERE</code> dit quelles lignes. <b>Sans <code>WHERE</code>, toutes les lignes sont modifiées</b> : dans le jeu, un UPDATE sans WHERE est arrêté avant de s'exécuter. Prends l'habitude de vérifier d'abord ton <code>WHERE</code> avec un SELECT.</p>`,
        example: "UPDATE specimen SET salle = 'R2' WHERE salle IS NULL;",
        tryit: 'Range plutôt ces spécimens en R5.'
      },
      calcul: {
        title: 'Plusieurs colonnes, et des calculs',
        html: `<p>Plusieurs colonnes se modifient ensemble, <b>séparées par des virgules</b> : <code>SET salle = 'R4', poids_kg = 0.1</code>. Avec <code>AND</code>, MySQL calculerait une condition au lieu de deux affectations.</p>
<p>La nouvelle valeur peut se calculer à partir de l'ancienne :</p>
<div class="rule"><span class="kw">UPDATE</span> specimen <span class="kw">SET</span> valeur = valeur * 1.1 <span class="kw">WHERE</span> classe = 'Fossiles';</div>`,
        example: "UPDATE specimen SET valeur = valeur * 1.1 WHERE classe = 'Fossiles';",
        tryit: 'Augmente plutôt de 100 euros la valeur des insectes.'
      }
    },
    steps: {
      predire: { before: 'update', kind: 'value', ask: 'Combien de lignes cette requête modifie-t-elle ? Trois spécimens n\'ont pas de salle.', query: "UPDATE specimen SET salle = 'R2' WHERE salle IS NULL;", value: 'SELECT COUNT(*) FROM specimen WHERE salle IS NULL', hints: ['Le WHERE désigne les lignes modifiées.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Range le Kiwi (spécimen 29) en salle R1.', template: "UPDATE specimen [[]] salle = 'R1' WHERE id = 29;", fill: ['SET'], ref: "UPDATE specimen SET salle = 'R1' WHERE id = 29", check: 'modify', tables: ['specimen'], hiddenless: true, hints: ['Le mot qui introduit la nouvelle valeur.', "UPDATE specimen ___ salle = 'R1' WHERE id = 29;"] },
      reparer: { before: 'calcul', kind: 'repair', ask: 'Cette requête doit fixer la valeur à 450 euros et le poids à 0.1 kg pour le spécimen 25. Elle met la valeur à 0 : répare-la.', starter: 'UPDATE specimen SET valeur = 450 AND poids_kg = 0.1 WHERE id = 25;', ref: 'UPDATE specimen SET valeur = 450, poids_kg = 0.1 WHERE id = 25', check: 'modify', tables: ['specimen'], hiddenless: true, hints: ['Dans un SET, les affectations se séparent par des virgules.', 'UPDATE specimen SET valeur = 450, poids_kg = 0.1 WHERE id = 25;'] },
      ecrire1: { kind: 'write', ask: 'Augmente de 10 % la valeur de tous les fossiles (multiplie-la par 1.1).', ref: "UPDATE specimen SET valeur = valeur * 1.1 WHERE classe = 'Fossiles'", check: 'modify', tables: ['specimen'], head: 0, hints: ['La nouvelle valeur se calcule à partir de l\'ancienne.', "UPDATE specimen SET valeur = valeur * ___ WHERE classe = 'Fossiles';"] },
      ecrire2: { kind: 'write', ask: 'Les spécimens qui n\'avaient jamais été revus viennent de l\'être aujourd\'hui : mets la date du jour (CURDATE()) dans leur date de dernière revue.', ref: 'UPDATE specimen SET derniere_revue = CURDATE() WHERE derniere_revue IS NULL', check: 'modify', tables: ['specimen'], head: 0, hints: ['Désigne les spécimens sans date de revue.', 'UPDATE specimen SET derniere_revue = CURDATE() WHERE derniere_revue __ ____;'] },
      ecrire3: { kind: 'write', ask: 'Le prêt 5 vient de rentrer : sa date de retour est le 2026-10-30.', ref: "UPDATE pret SET retour = '2026-10-30' WHERE id = 5", check: 'modify', tables: ['pret'], hiddenless: true, head: 0, hints: ['Une date entre apostrophes, et le prêt désigné par son id.', "UPDATE pret SET retour = '__________' WHERE id = 5;"] }
    }
  });

  C.level({
    id: 'N24', chapter: 6, num: 24, title: 'Supprimer', summary: 'DELETE … WHERE · clés étrangères', notions: ['delete'],
    lessons: {
      delete: {
        title: 'DELETE',
        html: `<p>Pour supprimer des lignes :</p>
<div class="rule"><span class="kw">DELETE FROM</span> pret <span class="kw">WHERE</span> retour <span class="kw">IS NOT NULL</span>;</div>
<p>Comme pour UPDATE, <b>sans <code>WHERE</code>, toutes les lignes partent</b> (dans le jeu, c'est arrêté avant). Bon réflexe : écrire d'abord <code>SELECT * FROM pret WHERE …</code>, vérifier les lignes, puis remplacer <code>SELECT *</code> par <code>DELETE</code>.</p>`,
        example: 'DELETE FROM pret WHERE retour IS NOT NULL;',
        tryit: 'Supprime seulement les prêts partis avant 2021.'
      },
      fk: {
        title: 'Les clés étrangères protègent',
        html: `<p>On ne peut pas supprimer une ligne à laquelle d'autres font référence : supprimer le spécimen 10, prêté deux fois, est refusé (<code>ERROR 1451 … a foreign key constraint fails</code>). Il faudrait d'abord supprimer ses prêts.</p>
<p>C'est voulu : la base empêche des prêts de désigner un spécimen qui n'existe plus.</p>`,
        example: 'DELETE FROM specimen WHERE id = 10;',
        fails: true,
        tryit: 'Supprime d\'abord les prêts du spécimen 10, puis le spécimen, en deux requêtes.'
      }
    },
    steps: {
      predire: { before: 'delete', kind: 'value', ask: 'Combien de lignes cette requête supprime-t-elle ? La table pret compte 9 prêts, dont 3 en cours (sans date de retour).', query: 'DELETE FROM pret WHERE retour IS NOT NULL;', value: 'SELECT COUNT(*) FROM pret WHERE retour IS NOT NULL', hints: ['Les prêts terminés : 9 moins les 3 en cours.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Supprime les prêts rentrés avant 2021 (date de retour avant le 2021-01-01).', template: "[[]] [[]] pret WHERE retour < '2021-01-01';", fill: ['DELETE', 'FROM'], ref: "DELETE FROM pret WHERE retour < '2021-01-01'", check: 'modify', tables: ['pret'], hints: ['Les deux mots qui commencent une suppression.', "DELETE ____ pret WHERE retour < '2021-01-01';"] },
      reparer: { before: 'fk', kind: 'repair', ask: 'Cette requête doit supprimer seulement les prêts de l\'institution 3. Le jeu l\'arrête : répare-la.', starter: 'DELETE FROM pret;', ref: 'DELETE FROM pret WHERE institution_id = 3', check: 'modify', tables: ['pret'], hiddenless: true, hints: ['Sans WHERE, tous les prêts partiraient.', 'DELETE FROM pret WHERE ______________ = 3;'] },
      ecrire1: { kind: 'write', ask: 'Supprime les prêts terminés (ceux qui ont une date de retour).', ref: 'DELETE FROM pret WHERE retour IS NOT NULL', check: 'modify', tables: ['pret'], head: 0, hints: ['Un prêt terminé a une date de retour.', 'DELETE FROM pret WHERE retour __ ___ ____;'] },
      ecrire2: { kind: 'write', ask: 'Supprime les insectes de la collection.', ref: "DELETE FROM specimen WHERE classe = 'Insectes'", check: 'modify', tables: ['specimen'], head: 0, hints: ['Un DELETE avec un WHERE sur la classe.', "DELETE FROM specimen WHERE classe = '________';"] },
      ecrire3: { kind: 'write', ask: 'Supprime les institutions qui n\'ont jamais reçu de prêt.', ref: 'DELETE FROM institution WHERE id NOT IN (SELECT institution_id FROM pret)', check: 'modify', tables: ['institution'], head: 0, hints: ['Une sous-requête donne les institutions qui ont reçu des prêts.', 'DELETE FROM institution WHERE id NOT IN (SELECT ______________ FROM pret);'] }
    }
  });

  C.chrono({
    id: 'C09', chapter: 6, title: 'Modifier', covers: ['N22', 'N23', 'N24'], seconds: 270, draw: 5,
    pool: [
      { ask: 'Range en R3 les poissons qui n\'ont pas de salle.', ref: "UPDATE specimen SET salle = 'R3' WHERE classe = 'Poissons' AND salle IS NULL", check: 'modify', tables: ['specimen'], allowEmpty: true },
      { ask: 'Augmente de 100 euros la valeur des insectes.', ref: "UPDATE specimen SET valeur = valeur + 100 WHERE classe = 'Insectes'", check: 'modify', tables: ['specimen'] },
      { ask: 'Supprime les prêts partis avant 2020.', ref: "DELETE FROM pret WHERE depart < '2020-01-01'", check: 'modify', tables: ['pret'] },
      { ask: 'Ajoute la salle R8, « Atelier », au rez-de-chaussée (étage 0), de 40 m².', ref: "INSERT INTO salle (code, libelle, etage, surface_m2) VALUES ('R8', 'Atelier', 0, 40)", check: 'modify', tables: ['salle'] },
      { ask: 'Agrandis de 20 m² toutes les salles du premier étage.', ref: 'UPDATE salle SET surface_m2 = surface_m2 + 20 WHERE etage = 1', check: 'modify', tables: ['salle'] },
      { ask: 'Supprime les spécimens qui valent moins de 100 euros.', ref: 'DELETE FROM specimen WHERE valeur < 100', check: 'modify', tables: ['specimen'] },
      { ask: 'Mets la date du jour (CURDATE()) comme date de dernière revue de tous les oiseaux.', ref: "UPDATE specimen SET derniere_revue = CURDATE() WHERE classe = 'Oiseaux'", check: 'modify', tables: ['specimen'] },
      { ask: 'Le prêt 3 est rentré le 2022-07-01 : corrige sa date de retour.', ref: "UPDATE pret SET retour = '2022-07-01' WHERE id = 3", check: 'modify', tables: ['pret'], hiddenless: true }
    ]
  });
})(window.SPPContent);
