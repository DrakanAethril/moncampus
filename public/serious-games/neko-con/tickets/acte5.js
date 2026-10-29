/* Acte 5 — La bascule (course CSS 01). Game half: bug, criteria, hints, scenes. */
(function (T) {
  'use strict';

  T.add({
    id: 'T21', phase: 'html', page: 'index.html', edit: ['index.html'], show: ['css/style.css'],
    fix: [{ file: 'index.html', find: 'href="css/styles.css"', replace: 'href="css/style.css"' }],
    checks: [
      { label: 'La page d\'accueil charge sa feuille de style', test: function (h) { return h.sheetLoaded(); } },
      { label: 'Le fond de la page prend la couleur lavande du club', test: function (h) { return h.color('body', 'background-color') === 'rgb(246,241,255)'; } }
    ],
    hints: [
      'Une feuille de style ne s\'applique que si le chemin de son <link> mène exactement au fichier. Une lettre de trop, et le navigateur ne trouve rien, sans rien dire. Cours CSS 01, Découvrir le langage : relier une feuille de style à une page.',
      'Dans le <link>, remplace css/styles.css par css/style.css.'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'Dis… le site, il est censé rester tout blanc ? Parce que le club a voté pour du violet. Et du rose. Beaucoup de rose.' },
      { who: 'ines', expr: 'soupir', text: 'Jordan a écrit toute une feuille de style. Elle n\'a jamais été chargée. Trouve pourquoi, et le site va changer de tête.' }
    ],
    done: [
      { who: 'maelle', expr: 'etoiles', text: 'DU ROSE ! DU VIOLET ! La mascotte ! C\'est exactement la maquette !' },
      { who: 'ines', expr: 'sourire', text: 'Je reporte la même correction sur les autres pages. Retiens le réflexe : une page sans style, on vérifie d\'abord le chemin du <link>. Et maintenant que le CSS est branché, prépare-toi : ses bugs à lui vont arriver.' }
    ]
  });

  T.add({
    id: 'T22', phase: 'css', page: 'index.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '  font-size: 3.5rem;\n  color: #e8457a;', replace: '  font-size: 3.5rem\n  color: #e8457a;' }],
    checks: [
      { label: 'Le titre « Neko-Con » est rose bonbon', test: function (h) { return h.color('.hero h1') === 'rgb(232,69,122)'; } },
      { label: 'Il est en énorme, comme sur la maquette', test: function (h) { return h.px('.hero h1', 'font-size') >= 50; } }
    ],
    hints: [
      'Chaque déclaration se termine par un point-virgule. Sans lui, le navigateur colle la ligne suivante à la valeur précédente, ne comprend plus rien et jette le tout. Cours CSS 01, Découvrir le langage : la syntaxe d\'une règle.',
      'Ajoute « ; » à la fin de font-size: 3.5rem.'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'Le titre ! Sur la maquette, il est rose et immense. Là, il est noir et normal. On dirait un titre de copie de philo.' },
      { who: 'ines', expr: 'neutre', text: 'La couleur est bien écrite dans la règle h1. Alors pourquoi le navigateur l\'ignore-t-il ? Lis la règle comme lui : caractère par caractère.' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'Rose ! Énorme ! Avec l\'ombre violette ! Parfait.' },
      { who: 'ines', expr: 'neutre', text: 'Réflexe : termine chaque déclaration par « ; », même la dernière de la règle. Le jour où tu en ajoutes une en dessous, tu ne casseras rien.' }
    ]
  });

  T.add({
    id: 'T23', phase: 'css', page: 'index.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '.quand {\n  font-size: 1.2rem;\n  font-weight: 700;\n  color: #5b3fb0;\n}', replace: '.quand {\n  font-size: 18;\n  font-weight: 700;\n  colour: #5b3fb0;\n}' }],
    checks: [
      { label: 'Les horaires sont bien lisibles, plus grands que le texte normal', test: function (h) { return h.px('.quand', 'font-size') >= 17; } },
      { label: 'Les horaires sont en violet', test: function (h) { return h.color('.quand') === 'rgb(91,63,176)'; } }
    ],
    hints: [
      'Le navigateur ignore une déclaration dont la valeur ou le nom de propriété est invalide : une taille sans unité, une propriété mal orthographiée. Cours CSS 01, Découvrir le langage : propriétés, valeurs et unités.',
      'Écris font-size: 18px (ou 1.2rem) et color à la place de colour.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Les horaires sur l\'accueil, ils sont censés être violets et gros. Ils sont comme le reste. Si les gens arrivent à 8 h, ce sera ma faute, je tiens l\'entrée.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Violet, bien gros. Même moi, je ne peux pas me tromper d\'heure.' },
      { who: 'ines', expr: 'sourire', text: 'Dans un vrai navigateur, l\'inspecteur des outils de développement barre les déclarations invalides : un coup d\'œil suffit. Et retiens que le CSS parle américain : color, center, gray.' }
    ]
  });
})(window.NekoTickets);
