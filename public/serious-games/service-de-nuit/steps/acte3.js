/* Service de nuit — acte 3, « Les couloirs » (N08-N11), the game's half. */
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

  var N08 = page('Nos chambres', 'Nos chambres', L(
    '  <ul>',
    '<?php',
    'for ($i = 1; $i <= $ouvertes; $i++) {',
    '    echo \'    <li>Chambre \' . $i . \'</li>\' . "\\n";',
    '}',
    '?>',
    '  </ul>'));

  var N10 = page('Nos chambres', 'Nos chambres', L(
    '  <ul>',
    '<?php',
    'for ($i = 1; $i <= $ouvertes; $i++) {',
    '    if ($i == 13) {',
    '        continue;',
    '    }',
    '    if ($i == $travaux) {',
    '        echo \'    <li>Chambre \' . $i . \' : fermée</li>\' . "\\n";',
    '    } elseif ($i % 2 == 0) {',
    '        echo \'    <li>Chambre \' . $i . \' : côté cour</li>\' . "\\n";',
    '    } else {',
    '        echo \'    <li>Chambre \' . $i . \' : côté rue</li>\' . "\\n";',
    '    }',
    '}',
    '?>',
    '  </ul>'));

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
  var N09 = page('La navette', 'La navette de la gare', L(
    '  <p>Départ devant l\'hôtel, toutes les 25 minutes.</p>',
    '<?php',
    '$depart = mktime(5, 40, 0);',
    '$minuit = mktime(23, 59, 0);',
    '$restants = 0;',
    'echo \'<ul>\';',
    'while ($depart <= $minuit) {',
    '    if ($depart >= time()) {',
    '        echo \'<li>\' . date(\'H:i\', $depart) . \'</li>\';',
    '        $restants = $restants + 1;',
    '    }',
    '    $depart = $depart + 25 * 60;',
    '}',
    'echo \'</ul>\';',
    'if ($restants == 0) {',
    '    echo \'<p>Plus de navette ce soir.</p>\';',
    '}',
    '?>'));

  var THU = '2026-11-05 ', FRI = '2026-11-06 ', SAT = '2026-11-07 ', SUN = '2026-11-08 ';

  function rooms(n, travaux) {
    var out = [];
    for (var i = 1; i <= n; i++) {
      if (travaux === undefined) { out.push('Chambre ' + i); continue; }
      if (i === 13) continue;
      out.push('Chambre ' + i + ' : ' + (i === travaux ? 'fermée' : (i % 2 === 0 ? 'côté cour' : 'côté rue')));
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
  function shuttle(at) {
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
    id: 'N08', act: 3, learn: ['boucle-for'], notions: ['boucle-for', 'concatenation', 'variables'], page: 'chambres.php',
    add: { 'chambres.php': CHAMBRES },
    ref: { 'chambres.php': N08 },
    cost: { label: 'lignes <li> écrites à la main', max: 1, measure: liMeter('chambres.php') },
    cases: [
      { id: 'roux', who: 'Mme Roux', page: 'chambres.php', at: THU + '22:40:00', reg: reg({ ouvertes: 40 }), visible: true, label: 'Ce soir, 40 chambres ouvertes : de la 1 à la 40', check: list(rooms(40)) },
      { id: 'li', source: true, visible: true, label: 'Une seule ligne <li> écrite dans le code', check: liOnce('chambres.php') },
      { id: 'travaux', who: 'Un client de vendredi', page: 'chambres.php', at: FRI + '21:00:00', reg: reg({ ouvertes: 38, garde: 'Nora' }), label: 'Vendredi, 38 chambres ouvertes', check: list(rooms(38)) },
      { id: 'hiver', who: 'Une cliente de dimanche', page: 'chambres.php', at: SUN + '20:00:00', reg: reg({ ouvertes: 12, garde: 'Théo' }), label: 'Dimanche, l\'annexe fermée : 12 chambres', check: list(rooms(12)) },
      { id: 'une', who: 'Le premier client de la saison', page: 'chambres.php', at: SAT + '23:00:00', reg: reg({ ouvertes: 1, garde: 'Théo' }), label: 'Un soir de fermeture : une seule chambre ouverte', check: list(rooms(1)) }
    ],
    traps: [
      { label: 'la fin écrite en dur : $i <= 40', files: { 'chambres.php': N08.replace('$i <= $ouvertes', '$i <= 40') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'for ($i = 1; $i < 4; $i++) {', '    echo $i . \' \';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Une ligne, autant de fois qu\'il faut', body: 'for (départ; condition; pas) {\n    // répété tant que la condition est vraie\n}\n\nfor ($i = 1; $i <= 3; $i++) {\n    echo \'<li>\' . $i . \'</li>\';\n}' },
      { title: 'La solution', body: '<ul>\n<?php\nfor ($i = 1; $i <= $ouvertes; $i++) {\n    echo \'<li>Chambre \' . $i . \'</li>\';\n}\n?>\n</ul>' }
    ],
    intro: [
      { who: 'narr', text: 'Troisième semaine. Des ouvriers montent des lits dans l\'immeuble voisin ; une porte a été percée au premier étage.' },
      { who: 'mireille', expr: 'sourire', text: 'Quarante chambres ! Enfin, quand tout sera prêt. Certains soirs 38, d\'autres 12. Le registre le dira.' },
      { who: 'sami', expr: 'neutre', text: 'Tu peux toujours écrire 40 lignes <li>… et les effacer demain. Ou écrire une ligne qui se répète.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Trente-huit ce soir, quarante samedi. La page suit le registre.' }
    ]
  });

  ST.add({
    id: 'N09', act: 3, learn: ['boucle-while'], notions: ['boucle-while', 'date', 'conditions'], page: 'navette.php',
    add: { 'navette.php': NAVETTE },
    ref: { 'navette.php': N09 },
    cost: { label: 'lignes <li> écrites à la main', max: 1, measure: liMeter('navette.php') },
    cases: [
      { id: 'lina', who: 'Lina', page: 'navette.php', at: THU + '21:10:00', reg: reg(), visible: true, label: 'Lina, 21 h 10 : les six départs qui restent', check: shuttle(THU + '21:10') },
      { id: 'roux', who: 'Mme Roux', page: 'navette.php', at: THU + '05:00:00', reg: reg(), visible: true, label: 'Mme Roux, 5 h : tous les départs de la journée', check: shuttle(THU + '05:00') },
      { id: 'li', source: true, visible: true, label: 'Une seule ligne <li> écrite dans le code', check: liOnce('navette.php') },
      { id: 'quai', who: 'M. Diaz, valise à la main', page: 'navette.php', at: FRI + '22:45:00', reg: reg({ garde: 'Nora' }), label: 'Vendredi 22 h 45 : la navette est à quai', check: shuttle(FRI + '22:45') },
      { id: 'tard', who: 'Les Bergeron', page: 'navette.php', at: SAT + '23:50:00', reg: reg({ garde: 'Théo' }), label: 'Samedi 23 h 50', check: shuttle(SAT + '23:50') },
      { id: 'midi', who: 'La famille Okafor', page: 'navette.php', at: SUN + '13:05:00', reg: reg({ garde: 'Théo' }), label: 'Dimanche 13 h 05', check: shuttle(SUN + '13:05') }
    ],
    traps: [
      { label: '> time() : la navette à quai oubliée', files: { 'navette.php': N09.replace('$depart >= time()', '$depart > time()') } },
      { label: 'pas de message quand il n\'y a plus de navette', files: { 'navette.php': N09.replace('if ($restants == 0) {\n    echo \'<p>Plus de navette ce soir.</p>\';\n}', '') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$places = 3;', 'while ($places > 0) {', '    echo \'Une place. \';', '    $places = $places - 1;', '}', 'echo \'Complet !\';', '?>'], run: {} } },
    hints: [
      { title: 'Tant que…', body: 'while (condition) {\n    // répété tant que la condition est vraie\n    // et quelque chose doit la rendre fausse un jour !\n}\n\nUn départ toutes les 25 minutes : $depart = $depart + 25 * 60;\nIl est passé si $depart < time().' },
      { title: 'La solution', body: '$depart = mktime(5, 40, 0);\n$minuit = mktime(23, 59, 0);\n$restants = 0;\necho \'<ul>\';\nwhile ($depart <= $minuit) {\n    if ($depart >= time()) {\n        echo \'<li>\' . date(\'H:i\', $depart) . \'</li>\';\n        $restants = $restants + 1;\n    }\n    $depart = $depart + 25 * 60;\n}\necho \'</ul>\';\nif ($restants == 0) {\n    echo \'<p>Plus de navette ce soir.</p>\';\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'La mairie nous prête une navette pour la gare. Les clients me demandent l\'horaire toutes les cinq minutes.' },
      { who: 'sami', expr: 'neutre', text: 'Je t\'ai mis un début de page : mktime(), date() avec une heure donnée, time(). Le reste, c\'est une boucle qui avance de 25 minutes tant qu\'on n\'a pas passé minuit.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'M. Diaz a eu la navette de 22 h 45. Il l\'a vue sur la page, et elle était devant la porte.' }
    ]
  });

  ST.add({
    id: 'N10', act: 3, learn: ['boucle-condition'], notions: ['boucle-condition', 'boucle-for', 'conditions-multiples'], page: 'chambres.php',
    ref: { 'chambres.php': N10 },
    cases: [
      { id: 'roux', who: 'Mme Roux', page: 'chambres.php', at: THU + '22:40:00', reg: reg({ ouvertes: 40, travaux: 9 }), visible: true, label: '40 chambres, la 9 en travaux : cour ou rue, pas de 13', check: list(rooms(40, 9)) },
      { id: 'diaz', who: 'M. Diaz', page: 'chambres.php', at: THU + '23:12:00', reg: reg({ ouvertes: 40, travaux: 0 }), visible: true, label: '40 chambres, aucune en travaux', check: list(rooms(40, 0)) },
      { id: 'douze', who: 'Une cliente de dimanche', page: 'chambres.php', at: SUN + '20:00:00', reg: reg({ ouvertes: 12, travaux: 12, garde: 'Théo' }), label: 'Dimanche : 12 chambres, la 12 en travaux', check: list(rooms(12, 12)) },
      { id: 'treize', who: 'Un client de vendredi', page: 'chambres.php', at: FRI + '21:00:00', reg: reg({ ouvertes: 38, travaux: 13, garde: 'Nora' }), label: 'Vendredi : le registre met la 13 en travaux', check: list(rooms(38, 13)) },
      { id: 'vingt', who: 'Les Bergeron', page: 'chambres.php', at: SAT + '23:00:00', reg: reg({ ouvertes: 20, travaux: 0, garde: 'Théo' }), label: 'Samedi : 20 chambres', check: list(rooms(20, 0)) }
    ],
    traps: [
      { label: 'les travaux testés avant le saut de la 13', files: { 'chambres.php': N10.replace('    if ($i == 13) {\n        continue;\n    }\n    if ($i == $travaux) {', '    if ($i == $travaux) {\n        echo \'    <li>Chambre \' . $i . \' : fermée</li>\' . "\\n";\n        continue;\n    }\n    if ($i == 13) {\n        continue;\n    }\n    if ($i == $travaux) {') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'for ($i = 1; $i <= 6; $i++) {', '    if ($i % 3 == 0) {', '        echo \'[\' . $i . \'] \';', '    } else {', '        echo $i . \' \';', '    }', '}', '?>'], run: {} } },
    hints: [
      { title: 'Décider à chaque tour', body: 'Dans la boucle, un if décide pour CETTE chambre :\n\nif ($i % 2 == 0)   → $i est pair (le reste de la division par 2 est nul)\ncontinue;          → passe au tour suivant, sans rien écrire\n\nL\'ordre des tests compte : la 13 n\'existe pas, même en travaux.' },
      { title: 'La solution', body: 'for ($i = 1; $i <= $ouvertes; $i++) {\n    if ($i == 13) {\n        continue;\n    }\n    if ($i == $travaux) {\n        echo \'<li>Chambre \' . $i . \' : fermée</li>\';\n    } elseif ($i % 2 == 0) {\n        echo \'<li>Chambre \' . $i . \' : côté cour</li>\';\n    } else {\n        echo \'<li>Chambre \' . $i . \' : côté rue</li>\';\n    }\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Les clients veulent savoir s\'ils dormiront côté cour ou côté rue. Et il n\'y a jamais eu de chambre 13 ici. Les gens n\'en veulent pas.' },
      { who: 'sami', expr: 'sourire', text: 'Une boucle qui décide à chaque tour. Et une chambre à sauter : continue.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'De la 12 à la 14, comme dans l\'ascenseur. Et la 9 est fermée tant que le plombier n\'est pas passé.' },
      { who: 'sami', expr: 'surprise', text: 'Mireille ! Elle a trouvé quelque chose dans la couverture du premier carnet.' }
    ]
  });

  var PIED_LINES = ST.PIED.replace(/\n$/, '').split('\n');
  ST.add({
    id: 'N11', act: 3, learn: ['lecture'], notions: ['lecture', 'conditions-multiples', 'operateurs-logiques', 'date'],
    views: {
      pied: { title: 'pied.php · Augustin', lines: PIED_LINES },
      'pied-0314': { title: 'pied.php · jeudi 3 h 14', lines: PIED_LINES, run: { at: THU + '03:14:00' } }
    },
    intro: [
      { who: 'mireille', expr: 'surprise', text: 'Dans la couverture du premier carnet, au crayon : « pied.php ». C\'est le mot de passe du fichier que l\'hôtel ajoute en bas de toutes les pages.' },
      { who: 'sami', expr: 'neutre', text: 'Les lignes de la nuit, c\'est lui. Augustin l\'a écrit pour celui qui viendrait après lui. Pour toi.' },
      { who: 'sami', expr: 'sourire', text: 'Cette fois, pas de visiteurs : on lit son code, ligne à ligne. C\'est la moitié du métier.' }
    ],
    done: [
      { who: 'narr', text: 'Vendredi, 4 h 04. Tu montes au quatrième. La chambre 404 n\'est pas fermée à clé.' },
      { who: 'narr', text: 'Sur la table de nuit, un carnet rouge. Sur la couverture : « Les étages ». Dedans, des pages sur les tableaux, les fonctions, les formulaires.' },
      { who: 'mireille', expr: 'surprise', text: 'Il avait tout prévu. Il savait qu\'un jour quelqu\'un lirait son fichier.' },
      { who: 'narr', text: 'Dehors, le trait d\'union de QUATRE-VENTS s\'allume. Au-dessus, les cinq lettres de HÔTEL attendent, éteintes.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
