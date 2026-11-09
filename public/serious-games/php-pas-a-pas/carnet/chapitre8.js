/* PHP pas à pas — the notebook, chapter 8 « PHP dans une page »: one fiche per notion of the chapter and the
   PHP functions it introduces (content.js, « Mon carnet »). Examples are HTML pages with PHP inside. */
(function (C) {
  'use strict';

  C.fiche({
    notion: 'html',
    rule: '<balise>texte <?= valeur ?></balise>\n<?php instruction; ?>\n<ul>\n<?php foreach ($liste as $element) { ?>\n    <li><?= $element ?></li>\n<?php } ?>\n</ul>',
    points: [
      'Hors des balises PHP, c\'est du **HTML**, envoyé tel quel ; entre `<?php` et `?>`, du **PHP**, exécuté par le serveur.',
      '`<?= valeur ?>` est le raccourci de `<?php echo valeur; ?>` : il affiche une valeur au milieu du HTML, sans point-virgule.',
      'Le navigateur ne reçoit **jamais** le PHP : seulement la page fabriquée, avec ce que le PHP a affiché.',
      'Dans une boucle ou un `if`, le HTML écrit entre `{ ?>` et `<?php }` est écrit à chaque tour, ou seulement si la condition est vraie.'
    ],
    example: '<h1>Muséum</h1>\n<p>Salle du jour : <?= $salle ?></p>\n<ul>\n<?php foreach ($salles as $s) { ?>\n  <li><?= $s ?></li>\n<?php } ?>\n</ul>',
    vars: { salle: 'R2', salles: ['R1', 'R2', 'R3'] },
    traps: [
      '`<ul>` ou `</ul>` écrit dans la boucle fabrique une liste par tour : ce qui n\'apparaît qu\'une fois va **avant** ou **après** la boucle.',
      'Ne cherche pas le PHP dans le code source de la page : le serveur l\'a exécuté, et à la place des balises il ne reste que ce qu\'elles ont affiché.',
      'Chaque `<?php foreach (…) { ?>` se referme plus bas par `<?php } ?>` : une accolade oubliée arrête la page.'
    ],
    fns: []
  });

  C.fiche({
    notion: 'get',
    rule: '<?php $nom = $_GET[\'parametre\'] ?? \'valeur par défaut\'; ?>\n<p><?= htmlspecialchars($nom) ?></p>\n<?php if (is_numeric($texte)) { ?>\n    … calculer avec (int) $texte …\n<?php } ?>',
    points: [
      '`fiche.php?nom=Léa&salle=R2` : PHP range les paramètres de l\'adresse dans le tableau `$_GET`. Leurs valeurs sont **toujours du texte**.',
      'Un paramètre peut **manquer** : `$_GET[\'nom\'] ?? \'visiteur\'` donne une valeur par défaut, `isset($_GET[\'nom\'])` dit s\'il existe.',
      '**Tout ce qui vient de l\'adresse passe par `htmlspecialchars` avant d\'être affiché** : `<` devient `&lt;`, et le navigateur montre le texte au lieu de l\'exécuter.',
      'Un nombre de l\'adresse : vérifie-le avec `is_numeric` avant de le convertir avec `(int)`.'
    ],
    example: '<p>Bonjour <?= htmlspecialchars($_GET[\'nom\'] ?? \'visiteur\') ?></p>\n<?php $n = $_GET[\'n\'] ?? \'\'; ?>\n<?php if (is_numeric($n)) { ?>\n<p><?= (int) $n * 8 ?> €</p>\n<?php } else { ?>\n<p>Nombre invalide</p>\n<?php } ?>',
    vars: { _GET: { nom: '<b>Zoé</b>', n: '3' } },
    traps: [
      'N\'affiche jamais `$_GET[\'nom\']` tel quel : n\'importe qui écrit l\'adresse, et un `<script>` tapé dedans serait exécuté (faille XSS). Écris `htmlspecialchars($_GET[\'nom\'] ?? \'visiteur\')`.',
      '`htmlspecialchars` ne règle pas l\'absence du paramètre, et `??` ne protège de rien : il faut les deux. Et `??` laisse passer le texte vide de `fiche.php?nom=`.',
      '`(int) \'abc\'` vaut 0 sans prévenir : c\'est `is_numeric` qui dit si c\'était un nombre.'
    ],
    fns: ['htmlspecialchars', 'is_numeric', 'isset']
  });

  C.fn({ name: 'htmlspecialchars', notion: 'get', sig: 'htmlspecialchars($texte)', does: 'Renvoie le texte où `<`, `>`, `&` et `"` deviennent `&lt;`, `&gt;`, `&amp;` et `&quot;` : le navigateur les affiche au lieu de les interpréter.', example: '<p><?= htmlspecialchars("<b>Zoé</b> & Tom") ?></p>', note: 'Il n\'efface rien : le texte s\'affiche tel qu\'il a été tapé, balises comprises.' });
  C.fn({ name: 'is_numeric', notion: 'get', sig: 'is_numeric($valeur)', does: 'Renvoie `true` si la valeur est un nombre ou un texte qui en est un (`\'3\'`, `\'2.5\'`), `false` sinon (`\'abc\'`, `\'\'`).', example: '<?php $n = \'3\'; ?>\n<?php if (is_numeric($n)) { ?>\n<p><?= (int) $n * 8 ?> €</p>\n<?php } ?>', note: '`(int) \'abc\'` vaut 0 sans prévenir : teste avec `is_numeric` avant de convertir.' });
})(window.PPPContent);
