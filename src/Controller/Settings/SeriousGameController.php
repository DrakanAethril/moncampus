<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameTemplate;
use App\Enum\Feature;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameTemplateRepository;
use App\Security\Voter\SeriousGameVoter;
use App\SeriousGame\SeriousGameHealth;
use App\SeriousGame\SeriousGameManifest;
use App\SeriousGame\SeriousGameTemplates;
use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateDefinition;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paramètres › Serious games (design/validated/serious-games.md §8, « Administration »,
 * design/conception_serious_games/catalogue.html and generateurs.html): the games, their
 * publication, the answer kinds they accept, their question templates and their health.
 * Administrators only - every other account gets the 404 of a screen that does not exist.
 *
 * A game arrives unpublished from `app:serious-games:sync`; once published it is lit per role in
 * Paramètres › Fonctionnalités, in the « Serious games » block. A template version is read here,
 * put on the bench again, and - a draft whose bench is green - made the unit's current version.
 * Drafts are written with Claude through the connector (§9); nothing is deleted.
 */
#[RequiresFeature(Feature::SeriousGames)]
class SeriousGameController extends AbstractController
{
    /** The kinds of answer a game may accept, and how the screen names them. */
    public const array ANSWER_KINDS = ['short' => 'seriousGameAnswerKindShort', 'choice' => 'seriousGameAnswerKindChoice', 'order' => 'seriousGameAnswerKindOrder', 'spot' => 'seriousGameAnswerKindSpot'];

    #[Route(path: '/settings/serious-games', name: 'app_settings_serious_games', methods: ['GET'])]
    public function index(SeriousGameRepository $games, SeriousGameTemplateRepository $templates): Response
    {
        $this->assertManager();

        $rows = [];
        foreach ($games->findAllOrdered() as $game) {
            $manifest = new SeriousGameManifest($game->getManifest());
            $current = $templates->findCurrentByUnit($game);
            $rows[] = [
                'game' => $game,
                'units' => \count($manifest->units()),
                'installed' => \count($current),
                'hidden' => \count(array_filter($current, static fn (SeriousGameTemplate $t): bool => !self::servable($game, $t))),
            ];
        }

        return $this->render('settings/serious_game/index.html.twig', [
            'rows' => $rows,
            'kinds' => self::ANSWER_KINDS,
        ]);
    }

    #[Route(path: '/settings/serious-games/{slug}/publish', name: 'app_settings_serious_games_publish', methods: ['POST'])]
    public function publish(Request $request, string $slug, SeriousGameRepository $games, \Doctrine\ORM\EntityManagerInterface $entityManager): Response
    {
        $game = $this->managedGame($games, $slug);
        $this->assertToken($request, 'serious-game-publish-'.$slug);

        $game->setPublished('1' === $request->request->getString('published'));
        $entityManager->flush();
        $this->addFlash('success', $game->isPublished() ? 'seriousGamePublishedFlash' : 'seriousGameUnpublishedFlash');

        return $this->redirectToRoute('app_settings_serious_games');
    }

    #[Route(path: '/settings/serious-games/{slug}/answer-kinds', name: 'app_settings_serious_games_kinds', methods: ['POST'])]
    public function answerKinds(Request $request, string $slug, SeriousGameRepository $games, \Doctrine\ORM\EntityManagerInterface $entityManager): Response
    {
        $game = $this->managedGame($games, $slug);
        $this->assertToken($request, 'serious-game-kinds-'.$slug);

        $kinds = array_values(array_intersect(array_keys(self::ANSWER_KINDS), array_keys($request->request->all('kinds'))));
        if ([] === $kinds) {
            $this->addFlash('danger', 'seriousGameAnswerKindsEmptyFlash');

            return $this->redirectToRoute('app_settings_serious_games');
        }

        $game->setAnswerKinds($kinds);
        $entityManager->flush();
        $this->addFlash('success', 'seriousGameAnswerKindsSavedFlash');

        return $this->redirectToRoute('app_settings_serious_games');
    }

    #[Route(path: '/settings/serious-games/{slug}', name: 'app_settings_serious_games_game', methods: ['GET'])]
    public function game(string $slug, SeriousGameRepository $games, SeriousGameTemplateRepository $templates, SeriousGameHealth $health): Response
    {
        $game = $this->managedGame($games, $slug);
        $manifest = new SeriousGameManifest($game->getManifest());
        $versions = [];
        foreach ($templates->findForGame($game) as $template) {
            $versions[$template->getUnitCode()][] = $template;
        }

        $chapters = [];
        foreach ($manifest->chapters() as $chapter) {
            $units = [];
            foreach ($chapter['units'] as $unit) {
                $list = $versions[$unit['code']] ?? [];
                $current = array_values(array_filter($list, static fn (SeriousGameTemplate $t): bool => $t->isValidated()))[0] ?? null;
                $units[] = [
                    'unit' => $unit,
                    'current' => $current,
                    'drafts' => array_values(array_filter($list, static fn (SeriousGameTemplate $t): bool => $t->isDraft())),
                    'versions' => $list,
                    'hidden' => null !== $current && !self::servable($game, $current),
                ];
            }
            $chapters[] = ['chapter' => $chapter, 'units' => $units];
        }

        return $this->render('settings/serious_game/game.html.twig', [
            'game' => $game,
            'chapters' => $chapters,
            'health' => $health->forGame($game),
        ]);
    }

    #[Route(path: '/settings/serious-games/{slug}/templates/{unit}/{version}', name: 'app_settings_serious_games_template', requirements: ['version' => '\d+'], methods: ['GET'])]
    public function template(Request $request, string $slug, string $unit, int $version, SeriousGameRepository $games, SeriousGameTemplateRepository $templates, SeriousGameTemplates $versions): Response
    {
        $game = $this->managedGame($games, $slug);
        $template = $templates->findVersion($game, $unit, $version) ?? throw $this->createNotFoundException();
        $count = max(1, min(12, QueryValue::int($request, 'samples', 5)));

        return $this->render('settings/serious_game/template.html.twig', [
            'game' => $game,
            'template' => $template,
            'samples' => $versions->samples($template, $count),
            'describe' => TemplateBench::describe(...),
        ]);
    }

    #[Route(path: '/settings/serious-games/{slug}/templates/{unit}/{version}/bench', name: 'app_settings_serious_games_bench', requirements: ['version' => '\d+'], methods: ['POST'])]
    public function bench(Request $request, string $slug, string $unit, int $version, SeriousGameRepository $games, SeriousGameTemplateRepository $templates, SeriousGameTemplates $versions): Response
    {
        $game = $this->managedGame($games, $slug);
        $template = $templates->findVersion($game, $unit, $version) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'serious-game-template-'.$template->getId());

        $report = $versions->bench($template, SeriousGameTemplates::PROPOSE_SEEDS);
        $this->addFlash(true === $report['green'] ? 'success' : 'danger', true === $report['green'] ? 'seriousGameBenchGreenFlash' : 'seriousGameBenchRedFlash');

        return $this->redirectToRoute('app_settings_serious_games_template', ['slug' => $slug, 'unit' => $unit, 'version' => $version]);
    }

    #[Route(path: '/settings/serious-games/{slug}/templates/{unit}/{version}/validate', name: 'app_settings_serious_games_validate', requirements: ['version' => '\d+'], methods: ['POST'])]
    public function validate(Request $request, string $slug, string $unit, int $version, SeriousGameRepository $games, SeriousGameTemplateRepository $templates, SeriousGameTemplates $versions): Response
    {
        $game = $this->managedGame($games, $slug);
        $template = $templates->findVersion($game, $unit, $version) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'serious-game-template-'.$template->getId());

        try {
            $versions->validate($template, $this->getUser() instanceof \App\Entity\User ? $this->getUser() : null);
            $this->addFlash('success', 'seriousGameTemplateValidatedFlash');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_settings_serious_games_template', ['slug' => $slug, 'unit' => $unit, 'version' => $version]);
    }

    /** Whether a template asks at least one kind of answer the game accepts - else its unit is hidden. */
    private static function servable(SeriousGame $game, SeriousGameTemplate $template): bool
    {
        return (new TemplateDefinition($template->getUnitCode(), $template->getVersion(), $template->getDefinition()))->servableWith($game->getAnswerKinds());
    }

    private function managedGame(SeriousGameRepository $games, string $slug): SeriousGame
    {
        $this->assertManager();

        return $games->findOneBySlug($slug) ?? throw $this->createNotFoundException();
    }

    private function assertManager(): void
    {
        if (!$this->isGranted(SeriousGameVoter::MANAGE)) {
            throw $this->createNotFoundException();
        }
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
