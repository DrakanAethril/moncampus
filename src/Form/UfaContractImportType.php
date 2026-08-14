<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
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
            ->add('file', FileType::class, [
                'label' => 'ufaContractImportFileFieldLabel',
                'help' => 'ufaContractImportFileFieldHelpText',
                'constraints' => [
                    new NotNull(message: 'ufaContractImportFileRequiredMessage'),
                    new File(
                        maxSize: self::MAX_SIZE,
                        extensions: ['xlsx' => [
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/zip',
                            'application/octet-stream',
                        ]],
                        extensionsMessage: 'ufaContractImportInvalidExtensionMessage',
                    ),
                ],
            ])
        ;
    }
}
