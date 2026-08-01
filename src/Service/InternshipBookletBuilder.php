<?php

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipProgramInfo;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\SkillGroup;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Enum\ProgramAlternanceCalendarMode;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillLevelRepository;
use App\Repository\TopicGroupRepository;
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
        private readonly InternshipLivretEngagementRepository $engagementRepository,
        private readonly InternshipProgramInfoRepository $programInfoRepository,
        private readonly TopicRepository $topicRepository,
        private readonly TopicGroupRepository $topicGroupRepository,
        private readonly InternshipBehaviorCriteriaRepository $behaviorCriteriaRepository,
        private readonly SkillGroupRepository $skillGroupRepository,
        private readonly SkillLevelRepository $skillLevelRepository,
        private readonly PeriodRepository $periodRepository,
        private readonly InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        private readonly InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly InternshipTeamEvaluationRepository $teamEvaluationRepository,
        private readonly InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
        private readonly InternshipOptionExamModalityRepository $optionExamModalityRepository,
        private readonly InternshipOptionLegalNameRepository $optionLegalNameRepository,
        private readonly InternshipCalendarBuilder $calendarBuilder,
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

        // "Equipe pédagogique" (I.4): one row per active TopicGroup, alphabetically (the
        // repository's own order), facing the teacher who answers for that group.
        $topicsByGroupId = [];
        foreach ($this->topicRepository->findAllActiveForProgram($program) as $topic) {
            $topicsByGroupId[$topic->getTopicGroup()?->getId() ?? 0][] = $topic;
        }

        $teamRows = array_map(
            fn (TopicGroup $topicGroup): array => [
                'topicGroup' => $topicGroup,
                // TopicGroup::$teacher is the answer whenever staff set one; otherwise the group
                // is represented by one of the teachers of its own subjects - see
                // resolveTopicGroupTeacher() for which one and why.
                'teacher' => $topicGroup->getTeacher()
                    ?? $this->resolveTopicGroupTeacher($topicsByGroupId[$topicGroup->getId()] ?? []),
            ],
            $this->topicGroupRepository->findAllActiveForProgram($program),
        );

        // Two independent notions of "period" feed this booklet: $rawPeriods is the alternance
        // calendar (classroom vs. company weeks, used only for the calendar visualization below),
        // while $evaluationPeriods is what the tutor/student/team evaluations are actually keyed
        // on - see InternshipEvaluationPeriod's docblock for why these were split apart.
        $rawPeriods = $this->periodRepository->findAllActiveForProgram($program);

        // Chaque contribution paraît dès qu'elle est saisie, sans attendre la clôture de la
        // période par le chargé de suivi. Ce filtre-là existait ("le livret se remplit au fil des
        // points de suivi", plan doc §7, avec l'idée qu'un brouillon ne devait pas fuiter à
        // l'impression) mais il cachait aussi des bilans bel et bien signés : un tuteur qui venait
        // de transmettre le sien ne le retrouvait nulle part dans le livret. Rien n'était perdu
        // pour autant - le filtre s'appliquait à la lecture, pas à l'enregistrement - donc lever
        // la condition fait réapparaître d'un coup tout l'existant.
        //
        // $isClosed reste calculé : le livret continue de dire si la période est clôturée.
        $periods = array_map(
            function (InternshipEvaluationPeriod $evaluationPeriod) use ($tutorLink, $student): array {
                $supervisorEvaluation = $this->supervisorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $evaluationPeriod);

                return [
                    'period' => $evaluationPeriod,
                    'isClosed' => $supervisorEvaluation?->isClosed() ?? false,
                    'tutorEvaluation' => $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $evaluationPeriod),
                    'studentEvaluation' => $this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $evaluationPeriod),
                    'teamEvaluation' => $this->teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $evaluationPeriod),
                    'supervisorEvaluation' => $supervisorEvaluation,
                ];
            },
            $this->evaluationPeriodRepository->findAllActiveForProgram($program),
        );

        $startDate = $program->getEffectiveStartDate();
        $endDate = $program->getEffectiveEndDate();

        // "Calendrier d'alternance" section II.1: when the Program says its calendar IS an
        // uploaded PDF (ProgramAlternanceCalendarMode::File, same switch the nav's calendar entry
        // honours in ProgramController::alternanceCalendarPdf()), that file replaces the section
        // outright - the month grid derived from the saisies periods is not generated at all,
        // rather than printed alongside or underneath it. Mode File with nothing actually
        // uploaded falls back to the generated grid, again matching that controller.
        $calendarFileKey = ProgramAlternanceCalendarMode::File === $program->getAlternanceCalendarMode()
            ? $program->getAlternanceCalendarFileKey()
            : null;

        return [
            'tutorLink' => $tutorLink,
            'program' => $program,
            'student' => $student,
            'formationCenter' => $this->formationCenterRepository->findSingleton(),
            // "Mise à disposition du livret": the 3 signatures already collected in the app, so
            // the booklet's own signature table reports them instead of printing blank boxes
            // under people who have signed. Null before anyone opens the engagement screen.
            'engagement' => $this->engagementRepository->findOneForTutorLink($tutorLink),
            'programInfo' => $programInfo,
            'programLegalName' => $programLegalName,
            'examModalities' => $examModalities,
            'teamRows' => $teamRows,
            'behaviorCriteria' => $this->behaviorCriteriaRepository->findAllActive(),
            'skillGroups' => $skillGroups,
            'skillLevels' => $this->skillLevelRepository->findAllActiveForProgramOrGlobal($program),
            'periods' => $periods,
            // Both null unless the uploaded-file mode is on: the key is what
            // InternshipBookletPdfExporter merges into the exported PDF, the url what the on-screen
            // booklet embeds in place of the grid.
            'calendarFileKey' => $calendarFileKey,
            'calendarFileUrl' => null !== $calendarFileKey ? $this->fileUploadService->url($calendarFileKey) : null,
            'calendarMonths' => (null === $calendarFileKey && null !== $startDate && null !== $endDate) ? $this->calendarBuilder->build($startDate, $endDate, $rawPeriods) : [],
            'calendarLegend' => null === $calendarFileKey ? $this->calendarBuilder->buildLegend($rawPeriods) : [],
        ];
    }

    /**
     * Who represents a TopicGroup that nobody was explicitly assigned to: one of the teachers of
     * its own subjects, the one covering the most of them - the closest thing to "the teacher of
     * this group" the data actually supports. Ties (and the common case of one subject each) are
     * broken alphabetically rather than left to row order, so the same booklet exported twice
     * never names two different people.
     *
     * Null when the group has no subjects, or none of them has a teacher: the row is printed with
     * an empty Formateur cell rather than dropped, since the group is still part of the
     * curriculum the alternant is shown.
     *
     * @param list<Topic> $topics the group's own active Topics
     */
    private function resolveTopicGroupTeacher(array $topics): ?User
    {
        /** @var array<int, array{teacher: User, count: int}> $byTeacherId */
        $byTeacherId = [];
        foreach ($topics as $topic) {
            $teacher = $topic->getTeacher();
            if (null === $teacher) {
                continue;
            }

            $id = $teacher->getId();
            $byTeacherId[$id] ??= ['teacher' => $teacher, 'count' => 0];
            ++$byTeacherId[$id]['count'];
        }

        if ([] === $byTeacherId) {
            return null;
        }

        usort($byTeacherId, static fn (array $a, array $b): int => $b['count'] <=> $a['count']
            ?: strcasecmp($a['teacher']->getDisplayName() ?? '', $b['teacher']->getDisplayName() ?? ''));

        return $byTeacherId[0]['teacher'];
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
