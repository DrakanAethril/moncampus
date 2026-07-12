<?php

namespace App\Form;

use App\Entity\Program;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Not entity-backed - only asks for what a SeanceInstance doesn't already carry (day/hours/room/
// teacher). Title/length come from the SeanceInstance's own frozen content, same reasoning as
// App\Form\LessonLogAttachmentType not being mapped onto a single entity's field shape.
class ScheduleSeanceType extends AbstractType
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
                'mapped' => false,
            ])
            ->add('startHour', TimeType::class, [
                'label' => 'lessonSessionStartHourFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'mapped' => false,
            ])
            ->add('endHour', TimeType::class, [
                'label' => 'lessonSessionEndHourFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'mapped' => false,
            ])
            ->add('teacher', EntityType::class, [
                'class' => User::class,
                'choices' => $program->getTeachers(),
                'choice_label' => static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                'label' => 'lessonSessionTeacherFieldLabel',
                'required' => false,
                'mapped' => false,
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
                'mapped' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'scheduleSeanceAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
