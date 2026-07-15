<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

// Self-service AD password change (App\Controller\ProfileController::changePassword()) - not
// mapped to any entity. currentPassword is only ever re-verified against LDAP
// (App\Security\LdapCredentialsVerifier::verifyPassword()); newPassword only ever used to build
// a new App\Entity\LdapManagePassword queue row. Neither is ever an App\Entity\User property, so
// there's no data_class here.
class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'currentPasswordFieldLabel',
                'constraints' => [new NotBlank()],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'newPasswordMismatchMessage',
                'first_options' => ['label' => 'newPasswordFieldLabel'],
                'second_options' => ['label' => 'newPasswordConfirmationFieldLabel'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 12, minMessage: 'newPasswordTooShortMessage'),
                    // Mirrors the créa's own stated rule: at least one upper/lower/digit/special
                    // character - "must not contain the username" is checked in the controller
                    // instead, since it needs the current User to check against.
                    new Regex(
                        pattern: '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/',
                        message: 'newPasswordComplexityMessage',
                    ),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'changePasswordSubmitAction',
            ])
        ;
    }
}
