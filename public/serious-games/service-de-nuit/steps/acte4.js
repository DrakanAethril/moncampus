/* Service de nuit — acte 4, « Les étages » (N12-N14), the game's half. */
(function (root) {
  'use strict';
  var ST = root.SDNSteps, sees = ST.sees, reg = ST.reg;
  var L = function () { return Array.prototype.slice.call(arguments).join('\n'); };
  function page(title, h1, body) {
    return L('<!DOCTYPE html>', '<html lang="fr">', '<head>', '  <meta charset="utf-8">', '  <title>' + title + ' · Hôtel des Quatre-Vents</title>', '</head>', '<body>', '  <h1>' + h1 + '</h1>', body, '</body>', '</html>', '');
  }

  var N07 = ST.refOf('N07')['accueil.php'];
  var DATE = L(
    '$jours = [\'lundi\', \'mardi\', \'mercredi\', \'jeudi\', \'vendredi\', \'samedi\', \'dimanche\'];',
    '$mois = [\'janvier\', \'février\', \'mars\', \'avril\', \'mai\', \'juin\', \'juillet\', \'août\', \'septembre\', \'octobre\', \'novembre\', \'décembre\'];',
    '$quantieme = date(\'j\');',
    'if ($quantieme == 1) {',
    '    $quantieme = \'1er\';',
    '}',
    '$date = $jours[date(\'N\') - 1] . \' \' . $quantieme . \' \' . $mois[date(\'n\') - 1];');
  var N12 = N07
    .replace('$jour = (int) date(\'N\');\n', '$jour = (int) date(\'N\');\n' + DATE + '\n')
    .replace('<h1>Hôtel des Quatre-Vents</h1>', '<h1>Hôtel des Quatre-Vents</h1>\n  <p class="date">Nous sommes le <?php echo $date; ?>.</p>');

  var ARRIVEES = page('Les arrivées', 'Les arrivées du soir', L(
    '  <p>3 arrivées ce soir</p>',
    '  <ul>',
    '    <li>1. Mme Roux</li>',
    '    <li>2. M. Diaz</li>',
    '    <li>3. A. L.</li>',
    '  </ul>'));
  var N13 = page('Les arrivées', 'Les arrivées du soir', L(
    '<?php',
    '$nombre = count($arrivees);',
    'if ($nombre == 0) {',
    '    echo \'  <p>Aucune arrivée ce soir.</p>\' . "\\n";',
    '} else {',
    '    if ($nombre == 1) {',
    '        echo \'  <p>1 arrivée ce soir</p>\' . "\\n";',
    '    } else {',
    '        echo \'  <p>\' . $nombre . \' arrivées ce soir</p>\' . "\\n";',
    '    }',
    '    echo \'  <ul>\' . "\\n";',
    '    $rang = 1;',
    '    foreach ($arrivees as $client) {',
    '        echo \'    <li>\' . $rang . \'. \' . $client . \'</li>\' . "\\n";',
    '        $rang++;',
    '    }',
    '    echo \'  </ul>\' . "\\n";',
    '}',
    '?>'));

  var TARIFS = page('Nos tarifs', 'Nos tarifs', L(
    '  <ul>',
    '    <li>Simple : 62 €</li>',
    '    <li>Double : 78 €</li>',
    '    <li>Suite : 120 €</li>',
    '  </ul>',
    '  <p>Dès 62 € la nuit</p>'));
  var N14 = page('Nos tarifs', 'Nos tarifs', L(
    '  <ul>',
    '<?php',
    'foreach ($tarifs as $type => $prix) {',
    '    echo \'    <li>\' . $type . \' : \' . number_format($prix, 2, \',\', \' \') . \' €</li>\' . "\\n";',
    '}',
    '?>',
    '  </ul>',
    '  <p>Dès <?php echo number_format(min($tarifs), 2, \',\', \' \'); ?> € la nuit</p>'));

  var THU = '2026-11-12 ', FRI = '2026-11-13 ', SAT = '2026-11-14 ', SUN = '2026-11-15 ';

  function conditions(files, H) { var c = H.code(files['accueil.php'] || ''), m = c.match(/\b(if|elseif)\s*\(/g); return m ? m.length : 0; }
  var added = function (files, H, base) { return Math.max(0, conditions(files, H) - conditions(base || {}, H)); };
  function list(expected) {
    return function (res, H) {
      var got = H.items(res.output);
      for (var i = 0; i < Math.max(got.length, expected.length); i++) {
        if (got[i] !== expected[i]) {
          if (got[i] === undefined) return 'il manque « ' + expected[i] + ' » (' + got.length + ' lignes, il en faut ' + expected.length + ')';
          if (expected[i] === undefined) return 'une ligne de trop : « ' + got[i] + ' »';
          return 'ligne ' + (i + 1) + ' : « ' + got[i] + ' » au lieu de « ' + expected[i] + ' »';
        }
      }
      return true;
    };
  }
  function arrivals(names) {
    var head = !names.length ? 'Aucune arrivée ce soir.' : (names.length === 1 ? '1 arrivée ce soir' : names.length + ' arrivées ce soir');
    var items = names.map(function (n, i) { return (i + 1) + '. ' + n; });
    return function (res, H) {
      if (!H.has(res, head)) return 'la page n\'affiche pas « ' + head + ' »';
      if (names.length !== 1 && H.has(res, '1 arrivées')) return '« 1 arrivées » : au singulier, une arrivée';
      return list(items)(res, H);
    };
  }
  function prices(tarifs) {
    var items = [], min = Infinity;
    for (var k in tarifs) { items.push(k + ' : ' + ST.helpers.euros(tarifs[k])); min = Math.min(min, tarifs[k]); }
    return function (res, H) { var r = list(items)(res, H); return r === true ? sees('Dès ' + H.euros(min) + ' la nuit')(res, H) : r; };
  }

  ST.add({
    id: 'N12', act: 4, learn: ['tableaux'], notions: ['tableaux', 'date', 'concatenation'],
    ref: { 'accueil.php': N12 },
    cost: { label: 'conditions ajoutées ce soir', max: 1, measure: added },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', reg: reg(), visible: true, label: 'Jeudi 12 novembre', check: sees('Nous sommes le jeudi 12 novembre') },
      { id: 'diaz', who: 'M. Diaz', at: SAT + '23:12:00', reg: reg({ client: 'M. Diaz', chambre: 3 }), visible: true, label: 'Samedi 14 novembre', check: sees('Nous sommes le samedi 14 novembre') },
      { id: 'ifs', source: true, visible: true, label: 'Au plus une condition de plus dans la page', check: function (files, H, base) { var n = added(files, H, base); return n <= 1 || n + ' conditions de plus : un tableau donne le nom du jour sans if'; } },
      { id: 'dimanche', who: 'Une cliente de dimanche', at: SUN + '10:40:00', reg: reg({ garde: 'Théo' }), label: 'Dimanche 15 novembre', check: sees('Nous sommes le dimanche 15 novembre') },
      { id: 'decembre', who: 'Un client de décembre', at: '2026-12-01 21:00:00', reg: reg({ garde: 'Nora' }), label: 'Mardi 1er décembre', check: sees('Nous sommes le mardi 1er décembre') },
      { id: 'janvier', who: 'Le premier client de l\'année', at: '2027-01-01 01:30:00', reg: reg({ garde: 'Théo' }), label: 'Vendredi 1er janvier', check: sees('Nous sommes le vendredi 1er janvier') }
    ],
    traps: [
      { label: 'le « 1er » oublié', files: { 'accueil.php': N12.replace('if ($quantieme == 1) {\n    $quantieme = \'1er\';\n}\n', '') } },
      { label: 'date(\'w\') - 1 : le dimanche tombe hors du tableau', files: { 'accueil.php': N12.replace('$jours[date(\'N\') - 1]', '$jours[date(\'w\') - 1]') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$etages = [\'rez-de-chaussée\', \'premier\', \'deuxième\', \'troisième\'];', 'echo $etages[1] . \' / \' . count($etages);', '?>'], run: {} } },
    hints: [
      { title: 'Des cases numérotées', body: '$jours = [\'lundi\', \'mardi\', …, \'dimanche\'];\n$jours[0]  →  lundi\n$jours[3]  →  jeudi\n\ndate(\'N\') : 1 le lundi … 7 le dimanche\ndate(\'n\') : 1 en janvier … 12 en décembre\ndate(\'j\') : le jour du mois, sans zéro' },
      { title: 'La solution', body: '$jours = [\'lundi\', \'mardi\', \'mercredi\', \'jeudi\', \'vendredi\', \'samedi\', \'dimanche\'];\n$mois = [\'janvier\', …, \'décembre\'];\n$quantieme = date(\'j\');\nif ($quantieme == 1) {\n    $quantieme = \'1er\';\n}\n$date = $jours[date(\'N\') - 1] . \' \' . $quantieme . \' \' . $mois[date(\'n\') - 1];\n…\n<p class="date">Nous sommes le <?php echo $date; ?>.</p>' }
    ],
    intro: [
      { who: 'narr', text: 'Le carnet rouge est ouvert sur le comptoir. Première page, de l\'écriture serrée d\'Augustin : « Les tableaux. Des cases numérotées, à partir de zéro, comme les étages. »' },
      { who: 'mireille', expr: 'sourcil', text: 'Thursday ! Nous sommes en Corrèze, pas à Londres.' },
      { who: 'sami', expr: 'neutre', text: 'Sept jours, douze mois. Avec des if, ça fait dix-neuf conditions. Le compteur te regarde.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Mardi 1er décembre. Avec le « er ». Augustin l\'écrivait à la main sur l\'ardoise, tous les premiers du mois.' }
    ]
  });

  ST.add({
    id: 'N13', act: 4, learn: ['foreach'], notions: ['foreach', 'tableaux', 'conditions-multiples'], page: 'arrivees.php',
    add: { 'arrivees.php': ARRIVEES },
    ref: { 'arrivees.php': N13 },
    cases: [
      { id: 'jeudi', who: 'Mireille, jeudi', page: 'arrivees.php', at: THU + '18:00:00', reg: reg({ arrivees: ['Mme Roux', 'M. Diaz', 'A. L.'] }), visible: true, label: 'Jeudi : trois arrivées', check: arrivals(['Mme Roux', 'M. Diaz', 'A. L.']) },
      { id: 'vendredi', who: 'Mireille, vendredi', page: 'arrivees.php', at: FRI + '18:00:00', reg: reg({ arrivees: ['Famille Okafor', 'Lina'], garde: 'Nora' }), visible: true, label: 'Vendredi : deux arrivées', check: arrivals(['Famille Okafor', 'Lina']) },
      { id: 'aucune', who: 'Nora, un dimanche creux', page: 'arrivees.php', at: SUN + '18:00:00', reg: reg({ arrivees: [], garde: 'Théo' }), label: 'Dimanche : personne', check: arrivals([]) },
      { id: 'une', who: 'Théo, samedi', page: 'arrivees.php', at: SAT + '18:00:00', reg: reg({ arrivees: ['M. Diaz'], garde: 'Théo' }), label: 'Samedi : une seule arrivée', check: arrivals(['M. Diaz']) },
      { id: 'cinq', who: 'Mireille, un jeudi de congrès', page: 'arrivees.php', at: '2026-11-19 18:00:00', reg: reg({ arrivees: ['M. et Mme Bergeron', 'Mme Roux', 'Lina', 'M. Diaz', 'A. L.'] }), label: 'Un jeudi de congrès : cinq arrivées', check: arrivals(['M. et Mme Bergeron', 'Mme Roux', 'Lina', 'M. Diaz', 'A. L.']) }
    ],
    traps: [
      { label: 'pas de singulier : « 1 arrivées »', files: { 'arrivees.php': N13.replace('    if ($nombre == 1) {\n        echo \'  <p>1 arrivée ce soir</p>\' . "\\n";\n    } else {\n        echo \'  <p>\' . $nombre . \' arrivées ce soir</p>\' . "\\n";\n    }', '    echo \'  <p>\' . $nombre . \' arrivées ce soir</p>\' . "\\n";') } },
      { label: 'rien de prévu quand personne n\'arrive', files: { 'arrivees.php': N13.replace('if ($nombre == 0) {\n    echo \'  <p>Aucune arrivée ce soir.</p>\' . "\\n";\n} else {', 'if (true) {') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$cles = [\'101\', \'102\', \'205\'];', 'foreach ($cles as $cle) {', '    echo $cle . \' \';', '}', 'echo \'(\' . count($cles) . \')\';', '?>'], run: {} } },
    hints: [
      { title: 'Une fois par case', body: 'foreach ($arrivees as $client) {\n    // $client vaut la case du tour : \'Mme Roux\', puis \'M. Diaz\'…\n}\n\ncount($arrivees) : le nombre de cases.\nUn compteur : $rang = 1; avant, $rang++; dans la boucle.' },
      { title: 'La solution', body: '$nombre = count($arrivees);\nif ($nombre == 0) {\n    echo \'<p>Aucune arrivée ce soir.</p>\';\n} else {\n    if ($nombre == 1) {\n        echo \'<p>1 arrivée ce soir</p>\';\n    } else {\n        echo \'<p>\' . $nombre . \' arrivées ce soir</p>\';\n    }\n    echo \'<ul>\';\n    $rang = 1;\n    foreach ($arrivees as $client) {\n        echo \'<li>\' . $rang . \'. \' . $client . \'</li>\';\n        $rang++;\n    }\n    echo \'</ul>\';\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Chaque soir, je recopie la liste du registre sur la page des arrivées. Trois, cinq, parfois personne.' },
      { who: 'sami', expr: 'sourire', text: 'Le registre te donne un tableau, $arrivees. Tu ne sais pas combien il a de cases : foreach, si.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourcil', text: '« A. L. », encore. Chaque jeudi. Il réserve la 12 et il paie en liquide. Je ne l\'ai jamais vu : il arrive après mon départ.' }
    ]
  });

  ST.add({
    id: 'N14', act: 4, learn: ['tableaux-associatifs'], notions: ['tableaux-associatifs', 'foreach', 'calculs'], page: 'tarifs.php',
    add: { 'tarifs.php': TARIFS },
    ref: { 'tarifs.php': N14 },
    cases: [
      { id: 'hiver', who: 'Mme Roux', page: 'tarifs.php', at: THU + '20:00:00', reg: reg({ tarifs: { Simple: 62, Double: 78, Suite: 120 } }), visible: true, label: 'Tarifs d\'hiver : trois types, dès 62,00 €', check: prices({ Simple: 62, Double: 78, Suite: 120 }) },
      { id: 'hiver2', who: 'M. Diaz', page: 'tarifs.php', at: FRI + '23:00:00', reg: reg({ tarifs: { Simple: 62, Double: 78, Suite: 120 }, garde: 'Nora' }), visible: true, label: 'La même grille, vendredi soir', check: prices({ Simple: 62, Double: 78, Suite: 120 }) },
      { id: 'ete', who: 'Un client de juillet', page: 'tarifs.php', at: '2027-07-15 21:00:00', reg: reg({ tarifs: { Simple: 74, Double: 92, Suite: 140, Familiale: 125 } }), label: 'Haute saison, avec les familiales', check: prices({ Simple: 74, Double: 92, Suite: 140, Familiale: 125 }) },
      { id: 'travaux', who: 'Une cliente de dimanche', page: 'tarifs.php', at: SUN + '20:00:00', reg: reg({ tarifs: { Double: 78 }, garde: 'Théo' }), label: 'Un soir de travaux : seulement des doubles', check: prices({ Double: 78 }) },
      { id: 'promo', who: 'Lina', page: 'tarifs.php', at: SAT + '20:00:00', reg: reg({ tarifs: { Suite: 120, Simple: 59.5 }, garde: 'Théo' }), label: 'Une promotion à 59,50 €', check: prices({ Suite: 120, Simple: 59.5 }) }
    ],
    traps: [
      { label: 'les trois types écrits en dur', files: { 'tarifs.php': N14.replace('foreach ($tarifs as $type => $prix) {\n    echo \'    <li>\' . $type . \' : \' . number_format($prix, 2, \',\', \' \') . \' €</li>\' . "\\n";\n}', ['Simple', 'Double', 'Suite'].map(function (t) { return 'echo \'    <li>' + t + ' : \' . number_format($tarifs[\'' + t + '\'], 2, \',\', \' \') . \' €</li>\' . "\\n";'; }).join('\n')) } },
      { label: 'le prix le plus bas écrit en dur', files: { 'tarifs.php': N14.replace('number_format(min($tarifs), 2, \',\', \' \')', 'number_format(62, 2, \',\', \' \')') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$chambres = [\'Roux\' => 12, \'Diaz\' => 3];', '$chambres[\'Okafor\'] = 21;', 'echo $chambres[\'Diaz\'] . \' / \' . count($chambres);', '?>'], run: {} } },
    hints: [
      { title: 'Des cases avec un nom', body: '$tarifs = [\'Simple\' => 62, \'Double\' => 78];\n$tarifs[\'Double\']  →  78\n\nforeach ($tarifs as $type => $prix) {\n    // $type : \'Simple\', puis \'Double\'… ; $prix : 62, puis 78…\n}\nmin($tarifs) : la plus petite valeur.' },
      { title: 'La solution', body: '<ul>\n<?php\nforeach ($tarifs as $type => $prix) {\n    echo \'<li>\' . $type . \' : \' . number_format($prix, 2, \',\', \' \') . \' €</li>\';\n}\n?>\n</ul>\n<p>Dès <?php echo number_format(min($tarifs), 2, \',\', \' \'); ?> € la nuit</p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Les tarifs bougent avec la saison, et l\'annexe aura des familiales au printemps. Le registre les donne, type par type.' },
      { who: 'sami', expr: 'neutre', text: 'Un tableau dont les cases ont un nom au lieu d\'un numéro. Le carnet rouge appelle ça des clés.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Cinquante-neuf euros cinquante, la promotion de Lina. Et en juillet, les familiales. Je n\'ai rien à toucher.' },
      { who: 'sami', expr: 'sourcil', text: 'Dans le registre des réservations, « A. L. » revient tous les jeudis depuis la retraite d\'Augustin. La 12. En liquide.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
