/* Panique à la Neko-Con — the story: the prologue, the opening of each act, the bascule, the end.
   A line: { who: 'maelle' | 'ines' | 'theo' | 'mika' | 'narration', expr, text }. */
(function (root) {
  'use strict';
  root.NekoStory = {
    prologue: [
      { who: 'narration', text: 'Vendredi, 16 h 30. Salle du club manga. Demain matin, la Neko-Con ouvre ses portes.' },
      { who: 'maelle', expr: 'joie', text: 'Te voilà ! Tu es le stagiaire du service informatique ? Génial ! Moi, c\'est Maëlle, présidente du club manga.' },
      { who: 'maelle', expr: 'inquiete', text: 'Petit souci : Jordan, l\'ancien président, s\'est occupé du site… puis il est parti étudier au Japon. Sans nous laisser les accès.' },
      { who: 'ines', expr: 'neutre', text: 'Inès. Je serai ta tutrice. J\'ai jeté un œil au site. Il est… comment dire…' },
      { who: 'ines', expr: 'soupir', text: 'Cassé. Un peu partout.' },
      { who: 'maelle', expr: 'paniquee', text: 'Et la convention ouvre DEMAIN !' },
      { who: 'ines', expr: 'neutre', text: 'Chaque problème arrivera sous forme de ticket, dans le tableau Kanban. On commence par le fond : le HTML. La feuille de style, on la rebranchera après.' },
      { who: 'ines', expr: 'sourcil', text: 'Et pour mettre le site en ligne, il faudra retrouver le mot de passe de Jordan. Ouvre l\'œil : il notait tout n\'importe où.' },
      { who: 'maelle', expr: 'joie', text: 'Allez, au travail ! Senpai compte sur toi !' },
      { who: 'ines', expr: 'sourcil', text: '… Personne ne t\'appelle « senpai », Maëlle.' }
    ],
    acts: {
      1: [
        { who: 'maelle', expr: 'neutre', text: 'Les premiers tickets sont dans la colonne « À faire ». Clique sur une carte pour la prendre en charge.' },
        { who: 'ines', expr: 'neutre', text: 'Quatre tickets ouverts au maximum : la file se remplit à mesure que tu en fermes. Commence par celui que tu veux.' }
      ],
      2: [
        { who: 'ines', expr: 'neutre', text: 'J\'ai relu ton HTML. Ça s\'affiche, mais un lecteur d\'écran ou un moteur de recherche n\'y comprendrait pas grand-chose.' },
        { who: 'ines', expr: 'sourire', text: 'Je te débloque deux outils : le lecteur d\'écran simulé et l\'aperçu dans un moteur de recherche. Ils sont dans les onglets, au-dessus du site.' }
      ],
      3: [
        { who: 'maelle', expr: 'joie', text: 'Théo a fini le planning de la journée ! Enfin… il l\'a commencé.' },
        { who: 'theo', expr: 'inquiet', text: 'J\'ai un peu galéré avec les cases fusionnées. Et le tableau. Et les cases.' }
      ],
      4: [
        { who: 'maelle', expr: 'etoiles', text: 'La billetterie est ouverte ! Les premières réservations arrivent !' },
        { who: 'theo', expr: 'inquiet', text: '… Et les premiers problèmes aussi.' },
        { who: 'ines', expr: 'neutre', text: 'Je t\'ouvre le moniteur de requêtes : quand tu envoies un formulaire dans l\'aperçu, tu vois exactement ce qui part vers le serveur.' }
      ],
      5: [
        { who: 'ines', expr: 'neutre', text: 'Le HTML tient debout. Il est temps de rebrancher la feuille de style.' },
        { who: 'maelle', expr: 'etoiles', text: 'Enfin des couleurs ?' },
        { who: 'ines', expr: 'sourcil', text: 'Enfin des couleurs. Et sans doute quelques surprises.' }
      ],
      6: [
        { who: 'ines', expr: 'soupir', text: 'Comme prévu : la feuille de style de Jordan est pleine de bugs.' },
        { who: 'maelle', expr: 'paniquee', text: 'Les tickets arrivent de partout !' },
        { who: 'ines', expr: 'neutre', text: 'Un ticket à la fois. Tu peux changer la largeur de l\'aperçu : téléphone, tablette, ordinateur. Et rappelle-toi : pas de !important pour gagner une bataille de sélecteurs.' }
      ],
      7: [
        { who: 'maelle', expr: 'inquiete', text: 'Je viens de regarder le site sur mon téléphone…' },
        { who: 'maelle', expr: 'paniquee', text: 'C\'est une catastrophe. Et la moitié des visiteurs viendront avec leur téléphone !' }
      ],
      8: [
        { who: 'maelle', expr: 'joie', text: 'Ah, et j\'avais oublié ! Il nous faut un plan des stands sur le site. Pour demain matin.' },
        { who: 'ines', expr: 'soupir', text: 'Évidemment.' },
        { who: 'theo', expr: 'neutre', text: 'Jordan avait commencé une page « exposants ». Avec une grille, je crois.' }
      ],
      9: [
        { who: 'ines', expr: 'neutre', text: 'Le site est prêt. Reste à le mettre en ligne, depuis la page cachée de Jordan : coulisses.html.' },
        { who: 'maelle', expr: 'inquiete', text: 'Tu as bien tous les morceaux du mot de passe ?' }
      ]
    },
    bascule: [
      { who: 'maelle', expr: 'etoiles', text: 'Des COULEURS ! Notre convention a des couleurs ! Inès, regarde, on dirait un vrai site pro !' },
      { who: 'narration', text: 'Inès a l\'air moins enthousiaste. Elle fixe le menu.' },
      { who: 'ines', expr: 'sourcil', text: 'Profite. Les tickets vont tomber.' }
    ],
    final: [
      { who: 'narration', text: 'Samedi, 9 h 58. Devant le gymnase, la file d\'attente fait le tour du parking.' },
      { who: 'maelle', expr: 'etoiles', text: 'Le site est en ligne ! Les billets se vendent, le planning se lit, même sur téléphone !' },
      { who: 'theo', expr: 'joie', text: 'Et le chat gonflable tient debout. Enfin.' },
      { who: 'mika', expr: 'joie', text: 'C\'est toi qui as réparé le site ? Alors tu auras la première dédicace de la journée.' },
      { who: 'ines', expr: 'sourire', text: 'Beau travail. Vraiment. Jordan aurait dû te laisser ses accès dès le début.' },
      { who: 'narration', text: '10 h. Les portes de la Neko-Con s\'ouvrent.' }
    ],
    // The closing certificate: what each act worked on.
    skills: {
      1: 'Écrire la structure minimale d\'une page, des liens et des images qui fonctionnent',
      2: 'Structurer une page avec les balises sémantiques et un plan de titres sans trou',
      3: 'Construire un tableau de données avec des cellules fusionnées et ses en-têtes',
      4: 'Construire un formulaire accessible et choisir entre GET et POST',
      5: 'Relier une feuille de style et lire une règle CSS',
      6: 'Maîtriser la cascade, le modèle de boîte, le positionnement et Flexbox',
      7: 'Adapter une page aux téléphones : viewport, images fluides, media queries',
      8: 'Construire une mise en page avec CSS Grid',
      9: 'Sécuriser un formulaire de connexion'
    }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
