<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\HelpArticle;
use App\Entity\HelpSection;
use App\Entity\User;
use App\Enum\HelpArticleKind;
use App\Repository\HelpArticleRepository;
use App\Repository\HelpSectionRepository;
use App\Service\HelpAccess;
use App\Service\HelpArticleOutline;
use App\Service\HelpSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The help centre, read side (design_handoff_aide).
 *
 * Paths are English like every other route in this app - the handoff writes /aide, this is /help,
 * for the same reason the "À propos" screen lives at /about.
 *
 * Every screen here is open to any authenticated user and shows nothing but what that user's
 * audiences allow (App\Service\HelpAccess). Only the *link* into it is gated, in the profile menu:
 * students and tutors have no entry point yet, and a student who reaches /help by hand lands on an
 * empty help rather than on a 403 - there is nothing to protect, only content addressed to someone
 * else.
 *
 * {sectionSlug} deliberately refuses "manage": App\Controller\HelpAdminController hangs its own
 * screens off /help/manage, and a route pattern is what keeps the two from racing to match it.
 */
#[IsGranted('ROLE_USER')]
class HelpController extends AbstractController
{
    private const string SECTION_SLUG = '(?!manage$)[a-z0-9-]+';

    // How many entries each home block holds. Both are display caps, not queries: the whole index
    // is read anyway to know what this reader may see.
    private const int HOME_FAQ_LIMIT = 6;
    private const int HOME_POPULAR_LIMIT = 4;
    private const int NEXT_TO_READ_LIMIT = 3;

    public function __construct(
        private readonly HelpSectionRepository $sections,
        private readonly HelpArticleRepository $articles,
        private readonly HelpAccess $access,
    ) {
    }

    #[Route(path: '/help', name: 'app_help', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->currentUser();
        $readable = $this->access->filterArticles($this->articles->findAllWithSection(), $user);

        $cards = [];
        foreach ($this->sections->findAllWithArticles() as $section) {
            if (!$this->access->canReadSection($section, $user)) {
                continue;
            }

            $sectionArticles = array_filter(
                $readable,
                static fn (HelpArticle $article): bool => $article->getSection()?->getId() === $section->getId(),
            );

            // A card that leads to an empty page is worse than no card: sections exist per audience,
            // so a section whose every entry is addressed elsewhere simply does not show up here.
            if ([] !== $sectionArticles) {
                $cards[] = $section;
            }
        }

        $faqs = array_slice(
            array_filter($readable, static fn (HelpArticle $a): bool => HelpArticleKind::Faq === $a->getKind()),
            0,
            self::HOME_FAQ_LIMIT,
        );

        $popular = array_filter($readable, static fn (HelpArticle $a): bool => HelpArticleKind::Article === $a->getKind());
        usort($popular, static fn (HelpArticle $a, HelpArticle $b): int => $b->getViewCount() <=> $a->getViewCount());

        return $this->render('help/index.html.twig', [
            'sections' => $cards,
            'faqs' => $faqs,
            'popular' => array_slice($popular, 0, self::HOME_POPULAR_LIMIT),
            'isHelpAdmin' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route(path: '/help/search', name: 'app_help_search', methods: ['GET'])]
    public function search(Request $request, HelpSearch $search): Response
    {
        $query = trim($request->query->getString('q'));
        $filter = $request->query->getString('kind');
        $kind = HelpArticleKind::tryFrom($filter);

        $readable = $this->access->filterArticles($this->articles->findAllWithSection(), $this->currentUser());

        /** @var array<string, HelpArticle> $byKey */
        $byKey = [];
        $rows = [];
        foreach ($readable as $article) {
            $key = (string) $article->getId();
            $byKey[$key] = $article;
            $rows[] = [
                'key' => $key,
                'title' => $article->getTitle(),
                // The body is searched as text: an article whose steps mention "tirage au sort"
                // must be findable on those words, not only on its title and chapeau.
                'text' => trim($article->getSummary().' '.$this->plainText($article->getBody())),
            ];
        }

        $hits = $search->search($query, $rows);

        $counts = [
            HelpArticleKind::Article->value => 0,
            HelpArticleKind::Faq->value => 0,
            HelpArticleKind::Glossary->value => 0,
        ];
        $results = [];
        foreach ($hits as $hit) {
            $article = $byKey[$hit['key']] ?? null;
            if (null === $article) {
                continue;
            }

            ++$counts[$article->getKind()->value];

            if (null !== $kind && $article->getKind() !== $kind) {
                continue;
            }

            $results[] = [
                'article' => $article,
                'title' => $search->segments($article->getTitle(), $query),
                'excerpt' => $hit['excerpt'],
            ];
        }

        return $this->render('help/search.html.twig', [
            'query' => $query,
            'kind' => $kind,
            'results' => $results,
            'counts' => $counts,
            'total' => array_sum($counts),
        ]);
    }

    #[Route(path: '/help/{sectionSlug}', name: 'app_help_section', requirements: ['sectionSlug' => self::SECTION_SLUG], methods: ['GET'])]
    public function section(string $sectionSlug): Response
    {
        $section = $this->findSection($sectionSlug);
        $articles = $this->access->filterArticles($section->getArticles(), $this->currentUser());

        return $this->render('help/section.html.twig', [
            'section' => $section,
            'articles' => array_filter($articles, static fn (HelpArticle $a): bool => HelpArticleKind::Article === $a->getKind()),
            'faqs' => array_filter($articles, static fn (HelpArticle $a): bool => HelpArticleKind::Faq === $a->getKind()),
            'terms' => array_filter($articles, static fn (HelpArticle $a): bool => HelpArticleKind::Glossary === $a->getKind()),
            'isHelpAdmin' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route(path: '/help/{sectionSlug}/{articleSlug}', name: 'app_help_article', requirements: ['sectionSlug' => self::SECTION_SLUG], methods: ['GET'])]
    public function article(string $sectionSlug, string $articleSlug, EntityManagerInterface $entityManager, HelpArticleOutline $outline): Response
    {
        $section = $this->findSection($sectionSlug);
        $article = $this->articles->findOneBySlug($section, $articleSlug) ?? throw $this->createNotFoundException();

        if (!$this->access->canReadArticle($article, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        // Counted on the way in, and only for a full article - a FAQ answer or a glossary term has
        // no page of its own to rank.
        if (HelpArticleKind::Article === $article->getKind()) {
            $article->incrementViewCount();
            $entityManager->flush();
        }

        $siblings = array_values(array_filter(
            $this->access->filterArticles($section->getArticles(), $this->currentUser()),
            static fn (HelpArticle $a): bool => HelpArticleKind::Article === $a->getKind() && $a->getId() !== $article->getId(),
        ));

        return $this->render('help/article.html.twig', [
            'section' => $section,
            'article' => $article,
            'outline' => $outline->build($article->getBody()),
            'nextToRead' => array_slice($siblings, 0, self::NEXT_TO_READ_LIMIT),
            'isHelpAdmin' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route(path: '/help/{sectionSlug}/{articleSlug}/feedback', name: 'app_help_article_feedback', requirements: ['sectionSlug' => self::SECTION_SLUG], methods: ['POST'])]
    public function feedback(string $sectionSlug, string $articleSlug, Request $request, EntityManagerInterface $entityManager): Response
    {
        $section = $this->findSection($sectionSlug);
        $article = $this->articles->findOneBySlug($section, $articleSlug) ?? throw $this->createNotFoundException();

        if (!$this->access->canReadArticle($article, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('help_feedback', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $article->recordHelpfulVote('yes' === $request->request->getString('helpful'));
        $entityManager->flush();

        $this->addFlash('success', 'helpFeedbackThanksFlashMessage');

        // A POST handled by Turbo has to redirect (see CLAUDE.md) - and landing back on the article
        // is what the reader expects anyway.
        return $this->redirectToRoute('app_help_article', [
            'sectionSlug' => $section->getSlug(),
            'articleSlug' => $article->getSlug(),
        ]);
    }

    private function findSection(string $slug): HelpSection
    {
        $section = $this->sections->findOneBySlug($slug) ?? throw $this->createNotFoundException();

        if (!$this->access->canReadSection($section, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $section;
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function plainText(?string $html): string
    {
        if (null === $html) {
            return '';
        }

        // Only the *block* boundaries become a space: separating on every tag would insert one
        // before each <strong>, and the excerpt would read " , " where the source had none.
        $separated = preg_replace('#</(?:p|h[1-6]|li|ul|ol|div|tr|td|th|blockquote|table)>#i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($separated), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
