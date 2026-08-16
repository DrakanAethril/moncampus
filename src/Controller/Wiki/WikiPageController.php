<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Entity\User;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Security\Voter\WikiVoter;
use App\Service\PostValue;
use App\Service\WikiNodeManager;
use App\Service\WikiTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reading and writing one page: the rail on the left, the page in the middle.
 *
 * A folder is opened here too, and renders its own body when it carries one - the "everything is a
 * page that can have children" half of the model. A folder with no body shows what it holds.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki/{id}/p', requirements: ['id' => '\d+'])]
class WikiPageController extends AbstractController
{
    use WikiTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly WikiNodeManager $nodeManager,
    ) {
    }

    #[Route(path: '/{nodeId}', name: 'app_wiki_page', requirements: ['nodeId' => '\d+'], methods: ['GET'])]
    public function page(int $id, int $nodeId): Response
    {
        $wiki = $this->loadWiki($id);
        $node = $this->loadNode($wiki, $nodeId);

        if ($node->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $rail = $this->rail($wiki);

        return $this->render('wiki/page.html.twig', [
            'wiki' => $wiki,
            'node' => $node,
            'tree' => $rail['tree'],
            'ancestors' => $this->nodeManager->ancestorsOf($node, $rail['byId']),
        ]);
    }

    #[Route(path: '/{nodeId}/edit', name: 'app_wiki_page_edit', requirements: ['nodeId' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[Target('app.wiki_page_body')] HtmlSanitizerInterface $sanitizer,
        int $id,
        int $nodeId,
    ): Response {
        $wiki = $this->loadWiki($id);
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);
        $node = $this->loadNode($wiki, $nodeId);
        $user = $this->getUser();

        if ($node->isDeleted() || !$user instanceof User) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->assertToken($request, 'wiki_page_edit');

            $title = PostValue::trimmed($request, 'title');

            if ('' !== $title && $title !== $node->getTitle()) {
                $this->nodeManager->rename($node, $title, $user);
            }

            $body = PostValue::string($request, 'body');
            $this->nodeManager->writeBody($node, '' === trim($body) ? null : $sanitizer->sanitize($body), $user);

            // Leaving the editor hands the page back: the lock exists to stop a silent overwrite,
            // not to survive the save that ends it.
            $node->releaseLock();
            $this->entityManager->flush();

            $this->addFlash('success', 'wikiPageSavedFlashMessage');

            return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
        }

        $rail = $this->rail($wiki);

        return $this->render('wiki/page_edit.html.twig', [
            'wiki' => $wiki,
            'node' => $node,
            'tree' => $rail['tree'],
            'ancestors' => $this->nodeManager->ancestorsOf($node, $rail['byId']),
        ]);
    }
}
