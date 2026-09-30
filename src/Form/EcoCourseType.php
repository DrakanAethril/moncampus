<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCourse;
use App\Enum\EcoCourseMode;
use App\Enum\EcoMapVisibility;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

// Screen 1g's "Nouvelle course" panel - reused unchanged for editing a Prepared course (the code
// itself is generated server-side, App\Service\EcoCourseCodeGenerator, never edited here), and by
// the teacher app's course creation (App\Controller\Api\EcoTeacherApiController), so the rules are
// written once.
class EcoCourseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'ecoCourseNameFieldLabel',
                'constraints' => [new NotBlank()],
            ])
            ->add('mode', EnumType::class, [
                'class' => EcoCourseMode::class,
                'choice_label' => static fn (EcoCourseMode $mode): string => $mode->labelKey(),
                'expanded' => true,
                'label' => 'ecoCourseModeFieldLabel',
            ])
            // « Balises spécifiques »: in order, or in the order the runner likes.
            ->add('specificOrdered', ChoiceType::class, [
                'label' => 'ecoCourseSpecificOrderFieldLabel',
                'expanded' => true,
                // Always answered - the entity defaults to « dans l'ordre » - so no empty choice.
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    'ecoCourseSpecificOrderedLabel' => true,
                    'ecoCourseSpecificFreeOrderLabel' => false,
                ],
            ])
            // A race run in order is ranked on time: there is no allowance to run out of. The field
            // is therefore only shown for the others (eco_course_mode_controller, template side).
            ->add('timeLimitMinutes', IntegerType::class, [
                'label' => 'ecoCourseTimeLimitFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
                'attr' => ['min' => 1, 'placeholder' => '45'],
            ])
            ->add('mapVisibility', EnumType::class, [
                'class' => EcoMapVisibility::class,
                'choice_label' => static fn (EcoMapVisibility $visibility): string => $visibility->labelKey(),
                'label' => 'ecoCourseMapVisibilityFieldLabel',
            ])
            ->add('teamsEnabled', CheckboxType::class, [
                'label' => 'ecoCourseTeamsEnabledFieldLabel',
                'required' => false,
            ])
            ->add('safetyAlertsEnabled', CheckboxType::class, [
                'label' => 'ecoCourseSafetyAlertsEnabledFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'ecoCourseCreateSubmitLabel',
            ])
        ;

        // The flags to choose from are the course's own parcours', known only once the course is.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $course = $event->getData();
            if (!$course instanceof EcoCourse || null === $course->getParcours()) {
                return;
            }

            $event->getForm()->add('specificCheckpoints', EntityType::class, [
                'class' => EcoCheckpoint::class,
                'choices' => $course->getParcours()->getRegularCheckpoints(),
                'choice_label' => static fn (EcoCheckpoint $checkpoint): string => null !== $checkpoint->getNote() && '' !== $checkpoint->getNote()
                    ? \sprintf('%s (%s)', $checkpoint->getName(), $checkpoint->getNote())
                    : (string) $checkpoint->getName(),
                'multiple' => true,
                'expanded' => true,
                // Through addSpecificCheckpoint()/removeSpecificCheckpoint(), which refuse a flag
                // of another parcours.
                'by_reference' => false,
                'required' => false,
                'label' => 'ecoCourseSpecificCheckpointsFieldLabel',
            ]);
        });

        // What a mode does not use is not kept: a selection left over from « Balises spécifiques »,
        // an allowance left over from a mode played against the clock. Before validation, so the
        // entity is judged as it will be stored.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $course = $event->getData();
            if (!$course instanceof EcoCourse) {
                return;
            }

            if (!$course->isSpecificCheckpoints()) {
                foreach ($course->getSpecificCheckpoints()->toArray() as $checkpoint) {
                    $course->removeSpecificCheckpoint($checkpoint);
                }
                $course->setSpecificOrdered(true);
            }
            if (!$course->isTimeLimited()) {
                $course->setTimeLimitMinutes(null);
            }
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EcoCourse::class]);
    }
}
