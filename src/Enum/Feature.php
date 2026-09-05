<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The catalogue of switchable features (design/validated/feature-access.md §4).
 *
 * **The catalogue lives in code**, on the same principle as App\Help\HelpContentCatalog: the
 * database stores only the *deviations* from it - the role matrix and the individual overrides -
 * so adding a feature is a case here plus an attribute on a controller, never a data migration.
 *
 * A feature is **not a screen**. It is a set of routes, menu entries, dashboard blocks, form
 * fields, API endpoints and mobile tabs: the agenda is the page *and* the "prochains événements"
 * block *and* /api/agenda. That is why the guard is declarative
 * (App\Attribute\RequiresFeature) and why the Twig function exists next to it.
 *
 * Three things this enum deliberately does not carry:
 *
 * - **`ROLE_ADMIN`.** An admin has everything, without reading anything - see
 *   App\Security\FeatureResolver. It is the escape hatch that makes a general switch-off safe,
 *   and a matrix column for it would eventually get unticked.
 * - **Rights.** This layer can only ever *remove*. Ticking `gradebook_entry` for a student does
 *   not open the saisie to them; the Voters remain the sole authority on who writes what.
 * - **Profile, theme, language, login, changelog, technical description.** Absent on purpose:
 *   putting them here would add a lock-out risk for nothing.
 */
enum Feature: string
{
    // --- Pédagogie ---------------------------------------------------------------------------

    case LessonLog = 'lesson_log';
    case StudentWork = 'student_work';
    case QuizLibrary = 'quiz_library';
    case QuizTake = 'quiz_take';
    case QuizLive = 'quiz_live';
    case Progression = 'progression';
    case SequenceLibrary = 'sequence_library';
    case SequenceImport = 'sequence_import';
    case CourseSpace = 'course_space';
    case Video = 'video';
    case Audio = 'audio';
    case FileLibrary = 'file_library';
    case SharedDocuments = 'shared_documents';
    case ContentSharing = 'content_sharing';
    case Wiki = 'wiki';
    case Documentation = 'documentation';
    case Surveys = 'surveys';
    case ClassTools = 'class_tools';
    case TsfReferential = 'tsf_referential';
    case Game = 'game';

    // --- Scolarité ---------------------------------------------------------------------------

    case Timetable = 'timetable';
    case TimetableSettings = 'timetable_settings';
    case EvaluationPlanning = 'evaluation_planning';
    case GradebookEntry = 'gradebook_entry';
    case GradebookStudent = 'gradebook_student';
    case SelfAssessment = 'self_assessment';
    case ProgramReporting = 'program_reporting';
    case ProgramExports = 'program_exports';
    case ClassListExports = 'class_list_exports';
    case ProgramFinancial = 'program_financial';
    case Directory = 'directory';

    // --- Vie scolaire et communication -------------------------------------------------------

    case Agenda = 'agenda';
    case Announcements = 'announcements';
    case Messaging = 'messaging';
    case SchoolMail = 'school_mail';
    case SchoolMailSupervision = 'school_mail_supervision';
    case SignupLists = 'signup_lists';
    case Support = 'support';
    case Help = 'help';

    // --- Alternance et insertion -------------------------------------------------------------

    case UfaBooklet = 'ufa_booklet';
    case MyAlternance = 'my_alternance';
    case TutorEvaluations = 'tutor_evaluations';
    case LaptopLoans = 'laptop_loans';
    case TrainingOffers = 'training_offers';
    case JobSearch = 'job_search';

    // --- Technique ---------------------------------------------------------------------------

    case MyVms = 'my_vms';
    case Infrastructure = 'infrastructure';
    case GuestConsole = 'guest_console';
    case Eco = 'eco';
    case ActivityHistory = 'activity_history';

    /**
     * The roles the matrix offers a column for, in the order the screen draws them.
     *
     * `ROLE_ADMIN` is absent by construction (§3.2 and "les douze choses à ne pas faire", 5). The
     * roles a user carries beyond this list - `ROLE_USER`, the cohort roles such as `ROLE_SIO` -
     * are simply not read: they answer "who is this person", not "what does the establishment run".
     *
     * Adding a role here is painless: its pairs are absent from the matrix until an admin ticks
     * them, and an absent pair falls back on defaultForRoles().
     *
     * @return list<string>
     */
    public static function managedRoles(): array
    {
        return [
            'ROLE_STUDENT',
            'ROLE_TEACHER',
            'ROLE_STAFF',
            'ROLE_STAFF-LEAD',
            'ROLE_TUTOR',
            'ROLE_SUPPORT-TECH',
            'ROLE_ECO',
            'ROLE_EXTERNAL',
        ];
    }

    public function family(): FeatureFamily
    {
        return match ($this) {
            self::LessonLog, self::StudentWork, self::QuizLibrary, self::QuizTake, self::QuizLive,
            self::Progression, self::SequenceLibrary, self::SequenceImport, self::CourseSpace,
            self::Video, self::Audio, self::FileLibrary, self::SharedDocuments,
            self::ContentSharing, self::Wiki, self::Documentation, self::Surveys,
            self::ClassTools, self::TsfReferential, self::Game => FeatureFamily::Pedagogy,

            self::Timetable, self::TimetableSettings, self::EvaluationPlanning,
            self::GradebookEntry, self::GradebookStudent, self::SelfAssessment,
            self::ProgramReporting, self::ProgramExports, self::ClassListExports,
            self::ProgramFinancial, self::Directory => FeatureFamily::Schooling,

            self::Agenda, self::Announcements, self::Messaging, self::SchoolMail,
            self::SchoolMailSupervision, self::SignupLists, self::Support,
            self::Help => FeatureFamily::Communication,

            self::UfaBooklet, self::MyAlternance, self::TutorEvaluations, self::LaptopLoans,
            self::TrainingOffers, self::JobSearch => FeatureFamily::Alternance,

            self::MyVms, self::Infrastructure, self::GuestConsole, self::Eco,
            self::ActivityHistory => FeatureFamily::Technical,
        };
    }

    /**
     * One level, never a tree (§3.7). A child enabled under an extinguished parent stays
     * extinguished, and that is checked before the individual override - otherwise a derogation
     * would resurrect a child whose parent no longer exists.
     *
     * The single pair today is `self_assessment` -> `gradebook_entry`: the student estimates a
     * grade nobody can enter any more, which is estimating into the void (§8.5).
     */
    public function parent(): ?self
    {
        return match ($this) {
            self::SelfAssessment => self::GradebookEntry,
            default => null,
        };
    }

    /**
     * Is this feature decided by the *formation* rather than by the role matrix?
     *
     * Exactly one today - the Courrier pro, whose switch is Program::$schoolMailEnabled next to
     * the four booleans that area already carries. The program axis short-circuits the matrix: for
     * a scoped feature the answer is "does at least one of this person's formations open it",
     * multi-formation resolving to the most permissive (§3.4). Only the individual override, read
     * one step earlier, can still say otherwise (§3.5).
     */
    public function isProgramScoped(): bool
    {
        return self::SchoolMail === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::LessonLog => 'featureLessonLogLabel',
            self::StudentWork => 'featureStudentWorkLabel',
            self::QuizLibrary => 'featureQuizLibraryLabel',
            self::QuizTake => 'featureQuizTakeLabel',
            self::QuizLive => 'featureQuizLiveLabel',
            self::Progression => 'featureProgressionLabel',
            self::SequenceLibrary => 'featureSequenceLibraryLabel',
            self::SequenceImport => 'featureSequenceImportLabel',
            self::CourseSpace => 'featureCourseSpaceLabel',
            self::Video => 'featureVideoLabel',
            self::Audio => 'featureAudioLabel',
            self::FileLibrary => 'featureFileLibraryLabel',
            self::SharedDocuments => 'featureSharedDocumentsLabel',
            self::ContentSharing => 'featureContentSharingLabel',
            self::Wiki => 'featureWikiLabel',
            self::Documentation => 'featureDocumentationLabel',
            self::Surveys => 'featureSurveysLabel',
            self::ClassTools => 'featureClassToolsLabel',
            self::TsfReferential => 'featureTsfReferentialLabel',
            self::Game => 'featureGameLabel',
            self::Timetable => 'featureTimetableLabel',
            self::TimetableSettings => 'featureTimetableSettingsLabel',
            self::EvaluationPlanning => 'featureEvaluationPlanningLabel',
            self::GradebookEntry => 'featureGradebookEntryLabel',
            self::GradebookStudent => 'featureGradebookStudentLabel',
            self::SelfAssessment => 'featureSelfAssessmentLabel',
            self::ProgramReporting => 'featureProgramReportingLabel',
            self::ProgramExports => 'featureProgramExportsLabel',
            self::ClassListExports => 'featureClassListExportsLabel',
            self::ProgramFinancial => 'featureProgramFinancialLabel',
            self::Directory => 'featureDirectoryLabel',
            self::Agenda => 'featureAgendaLabel',
            self::Announcements => 'featureAnnouncementsLabel',
            self::Messaging => 'featureMessagingLabel',
            self::SchoolMail => 'featureSchoolMailLabel',
            self::SchoolMailSupervision => 'featureSchoolMailSupervisionLabel',
            self::SignupLists => 'featureSignupListsLabel',
            self::Support => 'featureSupportLabel',
            self::Help => 'featureHelpLabel',
            self::UfaBooklet => 'featureUfaBookletLabel',
            self::MyAlternance => 'featureMyAlternanceLabel',
            self::TutorEvaluations => 'featureTutorEvaluationsLabel',
            self::LaptopLoans => 'featureLaptopLoansLabel',
            self::TrainingOffers => 'featureTrainingOffersLabel',
            self::JobSearch => 'featureJobSearchLabel',
            self::MyVms => 'featureMyVmsLabel',
            self::Infrastructure => 'featureInfrastructureLabel',
            self::GuestConsole => 'featureGuestConsoleLabel',
            self::Eco => 'featureEcoLabel',
            self::ActivityHistory => 'featureActivityHistoryLabel',
        };
    }

    /**
     * The seed of the role matrix, and the fallback for a (feature, role) pair the matrix has no
     * row for.
     *
     * **It is read once, at seeding time, and never again as an authority**: once the matrix is
     * written, the matrix decides. Changing a value here must not silently overwrite what an admin
     * ticked - which is exactly what would happen if the resolver preferred it to a stored row.
     *
     * Since 2026-08-25 the answer is **no** for almost everything, and the six exceptions are
     * listed rather than the refusals: the establishment runs a handful of areas and keeps the rest
     * of its schooling in another application. What survives here is what no role-specific decision
     * applies to; everything opened for one role and not another is in defaultRoles() instead.
     */
    public function defaultForRoles(): bool
    {
        return match ($this) {
            // Planned evaluations stay: an Evaluation is a pedagogical object carried by the
            // progression, and it is not the Grade - cutting the carnet must not take the planning
            // with it (§8.2).
            self::EvaluationPlanning => true,

            // Support: everybody must be able to say that something is broken.
            self::Support => true,

            // Alternance is the other half of what this establishment runs, and it is open to
            // everybody who takes part in it - the tutor included, whose only screens these are.
            self::UfaBooklet, self::MyAlternance, self::TutorEvaluations, self::LaptopLoans => true,

            default => false,
        };
    }

    /**
     * The roles a feature is delivered ON for, when the answer is not the same for everybody.
     *
     * `null` means there is no role-specific decision and defaultForRoles() answers alone. A list
     * means exactly those roles get it and every other one does not - `ROLE_ADMIN` never appears,
     * having everything by construction.
     *
     * Read next to defaultForRoles(): a feature that is `false` there and named here is off
     * everywhere *except* for the roles listed, which is how most of this catalogue now reads.
     *
     * @return list<string>|null
     */
    public function defaultRoles(): ?array
    {
        return match ($this) {
            // Pédagogie is off for everyone, with four exceptions the establishment asked for by
            // name. The rest of the family - the cahier de texte, the quizzes, the progression, the
            // libraries, the videos, the audio, the surveys, the référentiel - is not switched off
            // because it does not work, but because nobody has asked to run it yet. An admin turns
            // any line back on from Gestion > Fonctionnalités, and one person at a time from their
            // card in the annuaire.
            self::StudentWork, self::SharedDocuments, self::Wiki => ['ROLE_STUDENT'],
            self::ClassTools => ['ROLE_TEACHER'],

            // The student's own corner of the app. « Candidatures » follows school_mail on the
            // route itself (App\Controller\MyJobApplicationController), and these three travel
            // together: an offer applied to, a mail sent from the school mailbox, the list of what
            // was sent. The teachers' tracking screens go with them - reading what a class did is
            // no longer offered by the role, only by an individual derogation.
            self::SchoolMail, self::TrainingOffers, self::JobSearch => ['ROLE_STUDENT'],

            // The machines are handed out in class, so the two roles that sit in one.
            self::MyVms => ['ROLE_STUDENT', 'ROLE_TEACHER'],

            // e-CO is what ROLE_ECO exists for.
            self::Eco => ['ROLE_ECO'],

            // The two class lists are the establishment's own directory rather than a teaching
            // tool (see the nav's comment on them), and so is exporting one: an émargement sheet
            // and a file of names, addresses and options. The routes are staff/admin either way -
            // this line only says which of those two the establishment delivers it to by default.
            self::ClassListExports => ['ROLE_STAFF', 'ROLE_STAFF-LEAD'],

            default => null,
        };
    }

    /**
     * The roles that are delivered **nothing at all** unless a feature names them.
     *
     * They are not "somebody who uses the platform" the way a student or a teacher is: `ROLE_ECO`
     * exists for the orienteering races and is named by `eco` alone, and the other two are outside
     * accounts. Without this they would inherit the six role-blind survivors of defaultForRoles(),
     * which is how a race organiser came to be delivered the alternance booklet.
     *
     * It costs nobody anything real: these roles are carried alongside another one far more often
     * than on their own, and the resolver takes the most permissive of a person's roles. Somebody
     * who genuinely holds only one of them, and needs a screen, gets it from their card in the
     * annuaire - which is what the individual derogation is for.
     */
    private const array ROLES_WITHOUT_DEFAULTS = ['ROLE_ECO', 'ROLE_SUPPORT-TECH', 'ROLE_EXTERNAL'];

    /**
     * The seed for one (feature, role) pair. Only used by the seeding migrations; the resolver
     * reads the stored matrix and falls back on defaultForRoles() alone.
     */
    public function defaultForRole(string $role): bool
    {
        $roles = $this->defaultRoles();

        if (null !== $roles) {
            return \in_array($role, $roles, true);
        }

        // Checked after the named roles, not before: a feature that names one of these three is
        // delivered to it, which is the whole point of `eco` and `ROLE_ECO`.
        if (\in_array($role, self::ROLES_WITHOUT_DEFAULTS, true)) {
            return false;
        }

        return $this->defaultForRoles();
    }
}
