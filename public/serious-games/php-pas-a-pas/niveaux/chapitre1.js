/* PHP pas à pas — chapter 1, « Afficher et calculer »: echo, variables, calculations, assembling text.
   No reminder yet (they start at level 6) and no « Suivre » table: the programs have no loop to follow. */
(function (C) {
  'use strict';

  C.level({
    id: 'N01', chapter: 1, num: 1, title: 'Afficher du texte', summary: 'echo · "texte" · ;', notions: ['echo'],
    lessons: {
      echo: {
        title: 'Un programme qui parle : echo',
        html: `<p>Un programme PHP est une suite d'<b>instructions</b>, exécutées l'une après l'autre, de haut en bas. La première à connaître affiche du texte :</p>
<div class="rule"><span class="kw">echo</span> "Bonjour !";</div>
<p><code>echo</code> affiche ce qui suit. Un texte s'écrit <b>entre guillemets</b>. Chaque instruction se termine par un <b>point-virgule</b> <code>;</code> : c'est lui qui dit à PHP « l'instruction est finie ».</p>
<p>La ligne <code>&lt;?php</code>, en haut, est déjà écrite pour toi : elle annonce que la suite est du PHP.</p>`,
        example: 'echo "Bonjour !";\necho "Je découvre PHP.";',
        tryit: 'Exécute, puis change le texte du premier echo. Enlève un point-virgule et lis l\'erreur.'
      },
      lignes: {
        title: 'Aller à la ligne',
        html: `<p>Deux <code>echo</code> à la suite écrivent l'un derrière l'autre, sur la même ligne. Pour passer à la ligne, on écrit <code>\\n</code> dans le texte, entre guillemets doubles :</p>
<div class="rule"><span class="kw">echo</span> "Mammifères\\n";<br><span class="kw">echo</span> "Oiseaux\\n";</div>
<p><code>\\n</code> ne s'affiche pas : c'est un « retour à la ligne ». Dans ce chapitre, le jeu ne compte pas les espaces ni les retours à la ligne : seuls les mots comptent.</p>`,
        example: 'echo "Mammifères\\n";\necho "Oiseaux\\n";\necho "Reptiles";',
        tryit: 'Enlève les \\n : que devient l\'affichage ?'
      }
    },
    steps: {
      predire: { before: 'echo', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: 'echo "Bon";\necho "jour";', options: ['Bonjour', 'Bon jour', 'echo Bon echo jour', '"Bon" "jour"'], hints: ['Le premier echo affiche Bon, le second affiche jour juste derrière : rien ne les sépare.'] },
      comprendre: {},
      completer: { before: 'lignes', kind: 'complete', fixed: true, ask: 'Affiche le message « Bienvenue au muséum ! ».', template: '[[]] "Bienvenue au muséum !"[[]]', fill: ['echo', ';'], words: ['echo', ';', '"', 'print'], ref: 'echo "Bienvenue au muséum !";', hints: ['Il manque le mot qui affiche, et ce qui termine une instruction.', 'echo "…" ;'] },
      reparer: { kind: 'repair', fixed: true, ask: 'Ce programme doit afficher « Salle des fossiles », puis « Entrée libre ». PHP refuse de l\'exécuter : répare-le.', starter: 'echo "Salle des fossiles\\n"\necho "Entrée libre";', ref: 'echo "Salle des fossiles\\n";\necho "Entrée libre";', hints: ['Exécute-le et lis le message : il parle de la ligne 3, mais le problème est au bout de la ligne 2.', 'Chaque instruction finit par ;'] },
      ecrire1: { kind: 'write', fixed: true, ask: 'Affiche « PHP pas à pas ».', ref: 'echo "PHP pas à pas";', hints: ['Une seule instruction : echo, le texte entre guillemets, le point-virgule.', 'echo "…";'] },
      ecrire2: { kind: 'write', fixed: true, ask: 'Affiche « Ouvert » sur une ligne, puis « de 9 h à 18 h » sur la suivante.', ref: 'echo "Ouvert\\n";\necho "de 9 h à 18 h";', hints: ['Deux echo, ou un seul avec \\n au milieu.', 'echo "Ouvert\\n";\necho "…";'] },
      ecrire3: { kind: 'write', fixed: true, ask: 'Affiche les trois salles du muséum, une par ligne : « Fossiles », « Oiseaux », « Minéraux ».', ref: 'echo "Fossiles\\n";\necho "Oiseaux\\n";\necho "Minéraux";', hints: ['Trois echo, chacun avec son texte et son \\n.', 'echo "Fossiles\\n";\necho "…\\n";\necho "…";'] }
    }
  });

  C.level({
    id: 'N02', chapter: 1, num: 2, title: 'Les variables', summary: '$nom = valeur · changer · réutiliser', notions: ['variables'],
    lessons: {
      variable: {
        title: 'Ranger une valeur dans une variable',
        html: `<p>Une <b>variable</b> est une boîte qui porte un nom et contient une valeur. En PHP, son nom commence toujours par <code>$</code> :</p>
<div class="rule">$prenom = "Léa";<br>$age = 17;<br><span class="kw">echo</span> $prenom;</div>
<p><code>=</code> veut dire « range la valeur de droite dans la boîte de gauche ». Un texte s'écrit entre guillemets, un nombre tel quel. Pour afficher la valeur, on écrit le nom de la variable, <b>sans guillemets</b>.</p>
<p>Une variable peut changer : <code>$age = 18;</code> remplace l'ancienne valeur. Le programme lit toujours la valeur <b>du moment</b>, ligne après ligne.</p>`,
        example: '$prenom = "Léa";\n$age = 17;\necho $prenom;\necho "\\n";\necho $age;',
        tryit: 'Ajoute $age = 18; juste avant le dernier echo. Ouvre « Pas à pas » pour voir la boîte changer.'
      },
      preparees: {
        title: 'Les lignes grises',
        html: `<p>Dans les exercices, le jeu <b>prépare des variables</b> pour toi. Elles sont écrites en gris en haut du programme, et tu ne peux pas les modifier : c'est la situation de départ.</p>
<p>Quand tu cliques sur « Vérifier », le jeu essaie ton programme avec <b>d'autres valeurs</b> dans ces lignes grises (les « cas cachés »). Un programme qui écrit <code>echo "Léa";</code> au lieu de <code>echo $prenom;</code> marche une fois, puis se trompe dès que le prénom change.</p>`,
        example: 'echo "Bonjour ";\necho $prenom;',
        vars: { prenom: 'Léa' },
        tryit: 'Le prénom vient de la ligne grise. Écris echo $prenom; une deuxième fois.'
      }
    },
    steps: {
      predire: { before: 'variable', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: '$stock = 5;\n$stock = 8;\necho $stock;', options: ['5', '8', '58', '13'], hints: ['La deuxième ligne remplace la valeur de la boîte : que contient-elle au moment du echo ?'] },
      comprendre: {},
      completer: { before: 'preparees', kind: 'complete', ask: 'Affiche « Bonjour », puis le prénom préparé.', template: 'echo "Bonjour ";\n[[]] [[]];', fill: ['echo', '$prenom'], words: ['echo', '$prenom', 'prenom', '"prenom"', ';'], ref: 'echo "Bonjour ";\necho $prenom;', vars: { prenom: 'Léa' }, hidden: [{ vars: { prenom: 'Tom' } }, { vars: { prenom: 'Zoé' } }], hints: ['Le premier trou affiche, le second est la variable préparée.', 'echo $…;'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher la salle préparée. PHP s\'arrête sur une erreur : répare-le.', starter: 'echo salle;', ref: 'echo $salle;', vars: { salle: 'R3' }, hidden: [{ vars: { salle: 'R12' } }, { vars: { salle: 'Galerie' } }], traps: ['echo "R3";'], hints: ['Lis l\'explication de l\'erreur : PHP ne sait pas ce qu\'est « salle ».', 'Une variable s\'écrit toujours avec $ devant.'] },
      ecrire1: { kind: 'write', ask: 'Affiche l\'année préparée, puis la salle sur la ligne suivante.', ref: 'echo $annee;\necho "\\n";\necho $salle;', vars: { annee: 1912, salle: 'R3' }, hidden: [{ vars: { annee: 2004, salle: 'R1' } }, { vars: { annee: 1958, salle: 'Réserve' } }], traps: ['echo "1912\\nR3";'], hints: ['Deux variables à afficher, avec un retour à la ligne entre les deux.', 'echo $annee;\necho "\\n";\necho …;'] },
      ecrire2: { kind: 'write', ask: 'Crée une variable $total qui reçoit la valeur de $prix, puis affiche $total.', ref: '$total = $prix;\necho $total;', vars: { prix: 12 }, hidden: [{ vars: { prix: 7 } }, { vars: { prix: 30 } }], hints: ['Une ligne pour ranger, une ligne pour afficher.', '$total = …;\necho $total;'] },
      ecrire3: { kind: 'write', ask: 'Range $prenom dans une variable $auteur. Change ensuite $prenom en « Anonyme ». Affiche enfin $auteur : que reste-t-il dans la boîte ?', ref: '$auteur = $prenom;\n$prenom = "Anonyme";\necho $auteur;', vars: { prenom: 'Léa' }, hidden: [{ vars: { prenom: 'Tom' } }, { vars: { prenom: 'Inès' } }], hints: ['Trois lignes, dans l\'ordre de la consigne. Ouvre « Pas à pas » pour voir les deux boîtes.', '$auteur = $prenom;\n$prenom = "…";\necho …;'] }
    }
  });

  C.mastery({
    id: 'M01', chapter: 1, title: 'echo et variables', covers: ['N01', 'N02'], draw: 3,
    pool: [
      { ask: 'Affiche le nom du spécimen préparé.', ref: 'echo $nom;', vars: { nom: 'Trilobite' }, hidden: [{ vars: { nom: 'Ammonite' } }, { vars: { nom: 'Loup gris' } }], hints: ['Une seule instruction.', 'echo $…;'] },
      { ask: 'Affiche « Salle : », puis la salle préparée.', ref: 'echo "Salle : ";\necho $salle;', vars: { salle: 'R2' }, hidden: [{ vars: { salle: 'R5' } }, { vars: { salle: 'Réserve' } }], hints: ['Un echo pour le texte, un pour la variable.', 'echo "Salle : ";\necho …;'] },
      { ask: 'Affiche le prénom, puis l\'âge sur la ligne suivante.', ref: 'echo $prenom;\necho "\\n";\necho $age;', vars: { prenom: 'Léa', age: 17 }, hidden: [{ vars: { prenom: 'Tom', age: 19 } }, { vars: { prenom: 'Zoé', age: 16 } }], hints: ['Deux variables et un \\n entre elles.', 'echo $prenom;\necho "\\n";\necho …;'] },
      { ask: 'Range $ville dans $depart, puis affiche $depart deux fois, une par ligne.', ref: '$depart = $ville;\necho $depart;\necho "\\n";\necho $depart;', vars: { ville: 'Limoges' }, hidden: [{ vars: { ville: 'Brive' } }, { vars: { ville: 'Tulle' } }], hints: ['Range d\'abord, affiche ensuite.', '$depart = $ville;\necho $depart;\necho "\\n";\necho …;'] },
      { ask: 'Change la valeur de $etat en « fermé », puis affiche $salle et $etat sur deux lignes.', ref: '$etat = "fermé";\necho $salle;\necho "\\n";\necho $etat;', vars: { salle: 'R4', etat: 'ouvert' }, hidden: [{ vars: { salle: 'R1', etat: 'ouvert' } }, { vars: { salle: 'Hall', etat: 'travaux' } }], hints: ['Une affectation, puis deux echo.', '$etat = "fermé";\necho $salle;\necho "\\n";\necho $etat;'] },
      { ask: 'Affiche l\'inventaire, puis le nom, puis l\'année, une valeur par ligne.', ref: 'echo $inventaire . "\\n";\necho $nom . "\\n";\necho $annee;', vars: { inventaire: 'MHN-0142', nom: 'Trilobite', annee: 1911 }, hidden: [{ vars: { inventaire: 'MHN-0007', nom: 'Ammonite', annee: 1950 } }, { vars: { inventaire: 'MHN-1200', nom: 'Grand pingouin', annee: 1844 } }], hints: ['Trois valeurs, deux retours à la ligne.', 'echo $inventaire;\necho "\\n";\n…'] },
      { ask: 'Échange : range $a dans $b et $b dans $a, en passant par une troisième variable $c, puis affiche $a et $b sur deux lignes.', ref: '$c = $a;\n$a = $b;\n$b = $c;\necho $a;\necho "\\n";\necho $b;', vars: { a: 'Oiseaux', b: 'Reptiles' }, hidden: [{ vars: { a: 'R1', b: 'R2' } }, { vars: { a: 'jour', b: 'nuit' } }], hints: ['Si tu écris $a = $b; tout de suite, l\'ancienne valeur de $a est perdue : garde-la d\'abord dans $c.', '$c = $a;\n$a = $b;\n$b = $c;'] },
      { ask: 'Affiche « Bienvenue », puis le prénom préparé, puis « ! », sur une seule ligne.', ref: 'echo "Bienvenue ";\necho $prenom;\necho " !";', vars: { prenom: 'Léa' }, hidden: [{ vars: { prenom: 'Tom' } }, { vars: { prenom: 'Inès' } }], hints: ['Trois echo à la suite, sans \\n.', 'echo "Bienvenue ";\necho $prenom;\necho …;'] },
      { ask: 'La variable $note vaut une première note. Range-la dans $ancienne, remplace $note par 20, puis affiche $ancienne puis $note.', ref: '$ancienne = $note;\n$note = 20;\necho $ancienne;\necho "\\n";\necho $note;', vars: { note: 12 }, hidden: [{ vars: { note: 8 } }, { vars: { note: 15 } }], hints: ['Garde l\'ancienne valeur avant de la remplacer.', '$ancienne = $note;\n$note = 20;\n…'] }
    ]
  });

  C.level({
    id: 'N03', chapter: 1, num: 3, title: 'Calculer', summary: '+ - * / % · priorités · intdiv · round', notions: ['calculs'],
    lessons: {
      operateurs: {
        title: 'Les calculs',
        html: `<p>PHP calcule avec <code>+</code>, <code>-</code>, <code>*</code> (multiplier) et <code>/</code> (diviser). Comme en mathématiques, <b>la multiplication et la division passent avant</b> l'addition et la soustraction ; les parenthèses passent avant tout :</p>
<div class="rule"><span class="kw">echo</span> 2 + 3 * 4;    <span class="c">// 14</span><br><span class="kw">echo</span> (2 + 3) * 4;  <span class="c">// 20</span></div>
<p>Un calcul peut utiliser des variables, et son résultat peut être rangé : <code>$total = $prix * $quantite;</code></p>
<p>Les lignes qui commencent par <code>//</code> sont des <b>commentaires</b> : PHP les ignore.</p>`,
        example: '$prix = 8;\n$quantite = 3;\n$total = $prix * $quantite;\necho $total;',
        tryit: 'Ajoute 2 euros de frais : $total = $prix * $quantite + 2;'
      },
      division: {
        title: 'Diviser, garder le reste, arrondir',
        html: `<p>En PHP, <code>/</code> donne le résultat exact, avec ses décimales : <code>7 / 2</code> vaut <code>3.5</code> (un point, pas une virgule).</p>
<ul><li><code>intdiv(7, 2)</code> donne la <b>division entière</b> : 3.</li><li><code>7 % 2</code> donne le <b>reste</b> : 1. C'est le « modulo ».</li><li><code>round(2.456, 1)</code> <b>arrondit</b> à 1 décimale : 2.5.</li></ul>
<div class="rule">$minutes = 135;<br><span class="kw">echo</span> intdiv($minutes, 60);  <span class="c">// 2 heures</span><br><span class="kw">echo</span> $minutes % 60;         <span class="c">// et 15 minutes</span></div>`,
        example: '$minutes = 135;\necho intdiv($minutes, 60);\necho "\\n";\necho $minutes % 60;',
        tryit: 'Essaie avec 59 minutes, puis avec 60.'
      }
    },
    steps: {
      predire: { before: 'operateurs', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: 'echo 2 + 3 * 4;', options: ['20', '14', '24', '9'], hints: ['La multiplication passe avant l\'addition.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Affiche le prix total de $quantite billets à $prix euros chacun.', template: 'echo [[]] [[]] [[]];', fill: ['$quantite', '*', '$prix'], words: ['$quantite', '$prix', '*', '+', '/', 'x'], ref: 'echo $quantite * $prix;', vars: { quantite: 3, prix: 8 }, hidden: [{ vars: { quantite: 1, prix: 12 } }, { vars: { quantite: 5, prix: 6.5 } }], hints: ['Le prix total, c\'est le nombre de billets multiplié par le prix d\'un billet. Multiplier s\'écrit *.', 'echo $quantite * …;'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher la moyenne des deux notes $a et $b. Avec 12 et 16, il affiche 20 au lieu de 14 : répare-le.', starter: 'echo $a + $b / 2;', ref: 'echo ($a + $b) / 2;', vars: { a: 12, b: 16 }, hidden: [{ vars: { a: 9, b: 10 } }, { vars: { a: 0, b: 20 } }], hints: ['Qu\'est-ce que PHP calcule en premier, l\'addition ou la division ?', 'echo (… + …) / 2;'] },
      ecrire1: { before: 'division', kind: 'write', ask: 'Affiche le nombre de semaines complètes contenues dans $jours jours.', ref: 'echo intdiv($jours, 7);', vars: { jours: 30 }, hidden: [{ vars: { jours: 7 }, label: '$jours = 7 (pile une semaine)' }, { vars: { jours: 6 }, label: '$jours = 6 (pas une semaine entière)' }, { vars: { jours: 100 } }], traps: ['echo round($jours / 7);'], hints: ['Une semaine, c\'est 7 jours. Tu veux la division entière, sans décimales.', 'echo intdiv($jours, …);'] },
      ecrire2: { kind: 'write', ask: 'Affiche le nombre de jours qui restent en plus des semaines complètes de $jours jours.', ref: 'echo $jours % 7;', vars: { jours: 30 }, hidden: [{ vars: { jours: 7 }, label: '$jours = 7 (aucun jour en plus)' }, { vars: { jours: 13 } }, { vars: { jours: 100 } }], hints: ['C\'est le reste de la division par 7.', 'echo $jours % …;'] },
      ecrire3: { kind: 'write', ask: 'Affiche le prix TTC : le prix hors taxe $ht augmenté de 20 %, arrondi à 2 décimales.', ref: 'echo round($ht * 1.2, 2);', vars: { ht: 10 }, hidden: [{ vars: { ht: 12.5 } }, { vars: { ht: 9.99 }, label: '$ht = 9.99 (un résultat à arrondir)' }], traps: ['echo $ht * 1.2;'], hints: ['Augmenter de 20 %, c\'est multiplier par 1.2. Puis arrondir avec round.', 'echo round($ht * 1.2, …);'] }
    }
  });

  C.level({
    id: 'N04', chapter: 1, num: 4, title: 'Assembler un texte', summary: '. · "…$variable…" · \'…\'', notions: ['concat'],
    lessons: {
      point: {
        title: 'Le point colle les textes',
        html: `<p>Le <b>point</b> <code>.</code> assemble des textes et des valeurs en un seul texte :</p>
<div class="rule"><span class="kw">echo</span> "Bonjour " . $prenom . " !";</div>
<p>Pense aux espaces : ils font partie des textes entre guillemets. <code>"Bonjour" . $prenom</code> colle tout : « BonjourLéa ».</p>
<p>Attention : <code>+</code> <b>calcule</b>, <code>.</code> <b>assemble</b>. <code>"Total : " + $total</code> est une erreur, car PHP ne sait pas additionner un texte.</p>`,
        example: '$prenom = "Léa";\n$age = 17;\necho $prenom . " a " . $age . " ans.";',
        tryit: 'Enlève les espaces dans " a " et regarde le résultat. Remplace un point par + et lis l\'erreur.'
      },
      guillemets: {
        title: 'Guillemets doubles et apostrophes',
        html: `<p>Entre <b>guillemets doubles</b>, PHP remplace les variables par leur valeur : <code>"Bonjour $prenom"</code> affiche « Bonjour Léa ».</p>
<p>Entre <b>apostrophes</b>, rien n'est remplacé : <code>'Bonjour $prenom'</code> affiche « Bonjour $prenom », tel quel. Et <code>\\n</code> n'y fait pas de retour à la ligne.</p>
<p>Le point marche dans les deux cas : <code>'Bonjour ' . $prenom</code>. C'est la façon la plus sûre.</p>`,
        example: '$prenom = "Léa";\necho "Bonjour $prenom\\n";\necho \'Bonjour $prenom\';',
        tryit: 'Quelle ligne affiche la valeur, laquelle affiche le nom de la variable ?'
      }
    },
    steps: {
      predire: { before: 'guillemets', kind: 'choose', ask: 'Sans l\'exécuter : qu\'affiche ce programme ?', code: '$n = 3;\necho \'Il y a $n fossiles\';', options: ['Il y a 3 fossiles', 'Il y a $n fossiles', 'Il y a n fossiles', 'Il y a fossiles'], hints: ['Regarde bien : le texte est entre apostrophes, pas entre guillemets doubles.'] },
      comprendre: {},
      completer: { before: 'point', kind: 'complete', ask: 'Affiche « Bonjour Léa ! » avec le prénom préparé.', template: 'echo "Bonjour " [[]] $prenom [[]] " !";', fill: ['.', '.'], words: ['.', '+', ',', '&'], ref: 'echo "Bonjour " . $prenom . " !";', vars: { prenom: 'Léa' }, hidden: [{ vars: { prenom: 'Tom' } }, { vars: { prenom: 'Inès' } }], hints: ['Il faut coller trois morceaux : quel signe assemble du texte ?', 'echo "Bonjour " . $prenom . …;'] },
      reparer: { kind: 'repair', ask: 'Ce programme doit afficher « Total : 12 € » avec le total préparé. Il s\'arrête sur une erreur : répare-le.', starter: 'echo "Total : " + $total . " €";', ref: 'echo "Total : " . $total . " €";', vars: { total: 12 }, hidden: [{ vars: { total: 0 } }, { vars: { total: 7.5 } }], traps: ['echo "Total : 12 €";'], hints: ['Lis l\'erreur : PHP essaie de calculer avec un texte.', 'Le signe qui assemble est le point.'] },
      ecrire1: { kind: 'write', ask: 'Affiche « Léa a 17 ans. » avec le prénom et l\'âge préparés.', ref: 'echo $prenom . " a " . $age . " ans.";', vars: { prenom: 'Léa', age: 17 }, hidden: [{ vars: { prenom: 'Tom', age: 19 } }, { vars: { prenom: 'Zoé', age: 16 } }], traps: ['echo "Léa a " . $age . " ans.";'], hints: ['Quatre morceaux : le prénom, « a », l\'âge, « ans. ». Attention aux espaces.', 'echo $prenom . " a " . … . " ans.";'] },
      ecrire2: { kind: 'write', ask: 'Affiche le spécimen sous la forme « Trilobite (salle R3) ».', ref: 'echo $nom . " (salle " . $salle . ")";', vars: { nom: 'Trilobite', salle: 'R3' }, hidden: [{ vars: { nom: 'Ammonite', salle: 'R1' } }, { vars: { nom: 'Loup gris', salle: 'R4' } }], hints: ['Le nom, puis « (salle », la salle, et la parenthèse fermante.', 'echo $nom . " (salle " . … . ")";'] },
      ecrire3: { kind: 'write', ask: 'Affiche « 3 billets : 24 € », où 3 est $quantite et 24 le prix total ($quantite fois $prix).', ref: 'echo $quantite . " billets : " . $quantite * $prix . " €";', vars: { quantite: 3, prix: 8 }, hidden: [{ vars: { quantite: 1, prix: 12 } }, { vars: { quantite: 4, prix: 5 } }], hints: ['Le calcul peut se faire dans une variable d\'abord : $total = $quantite * $prix;', '$total = $quantite * $prix;\necho $quantite . " billets : " . $total . " €";'] }
    }
  });

  C.mastery({
    id: 'M02', chapter: 1, title: 'calculer et assembler', covers: ['N03', 'N04'], draw: 3,
    pool: [
      { ask: 'Affiche la somme de $a et $b.', ref: 'echo $a + $b;', vars: { a: 12, b: 30 }, hidden: [{ vars: { a: 0, b: 7 } }, { vars: { a: -3, b: 3 } }], hints: ['Additionner s\'écrit +.', 'echo $a + …;'] },
      { ask: 'Affiche le périmètre d\'un rectangle de longueur $l et de largeur $w : deux fois la somme des deux.', ref: 'echo 2 * ($l + $w);', vars: { l: 5, w: 3 }, hidden: [{ vars: { l: 10, w: 1 } }, { vars: { l: 4, w: 4 } }], hints: ['Attention aux priorités : la somme d\'abord, entre parenthèses.', 'echo 2 * (… + …);'] },
      { ask: 'Affiche « Bonjour Léa » (avec le prénom préparé).', ref: 'echo "Bonjour " . $prenom;', vars: { prenom: 'Léa' }, hidden: [{ vars: { prenom: 'Tom' } }, { vars: { prenom: 'Inès' } }], hints: ['Le texte, un espace, puis la variable, collés par un point.', 'echo "Bonjour " . …;'] },
      { ask: 'Affiche le nombre d\'heures complètes dans $minutes minutes.', ref: 'echo intdiv($minutes, 60);', vars: { minutes: 135 }, hidden: [{ vars: { minutes: 60 } }, { vars: { minutes: 59 } }], hints: ['Une heure, c\'est 60 minutes : la division entière.', 'echo intdiv($minutes, …);'] },
      { ask: 'Affiche une durée de $minutes minutes sous la forme « 2 h 15 ».', ref: 'echo intdiv($minutes, 60) . " h " . $minutes % 60;', vars: { minutes: 135 }, hidden: [{ vars: { minutes: 60 } }, { vars: { minutes: 7 } }], hints: ['Les heures avec intdiv, les minutes restantes avec %.', 'echo intdiv($minutes, 60) . " h " . …;'] },
      { ask: 'Affiche la moyenne de $a, $b et $c, arrondie à une décimale.', ref: 'echo round(($a + $b + $c) / 3, 1);', vars: { a: 12, b: 15, c: 10 }, hidden: [{ vars: { a: 10, b: 10, c: 11 } }, { vars: { a: 20, b: 0, c: 9 } }], hints: ['La somme entre parenthèses, divisée par 3, puis arrondie.', 'echo round((… + … + …) / 3, 1);'] },
      { ask: 'Affiche « Trilobite : 1911 » avec le nom et l\'année préparés.', ref: 'echo $nom . " : " . $annee;', vars: { nom: 'Trilobite', annee: 1911 }, hidden: [{ vars: { nom: 'Ammonite', annee: 1950 } }, { vars: { nom: 'Loup gris', annee: 2004 } }], hints: ['Le nom, « : », l\'année.', 'echo $nom . " : " . …;'] },
      { ask: 'Affiche l\'âge du spécimen en $actuelle s\'il est entré au muséum en $entree, sous la forme « 113 ans ».', ref: 'echo $actuelle - $entree . " ans";', vars: { actuelle: 2026, entree: 1913 }, hidden: [{ vars: { actuelle: 2026, entree: 2025 } }, { vars: { actuelle: 2030, entree: 1950 } }], hints: ['Une soustraction, puis le texte « ans » collé avec un point.', '$age = $actuelle - $entree;\necho $age . " ans";'] },
      { ask: 'Affiche le prix réduit : $prix moins 10 %, arrondi à 2 décimales.', ref: 'echo round($prix * 0.9, 2);', vars: { prix: 20 }, hidden: [{ vars: { prix: 9.99 } }, { vars: { prix: 3 } }], hints: ['Enlever 10 %, c\'est multiplier par 0.9.', 'echo round($prix * 0.9, …);'] }
    ]
  });
})(window.PPPContent);
