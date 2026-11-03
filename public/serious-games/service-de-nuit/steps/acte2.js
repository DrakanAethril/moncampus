/* Service de nuit — acte 2, « L'horloge » (N05-N07), the game's half. */
(function (root) {
  'use strict';
  var ST = root.SDNSteps, sees = ST.sees, reg = ST.reg;
  var L = function () { return Array.prototype.slice.call(arguments).join('\n'); };

  var N04 = ST.refOf('N04')['accueil.php'];
  var SALUT_BIENVENUE = '$accueil = \'Bienvenue \' . $client . \', chambre \' . $chambre;';

  var N05 = N04.replace(SALUT_BIENVENUE, L(
    'if (date(\'G\') < 18) {',
    '    $salut = \'Bonjour\';',
    '} else {',
    '    $salut = \'Bonsoir\';',
    '}',
    '$accueil = $salut . \' \' . $client . \', chambre \' . $chambre;'));

  var PETIT_DEJ = L(
    'if ($heure < 7) {',
    '    $petitDej = \'Le petit-déjeuner ouvre à 7 h.\';',
    '} elseif ($heure < 10) {',
    '    $petitDej = \'Le petit-déjeuner est servi jusqu\\\'à 10 h.\';',
    '} else {',
    '    $petitDej = \'Le petit-déjeuner est servi demain dès 7 h.\';',
    '}');
  var N06 = N05
    .replace('if (date(\'G\') < 18) {', '$heure = (int) date(\'G\');\nif ($heure < 18) {')
    .replace('$accueil = $salut', PETIT_DEJ + '\n$accueil = $salut')
    .replace('<p class="accueil"><?php echo $accueil; ?></p>', '<p class="accueil"><?php echo $accueil; ?></p>\n  <p class="petit-dej"><?php echo $petitDej; ?></p>');

  var N07 = N06
    .replace('$heure = (int) date(\'G\');\nif ($heure < 18) {', L(
      '$heure = (int) date(\'G\');',
      '$jour = (int) date(\'N\');',
      'if ($heure >= 23 || $heure < 5) {',
      '    $salut = \'Bonne nuit\';',
      '} elseif ($heure < 18) {'))
    .replace('} elseif ($heure < 10) {', L(
      '} elseif ($jour >= 6 && $heure < 11) {',
      '    $petitDej = \'Le petit-déjeuner est servi jusqu\\\'à 11 h.\';',
      '} elseif ($heure < 10) {'));

  var THU = '2026-11-05 ', FRI = '2026-11-06 ', SAT = '2026-11-07 ', SUN = '2026-11-08 ';
  var ROUX = { client: 'Mme Roux', chambre: 12 }, DIAZ = { client: 'M. Diaz', chambre: 3 }, OKAFOR = { client: 'Famille Okafor', chambre: 21, nuits: 4, tarif: 105, personnes: 4 }, LINA = { client: 'Lina', chambre: 5, nuits: 2, tarif: 62 }, BERGERON = { client: 'M. et Mme Bergeron', chambre: 30, nuits: 7, tarif: 160, personnes: 2 };
  function salut(mot, who) { return mot + ' ' + who.client + ', chambre ' + who.chambre; }
  var OUVRE = 'Le petit-déjeuner ouvre à 7 h.', DIX = 'Le petit-déjeuner est servi jusqu\'à 10 h.', ONZE = 'Le petit-déjeuner est servi jusqu\'à 11 h.', DEMAIN = 'Le petit-déjeuner est servi demain dès 7 h.';

  ST.add({
    id: 'N05', act: 2, learn: ['conditions'], notions: ['conditions', 'date', 'concatenation'],
    ref: { 'accueil.php': N05 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', reg: reg(ROUX), visible: true, label: 'Mme Roux, 8 h 05 : « Bonjour »', check: sees(salut('Bonjour', ROUX)) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg(DIAZ), visible: true, label: 'M. Diaz, 23 h 12 : « Bonsoir »', check: sees(salut('Bonsoir', DIAZ)) },
      { id: 'okafor', who: 'La famille Okafor', at: FRI + '18:30:00', reg: reg(OKAFOR), label: 'La famille Okafor, à 18 h 30', check: sees(salut('Bonsoir', OKAFOR)) },
      { id: 'lina', who: 'Lina', at: SAT + '18:00:00', reg: reg(LINA), label: 'Lina, à 18 h pile', check: sees(salut('Bonsoir', LINA)) },
      { id: 'bergeron', who: 'Les Bergeron', at: SAT + '17:59:00', reg: reg(BERGERON), label: 'Les Bergeron, à 17 h 59', check: sees(salut('Bonjour', BERGERON)) }
    ],
    traps: [
      { label: '<= 18 : « Bonjour » jusqu\'à 18 h 59', files: { 'accueil.php': N05.replace('date(\'G\') < 18', 'date(\'G\') <= 18') } },
      { label: '> 18 pour « Bonsoir » : 18 h pile oubliée', files: { 'accueil.php': N05.replace('if (date(\'G\') < 18) {\n    $salut = \'Bonjour\';\n} else {\n    $salut = \'Bonsoir\';\n}', 'if (date(\'G\') > 18) {\n    $salut = \'Bonsoir\';\n} else {\n    $salut = \'Bonjour\';\n}') } },
      { label: '< 17 : « Bonsoir » dès 17 h', files: { 'accueil.php': N05.replace('date(\'G\') < 18', 'date(\'G\') < 17') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$nuits = 1;', 'if ($nuits > 1) {', '    echo \'Bon séjour parmi nous.\';', '} else {', '    echo \'Bonne nuit parmi nous.\';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Si… sinon…', body: 'if (condition) {\n    // ce qui se passe quand c\'est vrai\n} else {\n    // sinon\n}\n\ndate(\'G\') donne l\'heure, de 0 à 23, sans zéro devant.' },
      { title: 'La solution', body: 'if (date(\'G\') < 18) {\n    $salut = \'Bonjour\';\n} else {\n    $salut = \'Bonsoir\';\n}\n$accueil = $salut . \' \' . $client . \', chambre \' . $chambre;' }
    ],
    intro: [
      { who: 'narr', text: 'Deuxième semaine. Mireille a imprimé la page d\'accueil et l\'a punaisée derrière le comptoir.' },
      { who: 'mireille', expr: 'sourcil', text: '« Bienvenue », à minuit, à des gens qui rentrent du restaurant… Augustin disait bonjour et bonsoir. Il savait l\'heure, lui.' },
      { who: 'sami', expr: 'neutre', text: 'Ta page aussi la sait : date(\'G\'). Il reste à lui faire choisir entre deux mots.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'À 18 h pile, « Bonsoir ». C\'est exactement ce que faisait Augustin.' }
    ]
  });

  ST.add({
    id: 'N06', act: 2, learn: ['conditions-multiples'], notions: ['conditions-multiples', 'conditions', 'date'],
    ref: { 'accueil.php': N06 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', reg: reg(ROUX), visible: true, label: 'Mme Roux, 8 h 05 : servi jusqu\'à 10 h', check: sees(DIX) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg(DIAZ), visible: true, label: 'M. Diaz, 23 h 12 : servi demain dès 7 h', check: sees(DEMAIN) },
      { id: 'tot', who: 'Un client matinal', at: FRI + '06:30:00', reg: reg(OKAFOR), label: 'Un client à 6 h 30', check: sees(OUVRE) },
      { id: 'dix', who: 'Une cliente en retard', at: FRI + '10:15:00', reg: reg(LINA), label: 'Une cliente à 10 h 15', check: sees(DEMAIN) },
      { id: 'sept', who: 'Les Bergeron', at: SAT + '07:00:00', reg: reg(BERGERON), label: 'Les Bergeron, à 7 h pile', check: sees(DIX) }
    ],
    traps: [
      { label: 'les conditions dans le désordre : « < 10 » avant « < 7 »', files: { 'accueil.php': N06.replace(PETIT_DEJ, L('if ($heure < 10) {', '    $petitDej = \'Le petit-déjeuner est servi jusqu\\\'à 10 h.\';', '} elseif ($heure < 7) {', '    $petitDej = \'Le petit-déjeuner ouvre à 7 h.\';', '} else {', '    $petitDej = \'Le petit-déjeuner est servi demain dès 7 h.\';', '}')) } },
      { label: '<= 10 : servi jusqu\'à 10 h 59', files: { 'accueil.php': N06.replace('} elseif ($heure < 10) {', '} elseif ($heure <= 10) {') } },
      { label: '<= 7 : fermé jusqu\'à 7 h 59', files: { 'accueil.php': N06.replace('if ($heure < 7) {', 'if ($heure <= 7) {') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$heure = 14;', 'if ($heure < 12) {', '    echo \'matin\';', '} elseif ($heure < 18) {', '    echo \'après-midi\';', '} elseif ($heure < 16) {', '    echo \'goûter\';', '} else {', '    echo \'soir\';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Trois cas', body: 'if (…) {\n    …\n} elseif (…) {\n    …\n} else {\n    …\n}\n\nPHP teste de haut en bas et s\'arrête à la première condition vraie.\nL\'apostrophe de « jusqu\'à » : écris jusqu\\\'à entre guillemets simples.' },
      { title: 'La solution', body: '$heure = (int) date(\'G\');\n…\nif ($heure < 7) {\n    $petitDej = \'Le petit-déjeuner ouvre à 7 h.\';\n} elseif ($heure < 10) {\n    $petitDej = \'Le petit-déjeuner est servi jusqu\\\'à 10 h.\';\n} else {\n    $petitDej = \'Le petit-déjeuner est servi demain dès 7 h.\';\n}\n…\n<p class="petit-dej"><?php echo $petitDej; ?></p>' }
    ],
    intro: [
      { who: 'josette', expr: 'sourire', text: 'On ne s\'est pas présentés : Josette, trente ans de croissants. Augustin me gardait toujours le dernier pain au chocolat.' },
      { who: 'josette', expr: 'neutre', text: 'Trois phrases, selon l\'heure. Et je tiens à mon apostrophe dans « jusqu\'à ».' },
      { who: 'sami', expr: 'neutre', text: 'Trois cas, ça se fait avec elseif. Fais attention à l\'ordre : regarde le client de 6 h 30.' }
    ],
    done: [
      { who: 'josette', expr: 'sourire', text: 'Plus personne ne vient me demander à 10 h 15 s\'il reste du café. Enfin, presque plus.' }
    ]
  });

  ST.add({
    id: 'N07', act: 2, learn: ['operateurs-logiques'], notions: ['operateurs-logiques', 'conditions-multiples', 'date'],
    ref: { 'accueil.php': N07 },
    cases: [
      { id: 'samedi', who: 'Lina, samedi', at: SAT + '10:30:00', reg: reg(LINA), visible: true, label: 'Samedi 10 h 30 : « Bonjour » et servi jusqu\'à 11 h', check: sees(salut('Bonjour', LINA), ONZE) },
      { id: 'diaz', who: 'M. Diaz, jeudi', at: THU + '23:12:00', reg: reg(DIAZ), visible: true, label: 'Jeudi 23 h 12 : « Bonne nuit »', check: sees(salut('Bonne nuit', DIAZ), DEMAIN) },
      { id: 'dimanche', who: 'Les Bergeron, dimanche', at: SUN + '10:40:00', reg: reg(BERGERON), label: 'Dimanche 10 h 40', check: sees(salut('Bonjour', BERGERON), ONZE) },
      { id: 'vendredi', who: 'Mme Roux, vendredi', at: FRI + '10:40:00', reg: reg(ROUX), label: 'Vendredi 10 h 40', check: sees(salut('Bonjour', ROUX), DEMAIN) },
      { id: 'cinq', who: 'La famille Okafor, au retour du train de nuit', at: FRI + '04:59:00', reg: reg(OKAFOR), label: 'Vendredi 4 h 59', check: sees(salut('Bonne nuit', OKAFOR), OUVRE) },
      { id: 'cinqpile', who: 'Un client à 5 h', at: FRI + '05:00:00', reg: reg(DIAZ), label: 'Vendredi 5 h pile', check: sees(salut('Bonjour', DIAZ), OUVRE) },
      { id: 'vingtdeux', who: 'Une cliente à 22 h 59', at: THU + '22:59:00', reg: reg(LINA), label: 'Jeudi 22 h 59', check: sees(salut('Bonsoir', LINA), DEMAIN) }
    ],
    traps: [
      { label: 'le samedi seulement : date(\'N\') == 6', files: { 'accueil.php': N07.replace('$jour >= 6 && $heure < 11', '$jour == 6 && $heure < 11') } },
      { label: 'le vendredi compté dans le week-end', files: { 'accueil.php': N07.replace('$jour >= 6 && $heure < 11', '$jour >= 5 && $heure < 11') } },
      { label: '« Bonne nuit » jusqu\'à 3 h 59 seulement', files: { 'accueil.php': N07.replace('$heure >= 23 || $heure < 5', '$heure >= 23 || $heure < 4') } },
      { label: '« Bonne nuit » jusqu\'à 5 h 59', files: { 'accueil.php': N07.replace('$heure >= 23 || $heure < 5', '$heure >= 23 || $heure <= 5') } },
      { label: '« Bonne nuit » dès 22 h', files: { 'accueil.php': N07.replace('$heure >= 23 || $heure < 5', '$heure >= 22 || $heure < 5') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$heure = 2;', 'if ($heure >= 23 && $heure < 5) {', '    echo \'Bonne nuit\';', '} else {', '    echo \'Bonsoir\';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Et, ou', body: 'a && b : vrai si les DEUX sont vraies.\na || b : vrai si l\'UNE au moins est vraie.\n\ndate(\'N\') : 1 lundi … 6 samedi, 7 dimanche.\nUne heure ne peut pas être à la fois après 23 h et avant 5 h.' },
      { title: 'La solution', body: '$jour = (int) date(\'N\');\nif ($heure >= 23 || $heure < 5) {\n    $salut = \'Bonne nuit\';\n} elseif ($heure < 18) {\n    $salut = \'Bonjour\';\n} else {\n    $salut = \'Bonsoir\';\n}\n…\n} elseif ($jour >= 6 && $heure < 11) {\n    $petitDej = \'Le petit-déjeuner est servi jusqu\\\'à 11 h.\';\n} elseif ($heure < 10) {' }
    ],
    intro: [
      { who: 'josette', expr: 'sourire', text: 'Le week-end, les gens dorment. Je sers jusqu\'à 11 h, samedi et dimanche.' },
      { who: 'mireille', expr: 'neutre', text: 'Et la nuit, entre 23 h et 5 h, je veux « Bonne nuit ». Augustin disait que « Bonsoir » à 3 h du matin, ça réveille.' },
      { who: 'sami', expr: 'neutre', text: 'Deux conditions en même temps, ou l\'une ou l\'autre : && et ||. Le carnet d\'Augustin a une page là-dessus.' }
    ],
    done: [
      { who: 'sami', expr: 'sourcil', text: 'Tiens. Le message de 23 h 12 n\'apparaît que le jeudi. Celui qui l\'a écrit teste le jour de la semaine, comme toi.' },
      { who: 'mireille', expr: 'neutre', text: 'Augustin faisait toutes les nuits du jeudi. Trente ans. Je n\'y avais jamais pensé.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
