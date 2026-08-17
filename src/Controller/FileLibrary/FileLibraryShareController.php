<?php

declare(strict_types=1);

namespace App\Controller\FileLibrary;

use App\Entity\FileLibraryNode;
use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\SharedDocument;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\ProgramRepository;
use App\Repository\SharedDocumentRepository;
use App\Repository\TopicRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\FileLibraryVoter;
use App\Service\FileLibraryQuota;
use App\Service\FileLibraryTree;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Partager à une classe » - the entry the row menu carries just above « Créer un travail »
 * (mockup 1's kebab), and the screen behind it.
 *
 * It is a screen rather than a modal, and the reason is the form itself: a class, then a narrowing
 * by options *and/or* modalities, then a matière, then a visibility window. That does not fit an
 * overlay, and the choices below the class depend on the class - which a page can re-render and a
 * modal would have to fetch.
 *
 * **Every list this screen offers is rendered for every one of the teacher's classes at once**, and
 * a Stimulus controller shows the block belonging to the selected class. A teacher holds a handful
 * of classes, so the whole thing is a few dozen checkboxes; the alternative - an ajax round trip per
 * change of class - would buy nothing and could fail. What is submitted is still validated against
 * the chosen class, never trusted: see assertBelongs() below.
 *
 * There is deliberately **no plain POST straight from the menu**: sharing has four decisions in it,
 * and a one-click share would be a share to the wrong class.
 */
#[IsGranted(FileLibraryVoter::VIEW)]
#[Route(path: '/tools/file-library')]
class FileLibraryShareController extends AbstractController
{
    use FileLibraryTrait;

    public function __construct(
        private readonly FileLibraryNodeRepository $nodes,
        private readonly FileLibraryTree $tree,
        private readonly FileLibraryQuota $quota,
        private readonly SharedDocumentRepository $sharedDocuments,
        private readonly ProgramRepository $programs,
        private readonly TopicRepository $topics,
        private readonly StructureAccessChecker $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/{nodeId}/share', name: 'app_file_library_node_share', requirements: ['nodeId' => '\d+'], methods: ['GET'])]
    public function form(int $nodeId): Response
    {
        $node = $this->shareableNode($nodeId);
        $owner = $this->currentUser();

        return $this->render('file_library/share.html.twig', [
            'node' => $node,
            'targets' => $this->targetsFor($owner),
            'shares' => $this->sharedDocuments->findForNode($node),
            'ancestors' => $this->ancestorsOf($this->nodes, $node->getParent()),
            'currentFolder' => $node->getParent(),
            'rail' => $this->railTree($this->nodes, $this->tree, $owner),
            'quota' => $this->quotaBar($this->quota, $owner),
        ]);
    }

    #[Route(path: '/{nodeId}/share', name: 'app_file_library_node_share_save', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function share(Request $request, int $nodeId): Response
    {
        $node = $this->shareableNode($nodeId);
        $teacher = $this->currentUser();

        if (!$this->isCsrfTokenValid('shared_document', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException();
        }

        $program = $this->programOrFail(PostValue::nullableInt($request, 'program'), $teacher);

        if (null !== $this->sharedDocuments->findOneForNodeAndProgram($node, $teacher, $program)) {
            $this->addFlash('error', 'sharedDocumentAlreadySharedError');

            return $this->redirectToRoute('app_file_library_node_share', ['nodeId' => $nodeId]);
        }

        [$visibleFrom, $visibleUntil] = $this->submittedWindow($request);

        if (null !== $visibleFrom && null !== $visibleUntil && $visibleUntil <= $visibleFrom) {
            $this->addFlash('error', 'sharedDocumentWindowOrderError');

            return $this->redirectToRoute('app_file_library_node_share', ['nodeId' => $nodeId]);
        }

        $share = new SharedDocument($node, $teacher, $program);
        $share->setVisibleFrom($visibleFrom);
        $share->setVisibleUntil($visibleUntil);

        $topicId = PostValue::nullableInt($request, 'topic');

        if (null !== $topicId) {
            $topic = $this->topics->find($topicId);
            $share->setTopic(null === $topic ? null : $this->assertBelongs($topic, $this->topicsFor($program, $teacher)));
        }

        foreach (PostValue::intList($request, 'options') as $optionId) {
            foreach ($program->getOptions() as $option) {
                if ($option->getId() === $optionId) {
                    $share->addOption($option);
                }
            }
        }

        foreach (PostValue::intList($request, 'modalities') as $modalityId) {
            foreach ($program->getModalities() as $modality) {
                if ($modality->getId() === $modalityId) {
                    $share->addModality($modality);
                }
            }
        }

        $this->entityManager->persist($share);
        $this->entityManager->flush();

        $this->addFlash('success', 'sharedDocumentSharedFlash');

        return $this->redirectToRoute('app_file_library_node_share', ['nodeId' => $nodeId]);
    }

    /**
     * Withdrawing a share, from the list at the bottom of the same screen.
     *
     * The row goes rather than being marked: a document nobody was ever meant to receive should
     * leave no trace of having been offered, and the file itself is untouched either way - deleting
     * a share deletes a link, never bytes.
     */
    #[Route(path: '/shares/{shareId}/delete', name: 'app_file_library_share_delete', requirements: ['shareId' => '\d+'], methods: ['POST'])]
    public function unshare(Request $request, int $shareId): Response
    {
        $share = $this->sharedDocuments->find($shareId) ?? throw $this->createNotFoundException();
        $node = $share->getLibraryNode();
        $this->denyAccessUnlessGranted(FileLibraryVoter::EDIT, $node);

        if (!$this->isCsrfTokenValid('shared_document', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($share);
        $this->entityManager->flush();

        $this->addFlash('success', 'sharedDocumentUnsharedFlash');

        return $this->redirectToRoute('app_file_library_node_share', ['nodeId' => $node->getId()]);
    }

    /**
     * A folder is not shared, and neither is a file in the corbeille: both would put a row in front
     * of a student that resolves to nothing.
     */
    private function shareableNode(int $nodeId): FileLibraryNode
    {
        $node = $this->loadNode($this->nodes, $nodeId, FileLibraryVoter::EDIT);

        if (null === $node || !$node->isFile() || $node->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $node;
    }

    /**
     * What the screen renders one block per: the class, then everything that narrows a share inside
     * it.
     *
     * @return list<array{program: Program, topics: list<Topic>, options: list<Option>, modalities: list<Modality>}>
     */
    private function targetsFor(User $teacher): array
    {
        return array_map(
            fn (Program $program): array => [
                'program' => $program,
                'topics' => $this->topicsFor($program, $teacher),
                'options' => array_values($program->getOptions()->toArray()),
                'modalities' => array_values($program->getModalities()->toArray()),
            ],
            $this->programsFor($teacher),
        );
    }

    /**
     * The classes on offer - « celles de l'enseignant ». Staff, who also have a library, teach
     * nothing in the general case, so for them it is every active class of the current school year:
     * the same widening StructureAccessChecker::isStaff() grants everywhere else.
     *
     * @return list<Program>
     */
    private function programsFor(User $teacher): array
    {
        $taught = $this->programs->findAllForTeacher($teacher);

        if (!$this->access->isStaff()) {
            return $taught;
        }

        $byId = [];

        foreach ($taught as $program) {
            $byId[(int) $program->getId()] = $program;
        }

        foreach ($this->programs->findActiveForNav($teacher) as $program) {
            $byId[(int) $program->getId()] = $program;
        }

        $all = array_values($byId);
        usort($all, static fn (Program $a, Program $b): int => $a->getShortName() <=> $b->getShortName());

        return $all;
    }

    /**
     * The matières on offer for one class: the teacher's own, and failing that the class's, because
     * a screen whose only required field can be empty is a dead end. Staff get the class's list for
     * the same reason they get every class.
     *
     * @return list<Topic>
     */
    private function topicsFor(Program $program, User $teacher): array
    {
        $mine = $this->topics->findTaughtByTeacherInProgram($program, $teacher);

        return [] === $mine ? $this->topics->findAllActiveForProgram($program) : $mine;
    }

    private function programOrFail(?int $programId, User $teacher): Program
    {
        foreach ($this->programsFor($teacher) as $program) {
            if ($program->getId() === $programId) {
                return $program;
            }
        }

        throw $this->createAccessDeniedException();
    }

    /**
     * Unlimited unless the teacher asked for a window, and an empty bound inside a window is simply
     * that side left open - « à partir du 3 mars » is a legitimate answer, and so is « jusqu'au ».
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function submittedWindow(Request $request): array
    {
        if ('window' !== PostValue::string($request, 'visibility')) {
            return [null, null];
        }

        return [
            $this->submittedMoment(PostValue::trimmed($request, 'visibleFrom')),
            $this->submittedMoment(PostValue::trimmed($request, 'visibleUntil')),
        ];
    }

    private function submittedMoment(string $raw): ?\DateTimeImmutable
    {
        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The submitted matière is checked against the list this class actually offered, never taken on
     * the request's word: the blocks of every class are in the page, so a hand-edited form could
     * otherwise file a document under a matière of somebody else's class.
     *
     * @param list<Topic> $allowed
     */
    private function assertBelongs(Topic $candidate, array $allowed): Topic
    {
        foreach ($allowed as $entry) {
            if ($entry->getId() === $candidate->getId()) {
                return $candidate;
            }
        }

        throw $this->createAccessDeniedException();
    }
}
