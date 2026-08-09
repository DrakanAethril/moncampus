<?php

declare(strict_types=1);

namespace App\Help;

use App\Enum\HelpArticleKind;
use App\Enum\HelpAudience;

/**
 * The help centre's initial content, as data.
 *
 * It lives in the repository rather than only in the database because it had to be written from the
 * application's real screens - every button name below is the one the interface shows - and because
 * a fresh install (or the CI database, replayed from empty) should be able to get it back.
 * App\Command\SyncHelpContentCommand loads it, and never overwrites what an admin has since edited:
 * from the moment a row is saved through the admin screens, this file stops being its source.
 *
 * Adding to it is fine; rewriting an entry here has no effect on a row already edited in production.
 *
 * @phpstan-type CatalogArticle array{
 *     kind: HelpArticleKind,
 *     slug: string,
 *     title: string,
 *     audiences: list<HelpAudience>,
 *     summary: string,
 *     body?: string
 * }
 * @phpstan-type CatalogSection array{
 *     slug: string,
 *     title: string,
 *     description: string,
 *     audiences: list<HelpAudience>,
 *     articles: list<CatalogArticle>
 * }
 */
final class HelpContentCatalog
{
    /** @return list<CatalogSection> */
    public function sections(): array
    {
        return [
            $this->firstSteps(),
            $this->assignments(),
            $this->lessonLog(),
            $this->gradebook(),
            $this->classroomTools(),
            $this->contentPreparation(),
            $this->alternance(),
            $this->configuration(),
            $this->glossary(),
        ];
    }

    /** @return CatalogSection */
    private function firstSteps(): array
    {
        return [
            'slug' => 'premiers-pas',
            'title' => 'Premiers pas',
            'description' => 'Se repérer, retrouver ses classes, régler son compte',
            'audiences' => [HelpAudience::Teacher, HelpAudience::Staff],
            'articles' => [
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'se-reperer-dans-moncampus',
                    'title' => 'Se repérer dans MonCampus',
                    'audiences' => [HelpAudience::Teacher, HelpAudience::Staff],
                    'summary' => "La barre du haut change selon votre rôle. Elle contient tout : vos classes, vos outils, l'agenda et votre compte.",
                    'body' => <<<'HTML'
                        <h2>La barre de navigation</h2>
                        <p>Elle s'adapte à votre rôle. Un enseignant y trouve <strong>Accueil</strong>, une entrée par section de l'école, <strong>Outils</strong>, <strong>Support</strong> et <strong>Agenda</strong>. L'administration y trouve en plus <strong>UFA</strong>, <strong>Gestion</strong> et <strong>Configuration</strong>.</p>
                        <p>Les entrées portant un chevron ouvrent un sous-menu. Les entrées de section mènent à vos formations, regroupées par année scolaire.</p>
                        <h2>Le menu Outils</h2>
                        <p>Il rassemble les outils qui ne dépendent pas d'un écran de classe, en trois groupes : <strong>Animer la classe</strong> (Tirage au sort, Création de groupes), <strong>Préparer ses contenus</strong> (Progression pédagogique, Bibliothèque de Séquences/Séances, Bibliothèque de Quiz, Enregistrements audio) et <strong>Suivre les étudiants</strong> (Cahier de texte, Travail étudiant, Carnet de notes).</p>
                        <p>Un outil qui a besoin d'une classe et qui est ouvert depuis ce menu commence par vous la demander. Ouvert depuis le sous-menu d'une formation, il la connaît déjà.</p>
                        <h2>Le menu de votre avatar</h2>
                        <p>En haut à droite : <strong>Profil</strong>, l'interrupteur de thème clair/sombre, <strong>Aide</strong>, <strong>À propos</strong> et <strong>Se déconnecter</strong>. Les enseignants y ont aussi leur <strong>Emploi du Temps</strong>.</p>
                        <h2>Le fil d'Ariane</h2>
                        <p>Chaque écran affiche sous la barre le chemin qui y mène, en commençant toujours par <strong>Accueil</strong>. Chaque segment est cliquable, y compris le dernier.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'retrouver-ses-classes',
                    'title' => 'Retrouver ses classes et ses formations',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => 'Vos formations sont rangées par section puis par année scolaire. Chacune ouvre ses propres écrans : étudiants, emploi du temps, travaux, notes.',
                    'body' => <<<'HTML'
                        <h2>Où elles se trouvent</h2>
                        <p>Chaque section de l'école a son entrée dans la barre du haut. Elle liste les formations, regroupées par année scolaire. Vous ne voyez que les formations auxquelles vous êtes rattaché : si l'une manque, c'est le rattachement qui manque — écrivez au secrétariat.</p>
                        <h2>Ce qu'on trouve dans une formation</h2>
                        <p>Le sous-menu d'une formation donne accès à la <strong>Liste des étudiants</strong>, à la <strong>Liste des enseignants</strong>, à l'<strong>Emploi du temps</strong>, aux <strong>Travaux</strong>, aux <strong>Séquences</strong>, au <strong>Syllabus</strong>, aux <strong>Quiz</strong> et aux outils de la classe.</p>
                        <h2>La zone de test</h2>
                        <p>Les formations de démonstration sont regroupées sous <strong>ZONE DE TEST</strong>. Elles servent à s'entraîner sans conséquence. Un compte de test ne voit que ces formations ; un compte réel voit les deux.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'regler-son-profil',
                    'title' => 'Régler son profil et son thème',
                    'audiences' => [HelpAudience::Teacher, HelpAudience::Staff],
                    'summary' => 'Photo, signature de messagerie, adresse de contact et thème clair ou sombre se règlent depuis le menu de votre avatar.',
                    'body' => <<<'HTML'
                        <h2>Ouvrir son profil</h2>
                        <p>Menu de l'avatar, en haut à droite, puis <strong>Profil</strong>.</p>
                        <h2>Ce qui s'y règle</h2>
                        <p>Votre photo, votre signature de messagerie et vos préférences. Votre identifiant, votre nom et vos droits viennent de l'annuaire de l'école : ils ne se modifient pas ici.</p>
                        <h2>Le thème</h2>
                        <p>L'interrupteur clair/sombre est dans le menu de l'avatar, au-dessus de « Aide ». Le choix est enregistré sur votre compte et vous suit d'un poste à l'autre.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'signaler-un-probleme',
                    'title' => 'Signaler un problème au support',
                    'audiences' => [HelpAudience::Teacher, HelpAudience::Staff],
                    'summary' => "L'entrée Support de la barre du haut ouvre vos tickets. La suite de l'échange avec l'équipe technique se passe dans le ticket lui-même.",
                    'body' => <<<'HTML'
                        <h2>Ouvrir un ticket</h2>
                        <p><strong>Support</strong> dans la barre du haut. Un ticket porte un <strong>Sujet</strong>, une <strong>Catégorie</strong>, une <strong>Salle</strong> lorsque le problème est situé — ou un <strong>Autre lieu</strong> s'il n'est pas dans la liste — et une <strong>Description</strong>.</p>
                        <h2>Ce qu'il faut écrire</h2>
                        <p>L'écran concerné, ce que vous attendiez et ce qui s'est produit. Une adresse de page, copiée depuis la barre du navigateur, fait gagner le plus de temps.</p>
                        <h2>Le suivi</h2>
                        <p>Le ticket porte une conversation : chaque réponse de l'équipe technique s'y ajoute. Son statut passe d'<strong>Ouvert</strong> à <strong>En cours</strong>, <strong>En attente d'informations</strong>, <strong>Résolu</strong> puis <strong>Fermé</strong>.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'classe-absente-du-menu',
                    'title' => "Une de mes classes n'apparaît pas dans le menu, pourquoi ?",
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Le menu n'affiche que les formations auxquelles votre compte est rattaché comme enseignant. Le rattachement se fait dans le paramétrage de la formation, onglet Enseignants — demandez-le au secrétariat.",
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'plusieurs-annees-scolaires',
                    'title' => 'Je vois les formations de plusieurs années scolaires, est-ce normal ?',
                    'audiences' => [HelpAudience::Teacher, HelpAudience::Staff],
                    'summary' => "Oui. Les formations sont regroupées par année scolaire sous chaque section, et les années passées restent consultables. Vérifiez l'année avant de saisir.",
                ],
            ],
        ];
    }

    /** @return CatalogSection */
    private function assignments(): array
    {
        return [
            'slug' => 'travaux',
            'title' => 'Travaux à faire',
            'description' => 'Créer un travail, demander des rendus, suivre les dépôts',
            'audiences' => [HelpAudience::Teacher],
            'articles' => [
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'creer-un-travail',
                    'title' => 'Créer un travail et le publier',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "L'assistant tient en quatre étapes : destinataires, type de travail, consigne, échéance. Rien n'est enregistré tant que vous n'avez pas publié.",
                    'body' => <<<'HTML'
                        <h2>Ouvrir l'assistant</h2>
                        <p>Depuis la formation, écran <strong>Travaux</strong>, bouton <strong>+ Nouveau travail</strong>. Depuis une séance du cahier de texte, <strong>+ Ajouter un travail</strong> : le travail est alors rattaché à cette séance.</p>
                        <h2>1. Destinataires</h2>
                        <p>Un travail vise toujours une classe. Au sein de la classe, vous choisissez <strong>Toute la classe</strong>, <strong>Par option</strong>, une <strong>Sélection d'élèves</strong> ou <strong>Par groupes</strong>. Par groupes, le travail s'appuie sur un lot créé avec l'outil Création de groupes : un seul membre dépose pour son groupe.</p>
                        <h2>2. Type de travail</h2>
                        <p>Le type détermine ce qui est demandé : <strong>À rendre</strong>, <strong>À réviser</strong>, <strong>À préparer</strong>, <strong>À lire</strong>, <strong>Exercices</strong>, <strong>Quiz en ligne</strong>, <strong>Autoévaluation</strong>, <strong>Écoute</strong> ou <strong>Autre</strong>. Vous réglez ensuite le caractère (obligatoire ou facultatif) et la notation.</p>
                        <p>Un travail de type Quiz en ligne demande le quiz à dérouler et un objectif minimum, en pourcentage de bonnes réponses. Un travail d'Écoute demande l'enregistrement audio à écouter. Une Autoévaluation demande l'évaluation du carnet de notes que l'étudiant doit estimer.</p>
                        <h2>3. Consigne</h2>
                        <p>Titre, consigne, documents et liens. La consigne est ce que l'étudiant lit en premier : elle doit se comprendre sans ouvrir de pièce jointe. Un fichier joint est limité à 20 Mo.</p>
                        <p>C'est ici que se déclarent les <strong>productions attendues</strong>, quand le travail demande un dépôt.</p>
                        <h2>4. Échéance</h2>
                        <p>Prochaine séance, fin de semaine ou date et heure précises. Vous décidez si le dépôt en retard est autorisé — dans ce cas le rendu arrive signalé « en retard ». Enfin, la visibilité : visible dès l'enregistrement, programmée à une date, ou masquée.</p>
                        <h2>Publier</h2>
                        <p><strong>Publier le travail</strong> clôt l'assistant. L'écran de confirmation propose <strong>Suivre les rendus</strong> et <strong>Créer un autre travail</strong>. Un travail masqué reste invisible des étudiants jusqu'à sa publication.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'productions-attendues',
                    'title' => 'Demander une ou plusieurs productions',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Une production attendue, c'est un dépôt : un nom, un format et sa propre échéance. Un travail peut en demander plusieurs.",
                    'body' => <<<'HTML'
                        <h2>Déclarer une production</h2>
                        <p>À l'étape <strong>Consigne</strong>, <strong>+ Ajouter une production attendue</strong>. Donnez-lui un nom explicite : c'est ce nom que l'étudiant voit au-dessus de sa zone de dépôt.</p>
                        <h2>Le format</h2>
                        <p>Image, PDF, Tableur, URL, ZIP ou tout format. Le format contraint ce que l'étudiant peut déposer. Chaque production est limitée à 10 Mo.</p>
                        <h2>Les échéances multiples</h2>
                        <p>Chaque production suit l'échéance du travail, ou porte sa propre date et heure. Dans ce cas l'assistant vous le signale, et l'étudiant voit chaque production avec sa propre date.</p>
                        <h2>Ce que ça change pour l'étudiant</h2>
                        <p>Son écran <strong>Travail à faire</strong> affiche une ligne par production attendue, pas une par travail. Un travail à trois productions apparaît donc trois fois, chacune avec son échéance et son état.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'suivre-les-rendus',
                    'title' => 'Suivre les rendus',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "La liste des travaux affiche l'avancement de chacun. L'ouvrir donne le détail élève par élève, avec la date de dépôt réelle.",
                    'body' => <<<'HTML'
                        <h2>La liste des travaux</h2>
                        <p>Écran <strong>Travaux</strong> de la formation, ou <strong>Outils &gt; Travail étudiant</strong> pour voir toutes vos classes à la fois. La colonne <strong>Avancement</strong> indique le nombre de dépôts, de réponses ou de lectures selon le type de travail.</p>
                        <h2>Le détail d'un travail</h2>
                        <p><strong>Consulter</strong> ouvre le travail : ses destinataires, ses productions attendues, et qui a déposé quoi. La colonne <strong>Déposé le</strong> porte la date réelle du dépôt, ce qui rend un retard visible même quand il est autorisé.</p>
                        <h2>Les états d'un travail</h2>
                        <p>Un travail est <strong>à venir</strong>, <strong>imminent</strong>, <strong>échu</strong> ou <strong>masqué</strong>. Un travail masqué n'a pas encore d'existence pour les étudiants ; sa ligne indique la date de publication quand elle est programmée.</p>
                        <h2>Filtrer</h2>
                        <p>La liste se filtre par classe, par type et par état, et se cherche par titre.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'modifier-un-travail-publie',
                    'title' => 'Modifier, masquer ou reporter un travail',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Un travail publié se rouvre dans le même assistant. Les modifications ne prennent effet qu'à l'enregistrement.",
                    'body' => <<<'HTML'
                        <h2>Rouvrir le travail</h2>
                        <p>Depuis la liste des travaux, ouvrez le travail puis modifiez-le. L'assistant est le même qu'à la création, en quatre étapes, et l'écran s'intitule <strong>Modifier le travail</strong>.</p>
                        <h2>Ce qui est visible immédiatement</h2>
                        <p>Les modifications ne prennent effet qu'à l'enregistrement. Une fois enregistrées, elles sont visibles des étudiants concernés.</p>
                        <h2>Reporter une échéance</h2>
                        <p>Modifiez l'échéance à l'étape 4. Les dépôts déjà effectués sont conservés avec leur date de dépôt d'origine.</p>
                        <h2>Retirer un travail de la vue des étudiants</h2>
                        <p>Passez sa visibilité à <strong>Masquée</strong>. Le travail reste dans votre liste, avec les rendus déjà déposés.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'rendu-en-retard',
                    'title' => 'Un étudiant a rendu en retard, que se passe-t-il ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Si vous avez autorisé le dépôt en retard, le rendu est accepté et signalé « en retard » dans votre suivi, avec sa date de dépôt réelle. Sinon, le dépôt n'est plus possible passé l'échéance.",
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'travail-facultatif',
                    'title' => 'À quoi sert le caractère « facultatif » ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Il se règle à l'étape « Type de travail » et marque un travail proposé sans être exigé : sa ligne porte la mention « facultatif » dans la liste des travaux.",
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'travail-ignore-par-un-etudiant',
                    'title' => "Que fait « Ignorer » du côté de l'étudiant ?",
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Sur sa liste, l'étudiant peut ignorer une ligne : elle cesse d'être signalée en retard, et un travail ignoré dont l'échéance est à venir reste visible, grisé. Il peut la rétablir. Cela ne change rien à votre suivi des rendus.",
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'travail-note-et-carnet',
                    'title' => 'Un travail noté crée-t-il une évaluation dans le carnet ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Oui : un travail déclaré noté fait naître l'évaluation correspondante dans le carnet de notes à la réception des rendus. Vous saisissez ensuite les notes depuis le carnet, comme pour n'importe quelle évaluation.",
                ],
            ],
        ];
    }

    /** @return CatalogSection */
    private function lessonLog(): array
    {
        return [
            'slug' => 'cahier-de-texte',
            'title' => 'Cahier de texte',
            'description' => 'Remplir une séance, décider de sa visibilité',
            'audiences' => [HelpAudience::Teacher],
            'articles' => [
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'remplir-une-seance',
                    'title' => 'Remplir le cahier de texte d\'une séance',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => 'Le cahier de texte se remplit séance par séance, en trois temps : avant, pendant, après.',
                    'body' => <<<'HTML'
                        <h2>Ouvrir une séance</h2>
                        <p><strong>Outils &gt; Cahier de texte</strong>, puis la classe. L'écran liste les séances de la semaine et indique celles qui sont remplies. <strong>Ouvrir la séance</strong> passe à la saisie.</p>
                        <h2>Les trois temps</h2>
                        <p><strong>Avant la séance</strong> porte le travail à faire pour venir. <strong>Pendant la séance</strong> porte le contenu réalisé. <strong>Après la séance</strong> porte le travail donné pour la suite. Chaque temps a sa propre visibilité.</p>
                        <h2>Documents et travaux</h2>
                        <p><strong>+ Ajouter un document</strong> joint un fichier ou un lien — l'un ou l'autre, pas les deux. <strong>+ Ajouter un travail</strong> crée un travail rattaché à la séance, avec l'assistant habituel.</p>
                        <h2>Les séances des collègues</h2>
                        <p>L'interrupteur <strong>Séances de toute la formation</strong> affiche les séances des autres enseignants. Elles sont consultables en lecture seule : la saisie se fait sur la page de séance de leur auteur.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'visibilite-du-cahier-de-texte',
                    'title' => 'Décider quand les étudiants voient un temps',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Chaque temps de la séance est masqué par défaut. Tant que vous ne le publiez pas, l'étudiant ne lit rien.",
                    'body' => <<<'HTML'
                        <h2>Le réglage</h2>
                        <p>Chaque temps porte un bouton <strong>Visibilité</strong>. Quatre choix : <strong>Visible dès maintenant</strong>, <strong>Programmer · fin de la séance</strong>, <strong>Choisir date et heure…</strong> et <strong>Masqué (brouillon)</strong>.</p>
                        <h2>Le défaut est « masqué »</h2>
                        <p>Un temps nouvellement rempli est masqué. C'est ce qui permet de préparer une séance à l'avance sans que la classe la lise. Rien n'est lisible tant que vous n'avez pas choisi une autre visibilité.</p>
                        <h2>Ce que le réglage emporte</h2>
                        <p>La visibilité s'applique au temps <em>et</em> à ses documents. Un document peut ensuite recevoir sa propre date d'ouverture, plus tardive.</p>
                        <h2>Vérifier</h2>
                        <p>Le badge à côté de chaque temps dit l'état réel : <strong>Visible</strong>, <strong>Masqué</strong>, <strong>Visible depuis le…</strong> ou <strong>Programmé · …</strong>.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'importer-depuis-une-seance',
                    'title' => 'Reprendre le contenu d\'une autre séance',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "L'action Importer depuis une séance recopie le contenu d'une séance déjà remplie dans celle que vous éditez.",
                    'body' => <<<'HTML'
                        <h2>Quand s'en servir</h2>
                        <p>Deux groupes qui suivent la même séance, ou une séance qui reprend la précédente.</p>
                        <h2>Comment</h2>
                        <p>Sur la séance à remplir, <strong>Importer depuis une séance</strong>, choisissez la séance source, puis <strong>Importer</strong>.</p>
                        <h2>Après l'import</h2>
                        <p>Le contenu importé est modifiable comme n'importe quelle saisie, et la visibilité reste à régler sur la séance d'arrivée.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'etudiant-ne-voit-pas-le-cahier',
                    'title' => 'Mes étudiants ne voient rien dans le cahier de texte, pourquoi ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => 'Un temps de séance est masqué par défaut. Ouvrez la séance, bouton Visibilité sur le temps concerné, et choisissez « Visible dès maintenant » ou une date de publication.',
                ],
            ],
        ];
    }

    /** @return CatalogSection */
    private function gradebook(): array
    {
        return [
            'slug' => 'carnet-de-notes',
            'title' => 'Carnet de notes',
            'description' => 'Évaluations, saisie des notes, barèmes, moyennes',
            'audiences' => [HelpAudience::Teacher],
            'articles' => [
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'creer-une-evaluation',
                    'title' => 'Créer une évaluation',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => 'Une évaluation appartient à une matière et à une période. Son barème, son coefficient et sa visibilité se règlent à la création.',
                    'body' => <<<'HTML'
                        <h2>Ouvrir le carnet</h2>
                        <p><strong>Outils &gt; Carnet de notes</strong>, puis la classe. Choisissez la <strong>Matière</strong> et la <strong>Période</strong> en haut de la grille.</p>
                        <h2>Créer</h2>
                        <p><strong>+ Nouvelle évaluation</strong>. Renseignez l'intitulé, le support (écrite, orale, pratique), la modalité (individuelle ou en groupe), le statut (prévue ou surprise) et la date.</p>
                        <h2>Barème et coefficient</h2>
                        <p>Le barème est la note maximale possible — 20, 10, 40. <strong>Ramener sur 20</strong> décide si la note entre dans la moyenne rapportée sur 20 ou pour sa valeur brute. Le coefficient pèse l'évaluation dans la moyenne de la matière.</p>
                        <h2>Visibilité</h2>
                        <p>Immédiate, ou programmée à une date. Avant cette date, l'évaluation est invisible pour les élèves et ne compte pas dans les moyennes qu'ils voient.</p>
                        <h2>Enchaîner sur la saisie</h2>
                        <p><strong>Créer et saisir</strong> ouvre directement l'écran de saisie des notes.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'saisir-les-notes',
                    'title' => 'Saisir les notes',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Deux façons de saisir : directement dans une cellule de la grille, ou dans l'écran de saisie rapide, élève par élève. L'enregistrement est automatique.",
                    'body' => <<<'HTML'
                        <h2>Dans la grille</h2>
                        <p>Cliquez une cellule et saisissez. L'enregistrement est automatique, cellule par cellule.</p>
                        <h2>En saisie rapide</h2>
                        <p>Depuis la colonne d'une évaluation, <strong>Saisir les notes</strong>. L'écran présente les élèves un par un : <strong>Entrée</strong> ou <strong>↓</strong> valide et passe au suivant, <strong>↑</strong> revient en arrière. <strong>Reprendre plus tard</strong> et <strong>Terminer</strong> ferment l'écran ; ce qui est saisi est déjà enregistré.</p>
                        <h2>Les saisies particulières</h2>
                        <p>Trois valeurs remplacent une note : <strong>abs</strong> pour un absent, <strong>ne</strong> pour non évalué, et une note entre parenthèses pour une note qui ne compte pas dans la moyenne. Elles se tapent comme une note, ou se posent avec les boutons de l'écran de saisie.</p>
                        <h2>Trier</h2>
                        <p>Les en-têtes de la grille trient les élèves par moyenne générale ou par la note d'une évaluation.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'bareme-detaille',
                    'title' => 'Noter question par question',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => 'Un barème détaillé remplace la note globale par une notation question par question, regroupée en parties.',
                    'body' => <<<'HTML'
                        <h2>Activer</h2>
                        <p>À la création ou à la modification de l'évaluation, cochez <strong>Barème détaillé</strong>.</p>
                        <h2>Construire le barème</h2>
                        <p>Nommez vos parties, puis ajoutez les questions et leurs points. Le compteur rappelle le total des points face au barème de l'évaluation. <strong>Compléter plus tard</strong> permet de commencer la saisie avant d'avoir tout écrit.</p>
                        <h2>Saisir</h2>
                        <p>La saisie se fait par élève et par question, aux flèches. <strong>nt</strong> dans une case marque une question non traitée, qui vaut zéro point. L'enregistrement est automatique, même partiel.</p>
                        <h2>Ce que l'élève voit</h2>
                        <p>Sa note, et le détail du barème lorsque l'évaluation lui est visible.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'ce-que-voit-l-etudiant',
                    'title' => 'Ce que l\'étudiant voit de son carnet',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "L'étudiant a son propre écran « Mon carnet de notes » : ses moyennes, ses dernières notes, matière par matière.",
                    'body' => <<<'HTML'
                        <h2>Son écran</h2>
                        <p>Il y trouve sa moyenne générale, ses moyennes par matière et ses dernières notes.</p>
                        <h2>Ce qui lui est caché</h2>
                        <p>Une évaluation dont la visibilité est programmée reste invisible jusqu'à sa date, et ne compte pas dans les moyennes qui lui sont affichées. Une note entre parenthèses lui apparaît sans peser dans sa moyenne.</p>
                        <h2>Il ne voit que lui</h2>
                        <p>Un étudiant n'a accès ni aux notes des autres, ni à la grille de la classe.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'qui-voit-le-carnet',
                    'title' => "Qui peut voir le carnet de notes d'une classe ?",
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Vous saisissez dans les matières que vous enseignez ; les matières de vos collègues vous apparaissent en lecture seule. L'administration a accès à l'ensemble. Un étudiant ne voit que ses propres notes.",
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'note-qui-ne-compte-pas',
                    'title' => 'Comment saisir une note qui ne doit pas compter ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Mettez-la entre parenthèses : elle reste affichée et lisible, sans entrer dans la moyenne. Pour un élève absent, saisissez « abs » ; pour une évaluation qu'il n'a pas passée, « ne ».",
                ],
            ],
        ];
    }

    /** @return CatalogSection */
    private function classroomTools(): array
    {
        return [
            'slug' => 'outils-de-classe',
            'title' => 'Outils de classe',
            'description' => 'Tirage au sort, groupes, enregistrements audio',
            'audiences' => [HelpAudience::Teacher],
            'articles' => [
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'tirage-au-sort',
                    'title' => 'Tirer un étudiant au sort',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => 'Un tirage projetable, qui retient qui est déjà passé pour ne pas retomber deux fois sur la même personne.',
                    'body' => <<<'HTML'
                        <h2>Ouvrir l'outil</h2>
                        <p><strong>Outils &gt; Tirage au sort</strong>, puis la classe — sauf si vous partez du sous-menu de la formation, qui la connaît déjà.</p>
                        <h2>Régler le tirage</h2>
                        <p>Vous pouvez restreindre le tirage à une option, et afficher les noms complets ou abrégés. <strong>Remise en jeu</strong> décide si un élève déjà tiré peut ressortir.</p>
                        <h2>Tirer</h2>
                        <p><strong>Tirer au sort</strong> lance la roue. Le compteur rappelle combien d'élèves restent en jeu et combien sont déjà tirés. Quand tout le monde est passé, l'écran le dit : réinitialisez ou activez la remise.</p>
                        <h2>Projeter</h2>
                        <p><strong>Plein écran</strong> met l'outil au format vidéoprojecteur.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'creer-des-groupes',
                    'title' => 'Constituer des groupes équilibrés',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "L'outil répartit la classe en groupes selon vos contraintes, puis vous laisse ajuster à la main et enregistrer le résultat comme un lot réutilisable.",
                    'body' => <<<'HTML'
                        <h2>Régler la répartition</h2>
                        <p><strong>Outils &gt; Création de groupes</strong>, puis la classe. Choisissez <strong>Par taille</strong> (tant d'élèves par groupe) ou <strong>Par nombre</strong> (tant de groupes). La mixité des options peut être laissée <strong>Libre</strong>, forcée <strong>Homogène</strong> ou <strong>Mixte</strong>.</p>
                        <h2>Les contraintes</h2>
                        <p><strong>À séparer</strong> tient deux élèves dans des groupes différents. <strong>À réunir</strong> les met ensemble. Les élèves déclarés <strong>Absents</strong> sont exclus du tirage.</p>
                        <h2>Créer et ajuster</h2>
                        <p><strong>Créer les groupes</strong> produit la répartition. Glissez-déposez un élève d'un groupe à l'autre pour la retoucher. <strong>Verrouiller</strong> un groupe le préserve lors d'un <strong>Rebrassage des groupes déverrouillés</strong>.</p>
                        <h2>Conserver le résultat</h2>
                        <p><strong>Enregistrer le lot</strong> le range dans <strong>Lots enregistrés</strong>, sous le nom que vous lui donnez. Un lot enregistré est ce qu'un travail « Par groupes » réutilise ensuite comme destinataires.</p>
                        <h2>Diffuser</h2>
                        <p><strong>Exporter en PDF</strong> pour l'impression, <strong>Envoyer par messagerie</strong> pour prévenir la classe, <strong>Plein écran</strong> pour projeter.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'enregistrements-audio',
                    'title' => 'Créer un enregistrement audio pour une classe',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Enregistrez au micro un fichier commun à toute la classe, ou un fichier différent par étudiant, puis diffusez-le comme travail d'écoute.",
                    'body' => <<<'HTML'
                        <h2>Étape 1 — Paramètres</h2>
                        <p><strong>Outils &gt; Enregistrements audio</strong>, puis <strong>Nouvel enregistrement</strong>. Donnez un nom, choisissez la classe et éventuellement les options ciblées. Choisissez enfin le mode : fichiers <strong>Communs</strong> à toute la cible, ou <strong>Individualisés</strong> par étudiant.</p>
                        <h2>Étape 2 — Fichiers audio</h2>
                        <p><strong>Enregistrer au micro</strong> puis <strong>Arrêter</strong>. <strong>Réécouter</strong> vérifie la prise avant de passer à la suivante. En mode individualisé, l'écran suit le nombre d'étudiants déjà couverts.</p>
                        <h2>Brouillon et complétion</h2>
                        <p><strong>Enregistrer le brouillon</strong> met l'enregistrement de côté. Il reste au statut <strong>Brouillon</strong> dans la liste, et <strong>Compléter</strong> le rouvre là où vous l'aviez laissé.</p>
                        <h2>Le diffuser</h2>
                        <p><strong>Créer un travail à faire</strong> ouvre l'assistant de création avec le type <strong>Écoute</strong> et cet enregistrement déjà rattaché.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'suivre-les-ecoutes',
                    'title' => 'Suivre les écoutes d\'un enregistrement',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Les statistiques d'écoute disent, fichier par fichier et étudiant par étudiant, qui a écouté l'enregistrement en entier.",
                    'body' => <<<'HTML'
                        <h2>Ouvrir les statistiques</h2>
                        <p>Depuis la liste des enregistrements, action <strong>Statistiques</strong>.</p>
                        <h2>Ce qu'elles montrent</h2>
                        <p>Un résumé — écoutes complètes, en cours, non commencées, écoute moyenne — puis le détail <strong>Écoute par fichier</strong> et le tableau par étudiant, avec la date de dernière écoute.</p>
                        <h2>Ce que « écouté en entier » veut dire</h2>
                        <p>Le suivi crédite la portion réellement parcourue de bout en bout. Sauter en avant dans la barre de lecture ne remplit pas la portion sautée.</p>
                        <h2>Sur téléphone</h2>
                        <p>L'écoute et son suivi fonctionnent aussi depuis l'application mobile.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'reutiliser-un-lot-de-groupes',
                    'title' => 'Puis-je réutiliser les mêmes groupes pour un autre travail ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Oui, à condition d'avoir enregistré le lot. À l'étape « Destinataires » d'un travail, choisissez « Par groupes » puis le lot voulu. Un seul membre dépose alors pour son groupe.",
                ],
            ],
        ];
    }

    /** @return CatalogSection */
    private function contentPreparation(): array
    {
        return [
            'slug' => 'preparer-ses-contenus',
            'title' => 'Préparer ses contenus',
            'description' => 'Séquences, progression, quiz',
            'audiences' => [HelpAudience::Teacher],
            'articles' => [
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'bibliotheque-de-sequences',
                    'title' => 'Écrire une séquence et ses séances',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => 'La bibliothèque garde vos séquences et leurs séances, indépendamment de toute classe. On les instancie ensuite pour une classe donnée.',
                    'body' => <<<'HTML'
                        <h2>Créer une séquence</h2>
                        <p><strong>Outils &gt; Bibliothèque de Séquences/Séances</strong>, puis <strong>Nouvelle séquence</strong>. Titre, capacités attendues, pré-requis, objectifs, transversalités, situation problématique et supports généraux.</p>
                        <h2>Les tags</h2>
                        <p>Niveau, Option et Blocs de compétences sont des étiquettes libres, propres à vous. Elles servent à filtrer votre bibliothèque et ne sont reliées à aucune classe ni option officielle.</p>
                        <h2>Les séances</h2>
                        <p>Une séquence contient des séances, créées avec <strong>Nouvelle séance</strong>. Une séance peut être marquée facultative. Les durées de séance se saisissent en minutes.</p>
                        <h2>L'instancier pour une classe</h2>
                        <p><strong>Instancier pour une classe</strong> crée une copie figée de la séquence pour la classe choisie. Modifier la séquence d'origine ensuite ne change jamais cette copie.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'progression-pedagogique',
                    'title' => 'Bâtir sa progression pédagogique',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Une progression pose vos séquences et vos évaluations sur l'année d'une matière, puis place chaque séance sur un créneau réel de l'emploi du temps.",
                    'body' => <<<'HTML'
                        <h2>Créer une progression</h2>
                        <p><strong>Outils &gt; Progression pédagogique</strong>. La vue annuelle liste vos progressions ; <strong>Nouvelle progression</strong> en ouvre une, rattachée à une matière d'une classe.</p>
                        <h2>La remplir</h2>
                        <p><strong>Ajouter une séquence</strong> y accroche une séquence de votre bibliothèque. <strong>Poser une évaluation</strong> y place une évaluation du carnet de notes.</p>
                        <h2>Placer les séances</h2>
                        <p><strong>Placer les séances</strong> ouvre l'écran de placement : à chaque séance, vous choisissez un créneau de l'emploi du temps. Quand la durée de la séance dépasse celle du créneau, l'écran propose de la ramener à la durée du créneau.</p>
                        <h2>Vues</h2>
                        <p><strong>Détail</strong> ouvre un mois, <strong>Vue annuelle</strong> revient à l'année entière.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'creer-un-quiz',
                    'title' => 'Créer un quiz',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Un quiz est un modèle : vous l'écrivez une fois dans votre bibliothèque, et chaque lancement en copie une version figée pour une classe.",
                    'body' => <<<'HTML'
                        <h2>Créer</h2>
                        <p><strong>Outils &gt; Bibliothèque de Quiz</strong>, puis <strong>+ Nouveau quiz</strong>. L'éditeur a trois onglets : <strong>Questions</strong>, <strong>Paramètres</strong> et <strong>Tester</strong>.</p>
                        <h2>Les questions</h2>
                        <p>Chaque question porte son énoncé, ses propositions, la ou les bonnes réponses et une difficulté. Le type « texte à compléter » se note partiellement, trou par trou.</p>
                        <h2>Les paramètres</h2>
                        <p>L'identité du modèle n'est visible que de vous. Les valeurs par défaut au lancement pré-remplissent l'écran « Lancer » sans jamais toucher aux quiz déjà lancés.</p>
                        <h2>Tester</h2>
                        <p>L'onglet <strong>Tester</strong> déroule le quiz comme le ferait un étudiant et affiche le score, sans rien enregistrer.</p>
                        <h2>Importer un fichier</h2>
                        <p><strong>Import</strong> lit un CSV ou un rapport de partie Kahoot au format .xlsx. Rien n'est écrit à cette étape : un écran de vérification vous montre le quiz avant sa création.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'lancer-un-quiz',
                    'title' => 'Lancer un quiz dans une classe',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Lancer copie le quiz à l'instant T pour une classe. Le mode choisi décide de tout le reste : entraînement, évaluation ou concours live.",
                    'body' => <<<'HTML'
                        <h2>Les trois modes</h2>
                        <p><strong>Entraînement permanent</strong> : toujours ouvert, refaisable, tirage aléatoire à chaque tentative. <strong>Évaluation</strong> : une tentative, une fenêtre de temps, un score remonté. <strong>Concours live</strong> : en classe, sur mobile, classement en direct.</p>
                        <h2>Le tirage</h2>
                        <p>Vous fixez le nombre de questions tirées et le niveau de difficulté du tirage. Vous décidez si tous les étudiants reçoivent les mêmes questions, si l'ordre des questions change d'un étudiant à l'autre, et si l'ordre des réponses change aussi. Le récapitulatif au bas de l'écran énonce le réglage obtenu en toutes lettres.</p>
                        <h2>Le temps et le barème</h2>
                        <p>Un temps par question, et un temps global facultatif. Le barème est une note sur 20 ou un score en pourcentage. Vous décidez si l'étudiant voit son score dès la fin du questionnaire.</p>
                        <h2>Lancer</h2>
                        <p><strong>Lancer le quiz</strong> crée la copie pour la classe. Vos modifications ultérieures du modèle ne l'atteignent plus.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'concours-live',
                    'title' => 'Animer un concours live',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => 'Le concours live projette les questions, les étudiants répondent depuis leur téléphone, et le classement se met à jour à chaque question.',
                    'body' => <<<'HTML'
                        <h2>Créer la session</h2>
                        <p>Depuis le quiz, <strong>Lancer un concours</strong>, puis <strong>Créer la session</strong>. L'écran de session affiche le code que les étudiants saisissent pour rejoindre.</p>
                        <h2>Projeter</h2>
                        <p><strong>Ouvrir l'écran de projection</strong> ouvre la vue destinée au vidéoprojecteur : la question, le décompte, puis les résultats.</p>
                        <h2>Mener la partie</h2>
                        <p><strong>Lancer le concours</strong> démarre. <strong>Question suivante</strong> avance, <strong>Passer la question</strong> l'abandonne, <strong>Terminer la session</strong> clôt la partie. <strong>Classement complet</strong> affiche le tableau final.</p>
                        <h2>Retrouver une partie</h2>
                        <p>Les parties passées restent dans <strong>Sessions live</strong>, avec leur classement.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'partager-une-sequence',
                    'title' => 'Comment partager une séquence avec un collègue ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "La bibliothèque de séquences est personnelle : une séquence appartient à l'enseignant qui l'a écrite, et il n'y a pas d'échange entre bibliothèques. L'administration, elle, a accès à l'ensemble.",
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'modifier-un-quiz-deja-lance',
                    'title' => 'Je modifie un quiz : les quiz déjà lancés changent-ils ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Non. Lancer un quiz en copie une version à l'instant T pour la classe. Vos modifications du modèle ne s'appliquent qu'aux lancements suivants.",
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'duree-seance-minutes',
                    'title' => 'Les durées de séance sont-elles en heures ou en minutes ?',
                    'audiences' => [HelpAudience::Teacher],
                    'summary' => "Une séance de la bibliothèque se saisit en minutes. Les créneaux de l'emploi du temps, eux, sont en heures : c'est l'écran de placement de la progression qui fait la conversion.",
                ],
            ],
        ];
    }

    /** @return CatalogSection */
    private function alternance(): array
    {
        return [
            'slug' => 'alternance',
            'title' => 'Stage et alternance',
            'description' => 'Dossiers UFA, livret de suivi, relances, ordinateurs prêtés',
            'audiences' => [HelpAudience::Staff, HelpAudience::Teacher],
            'articles' => [
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'suivre-une-alternance',
                    'title' => 'Suivre une alternance',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Le tableau des alternances donne, pour une année scolaire, l'état du livret de chaque alternant et l'étape qui bloque.",
                    'body' => <<<'HTML'
                        <h2>Le tableau</h2>
                        <p><strong>UFA &gt; Alternances</strong>. La liste porte l'alternant, sa formation, son tuteur, l'entreprise et le <strong>Suivi du livret</strong>. Elle se filtre par année scolaire, par formation et par entreprise, et se cherche sur l'alternant, le tuteur ou l'entreprise.</p>
                        <h2>Lire la colonne de suivi</h2>
                        <p>Le badge nomme l'étape en cours et le rôle attendu : engagement, période d'évaluation, période clôturée, année clôturée. Un badge « en retard » signale une échéance dépassée.</p>
                        <h2>Ouvrir un dossier</h2>
                        <p>L'action <strong>Suivi</strong> ouvre le dossier : l'engagement, les périodes d'évaluation et le livret.</p>
                        <h2>Créer une alternance</h2>
                        <p><strong>Nouvelle alternance</strong> : l'alternant, la formation, l'entreprise, le tuteur et le type de contrat.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'livret-quatre-signatures',
                    'title' => 'Le livret de l\'alternant et ses quatre rôles',
                    'audiences' => [HelpAudience::Staff, HelpAudience::Teacher],
                    'summary' => "Chaque période d'évaluation se remplit par quatre rôles distincts : le tuteur, l'alternant, l'équipe pédagogique et le chargé de suivi.",
                    'body' => <<<'HTML'
                        <h2>Les quatre rôles</h2>
                        <p>Le <strong>Tuteur</strong> en entreprise, l'<strong>Alternant</strong>, l'<strong>Équipe pédagogique</strong> et le <strong>Chargé de suivi</strong>. Chacun a son propre formulaire et sa propre étape.</p>
                        <h2>Qui remplit quoi</h2>
                        <p>Le tuteur évalue les compétences et les comportements observés en entreprise. L'alternant fait son propre bilan. L'équipe pédagogique — ouverte aux enseignants de la formation — apporte le regard de l'école. Le chargé de suivi clôt la période.</p>
                        <h2>Suivre l'avancement</h2>
                        <p>Le dossier de l'alternance indique l'étape en cours et le rôle attendu. Les écrans d'état de l'UFA donnent la même lecture pour toute une formation.</p>
                        <h2>Le document</h2>
                        <p>Le livret s'exporte en PDF, avec ce qui a été renseigné à la date de l'export.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'relances-alternance',
                    'title' => 'Relancer les retards',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Deux façons de relancer : une alternance à la fois depuis son dossier, ou toute une période d'évaluation d'un coup depuis l'écran Relances.",
                    'body' => <<<'HTML'
                        <h2>Relancer toute une période</h2>
                        <p><strong>UFA &gt; Relances</strong>, puis choisissez la période d'évaluation. L'écran liste les alternances de cette période qui attendent le tuteur ou l'alternant, avec le badge de leur état.</p>
                        <h2>Relancer une alternance</h2>
                        <p>Depuis le dossier de l'alternance, l'action de relance ouvre un panneau qui rappelle l'étape attendue et les relances déjà envoyées pour ce dossier.</p>
                        <h2>La trace</h2>
                        <p>Chaque relance envoyée est conservée et reste consultable depuis le dossier de l'alternance.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'prets-d-ordinateurs',
                    'title' => 'Prêter un ordinateur portable',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "L'inventaire tient la liste des machines, les prêts tiennent qui a quoi, et l'état de chaque machine se constate au retour.",
                    'body' => <<<'HTML'
                        <h2>L'inventaire</h2>
                        <p><strong>UFA &gt; Ordinateurs portables &gt; Inventaire</strong> : les machines et leur état courant.</p>
                        <h2>Prêter et rendre</h2>
                        <p><strong>Prêts</strong> ouvre les prêts en cours, avec l'emprunteur et la date de retour attendue. Le retour se constate depuis la même liste.</p>
                        <h2>Les états</h2>
                        <p>Les états possibles d'une machine sont paramétrables dans <strong>Configuration</strong>, avec leur nom et leur couleur. L'état courant d'une machine est celui constaté à son dernier retour.</p>
                        <h2>L'historique</h2>
                        <p>Chaque machine garde l'historique de ses prêts.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'tuteur-sans-compte',
                    'title' => 'Un tuteur en entreprise a-t-il un compte MonCampus ?',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Oui : le tuteur se connecte avec un compte qui ne lui donne accès qu'aux alternances qu'il suit. Il n'apparaît pas dans les destinataires de la messagerie.",
                ],
            ],
        ];
    }

    /** @return CatalogSection */
    private function configuration(): array
    {
        return [
            'slug' => 'configuration',
            'title' => 'Configuration',
            'description' => "Structure de l'école, formations, classes, adresses élèves",
            'audiences' => [HelpAudience::Staff],
            'articles' => [
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'structure-de-l-ecole',
                    'title' => 'La structure : section, filière, classe',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Tout le reste s'accroche à ces trois niveaux. Ils se gèrent dans Configuration, un onglet par niveau.",
                    'body' => <<<'HTML'
                        <h2>Les trois niveaux</h2>
                        <p>Une <strong>Section</strong> contient des <strong>Filières</strong>, qui contiennent des <strong>Classes</strong>. La section est ce qui apparaît comme entrée dans la barre de navigation.</p>
                        <h2>Où les gérer</h2>
                        <p><strong>Configuration</strong> dans la barre du haut, onglets <strong>Sections</strong>, <strong>Filières</strong>, <strong>Classes</strong>. Les mêmes écrans tiennent les <strong>Salles</strong>, les <strong>Options</strong>, les <strong>Modalités</strong>, les <strong>Types de séance</strong>, les <strong>Types de période</strong> et les <strong>Niveaux de compétence</strong>.</p>
                        <h2>Désactiver plutôt que supprimer</h2>
                        <p>Ces écrans désactivent : l'élément cesse d'être proposé, sans que ce qui s'y rattache disparaisse. Un filtre permet de réafficher les éléments désactivés.</p>
                        <h2>Qui y a accès</h2>
                        <p>La Configuration est réservée à l'administration.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'creer-une-formation',
                    'title' => 'Créer une formation pour une année scolaire',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Une formation, c'est une classe pour une année scolaire donnée. C'est l'objet auquel se rattachent les étudiants, l'emploi du temps, les travaux et les notes.",
                    'body' => <<<'HTML'
                        <h2>Où</h2>
                        <p><strong>Pédagogique</strong> dans la barre du haut, onglet <strong>Formations</strong>. L'onglet <strong>Années scolaires</strong>, à côté, tient les années.</p>
                        <h2>Créer</h2>
                        <p>Choisissez la classe et l'année scolaire. Une même classe donne donc une formation par année, et l'historique des années précédentes reste consultable.</p>
                        <h2>Ce qui suit</h2>
                        <p>Une fois la formation créée, son <strong>Paramétrage</strong> permet d'y rattacher étudiants, enseignants, matières et emploi du temps.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'parametrer-une-classe',
                    'title' => 'Paramétrer une formation',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Le paramétrage d'une formation est une suite d'onglets : étudiants, enseignants, référents, matières, emploi du temps, compétences, financier.",
                    'body' => <<<'HTML'
                        <h2>Ouvrir le paramétrage</h2>
                        <p>Depuis le sous-menu de la formation, <strong>Paramétrage</strong>.</p>
                        <h2>Les personnes</h2>
                        <p><strong>Étudiants</strong> inscrit la promotion ; c'est aussi là que se règlent leurs options et leurs modalités. <strong>Enseignants</strong> rattache l'équipe — c'est ce rattachement qui fait apparaître la formation dans le menu d'un enseignant. <strong>Référents</strong> désigne les enseignants référents.</p>
                        <h2>Le contenu</h2>
                        <p><strong>Matières</strong> et <strong>Groupes de matières</strong> décrivent ce qui est enseigné : le carnet de notes et la progression s'y accrochent. <strong>Groupes de compétences</strong> et <strong>Niveaux de compétence</strong> servent aux évaluations par compétences.</p>
                        <h2>L'emploi du temps</h2>
                        <p>L'onglet <strong>Emploi du temps</strong> décide si la formation en gère un. Les outils qui s'appuient sur les séances — cahier de texte, carnet de notes — ne sont proposés que pour les formations qui en gèrent un.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Article,
                    'slug' => 'adresses-courrier-ecole',
                    'title' => 'Les adresses Courrier école des élèves',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Un élève peut porter plusieurs adresses de l'école, dont une par défaut. Elles s'administrent depuis sa fiche dans l'annuaire.",
                    'body' => <<<'HTML'
                        <h2>Où les administrer</h2>
                        <p><strong>Annuaire &gt; Utilisateurs</strong>, puis la fiche de l'élève. Ses adresses y figurent avec leur <strong>Origine</strong> : composée depuis le nom, reprise de l'identifiant, ou créée à la main.</p>
                        <h2>Ajouter une adresse</h2>
                        <p><strong>Ajouter une adresse</strong>, puis la partie qui précède l'arobase. Une adresse créée à la main comporte un point, sous la forme « quelquechose.quelquechose ». <strong>Retirer l'adresse</strong> enlève une ligne.</p>
                        <h2>L'adresse par défaut</h2>
                        <p>Une adresse est marquée par défaut : c'est celle qui représente l'élève.</p>
                        <h2>Ce que l'élève en voit</h2>
                        <p>Son écran <strong>Courrier école</strong> reçoit les messages arrivés sur ses adresses, avec leurs pièces jointes.</p>
                        HTML,
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'supprimer-une-classe',
                    'title' => 'Puis-je supprimer une classe ou une section ?',
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Les écrans de Configuration désactivent au lieu de supprimer : l'élément cesse d'être proposé, et tout ce qui s'y rattachait — formations, notes, travaux — reste consultable.",
                ],
                [
                    'kind' => HelpArticleKind::Faq,
                    'slug' => 'roles-viennent-de-l-annuaire',
                    'title' => "Où se changent les droits d'un utilisateur ?",
                    'audiences' => [HelpAudience::Staff],
                    'summary' => "Pas dans MonCampus : les rôles viennent des groupes de l'annuaire de l'école et sont resynchronisés à chaque connexion. Un changement de droits prend effet à la connexion suivante de la personne.",
                ],
            ],
        ];
    }

    /** @return CatalogSection */
    private function glossary(): array
    {
        $terms = [
            ['formation', 'Formation', "Une classe pour une année scolaire donnée. C'est l'objet auquel se rattachent les étudiants, l'emploi du temps, les travaux, les notes et les outils."],
            ['travail', 'Travail', 'Ce qui est demandé aux étudiants : à rendre, à réviser, à préparer, à lire, exercices, quiz, autoévaluation ou écoute. On dit un travail, jamais un devoir.'],
            ['production-attendue', 'Production attendue', "Un dépôt demandé dans un travail : un nom, un format et une échéance. Un travail peut en demander plusieurs, et l'étudiant voit une ligne par production."],
            ['sequence', 'Séquence', "Un ensemble de séances, écrit dans la bibliothèque personnelle d'un enseignant, indépendamment de toute classe."],
            ['seance-pedagogique', 'Séance (pédagogique)', "L'unité de contenu d'une séquence. Sa durée se saisit en minutes."],
            ['creneau', 'Créneau', "Une case de l'emploi du temps d'une formation : un jour, une heure, une salle, un enseignant. Sa durée s'exprime en heures."],
            ['progression', 'Progression pédagogique', "La mise en année des séquences et des évaluations d'une matière, puis le placement de chaque séance sur un créneau réel."],
            ['cahier-de-texte', 'Cahier de texte', 'Ce qui est consigné pour une séance, en trois temps : avant, pendant, après. Chaque temps a sa propre visibilité.'],
            ['evaluation', 'Évaluation', 'Une note attendue dans une matière et une période : un support, un barème, un coefficient et une date.'],
            ['bareme-detaille', 'Barème détaillé', "Une notation question par question, regroupée en parties, à la place d'une note globale."],
            ['lot-de-groupes', 'Lot de groupes', "Une répartition de la classe enregistrée sous un nom, réutilisable comme destinataires d'un travail « par groupes »."],
            ['enregistrement-audio', 'Enregistrement audio', "Un ou plusieurs fichiers sonores destinés à une classe, communs à tous ou individualisés par étudiant, diffusés comme travail d'écoute."],
            ['quiz-modele', 'Quiz (modèle)', "Le quiz tel qu'il est écrit dans la bibliothèque. Le lancer en copie une version figée pour une classe ; le modèle peut ensuite évoluer sans la toucher."],
            ['alternance', 'Alternance', 'Le dossier qui lie un alternant, une formation, une entreprise et un tuteur pour une année scolaire.'],
            ['livret-de-l-alternant', "Livret de l'alternant", "Le suivi d'une alternance période par période, rempli par quatre rôles : tuteur, alternant, équipe pédagogique et chargé de suivi."],
            ['zone-de-test', 'Zone de test', "Les formations de démonstration, regroupées dans le menu sous « ZONE DE TEST ». Un compte de test ne voit qu'elles ; un compte réel voit les deux mondes."],
        ];

        return [
            'slug' => 'glossaire',
            'title' => 'Glossaire',
            'description' => 'Les mots employés dans la plateforme',
            'audiences' => [HelpAudience::Teacher, HelpAudience::Staff],
            'articles' => array_map(
                static fn (array $term): array => [
                    'kind' => HelpArticleKind::Glossary,
                    'slug' => $term[0],
                    'title' => $term[1],
                    'audiences' => [HelpAudience::Teacher, HelpAudience::Staff],
                    'summary' => $term[2],
                ],
                $terms,
            ),
        ];
    }
}
