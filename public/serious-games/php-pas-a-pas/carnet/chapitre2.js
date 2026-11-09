/* PHP pas à pas — the notebook, chapter 2 « Décider »: one fiche per notion of the chapter (content.js,
   « Mon carnet »). The chapter introduces no PHP function: match is a language construct, not a function. */
(function (C) {
  'use strict';

  C.fiche({
    notion: 'comparer',
    rule: '$a == $b    // égal à\n$a != $b    // différent de\n$a < $b     // strictement plus petit\n$a > $b     // strictement plus grand\n$a <= $b    // plus petit ou égal\n$a >= $b    // plus grand ou égal',
    points: [
      'Une comparaison pose une question : la réponse est **vrai** (`true`) ou **faux** (`false`).',
      '`>` **exclut** la valeur, `>=` l\'**accepte** : avec `$note = 10`, `$note > 10` est faux, `$note >= 10` est vrai.',
      'Les textes se comparent lettre par lettre : majuscules et accents comptent, `"r3" == "R3"` est faux.',
      'Pour voir la réponse, place la comparaison dans un **testeur** `if … else` : un `echo` de la comparaison affiche `1`, ou rien du tout.'
    ],
    example: '$note = 10;\nif ($note > 10) {\n    echo "vrai";\n} else {\n    echo "faux";\n}',
    traps: [
      'Un seul `=` **range** au lieu de comparer : `if ($salle = "R3")` change `$salle` et répond presque toujours « vrai ». Pour comparer : `==`.',
      'Le `=` s\'écrit toujours en dernier : `<=` et `>=`, jamais `=<`.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'if',
    rule: 'if (condition) {\n    // exécuté seulement si la condition est vraie\n}',
    points: [
      'La condition s\'écrit **entre parenthèses**, le bloc **entre accolades** : il ne s\'exécute que si la condition est vraie.',
      'Si elle est fausse, PHP saute le bloc et continue après l\'accolade fermante : un `if` faux n\'arrête pas le programme.',
      'Ce sont les accolades, pas le décalage de 4 espaces, qui disent où le bloc s\'arrête.',
      'Pour changer une valeur « seulement dans un cas », prépare-la avant le `if` : le bloc la modifie.'
    ],
    example: '$note = 17;\n$badge = "aucun";\nif ($note >= 16) {\n    $badge = "or";\n}\necho "Badge : " . $badge;',
    traps: [
      'Pas de point-virgule après `if (…)` : `if ($note >= 16);` termine le `if` tout de suite, et le bloc qui suit s\'exécute toujours.',
      'La ligne placée après `}` n\'est pas un « sinon » : elle s\'exécute dans tous les cas. Pour le cas contraire, il faut un `else`.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'else',
    rule: 'if (condition) {\n    // si la condition est vraie\n} else {\n    // sinon\n}',
    points: [
      '`else` (« sinon ») donne un bloc pour le cas où la condition est fausse.',
      'Avec `if … else`, **exactement un** des deux blocs s\'exécute : jamais les deux, jamais aucun.',
      'Le `else` reçoit le **contraire** de la condition : le contraire de `>=` est `<`, celui de `>` est `<=`, celui de `==` est `!=`.'
    ],
    example: '$note = 9;\nif ($note >= 10) {\n    echo "Admis";\n} else {\n    echo "Refusé";\n}',
    traps: [
      '`else` n\'a **jamais** de condition : `else ($note < 10)` est une erreur. Il prend tout ce que le `if` n\'a pas pris.',
      'Deux `if` à la place d\'un `if … else` laissent des trous : avec `if ($note > 10)` puis `if ($note < 10)`, la note 10 n\'affiche rien.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'elseif',
    rule: 'if (condition1) {\n    // cas 1\n} elseif (condition2) {\n    // cas 2\n} else {\n    // tous les autres cas\n}',
    points: [
      'PHP teste les conditions **de haut en bas**, exécute le bloc de la **première vraie**, puis saute tout le reste.',
      'Range les cas **du plus exigeant au moins exigeant** : `>= 10000` avant `>= 1000`.',
      'Le `else` final, sans condition, prend tout ce qui reste. Sans lui, il peut arriver qu\'aucun bloc ne s\'exécute.'
    ],
    example: '$valeur = 50000;\nif ($valeur >= 10000) {\n    echo "Trésor";\n} elseif ($valeur >= 1000) {\n    echo "Précieux";\n} else {\n    echo "Courant";\n}',
    traps: [
      'À l\'envers, un cas devient inatteignable : si `$valeur >= 1000` est testé d\'abord, « Trésor » ne s\'affiche jamais.',
      'Des `if` séparés ne sont pas des `elseif` : chacun est testé pour lui-même et plusieurs blocs peuvent s\'exécuter ; un `else` final n\'appartient alors qu\'au dernier `if`.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'bornes',
    rule: '$age >= 18    // « au moins 18 », « à partir de 18 » : 18 dedans\n$age > 18     // « plus de 18 », « au-delà de 18 » : 18 dehors\n$note < 10    // « moins de 10 », « sous 10 » : 10 dehors\n$note <= 10   // « jusqu\'à 10 », « 10 compris » : 10 dedans',
    points: [
      'Avant d\'écrire une condition, demande-toi : **la borne est-elle dedans ou dehors ?**',
      'Écris la **vraie borne** de l\'énoncé : une note peut valoir 9.5, donc « au moins 10 » s\'écrit `>= 10`, pas `> 9`.',
      'Pour vérifier, essaie **chaque borne et la valeur juste à côté** (9.5 et 10, 11.5 et 12…), pas une valeur au milieu.',
      'Dans une suite d\'`elseif` bien rangée, la borne du haut est déjà réglée par le cas d\'avant : pas besoin de la répéter.'
    ],
    example: '$age = 12;\nif ($age <= 12) {\n    echo "Tarif enfant";\n} else {\n    echo "Plein tarif";\n}',
    traps: [
      '« Jusqu\'à 12 » **inclut** 12 : c\'est `<= 12`, pas `< 12`.',
      'Remplacer `<= 12` par `< 13` marche avec des âges entiers, mais se trompe dès qu\'une valeur a des décimales, comme 12.5.',
      'Un programme qui marche avec une valeur ne marche pas forcément avec toutes : les cas cachés du jeu visent justement les bornes.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'etou',
    rule: 'if (condition1 && condition2) {\n    // les deux sont vraies\n}\nif (condition1 || condition2) {\n    // au moins une est vraie\n}',
    points: [
      '`&&` (« et ») : vrai seulement si **les deux** conditions sont vraies. C\'est un intervalle : `$note >= 10 && $note < 12`.',
      '`||` (« ou ») : vrai si **au moins une** est vraie, ou les deux. C\'est « en dehors » : `$age < 26 || $age >= 65`.',
      'Chaque côté répète la variable et la comparaison : `$classe == "Mammifères" || $classe == "Oiseaux"`.'
    ],
    example: '$age = 70;\nif ($age < 26 || $age >= 65) {\n    echo "Gratuit";\n} else {\n    echo "9 €";\n}',
    traps: [
      '`10 <= $note < 12` n\'existe pas en PHP, et `$note >= 10 && < 12` est une erreur : écris `$note >= 10 && $note < 12`.',
      '« Entre 5 et 10 », c\'est `&&` : `$x > 5 || $x < 10` est **toujours vrai**.',
      'Le « et » du français trompe : « les Mammifères et les Oiseaux vont en R1 » s\'écrit avec `||`, car un spécimen n\'est jamais des deux classes à la fois.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'non',
    rule: 'if (!(condition)) {\n    // la condition est fausse\n}\nif (condition1 && (condition2 || condition3)) {\n    // des parenthèses dès qu\'on mélange && et ||\n}',
    points: [
      '`!` (« non ») retourne une condition : vrai devient faux, faux devient vrai. Devant une parenthèse, il porte sur **toute** la parenthèse.',
      '`&&` passe avant `||`, comme `*` avant `+` : `$a || $b && $c` se lit `$a || ($b && $c)`.',
      'Dès que tu mélanges `&&` et `||`, **mets des parenthèses**, même quand elles ne changent rien : elles disent ce que tu veux.'
    ],
    example: '$jour = "lundi";\n$age = 70;\nif ($jour == "mercredi" && ($age < 12 || $age >= 65)) {\n    echo "Gratuit";\n} else {\n    echo "Payant";\n}',
    traps: [
      'Le contraire d\'un « et » est un « ou » : « pas (au moins 10 et moins de 3 absences) », c\'est `$note < 10 || $absences >= 3`.',
      '« Ni la Réserve ni l\'Atelier » s\'écrit `$salle != "Réserve" && $salle != "Atelier"` : avec `||`, ce serait toujours vrai.',
      'Sans parenthèses, `$jour == "mercredi" && $age < 12 || $age >= 65` rend les 65 ans et plus gratuits **tous les jours**.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'imbrique',
    rule: 'if (condition1) {\n    if (condition2) {\n        // condition1 et condition2 vraies\n    } else {\n        // condition1 vraie, condition2 fausse\n    }\n} else {\n    // condition1 fausse\n}',
    points: [
      'Un bloc peut contenir un autre `if` : celui de l\'intérieur n\'est testé **que si** la condition extérieure est vraie.',
      'Chaque `else` appartient au `if` dont il suit l\'accolade fermante. Décale de 4 espaces par niveau pour voir qui va avec qui.',
      'Sans `else` à l\'extérieur, deux `if` emboîtés se collent avec `&&` : `if ($salle == "R1" && $valeur >= 1000)`.'
    ],
    example: '$salle = "R1";\n$valeur = 300;\nif ($salle == "R1") {\n    if ($valeur >= 1000) {\n        echo "Vitrine à clé";\n    } else {\n        echo "Vitrine ouverte";\n    }\n}',
    traps: [
      'PHP teste toujours le `if` **extérieur** d\'abord, jamais l\'intérieur en premier.',
      'Un `else` se rattache au `if` dont il suit l\'accolade fermante, pas à celui que tu crois : compte les accolades.',
      'Aplatir avec `&&` quand il y a un `else` mélange deux cas : « pas en R1 » et « en R1 mais bon marché ». S\'ils doivent afficher des choses différentes, garde l\'imbrication.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'identique',
    rule: '$a === $b   // même valeur et même type\n$a !== $b   // pas identiques\n\n$resultat = match ($variable) {\n    valeur1, valeur2 => resultat1,\n    valeur3 => resultat2,\n    default => resultat_par_defaut,\n};',
    points: [
      '`17` est un nombre, `"17"` un texte : les guillemets changent le **type**.',
      '`==` compare après conversion (`17 == "17"` est vrai) ; `===` compare la valeur **et** le type (`17 === "17"` est faux). Utilise `===` et `!==` par défaut.',
      '`match` compare une même variable à des valeurs précises, avec `===`, et **donne une valeur** qu\'on range ou qu\'on affiche.',
      'Chaque ligne de `match` : une ou plusieurs valeurs séparées par des virgules, `=>`, le résultat. Un point-virgule après l\'accolade finale.'
    ],
    example: '$saisie = "17";\n$avis = match ($saisie) {\n    17 => "le nombre 17",\n    "17" => "le texte 17",\n    default => "autre chose",\n};\necho $avis;',
    traps: [
      'Sans `default`, une valeur absente de toutes les lignes arrête le programme sur une erreur (`UnhandledMatchError`) : mets toujours un `default`.',
      '`match` compare des valeurs exactes : pour des intervalles, comme les mentions, garde `if … elseif`.',
      '`"4721" == "04721"` est vrai : deux textes qui ressemblent à des nombres sont comparés comme des nombres. Avec `===`, c\'est faux.'
    ],
    fns: []
  });
})(window.PPPContent);
