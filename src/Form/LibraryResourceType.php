<?php

namespace App\Form;

use App\Entity\Bloc;
use App\Entity\Cohort;
use App\Entity\Option;
use App\Repository\BlocRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

// Not entity-backed, same reasoning as App\Form\LessonLogAttachmentType: the controller decides
// whether $file or $url was actually filled in (exactly one is expected) and which of
// sequenceTemplate/seanceTemplate/seancePhaseTemplate to attach to, since a single form can't map
// cleanly onto either of those XOR shapes.
class LibraryResourceType extends AbstractType
{
    public function __construct(private readonly BlocRepository $blocRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'libraryResourceLabelFieldLabel',
            ])
            ->add('file', FileType::class, [
                'label' => 'libraryResourceFileFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'constraints' => [
                    new File(
                        maxSize: FileUploadDefaults::MAX_SIZE,
                        mimeTypes: [
                            'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/plain', 'application/zip',
                        ],
                        mimeTypesMessage: 'libraryResourceInvalidTypeMessage',
                    ),
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => 'libraryResourceUrlFieldLabel',
                'mapped' => false,
                'required' => false,
            ])
            ->add('blocs', EntityType::class, [
                'class' => Bloc::class,
                'choices' => $this->blocRepository->findAllActiveOrderedByCode(),
                'choice_label' => static fn (Bloc $bloc): string => sprintf('%s - %s', $bloc->getCode(), $bloc->getLabel()),
                'label' => 'sequenceTemplateBlocsFieldLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('cohort', EntityType::class, [
                'class' => Cohort::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('c')
                    ->where('c.inactiveDate IS NULL')
                    ->orderBy('c.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'sequenceTemplateNiveauFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('option', EntityType::class, [
                'class' => Option::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('o')
                    ->where('o.inactiveDate IS NULL')
                    ->orderBy('o.shortName', 'ASC'),
                'choice_label' => 'shortName',
                'label' => 'sequenceTemplateOptionFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'libraryResourceAddAction',
            ])
        ;
    }
}
