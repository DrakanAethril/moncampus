<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\Sirene\Siret as SiretNumber;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class SiretValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Siret) {
            throw new UnexpectedTypeException($constraint, Siret::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $siret = SiretNumber::normalize($value);

        if ('' === $siret) {
            return;
        }

        if (1 !== preg_match('/^\d{14}$/', $siret)) {
            $this->context->buildViolation($constraint->lengthMessage)->addViolation();

            return;
        }

        if (!SiretNumber::isValid($siret)) {
            $this->context->buildViolation($constraint->checksumMessage)->addViolation();
        }
    }
}
