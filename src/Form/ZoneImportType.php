<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The "Import interactif (JSON)" upload step - a paste field rather than a file input, because
 * the JSON comes straight out of a Claude conversation (see the copyable prompt on the same
 * screen); saving it to a file first would only add a step. App\Service\ZoneJsonImporter does the
 * actual reading.
 */
class ZoneImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('json', TextareaType::class, [
            'label' => 'zoneImportJsonFieldLabel',
            'constraints' => [new NotBlank(message: 'zoneImportJsonEmptyMessage')],
            'attr' => [
                'rows' => 14,
                'placeholder' => '{"format": "moncampus-zones/1", …}',
                'style' => 'font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12.5px;',
            ],
        ]);
    }
}
