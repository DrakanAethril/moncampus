<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Attribute\RequiresFeature;
use App\Entity\Group;
use App\Entity\User;
use App\Enum\DocumentationAudience;
use App\Enum\Feature;
use App\Repository\DocumentationArticleRepository;
use App\Security\Voter\DocumentationArticleVoter;
use App\Service\DocumentationAccess;
use App\Service\DocumentationBoard;
use App\Service\DocumentationPerimeter;
use App\Service\DocumentationReadCounter;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The documentation base, read side (handoff 2a/2b/2c/2e) - open to every logged-in user, which is
 * why "Ressources" is now a menu everybody sees.
 *
 * The home page and a page de garde are the same screen at two depths of the perimeter, so they
 * are one method: App\Service\DocumentationBoard does the narrowing, this only says where to start.
 * The article page is likewise one template for the two variants of the handoff - the management
 * panel is drawn or not according to DOCUMENTATION_ARTICLE_MANAGE, and a teacher who is not the
 * owner gets it inert.
 */
#[IsGranted('ROLE_USER')]
#[Route(path: '/documentation')]
#[RequiresFeature(Feature::Documentation)]
class DocumentationController extends AbstractController
{
    public const int RECENT_LIMIT = 8;

    public function __construct(
        private readonly DocumentationBoard $board,
        private readonly DocumentationPerimeter $perimeter,
        private readonly DocumentationAccess $access,
        private readonly DocumentationArticleRepository $articles,
    ) {
    }

    #[Route(path: '', name: 'app_documentation', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderBoard($request, null);
    }

    #[Route(path: '/scope/{id}', name: 'app_documentation_scope', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function scope(Request $request, int $id): Response
    {
        $group = $this->perimeter->find($id) ?? throw $this->createNotFoundException();

        if (!\in_array($id, $this->perimeter->eligibleIds(), true)) {
            throw $this->createNotFoundException('This group is not part of the documentation perimeter.');
        }

        return $this->renderBoard($request, $group);
    }

    #[Route(path: '/articles/{id}', name: 'app_documentation_article', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function article(int $id, DocumentationReadCounter $readCounter): Response
    {
        $article = $this->articles->find($id) ?? throw $this->createNotFoundException();

        $this->denyAccessUnlessGranted(DocumentationArticleVoter::VIEW, $article);

        // The article page is a GET that writes - the two counters, and nothing else. The
        // increment is its own UPDATE (see DocumentationReadCounter), so it needs no flush and
        // nothing else can ride along with it; the hydrated article keeps the count it was loaded
        // with, which is only ever displayed to a manager, whose own reads do not count.
        $user = $this->getUser();
        $readCounter->registerRead($article, $user instanceof User ? $user : null);

        return $this->render('documentation/article.html.twig', [
            'article' => $article,
            'canManage' => $this->isGranted(DocumentationArticleVoter::MANAGE, $article),
            'perimeterTree' => $this->perimeter->tree(),
            'audiences' => DocumentationAudience::ordered(),
            'isManager' => $this->isManager(),
        ]);
    }

    private function renderBoard(Request $request, ?Group $scope): Response
    {
        $user = $this->getUser();
        $tagId = QueryValue::nullableInt($request, 'tag');
        $search = QueryValue::trimmed($request, 'q');

        $board = $this->board->build(
            $user instanceof User ? $user : null,
            $scope,
            $tagId,
            '' === $search ? null : $search,
            null === $scope ? self::RECENT_LIMIT : null,
        );

        return $this->render('documentation/index.html.twig', [
            'board' => $board,
            'scope' => $scope,
            'scopePath' => null === $scope ? [] : $this->perimeter->pathTo($scope),
            'perimeterTree' => $this->perimeter->tree(),
            'root' => $this->perimeter->root(),
            'activeTagId' => $tagId,
            'search' => $search,
            'isManager' => $this->isManager(),
        ]);
    }

    private function isManager(): bool
    {
        $user = $this->getUser();

        return $user instanceof User && $this->access->isManagerRole($user->getRoles());
    }
}
