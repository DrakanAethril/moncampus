<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Attribute\RequiresFeature;
use App\Entity\ProxmoxHost;
use App\Enum\Feature;
use App\Enum\ProxmoxCredentialKind;
use App\Enum\ProxmoxTlsMode;
use App\Form\ProxmoxHostType;
use App\Repository\ProxmoxHostRepository;
use App\Service\Crypto\SecretBoxException;
use App\Service\Crypto\SecretBoxProvider;
use App\Service\FormValue;
use App\Service\JsonRequestPayload;
use App\Service\Proxmox\ProxmoxCertificateInspector;
use App\Service\Proxmox\ProxmoxCheckWarning;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxFailureMessage;
use App\Service\Proxmox\ProxmoxHostChecker;
use App\Service\Proxmox\ProxmoxSecretWriter;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Declaring, editing, testing and deactivating a Proxmox host.
 *
 * Two things about this screen are load-bearing rather than cosmetic.
 *
 * A host is **deactivated, never deleted** - the same logical-deactivation shape as every
 * configuration entity in Paramètres. The operations log points at hosts, and a deleted row would
 * take its own history with it.
 *
 * "Tester la connexion" tests **the values in the form**, before they are saved. Testing a saved
 * declaration would mean storing a broken one first, every single time somebody mistypes a
 * password - so the endpoint takes the submitted values and builds a throwaway client from them.
 * It is a JSON endpoint driven by fetch rather than a second submit button, because a nested
 * <form> inside the host form is one of the two bugs this repository keeps rediscovering; its CSRF
 * token travels in the X-CSRF-Token header, which is the other half of that pair.
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::Infrastructure)]
class HostController extends AbstractController
{
    use InfrastructureTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ProxmoxFailureMessage $failureMessage,
    ) {
    }

    #[Route(path: '/infrastructure/hosts', name: 'app_infrastructure_hosts')]
    public function index(ProxmoxHostRepository $repository, SecretBoxProvider $secretBoxProvider): Response
    {
        return $this->render('infrastructure/hosts.html.twig', [
            'activeNav' => 'hosts',
            // Deactivated hosts included: this is the screen where one is brought back, so hiding
            // them here would make deactivation a one-way door.
            'hosts' => $repository->findOrdered(true),
            'encryptionAvailable' => $secretBoxProvider->isAvailable(),
            'encryptionFailure' => $secretBoxProvider->unavailableReason(),
        ]);
    }

    #[Route(path: '/infrastructure/hosts/new', name: 'app_infrastructure_hosts_new')]
    #[Route(path: '/infrastructure/hosts/{id}/edit', name: 'app_infrastructure_hosts_edit', requirements: ['id' => '\d+'])]
    public function form(
        Request $request,
        EntityManagerInterface $entityManager,
        ProxmoxHostRepository $repository,
        ProxmoxSecretWriter $secretWriter,
        SecretBoxProvider $secretBoxProvider,
        ?int $id = null,
    ): Response {
        $host = null !== $id ? $this->findHostOrNotFound($repository, $id) : null;
        $isEdit = null !== $host;

        // A blank host rather than null for the "new" case, so the form starts on the entity's own
        // defaults: the API-token mode preselected (it is the recommended one and the form says
        // so), the cluster-CA TLS mode, port 8006, and starting/stopping allowed. Bound to null,
        // Symfony has no object to read those from - every radio would render unchecked, and the
        // screen would open on a state the design never proposes.
        $form = $this->createForm(ProxmoxHostType::class, $host ?? new ProxmoxHost('', '', ''));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ProxmoxHost $entity */
            $entity = $form->getData();

            $secret = FormValue::string($form, 'secret');
            $provisionSecret = FormValue::string($form, 'provisionSecret');

            // A brand-new host has to arrive with a secret; an edited one keeps the one it has
            // unless a new value was actually typed. See App\Service\Proxmox\ProxmoxSecretWriter.
            if (!$isEdit && '' === $secret) {
                $form->get('secret')->addError(new FormError($this->translator->trans('proxmoxHostSecretRequiredError')));
            } elseif (!$secretBoxProvider->isAvailable()) {
                $form->addError(new FormError($this->translator->trans('proxmoxEncryptionUnavailableError')));
            } else {
                try {
                    $secretWriter->apply($entity, $secret, $provisionSecret);
                } catch (SecretBoxException) {
                    $form->addError(new FormError($this->translator->trans('proxmoxEncryptionUnavailableError')));
                }
            }

            if (0 === \count($form->getErrors(true))) {
                if ($isEdit) {
                    $entity->setLastUpdatedBy($this->currentUser());
                    $entity->setLastUpdatedDate(new \DateTimeImmutable());
                } else {
                    $entity->setCreatedBy($this->currentUser());
                    $entity->setPosition($repository->nextPosition());
                }

                $entityManager->persist($entity);
                $entityManager->flush();

                $this->addFlash('success', $isEdit ? 'proxmoxHostUpdatedFlashMessage' : 'proxmoxHostCreatedFlashMessage');

                return $this->redirectToRoute('app_infrastructure_hosts');
            }
        }

        return $this->render('infrastructure/host_form.html.twig', [
            'activeNav' => 'hosts',
            'form' => $form,
            'host' => $host,
            'isEdit' => $isEdit,
            'encryptionAvailable' => $secretBoxProvider->isAvailable(),
            'encryptionFailure' => $secretBoxProvider->unavailableReason(),
        ]);
    }

    /**
     * Re-tests a saved host and writes down what it found. The badge on the cards moves only here
     * and in `app:proxmox:check` - never as a side effect of rendering a list.
     */
    #[Route(path: '/infrastructure/hosts/{id}/check', name: 'app_infrastructure_hosts_check', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function check(Request $request, ProxmoxHostRepository $repository, ProxmoxHostChecker $checker, int $id): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);
        $host = $this->findHostOrNotFound($repository, $id);

        $result = $checker->checkAndFlush($host);

        return $this->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'version' => $result->version,
            'nodeCount' => $result->nodeCount,
            'guestCount' => $result->guestCount,
            'runningCount' => $result->runningCount,
            'warnings' => array_map(
                fn (ProxmoxCheckWarning $warning): string => $this->translator->trans($warning->messageKey, $warning->parameters),
                $result->warnings,
            ),
        ]);
    }

    /**
     * Tests values that have no row yet - what the form's own button calls. Nothing is stored, and
     * the secret it receives lives for the duration of this request only.
     */
    #[Route(path: '/infrastructure/hosts/test-connection', name: 'app_infrastructure_hosts_test_connection', methods: ['POST'])]
    public function testConnection(
        Request $request,
        ProxmoxClientFactory $clientFactory,
        ProxmoxHostRepository $repository,
        SecretBoxProvider $secretBoxProvider,
    ): JsonResponse {
        $this->assertValidInfrastructureToken($request);

        $payload = JsonRequestPayload::fromRequest($request);
        $secret = $payload->string('secret');
        $hostId = $payload->int('hostId');

        // Editing a host without retyping its secret must still be testable, or the button would
        // be useless on exactly the screen where it is most wanted. The stored secret is opened
        // for this one call and goes no further - the answer below never carries it back.
        if ('' === $secret && null !== $hostId) {
            $existing = $repository->find($hostId);

            if (null !== $existing && $existing->hasSecret() && $secretBoxProvider->isAvailable()) {
                try {
                    $secret = $secretBoxProvider->get()->open($existing->getSecretCipher());
                } catch (SecretBoxException) {
                    return $this->json(['ok' => false, 'message' => $this->translator->trans('proxmoxEncryptionUnavailableError')]);
                }
            }
        }

        if ('' === $secret) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('proxmoxTestNoSecretMessage')]);
        }

        try {
            $client = $clientFactory->draft(
                $payload->string('hostname'),
                $payload->int('port') ?? ProxmoxHost::DEFAULT_PORT,
                ProxmoxCredentialKind::tryFrom($payload->string('credentialKind')) ?? ProxmoxCredentialKind::ApiToken,
                $payload->string('username'),
                '' !== $payload->string('realm') ? $payload->string('realm') : 'pve',
                '' !== $payload->string('tokenName') ? $payload->string('tokenName') : null,
                $secret,
                ProxmoxTlsMode::tryFrom($payload->string('tlsMode')) ?? ProxmoxTlsMode::Ca,
                '' !== $payload->string('tlsCaPem') ? $payload->string('tlsCaPem') : null,
                '' !== $payload->string('tlsPinSha256') ? $payload->string('tlsPinSha256') : null,
            );

            $version = $client->version()->string('version', '?');
            $pool = $payload->string('managedPool');

            return $this->json([
                'ok' => true,
                'version' => $version,
                'pool' => $pool,
                'poolMissing' => '' !== $pool && !$client->poolExists($pool),
            ]);
        } catch (ProxmoxUnavailableException $exception) {
            // 200 with ok:false, not a 4xx: an unreachable hypervisor is an ordinary answer to
            // "can you reach it?", and the screen renders it as a message rather than as a broken
            // request.
            return $this->json(['ok' => false, 'message' => $this->failureMessage->readable($exception)]);
        }
    }

    /**
     * Reads the certificate the host presents, so the form can show its two digests side by side
     * under their real names. Nothing is verified and nothing is stored - see
     * App\Service\Proxmox\ProxmoxCertificateInspector for why this is not the hole it looks like.
     */
    #[Route(path: '/infrastructure/hosts/inspect-certificate', name: 'app_infrastructure_hosts_inspect_certificate', methods: ['POST'])]
    public function inspectCertificate(Request $request, ProxmoxCertificateInspector $inspector): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);

        $payload = JsonRequestPayload::fromRequest($request);

        try {
            $certificate = $inspector->inspect($payload->string('hostname'), $payload->int('port') ?? ProxmoxHost::DEFAULT_PORT);
        } catch (ProxmoxUnavailableException $exception) {
            return $this->json(['ok' => false, 'message' => $this->failureMessage->readable($exception)]);
        }

        return $this->json([
            'ok' => true,
            'fingerprint' => $certificate->fingerprint,
            'publicKeyPin' => $certificate->publicKeyPin,
            'subject' => $certificate->subject,
            'issuer' => $certificate->issuer,
            'selfSigned' => $certificate->selfSigned,
            'validUntil' => $certificate->validUntil?->format('d/m/Y'),
        ]);
    }

    /**
     * Deactivates a host, or brings it back: it stops being offered anywhere, keeps its history.
     * There is no delete action, here or anywhere else in this area.
     */
    #[Route(path: '/infrastructure/hosts/{id}/deactivate', name: 'app_infrastructure_hosts_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(Request $request, EntityManagerInterface $entityManager, ProxmoxHostRepository $repository, int $id): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);
        $host = $this->findHostOrNotFound($repository, $id);

        if ($host->isActive()) {
            $host->setInactiveDate(new \DateTimeImmutable());
            $host->setInactivatedBy($this->currentUser());
        } else {
            $host->setInactiveDate(null);
            $host->setInactivatedBy(null);
        }

        $entityManager->flush();

        return $this->json(['success' => true, 'active' => $host->isActive()]);
    }
}
