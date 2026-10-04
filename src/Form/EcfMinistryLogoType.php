<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

// The ministry's bloc-marque for the ECF booklet's cover. Not entity-backed: the controller stores
// the staged file itself, like the avatar.
class EcfMinistryLogoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('logo', FilePickerType::class, [
                'label' => 'ecfMinistryLogoFieldLabel',
                'help' => 'ecfMinistryLogoFieldHelp',
                'mapped' => false,
                'policy' => UploadPolicy::images(),
                'max_size' => '2M',
                'library' => false,
                'required' => true,
            ])
            ->add('submit', SubmitType::class, ['label' => 'ecfMinistryLogoSubmitAction'])
        ;
    }
}
