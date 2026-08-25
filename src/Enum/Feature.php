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

    // --- Scolarité ---------------------------------------------------------------------------

    case Timetable = 'timetable';
    case TimetableSettings = 'timetable_settings';
    case EvaluationPlanning = 'evaluation_planning';
    case GradebookEntry = 'gradebook_entry';
    case GradebookStudent = 'gradebook_student';
    case SelfAssessment = 'self_assessment';
    case ProgramReporting = 'program_reporting';
    case ProgramExports = 'program_exports';
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
            self::ClassTools, self::TsfReferential => FeatureFamily::Pedagogy,

            self::Timetable, self::TimetableSettings, self::EvaluationPlanning,
            self::GradebookEntry, self::GradebookStudent, self::SelfAssessment,
            self::ProgramReporting, self::ProgramExports, self::ProgramFinancial,
            self::Directory => FeatureFamily::Schooling,

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
     * Exactly one today - the Courrier école, whose switch is Program::$schoolMailEnabled next to
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
            self::Timetable => 'featureTimetableLabel',
            self::TimetableSettings => 'featureTimetableSettingsLabel',
            self::EvaluationPlanning => 'featureEvaluationPlanningLabel',
            self::GradebookEntry => 'featureGradebookEntryLabel',
            self::GradebookStudent => 'featureGradebookStudentLabel',
            self::SelfAssessment => 'featureSelfAssessmentLabel',
            self::ProgramReporting => 'featureProgramReportingLabel',
            self::ProgramExports => 'featureProgramExportsLabel',
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
     * The values below are the ones of §4, applied by the lot 5 migration. Lot 1 seeded everything
     * to ON so that no screen changed before the occurrences of §7.3 had been dealt with.
     */
    public function defaultForRoles(): bool
    {
        return match ($this) {
            // Pédagogie - what the platform is for, mostly on.
            self::LessonLog, self::CourseSpace, self::FileLibrary, self::SharedDocuments,
            self::ContentSharing, self::Documentation => false,

            // Scolarité - the establishment keeps all of this elsewhere.
            self::Timetable, self::TimetableSettings, self::GradebookEntry,
            self::GradebookStudent, self::SelfAssessment, self::ProgramReporting,
            self::ProgramExports, self::ProgramFinancial, self::Directory => false,

            // Vie scolaire. `school_mail` is ON here and decided by the formation, which starts
            // closed everywhere (§12.1); `school_mail_supervision` is off on every role, which
            // leaves it to the admins while keeping it delegable to one person (§12.2).
            self::Agenda, self::Announcements, self::Messaging, self::SchoolMailSupervision,
            self::SignupLists, self::Help => false,

            // Technique - the same treatment as the supervision screen: off everywhere, so admins
            // only, but still delegable through an individual override. `eco` is off here too and
            // seeded ON for ROLE_ECO alone - see defaultForRole().
            self::Infrastructure, self::GuestConsole, self::Eco, self::ActivityHistory => false,

            default => true,
        };
    }

    /**
     * The seed for one (feature, role) pair. Only used by the seeding migration; the resolver
     * reads the stored matrix and falls back on defaultForRoles() alone.
     *
     * It exists for the single exception of §4: e-CO is off for everybody *except* `ROLE_ECO`,
     * which is what that role is for.
     */
    public function defaultForRole(string $role): bool
    {
        if (self::Eco === $this) {
            return 'ROLE_ECO' === $role;
        }

        return $this->defaultForRoles();
    }
}
