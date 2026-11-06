/* SQL pas à pas — chapter 3, « Valeurs manquantes et calculs »: NULL, DISTINCT, calculs, AS, fonctions. */
(function (C) {
  'use strict';

  C.level({
    id: 'N09', chapter: 3, num: 9, title: 'Les cases vides', summary: 'NULL · IS NULL · IS NOT NULL · IFNULL', notions: ['null'],
    lessons: {
      null: {
        title: 'NULL : une valeur inconnue',
        html: `<p>Une case <code>NULL</code> ne contient pas « rien » ni 0 : elle dit que l'information est <b>inconnue</b>. Comparer une valeur inconnue ne donne ni vrai ni faux : <code>salle = NULL</code> n'est jamais vrai, même pour les spécimens sans salle.</p>
<p>Pour tester l'absence de valeur, on écrit <code>IS NULL</code>, et pour sa présence <code>IS NOT NULL</code> :</p>
<div class="rule"><span class="kw">SELECT</span> nom <span class="kw">FROM</span> specimen <span class="kw">WHERE</span> salle <span class="kw">IS NULL</span>;</div>`,
        example: 'SELECT nom, salle FROM specimen WHERE salle IS NULL;',
        tryit: 'Remplace IS NULL par = NULL : combien de lignes ?'
      },
      ifnull: {
        title: 'Remplacer NULL à l\'affichage',
        html: `<p><code>IFNULL(colonne, remplacement)</code> affiche la valeur de la colonne, ou le remplacement quand elle est <code>NULL</code> :</p>
<div class="rule"><span class="kw">SELECT</span> nom, IFNULL(salle, 'réserve') <span class="kw">FROM</span> specimen;</div>
<p>La table ne change pas : seul l'affichage du résultat change. <code>COALESCE(a, b)</code> fait la même chose.</p>`,
        example: "SELECT nom, IFNULL(salle, 'réserve') FROM specimen;",
        tryit: 'Affiche le poids, et 0 quand il est inconnu.'
      }
    },
    steps: {
      predire: { before: 'null', kind: 'value', ask: 'Sans l\'exécuter : combien de lignes renvoie cette requête ? (Trois spécimens n\'ont pas de salle.)', query: 'SELECT nom FROM specimen WHERE salle = NULL;', value: 'SELECT 0', hints: ['Relis le cours : « = NULL » est-il vrai pour une case NULL ?'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom des spécimens dont on ne connaît pas le poids.', template: 'SELECT nom FROM specimen WHERE poids_kg [[]] [[]];', fill: ['IS', 'NULL'], ref: 'SELECT nom FROM specimen WHERE poids_kg IS NULL', hints: ['Deux mots, qui testent l\'absence de valeur.', 'SELECT nom FROM specimen WHERE poids_kg IS ____;'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom et la date de dernière revue des spécimens déjà revus. Elle ne renvoie rien : répare-la.', starter: 'SELECT nom, derniere_revue FROM specimen WHERE derniere_revue <> NULL;', ref: 'SELECT nom, derniere_revue FROM specimen WHERE derniere_revue IS NOT NULL', hints: ['On ne compare pas à NULL avec <>.', 'SELECT nom, derniere_revue FROM specimen WHERE derniere_revue IS ___ NULL;'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et la classe des spécimens qui n\'ont pas de salle.', ref: 'SELECT nom, classe FROM specimen WHERE salle IS NULL', head: 2, hints: ['Une salle inconnue.', 'SELECT nom, classe FROM specimen WHERE salle __ ____;'] },
      ecrire2: { before: 'ifnull', kind: 'write', ask: 'Affiche le nom et le poids de chaque spécimen, en affichant 0 quand le poids est inconnu.', ref: 'SELECT nom, IFNULL(poids_kg, 0) FROM specimen', head: 3, hints: ['Une fonction qui remplace NULL.', 'SELECT nom, IFNULL(poids_kg, _) FROM specimen;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom des oiseaux qui n\'ont pas de salle ou pas de date de revue.', ref: "SELECT nom FROM specimen WHERE classe = 'Oiseaux' AND (salle IS NULL OR derniere_revue IS NULL)", head: 2, hints: ['Un oiseau, ET (pas de salle OU pas de revue) : attention aux parenthèses.', "… WHERE classe = 'Oiseaux' AND (salle IS NULL OR ____ IS NULL);"] }
    }
  });

  C.level({
    id: 'N10', chapter: 3, num: 10, title: 'Sans doublons', summary: 'DISTINCT · combinaisons', notions: ['distinct'],
    lessons: {
      distinct: {
        title: 'DISTINCT',
        html: `<p><code>SELECT classe FROM specimen</code> affiche une ligne par spécimen : « Oiseaux » revient neuf fois. <code>DISTINCT</code>, placé juste après <code>SELECT</code>, supprime les lignes en double du <b>résultat</b> :</p>
<div class="rule"><span class="kw">SELECT DISTINCT</span> classe <span class="kw">FROM</span> specimen;</div>
<p>Avec plusieurs colonnes, <code>DISTINCT</code> porte sur la <b>ligne entière</b> : <code>SELECT DISTINCT classe, salle</code> garde chaque <i>combinaison</i> une fois. « Oiseaux / R1 » et « Oiseaux / NULL » sont deux combinaisons différentes.</p>`,
        example: 'SELECT DISTINCT classe FROM specimen;',
        tryit: 'Ajoute la colonne salle après classe : pourquoi « Oiseaux » revient-il ?'
      }
    },
    steps: {
      predire: { before: 'distinct', kind: 'value', ask: 'Combien de lignes renvoie cette requête ? Les fiches ci-dessous montrent la classe de chaque spécimen.', query: 'SELECT DISTINCT classe FROM specimen;', value: 'SELECT COUNT(DISTINCT classe) FROM specimen', show: ['classe'], hints: ['Compte les classes différentes, chacune une fois.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche la liste des origines, chacune une seule fois.', template: 'SELECT [[]] origine FROM specimen;', fill: ['DISTINCT'], ref: 'SELECT DISTINCT origine FROM specimen', hints: ['Le mot qui supprime les doublons, juste après SELECT.', 'SELECT ________ origine FROM specimen;'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher les salles utilisées, chacune une seule fois, par ordre alphabétique. Elle répète les salles : répare-la.', starter: 'SELECT salle FROM specimen ORDER BY salle;', ref: 'SELECT DISTINCT salle FROM specimen ORDER BY salle', hints: ['Il manque un mot juste après SELECT.', 'SELECT ________ salle FROM specimen ORDER BY salle;'] },
      ecrire1: { kind: 'write', ask: 'Affiche les classes représentées dans la collection, chacune une fois, par ordre alphabétique.', ref: 'SELECT DISTINCT classe FROM specimen ORDER BY classe', head: 3, hints: ['DISTINCT, puis un tri.', 'SELECT DISTINCT classe FROM specimen ORDER BY ______;'] },
      ecrire2: { kind: 'write', ask: 'Affiche les combinaisons classe / origine présentes dans la collection, chacune une fois.', ref: 'SELECT DISTINCT classe, origine FROM specimen', head: 3, hints: ['DISTINCT porte sur toute la ligne.', 'SELECT DISTINCT classe, ______ FROM specimen;'] },
      ecrire3: { kind: 'write', ask: 'Affiche les salles où sont rangés des mammifères, chacune une fois.', ref: "SELECT DISTINCT salle FROM specimen WHERE classe = 'Mammifères'", head: 2, hints: ['DISTINCT et un WHERE.', "SELECT DISTINCT salle FROM specimen WHERE classe = '__________';"] }
    }
  });

  C.level({
    id: 'N11', chapter: 3, num: 11, title: 'Renommer et calculer', summary: 'AS · + − * / · CONCAT', notions: ['calcul'],
    lessons: {
      calcul: {
        title: 'Calculer dans le SELECT, nommer avec AS',
        html: `<p>Une colonne du résultat peut être un <b>calcul</b> : <code>valeur * 2</code>, <code>poids_kg * 1000</code>, <code>valeur / poids_kg</code>. Dans MySQL, <code>/</code> donne toujours un résultat à virgule.</p>
<p><code>AS</code> donne un nom à la colonne calculée :</p>
<div class="rule"><span class="kw">SELECT</span> nom, valeur / 1000 <span class="kw">AS</span> valeur_k <span class="kw">FROM</span> specimen;</div>
<p>Ce nom, l'<b>alias</b>, n'existe que dans le résultat. On peut s'en servir dans <code>ORDER BY</code>, mais pas dans <code>WHERE</code> : MySQL évalue <code>WHERE</code> avant le <code>SELECT</code>. Dans un <code>WHERE</code>, on répète le calcul.</p>`,
        example: 'SELECT nom, valeur / 1000 AS valeur_k FROM specimen;',
        tryit: 'Ajoute une colonne poids_g qui donne le poids en grammes.'
      },
      concat: {
        title: 'Assembler des textes : CONCAT',
        html: `<p>En MySQL, on assemble des textes avec la fonction <code>CONCAT</code>, qui prend autant de morceaux qu'on veut :</p>
<div class="rule"><span class="kw">SELECT</span> CONCAT(nom, ' (', classe, ')') <span class="kw">AS</span> libelle <span class="kw">FROM</span> specimen;</div>
<p>Le signe <code>+</code> additionne des nombres : <code>'Kiwi' + ' (Oiseaux)'</code> ne donne pas du texte, mais 0.</p>`,
        example: "SELECT CONCAT(nom, ' (', classe, ')') AS libelle FROM specimen;",
        tryit: 'Fais afficher « Kiwi, entré en 2012 ».'
      }
    },
    steps: {
      predire: { before: 'calcul', kind: 'value', ask: 'Que vaut la colonne calculée sur la ligne du Loup gris, dont la valeur est de 4 500 euros ?', query: 'SELECT nom, valeur * 2 AS valeur_double FROM specimen;', value: 'SELECT valeur * 2 FROM specimen WHERE id = 2', hints: ['La valeur du Loup gris, multipliée par 2.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom et la valeur en milliers d\'euros, sous le nom valeur_k.', template: 'SELECT nom, valeur / 1000 [[]] valeur_k FROM specimen;', fill: ['AS'], ref: 'SELECT nom, valeur / 1000 AS valeur_k FROM specimen', hints: ['Le mot qui donne un nom à une colonne.', 'SELECT nom, valeur / 1000 __ valeur_k FROM specimen;'] },
      reparer: { before: 'concat', kind: 'repair', ask: 'Cette requête doit afficher « Nom (classe) » pour chaque spécimen, par exemple « Kiwi (Oiseaux) ». Elle affiche des 0 : répare-la.', starter: "SELECT nom + ' (' + classe + ')' AS libelle FROM specimen;", ref: "SELECT CONCAT(nom, ' (', classe, ')') AS libelle FROM specimen", hints: ['En MySQL, + additionne des nombres. Une fonction assemble des textes.', "SELECT CONCAT(nom, ' (', ______, ')') AS libelle FROM specimen;"] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et le poids en grammes (le poids en kg multiplié par 1 000), sous le nom grammes.', ref: 'SELECT nom, poids_kg * 1000 AS grammes FROM specimen', head: 3, hints: ['Un calcul dans le SELECT, puis AS.', 'SELECT nom, poids_kg * ____ AS grammes FROM specimen;'] },
      ecrire2: { kind: 'write', ask: 'Pour les spécimens dont le poids est connu, affiche le nom et la valeur d\'un kilo (la valeur divisée par le poids), sous le nom prix_kilo.', ref: 'SELECT nom, valeur / poids_kg AS prix_kilo FROM specimen WHERE poids_kg IS NOT NULL', head: 3, hints: ['Une division, et un WHERE qui écarte les poids inconnus.', 'SELECT nom, valeur / poids_kg AS prix_kilo FROM specimen WHERE poids_kg IS ___ ____;'] },
      ecrire3: { kind: 'write', ask: 'Affiche une seule colonne, fiche, du type « MHN-1872-004 : Grand pingouin ».', ref: "SELECT CONCAT(inventaire, ' : ', nom) AS fiche FROM specimen", head: 2, hints: ['CONCAT, avec le séparateur « : » entouré d\'espaces au milieu.', "SELECT CONCAT(inventaire, ' : ', ___) AS fiche FROM specimen;"] }
    }
  });

  C.level({
    id: 'N12', chapter: 3, num: 12, title: 'Fonctions utiles', summary: 'UPPER · ROUND · CHAR_LENGTH · YEAR · DATEDIFF', notions: ['fonctions'],
    lessons: {
      texte: {
        title: 'Fonctions de texte et de nombre',
        html: `<p>MySQL offre des fonctions qui transforment une valeur :</p>
<ul><li><code>UPPER(nom)</code>, <code>LOWER(nom)</code> : en majuscules, en minuscules ;</li><li><code>CHAR_LENGTH(nom)</code> : le nombre de caractères ;</li><li><code>ROUND(x)</code>, <code>ROUND(x, 1)</code> : arrondi à l'unité, au dixième.</li></ul>
<div class="rule"><span class="kw">SELECT</span> UPPER(nom), CHAR_LENGTH(nom), ROUND(poids_kg) <span class="kw">FROM</span> specimen;</div>`,
        example: 'SELECT UPPER(nom), CHAR_LENGTH(nom), ROUND(poids_kg) FROM specimen;',
        tryit: 'Arrondis plutôt le poids au dixième.'
      },
      dates: {
        title: 'Les dates',
        html: `<p>Une date s'écrit <code>'AAAA-MM-JJ'</code>, entre apostrophes : <code>'2025-06-02'</code>. Quelques fonctions :</p>
<ul><li><code>YEAR(d)</code>, <code>MONTH(d)</code> : l'année, le mois ;</li><li><code>CURDATE()</code> : la date d'aujourd'hui (dans le jeu, le 2 novembre 2026) ;</li><li><code>DATEDIFF(a, b)</code> : le nombre de jours de <code>b</code> à <code>a</code>.</li></ul>
<div class="rule"><span class="kw">SELECT</span> nom, DATEDIFF(CURDATE(), derniere_revue) <span class="kw">AS</span> jours <span class="kw">FROM</span> specimen;</div>`,
        example: 'SELECT nom, derniere_revue, DATEDIFF(CURDATE(), derniere_revue) AS jours FROM specimen;',
        tryit: 'Affiche l\'année de la dernière revue avec YEAR.'
      }
    },
    steps: {
      predire: { before: 'texte', kind: 'value', ask: 'Que renvoie cette requête ?', query: 'SELECT ROUND(2.567, 1);', value: 'SELECT ROUND(2.567, 1)', hints: ['Arrondi au dixième : un seul chiffre après la virgule.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom en majuscules de chaque spécimen.', template: 'SELECT [[]](nom) AS nom_maj FROM specimen;', fill: ['UPPER'], ref: 'SELECT UPPER(nom) AS nom_maj FROM specimen', hints: ['La fonction des majuscules.', 'SELECT _____(nom) AS nom_maj FROM specimen;'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom et le poids arrondi à l\'unité. Elle garde une décimale : répare-la.', starter: 'SELECT nom, ROUND(poids_kg, 1) FROM specimen;', ref: 'SELECT nom, ROUND(poids_kg) FROM specimen', hints: ['Le second argument de ROUND est le nombre de décimales.', 'SELECT nom, ROUND(poids_kg) FROM specimen;'] },
      ecrire1: { before: 'dates', kind: 'write', ask: 'Affiche le nom des spécimens revus en 2025.', ref: 'SELECT nom FROM specimen WHERE YEAR(derniere_revue) = 2025', head: 3, hints: ['L\'année d\'une date, comparée à 2025.', 'SELECT nom FROM specimen WHERE ____(derniere_revue) = 2025;'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom et le nombre de caractères du nom (sous le nom lettres), des noms les plus longs aux plus courts.', ref: 'SELECT nom, CHAR_LENGTH(nom) AS lettres FROM specimen ORDER BY lettres DESC', head: 3, hints: ['CHAR_LENGTH, un alias, puis un tri décroissant sur cet alias.', 'SELECT nom, CHAR_LENGTH(nom) AS lettres FROM specimen ORDER BY _______ DESC;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom et le nombre de jours écoulés depuis la dernière revue, pour les spécimens revus il y a plus de 500 jours.', ref: 'SELECT nom, DATEDIFF(CURDATE(), derniere_revue) FROM specimen WHERE DATEDIFF(CURDATE(), derniere_revue) > 500', head: 3, hints: ['Le même calcul dans le SELECT et dans le WHERE (l\'alias n\'y existe pas encore).', 'SELECT nom, DATEDIFF(CURDATE(), derniere_revue) FROM specimen WHERE DATEDIFF(CURDATE(), derniere_revue) > ___;'] }
    }
  });

  C.chrono({
    id: 'C04', chapter: 3, title: 'Valeurs et calculs', covers: ['N09', 'N10', 'N11', 'N12'], seconds: 240, draw: 5,
    pool: [
      { ask: 'Le nom des spécimens qui n\'ont jamais été revus (pas de date de revue).', ref: 'SELECT nom FROM specimen WHERE derniere_revue IS NULL' },
      { ask: 'La liste des origines, chacune une fois.', ref: 'SELECT DISTINCT origine FROM specimen' },
      { ask: 'Le nom et la valeur en milliers d\'euros.', ref: 'SELECT nom, valeur / 1000 FROM specimen' },
      { ask: 'Le nom en minuscules des fossiles.', ref: "SELECT LOWER(nom) FROM specimen WHERE classe = 'Fossiles'" },
      { ask: 'Le nom et l\'année de dernière revue (fonction YEAR) des spécimens déjà revus.', ref: 'SELECT nom, YEAR(derniere_revue) FROM specimen WHERE derniere_revue IS NOT NULL' },
      { ask: 'Le nom des spécimens dont la salle est connue et qui pèsent plus de 50 kg.', ref: 'SELECT nom FROM specimen WHERE salle IS NOT NULL AND poids_kg > 50' },
      { ask: 'Le nom et le poids arrondi au dixième des poissons.', ref: "SELECT nom, ROUND(poids_kg, 1) FROM specimen WHERE classe = 'Poissons'" },
      { ask: 'Les salles où sont rangés des oiseaux, chacune une fois.', ref: "SELECT DISTINCT salle FROM specimen WHERE classe = 'Oiseaux'" },
      { ask: 'Le nom et le nombre de caractères du nom des insectes.', ref: "SELECT nom, CHAR_LENGTH(nom) FROM specimen WHERE classe = 'Insectes'" },
      { ask: 'Le nom et la valeur augmentée de 10 % (multipliée par 1.1).', ref: 'SELECT nom, valeur * 1.1 FROM specimen' }
    ]
  });
})(window.SPPContent);
