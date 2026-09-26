<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Counter\CounterRecomputer;
use App\Entity\SignupList;
use App\Entity\SignupListRegistration;
use App\Enum\MessageAudienceType;
use App\Service\SignupListRegistrar;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The stored number of registrations of a sign-up list follows the registrations themselves: in,
 * out, and not twice for somebody already registered. The recomputation then has nothing to
 * correct - which is what proves the live side and the source agree.
 */
class SignupListRegistrationTest extends FunctionalTestCase
{
    public function testTheStoredCountFollowsRegistrations(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $registrar = static::getContainer()->get(SignupListRegistrar::class);

        $author = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'signup.author');
        $alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'signup.alice');
        $bob = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'signup.bob');

        $list = (new SignupList())->setTitle('Sortie')->setDescription('<p>Sortie</p>');
        $list->setAudienceTypes([MessageAudienceType::Manual]);
        $list->addManualRecipient($alice)->addManualRecipient($bob);
        $list->setCreatedBy($author);
        $entityManager->persist($list);
        $entityManager->flush();

        self::assertTrue($registrar->register($list, $alice));
        self::assertTrue($registrar->register($list, $bob));
        self::assertFalse($registrar->register($list, $alice), 'already registered: nothing added');
        self::assertSame(2, $list->getRegistrationCount());

        $registration = $entityManager->getRepository(SignupListRegistration::class)->findOneBy(['signupList' => $list, 'user' => $bob]);
        self::assertNotNull($registration);
        $registrar->unregister($registration);
        self::assertSame(1, $list->getRegistrationCount());

        $entityManager->refresh($list);
        self::assertSame(1, $list->getRegistrationCount(), 'the database holds the same figure');

        $run = static::getContainer()->get(CounterRecomputer::class)->recompute(SignupListRegistrar::NAME, (int) $list->getId());
        self::assertSame([], $run->drifts);
    }

    /** The web screen reads the stored figure. */
    public function testTheScreenShowsTheStoredCount(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'signup.admin');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'signup.carol');

        $list = (new SignupList())->setTitle('Atelier')->setDescription('<p>Atelier</p>');
        $list->setAudienceTypes([MessageAudienceType::Manual]);
        $list->addManualRecipient($student);
        $list->setCreatedBy($author);
        $entityManager->persist($list);
        $entityManager->flush();

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/signup-lists/'.$list->getId());
        $this->client->submit($crawler->filter('form[action$="/register"]')->form());
        self::assertResponseRedirects('/signup-lists/'.$list->getId());

        // The request cleared the manager: read the row again rather than refresh a detached list.
        self::assertEquals(1, $entityManager->getConnection()->fetchOne('SELECT registration_count FROM signup_list WHERE id = ?', [$list->getId()]));
    }
}
