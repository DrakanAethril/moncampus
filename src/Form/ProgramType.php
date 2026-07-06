<?php

namespace App\Form;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
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
            ])
            ->add('shortName', TextType::class, [
                'label' => 'structureShortNameColumnLabel',
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
