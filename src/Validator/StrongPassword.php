<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints\Compound;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The platform's one rule for a password somebody chooses: twelve characters, at least one upper
 * case, one lower case, one digit and one special character - the créa's own stated rule.
 *
 * One constraint so the two places that ask for a password cannot drift: the change of the
 * account password (App\Form\ChangePasswordType) and the passwords of external services
 * (App\Security\ExternalServicePasswords). « Must not contain the username » needs the user and is
 * checked by each caller.
 */
#[\Attribute]
final class StrongPassword extends Compound
{
    /**
     * @param array<string, mixed> $options
     *
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    protected function getConstraints(array $options): array
    {
        return [
            new NotBlank(),
            new Length(min: 12, minMessage: 'newPasswordTooShortMessage'),
            new Regex(
                pattern: '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/',
                message: 'newPasswordComplexityMessage',
            ),
        ];
    }
}
