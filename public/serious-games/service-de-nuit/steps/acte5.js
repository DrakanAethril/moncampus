/* Service de nuit — acte 5, « Le comptoir » (N24-N28), the game's half. */
(function (root) {
  'use strict';
  var ST = root.SDNSteps, sees = ST.sees, reg = ST.reg;
  var L = function () { return Array.prototype.slice.call(arguments).join('\n'); };
  function page(title, h1, body) {
    return L('<!DOCTYPE html>', '<html lang="fr">', '<head>', '  <meta charset="utf-8">', '  <title>' + title + ' · Hôtel des Quatre-Vents</title>', '</head>', '<body>', '  <h1>' + h1 + '</h1>', body, '</body>', '</html>', '');
  }

  var BEFORE = ST.refOf('N23');
  var PRIX = L('function prix($montant)', '{', '    return number_format($montant, 2, \',\', \' \') . \' €\';', '}', '');
  var N24_ACCUEIL = BEFORE['accueil.php']
    .replace('<?php\n$telephone', '<?php\n' + PRIX + '$telephone')
    .replace('<?php echo number_format($total, 2, \',\', \' \'); ?> €', '<?php echo prix($total); ?>');

  var FONCTIONS = L('<?php', '// Les fonctions du site. Une page s\'en sert après : include \'fonctions.php\';', '');
  var N25_FONCTIONS = FONCTIONS + '\n' + PRIX;
  var N25_ACCUEIL = N24_ACCUEIL.replace('<?php\n' + PRIX, '<?php\ninclude \'fonctions.php\';\n');
  var N25_TARIFS = ('<?php include \'fonctions.php\'; ?>\n' + BEFORE['tarifs.php'])
    .replace('\' : \' . number_format($prix, 2, \',\', \' \') . \' €</li>\'', '\' : \' . prix($prix) . \'</li>\'')
    .replace('Dès <?php echo number_format(min($tarifs), 2, \',\', \' \'); ?> € la nuit', 'Dès <?php echo prix(min($tarifs)); ?> la nuit');

  var FORM = L(
    '  <form method="get" action="reservation.php">',
    '    <label for="nom">Votre nom</label>',
    '    <input id="nom" name="nom">',
    '    <button>Chercher</button>',
    '  </form>');
  var RESERVATION = page('Ma réservation', 'Retrouver ma réservation', FORM);
  var N26 = page('Ma réservation', 'Retrouver ma réservation', L(
    FORM,
    '<?php',
    'if (isset($_GET[\'nom\'])) {',
    '    $nom = $_GET[\'nom\'];',
    '    if (isset($reservations[$nom])) {',
    '        echo \'  <p>\' . $nom . \' : chambre \' . $reservations[$nom] . \'.</p>\';',
    '    } else {',
    '        echo \'  <p>Aucune réservation au nom de \' . $nom . \'.</p>\';',
    '    }',
    '}',
    '?>'));
  var N27 = N26
    .replace('echo \'  <p>\' . $nom . \' : chambre', 'echo \'  <p>\' . htmlspecialchars($nom) . \' : chambre')
    .replace('au nom de \' . $nom . \'.</p>\'', 'au nom de \' . htmlspecialchars($nom) . \'.</p>\'');

  var VEILLEURS = page('Les veilleurs', 'Les veilleurs du mois', '  <p>Page à construire.</p>');
  var NUITS = L('function nuits($n)', '{', '    if ($n <= 1) {', '        return $n . \' nuit\';', '    }', '    return $n . \' nuits\';', '}', '');
  var N28_FONCTIONS = N25_FONCTIONS + '\n' + NUITS;
  var N28 = L(
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
  var formats = function (files, H2) { var n = 0; for (var f in files) n += H2.count(H2.code(files[f]), 'number_format'); return n; };
  function defines(file, name) { return function (files, H2) { return new RegExp('function\\s+' + name + '\\s*\\(').test(H2.code(files[file] || '')); }; }
  function found(nom, chambre) { return sees(nom + ' : chambre ' + chambre + '.'); }
  function unknown(nom) { return sees('Aucune réservation au nom de ' + nom + '.'); }
  function unknownSafe(typed) {
    return function (res, H2) {
      if (res.output.indexOf(typed) !== -1) return 'le HTML tapé par le visiteur passe dans la page : le navigateur l\'exécute au lieu de l\'afficher';
      return unknown(typed)(res, H2);
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
  var ROUX = { nuits: 1, tarif: 78, personnes: 1 }, BERGERON = { client: 'M. et Mme Bergeron', chambre: 30, nuits: 7, tarif: 160, personnes: 2, garde: 'Théo' }, OKAFOR = { client: 'Famille Okafor', chambre: 21, nuits: 4, tarif: 105, personnes: 4, garde: 'Nora' };

  ST.add({
    id: 'N24', act: 5, learn: ['fonctions'], notions: ['fonctions', 'calculs'],
    show: { before: 'number_format($total, 2, \',\', \' \') . \' €\'', after: 'prix($total)', note: 'la page affiche toujours « Total à régler : 79,50 € »' },
    ref: { 'accueil.php': N24_ACCUEIL },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '22:40:00', reg: reg(ROUX), visible: true, label: 'L\'accueil : « Total à régler : 79,50 € »', check: stay(1, 78, 1) },
      { id: 'fn', source: true, visible: true, label: 'accueil.php définit prix() et s\'en sert pour le total', check: function (files, H2) {
        if (!defines('accueil.php', 'prix')(files, H2)) return 'pas de function prix(…) dans accueil.php';
        return H2.count(H2.code(files['accueil.php'] || ''), 'prix(') >= 2 || 'prix() est écrite, mais la page ne l\'appelle pas';
      } },
      { id: 'bergeron', who: 'Les Bergeron', at: SAT + '23:30:00', reg: reg(BERGERON), label: 'Les Bergeron : plus de 1 000 €', check: stay(7, 160, 2) },
      { id: 'okafor', who: 'La famille Okafor', at: FRI + '21:05:00', reg: reg(OKAFOR), label: 'La famille Okafor', check: stay(4, 105, 4) }
    ],
    traps: [
      { label: 'prix() sans l\'espace des milliers', files: { 'accueil.php': N24_ACCUEIL.replace('number_format($montant, 2, \',\', \' \')', 'number_format($montant, 2, \',\')') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'function double($n)', '{', '    return $n * 2;', '}', 'echo double(3) . \' / \' . double(10);', '?>'], run: {} } },
    hints: [
      { title: 'Écrire une fois, appeler partout', body: 'En haut du fichier, après <?php :\n\n  function prix($montant)\n  {\n      return …;\n  }\n\npuis, pour le total :  <?php echo prix($total); ?>\nreturn rend le texte à celui qui appelle la fonction.' },
      { title: 'La solution', body: '<?php\nfunction prix($montant)\n{\n    return number_format($montant, 2, \',\', \' \') . \' €\';\n}\n$telephone = …\n…\n<p>Total à régler : <?php echo prix($total); ?></p>' }
    ],
    intro: [
      { who: 'sami', expr: 'neutre', text: 'number_format avec ses quatre arguments, on va en avoir besoin partout. Range-le une fois dans une fonction prix(), et sers-t\'en pour le total.' }
    ],
    done: [
      { who: 'sami', expr: 'sourire', text: 'Le jour où Mireille voudra « EUR » au lieu de « € », tu changeras une ligne.' }
    ]
  });

  ST.add({
    id: 'N25', act: 5, learn: ['fonctions'], notions: ['fonctions', 'tableaux-associatifs'], page: 'fonctions.php',
    show: { before: 'prix() dans accueil.php, number_format dans tarifs.php', after: 'prix() dans fonctions.php, incluse par les deux pages', note: 'les pages affichent toujours les mêmes prix' },
    add: { 'fonctions.php': FONCTIONS },
    ref: { 'fonctions.php': N25_FONCTIONS, 'accueil.php': N25_ACCUEIL, 'tarifs.php': N25_TARIFS },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '22:40:00', reg: reg(ROUX), visible: true, label: 'L\'accueil : « Total à régler : 79,50 € »', check: stay(1, 78, 1) },
      { id: 'tarifs', who: 'M. Diaz', page: 'tarifs.php', at: THU + '23:30:00', reg: reg({ client: 'M. Diaz', chambre: 3 }), visible: true, label: 'Les tarifs d\'hiver', check: prices({ Simple: 62, Double: 78, Suite: 120 }) },
      { id: 'une', source: true, visible: true, label: 'prix() est dans fonctions.php, et number_format n\'est écrit qu\'une fois dans tout le site', check: function (files, H2) {
        if (!defines('fonctions.php', 'prix')(files, H2)) return 'pas de function prix(…) dans fonctions.php';
        var n = formats(files, H2);
        return n === 1 || 'number_format est écrit ' + n + ' fois';
      } },
      { id: 'bergeron', who: 'Les Bergeron', at: SAT + '23:30:00', reg: reg(BERGERON), label: 'Les Bergeron : plus de 1 000 €', check: stay(7, 160, 2) },
      { id: 'gala', who: 'Un organisateur de gala', page: 'tarifs.php', at: FRI + '20:00:00', reg: reg({ tarifs: { Double: 78, Suite: 1250 }, garde: 'Nora' }), label: 'Un soir de gala : la suite à 1 250 €', check: prices({ Double: 78, Suite: 1250 }) }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'function nuitee($tarif, $nuits = 1)', '{', '    return $tarif * $nuits;', '}', 'echo nuitee(62) . \' / \' . nuitee(62, 3);', '?>'], run: {} } },
    hints: [
      { title: 'Un fichier pour les fonctions', body: '1. Déplace function prix(…) { … } dans fonctions.php.\n2. À sa place, en haut d\'accueil.php :  include \'fonctions.php\';\n3. En tête de tarifs.php :  <?php include \'fonctions.php\'; ?>\n4. Dans tarifs.php, remplace les number_format(…) . \' €\' par prix(…).\n\nUne fonction ne s\'écrit qu\'une fois : deux function prix, et PHP s\'arrête.' },
      { title: 'La solution', body: 'fonctions.php :\n  function prix($montant)\n  {\n      return number_format($montant, 2, \',\', \' \') . \' €\';\n  }\n\naccueil.php :  include \'fonctions.php\';  à la place de la fonction\n\ntarifs.php : <?php include \'fonctions.php\'; ?> en tête, puis\n  echo \'<li>\' . $type . \' : \' . prix($prix) . \'</li>\';\n  <p>Dès <?php echo prix(min($tarifs)); ?> la nuit</p>' }
    ],
    intro: [
      { who: 'sami', expr: 'sourcil', text: 'La page des tarifs a besoin de prix() aussi. Plutôt que la recopier, range-la dans fonctions.php : chaque page l\'inclut avec include.' }
    ],
    done: [
      { who: 'sami', expr: 'sourire', text: 'Tu sais quoi ? Les lignes grises en haut de tes fichiers, c\'est la même chose : l\'hôtel inclut son registre avant ta page.' }
    ]
  });

  ST.add({
    id: 'N26', act: 5, learn: ['formulaires'], notions: ['formulaires', 'tableaux-associatifs', 'conditions'], page: 'reservation.php',
    show: { before: '(le formulaire, sans réponse)', after: 'Roux : chambre 12.', note: 'quand on envoie « Roux » ; « Aucune réservation au nom de Dupont. » sinon' },
    add: { 'reservation.php': RESERVATION },
    ref: { 'reservation.php': N26 },
    cases: [
      { id: 'roux', who: 'Mme Roux tape « Roux »', page: 'reservation.php', at: THU + '19:00:00', get: { nom: 'Roux' }, reg: reg(), visible: true, label: '?nom=Roux : « Roux : chambre 12. »', check: found('Roux', 12) },
      { id: 'vide', who: 'Un visiteur qui arrive sur la page', page: 'reservation.php', at: THU + '19:05:00', reg: reg(), visible: true, label: 'Sans nom envoyé : le formulaire, rien d\'autre', check: nothingYet },
      { id: 'dupont', who: 'Quelqu\'un tape « Dupont »', page: 'reservation.php', at: FRI + '20:00:00', get: { nom: 'Dupont' }, reg: reg({ garde: 'Nora' }), label: '?nom=Dupont : pas de réservation', check: unknown('Dupont') },
      { id: 'okafor', who: 'La famille Okafor', page: 'reservation.php', at: SAT + '17:00:00', get: { nom: 'Okafor' }, reg: reg({ garde: 'Théo' }), label: '?nom=Okafor', check: found('Okafor', 21) }
    ],
    traps: [
      { label: 'la case lue sans vérifier qu\'elle existe', files: { 'reservation.php': N26.replace('if (isset($reservations[$nom])) {', 'if ($reservations[$nom]) {') } }
    ],
    views: { veille: { title: 'reservation.php?chambre=12', lines: ['<?php', 'if (isset($_GET[\'chambre\'])) {', '    echo \'Chambre \' . $_GET[\'chambre\'];', '} else {', '    echo \'Quelle chambre ?\';', '}', '?>'], run: { get: { chambre: '12' } } } },
    hints: [
      { title: 'Ce que porte l\'adresse', body: 'reservation.php?nom=Roux  →  $_GET[\'nom\'] vaut \'Roux\'\nSans ?nom=…, la case n\'existe pas : isset($_GET[\'nom\']) d\'abord.\n\nisset($reservations[$nom])  : ce nom a-t-il une réservation ?\n$reservations[$nom]         : sa chambre.' },
      { title: 'La solution', body: 'if (isset($_GET[\'nom\'])) {\n    $nom = $_GET[\'nom\'];\n    if (isset($reservations[$nom])) {\n        echo \'<p>\' . $nom . \' : chambre \' . $reservations[$nom] . \'.</p>\';\n    } else {\n        echo \'<p>Aucune réservation au nom de \' . $nom . \'.</p>\';\n    }\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Les clients appellent pour savoir quelle chambre ils auront. Sur reservation.php, ils tapent leur nom ; l\'hôtel prépare $reservations, le nom et la chambre.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Trois appels de moins ce soir. Ils ont trouvé tout seuls.' }
    ]
  });

  ST.add({
    id: 'N27', act: 5, learn: ['formulaires'], notions: ['formulaires', 'conditions'], page: 'reservation.php',
    show: { before: 'Aucune réservation au nom de Dupont.  (Dupont en gras)', after: 'Aucune réservation au nom de <b>Dupont</b>.', note: 'ce qu\'a tapé le visiteur, affiché tel quel, sans devenir du HTML' },
    ref: { 'reservation.php': N27 },
    cases: [
      { id: 'html', who: 'Un petit malin tape « <b>Dupont</b> »', page: 'reservation.php', at: FRI + '20:10:00', get: { nom: '<b>Dupont</b>' }, reg: reg({ garde: 'Nora' }), visible: true, label: 'Un nom qui contient du HTML : affiché, pas exécuté', check: unknownSafe('<b>Dupont</b>') },
      { id: 'roux', who: 'Mme Roux tape « Roux »', page: 'reservation.php', at: THU + '19:00:00', get: { nom: 'Roux' }, reg: reg(), visible: true, label: '?nom=Roux : toujours « Roux : chambre 12. »', check: found('Roux', 12) },
      { id: 'italique', who: 'Un autre petit malin', page: 'reservation.php', at: SAT + '23:00:00', get: { nom: '<i>Okafor</i>' }, reg: reg({ garde: 'Théo' }), label: 'Un nom en italique', check: unknownSafe('<i>Okafor</i>') },
      { id: 'dupont', who: 'Quelqu\'un tape « Dupont »', page: 'reservation.php', at: FRI + '20:00:00', get: { nom: 'Dupont' }, reg: reg({ garde: 'Nora' }), label: '?nom=Dupont', check: unknown('Dupont') },
      { id: 'vide', who: 'Un visiteur qui arrive sur la page', page: 'reservation.php', at: SUN + '09:00:00', reg: reg({ garde: 'Théo' }), label: 'Sans nom envoyé', check: nothingYet }
    ],
    views: { veille: { title: 'reservation.php?chambre=<b>12</b>', lines: ['<?php', 'echo \'Chambre \' . htmlspecialchars($_GET[\'chambre\']);', '?>'], run: { get: { chambre: '<b>12</b>' } } } },
    hints: [
      { title: 'Écrire sans exécuter', body: 'htmlspecialchars($nom) transforme < et > en &lt; et &gt; :\nle navigateur les affiche au lieu d\'en faire des balises.\n\nÀ mettre partout où la page écrit $nom.' },
      { title: 'La solution', body: 'echo \'<p>\' . htmlspecialchars($nom) . \' : chambre \' . $reservations[$nom] . \'.</p>\';\n…\necho \'<p>Aucune réservation au nom de \' . htmlspecialchars($nom) . \'.</p>\';' }
    ],
    intro: [
      { who: 'sami', expr: 'sourcil', text: 'Ce qu\'on tape dans le champ arrive tel quel dans ta page. Regarde le visiteur qui a tapé <b>Dupont</b> : son nom sort en gras. Avec un <script>, ce serait bien pire.' }
    ],
    done: [
      { who: 'sami', expr: 'sourire', text: 'Le <b> s\'affiche comme du texte, il ne met plus rien en gras. Tu viens de fermer la première faille de ton site.' }
    ]
  });

  ST.add({
    id: 'N28', act: 5, learn: [], notions: ['fonctions', 'tableaux-associatifs', 'foreach', 'conditions', 'calculs', 'boucle-condition'], page: 'veilleurs.php',
    show: { before: 'Page à construire.', after: 'Sami : 8 nuits · Nora : 12 nuits (de garde) · Théo : 10 nuits · Total : 30 nuits', note: '« 1 nuit » et « 0 nuit » au singulier' },
    add: { 'veilleurs.php': VEILLEURS },
    ref: { 'fonctions.php': N28_FONCTIONS, 'veilleurs.php': N28 },
    cases: [
      { id: 'jeudi', who: 'Nora, jeudi', page: 'veilleurs.php', at: THU + '23:12:00', reg: reg({ veilleurs: { Sami: 8, Nora: 12, 'Théo': 10 }, garde: 'Nora' }), visible: true, label: 'Jeudi : Nora de garde, 30 nuits en tout', check: watchers({ Sami: 8, Nora: 12, 'Théo': 10 }, 'Nora') },
      { id: 'debut', who: 'Sami, début du mois', page: 'veilleurs.php', at: '2026-12-02 22:00:00', reg: reg({ veilleurs: { Sami: 1, Nora: 4 }, garde: 'Sami' }), visible: true, label: 'Début du mois : « 1 nuit », au singulier', check: watchers({ Sami: 1, Nora: 4 }, 'Sami') },
      { id: 'regle', source: true, visible: true, label: 'Le pluriel est écrit une fois, dans nuits(), dans fonctions.php', check: function (files, H2) {
        if (!defines('fonctions.php', 'nuits')(files, H2)) return 'pas de fonction nuits() dans fonctions.php';
        return H2.code(files['veilleurs.php'] || '').indexOf('nuits(') !== -1 || 'veilleurs.php n\'appelle pas nuits()';
      } },
      { id: 'toi', who: 'Toi, ta première nuit de décembre', page: 'veilleurs.php', at: '2026-12-01 23:00:00', reg: reg({ veilleurs: { Toi: 1 }, garde: 'Toi' }), label: 'Le 1er décembre : toi seul, une nuit', check: watchers({ Toi: 1 }, 'Toi') },
      { id: 'zero', who: 'Mireille, en fin de mois', page: 'veilleurs.php', at: '2026-11-30 22:00:00', reg: reg({ veilleurs: { Sami: 0, Nora: 6, 'Théo': 3, Toi: 9 }, garde: 'Toi' }), label: 'Sami en vacances : 0 nuit', check: watchers({ Sami: 0, Nora: 6, 'Théo': 3, Toi: 9 }, 'Toi') },
      { id: 'absent', who: 'Théo, un remplacement', page: 'veilleurs.php', at: SAT + '22:00:00', reg: reg({ veilleurs: { Sami: 2, Nora: 2 }, garde: 'Théo' }), label: 'Un remplaçant de garde, absent de la liste', check: watchers({ Sami: 2, Nora: 2 }, 'Théo') }
    ],
    traps: [
      { label: 'le singulier pour 1 seulement : « 0 nuits »', files: { 'fonctions.php': N28_FONCTIONS.replace('if ($n <= 1) {', 'if ($n == 1) {') } },
      { label: 'le total toujours au pluriel', files: { 'veilleurs.php': N28.replace('<?php echo nuits($total); ?>', '<?php echo $total; ?> nuits') } }
    ],
    hints: [
      { title: 'Ce que tu sais déjà', body: 'fonctions.php : function nuits($n) { … return …; }\nveilleurs.php : include \'fonctions.php\';\n\nforeach ($veilleurs as $nom => $n)  → chaque veilleur et ses nuits\nif ($nom == $garde)                → celui de ce soir\n$total = $total + $n;              → la somme, à chaque tour' },
      { title: 'La solution', body: 'fonctions.php :\n  function nuits($n)\n  {\n      if ($n <= 1) {\n          return $n . \' nuit\';\n      }\n      return $n . \' nuits\';\n  }\n\nveilleurs.php :\n  include \'fonctions.php\';\n  $total = 0;\n  foreach ($veilleurs as $nom => $n) { $total = $total + $n; }\n  …\n  foreach ($veilleurs as $nom => $n) {\n      $ligne = $nom . \' : \' . nuits($n);\n      if ($nom == $garde) { $ligne = $ligne . \' (de garde)\'; }\n      echo \'<li>\' . $ligne . \'</li>\';\n  }\n  <p>Total : <?php echo nuits($total); ?></p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'sourire', text: 'Dernière page à faire : celle des veilleurs du mois. Tout ce que tu as appris y sert. Et ton nom y entre, pour de bon.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Zéro nuit, une nuit, trente nuits : ta page ne se trompe jamais. La loge est à toi.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
