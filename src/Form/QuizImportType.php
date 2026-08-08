<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
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
            ->add('file', FileType::class, [
                'label' => 'quizImportFileFieldLabel',
                'help' => $kahoot ? 'quizImportKahootFileFieldHelpText' : 'quizImportFileFieldHelpText',
                'constraints' => [
                    new NotNull(message: 'quizImportFileRequiredMessage'),
                    new File(
                        maxSize: self::MAX_SIZE,
                        extensions: $kahoot
                            ? ['xlsx' => [
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/zip',
                                'application/octet-stream',
                            ]]
                            : [
                                'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
                                'txt' => 'text/plain',
                            ],
                        extensionsMessage: $kahoot ? 'quizImportKahootInvalidExtensionMessage' : 'quizImportInvalidExtensionMessage',
                    ),
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
