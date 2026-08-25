<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FeatureRoleSetting;
use App\Enum\Feature;
use App\Enum\FeatureFamily;
use App\Repository\FeatureRoleSettingRepository;
use App\Repository\UserFeatureAccessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion > Fonctionnalités - the role matrix (design/validated/feature-access.md §9.1).
 *
 * It sits under Gestion, straight below Annuaire, rather than under Paramètres: what it settles is
 * what the establishment runs for whom, which is the same kind of decision as the accounts next to
 * it, not a piece of the pedagogical structure.
 *
 * **Admin-only, and carrying no `RequiresFeature` attribute of its own.** That is not an oversight:
 * an admin has every feature by construction, so no setting made here can close the screen the
 * settings are made on. It is the single reason the "the admin has everything, always" rule is not
 * negotiable (§8.8).
 *
 * There is deliberately **no `ROLE_ADMIN` column**. An admin does not read the matrix, and a column
 * that exists would eventually get unticked - which is exactly the lock-out this design exists to
 * avoid.
 *
 * One POST per line rather than one big form: a matrix of fifty rows by eight columns saved in one
 * go is a screen where an accidental click is invisible, and a line that saves on its own is a line
 * whose effect can be read straight away.
 */
#[IsGranted('ROLE_ADMIN')]
class FeatureMatrixController extends AbstractController
{
    public function __construct(
        private readonly FeatureRoleSettingRepository $roleSettings,
        private readonly UserFeatureAccessRepository $overrides,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/features', name: 'app_features')]
    public function index(): Response
    {
        $matrix = $this->roleSettings->matrix();
        $counts = $this->overrides->countsByFeature();
        $roles = Feature::managedRoles();

        $families = [];
        foreach (FeatureFamily::cases() as $family) {
            $rows = [];
            foreach (Feature::cases() as $feature) {
                if ($feature->family() !== $family) {
                    continue;
                }

                $cells = [];
                foreach ($roles as $role) {
                    // An absent pair is not a `false`: it falls back on the catalogue, and the
                    // screen shows that fallback ticked or not, exactly as the resolver reads it.
                    $cells[$role] = $matrix[$feature->value.'|'.$role] ?? $feature->defaultForRoles();
                }

                $rows[] = [
                    'feature' => $feature,
                    'cells' => $cells,
                    'parent' => $feature->parent(),
                    'programScoped' => $feature->isProgramScoped(),
                    'overrides' => $counts[$feature->value] ?? 0,
                ];
            }

            if ([] !== $rows) {
                $families[] = ['family' => $family, 'rows' => $rows];
            }
        }

        return $this->render('feature/matrix.html.twig', [
            'roles' => $roles,
            'families' => $families,
        ]);
    }

    /**
     * One line saved. The checkboxes that came back are the ticked ones; the rest are off - a
     * checkbox that is not sent is what "unticked" looks like on the wire, so the line is written
     * from the whole role list rather than from what arrived.
     *
     * Turbo handles the POST, so it redirects (a POST that renders is a POST Turbo drops).
     */
    #[Route(path: '/features/{feature}', name: 'app_features_save', methods: ['POST'])]
    public function save(Request $request, string $feature): Response
    {
        $case = Feature::tryFrom($feature) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('feature-matrix-'.$case->value, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var array<string, mixed> $ticked */
        $ticked = $request->request->all('roles');
        $existing = [];
        foreach ($this->roleSettings->findForFeature($case) as $row) {
            $existing[$row->getRole()] = $row;
        }

        foreach (Feature::managedRoles() as $role) {
            $enabled = \array_key_exists($role, $ticked);
            $row = $existing[$role] ?? null;

            if (null === $row) {
                $this->entityManager->persist(new FeatureRoleSetting($case, $role, $enabled));

                continue;
            }

            $row->setEnabled($enabled);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'featureMatrixSavedFlashMessage');

        return $this->redirectToRoute('app_features', ['_fragment' => $case->value]);
    }

    /**
     * The people who carry a derogation on one feature - what the counter on each line leads to.
     *
     * A read-only list: the derogation itself is set from the person's own card in the annuaire,
     * where the reader can see who they are looking at rather than a name in a list.
     */
    #[Route(path: '/features/{feature}/overrides', name: 'app_features_overrides')]
    public function overrides(string $feature): Response
    {
        $case = Feature::tryFrom($feature) ?? throw $this->createNotFoundException();

        return $this->render('feature/overrides.html.twig', [
            'feature' => $case,
            'rows' => $this->overrides->findForFeatureWithUsers($case),
        ]);
    }
}
