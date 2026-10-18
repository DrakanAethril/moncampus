/* The Irregulars — the casebook and the glossary: what the game lets a learner read outside an
   investigation (design/validated/serious-games-irregulars.md). The irregular verbs are the list
   the templates draw from (the conception's bank: base, preterite, past participle, British
   spelling); the memos are one per place, in French, the rule and an example from 1895 London.

   A classic script that sets globalThis.IRRCasebook - data only. */
(function (root) {
  'use strict';

  root.IRRCasebook = {
    verbs: [
      ['be', 'was / were', 'been', 'être'], ['begin', 'began', 'begun', 'commencer'], ['break', 'broke', 'broken', 'casser'],
      ['bring', 'brought', 'brought', 'apporter'], ['buy', 'bought', 'bought', 'acheter'], ['catch', 'caught', 'caught', 'attraper'],
      ['come', 'came', 'come', 'venir'], ['drink', 'drank', 'drunk', 'boire'], ['drive', 'drove', 'driven', 'conduire'],
      ['eat', 'ate', 'eaten', 'manger'], ['fall', 'fell', 'fallen', 'tomber'], ['find', 'found', 'found', 'trouver'],
      ['forget', 'forgot', 'forgotten', 'oublier'], ['get', 'got', 'got', 'obtenir'], ['give', 'gave', 'given', 'donner'],
      ['go', 'went', 'gone', 'aller'], ['hear', 'heard', 'heard', 'entendre'], ['hide', 'hid', 'hidden', 'cacher'],
      ['know', 'knew', 'known', 'savoir, connaître'], ['leave', 'left', 'left', 'partir, quitter'], ['lose', 'lost', 'lost', 'perdre'],
      ['make', 'made', 'made', 'faire, fabriquer'], ['meet', 'met', 'met', 'rencontrer'], ['pay', 'paid', 'paid', 'payer'],
      ['put', 'put', 'put', 'poser, mettre'], ['ride', 'rode', 'ridden', 'monter (à cheval)'], ['ring', 'rang', 'rung', 'sonner'],
      ['run', 'ran', 'run', 'courir'], ['say', 'said', 'said', 'dire'], ['see', 'saw', 'seen', 'voir'],
      ['sell', 'sold', 'sold', 'vendre'], ['send', 'sent', 'sent', 'envoyer'], ['sing', 'sang', 'sung', 'chanter'],
      ['sit', 'sat', 'sat', 'être assis'], ['speak', 'spoke', 'spoken', 'parler'], ['steal', 'stole', 'stolen', 'voler (dérober)'],
      ['take', 'took', 'taken', 'prendre'], ['tell', 'told', 'told', 'dire, raconter'], ['think', 'thought', 'thought', 'penser'],
      ['throw', 'threw', 'thrown', 'lancer'], ['wake', 'woke', 'woken', 'se réveiller'], ['wear', 'wore', 'worn', 'porter (un vêtement)'],
      ['write', 'wrote', 'written', 'écrire']
    ],
    // one memo per place: [chapter code, title, rule paragraphs, examples]
    memos: [
      ['docks', 'Le prétérit', [
        'Une action terminée, datée dans le passé (yesterday, last night, two hours ago, in 1890) : prétérit.',
        'Verbes réguliers : base + -ed. Un e final ne prend que -d (arrive → arrived) ; consonne + y donne -ied (carry → carried) ; une syllabe accentuée consonne-voyelle-consonne double sa consonne (stop → stopped ; en anglais britannique, travel → travelled).',
        'Verbes irréguliers : la deuxième colonne de la liste (see → saw, go → went).',
        'Négation et question : did / didn’t + base verbale. Le passé est déjà porté par did : « He didn’t see the thief », « Did you hear a scream? »'
      ], ['Last night, two dockers noticed a strange letter near the docks.', 'The cabman didn’t see the man in grey.']],
      ['market', 'Le present perfect', [
        'have / has + participe passé (troisième colonne : seen, taken, written) : une action passée reliée au moment présent, sans date précise.',
        'Avec un marqueur de passé révolu (yesterday, ago, in 1891), jamais de present perfect : prétérit.',
        'for + une durée (for three years), since + un point de départ (since Monday) ; ago s’emploie avec le prétérit (three years ago).',
        'already (déjà) et just (venir de) se placent entre have et le participe ; yet se place en fin de question ou de phrase négative.'
      ], ['The flower girl has worked in Covent Garden since 1891.', 'Has the inspector found the key yet?']],
      ['theatre', 'Questions et auxiliaires', [
        'Le mot interrogatif dit ce qu’on demande : Who (une personne), What (une chose), Where (un lieu), When (un moment), Why (une cause), How (une manière), How much (un prix).',
        'L’auxiliaire dépend du temps : do / does au présent simple, am / is / are + -ing au présent en be + -ing, did au prétérit, have / has au present perfect.',
        'Ordre : mot interrogatif + auxiliaire + sujet + verbe + complément (« Where did he hide the bag? »).',
        'La réponse courte reprend l’auxiliaire de la question, sans le verbe : « Did she see him? — Yes, she did. »'
      ], ['Why did the porter run away?', 'Has the actor left the theatre? — No, he hasn’t.']],
      ['museum', 'Comparatifs et superlatifs', [
        'Adjectif court : -er than (older than, taller than) ; y devient -ier (heavier) ; une consonne finale après une voyelle courte double (bigger).',
        'Adjectif long : more / less + adjectif + than (more expensive than).',
        'Irréguliers : good → better → the best ; bad → worse → the worst.',
        'Égalité : as + adjectif + as ; infériorité : not as + adjectif + as. L’adjectif reste à la forme simple.',
        'Une comparaison n’est juste que si les étiquettes du musée la confirment : lis les mesures avant de choisir.'
      ], ['The Greek statue is heavier than the Viking sword.', 'The Roman coin is not as old as the Egyptian vase.']],
      ['station', 'Modaux et futur', [
        'must : obligation ; mustn’t : interdiction ; don’t have to : absence d’obligation (on peut, mais ce n’est pas nécessaire).',
        'Capacité : can au présent, could au passé, will be able to au futur.',
        'will : décision prise sur le moment, prédiction d’opinion (I think…) ; be going to : intention déjà décidée, prédiction appuyée sur un indice visible.',
        'Condition réelle : If + présent, will + base verbale dans la principale. Jamais will après if.'
      ], ['Passengers mustn’t cross the tracks.', 'If the train is late, we will miss the meeting.']]
    ],
    glossary: {
      london: [
        ['Baker Street', 'La rue du détective consultant, au nord-ouest de Londres. Son appartement est au 221B, au premier étage.'],
        ['Irregulars', 'La bande d’enfants des rues qui travaille pour le détective : ils vont partout, voient tout, et on ne les remarque pas.'],
        ['cabman', 'Le cocher d’un fiacre, le taxi à cheval de Londres ; il connaît toutes les rues.'],
        ['gas lamp', 'Le bec de gaz qui éclaire les rues ; l’allumeur passe vers quatre heures en automne.'],
        ['fog', 'Le brouillard qui monte de la Tamise et se mêle à la fumée du charbon : on l’appelait « pea-souper ».'],
        ['the Docks', 'Les quais du port de Londres, sur la Tamise, où l’on décharge les navires de tout l’Empire.'],
        ['Covent Garden', 'Le grand marché aux fruits, aux légumes et aux fleurs, au centre de Londres.'],
        ['the Lyceum', 'Un théâtre célèbre du Strand, où l’on jouait Shakespeare.'],
        ['the Museum', 'Le British Museum et ses vitrines d’antiquités du monde entier.'],
        ['Paddington', 'La grande gare de l’ouest de Londres, terminus du Great Western Railway.'],
        ['inspector', 'L’inspecteur de Scotland Yard, la police de Londres (Lestrade, dans les récits).'],
        ['telegram', 'Le télégramme : le message le plus rapide de l’époque, porté par un coursier.'],
        ['shilling, pound', 'Les pièces de l’époque : vingt shillings font une livre (£).'],
        ['Mrs Hudson', 'La logeuse du 221B, qui ouvre la porte aux visiteurs… et aux Irregulars.']
      ],
      grammar: [
        ['prétérit (simple past)', 'Le temps du récit : une action terminée, datée dans le passé.'],
        ['base verbale', 'Le verbe sans terminaison, comme dans le dictionnaire : see, go, carry.'],
        ['participe passé', 'La troisième colonne de la liste des verbes irréguliers ; -ed pour un verbe régulier.'],
        ['present perfect', 'have / has + participe passé : le passé vu depuis le présent.'],
        ['auxiliaire', 'Le petit verbe qui porte le temps dans une question ou une négation : do, does, did, is, has…'],
        ['réponse courte', '« Yes, he did. » « No, they haven’t. » : on reprend l’auxiliaire, sans le verbe.'],
        ['comparatif', 'Comparer deux choses : older than, more expensive than, as old as.'],
        ['superlatif', 'Le plus haut degré parmi plusieurs : the oldest, the most valuable, the best.'],
        ['modal', 'can, could, must… : il se met devant la base verbale, sans -s ni to.'],
        ['fausse piste', 'Une erreur qui revient : deux fois la même parmi les trente dernières réponses. Elle se lève en trois témoignages.']
      ]
    }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
