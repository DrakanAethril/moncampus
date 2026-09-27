<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

// Self-service AD password change (App\Controller\ProfileController::changePassword()) - not
// mapped to any entity. newPassword is only ever used to build a new App\Entity\LdapManagePassword
// queue row, never an App\Entity\User property, so there's no data_class here. No current-password
// field/re-verification - a logged-in session is trusted on its own to change its own password.
class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'newPasswordMismatchMessage',
                'first_options' => ['label' => 'newPasswordFieldLabel'],
                'second_options' => ['label' => 'newPasswordConfirmationFieldLabel'],
                'constraints' => [
                    // « must not contain the username » is checked in the controller, since it needs
                    // the current User to check against.
                    new StrongPassword(),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'changePasswordSubmitAction',
            ])
        ;
    }
}
