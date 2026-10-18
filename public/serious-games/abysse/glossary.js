/* Station Abysse — the glossary: the station's words and the SVT words the observations use.
   Definitions, never answers: open over any screen, during a dive and the ascent included.
   A classic script that sets globalThis.ABYGlossary. */
(function (root) {
  'use strict';

  root.ABYGlossary = {
    station: [
      ['Bathyscaphe', 'Le sous-marin de relève, amarré à la station. Il ne remonte qu’avec un carnet complet.'],
      ['Carnet d’espèces', 'Le registre de la station : une page par espèce, avec son croquis. Une espèce y entre dès qu’une plongée compte six observations justes sur douze.'],
      ['Croquis', 'Le dessin d’une espèce dans le carnet, d’après la meilleure plongée : esquissé (6 ou 7), annoté (8 ou 9), colorié (10 ou 11), encadré (12). Il ne pâlit jamais.'],
      ['Dorsale médio-atlantique', 'La chaîne de montagnes sous-marines qui coupe l’Atlantique du nord au sud, là où deux plaques s’écartent. La station y est posée.'],
      ['Fumeur noir', 'Une cheminée hydrothermale : de l’eau à plus de 350 °C, chargée de soufre et de métaux, sort du plancher océanique et noircit en refroidissant.'],
      ['Hublot', 'La vitre épaisse du poste d’observation. À 3 000 m, elle supporte trois cents fois la pression de la surface.'],
      ['Palier', 'Une profondeur de la plongée : 200 m, puis 1 000 m, puis 3 000 m. Plus on descend, plus l’observation demande d’étapes.'],
      ['Plongée', 'Une série de douze observations sur une espèce, en trois paliers. Autant de plongées qu’on veut, sans chronomètre.'],
      ['Anomalie', 'Un écho qui revient au sonar : la même erreur faite deux fois dans les trente dernières observations. Trois observations ciblées l’étudient ; deux justes l’identifient.'],
      ['La Remontée', 'L’épreuve finale : douze observations de tout le carnet pendant la remontée du bathyscaphe, en 20 minutes, corrigées à l’arrivée.'],
      ['Sonar', 'L’appareil qui écoute l’écho des sons sous l’eau. Sur l’écran de la station, il montre les anomalies.'],
      ['Source hydrothermale', 'Un endroit où de l’eau chauffée par le magma ressort du plancher océanique. Sans lumière, la vie y tire son énergie de la chimie.']
    ],
    svt: [
      ['ADN', 'La molécule qui porte l’information génétique : deux brins de nucléotides (A, T, C, G) appariés A avec T et C avec G.'],
      ['Allèle', 'Une des versions d’un même gène. Chaque individu en porte deux, un sur chaque chromosome de la paire.'],
      ['Arbre de parenté', 'Un schéma qui regroupe les espèces selon les caractères qu’elles ont hérités d’un ancêtre commun. Il se lit de nœud en nœud.'],
      ['ARN messager', 'La copie d’un gène qui sort du noyau : un seul brin, où l’uracile (U) remplace la thymine (T).'],
      ['Autotrophe', 'Un être vivant qui fabrique sa matière organique à partir de matière minérale, grâce à la lumière (photosynthèse) ou à l’énergie de réactions chimiques (chimiosynthèse).'],
      ['Biodiversité', 'La diversité du vivant, à trois échelles : génétique (entre individus d’une espèce), spécifique (entre espèces), des écosystèmes.'],
      ['Caractère', 'Un trait anatomique observable (vertèbres, mâchoires, plumes…). Pour classer, on utilise les caractères hérités, partagés par descendance.'],
      ['Capture-marquage-recapture', 'Une méthode pour estimer un effectif : on marque des individus, on les relâche, puis on compte la part d’individus marqués dans une seconde capture.'],
      ['Chimiosynthèse', 'La fabrication de matière organique avec l’énergie de réactions chimiques (soufre, méthane), sans lumière.'],
      ['Codon', 'Un groupe de trois nucléotides de l’ARN messager. Le code génétique associe chaque codon à un acide aminé, ou à un arrêt (codon stop).'],
      ['Dérive génétique', 'La variation, due au hasard, de la fréquence d’un allèle d’une génération à la suivante. Elle est d’autant plus forte que la population est petite.'],
      ['Enzyme', 'Une protéine qui accélère une réaction chimique. Son activité dépend de la température : elle est maximale à son optimum.'],
      ['Espèce', 'Un ensemble d’individus qui peuvent se reproduire entre eux et avoir des descendants fertiles : ils sont interféconds.'],
      ['Fermentation', 'Une dégradation incomplète du glucose, sans dioxygène, qui produit par exemple de l’éthanol.'],
      ['Génotype, phénotype', 'Le génotype est l’ensemble des allèles d’un individu ; le phénotype, ce qu’on observe (couleur, forme…).'],
      ['Hétérotrophe', 'Un être vivant qui prélève sa matière organique dans son milieu, en consommant d’autres êtres vivants.'],
      ['Mutation', 'Une modification de la séquence de l’ADN. Elle se produit au hasard ; elle peut être silencieuse, faux-sens ou non-sens.'],
      ['Mutualisme', 'Une relation où deux espèces tirent chacune un bénéfice de l’autre.'],
      ['Niveau trophique', 'La place d’un être vivant dans un réseau trophique : producteur, consommateur primaire, secondaire…'],
      ['Parasitisme', 'Une relation où un être vivant vit aux dépens d’un autre, sans le tuer tout de suite.'],
      ['Photosynthèse', 'La fabrication de matière organique à partir de dioxyde de carbone et d’eau, grâce à l’énergie lumineuse ; elle rejette du dioxygène.'],
      ['Rendement', 'La part de l’énergie d’un niveau trophique qui passe au niveau suivant, en pourcentage. Le reste est perdu (respiration, chaleur, déchets).'],
      ['Réseau trophique', 'L’ensemble des relations alimentaires d’un milieu. Une flèche va de l’aliment vers celui qui le mange : elle signifie « est mangé par ».'],
      ['Respiration', 'La dégradation du glucose avec du dioxygène, qui libère de l’énergie et rejette du dioxyde de carbone et de l’eau.'],
      ['Sélection naturelle', 'Le tri, par le milieu, des individus qui survivent et se reproduisent le mieux : leurs allèles deviennent plus fréquents.'],
      ['Traduction', 'La lecture de l’ARN messager, codon par codon, depuis le codon initiateur AUG jusqu’à un codon stop, qui produit une protéine.'],
      ['Transcription', 'La fabrication d’un ARN messager complémentaire du brin transcrit d’un gène.']
    ]
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
