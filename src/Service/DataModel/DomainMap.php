<?php

declare(strict_types=1);

namespace App\Service\DataModel;

/**
 * Groups entities by functional domain so the diagrams stay readable: 150 entities on one drawing
 * is a wall, 8 to 20 per domain is a document. The lists mirror the navigation's domain map; they
 * are curated, but the grouping cannot silently rot: an entity named nowhere lands in the "other"
 * group instead of disappearing, and a name that no longer matches an entity is dropped.
 */
final class DomainMap
{
    public const string FALLBACK = 'other';

    private const array DOMAINS = [
        'structure' => [
            'Section', 'Track', 'Cohort', 'Option', 'Modality', 'SchoolYear', 'User',
            'Group', 'GroupBatch', 'GroupType', 'MagicLoginToken',
            'LdapComputer', 'LdapManageGroup', 'LdapManagePassword', 'LdapManageUser', 'LdapService',
        ],
        'program' => [
            'Program', 'ProgramContractModality', 'ProgramFinancialItem', 'ProgramLessonTypeCost',
            'ProgramPeriodGroup', 'ProgramReferentTeacherOption', 'ProgramReport',
            'ProgramStudentModality', 'ProgramStudentOption', 'ProgramTeacherOption',
            'Period', 'PeriodGroup', 'PeriodType',
        ],
        'timetable' => [
            'LessonSession', 'Room', 'LessonType',
            'LessonLog', 'LessonLogAttachment', 'LessonLogAttachmentView',
            'SelfAssessment', 'SelfAssessmentAnswer',
        ],
        'assignment' => [
            'Assignment', 'AssignmentAttachment', 'AssignmentCompletion', 'AssignmentDismissal',
            'AssignmentExpectedProduction', 'AssignmentSubmission', 'AssignmentSubmissionFile', 'AssignmentView',
        ],
        'gradebook' => [
            'Evaluation', 'EvaluationPeriod', 'EvaluationPeriodGroup',
            'EvaluationRubricQuestion', 'EvaluationRubricSection', 'Grade', 'GradeRubricAnswer',
            'Topic', 'TopicGroup', 'Skill', 'SkillGroup', 'SkillLevel',
        ],
        'library' => [
            'SequenceTemplate', 'SequenceInstance', 'SeanceTemplate', 'SeanceInstance',
            'SeancePhaseTemplate', 'SeancePhaseInstance',
            'LibraryResource', 'LibraryResourceInstance',
            'LibraryBlocTag', 'LibraryNiveauTag', 'LibraryOptionTag',
            'Progression', 'ProgressionSeance', 'ProgressionSeancePlacement', 'ProgressionSequence',
            'AudioRecording', 'AudioRecordingFile', 'AudioListenProgress',
        ],
        'quiz' => [
            'QuizTemplate', 'QuizQuestion', 'QuizQuestionDefinition', 'QuizAnswer',
            'QuizInstance', 'QuizInstanceQuestion', 'QuizInstanceAnswer',
            'QuizAttempt', 'QuizAttemptAnswer', 'QuizAttemptSelectedAnswer',
            'QuizLiveSession', 'QuizLiveParticipant', 'QuizLiveAnswer',
        ],
        'ufa' => [
            'Enterprise', 'ContractType',
            'InternshipBehaviorCriteria', 'InternshipBehaviorLevel', 'InternshipEvaluationPeriod',
            'InternshipFormationCenter', 'InternshipLivretEngagement', 'InternshipOptionExamModality',
            'InternshipOptionLegalName', 'InternshipProgramInfo', 'InternshipReminder',
            'InternshipStudentEvaluation', 'InternshipSupervisorEvaluation', 'InternshipTeamEvaluation',
            'InternshipTutorEvaluation', 'InternshipTutorEvaluationBehavior', 'InternshipTutorEvaluationSkill',
            'InternshipTutorLink',
            'Laptop', 'LaptopConditionType', 'LaptopLoan', 'UfaActivity',
        ],
        'jobsearch' => [
            'JobSearch', 'JobSearchNote', 'JobApplication',
            'TrainingOffer', 'TrainingApplication', 'TrainingApplicationAttachment',
            'TrainingApplicationReview', 'TrainingApplicationVersion',
        ],
        'mail' => [
            'EmailAlias', 'EmailAttachment', 'EmailEvent', 'EmailMessage', 'EmailSuppression',
            'SuppressedEmailAddress', 'SchoolMailDraft', 'SchoolMailSignature',
        ],
        'communication' => [
            'MessageThread', 'Message', 'MessageAttachment', 'MessageThreadRecipient',
            'Announcement', 'AgendaEvent', 'SignupList', 'SignupListAttachment', 'SignupListRegistration',
        ],
        'eco' => [
            'EcoCourse', 'EcoParcours', 'EcoCheckpoint', 'EcoCheckpointScan',
            'EcoRunner', 'EcoTeam', 'EcoPositionPing', 'EcoAppEvent',
        ],
        'support' => [
            'Ticket', 'TicketCategory', 'TicketComment', 'HelpSection', 'HelpArticle', 'PlatformActivity',
        ],
    ];

    /**
     * @return array<string, list<string>> domain key => entity short names actually present
     */
    public function domains(DataModel $model): array
    {
        $groups = [];
        $grouped = [];
        foreach (self::DOMAINS as $key => $names) {
            $present = array_values(array_filter($names, static fn (string $name): bool => isset($model->entities[$name])));
            if ([] !== $present) {
                $groups[$key] = $present;
                $grouped += array_flip($present);
            }
        }

        $rest = array_values(array_diff(array_keys($model->entities), array_keys($grouped)));
        if ([] !== $rest) {
            $groups[self::FALLBACK] = $rest;
        }

        return $groups;
    }
}
