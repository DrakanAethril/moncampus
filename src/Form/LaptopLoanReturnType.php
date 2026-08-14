<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LaptopConditionType;
use App\Entity\LaptopLoan;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LaptopLoanReturnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Date du retour, saisissable comme sur la maquette 25f - le retour n'est pas toujours
            // enregistré le jour où l'ordinateur revient.
            ->add('returnedAt', DateType::class, [
                'label' => 'laptopLoanReturnedAtFieldLabel',
                // Valeur par défaut portée par le champ et non par l'entité : renseigner
                // $returnedAt sur le prêt le ferait passer pour rendu (LaptopLoan::isReturned()),
                // et l'écran perdrait aussitôt son indication de retard.
                'data' => new \DateTimeImmutable(),
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('returnConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
                'choice_label' => 'name',
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopLoanReturnConditionFieldLabel',
                // Un choix vide en tête, et non 'placeholder' => false : l'état constaté au retour
                // est une saisie, pas une reconduction. Sans lui le navigateur sélectionne le
                // premier état de la liste et un retour enregistré sans y toucher affirme un état
                // que personne n'a constaté.
                'placeholder' => 'laptopConditionPlaceholder',
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('returnStateNotes', TextareaType::class, [
                'label' => 'laptopLoanReturnStateNotesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 5],
                // Explicit '' (not the default) activates TextareaType's own null->'' safety net
                // for blank submissions on this non-nullable-at-return-time field.
                'empty_data' => '',
            ])
            // « État des accessoires », le pendant au retour de la section « Accessoires prêtés »
            // du formulaire de prêt. Facultatif des deux côtés : un prêt sans accessoire n'a rien à
            // constater ici.
            ->add('returnAccessoryConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
                'choice_label' => 'name',
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopLoanReturnAccessoryConditionFieldLabel',
                'placeholder' => 'laptopConditionPlaceholder',
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('returnAccessoryNotes', TextareaType::class, [
                'label' => 'laptopLoanReturnAccessoryNotesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'returnLaptopSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LaptopLoan::class,
        ]);
    }
}
