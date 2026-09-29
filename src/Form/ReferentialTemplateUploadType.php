<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/**
 * « Téléverser le modèle de la session » - the official .xlsx of annexe VI-1 for one session.
 */
class ReferentialTemplateUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('session', IntegerType::class, [
                'label' => 'referentialTemplateSessionFieldLabel',
                'constraints' => [new NotNull(), new Range(min: 2000, max: 2100)],
            ])
            ->add('file', FilePickerType::class, [
                'label' => 'referentialTemplateFileFieldLabel',
                'mapped' => false,
                'required' => true,
                'help' => 'referentialTemplateFileFieldHelp',
                'policy' => UploadPolicy::spreadsheets(),
                'library' => false,
            ])
        ;
    }
}
