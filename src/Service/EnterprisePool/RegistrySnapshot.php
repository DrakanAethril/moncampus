<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool;

use App\Entity\Enterprise;
use App\Entity\User;
use App\Repository\EnterpriseRepository;
use App\Service\CompanySearch\CompanySearchService;
use App\Service\Sirene\EstablishmentCandidate;
use App\Service\Sirene\RegistryCompany;
use App\Service\Sirene\SireneUnavailableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Between the register and the vivier: what the register says about an employer's confirmed
 * establishment, copied onto the Enterprise (design/validated/vivier-entreprises.md §5.1), and the
 * one way an establishment chosen in the register **becomes** an employer of the vivier.
 *
 * Choosing an establishment in the register is a person seeing what a SIRET designates, so the
 * employer it creates or finds carries that SIRET confirmed (R1) - the rule of the SIRET queue,
 * not an exception to it.
 */
class RegistrySnapshot
{
    /** A snapshot older than this is read again when its fiche is opened. */
    private const string STALE_AFTER = '-30 days';

    public function __construct(
        private readonly CompanySearchService $search,
        private readonly EnterpriseRepository $enterprises,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Reads the register again for a confirmed SIRET whose snapshot is missing or old. Best effort:
     * a register that does not answer leaves the fiche as it was. Does not flush.
     */
    public function refreshIfStale(Enterprise $enterprise): void
    {
        $siret = $enterprise->getSiret();
        if (null === $siret || null === $enterprise->getSiretConfirmedAt()) {
            return;
        }

        $readAt = $enterprise->getRegistryReadAt();
        if (null !== $readAt && $readAt > new \DateTimeImmutable(self::STALE_AFTER)) {
            return;
        }

        try {
            $company = $this->search->company($siret);
        } catch (SireneUnavailableException) {
            return;
        }

        if (null !== $company) {
            $this->record($enterprise, $company, $company->establishments[0]);
        }
    }

    public function record(Enterprise $enterprise, RegistryCompany $company, EstablishmentCandidate $establishment): void
    {
        $enterprise->recordRegistry(
            $establishment->postalCode,
            $establishment->latitude,
            $establishment->longitude,
            $company->activityCode,
            new \DateTimeImmutable(),
        );
    }

    /**
     * The employer of the vivier for this establishment of the register: the one already carrying
     * its SIRET - confirmed now, if it was not - or a new one. Persisted, not flushed.
     */
    public function enterpriseFor(RegistryCompany $company, User $by): Enterprise
    {
        $establishment = $company->establishments[0];
        $now = new \DateTimeImmutable();
        $enterprise = $this->enterprises->findOneBySiret($establishment->siret, $by);

        if (null === $enterprise) {
            $enterprise = new Enterprise($company->fullName, $establishment->address);
            $enterprise->setCity($establishment->city);
            $enterprise->setCreatedBy($by);
            $enterprise->setTestEnterprise($by->isTestUser());
            $this->entityManager->persist($enterprise);
        }

        if (null === $enterprise->getSiretConfirmedAt()) {
            $enterprise->confirmSiret($establishment->siret, $by, $now);
        }

        $this->record($enterprise, $company, $establishment);

        return $enterprise;
    }
}
