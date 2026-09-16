<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LaptopConditionType;
use App\Entity\LaptopLoan;
use App\Enum\LaptopLoanType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The laptop and borrower are set on the entity by the controller before this form is built,
// not editable form fields here - see LaptopController::lendForm(). The borrower is instead
// picked via a plain ajax tom-select field embedded directly in lend.html.twig (resolved from a
// top-level "borrower" POST field, same convention as AssignmentType's manual_recipients).
class LaptopLoanLendType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Fixes which of the two paper conventions this loan prints on, and is therefore asked
            // at lend time rather than at print time - see App\Enum\LaptopLoanType. Placeholder
            // because it is an input select: nothing must be picked by default.
            ->add('loanType', EnumType::class, [
                'class' => LaptopLoanType::class,
                'choice_label' => static fn (LaptopLoanType $type): string => $type->labelKey(),
                'label' => 'laptopLoanTypeFieldLabel',
                'placeholder' => 'laptopLoanTypePlaceholder',
            ])
            // The loan date can be entered, as on mockup 25e: it was until now frozen at the moment
            // of saving, which forbade entering a loan after the fact - the common case when the
            // laptop is handed over before the trip to the office.
            ->add('lentAt', DateType::class, [
                'label' => 'laptopLoanLentAtFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            // Not 'required' at the form level, because whether it is depends on the loan type
            // picked just above: mandatory on the two conventions, optional on an internal loan
            // whose "Durée indéfinie" box is ticked. What imposes it is LaptopLoan's own
            // Assert\Callback; the asterisk and the html5 required attribute are put back on the
            // client by laptop_loan_duration_controller.js, which knows the same rule.
            ->add('dueAt', DateType::class, [
                'label' => 'laptopLoanDueAtFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            // Unmapped: what it means on the entity is simply "no due date", so there is nothing to
            // store beyond LaptopLoan::$dueAt staying null (see isIndefinite()). Offered on the
            // internal loan only, and ticked by default there - the front end does that, since the
            // type is chosen in the browser; PRE_SUBMIT below is what makes the choice binding.
            ->add('indefiniteDuration', CheckboxType::class, [
                'label' => 'laptopLoanIndefiniteDurationFieldLabel',
                'mapped' => false,
                'required' => false,
            ])
            // Mandatory despite the absence of an asterisk on the mockup: the entity requires the
            // condition and its notes at lending time (non-null column + Assert), which is what
            // documents the state of the equipment handed over. The mockup is less strict than the
            // model here, not the other way round.
            ->add('lentConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
                'choice_label' => 'name',
                // The condition's color travels in data-color, which tom_select_controller.js renders
                // as a chip before the label.
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopLoanConditionFieldLabel',
                'placeholder' => 'laptopConditionPlaceholder',
                // Without tom-select the data-color above is never read: a native <select> cannot
                // paint a chip before an <option>.
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('lentStateNotes', TextareaType::class, [
                'label' => 'laptopLoanLentStateNotesFieldLabel',
                'attr' => ['rows' => 4, 'placeholder' => 'laptopLoanLentStateNotesPlaceholder'],
                // Explicit '' (not the default) activates TextareaType's own null->'' safety net
                // for blank submissions on this non-nullable property.
                'empty_data' => '',
            ])
            // "Accessoires prêtés", shaped like the material-condition section just above but
            // entirely optional: a loan may well be the machine and nothing else.
            ->add('lentAccessories', TextareaType::class, [
                'label' => 'laptopLoanLentAccessoriesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'laptopLoanLentAccessoriesPlaceholder'],
            ])
            ->add('lentAccessoryConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('t')
                    ->where('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
                'choice_label' => 'name',
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'label' => 'laptopLoanLentAccessoryConditionFieldLabel',
                'placeholder' => 'laptopConditionPlaceholder',
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'lendLaptopSubmitAction',
            ])
        ;

        // Reading the box back: a submitted "Durée indéfinie" empties the return date rather than
        // merely allowing it to be empty, so ticking it after having typed a date does what it says.
        // It is only honoured for a type that allows it - a box left ticked while the operator
        // switches back to a convention must not carry an indefinite loan through, and the box is
        // unticked in the same breath so a re-render shows the state that was actually kept.
        //
        // PRE_SUBMIT rather than POST_SUBMIT: the date has to be gone before the form maps it onto
        // the entity and the validator reads it, not after.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            if (!\is_array($data) || !($data['indefiniteDuration'] ?? false)) {
                return;
            }

            $loanType = \is_string($data['loanType'] ?? null) ? LaptopLoanType::tryFrom($data['loanType']) : null;

            if (true === $loanType?->allowsIndefiniteDuration()) {
                $data['dueAt'] = '';
            } else {
                unset($data['indefiniteDuration']);
            }

            $event->setData($data);
        });

        // The box starts out reflecting the loan it is shown on, which on a fresh form is simply
        // "no type picked yet, so nothing indefinite".
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $loan = $event->getData();

            if ($loan instanceof LaptopLoan && $loan->isIndefinite() && true === $loan->getLoanType()?->allowsIndefiniteDuration()) {
                $event->getForm()->get('indefiniteDuration')->setData(true);
            }
        });
    }

    // Handed to the templates so the front end reads the same rule the enum holds, rather than
    // hard-coding "interne" in a Stimulus controller.
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['indefinite_duration_types'] = LaptopLoanType::indefiniteDurationValues();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LaptopLoan::class,
        ]);
    }
}
