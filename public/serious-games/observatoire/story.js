/* L'Observatoire — the journal of Hélène Vasseur, told page by page (shared/engage.js, `story`).

   One page opens at each star lit, in this order whatever order the stars are lit in; the last
   page opens once La Couronne has been played to its end. It agrees with the carnet's lines of the
   main screen (game.js, PROLOGUE and BEATS): the corrected drafts of the left drawer (8), the
   letter in the telescope tube (16), the empty circle (24), the Astrolabe (31).

   The mystery: a star of the central circle, which Marguerite Albaret noted « A. », goes out every
   fifty-two years. Hélène left before the storm to watch it go out from the Pic du Midi; measuring
   its return needs the stars around it, so the whole sky map - which the player rebuilds. */
(function (root) {
  'use strict';

  root.OBSStory = {
    title: 'Le journal d’Hélène',
    lockedPage: function (need) { return 'Cette page s’écrit à la ' + need + (need === 1 ? 're' : 'e') + ' étoile allumée.'; },
    lockedFinale: 'La dernière page ne s’ouvre qu’une fois la Couronne jouée jusqu’au bout.',
    pages: [
      { title: 'À qui lira', text: [
        'Ce journal n’est pas fait pour être lu d’un trait. Il s’ouvrira page par page, à mesure que le ciel reviendra : une étoile, une page.',
        'Vous venez d’en rallumer une. C’est donc que vous calculez, et c’est tout ce que j’avais besoin de savoir de vous. — H. V.'] },
      { title: 'Marguerite', text: [
        '12 mars. L’observatoire a été construit en 1961 par Marguerite Albaret, avec l’argent de sa ferme et l’aide de trois maçons du village. C’est elle qui a dessiné la grande carte du ciel, à la main, étoile par étoile.',
        'J’avais douze ans quand elle m’a laissée regarder pour la première fois. Je ne suis jamais vraiment repartie.'] },
      { title: 'La règle de la maison', text: [
        '14 mars. Marguerite n’avait qu’une règle, peinte au-dessus de la porte : « On calcule d’abord, on regarde ensuite. »',
        'Le télescope ne trouve que ce qu’on lui a appris à chercher. Un calcul faux, et il fixe un ciel vide en toute confiance.'] },
      { title: 'Une étoile qui faiblit', text: [
        '2 avril. Au centre de la carte, dans le petit cercle que Marguerite appelait la Couronne, il y a une étoile sans nom. Elle l’a seulement notée « A. ».',
        'Ce soir, A. est plus pâle que sur les plaques de Marguerite. Je ne l’aurais pas remarqué si je ne l’avais pas regardée chaque nuit depuis cinquante ans.'] },
      { title: 'Mesurer', text: [
        '30 avril. Un mois de mesures. A. a perdu 4 % de son éclat. En mai, encore 4 %, mais de ce qui restait.',
        'Ce n’est pas la même chose, et c’est toute la différence entre une étoile qui s’éteint d’un coup et une étoile qui respire.'] },
      { title: 'Le registre du grenier', text: [
        '9 mai. Dans le grenier, sous les cartons de plaques, le registre de Marguerite. À la date du 3 novembre 1974, une ligne : « A. disparue. »',
        'Et un an plus tard, d’une écriture qui tremble un peu : « A. revenue. Elle était là, Hélène aussi. »'] },
      { title: 'Joseph', text: [
        '15 mai. J’ai écrit à Joseph Ferrand, à l’observatoire du Pic du Midi. Nous nous sommes connus étudiants ; il croit aux instruments, je crois aux registres.',
        'Sa réponse tient en une phrase : « Une étoile qui disparaît et revient, c’est une erreur de mesure ou un miracle. Je ne crois pas aux miracles. Envoie-moi tes chiffres. »'] },
      { title: 'Les brouillons rouges', text: [
        '20 mai. J’ai rangé dans le tiroir de gauche tous mes calculs ratés, corrigés à l’encre rouge. Il y en a plus que de justes.',
        'Je vous les laisse. Mes erreurs m’ont appris davantage que mes réussites, et elles vous feront gagner du temps : vous ferez sans doute les mêmes.'] },
      { title: 'Une hypothèse', text: [
        '4 juin. Si A. disparaît et revient, quelque chose passe peut-être devant elle. Un compagnon sombre, qui tourne autour d’elle sans jamais briller.',
        'Joseph appelle ça une binaire à éclipse. Moi, j’appelle ça une hypothèse : tant qu’on ne l’a pas mesurée, ce n’est qu’une jolie phrase.'] },
      { title: 'Trois semaines de brouillard', text: [
        '28 juin. Pas une nuit claire depuis trois semaines. Képler, le chat, dort sur les plaques ; moi, je recompte.',
        'Marguerite disait que le ciel n’est jamais en retard. C’est nous qui sommes pressés.'] },
      { title: 'Le cahier de 1922', text: [
        '7 juillet. Dans le registre, une page collée, d’une autre écriture : celle d’Émile Albaret, le père de Marguerite, instituteur et astronome du dimanche.',
        '« 3 novembre 1922. L’étoile du cercle a disparu. Personne ne me croit. »'] },
      { title: 'La nuit où je suis venue', text: [
        '8 juillet. Je comprends maintenant pourquoi Marguerite m’a fait monter ce soir-là, en octobre 1975. Elle attendait le retour de A. depuis un an, seule.',
        'Elle m’a mis l’œil à l’oculaire et m’a dit : « Elle revient pour ceux qui calculent. » J’ai cru que c’était une phrase pour les enfants.'] },
      { title: 'Cinquante-deux ans', text: [
        '15 juillet. 1922, 1974. Cinquante-deux ans entre deux disparitions. Si la suite continue, la prochaine est pour cette année.',
        'J’ai refait le calcul dix fois. Il tombe toujours sur la même nuit : le 3 novembre.'] },
      { title: 'Ce que Joseph ne dit pas', text: [
        '2 août. Joseph a reçu mes chiffres. Il ne parle plus de miracle. Il me demande les dates exactes, l’heure, la hauteur de l’étoile sur l’horizon.',
        'Chez lui, c’est une façon de dire : tu as peut-être raison.'] },
      { title: 'Pourquoi je partirai', text: [
        '20 août. Personne n’a jamais vu A. s’éteindre. Émile et Marguerite l’ont trouvée éteinte, au matin. Moi, je veux voir le moment exact.',
        'Ici, en novembre, le ciel est couvert neuf nuits sur dix. Au Pic du Midi, on observe au-dessus des nuages. Je partirai là-bas.'] },
      { title: 'La lettre du tube', text: [
        '29 août. En nettoyant le télescope, une lettre roulée dans le tube. L’écriture de Marguerite : « Je n’ai jamais compté mes séries sur le Serpent. Ne comptez pas les vôtres. »',
        'Elle l’a laissée pour moi, j’en suis sûre. Je la remets à sa place. Elle est pour vous, maintenant.'] },
      { title: 'Les étoiles voisines', text: [
        '6 septembre. On ne mesure pas l’éclat d’une étoile seule. On le compare à celui de ses voisines, dont on connaît l’éclat.',
        'Voilà pourquoi la carte entière compte : sans les autres étoiles, on ne saura jamais dire si A. est revenue, ni de combien.'] },
      { title: 'Une erreur de Marguerite', text: [
        '11 septembre. Dans le registre de 1974, Marguerite s’est trompée d’un facteur dix, puis elle a barré, et recommencé dans la marge.',
        'Je ne l’ai jamais tant aimée qu’en lisant cette rature.'] },
      { title: 'Képler', text: [
        '19 septembre. Képler a disparu deux jours. Je l’ai cherché partout, jusqu’à la coupole.',
        'Il est revenu ce matin, comme si de rien n’était. J’y vois un encouragement.'] },
      { title: 'Trois nuits sur quatre', text: [
        '30 septembre. Joseph m’écrit qu’au Pic, en novembre, trois nuits sur quatre sont claires au-dessus des nuages.',
        'J’ai fait le calcul : une seule nuit, trois chances sur quatre. Deux nuits pour plus de sûreté, et le risque de tout manquer tombe à une chance sur seize. Je resterai une semaine.'] },
      { title: 'Le compagnon', text: [
        '8 octobre. Les mesures de l’été sont formelles : la baisse est régulière, sans à-coups. Quelque chose de sombre glisse devant A., lentement, comme un rideau.',
        'Émile ne pouvait pas le savoir. Marguerite le devinait. Moi, je le mesure.'] },
      { title: 'La tempête annoncée', text: [
        '20 octobre. Météo-France annonce une tempête pour le début de novembre. La coupole fuit depuis l’hiver ; les plaques souffriront.',
        'Si la carte du ciel s’efface, il faudra la refaire, étoile par étoile, comme Marguerite l’avait faite. Ce ne sera pas moi : je serai là-haut.'] },
      { title: 'La valise', text: [
        '29 octobre. Dans la valise : le registre, trois carnets, des gants. L’Astrolabe reste ici, sous la carte.',
        'Il ne doit sortir que pour celui ou celle qui aura rallumé tout le ciel.'] },
      { title: 'Le cercle vide', text: [
        '30 octobre. J’ai effacé A. de la carte à la craie, pour ne pas tricher. Au centre, il ne reste qu’un cercle vide.',
        'Il se remplira quand toutes les autres étoiles seront revenues : avant, on n’aurait rien pour la comparer.'] },
      { title: 'La veille', text: [
        '1er novembre. J’écris les dernières pages de ce journal pour vous, que je ne connais pas.',
        'Demain je pars. Je ne sais pas qui viendra, ni quand. Je sais seulement que la personne qui lira ceci aura appris à calculer avant de regarder.'] },
      { title: 'Au-dessus des nuages', text: [
        'Carte postale du Pic du Midi, 3 novembre. En bas, la tempête couvre toute la plaine : une mer grise, immobile. Au-dessus, un ciel d’une netteté que je n’avais jamais vue.',
        'Joseph a préparé le télescope. A. est là, très pâle. Nous attendons.'] },
      { title: '3 h 12', text: [
        '4 novembre, au matin. À 3 h 12, A. s’est éteinte. Pas d’un coup : en quarante minutes, comme une bougie qu’on baisse.',
        'Personne ne l’avait jamais vu. Joseph n’a rien dit pendant un long moment. Puis il a écrit l’heure, trois fois, pour être sûr.'] },
      { title: 'La question qui reste', text: [
        '5 novembre. Elle reviendra. Mais quand ? En 1975, il a fallu presque un an. Je ne peux pas rester au Pic une année entière, et ses instruments servent à d’autres.',
        'Son retour se verra depuis chez nous, avec notre télescope et la carte complète. C’est-à-dire avec vous.'] },
      { title: 'Ce que je vous confie', text: [
        '6 novembre. Je vous confie le ciel comme Marguerite me l’a confié : sans mode d’emploi, avec un carnet et une règle peinte au-dessus de la porte.',
        'Vous avez déjà fait l’essentiel. Le reste n’est qu’une question de patience, et la patience ne se compte pas.'] },
      { title: 'Presque', text: [
        'Si vous lisez cette page, il ne manque presque plus rien. Si mes calculs sont justes, A. est déjà en train de revenir, encore trop faible pour qu’on la voie.',
        'Rallumez la dernière étoile. Les voisines de A. seront alors toutes là pour témoigner.'] },
      { title: 'L’Astrolabe', text: [
        'L’Astrolabe était à Marguerite ; elle me l’a donné l’été de mes treize ans. Il est à vous, maintenant.',
        'Au centre de la carte, le cercle vide attend. Marguerite l’appelait la Couronne. Jouez-la jusqu’au bout : c’est la dernière mesure, et la seule qui compte pour A.'] }
    ],
    finale: { title: 'L’étoile d’Albaret', text: [
      'Lettre arrivée par la poste, sans date. Ma chère inconnue, mon cher inconnu,',
      'Joseph m’a appelée cette nuit : depuis notre télescope, au centre de la carte que vous avez refaite, une étoile est réapparue dans le cercle de la Couronne. Il l’a mesurée contre ses voisines, une à une. Ce sont vos étoiles qui ont permis de le dire.',
      'Cent quatre ans après Émile, cinquante-deux après Marguerite, A. est revenue, et quelqu’un l’attendait. Je l’ai inscrite au registre sous son nom entier : l’étoile d’Albaret.',
      'Dans la marge, juste à côté, j’ai écrit votre prénom, {prenom}. C’est vous qui l’avez vue revenir. Marguerite avait raison : elle revient pour ceux qui calculent. — Hélène Vasseur'] }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
