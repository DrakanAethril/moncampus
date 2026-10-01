<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use App\Entity\User;
use App\Service\Sirene\RechercheEntreprisesClient;
use App\Service\Sirene\RegistryCompany;
use App\Service\Sirene\RegistryPage;
use App\Service\Sirene\SireneUnavailableException;
use App\Service\Sirene\Siret;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * « Trouver une entreprise » between the screen and the register: the cache in front of the API,
 * and the per-person ceiling.
 *
 * A page is kept a day (`cache.sirene`): SIRENE is published daily, so thirty students running the
 * class's search cost one call. A fiche is kept as long, under its SIRET. Only a miss consumes the
 * person's `company_search` allowance - reading a page somebody else already paid for is free.
 *
 * Everything it throws is SireneUnavailableException or CompanySearchThrottledException: the screen
 * says « le registre ne répond pas » or « trop de recherches », never a 500.
 */
class CompanySearchService
{
    private const int TTL = 86400;

    public function __construct(
        private readonly RechercheEntreprisesClient $client,
        private readonly CacheInterface $cacheSirene,
        #[Target('company_search')]
        private readonly RateLimiterFactoryInterface $companySearchLimiter,
    ) {
    }

    /**
     * @throws SireneUnavailableException
     * @throws CompanySearchThrottledException
     */
    public function search(CompanySearchCriteria $criteria, User $user): RegistryPage
    {
        ['endpoint' => $endpoint, 'parameters' => $parameters] = $criteria->toApiRequest();
        ksort($parameters);
        $key = 'browse_'.sha1($endpoint.'|'.json_encode($parameters).'|'.$criteria->page);

        return $this->cacheSirene->get($key, function (ItemInterface $item) use ($endpoint, $parameters, $criteria, $user): RegistryPage {
            $item->expiresAfter(self::TTL);

            if (!$this->companySearchLimiter->create('user:'.$user->getId())->consume()->isAccepted()) {
                throw new CompanySearchThrottledException();
            }

            $page = $this->client->browse($endpoint, $parameters, $criteria->page);
            $kept = array_values(array_filter($page->companies, $criteria->keeps(...)));

            return \count($kept) === \count($page->companies)
                ? $page
                : new RegistryPage($page->total, $page->page, $page->totalPages, $kept, $page->dropped + \count($page->companies) - \count($kept), $page->readAt);
        });
    }

    /**
     * One establishment, closed ones included - a fiche must still open on a company that shut since
     * a student kept it.
     *
     * @throws SireneUnavailableException
     */
    public function company(string $siret): ?RegistryCompany
    {
        $siret = Siret::normalize($siret);

        return $this->cacheSirene->get('company_'.$siret, function (ItemInterface $item) use ($siret): ?RegistryCompany {
            $item->expiresAfter(self::TTL);

            return $this->client->company($siret);
        });
    }
}
