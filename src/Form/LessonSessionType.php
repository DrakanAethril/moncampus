<?php

namespace App\Form;

use App\Entity\LessonSession;
use App\Entity\LessonType;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LessonSessionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('day', DateType::class, [
                'label' => 'lessonSessionDayFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('startHour', TimeType::class, [
                'label' => 'lessonSessionStartHourFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('endHour', TimeType::class, [
                'label' => 'lessonSessionEndHourFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('title', TextType::class, [
                'label' => 'lessonSessionTitleFieldLabel',
            ])
            // Only the program's own teachers/options can be picked here - a teacher must
            // already be attached to the class (via the Enseignants tab) before being
            // scheduled to teach one of its lesson sessions.
            ->add('teacher', EntityType::class, [
                'class' => User::class,
                'choices' => $program->getTeachers(),
                'choice_label' => static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                'label' => 'lessonSessionTeacherFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('classRoom', EntityType::class, [
                'class' => Room::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('r')
                    ->where('r.inactiveDate IS NULL')
                    ->orderBy('r.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'lessonSessionClassRoomFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('lessonType', EntityType::class, [
                'class' => LessonType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('l')
                    ->where('l.inactiveDate IS NULL')
                    ->orderBy('l.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'lessonSessionLessonTypeFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
        ;

        if (!$program->getOptions()->isEmpty()) {
            $builder->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $program->getOptions(),
                'choice_label' => 'shortName',
                'label' => 'lessonSessionOptionsFieldLabel',
                'multiple' => true,
                'required' => false,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'submitCreateAction',
        ]);

        // LessonSession's constructor requires a title and a Program - built here from the
        // already-submitted "title" field and the "program" form option (captured directly,
        // since configureOptions() below has no access to per-request option values), so a
        // missing title is a validation error, not a TypeError.
        $builder->setEmptyData(static function (FormInterface $form) use ($program): LessonSession {
            return new LessonSession($form->get('title')->getData() ?? '', $program);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => LessonSession::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
