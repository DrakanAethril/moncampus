/* PHP pas à pas — the notebook, chapter 3 « Répéter »: one fiche per notion of the chapter and the PHP
   functions it introduces (content.js, « Mon carnet »). The chapter introduces no PHP function. */
(function (C) {
  'use strict';

  C.fiche({
    notion: 'while',
    rule: '$i = depart;\nwhile (condition) {\n    // instructions\n    $i++;\n}',
    points: [
      '`while` (« tant que ») répète son bloc **tant que la condition est vraie**. Elle est vérifiée **avant chaque tour**.',
      '`$i++` ajoute 1 à `$i` (c\'est `$i = $i + 1` en plus court) : c\'est lui qui fait changer la condition, tour après tour.',
      'Si la condition est fausse dès le départ, la boucle ne fait **aucun tour** : ce n\'est pas une erreur.'
    ],
    example: '$stock = 50;\n$jour = 0;\nwhile ($stock >= 15) {\n    $stock = $stock - 15;\n    $jour++;\n    echo "Jour " . $jour . " : il reste " . $stock . " brochures\\n";\n}\necho "Fini";',
    traps: [
      'Rien dans le bloc ne fait changer la condition (le `$i++` oublié) : c\'est une **boucle infinie**, le jeu la coupe avec « Maximum execution time … exceeded ». Demande-toi ce qui, dans le bloc, rapproche la condition du « non ».',
      '`$i + 1;` calcule une valeur sans la ranger nulle part : `$i` ne change pas. Écris `$i++;` ou `$i = $i + 1;`.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'for',
    rule: 'for (depart; condition; pas) {\n    // instructions\n}\n\nfor ($i = 1; $i <= $n; $i++) {\n    // $n tours : $i vaut 1, 2, …, $n\n}',
    points: [
      'Trois parties séparées par des `;` : le **départ** (une seule fois, avant le premier tour), la **condition** (avant chaque tour), le **pas** (à la fin de chaque tour).',
      'C\'est un `while` rangé sur une ligne : avec `for`, impossible d\'oublier le pas.',
      '`$i = 1; $i <= $n` et `$i = 0; $i < $n` font tous les deux **$n tours** ; seules les valeurs de `$i` changent.'
    ],
    example: '$n = 4;\nfor ($i = 0; $i < $n; $i++) {\n    echo "*";\n}\necho "\\n";\nfor ($i = 1; $i <= $n; $i++) {\n    echo "*";\n}',
    traps: [
      'Le tour de trop ou de moins : `$i = 1; $i < $n` fait **un tour de moins**, `$i = 0; $i <= $n` **un tour de trop**. Vérifie dans ta tête avec `$n` = 1 (un seul tour ?) et `$n` = 0 (aucun tour ?).',
      'Le pas d\'un `for` s\'exécute déjà à la fin de chaque tour : n\'ajoute pas un `$i++` dans les accolades, `$i` avancerait de 2.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'pas',
    rule: 'for ($i = debut; $i <= fin; $i += pas) {    // qui monte\n    // instructions\n}\n\nfor ($i = debut; $i >= fin; $i -= pas) {    // qui descend\n    // instructions\n}',
    points: [
      '`$i--` retire 1, `$i += 2` c\'est `$i = $i + 2`, `$i -= 5` c\'est `$i = $i - 5`.',
      '**La condition suit le sens du pas** : une boucle qui monte continue tant que `$i <= fin`, une boucle qui descend tant que `$i >= fin`.',
      'Avec un pas de 2 ou plus, `$i` peut **sauter par-dessus** la borne : de 1 à 10 de 2 en 2, il vaut 1, 3, 5, 7, 9, puis 11.'
    ],
    example: 'for ($i = 5; $i >= 1; $i--) {\n    echo $i . " ";\n}\necho "Partez !\\n";\nfor ($i = 0; $i <= 20; $i += 5) {\n    echo $i . " ";\n}',
    traps: [
      'Une condition dans le mauvais sens : `for ($i = 10; $i <= 1; $i--)` ne fait aucun tour, car 10 <= 1 est faux dès le départ. Pour descendre, c\'est `$i >= 1`.',
      'Jamais `$i != 10` comme condition : avec un pas de 2 depuis 1, `$i` ne vaut jamais 10 et la boucle ne s\'arrête plus. Écris `$i <= 10`.',
      'Quand la borne tombe pile, elle compte : de 0 à 20 de 5 en 5, `$i <= 20` passe par 20, `$i < 20` non.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'accumuler',
    rule: '$somme = 0;              // AVANT : préparer, une fois\nfor ($i = 1; $i <= $n; $i++) {\n    $somme += valeur;    // DEDANS : ajouter, à chaque tour\n}\necho $somme;             // APRÈS : afficher, une fois',
    points: [
      'Trois places, trois moments : **préparer** avant la boucle, **ajouter** dedans, **afficher** après.',
      '`$somme += $i` c\'est `$somme = $somme + $i`. Pour un produit, `$p *= $i`, et le produit part de **1**.',
      '`.=` colle un morceau au bout d\'un texte : `$ligne .= "*";`. Le texte se prépare **vide**, `$ligne = "";`.'
    ],
    example: '$total = 0;\nfor ($j = 1; $j <= 4; $j++) {\n    $total += $j * 10 + 5;\n}\necho "Visiteurs en 4 jours : " . $total . "\\n";\n$ligne = "";\nfor ($i = 1; $i <= 5; $i++) {\n    $ligne .= "*";\n}\necho "[" . $ligne . "]";',
    traps: [
      '`$somme = 0;` **dans** la boucle : la somme est effacée à chaque tour et il ne reste que le dernier ajout. Une variable qui accumule se prépare **avant** la boucle.',
      'Le `echo` dans la boucle affiche chaque somme provisoire : le total s\'affiche **après** l\'accolade fermante.',
      'Un produit qui part de 0 reste à 0 : écris `$p = 1;`.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'compter',
    rule: '$nb = 0;                 // avant : rien compté\nfor ($i = 1; $i <= $n; $i++) {\n    if (condition) {\n        $nb++;           // seulement si c\'est vrai\n    }\n}\necho $nb;                // après : le compte final',
    points: [
      'Compter, c\'est accumuler en ajoutant toujours 1, mais **seulement quand la condition est vraie**.',
      'Le compteur part de 0 : s\'il n\'y a rien à compter, il reste à 0, et c\'est la bonne réponse.',
      'Compter (`$nb++`) n\'est pas additionner (`$somme += $v`) : le compteur dit **combien de fois**, pas combien en tout.'
    ],
    example: '$nb = 0;\nfor ($h = 1; $h <= 11; $h++) {\n    $v = $h * (12 - $h);\n    if ($v >= 30) {\n        $nb++;\n    }\n}\necho $nb . " heures à 30 visiteurs ou plus";',
    traps: [
      '`$nb++` écrit hors du `if` compte **tous** les tours : il va dans les accolades du `if`.',
      'Pas de `else` qui remet `$nb` à 0, et pas de `echo` dans la boucle : le compte s\'affiche une fois, après.',
      '« Au moins 10 » compte 10 : c\'est `>= 10`, pas `> 10`.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'extremum',
    rule: 'for ($h = 1; $h <= $n; $h++) {\n    $v = valeur;\n    if ($h == 1 || $v > $max) {\n        $max = $v;\n    }\n}\necho $max;',
    points: [
      '`$max` garde la plus grande valeur vue **jusqu\'ici** : à chaque tour, une valeur plus grande prend sa place.',
      'Au **premier tour**, il n\'y a rien à battre : la première valeur devient le maximum (`$h == 1 ||`).',
      'Pour le **minimum**, c\'est pareil avec `<`. Pour savoir **où** est le maximum, range aussi le moment dans le même `if` : `$heure = $h;`.'
    ],
    example: 'for ($h = 1; $h <= 10; $h++) {\n    $v = $h * (11 - $h);\n    if ($h == 1 || $v > $max) {\n        $max = $v;\n        $heure = $h;\n    }\n}\necho $max . " visiteurs à " . $heure . " h";',
    traps: [
      '`$max = 0;` avant la boucle : si toutes les valeurs sont négatives, aucune ne bat 0, et le programme affiche 0, qui n\'est même pas dans la suite. Même piège pour un minimum qui part de 0 quand tout est positif. Pars de la première valeur.',
      'En cas d\'égalité, le signe décide : `>` garde **la première**, `>=` garde **la dernière**. Lis bien la consigne.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'reste',
    rule: 'if ($i % 2 == 0)     // $i est pair\nif ($i % 2 != 0)     // $i est impair\nif ($i % 3 == 0)     // $i est un multiple de 3\n\ncontinue;            // passe au tour suivant\nbreak;               // quitte la boucle',
    points: [
      '`%` donne le **reste** de la division entière : `17 % 5` vaut 2. Un reste de 0 veut dire « multiple ».',
      '`continue` saute la fin du tour et passe **au tour suivant**.',
      '`break` **quitte la boucle** tout de suite : le programme reprend après l\'accolade fermante.',
      'Ce qui est écrit après un `continue` ou un `break`, dans le même tour, ne s\'exécute pas : l\'ordre des lignes compte.'
    ],
    example: 'for ($i = 1; $i <= 10; $i++) {\n    if ($i % 2 == 0) {\n        continue;\n    }\n    if ($i > 7) {\n        break;\n    }\n    echo $i . " ";\n}',
    traps: [
      '« Impair » se teste avec `% 2 != 0`, pas avec `% 2 == 1` : pour un nombre négatif, le reste est négatif (`-5 % 2` vaut -1).',
      '`%` n\'est pas `/` : `$i / 3 == 0` n\'est vrai que pour 0. Un multiple de 3, c\'est `$i % 3 == 0`.',
      '`continue` n\'arrête pas la boucle et ne la fait pas repartir du premier tour : il passe au tour suivant. C\'est `break` qui arrête.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'chercher',
    rule: '$trouve = false;             // avant : rien trouvé\nfor ($i = 1; $i <= $n; $i++) {\n    if (condition) {\n        $trouve = true;\n        break;               // inutile de chercher plus loin\n    }\n}\nif ($trouve) {               // après : on conclut\n    echo "oui";\n} else {\n    echo "non";\n}',
    points: [
      'On ne peut répondre « non » **qu\'après avoir tout regardé**. D\'où un **drapeau** : `false` (faux) au départ, `true` (vrai) dès qu\'on trouve.',
      '`if ($trouve)` veut dire « si `$trouve` est vrai ».',
      'Pour garder **ce qu\'on a trouvé**, range-le aussi, juste avant le `break` : `$premier = $i;`.'
    ],
    example: '$trouve = false;\nfor ($i = 1; $i <= 10; $i++) {\n    if ($i * $i == 49) {\n        $trouve = true;\n        break;\n    }\n}\nif ($trouve) {\n    echo "oui, " . $i . " au carré";\n} else {\n    echo "non";\n}',
    traps: [
      'Un `else` **dans** la boucle qui remet `$trouve` à `false`, ou qui affiche « non » à chaque tour : la conclusion « non » s\'écrit **après** la boucle.',
      'Oublier le cas « rien trouvé » : sans le `else` après la boucle, le programme ne dit rien quand il n\'y a rien.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'imbriquees',
    rule: 'for ($i = 1; $i <= $lignes; $i++) {          // une ligne\n    for ($j = 1; $j <= $colonnes; $j++) {    // ses colonnes\n        // instructions\n    }\n    echo "\\n";                               // fin de la ligne\n}',
    points: [
      'Pour **chaque tour** de la boucle extérieure, la boucle intérieure fait **tous ses tours** : 3 lignes de 5 colonnes, c\'est 3 × 5 = 15 tours.',
      'Chaque boucle a **son propre compteur** (`$i`, `$j`), et `$j = 1` repart de 1 à chaque ligne.',
      'La boucle intérieure peut dépendre de la ligne en cours : avec `$j <= $i`, la ligne `$i` a `$i` tours, c\'est un triangle.'
    ],
    example: 'for ($i = 1; $i <= 4; $i++) {\n    for ($j = 1; $j <= $i; $j++) {\n        echo $j;\n    }\n    echo "\\n";\n}',
    traps: [
      'Réutiliser `$i` dans la boucle intérieure : les deux boucles se marchent dessus. Prends un autre compteur, `$j`.',
      'Le `echo "\\n"` se place **entre** les deux accolades fermantes, une fois par ligne : dans la boucle intérieure, il irait à la ligne après chaque étoile.',
      'Les tours se **multiplient**, ils ne s\'additionnent pas : 3 lignes de 5 colonnes, c\'est 15 tours intérieurs, pas 8.'
    ],
    fns: []
  });
})(window.PPPContent);
