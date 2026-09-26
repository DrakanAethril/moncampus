<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\QuizTemplate;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/**
 * One « quiz added » row of the merged pool on screen 1c: the quiz, and the share of the final draw
 * it must provide (« Part du quiz »). The share is optional - a row left blank draws from what the
 * shared ones leave (App\Service\QuizPoolShares).
 */
class QuizPoolEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('template', EntityType::class, [
                'class' => QuizTemplate::class,
                'choices' => $options['templateChoices'],
                'choice_label' => static fn (QuizTemplate $template): string => $template->getName() ?? '',
                'label' => false,
                'placeholder' => 'quizLaunchAdditionalTemplatePlaceholder',
                'required' => false,
            ])
            ->add('share', IntegerType::class, [
                'label' => 'quizLaunchShareFieldLabel',
                'required' => false,
                'constraints' => [new Range(min: 1, max: 100)],
                // Up to the row, and from there to the pool (QuizLaunchType): a row is one line of
                // a flex layout, and its errors are read under the whole pool.
                'error_bubbling' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefault('error_bubbling', true)
            ->setRequired('templateChoices')
            ->setAllowedTypes('templateChoices', 'array')
        ;
    }
}
