<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AssignmentExpectedProduction;
use App\Enum\AssignmentProductionFormat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une ligne de « Productions attendues » (2a, étape Consigne) : nom, format, échéance.
 *
 * $dueMode n'est pas une donnée mais une bascule d'écran - « Échéance du travail » ou « Date et
 * heure précises… ». Elle vit dans le formulaire plutôt que dans le gabarit pour se retrouver dans
 * le prototype des lignes ajoutées à la volée, et c'est elle qui décide, à la soumission, si la
 * date saisie compte ou si la ligne suit le travail.
 */
class AssignmentExpectedProductionType extends AbstractType
{
    public const DUE_MODE_WORK = 'work';
    public const DUE_MODE_CUSTOM = 'custom';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ?AssignmentExpectedProduction $production */
        $production = $builder->getData();

        $builder
            ->add('name', TextType::class, [
                'label' => false,
                'empty_data' => '',
                'attr' => ['placeholder' => 'assignmentProductionNamePlaceholder'],
            ])
            ->add('format', EnumType::class, [
                'class' => AssignmentProductionFormat::class,
                'choice_label' => static fn (AssignmentProductionFormat $format): string => $format->labelKey(),
                'label' => false,
                'placeholder' => false,
            ])
            ->add('dueMode', ChoiceType::class, [
                'label' => false,
                'mapped' => false,
                'choices' => [
                    'assignmentProductionDueWorkLabel' => self::DUE_MODE_WORK,
                    'assignmentProductionDueCustomLabel' => self::DUE_MODE_CUSTOM,
                ],
                'placeholder' => false,
                'data' => $production?->hasOwnDueDate() ? self::DUE_MODE_CUSTOM : self::DUE_MODE_WORK,
            ])
            ->add('dueDate', DateTimeType::class, [
                'label' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            // Le rang tel que la ligne se lit à l'écran, réécrit par le contrôleur Stimulus à
            // chaque ajout/suppression - sans quoi une ligne retirée au milieu ferait remonter les
            // suivantes dans le désordre.
            ->add('position', HiddenType::class)
        ;

        // « Échéance du travail » veut dire « pas de date propre » : la valeur restée dans le champ
        // caché ne doit pas survivre au retour en arrière.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $production = $event->getData();

            if ($production instanceof AssignmentExpectedProduction && self::DUE_MODE_CUSTOM !== $event->getForm()->get('dueMode')->getData()) {
                $production->setDueDate(null);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AssignmentExpectedProduction::class]);
    }
}
