<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\GuestAccount;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\ProxmoxAction;
use App\Repository\GuestAccountRepository;
use App\Repository\IpAllocationRepository;
use App\Security\Voter\GuestAccountVoter;
use App\Service\Guest\GuestAccountService;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;
use App\Service\Guest\UserMachineFinder;
use App\Service\JsonRequestPayload;
use App\Service\Proxmox\GuestPowerRunner;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Mes machines virtuelles » - the screen for the person a machine was built for, rather than for
 * the person who built it.
 *
 * **Not under /infrastructure**, and that is the point: everything there is ROLE_ADMIN by
 * access_control, and this screen exists precisely for the students and the teachers who are not.
 * What they may do is decided per machine by App\Security\Voter\GuestAccountVoter - they hold an
 * account on it - never by a role, so nothing here widens as somebody gains one.
 *
 * Three verbs and no more: start it, shut it down, choose a password on it. Not « forcer l'arrêt »,
 * which is a power cut and an administrator's decision, and nothing that creates, deletes or
 * reconfigures - a machine of a practical class is not its user's to reshape.
 *
 * The password is the reason this screen had to exist at all. The accounts a batch creates are born
 * with a password that is generated, sent to the machine and forgotten on the spot: nobody knows
 * it, so until its owner sets one, the account exists and nobody can log into it. Setting it needs
 * SSH, which needs the machine to be running - which is why « démarrer » is here too and not only
 * on the administration side.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::MyVms)]
class MyMachineController extends AbstractController
{
    /** Start and shutdown, and deliberately not stop or reboot - see the class docblock. */
    private const array ALLOWED_ACTIONS = ['start', 'shutdown'];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route(path: '/my/machines', name: 'app_my_machines', methods: ['GET'])]
    public function index(UserMachineFinder $finder): Response
    {
        return $this->render('my_machine/index.html.twig', [
            'machines' => $finder->forUser($this->currentUser()),
        ]);
    }

    /**
     * Start or shut down one machine.
     *
     * The whole act goes through App\Service\Proxmox\GuestPowerRunner, exactly as the administration
     * screen does: the perimeter is checked again there, the operation is logged with the name of
     * whoever asked, and a lock stops two clicks from sending Proxmox two contradictory orders. A
     * student's click is not a lesser act than an administrator's, so it is not a lesser path.
     */
    #[Route(
        path: '/my/machines/{id}/{action}',
        name: 'app_my_machines_power',
        requirements: ['id' => '\d+', 'action' => 'start|shutdown'],
        methods: ['POST'],
    )]
    public function power(
        Request $request,
        GuestAccountRepository $accounts,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        GuestPowerRunner $runner,
        int $id,
        string $action,
    ): JsonResponse {
        $account = $this->ownAccount($request, $accounts, $id);
        $host = $account->getHost();
        $proxmoxAction = ProxmoxAction::tryFrom($action);

        if (null === $host || null === $proxmoxAction || !\in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw $this->createNotFoundException();
        }

        try {
            // Re-read from the hypervisor rather than trusted from the row on screen: whether this
            // is a template, and which pool it is in, is what the perimeter is judged on.
            $guest = $this->findGuest($clientFactory, $inventory, $account);
            $operation = $runner->run($host, $guest, $proxmoxAction, $this->currentUser());
        } catch (ProxmoxUnavailableException $exception) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans($exception->getMessage())]);
        }

        return $this->json(['ok' => true, 'operationId' => $operation->getId()]);
    }

    /**
     * The password its owner chooses, on their own account.
     *
     * Never stored, never answered back, and not the same act as the administration screen's reset:
     * that one invents a password and shows it once, this one takes the one somebody typed. Both
     * end at the same `chpasswd`.
     */
    #[Route(path: '/my/machines/{id}/password', name: 'app_my_machines_password', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function password(
        Request $request,
        GuestAccountRepository $accounts,
        IpAllocationRepository $allocations,
        GuestShellFactory $shellFactory,
        GuestAccountService $service,
        UserMachineFinder $finder,
        int $id,
    ): JsonResponse {
        $account = $this->ownAccount($request, $accounts, $id);
        $host = $account->getHost();
        $ip = $this->addressOf($finder, $allocations, $account);

        if (null === $host || null === $ip) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('myMachineNoAddressMessage')]);
        }

        $password = JsonRequestPayload::fromRequest($request)->string('password');

        try {
            $shell = $shellFactory->open($ip);
        } catch (GuestUnreachableException|PlatformKeyUnavailableException $exception) {
            // Almost always "it is switched off": said as such rather than as SSH's own words,
            // because starting it is the button right next to this one.
            return $this->json(['ok' => false, 'message' => $this->translator->trans('myMachineUnreachableMessage'), 'detail' => $exception->getMessage()]);
        }

        try {
            $service->setPassword($shell, $host, $account->getNode(), $account->getVmid(), $account->getLogin(), $password, $this->currentUser());
        } catch (\InvalidArgumentException|GuestUnreachableException $exception) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans($exception->getMessage())]);
        } finally {
            $shell->disconnect();
        }

        return $this->json(['ok' => true, 'message' => $this->translator->trans('myMachinePasswordChangedMessage')]);
    }

    /**
     * The account this request is about, or a refusal.
     *
     * Loaded by id and then judged, never searched by "the machines of the current user": the voter
     * is the single sentence that says who may act, and a query that filtered by user would be a
     * second, silent copy of it.
     */
    private function ownAccount(Request $request, GuestAccountRepository $accounts, int $id): GuestAccount
    {
        if (!$this->isCsrfTokenValid('my_machines_action', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $account = $accounts->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(GuestAccountVoter::OWN, $account);

        return $account;
    }

    /** @throws ProxmoxUnavailableException when the machine is not on this host any more */
    private function findGuest(ProxmoxClientFactory $clientFactory, ProxmoxInventory $inventory, GuestAccount $account): ProxmoxGuest
    {
        $host = $account->getHost();

        foreach ($inventory->guests($clientFactory->operate($host ?? throw new ProxmoxUnavailableException('proxmoxRefusalOutOfScope'))) as $guest) {
            if ($guest->vmid === $account->getVmid() && $guest->node === $account->getNode()) {
                return $guest;
            }
        }

        throw new ProxmoxUnavailableException('proxmoxRefusalOutOfScope');
    }

    /** Where the machine answers - the batch's own allocation first, the registry as a fallback. */
    private function addressOf(UserMachineFinder $finder, IpAllocationRepository $allocations, GuestAccount $account): ?string
    {
        foreach ($finder->forUser($this->currentUser()) as $machine) {
            if ($machine->account->getId() === $account->getId() && null !== $machine->ip) {
                return $machine->ip;
            }
        }

        return $allocations->findAddressForVmid($account->getVmid());
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
