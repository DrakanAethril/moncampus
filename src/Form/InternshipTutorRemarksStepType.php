<?php

namespace App\Form;

use App\Entity\InternshipTutorEvaluation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Tuteur wizard step 4 ("Remarques et signature", 28d). The signature itself is not a form field
 * (decision #1 - a plain authenticated click): the template renders one plain
 * <button name="action" value="sign"> outside this form's own SubmitType, and the controller
 * checks that request field to tell a save from a "Signer et transmettre..." submit.
 */
class InternshipTutorRemarksStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('remarksText', TextareaType::class, ['label' => 'internshipTutorEvaluationRemarksFieldLabel', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipTutorEvaluation::class]);
    }
}
