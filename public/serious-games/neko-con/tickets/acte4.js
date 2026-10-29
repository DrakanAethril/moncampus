/* Acte 4 — Les premières inscriptions (course HTML 04: forms). Game half: bug, criteria, hints, scenes.
   Every test is self-contained: it uses only h and the browser's globals (it runs inside the preview).
   Fields are found by the text of their label, so that a renamed id or name is accepted. */
(function (T) {
  'use strict';

  T.add({
    id: 'T15', phase: 'html', page: 'billetterie.html', edit: ['billetterie.html'],
    bug: [{ file: 'billetterie.html', find: '<input type="checkbox" id="reglement" name="reglement" required>', replace: '<input type="checkbox" id="cgu" name="reglement" required>' }],
    checks: [
      { label: 'Cliquer sur « J\'accepte le règlement de la convention » coche la case', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /accepte le règlement/.test(x.textContent); })[0];
        var c = l && l.control;
        if (!c || c.type !== 'checkbox') return false;
        var before = c.checked; l.click(); var after = c.checked; c.checked = before;
        return after !== before;
      } },
      { label: 'Le lecteur d\'écran annonce la case avec son texte', test: function (h) {
        var c = h.reader().filter(function (i) { return i.kind === 'control' && /^Case à cocher/.test(i.text); })[0];
        return { ok: !!c && /accepte le règlement/.test(c.text), detail: c ? 'Le lecteur dit : « ' + c.text + ' »' : '' };
      } },
      { label: 'Accepter le règlement reste obligatoire pour réserver', test: function (h) {
        var c = h.$('form input[type="checkbox"]');
        return !!c && c.required && !!c.name;
      } }
    ],
    hints: [
      'Un <label for="…"> est relié au champ dont l\'id porte exactement la même valeur. Si les deux ne correspondent plus, le texte n\'est plus l\'étiquette de rien. Cours HTML 04, Les formulaires.',
      'La case a id="cgu" et l\'étiquette for="reglement" : fais-les correspondre, par exemple en remettant id="reglement" sur la case.'
    ],
    intro: [
      { who: 'maelle', expr: 'inquiete', text: 'Ma petite sœur a essayé de réserver : elle a cliqué dix fois sur « J\'accepte le règlement », rien ne se coche, donc elle ne peut pas payer. Elle croit que le règlement la refuse.' },
      { who: 'ines', expr: 'neutre', text: 'Normalement, cliquer sur le texte d\'une case suffit à la cocher. Dans l\'aperçu, clique sur l\'un, puis sur l\'autre, et compare.' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'Ça coche ! Ma sœur a réservé. Elle a même lu le règlement, du coup.' },
      { who: 'ines', expr: 'neutre', text: 'Réflexe : avant de renommer un id, cherche-le dans tout le site avec l\'outil de recherche. Une étiquette, une ancre ou une règle CSS s\'en sert peut-être encore.' }
    ]
  });

  T.add({
    id: 'T16', phase: 'html', page: 'billetterie.html', edit: ['billetterie.html'],
    bug: [
      { file: 'billetterie.html', find: '<input type="tel" id="tel" name="tel">', replace: '<input type="text" id="tel" name="tel">' },
      { file: 'billetterie.html', find: '<input type="email" id="email" name="email" required>', replace: '<input type="text" id="email" name="email" required>' }
    ],
    checks: [
      { label: 'Sur téléphone, le champ « Téléphone » ouvre le clavier des numéros', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /Téléphone/.test(x.textContent); })[0];
        var c = l && l.control;
        if (!c) return false;
        return { ok: c.type === 'tel' || (c.getAttribute('inputmode') || '') === 'tel', detail: c.type === 'tel' ? '' : 'Type du champ : ' + c.type };
      } },
      { label: 'Le champ « Adresse e-mail » ouvre le clavier avec @ et vérifie l\'adresse', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /Adresse e-mail/.test(x.textContent); })[0];
        var c = l && l.control;
        if (!c) return false;
        var v = c.value; c.value = 'pas-une-adresse'; var refused = !c.checkValidity(); c.value = v;
        return { ok: c.type === 'email' && refused, detail: c.type === 'email' ? '' : 'Type du champ : ' + c.type };
      } },
      { label: 'Les deux champs partent toujours avec la réservation', test: function (h) {
        var ok = true;
        ['Téléphone', 'Adresse e-mail'].forEach(function (t) {
          var l = h.$$('label').filter(function (x) { return x.textContent.indexOf(t) !== -1; })[0];
          if (!l || !l.control || !l.control.name) ok = false;
        });
        return ok;
      } }
    ],
    hints: [
      'L\'attribut type d\'un <input> dit quel genre de donnée on attend : text, email, tel, number… Le téléphone adapte son clavier, et le navigateur vérifie certains formats. Cours HTML 04, Les formulaires.',
      'Mets type="tel" sur le champ du téléphone et type="email" sur celui de l\'adresse e-mail.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'J\'ai voulu réserver depuis mon téléphone. Pour le numéro, le clavier ne m\'a montré que des lettres. Et pour l\'e-mail, je cherche encore l\'arobase.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Un pavé de chiffres pour le numéro, l\'arobase direct pour l\'e-mail. Je réserve en dix secondes !' },
      { who: 'ines', expr: 'neutre', text: 'Quand tu crées un champ, demande-toi toujours ce qu\'il attend : une date, une adresse, un numéro… Il existe presque toujours un type plus précis que text.' }
    ]
  });

  T.add({
    id: 'T17', phase: 'html', page: 'billetterie.html', edit: ['billetterie.html'],
    bug: [
      { file: 'billetterie.html', find: '<input type="text" id="nom" name="nom" required>', replace: '<input type="text" id="nom" required>' },
      { file: 'billetterie.html', find: '<input type="email" id="email" name="email" required>', replace: '<input type="email" id="email" required>' },
      { file: 'billetterie.html', find: '<input type="tel" id="tel" name="tel">', replace: '<input type="tel" id="tel">' }
    ],
    checks: [
      { label: 'Le nom, l\'adresse e-mail et le téléphone arrivent bien avec chaque réservation', test: function (h) {
        var miss = [];
        ['Nom et prénom', 'Adresse e-mail', 'Téléphone'].forEach(function (t) {
          var l = h.$$('label').filter(function (x) { return x.textContent.indexOf(t) !== -1; })[0];
          if (!l || !l.control || !l.control.name) miss.push(t);
        });
        return { ok: miss.length === 0, detail: miss.length ? 'Ne partent pas : ' + miss.join(', ') : '' };
      } },
      { label: 'Chaque information arrive sous son propre nom, sans en écraser une autre', test: function (h) {
        var f = h.$('form.formulaire');
        if (!f) return false;
        var seen = {}, dup = [];
        Array.prototype.forEach.call(f.elements, function (el) {
          if (!el.name || el.type === 'radio') return;
          if (seen[el.name]) dup.push(el.name); seen[el.name] = true;
        });
        return { ok: dup.length === 0, detail: dup.length ? 'En double : ' + dup.join(', ') : '' };
      } },
      { label: 'Le reste de la réservation part toujours : quantité, formule, carte, règlement', test: function (h) {
        var f = h.$('form.formulaire');
        if (!f) return false;
        var names = Array.prototype.map.call(f.elements, function (el) { return el.name; });
        return ['quantite', 'formule', 'carte', 'reglement'].every(function (n) { return names.indexOf(n) !== -1; });
      } }
    ],
    hints: [
      'Un champ n\'est envoyé que s\'il a un attribut name : c\'est le nom sous lequel le serveur reçoit sa valeur (name=valeur). L\'id, lui, sert au label, pas à l\'envoi. Cours HTML 04, Les formulaires.',
      'Ajoute name="nom", name="email" et name="tel" aux trois champs concernés.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'J\'ai le tableau des réservations du serveur. Douze réservations, douze billets payés… et pas un seul nom. Ni d\'e-mail. On a de l\'argent, mais on ne sait pas à qui.' },
      { who: 'ines', expr: 'neutre', text: 'Fais une réservation de test dans l\'aperçu et regarde l\'onglet « Requêtes ». On va voir ce qui part réellement.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Nom, e-mail, téléphone : tout arrive ! Je vais pouvoir faire mes comptes. Mon activité préférée.' },
      { who: 'ines', expr: 'neutre', text: 'Réflexe à garder : après chaque retouche d\'un formulaire, envoie un test et lis la requête. C\'est le seul moyen de voir ce que le serveur reçoit vraiment.' }
    ]
  });

  T.add({
    id: 'T18', phase: 'html', page: 'billetterie.html', edit: ['billetterie.html'],
    bug: [{ file: 'billetterie.html', find: '<form class="formulaire" action="commande.php" method="post">', replace: '<form class="formulaire" action="commande.php" method="get">' }],
    checks: [
      { label: 'Le numéro de carte n\'apparaît plus dans l\'adresse quand on réserve', test: function (h) {
        var f = h.$('form.formulaire');
        return !!f && (f.getAttribute('method') || 'get').toLowerCase() === 'post';
      } },
      { label: 'La réservation part toujours vers la caisse (commande.php)', test: function (h) {
        var f = h.$('form.formulaire');
        return !!f && f.getAttribute('action') === 'commande.php';
      } },
      { label: 'Le numéro de carte est toujours demandé et envoyé', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /carte bancaire/.test(x.textContent); })[0];
        return !!l && !!l.control && !!l.control.name;
      } }
    ],
    hints: [
      'L\'attribut method de <form> dit comment partent les données : en GET, collées à l\'adresse (visibles, gardées dans l\'historique) ; en POST, dans le corps de la requête. Cours HTML 04, Les formulaires.',
      'Remets method="post" sur la balise <form> de la billetterie.'
    ],
    intro: [
      { who: 'maelle', expr: 'paniquee', text: 'AU SECOURS ! J\'ai réservé pour tester, et mon numéro de carte bancaire est écrit en toutes lettres dans la barre d\'adresse ! Et dans l\'historique ! Et j\'ai envoyé le lien à Théo !' },
      { who: 'ines', expr: 'surprise', text: 'Respire. Personne ne paie avec ta carte de test. Mais un vrai visiteur, si. Envoie le formulaire dans l\'aperçu et regarde la requête.' }
    ],
    done: [
      { who: 'maelle', expr: 'joie', text: 'L\'adresse est propre ! Je vais quand même effacer mon historique. Et demander à Théo d\'oublier le lien.' },
      { who: 'ines', expr: 'neutre', text: 'GET pour lire (une recherche, un filtre), POST pour envoyer des données. Et la prochaine fois qu\'on teste un paiement, Maëlle : un numéro de carte de test, jamais le tien.' }
    ]
  });

  T.add({
    id: 'T19', phase: 'html', page: 'billetterie.html', edit: ['billetterie.html'],
    bug: [{ file: 'billetterie.html', find: 'name="quantite" min="1" max="10" value="1">', replace: 'name="quantite" value="1">' }],
    checks: [
      { label: 'Impossible de réserver moins d\'un billet (−12, 0…)', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /Nombre de billets/.test(x.textContent); })[0];
        var q = l && l.control;
        if (!q) return { ok: false, detail: 'Champ « Nombre de billets » introuvable' };
        var v = q.value, refused = ['-12', '0'].every(function (x) { q.value = x; return !q.checkValidity(); });
        q.value = '1'; var accepted = q.checkValidity(); q.value = v;
        return refused && accepted;
      } },
      { label: 'Ni plus de 10 billets par personne, comme le dit le règlement', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /Nombre de billets/.test(x.textContent); })[0];
        var q = l && l.control;
        if (!q) return false;
        var v = q.value;
        q.value = '11'; var refused = !q.checkValidity();
        q.value = '10'; var accepted = q.checkValidity(); q.value = v;
        return refused && accepted;
      } },
      { label: 'Le champ reste un nombre, avec 1 billet par défaut', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /Nombre de billets/.test(x.textContent); })[0];
        var q = l && l.control;
        return !!q && q.type === 'number' && q.value === '1' && !!q.name;
      } }
    ],
    hints: [
      'Un champ type="number" accepte n\'importe quel nombre, négatif compris, tant qu\'on ne lui donne pas de limites avec les attributs min et max. Cours HTML 04, Les formulaires.',
      'Ajoute min="1" et max="10" au champ de la quantité.'
    ],
    intro: [
      { who: 'theo', expr: 'inquiet', text: 'Quelqu\'un a réservé −12 billets. Moins douze. Le serveur lui doit maintenant 60 euros. Je ne sais pas comment l\'expliquer au proviseur.' },
      { who: 'ines', expr: 'sourcil', text: 'Un petit malin. Commence par empêcher ça dans le formulaire. Ensuite, on parlera de ce que ça ne suffit pas à empêcher.' }
    ],
    done: [
      { who: 'theo', expr: 'joie', text: 'Entre 1 et 10, pas un de plus. Et j\'ai envoyé le message au développeur du serveur. Il a dit « aïe ».' },
      { who: 'ines', expr: 'neutre', text: 'Réflexe de testeur : essaie toujours les valeurs absurdes. Zéro, négatif, énorme, vide. Si tu ne le fais pas, un petit malin le fera pour toi.' }
    ]
  });

  T.add({
    id: 'T20', phase: 'html', page: 'cosplay.html', edit: ['cosplay.html'],
    bug: [{ file: 'cosplay.html', find: '<input type="text" id="pseudo" name="pseudo" required>', replace: '<input type="text" id="pseudo" name="pseudo">' }],
    checks: [
      { label: 'Impossible d\'envoyer l\'inscription sans nom de scène', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /Nom de scène/.test(x.textContent); })[0];
        var p = l && l.control;
        if (!p) return { ok: false, detail: 'Champ « Nom de scène » introuvable' };
        var v = p.value;
        p.value = ''; var refused = !p.checkValidity();
        p.value = 'Kitsune'; var accepted = p.checkValidity(); p.value = v;
        return refused && accepted;
      } },
      { label: 'Le personnage et l\'adresse e-mail restent obligatoires', test: function (h) {
        return ['Personnage', 'Adresse e-mail'].every(function (t) {
          var l = h.$$('label').filter(function (x) { return x.textContent.indexOf(t) !== -1; })[0];
          return !!l && !!l.control && l.control.required;
        });
      } },
      { label: 'Le nom de scène part toujours avec l\'inscription', test: function (h) {
        var l = h.$$('label').filter(function (x) { return /Nom de scène/.test(x.textContent); })[0];
        return !!l && !!l.control && !!l.control.name;
      } }
    ],
    hints: [
      'L\'attribut required rend un champ obligatoire : le navigateur refuse d\'envoyer le formulaire tant qu\'il est vide. Cours HTML 04, Les formulaires.',
      'Ajoute required au champ du nom de scène, comme sur les deux autres.'
    ],
    intro: [
      { who: 'mika', expr: 'neutre', text: 'Bonjour ! Je prépare les fiches du jury. J\'ai déjà trois inscrits sans nom de scène : « Personnage : Sailor Moon », et c\'est tout. Je les appelle comment, sur scène ? « Toi, là » ?' },
      { who: 'ines', expr: 'neutre', text: 'Le personnage et l\'e-mail sont obligatoires, pas le nom de scène. Cherche la différence.' }
    ],
    done: [
      { who: 'mika', expr: 'joie', text: 'Parfait ! Plus d\'inscription anonyme. Et merci pour les candidats : je déteste les appeler « Toi, là ».' },
      { who: 'ines', expr: 'neutre', text: 'Un champ obligatoire, dis-le aussi au visiteur, dans l\'étiquette. Le navigateur bloque l\'envoi, d\'accord, mais c\'est plus aimable de prévenir avant que de refuser après.' }
    ]
  });
})(window.NekoTickets);
