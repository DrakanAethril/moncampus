/* Service de nuit — acte 1, « La loge » (N01-N04), the game's half: the site, the visitors, the walls,
   the references, Sami's cards and the scenes. The right answers of the questions are on the server. */
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
    N01.replace('Ce soir, Sami veille', 'Ce soir, <?php echo $garde; ?> veille')
      .replace('Téléphone : 05 55 98 76 54', 'Téléphone : <?php echo $telephone; ?>')
      .replace('Appelez le 05 55 98 76 54, Sami vous répond', 'Appelez le <?php echo $telephone; ?>, <?php echo $garde; ?> vous répond')
      .replace('12 rue des Vents · 05 55 98 76 54', '12 rue des Vents · <?php echo $telephone; ?>'));

  var N03 = N02
    .replace('$telephone = \'05 55 12 34 56\';', '$telephone = \'05 55 12 34 56\';\n$accueil = \'Bienvenue \' . $client . \', chambre \' . $chambre;')
    .replace('<title>Hôtel des Quatre-Vents</title>', '<title><?php echo $accueil; ?></title>')
    .replace('<h1>Hôtel des Quatre-Vents</h1>', '<h1>Hôtel des Quatre-Vents</h1>\n  <p class="accueil"><?php echo $accueil; ?></p>');

  var N04 = N03
    .replace('$accueil = ', '$taxe = $nuits * $personnes * 1.5;\n$total = $nuits * $tarif + $taxe;\n$accueil = ')
    .replace('<p class="accueil"><?php echo $accueil; ?></p>', '<p class="accueil"><?php echo $accueil; ?></p>\n  <p>Total à régler : <?php echo number_format($total, 2, \',\', \' \'); ?> €</p>');

  var THU = '2026-11-05 ', FRI = '2026-11-06 ', SAT = '2026-11-07 ';
  function heure(at) { return 'Il est ' + at.slice(11, 16); }

  ST.add({
    id: 'N01', act: 1, learn: ['echo', 'date'], notions: ['echo', 'date'],
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
      { who: 'narr', text: 'Jeudi 5 novembre, 22 h. Hôtel des Quatre-Vents, la loge. L\'enseigne grésille au-dessus de la porte.' },
      { who: 'mireille', expr: 'neutre', text: 'Te voilà. Augustin est parti dimanche, sans prévenir, après trente ans de nuits. Il nous laisse son site… et ses carnets.' },
      { who: 'sami', expr: 'sourire', text: 'Moi, c\'est Sami. Je fais les week-ends, et je suis en BTS SIO, en deuxième année. Je t\'aiderai si tu bloques.' },
      { who: 'mireille', expr: 'sourcil', text: 'Premier souci : la page d\'accueil affiche toujours « Il est 23:12 ». Mon neveu a « réparé » quelque chose. Les clients du matin rient.' },
      { who: 'sami', expr: 'neutre', text: 'Augustin voulait qu\'on apprenne en faisant. Essaie d\'abord avec ce que tu sais, et regarde les visiteurs. Quand tu buteras, son carnet aura la page qu\'il faut.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: '8 h 05 pour Mme Roux, 10 h 30 pour Lina. La même page, deux heures différentes.' },
      { who: 'sami', expr: 'sourire', text: 'La page n\'existe pas tant que personne ne la demande. Le serveur la fabrique à chaque visite : c\'est tout le PHP.' },
      { who: 'narr', text: 'Dehors, deux lettres de l\'enseigne s\'allument en grésillant : le Q et le U.' }
    ]
  });

  var numberMeter = function (files, H) { var c = H.code(files['accueil.php']); return H.count(c, '05 55 12 34 56') + H.count(c, '05 55 98 76 54'); };
  ST.add({
    id: 'N02', act: 1, learn: ['variables'], notions: ['variables', 'echo'],
    ref: { 'accueil.php': N02 },
    cost: { label: 'fois où un numéro est écrit dans le code', max: 1, measure: numberMeter },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', reg: reg({ garde: 'Sami' }), visible: true, label: 'Mme Roux : le nouveau numéro partout, Sami de garde', check: function (res, H) {
        if (H.text(res.output).indexOf('98 76 54') !== -1) return 'l\'ancien numéro est encore sur la page';
        if (H.count(H.text(res.output), '05 55 12 34 56') < 3) return 'le nouveau numéro doit apparaître à ses trois places';
        return sees('Ce soir, Sami veille sur vous', 'Appelez le 05 55 12 34 56, Sami vous répond')(res, H);
      } },
      { id: 'lina', who: 'Lina, la livreuse', at: THU + '10:30:00', reg: reg({ garde: 'Sami' }), visible: true, label: 'Lina : l\'heure et le numéro justes', check: sees('Il est 10:30', 'Téléphone : 05 55 12 34 56') },
      { id: 'once', source: true, visible: true, label: 'Le numéro n\'est écrit qu\'une fois dans le code', check: function (files, H) { var n = numberMeter(files, H); return n === 1 || 'un numéro est écrit ' + n + ' fois dans le code'; } },
      { id: 'nora', who: 'Un client de vendredi', at: FRI + '23:40:00', reg: reg({ garde: 'Nora' }), label: 'Vendredi, c\'est Nora qui veille', check: sees('Ce soir, Nora veille sur vous', 'Nora vous répond') },
      { id: 'theo', who: 'Une cliente de samedi', at: SAT + '22:15:00', reg: reg({ garde: 'Théo' }), label: 'Samedi, c\'est Théo qui veille', check: sees('Ce soir, Théo veille sur vous', 'Théo vous répond') }
    ],
    traps: [
      { label: '« Sami » écrit en dur au lieu de $garde', files: { 'accueil.php': N02.split('<?php echo $garde; ?>').join('Sami') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$chambre = 7;', '$chambre = 12;', '?>', '<p>Votre chambre : <?php echo $chambre; ?></p>'], run: {} } },
    hints: [
      { title: 'Une boîte avec une étiquette', body: 'Range le numéro une fois, en haut du fichier :\n\n  <?php\n  $telephone = \'05 55 12 34 56\';\n  ?>\n\npuis, à chaque endroit :  <?php echo $telephone; ?>\nLe veilleur, lui, est déjà dans une variable du registre : $garde.' },
      { title: 'La solution', body: '<?php\n$telephone = \'05 55 12 34 56\';\n?>\n…\n<p>Ce soir, <?php echo $garde; ?> veille sur vous.</p>\n<p>Téléphone : <?php echo $telephone; ?></p>\n<p>Une urgence la nuit ? Appelez le <?php echo $telephone; ?>, <?php echo $garde; ?> vous répond.</p>\n<p class="adresse">12 rue des Vents · <?php echo $telephone; ?></p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'France Télécom… enfin, l\'opérateur change notre ligne demain. Et je ne veux plus que la page parle de Sami les soirs où il n\'est pas là.' },
      { who: 'sami', expr: 'sourire', text: 'Le registre de l\'hôtel est rempli avant chaque page : regarde ce qu\'il contient, sous chaque visiteur. Ce soir, $garde vaut « Sami ». Demain, ce sera Nora.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Nora a vu son nom sur la page en arrivant. Elle a cru que c\'était moi qui l\'avais écrit.' },
      { who: 'sami', expr: 'neutre', text: 'Une variable, c\'est une étiquette sur une boîte. La page lit la boîte au moment de la visite.' }
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
    id: 'N03', act: 1, learn: ['concatenation'], notions: ['concatenation', 'variables'],
    ref: { 'accueil.php': N03 },
    cost: { label: 'fois où « Bienvenue » est écrit dans le code', max: 1, measure: bienvenue },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '22:40:00', reg: reg({ client: 'Mme Roux', chambre: 12 }), visible: true, label: 'Mme Roux : « Bienvenue Mme Roux, chambre 12 », dans l\'onglet et sur la page', check: welcome('Mme Roux', 12) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg({ client: 'M. Diaz', chambre: 3 }), visible: true, label: 'M. Diaz : « Bienvenue M. Diaz, chambre 3 », dans l\'onglet et sur la page', check: welcome('M. Diaz', 3) },
      { id: 'once', source: true, visible: true, label: 'La phrase d\'accueil n\'est écrite qu\'une fois dans le code', check: function (files, H) { var n = bienvenue(files, H); return n === 1 || '« Bienvenue » est écrit ' + n + ' fois dans le code'; } },
      { id: 'okafor', who: 'La famille Okafor', at: FRI + '21:05:00', reg: reg({ client: 'Famille Okafor', chambre: 21, garde: 'Nora' }), label: 'La famille Okafor, chambre 21', check: welcome('Famille Okafor', 21) },
      { id: 'lina', who: 'Lina', at: SAT + '22:30:00', reg: reg({ client: 'Lina', chambre: 5, garde: 'Théo' }), label: 'Lina, chambre 5', check: welcome('Lina', 5) }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$client = \'Lina\';', 'echo \'Bonsoir \' . $client . \' !\';', 'echo \'<br>\';', 'echo \'Bonsoir $client !\';', 'echo \'<br>\';', 'echo "Bonsoir $client !";', '?>'], run: {} } },
    hints: [
      { title: 'Coller des morceaux', body: 'Le point colle deux morceaux bout à bout, sans rien entre eux :\n\n  \'Bienvenue \' . $client      →  Bienvenue Mme Roux\n\nL\'espace fait partie du texte entre guillemets.' },
      { title: 'La solution', body: 'En haut, avec le téléphone :\n  $accueil = \'Bienvenue \' . $client . \', chambre \' . $chambre;\n\nDans l\'onglet :  <title><?php echo $accueil; ?></title>\nSous le titre :  <p class="accueil"><?php echo $accueil; ?></p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'sourire', text: 'Augustin accueillait chaque client par son nom. Je voudrais que la page le fasse aussi : dans l\'onglet, et en haut de la page.' },
      { who: 'sami', expr: 'neutre', text: 'Le registre te donne $client et $chambre. À toi de construire la phrase… une seule fois.' }
    ],
    done: [
      { who: 'mireille', expr: 'surprise', text: 'Il y a une ligne bizarre en bas de la page de M. Diaz, à 23 h 12. Tu l\'as vue ? « Regarde qui arrive à la 12. »' },
      { who: 'sami', expr: 'sourcil', text: 'Elle n\'est pas dans accueil.php. Elle vient d\'ailleurs. Garde ça dans un coin de ta tête.' }
    ]
  });

  var N04_TRAP_TAXE = N04.replace('$taxe = $nuits * $personnes * 1.5;', '$taxe = $personnes * 1.5;');
  var N04_TRAP_PERS = N04.replace('$taxe = $nuits * $personnes * 1.5;', '$taxe = $nuits * 1.5;');
  var N04_TRAP_MILLE = N04.replace('number_format($total, 2, \',\', \' \')', 'number_format($total, 2, \',\')');
  function total(nuits, tarif, personnes) {
    var due = nuits * tarif + nuits * personnes * 1.5;
    return function (res, H) { return sees('Total à régler : ' + H.euros(due))(res, H); };
  }
  ST.add({
    id: 'N04', act: 1, learn: ['calculs'], notions: ['calculs', 'concatenation', 'variables'],
    ref: { 'accueil.php': N04 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '22:40:00', reg: reg({ client: 'Mme Roux', chambre: 12, nuits: 1, tarif: 78, personnes: 1 }), visible: true, label: 'Mme Roux, 1 nuit à 78 €, seule : 79,50 €', check: total(1, 78, 1) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg({ client: 'M. Diaz', chambre: 3, nuits: 1, tarif: 62, personnes: 1 }), visible: true, label: 'M. Diaz, 1 nuit à 62 €, seul : 63,50 €', check: total(1, 62, 1) },
      { id: 'okafor', who: 'La famille Okafor', at: FRI + '21:05:00', reg: reg({ client: 'Famille Okafor', chambre: 21, nuits: 4, tarif: 105, personnes: 4, garde: 'Nora' }), label: 'La famille Okafor : 4 nuits à 105 €, à 4', check: total(4, 105, 4) },
      { id: 'lina', who: 'Lina', at: SAT + '22:30:00', reg: reg({ client: 'Lina', chambre: 5, nuits: 2, tarif: 62, personnes: 1, garde: 'Théo' }), label: 'Lina : 2 nuits à 62 €', check: total(2, 62, 1) },
      { id: 'bergeron', who: 'M. et Mme Bergeron', at: SAT + '23:30:00', reg: reg({ client: 'M. et Mme Bergeron', chambre: 30, nuits: 7, tarif: 160, personnes: 2, garde: 'Théo' }), label: 'Les Bergeron : une semaine dans la suite, plus de 1 000 €', check: total(7, 160, 2) }
    ],
    traps: [
      { label: 'la taxe comptée une seule nuit', files: { 'accueil.php': N04_TRAP_TAXE } },
      { label: 'la taxe comptée pour une seule personne', files: { 'accueil.php': N04_TRAP_PERS } },
      { label: 'number_format sans l\'espace des milliers', files: { 'accueil.php': N04_TRAP_MILLE } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$nuits = 2;', '$tarif = 62;', 'echo $nuits * $tarif + 10;', 'echo \' / \';', 'echo $nuits * ($tarif + 10);', '?>'], run: {} } },
    hints: [
      { title: 'Calculer, puis écrire', body: 'Calcule d\'abord, en haut du fichier :\n  $taxe = … ;\n  $total = … ;\n\nPuis écris le total à la française :\n  number_format($total, 2, \',\', \' \')  →  1 141,00\n(2 décimales, la virgule, l\'espace des milliers)' },
      { title: 'La solution', body: '$taxe = $nuits * $personnes * 1.5;\n$total = $nuits * $tarif + $taxe;\n…\n<p>Total à régler : <?php echo number_format($total, 2, \',\', \' \'); ?> €</p>' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Je calcule chaque séjour à la calculette, taxe de séjour comprise. Et je me trompe une fois sur dix.' },
      { who: 'sami', expr: 'neutre', text: 'PHP calcule avec + - * /. Le seul piège : il écrit 79.5, pas 79,50 €.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Mille cent quarante et un euros pour les Bergeron. Au centime près.' },
      { who: 'sami', expr: 'sourcil', text: 'Et la ligne de 23 h 12, elle est revenue. Ce n\'est pas ta page : quelque chose est ajouté en bas de toutes les pages de l\'hôtel.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
