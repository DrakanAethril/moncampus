<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802200000 extends AbstractMigration
{
    /**
     * Mêmes contenus que Version20260802180000, réappariés autrement.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const ENTRIES = [
        ['A la découverte du cloud', 'Aspects Financiers des Cloud', '<p>Présentation des différences financières sur le Cloud et les modèle économiques comparé à une infrastructure classique.<br>Notion de Capex et d’Opex<br>Notion de TCO</p>'],
        ['A la découverte du cloud', 'Evaluation sommative', '<p>Evaluation des modules 1 à 3 de la certification AWS</p>'],
        ['A la découverte du cloud', 'Les principaux Cloud et les concepts clés', '<p>Présentation des objectifs de la séquence d’introduction au cloud, des acteurs du cloud et inscription à la certification AWS.<br>Présentation des différences entre infrastructure on premise et infrastructure cloud.</p>'],
        ['A la découverte du cloud', 'L’importance de la localisation des données', '<p>Comprendre les structures physiques des cloud et les enjeux liés.<br>Notions de latence, de souveraineté et de droit.<br>Notions de régions, zone de disponibilité et points de présence.</p>'],
        ['A la découverte du cloud', 'Révision certification AWS - Modules 1 à 3', '<p>Séance de révision sur les modules 1 à 3 avec fourniture de supports dédiés et différenciés.</p>'],
        ['A la découverte du mobile - Flutter', 'Découverte du framework Flutter', '<p>Installation et première utilisation du Framework Flutter pour le développement mobile.<br>Mise en place de la première application et modifications de cette dernière pour comprendre l’environnement de développement</p>'],
        ['A la découverte du mobile - Flutter', 'Les principaux widget Flutter d’interaction', '<p>Mise en place d’un appel distant et du traitement du retour avec le framework Flutter. Intégration de l’authentification avec le token JWT vu dans la séquence Api-Platform</p>'],
        ['A la découverte du mobile - Flutter', 'Les principaux widgets Flutter de structuration et décoration', '<p>Première utilisation des widget dans Flutter sur la partie structuration et décoration. Gestion des paramètres et des gestures sur les listes.</p>'],
        ['AWS - leader mondial du cloud', 'AWS Architecture (M9) - ANNULE (BTS Blanc du 21/04)', '<p>Présentation et construction d’une infrastructure sécurisée sur AWS.<br>Evolution du schéma du premier semestre pour l’intégrer dans des concepts et services Cloud.</p>'],
        ['AWS - leader mondial du cloud', 'Découverte d’AWS IAM (M4)', '<p>Présentation du service IAM sur AWS. Les points clés à retenir sont :</p><ul><li>Les utilisateurs, groupes et roles</li><li>Les permissions et ressources</li><li>Les policies</li><li>Le principe du Deny by default</li></ul>'],
        ['AWS - leader mondial du cloud', 'Découverte d’AWS VPC (M5)', '<p>Présentation du service de VPC sur AWS. Les points clés à retenir sont :</p><ul><li>Les sous réseaux</li><li>Les groupes de sécurité</li><li>Les passerelles internet ou NAT</li><li>Les endpoints spécifiques AWS (comme S3)</li></ul>'],
        ['AWS - leader mondial du cloud', 'Evaluation modules 1 à 5', '<p>Evaluation sur les modules 1 à 5 de la certification AWS</p>'],
        ['AWS - leader mondial du cloud', 'Evaluation modules 1 à 7', '<p>Evaluation sur les modules 1 à 7 de la certification AWS</p>'],
        ['AWS - leader mondial du cloud', 'Evaluation sommative', '<p>Evaluation la certification AWS</p>'],
        ['AWS - leader mondial du cloud', 'Les principes de l’auto-scaling et de la haute disponibilité (M10) - Décalé cause CC', '<p>Présentation des notions d’auto-scaling et de haute disponibilité.<br>Exemple d’entreprise sur la gestion de la charge variable et non prévisible sur Iconosquare.</p>'],
        ['AWS - leader mondial du cloud', 'Les solutions de calcul - avec ou sans serveurs - EC2 et Lambda (M6)', '<p>Présentation des services EC2 et Lambda.<br>Découverte des notions de serverless et des fonctions planifiées.<br>Rappel sur les coûts d’EC2 et les engagement possibles.</p>'],
        ['AWS - leader mondial du cloud', 'Les solutions de stockage. A la découverte de S3. (M7)', '<p>Rappels de cybersécurité sur les archivages et la sauvegarde.<br>Présentation du service S3 et de ses options de stockage et de tarification</p>'],
        ['AWS - leader mondial du cloud', 'Remédiation et préparation à l’évaluation sommative', '<p>Révisions en vue de l’évaluation sommative de la semaine prochaine.</p>'],
        ['AWS - leader mondial du cloud', 'SQL vs NoSQL - Les bases de données sur AWS. (M8) - Reportée du 21/04 au 28/04 - BTS blanc', '<p>Comment choisir sa B.D.D. en fonction de son projet entre SQL et NoSQL ?<br>Présentation des services RDS et DynamoDB.<br>Découverte des notions de bases NoSQL.</p>'],
        ['En route vers l’E7', 'Gérer les accès', '<p>Rappels sur les bonnes pratiques de gestion des accès PolP, Deny by default.<br>Rappels sur les notions de groupes et rôles, de durée de vie<br>Rappels sur RBAC/ABAC</p>'],
        ['En route vers l’E7', 'La gestion des sauvegardes', '<p>Rappels sur les bonnes pratiques en terme de gestion des sauvegardes.<br>Différence entre sauvegarde et réplication, notion de WORM</p>'],
        ['En route vers l’E7', 'Le contrôle d’intégrité', '<p>Rappels sur les bonnes pratiques en terme de controle d’intégrité.<br>Rappels sur le hachage, la signature asymétrique et les PKI.</p>'],
        ['En route vers l’E7', 'Les environnements logiciels', '<p>Rappels sur les différents type d’environnement et les contraintes associés<br>Rappels sur les différentes méthodes de deploiement avec un focus sur Blue/Green et Canary</p>'],
        ['En route vers l’E7', 'L’authentification', '<p>Rappels sur les bonnes pratiques d’authentification et les méthodes à proscrire.<br>Rappels sur Fido2 et passwordless.<br>Rappels sur les algorithmes de hachage à éviter.</p>'],
        ['En route vers l’E7', 'OSWAP', '<p>Rappels sur ce qu’est l’OSWAP et sur la version 2025.<br>Rappels sur les principales failles web (IDOR, CSRF, XSS, MITM, SQLi).</p>'],
        ['En route vers l’E7', 'RGPD', '<p>Rappels sur les obligations liées au RGPD pour les développeurs.<br>Notion de DICP, AIDP, de contenus obligatoires et de droit à l’accès aux données.</p>'],
        ['Framework Web - Symfony - API et interopérabilité', 'Découverte des API REST et GraphQL à l’aide d’API-Platform', '<p>Introduction aux API et aux échanges machine-machine. Notions d’API REST et GraphQL, de swagger, d’outil de test comme Postman et du bundle API-Platform permettant également une documentation.</p><p>Ne pas oublier de compléter sa documentation B2 au propre.</p>'],
        ['Framework Web - Symfony - API et interopérabilité', 'Evaluation sommative (BTS blanc)', '<p>Evaluation type sujet E7 de BTS</p>'],
        ['Framework Web - Symfony - API et interopérabilité', 'Filtrer les données via une API', '<p>Introduction aux stateProvider et remédiation sur les authentification par token JWT. Exemple en filtrant les données reçues de l’API en fonction de l’utilisateur connecté via un token JWT</p>'],
        ['Framework Web - Symfony - API et interopérabilité', 'L’authentification par API : le JWT Token', '<p>Introduction aux tokens JWT dans le cadre des projets web pour anticiper leur utilisation dans le cadre des applications mobiles.<br>Notions de sécurité du token, définition de la sécurité des cookies concernés<br>Mise en place sur les projets en cours.</p>'],
        ['Framework Web - Symfony - Initiation', 'Design pattern MVC et le routage dans Symfony', '<p>Présentation du design pattern MVC, du cycle de vie et du routage d’une requête dans le framework Symfony.<br>Mise en place de la gestion des utilisateurs dans le projet en cours.</p>'],
        ['Framework Web - Symfony - Initiation', 'Evaluation formative et remédiation', '<p>Evaluation formative puis réutilisation de la documentation pour récréer un projet Symfony.</p>'],
        ['Framework Web - Symfony - Initiation', 'Evaluation sommative (type BTS)', '<p>Evaluation type sujet E7 de BTS</p>'],
        ['Framework Web - Symfony - Initiation', 'La gestion des formulaires dans Symfony', '<p>Présentation de la gestion des fomulaires dans Symfony du controller jusqu’à la soumission et l’enregistrement de l’entité. Notions de contraintes et de validateurs, intégration avec Twig.</p>'],
        ['Framework Web - Symfony - Initiation', 'Les ORM et Doctrine dans Symfony. Twig et les moteurs de vue', '<p>Découverte des ORM et des moteurs de vue, Doctrine et Twig. Notions de migrations et d’architecture logicielle.<br>Mise en place de pages publiques avec intégration du menu et des assets.</p>'],
        ['Framework Web - Symfony - Initiation', 'Présentation du Framework Symfony et installation du projet via Docker', '<p>Introduction aux framework web avec la mise en place d’un projet Symfony et explications des composants associés (Docker, Github, PostGreSQL). Familiarisation avec la documentation de Symfony et le projet de démo (The fast track).</p><p>Commencer sa documentation B2.</p>'],
        ['Le côté client - Décorer avec CSS', 'A la découverte du CSS', '<p>Présentation de l’objectif de la séquence à savoir la découverte du langage de décoration des pages web CSS. Voir le code de son langage en action et en déduire des informations concernant le fonctionnement du langage.</p>'],
        ['Le côté client - Décorer avec CSS', 'CSS avancé - le responsive design avec Flexbox', '<p>Découverte du responsive design avec les propriétés CSS de Flexbox.</p>'],
        ['Le côté client - Décorer avec CSS', 'Evaluation sommative', '<p>Evaluation sur le langage CSS et quelques rappels du langage HTML</p>'],
        ['Le côté client - Décorer avec CSS', 'Le CSS en pratique', '<p>Création de ses premiers fichiers CSS et utilisation des première propriétés en utilisant le langage CSS et un éditeur de texte. Affichage dans le navigateur du poste.</p>'],
        ['Le côté client - Décorer avec CSS', 'Notions théorique de CSS', '<p>Approche théorique des découvertes réalisées lors de la séance pratique précédente. Notions de sélecteurs, de structure, de propriétés.</p>'],
        ['Le côté client - Décorer avec CSS', 'Remédiation', '<p>Séance de révision et de pratique en vue de l’évaluation sommative sur papier et de la réalisation du CV au format HTML/CSS sur les vacances.</p>'],
        ['Le côté client - Structurer avec HTML', 'Evaluation sommative', '<p>Evaluation sur le langage HTML, la communication avec une machine et les échanges web.</p>'],
        ['Le côté client - Structurer avec HTML', 'La structure du langage HTML', '<p>Approche théorique des découvertes réalisées lors de la séance pratique précédente. Notions de balises, de structure minimum d’une page web</p>'],
        ['Le côté client - Structurer avec HTML', 'Le langage HTML en action', '<p>Présentation de l’objectif de la séquence à savoir la découverte du langage de structuration des pages web HTML. Voir le code de son langage en action et en déduire des informations concernant le fonctionnement du langage.</p>'],
        ['Le côté client - Structurer avec HTML', 'Permettre à un utilisateur de saisir des données : les formulaires', '<p>Création de son premier formulaire en HTML. Utilisation de différents types de champ et des méthodes d’envoi. Remédiation si nécessaire sur le langage HTML en vue de l’évaluation.</p>'],
        ['Le côté client - Structurer avec HTML', 'Pratique du langage : Images et Tableaux', '<p>Création de ses premières pages structurées à partir du langage HTML en utilisant les balises les plus fréquentes et un éditeur de texte. Affichage dans le navigateur du poste.</p>'],
        ['Le côté client - Structurer avec HTML', 'Remédiation', '<p>Présentation de la gestion des formulaire en HTML et des problématiques de cybersécurité associées.<br>Révisions en vue de l’évaluation sommative.</p>'],
        ['Le côté serveur - Initiation au PHP', 'Correction évaluation sommative', '<p>Correction du BTS blanc</p>'],
        ['Le côté serveur - Initiation au PHP', 'Découverte des tableaux en PHP', '<p>Rappels sur les variables de type tableaux (ou listes en algo) et les boucles pour les parcourir.<br>Implémentation en PHP</p>'],
        ['Le côté serveur - Initiation au PHP', 'Découverte du langage PHP', '<ul><li>Lancement de la nouvelle séquence dédiée au langage interprété PHP (acronyme récursif pour *PHP Hypertext Preprocessor*)</li><li>Découverte et positionnement sur le schéma des échanges web d’un langage côté serveur</li><li>Présentation de la structure du langage et de quelques types de variable.</li></ul>'],
        ['Le côté serveur - Initiation au PHP', 'Evaluation sommative - BTS Blanc', '<p>Evaluation sur les séquences depuis le début de l’année dans le cadre d’un BTS blanc</p>'],
        ['Le côté serveur - Initiation au PHP', 'Les conditions et boucles en PHP', '<p>Rappels sur les conditions et les boucles dans la programmation.<br>Implémentation en PHP</p>'],
        ['Le côté serveur - Initiation au PHP', 'Les fonctions en PHP', '<p>Présentation des concepts et intérêts de l’utilisation des fonctions en programmation. Notions de paramètres et de retours.<br>Implémentation en PHP</p>'],
        ['Le côté serveur - Initiation au PHP', 'Les tableaux avancés en PHP', '<p>Atelier pratique sur les tableaux associatif et l’utilisation combinée des tableaux, des boucles et des conditions.</p>'],
        ['Le côté serveur - Initiation au PHP', 'Remédiation - Prise en main PHP', '<p>Début de la programmation en PHP avec la première implémentation des conditions et des boucles.</p>'],
        ['Le côté serveur - PHP avancé', 'Connecter PHP à une BDD', '<p>Découverte de l’interaction entre PHP et MySQL dans le cadre d’une stack sous Docker.</p>'],
        ['Le côté serveur - PHP avancé', 'Evaluation sommative', '<p>Evaluation sur les composants avancés du langage PHP</p>'],
        ['Le côté serveur - PHP avancé', 'Héberger son code PHP', '<p>Présentation de l’objectif de la séquence à savoir l’interaction entre PHP et les outils ou composants utilisés dans le domaine du web.<br>Installation de l’environnement de développement local avec Docker</p>'],
        ['Le côté serveur - PHP avancé', 'Installation d’un service MySQL conteneurisé', '<p>Ajouter un service MySQL dans sa stack Docker. Aide pour ceux qui n’ont pas réussi la séance pratique précédente.</p>'],
        ['Le côté serveur - PHP avancé', 'PHP pour les pages web', '<p>Découverte de Github, création de son compte et début de ses envois sur la plateforme.</p>'],
        ['Le côté serveur - PHP avancé', 'Remédiation', '<p>Séance de remédiation pratique sur les points vus dans la séquence : Docker, Github, MySQL, PHP.<br>Pour les plus rapides, ajout d’un outil de gestion de la B.D.D. (PhpMyAdmin, PgAdmin ou Adminer suivant les B.D.D.)</p>'],
        ['Les bases du Web', 'Anatomie d’un appel d’URL', '<p>Découverte par l’observation puis approche théorique des échanges qui entrent en jeu lors de l’appel d’une page Web.<br>Découverte du DNS, des notions de côtés client et serveur et début de réflexion sur les impacts en terme de sécurité de la donnée.<br>Début de prise en main des outils pour développeur dans un navigateur, découverte du langage HTML et CSS qui seront les sujets des prochaines séquences.</p>'],
        ['Les bases du Web', 'Communiquer avec un ordinateur', '<p>Point sur l\'intégration et questions diverses concernant la vie de l\'établissement.</p><p>Introduction aux fondements de l\'informatique et des échanges homme-machine. Aperçu des futures séances pour offrir une vision claire sur :</p><ul><li>le système binaire</li><li>la logique informatique (penser comme une machine)</li><li>Concepts essentiels à maîtriser : binaire, jeux de caractères (charset), algorithmes et langages de programmation</li></ul>'],
        ['Principales failles web', 'Evaluation diagnostique', '<p>Evaluation diagnostique sur les acquis de première année</p>'],
        ['Principales failles web', 'Intervenant extérieur - Cyber@Hack', '<p>Intervenant extérieur spécialisé en cybersécurité et présentation du jeu Cyber@Hack.</p>'],
        ['Principales failles web', 'Le Man In The Middle (MITM)', '<p>Présentation des failles de type MITM.</p><p>Savoir les détecter mais aussi savoir s’en prémunir.</p><p>Révisions des principes de chiffrement asymétriques pour comprendre la faille.</p>'],
        ['Principales failles web', 'Les attaques de type IDOR', '<p>Présentation des failles de type IDOR. Savoir les reconnaitre et s’en protéger.<br>La protection (ou non) des failles IDOR via les Framework.</p>'],
        ['Principales failles web', 'Les attaques par CSRF', '<p>Présentation des attaques de type CSRF.<br>Savoir les détecter mais aussi savoir s’en prémunir.<br>Faire le lien avec la configuration de Symfony vue en B2.</p>'],
        ['Principales failles web', 'Les injections SQL', '<p>Présentation des attaques de type SQLi. Savoir les détecter et s’en prémunir avec des requêtes préparées.<br>Avantages des ORM vus en B2 sur ce type de failles</p>'],
        ['Principales failles web', 'Les vulnérabilités XSS', '<p>Présentation des attaques de type XSS.<br>Savoir les détecter mais aussi savoir s’en prémunir.<br>Focus sur les 2 premiers types (réfléchies et stockées)<br>Lien avec les Forms sur Symfony</p>'],
        ['Qualité de code - Les tests fonctionnels', 'Correction sujet BTS Blanc', '<p>Correction du sujet type BTS</p>'],
        ['Qualité de code - Les tests fonctionnels', 'Evaluation sommative - Sujet Type BTS', '<p>Evaluation type BTS</p>'],
        ['Qualité de code - Les tests fonctionnels', 'Framework de tests PHP : Codeception', '<p>Présentation du framework de tests logiciels nommé Codeception.</p><p>Projection sur son utilisation dans le cadre des SP.</p>'],
        ['Qualité de code - Les tests fonctionnels', 'Les tests dans une chaîne de CI/CD', '<p>Présentation du principe des chaînes de CI/CD.</p><p>Mise en avant de l’intégration des tests logiciels dans ces chaînes.</p>'],
        ['Qualité de code - Les tests fonctionnels', 'Présentation des tests logiciels', '<p>Introduction au principe des tests fonctionnels. Présentation des différents types de tests avec leurs avantages et leurs inconvénients. Rédaction d’une suite de test. Introduction du TDD.</p>'],
        ['Qualité d’un projet web', 'Auditer le code - SonarQube', '<p>Présentation et démonstration de l’outil SonarQube pour les projets de développement logiciel. Utilisation sur des repository Github via sonarcloud.io</p>'],
        ['Qualité d’un projet web', 'Coding standards - les Linter', '<p>Présentation des Linter avec pour exemple PHPLint. Intérêt, installation, configuration et utilisation au sein d’un projet comme le projet Symfony</p>'],
        ['Qualité d’un projet web', 'Evaluation sommative', '<p>Evaluation sommative sur les acquis de la séquence</p>'],
        ['Qualité d’un projet web', 'La documentation d’un projet', '<p>Présentation des grandes parties des documentations techniques en partant de solutions existentes. L’utilisation d’outil de génération versus la difficulté de maintenir une documentation à jour.</p>'],
        ['Qualité d’un projet web', 'Le recettage et les équipes Q&A', '<p>Le recettage et les équipes Q&amp;A. Composition, role et outils de travail. Impact sur la vie du projet et sur les besoins en termes de documentation.</p>'],
        ['Qualité d’un projet web', 'PCA / PRA', '<p>Présentation des notions de PCA/PRA et de RTO/RPO. Révisions sur les notions de haute disponibilité et de scalabilité, de sauvegarde et de l’importance des tests de reprise.</p>'],
        ['Qualité d’un projet web', 'Penser les accès - Principe du moindre privilège', '<p>Principes de sécurité des accès et du moindre privilège. Notion de deny by default, PoLP et sa mise en place dans les applications logicielles.</p>'],
        ['Techno Web - La partie publique', 'Evaluation sommative', '<p>Evaluation sommative de la séquence sur machine d’après la grille d’évaluation co-construite à la séance précédente.</p>'],
        ['Techno Web - La partie publique', 'Home - La page d’accueil', '<p>Création de la page d’accueil publique su site.<br>Réutilisation des concepts d’architecture logicielle, de liste d’éléments, d’ergonomie.</p>'],
        ['Techno Web - La partie publique', 'La page de détail', '<p>Développement d’une page publique de détail d’une entité.<br>Notions d’architecture logicielle, passage de paramètre, failles de type IDOR.</p>'],
        ['Techno Web - La partie publique', 'Remédiation & co-construction de la grille d’évaluation', '<p>Co-Création de la grille d’évaluation en vue de l’évaluation sommative de la séance suivante.</p>'],
        ['Techno Web - L’interface d’administration', 'CR[U]D - Adapter la création pour gérer l’update', '<p>Création d’une fonctionnalité d’édition d’entité sans duplication du code de création.<br>Notions d’architecture logicielle et de factorisation de code</p>'],
        ['Techno Web - L’interface d’administration', 'C[R]U[D] - Gérer l’affichage et la suppression des données', '<p>Ajout d’une liste des entités crées sur la page d’accueil de l’interface d’administration.<br>Ajout de la possibilité de supprimer une entité.<br>Gestion du passage des paramètres et protection contre les failles IDOR.<br>Introduction de la notion de suppression logicielle.</p>'],
        ['Techno Web - L’interface d’administration', 'Evaluation sommative sur machine', '<p>Evaluation sommative de la séquence sur machine d’après la grille d’évaluation co-construite à la séance précédente.</p>'],
        ['Techno Web - L’interface d’administration', 'Remédiation & Co-construction de l’évaluation', '<p>Co-Création de la grille d’évaluation en vue de l’évaluation sommative de la séance suivante.</p>'],
        ['Techno Web - L’interface d’administration', '[C]RUD - La base de données', '<p>Validation du sujet du site et déclinaison des entités en schéma de bases de données.<br>Création des tables via PhpMyAdmin ou équivalent.<br>Mise en place des index sur les tables.<br>Questionnement sur les champs nécessaires et les champs pertinents.</p>'],
        ['Techno Web - L’interface d’administration', '[C]RUD - Le backend', '<p>Développement de la partie backend de la création d’une entité sur l’interface d’administration de son site.<br>Révisions sur les requêtes SQL et sur les requêtes préparées pour se protéger des failles de type injections SQL.</p>'],
        ['Techno Web - L’interface d’administration', '[C]RUD - Le frontend', '<p>Développement de la partie frontend de la création d’une entité sur l’interface d’administration de son site.<br>Notions d’ergonomie et de procédures de tests</p>'],
        ['Techno Web - l’authentification', 'BDD - La sécurité du mot de passe', '<p>Création de la table User en base de données avec compréhension du processus de stockage et vérification du mot de passe.<br>Ecriture du code permettant d’interagir avec cette table.</p>'],
        ['Techno Web - l’authentification', 'Evaluation sommative sur machine', '<p>Evaluation sommative de la séquence sur machine d’après la grille d’évaluation fournie à la séance précédente.</p>'],
        ['Techno Web - l’authentification', 'HTML - Le formulaire d’authentification', '<p>Réaliser la partie frontend d’un formulaire d’authentification en HTML.<br>Comprendre les éléments clés de sécurités comme l’en voie en POST et les différents types de champs utilisés.<br>Mettre en place des éléments en HTML5 pour améliorer l’ergonomie (et non la sécurité !!!)</p>'],
        ['Techno Web - l’authentification', 'Installation de son environnement de travail', '<p>Présentation de l’objectif de la séquence à savoir la réalisation d’un formulaire de connexion sécurisé en PHP.<br>Installation de l’environnement de développement local avec Docker<br>Sauvegarde de son travail sur un outil de travail collaboratif : Github</p>'],
        ['Techno Web - l’authentification', 'PHP - Rester connecté, les sessions', '<p>Mise en place de la persistance de l’authentification lors de la navigation de l’utilisateur.<br>Importance des critères de stockage du cookie du point de vue de la cybersécurité.</p>'],
        ['Techno Web - l’authentification', 'Remédiation', '<p>Co-Création de la grille d’évaluation en vue de l’évaluation sommative de la séance suivante.</p>'],
    ];

    public function getDescription(): string
    {
        return "Reprend l'import des cahiers de texte en ignorant la forme de l'apostrophe dans les titres.";
    }

    /**
     * Version20260802180000 n'a retrouvé que 61 séances sur 100. Les 39 manquantes sont exactement
     * celles dont le titre - de séance ou de séquence - contient une apostrophe : l'export Notion
     * écrit l'apostrophe typographique (’) là où la base en porte une autre forme. Toute séquence
     * dont le titre en contient une échouait en bloc, d'où « Techno Web - l’authentification » ou
     * « Qualité d’un projet web » entièrement absentes.
     *
     * L'appariement ignore donc l'apostrophe des deux côtés, sous toutes ses formes, plutôt que de
     * parier sur celle que porte la base. Aucun des 100 couples ne devient ambigu une fois
     * l'apostrophe retirée - vérifié sur l'export.
     *
     * Les 100 entrées sont rejouées, et non les 39 seules : les séances déjà renseignées restent
     * protégées par la même clause qu'avant, celle qui n'écrit que dans un cahier de texte vide.
     */
    public function up(Schema $schema): void
    {
        foreach (self::ENTRIES as [$sequence, $seance, $contenu]) {
            $this->addSql(
                <<<'SQL'
                    UPDATE seance_template st
                    INNER JOIN sequence_template sq ON sq.id = st.sequence_template_id
                    SET st.cahier_de_texte_description = ?
                    WHERE REPLACE(REPLACE(REPLACE(sq.titre, '’', ''), '‘', ''), '''', '') = ?
                      AND REPLACE(REPLACE(REPLACE(st.titre, '’', ''), '‘', ''), '''', '') = ?
                      AND (st.cahier_de_texte_description IS NULL OR st.cahier_de_texte_description = '')
                    SQL,
                [$contenu, self::sansApostrophe($sequence), self::sansApostrophe($seance)],
            );
        }

        $this->addSql(<<<'SQL'
            UPDATE seance_instance si
            INNER JOIN seance_template st ON st.id = si.source_template_id
            SET si.cahier_de_texte_description = st.cahier_de_texte_description
            WHERE (si.cahier_de_texte_description IS NULL OR si.cahier_de_texte_description = '')
              AND st.cahier_de_texte_description IS NOT NULL
              AND st.cahier_de_texte_description <> ''
            SQL);
    }

    public function postUp(Schema $schema): void
    {
        $introuvables = [];

        foreach (self::ENTRIES as [$sequence, $seance, $contenu]) {
            $trouvees = (int) $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*) FROM seance_template st
                    INNER JOIN sequence_template sq ON sq.id = st.sequence_template_id
                    WHERE REPLACE(REPLACE(REPLACE(sq.titre, '’', ''), '‘', ''), '''', '') = ?
                      AND REPLACE(REPLACE(REPLACE(st.titre, '’', ''), '‘', ''), '''', '') = ?
                    SQL,
                [self::sansApostrophe($sequence), self::sansApostrophe($seance)],
            );

            if (0 === $trouvees) {
                $introuvables[] = sprintf('%s ▸ %s', $sequence, $seance);
            }
        }

        $total = \count(self::ENTRIES);
        $this->write(sprintf('%d séances sur %d retrouvées en base.', $total - \count($introuvables), $total));

        foreach ($introuvables as $couple) {
            $this->write(sprintf('  introuvable : %s', $couple));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ENTRIES as [$sequence, $seance, $contenu]) {
            $this->addSql(
                <<<'SQL'
                    UPDATE seance_instance si
                    INNER JOIN seance_template st ON st.id = si.source_template_id
                    INNER JOIN sequence_template sq ON sq.id = st.sequence_template_id
                    SET si.cahier_de_texte_description = NULL
                    WHERE REPLACE(REPLACE(REPLACE(sq.titre, '’', ''), '‘', ''), '''', '') = ?
                      AND REPLACE(REPLACE(REPLACE(st.titre, '’', ''), '‘', ''), '''', '') = ?
                      AND si.cahier_de_texte_description = ?
                    SQL,
                [self::sansApostrophe($sequence), self::sansApostrophe($seance), $contenu],
            );
            $this->addSql(
                <<<'SQL'
                    UPDATE seance_template st
                    INNER JOIN sequence_template sq ON sq.id = st.sequence_template_id
                    SET st.cahier_de_texte_description = NULL
                    WHERE REPLACE(REPLACE(REPLACE(sq.titre, '’', ''), '‘', ''), '''', '') = ?
                      AND REPLACE(REPLACE(REPLACE(st.titre, '’', ''), '‘', ''), '''', '') = ?
                      AND st.cahier_de_texte_description = ?
                    SQL,
                [self::sansApostrophe($sequence), self::sansApostrophe($seance), $contenu],
            );
        }
    }

    private static function sansApostrophe(string $titre): string
    {
        return str_replace(['’', '‘', "'"], '', $titre);
    }
}
