<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The "Tuteur en entreprise" block shared by every screen that attaches a tutor to an alternance
 * (App\Form\InternshipAlternanceType and App\Form\InternshipTutorLinkType). Deliberately unmapped
 * and data_class-less: none of this lands on InternshipTutorLink itself, which only ever holds a
 * $tutor User - these are the raw inputs App\Service\InternshipTutorFormResolver turns into one,
 * either by picking an existing tutor account or by provisioning a brand new one.
 *
 * $mode drives assets/controllers/tutor_picker_controller.js's radio-panel toggle; the four
 * identity fields are only filled (and only shown) in "new" mode, $existingTutorId only in
 * "existing" mode, where it carries a User id chosen through the tom-select ajax search.
 *
 * Everything is required: false at the form level - a missing tutor surfaces as the entity's own
 * Assert\NotNull on $tutor rather than as four separate "this field is required" errors on inputs
 * the staff member may legitimately have left hidden.
 */
class InternshipTutorFieldsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mode', ChoiceType::class, [
                'expanded' => true,
                'choices' => ['ufaAlternanceNewTutorExistingLabel' => 'existing', 'ufaAlternanceNewTutorNewLabel' => 'new'],
                'data' => 'existing',
                'label' => false,
            ])
            ->add('existingTutorId', HiddenType::class, ['required' => false])
            ->add('firstname', TextType::class, ['label' => 'internshipTutorLinkTutorFirstNameFieldLabel', 'required' => false])
            ->add('lastname', TextType::class, ['label' => 'internshipTutorLinkTutorLastNameFieldLabel', 'required' => false])
            ->add('email', TextType::class, ['label' => 'internshipTutorLinkTutorEmailFieldLabel', 'required' => false, 'help' => 'internshipTutorLinkTutorEmailFieldHelp'])
            ->add('phone', TelType::class, ['label' => 'internshipTutorLinkTutorPhoneFieldLabel', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['mapped' => false, 'label' => false]);
    }
}
