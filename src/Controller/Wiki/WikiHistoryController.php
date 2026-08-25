<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Entity\Wiki;
use App\Entity\WikiRevision;
use App\Enum\Feature;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Repository\WikiRevisionRepository;
use App\Security\Voter\WikiVoter;
use App\Service\QueryValue;
use App\Service\WikiNodeManager;
use App\Service\WikiSearchTerms;
use App\Service\WikiTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Looking backwards and sideways: the revision history of one page, and the search across the wiki.
 *
 * Search is deliberately scoped to the current wiki. Cross-wiki search was considered and deferred:
 * it is worth building the day a teacher actually follows thirty student wikis, and the rail's field
 * answers everything before that.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki/{id}', requirements: ['id' => '\d+'])]
#[RequiresFeature(Feature::Wiki)]
class WikiHistoryController extends AbstractController
{
    use WikiTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly WikiRevisionRepository $revisions,
        private readonly WikiTree $tree,
        private readonly WikiNodeManager $nodeManager,
    ) {
    }

    /** A GET form, per the Turbo rule: a "show me a result" screen never posts. */
    #[Route(path: '/search', name: 'app_wiki_search', methods: ['GET'])]
    public function search(Request $request, int $id): Response
    {
        $wiki = $this->loadWiki($id);
        $typed = QueryValue::trimmed($request, 'q');
        $rail = $this->rail($wiki);

        return $this->render('wiki/search.html.twig', [
            'wiki' => $wiki,
            'tree' => $rail['tree'],
            'search' => $typed,
            'results' => $this->nodes->search($wiki, WikiSearchTerms::forBooleanMode($typed)),
        ]);
    }

    #[Route(path: '/p/{nodeId}/history', name: 'app_wiki_page_history', requirements: ['nodeId' => '\d+'], methods: ['GET'])]
    public function history(Request $request, int $id, int $nodeId): Response
    {
        $wiki = $this->loadWiki($id);
        $node = $this->loadNode($wiki, $nodeId);
        $revisions = $this->revisions->findForNode($node);
        $compared = QueryValue::nullableInt($request, 'compare');
        $rail = $this->rail($wiki);

        $selected = null;

        foreach ($revisions as $revision) {
            if ($revision->getId() === $compared) {
                $selected = $revision;

                break;
            }
        }

        return $this->render('wiki/history.html.twig', [
            'wiki' => $wiki,
            'node' => $node,
            'tree' => $rail['tree'],
            'ancestors' => $this->nodeManager->ancestorsOf($node, $rail['byId']),
            'revisions' => $revisions,
            'selected' => $selected,
            // The cap is short enough to be surprising, so the screen states it rather than letting
            // a user assume every save is still there.
            'revisionCap' => WikiRevision::KEEP_PER_NODE,
        ]);
    }

    /**
     * Restoring is a save like any other: the state being left behind is recorded first, so the
     * restore itself can be undone. Anything else would make the history a trap.
     */
    #[Route(path: '/p/{nodeId}/history/{revisionId}/restore', name: 'app_wiki_page_restore', requirements: ['nodeId' => '\d+', 'revisionId' => '\d+'], methods: ['POST'])]
    public function restore(Request $request, int $id, int $nodeId, int $revisionId): Response
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);
        $this->assertToken($request, 'wiki_history');

        $node = $this->loadNode($wiki, $nodeId);
        $user = $this->currentUser();
        $revision = $this->revisions->find($revisionId);

        if (null === $revision || $revision->getNode() !== $node) {
            throw $this->createNotFoundException();
        }

        $this->revisions->record($node, $user);
        $this->nodeManager->rename($node, $revision->getTitle(), $user);
        $this->nodeManager->writeBody($node, $revision->getBody(), $user);
        $this->entityManager->flush();

        $this->addFlash('success', 'wikiRevisionRestoredFlashMessage');

        return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
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
