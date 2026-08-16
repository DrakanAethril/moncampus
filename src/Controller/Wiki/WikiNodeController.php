<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Entity\User;
use App\Entity\Wiki;
use App\Enum\WikiNodeType;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Security\Voter\WikiVoter;
use App\Service\PostValue;
use App\Service\WikiNodeManager;
use App\Service\WikiTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the rail does to the tree: create, rename, move, trash, restore, purge.
 *
 * Every one of these is a POST that redirects - Turbo's rule, and the reason none of them answers
 * JSON: the rail is server-rendered, so the redirect *is* the update.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki/{id}', requirements: ['id' => '\d+'])]
class WikiNodeController extends AbstractController
{
    use WikiTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly WikiNodeManager $nodeManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/nodes', name: 'app_wiki_node_create', methods: ['POST'])]
    public function create(Request $request, int $id): Response
    {
        $wiki = $this->editableWiki($id);
        $this->assertToken($request, 'wiki_node');
        $user = $this->currentUser();

        $parentId = PostValue::nullableInt($request, 'parent');
        $parent = null !== $parentId ? $this->loadNode($wiki, $parentId) : null;
        $type = WikiNodeType::Folder->value === PostValue::string($request, 'type') ? WikiNodeType::Folder : WikiNodeType::Page;
        $title = PostValue::trimmed($request, 'title');

        if ('' === $title) {
            $title = $this->translator->trans(WikiNodeType::Folder === $type ? 'wikiNewFolderDefaultTitle' : 'wikiNewPageDefaultTitle');
        }

        $node = $this->nodeManager->create($wiki, $parent, $type, $title, $user);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $node->getId()]);
    }

    #[Route(path: '/nodes/{nodeId}/rename', name: 'app_wiki_node_rename', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function rename(Request $request, int $id, int $nodeId): Response
    {
        $wiki = $this->editableWiki($id);
        $this->assertToken($request, 'wiki_node');
        $node = $this->loadNode($wiki, $nodeId);
        $title = PostValue::trimmed($request, 'title');

        if ('' !== $title) {
            $this->nodeManager->rename($node, $title, $this->currentUser());
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
    }

    /**
     * A drag in the rail. Refuses to drop a folder onto one of its own descendants rather than
     * producing a subtree nothing links to any more.
     *
     * The moved node is named in the body, not in the path, on purpose: the rail's controller knows
     * one endpoint and fills in the ids, where a {nodeId} route would force it to build URLs from a
     * template - and a `\d+` requirement against a placeholder is a 500 on the whole screen, a trap
     * this repository has already paid for once.
     */
    #[Route(path: '/nodes/move', name: 'app_wiki_node_move', methods: ['POST'])]
    public function move(Request $request, int $id): Response
    {
        $wiki = $this->editableWiki($id);
        $this->assertToken($request, 'wiki_node');
        $nodeId = PostValue::int($request, 'node');
        $node = $this->loadNode($wiki, $nodeId);

        $parentId = PostValue::nullableInt($request, 'parent');
        $parent = null !== $parentId ? $this->loadNode($wiki, $parentId) : null;

        if (!$this->nodeManager->move($node, $parent, PostValue::nullableInt($request, 'position'))) {
            $this->addFlash('danger', 'wikiNodeMoveRefusedFlashMessage');

            return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
    }

    #[Route(path: '/nodes/{nodeId}/delete', name: 'app_wiki_node_delete', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id, int $nodeId): Response
    {
        $wiki = $this->editableWiki($id);
        $this->assertToken($request, 'wiki_node');
        $node = $this->loadNode($wiki, $nodeId);

        $this->nodeManager->trash($node);
        $this->entityManager->flush();

        $this->addFlash('success', 'wikiNodeTrashedFlashMessage');

        return $this->redirectToRoute('app_wiki_show', ['id' => $id]);
    }

    #[Route(path: '/trash', name: 'app_wiki_trash', methods: ['GET'])]
    public function trash(int $id): Response
    {
        $wiki = $this->editableWiki($id);
        $rail = $this->rail($wiki);

        return $this->render('wiki/trash.html.twig', [
            'wiki' => $wiki,
            'tree' => $rail['tree'],
            'trashed' => $this->nodes->findTrashedOf($wiki),
        ]);
    }

    #[Route(path: '/trash/{nodeId}/restore', name: 'app_wiki_node_restore', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function restore(Request $request, int $id, int $nodeId): Response
    {
        $wiki = $this->editableWiki($id);
        $this->assertToken($request, 'wiki_trash');
        $node = $this->loadNode($wiki, $nodeId);

        $this->nodeManager->restore($node);
        $this->entityManager->flush();

        $this->addFlash('success', 'wikiNodeRestoredFlashMessage');

        return $this->redirectToRoute('app_wiki_trash', ['id' => $id]);
    }

    #[Route(path: '/trash/{nodeId}/purge', name: 'app_wiki_node_purge', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function purge(Request $request, int $id, int $nodeId): Response
    {
        $wiki = $this->editableWiki($id);
        $this->assertToken($request, 'wiki_trash');
        $node = $this->loadNode($wiki, $nodeId);

        // A purge takes the whole branch: leaving the descendants behind would strand rows nothing
        // can reach any more.
        $this->nodeManager->purge(array_merge([$node], $this->nodes->findDescendantsOf($node)));
        $this->entityManager->flush();

        $this->addFlash('success', 'wikiNodePurgedFlashMessage');

        return $this->redirectToRoute('app_wiki_trash', ['id' => $id]);
    }

    private function editableWiki(int $id): Wiki
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);

        return $wiki;
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
