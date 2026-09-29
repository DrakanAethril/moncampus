<?php

declare(strict_types=1);

namespace App\Service\Sirene;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The État's « API Recherche d'entreprises » (recherche-entreprises.api.gouv.fr, DINUM): the SIRENE
 * and RNE registers, searchable by name, address, SIREN or SIRET. Open - no key, Licence Ouverte -
 * and limited to **7 requests per second per IP**, which the client stays under by spacing its own
 * calls. The pause is kept on the instance, which in worker mode outlives the request: harmless, a
 * timestamp that is too old only means no pause.
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
 *     liste_enseignes?: mixed, nom_commercial?: mixed
 * }
 * @phpstan-type Company array{
 *     siren?: mixed, nom_complet?: mixed, nom_raison_sociale?: mixed, sigle?: mixed,
 *     matching_etablissements?: mixed
 * }
 */
class RechercheEntreprisesClient
{
    /** The published limit is 7/s; a little over 1/7 s between two calls keeps clear of it. */
    private const float MIN_INTERVAL = 0.15;

    private const int PER_PAGE = 10;

    private float $lastCallAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $sireneHttpClient,
        private readonly LoggerInterface $logger,
    ) {
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
     * @param array<string, scalar> $parameters
     *
     * @return list<Company>
     */
    private function results(array $parameters): array
    {
        $this->pace();

        try {
            $response = $this->sireneHttpClient->request('GET', '/search', ['query' => $parameters]);
            $status = $response->getStatusCode();

            if (200 !== $status) {
                throw new SireneUnavailableException(\sprintf('Recherche d\'entreprises answered HTTP %d.', $status));
            }

            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new SireneUnavailableException(\sprintf('Recherche d\'entreprises unreachable: %s', $exception->getMessage()), 0, $exception);
        }

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
        );
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

    private function pace(): void
    {
        $elapsed = microtime(true) - $this->lastCallAt;

        if ($elapsed < self::MIN_INTERVAL) {
            usleep((int) ((self::MIN_INTERVAL - $elapsed) * 1_000_000));
        }

        $this->lastCallAt = microtime(true);
    }

    private function refuse(string $what): never
    {
        $this->logger->error('Recherche d\'entreprises: unrecognised answer ({what}).', ['what' => $what]);

        throw new SireneUnavailableException(\sprintf('Recherche d\'entreprises: unrecognised answer (%s).', $what));
    }
}
