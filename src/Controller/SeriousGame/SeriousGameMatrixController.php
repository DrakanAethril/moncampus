<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Entity\SeriousGameRoleSetting;
use App\Enum\Feature;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameRoleSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One line of the « Serious games » block of the feature matrix saved
 * (design/validated/serious-games.md §3.1): which roles a game is lit for.
 *
 * The neighbour of App\Controller\FeatureMatrixController, and administered the same way: admin
 * only, no `RequiresFeature` of its own, one POST per line. **By role only** - no per-formation
 * setting, no individual derogation, no teacher opening a game to a class.
 */
#[IsGranted('ROLE_ADMIN')]
class SeriousGameMatrixController extends AbstractController
{
    public function __construct(
        private readonly SeriousGameRepository $games,
        private readonly SeriousGameRoleSettingRepository $roleSettings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/features/serious-games/{slug}', name: 'app_features_serious_game_save', methods: ['POST'])]
    public function save(Request $request, string $slug): Response
    {
        $game = $this->games->findOneBySlug($slug) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('serious-game-matrix-'.$slug, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var array<string, mixed> $ticked */
        $ticked = $request->request->all('roles');
        $existing = [];
        foreach ($this->roleSettings->findForGame($game) as $row) {
            $existing[$row->getRole()] = $row;
        }

        foreach (Feature::managedRoles() as $role) {
            $enabled = \array_key_exists($role, $ticked);
            $row = $existing[$role] ?? null;

            if (null === $row) {
                $this->entityManager->persist(new SeriousGameRoleSetting($game, $role, $enabled));

                continue;
            }

            $row->setEnabled($enabled);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'featureMatrixSavedFlashMessage');

        return $this->redirectToRoute('app_features', ['_fragment' => 'serious-game-'.$slug]);
    }
}
