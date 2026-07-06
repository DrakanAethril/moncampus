<?php

namespace App\Form;

use App\Entity\LessonType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LessonTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
            ])
            ->add('agendaColor', ColorType::class, [
                'label' => 'structureAgendaColorColumnLabel',
            ])
            ->add('defaultCost', NumberType::class, [
                'label' => 'lessonTypeDefaultCostFieldLabel',
                'required' => false,
                'html5' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LessonType::class,
            // LessonType's constructor requires a name and a color, so a fresh entity can't be
            // built via plain reflection - construct it here once those fields are submitted.
            'empty_data' => static function (FormInterface $form): LessonType {
                return new LessonType(
                    $form->get('name')->getData() ?? '',
                    $form->get('agendaColor')->getData() ?? '#206bc4',
                );
            },
        ]);
    }
}
