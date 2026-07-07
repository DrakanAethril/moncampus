<?php

namespace App\Form;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InternshipTutorLinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            // Only the program's own students can be linked here, same scoping style as
            // TopicType's teacher field.
            ->add('student', EntityType::class, [
                'class' => User::class,
                'choices' => $program->getStudents(),
                'choice_label' => static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                'label' => 'internshipTutorLinkStudentFieldLabel',
            ])
            ->add('tutorFirstName', TextType::class, [
                'label' => 'internshipTutorLinkTutorFirstNameFieldLabel',
            ])
            ->add('tutorLastName', TextType::class, [
                'label' => 'internshipTutorLinkTutorLastNameFieldLabel',
            ])
            ->add('tutorEmail', TextType::class, [
                'label' => 'internshipTutorLinkTutorEmailFieldLabel',
            ])
            ->add('tutorPhone', TelType::class, [
                'label' => 'internshipTutorLinkTutorPhoneFieldLabel',
            ])
            ->add('companyName', TextType::class, [
                'label' => 'internshipTutorLinkCompanyNameFieldLabel',
            ])
            ->add('companyAddress', TextareaType::class, [
                'label' => 'internshipTutorLinkCompanyAddressFieldLabel',
                'required' => false,
            ])
            ->add('contractStartDate', DateType::class, [
                'label' => 'internshipTutorLinkContractStartDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('contractEndDate', DateType::class, [
                'label' => 'internshipTutorLinkContractEndDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => InternshipTutorLink::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
