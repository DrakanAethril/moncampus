<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * « Ma page »: the address, the title and the few lines of presentation of a teacher's public page
 * (design/validated/cours-en-ligne.md, §8).
 *
 * Not mapped onto the entity: the address does not go through a setter but through
 * App\Service\OnlineCourse\OnlineCoursePageHandles, which is what keeps the one being left
 * redirecting - and the same form serves the day the page does not exist yet.
 */
class OnlineCoursePageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('handle', TextType::class, [
                'label' => 'onlineCoursePageHandleFieldLabel',
                'help' => 'onlineCoursePageHandleFieldHelp',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'onlineCourseHandleFormatMessage')],
                'attr' => ['maxlength' => 60, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('title', TextType::class, [
                'label' => 'onlineCoursePageTitleFieldLabel',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'onlineCoursePageTitleRequiredMessage'), new Length(max: 150)],
                'attr' => ['maxlength' => 150],
            ])
            ->add('introduction', TextareaType::class, [
                'label' => 'onlineCoursePageIntroductionFieldLabel',
                'required' => false,
            ]);
    }
}
