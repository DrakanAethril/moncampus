/* Acte 1 — Le site brut (course HTML 01). Game half: bug, criteria, hints, scenes. */
(function (T) {
  'use strict';

  T.add({
    id: 'T01', phase: 'html', page: 'index.html', edit: ['index.html'],
    bug: [{ file: 'index.html', find: '<head>\n  <meta charset="utf-8">\n', replace: '<head>\n' }],
    checks: [
      { label: 'Le slogan s\'affiche avec ses accents : « La convention manga du lycée »', test: function (h) { return h.text('.slogan') === 'La convention manga du lycée'; } },
      { label: 'La date s\'affiche correctement : « Samedi · 10 h – 18 h · gymnase B »', test: function (h) { return h.text('.quand') === 'Samedi · 10 h – 18 h · gymnase B'; } }
    ],
    hints: [
      'Une page doit déclarer son encodage, sinon le navigateur devine… mal. C\'est dans le cours HTML 01, la structure minimale d\'une page.',
      'Ajoute <meta charset="utf-8"> juste après <head>.'
    ],
    intro: [
      { who: 'maelle', expr: 'paniquee', text: 'Au secours ! Sur la page d\'accueil, « lycée » s\'écrit « lycÃ©e » ! Et c\'est partout pareil !' },
      { who: 'ines', expr: 'neutre', text: 'Classique. Le navigateur ne sait pas comment lire les caractères de la page. Regarde l\'aperçu, puis le code.' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'Les accents sont revenus ! « Lycée », « Samedi · 10 h – 18 h »… Tout est lisible !' },
      { who: 'ines', expr: 'neutre', text: 'Ce <meta charset="utf-8"> fait partie des toutes premières lignes de chaque page. De chaque page. Retiens-le.' }
    ]
  });

  T.add({
    id: 'T02', phase: 'html', page: 'index.html', edit: ['index.html'],
    bug: [{ file: 'index.html', find: 'src="img/affiche.svg"', replace: 'src="img/Affiche.SVG"' }],
    checks: [
      { label: 'L\'affiche s\'affiche sur la page d\'accueil', test: function (h) { var i = h.$('img.affiche'); return !!i && i.complete && i.naturalWidth > 0; } },
      { label: 'Elle garde son texte alternatif', test: function (h) { var i = h.$('img.affiche'); return !!i && (i.getAttribute('alt') || '').length > 10; } }
    ],
    hints: [
      'Sur un serveur web, Affiche.SVG et affiche.svg sont deux fichiers différents : les chemins respectent les majuscules. Revois le cours HTML 01, la partie sur les images.',
      'Remplace src="img/Affiche.SVG" par src="img/affiche.svg".'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'L\'affiche de la convention ! Celle que le club dessin a mis trois semaines à faire ! Elle ne s\'affiche pas !' }
    ],
    done: [
      { who: 'maelle', expr: 'etoiles', text: 'La voilà ! Le club dessin va être tellement content !' },
      { who: 'ines', expr: 'neutre', text: 'Prends l\'habitude de nommer tes fichiers en minuscules, sans espace ni accent. Sur ton ordinateur, la casse passe parfois inaperçue ; sur un serveur, jamais.' }
    ]
  });

  T.add({
    id: 'T03', phase: 'html', page: 'index.html', edit: ['index.html'],
    bug: [{ file: 'index.html', find: '<p><a href="planning.html">Voir le planning complet</a></p>', replace: '<p><a href="planning.html">Voir le planning complet</p>' }],
    checks: [
      { label: 'Le lien « Voir le planning complet » ne contient que son texte', test: function (h) {
        var a = h.$$('a').filter(function (x) { return x.getAttribute('href') === 'planning.html' && /planning complet/.test(x.textContent); })[0];
        return !!a && a.textContent.trim() === 'Voir le planning complet';
      } },
      { label: 'Le bloc « Infos » et le pied de page ne sont plus des liens', test: function (h) {
        var t = h.$('.bloc-infos h2'), f = h.$('.pied p');
        return !!t && !t.closest('a') && !!f && !f.closest('a') && !h.$('img.affiche').closest('a');
      } }
    ],
    hints: [
      'Une balise <a> ouverte doit être fermée par </a>. Si elle ne l\'est pas, le navigateur prolonge le lien sur tout ce qui suit. Cours HTML 01 : balises ouvrantes et fermantes.',
      'Écris </a> juste après « Voir le planning complet », avant </p>.'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'Depuis ce matin, tout le bas de la page est souligné en bleu. Et quand on clique n\'importe où, on part sur le planning !' },
      { who: 'ines', expr: 'neutre', text: 'Avant de toucher au code, regarde dans l\'aperçu où le soulignement commence. C\'est là qu\'il faut chercher.' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'Plus de lien qui fuit ! Mon cousin avait raison, d\'ailleurs.' },
      { who: 'ines', expr: 'sourcil', text: 'Au fait… tu as lu le commentaire tout en haut de index.html ? Jordan y a laissé quelque chose.' }
    ]
  });

  T.add({
    id: 'T04', phase: 'html', page: 'index.html', edit: ['index.html'],
    bug: [{ file: 'index.html', find: '<li><a href="infos.html">Infos pratiques</a></li>', replace: '<li><a herf="infos.html">Infos pratiques</a></li>' }],
    checks: [
      { label: 'Le lien « Infos pratiques » du menu mène à infos.html', test: function (h) {
        var a = h.$$('.menu a').filter(function (x) { return x.textContent.trim() === 'Infos pratiques'; })[0];
        return !!a && a.getAttribute('href') === 'infos.html';
      } },
      { label: 'Les autres liens du menu fonctionnent toujours', test: function (h) {
        var a = h.$$('.menu a');
        return a.length === 6 && a.every(function (x) { return !!x.getAttribute('href'); });
      } }
    ],
    hints: [
      'Un lien a besoin de l\'attribut href pour avoir une adresse. Un attribut mal orthographié est ignoré, sans aucun message d\'erreur. Cours HTML 01 : balise, attribut, valeur.',
      'Remplace herf par href.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Le lien « Infos pratiques » ne marche pas. J\'ai cliqué quinze fois. Peut-être seize.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Ça marche du premier coup ! J\'aurais dû cliquer seize fois depuis le début.' },
      { who: 'ines', expr: 'neutre', text: 'Quand un lien ne fait rien, compare-le à un lien qui marche. La différence saute souvent aux yeux.' }
    ]
  });

  T.add({
    id: 'T05', phase: 'html', page: 'index.html', edit: [],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Urgent ! Mon mot de passe de l\'ENT ne marche plus et je dois rendre un devoir de maths ce soir !' },
      { who: 'ines', expr: 'neutre', text: 'Avant de foncer, demande-toi si ce ticket est bien pour toi.' }
    ],
    done: [
      { who: 'ines', expr: 'sourire', text: 'Bon réflexe. Trier les tickets, ça fait partie du métier : chacun son périmètre.' }
    ]
  });
})(window.NekoTickets);
