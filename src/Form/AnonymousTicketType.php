<?php

namespace App\Form;

use App\Entity\Ticket;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The logged-out "lost access to your account" form (see PublicTicketController). Deliberately
// narrower than TicketType: no subject (auto-generated server-side), no category/room picker
// (fixed to the "Account & Login Access" category server-side) - this form only needs to exist
// for the one thing that genuinely can't wait for a login: getting back into the platform.
class AnonymousTicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reporterName', TextType::class, [
                'label' => 'anonymousTicketNameFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable-for-this-form property.
                'empty_data' => '',
            ])
            ->add('reporterContact', TextType::class, [
                'label' => 'anonymousTicketContactFieldLabel',
                'empty_data' => '',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'anonymousTicketDescriptionFieldLabel',
                'empty_data' => '',
            ])
            // Honeypot: hidden off-screen via CSS in the template, so real users never see or
            // fill it - anything that does is almost certainly a bot filling every field it
            // finds. Not mapped onto the entity; checked directly in the controller.
            ->add('website', TextType::class, [
                'label' => false,
                'required' => false,
                'mapped' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'anonymousTicketSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
            'validation_groups' => ['Default', 'anonymous'],
        ]);
    }
}
