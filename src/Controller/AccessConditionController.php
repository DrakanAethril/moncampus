<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccessConditionHost;
use App\Entity\Assignment;
use App\Entity\LibraryResourceInstance;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\SequenceInstance;
use App\Enum\AccessConditionDisplay;
use App\Enum\AccessConditionType;
use App\Repository\AssignmentRepository;
use App\Repository\LibraryResourceInstanceRepository;
use App\Repository\QuizInstanceRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Service\AccessConditionCycleDetector;
use App\Service\AccessConditionGraph;
use App\Service\AccessConditionHostKey;
use App\Service\AccessConditionLeaf;
use App\Service\AccessConditionOptions;
use App\Service\AccessConditionTree;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One screen for the four hosts - see design/comparaison/creas_espace_cours_conditions.html, écran
 * 4. A travail, a séquence, a ressource and a quiz all answer the same two questions ("l'accès est
 * ouvert quand…" and "tant que les conditions ne sont pas remplies"), so they share one form, one
 * route and one save, rather than four of each drifting apart.
 *
 * Teachers of the class and staff only: the condition is a pedagogical intention, and the student
 * side of it is read through AccessConditionGate, never here.
 */
class AccessConditionController extends AbstractController
{
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly QuizInstanceRepository $quizInstanceRepository,
        private readonly LibraryResourceInstanceRepository $resourceRepository,
        private readonly SequenceInstanceRepository $sequenceRepository,
        private readonly StructureAccessChecker $accessChecker,
    ) {
    }

    #[Route(path: '/access-conditions/{type}/{id}', name: 'app_access_condition_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(string $type, int $id, AccessConditionOptions $options, TranslatorInterface $translator): Response
    {
        $host = $this->hostOrDeny($type, $id);
        $program = $host->getAccessConditionProgram() ?? throw $this->createNotFoundException();
        $tree = $host->getAccessConditionTree();

        return $this->render('access_condition/edit.html.twig', [
            'program' => $program,
            'host' => $host,
            'hostType' => $type,
            'hostTypeLabelKey' => $this->hostTypeLabelKey($host),
            'tree' => $tree,
            'conditions' => array_map(self::rowOf(...), null === $tree ? [] : $tree->leaves),
            'options' => $options->forProgram($program, $host),
            'types' => AccessConditionType::forPicker(),
            // The screen draws its own rows, so it needs the type names the same way it needs the
            // object names - as data, not as markup it would have to keep in step with the enum.
            'conditionLabels' => array_reduce(
                AccessConditionType::cases(),
                static fn (array $labels, AccessConditionType $one): array => $labels + [$one->value => $translator->trans($one->labelKey())],
                [],
            ),
            // Which types name an object, straight off the enum: the screen decides whether to show
            // the object select from this, so the two cannot drift the way a hardcoded list would.
            'needsTarget' => array_reduce(
                AccessConditionType::cases(),
                static fn (array $needs, AccessConditionType $one): array => $needs + [$one->value => null !== $one->targetKey()],
                [],
            ),
            'builderMessages' => [
                'emptyTarget' => $translator->trans('accessConditionNoTargetAvailableMessage'),
                'emptyTargetNote' => $translator->trans('accessConditionNoTargetAvailableNote'),
                'missingTarget' => $translator->trans('accessConditionTargetRequiredMessage'),
                'missingDate' => $translator->trans('accessConditionDateRequiredMessage'),
            ],
            'displays' => AccessConditionDisplay::cases(),
            'backUrl' => $this->backUrl($host, $program),
            'parentUrl' => $this->parentUrl($host, $program),
        ]);
    }

    /**
     * A stored leaf as the form holds it: the object always under "target", the date in the format
     * an <input type="datetime-local"> reads back.
     *
     * @return array{type: string, target: int|null, min_percent: int|null, max_percent: int|null, at: string|null, moment: string}
     */
    private static function rowOf(AccessConditionLeaf $leaf): array
    {
        return [
            'type' => $leaf->type->value,
            'target' => $leaf->targetId,
            'min_percent' => $leaf->minPercent,
            'max_percent' => $leaf->maxPercent,
            'at' => $leaf->at?->format('Y-m-d\TH:i'),
            'moment' => $leaf->moment->value,
        ];
    }

    /**
     * The whole condition is replaced at once: the form posts the list it holds, so a leaf removed
     * on screen is a leaf absent here. Saving an empty list clears the condition, which is the only
     * way to open an object back up and has to stay a one-click gesture.
     */
    #[Route(path: '/access-conditions/{type}/{id}', name: 'app_access_condition_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(
        string $type,
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        AccessConditionGraph $graph,
        AccessConditionCycleDetector $detector,
        SeanceInstanceRepository $seanceRepository,
        TranslatorInterface $translator,
    ): Response {
        $host = $this->hostOrDeny($type, $id);
        $program = $host->getAccessConditionProgram() ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('access-condition-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $rows = array_map(
            static fn (JsonRequestPayload $row): array => $row->toArray(),
            JsonRequestPayload::listFromJson((string) $request->request->get('conditions', '[]')),
        );
        $tree = AccessConditionTree::fromSubmitted((string) $request->request->get('mode', 'all'), $rows);

        // A row that describes no leaf - no object where one is required, an unparsable date - is
        // dropped on the way in. Dropping it and reporting a success is how a screen comes to say it
        // saved a condition it did not: the whole save is refused instead, leaving what was stored
        // untouched.
        if (\count($rows) !== (null === $tree ? 0 : \count($tree->leaves))) {
            $this->addFlash('danger', $translator->trans('accessConditionInvalidRefusedMessage'));

            return $this->redirectToRoute('app_access_condition_edit', ['type' => $type, 'id' => $id]);
        }

        // A loop leaves two objects nobody can ever open, and no error anywhere to say why. Refused
        // at the save, which is the only moment somebody is there to read the refusal.
        if ($detector->wouldCycle(AccessConditionHostKey::of($host), AccessConditionGraph::dependenciesOf($tree), $graph->edges())) {
            $this->addFlash('danger', $translator->trans('accessConditionCycleRefusedMessage'));

            return $this->redirectToRoute('app_access_condition_edit', ['type' => $type, 'id' => $id]);
        }

        $host->setAccessConditionTree($tree);
        $host->setAccessConditionDisplay(AccessConditionDisplay::tryFrom((string) $request->request->get('display')) ?? AccessConditionDisplay::Locked);
        $entityManager->flush();

        $this->warnAboutUnscheduledSeances($tree, $seanceRepository, $translator);
        $this->addFlash('success', $translator->trans('accessConditionSavedMessage'));

        return $this->redirectToRoute('app_access_condition_edit', ['type' => $type, 'id' => $id]);
    }

    /**
     * "Avertir à l'enregistrement, sans refuser": a teacher is entitled to write the condition
     * before the séance is placed on the timetable, but a condition that cannot open yet has to say
     * so - an error that is visible beats one that is silent.
     */
    private function warnAboutUnscheduledSeances(?AccessConditionTree $tree, SeanceInstanceRepository $seanceRepository, TranslatorInterface $translator): void
    {
        $ids = [];
        foreach (null === $tree ? [] : $tree->leaves as $leaf) {
            if (AccessConditionType::SeancePassed === $leaf->type && null !== $leaf->targetId) {
                $ids[] = $leaf->targetId;
            }
        }

        foreach ($seanceRepository->findWithSlotByIds($ids) as $seance) {
            if (null === $seance->getLessonSession()) {
                $this->addFlash('warning', $translator->trans('accessConditionSeanceUnscheduledWarning', ['%name%' => (string) $seance->getTitre()]));
            }
        }
    }

    private function hostOrDeny(string $type, int $id): AccessConditionHost
    {
        $host = match ($type) {
            AccessConditionHostKey::ASSIGNMENT => $this->assignmentRepository->find($id),
            AccessConditionHostKey::QUIZ_INSTANCE => $this->quizInstanceRepository->find($id),
            AccessConditionHostKey::RESOURCE => $this->resourceRepository->find($id),
            AccessConditionHostKey::SEQUENCE => $this->sequenceRepository->find($id),
            default => null,
        };

        if (!$host instanceof AccessConditionHost) {
            throw $this->createNotFoundException();
        }

        $program = $host->getAccessConditionProgram();

        if (null === $program || !($this->accessChecker->isStaff() || $this->accessChecker->isProgramTeacher($program))) {
            throw $this->createAccessDeniedException();
        }

        return $host;
    }

    private function hostTypeLabelKey(AccessConditionHost $host): string
    {
        return match (true) {
            $host instanceof Assignment => 'accessConditionHostAssignmentLabel',
            $host instanceof QuizInstance => 'accessConditionHostQuizLabel',
            $host instanceof LibraryResourceInstance => 'accessConditionHostResourceLabel',
            default => 'accessConditionHostSequenceLabel',
        };
    }

    /** Where "Annuler" goes back to: the screen the condition was reached from. */
    private function backUrl(AccessConditionHost $host, Program $program): string
    {
        if ($host instanceof Assignment) {
            return $this->generateUrl('app_program_assignments_show', ['id' => $program->getId(), 'assignmentId' => $host->getId()]);
        }

        if ($host instanceof QuizInstance) {
            return $this->generateUrl('app_program_quiz_show', ['id' => $program->getId(), 'instanceId' => $host->getId()]);
        }

        $sequence = $host instanceof SequenceInstance ? $host : $this->sequenceOf($host);

        return null === $sequence
            ? $this->generateUrl('app_program_sequences', ['id' => $program->getId()])
            : $this->generateUrl('app_program_sequences_show', ['id' => $program->getId(), 'sequenceInstanceId' => $sequence->getId()]);
    }

    /** The list the object belongs to - the breadcrumb's class-level step. */
    private function parentUrl(AccessConditionHost $host, Program $program): string
    {
        return match (true) {
            $host instanceof Assignment => $this->generateUrl('app_program_assignments', ['id' => $program->getId()]),
            $host instanceof QuizInstance => $this->generateUrl('app_program_quiz', ['id' => $program->getId()]),
            default => $this->generateUrl('app_program_sequences', ['id' => $program->getId()]),
        };
    }

    /** A resource hangs off a séquence, a séance or a phase; only the first case is direct. */
    private function sequenceOf(AccessConditionHost $host): ?SequenceInstance
    {
        if (!$host instanceof LibraryResourceInstance) {
            return null;
        }

        return $host->getSequenceInstance()
            ?? $host->getSeanceInstance()?->getSequenceInstance()
            ?? $host->getSeancePhaseInstance()?->getSeanceInstance()?->getSequenceInstance();
    }
}
