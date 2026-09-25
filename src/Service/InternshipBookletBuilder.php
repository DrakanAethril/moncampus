<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipProgramInfo;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Program;
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
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SkillLevelRepository;

/**
 * Assembles the full Livret Alternant booklet view data for one InternshipTutorLink - shared by
 * the staff, student, and tutor "view booklet" routes so the aggregation logic (options
 * filtering, per-period evaluation lookup) isn't duplicated three times.
 *
 * @phpstan-import-type BookletOutlineEntry from BookletOutline
 */
class InternshipBookletBuilder
{
    public function __construct(
        private readonly InternshipFormationCenterRepository $formationCenterRepository,
        private readonly InternshipLivretEngagementRepository $engagementRepository,
        private readonly InternshipProgramInfoRepository $programInfoRepository,
        private readonly InternshipBehaviorCriteriaRepository $behaviorCriteriaRepository,
        private readonly BookletSkillGroups $bookletSkillGroups,
        private readonly SkillLevelRepository $skillLevelRepository,
        private readonly PeriodRepository $periodRepository,
        private readonly InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        private readonly InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
        private readonly InternshipOptionExamModalityRepository $optionExamModalityRepository,
        private readonly InternshipOptionLegalNameRepository $optionLegalNameRepository,
        private readonly InternshipCalendarBuilder $calendarBuilder,
        private readonly FileUploadService $fileUploadService,
        private readonly BookletContractModalities $contractModalities,
        private readonly BookletOutline $outline,
    ) {
    }

    /**
     * The booklet's outline alone, for the reader's menu - see App\Service\BookletOutline.
     *
     * @return list<BookletOutlineEntry>
     */
    public function outline(InternshipTutorLink $tutorLink): array
    {
        return $this->outlineOf($tutorLink, $this->contractModalities->forTutorLink($tutorLink));
    }

    /** @return array<string, mixed> */
    public function build(InternshipTutorLink $tutorLink): array
    {
        $program = $tutorLink->getProgram();
        $student = $tutorLink->getStudent();

        $studentOptions = $this->studentOptionRepository->findOptionsForStudent($program, $student);
        $studentOptionIds = array_map(static fn (Option $option): int => $option->getId(), $studentOptions);

        $skillGroups = $this->bookletSkillGroups->forTutorLink($tutorLink);

        $programInfo = $this->programInfoRepository->findOneByProgram($program);
        $contractModalities = $this->contractModalities->forTutorLink($tutorLink);
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

        // "Equipe pédagogique" (I.4): the lines written in UFA > Formations > « Équipe » that concern
        // this alternant - common to every option, or one of their own - alphabetically by matière.
        // Free text, deliberately unrelated to the formation's matières and timetable: see
        // App\Service\TeachingTeam.
        $teamRows = TeachingTeam::forBooklet($programInfo?->getTeachingTeam() ?? [], $studentOptionIds);

        // Two independent notions of "period" feed this booklet: $rawPeriods is the alternance
        // calendar (classroom vs. company weeks, used only for the calendar visualization below),
        // while $evaluationPeriods is what the tutor/student/team evaluations are actually keyed
        // on - see InternshipEvaluationPeriod's docblock for why these were split apart.
        $rawPeriods = $this->periodRepository->findAllActiveForProgram($program);

        // Each contribution appears as soon as it is SIGNED by its author, without waiting for the
        // period to be closed by the follow-up officer. It was that closing the booklet used to
        // require ("the booklet fills in as the follow-up meetings go", doc plan §7): a tutor who had
        // just sent their report found it nowhere. The signature is the right boundary - it is the
        // act by which a role hands over - where closing made everyone wait for the last one.
        //
        // A draft saved without a signature (the follow-up officer's "Enregistrer cette étape")
        // therefore stays out of the booklet, and out of its PDF export.
        //
        // None of this is stored: the filter applies on reading, so that what is already in the
        // database reappears by itself as soon as it is signed. The booklet says nothing about the
        // closing of the period itself: $supervisorEvaluation is still passed to the template for
        // whoever might need it.
        $periods = array_map(
            function (InternshipEvaluationPeriod $evaluationPeriod) use ($tutorLink, $student): array {
                $supervisorEvaluation = $this->supervisorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $evaluationPeriod);
                $tutorEvaluation = $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $evaluationPeriod);
                $studentEvaluation = $this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $evaluationPeriod);

                return [
                    'period' => $evaluationPeriod,
                    'tutorEvaluation' => $tutorEvaluation?->isSigned() ? $tutorEvaluation : null,
                    'studentEvaluation' => $studentEvaluation?->isSigned() ? $studentEvaluation : null,
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

        // "Emploi du temps" section II.2: unlike the calendar there is no mode to consult - the
        // document deposited in UFA > Formations > Documents is the whole condition. A formation
        // that has none keeps the booklet it has always had, with the exam modalities as II.2.
        $timetableFileKey = $program->getTimetableDocumentFileKey();

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
            // Sections 5, 6... of chapter I, or null when this contract type has no text anywhere.
            'contractModalities' => $contractModalities,
            'outline' => $this->outlineOf($tutorLink, $contractModalities),
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
            // Same pair as the calendar's, and for the same reason: the key is what
            // InternshipBookletPdfExporter merges into the exported PDF, the url what the on-screen
            // booklet embeds. Both null when the formation deposited no timetable, which is what
            // the template reads to decide whether the section exists at all.
            'timetableFileKey' => $timetableFileKey,
            'timetableFileUrl' => null !== $timetableFileKey ? $this->fileUploadService->url($timetableFileKey) : null,
        ];
    }

    /** @return list<BookletOutlineEntry> */
    private function outlineOf(InternshipTutorLink $tutorLink, ?BookletFreeText $contractModalities): array
    {
        $program = $tutorLink->getProgram();

        return $this->outline->entries(
            $contractModalities->sections ?? [],
            null !== $program?->getTimetableDocumentFileKey(),
            null === $program ? [] : array_map(
                static fn (InternshipEvaluationPeriod $period): string => $period->getName(),
                $this->evaluationPeriodRepository->findAllActiveForProgram($program),
            ),
        );
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
