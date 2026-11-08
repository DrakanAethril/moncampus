/* PHP pas à pas — chapter 5, « Tableaux à deux dimensions »: grids, lists of records, a list inside a
   record, grouping, building, sorting. The chapter students find the hardest: every level starts from a
   concrete table (a seating plan, a timetable, the museum's collection, a class), every program is checked
   on a grid that is not square (2 lines × 5 columns, so that swapping line and column shows), an empty
   list, a single record, a record with no grade and ties. Outputs are compared line by line.
   Program sources are template literals: a real line break is a line of PHP, `\\n` is PHP's newline. */
(function (C) {
  'use strict';

  /* ——— N35 · a grid ——— */
  C.level({
    id: 'N35', chapter: 5, num: 35, title: 'Une grille', summary: '$g[$ligne][$colonne] · count($g) · count($g[0])', notions: ['grille'],
    lessons: {
      grille: {
        title: 'Une liste de listes',
        html: `<p>Au chapitre 4, une liste rangeait des valeurs dans des cases numérotées. Mais une case peut contenir… <b>une autre liste</b>. On obtient une <b>grille</b> : des lignes, et dans chaque ligne, des cases.</p>
<p>Exemple : le plan de l'amphithéâtre du muséum, deux rangs de trois places.</p>
<div class="rule">$places = [<br>    ['A1', 'A2', 'A3'],   <span class="c">// ligne 0 : le rang A</span><br>    ['B1', 'B2', 'B3'],   <span class="c">// ligne 1 : le rang B</span><br>];</div>
<p><code>$places[1]</code> est <b>toute la ligne 1</b> : la liste <code>['B1', 'B2', 'B3']</code>. Dans cette ligne, on choisit une case avec un <b>deuxième crochet</b> : <code>$places[1][2]</code> vaut <code>'B3'</code>.</p>
<p>Lis l'accès <b>de gauche à droite</b>, comme quand tu cherches ta place : d'abord le rang (le premier crochet), ensuite le siège dans ce rang (le deuxième crochet). Les deux numéros commencent à <b>0</b> : <code>$places[0][0]</code> est la place en haut à gauche.</p>`,
        example: `$places = [
    ['A1', 'A2', 'A3'],
    ['B1', 'B2', 'B3'],
];
echo $places[1][2] . "\\n";
echo $places[0][0];`,
        tryit: 'Affiche A2, puis B1. Ouvre « Pas à pas » pour voir la grille et ses deux lignes.'
      },
      lignecolonne: {
        title: 'Ligne d\'abord, colonne ensuite',
        html: `<p>Une grille a des <b>lignes</b> (de haut en bas) et des <b>colonnes</b> (de gauche à droite). En PHP, l'ordre ne change jamais : <code>$g[$ligne][$colonne]</code>.</p>
<p>Un emploi du temps : une ligne par jour, une colonne par créneau.</p>
<div class="rule">$edt = [<br>    ['Maths', 'Anglais', 'PHP'],   <span class="c">// ligne 0 : lundi</span><br>    ['SQL', 'Sport', 'Réseau'],    <span class="c">// ligne 1 : mardi</span><br>];<br><span class="kw">echo</span> $edt[1][0];   <span class="c">// mardi, 1er créneau : SQL</span></div>
<ul><li><code>count($edt)</code> compte les <b>lignes</b> (ici 2) : c'est le nombre de cases de la grande liste.</li><li><code>count($edt[0])</code> compte les cases de la ligne 0, donc les <b>colonnes</b> (ici 3).</li><li>La dernière ligne est <code>count($edt) - 1</code>, la dernière colonne <code>count($edt[0]) - 1</code>.</li></ul>
<p><b>Le piège n°1 du chapitre</b> : inverser les crochets. <code>$edt[0][1]</code> (lundi, 2e créneau : Anglais) n'est pas <code>$edt[1][0]</code> (mardi, 1er créneau : SQL). Et sur une grille qui n'est pas carrée, l'erreur se voit tout de suite : <code>$edt[2][0]</code> n'existe pas, il n'y a que 2 lignes.</p>`,
        example: `$edt = [
    ['Maths', 'Anglais', 'PHP'],
    ['SQL', 'Sport', 'Réseau'],
];
echo $edt[0][1] . "\\n";
echo $edt[1][0] . "\\n";
echo count($edt) . " lignes, " . count($edt[0]) . " colonnes";`,
        tryit: 'Écris echo $edt[2][0]; et lis l\'avertissement : il n\'y a pas de ligne 2.'
      }
    },
    steps: {
      predire: { before: 'grille', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: `$plateau = [
    ['A', 'B', 'C'],
    ['D', 'E', 'F'],
];
echo $plateau[1][0];`, options: ['D', 'B', 'A', 'E'], hints: ['[1] choisit la deuxième ligne (on compte à partir de 0), puis [0] la première case de cette ligne.'] },
      suivre: { kind: 'trace', ask: 'On descend la colonne 2 du plan : seul le premier crochet change. Remplis $l et $case après chaque passage sur la ligne $case = ….', code: `$col = 2;
for ($l = 0; $l < count($plan); $l++) {
    $case = $plan[$l][$col];
}`, vars: { plan: [['A1', 'A2', 'A3', 'A4'], ['B1', 'B2', 'B3', 'B4'], ['C1', 'C2', 'C3', 'C4']] }, after: 3, watch: ['$l', '$case'], hints: ['$l vaut 0, puis 1, puis 2 : on change de ligne à chaque tour, et on prend toujours la case 2 de la ligne (la troisième).'] },
      viser: { kind: 'aim', ask: 'Touche la case $plateau[2][1].', var: 'plateau', vars: { plateau: [[3, 8, 1, 6], [7, 2, 9, 4], [5, 0, 11, 10]] }, target: '[2][1]', hints: ['Le premier crochet choisit la ligne : [2], la troisième en partant du haut. Le deuxième choisit la case dans cette ligne : [1], la deuxième en partant de la gauche.'] },
      comprendre: {},
      completer: { before: 'lignecolonne', kind: 'complete', ask: 'Affiche la personne assise au rang $r (une ligne du plan) et au siège $s (une colonne).', template: 'echo $plan[[]][[]];', fill: ['[$r]', '[$s]'], words: ['[$r]', '[$s]', '[$r][$s]', '[]', '[0]'], ref: 'echo $plan[$r][$s];', vars: { plan: [['Léa', 'Tom', 'Inès', 'Zoé', 'Hugo'], ['Nora', 'Sami', 'Jade', 'Paul', 'Emma']], r: 1, s: 3 }, hidden: [{ vars: { r: 0, s: 4 }, label: '$r = 0, $s = 4 (le plan a 2 rangs et 5 sièges)' }, { vars: { r: 1, s: 0 } }], hints: ['Ligne d\'abord, colonne ensuite : le rang, puis le siège.', 'echo $plan[$…][$…];'] },
      reparer: { kind: 'repair', ask: 'L\'emploi du temps a une ligne par jour et une colonne par créneau. Ce programme doit afficher le cours du jour $jour au créneau $heure. Il se trompe de case : répare-le.', starter: 'echo $edt[$heure][$jour];', ref: 'echo $edt[$jour][$heure];', vars: { edt: [['Maths', 'Anglais', 'PHP', 'PHP', 'Sport'], ['Réseau', 'Maths', 'Anglais', 'SQL', 'SQL']], jour: 0, heure: 1 }, hidden: [{ vars: { jour: 1, heure: 4 }, label: '$jour = 1, $heure = 4 (2 jours, 5 créneaux)' }, { vars: { jour: 0, heure: 2 } }], traps: ['echo $edt[$jour][1];'], ir: [{ re: /\$edt\s*\[\s*\$heure\s*\]\s*\[\s*\$jour\s*\]/, text: 'Le premier crochet choisit la <b>ligne</b> : ici, un jour. Le deuxième choisit la colonne : le créneau. <code>$edt[$jour][$heure]</code>.' }], hints: ['Une ligne par jour : quel crochet choisit la ligne ?', 'echo $edt[$jour][…];'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nombre de lignes de la grille $g, puis, sur la ligne suivante, son nombre de colonnes.', ref: `echo count($g) . "\\n";
echo count($g[0]);`, vars: { g: [[4, 7, 1], [2, 9, 5], [8, 3, 6]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: 'une grille de 2 lignes et 5 colonnes' }, { vars: { g: [[1, 2, 3, 4]] }, label: 'une seule ligne de 4 cases' }], traps: [`echo count($g) . "\\n";
echo count($g);`], ir: [{ re: /count\s*\(\s*\$g\s*\)[\s\S]*count\s*\(\s*\$g\s*\)/, text: '<code>count($g)</code> compte les lignes. Les colonnes sont les cases d\'une ligne : <code>count($g[0])</code>.' }], hints: ['count($g) compte les cases de la grande liste, c\'est-à-dire les lignes. Pour les colonnes, compte les cases d\'une ligne.', 'echo count($g) . "\\n";\necho count($g[…]);'] },
      ecrire2: { kind: 'write', ask: 'Affiche la case en bas à droite de la grille $g : la dernière ligne, la dernière colonne.', ref: 'echo $g[count($g) - 1][count($g[0]) - 1];', vars: { g: [[4, 7, 1], [2, 9, 5], [8, 3, 6]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: 'une grille de 2 lignes et 5 colonnes' }, { vars: { g: [[11, 12], [13, 14], [15, 16], [17, 18]] }, label: 'une grille de 4 lignes et 2 colonnes' }], traps: ['echo $g[2][2];', 'echo $g[count($g) - 1][count($g) - 1];'], ir: [{ re: /\$g\s*\[\s*2\s*\]\s*\[\s*2\s*\]/, text: 'La grille n\'a pas toujours 3 lignes et 3 colonnes : calcule la dernière ligne avec <code>count($g) - 1</code> et la dernière colonne avec <code>count($g[0]) - 1</code>.' }], hints: ['La dernière ligne porte le numéro count($g) - 1 ; la dernière colonne, count($g[0]) - 1.', '$dl = count($g) - 1;\n$dc = count($g[0]) - 1;\necho $g[$dl][…];'] },
      ecrire3: { kind: 'write', ask: 'La personne du rang $r, siège $s s\'en va : mets « vide » dans sa case du plan. Affiche ensuite tout le rang $r, les noms séparés par un espace.', ref: `$plan[$r][$s] = "vide";
echo implode(" ", $plan[$r]);`, vars: { plan: [['Léa', 'Tom', 'Inès', 'Zoé', 'Hugo'], ['Nora', 'Sami', 'Jade', 'Paul', 'Emma']], r: 1, s: 1 }, hidden: [{ vars: { r: 0, s: 3 }, label: '$r = 0, $s = 3' }, { vars: { r: 1, s: 4 }, label: '$r = 1, $s = 4 (le dernier siège)' }], traps: [`$plan[$s][$r] = "vide";
echo implode(" ", $plan[$r]);`], ir: [{ re: /\$plan\s*\[\s*\$s\s*\]\s*\[\s*\$r\s*\]/, text: 'Le rang est une ligne, le siège une colonne : <code>$plan[$r][$s]</code>, le rang d\'abord.' }], hints: ['On modifie une case comme on la lit : $plan[ligne][colonne] = …; puis implode assemble la ligne $plan[$r].', '$plan[$r][$s] = "vide";\necho implode(" ", $plan[…]);'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  /* ——— N36 · walking through a grid ——— */
  C.level({
    id: 'N36', chapter: 5, num: 36, title: 'Parcourir une grille', summary: 'for dans for · foreach dans foreach · total par ligne', notions: ['parcours2d'],
    lessons: {
      doublefor: {
        title: 'Deux boucles, l\'une dans l\'autre',
        html: `<p>Pour visiter <b>toutes</b> les cases d'une grille, on lit comme dans un livre : la ligne 0 de gauche à droite, puis la ligne 1, et ainsi de suite. Il faut donc <b>deux boucles</b> : une pour les lignes, et, <b>à l'intérieur</b>, une pour les cases de la ligne.</p>
<div class="rule"><span class="kw">for</span> ($l = 0; $l &lt; count($g); $l++) {          <span class="c">// chaque ligne</span><br>    <span class="kw">for</span> ($c = 0; $c &lt; count($g[$l]); $c++) {   <span class="c">// chaque case de la ligne</span><br>        <span class="kw">echo</span> $g[$l][$c] . " ";<br>    }<br>    <span class="kw">echo</span> "\\n";   <span class="c">// la ligne est finie : on passe à la ligne</span><br>}</div>
<p>La boucle intérieure fait <b>tous ses tours</b> pour chaque tour de la boucle extérieure. Avec 2 lignes de 5 cases : $l vaut 0 et $c va de 0 à 4, puis $l vaut 1 et $c repart de 0 jusqu'à 4. Soit 2 × 5 = 10 passages.</p>
<p><b>Le piège</b> : la boucle des colonnes s'arrête à <code>count($g[$l])</code>, le nombre de cases <b>de la ligne</b>. Avec <code>count($g)</code>, elle s'arrête au nombre de lignes : sur une grille carrée on ne voit rien, sur une grille de 2 lignes et 5 colonnes, il manque 3 colonnes.</p>`,
        example: `$g = [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]];
for ($l = 0; $l < count($g); $l++) {
    for ($c = 0; $c < count($g[$l]); $c++) {
        echo $g[$l][$c] . " ";
    }
    echo "\\n";
}`,
        tryit: 'Remplace count($g[$l]) par count($g) : combien de colonnes restent ? Ouvre « Pas à pas » pour suivre $l et $c.'
      },
      foreachdouble: {
        title: 'foreach dans foreach, et où remettre à zéro',
        html: `<p>Avec <code>foreach</code>, plus besoin de numéros : chaque tour de la boucle extérieure donne <b>une ligne entière</b> (une liste), et la boucle intérieure parcourt cette liste.</p>
<div class="rule">$total = 0;                  <span class="c">// total général : avant les DEUX boucles</span><br><span class="kw">foreach</span> ($g <span class="kw">as</span> $ligne) {<br>    $somme = 0;              <span class="c">// total de la ligne : remis à 0 à chaque ligne</span><br>    <span class="kw">foreach</span> ($ligne <span class="kw">as</span> $case) {<br>        $somme = $somme + $case;<br>        $total = $total + $case;<br>    }<br>    <span class="kw">echo</span> $somme . "\\n";      <span class="c">// après la boucle intérieure</span><br>}<br><span class="kw">echo</span> "Total : " . $total;</div>
<p>Retiens bien <b>la place de chaque ligne</b> :</p>
<ul><li>ce qui compte <b>toute la grille</b> se prépare avant les deux boucles et s'affiche après les deux ;</li><li>ce qui compte <b>une ligne</b> se remet à zéro au début de la boucle extérieure et s'affiche à sa fin, après la boucle intérieure.</li></ul>
<p>Si <code>$somme = 0;</code> est placé avant les deux boucles, la ligne 1 ajoute ses cases au total de la ligne 0 : les totaux grossissent au lieu de recommencer.</p>`,
        example: `$g = [[2, 4, 6], [1, 1, 1]];
$total = 0;
foreach ($g as $ligne) {
    $somme = 0;
    foreach ($ligne as $case) {
        $somme = $somme + $case;
        $total = $total + $case;
    }
    echo $somme . "\\n";
}
echo "Total : " . $total;`,
        tryit: 'Déplace $somme = 0; tout en haut, avant le premier foreach : que deviennent les totaux ?'
      }
    },
    steps: {
      predire: { before: 'doublefor', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: `$g = [[1, 2, 3], [4, 5, 6]];
for ($l = 0; $l < count($g); $l++) {
    for ($c = 0; $c < count($g[$l]); $c++) {
        echo $g[$l][$c] . " ";
    }
    echo "\\n";
}`, options: ['1 2 3\n4 5 6', '1 4\n2 5\n3 6', '1 2\n4 5', '6 5 4\n3 2 1'], hints: ['La boucle extérieure choisit la ligne, la boucle intérieure parcourt toute cette ligne avant de passer à la suivante.'] },
      suivre: { kind: 'trace', ask: 'Remplis le tableau : $l, $c et $total après chaque passage sur la ligne $total = … de la boucle intérieure.', code: `$total = 0;
for ($l = 0; $l < count($g); $l++) {
    for ($c = 0; $c < count($g[$l]); $c++) {
        $total = $total + $g[$l][$c];
    }
}
echo $total;`, vars: { g: [[5, 2, 1], [3, 4, 6]] }, after: 4, watch: ['$l', '$c', '$total'], hints: ['Pour $l = 0, $c vaut 0, 1, 2 ; puis $l passe à 1 et $c repart de 0. $total n\'est jamais remis à zéro.'] },
      viser: { kind: 'aimw', ask: 'Écris l\'accès à la case allumée de la grille $g (par exemple $g[0][2]).', var: 'g', vars: { g: [[12, 7, 30, 4, 18], [9, 25, 3, 16, 21]] }, target: '[1][3]', hints: ['Compte la ligne à partir de 0 en partant du haut, puis la case à partir de 0 en partant de la gauche : $g[ligne][colonne].'] },
      comprendre: {},
      completer: { before: 'foreachdouble', kind: 'complete', ask: 'Affiche le total de toutes les cases de la grille $g.', template: `$total = 0;
foreach ($g as [[]]) {
    foreach ([[]] as $case) {
        $total = $total + [[]];
    }
}
echo $total;`, fill: ['$ligne', '$ligne', '$case'], words: ['$ligne', '$case', '$g', '$total', '$g[$ligne]'], ref: `$total = 0;
foreach ($g as $ligne) {
    foreach ($ligne as $case) {
        $total = $total + $case;
    }
}
echo $total;`, vars: { g: [[2, 4, 6], [1, 1, 1], [10, 0, 5]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: 'une grille de 2 lignes et 5 colonnes' }, { vars: { g: [] }, label: 'une grille vide' }, { vars: { g: [[7]] }, label: 'une seule case' }], hints: ['La boucle extérieure donne une ligne, la boucle intérieure parcourt cette ligne case par case.', 'foreach ($g as $ligne) {\n    foreach ($ligne as $case) { … }\n}'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher le total de chaque ligne, un par ligne. Les totaux grossissent d\'une ligne à l\'autre : répare-le.', starter: `$somme = 0;
foreach ($g as $ligne) {
    foreach ($ligne as $case) {
        $somme = $somme + $case;
    }
    echo $somme . "\\n";
}`, ref: `foreach ($g as $ligne) {
    $somme = 0;
    foreach ($ligne as $case) {
        $somme = $somme + $case;
    }
    echo $somme . "\\n";
}`, vars: { g: [[2, 4, 6], [1, 1, 1], [10, 0, 5]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: 'une grille de 2 lignes et 5 colonnes' }, { vars: { g: [[3, 3], [0, 0]] } }], traps: [`for ($l = 0; $l < count($g); $l++) {
    $somme = 0;
    for ($c = 0; $c < count($g); $c++) {
        $somme = $somme + $g[$l][$c];
    }
    echo $somme . "\\n";
}`], ir: [{ re: /\$c\s*<\s*count\s*\(\s*\$g\s*\)/, text: 'La boucle des colonnes doit s\'arrêter au nombre de cases <b>de la ligne</b> : <code>count($g[$l])</code>. <code>count($g)</code> est le nombre de lignes.' }], hints: ['Le total d\'une ligne doit repartir de 0 au début de chaque ligne : où faut-il placer $somme = 0; ?', 'foreach ($g as $ligne) {\n    $somme = 0;\n    foreach … \n    echo $somme . "\\n";\n}'] },
      ecrire1: { kind: 'write', ask: 'Affiche la grille $g ligne par ligne : une ligne de la grille par ligne affichée, les cases séparées par un espace.', ref: `foreach ($g as $ligne) {
    echo implode(" ", $ligne) . "\\n";
}`, vars: { g: [['A', 'B', 'C'], ['D', 'E', 'F'], ['G', 'H', 'I']] }, hidden: [{ vars: { g: [['a', 'b', 'c', 'd', 'e'], ['f', 'g', 'h', 'i', 'j']] }, label: 'une grille de 2 lignes et 5 colonnes' }, { vars: { g: [['X']] }, label: 'une seule case' }], traps: [`for ($l = 0; $l < count($g); $l++) {
    for ($c = 0; $c < count($g); $c++) {
        echo $g[$l][$c] . " ";
    }
    echo "\\n";
}`], ir: [{ re: /\$c\s*<\s*count\s*\(\s*\$g\s*\)/, text: 'Les colonnes s\'arrêtent à <code>count($g[$l])</code>, le nombre de cases de la ligne, pas à <code>count($g)</code>.' }], hints: ['Deux boucles : une par ligne, une par case de la ligne ; le retour à la ligne s\'écrit après la boucle intérieure. (implode(" ", $ligne) assemble une ligne d\'un coup.)', 'foreach ($g as $ligne) {\n    foreach ($ligne as $case) {\n        echo $case . " ";\n    }\n    echo "\\n";\n}'] },
      ecrire2: { kind: 'write', ask: 'Bataille navale : la grille $g contient « X » pour une case touchée et « . » pour l\'eau. Affiche le nombre de cases touchées.', ref: `$n = 0;
foreach ($g as $ligne) {
    foreach ($ligne as $case) {
        if ($case == "X") {
            $n++;
        }
    }
}
echo $n;`, vars: { g: [['X', '.', '.'], ['.', 'X', 'X'], ['.', '.', '.']] }, hidden: [{ vars: { g: [['.', '.', '.', '.', 'X'], ['X', '.', '.', 'X', 'X']] }, label: 'une grille de 2 lignes et 5 colonnes' }, { vars: { g: [['.', '.'], ['.', '.']] }, label: 'aucune case touchée' }], traps: [`$n = 0;
for ($l = 0; $l < count($g); $l++) {
    for ($c = 0; $c < count($g); $c++) {
        if ($g[$l][$c] == "X") {
            $n++;
        }
    }
}
echo $n;`], ir: [{ re: /\$c\s*<\s*count\s*\(\s*\$g\s*\)/, text: 'Les colonnes s\'arrêtent à <code>count($g[$l])</code>, pas à <code>count($g)</code> (le nombre de lignes).' }], hints: ['Un compteur préparé avant les deux boucles, augmenté de 1 quand la case vaut "X".', '$n = 0;\nforeach ($g as $ligne) {\n    foreach ($ligne as $case) {\n        if (…) { $n++; }\n    }\n}\necho $n;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le total de chaque ligne de $g, un par ligne, puis une dernière ligne « Total : » suivi du total de toute la grille.', ref: `$total = 0;
foreach ($g as $ligne) {
    $somme = 0;
    foreach ($ligne as $case) {
        $somme = $somme + $case;
    }
    echo $somme . "\\n";
    $total = $total + $somme;
}
echo "Total : " . $total;`, vars: { g: [[2, 4, 6], [1, 1, 1], [10, 0, 5]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: 'une grille de 2 lignes et 5 colonnes' }, { vars: { g: [[0, 0], [5, 5]] } }], traps: [`$total = 0;
for ($l = 0; $l < count($g); $l++) {
    $somme = 0;
    for ($c = 0; $c < count($g); $c++) {
        $somme = $somme + $g[$l][$c];
    }
    echo $somme . "\\n";
    $total = $total + $somme;
}
echo "Total : " . $total;`], ir: [{ re: /\$c\s*<\s*count\s*\(\s*\$g\s*\)/, text: 'Les colonnes s\'arrêtent à <code>count($g[$l])</code>, pas à <code>count($g)</code>.' }], hints: ['Deux totaux : celui de la ligne, remis à 0 à chaque ligne, et celui de la grille, préparé une seule fois avant les deux boucles.', '$total = 0;\nforeach ($g as $ligne) {\n    $somme = 0;\n    …\n    echo $somme . "\\n";\n    $total = $total + $somme;\n}\necho "Total : " . $total;'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  C.mastery({
    id: 'M15', chapter: 5, title: 'Grilles', covers: ['N35', 'N36'], draw: 3,
    pool: [
      { ask: 'Affiche la case de la ligne $l et de la colonne $c de la grille $g.', ref: 'echo $g[$l][$c];', vars: { g: [[4, 7, 1], [2, 9, 5], [8, 3, 6]], l: 1, c: 1 }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]], l: 0, c: 4 }, label: '2 lignes, 5 colonnes, $l = 0, $c = 4' }, { vars: { l: 2, c: 0 } }], traps: ['echo $g[$c][$l];'], hints: ['Ligne d\'abord, colonne ensuite.', 'echo $g[$…][$…];'] },
      { ask: 'Affiche le nombre de cases de la grille $g (lignes × colonnes).', ref: 'echo count($g) * count($g[0]);', vars: { g: [[4, 7, 1], [2, 9, 5], [8, 3, 6]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: '2 lignes, 5 colonnes' }, { vars: { g: [[1], [2], [3], [4]] }, label: '4 lignes, 1 colonne' }], traps: ['echo count($g) * count($g);'], hints: ['count($g) compte les lignes, count($g[0]) les colonnes.', 'echo count($g) * count($g[…]);'] },
      { ask: 'Affiche la dernière ligne de la grille $g, les cases séparées par un espace.', ref: 'echo implode(" ", $g[count($g) - 1]);', vars: { g: [[4, 7, 1], [2, 9, 5], [8, 3, 6]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: '2 lignes seulement' }, { vars: { g: [[1, 2], [3, 4], [5, 6], [7, 8]] }, label: '4 lignes' }], traps: ['echo implode(" ", $g[2]);'], hints: ['La dernière ligne porte le numéro count($g) - 1 ; implode l\'assemble.', 'echo implode(" ", $g[count($g) - 1]);'] },
      { ask: 'Affiche le total de la colonne $c de la grille $g (une case par ligne, toujours à la colonne $c).', ref: `$total = 0;
foreach ($g as $ligne) {
    $total = $total + $ligne[$c];
}
echo $total;`, vars: { g: [[1, 2, 3], [2, 5, 6], [3, 6, 9]], c: 1 }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]], c: 3 }, label: '2 lignes, 5 colonnes, $c = 3' }, { vars: { c: 0 } }], traps: [`$total = 0;
foreach ($g[$c] as $case) {
    $total = $total + $case;
}
echo $total;`], ir: [{ re: /foreach\s*\(\s*\$g\s*\[\s*\$c\s*\]/, text: '<code>$g[$c]</code> est une <b>ligne</b> entière. Une colonne, c\'est une case dans chaque ligne : <code>$ligne[$c]</code>.' }], hints: ['Une colonne se lit en descendant : une boucle sur les lignes, et dans chaque ligne la case $c.', '$total = 0;\nforeach ($g as $ligne) {\n    $total = $total + $ligne[…];\n}\necho $total;'] },
      { ask: 'Affiche les quatre coins de la grille $g, un par ligne : en haut à gauche, en haut à droite, en bas à gauche, en bas à droite.', ref: `$dl = count($g) - 1;
$dc = count($g[0]) - 1;
echo $g[0][0] . "\\n";
echo $g[0][$dc] . "\\n";
echo $g[$dl][0] . "\\n";
echo $g[$dl][$dc];`, vars: { g: [[4, 7, 1], [2, 9, 5], [8, 3, 6]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: '2 lignes, 5 colonnes' }, { vars: { g: [[1, 2], [3, 4], [5, 6], [7, 8]] }, label: '4 lignes, 2 colonnes' }], traps: [`$dl = count($g) - 1;
$dc = count($g) - 1;
echo $g[0][0] . "\\n";
echo $g[0][$dc] . "\\n";
echo $g[$dl][0] . "\\n";
echo $g[$dl][$dc];`], hints: ['Calcule d\'abord la dernière ligne (count($g) - 1) et la dernière colonne (count($g[0]) - 1).', '$dl = count($g) - 1;\n$dc = count($g[0]) - 1;\necho $g[0][0] . "\\n";\n…'] },
      { ask: 'Affiche le total de toutes les cases de la grille $g.', ref: `$total = 0;
foreach ($g as $ligne) {
    foreach ($ligne as $case) {
        $total = $total + $case;
    }
}
echo $total;`, vars: { g: [[4, 7, 1], [2, 9, 5], [8, 3, 6]] }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]] }, label: '2 lignes, 5 colonnes' }, { vars: { g: [] }, label: 'une grille vide' }], traps: [`$total = 0;
for ($l = 0; $l < count($g); $l++) {
    for ($c = 0; $c < count($g); $c++) {
        $total = $total + $g[$l][$c];
    }
}
echo $total;`], hints: ['Deux boucles imbriquées, un total préparé avant les deux.', '$total = 0;\nforeach ($g as $ligne) {\n    foreach ($ligne as $case) { … }\n}\necho $total;'] },
      { ask: 'La grille $t contient des relevés de température (une ligne par jour, une colonne par heure). Affiche la plus haute température.', ref: `$max = $t[0][0];
foreach ($t as $ligne) {
    foreach ($ligne as $v) {
        if ($v > $max) {
            $max = $v;
        }
    }
}
echo $max;`, vars: { t: [[12, 15, 9], [14, 18, 11]] }, hidden: [{ vars: { t: [[-5, -2, -8, -1, -3], [-6, -4, -9, -7, -2]] }, label: 'des températures toutes négatives' }, { vars: { t: [[3], [7], [5]] } }], traps: [`$max = 0;
foreach ($t as $ligne) {
    foreach ($ligne as $v) {
        if ($v > $max) {
            $max = $v;
        }
    }
}
echo $max;`], ir: [{ re: /\$max\s*=\s*0\s*;/, text: 'Si toutes les températures sont négatives, <code>$max = 0</code> n\'est jamais dépassé. Pars d\'une vraie case : <code>$t[0][0]</code>.' }], hints: ['Le maximum part d\'une vraie case de la grille, $t[0][0], puis on visite toutes les cases.', '$max = $t[0][0];\nforeach ($t as $ligne) {\n    foreach ($ligne as $v) {\n        if ($v > $max) { … }\n    }\n}\necho $max;'] },
      { ask: 'Affiche le numéro (à partir de 0) de la ligne de $g dont le total est le plus grand. En cas d\'égalité, la première.', ref: `$best = 0;
$bestTotal = array_sum($g[0]);
for ($l = 1; $l < count($g); $l++) {
    if (array_sum($g[$l]) > $bestTotal) {
        $best = $l;
        $bestTotal = array_sum($g[$l]);
    }
}
echo $best;`, vars: { g: [[1, 2, 3], [9, 0, 4], [2, 2, 2]] }, hidden: [{ vars: { g: [[5, 5, 0, 0, 0], [2, 2, 2, 2, 2]] }, label: 'deux lignes au même total' }, { vars: { g: [[1, 1], [0, 0], [3, 4]] } }], traps: [`$best = 0;
$bestTotal = array_sum($g[0]);
for ($l = 1; $l < count($g); $l++) {
    if (array_sum($g[$l]) >= $bestTotal) {
        $best = $l;
        $bestTotal = array_sum($g[$l]);
    }
}
echo $best;`], hints: ['Garde le numéro de la meilleure ligne et son total ; array_sum($g[$l]) donne le total d\'une ligne. Avec > strict, la première reste en cas d\'égalité.', '$best = 0;\n$bestTotal = array_sum($g[0]);\nfor ($l = 1; $l < count($g); $l++) {\n    if (array_sum($g[$l]) > $bestTotal) { … }\n}\necho $best;'] },
      { ask: 'Mets « X » dans la case de la ligne $l, colonne $c de la grille $g, puis affiche la grille, une ligne par ligne, les cases collées.', ref: `$g[$l][$c] = "X";
foreach ($g as $ligne) {
    echo implode("", $ligne) . "\\n";
}`, vars: { g: [['.', '.', '.'], ['.', '.', '.'], ['.', '.', '.']], l: 2, c: 2 }, hidden: [{ vars: { g: [['.', '.', '.', '.', '.'], ['.', '.', '.', '.', '.']], l: 0, c: 3 }, label: '2 lignes, 5 colonnes, $l = 0, $c = 3' }, { vars: { l: 1, c: 0 } }], traps: [`$g[$c][$l] = "X";
foreach ($g as $ligne) {
    echo implode("", $ligne) . "\\n";
}`], hints: ['On écrit dans une case comme on la lit : $g[ligne][colonne] = "X";', '$g[$l][$c] = "X";\nforeach ($g as $ligne) {\n    echo implode("", $ligne) . "\\n";\n}'] },
      { ask: 'Affiche combien de cases de la grille $g valent $v.', ref: `$n = 0;
foreach ($g as $ligne) {
    foreach ($ligne as $case) {
        if ($case == $v) {
            $n++;
        }
    }
}
echo $n;`, vars: { g: [[1, 0, 1], [0, 1, 1], [1, 0, 0]], v: 1 }, hidden: [{ vars: { g: [[1, 0, 1, 1, 1], [0, 0, 0, 1, 1]] }, label: '2 lignes, 5 colonnes' }, { vars: { v: 7 }, label: '$v = 7 (aucune case)' }], traps: [`$n = 0;
for ($l = 0; $l < count($g); $l++) {
    for ($c = 0; $c < count($g); $c++) {
        if ($g[$l][$c] == $v) {
            $n++;
        }
    }
}
echo $n;`], hints: ['Un compteur, deux boucles, un if.', '$n = 0;\nforeach ($g as $ligne) {\n    foreach ($ligne as $case) {\n        if ($case == $v) { $n++; }\n    }\n}\necho $n;'] }
    ]
  });

  C.flash({
    id: 'E13', chapter: 5, title: 'Grilles', covers: ['N35', 'N36'],
    pool: [
      { code: 'echo $g[1][0];', vars: { g: [[4, 7], [2, 9]] }, options: ['2', '7', '4', '9'] },
      { code: 'echo count($g);', vars: { g: [[1, 2, 3], [4, 5, 6]] }, options: ['2', '3', '6', '1'] },
      { code: 'echo count($g[0]);', vars: { g: [[1, 2, 3], [4, 5, 6]] }, options: ['3', '2', '6', '1'] },
      { code: 'echo $g[0][2] + $g[1][0];', vars: { g: [[1, 2, 3], [4, 5, 6]] }, options: ['7', '5', '6', '9'] },
      { code: `for ($c = 0; $c < 2; $c++) {
    for ($l = 0; $l < 2; $l++) {
        echo $g[$l][$c];
    }
    echo " ";
}`, vars: { g: [[1, 2], [3, 4]] }, options: ['13 24', '12 34', '1234', '31 42'] },
      { code: `$t = 0;
foreach ($g as $ligne) {
    foreach ($ligne as $v) {
        $t = $t + $v;
    }
}
echo $t;`, vars: { g: [[1, 2, 3], [4, 5, 6]] }, options: ['21', '6', '15', '9'] },
      { code: `foreach ($g as $ligne) {
    $s = 0;
    foreach ($ligne as $v) {
        $s = $s + $v;
    }
    echo $s . " ";
}`, vars: { g: [[1, 2, 3], [4, 5, 6]] }, options: ['6 15', '6 21', '21', '5 7 9'] },
      { code: `$s = 0;
foreach ($g as $ligne) {
    foreach ($ligne as $v) {
        $s = $s + $v;
    }
    echo $s . " ";
}`, vars: { g: [[1, 2, 3], [4, 5, 6]] }, options: ['6 21', '6 15', '21', '15'] },
      { code: `$n = 0;
for ($l = 0; $l < 2; $l++) {
    for ($c = 0; $c < 5; $c++) {
        $n++;
    }
}
echo $n;`, options: ['10', '7', '5', '12'] },
      { code: `$g[1][] = 7;
echo count($g[1]);`, vars: { g: [[1, 2], [3, 4]] }, options: ['3', '2', '7', '5'] },
      { code: `$g[0] = [9, 9];
echo $g[0][1] . $g[1][1];`, vars: { g: [[1, 2], [3, 4]] }, options: ['94', '92', '24', '99'] },
      { code: 'echo implode("-", $g[1]);', vars: { g: [['a', 'b'], ['c', 'd']] }, options: ['c-d', 'a-b', 'b-d', 'a-c'] },
      { code: 'echo $g[count($g) - 1][0];', vars: { g: [[5, 6], [7, 8], [9, 1]] }, options: ['9', '7', '1', '5'] },
      { code: `$n = 0;
foreach ($g as $ligne) {
    foreach ($ligne as $v) {
        if ($v > 4) {
            $n++;
        }
    }
}
echo $n;`, vars: { g: [[3, 8, 4], [5, 1, 9]] }, options: ['3', '4', '2', '6'] }
    ]
  });
  /* ——— N37 · a list of records ——— */
  C.level({
    id: 'N37', chapter: 5, num: 37, title: 'Une liste d\'enregistrements', summary: '$eleves[1][\'nom\'] · foreach ($eleves as $e) · $e[\'note\']', notions: ['enregistrements'],
    lessons: {
      fiche: {
        title: 'Une fiche par élève',
        html: `<p>Au chapitre 4, un tableau associatif rangeait des informations sous des noms : c'est une <b>fiche</b>.</p>
<div class="rule">$eleve = ['nom' => 'Léa', 'note' => 14];<br><span class="kw">echo</span> $eleve['nom'];   <span class="c">// Léa</span></div>
<p>Une classe, c'est <b>une liste de fiches</b> : une case par élève, et dans chaque case, une fiche entière.</p>
<div class="rule">$eleves = [<br>    ['nom' => 'Léa', 'note' => 14],    <span class="c">// case 0</span><br>    ['nom' => 'Tom', 'note' => 9],     <span class="c">// case 1</span><br>    ['nom' => 'Inès', 'note' => 17],   <span class="c">// case 2</span><br>];<br><span class="kw">echo</span> $eleves[1]['nom'];   <span class="c">// Tom</span></div>
<p>Lis l'accès <b>de gauche à droite</b> :</p>
<ul><li><code>$eleves</code> : toute la classe ;</li><li><code>$eleves[1]</code> : la fiche de Tom, toute la fiche (un tableau) ;</li><li><code>$eleves[1]['nom']</code> : dans la fiche de Tom, la case <code>'nom'</code>.</li></ul>
<p>Le premier crochet est un <b>numéro</b> (quel élève, à partir de 0), le deuxième une <b>clé entre guillemets</b> (quelle information). C'est une grille dont les colonnes portent des noms.</p>`,
        example: `$eleves = [
    ['nom' => 'Léa', 'note' => 14],
    ['nom' => 'Tom', 'note' => 9],
    ['nom' => 'Inès', 'note' => 17],
];
echo $eleves[1]['nom'] . " a " . $eleves[1]['note'];`,
        tryit: 'Affiche la note d\'Inès. Puis écris echo $eleves[1]; et lis l\'avertissement : une fiche n\'est pas un texte.'
      },
      foreachfiche: {
        title: 'Parcourir les fiches : foreach',
        html: `<p>Pour traiter tous les élèves, on parcourt la liste. À chaque tour, <code>$e</code> reçoit <b>une fiche entière</b> :</p>
<div class="rule"><span class="kw">foreach</span> ($eleves <span class="kw">as</span> $e) {<br>    <span class="kw">echo</span> $e['nom'] . " : " . $e['note'] . "\\n";<br>}</div>
<p>Au premier tour, <code>$e</code> est la fiche de Léa, donc <code>$e['nom']</code> vaut « Léa ». Au deuxième tour, c'est la fiche de Tom. Dans la boucle, plus de numéro : <code>$e</code> remplace <code>$eleves[$i]</code>.</p>
<p>Les trois erreurs les plus fréquentes :</p>
<ul><li><code>echo $e;</code> affiche « Array » et un avertissement : <code>$e</code> est une fiche, pas un texte. Choisis une case : <code>$e['nom']</code>.</li><li><code>$e[nom]</code> sans guillemets : PHP cherche une constante « nom » et s'arrête sur une erreur. Une clé s'écrit <b>entre guillemets</b>.</li><li><code>$eleves['note']</code> : la liste n'a que des cases 0, 1, 2… La clé <code>'note'</code> est dans chaque fiche, pas dans la liste.</li></ul>`,
        example: `$eleves = [
    ['nom' => 'Léa', 'note' => 14],
    ['nom' => 'Tom', 'note' => 9],
];
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`,
        tryit: 'Enlève les guillemets autour de nom et lis l\'erreur. Remplace $e[\'nom\'] par $e : que s\'affiche-t-il ?'
      }
    },
    steps: {
      predire: { before: 'fiche', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: `$eleves = [
    ['nom' => 'Léa', 'note' => 14],
    ['nom' => 'Tom', 'note' => 9],
];
echo $eleves[1]['nom'];`, options: ['Tom', 'Léa', '9', 'Array'], hints: ['[1] choisit la deuxième fiche (on compte à partir de 0), puis [\'nom\'] la case nom de cette fiche.'] },
      suivre: { before: 'foreachfiche', kind: 'trace', ask: 'À chaque tour, $e est une fiche entière. Remplis $nom et $total après chaque passage sur la ligne $total = ….', code: `$total = 0;
foreach ($eleves as $e) {
    $nom = $e['nom'];
    $total = $total + $e['note'];
}
echo $total;`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, after: 4, watch: ['$nom', '$total'], hints: ['Premier tour : $e est la fiche de Léa, donc $nom vaut Léa et on ajoute 14 au total.'] },
      viser: { kind: 'aim', ask: 'Touche la case $eleves[2][\'note\'].', var: 'eleves', vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }, { nom: 'Zoé', note: 11 }] }, target: "[2]['note']", hints: ['[2] : la troisième fiche en partant du haut. Puis, dans cette fiche, la case note.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche chaque élève sous la forme « Léa : 14 », un par ligne.', template: `foreach ($eleves as [[]]) {
    echo [[]] . " : " . [[]] . "\\n";
}`, fill: ['$e', "$e['nom']", "$e['note']"], words: ['$e', "$e['nom']", "$e['note']", '$eleves', '$e[nom]', "$eleves['nom']"], ref: `foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 20 }] }, label: 'un seul élève' }, { vars: { eleves: [] }, label: 'une classe vide' }, { vars: { eleves: [{ nom: 'Nora', note: 8 }, { nom: 'Sami', note: 12 }] } }], hints: ['$e reçoit une fiche ; on lit ses cases avec une clé entre guillemets.', 'foreach ($eleves as $e) {\n    echo $e[\'nom\'] . " : " . $e[…] . "\\n";\n}'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher le nom de chaque élève, un par ligne. PHP s\'arrête sur une erreur : répare-le.', starter: `foreach ($eleves as $e) {
    echo $e[nom] . "\\n";
}`, ref: `foreach ($eleves as $e) {
    echo $e['nom'] . "\\n";
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 20 }] }, label: 'un seul élève' }, { vars: { eleves: [{ nom: 'Nora', note: 8 }, { nom: 'Sami', note: 12 }, { nom: 'Jade', note: 15 }, { nom: 'Paul', note: 10 }, { nom: 'Emma', note: 13 }] }, label: 'cinq élèves' }], traps: [`for ($i = 0; $i < 3; $i++) {
    echo $eleves[$i]['nom'] . "\\n";
}`], ir: [{ re: /\$e\s*\[\s*nom\s*\]/, text: 'Une clé s\'écrit entre guillemets : <code>$e[\'nom\']</code>. Sans guillemets, PHP cherche une constante qui s\'appellerait nom.' }, { re: /<\s*3\s*;/, text: 'La classe n\'a pas toujours 3 élèves : <code>foreach</code> (ou <code>count($eleves)</code>) s\'adapte à la liste.' }], hints: ['Lis l\'erreur : PHP ne sait pas ce qu\'est « nom ». Comment s\'écrit une clé ?', 'echo $e[\'nom\'] . "\\n";'] },
      ecrire1: { kind: 'write', ask: 'Affiche la fiche de l\'élève numéro $i sur trois lignes : « Nom : Tom », « Note : 9 », « Absences : 5 ».', ref: `echo "Nom : " . $eleves[$i]['nom'] . "\\n";
echo "Note : " . $eleves[$i]['note'] . "\\n";
echo "Absences : " . $eleves[$i]['absences'];`, vars: { eleves: [{ nom: 'Léa', note: 14, absences: 2 }, { nom: 'Tom', note: 9, absences: 5 }, { nom: 'Inès', note: 17, absences: 0 }, { nom: 'Zoé', note: 11, absences: 1 }], i: 1 }, hidden: [{ vars: { i: 0 }, label: '$i = 0 (la première fiche)' }, { vars: { i: 3 }, label: '$i = 3 (la dernière fiche)' }], traps: [`echo "Nom : " . $eleves[1]['nom'] . "\\n";
echo "Note : " . $eleves[1]['note'] . "\\n";
echo "Absences : " . $eleves[1]['absences'];`], ir: [{ re: /\$eleves\s*\[\s*1\s*\]/, text: 'Le numéro de l\'élève est dans <code>$i</code> : <code>$eleves[$i][\'nom\']</code>.' }], hints: ['Le numéro de la fiche est $i ; dans la fiche, les clés nom, note et absences.', 'echo "Nom : " . $eleves[$i][\'nom\'] . "\\n";\necho "Note : " . …;'] },
      ecrire2: { kind: 'write', ask: 'Affiche chaque spécimen de la collection sous la forme « Trilobite (1911) », un par ligne.', ref: `foreach ($specimens as $s) {
    echo $s['nom'] . " (" . $s['annee'] . ")\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', annee: 1911 }, { nom: 'Loup gris', annee: 1954 }, { nom: 'Ammonite', annee: 1898 }, { nom: 'Aigle royal', annee: 1987 }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', annee: 2003 }, { nom: 'Quartz', annee: 1930 }] }, label: 'deux spécimens seulement' }, { vars: { specimens: [{ nom: 'Dodo', annee: 1880 }, { nom: 'Lynx', annee: 2003 }, { nom: 'Quartz', annee: 1930 }, { nom: 'Mammouth', annee: 1902 }, { nom: 'Opale', annee: 1975 }, { nom: 'Hibou', annee: 2011 }] }, label: 'six spécimens' }], traps: [`for ($i = 0; $i < 4; $i++) {
    echo $specimens[$i]['nom'] . " (" . $specimens[$i]['annee'] . ")\\n";
}`], ir: [{ out: function (o) { return /Array/.test(o); }, text: 'Tu affiches une fiche entière : PHP n\'écrit que « Array ». Choisis une case : <code>$s[\'nom\']</code>.' }], hints: ['foreach donne chaque fiche ; affiche ses cases nom et annee.', 'foreach ($specimens as $s) {\n    echo $s[\'nom\'] . " (" . … . ")\\n";\n}'] },
      ecrire3: { kind: 'write', ask: 'Affiche « Premier : » suivi du nom du premier spécimen de la liste, puis, sur la ligne suivante, « Dernier : » suivi du nom du dernier.', ref: `echo "Premier : " . $specimens[0]['nom'] . "\\n";
echo "Dernier : " . $specimens[count($specimens) - 1]['nom'];`, vars: { specimens: [{ nom: 'Trilobite', annee: 1911 }, { nom: 'Loup gris', annee: 1954 }, { nom: 'Ammonite', annee: 1898 }, { nom: 'Aigle royal', annee: 1987 }] }, hidden: [{ vars: { specimens: [{ nom: 'Quartz', annee: 1930 }] }, label: 'un seul spécimen' }, { vars: { specimens: [{ nom: 'Dodo', annee: 1880 }, { nom: 'Lynx', annee: 2003 }, { nom: 'Quartz', annee: 1930 }, { nom: 'Mammouth', annee: 1902 }, { nom: 'Opale', annee: 1975 }, { nom: 'Hibou', annee: 2011 }] }, label: 'six spécimens' }], traps: [`echo "Premier : " . $specimens[0]['nom'] . "\\n";
echo "Dernier : " . $specimens[3]['nom'];`], hints: ['La dernière fiche porte le numéro count($specimens) - 1.', 'echo "Premier : " . $specimens[0][\'nom\'] . "\\n";\necho "Dernier : " . $specimens[count($specimens) - 1][…];'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  /* ——— N38 · filtering and totalling records ——— */
  C.level({
    id: 'N38', chapter: 5, num: 38, title: 'Filtrer et totaliser des enregistrements', summary: 'if ($s[\'salle\'] == …) · total d\'une case · moyenne', notions: ['filtrer2d'],
    lessons: {
      filtre: {
        title: 'Garder certaines fiches',
        html: `<p>Souvent, on ne veut pas toutes les fiches, mais seulement <b>celles qui remplissent une condition</b> : les spécimens de la salle R1, les élèves qui ont la moyenne… On parcourt la liste et on met un <code>if</code> sur <b>une case de la fiche</b> :</p>
<div class="rule"><span class="kw">foreach</span> ($specimens <span class="kw">as</span> $s) {<br>    <span class="kw">if</span> ($s['salle'] == 'R1') {<br>        <span class="kw">echo</span> $s['nom'] . "\\n";<br>    }<br>}</div>
<p>Le test porte sur <code>$s['salle']</code>, une case précise de la fiche, jamais sur <code>$s</code> tout entier. Et ce qu'on affiche est une autre case de la même fiche : <code>$s['nom']</code>.</p>
<p>Pour dire « Aucun » quand rien ne correspond, il faut un <b>compteur</b> : on compte les fiches gardées, et c'est <b>après la boucle</b> qu'on regarde s'il vaut 0. Un <code>else</code> dans la boucle afficherait « Aucun » à chaque fiche qui ne correspond pas.</p>`,
        example: `$specimens = [
    ['nom' => 'Trilobite', 'salle' => 'R1'],
    ['nom' => 'Loup gris', 'salle' => 'R3'],
    ['nom' => 'Ammonite', 'salle' => 'R1'],
];
$n = 0;
foreach ($specimens as $s) {
    if ($s['salle'] == 'R1') {
        echo $s['nom'] . "\\n";
        $n++;
    }
}
if ($n == 0) {
    echo "Aucun";
}`,
        tryit: 'Remplace R1 par R5 : seul « Aucun » s\'affiche, une seule fois.'
      },
      somme: {
        title: 'Additionner une case des fiches gardées',
        html: `<p>Pour additionner une information des fiches gardées (la valeur des spécimens d'une salle, les notes des élèves sans absence), on prépare un total <b>avant</b> la boucle et on lui ajoute <b>la case</b> qui nous intéresse :</p>
<div class="rule">$total = 0;<br>$n = 0;<br><span class="kw">foreach</span> ($specimens <span class="kw">as</span> $s) {<br>    <span class="kw">if</span> ($s['salle'] == $salle) {<br>        $total = $total + $s['valeur'];<br>        $n++;<br>    }<br>}</div>
<p>Pour une <b>moyenne</b>, on divise par le nombre de fiches <b>gardées</b>, <code>$n</code>, et pas par <code>count($specimens)</code>, qui compte toute la collection.</p>
<p>Et si <code>$n</code> vaut 0, diviser est interdit (« Division by zero ») : on teste d'abord.</p>
<div class="rule"><span class="kw">if</span> ($n == 0) {<br>    <span class="kw">echo</span> "Aucun";<br>} <span class="kw">else</span> {<br>    <span class="kw">echo</span> round($total / $n, 1);<br>}</div>`,
        example: `$total = 0;
$n = 0;
foreach ($specimens as $s) {
    if ($s['salle'] == $salle) {
        $total = $total + $s['valeur'];
        $n++;
    }
}
if ($n == 0) {
    echo "Aucun";
} else {
    echo round($total / $n, 1);
}`,
        vars: { specimens: [{ salle: 'R1', valeur: 800 }, { salle: 'R3', valeur: 2500 }, { salle: 'R1', valeur: 450 }], salle: 'R1' },
        tryit: 'Divise par count($specimens) au lieu de $n : la moyenne baisse, parce que le spécimen de R3 est compté.'
      }
    },
    steps: {
      predire: { before: 'filtre', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: `foreach ($eleves as $e) {
    if ($e['note'] >= 10) {
        echo $e['nom'] . " ";
    }
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 10 }, { nom: 'Zoé', note: 8 }] }, options: ['Léa Inès', 'Léa', 'Tom Zoé', 'Léa Tom Inès Zoé'], hints: ['Regarde la note de chaque fiche : >= 10 garde aussi la note 10.'] },
      suivre: { kind: 'trace', ask: 'Seuls les spécimens de la salle R1 comptent. Remplis $n et $total à la fin de chaque tour de la boucle, après le if.', code: `$n = 0;
$total = 0;
foreach ($specimens as $s) {
    if ($s['salle'] == 'R1') {
        $n++;
        $total = $total + $s['valeur'];
    }
}`, vars: { specimens: [{ salle: 'R1', valeur: 800 }, { salle: 'R3', valeur: 2500 }, { salle: 'R1', valeur: 450 }, { salle: 'R2', valeur: 1200 }] }, after: 7, watch: ['$n', '$total'], hints: ['Quand la salle n\'est pas R1, rien ne change : $n et $total gardent leur valeur.'] },
      viser: { kind: 'aimw', ask: 'Écris l\'accès à la case allumée (par exemple $specimens[0][\'nom\']).', var: 'specimens', vars: { specimens: [{ nom: 'Trilobite', valeur: 800 }, { nom: 'Loup gris', valeur: 2500 }, { nom: 'Ammonite', valeur: 450 }] }, target: "[1]['valeur']", hints: ['D\'abord le numéro de la fiche (à partir de 0), puis la clé de la case, entre guillemets.'] },
      comprendre: {},
      completer: { before: 'somme', kind: 'complete', ask: 'Affiche la valeur totale des spécimens de la salle $salle.', template: `$total = 0;
foreach ($specimens as $s) {
    if ([[]] == $salle) {
        $total = [[]] + [[]];
    }
}
echo $total;`, fill: ["$s['salle']", '$total', "$s['valeur']"], words: ["$s['salle']", '$s', '$total', "$s['valeur']", "$specimens['salle']", '$salle'], ref: `$total = 0;
foreach ($specimens as $s) {
    if ($s['salle'] == $salle) {
        $total = $total + $s['valeur'];
    }
}
echo $total;`, vars: { specimens: [{ salle: 'R1', valeur: 800 }, { salle: 'R3', valeur: 2500 }, { salle: 'R1', valeur: 450 }, { salle: 'R2', valeur: 1200 }], salle: 'R1' }, hidden: [{ vars: { salle: 'R4' }, label: '$salle = \'R4\' (aucun spécimen)' }, { vars: { salle: 'R2' } }], hints: ['On compare la case salle de la fiche, et on ajoute sa case valeur au total.', 'if ($s[\'salle\'] == $salle) {\n    $total = $total + $s[…];\n}'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher la moyenne des notes des élèves qui n\'ont aucune absence, arrondie à 1 décimale. S\'il n\'y en a aucun, il doit afficher « Aucun ». La moyenne est trop basse : répare-le.', starter: `$total = 0;
$n = 0;
foreach ($eleves as $e) {
    if ($e['absences'] == 0) {
        $total = $total + $e['note'];
        $n++;
    }
}
echo round($total / count($eleves), 1);`, ref: `$total = 0;
$n = 0;
foreach ($eleves as $e) {
    if ($e['absences'] == 0) {
        $total = $total + $e['note'];
        $n++;
    }
}
if ($n == 0) {
    echo "Aucun";
} else {
    echo round($total / $n, 1);
}`, vars: { eleves: [{ note: 14, absences: 0 }, { note: 9, absences: 3 }, { note: 16, absences: 0 }, { note: 11, absences: 1 }] }, hidden: [{ vars: { eleves: [{ note: 12, absences: 2 }, { note: 8, absences: 1 }] }, label: 'aucun élève sans absence' }, { vars: { eleves: [{ note: 13, absences: 0 }] }, label: 'un seul élève' }], traps: [`$total = 0;
$n = 0;
foreach ($eleves as $e) {
    if ($e['absences'] == 0) {
        $total = $total + $e['note'];
        $n++;
    }
}
echo round($total / $n, 1);`], ir: [{ re: /\/\s*count\s*\(\s*\$eleves\s*\)/, text: '<code>count($eleves)</code> compte toute la classe, absents compris. On divise par le nombre d\'élèves gardés : <code>$n</code>.' }], hints: ['Divise par le nombre d\'élèves gardés, et teste d\'abord s\'il vaut 0.', 'if ($n == 0) {\n    echo "Aucun";\n} else {\n    echo round($total / $n, 1);\n}'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nom des spécimens entrés au muséum avant l\'année $annee (strictement), un par ligne. S\'il n\'y en a aucun, affiche « Aucun ».', ref: `$n = 0;
foreach ($specimens as $s) {
    if ($s['annee'] < $annee) {
        echo $s['nom'] . "\\n";
        $n++;
    }
}
if ($n == 0) {
    echo "Aucun";
}`, vars: { specimens: [{ nom: 'Trilobite', annee: 1911 }, { nom: 'Loup gris', annee: 1954 }, { nom: 'Ammonite', annee: 1898 }, { nom: 'Aigle royal', annee: 1987 }], annee: 1950 }, hidden: [{ vars: { annee: 1954 }, label: '$annee = 1954 (le loup est entré cette année-là)' }, { vars: { annee: 1800 }, label: '$annee = 1800 (aucun spécimen)' }], traps: [`foreach ($specimens as $s) {
    if ($s['annee'] <= $annee) {
        echo $s['nom'] . "\\n";
    }
}`, `foreach ($specimens as $s) {
    if ($s['annee'] < $annee) {
        echo $s['nom'] . "\\n";
    }
}`], ir: [{ re: /<=\s*\$annee/, text: '« Avant 1954 » ne garde pas 1954 : c\'est <code>&lt;</code>, pas <code>&lt;=</code>.' }], hints: ['Un if sur la case annee, et un compteur pour savoir après la boucle si rien n\'a été affiché.', '$n = 0;\nforeach ($specimens as $s) {\n    if ($s[\'annee\'] < $annee) { … }\n}\nif ($n == 0) { echo "Aucun"; }'] },
      ecrire2: { kind: 'write', ask: 'Affiche la valeur totale des spécimens de la salle $salle qui valent au moins 1000 €.', ref: `$total = 0;
foreach ($specimens as $s) {
    if ($s['salle'] == $salle && $s['valeur'] >= 1000) {
        $total = $total + $s['valeur'];
    }
}
echo $total;`, vars: { specimens: [{ salle: 'R1', valeur: 800 }, { salle: 'R1', valeur: 2500 }, { salle: 'R2', valeur: 1200 }, { salle: 'R1', valeur: 1500 }], salle: 'R1' }, hidden: [{ vars: { specimens: [{ salle: 'R1', valeur: 1000 }, { salle: 'R1', valeur: 999 }, { salle: 'R2', valeur: 5000 }] }, label: 'un spécimen à 1000 € pile' }, { vars: { salle: 'R2' } }], traps: [`$total = 0;
foreach ($specimens as $s) {
    if ($s['salle'] == $salle && $s['valeur'] > 1000) {
        $total = $total + $s['valeur'];
    }
}
echo $total;`], ir: [{ re: />\s*1000/, text: '« Au moins 1000 » garde 1000 : c\'est <code>&gt;=</code>.' }, { re: /\|\|/, text: 'Les deux conditions doivent être vraies en même temps : <code>&amp;&amp;</code>.' }], hints: ['Deux conditions sur la même fiche, reliées par &&.', '$total = 0;\nforeach ($specimens as $s) {\n    if ($s[\'salle\'] == $salle && $s[\'valeur\'] >= 1000) { … }\n}\necho $total;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom des élèves qui ont au moins 3 absences, un par ligne, puis une dernière ligne « 2 élève(s) » avec leur nombre.', ref: `$n = 0;
foreach ($eleves as $e) {
    if ($e['absences'] >= 3) {
        echo $e['nom'] . "\\n";
        $n++;
    }
}
echo $n . " élève(s)";`, vars: { eleves: [{ nom: 'Léa', absences: 1 }, { nom: 'Tom', absences: 5 }, { nom: 'Inès', absences: 2 }, { nom: 'Zoé', absences: 4 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', absences: 3 }, { nom: 'Nora', absences: 0 }] }, label: 'Hugo a 3 absences pile' }, { vars: { eleves: [{ nom: 'Sami', absences: 0 }] }, label: 'personne' }], traps: [`$n = 0;
foreach ($eleves as $e) {
    if ($e['absences'] > 3) {
        echo $e['nom'] . "\\n";
        $n++;
    }
}
echo $n . " élève(s)";`], ir: [{ re: /count\s*\(\s*\$eleves\s*\)/, text: '<code>count($eleves)</code> compte toute la classe. Compte toi-même les élèves gardés, dans le if.' }], hints: ['Un compteur augmenté dans le if, affiché après la boucle.', '$n = 0;\nforeach ($eleves as $e) {\n    if ($e[\'absences\'] >= 3) { …; $n++; }\n}\necho $n . " élève(s)";'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  /* ——— N39 · the best, the worst ——— */
  C.level({
    id: 'N39', chapter: 5, num: 39, title: 'Le meilleur, le pire', summary: 'garder la fiche entière · ou son indice · ex æquo', notions: ['meilleur'],
    lessons: {
      garderfiche: {
        title: 'Garder la fiche entière',
        html: `<p>Au chapitre 3, on cherchait le plus grand nombre. Avec des fiches, on veut savoir <b>qui</b> : le nom de l'élève qui a la meilleure note, le spécimen le plus précieux. Si on ne garde que la note, le nom est perdu.</p>
<p>La méthode : garder <b>la fiche entière</b> du meilleur, en partant de <b>la première fiche</b> de la liste.</p>
<div class="rule">$meilleur = $eleves[0];             <span class="c">// une vraie fiche pour commencer</span><br><span class="kw">foreach</span> ($eleves <span class="kw">as</span> $e) {<br>    <span class="kw">if</span> ($e['note'] > $meilleur['note']) {<br>        $meilleur = $e;               <span class="c">// toute la fiche</span><br>    }<br>}<br><span class="kw">echo</span> $meilleur['nom'] . " : " . $meilleur['note'];</div>
<ul><li>On <b>compare les notes</b> (<code>$e['note'] > $meilleur['note']</code>), mais on <b>garde toute la fiche</b> (<code>$meilleur = $e</code>). Après la boucle, on a le nom ET la note.</li><li>On part de <code>$eleves[0]</code>, jamais d'une fausse fiche avec 0 : si on cherche le plus petit, ou si toutes les valeurs valent 0, la fausse fiche gagnerait.</li><li>Si la liste est vide, <code>$eleves[0]</code> n'existe pas : on teste <code>count($eleves) == 0</code> d'abord.</li></ul>`,
        example: `$eleves = [
    ['nom' => 'Léa', 'note' => 12],
    ['nom' => 'Tom', 'note' => 16],
    ['nom' => 'Inès', 'note' => 9],
];
$meilleur = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] > $meilleur['note']) {
        $meilleur = $e;
    }
}
echo $meilleur['nom'] . " : " . $meilleur['note'];`,
        tryit: 'Cherche le pire : remplace > par &lt; et renomme $meilleur en $pire.'
      },
      indice: {
        title: 'Garder l\'indice, et les ex æquo',
        html: `<p>Au lieu de la fiche, on peut garder son <b>numéro</b> dans la liste : <code>$iMax</code>. On compare alors <code>$eleves[$i]['note']</code> à <code>$eleves[$iMax]['note']</code>.</p>
<div class="rule">$iMax = 0;<br><span class="kw">for</span> ($i = 1; $i &lt; count($eleves); $i++) {<br>    <span class="kw">if</span> ($eleves[$i]['note'] > $eleves[$iMax]['note']) {<br>        $iMax = $i;<br>    }<br>}<br><span class="kw">echo</span> $eleves[$iMax]['nom'];</div>
<p><b>Les ex æquo</b> : avec <code>></code>, un élève qui a <b>la même</b> note que le meilleur ne le remplace pas : c'est donc <b>le premier</b> de la liste qui reste. Avec <code>>=</code>, c'est le dernier. Lis bien la consigne.</p>
<p>Pour afficher <b>tous</b> les ex æquo, il faut deux boucles : la première trouve la meilleure note, la seconde affiche chaque élève qui a cette note.</p>`,
        example: `$eleves = [
    ['nom' => 'Léa', 'note' => 12],
    ['nom' => 'Tom', 'note' => 16],
    ['nom' => 'Zoé', 'note' => 16],
];
$iMax = 0;
for ($i = 1; $i < count($eleves); $i++) {
    if ($eleves[$i]['note'] > $eleves[$iMax]['note']) {
        $iMax = $i;
    }
}
echo $eleves[$iMax]['nom'];`,
        tryit: 'Remplace > par >= : c\'est Zoé qui l\'emporte.'
      }
    },
    steps: {
      predire: { before: 'garderfiche', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: `$meilleur = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] > $meilleur['note']) {
        $meilleur = $e;
    }
}
echo $meilleur['nom'];`, vars: { eleves: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }] }, options: ['Tom', '16', 'Inès', 'Léa'], hints: ['$meilleur garde toute la fiche du meilleur ; à la fin, on affiche sa case nom.'] },
      suivre: { before: 'indice', kind: 'trace', ask: 'Remplis $i et $iMax à la fin de chaque tour de la boucle for, après le if. Attention à Zoé, qui a la même note que Tom.', code: `$iMax = 0;
for ($i = 1; $i < count($eleves); $i++) {
    if ($eleves[$i]['note'] > $eleves[$iMax]['note']) {
        $iMax = $i;
    }
}
echo $eleves[$iMax]['nom'];`, vars: { eleves: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }, { nom: 'Zoé', note: 16 }] }, after: 5, watch: ['$i', '$iMax'], hints: ['16 > 16 est faux : Zoé ne remplace pas Tom.'] },
      viser: { kind: 'aim', ask: '$iMax vaut 2. Touche la case $eleves[$iMax][\'nom\'].', var: 'eleves', vars: { eleves: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }, { nom: 'Zoé', note: 11 }] }, target: "[2]['nom']", hints: ['$eleves[$iMax] est la fiche numéro 2, la troisième ; dans cette fiche, la case nom.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le nom de l\'élève qui a la moins bonne note (le premier de la liste en cas d\'égalité).', template: `$pire = [[]];
foreach ($eleves as $e) {
    if ($e['note'] [[]] $pire['note']) {
        $pire = [[]];
    }
}
echo $pire['nom'];`, fill: ['$eleves[0]', '<', '$e'], words: ['$eleves[0]', '0', '<', '>', '$e', "$e['note']", '$eleves'], ref: `$pire = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] < $pire['note']) {
        $pire = $e;
    }
}
echo $pire['nom'];`, vars: { eleves: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }, { nom: 'Zoé', note: 11 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 15 }] }, label: 'un seul élève' }, { vars: { eleves: [{ nom: 'Nora', note: 8 }, { nom: 'Sami', note: 13 }, { nom: 'Jade', note: 8 }] }, label: 'deux élèves ex æquo' }], hints: ['On part de la première fiche, on garde toute la fiche d\'un élève qui fait moins bien.', '$pire = $eleves[0];\n… if ($e[\'note\'] < $pire[\'note\']) { $pire = $e; }'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher le nom de l\'élève qui a la meilleure note (le premier de la liste en cas d\'égalité). Il affiche une note : répare-le.', starter: `$max = 0;
foreach ($eleves as $e) {
    if ($e['note'] > $max) {
        $max = $e['note'];
    }
}
echo $max;`, ref: `$meilleur = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] > $meilleur['note']) {
        $meilleur = $e;
    }
}
echo $meilleur['nom'];`, vars: { eleves: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }] }, hidden: [{ vars: { eleves: [{ nom: 'Nora', note: 15 }, { nom: 'Sami', note: 18 }, { nom: 'Jade', note: 18 }] }, label: 'Sami et Jade ex æquo' }, { vars: { eleves: [{ nom: 'Hugo', note: 7 }] }, label: 'un seul élève' }], traps: [`$meilleur = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] >= $meilleur['note']) {
        $meilleur = $e;
    }
}
echo $meilleur['nom'];`], ir: [{ re: /\$max\s*=\s*\$e\s*\[\s*'note'\s*\]/, text: 'En gardant seulement la note, tu perds le nom. Garde toute la fiche : <code>$meilleur = $e;</code>' }, { re: />=\s*\$meilleur/, text: 'Avec <code>&gt;=</code>, un ex æquo remplace le premier. La consigne demande le premier : <code>&gt;</code>.' }], hints: ['Garde la fiche entière du meilleur, en partant de $eleves[0], et compare les notes.', '$meilleur = $eleves[0];\nforeach ($eleves as $e) {\n    if ($e[\'note\'] > $meilleur[\'note\']) { $meilleur = $e; }\n}\necho $meilleur[…];'] },
      ecrire1: { kind: 'write', ask: 'Affiche le spécimen le plus précieux sous la forme « Mammouth : 12000 € » (le premier en cas d\'égalité). Si la collection est vide, affiche « Aucun spécimen ».', ref: `if (count($specimens) == 0) {
    echo "Aucun spécimen";
} else {
    $best = $specimens[0];
    foreach ($specimens as $s) {
        if ($s['valeur'] > $best['valeur']) {
            $best = $s;
        }
    }
    echo $best['nom'] . " : " . $best['valeur'] . " €";
}`, vars: { specimens: [{ nom: 'Trilobite', valeur: 800 }, { nom: 'Mammouth', valeur: 12000 }, { nom: 'Ammonite', valeur: 450 }, { nom: 'Aigle royal', valeur: 1200 }] }, hidden: [{ vars: { specimens: [] }, label: 'une collection vide' }, { vars: { specimens: [{ nom: 'Lynx', valeur: 3000 }, { nom: 'Quartz', valeur: 150 }, { nom: 'Dodo', valeur: 3000 }] }, label: 'deux spécimens ex æquo' }], traps: [`$best = $specimens[0];
foreach ($specimens as $s) {
    if ($s['valeur'] > $best['valeur']) {
        $best = $s;
    }
}
echo $best['nom'] . " : " . $best['valeur'] . " €";`, `if (count($specimens) == 0) {
    echo "Aucun spécimen";
} else {
    $best = $specimens[0];
    foreach ($specimens as $s) {
        if ($s['valeur'] >= $best['valeur']) {
            $best = $s;
        }
    }
    echo $best['nom'] . " : " . $best['valeur'] . " €";
}`], hints: ['D\'abord le cas de la liste vide ; sinon, la fiche entière du meilleur, en partant de la première.', 'if (count($specimens) == 0) {\n    echo "Aucun spécimen";\n} else {\n    $best = $specimens[0];\n    …\n}'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nom du spécimen le plus ancien, celui qui a la plus petite année d\'entrée (le premier en cas d\'égalité).', ref: `$vieux = $specimens[0];
foreach ($specimens as $s) {
    if ($s['annee'] < $vieux['annee']) {
        $vieux = $s;
    }
}
echo $vieux['nom'];`, vars: { specimens: [{ nom: 'Trilobite', annee: 1911 }, { nom: 'Loup gris', annee: 1954 }, { nom: 'Ammonite', annee: 1898 }, { nom: 'Aigle royal', annee: 1987 }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', annee: 2003 }, { nom: 'Dodo', annee: 1880 }, { nom: 'Quartz', annee: 1880 }] }, label: 'deux spécimens entrés la même année' }, { vars: { specimens: [{ nom: 'Opale', annee: 1975 }] }, label: 'un seul spécimen' }], traps: [`$vieux = $specimens[0];
foreach ($specimens as $s) {
    if ($s['annee'] <= $vieux['annee']) {
        $vieux = $s;
    }
}
echo $vieux['nom'];`], ir: [{ re: /\$\w+\s*=\s*\[\s*'nom'\s*=>\s*''/, text: 'Une fausse fiche de départ fausse le résultat : pars de la première vraie fiche, <code>$specimens[0]</code>.' }], hints: ['Le plus ancien a la plus petite année : on garde la fiche quand on trouve une année plus petite.', '$vieux = $specimens[0];\nforeach ($specimens as $s) {\n    if ($s[\'annee\'] < $vieux[\'annee\']) { … }\n}\necho $vieux[\'nom\'];'] },
      ecrire3: { kind: 'write', ask: 'Affiche le nom de tous les élèves qui ont la meilleure note de la classe (s\'il y a des ex æquo, tous), un par ligne.', ref: `$max = $eleves[0]['note'];
foreach ($eleves as $e) {
    if ($e['note'] > $max) {
        $max = $e['note'];
    }
}
foreach ($eleves as $e) {
    if ($e['note'] == $max) {
        echo $e['nom'] . "\\n";
    }
}`, vars: { eleves: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }] }, hidden: [{ vars: { eleves: [{ nom: 'Nora', note: 15 }, { nom: 'Sami', note: 18 }, { nom: 'Jade', note: 18 }, { nom: 'Paul', note: 18 }] }, label: 'trois élèves ex æquo' }, { vars: { eleves: [{ nom: 'Hugo', note: 0 }] }, label: 'un seul élève, qui a 0' }], traps: [`$meilleur = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] > $meilleur['note']) {
        $meilleur = $e;
    }
}
echo $meilleur['nom'];`], hints: ['Deux boucles : la première trouve la meilleure note, la seconde affiche chaque élève qui a exactement cette note.', '$max = $eleves[0][\'note\'];\nforeach … { if ($e[\'note\'] > $max) { … } }\nforeach ($eleves as $e) {\n    if ($e[\'note\'] == $max) { echo … ; }\n}'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  C.mastery({
    id: 'M16', chapter: 5, title: 'Enregistrements', covers: ['N37', 'N38', 'N39'], draw: 3,
    pool: [
      { ask: 'Affiche chaque élève sous la forme « Léa : 14 », un par ligne.', ref: `foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 20 }] }, label: 'un seul élève' }, { vars: { eleves: [{ nom: 'Nora', note: 8 }, { nom: 'Sami', note: 12 }, { nom: 'Jade', note: 15 }, { nom: 'Paul', note: 10 }, { nom: 'Emma', note: 13 }] }, label: 'cinq élèves' }], traps: [`for ($i = 0; $i < 3; $i++) {
    echo $eleves[$i]['nom'] . " : " . $eleves[$i]['note'] . "\\n";
}`], hints: ['foreach donne chaque fiche ; affiche ses cases nom et note.', 'foreach ($eleves as $e) {\n    echo $e[\'nom\'] . " : " . … . "\\n";\n}'] },
      { ask: 'Affiche la note de l\'élève numéro $i.', ref: 'echo $eleves[$i][\'note\'];', vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }, { nom: 'Zoé', note: 11 }], i: 2 }, hidden: [{ vars: { i: 0 } }, { vars: { i: 3 } }], traps: ['echo $eleves[2][\'note\'];'], hints: ['Le numéro de la fiche est $i, la clé est note.', 'echo $eleves[$i][…];'] },
      { ask: 'Affiche le nom des élèves qui ont la moyenne (au moins 10), un par ligne.', ref: `foreach ($eleves as $e) {
    if ($e['note'] >= 10) {
        echo $e['nom'] . "\\n";
    }
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 12 }, { nom: 'Zoé', note: 8 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 10 }, { nom: 'Nora', note: 9 }] }, label: 'Hugo a 10 pile' }, { vars: { eleves: [] }, label: 'une classe vide' }], traps: [`foreach ($eleves as $e) {
    if ($e['note'] > 10) {
        echo $e['nom'] . "\\n";
    }
}`], hints: ['Un if sur la case note de chaque fiche.', 'foreach ($eleves as $e) {\n    if ($e[\'note\'] >= 10) { … }\n}'] },
      { ask: 'Affiche la valeur moyenne des spécimens de la salle $salle, arrondie à l\'euro (round). S\'il n\'y en a aucun, affiche « Aucun ».', ref: `$total = 0;
$n = 0;
foreach ($specimens as $s) {
    if ($s['salle'] == $salle) {
        $total = $total + $s['valeur'];
        $n++;
    }
}
if ($n == 0) {
    echo "Aucun";
} else {
    echo round($total / $n);
}`, vars: { specimens: [{ salle: 'R1', valeur: 800 }, { salle: 'R2', valeur: 1200 }, { salle: 'R1', valeur: 450 }, { salle: 'R1', valeur: 1000 }], salle: 'R1' }, hidden: [{ vars: { salle: 'R5' }, label: '$salle = \'R5\' (aucun spécimen)' }, { vars: { salle: 'R2' } }], traps: [`$total = 0;
$n = 0;
foreach ($specimens as $s) {
    if ($s['salle'] == $salle) {
        $total = $total + $s['valeur'];
        $n++;
    }
}
echo round($total / $n);`], hints: ['Un total et un compteur pour les fiches gardées ; on teste $n == 0 avant de diviser.', '… if ($n == 0) {\n    echo "Aucun";\n} else {\n    echo round($total / $n);\n}'] },
      { ask: 'Affiche le nombre d\'élèves qui ont au moins $seuil absences.', ref: `$n = 0;
foreach ($eleves as $e) {
    if ($e['absences'] >= $seuil) {
        $n++;
    }
}
echo $n;`, vars: { eleves: [{ nom: 'Léa', absences: 1 }, { nom: 'Tom', absences: 5 }, { nom: 'Inès', absences: 2 }, { nom: 'Zoé', absences: 4 }], seuil: 3 }, hidden: [{ vars: { seuil: 4 }, label: '$seuil = 4 (Zoé a 4 absences pile)' }, { vars: { seuil: 6 } }], traps: [`$n = 0;
foreach ($eleves as $e) {
    if ($e['absences'] > $seuil) {
        $n++;
    }
}
echo $n;`], hints: ['Un compteur, un if sur la case absences.', '$n = 0;\nforeach ($eleves as $e) {\n    if ($e[\'absences\'] >= $seuil) { $n++; }\n}\necho $n;'] },
      { ask: 'Affiche le nom de l\'élève qui a la meilleure note (le premier de la liste en cas d\'égalité).', ref: `$best = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] > $best['note']) {
        $best = $e;
    }
}
echo $best['nom'];`, vars: { eleves: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }] }, hidden: [{ vars: { eleves: [{ nom: 'Nora', note: 18 }, { nom: 'Sami', note: 11 }, { nom: 'Jade', note: 18 }] }, label: 'Nora et Jade ex æquo' }, { vars: { eleves: [{ nom: 'Hugo', note: 5 }] }, label: 'un seul élève' }], traps: [`$best = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] >= $best['note']) {
        $best = $e;
    }
}
echo $best['nom'];`], hints: ['Garde la fiche entière du meilleur, en partant de la première.', '$best = $eleves[0];\nforeach ($eleves as $e) {\n    if ($e[\'note\'] > $best[\'note\']) { $best = $e; }\n}\necho $best[\'nom\'];'] },
      { ask: 'Affiche le nom du spécimen le moins précieux (le premier en cas d\'égalité).', ref: `$min = $specimens[0];
foreach ($specimens as $s) {
    if ($s['valeur'] < $min['valeur']) {
        $min = $s;
    }
}
echo $min['nom'];`, vars: { specimens: [{ nom: 'Trilobite', valeur: 800 }, { nom: 'Loup gris', valeur: 2500 }, { nom: 'Ammonite', valeur: 450 }] }, hidden: [{ vars: { specimens: [{ nom: 'Quartz', valeur: 150 }, { nom: 'Lynx', valeur: 3000 }, { nom: 'Opale', valeur: 150 }] }, label: 'deux spécimens ex æquo' }, { vars: { specimens: [{ nom: 'Dodo', valeur: 9000 }] }, label: 'un seul spécimen' }], traps: [`$min = $specimens[0];
foreach ($specimens as $s) {
    if ($s['valeur'] <= $min['valeur']) {
        $min = $s;
    }
}
echo $min['nom'];`], hints: ['Comme pour le meilleur, avec &lt; : on part de la première fiche.', '$min = $specimens[0];\nforeach ($specimens as $s) {\n    if ($s[\'valeur\'] < $min[\'valeur\']) { $min = $s; }\n}\necho $min[\'nom\'];'] },
      { ask: 'Affiche la moyenne de la classe arrondie à 1 décimale. Si la classe est vide, affiche « Aucun élève ».', ref: `if (count($eleves) == 0) {
    echo "Aucun élève";
} else {
    $total = 0;
    foreach ($eleves as $e) {
        $total = $total + $e['note'];
    }
    echo round($total / count($eleves), 1);
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, hidden: [{ vars: { eleves: [] }, label: 'une classe vide' }, { vars: { eleves: [{ nom: 'Hugo', note: 11 }, { nom: 'Nora', note: 12 }] } }], traps: [`$total = 0;
foreach ($eleves as $e) {
    $total = $total + $e['note'];
}
echo round($total / count($eleves), 1);`], hints: ['Le total des cases note, divisé par le nombre d\'élèves ; la classe vide d\'abord.', 'if (count($eleves) == 0) {\n    echo "Aucun élève";\n} else {\n    …\n}'] },
      { ask: 'Affiche le nom des élèves qui ont strictement plus que la moyenne de la classe, un par ligne.', ref: `$total = 0;
foreach ($eleves as $e) {
    $total = $total + $e['note'];
}
$moyenne = $total / count($eleves);
foreach ($eleves as $e) {
    if ($e['note'] > $moyenne) {
        echo $e['nom'] . "\\n";
    }
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 8 }, { nom: 'Inès', note: 11 }, { nom: 'Zoé', note: 7 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 15 }, { nom: 'Nora', note: 12 }, { nom: 'Sami', note: 12 }] }, label: 'une classe qui a 13 de moyenne' }, { vars: { eleves: [{ nom: 'Jade', note: 9 }, { nom: 'Paul', note: 5 }] }, label: 'une classe qui a 7 de moyenne' }], traps: [`foreach ($eleves as $e) {
    if ($e['note'] > 10) {
        echo $e['nom'] . "\\n";
    }
}`], hints: ['Deux boucles : la première calcule la moyenne, la seconde compare chaque note à cette moyenne.', '$total = 0;\nforeach … \n$moyenne = $total / count($eleves);\nforeach ($eleves as $e) {\n    if ($e[\'note\'] > $moyenne) { … }\n}'] },
      { ask: 'Affiche le dernier de la classe sous la forme « Tom : 9 » (la plus petite note, le premier en cas d\'égalité). Si la classe est vide, affiche « Aucun élève ».', ref: `if (count($eleves) == 0) {
    echo "Aucun élève";
} else {
    $pire = $eleves[0];
    foreach ($eleves as $e) {
        if ($e['note'] < $pire['note']) {
            $pire = $e;
        }
    }
    echo $pire['nom'] . " : " . $pire['note'];
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, hidden: [{ vars: { eleves: [] }, label: 'une classe vide' }, { vars: { eleves: [{ nom: 'Hugo', note: 6 }, { nom: 'Nora', note: 6 }] }, label: 'deux élèves ex æquo' }], traps: [`$pire = $eleves[0];
foreach ($eleves as $e) {
    if ($e['note'] < $pire['note']) {
        $pire = $e;
    }
}
echo $pire['nom'] . " : " . $pire['note'];`], hints: ['La classe vide d\'abord ; sinon, la fiche entière du pire, en partant de la première.', 'if (count($eleves) == 0) {\n    echo "Aucun élève";\n} else {\n    $pire = $eleves[0];\n    …\n}'] }
    ]
  });

  C.flash({
    id: 'E14', chapter: 5, title: 'Enregistrements', covers: ['N37', 'N38', 'N39'],
    pool: [
      { code: 'echo $e[1][\'nom\'];', vars: { e: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }] }, options: ['Tom', 'Léa', '9', '14'] },
      { code: 'echo $e[0][\'note\'] + $e[1][\'note\'];', vars: { e: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }] }, options: ['23', '14', '149', '9'] },
      { code: 'echo count($e);', vars: { e: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, options: ['3', '2', '6', '1'] },
      { code: 'echo count($e[0]);', vars: { e: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, options: ['2', '3', '6', '1'] },
      { code: 'echo $e[count($e) - 1][\'nom\'];', vars: { e: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, options: ['Inès', 'Tom', 'Léa', '17'] },
      { code: `foreach ($e as $x) {
    if ($x['note'] >= 10) {
        echo $x['nom'] . " ";
    }
}`, vars: { e: [{ nom: 'Léa', note: 10 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, options: ['Léa Inès', 'Inès', 'Tom', 'Léa Tom Inès'] },
      { code: `$n = 0;
foreach ($e as $x) {
    if ($x['abs'] == 0) {
        $n++;
    }
}
echo $n;`, vars: { e: [{ nom: 'Léa', abs: 0 }, { nom: 'Tom', abs: 2 }, { nom: 'Inès', abs: 0 }] }, options: ['2', '1', '3', '0'] },
      { code: `$t = 0;
foreach ($e as $x) {
    $t = $t + $x['note'];
}
echo $t / count($e);`, vars: { e: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 8 }, { nom: 'Inès', note: 17 }] }, options: ['13', '39', '3', '14'] },
      { code: `$m = $e[0];
foreach ($e as $x) {
    if ($x['note'] > $m['note']) {
        $m = $x;
    }
}
echo $m['nom'];`, vars: { e: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Zoé', note: 16 }] }, options: ['Tom', 'Zoé', 'Léa', '16'] },
      { code: `$m = $e[0];
foreach ($e as $x) {
    if ($x['note'] >= $m['note']) {
        $m = $x;
    }
}
echo $m['nom'];`, vars: { e: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Zoé', note: 16 }] }, options: ['Zoé', 'Tom', 'Léa', '16'] },
      { code: `$max = 0;
foreach ($e as $x) {
    if ($x['note'] > $max) {
        $max = $x['note'];
    }
}
echo $max;`, vars: { e: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }] }, options: ['16', 'Tom', '1', '12'] },
      { code: `$k = 0;
for ($i = 1; $i < count($e); $i++) {
    if ($e[$i]['note'] < $e[$k]['note']) {
        $k = $i;
    }
}
echo $k;`, vars: { e: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }] }, options: ['2', '3', 'Inès', '9'] },
      { code: `foreach ($e as $i => $x) {
    echo $i . ":" . $x['nom'] . " ";
}`, vars: { e: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }] }, options: ['0:Léa 1:Tom', '1:Léa 2:Tom', 'Léa:0 Tom:1', '0:12 1:16'] },
      { code: `$t = 0;
foreach ($s as $x) {
    if ($x['salle'] == 'R1') {
        $t = $t + $x['valeur'];
    }
}
echo $t;`, vars: { s: [{ salle: 'R1', valeur: 800 }, { salle: 'R2', valeur: 1200 }, { salle: 'R1', valeur: 450 }] }, options: ['1250', '2450', '800', '450'] }
    ]
  });
  /* ——— N40 · a list inside a record ——— */
  C.level({
    id: 'N40', chapter: 5, num: 40, title: 'Un tableau dans une clé', summary: '$classe[1][\'notes\'][2] · count($e[\'notes\']) · moyenne de chaque élève', notions: ['cletableau'],
    lessons: {
      listedansfiche: {
        title: 'Une liste dans une fiche',
        html: `<p>Une case de fiche peut contenir une <b>liste</b>. Par exemple, toutes les notes d'un élève :</p>
<div class="rule">$eleve = ['nom' => 'Léa', 'notes' => [12, 15, 9]];</div>
<ul><li><code>$eleve['notes']</code> est la liste <code>[12, 15, 9]</code> ;</li><li><code>$eleve['notes'][1]</code> est la case 1 de cette liste : 15 ;</li><li><code>count($eleve['notes'])</code> est le nombre de notes : 3 ;</li><li><code>foreach ($eleve['notes'] as $n)</code> parcourt les notes une par une.</li></ul>
<p>Pour une classe entière, on arrive à <b>trois crochets</b> :</p>
<div class="rule">$classe = [<br>    ['nom' => 'Léa', 'notes' => [12, 15, 9]],<br>    ['nom' => 'Tom', 'notes' => [8, 14]],<br>];<br><span class="kw">echo</span> $classe[1]['notes'][0];   <span class="c">// 8</span></div>
<p>Lis-les de gauche à droite, un crochet à la fois, comme on ouvre des boîtes rangées les unes dans les autres : <code>$classe[1]</code> la fiche de Tom → <code>['notes']</code> sa liste de notes → <code>[0]</code> la première de ces notes.</p>
<p>Attention : la liste peut être <b>vide</b> (un élève qui n'a encore aucune note). Alors <code>$eleve['notes'][0]</code> n'existe pas, et <code>count($eleve['notes'])</code> vaut 0.</p>`,
        example: `$classe = [
    ['nom' => 'Léa', 'notes' => [12, 15, 9]],
    ['nom' => 'Tom', 'notes' => [8, 14]],
];
echo $classe[1]['notes'][0] . "\\n";
echo count($classe[0]['notes']) . "\\n";
echo implode(" ", $classe[0]['notes']);`,
        tryit: 'Affiche la dernière note de Léa. Puis écris echo $classe[1][\'notes\']; : il manque un crochet, PHP écrit « Array ».'
      },
      moyenneeleve: {
        title: 'La moyenne de chaque élève',
        html: `<p>Pour calculer la moyenne de <b>chaque</b> élève, on parcourt la classe, et pour chaque élève on parcourt <b>ses</b> notes : deux boucles, comme pour une grille.</p>
<div class="rule"><span class="kw">foreach</span> ($classe <span class="kw">as</span> $e) {<br>    <span class="kw">if</span> (count($e['notes']) == 0) {<br>        <span class="kw">echo</span> $e['nom'] . " : pas de note\\n";<br>    } <span class="kw">else</span> {<br>        $total = 0;                 <span class="c">// remis à 0 pour CHAQUE élève</span><br>        <span class="kw">foreach</span> ($e['notes'] <span class="kw">as</span> $n) {<br>            $total = $total + $n;<br>        }<br>        <span class="kw">echo</span> $e['nom'] . " : " . round($total / count($e['notes']), 1) . "\\n";<br>    }<br>}</div>
<ul><li>Le total d'un élève repart de 0 <b>dans</b> la boucle des élèves : sinon Tom hérite des notes de Léa.</li><li>On divise par <code>count($e['notes'])</code>, le nombre de notes <b>de cet élève</b>. Ni <code>count($classe)</code> (le nombre d'élèves), ni <code>count($e)</code> (le nombre de cases de la fiche : nom et notes, donc 2).</li><li>Un élève sans note : diviser par 0 est interdit, on le teste d'abord.</li></ul>
<p><code>array_sum($e['notes'])</code> (chapitre 4) additionne aussi la liste d'un coup.</p>`,
        example: `$classe = [
    ['nom' => 'Léa', 'notes' => [12, 15, 9]],
    ['nom' => 'Tom', 'notes' => [8, 14]],
    ['nom' => 'Inès', 'notes' => []],
];
foreach ($classe as $e) {
    if (count($e['notes']) == 0) {
        echo $e['nom'] . " : pas de note\\n";
    } else {
        echo $e['nom'] . " : " . round(array_sum($e['notes']) / count($e['notes']), 1) . "\\n";
    }
}`,
        tryit: 'Enlève le if : que se passe-t-il pour Inès ?'
      }
    },
    steps: {
      predire: { before: 'listedansfiche', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: `$eleve = ['nom' => 'Léa', 'notes' => [12, 15, 9]];
echo $eleve['notes'][1];`, options: ['15', '12', 'Léa', '9'], hints: ['[\'notes\'] donne la liste [12, 15, 9] ; [1] est sa deuxième case.'] },
      suivre: { before: 'moyenneeleve', kind: 'trace', ask: 'Pour chaque élève, on additionne ses notes. Remplis $nom, $n et $total après chaque passage sur la ligne $total = $total + $n;.', code: `foreach ($eleves as $e) {
    $nom = $e['nom'];
    $total = 0;
    foreach ($e['notes'] as $n) {
        $total = $total + $n;
    }
}`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15, 9] }, { nom: 'Tom', notes: [8, 14] }] }, after: 5, watch: ['$nom', '$n', '$total'], hints: ['Quand on passe à Tom, $total repart de 0 : la ligne $total = 0; est dans la boucle des élèves.'] },
      viser: { kind: 'aimw', ask: 'Écris l\'accès à la case allumée (trois crochets : l\'élève, la clé, le numéro de la note).', var: 'classe', vars: { classe: [{ nom: 'Léa', notes: [12, 15, 9] }, { nom: 'Tom', notes: [8, 14, 17, 11] }, { nom: 'Inès', notes: [16] }] }, target: "[1]['notes'][2]", hints: ['Le numéro de la fiche (à partir de 0), puis [\'notes\'], puis le numéro de la note dans la liste (à partir de 0).'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche la moyenne des notes de l\'élève $eleve, arrondie à 1 décimale.', template: `$total = 0;
foreach ([[]] as $n) {
    $total = $total + [[]];
}
echo round($total / [[]], 1);`, fill: ["$eleve['notes']", '$n', "count($eleve['notes'])"], words: ["$eleve['notes']", '$eleve', '$n', '$total', "count($eleve['notes'])", 'count($eleve)'], ref: `$total = 0;
foreach ($eleve['notes'] as $n) {
    $total = $total + $n;
}
echo round($total / count($eleve['notes']), 1);`, vars: { eleve: { nom: 'Léa', notes: [12, 15, 9] } }, hidden: [{ vars: { eleve: { nom: 'Tom', notes: [8, 14] } }, label: 'deux notes' }, { vars: { eleve: { nom: 'Inès', notes: [17] } }, label: 'une seule note' }, { vars: { eleve: { nom: 'Zoé', notes: [13, 14, 14] } } }], hints: ['On parcourt la liste des notes de l\'élève, et on divise par le nombre de ces notes.', 'foreach ($eleve[\'notes\'] as $n) { … }\necho round($total / count($eleve[…]), 1);'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher le total des notes de chaque élève, « Léa : 36 ». Le total de Tom contient celui de Léa : répare-le.', starter: `$total = 0;
foreach ($eleves as $e) {
    foreach ($e['notes'] as $n) {
        $total = $total + $n;
    }
    echo $e['nom'] . " : " . $total . "\\n";
}`, ref: `foreach ($eleves as $e) {
    $total = 0;
    foreach ($e['notes'] as $n) {
        $total = $total + $n;
    }
    echo $e['nom'] . " : " . $total . "\\n";
}`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15, 9] }, { nom: 'Tom', notes: [8, 14, 10] }, { nom: 'Inès', notes: [16, 11, 13] }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', notes: [10, 20] }, { nom: 'Nora', notes: [] }] }, label: 'deux notes, puis aucune note' }, { vars: { eleves: [{ nom: 'Sami', notes: [5, 5, 5, 5] }] }, label: 'quatre notes' }], traps: [`foreach ($eleves as $e) {
    $total = 0;
    for ($i = 0; $i < 3; $i++) {
        $total = $total + $e['notes'][$i];
    }
    echo $e['nom'] . " : " . $total . "\\n";
}`], ir: [{ re: /<\s*3\s*;/, text: 'Les élèves n\'ont pas tous 3 notes : parcours la liste avec <code>foreach ($e[\'notes\'] as $n)</code>, ou va jusqu\'à <code>count($e[\'notes\'])</code>.' }], hints: ['Chaque élève a son propre total : où faut-il le remettre à 0 ?', 'foreach ($eleves as $e) {\n    $total = 0;\n    …\n}'] },
      ecrire1: { kind: 'write', ask: 'Affiche, pour chaque élève, son nombre de notes sous la forme « Léa : 2 notes ».', ref: `foreach ($eleves as $e) {
    echo $e['nom'] . " : " . count($e['notes']) . " notes\\n";
}`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15] }, { nom: 'Tom', notes: [8, 14] }] }, hidden: [{ vars: { eleves: [{ nom: 'Inès', notes: [16, 11, 13] }, { nom: 'Zoé', notes: [9] }] }, label: 'trois notes, puis une seule' }, { vars: { eleves: [{ nom: 'Hugo', notes: [] }] }, label: 'un élève sans note' }], traps: [`foreach ($eleves as $e) {
    echo $e['nom'] . " : " . count($e) . " notes\\n";
}`, `foreach ($eleves as $e) {
    echo $e['nom'] . " : " . count($eleves) . " notes\\n";
}`], ir: [{ re: /count\s*\(\s*\$e\s*\)/, text: '<code>count($e)</code> compte les cases de la fiche (nom et notes : 2). Les notes, c\'est <code>count($e[\'notes\'])</code>.' }, { re: /count\s*\(\s*\$eleves\s*\)/, text: '<code>count($eleves)</code> compte les élèves. Les notes d\'un élève : <code>count($e[\'notes\'])</code>.' }], hints: ['count compte les cases de ce qu\'on lui donne : donne-lui la liste des notes de l\'élève.', 'foreach ($eleves as $e) {\n    echo $e[\'nom\'] . " : " . count($e[…]) . " notes\\n";\n}'] },
      ecrire2: { kind: 'write', ask: 'Affiche la moyenne de chaque élève arrondie à 1 décimale, « Léa : 12 », ou « Léa : pas de note » s\'il n\'a aucune note.', ref: `foreach ($eleves as $e) {
    if (count($e['notes']) == 0) {
        echo $e['nom'] . " : pas de note\\n";
    } else {
        echo $e['nom'] . " : " . round(array_sum($e['notes']) / count($e['notes']), 1) . "\\n";
    }
}`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15, 9] }, { nom: 'Tom', notes: [8, 14] }, { nom: 'Inès', notes: [16] }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', notes: [] }, { nom: 'Nora', notes: [13, 14] }] }, label: 'Hugo n\'a aucune note' }, { vars: { eleves: [{ nom: 'Sami', notes: [10, 11, 11] }] } }], traps: [`foreach ($eleves as $e) {
    echo $e['nom'] . " : " . round(array_sum($e['notes']) / count($e['notes']), 1) . "\\n";
}`], ir: [{ re: /\/\s*count\s*\(\s*\$eleves\s*\)/, text: 'On divise par le nombre de notes <b>de l\'élève</b> : <code>count($e[\'notes\'])</code>.' }], hints: ['Pour chaque élève : s\'il n\'a pas de note, le dire ; sinon, le total de ses notes divisé par leur nombre.', 'foreach ($eleves as $e) {\n    if (count($e[\'notes\']) == 0) { … }\n    else { … round(array_sum($e[\'notes\']) / count($e[\'notes\']), 1) … }\n}'] },
      ecrire3: { kind: 'write', ask: 'Affiche, une seule fois chacun, le nom des élèves qui ont au moins une note sous 10.', ref: `foreach ($eleves as $e) {
    $faible = false;
    foreach ($e['notes'] as $n) {
        if ($n < 10) {
            $faible = true;
        }
    }
    if ($faible) {
        echo $e['nom'] . "\\n";
    }
}`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15] }, { nom: 'Tom', notes: [14, 11] }, { nom: 'Inès', notes: [16, 8] }] }, hidden: [{ vars: { eleves: [{ nom: 'Tom', notes: [9, 7] }, { nom: 'Léa', notes: [12] }] }, label: 'Tom a deux notes sous 10, Léa aucune' }, { vars: { eleves: [{ nom: 'Hugo', notes: [] }, { nom: 'Zoé', notes: [10, 10] }] }, label: 'personne' }], traps: [`$faible = false;
foreach ($eleves as $e) {
    foreach ($e['notes'] as $n) {
        if ($n < 10) {
            $faible = true;
        }
    }
    if ($faible) {
        echo $e['nom'] . "\\n";
    }
}`, `foreach ($eleves as $e) {
    foreach ($e['notes'] as $n) {
        if ($n < 10) {
            echo $e['nom'] . "\\n";
        }
    }
}`], hints: ['Pour chaque élève, un drapeau remis à false, qui passe à true si une note est sous 10 ; on affiche après la boucle des notes.', 'foreach ($eleves as $e) {\n    $faible = false;\n    foreach ($e[\'notes\'] as $n) { … }\n    if ($faible) { echo … ; }\n}'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  /* ——— N41 · grouping ——— */
  C.level({
    id: 'N41', chapter: 5, num: 41, title: 'Regrouper', summary: '$parSalle[$s[\'salle\']][] = $s[\'nom\'] · compter par groupe', notions: ['regrouper'],
    lessons: {
      groupe: {
        title: 'Ranger par groupe',
        html: `<p>On veut la liste des spécimens <b>de chaque salle</b> : R1 → Trilobite, Ammonite ; R3 → Loup gris… On construit un tableau dont les <b>clés sont les salles</b> et les valeurs des <b>listes de noms</b> :</p>
<div class="rule">$parSalle = [];<br><span class="kw">foreach</span> ($specimens <span class="kw">as</span> $s) {<br>    $parSalle[$s['salle']][] = $s['nom'];<br>}</div>
<p>Cette ligne se lit <b>de l'intérieur vers l'extérieur</b> :</p>
<ul><li><code>$s['salle']</code> : la salle du spécimen, par exemple 'R1' ;</li><li><code>$parSalle['R1']</code> : la liste des noms de cette salle (PHP la crée, vide, la première fois) ;</li><li><code>[] = $s['nom']</code> : ajoute le nom <b>au bout</b> de cette liste.</li></ul>
<p>Résultat : <code>['R1' => ['Trilobite', 'Ammonite'], 'R3' => ['Loup gris']]</code>. On l'affiche avec un <code>foreach</code> qui donne la clé et la liste :</p>
<div class="rule"><span class="kw">foreach</span> ($parSalle <span class="kw">as</span> $salle => $noms) {<br>    <span class="kw">echo</span> $salle . " : " . implode(", ", $noms) . "\\n";<br>}</div>
<p><b>Le piège</b> : oublier les <code>[]</code>. <code>$parSalle[$s['salle']] = $s['nom'];</code> <b>remplace</b> la valeur à chaque spécimen : il ne reste que le dernier nom de chaque salle.</p>`,
        example: `$specimens = [
    ['nom' => 'Trilobite', 'salle' => 'R1'],
    ['nom' => 'Loup gris', 'salle' => 'R3'],
    ['nom' => 'Ammonite', 'salle' => 'R1'],
];
$parSalle = [];
foreach ($specimens as $s) {
    $parSalle[$s['salle']][] = $s['nom'];
}
foreach ($parSalle as $salle => $noms) {
    echo $salle . " : " . implode(", ", $noms) . "\\n";
}`,
        tryit: 'Enlève les [] après $parSalle[$s[\'salle\']] : qui reste en R1 ? (implode refuse alors un texte : c\'est le signe.)'
      },
      compter: {
        title: 'Compter et additionner par groupe',
        html: `<p>Pour <b>compter</b> les spécimens de chaque classe, la valeur d'une clé est un nombre, qu'on augmente de 1. La première fois, la clé n'existe pas encore : <code>?? 0</code> (chapitre 4) part de 0.</p>
<div class="rule">$nb = [];<br><span class="kw">foreach</span> ($specimens <span class="kw">as</span> $s) {<br>    $k = $s['classe'];<br>    $nb[$k] = ($nb[$k] ?? 0) + 1;<br>}</div>
<p>Pour <b>additionner</b> la valeur par salle, même forme : <code>$total[$k] = ($total[$k] ?? 0) + $s['valeur'];</code></p>
<ul><li>On ne crée pas les groupes à la main (<code>$nb = ['Fossiles' => 0, 'Oiseaux' => 0]</code>) : on ne sait pas d'avance quelles classes la collection contient. Les groupes apparaissent dans l'ordre où on les rencontre.</li><li><code>count($parSalle)</code> compte les <b>groupes</b> (les salles) ; <code>count($noms)</code> compte les spécimens <b>d'une</b> salle.</li></ul>`,
        example: `$specimens = [
    ['nom' => 'Trilobite', 'classe' => 'Fossiles'],
    ['nom' => 'Aigle', 'classe' => 'Oiseaux'],
    ['nom' => 'Ammonite', 'classe' => 'Fossiles'],
];
$nb = [];
foreach ($specimens as $s) {
    $k = $s['classe'];
    $nb[$k] = ($nb[$k] ?? 0) + 1;
}
foreach ($nb as $classe => $n) {
    echo $classe . " : " . $n . "\\n";
}`,
        tryit: 'Remplace ($nb[$k] ?? 0) + 1 par $nb[$k] + 1 et lis l\'avertissement.'
      }
    },
    steps: {
      predire: { before: 'groupe', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ? Regarde bien la ligne qui range le nom.', code: `$parSalle = [];
foreach ($specimens as $s) {
    $parSalle[$s['salle']] = $s['nom'];
}
echo $parSalle['R1'];`, vars: { specimens: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Loup gris', salle: 'R3' }, { nom: 'Ammonite', salle: 'R1' }] }, options: ['Ammonite', 'Trilobite', 'Trilobite, Ammonite', 'Array'], hints: ['Il n\'y a pas de [] : chaque spécimen de R1 remplace le précédent.'] },
      suivre: { before: 'compter', kind: 'trace', ask: 'On compte les spécimens de chaque classe. Remplis $k et $vu après chaque passage sur la ligne $vu = $nb[$k];.', code: `$nb = [];
foreach ($specimens as $s) {
    $k = $s['classe'];
    $nb[$k] = ($nb[$k] ?? 0) + 1;
    $vu = $nb[$k];
}`, vars: { specimens: [{ nom: 'Trilobite', classe: 'Fossiles' }, { nom: 'Aigle', classe: 'Oiseaux' }, { nom: 'Ammonite', classe: 'Fossiles' }, { nom: 'Lynx', classe: 'Mammifères' }, { nom: 'Oursin', classe: 'Fossiles' }] }, after: 5, watch: ['$k', '$vu'], hints: ['Chaque classe a son propre compteur : celui des Fossiles passe à 2 au troisième spécimen, celui des Oiseaux reste à 1.'] },
      viser: { kind: 'aim', ask: 'Touche la case $parSalle[\'R3\'][1].', var: 'parSalle', vars: { parSalle: { R1: ['Trilobite', 'Ammonite'], R2: ['Aigle'], R3: ['Loup gris', 'Lynx'] } }, target: "['R3'][1]", hints: ['[\'R3\'] choisit la liste de la salle R3 ; [1] sa deuxième case.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Range les noms des spécimens par classe, puis affiche chaque classe avec ses spécimens : « Fossiles : Trilobite, Ammonite ».', template: `$parClasse = [];
foreach ($specimens as $s) {
    $parClasse[[]][[]] = [[]];
}
foreach ($parClasse as $classe => $noms) {
    echo $classe . " : " . implode(", ", $noms) . "\\n";
}`, fill: ["[$s['classe']]", '[]', "$s['nom']"], words: ["[$s['classe']]", '[]', "$s['nom']", "['classe']", '[0]', '$s'], ref: `$parClasse = [];
foreach ($specimens as $s) {
    $parClasse[$s['classe']][] = $s['nom'];
}
foreach ($parClasse as $classe => $noms) {
    echo $classe . " : " . implode(", ", $noms) . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', classe: 'Fossiles' }, { nom: 'Aigle', classe: 'Oiseaux' }, { nom: 'Ammonite', classe: 'Fossiles' }, { nom: 'Hibou', classe: 'Oiseaux' }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', classe: 'Mammifères' }] }, label: 'un seul spécimen' }, { vars: { specimens: [] }, label: 'une collection vide' }, { vars: { specimens: [{ nom: 'Quartz', classe: 'Minéraux' }, { nom: 'Opale', classe: 'Minéraux' }, { nom: 'Pyrite', classe: 'Minéraux' }] }, label: 'une seule classe' }], hints: ['La clé est la classe du spécimen ; [] ajoute son nom au bout de la liste de cette classe.', '$parClasse[$s[\'classe\']][] = $s[…];'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher chaque salle avec tous ses spécimens : « R1 : Trilobite, Ammonite ». Il n\'en garde qu\'un par salle : répare-le.', starter: `$parSalle = [];
foreach ($specimens as $s) {
    $parSalle[$s['salle']] = $s['nom'];
}
foreach ($parSalle as $salle => $noms) {
    echo $salle . " : " . $noms . "\\n";
}`, ref: `$parSalle = [];
foreach ($specimens as $s) {
    $parSalle[$s['salle']][] = $s['nom'];
}
foreach ($parSalle as $salle => $noms) {
    echo $salle . " : " . implode(", ", $noms) . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Aigle', salle: 'R2' }, { nom: 'Ammonite', salle: 'R1' }, { nom: 'Hibou', salle: 'R2' }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', salle: 'R2' }, { nom: 'Quartz', salle: 'R5' }, { nom: 'Opale', salle: 'R2' }] }, label: 'R2 d\'abord, puis une salle R5' }, { vars: { specimens: [] }, label: 'une collection vide' }], traps: [`$parSalle = ['R1' => [], 'R2' => []];
foreach ($specimens as $s) {
    $parSalle[$s['salle']][] = $s['nom'];
}
foreach ($parSalle as $salle => $noms) {
    echo $salle . " : " . implode(", ", $noms) . "\\n";
}`], ir: [{ re: /\$parSalle\s*=\s*\[\s*'R/, text: 'Ne crée pas les salles à la main : on ne sait pas d\'avance lesquelles la collection contient. <code>$parSalle[$s[\'salle\']][] = …</code> crée chaque salle quand elle apparaît.' }], hints: ['Il faut ajouter le nom à la liste de la salle, pas remplacer : il manque []. Puis implode assemble la liste.', '$parSalle[$s[\'salle\']][] = $s[\'nom\'];\n…\necho $salle . " : " . implode(", ", $noms) . "\\n";'] },
      ecrire1: { kind: 'write', ask: 'Affiche le nombre de spécimens de chaque classe, « Fossiles : 3 », dans l\'ordre où les classes apparaissent. Si la collection est vide, affiche « Collection vide ».', ref: `if (count($specimens) == 0) {
    echo "Collection vide";
}
$nb = [];
foreach ($specimens as $s) {
    $k = $s['classe'];
    $nb[$k] = ($nb[$k] ?? 0) + 1;
}
foreach ($nb as $classe => $n) {
    echo $classe . " : " . $n . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', classe: 'Fossiles' }, { nom: 'Aigle', classe: 'Oiseaux' }, { nom: 'Ammonite', classe: 'Fossiles' }, { nom: 'Hibou', classe: 'Oiseaux' }, { nom: 'Oursin', classe: 'Fossiles' }] }, hidden: [{ vars: { specimens: [] }, label: 'une collection vide' }, { vars: { specimens: [{ nom: 'Lynx', classe: 'Mammifères' }, { nom: 'Quartz', classe: 'Minéraux' }, { nom: 'Opale', classe: 'Minéraux' }] }, label: 'des Mammifères et des Minéraux' }], traps: [`$nb = [];
foreach ($specimens as $s) {
    $k = $s['classe'];
    $nb[$k] = ($nb[$k] ?? 0) + 1;
}
foreach ($nb as $classe => $n) {
    echo $classe . " : " . $n . "\\n";
}`, `if (count($specimens) == 0) {
    echo "Collection vide";
}
$nb = ['Fossiles' => 0, 'Oiseaux' => 0];
foreach ($specimens as $s) {
    $nb[$s['classe']]++;
}
foreach ($nb as $classe => $n) {
    echo $classe . " : " . $n . "\\n";
}`], ir: [{ re: /\$nb\s*=\s*\[\s*'/, text: 'Les classes ne sont pas toujours Fossiles et Oiseaux : laisse <code>$nb</code> vide au départ, et crée chaque compteur avec <code>?? 0</code>.' }], hints: ['Un compteur par classe : $nb[$k] = ($nb[$k] ?? 0) + 1; puis un foreach avec la clé pour afficher.', '$nb = [];\nforeach ($specimens as $s) {\n    $k = $s[\'classe\'];\n    $nb[$k] = ($nb[$k] ?? 0) + 1;\n}\nforeach ($nb as $classe => $n) { … }'] },
      ecrire2: { kind: 'write', ask: 'Affiche la valeur totale de chaque salle, « R1 : 1800 € », en ne comptant que les spécimens entrés en 1900 ou après.', ref: `$total = [];
foreach ($specimens as $s) {
    if ($s['annee'] >= 1900) {
        $k = $s['salle'];
        $total[$k] = ($total[$k] ?? 0) + $s['valeur'];
    }
}
foreach ($total as $salle => $t) {
    echo $salle . " : " . $t . " €\\n";
}`, vars: { specimens: [{ salle: 'R1', annee: 1911, valeur: 800 }, { salle: 'R3', annee: 1954, valeur: 2500 }, { salle: 'R1', annee: 1898, valeur: 450 }, { salle: 'R1', annee: 1930, valeur: 1000 }, { salle: 'R3', annee: 1987, valeur: 1200 }] }, hidden: [{ vars: { specimens: [{ salle: 'R2', annee: 1900, valeur: 300 }, { salle: 'R2', annee: 1850, valeur: 700 }, { salle: 'R4', annee: 2001, valeur: 50 }] }, label: 'un spécimen entré en 1900 pile' }, { vars: { specimens: [{ salle: 'R5', annee: 1820, valeur: 90 }] }, label: 'aucun spécimen après 1900' }], traps: [`$total = [];
foreach ($specimens as $s) {
    if ($s['annee'] > 1900) {
        $k = $s['salle'];
        $total[$k] = ($total[$k] ?? 0) + $s['valeur'];
    }
}
foreach ($total as $salle => $t) {
    echo $salle . " : " . $t . " €\\n";
}`], ir: [{ re: />\s*1900/, text: '« En 1900 ou après » garde 1900 : <code>&gt;=</code>.' }], hints: ['Un if sur l\'année, puis un total par salle avec ?? 0.', '$total = [];\nforeach ($specimens as $s) {\n    if ($s[\'annee\'] >= 1900) {\n        $total[$s[\'salle\']] = ($total[$s[\'salle\']] ?? 0) + $s[\'valeur\'];\n    }\n}\nforeach ($total as $salle => $t) { … }'] },
      ecrire3: { kind: 'write', ask: 'Affiche chaque classe avec son nombre de spécimens et leurs noms : « Fossiles (2) : Trilobite, Ammonite ».', ref: `$parClasse = [];
foreach ($specimens as $s) {
    $parClasse[$s['classe']][] = $s['nom'];
}
foreach ($parClasse as $classe => $noms) {
    echo $classe . " (" . count($noms) . ") : " . implode(", ", $noms) . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', classe: 'Fossiles' }, { nom: 'Aigle', classe: 'Oiseaux' }, { nom: 'Ammonite', classe: 'Fossiles' }, { nom: 'Hibou', classe: 'Oiseaux' }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', classe: 'Mammifères' }, { nom: 'Quartz', classe: 'Minéraux' }, { nom: 'Opale', classe: 'Minéraux' }, { nom: 'Dodo', classe: 'Oiseaux' }, { nom: 'Pyrite', classe: 'Minéraux' }] }, label: 'trois classes de tailles différentes' }, { vars: { specimens: [{ nom: 'Opale', classe: 'Minéraux' }] }, label: 'un seul spécimen' }], traps: [`$parClasse = [];
foreach ($specimens as $s) {
    $parClasse[$s['classe']][] = $s['nom'];
}
foreach ($parClasse as $classe => $noms) {
    echo $classe . " (" . count($parClasse) . ") : " . implode(", ", $noms) . "\\n";
}`], ir: [{ re: /count\s*\(\s*\$parClasse\s*\)/, text: '<code>count($parClasse)</code> compte les classes. Les spécimens d\'une classe, c\'est la liste <code>$noms</code> : <code>count($noms)</code>.' }], hints: ['Regroupe les noms par classe, puis, pour chaque classe, count de sa liste et implode.', 'foreach ($parClasse as $classe => $noms) {\n    echo $classe . " (" . count($noms) . ") : " . implode(", ", $noms) . "\\n";\n}'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  C.mastery({
    id: 'M17', chapter: 5, title: 'Tableaux dans des tableaux', covers: ['N40', 'N41'], draw: 3,
    pool: [
      { ask: 'Affiche toutes les notes de l\'élève $eleve, séparées par un espace.', ref: 'echo implode(" ", $eleve[\'notes\']);', vars: { eleve: { nom: 'Léa', notes: [12, 15, 9] } }, hidden: [{ vars: { eleve: { nom: 'Tom', notes: [8, 14] } }, label: 'deux notes' }, { vars: { eleve: { nom: 'Inès', notes: [17, 11, 13, 10] } }, label: 'quatre notes' }], traps: [`for ($i = 0; $i < 3; $i++) {
    echo $eleve['notes'][$i] . " ";
}`], hints: ['La liste des notes est $eleve[\'notes\'] ; implode l\'assemble.', 'echo implode(" ", $eleve[…]);'] },
      { ask: 'Affiche la meilleure note de l\'élève $eleve, ou « pas de note » s\'il n\'en a aucune.', ref: `if (count($eleve['notes']) == 0) {
    echo "pas de note";
} else {
    $max = $eleve['notes'][0];
    foreach ($eleve['notes'] as $n) {
        if ($n > $max) {
            $max = $n;
        }
    }
    echo $max;
}`, vars: { eleve: { nom: 'Léa', notes: [12, 15, 9] } }, hidden: [{ vars: { eleve: { nom: 'Tom', notes: [] } }, label: 'aucune note' }, { vars: { eleve: { nom: 'Inès', notes: [7] } }, label: 'une seule note' }], traps: [`$max = $eleve['notes'][0];
foreach ($eleve['notes'] as $n) {
    if ($n > $max) {
        $max = $n;
    }
}
echo $max;`], hints: ['La liste vide d\'abord ; sinon un maximum qui part de la première note.', 'if (count($eleve[\'notes\']) == 0) {\n    echo "pas de note";\n} else {\n    $max = $eleve[\'notes\'][0];\n    …\n}'] },
      { ask: 'Affiche le nombre total de notes de toute la classe.', ref: `$n = 0;
foreach ($eleves as $e) {
    $n = $n + count($e['notes']);
}
echo $n;`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15] }, { nom: 'Tom', notes: [8, 14] }, { nom: 'Inès', notes: [16, 11] }] }, hidden: [{ vars: { eleves: [{ nom: 'Léa', notes: [12, 15, 9] }, { nom: 'Tom', notes: [8, 14, 10] }] } }, { vars: { eleves: [{ nom: 'Hugo', notes: [] }] }, label: 'un élève sans note' }], traps: [`$n = 0;
foreach ($eleves as $e) {
    $n = $n + count($e);
}
echo $n;`], hints: ['Pour chaque élève, on ajoute le nombre de SES notes.', '$n = 0;\nforeach ($eleves as $e) {\n    $n = $n + count($e[…]);\n}\necho $n;'] },
      { ask: 'Affiche la meilleure note de chaque élève, « Léa : 15 », ou « Léa : pas de note ».', ref: `foreach ($eleves as $e) {
    if (count($e['notes']) == 0) {
        echo $e['nom'] . " : pas de note\\n";
    } else {
        $max = $e['notes'][0];
        foreach ($e['notes'] as $n) {
            if ($n > $max) {
                $max = $n;
            }
        }
        echo $e['nom'] . " : " . $max . "\\n";
    }
}`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15, 9] }, { nom: 'Tom', notes: [8, 14] }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', notes: [] }, { nom: 'Nora', notes: [3] }] }, label: 'Hugo n\'a aucune note' }, { vars: { eleves: [{ nom: 'Sami', notes: [11, 19, 4] }] } }], traps: [`foreach ($eleves as $e) {
    $max = $e['notes'][0];
    foreach ($e['notes'] as $n) {
        if ($n > $max) {
            $max = $n;
        }
    }
    echo $e['nom'] . " : " . $max . "\\n";
}`], hints: ['Pour chaque élève : la liste vide d\'abord, sinon un maximum qui part de sa première note.', 'foreach ($eleves as $e) {\n    if (count($e[\'notes\']) == 0) { … }\n    else { $max = $e[\'notes\'][0]; … }\n}'] },
      { ask: 'Affiche, une seule fois chacun, le nom des élèves qui ont eu au moins un 20.', ref: `foreach ($eleves as $e) {
    $vingt = false;
    foreach ($e['notes'] as $n) {
        if ($n == 20) {
            $vingt = true;
        }
    }
    if ($vingt) {
        echo $e['nom'] . "\\n";
    }
}`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15] }, { nom: 'Tom', notes: [14, 11] }, { nom: 'Inès', notes: [20, 8] }] }, hidden: [{ vars: { eleves: [{ nom: 'Tom', notes: [20, 20] }, { nom: 'Léa', notes: [12] }] }, label: 'Tom a deux 20, Léa aucun' }, { vars: { eleves: [{ nom: 'Hugo', notes: [] }] }, label: 'un élève sans note' }], traps: [`$vingt = false;
foreach ($eleves as $e) {
    foreach ($e['notes'] as $n) {
        if ($n == 20) {
            $vingt = true;
        }
    }
    if ($vingt) {
        echo $e['nom'] . "\\n";
    }
}`, `foreach ($eleves as $e) {
    foreach ($e['notes'] as $n) {
        if ($n == 20) {
            echo $e['nom'] . "\\n";
        }
    }
}`], hints: ['Un drapeau par élève, remis à false au début de chaque élève ; on affiche après la boucle des notes.', 'foreach ($eleves as $e) {\n    $vingt = false;\n    foreach ($e[\'notes\'] as $n) { … }\n    if ($vingt) { … }\n}'] },
      { ask: 'Range les spécimens par classe et affiche « Fossiles : Trilobite, Ammonite », une classe par ligne, dans l\'ordre où les classes apparaissent.', ref: `$p = [];
foreach ($specimens as $s) {
    $p[$s['classe']][] = $s['nom'];
}
foreach ($p as $classe => $noms) {
    echo $classe . " : " . implode(", ", $noms) . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', classe: 'Fossiles' }, { nom: 'Aigle', classe: 'Oiseaux' }, { nom: 'Ammonite', classe: 'Fossiles' }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', classe: 'Mammifères' }, { nom: 'Hibou', classe: 'Oiseaux' }, { nom: 'Ours', classe: 'Mammifères' }] }, label: 'des Mammifères d\'abord' }, { vars: { specimens: [] }, label: 'une collection vide' }], traps: [`$p = ['Fossiles' => [], 'Oiseaux' => []];
foreach ($specimens as $s) {
    $p[$s['classe']][] = $s['nom'];
}
foreach ($p as $classe => $noms) {
    echo $classe . " : " . implode(", ", $noms) . "\\n";
}`], hints: ['$p[$s[\'classe\']][] = $s[\'nom\']; crée chaque classe quand elle apparaît.', '$p = [];\nforeach ($specimens as $s) {\n    $p[$s[\'classe\']][] = $s[\'nom\'];\n}\nforeach ($p as $classe => $noms) { … }'] },
      { ask: 'Affiche le nombre de spécimens de chaque salle, « R1 : 2 », dans l\'ordre où les salles apparaissent.', ref: `$nb = [];
foreach ($specimens as $s) {
    $nb[$s['salle']] = ($nb[$s['salle']] ?? 0) + 1;
}
foreach ($nb as $salle => $n) {
    echo $salle . " : " . $n . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Aigle', salle: 'R2' }, { nom: 'Ammonite', salle: 'R1' }, { nom: 'Loup gris', salle: 'R3' }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', salle: 'R3' }, { nom: 'Quartz', salle: 'R5' }, { nom: 'Opale', salle: 'R3' }] }, label: 'R3 d\'abord, puis une salle R5' }, { vars: { specimens: [{ nom: 'Dodo', salle: 'R2' }] }, label: 'un seul spécimen' }], traps: [`$nb = ['R1' => 0, 'R2' => 0, 'R3' => 0];
foreach ($specimens as $s) {
    $nb[$s['salle']]++;
}
foreach ($nb as $salle => $n) {
    if ($n > 0) {
        echo $salle . " : " . $n . "\\n";
    }
}`], hints: ['Un compteur par salle, créé avec ?? 0 quand la salle apparaît.', '$nb = [];\nforeach ($specimens as $s) {\n    $nb[$s[\'salle\']] = ($nb[$s[\'salle\']] ?? 0) + 1;\n}\nforeach ($nb as $salle => $n) { … }'] },
      { ask: 'Affiche la valeur totale de chaque classe, « Fossiles : 1250 € », les classes dans l\'ordre alphabétique (ksort).', ref: `$total = [];
foreach ($specimens as $s) {
    $total[$s['classe']] = ($total[$s['classe']] ?? 0) + $s['valeur'];
}
ksort($total);
foreach ($total as $classe => $t) {
    echo $classe . " : " . $t . " €\\n";
}`, vars: { specimens: [{ classe: 'Fossiles', valeur: 800 }, { classe: 'Oiseaux', valeur: 1200 }, { classe: 'Fossiles', valeur: 450 }] }, hidden: [{ vars: { specimens: [{ classe: 'Oiseaux', valeur: 300 }, { classe: 'Mammifères', valeur: 2500 }, { classe: 'Oiseaux', valeur: 100 }] }, label: 'des classes qui n\'arrivent pas dans l\'ordre alphabétique' }, { vars: { specimens: [{ classe: 'Minéraux', valeur: 150 }] } }], traps: [`$total = [];
foreach ($specimens as $s) {
    $total[$s['classe']] = ($total[$s['classe']] ?? 0) + $s['valeur'];
}
foreach ($total as $classe => $t) {
    echo $classe . " : " . $t . " €\\n";
}`], hints: ['Un total par classe avec ?? 0, puis ksort trie les clés avant l\'affichage.', '$total = [];\nforeach … \nksort($total);\nforeach ($total as $classe => $t) { … }'] },
      { ask: 'Pour chaque salle, affiche le nom de son spécimen le plus précieux, « R1 : Trilobite » (le premier rencontré en cas d\'égalité).', ref: `$best = [];
foreach ($specimens as $s) {
    $k = $s['salle'];
    if (!isset($best[$k]) || $s['valeur'] > $best[$k]['valeur']) {
        $best[$k] = $s;
    }
}
foreach ($best as $salle => $s) {
    echo $salle . " : " . $s['nom'] . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', salle: 'R1', valeur: 800 }, { nom: 'Aigle', salle: 'R2', valeur: 1200 }, { nom: 'Ammonite', salle: 'R1', valeur: 450 }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', salle: 'R3', valeur: 3000 }, { nom: 'Dodo', salle: 'R3', valeur: 3000 }] }, label: 'deux spécimens ex æquo en R3' }, { vars: { specimens: [{ nom: 'Quartz', salle: 'R5', valeur: 150 }, { nom: 'Opale', salle: 'R5', valeur: 900 }] } }], traps: [`$best = [];
foreach ($specimens as $s) {
    $k = $s['salle'];
    if (!isset($best[$k]) || $s['valeur'] >= $best[$k]['valeur']) {
        $best[$k] = $s;
    }
}
foreach ($best as $salle => $s) {
    echo $salle . " : " . $s['nom'] . "\\n";
}`], hints: ['Un tableau $best dont la clé est la salle et la valeur une fiche entière ; une salle encore absente prend la première fiche venue (isset).', 'if (!isset($best[$k]) || $s[\'valeur\'] > $best[$k][\'valeur\']) {\n    $best[$k] = $s;\n}'] },
      { ask: 'Ajoute la note $nouvelle au bout des notes de l\'élève numéro $i, puis affiche toutes ses notes séparées par un espace.', ref: `$eleves[$i]['notes'][] = $nouvelle;
echo implode(" ", $eleves[$i]['notes']);`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15] }, { nom: 'Tom', notes: [8, 14] }, { nom: 'Inès', notes: [] }], i: 1, nouvelle: 13 }, hidden: [{ vars: { i: 0, nouvelle: 20 } }, { vars: { i: 2, nouvelle: 9 }, label: 'Inès, qui n\'avait aucune note' }], traps: [`$eleves[1]['notes'][] = $nouvelle;
echo implode(" ", $eleves[1]['notes']);`], hints: ['Trois crochets : l\'élève $i, sa liste de notes, et [] pour ajouter au bout.', '$eleves[$i][\'notes\'][] = $nouvelle;\necho implode(" ", $eleves[$i][…]);'] }
    ]
  });

  /* ——— N42 · building a two-dimensional array ——— */
  C.level({
    id: 'N42', chapter: 5, num: 42, title: 'Construire un tableau à deux dimensions', summary: 'remplir une grille · $eleves[] = [\'nom\' => …, \'note\' => …]', notions: ['construire'],
    lessons: {
      remplirgrille: {
        title: 'Remplir une grille',
        html: `<p>Jusqu'ici, la grille était donnée. On peut aussi la <b>construire</b> : deux boucles, une ligne à la fois.</p>
<div class="rule">$g = [];<br><span class="kw">for</span> ($l = 0; $l &lt; $nl; $l++) {<br>    $ligne = [];                     <span class="c">// une ligne neuve, vide</span><br>    <span class="kw">for</span> ($c = 0; $c &lt; $nc; $c++) {<br>        $ligne[] = ($l + 1) * ($c + 1);  <span class="c">// on ajoute une case</span><br>    }<br>    $g[] = $ligne;                   <span class="c">// la ligne finie entre dans la grille</span><br>}</div>
<ul><li><code>$ligne = [];</code> se place au début de la boucle des lignes : chaque ligne commence vide. Placée avant les deux boucles, la ligne 1 garderait les cases de la ligne 0 et grandirait.</li><li>La boucle des lignes s'arrête au nombre de lignes <code>$nl</code>, celle des colonnes au nombre de colonnes <code>$nc</code>. Les inverser ne se voit pas sur une grille carrée : essaie toujours une grille de 2 lignes et 5 colonnes.</li><li>On peut aussi écrire directement <code>$g[$l][$c] = …;</code> : PHP crée la ligne si elle n'existe pas encore.</li></ul>`,
        example: `$nl = 2;
$nc = 5;
$g = [];
for ($l = 0; $l < $nl; $l++) {
    $ligne = [];
    for ($c = 0; $c < $nc; $c++) {
        $ligne[] = ($l + 1) * ($c + 1);
    }
    $g[] = $ligne;
}
foreach ($g as $ligne) {
    echo implode(" ", $ligne) . "\\n";
}`,
        tryit: 'Déplace $ligne = []; avant le premier for : que devient la deuxième ligne ?'
      },
      assembler: {
        title: 'Construire une liste de fiches',
        html: `<p>Les données arrivent parfois en <b>listes séparées</b> : les noms d'un côté, les notes de l'autre, dans le même ordre. On les assemble en fiches :</p>
<div class="rule">$eleves = [];<br><span class="kw">for</span> ($i = 0; $i &lt; count($noms); $i++) {<br>    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];<br>}</div>
<p>Le même <code>$i</code> lit la case <code>$i</code> des deux listes : Léa va avec 14, Tom avec 9. <code>$eleves[] = [...]</code> ajoute <b>une fiche entière</b> au bout de la liste.</p>
<p><b>Le piège</b> : <code>$eleves['nom'] = $noms[$i];</code> sans les <code>[]</code>. On n'ajoute pas de fiche : on réécrit à chaque tour la case 'nom' d'une seule fiche, et il ne reste que le dernier élève.</p>`,
        example: `$noms = ['Léa', 'Tom', 'Inès'];
$notes = [14, 9, 17];
$eleves = [];
for ($i = 0; $i < count($noms); $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
echo $eleves[2]['nom'] . " : " . $eleves[2]['note'];`,
        tryit: 'Ouvre « Pas à pas » et regarde $eleves grandir d\'une fiche à chaque tour.'
      }
    },
    steps: {
      predire: { before: 'remplirgrille', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: `$g = [];
for ($l = 0; $l < 2; $l++) {
    $ligne = [];
    for ($c = 0; $c < 3; $c++) {
        $ligne[] = $l + $c;
    }
    $g[] = $ligne;
}
echo $g[1][2];`, options: ['3', '2', '5', '12'], hints: ['La case [1][2] est remplie quand $l vaut 1 et $c vaut 2.'] },
      suivre: { kind: 'trace', ask: 'On construit une table de multiplication. Remplis $l, $c et $v après chaque passage sur la ligne $ligne[] = $v;.', code: `$g = [];
for ($l = 0; $l < 2; $l++) {
    $ligne = [];
    for ($c = 0; $c < 3; $c++) {
        $v = ($l + 1) * ($c + 1);
        $ligne[] = $v;
    }
    $g[] = $ligne;
}`, after: 6, watch: ['$l', '$c', '$v'], hints: ['Pour $l = 0, $c vaut 0, 1, 2 et $v vaut 1, 2, 3 ; puis $l passe à 1.'] },
      viser: { kind: 'aimw', ask: 'Cette table a été construite avec $table[$l][$c] = ($l + 1) × ($c + 1). Écris l\'accès à la case allumée.', var: 'table', vars: { table: [[1, 2, 3, 4], [2, 4, 6, 8], [3, 6, 9, 12]] }, target: '[1][3]', hints: ['La case allumée vaut 2 × 4 : $l + 1 = 2 et $c + 1 = 4.'] },
      comprendre: {},
      completer: { before: 'assembler', kind: 'complete', ask: 'Assemble les listes $noms et $notes en une liste de fiches $eleves, puis affiche chaque fiche « Léa : 14 ».', template: `$eleves = [];
for ($i = 0; $i < count($noms); $i++) {
    $eleves[] = ['nom' => [[]], 'note' => [[]]];
}
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`, fill: ['$noms[$i]', '$notes[$i]'], words: ['$noms[$i]', '$notes[$i]', '$noms', '$notes', '$i', '$eleves[$i]'], ref: `$eleves = [];
for ($i = 0; $i < count($noms); $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`, vars: { noms: ['Léa', 'Tom', 'Inès'], notes: [14, 9, 17] }, hidden: [{ vars: { noms: ['Hugo'], notes: [20] }, label: 'un seul élève' }, { vars: { noms: [], notes: [] }, label: 'des listes vides' }, { vars: { noms: ['Nora', 'Sami'], notes: [8, 12] } }], hints: ['Le même $i lit le nom et la note.', '$eleves[] = [\'nom\' => $noms[$i], \'note\' => $notes[…]];'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit assembler $noms et $notes en fiches, puis afficher « Léa : 14 » pour chaque élève. PHP s\'arrête sur une erreur : répare-le.', starter: `$eleves = [];
for ($i = 0; $i < count($noms); $i++) {
    $eleves['nom'] = $noms[$i];
    $eleves['note'] = $notes[$i];
}
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`, ref: `$eleves = [];
for ($i = 0; $i < count($noms); $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`, vars: { noms: ['Léa', 'Tom', 'Inès'], notes: [14, 9, 17] }, hidden: [{ vars: { noms: ['Nora', 'Sami'], notes: [8, 12] }, label: 'deux élèves' }, { vars: { noms: ['Jade', 'Paul', 'Emma', 'Hugo', 'Zoé'], notes: [15, 10, 13, 20, 11] }, label: 'cinq élèves' }], traps: [`$eleves = [];
for ($i = 0; $i < 3; $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`], ir: [{ re: /\$eleves\s*\[\s*'nom'\s*\]\s*=/, text: '<code>$eleves[\'nom\'] = …</code> réécrit la case nom d\'une seule fiche. Pour ajouter une fiche au bout de la liste : <code>$eleves[] = [\'nom\' => …, \'note\' => …];</code>' }], hints: ['À chaque tour, il faut ajouter une fiche entière au bout de la liste, pas réécrire une case.', '$eleves[] = [\'nom\' => $noms[$i], \'note\' => $notes[$i]];'] },
      ecrire1: { kind: 'write', ask: 'Construis la grille $g d\'un damier de $nl lignes et $nc colonnes : « # » quand $l + $c est pair, « . » sinon. Affiche-la ensuite, une ligne par ligne, cases collées.', ref: `$g = [];
for ($l = 0; $l < $nl; $l++) {
    $ligne = [];
    for ($c = 0; $c < $nc; $c++) {
        if (($l + $c) % 2 == 0) {
            $ligne[] = "#";
        } else {
            $ligne[] = ".";
        }
    }
    $g[] = $ligne;
}
foreach ($g as $ligne) {
    echo implode("", $ligne) . "\\n";
}`, vars: { nl: 3, nc: 3 }, hidden: [{ vars: { nl: 2, nc: 5 }, label: '2 lignes, 5 colonnes' }, { vars: { nl: 4, nc: 1 }, label: '4 lignes, 1 colonne' }], traps: [`$g = [];
for ($l = 0; $l < $nl; $l++) {
    $ligne = [];
    for ($c = 0; $c < $nl; $c++) {
        if (($l + $c) % 2 == 0) {
            $ligne[] = "#";
        } else {
            $ligne[] = ".";
        }
    }
    $g[] = $ligne;
}
foreach ($g as $ligne) {
    echo implode("", $ligne) . "\\n";
}`], ir: [{ re: /\$c\s*<\s*\$nl/, text: 'La boucle des colonnes va jusqu\'au nombre de colonnes : <code>$c &lt; $nc</code>.' }], hints: ['Une ligne neuve à chaque tour de la boucle des lignes ; dans la boucle des colonnes, un if sur ($l + $c) % 2.', '$g = [];\nfor ($l = 0; $l < $nl; $l++) {\n    $ligne = [];\n    for ($c = 0; $c < $nc; $c++) { … }\n    $g[] = $ligne;\n}\nforeach ($g as $ligne) { … }'] },
      ecrire2: { kind: 'write', ask: 'Assemble $noms et $notes en fiches $eleves, puis affiche seulement les élèves qui ont au moins 10, « Léa : 14 ».', ref: `$eleves = [];
for ($i = 0; $i < count($noms); $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
foreach ($eleves as $e) {
    if ($e['note'] >= 10) {
        echo $e['nom'] . " : " . $e['note'] . "\\n";
    }
}`, vars: { noms: ['Léa', 'Tom', 'Inès', 'Zoé'], notes: [14, 9, 17, 8] }, hidden: [{ vars: { noms: ['Hugo', 'Nora'], notes: [10, 3] }, label: 'Hugo a 10 pile' }, { vars: { noms: ['Sami', 'Jade', 'Paul', 'Emma', 'Lina', 'Noé'], notes: [12, 5, 11, 19, 7, 15] }, label: 'six élèves' }], traps: [`$eleves = [];
for ($i = 0; $i < 4; $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
foreach ($eleves as $e) {
    if ($e['note'] >= 10) {
        echo $e['nom'] . " : " . $e['note'] . "\\n";
    }
}`, `$eleves = [];
for ($i = 0; $i < count($noms); $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
foreach ($eleves as $e) {
    if ($e['note'] > 10) {
        echo $e['nom'] . " : " . $e['note'] . "\\n";
    }
}`], hints: ['D\'abord construire la liste de fiches, puis la parcourir avec un if.', '$eleves = [];\nfor ($i = 0; $i < count($noms); $i++) {\n    $eleves[] = [\'nom\' => $noms[$i], \'note\' => $notes[$i]];\n}\nforeach ($eleves as $e) {\n    if (…) { … }\n}'] },
      ecrire3: { kind: 'write', ask: 'Place les élèves de $noms dans l\'amphithéâtre, par rangs de $par places, dans l\'ordre de la liste. Affiche chaque rang : « Rang 1 : Léa Tom Inès ». Le dernier rang peut être incomplet.', ref: `$rangs = [];
for ($i = 0; $i < count($noms); $i++) {
    $rangs[intdiv($i, $par)][] = $noms[$i];
}
foreach ($rangs as $r => $rang) {
    echo "Rang " . ($r + 1) . " : " . implode(" ", $rang) . "\\n";
}`, vars: { noms: ['Léa', 'Tom', 'Inès', 'Zoé', 'Hugo', 'Nora'], par: 3 }, hidden: [{ vars: { noms: ['Léa', 'Tom', 'Inès', 'Zoé', 'Hugo', 'Nora', 'Sami'], par: 3 }, label: '7 élèves par rangs de 3 (un rang incomplet)' }, { vars: { noms: ['Jade', 'Paul'], par: 3 }, label: '2 élèves, un seul rang incomplet' }], traps: [`for ($r = 0; $r < intdiv(count($noms), $par); $r++) {
    $rang = [];
    for ($p = 0; $p < $par; $p++) {
        $rang[] = $noms[$r * $par + $p];
    }
    echo "Rang " . ($r + 1) . " : " . implode(" ", $rang) . "\\n";
}`], hints: ['L\'élève numéro $i va au rang intdiv($i, $par) : avec 3 places, les élèves 0, 1, 2 au rang 0, les élèves 3, 4, 5 au rang 1…', '$rangs = [];\nfor ($i = 0; $i < count($noms); $i++) {\n    $rangs[intdiv($i, $par)][] = $noms[$i];\n}\nforeach ($rangs as $r => $rang) { … }'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  /* ——— N43 · sorting ——— */
  C.level({
    id: 'N43', chapter: 5, num: 43, title: 'Trier', summary: 'sort · rsort · asort · arsort · ksort · usort et <=>', notions: ['trier'],
    lessons: {
      sorts: {
        title: 'Trier un tableau',
        html: `<p>PHP sait trier. Chaque fonction de tri <b>modifie le tableau lui-même</b> :</p>
<ul><li><code>sort($t)</code> : les valeurs dans l'ordre croissant (les nombres du plus petit au plus grand, les textes dans l'ordre alphabétique) ;</li><li><code>rsort($t)</code> : l'ordre décroissant ;</li><li><code>asort($t)</code> / <code>arsort($t)</code> : croissant / décroissant <b>en gardant les clés</b>, pour un tableau associatif comme <code>['Léa' => 14, 'Tom' => 16]</code> ;</li><li><code>ksort($t)</code> : trie selon les <b>clés</b> (par exemple les noms), pas selon les valeurs.</li></ul>
<div class="rule">$notes = [14, 9, 17];<br>sort($notes);                   <span class="c">// $notes vaut maintenant [9, 14, 17]</span><br><span class="kw">echo</span> implode(" ", $notes);</div>
<p><b>Le piège classique</b> : <code>$notes = sort($notes);</code>. <code>sort</code> ne renvoie pas le tableau trié : il renvoie seulement <code>true</code> (« c'est fait »). Cette ligne remplace donc tes notes par <code>true</code>. On écrit <code>sort($notes);</code> seul sur sa ligne, puis on utilise <code>$notes</code>.</p>
<p>Et <code>sort</code> sur un tableau associatif perd les clés (elles deviennent 0, 1, 2) : pour garder les noms, c'est <code>asort</code> ou <code>arsort</code>.</p>`,
        example: `$notes = ['Léa' => 14, 'Tom' => 16, 'Inès' => 9];
arsort($notes);
foreach ($notes as $nom => $note) {
    echo $nom . " : " . $note . "\\n";
}`,
        tryit: 'Remplace arsort par ksort, puis par sort : que deviennent les noms ?'
      },
      usort: {
        title: 'Trier des fiches : usort',
        html: `<p>Comment trier une liste de fiches ? PHP ne sait pas s'il faut trier par nom, par note… On lui donne une petite fonction qui <b>compare deux fiches</b> <code>$a</code> et <code>$b</code> :</p>
<div class="rule">usort($eleves, <span class="kw">fn</span>($a, $b) => $a['note'] &lt;=> $b['note']);</div>
<p><code>fn($a, $b) => …</code> est une fonction en une ligne : elle reçoit deux fiches et renvoie le résultat de la comparaison. <code>usort</code> l'appelle autant de fois qu'il faut pour ranger toute la liste.</p>
<p><code>&lt;=></code> (le « vaisseau spatial ») compare deux valeurs : il donne -1 si la première est plus petite, 0 si elles sont égales, 1 si elle est plus grande. Avec <code>$a</code> à gauche, le tri est <b>croissant</b> ; pour un tri <b>décroissant</b>, on inverse : <code>$b['note'] &lt;=> $a['note']</code>.</p>
<ul><li>On trie par nom avec <code>$a['nom'] &lt;=> $b['nom']</code>.</li><li>Deux fiches égales gardent leur ordre de départ.</li><li>Comme <code>sort</code>, <code>usort</code> modifie la liste et ne renvoie rien d'utile : jamais <code>$eleves = usort(...)</code>.</li></ul>`,
        example: `$eleves = [
    ['nom' => 'Léa', 'note' => 14],
    ['nom' => 'Tom', 'note' => 9],
    ['nom' => 'Inès', 'note' => 17],
];
usort($eleves, fn($a, $b) => $b['note'] <=> $a['note']);
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`,
        tryit: 'Échange $a et $b pour trier dans l\'autre sens, puis trie par nom.'
      }
    },
    steps: {
      predire: { before: 'sorts', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: `$t = [5, 2, 8];
$r = sort($t);
echo $r;`, options: ['1', '2 5 8', '258', 'Array'], hints: ['sort range $t lui-même et renvoie seulement true ; echo true affiche 1.'] },
      suivre: { kind: 'trace', ask: 'arsort trie par note décroissante en gardant les noms. Remplis $rang, $nom et $note après chaque passage sur la ligne $rang++;.', code: `arsort($notes);
$rang = 0;
foreach ($notes as $nom => $note) {
    $rang++;
}`, vars: { notes: { 'Léa': 12, 'Tom': 16, 'Inès': 9 } }, after: 4, watch: ['$rang', '$nom', '$note'], hints: ['Après arsort, la plus haute note vient d\'abord, et chaque nom reste attaché à sa note.'] },
      viser: { kind: 'aim', ask: 'Cette liste vient d\'être triée par usort, note croissante. Touche le nom de l\'élève qui a la deuxième meilleure note.', var: 'eleves', vars: { eleves: [{ nom: 'Zoé', note: 8 }, { nom: 'Tom', note: 11 }, { nom: 'Léa', note: 14 }, { nom: 'Inès', note: 17 }] }, target: "[2]['nom']", hints: ['Note croissante : la meilleure est la dernière fiche, la deuxième meilleure juste avant.'] },
      comprendre: {},
      completer: { before: 'usort', kind: 'complete', ask: 'Trie les élèves par note décroissante, puis affiche « Inès : 17 », un par ligne.', template: `usort($eleves, fn($a, $b) => [[]]['note'] <=> [[]]['note']);
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`, fill: ['$b', '$a'], words: ['$a', '$b', '$e', '$eleves'], ref: `usort($eleves, fn($a, $b) => $b['note'] <=> $a['note']);
foreach ($eleves as $e) {
    echo $e['nom'] . " : " . $e['note'] . "\\n";
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, hidden: [{ vars: { eleves: [{ nom: 'Nora', note: 12 }, { nom: 'Sami', note: 15 }, { nom: 'Jade', note: 12 }] }, label: 'deux élèves ex æquo' }, { vars: { eleves: [{ nom: 'Hugo', note: 10 }] }, label: 'un seul élève' }], hints: ['Décroissant : c\'est $b qui est à gauche de &lt;=>.', 'usort($eleves, fn($a, $b) => $b[\'note\'] <=> $a[…]);'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher les notes de la plus haute à la plus basse, une par ligne. Il affiche un avertissement : répare-le.', starter: `$notes = rsort($notes);
foreach ($notes as $n) {
    echo $n . "\\n";
}`, ref: `rsort($notes);
foreach ($notes as $n) {
    echo $n . "\\n";
}`, vars: { notes: [14, 9, 17, 11] }, hidden: [{ vars: { notes: [8, 12] }, label: 'deux notes' }, { vars: { notes: [10, 20, 15, 5, 13] }, label: 'cinq notes' }], traps: [`rsort($notes);
for ($i = 0; $i < 4; $i++) {
    echo $notes[$i] . "\\n";
}`], ir: [{ re: /=\s*r?sort\s*\(/, text: '<code>rsort</code> trie le tableau lui-même et renvoie seulement <code>true</code> : écris <code>rsort($notes);</code> seul, sans <code>$notes =</code>.' }], hints: ['Lis l\'avertissement : après la première ligne, $notes n\'est plus un tableau. Que renvoie rsort ?', 'rsort($notes);\nforeach ($notes as $n) { … }'] },
      ecrire1: { kind: 'write', ask: 'Le tableau $notes associe chaque élève à sa note. Affiche « Inès : 17 », un élève par ligne, de la meilleure note à la moins bonne.', ref: `arsort($notes);
foreach ($notes as $nom => $note) {
    echo $nom . " : " . $note . "\\n";
}`, vars: { notes: { 'Léa': 14, 'Tom': 9, 'Inès': 17 } }, hidden: [{ vars: { notes: { 'Léa': 12, 'Tom': 16, 'Inès': 9 } }, label: 'Tom a la meilleure note' }, { vars: { notes: { 'Zoé': 11, 'Hugo': 15, 'Nora': 3 } } }], traps: [`ksort($notes);
foreach ($notes as $nom => $note) {
    echo $nom . " : " . $note . "\\n";
}`], ir: [{ re: /\bksort\s*\(/, text: '<code>ksort</code> trie selon les clés, ici les noms : c\'est l\'ordre alphabétique. Pour les notes en gardant les noms : <code>arsort</code>.' }, { re: /\brsort\s*\(/, text: '<code>rsort</code> perd les clés : les noms deviennent 0, 1, 2. Pour garder les noms : <code>arsort</code>.' }], hints: ['Il faut trier par valeur, décroissant, en gardant les clés.', 'arsort($notes);\nforeach ($notes as $nom => $note) { … }'] },
      ecrire2: { kind: 'write', ask: 'Affiche les 3 spécimens les plus précieux, du plus précieux au moins précieux, « Mammouth : 12000 € » (moins de 3 si la collection est plus petite).', ref: `usort($specimens, fn($a, $b) => $b['valeur'] <=> $a['valeur']);
for ($i = 0; $i < 3 && $i < count($specimens); $i++) {
    echo $specimens[$i]['nom'] . " : " . $specimens[$i]['valeur'] . " €\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', valeur: 800 }, { nom: 'Mammouth', valeur: 12000 }, { nom: 'Ammonite', valeur: 450 }, { nom: 'Aigle', valeur: 1200 }, { nom: 'Lynx', valeur: 3000 }] }, hidden: [{ vars: { specimens: [{ nom: 'Quartz', valeur: 150 }, { nom: 'Opale', valeur: 900 }] }, label: 'deux spécimens seulement' }, { vars: { specimens: [{ nom: 'Dodo', valeur: 5000 }, { nom: 'Hibou', valeur: 700 }, { nom: 'Ours', valeur: 6000 }, { nom: 'Pyrite', valeur: 80 }] } }], traps: [`usort($specimens, fn($a, $b) => $b['valeur'] <=> $a['valeur']);
for ($i = 0; $i < 3; $i++) {
    echo $specimens[$i]['nom'] . " : " . $specimens[$i]['valeur'] . " €\\n";
}`], hints: ['Trie par valeur décroissante, puis affiche les premières fiches, sans dépasser la fin de la liste.', 'usort($specimens, fn($a, $b) => $b[\'valeur\'] <=> $a[\'valeur\']);\nfor ($i = 0; $i < 3 && $i < count($specimens); $i++) { … }'] },
      ecrire3: { kind: 'write', ask: 'Affiche le classement de la classe par note décroissante : « 1. Tom (16) ». Des ex æquo ont le même rang, et le suivant reprend à sa place : 1, 2, 2, 4.', ref: `usort($eleves, fn($a, $b) => $b['note'] <=> $a['note']);
$rang = 0;
for ($i = 0; $i < count($eleves); $i++) {
    if ($i == 0 || $eleves[$i]['note'] != $eleves[$i - 1]['note']) {
        $rang = $i + 1;
    }
    echo $rang . ". " . $eleves[$i]['nom'] . " (" . $eleves[$i]['note'] . ")\\n";
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 16 }, { nom: 'Inès', note: 9 }, { nom: 'Zoé', note: 11 }] }, hidden: [{ vars: { eleves: [{ nom: 'Nora', note: 15 }, { nom: 'Sami', note: 18 }, { nom: 'Jade', note: 15 }, { nom: 'Paul', note: 12 }] }, label: 'Nora et Jade ex æquo' }, { vars: { eleves: [{ nom: 'Hugo', note: 10 }] }, label: 'un seul élève' }], traps: [`usort($eleves, fn($a, $b) => $b['note'] <=> $a['note']);
for ($i = 0; $i < count($eleves); $i++) {
    echo ($i + 1) . ". " . $eleves[$i]['nom'] . " (" . $eleves[$i]['note'] . ")\\n";
}`], hints: ['Après le tri, le rang est $i + 1, sauf quand la note est la même que celle de l\'élève d\'avant : on garde alors le rang précédent.', 'usort(…);\n$rang = 0;\nfor ($i = 0; $i < count($eleves); $i++) {\n    if ($i == 0 || $eleves[$i][\'note\'] != $eleves[$i - 1][\'note\']) { $rang = $i + 1; }\n    echo …;\n}'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  /* ——— N44 · workshop ——— */
  C.level({
    id: 'N44', chapter: 5, num: 44, title: 'Atelier : données', summary: 'cinq réparations · trois programmes qui mêlent tout', notions: ['grille', 'enregistrements', 'regrouper', 'trier'], atelier: true,
    lessons: {
      lire: {
        title: 'Lire et déboguer un tableau de tableaux',
        html: `<p>Quand un programme sur des tableaux se trompe, regarde d'abord <b>ce que contient le tableau</b> : <code>print_r($t);</code> affiche toutes les cases, avec leurs clés.</p>
<p>Puis lis chaque accès <b>crochet par crochet</b>, en te demandant à chaque étape « qu'est-ce que j'ai dans la main ? » :</p>
<ul><li><code>$eleves</code> : une liste de fiches ;</li><li><code>$eleves[1]</code> : une fiche (un tableau : echo afficherait « Array ») ;</li><li><code>$eleves[1]['notes']</code> : une liste de notes (encore un tableau) ;</li><li><code>$eleves[1]['notes'][0]</code> : un nombre, enfin quelque chose qu'echo sait afficher.</li></ul>
<p>Les erreurs de ce chapitre ont chacune leur signature :</p>
<ul><li>« Array » à l'écran : il manque un crochet ;</li><li>« Undefined array key » : ligne et colonne inversées, ou une case qui n'existe pas ;</li><li>« Undefined constant » : une clé sans guillemets ;</li><li>un seul nom par groupe : il manque <code>[]</code> ;</li><li>un nombre au lieu d'un nom : on a gardé la valeur au lieu de la fiche.</li></ul>`,
        example: `$eleves = [
    ['nom' => 'Léa', 'notes' => [12, 15]],
    ['nom' => 'Tom', 'notes' => [8, 14]],
];
print_r($eleves[1]);
echo $eleves[1]['notes'][0];`,
        tryit: 'Remplace print_r par echo : lis l\'avertissement.'
      }
    },
    steps: {
      predire: { before: 'lire', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: 'print_r($eleves[1]);', vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }] }, options: ['Array ( [nom] => Tom [note] => 9 )', 'Tom 9', 'Array', 'Array ( [0] => Tom [1] => 9 )'], hints: ['print_r montre un tableau case par case, avec ses clés : ici la fiche de Tom.'] },
      suivre: { kind: 'trace', ask: 'On cherche la plus grande valeur de chaque salle. Remplis $k et $m après chaque passage sur la ligne $m = $max[$k];.', code: `$max = [];
foreach ($specimens as $s) {
    $k = $s['salle'];
    if (!isset($max[$k]) || $s['valeur'] > $max[$k]) {
        $max[$k] = $s['valeur'];
    }
    $m = $max[$k];
}`, vars: { specimens: [{ salle: 'R1', valeur: 800 }, { salle: 'R2', valeur: 1200 }, { salle: 'R1', valeur: 2500 }, { salle: 'R2', valeur: 300 }, { salle: 'R1', valeur: 450 }] }, after: 7, watch: ['$k', '$m'], hints: ['Chaque salle a son propre maximum : une salle qu\'on voit pour la première fois prend la valeur du spécimen.'] },
      comprendre: {},
      reparer1: { kind: 'repair', ask: 'Ce programme doit afficher la colonne $c de la grille, une case par ligne, de haut en bas. Il affiche une ligne : répare-le.', starter: `for ($l = 0; $l < count($g); $l++) {
    echo $g[$c][$l] . "\\n";
}`, ref: `for ($l = 0; $l < count($g); $l++) {
    echo $g[$l][$c] . "\\n";
}`, vars: { g: [[4, 7, 1], [2, 9, 5], [8, 3, 6]], c: 1 }, hidden: [{ vars: { g: [[1, 2, 3, 4, 5], [6, 7, 8, 9, 10]], c: 4 }, label: '2 lignes, 5 colonnes, $c = 4' }, { vars: { c: 0 } }], traps: [`for ($l = 0; $l < count($g[0]); $l++) {
    echo $g[$l][$c] . "\\n";
}`], ir: [{ re: /\$g\s*\[\s*\$c\s*\]\s*\[\s*\$l\s*\]/, text: 'Ligne d\'abord, colonne ensuite : <code>$g[$l][$c]</code>.' }, { re: /\$l\s*<\s*count\s*\(\s*\$g\s*\[\s*0\s*\]\s*\)/, text: 'On descend la colonne : une case par <b>ligne</b>. La boucle va jusqu\'à <code>count($g)</code>, le nombre de lignes.' }], hints: ['Dans $g[…][…], quel crochet choisit la ligne ?', 'echo $g[$l][$c] . "\\n";'] },
      reparer2: { kind: 'repair', ask: 'Ce programme doit afficher le nom des élèves qui ont au moins 10. PHP s\'arrête sur une erreur : répare-le.', starter: `foreach ($eleves as $e) {
    if ($e[note] >= 10) {
        echo $e[nom] . "\\n";
    }
}`, ref: `foreach ($eleves as $e) {
    if ($e['note'] >= 10) {
        echo $e['nom'] . "\\n";
    }
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 12 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 10 }, { nom: 'Nora', note: 9 }] }, label: 'Hugo a 10 pile' }, { vars: { eleves: [] }, label: 'une classe vide' }], traps: [`foreach ($eleves as $e) {
    if ($e['note'] > 10) {
        echo $e['nom'] . "\\n";
    }
}`], ir: [{ re: /\$e\s*\[\s*(nom|note)\s*\]/, text: 'Une clé s\'écrit entre guillemets : <code>$e[\'note\']</code>, <code>$e[\'nom\']</code>.' }], hints: ['Lis l\'erreur : « Undefined constant ». Comment s\'écrit une clé ?', 'if ($e[\'note\'] >= 10) {\n    echo $e[\'nom\'] . "\\n";\n}'] },
      reparer3: { kind: 'repair', ask: 'Ce programme doit afficher le nom de chaque spécimen de la salle $salle, un par ligne. Il affiche « Array » : répare-le.', starter: `for ($i = 0; $i < count($specimens); $i++) {
    if ($specimens[$i]['salle'] == $salle) {
        echo $specimens[$i] . "\\n";
    }
}`, ref: `for ($i = 0; $i < count($specimens); $i++) {
    if ($specimens[$i]['salle'] == $salle) {
        echo $specimens[$i]['nom'] . "\\n";
    }
}`, vars: { specimens: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Aigle', salle: 'R2' }, { nom: 'Ammonite', salle: 'R1' }, { nom: 'Hibou', salle: 'R2' }], salle: 'R1' }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', salle: 'R3' }, { nom: 'Quartz', salle: 'R1' }, { nom: 'Opale', salle: 'R1' }, { nom: 'Dodo', salle: 'R2' }, { nom: 'Pyrite', salle: 'R1' }] }, label: 'cinq spécimens, le dernier en R1' }, { vars: { salle: 'R2' } }], traps: [`for ($i = 0; $i < 4; $i++) {
    if ($specimens[$i]['salle'] == $salle) {
        echo $specimens[$i]['nom'] . "\\n";
    }
}`], ir: [{ out: function (o) { return /Array/.test(o); }, text: '<code>$specimens[$i]</code> est une fiche entière : echo n\'écrit que « Array ». Il manque le deuxième crochet : <code>$specimens[$i][\'nom\']</code>.' }], hints: ['$specimens[$i] est toute la fiche : quelle case veux-tu afficher ?', 'echo $specimens[$i][\'nom\'] . "\\n";'] },
      reparer4: { kind: 'repair', ask: 'Ce programme doit afficher le spécimen le plus précieux, « Mammouth : 12000 € » (le premier en cas d\'égalité). Il n\'affiche qu\'un nombre : répare-le.', starter: `$max = 0;
foreach ($specimens as $s) {
    if ($s['valeur'] > $max) {
        $max = $s['valeur'];
    }
}
echo $max;`, ref: `$best = $specimens[0];
foreach ($specimens as $s) {
    if ($s['valeur'] > $best['valeur']) {
        $best = $s;
    }
}
echo $best['nom'] . " : " . $best['valeur'] . " €";`, vars: { specimens: [{ nom: 'Trilobite', valeur: 800 }, { nom: 'Mammouth', valeur: 12000 }, { nom: 'Ammonite', valeur: 450 }] }, hidden: [{ vars: { specimens: [{ nom: 'Caillou', valeur: 0 }, { nom: 'Galet', valeur: 0 }] }, label: 'des spécimens sans valeur (0 €)' }, { vars: { specimens: [{ nom: 'Lynx', valeur: 3000 }, { nom: 'Dodo', valeur: 3000 }] }, label: 'deux spécimens ex æquo' }], traps: [`$best = ['nom' => '', 'valeur' => 0];
foreach ($specimens as $s) {
    if ($s['valeur'] > $best['valeur']) {
        $best = $s;
    }
}
echo $best['nom'] . " : " . $best['valeur'] . " €";`, `$best = $specimens[0];
foreach ($specimens as $s) {
    if ($s['valeur'] >= $best['valeur']) {
        $best = $s;
    }
}
echo $best['nom'] . " : " . $best['valeur'] . " €";`], ir: [{ re: /\[\s*'nom'\s*=>\s*''/, text: 'Une fausse fiche de départ peut gagner : si tous les spécimens valent 0, elle n\'est jamais remplacée. Pars de <code>$specimens[0]</code>.' }], hints: ['Garde la fiche entière du meilleur, en partant de la première fiche.', '$best = $specimens[0];\nforeach ($specimens as $s) {\n    if ($s[\'valeur\'] > $best[\'valeur\']) { $best = $s; }\n}\necho $best[\'nom\'] . " : " . … . " €";'] },
      reparer5: { kind: 'repair', ask: 'Ce programme doit afficher le nombre de spécimens de chaque salle, « R1 : 3 », dans l\'ordre où les salles apparaissent. Il affiche 1 partout : répare-le.', starter: `$nb = [];
foreach ($specimens as $s) {
    $nb[$s['salle']] = 1;
}
foreach ($nb as $salle => $n) {
    echo $salle . " : " . $n . "\\n";
}`, ref: `$nb = [];
foreach ($specimens as $s) {
    $nb[$s['salle']] = ($nb[$s['salle']] ?? 0) + 1;
}
foreach ($nb as $salle => $n) {
    echo $salle . " : " . $n . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Aigle', salle: 'R2' }, { nom: 'Ammonite', salle: 'R1' }, { nom: 'Hibou', salle: 'R2' }, { nom: 'Lynx', salle: 'R1' }] }, hidden: [{ vars: { specimens: [{ nom: 'Quartz', salle: 'R5' }, { nom: 'Opale', salle: 'R2' }, { nom: 'Dodo', salle: 'R5' }] }, label: 'R5 d\'abord' }, { vars: { specimens: [] }, label: 'une collection vide' }], traps: [`$nb = ['R1' => 0, 'R2' => 0];
foreach ($specimens as $s) {
    $nb[$s['salle']]++;
}
foreach ($nb as $salle => $n) {
    echo $salle . " : " . $n . "\\n";
}`], ir: [{ re: /\$nb\s*=\s*\[\s*'R/, text: 'Ne crée pas les salles à la main : laisse <code>$nb</code> vide et pars de 0 avec <code>?? 0</code>.' }], hints: ['À chaque spécimen, le compteur de sa salle doit augmenter de 1, pas valoir 1.', '$nb[$s[\'salle\']] = ($nb[$s[\'salle\']] ?? 0) + 1;'] },
      ecrire1: { kind: 'write', ask: 'Bulletin : pour chaque élève, affiche « Léa : 12 » avec sa moyenne arrondie à 1 décimale, ou « Inès : pas de note ». Puis une dernière ligne « Meilleure moyenne : Léa » (le premier en cas d\'égalité ; un élève sans note ne compte pas ; « personne » si aucun élève n\'a de note).', ref: `$best = null;
$bestMoy = 0;
foreach ($eleves as $e) {
    if (count($e['notes']) == 0) {
        echo $e['nom'] . " : pas de note\\n";
    } else {
        $moy = round(array_sum($e['notes']) / count($e['notes']), 1);
        echo $e['nom'] . " : " . $moy . "\\n";
        if ($best === null || $moy > $bestMoy) {
            $best = $e['nom'];
            $bestMoy = $moy;
        }
    }
}
echo "Meilleure moyenne : " . ($best ?? "personne");`, vars: { eleves: [{ nom: 'Léa', notes: [12, 15, 9] }, { nom: 'Tom', notes: [8, 14] }, { nom: 'Inès', notes: [] }] }, hidden: [{ vars: { eleves: [{ nom: 'Nora', notes: [] }, { nom: 'Sami', notes: [15, 16] }, { nom: 'Jade', notes: [16, 15] }] }, label: 'Sami et Jade ex æquo' }, { vars: { eleves: [{ nom: 'Hugo', notes: [] }] }, label: 'personne n\'a de note' }], traps: [`$best = null;
$bestMoy = 0;
foreach ($eleves as $e) {
    if (count($e['notes']) == 0) {
        echo $e['nom'] . " : pas de note\\n";
    } else {
        $moy = round(array_sum($e['notes']) / count($e['notes']), 1);
        echo $e['nom'] . " : " . $moy . "\\n";
        if ($best === null || $moy >= $bestMoy) {
            $best = $e['nom'];
            $bestMoy = $moy;
        }
    }
}
echo "Meilleure moyenne : " . ($best ?? "personne");`], hints: ['Une boucle sur les élèves : la liste vide d\'abord, sinon la moyenne ; et, en même temps, le nom du meilleur (null tant qu\'aucun élève n\'a de note).', '$best = null;\n$bestMoy = 0;\nforeach ($eleves as $e) {\n    if (count($e[\'notes\']) == 0) { … }\n    else { $moy = …; echo …; if ($best === null || $moy > $bestMoy) { … } }\n}\necho "Meilleure moyenne : " . ($best ?? "personne");'] },
      ecrire2: { kind: 'write', ask: 'Pour chaque salle, dans l\'ordre alphabétique des salles, affiche le nombre de spécimens et leur valeur totale : « R1 : 2 spécimen(s), 1250 € ».', ref: `$nb = [];
$total = [];
foreach ($specimens as $s) {
    $k = $s['salle'];
    $nb[$k] = ($nb[$k] ?? 0) + 1;
    $total[$k] = ($total[$k] ?? 0) + $s['valeur'];
}
ksort($nb);
foreach ($nb as $salle => $n) {
    echo $salle . " : " . $n . " spécimen(s), " . $total[$salle] . " €\\n";
}`, vars: { specimens: [{ salle: 'R1', valeur: 800 }, { salle: 'R2', valeur: 1200 }, { salle: 'R1', valeur: 450 }, { salle: 'R3', valeur: 2500 }] }, hidden: [{ vars: { specimens: [{ salle: 'R3', valeur: 100 }, { salle: 'R1', valeur: 200 }, { salle: 'R3', valeur: 50 }] }, label: 'R3 rencontrée avant R1' }, { vars: { specimens: [] }, label: 'une collection vide' }], traps: [`$nb = [];
$total = [];
foreach ($specimens as $s) {
    $k = $s['salle'];
    $nb[$k] = ($nb[$k] ?? 0) + 1;
    $total[$k] = ($total[$k] ?? 0) + $s['valeur'];
}
foreach ($nb as $salle => $n) {
    echo $salle . " : " . $n . " spécimen(s), " . $total[$salle] . " €\\n";
}`], hints: ['Deux tableaux par salle (un compteur, un total), puis ksort pour l\'ordre alphabétique des salles.', '$nb = [];\n$total = [];\nforeach ($specimens as $s) { … }\nksort($nb);\nforeach ($nb as $salle => $n) { … $total[$salle] … }'] },
      ecrire3: { kind: 'write', ask: 'La grille $g compte les visiteurs : une ligne par jour (les noms dans $jours), une colonne par heure. Affiche le total de chaque jour, « lundi : 50 », puis « Le plus fréquenté : mardi » (le premier en cas d\'égalité).', ref: `$best = 0;
$bestTotal = array_sum($g[0]);
for ($j = 0; $j < count($g); $j++) {
    $total = 0;
    for ($h = 0; $h < count($g[$j]); $h++) {
        $total = $total + $g[$j][$h];
    }
    echo $jours[$j] . " : " . $total . "\\n";
    if ($total > $bestTotal) {
        $best = $j;
        $bestTotal = $total;
    }
}
echo "Le plus fréquenté : " . $jours[$best];`, vars: { jours: ['lundi', 'mardi', 'mercredi'], g: [[12, 30, 8], [20, 25, 15], [5, 10, 9]] }, hidden: [{ vars: { jours: ['jeudi', 'vendredi'], g: [[10, 10, 10, 10, 10], [20, 5, 5, 10, 10]] }, label: '2 jours, 5 heures, ex æquo' }, { vars: { g: [[0, 0, 0], [1, 0, 0], [0, 0, 4]] } }], traps: [`$best = 0;
$bestTotal = array_sum($g[0]);
for ($j = 0; $j < count($g); $j++) {
    $total = 0;
    for ($h = 0; $h < count($g); $h++) {
        $total = $total + $g[$j][$h];
    }
    echo $jours[$j] . " : " . $total . "\\n";
    if ($total > $bestTotal) {
        $best = $j;
        $bestTotal = $total;
    }
}
echo "Le plus fréquenté : " . $jours[$best];`, `$best = 0;
$bestTotal = array_sum($g[0]);
for ($j = 0; $j < count($g); $j++) {
    $total = array_sum($g[$j]);
    echo $jours[$j] . " : " . $total . "\\n";
    if ($total >= $bestTotal) {
        $best = $j;
        $bestTotal = $total;
    }
}
echo "Le plus fréquenté : " . $jours[$best];`], hints: ['Une boucle sur les jours : le total de la ligne (remis à 0 à chaque jour), affiché, puis comparé au meilleur total.', '$best = 0;\n$bestTotal = array_sum($g[0]);\nfor ($j = 0; $j < count($g); $j++) {\n    $total = …;\n    echo $jours[$j] . " : " . $total . "\\n";\n    if ($total > $bestTotal) { … }\n}\necho "Le plus fréquenté : " . $jours[$best];'] },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  C.mastery({
    id: 'M18', chapter: 5, title: 'Construire et trier', covers: ['N42', 'N43', 'N44'], draw: 3,
    pool: [
      { ask: 'Construis une grille $g de $nl lignes et $nc colonnes remplie de 0, puis affiche-la, une ligne par ligne, les cases séparées par un espace.', ref: `$g = [];
for ($l = 0; $l < $nl; $l++) {
    $ligne = [];
    for ($c = 0; $c < $nc; $c++) {
        $ligne[] = 0;
    }
    $g[] = $ligne;
}
foreach ($g as $ligne) {
    echo implode(" ", $ligne) . "\\n";
}`, vars: { nl: 3, nc: 3 }, hidden: [{ vars: { nl: 2, nc: 5 }, label: '2 lignes, 5 colonnes' }, { vars: { nl: 1, nc: 4 } }], traps: [`$g = [];
for ($l = 0; $l < $nl; $l++) {
    $ligne = [];
    for ($c = 0; $c < $nl; $c++) {
        $ligne[] = 0;
    }
    $g[] = $ligne;
}
foreach ($g as $ligne) {
    echo implode(" ", $ligne) . "\\n";
}`], hints: ['Une ligne neuve par tour de la boucle des lignes, $nc cases dans chaque ligne.', '$g = [];\nfor ($l = 0; $l < $nl; $l++) {\n    $ligne = [];\n    for ($c = 0; $c < $nc; $c++) { $ligne[] = 0; }\n    $g[] = $ligne;\n}'] },
      { ask: 'Assemble $noms et $annees en fiches (nom, annee), puis affiche le nom des spécimens entrés avant 1950, un par ligne.', ref: `$specimens = [];
for ($i = 0; $i < count($noms); $i++) {
    $specimens[] = ['nom' => $noms[$i], 'annee' => $annees[$i]];
}
foreach ($specimens as $s) {
    if ($s['annee'] < 1950) {
        echo $s['nom'] . "\\n";
    }
}`, vars: { noms: ['Trilobite', 'Loup gris', 'Ammonite'], annees: [1911, 1954, 1898] }, hidden: [{ vars: { noms: ['Lynx', 'Quartz'], annees: [1930, 2003] }, label: 'deux spécimens' }, { vars: { noms: ['Dodo', 'Opale', 'Hibou', 'Pyrite', 'Ours'], annees: [1880, 1975, 1920, 2011, 1949] }, label: 'cinq spécimens' }], traps: [`$specimens = [];
for ($i = 0; $i < 3; $i++) {
    $specimens[] = ['nom' => $noms[$i], 'annee' => $annees[$i]];
}
foreach ($specimens as $s) {
    if ($s['annee'] < 1950) {
        echo $s['nom'] . "\\n";
    }
}`], hints: ['Le même $i lit le nom et l\'année ; puis un foreach avec un if.', '$specimens[] = [\'nom\' => $noms[$i], \'annee\' => $annees[$i]];'] },
      { ask: 'Construis le plan des places : $g[$l][$c] vaut le numéro de la place, en comptant de 1 ligne après ligne ($l × $nc + $c + 1). Affiche-le, une ligne par ligne, les numéros séparés par un espace.', ref: `$g = [];
for ($l = 0; $l < $nl; $l++) {
    for ($c = 0; $c < $nc; $c++) {
        $g[$l][$c] = $l * $nc + $c + 1;
    }
}
foreach ($g as $ligne) {
    echo implode(" ", $ligne) . "\\n";
}`, vars: { nl: 3, nc: 3 }, hidden: [{ vars: { nl: 2, nc: 5 }, label: '2 lignes, 5 colonnes' }, { vars: { nl: 4, nc: 2 } }], traps: [`$g = [];
for ($l = 0; $l < $nl; $l++) {
    for ($c = 0; $c < $nc; $c++) {
        $g[$l][$c] = $l * $nl + $c + 1;
    }
}
foreach ($g as $ligne) {
    echo implode(" ", $ligne) . "\\n";
}`], hints: ['Chaque ligne complète contient $nc places : la ligne $l commence après $l × $nc places.', 'for ($l = 0; $l < $nl; $l++) {\n    for ($c = 0; $c < $nc; $c++) {\n        $g[$l][$c] = $l * $nc + $c + 1;\n    }\n}'] },
      { ask: 'Affiche les noms de $noms dans l\'ordre alphabétique, séparés par une virgule et une espace.', ref: `sort($noms);
echo implode(", ", $noms);`, vars: { noms: ['Inès', 'Léa', 'Tom'] }, hidden: [{ vars: { noms: ['Zoé', 'Hugo', 'Nora'] }, label: 'une liste dans le désordre' }, { vars: { noms: ['Sami'] } }], traps: ['echo implode(", ", $noms);'], ir: [{ re: /=\s*sort\s*\(/, text: '<code>sort</code> trie la liste elle-même et renvoie seulement <code>true</code> : écris <code>sort($noms);</code> seul.' }], hints: ['sort trie la liste elle-même ; implode l\'assemble ensuite.', 'sort($noms);\necho implode(", ", $noms);'] },
      { ask: 'Trie les spécimens par année d\'entrée croissante et affiche « 1898 Ammonite », un par ligne.', ref: `usort($specimens, fn($a, $b) => $a['annee'] <=> $b['annee']);
foreach ($specimens as $s) {
    echo $s['annee'] . " " . $s['nom'] . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', annee: 1954 }, { nom: 'Ammonite', annee: 1898 }, { nom: 'Lynx', annee: 1911 }] }, hidden: [{ vars: { specimens: [{ nom: 'Quartz', annee: 1930 }, { nom: 'Dodo', annee: 1880 }, { nom: 'Opale', annee: 2001 }, { nom: 'Hibou', annee: 1999 }] } }, { vars: { specimens: [{ nom: 'Ours', annee: 1975 }] }, label: 'un seul spécimen' }], traps: [`usort($specimens, fn($a, $b) => $a['nom'] <=> $b['nom']);
foreach ($specimens as $s) {
    echo $s['annee'] . " " . $s['nom'] . "\\n";
}`], hints: ['usort avec une comparaison des cases annee, $a à gauche pour un ordre croissant.', 'usort($specimens, fn($a, $b) => $a[\'annee\'] <=> $b[…]);\nforeach ($specimens as $s) { … }'] },
      { ask: 'Affiche les 3 meilleures notes de la liste $notes, de la plus haute à la plus basse, une par ligne (moins de 3 si la liste est plus courte).', ref: `rsort($notes);
for ($i = 0; $i < 3 && $i < count($notes); $i++) {
    echo $notes[$i] . "\\n";
}`, vars: { notes: [12, 18, 9, 15, 11] }, hidden: [{ vars: { notes: [8, 14] }, label: 'deux notes seulement' }, { vars: { notes: [10, 20, 20, 5] }, label: 'deux 20' }], traps: [`rsort($notes);
for ($i = 0; $i < 3; $i++) {
    echo $notes[$i] . "\\n";
}`], hints: ['rsort, puis les premières cases sans dépasser la fin de la liste.', 'rsort($notes);\nfor ($i = 0; $i < 3 && $i < count($notes); $i++) { … }'] },
      { ask: 'Le tableau $visites associe chaque salle à son nombre de visiteurs. Affiche « R2 : 340 », une salle par ligne, de la plus visitée à la moins visitée.', ref: `arsort($visites);
foreach ($visites as $salle => $n) {
    echo $salle . " : " . $n . "\\n";
}`, vars: { visites: { R2: 120, R1: 340, R3: 90 } }, hidden: [{ vars: { visites: { R1: 50, R2: 400, R3: 210 } }, label: 'R2 la plus visitée' }, { vars: { visites: { R4: 10, R5: 70 } } }], traps: [`ksort($visites);
foreach ($visites as $salle => $n) {
    echo $salle . " : " . $n . "\\n";
}`], hints: ['Trier par valeur, décroissant, en gardant les clés.', 'arsort($visites);\nforeach ($visites as $salle => $n) { … }'] },
      { ask: 'Affiche le classement par note décroissante, « 1. Inès (17) ». Des ex æquo ont le même rang, et le suivant reprend à sa place : 1, 2, 2, 4.', ref: `usort($eleves, fn($a, $b) => $b['note'] <=> $a['note']);
$rang = 0;
for ($i = 0; $i < count($eleves); $i++) {
    if ($i == 0 || $eleves[$i]['note'] != $eleves[$i - 1]['note']) {
        $rang = $i + 1;
    }
    echo $rang . ". " . $eleves[$i]['nom'] . " (" . $eleves[$i]['note'] . ")\\n";
}`, vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Inès', note: 17 }, { nom: 'Tom', note: 9 }] }, hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 12 }, { nom: 'Nora', note: 12 }, { nom: 'Sami', note: 8 }] }, label: 'Hugo et Nora ex æquo' }, { vars: { eleves: [{ nom: 'Jade', note: 20 }, { nom: 'Paul', note: 11 }, { nom: 'Emma', note: 11 }, { nom: 'Zoé', note: 11 }] } }], traps: [`usort($eleves, fn($a, $b) => $b['note'] <=> $a['note']);
for ($i = 0; $i < count($eleves); $i++) {
    echo ($i + 1) . ". " . $eleves[$i]['nom'] . " (" . $eleves[$i]['note'] . ")\\n";
}`], hints: ['Le rang est $i + 1, sauf si la note est la même que celle de l\'élève d\'avant.', 'if ($i == 0 || $eleves[$i][\'note\'] != $eleves[$i - 1][\'note\']) {\n    $rang = $i + 1;\n}'] },
      { ask: 'Range les spécimens par salle, puis affiche chaque salle dans l\'ordre alphabétique avec ses spécimens : « R1 : Trilobite, Ammonite ».', ref: `$p = [];
foreach ($specimens as $s) {
    $p[$s['salle']][] = $s['nom'];
}
ksort($p);
foreach ($p as $salle => $noms) {
    echo $salle . " : " . implode(", ", $noms) . "\\n";
}`, vars: { specimens: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Aigle', salle: 'R2' }, { nom: 'Ammonite', salle: 'R1' }] }, hidden: [{ vars: { specimens: [{ nom: 'Lynx', salle: 'R4' }, { nom: 'Quartz', salle: 'R2' }, { nom: 'Opale', salle: 'R4' }] }, label: 'R4 rencontrée avant R2' }, { vars: { specimens: [] }, label: 'une collection vide' }], traps: [`$p = [];
foreach ($specimens as $s) {
    $p[$s['salle']][] = $s['nom'];
}
foreach ($p as $salle => $noms) {
    echo $salle . " : " . implode(", ", $noms) . "\\n";
}`], hints: ['Regroupe avec [], puis ksort trie les salles.', '$p[$s[\'salle\']][] = $s[\'nom\'];\n…\nksort($p);'] },
      { ask: 'Assemble $noms et $notes en fiches, trie-les par note décroissante et affiche le nom du premier (le premier de la liste en cas d\'égalité).', ref: `$eleves = [];
for ($i = 0; $i < count($noms); $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
usort($eleves, fn($a, $b) => $b['note'] <=> $a['note']);
echo $eleves[0]['nom'];`, vars: { noms: ['Léa', 'Tom', 'Inès'], notes: [14, 9, 17] }, hidden: [{ vars: { noms: ['Nora', 'Sami', 'Jade', 'Paul'], notes: [11, 8, 13, 19] }, label: 'quatre élèves, le meilleur en dernier' }, { vars: { noms: ['Hugo', 'Zoé'], notes: [15, 15] }, label: 'deux ex æquo' }], traps: [`$eleves = [];
for ($i = 0; $i < 3; $i++) {
    $eleves[] = ['nom' => $noms[$i], 'note' => $notes[$i]];
}
usort($eleves, fn($a, $b) => $b['note'] <=> $a['note']);
echo $eleves[0]['nom'];`], hints: ['Construire, trier, lire la première fiche.', '$eleves[] = [\'nom\' => $noms[$i], \'note\' => $notes[$i]];\n…\nusort($eleves, fn($a, $b) => $b[\'note\'] <=> $a[\'note\']);\necho $eleves[0][\'nom\'];'] }
    ]
  });

  C.flash({
    id: 'E15', chapter: 5, title: 'Tableaux dans des tableaux', covers: ['N40', 'N41', 'N42', 'N43', 'N44'],
    pool: [
      { code: 'echo $e[\'notes\'][1];', vars: { e: { nom: 'Léa', notes: [12, 15, 9] } }, options: ['15', '12', '9', 'Léa'] },
      { code: 'echo count($e[\'notes\']);', vars: { e: { nom: 'Léa', notes: [12, 15, 9] } }, options: ['3', '2', '1', '36'] },
      { code: 'echo $c[1][\'notes\'][0];', vars: { c: [{ nom: 'Léa', notes: [12, 15] }, { nom: 'Tom', notes: [8, 14] }] }, options: ['8', '12', '15', '14'] },
      { code: 'echo array_sum($c[0][\'notes\']);', vars: { c: [{ nom: 'Léa', notes: [12, 15] }, { nom: 'Tom', notes: [8, 14] }] }, options: ['27', '22', '12', '49'] },
      { code: `$p = [];
foreach ($s as $x) {
    $p[$x['salle']][] = $x['nom'];
}
echo count($p['R1']);`, vars: { s: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Aigle', salle: 'R2' }, { nom: 'Ammonite', salle: 'R1' }] }, options: ['2', '1', '3', '0'] },
      { code: `$p = [];
foreach ($s as $x) {
    $p[$x['salle']] = $x['nom'];
}
echo $p['R1'];`, vars: { s: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Aigle', salle: 'R2' }, { nom: 'Ammonite', salle: 'R1' }] }, options: ['Ammonite', 'Trilobite', 'Aigle', 'Trilobite, Ammonite'] },
      { code: `$p = [];
foreach ($s as $x) {
    $p[$x['salle']][] = $x['nom'];
}
echo count($p);`, vars: { s: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Aigle', salle: 'R2' }, { nom: 'Ammonite', salle: 'R1' }] }, options: ['2', '3', '1', '4'] },
      { code: `$n = [];
foreach ($s as $x) {
    $n[$x['classe']] = ($n[$x['classe']] ?? 0) + 1;
}
echo $n['Fossiles'];`, vars: { s: [{ classe: 'Fossiles' }, { classe: 'Oiseaux' }, { classe: 'Fossiles' }, { classe: 'Fossiles' }] }, options: ['3', '1', '4', '2'] },
      { code: `$g = [];
for ($l = 0; $l < 2; $l++) {
    for ($c = 0; $c < 3; $c++) {
        $g[$l][$c] = $l * 10 + $c;
    }
}
echo $g[1][2];`, options: ['12', '21', '3', '2'] },
      { code: `$t = [5, 2, 8];
sort($t);
echo $t[0];`, options: ['2', '5', '8', '1'] },
      { code: `$t = [5, 2, 8];
rsort($t);
echo implode(" ", $t);`, options: ['8 5 2', '2 5 8', '5 2 8', '1'] },
      { code: `$n = ['Léa' => 14, 'Tom' => 16];
arsort($n);
foreach ($n as $k => $v) {
    echo $k . " ";
}`, options: ['Tom Léa', 'Léa Tom', '16 14', '0 1'] },
      { code: `usort($e, fn($a, $b) => $b['note'] <=> $a['note']);
echo $e[0]['nom'];`, vars: { e: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] }, options: ['Inès', 'Tom', 'Léa', '17'] },
      { code: 'echo 3 <=> 5;', options: ['-1', '1', '0', '2'] },
      { code: `$t = [3, 1, 2];
$r = sort($t);
echo $r;`, options: ['1', '1 2 3', '123', '3'] },
      { code: `$e = [];
$e[] = ['nom' => 'Léa'];
$e[] = ['nom' => 'Tom'];
echo count($e) . $e[1]['nom'];`, options: ['2Tom', '1Tom', '2Léa', '4Tom'] }
    ]
  });
})(window.PPPContent);
