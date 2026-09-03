<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Se connecter en tant que » - the administrator's way of seeing the application through somebody
 * else's account, from the profile menu and nowhere else.
 *
 * It is deliberately **not** a Feature (App\Enum\Feature) and does not appear in the changelog: an
 * establishment does not run this, one administrator uses it to reproduce what a student is
 * describing. Two consequences worth stating: there is no entry in Gestion > Fonctionnalités to
 * switch it off, and App\Tests\Functional\FeatureCoverageTest exempts these routes under
 * "administering the platform, including this system itself".
 *
 * The switch itself is Symfony's own (`switch_user` on the `main` firewall): this controller only
 * picks the account and hands the identifier over as `_switch_user` on a URL the firewall reads.
 * The two rules it must never be the only holder of are:
 *
 * - only an administrator may ask - `switch_user: { role: ROLE_ADMIN }`, plus the #[IsGranted]
 *   below so the picker itself is not a roster anyone can read;
 * - never another administrator - App\Security\ImpersonationSubscriber, because the switch is a
 *   query parameter on *any* URL and therefore reachable without passing through here at all.
 *
 * Leaving needs no route: `impersonation_exit_path()` in the banner appends `_switch_user=_exit` to
 * whatever page the administrator is standing on.
 */
#[IsGranted('ROLE_ADMIN')]
class ImpersonationController extends AbstractController
{
    /**
     * The account picker - tomselect + ajax, per the repository's rule that picking Users always
     * goes through one.
     *
     * Administrators are excluded from the list because they are excluded from the gesture, and a
     * deactivated account is excluded because the firewall's user_checker would refuse it anyway -
     * offering either would only mean a name that answers 403 once clicked.
     */
    #[Route(path: '/impersonate/search', name: 'app_impersonate_search', methods: ['GET'])]
    public function search(Request $request, UserRepository $users): JsonResponse
    {
        $limit = 20;
        $candidates = \array_slice($this->candidates($users, QueryValue::trimmed($request, 'q')), 0, $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                // The login is part of the label rather than hidden behind it: two people share a
                // display name often enough, and the login is what the administrator was given by
                // whoever reported the problem.
                'text' => \sprintf('%s (%s)', $user->getDisplayName() ?? $user->getUsername(), $user->getUsername()),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    /**
     * Starts the impersonation: resolves the picked id, then sends the administrator to the home
     * page carrying `_switch_user`, which the firewall consumes before any controller runs.
     *
     * POST and CSRF-checked, although the firewall would refuse a non-administrator on its own: the
     * gesture changes who the browser is for every subsequent request, which is not something a
     * crafted link on another site gets to do on an administrator's behalf.
     */
    #[Route(path: '/impersonate', name: 'app_impersonate', methods: ['POST'])]
    public function start(Request $request, UserRepository $users): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('impersonate', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $id = $request->request->getInt('user');
        $target = 0 !== $id ? $users->find($id) : null;

        // Re-read through the same candidate list the picker is built from rather than trusting the
        // submitted id: the identity that reaches the firewall must have passed the same filter as
        // the name that was displayed.
        $allowedIds = array_map(static fn (User $user): int => (int) $user->getId(), $this->candidates($users, null));

        if (!$target instanceof User || !\in_array((int) $target->getId(), $allowedIds, true)) {
            $this->addFlash('error', 'impersonateUnavailableFlashMessage');

            return $this->redirectToRoute('app_home');
        }

        return $this->redirectToRoute('app_home', ['_switch_user' => $target->getUserIdentifier()]);
    }

    /** @return list<User> */
    private function candidates(UserRepository $users, ?string $search): array
    {
        $self = $this->getUser();

        return $users->findActiveExcludingRoles(
            ['ROLE_ADMIN'],
            $self instanceof User ? [(int) $self->getId()] : [],
            $search,
        );
    }
}
