/* Les Tablettes de Pompéi — the tablets of Lucilla, told page by page (shared/engage.js, `story`).

   One page opens at each tablet traced, in this order whatever order the tablets are traced in;
   the last one opens once the Lararium has been played to its end. It agrees with the architect's
   lines of the main screen (game.js, PROLOGUE and BEATS): the first crack (7), Julia Felix's silent
   visit (14), the hatched room at the centre of the plan (21), the lararium and the groma (28).

   Lucilla, thirteen, daughter of the architect, may not write on her father's tablets: she learns
   her letters at night by copying the apprentice's - the player's. The mystery: the bronze Lar of
   Julia Felix's house, lost in the earthquake of 62. An old gardener hid it and wrote where on a
   wall since plastered over; the cracks reopened for repair give the inscription back, word by
   word, and the declensions the player traces are what lets her read it. */
(function (root) {
  'use strict';

  root.POMStory = {
    title: 'Les tablettes de Lucilla',
    lockedPage: function (need) { return 'Cette tablette est encore vierge. Elle se lit à la ' + need + (need === 1 ? 're' : 'e') + ' tablette tracée.'; },
    lockedFinale: 'La dernière tablette ne se lit qu’une fois le lararium joué jusqu’au bout.',
    pages: [
      { title: 'Une tablette prise au coffre', text: [
        'Je m’appelle Lucilla, fille de Caius Vibius Rufus, l’architecte. J’ai treize ans, et je n’ai pas le droit d’écrire sur les tablettes de mon père.',
        'Celle-ci, je l’ai prise dans son coffre : il y en a tant qu’il ne verra rien. Le soir, je recopie les lettres que tu traces pour lui. Tu ne le sais pas, mais c’est toi qui m’apprends à écrire.'] },
      { title: 'La maison de Julia Felix', text: [
        'Julia Felix possède tout un quartier, près de l’amphithéâtre : sa maison, des boutiques, des bains qu’elle loue aux passants. Depuis le grand tremblement de terre, elle fait tout rebâtir, pièce par pièce.',
        'Elle ne sourit jamais. Mon père dit qu’elle paie bien, et que c’est une manière de sourire.'] },
      { title: 'Ce que raconte grand-mère', text: [
        'Grand-mère Vibia était déjà vieille quand la terre a tremblé, aux nones de février, il y a seize ans. « Elle a grondé comme un taureau, dit-elle, et les murs se sont ouverts comme du pain. »',
        'Je n’étais pas née. Mais les lézardes, je les connais : elles sont encore dans tous les murs de Pompéi.'] },
      { title: 'Le Lar dansant', text: [
        'Grand-mère m’a parlé du Lar de la maison : un petit dieu de bronze, haut comme ma main, qui danse sur la pointe d’un pied en levant une coupe. Il veillait sur la famille de Julia Felix depuis des générations.',
        'Le jour du tremblement de terre, l’autel s’est effondré. Quand on a déblayé, le Lar n’était plus là. Personne ne l’a jamais retrouvé.'] },
      { title: 'R, O, S, A', text: [
        'J’ai tracé mes premières lettres en cachette, sous la lampe : R, O, S, A. Puis rosa, rosae, rosae, rosam. La cire est molle, mes lettres penchent.',
        'Mais je peux les relire le lendemain, et c’est la chose la plus étrange du monde : ma voix d’hier est restée dans la cire.'] },
      { title: '« Relis toujours le génitif »', text: [
        'Mon père le répète à son apprenti·e plusieurs fois par jour : « Relis toujours le génitif. » Lui-même écrivait regum pour regis, quand il était jeune. Il ne le dit qu’en riant.',
        'J’ai compris pourquoi : au nominatif, rex ne dit rien de sa famille. Au génitif, regis, on voit enfin à quelle déclinaison il appartient.'] },
      { title: 'Sous la lézarde', text: [
        'Ce matin, une lézarde s’est rouverte dans un mur de l’atrium. Les maçons ont fait tomber le vieil enduit pour la reboucher.',
        'Dessous, sur la pierre, il y avait des lettres griffées à la pointe, à moitié effacées. J’ai lu SVB, puis plus rien. Personne d’autre ne les a remarquées : personne ne regarde les murs, ici, à part moi.'] },
      { title: 'Recopier avant l’enduit', text: [
        'Les maçons replâtrent demain. Ce soir, j’ai recopié sur ma tablette tout ce que j’ai pu voir, une lettre après l’autre, sans rien comprendre.',
        'SVB, et avant, peut-être un L. Mon cœur battait si fort que la lampe tremblait.'] },
      { title: 'Eutychus', text: [
        'J’ai montré les lettres à grand-mère. Elle s’est tue longtemps, puis elle a dit un nom : Eutychus. Le jardinier de la maison, un vieux Grec qui parlait aux plantes.',
        '« Il aimait le grand figuier de l’hortus plus que tout. Après le tremblement de terre, Julia Felix l’a affranchi. Il est parti à Neapolis, et on ne l’a plus revu. »'] },
      { title: 'Dans le tablinum', text: [
        'Le tablinum, c’est la pièce des archives : les contrats, les comptes, les registres de la maison. Je n’ai pas le droit d’y entrer.',
        'J’y suis entrée quand même. Dans un registre, j’ai trouvé son nom : « Eutychus, hortulanus. » Jardinier. Je l’ai su lire toute seule.'] },
      { title: 'Une deuxième lézarde', text: [
        'Une lézarde dans le tablinum, cette fois. Les maçons ont gratté, et j’ai vu d’autres lettres de la même main : FICV.',
        'Ficu ? Ce n’est ni rosa ni dominus. Je l’ai tourné dans tous les sens, sur ma tablette, jusqu’à ce que la cire soit grise.'] },
      { title: 'La montagne', text: [
        'Du toit, on voit le Vésuve, couvert de vignes jusqu’en haut. On dit que Bacchus en personne y a planté les premières.',
        'Ce matin, un peu de fumée flottait au sommet. Mon père dit que la montagne respire, qu’elle a toujours respiré. Je l’ai regardée longtemps quand même.'] },
      { title: 'La laine et la cire', text: [
        'Ma mère m’a surprise avec ma tablette. « Une fille tisse, elle n’écrit pas. » Elle ne l’a pas dit méchamment ; elle l’a dit comme on dit qu’il pleut.',
        'J’ai caché la tablette au fond de mon coffre à laine. Le soir, je tisse deux rangs, puis j’écris deux lignes. Elle n’a rien vu, ou elle fait semblant.'] },
      { title: 'Julia Felix m’a vue', text: [
        'Julia Felix est passée ce matin voir les fresques. J’étais derrière une colonne, ma tablette à la main. Elle s’est arrêtée, elle m’a regardée, et elle n’a rien dit.',
        'Mon père dit que, chez elle, le silence est un compliment. Pour moi, ce fut surtout une très grande peur.'] },
      { title: 'Manus, manus', text: [
        'Aujourd’hui, mon père a dicté la quatrième déclinaison : manus, manus, manui, manum, manu. Et soudain j’ai compris.',
        'Ficus, le figuier, se décline aussi comme manus. Ficu, c’est l’ablatif. Le mot du mur, c’est le figuier !'] },
      { title: 'Sub ficu', text: [
        'Sub, « sous », veut l’ablatif quand on est quelque part, immobile. Sub ficu : sous le figuier.',
        'Quelqu’un a écrit sur le mur de l’atrium et sur celui du tablinum qu’il y avait quelque chose sous le figuier. Je ne pense plus qu’à ça.'] },
      { title: 'Le figuier mort', text: [
        'Je suis allée dans l’hortus. Le grand figuier d’Eutychus est mort depuis le tremblement de terre : il ne reste qu’une souche grise, au milieu des choux.',
        'Le nouveau jardinier veut l’arracher pour planter des rosiers. Il dit qu’une souche morte porte malheur.'] },
      { title: 'Ce qu’on dit aux thermes', text: [
        'Aux bains de Julia Felix, rouverts depuis le printemps, les femmes parlent de tout. J’apporte les serviettes et j’écoute.',
        'Une marchande de Neapolis a raconté qu’un vieux Grec de son quartier, ancien jardinier, dit à qui veut l’entendre qu’il a « sauvé un dieu ». Tout le monde a ri. Pas moi.'] },
      { title: 'Ma première lettre', text: [
        'J’ai écrit à Eutychus. Ma première vraie lettre, à quelqu’un que je n’ai jamais vu : « Lucilla, fille de Caius, à Eutychus, salut. Qu’as-tu caché sous le figuier ? »',
        'Un marchand de garum qui part pour Neapolis l’a prise. Il m’a fait payer deux as. C’est tout ce que j’avais.'] },
      { title: 'Attendre', text: [
        'Pas de réponse. Pour ne pas y penser, j’apprends les pronoms que tu traces : hic, haec, hoc ; ille, illa, illud. Celui-ci, celle-là.',
        'Grand-mère dit que les lettres voyagent à la vitesse des ânes. Je regarde passer tous les ânes de la rue.'] },
      { title: 'La pièce hachurée', text: [
        'Au centre du plan de mon père, une pièce reste hachurée. Il refuse de dire ce qu’elle cache, même à moi.',
        'Mais je l’ai vu tailler une petite niche dans le marbre, avec un autel devant. C’est un nouveau lararium. Et un lararium sans son Lar, c’est une maison qui dort mal.'] },
      { title: 'La réponse', text: [
        'La réponse est arrivée, sur une tablette fendue, dictée à un écrivain public : « Eutychus à Lucilla, salut. Le jour où la terre a tremblé, des pillards fouillaient les ruines. J’ai pris le Lar pour le sauver. »',
        '« Je l’ai enterré là où je savais le retrouver, et je l’ai écrit sur les murs pour la maîtresse. Mais on a replâtré avant qu’elle les lise. »'] },
      { title: 'La phrase entière', text: [
        'Une dernière lézarde, dans le triclinium, a rendu le reste de la phrase. Je l’ai recopiée en entier : LAREM SVB FICV SERVAVI. EVTYCHVS.',
        'Larem, à l’accusatif : c’est lui qu’on garde. Servavi : « j’ai gardé ». « J’ai gardé le Lar sous le figuier. Eutychus. » Je l’ai lue toute seule, d’un bout à l’autre.'] },
      { title: 'Demain, on arrache', text: [
        'Le nouveau jardinier arrache la souche demain, à l’aube. S’il trouve quelque chose, il ne saura pas ce que c’est, ou il le vendra.',
        'Si je le dis à mon père, je dois tout avouer : la tablette volée, le tablinum, les lettres. Je n’ai pas le temps de choisir. J’irai cette nuit.'] },
      { title: 'La nuit dans l’hortus', text: [
        'J’ai creusé avec la petite truelle des maçons, entre les racines mortes, à la lueur d’une lampe que je cachais sous ma palla.',
        'Au bout d’une heure, le fer a sonné contre quelque chose de dur : une jarre de terre, fermée de cire. La cire, comme mes tablettes.'] },
      { title: 'Ce qu’il y avait dans la jarre', text: [
        'Dedans, roulé dans un vieux linge, il y avait le Lar. Vert de vieillesse, une jambe levée, sa coupe à la main. Il dansait encore.',
        'Je l’ai caché dans mon coffre à laine, avec mes tablettes. Il a passé la nuit entre la cire et la laine. Moi, je n’ai pas dormi.'] },
      { title: 'Le dire ou non', text: [
        'Si je rends le Lar, il faudra dire comment je l’ai trouvé. Que je sais lire. Que je sais écrire. Que je suis entrée là où une fille n’entre pas.',
        'Grand-mère a dit seulement : « Un dieu rendu vaut bien une tablette volée. » Puis elle s’est rendormie.'] },
      { title: 'La villa est tracée', text: [
        'Toutes les tablettes sont tracées. Au centre du plan, la pièce hachurée a pris son nom : le lararium. Mon père t’a donné sa groma, et il souriait comme je ne l’avais jamais vu sourire.',
        'Julia Felix viendra elle-même lire tes inscriptions devant l’autel. J’y serai aussi. J’apporterai quelque chose.'] }
    ],
    finale: { title: 'Ce que Julia Felix a lu', text: [
      'Quand tu as eu fini de lire tes inscriptions, je me suis avancée devant l’autel. J’ai posé le Lar dans la niche, et à côté, toutes mes tablettes.',
      'Julia Felix les a lues une à une, sans un mot, très longtemps. Mon père était pâle. Puis elle a souri — tout le monde dit que c’était la première fois. « Une maison qui retrouve son Lar, et une fille qui sait écrire. C’est beaucoup de chance pour un seul jour. »',
      'Elle a fait envoyer un sac de figues sèches à Eutychus, à Neapolis. Et moi, je serai désormais l’écrivain de sa maison : le maître d’école me prendra dès les calendes.',
      'J’ai rangé cette dernière tablette dans la niche, près du Lar. Si quelqu’un la trouve un jour, longtemps après nous, qu’il sache qu’une fille de treize ans a appris à écrire en regardant {prenom} tracer les tablettes de son père. <i lang="la">Vale.</i> — Lucilla'] }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
