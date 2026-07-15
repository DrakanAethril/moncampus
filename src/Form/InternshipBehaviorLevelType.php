<?php

namespace App\Form;

use App\Entity\InternshipBehaviorLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Entry type for the fixed 5-level CollectionType embedded in InternshipBehaviorCriteriaType -
// only the label is editable, levelNumber is fixed at creation (see
// UfaSettingsController::behaviorCriteriaForm()) and never exposed as a form field.
class InternshipBehaviorLevelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label' => false,
            // Explicit '' (not the default) activates TextType's own null->'' safety net for
            // blank submissions on this non-nullable property - see TextType::buildForm().
            'empty_data' => '',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipBehaviorLevel::class]);
    }
}
