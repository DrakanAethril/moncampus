/* Service de nuit — acte 1, « La loge » (N01-N06), the game's half: the site, the visitors, the references,
   Sami's cards and the scenes. One notion a night. The right answers of the questions are on the server. */
(function (root) {
  'use strict';
  var ST = root.SDNSteps, sees = ST.sees, reg = ST.reg;
  var L = function () { return Array.prototype.slice.call(arguments).join('\n'); };

  // Augustin's page as the nephew left it: the hour typed by hand, the old number, Sami everywhere.
  var BASE = L(
    '<!DOCTYPE html>',
    '<html lang="fr">',
    '<head>',
    '  <meta charset="utf-8">',
    '  <title>Hôtel des Quatre-Vents</title>',
    '</head>',
    '<body>',
    '  <h1>Hôtel des Quatre-Vents</h1>',
    '  <p>Réception ouverte jour et nuit.</p>',
    '  <p>Il est 23:12.</p>',
    '  <p>Ce soir, Sami veille sur vous.</p>',
    '  <h2>Nous joindre</h2>',
    '  <p>Téléphone : 05 55 98 76 54</p>',
    '  <p>Une urgence la nuit ? Appelez le 05 55 98 76 54, Sami vous répond.</p>',
    '  <p class="adresse">12 rue des Vents · 05 55 98 76 54</p>',
    '</body>',
    '</html>',
    '');

  var N01 = BASE.replace('<p>Il est 23:12.</p>', '<p>Il est <?php echo date(\'H:i\'); ?>.</p>');

  var N02 = L(
    '<?php',
    '$telephone = \'05 55 12 34 56\';',
    '?>',
    N01.replace('Téléphone : 05 55 98 76 54', 'Téléphone : <?php echo $telephone; ?>')
      .replace('Appelez le 05 55 98 76 54, Sami', 'Appelez le <?php echo $telephone; ?>, Sami')
      .replace('12 rue des Vents · 05 55 98 76 54', '12 rue des Vents · <?php echo $telephone; ?>'));

  var N03 = N02
    .replace('Ce soir, Sami veille', 'Ce soir, <?php echo $garde; ?> veille')
    .replace('<?php echo $telephone; ?>, Sami vous répond', '<?php echo $telephone; ?>, <?php echo $garde; ?> vous répond');

  var N04 = N03
    .replace('$telephone = \'05 55 12 34 56\';', '$telephone = \'05 55 12 34 56\';\n$accueil = \'Bienvenue \' . $client . \', chambre \' . $chambre;')
    .replace('<title>Hôtel des Quatre-Vents</title>', '<title><?php echo $accueil; ?></title>')
    .replace('<h1>Hôtel des Quatre-Vents</h1>', '<h1>Hôtel des Quatre-Vents</h1>\n  <p class="accueil"><?php echo $accueil; ?></p>');

  var N05 = N04
    .replace('$accueil = ', '$taxe = $nuits * $personnes * 1.5;\n$total = $nuits * $tarif + $taxe;\n$accueil = ')
    .replace('<p class="accueil"><?php echo $accueil; ?></p>', '<p class="accueil"><?php echo $accueil; ?></p>\n  <p>Total à régler : <?php echo $total; ?> €</p>');

  var N06 = N05.replace('<?php echo $total; ?> €', '<?php echo number_format($total, 2, \',\', \' \'); ?> €');

  var THU = '2026-11-05 ', FRI = '2026-11-06 ', SAT = '2026-11-07 ', SUN = '2026-11-08 ';
  function heure(at) { return 'Il est ' + at.slice(11, 16); }

  ST.add({
    id: 'N01', act: 1, learn: ['echo', 'date'], notions: ['echo', 'date'],
    show: { before: 'Il est 23:12.', after: 'Il est 08:05.', note: 'pour Mme Roux, venue à 8 h 05' },
    add: { 'accueil.php': BASE },
    ref: { 'accueil.php': N01 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', visible: true, label: 'Mme Roux, 8 h 05 : la page donne l\'heure de sa visite', check: sees(heure(THU + '08:05')) },
      { id: 'lina', who: 'Lina, la livreuse', at: THU + '10:30:00', visible: true, label: 'Lina, 10 h 30 : la page donne l\'heure de sa visite', check: sees(heure(THU + '10:30')) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', label: 'M. Diaz, 23 h 12', check: sees(heure(THU + '23:12')) },
      { id: 'minuit', who: 'Un client après minuit', at: FRI + '00:07:00', label: 'Un client à 0 h 07', check: sees(heure(FRI + '00:07')) },
      { id: 'aprem', who: 'Une cliente de l\'après-midi', at: FRI + '13:45:00', label: 'Une cliente à 13 h 45', check: sees(heure(FRI + '13:45')) }
    ],
    traps: [
      { label: 'date(\'h:i\') : l\'heure sur 12 heures', files: { 'accueil.php': N01.replace('date(\'H:i\')', 'date(\'h:i\')') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<p>Bonsoir <?php echo \'Mme Roux\'; ?> !</p>'], run: {} } },
    hints: [
      { title: 'Ouvrir le PHP dans le HTML', body: 'Au milieu du HTML, le PHP s\'écrit entre <?php et ?>.\nCe qu\'il écrit avec echo prend sa place dans la page :\n\n  <p>Il est <?php echo \'…\'; ?>.</p>' },
      { title: 'La solution', body: '<p>Il est <?php echo date(\'H:i\'); ?>.</p>\n\nH : l\'heure sur 24 h, avec un zéro devant (08)\ni : les minutes, avec un zéro devant (05)' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Bienvenue à la loge. Le site de l\'hôtel affiche toujours « Il est 23:12 », même à 8 h du matin. Ta première nuit : l\'heure juste.' },
      { who: 'sami', expr: 'sourire', text: 'Moi, c\'est Sami. Le carnet d\'Augustin, l\'ancien veilleur, va s\'ouvrir à la bonne page : lis-la, puis écris. Je t\'aide si tu bloques.' }
    ],
    done: [
      { who: 'sami', expr: 'sourire', text: 'La page n\'existe pas tant que personne ne la demande : le serveur la fabrique à chaque visite. C\'est tout le PHP.' }
    ]
  });

  var numberMeter = function (files, H) { var c = H.code(files['accueil.php']); return H.count(c, '05 55 12 34 56') + H.count(c, '05 55 98 76 54'); };
  function phone(res, H) {
    if (H.text(res.output).indexOf('98 76 54') !== -1) return 'l\'ancien numéro est encore sur la page';
    if (H.count(H.text(res.output), '05 55 12 34 56') < 3) return 'le nouveau numéro doit apparaître à ses trois places';
    return true;
  }
  ST.add({
    id: 'N02', act: 1, learn: ['variables'], notions: ['variables', 'echo'],
    show: { before: 'Téléphone : 05 55 98 76 54', after: 'Téléphone : 05 55 12 34 56', note: 'aux trois endroits de la page, le numéro écrit une seule fois dans le code' },
    ref: { 'accueil.php': N02 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', visible: true, label: 'Le nouveau numéro, à ses trois places', check: phone },
      { id: 'once', source: true, visible: true, label: 'Le numéro n\'est écrit qu\'une fois dans le code', check: function (files, H) { var n = numberMeter(files, H); return n === 1 || 'un numéro est écrit ' + n + ' fois dans le code'; } },
      { id: 'nora', who: 'Un client de vendredi', at: FRI + '23:40:00', label: 'Vendredi soir, le numéro juste', check: phone },
      { id: 'theo', who: 'Une cliente de samedi', at: SAT + '22:15:00', label: 'Samedi soir, le numéro juste', check: phone }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$chambre = 7;', '$chambre = 12;', '?>', '<p>Votre chambre : <?php echo $chambre; ?></p>'], run: {} } },
    hints: [
      { title: 'Une boîte avec une étiquette', body: 'Range le numéro une fois, tout en haut du fichier :\n\n  <?php\n  $telephone = \'05 55 12 34 56\';\n  ?>\n\npuis, à chaque endroit :  <?php echo $telephone; ?>' },
      { title: 'La solution', body: '<?php\n$telephone = \'05 55 12 34 56\';\n?>\n…\n<p>Téléphone : <?php echo $telephone; ?></p>\n<p>Une urgence la nuit ? Appelez le <?php echo $telephone; ?>, Sami vous répond.</p>\n<p class="adresse">12 rue des Vents · <?php echo $telephone; ?></p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'L\'opérateur change notre ligne demain, et le numéro est écrit trois fois sur la page. Je ne veux plus jamais le chercher partout.' }
    ],
    done: [
      { who: 'sami', expr: 'sourire', text: 'Le jour où le numéro change encore, tu modifies une ligne, et toute la page suit.' }
    ]
  });

  function watcher(name) { return sees('Ce soir, ' + name + ' veille sur vous', name + ' vous répond'); }
  ST.add({
    id: 'N03', act: 1, learn: ['variables'], notions: ['variables', 'echo'],
    show: { before: 'Ce soir, Sami veille sur vous.', after: 'Ce soir, Nora veille sur vous.', note: 'le soir où Nora est de garde' },
    ref: { 'accueil.php': N03 },
    cases: [
      { id: 'roux', who: 'Mme Roux, jeudi', at: THU + '22:40:00', reg: reg({ garde: 'Sami' }), visible: true, label: 'Jeudi, Sami est de garde : « Sami » aux deux endroits', check: watcher('Sami') },
      { id: 'nora', who: 'M. Diaz, vendredi', at: FRI + '23:40:00', reg: reg({ garde: 'Nora' }), visible: true, label: 'Vendredi, Nora est de garde : « Nora » aux deux endroits', check: watcher('Nora') },
      { id: 'theo', who: 'Une cliente de samedi', at: SAT + '22:15:00', reg: reg({ garde: 'Théo' }), label: 'Samedi, Théo est de garde', check: watcher('Théo') },
      { id: 'ines', who: 'Un client de dimanche', at: SUN + '01:30:00', reg: reg({ garde: 'Inès' }), label: 'Dimanche, Inès remplace Théo', check: watcher('Inès') }
    ],
    views: { veille: { title: 'veille.php', lines: ['<p>Ce soir, <?php echo $garde; ?> veille.</p>'], run: { reg: { garde: 'Nora' } } } },
    hints: [
      { title: 'La variable est déjà là', body: 'Regarde les lignes grises en haut du fichier :\nl\'hôtel y range le veilleur du soir dans $garde.\n\nPour l\'écrire dans la page :  <?php echo $garde; ?>' },
      { title: 'La solution', body: '<p>Ce soir, <?php echo $garde; ?> veille sur vous.</p>\n…\n<p>Une urgence la nuit ? Appelez le <?php echo $telephone; ?>, <?php echo $garde; ?> vous répond.</p>' }
    ],
    intro: [
      { who: 'sami', expr: 'neutre', text: 'La page dit « Sami » même les soirs où je ne suis pas là. Regarde en haut du fichier : l\'hôtel y prépare $garde, le veilleur du soir. Change de visiteur, la valeur change.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Nora a vu son nom sur la page en arrivant. Elle a cru que c\'était moi qui l\'avais écrit.' }
    ]
  });

  var bienvenue = function (files, H) { return H.count(H.code(files['accueil.php']), 'Bienvenue'); };
  function welcome(client, chambre) {
    var phrase = 'Bienvenue ' + client + ', chambre ' + chambre;
    return function (res, H) {
      if (H.title(res.output).indexOf(phrase) === -1) return 'l\'onglet du navigateur n\'affiche pas « ' + phrase + ' »';
      return sees(phrase)(res, H);
    };
  }
  ST.add({
    id: 'N04', act: 1, learn: ['concatenation'], notions: ['concatenation', 'variables'],
    show: { before: 'Hôtel des Quatre-Vents', after: 'Bienvenue Mme Roux, chambre 12', note: 'dans l\'onglet du navigateur, et sous le titre de la page' },
    ref: { 'accueil.php': N04 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '22:40:00', reg: reg({ client: 'Mme Roux', chambre: 12 }), visible: true, label: 'Mme Roux : « Bienvenue Mme Roux, chambre 12 », dans l\'onglet et sur la page', check: welcome('Mme Roux', 12) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg({ client: 'M. Diaz', chambre: 3 }), visible: true, label: 'M. Diaz : « Bienvenue M. Diaz, chambre 3 », dans l\'onglet et sur la page', check: welcome('M. Diaz', 3) },
      { id: 'once', source: true, visible: true, label: 'La phrase d\'accueil n\'est écrite qu\'une fois dans le code', check: function (files, H) { var n = bienvenue(files, H); return n === 1 || '« Bienvenue » est écrit ' + n + ' fois dans le code'; } },
      { id: 'okafor', who: 'La famille Okafor', at: FRI + '21:05:00', reg: reg({ client: 'Famille Okafor', chambre: 21, garde: 'Nora' }), label: 'La famille Okafor, chambre 21', check: welcome('Famille Okafor', 21) },
      { id: 'lina', who: 'Lina', at: SAT + '22:30:00', reg: reg({ client: 'Lina', chambre: 5, garde: 'Théo' }), label: 'Lina, chambre 5', check: welcome('Lina', 5) }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$client = \'Lina\';', 'echo \'Bonsoir \' . $client . \' !\';', 'echo \'<br>\';', 'echo \'Bonsoir $client !\';', 'echo \'<br>\';', 'echo "Bonsoir $client !";', '?>'], run: {} } },
    hints: [
      { title: 'Coller des morceaux', body: 'Le point colle deux morceaux bout à bout, sans rien entre eux :\n\n  \'Bienvenue \' . $client      →  Bienvenue Mme Roux\n\nL\'espace fait partie du texte entre guillemets.\nRange la phrase dans une variable, puis affiche-la deux fois.' },
      { title: 'La solution', body: 'En haut, avec le téléphone :\n  $accueil = \'Bienvenue \' . $client . \', chambre \' . $chambre;\n\nDans l\'onglet :  <title><?php echo $accueil; ?></title>\nSous le titre :  <p class="accueil"><?php echo $accueil; ?></p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'sourire', text: 'Augustin accueillait chaque client par son nom. L\'hôtel prépare $client et $chambre : je voudrais la phrase dans l\'onglet et en haut de la page.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Des morceaux de phrase et des valeurs de l\'hôtel, collés au moment de la visite. C\'est malin.' }
    ]
  });

  var N05_TRAP_TAXE = N05.replace('$taxe = $nuits * $personnes * 1.5;', '$taxe = $personnes * 1.5;');
  var N05_TRAP_PERS = N05.replace('$taxe = $nuits * $personnes * 1.5;', '$taxe = $nuits * 1.5;');
  function due(nuits, tarif, personnes) { return nuits * tarif + nuits * personnes * 1.5; }
  function raw(nuits, tarif, personnes) { return sees('Total à régler : ' + String(due(nuits, tarif, personnes)) + ' €'); }
  var ROUX = { client: 'Mme Roux', chambre: 12, nuits: 1, tarif: 78, personnes: 1 }, DIAZ = { client: 'M. Diaz', chambre: 3, nuits: 1, tarif: 62, personnes: 1 };
  var OKAFOR = { client: 'Famille Okafor', chambre: 21, nuits: 4, tarif: 105, personnes: 4, garde: 'Nora' }, LINA = { client: 'Lina', chambre: 5, nuits: 2, tarif: 62, personnes: 1, garde: 'Théo' };
  var BERGERON = { client: 'M. et Mme Bergeron', chambre: 30, nuits: 7, tarif: 160, personnes: 2, garde: 'Théo' };
  ST.add({
    id: 'N05', act: 1, learn: ['calculs'], notions: ['calculs', 'variables'],
    show: { before: '(rien)', after: 'Total à régler : 79.5 €', note: 'Mme Roux : 1 nuit à 78 €, seule, taxe comprise' },
    ref: { 'accueil.php': N05 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '22:40:00', reg: reg(ROUX), visible: true, label: 'Mme Roux, 1 nuit à 78 €, seule : 79.5 €', check: raw(1, 78, 1) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg(DIAZ), visible: true, label: 'M. Diaz, 1 nuit à 62 €, seul : 63.5 €', check: raw(1, 62, 1) },
      { id: 'okafor', who: 'La famille Okafor', at: FRI + '21:05:00', reg: reg(OKAFOR), label: 'La famille Okafor : 4 nuits à 105 €, à 4', check: raw(4, 105, 4) },
      { id: 'lina', who: 'Lina', at: SAT + '22:30:00', reg: reg(LINA), label: 'Lina : 2 nuits à 62 €', check: raw(2, 62, 1) },
      { id: 'bergeron', who: 'M. et Mme Bergeron', at: SAT + '23:30:00', reg: reg(BERGERON), label: 'Les Bergeron : une semaine dans la suite', check: raw(7, 160, 2) }
    ],
    traps: [
      { label: 'la taxe comptée une seule nuit', files: { 'accueil.php': N05_TRAP_TAXE } },
      { label: 'la taxe comptée pour une seule personne', files: { 'accueil.php': N05_TRAP_PERS } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$nuits = 2;', '$tarif = 62;', 'echo $nuits * $tarif + 10;', 'echo \' / \';', 'echo $nuits * ($tarif + 10);', '?>'], run: {} } },
    hints: [
      { title: 'Calculer en haut, écrire en bas', body: 'En haut du fichier, avec les autres variables :\n  $taxe = … ;     (1.5 par nuit et par personne)\n  $total = … ;    (les nuits, plus la taxe)\n\nPuis dans la page :\n  <p>Total à régler : <?php echo $total; ?> €</p>\n\nEn PHP, la virgule des décimales s\'écrit avec un point : 1.5' },
      { title: 'La solution', body: '$taxe = $nuits * $personnes * 1.5;\n$total = $nuits * $tarif + $taxe;\n…\n<p>Total à régler : <?php echo $total; ?> €</p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Je calcule chaque séjour à la calculette, et je me trompe une fois sur dix. Le nombre suffit ce soir ; la belle écriture, ce sera demain.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Mille cent quarante et un euros pour les Bergeron. Juste. Même si « 79.5 € », ça fait un peu calculette.' }
    ]
  });

  var N06_TRAP_MILLE = N06.replace('number_format($total, 2, \',\', \' \')', 'number_format($total, 2, \',\')');
  function euros(nuits, tarif, personnes) { return function (res, H) { return sees('Total à régler : ' + H.euros(due(nuits, tarif, personnes)))(res, H); }; }
  ST.add({
    id: 'N06', act: 1, learn: ['calculs'], notions: ['calculs'],
    show: { before: 'Total à régler : 79.5 €', after: 'Total à régler : 79,50 €', note: 'et 1 141,00 € pour une semaine dans la suite' },
    ref: { 'accueil.php': N06 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '22:40:00', reg: reg(ROUX), visible: true, label: 'Mme Roux : 79,50 €', check: euros(1, 78, 1) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg(DIAZ), visible: true, label: 'M. Diaz : 63,50 €', check: euros(1, 62, 1) },
      { id: 'okafor', who: 'La famille Okafor', at: FRI + '21:05:00', reg: reg(OKAFOR), label: 'La famille Okafor : un compte rond', check: euros(4, 105, 4) },
      { id: 'bergeron', who: 'M. et Mme Bergeron', at: SAT + '23:30:00', reg: reg(BERGERON), label: 'Les Bergeron : plus de 1 000 €', check: euros(7, 160, 2) }
    ],
    traps: [
      { label: 'number_format sans l\'espace des milliers', files: { 'accueil.php': N06_TRAP_MILLE } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', 'echo number_format(1141, 2, \',\', \' \');', 'echo \' / \';', 'echo number_format(7.5, 2, \',\', \' \');', '?>'], run: {} } },
    hints: [
      { title: 'Écrire un prix à la française', body: 'number_format(nombre, décimales, virgule, milliers)\n\n  number_format(1141, 2, \',\', \' \')  →  1 141,00\n\nÀ mettre autour de $total, là où la page l\'écrit.' },
      { title: 'La solution', body: '<p>Total à régler : <?php echo number_format($total, 2, \',\', \' \'); ?> €</p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: '« 79.5 € » : un point, et un seul chiffre après. Sur une facture, on écrit 79,50 €.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: '1 141,00 €. Au centime près, et ça se lit.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
