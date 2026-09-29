<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\Siret;
use App\Validator\SiretValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<SiretValidator>
 */
class SiretValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): SiretValidator
    {
        return new SiretValidator();
    }

    public function testBlankPasses(): void
    {
        $this->validator->validate(null, new Siret());
        $this->validator->validate('', new Siret());
        $this->validator->validate('   ', new Siret());

        $this->assertNoViolation();
    }

    public function testAValidNumberPassesWhateverItsSpacing(): void
    {
        $this->validator->validate('489 319 103 00037', new Siret());

        $this->assertNoViolation();
    }

    public function testTheWrongLengthIsSaidAsSuch(): void
    {
        $this->validator->validate('489 319 103 0003', new Siret());

        $this->buildViolation('siretLengthMessage')->assertRaised();
    }

    public function testAWrongDigitIsRefusedNotCorrected(): void
    {
        $this->validator->validate('48931910300038', new Siret());

        $this->buildViolation('siretChecksumMessage')->assertRaised();
    }
}
