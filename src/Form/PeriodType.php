<?php

namespace App\Form;

use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\PeriodType as PeriodTypeEntity;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Named PeriodType to match the App\Entity\Period it edits, per this codebase's <Entity>Type
// form-naming convention - unrelated to the separate App\Entity\PeriodType lookup entity (which
// this form references as a field, aliased PeriodTypeEntity below to avoid a same-namespace
// class-name clash with this class itself).
class PeriodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var PeriodGroup $periodGroup */
        $periodGroup = $options['periodGroup'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('startDate', DateType::class, [
                'label' => 'structureStartDateColumnLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'structureEndDateColumnLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('type', EntityType::class, [
                'class' => PeriodTypeEntity::class,
                'choice_label' => 'name',
                'label' => 'periodTypeFieldLabel',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // Period's constructor requires a name, start/end dates, a type and a periodGroup - built
        // here from already-submitted sibling fields plus the "periodGroup" form option (fixed by
        // the URL's {groupId}, not a form field itself), same reasoning as ProgramReportType's
        // "program" option.
        $builder->setEmptyData(static function (FormInterface $form) use ($periodGroup): Period {
            /** @var PeriodTypeEntity|null $type */
            $type = $form->get('type')->getData();

            return new Period(
                $form->get('name')->getData() ?? '',
                $form->get('startDate')->getData() ?? new \DateTimeImmutable(),
                $form->get('endDate')->getData() ?? new \DateTimeImmutable(),
                $type,
                $periodGroup,
            );
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => Period::class])
            ->setRequired('periodGroup')
            ->setAllowedTypes('periodGroup', PeriodGroup::class)
        ;
    }
}
