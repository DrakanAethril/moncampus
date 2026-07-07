<?php

namespace App\Service;

use App\Entity\InternshipTutorLink;
use App\Entity\Period;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\InternshipSkillGroupRepository;
use App\Repository\InternshipSkillLevelRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\PeriodRepository;
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
        private readonly InternshipSkillGroupRepository $skillGroupRepository,
        private readonly InternshipSkillLevelRepository $skillLevelRepository,
        private readonly PeriodRepository $periodRepository,
        private readonly InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly InternshipTeamEvaluationRepository $teamEvaluationRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(InternshipTutorLink $tutorLink): array
    {
        $program = $tutorLink->getProgram();
        $student = $tutorLink->getStudent();

        // Read-only: derived from the program's own Topics, same grouping as
        // ProgramInternshipController::teamTab() - kept here too since the booklet needs it
        // without going through that staff-only controller.
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
            'programInfo' => $this->programInfoRepository->findOneByProgram($program),
            'topicsByTeacher' => $topicsByTeacher,
            'behaviorCriteria' => $this->behaviorCriteriaRepository->findAllActive(),
            'skillGroups' => $this->skillGroupRepository->findAllActiveForProgram($program),
            'skillLevels' => $this->skillLevelRepository->findAllActive(),
            'periods' => $periods,
        ];
    }
}
