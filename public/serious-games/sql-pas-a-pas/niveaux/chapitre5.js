/* SQL pas à pas — chapter 5, « Relier les tables »: keys, JOIN, aliases, LEFT JOIN, subqueries. */
(function (C) {
  'use strict';

  C.level({
    id: 'N17', chapter: 5, num: 17, title: 'Lire un schéma', summary: 'clé primaire · clé étrangère · un pour plusieurs', notions: ['cles'],
    lessons: {
      cles: {
        title: 'Trois nouvelles tables, et des clés',
        html: `<p>La carte de la base montre maintenant quatre tables : <code>specimen</code>, <code>salle</code>, <code>institution</code> (les musées qui empruntent) et <code>pret</code>.</p>
<ul><li>La <b>clé primaire</b> désigne chaque ligne sans ambiguïté : <code>specimen.id</code>, <code>salle.code</code>. Deux lignes ne peuvent pas avoir la même.</li>
<li>Une <b>clé étrangère</b> fait référence à la clé primaire d'une autre table : <code>pret.specimen_id</code> contient l'id d'un spécimen, <code>pret.institution_id</code> l'id d'une institution, <code>specimen.salle</code> le code d'une salle.</li></ul>
<p>Plutôt que de recopier le nom du spécimen dans chaque prêt, la base garde son id : le nom n'est écrit qu'une fois.</p>`,
        example: 'SELECT * FROM pret;',
        tryit: 'Affiche aussi les tables salle et institution.'
      },
      plusieurs: {
        title: 'Un pour plusieurs',
        html: `<p>Un spécimen peut être prêté plusieurs fois : plusieurs lignes de <code>pret</code> ont alors le même <code>specimen_id</code>. Une salle contient plusieurs spécimens : plusieurs lignes de <code>specimen</code> ont la même <code>salle</code>.</p>
<p>C'est la relation « un pour plusieurs » : la clé étrangère est toujours du côté « plusieurs ».</p>`,
        example: 'SELECT specimen_id, COUNT(*) FROM pret GROUP BY specimen_id;',
        tryit: 'Quels spécimens ont été prêtés plus d\'une fois ?'
      }
    },
    steps: {
      predire: { before: 'cles', kind: 'value', ask: 'Combien de prêts concernent le spécimen 10 (le Coelacanthe) ?', query: 'SELECT * FROM pret WHERE specimen_id = 10;', value: 'SELECT COUNT(*) FROM pret WHERE specimen_id = 10', hints: ['Exécute SELECT * FROM pret; dans le cours et compte les lignes où specimen_id vaut 10.'] },
      comprendre: {},
      cle: {},
      completer: { before: 'plusieurs', kind: 'complete', ask: 'Affiche la date de départ et l\'id de l\'institution des prêts du spécimen 10.', template: 'SELECT depart, institution_id FROM pret WHERE [[]] = 10;', fill: ['specimen_id'], ref: 'SELECT depart, institution_id FROM pret WHERE specimen_id = 10', allowEmpty: true, hints: ['La colonne de pret qui désigne le spécimen.', 'SELECT depart, institution_id FROM pret WHERE ___________ = 10;'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le libellé de la salle R3. MySQL la refuse : répare-la.', starter: "SELECT libelle FROM specimen WHERE salle = 'R3';", ref: "SELECT libelle FROM salle WHERE code = 'R3'", hints: ['Dans quelle table se trouve le libellé ? Ouvre la carte de la base.', "SELECT libelle FROM _____ WHERE code = 'R3';"] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et la ville des institutions françaises.', ref: "SELECT nom, ville FROM institution WHERE pays = 'France'", head: 2, hints: ['Une seule table : institution.', "SELECT nom, ville FROM institution WHERE pays = '______';"] },
      ecrire2: { kind: 'write', ask: 'Affiche l\'id, l\'id du spécimen et la date de départ des prêts en cours (sans date de retour).', ref: 'SELECT id, specimen_id, depart FROM pret WHERE retour IS NULL', head: 2, hints: ['Un prêt en cours n\'a pas encore de date de retour.', 'SELECT id, specimen_id, depart FROM pret WHERE retour __ ____;'] },
      ecrire3: { kind: 'write', ask: 'Affiche l\'id des spécimens prêtés au moins une fois, chacun une seule fois.', ref: 'SELECT DISTINCT specimen_id FROM pret', head: 3, hints: ['Dans pret, sans doublon.', 'SELECT ________ specimen_id FROM pret;'] }
    }
  });

  C.level({
    id: 'N18', chapter: 5, num: 18, title: 'Joindre deux tables', summary: 'JOIN … ON · table.colonne', notions: ['join'],
    lessons: {
      join: {
        title: 'JOIN … ON',
        html: `<p>Une <b>jointure</b> associe chaque ligne d'une table à la ligne correspondante d'une autre. On dit avec <code>ON</code> comment elles se correspondent, le plus souvent clé étrangère = clé primaire :</p>
<div class="rule"><span class="kw">SELECT</span> nom, libelle<br><span class="kw">FROM</span> specimen <span class="kw">JOIN</span> salle <span class="kw">ON</span> specimen.salle = salle.code;</div>
<p>Chaque spécimen est affiché avec le libellé de sa salle. Les spécimens sans salle (NULL) ne correspondent à aucune salle : une jointure ne les garde pas.</p>`,
        example: 'SELECT nom, libelle FROM specimen JOIN salle ON specimen.salle = salle.code;',
        tryit: 'Ajoute l\'étage de la salle.'
      },
      prefixe: {
        title: 'table.colonne',
        html: `<p>Quand deux tables ont une colonne du même nom, il faut dire laquelle on veut, en écrivant le nom de la table devant : <code>specimen.nom</code>, <code>institution.nom</code>. Sans cela, MySQL répond <code>Column 'nom' in field list is ambiguous</code>.</p>
<p>Oublier le <code>ON</code>, ou séparer les tables par une virgule sans condition, associe chaque ligne de l'une à <b>chaque</b> ligne de l'autre : c'est le produit cartésien, 30 × 5 = 150 lignes pour specimen et salle.</p>`,
        example: 'SELECT pret.id, specimen.nom, pret.depart FROM pret JOIN specimen ON pret.specimen_id = specimen.id;',
        tryit: 'Enlève la partie ON … : combien de lignes ?'
      }
    },
    steps: {
      predire: { before: 'join', kind: 'value', ask: 'Combien de lignes renvoie cette jointure ? 30 spécimens, dont 3 sans salle.', query: 'SELECT nom, libelle FROM specimen JOIN salle ON specimen.salle = salle.code;', value: 'SELECT COUNT(*) FROM specimen JOIN salle ON specimen.salle = salle.code', hints: ['Chaque spécimen qui a une salle donne une ligne.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom de chaque spécimen et le libellé de sa salle.', template: 'SELECT nom, libelle FROM specimen JOIN salle [[]] specimen.salle = salle.code;', fill: ['ON'], ref: 'SELECT nom, libelle FROM specimen JOIN salle ON specimen.salle = salle.code', hints: ['Le mot qui introduit la condition de jointure.', 'SELECT nom, libelle FROM specimen JOIN salle __ specimen.salle = salle.code;'] },
      reparer: { before: 'prefixe', kind: 'repair', ask: 'Cette requête doit afficher le nom de chaque spécimen et le libellé de sa salle. Elle renvoie 150 lignes : répare-la.', starter: 'SELECT nom, libelle FROM specimen, salle;', ref: 'SELECT nom, libelle FROM specimen JOIN salle ON specimen.salle = salle.code', hints: ['Il manque la condition qui relie un spécimen à sa salle.', 'SELECT nom, libelle FROM specimen JOIN salle ON specimen.salle = _____.____;'] },
      ecrire1: { kind: 'write', ask: 'Pour chaque prêt, affiche son id et le nom du spécimen prêté.', ref: 'SELECT pret.id, specimen.nom FROM pret JOIN specimen ON pret.specimen_id = specimen.id', head: 2, hints: ['pret JOIN specimen, la clé étrangère égale à la clé primaire.', 'SELECT pret.id, specimen.nom FROM pret JOIN specimen ON pret.specimen_id = ________.__;'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom de chaque oiseau et l\'étage de sa salle.', ref: "SELECT nom, etage FROM specimen JOIN salle ON specimen.salle = salle.code WHERE classe = 'Oiseaux'", head: 2, hints: ['La jointure, puis un WHERE.', "… JOIN salle ON specimen.salle = salle.code WHERE classe = '_______';"] },
      ecrire3: { kind: 'write', ask: 'Pour chaque prêt, affiche le nom de l\'institution et la date de départ, du départ le plus récent au plus ancien.', ref: 'SELECT institution.nom, pret.depart FROM pret JOIN institution ON pret.institution_id = institution.id ORDER BY pret.depart DESC', head: 2, hints: ['pret JOIN institution, puis ORDER BY sur la date.', 'SELECT institution.nom, pret.depart FROM pret JOIN institution ON pret.institution_id = institution.id ORDER BY ___________ DESC;'] }
    }
  });

  C.level({
    id: 'N19', chapter: 5, num: 19, title: 'Joindre trois tables', summary: 'alias de tables · colonnes ambiguës', notions: ['alias'],
    lessons: {
      trois: {
        title: 'Trois tables',
        html: `<p>Un prêt relie un spécimen à une institution. Pour afficher les deux noms, on enchaîne deux jointures :</p>
<div class="rule"><span class="kw">SELECT</span> specimen.nom, institution.nom<br><span class="kw">FROM</span> pret<br><span class="kw">JOIN</span> specimen <span class="kw">ON</span> pret.specimen_id = specimen.id<br><span class="kw">JOIN</span> institution <span class="kw">ON</span> pret.institution_id = institution.id;</div>`,
        example: 'SELECT specimen.nom, institution.nom FROM pret JOIN specimen ON pret.specimen_id = specimen.id JOIN institution ON pret.institution_id = institution.id;',
        tryit: 'Ajoute la ville de l\'institution.'
      },
      alias: {
        title: 'Les alias de tables',
        html: `<p>On donne à chaque table un nom court, son <b>alias</b>, juste après son nom :</p>
<div class="rule"><span class="kw">SELECT</span> s.nom, i.nom<br><span class="kw">FROM</span> pret p<br><span class="kw">JOIN</span> specimen s <span class="kw">ON</span> p.specimen_id = s.id<br><span class="kw">JOIN</span> institution i <span class="kw">ON</span> p.institution_id = i.id;</div>
<p>Une fois l'alias donné, on l'utilise partout dans la requête.</p>`,
        example: 'SELECT s.nom, i.nom, i.ville FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id;',
        tryit: 'Garde seulement les prêts en cours.'
      }
    },
    steps: {
      predire: { before: 'trois', kind: 'value', ask: 'Combien de lignes renvoie la jointure des prêts avec leurs spécimens et leurs institutions ? Chaque prêt a un spécimen et une institution.', query: 'SELECT specimen.nom, institution.nom FROM pret JOIN specimen ON pret.specimen_id = specimen.id JOIN institution ON pret.institution_id = institution.id;', value: 'SELECT COUNT(*) FROM pret', hints: ['Une ligne par prêt.'] },
      comprendre: {},
      completer: { before: 'alias', kind: 'complete', ask: 'Affiche, pour chaque prêt, le nom du spécimen et le nom de l\'institution.', template: 'SELECT s.nom, [[]] FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id;', fill: ['i.nom'], ref: 'SELECT s.nom, i.nom FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id', hints: ['Le nom de l\'institution, avec l\'alias de sa table.', 'SELECT s.nom, _.nom FROM pret p …'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher, pour chaque prêt, le nom du spécimen et la ville de l\'institution. Elle associe les mauvaises lignes : répare-la.', starter: 'SELECT s.nom, i.ville FROM pret p JOIN specimen s ON p.institution_id = s.id JOIN institution i ON p.specimen_id = i.id;', ref: 'SELECT s.nom, i.ville FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id', hints: ['Relis chaque ON : quelle colonne de pret désigne le spécimen, laquelle l\'institution ?', 'SELECT s.nom, i.ville FROM pret p JOIN specimen s ON p.___________ = s.id JOIN institution i ON p.______________ = i.id;'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom des spécimens prêtés à une institution française.', ref: "SELECT s.nom FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id WHERE i.pays = 'France'", head: 2, hints: ['Trois tables, puis un WHERE sur le pays.', "… WHERE i.pays = '______';"] },
      ecrire2: { kind: 'write', ask: 'Pour chaque prêt en cours, affiche le nom du spécimen, le nom de l\'institution et la date de départ.', ref: 'SELECT s.nom, i.nom, p.depart FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id WHERE p.retour IS NULL', head: 2, hints: ['Un prêt en cours n\'a pas de date de retour.', '… WHERE p.retour __ ____;'] },
      ecrire3: { kind: 'write', ask: 'Pour chaque institution qui a reçu au moins un prêt, affiche son nom et son nombre de prêts.', ref: 'SELECT i.nom, COUNT(*) FROM pret p JOIN institution i ON p.institution_id = i.id GROUP BY i.id, i.nom', head: 2, hints: ['Une jointure, puis GROUP BY sur l\'institution.', 'SELECT i.nom, COUNT(*) FROM pret p JOIN institution i ON p.institution_id = i.id GROUP BY i.id, i.___;'] }
    }
  });

  C.chrono({
    id: 'C07', chapter: 5, title: 'Jointures', covers: ['N17', 'N18', 'N19'], seconds: 270, draw: 5,
    pool: [
      { ask: 'Le nom de chaque spécimen et l\'étage de sa salle.', ref: 'SELECT s.nom, sa.etage FROM specimen s JOIN salle sa ON s.salle = sa.code' },
      { ask: 'Pour chaque prêt, le nom du spécimen et la date de départ.', ref: 'SELECT s.nom, p.depart FROM pret p JOIN specimen s ON p.specimen_id = s.id' },
      { ask: 'Le libellé des salles qui contiennent des fossiles, chacun une fois.', ref: "SELECT DISTINCT sa.libelle FROM specimen s JOIN salle sa ON s.salle = sa.code WHERE s.classe = 'Fossiles'" },
      { ask: 'Le nom des spécimens prêtés à une institution étrangère (hors de France).', ref: "SELECT s.nom FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id WHERE i.pays <> 'France'" },
      { ask: 'Le nom de l\'institution et le nom du spécimen des prêts terminés (avec une date de retour).', ref: 'SELECT i.nom, s.nom FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id WHERE p.retour IS NOT NULL' },
      { ask: 'Le nombre de spécimens par libellé de salle.', ref: 'SELECT sa.libelle, COUNT(*) FROM specimen s JOIN salle sa ON s.salle = sa.code GROUP BY sa.libelle' },
      { ask: 'Le nom des spécimens rangés au premier étage.', ref: 'SELECT s.nom FROM specimen s JOIN salle sa ON s.salle = sa.code WHERE sa.etage = 1' },
      { ask: 'Pour chaque prêt, le pays de l\'institution et la classe du spécimen.', ref: 'SELECT i.pays, s.classe FROM pret p JOIN specimen s ON p.specimen_id = s.id JOIN institution i ON p.institution_id = i.id' }
    ]
  });

  C.level({
    id: 'N20', chapter: 5, num: 20, title: 'Ce qui n\'a pas de correspondance', summary: 'LEFT JOIN · … IS NULL', notions: ['leftjoin'],
    lessons: {
      left: {
        title: 'LEFT JOIN',
        html: `<p>Une jointure ordinaire ne garde que les lignes qui ont une correspondance. <code>LEFT JOIN</code> garde <b>toutes les lignes de la table de gauche</b> (celle écrite avant) ; quand il n'y a pas de correspondance, les colonnes de la table de droite valent <code>NULL</code> :</p>
<div class="rule"><span class="kw">SELECT</span> s.nom, sa.libelle<br><span class="kw">FROM</span> specimen s <span class="kw">LEFT JOIN</span> salle sa <span class="kw">ON</span> s.salle = sa.code;</div>
<p>Les spécimens sans salle apparaissent, avec un libellé NULL.</p>`,
        example: 'SELECT s.nom, sa.libelle FROM specimen s LEFT JOIN salle sa ON s.salle = sa.code;',
        tryit: 'Remplace LEFT JOIN par JOIN : qui disparaît ?'
      },
      anti: {
        title: 'Trouver ce qui n\'a jamais rien',
        html: `<p>Pour trouver les spécimens jamais prêtés, on garde tous les spécimens avec <code>LEFT JOIN pret</code>, puis seulement ceux pour qui la partie <code>pret</code> est vide :</p>
<div class="rule"><span class="kw">SELECT</span> s.nom <span class="kw">FROM</span> specimen s<br><span class="kw">LEFT JOIN</span> pret p <span class="kw">ON</span> p.specimen_id = s.id<br><span class="kw">WHERE</span> p.id <span class="kw">IS NULL</span>;</div>
<p>Attention en comptant : avec un <code>LEFT JOIN</code>, <code>COUNT(*)</code> compte 1 pour une ligne sans correspondance ; <code>COUNT(p.id)</code> compte 0.</p>`,
        example: 'SELECT s.nom FROM specimen s LEFT JOIN pret p ON p.specimen_id = s.id WHERE p.id IS NULL;',
        tryit: 'Trouve plutôt les institutions qui n\'ont jamais reçu de prêt.'
      }
    },
    steps: {
      predire: { before: 'left', kind: 'value', ask: 'Combien de lignes renvoie cette requête ? La table specimen a 30 lignes, dont 3 sans salle.', query: 'SELECT s.nom, sa.libelle FROM specimen s LEFT JOIN salle sa ON s.salle = sa.code;', value: 'SELECT COUNT(*) FROM specimen s LEFT JOIN salle sa ON s.salle = sa.code', hints: ['LEFT JOIN garde tous les spécimens, une fois chacun ici.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom de chaque spécimen et le libellé de sa salle, y compris les spécimens sans salle.', template: 'SELECT s.nom, sa.libelle FROM specimen s [[]] JOIN salle sa ON s.salle = sa.code;', fill: ['LEFT'], ref: 'SELECT s.nom, sa.libelle FROM specimen s LEFT JOIN salle sa ON s.salle = sa.code', hints: ['La jointure qui garde toutes les lignes de gauche.', 'SELECT s.nom, sa.libelle FROM specimen s ____ JOIN salle sa ON s.salle = sa.code;'] },
      reparer: { before: 'anti', kind: 'repair', ask: 'Cette requête doit afficher le libellé de chaque salle et le nombre de spécimens qu\'elle contient, salles vides comprises (avec 0). Elle oublie la salle vide : répare-la.', starter: 'SELECT sa.libelle, COUNT(*) FROM salle sa JOIN specimen s ON s.salle = sa.code GROUP BY sa.libelle;', ref: 'SELECT sa.libelle, COUNT(s.id) FROM salle sa LEFT JOIN specimen s ON s.salle = sa.code GROUP BY sa.libelle', hints: ['Il faut garder toutes les salles… et compter les spécimens, pas les lignes.', 'SELECT sa.libelle, COUNT(s.id) FROM salle sa LEFT JOIN specimen s ON s.salle = sa.code GROUP BY sa.libelle;'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom des spécimens qui n\'ont jamais été prêtés.', ref: 'SELECT s.nom FROM specimen s LEFT JOIN pret p ON p.specimen_id = s.id WHERE p.id IS NULL', head: 3, hints: ['LEFT JOIN pret, puis seulement ceux sans prêt.', 'SELECT s.nom FROM specimen s LEFT JOIN pret p ON p.specimen_id = s.id WHERE p.id __ ____;'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom des institutions qui n\'ont jamais reçu de prêt.', ref: 'SELECT i.nom FROM institution i LEFT JOIN pret p ON p.institution_id = i.id WHERE p.id IS NULL', head: 1, hints: ['Même schéma, en partant de la table institution.', 'SELECT i.nom FROM institution i LEFT JOIN pret p ON p.institution_id = i.id WHERE ____ IS NULL;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le libellé des salles qui ne contiennent aucun spécimen.', ref: 'SELECT sa.libelle FROM salle sa LEFT JOIN specimen s ON s.salle = sa.code WHERE s.id IS NULL', head: 1, hints: ['On part de salle.', 'SELECT sa.libelle FROM salle sa LEFT JOIN specimen s ON s.salle = sa.code WHERE ____ IS NULL;'] }
    }
  });

  C.level({
    id: 'N21', chapter: 5, num: 21, title: 'Une requête dans la requête', summary: 'sous-requête · IN · agrégat', notions: ['sousreq'],
    lessons: {
      in: {
        title: 'Une sous-requête avec IN',
        html: `<p>Une <b>sous-requête</b> est une requête entre parenthèses à l'intérieur d'une autre. Avec <code>IN</code>, elle fournit une liste de valeurs :</p>
<div class="rule"><span class="kw">SELECT</span> nom <span class="kw">FROM</span> specimen<br><span class="kw">WHERE</span> id <span class="kw">IN</span> (<span class="kw">SELECT</span> specimen_id <span class="kw">FROM</span> pret);</div>
<p>La sous-requête doit renvoyer une seule colonne. <code>NOT IN (…)</code> garde le reste ; attention, si la sous-requête renvoie un <code>NULL</code>, <code>NOT IN</code> ne garde plus rien.</p>`,
        example: 'SELECT nom FROM specimen WHERE id IN (SELECT specimen_id FROM pret);',
        tryit: 'Garde seulement les spécimens dont le prêt est en cours.'
      },
      agregat: {
        title: 'Comparer à un calcul',
        html: `<p>Une sous-requête qui renvoie une seule valeur se compare comme un nombre :</p>
<div class="rule"><span class="kw">SELECT</span> nom, valeur <span class="kw">FROM</span> specimen<br><span class="kw">WHERE</span> valeur &gt; (<span class="kw">SELECT</span> AVG(valeur) <span class="kw">FROM</span> specimen);</div>
<p>On ne peut pas écrire <code>WHERE valeur &gt; AVG(valeur)</code> : un agrégat n'a pas sa place dans un <code>WHERE</code>. La sous-requête calcule d'abord la moyenne, puis le <code>WHERE</code> compare chaque ligne à ce nombre.</p>`,
        example: 'SELECT nom, valeur FROM specimen WHERE valeur > (SELECT AVG(valeur) FROM specimen);',
        tryit: 'Trouve le spécimen le plus lourd avec MAX dans une sous-requête.'
      }
    },
    steps: {
      predire: { before: 'agregat', kind: 'value', ask: 'Combien de lignes renvoie cette requête ? Les fiches montrent la valeur de chaque spécimen ; la moyenne est de 3 759 euros.', query: 'SELECT nom FROM specimen WHERE valeur > (SELECT AVG(valeur) FROM specimen);', value: 'SELECT COUNT(*) FROM specimen WHERE valeur > (SELECT AVG(valeur) FROM specimen)', show: ['valeur'], hints: ['Compte les fiches dont la valeur dépasse 3 759.'] },
      comprendre: {},
      completer: { before: 'in', kind: 'complete', ask: 'Affiche le nom des spécimens actuellement prêtés (prêt sans date de retour).', template: 'SELECT nom FROM specimen WHERE id [[]] (SELECT specimen_id FROM pret WHERE retour IS NULL);', fill: ['IN'], ref: 'SELECT nom FROM specimen WHERE id IN (SELECT specimen_id FROM pret WHERE retour IS NULL)', hints: ['Le mot qui précède une liste… ici fournie par une sous-requête.', 'SELECT nom FROM specimen WHERE id __ (SELECT …);'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom et la valeur du spécimen le plus cher. MySQL la refuse : répare-la.', starter: 'SELECT nom, valeur FROM specimen WHERE valeur = MAX(valeur);', ref: 'SELECT nom, valeur FROM specimen WHERE valeur = (SELECT MAX(valeur) FROM specimen)', hints: ['Un agrégat dans un WHERE : il faut le calculer dans une sous-requête.', 'SELECT nom, valeur FROM specimen WHERE valeur = (SELECT ___(valeur) FROM specimen);'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et le poids des spécimens plus lourds que la moyenne.', ref: 'SELECT nom, poids_kg FROM specimen WHERE poids_kg > (SELECT AVG(poids_kg) FROM specimen)', head: 2, hints: ['Comparer à une moyenne calculée par une sous-requête.', 'SELECT nom, poids_kg FROM specimen WHERE poids_kg > (SELECT ___(poids_kg) FROM specimen);'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom des spécimens prêtés à une institution française, avec deux sous-requêtes imbriquées.', ref: "SELECT nom FROM specimen WHERE id IN (SELECT specimen_id FROM pret WHERE institution_id IN (SELECT id FROM institution WHERE pays = 'France'))", head: 2, hints: ['Les institutions françaises → leurs prêts → les spécimens.', "SELECT nom FROM specimen WHERE id IN (SELECT specimen_id FROM pret WHERE institution_id IN (SELECT id FROM institution WHERE pays = '______'));"] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom et l\'année d\'entrée du spécimen entré le plus anciennement.', ref: 'SELECT nom, annee_entree FROM specimen WHERE annee_entree = (SELECT MIN(annee_entree) FROM specimen)', head: 1, hints: ['La plus petite année, calculée par une sous-requête.', 'SELECT nom, annee_entree FROM specimen WHERE annee_entree = (SELECT ___(annee_entree) FROM specimen);'] }
    }
  });

  C.chrono({
    id: 'C08', chapter: 5, title: 'Jointures externes et sous-requêtes', covers: ['N20', 'N21'], seconds: 270, draw: 5,
    pool: [
      { ask: 'Le nom des spécimens jamais prêtés.', ref: 'SELECT s.nom FROM specimen s LEFT JOIN pret p ON p.specimen_id = s.id WHERE p.id IS NULL' },
      { ask: 'Le nom des spécimens qui valent plus que la valeur moyenne.', ref: 'SELECT nom FROM specimen WHERE valeur > (SELECT AVG(valeur) FROM specimen)' },
      { ask: 'Le libellé de chaque salle et son nombre de spécimens, salles vides comprises (0).', ref: 'SELECT sa.libelle, COUNT(s.id) FROM salle sa LEFT JOIN specimen s ON s.salle = sa.code GROUP BY sa.libelle' },
      { ask: 'Le nom des institutions qui n\'ont reçu aucun prêt.', ref: 'SELECT i.nom FROM institution i LEFT JOIN pret p ON p.institution_id = i.id WHERE p.id IS NULL' },
      { ask: 'Le nom du spécimen le plus lourd.', ref: 'SELECT nom FROM specimen WHERE poids_kg = (SELECT MAX(poids_kg) FROM specimen)' },
      { ask: 'Le nom, la classe et, s\'il en a une, le libellé de la salle de chaque spécimen.', ref: 'SELECT s.nom, s.classe, sa.libelle FROM specimen s LEFT JOIN salle sa ON s.salle = sa.code' },
      { ask: 'Le nom des spécimens entrés après le plus récent des mammifères.', ref: "SELECT nom FROM specimen WHERE annee_entree > (SELECT MAX(annee_entree) FROM specimen WHERE classe = 'Mammifères')" },
      { ask: 'Le nom des spécimens actuellement prêtés (prêt sans date de retour).', ref: 'SELECT nom FROM specimen WHERE id IN (SELECT specimen_id FROM pret WHERE retour IS NULL)' }
    ]
  });
})(window.SPPContent);
