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
use App\Service\HelpLocaleResolver;
use App\Service\HelpSearch;
use App\Service\QueryValue;
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
 * for the same reason the "À propos" screen lives at /about. They carry no language either: an
 * entry exists once per language it was written in, and which row a URL answers with depends on
 * the reader's own locale (App\Service\HelpLocaleResolver), so a link stays shareable between two
 * colleagues who do not read the app in the same language.
 *
 * Every screen here is open to any authenticated user and shows nothing but what that user's
 * audiences allow (App\Service\HelpAccess). Only the *link* into it is gated, in the profile menu:
 * students and tutors have no entry point yet, and a student who reaches /help by hand lands on an
 * empty help rather than on a 403 - there is nothing to protect, only content addressed to someone
 * else.
 *
 * Order matters in every screen below: filter on what this reader may read *first*, then pick the
 * language among what is left. The other way round, an entry whose translation is addressed to a
 * different audience would vanish instead of falling back to French.
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
        private readonly HelpLocaleResolver $locales,
    ) {
    }

    #[Route(path: '/help', name: 'app_help', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $readable = $this->readableArticles($request);

        $sections = $this->locales->sections($this->sections->findAllWithArticles(), $request->getLocale());
        usort($sections, static fn (HelpSection $a, HelpSection $b): int => [$a->getPosition(), $a->getTitle()] <=> [$b->getPosition(), $b->getTitle()]);

        $cards = [];
        foreach ($sections as $section) {
            if (!$this->access->canReadSection($section, $user)) {
                continue;
            }

            // Matched on the slug, not on the section's id: an article kept in French hangs off the
            // French row of a section whose English version is the one being shown.
            $sectionArticles = array_filter(
                $readable,
                static fn (HelpArticle $article): bool => $article->getSection()?->getSlug() === $section->getSlug(),
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
        $query = trim(QueryValue::string($request, 'q'));
        $kind = HelpArticleKind::tryFrom(QueryValue::string($request, 'kind'));

        /** @var array<string, HelpArticle> $byKey */
        $byKey = [];
        $rows = [];
        foreach ($this->readableArticles($request) as $article) {
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

        $counts = [
            HelpArticleKind::Article->value => 0,
            HelpArticleKind::Faq->value => 0,
            HelpArticleKind::Glossary->value => 0,
        ];
        $results = [];
        foreach ($search->search($query, $rows) as $hit) {
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
    public function section(string $sectionSlug, Request $request): Response
    {
        $section = $this->findSection($sectionSlug, $request);
        $articles = $this->readableArticles($request, $sectionSlug);

        return $this->render('help/section.html.twig', [
            'section' => $section,
            'articles' => array_filter($articles, static fn (HelpArticle $a): bool => HelpArticleKind::Article === $a->getKind()),
            'faqs' => array_filter($articles, static fn (HelpArticle $a): bool => HelpArticleKind::Faq === $a->getKind()),
            'terms' => array_filter($articles, static fn (HelpArticle $a): bool => HelpArticleKind::Glossary === $a->getKind()),
            'isHelpAdmin' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route(path: '/help/{sectionSlug}/{articleSlug}', name: 'app_help_article', requirements: ['sectionSlug' => self::SECTION_SLUG], methods: ['GET'])]
    public function article(string $sectionSlug, string $articleSlug, Request $request, EntityManagerInterface $entityManager, HelpArticleOutline $outline): Response
    {
        $section = $this->findSection($sectionSlug, $request);
        $siblings = $this->readableArticles($request, $sectionSlug);
        $article = $this->pickBySlug($siblings, $articleSlug) ?? throw $this->createNotFoundException();

        // Counted on the way in, and only for a full article - a FAQ answer or a glossary term has
        // no page of its own to rank. This is what feeds "Les plus consultés" on the home; there is
        // no other measurement of an article, the handoff having dropped the "was this useful?" vote.
        if (HelpArticleKind::Article === $article->getKind()) {
            $article->incrementViewCount();
            $entityManager->flush();
        }

        $nextToRead = array_values(array_filter(
            $siblings,
            static fn (HelpArticle $a): bool => HelpArticleKind::Article === $a->getKind() && $a->getId() !== $article->getId(),
        ));

        return $this->render('help/article.html.twig', [
            'section' => $section,
            'article' => $article,
            'outline' => $outline->build($article->getBody()),
            'nextToRead' => array_slice($nextToRead, 0, self::NEXT_TO_READ_LIMIT),
            'isHelpAdmin' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    /**
     * What this reader may read, in their own language, optionally inside one section.
     *
     * @return list<HelpArticle>
     */
    private function readableArticles(Request $request, ?string $sectionSlug = null): array
    {
        $articles = $this->articles->findAllWithSection();

        if (null !== $sectionSlug) {
            $articles = array_values(array_filter(
                $articles,
                static fn (HelpArticle $article): bool => $article->getSection()?->getSlug() === $sectionSlug,
            ));
        }

        $resolved = $this->locales->articles(
            $this->access->filterArticles($articles, $this->currentUser()),
            $request->getLocale(),
        );

        // Sorted here rather than in the query: the list mixes rows from the French and the English
        // row of the same section, so the order they came out of the database in is the order of
        // their *parent*, not their own. App\Service\HelpOrdering is what makes the two agree.
        usort($resolved, static fn (HelpArticle $a, HelpArticle $b): int => [$a->getPosition(), $a->getTitle()] <=> [$b->getPosition(), $b->getTitle()]);

        return $resolved;
    }

    /** @param list<HelpArticle> $articles */
    private function pickBySlug(array $articles, string $slug): ?HelpArticle
    {
        foreach ($articles as $article) {
            if ($article->getSlug() === $slug) {
                return $article;
            }
        }

        return null;
    }

    private function findSection(string $slug, Request $request): HelpSection
    {
        $candidates = array_values(array_filter(
            $this->sections->findAllBySlug($slug),
            fn (HelpSection $section): bool => $this->access->canReadSection($section, $this->currentUser()),
        ));

        return $this->locales->sections($candidates, $request->getLocale())[0]
            ?? throw $this->createNotFoundException();
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
