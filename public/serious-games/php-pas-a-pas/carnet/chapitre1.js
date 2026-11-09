/* PHP pas à pas — the notebook, chapter 1 « Afficher et calculer »: one fiche per notion of the chapter and
   the PHP functions it introduces (content.js, « Mon carnet »). */
(function (C) {
  'use strict';

  C.fiche({
    notion: 'echo',
    rule: 'echo "texte";\necho "texte puis retour à la ligne\\n";',
    points: [
      '`echo` affiche ce qui le suit ; un texte s\'écrit **entre guillemets**.',
      'Chaque instruction se termine par un **point-virgule** `;`.',
      'Les instructions s\'exécutent l\'une après l\'autre, de haut en bas.',
      'Deux `echo` écrivent l\'un derrière l\'autre : `\\n`, dans un texte entre guillemets doubles, fait passer à la ligne.'
    ],
    example: 'echo "Bienvenue au muséum !\\n";\necho "Fossiles\\n";\necho "Oiseaux";',
    traps: [
      'Un point-virgule oublié : PHP signale l\'erreur **à la ligne suivante**, car il continue de lire l\'instruction. Regarde la fin de la ligne d\'avant.',
      'Sans `\\n`, les textes se collent : `echo "Bon";` puis `echo "jour";` affichent « Bonjour ».'
    ],
    fns: []
  });

  C.fiche({
    notion: 'variables',
    rule: '$nom = valeur;\necho $nom;',
    points: [
      'Une variable est une boîte qui porte un nom ; ce nom commence toujours par `$`.',
      '`=` **range** la valeur de droite dans la boîte de gauche. Un texte s\'écrit entre guillemets, un nombre tel quel.',
      'Une nouvelle affectation remplace l\'ancienne valeur : PHP lit toujours la valeur **du moment**.',
      '`$auteur = $prenom;` copie la valeur une fois : changer `$prenom` ensuite ne touche pas `$auteur`.'
    ],
    example: '$prenom = "Léa";\n$age = 17;\n$age = 18;\necho $prenom;\necho "\\n";\necho $age;',
    traps: [
      'Sans `$`, ce n\'est pas une variable : `echo salle;` est une erreur, écris `echo $salle;`.',
      'Recopier la valeur au lieu de la variable : `echo "Léa";` ne marche qu\'avec Léa, `echo $prenom;` marche avec tous les prénoms.',
      'Pour échanger `$a` et `$b`, passe par une troisième boîte : `$c = $a; $a = $b; $b = $c;`. Sinon l\'ancienne valeur de `$a` est perdue.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'calculs',
    rule: '$total = $prix * $quantite;   // + - * /\n$reste = $a % $b;              // le reste de la division\n$entier = intdiv($a, $b);      // le quotient entier\n$arrondi = round($nombre, 2);  // arrondi à 2 décimales',
    points: [
      '`*` et `/` passent **avant** `+` et `-` ; les parenthèses passent avant tout : `2 + 3 * 4` vaut 14, `(2 + 3) * 4` vaut 20.',
      '`/` donne le résultat exact, avec ses décimales : `7 / 2` vaut `3.5` (un point, pas une virgule).',
      '`intdiv($a, $b)` garde la partie entière, `$a % $b` le reste : 135 minutes, c\'est `intdiv(135, 60)`, 2 h, et `135 % 60`, 15 min.',
      '`round($nombre, 2)` arrondit à 2 décimales.'
    ],
    example: '$minutes = 135;\necho intdiv($minutes, 60);\necho "\\n";\necho $minutes % 60;\necho "\\n";\necho (12 + 16) / 2;\necho "\\n";\necho round(10 / 3, 2);',
    traps: [
      'Une moyenne sans parenthèses : `$a + $b / 2` ne divise que `$b`. Écris `($a + $b) / 2`.',
      'Pour compter des semaines complètes, ni `$jours / 7` (des décimales) ni `round($jours / 7)` (qui arrondit parfois au-dessus) : c\'est `intdiv($jours, 7)`.',
      'Les décimales s\'écrivent avec un point : `1.2`, jamais `1,2`.'
    ],
    fns: ['intdiv', 'round']
  });

  C.fiche({
    notion: 'concat',
    rule: 'echo "texte " . $variable . " texte";\necho "texte $variable texte";   // guillemets doubles seulement',
    points: [
      'Le point `.` **assemble** des textes et des valeurs en un seul texte ; `+` **calcule**.',
      'Les espaces font partie des textes : `"Bonjour" . $prenom` colle tout, « BonjourLéa ».',
      'Entre guillemets doubles, une variable est remplacée par sa valeur ; entre apostrophes, rien n\'est remplacé, et `\\n` n\'y fait pas de retour à la ligne.'
    ],
    example: '$prenom = "Léa";\n$age = 17;\necho $prenom . " a " . $age . " ans.\\n";\necho "Bonjour $prenom\\n";\necho \'Bonjour $prenom\';',
    traps: [
      '`"Total : " + $total` est une erreur : PHP ne sait pas additionner un texte. Assemble avec le point : `"Total : " . $total`.',
      'Mets les espaces **dans** les guillemets : `$prenom . " a " . $age`, pas `$prenom . "a" . $age`.',
      'Un calcul au milieu d\'un texte se relit mal : range-le d\'abord, `$total = $quantite * $prix;`, puis assemble `$total`.'
    ],
    fns: []
  });

  C.fn({
    name: 'intdiv',
    notion: 'calculs',
    sig: 'intdiv($dividende, $diviseur)',
    does: 'Renvoie le quotient entier de la division : la partie après la virgule est enlevée, sans arrondir.',
    example: 'echo intdiv(17, 5);\necho "\\n";\necho intdiv(135, 60);',
    note: '`intdiv(59, 60)` vaut 0 : le résultat n\'est jamais arrondi au-dessus. Le reste, lui, se calcule avec `%`.'
  });

  C.fn({
    name: 'round',
    notion: 'calculs',
    sig: 'round($nombre, $decimales)',
    does: 'Renvoie le nombre arrondi au plus proche, avec ce nombre de décimales ; sans le second paramètre, arrondit à l\'entier.',
    example: 'echo round(2.456, 1);\necho "\\n";\necho round(9.99 * 1.2, 2);',
    note: 'Pour compter des semaines complètes, `round(6 / 7)` vaut 1 alors qu\'il n\'y en a aucune : utilise `intdiv`.'
  });
})(window.PPPContent);
