/* PHP pas à pas — the notebook, chapter 4 « Les tableaux »: one fiche per notion of the chapter and the PHP
   functions it introduces (content.js, « Mon carnet »). */
(function (C) {
  'use strict';

  C.fiche({
    notion: 'liste',
    rule: '$tableau = [valeur, valeur, valeur];\necho $tableau[0];                    // la première case\necho count($tableau);                // le nombre de cases\necho $tableau[count($tableau) - 1];  // la dernière case',
    points: [
      'Un tableau range plusieurs valeurs dans des **cases numérotées** ; le numéro s\'écrit entre crochets : `$notes[1]`.',
      '**La première case est la case 0.** Avec 3 cases, les numéros vont de 0 à 2.',
      '`count($notes)` donne le **nombre de cases** ; la dernière est donc `$notes[count($notes) - 1]`, quelle que soit la longueur de la liste.',
      'Pour afficher plusieurs valeurs, mets `"\\n"` entre elles : le jeu compare ligne par ligne.'
    ],
    example: '$notes = [12, 8, 15];\necho "Première : " . $notes[0] . "\\n";\necho "Nombre : " . count($notes) . "\\n";\necho "Dernière : " . $notes[count($notes) - 1];',
    traps: [
      '`$notes[1]` est la **deuxième** valeur, pas la première : on compte à partir de 0.',
      '`$notes[count($notes)]` lit une case de trop. Elle ne vaut pas 0 : PHP affiche « Undefined array key 3 ». La dernière case est `count($notes) - 1`.'
    ],
    fns: ['count']
  });

  C.fiche({
    notion: 'parcourir',
    rule: 'for ($i = 0; $i < count($tableau); $i++) {\n    // $tableau[$i] : la case numéro $i\n}\n\nforeach ($tableau as $valeur) {\n    // $valeur : la case suivante, à chaque tour\n}',
    points: [
      'Avec `for`, `$i` part de **0** et la boucle continue **tant que `$i < count($tableau)`** : le dernier tour lit la dernière case.',
      'Avec `foreach`, la variable écrite après `as` reçoit une case à chaque tour : autant de tours que de cases, aucune borne à écrire.',
      'Prends `for` quand tu as besoin du **numéro** de case : l\'afficher, ou aller à l\'envers.',
      'Une liste vide ne fait aucun tour.'
    ],
    example: '$salles = [\'R1\', \'R2\', \'R3\'];\nfor ($i = 0; $i < count($salles); $i++) {\n    echo "Case " . $i . " : " . $salles[$i] . "\\n";\n}\nforeach ($salles as $s) {\n    echo $s . "\\n";\n}',
    traps: [
      '`$i <= count($t)` fait un tour de trop, sur une case qui n\'existe pas : avertissement. Écris `$i < count($t)`.',
      '`$i = 1` saute la première case : pars de `$i = 0`.',
      'À l\'envers : `for ($i = count($t) - 1; $i >= 0; $i--)`. Partir de `count($t)` lit une case de trop, et `$i > 0` oublie la case 0.'
    ],
    fns: ['count']
  });

  C.fiche({
    notion: 'ajouter',
    rule: '$tableau = [];              // une liste vide\n$tableau[] = valeur;        // ajoute une case à la fin\n$tableau[numero] = valeur;  // remplace la case numero\n\nfor ($i = 0; $i < count($tableau); $i++) {\n    $tableau[$i] = valeur;  // écrit dans chaque case\n}',
    points: [
      '`$t[] = valeur;`, avec des crochets **vides**, ajoute une case **à la fin**, quelle que soit la longueur de la liste.',
      '`$t[1] = valeur;` **remplace** la case 1 : une case se modifie comme une variable.',
      'Dans `foreach ($t as $v)`, `$v` est une **copie** de la case. Pour modifier les cases, écris dans `$t[$i]` avec une boucle `for`.'
    ],
    example: '$notes = [12, 8];\n$notes[] = 15;\n$notes[0] = 13;\nfor ($i = 0; $i < count($notes); $i++) {\n    $notes[$i] = $notes[$i] + 1;\n}\necho count($notes) . " notes : " . $notes[0] . " " . $notes[1] . " " . $notes[2];',
    traps: [
      '`$t[3] = valeur;` pour « ajouter » ne marche que si la liste a pile 3 cases : écris `$t[] = valeur;`.',
      '`foreach ($notes as $n) { $n = $n + 1; }` ne change rien à `$notes` (`$n++` non plus) : écris `$notes[$i] = $notes[$i] + 1;` dans un `for`.'
    ],
    fns: ['count']
  });

  C.fiche({
    notion: 'totaliser',
    rule: '$total = 0;\nforeach ($tableau as $valeur) {\n    $total = $total + $valeur;\n}\n$moyenne = $total / count($tableau);\n\n$max = $tableau[0];\nforeach ($tableau as $valeur) {\n    if ($valeur > $max) {\n        $max = $valeur;\n    }\n}',
    points: [
      'Le total se prépare à 0 **avant** la boucle ; chaque tour y ajoute une case.',
      '`array_sum($t)` fait le même total tout seul ; la moyenne, c\'est le total divisé par `count($t)`.',
      'Pour le plus grand, pars de la **première case**, `$max = $t[0]`, et remplace-le dès qu\'une case le dépasse. Pour le plus petit, même chose avec `<`.'
    ],
    example: '$notes = [12, 8, 15, 10];\n$max = $notes[0];\nforeach ($notes as $n) {\n    if ($n > $max) {\n        $max = $n;\n    }\n}\necho "Total : " . array_sum($notes) . "\\n";\necho "Moyenne : " . (array_sum($notes) / count($notes)) . "\\n";\necho "Plus grande : " . $max;',
    traps: [
      '`$total = 0;` écrit **dans** la boucle repart de zéro à chaque tour : il ne reste que la dernière case.',
      'Liste vide : `count` vaut 0, et diviser par 0 arrête le programme. Teste `if (count($t) == 0)` avant de diviser.',
      '`$max = 0` échoue quand toutes les valeurs sont négatives (-3, -8, -1) : il resterait 0, qui n\'est même pas dans la liste. Pars de `$t[0]`.'
    ],
    fns: ['array_sum', 'count']
  });

  C.fiche({
    notion: 'filtrer',
    rule: '$garde = [];\nforeach ($tableau as $valeur) {\n    if (condition) {\n        $garde[] = $valeur;\n    }\n}\necho implode(", ", $garde);',
    points: [
      'On construit une **nouvelle liste** : vide **avant** la boucle, et chaque valeur qui passe le test y est ajoutée avec `[] =`.',
      'Après la boucle, elle contient les valeurs gardées, dans leur ordre ; `count` dit combien sont passées.',
      '`implode(", ", $liste)` colle les valeurs en un seul texte, avec le séparateur **entre** elles seulement : rien au début, rien à la fin.'
    ],
    example: '$notes = [14, 8, 10, 6, 17];\n$admis = [];\nforeach ($notes as $n) {\n    if ($n >= 10) {\n        $admis[] = $n;\n    }\n}\necho count($admis) . " admis : " . implode(", ", $admis);',
    traps: [
      '`$admis = [];` écrit **dans** la boucle vide la liste à chaque tour : au mieux, il ne reste que la dernière valeur. Place-le avant.',
      '`implode` d\'une liste vide n\'affiche rien : si la consigne demande un message (« Personne »), teste `count($liste) == 0` avant.'
    ],
    fns: ['implode', 'count']
  });

  C.fiche({
    notion: 'chercher-tab',
    rule: '$trouve = false;\nforeach ($tableau as $valeur) {\n    if ($valeur == $cherche) {\n        $trouve = true;\n    }\n}\n\nin_array($cherche, $tableau)       // true ou false\narray_search($cherche, $tableau)   // numéro de case, ou false',
    points: [
      'Le **drapeau** part à `false` avant la boucle et passe à `true` dès qu\'on trouve ; un drapeau levé ne se baisse plus.',
      'Pour la position du premier trouvé : `$pos = -1` (« pas trouvé ») avant une boucle `for`, puis `$pos = $i;` et `break`.',
      '`in_array` dit si la valeur est dans la liste ; `array_search` donne le numéro de la **première** case qui la contient, ou `false`.'
    ],
    example: '$prenoms = [\'Léa\', \'Tom\', \'Inès\', \'Tom\'];\nif (in_array(\'Tom\', $prenoms)) {\n    echo "Tom est là\\n";\n}\n$pos = array_search(\'Léa\', $prenoms);\nif ($pos === false) {\n    echo "Pas de Léa";\n} else {\n    echo "Léa est en case " . $pos;\n}',
    traps: [
      'Pas de `else { $trouve = false; }` dans la boucle : la case suivante, différente, baisserait le drapeau, et seule la dernière case compterait.',
      '`array_search` renvoie 0 pour la première case, et 0 ressemble à `false` : compare toujours avec `=== false`, jamais `if ($pos)` ni `== false`.'
    ],
    fns: ['in_array', 'array_search']
  });

  C.fiche({
    notion: 'associatif',
    rule: '$tableau = [\'cle\' => valeur, \'autre\' => valeur];\necho $tableau[\'cle\'];       // lit la case nommée cle\n$tableau[\'cle\'] = valeur;    // remplace sa valeur\n$tableau[\'nouvelle\'] = valeur;  // une clé nouvelle crée une case\necho $tableau[$variable];   // la clé est dans une variable',
    points: [
      'Chaque case porte un **nom**, sa clé : `\'Léa\' => 14` se lit « la case Léa contient 14 ».',
      'Écrire une clé nouvelle **crée** une case ; réécrire une clé qui existe **remplace** sa valeur. Une clé n\'existe qu\'une fois.',
      'La clé remplace le numéro : `$notes[0]` n\'existe pas ici. `count` compte les cases, comme pour une liste.'
    ],
    example: '$notes = [\'Léa\' => 14, \'Tom\' => 9];\n$notes[\'Tom\'] = 11;\n$notes[\'Hugo\'] = 12;\n$nom = \'Léa\';\necho count($notes) . "\\n";\necho $notes[\'Tom\'] . "\\n";\necho $notes[$nom];',
    traps: [
      'Une clé texte s\'écrit entre apostrophes : `$specimen[\'nom\']`. Sans elles, `$specimen[nom]` arrête le programme (« Undefined constant "nom" »).',
      'Clé rangée dans une variable : `$notes[$prenom]`, sans apostrophes. `$notes[\'prenom\']` chercherait une case nommée « prenom ».'
    ],
    fns: ['count']
  });

  C.fiche({
    notion: 'cle',
    rule: 'foreach ($tableau as $cle => $valeur) {\n    // $cle : le nom de la case, $valeur : son contenu\n}\n\nif (isset($tableau[$cle])) {\n    // la case existe\n}\necho $tableau[$cle] ?? valeur;  // sa valeur, ou celle-ci si elle n\'existe pas',
    points: [
      '`foreach ($t as $cle => $valeur)` donne à chaque tour la clé **et** la valeur, dans l\'ordre du tableau. Sur une liste, la clé est le numéro de case.',
      'Lire une clé absente affiche « Undefined array key » : ce n\'est ni 0 ni vide, la case n\'existe pas.',
      '`isset($t[$cle])` vaut `true` si la case existe, sans avertissement ; `$t[$cle] ?? 0` donne sa valeur, ou 0 si elle n\'existe pas.'
    ],
    example: '$notes = [\'Léa\' => 14, \'Tom\' => 9];\nforeach ($notes as $nom => $note) {\n    echo $nom . " : " . $note . "\\n";\n}\nif (!isset($notes[\'Hugo\'])) {\n    echo "Pas de note pour Hugo\\n";\n}\necho "Hugo : " . ($notes[\'Hugo\'] ?? 0);',
    traps: [
      '`??` n\'ajoute pas de case : après `$notes[\'Hugo\'] ?? 0`, le tableau n\'a toujours pas de case Hugo.',
      'Dans un texte assemblé, mets `??` entre parenthèses : `"Hugo : " . ($notes[\'Hugo\'] ?? 0)`. Sans elles, le `.` passe d\'abord et l\'avertissement revient.',
      '`$valeur` reste une copie : pour changer la case, écris `$t[$cle] = …`.'
    ],
    fns: ['isset']
  });

  C.fiche({
    notion: 'occurrences',
    rule: '$compte = [];\nforeach ($tableau as $valeur) {\n    $compte[$valeur] = ($compte[$valeur] ?? 0) + 1;\n}\nforeach ($compte as $valeur => $n) {\n    echo $valeur . " : " . $n . "\\n";\n}',
    points: [
      'La **clé** est la valeur rencontrée, la **case** est son compteur.',
      'Au premier passage, la case n\'existe pas : `?? 0` part de 0 et la case est créée à 1. Ensuite elle passe à 2, 3…',
      'À la fin, une case par valeur différente, dans l\'ordre de première apparition : `count($compte)` dit combien de valeurs différentes.',
      'Le plus fréquent : parcours `$compte` avec la clé et garde le plus grand compteur, avec sa clé.'
    ],
    example: '$votes = [\'Zoo\', \'Grottes\', \'Zoo\', \'Muséum\', \'Zoo\'];\n$compte = [];\nforeach ($votes as $v) {\n    $compte[$v] = ($compte[$v] ?? 0) + 1;\n}\nforeach ($compte as $lieu => $n) {\n    echo $lieu . " : " . $n . "\\n";\n}\necho count($compte) . " lieux différents";',
    traps: [
      '`$compte[$v] = $compte[$v] + 1;` sans `??` lit une case absente au premier passage : avertissement.',
      'Les parenthèses comptent : `$compte[$v] ?? 0 + 1` ajoute 1 au 0 seulement, et chaque compteur reste bloqué à 1. Écris `($compte[$v] ?? 0) + 1`.',
      'En cas d\'ex æquo pour le plus fréquent, `>` garde le premier rencontré, `>=` le dernier.'
    ],
    fns: ['count']
  });

  C.fn({
    name: 'count',
    notion: 'liste',
    sig: 'count($tableau)',
    does: 'Renvoie le nombre de cases du tableau, 0 pour un tableau vide. Les numéros de case vont donc de 0 à `count($tableau) - 1`.',
    example: 'echo count([12, 8, 15]);\necho "\\n";\necho count([]);',
    note: 'count compte les cases à partir de 1 : `$t[count($t)]` n\'existe jamais, la dernière est `$t[count($t) - 1]`.'
  });

  C.fn({
    name: 'array_sum',
    notion: 'totaliser',
    sig: 'array_sum($tableau)',
    does: 'Renvoie la somme de toutes les valeurs du tableau, 0 pour un tableau vide.',
    example: 'echo array_sum([12, 8, 15, 10]);',
    note: 'Ne confonds pas avec `count`, qui compte les cases sans les additionner.'
  });

  C.fn({
    name: 'implode',
    notion: 'filtrer',
    sig: 'implode($separateur, $tableau)',
    does: 'Renvoie un seul texte : les valeurs du tableau collées dans l\'ordre, avec le séparateur entre elles (rien au début, rien à la fin).',
    example: 'echo implode(", ", [\'Léa\', \'Tom\', \'Inès\']);\necho "\\n";\necho implode(" / ", [14, 10, 17]);',
    note: 'Un tableau vide donne un texte vide : si un message est attendu, teste `count($tableau) == 0` avant.'
  });

  C.fn({
    name: 'in_array',
    notion: 'chercher-tab',
    sig: 'in_array($valeur, $tableau)',
    does: 'Renvoie `true` si la valeur est dans une des cases du tableau, `false` sinon.',
    example: '$salles = [\'R1\', \'R2\', \'R3\'];\nif (in_array(\'R2\', $salles)) {\n    echo "R2 existe";\n}',
    note: 'La valeur cherchée vient en premier, le tableau en second.'
  });

  C.fn({
    name: 'array_search',
    notion: 'chercher-tab',
    sig: 'array_search($valeur, $tableau)',
    does: 'Renvoie le numéro (ou la clé) de la première case qui contient la valeur, ou `false` si elle n\'y est pas.',
    example: '$prenoms = [\'Léa\', \'Tom\', \'Inès\'];\n$pos = array_search(\'Léa\', $prenoms);\nif ($pos === false) {\n    echo "Absente";\n} else {\n    echo "Case " . $pos;\n}',
    note: 'La première case porte le numéro 0, qui ressemble à `false` : teste toujours `$pos === false`, jamais `if ($pos)`.'
  });

  C.fn({
    name: 'isset',
    notion: 'cle',
    sig: 'isset($tableau[$cle])',
    does: 'Renvoie `true` si la case existe, `false` sinon, sans aucun avertissement.',
    example: '$notes = [\'Léa\' => 14, \'Tom\' => 9];\nif (isset($notes[\'Tom\'])) {\n    echo "Tom a une note\\n";\n}\nif (!isset($notes[\'Hugo\'])) {\n    echo "Pas de note pour Hugo";\n}',
    note: 'Pour lire la valeur ou une valeur par défaut en une fois, `$notes[$nom] ?? 0` est plus court.'
  });
})(window.PPPContent);
