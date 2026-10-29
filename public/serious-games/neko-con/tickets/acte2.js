/* Acte 2 — La relecture d'Inès (course HTML 02: semantic structure). Game half: bug, criteria, hints, scenes.
   Every test is self-contained: it uses only h and the browser's globals (it runs inside the preview). */
(function (T) {
  'use strict';

  T.add({
    id: 'T06', phase: 'html', page: 'index.html', edit: ['index.html'],
    bug: [
      { file: 'index.html', find: '<header class="entete">', replace: '<div class="entete">' },
      { file: 'index.html', find: '<nav aria-label="Menu principal">', replace: '<div class="menu-principal">' },
      { file: 'index.html', find: '    </nav>\n  </header>\n  <main>\n', replace: '    </div>\n  </div>\n  <div class="contenu">\n' },
      { file: 'index.html', find: '  </main>\n  <footer class="pied">', replace: '  </div>\n  <div class="pied">' },
      { file: 'index.html', find: '  </footer>\n</body>', replace: '  </div>\n</body>' }
    ],
    checks: [
      { label: 'Le lecteur d\'écran annonce la bannière, l\'en-tête du site avec le logo', test: function (h) {
        var ok = h.reader().some(function (i) { return i.kind === 'landmark' && /^Bannière/.test(i.text); });
        var e = h.$('header');
        return ok && !!e && !!e.querySelector('img');
      } },
      { label: 'Il annonce la navigation, avec les six liens du menu', test: function (h) {
        var ok = h.reader().some(function (i) { return i.kind === 'landmark' && /^Navigation/.test(i.text); });
        var n = h.$('nav');
        return ok && !!n && n.querySelectorAll('a[href]').length === 6;
      } },
      { label: 'Il annonce le contenu principal, qui commence par le titre « Neko-Con »', test: function (h) {
        var ok = h.reader().some(function (i) { return i.kind === 'landmark' && /^Contenu principal/.test(i.text); });
        return ok && h.$$('main').length === 1 && !!h.$('main h1');
      } },
      { label: 'Il annonce le pied de page, avec « Nous contacter »', test: function (h) {
        var ok = h.reader().some(function (i) { return i.kind === 'landmark' && /^Informations sur le site/.test(i.text); });
        var f = h.$('footer');
        return ok && !!f && /Nous contacter/.test(f.textContent);
      } }
    ],
    hints: [
      'Une <div> ne veut rien dire : c\'est une boîte anonyme. HTML a des balises qui disent le rôle de chaque zone : <header>, <nav>, <main>, <footer>. Cours HTML 02, La structure sémantique.',
      'Remplace la <div class="entete"> par <header class="entete">, la <div class="menu-principal"> par <nav>, la <div class="contenu"> par <main> et la <div class="pied"> par <footer class="pied">. Sans oublier les balises fermantes qui vont avec.'
    ],
    intro: [
      { who: 'ines', expr: 'soupir', text: 'J\'ai relu index.html. Tout le site est en <div>. Des <div> dans des <div>. À l\'écran, ça ne se voit pas ; pour un lecteur d\'écran, c\'est un mur sans portes.' },
      { who: 'ines', expr: 'neutre', text: 'Ouvre le lecteur d\'écran et demande-lui les repères de la page. Tu vas comprendre.' }
    ],
    done: [
      { who: 'ines', expr: 'sourire', text: 'Bannière, navigation, contenu principal, pied de page : la page a enfin un plan.' },
      { who: 'ines', expr: 'neutre', text: 'Réflexe à garder : avant d\'écrire une <div>, demande-toi si une balise dit déjà ce rôle. Et une page se vérifie aussi à l\'oreille, pas seulement à l\'œil.' }
    ]
  });

  T.add({
    id: 'T07', phase: 'html', page: 'index.html', edit: ['index.html'],
    bug: [
      { file: 'index.html', find: '<img src="img/logo.svg" alt="Neko-Con, accueil">', replace: '<img src="img/logo.svg">' },
      { file: 'index.html', find: 'src="img/mascotte.svg" alt="Neko, la mascotte de la convention : un chat blanc au bandeau rose">', replace: 'src="img/mascotte.svg">' },
      { file: 'index.html', find: '<p><a href="planning.html">Voir le planning complet</a></p>', replace: '<p>Pour voir le planning complet, <a href="planning.html">cliquez ici</a>.</p>' },
      { file: 'index.html', find: '<p><a href="infos.html">Venir à la convention</a></p>', replace: '<p>Pour venir à la convention, <a href="infos.html">cliquez ici</a>.</p>' }
    ],
    checks: [
      { label: 'Le lien du logo dit où il mène', test: function (h) {
        var i = h.$('.logo img') || h.$('a[href="index.html"] img');
        var alt = i ? (i.getAttribute('alt') || '').trim() : '';
        return { ok: alt.length >= 4 && !/^(logo|image|img|lien|icône|icone)$/i.test(alt) && !/\.(svg|png|jpe?g|gif)\b/i.test(alt), detail: alt ? 'Le lecteur dit : « Lien, ' + alt + ' »' : 'Le lecteur dit : « Lien, sans nom »' };
      } },
      { label: 'La mascotte est décrite : on sait ce qu\'il y a sur l\'image', test: function (h) {
        var i = h.$('img.mascotte');
        var alt = i ? (i.getAttribute('alt') || '').trim() : '';
        return !!i && alt.length >= 8 && !/^(mascotte|image|img|photo|dessin)$/i.test(alt) && !/\.(svg|png|jpe?g|gif)\b/i.test(alt);
      } },
      { label: 'Plus aucun lien « cliquez ici » : chaque lien dit où il mène', test: function (h) {
        var bad = h.reader().filter(function (i) { return i.kind === 'link' && /cliquez|clique ici|ici\s*$|sans nom/i.test(i.text); });
        return { ok: bad.length === 0, detail: bad.map(function (i) { return '« ' + i.text + ' »'; }).join(', ') };
      } },
      { label: 'Les liens vers le planning et vers les infos pratiques sont toujours là', test: function (h) {
        var p = h.$('main a[href="planning.html"]'), i = h.$('main a[href="infos.html"]');
        return !!p && !!i && h.text(p).length >= 6 && h.text(i).length >= 6;
      } }
    ],
    hints: [
      'Une image sans attribut alt n\'a pas de nom : le lecteur dit seulement « image ». Et un lien doit dire où il mène, même lu hors de sa phrase. Cours HTML 02, La structure sémantique (accessibilité).',
      'Ajoute un alt qui décrit l\'image (alt="Neko-Con, accueil" pour le logo, une courte description de la mascotte), puis mets le texte utile dans le lien lui-même : <a href="planning.html">Voir le planning complet</a>.'
    ],
    intro: [
      { who: 'client', from: 'Gérard Dubreuil', text: 'Bonjour. Je suis non-voyant et je navigue avec un lecteur d\'écran. Sur votre page d\'accueil, j\'entends « image », « image », puis deux fois « cliquez ici ». Je ne sais ni ce que je regarde, ni où je vais.' },
      { who: 'ines', expr: 'neutre', text: 'Mets-toi à sa place : ouvre le lecteur d\'écran et écoute la page comme lui.' }
    ],
    done: [
      { who: 'client', from: 'Gérard Dubreuil', text: '« Neko, la mascotte de la convention »… et un lien vers le planning qui s\'annonce comme tel. Merci, c\'est parfait. Je viendrai samedi.' },
      { who: 'ines', expr: 'sourire', text: 'Un bon alt dit ce que l\'image apporte, sans commencer par « image de » : le lecteur l\'annonce déjà. Et une image purement décorative prend un alt vide, alt="", pour qu\'il se taise.' }
    ]
  });

  T.add({
    id: 'T08', phase: 'html', page: 'index.html', edit: ['index.html'],
    bug: [
      { file: 'index.html',
        find: '<title>Neko-Con — la convention manga du lycée</title>\n  <meta name="description" content="Samedi au gymnase B : ateliers dessin, karaoké, concours de cosplay et dédicaces. La première convention manga du lycée.">',
        replace: '<title>Document</title>' }
    ],
    checks: [
      { label: 'Dans les résultats de recherche, le site s\'appelle « Neko-Con… »', test: function (h) {
        var t = h.search().title;
        return { ok: /neko-?con/i.test(t) && !/^document$/i.test(t.trim()), detail: 'Titre affiché : « ' + t + ' »' };
      } },
      { label: 'Un petit texte de présentation s\'affiche sous le titre (entre 50 et 160 caractères)', test: function (h) {
        var d = h.search().description.trim();
        return { ok: d.length >= 50 && d.length <= 160, detail: d ? d.length + ' caractères' : 'Aucune description' };
      } },
      { label: 'Le titre et le résumé sont donnés à Google, sans s\'afficher dans la page elle-même', test: function (h) {
        var m = h.doc.head && h.doc.head.querySelector('meta[name="description"]');
        return !!m && !!h.doc.head.querySelector('title');
      } }
    ],
    hints: [
      'Le nom affiché par Google et dans l\'onglet vient de la balise <title> ; le petit texte en dessous, de <meta name="description" content="…">. Les deux vont dans le <head>. Cours HTML 02, La structure sémantique.',
      'Écris un vrai titre, par exemple <title>Neko-Con — la convention manga du lycée</title>, puis ajoute en dessous <meta name="description" content="…"> avec une phrase qui donne envie de venir.'
    ],
    intro: [
      { who: 'maelle', expr: 'paniquee', text: 'Ma tante a cherché « Neko-Con » sur Google. Notre site s\'appelle « Document ». DOCUMENT ! Et il n\'y a rien écrit en dessous !' },
      { who: 'ines', expr: 'sourcil', text: 'Ouvre le moteur de recherche du poste : c\'est ce que voit sa tante. Ensuite, cherche d\'où ça vient.' }
    ],
    done: [
      { who: 'maelle', expr: 'etoiles', text: '« Neko-Con — la convention manga du lycée », avec une description ! On fait presque sérieux !' },
      { who: 'ines', expr: 'neutre', text: 'Chaque page mérite son propre titre, son sujet d\'abord, le nom du site ensuite : c\'est comme ça qu\'on la retrouve parmi dix onglets ouverts. Et un titre unique par page, Google aime aussi.' }
    ]
  });

  T.add({
    id: 'T09', phase: 'html', page: 'infos.html', edit: ['infos.html'],
    bug: [
      { file: 'infos.html', find: '<h2>Venir au gymnase</h2>', replace: '<h1>Venir au gymnase</h1>' },
      { file: 'infos.html', find: '<h3>Navette</h3>', replace: '<h5>Navette</h5>' },
      { file: 'infos.html', find: '<h2>Sur place</h2>', replace: '<h1>Sur place</h1>' },
      { file: 'infos.html', find: '<h3>Accessibilité</h3>', replace: '<h4>Accessibilité</h4>' }
    ],
    checks: [
      { label: 'La page n\'a qu\'un seul grand titre : « Infos pratiques »', test: function (h) {
        var h1 = h.$$('h1');
        return { ok: h1.length === 1 && /Infos pratiques/.test(h1[0].textContent), detail: h1.length === 1 ? '' : h1.length + ' titres de niveau 1' };
      } },
      { label: 'Dans le plan, aucun niveau n\'est sauté', test: function (h) {
        var hs = h.$$('h1, h2, h3, h4, h5, h6'), prev = 0, bad = [];
        hs.forEach(function (e) { var n = +e.tagName.charAt(1); if (n > prev + 1) bad.push(e.tagName.toLowerCase() + ' « ' + e.textContent.trim() + ' »'); prev = n; });
        return { ok: hs.length > 0 && bad.length === 0, detail: bad.length ? 'Niveau sauté : ' + bad.join(', ') : '' };
      } },
      { label: '« Venir au gymnase » et « Sur place » restent des titres de partie, avec chacun leurs rubriques en dessous', test: function (h) {
        var lv = {};
        h.$$('h1, h2, h3, h4, h5, h6').forEach(function (e) { lv[e.textContent.trim()] = +e.tagName.charAt(1); });
        var a = lv['Venir au gymnase'], b = lv['Sur place'];
        var ra = ['Se restaurer', 'En transport', 'Navette'], rb = ['Parking', 'Accessibilité', 'Informations'];
        return !!a && !!b && a > 1 && b > 1 &&
          ra.every(function (t) { return lv[t] > a; }) && rb.every(function (t) { return lv[t] > b; });
      } }
    ],
    hints: [
      'Un seul <h1> par page, pour son sujet. Ensuite, on descend d\'un niveau à la fois : h2 sous h1, h3 sous h2. Le niveau dit la place dans le plan, pas la taille du texte. Cours HTML 02, La structure sémantique.',
      '« Venir au gymnase » et « Sur place » sont des parties : <h2>. Les six rubriques qu\'elles contiennent sont toutes des <h3>, y compris « Navette » et « Accessibilité ».'
    ],
    intro: [
      { who: 'ines', expr: 'sourcil', text: 'Écoute le plan de infos.html au lecteur d\'écran : trois titres de niveau 1, puis on saute du 3 au 5, puis on remonte… C\'est un sommaire écrit par un chat sur un clavier.' },
      { who: 'maelle', expr: 'inquiete', text: 'C\'est moi… je trouvais « Navette » trop gros, alors j\'ai pris un numéro plus grand. Ça le rend plus petit, non ?' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'Tous les sous-titres sont au même niveau. C\'est bizarre, maintenant ça me paraît évident.' },
      { who: 'ines', expr: 'neutre', text: 'Réflexe : pour vérifier un plan, ne lis que les titres, à la suite, comme un sommaire. S\'ils ne racontent pas la page dans l\'ordre, le code non plus.' }
    ]
  });

  T.add({
    id: 'T10', phase: 'html', page: 'planning.html', edit: ['planning.html'],
    bug: [{ file: 'planning.html', find: '<img src="img/logo.svg" alt="Neko-Con, accueil">', replace: '<img src="../img/logo.svg" alt="Neko-Con, accueil">' }],
    checks: [
      { label: 'Le logo s\'affiche en haut de la page Planning', test: function (h) {
        var i = h.$('.logo img') || h.$('header img');
        return !!i && i.complete && i.naturalWidth > 0;
      } },
      { label: 'Il garde son texte alternatif et ramène toujours à l\'accueil', test: function (h) {
        var i = h.$('.logo img') || h.$('header img');
        var a = i && i.closest('a');
        return !!i && (i.getAttribute('alt') || '').trim().length >= 4 && !!a && a.getAttribute('href') === 'index.html';
      } }
    ],
    hints: [
      'Un chemin relatif part du dossier de la page. planning.html est à la racine du site, comme le dossier img. Et « ../ » veut dire : remonter d\'un dossier. Cours HTML 01, les chemins des images.',
      'Retire le « ../ » : src="img/logo.svg".'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Sur la page Planning, à la place du logo, il y a écrit « Neko-Con, accueil ». En petit. Tout seul. C\'est triste.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Le logo est revenu ! Et quand on clique dessus, on revient à l\'accueil, comme avant.' },
      { who: 'ines', expr: 'neutre', text: 'Tu as remarqué ? Le texte affiché, c\'était le alt : il remplace l\'image quand elle ne se charge pas. Encore une bonne raison de toujours en écrire un.' }
    ]
  });
})(window.NekoTickets);
