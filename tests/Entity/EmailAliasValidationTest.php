<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EmailAlias;
use App\Entity\User;
use App\Enum\EmailAliasOrigin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The dot rule, checked where it is actually applied.
 *
 * App\Tests\Util\SchoolMailLocalPartTest covers the rule itself; here we make sure it is properly
 * wired to the entity and that it depends on the origin - it is that dependency which makes « every
 * address entered must contain a dot » and « the login alias never has one » live together.
 */
class EmailAliasValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testAHandCreatedAddressWithoutADotIsRefused(): void
    {
        $violations = $this->validate('comptabilite', EmailAliasOrigin::Manual);

        self::assertCount(1, $violations);
        self::assertSame('emailAliasMissingDot', $violations[0]->getMessage());
        self::assertSame('localPart', $violations[0]->getPropertyPath());
    }

    public function testAHandCreatedAddressWithADotPasses(): void
    {
        self::assertCount(0, $this->validate('stages.sio2', EmailAliasOrigin::Manual));
    }

    public function testTheLoginAliasIsExemptFromTheDotRule(): void
    {
        // The only exception, and it holds because this alias is not typed in: it takes the
        // directory's username and is administrable from no screen.
        self::assertCount(0, $this->validate('croux', EmailAliasOrigin::Login));
    }

    public function testReservedAddressesAreRefusedWhateverTheOrigin(): void
    {
        foreach (EmailAliasOrigin::cases() as $origin) {
            $violations = $this->validate('dmarc', $origin);

            self::assertCount(1, $violations, $origin->value);
            self::assertSame('emailAliasReservedLocalPart', $violations[0]->getMessage());
        }
    }

    public function testOnlyHandCreatedAliasesAreManageable(): void
    {
        self::assertTrue(EmailAliasOrigin::Manual->isManageable());
        self::assertFalse(EmailAliasOrigin::Login->isManageable());
        self::assertFalse(EmailAliasOrigin::Generated->isManageable());
    }

    private function validate(string $localPart, EmailAliasOrigin $origin): array
    {
        $alias = (new EmailAlias())
            ->setUser(new User('test'))
            ->setLocalPart($localPart)
            ->setOrigin($origin);

        return iterator_to_array($this->validator->validate($alias));
    }
}
