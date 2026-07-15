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
        /** @var User|null $referee */
        $referee = $options['referee'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'reportTitleFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('day', DateType::class, [
                'label' => 'reportDayFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            // Not a form field: "referee" is picked via an ajax tom-select field embedded
            // directly in report_new.html.twig, resolved server-side by
            // ProgramSettingsController::reportForm() (same convention as LessonSessionType's
            // teacher field) into the "referee" option above - only the program's own teachers
            // are eligible. Unlike that field this one is required (ProgramReport's constructor
            // needs it), so the controller sets it on the entity before validation for the edit
            // case, and this form's own empty_data (below) uses the resolved option directly for
            // the new-entity case.
        ;

        if (!$program->getOptions()->isEmpty()) {
            $builder->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $program->getOptions(),
                'choice_label' => 'shortName',
                'label' => 'reportOptionsFieldLabel',
                'multiple' => true,
                'expanded' => true,
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
        // submitted values, the "program" form option, and the already-resolved "referee" option,
        // captured directly since configureOptions() below has no access to per-request option
        // values.
        $builder->setEmptyData(static function (FormInterface $form) use ($program, $referee): ProgramReport {
            /** @var \DateTimeImmutable|null $day */
            $day = $form->get('day')->getData();

            return new ProgramReport($form->get('title')->getData() ?? '', $day ?? new \DateTimeImmutable(), $referee, $program);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => ProgramReport::class])
            ->setRequired(['program', 'referee'])
            ->setAllowedTypes('program', Program::class)
            ->setAllowedTypes('referee', ['null', User::class])
        ;
    }
}
