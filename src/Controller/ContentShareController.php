<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentShare;
use App\Entity\FileLibraryNode;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\ContentShareScope;
use App\Enum\ContentShareSubject;
use App\Repository\ContentShareRepository;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\ProgressionRepository;
use App\Repository\QuizTemplateRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Repository\TopicRepository;
use App\Repository\UserRepository;
use App\Service\ByteSize;
use App\Service\ContentShareAccess;
use App\Service\ContentShareAudience;
use App\Service\ContentShareCatalog;
use App\Service\ContentShareComposer;
use App\Service\ContentShareQuotaException;
use App\Service\FileLibraryNodeDuplicator;
use App\Service\FileLibraryQuota;
use App\Service\FileUploadService;
use App\Service\PostValue;
use App\Service\ProgressionTrameImporter;
use App\Service\QueryValue;
use App\Service\QuizTemplateDuplicator;
use App\Service\SequenceDuplicator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Outils > Partages, and the modal that feeds it - see
 * design/validated/content-sharing-between-teachers.md.
 *
 * One screen with three tabs (Reçus / Catalogue / Mes partages) and, on the item screens, one modal
 * shared by the five subjects. Gated to teachers and personnel exactly like the libraries a share
 * lands in: `ROLE_STUDENT`, `ROLE_TUTOR` and `ROLE_EXTERNAL` are never readers of a share, and
 * App\Service\ContentShareAccess says so a second time for anything that gets past this door.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ContentShareController extends AbstractController
{
    public function __construct(
        private readonly ContentShareRepository $shares,
        private readonly ContentShareAudience $audience,
        private readonly EntityManagerInterface $entityManager,
        // The recipient's own séquences: the list a lone séance is offered to land in, and the check
        // that the one they submitted is theirs.
        private readonly SequenceTemplateRepository $sequences,
    ) {
    }

    /**
     * « Reçus » - what colleagues addressed to me, by name or to a group of mine, newest first.
     *
     * A revoked share stays on the list, greyed and named « retiré par l'auteur », until its reader
     * dismisses it: vanishing without a word from a list somebody was working from is the behaviour
     * this avoids. What they had already duplicated is theirs and is untouched either way.
     */
    #[Route(path: '/shares', name: 'app_shares', methods: ['GET'])]
    public function received(): Response
    {
        $reader = $this->currentUser();
        $readerId = (int) $reader->getId();

        $received = array_values(array_filter(
            $this->audience->filterReadable($this->shares->findReceivedCandidates($reader), $reader),
            static fn (ContentShare $share): bool => !$share->isRevoked() || !$share->isDismissedBy($readerId),
        ));

        return $this->render('content_share/received.html.twig', [
            'shares' => $received,
            'currentTab' => 'received',
            'receivedCount' => \count($received),
        ]);
    }

    /**
     * « Mes partages » - one row per share and not per content: the same séquence given by name and
     * to a team is two lines, because they are withdrawn separately.
     */
    #[Route(path: '/shares/mine', name: 'app_shares_mine', methods: ['GET'])]
    public function mine(): Response
    {
        $owner = $this->currentUser();
        $shares = $this->shares->findOwnedBy($owner);

        return $this->render('content_share/mine.html.twig', [
            'shares' => $shares,
            'memberCounts' => $this->memberCounts($shares),
            'currentTab' => 'mine',
        ]);
    }

    /**
     * « Catalogue » - what colleagues published to the whole establishment.
     *
     * **The search is free text over the title, the author's name and the tag LABELS**, and there is
     * deliberately no facet select built on Niveau / Option / Bloc: those are a private vocabulary
     * per teacher (App\Entity\AbstractLibraryTag), so « SIO 1 », « sio1 » and « Première année » are
     * three different rows meaning the same thing. A select built on the reader's own tags would
     * silently hide every colleague's séquence whose label differs by a space; a free-text search
     * that finds too much is recoverable, a filter that finds nothing looks like an empty catalogue.
     *
     * The two selects the screen does carry are not tags: a type is an enum this application owns,
     * an author is a person. Both are the same word for everybody.
     *
     * Every filter is read through App\Service\QueryValue: a « Tous » option carries `value=""`, and
     * InputBag::getInt() answers a **400** to the empty string.
     */
    #[Route(path: '/shares/catalog', name: 'app_shares_catalog', methods: ['GET'])]
    public function catalog(Request $request, ContentShareCatalog $catalog): Response
    {
        $reader = $this->currentUser();
        $shares = $this->audience->filterReadable($this->shares->findCatalogCandidates($reader), $reader);

        $query = QueryValue::trimmed($request, 'q');
        $type = ContentShareSubject::tryFrom(QueryValue::string($request, 'type'));
        $authorId = QueryValue::nullableInt($request, 'author');

        // The author list is built from the catalogue itself, before the filters narrow it: a select
        // that loses its own selected entry the moment it is used is worse than no select.
        $authors = [];

        foreach ($shares as $share) {
            $authors[(int) $share->getOwner()->getId()] = $share->getOwner();
        }

        $rows = [];

        foreach ($shares as $share) {
            if (null !== $type && $type !== $share->getSubject()) {
                continue;
            }

            if (null !== $authorId && $authorId !== $share->getOwner()->getId()) {
                continue;
            }

            if (!$catalog->matches($catalog->haystackOf($share), $query)) {
                continue;
            }

            $rows[] = ['share' => $share, 'tags' => $catalog->tagLabelsOf($share)];
        }

        return $this->render('content_share/catalog.html.twig', [
            'rows' => $rows,
            'authors' => array_values($authors),
            'query' => $query,
            'type' => $type,
            'authorId' => $authorId,
            'currentTab' => 'catalog',
        ]);
    }

    /**
     * The modal's submit. One endpoint for the five subjects, because the audience *is* the feature
     * and it is identical for all of them.
     *
     * A POST handled by Turbo must redirect, and the redirect goes back to the item itself - which
     * is also where the modal states, under the form, the shares now in place.
     */
    #[Route(path: '/shares/new', name: 'app_shares_new', methods: ['POST'])]
    public function create(
        Request $request,
        ContentShareComposer $composer,
        SequenceTemplateRepository $sequences,
        SeanceTemplateRepository $seances,
        QuizTemplateRepository $quizzes,
        FileLibraryNodeRepository $nodes,
        ProgressionRepository $progressions,
    ): Response {
        // Read from the body, never from a header: that is one of the two bugs a small action inside
        // a form keeps producing in this repository (the other being nested <form> elements, which
        // is why the « Retirer » buttons of the modal are siblings of its form, not children).
        if (!$this->isCsrfTokenValid('content_share', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $subject = $this->resolveSubject($request, $sequences, $seances, $quizzes, $nodes, $progressions);
        $owner = $this->currentUser();

        $this->denyUnlessOwner($subject, $owner);

        $scope = ContentShareScope::tryFrom(PostValue::string($request, 'scope')) ?? ContentShareScope::Users;

        $share = $composer->compose(
            $subject,
            $owner,
            $scope,
            PostValue::intList($request, 'users'),
            PostValue::intList($request, 'groups'),
            PostValue::trimmed($request, 'note'),
        );

        if (null === $share) {
            $this->addFlash('danger', 'contentShareEmptyAudienceFlashMessage');
        } else {
            $this->entityManager->flush();
            $this->addFlash('success', 'contentShareCreatedFlashMessage');
        }

        return $this->redirect($this->subjectUrl($subject));
    }

    /**
     * « Partager à un collègue » on a file library row - the same form as the modal, as a page.
     *
     * A page and not a modal because a browse screen holds thirty rows, and thirty modals would be
     * thirty copies of the group tree in one document.
     */
    #[Route(path: '/shares/new/file/{nodeId}', name: 'app_shares_new_file', methods: ['GET'], requirements: ['nodeId' => '\d+'])]
    public function shareFile(int $nodeId, FileLibraryNodeRepository $nodes): Response
    {
        $node = $nodes->find($nodeId) ?? throw $this->createNotFoundException();
        $owner = $this->currentUser();

        // Only the owner shares, and only what is still in their library.
        if ($node->getOwner()->getId() !== $owner->getId() || $node->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $existing = $this->shares->findForSubject($node);

        return $this->render('content_share/share_file.html.twig', [
            'node' => $node,
            'groups' => $this->audience->pickableGroups(),
            'shares' => $existing,
            'memberCounts' => $this->memberCounts($existing),
        ]);
    }

    /**
     * « Retirer » - and it destroys nothing. The colleague's copy is theirs, and the row stays so the
     * author can still answer « à qui l'ai-je donné ? ».
     */
    #[Route(path: '/shares/{id}/revoke', name: 'app_shares_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, Request $request): Response
    {
        $share = $this->ownedShareOrNotFound($id);
        $this->assertCsrf($request, 'content_share_state');

        $share->revoke();
        $this->entityManager->flush();
        $this->addFlash('success', 'contentShareRevokedFlashMessage');

        return $this->redirectAfterStateChange($request, $share);
    }

    /** « Rétablir » - the second reason a revocation is a date rather than a DELETE. */
    #[Route(path: '/shares/{id}/restore', name: 'app_shares_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(int $id, Request $request): Response
    {
        $share = $this->ownedShareOrNotFound($id);
        $this->assertCsrf($request, 'content_share_state');

        $share->restore();
        $this->entityManager->flush();
        $this->addFlash('success', 'contentShareRestoredFlashMessage');

        return $this->redirectAfterStateChange($request, $share);
    }

    /** « Masquer » - the reader closing a line whose author has withdrawn it. */
    #[Route(path: '/shares/{id}/dismiss', name: 'app_shares_dismiss', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function dismiss(int $id, Request $request): Response
    {
        $share = $this->shares->find($id) ?? throw $this->createNotFoundException();
        $reader = $this->currentUser();

        if (!$this->audience->allows($share, $reader)) {
            throw $this->createNotFoundException();
        }

        $this->assertCsrf($request, 'content_share_state');

        $share->dismissBy((int) $reader->getId());
        $this->entityManager->flush();

        return $this->redirectToRoute('app_shares');
    }

    /**
     * « Dupliquer chez moi », the confirmation - **a whole screen and never a modal** as soon as
     * files are involved, because three things have to be shown that a modal makes illegible: the
     * folders that will be created, what it weighs, and what the quota will read afterwards.
     *
     * A GET that shows and a POST that writes, per this repository's rule for a "show me a result"
     * form.
     */
    #[Route(path: '/shares/{id}/duplicate', name: 'app_shares_duplicate', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function duplicateConfirm(
        int $id,
        SequenceDuplicator $sequences,
        FileLibraryNodeDuplicator $files,
        ProgressionTrameImporter $trames,
        FileLibraryNodeRepository $nodes,
        FileLibraryQuota $quota,
        TopicRepository $topics,
        ProgressionRepository $progressions,
        SchoolYearRepository $schoolYears,
    ): Response {
        $share = $this->readableShareOrNotFound($id);
        $recipient = $this->currentUser();

        return match ($share->getSubject()) {
            ContentShareSubject::Sequence => $this->confirmSequence($share, $recipient, $sequences, $nodes, $quota),
            ContentShareSubject::Seance => $this->confirmSeance($share, $recipient, $sequences, $nodes, $quota),
            ContentShareSubject::Quiz => $this->confirmQuiz($share),
            ContentShareSubject::File => $this->confirmFile($share, $recipient, $files, $nodes, $quota),
            ContentShareSubject::Progression => $this->confirmProgression($share, $recipient, $trames, $nodes, $quota, $topics, $progressions, $schoolYears),
        };
    }

    /**
     * The write. All-or-nothing wherever files are involved: the quota is asked once with the sum
     * inside the duplicator, and a refusal writes nothing at all - not the séquence, not the
     * folders, not the first files that would have fitted.
     *
     * The redirect goes to the new copy in the recipient's own library, which is also the proof it
     * worked.
     */
    #[Route(path: '/shares/{id}/duplicate', name: 'app_shares_duplicate_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(
        int $id,
        Request $request,
        SequenceDuplicator $sequences,
        QuizTemplateDuplicator $quizzes,
        FileLibraryNodeDuplicator $files,
        ProgressionTrameImporter $trames,
        FileLibraryNodeRepository $nodes,
        TopicRepository $topics,
        ProgressionRepository $progressions,
        SchoolYearRepository $schoolYears,
        TranslatorInterface $translator,
    ): Response {
        $share = $this->readableShareOrNotFound($id);
        $this->assertCsrf($request, 'content_share_duplicate');

        $recipient = $this->currentUser();
        $destination = $this->destinationFolder($request, $nodes, $recipient);

        try {
            $target = match ($share->getSubject()) {
                ContentShareSubject::Sequence => $this->generateUrl('app_library_sequences_show', [
                    'id' => $sequences->duplicate($share->getSequenceTemplate() ?? throw $this->createNotFoundException(), $recipient, $destination)->getId(),
                ]),
                // A quiz weighs nothing in the recipient's library: its illustrations get a fresh S3
                // object and **no** library node, exactly like an image uploaded straight into a
                // question. So there is no quota question to ask here.
                ContentShareSubject::Quiz => $this->generateUrl('app_library_quiz_questions', [
                    'id' => $quizzes->duplicate($share->getQuizTemplate() ?? throw $this->createNotFoundException(), $recipient, $recipient)->getId(),
                ]),
                ContentShareSubject::Seance => $this->generateUrl('app_library_seances_show', $this->seanceCopyParameters(
                    $sequences->duplicateSeance(
                        $share->getSeanceTemplate() ?? throw $this->createNotFoundException(),
                        $recipient,
                        $this->targetSequence($request, $recipient),
                        $destination,
                    ),
                )),
                ContentShareSubject::File => $this->fileLibraryUrl(
                    $files->duplicate($share->getLibraryNode() ?? throw $this->createNotFoundException(), $recipient, $destination),
                ),
                ContentShareSubject::Progression => $this->generateUrl('app_progression_show', [
                    'id' => $trames->import(
                        $share->getProgression() ?? throw $this->createNotFoundException(),
                        $recipient,
                        $this->pickedTopic($request, $recipient, $topics, $progressions, $schoolYears),
                        $destination,
                    )->getId(),
                ]),
            };
        } catch (ContentShareQuotaException $refusal) {
            // « Il manque 34 Mo » is actionable where « quota dépassé » is not - and nothing has been
            // written, which is what the confirmation screen promised.
            $this->addFlash('danger', $translator->trans('contentShareQuotaRefusalFlashMessage', [
                '%missing%' => ByteSize::format($refusal->shortfallBytes()),
                '%size%' => ByteSize::format($refusal->requiredBytes),
                '%remaining%' => ByteSize::format($refusal->remainingBytes),
            ]));

            return $this->redirectToRoute('app_shares_duplicate', ['id' => $id]);
        }

        $share->markDuplicatedBy((int) $recipient->getId());
        $this->entityManager->flush();

        $this->addFlash('success', 'contentShareDuplicatedFlashMessage');

        return $this->redirect($target);
    }

    /**
     * « Consulter » - the item in full, read-only.
     *
     * Three of the five open their own show screen, which is already share-aware. The other two get
     * a screen of their own here, and both for the same reason: a quiz has no read-only screen (its
     * two screens are editors), and a folder's own screen is the author's whole library - rail
     * included. What is shared is one item, so what opens is one item.
     */
    #[Route(path: '/shares/{id}/open', name: 'app_shares_open', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function open(int $id, Request $request, FileUploadService $fileUploads, FileLibraryNodeRepository $nodes): Response
    {
        $share = $this->readableShareOrNotFound($id);

        return match ($share->getSubject()) {
            ContentShareSubject::Sequence => $this->redirectToRoute('app_library_sequences_show', ['id' => $share->getSequenceTemplate()?->getId()]),
            ContentShareSubject::Quiz => $this->render('content_share/read_quiz.html.twig', [
                'share' => $share,
                'quiz' => $share->getQuizTemplate() ?? throw $this->createNotFoundException(),
            ]),
            ContentShareSubject::Seance => $this->render('content_share/read_seance.html.twig', [
                'share' => $share,
                'seance' => $share->getSeanceTemplate() ?? throw $this->createNotFoundException(),
            ]),
            ContentShareSubject::File => $this->openFile($share, $request, $fileUploads, $nodes),
            ContentShareSubject::Progression => $this->render('content_share/read_progression.html.twig', [
                'share' => $share,
                'progression' => $share->getProgression() ?? throw $this->createNotFoundException(),
            ]),
        };
    }

    private function confirmSequence(ContentShare $share, User $recipient, SequenceDuplicator $duplicator, FileLibraryNodeRepository $nodes, FileLibraryQuota $quota): Response
    {
        $sequence = $share->getSequenceTemplate() ?? throw $this->createNotFoundException();
        $plan = $duplicator->plan($sequence);
        $phaseCount = 0;

        foreach ($sequence->getSeanceTemplates() as $seance) {
            $phaseCount += $seance->getSeancePhaseTemplates()->count();
        }

        return $this->render('content_share/duplicate_sequence.html.twig', [
            'share' => $share,
            'sequence' => $sequence,
            'plan' => $plan,
            'phaseCount' => $phaseCount,
            'folders' => $nodes->findFolders($recipient),
            'quotaUsed' => $quota->usedBytes($recipient),
            'quotaLimit' => $quota->limitFor($recipient),
            'fits' => $quota->accepts($recipient, $plan['totalBytes']),
            'remaining' => $quota->remainingBytes($recipient),
        ]);
    }

    /**
     * The one screen that asks a question instead of guessing: a séance always lives in a séquence,
     * so the recipient names one - or asks for a new one bearing the séance's own title.
     */
    private function confirmSeance(ContentShare $share, User $recipient, SequenceDuplicator $duplicator, FileLibraryNodeRepository $nodes, FileLibraryQuota $quota): Response
    {
        $seance = $share->getSeanceTemplate() ?? throw $this->createNotFoundException();
        $plan = $duplicator->planSeance($seance);

        return $this->render('content_share/duplicate_seance.html.twig', [
            'share' => $share,
            'seance' => $seance,
            'plan' => $plan,
            'sequences' => $this->sequences->findForTeacher($recipient),
            'folders' => $nodes->findFolders($recipient),
            'quotaUsed' => $quota->usedBytes($recipient),
            'quotaLimit' => $quota->limitFor($recipient),
            'fits' => $quota->accepts($recipient, $plan['totalBytes']),
            'remaining' => $quota->remainingBytes($recipient),
        ]);
    }

    /**
     * The séquence a duplicated séance joins - null when the recipient asked for a new one, and null
     * again when the id they submitted is not one of theirs. The picker only ever offered their own,
     * so a foreign id means a hand-edited request; creating a fresh séquence is the safe answer, not
     * writing into a colleague's.
     */
    private function targetSequence(Request $request, User $recipient): ?SequenceTemplate
    {
        if ('existing' !== PostValue::string($request, 'target', 'existing')) {
            return null;
        }

        $sequenceId = PostValue::nullableInt($request, 'sequence');

        if (null === $sequenceId) {
            return null;
        }

        $sequence = $this->sequences->find($sequenceId);

        return null !== $sequence && $sequence->getTeacher() === $recipient ? $sequence : null;
    }

    /** @return array{sequenceId: int|null, id: int|null} */
    private function seanceCopyParameters(SeanceTemplate $copy): array
    {
        return ['sequenceId' => $copy->getSequenceTemplate()?->getId(), 'id' => $copy->getId()];
    }

    /** No quota block: a quiz's illustrations never enter the recipient's library, so nothing weighs. */
    private function confirmQuiz(ContentShare $share): Response
    {
        return $this->render('content_share/duplicate_quiz.html.twig', [
            'share' => $share,
            'quiz' => $share->getQuizTemplate() ?? throw $this->createNotFoundException(),
        ]);
    }

    private function confirmFile(ContentShare $share, User $recipient, FileLibraryNodeDuplicator $duplicator, FileLibraryNodeRepository $nodes, FileLibraryQuota $quota): Response
    {
        $node = $share->getLibraryNode() ?? throw $this->createNotFoundException();
        $plan = $duplicator->plan($node);

        return $this->render('content_share/duplicate_file.html.twig', [
            'share' => $share,
            'node' => $node,
            'plan' => $plan,
            'folders' => $nodes->findFolders($recipient),
            'quotaUsed' => $quota->usedBytes($recipient),
            'quotaLimit' => $quota->limitFor($recipient),
            'fits' => $quota->accepts($recipient, $plan['totalBytes']),
            'remaining' => $quota->remainingBytes($recipient),
        ]);
    }

    /**
     * « Reprendre la trame » - the heaviest of the five, and the one that shows its constraints
     * before the click rather than after it.
     */
    private function confirmProgression(ContentShare $share, User $recipient, ProgressionTrameImporter $importer, FileLibraryNodeRepository $nodes, FileLibraryQuota $quota, TopicRepository $topics, ProgressionRepository $progressions, SchoolYearRepository $schoolYears): Response
    {
        $progression = $share->getProgression() ?? throw $this->createNotFoundException();
        $free = $this->freeTopics($recipient, $topics, $progressions, $schoolYears);

        // Analysed against the first class offered, so the screen can name what will happen line by
        // line before the recipient has chosen. It is re-analysed at the write, against the class
        // they actually picked.
        $program = $free[0]['program'] ?? null;
        $analysis = null === $program
            ? ['lines' => [], 'keptCount' => 0, 'fileCount' => 0, 'totalBytes' => 0]
            : $importer->analyse($progression, $program);

        return $this->render('content_share/duplicate_progression.html.twig', [
            'share' => $share,
            'progression' => $progression,
            'analysis' => $analysis,
            'topics' => array_map(static fn (array $group): array => ['program' => $group['program']->getDisplayShortName(), 'topics' => $group['topics']], $free),
            'folders' => $nodes->findFolders($recipient),
            'fits' => $quota->accepts($recipient, $analysis['totalBytes']),
            'remaining' => $quota->remainingBytes($recipient),
        ]);
    }

    /**
     * The matières the trame may land on: the recipient's own, in a school year that is running, and
     * **without a progression already** - `Progression` is a `OneToOne` on `Topic`.
     *
     * Grouped by formation so one select can answer « pour quelle classe » and « sur quelle matière »
     * at once: two selects can produce a pair that does not exist, and this one cannot.
     *
     * @return list<array{program: Program, topics: list<Topic>}>
     */
    private function freeTopics(User $recipient, TopicRepository $topics, ProgressionRepository $progressions, SchoolYearRepository $schoolYears): array
    {
        $schoolYear = $schoolYears->findCurrentOrMostRecent();

        if (null === $schoolYear) {
            return [];
        }

        $byProgram = [];

        foreach ($topics->findForTeacherInSchoolYear($recipient, $schoolYear) as $topic) {
            $program = $topic->getProgram();

            if (null === $program || null !== $progressions->findOneForTopic($topic)) {
                continue;
            }

            $byProgram[(int) $program->getId()] ??= ['program' => $program, 'topics' => []];
            $byProgram[(int) $program->getId()]['topics'][] = $topic;
        }

        return array_values($byProgram);
    }

    /** The matière the recipient picked - refused rather than guessed when it is not one of theirs. */
    private function pickedTopic(Request $request, User $recipient, TopicRepository $topics, ProgressionRepository $progressions, SchoolYearRepository $schoolYears): Topic
    {
        $topicId = PostValue::nullableInt($request, 'topic');

        foreach ($this->freeTopics($recipient, $topics, $progressions, $schoolYears) as $group) {
            foreach ($group['topics'] as $topic) {
                if ($topic->getId() === $topicId) {
                    return $topic;
                }
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * A shared file hands over the CDN address, exactly as every other stored file of this
     * application is served; a shared folder lists **its own** subtree, and its rows come back here
     * with `?node=` to download one.
     */
    private function openFile(ContentShare $share, Request $request, FileUploadService $fileUploads, FileLibraryNodeRepository $nodes): Response
    {
        $shared = $share->getLibraryNode() ?? throw $this->createNotFoundException();
        $wanted = QueryValue::nullableInt($request, 'node');
        $node = null === $wanted ? $shared : $nodes->find($wanted) ?? throw $this->createNotFoundException();

        // A node reached through a share must be **inside** what was shared. Without this the id in
        // the query string would open any file of the author's library.
        if ($node->getId() !== $shared->getId() && !$this->isInside($node, $shared)) {
            throw $this->createNotFoundException();
        }

        if ($node->isFile()) {
            if ($node->isDeleted() || null === $node->getStorageKey()) {
                throw $this->createNotFoundException();
            }

            return $this->redirect($fileUploads->url($node->getStorageKey()));
        }

        $rows = [];

        foreach ($nodes->findSubtree($shared) as $member) {
            if ($member->getId() !== $shared->getId() && !$member->isDeleted()) {
                $rows[] = ['node' => $member, 'depth' => $member->getDepth() - $shared->getDepth() - 1];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['node']->getPath(), $a['node']->getName()] <=> [$b['node']->getPath(), $b['node']->getName()]);

        return $this->render('content_share/read_folder.html.twig', ['share' => $share, 'node' => $shared, 'rows' => $rows]);
    }

    private function isInside(FileLibraryNode $node, FileLibraryNode $ancestor): bool
    {
        return str_starts_with($node->getPath(), $ancestor->getPath().$ancestor->getId().'/');
    }

    /** Where a duplicated file or folder landed - its parent folder, or the library root. */
    private function fileLibraryUrl(FileLibraryNode $node): string
    {
        return null === $node->getParent()
            ? $this->generateUrl('app_file_library')
            : $this->generateUrl('app_file_library_folder', ['nodeId' => $node->getParent()->getId()]);
    }

    /**
     * Where the copies land - the folder the recipient picked, defaulting to their library root.
     *
     * A folder of somebody else's library is read as "no folder" rather than refused: the picker
     * only ever offered the recipient's own, so a foreign id means a hand-edited request.
     */
    private function destinationFolder(Request $request, FileLibraryNodeRepository $nodes, User $recipient): ?FileLibraryNode
    {
        $folderId = PostValue::nullableInt($request, 'destination');

        if (null === $folderId) {
            return null;
        }

        $folder = $nodes->find($folderId);

        return null !== $folder && $folder->isFolder() && !$folder->isDeleted() && $folder->getOwner()->getId() === $recipient->getId()
            ? $folder
            : null;
    }

    /** A share this person may read - the one door onto everything a duplication does. */
    private function readableShareOrNotFound(int $id): ContentShare
    {
        $share = $this->shares->find($id) ?? throw $this->createNotFoundException();

        if (!$this->audience->allows($share, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $share;
    }

    /**
     * The modal's people picker - tomselect + ajax, per the repository's rule that picking Users
     * always goes through one. Only accounts that could actually read a share are offered.
     */
    #[Route(path: '/shares/users/search', name: 'app_shares_user_search', methods: ['GET'])]
    public function userSearch(Request $request, UserRepository $users): JsonResponse
    {
        $limit = 20;
        $candidates = \array_slice(
            $users->findActiveMatchingAnyRole(ContentShareAccess::READER_ROLES, [(int) $this->currentUser()->getId()], QueryValue::trimmed($request, 'q')),
            0,
            $limit,
        );

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    /**
     * « Ce partage sera visible de 87 personnes » - measured before the submit, never estimated.
     *
     * The hierarchy's root is « campus »: picking it shares with everybody while looking like a
     * small gesture, and the only honest place to say so is next to the picker.
     */
    #[Route(path: '/shares/group-count', name: 'app_shares_group_count', methods: ['GET'])]
    public function groupCount(Request $request): JsonResponse
    {
        return $this->json(['count' => $this->audience->memberCount(QueryValue::intList($request, 'groups'))]);
    }

    /**
     * The resolved size of every `group` share of the list, keyed by share id - « Équipe SIO —
     * 11 personnes ».
     *
     * @param list<ContentShare> $shares
     *
     * @return array<int, int>
     */
    private function memberCounts(array $shares): array
    {
        $counts = [];

        foreach ($shares as $share) {
            if (ContentShareScope::Group === $share->getScope()) {
                $counts[(int) $share->getId()] = $this->audience->memberCount($share->getGroupIds());
            }
        }

        return $counts;
    }

    /** The five subjects behind one form field pair - `subjectType` names which, `subjectId` which one. */
    private function resolveSubject(
        Request $request,
        SequenceTemplateRepository $sequences,
        SeanceTemplateRepository $seances,
        QuizTemplateRepository $quizzes,
        FileLibraryNodeRepository $nodes,
        ProgressionRepository $progressions,
    ): SequenceTemplate|SeanceTemplate|QuizTemplate|FileLibraryNode|Progression {
        $subjectId = PostValue::int($request, 'subjectId');
        $type = ContentShareSubject::tryFrom(PostValue::string($request, 'subjectType')) ?? throw $this->createNotFoundException();

        return match ($type) {
            ContentShareSubject::Sequence => $sequences->find($subjectId),
            ContentShareSubject::Seance => $seances->find($subjectId),
            ContentShareSubject::Quiz => $quizzes->find($subjectId),
            ContentShareSubject::File => $nodes->find($subjectId),
            ContentShareSubject::Progression => $progressions->find($subjectId),
        } ?? throw $this->createNotFoundException();
    }

    /**
     * Only the owner shares - deliberately no staff bypass. Sharing is an act of authorship, and
     * handing a colleague's work around on their behalf is not a gesture this screen offers.
     */
    private function denyUnlessOwner(SequenceTemplate|SeanceTemplate|QuizTemplate|FileLibraryNode|Progression $subject, User $owner): void
    {
        $ownerId = match (true) {
            $subject instanceof SequenceTemplate => $subject->getTeacher()?->getId(),
            $subject instanceof SeanceTemplate => $subject->getSequenceTemplate()?->getTeacher()?->getId(),
            $subject instanceof QuizTemplate => $subject->getTeacher()?->getId(),
            $subject instanceof FileLibraryNode => $subject->getOwner()->getId(),
            default => $subject->getTeacher()?->getId(),
        };

        if (null === $ownerId || $ownerId !== $owner->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function subjectUrl(SequenceTemplate|SeanceTemplate|QuizTemplate|FileLibraryNode|Progression $subject): string
    {
        return match (true) {
            $subject instanceof SequenceTemplate => $this->generateUrl('app_library_sequences_show', ['id' => $subject->getId()]),
            $subject instanceof SeanceTemplate => $this->generateUrl('app_library_seances_show', [
                'sequenceId' => $subject->getSequenceTemplate()?->getId(),
                'id' => $subject->getId(),
            ]),
            $subject instanceof QuizTemplate => $this->generateUrl('app_library_quiz_settings', ['id' => $subject->getId()]),
            $subject instanceof FileLibraryNode => null === $subject->getParent()
                ? $this->generateUrl('app_file_library')
                : $this->generateUrl('app_file_library_folder', ['nodeId' => $subject->getParent()->getId()]),
            default => $this->generateUrl('app_progression_show', ['id' => $subject->getId()]),
        };
    }

    private function ownedShareOrNotFound(int $id): ContentShare
    {
        $share = $this->shares->find($id) ?? throw $this->createNotFoundException();

        if ($share->getOwner()->getId() !== $this->currentUser()->getId()) {
            throw $this->createNotFoundException();
        }

        return $share;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * « Retirer » exists in two places - the modal on the item, and « Mes partages ». The form says
     * which one it came from rather than the controller guessing from a Referer that a proxy may
     * have stripped.
     */
    private function redirectAfterStateChange(Request $request, ContentShare $share): Response
    {
        return PostValue::bool($request, 'fromSubject')
            ? $this->redirect($this->subjectUrlOf($share))
            : $this->redirectToRoute('app_shares_mine');
    }

    private function subjectUrlOf(ContentShare $share): string
    {
        $subject = $share->getSequenceTemplate()
            ?? $share->getSeanceTemplate()
            ?? $share->getQuizTemplate()
            ?? $share->getLibraryNode()
            ?? $share->getProgression();

        return null === $subject ? $this->generateUrl('app_shares_mine') : $this->subjectUrl($subject);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
