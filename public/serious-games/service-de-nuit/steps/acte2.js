/* Service de nuit — acte 2, « L'horloge » (N07-N10), the game's half. */
(function (root) {
  'use strict';
  var ST = root.SDNSteps, sees = ST.sees, reg = ST.reg;
  var L = function () { return Array.prototype.slice.call(arguments).join('\n'); };

  var N06 = ST.refOf('N06')['accueil.php'];
  var SALUT_BIENVENUE = '$accueil = \'Bienvenue \' . $client . \', chambre \' . $chambre;';

  var N07 = N06.replace(SALUT_BIENVENUE, L(
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
  var N08 = N07
    .replace('if (date(\'G\') < 18) {', '$heure = (int) date(\'G\');\nif ($heure < 18) {')
    .replace('$accueil = $salut', PETIT_DEJ + '\n$accueil = $salut')
    .replace('<p class="accueil"><?php echo $accueil; ?></p>', '<p class="accueil"><?php echo $accueil; ?></p>\n  <p class="petit-dej"><?php echo $petitDej; ?></p>');

  var N09 = N08
    .replace('$heure = (int) date(\'G\');\n', '$heure = (int) date(\'G\');\n$jour = (int) date(\'N\');\n')
    .replace('} elseif ($heure < 10) {', L(
      '} elseif ($jour >= 6 && $heure < 11) {',
      '    $petitDej = \'Le petit-déjeuner est servi jusqu\\\'à 11 h.\';',
      '} elseif ($heure < 10) {'));

  var N10 = N09.replace('if ($heure < 18) {', L(
    'if ($heure >= 23 || $heure < 5) {',
    '    $salut = \'Bonne nuit\';',
    '} elseif ($heure < 18) {'));

  var THU = '2026-11-05 ', FRI = '2026-11-06 ', SAT = '2026-11-07 ', SUN = '2026-11-08 ';
  var ROUX = { client: 'Mme Roux', chambre: 12 }, DIAZ = { client: 'M. Diaz', chambre: 3 }, OKAFOR = { client: 'Famille Okafor', chambre: 21, nuits: 4, tarif: 105, personnes: 4 }, LINA = { client: 'Lina', chambre: 5, nuits: 2, tarif: 62 }, BERGERON = { client: 'M. et Mme Bergeron', chambre: 30, nuits: 7, tarif: 160, personnes: 2 };
  function salut(mot, who) { return mot + ' ' + who.client + ', chambre ' + who.chambre; }
  var OUVRE = 'Le petit-déjeuner ouvre à 7 h.', DIX = 'Le petit-déjeuner est servi jusqu\'à 10 h.', ONZE = 'Le petit-déjeuner est servi jusqu\'à 11 h.', DEMAIN = 'Le petit-déjeuner est servi demain dès 7 h.';

  ST.add({
    id: 'N07', act: 2, learn: ['conditions'], notions: ['conditions', 'date', 'concatenation'],
    show: { before: 'Bienvenue M. Diaz, chambre 3', after: 'Bonsoir M. Diaz, chambre 3', note: 'à 23 h 12 ; « Bonjour » avant 18 h' },
    ref: { 'accueil.php': N07 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', reg: reg(ROUX), visible: true, label: 'Mme Roux, 8 h 05 : « Bonjour »', check: sees(salut('Bonjour', ROUX)) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg(DIAZ), visible: true, label: 'M. Diaz, 23 h 12 : « Bonsoir »', check: sees(salut('Bonsoir', DIAZ)) },
      { id: 'okafor', who: 'La famille Okafor', at: FRI + '18:30:00', reg: reg(OKAFOR), label: 'La famille Okafor, à 18 h 30', check: sees(salut('Bonsoir', OKAFOR)) },
      { id: 'lina', who: 'Lina', at: SAT + '18:00:00', reg: reg(LINA), label: 'Lina, à 18 h pile', check: sees(salut('Bonsoir', LINA)) },
      { id: 'bergeron', who: 'Les Bergeron', at: SAT + '17:59:00', reg: reg(BERGERON), label: 'Les Bergeron, à 17 h 59', check: sees(salut('Bonjour', BERGERON)) }
    ],
    traps: [
      { label: '<= 18 : « Bonjour » jusqu\'à 18 h 59', files: { 'accueil.php': N07.replace('date(\'G\') < 18', 'date(\'G\') <= 18') } },
      { label: '> 18 pour « Bonsoir » : 18 h pile oubliée', files: { 'accueil.php': N07.replace('if (date(\'G\') < 18) {\n    $salut = \'Bonjour\';\n} else {\n    $salut = \'Bonsoir\';\n}', 'if (date(\'G\') > 18) {\n    $salut = \'Bonsoir\';\n} else {\n    $salut = \'Bonjour\';\n}') } },
      { label: '< 17 : « Bonsoir » dès 17 h', files: { 'accueil.php': N07.replace('date(\'G\') < 18', 'date(\'G\') < 17') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$nuits = 1;', 'if ($nuits > 1) {', '    echo \'Bon séjour parmi nous.\';', '} else {', '    echo \'Bonne nuit parmi nous.\';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Si… sinon…', body: 'if (condition) {\n    // ce qui se passe quand c\'est vrai\n} else {\n    // sinon\n}\n\ndate(\'G\') donne l\'heure, de 0 à 23, sans zéro devant.\nRange le mot choisi dans $salut, et sers-t\'en à la place de \'Bienvenue \'.' },
      { title: 'La solution', body: 'if (date(\'G\') < 18) {\n    $salut = \'Bonjour\';\n} else {\n    $salut = \'Bonsoir\';\n}\n$accueil = $salut . \' \' . $client . \', chambre \' . $chambre;' }
    ],
    intro: [
      { who: 'mireille', expr: 'sourcil', text: '« Bienvenue », à minuit, à des gens qui rentrent du restaurant… Je veux « Bonjour » avant 18 h et « Bonsoir » à partir de 18 h.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'À 18 h pile, « Bonsoir ». C\'est exactement ce que faisait Augustin.' }
    ]
  });

  ST.add({
    id: 'N08', act: 2, learn: ['conditions-multiples'], notions: ['conditions-multiples', 'conditions', 'date'],
    show: { before: '(rien)', after: 'Le petit-déjeuner est servi jusqu\'à 10 h.', note: 'à 8 h 05 ; deux autres phrases avant 7 h et après 10 h' },
    ref: { 'accueil.php': N08 },
    cases: [
      { id: 'roux', who: 'Mme Roux', at: THU + '08:05:00', reg: reg(ROUX), visible: true, label: 'Mme Roux, 8 h 05 : servi jusqu\'à 10 h', check: sees(DIX) },
      { id: 'diaz', who: 'M. Diaz', at: THU + '23:12:00', reg: reg(DIAZ), visible: true, label: 'M. Diaz, 23 h 12 : servi demain dès 7 h', check: sees(DEMAIN) },
      { id: 'tot', who: 'Un client matinal', at: FRI + '06:30:00', reg: reg(OKAFOR), visible: true, label: 'Un client à 6 h 30 : ouvre à 7 h', check: sees(OUVRE) },
      { id: 'dix', who: 'Une cliente en retard', at: FRI + '10:15:00', reg: reg(LINA), label: 'Une cliente à 10 h 15', check: sees(DEMAIN) },
      { id: 'sept', who: 'Les Bergeron', at: SAT + '07:00:00', reg: reg(BERGERON), label: 'Les Bergeron, à 7 h pile', check: sees(DIX) }
    ],
    traps: [
      { label: '<= 10 : servi jusqu\'à 10 h 59', files: { 'accueil.php': N08.replace('} elseif ($heure < 10) {', '} elseif ($heure <= 10) {') } },
      { label: '<= 7 : fermé jusqu\'à 7 h 59', files: { 'accueil.php': N08.replace('if ($heure < 7) {', 'if ($heure <= 7) {') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$heure = 14;', 'if ($heure < 12) {', '    echo \'matin\';', '} elseif ($heure < 18) {', '    echo \'après-midi\';', '} elseif ($heure < 16) {', '    echo \'goûter\';', '} else {', '    echo \'soir\';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Trois cas', body: 'if (…) {\n    …\n} elseif (…) {\n    …\n} else {\n    …\n}\n\nPHP teste de haut en bas et s\'arrête à la première condition vraie.\nL\'apostrophe de « jusqu\'à » : écris jusqu\\\'à entre guillemets simples.' },
      { title: 'La solution', body: '$heure = (int) date(\'G\');\n…\nif ($heure < 7) {\n    $petitDej = \'Le petit-déjeuner ouvre à 7 h.\';\n} elseif ($heure < 10) {\n    $petitDej = \'Le petit-déjeuner est servi jusqu\\\'à 10 h.\';\n} else {\n    $petitDej = \'Le petit-déjeuner est servi demain dès 7 h.\';\n}\n…\n<p class="petit-dej"><?php echo $petitDej; ?></p>' }
    ],
    intro: [
      { who: 'josette', expr: 'sourire', text: 'Josette, du petit-déjeuner. On me demande toute la journée si c\'est encore servi : trois phrases selon l\'heure, sous l\'accueil, s\'il te plaît.' }
    ],
    done: [
      { who: 'josette', expr: 'sourire', text: 'Plus personne ne vient me demander à 10 h 15 s\'il reste du café. Enfin, presque plus.' }
    ]
  });

  ST.add({
    id: 'N09', act: 2, learn: ['operateurs-logiques'], notions: ['operateurs-logiques', 'conditions-multiples', 'date'],
    show: { before: 'Le petit-déjeuner est servi demain dès 7 h.', after: 'Le petit-déjeuner est servi jusqu\'à 11 h.', note: 'un samedi à 10 h 30' },
    ref: { 'accueil.php': N09 },
    cases: [
      { id: 'samedi', who: 'Lina, samedi', at: SAT + '10:30:00', reg: reg(LINA), visible: true, label: 'Samedi 10 h 30 : servi jusqu\'à 11 h', check: sees(ONZE) },
      { id: 'jeudi', who: 'Mme Roux, jeudi', at: THU + '08:05:00', reg: reg(ROUX), visible: true, label: 'Jeudi 8 h 05 : toujours jusqu\'à 10 h', check: sees(DIX) },
      { id: 'dimanche', who: 'Les Bergeron, dimanche', at: SUN + '10:40:00', reg: reg(BERGERON), label: 'Dimanche 10 h 40', check: sees(ONZE) },
      { id: 'vendredi', who: 'M. Diaz, vendredi', at: FRI + '10:40:00', reg: reg(DIAZ), label: 'Vendredi 10 h 40', check: sees(DEMAIN) },
      { id: 'tot', who: 'La famille Okafor, samedi', at: SAT + '06:30:00', reg: reg(OKAFOR), label: 'Samedi 6 h 30', check: sees(OUVRE) }
    ],
    traps: [
      { label: 'le samedi seulement : $jour == 6', files: { 'accueil.php': N09.replace('$jour >= 6 && $heure < 11', '$jour == 6 && $heure < 11') } },
      { label: 'le vendredi compté dans le week-end', files: { 'accueil.php': N09.replace('$jour >= 6 && $heure < 11', '$jour >= 5 && $heure < 11') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$jour = 7;', '$heure = 12;', 'if ($jour >= 6 && $heure < 11) {', '    echo \'Servi\';', '} else {', '    echo \'Fermé\';', '}', '?>'], run: {} } },
    hints: [
      { title: 'Les deux à la fois', body: 'a && b : vrai si les DEUX sont vraies.\n\ndate(\'N\') : 1 le lundi … 6 le samedi, 7 le dimanche.\nLe week-end : $jour >= 6.\n\nUne branche de plus, entre « ouvre à 7 h » et « jusqu\'à 10 h ».' },
      { title: 'La solution', body: '$heure = (int) date(\'G\');\n$jour = (int) date(\'N\');\n…\nif ($heure < 7) {\n    …\n} elseif ($jour >= 6 && $heure < 11) {\n    $petitDej = \'Le petit-déjeuner est servi jusqu\\\'à 11 h.\';\n} elseif ($heure < 10) {\n    …' }
    ],
    intro: [
      { who: 'josette', expr: 'sourire', text: 'Le week-end, les gens dorment. Samedi et dimanche, je sers jusqu\'à 11 h.' }
    ],
    done: [
      { who: 'josette', expr: 'sourire', text: 'Dimanche 10 h 40, et la page dit qu\'il reste des croissants. Il en reste.' }
    ]
  });

  ST.add({
    id: 'N10', act: 2, learn: ['operateurs-logiques'], notions: ['operateurs-logiques', 'conditions-multiples', 'date'],
    show: { before: 'Bonsoir M. Diaz, chambre 3', after: 'Bonne nuit M. Diaz, chambre 3', note: 'à 23 h 12 ; « Bonne nuit » de 23 h à 5 h' },
    ref: { 'accueil.php': N10 },
    cases: [
      { id: 'diaz', who: 'M. Diaz, jeudi', at: THU + '23:12:00', reg: reg(DIAZ), visible: true, label: 'Jeudi 23 h 12 : « Bonne nuit »', check: sees(salut('Bonne nuit', DIAZ)) },
      { id: 'roux', who: 'Mme Roux, jeudi', at: THU + '08:05:00', reg: reg(ROUX), visible: true, label: 'Jeudi 8 h 05 : toujours « Bonjour »', check: sees(salut('Bonjour', ROUX)) },
      { id: 'cinq', who: 'La famille Okafor, au retour du train de nuit', at: FRI + '04:59:00', reg: reg(OKAFOR), label: 'Vendredi 4 h 59', check: sees(salut('Bonne nuit', OKAFOR)) },
      { id: 'cinqpile', who: 'Un client à 5 h', at: FRI + '05:00:00', reg: reg(DIAZ), label: 'Vendredi 5 h pile', check: sees(salut('Bonjour', DIAZ)) },
      { id: 'vingtdeux', who: 'Une cliente à 22 h 59', at: THU + '22:59:00', reg: reg(LINA), label: 'Jeudi 22 h 59', check: sees(salut('Bonsoir', LINA)) }
    ],
    traps: [
      { label: '« Bonne nuit » jusqu\'à 3 h 59 seulement', files: { 'accueil.php': N10.replace('$heure >= 23 || $heure < 5', '$heure >= 23 || $heure < 4') } },
      { label: '« Bonne nuit » jusqu\'à 5 h 59', files: { 'accueil.php': N10.replace('$heure >= 23 || $heure < 5', '$heure >= 23 || $heure <= 5') } },
      { label: '« Bonne nuit » dès 22 h', files: { 'accueil.php': N10.replace('$heure >= 23 || $heure < 5', '$heure >= 22 || $heure < 5') } }
    ],
    views: { veille: { title: 'veille.php', lines: ['<?php', '$heure = 2;', 'if ($heure >= 23 && $heure < 5) {', '    echo \'Bonne nuit\';', '} else {', '    echo \'Bonsoir\';', '}', '?>'], run: {} } },
    hints: [
      { title: 'L\'une ou l\'autre', body: 'a || b : vrai si l\'UNE au moins est vraie.\n\nLa nuit, c\'est « après 23 h » OU « avant 5 h » :\naucune heure n\'est les deux à la fois.\n\nUne branche de plus, en premier, dans le choix de $salut.' },
      { title: 'La solution', body: 'if ($heure >= 23 || $heure < 5) {\n    $salut = \'Bonne nuit\';\n} elseif ($heure < 18) {\n    $salut = \'Bonjour\';\n} else {\n    $salut = \'Bonsoir\';\n}' }
    ],
    intro: [
      { who: 'mireille', expr: 'neutre', text: 'Entre 23 h et 5 h, je veux « Bonne nuit » au lieu de « Bonsoir ». « Bonsoir » à 3 h du matin, ça réveille.' }
    ],
    done: [
      { who: 'mireille', expr: 'sourire', text: 'Bonne nuit à 4 h 59, bonjour à 5 h. La page sait l\'heure mieux que moi.' }
    ]
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
