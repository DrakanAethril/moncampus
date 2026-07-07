<?php

namespace App\Form;

use App\Entity\InternshipBehaviorLevel;
use App\Entity\InternshipTutorEvaluationBehavior;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Entry type for the InternshipTutorEvaluationType's 'behaviorEvaluations' CollectionType - each
// row's own behaviorCriteria (already fixed at creation, see
// InternshipTutorEvaluationController::evaluate()) determines which 5 levels are choosable, so
// the field is added dynamically from the bound row's own data rather than via a static form
// option (CollectionType applies the same entry_options to every row, but each row here needs
// different choices).
class InternshipTutorEvaluationBehaviorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            /** @var InternshipTutorEvaluationBehavior|null $behaviorEvaluation */
            $behaviorEvaluation = $event->getData();

            if (null === $behaviorEvaluation || null === $behaviorEvaluation->getBehaviorCriteria()) {
                return;
            }

            $event->getForm()->add('behaviorLevel', EntityType::class, [
                'class' => InternshipBehaviorLevel::class,
                'choices' => $behaviorEvaluation->getBehaviorCriteria()->getLevels(),
                'choice_label' => 'label',
                'label' => false,
                'required' => false,
                'placeholder' => false,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipTutorEvaluationBehavior::class]);
    }
}
