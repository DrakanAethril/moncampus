<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Cohort;
use App\Entity\EvaluationPeriodGroup;
use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Enum\ProgramAlternanceCalendarMode;
use App\Enum\ProgramSyllabusMode;
use App\Enum\VisibilityLevel;
use App\Repository\EvaluationPeriodGroupRepository;
use App\Service\UploadPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProgramType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('shortName', TextType::class, [
                'label' => 'structureShortNameColumnLabel',
                'empty_data' => '',
            ])
            ->add('cohort', EntityType::class, [
                'class' => Cohort::class,
                'choice_label' => 'name',
                'label' => 'structureParentCohortColumnLabel',
                'placeholder' => 'structureCohortPlaceholder',
            ])
            ->add('schoolYear', EntityType::class, [
                'class' => SchoolYear::class,
                'choice_label' => static fn (SchoolYear $schoolYear): string => sprintf('%s - %s', $schoolYear->getStartDate()->format('Y'), $schoolYear->getEndDate()->format('Y')),
                'label' => 'structureSchoolYearColumnLabel',
                'placeholder' => 'structureSchoolYearPlaceholder',
            ])
            ->add('startDate', DateType::class, [
                'label' => 'programStartDateFieldLabel',
                'help' => 'programStartDateFieldHelpText',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'programEndDateFieldLabel',
                'help' => 'programEndDateFieldHelpText',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            // Program is the inverse side of these ManyToMany relations (Option/Modality own
            // them), so by_reference must be false to make Symfony call addOption()/
            // removeOption() (and the Modality equivalents) instead of mutating the inverse
            // collection directly, which Doctrine would never persist.
            ->add('options', EntityType::class, [
                'class' => Option::class,
                'choice_label' => 'shortName',
                'label' => 'structureOptionsColumnLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('modalities', EntityType::class, [
                'class' => Modality::class,
                'choice_label' => static fn (Modality $modality): string => $modality->getShortName() ?? $modality->getName(),
                'label' => 'structureModalitiesColumnLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('timetableManagementEnabled', CheckboxType::class, [
                'label' => 'programTimetableManagementFieldLabel',
                'required' => false,
            ])
            ->add('timetableVisibility', EnumType::class, [
                'class' => VisibilityLevel::class,
                'choice_label' => static fn (VisibilityLevel $level): string => $level->labelKey(),
                'label' => 'programTimetableVisibilityFieldLabel',
            ])
            ->add('syllabusVisibility', EnumType::class, [
                'class' => VisibilityLevel::class,
                'choice_label' => static fn (VisibilityLevel $level): string => $level->labelKey(),
                'label' => 'programSyllabusVisibilityFieldLabel',
            ])
            ->add('syllabusMode', EnumType::class, [
                'class' => ProgramSyllabusMode::class,
                'choice_label' => static fn (ProgramSyllabusMode $mode): string => $mode->labelKey(),
                'label' => 'programSyllabusModeFieldLabel',
            ])
            ->add('syllabusFile', FilePickerType::class, [
                'label' => 'programSyllabusFileFieldLabel',
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'mapped' => false,
                'required' => false,
                'policy' => UploadPolicy::pdf(),
                // A programme document rather than a teacher's own course material: no library tab.
                'library' => false,
            ])
            ->add('financialManagementEnabled', CheckboxType::class, [
                'label' => 'programFinancialManagementFieldLabel',
                'required' => false,
            ])
            ->add('assignmentManagementEnabled', CheckboxType::class, [
                'label' => 'programAssignmentManagementFieldLabel',
                'required' => false,
            ])
            // The third axis of the feature system: the Courrier école is decided per formation
            // rather than per role or per person (design/validated/feature-access.md, "Le troisième
            // axe"). The help line says what it does *not* do, because that is the half people get
            // wrong: the addresses exist and keep receiving whatever this box says - closing it
            // closes the reading, never the mailbox (§8.6).
            ->add('schoolMailEnabled', CheckboxType::class, [
                'label' => 'programSchoolMailEnabledFieldLabel',
                'help' => 'programSchoolMailEnabledFieldHelp',
                'required' => false,
            ])
            // UFA section fields - all revealed together on the Program form by the alternance
            // Modality chip (Modality::$isAlternance), not by a dedicated checkbox of their own.
            ->add('internshipManagementEnabled', CheckboxType::class, [
                'label' => 'programInternshipManagementFieldLabel',
                'required' => false,
            ])
            ->add('tsfExportEnabled', CheckboxType::class, [
                'label' => 'programTsfExportEnabledFieldLabel',
                'help' => 'programTsfExportEnabledFieldHelp',
                'required' => false,
            ])
            ->add('alternanceCalendarVisibility', EnumType::class, [
                'class' => VisibilityLevel::class,
                'choice_label' => static fn (VisibilityLevel $level): string => $level->labelKey(),
                // No "Enseignants uniquement" tier for the alternance calendar - unlike
                // timetable/syllabus visibility above.
                'choice_filter' => static fn (VisibilityLevel $level): bool => VisibilityLevel::TeachersOnly !== $level,
                'label' => 'programAlternanceCalendarVisibilityFieldLabel',
            ])
            ->add('alternanceCalendarMode', EnumType::class, [
                'class' => ProgramAlternanceCalendarMode::class,
                'choice_label' => static fn (ProgramAlternanceCalendarMode $mode): string => $mode->labelKey(),
                'label' => 'programAlternanceCalendarModeFieldLabel',
            ])
            ->add('alternanceCalendarFile', FilePickerType::class, [
                'label' => 'programAlternanceCalendarFileFieldLabel',
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'mapped' => false,
                'required' => false,
                'policy' => UploadPolicy::pdf(),
                // A programme document rather than a teacher's own course material: no library tab.
                'library' => false,
            ])
            ->add('evaluationPeriodGroup', EntityType::class, [
                'class' => EvaluationPeriodGroup::class,
                'choice_label' => 'name',
                'query_builder' => static fn (EvaluationPeriodGroupRepository $repository) => $repository->createQueryBuilder('g')
                    ->andWhere('g.inactiveDate IS NULL')
                    ->orderBy('g.name', 'ASC'),
                'label' => 'structureEvaluationPeriodGroupColumnLabel',
                'placeholder' => 'structureEvaluationPeriodGroupPlaceholder',
                'required' => false,
            ])
            ->add('testProgram', CheckboxType::class, [
                'label' => 'programTestProgramFieldLabel',
                'help' => 'programTestProgramFieldHelpText',
                'required' => false,
            ])
            ->add('visibility', EnumType::class, [
                'class' => VisibilityLevel::class,
                'choice_label' => static fn (VisibilityLevel $level): string => $level->labelKey(),
                'choice_filter' => static fn (VisibilityLevel $level): bool => VisibilityLevel::TeachersOnly !== $level,
                'label' => 'programVisibilityFieldLabel',
                'help' => 'programVisibilityFieldHelpText',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Program::class,
            // Same reasoning as CohortType::$empty_data: Program's constructor requires a
            // name, a short name, a Cohort and a SchoolYear, built here from already-submitted
            // sibling fields, with throwaway fallbacks so a missing required field is a
            // validation error, not a TypeError.
            'empty_data' => static function (FormInterface $form): Program {
                $cohort = $form->get('cohort')->getData() ?? new Cohort('', new Track('', new Section('')));
                $schoolYear = $form->get('schoolYear')->getData() ?? new SchoolYear(new \DateTimeImmutable(), new \DateTimeImmutable());

                return new Program(
                    $form->get('name')->getData() ?? '',
                    $form->get('shortName')->getData() ?? '',
                    $cohort,
                    $schoolYear,
                );
            },
        ]);
    }
}
