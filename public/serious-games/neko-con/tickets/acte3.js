/* Acte 3 — Le planning (course HTML 03: tables). Game half: bug, criteria, hints, scenes.
   Every test is self-contained: it uses only h and the browser's globals (it runs inside the preview). */
(function (T) {
  'use strict';

  T.add({
    id: 'T11', phase: 'html', page: 'planning.html', edit: ['planning.html'],
    bug: [{ file: 'planning.html', find: '          <td>Défilé libre</td>\n        </tr>', replace: '          <td>Défilé libre</td>\n          <td>Rétrogaming (suite)</td>\n        </tr>' }],
    checks: [
      { label: 'Chaque ligne du planning a exactement 5 cases : plus rien ne dépasse', test: function (h) {
        var g = h.grid('table.planning') , bad = [];
        g.forEach(function (row, r) {
          var n = 0; for (var c = 0; c < row.length; c++) if (row[c]) n++;
          if (row.length !== 5 || n !== 5) bad.push((row[0] ? row[0].textContent.trim() : 'ligne ' + (r + 1)) + ' : ' + row.length + ' cases');
        });
        return { ok: g.length > 0 && bad.length === 0, detail: bad.join(', ') };
      } },
      { label: 'La « Conférence shōjo » est bien dans la colonne Salle 2, à 11 h', test: function (h) {
        var g = h.grid('table.planning');
        if (!g.length || !g[0][2] || g[0][2].textContent.trim() !== 'Salle 2') return false;
        return g.some(function (row) { return row[0] && row[0].textContent.trim() === '11 h' && row[2] && row[2].textContent.trim() === 'Conférence shōjo'; });
      } },
      { label: 'Le rétrogaming occupe toujours la Salle 3 de 10 h à midi', test: function (h) {
        var g = h.grid('table.planning'), col = -1, r10 = -1;
        if (!g.length) return false;
        g[0].forEach(function (c, k) { if (c && c.textContent.trim() === 'Salle 3') col = k; });
        g.forEach(function (row, r) { if (row[0] && row[0].textContent.trim() === '10 h') r10 = r; });
        if (col < 0 || r10 < 0 || !g[r10 + 1]) return false;
        var a = g[r10][col], b = g[r10 + 1][col];
        return !!a && !!b && /Rétrogaming/i.test(a.textContent) && /Rétrogaming/i.test(b.textContent);
      } }
    ],
    hints: [
      'rowspan="2" fait descendre une case sur deux lignes : elle occupe aussi la même colonne dans la ligne suivante, qui doit donc avoir une case de moins. Cours HTML 03, Les tableaux.',
      'Le Rétrogaming de 10 h a déjà rowspan="2" : supprime la case « Rétrogaming (suite) » de la ligne de 11 h.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Sur le planning, à 11 h, il y a une case qui dépasse à droite du tableau. Elle n\'a même pas de titre de colonne. Elle flotte.' },
      { who: 'ines', expr: 'neutre', text: 'Un tableau HTML, c\'est une grille. Si une ligne a une case de trop, c\'est que quelqu\'un a mal compté. Compte avec lui.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Plus rien ne dépasse ! Le rétrogaming tient bien ses deux heures en Salle 3.' },
      { who: 'ines', expr: 'neutre', text: 'Réflexe pour les tableaux : avant de livrer, compte les cases de chaque ligne, y compris celles qui descendent d\'au-dessus. Le total doit être le même partout.' }
    ]
  });

  T.add({
    id: 'T12', phase: 'html', page: 'planning.html', edit: ['planning.html'],
    bug: [{ file: 'planning.html', find: '<td class="pause" colspan="4">', replace: '<td class="pause">' }],
    checks: [
      { label: 'La pause déjeuner s\'étend sur toutes les salles, de la Salle 1 à la Salle 3', test: function (h) {
        var g = h.grid('table.planning');
        var row = g.filter(function (r) { return r[0] && r[0].textContent.trim() === '12 h'; })[0];
        if (!row || row.length !== 5) return { ok: false, detail: row ? 'La ligne de midi fait ' + row.length + ' colonnes sur 5' : 'Ligne de 12 h introuvable' };
        var p = row[1];
        return !!p && /Pause déjeuner/.test(p.textContent) && row[2] === p && row[3] === p && row[4] === p;
      } },
      { label: 'Toutes les lignes du planning ont leurs 5 colonnes', test: function (h) {
        var g = h.grid('table.planning');
        return g.length === 8 && g.every(function (row) { return row.length === 5 && row.every(Boolean); });
      } }
    ],
    hints: [
      'Pour qu\'une case s\'étende sur plusieurs colonnes, on lui donne l\'attribut colspan, avec le nombre de colonnes qu\'elle couvre. Cours HTML 03, Les tableaux.',
      'La case de l\'heure occupe déjà la première colonne : il en reste 4. Écris <td class="pause" colspan="4">.'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'Sur le planning, la pause déjeuner est coincée dans la case Salle 1. Les gens vont croire que les food trucks sont dans la salle de dessin !' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'La pause traverse tout le planning. On voit bien que tout le monde mange en même temps. Ramen pour tous !' },
      { who: 'ines', expr: 'neutre', text: 'Fusionner une case pour une pause, c\'est très bien. Mais garde les tableaux pour ce qui est vraiment une grille de données, comme ce planning : pour mettre une page en forme, ce sera le travail du CSS.' }
    ]
  });

  T.add({
    id: 'T13', phase: 'html', page: 'planning.html', edit: ['planning.html'],
    bug: [
      { file: 'planning.html',
        find: '          <th scope="col">Horaire</th>\n          <th scope="col">Salle 1</th>\n          <th scope="col">Salle 2</th>\n          <th scope="col">Scène</th>\n          <th scope="col">Salle 3</th>',
        replace: '          <td>Horaire</td>\n          <td>Salle 1</td>\n          <td>Salle 2</td>\n          <td>Scène</td>\n          <td>Salle 3</td>' },
      { file: 'planning.html', find: '<th scope="row">', replace: '<th>' }
    ],
    checks: [
      { label: 'Pour « Conférence shōjo », le lecteur d\'écran annonce la salle (Salle 2) et l\'heure (11 h)', test: function (h) {
        var c = h.reader().filter(function (i) { return i.kind === 'cell' && /Conférence shōjo/.test(i.text); })[0];
        return { ok: !!c && /Salle 2/.test(c.text) && /11 h/.test(c.text), detail: c ? 'Le lecteur dit : « ' + c.text + ' »' : '' };
      } },
      { label: 'Pareil ailleurs : « Escape room » est annoncée avec Salle 3 et 15 h', test: function (h) {
        var c = h.reader().filter(function (i) { return i.kind === 'cell' && /Escape room/.test(i.text); })[0];
        return { ok: !!c && /Salle 3/.test(c.text) && /15 h/.test(c.text), detail: c ? 'Le lecteur dit : « ' + c.text + ' »' : '' };
      } },
      { label: 'Le planning garde ses 8 lignes et ses 5 colonnes', test: function (h) {
        var g = h.grid('table.planning');
        return g.length === 8 && g.every(function (row) { return row.length === 5 && row.every(Boolean); });
      } }
    ],
    hints: [
      'Une case de titre s\'écrit <th>, pas <td> ; et l\'attribut scope dit si ce titre vaut pour sa colonne (scope="col") ou pour sa ligne (scope="row"). Cours HTML 03, Les tableaux.',
      'Dans <thead>, écris les cinq titres en <th scope="col">. Dans <tbody>, donne scope="row" à chaque <th> d\'horaire.'
    ],
    intro: [
      { who: 'client', from: 'Gérard Dubreuil', text: 'Rebonjour. Sur le planning, mon lecteur me lit « Conférence shōjo », « Défilé libre », « Karaoké anisong »… mais jamais ni la salle, ni l\'heure. Je connais le programme, mais pas où ni quand.' },
      { who: 'ines', expr: 'neutre', text: 'Un tableau, à l\'oreille, c\'est une suite de cases. Ouvre le lecteur d\'écran et écoute-le comme Gérard, case par case.' }
    ],
    done: [
      { who: 'client', from: 'Gérard Dubreuil', text: '« Salle 2, 11 h : Conférence shōjo. » Voilà qui est clair. Je serai au premier rang. Façon de parler.' },
      { who: 'ines', expr: 'sourire', text: 'Un tableau ne se vérifie pas qu\'à l\'œil. Écoute quelques cases au lecteur d\'écran, au milieu et dans un coin : chacune doit s\'annoncer avec sa salle et son heure.' }
    ]
  });

  T.add({
    id: 'T14', phase: 'html', page: 'exposants.html', edit: [],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Alerte rouge ! Le distributeur de boissons du gymnase est vide ! Et samedi, il va faire chaud, avec les cosplays !' },
      { who: 'ines', expr: 'sourcil', text: 'Le distributeur. Du gymnase. Relis bien le ticket avant de lancer l\'éditeur.' }
    ],
    done: [
      { who: 'ines', expr: 'sourire', text: 'Tout ce qui arrive dans ta file n\'est pas un bug du site. Le trier vite et bien, c\'est aussi rendre service.' }
    ]
  });
})(window.NekoTickets);
