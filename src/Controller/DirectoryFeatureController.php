<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserFeatureAccess;
use App\Enum\Feature;
use App\Enum\FeatureAccessState;
use App\Enum\PlatformActivityType;
use App\Repository\UserFeatureAccessRepository;
use App\Repository\UserRepository;
use App\Service\PlatformActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The « Fonctionnalités » block of a person's card in the annuaire
 * (design/validated/feature-access.md §9.2).
 *
 * « Une fonctionnalité s'éteint pour un rôle, et se rallume pour une personne » is the sentence the
 * whole design comes from, and this is the second half of it.
 *
 * **`défaut` is never stored.** The block offers three buttons; picking « Par défaut » deletes the
 * row rather than writing a third value. That is what leaves this table empty at deployment, with
 * the matrix deciding alone, and what keeps « par défaut » meaning "whatever the matrix says
 * today" rather than "whatever it said the day somebody clicked".
 *
 * Admin-only, like the matrix: an admin has every feature by construction, so no gesture made here
 * can shut the door it was made through.
 */
#[IsGranted('ROLE_ADMIN')]
class DirectoryFeatureController extends AbstractController
{
    public function __construct(
        private readonly UserFeatureAccessRepository $overrides,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlatformActivityRecorder $activityRecorder,
    ) {
    }

    #[Route(path: '/directory/users/{id}/features', name: 'app_directory_users_features', methods: ['POST'])]
    public function save(Request $request, UserRepository $users, int $id): Response
    {
        $user = $users->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('user-features-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('states');
        $changed = [];

        foreach (Feature::cases() as $feature) {
            $choice = $submitted[$feature->value] ?? null;

            if (!\is_string($choice)) {
                continue;
            }

            // Anything that is not one of the two stored states means « Par défaut ». Read that way
            // round rather than matching a magic string: the third button carries a value the enum
            // deliberately does not know, because "par défaut" is the absence of a row.
            $state = FeatureAccessState::tryFrom($choice);

            if ($this->apply($user, $feature, $state)) {
                $changed[$feature->value] = null === $state ? 'default' : $state->value;
            }
        }

        $this->entityManager->flush();

        // Only when something actually moved: opening a card and pressing Enregistrer is not an act
        // on somebody's account, and a journal full of no-ops is a journal nobody reads.
        if ([] !== $changed) {
            $this->activityRecorder->record(PlatformActivityType::FeatureOverrideChanged, $this->currentUser(), $request, [
                'target' => $user->getUsername(),
                // Flattened rather than nested: the payload is a map of strings, and what a reader
                // of the journal wants is which features moved and where to, on one line.
                'changes' => implode(', ', array_map(
                    static fn (string $feature, string $state): string => $feature.'='.$state,
                    array_keys($changed),
                    $changed,
                )),
            ]);
        }

        $this->addFlash('success', 'featureOverrideSavedFlashMessage');

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $id, '_fragment' => 'features']);
    }

    /**
     * Writes one line, and answers whether anything actually changed - « Par défaut » on a feature
     * that already had no row is not a decision, and must not be recorded as one.
     */
    private function apply(User $user, Feature $feature, ?FeatureAccessState $state): bool
    {
        $existing = $this->overrides->findOneFor($user, $feature);

        if (null === $state) {
            if (null === $existing) {
                return false;
            }

            // The row goes, rather than a third value being written: that is what makes
            // « par défaut » mean "whatever the matrix says today".
            $this->entityManager->remove($existing);

            return true;
        }

        if (null === $existing) {
            $this->entityManager->persist(new UserFeatureAccess($user, $feature, $state));

            return true;
        }

        if ($existing->getState() === $state) {
            return false;
        }

        $existing->setState($state);

        return true;
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
