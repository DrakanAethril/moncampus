<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\OnlineCourse;
use App\Entity\QuizTemplate;
use App\Repository\QuizTemplateRepository;
use App\Service\OnlineCourse\OnlineCourseImageStore;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/**
 * The card of an online course (design/validated/cours-en-ligne.md, §8): what its author's public
 * page shows of it. The materials are not here - each is added on a screen of its own, since each
 * carries a file.
 *
 * Two fields are not plain mappings:
 *
 * - "slug" is normalized at PRE_SUBMIT, and derived from the title when left empty, so that the
 *   format constraint on the entity never refuses what a person would reasonably type. Once the
 *   course has been published the field is disabled: the address a class was given must keep
 *   answering (the entity ignores a slug posted by hand all the same).
 * - "tags" is a hidden field holding the labels, one per line - the documentation's own tag field,
 *   reused: the vocabulary is created by typing, so there is nothing to choose from.
 *
 * "image" and "removeImage" are not mapped either: the picture is a file the controller hands to
 * App\Service\OnlineCourse\OnlineCourseImageStore, once the course has an id to file it under.
 *
 * "quiz" - the test « Test » launches on the course's card - and "testPassPercent", the share of
 * right answers that earns « Bravo », are not mapped: the controller hands both to
 * App\Service\OnlineCourse\OnlineCourseWriter::linkQuiz(), the one door the connector goes
 * through too. Its choices already hold the rule (the author's own quizzes that ask something), so a
 * forged id is refused by the form before the writer is reached.
 */
class OnlineCourseType extends AbstractType
{
    public function __construct(
        private readonly OnlineCourseWriter $writer,
        private readonly QuizTemplateRepository $quizzes,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $course = $builder->getData();
        $frozen = $course instanceof OnlineCourse && $course->isSlugFrozen();

        $builder
            ->add('title', TextType::class, [
                'label' => 'onlineCourseTitleFieldLabel',
                'empty_data' => '',
                'attr' => ['maxlength' => 200],
            ])
            ->add('slug', TextType::class, [
                'label' => 'onlineCourseSlugFieldLabel',
                'help' => $frozen ? 'onlineCourseSlugFrozenHelp' : 'onlineCourseSlugFieldHelp',
                'required' => false,
                'empty_data' => '',
                'disabled' => $frozen,
                'attr' => ['maxlength' => 120, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'onlineCourseSummaryFieldLabel',
                'help' => 'onlineCourseSummaryFieldHelp',
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 2, 'maxlength' => 300],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'onlineCourseDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('estimatedMinutes', IntegerType::class, [
                'label' => 'onlineCourseDurationFieldLabel',
                'help' => 'onlineCourseDurationFieldHelp',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 6000],
            ])
            ->add('tags', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
            ->add('image', FilePickerType::class, [
                'label' => 'onlineCourseImageFieldLabel',
                'help' => 'onlineCourseImageFieldHelp',
                'mapped' => false,
                'required' => false,
                'policy' => OnlineCourseImageStore::policy(),
                // Like a material, a picture picked in the bibliothèque is copied into the course's
                // own folder, not referenced (OnlineCourseImageStore).
                'library' => true,
            ])
            ->add('removeImage', CheckboxType::class, [
                'label' => 'onlineCourseRemoveImageFieldLabel',
                'mapped' => false,
                'required' => false,
            ])
            ->add('quiz', EntityType::class, [
                'class' => QuizTemplate::class,
                'label' => 'onlineCourseQuizFieldLabel',
                'help' => 'onlineCourseQuizFieldHelp',
                'mapped' => false,
                'required' => false,
                'placeholder' => 'onlineCourseQuizPlaceholder',
                'choices' => $course instanceof OnlineCourse ? array_values(array_filter(
                    $this->quizzes->findPickable($course->getOwner()),
                    static fn (QuizTemplate $quiz): bool => !$quiz->getQuestions()->isEmpty(),
                )) : [],
                'choice_label' => static fn (QuizTemplate $quiz): string => $quiz->getName() ?? '',
                'data' => $course instanceof OnlineCourse ? $course->getQuizTemplate() : null,
            ])
            ->add('testPassPercent', IntegerType::class, [
                'label' => 'onlineCourseTestPassPercentFieldLabel',
                'help' => 'onlineCourseTestPassPercentFieldHelp',
                'mapped' => false,
                'required' => false,
                'data' => $course instanceof OnlineCourse ? $course->getTestPassPercent() : OnlineCourse::DEFAULT_TEST_PASS_PERCENT,
                'constraints' => [new Range(min: 1, max: 100)],
                'attr' => ['min' => 1, 'max' => 100, 'step' => 1],
            ]);

        if (!$frozen) {
            $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $data = $event->getData();
                if (!\is_array($data)) {
                    return;
                }

                $slug = isset($data['slug']) && \is_scalar($data['slug']) ? (string) $data['slug'] : '';
                $title = isset($data['title']) && \is_scalar($data['title']) ? (string) $data['title'] : '';
                $normalized = $this->writer->normalizeSlug($slug);
                $course = $event->getForm()->getData();
                // An address left empty is derived from the title and numbered when the author
                // already uses it; one typed by hand is only normalized - the form then says when
                // it is taken, rather than quietly changing what was asked for.
                $data['slug'] = match (true) {
                    '' !== $normalized => $normalized,
                    $course instanceof OnlineCourse => $this->writer->availableSlug($course->getOwner(), $title, $course),
                    default => $this->writer->normalizeSlug($title),
                };
                $event->setData($data);
            });
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OnlineCourse::class,
        ]);
    }
}
