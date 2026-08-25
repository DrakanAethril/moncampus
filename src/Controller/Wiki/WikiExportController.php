<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Attribute\RequiresFeature;
use App\Entity\Wiki;
use App\Enum\Feature;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Service\GotenbergUnavailableException;
use App\Service\QueryValue;
use App\Service\WikiPdfExporter;
use App\Service\WikiTree;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Taking a wiki with you.
 *
 * Export follows WIKI_EDIT - which, there being no read-only access, means anybody who can read the
 * wiki can take it away. That is deliberate: it is the *export* that decides what leaves, and a
 * reader who could not export could still copy the pages by hand.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki/{id}', requirements: ['id' => '\d+'])]
#[RequiresFeature(Feature::Wiki)]
class WikiExportController extends AbstractController
{
    use WikiTrait;

    public function __construct(
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly WikiPdfExporter $exporter,
    ) {
    }

    /**
     * The whole wiki, a subtree (`?node=`), or a single page (`?node=&page=1`).
     *
     * A Gotenberg outage degrades to a flash message and a redirect rather than a raw 500 - the
     * contract App\Service\GotenbergUnavailableException exists for, and the same one the Livret
     * Alternant's exports honour.
     */
    #[Route(path: '/export.pdf', name: 'app_wiki_export_pdf', methods: ['GET'])]
    public function pdf(Request $request, int $id): Response
    {
        $wiki = $this->loadWiki($id);
        $nodeId = QueryValue::nullableInt($request, 'node');
        $node = null !== $nodeId ? $this->loadNode($wiki, $nodeId) : null;
        $singlePage = QueryValue::bool($request, 'page');

        try {
            $pdf = $this->exporter->export(
                $wiki,
                $node,
                $singlePage,
                $this->renderView(...),
                new \DateTimeImmutable(),
            );
        } catch (GotenbergUnavailableException) {
            $this->addFlash('danger', 'wikiExportUnavailableFlashMessage');

            return $this->redirectToRoute('app_wiki_show', ['id' => $id]);
        }

        $response = new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->filenameOf($wiki, $node?->getTitle()),
        ));

        return $response;
    }

    private function filenameOf(Wiki $wiki, ?string $nodeTitle): string
    {
        $base = $nodeTitle ?? $wiki->getTitle();
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $base) ?? 'wiki';

        return 'wiki-'.trim(mb_strtolower($slug), '-').'.pdf';
    }
}
