<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Repository\LdapManageAccountRepository;
use App\Repository\UserRepository;
use App\Service\LdapAccountApplier;
use App\Service\LdapAccountRequestException;
use App\Service\LdapAccountRequestService;
use App\Service\LdapAccountStatusPresenter;
use App\Service\LoginGenerator;
use App\Service\PostValue;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The three gestures that bear on an account which already exists - deactivate it, reactivate it,
 * rename it - and the screen that follows them.
 *
 * **A controller of its own, and ROLE_ADMIN on the class.** The rest of the Annuaire is open to
 * staff, this is not, and the difference had been living inside two `isGranted()` calls in the
 * middle of App\Controller\DirectoryUserController - the kind of guard that a later harmonisation of
 * controller attributes removes without anything showing on screen. Here the door is the class
 * attribute, and RoleAccessSmokeTest has a line per role saying so. There is deliberately no
 * ROLE_STAFF exception, whatever the neighbouring passwords screen may grant.
 *
 * The two halves of the asymmetry that the whole design rests on both live here:
 *
 *  - deactivate()/reactivate() switch App\Entity\User::$inactiveDate **at the click**, then queue
 *    the request. Closing the platform needs the directory's permission for nothing, and if the
 *    script later fails, the state that is left is the safe one: MonCampus closed, the directory
 *    not yet.
 *  - a rename never touches $username here. It waits for the directory to confirm - see
 *    App\Service\LdapAccountApplier.
 */
#[IsGranted('ROLE_ADMIN')]
class DirectoryAccountController extends AbstractController
{
    #[Route(path: '/directory/users/{id}/deactivate', name: 'app_directory_users_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(
        Request $request,
        UserRepository $users,
        LdapAccountRequestService $accountRequests,
        EntityManagerInterface $entityManager,
        int $id,
    ): Response {
        $user = $users->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('directory_user_deactivate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // The date is set before the queue row, and both go out on the service's single flush: the
        // platform closing and the directory being told are one write, or the screen could show a
        // closed account with nothing queued behind it. The service is also what refuses an
        // administrator closing their own account, which is why nothing checks it here.
        $wasActive = null === $user->getInactiveDate();
        $user->setInactiveDate(new \DateTimeImmutable());
        $user->setInactivatedBy($currentUser);

        try {
            $accountRequests->disable($user, $currentUser);
        } catch (LdapAccountRequestException $exception) {
            // Nothing was written: the service throws before persisting, and the two lines above
            // are dropped with the unit of work rather than flushed on their own.
            $entityManager->refresh($user);
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
        }

        $this->addFlash('success', $wasActive ? 'userDeactivatedFlashMessage' : 'userDeactivationRequeuedFlashMessage');

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
    }

    #[Route(path: '/directory/users/{id}/reactivate', name: 'app_directory_users_reactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reactivate(
        Request $request,
        UserRepository $users,
        LdapAccountRequestService $accountRequests,
        EntityManagerInterface $entityManager,
        int $id,
    ): Response {
        $user = $users->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('directory_user_reactivate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $user->setInactiveDate(null);
        $user->setInactivatedBy(null);

        try {
            $accountRequests->enable($user, $currentUser);
        } catch (LdapAccountRequestException $exception) {
            $entityManager->refresh($user);
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
        }

        $this->addFlash('success', 'userReactivatedFlashMessage');

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
    }

    /**
     * What the fiche's banner polls, every two seconds.
     *
     * It re-reads the directory on the way through (LdapAccountApplier), so the screen is right
     * straight away - but the banner does not depend on it: it is rendered by the server on the
     * fiche itself, and refreshing or coming back from another machine shows the same state. The
     * polling makes it live, it does not make it exist. The cron command is what carries the work
     * when nobody is watching.
     */
    #[Route(path: '/directory/users/{id}/account-status', name: 'app_directory_account_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(
        UserRepository $users,
        LdapManageAccountRepository $requests,
        LdapAccountApplier $applier,
        LdapAccountStatusPresenter $presenter,
        int $id,
    ): JsonResponse {
        $user = $users->find($id) ?? throw $this->createNotFoundException();
        $latest = $requests->findMostRecentForUser($user);

        if (null !== $latest) {
            $applier->process($latest);
        }

        return $this->json([
            'status' => $presenter->present($latest),
            'html' => null === $latest ? '' : $this->renderView('directory/_account_banner.html.twig', [
                'accountStatus' => $presenter->present($latest),
                'editedUser' => $user,
            ]),
        ]);
    }

    /**
     * « Changer le login ».
     *
     * It queues the request and nothing else - App\Entity\User::$username is not touched here, and
     * that is the whole asymmetry of this feature: LdapCredentialsVerifier looks the directory up by
     * the local name, so a username written ahead of the directory makes the account unreachable on
     * both sides at once. The switch happens in App\Service\LdapAccountApplier, once the rename has
     * been confirmed *and* read back.
     *
     * Every refusal comes back on the fiche as a flash - taken, unchanged, malformed, or a gesture
     * already under way. The rules are the service's, not this action's: the modal is one caller.
     */
    #[Route(path: '/directory/users/{id}/change-login', name: 'app_directory_account_change_login', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeLogin(
        Request $request,
        UserRepository $users,
        LdapAccountRequestService $accountRequests,
        int $id,
    ): Response {
        $user = $users->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('directory_account_change_login', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $accountRequests->changeLogin($user, PostValue::string($request, 'new_login'), $currentUser);
            $this->addFlash('success', 'ldapAccountLoginChangeQueuedFlashMessage');
        } catch (LdapAccountRequestException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
    }

    /**
     * "Is this login free?", asked while the administrator types.
     *
     * Against **both** sources, which is App\Service\LoginGenerator::loginTaken()'s whole point: a
     * login reserved by a creation that never went through is taken every bit as much as one
     * somebody carries. It is also why an old login stays reserved for ever after a rename.
     *
     * The answer is advisory. The request itself re-runs the same checks when it is posted, because
     * between typing and validating anything may have happened.
     */
    #[Route(path: '/directory/users/{id}/login-availability', name: 'app_directory_account_login_availability', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function loginAvailability(
        Request $request,
        UserRepository $users,
        LdapAccountRequestService $accountRequests,
        LoginGenerator $loginGenerator,
        int $id,
    ): JsonResponse {
        $user = $users->find($id) ?? throw $this->createNotFoundException();
        $login = $accountRequests->normaliseLogin(QueryValue::trimmed($request, 'login'));

        if ('' === $login) {
            return $this->json(['login' => '', 'state' => 'empty']);
        }

        if (1 !== preg_match(LdapAccountRequestService::LOGIN_PATTERN, $login)) {
            return $this->json(['login' => $login, 'state' => 'invalid']);
        }

        if ($login === mb_strtolower($user->getUsername())) {
            return $this->json(['login' => $login, 'state' => 'current']);
        }

        return $this->json([
            'login' => $login,
            'state' => $loginGenerator->loginTaken($login) ? 'taken' : 'available',
        ]);
    }

    /**
     * Retrying inserts a **new** row rather than putting the old one back to state 0 - the same
     * reasoning as the class import: a queue row is the trace of one attempt, and a counter reset to
     * zero loses the only record that anything was tried.
     */
    #[Route(path: '/directory/accounts/{id}/retry', name: 'app_directory_account_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(
        Request $request,
        LdapManageAccountRepository $requests,
        LdapAccountRequestService $accountRequests,
        int $id,
    ): Response {
        $failed = $requests->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('directory_account_retry', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $accountRequests->retry($failed, $currentUser);
            $this->addFlash('success', 'ldapAccountRetryQueuedFlashMessage');
        } catch (LdapAccountRequestException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $this->userIdOf($failed)]);
    }

    private function userIdOf(LdapManageAccount $request): int
    {
        return $request->getUser()->getId() ?? throw $this->createNotFoundException();
    }
}
