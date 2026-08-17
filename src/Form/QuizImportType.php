<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

// The upload half of the quiz import (App\Controller\QuizImportController): one file, no entity -
// the quiz itself is only ever built from the preview's confirmation form (QuizTemplateSettingsType).
// One form for both sources; only what the file may be changes with `source`.
//
// Every extension check spells its own MIME list out because the constraint's default ones reject
// the real thing: a CSV *is* a text file, so MimeTypes::guessMimeType() reads it back as
// text/plain and Excel-saved ones as application/vnd.ms-excel - and an .xlsx *is* a zip, which is
// what a guesser sees when the file has no registered Office signature to go on.
class QuizImportType extends AbstractType
{
    public const string MAX_SIZE = '5M';

    public const string SOURCE_CSV = 'csv';
    public const string SOURCE_KAHOOT = 'kahoot';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $kahoot = self::SOURCE_KAHOOT === $options['source'];

        $builder
            ->add('file', FilePickerType::class, [
                'label' => 'quizImportFileFieldLabel',
                'help' => $kahoot ? 'quizImportKahootFileFieldHelpText' : 'quizImportFileFieldHelpText',
                // Kahoot hands over an .xlsx; the app's own format is a .csv, and .txt stays
                // accepted alongside it because that is what this field already did. Both are
                // narrowings of the platform list, so neither can drift outside it.
                'policy' => $kahoot
                    ? UploadPolicy::spreadsheets()->restrictTo('xlsx')
                    : UploadPolicy::platform()->restrictTo('csv', 'txt'),
                'max_size' => self::MAX_SIZE,
                // No library tab: a document imported once is not course material
                // (design/validated/file-library.md, "The component").
                'library' => false,
                'constraints' => [
                    new NotNull(message: 'quizImportFileRequiredMessage'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefault('source', self::SOURCE_CSV)
            ->setAllowedValues('source', [self::SOURCE_CSV, self::SOURCE_KAHOOT])
        ;
    }
}
