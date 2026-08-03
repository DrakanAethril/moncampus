<?php

namespace App\Form;

use App\Entity\Assignment;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Enum\AssignmentNature;
use App\Enum\QuizMode;
use App\Repository\QuizInstanceRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * « Ajouter un travail » depuis une séance (design_handoff_cahier_de_texte 2b).
 *
 * Volontairement plus court qu'AssignmentType : ni formation, ni destinataires, ni nature libre.
 * Un travail donné en séance s'adresse au public de cette séance, décidé par le contrôleur à partir
 * de ses options - c'est ce que la maquette suppose en ne posant jamais la question.
 */
class LessonLogWorkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nature', EnumType::class, [
                'class' => AssignmentNature::class,
                'choices' => AssignmentNature::forLessonLog(),
                'choice_label' => static fn (AssignmentNature $nature): string => $nature->labelKey(),
                'label' => 'lessonLogWorkNatureFieldLabel',
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('title', TextType::class, [
                'label' => 'lessonLogWorkTitleFieldLabel',
                'attr' => ['placeholder' => 'lessonLogWorkTitlePlaceholder'],
                'empty_data' => '',
            ])
            // Proposé pour la nature Quiz, masqué sinon (le gabarit s'en charge) : le serveur, lui,
            // vérifie qu'un travail de type Quiz désigne bien un quiz - voir le contrôleur.
            ->add('quizInstance', EntityType::class, [
                'class' => QuizInstance::class,
                'choice_label' => static fn (QuizInstance $instance): string => $instance->getName(),
                'query_builder' => static fn (QuizInstanceRepository $repository) => $repository->createQueryBuilder('q')
                    ->where('q.program = :program')
                    // Un concours se déroule ensemble, à l'heure dite : il ne se donne pas à faire
                    // pour la prochaine fois.
                    ->andWhere('q.mode != :live')
                    ->setParameter('program', $options['program'])
                    ->setParameter('live', QuizMode::Live)
                    ->orderBy('q.creationDate', 'DESC'),
                'label' => 'lessonLogWorkQuizFieldLabel',
                'placeholder' => 'lessonLogWorkQuizPlaceholder',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'lessonLogWorkDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('dueDate', DateTimeType::class, [
                'label' => 'lessonLogWorkDueDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            // Sélection multiple, comme les pastilles PDF / ZIP / Tout format de la maquette : ne
            // rien cocher vaut « tout format », ce qui évite une pastille de plus pour le dire.
            ->add('acceptedFormats', ChoiceType::class, [
                'label' => 'lessonLogWorkAcceptedFormatsFieldLabel',
                'choices' => [
                    'lessonLogWorkFormatPdfLabel' => 'pdf',
                    'lessonLogWorkFormatZipLabel' => 'zip',
                    'lessonLogWorkFormatImageLabel' => 'image',
                ],
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ])
            // Non mappé : l'entité porte une date de publication, l'écran une case à cocher. Le
            // contrôleur fait la traduction, seul endroit qui connaît « maintenant ».
            ->add('publishNow', CheckboxType::class, [
                'label' => 'lessonLogWorkPublishNowFieldLabel',
                'required' => false,
                'mapped' => false,
                'data' => true,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'lessonLogWorkSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Assignment::class]);
        $resolver->setRequired('program');
        $resolver->setAllowedTypes('program', Program::class);
    }
}
