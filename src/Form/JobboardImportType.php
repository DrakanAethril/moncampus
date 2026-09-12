<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Section;
use App\Repository\SectionRepository;
use App\Service\UploadPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The upload half of « Configuration > Jobboard > Import »: one JSON file and the filière it lands
 * in. Nothing is written until the analysis it produces has been confirmed.
 *
 * The filière is a field rather than a guess: the legacy file carries no `filiere` at all, and
 * deducing one from the offers' content is exactly what this feature refuses to do everywhere else.
 *
 * The file goes through FilePickerType like every other upload of this platform - no form here
 * carries bytes - and the library tab is off: a file imported once is not course material.
 */
class JobboardImportType extends AbstractType
{
    public const string MAX_SIZE = '10M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('section', EntityType::class, [
                'class' => Section::class,
                'label' => 'jobboardImportSectionFieldLabel',
                'help' => 'jobboardImportSectionFieldHelpText',
                'choice_label' => 'name',
                'query_builder' => static fn (SectionRepository $repository) => $repository->createQueryBuilder('s')->orderBy('s.name', 'ASC'),
                // A select used for input, so it starts on nothing: the filière is the one decision
                // this screen asks for, and a pre-selected one would be taken by accident.
                'placeholder' => 'jobboardImportSectionPlaceholder',
                'constraints' => [new NotNull(message: 'jobboardImportSectionRequiredMessage')],
            ])
            ->add('file', FilePickerType::class, [
                'label' => 'jobboardImportFileFieldLabel',
                'help' => 'jobboardImportFileFieldHelpText',
                'policy' => UploadPolicy::platform()->restrictTo('json'),
                'max_size' => self::MAX_SIZE,
                'library' => false,
                'constraints' => [new NotNull(message: 'jobboardImportFileRequiredMessage')],
            ])
        ;
    }
}
