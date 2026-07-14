<?php

namespace App\Form;

use App\Entity\LessonSession;
use App\Entity\LessonType;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Room;
use App\Entity\Topic;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
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
            // Deliberately not derived from startHour/endHour (those only position the session
            // on the timetable) - the only value ProgramFinancialCalculator uses for cost
            // calculations, so it's always entered by hand.
            ->add('length', NumberType::class, [
                'label' => 'lessonSessionLengthFieldLabel',
                'html5' => false,
            ])
            // Title is optional: pick one of the program's own topics for its name to double as
            // the session's display name, or leave it unset and fill in a free-text title
            // instead (or both, with the title taking precedence for display).
            ->add('topic', EntityType::class, [
                'class' => Topic::class,
                'choices' => $program->getTopics()->filter(static fn (Topic $topic): bool => null === $topic->getInactiveDate()),
                'choice_label' => 'name',
                'label' => 'lessonSessionTopicFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('title', TextType::class, [
                'label' => 'lessonSessionTitleFieldLabel',
                'required' => false,
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
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'submitCreateAction',
        ]);

        // LessonSession's constructor only requires a Program - built here from the "program"
        // form option, captured directly since configureOptions() below has no access to
        // per-request option values.
        $builder->setEmptyData(static fn (): LessonSession => new LessonSession($program));
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
