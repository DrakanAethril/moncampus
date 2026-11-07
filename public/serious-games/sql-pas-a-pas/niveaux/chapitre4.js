/* SQL pas à pas — chapter 4, « Compter et regrouper »: COUNT, SUM, AVG, MIN, MAX, GROUP BY, HAVING. */
(function (C) {
  'use strict';

  C.level({
    id: 'N13', chapter: 4, num: 13, title: 'Compter', summary: 'COUNT(*) · COUNT(colonne) · COUNT(DISTINCT)', notions: ['count'],
    lessons: {
      count: {
        title: 'COUNT',
        html: `<p>Une <b>fonction d'agrégat</b> résume toutes les lignes en une seule valeur. <code>COUNT(*)</code> compte les lignes :</p>
<div class="rule"><span class="kw">SELECT COUNT</span>(*) <span class="kw">FROM</span> specimen <span class="kw">WHERE</span> classe = 'Oiseaux';</div>
<p>Le <code>WHERE</code> agit d'abord, <code>COUNT</code> compte ensuite ce qui reste.</p>`,
        example: "SELECT COUNT(*) FROM specimen WHERE classe = 'Oiseaux';",
        tryit: 'Compte plutôt les spécimens venus d\'Afrique.'
      },
      colonne: {
        title: 'COUNT(colonne) et COUNT(DISTINCT)',
        html: `<p><code>COUNT(colonne)</code> ne compte que les lignes où la colonne <b>n'est pas NULL</b> : <code>COUNT(poids_kg)</code> donne 27, alors que la table a 30 lignes.</p>
<p><code>COUNT(DISTINCT colonne)</code> compte les valeurs différentes : <code>COUNT(DISTINCT classe)</code> donne le nombre de classes.</p>`,
        example: 'SELECT COUNT(*), COUNT(poids_kg), COUNT(DISTINCT classe) FROM specimen;',
        tryit: 'Compte les salles différentes utilisées.'
      }
    },
    steps: {
      predire: { before: 'count', kind: 'value', ask: 'Que renvoie cette requête ? Les fiches montrent la classe de chaque spécimen.', query: "SELECT COUNT(*) FROM specimen WHERE classe = 'Oiseaux';", value: "SELECT COUNT(*) FROM specimen WHERE classe = 'Oiseaux'", show: ['classe'], hints: ['Compte les fiches d\'oiseaux.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nombre de spécimens entrés après 1950.', template: 'SELECT [[]](*) FROM specimen WHERE annee_entree > 1950;', fill: ['COUNT'], ref: 'SELECT COUNT(*) FROM specimen WHERE annee_entree > 1950', hints: ['La fonction qui compte.', 'SELECT _____(*) FROM specimen WHERE annee_entree > 1950;'] },
      reparer: { before: 'colonne', kind: 'repair', ask: 'Cette requête doit donner le nombre total de spécimens, qu\'ils aient une salle ou non. Elle en oublie : répare-la.', starter: 'SELECT COUNT(salle) FROM specimen;', ref: 'SELECT COUNT(*) FROM specimen', hints: ['COUNT(salle) ignore les spécimens sans salle.', 'SELECT COUNT(_) FROM specimen;'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nombre de reptiles.', ref: "SELECT COUNT(*) FROM specimen WHERE classe = 'Reptiles'", head: 1, hints: ['COUNT et un WHERE.', "SELECT COUNT(*) FROM specimen WHERE classe = '________';"] },
      ecrire2: { kind: 'write', ask: 'Affiche le nombre de spécimens dont on connaît la date de dernière revue.', ref: 'SELECT COUNT(derniere_revue) FROM specimen', head: 1, hints: ['COUNT(colonne) ne compte pas les NULL.', 'SELECT COUNT(______________) FROM specimen;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le nombre d\'origines différentes.', ref: 'SELECT COUNT(DISTINCT origine) FROM specimen', head: 1, hints: ['COUNT et DISTINCT ensemble.', 'SELECT COUNT(DISTINCT _______) FROM specimen;'] }
    }
  });

  C.level({
    id: 'N14', chapter: 4, num: 14, title: 'Additionner, moyenner', summary: 'SUM · AVG · MIN · MAX', notions: ['agregats'],
    lessons: {
      sum: {
        title: 'SUM, AVG, MIN, MAX',
        html: `<p>Les autres fonctions d'agrégat :</p>
<ul><li><code>SUM(valeur)</code> : la somme ;</li><li><code>AVG(valeur)</code> : la moyenne ;</li><li><code>MIN(annee_entree)</code>, <code>MAX(annee_entree)</code> : la plus petite, la plus grande.</li></ul>
<p>Toutes ignorent les <code>NULL</code>. On peut en mettre plusieurs dans le même <code>SELECT</code>, et arrondir : <code>ROUND(AVG(valeur))</code>.</p>
<div class="rule"><span class="kw">SELECT</span> SUM(valeur), ROUND(AVG(valeur)), MIN(annee_entree), MAX(annee_entree) <span class="kw">FROM</span> specimen;</div>
<p>Un agrégat ramène toutes les lignes à une seule : on ne peut pas afficher <code>nom</code> à côté de <code>MAX(valeur)</code>, MySQL ne saurait pas quel nom choisir.</p>`,
        example: 'SELECT SUM(valeur), ROUND(AVG(valeur)), MIN(annee_entree), MAX(annee_entree) FROM specimen;',
        tryit: 'Fais la même chose pour les seuls oiseaux.'
      }
    },
    steps: {
      predire: { before: 'sum', kind: 'value', ask: 'Que renvoie cette requête ? Les fiches montrent l\'année d\'entrée de chaque spécimen.', query: 'SELECT MAX(annee_entree) FROM specimen;', value: 'SELECT MAX(annee_entree) FROM specimen', show: ['annee_entree'], hints: ['L\'année la plus récente.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche la valeur totale de la collection.', template: 'SELECT [[]](valeur) FROM specimen;', fill: ['SUM'], ref: 'SELECT SUM(valeur) FROM specimen', hints: ['La fonction de la somme.', 'SELECT ___(valeur) FROM specimen;'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit donner la valeur moyenne des oiseaux, arrondie à l\'euro. Elle donne NULL : répare-la.', starter: "SELECT ROUND(AVG(valeur)) FROM specimen WHERE classe = 'Oiseau';", ref: "SELECT ROUND(AVG(valeur)) FROM specimen WHERE classe = 'Oiseaux'", hints: ['Une moyenne de rien vaut NULL : le WHERE trouve-t-il des lignes ? Regarde comment la classe est écrite dans la table.', "… WHERE classe = '_______';"] },
      ecrire1: { kind: 'write', ask: 'Affiche le poids moyen des spécimens, arrondi au centième.', ref: 'SELECT ROUND(AVG(poids_kg), 2) FROM specimen', head: 1, hints: ['AVG ignore déjà les poids inconnus ; ROUND(…, 2) arrondit au centième.', 'SELECT ROUND(AVG(poids_kg), _) FROM specimen;'] },
      ecrire2: { kind: 'write', ask: 'Affiche, en deux colonnes, l\'année d\'entrée la plus ancienne et la plus récente.', ref: 'SELECT MIN(annee_entree), MAX(annee_entree) FROM specimen', head: 1, hints: ['Deux agrégats dans le même SELECT.', 'SELECT MIN(annee_entree), ___(annee_entree) FROM specimen;'] },
      ecrire3: { kind: 'write', ask: 'Affiche la valeur totale des spécimens de la salle R3.', ref: "SELECT SUM(valeur) FROM specimen WHERE salle = 'R3'", head: 1, hints: ['SUM et un WHERE.', "SELECT SUM(valeur) FROM specimen WHERE salle = '__';"] }
    }
  });

  C.chrono({
    id: 'C05', chapter: 4, title: 'Compter et calculer', covers: ['N13', 'N14'], seconds: 240, draw: 5,
    pool: [
      { ask: 'Le nombre de fossiles.', ref: "SELECT COUNT(*) FROM specimen WHERE classe = 'Fossiles'" },
      { ask: 'La valeur totale des oiseaux.', ref: "SELECT SUM(valeur) FROM specimen WHERE classe = 'Oiseaux'" },
      { ask: 'Le poids le plus lourd de la collection.', ref: 'SELECT MAX(poids_kg) FROM specimen' },
      { ask: 'Le nombre de spécimens sans salle.', ref: 'SELECT COUNT(*) FROM specimen WHERE salle IS NULL' },
      { ask: 'La valeur moyenne des mammifères, arrondie à l\'euro.', ref: "SELECT ROUND(AVG(valeur)) FROM specimen WHERE classe = 'Mammifères'" },
      { ask: 'Le nombre de classes différentes.', ref: 'SELECT COUNT(DISTINCT classe) FROM specimen' },
      { ask: 'L\'année d\'entrée la plus récente parmi les reptiles.', ref: "SELECT MAX(annee_entree) FROM specimen WHERE classe = 'Reptiles'" },
      { ask: 'Le nombre de spécimens valant plus de 1 000 euros.', ref: 'SELECT COUNT(*) FROM specimen WHERE valeur > 1000' },
      { ask: 'La valeur la plus faible de la collection.', ref: 'SELECT MIN(valeur) FROM specimen' }
    ]
  });

  C.level({
    id: 'N15', chapter: 4, num: 15, title: 'Par groupe', summary: 'GROUP BY', notions: ['groupby'],
    lessons: {
      groupby: {
        title: 'GROUP BY',
        html: `<p><code>GROUP BY colonne</code> range les lignes en <b>groupes</b> qui ont la même valeur dans cette colonne, et calcule les agrégats <b>pour chaque groupe</b> :</p>
<div class="rule"><span class="kw">SELECT</span> classe, COUNT(*) <span class="kw">FROM</span> specimen <span class="kw">GROUP BY</span> classe;</div>
<p>On obtient une ligne par classe, avec son nombre de spécimens.</p>`,
        example: 'SELECT classe, COUNT(*) FROM specimen GROUP BY classe;',
        tryit: 'Groupe plutôt par origine.'
      },
      regle: {
        title: 'La règle du GROUP BY',
        html: `<p>Une ligne du résultat représente tout un groupe. On ne peut donc y afficher que :</p>
<ul><li>les colonnes du <code>GROUP BY</code> (une seule valeur par groupe) ;</li><li>des agrégats (<code>COUNT</code>, <code>SUM</code>, <code>AVG</code>…).</li></ul>
<p><code>SELECT classe, nom, COUNT(*) … GROUP BY classe</code> est refusé par MySQL : il y a plusieurs noms par classe. On peut trier sur un agrégat : <code>ORDER BY COUNT(*) DESC</code>.</p>`,
        example: 'SELECT classe, ROUND(AVG(valeur)) AS moyenne FROM specimen GROUP BY classe ORDER BY moyenne DESC;',
        tryit: 'Ajoute la colonne nom dans le SELECT et lis l\'erreur.'
      }
    },
    steps: {
      predire: { before: 'groupby', kind: 'value', ask: 'Combien de lignes renvoie cette requête ? Les fiches montrent l\'origine de chaque spécimen.', query: 'SELECT origine, COUNT(*) FROM specimen GROUP BY origine;', value: 'SELECT COUNT(DISTINCT origine) FROM specimen', show: ['origine'], hints: ['Une ligne par origine différente.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nombre de spécimens de chaque classe.', template: 'SELECT classe, COUNT(*) FROM specimen [[]] [[]] classe;', fill: ['GROUP', 'BY'], ref: 'SELECT classe, COUNT(*) FROM specimen GROUP BY classe', hints: ['Deux mots qui forment les groupes.', 'SELECT classe, COUNT(*) FROM specimen GROUP __ classe;'] },
      reparer: { before: 'regle', kind: 'repair', ask: 'Cette requête doit donner, pour chaque salle, la valeur totale de ses spécimens. MySQL la refuse : répare-la.', starter: 'SELECT salle, nom, SUM(valeur) FROM specimen GROUP BY salle;', ref: 'SELECT salle, SUM(valeur) FROM specimen GROUP BY salle', hints: ['Une colonne affichée n\'est ni groupée, ni un agrégat.', 'SELECT salle, SUM(valeur) FROM specimen GROUP BY salle;'] },
      ecrire1: { kind: 'write', ask: 'Pour chaque origine, affiche le nombre de spécimens.', ref: 'SELECT origine, COUNT(*) FROM specimen GROUP BY origine', head: 2, hints: ['La colonne du groupe, puis COUNT(*).', 'SELECT origine, COUNT(*) FROM specimen GROUP BY _______;'] },
      ecrire2: { kind: 'write', ask: 'Pour chaque classe, affiche la valeur moyenne arrondie à l\'euro, de la plus élevée à la plus faible.', ref: 'SELECT classe, ROUND(AVG(valeur)) AS moyenne FROM specimen GROUP BY classe ORDER BY moyenne DESC', head: 2, hints: ['GROUP BY, puis ORDER BY sur la moyenne.', 'SELECT classe, ROUND(AVG(valeur)) AS moyenne FROM specimen GROUP BY classe ORDER BY _______ DESC;'] },
      ecrire3: { kind: 'write', ask: 'Pour chaque salle (sans les spécimens qui n\'en ont pas), affiche le nombre de spécimens et leur valeur totale.', ref: 'SELECT salle, COUNT(*), SUM(valeur) FROM specimen WHERE salle IS NOT NULL GROUP BY salle', head: 2, hints: ['Le WHERE écarte d\'abord les spécimens sans salle, puis on groupe.', 'SELECT salle, COUNT(*), SUM(valeur) FROM specimen WHERE salle IS NOT NULL GROUP BY _____;'] }
    }
  });

  C.level({
    id: 'N16', chapter: 4, num: 16, title: 'Filtrer les groupes', summary: 'HAVING · WHERE ou HAVING ?', notions: ['having'],
    lessons: {
      having: {
        title: 'HAVING',
        html: `<p><code>WHERE</code> filtre les <b>lignes</b>, avant qu'elles soient regroupées. Pour filtrer les <b>groupes</b> d'après leurs agrégats, on écrit <code>HAVING</code>, après le <code>GROUP BY</code> :</p>
<div class="rule"><span class="kw">SELECT</span> classe, COUNT(*) <span class="kw">FROM</span> specimen <span class="kw">GROUP BY</span> classe <span class="kw">HAVING</span> COUNT(*) &gt;= 4;</div>
<p>L'ordre complet d'une requête : <code>SELECT … FROM … WHERE … GROUP BY … HAVING … ORDER BY … LIMIT</code>. Un agrégat dans un <code>WHERE</code> est refusé : <code>Invalid use of group function</code>.</p>`,
        example: 'SELECT classe, COUNT(*) FROM specimen GROUP BY classe HAVING COUNT(*) >= 4;',
        tryit: 'Ajoute un WHERE annee_entree > 1900 avant le GROUP BY : qu\'est-ce qui change ?'
      }
    },
    steps: {
      predire: { before: 'having', kind: 'value', ask: 'Combien de classes garde cette requête ? Les fiches montrent la classe de chaque spécimen.', query: 'SELECT classe, COUNT(*) FROM specimen GROUP BY classe HAVING COUNT(*) >= 4;', value: 'SELECT COUNT(*) FROM (SELECT classe FROM specimen GROUP BY classe HAVING COUNT(*) >= 4) AS t', show: ['classe'], hints: ['Compte les spécimens de chaque classe, et garde les classes qui en ont au moins 4.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche les classes qui comptent plus de 3 spécimens, avec leur nombre.', template: 'SELECT classe, COUNT(*) FROM specimen GROUP BY classe [[]] COUNT(*) > 3;', fill: ['HAVING'], ref: 'SELECT classe, COUNT(*) FROM specimen GROUP BY classe HAVING COUNT(*) > 3', hints: ['Le mot qui filtre les groupes.', 'SELECT classe, COUNT(*) FROM specimen GROUP BY classe ______ COUNT(*) > 3;'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit donner les origines dont la valeur totale dépasse 20 000 euros. MySQL la refuse : répare-la.', starter: 'SELECT origine, SUM(valeur) FROM specimen WHERE SUM(valeur) > 20000 GROUP BY origine;', ref: 'SELECT origine, SUM(valeur) FROM specimen GROUP BY origine HAVING SUM(valeur) > 20000', hints: ['Un agrégat ne se teste pas dans un WHERE.', 'SELECT origine, SUM(valeur) FROM specimen GROUP BY origine ______ SUM(valeur) > 20000;'] },
      ecrire1: { kind: 'write', ask: 'Affiche les salles qui contiennent au moins 5 spécimens, avec leur nombre.', ref: 'SELECT salle, COUNT(*) FROM specimen GROUP BY salle HAVING COUNT(*) >= 5', head: 2, hints: ['GROUP BY salle, puis HAVING sur le nombre.', 'SELECT salle, COUNT(*) FROM specimen GROUP BY salle HAVING COUNT(*) >= _;'] },
      ecrire2: { kind: 'write', ask: 'Parmi les spécimens entrés après 1900, affiche les classes qui en comptent au moins 3, avec leur nombre.', ref: 'SELECT classe, COUNT(*) FROM specimen WHERE annee_entree > 1900 GROUP BY classe HAVING COUNT(*) >= 3', head: 2, hints: ['WHERE pour les lignes (après 1900), HAVING pour les groupes (au moins 3).', 'SELECT classe, COUNT(*) FROM specimen WHERE annee_entree > 1900 GROUP BY classe HAVING ________ >= 3;'] },
      ecrire3: { kind: 'write', ask: 'Affiche les classes dont la valeur moyenne dépasse 3 000 euros, avec cette moyenne, de la plus chère à la moins chère.', ref: 'SELECT classe, AVG(valeur) FROM specimen GROUP BY classe HAVING AVG(valeur) > 3000 ORDER BY AVG(valeur) DESC', head: 2, hints: ['HAVING sur la moyenne, puis ORDER BY sur la moyenne.', 'SELECT classe, AVG(valeur) FROM specimen GROUP BY classe HAVING AVG(valeur) > 3000 ORDER BY __________ DESC;'] }
    }
  });

  C.chrono({
    id: 'C06', chapter: 4, title: 'Grouper', covers: ['N15', 'N16'], seconds: 240, draw: 5,
    pool: [
      { ask: 'Le nombre de spécimens de chaque salle.', ref: 'SELECT salle, COUNT(*) FROM specimen GROUP BY salle' },
      { ask: 'La valeur totale de chaque origine.', ref: 'SELECT origine, SUM(valeur) FROM specimen GROUP BY origine' },
      { ask: 'Les classes qui comptent exactement 2 spécimens.', ref: 'SELECT classe FROM specimen GROUP BY classe HAVING COUNT(*) = 2' },
      { ask: 'Pour chaque classe, l\'année d\'entrée la plus ancienne.', ref: 'SELECT classe, MIN(annee_entree) FROM specimen GROUP BY classe' },
      { ask: 'Pour chaque classe, le poids moyen arrondi au dixième.', ref: 'SELECT classe, ROUND(AVG(poids_kg), 1) FROM specimen GROUP BY classe' },
      { ask: 'Les origines représentées par plus de 3 spécimens, avec leur nombre.', ref: 'SELECT origine, COUNT(*) FROM specimen GROUP BY origine HAVING COUNT(*) > 3' },
      { ask: 'Pour chaque classe, la valeur la plus élevée, de la plus forte à la plus faible.', ref: 'SELECT classe, MAX(valeur) AS plus_chere FROM specimen GROUP BY classe ORDER BY plus_chere DESC' },
      { ask: 'Le nombre de spécimens revus chaque année (fonction YEAR), sans les spécimens jamais revus.', ref: 'SELECT YEAR(derniere_revue), COUNT(*) FROM specimen WHERE derniere_revue IS NOT NULL GROUP BY YEAR(derniere_revue)' },
      { ask: 'Les salles dont la valeur totale dépasse 10 000 euros, avec ce total.', ref: 'SELECT salle, SUM(valeur) FROM specimen GROUP BY salle HAVING SUM(valeur) > 10000' }
    ]
  });
})(window.SPPContent);
