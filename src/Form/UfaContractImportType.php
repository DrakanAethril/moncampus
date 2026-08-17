<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

// The upload half of the UFA contract import (App\Controller\Ufa\ContractImportController): one
// file, no entity - nothing is created until the analysis it produces has been confirmed.
//
// The MIME list is spelled out for the same reason as QuizImportType's: an .xlsx *is* a zip, so a
// guesser with no Office signature to go on reads it back as application/zip (or
// application/octet-stream), and the constraint's own default list would reject the school's real
// export.
class UfaContractImportType extends AbstractType
{
    public const string MAX_SIZE = '5M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FilePickerType::class, [
                'label' => 'ufaContractImportFileFieldLabel',
                'help' => 'ufaContractImportFileFieldHelpText',
                // The "spreadsheets" narrowing, itself narrowed to the one format the school's
                // export produces. The three MIME types this field used to spell out are the
                // platform map's own entry for .xlsx - an OOXML file is a zip and sniffs as one,
                // which is why Assert\File's default list rejected the real export.
                'policy' => UploadPolicy::spreadsheets()->restrictTo('xlsx'),
                'max_size' => self::MAX_SIZE,
                // No library tab: a spreadsheet imported once is not course material
                // (design/validated/file-library.md, "The component").
                'library' => false,
                'constraints' => [
                    new NotNull(message: 'ufaContractImportFileRequiredMessage'),
                ],
            ])
        ;
    }
}
