/* Acte 8 — « Ah, et j'avais oublié… » (course CSS 07: Grid). Game half: bug, criteria, hints, scenes. */
(function (T) {
  'use strict';

  T.add({
    id: 'T37', phase: 'css', page: 'exposants.html', edit: ['css/style.css'], show: ['exposants.html'],
    bug: [{ file: 'css/style.css',
      find: '  grid-template-areas:\n    "scene scene dedicaces"\n',
      replace: '  grid-template-areas:\n    "scene dedicaces scene"\n' }],
    checks: [
      { label: 'La scène occupe les deux premières cases de la première rangée', width: 1280, test: function (h) {
        var p = h.rect('.plan'), s = h.rect('.scene'), d = h.rect('.dedicaces');
        if (!p || !s || !d) return false;
        return h.near(s.left, p.left, 2) && h.near(s.top, p.top, 2) && s.width > d.width * 1.6;
      } },
      { label: 'L\'espace dédicaces est dans le coin droit, à côté de la scène', width: 1280, test: function (h) {
        var p = h.rect('.plan'), s = h.rect('.scene'), d = h.rect('.dedicaces');
        if (!p || !s || !d) return false;
        return h.near(d.top, s.top, 2) && d.left >= s.right && h.near(d.right, p.right, 2);
      } },
      { label: 'Les six stands sont en dessous, trois par rangée', width: 1280, test: function (h) {
        var r = ['.s1', '.s2', '.s3', '.s4', '.buvette', '.entree'].map(function (c) { return h.rect(c); });
        var s = h.rect('.scene');
        if (!s || r.some(function (x) { return !x; })) return false;
        var row = function (a, b, c) { return h.near(a.top, b.top, 2) && h.near(b.top, c.top, 2) && a.right <= b.left && b.right <= c.left; };
        return r[0].top >= s.bottom && row(r[0], r[1], r[2]) && row(r[3], r[4], r[5]) && r[3].top >= r[0].bottom;
      } }
    ],
    hints: [
      'Une zone nommée doit former un rectangle : ses cases doivent se toucher. Sinon, le navigateur jette toute la propriété, et les zones n\'ont plus de place où aller. Cours CSS 07, Grid, les zones nommées.',
      'La première rangée doit être "scene scene dedicaces" : les deux cases de la scène côte à côte, puis les dédicaces.'
    ],
    intro: [
      { who: 'maelle', expr: 'paniquee', text: 'Le plan des stands ! Tout est empilé dans un coin ! On dirait un mille-feuille de stands !' },
      { who: 'ines', expr: 'neutre', text: 'Une grille dont les stands se marchent dessus, c\'est souvent une grille qui a perdu son plan. Regarde la règle .plan.' }
    ],
    done: [
      { who: 'maelle', expr: 'etoiles', text: 'C\'est exactement le plan papier ! La scène au fond, les dédicaces dans le coin, les stands en dessous !' },
      { who: 'ines', expr: 'neutre', text: 'Une habitude à prendre : aligne les noms de zones les uns sous les autres, en colonnes, comme une vraie grille. Une case de travers se voit alors au premier coup d\'œil, avant même l\'aperçu.' }
    ]
  });

  T.add({
    id: 'T38', phase: 'css', page: 'exposants.html', edit: ['css/style.css'], show: ['exposants.html'],
    bug: [{ file: 'css/style.css',
      find: '  grid-template-columns: 1fr 1fr 1fr;\n  grid-template-areas:',
      replace: '  grid-template-columns: 200px 200px 1fr;\n  grid-template-areas:' }],
    checks: [
      { label: 'Les trois colonnes du plan ont la même largeur', width: 1280, test: function (h) {
        var a = h.rect('.s1'), b = h.rect('.s2'), c = h.rect('.s3');
        if (!a || !b || !c) return false;
        return { ok: h.near(a.width, b.width, 3) && h.near(b.width, c.width, 3), detail: 'Colonnes de ' + [a, b, c].map(function (x) { return Math.round(x.width); }).join(', ') + ' px' };
      } },
      { label: 'L\'espace dédicaces fait une case, la moitié de la scène', width: 1280, test: function (h) {
        var s = h.rect('.scene'), d = h.rect('.dedicaces'), a = h.rect('.s1');
        if (!s || !d || !a) return false;
        return h.near(d.width, a.width, 3) && s.width > d.width * 1.8;
      } },
      { label: 'Le plan remplit toute la largeur de la page', width: 1280, test: function (h) {
        var p = h.rect('.plan'), c = h.rect('.s3');
        return !!p && !!c && h.near(c.right, p.right, 2) && !h.overflowX();
      } }
    ],
    hints: [
      'fr ne partage que l\'espace libre, une fois les tailles fixes retirées. Avec 200px 200px 1fr, la dernière colonne prend tout le reste. Cours CSS 07, Grid, l\'unité fr.',
      'Donne la même part aux trois colonnes : grid-template-columns: 1fr 1fr 1fr; (ou repeat(3, 1fr)).'
    ],
    intro: [
      { who: 'mika', expr: 'neutre', text: 'Bonjour ! Je suis Mika, l\'autrice invitée de samedi. J\'ai regardé le plan… Mon espace dédicaces est plus grand que la scène.' },
      { who: 'mika', expr: 'joie', text: 'C\'est flatteur, mais je ne vais pas signer des autographes dans la moitié du gymnase.' }
    ],
    done: [
      { who: 'mika', expr: 'joie', text: 'Parfait, une case comme tout le monde. Tu passes au stand 4 samedi ? Je te dessine un chat.' },
      { who: 'ines', expr: 'neutre', text: 'Petit raccourci à retenir : repeat(3, 1fr) écrit la même chose en plus court. Pratique le jour où Maëlle voudra douze stands par rangée.' }
    ]
  });
})(window.NekoTickets);
