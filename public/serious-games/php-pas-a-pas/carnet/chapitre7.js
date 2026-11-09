/* PHP pas à pas — the notebook, chapter 7 « Révision »: the method to cut a big program into steps, mixing
   arrays of arrays, a function, a loop and conditions (content.js, « Mon carnet »). */
(function (C) {
  'use strict';

  C.fiche({
    notion: 'revision',
    rule: '// 1. lire les données : $classe[0][\'notes\'][0]\nfunction calcul(array $donnees) {\n    // 2. le calcul pour UN élément\n    return resultat;\n}\n$total = 0;  // 3. préparer avant la boucle\nforeach ($liste as $element) {\n    if (condition) {\n        // 4. décider, compter, afficher\n    }\n}',
    points: [
      '**Lis les données** : `$classe` est une liste d\'élèves, et dans la boucle `$e[\'notes\']` est la liste de notes d\'**un** élève.',
      '**Écris la fonction** du calcul pour un seul élève et essaie-la sur un seul : `echo moyenne($classe[0][\'notes\']);`.',
      '**Ajoute la boucle**, et prépare **avant** elle les variables qui accumulent sur toute la classe : un total, un meilleur, un compteur.',
      '**Exécute après chaque étape** et pense aux cas cachés : un seul élève, aucune note, deux ex æquo, une moyenne pile sur une borne.'
    ],
    example: 'function moyenne(array $notes) {\n    return round(array_sum($notes) / count($notes), 1);\n}\necho "Ont la moyenne :\\n";\nforeach ($classe as $e) {\n    $m = moyenne($e[\'notes\']);\n    if ($m >= 10) {\n        echo $e[\'prenom\'] . " : " . $m . "\\n";\n    }\n}',
    vars: {
      classe: [
        { prenom: 'Léa', notes: [15, 16, 12], absences: 0 },
        { prenom: 'Tom', notes: [8, 11, 9], absences: 4 },
        { prenom: 'Inès', notes: [17, 18, 15], absences: 1 },
        { prenom: 'Hugo', notes: [10, 13, 11], absences: 6 }
      ]
    },
    traps: [
      '`$classe[\'notes\']` n\'existe pas : `$classe` est la liste des élèves. Les notes se lisent sur un élève, `$e[\'notes\']` dans la boucle.',
      'Ce qui se calcule pour **toute la classe** (total, compteur) se prépare **avant** la boucle ; ce qui se calcule pour **un** élève (sa meilleure note) repart **dans** la boucle, à chaque élève.',
      'Pour des mentions, teste les bornes **de la plus haute à la plus basse** (`>= 16`, puis `>= 14`…) : commencer par `>= 10` donne « Passable » à tout le monde.'
    ],
    fns: ['round', 'array_sum', 'count']
  });
})(window.PPPContent);
