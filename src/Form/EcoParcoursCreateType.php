<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

// Not entity-mapped: $checkpointCount only exists at creation time (App\Service\EcoParcoursFactory
// turns it into that many individual EcoCheckpoint rows plus the auto Start/Finish), it isn't a
// field that persists on EcoParcours itself.
class EcoParcoursCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'ecoParcoursNameFieldLabel',
                'constraints' => [new NotBlank()],
            ])
            ->add('checkpointCount', IntegerType::class, [
                'label' => 'ecoParcoursCheckpointCountFieldLabel',
                'data' => 8,
                'constraints' => [new Positive()],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'ecoParcoursCreateSubmitLabel',
            ])
        ;
    }
}
