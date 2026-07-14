<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Self-service counterpart to ContactEmailType, same reasoning: a User-backed form kept separate
// from the staff-facing Gestion > Utilisateurs form. $signature is sanitized server-side by
// ProfileController before being persisted (see the "app.message_signature" sanitizer), not here -
// this form only carries the raw submitted value.
class MessagingPreferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emailCopyOfMessagesEnabled', CheckboxType::class, [
                'label' => 'messagingPreferencesEmailCopyLabel',
                'label_attr' => ['class' => 'checkbox-switch'],
                'help' => 'messagingPreferencesEmailCopyHelpText',
                'required' => false,
            ])
            ->add('signature', TextareaType::class, [
                'label' => 'messagingPreferencesSignatureLabel',
                'help' => 'messagingPreferencesSignatureHelpText',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
