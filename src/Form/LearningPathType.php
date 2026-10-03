<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LearningPath;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The card of a learning path (design/validated/cours-en-ligne.md, §10): what somebody reads before
 * they start it. The steps are not here - they are added, ordered and removed one at a time on the
 * path's own screen.
 */
class LearningPathType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'learningPathTitleFieldLabel',
                'empty_data' => '',
                'attr' => ['maxlength' => 200],
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'learningPathSummaryFieldLabel',
                'help' => 'learningPathSummaryFieldHelp',
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 2, 'maxlength' => 300],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'learningPathDescriptionFieldLabel',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LearningPath::class,
        ]);
    }
}
