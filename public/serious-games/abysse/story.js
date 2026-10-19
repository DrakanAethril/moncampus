/* Station Abysse — the journal de bord of Commandante Ysé Marin, told page by page
   (shared/engage.js, `story`).

   One page opens at each species catalogued, in this order whatever order the species are
   catalogued in; the last page opens once La Remontée has been played to its end. It agrees with
   the commandante's lines of the main screen (game.js, PROLOGUE and BEATS): the previous mission's
   logbook two-thirds empty, the anomalies on the sonar (8), the surface teams reading the sketches
   (15), the bathyscaphe warming its engines (23), the full logbook and the nautilus (30).

   The vanished animal: a small vent snail fed by its bacteria and glowing blue, drawn once in
   2019 by Malo Ferreira, the previous mission's biologist, at the Lucioles vent field - which went
   cold a year later. Believed extinct. The player's logbook brings back its DNA, then traces of it
   40 km north; how a snail that cannot swim got there is answered only on the ascent: its larvae
   drift high in the water column. */
(function (root) {
  'use strict';

  root.ABYStory = {
    title: 'Journal de bord',
    lockedPage: function (need) { return 'Cette page du journal s’écrit à la ' + need + (need === 1 ? 're' : 'e') + ' espèce cataloguée.'; },
    lockedFinale: 'Cette page s’écrit pendant la Remontée : elle s’ouvre une fois la Remontée faite jusqu’au bout.',
    pages: [
      { title: 'La dernière page de Malo', text: [
        'Ta première espèce est au carnet. Alors je peux te montrer ça : la dernière page remplie par la mission précédente.',
        'Un escargot de la taille d’un ongle, le pied cerclé d’un bleu pâle. Dessous, l’écriture de Malo Ferreira : « Source des Lucioles, 2 900 m. Vu une fois. À revoir. » Personne ne l’a jamais revu. — Y. M.'] },
      { title: 'Malo', text: [
        'Malo était le biologiste de la mission de 2019. Moi, je pilotais déjà la station. Il dessinait mieux que personne et parlait aux spécimens à voix basse, comme pour ne pas les vexer.',
        'Il a rempli un tiers du carnet. Puis il s’est arrêté. Les deux tiers vides que tu remplis, ce sont les siens.'] },
      { title: 'Les Lucioles', text: [
        'On appelait ce champ de sources les Lucioles : quand on éteignait les projecteurs du bathyscaphe, les cheminées scintillaient de petits points bleus.',
        'Je n’y suis pas retournée depuis onze ans. Ce n’est pas loin. C’est seulement que je n’en ai jamais eu envie.'] },
      { title: 'Une source qui s’arrête', text: [
        'En 2020, les Lucioles se sont arrêtées. Le fluide chaud a cessé de sortir des cheminées ; la croûte s’est refermée, comme une plaie.',
        'Sans fluide, pas de sulfure d’hydrogène. Sans sulfure, plus de bactéries pour fabriquer la matière organique. En quelques mois, tout ce qui vivait là est mort. Malo est rentré à terre.'] },
      { title: 'Un escargot qui ne mange pas', text: [
        'Les notes de Malo sont précises. L’escargot n’a presque pas de râpe pour brouter. En revanche, une glande énorme, remplie de bactéries.',
        'Ce sont elles qui le nourrissent : elles tirent leur énergie du sulfure d’hydrogène et fabriquent de la matière organique sans lumière. Une chimiosynthèse portée en soi, comme un jardin dans le ventre.'] },
      { title: 'La lumière', text: [
        'Le bleu du pied, c’est de la bioluminescence : une molécule, la luciférine, oxydée grâce à une enzyme, la luciférase. La réaction donne de la lumière presque sans chaleur.',
        'Pourquoi briller dans le noir quand on ne voit rien ? Malo avait trois hypothèses dans la marge, et un point d’interrogation à côté de chacune.'] },
      { title: 'Voyageurs ou condamnés', text: [
        'Une source hydrothermale vit quelques années, parfois quelques décennies. À l’échelle d’une espèce, c’est un instant.',
        'Malo a écrit : « Ici, il n’y a que deux sortes d’animaux : les voyageurs et les condamnés. » Il pensait que son escargot était des seconds.'] },
      { title: 'Le soir du sonar', text: [
        'Te voilà plongeur·se scientifique : tu ne regardes plus le hublot, tu l’observes. Malo disait que c’est à ça qu’on reconnaît ceux qui restent.',
        'Le sonar, ce n’est pas que des anomalies. C’est lui qui m’a montré, un soir de 2020, que les Lucioles ne fumaient plus. Une ligne plate, là où il y avait toujours eu un panache.'] },
      { title: 'Le tube du congélateur', text: [
        'En rangeant le congélateur de la station, j’ai trouvé un tube étiqueté de la main de Malo : « Lucioles, pied, 14 juin 2019 ».',
        'Un morceau de l’escargot, conservé à −80 °C. La mission s’est arrêtée avant qu’on ait le temps de le séquencer. Il dort là depuis douze ans.'] },
      { title: 'Séquencer', text: [
        'Le séquenceur de la station a lu le tube cette nuit. Des millions de lettres : A, T, C, G.',
        'Ce qui m’émeut, c’est que l’ADN, lui, ne sait pas que l’animal est mort. Il raconte tout comme si l’escargot allait se remettre à brouter.'] },
      { title: 'Une espèce sans nom', text: [
        'L’ADN ne ressemble à celui d’aucune espèce décrite. C’est un gastéropode, de la famille des escargots des sources, mais personne ne l’avait jamais séquencé.',
        'Une espèce nouvelle, donc. Et sans doute disparue avant même d’avoir un nom.'] },
      { title: 'La surface répond', text: [
        'J’ai envoyé la séquence aux équipes de surface. Leur réponse est prudente : on peut décrire une espèce à partir d’un seul spécimen, mais un seul spécimen ne dit rien d’une population.',
        'Elles posent la question que je n’ose pas poser : est-ce qu’il en reste ?'] },
      { title: 'Le réseau des Lucioles', text: [
        'Malo avait dessiné le réseau trophique des Lucioles : les bactéries, l’escargot, les crabes blancs qui le mangeaient, les poissons qui mangeaient les crabes.',
        'Quand la source s’est arrêtée, tout le réseau est tombé d’un coup, par le bas. Rien n’y survit quand les producteurs disparaissent.'] },
      { title: 'La dernière plongée', text: [
        'C’est moi qui ai piloté la dernière plongée aux Lucioles. Les cheminées étaient froides, couvertes de coquilles vides. Malo n’a rien dit pendant toute la remontée.',
        'Je n’ai rien fait de mal. Mais on se souvient longtemps des remontées silencieuses.'] },
      { title: 'L’idée d’Awa', text: [
        'La moitié du carnet est remplie. Là-haut, les équipes lisent tes croquis ; l’une d’elles, Awa Ndiaye, une généticienne, a eu une idée.',
        'Tout animal laisse dans l’eau des fragments de son ADN : mucus, cellules, œufs. Filtrer l’eau le long de la dorsale, et chercher dedans la séquence de Malo.'] },
      { title: 'L’ADN de l’eau', text: [
        'On appelle ça l’ADN environnemental. Une centaine de litres filtrés, et on retrouve qui est passé par là sans avoir vu personne.',
        'Le plus important, Awa l’a souligné deux fois : dans l’eau de mer, cet ADN se dégrade en quelques jours à quelques semaines. Si on le trouve, il est récent.'] },
      { title: 'Cent litres, dix fois', text: [
        'Le robot de la station a pris dix prélèvements le long de la dorsale, de part et d’autre des Lucioles, tous les dix kilomètres.',
        'Cent litres chacun, filtrés à 3 000 m, puis remontés au laboratoire. Il ne reste qu’à lire.'] },
      { title: 'Rien', text: [
        'Six prélèvements lus. Rien. Les Lucioles elles-mêmes : rien, évidemment.',
        'Je me suis surprise à espérer, et à m’en vouloir d’espérer. Malo aurait ri de moi. Puis il aurait relu les six résultats, un par un.'] },
      { title: 'Un signal au nord', text: [
        'Septième prélèvement, quarante kilomètres au nord, près d’un petit champ de sources découvert en 2024 et jamais exploré à sa base : une trace.',
        'Faible, mais nette. Des fragments d’ADN identiques à la séquence du tube de Malo.'] },
      { title: 'Erreur de mesure ?', text: [
        'Première pensée de scientifique : la contamination. Le tube de Malo a été séquencé dans le même laboratoire, quelques jours plus tôt.',
        'Alors on recommence tout : gants neufs, flacons neufs, robot nettoyé, et un prélèvement témoin d’eau stérile. On ne croit pas un résultat qu’on désire autant.'] },
      { title: 'Le champ Nord', text: [
        'Deuxième série au champ Nord. Le témoin est vierge. La trace est là, plus forte à mesure qu’on s’approche des cheminées.',
        'Ce n’est pas une erreur. Quelque chose qui porte l’ADN de l’escargot des Lucioles vit, ou vivait il y a quelques jours, à quarante kilomètres de chez lui.'] },
      { title: 'D’autres allèles', text: [
        'Awa a comparé les fragments du champ Nord à la séquence de Malo. Mêmes gènes, mais pas toujours les mêmes allèles, et pas dans les mêmes proportions.',
        'Ce n’est donc pas l’ADN de l’escargot du tube. C’est celui d’autres individus : une autre population.'] },
      { title: 'Les moteurs chauffent', text: [
        'Chef·fe de mission. Il reste quelques pages blanches, les plus profondes. Le bathyscaphe chauffe ses moteurs.',
        'J’ai demandé à la surface que la relève remonte en passant au-dessus du champ Nord. Ils ont accepté sans poser de questions, ce qui veut dire qu’ils en ont beaucoup.'] },
      { title: 'Comment ?', text: [
        'Reste la question qui m’empêche de dormir. Un escargot adulte ne nage pas. Il rampe, quelques mètres dans sa vie, autour d’une cheminée.',
        'Entre les Lucioles et le champ Nord, il y a quarante kilomètres d’eau à 2 °C, sans nourriture, sans sulfure. Aucun adulte ne traverse ça.'] },
      { title: 'Les capsules', text: [
        'Dans les notes de Malo, une phrase que je n’avais pas comprise : « Capsules d’œufs sur les coquilles. Larves ? Où vont-elles ? »',
        'Les larves de bien des animaux marins dérivent dans le plancton avant de se fixer. Mais celles d’un escargot de source ? Personne ne les a jamais suivies.'] },
      { title: 'La lettre de 2020', text: [
        'J’ai retrouvé la dernière lettre de Malo. « Elle était trop fragile pour voyager, Ysé. Je crois qu’on l’a regardée mourir sans rien comprendre. »',
        'Je ne lui ai jamais répondu. Je ne savais pas quoi dire. Je crois que je commence à savoir.'] },
      { title: 'Plus haut, plus vite', text: [
        'Près du fond, les courants sont lents. Plus haut dans la colonne d’eau, vers mille mètres, ils sont souvent plus rapides et portent plus loin.',
        'Les larves de certains animaux des sources montent ainsi des centaines de mètres pour se laisser porter, puis redescendent ailleurs. Ce n’est qu’une hypothèse. Il faudrait les voir.'] },
      { title: 'Le plan de remontée', text: [
        'J’ai modifié le plan de la remontée. Au-dessus du champ Nord, le bathyscaphe montera lentement entre 1 200 et 900 m, projecteurs éteints, caméras sensibles allumées.',
        'Si des larves lumineuses passent, on les verra. Si on ne voit rien, on n’aura rien perdu : la remontée se refait.'] },
      { title: 'Une réponse à écrire', text: [
        'J’ai commencé une lettre à Malo. Je l’enverrai quand le carnet sera complet, quoi qu’on voie pendant la remontée.',
        'Onze ans de retard. Mais une réponse en retard vaut mieux qu’une remontée silencieuse.'] },
      { title: 'Le Nautile', text: [
        'Le carnet est complet. Trente espèces, et le tiers de Malo au début : c’est enfin un seul livre.',
        'Ce nautile en laiton, sur la console, était à lui ; il l’avait oublié en partant. Garde-le. La remontée peut commencer. Vers mille mètres, regarde bien par le hublot.'] }
    ],
    finale: { title: 'La voyageuse', text: [
      'Remontée, 1 080 m, projecteurs éteints. D’abord un point bleu. Puis dix, puis des centaines : un nuage de minuscules larves lumineuses qui montaient avec nous, du même bleu que le pied de l’escargot de Malo.',
      'La caméra a tout filmé. À la surface, Awa a filtré l’eau du sas : c’est bien lui. L’escargot des Lucioles n’a pas disparu. Il n’a jamais vécu en un seul endroit : ses larves voyagent loin au-dessus des sources, et redescendent là où une cheminée s’ouvre.',
      'Malo m’a répondu le soir même, en trois lignes. Il refuse qu’on donne son nom à l’espèce. Il propose Lucerna viatrix : la lanterne voyageuse. Il remonte à bord à la prochaine mission.',
      'Dans la description de l’espèce, il y aura une ligne que j’ai écrite moi-même : « Larves observées pour la première fois par {prenom}. » C’est toi qui as rempli le carnet jusqu’au bout. — Ysé Marin'] }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
