/* Service de nuit — acte 4, « Les étages » (N18-N23), the game's half. */
(function (root) {
  'use strict';
  var ST = root.SDNSteps, sees = ST.sees, reg = ST.reg;
  var L = function () { return Array.prototype.slice.call(arguments).join('\n'); };
  function page(title, h1, body) {
    return L('<!DOCTYPE html>', '<html lang="fr">', '<head>', '  <meta charset="utf-8">', '  <title>' + title + ' · Hôtel des Quatre-Vents</title>', '</head>', '<body>', '  <h1>' + h1 + '</h1>', body, '</body>', '</html>', '');
  }

  var N10 = ST.refOf('N10')['accueil.php'];
  var ARRAYS = L(
    '$jours = [\'lundi\', \'mardi\', \'mercredi\', \'jeudi\', \'vendredi\', \'samedi\', \'dimanche\'];',
    '$mois = [\'janvier\', \'février\', \'mars\', \'avril\', \'mai\', \'juin\', \'juillet\', \'août\', \'septembre\', \'octobre\', \'novembre\', \'décembre\'];');
  var N18 = N10
    .replace('$jour = (int) date(\'N\');\n', '$jour = (int) date(\'N\');\n' + ARRAYS + '\n$date = $jours[date(\'N\') - 1] . \' \' . date(\'j\') . \' \' . $mois[date(\'n\') - 1];\n')
    .replace('<h1>Hôtel des Quatre-Vents</h1>', '<h1>Hôtel des Quatre-Vents</h1>\n  <p class="date">Nous sommes le <?php echo $date; ?>.</p>');
  var N19 = N18.replace('$date = $jours[date(\'N\') - 1] . \' \' . date(\'j\') . \' \' . $mois[date(\'n\') - 1];', L(
    '$quantieme = date(\'j\');',
    'if ($quantieme == 1) {',
    '    $quantieme = \'1er\';',
    '}',
    '$date = $jours[date(\'N\') - 1] . \' \' . $quantieme . \' \' . $mois[date(\'n\') - 1];'));

  var ARRIVEES = page('Les arrivées', 'Les arrivées du soir', L(
    '  <ul>',
    '    <li>1. Mme Roux</li>',
    '    <li>2. M. Diaz</li>',
    '    <li>3. Mme Faure</li>',
    '  </ul>'));
  var N20 = page('Les arrivées', 'Les arrivées du soir', L(
    '  <ul>',
    '<?php',
    '$rang = 1;',
    'foreach ($arrivees as $nom) {',
    '    echo \'    <li>\' . $rang . \'. \' . $nom . \'</li>\' . "\\n";',
    '    $rang++;',
    '}',
    '?>',
    '  </ul>'));
  var N21 = page('Les arrivées', 'Les arrivées du soir', L(
    '<?php',
    '$nombre = count($arrivees);',
    'if ($nombre == 0) {',
    '    echo \'  <p>Aucune arrivée ce soir.</p>\' . "\\n";',
    '} elseif ($nombre == 1) {',
    '    echo \'  <p>1 arrivée ce soir</p>\' . "\\n";',
    '} else {',
    '    echo \'  <p>\' . $nombre . \' arrivées ce soir</p>\' . "\\n";',
    '}',
    '?>',
    '  <ul>',
    '<?php',
    '$rang = 1;',
    'foreach ($arrivees as $nom) {',
    '    echo \'    <li>\' . $rang . \'. \' . $nom . \'</li>\' . "\\n";',
    '    $rang++;',
    '}',
    '?>',
    '  </ul>'));

  var TARIFS = page('Nos tarifs', 'Nos tarifs', L(
    '  <ul>',
    '    <li>Simple : 62 €</li>',
    '    <li>Double : 78 €</li>',
    '    <li>Suite : 120 €</li>',
    '  </ul>',
    '  <p>Dès 62 € la nuit</p>'));
  var FOREACH_TARIFS = L(
    '<?php',
    'foreach ($tarifs as $type => $prix) {',
    '    echo \'    <li>\' . $type . \' : \' . number_format($prix, 2, \',\', \' \') . \' €</li>\' . "\\n";',
    '}',
    '?>');
  var N22 = page('Nos tarifs', 'Nos tarifs', L('  <ul>', FOREACH_TARIFS, '  </ul>', '  <p>Dès 62 € la nuit</p>'));
  var N23 = N22.replace('  <p>Dès 62 € la nuit</p>', '  <p>Dès <?php echo number_format(min($tarifs), 2, \',\', \' \'); ?> € la nuit</p>');

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
  function numbered(names) { return list(names.map(function (n, i) { return (i + 1) + '. ' + n; })); }
  function arrivals(names) {
    var head = !names.length ? 'Aucune arrivée ce soir.' : (names.length === 1 ? '1 arrivée ce soir' : names.length + ' arrivées ce soir');
    return function (res, H) {
      if (!H.has(res, head)) return 'la page n\'affiche pas « ' + head + ' »';
      if (names.length !== 1 && H.has(res, '1 arrivées')) return '« 1 arrivées » : au singulier, une arrivée';
      return numbered(names)(res, H);
    };
  }
  function lines(tarifs) { var items = []; for (var k in tarifs) items.push(k + ' : ' + ST.helpers.euros(tarifs[k])); return list(items); }
  function prices(tarifs) {
    var min = Infinity;
    for (var k in tarifs) min = Math.min(min, tarifs[k]);
    return function (res, H) { var r = lines(tarifs)(res, H); return r === true ? sees('Dès ' + H.euros(min) + ' la nuit')(res, H) : r; };
  }
  var HIVER = { Simple: 62, Double: 78, Suite: 120 }, ETE = { Simple: 74, Double: 92, Suite: 140, Familiale: 125 }, DOUBLES = { Double: 78 }, PROMO = { Suite: 120, Simple: 59.5 };
  var JEUDI = ['Mme Roux', 'M. Diaz', 'Mme Faure'], VENDREDI = ['Famille Okafor', 'Lina'], CONGRES = ['M. et Mme Bergeron', 'Mme Roux', 'Lina', 'M. Diaz', 'Mme Faure'];

  ST.add({
    id: 'N18', act: 4, learn: ['tableaux'], notions: ['tableaux', 'date', 'concatenation'],
    show: { before: '(rien)', after: 'Nous sommes le jeudi 12 novembre.', note: 'en français, sous le titre de la page d\'accueil' },
    ref: { 'accueil.php': N18 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', reg: reg(), visible: true, label: 'Jeudi 12 novembre', check: sees('Nous sommes le jeudi 12 novembre') },
      { id: 'diaz', who: 'M. Diaz', at: SAT + '23:12:00', reg: reg({ client: 'M. Diaz', chambre: 3 }), visible: true, label: 'Samedi 14 novembre', check: sees('Nous sommes le samedi 14 novembre') },
      { id: 'ifs', source: true, visible: true, label: 'Aucune condition de plus : un tableau donne le nom du jour', check: function (files, H, base) { var n = added(files, H, base); return n === 0 || n + ' condition' + (n > 1 ? 's' : '') + ' de plus : range les noms dans un tableau, et lis la bonne case'; } },
      { id: 'dimanche', who: 'Une cliente de dimanche', at: SUN + '10:40:00', reg: reg({ garde: 'Théo' }), label: 'Dimanche 15 novembre', check: sees('Nous sommes le dimanche 15 novembre') },
      { id: 'decembre', who: 'Un client de décembre', at: '2026-12-08 21:00:00', reg: reg({ garde: 'Nora' }), label: 'Mardi 8 décembre', check: sees('Nous sommes le mardi 8 décembre') },
      { id: 'janvier', who: 'Un client de janvier', at: '2027-01-15 01:30:00', reg: reg({ garde: 'Théo' }), label: 'Vendredi 15 janvier', check: sees('Nous sommes le vendredi 15 janvier') }
    ],
    traps: [
      { label: 'date(\'w\') - 1 : le dimanche tombe hors du tableau', files: { 'accueil.php': N18.replace('$jours[date(\'N\') - 1]', '$jours[date(\'w\') - 1]') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$etages = [\'rez-de-chaussée\', \'premier\', \'deuxième\', \'troisième\'];', 'echo $etages[1] . \' / \' . count($etages);', '?>'], run: {} } },
    hints: [
      { title: 'Des cases numérotées', body: '$jours = [\'lundi\', \'mardi\', …, \'dimanche\'];\n$jours[0]  →  lundi\n$jours[3]  →  jeudi\n\ndate(\'N\') : 1 le lundi … 7 le dimanche  → la case date(\'N\') - 1\ndate(\'n\') : 1 en janvier … 12 en décembre\ndate(\'j\') : le jour du mois, sans zéro' },
      { title: 'La solution', body: '$jours = [\'lundi\', \'mardi\', \'mercredi\', \'jeudi\', \'vendredi\', \'samedi\', \'dimanche\'];\n$mois = [\'janvier\', …, \'décembre\'];\n$date = $jours[date(\'N\') - 1] . \' \' . date(\'j\') . \' \' . $mois[date(\'n\') - 1];\n…\n<p class="date">Nous sommes le <?php echo $date; ?>.</p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'sourcil', text: 'J\'ai essayé date(\'l j F\') : la page m\'a répondu « Thursday 12 November ». Je veux la date en français, en haut de l\'accueil.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Jeudi 12 novembre. Sept jours, douze mois, et pas un seul if.' }
    ]
  });

  ST.add({
    id: 'N19', act: 4, learn: ['conditions'], notions: ['conditions', 'tableaux'],
    show: { before: 'Nous sommes le mardi 1 décembre.', after: 'Nous sommes le mardi 1er décembre.', note: 'le premier du mois seulement' },
    ref: { 'accueil.php': N19 },
    cases: [
      { id: 'decembre', who: 'Un client de décembre', at: '2026-12-01 21:00:00', reg: reg({ garde: 'Nora' }), visible: true, label: 'Mardi 1er décembre', check: sees('Nous sommes le mardi 1er décembre') },
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', reg: reg(), visible: true, label: 'Jeudi 12 novembre : toujours « 12 »', check: sees('Nous sommes le jeudi 12 novembre') },
      { id: 'ifs', source: true, visible: true, label: 'Au plus une condition de plus', check: function (files, H, base) { var n = added(files, H, base); return n <= 1 || n + ' conditions de plus : une seule suffit'; } },
      { id: 'janvier', who: 'Le premier client de l\'année', at: '2027-01-01 01:30:00', reg: reg({ garde: 'Théo' }), label: 'Vendredi 1er janvier', check: sees('Nous sommes le vendredi 1er janvier') },
      { id: 'mars', who: 'Une cliente de mars', at: '2027-03-01 20:00:00', reg: reg({ garde: 'Nora' }), label: 'Lundi 1er mars', check: sees('Nous sommes le lundi 1er mars') },
      { id: 'onze', who: 'Un client du 11', at: '2026-12-11 22:00:00', reg: reg({ garde: 'Sami' }), label: 'Vendredi 11 décembre : pas de « er »', check: sees('Nous sommes le vendredi 11 décembre') }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$q = 1;', 'if ($q == 1) {', '    $q = \'1er\';', '}', 'echo \'le \' . $q . \' mai\';', '?>'], run: {} } },
    hints: [
      { title: 'Un seul cas à part', body: 'Range le jour du mois dans une variable, et change-la si c\'est le 1 :\n\n  $quantieme = date(\'j\');\n  if ($quantieme == 1) {\n      $quantieme = \'1er\';\n  }\n\npuis sers-toi de $quantieme à la place de date(\'j\').' },
      { title: 'La solution', body: '$quantieme = date(\'j\');\nif ($quantieme == 1) {\n    $quantieme = \'1er\';\n}\n$date = $jours[date(\'N\') - 1] . \' \' . $quantieme . \' \' . $mois[date(\'n\') - 1];' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Le premier du mois, on n\'écrit pas « 1 décembre » : on écrit « 1er décembre ». Les autres jours, rien ne change.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Mardi 1er décembre. Augustin l\'écrivait à la main sur l\'ardoise, tous les premiers du mois.' }
    ]
  });

  ST.add({
    id: 'N20', act: 4, learn: ['foreach'], notions: ['foreach', 'tableaux'], page: 'arrivees.php',
    show: { before: '1. Mme Roux · 2. M. Diaz · 3. Mme Faure, écrits à la main', after: '1. Famille Okafor · 2. Lina', note: 'vendredi : la liste que l\'hôtel prépare dans $arrivees' },
    add: { 'arrivees.php': ARRIVEES },
    ref: { 'arrivees.php': N20 },
    cases: [
      { id: 'jeudi', who: 'Mireille, jeudi', page: 'arrivees.php', at: THU + '18:00:00', reg: reg({ arrivees: JEUDI }), visible: true, label: 'Jeudi : trois arrivées, numérotées', check: numbered(JEUDI) },
      { id: 'vendredi', who: 'Mireille, vendredi', page: 'arrivees.php', at: FRI + '18:00:00', reg: reg({ arrivees: VENDREDI, garde: 'Nora' }), visible: true, label: 'Vendredi : deux arrivées, numérotées', check: numbered(VENDREDI) },
      { id: 'une', who: 'Théo, samedi', page: 'arrivees.php', at: SAT + '18:00:00', reg: reg({ arrivees: ['M. Diaz'], garde: 'Théo' }), label: 'Samedi : une seule arrivée', check: numbered(['M. Diaz']) },
      { id: 'cinq', who: 'Mireille, un jeudi de congrès', page: 'arrivees.php', at: '2026-11-19 18:00:00', reg: reg({ arrivees: CONGRES }), label: 'Un jeudi de congrès : cinq arrivées', check: numbered(CONGRES) },
      { id: 'aucune', who: 'Nora, un dimanche creux', page: 'arrivees.php', at: SUN + '18:00:00', reg: reg({ arrivees: [], garde: 'Théo' }), label: 'Dimanche : personne', check: numbered([]) }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$cles = [\'101\', \'102\', \'205\'];', 'foreach ($cles as $cle) {', '    echo $cle . \' \';', '}', 'echo \'(\' . count($cles) . \')\';', '?>'], run: {} } },
    hints: [
      { title: 'Une fois par case', body: 'foreach ($arrivees as $nom) {\n    // $nom vaut la case du tour : \'Mme Roux\', puis \'M. Diaz\'…\n}\n\nPour numéroter, un compteur :\n  $rang = 1;  avant la boucle\n  $rang++;    dans la boucle' },
      { title: 'La solution', body: '<ul>\n<?php\n$rang = 1;\nforeach ($arrivees as $nom) {\n    echo \'<li>\' . $rang . \'. \' . $nom . \'</li>\';\n    $rang++;\n}\n?>\n</ul>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Chaque soir, je recopie la liste des clients attendus. L\'hôtel la prépare pourtant, dans $arrivees : trois, cinq, parfois personne.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Cinq arrivées le soir du congrès, et je n\'ai rien recopié.' }
    ]
  });

  ST.add({
    id: 'N21', act: 4, learn: ['tableaux'], notions: ['tableaux', 'conditions-multiples'], page: 'arrivees.php',
    show: { before: '(rien au-dessus de la liste)', after: '3 arrivées ce soir', note: '« 1 arrivée ce soir » au singulier, « Aucune arrivée ce soir. » quand il n\'y a personne' },
    ref: { 'arrivees.php': N21 },
    cases: [
      { id: 'jeudi', who: 'Mireille, jeudi', page: 'arrivees.php', at: THU + '18:00:00', reg: reg({ arrivees: JEUDI }), visible: true, label: 'Jeudi : « 3 arrivées ce soir »', check: arrivals(JEUDI) },
      { id: 'vendredi', who: 'Mireille, vendredi', page: 'arrivees.php', at: FRI + '18:00:00', reg: reg({ arrivees: VENDREDI, garde: 'Nora' }), visible: true, label: 'Vendredi : « 2 arrivées ce soir »', check: arrivals(VENDREDI) },
      { id: 'une', who: 'Théo, samedi', page: 'arrivees.php', at: SAT + '18:00:00', reg: reg({ arrivees: ['M. Diaz'], garde: 'Théo' }), label: 'Samedi : une seule arrivée', check: arrivals(['M. Diaz']) },
      { id: 'aucune', who: 'Nora, un dimanche creux', page: 'arrivees.php', at: SUN + '18:00:00', reg: reg({ arrivees: [], garde: 'Théo' }), label: 'Dimanche : personne', check: arrivals([]) },
      { id: 'cinq', who: 'Mireille, un jeudi de congrès', page: 'arrivees.php', at: '2026-11-19 18:00:00', reg: reg({ arrivees: CONGRES }), label: 'Un jeudi de congrès : cinq arrivées', check: arrivals(CONGRES) }
    ],
    traps: [
      { label: 'pas de singulier : « 1 arrivées »', files: { 'arrivees.php': N21.replace('} elseif ($nombre == 1) {\n    echo \'  <p>1 arrivée ce soir</p>\' . "\\n";\n', '') } },
      { label: 'rien de prévu quand personne n\'arrive', files: { 'arrivees.php': N21.replace('if ($nombre == 0) {\n    echo \'  <p>Aucune arrivée ce soir.</p>\' . "\\n";\n} elseif ($nombre == 1) {', 'if ($nombre == 1) {') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$cles = [\'101\'];', '$n = count($cles);', 'if ($n == 1) {', '    echo \'1 clé\';', '} else {', '    echo $n . \' clés\';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Compter, puis choisir', body: 'count($arrivees) : le nombre de cases.\n\nAu-dessus de la liste, trois cas :\n  0  → « Aucune arrivée ce soir. »\n  1  → « 1 arrivée ce soir »\n  sinon → « 3 arrivées ce soir »' },
      { title: 'La solution', body: '<?php\n$nombre = count($arrivees);\nif ($nombre == 0) {\n    echo \'<p>Aucune arrivée ce soir.</p>\';\n} elseif ($nombre == 1) {\n    echo \'<p>1 arrivée ce soir</p>\';\n} else {\n    echo \'<p>\' . $nombre . \' arrivées ce soir</p>\';\n}\n?>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Au-dessus de la liste, je voudrais le nombre : « 3 arrivées ce soir ». Au singulier s\'il n\'y en a qu\'une, et « Aucune arrivée ce soir. » s\'il n\'y a personne.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: '« 1 arrivée ». Pas « 1 arrivées ». Josette va enfin arrêter de corriger mes pages au stylo.' }
    ]
  });

  ST.add({
    id: 'N22', act: 4, learn: ['tableaux-associatifs'], notions: ['tableaux-associatifs', 'foreach', 'calculs'], page: 'tarifs.php',
    show: { before: 'Simple : 62 €, écrit à la main', after: 'Simple : 62,00 € · Double : 78,00 € · Suite : 120,00 €', note: 'une ligne par type de chambre de $tarifs' },
    add: { 'tarifs.php': TARIFS },
    ref: { 'tarifs.php': N22 },
    cases: [
      { id: 'hiver', who: 'Mme Roux', page: 'tarifs.php', at: THU + '20:00:00', reg: reg({ tarifs: HIVER }), visible: true, label: 'Tarifs d\'hiver : trois types', check: lines(HIVER) },
      { id: 'hiver2', who: 'M. Diaz', page: 'tarifs.php', at: FRI + '23:00:00', reg: reg({ tarifs: HIVER, garde: 'Nora' }), visible: true, label: 'La même grille, vendredi soir', check: lines(HIVER) },
      { id: 'ete', who: 'Un client de juillet', page: 'tarifs.php', at: '2027-07-15 21:00:00', reg: reg({ tarifs: ETE }), label: 'Haute saison, avec les familiales', check: lines(ETE) },
      { id: 'travaux', who: 'Une cliente de dimanche', page: 'tarifs.php', at: SUN + '20:00:00', reg: reg({ tarifs: DOUBLES, garde: 'Théo' }), label: 'Un soir de travaux : seulement des doubles', check: lines(DOUBLES) },
      { id: 'promo', who: 'Lina', page: 'tarifs.php', at: SAT + '20:00:00', reg: reg({ tarifs: PROMO, garde: 'Théo' }), label: 'Une promotion à 59,50 €', check: lines(PROMO) }
    ],
    traps: [
      { label: 'les trois types écrits en dur', files: { 'tarifs.php': N22.replace(FOREACH_TARIFS, '<?php\n' + ['Simple', 'Double', 'Suite'].map(function (t) { return 'echo \'    <li>' + t + ' : \' . number_format($tarifs[\'' + t + '\'], 2, \',\', \' \') . \' €</li>\' . "\\n";'; }).join('\n') + '\n?>') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$chambres = [\'Roux\' => 12, \'Diaz\' => 3];', '$chambres[\'Okafor\'] = 21;', 'echo $chambres[\'Diaz\'] . \' / \' . count($chambres);', '?>'], run: {} } },
    hints: [
      { title: 'Des cases avec un nom', body: '$tarifs = [\'Simple\' => 62, \'Double\' => 78];\n$tarifs[\'Double\']  →  78\n\nforeach ($tarifs as $type => $prix) {\n    // $type : \'Simple\', puis \'Double\'… ; $prix : 62, puis 78…\n}' },
      { title: 'La solution', body: '<ul>\n<?php\nforeach ($tarifs as $type => $prix) {\n    echo \'<li>\' . $type . \' : \' . number_format($prix, 2, \',\', \' \') . \' €</li>\';\n}\n?>\n</ul>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Les tarifs bougent avec la saison, et l\'annexe aura des familiales. L\'hôtel prépare $tarifs : chaque type de chambre, avec son prix.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'En juillet, les familiales apparaîtront toutes seules. Je n\'aurai rien à toucher.' }
    ]
  });

  ST.add({
    id: 'N23', act: 4, learn: ['tableaux-associatifs'], notions: ['tableaux-associatifs', 'calculs'], page: 'tarifs.php',
    show: { before: 'Dès 62 € la nuit, écrit à la main', after: 'Dès 62,00 € la nuit', note: 'le prix le plus bas de $tarifs, quel qu\'il soit' },
    ref: { 'tarifs.php': N23 },
    cases: [
      { id: 'hiver', who: 'Mme Roux', page: 'tarifs.php', at: THU + '20:00:00', reg: reg({ tarifs: HIVER }), visible: true, label: 'Tarifs d\'hiver : dès 62,00 €', check: prices(HIVER) },
      { id: 'ete', who: 'Un client de juillet', page: 'tarifs.php', at: '2027-07-15 21:00:00', reg: reg({ tarifs: ETE }), label: 'Haute saison', check: prices(ETE) },
      { id: 'travaux', who: 'Une cliente de dimanche', page: 'tarifs.php', at: SUN + '20:00:00', reg: reg({ tarifs: DOUBLES, garde: 'Théo' }), label: 'Un soir de travaux : seulement des doubles', check: prices(DOUBLES) },
      { id: 'promo', who: 'Lina', page: 'tarifs.php', at: SAT + '20:00:00', reg: reg({ tarifs: PROMO, garde: 'Théo' }), label: 'Une promotion à 59,50 €', check: prices(PROMO) }
    ],
    traps: [
      { label: 'le prix le plus bas écrit en dur', files: { 'tarifs.php': N23.replace('number_format(min($tarifs), 2, \',\', \' \')', 'number_format(62, 2, \',\', \' \')') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$prix = [\'Simple\' => 62, \'Suite\' => 120, \'Double\' => 78];', 'echo min($prix) . \' / \' . max($prix);', '?>'], run: {} } },
    hints: [
      { title: 'Le plus petit', body: 'min($tarifs) : la plus petite valeur du tableau.\n\nÀ écrire à la française, comme les lignes :\n  number_format(min($tarifs), 2, \',\', \' \')' },
      { title: 'La solution', body: '<p>Dès <?php echo number_format(min($tarifs), 2, \',\', \' \'); ?> € la nuit</p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'En bas, « Dès 62 € la nuit » est écrit à la main. Le jour d\'une promotion, c\'est faux. Je veux le prix le plus bas de la grille.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Dès 59,50 €, le soir de la promotion de Lina. Juste, sans que j\'y pense.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
