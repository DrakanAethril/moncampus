/* The Irregulars — the gang's own investigation, told page by page (shared/engage.js, `story`).

   While the player solves the guv'nor's cases, the Irregulars follow one of their own, which
   nobody at Scotland Yard has noticed: the Silver Owl of the Museum, which hooted the hour, has
   gone silent. One page opens at each case solved, in this order whatever order the cases are
   solved in; the four pages of each place follow the board's places (the Docks, Covent Garden,
   the Lyceum, the Museum, Paddington). The last page, the solution, opens once The Final Case has
   been played to its end.

   It agrees with Wiggins's lines of the main screen (game.js, PROLOGUE and BEATS): the cabmen (5),
   Mrs Hudson's biscuit (10), Lestrade asking for the player by name (15), the guv'nor away on the
   night of The Final Case and his magnifying glass (20). English lines are A2, the French follows. */
(function (root) {
  'use strict';

  root.IRRStory = {
    title: 'L’enquête des Irregulars',
    lockedPage: function (need) { return 'Cette page s’écrit à la ' + need + (need === 1 ? 're' : 'e') + ' affaire résolue.'; },
    lockedFinale: 'La dernière page ne s’écrit qu’une fois The Final Case jouée jusqu’au bout.',
    pages: [
      { title: 'Une caisse à minuit', text: [
        'Aux Docks, cette nuit-là, Simpson, le plus petit de la bande, voit des dockers charger une caisse marquée « British Museum — Plaster » sur un bateau pour Calais.',
        '<i lang="en">« Museums don’t send plaster at midnight. »</i> Les musées n’envoient pas de plâtre à minuit, dit Wiggins. Le patron a trop d’affaires : celle-ci, les Irregulars la gardent pour eux.'] },
      { title: 'Le chapeau gris', text: [
        'Le lendemain, un homme en chapeau melon gris traîne sur le quai. Il ne regarde pas les bateaux : il regarde la bande, et il écrit dans un petit carnet noir.',
        '<i lang="en">« Don’t look at him. Walk. »</i> Ne le regarde pas. Marche. Tu marches, mais tu as eu le temps de voir ses chaussures : neuves, et beaucoup trop propres pour les Docks.'] },
      { title: 'Une plume d’argent', text: [
        'Entre deux pavés, à l’endroit où la caisse attendait, quelque chose brille. Une plume minuscule, en argent, gravée trait par trait.',
        'Wiggins la range dans une boîte d’allumettes. <i lang="en">« Birds don’t lose silver feathers. »</i> Les oiseaux ne perdent pas de plumes d’argent.'] },
      { title: 'Le registre du quai', text: [
        'Le commis des Docks n’aime pas les enfants, mais il aime qu’on lui pose les bonnes questions. Dans son registre : caisse n° 17, expédiée lundi, <i lang="en">« returned to sender »</i>, revenue à l’expéditeur mardi.',
        'Le nom de l’expéditeur est écrit d’une main fine : E. Finch. Finch, comme le pinson. Encore un oiseau.'] },
      { title: 'Les cochers parlent', text: [
        'Les cochers connaissent ta tête, maintenant. L’un d’eux se souvient d’une jeune femme, mardi, une boîte à chapeau sur les genoux, qu’il a conduite des Docks à Covent Garden.',
        '<i lang="en">« She held that box like a baby. »</i> Elle tenait cette boîte comme un bébé. Wiggins épingle la plume à votre propre tableau, avec un fil rouge vers Covent Garden.'] },
      { title: 'Les violettes d’Ada', text: [
        'À Covent Garden, Ada vend des violettes enveloppées dans du papier imprimé. Tu reconnais une page du catalogue du Museum : « La Chouette d’argent, 1780. Elle chante l’heure. »',
        'Ada a trouvé tout un paquet de ces pages derrière une boutique fermée. <i lang="en">« Good paper. Free, too. »</i> Du bon papier, et gratuit.'] },
      { title: 'Le chapeau gris, encore', text: [
        'L’homme au chapeau gris achète un bouquet à Ada. Il ne sent pas les fleurs : il demande si « ces enfants » viennent souvent.',
        'Ada répond qu’elle ne connaît aucun enfant. Elle te fait un clin d’œil. Le carnet noir se referme. Qui est cet homme, et pour qui écrit-il ?'] },
      { title: 'Une chouette muette', text: [
        'Dans le journal du matin, un entrefilet : depuis une semaine, la Chouette d’argent du Museum ne chante plus l’heure. Le conservateur adjoint, Mr Pemberton, parle d’une « petite réparation ».',
        '<i lang="en">« A small repair? At midnight, by boat? »</i> Une petite réparation ? À minuit, en bateau ? Wiggins plie le journal en quatre.'] },
      { title: 'Finch & Daughter', text: [
        'La boutique fermée derrière l’étal d’Ada porte une enseigne délavée : « Finch & Daughter, horlogers ». Sur la porte, un carton : <i lang="en">« Back soon »</i>, de retour bientôt.',
        'Par la vitre, tu vois un établi, des ressorts, et une boîte à chapeau vide. E. Finch n’est peut-être pas le père. C’est peut-être la fille.'] },
      { title: 'Le biscuit de Mrs Hudson', text: [
        'Mrs Hudson t’a donné un biscuit. Elle ne fait jamais ça. Elle t’a aussi donné une nouvelle, ce qui est plus rare encore.',
        'Sa cousine est habilleuse au Lyceum. L’accessoiriste du théâtre y fabrique en ce moment « un petit oiseau argenté, pour un monsieur qui n’est pas acteur ».'] },
      { title: 'L’atelier des accessoires', text: [
        'Sous la scène du Lyceum, Miss Lavinia Crane fabrique des couronnes en carton qui ont l’air d’or et des épées en bois qui ont l’air d’acier.',
        'Sur son établi, des moules en plâtre. <i lang="en">« Yes, I made an owl. Tin, painted silver. Very good, if I say so myself. »</i> Oui, une chouette en fer-blanc peint. Très réussie, si je puis dire.'] },
      { title: 'Qui a commandé ?', text: [
        'Dans le livre de commandes de Miss Crane : « 1 chouette, fer-blanc argenté, à la mesure exacte du modèle. Payé comptant. Mr P., Museum Street. »',
        'Mr P. Comme Pemberton. Une copie parfaite d’une chouette qui ne chante plus. Sur votre tableau, un deuxième fil rouge part du Lyceum vers le Museum.'] },
      { title: 'Le chapeau gris file', text: [
        'L’homme au chapeau gris était au parterre, ce soir. Cette fois, c’est vous qui le suivez. Il traverse le Strand, remonte Whitehall… et entre à Scotland Yard.',
        '<i lang="en">« He’s a copper. Is he watching us? »</i> C’est un policier. Il nous surveille ? Personne ne dort très bien, cette nuit-là.'] },
      { title: 'Une question bien posée', text: [
        'Le portier de l’entrée des artistes ne répond qu’aux questions bien posées. <i lang="en">« Who came with Mr P.? »</i> Qui est venu avec Mr P. ?',
        'Une jeune femme en tablier d’horloger, avec une boîte à chapeau. Elle a mesuré la copie au pied à coulisse et elle a dit : « Elle ne chantera pas, mais elle fera illusion. »'] },
      { title: 'Lestrade', text: [
        'Lestrade te demande par ton nom, maintenant. Ne prends pas la grosse tête. Tu en profites pour lui parler de la Chouette.',
        '<i lang="en">« No theft was reported, kid. »</i> Aucun vol n’a été signalé, petit. Et le chapeau gris ? Lestrade rit : c’est l’agent Hobbs, en civil, chargé d’éviter que la bande ne se fourre dans les ennuis. Raté.'] },
      { title: 'La vitrine', text: [
        'Au Museum, la Chouette est bien dans sa vitrine. L’étiquette annonce : hauteur 7 pouces, poids 12 onces. Mais le reflet ne trompe pas un Irregular.',
        'La chouette de la vitrine est un peu plus haute que l’étiquette, et bien moins lourde que l’argent : le socle ne s’enfonce pas dans le velours. <i lang="en">« Not as heavy as silver. »</i> C’est la copie de Miss Crane.'] },
      { title: 'Mr Pemberton', text: [
        'Mr Pemberton est un petit homme qui essuie ses lunettes quand il a peur. Il les essuie beaucoup.',
        '<i lang="en">« The original is at the restorer’s. Everything is fine. »</i> L’original est chez la restauratrice, tout va bien. Puis il regarde la boîte d’allumettes de Wiggins, et il devient très pâle.'] },
      { title: 'La plume manquante', text: [
        'Sur la photographie du catalogue, l’aile gauche de la Chouette compte neuf plumes d’argent, toutes gravées trait par trait, comme celle de ta boîte d’allumettes.',
        'Une chouette ne perd pas une plume d’argent toute seule. Elle la perd quand on la fait tomber.'] },
      { title: 'Le train de 9 h 15', text: [
        'À Paddington, un porteur se souvient d’une jeune femme qui serrait une boîte à chapeau comme un bébé. Elle a pris le 9 h 15 pour Bristol, avec un billet de retour.',
        '<i lang="en">« She’s coming back tomorrow. The noon train. »</i> Elle revient demain, par le train de midi. Le gala du Museum, avec tout le beau monde de Londres, a lieu demain soir.'] },
      { title: 'Le patron est absent', text: [
        'Toutes les affaires sont classées. Le patron est absent ce soir. Sur votre tableau à vous, il y a une plume, une caisse revenue, une copie en fer-blanc, un homme qui essuie ses lunettes et un train de midi.',
        '<i lang="en">« We’ve got every thread. Now we tie them. »</i> On a tous les fils. Maintenant, on les noue. C’est ce soir, The Final Case. Prends la loupe du patron : elle est à toi.'] }
    ],
    finale: { title: 'La Chouette chante à midi', text: [
      'Il n’y avait pas de voleur. Mr Pemberton a fait tomber la Chouette en l’époussetant ; une plume s’est détachée, le mécanisme s’est tu. De peur d’être renvoyé, il a fait copier l’oiseau au Lyceum et confié l’original à Ellen Finch, horlogère, dont le père avait réparé la Chouette autrefois.',
      'Ellen a d’abord voulu envoyer l’oiseau à Paris pour un ressort introuvable : c’était la caisse de minuit. Puis elle a eu peur de la mer, l’a fait revenir, et elle est allée à Bristol chez le vieil horloger qui avait encore la pièce.',
      'Le lendemain à midi, les Irregulars attendent Ellen sur le quai de Paddington et l’escortent jusqu’au Museum. Mr Pemberton avoue tout au directeur, qui, à la surprise générale, le remercie d’avoir sauvé l’oiseau. Wiggins rend la plume. À midi pile, la Chouette chante l’heure.',
      'L’agent Hobbs écrit son rapport : « Scotland Yard n’a rien vu. Les Irregulars ont tout vu. » Le patron, de retour, lit votre dossier sans rien dire, puis le pose sur sa cheminée. <i lang="en">« Elementary, {prenom}. »</i> Élémentaire. Et Mrs Hudson donne deux biscuits.'] }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
