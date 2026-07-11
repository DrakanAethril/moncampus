<?php

namespace App\Form;

use App\Entity\LessonLog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LessonLogType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contenuRealise', TextareaType::class, [
                'label' => 'lessonLogContenuRealiseFieldLabel',
                'required' => false,
            ])
            ->add('travailAvantDescription', TextareaType::class, [
                'label' => 'lessonLogTravailAvantFieldLabel',
                'required' => false,
            ])
            ->add('travailApresDescription', TextareaType::class, [
                'label' => 'lessonLogTravailApresFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LessonLog::class]);
    }
}
