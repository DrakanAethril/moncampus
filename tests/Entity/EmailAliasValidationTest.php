<?php

namespace App\Tests\Entity;

use App\Entity\EmailAlias;
use App\Entity\User;
use App\Enum\EmailAliasOrigin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * La règle du point, vérifiée là où elle est réellement appliquée.
 *
 * App\Tests\Util\SchoolMailLocalPartTest couvre la règle elle-même ; ici on s'assure qu'elle est
 * bien câblée à l'entité et qu'elle dépend de l'origine - c'est cette dépendance qui fait vivre
 * ensemble « toute adresse saisie doit contenir un point » et « l'alias de login n'en a jamais ».
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
        // La seule exception, et elle tient à ce que cet alias n'est pas saisi : il reprend
        // l'identifiant de l'annuaire et n'est administrable depuis aucun écran.
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
