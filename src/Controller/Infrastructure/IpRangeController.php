<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Entity\IpRange;
use App\Enum\IpAllocationOrigin;
use App\Enum\IpAllocationStatus;
use App\Form\IpRangeType;
use App\Repository\IpAllocationRepository;
use App\Repository\IpRangeRepository;
use App\Service\JsonRequestPayload;
use App\Service\Network\AddressGap;
use App\Service\Network\IpAllocator;
use App\Service\Network\IpRangeCalculator;
use App\Service\Network\RangeScanner;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Declaring networks, and keeping their registry honest.
 *
 * The registry screen shows **the gaps first and the register second**, and the order is the whole
 * point: what somebody needs on arriving is not the state of the register, it is the ways in which
 * it is wrong. A conflict wants a person now; a list of correctly-assigned addresses does not.
 *
 * None of the actions here writes to Proxmox. Adopting a discovery and freeing an orphan only bring
 * the register back into agreement with what exists - the footer of the gaps card says so, because
 * "Libérer" on a screen full of virtual machines reads like something that might delete one.
 */
#[IsGranted('ROLE_ADMIN')]
class IpRangeController extends AbstractController
{
    use InfrastructureTrait;

    #[Route(path: '/infrastructure/ip-ranges', name: 'app_infrastructure_ip_ranges')]
    public function index(IpRangeRepository $repository, IpAllocationRepository $allocations, IpRangeCalculator $calculator): Response
    {
        $rows = [];

        foreach ($repository->findOrdered(true) as $range) {
            $taken = $allocations->findLiveAddresses($range);

            $rows[] = [
                'range' => $range,
                'capacity' => $calculator->capacity($range->getFirstUsable(), $range->getLastUsable()),
                'free' => $calculator->freeCount($range->getFirstUsable(), $range->getLastUsable(), $taken),
                'used' => \count($taken),
            ];
        }

        return $this->render('infrastructure/ip_ranges.html.twig', [
            'activeNav' => 'ip_ranges',
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/infrastructure/ip-ranges/new', name: 'app_infrastructure_ip_ranges_new')]
    #[Route(path: '/infrastructure/ip-ranges/{id}/edit', name: 'app_infrastructure_ip_ranges_edit', requirements: ['id' => '\d+'])]
    public function form(
        Request $request,
        EntityManagerInterface $entityManager,
        IpRangeRepository $repository,
        IpRangeCalculator $calculator,
        ?int $id = null,
    ): Response {
        $range = null !== $id ? ($repository->find($id) ?? throw $this->createNotFoundException()) : null;
        $isEdit = null !== $range;

        $form = $this->createForm(IpRangeType::class, $range);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var IpRange $entity */
            $entity = $form->getData();

            if ($isEdit) {
                $entity->setLastUpdatedBy($this->currentUser());
                $entity->setLastUpdatedDate(new \DateTimeImmutable());
            } else {
                $entity->setCreatedBy($this->currentUser());
            }

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'ipRangeUpdatedFlashMessage' : 'ipRangeCreatedFlashMessage');

            // « Enregistrer et balayer » - on a fresh range this is almost always what is wanted,
            // because a newly declared network already contains machines.
            return null !== $request->request->get('scan')
                ? $this->redirectToRoute('app_infrastructure_ip_range', ['id' => $entity->getId(), 'scan' => 1])
                : $this->redirectToRoute('app_infrastructure_ip_ranges');
        }

        return $this->render('infrastructure/ip_range_form.html.twig', [
            'activeNav' => 'ip_ranges',
            'form' => $form,
            'range' => $range,
            'isEdit' => $isEdit,
            // The arithmetic default, offered so the window can be narrowed from something rather
            // than typed from nothing.
            'defaultBounds' => null !== $range ? $calculator->defaultUsableBounds($range->getCidr()) : null,
        ]);
    }

    #[Route(path: '/infrastructure/ip-ranges/{id}', name: 'app_infrastructure_ip_range', requirements: ['id' => '\d+'])]
    public function registry(
        Request $request,
        IpRangeRepository $repository,
        IpAllocationRepository $allocations,
        IpRangeCalculator $calculator,
        RangeScanner $scanner,
        int $id,
    ): Response {
        $range = $repository->find($id) ?? throw $this->createNotFoundException();

        // Scanned on demand rather than on every render: reading N guest configurations is a real
        // cost, and the screen is useful without it.
        $report = QueryValue::bool($request, 'scan') ? $scanner->scan($range) : null;

        $status = IpAllocationStatus::tryFrom(QueryValue::trimmed($request, 'status'));
        $origin = IpAllocationOrigin::tryFrom(QueryValue::trimmed($request, 'origin'));
        $search = QueryValue::trimmed($request, 'q');

        $taken = $allocations->findLiveAddresses($range);

        return $this->render('infrastructure/ip_range.html.twig', [
            'activeNav' => 'ip_ranges',
            'range' => $range,
            'report' => $report,
            'allocations' => $allocations->findAllFor($range, $search, $status, $origin),
            'statuses' => IpAllocationStatus::cases(),
            'origins' => IpAllocationOrigin::cases(),
            'filters' => ['q' => $search, 'status' => $status?->value, 'origin' => $origin?->value],
            'capacity' => $calculator->capacity($range->getFirstUsable(), $range->getLastUsable()),
            'free' => $calculator->freeCount($range->getFirstUsable(), $range->getLastUsable(), $taken),
            'used' => \count($taken),
        ]);
    }

    /**
     * Adopts one discovery, or every discovery of the last scan. Writes nothing to Proxmox: it
     * stops the register claiming those addresses are free.
     */
    #[Route(path: '/infrastructure/ip-ranges/{id}/adopt', name: 'app_infrastructure_ip_range_adopt', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adopt(Request $request, IpRangeRepository $repository, RangeScanner $scanner, IpAllocator $allocator, int $id): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);
        $range = $repository->find($id) ?? throw $this->createNotFoundException();

        $only = JsonRequestPayload::fromRequest($request)->string('ip');
        // Re-scanned rather than trusting what the browser is showing: the page may be minutes old,
        // and adopting an address a machine no longer carries would write a fresh lie into the
        // register.
        $report = $scanner->scan($range);

        $adopted = 0;
        foreach ($report->gaps as $gap) {
            if (AddressGap::DISCOVERED !== $gap->kind || ('' !== $only && $gap->ip !== $only)) {
                continue;
            }

            $guest = $gap->guests[0] ?? null;

            if (null !== $guest && null !== $allocator->adopt($range, $gap->ip, $guest->vmid, $guest->node, $guest->guestName, $guest->macAddress)) {
                ++$adopted;
            }
        }

        return $this->json(['ok' => true, 'adopted' => $adopted]);
    }

    /** Frees an address the register holds and no machine carries. */
    #[Route(path: '/infrastructure/ip-ranges/{id}/release', name: 'app_infrastructure_ip_range_release', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function release(Request $request, IpRangeRepository $repository, IpAllocationRepository $allocations, IpAllocator $allocator, int $id): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);
        $range = $repository->find($id) ?? throw $this->createNotFoundException();

        $ip = JsonRequestPayload::fromRequest($request)->string('ip');
        $allocation = '' !== $ip ? $allocations->findLiveByAddress($range, $ip) : null;

        if (null === $allocation) {
            return $this->json(['ok' => false, 'message' => 'ipAllocationNotFoundMessage']);
        }

        $allocator->release($allocation);

        return $this->json(['ok' => true]);
    }

    /**
     * Declares an address for something that is not a Proxmox guest - a printer, a switch. These
     * are never offered, and the scan never calls them orphaned.
     */
    #[Route(path: '/infrastructure/ip-ranges/{id}/external', name: 'app_infrastructure_ip_range_external', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function declareExternal(Request $request, IpRangeRepository $repository, IpAllocator $allocator, int $id): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);
        $range = $repository->find($id) ?? throw $this->createNotFoundException();

        $payload = JsonRequestPayload::fromRequest($request);
        $ip = $payload->string('ip');
        $note = $payload->string('note');

        if ('' === $ip || !$allocator->isInsideWindow($range, $ip)) {
            return $this->json(['ok' => false, 'message' => 'ipAllocationOutsideWindowMessage']);
        }

        $allocator->declareExternal($range, $ip, '' !== $note ? $note : $ip);

        return $this->json(['ok' => true]);
    }

    #[Route(path: '/infrastructure/ip-ranges/{id}/deactivate', name: 'app_infrastructure_ip_ranges_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(Request $request, EntityManagerInterface $entityManager, IpRangeRepository $repository, int $id): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);
        $range = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($range->isActive()) {
            $range->setInactiveDate(new \DateTimeImmutable());
            $range->setInactivatedBy($this->currentUser());
        } else {
            $range->setInactiveDate(null);
            $range->setInactivatedBy(null);
        }

        $entityManager->flush();

        return $this->json(['success' => true, 'active' => $range->isActive()]);
    }
}
