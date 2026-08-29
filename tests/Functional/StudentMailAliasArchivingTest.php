<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EmailAlias;
use App\Entity\User;
use App\Enum\EmailAliasOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Annuaire > fiche d'un élève > « Adresses Courrier école », on the refusal path.
 *
 * That path had never worked: the violations came back keyed by integers - PHP renormalises the
 * numeric-string row names Symfony hands out - and `Form::get()` takes nothing but a string, so
 * every refused address answered **500** instead of showing its message. The screen appeared to
 * accept nothing and explain nothing, and had done so since the feature shipped. It is exactly the
 * kind of branch a smoke test on GET cannot reach, which is why it is pinned here.
 *
 * The archiving is the other half. A Courrier école local part is never handed to a second student,
 * so removing a row does not delete it - and the corollary, which the unique index would otherwise
 * turn into a 500 of its own: typing back an address this same student had retired must revive that
 * row rather than add a second one for the same local part.
 */
class StudentMailAliasArchivingTest extends FunctionalTestCase
{
    private User $admin;
    private User $student;
    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'alias.admin');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'croux');
        $this->student->setFirstname('Camille')->setLastname('Roux');
        $this->entityManager()->flush();
        $this->studentId = $this->student->getId() ?? 0;

        $this->client->loginUser($this->admin);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadStudent(): User
    {
        $this->entityManager()->clear();
        $user = $this->entityManager()->getRepository(User::class)->find($this->studentId);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @return array<string, bool> local part => still active */
    private function aliases(): array
    {
        $rows = [];
        foreach ($this->reloadStudent()->getEmailAliases() as $alias) {
            $rows[$alias->getLocalPart()] = $alias->isActive();
        }

        return $rows;
    }

    private function giveAlias(string $localPart): void
    {
        $alias = (new EmailAlias())->setLocalPart($localPart)->setOrigin(EmailAliasOrigin::Manual);
        $this->student->addEmailAlias($alias);
        $this->entityManager()->persist($alias);
        $this->entityManager()->flush();
    }

    /**
     * Submits the fiche with exactly the local parts given - the collection's own rows, keyed "0",
     * "1", … A local part missing from the list is a row the administrator took out.
     *
     * @param list<string> $localParts
     */
    private function submitAliases(array $localParts): Crawler
    {
        $crawler = $this->client->request('GET', sprintf('/directory/users/%d/edit', $this->studentId));
        $form = $crawler->filter('form')->reduce(
            static fn (Crawler $node): bool => null === $node->attr('action'),
        )->form();

        $values = $form->getPhpValues();
        /** @var array<string, mixed> $profile */
        $profile = $values['user_profile'] ?? [];
        $profile['emailAliases'] = [];
        foreach ($localParts as $index => $localPart) {
            $profile['emailAliases'][(string) $index] = ['localPart' => $localPart];
        }
        $profile['primaryAliasKey'] = '0';
        $values['user_profile'] = $profile;

        return $this->client->request('POST', $form->getUri(), $values);
    }

    /**
     * The regression the whole class is named after: a duplicate is the one case the validator
     * exists for, and it answered 500.
     */
    public function testAnAddressAlreadyHeldBySomebodyElseIsRefusedWithItsMessage(): void
    {
        $other = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'adurand');
        $other->setFirstname('Alex')->setLastname('Durand');
        $taken = (new EmailAlias())->setLocalPart('alex.durand')->setOrigin(EmailAliasOrigin::Manual);
        $other->addEmailAlias($taken);
        $this->entityManager()->persist($taken);
        $this->entityManager()->flush();

        $crawler = $this->submitAliases(['alex.durand']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'Refused, not crashed.');
        self::assertStringContainsString('Alex Durand', $crawler->filter('body')->text(), 'And it says who holds it.');
        self::assertSame([], $this->aliases(), 'Nothing was written.');
    }

    public function testRemovingAnAddressArchivesItInsteadOfDeletingIt(): void
    {
        $this->giveAlias('camille.roux');

        $this->submitAliases([]);

        self::assertSame(['camille.roux' => false], $this->aliases());
    }

    /** An archived address is still an occupant: nobody else may be given it. */
    public function testAnArchivedAddressIsStillRefusedToAnotherStudent(): void
    {
        $this->giveAlias('camille.roux');
        $this->submitAliases([]);

        $newcomer = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'cderoux');
        $this->studentId = $newcomer->getId() ?? 0;
        $this->student = $newcomer;

        $this->submitAliases(['camille.roux']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->aliases(), 'The newcomer gets nothing.');
    }

    /**
     * The corollary of archiving, and a 500 if it is missed: the row is the account's own, so no
     * between-students check can see the collision - only the unique index, at flush time.
     */
    public function testTypingBackAnArchivedAddressRevivesItRatherThanDuplicatingIt(): void
    {
        $this->giveAlias('camille.roux');
        $this->submitAliases([]);
        self::assertSame(['camille.roux' => false], $this->aliases());

        $this->submitAliases(['camille.roux']);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'Accepted.');
        self::assertSame(['camille.roux' => true], $this->aliases(), 'One row, revived.');
    }
}
