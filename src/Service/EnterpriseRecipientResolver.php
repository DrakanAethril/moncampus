<?php

namespace App\Service;

use App\Entity\Enterprise;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Repository\EnterpriseRepository;
use App\Repository\JobApplicationRepository;
use Doctrine\DBAL\Connection;

/**
 * Links a recipient address to a company as the student types the "To" field of the compose screen
 * (design_handoff_stage_alternance, screen 3g).
 *
 * Three cases, and the handoff's principle #4 makes this a *blocking* step: nothing leaves until
 * the company is known. That is what guarantees a mail always lands inside an application, and
 * therefore that the reply - linked later through In-Reply-To - knows where to go.
 *
 * 1. address already seen on the platform -> silent link;
 * 2. non-generic domain already known to a company -> we offer, we do not decide;
 * 3. unknown or generic domain -> creation is mandatory.
 *
 * A generic domain (gmail...) is never written onto a company: linking there happens on the full
 * address, otherwise every individual on the same provider would become the same company.
 */
class EnterpriseRecipientResolver
{
    public const string CASE_LINKED = 'linked';
    public const string CASE_CONFIRM = 'confirm';
    public const string CASE_CREATE = 'create';

    /** @param list<string> $genericEmailDomains */
    public function __construct(
        private readonly EnterpriseRepository $enterpriseRepository,
        private readonly JobApplicationRepository $applicationRepository,
        private readonly Connection $connection,
        private readonly array $genericEmailDomains,
    ) {
    }

    /**
     * @return array{case: string, enterprise: ?Enterprise, application: ?JobApplication, domain: string, generic: bool}
     */
    public function resolve(string $address, User $student): array
    {
        $address = mb_strtolower(trim($address));
        $domain = $this->domainOf($address);
        $generic = $this->isGeneric($domain);

        $enterprise = $this->enterpriseOfKnownAddress($address);

        if (null !== $enterprise) {
            return [
                'case' => self::CASE_LINKED,
                'enterprise' => $enterprise,
                'application' => $this->applicationRepository->findOneForStudentAndEnterprise($student, $enterprise),
                'domain' => $domain,
                'generic' => $generic,
            ];
        }

        if ('' !== $domain && !$generic) {
            $byDomain = $this->enterpriseRepository->findOneBy(['emailDomain' => $domain]);

            if (null !== $byDomain) {
                return [
                    'case' => self::CASE_CONFIRM,
                    'enterprise' => $byDomain,
                    'application' => $this->applicationRepository->findOneForStudentAndEnterprise($student, $byDomain),
                    'domain' => $domain,
                    'generic' => false,
                ];
            }
        }

        return ['case' => self::CASE_CREATE, 'enterprise' => null, 'application' => null, 'domain' => $domain, 'generic' => $generic];
    }

    /**
     * The application this mail will fall into. One application per company and per student: a
     * mail, its follow-up and the reply received all belong to the same one - that is the whole
     * point of grouping by company.
     */
    public function applicationFor(User $student, Enterprise $enterprise): JobApplication
    {
        $application = $this->applicationRepository->findOneForStudentAndEnterprise($student, $enterprise);

        if (null !== $application) {
            return $application;
        }

        return (new JobApplication())
            ->setStudent($student)
            ->setEnterprise($enterprise);
    }

    public function isGeneric(string $domain): bool
    {
        return \in_array(mb_strtolower($domain), $this->genericEmailDomains, true);
    }

    public function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return false === $at ? '' : mb_strtolower(substr($address, $at + 1));
    }

    /**
     * An "already seen" address is seen platform-wide, not per student: a classmate's contact is
     * what spares the next student the question.
     *
     * Raw SQL rather than DQL because recipients live in a JSON column: JSON_SEARCH has no DQL
     * equivalent, and a LIKE over the serialized value would catch partial matches
     * ("rh@neopixel.fr" inside "rh@neopixel.fr.example.com").
     */
    private function enterpriseOfKnownAddress(string $address): ?Enterprise
    {
        if ('' === $address) {
            return null;
        }

        $enterpriseId = $this->connection->fetchOne(
            'SELECT a.enterprise_id
             FROM email_message m
             JOIN job_application a ON a.id = m.job_application_id
             WHERE LOWER(m.from_address) = :address
                OR JSON_SEARCH(LOWER(m.to_addresses), \'one\', :address) IS NOT NULL
             ORDER BY m.id DESC
             LIMIT 1',
            ['address' => $address]
        );

        return false === $enterpriseId || null === $enterpriseId
            ? null
            : $this->enterpriseRepository->find((int) $enterpriseId);
    }
}
