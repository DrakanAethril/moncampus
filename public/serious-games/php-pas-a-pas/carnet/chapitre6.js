/* PHP pas à pas — the notebook, chapter 6 « Les fonctions »: one fiche per notion of the chapter and the
   PHP functions it introduces (content.js, « Mon carnet »). */
(function (C) {
  'use strict';

  C.fiche({
    notion: 'fnphp',
    rule: '$resultat = nom_de_la_fonction(argument1, argument2);\necho nom_de_la_fonction(argument);',
    points: [
      'On écrit le **nom** de la fonction, puis ses **arguments** entre parenthèses, séparés par des virgules.',
      'La fonction **renvoie** une valeur : tu l\'affiches avec `echo`, tu la ranges dans une variable ou tu la testes dans un `if`.',
      'Pour les textes : `strlen`, `strtoupper`, `strtolower`, `ucfirst`, `str_contains`. Pour les listes et les nombres : `implode`, `explode`, `max`, `min`, `round`.',
      'Avec des accents, compte les lettres avec `mb_strlen` et mets en majuscules avec `mb_strtoupper`.'
    ],
    example: '$prenom = "Zoé";\necho strlen($prenom) . " / " . mb_strlen($prenom) . "\\n";\necho ucfirst("tom") . " " . strtoupper("tom") . "\\n";\n$notes = explode(";", "12;8;15");\necho implode(" - ", $notes) . "\\n";\necho max($notes) . "\\n";\nif (str_contains("Trilobite", "lob")) {\n    echo "trouvé";\n}',
    traps: [
      '`strlen("Zoé")` vaut 4, pas 3 : une lettre accentuée compte pour deux octets. Pour compter les lettres, `mb_strlen("Zoé")`.',
      'Avec `implode` et `explode`, le séparateur s\'écrit **en premier** : `implode(", ", $t)`, jamais `implode($t, ", ")`.',
      '`max` et `min` sur une liste **vide** arrêtent le programme : vérifie `count($t)` avant.'
    ],
    fns: ['strlen', 'mb_strlen', 'strtoupper', 'mb_strtoupper', 'strtolower', 'ucfirst', 'str_contains', 'implode', 'explode', 'max', 'min', 'round']
  });

  C.fiche({
    notion: 'fonction',
    rule: 'function nom($parametre1, $parametre2) {\n    // ce que fait la fonction\n}\n\nnom(valeur1, valeur2);  // l\'appel',
    points: [
      'Le mot `function`, le nom, les **paramètres** entre parenthèses, puis le corps entre accolades.',
      'Écrire la fonction **ne l\'exécute pas** : elle attend d\'être **appelée**, par son nom, avec des valeurs entre parenthèses.',
      'À l\'appel, les valeurs vont dans les paramètres **dans l\'ordre** : la première valeur dans le premier paramètre.',
      'Tu l\'appelles autant de fois que tu veux, avec d\'autres valeurs à chaque fois, même dans un `foreach`.'
    ],
    example: 'function carte($nom, $salle) {\n    echo $nom . " — salle " . $salle . "\\n";\n}\n\ncarte("Trilobite", "R3");\ncarte("Dodo", "R1");',
    traps: [
      'Une fonction écrite mais jamais appelée n\'affiche rien : ajoute l\'appel, `carte("Dodo", "R1");`.',
      'Il faut **autant de valeurs que de paramètres** : `carte("Dodo")` arrête le programme (« Too few arguments »).',
      'Inutile d\'écrire `$nom = "Dodo";` au-dessus de la fonction : le paramètre reçoit sa valeur au moment de l\'appel.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'return',
    rule: 'function nom($parametre) {\n    return valeur;\n}\n\n$resultat = nom(valeur);',
    points: [
      '`return` **renvoie** une valeur au programme et **arrête** la fonction tout de suite.',
      'L\'appel est **remplacé par la valeur renvoyée** : tu peux l\'afficher, la ranger, l\'additionner, la comparer dans un `if`.',
      'La règle : la fonction **calcule et renvoie**, c\'est le programme qui **affiche**.'
    ],
    example: 'function ttc($ht) {\n    return round($ht * 1.2, 2);\n}\n$total = ttc(10) + ttc(5);\necho $total . "\\n";\nif (ttc(50) > 50) {\n    echo "Plus de 50 € TTC";\n}',
    traps: [
      'Une fonction qui fait `echo` au lieu de `return` affiche, mais **ne renvoie rien** : son appel vaut `null`, et `ttc(10) + ttc(5)` vaut 0. Mets `return` dans la fonction, et `echo` dans le programme.',
      '`return` n\'affiche rien : un appel seul sur sa ligne, `ttc(10);`, perd la valeur. Affiche-la ou range-la.',
      'Les lignes écrites après un `return` ne sont jamais lues.'
    ],
    fns: ['round']
  });

  C.fiche({
    notion: 'portee',
    rule: 'function nom($parametre, $autreValeur) {\n    // ne voit que ses paramètres et ses propres variables\n    return resultat;\n}\n\n$x = nom($valeur, $autre);  // on passe, on range',
    points: [
      'Une fonction travaille dans **sa propre boîte** : elle ne voit que ses **paramètres** et les variables qu\'elle crée elle-même.',
      'Une variable du programme dont elle a besoin, tu la lui **passes en paramètre** : `ttc(10, $taux)`.',
      'Le paramètre reçoit une **copie** : changer `$note` dans la fonction ne change pas `$n` dehors.',
      'Ce qui doit sortir de la fonction sort par `return`, et tu le **ranges** : `$n = bonus($n);`.'
    ],
    example: 'function ttc($ht, $taux) {\n    return round($ht * $taux, 2);\n}\n$prix = 10;\nttc($prix, $taux);\necho $prix . "\\n";\n$prix = ttc($prix, $taux);\necho $prix;',
    vars: { taux: 1.2 },
    traps: [
      'Lire une variable du programme dans la fonction donne « Undefined variable » : passe-la en paramètre. Écrire la fonction plus haut ou plus bas n\'y change rien.',
      'Écrire `1.2` en dur à la place de `$taux` fait taire l\'avertissement… jusqu\'au jour où le taux change. Ajoute plutôt un paramètre.',
      '`bonus($n);` seul ne change pas `$n` : range le résultat, `$n = bonus($n);`.'
    ],
    fns: ['round']
  });

  C.fiche({
    notion: 'fntableau',
    rule: 'function nom(array $liste) {\n    $resultat = depart;\n    foreach ($liste as $valeur) {\n        // calculer, compter, filtrer…\n    }\n    return $resultat;\n}',
    points: [
      'Un paramètre peut recevoir un **tableau entier** : `array` devant le paramètre dit « j\'attends un tableau ».',
      'Le calcul s\'écrit **une fois** et marche sur n\'importe quelle liste : à chaque appel, tout repart de zéro.',
      'Pour renvoyer une liste : pars de `[]`, ajoute avec `$garde[] = $n;`, et fais le `return` **après** la boucle.'
    ],
    example: 'function moyenne(array $notes) {\n    if (count($notes) === 0) {\n        return 0;\n    }\n    return round(array_sum($notes) / count($notes), 1);\n}\necho moyenne([12, 15, 9]) . "\\n";\necho moyenne([8, 11]) . "\\n";\necho moyenne([]);',
    traps: [
      'La liste peut être **vide** : sans le test `count($notes) === 0`, la moyenne divise par 0 et arrête le programme.',
      'Un `return` **dans** la boucle arrête la fonction au premier tour : place-le après l\'accolade qui ferme le `foreach`.',
      'Les variables qui accumulent (`$somme`, `$garde`) se créent **dans** la fonction, avant la boucle : celles du programme sont invisibles pour elle.'
    ],
    fns: ['count', 'array_sum', 'round']
  });

  C.fn({ name: 'strlen', notion: 'fnphp', sig: 'strlen($texte)', does: 'Renvoie la longueur du texte en octets : son nombre de caractères tant qu\'il n\'a pas d\'accent.', example: 'echo strlen("Trilobite");', note: 'Une lettre accentuée compte pour deux : `strlen("Zoé")` vaut 4. Pour compter les lettres, `mb_strlen`.' });
  C.fn({ name: 'mb_strlen', notion: 'fnphp', sig: 'mb_strlen($texte)', does: 'Renvoie le nombre de lettres du texte, accents compris : chaque lettre compte pour une.', example: 'echo strlen("Zoé") . " octets, " . mb_strlen("Zoé") . " lettres";' });
  C.fn({ name: 'strtoupper', notion: 'fnphp', sig: 'strtoupper($texte)', does: 'Renvoie le texte en majuscules.', example: 'echo strtoupper("lion");', note: 'Les lettres accentuées restent en minuscules : `strtoupper("léa")` donne « LéA ». Avec des accents, `mb_strtoupper`.' });
  C.fn({ name: 'mb_strtoupper', notion: 'fnphp', sig: 'mb_strtoupper($texte)', does: 'Renvoie le texte en majuscules, lettres accentuées comprises.', example: 'echo mb_strtoupper("léa");' });
  C.fn({ name: 'strtolower', notion: 'fnphp', sig: 'strtolower($texte)', does: 'Renvoie le texte en minuscules.', example: 'echo strtolower("DODO");', note: 'Comme `strtoupper`, il laisse les lettres accentuées telles quelles.' });
  C.fn({ name: 'ucfirst', notion: 'fnphp', sig: 'ucfirst($texte)', does: 'Renvoie le texte avec sa première lettre en majuscule ; le reste ne change pas.', example: 'echo ucfirst("trilobite");', note: 'Il ne met pas le reste en minuscules : `ucfirst("tOM")` donne « TOM ».' });
  C.fn({ name: 'str_contains', notion: 'fnphp', sig: 'str_contains($texte, $morceau)', does: 'Renvoie `true` si $morceau se trouve dans $texte, `false` sinon : on s\'en sert dans un `if`.', example: 'if (str_contains("Trilobite", "lob")) {\n    echo "trouvé";\n}', note: 'Il distingue majuscules et minuscules : `str_contains("Trilobite", "tri")` est faux.' });
  C.fn({ name: 'explode', notion: 'fnphp', sig: 'explode($separateur, $texte)', does: 'Découpe le texte à chaque séparateur et renvoie la liste des morceaux.', example: '$notes = explode(";", "12;8;15");\necho count($notes) . " notes, la deuxième : " . $notes[1];', note: 'Le séparateur s\'écrit en premier, comme pour `implode`.' });
  C.fn({ name: 'max', notion: 'fnphp', sig: 'max($liste) ou max($a, $b, …)', does: 'Renvoie la plus grande valeur d\'une liste, ou des valeurs qu\'on lui donne.', example: 'echo max([12, 8, 15]) . "\\n";\necho max(3, 9, 2);', note: 'Sur une liste vide, il arrête le programme : vérifie `count($t)` avant.' });
  C.fn({ name: 'min', notion: 'fnphp', sig: 'min($liste) ou min($a, $b, …)', does: 'Renvoie la plus petite valeur d\'une liste, ou des valeurs qu\'on lui donne.', example: 'echo min([12, 8, 15]) . "\\n";\necho min(3, 9, 2);', note: 'Sur une liste vide, il arrête le programme : vérifie `count($t)` avant.' });
})(window.PPPContent);
