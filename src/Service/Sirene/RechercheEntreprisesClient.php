<?php

declare(strict_types=1);

namespace App\Service\Sirene;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The État's « API Recherche d'entreprises » (recherche-entreprises.api.gouv.fr, DINUM): the SIRENE
 * and RNE registers, searchable by name, address, SIREN or SIRET. Open - no key, Licence Ouverte -
 * and limited to **7 requests per second per IP** - one IP for the whole platform. The client stays
 * under it through the `sirene_api` token bucket, shared by every worker of the container: the
 * per-instance pause it used before protected one process out of eight, which was enough for the
 * UFA's SIRET queue and not for a class searching « Trouver une entreprise » at once. A call that
 * would wait more than MAX_WAIT for its token is an outage like any other (R7 of the vivier spec).
 *
 * Only an employer's name and address are ever sent, never anything about an alternant.
 *
 * It **proposes, it never decides** (design/validated/siret-entreprises.md, R1): nothing here, nor
 * in anything that calls it, writes a SIRET. Called during a web request - a screen waiting on it -
 * hence the short timeout, unlike the IGN's client, which a scheduled command drives.
 *
 * Nothing it throws but SireneUnavailableException. A 429 is an outage like any other. An answer
 * whose shape is not the documented one is logged at error level - the API changed, somebody has
 * to hear about it - and refused rather than guessed at.
 *
 * @phpstan-type Establishment array{
 *     siret?: mixed, adresse?: mixed, code_postal?: mixed, etat_administratif?: mixed,
 *     est_siege?: mixed, date_creation?: mixed, date_fermeture?: mixed,
 *     liste_enseignes?: mixed, nom_commercial?: mixed, latitude?: mixed, longitude?: mixed,
 *     activite_principale?: mixed, tranche_effectif_salarie?: mixed, libelle_commune?: mixed
 * }
 * @phpstan-type Company array{
 *     siren?: mixed, nom_complet?: mixed, nom_raison_sociale?: mixed, sigle?: mixed,
 *     matching_etablissements?: mixed, siege?: mixed, activite_principale?: mixed,
 *     tranche_effectif_salarie?: mixed, categorie_entreprise?: mixed, nature_juridique?: mixed
 * }
 */
class RechercheEntreprisesClient
{
    private const int PER_PAGE = 10;

    /** Seconds a call may wait for its token before the register counts as unavailable. */
    private const float MAX_WAIT = 2.0;

    /** INSEE's legal-form code of an entrepreneur individuel (catégorie juridique 1000). */
    private const string INDIVIDUAL_LEGAL_FORM = '1000';

    public function __construct(
        private readonly HttpClientInterface $sireneHttpClient,
        private readonly LoggerInterface $logger,
        #[Target('sirene_api')]
        private readonly RateLimiterFactoryInterface $sireneApiLimiter,
    ) {
    }

    /**
     * One page of « Trouver une entreprise »: `/search` (filters only, no name needed) or
     * `/near_point` (around a commune). The parameters come whole from
     * App\Service\CompanySearch\CompanySearchCriteria; this method only adds the paging and the
     * shape of the answer, and keeps the **open** establishments - a company left with none in the
     * zone is dropped from the page and counted in RegistryPage::$dropped.
     *
     * @param '/search'|'/near_point' $endpoint
     * @param array<string, string>   $parameters
     */
    public function browse(string $endpoint, array $parameters, int $page): RegistryPage
    {
        $page = max(1, min($page, intdiv(RegistryPage::MAX_RESULTS, RegistryPage::PER_PAGE)));
        $data = $this->request($endpoint, [
            ...$parameters,
            'page' => $page,
            'per_page' => RegistryPage::PER_PAGE,
            'minimal' => 'true',
            'include' => 'matching_etablissements,siege',
        ]);

        $companies = [];
        $dropped = 0;
        foreach ($this->companiesOf($data) as $company) {
            $establishments = [];
            foreach ($this->establishmentsOf($company) as $establishment) {
                $candidate = $this->candidate($company, $establishment);
                if ($candidate->open) {
                    $establishments[] = $candidate;
                }
            }

            if ([] === $establishments) {
                ++$dropped;
                continue;
            }

            $companies[] = $this->registryCompany($company, $establishments);
        }

        return new RegistryPage(
            total: $this->intOf($data['total_results'] ?? null),
            page: $page,
            totalPages: $this->intOf($data['total_pages'] ?? null),
            companies: $companies,
            dropped: $dropped,
            readAt: new \DateTimeImmutable(),
        );
    }

    /**
     * The **open** establishments matching a free-text query (a name, a street line, a SIREN),
     * narrowed to a postcode or a département when one is given. A closed establishment is never a
     * candidate for an employer that has a contract today.
     *
     * @return list<EstablishmentCandidate>
     */
    public function search(string $query, ?string $postalCode = null, ?string $department = null): array
    {
        $parameters = [
            'q' => $query,
            'per_page' => self::PER_PAGE,
            'minimal' => 'true',
            'include' => 'matching_etablissements,siege',
        ];
        if (null !== $postalCode) {
            $parameters['code_postal'] = $postalCode;
        } elseif (null !== $department) {
            $parameters['departement'] = $department;
        }

        $candidates = [];
        foreach ($this->results($parameters) as $company) {
            foreach ($this->establishmentsOf($company) as $establishment) {
                $candidate = $this->candidate($company, $establishment);
                if ($candidate->open) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * One establishment by its SIRET, **closed ones included** - that is how a fiche learns its
     * confirmed establishment has shut (R9), and how a SIRET typed by hand is shown before it is
     * saved. Null when the register knows no such number.
     */
    public function establishment(string $siret): ?EstablishmentCandidate
    {
        $siret = Siret::normalize($siret);

        foreach ($this->results(['q' => $siret, 'per_page' => 1, 'minimal' => 'true', 'include' => 'matching_etablissements,siege']) as $company) {
            foreach ($this->establishmentsOf($company) as $establishment) {
                if (($establishment['siret'] ?? null) === $siret) {
                    return $this->candidate($company, $establishment);
                }
            }
        }

        return null;
    }

    /**
     * One establishment **with its company** - the fiche of « Trouver une entreprise ». Closed ones
     * included, like establishment(): a fiche kept by a student must still open, and say it shut.
     */
    public function company(string $siret): ?RegistryCompany
    {
        $siret = Siret::normalize($siret);

        foreach ($this->results(['q' => $siret, 'per_page' => 1, 'minimal' => 'true', 'include' => 'matching_etablissements,siege']) as $company) {
            foreach ($this->establishmentsOf($company) as $establishment) {
                if (($establishment['siret'] ?? null) === $siret) {
                    return $this->registryCompany($company, [$this->candidate($company, $establishment)]);
                }
            }
        }

        return null;
    }

    /**
     * @param Company                     $company
     * @param list<EstablishmentCandidate> $establishments
     */
    private function registryCompany(array $company, array $establishments): RegistryCompany
    {
        $siege = $company['siege'] ?? null;

        return new RegistryCompany(
            siren: $establishments[0]->siren,
            fullName: $establishments[0]->fullName,
            activityCode: $this->stringOrNull($company['activite_principale'] ?? null),
            employeeBracket: $this->stringOrNull($company['tranche_effectif_salarie'] ?? null),
            category: $this->stringOrNull($company['categorie_entreprise'] ?? null),
            headOffice: \is_array($siege) && \is_string($siege['siret'] ?? null) ? $this->candidate($company, $siege) : null,
            establishments: $establishments,
            individual: self::INDIVIDUAL_LEGAL_FORM === $this->stringOrNull($company['nature_juridique'] ?? null),
            legalForm: $this->stringOrNull($company['nature_juridique'] ?? null),
        );
    }

    /**
     * @param array<string, scalar> $parameters
     *
     * @return list<Company>
     */
    private function results(array $parameters): array
    {
        return $this->companiesOf($this->request('/search', $parameters));
    }

    /**
     * @param array<string, scalar> $parameters
     *
     * @return array<array-key, mixed>
     */
    private function request(string $endpoint, array $parameters): array
    {
        $this->pace();

        try {
            $response = $this->sireneHttpClient->request('GET', $endpoint, ['query' => $parameters]);
            $status = $response->getStatusCode();

            if (200 !== $status) {
                throw new SireneUnavailableException(\sprintf('Recherche d\'entreprises answered HTTP %d.', $status));
            }

            return $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new SireneUnavailableException(\sprintf('Recherche d\'entreprises unreachable: %s', $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<Company>
     */
    private function companiesOf(array $data): array
    {
        $results = $data['results'] ?? null;
        if (!\is_array($results)) {
            $this->refuse('search answer without results');
        }

        foreach ($results as $company) {
            if (!\is_array($company)) {
                $this->refuse('result that is not an object');
            }
        }

        /** @var list<Company> $companies */
        $companies = array_values($results);

        return $companies;
    }

    /**
     * @param Company $company
     *
     * @return list<Establishment>
     */
    private function establishmentsOf(array $company): array
    {
        $establishments = $company['matching_etablissements'] ?? null;
        if (!\is_array($establishments)) {
            $this->refuse('company without matching_etablissements');
        }

        /** @var list<Establishment> $list */
        $list = array_values(array_filter($establishments, \is_array(...)));

        return $list;
    }

    /**
     * @param Company       $company
     * @param Establishment $establishment
     */
    private function candidate(array $company, array $establishment): EstablishmentCandidate
    {
        $siret = $establishment['siret'] ?? null;
        $siren = $company['siren'] ?? null;
        $fullName = $company['nom_complet'] ?? null;

        if (!\is_string($siret) || !\is_string($siren) || !\is_string($fullName)) {
            $this->refuse('establishment without siret, siren or name');
        }

        $signs = [];
        $rawSigns = $establishment['liste_enseignes'] ?? null;
        foreach (\is_array($rawSigns) ? $rawSigns : [] as $sign) {
            if (\is_string($sign) && '' !== trim($sign)) {
                $signs[] = trim($sign);
            }
        }

        return new EstablishmentCandidate(
            siret: $siret,
            siren: $siren,
            fullName: $fullName,
            legalName: $this->stringOrNull($company['nom_raison_sociale'] ?? null),
            acronym: $this->stringOrNull($company['sigle'] ?? null),
            signs: $signs,
            tradeName: $this->stringOrNull($establishment['nom_commercial'] ?? null),
            address: $this->stringOrNull($establishment['adresse'] ?? null) ?? '',
            postalCode: $this->stringOrNull($establishment['code_postal'] ?? null),
            open: 'A' === ($establishment['etat_administratif'] ?? null),
            headOffice: true === ($establishment['est_siege'] ?? null),
            createdOn: $this->dateOrNull($establishment['date_creation'] ?? null),
            closedOn: $this->dateOrNull($establishment['date_fermeture'] ?? null),
            latitude: $this->floatOrNull($establishment['latitude'] ?? null),
            longitude: $this->floatOrNull($establishment['longitude'] ?? null),
            activityCode: $this->stringOrNull($establishment['activite_principale'] ?? null),
            employeeBracket: $this->stringOrNull($establishment['tranche_effectif_salarie'] ?? null),
            city: $this->stringOrNull($establishment['libelle_commune'] ?? null),
        );
    }

    /** The API writes coordinates as strings (« 45.859338617 »). */
    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    /**
     * Waits for a token of the platform-wide bucket. Past MAX_WAIT the register is « unavailable »
     * for this call: a page that answers « réessayez » beats a page that hangs, and beats the IP
     * being throttled for everybody.
     */
    private function pace(): void
    {
        try {
            $this->sireneApiLimiter->create('recherche-entreprises')->reserve(1, self::MAX_WAIT)->wait();
        } catch (MaxWaitDurationExceededException $exception) {
            throw new SireneUnavailableException('Recherche d\'entreprises: platform quota reached.', 0, $exception);
        }
    }

    private function refuse(string $what): never
    {
        $this->logger->error('Recherche d\'entreprises: unrecognised answer ({what}).', ['what' => $what]);

        throw new SireneUnavailableException(\sprintf('Recherche d\'entreprises: unrecognised answer (%s).', $what));
    }
}
