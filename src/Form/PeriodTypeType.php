<?php

namespace App\Form;

use App\Entity\PeriodType;
use Symfony\Component\Form\AbstractType;
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
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PeriodType::class,
            // PeriodType's constructor requires a name, so a fresh entity can't be built via
            // plain reflection - construct it here once the name field has actually been submitted.
            'empty_data' => static function (FormInterface $form): PeriodType {
                return new PeriodType($form->get('name')->getData() ?? '');
            },
        ]);
    }
}
