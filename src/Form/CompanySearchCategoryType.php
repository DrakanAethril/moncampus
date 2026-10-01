<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CompanySearchCategory;
use App\Enum\CompanyCategoryFlag;
use App\Enum\CompanyCategoryTheme;
use App\Enum\EmployeeBand;
use App\Service\CompanySearch\NafNomenclature;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One category of « Trouver une entreprise ». The NAF codes are typed as a list (« 62.01Z,
 * 62.02A ») and each must be one of the nomenclature's: a mistyped code would silently find
 * nothing, which reads to a student as « aucune entreprise ne fait ça ici ».
 */
class CompanySearchCategoryType extends AbstractType
{
    public function __construct(
        private readonly NafNomenclature $nomenclature,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('theme', EnumType::class, [
                'class' => CompanyCategoryTheme::class,
                'choice_label' => static fn (CompanyCategoryTheme $theme): string => $theme->labelKey(),
                'label' => 'companyCategoryThemeFieldLabel',
            ])
            ->add('label', TextType::class, [
                'label' => 'companyCategoryLabelFieldLabel',
                'empty_data' => '',
            ])
            ->add('hint', TextType::class, [
                'label' => 'companyCategoryHintFieldLabel',
                'help' => 'companyCategoryHintFieldHelp',
                'required' => false,
            ])
            ->add('nafCodes', TextType::class, [
                'label' => 'companyCategoryNafCodesFieldLabel',
                'help' => 'companyCategoryNafCodesFieldHelp',
                'required' => false,
                'constraints' => [new Callback(function (mixed $codes, ExecutionContextInterface $context): void {
                    foreach (\is_array($codes) ? $codes : [] as $code) {
                        if (!\is_string($code) || !$this->nomenclature->exists($code)) {
                            $context->buildViolation('companyCategoryUnknownNafCodeError')
                                ->setParameter('%code%', \is_string($code) ? $code : '?')
                                ->addViolation();
                        }
                    }
                })],
            ])
            ->add('flag', EnumType::class, [
                'class' => CompanyCategoryFlag::class,
                'choice_label' => static fn (CompanyCategoryFlag $flag): string => $flag->labelKey(),
                'label' => 'companyCategoryFlagFieldLabel',
                'help' => 'companyCategoryFlagFieldHelp',
                'placeholder' => 'companyCategoryFlagPlaceholder',
                'required' => false,
            ])
            ->add('minimumBand', EnumType::class, [
                'class' => EmployeeBand::class,
                'choice_label' => static fn (EmployeeBand $band): string => $band->labelKey(),
                'label' => 'companyCategoryMinimumBandFieldLabel',
                'help' => 'companyCategoryMinimumBandFieldHelp',
                'placeholder' => 'companyCategoryMinimumBandPlaceholder',
                'required' => false,
            ]);

        $builder->get('nafCodes')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $codes): string => \is_array($codes) ? implode(', ', array_filter($codes, \is_string(...))) : '',
            static fn (mixed $text): array => array_values(array_filter(
                array_map(static fn (string $code): string => mb_strtoupper(trim($code)), preg_split('/[\s,;]+/', \is_string($text) ? $text : '') ?: []),
                static fn (string $code): bool => '' !== $code,
            )),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompanySearchCategory::class,
        ]);
    }
}
