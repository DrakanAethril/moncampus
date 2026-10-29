/* Acte 7 — « Je l'ai regardé sur mon téléphone… » (course CSS 06). Game half: bug, criteria, hints, scenes. */
(function (T) {
  'use strict';

  // The site's media query, as it stands at the end of css/style.css (T36 moves it to the top).
  var MEDIA = [
    '/* Téléphone */',
    '@media (max-width: 600px) {',
    '  .entete {',
    '    flex-direction: column;',
    '    align-items: flex-start;',
    '  }',
    '  .menu {',
    '    flex-direction: column;',
    '    gap: 4px;',
    '  }',
    '  h1 {',
    '    font-size: 2.4rem;',
    '  }',
    '  .mascotte {',
    '    position: static;',
    '    width: 160px;',
    '    max-width: none;',
    '  }',
    '  .duo {',
    '    flex-direction: column;',
    '  }',
    '  .col {',
    '    float: none;',
    '    width: 100%;',
    '  }',
    '  .plan {',
    '    grid-template-columns: 1fr;',
    '    grid-template-areas: "scene" "dedicaces" "s1" "s2" "s3" "s4" "buvette" "entree";',
    '  }',
    '  .pied::after {',
    '    content: "J. : 5e morceau, un point d\'exclamation. Personne ne regarde le pied de page sur téléphone.";',
    '    display: block;',
    '    margin-top: 8px;',
    '    font-size: 0.75rem;',
    '    opacity: 0.7;',
    '  }',
    '}'
  ].join('\n');

  var GALLERY_RULE = '.galerie figure {\n  margin: 0;\n  padding: 10px;\n  border-radius: 12px;\n  background: #ffffff;\n}\n';
  var SHEET_HEADER = '/* Neko-Con : feuille de style du site du club manga */\n\n';

  T.add({
    id: 'T34', phase: 'css', page: 'index.html', edit: ['index.html'], startDevice: 375,
    bug: [{ file: 'index.html',
      find: '  <meta charset="utf-8">\n  <meta name="viewport" content="width=device-width, initial-scale=1">\n  <title>',
      replace: '  <meta charset="utf-8">\n  <title>' }],
    checks: [
      { label: 'Sur mon téléphone, la page s\'affiche à la largeur de l\'écran, sans être rapetissée', width: 375, test: function (h) {
        var w = h.doc.documentElement.clientWidth;
        return { ok: h.layout <= 400 || w <= 400, detail: 'Page mise en page sur ' + Math.round(Math.min(h.layout, w)) + ' px' };
      } },
      { label: 'Le menu se range en colonne, comme sur un vrai site pour téléphone', width: 375, test: function (h) {
        var li = h.$$('.menu li');
        if (li.length < 2) return false;
        return h.css('.menu', 'flex-direction') === 'column' || li[1].getBoundingClientRect().top >= li[0].getBoundingClientRect().bottom - 1;
      } }
    ],
    hints: [
      'Sans balise viewport, un téléphone met la page en page sur 980 px, puis la réduit pour la faire tenir dans l\'écran : les media queries ne se déclenchent même pas. Cours CSS 06, Le responsive design.',
      'Ajoute dans le <head>, après la balise charset : <meta name="viewport" content="width=device-width, initial-scale=1">.'
    ],
    intro: [
      { who: 'maelle', expr: 'paniquee', text: 'Sur mon téléphone, la page d\'accueil est minuscule ! Il faut zoomer pour lire « Neko-Con » ! Et trois personnes du club m\'ont dit pareil !' },
      { who: 'ines', expr: 'neutre', text: 'L\'aperçu est réglé sur un téléphone de 375 px. Regarde la page d\'accueil… puis une autre page.' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'Tout est à la bonne taille ! Et le menu s\'est rangé en colonne, comme un vrai site pour téléphone !' },
      { who: 'ines', expr: 'neutre', text: 'Une bonne habitude : chaque nouvelle page part d\'un modèle dont le <head> est complet, charset, viewport, titre et feuille de style. Jordan, lui, a recopié celle-ci de mémoire, un soir de fatigue.' }
    ]
  });

  T.add({
    id: 'T35', phase: 'css', page: 'index.html', edit: ['css/style.css'], show: ['index.html'], startDevice: 375,
    bug: [{ file: 'css/style.css',
      find: '  line-height: 1.5;\n}\n\nimg {\n  max-width: 100%;\n  height: auto;\n}\n\n',
      replace: '  line-height: 1.5;\n}\n\n' }],
    checks: [
      { label: 'Sur mon téléphone, l\'affiche tient entière dans l\'écran', width: 375, test: function (h) {
        var a = h.rect('img.affiche'), w = h.doc.documentElement.clientWidth;
        return !!a && a.width > 100 && { ok: a.left >= -1 && a.right <= w + 1, detail: 'Affiche de ' + Math.round(a.width) + ' px pour un écran de ' + w + ' px' };
      } },
      { label: 'Elle reste dans le cadre du bloc « Infos »', width: 375, test: function (h) { return h.inside('img.affiche', '.bloc-infos', 1); } },
      { label: 'Le chat n\'est pas déformé : l\'affiche garde ses proportions', width: 375, test: function (h) {
        var a = h.rect('img.affiche');
        return !!a && a.height > 0 && Math.abs(a.width / a.height - 1.5) < 0.05;
      } },
      { label: 'Sur ordinateur, le bloc « Infos » reste plus étroit que le texte de présentation', width: 1280, test: function (h) {
        var i = h.rect('.bloc-infos'), p = h.rect('.bloc-programme');
        return !!i && !!p && i.width < p.width && h.inside('img.affiche', '.bloc-infos', 1);
      } }
    ],
    hints: [
      'Une image fluide ne dépasse jamais son conteneur et garde ses proportions : on limite sa largeur maximale à 100 % et on laisse la hauteur se calculer. Cours CSS 06, Le responsive design, les images fluides.',
      'Ajoute, au début de la feuille, une règle img avec max-width: 100%; et height: auto;.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'L\'affiche du club dessin est gigantesque sur mon téléphone. Je vois une oreille du chat. Une seule.' },
      { who: 'ines', expr: 'neutre', text: 'Fais défiler l\'aperçu vers la droite, tu verras le reste. Ce n\'est pas normal qu\'on puisse.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Le chat entier ! Avec ses deux oreilles ! Le club dessin me laisse vivre.' },
      { who: 'ines', expr: 'neutre', text: 'Réflexe à garder : quand une page glisse sur le côté sur téléphone, cherche l\'élément plus large que l\'écran. C\'est presque toujours une image, un tableau ou une largeur fixe en px.' }
    ]
  });

  T.add({
    id: 'T36', phase: 'css', page: 'index.html', edit: ['css/style.css'], startDevice: 375,
    bug: [
      { file: 'css/style.css', find: GALLERY_RULE + '\n' + MEDIA + '\n', replace: GALLERY_RULE },
      { file: 'css/style.css', find: SHEET_HEADER + 'body {', replace: SHEET_HEADER + MEDIA + '\n\nbody {' }
    ],
    checks: [
      { label: 'Sur téléphone, les liens du menu sont les uns sous les autres', width: 375, test: function (h) {
        var li = h.$$('.menu li');
        if (li.length < 2) return false;
        return h.css('.menu', 'flex-direction') === 'column' || li[1].getBoundingClientRect().top >= li[0].getBoundingClientRect().bottom - 1;
      } },
      { label: 'Sur téléphone, le grand titre est réduit pour tenir dans l\'écran', width: 375, test: function (h) {
        var px = h.px('h1', 'font-size');
        return { ok: px > 0 && px <= 44, detail: 'Titre en ' + Math.round(px) + ' px' };
      } },
      { label: 'Sur téléphone, le plan des stands tient sur une seule colonne', page: 'exposants.html', width: 375, test: function (h) {
        var z = h.$$('.zone');
        if (z.length < 2) return false;
        var l = z[0].getBoundingClientRect().left;
        return z.every(function (x) { return Math.abs(x.getBoundingClientRect().left - l) <= 2; });
      } },
      { label: 'Sur ordinateur, le menu reste sur une seule ligne', width: 1280, test: function (h) {
        var li = h.$$('.menu li');
        if (li.length < 2) return false;
        var t = li[0].getBoundingClientRect().top;
        return li.every(function (x) { return Math.abs(x.getBoundingClientRect().top - t) <= 2; });
      } }
    ],
    forbid: [{ file: 'css/style.css', pattern: /!important/, label: 'Pas de !important : la cascade doit gagner toute seule' }],
    hints: [
      'À spécificité égale, c\'est la règle écrite le plus bas dans le fichier qui gagne. Une media query ne donne aucune priorité : il faut la placer après les règles qu\'elle adapte. Cours CSS 06, Le responsive design.',
      'Coupe tout le bloc @media (max-width: 600px) { … }, du commentaire « Téléphone » jusqu\'à son accolade fermante, et colle-le tout en bas de la feuille.'
    ],
    intro: [
      { who: 'ines', expr: 'sourcil', text: 'Celui-là, c\'est moi qui l\'ai ouvert. Sur mon téléphone, le menu reste en ligne alors que la feuille prévoit une colonne.' },
      { who: 'ines', expr: 'neutre', text: 'La media query est bien là. Alors pourquoi perd-elle ? Sur un téléphone, elle devrait gagner. Cherche ce qui l\'en empêche.' }
    ],
    done: [
      { who: 'ines', expr: 'sourire', text: 'Le menu en colonne, le titre qui tient, le plan sur une colonne. Et regarde tout en bas, sous le pied de page.' },
      { who: 'ines', expr: 'neutre', text: 'Range ta feuille comme un livre : les réglages généraux d\'abord, puis chaque partie du site, et les media queries à la fin. Le prochain qui l\'ouvrira te dira merci.' }
    ]
  });
})(window.NekoTickets);
