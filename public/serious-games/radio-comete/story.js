/* Radio-Comète — the story, on the game's side: the hours of the acts, their announcements, the
   prologue and the epilogue (scenes), and the grades. The platform holds the mystery (the five clues);
   the words around it are here. */
(function (root) {
  'use strict';
  root.RCStory = {
    acts: {
      1: { time: '10 H 20', clock: [10, 20], blurb: 'Jeudi matin. Marc a laissé un mot et des scripts. Le premier, celui des sauvegardes, se lit et se répare : guillemets, variables, boucles, codes de retour.', notions: ['guillemets', 'variables', 'boucles', 'getopts', 'codes de retour'] },
      2: { time: '13 H 45', clock: [13, 45], blurb: 'Douze serveurs, douze crontabs. Les comparer à la main est impossible : on écrit les scripts qui le font, et on trouve la ligne que les autres n\'ont pas.', notions: ['ssh', 'diff et comm', 'sort et uniq', 'sed', 'cron'] },
      3: { time: '17 H 30', clock: [17, 30], blurb: 'Le script de Marc, écrit en 2019, n\'a pas un commentaire. On le lit comme on lirait celui d\'un autre : fonctions, valeurs par défaut, find. Et on le rejoue à blanc.', notions: ['${A:-B}', 'find -delete', 'fonctions', 'lecture à blanc'] },
      4: { time: '21 H 15', clock: [21, 15], blurb: 'La configuration de chaque serveur dit-elle la même chose ? On la lit, on la contrôle, et on mesure ce que la tâche de 3 h a emporté.', notions: ['while read', 'awk et cut', 'tests de fichiers', 'agrégats'] },
      5: { time: '02 H 40', clock: [2, 40], blurb: 'Il est presque trois heures. On réécrit le nettoyage avec des garde-fous, on remplace le cron par un timer, on restaure les archives, et on regarde passer la nuit.', notions: ['set -euo pipefail', 'flock', 'journal', 'systemd', 'idempotence'] }
    },
    prologue: [
      { who: 'narr', text: 'Jeudi, 10 h 20. Radio Comète, une radio associative installée dans une ancienne poste. Douze serveurs, un poste de régie, et un mot laissé sur le bureau.' },
      { who: 'odile', expr: 'sourcil', text: 'Marc est parti lundi, sans prévenir. Sur la table, il a écrit : « Tout est dans les scripts. »' },
      { who: 'yanis', expr: 'surprise', text: 'Et ce matin, mes émissions de 2019 ne sont plus sur le serveur d\'archives. Encore. Elles s\'évaporent par petits paquets.' },
      { who: 'colette', expr: 'sourire', text: 'Je suis Colette. J\'ai monté la première régie de cette radio en 1987. Les scripts, je ne les lis plus très bien, mais je sais comment on cherche.' },
      { who: 'colette', expr: 'neutre', text: 'Tu es l\'alternant·e du prestataire. Commence par lire ce que Marc a laissé. Je tiens le carnet : chaque piste, je la note.' },
      { who: 'odile', expr: 'neutre', text: 'On a jusqu\'à vendredi matin, avant la reprise de la matinale. Chaque nuit, à 3 h, quelque chose s\'exécute que personne n\'a écrit. Trouvons quoi.' }
    ],
    epilogue: [
      { who: 'narr', text: 'Vendredi, 3 h 00. Le nouveau timer passe. Pour la première fois depuis septembre, rien n\'est supprimé.' },
      { who: 'colette', expr: 'sourire', text: 'Un message de Marc vient d\'arriver sur le téléphone d\'Odile. Je te le lis.' },
      { who: 'marc', expr: 'neutre', text: '« Salut la régie. Ce script de nettoyage, je l\'avais mis en pause avant la migration, puis réactivé en partant... sans changer le chemin. Pardon. Il avait servi dix ans sur l\'ancien serveur. »' },
      { who: 'marc', expr: 'sourire', text: '« Un script qui supprime doit toujours se méfier de lui-même. Vous l\'avez fait. Prenez soin de la radio. »' },
      { who: 'odile', expr: 'sourire', text: 'Les émissions de Yanis sont revenues. On se souviendra de cette nuit de 3 h.' },
      { who: 'yanis', expr: 'sourire', text: 'Je vais les remettre à l\'antenne. Et cette fois, je dors.' }
    ],
    grades: [[0, 'Bénévole'], [10, 'Technicien·ne'], [20, 'Administrateur·rice'], [30, 'Scripteur·rice'], [40, 'Gardien·ne de la nuit']]
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
