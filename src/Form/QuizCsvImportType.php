<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

// The upload half of the CSV import (App\Controller\QuizImportController): one file, no entity -
// the quiz itself is only ever built from the preview's confirmation form (QuizTemplateSettingsType).
// The extension check spells its own MIME list out because the constraint's default one (from
// symfony/mime: text/csv and friends) rejects every real CSV: a CSV *is* a text file, so
// MimeTypes::guessMimeType() reads it back as text/plain, and Excel-saved ones land as
// application/vnd.ms-excel.
class QuizCsvImportType extends AbstractType
{
    public const string MAX_SIZE = '5M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'quizImportFileFieldLabel',
                'help' => 'quizImportFileFieldHelpText',
                'constraints' => [
                    new NotNull(message: 'quizImportFileRequiredMessage'),
                    new File(
                        maxSize: self::MAX_SIZE,
                        extensions: [
                            'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
                            'txt' => 'text/plain',
                        ],
                        extensionsMessage: 'quizImportInvalidExtensionMessage',
                    ),
                ],
            ])
        ;
    }
}
