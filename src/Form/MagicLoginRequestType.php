<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The logged-out "email me a login link" form (see PublicMagicLoginController). Not bound to
// User::$contactEmail (data_class null, plain array data) - a submitted address is looked up,
// never trusted or written back, since the whole point is this runs before anyone is
// authenticated.
class MagicLoginRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Mapped, unlike the honeypot below: PublicMagicLoginController::sent() builds this
            // form with ['email' => …] to pre-fill the resend button with what the browser just
            // typed, and an unmapped field would silently ignore that data. Mapping it writes into
            // the plain array the form carries, never into a User.
            ->add('email', EmailType::class, [
                'label' => 'magicLoginEmailFieldLabel',
            ])
            // Honeypot - same convention as AnonymousTicketType::$website, hidden off-screen in
            // the template, checked directly in the controller, never persisted.
            ->add('website', TextType::class, [
                'label' => false,
                'required' => false,
                'mapped' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'magicLoginRequestSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
