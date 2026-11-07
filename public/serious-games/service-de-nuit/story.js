/* Service de nuit — the story, kept to the setting: the prologue, the five acts, the ending, the optional
   epilogue (Augustin's file, read once the game is over) and the grades. */
(function (root) {
  'use strict';
  root.SDNStory = {
    prologue: [
      { who: 'narr', text: 'Une rue en pente, et en haut de la rue une enseigne au néon à moitié éteinte : HÔTEL DES QUATRE-VENTS. Trente chambres, une loge, et un site écrit en PHP.' },
      { who: 'narr', text: 'Augustin, le veilleur de nuit qui l\'avait écrit, est parti à la retraite. Il a laissé ses carnets. Ce soir, la loge est à toi.' }
    ],
    acts: {
      1: { time: 'Première semaine', blurb: 'La page d\'accueil : le serveur qui l\'écrit à chaque visite, les variables, les phrases qu\'on colle et les comptes du séjour.', notions: ['<?php ?> et echo', 'date()', 'variables', 'concaténation', 'calculs'] },
      2: { time: 'Deuxième semaine', blurb: 'La page sait l\'heure ; elle doit maintenant choisir. Bonjour ou bonsoir, le petit-déjeuner, le week-end et la nuit.', notions: ['if / else', 'elseif', '&&', '||'] },
      3: { time: 'Troisième semaine', blurb: 'Quarante chambres et une navette : les boucles, une décision à chaque tour, puis lire le code d\'un autre.', notions: ['for', 'while', '%', 'continue', 'lire du code'] },
      4: { time: 'Quatrième semaine', blurb: 'Le deuxième niveau : des cases numérotées, une boucle qui les parcourt toutes, des cases avec un nom.', notions: ['tableaux', 'foreach', 'count()', 'tableaux associatifs'] },
      5: { time: 'Cinquième semaine', blurb: 'Ce qui s\'écrit deux fois devient une fonction. Les clients écrivent à l\'hôtel par un formulaire, et pas toujours gentiment.', notions: ['fonctions', 'include', '$_GET', 'htmlspecialchars'] }
    },
    ending: [
      { who: 'mireille', expr: 'sourire', text: 'Le site tient tout seul, et c\'est toi qui l\'as écrit. La loge est à toi.' }
    ],
    // The optional epilogue, opened from the end screen: the file Augustin left, and who he left it for.
    epilogueIntro: [
      { who: 'mireille', expr: 'surprise', text: 'En rangeant la loge, j\'ai trouvé un fichier d\'Augustin que le site n\'a jamais affiché : pied.php. Il parle du jeudi, à 23 h 12. Tu le lis ?' }
    ],
    epilogue: [
      { who: 'narr', text: 'Jeudi, 23 h 12. La porte s\'ouvre. Un homme âgé pose une petite valise devant le comptoir et demande la 12.' },
      { who: 'augustin', expr: 'sourire', text: 'Bonsoir. Je voulais voir entre quelles mains je laissais la loge. Il paraît que la page dit « Bonne nuit » après 23 h, maintenant.' },
      { who: 'augustin', expr: 'neutre', text: 'Trente ans, j\'ai cru que le site tenait parce que je veillais dessus. Il tient parce que quelqu\'un a appris à le lire.' }
    ],
    // Mirrors the manifest's grades (game.yaml), by nights closed.
    grades: [[0, 'Veilleur·se stagiaire'], [6, 'Veilleur·se'], [13, 'Veilleur·se de confiance'], [20, 'Chef·fe de loge'], [28, 'Gardien·ne des Quatre-Vents']]
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
