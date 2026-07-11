<?php

namespace App\Service;

use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Period;
use App\Entity\SkillGroup;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\InternshipSkillLevelRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SkillGroupRepository;
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
        private readonly InternshipSkillLevelRepository $skillLevelRepository,
        private readonly PeriodRepository $periodRepository,
        private readonly InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly InternshipTeamEvaluationRepository $teamEvaluationRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
        private readonly InternshipOptionExamModalityRepository $optionExamModalityRepository,
        private readonly FileUploadService $fileUploadService,
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
            $this->skillGroupRepository->findAllActiveForProgramOrGlobal($program),
            static fn (SkillGroup $group): bool => $group->isVisibleInBooklet() && $group->isVisibleForStudentOptions($studentOptionIds),
        ));

        $programInfo = $this->programInfoRepository->findOneByProgram($program);
        $examModalitiesByOptionId = $this->optionExamModalityRepository->findMapForProgram($program);

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

        $periods = array_map(
            fn (Period $period): array => [
                'period' => $period,
                'tutorEvaluation' => $this->tutorEvaluationRepository->findOneForTutorLinkAndPeriod($tutorLink, $period),
                'studentEvaluation' => $this->studentEvaluationRepository->findOneForStudentAndPeriod($student, $period),
                'teamEvaluation' => $this->teamEvaluationRepository->findOneForStudentAndPeriod($student, $period),
            ],
            $this->periodRepository->findAllActive(),
        );

        return [
            'tutorLink' => $tutorLink,
            'program' => $program,
            'student' => $student,
            'formationCenter' => $this->formationCenterRepository->findSingleton(),
            'programInfo' => $programInfo,
            'examModalities' => $examModalities,
            'topicsByTeacher' => $topicsByTeacher,
            'behaviorCriteria' => $this->behaviorCriteriaRepository->findAllActive(),
            'skillGroups' => $skillGroups,
            'skillLevels' => $this->skillLevelRepository->findAllActive(),
            'periods' => $periods,
            'coverPage' => $this->resolveProgramInfoAsset($programInfo?->getCoverPageKey()),
            'calendar' => $this->resolveProgramInfoAsset($programInfo?->getCalendarKey()),
        ];
    }

    private function resolveProgramInfoAsset(?string $key): ?ProgramInfoAsset
    {
        if (null === $key) {
            return null;
        }

        $isPdf = str_ends_with(strtolower($key), '.pdf');

        return new ProgramInfoAsset($key, $isPdf, $isPdf ? null : $this->fileUploadService->url($key));
    }
}
