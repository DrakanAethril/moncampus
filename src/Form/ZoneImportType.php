<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The paste step of the question import - a paste field rather than a file input, because the JSON
 * comes straight out of a conversation with an assistant (see the prompt the same screen builds);
 * saving it to a file first would only add a step.
 *
 * Which reader gets it is decided by the document itself, not by this form
 * (App\Service\InteractiveQuizImporterRegistry::forDocument()): the label names the format the
 * screen teaches, and the four older ones are still accepted.
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
                'placeholder' => '{"format": "moncampus-quiz/1", …}',
                'style' => 'font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12.5px;',
            ],
        ]);
    }
}
