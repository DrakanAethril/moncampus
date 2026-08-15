<?php

declare(strict_types=1);

namespace App\Referential;

/**
 * The Bac+3 Informatique training referential ("TSF"), one entry per competency, transcribed from
 * the establishment's own document - design/sources/TSF_Bachelor_Info_25_26.pdf, 23 fiches.
 *
 * Loaded by App\Command\ImportTsfReferentialCommand into one Program at a time, since production
 * runs several years of this course side by side and each one owns its copy of the referential.
 *
 * Three things about this transcription are deliberate:
 *
 * - It is faithful, not corrected. Where the source document is odd - C.11's "Connaissances" and
 *   "Activités" columns read as if swapped, several bullets keep a trailing full stop and others
 *   do not - it is reproduced as printed. An admin fixes it in the screens afterwards; an import
 *   that silently improves its source is an import nobody can audit against the source.
 * - The option is named by the *database's* short name, "CDA". The document writes "CDWM"
 *   throughout while describing the CDA occupation ("Le concepteur développeur d'applications…").
 * - Every fiche carries the same three assessment strands, so they are stated once in
 *   ASSESSMENT_* and a fiche only names them when it departs from that - none does today.
 *
 * @phpstan-type SkillDefinition array{
 *     code: string,
 *     label: string,
 *     aliases?: list<string>,
 *     occupationDescription: string,
 *     knowledge: list<string>,
 *     activities: list<string>,
 *     performanceCriteria: list<string>,
 *     volumeHours: string,
 *     teachingPeriod: string,
 *     teacher: string,
 *     diagnosticAssessment?: string,
 *     summativeAssessment?: string,
 *     certifyingAssessment?: string
 * }
 * @phpstan-type GroupDefinition array{
 *     code: ?string,
 *     label: string,
 *     aliases?: list<string>,
 *     optionShortName: ?string,
 *     skills: list<SkillDefinition>
 * }
 * @phpstan-type CertificationDefinition array{
 *     optionShortName: string,
 *     label: string,
 *     kind: string,
 *     rncpCode: ?string,
 *     level: ?int,
 *     certifier: ?string
 * }
 */
class BachelorInfoTsfCatalog
{
    public const ASSESSMENT_DIAGNOSTIC = 'Par écrit dès la première semaine de formation';
    public const ASSESSMENT_SUMMATIVE = 'À la fin de chaque période via le suivi de projet';
    public const ASSESSMENT_CERTIFYING = 'Soutenance orale au mois de juin';

    /**
     * The certifications this course prepares, one per option - the whole reason
     * ProgramCertification is keyed by option rather than by program.
     *
     * The RNCP codes, levels and certifier are NOT in the source document: it names only
     * "TP - Administrateur d'Infrastructures Sécurisées", and prints it on the CDA fiches too,
     * which is an error of the document. They are left null on purpose - the import writes the
     * label it knows and leaves the rest for an admin, rather than inventing a national identifier.
     *
     * @return list<CertificationDefinition>
     */
    public function certifications(): array
    {
        return [
            [
                'optionShortName' => 'AIS',
                'label' => "Administrateur d'Infrastructures Sécurisées",
                'kind' => 'titre_pro',
                'rncpCode' => null,
                'level' => null,
                'certifier' => null,
            ],
            [
                'optionShortName' => 'CDA',
                'label' => "Concepteur Développeur d'Applications",
                'kind' => 'titre_pro',
                'rncpCode' => null,
                'level' => null,
                'certifier' => null,
            ],
        ];
    }

    /** @return list<GroupDefinition> */
    public function groups(): array
    {
        return [
            [
                'code' => 'CCP 1',
                'label' => 'Développer une application sécurisée',
                'optionShortName' => 'CDA',
                'skills' => $this->cdaCcp1(),
            ],
            [
                'code' => 'CCP 2',
                'label' => 'Concevoir et développer une application sécurisée organisée en couches',
                'optionShortName' => 'CDA',
                'skills' => $this->cdaCcp2(),
            ],
            [
                'code' => 'CCP 3',
                'label' => "Préparer le déploiement d'une application sécurisée",
                'optionShortName' => 'CDA',
                'skills' => $this->cdaCcp3(),
            ],
            [
                // No CCP and no option: the two communication competencies are common to both
                // certifications. This group does not exist in the database yet - it is the only
                // one the import creates rather than fills.
                'code' => null,
                'label' => 'Compétences transverses',
                'optionShortName' => null,
                'skills' => $this->crossCutting(),
            ],
            [
                'code' => 'CCP 1',
                'label' => 'Administrer et sécuriser les infrastructures',
                'optionShortName' => 'AIS',
                'skills' => $this->aisCcp1(),
            ],
            [
                'code' => 'CCP 2',
                'label' => "Concevoir et mettre en œuvre une solution en réponse à un besoin d'évolution",
                'optionShortName' => 'AIS',
                'skills' => $this->aisCcp2(),
            ],
            [
                'code' => 'CCP 3',
                'label' => 'Participer à la gestion de la cybersécurité',
                'optionShortName' => 'AIS',
                'skills' => $this->aisCcp3(),
            ],
        ];
    }

    /** @return list<SkillDefinition> */
    private function cdaCcp1(): array
    {
        return [
            [
                'code' => 'C.1',
                'label' => 'Installer et configurer son environnement de travail en fonction du projet',
                'occupationDescription' => "Le concepteur développeur d'applications réalise l'installation et la configuration de son environnement de travail en début de projet, en adéquation avec les technologies utilisées. Il coordonne son environnement de travail avec les autres intervenants du projet en cas de travail en équipe.",
                'knowledge' => [
                    'Différents environnements de développement intégrés',
                    'Outils de gestion des versions et de partage de code',
                    "Différents formats de fichiers de persistance de données et d'échanges",
                    'Principales bases de données et leur mise en place',
                    'Outils collaboratifs de partage de ressources et leurs vulnérabilités',
                    'Outils de conteneurisation',
                ],
                'activities' => [
                    'Mettre en place et utiliser un environnement de développement intégré,',
                    'Mettre en place localement un serveur de données',
                    'Créer des fichiers pour la persistance de données ou pour des échanges',
                    'Utiliser un outil de gestion de versions',
                    'Paramétrer et utiliser un outil de conteneurisation',
                    'Faire évoluer son environnement de travail en adéquation',
                    "Intégrer son environnement de développement au sein d'une organisation",
                ],
                'performanceCriteria' => [
                    'Les outils de développement nécessaires sont installés',
                    'Les outils de gestion des versions et de collaboration sont installés',
                    'Les conteneurs implémentent les services requis',
                ],
                'volumeHours' => '30.00',
                'teachingPeriod' => 'Septembre-Octobre',
                'teacher' => 'F. Sautour',
            ],
            [
                'code' => 'C.2',
                'label' => 'Développer des interfaces utilisateur',
                // The database says "utilisateurs"; the referential says "utilisateur". Declared
                // rather than folded away - see ReferentialLabelMatcher.
                'aliases' => ['Développer des interfaces utilisateurs'],
                'occupationDescription' => "A partir du dossier de conception, développer les interfaces utilisateur sécurisées en tenant compte du type d'utilisation de l'application, de la charte graphique et de la règlementation en vigueur. Réaliser une veille technologique sur les évolutions techniques des interfaces utilisateur.",
                'knowledge' => [
                    'Architecture du web et des standards W3C',
                    'Principales failles de sécurité des applications web',
                    "Guide de recommandations de l'ANSSI",
                    'RGAA',
                    'Règles ergonomiques',
                    'Règles de référencement',
                ],
                'activities' => [
                    'Coder dans un langage de programmation, en adoptant un style défensif',
                    'Utiliser un langage de présentation HyperText Markup Language (HTML), Cascading Style Sheets (CSS)...',
                    "Gérer les événements de l'interface utilisateur",
                    'Utiliser un service distant (API Rest)',
                    "Adapter l'interface à la taille, au type et à la disposition du support",
                    "Structurer sa démarche de résolution de problème en cas de dysfonctionnement de l'interface",
                ],
                'performanceCriteria' => [
                    "L'interface est conforme au dossier de conception",
                    "L'interface est responsive",
                    'La charte graphique est respectée',
                    'La règlementation en vigueur est respectée',
                ],
                'volumeHours' => '35.00',
                'teachingPeriod' => 'Septembre-Octobre',
                'teacher' => 'D. Bude',
            ],
            [
                'code' => 'C.3',
                'label' => 'Développer des composants métier',
                'occupationDescription' => "A partir du dossier de conception, développer la partie dynamique de l'application avec des composants métier sécurisés, dans un style défensif, et éventuellement en asynchrone, en respectant les bonnes pratiques de la programmation orientée objet et les règles de nommage décrites dans les normes de qualité de l'entreprise.",
                'knowledge' => [
                    'Un langage de développement orienté objet',
                    'Principes et règles du développement sécurisé',
                    'Architectures logicielles multicouches réparties sécurisées',
                    "Différents patrons de conception et d'architecture",
                    'Middleware',
                    'Formats de données (fichiers JSON, XML, ...)',
                    'OWASP',
                ],
                'activities' => [
                    'Coder dans un langage orienté objet avec un style défensif',
                    'Appeler des Web Services dans un composant serveur',
                    "Gérer la sécurité de l'application (authentification, permissions, validation des entrées...)",
                    "Utiliser des composants d'accès aux données",
                    'Utiliser un service distant (API Rest)',
                    'Améliorer à fonctionnalités constantes un code existant (refactoring)',
                ],
                'performanceCriteria' => [
                    'Les bonnes pratiques de la programmation orientée objet (POO) sont respectées',
                    'Les composants métier sont sécurisés',
                    "Les règles de nommage sont conformes aux normes de qualité de l'entreprise",
                    'Les traitements répondent aux fonctionnalités décrites dans le dossier de conception',
                ],
                'volumeHours' => '35.00',
                'teachingPeriod' => 'Septembre-Octobre',
                'teacher' => 'S. Tharaud',
            ],
            [
                'code' => 'C.4',
                'label' => "Contribuer à la gestion d'un projet informatique",
                'occupationDescription' => "Cette compétence s'exerce seul ou au sein d'une équipe, en adéquation avec la méthode de gestion de projet choisie, séquentielle ou itérative. Pour les projets de petite taille ou au sein de petites entreprises, le concepteur développeur d'applications peut mener en autonomie la gestion complète d'un projet informatique.",
                'knowledge' => [
                    "Connaissance d'une démarche de développement séquentielle en termes d'outils de formalisation",
                    "Connaissance d'une démarche de développement en approche de type Agile",
                    'Connaissance des différents types de démarches de conception de logiciel',
                    'Connaissance des outils de planification',
                    "Connaissance des méthodologies de découpage de projets en itérations, d'estimation de complexité et de charge, de suivi en temps réel",
                ],
                'activities' => [
                    'Mettre en œuvre les procédures de la démarche qualité',
                    'Utiliser un outil collaboratif de gestion de projet',
                    "Participer à la planification et au suivi du projet au sein de l'équipe de projet",
                    'Collaborer de façon séquentielle à un projet informatique',
                    'Coordonner de façon itérative et en mode collaboratif un projet informatique',
                ],
                'performanceCriteria' => [
                    'Les tâches de conception et de développement sont planifiées en fonction du délai défini',
                    'Le suivi des tâches est mis en rapprochement avec la planification,',
                    'Les éventuels retards sont identifiés et les acteurs concernés sont alertés',
                    'Les procédures qualité sont mises en œuvre',
                    'Les outils collaboratifs sont choisis en fonction de la méthode de développement',
                ],
                'volumeHours' => '35.00',
                'teachingPeriod' => 'Septembre-Juin',
                'teacher' => 'V. Sautour',
            ],
        ];
    }

    /** @return list<SkillDefinition> */
    private function cdaCcp2(): array
    {
        return [
            [
                'code' => 'C.5',
                'label' => 'Analyser les besoins et maquetter une application',
                'occupationDescription' => "À partir du cahier des charges de la maîtrise d'ouvrage, analyser les besoins, réaliser les maquettes. Modéliser l'application à l'aide d'un schéma présentant l'enchainement des écrans. Constituer le dossier de conception en suivant une démarche de conception.",
                'knowledge' => [
                    "Connaissance d'une démarche de conception",
                    "Connaissance des règles ergonomiques issues de l'expérience utilisateur",
                    'Connaissance des obligations légales notamment le RGPD et le RGAA',
                    "Connaissance des normes d'accessibilité requises pour le projet",
                    "Connaissance des règles d'éco-conception des logiciels",
                    "Connaissance des composants d'interface graphique",
                ],
                'activities' => [
                    'Analyser un cahier des charges en identifiant les limites du système, les acteurs et les messages',
                    'Formaliser les besoins utilisateur (use cases, user stories ou autre)',
                    'Utiliser un outil de maquettage',
                    "Construire la maquette de l'application, l'enchaînement et la composition des écrans",
                    "Comprendre les notions d'accessibilité des contenus",
                    'Appliquer le RGAA',
                ],
                'performanceCriteria' => [
                    "Les besoins recensés couvrent l'ensemble des exigences utilisateur exprimées dans le cahier des charges",
                    "L'enchainement des maquettes est formalisé par un schéma",
                    'Le dossier de conception est structuré, en conformité avec la démarche de conception',
                ],
                'volumeHours' => '35.00',
                'teachingPeriod' => 'Septembre-Février',
                'teacher' => 'D. Bude',
            ],
            [
                'code' => 'C.6',
                'label' => "Définir l'architecture logicielle d'une application",
                'occupationDescription' => "En tenant compte des besoins des utilisateurs, en amont de tout développement, définir l'architecture logicielle multicouche répartie en vue du développement d'une application sécurisée. Définir le rôle de chaque couche en tenant compte de la stratégie de sécurité. Identifier les besoins d'éco-conception.",
                'knowledge' => [
                    'Connaissance des architectures logicielles multicouches réparties sécurisées',
                    "Connaissance des indicateurs de sécurité des systèmes d'information (DICP)",
                    'Connaissance du formalisme des diagrammes de modélisation',
                    'Connaissance des principaux langages, Framework et ORM du marché',
                ],
                'activities' => [
                    "Définir l'architecture logicielle en identifiant les Framework et ORM à utiliser",
                    "Adapter l'architecture logicielle aux besoins des utilisateurs et à la stratégie de sécurité selon les recommandations de l'ANSSI",
                    'Utiliser les patrons de conception (design patterns) et les patrons de sécurité (Security pattern)',
                ],
                'performanceCriteria' => [
                    "L'architecture logicielle est conforme aux bonnes pratiques d'une architecture multicouche répartie sécurisée",
                    'Le rôle de chaque couche est bien défini en tenant compte de la stratégie de sécurité',
                    "Les besoins d'éco-conception de l'application sont identifiés",
                ],
                'volumeHours' => '30.00',
                'teachingPeriod' => 'Janvier-Février',
                'teacher' => 'S. Tharaud',
            ],
            [
                'code' => 'C.7',
                'label' => 'Concevoir et mettre en place une base de données relationnelle',
                'aliases' => ['Concevoir et mettre en place une base de données relationnelles'],
                'occupationDescription' => "A partir des besoins exprimés dans le cahier des charges, concevoir le schéma conceptuel des données en respectant les règles des bases de données relationnelles, les règles de nommage en vigueur dans l'entreprise et en assurant l'intégrité des données. A partir du schéma conceptuel, comprendre la documentation technique, y compris en anglais, et mettre en place la base de données. Définir les utilisateurs et leurs droits d'accès en respectant les règles de sécurité et de confidentialité définies dans le cahier des charges.",
                'knowledge' => [
                    'Connaissance des concepts du modèle entité-association',
                    'Connaissance des règles de passage du modèle entité-association vers le modèle physique',
                    'Connaissance du RGPD',
                    'Connaissance du système de gestion de base de données relationnelles',
                    'Connaissance du langage de requête SQL',
                    'Connaissance des différents types de codage des données',
                    'Connaissance des vulnérabilités et des attaques classiques',
                    'Connaissance des bonnes pratiques de sécurisation',
                ],
                'activities' => [
                    'Recenser les informations du domaine étudié',
                    'Construire le schéma conceptuel des données',
                    'Construire le schéma logique des données',
                    'Construire le schéma physique des données',
                    "Mettre en œuvre les instructions d'écriture de base de données",
                    'Mettre en œuvre les instructions pour implémenter les contraintes',
                    'Exprimer les besoins de sécurité du SGDB',
                ],
                'performanceCriteria' => [
                    'Le schéma conceptuel respecte les règles du relationnel',
                    'Le schéma physique est conforme aux besoins exprimés dans le cahier des charges',
                    'Les règles de nommage ont été respectées',
                    "L'intégrité, la sécurité et la confidentialité des données est assurée",
                ],
                'volumeHours' => '40.00',
                'teachingPeriod' => 'Septembre-Octobre',
                'teacher' => 'S. Tharaud',
            ],
            [
                'code' => 'C.8',
                'label' => "Développer des composants d'accès aux données SQL et NoSQL",
                'occupationDescription' => "En tenant compte de la structure de la base de données et du dossier de conception, coder les traitements relatifs aux accès aux données en consultation, modification, création et suppression. S'assurer que les traitements gèrent l'intégrité et les conflits d'accès aux données, et qu'ils permettent de respecter la confidentialité, prendre en compte les cas d'exception. Valider et contrôler les entrées dans les composants serveurs sécurisés avant la mise à jour de la base de données. Réaliser une veille technologique sur les évolutions techniques et les problématiques de sécurité liées aux bases de données SQL et NoSQL.",
                'knowledge' => [
                    "Connaissance d'un langage de requête de type SQL",
                    "Connaissance d'une méthode d'interaction avec les bases de données NoSQL",
                    'Connaissance du langage de programmation du système de gestion de base de données',
                    "Connaissance de la gestion de l'intégrité des données",
                    'Connaissance des principes de fonctionnement des transactions',
                ],
                'activities' => [
                    'Coder de façon sécurisée les accès aux données relationnelles ou non relationnelles',
                    "Inclure dans les composants d'accès l'authentification et la gestion de la sécurité du SGDB",
                    'Programmer des fonctions, des procédures stockées et des déclencheurs (triggers)',
                    'Intégrer les traitements sur les données dans une transaction',
                ],
                'performanceCriteria' => [
                    'Les traitements relatifs aux manipulations des données répondent aux fonctionnalités',
                    "Les cas d'exception sont pris en compte",
                    "L'intégrité et la confidentialité des données sont maintenues",
                    "Les conflits d'accès aux données sont gérés",
                ],
                'volumeHours' => '35.00',
                'teachingPeriod' => 'Septembre-Février',
                'teacher' => 'S. Tharaud',
            ],
        ];
    }

    /** @return list<SkillDefinition> */
    private function cdaCcp3(): array
    {
        return [
            [
                'code' => 'C.9',
                'label' => "Préparer et exécuter les plans de tests d'une application",
                'occupationDescription' => "En tenant compte de toutes les fonctionnalités de l'application, préparer un plan de tests comprenant les tests d'intégration, y compris de non-régression si nécessaire, les tests systèmes y compris les tests de sécurité et de charge. Créer un environnement de tests. Exécuter ou faire exécuter, sur cet environnement, tous les tests d'intégration et système définis dans le plan, manuellement ou automatiquement avec des logiciels d'automatisation de tests. Faire réaliser par les utilisateurs de l'application les tests d'acceptation.",
                'knowledge' => [
                    'Connaissance des outils de tests',
                    'Connaissance des différents types de tests',
                    "Connaissance de la place et de l'impact des tests dans le cycle de vie du projet",
                ],
                'activities' => [
                    'Rédiger un plan de tests',
                    'Créer un environnement de test',
                    'Rechercher des failles de sécurité par des tests aléatoires (fuzzing)',
                    "Exécuter les tests d'intégration en manuel, ou en automatique",
                    'Exécuter un test de charge',
                    'Analyser les résultats des différents tests et apporter les corrections',
                    'Rédiger le dossier de compte rendu de tests',
                ],
                'performanceCriteria' => [
                    "Le plan de tests couvre l'ensemble des fonctionnalités retenues pour l'application",
                    'Un environnement de test est créé',
                    "L'intégralité des tests exécutés sont conformes au plan de tests défini",
                    'Les résultats obtenus sont cohérents avec les résultats attendus',
                ],
                'volumeHours' => '35.00',
                'teachingPeriod' => 'Mai-Juin',
                'teacher' => 'N. Giraud',
            ],
            [
                'code' => 'C.10',
                'label' => "Préparer et documenter le déploiement d'une application",
                'occupationDescription' => "En tenant compte des dépendances et des versions, définir ou mettre à jour la procédure d'exécution des tests d'intégration, système et d'acceptation client. Rédiger la procédure de déploiement. Ecrire et documenter les scripts de déploiement.",
                'knowledge' => [
                    "Connaissance des différents types d'environnement",
                    'Connaissance des différents types de mise en production (totale, partielle, progressive, ...)',
                    "Connaissance du rôle de l'infrastructure et des réseaux TCP-IP",
                ],
                'activities' => [
                    'Prendre en compte les dépendances du composant à déployer',
                    'Prendre en compte les évolutions de versions',
                    'Mettre à jour la procédure des tests',
                    'Rédiger une procédure de déploiement',
                    "Préparer des scripts d'évolution",
                ],
                'performanceCriteria' => [
                    'La procédure de déploiement est rédigée',
                    'Les scripts de déploiement sont écrits et documentés',
                ],
                'volumeHours' => '35.00',
                'teachingPeriod' => 'Mai-Juin',
                'teacher' => 'S. Tharaud',
            ],
            [
                'code' => 'C.11',
                'label' => 'Contribuer à la mise en production dans une démarche DevOps',
                'occupationDescription' => "Dans le cadre d'une démarche DevOps, utiliser un environnement collaboratif et des conteneurs afin d'automatiser l'intégration continue du code, ainsi que les tests d'intégration et système. Utiliser un outil pour vérifier la qualité du code. Automatiser les tests avec des logiciels d'automatisation de tests.",
                // Reproduced as printed: the source puts the "Savoir …" items under Connaissances
                // and the "Connaissance de …" items under Activités, which reads as a swap between
                // the two columns. Not corrected here - see the class docblock.
                'knowledge' => [
                    'Savoir utiliser un gestionnaire de conteneurs',
                    'Savoir gérer des stacks de conteneurs',
                    'Savoir utiliser les outils collaboratifs de développement logiciel et de versionning',
                    'Savoir coder et exécuter les tests en automatique',
                    "Savoir créer un script d'intégration continue (de type Yaml)",
                ],
                'activities' => [
                    'Connaissance des mécanismes de connectivité TCP-IP',
                    'Connaissance de la démarche DevOps',
                    'Connaissance des bases de Linux',
                ],
                'performanceCriteria' => [
                    'Les outils de qualité de code sont utilisés',
                    "Les outils d'automatisation de tests sont utilisés",
                    "Les scripts d'intégration continue s'exécutent sans erreur",
                    "Le serveur d'automatisation est paramétré pour les livrables et les tests",
                    "Les rapports de l'Intégration continue sont interprétés",
                ],
                'volumeHours' => '35.00',
                'teachingPeriod' => 'Mai-Juin',
                'teacher' => 'S. Tharaud',
            ],
        ];
    }

    /** @return list<SkillDefinition> */
    private function crossCutting(): array
    {
        $occupationDescription = "Afin de connaître les fonctionnalités et les besoins de sécurité de l'application, analyser le cahier des charges et solliciter si nécessaire des informations complémentaires auprès d'interlocuteurs divers. Être à l'écoute du client pour collecter ses besoins et les éléments du contexte permettant l'élaboration d'un projet correspondant à ses besoins. Présenter le projet au client, oralement en face-à-face ou à distance, ou par écrit, en adaptant son langage au client tout au long de l'interaction.";

        $knowledge = [
            'Expression écrite, compréhension écrite et compréhension orale : niveau B1.',
            'Expression orale : niveau A2.',
        ];

        $activities = [
            'Rédiger des dossiers techniques dans un langage adapté au destinataire',
            'Formuler ses courriels professionnels de manière claire et concise.',
            'Rechercher des informations dans des documents techniques',
            'Communiquer si besoin au sujet des contenus.',
        ];

        $performanceCriteria = [
            "Les fonctionnalités de l'application et ses besoins de sécurités sont connus",
            'Le projet est présenté au client, oralement ou par écrit',
            'La documentation technique est comprise',
            'La communication orale est claire, concise, structurée, et adaptée au destinataire et au contexte',
            'La communication écrite est claire, concise, structurée, et adaptée au destinataire et au contexte',
        ];

        return [
            [
                'code' => 'C.12',
                'label' => 'Communication en langue française',
                'occupationDescription' => $occupationDescription,
                'knowledge' => $knowledge,
                'activities' => $activities,
                'performanceCriteria' => $performanceCriteria,
                'volumeHours' => '45.00',
                'teachingPeriod' => 'Septembre-Juin',
                'teacher' => 'A. Sautour',
            ],
            [
                'code' => 'C.13',
                'label' => 'Communication en langue anglaise',
                'occupationDescription' => $occupationDescription,
                'knowledge' => $knowledge,
                'activities' => $activities,
                'performanceCriteria' => $performanceCriteria,
                'volumeHours' => '30.00',
                'teachingPeriod' => 'Septembre-Juin',
                'teacher' => 'P. Reynaud',
            ],
        ];
    }

    /** @return list<SkillDefinition> */
    private function aisCcp1(): array
    {
        return [
            [
                'code' => 'C.1',
                'label' => "Appliquer les bonnes pratiques dans l'administration des infrastructures",
                'occupationDescription' => "À partir du signalement de la dégradation d'un service issu d'un processus d'escalade ou de la supervision, identifier, classifier, et enregistrer un incident, le diagnostiquer et le résoudre afin de rétablir le service fourni au niveau attendu. À partir des informations d'exploitation ou des éléments fournis par la supervision, établir, planifier et réaliser les tâches préventives de type mise à jour, sauvegarde, vérification des dispositifs de reprise et de continuité informatique, remplacement ou paramétrage d'un élément de configuration, afin de maintenir le niveau de service de l'infrastructure du système d'information à la valeur attendue.",
                'knowledge' => [
                    'Connaissance des principes généraux des normes et bonnes pratiques de la gestion des services : type iso 20000, ITIL, ITSM',
                ],
                'activities' => [
                    'Utiliser un outil de gestion des actifs et des configurations de type GLPI',
                    "Exploiter les données d'un outil de gestion des incidents de type GLPI",
                    'Vérifier que la qualité de service mesurée correspond aux accords de niveaux de services (SLA)',
                    'Mettre en œuvre une démarche structurée de diagnostic',
                    "Établir une procédure de traitement d'incident ou d'exploitation",
                ],
                'performanceCriteria' => [
                    'Les problèmes et incidents sont résolus',
                    'Les taches planifiées pour le maintien en condition opérationnelle des infrastructures sont réalisées dans le respect des bonnes pratiques.',
                    'Le niveau de qualité des services est maintenu à la valeur attendue',
                ],
                'volumeHours' => '33.00',
                'teachingPeriod' => 'Septembre-Octobre',
                'teacher' => 'O. Thiriau',
            ],
            [
                'code' => 'C.2',
                'label' => 'Administrer et sécuriser les infrastructures réseaux',
                'occupationDescription' => "À partir des demandes d'interventions et des informations d'exploitation ou de supervision, administrer et sécuriser, en appliquant les bonnes pratiques, les éléments des infrastructures réseaux afin de les maintenir en condition opérationnelle dans le respect des accords de niveau de service, des règles de sécurité et de la réglementation en vigueur.",
                'knowledge' => [
                    'Connaissance des objectifs et des usages des PRA, PCA, PRI, PCI.',
                    'Connaissance des caractéristiques et limites techniques des équipements réseaux.',
                    'Connaissance des principales technologies et des normes utilisées dans les réseaux convergents (voix, données, images)',
                    'Connaissance des risques et principales menaces sur les infrastructures réseau, et des moyens de protection associés',
                ],
                'activities' => [
                    'Administrer et sécuriser des commutateurs de niveau 2 et 3 et des routeurs en mettant en œuvre les technologies de type vlan, redondance et agrégat de liens, sécurité des accès, routage statique et dynamique, monitoring.',
                    'Administrer et sécuriser les réseaux sans fil.',
                    'Administrer les dispositifs de sécurisation des accès réseaux de type pare feu, proxy, portail captif, bastion.',
                    "Administrer et sécuriser des solutions de prévention et détection d'intrusion (IPS, IDS)",
                    'Administrer les dispositifs réseaux en haute disponibilité utilisant des technologies de type HSRP, STP, agrégat de lien.',
                    'Administrer et sécuriser les accès distants des utilisateurs nomades et les connexions inter sites de type VPN',
                ],
                'performanceCriteria' => [
                    "L'infrastructure réseau est opérationnelle conformément aux accords de niveau de service.",
                    'Les tâches sont réalisées dans le respect des bonnes pratiques.',
                    'Les règles de sécurité sont appliquées.',
                    'La règlementation en vigueur est respectée.',
                ],
                'volumeHours' => '42.00',
                'teachingPeriod' => 'Septembre-Octobre',
                'teacher' => 'F. Sautour',
            ],
            [
                'code' => 'C.3',
                'label' => 'Administrer et sécuriser les infrastructures systèmes',
                'occupationDescription' => "À partir des demandes d'interventions et des informations d'exploitation ou de supervision, administrer et sécuriser, en appliquant les bonnes pratiques, les infrastructures systèmes afin de les maintenir en condition opérationnelle dans le respect des accords de niveau de service, des règles de sécurité et de la réglementation en vigueur.",
                'knowledge' => [
                    'Connaissance des spécificités de chaque environnement système',
                    'Connaissance des dispositifs relatifs aux accès sécurisés',
                    "Connaissance des principes d'une infrastructure à clés publiques (PKI)",
                    'Connaissance des règles de gestion relatives aux licences systèmes et logicielles',
                ],
                'activities' => [
                    "Système d'exploitation serveur (Windows, Linux, Unix)",
                    'Services réseaux type DNS, DHCP, certificats',
                    'Services de type bureau à distance de Microsoft',
                    'Annuaire de réseau de type LDAP, Active Directory (AD)',
                    'Protocoles SSH, SFTP, TLS, SMB chiffré',
                    'Authentification forte de type Multifactor Authentication (MFA), One Time Password (OTP)',
                    "Tâches d'administration par script basé sur un langage type Python, PowerShell, Bash.",
                    'Solution de gestion des mises à jour systèmes',
                    'Solution de sauvegarde VEEAM.',
                ],
                'performanceCriteria' => [
                    "L'infrastructure système est opérationnelle conformément aux accords de niveau de service.",
                    'Les tâches sont réalisées dans le respect des bonnes pratiques.',
                    'Les règles de sécurité sont appliquées',
                    'La règlementation en vigueur est respectée',
                ],
                'volumeHours' => '64.00',
                'teachingPeriod' => 'Septembre-Octobre + Janvier-Février',
                'teacher' => 'A. Théron',
            ],
            [
                'code' => 'C.4',
                'label' => 'Administrer et sécuriser les infrastructures virtualisées',
                'occupationDescription' => "À partir des demandes de services et des informations d'exploitation ou de supervision, administrer et sécuriser, en appliquant les bonnes pratiques, les éléments de l'infrastructure virtualisée (On-premise et cloud) afin de les maintenir en condition opérationnelle dans le respect des accords de niveau de service, des règles de sécurité et de la réglementation en vigueur.",
                'knowledge' => [
                    'Comprendre les différents types de cloud public, privé, hybride et multicloud',
                    'Comprendre les modèles de service Cloud IaaS, PaaS et SaaS',
                    'Connaissances des principes, des enjeux et des risques du cloud-computing',
                    "Connaissance des principales solutions de gestion d'environnements virtualisés",
                    'Connaissance des fonctions avancées de la gestion des environnements virtualisés (clustering, stockage, migration)',
                    'Connaissance des équipements matériels du cluster (serveurs, baies de stockage, switch)',
                ],
                'activities' => [
                    'Administrer la haute disponibilité et la répartition de charge au niveau des hyperviseurs.',
                    'Administrer et sécuriser les dispositifs de stockage type SAN, VSAN, NAS, DAS.',
                    'Administrer et sécuriser les réseaux virtuels dans des infrastructures virtualisées',
                    'Configurer, administrer et sécuriser les sauvegardes et la restauration avec VEEAM Backup',
                    'Implémenter, administrer et sécuriser des conteneurs.',
                ],
                'performanceCriteria' => [
                    "L'infrastructure virtualisée est opérationnelle conformément aux accords de niveaux de service.",
                    'Les tâches sont réalisées dans le respect des bonnes pratiques.',
                    'Les règles de sécurité sont appliquées',
                    'La règlementation en vigueur est respectée',
                ],
                'volumeHours' => '34.00',
                'teachingPeriod' => 'Septembre-Octobre',
                'teacher' => 'O. Thiriau',
            ],
        ];
    }

    /** @return list<SkillDefinition> */
    private function aisCcp2(): array
    {
        return [
            [
                'code' => 'C.5',
                'label' => "Concevoir une solution technique répondant à des besoins d'évolution de l'infrastructure",
                'occupationDescription' => "À partir des besoins et contraintes définis dans le cahier des charges fourni dans le cadre d'un projet d'évolution de l'infrastructure, concevoir et proposer dans les délais impartis une solution technique évolutive qui tient compte des contraintes budgétaires, environnementales, de production et de sécurité ainsi que des réglementations en vigueur.",
                'knowledge' => [
                    "Connaissance des solutions techniques de sécurisation d'une infrastructure informatique.",
                    "Connaissance des pratiques ou processus du développement, de la construction, de la transition et de l'assurance qualité des services type iso 20000 ou ITIL",
                    'Connaissance de méthodes de gestion de projet de type classique ou agile.',
                ],
                'activities' => [
                    'Repérer, tester et évaluer préalablement une solution technique.',
                    'Définir les critères à retenir pour évaluer une solution.',
                    'Mettre en œuvre un environnement de test ou de simulation',
                    "Évaluer l'impact d'une solution technique sur le système d'information.",
                    'Définir, planifier et ordonnancer les tâches du projet.',
                ],
                'performanceCriteria' => [
                    'Les contraintes budgétaires, environnementales, de production et de sécurité sont prises en compte',
                    'La réglementation en vigueur est respectée.',
                    'La solution proposée est évolutive.',
                    'La solution proposée est conforme au cahier des charges et respecte les délais.',
                ],
                'volumeHours' => '43.00',
                'teachingPeriod' => 'Janvier-Février',
                'teacher' => 'O. Thiriau',
            ],
            [
                'code' => 'C.6',
                'label' => "Mettre en production des évolutions de l'infrastructure",
                'occupationDescription' => "À partir d'une solution, élaborée et testée en amont et répondant à une demande de changement, planifier, réaliser et valider son intégration en appliquant les bonnes pratiques afin qu'elle soit mise en production dans le respect des accords de niveau de service, des règles de sécurité et de la réglementation en vigueur.",
                'knowledge' => [
                    'Connaissance des pratiques ou processus de gestion du déploiement, des mises en production, de la disponibilité, de la continuité et de la capacité de type ITIL et iso 20000.',
                    'Connaissance de méthodes de gestion de projet de type classique ou agile.',
                ],
                'activities' => [
                    'Élaborer les procédures de test et de validation.',
                    'Minimiser l\'impact sur la disponibilité du SI lors la planification et de de la mise en production.',
                    "Participer à la définition et la planification des tâches d'un projet.",
                    'Utiliser un outil de gestion de projets',
                    "Créer ou mettre à jour les informations et procédures d'exploitation.",
                ],
                'performanceCriteria' => [
                    'Respect des bonnes pratiques et prend en compte les contraintes de production et de sécurité.',
                    'Chaque étape de la mise en production est évaluée et validée.',
                    'Les procédures des PRI et PCI associés aux dispositifs mis en production sont testés et validés.',
                    'La solution est mise en production conformément aux accords de niveau de service',
                ],
                'volumeHours' => '23.00',
                'teachingPeriod' => 'Janvier-Février',
                'teacher' => 'O. Thiriau',
            ],
            [
                'code' => 'C.7',
                'label' => 'Mettre en oeuvre et optimiser la supervision des infrastructures',
                'aliases' => ['Mettre en œuvre et optimiser la supervision des infrastructures'],
                'occupationDescription' => "A partir des accords de niveaux de service et des besoins de contrôle des infrastructures, choisir les indicateurs et évènements associés à la disponibilité, aux performances, à la consommation de services qui doivent être supervisés. Mettre en œuvre ou optimiser les outils de supervision nécessaires au suivi des indicateurs et des évènements, en respect de la réglementation et des règles de sécurité, afin de mettre à disposition des équipes d'exploitation et d'administration les tableaux de bords et les informations indispensables au support et au pilotage des infrastructures du système d'information.",
                'knowledge' => [
                    "Connaissance des solutions de centralisation et d'analyse des journaux d'événements d'une infrastructure distribuée",
                    'Connaissance de la gestion des niveaux de services',
                    'Connaissance du protocole SNMP',
                    'Connaissance du protocole Syslog',
                ],
                'activities' => [
                    "Définir les éléments de l'infrastructure qui doivent être suivis.",
                    "Définir les seuils d'alerte et les indicateurs principaux et les configurer.",
                    'Mettre en œuvre et exploiter une solution de supervision dans une infrastructure distribuée',
                    "Mettre en œuvre une solution de centralisation et d'analyse des journaux d'événements.",
                    'Élaborer des tableaux de bord de suivi de production informatique',
                ],
                'performanceCriteria' => [
                    'Les indicateurs et les évènements choisis sont pertinents.',
                    'Les outils de supervisons sont fonctionnels et respectent la réglementation et les règles de sécurité.',
                    'Les tableaux de bords et les informations présentés sont structurés et exploitables.',
                ],
                'volumeHours' => '30.00',
                'teachingPeriod' => 'Janvier-Février',
                'teacher' => 'A. Théron',
            ],
        ];
    }

    /** @return list<SkillDefinition> */
    private function aisCcp3(): array
    {
        return [
            [
                'code' => 'C.8',
                'label' => "Participer à la mesure et à l'analyse du niveau de sécurité de l'infrastructure",
                'occupationDescription' => "A partir d'une demande d'évaluation de la sécurité sur un périmètre défini de l'infrastructure, planifier et spécifier les points de contrôle et effectuer les mesures afin d'évaluer le niveau de sécurité dans le respect de la réglementation.",
                'knowledge' => [
                    'Connaissance des risques informatiques encourus et leurs causes',
                    'Connaissance de base sur les organismes et la réglementation relatifs à la protection des données en France et en Europe (CNIL, RGPD)',
                    "Connaissance des organismes de lutte et d'information contre les risques Cyber ANSSI, CESIN, CLUSIF, MITRE, NIST, CIS...",
                    "Connaissance des principes d'une méthode de gestion des risques comme ISO 27005, EBIOS, MEHARI.",
                    "Connaissance des principes d'un SOC (Security Operations Center).",
                ],
                'activities' => [
                    'Participer à une analyse des risques avec une méthode ou un guide de type EBIOS, ISO 27005',
                    'Identifier les différents types de menaces redoutées.',
                    "Évaluer la criticité d'une vulnérabilité",
                    'Réaliser un audit de sécurité interne.',
                    'Utiliser des outils de détection de vulnérabilité.',
                ],
                'performanceCriteria' => [
                    'Les points de contrôle sont pertinents',
                    'Les vulnérabilités des composants sont identifiées',
                    'Les risques et leurs menaces associées sont caractérisés.',
                    'Le rapport de contrôle est clair et exploitable.',
                ],
                'volumeHours' => '42.00',
                'teachingPeriod' => 'Mai-Juin',
                'teacher' => 'F. Sautour',
            ],
            [
                'code' => 'C.9',
                'label' => "Participer à l'élaboration et à la mise en oeuvre de la politique de sécurité",
                'aliases' => ["Participer à l'élaboration et à la mise en œuvre de la politique de sécurité"],
                'occupationDescription' => "A partir des règles de sécurité retenues dans la politique de sécurité du système d'information (PSSI), contribuer, dans son périmètre d'intervention, au choix, à l'implantation et l'évaluation des solutions permettant leur mise en oeuvre. Participer à la définition, rédaction et la validation de procédures permettant la déclinaison opérationnelle de la PSSI.",
                'knowledge' => [
                    'Connaissance des menaces et vulnérabilité.',
                    'Connaissance des solutions de sécurisation type IPS/IDS, EDR, XDR, SIEM, SOAR',
                    'Connaissance des offres des prestataires spécialisés en cybersécurité',
                    "Connaissance de la structure de la PSSI et de sa méthodologie d'élaboration.",
                ],
                'activities' => [
                    "Appliquer les recommandations de l'ANSSI.",
                    'Prendre en compte la Règlement général sur la protection des données (RGPD).',
                    'Sécuriser et gérer les accès avec des méthodes et outils de type Pare-feu, Bastion, MFA, méthode Zéro trust',
                    'Durcissement des systèmes Microsoft, Linux.',
                    "Identifier et proposer des systèmes de détection de menace type EDR, IPS/IDS, XDR, SIEM adaptés à l'entreprise.",
                ],
                'performanceCriteria' => [
                    'La solution choisie répond aux règles de sécurité retenues par la PSSI.',
                    'La solution choisie est mise en œuvre et évaluées',
                    'Les procédures sont rédigées dans le respect des bonnes pratiques et validées',
                ],
                'volumeHours' => '36.00',
                'teachingPeriod' => 'Mai-Juin',
                'teacher' => 'F. Sautour',
            ],
            [
                'code' => 'C.10',
                'label' => 'Participer à la détection et au traitement des incidents de sécurité',
                'occupationDescription' => "En s'appuyant sur les documents d'exploitation, configurer et exploiter un dispositif de détection d'évènements de sécurité afin de détecter et qualifier un incident. Appliquer les mesures de réaction en réponse à un incident afin de minimiser l'impact sur les actifs de l'entreprise et d'informer les parties concernées",
                'knowledge' => [
                    'Connaissance des menaces et vulnérabilité.',
                    "Connaissance des règles d'organisation d'un RETEX",
                    'Connaissance des solutions de sécurisation type IPS/IDS, EDR, MDR, XDR, SIEM, SOAR, UEBA',
                    "Connaissance de l'organisation et des rôles au sein d'un SOC.",
                ],
                'activities' => [
                    'Configurer et exploiter un système de détection ou réponse à incident de sécurité (SIEM, SOAR, XDR)',
                    'Adapter les règles de détection des vulnérabilités aux différents environnements.',
                    'Qualifier un incident de sécurité.',
                    'Appliquer les mesures de réaction en réponse à un incident de sécurité',
                    'Réaliser la veille sur les menaces et les dispositifs de protection.',
                ],
                'performanceCriteria' => [
                    'Le système est configuré afin de détecter les incidents de sécurité.',
                    'Les incidents sont identifiés et qualifiés.',
                    'Les mesures de réponse à incident sont appliquées.',
                    "Les règles de détection et le traitement des incidents sont adaptés à l'évolution des menaces.",
                    'Les moyens mis en place pour assurer sa veille en matière de cybersécurité sont pertinents.',
                ],
                'volumeHours' => '33.00',
                'teachingPeriod' => 'Mai-Juin',
                'teacher' => 'F. Sautour',
            ],
        ];
    }
}
