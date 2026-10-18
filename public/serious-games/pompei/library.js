/* Les Tablettes de Pompéi — the Bibliothèque and the Glossaire: what a student consults to revise
   or to learn, at any moment, a series included.

   The tables below are not written by hand: they are LatinMorphology's own (src/SeriousGame/Latin/),
   the grammar that corrects the answers, dumped once - and PompeiLibraryTest checks that every form
   here is still the one the server expects, so the library and the corrector cannot drift apart.

   A classic script that sets globalThis.POMLibrary. */
(function (root) {
  'use strict';
  root.POMLibrary = {
    "tables": {
      "nouns": [
        {
          "entry": [
            "rosa",
            "rosae",
            "f"
          ],
          "meaning": "la rose",
          "room": "atrium",
          "kind": "1re déclinaison",
          "sg": [
            "rosa",
            "rosa",
            "rosam",
            "rosae",
            "rosae",
            "rosa"
          ],
          "pl": [
            "rosae",
            "rosae",
            "rosas",
            "rosarum",
            "rosis",
            "rosis"
          ]
        },
        {
          "entry": [
            "dominus",
            "domini",
            "m"
          ],
          "meaning": "le maître",
          "room": "atrium",
          "kind": "2e déclinaison en -us",
          "sg": [
            "dominus",
            "domine",
            "dominum",
            "domini",
            "domino",
            "domino"
          ],
          "pl": [
            "domini",
            "domini",
            "dominos",
            "dominorum",
            "dominis",
            "dominis"
          ]
        },
        {
          "entry": [
            "puer",
            "pueri",
            "m"
          ],
          "meaning": "l’enfant",
          "room": "atrium",
          "kind": "2e déclinaison en -er (garde le e)",
          "sg": [
            "puer",
            "puer",
            "puerum",
            "pueri",
            "puero",
            "puero"
          ],
          "pl": [
            "pueri",
            "pueri",
            "pueros",
            "puerorum",
            "pueris",
            "pueris"
          ]
        },
        {
          "entry": [
            "ager",
            "agri",
            "m"
          ],
          "meaning": "le champ",
          "room": "atrium",
          "kind": "2e déclinaison en -er (perd le e)",
          "sg": [
            "ager",
            "ager",
            "agrum",
            "agri",
            "agro",
            "agro"
          ],
          "pl": [
            "agri",
            "agri",
            "agros",
            "agrorum",
            "agris",
            "agris"
          ]
        },
        {
          "entry": [
            "templum",
            "templi",
            "n"
          ],
          "meaning": "le temple",
          "room": "atrium",
          "kind": "2e déclinaison, neutre",
          "sg": [
            "templum",
            "templum",
            "templum",
            "templi",
            "templo",
            "templo"
          ],
          "pl": [
            "templa",
            "templa",
            "templa",
            "templorum",
            "templis",
            "templis"
          ]
        },
        {
          "entry": [
            "rex",
            "regis",
            "m"
          ],
          "meaning": "le roi",
          "room": "tablinum",
          "kind": "3e déclinaison, imparisyllabique",
          "sg": [
            "rex",
            "rex",
            "regem",
            "regis",
            "regi",
            "rege"
          ],
          "pl": [
            "reges",
            "reges",
            "reges",
            "regum",
            "regibus",
            "regibus"
          ]
        },
        {
          "entry": [
            "corpus",
            "corporis",
            "n"
          ],
          "meaning": "le corps",
          "room": "tablinum",
          "kind": "3e déclinaison, neutre",
          "sg": [
            "corpus",
            "corpus",
            "corpus",
            "corporis",
            "corpori",
            "corpore"
          ],
          "pl": [
            "corpora",
            "corpora",
            "corpora",
            "corporum",
            "corporibus",
            "corporibus"
          ]
        },
        {
          "entry": [
            "civis",
            "civis",
            "m"
          ],
          "meaning": "le citoyen",
          "room": "tablinum",
          "kind": "3e déclinaison, parisyllabique (thème en -i)",
          "sg": [
            "civis",
            "civis",
            "civem",
            "civis",
            "civi",
            "cive"
          ],
          "pl": [
            "cives",
            "cives",
            "cives / civis",
            "civium",
            "civibus",
            "civibus"
          ]
        },
        {
          "entry": [
            "urbs",
            "urbis",
            "f"
          ],
          "meaning": "la ville",
          "room": "tablinum",
          "kind": "3e déclinaison, deux consonnes (thème en -i)",
          "sg": [
            "urbs",
            "urbs",
            "urbem",
            "urbis",
            "urbi",
            "urbe"
          ],
          "pl": [
            "urbes",
            "urbes",
            "urbes / urbis",
            "urbium",
            "urbibus",
            "urbibus"
          ]
        },
        {
          "entry": [
            "mare",
            "maris",
            "n"
          ],
          "meaning": "la mer",
          "room": "tablinum",
          "kind": "3e déclinaison, neutre en -e (thème en -i)",
          "sg": [
            "mare",
            "mare",
            "mare",
            "maris",
            "mari",
            "mari"
          ],
          "pl": [
            "maria",
            "maria",
            "maria",
            "marium",
            "maribus",
            "maribus"
          ]
        },
        {
          "entry": [
            "manus",
            "manus",
            "f"
          ],
          "meaning": "la main",
          "room": "triclinium",
          "kind": "4e déclinaison",
          "sg": [
            "manus",
            "manus",
            "manum",
            "manus",
            "manui",
            "manu"
          ],
          "pl": [
            "manus",
            "manus",
            "manus",
            "manuum",
            "manibus",
            "manibus"
          ]
        },
        {
          "entry": [
            "cornu",
            "cornus",
            "n"
          ],
          "meaning": "la corne",
          "room": "triclinium",
          "kind": "4e déclinaison, neutre",
          "sg": [
            "cornu",
            "cornu",
            "cornu",
            "cornus",
            "cornu / cornui",
            "cornu"
          ],
          "pl": [
            "cornua",
            "cornua",
            "cornua",
            "cornuum",
            "cornibus",
            "cornibus"
          ]
        },
        {
          "entry": [
            "res",
            "rei",
            "f"
          ],
          "meaning": "la chose",
          "room": "triclinium",
          "kind": "5e déclinaison",
          "sg": [
            "res",
            "res",
            "rem",
            "rei",
            "rei",
            "re"
          ],
          "pl": [
            "res",
            "res",
            "res",
            "rerum",
            "rebus",
            "rebus"
          ]
        },
        {
          "entry": [
            "dies",
            "diei",
            "m"
          ],
          "meaning": "le jour",
          "room": "triclinium",
          "kind": "5e déclinaison",
          "sg": [
            "dies",
            "dies",
            "diem",
            "diei",
            "diei",
            "die"
          ],
          "pl": [
            "dies",
            "dies",
            "dies",
            "dierum",
            "diebus",
            "diebus"
          ]
        }
      ],
      "adjectives": [
        {
          "entry": [
            "bonus",
            "bona",
            "bonum",
            "boni"
          ],
          "meaning": "bon",
          "kind": "1re classe : -us, -a, -um",
          "sg": {
            "m": [
              "bonus",
              "bone",
              "bonum",
              "boni",
              "bono",
              "bono"
            ],
            "f": [
              "bona",
              "bona",
              "bonam",
              "bonae",
              "bonae",
              "bona"
            ],
            "n": [
              "bonum",
              "bonum",
              "bonum",
              "boni",
              "bono",
              "bono"
            ]
          },
          "pl": {
            "m": [
              "boni",
              "boni",
              "bonos",
              "bonorum",
              "bonis",
              "bonis"
            ],
            "f": [
              "bonae",
              "bonae",
              "bonas",
              "bonarum",
              "bonis",
              "bonis"
            ],
            "n": [
              "bona",
              "bona",
              "bona",
              "bonorum",
              "bonis",
              "bonis"
            ]
          }
        },
        {
          "entry": [
            "pulcher",
            "pulchra",
            "pulchrum",
            "pulchri"
          ],
          "meaning": "beau",
          "kind": "1re classe en -er",
          "sg": {
            "m": [
              "pulcher",
              "pulcher",
              "pulchrum",
              "pulchri",
              "pulchro",
              "pulchro"
            ],
            "f": [
              "pulchra",
              "pulchra",
              "pulchram",
              "pulchrae",
              "pulchrae",
              "pulchra"
            ],
            "n": [
              "pulchrum",
              "pulchrum",
              "pulchrum",
              "pulchri",
              "pulchro",
              "pulchro"
            ]
          },
          "pl": {
            "m": [
              "pulchri",
              "pulchri",
              "pulchros",
              "pulchrorum",
              "pulchris",
              "pulchris"
            ],
            "f": [
              "pulchrae",
              "pulchrae",
              "pulchras",
              "pulchrarum",
              "pulchris",
              "pulchris"
            ],
            "n": [
              "pulchra",
              "pulchra",
              "pulchra",
              "pulchrorum",
              "pulchris",
              "pulchris"
            ]
          }
        },
        {
          "entry": [
            "fortis",
            "fortis",
            "forte",
            "fortis"
          ],
          "meaning": "courageux",
          "kind": "2e classe, deux terminaisons",
          "sg": {
            "m": [
              "fortis",
              "fortis",
              "fortem",
              "fortis",
              "forti",
              "forti"
            ],
            "f": [
              "fortis",
              "fortis",
              "fortem",
              "fortis",
              "forti",
              "forti"
            ],
            "n": [
              "forte",
              "forte",
              "forte",
              "fortis",
              "forti",
              "forti"
            ]
          },
          "pl": {
            "m": [
              "fortes",
              "fortes",
              "fortes / fortis",
              "fortium",
              "fortibus",
              "fortibus"
            ],
            "f": [
              "fortes",
              "fortes",
              "fortes / fortis",
              "fortium",
              "fortibus",
              "fortibus"
            ],
            "n": [
              "fortia",
              "fortia",
              "fortia",
              "fortium",
              "fortibus",
              "fortibus"
            ]
          }
        },
        {
          "entry": [
            "acer",
            "acris",
            "acre",
            "acris"
          ],
          "meaning": "vif",
          "kind": "2e classe, trois terminaisons",
          "sg": {
            "m": [
              "acer",
              "acer",
              "acrem",
              "acris",
              "acri",
              "acri"
            ],
            "f": [
              "acris",
              "acris",
              "acrem",
              "acris",
              "acri",
              "acri"
            ],
            "n": [
              "acre",
              "acre",
              "acre",
              "acris",
              "acri",
              "acri"
            ]
          },
          "pl": {
            "m": [
              "acres",
              "acres",
              "acres / acris",
              "acrium",
              "acribus",
              "acribus"
            ],
            "f": [
              "acres",
              "acres",
              "acres / acris",
              "acrium",
              "acribus",
              "acribus"
            ],
            "n": [
              "acria",
              "acria",
              "acria",
              "acrium",
              "acribus",
              "acribus"
            ]
          }
        },
        {
          "entry": [
            "ingens",
            "ingens",
            "ingens",
            "ingentis"
          ],
          "meaning": "immense",
          "kind": "2e classe, une terminaison",
          "sg": {
            "m": [
              "ingens",
              "ingens",
              "ingentem",
              "ingentis",
              "ingenti",
              "ingenti"
            ],
            "f": [
              "ingens",
              "ingens",
              "ingentem",
              "ingentis",
              "ingenti",
              "ingenti"
            ],
            "n": [
              "ingens",
              "ingens",
              "ingens",
              "ingentis",
              "ingenti",
              "ingenti"
            ]
          },
          "pl": {
            "m": [
              "ingentes",
              "ingentes",
              "ingentes / ingentis",
              "ingentium",
              "ingentibus",
              "ingentibus"
            ],
            "f": [
              "ingentes",
              "ingentes",
              "ingentes / ingentis",
              "ingentium",
              "ingentibus",
              "ingentibus"
            ],
            "n": [
              "ingentia",
              "ingentia",
              "ingentia",
              "ingentium",
              "ingentibus",
              "ingentibus"
            ]
          }
        }
      ],
      "pronouns": [
        {
          "lemma": "is",
          "head": "is, ea, id",
          "meaning": "ce, cette ; il, elle (anaphorique)",
          "sg": {
            "m": [
              "is",
              "eum",
              "eius",
              "ei",
              "eo"
            ],
            "f": [
              "ea",
              "eam",
              "eius",
              "ei",
              "ea"
            ],
            "n": [
              "id",
              "id",
              "eius",
              "ei",
              "eo"
            ]
          },
          "pl": {
            "m": [
              "ei / ii",
              "eos",
              "eorum",
              "eis / iis",
              "eis / iis"
            ],
            "f": [
              "eae",
              "eas",
              "earum",
              "eis / iis",
              "eis / iis"
            ],
            "n": [
              "ea",
              "ea",
              "eorum",
              "eis / iis",
              "eis / iis"
            ]
          }
        },
        {
          "lemma": "hic",
          "head": "hic, haec, hoc",
          "meaning": "celui-ci, ce… -ci (proche)",
          "sg": {
            "m": [
              "hic",
              "hunc",
              "huius",
              "huic",
              "hoc"
            ],
            "f": [
              "haec",
              "hanc",
              "huius",
              "huic",
              "hac"
            ],
            "n": [
              "hoc",
              "hoc",
              "huius",
              "huic",
              "hoc"
            ]
          },
          "pl": {
            "m": [
              "hi",
              "hos",
              "horum",
              "his",
              "his"
            ],
            "f": [
              "hae",
              "has",
              "harum",
              "his",
              "his"
            ],
            "n": [
              "haec",
              "haec",
              "horum",
              "his",
              "his"
            ]
          }
        },
        {
          "lemma": "ille",
          "head": "ille, illa, illud",
          "meaning": "celui-là, ce… -là (éloigné)",
          "sg": {
            "m": [
              "ille",
              "illum",
              "illius",
              "illi",
              "illo"
            ],
            "f": [
              "illa",
              "illam",
              "illius",
              "illi",
              "illa"
            ],
            "n": [
              "illud",
              "illud",
              "illius",
              "illi",
              "illo"
            ]
          },
          "pl": {
            "m": [
              "illi",
              "illos",
              "illorum",
              "illis",
              "illis"
            ],
            "f": [
              "illae",
              "illas",
              "illarum",
              "illis",
              "illis"
            ],
            "n": [
              "illa",
              "illa",
              "illorum",
              "illis",
              "illis"
            ]
          }
        },
        {
          "lemma": "qui",
          "head": "qui, quae, quod",
          "meaning": "qui, que (relatif)",
          "sg": {
            "m": [
              "qui",
              "quem",
              "cuius",
              "cui",
              "quo"
            ],
            "f": [
              "quae",
              "quam",
              "cuius",
              "cui",
              "qua"
            ],
            "n": [
              "quod",
              "quod",
              "cuius",
              "cui",
              "quo"
            ]
          },
          "pl": {
            "m": [
              "qui",
              "quos",
              "quorum",
              "quibus",
              "quibus"
            ],
            "f": [
              "quae",
              "quas",
              "quarum",
              "quibus",
              "quibus"
            ],
            "n": [
              "quae",
              "quae",
              "quorum",
              "quibus",
              "quibus"
            ]
          }
        },
        {
          "lemma": "ego",
          "head": "ego, nos",
          "meaning": "je, nous",
          "personal": true,
          "sg": [
            "ego",
            "—",
            "me",
            "mei",
            "mihi",
            "me"
          ],
          "pl": [
            "nos",
            "—",
            "nos",
            "nostri / nostrum",
            "nobis",
            "nobis"
          ]
        },
        {
          "lemma": "tu",
          "head": "tu, vos",
          "meaning": "tu, vous",
          "personal": true,
          "sg": [
            "tu",
            "tu",
            "te",
            "tui",
            "tibi",
            "te"
          ],
          "pl": [
            "vos",
            "vos",
            "vos",
            "vestri / vestrum",
            "vobis",
            "vobis"
          ]
        },
        {
          "lemma": "se",
          "head": "se, sui, sibi",
          "meaning": "se, soi (réfléchi)",
          "personal": true,
          "sg": [
            "—",
            "—",
            "se",
            "sui",
            "sibi",
            "se"
          ],
          "pl": [
            "—",
            "—",
            "se",
            "sui",
            "sibi",
            "se"
          ]
        }
      ]
    },
    "glossary": {
      "villa": [
        [
          "villa",
          "La maison d’un riche propriétaire. Celle de Julia Felix est à bâtir, pièce par pièce."
        ],
        [
          "domus",
          "La maison urbaine romaine, organisée autour de l’atrium et du péristyle."
        ],
        [
          "fauces",
          "Le couloir d’entrée, qui mène de la rue à l’atrium (littéralement « la gorge »)."
        ],
        [
          "atrium",
          "La pièce d’entrée de la maison romaine, ouverte sur le ciel en son centre."
        ],
        [
          "compluvium · impluvium",
          "L’ouverture du toit de l’atrium, et le bassin qui recueille la pluie en dessous."
        ],
        [
          "tablinum",
          "Le bureau du maître de maison, entre l’atrium et le péristyle, où l’on range les archives."
        ],
        [
          "triclinium",
          "La salle à manger : trois lits disposés autour d’une table, sur lesquels on mange allongé."
        ],
        [
          "peristylium",
          "Le péristyle : un jardin intérieur entouré de colonnes."
        ],
        [
          "thermae",
          "Les thermes : les bains. Une riche villa avait les siens."
        ],
        [
          "hortus",
          "Le jardin, souvent au fond de la maison."
        ],
        [
          "lararium",
          "Le petit autel domestique dédié aux Lares, dieux protecteurs du foyer."
        ],
        [
          "Lares",
          "Les dieux protecteurs de la maison et de la famille, honorés au lararium."
        ],
        [
          "tabula (cerata)",
          "Une tablette : une planchette de bois creusée et remplie de cire, sur laquelle on écrit."
        ],
        [
          "cera",
          "La cire de la tablette. On la lisse avec le plat du stylet pour effacer."
        ],
        [
          "stilus",
          "Le stylet : une tige pointue pour graver la cire, plate à l’autre bout pour l’effacer."
        ],
        [
          "groma",
          "L’instrument des arpenteurs romains : une croix et quatre fils à plomb, pour tracer des angles droits."
        ],
        [
          "architectus",
          "L’architecte. Ici Caius Vibius Rufus, qui dicte ses consignes en latin."
        ],
        [
          "Cave canem",
          "« Prends garde au chien » : l’inscription de la mosaïque d’entrée d’une maison de Pompéi."
        ],
        [
          "Pompéi",
          "Ville de Campanie, au pied du Vésuve. Le jeu se passe en l’an 78 : la ville est vivante."
        ],
        [
          "Julia Felix",
          "Une riche habitante de Pompéi, propriétaire d’un grand domaine. Ici, la commanditaire de la villa."
        ]
      ],
      "grammaire": [
        [
          "cas",
          "La forme que prend un mot selon sa fonction dans la phrase. Le latin en a six."
        ],
        [
          "nominatif",
          "Le cas du sujet et de l’attribut du sujet. C’est aussi la forme du dictionnaire."
        ],
        [
          "vocatif",
          "Le cas de l’apostrophe : la personne à qui l’on parle (« Ô maître ! » : domine)."
        ],
        [
          "accusatif",
          "Le cas du complément d’objet direct, et du complément de certaines prépositions (ad, per, apud…)."
        ],
        [
          "génitif",
          "Le cas du complément du nom (« le jardin du maître » : hortus domini)."
        ],
        [
          "datif",
          "Le cas du complément d’attribution (« donner au maître » : domino dare)."
        ],
        [
          "ablatif",
          "Le cas des compléments circonstanciels (moyen, manière, lieu…) et de certaines prépositions (cum, sine, ex…)."
        ],
        [
          "déclinaison",
          "L’ensemble des formes d’un nom ; le latin range ses noms en cinq déclinaisons."
        ],
        [
          "génitif singulier",
          "La deuxième forme donnée par le dictionnaire (rosa, rosae) : sa terminaison dit la déclinaison."
        ],
        [
          "radical",
          "Ce qui reste du génitif singulier sans sa terminaison (reg- dans regis) : on lui ajoute les terminaisons."
        ],
        [
          "terminaison",
          "La fin du mot, qui change selon le cas et le nombre."
        ],
        [
          "parisyllabique",
          "Qui a autant de syllabes au nominatif qu’au génitif (civis, civis) : génitif pluriel en -ium."
        ],
        [
          "imparisyllabique",
          "Qui a une syllabe de plus au génitif (rex, regis) : génitif pluriel en -um."
        ],
        [
          "thème en -i",
          "Nom ou adjectif de la 3e déclinaison qui garde un -i- à certains cas : génitif pluriel en -ium, neutre pluriel en -ia."
        ],
        [
          "neutre",
          "Le troisième genre. Un neutre a la même forme au nominatif, au vocatif et à l’accusatif ; au pluriel, en -a."
        ],
        [
          "accord",
          "L’adjectif prend le genre, le nombre et le cas du nom qu’il accompagne, pas sa terminaison."
        ],
        [
          "pronom relatif",
          "Qui, quae, quod : il prend le genre et le nombre de son antécédent, et le cas de sa fonction."
        ],
        [
          "antécédent",
          "Le nom que reprend le pronom relatif."
        ],
        [
          "pronom réfléchi",
          "Se, sui, sibi : il renvoie au sujet de la phrase."
        ],
        [
          "préposition",
          "Petit mot invariable qui gouverne un cas : cum + ablatif, ad + accusatif."
        ],
        [
          "macron",
          "Le trait sur une voyelle longue (ā). Facultatif dans le jeu : rosa et rosā sont la même réponse."
        ]
      ]
    },
    "functions": [
      [
        "Nominatif",
        "sujet, attribut du sujet",
        "Puella cantat.",
        "La jeune fille chante."
      ],
      [
        "Vocatif",
        "apostrophe",
        "Domine, veni !",
        "Maître, viens !"
      ],
      [
        "Accusatif",
        "COD ; après ad, per, ante, post, apud, contra, propter, inter, circum, trans ; in + mouvement",
        "Dominus servum vocat.",
        "Le maître appelle l’esclave."
      ],
      [
        "Génitif",
        "complément du nom",
        "Hortus domini.",
        "Le jardin du maître."
      ],
      [
        "Datif",
        "complément d’attribution (à qui ?)",
        "Puella domino rosam dat.",
        "La jeune fille donne une rose au maître."
      ],
      [
        "Ablatif",
        "compléments circonstanciels (moyen, manière…) ; après cum, sine, ex, ab, de, pro, sub ; in sans mouvement",
        "Miles gladio pugnat.",
        "Le soldat combat avec son épée."
      ]
    ],
    "prepositions": {
      "acc": [
        "ad (vers)",
        "per (à travers)",
        "ante (devant)",
        "post (derrière, après)",
        "apud (chez)",
        "contra (contre)",
        "propter (à cause de)",
        "inter (parmi, entre)",
        "circum (autour de)",
        "trans (au-delà de)",
        "in (dans, vers : avec mouvement)",
        "sub (sous : avec mouvement)"
      ],
      "abl": [
        "cum (avec)",
        "sine (sans)",
        "ex / e (hors de)",
        "ab / a (loin de, par)",
        "de (au sujet de, du haut de)",
        "pro (pour, devant)",
        "in (dans : sans mouvement)",
        "sub (sous : sans mouvement)"
      ]
    },
    "genitives": [
      [
        "1re",
        "-ae",
        "rosa, rosae"
      ],
      [
        "2e",
        "-i",
        "dominus, domini ; templum, templi"
      ],
      [
        "3e",
        "-is",
        "rex, regis ; corpus, corporis"
      ],
      [
        "4e",
        "-us",
        "manus, manus ; cornu, cornus"
      ],
      [
        "5e",
        "-ei",
        "res, rei ; dies, diei"
      ]
    ]
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
