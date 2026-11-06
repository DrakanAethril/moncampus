/* SQL pas à pas — chapter 2, « Combiner les conditions »: AND, OR, NOT, IN, BETWEEN, LIKE. */
(function (C) {
  'use strict';

  C.level({
    id: 'N05', chapter: 2, num: 5, title: 'Et, ou', summary: 'AND · OR · parenthèses', notions: ['andor'],
    lessons: {
      etou: {
        title: 'Deux conditions : AND, OR',
        html: `<p>Un <code>WHERE</code> peut combiner plusieurs conditions :</p>
<ul><li><code>a AND b</code> est vrai quand <b>les deux</b> sont vraies ;</li><li><code>a OR b</code> est vrai quand <b>au moins une</b> est vraie.</li></ul>
<div class="rule"><span class="kw">WHERE</span> classe = 'Oiseaux' <span class="kw">AND</span> annee_entree &gt; 2000</div>
<p>Attention au français : « les oiseaux et les reptiles » veut dire « ceux qui sont oiseaux <b>ou</b> reptiles ». Une ligne n'a qu'une classe : <code>classe = 'Oiseaux' AND classe = 'Reptiles'</code> n'est jamais vrai.</p>`,
        example: "SELECT nom, classe, annee_entree FROM specimen WHERE classe = 'Oiseaux' AND annee_entree > 2000;",
        tryit: 'Remplace AND par OR : pourquoi y a-t-il bien plus de lignes ?'
      },
      parentheses: {
        title: 'Les parenthèses',
        html: `<p><code>AND</code> passe avant <code>OR</code>, comme la multiplication avant l'addition. Ainsi :</p>
<div class="rule">classe = 'Oiseaux' <span class="kw">OR</span> classe = 'Reptiles' <span class="kw">AND</span> annee_entree &gt; 1950</div>
<p>se lit « les oiseaux, ou bien les reptiles entrés après 1950 ». Pour dire « les oiseaux ou les reptiles, entrés après 1950 », on met des parenthèses :</p>
<div class="rule">(classe = 'Oiseaux' <span class="kw">OR</span> classe = 'Reptiles') <span class="kw">AND</span> annee_entree &gt; 1950</div>`,
        example: "SELECT nom, classe, annee_entree FROM specimen WHERE (classe = 'Oiseaux' OR classe = 'Reptiles') AND annee_entree > 1950;",
        tryit: 'Enlève les parenthèses : quelles lignes apparaissent en plus ?'
      }
    },
    steps: {
      predire: { before: 'etou', kind: 'pick', ask: 'Touche les spécimens que cette requête va renvoyer.', query: "SELECT nom FROM specimen WHERE classe = 'Oiseaux' AND annee_entree > 2000;", pick: "SELECT id FROM specimen WHERE classe = 'Oiseaux' AND annee_entree > 2000", show: ['classe', 'annee_entree'], hints: ['Les deux conditions doivent être vraies : un oiseau, et une année après 2000.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom et l\'année des mammifères entrés après 1950.', template: "SELECT nom, annee_entree FROM specimen WHERE classe = 'Mammifères' [[]] annee_entree > 1950;", fill: ['AND'], ref: "SELECT nom, annee_entree FROM specimen WHERE classe = 'Mammifères' AND annee_entree > 1950", hints: ['Les deux conditions doivent être vraies en même temps.', "… WHERE classe = 'Mammifères' ___ annee_entree > 1950;"] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom et la classe des oiseaux et des reptiles. Elle ne renvoie rien : répare-la.', starter: "SELECT nom, classe FROM specimen WHERE classe = 'Oiseaux' AND classe = 'Reptiles';", ref: "SELECT nom, classe FROM specimen WHERE classe = 'Oiseaux' OR classe = 'Reptiles'", hints: ['Une ligne peut-elle être un oiseau et un reptile à la fois ?', "… WHERE classe = 'Oiseaux' __ classe = 'Reptiles';"] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et la valeur des spécimens qui valent plus de 5 000 euros et sont entrés avant 1950.', ref: 'SELECT nom, valeur FROM specimen WHERE valeur > 5000 AND annee_entree < 1950', head: 2, hints: ['Deux conditions, toutes deux nécessaires.', 'SELECT nom, valeur FROM specimen WHERE valeur > ____ AND annee_entree < ____;'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom et la salle des spécimens rangés en R1 ou en R4.', ref: "SELECT nom, salle FROM specimen WHERE salle = 'R1' OR salle = 'R4'", head: 3, hints: ['Une salle ou l\'autre : c\'est OR.', "SELECT nom, salle FROM specimen WHERE salle = 'R1' OR salle = '__';"] },
      ecrire3: { before: 'parentheses', kind: 'write', ask: 'Affiche le nom, la classe et l\'année des oiseaux et des reptiles entrés après 1950.', ref: "SELECT nom, classe, annee_entree FROM specimen WHERE (classe = 'Oiseaux' OR classe = 'Reptiles') AND annee_entree > 1950", head: 3, hints: ['Le OR doit être évalué d\'abord : entoure-le de parenthèses.', "… WHERE (classe = 'Oiseaux' OR classe = 'Reptiles') AND ____;"] }
    }
  });

  C.level({
    id: 'N06', chapter: 2, num: 6, title: 'Le contraire', summary: 'NOT · <> · ni l\'un ni l\'autre', notions: ['not'],
    lessons: {
      not: {
        title: 'NOT et <>',
        html: `<p><code>&lt;&gt;</code> (on lit « différent de ») et <code>NOT</code> disent le contraire :</p>
<div class="rule"><span class="kw">WHERE</span> classe &lt;&gt; 'Oiseaux'<br><span class="kw">WHERE NOT</span> classe = 'Oiseaux'</div>
<p>Ces deux conditions gardent les mêmes lignes. <code>NOT</code> s'applique aussi à toute une condition entre parenthèses : <code>NOT (a OR b)</code>.</p>
<p>« Ni oiseau ni mammifère » se dit <code>classe &lt;&gt; 'Oiseaux' AND classe &lt;&gt; 'Mammifères'</code> : avec <code>OR</code>, toute ligne serait gardée, car un oiseau n'est pas un mammifère.</p>
<p>Une case <code>NULL</code> n'est ni égale ni différente de quoi que ce soit : <code>salle &lt;&gt; 'R2'</code> ne garde pas les spécimens sans salle.</p>`,
        example: "SELECT nom, classe FROM specimen WHERE classe <> 'Oiseaux' AND classe <> 'Mammifères';",
        tryit: 'Remplace AND par OR et compte les lignes.'
      }
    },
    steps: {
      predire: { before: 'not', kind: 'pick', ask: 'Touche les spécimens que cette requête va renvoyer.', query: "SELECT nom FROM specimen WHERE salle = 'R3' AND NOT classe = 'Fossiles';", pick: "SELECT id FROM specimen WHERE salle = 'R3' AND NOT classe = 'Fossiles'", show: ['classe', 'salle'], hints: ['En salle R3, sauf les fossiles.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom et la classe de tous les spécimens, sauf les oiseaux.', template: "SELECT nom, classe FROM specimen WHERE classe [[]] 'Oiseaux';", fill: ['<>'], ref: "SELECT nom, classe FROM specimen WHERE classe <> 'Oiseaux'", hints: ['L\'opérateur « différent de ».', "… WHERE classe __ 'Oiseaux';"] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom et la classe des spécimens qui ne sont ni des oiseaux ni des mammifères. Elle les garde tous : répare-la.', starter: "SELECT nom, classe FROM specimen WHERE classe <> 'Oiseaux' OR classe <> 'Mammifères';", ref: "SELECT nom, classe FROM specimen WHERE classe <> 'Oiseaux' AND classe <> 'Mammifères'", hints: ['Un oiseau est-il différent de « Mammifères » ? Alors OR le garde.', "… WHERE classe <> 'Oiseaux' ___ classe <> 'Mammifères';"] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et l\'origine des spécimens qui ne viennent ni d\'Europe ni d\'Afrique.', ref: "SELECT nom, origine FROM specimen WHERE origine <> 'Europe' AND origine <> 'Afrique'", head: 3, hints: ['« Ni l\'un ni l\'autre » : deux « différent de », reliés par AND.', "… WHERE origine <> 'Europe' AND origine <> '_______';"] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom et la valeur des spécimens qui ne sont pas des insectes et valent moins de 300 euros.', ref: "SELECT nom, valeur FROM specimen WHERE classe <> 'Insectes' AND valeur < 300", head: 3, hints: ['Deux conditions nécessaires.', "… WHERE classe <> 'Insectes' AND valeur < ___;"] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom et l\'année des spécimens qui ne sont pas entrés au XXᵉ siècle (de 1900 à 1999).', ref: 'SELECT nom, annee_entree FROM specimen WHERE NOT (annee_entree >= 1900 AND annee_entree <= 1999)', head: 3, hints: ['Écris la condition « de 1900 à 1999 », puis mets NOT devant, entre parenthèses.', 'SELECT nom, annee_entree FROM specimen WHERE NOT (annee_entree >= ____ AND annee_entree <= ____);'] }
    }
  });

  C.level({
    id: 'N07', chapter: 2, num: 7, title: 'Listes et intervalles', summary: 'IN · NOT IN · BETWEEN', notions: ['in'],
    lessons: {
      in: {
        title: 'Une liste de valeurs : IN',
        html: `<p>Plutôt que d'enchaîner les <code>OR</code> sur une même colonne, on donne la liste :</p>
<div class="rule"><span class="kw">WHERE</span> salle <span class="kw">IN</span> ('R1', 'R3', 'R4')</div>
<p>C'est exactement <code>salle = 'R1' OR salle = 'R3' OR salle = 'R4'</code>. <code>NOT IN (…)</code> garde tout le reste.</p>`,
        example: "SELECT nom, salle FROM specimen WHERE salle IN ('R1', 'R4');",
        tryit: 'Essaie NOT IN à la place de IN.'
      },
      between: {
        title: 'Un intervalle : BETWEEN',
        html: `<p><code>BETWEEN a AND b</code> garde les valeurs de <code>a</code> à <code>b</code>, <b>les deux bornes comprises</b> :</p>
<div class="rule"><span class="kw">WHERE</span> annee_entree <span class="kw">BETWEEN</span> 1950 <span class="kw">AND</span> 1960</div>
<p>C'est <code>annee_entree &gt;= 1950 AND annee_entree &lt;= 1960</code>. La plus petite borne s'écrit en premier : <code>BETWEEN 1960 AND 1950</code> ne garde rien.</p>`,
        example: 'SELECT nom, annee_entree FROM specimen WHERE annee_entree BETWEEN 1950 AND 1960;',
        tryit: 'Inverse les deux bornes et regarde ce qui se passe.'
      }
    },
    steps: {
      predire: { before: 'between', kind: 'pick', ask: 'Touche les spécimens que cette requête va renvoyer.', query: 'SELECT nom FROM specimen WHERE annee_entree BETWEEN 1950 AND 1960;', pick: 'SELECT id FROM specimen WHERE annee_entree BETWEEN 1950 AND 1960', show: ['annee_entree'], hints: ['De 1950 à 1960, bornes comprises.'] },
      comprendre: {},
      completer: { before: 'in', kind: 'complete', ask: 'Affiche le nom et la classe des amphibiens, des reptiles et des poissons.', template: "SELECT nom, classe FROM specimen WHERE classe [[]] ('Amphibiens', 'Reptiles', 'Poissons');", fill: ['IN'], ref: "SELECT nom, classe FROM specimen WHERE classe IN ('Amphibiens', 'Reptiles', 'Poissons')", hints: ['Le mot qui précède une liste de valeurs.', "… WHERE classe __ ('Amphibiens', 'Reptiles', 'Poissons');"] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom et la valeur des spécimens qui valent de 1 000 à 3 000 euros, bornes comprises. Elle ne renvoie rien : répare-la.', starter: 'SELECT nom, valeur FROM specimen WHERE valeur BETWEEN 3000 AND 1000;', ref: 'SELECT nom, valeur FROM specimen WHERE valeur BETWEEN 1000 AND 3000', hints: ['Dans quel ordre s\'écrivent les bornes de BETWEEN ?', 'SELECT nom, valeur FROM specimen WHERE valeur BETWEEN ____ AND ____;'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom et la salle des spécimens rangés en R1, R3 ou R4.', ref: "SELECT nom, salle FROM specimen WHERE salle IN ('R1', 'R3', 'R4')", head: 3, hints: ['Une liste de trois salles.', "… WHERE salle IN ('R1', '__', '__');"] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom et l\'année des spécimens entrés de 1900 à 1950, bornes comprises.', ref: 'SELECT nom, annee_entree FROM specimen WHERE annee_entree BETWEEN 1900 AND 1950', head: 3, hints: ['Un intervalle, bornes comprises.', '… WHERE annee_entree BETWEEN ____ AND ____;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom et l\'origine des spécimens qui ne viennent ni d\'Europe, ni d\'Afrique, ni d\'Asie.', ref: "SELECT nom, origine FROM specimen WHERE origine NOT IN ('Europe', 'Afrique', 'Asie')", head: 3, hints: ['Tout sauf une liste.', "… WHERE origine NOT IN ('Europe', '_______', '____');"] }
    }
  });

  C.level({
    id: 'N08', chapter: 2, num: 8, title: 'Chercher dans un texte', summary: 'LIKE · % · _', notions: ['like'],
    lessons: {
      like: {
        title: 'LIKE et le joker %',
        html: `<p><code>LIKE</code> compare un texte à un <b>motif</b>, où <code>%</code> remplace n'importe quelle suite de caractères (même aucune) :</p>
<ul><li><code>nom LIKE 'P%'</code> : commence par P ;</li><li><code>nom LIKE '%e'</code> : finit par e ;</li><li><code>nom LIKE '%noir%'</code> : contient « noir ».</li></ul>
<p>Sans <code>%</code>, <code>LIKE 'pic'</code> compare le texte entier, comme <code>=</code>. Avec la collation de MySQL, la casse ne compte pas : <code>'%pingouin%'</code> trouve aussi « Pingouin ».</p>
<p>Le joker <code>_</code> remplace exactement un caractère : <code>LIKE 'MHN-195_-%'</code>.</p>`,
        example: "SELECT nom FROM specimen WHERE nom LIKE 'P%';",
        tryit: "Cherche les noms qui contiennent « on »."
      }
    },
    steps: {
      predire: { before: 'like', kind: 'pick', ask: 'Touche les spécimens que cette requête va renvoyer.', query: "SELECT nom FROM specimen WHERE nom LIKE 'P%';", pick: "SELECT id FROM specimen WHERE nom LIKE 'P%'", show: ['classe'], hints: ['Le nom commence par la lettre P.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom des spécimens dont le nom commence par « Grand ».', template: 'SELECT nom FROM specimen WHERE nom [[]] [[]];', fill: ['LIKE', "'Grand%'"], ref: "SELECT nom FROM specimen WHERE nom LIKE 'Grand%'", hints: ['L\'opérateur des motifs, puis le motif entre apostrophes, avec son joker à la fin.', "… WHERE nom LIKE 'Grand_';"] },
      reparer: { kind: 'repair', ask: 'Cette requête doit afficher le nom des spécimens dont le nom contient « noir ». Elle ne renvoie rien : répare-la.', starter: "SELECT nom FROM specimen WHERE nom = '%noir%';", ref: "SELECT nom FROM specimen WHERE nom LIKE '%noir%'", hints: ['Le % n\'est un joker qu\'avec un certain opérateur.', "… WHERE nom ____ '%noir%';"] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom des spécimens dont le nom se termine par la lettre « e ».', ref: "SELECT nom FROM specimen WHERE nom LIKE '%e'", head: 3, hints: ['Le joker se place avant la lettre.', "… WHERE nom LIKE '_e';"] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom des spécimens dont le nom contient « pingouin ».', ref: "SELECT nom FROM specimen WHERE nom LIKE '%pingouin%'", head: 1, hints: ['N\'importe où dans le nom : un joker de chaque côté.', "… WHERE nom LIKE '_pingouin_';"] },
      ecrire3: { kind: 'write', ask: 'Les numéros d\'inventaire des spécimens entrés dans les années 1950 commencent par « MHN-195 ». Affiche leur numéro d\'inventaire et leur nom.', ref: "SELECT inventaire, nom FROM specimen WHERE inventaire LIKE 'MHN-195%'", head: 2, hints: ['Un motif qui commence par MHN-195.', "… WHERE inventaire LIKE 'MHN-195_';"] }
    }
  });

  C.chrono({
    id: 'C03', chapter: 2, title: 'Conditions', covers: ['N05', 'N06', 'N07', 'N08'], seconds: 240, draw: 5,
    pool: [
      { ask: 'Le nom des oiseaux entrés avant 1950.', ref: "SELECT nom FROM specimen WHERE classe = 'Oiseaux' AND annee_entree < 1950" },
      { ask: 'Le nom et la classe des fossiles et des insectes.', ref: "SELECT nom, classe FROM specimen WHERE classe IN ('Fossiles', 'Insectes')" },
      { ask: 'Le nom et la valeur des spécimens valant de 500 à 2 000 euros, bornes comprises.', ref: 'SELECT nom, valeur FROM specimen WHERE valeur BETWEEN 500 AND 2000' },
      { ask: 'Le nom des spécimens dont le nom contient « ou ».', ref: "SELECT nom FROM specimen WHERE nom LIKE '%ou%'" },
      { ask: 'Le nom et l\'origine des spécimens qui ne viennent pas d\'Europe.', ref: "SELECT nom, origine FROM specimen WHERE origine <> 'Europe'" },
      { ask: 'Le nom et la classe des spécimens de la salle R2 qui ne sont pas des mammifères.', ref: "SELECT nom, classe FROM specimen WHERE salle = 'R2' AND classe <> 'Mammifères'" },
      { ask: 'Le nom, la classe et l\'année des reptiles et des amphibiens entrés après 1950.', ref: "SELECT nom, classe, annee_entree FROM specimen WHERE classe IN ('Reptiles', 'Amphibiens') AND annee_entree > 1950" },
      { ask: 'Le nom des spécimens dont le nom commence par C.', ref: "SELECT nom FROM specimen WHERE nom LIKE 'C%'" },
      { ask: 'Le nom et l\'origine des spécimens d\'Asie ou d\'Océanie.', ref: "SELECT nom, origine FROM specimen WHERE origine IN ('Asie', 'Océanie')" },
      { ask: 'Le nom et le poids des spécimens de 1 à 10 kg, bornes comprises.', ref: 'SELECT nom, poids_kg FROM specimen WHERE poids_kg BETWEEN 1 AND 10' }
    ]
  });
})(window.SPPContent);
