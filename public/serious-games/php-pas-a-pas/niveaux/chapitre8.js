/* PHP pas à pas — chapter 8, « PHP dans une page »: PHP islands in an HTML page, loops and conditions that
   write HTML, then the address ($_GET), default values and safe display (htmlspecialchars, is_numeric).
   The chapter compares in « html » mode and every program starts in HTML mode, right after the grey lines:
   the references therefore always open on an HTML tag. A « Suivre » table (`cases`) runs in PHP mode, so
   its snippet opens with `<?php`. The final test is appended at the end of the file by the game's author. */
(function (C) {
  'use strict';

  /* Misconceptions shared by the address exercises (tested on the code, after a failed check). */
  var NO_ESCAPE = { out: function (o, code) { return /\$_GET/.test(code) && !/htmlspecialchars/.test(code); }, text: 'Tout ce qui vient de l’adresse peut contenir du HTML (<code>&lt;b&gt;</code>, <code>&lt;script&gt;</code>) : passe-le par <code>htmlspecialchars()</code> avant de l’afficher.' };
  var NO_DEFAULT = { out: function (o, code) { return /\$_GET/.test(code) && !/\?\?/.test(code) && !/isset\s*\(/.test(code); }, text: 'Un paramètre peut manquer dans l’adresse : lis-le avec <code>$_GET[\'nom\'] ?? \'valeur par défaut\'</code>, ou teste-le avec <code>isset()</code>.' };
  var INT_ONLY = { out: function (o, code) { return /\(int\)/.test(code) && !/is_numeric/.test(code); }, text: '<code>(int) \'abc\'</code> donne 0 sans prévenir : vérifie d’abord avec <code>is_numeric()</code> que c’est bien un nombre.' };
  var CLOSE_IN_LOOP = { re: /\{\s*\?>[\s\S]*<\/(?:ul|ol|table|select)>[\s\S]*<\?php\s*\}/, text: 'La balise fermante est dans la boucle : elle est écrite à chaque tour. Ce qui n’apparaît qu’une fois va avant ou après la boucle.' };

  C.level({
    id: 'N51', chapter: 8, num: 51, title: 'Du PHP dans du HTML', summary: '<?php ?> · <?= ?> · une boucle écrit les lignes', notions: ['html'],
    lessons: {
      ilots: {
        title: 'Une page, deux langues',
        html: `<p>Jusqu'ici, ton programme affichait du texte. Sur un site, PHP sert à fabriquer une <b>page HTML</b>. Le fichier mélange deux langues :</p>
<ul><li>tout ce qui est <b>hors des balises PHP</b> est du HTML, envoyé tel quel au navigateur ;</li><li>ce qui est entre <code>&lt;?php</code> et <code>?&gt;</code> est du PHP, exécuté par le serveur. Seul ce qu'il affiche part dans la page.</li></ul>
<div class="rule">&lt;h1&gt;Salle &lt;?php <span class="kw">echo</span> $salle; ?&gt;&lt;/h1&gt;<br>&lt;p&gt;Salle &lt;?= $salle ?&gt;&lt;/p&gt;</div>
<p><code>&lt;?= … ?&gt;</code> est un raccourci de <code>&lt;?php echo …; ?&gt;</code> : il affiche une valeur au milieu du HTML, sans point-virgule.</p>
<p>Dans ce chapitre, ton programme <b>commence en HTML</b>, juste après les lignes grises. Le navigateur ne reçoit jamais le PHP : seulement la page fabriquée. Le jeu compare ta page à la page attendue sans compter les retours à la ligne entre les balises.</p>`,
        example: '<h1>Muséum</h1>\n<p>Salle du jour : <?= $salle ?></p>\n<?php $vitrines = 4 * 3; ?>\n<p><?= $vitrines ?> vitrines</p>',
        vars: { salle: 'R2' },
        tryit: 'Remplace 4 * 3 par 5 * 3 et exécute. Regarde la page reçue : plus aucune trace de PHP.'
      },
      boucle: {
        title: 'Une boucle qui écrit du HTML',
        html: `<p>Une boucle peut écrire une partie de la page : une ligne de liste par salle, une ligne de tableau par spécimen. On ouvre la boucle en PHP, on <b>referme la balise PHP</b>, on écrit le HTML d'<b>un</b> tour, puis on rouvre PHP pour fermer l'accolade :</p>
<div class="rule">&lt;ul&gt;<br>&lt;?php <span class="kw">foreach</span> ($salles <span class="kw">as</span> $s) { ?&gt;<br>  &lt;li&gt;&lt;?= $s ?&gt;&lt;/li&gt;<br>&lt;?php } ?&gt;<br>&lt;/ul&gt;</div>
<p>Ce qui est entre <code>{ ?&gt;</code> et <code>&lt;?php }</code> est écrit <b>à chaque tour</b>. Le piège : mettre <code>&lt;ul&gt;</code> ou <code>&lt;/ul&gt;</code> dans la boucle, ce qui fabrique une liste par salle. Ce qui n'apparaît qu'une fois va <b>avant</b> ou <b>après</b> la boucle.</p>
<p>Pour un tableau de tableaux : une ligne <code>&lt;tr&gt;</code> par enregistrement, une case <code>&lt;td&gt;</code> par valeur, <code>&lt;td&gt;&lt;?= $sp['nom'] ?&gt;&lt;/td&gt;</code>. Une condition marche pareil : le HTML entre <code>&lt;?php if (…) { ?&gt;</code> et <code>&lt;?php } ?&gt;</code> n'est écrit que si la condition est vraie.</p>`,
        example: `<table>
<?php foreach ($specimens as $sp) { ?>
  <tr>
    <td><?= $sp['nom'] ?></td>
    <td><?= $sp['valeur'] ?> €<?php if ($sp['valeur'] >= 1000) { ?> (pièce rare)<?php } ?></td>
  </tr>
<?php } ?>
</table>`,
        vars: { specimens: [{ nom: 'Trilobite', valeur: 450 }, { nom: 'Loup gris', valeur: 1200 }, { nom: 'Quartz rose', valeur: 80 }] },
        tryit: 'Déplace </table> juste avant <?php } ?> : combien de tableaux obtiens-tu ?'
      }
    },
    steps: {
      predire: { before: 'ilots', kind: 'choose', ask: 'Sans l\'exécuter : quelle page le navigateur reçoit-il ?', code: '<h1>Salle <?= $salle ?></h1>\n<?php $vitrines = 4 + 2; ?>\n<p><?= $vitrines ?> vitrines</p>', vars: { salle: 'R4' }, options: ['<h1>Salle R4</h1> <p>6 vitrines</p>', '<h1>Salle <?= $salle ?></h1> <p><?= $vitrines ?> vitrines</p>', '<h1>Salle R4</h1> <?php $vitrines = 4 + 2; ?> <p>6 vitrines</p>', '<h1>Salle $salle</h1> <p>4 + 2 vitrines</p>'], hints: ['Le PHP est exécuté par le serveur : le navigateur ne reçoit que le HTML et ce que <?= ?> a affiché.'] },
      comprendre: {},
      completer: {
        before: 'boucle', kind: 'complete', ask: 'Écris la liste des salles : <ul>, puis un <li> par salle de $salles, puis </ul>.',
        template: '<ul>\n<?php [[]] ($salles as $s) { ?>\n  <li>[[]]</li>\n<?php [[]] ?>\n</ul>',
        fill: ['foreach', '<?= $s ?>', '}'], words: ['foreach', 'for', '<?= $s ?>', '<?= $salles ?>', '$s', '}', '{', '</ul>'],
        ref: '<ul>\n<?php foreach ($salles as $s) { ?>\n  <li><?= $s ?></li>\n<?php } ?>\n</ul>',
        vars: { salles: ['R1', 'R3', 'R5'] },
        hidden: [{ vars: { salles: ['R2'] }, label: 'une seule salle' }, { vars: { salles: [] }, label: 'aucune salle : la liste reste vide' }, { vars: { salles: ['R4', 'R2', 'R1', 'R5'] } }],
        hints: ['La boucle parcourt $salles ; entre { ?> et <?php }, le <li> du tour affiche $s.', '<?php foreach (… as $s) { ?>\n  <li><?= … ?></li>\n<?php } ?>']
      },
      reparer: {
        kind: 'repair', ask: 'Cette page doit afficher UNE liste <ul> avec un <li> par spécimen. Elle fabrique une liste par spécimen : répare-la.',
        starter: '<h2>Spécimens</h2>\n<?php foreach ($noms as $nom) { ?>\n<ul>\n  <li><?= $nom ?></li>\n</ul>\n<?php } ?>',
        ref: '<h2>Spécimens</h2>\n<ul>\n<?php foreach ($noms as $nom) { ?>\n  <li><?= $nom ?></li>\n<?php } ?>\n</ul>',
        vars: { noms: ['Trilobite', 'Loup gris', 'Quartz rose'] },
        hidden: [{ vars: { noms: [] }, label: 'aucun spécimen : une liste vide' }, { vars: { noms: ['Ammonite', 'Grand pingouin'] } }, { vars: { noms: ['Ammonite'] }, label: 'un seul spécimen' }],
        traps: ['<h2>Spécimens</h2>\n<ul>\n<?php for ($i = 0; $i < 3; $i++) { ?>\n  <li><?= $noms[$i] ?></li>\n<?php } ?>\n</ul>'],
        ir: [CLOSE_IN_LOOP, { re: /<\s*3\s*;/, text: 'La liste n’a pas toujours 3 noms : laisse <code>foreach</code> faire le compte.' }],
        hints: ['Ce qui ne doit apparaître qu\'une fois va avant ou après la boucle ; seul le <li> se répète.', '<ul>\n<?php foreach (…) { ?>\n  <li>…</li>\n<?php } ?>\n</ul>']
      },
      ecrire1: {
        kind: 'write', ask: 'Affiche le tableau des spécimens : <table>, puis pour chaque spécimen une ligne <tr> avec deux cases <td> (son nom, puis sa salle), puis </table>.',
        ref: '<table>\n<?php foreach ($specimens as $sp) { ?>\n  <tr><td><?= $sp[\'nom\'] ?></td><td><?= $sp[\'salle\'] ?></td></tr>\n<?php } ?>\n</table>',
        vars: { specimens: [{ nom: 'Trilobite', salle: 'R1' }, { nom: 'Loup gris', salle: 'R3' }, { nom: 'Quartz rose', salle: 'R5' }] },
        hidden: [{ vars: { specimens: [{ nom: 'Ammonite', salle: 'R2' }] }, label: 'un seul spécimen' }, { vars: { specimens: [] }, label: 'aucun spécimen : un tableau vide' }, { vars: { specimens: [{ nom: 'Hibou grand-duc', salle: 'R4' }, { nom: 'Améthyste', salle: 'R5' }, { nom: 'Iguane', salle: 'R2' }, { nom: 'Mammouth', salle: 'R1' }] } }],
        traps: ['<table>\n<?php for ($i = 0; $i < 3; $i++) { ?>\n  <tr><td><?= $specimens[$i][\'nom\'] ?></td><td><?= $specimens[$i][\'salle\'] ?></td></tr>\n<?php } ?>\n</table>'],
        ir: [CLOSE_IN_LOOP, { re: /<\s*3\s*;/, text: 'Il n’y a pas toujours 3 spécimens : parcours la liste avec <code>foreach</code>.' }],
        hints: ['Une ligne <tr> par tour de boucle : $sp est l\'enregistrement du tour, $sp[\'nom\'] son nom.', '<table>\n<?php foreach ($specimens as $sp) { ?>\n  <tr><td><?= … ?></td><td><?= … ?></td></tr>\n<?php } ?>\n</table>']
      },
      ecrire2: {
        kind: 'write', ask: 'Affiche les élèves dans une liste <ul> : un <li> par élève, sous la forme « Léa : 14 ». Si la note est sous la moyenne (moins de 10), le <li> porte la classe alerte : <li class="alerte">Tom : 8</li>.',
        ref: `<ul>
<?php foreach ($eleves as $e) { ?>
<?php if ($e['note'] < 10) { ?>
  <li class="alerte"><?= $e['nom'] ?> : <?= $e['note'] ?></li>
<?php } else { ?>
  <li><?= $e['nom'] ?> : <?= $e['note'] ?></li>
<?php } ?>
<?php } ?>
</ul>`,
        vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 8 }, { nom: 'Inès', note: 17 }] },
        hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 10 }, { nom: 'Nora', note: 9 }] }, label: 'Hugo a pile 10 : pas d\'alerte' }, { vars: { eleves: [] }, label: 'aucun élève' }, { vars: { eleves: [{ nom: 'Zoé', note: 3 }, { nom: 'Sami', note: 19 }, { nom: 'Jade', note: 9 }] } }],
        traps: [`<ul>
<?php foreach ($eleves as $e) { ?>
<?php if ($e['note'] <= 10) { ?>
  <li class="alerte"><?= $e['nom'] ?> : <?= $e['note'] ?></li>
<?php } else { ?>
  <li><?= $e['nom'] ?> : <?= $e['note'] ?></li>
<?php } ?>
<?php } ?>
</ul>`],
        ir: [{ re: /<=\s*10/, text: '10, c’est la moyenne : pas d’alerte. « Moins de 10 » s’écrit <code>&lt; 10</code>.' }, CLOSE_IN_LOOP],
        hints: ['Dans la boucle, un if choisit entre deux <li> : avec la classe alerte, ou sans.', '<?php foreach ($eleves as $e) { ?>\n<?php if ($e[\'note\'] < 10) { ?>\n  <li class="alerte">…</li>\n<?php } else { ?>\n  <li>…</li>\n<?php } ?>\n<?php } ?>']
      },
      ecrire3: {
        kind: 'write', ask: 'Affiche <h2>Collection</h2>, puis le tableau des spécimens avec une ligne de titres : <table>, puis <tr><th>Nom</th><th>Valeur</th></tr>, puis une ligne <tr><td>Trilobite</td><td>450 €</td></tr> par spécimen, puis </table>. S\'il n\'y a aucun spécimen, affiche seulement <p>Aucun spécimen.</p>.',
        ref: `<h2>Collection</h2>
<?php if (count($specimens) === 0) { ?>
<p>Aucun spécimen.</p>
<?php } else { ?>
<table>
  <tr><th>Nom</th><th>Valeur</th></tr>
<?php foreach ($specimens as $sp) { ?>
  <tr><td><?= $sp['nom'] ?></td><td><?= $sp['valeur'] ?> €</td></tr>
<?php } ?>
</table>
<?php } ?>`,
        vars: { specimens: [{ nom: 'Trilobite', valeur: 450 }, { nom: 'Loup gris', valeur: 1200 }, { nom: 'Quartz rose', valeur: 80 }] },
        hidden: [{ vars: { specimens: [] }, label: 'aucun spécimen' }, { vars: { specimens: [{ nom: 'Ammonite', valeur: 300 }] }, label: 'un seul spécimen' }, { vars: { specimens: [{ nom: 'Mammouth', valeur: 9000 }, { nom: 'Améthyste', valeur: 60 }] } }],
        traps: [`<h2>Collection</h2>
<table>
  <tr><th>Nom</th><th>Valeur</th></tr>
<?php foreach ($specimens as $sp) { ?>
  <tr><td><?= $sp['nom'] ?></td><td><?= $sp['valeur'] ?> €</td></tr>
<?php } ?>
</table>`],
        ir: [{ out: function (o) { return /<table>/.test(o) && !/<td>/.test(o); }, text: 'Une liste vide donne un tableau sans aucune ligne : teste d’abord <code>count($specimens) === 0</code> et affiche le paragraphe à la place.' }, CLOSE_IN_LOOP],
        hints: ['D\'abord un if sur count($specimens) : 0, c\'est le paragraphe ; sinon le tableau, avec sa ligne de titres avant la boucle et ses lignes dans la boucle.', '<h2>Collection</h2>\n<?php if (count($specimens) === 0) { ?>\n<p>Aucun spécimen.</p>\n<?php } else { ?>\n<table>\n  <tr><th>Nom</th><th>Valeur</th></tr>\n<?php foreach (…) { ?>\n  <tr>…</tr>\n<?php } ?>\n</table>\n<?php } ?>']
      },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  C.mastery({
    id: 'M23', chapter: 8, title: 'Pages', covers: ['N51'], draw: 3,
    pool: [
      {
        ask: 'Affiche la liste numérotée des élèves : <ol>, un <li> par prénom de $prenoms, puis </ol>.',
        ref: '<ol>\n<?php foreach ($prenoms as $p) { ?>\n  <li><?= $p ?></li>\n<?php } ?>\n</ol>',
        vars: { prenoms: ['Léa', 'Tom', 'Inès'] },
        hidden: [{ vars: { prenoms: ['Zoé'] }, label: 'un seul prénom' }, { vars: { prenoms: [] }, label: 'aucun prénom' }, { vars: { prenoms: ['Hugo', 'Nora', 'Sami', 'Jade'] } }],
        traps: ['<ol>\n<?php for ($i = 0; $i < 3; $i++) { ?>\n  <li><?= $prenoms[$i] ?></li>\n<?php } ?>\n</ol>'],
        ir: [CLOSE_IN_LOOP],
        hints: ['<ol> avant la boucle, </ol> après, un <li> par tour.', '<ol>\n<?php foreach ($prenoms as $p) { ?>\n  <li><?= … ?></li>\n<?php } ?>\n</ol>']
      },
      {
        ask: 'Affiche une liste déroulante des salles : <select>, puis une ligne <option>R1</option> par salle de $salles, puis </select>.',
        ref: '<select>\n<?php foreach ($salles as $s) { ?>\n  <option><?= $s ?></option>\n<?php } ?>\n</select>',
        vars: { salles: ['R1', 'R2', 'R4'] },
        hidden: [{ vars: { salles: ['R5'] }, label: 'une seule salle' }, { vars: { salles: [] }, label: 'aucune salle' }, { vars: { salles: ['R1', 'R2', 'R3', 'R4', 'R5'] } }],
        traps: ['<select>\n<?php for ($i = 0; $i < 3; $i++) { ?>\n  <option><?= $salles[$i] ?></option>\n<?php } ?>\n</select>'],
        ir: [CLOSE_IN_LOOP],
        hints: ['Une <option> par tour de boucle, <select> et </select> hors de la boucle.', '<select>\n<?php foreach ($salles as $s) { ?>\n  <option>…</option>\n<?php } ?>\n</select>']
      },
      {
        ask: 'Affiche les notes en tableau : <table>, une ligne <tr><td>Léa</td><td>14/20</td></tr> par élève, puis </table>.',
        ref: '<table>\n<?php foreach ($eleves as $e) { ?>\n  <tr><td><?= $e[\'nom\'] ?></td><td><?= $e[\'note\'] ?>/20</td></tr>\n<?php } ?>\n</table>',
        vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 8 }, { nom: 'Inès', note: 17 }] },
        hidden: [{ vars: { eleves: [{ nom: 'Zoé', note: 20 }] }, label: 'une seule élève' }, { vars: { eleves: [] }, label: 'aucun élève' }, { vars: { eleves: [{ nom: 'Hugo', note: 11 }, { nom: 'Nora', note: 6 }] } }],
        traps: ['<table>\n<?php for ($i = 0; $i < 3; $i++) { ?>\n  <tr><td><?= $eleves[$i][\'nom\'] ?></td><td><?= $eleves[$i][\'note\'] ?>/20</td></tr>\n<?php } ?>\n</table>'],
        ir: [CLOSE_IN_LOOP],
        hints: ['Une ligne <tr> par élève : $e[\'nom\'] dans la première case, $e[\'note\'] suivi de /20 dans la seconde.', '<table>\n<?php foreach ($eleves as $e) { ?>\n  <tr><td><?= … ?></td><td><?= … ?>/20</td></tr>\n<?php } ?>\n</table>']
      },
      {
        ask: 'Affiche un <ul> des spécimens : un <li> par nom. Si sa valeur atteint 1000 € ou plus, ajoute « (pièce rare) » après le nom : <li>Loup gris (pièce rare)</li>.',
        ref: '<ul>\n<?php foreach ($specimens as $sp) { ?>\n  <li><?= $sp[\'nom\'] ?><?php if ($sp[\'valeur\'] >= 1000) { ?> (pièce rare)<?php } ?></li>\n<?php } ?>\n</ul>',
        vars: { specimens: [{ nom: 'Trilobite', valeur: 450 }, { nom: 'Loup gris', valeur: 1200 }, { nom: 'Quartz rose', valeur: 80 }] },
        hidden: [{ vars: { specimens: [{ nom: 'Ammonite', valeur: 1000 }, { nom: 'Iguane', valeur: 999 }] }, label: 'une valeur pile à 1000 €' }, { vars: { specimens: [] }, label: 'aucun spécimen' }],
        traps: ['<ul>\n<?php foreach ($specimens as $sp) { ?>\n  <li><?= $sp[\'nom\'] ?><?php if ($sp[\'valeur\'] > 1000) { ?> (pièce rare)<?php } ?></li>\n<?php } ?>\n</ul>'],
        ir: [{ re: />\s*1000\b/, text: '« 1000 € ou plus » comprend 1000 : c’est <code>&gt;= 1000</code>.' }],
        hints: ['Dans le <li>, après le nom, un if n\'écrit « (pièce rare) » que si la valeur est d\'au moins 1000.', '<li><?= $sp[\'nom\'] ?><?php if (…) { ?> (pièce rare)<?php } ?></li>']
      },
      {
        ask: 'Affiche <h2>Spécimens : 3</h2> avec le nombre de noms de $noms, puis la liste <ul> de ces noms (un <li> par nom).',
        ref: '<h2>Spécimens : <?= count($noms) ?></h2>\n<ul>\n<?php foreach ($noms as $n) { ?>\n  <li><?= $n ?></li>\n<?php } ?>\n</ul>',
        vars: { noms: ['Trilobite', 'Loup gris', 'Quartz rose'] },
        hidden: [{ vars: { noms: [] }, label: 'aucun nom : Spécimens : 0' }, { vars: { noms: ['Ammonite', 'Mammouth', 'Iguane', 'Améthyste', 'Hibou'] } }],
        traps: ['<h2>Spécimens : 3</h2>\n<ul>\n<?php foreach ($noms as $n) { ?>\n  <li><?= $n ?></li>\n<?php } ?>\n</ul>'],
        ir: [{ re: /Spécimens\s*:\s*\d/, text: 'Le nombre de spécimens change d’un cas à l’autre : calcule-le avec <code>count($noms)</code>.' }],
        hints: ['count($noms) donne le nombre de noms ; <?= ?> l\'affiche dans le titre.', '<h2>Spécimens : <?= count(…) ?></h2>\n<ul>\n<?php foreach (…) { ?>\n  <li>…</li>\n<?php } ?>\n</ul>']
      },
      {
        ask: 'Affiche <table>, une ligne <tr><td>Trilobite</td><td>450 €</td></tr> par spécimen, puis une dernière ligne <tr><td>Total</td><td>1730 €</td></tr> avec la somme des valeurs, puis </table>.',
        ref: '<table>\n<?php $total = 0; ?>\n<?php foreach ($specimens as $sp) { ?>\n<?php $total += $sp[\'valeur\']; ?>\n  <tr><td><?= $sp[\'nom\'] ?></td><td><?= $sp[\'valeur\'] ?> €</td></tr>\n<?php } ?>\n  <tr><td>Total</td><td><?= $total ?> €</td></tr>\n</table>',
        vars: { specimens: [{ nom: 'Trilobite', valeur: 450 }, { nom: 'Loup gris', valeur: 1200 }, { nom: 'Quartz rose', valeur: 80 }] },
        hidden: [{ vars: { specimens: [] }, label: 'aucun spécimen : total 0 €' }, { vars: { specimens: [{ nom: 'Ammonite', valeur: 300 }, { nom: 'Mammouth', valeur: 9000 }] } }],
        traps: ['<table>\n<?php foreach ($specimens as $sp) { ?>\n  <tr><td><?= $sp[\'nom\'] ?></td><td><?= $sp[\'valeur\'] ?> €</td></tr>\n<?php } ?>\n  <tr><td>Total</td><td><?= array_sum([450, 1200, 80]) ?> €</td></tr>\n</table>'],
        ir: [{ re: /foreach[^{]*\{[^}]*\$total\s*=\s*0/, text: 'Le total est remis à 0 à chaque tour : prépare <code>$total = 0;</code> avant la boucle.' }],
        hints: ['Prépare $total = 0 avant la boucle, ajoute la valeur à chaque tour, affiche $total dans la dernière ligne, après la boucle.', '<table>\n<?php $total = 0; ?>\n<?php foreach (…) { ?>\n<?php $total += …; ?>\n  <tr>…</tr>\n<?php } ?>\n  <tr><td>Total</td><td><?= $total ?> €</td></tr>\n</table>']
      },
      {
        ask: 'Affiche un <ul> des élèves : un <li> par élève, « Léa – absences : 2 », ou « Léa – aucune absence » si elle n\'en a aucune.',
        ref: '<ul>\n<?php foreach ($eleves as $e) { ?>\n<?php if ($e[\'absences\'] === 0) { ?>\n  <li><?= $e[\'nom\'] ?> – aucune absence</li>\n<?php } else { ?>\n  <li><?= $e[\'nom\'] ?> – absences : <?= $e[\'absences\'] ?></li>\n<?php } ?>\n<?php } ?>\n</ul>',
        vars: { eleves: [{ nom: 'Léa', absences: 2 }, { nom: 'Tom', absences: 5 }, { nom: 'Inès', absences: 1 }] },
        hidden: [{ vars: { eleves: [{ nom: 'Zoé', absences: 0 }, { nom: 'Hugo', absences: 3 }] }, label: 'Zoé n\'a aucune absence' }, { vars: { eleves: [] }, label: 'aucun élève' }],
        traps: ['<ul>\n<?php foreach ($eleves as $e) { ?>\n  <li><?= $e[\'nom\'] ?> – absences : <?= $e[\'absences\'] ?></li>\n<?php } ?>\n</ul>'],
        hints: ['Dans la boucle, un if sur $e[\'absences\'] === 0 choisit entre les deux <li>.', '<?php if ($e[\'absences\'] === 0) { ?>\n  <li>… – aucune absence</li>\n<?php } else { ?>\n  <li>… – absences : …</li>\n<?php } ?>']
      },
      {
        ask: 'Affiche <h2>Inscrits</h2>, puis la liste <ul> des inscrits de $prenoms (un <li> par prénom). Si la liste est vide, affiche seulement <p>Aucun inscrit.</p> à la place.',
        ref: '<h2>Inscrits</h2>\n<?php if (count($prenoms) === 0) { ?>\n<p>Aucun inscrit.</p>\n<?php } else { ?>\n<ul>\n<?php foreach ($prenoms as $p) { ?>\n  <li><?= $p ?></li>\n<?php } ?>\n</ul>\n<?php } ?>',
        vars: { prenoms: ['Nora', 'Sami', 'Jade'] },
        hidden: [{ vars: { prenoms: [] }, label: 'personne d\'inscrit' }, { vars: { prenoms: ['Léa'] }, label: 'une seule inscrite' }],
        traps: ['<h2>Inscrits</h2>\n<ul>\n<?php foreach ($prenoms as $p) { ?>\n  <li><?= $p ?></li>\n<?php } ?>\n</ul>'],
        hints: ['Un if sur count($prenoms) === 0 choisit entre le paragraphe et la liste.', '<h2>Inscrits</h2>\n<?php if (count($prenoms) === 0) { ?>\n<p>Aucun inscrit.</p>\n<?php } else { ?>\n<ul>\n…\n</ul>\n<?php } ?>']
      },
      {
        ask: 'Affiche les notes en tableau : <table>, une ligne <tr><td>Léa</td><td class="bien">14</td></tr> par élève, puis </table>. La case de la note porte la classe bien si la note est de 12 ou plus, sinon la classe faible.',
        ref: '<table>\n<?php foreach ($eleves as $e) { ?>\n<?php if ($e[\'note\'] >= 12) { $c = \'bien\'; } else { $c = \'faible\'; } ?>\n  <tr><td><?= $e[\'nom\'] ?></td><td class="<?= $c ?>"><?= $e[\'note\'] ?></td></tr>\n<?php } ?>\n</table>',
        vars: { eleves: [{ nom: 'Léa', note: 14 }, { nom: 'Tom', note: 8 }, { nom: 'Inès', note: 17 }] },
        hidden: [{ vars: { eleves: [{ nom: 'Hugo', note: 12 }, { nom: 'Nora', note: 11 }] }, label: 'Hugo a pile 12' }, { vars: { eleves: [] }, label: 'aucun élève' }],
        traps: ['<table>\n<?php foreach ($eleves as $e) { ?>\n<?php if ($e[\'note\'] > 12) { $c = \'bien\'; } else { $c = \'faible\'; } ?>\n  <tr><td><?= $e[\'nom\'] ?></td><td class="<?= $c ?>"><?= $e[\'note\'] ?></td></tr>\n<?php } ?>\n</table>'],
        ir: [{ re: />\s*12\b/, text: '« 12 ou plus » comprend 12 : c’est <code>&gt;= 12</code>.' }],
        hints: ['Dans la boucle, choisis d\'abord la classe dans une variable avec un if, puis écris la ligne avec class="<?= $c ?>".', '<?php if ($e[\'note\'] >= 12) { $c = \'bien\'; } else { $c = \'faible\'; } ?>\n  <tr><td>…</td><td class="<?= $c ?>">…</td></tr>']
      }
    ]
  });

  C.level({
    id: 'N52', chapter: 8, num: 52, title: 'Lire l\'adresse et afficher sans danger', summary: '$_GET · ?? · htmlspecialchars · is_numeric', notions: ['get'],
    lessons: {
      get: {
        title: 'L\'adresse de la page : $_GET',
        html: `<p>Une adresse peut transporter des informations après un <code>?</code> : <code>fiche.php?nom=Léa&amp;salle=R2</code>. Chaque <code>nom=valeur</code> est un <b>paramètre</b>, séparé du suivant par <code>&amp;</code>. PHP les range dans un tableau tout prêt, <code>$_GET</code> :</p>
<div class="rule">$_GET = ['nom' =&gt; 'Léa', 'salle' =&gt; 'R2'];</div>
<p>Dans les exercices, cette ligne est grise : c'est l'adresse demandée. Deux choses à retenir :</p>
<ul><li>une valeur de <code>$_GET</code> est <b>toujours du texte</b>, même <code>'3'</code> ;</li><li>un paramètre peut <b>manquer</b> : quelqu'un tape <code>fiche.php</code> tout court. Lire <code>$_GET['nom']</code> donne alors l'avertissement « Undefined array key ».</li></ul>
<div class="rule"><span class="kw">isset</span>($_GET['nom'])          <span class="c">// true si le paramètre existe</span><br>$_GET['nom'] ?? 'visiteur'   <span class="c">// sa valeur, ou 'visiteur' s'il manque</span></div>
<p><code>??</code> donne une <b>valeur par défaut</b>. Attention : il ne remplace que l'absence. <code>fiche.php?nom=</code> donne un texte <b>vide</b>, <code>''</code>, que <code>??</code> laisse passer.</p>
<p>Cette page n'est pas encore sûre : la leçon suivante dit pourquoi.</p>`,
        example: '<p>Bonjour <?= $_GET[\'nom\'] ?? \'visiteur\' ?></p>\n<p>Salle : <?= $_GET[\'salle\'] ?? \'R1\' ?></p>',
        vars: { _GET: { nom: 'Léa' } },
        tryit: 'La salle manque dans l\'adresse : d\'où vient R1 ? Enlève ?? \'R1\' et lis l\'avertissement.'
      },
      echapper: {
        title: 'Ne jamais afficher l\'adresse telle quelle',
        html: `<p>L'adresse, <b>n'importe qui</b> peut l'écrire. Si quelqu'un envoie <code>fiche.php?nom=&lt;script&gt;…&lt;/script&gt;</code> et que la page affiche <code>$_GET['nom']</code> tel quel, le navigateur reçoit une vraie balise <code>&lt;script&gt;</code> et l'exécute : c'est une <b>faille XSS</b>.</p>
<p><code>htmlspecialchars()</code> transforme les caractères qui ont un sens en HTML : <code>&lt;</code> devient <code>&amp;lt;</code>, <code>&gt;</code> devient <code>&amp;gt;</code>, <code>&amp;</code> devient <code>&amp;amp;</code>, <code>"</code> devient <code>&amp;quot;</code>. Le navigateur affiche alors <code>&lt;b&gt;</code> à l'écran, comme du texte, au lieu de l'exécuter.</p>
<div class="rule">&lt;p&gt;Bonjour &lt;?= <span class="kw">htmlspecialchars</span>($_GET['nom'] ?? 'visiteur') ?&gt;&lt;/p&gt;</div>
<p>La règle : <b>tout ce qui vient de l'adresse passe par htmlspecialchars avant d'être affiché</b>. Rien n'est effacé : le texte est montré tel qu'il a été tapé.</p>
<p>Et un nombre ? <code>billets.php?n=abc</code> : calculer avec <code>'abc'</code> arrête le programme. On vérifie d'abord avec <code>is_numeric($n)</code> (vrai pour <code>'3'</code> ou <code>'2.5'</code>, faux pour <code>'abc'</code> ou <code>''</code>), puis on le convertit avec <code>(int) $n</code>. Le piège : <code>(int) 'abc'</code> vaut 0 sans prévenir ; c'est <code>is_numeric</code> qui dit si c'était un nombre.</p>`,
        example: `<p>Bonjour <?= htmlspecialchars($_GET['nom'] ?? 'visiteur') ?></p>
<?php $n = $_GET['n'] ?? ''; ?>
<?php if (is_numeric($n)) { ?>
<p><?= (int) $n * 8 ?> €</p>
<?php } else { ?>
<p>Nombre invalide</p>
<?php } ?>`,
        vars: { _GET: { nom: '<b>Zoé</b>', n: '3' } },
        tryit: 'Enlève htmlspecialchars : que devient le nom dans la page reçue ?'
      }
    },
    steps: {
      predire: { before: 'get', kind: 'choose', ask: 'L\'adresse est fiche.php?nom=Léa. Sans l\'exécuter : quelle page le navigateur reçoit-il ?', code: '<p><?= $_GET[\'nom\'] ?? \'Visiteur\' ?>, salle <?= $_GET[\'salle\'] ?? \'R1\' ?></p>', vars: { _GET: { nom: 'Léa' } }, options: ['<p>Léa, salle R1</p>', '<p>Visiteur, salle R1</p>', '<p>Léa, salle</p>', '<p>Visiteur, salle</p>'], hints: ['?? garde la valeur du paramètre quand il existe, et prend la valeur par défaut quand il manque. Quel paramètre manque ici ?'] },
      suivre: {
        before: 'echapper', kind: 'cases', ask: 'Pour chaque adresse (la ligne $_GET), qu\'affiche ce programme ?',
        code: '<?php\n$nom = $_GET[\'nom\'] ?? \'visiteur\';\nif ($nom === \'\') {\n    $nom = \'anonyme\';\n}\necho \'Bonjour \' . htmlspecialchars($nom);',
        var: '_GET', values: [{ nom: 'Léa' }, {}, { nom: '' }, { nom: '<b>Zoé</b>' }],
        hints: ['?? ne remplace que l\'absence : le texte vide passe, puis le if le remplace. htmlspecialchars change < en &lt; et > en &gt;.']
      },
      comprendre: {},
      completer: {
        kind: 'complete', ask: 'Affiche « Bonjour » suivi du nom pris dans l\'adresse, protégé ; sans paramètre nom, « Bonjour visiteur ».',
        template: '<p>Bonjour <?= [[]]($_GET[\'nom\'] [[]] \'visiteur\') ?></p>',
        fill: ['htmlspecialchars', '??'], words: ['htmlspecialchars', 'isset', 'echo', '??', '||', '.'],
        ref: '<p>Bonjour <?= htmlspecialchars($_GET[\'nom\'] ?? \'visiteur\') ?></p>',
        vars: { _GET: { nom: 'Léa' } },
        hidden: [{ vars: { _GET: {} }, label: 'fiche.php sans paramètre' }, { vars: { _GET: { nom: '<b>Zoé</b>' } }, label: 'nom=<b>Zoé</b> (du HTML dans l\'adresse)' }, { vars: { _GET: { nom: 'Tom & Léa' } } }],
        hints: ['Le premier trou protège ce qui vient de l\'adresse ; le second donne la valeur par défaut.', '<?= htmlspecialchars($_GET[\'nom\'] … \'visiteur\') ?>']
      },
      reparer: {
        kind: 'repair', ask: 'Cette page affiche le prix de n billets à 8 € (billets.php?n=3). Elle plante si n manque ou n\'est pas un nombre : dans ce cas, affiche <p>Nombre invalide</p>.',
        starter: '<h1>Billets</h1>\n<p><?= $_GET[\'n\'] * 8 ?> €</p>',
        ref: `<h1>Billets</h1>
<?php
$n = $_GET['n'] ?? '';
if (is_numeric($n)) {
    $texte = (int) $n * 8 . ' €';
} else {
    $texte = 'Nombre invalide';
}
?>
<p><?= $texte ?></p>`,
        vars: { _GET: { n: '3' } },
        hidden: [{ vars: { _GET: {} }, label: 'billets.php sans paramètre' }, { vars: { _GET: { n: 'abc' } }, label: 'n=abc (pas un nombre)' }, { vars: { _GET: { n: '5' } } }],
        traps: ['<h1>Billets</h1>\n<p><?= (int) ($_GET[\'n\'] ?? 0) * 8 ?> €</p>'],
        ir: [INT_ONLY, NO_DEFAULT],
        hints: ['Range d\'abord le paramètre dans $n avec ?? \'\', puis un if avec is_numeric($n) choisit le texte à afficher.', '<h1>Billets</h1>\n<?php\n$n = $_GET[\'n\'] ?? \'\';\nif (is_numeric($n)) {\n    $texte = …;\n} else {\n    $texte = \'Nombre invalide\';\n}\n?>\n<p><?= $texte ?></p>']
      },
      ecrire1: {
        kind: 'write', ask: 'Affiche <p>Léa visite la salle R2</p> avec les paramètres nom et salle de l\'adresse, tous deux protégés par htmlspecialchars. Un paramètre absent est remplacé : « Un visiteur » pour le nom, « R1 » pour la salle.',
        ref: '<p><?= htmlspecialchars($_GET[\'nom\'] ?? \'Un visiteur\') ?> visite la salle <?= htmlspecialchars($_GET[\'salle\'] ?? \'R1\') ?></p>',
        vars: { _GET: { nom: 'Léa', salle: 'R2' } },
        hidden: [{ vars: { _GET: { nom: 'Tom' } }, label: 'salle absente' }, { vars: { _GET: {} }, label: 'aucun paramètre' }, { vars: { _GET: { nom: '<script>alert(1)</script>', salle: 'R4' } }, label: 'un nom qui contient <script>' }, { vars: { _GET: { nom: 'Inès', salle: '<b>R5</b>' } }, label: 'une salle qui contient <b>' }],
        traps: ['<p><?= $_GET[\'nom\'] ?? \'Un visiteur\' ?> visite la salle <?= $_GET[\'salle\'] ?? \'R1\' ?></p>', '<p><?= htmlspecialchars($_GET[\'nom\'] ?? \'Un visiteur\') ?> visite la salle <?= $_GET[\'salle\'] ?? \'R1\' ?></p>', '<p><?= htmlspecialchars($_GET[\'nom\']) ?> visite la salle <?= htmlspecialchars($_GET[\'salle\']) ?></p>'],
        ir: [NO_ESCAPE, NO_DEFAULT, { out: function (o, code) { return (code.match(/htmlspecialchars/g) || []).length < 2; }, text: 'Les deux paramètres viennent de l’adresse : chacun passe par <code>htmlspecialchars()</code>.' }],
        hints: ['Deux <?= ?> dans le paragraphe, chacun avec htmlspecialchars et ??.', '<p><?= htmlspecialchars($_GET[\'nom\'] ?? …) ?> visite la salle <?= htmlspecialchars(…) ?></p>']
      },
      ecrire2: {
        kind: 'write', ask: 'L\'adresse porte une quantité de billets (reserver.php?qte=3) ; un billet coûte $prix euros. Affiche <h1>Réservation</h1>, puis <p>Quantité : 3 · Total : 24 €</p>. Si qte manque, n\'est pas un nombre ou est plus petite que 1, affiche <p>Quantité invalide</p> à la place.',
        ref: `<h1>Réservation</h1>
<?php
$qte = $_GET['qte'] ?? '';
if (is_numeric($qte) && (int) $qte >= 1) {
    $texte = 'Quantité : ' . (int) $qte . ' · Total : ' . (int) $qte * $prix . ' €';
} else {
    $texte = 'Quantité invalide';
}
?>
<p><?= $texte ?></p>`,
        vars: { prix: 8, _GET: { qte: '3' } },
        hidden: [{ vars: { _GET: { qte: '0' } }, label: 'qte=0' }, { vars: { _GET: { qte: 'abc' } }, label: 'qte=abc (pas un nombre)' }, { vars: { _GET: {} }, label: 'qte absente' }, { vars: { prix: 12, _GET: { qte: '1' } }, label: 'qte=1, billet à 12 €' }],
        traps: [`<h1>Réservation</h1>
<?php
$qte = $_GET['qte'] ?? '';
if (is_numeric($qte)) {
    $texte = 'Quantité : ' . (int) $qte . ' · Total : ' . (int) $qte * $prix . ' €';
} else {
    $texte = 'Quantité invalide';
}
?>
<p><?= $texte ?></p>`],
        ir: [{ out: function (o) { return /Quantité : 0\b/.test(o); }, text: 'Réserver 0 billet n’a pas de sens : ajoute la condition <code>(int) $qte &gt;= 1</code>.' }, INT_ONLY, NO_DEFAULT],
        hints: ['Range le paramètre dans $qte avec ?? \'\', puis un seul if : is_numeric($qte) && (int) $qte >= 1.', '<h1>Réservation</h1>\n<?php\n$qte = $_GET[\'qte\'] ?? \'\';\nif (is_numeric($qte) && (int) $qte >= 1) {\n    $texte = \'Quantité : \' . … . \' · Total : \' . … . \' €\';\n} else {\n    $texte = \'Quantité invalide\';\n}\n?>\n<p><?= $texte ?></p>']
      },
      ecrire3: {
        kind: 'write', ask: 'La page liste les spécimens de la classe demandée (liste.php?classe=Fossiles) : d\'abord <h1>Fossiles</h1>, puis un <ul> avec un <li> par spécimen de cette classe. Sans paramètre, le titre est « Toutes » et la liste montre tous les spécimens. Le titre vient de l\'adresse : protège-le.',
        ref: `<h1><?= htmlspecialchars($_GET['classe'] ?? 'Toutes') ?></h1>
<ul>
<?php foreach ($specimens as $sp) { ?>
<?php if (!isset($_GET['classe']) || $sp['classe'] === $_GET['classe']) { ?>
  <li><?= $sp['nom'] ?></li>
<?php } ?>
<?php } ?>
</ul>`,
        vars: { specimens: [{ nom: 'Trilobite', classe: 'Fossiles' }, { nom: 'Loup gris', classe: 'Mammifères' }, { nom: 'Ammonite', classe: 'Fossiles' }, { nom: 'Quartz rose', classe: 'Minéraux' }], _GET: { classe: 'Fossiles' } },
        hidden: [{ vars: { _GET: {} }, label: 'sans paramètre : toute la collection' }, { vars: { _GET: { classe: 'Minéraux' } } }, { vars: { _GET: { classe: '<b>Oiseaux</b>' } }, label: 'une classe avec du HTML, et aucun spécimen' }],
        traps: [`<h1><?= htmlspecialchars($_GET['classe'] ?? 'Toutes') ?></h1>
<ul>
<?php foreach ($specimens as $sp) { ?>
<?php if ($sp['classe'] === ($_GET['classe'] ?? 'Toutes')) { ?>
  <li><?= $sp['nom'] ?></li>
<?php } ?>
<?php } ?>
</ul>`, `<h1><?= $_GET['classe'] ?? 'Toutes' ?></h1>
<ul>
<?php foreach ($specimens as $sp) { ?>
<?php if (!isset($_GET['classe']) || $sp['classe'] === $_GET['classe']) { ?>
  <li><?= $sp['nom'] ?></li>
<?php } ?>
<?php } ?>
</ul>`],
        ir: [NO_ESCAPE, { out: function (o) { return /<h1>Toutes<\/h1><ul><\/ul>/.test(o); }, text: 'Sans paramètre, aucun spécimen n’est de la classe « Toutes » : le if doit laisser passer tout le monde quand <code>!isset($_GET[\'classe\'])</code>.' }, CLOSE_IN_LOOP],
        hints: ['Le titre : htmlspecialchars avec ?? \'Toutes\'. Dans la boucle, un if garde le spécimen si le paramètre manque OU si sa classe est celle demandée.', '<h1><?= htmlspecialchars(…) ?></h1>\n<ul>\n<?php foreach ($specimens as $sp) { ?>\n<?php if (!isset($_GET[\'classe\']) || …) { ?>\n  <li><?= $sp[\'nom\'] ?></li>\n<?php } ?>\n<?php } ?>\n</ul>']
      },
      rappel1: { kind: 'recall' },
      rappel2: { kind: 'recall' }
    }
  });

  C.mastery({
    id: 'M24', chapter: 8, title: 'Adresse et sécurité', covers: ['N52'], draw: 3,
    pool: [
      {
        ask: 'Affiche <p>Bonjour Léa</p> avec le paramètre nom de l\'adresse, protégé par htmlspecialchars ; sans paramètre, <p>Bonjour visiteur</p>.',
        ref: '<p>Bonjour <?= htmlspecialchars($_GET[\'nom\'] ?? \'visiteur\') ?></p>',
        vars: { _GET: { nom: 'Léa' } },
        hidden: [{ vars: { _GET: {} }, label: 'aucun paramètre' }, { vars: { _GET: { nom: '<b>Tom</b>' } }, label: 'nom=<b>Tom</b>' }],
        traps: ['<p>Bonjour <?= $_GET[\'nom\'] ?? \'visiteur\' ?></p>', '<p>Bonjour <?= htmlspecialchars($_GET[\'nom\']) ?></p>'],
        ir: [NO_ESCAPE, NO_DEFAULT],
        hints: ['Un seul <?= ?> : htmlspecialchars autour du paramètre lu avec ??.', '<p>Bonjour <?= htmlspecialchars($_GET[\'nom\'] ?? …) ?></p>']
      },
      {
        ask: 'Affiche <h1>Salle R3</h1> avec le paramètre salle de l\'adresse, protégé ; sans paramètre, la salle R1.',
        ref: '<h1>Salle <?= htmlspecialchars($_GET[\'salle\'] ?? \'R1\') ?></h1>',
        vars: { _GET: { salle: 'R3' } },
        hidden: [{ vars: { _GET: {} }, label: 'aucun paramètre' }, { vars: { _GET: { salle: '<i>R5</i>' } }, label: 'salle=<i>R5</i>' }],
        traps: ['<h1>Salle <?= $_GET[\'salle\'] ?? \'R1\' ?></h1>', '<h1>Salle <?= htmlspecialchars($_GET[\'salle\']) ?></h1>'],
        ir: [NO_ESCAPE, NO_DEFAULT],
        hints: ['La valeur par défaut avec ??, la protection avec htmlspecialchars.', '<h1>Salle <?= htmlspecialchars(… ?? \'R1\') ?></h1>']
      },
      {
        ask: 'L\'adresse porte un numéro de page (liste.php?page=2). Affiche <p>Page 2</p> ; si le paramètre manque ou n\'est pas un nombre, affiche <p>Page 1</p>.',
        ref: '<p>Page <?php if (isset($_GET[\'page\']) && is_numeric($_GET[\'page\'])) { echo (int) $_GET[\'page\']; } else { echo 1; } ?></p>',
        vars: { _GET: { page: '2' } },
        hidden: [{ vars: { _GET: {} }, label: 'aucun paramètre' }, { vars: { _GET: { page: 'abc' } }, label: 'page=abc' }, { vars: { _GET: { page: '7' } } }],
        traps: ['<p>Page <?= (int) ($_GET[\'page\'] ?? 1) ?></p>'],
        ir: [INT_ONLY, NO_DEFAULT],
        hints: ['Un if : le paramètre existe ET is_numeric dit oui → (int) ; sinon 1.', '<p>Page <?php if (isset(…) && is_numeric(…)) { echo (int) …; } else { echo 1; } ?></p>']
      },
      {
        ask: 'Un moteur de recherche (cherche.php?q=loup). Affiche <h1>Recherche</h1>, puis <p>Résultats pour : loup</p> en protégeant le mot ; si q manque ou est vide, affiche <p>Tape un mot à chercher.</p>.',
        ref: '<h1>Recherche</h1>\n<?php $q = $_GET[\'q\'] ?? \'\'; ?>\n<?php if ($q === \'\') { ?>\n<p>Tape un mot à chercher.</p>\n<?php } else { ?>\n<p>Résultats pour : <?= htmlspecialchars($q) ?></p>\n<?php } ?>',
        vars: { _GET: { q: 'loup' } },
        hidden: [{ vars: { _GET: {} }, label: 'q absent' }, { vars: { _GET: { q: '' } }, label: 'q vide : cherche.php?q=' }, { vars: { _GET: { q: '<script>alert(1)</script>' } }, label: 'q contient <script>' }],
        traps: ['<h1>Recherche</h1>\n<?php if (!isset($_GET[\'q\'])) { ?>\n<p>Tape un mot à chercher.</p>\n<?php } else { ?>\n<p>Résultats pour : <?= htmlspecialchars($_GET[\'q\']) ?></p>\n<?php } ?>', '<h1>Recherche</h1>\n<?php $q = $_GET[\'q\'] ?? \'\'; ?>\n<?php if ($q === \'\') { ?>\n<p>Tape un mot à chercher.</p>\n<?php } else { ?>\n<p>Résultats pour : <?= $q ?></p>\n<?php } ?>'],
        ir: [NO_ESCAPE, { out: function (o) { return /Résultats pour :\s*<\/p>/.test(o); }, text: '<code>cherche.php?q=</code> donne un texte vide : <code>isset</code> et <code>??</code> le laissent passer. Teste aussi <code>$q === \'\'</code>.' }],
        hints: ['$q = $_GET[\'q\'] ?? \'\' règle l\'absence ; ensuite un seul if sur $q === \'\' règle le vide.', '<h1>Recherche</h1>\n<?php $q = $_GET[\'q\'] ?? \'\'; ?>\n<?php if ($q === \'\') { ?>\n<p>Tape un mot à chercher.</p>\n<?php } else { ?>\n<p>Résultats pour : <?= htmlspecialchars(…) ?></p>\n<?php } ?>']
      },
      {
        ask: 'fiche.php?id=1 : affiche <h1>Loup gris</h1>, le nom du spécimen numéro id de $noms (la case 1). Si id manque, n\'est pas un nombre ou ne correspond à aucune case, affiche <h1>Spécimen introuvable</h1>.',
        ref: '<h1><?php\n$id = $_GET[\'id\'] ?? \'\';\nif (is_numeric($id) && isset($noms[(int) $id])) {\n    echo $noms[(int) $id];\n} else {\n    echo \'Spécimen introuvable\';\n}\n?></h1>',
        vars: { noms: ['Trilobite', 'Loup gris', 'Quartz rose'], _GET: { id: '1' } },
        hidden: [{ vars: { _GET: {} }, label: 'id absent' }, { vars: { _GET: { id: 'abc' } }, label: 'id=abc' }, { vars: { _GET: { id: '7' } }, label: 'id=7 : aucune case 7' }, { vars: { _GET: { id: '0' } }, label: 'id=0 : la première case' }],
        traps: ['<h1><?php\n$id = $_GET[\'id\'] ?? \'\';\nif (is_numeric($id)) {\n    echo $noms[(int) $id];\n} else {\n    echo \'Spécimen introuvable\';\n}\n?></h1>'],
        ir: [INT_ONLY, { out: function (o, code) { return !/isset\s*\(\s*\$noms/.test(code); }, text: 'Un nombre peut désigner une case qui n’existe pas : vérifie aussi <code>isset($noms[(int) $id])</code>.' }],
        hints: ['Trois conditions : le paramètre (avec ??), is_numeric, puis isset($noms[(int) $id]) pour la case.', '<h1><?php\n$id = $_GET[\'id\'] ?? \'\';\nif (is_numeric($id) && isset($noms[(int) $id])) {\n    echo …;\n} else {\n    echo \'Spécimen introuvable\';\n}\n?></h1>']
      },
      {
        ask: 'note.php?note=15 : affiche <p>Note enregistrée : 15/20</p>. Si la note manque, n\'est pas un nombre ou sort de 0 à 20, affiche <p>Note invalide</p>.',
        ref: '<p><?php\n$note = $_GET[\'note\'] ?? \'\';\nif (is_numeric($note) && (int) $note >= 0 && (int) $note <= 20) {\n    echo \'Note enregistrée : \' . (int) $note . \'/20\';\n} else {\n    echo \'Note invalide\';\n}\n?></p>',
        vars: { _GET: { note: '15' } },
        hidden: [{ vars: { _GET: { note: '20' } }, label: 'note=20 : pile la borne' }, { vars: { _GET: { note: '21' } }, label: 'note=21' }, { vars: { _GET: { note: 'abc' } }, label: 'note=abc' }, { vars: { _GET: {} }, label: 'note absente' }],
        traps: ['<p><?php\n$note = $_GET[\'note\'] ?? \'\';\nif (is_numeric($note) && (int) $note >= 0) {\n    echo \'Note enregistrée : \' . (int) $note . \'/20\';\n} else {\n    echo \'Note invalide\';\n}\n?></p>', '<p><?php\n$note = $_GET[\'note\'] ?? \'\';\nif (is_numeric($note) && (int) $note >= 0 && (int) $note < 20) {\n    echo \'Note enregistrée : \' . (int) $note . \'/20\';\n} else {\n    echo \'Note invalide\';\n}\n?></p>'],
        ir: [{ re: /<\s*20\b/, text: '20 est une note valide : c’est <code>&lt;= 20</code>.' }, INT_ONLY],
        hints: ['Un seul if qui demande tout à la fois : un nombre, au moins 0, au plus 20.', '$note = $_GET[\'note\'] ?? \'\';\nif (is_numeric($note) && (int) $note >= 0 && (int) $note <= 20) { … } else { … }']
      },
      {
        ask: 'liste.php?min=500 : affiche un <ul> avec un <li> par spécimen qui vaut au moins min euros. Si min manque ou n\'est pas un nombre, montre tous les spécimens.',
        ref: '<ul>\n<?php\n$min = $_GET[\'min\'] ?? \'\';\nif (!is_numeric($min)) {\n    $min = 0;\n}\nforeach ($specimens as $sp) {\n    if ($sp[\'valeur\'] >= (int) $min) {\n        echo \'<li>\' . $sp[\'nom\'] . \'</li>\';\n    }\n}\n?>\n</ul>',
        vars: { specimens: [{ nom: 'Trilobite', valeur: 450 }, { nom: 'Loup gris', valeur: 1200 }, { nom: 'Quartz rose', valeur: 80 }, { nom: 'Ammonite', valeur: 300 }], _GET: { min: '400' } },
        hidden: [{ vars: { _GET: {} }, label: 'min absent : tous' }, { vars: { _GET: { min: 'abc' } }, label: 'min=abc : tous' }, { vars: { _GET: { min: '1200' } }, label: 'min=1200 : pile la valeur du loup' }],
        traps: ['<ul>\n<?php\n$min = $_GET[\'min\'] ?? \'\';\nif (!is_numeric($min)) {\n    $min = 0;\n}\nforeach ($specimens as $sp) {\n    if ($sp[\'valeur\'] > (int) $min) {\n        echo \'<li>\' . $sp[\'nom\'] . \'</li>\';\n    }\n}\n?>\n</ul>'],
        ir: [{ re: /\[\s*'valeur'\s*\]\s*>\s*[^=]/, text: '« Au moins min » comprend min : c’est <code>&gt;=</code>.' }, INT_ONLY, NO_DEFAULT],
        hints: ['Lis min avec ??, remplace-le par 0 s\'il n\'est pas un nombre, puis filtre dans la boucle avec >=.', '<ul>\n<?php\n$min = $_GET[\'min\'] ?? \'\';\nif (!is_numeric($min)) {\n    $min = 0;\n}\nforeach ($specimens as $sp) {\n    if (…) {\n        echo \'<li>\' . … . \'</li>\';\n    }\n}\n?>\n</ul>']
      },
      {
        ask: 'accueil.php?msg=… : affiche toujours <h1>Accueil</h1> ; si un paramètre msg est présent, ajoute dessous <p class="info">…</p> avec le message protégé. Sans msg, rien d\'autre.',
        ref: '<h1>Accueil</h1>\n<?php if (isset($_GET[\'msg\'])) { ?>\n<p class="info"><?= htmlspecialchars($_GET[\'msg\']) ?></p>\n<?php } ?>',
        vars: { _GET: { msg: 'Salle R2 rouverte' } },
        hidden: [{ vars: { _GET: {} }, label: 'aucun message' }, { vars: { _GET: { msg: '<i>Salle fermée</i>' } }, label: 'un message avec du HTML' }],
        traps: ['<h1>Accueil</h1>\n<p class="info"><?= htmlspecialchars($_GET[\'msg\'] ?? \'\') ?></p>', '<h1>Accueil</h1>\n<?php if (isset($_GET[\'msg\'])) { ?>\n<p class="info"><?= $_GET[\'msg\'] ?></p>\n<?php } ?>'],
        ir: [NO_ESCAPE, { out: function (o) { return /<p class="info"><\/p>/.test(o); }, text: 'Sans message, il ne faut pas de paragraphe du tout : entoure-le d’un <code>if (isset($_GET[\'msg\']))</code>.' }],
        hints: ['Un if (isset(…)) entoure le paragraphe, qui affiche le message passé par htmlspecialchars.', '<h1>Accueil</h1>\n<?php if (isset($_GET[\'msg\'])) { ?>\n<p class="info"><?= htmlspecialchars(…) ?></p>\n<?php } ?>']
      },
      {
        ask: 'Affiche <p>Carte de Léa Martin</p> avec les paramètres prenom et nom, tous deux protégés ; un paramètre absent est remplacé par « ? ».',
        ref: '<p>Carte de <?= htmlspecialchars($_GET[\'prenom\'] ?? \'?\') ?> <?= htmlspecialchars($_GET[\'nom\'] ?? \'?\') ?></p>',
        vars: { _GET: { prenom: 'Léa', nom: 'Martin' } },
        hidden: [{ vars: { _GET: { prenom: 'Tom' } }, label: 'nom absent' }, { vars: { _GET: { prenom: '<b>Zoé</b>', nom: 'Durand & fils' } }, label: 'du HTML et un & dans l\'adresse' }],
        traps: ['<p>Carte de <?= htmlspecialchars($_GET[\'prenom\'] ?? \'?\') ?> <?= $_GET[\'nom\'] ?? \'?\' ?></p>', '<p>Carte de <?= htmlspecialchars($_GET[\'prenom\']) ?> <?= htmlspecialchars($_GET[\'nom\']) ?></p>'],
        ir: [NO_ESCAPE, NO_DEFAULT, { out: function (o, code) { return (code.match(/htmlspecialchars/g) || []).length < 2; }, text: 'Les deux paramètres viennent de l’adresse : chacun passe par <code>htmlspecialchars()</code>.' }],
        hints: ['Deux <?= ?>, chacun avec htmlspecialchars et ?? \'?\', séparés par une espace.', '<p>Carte de <?= htmlspecialchars(… ?? \'?\') ?> <?= htmlspecialchars(… ?? \'?\') ?></p>']
      }
    ]
  });

  /* The final test: twelve programs drawn from the mastery pools of every chapter, nine of them on the
     core (conditions, loops, arrays), timed, passed at ten. */
  C.final({ id: 'FIN', chapter: 8, title: 'L\'épreuve finale', seconds: 3600, perChapter: { 1: 1, 2: 3, 3: 2, 4: 2, 5: 2, 6: 1, 8: 1 } });
})(window.PPPContent);
