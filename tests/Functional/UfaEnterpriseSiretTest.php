<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\User;
use App\Enum\EnterpriseSiretStatus;
use App\Repository\EnterpriseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The SIRET of an employer, proposed by the État and confirmed by a person
 * (design/validated/siret-entreprises.md): only a button writes one, the queue walks the
 * employers « à confirmer » in id order and nothing else, and an État that does not answer blocks
 * nothing (the test environment's client answers nothing, which is R10's case exactly).
 */
class UfaEnterpriseSiretTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private EnterpriseRepository $enterprises;
    private User $staff;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->enterprises = static::getContainer()->get(EnterpriseRepository::class);
        $this->staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'secretariat');
        $this->now = new \DateTimeImmutable();
    }

    public function testTheQueueHoldsWhatIsAwaitingAPersonAndNothingElse(): void
    {
        $pending = $this->enterprise('EN ATTENTE', '48931910300037');
        $missing = $this->enterprise('SANS NUMERO');
        $this->enterprise('CONFIRMEE')->confirmSiret('48931910300029', $this->staff, $this->now);
        $this->enterprise('MISE DE COTE')->markSiretNotFound($this->staff, $this->now->modify('-10 days'));
        $expired = $this->enterprise('MISE DE COTE IL Y A LONGTEMPS');
        $expired->markSiretNotFound($this->staff, $this->now->modify(\sprintf('-%d days', Enterprise::SIRET_SET_ASIDE_DAYS + 1)));
        $this->enterprise('INACTIVE')->setInactiveDate($this->now);
        $this->entityManager->flush();

        $queue = $this->enterprises->queryPendingSiret($this->now, $this->staff)->orderBy('e.id')->getQuery()->getResult();

        self::assertSame([$pending, $missing, $expired], $queue);
        foreach ($this->enterprises->findAll() as $enterprise) {
            // The SQL and the entity must read « à confirmer » the same way.
            self::assertSame(
                \in_array($enterprise, $queue, true),
                null === $enterprise->getInactiveDate() && $enterprise->siretStatus($this->now)->isAwaitingReview(),
                $enterprise->getName(),
            );
        }
    }

    public function testATestAccountQueuesOnlyTestEmployers(): void
    {
        $this->enterprise('REELLE');
        $fake = $this->enterprise('DE TEST');
        $fake->setTestEnterprise(true);
        $this->staff->setTestUser(true);
        $this->entityManager->flush();

        self::assertSame(1, $this->enterprises->countPendingSiret($this->now, $this->staff));
        self::assertSame($fake, $this->enterprises->findNextPendingSiret(0, $this->now, $this->staff));
    }

    public function testTheQueueWalksInIdOrderAndEndsOnTheList(): void
    {
        $first = $this->enterprise('PREMIERE');
        $second = $this->enterprise('SECONDE');
        $this->entityManager->flush();
        $this->client->loginUser($this->staff);

        $this->client->request('GET', '/ufa/enterprises/siret-review');
        self::assertResponseRedirects(\sprintf('/ufa/enterprises/%d/siret?queue=1', $first->getId()));

        $this->client->request('GET', '/ufa/enterprises/siret-review?after='.$first->getId());
        self::assertResponseRedirects(\sprintf('/ufa/enterprises/%d/siret?queue=1', $second->getId()));

        $this->client->request('GET', '/ufa/enterprises/siret-review?after='.$second->getId());
        self::assertResponseRedirects('/ufa/enterprises');
    }

    public function testTheFicheSiretRendersWithoutTheEtat(): void
    {
        $enterprise = $this->enterprise('AUDEFI EXPERTISE COMPTABLE', '48931910300029', "4, rue Legouvé\n87000-LIMOGES");
        $this->entityManager->flush();
        $this->client->loginUser($this->staff);

        $this->client->request('GET', \sprintf('/ufa/enterprises/%d/siret?queue=1', $enterprise->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '489 319 103 00029');
        self::assertSelectorTextContains('body', 'SIRET à confirmer : 1 / 1');

        // R10: no answer is said as such, and « Chercher autrement » / « Pas de SIRET trouvable » stay.
        $this->client->request('GET', \sprintf('/ufa/enterprises/%d/siret/candidates', $enterprise->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#siret-candidates', "Service de l'État indisponible");

        $this->client->request('GET', \sprintf('/ufa/enterprises/%d/siret/establishment', $enterprise->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('turbo-frame#siret-recorded form', 'Nothing to confirm on a number nobody could look at.');
    }

    public function testAssociatingConfirmsInTheNameOfThePerson(): void
    {
        $enterprise = $this->enterprise('AUDEFI EXPERTISE-COMPTABLE');
        $this->entityManager->flush();
        $id = $enterprise->getId();
        $this->client->loginUser($this->staff);
        $this->client->request('GET', \sprintf('/ufa/enterprises/%d/siret', $id));

        $this->client->request('POST', \sprintf('/ufa/enterprises/%d/siret/associate', $id), [
            '_token' => $this->csrfToken('enterprise_siret_'.$id),
            'siret' => '489 319 103 00037',
        ]);
        self::assertResponseRedirects(\sprintf('/ufa/enterprises/%d', $id));

        $this->entityManager->clear();
        $saved = $this->enterprises->find($id);
        self::assertNotNull($saved);
        self::assertSame('48931910300037', $saved->getSiret());
        self::assertSame(EnterpriseSiretStatus::Confirmed, $saved->siretStatus($this->now));
        self::assertSame('secretariat', $saved->getSiretConfirmedBy()?->getUsername());
    }

    public function testInQueueModeAGestureMovesOnToTheNextEmployer(): void
    {
        $enterprise = $this->enterprise('ENSEMBLE / SAS');
        $this->entityManager->flush();
        $id = $enterprise->getId();
        $this->client->loginUser($this->staff);
        $this->client->request('GET', \sprintf('/ufa/enterprises/%d/siret?queue=1', $id));

        $this->client->request('POST', \sprintf('/ufa/enterprises/%d/siret/not-found', $id), [
            '_token' => $this->csrfToken('enterprise_siret_'.$id),
            'queue' => '1',
        ]);
        self::assertResponseRedirects('/ufa/enterprises/siret-review?after='.$id);

        $this->entityManager->clear();
        self::assertSame(EnterpriseSiretStatus::SetAside, $this->enterprises->find($id)?->siretStatus($this->now));
    }

    public function testAnInvalidNumberIsRefusedNotCorrected(): void
    {
        $enterprise = $this->enterprise('AUDEFI');
        $this->entityManager->flush();
        $id = $enterprise->getId();
        $this->client->loginUser($this->staff);
        $this->client->request('GET', \sprintf('/ufa/enterprises/%d/siret', $id));

        $this->client->request('POST', \sprintf('/ufa/enterprises/%d/siret/associate', $id), [
            '_token' => $this->csrfToken('enterprise_siret_'.$id),
            'siret' => '48931910300038',
        ]);

        $this->entityManager->clear();
        self::assertNull($this->enterprises->find($id)?->getSiret());
    }

    public function testNothingIsWrittenWithoutTheToken(): void
    {
        $enterprise = $this->enterprise('AUDEFI');
        $this->entityManager->flush();
        $this->client->loginUser($this->staff);

        $this->client->request('POST', \sprintf('/ufa/enterprises/%d/siret/associate', $enterprise->getId()), ['siret' => '48931910300037']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testATeacherIsRefusedAndAnExtinguishedFeatureDoesNotExist(): void
    {
        $enterprise = $this->enterprise('AUDEFI');
        $this->entityManager->flush();
        $path = \sprintf('/ufa/enterprises/%d/siret', $enterprise->getId());

        $this->assertScreens($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'enseignant'), [$path => 403]);
        $this->assertScreens($this->staff, [$path => 200]);

        $this->entityManager
            ->createQuery('UPDATE App\Entity\FeatureRoleSetting s SET s.enabled = false WHERE s.feature = :feature')
            ->setParameter('feature', 'ufa_booklet')
            ->execute();
        $this->assertScreens($this->staff, [$path => 404, '/ufa/enterprises/siret-review' => 404]);
    }

    private function enterprise(string $name, ?string $siret = null, ?string $address = null): Enterprise
    {
        $enterprise = new Enterprise($name, $address);
        $enterprise->setSiret($siret);
        $enterprise->setCreatedBy($this->staff);
        $this->entityManager->persist($enterprise);

        return $enterprise;
    }
}
