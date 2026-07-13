<?php

namespace App\Form;

use App\Entity\PeriodType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PeriodTypeType extends AbstractType
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
            ->add('color', ColorType::class, [
                'label' => 'structureColorColumnLabel',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PeriodType::class,
            // Same reasoning as OptionType::$empty_data: PeriodType's constructor requires a
            // name and a color, built here from already-submitted sibling fields, with a
            // throwaway fallback so a missing required field is a validation error, not a
            // TypeError.
            'empty_data' => static function (FormInterface $form): PeriodType {
                return new PeriodType(
                    $form->get('name')->getData() ?? '',
                    $form->get('color')->getData() ?? '#206bc4',
                );
            },
        ]);
    }
}
