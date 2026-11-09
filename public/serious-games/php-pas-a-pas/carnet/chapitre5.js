/* PHP pas à pas — the notebook, chapter 5 « Tableaux à deux dimensions »: one fiche per notion of the
   chapter and the PHP functions it introduces (content.js, « Mon carnet »). */
(function (C) {
  'use strict';

  C.fiche({
    notion: 'grille',
    rule: '$g = [\n    [case, case, case],   // ligne 0\n    [case, case, case],   // ligne 1\n];\necho $g[$ligne][$colonne];',
    points: [
      'Une grille est une **liste de listes** : chaque case de la grande liste est une ligne.',
      '`$g[1]` est toute la ligne 1 ; `$g[1][2]` est la case 2 de cette ligne. Les deux numéros commencent à **0**.',
      '`count($g)` compte les **lignes**, `count($g[0])` les **colonnes**. La case en bas à droite : `$g[count($g) - 1][count($g[0]) - 1]`.'
    ],
    example: '$edt = [\n    [\'Maths\', \'Anglais\', \'PHP\'],\n    [\'SQL\', \'Sport\', \'Réseau\'],\n];\necho $edt[1][0] . "\\n";\necho count($edt) . " lignes, " . count($edt[0]) . " colonnes";',
    traps: [
      '**Ligne d\'abord, colonne ensuite** : `$edt[1][0]` (mardi, 1er créneau) n\'est pas `$edt[0][1]` (lundi, 2e créneau). Teste sur une grille qui n\'est pas carrée, l\'erreur s\'y voit tout de suite.',
      '`count($g)` ne compte pas les colonnes : pour elles, compte les cases d\'une ligne, `count($g[0])`.'
    ],
    fns: ['count']
  });

  C.fiche({
    notion: 'parcours2d',
    rule: 'for ($l = 0; $l < count($g); $l++) {\n    for ($c = 0; $c < count($g[$l]); $c++) {\n        // la case $g[$l][$c]\n    }\n}\n\nforeach ($g as $ligne) {\n    foreach ($ligne as $case) {\n        // la case $case\n    }\n}',
    points: [
      'Deux boucles **l\'une dans l\'autre** : celle des lignes à l\'extérieur, celle des cases de la ligne à l\'intérieur.',
      'La boucle intérieure fait **tous ses tours** à chaque tour de l\'extérieure : 2 lignes de 5 cases, 10 passages.',
      'Un total de **toute la grille** se prépare avant les deux boucles et s\'affiche après les deux.',
      'Un total **par ligne** se remet à 0 au début de la boucle des lignes et s\'affiche après la boucle intérieure.'
    ],
    example: '$total = 0;\nforeach ($g as $ligne) {\n    $somme = 0;\n    foreach ($ligne as $case) {\n        $somme = $somme + $case;\n        $total = $total + $case;\n    }\n    echo $somme . "\\n";\n}\necho "Total : " . $total;',
    vars: { g: [[2, 4, 6], [1, 1, 1]] },
    traps: [
      'La boucle des colonnes s\'arrête à `count($g[$l])`, pas à `count($g)` : sur une grille de 2 lignes et 5 colonnes, il manquerait 3 colonnes.',
      '`$somme = 0;` placé avant les deux boucles : la ligne 1 s\'ajoute au total de la ligne 0. Remets-le à 0 **dans** la boucle des lignes.'
    ],
    fns: ['count']
  });

  C.fiche({
    notion: 'enregistrements',
    rule: '$eleves = [\n    [\'nom\' => valeur, \'note\' => valeur],   // fiche 0\n    [\'nom\' => valeur, \'note\' => valeur],   // fiche 1\n];\necho $eleves[$i][\'nom\'];\n\nforeach ($eleves as $e) {\n    echo $e[\'nom\'];\n}',
    points: [
      'Une liste d\'enregistrements est une **liste de fiches** : une case par élève, une fiche entière dans chaque case.',
      'Le premier crochet est un **numéro** (quelle fiche, à partir de 0), le second une **clé entre guillemets** (quelle information) : `$eleves[1][\'nom\']`.',
      'Dans `foreach ($eleves as $e)`, `$e` reçoit **une fiche entière** à chaque tour : `$e[\'note\']` remplace `$eleves[$i][\'note\']`.'
    ],
    example: '$eleves = [\n    [\'nom\' => \'Léa\', \'note\' => 14],\n    [\'nom\' => \'Tom\', \'note\' => 9],\n];\necho $eleves[1][\'nom\'] . "\\n";\nforeach ($eleves as $e) {\n    echo $e[\'nom\'] . " : " . $e[\'note\'] . "\\n";\n}',
    traps: [
      '`echo $e;` affiche « Array » et un avertissement : une fiche n\'est pas un texte. Choisis une case : `echo $e[\'nom\'];`.',
      '`$e[nom]` sans guillemets : PHP cherche une constante et s\'arrête sur une erreur. Une clé s\'écrit **entre guillemets**.',
      '`$eleves[\'note\']` n\'existe pas : la liste n\'a que des cases 0, 1, 2… La clé `\'note\'` est dans chaque fiche.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'filtrer2d',
    rule: '$total = 0;\n$n = 0;\nforeach ($fiches as $f) {\n    if ($f[\'critere\'] == valeur) {\n        $total = $total + $f[\'nombre\'];\n        $n++;\n    }\n}\nif ($n == 0) {\n    echo "Aucun";\n} else {\n    echo $total / $n;\n}',
    points: [
      'Le `if` teste **une case de la fiche** (`$s[\'salle\']`), jamais la fiche entière `$s`.',
      'Le total et le compteur se préparent **avant** la boucle et se lisent **après**.',
      'La moyenne se divise par `$n`, le nombre de fiches **gardées**, pas par `count($specimens)`, qui compte toute la collection.'
    ],
    example: '$total = 0;\n$n = 0;\nforeach ($specimens as $s) {\n    if ($s[\'salle\'] == \'R1\') {\n        $total = $total + $s[\'valeur\'];\n        $n++;\n    }\n}\necho $n . " spécimens, moyenne " . round($total / $n, 1);',
    vars: { specimens: [{ nom: 'Trilobite', salle: 'R1', valeur: 800 }, { nom: 'Loup gris', salle: 'R3', valeur: 2500 }, { nom: 'Ammonite', salle: 'R1', valeur: 450 }] },
    traps: [
      'Un `else` dans la boucle affiche « Aucun » à chaque fiche écartée : décide « Aucun » **après** la boucle, avec `$n == 0`.',
      'Si aucune fiche n\'est gardée, `$total / $n` divise par zéro (« Division by zero ») : teste `$n == 0` d\'abord.'
    ],
    fns: ['count', 'round']
  });

  C.fiche({
    notion: 'meilleur',
    rule: '$meilleur = $fiches[0];\nforeach ($fiches as $f) {\n    if ($f[\'nombre\'] > $meilleur[\'nombre\']) {\n        $meilleur = $f;\n    }\n}\necho $meilleur[\'nom\'];',
    points: [
      'On **compare une case** (`$e[\'note\'] > $meilleur[\'note\']`) mais on **garde toute la fiche** (`$meilleur = $e`) : après la boucle, on a le nom et la note.',
      'On part de la **première fiche**, `$eleves[0]`. Si la liste peut être vide, teste `count($eleves) == 0` d\'abord.',
      'On peut aussi garder le **numéro** du meilleur, `$iMax`, et comparer `$eleves[$i][\'note\']` à `$eleves[$iMax][\'note\']`.',
      'Ex æquo : avec `>`, **le premier** de la liste reste ; avec `>=`, c\'est le dernier.'
    ],
    example: '$meilleur = $eleves[0];\nforeach ($eleves as $e) {\n    if ($e[\'note\'] > $meilleur[\'note\']) {\n        $meilleur = $e;\n    }\n}\necho $meilleur[\'nom\'] . " : " . $meilleur[\'note\'];',
    vars: { eleves: [{ nom: 'Léa', note: 12 }, { nom: 'Tom', note: 16 }, { nom: 'Zoé', note: 16 }] },
    traps: [
      'Garder seulement la note (`$max = $e[\'note\']`) perd le nom : garde la fiche entière, ou son numéro.',
      'Partir d\'une fausse fiche à 0 : si on cherche le pire, ou si toutes les notes valent 0, elle gagne. Pars de `$eleves[0]`.'
    ],
    fns: ['count']
  });

  C.fiche({
    notion: 'cletableau',
    rule: '$classe = [\n    [\'nom\' => valeur, \'notes\' => [note, note, note]],\n    [\'nom\' => valeur, \'notes\' => [note, note]],\n];\necho $classe[$i][\'notes\'][$j];\n\nforeach ($classe as $e) {\n    foreach ($e[\'notes\'] as $n) {\n        // une note de cet élève\n    }\n}',
    points: [
      'Une case de fiche peut contenir une **liste** : `$e[\'notes\']` est la liste, `$e[\'notes\'][1]` une de ses notes.',
      'Trois crochets se lisent **de gauche à droite**, une boîte après l\'autre : `$classe[1]` la fiche, `[\'notes\']` sa liste, `[0]` la première note.',
      'La moyenne d\'un élève : `array_sum($e[\'notes\']) / count($e[\'notes\'])`, ou une boucle sur `$e[\'notes\']` dont le total repart de 0 **pour chaque élève**.'
    ],
    example: 'echo $classe[1][\'notes\'][0] . "\\n";\nforeach ($classe as $e) {\n    if (count($e[\'notes\']) == 0) {\n        echo $e[\'nom\'] . " : pas de note\\n";\n    } else {\n        echo $e[\'nom\'] . " : " . round(array_sum($e[\'notes\']) / count($e[\'notes\']), 1) . "\\n";\n    }\n}',
    vars: { classe: [{ nom: 'Léa', notes: [12, 15, 9] }, { nom: 'Tom', notes: [8, 14] }, { nom: 'Inès', notes: [] }] },
    traps: [
      'On divise par `count($e[\'notes\'])`, les notes **de cet élève** : ni `count($classe)` (les élèves), ni `count($e)` (les cases de la fiche, ici 2).',
      'Une liste peut être **vide** : `count($e[\'notes\'])` vaut 0 et diviser est interdit. Teste-le d\'abord.',
      '`echo $classe[1][\'notes\'];` affiche « Array » : il manque un crochet pour choisir une note.'
    ],
    fns: ['count', 'array_sum', 'round']
  });

  C.fiche({
    notion: 'regrouper',
    rule: '$parGroupe = [];\n$nb = [];\nforeach ($fiches as $f) {\n    $k = $f[\'groupe\'];\n    $parGroupe[$k][] = $f[\'nom\'];   // ranger au bout de la liste du groupe\n    $nb[$k] = ($nb[$k] ?? 0) + 1;    // compter\n}\nforeach ($parGroupe as $groupe => $noms) {\n    echo $groupe . " : " . implode(", ", $noms);\n}',
    points: [
      'Les **clés** du résultat sont les groupes (les salles), les valeurs des listes de noms ou des nombres.',
      '`$parSalle[$s[\'salle\']][] = $s[\'nom\'];` se lit de l\'intérieur : la salle, sa liste (créée vide la première fois), puis `[]` ajoute le nom au bout.',
      'Pour compter ou additionner : `$nb[$k] = ($nb[$k] ?? 0) + 1;`. `?? 0` part de 0 quand le groupe n\'existe pas encore.',
      'Les groupes apparaissent dans l\'ordre où on les rencontre ; `count($parSalle)` compte les groupes, `count($noms)` les fiches d\'un groupe.'
    ],
    example: '$parSalle = [];\nforeach ($specimens as $s) {\n    $parSalle[$s[\'salle\']][] = $s[\'nom\'];\n}\nforeach ($parSalle as $salle => $noms) {\n    echo $salle . " : " . implode(", ", $noms) . "\\n";\n}',
    vars: { specimens: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Loup gris', salle: 'R3' }, { nom: 'Ammonite', salle: 'R1' }] },
    traps: [
      'Sans les `[]`, `$parSalle[$s[\'salle\']] = $s[\'nom\'];` **remplace** la valeur : il ne reste que le dernier nom de chaque salle.',
      'On ne crée pas les groupes à la main : on ne sait pas d\'avance lesquels existent. `[]` et `?? 0` les créent au premier passage.',
      '`$nb[$k] + 1` sans `?? 0` : la première fois, la clé n\'existe pas encore et PHP avertit « Undefined array key ».'
    ],
    fns: ['implode', 'count']
  });

  C.fiche({
    notion: 'construire',
    rule: '$g = [];\nfor ($l = 0; $l < $nbLignes; $l++) {\n    $ligne = [];                 // une ligne neuve à chaque tour\n    for ($c = 0; $c < $nbColonnes; $c++) {\n        $ligne[] = valeur;\n    }\n    $g[] = $ligne;\n}\n\n$fiches = [];\nfor ($i = 0; $i < count($noms); $i++) {\n    $fiches[] = [\'nom\' => $noms[$i], \'note\' => $notes[$i]];\n}',
    points: [
      'Une grille se construit **une ligne à la fois** : une ligne vide, remplie par la boucle intérieure, puis ajoutée à la grille avec `$g[] = $ligne;`.',
      '`$ligne = [];` se place **au début de la boucle des lignes** : chaque ligne repart vide.',
      'Deux listes dans le même ordre s\'assemblent en fiches : le même `$i` lit `$noms[$i]` et `$notes[$i]`, et `$eleves[] = [ … ];` ajoute une fiche entière.',
      'Écrire directement `$g[$l][$c] = valeur;` marche aussi : PHP crée la ligne si elle n\'existe pas encore.'
    ],
    example: '$g = [];\nfor ($l = 0; $l < $nl; $l++) {\n    $ligne = [];\n    for ($c = 0; $c < $nc; $c++) {\n        $ligne[] = ($l + 1) * ($c + 1);\n    }\n    $g[] = $ligne;\n}\necho count($g) . " lignes\\n";\necho implode(" ", $g[1]);',
    vars: { nl: 2, nc: 5 },
    traps: [
      '`$ligne = [];` placé avant les deux boucles : la ligne 1 garde les cases de la ligne 0 et grandit.',
      'La boucle des lignes va jusqu\'au nombre de lignes, celle des colonnes jusqu\'au nombre de colonnes : les inverser ne se voit pas sur une grille carrée, essaie 2 lignes et 5 colonnes.',
      '`$eleves[\'nom\'] = $noms[$i];` réécrit la même fiche à chaque tour : il ne reste que le dernier élève. Écris `$eleves[] = [\'nom\' => …, \'note\' => …];`.'
    ],
    fns: ['count', 'implode']
  });

  C.fiche({
    notion: 'trier',
    rule: 'sort($t);     // valeurs croissantes, clés renumérotées 0, 1, 2…\nrsort($t);    // valeurs décroissantes\nasort($t);    // croissant, en gardant les clés\narsort($t);   // décroissant, en gardant les clés\nksort($t);    // selon les clés\nusort($fiches, fn($a, $b) => $a[\'case\'] <=> $b[\'case\']);   // des fiches, croissant',
    points: [
      'Chaque tri **modifie le tableau lui-même** : écris `sort($t);` seul sur sa ligne, puis utilise `$t`.',
      'Sur un tableau associatif, `sort` perd les clés : pour garder les noms, `asort` ou `arsort`. `ksort` trie selon les clés.',
      'Pour une liste de fiches, `usort` reçoit une fonction qui compare deux fiches : `$a[\'note\'] <=> $b[\'note\']` trie en croissant, `$b[\'note\'] <=> $a[\'note\']` en décroissant.',
      '`<=>` donne -1 si la première valeur est plus petite, 0 si elles sont égales, 1 si elle est plus grande.'
    ],
    example: 'usort($eleves, fn($a, $b) => $b[\'note\'] <=> $a[\'note\']);\nforeach ($eleves as $e) {\n    echo $e[\'nom\'] . " : " . $e[\'note\'] . "\\n";\n}',
    vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 9 }, { nom: 'Inès', note: 17 }] },
    traps: [
      '`$notes = sort($notes);` remplace tes notes par `true` : un tri ne renvoie pas le tableau trié. Pareil pour `usort`.',
      '`sort` sur `[\'Léa\' => 14, \'Tom\' => 16]` perd les noms (les clés deviennent 0 et 1) : prends `asort` ou `arsort`.',
      'Dans `usort`, `$a` à gauche de `<=>` trie en croissant : pour le décroissant, mets `$b` à gauche.'
    ],
    fns: ['sort', 'rsort', 'asort', 'arsort', 'ksort', 'usort']
  });

  C.fn({ name: 'sort', notion: 'trier', sig: 'sort($tableau)', does: 'Trie les valeurs dans l\'ordre croissant (nombres du plus petit au plus grand, textes dans l\'ordre alphabétique). Modifie le tableau lui-même et renumérote ses clés 0, 1, 2…', example: '$notes = [14, 9, 17];\nsort($notes);\necho implode(" ", $notes);', note: 'Ne renvoie que `true` : jamais `$notes = sort($notes);`.' });

  C.fn({ name: 'rsort', notion: 'trier', sig: 'rsort($tableau)', does: 'Trie les valeurs dans l\'ordre décroissant. Modifie le tableau lui-même et renumérote ses clés 0, 1, 2…', example: '$notes = [14, 9, 17];\nrsort($notes);\necho implode(" ", $notes);', note: 'Sur un tableau associatif, les clés sont perdues : prends `arsort`.' });

  C.fn({ name: 'asort', notion: 'trier', sig: 'asort($tableau)', does: 'Trie les valeurs dans l\'ordre croissant en gardant chaque clé avec sa valeur. Modifie le tableau lui-même.', example: '$notes = [\'Léa\' => 14, \'Tom\' => 16, \'Inès\' => 9];\nasort($notes);\nforeach ($notes as $nom => $note) {\n    echo $nom . " : " . $note . "\\n";\n}' });

  C.fn({ name: 'arsort', notion: 'trier', sig: 'arsort($tableau)', does: 'Trie les valeurs dans l\'ordre décroissant en gardant chaque clé avec sa valeur. Modifie le tableau lui-même.', example: '$notes = [\'Léa\' => 14, \'Tom\' => 16, \'Inès\' => 9];\narsort($notes);\nforeach ($notes as $nom => $note) {\n    echo $nom . " : " . $note . "\\n";\n}' });

  C.fn({ name: 'ksort', notion: 'trier', sig: 'ksort($tableau)', does: 'Trie le tableau selon ses clés (par exemple les noms), pas selon ses valeurs. Modifie le tableau lui-même.', example: '$notes = [\'Tom\' => 9, \'Léa\' => 16, \'Inès\' => 14];\nksort($notes);\nforeach ($notes as $nom => $note) {\n    echo $nom . " : " . $note . "\\n";\n}' });

  C.fn({ name: 'usort', notion: 'trier', sig: 'usort($liste, fn($a, $b) => $a[\'cle\'] <=> $b[\'cle\'])', does: 'Trie une liste (de fiches, par exemple) avec la fonction donnée, qui compare deux éléments $a et $b. Modifie la liste elle-même.', example: 'usort($eleves, fn($a, $b) => $a[\'nom\'] <=> $b[\'nom\']);\nforeach ($eleves as $e) {\n    echo $e[\'nom\'] . "\\n";\n}', vars: { eleves: [{ nom: 'Tom', note: 9 }, { nom: 'Léa', note: 14 }, { nom: 'Inès', note: 17 }] }, note: 'Ne renvoie rien d\'utile : jamais `$eleves = usort(…);`.' });

  C.fn({ name: 'print_r', notion: 'trier', sig: 'print_r($tableau)', does: 'Affiche tout le contenu d\'un tableau, avec ses clés, tableaux intérieurs compris. Pour voir ce qu\'un tableau contient vraiment quand un programme se trompe.', example: '$eleve = [\'nom\' => \'Léa\', \'notes\' => [12, 15]];\nprint_r($eleve);', note: 'C\'est un outil pour déboguer : pour un affichage propre, choisis les cases et affiche-les avec `echo`.' });
})(window.PPPContent);
