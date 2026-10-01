<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

// The upload half of « Importer l'historique » (App\Controller\CompanySearch\HostingImportController):
// one file, nothing created until its analysis has been confirmed. The model's .xlsx or a CSV
// saved from it.
class EnterpriseHostingImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FilePickerType::class, [
            'label' => 'enterpriseImportFileFieldLabel',
            'help' => 'enterpriseImportFileFieldHelp',
            'policy' => UploadPolicy::spreadsheets(),
            'max_size' => '5M',
            'library' => false,
            'constraints' => [new NotNull(message: 'enterpriseImportFileRequiredError')],
        ]);
    }
}
