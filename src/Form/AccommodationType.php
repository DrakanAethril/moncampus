<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Accommodation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccommodationType extends AbstractType
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
            // html5:false so the field keeps the French decimal comma (« 33,33 ») NumberType
            // already parses - an <input type="number"> would refuse it outright in a fr-FR
            // browser. Two decimals, like the column.
            ->add('quizExtraTimePercent', NumberType::class, [
                'label' => 'accommodationQuizExtraTimeFieldLabel',
                'help' => 'accommodationQuizExtraTimeFieldHelpText',
                'required' => false,
                'html5' => false,
                'scale' => 2,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Accommodation::class,
            // Accommodation's constructor requires a name, so a fresh entity can't be built via
            // plain reflection - construct it here once the field is submitted.
            'empty_data' => static function (FormInterface $form): Accommodation {
                return new Accommodation($form->get('name')->getData() ?? '');
            },
        ]);
    }
}
