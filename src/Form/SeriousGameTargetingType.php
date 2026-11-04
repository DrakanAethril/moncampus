<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SeriousGame;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * « Qui joue (étudiants) » of a serious game (design/validated/quiz-tournament.md §14), Paramètres ›
 * Serious games: the game's targeting, nothing else. Not required - an empty targeting is a decision
 * too, the game closed to every student.
 */
class SeriousGameTargetingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('targets', StructureTargetsType::class, [
            'targetable' => $options['data'] instanceof SeriousGame ? $options['data'] : null,
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SeriousGame::class]);
    }
}
