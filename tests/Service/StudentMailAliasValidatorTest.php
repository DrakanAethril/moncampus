<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\EmailAlias;
use App\Entity\User;
use App\Enum\EmailAliasOrigin;
use App\Repository\EmailAliasRepository;
use App\Service\StudentMailAliasValidator;
use PHPUnit\Framework\TestCase;

/**
 * What gets refused in the address list typed from Annuaire > Utilisateurs.
 *
 * Reception being catch-all, a local part taken is taken for the whole school: two students cannot
 * share `camille.roux`, and an outsider's mail must never be able to land in the wrong mailbox.
 * That is the rule these cases pin down - the rest (the address's shape) is only a restatement of
 * App\Util\SchoolMailLocalPart.
 */
class StudentMailAliasValidatorTest extends TestCase
{
    public function testAnAddressHeldByAnotherStudentIsRefusedAndNamesThem(): void
    {
        $owner = $this->user('croux', 'Camille', 'Roux');
        $validator = $this->validatorWithExisting([$this->alias('camille.roux', $owner)]);

        $violations = $validator->validate($this->user('cdupont'), ['0' => $this->alias('camille.roux')]);

        self::assertSame('emailAliasLocalPartTakenMessage', $violations['0']['message']);
        self::assertSame(['%student%' => 'Camille Roux'], $violations['0']['parameters']);
    }

    public function testAStudentOwnAddressIsNotACollisionWithItself(): void
    {
        $student = $this->user('croux', 'Camille', 'Roux');
        $existing = $this->alias('camille.roux', $student);
        $validator = $this->validatorWithExisting([$existing]);

        self::assertSame([], $validator->validate($student, ['0' => $existing]));
    }

    public function testTheSameAddressTwiceInOneSubmissionIsRefusedOnTheSecondLine(): void
    {
        $student = $this->user('croux');
        $validator = $this->validatorWithExisting([]);

        $violations = $validator->validate($student, [
            '0' => $this->alias('stages.sio2', $student),
            '1' => $this->alias('stages.sio2', $student),
        ]);

        self::assertArrayNotHasKey('0', $violations);
        self::assertSame('emailAliasDuplicateLocalPartMessage', $violations['1']['message']);
    }

    public function testATypedAddressCollidingWithTheStudentOwnGeneratedAddressIsRefused(): void
    {
        $student = $this->user('croux', 'Camille', 'Roux');
        $generated = $this->alias('camille.roux', $student, EmailAliasOrigin::Generated);
        $validator = $this->validatorWithExisting([$generated]);

        // The locked row occupies the place without ever being judged itself: the typed row is the
        // one refused, even though both belong to the same student - failing which the screen would
        // let a duplicate through for the unique index to reject at flush time, with nothing to
        // show against the offending field.
        $violations = $validator->validate($student, ['0' => $generated, '1' => $this->alias('camille.roux', $student)]);

        self::assertArrayNotHasKey('0', $violations);
        self::assertSame('emailAliasDuplicateLocalPartMessage', $violations['1']['message']);
    }

    public function testAddressesFollowingTheirSourceAreNeverJudged(): void
    {
        $student = $this->user('croux');

        // `croux` has no dot and would be refused had it been typed: taken from the login, it is
        // not. An address that already reached a company cannot become the reason a screen that
        // does not even touch it refuses to save.
        $violations = $this->validatorWithExisting([])->validate($student, [
            '0' => $this->alias('croux', $student, EmailAliasOrigin::Login),
            '1' => $this->alias('roux', $student, EmailAliasOrigin::Generated),
        ]);

        self::assertSame([], $violations);
    }

    public function testATypedAddressMustCarryADotAndStayWellFormed(): void
    {
        $student = $this->user('croux');
        $validator = $this->validatorWithExisting([]);

        self::assertSame('emailAliasMissingDot', $validator->validate($student, ['0' => $this->alias('comptabilite', $student)])['0']['message']);
        self::assertSame('emailAliasMalformedLocalPart', $validator->validate($student, ['0' => $this->alias('camille..roux', $student)])['0']['message']);
        self::assertSame('emailAliasReservedLocalPart', $validator->validate($student, ['0' => $this->alias('postmaster', $student)])['0']['message']);
        self::assertSame('emailAliasBlankLocalPartMessage', $validator->validate($student, ['0' => $this->alias('', $student)])['0']['message']);
    }

    /** @param list<EmailAlias> $existing */
    private function validatorWithExisting(array $existing): StudentMailAliasValidator
    {
        $repository = $this->createStub(EmailAliasRepository::class);
        $repository->method('findByLocalParts')
            ->willReturnCallback(static fn (array $localParts): array => array_values(array_filter(
                $existing,
                static fn (EmailAlias $alias): bool => \in_array($alias->getLocalPart(), $localParts, true),
            )));

        return new StudentMailAliasValidator($repository);
    }

    private function alias(string $localPart, ?User $user = null, EmailAliasOrigin $origin = EmailAliasOrigin::Manual): EmailAlias
    {
        return (new EmailAlias())
            ->setLocalPart($localPart)
            ->setUser($user ?? new User('test'))
            ->setOrigin($origin);
    }

    private function user(string $username, ?string $firstname = null, ?string $lastname = null): User
    {
        return (new User($username))->setFirstname($firstname)->setLastname($lastname);
    }
}
