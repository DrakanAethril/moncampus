<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Enum\GuestAccountOrigin;
use App\Repository\GuestAccountRepository;
use App\Repository\IpAllocationRepository;
use App\Repository\ProxmoxHostRepository;
use App\Security\Voter\ProxmoxHostVoter;
use App\Service\Guest\GuestAccountService;
use App\Service\Guest\GuestCommandFailedException;
use App\Service\Guest\GuestShell;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyProvider;
use App\Service\Guest\PlatformKeyUnavailableException;
use App\Service\Guest\UnixLogin;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The accounts of one machine: what is wanted, what is there, and the difference between them.
 *
 * The screen is a **difference**, not a list, and that shapes everything: it opens by reading the
 * machine, so an account somebody created by hand inside it shows up here rather than being
 * silently ignored, and a student who left shows up as a proposal rather than as a deletion that
 * already happened.
 *
 * Three refusals are worth stating because they are all deliberate:
 *
 *  - **removals are never automatic.** Deleting a home directory is a decision, not a schedule.
 *  - **accounts MonCampus never asked for are never touched**, whatever the difference says.
 *  - **passwords are shown once and never stored.** The screen that shows them is made to be
 *    printed or read out, and losing one is answered by a reset rather than by a lookup.
 *
 * Everything here needs the PHP container to reach the machine's network. Where it cannot, this
 * screen says so plainly instead of failing at each button.
 */
#[IsGranted('ROLE_ADMIN')]
class GuestAccountController extends AbstractController
{
    use InfrastructureTrait;

    #[Route(
        path: '/infrastructure/hosts/{id}/guests/{node}/{vmid}/accounts',
        name: 'app_infrastructure_guest_accounts',
        requirements: ['id' => '\d+', 'vmid' => '\d+'],
    )]
    public function index(
        ProxmoxHostRepository $repository,
        GuestAccountRepository $accounts,
        IpAllocationRepository $allocations,
        PlatformKeyProvider $keyProvider,
        GuestShellFactory $shellFactory,
        GuestAccountService $service,
        int $id,
        string $node,
        int $vmid,
    ): Response {
        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::VIEW, $host);

        $ip = $allocations->findAddressForVmid($vmid);
        $plan = null;
        $failure = null;

        if (null !== $keyProvider->activeKey() && null !== $ip) {
            try {
                $shell = $shellFactory->open($ip);
                // Read on opening: what makes this a difference rather than a list of intentions.
                $plan = $service->refresh($shell, $host, $node, $vmid);
                $shell->disconnect();
            } catch (GuestUnreachableException|GuestCommandFailedException|PlatformKeyUnavailableException $exception) {
                $failure = $exception->getMessage();
            }
        }

        return $this->render('infrastructure/guest_accounts.html.twig', [
            'activeNav' => 'guests',
            'host' => $host,
            'node' => $node,
            'vmid' => $vmid,
            'ip' => $ip,
            'accounts' => $accounts->findForMachine($host, $node, $vmid),
            'plan' => $plan,
            'failure' => $failure,
            'hasKey' => null !== $keyProvider->activeKey(),
        ]);
    }

    /** Creates what the difference says is missing, and answers the passwords once. */
    #[Route(
        path: '/infrastructure/hosts/{id}/guests/{node}/{vmid}/accounts/apply',
        name: 'app_infrastructure_guest_accounts_apply',
        requirements: ['id' => '\d+', 'vmid' => '\d+'],
        methods: ['POST'],
    )]
    public function apply(
        Request $request,
        ProxmoxHostRepository $repository,
        IpAllocationRepository $allocations,
        GuestShellFactory $shellFactory,
        GuestAccountService $service,
        int $id,
        string $node,
        int $vmid,
    ): JsonResponse {
        $this->assertValidInfrastructureToken($request);

        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::OPERATE, $host);

        try {
            $shell = $this->shellFor($shellFactory, $allocations, $vmid);
            $plan = $service->refresh($shell, $host, $node, $vmid);
            $applied = $service->apply($shell, $host, $node, $vmid, \sprintf('vm-%d', $vmid), $plan, $this->currentUser());
            $shell->disconnect();
        } catch (GuestUnreachableException|GuestCommandFailedException|PlatformKeyUnavailableException $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()]);
        }

        // The one time these exist outside the machine. Nothing stores them; the screen shows them
        // and forgets them, and "I lost it" is answered by the reset button.
        return $this->json(['ok' => true, 'passwords' => $applied['passwords']]);
    }

    #[Route(
        path: '/infrastructure/hosts/{id}/guests/{node}/{vmid}/accounts/declare',
        name: 'app_infrastructure_guest_accounts_declare',
        requirements: ['id' => '\d+', 'vmid' => '\d+'],
        methods: ['POST'],
    )]
    public function declare(
        Request $request,
        ProxmoxHostRepository $repository,
        GuestAccountService $service,
        UnixLogin $unixLogin,
        int $id,
        string $node,
        int $vmid,
    ): JsonResponse {
        $this->assertValidInfrastructureToken($request);

        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::OPERATE, $host);

        $payload = JsonRequestPayload::fromRequest($request);
        $login = $payload->string('login');

        if (!$unixLogin->isValid($login)) {
            return $this->json(['ok' => false, 'message' => 'guestAccountInvalidLoginMessage']);
        }

        $service->declare($host, $node, $vmid, $login, GuestAccountOrigin::Fixed, $payload->bool('sudo'));

        return $this->json(['ok' => true]);
    }

    /** Removes one account, with its home directory. One at a time, from a button, never in a loop. */
    #[Route(
        path: '/infrastructure/hosts/{id}/guests/{node}/{vmid}/accounts/remove',
        name: 'app_infrastructure_guest_accounts_remove',
        requirements: ['id' => '\d+', 'vmid' => '\d+'],
        methods: ['POST'],
    )]
    public function remove(
        Request $request,
        ProxmoxHostRepository $repository,
        IpAllocationRepository $allocations,
        GuestShellFactory $shellFactory,
        GuestAccountService $service,
        int $id,
        string $node,
        int $vmid,
    ): JsonResponse {
        $this->assertValidInfrastructureToken($request);

        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::OPERATE, $host);

        $login = JsonRequestPayload::fromRequest($request)->string('login');

        try {
            $shell = $this->shellFor($shellFactory, $allocations, $vmid);
            $service->remove($shell, $host, $node, $vmid, $login);
            $shell->disconnect();
        } catch (GuestUnreachableException|GuestCommandFailedException|PlatformKeyUnavailableException|\InvalidArgumentException $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()]);
        }

        return $this->json(['ok' => true]);
    }

    /** Records that an account no longer wanted is being left alone, so it stops being proposed. */
    #[Route(
        path: '/infrastructure/hosts/{id}/guests/{node}/{vmid}/accounts/keep',
        name: 'app_infrastructure_guest_accounts_keep',
        requirements: ['id' => '\d+', 'vmid' => '\d+'],
        methods: ['POST'],
    )]
    public function keep(
        Request $request,
        ProxmoxHostRepository $repository,
        GuestAccountService $service,
        int $id,
        string $node,
        int $vmid,
    ): JsonResponse {
        $this->assertValidInfrastructureToken($request);

        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::OPERATE, $host);

        $service->keep($host, $node, $vmid, JsonRequestPayload::fromRequest($request)->string('login'));

        return $this->json(['ok' => true]);
    }

    /** A new password, answered once - the counterpart of never storing them. */
    #[Route(
        path: '/infrastructure/hosts/{id}/guests/{node}/{vmid}/accounts/reset',
        name: 'app_infrastructure_guest_accounts_reset',
        requirements: ['id' => '\d+', 'vmid' => '\d+'],
        methods: ['POST'],
    )]
    public function reset(
        Request $request,
        ProxmoxHostRepository $repository,
        IpAllocationRepository $allocations,
        GuestShellFactory $shellFactory,
        GuestAccountService $service,
        int $id,
        string $node,
        int $vmid,
    ): JsonResponse {
        $this->assertValidInfrastructureToken($request);

        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::OPERATE, $host);

        $login = JsonRequestPayload::fromRequest($request)->string('login');

        try {
            $shell = $this->shellFor($shellFactory, $allocations, $vmid);
            $password = $service->resetPassword($shell, $login);
            $shell->disconnect();
        } catch (GuestUnreachableException|GuestCommandFailedException|PlatformKeyUnavailableException|\InvalidArgumentException $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()]);
        }

        return $this->json(['ok' => true, 'login' => $login, 'password' => $password]);
    }

    /** @throws GuestUnreachableException|PlatformKeyUnavailableException */
    private function shellFor(GuestShellFactory $shellFactory, IpAllocationRepository $allocations, int $vmid): GuestShell
    {
        $ip = $allocations->findAddressForVmid($vmid);

        if (null === $ip) {
            // The registry is how anything here knows where a machine is; a machine created by hand
            // has no entry until the scan adopts one, and there is nothing to connect to.
            throw new GuestUnreachableException('No address is recorded for this machine - scan its range first.');
        }

        return $shellFactory->open($ip);
    }
}
