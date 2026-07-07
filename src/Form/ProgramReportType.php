<?php

namespace App\Form;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramReport;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProgramReportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'reportTitleFieldLabel',
            ])
            ->add('day', DateType::class, [
                'label' => 'reportDayFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            // Only the program's own teachers can be picked here, same reasoning as the lesson
            // session form's teacher field.
            ->add('referee', EntityType::class, [
                'class' => User::class,
                'choices' => $program->getTeachers(),
                'choice_label' => static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                'label' => 'reportRefereeFieldLabel',
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
        ;

        if (!$program->getOptions()->isEmpty()) {
            $builder->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $program->getOptions(),
                'choice_label' => 'shortName',
                'label' => 'reportOptionsFieldLabel',
                'multiple' => true,
                'required' => false,
            ]);
        }

        $builder
            ->add('description', TextareaType::class, [
                'label' => 'reportDescriptionFieldLabel',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // ProgramReport's constructor requires a title, day and referee - built here from the
        // submitted values and the "program" form option, captured directly since
        // configureOptions() below has no access to per-request option values.
        $builder->setEmptyData(static function (FormInterface $form) use ($program): ProgramReport {
            /** @var \DateTimeImmutable|null $day */
            $day = $form->get('day')->getData();
            /** @var User|null $referee */
            $referee = $form->get('referee')->getData();

            return new ProgramReport($form->get('title')->getData() ?? '', $day ?? new \DateTimeImmutable(), $referee, $program);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => ProgramReport::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
