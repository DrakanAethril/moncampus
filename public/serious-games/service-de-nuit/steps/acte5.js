/* Service de nuit — acte 5, « La chambre 12 » (N15-N17), the game's half. */
(function (root) {
  'use strict';
  var ST = root.SDNSteps, sees = ST.sees, reg = ST.reg;
  var L = function () { return Array.prototype.slice.call(arguments).join('\n'); };
  function page(title, h1, body) {
    return L('<!DOCTYPE html>', '<html lang="fr">', '<head>', '  <meta charset="utf-8">', '  <title>' + title + ' · Hôtel des Quatre-Vents</title>', '</head>', '<body>', '  <h1>' + h1 + '</h1>', body, '</body>', '</html>', '');
  }

  var BEFORE = ST.refOf('N14');
  var FONCTIONS = L('<?php', '// Les fonctions du site. Une page s\'en sert après : include \'fonctions.php\';', '');
  var PRIX = L('function prix($montant)', '{', '    return number_format($montant, 2, \',\', \' \') . \' €\';', '}', '');
  var N15_FONCTIONS = FONCTIONS + '\n' + PRIX;
  var N15_ACCUEIL = BEFORE['accueil.php']
    .replace('<?php\n$telephone', '<?php\ninclude \'fonctions.php\';\n$telephone')
    .replace('<?php echo number_format($total, 2, \',\', \' \'); ?> €', '<?php echo prix($total); ?>');
  var N15_TARIFS = ('<?php include \'fonctions.php\'; ?>\n' + BEFORE['tarifs.php'])
    .replace('\' : \' . number_format($prix, 2, \',\', \' \') . \' €</li>\'', '\' : \' . prix($prix) . \'</li>\'')
    .replace('Dès <?php echo number_format(min($tarifs), 2, \',\', \' \'); ?> € la nuit', 'Dès <?php echo prix(min($tarifs)); ?> la nuit');

  var RESERVATION = page('Ma réservation', 'Retrouver ma réservation', L(
    '  <form method="get" action="reservation.php">',
    '    <label for="nom">Votre nom</label>',
    '    <input id="nom" name="nom">',
    '    <button>Chercher</button>',
    '  </form>'));
  var N16 = page('Ma réservation', 'Retrouver ma réservation', L(
    '  <form method="get" action="reservation.php">',
    '    <label for="nom">Votre nom</label>',
    '    <input id="nom" name="nom">',
    '    <button>Chercher</button>',
    '  </form>',
    '<?php',
    'if (isset($_GET[\'nom\'])) {',
    '    $nom = $_GET[\'nom\'];',
    '    if (isset($reservations[$nom])) {',
    '        echo \'  <p>\' . htmlspecialchars($nom) . \' : chambre \' . $reservations[$nom] . \'.</p>\';',
    '    } else {',
    '        echo \'  <p>Aucune réservation au nom de \' . htmlspecialchars($nom) . \'.</p>\';',
    '    }',
    '}',
    '?>'));

  var VEILLEURS = page('Les veilleurs', 'Les veilleurs du mois', '  <p>Page à construire. A.</p>');
  var NUITS = L('function nuits($n)', '{', '    if ($n <= 1) {', '        return $n . \' nuit\';', '    }', '    return $n . \' nuits\';', '}', '');
  var N17_FONCTIONS = N15_FONCTIONS + '\n' + NUITS;
  var N17 = L(
    '<?php',
    'include \'fonctions.php\';',
    '$total = 0;',
    'foreach ($veilleurs as $nom => $n) {',
    '    $total = $total + $n;',
    '}',
    '?>',
    page('Les veilleurs', 'Les veilleurs du mois', L(
      '  <ul>',
      '<?php',
      'foreach ($veilleurs as $nom => $n) {',
      '    $ligne = $nom . \' : \' . nuits($n);',
      '    if ($nom == $garde) {',
      '        $ligne = $ligne . \' (de garde)\';',
      '    }',
      '    echo \'    <li>\' . $ligne . \'</li>\' . "\\n";',
      '}',
      '?>',
      '  </ul>',
      '  <p>Total : <?php echo nuits($total); ?></p>')));

  var THU = '2026-11-19 ', FRI = '2026-11-20 ', SAT = '2026-11-21 ', SUN = '2026-11-22 ';
  var H = ST.helpers;

  function list(expected) {
    return function (res, H2) {
      var got = H2.items(res.output);
      for (var i = 0; i < Math.max(got.length, expected.length); i++) {
        if (got[i] !== expected[i]) {
          if (got[i] === undefined) return 'il manque « ' + expected[i] + ' »';
          if (expected[i] === undefined) return 'une ligne de trop : « ' + got[i] + ' »';
          return 'ligne ' + (i + 1) + ' : « ' + got[i] + ' » au lieu de « ' + expected[i] + ' »';
        }
      }
      return true;
    };
  }
  function stay(nuits, tarif, personnes) { return sees('Total à régler : ' + H.euros(nuits * tarif + nuits * personnes * 1.5)); }
  function prices(tarifs) {
    var items = [], min = Infinity;
    for (var k in tarifs) { items.push(k + ' : ' + H.euros(tarifs[k])); min = Math.min(min, tarifs[k]); }
    return function (res, H2) { var r = list(items)(res, H2); return r === true ? sees('Dès ' + H2.euros(min) + ' la nuit')(res, H2) : r; };
  }
  var formats = function (files, H2) { var n = 0; for (var f in files) if (f !== 'pied.php') n += H2.count(H2.code(files[f]), 'number_format'); return n; };
  function found(nom, chambre) { return sees(nom + ' : chambre ' + chambre + '.'); }
  function unknown(shown) {
    return function (res, H2) {
      if (res.output.indexOf('<b>') !== -1) return 'le HTML tapé par le visiteur passe dans la page : le navigateur l\'exécute';
      return sees('Aucune réservation au nom de ' + shown + '.')(res, H2);
    };
  }
  function nothingYet(res, H2) {
    if (H2.has(res, 'Aucune réservation') || H2.has(res, ': chambre')) return 'la page répond avant que le visiteur ait envoyé un nom';
    return /<input[^>]*name="nom"/.test(res.output) || 'le champ « Votre nom » a disparu';
  }
  function plural(n) { return n + (n <= 1 ? ' nuit' : ' nuits'); }
  function watchers(v, garde) {
    var items = [], total = 0;
    for (var k in v) { items.push(k + ' : ' + plural(v[k]) + (k === garde ? ' (de garde)' : '')); total += v[k]; }
    return function (res, H2) {
      var r = list(items)(res, H2);
      if (r !== true) return r;
      // « Total : 1 nuits » contains « Total : 1 nuit »: the word must end there.
      var parts = H2.text(res.output).split('Total : ' + plural(total));
      return parts.length < 2 || /^s/.test(parts[1]) ? 'la page n\'affiche pas « Total : ' + plural(total) + ' »' : true;
    };
  }

  ST.add({
    id: 'N15', act: 5, learn: ['fonctions'], notions: ['fonctions', 'calculs', 'tableaux-associatifs'], page: 'fonctions.php',
    add: { 'fonctions.php': FONCTIONS },
    ref: { 'fonctions.php': N15_FONCTIONS, 'accueil.php': N15_ACCUEIL, 'tarifs.php': N15_TARIFS },
    cost: { label: 'fois où number_format est écrit dans le site', max: 1, measure: formats },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '22:40:00', reg: reg({ nuits: 1, tarif: 78, personnes: 1 }), visible: true, label: 'L\'accueil : « Total à régler : 79,50 € »', check: stay(1, 78, 1) },
      { id: 'tarifs', who: 'M. Diaz', page: 'tarifs.php', at: THU + '23:30:00', reg: reg({ client: 'M. Diaz', chambre: 3 }), visible: true, label: 'Les tarifs d\'hiver', check: prices({ Simple: 62, Double: 78, Suite: 120 }) },
      { id: 'une', source: true, visible: true, label: 'number_format n\'est écrit qu\'une fois dans tout le site', check: function (files, H2) { var n = formats(files, H2); return n === 1 || 'number_format est écrit ' + n + ' fois'; } },
      { id: 'bergeron', who: 'Les Bergeron', at: SAT + '23:30:00', reg: reg({ client: 'M. et Mme Bergeron', chambre: 30, nuits: 7, tarif: 160, personnes: 2, garde: 'Théo' }), label: 'Les Bergeron : plus de 1 000 €', check: stay(7, 160, 2) },
      { id: 'gala', who: 'Un organisateur de gala', page: 'tarifs.php', at: FRI + '20:00:00', reg: reg({ tarifs: { Double: 78, Suite: 1250 }, garde: 'Nora' }), label: 'Un soir de gala : la suite à 1 250 €', check: prices({ Double: 78, Suite: 1250 }) }
    ],
    traps: [
      { label: 'prix() sans l\'espace des milliers', files: { 'fonctions.php': N15_FONCTIONS.replace('number_format($montant, 2, \',\', \' \')', 'number_format($montant, 2, \',\')') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'function nuitee($tarif, $nuits = 1) {', '    return $tarif * $nuits;', '}', 'echo nuitee(62) . \' / \' . nuitee(62, 3);', '?>'], run: {} } },
    hints: [
      { title: 'Écrire une fois, appeler partout', body: 'Dans fonctions.php :\n  function prix($montant)\n  {\n      return …;\n  }\n\nEn haut de chaque page qui s\'en sert :\n  include \'fonctions.php\';\npuis :  <?php echo prix($total); ?>' },
      { title: 'La solution', body: 'fonctions.php :\n  function prix($montant)\n  {\n      return number_format($montant, 2, \',\', \' \') . \' €\';\n  }\n\naccueil.php : include \'fonctions.php\'; en haut, puis\n  <p>Total à régler : <?php echo prix($total); ?></p>\n\ntarifs.php : <?php include \'fonctions.php\'; ?> en tête, puis\n  echo \'<li>\' . $type . \' : \' . prix($prix) . \'</li>\';\n  <p>Dès <?php echo prix(min($tarifs)); ?> la nuit</p>' }
    ],
    intro: [
      { who: 'narr', text: 'Quatrième semaine. Le carnet rouge s\'ouvre sur une page cornée : « Ce qui s\'écrit deux fois devient une fonction. »' },
      { who: 'sami', expr: 'sourcil', text: 'number_format, trois fois dans le site. Et un soir, quelqu\'un a oublié l\'espace des milliers : « 1,141,00 € ». J\'ai vu la tête des Bergeron.' },
      { who: 'sami', expr: 'neutre', text: 'Je t\'ai créé fonctions.php. Une fonction prix(), écrite une fois, que l\'accueil et les tarifs appellent.' }
    ],
    done: [
      { who: 'sami', expr: 'sourire', text: 'Le jour où Mireille voudra « EUR » au lieu de « € », tu changeras une ligne.' }
    ]
  });

  ST.add({
    id: 'N16', act: 5, learn: ['formulaires'], notions: ['formulaires', 'tableaux-associatifs', 'conditions'], page: 'reservation.php',
    add: { 'reservation.php': RESERVATION },
    ref: { 'reservation.php': N16 },
    cases: [
      { id: 'roux', who: 'Mme Roux tape « Roux »', page: 'reservation.php', at: THU + '19:00:00', get: { nom: 'Roux' }, reg: reg(), visible: true, label: '?nom=Roux : « Roux : chambre 12. »', check: found('Roux', 12) },
      { id: 'vide', who: 'Un visiteur qui arrive sur la page', page: 'reservation.php', at: THU + '19:05:00', reg: reg(), visible: true, label: 'Sans nom envoyé : le formulaire, rien d\'autre', check: nothingYet },
      { id: 'dupont', who: 'Quelqu\'un tape « Dupont »', page: 'reservation.php', at: FRI + '20:00:00', get: { nom: 'Dupont' }, reg: reg({ garde: 'Nora' }), label: '?nom=Dupont', check: unknown('Dupont') },
      { id: 'html', who: 'Un petit malin tape « <b>Dupont</b> »', page: 'reservation.php', at: FRI + '20:10:00', get: { nom: '<b>Dupont</b>' }, reg: reg({ garde: 'Nora' }), label: 'Un nom qui contient du HTML', check: unknown('<b>Dupont</b>') },
      { id: 'okafor', who: 'La famille Okafor', page: 'reservation.php', at: SAT + '17:00:00', get: { nom: 'Okafor' }, reg: reg({ garde: 'Théo' }), label: '?nom=Okafor', check: found('Okafor', 21) }
    ],
    traps: [
      { label: 'la case lue sans vérifier qu\'elle existe', files: { 'reservation.php': N16.replace('if (isset($reservations[$nom])) {', 'if ($reservations[$nom]) {') } },
      { label: 'le nom écrit sans htmlspecialchars', files: { 'reservation.php': N16.split('htmlspecialchars($nom)').join('$nom') } }
    ],
    views: { veille: { title: 'reservation.php?chambre=12', lines: ['<?php', 'if (isset($_GET[\'chambre\'])) {', '    echo \'Chambre \' . htmlspecialchars($_GET[\'chambre\']);', '} else {', '    echo \'Quelle chambre ?\';', '}', '?>'], run: { get: { chambre: '12' } } } },
    hints: [
      { title: 'Ce que porte l\'adresse', body: 'reservation.php?nom=Roux  →  $_GET[\'nom\'] vaut \'Roux\'\nSans ?nom=…, la case n\'existe pas : isset($_GET[\'nom\']) d\'abord.\n\nisset($reservations[$nom])  : ce nom a-t-il une réservation ?\nhtmlspecialchars($nom)     : écrire sans que < et > deviennent du HTML.' },
      { title: 'La solution', body: 'if (isset($_GET[\'nom\'])) {\n    $nom = $_GET[\'nom\'];\n    if (isset($reservations[$nom])) {\n        echo \'<p>\' . htmlspecialchars($nom) . \' : chambre \' . $reservations[$nom] . \'.</p>\';\n    } else {\n        echo \'<p>Aucune réservation au nom de \' . htmlspecialchars($nom) . \'.</p>\';\n    }\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Les clients appellent pour savoir quelle chambre ils auront. Je voudrais qu\'ils trouvent tout seuls.' },
      { who: 'sami', expr: 'sourcil', text: 'Attention : ce qu\'on tape dans un champ arrive tel quel dans ta page. Tape <b>Dupont</b> et regarde ce qui se passe.' }
    ],
    done: [
      { who: 'sami', expr: 'sourire', text: 'Le <b> s\'affiche comme du texte, il ne met plus rien en gras. La porte est fermée.' },
      { who: 'mireille', expr: 'sourcil', text: 'Quelqu\'un a tapé « Lebrun » ce soir. Puis « A. L. ». Puis rien.' }
    ]
  });

  ST.add({
    id: 'N17', act: 5, learn: [], notions: ['fonctions', 'tableaux-associatifs', 'foreach', 'conditions', 'calculs', 'boucle-condition'], page: 'veilleurs.php',
    add: { 'veilleurs.php': VEILLEURS },
    ref: { 'fonctions.php': N17_FONCTIONS, 'veilleurs.php': N17 },
    cases: [
      { id: 'jeudi', who: 'Nora, jeudi', page: 'veilleurs.php', at: THU + '23:12:00', reg: reg({ veilleurs: { Sami: 8, Nora: 12, 'Théo': 10 }, garde: 'Nora' }), visible: true, label: 'Jeudi : Nora de garde, 30 nuits en tout', check: watchers({ Sami: 8, Nora: 12, 'Théo': 10 }, 'Nora') },
      { id: 'debut', who: 'Sami, début du mois', page: 'veilleurs.php', at: '2026-12-02 22:00:00', reg: reg({ veilleurs: { Sami: 1, Nora: 4 }, garde: 'Sami' }), visible: true, label: 'Début du mois : « 1 nuit », au singulier', check: watchers({ Sami: 1, Nora: 4 }, 'Sami') },
      { id: 'regle', source: true, visible: true, label: 'Le pluriel est écrit une fois, dans nuits(), dans fonctions.php', check: function (files, H2) {
        if (!/function\s+nuits\s*\(/.test(H2.code(files['fonctions.php'] || ''))) return 'pas de fonction nuits() dans fonctions.php';
        return H2.code(files['veilleurs.php'] || '').indexOf('nuits(') !== -1 || 'veilleurs.php n\'appelle pas nuits()';
      } },
      { id: 'toi', who: 'Toi, ta première nuit de décembre', page: 'veilleurs.php', at: '2026-12-01 23:00:00', reg: reg({ veilleurs: { Toi: 1 }, garde: 'Toi' }), label: 'Le 1er décembre : toi seul, une nuit', check: watchers({ Toi: 1 }, 'Toi') },
      { id: 'zero', who: 'Mireille, en fin de mois', page: 'veilleurs.php', at: '2026-11-30 22:00:00', reg: reg({ veilleurs: { Sami: 0, Nora: 6, 'Théo': 3, Toi: 9 }, garde: 'Toi' }), label: 'Sami en vacances : 0 nuit', check: watchers({ Sami: 0, Nora: 6, 'Théo': 3, Toi: 9 }, 'Toi') },
      { id: 'absent', who: 'Théo, un remplacement', page: 'veilleurs.php', at: SAT + '22:00:00', reg: reg({ veilleurs: { Sami: 2, Nora: 2 }, garde: 'Théo' }), label: 'Un remplaçant de garde, absent du registre', check: watchers({ Sami: 2, Nora: 2 }, 'Théo') }
    ],
    traps: [
      { label: 'le singulier pour 1 seulement : « 0 nuits »', files: { 'fonctions.php': N17_FONCTIONS.replace('if ($n <= 1) {', 'if ($n == 1) {') } },
      { label: 'le total toujours au pluriel', files: { 'veilleurs.php': N17.replace('<?php echo nuits($total); ?>', '<?php echo $total; ?> nuits') } }
    ],
    hints: [
      { title: 'Ce que tu sais déjà', body: 'fonctions.php : function nuits($n) { … return …; }\nveilleurs.php : include \'fonctions.php\';\n\nforeach ($veilleurs as $nom => $n)  → chaque veilleur et ses nuits\nif ($nom == $garde)                → celui de ce soir\n$total = $total + $n;              → la somme, à chaque tour' },
      { title: 'La solution', body: 'fonctions.php :\n  function nuits($n)\n  {\n      if ($n <= 1) {\n          return $n . \' nuit\';\n      }\n      return $n . \' nuits\';\n  }\n\nveilleurs.php :\n  include \'fonctions.php\';\n  $total = 0;\n  foreach ($veilleurs as $nom => $n) { $total = $total + $n; }\n  …\n  foreach ($veilleurs as $nom => $n) {\n      $ligne = $nom . \' : \' . nuits($n);\n      if ($nom == $garde) { $ligne = $ligne . \' (de garde)\'; }\n      echo \'<li>\' . $ligne . \'</li>\';\n  }\n  <p>Total : <?php echo nuits($total); ?></p>' }
    ],
    intro: [
      { who: 'narr', text: 'Jeudi 19 novembre, 23 h 12. La porte s\'ouvre. Un homme âgé pose une petite valise devant le comptoir et demande la 12, comme chaque jeudi.' },
      { who: 'augustin', expr: 'sourire', text: 'Bonsoir. Je vois que l\'enseigne tient. Il paraît que la page dit « Bonne nuit » après 23 h, maintenant.' },
      { who: 'mireille', expr: 'surprise', text: 'Augustin ?! « A. L. »… Augustin Lebrun. Tous les jeudis. Depuis le début ?' },
      { who: 'augustin', expr: 'neutre', text: 'Je voulais savoir entre quelles mains je laissais la loge. Il me reste une dernière page dans le carnet rouge. Elle est pour toi.' }
    ],
    done: [
      { who: 'augustin', expr: 'sourire', text: 'Zéro nuit, une nuit, trente nuits. Mon site a dit « 1 nuits » pendant vingt ans. Le tien ne le dira jamais.' },
      { who: 'sami', expr: 'sourire', text: 'Il manque une chose : ton nom dans le registre des veilleurs. Pour de bon, cette fois.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
