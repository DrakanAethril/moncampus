/* Acte 9 — Les coulisses (HTML 04 revision). Game half: bug, criteria, hints, scenes. */
(function (T) {
  'use strict';

  T.add({
    id: 'T39', phase: 'css', page: 'coulisses.html', edit: ['coulisses.html'],
    bug: [
      { file: 'coulisses.html', find: 'action="mise-en-ligne.php" method="post"', replace: 'action="mise-en-ligne.php" method="get"' },
      { file: 'coulisses.html', find: '<input type="password" id="mdp" name="mdp" required>', replace: '<input type="text" id="mdp" name="mdp" required>' },
      { file: 'coulisses.html', find: '<label for="identifiant">Identifiant</label>', replace: '<label>Identifiant</label>' },
      { file: 'coulisses.html', find: '<label for="mdp">Mot de passe</label>', replace: '<label>Mot de passe</label>' }
    ],
    checks: [
      { label: 'Une fois le formulaire envoyé, le mot de passe n\'apparaît pas dans l\'adresse de la page', test: function (h) {
        var f = h.$('form');
        return !!f && (f.getAttribute('method') || '').trim().toLowerCase() === 'post';
      } },
      { label: 'Le mot de passe ne s\'affiche pas en clair pendant qu\'on le tape', test: function (h) {
        var i = h.$$('form input').filter(function (x) { return x.type === 'password'; });
        return i.length === 1 && !!i[0].name;
      } },
      { label: 'Un clic sur « Identifiant » ou « Mot de passe » place le curseur dans le bon champ', test: function (h) {
        var i = h.$$('form input, form select, form textarea').filter(function (x) { return x.type !== 'hidden' && x.type !== 'submit' && x.type !== 'button'; });
        return i.length >= 2 && i.every(function (x) { return x.labels && x.labels.length > 0; });
      } }
    ],
    hints: [
      'Un mot de passe part en POST, dans le corps de la requête, jamais dans l\'adresse ; son champ est de type password ; et chaque <label> se relie à son champ par for, qui reprend l\'id du champ. Cours HTML 04, Les formulaires.',
      'Mets method="post" sur <form>, type="password" sur le champ mdp, et ajoute for="identifiant" et for="mdp" aux deux <label>.'
    ],
    intro: [
      { who: 'maelle', expr: 'etoiles', text: 'C\'est le moment ! On met le site en ligne ! J\'ai les mains qui tremblent !' },
      { who: 'ines', expr: 'sourcil', text: 'Pas si vite. C\'est le formulaire de connexion de Jordan. Avant d\'y taper son mot de passe, on regarde où il part.' }
    ],
    done: [
      { who: 'ines', expr: 'neutre', text: 'Retiens le réflexe : on ne tape jamais un mot de passe dans un formulaire qu\'on n\'a pas relu. Même celui d\'un ami. Surtout celui de Jordan.' }
    ]
  });
})(window.NekoTickets);
