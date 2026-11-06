/* SQL pas à pas — chapter 1, « Lire une table »: SELECT, FROM, WHERE, ORDER BY, LIMIT. */
(function (C) {
  'use strict';
  var year1950 = function (c) { return c.extra.some(function (r) { return String(r[1]) === '1950'; }) ? 'Sur une autre base, une ligne de 1950 apparaît dans ton résultat : « avant 1950 » exclut 1950. Utilise <code>&lt;</code>, pas <code>&lt;=</code>.' : null; };

  C.level({
    id: 'N01', chapter: 1, num: 1, title: 'Lire une table', summary: 'SELECT · FROM · * · colonnes', notions: ['select', 'colonnes'],
    lessons: {
      table: {
        title: 'Une table, des lignes, des colonnes',
        html: `<p>Une base de données range ses informations dans des <b>tables</b>. Une table ressemble à un tableau : chaque <b>ligne</b> décrit un élément (ici, un spécimen de la collection d'un muséum), chaque <b>colonne</b> une information sur lui (son nom, sa classe, son année d'entrée…).</p>
<p>Pour lire une table, on écrit une <b>requête</b> en SQL. La plus simple affiche tout :</p>
<div class="rule"><span class="kw">SELECT</span> * <span class="kw">FROM</span> specimen;</div>
<p><code>SELECT</code> dit ce qu'on veut voir, <code>*</code> veut dire « toutes les colonnes », et <code>FROM</code> nomme la table. Le point-virgule termine la requête.</p>
<p>Une case vide s'affiche <code>NULL</code> : l'information est inconnue.</p>`,
        example: 'SELECT * FROM specimen;',
        tryit: 'Exécute-la, puis fais défiler le résultat vers la droite : combien de colonnes ? Repère les cases NULL.'
      },
      colonnes: {
        title: 'Choisir ses colonnes',
        html: `<p>On répond rarement à une question avec toutes les colonnes. On nomme celles qu'on veut, <b>séparées par des virgules</b>, dans l'ordre où on veut les voir :</p>
<div class="rule"><span class="kw">SELECT</span> nom, classe <span class="kw">FROM</span> specimen;</div>
<p>Les noms des colonnes s'écrivent exactement comme dans la table (la carte de la base, en haut, les donne). Les mots du SQL (<code>SELECT</code>, <code>FROM</code>…) s'écrivent en majuscules par habitude ; MySQL les accepte aussi en minuscules.</p>`,
        example: 'SELECT nom, classe FROM specimen;',
        tryit: 'Ajoute la colonne origine à la fin, après une virgule.'
      }
    },
    steps: {
      predire: { before: 'table', kind: 'value', ask: 'Sans l\'exécuter : combien de colonnes cette requête affiche-t-elle ?', query: 'SELECT nom, classe, salle FROM specimen;', value: 'SELECT 3', hints: ['Compte les noms écrits entre SELECT et FROM.'] },
      comprendre: {},
      completer: { before: 'colonnes', kind: 'complete', ask: 'Affiche le nom et l\'année d\'entrée de chaque spécimen.', template: 'SELECT [[]], [[]] FROM specimen;', fill: ['nom', 'annee_entree'], ref: 'SELECT nom, annee_entree FROM specimen', hints: ['Les deux trous sont deux noms de colonnes. Ouvre la carte de la base : lesquelles portent le nom et l\'année ?', 'SELECT nom, ____ FROM specimen;'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom et l\'origine de chaque spécimen. Elle ne donne qu\'une colonne : répare-la.', starter: 'SELECT nom origine FROM specimen;', ref: 'SELECT nom, origine FROM specimen', hints: ['Combien de colonnes obtiens-tu ? Que manque-t-il entre les deux noms ?', 'SELECT nom, ____ FROM specimen;'] },
      ecrire1: { kind: 'write', ask: 'Affiche le numéro d\'inventaire et le nom de chaque spécimen.', ref: 'SELECT inventaire, nom FROM specimen', head: 3, hints: ['Deux colonnes, une table.', 'SELECT ____, ____ FROM specimen;'] },
      ecrire2: { kind: 'write', ask: 'Affiche la classe, le nom et la valeur (en euros) de chaque spécimen.', ref: 'SELECT classe, nom, valeur FROM specimen', head: 3, hints: ['Trois colonnes séparées par des virgules.', 'SELECT classe, ____, ____ FROM specimen;'] },
      ecrire3: { kind: 'write', ask: 'Affiche toutes les colonnes de tous les spécimens.', ref: 'SELECT * FROM specimen', head: 2, hints: ['Un seul caractère veut dire « toutes les colonnes ».', 'SELECT _ FROM specimen;'] }
    }
  });

  C.level({
    id: 'N02', chapter: 1, num: 2, title: 'Garder certaines lignes', summary: "WHERE · = <> < > <= >= · 'texte'", notions: ['where', 'texte'],
    lessons: {
      where: {
        title: 'Filtrer avec WHERE',
        html: `<p><code>WHERE</code> se place après <code>FROM</code> et ne garde que les lignes pour lesquelles la <b>condition</b> est vraie :</p>
<div class="rule"><span class="kw">SELECT</span> colonnes <span class="kw">FROM</span> table <span class="kw">WHERE</span> condition;</div>
<p>Une condition compare une colonne à une valeur : <code>=</code> égal, <code>&lt;&gt;</code> différent, <code>&lt;</code> inférieur, <code>&gt;</code> supérieur, <code>&lt;=</code> inférieur ou égal, <code>&gt;=</code> supérieur ou égal.</p>
<div class="rule"><span class="kw">SELECT</span> nom, annee_entree <span class="kw">FROM</span> specimen <span class="kw">WHERE</span> annee_entree &lt; 1900;</div>
<p>Attention aux bornes : <code>&lt; 1900</code> exclut 1900, <code>&lt;= 1900</code> l'inclut.</p>`,
        example: 'SELECT nom, annee_entree FROM specimen WHERE annee_entree < 1900;',
        tryit: 'Remplace < par >= : quelles lignes restent ?'
      },
      texte: {
        title: 'Comparer un texte',
        html: `<p>Un nombre s'écrit tel quel. Un <b>texte s'écrit entre apostrophes</b> droites :</p>
<div class="rule"><span class="kw">SELECT</span> nom, salle <span class="kw">FROM</span> specimen <span class="kw">WHERE</span> salle = 'R3';</div>
<p>Sans apostrophes, MySQL croit que <code>R3</code> est le nom d'une colonne et répond par une erreur : <code>Unknown column 'R3'</code>.</p>
<p>Avec la collation de MySQL, <code>'r3'</code> et <code>'R3'</code> sont égaux : la casse ne compte pas. Les accents, eux, doivent être écrits comme dans la table.</p>`,
        example: "SELECT nom, salle FROM specimen WHERE salle = 'R3';",
        tryit: 'Enlève les apostrophes autour de R3 et lis l\'erreur.'
      }
    },
    steps: {
      predire: { before: 'where', kind: 'pick', ask: 'Touche les spécimens que cette requête va renvoyer, puis vérifie.', query: 'SELECT nom FROM specimen WHERE annee_entree < 1910;', pick: 'SELECT id FROM specimen WHERE annee_entree < 1910', show: ['classe', 'annee_entree'], hints: ['Regarde l\'année de chaque fiche : est-elle strictement plus petite que 1910 ?'] },
      comprendre: {},
      completer: { before: 'texte', kind: 'complete', ask: 'Affiche le nom des spécimens rangés en salle R4.', template: "SELECT nom FROM specimen [[]] [[]] = 'R4';", fill: ['WHERE', 'salle'], ref: "SELECT nom FROM specimen WHERE salle = 'R4'", hints: ['Un mot du SQL qui introduit une condition, puis la colonne qui contient la salle.', "SELECT nom FROM specimen WHERE ____ = 'R4';"] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom et l\'année d\'entrée des oiseaux. Elle contient deux erreurs : répare-la.', starter: 'SELECT nom annee_entree FROM specimen WHERE classe = Oiseaux;', ref: "SELECT nom, annee_entree FROM specimen WHERE classe = 'Oiseaux'", hints: ['Exécute-la et lis le message : il désigne un mot. Est-ce une colonne ou une valeur ? Puis compte les colonnes obtenues.', "SELECT nom, annee_entree FROM specimen WHERE classe = '____';"] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et l\'année d\'entrée des spécimens entrés avant 1950.', ref: 'SELECT nom, annee_entree FROM specimen WHERE annee_entree < 1950', head: 3, hidden: year1950, hints: ['Quelle colonne ? Quel opérateur pour « avant » ?', 'SELECT nom, annee_entree FROM specimen WHERE ____ < ____;'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom et la valeur des spécimens qui valent au moins 5 000 euros.', ref: 'SELECT nom, valeur FROM specimen WHERE valeur >= 5000', head: 3, hints: ['« Au moins » : la borne est-elle comprise ? Un nombre s\'écrit sans espace : 5000.', 'SELECT nom, valeur FROM specimen WHERE valeur __ 5000;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom de tous les spécimens qui ne viennent pas d\'Europe.', ref: "SELECT nom FROM specimen WHERE origine <> 'Europe'", head: 3, hints: ['La colonne origine ; l\'opérateur « différent de » ; un texte entre apostrophes.', "SELECT nom FROM specimen WHERE origine __ 'Europe';"] }
    }
  });

  C.chrono({
    id: 'C01', chapter: 1, title: 'Lire et filtrer', covers: ['N01', 'N02'], seconds: 240, draw: 5,
    pool: [
      { ask: 'Le nom des fossiles.', ref: "SELECT nom FROM specimen WHERE classe = 'Fossiles'" },
      { ask: 'Le nom et la salle des spécimens entrés après 2000.', ref: 'SELECT nom, salle FROM specimen WHERE annee_entree > 2000' },
      { ask: 'Le nom et la salle de tout ce qui n\'est pas en salle R2.', ref: "SELECT nom, salle FROM specimen WHERE salle <> 'R2'" },
      { ask: 'Le numéro d\'inventaire des reptiles.', ref: "SELECT inventaire FROM specimen WHERE classe = 'Reptiles'" },
      { ask: 'Le nom et l\'année des spécimens entrés en 1958 ou avant.', ref: 'SELECT nom, annee_entree FROM specimen WHERE annee_entree <= 1958' },
      { ask: 'Toutes les colonnes des poissons.', ref: "SELECT * FROM specimen WHERE classe = 'Poissons'" },
      { ask: 'Le nom des spécimens rangés en salle R1.', ref: "SELECT nom FROM specimen WHERE salle = 'R1'" },
      { ask: 'Le nom des spécimens qui pèsent plus de 100 kg.', ref: 'SELECT nom FROM specimen WHERE poids_kg > 100' },
      { ask: 'Le nom et l\'origine des spécimens venus d\'Afrique.', ref: "SELECT nom, origine FROM specimen WHERE origine = 'Afrique'" },
      { ask: 'Le nom et la valeur des spécimens valant moins de 200 euros.', ref: 'SELECT nom, valeur FROM specimen WHERE valeur < 200' }
    ]
  });

  C.level({
    id: 'N03', chapter: 1, num: 3, title: 'Trier', summary: 'ORDER BY · ASC · DESC · plusieurs critères', notions: ['orderby'],
    lessons: {
      tri: {
        title: 'Trier avec ORDER BY',
        html: `<p>Sans consigne, MySQL renvoie les lignes dans l'ordre qui l'arrange : <b>rien ne garantit l'ordre</b>. Pour trier, on ajoute <code>ORDER BY</code> à la fin de la requête, après <code>WHERE</code> :</p>
<div class="rule"><span class="kw">SELECT</span> nom, annee_entree <span class="kw">FROM</span> specimen <span class="kw">ORDER BY</span> annee_entree;</div>
<p>Le tri est croissant par défaut (<code>ASC</code>, du plus petit au plus grand, de A à Z). <code>DESC</code> le rend décroissant :</p>
<div class="rule"><span class="kw">ORDER BY</span> annee_entree <span class="kw">DESC</span></div>
<p>Les cases <code>NULL</code> passent en premier dans un tri croissant, en dernier dans un tri décroissant.</p>`,
        example: 'SELECT nom, annee_entree FROM specimen ORDER BY annee_entree;',
        tryit: 'Ajoute DESC après annee_entree.'
      },
      criteres: {
        title: 'Plusieurs critères',
        html: `<p>On peut trier sur plusieurs colonnes, séparées par des virgules. La deuxième ne sert qu'à départager les lignes égales sur la première :</p>
<div class="rule"><span class="kw">SELECT</span> classe, nom <span class="kw">FROM</span> specimen <span class="kw">ORDER BY</span> classe, nom;</div>
<p>Ici, les spécimens sont rangés par classe, et à l'intérieur de chaque classe par nom. Chaque critère a son propre sens : <code>ORDER BY classe, valeur DESC</code> range les classes de A à Z, et dans chaque classe les valeurs de la plus forte à la plus faible.</p>`,
        example: 'SELECT classe, nom FROM specimen ORDER BY classe, nom;',
        tryit: 'Trie plutôt par classe, puis par année d\'entrée décroissante.'
      }
    },
    steps: {
      predire: { before: 'tri', kind: 'pick', ask: 'Touche le spécimen qui s\'affichera sur la première ligne.', query: 'SELECT nom, valeur FROM specimen ORDER BY valeur DESC;', pick: 'SELECT id FROM specimen ORDER BY valeur DESC LIMIT 1', show: ['valeur'], hints: ['DESC : du plus grand au plus petit. La première ligne porte la valeur la plus forte.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom et le poids des spécimens, du plus lourd au plus léger.', template: 'SELECT nom, poids_kg FROM specimen ORDER BY [[]] [[]];', fill: ['poids_kg', 'DESC'], ref: 'SELECT nom, poids_kg FROM specimen ORDER BY poids_kg DESC', hints: ['La colonne à trier, puis le sens du tri.', 'SELECT nom, poids_kg FROM specimen ORDER BY poids_kg ____;'] },
      reparer: { before: 'criteres', kind: 'repair', ask: 'Cette requête doit afficher le nom et l\'année des oiseaux, des plus récents aux plus anciens. MySQL la refuse : répare-la.', starter: "SELECT nom, annee_entree FROM specimen ORDER BY annee_entree DESC WHERE classe = 'Oiseaux';", ref: "SELECT nom, annee_entree FROM specimen WHERE classe = 'Oiseaux' ORDER BY annee_entree DESC", hints: ['L\'ordre des parties d\'une requête est fixe : SELECT, FROM, WHERE, puis ORDER BY.', "SELECT nom, annee_entree FROM specimen WHERE ____ ORDER BY ____;"] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom de tous les spécimens, par ordre alphabétique.', ref: 'SELECT nom FROM specimen ORDER BY nom', head: 3, hints: ['On trie sur la colonne nom ; l\'ordre alphabétique est le sens par défaut.', 'SELECT nom FROM specimen ORDER BY ____;'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom, la classe et la valeur, triés par classe, puis par valeur décroissante dans chaque classe.', ref: 'SELECT nom, classe, valeur FROM specimen ORDER BY classe, valeur DESC', head: 3, hints: ['Deux critères de tri, chacun avec son sens.', 'SELECT nom, classe, valeur FROM specimen ORDER BY classe, ____ ____;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom et l\'année d\'entrée des mammifères, du plus ancien au plus récent.', ref: "SELECT nom, annee_entree FROM specimen WHERE classe = 'Mammifères' ORDER BY annee_entree", head: 3, hints: ['Un WHERE sur la classe, puis un ORDER BY sur l\'année.', "SELECT nom, annee_entree FROM specimen WHERE classe = 'Mammifères' ORDER BY ____;"] }
    }
  });

  C.level({
    id: 'N04', chapter: 1, num: 4, title: 'Les premières lignes', summary: 'LIMIT · OFFSET · « le top 3 »', notions: ['limit'],
    lessons: {
      limit: {
        title: 'Garder les premières lignes',
        html: `<p><code>LIMIT n</code> se place tout à la fin de la requête et ne garde que les <code>n</code> premières lignes du résultat :</p>
<div class="rule"><span class="kw">SELECT</span> nom, valeur <span class="kw">FROM</span> specimen <span class="kw">ORDER BY</span> valeur <span class="kw">DESC</span> <span class="kw">LIMIT</span> 3;</div>
<p>C'est le « top 3 » des spécimens les plus chers. <b>Sans <code>ORDER BY</code>, « les trois premières lignes » ne veut rien dire</b> : MySQL renvoie trois lignes, sans garantir lesquelles.</p>`,
        example: 'SELECT nom, valeur FROM specimen ORDER BY valeur DESC LIMIT 3;',
        tryit: 'Garde les 5 plus chers au lieu de 3.'
      },
      offset: {
        title: 'Les pages : OFFSET',
        html: `<p>Pour afficher une liste par pages, on saute des lignes avec <code>OFFSET</code> :</p>
<div class="rule"><span class="kw">ORDER BY</span> nom <span class="kw">LIMIT</span> 10 <span class="kw">OFFSET</span> 10</div>
<p>Ici on saute les 10 premières lignes et on garde les 10 suivantes : c'est la page 2. La page 3 serait <code>LIMIT 10 OFFSET 20</code>. <code>LIMIT 1 OFFSET 1</code> donne la deuxième ligne seule.</p>`,
        example: 'SELECT nom FROM specimen ORDER BY nom LIMIT 10 OFFSET 10;',
        tryit: 'Affiche la page 3.'
      }
    },
    steps: {
      predire: { before: 'limit', kind: 'pick', ask: 'Touche les spécimens que cette requête va renvoyer.', query: 'SELECT nom FROM specimen ORDER BY annee_entree LIMIT 3;', pick: 'SELECT id FROM specimen ORDER BY annee_entree LIMIT 3', show: ['annee_entree'], hints: ['Trie les fiches de la plus ancienne à la plus récente, et garde les trois premières.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom et le poids des 5 spécimens les plus lourds.', template: 'SELECT nom, poids_kg FROM specimen ORDER BY poids_kg DESC [[]] [[]];', fill: ['LIMIT', '5'], ref: 'SELECT nom, poids_kg FROM specimen ORDER BY poids_kg DESC LIMIT 5', hints: ['Le mot qui garde les premières lignes, puis leur nombre.', 'SELECT nom, poids_kg FROM specimen ORDER BY poids_kg DESC LIMIT _;'] },
      reparer: { kind: 'repair', ask: 'Cette requête doit donner le nom et l\'année des 3 spécimens les plus récents. Elle donne les 3 plus anciens : répare-la.', starter: 'SELECT nom, annee_entree FROM specimen ORDER BY annee_entree LIMIT 3;', ref: 'SELECT nom, annee_entree FROM specimen ORDER BY annee_entree DESC LIMIT 3', hints: ['Le tri va dans le mauvais sens.', 'SELECT nom, annee_entree FROM specimen ORDER BY annee_entree ____ LIMIT 3;'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et la valeur du spécimen le plus cher.', ref: 'SELECT nom, valeur FROM specimen ORDER BY valeur DESC LIMIT 1', head: 1, hints: ['Trie par valeur décroissante et garde une seule ligne.', 'SELECT nom, valeur FROM specimen ORDER BY ____ ____ LIMIT 1;'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom et l\'année des 4 oiseaux entrés le plus anciennement.', ref: "SELECT nom, annee_entree FROM specimen WHERE classe = 'Oiseaux' ORDER BY annee_entree LIMIT 4", head: 2, hints: ['WHERE, puis ORDER BY, puis LIMIT : dans cet ordre.', "SELECT nom, annee_entree FROM specimen WHERE classe = 'Oiseaux' ORDER BY ____ LIMIT _;"] },
      ecrire3: { before: 'offset', kind: 'write', ask: 'Liste les noms par ordre alphabétique, 10 par page : affiche la deuxième page.', ref: 'SELECT nom FROM specimen ORDER BY nom LIMIT 10 OFFSET 10', head: 2, hints: ['La page 2 saute les 10 premiers noms.', 'SELECT nom FROM specimen ORDER BY nom LIMIT __ OFFSET __;'] }
    }
  });

  C.chrono({
    id: 'C02', chapter: 1, title: 'Trier et limiter', covers: ['N03', 'N04'], seconds: 240, draw: 5,
    pool: [
      { ask: 'Le nom et la valeur des 3 spécimens les moins chers.', ref: 'SELECT nom, valeur FROM specimen ORDER BY valeur LIMIT 3' },
      { ask: 'Le nom de tous les spécimens, par ordre alphabétique inverse (de Z à A).', ref: 'SELECT nom FROM specimen ORDER BY nom DESC' },
      { ask: 'Le nom et l\'année des reptiles, du plus récent au plus ancien.', ref: "SELECT nom, annee_entree FROM specimen WHERE classe = 'Reptiles' ORDER BY annee_entree DESC" },
      { ask: 'Le nom et la valeur du deuxième spécimen le plus cher.', ref: 'SELECT nom, valeur FROM specimen ORDER BY valeur DESC LIMIT 1 OFFSET 1' },
      { ask: 'La classe et le nom de chaque spécimen, triés par classe, puis par nom.', ref: 'SELECT classe, nom FROM specimen ORDER BY classe, nom' },
      { ask: 'Le nom et le poids des 2 poissons les plus lourds.', ref: "SELECT nom, poids_kg FROM specimen WHERE classe = 'Poissons' ORDER BY poids_kg DESC LIMIT 2" },
      { ask: 'Le nom et l\'année des 5 derniers spécimens entrés.', ref: 'SELECT nom, annee_entree FROM specimen ORDER BY annee_entree DESC LIMIT 5' },
      { ask: 'Le nom et l\'année des 3 spécimens d\'Europe entrés le plus anciennement.', ref: "SELECT nom, annee_entree FROM specimen WHERE origine = 'Europe' ORDER BY annee_entree LIMIT 3" },
      { ask: 'Le nom et la valeur des spécimens de la salle R3, du plus cher au moins cher.', ref: "SELECT nom, valeur FROM specimen WHERE salle = 'R3' ORDER BY valeur DESC" }
    ]
  });
})(window.SPPContent);
