/* Service de nuit — Augustin's notebooks: one page per notion, and the sign it lights.

   A notion is a letter of the hotel's sign: QUATRE-VENTS for the ground floor (the first level), HÔTEL
   for the floors (the second). Every page is readable from the start; a page opens by itself on the first
   night that brings it (SDNSteps' `learn`), and its memo - what to remember, the first example - stays
   beside the code during every night about it. A `reg` in a run is what the hotel prepares before the
   code: the game shows it as locked lines on top, like in the site.

     { id, letter, word: 'quatre' | 'hotel', index (its place in the word), title, intro,
       blocks: [{ text } | { code, run: { at, reg, get }, note }],   // an example runs and can be edited
       retenir: ['…'],
       exos: [{ id, prompt, start, ref, runs: [{ at, reg, get }] }] }       // « À toi »: the student's code must
                                                                            // show what the reference shows, on every run

   An exercise is checked in the game, by comparing what the browser would show: it repairs a letter of
   the sign, it is never sent to the platform. The bench checks that each reference runs without a
   warning and that each start does not already pass. */
(function (root) {
  'use strict';
  var THU = '2026-11-05 ';
  var notions = [
    {
      id: 'echo', letter: 'Q', word: 'quatre', index: 0, title: 'Le serveur écrit la page',
      intro: 'Une page PHP n\'existe pas avant qu\'on la demande. Le serveur la lit de haut en bas : le HTML, il le recopie tel quel ; entre <?php et ?>, il exécute, et ce que echo écrit prend la place du code.',
      blocks: [
        { text: 'Le navigateur ne reçoit jamais le PHP : il reçoit ce que le PHP a écrit. Tout ce qui est hors des balises part tel quel.' },
        { code: '<p>Réception ouverte.</p>\n<p><?php echo \'Bonsoir !\'; ?></p>', run: {}, note: 'Change le texte entre les guillemets, puis exécute.' },
        { text: 'Une instruction se termine par un point-virgule. Le texte s\'écrit entre guillemets ; sans guillemets, PHP croit lire du code.' }
      ],
      retenir: ['<?php … ?> : ce que le serveur exécute.', 'echo écrit dans la page, à l\'endroit où il se trouve.', 'Chaque instruction finit par ;'],
      exos: [
        { id: 'echo1', prompt: 'Fais écrire « Bonne nuit » par PHP, dans le paragraphe.', start: '<p>Bonsoir</p>', ref: '<p><?php echo \'Bonne nuit\'; ?></p>', runs: [{}] },
        { id: 'echo2', prompt: 'Écris deux paragraphes avec un seul bloc PHP : « Chambre 12 » puis « Deuxième étage ».', start: '<?php\n\n?>', ref: '<?php\necho \'<p>Chambre 12</p>\';\necho \'<p>Deuxième étage</p>\';\n?>', runs: [{}] }
      ]
    },
    {
      id: 'date', letter: 'U', word: 'quatre', index: 1, title: 'L\'horloge du serveur',
      intro: 'date() lit l\'horloge du serveur au moment de la visite, et l\'écrit selon un modèle : chaque lettre du modèle est remplacée par un morceau de la date.',
      blocks: [
        { code: '<p>Il est <?php echo date(\'H:i\'); ?>.</p>\n<p>Nous sommes le <?php echo date(\'d/m/Y\'); ?>.</p>', run: { at: THU + '08:05:00' }, note: 'Essaie date(\'G\'), date(\'j\'), date(\'N\').' },
        { text: 'H : l\'heure sur 24 h, avec un zéro (08) · G : sans zéro (8) · i : les minutes (05) · d : le jour (05) · j : sans zéro (5) · m : le mois (11) · Y : l\'année (2026) · N : le jour de la semaine, 1 le lundi, 7 le dimanche.' },
        { text: 'Attention : m, c\'est le mois, pas les minutes. Et h (minuscule), c\'est l\'heure sur 12 h : 23 h devient 11.' }
      ],
      retenir: ['date(\'H:i\') : 08:05.', 'L\'heure est celle du serveur, au moment de la visite.', 'i pour les minutes, m pour le mois.'],
      exos: [
        { id: 'date1', prompt: 'Écris « Il est 23 h 12 » avec l\'heure du serveur (G, puis i).', start: '<p>Il est 23 h 12</p>', ref: '<p>Il est <?php echo date(\'G\'); ?> h <?php echo date(\'i\'); ?></p>', runs: [{ at: THU + '23:12:00' }, { at: THU + '08:05:00' }] },
        { id: 'date2', prompt: 'Écris la date sous la forme 05/11/2026.', start: '<p></p>', ref: '<p><?php echo date(\'d/m/Y\'); ?></p>', runs: [{ at: THU + '23:12:00' }, { at: '2027-01-31 09:00:00' }] }
      ]
    },
    {
      id: 'variables', letter: 'A', word: 'quatre', index: 2, title: 'Les boîtes à étiquette',
      intro: 'Une variable est une boîte avec une étiquette qui commence par $. On y range une valeur une fois, et on la lit autant de fois qu\'on veut. L\'hôtel en prépare quelques-unes avant ta page, dans les lignes grises en haut du fichier : $garde, $client…',
      blocks: [
        { code: '<?php\n$telephone = \'05 55 12 34 56\';\n?>\n<p>Appelez le <?php echo $telephone; ?>.</p>\n<p>Ou de nuit : <?php echo $telephone; ?>.</p>', run: {}, note: 'Change le numéro dans la variable : les deux lignes suivent.' },
        { text: 'Une variable ne garde que sa dernière valeur : $chambre = 7; puis $chambre = 12; et elle vaut 12. Elle ne vit que le temps de fabriquer la page.' },
        { code: '<p>Ce soir, <?php echo $garde; ?> veille sur vous.</p>', run: { reg: { garde: 'Nora' } }, note: '$garde est préparé par l\'hôtel, dans les lignes grises : ici, il vaut « Nora ».' }
      ],
      retenir: ['$nom = valeur; range une valeur.', 'echo $nom; écrit ce qu\'elle contient.', 'Une valeur écrite une fois se change à un seul endroit.'],
      exos: [
        { id: 'var1', prompt: 'L\'hôtel prépare $hotel : le nom change selon l\'hôtel du groupe. Écris-le dans les deux titres.', start: '<h1>Quatre-Vents</h1>\n<h2>Quatre-Vents</h2>', ref: '<h1><?php echo $hotel; ?></h1>\n<h2><?php echo $hotel; ?></h2>', runs: [{ reg: { hotel: 'Quatre-Vents' } }, { reg: { hotel: 'Trois-Lunes' } }] },
        { id: 'var2', prompt: 'L\'hôtel prépare $garde. Écris « Veilleur : » suivi de son nom.', start: '<p>Veilleur : Sami</p>', ref: '<p>Veilleur : <?php echo $garde; ?></p>', runs: [{ reg: { garde: 'Théo' } }, { reg: { garde: 'Nora' } }] }
      ]
    },
    {
      id: 'concatenation', letter: 'T', word: 'quatre', index: 3, title: 'Coller les morceaux',
      intro: 'Le point colle deux morceaux de texte bout à bout, sans rien entre eux. Entre guillemets doubles, PHP remplace aussi les variables par leur valeur ; entre guillemets simples, jamais.',
      blocks: [
        { code: '<?php\n$client = \'Mme Roux\';\necho \'Bienvenue \' . $client . \' !\';\n?>', run: {}, note: 'Enlève l\'espace après « Bienvenue » : que se passe-t-il ?' },
        { code: '<?php\n$chambre = 12;\necho "<p>Chambre $chambre</p>";\necho \'<p>Chambre $chambre</p>\';\n?>', run: {}, note: 'Guillemets doubles, puis simples.' },
        { text: 'Pour écrire une apostrophe dans un texte entre guillemets simples, mets une barre devant : \'jusqu\\\'à 10 h\'.' }
      ],
      retenir: ['\'a\' . \'b\' donne « ab » : les espaces sont dans le texte.', '"… $x …" remplace $x ; \'… $x …\' non.', '\\\' pour une apostrophe entre guillemets simples.'],
      exos: [
        { id: 'cat1', prompt: 'Construis « Chambre 12, côté cour » dans $ligne à partir de $chambre, puis écris-la.', start: '<?php\n$chambre = 12;\n?>', ref: '<?php\n$chambre = 12;\n$ligne = \'Chambre \' . $chambre . \', côté cour\';\necho $ligne;\n?>', runs: [{}] },
        { id: 'cat2', prompt: 'Écris « Bonsoir Lina, à demain ! » avec $client (préparé par l\'hôtel).', start: '<p>Bonsoir Lina, à demain !</p>', ref: '<p><?php echo \'Bonsoir \' . $client . \', à demain !\'; ?></p>', runs: [{ reg: { client: 'Lina' } }, { reg: { client: 'M. Diaz' } }] }
      ]
    },
    {
      id: 'calculs', letter: 'R', word: 'quatre', index: 4, title: 'Faire les comptes',
      intro: 'PHP calcule avec + - * / et % (le reste d\'une division). La multiplication passe avant l\'addition, sauf si des parenthèses disent le contraire. Le séparateur décimal est le point : 1.5.',
      blocks: [
        { code: '<?php\n$nuits = 3;\n$tarif = 78;\necho $nuits * $tarif + 10;\necho \' / \';\necho $nuits * ($tarif + 10);\n?>', run: {}, note: 'Les parenthèses changent l\'ordre.' },
        { code: '<?php\n$total = 1141;\necho number_format($total, 2, \',\', \' \') . \' €\';\n?>', run: {}, note: 'number_format(nombre, décimales, virgule, séparateur des milliers).' }
      ],
      retenir: ['* et / avant + et -.', '1.5 dans le code, « 1,50 » à l\'écran grâce à number_format.', 'number_format($x, 2, \',\', \' \') : 1 141,00'],
      exos: [
        { id: 'calc1', prompt: 'Écris le prix de $nuits nuits à $tarif € (sans taxe), à la française : « 186,00 € ».', start: '<?php\n\n?>', ref: '<?php\necho number_format($nuits * $tarif, 2, \',\', \' \') . \' €\';\n?>', runs: [{ reg: { nuits: 3, tarif: 62 } }, { reg: { nuits: 12, tarif: 105 } }] },
        { id: 'calc2', prompt: 'La taxe : 1,50 € par nuit et par personne. Écris-la pour $nuits et $personnes, à la française.', start: '<?php\n\n?>', ref: '<?php\necho number_format($nuits * $personnes * 1.5, 2, \',\', \' \') . \' €\';\n?>', runs: [{ reg: { nuits: 4, personnes: 4 } }, { reg: { nuits: 1, personnes: 3 } }] }
      ]
    },
    {
      id: 'conditions', letter: 'E', word: 'quatre', index: 5, title: 'Si… sinon…',
      intro: 'if teste une condition : si elle est vraie, PHP exécute le premier bloc, sinon le bloc else. Jamais les deux. Les comparaisons : < > <= >= == (égal) != (différent).',
      blocks: [
        { code: '<?php\nif (date(\'G\') < 18) {\n    echo \'Bonjour\';\n} else {\n    echo \'Bonsoir\';\n}\n?>', run: { at: THU + '18:00:00' }, note: 'À 18 h pile, date(\'G\') vaut 18 : 18 < 18 est faux.' },
        { text: '= range une valeur ; == compare. if ($heure = 18) range 18 dans $heure, et c\'est toujours vrai : un piège classique.' }
      ],
      retenir: ['if (…) { … } else { … }', '< exclut la borne, <= l\'inclut.', '== compare, = range.'],
      exos: [
        { id: 'cond1', prompt: 'Écris « Complet » si $libres vaut 0, sinon « Chambres libres ».', start: '<?php\n\n?>', ref: '<?php\nif ($libres == 0) {\n    echo \'Complet\';\n} else {\n    echo \'Chambres libres\';\n}\n?>', runs: [{ reg: { libres: 0 } }, { reg: { libres: 3 } }] },
        { id: 'cond2', prompt: 'Écris « Prix réduit » si le séjour dure 7 nuits ou plus, sinon rien.', start: '<?php\n\n?>', ref: '<?php\nif ($nuits >= 7) {\n    echo \'Prix réduit\';\n}\n?>', runs: [{ reg: { nuits: 7 } }, { reg: { nuits: 6 } }, { reg: { nuits: 10 } }] }
      ]
    },
    {
      id: 'conditions-multiples', letter: 'V', word: 'quatre', index: 7, title: 'Plusieurs cas',
      intro: 'elseif ajoute un cas : PHP teste de haut en bas, s\'arrête à la première condition vraie, et le else ramasse le reste. L\'ordre des conditions compte.',
      blocks: [
        { code: '<?php\n$heure = (int) date(\'G\');\nif ($heure < 7) {\n    echo \'Fermé\';\n} elseif ($heure < 10) {\n    echo \'Servi\';\n} else {\n    echo \'Demain\';\n}\n?>', run: { at: THU + '06:30:00' }, note: 'Échange les deux premières conditions et exécute à 6 h 30.' }
      ],
      retenir: ['if … elseif … elseif … else : une seule branche s\'exécute.', 'La première condition vraie gagne.', 'Teste les cas les plus étroits d\'abord.'],
      exos: [
        { id: 'multi1', prompt: 'Selon $etage : 0 → « Rez-de-chaussée », 1 → « Premier », sinon « Étage ».', start: '<?php\n\n?>', ref: '<?php\nif ($etage == 0) {\n    echo \'Rez-de-chaussée\';\n} elseif ($etage == 1) {\n    echo \'Premier\';\n} else {\n    echo \'Étage\';\n}\n?>', runs: [{ reg: { etage: 0 } }, { reg: { etage: 1 } }, { reg: { etage: 3 } }] },
        { id: 'multi2', prompt: 'Selon l\'heure : avant 12 h « Matin », avant 18 h « Après-midi », sinon « Soir ».', start: '<?php\n\n?>', ref: '<?php\nif (date(\'G\') < 12) {\n    echo \'Matin\';\n} elseif (date(\'G\') < 18) {\n    echo \'Après-midi\';\n} else {\n    echo \'Soir\';\n}\n?>', runs: [{ at: THU + '09:00:00' }, { at: THU + '12:00:00' }, { at: THU + '18:30:00' }] }
      ]
    },
    {
      id: 'operateurs-logiques', letter: 'E', word: 'quatre', index: 8, title: 'Et, ou, non',
      intro: 'Deux conditions se combinent : a && b est vrai si les deux le sont ; a || b si l\'une au moins l\'est ; !a renverse a. Une heure ne peut pas être à la fois après 23 h et avant 5 h : la nuit, c\'est ||.',
      blocks: [
        { code: '<?php\n$heure = (int) date(\'G\');\n$jour = (int) date(\'N\');\nif ($jour >= 6 && $heure < 11) {\n    echo \'Petit-déjeuner du week-end\';\n} elseif ($heure >= 23 || $heure < 5) {\n    echo \'Bonne nuit\';\n} else {\n    echo \'Bonjour\';\n}\n?>', run: { at: '2026-11-08 10:40:00' }, note: 'Dimanche 10 h 40. Remplace && par || : que vois-tu un mardi à 10 h 40 ?' }
      ],
      retenir: ['&& : les deux. || : l\'une ou l\'autre.', 'date(\'N\') >= 6 : samedi ou dimanche.', '!$ouvert : « pas ouvert ».'],
      exos: [
        { id: 'logi1', prompt: 'Écris « Calme » entre 22 h et 7 h (22 h comprise, 7 h exclue), sinon « Animé ».', start: '<?php\n$heure = (int) date(\'G\');\n?>', ref: '<?php\n$heure = (int) date(\'G\');\nif ($heure >= 22 || $heure < 7) {\n    echo \'Calme\';\n} else {\n    echo \'Animé\';\n}\n?>', runs: [{ at: THU + '22:00:00' }, { at: THU + '06:59:00' }, { at: THU + '07:00:00' }, { at: THU + '15:00:00' }] },
        { id: 'logi2', prompt: 'Écris « Navette » en semaine (lundi à vendredi) entre 6 h et 20 h (20 h exclue), sinon « Pas de navette ».', start: '<?php\n\n?>', ref: '<?php\n$heure = (int) date(\'G\');\nif (date(\'N\') <= 5 && $heure >= 6 && $heure < 20) {\n    echo \'Navette\';\n} else {\n    echo \'Pas de navette\';\n}\n?>', runs: [{ at: THU + '06:00:00' }, { at: THU + '20:00:00' }, { at: '2026-11-07 10:00:00' }, { at: '2026-11-06 19:59:00' }] }
      ]
    },
    {
      id: 'boucle-for', letter: 'N', word: 'quatre', index: 9, title: 'Répéter en comptant',
      intro: 'for répète un bloc en comptant : un départ, une condition pour continuer, un pas. for ($i = 1; $i <= 3; $i++) fait trois tours, avec $i qui vaut 1, puis 2, puis 3.',
      blocks: [
        { code: '<ul>\n<?php\nfor ($i = 1; $i <= 5; $i++) {\n    echo \'<li>Chambre \' . $i . \'</li>\';\n}\n?>\n</ul>', run: {}, note: 'Change le 5 en $ouvertes, ou commence à 0.' },
        { text: '$i++ ajoute 1 à $i. On peut avancer autrement : $i = $i + 2 pour les chambres impaires.' }
      ],
      retenir: ['for (départ; condition; pas) { … }', '<= inclut la dernière valeur, < l\'exclut.', 'La fin vient d\'une variable, pas d\'un nombre écrit en dur.'],
      exos: [
        { id: 'for1', prompt: 'Écris les étages de 1 à $etages, séparés par des espaces : « 1 2 3 4 ».', start: '<?php\n\n?>', ref: '<?php\nfor ($i = 1; $i <= $etages; $i++) {\n    echo $i . \' \';\n}\n?>', runs: [{ reg: { etages: 4 } }, { reg: { etages: 1 } }] },
        { id: 'for2', prompt: 'Écris les chambres impaires de 1 à 9 : « 1 3 5 7 9 ».', start: '<?php\n\n?>', ref: '<?php\nfor ($i = 1; $i <= 9; $i = $i + 2) {\n    echo $i . \' \';\n}\n?>', runs: [{}] }
      ]
    },
    {
      id: 'boucle-while', letter: 'T', word: 'quatre', index: 10, title: 'Tant que…',
      intro: 'while répète tant que sa condition est vraie. On l\'utilise quand on ne sait pas d\'avance combien de tours il faudra. Il faut que quelque chose, dans la boucle, finisse par rendre la condition fausse.',
      blocks: [
        { code: '<?php\n$places = 3;\nwhile ($places > 0) {\n    echo \'Une place. \';\n    $places = $places - 1;\n}\necho \'Complet !\';\n?>', run: {}, note: 'Enlève la ligne qui diminue $places… et regarde le jeu t\'arrêter.' },
        { code: '<?php\n$depart = mktime(22, 0, 0);\nwhile ($depart <= mktime(23, 59, 0)) {\n    echo date(\'H:i\', $depart) . \' \';\n    $depart = $depart + 25 * 60;\n}\n?>', run: {}, note: 'mktime(H, i, s) donne une heure d\'aujourd\'hui en secondes ; date(\'H:i\', $t) l\'écrit.' }
      ],
      retenir: ['while (condition) { … } : tant que c\'est vrai.', 'La boucle doit faire avancer quelque chose.', 'time() : maintenant, en secondes.'],
      exos: [
        { id: 'while1', prompt: 'Le prix d\'une nuit, $prix, augmente de 10 € par an. Écris dans combien d\'années il dépasse 100 € (la boucle compte les années).', start: '<?php\n$annees = 0;\n?>', ref: '<?php\n$annees = 0;\nwhile ($prix <= 100) {\n    $prix = $prix + 10;\n    $annees = $annees + 1;\n}\necho $annees;\n?>', runs: [{ reg: { prix: 62 } }, { reg: { prix: 100 } }, { reg: { prix: 120 } }] },
        { id: 'while2', prompt: 'Écris les chambres en descendant, de $derniere jusqu\'à 1 : « 5 4 3 2 1 ».', start: '<?php\n$n = $derniere;\n?>', ref: '<?php\n$n = $derniere;\nwhile ($n >= 1) {\n    echo $n . \' \';\n    $n = $n - 1;\n}\n?>', runs: [{ reg: { derniere: 5 } }, { reg: { derniere: 1 } }] }
      ]
    },
    {
      id: 'boucle-condition', letter: 'S', word: 'quatre', index: 11, title: 'Décider à chaque tour',
      intro: 'Dans une boucle, un if décide pour le tour en cours. % donne le reste d\'une division : $i % 2 == 0 quand $i est pair. continue saute la fin du tour ; break sort de la boucle.',
      blocks: [
        { code: '<?php\nfor ($i = 1; $i <= 15; $i++) {\n    if ($i == 13) {\n        continue;\n    }\n    if ($i % 2 == 0) {\n        echo $i . \' cour · \';\n    } else {\n        echo $i . \' rue · \';\n    }\n}\n?>', run: {}, note: 'Remplace continue par break.' }
      ],
      retenir: ['$i % 2 == 0 : pair.', 'continue : au tour suivant. break : on sort.', 'L\'ordre des if compte, dans la boucle aussi.'],
      exos: [
        { id: 'bc1', prompt: 'De 1 à 10, écris les nombres, mais « * » à la place des multiples de 3 : « 1 2 * 4 5 * 7 8 * 10 ».', start: '<?php\n\n?>', ref: '<?php\nfor ($i = 1; $i <= 10; $i++) {\n    if ($i % 3 == 0) {\n        echo \'* \';\n    } else {\n        echo $i . \' \';\n    }\n}\n?>', runs: [{}] },
        { id: 'bc2', prompt: 'Compte les chambres paires de 1 à $ouvertes, et écris le total.', start: '<?php\n$paires = 0;\n?>', ref: '<?php\n$paires = 0;\nfor ($i = 1; $i <= $ouvertes; $i++) {\n    if ($i % 2 == 0) {\n        $paires = $paires + 1;\n    }\n}\necho $paires;\n?>', runs: [{ reg: { ouvertes: 40 } }, { reg: { ouvertes: 13 } }] }
      ]
    },
    {
      id: 'lecture', letter: '-', word: 'quatre', index: 6, title: 'Lire le code des autres',
      intro: 'Le trait d\'union de QUATRE-VENTS : lire un code qu\'on n\'a pas écrit, ligne à ligne, en suivant les valeurs. C\'est la moitié du métier. Il s\'allume avec le planning du ménage d\'Augustin.',
      blocks: [
        { text: 'Pour lire un if : remplace chaque variable par sa valeur, puis calcule la condition. Pour lire une boucle : note la valeur de $i à chaque tour.' }
      ],
      retenir: ['Remplace les variables par leurs valeurs.', 'Une seule branche de if/elseif s\'exécute.', 'Une boucle se lit tour par tour.'],
      exos: []
    },
    {
      id: 'tableaux', letter: 'H', word: 'hotel', index: 0, title: 'Des cases numérotées',
      intro: 'Un tableau range plusieurs valeurs dans une seule variable, chacune dans une case numérotée à partir de 0, comme les étages à partir du rez-de-chaussée. count() donne le nombre de cases.',
      blocks: [
        { code: '<?php\n$jours = [\'lundi\', \'mardi\', \'mercredi\', \'jeudi\', \'vendredi\', \'samedi\', \'dimanche\'];\necho $jours[0] . \' / \' . $jours[3] . \' / \' . count($jours);\necho \' / \' . $jours[date(\'N\') - 1];\n?>', run: { at: THU + '23:12:00' }, note: 'date(\'N\') vaut 4 le jeudi : la case 3.' },
        { code: '<?php\n$etages = [\'rez-de-chaussée\', \'premier\'];\n$etages[] = \'deuxième\';\necho count($etages) . \' : \' . $etages[2];\n?>', run: {}, note: '$tableau[] = … ajoute une case à la fin.' }
      ],
      retenir: ['[\'a\', \'b\'] : cases 0 et 1.', '$t[0] : la première case.', 'count($t) : le nombre de cases.'],
      exos: [
        { id: 'tab1', prompt: 'Range les mois dans un tableau et écris le mois de la visite en français (date(\'n\') va de 1 à 12).', start: '<?php\n$mois = [\'janvier\', \'février\', \'mars\', \'avril\', \'mai\', \'juin\', \'juillet\', \'août\', \'septembre\', \'octobre\', \'novembre\', \'décembre\'];\n?>', ref: '<?php\n$mois = [\'janvier\', \'février\', \'mars\', \'avril\', \'mai\', \'juin\', \'juillet\', \'août\', \'septembre\', \'octobre\', \'novembre\', \'décembre\'];\necho $mois[date(\'n\') - 1];\n?>', runs: [{ at: THU + '20:00:00' }, { at: '2027-01-15 20:00:00' }, { at: '2027-12-15 20:00:00' }] },
        { id: 'tab2', prompt: 'Écris la dernière arrivée de $arrivees (préparé par l\'hôtel), quelle que soit la longueur de la liste.', start: '<?php\n\n?>', ref: '<?php\necho $arrivees[count($arrivees) - 1];\n?>', runs: [{ reg: { arrivees: ['Mme Roux', 'M. Diaz', 'Lina'] } }, { reg: { arrivees: ['Famille Okafor'] } }] }
      ]
    },
    {
      id: 'foreach', letter: 'Ô', word: 'hotel', index: 1, title: 'Une fois par case',
      intro: 'foreach parcourt un tableau : un tour par case, et à chaque tour la valeur de la case dans la variable que tu nommes. Il s\'arrête tout seul à la dernière, quelle que soit la longueur.',
      blocks: [
        { code: '<ul>\n<?php\nforeach ($arrivees as $nom) {\n    echo \'<li>\' . $nom . \'</li>\';\n}\n?>\n</ul>', run: { reg: { arrivees: ['Mme Roux', 'M. Diaz', 'Mme Faure'] } }, note: 'L\'hôtel a préparé trois arrivées.' },
        { text: 'Pour numéroter à partir de 1, un compteur : $rang = 1; avant la boucle, $rang++; dedans.' }
      ],
      retenir: ['foreach ($t as $v) { … } : un tour par case.', 'Pas besoin de count() pour s\'arrêter.', 'Un compteur pour numéroter.'],
      exos: [
        { id: 'fe1', prompt: 'Écris les clés du trousseau ($cles), une par ligne, en <li>.', start: '<ul>\n</ul>', ref: '<ul>\n<?php\nforeach ($cles as $cle) {\n    echo \'<li>\' . $cle . \'</li>\';\n}\n?>\n</ul>', runs: [{ reg: { cles: ['101', '102', '205'] } }, { reg: { cles: [] } }] },
        { id: 'fe2', prompt: 'Additionne les nuits de $nuitsParClient (préparé par l\'hôtel) et écris le total.', start: '<?php\n$total = 0;\n?>', ref: '<?php\n$total = 0;\nforeach ($nuitsParClient as $n) {\n    $total = $total + $n;\n}\necho $total;\n?>', runs: [{ reg: { nuitsParClient: [3, 1, 4] } }, { reg: { nuitsParClient: [7] } }] }
      ]
    },
    {
      id: 'tableaux-associatifs', letter: 'T', word: 'hotel', index: 2, title: 'Des cases avec un nom',
      intro: 'Les cases d\'un tableau peuvent porter un nom, une clé, au lieu d\'un numéro : [\'Simple\' => 62, \'Double\' => 78]. $tarifs[\'Double\'] vaut 78. foreach donne la clé et la valeur à chaque tour.',
      blocks: [
        { code: '<?php\n$tarifs = [\'Simple\' => 62, \'Double\' => 78, \'Suite\' => 120];\nforeach ($tarifs as $type => $prix) {\n    echo $type . \' : \' . $prix . \' € / \';\n}\necho \'le moins cher : \' . min($tarifs);\n?>', run: {}, note: 'Ajoute $tarifs[\'Familiale\'] = 105; avant la boucle.' },
        { text: 'isset($t[\'cle\']) dit si la case existe. Lire une case qui n\'existe pas affiche un avertissement.' }
      ],
      retenir: ['[\'clé\' => valeur]', 'foreach ($t as $cle => $valeur)', 'isset($t[$cle]) avant de lire une case incertaine.'],
      exos: [
        { id: 'as1', prompt: 'Écris chaque client et sa chambre ($chambres) : « Roux : 12 », un par ligne en <li>.', start: '<ul>\n</ul>', ref: '<ul>\n<?php\nforeach ($chambres as $nom => $numero) {\n    echo \'<li>\' . $nom . \' : \' . $numero . \'</li>\';\n}\n?>\n</ul>', runs: [{ reg: { chambres: { Roux: 12, Diaz: 3 } } }, { reg: { chambres: { Okafor: 21 } } }] },
        { id: 'as2', prompt: 'Écris la chambre de $nom si elle est dans $chambres, sinon « inconnu ».', start: '<?php\n\n?>', ref: '<?php\nif (isset($chambres[$nom])) {\n    echo $chambres[$nom];\n} else {\n    echo \'inconnu\';\n}\n?>', runs: [{ reg: { chambres: { Roux: 12, Diaz: 3 }, nom: 'Diaz' } }, { reg: { chambres: { Roux: 12 }, nom: 'Dupont' } }] }
      ]
    },
    {
      id: 'fonctions', letter: 'E', word: 'hotel', index: 3, title: 'Écrire une fois',
      intro: 'Une fonction range des instructions sous un nom. On l\'appelle avec des valeurs (ses paramètres), et return rend son résultat à qui l\'a appelée. Écrite dans fonctions.php, une page s\'en sert après include \'fonctions.php\';',
      blocks: [
        { code: '<?php\nfunction prix($montant)\n{\n    return number_format($montant, 2, \',\', \' \') . \' €\';\n}\necho \'Total : \' . prix(79.5) . \' / Suite : \' . prix(1250);\n?>', run: {}, note: 'Remplace return par echo : où sort le prix ?' },
        { text: 'Un paramètre peut avoir une valeur par défaut : function nuitee($tarif, $nuits = 1). Les variables d\'une fonction ne voient pas celles de la page : passe-les en paramètres.' },
        { text: 'include \'fonctions.php\'; exécute ce fichier à cet endroit, comme s\'il était recopié là. Les lignes grises en haut de tes fichiers, depuis la première semaine, c\'est la même chose : l\'hôtel inclut son registre, qui range $garde, $client… avant ta page.' }
      ],
      retenir: ['function nom($p) { return …; }', 'return rend une valeur ; echo l\'écrit tout de suite.', 'include \'fonctions.php\'; en haut des pages.'],
      exos: [
        { id: 'fn1', prompt: 'Écris une fonction double($n) qui rend 2 × $n, et écris double(21).', start: '<?php\n\n?>', ref: '<?php\nfunction double($n)\n{\n    return 2 * $n;\n}\necho double(21);\n?>', runs: [{}] },
        { id: 'fn2', prompt: 'Écris une fonction salut($nom) qui rend « Bonsoir » suivi du nom, et écris salut($client).', start: '<?php\n\n?>', ref: '<?php\nfunction salut($nom)\n{\n    return \'Bonsoir \' . $nom;\n}\necho salut($client);\n?>', runs: [{ reg: { client: 'Mme Roux' } }, { reg: { client: 'Lina' } }] }
      ]
    },
    {
      id: 'formulaires', letter: 'L', word: 'hotel', index: 4, title: 'Ce que le visiteur envoie',
      intro: 'Un formulaire en method="get" envoie ses champs dans l\'adresse : reservation.php?nom=Roux. PHP les trouve dans le tableau $_GET. Avant le premier envoi, la case n\'existe pas : isset() d\'abord. Et ce que tape le visiteur ne s\'écrit jamais sans htmlspecialchars().',
      blocks: [
        { code: '<form method="get">\n  <input name="nom"> <button>Envoyer</button>\n</form>\n<?php\nif (isset($_GET[\'nom\'])) {\n    echo \'<p>Bonsoir \' . htmlspecialchars($_GET[\'nom\']) . \'</p>\';\n}\n?>', run: { get: { nom: '<b>Dupont</b>' } }, note: 'Le visiteur a tapé <b>Dupont</b>. Enlève htmlspecialchars et regarde le navigateur.' }
      ],
      retenir: ['$_GET[\'champ\'] : ce que porte l\'adresse.', 'isset($_GET[\'champ\']) avant de le lire.', 'htmlspecialchars() avant d\'écrire ce que le visiteur a tapé.'],
      exos: [
        { id: 'form1', prompt: 'Si l\'adresse porte ?chambre=…, écris « Chambre » suivi du numéro (protégé) ; sinon « Quelle chambre ? ».', start: '<?php\n\n?>', ref: '<?php\nif (isset($_GET[\'chambre\'])) {\n    echo \'Chambre \' . htmlspecialchars($_GET[\'chambre\']);\n} else {\n    echo \'Quelle chambre ?\';\n}\n?>', runs: [{ get: { chambre: '12' } }, { get: {} }, { get: { chambre: '<i>404</i>' } }] },
        { id: 'form2', prompt: 'Écris « Merci » suivi du prénom envoyé (?prenom=…), protégé, ou « Merci » seul s\'il n\'y en a pas.', start: '<?php\n\n?>', ref: '<?php\nif (isset($_GET[\'prenom\'])) {\n    echo \'Merci \' . htmlspecialchars($_GET[\'prenom\']);\n} else {\n    echo \'Merci\';\n}\n?>', runs: [{ get: { prenom: 'Lina' } }, { get: {} }, { get: { prenom: '<script>x</script>' } }] }
      ]
    }
  ];
  var byId = {};
  notions.forEach(function (n) { byId[n.id] = n; });

  root.SDNCarnets = {
    all: function () { return notions; },
    get: function (id) { return byId[id]; },
    /** The two lines of the sign, as the game draws them: each letter with the notion it stands for. */
    sign: function () {
      var hotel = 'HÔTEL'.split('').map(function (c, i) { return { char: c, notion: notions.filter(function (n) { return n.word === 'hotel' && n.index === i; })[0].id }; });
      var quatre = 'QUATRE-VENTS'.split('').map(function (c, i) { var n = notions.filter(function (x) { return x.word === 'quatre' && x.index === i; })[0]; return { char: c, notion: n ? n.id : null }; });
      return { hotel: hotel, quatre: quatre };
    }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
