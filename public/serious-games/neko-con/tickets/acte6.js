/* Acte 6 — L'avalanche (courses CSS 02 to 05). Game half: bug, criteria, hints, scenes.
   Every test is self-contained: it uses only its parameter h (it runs inside the preview). */
(function (T) {
  'use strict';

  T.add({
    id: 'T24', phase: 'css', page: 'billetterie.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '#billets .prix {', replace: '.prix {' }],
    checks: [
      { label: 'Les trois prix sont roses, bien visibles', test: function (h) {
        var p = h.$$('.prix');
        return p.length === 3 && p.every(function (x) { return h.color(x) === 'rgb(232,69,122)'; });
      } },
      { label: 'La phrase sur les ateliers reste en gris discret', test: function (h) {
        var p = h.$('#billets > p');
        return !!p && h.color(p) === 'rgb(138,131,153)';
      } }
    ],
    forbid: [{ file: 'css/style.css', pattern: /!important/, label: 'Sans !important : Inès relit la feuille de style' }],
    hints: [
      'Quand deux règles se contredisent, c\'est la plus spécifique qui gagne : un id pèse plus lourd que n\'importe quel nombre de classes. Cours CSS 02, Sélecteurs et cascade : la spécificité.',
      'Donne au sélecteur des prix au moins autant de poids que #billets p, par exemple #billets .prix.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Trois personnes m\'ont demandé si la billetterie était fermée. Les prix sont gris, comme s\'ils étaient barrés.' },
      { who: 'ines', expr: 'neutre', text: 'Deux règles se disputent la couleur des prix. Ne règle pas le duel à coups de !important : comprends pourquoi l\'une gagne.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Rose ! Je vais répondre aux trois personnes. Enfin, aux deux : la troisième, c\'était moi.' },
      { who: 'ines', expr: 'sourire', text: 'Et tu n\'as pas sorti !important. Bien : il gagne tout de suite, mais le jour où il faut le battre, il ne reste qu\'un autre !important. Après, c\'est la surenchère.' }
    ]
  });

  T.add({
    id: 'T25', phase: 'css', page: 'index.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '.btn:hover {', replace: '.btn :hover {' }],
    checks: [
      { label: 'Le bouton « Prendre mon billet » change de couleur quand on le survole', test: function (h) {
        var b = h.$('.hero .btn');
        if (!b) return false;
        return h.rules().some(function (r) {
          return r.selector.split(',').some(function (s) {
            s = s.trim();
            if (!/[^\s>+~]:hover/.test(s)) return false;
            try {
              return b.matches(s.replace(/:hover/g, '')) &&
                !!(r.style.getPropertyValue('background-color') || r.style.getPropertyValue('color'));
            } catch (e) { return false; }
          });
        });
      } },
      { label: 'Au repos, il reste jaune', test: function (h) { return h.color('.hero .btn', 'background-color') === 'rgb(255,210,63)'; } }
    ],
    hints: [
      'Dans un sélecteur, une espace veut dire « à l\'intérieur de ». Cours CSS 02, Sélecteurs et cascade : combinateurs et pseudo-classes.',
      'Colle :hover à .btn : écris .btn:hover, sans espace.'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'Le gros bouton jaune devenait rose quand on passait la souris dessus. C\'était mon effet préféré du site ! Il ne le fait plus.' },
      { who: 'ines', expr: 'neutre', text: 'Tu ne peux pas survoler dans les tests, mais tu peux lire le sélecteur. Lis-le comme le navigateur : chaque caractère compte.' }
    ],
    done: [
      { who: 'maelle', expr: 'etoiles', text: 'Jaune… rose ! Jaune… rose ! Je pourrais faire ça toute la journée.' },
      { who: 'ines', expr: 'neutre', text: 'Retiens ce réflexe : quand un style ne s\'applique pas, vérifie d\'abord que sa règle vise bien l\'élément. Une règle qui ne sélectionne personne ne provoque aucune erreur : elle attend, en silence.' }
    ]
  });

  T.add({
    id: 'T26', phase: 'css', page: 'index.html', edit: [], show: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '.hero {\n  position: relative;\n  padding: 40px 0;\n}', replace: '.hero {\n  position: relative;\n  padding: 40px 0;\n  font-style: italic;\n}' }],
    hints: [
      'La règle .slogan ne parle pas d\'italique. Cherche dans les règles des éléments qui contiennent le slogan.',
      'Certaines propriétés, comme la couleur, la police ou le style du texte, se transmettent des parents aux enfants. Cours CSS 02, Sélecteurs et cascade : l\'héritage.',
      'Regarde la règle .hero : la section qui contient le titre, le slogan, les horaires et le bouton.'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'Le slogan est en italique. Pourquoi il est en italique ? Ne touche à rien, j\'ai une réunion avec le proviseur dans cinq minutes !' },
      { who: 'ines', expr: 'sourire', text: 'Un ticket d\'analyse, pour une fois. Tu ne modifies rien : tu trouves la cause et tu l\'expliques.' }
    ],
    done: [
      { who: 'maelle', expr: 'neutre', text: 'D\'accord… donc ce n\'est pas le slogan, c\'est toute la section. Je décide après la réunion si on garde. Merci !' },
      { who: 'ines', expr: 'neutre', text: 'Une propriété héritée descend sur tous les descendants qui ne disent rien d\'autre. Quand un style vient de nulle part, regarde les parents.' }
    ]
  });

  T.add({
    id: 'T27', phase: 'css', page: 'infos.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '  width: 50%;\n  padding: 20px;\n  box-sizing: border-box;\n}', replace: '  width: 50%;\n  padding: 20px;\n}' }],
    checks: [
      { label: '« Venir au gymnase » et « Sur place » sont côte à côte', width: 1280, test: function (h) {
        var c = h.$$('.col');
        return c.length === 2 && h.near(h.rect(c[0]).top, h.rect(c[1]).top, 2) && h.rect(c[1]).left > h.rect(c[0]).left;
      } },
      { label: 'Les deux colonnes ne dépassent pas de la page', width: 1280, test: function (h) {
        var c = h.$$('.col'), box = h.rect('.deux-colonnes');
        return c.length === 2 && !!box && c.every(function (x) { return h.rect(x).right <= box.right + 1; });
      } },
      { label: 'Le texte ne colle pas aux bords des colonnes', width: 1280, test: function (h) {
        return h.$$('.col').every(function (x) { return h.px(x, 'padding-left') >= 10 && h.px(x, 'padding-right') >= 10; });
      } }
    ],
    hints: [
      'Par défaut, width ne compte que le contenu : padding et bordure s\'ajoutent par-dessus. box-sizing change ce calcul. Cours CSS 03, Le modèle de boîte.',
      'Ajoute box-sizing: border-box; dans la règle .col.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Sur la page Infos pratiques, la colonne « Sur place » est tombée en dessous de l\'autre. Il y a pourtant de la place à droite.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Côte à côte, réconciliées. Je n\'aurais jamais cru qu\'une colonne pouvait faire plus de la moitié en faisant la moitié.' },
      { who: 'ines', expr: 'sourire', text: 'Beaucoup de sites commencent leur feuille de style par *, *::before, *::after { box-sizing: border-box; }. La largeur annoncée devient la largeur réelle, partout. Note-le pour ton prochain site.' }
    ]
  });

  T.add({
    id: 'T28', phase: 'css', page: 'index.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '.btn {\n  display: inline-block;\n  padding: 12px 26px;', replace: '.btn {\n  padding: 12px 26px;' }],
    checks: [
      { label: 'Le bouton « Prendre mon billet » est bien large (320 px)', test: function (h) {
        var r = h.rect('.hero .btn-large');
        return !!r && h.near(r.width, 320, 2);
      } }
    ],
    hints: [
      'Un lien <a> est un élément en ligne (inline) : width et height ne s\'y appliquent pas. La propriété display change ce comportement. Cours CSS 03, Le modèle de boîte : display.',
      'Ajoute display: inline-block; dans la règle .btn.'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'Le bouton de l\'accueil devait être un GROS bouton, impossible à rater. Il est tout maigre, collé à son texte.' },
      { who: 'theo', expr: 'neutre', text: 'Moi je dis : on remplace le lien par un <button>. Un bouton, c\'est un bouton.' },
      { who: 'ines', expr: 'sourcil', text: 'Ou alors on comprend pourquoi la largeur ne s\'applique pas. Avant de toucher au HTML.' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'Ça, c\'est un bouton ! On ne peut plus le rater.' },
      { who: 'ines', expr: 'neutre', text: 'La balise se choisit pour ce qu\'elle veut dire, l\'apparence se règle en CSS. Un lien habillé en bouton reste un lien : le clavier, les lecteurs d\'écran et « ouvrir dans un nouvel onglet » marchent toujours.' }
    ]
  });

  T.add({
    id: 'T29', phase: 'css', page: 'index.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '.carte {\n  position: relative;\n  flex: 0 0 240px;', replace: '.carte {\n  flex: 0 0 240px;' }],
    checks: [
      { label: 'Le badge « Dédicace 14 h » est posé sur le haut de ma carte', test: function (h) {
        var b = h.rect('.carte-dedicace .badge'), c = h.rect('.carte-dedicace');
        if (!b || !c) return false;
        return b.left >= c.left - 2 && b.right <= c.right + 2 && b.top >= c.top - 20 && b.top <= c.top + 4;
      } }
    ],
    locked: [{ file: 'css/style.css', rule: '.badge', label: 'Le badge garde son style (.badge non modifiée)' }],
    hints: [
      'Un élément absolute se place par rapport à son plus proche ancêtre positionné. S\'il n\'y en a aucun, c\'est la page entière. Cours CSS 04, Le positionnement.',
      'Ajoute position: relative; dans la règle .carte, sans rien changer à .badge.'
    ],
    intro: [
      { who: 'mika', expr: 'neutre', text: 'Bonjour ! Je suis Mika, l\'invitée des dédicaces. Mon badge « Dédicace 14 h » s\'est envolé de ma carte… Je l\'ai aperçu, tout en haut de la page.' },
      { who: 'ines', expr: 'neutre', text: 'Elle a dessiné ce badge elle-même. Tu ne touches pas à son style : trouve plutôt pourquoi il ne sait plus où se poser.' }
    ],
    done: [
      { who: 'mika', expr: 'joie', text: 'Le revoilà, sur son coin de carte ! Merci, je vous en dessinerai un aussi.' },
      { who: 'ines', expr: 'sourire', text: 'Un réflexe : chaque fois que tu écris position: absolute, demande-toi tout de suite « par rapport à qui ? », et vérifie que ce parent-là est bien positionné. Ça évite les badges en cavale.' }
    ]
  });

  T.add({
    id: 'T30', phase: 'css', page: 'planning.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '.entete {\n  position: sticky;\n  top: 0;', replace: '.entete {\n  position: fixed;\n  top: 0;\n  left: 0;\n  right: 0;' }],
    checks: [
      { label: 'Sur le planning, le titre « Planning du samedi » s\'affiche en entier, sous la barre du menu', page: 'planning.html', width: 1280, test: function (h) {
        var t = h.rect('main h1'), e = h.rect('.entete');
        return !!t && !!e && t.top >= e.bottom - 1;
      } },
      { label: 'La barre du menu reste accrochée en haut de l\'écran', page: 'planning.html', width: 1280, test: function (h) {
        var p = h.css('.entete', 'position'), e = h.rect('.entete');
        return (p === 'sticky' || p === 'fixed') && !!e && h.near(e.top, 0, 1);
      } }
    ],
    hints: [
      'Un élément fixed sort du flux : les autres éléments se placent comme s\'il n\'existait pas. Un élément sticky garde sa place, puis colle en haut au défilement. Cours CSS 04, Le positionnement.',
      'Remets position: sticky; dans .entete (left et right deviennent alors inutiles). Ou garde fixed et décale le contenu de la hauteur de l\'en-tête.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Sur le planning, le titre a disparu sous la barre du menu. Je l\'ai cherché partout, il est juste… dessous.' },
      { who: 'ines', expr: 'sourcil', text: 'Quelqu\'un a voulu que le menu reste en haut quand on fait défiler. L\'intention est bonne. La valeur choisie, beaucoup moins.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Le titre est revenu ! Et le menu reste en haut quand on descend. Les deux en même temps, c\'est possible, donc.' },
      { who: 'ines', expr: 'neutre', text: 'Après un changement de position, fais défiler chaque page jusqu\'en bas et remonte : c\'est là qu\'on voit ce qui se cache. Tiens, justement : il y avait une note cachée sous l\'en-tête.' }
    ]
  });

  T.add({
    id: 'T31', phase: 'css', page: 'index.html', edit: ['css/style.css'],
    bug: [{ file: 'css/style.css', find: '.menu {\n  display: flex;', replace: '.menu {\n  display: block;' }],
    checks: [
      { label: 'Les six liens du menu sont sur la même ligne', width: 1280, test: function (h) {
        var li = h.$$('.menu li');
        if (li.length !== 6) return false;
        var top = h.rect(li[0]).top;
        return li.every(function (x) { return h.near(h.rect(x).top, top, 3); });
      } },
      { label: 'L\'en-tête redevient une barre fine', width: 1280, test: function (h) {
        var e = h.rect('.entete');
        return !!e && e.height <= 100;
      } }
    ],
    hints: [
      'Les propriétés de flexbox ne s\'appliquent qu\'aux enfants d\'un élément en display: flex. Sinon, une liste reste une liste, en colonne. Cours CSS 05, Flexbox.',
      'Dans .menu, remplace display: block; par display: flex;.'
    ],
    intro: [
      { who: 'maelle', expr: 'paniquee', text: 'Le menu ! Tous les liens sont les uns sous les autres, l\'en-tête prend la moitié de l\'écran ! On ne voit plus le site !' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'Une belle ligne bien rangée. Je respire.' },
      { who: 'ines', expr: 'neutre', text: 'Le navigateur ne signale jamais une propriété sans effet. Les outils de développement, si : ils marquent souvent les déclarations inactives. Quand une règle semble ignorée, c\'est le premier endroit où regarder.' }
    ]
  });

  T.add({
    id: 'T32', phase: 'css', page: 'index.html', edit: ['css/style.css'], startDevice: 768,
    bug: [{ file: 'css/style.css', find: '.cartes {\n  display: flex;\n  flex-wrap: wrap;\n  gap: 16px;', replace: '.cartes {\n  display: flex;\n  gap: 16px;' }],
    checks: [
      { label: 'Sur la tablette, on n\'a pas besoin de faire glisser l\'écran sur le côté', width: 768, test: function (h) { return !h.overflowX(); } },
      { label: 'Sur la tablette, toutes les cartes du programme sont entièrement visibles', width: 768, test: function (h) {
        var w = h.doc.documentElement.clientWidth;
        return h.$$('.cartes .carte').every(function (c) { var r = h.rect(c); return r.left >= 0 && r.right <= w + 1; });
      } },
      { label: 'Sur un grand écran, les cartes restent côte à côte', width: 1280, test: function (h) {
        var c = h.$$('.cartes .carte');
        return c.length === 4 && h.near(h.rect(c[0]).top, h.rect(c[1]).top, 2) && h.near(h.rect(c[1]).top, h.rect(c[2]).top, 2);
      } }
    ],
    hints: [
      'Par défaut, un conteneur flex met tous ses éléments sur une seule ligne, quitte à déborder. flex-wrap autorise le retour à la ligne. Cours CSS 05, Flexbox.',
      'Ajoute flex-wrap: wrap; dans la règle .cartes.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Sur la tablette du stand d\'accueil, les cartes du programme partent vers la droite. La dernière est carrément hors de l\'écran.' },
      { who: 'ines', expr: 'neutre', text: 'L\'aperçu s\'ouvre en largeur tablette, 768 px. Vérifie aussi qu\'en grand écran, rien ne casse.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Deux cartes par ligne sur la tablette, tout est visible. Personne n\'aura besoin de faire glisser.' },
      { who: 'ines', expr: 'sourire', text: 'Et tu as vérifié en grand écran aussi : bon réflexe. Une mise en page se teste à plusieurs largeurs, téléphone, tablette, ordinateur, jamais à une seule.' }
    ]
  });

  T.add({
    id: 'T33', phase: 'css', page: 'index.html', edit: ['css/style.css'],
    bug: [
      { file: 'css/style.css', find: '.bloc-programme {\n  flex: 2 1 0;', replace: '.bloc-programme {\n  flex-grow: 2;' },
      { file: 'css/style.css', find: '.bloc-infos {\n  flex: 1 1 0;', replace: '.bloc-infos {\n  flex-grow: 1;' }
    ],
    checks: [
      { label: '« Une journée entière » est deux fois plus large que « Infos »', width: 1280, test: function (h) {
        var a = h.$('.bloc-programme'), b = h.$('.bloc-infos');
        if (!a || !b) return false;
        var wa = h.rect(a).width - h.px(a, 'padding-left') - h.px(a, 'padding-right');
        var wb = h.rect(b).width - h.px(b, 'padding-left') - h.px(b, 'padding-right');
        var ratio = wb > 0 ? wa / wb : 0;
        return { ok: Math.abs(ratio - 2) <= 0.1, detail: 'rapport mesuré : ' + ratio.toFixed(2).replace('.', ',') };
      } },
      { label: 'Les deux blocs restent côte à côte', width: 1280, test: function (h) {
        var a = h.rect('.bloc-programme'), b = h.rect('.bloc-infos');
        return !!a && !!b && h.near(a.top, b.top, 2) && b.left > a.right;
      } }
    ],
    hints: [
      'flex-grow ne multiplie pas la largeur : il partage l\'espace libre qui reste après la taille de départ (flex-basis). Avec flex-basis: auto, cette taille de départ est celle du contenu. Cours CSS 05, Flexbox : flex-grow et flex-basis.',
      'Fais partir les deux blocs de zéro : flex: 2 1 0; pour .bloc-programme et flex: 1 1 0; pour .bloc-infos.'
    ],
    intro: [
      { who: 'maelle', expr: 'neutre', text: 'Sur ma maquette, « Une journée entière » fait deux fois la largeur de « Infos ». J\'ai mesuré à la règle, directement sur l\'écran.' },
      { who: 'maelle', expr: 'inquiete', text: 'Jordan avait mis « 2 » et « 1 ». Deux fois un, ça fait deux, non ? Alors pourquoi ce n\'est pas deux fois plus large ?' }
    ],
    done: [
      { who: 'maelle', expr: 'etoiles', text: 'Deux fois plus large, pile comme sur la maquette. Ma règle et moi sommes satisfaites.' },
      { who: 'ines', expr: 'neutre', text: 'Préfère la notation courte flex, qui donne d\'un coup les trois valeurs : grandir, rétrécir, taille de départ. Avec flex-grow seul, les deux autres gardent leur valeur par défaut, et c\'est là que les surprises se cachent.' }
    ]
  });
})(window.NekoTickets);
