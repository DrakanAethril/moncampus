/* Service de nuit — acte 3, « Les couloirs » (N11-N17), the game's half. */
(function (root) {
  'use strict';
  var ST = root.SDNSteps, sees = ST.sees, reg = ST.reg;
  var L = function () { return Array.prototype.slice.call(arguments).join('\n'); };

  function page(title, h1, body) {
    return L('<!DOCTYPE html>', '<html lang="fr">', '<head>', '  <meta charset="utf-8">', '  <title>' + title + ' · Hôtel des Quatre-Vents</title>', '</head>', '<body>', '  <h1>' + h1 + '</h1>', body, '</body>', '</html>', '');
  }
  var douze = [];
  for (var c = 1; c <= 12; c++) douze.push('    <li>Chambre ' + c + '</li>');
  var CHAMBRES = page('Nos chambres', 'Nos chambres', L('  <ul>', douze.join('\n'), '  </ul>'));

  function rooms(body) { return page('Nos chambres', 'Nos chambres', L('  <ul>', '<?php', 'for ($i = 1; $i <= $ouvertes; $i++) {', body, '}', '?>', '  </ul>')); }
  var N11 = rooms('    echo \'    <li>Chambre \' . $i . \'</li>\' . "\\n";');
  var SIDES = L(
    '    if ($i % 2 == 0) {',
    '        echo \'    <li>Chambre \' . $i . \' : côté cour</li>\' . "\\n";',
    '    } else {',
    '        echo \'    <li>Chambre \' . $i . \' : côté rue</li>\' . "\\n";',
    '    }');
  var SKIP = L('    if ($i == 13) {', '        continue;', '    }');
  var N14 = rooms(SIDES);
  var N15 = rooms(SKIP + '\n' + SIDES);
  var N16 = rooms(L(
    SKIP,
    '    if ($i == $travaux) {',
    '        echo \'    <li>Chambre \' . $i . \' : fermée</li>\' . "\\n";',
    '    } elseif ($i % 2 == 0) {',
    '        echo \'    <li>Chambre \' . $i . \' : côté cour</li>\' . "\\n";',
    '    } else {',
    '        echo \'    <li>Chambre \' . $i . \' : côté rue</li>\' . "\\n";',
    '    }'));

  var NAVETTE = page('La navette', 'La navette de la gare', L(
    '  <p>Départ devant l\'hôtel, toutes les 25 minutes.</p>',
    '<?php',
    '// Sami : mktime(5, 40, 0) donne 5 h 40 aujourd\'hui, comptée en secondes.',
    '// date(\'H:i\', $depart) écrit cette heure-là, et time() donne l\'heure qu\'il est, en secondes aussi.',
    '$depart = mktime(5, 40, 0);',
    'echo \'<ul>\';',
    'echo \'<li>\' . date(\'H:i\', $depart) . \'</li>\';',
    'echo \'</ul>\';',
    '?>'));
  var N12 = page('La navette', 'La navette de la gare', L(
    '  <p>Départ devant l\'hôtel, toutes les 25 minutes.</p>',
    '<?php',
    '$depart = mktime(5, 40, 0);',
    '$minuit = mktime(23, 59, 0);',
    'echo \'<ul>\';',
    'while ($depart <= $minuit) {',
    '    if ($depart >= time()) {',
    '        echo \'<li>\' . date(\'H:i\', $depart) . \'</li>\';',
    '    }',
    '    $depart = $depart + 25 * 60;',
    '}',
    'echo \'</ul>\';',
    '?>'));
  var N13 = N12
    .replace('$minuit = mktime(23, 59, 0);\n', '$minuit = mktime(23, 59, 0);\n$restants = 0;\n')
    .replace('        echo \'<li>\' . date(\'H:i\', $depart) . \'</li>\';\n', '        echo \'<li>\' . date(\'H:i\', $depart) . \'</li>\';\n        $restants = $restants + 1;\n')
    .replace('echo \'</ul>\';\n?>', 'echo \'</ul>\';\nif ($restants == 0) {\n    echo \'<p>Plus de navette ce soir.</p>\';\n}\n?>');

  // Augustin's housekeeping notice, read on the night of reading (N17): not a file of the site.
  var MENAGE = [
    '<?php',
    '// Le planning du ménage. A.',
    '$heure = (int) date(\'G\');',
    '$jour = (int) date(\'N\');',
    'if ($jour == 7) {',
    '    echo \'<p>Pas de ménage le dimanche.</p>\';',
    '} elseif ($heure >= 9 && $heure < 12) {',
    '    echo \'<p>Ménage des étages en cours.</p>\';',
    '} elseif ($heure >= 14 && $heure < 16) {',
    '    echo \'<p>Ménage du rez-de-chaussée en cours.</p>\';',
    '} else {',
    '    echo \'<p>Pas de ménage en ce moment.</p>\';',
    '}',
    '?>'
  ];

  var THU = '2026-11-05 ', FRI = '2026-11-06 ', SAT = '2026-11-07 ', SUN = '2026-11-08 ';

  /** The rooms a page lists: 1 to n, with their side, the 13 skipped, the room under works closed, as asked. */
  function roomList(n, o) {
    o = o || {};
    var out = [];
    for (var i = 1; i <= n; i++) {
      if (o.skip13 && i === 13) continue;
      if (!o.sides) { out.push('Chambre ' + i); continue; }
      out.push('Chambre ' + i + ' : ' + (o.travaux && i === o.travaux ? 'fermée' : (i % 2 === 0 ? 'côté cour' : 'côté rue')));
    }
    return out;
  }
  function list(expected) {
    return function (res, H) {
      var got = H.items(res.output);
      for (var i = 0; i < Math.max(got.length, expected.length); i++) {
        if (got[i] !== expected[i]) {
          if (got[i] === undefined) return 'il manque « ' + expected[i] + ' » (la liste s\'arrête à ' + got.length + ' lignes, il en faut ' + expected.length + ')';
          if (expected[i] === undefined) return 'une ligne de trop : « ' + got[i] + ' » (il en faut ' + expected.length + ')';
          return 'ligne ' + (i + 1) + ' : « ' + got[i] + ' » au lieu de « ' + expected[i] + ' »';
        }
      }
      return true;
    };
  }
  function departures(at) {
    var now = Number(at.slice(11, 13)) * 60 + Number(at.slice(14, 16)), out = [];
    for (var m = 5 * 60 + 40; m <= 23 * 60 + 59; m += 25) if (m >= now) out.push(ST.helpers.pad(Math.floor(m / 60)) + ':' + ST.helpers.pad(m % 60));
    return out;
  }
  function shuttle(at) { return list(departures(at)); }
  function shuttleOrNone(at) {
    var exp = departures(at);
    return function (res, H) {
      var r = list(exp)(res, H);
      if (r !== true) return r;
      if (!exp.length) return sees('Plus de navette ce soir.')(res, H);
      return H.has(res, 'Plus de navette ce soir.') ? 'la page dit « Plus de navette ce soir. » alors qu\'il reste des départs' : true;
    };
  }
  var liMeter = function (file) { return function (files, H) { return H.count(H.code(files[file] || ''), '<li'); }; };
  function liOnce(file) { return function (files, H) { var n = liMeter(file)(files, H); return n <= 1 || '<li> est écrit ' + n + ' fois dans ' + file; }; }

  ST.add({
    id: 'N11', act: 3, learn: ['boucle-for'], notions: ['boucle-for', 'concatenation', 'variables'], page: 'chambres.php',
    show: { before: 'Chambre 1 … Chambre 12, écrites à la main', after: 'Chambre 1 … Chambre 40', note: 'autant de lignes que $ouvertes, préparé par l\'hôtel' },
    add: { 'chambres.php': CHAMBRES },
    ref: { 'chambres.php': N11 },
    cases: [
      { id: 'roux', who: 'Mme Roux', page: 'chambres.php', at: THU + '22:40:00', reg: reg({ ouvertes: 40 }), visible: true, label: 'Ce soir, 40 chambres ouvertes : de la 1 à la 40', check: list(roomList(40)) },
      { id: 'li', source: true, visible: true, label: 'Une seule ligne <li> écrite dans le code', check: liOnce('chambres.php') },
      { id: 'travaux', who: 'Un client de vendredi', page: 'chambres.php', at: FRI + '21:00:00', reg: reg({ ouvertes: 38, garde: 'Nora' }), label: 'Vendredi, 38 chambres ouvertes', check: list(roomList(38)) },
      { id: 'hiver', who: 'Une cliente de dimanche', page: 'chambres.php', at: SUN + '20:00:00', reg: reg({ ouvertes: 12, garde: 'Théo' }), label: 'Dimanche, l\'annexe fermée : 12 chambres', check: list(roomList(12)) },
      { id: 'une', who: 'Le premier client de la saison', page: 'chambres.php', at: SAT + '23:00:00', reg: reg({ ouvertes: 1, garde: 'Théo' }), label: 'Un soir de fermeture : une seule chambre ouverte', check: list(roomList(1)) }
    ],
    traps: [
      { label: 'la fin écrite en dur : $i <= 40', files: { 'chambres.php': N11.replace('$i <= $ouvertes', '$i <= 40') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'for ($i = 1; $i < 4; $i++) {', '    echo $i . \' \';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Une ligne, autant de fois qu\'il faut', body: 'for (départ; condition; pas) {\n    // répété tant que la condition est vraie\n}\n\nfor ($i = 1; $i <= 3; $i++) {\n    echo \'<li>\' . $i . \'</li>\';\n}\n\nEfface les douze <li> écrits à la main.' },
      { title: 'La solution', body: '<ul>\n<?php\nfor ($i = 1; $i <= $ouvertes; $i++) {\n    echo \'<li>Chambre \' . $i . \'</li>\';\n}\n?>\n</ul>' }
    ],
    intro: [
      { who: 'mireille', expr: 'sourire', text: 'On a racheté l\'immeuble d\'à côté : jusqu\'à 40 chambres ! Certains soirs 38, d\'autres 12 : l\'hôtel prépare le nombre dans $ouvertes.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Trente-huit ce soir, quarante samedi. La page suit, et personne ne recopie rien.' }
    ]
  });

  ST.add({
    id: 'N12', act: 3, learn: ['boucle-while'], notions: ['boucle-while', 'date', 'conditions'], page: 'navette.php',
    show: { before: '05:40', after: '21:30 · 21:55 · 22:20 · 22:45 · 23:10 · 23:35', note: 'pour Lina, à 21 h 10 : les départs qui restent' },
    add: { 'navette.php': NAVETTE },
    ref: { 'navette.php': N12 },
    cases: [
      { id: 'lina', who: 'Lina', page: 'navette.php', at: THU + '21:10:00', reg: reg(), visible: true, label: 'Lina, 21 h 10 : les six départs qui restent', check: shuttle(THU + '21:10') },
      { id: 'roux', who: 'Mme Roux', page: 'navette.php', at: THU + '05:00:00', reg: reg(), visible: true, label: 'Mme Roux, 5 h : tous les départs de la journée', check: shuttle(THU + '05:00') },
      { id: 'li', source: true, visible: true, label: 'Une seule ligne <li> écrite dans le code', check: liOnce('navette.php') },
      { id: 'quai', who: 'M. Diaz, valise à la main', page: 'navette.php', at: FRI + '22:45:00', reg: reg({ garde: 'Nora' }), label: 'Vendredi 22 h 45 : la navette est à quai', check: shuttle(FRI + '22:45') },
      { id: 'midi', who: 'La famille Okafor', page: 'navette.php', at: SUN + '13:05:00', reg: reg({ garde: 'Théo' }), label: 'Dimanche 13 h 05', check: shuttle(SUN + '13:05') },
      { id: 'tard', who: 'Les Bergeron', page: 'navette.php', at: SAT + '23:50:00', reg: reg({ garde: 'Théo' }), label: 'Samedi 23 h 50 : une liste vide', check: shuttle(SAT + '23:50') }
    ],
    traps: [
      { label: '> time() : la navette à quai oubliée', files: { 'navette.php': N12.replace('$depart >= time()', '$depart > time()') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$places = 3;', 'while ($places > 0) {', '    echo \'Une place. \';', '    $places = $places - 1;', '}', 'echo \'Complet !\';', '?>'], run: {} } },
    hints: [
      { title: 'Tant que…', body: 'while (condition) {\n    // répété tant que la condition est vraie\n    // et quelque chose doit la rendre fausse un jour !\n}\n\nTant que $depart ne dépasse pas minuit :\n  écris-le s\'il n\'est pas passé ($depart >= time()),\n  puis avance de 25 minutes : $depart = $depart + 25 * 60;' },
      { title: 'La solution', body: '$depart = mktime(5, 40, 0);\n$minuit = mktime(23, 59, 0);\necho \'<ul>\';\nwhile ($depart <= $minuit) {\n    if ($depart >= time()) {\n        echo \'<li>\' . date(\'H:i\', $depart) . \'</li>\';\n    }\n    $depart = $depart + 25 * 60;\n}\necho \'</ul>\';' }
    ],
    intro: [
      { who: 'sami', expr: 'neutre', text: 'La navette part à 5 h 40, puis toutes les 25 minutes jusqu\'à minuit. Je t\'ai mis un début de page, avec des commentaires : lis-les avant d\'écrire.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'M. Diaz a eu la navette de 22 h 45. Il l\'a vue sur la page, et elle était devant la porte.' }
    ]
  });

  ST.add({
    id: 'N13', act: 3, learn: ['boucle-while'], notions: ['boucle-while', 'conditions'], page: 'navette.php',
    show: { before: '(une liste vide)', after: 'Plus de navette ce soir.', note: 'à 23 h 50, quand il ne reste aucun départ' },
    ref: { 'navette.php': N13 },
    cases: [
      { id: 'tard', who: 'Les Bergeron', page: 'navette.php', at: SAT + '23:50:00', reg: reg({ garde: 'Théo' }), visible: true, label: 'Samedi 23 h 50 : « Plus de navette ce soir. »', check: shuttleOrNone(SAT + '23:50') },
      { id: 'lina', who: 'Lina', page: 'navette.php', at: THU + '21:10:00', reg: reg(), visible: true, label: 'Lina, 21 h 10 : les départs, et pas le message', check: shuttleOrNone(THU + '21:10') },
      { id: 'derniere', who: 'Un client pressé', page: 'navette.php', at: FRI + '23:35:00', reg: reg({ garde: 'Nora' }), label: 'Vendredi 23 h 35 : la dernière navette', check: shuttleOrNone(FRI + '23:35') },
      { id: 'apres', who: 'Une cliente en retard', page: 'navette.php', at: FRI + '23:36:00', reg: reg({ garde: 'Nora' }), label: 'Vendredi 23 h 36 : elle vient de partir', check: shuttleOrNone(FRI + '23:36') },
      { id: 'matin', who: 'Mme Roux', page: 'navette.php', at: SUN + '05:00:00', reg: reg({ garde: 'Théo' }), label: 'Dimanche 5 h', check: shuttleOrNone(SUN + '05:00') }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$compte = 0;', '$depart = 22;', 'while ($depart < 24) {', '    $compte = $compte + 1;', '    $depart = $depart + 1;', '}', 'echo $compte . \' départs\';', '?>'], run: {} } },
    hints: [
      { title: 'Compter dans la boucle', body: 'Avant la boucle :      $restants = 0;\nQuand un départ s\'écrit :  $restants = $restants + 1;\nAprès la boucle :\n  if ($restants == 0) {\n      echo \'<p>Plus de navette ce soir.</p>\';\n  }' },
      { title: 'La solution', body: '$restants = 0;\necho \'<ul>\';\nwhile ($depart <= $minuit) {\n    if ($depart >= time()) {\n        echo \'<li>\' . date(\'H:i\', $depart) . \'</li>\';\n        $restants = $restants + 1;\n    }\n    $depart = $depart + 25 * 60;\n}\necho \'</ul>\';\nif ($restants == 0) {\n    echo \'<p>Plus de navette ce soir.</p>\';\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Après la dernière navette, la page est vide. Les clients croient qu\'elle est cassée. Je veux « Plus de navette ce soir. »' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Les Bergeron ont pris un taxi sans m\'appeler. La page leur avait tout dit.' }
    ]
  });

  ST.add({
    id: 'N14', act: 3, learn: ['boucle-condition'], notions: ['boucle-condition', 'boucle-for', 'conditions'], page: 'chambres.php',
    show: { before: 'Chambre 7', after: 'Chambre 7 : côté rue', note: 'les paires côté cour, les impaires côté rue' },
    ref: { 'chambres.php': N14 },
    cases: [
      { id: 'roux', who: 'Mme Roux', page: 'chambres.php', at: THU + '22:40:00', reg: reg({ ouvertes: 40 }), visible: true, label: '40 chambres : « côté cour » ou « côté rue » sur chacune', check: list(roomList(40, { sides: true })) },
      { id: 'vendredi', who: 'Un client de vendredi', page: 'chambres.php', at: FRI + '21:00:00', reg: reg({ ouvertes: 38, garde: 'Nora' }), label: 'Vendredi : 38 chambres', check: list(roomList(38, { sides: true })) },
      { id: 'hiver', who: 'Une cliente de dimanche', page: 'chambres.php', at: SUN + '20:00:00', reg: reg({ ouvertes: 12, garde: 'Théo' }), label: 'Dimanche : 12 chambres', check: list(roomList(12, { sides: true })) },
      { id: 'une', who: 'Le premier client de la saison', page: 'chambres.php', at: SAT + '23:00:00', reg: reg({ ouvertes: 1, garde: 'Théo' }), label: 'Une seule chambre ouverte', check: list(roomList(1, { sides: true })) }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'for ($i = 1; $i <= 6; $i++) {', '    if ($i % 3 == 0) {', '        echo \'[\' . $i . \'] \';', '    } else {', '        echo $i . \' \';', '    }', '}', '?>'], run: {} } },
    hints: [
      { title: 'Décider à chaque tour', body: 'Dans la boucle, un if décide pour CETTE chambre :\n\n  if ($i % 2 == 0)   → $i est pair\n                       (% donne le reste de la division)' },
      { title: 'La solution', body: 'for ($i = 1; $i <= $ouvertes; $i++) {\n    if ($i % 2 == 0) {\n        echo \'<li>Chambre \' . $i . \' : côté cour</li>\';\n    } else {\n        echo \'<li>Chambre \' . $i . \' : côté rue</li>\';\n    }\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Les clients veulent savoir s\'ils dormiront côté cour ou côté rue. Les paires donnent sur la cour, les impaires sur la rue.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Mme Roux a demandé la 12 pour le calme de la cour. Elle l\'avait lu sur la page.' }
    ]
  });

  ST.add({
    id: 'N15', act: 3, learn: ['boucle-condition'], notions: ['boucle-condition', 'boucle-for'], page: 'chambres.php',
    show: { before: 'Chambre 12 · Chambre 13 · Chambre 14', after: 'Chambre 12 · Chambre 14', note: 'il n\'y a pas de chambre 13 aux Quatre-Vents' },
    ref: { 'chambres.php': N15 },
    cases: [
      { id: 'roux', who: 'Mme Roux', page: 'chambres.php', at: THU + '22:40:00', reg: reg({ ouvertes: 40 }), visible: true, label: '40 chambres, sans la 13', check: list(roomList(40, { sides: true, skip13: true })) },
      { id: 'vendredi', who: 'Un client de vendredi', page: 'chambres.php', at: FRI + '21:00:00', reg: reg({ ouvertes: 38, garde: 'Nora' }), label: 'Vendredi : 38 chambres', check: list(roomList(38, { sides: true, skip13: true })) },
      { id: 'treize', who: 'Un soir de fermeture', page: 'chambres.php', at: SUN + '20:00:00', reg: reg({ ouvertes: 13, garde: 'Théo' }), label: 'Dimanche : 13 ouvertes, donc jusqu\'à la 12', check: list(roomList(13, { sides: true, skip13: true })) },
      { id: 'hiver', who: 'Une cliente de dimanche', page: 'chambres.php', at: SUN + '21:00:00', reg: reg({ ouvertes: 12, garde: 'Théo' }), label: 'Dimanche : 12 chambres', check: list(roomList(12, { sides: true, skip13: true })) }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'for ($i = 1; $i <= 5; $i++) {', '    if ($i == 3) {', '        continue;', '    }', '    echo $i . \' \';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Sauter un tour', body: 'continue;  → passe au tour suivant, sans faire la fin de celui-ci.\n\nAu début de la boucle :\n  if ($i == 13) {\n      continue;\n  }' },
      { title: 'La solution', body: 'for ($i = 1; $i <= $ouvertes; $i++) {\n    if ($i == 13) {\n        continue;\n    }\n    if ($i % 2 == 0) {\n        …' }
    ],
    intro: [
      { who: 'mireille', expr: 'sourcil', text: 'Il n\'y a jamais eu de chambre 13 ici : personne n\'en veut. La page ne doit pas en parler.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'De la 12 à la 14, comme dans l\'ascenseur.' }
    ]
  });

  ST.add({
    id: 'N16', act: 3, learn: ['boucle-condition'], notions: ['boucle-condition', 'conditions-multiples'], page: 'chambres.php',
    show: { before: 'Chambre 9 : côté rue', after: 'Chambre 9 : fermée', note: 'le soir où l\'hôtel prépare $travaux = 9 (0 : aucune en travaux)' },
    ref: { 'chambres.php': N16 },
    cases: [
      { id: 'roux', who: 'Mme Roux', page: 'chambres.php', at: THU + '22:40:00', reg: reg({ ouvertes: 40, travaux: 9 }), visible: true, label: '40 chambres, la 9 en travaux', check: list(roomList(40, { sides: true, skip13: true, travaux: 9 })) },
      { id: 'diaz', who: 'M. Diaz', page: 'chambres.php', at: THU + '23:12:00', reg: reg({ ouvertes: 40, travaux: 0 }), visible: true, label: '40 chambres, aucune en travaux', check: list(roomList(40, { sides: true, skip13: true, travaux: 0 })) },
      { id: 'douze', who: 'Une cliente de dimanche', page: 'chambres.php', at: SUN + '20:00:00', reg: reg({ ouvertes: 12, travaux: 12, garde: 'Théo' }), label: 'Dimanche : 12 chambres, la 12 en travaux', check: list(roomList(12, { sides: true, skip13: true, travaux: 12 })) },
      { id: 'treize', who: 'Un client de vendredi', page: 'chambres.php', at: FRI + '21:00:00', reg: reg({ ouvertes: 38, travaux: 13, garde: 'Nora' }), label: 'Vendredi : l\'hôtel met la 13 en travaux', check: list(roomList(38, { sides: true, skip13: true, travaux: 13 })) },
      { id: 'vingt', who: 'Les Bergeron', page: 'chambres.php', at: SAT + '23:00:00', reg: reg({ ouvertes: 20, travaux: 0, garde: 'Théo' }), label: 'Samedi : 20 chambres', check: list(roomList(20, { sides: true, skip13: true, travaux: 0 })) }
    ],
    traps: [
      { label: 'les travaux testés avant le saut de la 13', files: { 'chambres.php': N16.replace('    if ($i == 13) {\n        continue;\n    }\n    if ($i == $travaux) {', '    if ($i == $travaux) {\n        echo \'    <li>Chambre \' . $i . \' : fermée</li>\' . "\\n";\n        continue;\n    }\n    if ($i == 13) {\n        continue;\n    }\n    if ($i == $travaux) {') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$travaux = 2;', 'for ($i = 1; $i <= 3; $i++) {', '    if ($i == $travaux) {', '        echo \'X \';', '    } elseif ($i % 2 == 0) {', '        echo \'cour \';', '    } else {', '        echo \'rue \';', '    }', '}', '?>'], run: {} } },
    hints: [
      { title: 'Un cas de plus, en premier', body: 'La chambre en travaux passe avant cour et rue :\n\n  if ($i == $travaux) {\n      … fermée\n  } elseif ($i % 2 == 0) {\n      … côté cour\n  } else {\n      … côté rue\n  }\n\nLe saut de la 13 reste tout en haut.' },
      { title: 'La solution', body: 'for ($i = 1; $i <= $ouvertes; $i++) {\n    if ($i == 13) {\n        continue;\n    }\n    if ($i == $travaux) {\n        echo \'<li>Chambre \' . $i . \' : fermée</li>\';\n    } elseif ($i % 2 == 0) {\n        echo \'<li>Chambre \' . $i . \' : côté cour</li>\';\n    } else {\n        echo \'<li>Chambre \' . $i . \' : côté rue</li>\';\n    }\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Le plombier passe cette semaine. L\'hôtel prépare $travaux : la chambre fermée, ou 0 s\'il n\'y en a pas. Elle doit s\'afficher « fermée ».' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'La 9 est fermée tant que le plombier n\'est pas passé. Et personne ne l\'a réservée par erreur.' }
    ]
  });

  ST.add({
    id: 'N17', act: 3, learn: ['lecture'], notions: ['lecture', 'conditions-multiples', 'operateurs-logiques', 'date'],
    views: {
      menage: { title: 'menage.php · Augustin', lines: MENAGE },
      'menage-1530': { title: 'menage.php · jeudi 15 h 30', lines: MENAGE, run: { at: THU + '15:30:00' } },
      'menage-dimanche': { title: 'menage.php · dimanche 10 h', lines: MENAGE, run: { at: SUN + '10:00:00' } }
    },
    intro: [
      { who: 'sami', expr: 'sourire', text: 'Ce soir, pas de visiteurs : on lit le planning du ménage qu\'Augustin a écrit, ligne à ligne. Lire le code des autres, c\'est la moitié du métier.' }
    ],
    done: [
      { who: 'sami', expr: 'sourire', text: 'Tu as lu un fichier que tu n\'as pas écrit, et tu as su ce qu\'il afficherait. Le rez-de-chaussée est à toi : on monte dans les étages.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
