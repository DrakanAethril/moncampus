/* Service de nuit — the story: the prologue, the five acts, the epilogue and the grades. */
(function (root) {
  'use strict';
  root.SDNStory = {
    prologue: [
      { who: 'narr', text: 'Novembre. Une petite ville, une rue en pente, et en haut de la rue une enseigne au néon dont la moitié des lettres sont éteintes : HÔTEL DES QUATRE-VENTS.' },
      { who: 'narr', text: 'Trente chambres, une loge, et depuis trente ans le même veilleur de nuit : Augustin. Il a aussi écrit le site de l\'hôtel, en PHP, à la main, pendant ses nuits.' },
      { who: 'narr', text: 'Dimanche, Augustin est parti. Il a laissé sa clé, son site… et une pile de carnets. Ce soir, la loge est à toi.' }
    ],
    acts: {
      1: { time: 'Première semaine', blurb: 'La page d\'accueil d\'Augustin, abîmée par un neveu bien intentionné. Le serveur qui fabrique la page à chaque visite : echo, date(), les variables, les morceaux de texte qu\'on colle et les comptes du séjour.', notions: ['<?php ?> et echo', 'date()', 'variables', 'concaténation', 'calculs'] },
      2: { time: 'Deuxième semaine', blurb: 'La page sait l\'heure ; elle doit maintenant décider. Bonjour ou bonsoir, le petit-déjeuner de Josette, le week-end et la nuit.', notions: ['if / else', 'elseif', '&& et ||'] },
      3: { time: 'Troisième semaine', blurb: 'L\'hôtel s\'agrandit, la navette arrive, et les couloirs comptent quarante chambres. Les boucles, puis le fichier d\'Augustin, lu ligne à ligne.', notions: ['for', 'while', 'boucle et conditions', 'lire du code'] },
      4: { time: 'Les étages', blurb: 'Le carnet rouge de la chambre 404 ouvre le deuxième niveau : des cases numérotées, des cases avec un nom, et une boucle qui les parcourt toutes.', notions: ['tableaux', 'foreach', 'tableaux associatifs'] },
      5: { time: 'Quatrième semaine', blurb: 'Ce qui s\'écrit deux fois devient une fonction. Les clients écrivent à l\'hôtel par un formulaire. Et le client de la 12 arrive, comme chaque jeudi.', notions: ['fonctions et include', 'formulaires et $_GET', 'htmlspecialchars'] }
    },
    epilogue: [
      { who: 'narr', text: 'Jeudi, 23 h 12. L\'enseigne des Quatre-Vents brille en entier, pour la première fois depuis des années.' },
      { who: 'augustin', expr: 'sourire', text: 'Trente ans, j\'ai cru que le site tenait parce que je veillais dessus. Il tient parce que quelqu\'un a appris à le lire.' },
      { who: 'mireille', expr: 'sourire', text: 'La loge est à toi. Les carnets aussi.' },
      { who: 'sami', expr: 'sourire', text: 'Et le week-end prochain, c\'est toi qui m\'expliques les tableaux associatifs.' }
    ],
    // Mirrors the manifest's grades (game.yaml), by nights closed.
    grades: [[0, 'Veilleur·se stagiaire'], [4, 'Veilleur·se'], [8, 'Veilleur·se de confiance'], [12, 'Chef·fe de loge'], [17, 'Gardien·ne des Quatre-Vents']]
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
