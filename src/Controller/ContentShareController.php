<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentShare;
use App\Entity\FileLibraryNode;
use App\Entity\Progression;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\ContentShareScope;
use App\Enum\ContentShareSubject;
use App\Repository\ContentShareRepository;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\ProgressionRepository;
use App\Repository\QuizTemplateRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Repository\UserRepository;
use App\Service\ByteSize;
use App\Service\ContentShareAccess;
use App\Service\ContentShareAudience;
use App\Service\ContentShareComposer;
use App\Service\ContentShareQuotaException;
use App\Service\FileLibraryQuota;
use App\Service\PostValue;
use App\Service\QueryValue;
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
    public function duplicateConfirm(int $id, SequenceDuplicator $duplicator, FileLibraryNodeRepository $nodes, FileLibraryQuota $quota): Response
    {
        $share = $this->readableShareOrNotFound($id);
        $recipient = $this->currentUser();
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
     * The write. All-or-nothing: the quota is asked once with the sum inside
     * App\Service\SequenceDuplicator, and a refusal writes nothing at all - not the séquence, not
     * the folders, not the first files that would have fitted.
     *
     * The redirect goes to the new copy in the recipient's own library, which is also the proof it
     * worked.
     */
    #[Route(path: '/shares/{id}/duplicate', name: 'app_shares_duplicate_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(
        int $id,
        Request $request,
        SequenceDuplicator $duplicator,
        FileLibraryNodeRepository $nodes,
        TranslatorInterface $translator,
    ): Response {
        $share = $this->readableShareOrNotFound($id);
        $this->assertCsrf($request, 'content_share_duplicate');

        $recipient = $this->currentUser();
        $sequence = $share->getSequenceTemplate() ?? throw $this->createNotFoundException();
        $destination = $this->destinationFolder($request, $nodes, $recipient);

        try {
            $copy = $duplicator->duplicate($sequence, $recipient, $destination);
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

        return $this->redirectToRoute('app_library_sequences_show', ['id' => $copy->getId()]);
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
