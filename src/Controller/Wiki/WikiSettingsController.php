<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wiki;
use App\Enum\Feature;
use App\Enum\WikiType;
use App\Repository\GroupBatchRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Security\Voter\WikiVoter;
use App\Service\PostValue;
use App\Service\WikiAccess;
use App\Service\WikiAudienceScope;
use App\Service\WikiGroupProvisioner;
use App\Service\WikiNodeManager;
use App\Service\WikiTree;
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
 * Creating a shared wiki and composing its audience - title, members, classes, archive, delete.
 *
 * The screen has one job beyond the obvious: **saying the current regime in words**. The access
 * rule derives visibility from the audience, which is its one weakness - it changes what a wiki is
 * as a side effect of who is in it. So the screen states it ("visible par ses membres uniquement" /
 * "par tous les enseignants, car il est affecté à des étudiants") and warns before a save that
 * crosses the line in either direction. Nothing is hidden behind an inference the user cannot see.
 *
 * Membership is re-checked here at save, not merely offered by the picker - the same posture
 * messaging takes for its recipients.
 *
 * The screen is also the wiki's *réglages* in the wider sense: it carries the whole-wiki export and
 * the import, which used to sit as buttons above every page. That is why the class no longer demands
 * a teacher's role - a student reads the réglages of their own personal wiki to export it, and only
 * the composing half is WIKI_MANAGE. Creating a shared wiki and searching for members stay
 * teacher-and-above, on the actions themselves.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki')]
#[RequiresFeature(Feature::Wiki)]
class WikiSettingsController extends AbstractController
{
    use WikiTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly ProgramRepository $programs,
        private readonly GroupBatchRepository $groupBatches,
        private readonly UserRepository $users,
        private readonly WikiTree $tree,
        private readonly WikiAccess $access,
        private readonly WikiAudienceScope $scope,
        private readonly WikiNodeManager $nodeManager,
        private readonly WikiGroupProvisioner $groupProvisioner,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * A new shared wiki. Not in the design's route table, which only enumerates the reading
     * screens - but "let teachers create extra wikis" is the feature's own purpose, and the
     * composing form is this controller's, next to the settings screen it is the twin of.
     */
    #[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_ADMIN")'))]
    #[Route(path: '/new', name: 'app_wiki_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = $this->currentUser();

        if ($request->isMethod('POST')) {
            $this->assertToken($request, 'wiki_settings');
            $title = PostValue::trimmed($request, 'title');

            if ('' === $title) {
                $this->addFlash('danger', 'wikiTitleRequiredFlashMessage');

                return $this->redirectToRoute('app_wiki_new');
            }

            // Targeting a saved set of groups makes this form create N wikis instead of one - see
            // createFromGroups(). The members/classes pickers are not read at all in that mode:
            // the groups ARE the audience, and silently merging both would produce wikis whose
            // membership nobody could predict from the screen.
            $groupBatchId = PostValue::int($request, 'groupBatch');

            if ($groupBatchId > 0) {
                return $this->createFromGroups($groupBatchId, $title, $user);
            }

            $wiki = new Wiki($title, WikiType::Shared, $user);
            $this->applyAudience($wiki, $request, $user);

            $this->entityManager->persist($wiki);
            $this->entityManager->flush();

            $this->nodeManager->createHomePage(
                $wiki,
                $user,
                $this->translator->trans('wikiHomePageTitle'),
                '<p>'.$this->translator->trans('wikiHomePageWelcomeText').'</p>',
            );
            $this->entityManager->flush();

            $this->addFlash('success', 'wikiCreatedFlashMessage');

            return $this->redirectToRoute('app_wiki_show', ['id' => $wiki->getId()]);
        }

        return $this->render('wiki/settings.html.twig', [
            'wiki' => null,
            'canManage' => true,
            'assignablePrograms' => $this->scope->assignablePrograms($user),
            'groupBatchChoices' => $this->groupBatchChoices($user),
            'hasStudentAudience' => false,
        ]);
    }

    /**
     * The set picker's options, grouped by class so a teacher of three classes reads a browsable
     * list rather than a flat one. Built here rather than in Twig: the template would otherwise
     * have to walk entities to count groups and sort classes.
     *
     * @return list<array{label: string, batches: list<array{id: int, name: string, groupCount: int}>}>
     */
    private function groupBatchChoices(User $user): array
    {
        $byProgram = [];

        foreach ($this->scope->targetableGroupBatches($user) as $batch) {
            $label = $batch->getProgram()->getDisplayShortName();
            $byProgram[$label][] = [
                'id' => (int) $batch->getId(),
                'name' => $batch->getName(),
                // An empty group produces no wiki, so the count on screen must be the number of
                // wikis this set would actually create - see GroupWikiPlanner.
                'groupCount' => \count(array_filter($batch->getGroups(), static fn (array $ids): bool => [] !== $ids)),
            ];
        }

        ksort($byProgram);

        return array_map(
            static fn (string $label, array $batches): array => ['label' => $label, 'batches' => $batches],
            array_keys($byProgram),
            array_values($byProgram),
        );
    }

    /**
     * One shared wiki per group of a saved set - the "Un wiki par groupe" target of the creation
     * form.
     *
     * Open to teachers, not just staff: composing groups and giving each of them a place to write
     * is the same person's job. What bounds it is the set itself - WikiAudienceScope::mayTarget()
     * re-reads whether this composer owns it or has been shared it, because the picker is a
     * convenience and never the control.
     *
     * Nothing here refuses a set that has already been used: running it again is how a class gets
     * a second family of wikis for a second project.
     */
    private function createFromGroups(int $groupBatchId, string $titlePrefix, User $user): Response
    {
        $batch = $this->groupBatches->find($groupBatchId);

        if (null === $batch || !$this->scope->mayTarget($user, $batch)) {
            $this->addFlash('danger', 'wikiGroupBatchUnavailableFlashMessage');

            return $this->redirectToRoute('app_wiki_new');
        }

        $wikis = $this->groupProvisioner->provision($batch, $titlePrefix, $user);

        if ([] === $wikis) {
            $this->addFlash('danger', 'wikiGroupBatchEmptyFlashMessage');

            return $this->redirectToRoute('app_wiki_new');
        }

        $this->addFlash('success', $this->translator->trans('wikiGroupWikisCreatedFlashMessage', ['%count%' => \count($wikis)]));

        return $this->redirectToRoute('app_wiki_shared');
    }

    /**
     * The réglages screen: what the wiki *is* (title, members, classes) for whoever manages it, and
     * what one can do with it as a whole (export, import) for everybody who can reach it.
     *
     * Reading it takes WIKI_EDIT and writing it takes WIKI_MANAGE, which is not the usual pairing:
     * the two halves of the screen have different audiences on purpose, and the composing half is
     * simply not rendered to a reader who may not change it.
     */
    #[Route(path: '/{id}/settings', name: 'app_wiki_settings', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function settings(Request $request, int $id): Response
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);
        $user = $this->currentUser();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(WikiVoter::MANAGE, $wiki);
            $this->assertToken($request, 'wiki_settings');
            $title = PostValue::trimmed($request, 'title');

            if ('' !== $title) {
                $wiki->setTitle($title);
            }

            if (!$wiki->isPersonal()) {
                $this->applyAudience($wiki, $request, $user);
            }

            $wiki->touch();
            $this->entityManager->flush();

            $this->addFlash('success', 'wikiSettingsSavedFlashMessage');

            return $this->redirectToRoute('app_wiki_settings', ['id' => $id]);
        }

        $canManage = $this->isGranted(WikiVoter::MANAGE, $wiki);

        return $this->render('wiki/settings.html.twig', [
            'wiki' => $wiki,
            'canManage' => $canManage,
            // Not computed for a reader who cannot compose the audience anyway - the picker is the
            // only thing that reads it.
            'assignablePrograms' => $canManage ? $this->scope->assignablePrograms($user) : [],
            'hasStudentAudience' => $this->access->hasStudentAudience($wiki->getPrograms()->count(), $wiki->getMemberRoles()),
        ]);
    }

    #[Route(path: '/{id}/archive', name: 'app_wiki_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(Request $request, int $id): Response
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::MANAGE, $wiki);
        $this->assertToken($request, 'wiki_settings');

        // Archiving stays a manual switch: the end of a school year does nothing on its own, a
        // shared wiki stays attached to its Program and everybody keeps their access.
        $wiki->setArchived(!$wiki->isArchived());
        $wiki->touch();
        $this->entityManager->flush();

        return $this->redirectToRoute('app_wiki_settings', ['id' => $id]);
    }

    #[Route(path: '/{id}/delete', name: 'app_wiki_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::DELETE, $wiki);
        $this->assertToken($request, 'wiki_delete');

        $this->entityManager->remove($wiki);
        $this->entityManager->flush();

        $this->addFlash('success', 'wikiDeletedFlashMessage');

        return $this->redirectToRoute('app_wiki_shared');
    }

    /** The tomselect ajax endpoint - it returns only the people the requester may actually add. */
    #[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_ADMIN")'))]
    #[Route(path: '/members/search', name: 'app_wiki_member_search', methods: ['GET'])]
    public function memberSearch(Request $request): JsonResponse
    {
        $limit = 20;
        $candidates = $this->scope->candidates($this->currentUser(), $request->query->get('q'), $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    /**
     * Rebuilds the audience from what was submitted, dropping anything the composer may not add.
     *
     * Silently dropping rather than refusing the whole save is deliberate: the picker only ever
     * offered legitimate ids, so a rejected one means a hand-edited request, and there is nobody to
     * report it to.
     */
    private function applyAudience(Wiki $wiki, Request $request, User $composer): void
    {
        foreach ($wiki->getMembers()->toArray() as $member) {
            $wiki->removeMember($member);
        }

        foreach ($wiki->getPrograms()->toArray() as $program) {
            $wiki->removeProgram($program);
        }

        foreach (PostValue::intList($request, 'members') as $memberId) {
            $member = $this->users->find($memberId);

            if ($member instanceof User && $this->scope->mayAddMember($composer, $member)) {
                $wiki->addMember($member);
            }
        }

        foreach (PostValue::intList($request, 'programs') as $programId) {
            $program = $this->programs->find($programId);

            if ($program instanceof Program && $this->scope->mayAssign($composer, $program)) {
                $wiki->addProgram($program);
            }
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
