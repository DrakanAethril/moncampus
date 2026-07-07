<?php

namespace App\Form;

use App\Entity\InternshipBehaviorCriteria;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InternshipBehaviorCriteriaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'internshipBehaviorLabelFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net
                // (see TextType::buildForm) - without it, a blank submission on a non-nullable
                // string property throws a 500 instead of a NotBlank validation error.
                'empty_data' => '',
            ])
            // Fixed at exactly 5 entries - the controller always attaches the 5
            // InternshipBehaviorLevel rows (levelNumber 1-5) before building this form, so
            // allow_add/allow_delete stay false; only each level's label is editable here.
            ->add('levels', CollectionType::class, [
                'entry_type' => InternshipBehaviorLevelType::class,
                'allow_add' => false,
                'allow_delete' => false,
                'by_reference' => false,
                'label' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipBehaviorCriteria::class]);
    }
}
