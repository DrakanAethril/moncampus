<?php

namespace App\Form;

use App\Entity\PeriodGroup;
use App\Entity\SchoolYear;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PeriodGroupType extends AbstractType
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
            'data_class' => PeriodGroup::class,
            // PeriodGroup's constructor requires a name and a school year, built here from
            // already-submitted sibling fields, so a missing required field is a validation
            // error, not a TypeError - same reasoning as SchoolYearType::$empty_data.
            'empty_data' => static function (FormInterface $form): PeriodGroup {
                return new PeriodGroup(
                    $form->get('name')->getData() ?? '',
                    $form->get('schoolYear')->getData() ?? new SchoolYear(new \DateTimeImmutable(), new \DateTimeImmutable()),
                );
            },
        ]);
    }
}
