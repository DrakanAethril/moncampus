<?php

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipProgramInfo;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\SkillGroup;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillLevelRepository;
use App\Repository\TopicRepository;

/**
 * Assembles the full Livret Alternant booklet view data for one InternshipTutorLink - shared by
 * the staff, student, and tutor "view booklet" routes so the aggregation logic (team grouping,
 * per-period evaluation lookup) isn't duplicated three times.
 */
class InternshipBookletBuilder
{
    public function __construct(
        private readonly InternshipFormationCenterRepository $formationCenterRepository,
        private readonly InternshipProgramInfoRepository $programInfoRepository,
        private readonly TopicRepository $topicRepository,
        private readonly InternshipBehaviorCriteriaRepository $behaviorCriteriaRepository,
        private readonly SkillGroupRepository $skillGroupRepository,
        private readonly SkillLevelRepository $skillLevelRepository,
        private readonly PeriodRepository $periodRepository,
        private readonly InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        private readonly InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly InternshipTeamEvaluationRepository $teamEvaluationRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
        private readonly InternshipOptionExamModalityRepository $optionExamModalityRepository,
        private readonly InternshipOptionLegalNameRepository $optionLegalNameRepository,
        private readonly InternshipCalendarBuilder $calendarBuilder,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(InternshipTutorLink $tutorLink): array
    {
        $program = $tutorLink->getProgram();
        $student = $tutorLink->getStudent();

        $studentOptions = $this->studentOptionRepository->findOptionsForStudent($program, $student);
        $studentOptionIds = array_map(static fn (Option $option): int => $option->getId(), $studentOptions);

        $skillGroups = array_values(array_filter(
            $this->skillGroupRepository->findAllActiveForProgram($program),
            static fn (SkillGroup $group): bool => $group->isVisibleInBooklet() && $group->isVisibleForStudentOptions($studentOptionIds),
        ));

        $programInfo = $this->programInfoRepository->findOneByProgram($program);
        $examModalitiesByOptionId = $this->optionExamModalityRepository->findMapForProgram($program);
        $programLegalName = $this->resolveLegalName($program, $programInfo, $studentOptions);

        // One block per Option the student actually has, its own override text if set, else the
        // program-wide default; a student with no Options (the common case for a Program that
        // doesn't use them at all) just gets the one program-wide block.
        $examModalities = [] === $studentOptions
            ? [['option' => null, 'text' => $programInfo?->getExamModalityText()]]
            : array_map(
                static fn (Option $option): array => [
                    'option' => $option,
                    'text' => $examModalitiesByOptionId[$option->getId()] ?? $programInfo?->getExamModalityText(),
                ],
                $studentOptions,
            );

        // Read-only: derived from the program's own Topics, same grouping as
        // ProgramTimetableSettingsController::teamTab() - kept here too since the booklet needs
        // it without going through that staff-only controller.
        $topicsByTeacher = [];
        foreach ($this->topicRepository->findAllActiveForProgram($program) as $topic) {
            $teacher = $topic->getTeacher();
            $key = $teacher?->getId() ?? 0;

            if (!isset($topicsByTeacher[$key])) {
                $topicsByTeacher[$key] = ['teacher' => $teacher, 'topics' => []];
            }

            $topicsByTeacher[$key]['topics'][] = $topic;
        }

        // Two independent notions of "period" feed this booklet: $rawPeriods is the alternance
        // calendar (classroom vs. company weeks, used only for the calendar visualization below),
        // while $evaluationPeriods is what the tutor/student/team evaluations are actually keyed
        // on - see InternshipEvaluationPeriod's docblock for why these were split apart.
        $rawPeriods = $this->periodRepository->findAllActiveForProgram($program);

        $periods = array_map(
            fn (InternshipEvaluationPeriod $evaluationPeriod): array => [
                'period' => $evaluationPeriod,
                'tutorEvaluation' => $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $evaluationPeriod),
                'studentEvaluation' => $this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $evaluationPeriod),
                'teamEvaluation' => $this->teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $evaluationPeriod),
            ],
            $this->evaluationPeriodRepository->findAllActiveForProgram($program),
        );

        $schoolYear = $program->getSchoolYear();

        return [
            'tutorLink' => $tutorLink,
            'program' => $program,
            'student' => $student,
            'formationCenter' => $this->formationCenterRepository->findSingleton(),
            'programInfo' => $programInfo,
            'programLegalName' => $programLegalName,
            'examModalities' => $examModalities,
            'topicsByTeacher' => $topicsByTeacher,
            'behaviorCriteria' => $this->behaviorCriteriaRepository->findAllActive(),
            'skillGroups' => $skillGroups,
            'skillLevels' => $this->skillLevelRepository->findAllActiveForProgramOrGlobal($program),
            'periods' => $periods,
            'calendarMonths' => null !== $schoolYear ? $this->calendarBuilder->build($schoolYear, $rawPeriods) : [],
            'calendarLegend' => $this->calendarBuilder->buildLegend($rawPeriods),
        ];
    }

    // Cover-page name shown for this alternant: a student with exactly one Option gets that
    // Option's override if set (InternshipOptionLegalName), otherwise - and always for a student
    // with zero or several Options - the program-wide default (InternshipProgramInfo::$legalName,
    // itself falling back to Program::$name). Same "resolve per the student's own Options" shape
    // as the exam modalities above, but collapsed to a single value rather than one block per
    // Option, since a booklet only ever shows one name.
    /** @param list<Option> $studentOptions */
    private function resolveLegalName(Program $program, ?InternshipProgramInfo $programInfo, array $studentOptions): string
    {
        $defaultName = $programInfo?->getLegalName() ?: $program->getName();

        if (1 !== \count($studentOptions)) {
            return $defaultName;
        }

        $override = $this->optionLegalNameRepository->findOneForProgramAndOption($program, $studentOptions[0]);

        return $override?->getLegalName() ?? $defaultName;
    }
}
