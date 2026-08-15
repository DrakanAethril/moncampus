<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProgramCertification;
use App\Enum\CertificationKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One certification a Program prepares, for one Option.
 *
 * Rendered once per Option inside the tab's single form (see
 * Program\InternshipCertificationController), so it carries no submit button of its own - the
 * enclosing form has the only one, per the app's one-actionbar-per-page rule.
 */
class ProgramCertificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'programCertificationLabelFieldLabel',
                'help' => 'programCertificationLabelFieldHelp',
                'empty_data' => '',
                'required' => false,
            ])
            ->add('kind', EnumType::class, [
                'class' => CertificationKind::class,
                'label' => 'programCertificationKindFieldLabel',
                'choice_label' => static fn (CertificationKind $kind): string => $kind->translationKey(),
                // Consultation-style select: every entry is a real answer, so no placeholder -
                // unlike a select used to capture a value the user may not have.
                'placeholder' => false,
            ])
            ->add('rncpCode', TextType::class, [
                'label' => 'programCertificationRncpCodeFieldLabel',
                'help' => 'programCertificationRncpCodeFieldHelp',
                'required' => false,
            ])
            ->add('level', IntegerType::class, [
                'label' => 'programCertificationLevelFieldLabel',
                'help' => 'programCertificationLevelFieldHelp',
                'required' => false,
            ])
            ->add('certifier', TextType::class, [
                'label' => 'programCertificationCertifierFieldLabel',
                'help' => 'programCertificationCertifierFieldHelp',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProgramCertification::class]);
    }
}
